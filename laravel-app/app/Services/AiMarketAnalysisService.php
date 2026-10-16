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

    public function __construct()
    {
        $this->endpoint = env('AI_ROUTER_ENDPOINT', 'http://127.0.0.1:20128/v1') . '/chat/completions';
        $this->modelId = env('AI_MODEL_ID', 'ag/gemini-3.8-flash-high');
        $this->apiKey = env('AI_API_KEY', '');
        $this->timeout = (int) env('AI_ROUTER_TIMEOUT', 30);
    }

    /**
     * Run AI analysis on given market summary and news context.
     */
    public function analyze(AnalysisRun $run, string $marketContext, array $recentNews = []): MarketAnalysis
    {
        $template = PromptTemplate::where('code', 'MARKET_INTELLIGENCE_DAILY')
            ->where('is_active', true)
            ->latest('version')
            ->first();

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
            'max_tokens' => 1500,
            'response_format' => ['type' => 'json_object'],
        ];

        $analysisData = null;
        $validationStatus = 'valid_schema';

        try {
            $req = Http::timeout($this->timeout);
            if (!empty($this->apiKey)) {
                $req = $req->withToken($this->apiKey);
            }

            $resp = $req->post($this->endpoint, $payload);

            if ($resp->successful()) {
                $rawContent = $resp->json('choices.0.message.content');
                $decoded = json_decode($rawContent, true);

                if (is_array($decoded) && isset($decoded['market_regime_summary'])) {
                    $analysisData = $decoded;
                } else {
                    $validationStatus = 'invalid_schema';
                }
            } else {
                Log::warning("AI Router error [{$resp->status()}]: " . $resp->body());
                $validationStatus = 'fallback';
            }
        } catch (\Throwable $e) {
            Log::warning("AI Router connection exception: " . $e->getMessage());
            $validationStatus = 'fallback';
        }

        // Fallback analysis when model endpoint is unreachable or returns non-JSON
        if (!$analysisData) {
            return $this->buildFallbackAnalysis($run, $marketContext, $template);
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
    public function buildFallbackAnalysis(AnalysisRun $run, string $marketContext = '', ?PromptTemplate $template = null): MarketAnalysis
    {
        $analysisData = [
            'market_regime_summary' => 'Analisis kuantitatif terintegrasi: data pasar EOD berhasil dicatat ke database. Inferensi LLM saat ini dalam mode fallback terstruktur.',
            'impact_direction' => 'unclear',
            'impact_horizon' => 'multi_day',
            'confidence_score' => 0.50,
            'impacted_tickers' => [],
            'verified_facts' => ['Data snapshot EOD diarsipkan secara otomatis'],
            'unverified_points' => [],
            'risks' => ['Ketiadaan respon router AI lokal'],
            'unknowns' => ['Arah likuiditas sesi berikutnya'],
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
