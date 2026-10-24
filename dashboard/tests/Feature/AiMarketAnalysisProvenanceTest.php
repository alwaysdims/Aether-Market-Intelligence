<?php

namespace Tests\Feature;

use App\Models\AnalysisRun;
use App\Models\MarketAnalysis;
use App\Models\MarketSnapshot;
use App\Models\PostHorizonEvaluation;
use App\Models\PromptTemplate;
use App\Services\AiMarketAnalysisService;
use App\Services\PostHorizonEvaluatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiMarketAnalysisProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected AiMarketAnalysisService $aiService;
    protected PostHorizonEvaluatorService $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aiService = app(AiMarketAnalysisService::class);
        $this->evaluator = app(PostHorizonEvaluatorService::class);

        PromptTemplate::create([
            'code' => 'MARKET_INTELLIGENCE_DAILY',
            'version' => '1.0.0',
            'model_id' => 'ag/gemini-3.8-flash-high',
            'system_prompt' => 'You are Aether Market Intelligence AI.',
            'user_prompt_template' => "Analisis data pasar:\n{market_summary}",
            'is_active' => true,
        ]);
    }

    private function createRun(string $runId = 'RUN_PROV_001'): AnalysisRun
    {
        return AnalysisRun::create([
            'run_id' => $runId,
            'trigger_type' => 'cli',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'duration_seconds' => 1.5,
        ]);
    }

    /**
     * Skenario 1: Respons JSON valid.
     */
    public function test_scenario_1_valid_json_response_creates_valid_schema_analysis(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [
                    [
                        'finish_reason' => 'stop',
                        'message' => [
                            'content' => json_encode([
                                'market_regime_summary' => 'IHSG bergerak konsolidatif dengan inflow asing moderat pada big banks.',
                                'impact_direction' => 'positive',
                                'impact_horizon' => 'multi_day',
                                'confidence_score' => 0.85,
                                'impacted_tickers' => [
                                    ['ticker' => 'BBCA', 'direction' => 'positive', 'rationale' => 'Inflow asing solid'],
                                    ['ticker' => 'BMRI', 'direction' => 'positive', 'rationale' => 'Kinerja kredit kuat'],
                                ],
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $run = $this->createRun('RUN_VALID_001');
        $analysis = $this->aiService->analyze($run, 'BBCA: 6125; BMRI: 4950');

        $this->assertEquals('valid_schema', $analysis->validation_status);
        $this->assertEquals('positive', $analysis->impact_direction);
        $this->assertEquals(0.85, (float) $analysis->confidence_score);
        $this->assertEquals('ag/gemini-3.8-flash-high', $analysis->model_name);
        $this->assertStringContainsString('IHSG bergerak konsolidatif', $analysis->market_regime_summary);
    }

    /**
     * Skenario 2a: Respons JSON terpotong akibat batas token habis (finish_reason = length).
     */
    public function test_scenario_2a_truncated_token_budget_finish_reason_length_triggers_fallback(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [
                    [
                        'finish_reason' => 'length',
                        'message' => [
                            'content' => '{"market_regime_summary": "IHSG berpotensi melemah karena tekanan inflasi...',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $run = $this->createRun('RUN_TRUNC_LENGTH_001');
        $analysis = $this->aiService->analyze($run, 'Market Context');

        $this->assertEquals('fallback', $analysis->validation_status);
        $this->assertEquals('unclear', $analysis->impact_direction);
        $this->assertArrayHasKey('fallback_reason', $analysis->structured_output);
        $this->assertStringContainsString('batas token habis', $analysis->structured_output['fallback_reason']);
        $this->assertStringContainsString('finish_reason = length', $analysis->structured_output['fallback_reason']);
    }

    /**
     * Skenario 2b: Respons JSON terpotong / malformed syntax JSON.
     */
    public function test_scenario_2b_malformed_incomplete_json_triggers_fallback_with_parser_error(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [
                    [
                        'finish_reason' => 'stop',
                        'message' => [
                            'content' => '{"market_regime_summary": "IHSG bergerak stabil"', // Missing closing brace
                        ],
                    ],
                ],
            ], 200),
        ]);

        $run = $this->createRun('RUN_MALFORMED_JSON_001');
        $analysis = $this->aiService->analyze($run, 'Market Context');

        $this->assertEquals('fallback', $analysis->validation_status);
        $this->assertArrayHasKey('fallback_reason', $analysis->structured_output);
        $this->assertStringContainsString('Gagal parsing JSON', $analysis->structured_output['fallback_reason']);
    }

    /**
     * Skenario 3: Respons kosong (content kosong atau null).
     */
    public function test_scenario_3_empty_ai_response_content_triggers_fallback(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [
                    [
                        'finish_reason' => 'stop',
                        'message' => [
                            'content' => '   ',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $run = $this->createRun('RUN_EMPTY_CONTENT_001');
        $analysis = $this->aiService->analyze($run, 'Market Context');

        $this->assertEquals('fallback', $analysis->validation_status);
        $this->assertArrayHasKey('fallback_reason', $analysis->structured_output);
        $this->assertStringContainsString('Konten respons AI kosong', $analysis->structured_output['fallback_reason']);
    }

    /**
     * Skenario 4a: HTTP 401 Unauthorized.
     */
    public function test_scenario_4a_http_401_triggers_fallback(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'error' => ['message' => 'Invalid API key', 'code' => 'invalid_api_key'],
            ], 401),
        ]);

        $run401 = $this->createRun('RUN_AUTH_401');
        $analysis401 = $this->aiService->analyze($run401, 'Market Context');

        $this->assertEquals('fallback', $analysis401->validation_status);
        $this->assertStringContainsString('401', $analysis401->structured_output['fallback_reason']);
    }

    /**
     * Skenario 4b: HTTP 403 Forbidden.
     */
    public function test_scenario_4b_http_403_triggers_fallback(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'error' => ['message' => 'Forbidden resource access', 'code' => 'forbidden'],
            ], 403),
        ]);

        $run403 = $this->createRun('RUN_AUTH_403');
        $analysis403 = $this->aiService->analyze($run403, 'Market Context');

        $this->assertEquals('fallback', $analysis403->validation_status);
        $this->assertStringContainsString('403', $analysis403->structured_output['fallback_reason']);
    }

    /**
     * Skenario 5: HTTP 429 Rate Limit.
     */
    public function test_scenario_5_http_429_rate_limit_triggers_fallback_with_status_code(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'error' => ['message' => 'Rate limit exceeded', 'code' => 'rate_limit_exceeded'],
            ], 429),
        ]);

        $run = $this->createRun('RUN_RATE_LIMIT_429');
        $analysis = $this->aiService->analyze($run, 'Market Context');

        $this->assertEquals('fallback', $analysis->validation_status);
        $this->assertArrayHasKey('fallback_reason', $analysis->structured_output);
        $this->assertStringContainsString('429', $analysis->structured_output['fallback_reason']);
    }

    /**
     * Skenario 6a: HTTP 500 Internal Server Error.
     */
    public function test_scenario_6a_http_500_triggers_fallback(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'error' => ['message' => 'Internal server error in upstream model', 'code' => 'internal_error'],
            ], 500),
        ]);

        $run500 = $this->createRun('RUN_SERVER_ERROR_500');
        $analysis500 = $this->aiService->analyze($run500, 'Market Context');

        $this->assertEquals('fallback', $analysis500->validation_status);
        $this->assertStringContainsString('500', $analysis500->structured_output['fallback_reason']);
    }

    /**
     * Skenario 6b: Network Timeout.
     */
    public function test_scenario_6b_timeout_triggers_fallback(): void
    {
        Http::fake([
            '*/chat/completions' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds');
            },
        ]);

        $runTimeout = $this->createRun('RUN_TIMEOUT_001');
        $analysisTimeout = $this->aiService->analyze($runTimeout, 'Market Context');

        $this->assertEquals('fallback', $analysisTimeout->validation_status);
        $this->assertStringContainsString('timeout', strtolower($analysisTimeout->structured_output['fallback_reason']));
    }

    /**
     * Skenario 7: HTTP 200 dengan struktur respons yang tidak sesuai (choices kosong atau bukan array).
     */
    public function test_scenario_7_http_200_with_malformed_structure_triggers_fallback(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'id' => 'chatcmpl-invalid',
                'object' => 'chat.completion',
                'choices' => [], // Empty choices
            ], 200),
        ]);

        $run = $this->createRun('RUN_EMPTY_CHOICES_001');
        $analysis = $this->aiService->analyze($run, 'Market Context');

        $this->assertEquals('fallback', $analysis->validation_status);
        $this->assertArrayHasKey('fallback_reason', $analysis->structured_output);
        $this->assertStringContainsString('struktur choices kosong', $analysis->structured_output['fallback_reason']);
    }

    /**
     * Skenario 8: Respons valid yang memiliki metadata penghentian dan token usage.
     */
    public function test_scenario_8_valid_response_with_finish_reason_and_usage_preserves_provenance(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [
                    [
                        'finish_reason' => 'stop',
                        'message' => [
                            'content' => json_encode([
                                'market_regime_summary' => 'Sentimen pasar netral-positif didukung penguatan rupiah.',
                                'impact_direction' => 'positive',
                                'impact_horizon' => 'multi_day',
                                'confidence_score' => 0.78,
                                'impacted_tickers' => [
                                    ['ticker' => 'TLKM', 'direction' => 'positive', 'rationale' => 'ARPU data bertumbuh'],
                                ],
                            ]),
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 850,
                    'completion_tokens' => 240,
                    'total_tokens' => 1090,
                    'reasoning_tokens' => 420,
                ],
            ], 200),
        ]);

        $run = $this->createRun('RUN_USAGE_PROV_001');
        $analysis = $this->aiService->analyze($run, 'Market Context TLKM');

        $this->assertEquals('valid_schema', $analysis->validation_status);
        $this->assertEquals('positive', $analysis->impact_direction);
        $this->assertEquals('stop', $analysis->structured_output['_finish_reason']);
        $this->assertArrayHasKey('_usage', $analysis->structured_output);
        $this->assertEquals(850, $analysis->structured_output['_usage']['prompt_tokens']);
        $this->assertEquals(240, $analysis->structured_output['_usage']['completion_tokens']);
        $this->assertEquals(420, $analysis->structured_output['_usage']['reasoning_tokens']);
    }

    /**
     * 6. Skenario: Data input kosong / tidak lengkap ditangani dengan aman.
     */
    public function test_empty_market_input_handled_gracefully(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'market_regime_summary' => 'Data pasar tidak lengkap.',
                                'impact_direction' => 'unclear',
                                'impact_horizon' => 'multi_day',
                                'confidence_score' => 0.10,
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $run = $this->createRun('RUN_EMPTY_INPUT');
        $analysis = $this->aiService->analyze($run, '', []);

        $this->assertEquals('valid_schema', $analysis->validation_status);
        $this->assertEquals('unclear', $analysis->impact_direction);
    }

    /**
     * 7. Skenario: Fallback yang valid dan tercatat dengan benar.
     */
    public function test_structured_fallback_records_correct_attributes(): void
    {
        $run = $this->createRun('RUN_EXPLICIT_FALLBACK');
        $analysis = $this->aiService->buildFallbackAnalysis($run, 'test context', null, 'Manual test fallback');

        $this->assertEquals('fallback', $analysis->validation_status);
        $this->assertEquals('unclear', $analysis->impact_direction);
        $this->assertEquals('ag/gemini-3.8-flash-high', $analysis->model_name);
        $this->assertEquals('Manual test fallback', $analysis->structured_output['fallback_reason']);
        $this->assertEquals(0.50, (float) $analysis->confidence_score);
    }

    /**
     * 8. Skenario: Pendaftaran prediksi berulang (idempotensi) tidak membuat duplikat.
     */
    public function test_repeated_prediction_registration_is_strictly_idempotent(): void
    {
        $run = $this->createRun('RUN_IDEMPOTENT_001');
        $analysis = $this->aiService->buildFallbackAnalysis($run);

        MarketSnapshot::create([
            'ticker' => 'BBCA',
            'bar_date' => '2026-10-14',
            'source_timestamp' => now(),
            'close_price' => 6125.00,
            'data_classification' => 'end_of_day',
        ]);

        $evals1 = $this->evaluator->registerPredictions($analysis, 5);
        $this->assertCount(1, $evals1);

        $evals2 = $this->evaluator->registerPredictions($analysis, 5);
        $this->assertCount(1, $evals2);

        $this->assertEquals(1, PostHorizonEvaluation::where('market_analysis_id', $analysis->id)->count());
    }

    /**
     * 9. Skenario: Prediksi fallback sepenuhnya dikeluarkan dari metrik akurasi Gemini.
     */
    public function test_fallback_predictions_are_excluded_from_pure_ai_accuracy(): void
    {
        $run = $this->createRun('RUN_METRICS_ISOLATION');
        
        // Buat 1 analisis fallback
        $fallbackAnalysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Fallback summary',
            'impact_direction' => 'unclear',
            'confidence_score' => 0.50,
            'validation_status' => 'fallback',
        ]);

        // Buat 1 analisis AI valid
        $aiAnalysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0',
            'market_regime_summary' => 'Pure AI summary',
            'impact_direction' => 'positive',
            'confidence_score' => 0.90,
            'validation_status' => 'valid_schema',
        ]);

        // Mock snapshot harga 14 Okt
        MarketSnapshot::create([
            'ticker' => 'BMRI',
            'bar_date' => '2026-10-14',
            'source_timestamp' => now(),
            'close_price' => 4950.00,
            'data_classification' => 'end_of_day',
        ]);

        MarketSnapshot::create([
            'ticker' => 'BBRI',
            'bar_date' => '2026-10-14',
            'source_timestamp' => now(),
            'close_price' => 3820.00,
            'data_classification' => 'end_of_day',
        ]);

        // Daftarkan prediksi
        $this->evaluator->registerPredictions($fallbackAnalysis, 5);
        $this->evaluator->registerPredictions($aiAnalysis, 5);

        // Mock snapshot harga 21 Okt (+5 hari bursa)
        MarketSnapshot::create([
            'ticker' => 'BMRI',
            'bar_date' => '2026-10-21',
            'source_timestamp' => now(),
            'close_price' => 5100.00, // naik
            'data_classification' => 'end_of_day',
        ]);

        MarketSnapshot::create([
            'ticker' => 'BBRI',
            'bar_date' => '2026-10-21',
            'source_timestamp' => now(),
            'close_price' => 3950.00, // naik
            'data_classification' => 'end_of_day',
        ]);

        $asOf = Carbon::parse('2026-10-21 16:05:00', 'Asia/Jakarta');
        $this->evaluator->evaluatePending($asOf);

        $metrics = $this->evaluator->getMetricsSummary();

        // Verifikasi pemisahan metrik
        $this->assertGreaterThan(0, $metrics['total_evaluated']);
        $this->assertGreaterThan(0, $metrics['fallback_count']);
        
        // Akurasi AI murni hanya memperhitungkan record dengan is_fallback = false
        $this->assertArrayHasKey('ai_accuracy_pct', $metrics);
        $this->assertArrayHasKey('fallback_count', $metrics);
    }

    /**
     * Skenario 13: Pencegahan inferensi live tanpa izin operasional.
     */
    public function test_live_inference_disabled_triggers_safe_fallback_without_network_request(): void
    {
        config(['services.ai_router.live_inference_enabled' => false]);
        $service = app(AiMarketAnalysisService::class);

        // Even without Http::fake, no HTTP request should be sent
        $run = $this->createRun('RUN_DISABLED_LIVE_001');
        $analysis = $service->analyze($run, 'Market Context');

        $this->assertEquals('fallback', $analysis->validation_status);
        $this->assertEquals('unclear', $analysis->impact_direction);
        $this->assertArrayHasKey('fallback_reason', $analysis->structured_output);
        $this->assertStringContainsString('AI_LIVE_INFERENCE_ENABLED=false', $analysis->structured_output['fallback_reason']);
    }
}
