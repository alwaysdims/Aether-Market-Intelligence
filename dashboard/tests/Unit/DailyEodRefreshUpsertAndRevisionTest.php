<?php

namespace Tests\Unit;

use App\Models\DataRevisionArchive;
use App\Models\EodSessionQueue;
use App\Models\IdxCompanyRegistry;
use App\Models\MarketObservation;
use App\Services\BeiCalendarService;
use App\Services\BrokerSummaryIngestionService;
use App\Services\DailyEodRefreshService;
use App\Services\IdxEdgeClient;
use App\Services\TechnicalMetricsCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DailyEodRefreshUpsertAndRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_eod_refresh_adds_new_session_bar_without_duplicates(): void
    {
        $ticker = 'TEST';
        IdxCompanyRegistry::create([
            'ticker' => $ticker,
            'company_name' => 'Test Emiten Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'snapshot_date' => '2026-10-30',
            'last_bar_date' => '2026-10-30',
        ]);

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockCalendar = Mockery::mock(BeiCalendarService::class);
        $mockMetrics = Mockery::mock(TechnicalMetricsCalculationService::class);
        $mockBroker = Mockery::mock(BrokerSummaryIngestionService::class);

        $mockCalendar->shouldReceive('getCurrentTradingDay')
            ->andReturn(Carbon::parse('2026-11-02'));
        $mockCalendar->shouldReceive('isTradingDay')
            ->andReturn(false)
            ->byDefault();

        // First call: returns 2026-11-02 bar
        $bars = [
            [
                'date' => '2026-10-30',
                'open' => 1000,
                'high' => 1050,
                'low' => 990,
                'close' => 1020,
                'volume' => 5000000,
                'source' => 'IDX Edge PRO',
                'board_scope' => 'RG',
                'validation_status' => 'VALID',
            ],
            [
                'date' => '2026-11-02',
                'open' => 1020,
                'high' => 1080,
                'low' => 1010,
                'close' => 1060,
                'volume' => 8000000,
                'source' => 'IDX Edge PRO',
                'board_scope' => 'RG',
                'validation_status' => 'VALID',
            ],
        ];

        $mockClient->shouldReceive('history')
            ->with($ticker, 20, true, null, null, true)
            ->andReturn([
                'success' => true,
                'data' => [
                    'ticker' => $ticker,
                    'bars' => $bars,
                ],
            ]);

        $mockClient->shouldReceive('history')
            ->with($ticker, 20, false, null, null, true)
            ->andReturn([
                'success' => true,
                'data' => [
                    'ticker' => $ticker,
                    'bars' => $bars,
                ],
            ]);

        $mockMetrics->shouldReceive('calculateForTicker')
            ->with($ticker, '2026-11-02')
            ->andReturn(null);

        $service = new DailyEodRefreshService($mockClient, $mockCalendar, $mockMetrics, $mockBroker);

        // Run refresh 1
        $res1 = $service->refreshDailyEod([$ticker], 20, false);
        $this->assertEquals(1, $res1['updated']);
        $this->assertEquals(2, MarketObservation::where('ticker', $ticker)->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')->count());

        // Run refresh 2 (idempotent test)
        $res2 = $service->refreshDailyEod([$ticker], 20, false);
        $this->assertEquals(1, $res2['already_fresh']);
        $this->assertEquals(2, MarketObservation::where('ticker', $ticker)->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')->count());
    }

    public function test_fresh_session_recalculates_metrics_with_session_date(): void
    {
        $ticker = 'FRESH';
        IdxCompanyRegistry::create([
            'ticker' => $ticker,
            'company_name' => 'Fresh Fixture Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'snapshot_date' => '2026-11-02',
            'last_bar_date' => '2026-11-02',
        ]);

        foreach (['UNADJUSTED', 'PROVIDER_BACK_ADJUSTED'] as $mode) {
            MarketObservation::create([
                'ticker' => $ticker,
                'bar_date' => '2026-11-02',
                'observation_time' => Carbon::parse('2026-11-02 16:00:00', 'Asia/Jakarta')->utc(),
                'interval' => '1d',
                'data_classification' => 'PUBLIC_MARKET_EOD',
                'source' => 'IDX Edge PRO',
                'adjustment_mode' => $mode,
                'open_price' => 1000,
                'high_price' => 1050,
                'low_price' => 990,
                'close_price' => 1020,
                'volume' => 1000000,
                'validation_status' => 'VALID',
                'board_scope' => 'RG',
            ]);
        }

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockCalendar = Mockery::mock(BeiCalendarService::class);
        $mockMetrics = Mockery::mock(TechnicalMetricsCalculationService::class);
        $mockBroker = Mockery::mock(BrokerSummaryIngestionService::class);
        $mockMetrics->shouldReceive('calculateForTicker')
            ->once()
            ->with($ticker, '2026-11-02')
            ->andReturn(null);

        $service = new DailyEodRefreshService($mockClient, $mockCalendar, $mockMetrics, $mockBroker);
        $result = $service->refreshDailyEod([$ticker], 20, false, false, '2026-11-02');

        $this->assertSame(1, $result['already_fresh']);
        $this->assertSame(1, $result['metrics_insufficient_input']);
        $this->assertSame('INSUFFICIENT_INPUT', $result['metric_evidence'][$ticker]['status']);
        $this->assertSame('2026-11-02', $result['metric_evidence'][$ticker]['as_of_date']);
    }

    public function test_eod_refresh_detects_provider_revision_archives_previous_and_updates_canonical(): void
    {
        $ticker = 'TEST';
        IdxCompanyRegistry::create([
            'ticker' => $ticker,
            'company_name' => 'Test Emiten Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'snapshot_date' => '2026-10-30',
            'last_bar_date' => '2026-10-30',
        ]);

        $initialHash = hash('sha256', "{$ticker}|2026-11-02|1020|1080|1010|1060|8000000|IDX Edge PRO|PROVIDER_BACK_ADJUSTED");
        MarketObservation::create([
            'ticker' => $ticker,
            'bar_date' => '2026-11-02 00:00:00',
            'observation_time' => Carbon::parse('2026-11-02 16:00:00', 'Asia/Jakarta')->utc(),
            'interval' => '1d',
            'data_classification' => 'PUBLIC_MARKET_EOD',
            'source' => 'IDX Edge PRO',
            'adjustment_mode' => 'PROVIDER_BACK_ADJUSTED',
            'open_price' => 1020,
            'high_price' => 1080,
            'low_price' => 1010,
            'close_price' => 1060,
            'volume' => 8000000,
            'data_hash' => $initialHash,
            'source' => 'IDX Edge PRO',
            'board_scope' => 'RG',
            'validation_status' => 'VALID',
        ]);

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockCalendar = Mockery::mock(BeiCalendarService::class);
        $mockMetrics = Mockery::mock(TechnicalMetricsCalculationService::class);
        $mockBroker = Mockery::mock(BrokerSummaryIngestionService::class);

        $mockCalendar->shouldReceive('getCurrentTradingDay')
            ->andReturn(Carbon::parse('2026-11-02'));
        $mockCalendar->shouldReceive('isTradingDay')
            ->andReturn(false)
            ->byDefault();

        // Provider sends revised settlement close price from 1060 to 1055 and volume 8200000
        $revisedBars = [
            [
                'date' => '2026-11-02',
                'open' => 1020,
                'high' => 1080,
                'low' => 1010,
                'close' => 1055,
                'volume' => 8200000,
                'source' => 'IDX Edge PRO',
                'board_scope' => 'RG',
                'validation_status' => 'VALID',
            ],
        ];

        $mockClient->shouldReceive('history')
            ->with($ticker, 20, true, null, null, true)
            ->andReturn([
                'success' => true,
                'data' => [
                    'ticker' => $ticker,
                    'bars' => $revisedBars,
                ],
            ]);

        $mockClient->shouldReceive('history')
            ->with($ticker, 20, false, null, null, true)
            ->andReturn([
                'success' => false,
            ]);

        $mockMetrics->shouldReceive('calculateForTicker')
            ->with($ticker, '2026-11-02')
            ->andReturn(null);

        $service = new DailyEodRefreshService($mockClient, $mockCalendar, $mockMetrics, $mockBroker);

        $res = $service->refreshDailyEod([$ticker], 20, false);

        // Verify revision was archived
        $this->assertEquals(1, DataRevisionArchive::where('ticker', $ticker)->count());
        $archive = DataRevisionArchive::where('ticker', $ticker)->first();
        $this->assertEquals('MarketObservation', $archive->record_type);
        $this->assertEquals('2026-11-02', $archive->period_or_date);
        $this->assertEquals(1060, $archive->previous_payload['close_price']);
        $this->assertEquals(1055, $archive->new_payload['close']);

        // Verify canonical market observation updated
        $updatedObs = MarketObservation::where('ticker', $ticker)->whereDate('bar_date', '2026-11-02')->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')->first();
        $this->assertNotNull($updatedObs);
        $this->assertEquals(1055, (float) $updatedObs->close_price);
        $this->assertEquals(8200000, (float) $updatedObs->volume);
    }

    public function test_eod_refresh_persists_retry_schedule_when_session_not_yet_published(): void
    {
        $ticker = 'TEST';
        IdxCompanyRegistry::create([
            'ticker' => $ticker,
            'company_name' => 'Test Emiten Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'snapshot_date' => '2026-10-30',
            'last_bar_date' => '2026-10-30',
            'eod_target_session' => '2026-11-02',
            'eod_retry_attempts' => 1,
        ]);

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockCalendar = Mockery::mock(BeiCalendarService::class);
        $mockMetrics = Mockery::mock(TechnicalMetricsCalculationService::class);
        $mockBroker = Mockery::mock(BrokerSummaryIngestionService::class);

        $mockCalendar->shouldReceive('getCurrentTradingDay')
            ->andReturn(Carbon::parse('2026-11-02'));
        $mockCalendar->shouldReceive('isTradingDay')
            ->andReturn(false)
            ->byDefault();

        // Provider only returns up to 2026-10-30 (target 2026-11-02 not yet available at 16:05 probe)
        $bars = [
            [
                'date' => '2026-10-30',
                'open' => 1000,
                'high' => 1050,
                'low' => 990,
                'close' => 1020,
                'volume' => 5000000,
                'source' => 'IDX Edge PRO',
                'board_scope' => 'RG',
                'validation_status' => 'VALID',
            ],
        ];

        $mockClient->shouldReceive('history')
            ->with($ticker, 20, true, null, null, true)
            ->andReturn([
                'success' => true,
                'data' => [
                    'ticker' => $ticker,
                    'bars' => $bars,
                ],
            ]);

        $service = new DailyEodRefreshService($mockClient, $mockCalendar, $mockMetrics, $mockBroker);

        $res = $service->refreshDailyEod([$ticker], 20, false);
        $this->assertEquals(1, $res['waiting_for_session']);

        $reg = IdxCompanyRegistry::where('ticker', $ticker)->first();
        $this->assertEquals('WAITING_FOR_SESSION_DATA', $reg->eod_refresh_status);
        $this->assertEquals(2, $reg->eod_retry_attempts); // Incremented from 1 to 2
        $this->assertNotNull($reg->eod_next_retry_at);
        $this->assertTrue($reg->eod_next_retry_at->isFuture());
    }

    public function test_retry_only_mode_picks_due_records_and_updates_on_subsequent_probe(): void
    {
        $ticker = 'DUE';
        IdxCompanyRegistry::create([
            'ticker' => $ticker,
            'company_name' => 'Due Emiten Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'snapshot_date' => '2026-10-30',
            'last_bar_date' => '2026-10-30',
            'eod_refresh_status' => 'WAITING_FOR_SESSION_DATA',
            'eod_retry_attempts' => 1,
            'eod_target_session' => '2026-11-02',
            'eod_next_retry_at' => Carbon::now()->subMinutes(5), // Already due
        ]);

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockCalendar = Mockery::mock(BeiCalendarService::class);
        $mockMetrics = Mockery::mock(TechnicalMetricsCalculationService::class);
        $mockBroker = Mockery::mock(BrokerSummaryIngestionService::class);

        $mockCalendar->shouldReceive('getCurrentTradingDay')
            ->andReturn(Carbon::parse('2026-11-02'));
        $mockCalendar->shouldReceive('isTradingDay')
            ->andReturn(false)
            ->byDefault();

        // On retry probe, provider now has the released 2026-11-02 bar
        $bars = [
            [
                'date' => '2026-11-02',
                'open' => 2000,
                'high' => 2050,
                'low' => 1990,
                'close' => 2040,
                'volume' => 3000000,
                'source' => 'IDX Edge PRO',
                'board_scope' => 'RG',
                'validation_status' => 'VALID',
            ],
        ];

        $mockClient->shouldReceive('history')
            ->with($ticker, 20, true, null, null, true)
            ->andReturn([
                'success' => true,
                'data' => [
                    'ticker' => $ticker,
                    'bars' => $bars,
                ],
            ]);

        $mockClient->shouldReceive('history')
            ->with($ticker, 20, false, null, null, true)
            ->andReturn([
                'success' => true,
                'data' => [
                    'ticker' => $ticker,
                    'bars' => $bars,
                ],
            ]);

        $mockMetrics->shouldReceive('calculateForTicker')
            ->with($ticker, '2026-11-02')
            ->andReturn(null);

        $service = new DailyEodRefreshService($mockClient, $mockCalendar, $mockMetrics, $mockBroker);

        // Run with retryOnly = true
        $res = $service->refreshDailyEod(null, 20, false, true);
        $this->assertEquals(1, $res['total_eligible']);
        $this->assertEquals(1, $res['updated']);

        $updatedReg = IdxCompanyRegistry::where('ticker', $ticker)->first();
        $this->assertEquals('UPDATED', $updatedReg->eod_refresh_status);
        $this->assertNull($updatedReg->eod_next_retry_at);
        $this->assertEquals('2026-11-02', $updatedReg->last_bar_date->toDateString());
    }

    public function test_retry_rechecks_missing_nominal_bar_without_consuming_attempt_on_local_limiter(): void
    {
        $ticker = 'RETRY';
        IdxCompanyRegistry::create([
            'ticker' => $ticker,
            'company_name' => 'Retry Fixture Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'snapshot_date' => '2026-11-01',
            'last_bar_date' => '2026-11-01',
        ]);

        $adjustedBars = [[
            'date' => '2026-11-02', 'open' => 1000, 'high' => 1050, 'low' => 990,
            'close' => 1020, 'volume' => 1000000, 'source' => 'IDX Edge PRO',
            'board_scope' => 'RG', 'validation_status' => 'VALID',
        ]];
        $rawBars = [[
            'date' => '2026-11-02', 'open' => 1000, 'high' => 1050, 'low' => 990,
            'close' => 1020, 'volume' => 1000000, 'source' => 'IDX Edge PRO',
            'board_scope' => 'RG', 'validation_status' => 'VALID',
        ]];

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockClient->shouldReceive('history')->with($ticker, 20, true, null, null, true)->twice()->andReturn(
            ['success' => true, 'data' => ['bars' => $adjustedBars]],
            ['success' => true, 'data' => ['bars' => $adjustedBars]],
        );
        $mockClient->shouldReceive('history')->with($ticker, 20, false, null, null, true)->twice()->andReturn(
            ['success' => false, 'error_type' => 'PROVIDER_NO_DATA', 'data' => ['bars' => []]],
            ['success' => true, 'data' => ['bars' => $rawBars]],
        );
        $mockCalendar = Mockery::mock(BeiCalendarService::class);
        $mockCalendar->shouldReceive('getCurrentTradingDay')->andReturn(Carbon::parse('2026-11-02'));
        $mockCalendar->shouldReceive('isTradingDay')->andReturn(false)->byDefault();
        $mockMetrics = Mockery::mock(TechnicalMetricsCalculationService::class);
        $mockMetrics->shouldReceive('calculateForTicker')->with($ticker, '2026-11-02')->andReturn(null);
        $mockBroker = Mockery::mock(BrokerSummaryIngestionService::class);
        $service = new DailyEodRefreshService($mockClient, $mockCalendar, $mockMetrics, $mockBroker);

        $first = $service->refreshDailyEod([$ticker], 20, false, false, '2026-11-02');
        $afterFirst = IdxCompanyRegistry::where('ticker', $ticker)->firstOrFail();
        $this->assertSame(0, $first['updated']);
        $this->assertSame('PARTIAL_NOMINAL_MISSING', $afterFirst->eod_refresh_status);
        $this->assertSame(1, $afterFirst->eod_retry_attempts);
        $this->assertNotNull($afterFirst->eod_next_retry_at);

        $afterFirst->update(['eod_next_retry_at' => Carbon::now()->subMinute()]);
        $second = $service->refreshDailyEod(null, 20, false, true, '2026-11-02');
        $afterSecond = $afterFirst->fresh();
        $this->assertSame(1, $second['updated']);
        $this->assertSame('UPDATED', $afterSecond->eod_refresh_status);
        $this->assertSame(0, $afterSecond->eod_retry_attempts);
        $this->assertNull($afterSecond->eod_next_retry_at);
        $this->assertSame(1, MarketObservation::where('ticker', $ticker)->whereDate('bar_date', '2026-11-02')->where('adjustment_mode', 'UNADJUSTED')->count());
    }

    public function test_new_target_session_resets_retry_state_before_provider_attempt(): void
    {
        $ticker = 'NEWSESSION';
        IdxCompanyRegistry::create([
            'ticker' => $ticker,
            'company_name' => 'New Session Fixture Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'snapshot_date' => '2026-11-17',
            'last_bar_date' => '2026-11-17',
            'eod_refresh_status' => 'MAX_RETRIES_EXCEEDED',
            'eod_target_session' => '2026-11-17',
            'eod_retry_attempts' => 5,
            'eod_next_retry_at' => Carbon::now()->addHour(),
            'eod_last_error' => 'Old session failure',
        ]);

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockClient->shouldReceive('history')
            ->with($ticker, 20, true, null, null, true)
            ->once()
            ->andReturn([
                'success' => false,
                'error_type' => 'PROVIDER_NO_DATA',
                'message' => 'Target session not published',
                'data' => ['bars' => []],
            ]);
        $service = new DailyEodRefreshService(
            $mockClient,
            Mockery::mock(BeiCalendarService::class),
            Mockery::mock(TechnicalMetricsCalculationService::class),
            Mockery::mock(BrokerSummaryIngestionService::class),
        );

        $result = $service->refreshDailyEod([$ticker], 20, false, false, '2026-11-18');
        $registry = IdxCompanyRegistry::where('ticker', $ticker)->firstOrFail();

        $this->assertSame(0, $result['retry_evidence'][$ticker]['attempt_before']);
        $this->assertSame(1, $registry->eod_retry_attempts);
        $this->assertSame('2026-11-18', $registry->eod_target_session->toDateString());
    }

    public function test_retry_only_does_not_select_retry_state_from_another_session(): void
    {
        IdxCompanyRegistry::create([
            'ticker' => 'OLDRETRY',
            'company_name' => 'Old Retry Fixture Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'snapshot_date' => '2026-11-17',
            'last_bar_date' => '2026-11-17',
            'eod_refresh_status' => 'RETRY_SCHEDULED',
            'eod_target_session' => '2026-11-17',
            'eod_retry_attempts' => 1,
            'eod_next_retry_at' => Carbon::now()->subMinute(),
        ]);

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockClient->shouldNotReceive('history');
        $service = new DailyEodRefreshService(
            $mockClient,
            Mockery::mock(BeiCalendarService::class),
            Mockery::mock(TechnicalMetricsCalculationService::class),
            Mockery::mock(BrokerSummaryIngestionService::class),
        );

        $result = $service->refreshDailyEod(null, 20, false, true, '2026-11-18');

        $this->assertSame(0, $result['total_eligible']);
    }

    public function test_local_rate_limit_retry_records_final_response_evidence(): void
    {
        $ticker = 'LIMIT';
        IdxCompanyRegistry::create([
            'ticker' => $ticker,
            'company_name' => 'Rate Limit Fixture Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'snapshot_date' => '2026-11-17',
            'last_bar_date' => '2026-11-17',
        ]);
        $bar = [
            'date' => '2026-11-18', 'open' => 1000, 'high' => 1050, 'low' => 990,
            'close' => 1020, 'volume' => 1000000, 'source' => 'IDX Edge PRO',
            'board_scope' => 'RG', 'validation_status' => 'VALID',
        ];

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockClient->shouldReceive('history')->with($ticker, 20, true, null, null, true)->twice()->andReturn(
            ['success' => false, 'error_type' => 'LOCAL_RATE_LIMIT', 'available_in' => 0, 'data' => ['bars' => []]],
            ['success' => true, 'http_status' => 200, 'transport_status' => 'HTTP_RESPONSE', 'data' => ['bars' => [$bar]]],
        );
        $mockClient->shouldReceive('history')->with($ticker, 20, false, null, null, true)->once()
            ->andReturn(['success' => true, 'data' => ['bars' => [$bar]]]);
        $mockMetrics = Mockery::mock(TechnicalMetricsCalculationService::class);
        $mockMetrics->shouldReceive('calculateForTicker')->with($ticker, '2026-11-18')->andReturn(null);
        $service = new DailyEodRefreshService(
            $mockClient,
            Mockery::mock(BeiCalendarService::class),
            $mockMetrics,
            Mockery::mock(BrokerSummaryIngestionService::class),
        );

        $result = $service->refreshDailyEod([$ticker], 20, false, false, '2026-11-18');
        $evidence = $result['retry_evidence'][$ticker]['adjusted_request'];

        $this->assertTrue($evidence['success']);
        $this->assertSame(200, $evidence['http_status']);
        $this->assertNull($evidence['message']);
        $this->assertSame(['2026-11-18'], $evidence['bar_dates']);
    }

    public function test_stale_adjusted_response_marks_raw_request_skipped(): void
    {
        $ticker = 'STALE';
        IdxCompanyRegistry::create([
            'ticker' => $ticker,
            'company_name' => 'Stale Fixture Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'snapshot_date' => '2026-11-17',
            'last_bar_date' => '2026-11-17',
        ]);
        $bar = [
            'date' => '2026-11-17', 'open' => 1000, 'high' => 1050, 'low' => 990,
            'close' => 1020, 'volume' => 1000000, 'source' => 'IDX Edge PRO',
            'board_scope' => 'RG', 'validation_status' => 'VALID',
        ];

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockClient->shouldReceive('history')->with($ticker, 20, true, null, null, true)->once()
            ->andReturn(['success' => true, 'data' => ['bars' => [$bar]]]);
        $mockClient->shouldNotReceive('history')->with($ticker, 20, false, null, null, true);
        $service = new DailyEodRefreshService(
            $mockClient,
            Mockery::mock(BeiCalendarService::class),
            Mockery::mock(TechnicalMetricsCalculationService::class),
            Mockery::mock(BrokerSummaryIngestionService::class),
        );

        $result = $service->refreshDailyEod([$ticker], 20, false, false, '2026-11-18');
        $rawEvidence = $result['retry_evidence'][$ticker]['raw_request'];

        $this->assertFalse($rawEvidence['performed']);
        $this->assertSame('SKIPPED', $rawEvidence['status']);
        $this->assertSame('TARGET_SESSION_UNAVAILABLE', $rawEvidence['reason']);
    }

    public function test_nominal_refill_records_unadjusted_request_evidence(): void
    {
        $ticker = 'REFILL';
        IdxCompanyRegistry::create([
            'ticker' => $ticker,
            'company_name' => 'Nominal Refill Fixture Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'snapshot_date' => '2026-11-18',
            'last_bar_date' => '2026-11-18',
            'eod_refresh_status' => 'PARTIAL_NOMINAL_MISSING',
            'eod_target_session' => '2026-11-18',
            'eod_retry_attempts' => 1,
        ]);
        MarketObservation::create([
            'ticker' => $ticker,
            'bar_date' => '2026-11-18',
            'observation_time' => Carbon::parse('2026-11-18 16:00:00', 'Asia/Jakarta')->utc(),
            'interval' => '1d',
            'data_classification' => 'PUBLIC_MARKET_EOD',
            'source' => 'IDX Edge PRO',
            'adjustment_mode' => 'PROVIDER_BACK_ADJUSTED',
            'open_price' => 1000,
            'high_price' => 1050,
            'low_price' => 990,
            'close_price' => 1020,
            'volume' => 1000000,
            'validation_status' => 'VALID',
            'board_scope' => 'RG',
        ]);
        $rawBar = [
            'date' => '2026-11-18', 'open' => 1000, 'high' => 1050, 'low' => 990,
            'close' => 1020, 'volume' => 1000000, 'source' => 'IDX Edge PRO',
            'board_scope' => 'RG', 'validation_status' => 'VALID',
        ];

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockClient->shouldReceive('history')->with($ticker, 20, false, null, null, true)->once()
            ->andReturn([
                'success' => true,
                'http_status' => 200,
                'transport_status' => 'HTTP_RESPONSE',
                'data' => ['bars' => [$rawBar]],
            ]);
        $service = new DailyEodRefreshService(
            $mockClient,
            Mockery::mock(BeiCalendarService::class),
            Mockery::mock(TechnicalMetricsCalculationService::class),
            Mockery::mock(BrokerSummaryIngestionService::class),
        );

        $result = $service->refreshDailyEod([$ticker], 20, false, false, '2026-11-18');
        $evidence = $result['retry_evidence'][$ticker];

        $this->assertSame('SKIPPED', $evidence['adjusted_request']['status']);
        $this->assertSame('ADJUSTED_ALREADY_CANONICAL', $evidence['adjusted_request']['reason']);
        $this->assertTrue($evidence['raw_request']['success']);
        $this->assertSame(200, $evidence['raw_request']['http_status']);
        $this->assertSame(['2026-11-18'], $evidence['raw_request']['bar_dates']);
    }

    public function test_provider_failure_evidence_preserves_transport_http_period_and_mode(): void
    {
        $ticker = 'EVIDENCE';
        IdxCompanyRegistry::create([
            'ticker' => $ticker,
            'company_name' => 'Evidence Fixture Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'snapshot_date' => '2026-11-02',
            'last_bar_date' => '2026-11-01',
        ]);

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockCalendar = Mockery::mock(BeiCalendarService::class);
        $mockMetrics = Mockery::mock(TechnicalMetricsCalculationService::class);
        $mockBroker = Mockery::mock(BrokerSummaryIngestionService::class);
        $mockClient->shouldReceive('history')
            ->with($ticker, 20, true, null, null, true)
            ->once()
            ->andReturn([
                'success' => false,
                'error_type' => 'PROVIDER_HTTP_404',
                'message' => 'Quote not found: [REDACTED]',
                'http_status' => 404,
                'transport_status' => 'HTTP_RESPONSE',
                'response_period' => [
                    'started_at' => '2026-11-17T15:00:00Z',
                    'finished_at' => '2026-11-17T15:00:01Z',
                    'duration_ms' => 1000,
                ],
            ]);

        $service = new DailyEodRefreshService($mockClient, $mockCalendar, $mockMetrics, $mockBroker);
        $result = $service->refreshDailyEod([$ticker], 20, false, false, '2026-11-02');
        $evidence = $result['retry_evidence'][$ticker]['adjusted_request'];

        $this->assertSame('PROVIDER_BACK_ADJUSTED', $evidence['mode']);
        $this->assertSame('PROVIDER_HTTP_404', $evidence['error_type']);
        $this->assertSame('HTTP_RESPONSE', $evidence['transport_status']);
        $this->assertSame(404, $evidence['http_status']);
        $this->assertSame('Quote not found: [REDACTED]', $evidence['message']);
        $this->assertSame(1000, $evidence['response_period']['duration_ms']);
    }

    public function test_non_canonical_board_or_archive_source_does_not_satisfy_fresh_coverage(): void
    {
        $ticker = 'NEGATIVE';
        IdxCompanyRegistry::create([
            'ticker' => $ticker,
            'company_name' => 'Negative Fixture Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'snapshot_date' => '2026-11-02',
            'last_bar_date' => '2026-11-02',
        ]);

        foreach (['UNADJUSTED' => ['Yahoo Finance', 'UNVERIFIED'], 'PROVIDER_BACK_ADJUSTED' => ['IDX Edge PRO', 'NG']] as $mode => [$source, $boardScope]) {
            MarketObservation::create([
                'ticker' => $ticker,
                'bar_date' => '2026-11-02',
                'observation_time' => Carbon::parse('2026-11-02 16:00:00', 'Asia/Jakarta')->utc(),
                'interval' => '1d',
                'data_classification' => 'PUBLIC_MARKET_EOD',
                'source' => $source,
                'adjustment_mode' => $mode,
                'open_price' => 1000,
                'high_price' => 1050,
                'low_price' => 990,
                'close_price' => 1020,
                'volume' => 1000000,
                'validation_status' => 'VALID',
                'board_scope' => $boardScope,
            ]);
        }

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockCalendar = Mockery::mock(BeiCalendarService::class);
        $mockMetrics = Mockery::mock(TechnicalMetricsCalculationService::class);
        $mockBroker = Mockery::mock(BrokerSummaryIngestionService::class);
        $mockClient->shouldReceive('history')->once()->andReturn(['success' => false, 'error_type' => 'PROVIDER_NO_DATA']);

        $service = new DailyEodRefreshService($mockClient, $mockCalendar, $mockMetrics, $mockBroker);
        $result = $service->refreshDailyEod([$ticker], 20, false, false, '2026-11-02');

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['failed']);
        $this->assertFalse($result['data_coverage_complete']);
        $this->assertSame('PROVIDER_NO_DATA', $result['retry_evidence'][$ticker]['adjusted_request']['error_type']);
        $this->assertSame('Gagal mengambil bar terbaru.', $result['retry_evidence'][$ticker]['adjusted_request']['message']);
    }

    public function test_eod_refresh_does_not_mark_updated_when_nominal_fails_and_records_partial_nominal_missing(): void
    {
        $ticker = 'NOMFAIL';
        IdxCompanyRegistry::create([
            'ticker' => $ticker,
            'company_name' => 'Nominal Fail Emiten Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'snapshot_date' => '2026-10-30',
            'last_bar_date' => '2026-10-30',
        ]);

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockCalendar = Mockery::mock(BeiCalendarService::class);
        $mockMetrics = Mockery::mock(TechnicalMetricsCalculationService::class);
        $mockBroker = Mockery::mock(BrokerSummaryIngestionService::class);

        $mockCalendar->shouldReceive('isTradingDay')
            ->andReturn(true);
        $mockCalendar->shouldReceive('getCurrentTradingDay')
            ->andReturn(Carbon::parse('2026-11-02'));
        $mockCalendar->shouldReceive('getPreviousTradingDay')
            ->andReturn(Carbon::parse('2026-10-30'));
        $mockCalendar->shouldReceive('isTradingDay')
            ->andReturn(false)
            ->byDefault();

        // Adjusted returns valid bar
        $adjBars = [
            [
                'date' => '2026-11-02',
                'open' => 500,
                'high' => 520,
                'low' => 495,
                'close' => 510,
                'volume' => 1000000,
                'source' => 'IDX Edge PRO',
                'board_scope' => 'RG',
                'validation_status' => 'VALID',
            ],
        ];

        $mockClient->shouldReceive('history')
            ->with($ticker, 20, true, null, null, true)
            ->andReturn([
                'success' => true,
                'data' => [
                    'ticker' => $ticker,
                    'bars' => $adjBars,
                ],
            ]);

        // Nominal unadjusted call fails or returns empty bars
        $mockClient->shouldReceive('history')
            ->with($ticker, 20, false, null, null, true)
            ->andReturn([
                'success' => true,
                'data' => [
                    'ticker' => $ticker,
                    'bars' => [],
                ],
            ]);

        $service = new DailyEodRefreshService($mockClient, $mockCalendar, $mockMetrics, $mockBroker);

        $res = $service->refreshDailyEod([$ticker], 20, false);

        // Verification: updated must be 0, waiting_for_session must be 1
        $this->assertEquals(0, $res['updated']);
        $this->assertEquals(1, $res['waiting_for_session']);
        $this->assertFalse($res['universe_fully_fresh']);

        // Registry status must be PARTIAL_NOMINAL_MISSING, NOT UPDATED
        $reg = IdxCompanyRegistry::where('ticker', $ticker)->first();
        $this->assertEquals('PARTIAL_NOMINAL_MISSING', $reg->eod_refresh_status);
        $this->assertNotNull($reg->eod_next_retry_at);
        $this->assertEquals(1, $reg->eod_retry_attempts);

        // In database: adjusted exists, unadjusted missing
        $this->assertEquals(1, MarketObservation::where('ticker', $ticker)->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')->count());
        $this->assertEquals(0, MarketObservation::where('ticker', $ticker)->where('adjustment_mode', 'UNADJUSTED')->count());
    }

    public function test_missing_nominal_bar_refilled_on_fresh_path_is_reported_as_updated(): void
    {
        $ticker = 'REFILL';

        IdxCompanyRegistry::create([
            'ticker' => $ticker,
            'company_name' => 'Refill Fixture Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'snapshot_date' => '2026-11-02',
            'last_bar_date' => '2026-11-02',
        ]);

        $bar = [
            'date' => '2026-11-02', 'open' => 1000, 'high' => 1050, 'low' => 990,
            'close' => 1020, 'volume' => 1000000, 'source' => 'IDX Edge PRO',
            'board_scope' => 'RG', 'validation_status' => 'VALID',
        ];

        MarketObservation::create([
            'ticker' => $ticker,
            'bar_date' => '2026-11-02',
            'observation_time' => Carbon::parse('2026-11-02 16:00:00', 'Asia/Jakarta')->utc(),
            'interval' => '1d',
            'data_classification' => 'PUBLIC_MARKET_EOD',
            'source' => 'IDX Edge PRO',
            'adjustment_mode' => 'PROVIDER_BACK_ADJUSTED',
            'open_price' => 1000, 'high_price' => 1050, 'low_price' => 990,
            'close_price' => 1020, 'volume' => 1000000,
            'is_eod_final' => false,
            'finality_basis' => 'PROVISIONAL_POST_MARKET',
            'validation_status' => 'VALID',
            'board_scope' => 'RG',
        ]);

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockCalendar = Mockery::mock(BeiCalendarService::class);
        $mockMetrics = Mockery::mock(TechnicalMetricsCalculationService::class);
        $mockBroker = Mockery::mock(BrokerSummaryIngestionService::class);

        $mockClient->shouldReceive('history')->with($ticker, 20, false, null, null, true)
            ->once()
            ->andReturn(['success' => true, 'data' => ['bars' => [$bar]]]);

        $service = new DailyEodRefreshService($mockClient, $mockCalendar, $mockMetrics, $mockBroker);
        $result = $service->refreshDailyEod([$ticker], 20, false, false, '2026-11-02');

        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['already_fresh']);
        $this->assertSame('UPDATED', IdxCompanyRegistry::where('ticker', $ticker)->firstOrFail()->eod_refresh_status);
        $this->assertSame(1, MarketObservation::where('ticker', $ticker)->where('adjustment_mode', 'UNADJUSTED')->count());
    }

    public function test_regular_target_ignores_stale_recovery_queue_but_explicit_recovery_keeps_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 16:10:00', 'Asia/Jakarta'));
        EodSessionQueue::create([
            'session_date' => '2026-11-16',
            'status' => 'PENDING',
            'priority' => 10,
            'attempts' => 20,
        ]);

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockCalendar = Mockery::mock(BeiCalendarService::class);
        $mockMetrics = Mockery::mock(TechnicalMetricsCalculationService::class);
        $mockBroker = Mockery::mock(BrokerSummaryIngestionService::class);
        $mockCalendar->shouldReceive('isTradingDay')->withArgs(function ($date) {
            return $date->toDateString() === '2026-11-17';
        })->andReturn(true);
        $mockCalendar->shouldReceive('getCurrentTradingDay')->andReturn(Carbon::parse('2026-11-17'));
        $service = new DailyEodRefreshService($mockClient, $mockCalendar, $mockMetrics, $mockBroker);

        $this->assertSame('2026-11-17', $service->resolveTargetSessionDate());
        $this->assertSame('2026-11-16', $service->resolveTargetSessionDate('2026-11-16'));
        $this->assertSame('2026-11-16', EodSessionQueue::firstOrFail()->session_date->toDateString());

        Carbon::setTestNow();
    }

    public function test_resolve_expected_session_date_after_cutoff_late_in_hour_returns_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-13 17:00:00', 'Asia/Jakarta'));

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockCalendar = Mockery::mock(BeiCalendarService::class);
        $mockMetrics = Mockery::mock(TechnicalMetricsCalculationService::class);
        $mockBroker = Mockery::mock(BrokerSummaryIngestionService::class);

        $mockCalendar->shouldReceive('isTradingDay')->andReturn(true);
        $mockCalendar->shouldReceive('getCurrentTradingDay')->andReturn(Carbon::parse('2026-11-13'));

        $service = new DailyEodRefreshService($mockClient, $mockCalendar, $mockMetrics, $mockBroker);

        $this->assertSame('2026-11-13', $service->resolveExpectedSessionDate());

        Carbon::setTestNow();
    }

    public function test_superseded_prior_session_bar_is_promoted_to_final_while_latest_stays_provisional(): void
    {
        $ticker = 'FINAL';

        IdxCompanyRegistry::create([
            'ticker' => $ticker,
            'company_name' => 'Finality Fixture Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'snapshot_date' => '2026-11-01',
            'last_bar_date' => '2026-11-01',
        ]);

        $priorBar = [
            'date' => '2026-11-01', 'open' => 900, 'high' => 950, 'low' => 890,
            'close' => 940, 'volume' => 1000000, 'source' => 'IDX Edge PRO',
            'board_scope' => 'RG', 'validation_status' => 'VALID',
        ];
        $newBar = [
            'date' => '2026-11-02', 'open' => 940, 'high' => 980, 'low' => 930,
            'close' => 970, 'volume' => 1500000, 'source' => 'IDX Edge PRO',
            'board_scope' => 'RG', 'validation_status' => 'VALID',
        ];

        MarketObservation::create([
            'ticker' => $ticker,
            'bar_date' => '2026-11-01',
            'observation_time' => Carbon::parse('2026-11-01 16:00:00', 'Asia/Jakarta')->utc(),
            'interval' => '1d',
            'data_classification' => 'PUBLIC_MARKET_EOD',
            'source' => 'IDX Edge PRO',
            'adjustment_mode' => 'PROVIDER_BACK_ADJUSTED',
            'open_price' => 900, 'high_price' => 950, 'low_price' => 890,
            'close_price' => 940, 'volume' => 1000000,
            'is_eod_final' => false,
            'finality_basis' => 'PROVISIONAL_POST_MARKET',
            'validation_status' => 'VALID',
            'board_scope' => 'RG',
        ]);

        $mockClient = Mockery::mock(IdxEdgeClient::class);
        $mockCalendar = Mockery::mock(BeiCalendarService::class);
        $mockMetrics = Mockery::mock(TechnicalMetricsCalculationService::class);
        $mockBroker = Mockery::mock(BrokerSummaryIngestionService::class);

        $mockClient->shouldReceive('history')->with($ticker, 20, true, null, null, true)
            ->andReturn(['success' => true, 'data' => ['bars' => [$priorBar, $newBar]]]);
        $mockClient->shouldReceive('history')->with($ticker, 20, false, null, null, true)
            ->andReturn(['success' => true, 'data' => ['bars' => [$priorBar, $newBar]]]);
        $mockMetrics->shouldReceive('calculateForTicker')->with($ticker, '2026-11-02')->andReturn(null);

        $service = new DailyEodRefreshService($mockClient, $mockCalendar, $mockMetrics, $mockBroker);
        $service->refreshDailyEod([$ticker], 20, false, false, '2026-11-02');

        $prior = MarketObservation::where('ticker', $ticker)->whereDate('bar_date', '2026-11-01')
            ->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')->firstOrFail();
        $this->assertTrue($prior->is_eod_final);
        $this->assertSame('SUPERSEDED_BY_NEWER_SESSION', $prior->finality_basis);

        $latest = MarketObservation::where('ticker', $ticker)->whereDate('bar_date', '2026-11-02')
            ->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')->firstOrFail();
        $this->assertFalse($latest->is_eod_final);
        $this->assertSame('PROVISIONAL_POST_MARKET', $latest->finality_basis);
    }
}
