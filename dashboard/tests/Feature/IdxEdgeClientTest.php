<?php

namespace Tests\Feature;

use App\Services\IdxEdgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
            'daily_budget' => 800,
            'reserve' => 200,
            'requests_per_minute' => 10,
            'cache_ttl_seconds' => 30,
            'endpoints' => [
                'history' => true,
                'price' => true,
                'broker' => false,
                'fundamental' => false,
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

        $result = app(IdxEdgeClient::class)->price('BBCA');

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
        $this->assertSame('PROVIDER_HTTP_429', $result['error_type']);
        $this->assertSame(30, $result['retry_after_seconds']);
        $this->assertStringNotContainsString('test-key-not-secret', $result['message']);
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

        $valid = app(IdxEdgeClient::class)->history('BBCA', 20, false);
        Cache::clear();
        $invalid = app(IdxEdgeClient::class)->history('BBCA', 20, false);

        $this->assertTrue($valid['success']);
        $this->assertSame('UNADJUSTED', $valid['data']['adjustment_basis']);
        $this->assertSame(4890.0, $valid['data']['bars'][0]['close']);
        $this->assertFalse($invalid['success']);
        $this->assertSame('INVALID_PROVIDER_SCHEMA', $invalid['error_type']);
    }
}
