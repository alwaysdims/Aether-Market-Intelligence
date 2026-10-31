<?php

namespace Tests\Feature;

use App\Models\CompanyAnalysisMetric;
use App\Models\IdxCompanyRegistry;
use App\Models\MarketObservation;
use App\Models\ResearchRequest;
use App\Services\BeiUniverseRegistry;
use App\Services\CompanyFundamentalService;
use App\Services\IdxRegistryService;
use App\Services\OnDemandResearchService;
use App\Services\StockScreeningService;
use App\Services\TelegramTwoWayService;
use App\Services\TimestampAuditService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class IdxExpansionAndFundamentalAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected TelegramTwoWayService $twoWayService;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.chat_id' => '5923895922']);
        $this->twoWayService = app(TelegramTwoWayService::class);
    }

    protected function makeUpdate(string $text, string $updateId = 'upd_idx_1', string $messageId = '5001'): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $messageId,
                'from' => [
                    'id' => 5923895922,
                    'is_bot' => false,
                    'first_name' => 'Owner',
                    'username' => 'owner_bei',
                ],
                'chat' => [
                    'id' => 5923895922,
                    'type' => 'private',
                ],
                'date' => time(),
                'text' => $text,
            ],
            '_synthetic' => true,
        ];
    }

    public function test_registry_separates_equities_and_index_without_warrants(): void
    {
        IdxCompanyRegistry::create([
            'ticker' => 'BBCA',
            'company_name' => 'PT Bank Central Asia Tbk',
            'listing_board' => 'Utama',
            'sector' => 'Financials',
            'instrument_type' => 'EQUITY',
            'snapshot_date' => now()->toDateString(),
        ]);

        IdxCompanyRegistry::create([
            'ticker' => '^JKSE',
            'company_name' => 'Indeks Harga Saham Gabungan (IHSG)',
            'listing_board' => 'Benchmark Index',
            'sector' => 'Composite Index',
            'instrument_type' => 'INDEX',
            'snapshot_date' => now()->toDateString(),
        ]);

        $summary = app(IdxRegistryService::class)->getRegistrySummary();
        $this->assertEquals(1, $summary['total_equities']);
        $this->assertEquals(1, $summary['total_indices']);

        $equities = BeiUniverseRegistry::getEquityTickers();
        $indices = BeiUniverseRegistry::getIndexTickers();
        $this->assertNotContains('^JKSE', $equities);
        $this->assertContains('^JKSE', $indices);
    }

    public function test_timestamp_utc_persistence_and_future_anomaly_detection(): void
    {
        $pastUtc = Carbon::now('UTC')->subHours(2);
        $obs = MarketObservation::create([
            'ticker' => 'BBCA',
            'bar_date' => Carbon::parse('2026-10-23'),
            'open_price' => 5000.0,
            'high_price' => 5100.0,
            'low_price' => 4950.0,
            'close_price' => 5050.0,
            'volume' => 10000000,
            'fetched_at' => $pastUtc,
            'observation_time' => $pastUtc,
            'is_eod_final' => true,
        ]);

        $update = $this->makeUpdate('cek saham BBCA', 'upd_ts_1', '5002');
        $res = $this->twoWayService->handleInboundMessage($update);

        $this->assertStringNotContainsString('AUDIT_ANOMALY', $res['reply_text']);
        $this->assertStringContainsString('WIB', $res['reply_text']);

        // Now test future timestamp triggers audit anomaly flag
        $futureUtc = Carbon::now('UTC')->addHours(3);
        $obs->update(['fetched_at' => $futureUtc]);

        $resFuture = $this->twoWayService->handleInboundMessage($this->makeUpdate('cek saham BBCA', 'upd_ts_2', '5003'));
        $this->assertStringContainsString('[AUDIT_ANOMALY: FUTURE_TIMESTAMP]', $resFuture['reply_text']);
    }

    public function test_model_not_validated_for_instrument_displays_na_confidence(): void
    {
        MarketObservation::create([
            'ticker' => 'KIJA',
            'bar_date' => Carbon::parse('2026-10-23'),
            'observation_time' => now(),
            'open_price' => 130.0,
            'high_price' => 135.0,
            'low_price' => 128.0,
            'close_price' => 130.0,
            'volume' => 25000000,
            'is_eod_final' => true,
        ]);

        $update = $this->makeUpdate('analisis emiten KIJA', 'upd_kija', '5004');
        $res = $this->twoWayService->handleInboundMessage($update);

        $this->assertStringContainsString('MODEL_NOT_VALIDATED_FOR_INSTRUMENT', $res['reply_text']);
        $this->assertStringContainsString('Estimasi Prob    : N/A', $res['reply_text']);
        $this->assertStringContainsString('Confidence Score : N/A', $res['reply_text']);
        $this->assertStringNotContainsString('Ambang batas minimal: >= 40,00%', $res['reply_text']);
    }

    public function test_fundamental_request_not_marked_completed_when_only_ohlc_available(): void
    {
        $req = ResearchRequest::create([
            'request_id' => 'REQ_FUND_TEST',
            'chat_id' => '5923895922',
            'user_id' => '5923895922',
            'inbound_message_id' => '5005',
            'ticker' => 'MEDC',
            'company_name' => 'PT Medco Energi Internasional Tbk',
            'exchange' => 'IDX',
            'analysis_type' => 'fundamental',
            'data_type_requested' => 'eod',
            'horizon' => 'multi_day',
            'as_of_time' => now(),
            'status' => 'pending',
        ]);

        // Seed observation only (no company_fundamentals row)
        MarketObservation::create([
            'ticker' => 'MEDC',
            'bar_date' => Carbon::parse('2026-10-23'),
            'observation_time' => now(),
            'open_price' => 1200.0,
            'high_price' => 1250.0,
            'low_price' => 1180.0,
            'close_price' => 1220.0,
            'volume' => 15000000,
            'is_eod_final' => true,
            'research_request_id' => $req->request_id,
        ]);

        $researchService = app(OnDemandResearchService::class);
        $result = $researchService->executeResearch($req);

        $req->refresh();
        $this->assertEquals('partial', $req->status);
        $this->assertEquals('FUNDAMENTAL_DATA_UNAVAILABLE', $req->error_reason);
        $this->assertStringContainsString('PARTIAL', $result['reply_text']);
    }

    public function test_deterministic_technical_and_fundamental_report_kija(): void
    {
        IdxCompanyRegistry::create([
            'ticker' => 'KIJA',
            'company_name' => 'PT Kawasan Industri Jababeka Tbk',
            'listing_board' => 'Utama',
            'sector' => 'Properties & Real Estate',
            'instrument_type' => 'EQUITY',
            'snapshot_date' => now()->toDateString(),
        ]);

        MarketObservation::create([
            'ticker' => 'KIJA',
            'bar_date' => Carbon::parse('2026-10-23'),
            'observation_time' => now(),
            'open_price' => 130.0,
            'high_price' => 135.0,
            'low_price' => 128.0,
            'close_price' => 130.0,
            'volume' => 25000000,
            'is_eod_final' => true,
        ]);

        CompanyAnalysisMetric::create([
            'ticker' => 'KIJA',
            'as_of_date' => Carbon::now('Asia/Jakarta')->toDateString(),
            'close_price' => 130.0,
            'sma_20' => 143.75,
            'sma_50' => 170.86,
            'rsi_14' => 29.73,
            'macd' => -12.31,
            'macd_signal' => -11.33,
            'macd_hist' => -0.98,
            'atr_14' => 9.71,
            'atr_14_pct' => 7.47,
            'rvol_20' => 1.25,
            'support_price' => 109.0,
            'resistance_price' => 171.0,
            'technical_trend' => 'STRONG_BEARISH',
            'technical_status' => 'CALCULATED',
            'pe_ratio' => 5.2,
            'pbv_ratio' => 0.45,
            'roe_pct' => 8.5,
            'roa_pct' => 3.2,
            'der_ratio' => 0.74,
            'net_profit_margin_pct' => 8.22,
            'dividend_yield_pct' => 2.5,
            'eps' => 25.0,
            'bvps' => 288.0,
            'sector_template' => 'GENERAL',
            'fundamental_status' => 'CALCULATED',
        ]);

        $svc = app(CompanyFundamentalService::class);
        $report = $svc->formatFundamentalReport('KIJA');

        $this->assertStringContainsString('ANALISIS FUNDAMENTAL & TEKNIKAL — KIJA', $report);
        $this->assertStringContainsString('Kawasan Industri Jababeka', $report);
        $this->assertStringContainsString('Status Papan: Papan Utama', $report);
        $this->assertStringContainsString('Tingkat Harga & Fraksi: Rp 130 (Fraksi Tick: Rp 1', $report);
        $this->assertStringContainsString('STRONG_BEARISH', $report);
        $this->assertStringContainsString('RSI 14 (Wilder) : 29.73 (Oversold)', $report);
        $this->assertStringContainsString('5.2x', $report);
        $this->assertStringContainsString('0.74x', $report);
    }

    public function test_telegram_routes_coverage_and_completeness_queries(): void
    {
        IdxCompanyRegistry::create([
            'ticker' => 'BBCA',
            'company_name' => 'PT Bank Central Asia Tbk',
            'listing_board' => 'Utama',
            'sector' => 'Financials',
            'instrument_type' => 'EQUITY',
            'snapshot_date' => now()->toDateString(),
        ]);

        $updateCov = $this->makeUpdate('berapa emiten yang sudah lengkap?', 'upd_cov', '5006');
        $resCov = $this->twoWayService->handleInboundMessage($updateCov);
        $this->assertStringContainsString('CAKUPAN BASIS DATA SELURUH SAHAM BEI', $resCov['reply_text']);

        $updateWhy = $this->makeUpdate('kenapa data emiten ini belum lengkap?', 'upd_why', '5007');
        $resWhy = $this->twoWayService->handleInboundMessage($updateWhy);
        $this->assertStringContainsString('STATUS KELENGKAPAN DATA', $resWhy['reply_text']);
        $this->assertStringContainsString('rate limiting', $resWhy['reply_text']);
    }

    public function test_screening_excludes_index_and_reports_registered_equity_count(): void
    {
        IdxCompanyRegistry::create([
            'ticker' => 'SMRA',
            'company_name' => 'PT Summarecon Agung Tbk',
            'listing_board' => 'Utama',
            'sector' => 'Properties',
            'instrument_type' => 'EQUITY',
            'snapshot_date' => now()->toDateString(),
        ]);

        IdxCompanyRegistry::create([
            'ticker' => '^JKSE',
            'company_name' => 'Indeks Harga Saham Gabungan (IHSG)',
            'listing_board' => 'Benchmark Index',
            'sector' => 'Composite Index',
            'instrument_type' => 'INDEX',
            'snapshot_date' => now()->toDateString(),
        ]);

        MarketObservation::create([
            'ticker' => 'SMRA',
            'bar_date' => Carbon::parse('2026-10-23'),
            'observation_time' => now(),
            'open_price' => 256.0,
            'high_price' => 266.0,
            'low_price' => 254.0,
            'close_price' => 260.0,
            'volume' => 32107600,
            'is_eod_final' => true,
        ]);

        $screeningService = app(StockScreeningService::class);
        $res = $screeningService->formatScreeningResponse('SWING');

        $this->assertStringContainsString('Indeks ^JKSE tidak dihitung', $res);
        $this->assertStringContainsString('SMRA', $res);
        $this->assertStringNotContainsString('^JKSE —', $res);
    }

    public function test_timestamp_audit_service_handles_offsets_and_flags_unknown_zones(): void
    {
        $auditService = app(TimestampAuditService::class);

        $pastUtc = Carbon::now('UTC')->subHours(2);
        $pastUtcStr = $pastUtc->format('Y-m-d\TH:i:s\Z');
        $pastWibStr = $pastUtc->copy()->setTimezone('Asia/Jakarta')->toIso8601String();
        $pastNaiveWib = $pastUtc->copy()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s');

        // 1. Explicit UTC offset
        $utcRes = $auditService->parseTimestamp($pastUtcStr);
        $this->assertEquals('VALID_OFFSET', $utcRes['status']);
        $this->assertEquals($pastUtc->format('Y-m-d H:i:s'), $utcRes['carbon']->format('Y-m-d H:i:s'));
        $this->assertFalse($utcRes['is_anomaly']);

        // 2. Explicit WIB offset (+07:00)
        $wibRes = $auditService->parseTimestamp($pastWibStr);
        $this->assertEquals('VALID_OFFSET', $wibRes['status']);
        $this->assertEquals($pastUtc->format('Y-m-d H:i:s'), $wibRes['carbon']->format('Y-m-d H:i:s'));
        $this->assertFalse($wibRes['is_anomaly']);

        // 3. Naive with known source zone
        $knownRes = $auditService->parseTimestamp($pastNaiveWib, 'Asia/Jakarta');
        $this->assertEquals('VALID_KNOWN_ZONE', $knownRes['status']);
        $this->assertEquals($pastUtc->format('Y-m-d H:i:s'), $knownRes['carbon']->format('Y-m-d H:i:s'));
        $this->assertFalse($knownRes['is_anomaly']);

        // 4. Naive with UNKNOWN zone (prohibited to guess)
        $unknownRes = $auditService->parseTimestamp($pastNaiveWib, null);
        $this->assertEquals('ZONE_UNKNOWN', $unknownRes['status']);
        $this->assertNull($unknownRes['carbon']);
        $this->assertTrue($unknownRes['is_anomaly']);

        // 5. Future timestamp anomaly
        $futureStr = Carbon::now('UTC')->addHours(2)->toIso8601String();
        $futureRes = $auditService->parseTimestamp($futureStr);
        $this->assertTrue($futureRes['is_anomaly']);
        $this->assertStringContainsString('FUTURE_TIMESTAMP', $futureRes['anomaly_reason']);
    }

    public function test_official_registry_import_command_parses_user_file(): void
    {
        $tempJson = tempnam(sys_get_temp_dir(), 'idx_official_').'.json';
        file_put_contents($tempJson, json_encode([
            [
                'Code' => 'TLKM',
                'Name' => 'PT Telkom Indonesia (Persero) Tbk',
                'ListingDate' => '1995-11-14',
                'Board' => 'Utama',
                'Sector' => 'Telecommunication',
                'Shares' => 99062216600,
            ],
        ]));

        $exitCode = Artisan::call('idx:import-official-registry', ['file' => $tempJson]);
        $this->assertEquals(0, $exitCode);

        $tlkm = IdxCompanyRegistry::where('ticker', 'TLKM')->first();
        $this->assertNotNull($tlkm);
        $this->assertEquals('PT Telkom Indonesia (Persero) Tbk', $tlkm->company_name);
        $this->assertStringContainsString('OFFICIAL_BEI_EXPORT_USER_PROVIDED', $tlkm->source);

        @unlink($tempJson);
    }
}
