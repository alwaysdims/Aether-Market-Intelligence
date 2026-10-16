<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\PromptTemplate;
use App\Models\DataSource;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PromptTemplate::create([
            'code' => 'MARKET_INTELLIGENCE_DAILY',
            'version' => '1.0.0',
            'model_id' => 'ag/gemini-3.8-flash-high',
            'system_prompt' => 'Hermes system prompt',
            'user_prompt_template' => 'Hermes user prompt',
            'is_active' => true,
        ]);

        DataSource::create([
            'name' => 'Yahoo Finance Daily EOD',
            'type' => 'market_data',
            'data_classification' => 'end_of_day',
            'status' => 'active',
        ]);
    }
    /**
     * Test overview page renders successfully.
     */
    public function test_overview_page_renders_successfully(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('Aether Market Intelligence');
        $response->assertSee('PostgreSQL DB');
    }

    /**
     * Test market page renders.
     */
    public function test_market_page_renders(): void
    {
        $response = $this->get('/market');
        $response->assertStatus(200);
        $response->assertSee('Market Monitor');
    }

    /**
     * Test news page renders.
     */
    public function test_news_page_renders(): void
    {
        $response = $this->get('/news');
        $response->assertStatus(200);
        $response->assertSee('Peristiwa Pasar', false);
    }

    /**
     * Test history page renders.
     */
    public function test_history_page_renders(): void
    {
        $response = $this->get('/history');
        $response->assertStatus(200);
        $response->assertSee('Histori Analisis AI');
    }

    /**
     * Test health check page and API.
     */
    public function test_health_check_page_and_api(): void
    {
        $response = $this->get('/health');
        $response->assertStatus(200);
        $response->assertSee('System Health');

        $apiResp = $this->getJson('/api/health');
        $apiResp->assertStatus(200);
        $apiResp->assertJsonStructure([
            'database' => ['component', 'status', 'latency_ms'],
            'ai_model' => ['component', 'status', 'latency_ms'],
            'hermes_agent' => ['component', 'status', 'latency_ms'],
        ]);
        $this->assertEquals('healthy', $apiResp->json('database.status'));
    }

    /**
     * Test settings page shows prompt template and data sources.
     */
    public function test_settings_page_shows_config(): void
    {
        $response = $this->get('/settings');
        $response->assertStatus(200);
        $response->assertSee('ag/gemini-3.8-flash-high');
        $response->assertSee('MARKET_INTELLIGENCE_DAILY');
    }

    /**
     * Test Hermes adapter service ping.
     */
    public function test_hermes_adapter_service_ping(): void
    {
        $adapter = app(\App\Services\HermesAdapterService::class);
        $res = $adapter->ping();
        $this->assertTrue($res['ok']);
        $this->assertEquals('ping', $res['action']);
        $this->assertTrue($res['data']['hermes_root_accessible']);
    }

    /**
     * Test Hermes EOD sync artisan command.
     */
    public function test_hermes_sync_eod_artisan_command(): void
    {
        $this->artisan('hermes:sync-eod', ['--trigger' => 'test'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('analysis_runs', [
            'status' => 'completed',
            'trigger_type' => 'test',
        ]);
        $this->assertGreaterThan(0, \App\Models\MarketSnapshot::count());
        $this->assertGreaterThan(0, \App\Models\NewsItem::count());
    }

    /**
     * Test market page displays real snapshot data.
     */
    public function test_market_page_displays_real_snapshots(): void
    {
        \App\Models\MarketSnapshot::create([
            'ticker' => 'BBCA',
            'bar_date' => now()->toDateString(),
            'source_timestamp' => now(),
            'close_price' => 6125.00,
            'open_price' => 6000.00,
            'high_price' => 6125.00,
            'low_price' => 5950.00,
            'volume' => 136303100,
            'data_classification' => 'end_of_day',
            'indicators' => ['atr14' => 127.20, 'rvol20' => 1.16],
        ]);

        $response = $this->get('/market');
        $response->assertStatus(200);
        $response->assertSee('BBCA');
        $response->assertSee('6.125');
    }

    /**
     * Test news page displays real news items.
     */
    public function test_news_page_displays_real_news(): void
    {
        \App\Models\NewsItem::create([
            'title' => 'Pertumbuhan Laba Bersih Perbankan BEI Kuartal III',
            'url' => 'https://example.com/berita-uji-1',
            'source_name' => 'Keterbukaan Informasi IDX',
            'published_at' => now(),
            'fetched_at' => now(),
            'summary' => 'Ringkasan performa emiten perbankan kuartal III 2026.',
            'verification_status' => 'official_statement',
            'related_tickers' => ['BBCA', 'BBRI'],
        ]);

        $response = $this->get('/news');
        $response->assertStatus(200);
        $response->assertSee('Pertumbuhan Laba Bersih Perbankan BEI');
        $response->assertSee('OFFICIAL_STATEMENT');
    }
}
