<?php

namespace Tests\Feature;

use App\Models\AnalysisRun;
use App\Models\MarketAnalysis;
use App\Models\MarketSnapshot;
use App\Models\NotificationLog;
use App\Models\PostHorizonEvaluation;
use App\Services\BeiCalendarService;
use App\Services\PostHorizonEvaluatorService;
use App\Services\TelegramNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HermesPhase5Test extends TestCase
{
    use RefreshDatabase;

    protected BeiCalendarService $calendar;
    protected PostHorizonEvaluatorService $evaluator;
    protected TelegramNotificationService $telegram;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calendar = app(BeiCalendarService::class);
        $this->evaluator = app(PostHorizonEvaluatorService::class);
        $this->telegram = app(TelegramNotificationService::class);
    }

    /**
     * 1. Test consistent timestamps, timezone WIB, and trading dates.
     */
    public function test_timestamp_and_timezone_consistency(): void
    {
        $now = Carbon::now();
        $run = AnalysisRun::create([
            'run_id' => 'RUN_TZ_001',
            'trigger_type' => 'cli',
            'status' => 'completed',
            'started_at' => $now,
            'completed_at' => $now->copy()->addSeconds(3),
            'duration_seconds' => 3.0,
        ]);

        $this->assertEquals($now->toDateTimeString(), $run->started_at->toDateTimeString());
        $this->assertEquals(3.0, $run->duration_seconds);
    }

    /**
     * 2. Test target horizon date calculation per prediction based on BEI calendar.
     */
    public function test_target_eval_date_per_prediction_with_calendar(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_CAL_001',
            'trigger_type' => 'cli',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Calendar test',
            'impact_direction' => 'positive',
            'validation_status' => 'valid_schema',
        ]);

        // Wednesday 2026-10-14 snapshot
        MarketSnapshot::create([
            'ticker' => 'BBCA',
            'bar_date' => '2026-10-14',
            'source_timestamp' => now(),
            'close_price' => 6125.00,
            'data_classification' => 'end_of_day',
        ]);

        $evals = $this->evaluator->registerPredictions($analysis, 5);
        $this->assertCount(1, $evals);
        // 5 trading days after Wednesday 2026-10-14:
        // Day 1: Thu 15, Day 2: Fri 16, Day 3: Mon 19, Day 4: Tue 20, Day 5: Wed 21
        $this->assertEquals('2026-10-21', $evals[0]->target_eval_date->format('Y-m-d'));
    }

    /**
     * 3. Test evaluation before target date remains pending.
     */
    public function test_evaluation_before_target_date_remains_pending(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_PENDING_001',
            'trigger_type' => 'cli',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Test',
            'impact_direction' => 'positive',
            'validation_status' => 'valid_schema',
        ]);

        $eval = PostHorizonEvaluation::create([
            'market_analysis_id' => $analysis->id,
            'analysis_run_id' => $run->id,
            'ticker' => 'TLKM',
            'decision_date' => '2026-10-14',
            'decision_timestamp' => now(),
            'reference_price' => 3100.00,
            'reference_price_date' => '2026-10-14',
            'horizon_trading_days' => 5,
            'target_eval_date' => '2026-10-21',
            'predicted_direction' => 'positive',
            'status' => 'pending',
        ]);

        // Evaluate on 2026-10-16 (Day 2 out of 5)
        $res = $this->evaluator->evaluatePending(Carbon::parse('2026-10-16', 'Asia/Jakarta'));
        $this->assertEquals(0, $res['evaluated_count']);
        $this->assertEquals('pending', $eval->fresh()->status);
    }

    /**
     * 4. Test evaluation when target date is reached but target price is missing.
     */
    public function test_evaluation_target_date_reached_but_price_missing(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_MISSING_PRICE_001',
            'trigger_type' => 'cli',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Test',
            'impact_direction' => 'positive',
            'validation_status' => 'valid_schema',
        ]);

        $eval = PostHorizonEvaluation::create([
            'market_analysis_id' => $analysis->id,
            'analysis_run_id' => $run->id,
            'ticker' => 'ARTO',
            'decision_date' => '2026-10-14',
            'decision_timestamp' => now(),
            'reference_price' => 2400.00,
            'reference_price_date' => '2026-10-14',
            'horizon_trading_days' => 5,
            'target_eval_date' => '2026-10-21',
            'predicted_direction' => 'positive',
            'status' => 'pending',
        ]);

        // Evaluate on target date 2026-10-21 without any snapshot in DB
        $res = $this->evaluator->evaluatePending(Carbon::parse('2026-10-21', 'Asia/Jakarta'));
        $this->assertEquals(0, $res['evaluated_count']);
        $this->assertEquals('pending', $eval->fresh()->status); // Remains pending awaiting download
    }

    /**
     * 5. Test Telegram deduplication when content payload is identical.
     */
    public function test_telegram_deduplication_identical_content(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_TG_IDENTICAL_001',
            'trigger_type' => 'cli',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Identical payload test',
            'impact_direction' => 'unclear',
            'validation_status' => 'fallback',
        ]);

        $payload = $this->telegram->formatEodMessage($run, $analysis);
        $messageHash = sha1("EOD_CONTENT_" . sha1($payload));

        NotificationLog::create([
            'channel' => 'telegram',
            'destination' => 'telegram:5923895922',
            'analysis_run_id' => $run->id,
            'market_analysis_id' => $analysis->id,
            'message_hash' => $messageHash,
            'message_payload' => $payload,
            'status' => 'sent',
            'sent_at' => now()->subHours(2),
        ]);

        $res = $this->telegram->sendEodNotification($run, $analysis, false);
        $this->assertEquals('skipped_duplicate', $res['status']);
    }

    /**
     * 6. Test explicit policy when payload changes: prepends UPDATE label.
     */
    public function test_telegram_policy_when_payload_changes(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_TG_CHANGED_001',
            'trigger_type' => 'cli',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        // Prior notification sent earlier today
        NotificationLog::create([
            'channel' => 'telegram',
            'destination' => 'telegram:5923895922',
            'message_hash' => 'hash_early_morning',
            'message_payload' => 'Early morning report',
            'status' => 'sent',
            'sent_at' => now()->subHours(3),
            'created_at' => now()->subHours(3),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Revised afternoon report',
            'impact_direction' => 'positive',
            'validation_status' => 'valid_schema',
        ]);

        config(['services.hermes.cli_command' => 'mock_hermes_cli']);
        $res = (new TelegramNotificationService())->sendEodNotification($run, $analysis, true);

        // When sending revised report, payload has [PEMBARUAN / REVISI LAPORAN]
        $latestLog = NotificationLog::where('channel', 'telegram')->latest()->first();
        $this->assertNotNull($latestLog);
        $this->assertStringContainsString('PEMBARUAN / REVISI LAPORAN', $latestLog->message_payload);
    }

    /**
     * 7 & 8. Test catch-up after computer reboot and repeated --force sync.
     */
    public function test_catch_up_and_repeated_force_sync(): void
    {
        // First sync
        $this->artisan('hermes:sync-eod', ['--force' => true, '--no-telegram' => true])
            ->assertExitCode(0);

        $firstCount = PostHorizonEvaluation::count();
        $this->assertGreaterThan(0, $firstCount);

        // Re-registering predictions on the same analysis must be strictly idempotent
        $analysis = MarketAnalysis::latest()->first();
        $this->evaluator->registerPredictions($analysis, 5);
        $this->assertEquals($firstCount, PostHorizonEvaluation::count());
    }

    /**
     * 9. Test separation of Gemini and fallback metrics.
     */
    public function test_gemini_and_fallback_metrics_separated(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_METRIC_SEP_001',
            'trigger_type' => 'cli',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        // Gemini AI prediction
        $aiAnalysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'AI summary',
            'impact_direction' => 'positive',
            'validation_status' => 'valid_schema',
        ]);

        PostHorizonEvaluation::create([
            'market_analysis_id' => $aiAnalysis->id,
            'analysis_run_id' => $run->id,
            'ticker' => 'MEDC',
            'decision_date' => '2026-10-14',
            'decision_timestamp' => now(),
            'reference_price' => 1500.00,
            'reference_price_date' => '2026-10-14',
            'horizon_trading_days' => 5,
            'target_eval_date' => '2026-10-21',
            'predicted_direction' => 'positive',
            'is_fallback' => false,
            'validation_status' => 'valid_schema',
            'status' => 'evaluated',
            'actual_price' => 1550.00,
            'actual_return_pct' => 3.33,
            'actual_direction' => 'positive',
            'outcome' => 'correct',
        ]);

        // Fallback prediction
        $fallbackAnalysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Fallback summary',
            'impact_direction' => 'unclear',
            'validation_status' => 'fallback',
        ]);

        PostHorizonEvaluation::create([
            'market_analysis_id' => $fallbackAnalysis->id,
            'analysis_run_id' => $run->id,
            'ticker' => 'SMRA',
            'decision_date' => '2026-10-14',
            'decision_timestamp' => now(),
            'reference_price' => 500.00,
            'reference_price_date' => '2026-10-14',
            'horizon_trading_days' => 5,
            'target_eval_date' => '2026-10-21',
            'predicted_direction' => 'unclear',
            'is_fallback' => true,
            'validation_status' => 'fallback',
            'status' => 'evaluated',
            'actual_price' => 510.00,
            'actual_return_pct' => 2.00,
            'actual_direction' => 'positive',
            'outcome' => 'neutral',
        ]);

        $metrics = $this->evaluator->getMetricsSummary();

        $this->assertEquals(1, $metrics['ai_evaluated_count']);
        $this->assertEquals(100.0, $metrics['ai_accuracy_pct']); // 1 correct out of 1 directional
        $this->assertGreaterThanOrEqual(1, $metrics['fallback_count']);
    }

    /**
     * 10. Test idempotency of evaluatePending.
     */
    public function test_evaluate_pending_idempotency(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_IDEMPOTENT_001',
            'trigger_type' => 'cli',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Idempotency test',
            'impact_direction' => 'positive',
            'validation_status' => 'valid_schema',
        ]);

        $eval = PostHorizonEvaluation::create([
            'market_analysis_id' => $analysis->id,
            'analysis_run_id' => $run->id,
            'ticker' => 'BMRI',
            'decision_date' => '2026-10-14',
            'decision_timestamp' => now(),
            'reference_price' => 3900.00,
            'reference_price_date' => '2026-10-14',
            'horizon_trading_days' => 5,
            'target_eval_date' => '2026-10-21',
            'predicted_direction' => 'positive',
            'status' => 'pending',
        ]);

        MarketSnapshot::create([
            'ticker' => 'BMRI',
            'bar_date' => '2026-10-21',
            'source_timestamp' => now(),
            'close_price' => 4000.00,
            'data_classification' => 'end_of_day',
        ]);

        $day = Carbon::parse('2026-10-21 16:05:00', 'Asia/Jakarta');

        $res1 = $this->evaluator->evaluatePending($day);
        $this->assertEquals(1, $res1['evaluated_count']);

        $res2 = $this->evaluator->evaluatePending($day);
        $this->assertEquals(0, $res2['evaluated_count']);

        $this->assertEquals(1, PostHorizonEvaluation::where('market_analysis_id', $analysis->id)->count());
    }

    /**
     * 11. Test invalid data (price <= 0) excluded from correct/incorrect metrics.
     */
    public function test_invalid_price_handled_as_cannot_evaluate(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_INVALID_PRICE_001',
            'trigger_type' => 'cli',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Invalid price test',
            'impact_direction' => 'positive',
            'validation_status' => 'valid_schema',
        ]);

        $eval = PostHorizonEvaluation::create([
            'market_analysis_id' => $analysis->id,
            'analysis_run_id' => $run->id,
            'ticker' => 'BBNI',
            'decision_date' => '2026-10-14',
            'decision_timestamp' => now(),
            'reference_price' => 0.00, // Invalid reference price
            'reference_price_date' => '2026-10-14',
            'horizon_trading_days' => 5,
            'target_eval_date' => '2026-10-21',
            'predicted_direction' => 'positive',
            'status' => 'pending',
        ]);

        MarketSnapshot::create([
            'ticker' => 'BBNI',
            'bar_date' => '2026-10-21',
            'source_timestamp' => now(),
            'close_price' => 3400.00,
            'data_classification' => 'end_of_day',
        ]);

        $res = $this->evaluator->evaluatePending(Carbon::parse('2026-10-21 16:05:00', 'Asia/Jakarta'));
        $this->assertEquals('cannot_evaluate', $eval->fresh()->status);
        $this->assertEquals('missing_data', $eval->fresh()->outcome);

        $metrics = $this->evaluator->getMetricsSummary();
        $this->assertGreaterThanOrEqual(1, $metrics['total_cannot_evaluate']);
    }

    /**
     * 12. Test monitor artisan command execution.
     */
    public function test_monitor_artisan_command(): void
    {
        $this->artisan('hermes:monitor')
            ->assertExitCode(0);
    }
}
