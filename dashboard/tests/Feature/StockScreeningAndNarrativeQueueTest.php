<?php

namespace Tests\Feature;

use App\Models\AnalysisRun;
use App\Models\CompanyAnalysisMetric;
use App\Models\MarketAnalysis;
use App\Models\MarketObservation;
use App\Models\PredictionSignal;
use App\Services\StockScreeningService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockScreeningAndNarrativeQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_screening_contract_uses_frozen_version_and_neutral_wording(): void
    {
        $barDate = Carbon::parse('2026-10-26');

        MarketObservation::create([
            'ticker' => 'BBCA',
            'bar_date' => $barDate,
            'observation_time' => $barDate->copy()->setTime(16, 0),
            'open_price' => 5000,
            'high_price' => 5200,
            'low_price' => 4950,
            'close_price' => 5150,
            'volume' => 20_000_000,
            'classification' => 'EOD_FINAL',
            'source' => 'YAHOO_FINANCE',
        ]);

        CompanyAnalysisMetric::create([
            'ticker' => 'BBCA',
            'as_of_date' => $barDate,
            'close_price' => 5150,
            'technical_status' => 'CALCULATED',
            'fundamental_status' => 'CALCULATED',
        ]);

        $screeningService = app(StockScreeningService::class);
        $res = $screeningService->screenCandidates('SWING');

        $this->assertSame(StockScreeningService::STRATEGY_VERSION, $res['strategy_version']);
        $this->assertSame('ACTIVE', $res['outputs']['study_a_price_barrier_touch']['status']);
        $this->assertSame('DOCUMENTED_PENDING_INTRADAY_VERIFICATION', $res['outputs']['simulation_b_execution_strategy']['status']);
        $this->assertNotEmpty($res['candidates']);
        $candidate = $res['candidates'][0];

        $this->assertSame('KANDIDAT_LOLOS_FILTER', $candidate['contract_label']);
        $this->assertStringContainsString('Perubahan harga penutupan relatif terhadap pembukaan', $candidate['reasons'][2]);
        $this->assertSame(5, $candidate['evaluation_horizon_days']);
        $this->assertSame(0.80, $candidate['friction_allowance_pct']);
        $this->assertStringContainsString('AMBIGUOUS', $candidate['ambiguous_rule']);

        $formatted = $screeningService->formatScreeningResponse('SWING');
        $this->assertStringContainsString('v1.0.0-frozen', $formatted);
        $this->assertStringContainsString('KANDIDAT LOLOS FILTER', $formatted);
        $this->assertStringContainsString('Penutupan vs Pembukaan', $formatted);
        $this->assertStringContainsString('Output A', $formatted);
        $this->assertStringContainsString('WAITING_FOR_FINAL_DATA', $formatted);
    }

    public function test_stale_ml_prediction_is_not_borrowed_as_current_bar_confidence(): void
    {
        $currentBarDate = Carbon::parse('2026-10-26');

        MarketObservation::create([
            'ticker' => 'BBRI',
            'bar_date' => $currentBarDate,
            'observation_time' => $currentBarDate->copy()->setTime(16, 0),
            'open_price' => 2900,
            'high_price' => 3000,
            'low_price' => 2850,
            'close_price' => 2950,
            'volume' => 25_000_000,
            'classification' => 'EOD_FINAL',
            'source' => 'YAHOO_FINANCE',
        ]);

        // Historical prediction dated 10 days ago (expired)
        PredictionSignal::create([
            'prediction_id' => 'SHADOW_EXPIRED_01',
            'ticker' => 'BBRI',
            'direction' => 'FLAT',
            'probability_up' => 0.6494,
            'probability_down' => 0.3506,
            'decision_date' => Carbon::parse('2026-10-16'),
            'decision_timestamp' => Carbon::parse('2026-10-16 16:00:00'),
            'reference_price' => 2900,
            'reference_price_date' => Carbon::parse('2026-10-16'),
            'evaluation_due_date' => Carbon::parse('2026-10-23'),
            'expires_at' => Carbon::parse('2026-10-30'),
            'monitoring_status' => 'PENDING',
            'inference_duration_ms' => 45,
            'feature_schema_hash' => 'b268fdff729f',
            'model_name' => 'RandomForest',
            'model_version' => 'RandomForest_v1.0',
            'signal_status' => 'NO_SIGNAL_ABSTAIN',
            'max_probability' => 0.6494,
            'confidence_score' => 0.2988,
            'threshold_applied' => 0.7000,
            'predicted_horizon_days' => 5,
        ]);

        $screeningService = app(StockScreeningService::class);
        $res = $screeningService->screenCandidates('SWING');

        $candidate = $res['candidates'][0];
        $this->assertSame('BBRI', $candidate['ticker']);
        $this->assertSame('NO_ACTIVE_INFERENCE_FOR_BAR', $candidate['ml_status']);
        $this->assertSame('N/A', $candidate['ml_confidence']);
        $this->assertSame('N/A', $candidate['ml_max_probability']);
        $this->assertStringContainsString('telah kadaluarsa', $candidate['ml_note']);
    }

    public function test_process_narrative_queue_skips_when_cached_and_respects_limits(): void
    {
        $barDate = Carbon::parse('2026-10-26');

        MarketObservation::create([
            'ticker' => 'ICBP',
            'bar_date' => $barDate,
            'observation_time' => $barDate->copy()->setTime(16, 0),
            'open_price' => 11000,
            'high_price' => 11200,
            'low_price' => 10900,
            'close_price' => 11150,
            'volume' => 5_000_000,
            'classification' => 'EOD_FINAL',
            'source' => 'YAHOO_FINANCE',
        ]);

        $run = AnalysisRun::create([
            'run_id' => 'RUN_TEST_NARRATIVE',
            'trigger_type' => 'test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // Simulate pre-existing narrative for this bar
        MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0-on-demand',
            'market_regime_summary' => 'Ringkasan narasi ICBP tersimpan.',
            'impact_direction' => 'unclear',
            'impact_horizon' => 'multi_day',
            'confidence_score' => 0.50,
            'structured_output' => [
                'ticker' => 'ICBP',
                'bar_date' => '2026-10-26',
            ],
            'validation_status' => 'valid_schema',
        ]);

        $this->artisan('idx:process-narratives', ['--ticker' => 'ICBP'])
            ->expectsOutputToContain('Sukses [CACHED]: ICBP')
            ->assertSuccessful();
    }
}
