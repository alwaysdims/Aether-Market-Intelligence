<?php

namespace Tests\Unit;

use App\Services\IdxEdgeCapabilityParser;
use PHPUnit\Framework\TestCase;

class IdxEdgeCapabilityParserTest extends TestCase
{
    private function fixture(): array
    {
        return json_decode(
            file_get_contents(__DIR__.'/../Fixtures/idx-edge-capabilities.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    public function test_insiders_validates_items_pagination_and_dates(): void
    {
        $result = (new IdxEdgeCapabilityParser)->parseInsiders($this->fixture()['insiders']);

        self::assertSame('BBCA', $result['ticker']);
        self::assertSame(2, $result['count']);
        self::assertSame(85, $result['total']);
        self::assertSame(1, $result['page']);
        self::assertSame(43, $result['total_pages']);
        self::assertSame('2026-03-25', $result['items'][0]['date']);
        self::assertSame('NOT_DOCUMENTED', $result['items'][0]['unit']);
    }

    public function test_screener_validates_rows_and_parses_provider_date(): void
    {
        $result = (new IdxEdgeCapabilityParser)->parseScreener($this->fixture()['screener']);

        self::assertSame('2026-11-16', $result['as_of_date']);
        self::assertSame(2, $result['count']);
        self::assertSame('AADI', $result['items'][0]['ticker']);
        self::assertSame('PROVIDER_SUPPLIED', $result['data_classification']);
    }

    public function test_movers_validates_units_and_market_state(): void
    {
        $result = (new IdxEdgeCapabilityParser)->parseMovers($this->fixture()['movers']);

        self::assertSame('chg', $result['sort']);
        self::assertSame(114645, $result['gainers'][0]['lot']);
        self::assertSame('PROVIDER_LOT_FIELD_UNCONVERTED', $result['volume_unit']);
        self::assertSame('open', $result['market']['status']);
    }

    public function test_screener_accepts_provider_dates_outside_november(): void
    {
        $payload = $this->fixture()['screener'];
        $payload['date'] = '7 Januari 2027';

        $result = (new IdxEdgeCapabilityParser)->parseScreener($payload);

        self::assertSame('2027-01-07', $result['as_of_date']);
    }

    public function test_price_ihsg_validates_snapshot_and_preserves_nullable_timestamp(): void
    {
        $result = (new IdxEdgeCapabilityParser)->parsePriceIhsg([
            'code' => 'IHSG',
            'source' => 'IDX',
            'last_price' => 8123.45,
            'change' => -12.3,
            'change_pct' => -0.15,
            'source_date' => '2026-11-17',
            'data_ts' => null,
            'served_ts' => '2026-11-18T01:04:03+07:00',
            'market_state' => 'closed',
            'market_label' => 'Tutup',
            'quote_age_ms' => null,
        ]);

        self::assertSame('INDEX', $result['board_scope']);
        self::assertSame('2026-11-17', $result['as_of_date']);
        self::assertNull($result['data_timestamp']);
        self::assertSame('PROVIDER_TIMESTAMP_UNAVAILABLE', $result['freshness_status']);
    }

    public function test_chart_validates_positional_bars_without_inventing_board_or_unit(): void
    {
        $result = (new IdxEdgeCapabilityParser)->parseChart([
            'code' => 'BMRI',
            'interval' => 300,
            'native_interval' => 300,
            'date' => '2026-11-17',
            'count' => 1,
            'source' => 'IDX',
            'bars' => [[1763361000, 5100, 5125, 5075, 5110, 120000]],
            'market' => ['status' => 'closed', 'label' => 'Tutup', 'next_open' => '2026-11-18T09:00:00+07:00'],
        ]);

        self::assertSame('BMRI', $result['ticker']);
        self::assertSame(300, $result['interval_seconds']);
        self::assertSame(5110.0, $result['bars'][0]['close']);
        self::assertSame('UNVERIFIED', $result['board_scope']);
        self::assertSame('NOT_DOCUMENTED', $result['volume_unit']);
    }

    public function test_financial_statement_validates_period_count_and_items(): void
    {
        $result = (new IdxEdgeCapabilityParser)->parseFinancialStatements([
            'stock_code' => 'BMRI',
            'report_type' => 'INCOME_STATEMENT',
            'period' => 'quarterly',
            'count' => 1,
            'items' => [[
                'year' => '2026',
                'quarter' => 'Q3',
                'label' => 'Q3 2026',
                'fetched_at' => '2026-11-01T10:00:00+07:00',
                'data' => ['laba_rugi' => 1000],
            ]],
        ]);

        self::assertSame('quarterly', $result['period']);
        self::assertSame(1, $result['count']);
        self::assertSame('PROVIDER_SUPPLIED', $result['data_classification']);
        self::assertSame('NOT_DOCUMENTED', $result['currency']);
    }

    public function test_broker_accumulation_validates_series_and_top_lists(): void
    {
        $result = (new IdxEdgeCapabilityParser)->parseBrokerAccumulation([
            'code' => 'BBCA',
            'start_date' => '2026-11-03',
            'end_date' => '2026-11-17',
            'series' => [[
                'broker_code' => 'YP',
                'broker_name' => 'Broker YP',
                'points' => [[
                    'date' => '2026-11-17',
                    'nval' => 1000000,
                    'nvol' => 100,
                    'cum_nval' => 5000000,
                    'bavg' => null,
                    'savg' => 9950,
                ]],
            ]],
            'top_buyers' => [['broker_code' => 'YP', 'broker_name' => 'Broker YP', 'total_nval' => 5000000]],
            'top_sellers' => [['broker_code' => 'ZP', 'broker_name' => 'Broker ZP', 'total_nval' => -4000000]],
        ]);

        self::assertSame('2026-11-03', $result['period_start']);
        self::assertSame(5000000.0, $result['series'][0]['points'][0]['cumulative_net_value']);
        self::assertNull($result['series'][0]['points'][0]['buy_average']);
        self::assertSame('IDR', $result['value_currency']);
        self::assertSame('UNVERIFIED', $result['board_scope']);
    }

    public function test_financial_statement_accepts_keyed_reports_without_inventing_missing_values(): void
    {
        $result = (new IdxEdgeCapabilityParser)->parseFinancialStatements([
            'stock_code' => 'BRIS',
            'report_type' => 'INCOME_STATEMENT',
            'period' => 'quarterly',
            'reports' => [
                'Q2 2026' => ['laba_rugi' => 1955610000000],
                'Q1 2026' => ['laba_rugi' => 2200099000000],
            ],
        ]);

        self::assertSame(2, $result['count']);
        self::assertSame('Q2 2026', $result['items'][0]['period']);
        self::assertSame(1955610000000, $result['items'][0]['data']['laba_rugi']);
        self::assertArrayNotHasKey('aset', $result['items'][0]['data']);
    }

    public function test_schema_drift_and_invalid_provider_error_fail_closed(): void
    {
        $parser = new IdxEdgeCapabilityParser;

        $this->expectException(\UnexpectedValueException::class);
        $parser->parseMovers(['error' => ['message' => 'provider unavailable']]);
    }
}
