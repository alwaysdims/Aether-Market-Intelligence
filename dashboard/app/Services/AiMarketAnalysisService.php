<?php

namespace App\Services;

use App\Models\AnalysisRun;
use App\Models\MarketAnalysis;
use App\Models\PromptTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiMarketAnalysisService
{
    protected string $endpoint;
    protected string $modelId;
    protected ?string $apiKey;
    protected int $timeout;
    protected int $maxTokens;
    protected bool $liveInferenceEnabled;

    public function __construct()
    {
        $this->endpoint = rtrim(config('services.ai_router.endpoint', env('AI_ROUTER_ENDPOINT', 'http://127.0.0.1:20128/v1')), '/') . '/chat/completions';
        $this->modelId = config('services.ai_router.model_id', env('AI_MODEL_ID', 'ag/gemini-3.8-flash-high'));
        $this->apiKey = config('services.ai_router.api_key', env('AI_API_KEY', ''));
        $this->timeout = (int) config('services.ai_router.timeout', env('AI_ROUTER_TIMEOUT', 30));
        $this->maxTokens = (int) config('services.ai_router.max_tokens', env('AI_ROUTER_MAX_TOKENS', 2500));
        $this->liveInferenceEnabled = (bool) config('services.ai_router.live_inference_enabled', env('AI_LIVE_INFERENCE_ENABLED', false));
    }

    /**
     * Run AI analysis on given market summary and news context.
     */
    public function analyze(AnalysisRun $run, string $marketContext, array $recentNews = [], bool $forceLive = false): MarketAnalysis
    {
        $template = PromptTemplate::where('code', 'MARKET_INTELLIGENCE_DAILY')
            ->where('is_active', true)
            ->latest('version')
            ->first();

        // Safe gate: Prevent unintended live inference unless explicitly enabled
        if (!$this->liveInferenceEnabled && !$forceLive) {
            return $this->buildFallbackAnalysis(
                $run,
                $marketContext,
                $template,
                'Inferensi live AI dinonaktifkan (AI_LIVE_INFERENCE_ENABLED=false). Memerlukan izin operasional eksplisit.'
            );
        }

        $systemPrompt = $template ? $template->system_prompt : 'You are Aether Market Intelligence AI. Evaluate Indonesian stock market condition objectively without fabricating price targets.';
        $userTemplate = $template ? $template->user_prompt_template : "Analisis data pasar:\n{market_summary}";

        // Format news context if available
        $newsContext = "";
        if (!empty($recentNews)) {
            $newsContext = "\n\nBerita Terkini:\n";
            foreach (array_slice($recentNews, 0, 5) as $idx => $news) {
                $newsContext .= ($idx + 1) . ". [" . ($news['verification_status'] ?? 'unverified') . "] " . ($news['title'] ?? '') . "\n";
            }
        }

        $fullPrompt = str_replace('{market_summary}', $marketContext . $newsContext, $userTemplate);

        $payload = [
            'model' => $this->modelId,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $fullPrompt],
            ],
            'temperature' => 0.2,
            'max_tokens' => $this->maxTokens,
            'response_format' => ['type' => 'json_object'],
        ];

        $analysisData = null;
        $validationStatus = 'valid_schema';
        $fallbackReason = 'Ketiadaan respon router AI lokal';

        try {
            $req = Http::timeout($this->timeout);
            if (!empty($this->apiKey)) {
                $req = $req->withToken($this->apiKey);
            }

            $resp = $req->post($this->endpoint, $payload);

            if ($resp->successful()) {
                $choices = $resp->json('choices');
                if (!is_array($choices) || empty($choices)) {
                    $validationStatus = 'invalid_schema';
                    $fallbackReason = 'Format respons AI tidak valid: struktur choices kosong atau tidak sesuai standar.';
                } else {
                    $finishReason = $choices[0]['finish_reason'] ?? 'stop';
                    $rawContent = $choices[0]['message']['content'] ?? null;

                    if ($finishReason === 'length') {
                        $validationStatus = 'invalid_schema';
                        $fallbackReason = 'Respons AI terpotong karena batas token habis (finish_reason = length).';
                    } elseif ($rawContent === null || trim($rawContent) === '') {
                        $validationStatus = 'invalid_schema';
                        $fallbackReason = 'Konten respons AI kosong.';
                    } else {
                        $decoded = json_decode($rawContent, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $validationStatus = 'invalid_schema';
                            $fallbackReason = 'Gagal parsing JSON: ' . json_last_error_msg();
                        } elseif (!is_array($decoded) || empty($decoded['market_regime_summary']) || !is_string($decoded['market_regime_summary'])) {
                            $validationStatus = 'invalid_schema';
                            $fallbackReason = 'Skema respons AI tidak valid: kunci market_regime_summary wajib berupa string non-kosong.';
                        } else {
                            $analysisData = $decoded;
                            if ($resp->json('usage')) {
                                $analysisData['_usage'] = $resp->json('usage');
                            }
                            $analysisData['_finish_reason'] = $finishReason;
                        }
                    }
                }
            } else {
                $status = $resp->status();
                Log::warning("AI Router error [{$status}]: " . $resp->body());
                $validationStatus = 'fallback';
                $fallbackReason = "AI Router mengembalikan respon HTTP {$status}.";
            }
        } catch (\Throwable $e) {
            Log::warning("AI Router connection exception: " . $e->getMessage());
            $validationStatus = 'fallback';
            $fallbackReason = "Koneksi ke AI router terputus/timeout: " . $e->getMessage();
        }

        // Fallback analysis when model endpoint is unreachable or returns non-JSON
        if (!$analysisData) {
            return $this->buildFallbackAnalysis($run, $marketContext, $template, $fallbackReason);
        }

        return MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'prompt_template_id' => $template?->id,
            'model_name' => $this->modelId,
            'prompt_version' => $template ? $template->version : '1.0.0',
            'market_regime_summary' => $analysisData['market_regime_summary'] ?? 'Ringkasan pasar tidak tersedia.',
            'impact_direction' => in_array($analysisData['impact_direction'] ?? '', ['positive', 'negative', 'mixed', 'unclear']) ? $analysisData['impact_direction'] : 'unclear',
            'impact_horizon' => in_array($analysisData['impact_horizon'] ?? '', ['intraday', 'multi_day', 'long_term']) ? $analysisData['impact_horizon'] : 'multi_day',
            'confidence_score' => $analysisData['confidence_score'] ?? 0.50,
            'structured_output' => $analysisData,
            'validation_status' => $validationStatus,
        ]);
    }

    /**
     * Build structured fallback analysis when AI router fails or times out.
     */
    public function buildFallbackAnalysis(
        AnalysisRun $run,
        string $marketContext = '',
        ?PromptTemplate $template = null,
        string $fallbackReason = 'Ketiadaan respon router AI lokal'
    ): MarketAnalysis {
        $analysisData = [
            'market_regime_summary' => 'Analisis kuantitatif terintegrasi: data pasar EOD berhasil dicatat ke database. Inferensi LLM saat ini dalam mode fallback terstruktur.',
            'impact_direction' => 'unclear',
            'impact_horizon' => 'multi_day',
            'confidence_score' => 0.50,
            'impacted_tickers' => [],
            'verified_facts' => ['Data snapshot EOD diarsipkan secara otomatis'],
            'unverified_points' => [],
            'risks' => [$fallbackReason],
            'unknowns' => ['Arah likuiditas sesi berikutnya'],
            'fallback_reason' => $fallbackReason,
        ];

        return MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'prompt_template_id' => $template?->id,
            'model_name' => $this->modelId,
            'prompt_version' => $template ? $template->version : '1.0.0',
            'market_regime_summary' => $analysisData['market_regime_summary'],
            'impact_direction' => 'unclear',
            'impact_horizon' => 'multi_day',
            'confidence_score' => 0.50,
            'structured_output' => $analysisData,
            'validation_status' => 'fallback',
        ]);
    }
}
