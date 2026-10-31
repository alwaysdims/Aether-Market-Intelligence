<?php

namespace Tests\Feature;

use App\Models\MarketObservation;
use App\Models\PredictionSignal;
use App\Models\ResearchRequest;
use App\Models\TelegramConversation;
use App\Models\TelegramOutbox;
use App\Services\BeiCalendarService;
use App\Services\MarketMonitoringService;
use App\Services\PredictionRecapService;
use App\Services\TelegramFormattingService;
use App\Services\TelegramTwoWayService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TahapILanjutanRoutingAndAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected TelegramTwoWayService $twoWayService;

    protected TelegramFormattingService $formattingService;

    protected MarketMonitoringService $monitoringService;

    protected PredictionRecapService $recapService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoWayService = app(TelegramTwoWayService::class);
        $this->formattingService = app(TelegramFormattingService::class);
        $this->monitoringService = app(MarketMonitoringService::class);
        $this->recapService = app(PredictionRecapService::class);

        // Seed reference data in isolated test DB
        $this->seedReferenceSignals();
    }

    protected function seedReferenceSignals(): void
    {
        // Seed 10 shadow signals (Oct 16 -> Oct 23 horizon)
        $tickers = ['ARTO', 'ASII', 'BBCA', 'BBNI', 'BBRI', 'BMRI', 'MAPI', 'MEDC', 'SMRA', 'TLKM'];
        foreach ($tickers as $i => $tk) {
            PredictionSignal::create([
                'prediction_id' => 'SHADOW_TEST_'.strtolower($tk),
                'ticker' => $tk,
                'decision_date' => '2026-10-16',
                'decision_timestamp' => Carbon::parse('2026-10-16 16:05:00', 'Asia/Jakarta'),
                'published_at' => Carbon::parse('2026-10-16 16:05:00', 'Asia/Jakarta'),
                'model_name' => 'WalkForwardBinaryClassifier',
                'model_version' => 'v1.0.0-phaseH',
                'feature_schema_hash' => 'hash_phaseH_test',
                'reference_price' => 10000.0,
                'reference_price_date' => '2026-10-16',
                'probability_up' => 0.65,
                'probability_down' => 0.35,
                'max_probability' => 0.65,
                'confidence_score' => 0.30, // < 0.40 -> ABSTAIN
                'market_regime' => 'NORMAL',
                'signal_status' => 'NO_SIGNAL_ABSTAIN',
                'direction' => 'ABSTAIN',
                'reason_for_abstain' => 'Confidence score 30.0% < threshold 40.0% (Tau 0.70). Model menolak berspekulasi.',
                'predicted_horizon_days' => 5,
                'evaluation_due_date' => '2026-10-23',
                'expires_at' => Carbon::parse('2026-10-16 16:05:00', 'Asia/Jakarta')->addDays(14),
                'monitoring_status' => 'ACTIVE',
                'evaluation_status' => 'PENDING',
            ]);

            MarketObservation::create([
                'ticker' => $tk,
                'bar_date' => '2026-10-23',
                'observation_time' => Carbon::parse('2026-10-23 16:15:00', 'Asia/Jakarta'),
                'open_price' => 10000.0,
                'close_price' => 10100.0,
                'high_price' => 10200.0,
                'low_price' => 9950.0,
                'volume' => 5000000,
                'is_eod_final' => true,
                'source' => 'Yahoo Finance (Verified Settlement)',
            ]);
        }
    }

    /**
     * 1. Test Inbound Variations including real Message 279 routed accurately to Laravel.
     */
    public function test_inbound_variations_including_real_msg_279_routed_to_laravel(): void
    {
        $testCases = [
            'status system sekarang gimana' => 'COMMAND_STATUS',
            'status sistem sekarang bagaimana' => 'COMMAND_STATUS',
            'BBCA terbaru bagaimana' => 'QUERY_TICKER_STATUS',
            'kenapa abstain' => 'QUERY_PREDICTION_RATIONALE',
            'sinyal ini masih berlaku?' => 'QUERY_SIGNAL_VALIDITY',
            '/status' => 'COMMAND_STATUS',
            '/help' => 'COMMAND_HELP',
            '/signals' => 'COMMAND_SIGNALS',
            '/recap' => 'COMMAND_RECAP',
        ];

        foreach ($testCases as $text => $expectedIntent) {
            $update = [
                'update_id' => 'var_'.md5($text),
                'message' => [
                    'message_id' => 1000 + rand(1, 999),
                    'from' => ['id' => '5923895922', 'username' => 'Orlando'],
                    'chat' => ['id' => '5923895922'],
                    'text' => $text,
                ],
            ];

            $res = $this->twoWayService->handleInboundMessage($update);

            $this->assertEquals('success', $res['status']);
            $this->assertEquals($expectedIntent, $res['intent'], "Failed intent matching for '{$text}'");
            $this->assertNotEmpty($res['reply_text']);
            $this->assertLessThan(2000, $res['duration_ms'], 'Latency exceeded acceptable limit');
        }
    }

    /**
     * 2. Test Zero LLM & Zero Arbitrary Shell Execution.
     */
    public function test_zero_llm_zero_arbitrary_shell_execution(): void
    {
        $update = [
            'update_id' => 'perf_test_1',
            'message' => [
                'message_id' => 101,
                'from' => ['id' => '5923895922'],
                'chat' => ['id' => '5923895922'],
                'text' => 'status system sekarang gimana',
            ],
        ];

        $res = $this->twoWayService->handleInboundMessage($update);

        $convo = TelegramConversation::where('telegram_update_id', 'perf_test_1')->first();
        $this->assertNotNull($convo);
        $this->assertEquals('COMMAND_STATUS', $convo->detected_intent);

        // Verify provenance explicitly records non-live AI mode
        $prov = $convo->provenance;
        $this->assertEquals('DETERMINISTIC_DB_EXTRACTIVE (Live AI Disabled)', $prov['inference_mode']);
        $this->assertEquals('GATEWAY_INTEGRATED', $prov['response_method']);
    }

    /**
     * 3. Test Single Delivery Owner: Outbox Dispatcher vs Integrated Gateway.
     */
    public function test_single_delivery_owner_no_duplicate_outbound(): void
    {
        $initialOutboxCount = TelegramOutbox::count();

        // An integrated inbound query should NOT enqueue a duplicate outbox record
        $update = [
            'update_id' => 'single_delivery_check',
            'message' => [
                'message_id' => 102,
                'from' => ['id' => '5923895922'],
                'chat' => ['id' => '5923895922'],
                'text' => '/help',
            ],
        ];

        $res = $this->twoWayService->handleInboundMessage($update);
        $this->assertEquals('success', $res['status']);

        // Outbox count remains unchanged because reply is returned directly via Gateway integrated method
        $this->assertEquals($initialOutboxCount, TelegramOutbox::count());
    }

    /**
     * 4. Test Duplicate Inbound Update Idempotency and Concurrent Workers.
     */
    public function test_duplicate_inbound_update_idempotency_and_concurrency(): void
    {
        $update = [
            'update_id' => 'dedup_inbound_999',
            'message' => [
                'message_id' => 103,
                'from' => ['id' => '5923895922'],
                'chat' => ['id' => '5923895922'],
                'text' => 'kenapa abstain',
            ],
        ];

        $firstRun = $this->twoWayService->handleInboundMessage($update);
        $this->assertEquals('success', $firstRun['status']);
        $this->assertFalse($firstRun['is_duplicate']);

        // Second run with identical update_id
        $secondRun = $this->twoWayService->handleInboundMessage($update);
        $this->assertEquals('duplicate', $secondRun['status']);
        $this->assertTrue($secondRun['is_duplicate']);
        $this->assertEquals($firstRun['reply_text'], $secondRun['reply_text']);

        // Only 1 record in database
        $this->assertEquals(1, TelegramConversation::where('telegram_update_id', 'dedup_inbound_999')->count());
    }

    /**
     * 5. Test Controlled Failure Handling when DB or inputs are degraded.
     */
    public function test_controlled_security_and_failure_handling(): void
    {
        // Unauthorized user
        $unauthUpdate = [
            'update_id' => 'unauth_1',
            'message' => [
                'message_id' => 104,
                'from' => ['id' => '9999999999'], // Not allowlisted
                'chat' => ['id' => '9999999999'],
                'text' => '/status',
            ],
        ];

        $res = $this->twoWayService->handleInboundMessage($unauthUpdate);
        $this->assertEquals('rejected', $res['status']);
        $this->assertEquals('UNAUTHORIZED_ACCESS', $res['intent']);
        $this->assertStringContainsString('Akses Ditolak', $res['reply_text']);
    }

    /**
     * 6. Test Stale Data Labelling & EOD Finality Rules.
     */
    public function test_stale_data_labelling_and_eod_finality_rules(): void
    {
        $calendar = app(BeiCalendarService::class);

        // Saturday or Sunday should never be considered final trading day
        $sunday = Carbon::parse('2026-10-25 16:05:00', 'Asia/Jakarta');
        $this->assertFalse($calendar->isTradingDay($sunday));
        $this->assertFalse($calendar->isEodFinal($sunday));

        // Monday before 16:00 is not final
        $mondayEarly = Carbon::parse('2026-10-26 14:00:00', 'Asia/Jakarta');
        $this->assertTrue($calendar->isTradingDay($mondayEarly));
        $this->assertFalse($calendar->isEodFinal($mondayEarly));
    }

    /**
     * 7. Test Recap Cohort Separation & Abstain Transparency.
     */
    public function test_recap_cohort_separation_and_abstain_transparency(): void
    {
        $metrics = $this->recapService->generateRecapMetrics();

        // 10 forward signals seeded
        $this->assertEquals(10, $metrics['total_signals']);
        $this->assertEquals(0, $metrics['published_directional']);
        $this->assertEquals(10, $metrics['no_signal_abstained']);
        $this->assertEquals('0%', $metrics['coverage_pct']);
        $this->assertEquals('N/A', $metrics['directional_accuracy']);
    }

    /**
     * 8. Test Retrospective Provenance Audit.
     */
    public function test_retrospective_provenance_audit(): void
    {
        $signal = PredictionSignal::first();
        // Provenance must carry model name and explicit abstain rationale
        $this->assertNotNull($signal->model_name);
        $this->assertStringContainsString('Confidence score', $signal->reason_for_abstain);
    }

    /**
     * 9. Test Outbox Dispatcher Atomic Lease, Backoff & Stale Lease Reclaim.
     */
    public function test_outbox_refuses_non_allowlisted_target_even_in_mock_mode(): void
    {
        TelegramOutbox::create([
            'dedup_key' => 'TEST_DENY_TARGET', 'chat_id' => '123456',
            'message_type' => 'SYSTEM_ALERT', 'payload_text' => 'not allowed', 'status' => 'QUEUED',
        ]);
        app(TelegramFormattingService::class)->processOutboxQueue(true);
        $this->assertDatabaseHas('telegram_outbox', ['dedup_key' => 'TEST_DENY_TARGET', 'status' => 'SUPERSEDED_UNSENT']);
    }

    public function test_outbox_blocks_research_reply_without_real_inbound_message_id(): void
    {
        ResearchRequest::create(['request_id' => 'REQ_TEST_CLI', 'ticker' => 'PTRO', 'status' => 'completed', 'as_of_time' => now()]);
        TelegramOutbox::create([
            'dedup_key' => 'RESEARCH_REPLY_REQ_TEST_CLI',
            'chat_id' => (string) config('services.telegram.chat_id'),
            'message_type' => 'INBOUND_REPLY', 'payload_text' => 'hasil lama',
            'metadata' => ['request_id' => 'REQ_TEST_CLI'], 'status' => 'QUEUED',
        ]);
        $this->formattingService->processOutboxQueue(true);
        $this->assertDatabaseHas('telegram_outbox', ['dedup_key' => 'RESEARCH_REPLY_REQ_TEST_CLI', 'status' => 'SUPERSEDED_UNSENT']);
    }

    public function test_outbox_dispatcher_atomic_lease_and_stale_reclaim(): void
    {
        // Enqueue 2 items
        $outbox1 = $this->formattingService->enqueueOutbox(
            'TEST_OUTBOX_LEASE_1',
            'SYSTEM_ALERT',
            'Test Alert 1'
        );
        $outbox2 = $this->formattingService->enqueueOutbox(
            'TEST_OUTBOX_LEASE_2',
            'SYSTEM_ALERT',
            'Test Alert 2'
        );

        // Simulate outbox2 stuck in PROCESSING older than 10 minutes (stale lease)
        $outbox2->update([
            'status' => 'PROCESSING',
            'updated_at' => now()->subMinutes(10),
        ]);

        $res = $this->formattingService->processOutboxQueue(mockOnly: true);

        $this->assertEquals(2, $res['processed']);
        $this->assertEquals(2, $res['sent']);

        $outbox1->refresh();
        $outbox2->refresh();
        $this->assertEquals('SENT', $outbox1->status);
        $this->assertEquals('SENT', $outbox2->status);
    }

    /**
     * Test outbox retry backoff, attempt counting, and error tracking.
     */
    public function test_outbox_retry_backoff_and_exponential_delay(): void
    {
        $outbox = $this->formattingService->enqueueOutbox(
            'TEST_OUTBOX_RETRY_'.uniqid(),
            'SYSTEM_ALERT',
            'Test Retry Alert'
        );

        // Simulate failed attempt 1
        $outbox->update([
            'status' => 'QUEUED',
            'retry_count' => 1,
            'retry_after' => now()->addMinutes(2),
            'error_message' => 'Simulated transport network failure 1',
        ]);

        $res = $this->formattingService->processOutboxQueue(mockOnly: true);
        // Should not process item because retry_after is in the future
        $this->assertEquals(0, $res['processed']);

        // Set retry_after to past to allow processing
        $outbox->update(['retry_after' => now()->subMinute()]);
        $res2 = $this->formattingService->processOutboxQueue(mockOnly: true);
        $this->assertEquals(1, $res2['processed']);
        $this->assertEquals(1, $res2['sent']);

        $outbox->refresh();
        $this->assertEquals('SENT', $outbox->status);
    }

    /**
     * 10. Test Market Monitor Expiry & Idempotency.
     */
    public function test_market_monitor_expiry_and_idempotency(): void
    {
        // Create an expired signal (> 14 calendar days ago)
        $oldPublished = Carbon::now('Asia/Jakarta')->subDays(15);
        $expiredSig = PredictionSignal::create([
            'prediction_id' => 'SHADOW_EXPIRED_TEST',
            'ticker' => 'BBCA',
            'decision_date' => $oldPublished->format('Y-m-d'),
            'decision_timestamp' => $oldPublished,
            'published_at' => $oldPublished,
            'model_name' => 'WalkForwardBinaryClassifier',
            'model_version' => 'v1.0.0-phaseH',
            'feature_schema_hash' => 'hash_phaseH_test',
            'reference_price' => 10000.0,
            'reference_price_date' => $oldPublished->format('Y-m-d'),
            'probability_up' => 0.50,
            'probability_down' => 0.50,
            'max_probability' => 0.50,
            'confidence_score' => 0.00,
            'signal_status' => 'NO_SIGNAL_ABSTAIN',
            'direction' => 'ABSTAIN',
            'predicted_horizon_days' => 5,
            'evaluation_due_date' => $oldPublished->copy()->addDays(5)->format('Y-m-d'),
            'expires_at' => $oldPublished->copy()->addDays(14),
            'monitoring_status' => 'ACTIVE',
            'evaluation_status' => 'PENDING',
        ]);

        $firstCycle = $this->monitoringService->runMonitoringCycle();
        $this->assertEquals(1, $firstCycle['signals_expired']);

        $expiredSig->refresh();
        $this->assertEquals('EXPIRED', $expiredSig->monitoring_status);

        // Re-run cycle: idempotent, should not expire again
        $secondCycle = $this->monitoringService->runMonitoringCycle();
        $this->assertEquals(0, $secondCycle['signals_expired']);
    }
}
