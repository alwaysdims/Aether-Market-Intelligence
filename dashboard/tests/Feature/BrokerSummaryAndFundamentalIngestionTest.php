<?php

namespace Tests\Feature;

use App\Models\BrokerSummary;
use App\Models\CompanyFundamental;
use App\Models\DataRevisionArchive;
use App\Models\DataSource;
use App\Services\AiToolExecutorService;
use App\Services\BrokerSummaryIngestionService;
use App\Services\DataSourceRegistryService;
use App\Services\IdxEdgeFundamentalIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrokerSummaryAndFundamentalIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.idx_edge', [
            'enabled' => true,
            'base_url' => 'https://stock.arjum.com',
            'api_key' => 'test-api-key',
            'timeout' => 10,
            'daily_budget' => 800,
            'reserve' => 200,
            'requests_per_minute' => 10,
            'cache_ttl_seconds' => 30,
            'endpoints' => [
                'history' => true,
                'price' => false,
                'broker' => true,
                'fundamental' => true,
            ],
        ]);

        app(DataSourceRegistryService::class)->syncRegistry();
    }

    public function test_broker_summary_ingestion_saves_records_and_calculates_averages(): void
    {
        Http::fake([
            'https://stock.arjum.com/api/broker-summary/BBCA*' => Http::response([
                'stock_code' => 'BBCA',
                'broker_start_date' => '2026-10-30',
                'broker_end_date' => '2026-10-30',
                'flow' => 'all',
                'brokers' => [
                    [
                        'broker_code' => 'BK',
                        'broker_name' => 'J.P. MORGAN',
                        'bvol' => 100000,
                        'bval' => 498000000,
                        'bfrq' => 50,
                        'svol' => 20000,
                        'sval' => 99600000,
                        'sfrq' => 10,
                        'nvol' => 80000,
                        'nval' => 398400000,
                    ],
                    [
                        'broker_code' => 'YP',
                        'broker_name' => 'MIRAET',
                        'bvol' => 10000,
                        'bval' => 49700000,
                        'bfrq' => 5,
                        'svol' => 50000,
                        'sval' => 249000000,
                        'sfrq' => 30,
                        'nvol' => -40000,
                        'nval' => -199300000,
                    ],
                ],
            ], 200, ['X-Quota-Cost' => '1']),
        ]);

        $service = app(BrokerSummaryIngestionService::class);
        $result = $service->syncDailySummary('BBCA', '2026-10-30', 'all');

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['saved_records']);
        $this->assertSame('SHARES', $result['volume_unit']);
        $this->assertSame('IDR', $result['currency']);

        $bk = BrokerSummary::where('ticker', 'BBCA')->where('broker_code', 'BK')->firstOrFail();
        $this->assertSame(100000, $bk->buy_volume);
        $this->assertSame(4980.0, (float) $bk->buy_avg_price);
        $this->assertSame(80000, $bk->net_volume);
        $this->assertSame(398400000.0, (float) $bk->net_value);
        $this->assertSame('RG', $bk->board);
        $this->assertSame('PROVIDER_SUPPLIED', $bk->data_quality);

        $yp = BrokerSummary::where('ticker', 'BBCA')->where('broker_code', 'YP')->firstOrFail();
        $this->assertSame(-40000, $yp->net_volume);
        $this->assertSame(-199300000.0, (float) $yp->net_value);

        // Verify DataSource timestamps
        $source = DataSource::where('name', 'IDX Edge Broker Summary')->firstOrFail();
        $this->assertNotNull($source->last_success_at);
        $this->assertSame('healthy', $source->health);
    }

    public function test_fundamental_ingestion_maps_bank_and_parent_attribution(): void
    {
        Http::fake([
            'https://stock.arjum.com/api/financial-statements/BBCA?report_type=INCOME_STATEMENT*' => Http::response([
                'stock_code' => 'BBCA',
                'report_type' => 'INCOME_STATEMENT',
                'items' => [
                    [
                        'year' => '2025',
                        'quarter' => 'FY',
                        'data' => [
                            'pendapatan_dan_beban_operasional' => [
                                'pendapatan_bunga' => 98912652000000,
                            ],
                            'laba_operasional' => 71260876000000,
                            'laba_rugi' => 57563093000000,
                            'laba_rugi_yang_dapat_diatribusikan' => [
                                'laba_rugi_yang_dapat_diatribusikan_ke_entitas_induk' => 57537287000000,
                                'laba_rugi_yang_dapat_diatribusikan_ke_kepentingan_non_pengendali' => 25806000000,
                            ],
                            'laba_rugi_per_saham' => [
                                'laba_per_saham_dasar_diatribusikan_kepada_pemilik_entitas_induk' => [
                                    'laba_rugi_per_saham_dasar_dari_operasi_yang_dilanjutkan' => 467,
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200, ['X-Quota-Cost' => '1']),
            'https://stock.arjum.com/api/financial-statements/BBCA?report_type=BALANCE_SHEET*' => Http::response([
                'stock_code' => 'BBCA',
                'report_type' => 'BALANCE_SHEET',
                'items' => [
                    [
                        'year' => '2025',
                        'quarter' => 'FY',
                        'data' => [
                            'aset' => [
                                'total' => 1660579336000000,
                            ],
                            'liabilitas_dana_syirkah_temporer_dan_ekuitas' => [
                                'ekuitas' => [
                                    'total' => 281687555000000,
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200, ['X-Quota-Cost' => '1']),
        ]);

        $service = app(IdxEdgeFundamentalIngestionService::class);
        $result = $service->syncAnnualStatements('BBCA', 1);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['saved_records']);
        $this->assertSame('PROVIDER_SUPPLIED', $result['data_quality']);

        $f = CompanyFundamental::where('ticker', 'BBCA')->where('fiscal_year', 2025)->firstOrFail();
        $this->assertSame(98912652000000.0, (float) $f->total_revenue);
        $this->assertSame(57563093000000.0, (float) $f->net_income);
        $this->assertSame(57537287000000.0, (float) $f->attribution_parent);
        $this->assertSame(467.0, (float) $f->eps_basic);
        $this->assertSame(1660579336000000.0, (float) $f->total_assets);
        $this->assertSame(281687555000000.0, (float) $f->total_equity);
        $this->assertSame('IDX Edge PRO', $f->source);
        // Test MEDC Foreign Currency handling
        Http::fake([
            'https://stock.arjum.com/api/financial-statements/MEDC?report_type=INCOME_STATEMENT*' => Http::response([
                'stock_code' => 'MEDC',
                'report_type' => 'INCOME_STATEMENT',
                'items' => [
                    [
                        'year' => '2025',
                        'quarter' => 'FY',
                        'data' => [
                            'penjualan_dan_pendapatan_usaha' => 35000000000000,
                            'laba_rugi' => 5000000000000,
                        ],
                    ],
                ],
            ], 200, ['X-Quota-Cost' => '1']),
            'https://stock.arjum.com/api/financial-statements/MEDC?report_type=BALANCE_SHEET*' => Http::response([
                'stock_code' => 'MEDC',
                'report_type' => 'BALANCE_SHEET',
                'items' => [],
            ], 200, ['X-Quota-Cost' => '1']),
        ]);

        $service->syncAnnualStatements('MEDC', 1);
        $medc = CompanyFundamental::where('ticker', 'MEDC')->firstOrFail();
        $this->assertSame('USD', $medc->raw_payload['conversion_metadata']['original_currency']);
        $this->assertSame('UNKNOWN_PROVIDER_CONVERSION', $medc->raw_payload['conversion_metadata']['conversion_method']);
        $this->assertNull($medc->raw_payload['conversion_metadata']['exchange_rate']);
    }

    public function test_gemini_tools_return_broker_and_fundamental_data_with_provenance(): void
    {
        // Seed dummy broker summary
        BrokerSummary::create([
            'ticker' => 'BBCA',
            'summary_date' => '2026-10-30',
            'broker_code' => 'BK',
            'broker_name' => 'J.P. MORGAN',
            'board' => 'RG',
            'buy_volume' => 100000,
            'buy_value' => 498000000,
            'buy_frequency' => 50,
            'buy_avg_price' => 4980,
            'sell_volume' => 0,
            'sell_value' => 0,
            'net_volume' => 100000,
            'net_value' => 498000000,
            'flow_type' => 'all',
            'unit' => 'SHARES',
            'currency' => 'IDR',
            'source' => 'IDX Edge PRO',
            'data_quality' => 'PROVIDER_SUPPLIED',
        ]);

        // Seed dummy fundamental
        CompanyFundamental::create([
            'ticker' => 'BBCA',
            'report_type' => 'ANNUAL',
            'fiscal_year' => 2025,
            'period_end_date' => '2025-12-31',
            'total_revenue' => 98912652000000,
            'net_income' => 57563093000000,
            'attribution_parent' => 57537287000000,
            'total_equity' => 281687555000000,
            'source' => 'IDX Edge PRO',
            'data_quality' => 'DOCUMENT_VERIFIED_ON_SAMPLE',
        ]);

        $uid = (string) config('services.telegram.chat_id', '123456');
        config()->set('services.telegram.chat_id', $uid);

        $executor = app(AiToolExecutorService::class);
        $prov = [];

        $bsOutput = $executor->executeTool('get_broker_summary', ['ticker' => 'BBCA'], $uid, $uid, $prov);
        $this->assertStringContainsString('AKTIVITAS BROKER PASAR REGULER', $bsOutput);
        $this->assertStringContainsString('IDX Edge PRO', $bsOutput);
        $this->assertStringContainsString('BK', $bsOutput);
        $this->assertStringContainsString('Top-N Broker teramati', $bsOutput);
        $this->assertStringContainsString('buy_value / buy_volume', $bsOutput);
        $this->assertStringContainsString('bukan bukti kepemilikan definitif bandar tunggal', $bsOutput);

        $fundOutput = $executor->executeTool('get_company_fundamentals', ['ticker' => 'BBCA'], $uid, $uid, $prov);
        $this->assertStringContainsString('LAPORAN KEUANGAN FUNDAMENTAL', $fundOutput);
        $this->assertStringContainsString('2025', $fundOutput);
        $this->assertStringContainsString('DOCUMENT_VERIFIED_ON_SAMPLE', $fundOutput);
    }

    public function test_revisions_are_archived_when_broker_data_changes(): void
    {
        config()->set('services.idx_edge.api_key', 'test-api-key');

        Http::fake([
            'https://stock.arjum.com/api/broker-summary/BBCA*' => Http::sequence()
                ->push([
                    'stock_code' => 'BBCA',
                    'date' => '2026-10-30',
                    'brokers' => [
                        [
                            'broker_code' => 'BK',
                            'broker_name' => 'JP MORGAN',
                            'bvol' => 100000,
                            'bval' => 498000000,
                            'bfrq' => 10,
                            'svol' => 20000,
                            'sval' => 99600000,
                            'sfrq' => 2,
                            'nvol' => 80000,
                            'nval' => 398400000,
                        ],
                    ],
                ], 200, ['X-Quota-Cost' => '1'])
                ->push([
                    'stock_code' => 'BBCA',
                    'date' => '2026-10-30',
                    'brokers' => [
                        [
                            'broker_code' => 'BK',
                            'broker_name' => 'JP MORGAN',
                            'bvol' => 120000, // Revised volume
                            'bval' => 597600000,
                            'bfrq' => 12,
                            'svol' => 20000,
                            'sval' => 99600000,
                            'sfrq' => 2,
                            'nvol' => 100000,
                            'nval' => 498000000,
                        ],
                    ],
                ], 200, ['X-Quota-Cost' => '1']),
        ]);

        $service = app(BrokerSummaryIngestionService::class);
        $service->syncDailySummary('BBCA', '2026-10-30', 'all');

        $this->assertSame(0, DataRevisionArchive::count());

        // Clear cache so sequence hits second response
        Cache::flush();

        // Second sync with revised data
        $service->syncDailySummary('BBCA', '2026-10-30', 'all');

        $this->assertSame(1, DataRevisionArchive::count());
        $rev = DataRevisionArchive::firstOrFail();
        $this->assertSame('broker_summary', $rev->record_type);
        $this->assertSame('BBCA', $rev->ticker);
        $this->assertSame('2026-10-30', $rev->period_or_date);
        $this->assertSame(100000, $rev->changes['old']['buy_volume']);
        $this->assertSame(120000, $rev->changes['new']['buy_volume']);
    }
}
