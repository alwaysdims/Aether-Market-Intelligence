<?php

namespace Tests\Feature;

use App\Models\AnalysisRun;
use App\Models\MarketAnalysis;
use App\Models\MarketSnapshot;
use App\Models\NewsItem;
use App\Models\NotificationLog;
use App\Models\PostHorizonEvaluation;
use App\Services\AiMarketAnalysisService;
use App\Services\BeiCalendarService;
use App\Services\HermesAdapterService;
use App\Services\PostHorizonEvaluatorService;
use App\Services\TelegramNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HermesPhase4Test extends TestCase
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
     * 1. Test EOD pipeline formatting and notification creation.
     */
    public function test_telegram_eod_message_formatting_and_contents(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_E2E_001',
            'trigger_type' => 'cli',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'duration_seconds' => 5.2,
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Rezim akumulasi kuat di sektor perbankan dan energi.',
            'impact_direction' => 'positive',
            'confidence_score' => 0.88,
            'structured_output' => [
                'impacted_tickers' => [
                    ['ticker' => 'MEDC', 'direction' => 'positive', 'rationale' => 'Lonjakan harga komoditas minyak.'],
                    ['ticker' => 'BBCA', 'direction' => 'positive', 'rationale' => 'Inflow asing solid.'],
                ],
            ],
            'validation_status' => 'valid_schema',
        ]);

        MarketSnapshot::create([
            'analysis_run_id' => $run->id,
            'ticker' => 'MEDC',
            'bar_date' => '2026-10-14',
            'source_timestamp' => now(),
            'close_price' => 1545.00,
            'data_classification' => 'end_of_day',
        ]);

        $msg = $this->telegram->formatEodMessage($run, $analysis);

        $this->assertStringContainsString('AETHER MARKET INTELLIGENCE — EOD REPORT', $msg);
        $this->assertStringContainsString('ag/gemini-3.8-flash-high [VALID_SCHEMA]', $msg);
        $this->assertStringContainsString('POSITIVE', $msg);
        $this->assertStringContainsString('MEDC', $msg);
        $this->assertStringContainsString('BBCA', $msg);
        $this->assertStringContainsString('Dilarang dijadikan rekomendasi transaksi', $msg);
    }

    /**
     * 2. Test scheduled run skips before 16:00 WIB (Candle not final).
     */
    public function test_scheduled_run_aborts_before_market_close(): void
    {
        // 14:30 WIB is during active trading hours, not final
        $time = Carbon::parse('2026-10-16 14:30:00', 'Asia/Jakarta');
        $this->assertFalse($this->calendar->isEodFinal($time));
    }

    /**
     * 3. Test empty market data handling in adapter service.
     */
    public function test_empty_market_data_handled_gracefully(): void
    {
        $adapter = app(HermesAdapterService::class);
        $res = $adapter->getMarketSnapshots(['NON_EXISTENT_TICKER_XYZ']);
        
        $this->assertIsArray($res);
        $this->assertTrue(isset($res['ok']));
    }

    /**
     * 4 & 5 & 6. Test AI router timeout / failure produces properly labeled fallback.
     */
    public function test_ai_fallback_is_explicitly_labeled(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_FALLBACK_001',
            'trigger_type' => 'cli',
            'status' => 'running',
            'started_at' => now(),
        ]);

        // Mock AI service to invoke fallback directly
        $aiService = app(AiMarketAnalysisService::class);
        $reflection = new \ReflectionClass($aiService);
        $method = $reflection->getMethod('buildFallbackAnalysis');
        $method->setAccessible(true);

        /** @var MarketAnalysis $analysis */
        $analysis = $method->invoke($aiService, $run, "Market Summary Text");

        $this->assertEquals('fallback', $analysis->validation_status);
        $this->assertNotNull($analysis->impact_direction);

        MarketSnapshot::create([
            'analysis_run_id' => $run->id,
            'ticker' => 'BBCA',
            'bar_date' => '2026-10-14',
            'source_timestamp' => now(),
            'close_price' => 6125.00,
            'data_classification' => 'end_of_day',
        ]);

        // Register to post-horizon evaluator
        $evals = $this->evaluator->registerPredictions($analysis, 5);
        $this->assertNotEmpty($evals);
        $firstEval = $evals[0];

        $this->assertTrue($firstEval->is_fallback);
        $this->assertEquals('fallback', $firstEval->validation_status);

        // Check metrics distinguish fallback
        $metrics = $this->evaluator->getMetricsSummary();
        $this->assertGreaterThanOrEqual(1, $metrics['fallback_count']);
    }

    /**
     * 7. Test CLI failure does not throw or delete analysis.
     */
    public function test_telegram_failure_does_not_corrupt_analysis(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_TG_FAIL_001',
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

        // Telegram sender with dummy invalid CLI command
        config(['services.hermes.cli_command' => 'non_existent_hermes_cli_command_xyz']);
        $res = (new TelegramNotificationService())->sendEodNotification($run, $analysis);

        $this->assertEquals('failed', $res['status']);
        $this->assertDatabaseHas('market_analyses', ['id' => $analysis->id]);
        $this->assertDatabaseHas('notification_logs', [
            'analysis_run_id' => $run->id,
            'status' => 'failed',
        ]);
    }

    /**
     * 8. Test retry does not duplicate sent notification.
     */
    public function test_retry_does_not_duplicate_sent_notification(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_NO_DUPE_001',
            'trigger_type' => 'cli',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'No duplicate test',
            'impact_direction' => 'positive',
            'validation_status' => 'valid_schema',
        ]);

        NotificationLog::create([
            'channel' => 'telegram',
            'destination' => 'telegram:5923895922',
            'analysis_run_id' => $run->id,
            'market_analysis_id' => $analysis->id,
            'message_hash' => 'hash_test_123',
            'message_payload' => 'Payload already sent',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $res = $this->telegram->sendEodNotification($run, $analysis, false);

        $this->assertEquals('skipped_duplicate', $res['status']);
        $this->assertEquals(1, NotificationLog::where('analysis_run_id', $run->id)->count());
    }

    /**
     * 9. Test concurrent send locks.
     */
    public function test_concurrent_send_is_locked(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_CONCURRENT_001',
            'trigger_type' => 'cli',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $lockKey = 'telegram_send_lock_' . $run->id;
        $lock = Cache::lock($lockKey, 30);
        $this->assertTrue($lock->get());

        $res = $this->telegram->sendEodNotification($run, null, false);
        $this->assertEquals('skipped_concurrent', $res['status']);

        $lock->release();
    }

    /**
     * 10. Test scheduler skips on non-trading day.
     */
    public function test_scheduler_skips_on_weekend_and_holiday(): void
    {
        $saturday = Carbon::parse('2026-10-17 16:05:00', 'Asia/Jakarta');
        $this->assertFalse($this->calendar->isTradingDay($saturday));

        $holiday = Carbon::parse('2026-08-17 16:05:00', 'Asia/Jakarta');
        $this->assertFalse($this->calendar->isTradingDay($holiday));
    }

    /**
     * 11 & 12 & 13. Test horizon evaluation timing, missing data, and idempotency.
     */
    public function test_evaluator_horizon_lifecycle_and_idempotency(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'RUN_LIFECYCLE_001',
            'trigger_type' => 'cli',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Lifecycle test',
            'impact_direction' => 'positive',
            'confidence_score' => 0.90,
            'structured_output' => [],
            'validation_status' => 'valid_schema',
        ]);

        MarketSnapshot::create([
            'ticker' => 'BBCA',
            'bar_date' => '2026-10-14',
            'source_timestamp' => now(),
            'close_price' => 6125.00,
            'data_classification' => 'end_of_day',
        ]);

        // Register prediction
        $evals = $this->evaluator->registerPredictions($analysis, 5);
        $this->assertCount(1, $evals);
        $eval = $evals[0];

        // 11. Prediksi belum jatuh tempo: Evaluasi 3 hari setelah tanggal (belum 5 hari)
        $day3 = Carbon::parse('2026-10-19', 'Asia/Jakarta');
        $res3 = $this->evaluator->evaluatePending($day3);
        $this->assertEquals(0, $res3['evaluated_count']);
        $this->assertEquals('pending', $eval->fresh()->status);

        // 12. Prediksi jatuh tempo (5 hari = 2026-10-21) tapi data harga belum tersedia
        $day5 = Carbon::parse('2026-10-21', 'Asia/Jakarta');
        $res5 = $this->evaluator->evaluatePending($day5);
        $this->assertEquals(0, $res5['evaluated_count']);
        $this->assertEquals('pending', $eval->fresh()->status); // Tetap pending menunggu data

        // Sediakan data harga di 2026-10-21 (harga naik ke 6300, +2.85%)
        MarketSnapshot::create([
            'ticker' => 'BBCA',
            'bar_date' => '2026-10-21',
            'source_timestamp' => now(),
            'close_price' => 6300.00,
            'data_classification' => 'end_of_day',
        ]);

        $resEval = $this->evaluator->evaluatePending($day5);
        $this->assertEquals(1, $resEval['evaluated_count']);
        $fresh = $eval->fresh();
        $this->assertEquals('evaluated', $fresh->status);
        $this->assertEquals('correct', $fresh->outcome);

        // 13. Evaluasi diulang tanpa duplikasi
        $resRepeat = $this->evaluator->evaluatePending($day5);
        $this->assertEquals(0, $resRepeat['evaluated_count']); // Sudah dievaluasi, tidak diproses ulang
        $this->assertEquals(1, PostHorizonEvaluation::where('market_analysis_id', $analysis->id)->count());
    }

    /**
     * 14. Test secrets are never present in formatted payload or logs.
     */
    public function test_secrets_are_redacted_from_error_and_payload(): void
    {
        $rawError = "Error connecting to db: password=super_secret_123; bot_token=123456789:abcdefghijklmnopqrstuvwxyz012345678; api_key=sk-abcdefghijklmnopqrstuvwxyz123456";
        
        $res = $this->telegram->sendErrorAlert($rawError);
        $this->assertArrayHasKey('status', $res);

        $log = NotificationLog::where('channel', 'telegram')->latest()->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('super_secret_123', $log->message_payload);
        $this->assertStringNotContainsString('super_secret_123', $log->error_message);
        $this->assertStringContainsString('password=[REDACTED]', $log->message_payload);
        $this->assertStringContainsString('[REDACTED_BOT_TOKEN]', $log->message_payload);
        $this->assertStringContainsString('[REDACTED_API_KEY]', $log->message_payload);
    }
}
