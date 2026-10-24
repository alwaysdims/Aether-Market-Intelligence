<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\MarketAnalysis;
use App\Models\MarketSnapshot;
use App\Models\PostHorizonEvaluation;
use App\Models\AnalysisRun;
use App\Services\PostHorizonEvaluatorService;
use App\Services\BeiCalendarService;
use Carbon\Carbon;

class EvaluatorRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected PostHorizonEvaluatorService $evaluator;
    protected BeiCalendarService $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calendar = app(BeiCalendarService::class);
        $this->evaluator = app(PostHorizonEvaluatorService::class);
    }

    /**
     * Skenario 1: 30 record jatuh tempo tanpa harga => waiting_for_data, bukan not_due.
     */
    public function test_30_records_due_without_target_price_classified_as_waiting_for_data_not_due(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_TEST_30_DUE',
            'trigger_type' => 'test',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Test 30 due',
            'impact_direction' => 'positive',
            'validation_status' => 'valid_schema',
        ]);

        // Buat 30 evaluasi pending target 21 Okt 2026 (tanpa snapshot di DB)
        for ($i = 1; $i <= 30; $i++) {
            PostHorizonEvaluation::create([
                'market_analysis_id' => $analysis->id,
                'analysis_run_id' => $run->id,
                'ticker' => 'TICKER_' . $i,
                'decision_date' => '2026-10-14',
                'decision_timestamp' => now(),
                'reference_price' => 1000.00,
                'reference_price_date' => '2026-10-14',
                'horizon_trading_days' => 5,
                'target_eval_date' => '2026-10-21',
                'predicted_direction' => 'positive',
                'status' => 'pending',
            ]);
        }

        // As-of 24 Okt 2026 (sesudah target date 21 Okt)
        $asOf = Carbon::parse('2026-10-24 10:00:00', 'Asia/Jakarta');
        $res = $this->evaluator->evaluatePending($asOf, false);

        $this->assertEquals(0, $res['evaluated_count']);
        $this->assertEquals(0, $res['skipped_not_due'], 'Target 21 Okt yang sudah lewat as-of 24 Okt tidak boleh dianggap skipped_not_due');
        $this->assertEquals(30, $res['waiting_for_data_count'], '30 record harus diklasifikasikan sebagai waiting_for_data');
        $this->assertEquals(0, $res['missing_data_count'], 'Belum lewat 30 hari kalender, tidak boleh cannot_evaluate');
    }

    /**
     * Skenario 2: 10 record target masa depan => not_due (skipped_not_due).
     */
    public function test_10_records_future_target_classified_as_skipped_not_due(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_TEST_10_FUTURE',
            'trigger_type' => 'test',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Test 10 future',
            'impact_direction' => 'positive',
            'validation_status' => 'valid_schema',
        ]);

        for ($i = 1; $i <= 10; $i++) {
            PostHorizonEvaluation::create([
                'market_analysis_id' => $analysis->id,
                'analysis_run_id' => $run->id,
                'ticker' => 'FUT_' . $i,
                'decision_date' => '2026-10-20',
                'decision_timestamp' => now(),
                'reference_price' => 2000.00,
                'reference_price_date' => '2026-10-20',
                'horizon_trading_days' => 5,
                'target_eval_date' => '2026-10-27',
                'predicted_direction' => 'positive',
                'status' => 'pending',
            ]);
        }

        // As-of 24 Okt 2026 (sebelum target date 27 Okt)
        $asOf = Carbon::parse('2026-10-24 10:00:00', 'Asia/Jakarta');
        $res = $this->evaluator->evaluatePending($asOf, false);

        $this->assertEquals(0, $res['evaluated_count']);
        $this->assertEquals(10, $res['skipped_not_due'], 'Target 27 Okt harus berstatus skipped_not_due');
        $this->assertEquals(0, $res['waiting_for_data_count']);
    }

    /**
     * Skenario 3: Hanya ada harga setelah tanggal target => tidak dievaluasi (tetap pending).
     */
    public function test_snapshot_after_target_date_does_not_evaluate_exact_target(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_TEST_AFTER_TARGET',
            'trigger_type' => 'test',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Test exact horizon',
            'impact_direction' => 'positive',
            'validation_status' => 'valid_schema',
        ]);

        $eval = PostHorizonEvaluation::create([
            'market_analysis_id' => $analysis->id,
            'analysis_run_id' => $run->id,
            'ticker' => 'BBCA',
            'decision_date' => '2026-10-14',
            'decision_timestamp' => now(),
            'reference_price' => 5000.00,
            'reference_price_date' => '2026-10-14',
            'horizon_trading_days' => 5,
            'target_eval_date' => '2026-10-21',
            'predicted_direction' => 'positive',
            'status' => 'pending',
        ]);

        // Snapshot hanya ada di 2026-10-23 (bukan tanggal 21)
        MarketSnapshot::create([
            'ticker' => 'BBCA',
            'bar_date' => '2026-10-23',
            'source_timestamp' => now(),
            'close_price' => 5500.00,
            'data_classification' => 'end_of_day',
        ]);

        $asOf = Carbon::parse('2026-10-24 10:00:00', 'Asia/Jakarta');
        $res = $this->evaluator->evaluatePending($asOf, false);

        $this->assertEquals(0, $res['evaluated_count'], 'Tidak boleh mengevaluasi menggunakan bar tanggal setelah target');
        $this->assertEquals(1, $res['waiting_for_data_count']);
        $this->assertEquals('pending', $eval->fresh()->status);
        $this->assertNull($eval->fresh()->actual_price);
    }

    /**
     * Skenario 4: Harga target intraday => tidak dievaluasi sebagai EOD final.
     */
    public function test_intraday_snapshot_on_target_date_not_evaluated_as_eod(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_TEST_INTRADAY',
            'trigger_type' => 'test',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Test intraday exclusion',
            'impact_direction' => 'positive',
            'validation_status' => 'valid_schema',
        ]);

        $eval = PostHorizonEvaluation::create([
            'market_analysis_id' => $analysis->id,
            'analysis_run_id' => $run->id,
            'ticker' => 'BBRI',
            'decision_date' => '2026-10-14',
            'decision_timestamp' => now(),
            'reference_price' => 3000.00,
            'reference_price_date' => '2026-10-14',
            'horizon_trading_days' => 5,
            'target_eval_date' => '2026-10-21',
            'predicted_direction' => 'positive',
            'status' => 'pending',
        ]);

        // Snapshot tanggal 21 Okt bertipe intraday
        MarketSnapshot::create([
            'ticker' => 'BBRI',
            'bar_date' => '2026-10-21',
            'source_timestamp' => now(),
            'close_price' => 3100.00,
            'data_classification' => 'intraday',
        ]);

        $asOf = Carbon::parse('2026-10-24 10:00:00', 'Asia/Jakarta');
        $res = $this->evaluator->evaluatePending($asOf, false);

        $this->assertEquals(0, $res['evaluated_count'], 'Intraday snapshot tidak boleh dipakai evaluasi horizon EOD');
        $this->assertEquals(1, $res['waiting_for_data_count']);
        $this->assertEquals('pending', $eval->fresh()->status);
    }

    /**
     * Skenario 5: Harga EOD target valid => actual_price_date tepat pada target.
     */
    public function test_valid_eod_target_evaluates_with_exact_target_date(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_TEST_VALID_EOD',
            'trigger_type' => 'test',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Test valid EOD',
            'impact_direction' => 'positive',
            'validation_status' => 'valid_schema',
        ]);

        $eval = PostHorizonEvaluation::create([
            'market_analysis_id' => $analysis->id,
            'analysis_run_id' => $run->id,
            'ticker' => 'BMRI',
            'decision_date' => '2026-10-14',
            'decision_timestamp' => now(),
            'reference_price' => 4000.00,
            'reference_price_date' => '2026-10-14',
            'horizon_trading_days' => 5,
            'target_eval_date' => '2026-10-21',
            'predicted_direction' => 'positive',
            'status' => 'pending',
        ]);

        // Sediakan snapshot EOD tanggal 21 Okt
        MarketSnapshot::create([
            'ticker' => 'BMRI',
            'bar_date' => '2026-10-21',
            'source_timestamp' => now(),
            'close_price' => 4200.00, // +5.0%
            'data_classification' => 'end_of_day',
        ]);

        $asOf = Carbon::parse('2026-10-24 10:00:00', 'Asia/Jakarta');
        $res = $this->evaluator->evaluatePending($asOf, false);

        $this->assertEquals(1, $res['evaluated_count']);
        $fresh = $eval->fresh();
        $this->assertEquals('evaluated', $fresh->status);
        $this->assertEquals('2026-10-21', $fresh->actual_price_date->format('Y-m-d'));
        $this->assertEquals(4200.00, (float) $fresh->actual_price);
        $this->assertEquals(5.0, (float) $fresh->actual_return_pct);
        $this->assertEquals('correct', $fresh->outcome);
    }

    /**
     * Skenario 6: Force tidak boleh mengevaluasi sebelum target date.
     */
    public function test_force_mode_never_evaluates_before_target_date(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_TEST_FORCE_LOOKAHEAD',
            'trigger_type' => 'test',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Test force lookahead guard',
            'impact_direction' => 'positive',
            'validation_status' => 'valid_schema',
        ]);

        $eval = PostHorizonEvaluation::create([
            'market_analysis_id' => $analysis->id,
            'analysis_run_id' => $run->id,
            'ticker' => 'BBNI',
            'decision_date' => '2026-10-20',
            'decision_timestamp' => now(),
            'reference_price' => 3200.00,
            'reference_price_date' => '2026-10-20',
            'horizon_trading_days' => 5,
            'target_eval_date' => '2026-10-27',
            'predicted_direction' => 'positive',
            'status' => 'pending',
        ]);

        // As-of 24 Okt (sebelum target 27 Okt) dengan force = true
        $asOf = Carbon::parse('2026-10-24 10:00:00', 'Asia/Jakarta');
        $res = $this->evaluator->evaluatePending($asOf, true);

        $this->assertEquals(0, $res['evaluated_count']);
        $this->assertEquals(1, $res['skipped_not_due'], 'Force mode dilarang mengevaluasi sebelum target date');
        $this->assertEquals('pending', $eval->fresh()->status);
    }

    /**
     * Skenario 7: Same-day target sebelum jam cutoff 16:00 WIB => waiting_for_close.
     */
    public function test_same_day_target_before_cutoff_classified_as_waiting_for_close(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_TEST_CUTOFF',
            'trigger_type' => 'test',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Test cutoff',
            'impact_direction' => 'positive',
            'validation_status' => 'valid_schema',
        ]);

        $eval = PostHorizonEvaluation::create([
            'market_analysis_id' => $analysis->id,
            'analysis_run_id' => $run->id,
            'ticker' => 'ASII',
            'decision_date' => '2026-10-14',
            'decision_timestamp' => now(),
            'reference_price' => 4500.00,
            'reference_price_date' => '2026-10-14',
            'horizon_trading_days' => 5,
            'target_eval_date' => '2026-10-21',
            'predicted_direction' => 'positive',
            'status' => 'pending',
        ]);

        // As-of jam 10:00 WIB pada hari target yang sama (2026-10-21)
        $asOf = Carbon::parse('2026-10-21 10:00:00', 'Asia/Jakarta');
        $res = $this->evaluator->evaluatePending($asOf, false);

        $this->assertEquals(0, $res['evaluated_count']);
        $this->assertEquals(1, $res['waiting_for_close_count'], 'Target hari yang sama sebelum jam 16:00 harus waiting_for_close');
        $this->assertEquals('pending', $eval->fresh()->status);
    }

    /**
     * Skenario 8: Evaluasi berulang tetap idempoten.
     */
    public function test_repeated_evaluation_is_idempotent(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_TEST_IDEMPOTENT',
            'trigger_type' => 'test',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Test idempotent',
            'impact_direction' => 'positive',
            'validation_status' => 'valid_schema',
        ]);

        $eval = PostHorizonEvaluation::create([
            'market_analysis_id' => $analysis->id,
            'analysis_run_id' => $run->id,
            'ticker' => 'TLKM',
            'decision_date' => '2026-10-14',
            'decision_timestamp' => now(),
            'reference_price' => 2000.00,
            'reference_price_date' => '2026-10-14',
            'horizon_trading_days' => 5,
            'target_eval_date' => '2026-10-21',
            'predicted_direction' => 'positive',
            'status' => 'pending',
        ]);

        MarketSnapshot::create([
            'ticker' => 'TLKM',
            'bar_date' => '2026-10-21',
            'source_timestamp' => now(),
            'close_price' => 2100.00,
            'data_classification' => 'end_of_day',
        ]);

        $asOf = Carbon::parse('2026-10-24 10:00:00', 'Asia/Jakarta');

        $res1 = $this->evaluator->evaluatePending($asOf, false);
        $this->assertEquals(1, $res1['evaluated_count']);

        $res2 = $this->evaluator->evaluatePending($asOf, false);
        $this->assertEquals(0, $res2['evaluated_count'], 'Evaluasi kedua tidak memproses ulang record yang sudah evaluated');

        $this->assertEquals(1, PostHorizonEvaluation::where('market_analysis_id', $analysis->id)->count());
        $this->assertEquals('evaluated', $eval->fresh()->status);
    }

    /**
     * Skenario 9: Hilang lebih dari 30 hari kalender => cannot_evaluate.
     */
    public function test_target_missing_more_than_30_days_marked_cannot_evaluate(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_TEST_30_DAYS',
            'trigger_type' => 'test',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Test 30 days missing',
            'impact_direction' => 'positive',
            'validation_status' => 'valid_schema',
        ]);

        $eval = PostHorizonEvaluation::create([
            'market_analysis_id' => $analysis->id,
            'analysis_run_id' => $run->id,
            'ticker' => 'MEDC',
            'decision_date' => '2026-08-01',
            'decision_timestamp' => now(),
            'reference_price' => 1200.00,
            'reference_price_date' => '2026-08-01',
            'horizon_trading_days' => 5,
            'target_eval_date' => '2026-08-08',
            'predicted_direction' => 'positive',
            'status' => 'pending',
        ]);

        // As-of 2026-10-24 (lebih dari 70 hari kalender sejak 8 Agustus)
        $asOf = Carbon::parse('2026-10-24 10:00:00', 'Asia/Jakarta');
        $res = $this->evaluator->evaluatePending($asOf, false);

        $this->assertEquals(1, $res['missing_data_count']);
        $fresh = $eval->fresh();
        $this->assertEquals('cannot_evaluate', $fresh->status);
        $this->assertEquals('missing_data', $fresh->outcome);
    }
}
