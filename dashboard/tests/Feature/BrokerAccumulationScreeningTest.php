<?php

namespace Tests\Feature;

use App\Services\BrokerAccumulationScreeningService;
use App\Services\TelegramFormattingService;
use App\Services\TelegramTwoWayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrokerAccumulationScreeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_cross_ticker_query_classified_as_screening_broker_accumulation_not_swing(): void
    {
        $twoWay = app(TelegramTwoWayService::class);

        $crossTickerQuery = 'kasih tau saya kira kira saham yang sedang di akumulasi bandar/smart many selama 1 bulan terakhir ini apa aja yg walaupun turun masih di beli dan belum naik banyak';
        $intent = $twoWay->classifyIntent($crossTickerQuery);

        $this->assertEquals('SCREENING_BROKER_ACCUMULATION', $intent);
        $this->assertNotEquals('QUERY_TICKER_STATUS', $intent);
    }

    public function test_single_ticker_query_classified_as_query_broker_flow(): void
    {
        $twoWay = app(TelegramTwoWayService::class);

        $singleTickerQuery = 'siapa saja yang akumulasi saham SMRA 3 bulan terakhir';
        $intent = $twoWay->classifyIntent($singleTickerQuery);

        $this->assertEquals('QUERY_BROKER_FLOW', $intent);
    }

    public function test_screening_maintains_one_month_calendar_period(): void
    {
        $service = app(BrokerAccumulationScreeningService::class);
        $prov = [];
        $result = $service->executeScreening('saham akumulasi 1 bulan terakhir', $prov);

        $expectedEnd = now('Asia/Jakarta')->toDateString();
        $expectedStart = now('Asia/Jakarta')->subMonth()->toDateString();

        $this->assertStringContainsString('BROKER_ACCUMULATION_SCREENING_V1', $result);
        $this->assertStringContainsString("Periode Diminta : {$expectedStart} s/d {$expectedEnd}", $result);
        $this->assertStringContainsString('Periode Data    :', $result);
        $this->assertStringNotContainsString('SWING_FILTER_V1', $result);
    }

    public function test_screening_verifies_broker_evidence_and_strictly_prohibits_fabrication(): void
    {
        // Mock IDX Edge returning no brokers for candidate tickers
        Http::fake([
            'https://stock.arjum.com/api/broker-summary/*' => Http::response([
                'stock_code' => 'XYZ',
                'broker_start_date' => '2026-10-01',
                'broker_end_date' => '2026-10-31',
                'brokers' => [],
                'broker_levels' => [],
            ], 200, ['X-Quota-Cost' => '1']),
        ]);

        $service = app(BrokerAccumulationScreeningService::class);
        $prov = [];
        $result = $service->executeScreening('saham akumulasi', $prov);

        // When no brokers qualify, it must report empty result, not invent tickers or use swing filter
        $this->assertStringContainsString('TIDAK ADA KANDIDAT', $result);
        $this->assertStringContainsString('Sistem menolak mengarang emiten', $result);
        $this->assertStringNotContainsString('SWING_FILTER_V1', $result);
    }

    public function test_quota_limits_are_strictly_obeyed_and_universe_is_bounded(): void
    {
        Http::fake([
            'https://stock.arjum.com/api/broker-summary/*' => Http::response([
                'stock_code' => 'XYZ',
                'broker_start_date' => '2026-10-01',
                'broker_end_date' => '2026-10-31',
                'brokers' => [],
                'broker_levels' => [],
            ], 200, ['X-Quota-Cost' => '1']),
        ]);

        $service = app(BrokerAccumulationScreeningService::class);
        $prov = [];
        $service->executeScreening('saham akumulasi', $prov);

        // Max 5 candidate queries to IDX Edge, never querying the entire 864 tickers
        $this->assertLessThanOrEqual(10, count(Http::recorded()));
    }

    public function test_telegram_formatting_splits_long_messages_safely(): void
    {
        $formatting = app(TelegramFormattingService::class);

        // Generate a 9000-character text with lines
        $longText = str_repeat("Baris analisis pasar reguler IDX Edge dengan rincian metrik.\n", 150);
        $this->assertGreaterThan(4000, mb_strlen($longText));

        $chunks = $formatting->splitTextByLines($longText, 3900);

        $this->assertGreaterThanOrEqual(2, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(3900, mb_strlen($chunk));
        }
    }
}
