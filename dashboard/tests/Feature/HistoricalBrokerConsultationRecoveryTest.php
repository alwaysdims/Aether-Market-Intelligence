<?php

namespace Tests\Feature;

use App\Models\MarketObservation;
use App\Models\PredictionSignal;
use App\Models\ScheduledTelegramJob;
use App\Services\AiToolExecutorService;
use App\Services\IdxEdgeClient;
use App\Services\TelegramTwoWayService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HistoricalBrokerConsultationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.telegram.chat_id', '5923895922');
        config()->set('services.telegram.allowed_user_ids', ['5923895922']);
        config()->set('services.idx_edge.enabled', true);
        config()->set('services.idx_edge.api_key', 'test-key');
        config()->set('services.idx_edge.endpoints.broker', true);
        config()->set('services.idx_edge.endpoints.history', true);
    }

    public function test_broker_query_inbound_returns_fast_acknowledgment_and_enqueues_durable_job(): void
    {
        $service = app(TelegramTwoWayService::class);
        $t0 = microtime(true);

        $res = $service->handleInboundMessage([
            'update_id' => 'test_broker_ack_'.uniqid(),
            'message' => [
                'message_id' => '9981',
                'from' => ['id' => '5923895922'],
                'chat' => ['id' => '5923895922'],
                'text' => 'siapa saja yang sedang mengakumulasi dan distribusi saham SMRA selama 3 bulan terakhir',
            ],
        ]);

        $elapsedMs = (microtime(true) - $t0) * 1000;

        $this->assertSame('success', $res['status']);
        $this->assertSame('QUERY_BROKER_FLOW', $res['intent']);
        $this->assertLessThan(2000, $elapsedMs, 'Handler must finish quickly without waiting for retrieval or LLM.');
        $this->assertStringContainsString('Permintaan analisis broker SMRA diterima', $res['reply_text']);

        $job = ScheduledTelegramJob::where('original_prompt', 'like', '%SMRA%')
            ->where('request_type', 'ASYNC_CONSULTATION')
            ->latest('id')
            ->first();

        $this->assertNotNull($job);
        $this->assertSame('PENDING', $job->status);
    }

    public function test_broker_summary_client_uses_cache_and_avoids_duplicate_http_requests(): void
    {
        Cache::flush();

        Http::fake([
            'https://stock.arjum.com/api/broker-summary/SMRA*' => Http::response([
                'stock_code' => 'SMRA',
                'broker_start_date' => '2026-07-30',
                'broker_end_date' => '2026-10-30',
                'brokers' => [
                    [
                        'broker_code' => 'KI',
                        'broker_name' => 'CIPTADANA SEKURITAS',
                        'bval' => 100000000000,
                        'bvol' => 300000000,
                        'sval' => 2000000000,
                        'svol' => 5000000,
                        'nval' => 98000000000,
                        'nvol' => 295000000,
                    ],
                ],
                'broker_levels' => [
                    [
                        'buy' => [
                            'broker_code' => 'KI',
                            'broker_name' => 'CIPTADANA SEKURITAS',
                            'bval' => 98000000000,
                            'bvol' => 295000000,
                            'bavg' => 274.0,
                        ],
                        'sell' => [
                            'broker_code' => 'AK',
                            'broker_name' => 'UBS SEKURITAS',
                            'sval' => 56000000000,
                            'svol' => 200000000,
                            'savg' => 276.0,
                        ],
                    ],
                ],
            ], 200, ['X-Quota-Cost' => '1']),
        ]);

        $client = app(IdxEdgeClient::class);

        // First call triggers HTTP
        $res1 = $client->brokerSummary('SMRA', '2026-07-30', 'all', 20, '2026-10-30', true);
        $this->assertTrue($res1['success']);
        Http::assertSentCount(1);

        // Second identical call hits cache (0 additional HTTP calls)
        $res2 = $client->brokerSummary('SMRA', '2026-07-30', 'all', 20, '2026-10-30', true);
        $this->assertTrue($res2['success']);
        Http::assertSentCount(1);
    }

    public function test_ticker_status_does_not_leak_stale_ml_signals_into_new_bars(): void
    {
        $ticker = 'SMRA';
        $barDate = Carbon::parse('2026-10-30');

        MarketObservation::updateOrCreate(
            ['ticker' => $ticker, 'bar_date' => $barDate, 'interval' => '1d', 'data_classification' => 'PUBLIC_MARKET_EOD'],
            [
                'observation_time' => Carbon::parse('2026-10-30 15:42:14', 'UTC'),
                'open_price' => 244,
                'high_price' => 248,
                'low_price' => 242,
                'close_price' => 246,
                'volume' => 34000000,
                'source' => 'IDX Edge PRO',
                'is_eod_final' => true,
                'validation_status' => 'VALID',
            ]
        );

        // Stale prediction signal with evaluation_due_date = 2026-10-23
        PredictionSignal::updateOrCreate(
            ['prediction_id' => 'STALE_SIGNAL_SMRA_20261023'],
            [
                'ticker' => $ticker,
                'signal_status' => 'NO_SIGNAL_ABSTAIN',
                'direction' => 'ABSTAIN',
                'probability_up' => 0.50,
                'probability_down' => 0.50,
                'max_probability' => 0.50,
                'confidence_score' => 0.01,
                'threshold_applied' => 0.70,
                'model_name' => 'RandomForest',
                'model_version' => 'RandomForest_v1.0',
                'feature_schema_hash' => md5('test'),
                'decision_date' => '2026-10-18',
                'decision_timestamp' => now(),
                'reference_price' => 260,
                'reference_price_date' => '2026-10-18',
                'predicted_horizon_days' => 5,
                'evaluation_due_date' => '2026-10-23',
                'expires_at' => '2026-10-23 23:59:59',
                'monitoring_status' => 'NOT_MONITORED',
                'evaluation_status' => 'EVALUATED_NEUTRAL',
            ]
        );

        $service = app(TelegramTwoWayService::class);
        $res = $service->handleInboundMessage([
            '_sync' => true,
            'update_id' => 'test_stale_signal_'.uniqid(),
            'message' => [
                'message_id' => '9982',
                'from' => ['id' => '5923895922'],
                'chat' => ['id' => '5923895922'],
                'text' => 'cek SMRA',
            ],
        ]);

        $reply = $res['reply_text'];

        $this->assertStringContainsString('NO_ACTIVE_INFERENCE_FOR_BAR', $reply);
        $this->assertStringContainsString('telah jatuh tempo pada 23 Oct 2026 dan tidak berlaku sebagai prediksi aktif', $reply);
    }

    public function test_broker_summary_tool_separates_gross_averages_and_uses_dynamic_date(): void
    {
        Cache::flush();

        MarketObservation::updateOrCreate(
            ['ticker' => 'SMRA', 'bar_date' => '2026-10-30', 'interval' => '1d', 'data_classification' => 'PUBLIC_MARKET_EOD'],
            [
                'observation_time' => Carbon::parse('2026-10-30 15:42:14', 'UTC'),
                'open_price' => 244,
                'high_price' => 248,
                'low_price' => 242,
                'close_price' => 246,
                'volume' => 34000000,
                'source' => 'IDX Edge PRO',
                'is_eod_final' => true,
                'validation_status' => 'VALID',
            ]
        );

        Http::fake([
            'https://stock.arjum.com/api/broker-summary/SMRA*' => Http::response([
                'stock_code' => 'SMRA',
                'broker_start_date' => '2026-07-30',
                'broker_end_date' => '2026-10-30',
                'brokers' => [
                    [
                        'broker_code' => 'PD',
                        'broker_name' => 'INDO PREMIER SEKURITAS',
                        'bval' => 34280504200,
                        'bvol' => 122336400,
                        'sval' => 24115348200,
                        'svol' => 83884400,
                        'nval' => 10165156000,
                        'nvol' => 38452000,
                    ],
                    [
                        'broker_code' => 'AK',
                        'broker_name' => 'UBS SEKURITAS INDONESIA',
                        'bval' => 43790756800,
                        'bvol' => 162169800,
                        'sval' => 100532150600,
                        'svol' => 367201200,
                        'nval' => -56741393800,
                        'nvol' => -205031400,
                    ],
                ],
                'broker_levels' => [
                    [
                        'buy' => [
                            'broker_code' => 'PD',
                            'broker_name' => 'INDO PREMIER SEKURITAS',
                            'bval' => 10165156000,
                            'bvol' => 38452000,
                            'bavg' => 264.36,
                        ],
                        'sell' => [
                            'broker_code' => 'AK',
                            'broker_name' => 'UBS SEKURITAS INDONESIA',
                            'sval' => 56741393800,
                            'svol' => 205031400,
                            'savg' => 276.74,
                        ],
                    ],
                ],
            ], 200, ['X-Quota-Cost' => '1']),
        ]);

        $tool = app(AiToolExecutorService::class);
        $prov = [];
        $result = $tool->executeTool('get_broker_summary', ['ticker' => 'SMRA'], '5923895922', '5923895922', $prov);

        // Dynamic date assertion
        $this->assertStringContainsString('Periode Diminta', $result);
        $this->assertStringContainsString('Periode Penyedia : 2026-07-30 s/d 2026-10-30', $result);

        // Separation of Gross Buy Avg vs Gross Sell Avg
        $this->assertStringContainsString('Gross Buy Avg  : Rp 280,22', $result);
        $this->assertStringContainsString('Gross Sell Avg : Rp 287,48', $result);
        $this->assertStringContainsString('Rasio Net/Vol  : Rp 264,36 [Catatan: Bukan harga perolehan beli]', $result);

        // AK Distribution
        $this->assertStringContainsString('Gross Sell Avg : Rp 273,78', $result);
        $this->assertStringContainsString('Gross Buy Avg  : Rp 270,03', $result);
        $this->assertStringContainsString('Rasio Net/Vol  : Rp 276,74 [Catatan: Bukan harga pelepasan jual]', $result);
    }

    public function test_concurrent_requests_coalesce_with_atomic_lock(): void
    {
        Cache::flush();

        Http::fake([
            'https://stock.arjum.com/api/broker-summary/BMRI*' => Http::response([
                'stock_code' => 'BMRI',
                'broker_start_date' => '2026-07-30',
                'broker_end_date' => '2026-10-30',
                'brokers' => [],
                'broker_levels' => [],
            ], 200, ['X-Quota-Cost' => '1']),
        ]);

        $client = app(IdxEdgeClient::class);

        // Process 1 acquires lock and finishes
        $res1 = $client->brokerSummary('BMRI', '2026-07-30', 'all', 20, '2026-10-30', true);
        $this->assertTrue($res1['success']);
        $this->assertFalse($res1['cache_hit']);

        // Process 2 arrives with shared cache populated -> hits cache, 0 additional HTTP calls
        $res2 = $client->brokerSummary('BMRI', '2026-07-30', 'all', 20, '2026-10-30', true);
        $this->assertTrue($res2['success']);
        $this->assertTrue($res2['cache_hit']);

        Http::assertSentCount(1);
    }
}
