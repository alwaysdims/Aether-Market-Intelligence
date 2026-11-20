<?php

namespace Tests\Feature;

use App\Models\PredictionSignal;
use App\Services\TelegramTradingPlanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DryRunOperationalEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_dry_run_records_nothing(): void
    {
        $service = app(TelegramTradingPlanService::class);
        $before = [
            'signals' => PredictionSignal::count(),
            'evidence' => \DB::table('evidence_snapshots')->count(),
            'events' => \DB::table('monitoring_events')->count(),
            'outbox' => \DB::table('telegram_outbox')->count(),
        ];

        $result = $service->recordTradingPlanSignals([
            'candidates_scalping' => [['ticker' => 'TEST', 'type' => 'SCALPING']],
            'candidates_swing' => [],
            'candidates_bsjp' => [],
        ], Carbon::parse('2026-11-09 08:30:00', 'Asia/Jakarta'), '08:30', true);

        $this->assertSame([], $result);
        $this->assertSame($before, [
            'signals' => PredictionSignal::count(),
            'evidence' => \DB::table('evidence_snapshots')->count(),
            'events' => \DB::table('monitoring_events')->count(),
            'outbox' => \DB::table('telegram_outbox')->count(),
        ]);
    }

    public function test_archive_is_excluded_but_delivered_forward_signal_remains_eligible(): void
    {
        $common = [
            'ticker' => 'TEST',
            'signal_status' => 'PUBLISHED_CONDITIONAL',
            'direction' => 'UP',
            'probability_up' => 1,
            'probability_down' => 0,
            'max_probability' => 1,
            'confidence_score' => 1,
            'threshold_applied' => 0,
            'model_name' => 'TELEGRAM_SCALPING',
            'model_version' => 'test',
            'feature_schema_hash' => 'test',
            'decision_date' => '2026-11-09',
            'decision_timestamp' => '2026-11-09 08:30:00',
            'reference_price' => 100,
            'reference_price_date' => '2026-11-08',
            'predicted_horizon_days' => 1,
            'evaluation_due_date' => '2026-11-10',
            'published_at' => '2026-11-09 08:30:00',
            'expires_at' => '2026-11-09 15:50:00',
            'monitoring_status' => 'WAITING_FOR_TRIGGER',
            'evaluation_status' => 'PENDING',
            'target_price' => 110,
            'invalidation_price' => 95,
        ];

        PredictionSignal::create(array_merge($common, [
            'prediction_id' => 'PLAN_DRY_RUN_ARCHIVE',
            'metadata' => ['delivery_status' => 'DRY_RUN_ARCHIVE', 'operational_eligibility' => 'NON_OPERATIONAL_ARCHIVE'],
        ]));
        PredictionSignal::create(array_merge($common, [
            'prediction_id' => 'PLAN_FORWARD_SENT',
            'telegram_message_id' => 'real-message-id',
            'metadata' => ['delivery_status' => 'SENT', 'operational_eligibility' => 'OPERATIONAL'],
        ]));

        $this->assertSame(['PLAN_FORWARD_SENT'], PredictionSignal::operational()->pluck('prediction_id')->all());
        $this->assertSame(['PLAN_FORWARD_SENT'], PredictionSignal::deliveredToUser()->pluck('prediction_id')->all());
    }
}
