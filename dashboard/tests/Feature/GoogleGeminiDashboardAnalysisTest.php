<?php

namespace Tests\Feature;

use App\Models\AnalysisRun;
use App\Models\MarketAnalysis;
use App\Models\PromptTemplate;
use App\Services\AiMarketAnalysisService;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleGeminiDashboardAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_generate_content_payload_and_schema_are_validated(): void
    {
        config([
            'services.dashboard_ai.provider' => 'google',
            'services.dashboard_ai.api_key' => 'test-key-not-a-secret',
            'services.dashboard_ai.model' => 'gemini-3.6-flash',
            'services.dashboard_ai.base_url' => 'https://generativelanguage.googleapis.com',
            'services.dashboard_ai.eod_inference_enabled' => true,
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent' => Http::response([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [['text' => json_encode([
                        'market_regime_summary' => 'Data evidence menunjukkan kondisi konsolidasi.',
                        'impact_direction' => 'mixed',
                        'impact_horizon' => 'multi_day',
                        'confidence_score' => 0.6,
                    ])]]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 20],
            ], 200),
        ]);

        PromptTemplate::create([
            'code' => 'MARKET_INTELLIGENCE_DAILY',
            'version' => '1.0.0',
            'model_id' => 'gemini-3.6-flash',
            'system_prompt' => 'Gunakan evidence saja.',
            'user_prompt_template' => 'Evidence: {market_summary}',
            'is_active' => true,
        ]);
        $run = AnalysisRun::create([
            'run_id' => 'RUN_GOOGLE_001',
            'trigger_type' => 'cli',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $analysis = app(AiMarketAnalysisService::class)->analyze($run, 'IDX Edge evidence only');

        $this->assertSame('valid_schema', $analysis->validation_status);
        $this->assertSame('gemini-3.6-flash', $analysis->model_name);
        $this->assertSame('mixed', $analysis->impact_direction);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent'
                && $request->header('x-goog-api-key')[0] === 'test-key-not-a-secret'
                && $request->data()['contents'][0]['role'] === 'user'
                && isset($request->data()['systemInstruction'], $request->data()['generationConfig']['responseMimeType']);
        });
    }

    public function test_auth_failure_is_not_retried_and_key_is_not_in_failure_text(): void
    {
        config([
            'services.dashboard_ai.provider' => 'google',
            'services.dashboard_ai.api_key' => 'test-key-not-a-secret',
            'services.dashboard_ai.model' => 'gemini-3.6-flash',
            'services.dashboard_ai.base_url' => 'https://generativelanguage.googleapis.com',
            'services.dashboard_ai.eod_inference_enabled' => true,
            'services.dashboard_ai.max_retries' => 2,
        ]);
        Http::fake(['*generateContent' => Http::response(['error' => ['status' => 'UNAUTHENTICATED']], 401)]);
        PromptTemplate::create([
            'code' => 'MARKET_INTELLIGENCE_DAILY', 'version' => '1.0.0', 'model_id' => 'gemini-3.6-flash',
            'system_prompt' => 'Evidence only.', 'user_prompt_template' => '{market_summary}', 'is_active' => true,
        ]);
        $run = AnalysisRun::create(['run_id' => 'RUN_GOOGLE_401', 'trigger_type' => 'cli', 'status' => 'completed', 'started_at' => now(), 'completed_at' => now()]);

        $analysis = app(AiMarketAnalysisService::class)->analyze($run, 'evidence');

        $this->assertSame('fallback', $analysis->validation_status);
        $this->assertStringContainsString('HTTP 401', $analysis->structured_output['fallback_reason']);
        $this->assertStringNotContainsString('test-key-not-a-secret', json_encode($analysis->toArray()));
        Http::assertSentCount(1);
    }

    public function test_health_separates_google_dashboard_ai_from_consultation_router(): void
    {
        config([
            'services.dashboard_ai.provider' => 'google',
            'services.dashboard_ai.api_key' => 'test-key-not-a-secret',
            'services.dashboard_ai.model' => 'gemini-3.6-flash',
            'services.dashboard_ai.base_url' => 'https://generativelanguage.googleapis.com',
            'services.ai_router.endpoint' => 'http://127.0.0.1:20128/v1',
            'services.ai_router.model_id' => 'ag/gemini-3.8-flash-high',
            'services.dashboard_candidate.read_only_health' => true,
        ]);
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash' => Http::response([
                'name' => 'models/gemini-3.6-flash',
                'supportedGenerationMethods' => ['generateContent'],
            ]),
            'http://127.0.0.1:20128/v1/models' => Http::response([
                'data' => [['id' => 'ag/gemini-3.8-flash-high']],
            ]),
        ]);
        $run = AnalysisRun::create([
            'run_id' => 'RUN_HEALTH_GOOGLE_001',
            'trigger_type' => 'test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'gemini-3.6-flash',
            'prompt_version' => 'test',
            'market_regime_summary' => 'Valid stored result.',
            'impact_direction' => 'mixed',
            'impact_horizon' => 'multi_day',
            'confidence_score' => 0.6,
            'structured_output' => ['source' => 'test'],
            'validation_status' => 'valid_schema',
        ]);

        $health = app(SystemHealthService::class)->checkAll();

        $this->assertSame('dashboard_ai', $health['dashboard_ai']['component']);
        $this->assertSame('google', $health['dashboard_ai']['details']['provider']);
        $this->assertTrue($health['dashboard_ai']['details']['configuration_available']);
        $this->assertTrue($health['dashboard_ai']['details']['endpoint_reachable']);
        $this->assertTrue($health['dashboard_ai']['details']['inference_succeeded']);
        $this->assertTrue($health['dashboard_ai']['details']['result_validated']);
        $this->assertTrue($health['dashboard_ai']['details']['result_stored']);
        $this->assertSame('consultation_router', $health['consultation_router']['component']);
        $this->assertSame('ag/gemini-3.8-flash-high', $health['consultation_router']['details']['model']);
        $this->assertSame($health['dashboard_ai'], $health['ai_model']);
    }
}
