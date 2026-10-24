<?php

namespace Tests\Feature;

use App\Models\AnalysisRun;
use App\Models\DataSource;
use App\Models\MarketAnalysis;
use App\Models\MarketSnapshot;
use App\Models\PostHorizonEvaluation;
use App\Models\PromptTemplate;
use App\Services\HermesAdapterService;
use App\Services\PostHorizonEvaluatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class EodIngestionHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush();
        try {
            \Illuminate\Support\Facades\Cache::lock('hermes_sync_eod_mutex', 600)->forceRelease();
        } catch (\Throwable $e) {
        }

        DataSource::create([
            'name' => 'Yahoo Finance Daily EOD',
            'type' => 'market_data',
            'data_classification' => 'end_of_day',
            'base_url' => 'https://query1.finance.yahoo.com/v8/finance/chart/',
            'status' => 'active',
            'last_success_at' => now(),
        ]);

        DataSource::create([
            'name' => 'IDX Official News',
            'type' => 'news',
            'data_classification' => 'delayed',
            'base_url' => 'https://www.idx.co.id',
            'status' => 'active',
            'last_success_at' => now(),
        ]);

        PromptTemplate::create([
            'code' => 'MARKET_INTELLIGENCE_DAILY',
            'version' => '1.0.0',
            'model_id' => 'ag/gemini-3.8-flash-high',
            'system_prompt' => 'You are Aether Market Intelligence AI.',
            'user_prompt_template' => "Analisis data pasar:\n{market_summary}",
            'is_active' => true,
        ]);
    }

    /**
     * Scenario 8 & 10: Predictions NOT registered and command returns exit code FAILURE when EOD sync fails.
     */
    public function test_scenario_8_and_10_predictions_not_registered_when_eod_fails(): void
    {
        // Mock HermesAdapterService to return stale or failed EOD snapshot
        $mockAdapter = Mockery::mock(HermesAdapterService::class);
        $mockAdapter->shouldReceive('getMarketSnapshots')
            ->once()
            ->andReturn([
                'ok' => false,
                'error' => 'Data EOD belum final atau masih stale. Bar paling lama: 2026-10-14, tanggal diharapkan: 2026-10-19.',
                'data' => [
                    'is_fresh' => false,
                    'snapshots' => [],
                    'failed_tickers' => ['BBCA', 'MEDC'],
                ],
            ]);

        $this->app->instance(HermesAdapterService::class, $mockAdapter);

        // Run sync-eod with manual trigger
        $exitCode = $this->artisan('hermes:sync-eod', ['--trigger' => 'manual', '--no-telegram' => true])
            ->run();

        $this->assertEquals(1, $exitCode);

        // Verify no predictions registered
        $this->assertEquals(0, PostHorizonEvaluation::count());

        // Verify no market snapshots inserted
        $this->assertEquals(0, MarketSnapshot::count());

        // Verify AnalysisRun marked as failed with exact reason
        $latestRun = AnalysisRun::latest()->first();
        $this->assertNotNull($latestRun);
        $this->assertEquals('failed', $latestRun->status);
        $this->assertStringContainsString('EOD Snapshot Stale / Gagal', $latestRun->error_message);

        // Verify DataSource marked as degraded
        $ds = DataSource::where('type', 'market_data')->first();
        $this->assertEquals('degraded', $ds->status);
    }

    /**
     * Scenario 9: Identical snapshots do NOT duplicate predictions (anti-duplication guard).
     */
    public function test_scenario_9_identical_snapshot_does_not_duplicate_predictions(): void
    {
        $evaluator = app(PostHorizonEvaluatorService::class);

        // Run 1
        $run1 = AnalysisRun::create([
            'run_id' => 'RUN_TEST_001',
            'trigger_type' => 'manual',
            'status' => 'completed',
            'started_at' => Carbon::parse('2026-10-14 16:05:00'),
        ]);

        $analysis1 = MarketAnalysis::create([
            'analysis_run_id' => $run1->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Summary 1',
            'impact_direction' => 'positive',
            'confidence_score' => 0.7,
            'validation_status' => 'valid_schema',
            'structured_output' => [
                'impacted_tickers' => [
                    ['ticker' => 'BBCA', 'direction' => 'positive'],
                    ['ticker' => 'MEDC', 'direction' => 'neutral'],
                ],
            ],
        ]);

        MarketSnapshot::create([
            'analysis_run_id' => $run1->id,
            'ticker' => 'BBCA',
            'bar_date' => '2026-10-14',
            'source_timestamp' => '2026-10-14T16:00:00+07:00',
            'open_price' => 6000,
            'high_price' => 6150,
            'low_price' => 5950,
            'close_price' => 6125,
            'volume' => 1000000,
            'data_classification' => 'end_of_day',
            'indicators' => ['atr14' => 100, 'rvol20' => 1.5],
        ]);

        MarketSnapshot::create([
            'analysis_run_id' => $run1->id,
            'ticker' => 'MEDC',
            'bar_date' => '2026-10-14',
            'source_timestamp' => '2026-10-14T16:00:00+07:00',
            'open_price' => 1500,
            'high_price' => 1550,
            'low_price' => 1480,
            'close_price' => 1545,
            'volume' => 500000,
            'data_classification' => 'end_of_day',
            'indicators' => ['atr14' => 40, 'rvol20' => 1.2],
        ]);

        $evals1 = $evaluator->registerPredictions($analysis1, 5);
        $this->assertCount(2, $evals1);
        $this->assertEquals(2, PostHorizonEvaluation::count());

        // Run 2: Re-evaluating with same reference date 2026-10-14
        $run2 = AnalysisRun::create([
            'run_id' => 'RUN_TEST_002',
            'trigger_type' => 'manual',
            'status' => 'completed',
            'started_at' => Carbon::parse('2026-10-16 16:05:00'),
        ]);

        $analysis2 = MarketAnalysis::create([
            'analysis_run_id' => $run2->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Summary 2',
            'impact_direction' => 'positive',
            'confidence_score' => 0.7,
            'validation_status' => 'valid_schema',
            'structured_output' => [
                'impacted_tickers' => [
                    ['ticker' => 'BBCA', 'direction' => 'positive'],
                    ['ticker' => 'MEDC', 'direction' => 'neutral'],
                ],
            ],
        ]);

        $evals2 = $evaluator->registerPredictions($analysis2, 5);
        // Anti-duplication guard must prevent new rows
        $this->assertEquals(2, PostHorizonEvaluation::count());

        // Run 3: Fresh date 2026-10-19
        $run3 = AnalysisRun::create([
            'run_id' => 'RUN_TEST_003',
            'trigger_type' => 'manual',
            'status' => 'completed',
            'started_at' => Carbon::parse('2026-10-19 16:05:00'),
        ]);

        MarketSnapshot::create([
            'analysis_run_id' => $run3->id,
            'ticker' => 'BBCA',
            'bar_date' => '2026-10-19',
            'source_timestamp' => '2026-10-19T16:00:00+07:00',
            'open_price' => 4820,
            'high_price' => 4910,
            'low_price' => 4820,
            'close_price' => 4820,
            'volume' => 1200000,
            'data_classification' => 'end_of_day',
            'indicators' => ['atr14' => 95, 'rvol20' => 1.8],
        ]);

        $analysis3 = MarketAnalysis::create([
            'analysis_run_id' => $run3->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Summary 3',
            'impact_direction' => 'negative',
            'confidence_score' => 0.6,
            'validation_status' => 'valid_schema',
            'structured_output' => [
                'impacted_tickers' => [
                    ['ticker' => 'BBCA', 'direction' => 'negative'],
                ],
            ],
        ]);

        $evals3 = $evaluator->registerPredictions($analysis3, 5);
        // New prediction registered for 2026-10-19
        $this->assertEquals(3, PostHorizonEvaluation::count());

        $bbcaEvals = PostHorizonEvaluation::where('ticker', 'BBCA')->orderBy('id')->get();
        $this->assertCount(2, $bbcaEvals);
        $this->assertEquals('2026-10-14 00:00:00', $bbcaEvals[0]->reference_price_date);
        $this->assertEquals('2026-10-21 00:00:00', $bbcaEvals[0]->target_eval_date);
        $this->assertEquals('2026-10-19 00:00:00', $bbcaEvals[1]->reference_price_date);
        $this->assertEquals('2026-10-26 00:00:00', $bbcaEvals[1]->target_eval_date);
    }

    /**
     * Scenario 11: Live AI remains disabled on operational runtime.
     */
    public function test_scenario_11_live_ai_remains_safely_disabled(): void
    {
        // When AI_LIVE_INFERENCE_ENABLED is false, no network request is dispatched
        config(['services.ai_router.live_inference_enabled' => false]);
        Http::preventStrayRequests();

        $aiService = app(\App\Services\AiMarketAnalysisService::class);
        $run = AnalysisRun::create([
            'run_id' => 'RUN_NO_AI_001',
            'trigger_type' => 'scheduled',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $analysis = $aiService->analyze($run, "Market Summary Text");
        $this->assertEquals('fallback', $analysis->validation_status);
        $this->assertStringContainsString('AI_LIVE_INFERENCE_ENABLED=false', $analysis->structured_output['fallback_reason']);
    }

    /**
     * Scenario 12: Hermes monitor accurately displays latest bar date.
     */
    public function test_scenario_12_monitor_reports_accurate_latest_bar_date(): void
    {
        MarketSnapshot::create([
            'ticker' => 'BBCA',
            'bar_date' => '2026-10-19',
            'source_timestamp' => '2026-10-19T16:00:00+07:00',
            'open_price' => 4820,
            'high_price' => 4910,
            'low_price' => 4820,
            'close_price' => 4820,
            'volume' => 1200000,
            'data_classification' => 'end_of_day',
            'indicators' => ['atr14' => 95, 'rvol20' => 1.8],
        ]);

        $this->artisan('hermes:monitor')
            ->expectsOutputToContain('19 Oct 2026')
            ->assertExitCode(0);
    }
}
