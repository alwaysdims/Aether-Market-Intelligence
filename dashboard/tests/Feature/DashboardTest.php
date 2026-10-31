<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\IdxCompanyRegistry;
use App\Models\MarketObservation;
use App\Models\MarketSnapshot;
use App\Models\NewsItem;
use App\Models\PromptTemplate;
use App\Services\HermesAdapterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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
        $adapter = app(HermesAdapterService::class);
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
        $this->assertGreaterThan(0, MarketSnapshot::count());
        $this->assertGreaterThan(0, NewsItem::count());
    }

    /**
     * Test market page displays real snapshot data.
     */
    public function test_market_page_displays_real_snapshots(): void
    {
        MarketObservation::create([
            'ticker' => 'BBCA',
            'bar_date' => now()->toDateString(),
            'observation_time' => now(),
            'close_price' => 6125.00,
            'open_price' => 6000.00,
            'high_price' => 6125.00,
            'low_price' => 5950.00,
            'volume' => 136303100,
            'source' => 'Yahoo Finance',
            'is_eod_final' => true,
            'validation_status' => 'VALID',
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
        NewsItem::create([
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

    /**
     * Test ticker page renders with complete and unknown ticker data.
     */
    public function test_ticker_page_renders_with_complete_and_unknown_ticker(): void
    {
        // 1. Unknown ticker test
        $respUnknown = $this->get('/market/UNKNOWN');
        $respUnknown->assertStatus(200);
        $respUnknown->assertSee('UNKNOWN');
        $respUnknown->assertSee('Emiten Belum Terdaftar');

        // 2. Ticker with real observation
        MarketObservation::create([
            'ticker' => 'BBCA',
            'bar_date' => now()->toDateString(),
            'observation_time' => now(),
            'close_price' => 6125.00,
            'open_price' => 6000.00,
            'high_price' => 6125.00,
            'low_price' => 5950.00,
            'volume' => 136303100,
            'source' => 'Yahoo Finance',
            'is_eod_final' => true,
            'validation_status' => 'VALID',
        ]);

        $respBBCA = $this->get('/market/BBCA');
        $respBBCA->assertStatus(200);
        $respBBCA->assertSee('BBCA');
        $respBBCA->assertSee('Grafik Harga');

        // 3. Test all 7 tabs on ticker page
        $tabs = ['summary', 'monitoring', 'technical', 'fundamental', 'ai_insight', 'news', 'history'];
        foreach ($tabs as $t) {
            $respTab = $this->get("/market/BBCA?tab={$t}");
            $respTab->assertStatus(200);
        }
    }

    /**
     * Test profile page renders and updates analyst info.
     */
    public function test_profile_page_renders_and_updates(): void
    {
        $response = $this->get('/profile');
        $response->assertStatus(200);
        $response->assertSee('Profil Analis');

        $updateResp = $this->post('/profile', [
            'analyst_name' => 'Dr. Budi Santoso',
            'analyst_title' => 'Chief Quantitative Strategist',
            'email' => 'budi.santoso@aether.local',
        ]);
        $updateResp->assertRedirect('/profile');
        $this->get('/profile')->assertSee('Dr. Budi Santoso');
    }

    /**
     * Test brand update sanitization and rejection of dangerous files.
     */
    public function test_brand_update_sanitizes_files(): void
    {
        // Name update
        $resp = $this->post('/settings/brand', [
            'system_name' => 'Aether Enterprise Terminal',
            'system_subtitle' => 'Platform Riset Finansial Institusional',
        ]);
        $resp->assertRedirect('/profile');
        $this->get('/profile')->assertSee('Aether Enterprise Terminal');
    }

    /**
     * Test research submission and watchlist toggle APIs.
     */
    public function test_research_submit_and_watchlist_apis(): void
    {
        IdxCompanyRegistry::create([
            'ticker' => 'BBCA',
            'company_name' => 'Bank Central Asia Tbk.',
            'instrument_type' => 'EQUITY',
            'snapshot_date' => '2026-10-26',
        ]);

        // 1. Submit Research with unknown ticker -> rejected 422
        $resUnknown = $this->postJson('/api/research', [
            'ticker' => 'UNKNOWN',
            'analysis_type' => 'COMPREHENSIVE',
        ]);
        $resUnknown->assertStatus(422);
        $resUnknown->assertJson(['status' => 'REJECTED_UNKNOWN_TICKER']);

        // 2. Submit Research with valid ticker -> accepted 200
        $resResp = $this->postJson('/api/research', [
            'ticker' => 'BBCA',
            'analysis_type' => 'COMPREHENSIVE',
        ]);
        $resResp->assertStatus(200);
        $resResp->assertJson(['success' => true, 'ticker' => 'BBCA', 'status' => 'PENDING']);
        $this->assertDatabaseHas('research_requests', [
            'ticker' => 'BBCA',
            'status' => 'pending',
        ]);

        // 3. Watchlist Toggle
        $watchResp = $this->postJson('/api/watchlist/toggle', [
            'ticker' => 'BBCA',
        ]);
        $watchResp->assertStatus(200);
        $watchResp->assertJson(['success' => true, 'ticker' => 'BBCA', 'in_watchlist' => true]);
        $this->assertDatabaseHas('user_watchlists', [
            'ticker' => 'BBCA',
            'is_active' => true,
        ]);
    }
}
