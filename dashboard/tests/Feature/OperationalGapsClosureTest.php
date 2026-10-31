<?php

namespace Tests\Feature;

use App\Models\BrokerSummary;
use App\Models\CompanyFundamental;
use App\Models\MarketObservation;
use App\Models\ScheduledTelegramJob;
use App\Models\TelegramOutbox;
use App\Services\DataSourceRegistryService;
use App\Services\IdxEdgeFundamentalIngestionService;
use App\Services\StockScreeningService;
use App\Services\TelegramFormattingService;
use App\Services\TelegramMarketAssistantService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OperationalGapsClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.telegram.chat_id', '5923895922');
        config()->set('services.telegram.allowed_user_ids', ['5923895922']);
        config()->set('services.idx_edge.enabled', true);
        config()->set('services.idx_edge.endpoints.fundamental', true);
        config()->set('services.idx_edge.endpoints.broker', true);
        app(DataSourceRegistryService::class)->syncRegistry();
    }

    public function test_morning_briefing_generates_grounded_report_and_enqueues_outbox(): void
    {
        // Seed valid EOD observation
        MarketObservation::create([
            'ticker' => 'BBCA',
            'bar_date' => '2026-10-29',
            'observation_time' => '2026-10-29 16:00:00',
            'open_price' => 4900,
            'high_price' => 5000,
            'low_price' => 4880,
            'close_price' => 4940,
            'volume' => 150000000,
            'source' => 'IDX Edge PRO',
            'validation_status' => 'VALID',
            'is_eod_final' => true,
        ]);

        $exitCode = Artisan::call('hermes:morning-briefing', ['--force' => true]);
        $this->assertSame(0, $exitCode);

        $outbox = TelegramOutbox::where('message_type', 'SYSTEM_ALERT')
            ->where('dedup_key', 'like', 'MORNING_BRIEFING_%')
            ->first();

        $this->assertNotNull($outbox);
        $this->assertStringContainsString('BRIEFING PAGI PASAR BEI', $outbox->payload_text);
        $this->assertStringContainsString('BBCA: Rp 4.940', $outbox->payload_text);
        $this->assertStringContainsString('Quote Intraday (09:15, 12:15, 15:15): DINONAKTIFKAN (QUARANTINED)', $outbox->payload_text);
    }

    public function test_evening_review_includes_broker_observed_snapshot_with_disclaimer(): void
    {
        MarketObservation::create([
            'ticker' => 'BBCA',
            'bar_date' => '2026-10-30',
            'observation_time' => '2026-10-30 16:00:00',
            'open_price' => 4900,
            'high_price' => 5025,
            'low_price' => 4880,
            'close_price' => 4980,
            'volume' => 286000000,
            'source' => 'IDX Edge PRO',
            'validation_status' => 'VALID',
            'is_eod_final' => true,
        ]);

        BrokerSummary::create([
            'ticker' => 'BBCA',
            'summary_date' => '2026-10-30',
            'broker_code' => 'BK',
            'broker_name' => 'JP MORGAN',
            'flow_type' => 'all',
            'board' => 'RG',
            'buy_volume' => 30000000,
            'buy_value' => 149000000000,
            'buy_avg_price' => 4966.67,
            'sell_volume' => 500000,
            'sell_value' => 2480000000,
            'sell_avg_price' => 4960.0,
            'net_volume' => 29500000,
            'net_value' => 146520000000,
            'unit' => 'SHARES',
            'currency' => 'IDR',
            'source' => 'IDX Edge PRO',
            'data_quality' => 'VERIFIED_ON_SAMPLE',
        ]);

        $exitCode = Artisan::call('hermes:evening-review', ['--force' => true]);
        $this->assertSame(0, $exitCode);

        $outbox = TelegramOutbox::where('message_type', 'SYSTEM_ALERT')
            ->where('dedup_key', 'like', 'EVENING_REVIEW_%')
            ->first();

        $this->assertNotNull($outbox);
        $this->assertStringContainsString('REKAP PASAR & AKTIVITAS BROKER PASAR REGULER', $outbox->payload_text);
        $this->assertStringContainsString('OBSERVED_SNAPSHOT (FINALITY_UNATTESTED_BY_PROVIDER)', $outbox->payload_text);
        $this->assertStringContainsString('BK (+Rp 146.52 M', $outbox->payload_text);
        $this->assertStringContainsString('buy_value / buy_volume', $outbox->payload_text);
        $this->assertStringContainsString('bukan bukti kepemilikan definitif entitas bandar tunggal', $outbox->payload_text);
    }

    public function test_async_consultation_lifecycle_marks_analysis_completed_until_sent(): void
    {
        $job = ScheduledTelegramJob::create([
            'chat_id' => '5923895922',
            'telegram_user_id' => '5923895922',
            'telegram_update_id' => '496963099',
            'inbound_message_id' => '999',
            'original_prompt' => 'Analisis komprehensif saham BBCA',
            'request_type' => 'ASYNC_CONSULTATION',
            'status' => 'PENDING',
            'idempotency_key' => 'IDEMP_'.uniqid(),
            'scheduled_at' => now()->subMinute(),
        ]);

        // Mock mode (simulates successful immediate delivery)
        $service = app(TelegramMarketAssistantService::class);
        $res = $service->executeScheduledJob($job, mockOnly: true);

        $this->assertSame('success', $res['status']);
        $job->refresh();

        // In mock mode with mockMsgId, status is COMPLETED and delivery is SENT
        $this->assertSame('COMPLETED', $job->status);
        $this->assertSame('SENT', $job->delivery_status);
        $this->assertNotNull($job->outbound_message_id);
    }

    public function test_outbox_queue_delivery_unknown_does_not_mark_job_completed(): void
    {
        $job = ScheduledTelegramJob::create([
            'chat_id' => '5923895922',
            'telegram_user_id' => '5923895922',
            'telegram_update_id' => '496963100',
            'inbound_message_id' => '1000',
            'original_prompt' => 'Analisis saham BBCA',
            'request_type' => 'ASYNC_CONSULTATION',
            'status' => 'ANALYSIS_COMPLETED',
            'delivery_status' => 'QUEUED',
            'idempotency_key' => 'IDEMP_'.uniqid(),
            'scheduled_at' => now()->subMinute(),
        ]);

        $outbox = TelegramOutbox::create([
            'dedup_key' => 'TEST_JOB_OUTBOX_'.$job->id,
            'chat_id' => '5923895922',
            'message_type' => 'SCHEDULED_MARKET_ANALYSIS',
            'payload_text' => 'Hasil analisis BBCA',
            'status' => 'DELIVERY_UNKNOWN',
            'metadata' => [
                'scheduled_job_id' => $job->id,
            ],
        ]);

        // Run outbox sync method directly to verify job status tracking
        $formattingService = app(TelegramFormattingService::class);
        $refMethod = new \ReflectionMethod($formattingService, 'syncJobDeliveryStatus');
        $refMethod->setAccessible(true);
        $refMethod->invoke($formattingService, $outbox, 'DELIVERY_UNKNOWN', 'DELIVERY_UNKNOWN', null, null, 'Timeout transport');

        $job->refresh();
        $this->assertSame('DELIVERY_UNKNOWN', $job->status);
        $this->assertSame('DELIVERY_UNKNOWN', $job->delivery_status);
        $this->assertNull($job->outbound_message_id);
    }

    public function test_usd_reporting_issuers_flag_unknown_conversion_method(): void
    {
        config()->set('services.idx_edge.api_key', 'test-key');

        Http::fake([
            'https://stock.arjum.com/api/financial-statements/ITMG?report_type=INCOME_STATEMENT*' => Http::response([
                'stock_code' => 'ITMG',
                'report_type' => 'INCOME_STATEMENT',
                'items' => [
                    [
                        'year' => '2025',
                        'quarter' => 'FY',
                        'data' => [
                            'penjualan_dan_pendapatan_usaha' => 38000000000000,
                            'laba_rugi' => 7000000000000,
                        ],
                    ],
                ],
            ], 200, ['X-Quota-Cost' => '1']),
            'https://stock.arjum.com/api/financial-statements/ITMG?report_type=BALANCE_SHEET*' => Http::response([
                'stock_code' => 'ITMG',
                'report_type' => 'BALANCE_SHEET',
                'items' => [],
            ], 200, ['X-Quota-Cost' => '1']),
        ]);

        $fundService = app(IdxEdgeFundamentalIngestionService::class);
        $result = $fundService->syncAnnualStatements('ITMG', 1);

        $this->assertTrue($result['success']);
        $fund = CompanyFundamental::where('ticker', 'ITMG')->firstOrFail();
        $this->assertSame('USD', $fund->raw_payload['conversion_metadata']['original_currency']);
        $this->assertSame('UNKNOWN_PROVIDER_CONVERSION', $fund->raw_payload['conversion_metadata']['conversion_method']);
        $this->assertNull($fund->raw_payload['conversion_metadata']['exchange_rate']);
    }

    public function test_screening_candidates_without_ml_signal_register_outcome_monitoring_contract(): void
    {
        $screening = app(StockScreeningService::class);
        $candidates = [
            [
                'ticker' => 'BBCA',
                'bar_date' => '2026-10-30',
                'close_price' => 4980,
                'target_price_tick' => 5050,
                'invalidation_price' => 4780,
                'ml_status' => 'MODEL_NOT_VALIDATED_FOR_INSTRUMENT',
                'reasons' => ['Volume breakout', 'Uptrend'],
            ],
        ];

        $saved = $screening->registerCandidatesAsPredictions($candidates, Carbon::parse('2026-10-30 16:00:00', 'Asia/Jakarta'));

        $this->assertCount(1, $saved);
        $sig = $saved[0];
        $this->assertSame('REC_SCREEN_BBCA_20261030', $sig->prediction_id);
        $this->assertSame('BBCA', $sig->ticker);
        $this->assertSame('SCREENING_RULE_SWING', $sig->model_name);
        $this->assertSame('ACTIVE', $sig->monitoring_status);
        $this->assertSame('PENDING', $sig->evaluation_status);
        $this->assertEquals(5050, $sig->target_price);
        $this->assertEquals(4780, $sig->invalidation_price);
        $this->assertSame('MODEL_NOT_VALIDATED_FOR_INSTRUMENT', $sig->metadata['ml_status']);
    }
}
