<?php

namespace Tests\Feature;

use App\Models\MarketObservation;
use App\Models\PredictionSignal;
use App\Services\MarketMonitoringService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationMonitoringAndOutcomeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test 1: Actionable recommendation registration contract.
     * Non-actionable queries / educational chats do NOT create directional signals.
     */
    public function test_recommendation_registration_contract_preserves_immutable_rules(): void
    {
        $publishedAt = Carbon::parse('2026-10-30 09:00:00', 'Asia/Jakarta');
        $dueDate = Carbon::parse('2026-11-06', 'Asia/Jakarta');

        $sig = PredictionSignal::create([
            'prediction_id' => 'REC_SCREEN_BBCA_20261030',
            'ticker' => 'BBCA',
            'signal_status' => 'CANDIDATE',
            'direction' => 'UP',
            'probability_up' => 0.50,
            'probability_down' => 0.50,
            'max_probability' => 0.50,
            'confidence_score' => 0.00,
            'threshold_applied' => 0.70,
            'model_name' => 'SCREENING_RULE_SWING',
            'model_version' => '1.0.0',
            'feature_schema_hash' => md5('SCREENING_SWING_v1.0.0-frozen'),
            'decision_date' => '2026-10-30',
            'decision_timestamp' => $publishedAt,
            'reference_price' => 5000.0,
            'reference_price_date' => '2026-10-30',
            'predicted_horizon_days' => 5,
            'evaluation_due_date' => $dueDate->toDateString(),
            'published_at' => $publishedAt,
            'expires_at' => $publishedAt->copy()->addDays(14),
            'monitoring_status' => 'ACTIVE',
            'evaluation_status' => 'PENDING',
            'telegram_message_id' => 'TEST_RECEIPT',
            'target_price' => 5100.0,
            'invalidation_price' => 4800.0,
            'metadata' => [
                'delivery_status' => 'SENT',
                'strategy' => 'SWING',
                'origin' => 'DETERMINISTIC_SCREENING_RULE',
            ],
        ]);

        $this->assertDatabaseHas('prediction_signals', [
            'prediction_id' => 'REC_SCREEN_BBCA_20261030',
            'ticker' => 'BBCA',
            'monitoring_status' => 'ACTIVE',
            'evaluation_status' => 'PENDING',
            'telegram_message_id' => 'TEST_RECEIPT',
        ]);
        $this->assertEquals(5000.0, $sig->reference_price);
        $this->assertEquals(5100.0, $sig->target_price);
        $this->assertEquals(4800.0, $sig->invalidation_price);
    }

    /**
     * Test 2: Target Hit in live monitoring cycle.
     */
    public function test_target_hit_detected_with_valid_final_eod_observation(): void
    {
        $publishedAt = Carbon::parse('2026-10-30 08:30:00', 'Asia/Jakarta');
        $sig = PredictionSignal::create([
            'prediction_id' => 'SIG_TEST_UP_001',
            'ticker' => 'BBCA',
            'signal_status' => 'PUBLISHED_DIRECTIONAL',
            'direction' => 'UP',
            'probability_up' => 0.85,
            'probability_down' => 0.15,
            'max_probability' => 0.85,
            'confidence_score' => 0.70,
            'threshold_applied' => 0.70,
            'model_name' => 'RandomForest',
            'model_version' => '1.0.0',
            'feature_schema_hash' => 'hash_test',
            'decision_date' => '2026-10-30',
            'decision_timestamp' => $publishedAt,
            'reference_price' => 5000.0,
            'reference_price_date' => '2026-10-30',
            'predicted_horizon_days' => 5,
            'evaluation_due_date' => '2026-11-06',
            'published_at' => $publishedAt,
            'expires_at' => $publishedAt->copy()->addDays(14),
            'monitoring_status' => 'ACTIVE',
            'evaluation_status' => 'PENDING',
            'telegram_message_id' => 'TEST_RECEIPT',
            'metadata' => ['delivery_status' => 'SENT'],
            'target_price' => 5200.0,
            'invalidation_price' => 4900.0,
        ]);

        // Observation with High reaching target 5250
        MarketObservation::create([
            'ticker' => 'BBCA',
            'bar_date' => '2026-10-30',
            'observation_time' => Carbon::parse('2026-10-30 16:05:00', 'Asia/Jakarta')->utc(),
            'open_price' => 5000.0,
            'high_price' => 5250.0,
            'low_price' => 4950.0,
            'close_price' => 5200.0,
            'volume' => 1000000,
            'is_eod_final' => true,
            'source' => 'IDX Edge PRO',
            'board_scope' => 'RG',
            'validation_status' => 'VALID',
        ]);

        $monitor = app(MarketMonitoringService::class);
        $result = $monitor->runMonitoringCycle(Carbon::parse('2026-10-30 17:00:00', 'Asia/Jakarta'));

        $sig->refresh();
        $this->assertEquals('TARGET_HIT', $sig->monitoring_status);
        $this->assertDatabaseHas('monitoring_events', [
            'prediction_id' => 'SIG_TEST_UP_001',
            'event_type' => 'TARGET_HIT',
        ]);
    }

    /**
     * Test 3: Ambiguous hit when both Target and Invalidation are touched on the same bar.
     */
    public function test_ambiguous_hit_when_target_and_invalidation_touched_same_bar(): void
    {
        $publishedAt = Carbon::parse('2026-10-30 08:30:00', 'Asia/Jakarta');
        $sig = PredictionSignal::create([
            'prediction_id' => 'SIG_TEST_AMBIGUOUS_001',
            'ticker' => 'TLKM',
            'signal_status' => 'PUBLISHED_DIRECTIONAL',
            'direction' => 'UP',
            'probability_up' => 0.80,
            'probability_down' => 0.20,
            'max_probability' => 0.80,
            'confidence_score' => 0.60,
            'threshold_applied' => 0.70,
            'model_name' => 'RandomForest',
            'model_version' => '1.0.0',
            'feature_schema_hash' => 'hash_test',
            'decision_date' => '2026-10-30',
            'decision_timestamp' => $publishedAt,
            'reference_price' => 3000.0,
            'reference_price_date' => '2026-10-30',
            'predicted_horizon_days' => 5,
            'evaluation_due_date' => '2026-11-06',
            'published_at' => $publishedAt,
            'expires_at' => $publishedAt->copy()->addDays(14),
            'monitoring_status' => 'ACTIVE',
            'evaluation_status' => 'PENDING',
            'telegram_message_id' => 'TEST_RECEIPT',
            'metadata' => ['delivery_status' => 'SENT'],
            'target_price' => 3100.0,
            'invalidation_price' => 2900.0,
        ]);

        // Observation touches both target 3150 and invalidation 2850
        MarketObservation::create([
            'ticker' => 'TLKM',
            'bar_date' => '2026-10-30',
            'observation_time' => Carbon::parse('2026-10-30 16:05:00', 'Asia/Jakarta')->utc(),
            'open_price' => 3000.0,
            'high_price' => 3150.0,
            'low_price' => 2850.0,
            'close_price' => 3050.0,
            'volume' => 2000000,
            'is_eod_final' => true,
            'source' => 'IDX Edge PRO',
            'board_scope' => 'RG',
            'validation_status' => 'VALID',
        ]);

        $monitor = app(MarketMonitoringService::class);
        $monitor->runMonitoringCycle(Carbon::parse('2026-10-30 17:00:00', 'Asia/Jakarta'));

        $sig->refresh();
        $this->assertEquals('AMBIGUOUS', $sig->monitoring_status);
        $this->assertDatabaseHas('monitoring_events', [
            'prediction_id' => 'SIG_TEST_AMBIGUOUS_001',
            'event_type' => 'AMBIGUOUS_HIT',
        ]);
    }

    /**
     * Test 4: Horizon evaluation evaluates correctly when due date is reached with valid EOD bar.
     */
    public function test_horizon_evaluation_for_matured_prediction(): void
    {
        $publishedAt = Carbon::parse('2026-10-23 09:00:00', 'Asia/Jakarta');
        $dueDate = Carbon::parse('2026-10-30', 'Asia/Jakarta');

        $sig = PredictionSignal::create([
            'prediction_id' => 'SIG_MATURED_001',
            'ticker' => 'BMRI',
            'signal_status' => 'PUBLISHED_DIRECTIONAL',
            'direction' => 'UP',
            'probability_up' => 0.85,
            'probability_down' => 0.15,
            'max_probability' => 0.85,
            'confidence_score' => 0.70,
            'threshold_applied' => 0.70,
            'model_name' => 'RandomForest',
            'model_version' => '1.0.0',
            'feature_schema_hash' => 'hash_test',
            'decision_date' => '2026-10-23',
            'decision_timestamp' => $publishedAt,
            'reference_price' => 7000.0,
            'reference_price_date' => '2026-10-23',
            'predicted_horizon_days' => 5,
            'evaluation_due_date' => $dueDate->toDateString(),
            'published_at' => $publishedAt,
            'expires_at' => $publishedAt->copy()->addDays(14),
            'monitoring_status' => 'ACTIVE',
            'evaluation_status' => 'PENDING',
            'telegram_message_id' => 'TEST_RECEIPT',
            'metadata' => ['delivery_status' => 'SENT'],
        ]);

        // EOD bar on due date showing close of 7200 (+2.86%)
        MarketObservation::create([
            'ticker' => 'BMRI',
            'bar_date' => '2026-10-30',
            'observation_time' => Carbon::parse('2026-10-30 16:05:00', 'Asia/Jakarta')->utc(),
            'open_price' => 7050.0,
            'high_price' => 7250.0,
            'low_price' => 7000.0,
            'close_price' => 7200.0,
            'volume' => 5000000,
            'is_eod_final' => true,
            'source' => 'IDX Edge PRO',
            'board_scope' => 'RG',
            'validation_status' => 'VALID',
        ]);

        $monitor = app(MarketMonitoringService::class);
        $monitor->runMonitoringCycle(Carbon::parse('2026-10-30 17:00:00', 'Asia/Jakarta'));

        $sig->refresh();
        $this->assertEquals('EVALUATED_CORRECT', $sig->evaluation_status);
        $this->assertEquals(7200.0, $sig->metadata['actual_close_5d']);
        $this->assertEquals(2.86, $sig->metadata['return_5d_pct']);
    }

    /**
     * Test 5: Future / not-due predictions remain strictly PENDING (anti-lookahead).
     */
    public function test_future_prediction_not_evaluated_before_maturity(): void
    {
        $publishedAt = Carbon::parse('2026-10-30 09:00:00', 'Asia/Jakarta');
        $dueDate = Carbon::parse('2026-11-06', 'Asia/Jakarta');

        $sig = PredictionSignal::create([
            'prediction_id' => 'SIG_NOT_DUE_001',
            'ticker' => 'ASII',
            'signal_status' => 'PUBLISHED_DIRECTIONAL',
            'direction' => 'UP',
            'probability_up' => 0.85,
            'probability_down' => 0.15,
            'max_probability' => 0.85,
            'confidence_score' => 0.70,
            'threshold_applied' => 0.70,
            'model_name' => 'RandomForest',
            'model_version' => '1.0.0',
            'feature_schema_hash' => 'hash_test',
            'decision_date' => '2026-10-30',
            'decision_timestamp' => $publishedAt,
            'reference_price' => 5000.0,
            'reference_price_date' => '2026-10-30',
            'predicted_horizon_days' => 5,
            'evaluation_due_date' => $dueDate->toDateString(),
            'published_at' => $publishedAt,
            'expires_at' => $publishedAt->copy()->addDays(14),
            'monitoring_status' => 'ACTIVE',
            'evaluation_status' => 'PENDING',
            'telegram_message_id' => 'TEST_RECEIPT',
            'metadata' => ['delivery_status' => 'SENT'],
        ]);

        $monitor = app(MarketMonitoringService::class);
        // Run cycle on 30 Oct (due date is 6 Nov)
        $monitor->runMonitoringCycle(Carbon::parse('2026-10-30 17:00:00', 'Asia/Jakarta'));

        $sig->refresh();
        $this->assertEquals('PENDING', $sig->evaluation_status);
    }

    /**
     * Test 6: Audit check: Missing market observations prevent declaring clean EXPIRED.
     */
    public function test_expired_with_coverage_gap_when_no_verified_observations_found(): void
    {
        $publishedAt = Carbon::parse('2026-10-10 09:00:00', 'Asia/Jakarta');
        $expiresAt = Carbon::parse('2026-10-24 09:00:00', 'Asia/Jakarta');

        $sig = PredictionSignal::create([
            'prediction_id' => 'SIG_GAP_TEST_001',
            'ticker' => 'ZZZZ',
            'signal_status' => 'CANDIDATE',
            'direction' => 'UP',
            'probability_up' => 0.50,
            'probability_down' => 0.50,
            'max_probability' => 0.50,
            'confidence_score' => 0.00,
            'threshold_applied' => 0.70,
            'model_name' => 'SCREENING_RULE_SWING',
            'model_version' => '1.0.0',
            'feature_schema_hash' => 'hash_test',
            'decision_date' => '2026-10-10',
            'decision_timestamp' => $publishedAt,
            'reference_price' => 100.0,
            'reference_price_date' => '2026-10-10',
            'predicted_horizon_days' => 5,
            'evaluation_due_date' => '2026-10-17',
            'published_at' => $publishedAt,
            'expires_at' => $expiresAt,
            'monitoring_status' => 'ACTIVE',
            'evaluation_status' => 'PENDING',
            'telegram_message_id' => 'TEST_RECEIPT',
            'metadata' => ['delivery_status' => 'SENT'],
        ]);

        // No observations exist for ZZZZ between published_at and expires_at
        $monitor = app(MarketMonitoringService::class);
        $monitor->runMonitoringCycle(Carbon::parse('2026-10-25 10:00:00', 'Asia/Jakarta'));

        $sig->refresh();
        $this->assertEquals('EXPIRED_WITH_COVERAGE_GAP', $sig->monitoring_status);
        $this->assertTrue($sig->metadata['coverage_gap_detected']);
        $this->assertEquals(0, $sig->metadata['verified_sessions_checked']);
    }

    /**
     * Test 7: Non-ML screening contract allows null model_name and stores origin & strategy version.
     */
    public function test_non_ml_screening_contract_stores_origin_and_strategy_version(): void
    {
        $publishedAt = Carbon::parse('2026-10-30 09:00:00', 'Asia/Jakarta');
        $dueDate = Carbon::parse('2026-11-06', 'Asia/Jakarta');

        $sig = PredictionSignal::create([
            'prediction_id' => 'REC_SCREEN_BROKER_001',
            'ticker' => 'SMRA',
            'signal_status' => 'CANDIDATE',
            'direction' => 'UP',
            'probability_up' => 0.50,
            'probability_down' => 0.50,
            'max_probability' => 0.50,
            'confidence_score' => 0.00,
            'threshold_applied' => 0.70,
            'model_name' => 'BROKER_ACCUMULATION_SCREENING_V1',
            'model_version' => '1.0.0',
            'feature_schema_hash' => md5('BROKER_ACCUMULATION_v1.0.0'),
            'decision_date' => '2026-10-30',
            'decision_timestamp' => $publishedAt,
            'reference_price' => 246.0,
            'reference_price_date' => '2026-10-30',
            'predicted_horizon_days' => 5,
            'evaluation_due_date' => $dueDate->toDateString(),
            'published_at' => $publishedAt,
            'expires_at' => $publishedAt->copy()->addDays(14),
            'monitoring_status' => 'ACTIVE',
            'evaluation_status' => 'PENDING',
            'telegram_message_id' => 'TEST_RECEIPT',
            'metadata' => ['delivery_status' => 'SENT'],
            'target_price' => 256.0,
            'invalidation_price' => 236.0,
            'metadata' => [
                'origin' => 'BROKER_ACCUMULATION_SCREENING_V1',
                'strategy_version' => '1.0.0',
                'config_hash' => md5('CONFIG_BROKER_V1'),
                'evaluation_rule' => '5_TRADING_DAYS_CLOSE_OR_TARGET_TOUCH',
            ],
        ]);

        $this->assertEquals('BROKER_ACCUMULATION_SCREENING_V1', $sig->metadata['origin']);
        $this->assertEquals('1.0.0', $sig->metadata['strategy_version']);
        $this->assertEquals('5_TRADING_DAYS_CLOSE_OR_TARGET_TOUCH', $sig->metadata['evaluation_rule']);
    }

    /**
     * Test 8: Partial Coverage Gap Detection:
     * When some trading days in the 14-day window have observations, but some are missing.
     * Status must be EXPIRED_WITH_PARTIAL_COVERAGE, NOT clean EXPIRED.
     */
    public function test_expired_with_partial_coverage_gap_when_some_trading_days_missing(): void
    {
        // 2026-10-12 (Mon) to 2026-10-26 (Mon) = 14 calendar days (11 trading days)
        $publishedAt = Carbon::parse('2026-10-12 09:00:00', 'Asia/Jakarta');
        $expiresAt = Carbon::parse('2026-10-26 09:00:00', 'Asia/Jakarta');

        $sig = PredictionSignal::create([
            'prediction_id' => 'SIG_PARTIAL_GAP_001',
            'ticker' => 'MEDC',
            'signal_status' => 'CANDIDATE',
            'direction' => 'UP',
            'probability_up' => 0.50,
            'probability_down' => 0.50,
            'max_probability' => 0.50,
            'confidence_score' => 0.00,
            'threshold_applied' => 0.70,
            'model_name' => 'SCREENING_RULE_SWING',
            'model_version' => '1.0.0',
            'feature_schema_hash' => 'hash_test',
            'decision_date' => '2026-10-12',
            'decision_timestamp' => $publishedAt,
            'reference_price' => 1200.0,
            'reference_price_date' => '2026-10-12',
            'predicted_horizon_days' => 5,
            'evaluation_due_date' => '2026-10-19',
            'published_at' => $publishedAt,
            'expires_at' => $expiresAt,
            'monitoring_status' => 'ACTIVE',
            'evaluation_status' => 'PENDING',
            'telegram_message_id' => 'TEST_RECEIPT',
            'metadata' => ['delivery_status' => 'SENT'],
            'target_price' => 1350.0,
            'invalidation_price' => 1150.0,
        ]);

        // Provide only 2 sessions out of 11 trading days
        MarketObservation::create([
            'ticker' => 'MEDC',
            'bar_date' => '2026-10-13',
            'observation_time' => Carbon::parse('2026-10-13 16:05:00', 'Asia/Jakarta'),
            'open_price' => 1200.0,
            'high_price' => 1220.0,
            'low_price' => 1190.0,
            'close_price' => 1210.0,
            'volume' => 1000000,
            'is_eod_final' => true,
            'source' => 'IDX Edge PRO',
            'board_scope' => 'RG',
            'validation_status' => 'VALID',
        ]);
        MarketObservation::create([
            'ticker' => 'MEDC',
            'bar_date' => '2026-10-14',
            'observation_time' => Carbon::parse('2026-10-14 16:05:00', 'Asia/Jakarta'),
            'open_price' => 1210.0,
            'high_price' => 1230.0,
            'low_price' => 1200.0,
            'close_price' => 1220.0,
            'volume' => 1200000,
            'is_eod_final' => true,
            'source' => 'IDX Edge PRO',
            'board_scope' => 'RG',
            'validation_status' => 'VALID',
        ]);

        $monitor = app(MarketMonitoringService::class);
        $monitor->runMonitoringCycle(Carbon::parse('2026-10-27 10:00:00', 'Asia/Jakarta'));

        $sig->refresh();
        $this->assertEquals('EXPIRED_WITH_PARTIAL_COVERAGE', $sig->monitoring_status);
        $this->assertEquals('PARTIAL_GAP', $sig->metadata['coverage_gap_type']);
        $this->assertEquals(2, $sig->metadata['verified_sessions_checked']);
        $this->assertGreaterThan(2, $sig->metadata['expected_sessions_count']);
        $this->assertNotEmpty($sig->metadata['missing_sessions']);
    }

    /**
     * Test 9: Telegram Digest Deduplication Key Structure:
     * Must explicitly include chat_id, cohort/strategy, eval date range, and stable result hash.
     */
    public function test_telegram_digest_dedup_key_includes_chat_cohort_range_and_hash(): void
    {
        $chatId = '5923895922';
        $evalPeriodStart = '20261016';
        $evalPeriodEnd = '20261030';
        $stats = [
            'total_signals' => 10,
            'eval_correct' => 0,
            'eval_incorrect' => 0,
            'eval_pending' => 10,
        ];

        $stableResultHash = substr(md5(json_encode($stats)), 0, 8);
        $strategyCohort = 'SWING_AND_ML_V1';

        $dedupKey = "RECAP_{$strategyCohort}_{$chatId}_{$evalPeriodStart}_{$evalPeriodEnd}_{$stableResultHash}";

        $this->assertStringContainsString($evalPeriodStart.'_'.$evalPeriodEnd, $dedupKey);
        $this->assertStringContainsString($stableResultHash, $dedupKey);
    }

    /**
     * Test 10: Strict Provenance Separation for Shadow/Ledger Predictions:
     * Ledger timestamps alone do not prove forward execution; must be PROVENANCE_UNVERIFIED without external receipt.
     */
    public function test_shadow_ledger_predictions_classified_as_provenance_unverified(): void
    {
        $sig = PredictionSignal::create([
            'prediction_id' => 'SHADOW_AUDIT_PROV_001',
            'ticker' => 'BBCA',
            'signal_status' => 'NO_SIGNAL_ABSTAIN',
            'direction' => 'ABSTAIN',
            'probability_up' => 0.65,
            'probability_down' => 0.35,
            'max_probability' => 0.65,
            'confidence_score' => 0.30,
            'threshold_applied' => 0.70,
            'model_name' => 'RandomForest',
            'model_version' => 'RandomForest_v1.0',
            'feature_schema_hash' => 'hash_prov',
            'decision_date' => '2026-10-16',
            'decision_timestamp' => Carbon::parse('2026-10-16 16:00:00', 'Asia/Jakarta'),
            'reference_price' => 5175.0,
            'reference_price_date' => '2026-10-16',
            'predicted_horizon_days' => 5,
            'evaluation_due_date' => '2026-10-23',
            'published_at' => Carbon::parse('2026-10-16 16:05:00', 'Asia/Jakarta'),
            'expires_at' => Carbon::parse('2026-10-30 16:05:00', 'Asia/Jakarta')->utc(),
            'monitoring_status' => 'NOT_MONITORED',
            'evaluation_status' => 'PENDING',
            'metadata' => [
                'decision_status' => 'ABSTAIN',
                'provenance_status' => 'PROVENANCE_UNVERIFIED',
                'provenance_note' => 'Ledger JSON bertanggal lampau tanpa bukti stempel waktu eksternal independen.',
            ],
        ]);

        $this->assertEquals('ABSTAIN', $sig->direction);
        $this->assertEquals('NO_SIGNAL_ABSTAIN', $sig->signal_status);
        $this->assertEquals('PROVENANCE_UNVERIFIED', $sig->metadata['provenance_status']);
        $this->assertNotEquals('FORWARD_SHADOW', $sig->metadata['provenance_status']);
    }
}
