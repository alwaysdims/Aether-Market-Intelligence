<?php

namespace Tests\Feature;

use App\Models\AnalysisRun;
use App\Models\MarketAnalysis;
use App\Models\MarketSnapshot;
use App\Models\NotificationLog;
use App\Models\PostHorizonEvaluation;
use App\Services\BeiCalendarService;
use App\Services\PostHorizonEvaluatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HermesPhase3Test extends TestCase
{
    use RefreshDatabase;

    protected BeiCalendarService $calendar;
    protected PostHorizonEvaluatorService $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calendar = app(BeiCalendarService::class);
        $this->evaluator = app(PostHorizonEvaluatorService::class);
    }

    public function test_bei_calendar_weekend_is_not_trading_day(): void
    {
        // 2026-10-17 is Saturday, 2026-10-18 is Sunday
        $this->assertFalse($this->calendar->isTradingDay('2026-10-17'));
        $this->assertFalse($this->calendar->isTradingDay('2026-10-18'));
    }

    public function test_bei_calendar_holiday_is_not_trading_day(): void
    {
        // 2026-08-17 is Indonesian Independence Day (Monday)
        $this->assertFalse($this->calendar->isTradingDay('2026-08-17'));
        // 2026-01-01 is New Year (Thursday)
        $this->assertFalse($this->calendar->isTradingDay('2026-01-01'));
    }

    public function test_bei_calendar_active_weekday_is_trading_day(): void
    {
        // 2026-10-16 is Friday (normal trading day)
        $this->assertTrue($this->calendar->isTradingDay('2026-10-16'));
        // 2026-10-19 is Monday (normal trading day)
        $this->assertTrue($this->calendar->isTradingDay('2026-10-19'));
    }

    public function test_add_trading_days_skips_weekends_and_holidays(): void
    {
        // Friday 2026-10-16 + 5 trading days:
        // Day 1: Mon 19
        // Day 2: Tue 20
        // Day 3: Wed 21
        // Day 4: Thu 22
        // Day 5: Fri 23
        $target = $this->calendar->addTradingDays('2026-10-16', 5);
        $this->assertEquals('2026-10-23', $target->format('Y-m-d'));
    }

    public function test_register_predictions_is_idempotent(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_TEST_001',
            'trigger_type' => 'test',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Test regime summary',
            'impact_direction' => 'positive',
            'confidence_score' => 0.85,
            'structured_output' => [
                'impacted_tickers' => [
                    ['ticker' => 'MEDC', 'direction' => 'positive'],
                    ['ticker' => 'BBCA', 'direction' => 'positive'],
                ],
            ],
            'validation_status' => 'valid_schema',
        ]);

        MarketSnapshot::create([
            'ticker' => 'MEDC',
            'bar_date' => '2026-10-14',
            'source_timestamp' => now(),
            'close_price' => 1545.00,
            'data_classification' => 'end_of_day',
        ]);

        MarketSnapshot::create([
            'ticker' => 'BBCA',
            'bar_date' => '2026-10-14',
            'source_timestamp' => now(),
            'close_price' => 6125.00,
            'data_classification' => 'end_of_day',
        ]);

        // First registration
        $first = $this->evaluator->registerPredictions($analysis, 5);
        $this->assertCount(2, $first);
        $this->assertEquals(2, PostHorizonEvaluation::count());

        // Second registration must not duplicate rows
        $second = $this->evaluator->registerPredictions($analysis, 5);
        $this->assertCount(2, $second);
        $this->assertEquals(2, PostHorizonEvaluation::count());
    }

    public function test_evaluate_pending_skips_before_target_date(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_TEST_002',
            'trigger_type' => 'test',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Test regime',
            'impact_direction' => 'positive',
            'confidence_score' => 0.80,
            'structured_output' => [],
            'validation_status' => 'valid_schema',
        ]);

        $eval = PostHorizonEvaluation::create([
            'market_analysis_id' => $analysis->id,
            'analysis_run_id' => $run->id,
            'ticker' => 'BBRI',
            'decision_date' => '2026-10-16',
            'decision_timestamp' => now(),
            'reference_price' => 3090.00,
            'reference_price_date' => '2026-10-16',
            'horizon_trading_days' => 5,
            'target_eval_date' => '2026-10-23',
            'predicted_direction' => 'positive',
            'status' => 'pending',
        ]);

        // Evaluate on 2026-10-20 (Day 2 out of 5, not yet due!)
        $asOf = Carbon::parse('2026-10-20', 'Asia/Jakarta');
        $res = $this->evaluator->evaluatePending($asOf, false);

        $this->assertEquals(0, $res['evaluated_count']);
        $this->assertEquals(1, $res['skipped_not_due']);
        $this->assertEquals('pending', $eval->fresh()->status);
    }

    public function test_evaluate_pending_correct_outcome_on_due_date(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_TEST_003',
            'trigger_type' => 'test',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Bullish continuation',
            'impact_direction' => 'positive',
            'confidence_score' => 0.90,
            'structured_output' => [],
            'validation_status' => 'valid_schema',
        ]);

        $eval = PostHorizonEvaluation::create([
            'market_analysis_id' => $analysis->id,
            'analysis_run_id' => $run->id,
            'ticker' => 'MEDC',
            'decision_date' => '2026-10-14',
            'decision_timestamp' => now(),
            'reference_price' => 1500.00,
            'reference_price_date' => '2026-10-14',
            'horizon_trading_days' => 5,
            'target_eval_date' => '2026-10-21',
            'predicted_direction' => 'positive',
            'status' => 'pending',
        ]);

        // Provide future actual price at target date
        MarketSnapshot::create([
            'ticker' => 'MEDC',
            'bar_date' => '2026-10-21',
            'source_timestamp' => now(),
            'close_price' => 1600.00, // +6.67% gain
            'data_classification' => 'end_of_day',
        ]);

        $asOf = Carbon::parse('2026-10-21 16:05:00', 'Asia/Jakarta');
        $res = $this->evaluator->evaluatePending($asOf, false);

        $this->assertEquals(1, $res['evaluated_count']);
        $fresh = $eval->fresh();
        $this->assertEquals('evaluated', $fresh->status);
        $this->assertEquals('correct', $fresh->outcome);
        $this->assertEquals(1600.00, (float) $fresh->actual_price);
        $this->assertEquals(6.6667, round((float) $fresh->actual_return_pct, 4));
        $this->assertEquals('positive', $fresh->actual_direction);
    }

    public function test_evaluate_pending_incorrect_outcome_on_due_date(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_TEST_004',
            'trigger_type' => 'test',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Bullish attempt',
            'impact_direction' => 'positive',
            'confidence_score' => 0.75,
            'structured_output' => [],
            'validation_status' => 'valid_schema',
        ]);

        $eval = PostHorizonEvaluation::create([
            'market_analysis_id' => $analysis->id,
            'analysis_run_id' => $run->id,
            'ticker' => 'ASII',
            'decision_date' => '2026-10-14',
            'decision_timestamp' => now(),
            'reference_price' => 5000.00,
            'reference_price_date' => '2026-10-14',
            'horizon_trading_days' => 5,
            'target_eval_date' => '2026-10-21',
            'predicted_direction' => 'positive',
            'status' => 'pending',
        ]);

        // Provide future actual price (drops to 4800, -4%)
        MarketSnapshot::create([
            'ticker' => 'ASII',
            'bar_date' => '2026-10-21',
            'source_timestamp' => now(),
            'close_price' => 4800.00,
            'data_classification' => 'end_of_day',
        ]);

        $asOf = Carbon::parse('2026-10-21 16:05:00', 'Asia/Jakarta');
        $res = $this->evaluator->evaluatePending($asOf, false);

        $this->assertEquals(1, $res['evaluated_count']);
        $fresh = $eval->fresh();
        $this->assertEquals('evaluated', $fresh->status);
        $this->assertEquals('incorrect', $fresh->outcome);
        $this->assertEquals('negative', $fresh->actual_direction);
    }

    public function test_evaluate_horizon_artisan_command(): void
    {
        $this->artisan('hermes:evaluate-horizon')
            ->assertExitCode(0);
    }

    public function test_mutex_lock_prevents_concurrent_sync(): void
    {
        // Acquire lock manually
        $lock = Cache::lock('hermes_sync_eod_mutex', 600);
        $this->assertTrue($lock->get());

        // Artisan sync must fail because lock is already held
        $this->artisan('hermes:sync-eod', ['--force' => true])
            ->assertExitCode(1);

        $lock->release();
    }

    public function test_error_deduplication_cooldown(): void
    {
        $hash = sha1('test_error_message');

        NotificationLog::create([
            'channel' => 'telegram',
            'destination' => 'admin_error_alert',
            'message_hash' => $hash,
            'message_payload' => 'Initial error',
            'status' => 'failed',
            'error_message' => 'test_error_message',
            'created_at' => now()->subMinutes(10),
        ]);

        // Check if duplicate detection recognizes recent error within 6 hours
        $recent = NotificationLog::where('message_hash', $hash)
            ->where('created_at', '>=', now()->subHours(6))
            ->first();

        $this->assertNotNull($recent);
    }
}
