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
        $response->assertSee('Hermes Market Intelligence');
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
}
