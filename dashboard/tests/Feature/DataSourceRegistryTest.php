<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\MarketObservation;
use App\Services\CanonicalMarketReadService;
use App\Services\DataSourceRegistryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DataSourceRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_sync_is_idempotent_and_creates_no_duplicates(): void
    {
        $service = app(DataSourceRegistryService::class);

        $res1 = $service->syncRegistry();
        $count1 = DataSource::count();

        $res2 = $service->syncRegistry();
        $count2 = DataSource::count();

        $this->assertSame($count1, $count2);
        $this->assertSame(0, $res2['created']);
        $this->assertSame(7, $count1);
    }

    public function test_registry_contains_proper_status_separation(): void
    {
        app(DataSourceRegistryService::class)->syncRegistry();

        $history = DataSource::where('name', 'IDX Edge Histori EOD Reguler')->first();
        $this->assertNotNull($history);
        $this->assertSame('active', $history->status);
        $this->assertTrue($history->is_enabled);
        $this->assertSame('VALID', $history->data_quality);
        $this->assertSame('/api/history/{code}', $history->endpoint);

        $quote = DataSource::where('name', 'IDX Edge Quote Intraday')->first();
        $this->assertNotNull($quote);
        $this->assertSame('quarantined', $quote->status);
        $this->assertFalse($quote->is_enabled);
        $this->assertSame('QUARANTINED', $quote->data_quality);
        $this->assertNotNull($quote->quarantine_reason);

        $broker = DataSource::where('name', 'IDX Edge Broker Summary')->first();
        $this->assertNotNull($broker);
        $this->assertSame('active', $broker->status);
        $this->assertTrue($broker->is_enabled);
        $this->assertSame('VERIFIED_ON_SAMPLE', $broker->data_quality);

        $fundamental = DataSource::where('name', 'IDX Edge Fundamental')->first();
        $this->assertNotNull($fundamental);
        $this->assertSame('active', $fundamental->status);
        $this->assertTrue($fundamental->is_enabled);
        $this->assertSame('VERIFIED_ON_SAMPLE', $fundamental->data_quality);

        $yahoo = DataSource::where('name', 'Yahoo Finance Daily EOD')->first();
        $this->assertNotNull($yahoo);
        $this->assertSame('active', $yahoo->status);
        $this->assertTrue($yahoo->is_enabled);
        $this->assertSame('VALID', $yahoo->data_quality);

        $indexAlpha = DataSource::where('name', 'Index Alpha Broker Summary')->first();
        $this->assertNotNull($indexAlpha);
        $this->assertSame('inactive', $indexAlpha->status);
        $this->assertFalse($indexAlpha->is_enabled);
        $this->assertSame('DEPRECATED', $indexAlpha->data_quality);
    }

    public function test_registry_never_stores_api_key_or_secrets(): void
    {
        app(DataSourceRegistryService::class)->syncRegistry();

        foreach (DataSource::all() as $ds) {
            $json = json_encode($ds->toArray());
            $this->assertStringNotContainsString('sk_', $json);
            $this->assertStringNotContainsString('api_key', strtolower($json));
        }
    }

    public function test_conflicted_observation_is_strictly_excluded_from_canonical_routing(): void
    {
        // 1. Valid fallback observation from Yahoo
        $yahoo = MarketObservation::create([
            'ticker' => 'BBCA',
            'observation_time' => Carbon::parse('2026-10-30 10:00:00', 'Asia/Jakarta')->utc(),
            'bar_date' => '2026-10-30',
            'open_price' => 4900,
            'high_price' => 5025,
            'low_price' => 4900,
            'close_price' => 4980,
            'volume' => 100000,
            'source' => 'Yahoo Finance (via yfinance)',
            'interval' => '1d',
            'data_classification' => 'PUBLIC_MARKET_EOD',
            'is_eod_final' => true,
            'validation_status' => 'VALID',
        ]);

        // 2. Newer observation marked CONFLICTED (e.g. negotiated crossing anomaly)
        MarketObservation::create([
            'ticker' => 'BBCA',
            'observation_time' => Carbon::parse('2026-10-30 15:51:02', 'Asia/Jakarta')->utc(),
            'bar_date' => '2026-10-30',
            'open_price' => 4900,
            'high_price' => 5025,
            'low_price' => 4900,
            'close_price' => 6150,
            'volume' => 4700,
            'source' => 'IDX Edge PRO',
            'interval' => '1d',
            'data_classification' => 'PUBLIC_MARKET_EOD',
            'is_eod_final' => false,
            'validation_status' => 'CONFLICTED',
            'conflict_reason' => 'Terkontaminasi Pasar Negosiasi (NG)',
        ]);

        $reader = app(CanonicalMarketReadService::class);
        $selected = $reader->latest('BBCA');

        // Conflicted must be strictly excluded; valid Yahoo fallback must be returned
        $this->assertNotNull($selected);
        $this->assertSame($yahoo->id, $selected->id);
        $this->assertSame('Yahoo Finance (via yfinance)', $selected->source);
        $this->assertSame(4980.0, (float) $selected->close_price);
    }

    public function test_settings_page_renders_registered_sources_with_quarantine_reasons(): void
    {
        app(DataSourceRegistryService::class)->syncRegistry();

        $response = $this->get('/settings');
        $response->assertStatus(200);
        $response->assertSee('IDX Edge Histori EOD Reguler');
        $response->assertSee('IDX Edge Quote Intraday');
        $response->assertSee('DIKARANTINA');
        $response->assertSee('Pasar Negosiasi (NG)');
    }

    public function test_effective_status_correctly_blocks_unauthorized_or_quarantined_endpoints(): void
    {
        $registry = app(DataSourceRegistryService::class);
        $registry->syncRegistry();

        // 1. Quarantined quote endpoint
        $quoteSource = DataSource::where('name', 'IDX Edge Quote Intraday')->firstOrFail();
        $quoteStatus = $registry->getEffectiveStatus($quoteSource);
        $this->assertFalse($quoteStatus['effective_enabled']);
        $this->assertSame('QUARANTINED', $quoteStatus['badge']);

        // 2. Verified broker endpoint is enabled on sample
        $brokerSource = DataSource::where('name', 'IDX Edge Broker Summary')->firstOrFail();
        $brokerStatus = $registry->getEffectiveStatus($brokerSource);
        $this->assertTrue($brokerStatus['effective_enabled']);
        $this->assertSame('READY_CONTROLLED', $brokerStatus['badge']);

        // 3. When API key is missing
        config()->set('services.idx_edge.api_key', '');
        $historySource = DataSource::where('name', 'IDX Edge Histori EOD Reguler')->firstOrFail();
        $noKeyStatus = $registry->getEffectiveStatus($historySource);
        $this->assertFalse($noKeyStatus['effective_enabled']);
        $this->assertSame('BLOCKED_NO_KEY', $noKeyStatus['badge']);
    }

    public function test_controlled_sync_history_updates_timestamps_and_persists_observation(): void
    {
        config()->set('services.idx_edge.api_key', 'test-key');
        config()->set('services.idx_edge.enabled', true);
        config()->set('services.idx_edge.endpoints.history', true);

        Http::fake([
            'https://stock.arjum.com/api/history/TLKM*' => Http::response([
                'stock_code' => 'TLKM',
                'frame' => 'daily',
                'rows' => [
                    [
                        'date' => '2026-10-30',
                        'open' => 1950,
                        'high' => 2000,
                        'low' => 1940,
                        'close' => 1980,
                        'volume' => 85000000,
                    ],
                ],
            ], 200, ['X-Quota-Cost' => '1']),
        ]);

        $registry = app(DataSourceRegistryService::class);
        $registry->syncRegistry();

        $result = $registry->syncControlledEodHistory('TLKM', 1);

        $this->assertTrue($result['success']);
        $this->assertSame('TLKM', $result['ticker']);
        $this->assertSame(1980.0, (float) $result['latest_close']);

        $source = DataSource::where('name', 'IDX Edge Histori EOD Reguler')->firstOrFail();
        $this->assertNotNull($source->last_attempt_at);
        $this->assertNotNull($source->last_success_at);
        $this->assertSame('healthy', $source->health);
    }

    public function test_strict_allowlist_excludes_not_verified_and_quarantined_observations(): void
    {
        // 1. Observation with validation_status = 'NOT_VERIFIED'
        MarketObservation::create([
            'ticker' => 'BBRI',
            'observation_time' => Carbon::parse('2026-10-30 11:00:00', 'Asia/Jakarta')->utc(),
            'bar_date' => '2026-10-30',
            'open_price' => 4500,
            'high_price' => 4600,
            'low_price' => 4450,
            'close_price' => 4550,
            'volume' => 50000,
            'source' => 'Provisional Provider',
            'interval' => '1d',
            'data_classification' => 'PUBLIC_MARKET_EOD',
            'validation_status' => 'NOT_VERIFIED',
        ]);

        $reader = app(CanonicalMarketReadService::class);
        $selected = $reader->latest('BBRI');

        // Allowlist strictly requires 'VALID'; NOT_VERIFIED must be excluded
        $this->assertNull($selected);
    }
}
