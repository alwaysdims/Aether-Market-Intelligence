<?php

namespace Tests\Feature;

use App\Models\MarketObservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_quote_endpoint_returns_canonical_snapshot_without_provider_call(): void
    {
        MarketObservation::create([
            'ticker' => 'BBCA',
            'observation_time' => now('UTC'),
            'bar_date' => now('Asia/Jakarta')->toDateString(),
            'open_price' => 4800,
            'high_price' => 4920,
            'low_price' => 4780,
            'close_price' => 4890,
            'volume' => 1000,
            'source' => 'IDX Edge PRO',
            'interval' => '1d',
            'data_classification' => 'PUBLIC_MARKET_EOD',
            'is_eod_final' => false,
            'validation_status' => 'VALID',
            'available_at' => now('UTC'),
        ]);

        $response = $this->getJson('/api/market/BBCA/snapshot');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-cache, private')
            ->assertJsonPath('data.ticker', 'BBCA')
            ->assertJsonPath('data.source', 'IDX Edge PRO')
            ->assertJsonPath('data.connection_status', 'STORED_SNAPSHOT')
            ->assertJsonPath('data.delivery_mode', 'INTERNAL_POLLING')
            ->assertJsonPath('data.is_final', false);
    }

    public function test_internal_quote_endpoint_rejects_invalid_ticker(): void
    {
        $this->getJson('/api/market/INVALID1/snapshot')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_TICKER');
    }
}
