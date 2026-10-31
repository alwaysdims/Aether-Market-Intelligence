<?php

namespace Tests\Feature;

use App\Models\MarketObservation;
use App\Models\MonitoringEvent;
use App\Models\PredictionSignal;
use App\Models\TelegramConversation;
use App\Services\BeiCalendarService;
use App\Services\MarketMonitoringService;
use App\Services\PredictionRecapService;
use App\Services\TelegramFormattingService;
use App\Services\TelegramTwoWayService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TahapILifecycleAndTelegramTest extends TestCase
{
    use RefreshDatabase;

    protected BeiCalendarService $calendar;

    protected MarketMonitoringService $monitorService;

    protected TelegramFormattingService $formatter;

    protected TelegramTwoWayService $chatService;

    protected PredictionRecapService $recapService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calendar = app(BeiCalendarService::class);
        $this->monitorService = app(MarketMonitoringService::class);
        $this->formatter = app(TelegramFormattingService::class);
        $this->recapService = app(PredictionRecapService::class);
        $this->chatService = app(TelegramTwoWayService::class);
    }

    /**
     * 1. Test DB commit before delivery and rollback isolation.
     */
    public function test_db_commit_before_telegram_outbox_enqueue(): void
    {
        DB::transaction(function () {
            $sig = PredictionSignal::create([
                'prediction_id' => 'PRED_TEST_COMMIT_01',
                'ticker' => 'BBCA',
                'signal_status' => 'PUBLISHED_DIRECTIONAL',
                'direction' => 'UP',
                'probability_up' => 0.75,
                'probability_down' => 0.25,
                'max_probability' => 0.75,
                'confidence_score' => 0.50,
                'model_name' => 'RandomForest',
                'model_version' => 'v1.0',
                'feature_schema_hash' => 'test_hash',
                'decision_date' => '2026-10-23',
                'decision_timestamp' => now(),
                'reference_price' => 5000.0,
                'reference_price_date' => '2026-10-23',
                'evaluation_due_date' => '2026-10-30',
                'published_at' => now(),
                'expires_at' => now()->addDays(14),
                'monitoring_status' => 'ACTIVE',
                'evaluation_status' => 'PENDING',
            ]);

            $payload = $this->formatter->formatInitialSignalMessage($sig);
            $this->formatter->enqueueOutbox('DEDUP_TEST_01', 'INITIAL_SIGNAL', $payload, $sig->prediction_id);
        });

        $this->assertDatabaseHas('prediction_signals', ['prediction_id' => 'PRED_TEST_COMMIT_01']);
        $this->assertDatabaseHas('telegram_outbox', ['dedup_key' => 'DEDUP_TEST_01', 'status' => 'QUEUED']);
    }

    /**
     * 2. Test DB rollback does not persist prediction or outbox.
     */
    public function test_db_rollback_does_not_enqueue_outbox(): void
    {
        try {
            DB::transaction(function () {
                $sig = PredictionSignal::create([
                    'prediction_id' => 'PRED_TEST_ROLLBACK',
                    'ticker' => 'BBRI',
                    'signal_status' => 'PUBLISHED_DIRECTIONAL',
                    'direction' => 'UP',
                    'probability_up' => 0.75,
                    'probability_down' => 0.25,
                    'max_probability' => 0.75,
                    'confidence_score' => 0.50,
                    'model_name' => 'RandomForest',
                    'model_version' => 'v1.0',
                    'feature_schema_hash' => 'test_hash',
                    'decision_date' => '2026-10-23',
                    'decision_timestamp' => now(),
                    'reference_price' => 2800.0,
                    'reference_price_date' => '2026-10-23',
                    'evaluation_due_date' => '2026-10-30',
                    'published_at' => now(),
                    'expires_at' => now()->addDays(14),
                    'monitoring_status' => 'ACTIVE',
                    'evaluation_status' => 'PENDING',
                ]);

                $this->formatter->enqueueOutbox('DEDUP_ROLLBACK', 'INITIAL_SIGNAL', 'Test payload', $sig->prediction_id);

                throw new \Exception('Simulated catastrophic failure before commit');
            });
        } catch (\Exception $e) {
            // Expected
        }

        $this->assertDatabaseMissing('prediction_signals', ['prediction_id' => 'PRED_TEST_ROLLBACK']);
        $this->assertDatabaseMissing('telegram_outbox', ['dedup_key' => 'DEDUP_ROLLBACK']);
    }

    /**
     * 3. Test duplicate inbound update idempotency.
     */
    public function test_inbound_duplicate_update_idempotency(): void
    {
        $update = [
            'update_id' => 'upd_99991',
            'message' => [
                'from' => ['id' => '5923895922', 'username' => 'Tester'],
                'chat' => ['id' => '5923895922'],
                'text' => '/status',
            ],
        ];

        $res1 = $this->chatService->handleInboundMessage($update);
        $this->assertEquals('success', $res1['status']);

        // Same update ID attempt: Graceful duplicate handling without unhandled query exception
        $res2 = $this->chatService->handleInboundMessage($update);
        $this->assertEquals('duplicate', $res2['status']);
        $this->assertTrue($res2['is_duplicate']);
        $this->assertEquals(1, TelegramConversation::where('telegram_update_id', 'upd_99991')->count());
    }

    /**
     * 4. Test publication boundary and 14-day expiry.
     */
    public function test_publication_boundary_and_14_day_expiry(): void
    {
        $publishedAt = Carbon::parse('2026-10-16 16:05:00', 'Asia/Jakarta');
        $expiresAt = $publishedAt->copy()->addDays(14); // 2026-10-30 16:05:00 WIB

        $sig = PredictionSignal::create([
            'prediction_id' => 'PRED_TEST_EXPIRY',
            'ticker' => 'MEDC',
            'signal_status' => 'PUBLISHED_DIRECTIONAL',
            'direction' => 'UP',
            'probability_up' => 0.72,
            'probability_down' => 0.28,
            'max_probability' => 0.72,
            'confidence_score' => 0.44,
            'model_name' => 'RandomForest',
            'model_version' => 'v1.0',
            'feature_schema_hash' => 'hash_exp',
            'decision_date' => '2026-10-16',
            'decision_timestamp' => $publishedAt,
            'reference_price' => 1400.0,
            'reference_price_date' => '2026-10-16',
            'evaluation_due_date' => '2026-10-23',
            'published_at' => $publishedAt,
            'expires_at' => $expiresAt,
            'monitoring_status' => 'ACTIVE',
            'evaluation_status' => 'PENDING',
            'target_price' => 1550.0,
            'invalidation_price' => 1350.0,
        ]);

        // Observation prior to expiry (Day 10)
        $day10 = Carbon::parse('2026-10-26 10:00:00', 'Asia/Jakarta');
        $res1 = $this->monitorService->runMonitoringCycle($day10);
        $this->assertEquals(0, $res1['signals_expired']);
        $this->assertEquals('ACTIVE', $sig->fresh()->monitoring_status);

        // Time reaches after expiry (Day 15)
        $day15 = Carbon::parse('2026-10-31 09:00:00', 'Asia/Jakarta');
        $res2 = $this->monitorService->runMonitoringCycle($day15);
        $this->assertEquals(1, $res2['signals_expired']);
        $this->assertEquals('EXPIRED', $sig->fresh()->monitoring_status);

        // Check monitoring event
        $ev = MonitoringEvent::where('prediction_id', 'PRED_TEST_EXPIRY')->where('event_type', 'EXPIRED')->first();
        $this->assertNotNull($ev);
        $this->assertStringContainsString('Masa pemantauan maksimal 14 hari kalender telah berakhir', $ev->description);
    }

    /**
     * 5. Test 5 trading days horizon calculation does not shift across weekends or holidays.
     */
    public function test_horizon_5_trading_days_skips_weekends_and_holidays(): void
    {
        // 2026-10-16 is Friday
        $startDate = Carbon::parse('2026-10-16', 'Asia/Jakarta');
        $due = $this->calendar->addTradingDays($startDate, 5);
        // Friday + 1 = Mon (19), + 2 = Tue (20), + 3 = Wed (21), + 4 = Thu (22), + 5 = Fri (23)
        $this->assertEquals('2026-10-23', $due->format('Y-m-d'));

        // Test over a known BEI holiday: 2026-05-01 (Labor day, Friday)
        $apr30 = Carbon::parse('2026-04-30', 'Asia/Jakarta'); // Thursday
        $dueWithHoliday = $this->calendar->addTradingDays($apr30, 2);
        // Day 1: May 4 (Monday, skipping Friday May 1 holiday and weekend)
        // Day 2: May 5 (Tuesday)
        $this->assertEquals('2026-05-05', $dueWithHoliday->format('Y-m-d'));
    }

    /**
     * 6. Test ambiguous candle where both target and invalidation are touched.
     */
    public function test_ambiguous_candle_both_target_and_invalidation_hit(): void
    {
        $publishedAt = Carbon::parse('2026-10-16 16:05:00', 'Asia/Jakarta');
        $sig = PredictionSignal::create([
            'prediction_id' => 'PRED_TEST_AMBIGUOUS',
            'ticker' => 'ARTO',
            'signal_status' => 'PUBLISHED_DIRECTIONAL',
            'direction' => 'UP',
            'probability_up' => 0.75,
            'probability_down' => 0.25,
            'max_probability' => 0.75,
            'confidence_score' => 0.50,
            'model_name' => 'RandomForest',
            'model_version' => 'v1.0',
            'feature_schema_hash' => 'hash_amb',
            'decision_date' => '2026-10-16',
            'decision_timestamp' => $publishedAt,
            'reference_price' => 600.0,
            'reference_price_date' => '2026-10-16',
            'evaluation_due_date' => '2026-10-23',
            'published_at' => $publishedAt,
            'expires_at' => $publishedAt->copy()->addDays(14),
            'monitoring_status' => 'ACTIVE',
            'evaluation_status' => 'PENDING',
            'target_price' => 650.0,
            'invalidation_price' => 550.0,
        ]);

        // Observation on Oct 19: High touches 660 (target), Low touches 540 (invalidation)
        MarketObservation::create([
            'ticker' => 'ARTO',
            'bar_date' => '2026-10-19',
            'observation_time' => Carbon::parse('2026-10-19 16:00:00', 'Asia/Jakarta'),
            'open_price' => 600.0,
            'high_price' => 660.0,
            'low_price' => 540.0,
            'close_price' => 590.0,
            'volume' => 5000000,
            'source' => 'Yahoo Finance',
            'is_eod_final' => true,
        ]);

        $res = $this->monitorService->runMonitoringCycle(Carbon::parse('2026-10-19 16:10:00', 'Asia/Jakarta'));
        $this->assertEquals('AMBIGUOUS', $sig->fresh()->monitoring_status);

        $event = MonitoringEvent::where('prediction_id', 'PRED_TEST_AMBIGUOUS')->where('event_type', 'AMBIGUOUS_HIT')->first();
        $this->assertNotNull($event);
        $this->assertStringContainsString('AMBIGUOUS', $event->description);
    }

    /**
     * 7. Test offline gap detection.
     */
    public function test_offline_gap_detected_and_recorded(): void
    {
        $publishedAt = Carbon::parse('2026-10-16 16:05:00', 'Asia/Jakarta');
        $sig = PredictionSignal::create([
            'prediction_id' => 'PRED_TEST_GAP',
            'ticker' => 'TLKM',
            'signal_status' => 'PUBLISHED_DIRECTIONAL',
            'direction' => 'UP',
            'probability_up' => 0.72,
            'probability_down' => 0.28,
            'max_probability' => 0.72,
            'confidence_score' => 0.44,
            'model_name' => 'RandomForest',
            'model_version' => 'v1.0',
            'feature_schema_hash' => 'hash_gap',
            'decision_date' => '2026-10-16',
            'decision_timestamp' => $publishedAt,
            'reference_price' => 2100.0,
            'reference_price_date' => '2026-10-16',
            'evaluation_due_date' => '2026-10-23',
            'published_at' => $publishedAt,
            'expires_at' => $publishedAt->copy()->addDays(14),
            'monitoring_status' => 'ACTIVE',
            'evaluation_status' => 'PENDING',
            'target_price' => 2300.0,
            'invalidation_price' => 1950.0,
        ]);

        // Initial event on Oct 16
        MonitoringEvent::create([
            'prediction_id' => 'PRED_TEST_GAP',
            'event_type' => 'STARTED',
            'event_time' => $publishedAt,
            'first_observed_at' => $publishedAt,
            'description' => 'Pemantauan dimulai',
        ]);

        // System offline until Oct 21 (5 days later)
        MarketObservation::create([
            'ticker' => 'TLKM',
            'bar_date' => '2026-10-21',
            'observation_time' => Carbon::parse('2026-10-21 16:00:00', 'Asia/Jakarta'),
            'open_price' => 2120.0,
            'high_price' => 2150.0,
            'low_price' => 2100.0,
            'close_price' => 2140.0,
            'volume' => 10000000,
            'source' => 'Yahoo Finance',
            'is_eod_final' => true,
        ]);

        $res = $this->monitorService->runMonitoringCycle(Carbon::parse('2026-10-21 16:05:00', 'Asia/Jakarta'));
        $this->assertGreaterThan(0, $res['gaps_detected']);

        $gapEvent = MonitoringEvent::where('prediction_id', 'PRED_TEST_GAP')->where('event_type', 'GAP_OFFLINE_DETECTED')->first();
        $this->assertNotNull($gapEvent);
        $this->assertStringContainsString('Celah pemantauan offline terdeteksi', $gapEvent->description);
    }

    /**
     * 8. Test NO_SIGNAL / ABSTAIN is never forced to directional.
     */
    public function test_no_signal_abstain_never_forced_to_directional(): void
    {
        $sig = PredictionSignal::create([
            'prediction_id' => 'PRED_TEST_ABSTAIN',
            'ticker' => 'SMRA',
            'signal_status' => 'NO_SIGNAL_ABSTAIN',
            'direction' => 'ABSTAIN',
            'probability_up' => 0.6505,
            'probability_down' => 0.3495,
            'max_probability' => 0.6505,
            'confidence_score' => 0.3010, // Below 0.40 threshold
            'model_name' => 'RandomForest',
            'model_version' => 'v1.0',
            'feature_schema_hash' => 'hash_smra',
            'reason_for_abstain' => 'CONFIDENCE_BELOW_THRESHOLD (Confidence: 0.30 < 0.40)',
            'decision_date' => '2026-10-16',
            'decision_timestamp' => now(),
            'reference_price' => 250.0,
            'reference_price_date' => '2026-10-16',
            'evaluation_due_date' => '2026-10-23',
            'published_at' => now(),
            'expires_at' => now()->addDays(14),
            'monitoring_status' => 'NOT_MONITORED',
            'evaluation_status' => 'PENDING',
        ]);

        $payload = $this->formatter->formatInitialSignalMessage($sig);
        $this->assertStringContainsString('Status Sinyal : NO_SIGNAL_ABSTAIN', $payload);
        $this->assertStringContainsString('Arah Prediksi : ABSTAIN', $payload);
        $this->assertStringContainsString('Tingkat Yakin : 30.1%', $payload);
        $this->assertStringNotContainsString('ONE CLICK FOLLOW', $payload);
        $this->assertStringNotContainsString('SET AUTO TRADING', $payload);
    }

    /**
     * 9. Test two-way chat security: unauthorized user is rejected.
     */
    public function test_unauthorized_telegram_user_rejected(): void
    {
        $update = [
            'update_id' => 'upd_unauth_01',
            'message' => [
                'from' => ['id' => '11223344', 'username' => 'UnknownHacker'],
                'chat' => ['id' => '11223344'],
                'text' => '/status',
            ],
        ];

        $res = $this->chatService->handleInboundMessage($update);
        $this->assertEquals('rejected', $res['status']);
        $this->assertEquals('UNAUTHORIZED_ACCESS', $res['intent']);
        $this->assertStringContainsString('Akses Ditolak', $res['reply_text']);
    }

    /**
     * 10. Test recap metrics separating historical fallbacks from forward cohort.
     */
    public function test_recap_metrics_separates_historical_fallbacks_from_forward_cohort(): void
    {
        $stats = $this->recapService->generateRecapMetrics();
        $this->assertArrayHasKey('total_signals', $stats);
        $this->assertArrayHasKey('historical_fallback_evaluations', $stats);
        $this->assertArrayHasKey('directional_accuracy', $stats);

        // When denominator is zero, directional accuracy must be 'N/A'
        if ($stats['eval_correct'] + $stats['eval_incorrect'] === 0) {
            $this->assertEquals('N/A', $stats['directional_accuracy']);
        }
    }

    /**
     * 11. Test probability summation and confidence definition.
     */
    public function test_probability_sum_and_confidence_definition(): void
    {
        $pUp = 0.6505;
        $pDown = 0.3495;
        $sum = $pUp + $pDown;
        $this->assertEqualsWithDelta(1.0, $sum, 0.0001);

        $conf = abs($pUp - 0.5) * 2;
        $this->assertEqualsWithDelta(0.301, $conf, 0.0001);

        // Tau = 0.70 equivalence
        $tau = 0.70;
        $minConfForDirectional = ($tau - 0.50) * 2; // 0.40
        $this->assertEqualsWithDelta(0.40, $minConfForDirectional, 0.0001);
    }
}
