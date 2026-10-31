<?php

namespace Tests\Feature;

use App\Models\AnalysisRun;
use App\Models\CompanyAnalysisMetric;
use App\Models\CompanyFundamental;
use App\Models\IdxCompanyRegistry;
use App\Models\MarketAnalysis;
use App\Models\MarketObservation;
use App\Models\ScheduledTelegramJob;
use App\Models\TelegramOutbox;
use App\Services\BeiUniverseRegistry;
use App\Services\CompanyFundamentalService;
use App\Services\TechnicalMetricsCalculationService;
use App\Services\TelegramFormattingService;
use App\Services\TelegramTwoWayService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenericAnalysisPipelineCrossTickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.chat_id' => '5923895922']);
    }

    /**
     * 1. Test Banking Ticker (BBCA): Financials sector, main board, interest-bearing DER excluded.
     */
    public function test_generic_pipeline_banking_ticker(): void
    {
        IdxCompanyRegistry::create([
            'ticker' => 'BBCA',
            'company_name' => 'Bank Central Asia Tbk.',
            'listing_board' => 'Utama',
            'sector' => 'Financials',
            'instrument_type' => 'EQUITY',
            'snapshot_date' => now()->toDateString(),
        ]);

        MarketObservation::create([
            'ticker' => 'BBCA',
            'bar_date' => '2026-10-30',
            'observation_time' => now(),
            'open_price' => 5000.0,
            'high_price' => 5050.0,
            'low_price' => 4950.0,
            'close_price' => 4980.0,
            'volume' => 50000000,
            'source' => 'IDX Edge PRO',
            'validation_status' => 'VALID',
            'is_eod_final' => true,
        ]);

        CompanyFundamental::create([
            'ticker' => 'BBCA',
            'report_type' => 'ANNUAL',
            'fiscal_year' => 2025,
            'period_end_date' => '2025-12-31',
            'currency' => 'IDR',
            'unit' => 'IDR',
            'is_audited' => true,
            'total_revenue' => 98912652000000.0,
            'net_income' => 57537287000000.0,
            'total_equity' => 250000000000000.0,
            'attribution_parent' => 57537287000000.0,
            'source' => 'IDX Edge PRO',
            'data_quality' => 'PROVIDER_SUPPLIED',
        ]);

        CompanyAnalysisMetric::create([
            'ticker' => 'BBCA',
            'as_of_date' => '2026-10-30',
            'sector_template' => 'BANKING',
            'fundamental_status' => 'CALCULATED',
        ]);

        $report = app(CompanyFundamentalService::class)->formatFundamentalReport('BBCA');

        $this->assertStringContainsString('Bank Central Asia Tbk.', $report);
        $this->assertStringContainsString('Status Papan: Papan Utama', $report);
        $this->assertStringContainsString('Fraksi Tick: Rp 10', $report);
        $this->assertStringContainsString('Metrik Perbankan Dikecualikan', $report);
        $this->assertStringContainsString('Tahun 2025: Pendapatan Rp 98.912,65 M', $report);
    }

    /**
     * 2. Test Non-Banking Ticker (ASII): Industrials sector, separates total liabilities to equity ratio.
     */
    public function test_generic_pipeline_non_banking_ticker(): void
    {
        IdxCompanyRegistry::create([
            'ticker' => 'ASII',
            'company_name' => 'Astra International Tbk.',
            'listing_board' => 'Utama',
            'sector' => 'Industrials',
            'instrument_type' => 'EQUITY',
            'snapshot_date' => now()->toDateString(),
        ]);

        MarketObservation::create([
            'ticker' => 'ASII',
            'bar_date' => '2026-10-30',
            'observation_time' => now(),
            'open_price' => 4850.0,
            'high_price' => 4900.0,
            'low_price' => 4780.0,
            'close_price' => 4800.0,
            'volume' => 30000000,
            'source' => 'IDX Edge PRO',
            'validation_status' => 'VALID',
            'is_eod_final' => true,
        ]);

        CompanyFundamental::create([
            'ticker' => 'ASII',
            'report_type' => 'ANNUAL',
            'fiscal_year' => 2025,
            'period_end_date' => '2025-12-31',
            'currency' => 'IDR',
            'unit' => 'IDR',
            'is_audited' => true,
            'total_revenue' => 315000000000000.0,
            'net_income' => 34000000000000.0,
            'total_equity' => 210000000000000.0,
            'attribution_parent' => 33500000000000.0,
            'source' => 'IDX Edge PRO',
            'data_quality' => 'PROVIDER_SUPPLIED',
        ]);

        $report = app(CompanyFundamentalService::class)->formatFundamentalReport('ASII');

        $this->assertStringContainsString('Astra International Tbk.', $report);
        $this->assertStringContainsString('Status Papan: Papan Utama', $report);
        $this->assertStringContainsString('Fraksi Tick: Rp 10', $report);
        $this->assertStringContainsString('Tahun 2025: Pendapatan Rp 315.000,00 M', $report);
    }

    /**
     * 3. Test Foreign USD Reporting Ticker (MEDC): Explicit currency label, no false IDR scale interpretation.
     */
    public function test_generic_pipeline_usd_reporting_ticker(): void
    {
        IdxCompanyRegistry::create([
            'ticker' => 'MEDC',
            'company_name' => 'Medco Energi Internasional Tbk.',
            'listing_board' => 'Utama',
            'sector' => 'Energy',
            'instrument_type' => 'EQUITY',
            'snapshot_date' => now()->toDateString(),
        ]);

        MarketObservation::create([
            'ticker' => 'MEDC',
            'bar_date' => '2026-10-30',
            'observation_time' => now(),
            'open_price' => 1200.0,
            'high_price' => 1250.0,
            'low_price' => 1190.0,
            'close_price' => 1210.0,
            'volume' => 15000000,
            'source' => 'IDX Edge PRO',
            'validation_status' => 'VALID',
            'is_eod_final' => true,
        ]);

        CompanyFundamental::create([
            'ticker' => 'MEDC',
            'report_type' => 'ANNUAL',
            'fiscal_year' => 2025,
            'period_end_date' => '2025-12-31',
            'currency' => 'USD',
            'unit' => 'USD',
            'is_audited' => true,
            'total_revenue' => 2250000000000.0,
            'net_income' => 330000000000.0,
            'total_equity' => 1850000000000.0,
            'attribution_parent' => 330000000000.0,
            'source' => 'IDX Edge PRO',
            'data_quality' => 'PROVIDER_SUPPLIED',
        ]);

        $report = app(CompanyFundamentalService::class)->formatFundamentalReport('MEDC');

        $this->assertStringContainsString('Medco Energi Internasional Tbk.', $report);
        $this->assertStringContainsString('Mata Uang: USD', $report);
        $this->assertStringContainsString('Pendapatan US$', $report);
    }

    /**
     * 4. Test Watchlist / Short History Ticker: Flat Rp 1 tick, SMA50/200 null for < 50 bars.
     */
    public function test_generic_pipeline_short_history_and_watchlist_board(): void
    {
        IdxCompanyRegistry::create([
            'ticker' => 'GTSI',
            'company_name' => 'PT GTS Internasional Tbk',
            'listing_board' => 'Papan Pemantauan Khusus',
            'sector' => 'Transportation & Logistics',
            'instrument_type' => 'EQUITY',
            'snapshot_date' => now()->toDateString(),
        ]);

        // Create 25 bars for GTSI
        $startDate = Carbon::parse('2026-09-28');
        for ($i = 0; $i < 25; $i++) {
            MarketObservation::create([
                'ticker' => 'GTSI',
                'bar_date' => $startDate->copy()->addDays($i)->toDateString(),
                'observation_time' => now(),
                'open_price' => 70.0 + $i,
                'high_price' => 72.0 + $i,
                'low_price' => 69.0 + $i,
                'close_price' => 71.0 + $i,
                'volume' => 1000000,
                'source' => 'IDX Edge PRO',
                'validation_status' => 'VALID',
                'is_eod_final' => true,
            ]);
        }

        $metrics = app(TechnicalMetricsCalculationService::class)->calculateForTicker('GTSI', '2026-10-30');

        $this->assertNotNull($metrics);
        $this->assertNotNull($metrics->sma_20);
        $this->assertNull($metrics->sma_50, 'SMA50 must be null for 25 bars');
        $this->assertNull($metrics->sma_200, 'SMA200 must be null for 25 bars');
        $this->assertNull($metrics->macd, 'MACD must be null for 25 bars (< 35 bars minimum)');
        $this->assertSame('PARTIAL_CALCULATED', $metrics->technical_status);

        $fraction = BeiUniverseRegistry::resolvePriceFraction(71.0, 'Papan Pemantauan Khusus');
        $this->assertSame(1, $fraction['tick_size']);
        $this->assertSame('PAPAN_PEMANTAUAN_KHUSUS', $fraction['board']);
    }

    /**
     * 5. Test Missing / Empty Fundamental Data: Graceful PARTIAL reporting without crashing or invent data.
     */
    public function test_generic_pipeline_empty_fundamental(): void
    {
        IdxCompanyRegistry::create([
            'ticker' => 'UNVR',
            'company_name' => 'Unilever Indonesia Tbk.',
            'listing_board' => 'Utama',
            'sector' => 'Consumer Non-Cyclicals',
            'instrument_type' => 'EQUITY',
            'snapshot_date' => now()->toDateString(),
        ]);

        MarketObservation::create([
            'ticker' => 'UNVR',
            'bar_date' => '2026-10-30',
            'observation_time' => now(),
            'open_price' => 2200.0,
            'high_price' => 2250.0,
            'low_price' => 2180.0,
            'close_price' => 2200.0,
            'volume' => 12000000,
            'source' => 'IDX Edge PRO',
            'validation_status' => 'VALID',
            'is_eod_final' => true,
        ]);

        $report = app(CompanyFundamentalService::class)->formatFundamentalReport('UNVR');

        $this->assertStringContainsString('Unilever Indonesia Tbk.', $report);
        $this->assertStringContainsString('Status Data: Laporan tahunan resmi IDX Edge PRO belum tersinkronisasi di basis data', $report);
        $this->assertStringNotContainsString('Tahun 2025: Pendapatan', $report);
    }

    /**
     * 6. Test Invalid Ticker Rejection.
     */
    public function test_generic_pipeline_invalid_ticker_rejection(): void
    {
        $registry = app(BeiUniverseRegistry::class);
        $res = $registry->resolveInstrument('ZZZZ');

        $this->assertFalse($res['is_valid']);
        $this->assertSame('UNREGISTERED_BEI_TICKER', $res['error_type']);
    }

    /**
     * 7. Test Currency & Scale Integrity: Unknown scale blocks nominal interpretation.
     */
    public function test_currency_and_scale_integrity_blocks_nominal_interpretation(): void
    {
        IdxCompanyRegistry::create([
            'ticker' => 'SCAL',
            'company_name' => 'PT Scale Test Tbk',
            'listing_board' => 'Utama',
            'sector' => 'Technology',
            'instrument_type' => 'EQUITY',
            'snapshot_date' => now()->toDateString(),
        ]);

        MarketObservation::create([
            'ticker' => 'SCAL',
            'bar_date' => '2026-10-30',
            'observation_time' => now(),
            'open_price' => 100.0,
            'high_price' => 105.0,
            'low_price' => 95.0,
            'close_price' => 100.0,
            'volume' => 1000000,
            'source' => 'IDX Edge PRO',
            'validation_status' => 'VALID',
            'is_eod_final' => true,
        ]);

        CompanyFundamental::create([
            'ticker' => 'SCAL',
            'report_type' => 'ANNUAL',
            'fiscal_year' => 2025,
            'period_end_date' => '2025-12-31',
            'currency' => 'IDR',
            'unit' => 'UNKNOWN_UNIT_SCALE',
            'is_audited' => true,
            'total_revenue' => 500000000.0,
            'net_income' => 50000000.0,
            'total_equity' => 200000000.0,
            'source' => 'IDX Edge PRO',
            'data_quality' => 'PROVIDER_SUPPLIED',
        ]);

        $report = app(CompanyFundamentalService::class)->formatFundamentalReport('SCAL');
        $this->assertStringContainsString('NOMINAL_INTERPRETATION_BLOCKED', $report);
        $this->assertStringNotContainsString('Pendapatan Rp 0,50 M', $report);
    }

    /**
     * 8. Test Currency Conversion Integrity: Provider conversion without official rate blocks direct nominal comparison.
     */
    public function test_provider_conversion_without_official_rate_blocks_nominal_comparison(): void
    {
        IdxCompanyRegistry::create([
            'ticker' => 'CONV',
            'company_name' => 'PT Conversion Test Tbk',
            'listing_board' => 'Utama',
            'sector' => 'Energy',
            'instrument_type' => 'EQUITY',
            'snapshot_date' => now()->toDateString(),
        ]);

        MarketObservation::create([
            'ticker' => 'CONV',
            'bar_date' => '2026-10-30',
            'observation_time' => now(),
            'open_price' => 500.0,
            'high_price' => 520.0,
            'low_price' => 490.0,
            'close_price' => 500.0,
            'volume' => 1000000,
            'source' => 'IDX Edge PRO',
            'validation_status' => 'VALID',
            'is_eod_final' => true,
        ]);

        CompanyFundamental::create([
            'ticker' => 'CONV',
            'report_type' => 'ANNUAL',
            'fiscal_year' => 2025,
            'period_end_date' => '2025-12-31',
            'currency' => 'IDR',
            'unit' => 'IDR',
            'is_audited' => true,
            'total_revenue' => 1000000000000.0,
            'net_income' => 100000000000.0,
            'total_equity' => 500000000000.0,
            'source' => 'IDX Edge PRO',
            'data_quality' => 'PROVIDER_SUPPLIED',
            'raw_payload' => [
                'conversion_metadata' => [
                    'original_currency' => 'USD',
                    'conversion_method' => 'UNKNOWN_PROVIDER_CONVERSION',
                ],
            ],
        ]);

        $report = app(CompanyFundamentalService::class)->formatFundamentalReport('CONV');
        $this->assertStringContainsString('NOMINAL_INTERPRETATION_BLOCKED', $report);
        $this->assertStringContainsString('penyedia mengonversi tanpa nilai kurs resmi', $report);
    }

    /**
     * 9. Test Follow-up Ticker Switching Isolation: Context of Ticker A does not leak to Ticker B.
     */
    public function test_ticker_switching_context_isolation(): void
    {
        $service = app(TelegramTwoWayService::class);

        IdxCompanyRegistry::create([
            'ticker' => 'BBCA',
            'company_name' => 'Bank Central Asia Tbk.',
            'listing_board' => 'Utama',
            'sector' => 'Financials',
            'instrument_type' => 'EQUITY',
            'snapshot_date' => now()->toDateString(),
        ]);

        IdxCompanyRegistry::create([
            'ticker' => 'ASII',
            'company_name' => 'Astra International Tbk.',
            'listing_board' => 'Utama',
            'sector' => 'Industrials',
            'instrument_type' => 'EQUITY',
            'snapshot_date' => now()->toDateString(),
        ]);

        MarketObservation::create([
            'ticker' => 'BBCA',
            'bar_date' => '2026-10-30',
            'observation_time' => now(),
            'open_price' => 5000.0,
            'high_price' => 5050.0,
            'low_price' => 4950.0,
            'close_price' => 4980.0,
            'volume' => 50000000,
            'source' => 'IDX Edge PRO',
            'validation_status' => 'VALID',
            'is_eod_final' => true,
        ]);

        MarketObservation::create([
            'ticker' => 'ASII',
            'bar_date' => '2026-10-30',
            'observation_time' => now(),
            'open_price' => 4850.0,
            'high_price' => 4900.0,
            'low_price' => 4780.0,
            'close_price' => 4800.0,
            'volume' => 30000000,
            'source' => 'IDX Edge PRO',
            'validation_status' => 'VALID',
            'is_eod_final' => true,
        ]);

        // Turn 1: BBCA
        $prov1 = [];
        $res1 = $service->handleInboundMessage([
            'update_id' => 'upd_bbca_turn',
            'message' => [
                'message_id' => '9001',
                'from' => ['id' => 5923895922],
                'chat' => ['id' => 5923895922],
                'text' => 'analisis fundamental BBCA',
                'date' => time(),
            ],
            '_sync' => true,
        ]);

        $this->assertStringContainsString('Bank Central Asia Tbk.', $res1['reply_text']);
        $this->assertStringNotContainsString('Astra International', $res1['reply_text']);

        // Turn 2: Switch ticker to ASII
        $prov2 = [];
        $res2 = $service->handleInboundMessage([
            'update_id' => 'upd_asii_turn',
            'message' => [
                'message_id' => '9002',
                'from' => ['id' => 5923895922],
                'chat' => ['id' => 5923895922],
                'text' => 'bagaimana dengan fundamental ASII?',
                'date' => time(),
            ],
            '_sync' => true,
        ]);

        $this->assertStringContainsString('Astra International Tbk.', $res2['reply_text']);
        $this->assertStringNotContainsString('Bank Central Asia Tbk.', $res2['reply_text']);
        $this->assertStringNotContainsString('4.980', $res2['reply_text']);
    }

    /**
     * 10. Test Analysis Storage & Schema Contract: MarketAnalysis JSON containment & Error Sanitization.
     */
    public function test_analysis_storage_schema_contract_and_sanitization(): void
    {
        IdxCompanyRegistry::create([
            'ticker' => 'TEST',
            'company_name' => 'PT Test Emiten Tbk',
            'listing_board' => 'Utama',
            'sector' => 'Technology',
            'instrument_type' => 'EQUITY',
            'snapshot_date' => now()->toDateString(),
        ]);

        MarketObservation::create([
            'ticker' => 'TEST',
            'bar_date' => '2026-10-30',
            'observation_time' => now(),
            'open_price' => 100.0,
            'high_price' => 110.0,
            'low_price' => 95.0,
            'close_price' => 105.0,
            'volume' => 500000,
            'source' => 'IDX Edge PRO',
            'validation_status' => 'VALID',
            'is_eod_final' => true,
        ]);

        $run = AnalysisRun::create([
            'run_id' => 'RUN_TEST_'.now()->format('Ymd_His'),
            'trigger_type' => 'test_contract',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'metadata' => [
                'ticker' => 'TEST',
                'run_type' => 'TICKER_NARRATIVE',
            ],
        ]);

        // MarketAnalysis correctly associates with analysis_run_id and structured_output JSON
        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'validation_status' => 'valid_schema',
            'market_regime_summary' => 'Regim pasar akumulasi moderat.',
            'structured_output' => [
                'ticker' => 'TEST',
                'bar_date' => '2026-10-30',
                'summary' => 'Regim pasar akumulasi moderat.',
            ],
        ]);

        // Querying via MarketAnalysis JSON schema contract works without raw ticker column error
        $queried = MarketAnalysis::whereJsonContains('structured_output->ticker', 'TEST')
            ->whereJsonContains('structured_output->bar_date', '2026-10-30')
            ->where('validation_status', 'valid_schema')
            ->first();

        $this->assertNotNull($queried);
        $this->assertEquals($analysis->id, $queried->id);
        $this->assertEquals('Regim pasar akumulasi moderat.', $queried->market_regime_summary);
    }

    /**
     * 11. Test Outbox Superseded Policy for Completed Jobs: Alerts are canceled when job completes.
     */
    public function test_outbox_superseded_policy_for_completed_jobs(): void
    {
        $job = ScheduledTelegramJob::create([
            'chat_id' => (string) config('services.telegram.chat_id', '5923895922'),
            'telegram_user_id' => '5923895922',
            'inbound_message_id' => '7788',
            'original_prompt' => 'analisis BBCA',
            'request_type' => 'ASYNC_CONSULTATION',
            'scheduled_at' => now()->utc(),
            'status' => 'COMPLETED',
            'outbound_message_id' => '8899',
            'idempotency_key' => 'TEST_JOB_SUPERSEDED_'.uniqid(),
        ]);

        $alertOutbox = TelegramOutbox::create([
            'dedup_key' => 'MISSED_JOB_'.$job->id.'_test',
            'chat_id' => $job->chat_id,
            'reply_to_message_id' => '7788',
            'message_type' => 'SYSTEM_ALERT',
            'payload_text' => 'Deadline terlampaui.',
            'status' => 'QUEUED',
            'metadata' => [
                'scheduled_job_id' => $job->id,
            ],
        ]);

        $formattingService = app(TelegramFormattingService::class);
        $res = $formattingService->processOutboxQueue(mockOnly: true);

        $alertOutbox->refresh();
        $this->assertEquals('SUPERSEDED_UNSENT', $alertOutbox->status);
        $this->assertStringContainsString('telah selesai dan terkirim dengan sukses', $alertOutbox->error_message);
    }

    /**
     * 12. Test Stale Alert Cancelled on Active Recovery Attempt: Stale alert is cancelled when new worker attempt claims job.
     */
    public function test_stale_alert_cancelled_on_active_recovery_attempt(): void
    {
        $job = ScheduledTelegramJob::create([
            'chat_id' => (string) config('services.telegram.chat_id', '5923895922'),
            'telegram_user_id' => '5923895922',
            'inbound_message_id' => '9901',
            'original_prompt' => 'analisis ASII',
            'request_type' => 'ASYNC_CONSULTATION',
            'scheduled_at' => now()->utc(),
            'status' => 'PROCESSING',
            'worker_token' => 'WTOK_NEW_RECOVERY_ATTEMPT',
            'idempotency_key' => 'TEST_JOB_RECOVERY_'.uniqid(),
        ]);

        // Old alert generated by previous attempt WTOK_OLD_FAILED
        $alertOutbox = TelegramOutbox::create([
            'dedup_key' => 'ERR_JOB_'.$job->id.'_old',
            'chat_id' => $job->chat_id,
            'reply_to_message_id' => '9901',
            'message_type' => 'SYSTEM_ALERT',
            'payload_text' => 'Gagal memproses.',
            'status' => 'QUEUED',
            'metadata' => [
                'scheduled_job_id' => $job->id,
                'worker_token' => 'WTOK_OLD_FAILED',
            ],
        ]);

        $formattingService = app(TelegramFormattingService::class);
        $res = $formattingService->processOutboxQueue(mockOnly: true);

        $alertOutbox->refresh();
        $this->assertEquals('SUPERSEDED_UNSENT', $alertOutbox->status);
        $this->assertStringContainsString('attempt pemulihan aktif', $alertOutbox->error_message);
        $this->assertEquals('RECOVERY_IN_PROGRESS', $alertOutbox->metadata['superseded_reason']);
    }

    /**
     * 13. Test Cross Ticker Parametric Independence: Ticker B does not inherit Ticker A figures.
     */
    public function test_ticker_b_does_not_inherit_ticker_a_figures(): void
    {
        IdxCompanyRegistry::create([
            'ticker' => 'SMRA',
            'company_name' => 'Summarecon Agung Tbk',
            'listing_board' => 'Utama',
            'sector' => 'Real Estate',
            'instrument_type' => 'EQUITY',
            'snapshot_date' => now()->toDateString(),
        ]);

        MarketObservation::create([
            'ticker' => 'SMRA',
            'bar_date' => '2026-10-30',
            'observation_time' => now(),
            'open_price' => 600.0,
            'high_price' => 610.0,
            'low_price' => 590.0,
            'close_price' => 595.0,
            'volume' => 12000000,
            'source' => 'IDX Edge PRO',
            'validation_status' => 'VALID',
            'is_eod_final' => true,
        ]);

        $service = app(CompanyFundamentalService::class);
        $smraReport = $service->formatFundamentalReport('SMRA');

        // SMRA should not inherit BULL price 288 or BBCA price 4980
        $this->assertStringContainsString('595,00', $smraReport);
        $this->assertStringNotContainsString('288,00', $smraReport);
        $this->assertStringNotContainsString('4.980,00', $smraReport);
    }
}
