<?php

namespace Tests\Feature;

use App\Services\IdxEdgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class IdxEdgeClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::clear();
        config()->set('services.idx_edge', [
            'enabled' => true,
            'base_url' => 'https://stock.arjum.com',
            'api_key' => 'test-key-not-secret',
            'timeout' => 10,
            'daily_budget' => 10000,
            'reserve' => 0,
            'requests_per_minute' => 10,
            'cache_ttl_seconds' => 30,
            'endpoints' => [
                'history' => true,
                'price' => true,
                'broker' => false,
                'fundamental' => false,
                'chart' => true,
                'chart_ihsg' => true,
            ],
        ]);
    }

    public function test_disabled_client_fails_closed_without_network_request(): void
    {
        config()->set('services.idx_edge.enabled', false);
        config()->set('services.idx_edge.endpoints.price', false);
        Http::preventStrayRequests();

        $result = app(IdxEdgeClient::class)->price('BBCA');

        $this->assertFalse($result['success']);
        $this->assertSame('PROVIDER_DISABLED', $result['error_type']);
    }

    public function test_price_endpoint_fails_when_quarantined_even_with_provider_enabled(): void
    {
        config()->set('services.idx_edge.enabled', true);
        config()->set('services.idx_edge.endpoints.price', false);
        Http::preventStrayRequests();

        $result = app(IdxEdgeClient::class)->price('BBCA');

        $this->assertFalse($result['success']);
        $this->assertSame('ENDPOINT_QUARANTINED', $result['error_type']);
    }

    public function test_price_request_uses_server_side_auth_and_records_quota_headers(): void
    {
        Http::fake([
            'https://stock.arjum.com/api/price/BBCA' => Http::response([
                'code' => 'BBCA',
                'last_price' => 4890,
                'source_date' => '2026-10-30',
            ], 200, [
                'X-Quota-Cost' => '1',
                'X-RateLimit-Remaining' => '999',
            ]),
        ]);

        $result = app(IdxEdgeClient::class)->price('bbca');

        $this->assertTrue($result['success']);
        $this->assertSame('BBCA', $result['data']['code']);
        $this->assertSame(4890.0, $result['data']['last_price']);
        $this->assertSame(1, $result['quota']['cost']);
        $this->assertSame(999, $result['quota']['remaining']);
        $this->assertGreaterThanOrEqual(0, $result['latency_ms']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('X-API-Key', 'test-key-not-secret')
            && ! str_contains($request->url(), 'test-key-not-secret'));
    }

    public function test_identical_request_uses_shared_cache_without_second_provider_call(): void
    {
        Http::fake([
            'https://stock.arjum.com/api/price/BBCA' => Http::response([
                'code' => 'BBCA',
                'last_price' => 4890,
                'source_date' => '2026-10-30',
            ], 200, ['X-Quota-Cost' => '1']),
        ]);

        $client = app(IdxEdgeClient::class);
        $first = $client->price('BBCA');
        $second = $client->price('BBCA');

        $this->assertFalse($first['cache_hit']);
        $this->assertTrue($second['cache_hit']);
        Http::assertSentCount(1);
    }

    public function test_daily_budget_reserve_blocks_request_before_network_call(): void
    {
        config()->set('services.idx_edge.daily_budget', 2);
        config()->set('services.idx_edge.reserve', 1);
        Cache::put('idx-edge:quota:used:'.now('Asia/Jakarta')->toDateString(), 1, now()->addDay());
        Http::preventStrayRequests();

        $result = app(IdxEdgeClient::class)->price('ABCD');

        $this->assertFalse($result['success']);
        $this->assertSame('DAILY_BUDGET_RESERVED', $result['error_type']);
    }

    public function test_provider_error_is_redacted_and_retry_after_is_preserved(): void
    {
        Http::fake([
            'https://stock.arjum.com/api/price/BBCA' => Http::response([
                'message' => 'invalid X-API-Key test-key-not-secret',
            ], 429, ['Retry-After' => '30']),
        ]);

        $result = app(IdxEdgeClient::class)->price('BBCA');

        $this->assertFalse($result['success']);
        $this->assertSame('PROVIDER_429_RATE_LIMIT', $result['error_type']);
        $this->assertSame(30, $result['retry_after_seconds']);
        $this->assertStringNotContainsString('test-key-not-secret', $result['message']);
    }

    public function test_capability_probe_covers_all_documented_rest_endpoints_with_exact_queries(): void
    {
        config()->set('services.idx_edge.daily_budget', 6000);
        Http::fake([
            'https://stock.arjum.com/api/price/IHSG' => Http::response(['code' => 'IHSG'], 200),
            'https://stock.arjum.com/api/chart/BMRI?interval=300' => Http::response(['rows' => []], 200),
            'https://stock.arjum.com/api/screener/latest' => Http::response(['rows' => []], 200),
            'https://stock.arjum.com/api/movers?by=chg&n=10' => Http::response(['gainers' => [], 'losers' => []], 200),
            'https://stock.arjum.com/api/financial-statements/BMRI?report_type=INCOME_STATEMENT&period=quarterly&limit=12' => Http::response(['items' => []], 200),
            'https://stock.arjum.com/api/insiders/BBCA' => Http::response(['items' => []], 200),
            'https://stock.arjum.com/api/broker-accumulation/BBCA' => Http::response(['series' => []], 200),
        ]);

        $client = app(IdxEdgeClient::class);
        foreach (['price_ihsg', 'chart', 'screener', 'movers', 'financial_statements', 'insiders', 'broker_accumulation'] as $endpoint) {
            $result = $client->probeCapabilityEndpoint($endpoint, in_array($endpoint, ['insiders', 'broker_accumulation'], true) ? 'BBCA' : 'BMRI');
            $this->assertTrue($result['success'], $endpoint);
        }

        Http::assertSentCount(7);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://stock.arjum.com/api/movers?by=chg&n=10');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://stock.arjum.com/api/chart/BMRI?interval=300');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://stock.arjum.com/api/financial-statements/BMRI?report_type=INCOME_STATEMENT&period=quarterly&limit=12');
    }

    public function test_index_price_uses_dedicated_capability_without_enabling_equity_quote(): void
    {
        config()->set('services.idx_edge.endpoints.price', false);
        config()->set('services.idx_edge.endpoints.price_ihsg', true);
        Http::fake([
            'https://stock.arjum.com/api/price/IHSG' => Http::response([
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
            ], 200),
        ]);

        $client = app(IdxEdgeClient::class);
        $index = $client->priceIhsg();
        $equity = $client->price('BBCA');

        $this->assertTrue($index['success']);
        $this->assertSame('INDEX', $index['data']['board_scope']);
        $this->assertSame('PROVIDER_TIMESTAMP_UNAVAILABLE', $index['data']['freshness_status']);
        $this->assertFalse($equity['success']);
        $this->assertSame('ENDPOINT_QUARANTINED', $equity['error_type']);
        Http::assertSentCount(1);
    }

    public function test_movers_sends_requested_sort_and_limit(): void
    {
        config()->set('services.idx_edge.endpoints.movers', true);
        Http::fake([
            'https://stock.arjum.com/api/movers?by=chg&n=10' => Http::response([
                'by' => 'chg',
                'gainers' => [],
                'losers' => [],
                'count' => 0,
                'market' => ['status' => 'closed', 'label' => 'Tutup', 'next_open' => '2026-11-18T09:00:00+07:00'],
            ], 200),
        ]);

        $result = app(IdxEdgeClient::class)->movers('chg', 10);

        $this->assertTrue($result['success'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame('chg', $result['data']['sort']);
        $this->assertSame('closed', $result['data']['market']['status']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://stock.arjum.com/api/movers?by=chg&n=10');
    }

    public function test_broker_accumulation_rejects_missing_flow_values_instead_of_zero_filling(): void
    {
        config()->set('services.idx_edge.endpoints.broker_accumulation', true);
        Http::fake([
            'https://stock.arjum.com/api/broker-accumulation/BBCA*' => Http::response([
                'code' => 'BBCA',
                'start_date' => '2026-11-17',
                'end_date' => '2026-11-17',
                'series' => [[
                    'broker_code' => 'YP',
                    'points' => [[
                        'date' => '2026-11-17',
                        'nvol' => 100,
                        'cum_nval' => 1000000,
                        'bavg' => null,
                        'savg' => null,
                    ]],
                ]],
                'top_buyers' => [],
                'top_sellers' => [],
            ], 200),
        ]);

        $result = app(IdxEdgeClient::class)->brokerAccumulation('BBCA');

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_PROVIDER_SCHEMA', $result['error_type']);
    }

    public function test_chart_uses_sixty_second_interval_and_limit_for_equity(): void
    {
        Http::fake([
            'https://stock.arjum.com/api/chart/BBCA?interval=60&limit=2000' => Http::response([
                'code' => 'BBCA', 'interval' => 60, 'date' => '2026-11-18', 'count' => 1,
                'bars' => [[1774052100, 9000, 9010, 8990, 9005, 100]],
                'market' => ['status' => 'closed'],
            ], 200, ['X-Quota-Cost' => '1']),
        ]);

        $result = app(IdxEdgeClient::class)->chart('BBCA', 60, 2000);

        $this->assertTrue($result['success']);
        $this->assertSame(60, $result['data']['interval_seconds']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://stock.arjum.com/api/chart/BBCA?interval=60&limit=2000');
    }

    public function test_chart_uses_sixty_second_interval_for_ihsg(): void
    {
        Http::fake([
            'https://stock.arjum.com/api/chart/IHSG?interval=60&limit=2000' => Http::response([
                'code' => 'IHSG', 'interval' => 60, 'date' => '2026-11-18', 'count' => 1,
                'bars' => [[1774052100, 5000, 5010, 4990, 5005, 100]],
                'market' => ['status' => 'closed'],
            ], 200, ['X-Quota-Cost' => '1']),
        ]);

        $result = app(IdxEdgeClient::class)->chart('IHSG', 60, 2000);

        $this->assertTrue($result['success']);
        $this->assertSame('IHSG', $result['data']['ticker']);
    }

    public function test_history_preserves_explicit_calendar_range_and_limit(): void
    {
        Http::fake([
            'https://stock.arjum.com/api/history/BMRI*' => Http::response([
                'stock_code' => 'BMRI', 'frame' => 'daily', 'data_available' => true,
                'rows' => [[
                    'date' => '2026-10-30', 'open' => 4800, 'high' => 4920,
                    'low' => 4780, 'close' => 4890, 'volume' => 1000000,
                ]],
            ], 200, ['X-Quota-Cost' => '1']),
        ]);

        $result = app(IdxEdgeClient::class)->history('BMRI', 500, true, '2026-10-01', '2026-10-31');

        $this->assertTrue($result['success']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'limit=500')
            && str_contains($request->url(), 'start_date=2026-10-01')
            && str_contains($request->url(), 'end_date=2026-10-31'));
    }

    public function test_history_rejects_silent_range_shortfall(): void
    {
        Http::fake([
            'https://stock.arjum.com/api/history/DEWA*' => Http::response([
                'stock_code' => 'DEWA', 'frame' => 'daily', 'data_available' => false, 'rows' => [],
            ], 200, ['X-Quota-Cost' => '1']),
        ]);

        $result = app(IdxEdgeClient::class)->history('DEWA', 500, true, '2025-01-01', '2026-01-01');

        $this->assertFalse($result['success']);
        $this->assertSame('PROVIDER_EMPTY', $result['error_type']);
    }

    public function test_history_normalizes_valid_daily_ohlcv_and_rejects_invalid_bar(): void
    {
        Http::fakeSequence()
            ->push([
                'stock_code' => 'BBCA',
                'frame' => 'daily',
                'rows' => [[
                    'date' => '2026-10-29',
                    'open' => 4800,
                    'high' => 4920,
                    'low' => 4780,
                    'close' => 4890,
                    'volume' => 1000000,
                    'value' => 4890000000,
                ]],
            ], 200, ['X-Quota-Cost' => '1'])
            ->push([
                'stock_code' => 'BBCA',
                'frame' => 'daily',
                'rows' => [[
                    'date' => '2026-10-30',
                    'open' => 4800,
                    'high' => 4700,
                    'low' => 4780,
                    'close' => 4890,
                    'volume' => 1000000,
                ]],
            ]);

        $client = app(IdxEdgeClient::class);
        $valid = $client->history('BBCA', 20, false);
        Cache::clear();
        RateLimiter::clear('idx-edge:provider-rpm');
        $invalid = $client->history('BBCA', 20, true);

        $this->assertTrue($valid['success']);
        $this->assertSame('UNADJUSTED', $valid['data']['adjustment_basis']);
        $this->assertSame(4890.0, $valid['data']['bars'][0]['close']);
        $this->assertTrue($invalid['success']);
        $this->assertTrue($invalid['data']['has_invalid_bars']);
        $this->assertSame(1, $invalid['data']['invalid_bars_count']);
        $this->assertSame('OHLC_INVALID', $invalid['data']['bars'][0]['validation_status']);
    }
}
