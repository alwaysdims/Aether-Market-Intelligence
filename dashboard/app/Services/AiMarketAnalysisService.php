<?php

namespace App\Services;

use App\Models\AnalysisRun;
use App\Models\CompanyAnalysisMetric;
use App\Models\CompanyCorporateAction;
use App\Models\EvidenceSnapshot;
use App\Models\MarketAnalysis;
use App\Models\MarketObservation;
use App\Models\NewsItem;
use App\Models\PredictionSignal;
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

    protected bool $onDemandEnabled;

    public function __construct()
    {
        $this->endpoint = rtrim(config('services.ai_router.endpoint', env('AI_ROUTER_ENDPOINT', 'http://127.0.0.1:20128/v1')), '/').'/chat/completions';
        $this->modelId = config('services.ai_router.model_id', env('AI_MODEL_ID', 'ag/gemini-3.8-flash-high'));
        $this->apiKey = config('services.ai_router.api_key', env('AI_API_KEY', ''));
        $this->timeout = (int) config('services.ai_router.timeout', env('AI_ROUTER_TIMEOUT', 25));
        $this->maxTokens = (int) config('services.ai_router.max_tokens', env('AI_ROUTER_MAX_TOKENS', 600));
        $this->liveInferenceEnabled = (bool) config('services.ai_router.eod_inference_enabled', env('AI_LIVE_INFERENCE_EOD_ENABLED', false))
            || (bool) config('services.ai_router.live_inference_enabled', env('AI_LIVE_INFERENCE_ENABLED', false));
        $this->onDemandEnabled = (bool) config('services.ai_router.on_demand_enabled', env('AI_LIVE_INFERENCE_ON_DEMAND_ENABLED', true));
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
        if (! $this->liveInferenceEnabled && ! $forceLive) {
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
        $newsContext = '';
        if (! empty($recentNews)) {
            $newsContext = "\n\nBerita Terkini:\n";
            foreach (array_slice($recentNews, 0, 5) as $idx => $news) {
                $newsContext .= ($idx + 1).'. ['.($news['verification_status'] ?? 'unverified').'] '.($news['title'] ?? '')."\n";
            }
        }

        $fullPrompt = str_replace('{market_summary}', $marketContext.$newsContext, $userTemplate);

        $payload = [
            'model' => $this->modelId,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $fullPrompt],
            ],
            'temperature' => 0.2,
            'max_tokens' => $this->maxTokens,
            'stream' => false,
            'response_format' => ['type' => 'json_object'],
        ];

        $analysisData = null;
        $validationStatus = 'valid_schema';
        $fallbackReason = 'Ketiadaan respon router AI lokal';

        try {
            $req = Http::timeout($this->timeout);
            if (! empty($this->apiKey)) {
                $req = $req->withToken($this->apiKey);
            }

            $resp = $req->post($this->endpoint, $payload);

            if ($resp->successful()) {
                $choices = $resp->json('choices');
                if (! is_array($choices) || empty($choices)) {
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
                            $fallbackReason = 'Gagal parsing JSON: '.json_last_error_msg();
                        } elseif (! is_array($decoded) || empty($decoded['market_regime_summary']) || ! is_string($decoded['market_regime_summary'])) {
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
                Log::warning("AI Router error [{$status}]: ".$resp->body());
                $validationStatus = 'fallback';
                $fallbackReason = "AI Router mengembalikan respon HTTP {$status}.";
            }
        } catch (\Throwable $e) {
            Log::warning('AI Router connection exception: '.$e->getMessage());
            $validationStatus = 'fallback';
            $fallbackReason = 'Koneksi ke AI router terputus/timeout: '.$e->getMessage();
        }

        // Fallback analysis when model endpoint is unreachable or returns non-JSON
        if (! $analysisData) {
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

    /**
     * Generate grounded on-demand AI narrative explanation for a specific ticker based strictly on stored evidence.
     */
    public function generateOnDemandTickerExplanation(
        string $ticker,
        MarketObservation $obs,
        ?PredictionSignal $sig = null,
        ?EvidenceSnapshot $ev = null,
        ?NewsItem $news = null,
        ?CompanyAnalysisMetric $metric = null
    ): array {
        $barDateStr = $obs->bar_date->format('Y-m-d');
        $hasFund = $metric && $metric->fundamental_status === 'CALCULATED';
        $analysisType = $hasFund ? 'FULL_FUNDAMENTAL_AND_TECHNICAL' : 'PARTIAL_TECHNICAL_ONLY';

        // 1. Content-based indicator payload (independent of metric_id primary key)
        $indicatorPayload = $metric ? implode('|', [
            (string) $metric->sma_20,
            (string) $metric->sma_50,
            (string) $metric->sma_200,
            (string) $metric->rsi_14,
            (string) $metric->macd,
            (string) $metric->macd_signal,
            (string) $metric->atr_14,
            (string) $metric->technical_trend,
            (string) $metric->technical_status,
        ]) : 'no_indicators';

        // 2. Fundamental content, period, and revision/data_quality_flags payload
        $fundPayload = $metric ? implode('|', [
            (string) $metric->fundamental_status,
            (string) $metric->eps,
            (string) $metric->bvps,
            (string) $metric->pe_ratio,
            (string) $metric->pbv_ratio,
            (string) $metric->der_ratio,
            (string) $metric->roe_pct,
            (string) $metric->roa_pct,
            (string) $metric->net_profit_margin_pct,
            json_encode($metric->data_quality_flags ?? []),
        ]) : 'no_fundamentals';

        // 3. News content payload
        $newsPayload = $news ? implode('|', [
            (string) $news->title,
            (string) $news->source_name,
            (string) $news->published_at?->toIso8601String(),
        ]) : 'no_news';

        // 4. Corporate actions payload
        $corpAction = CompanyCorporateAction::where('ticker', $ticker)->latest('execution_date')->first();
        $actionPayload = $corpAction ? implode('|', [
            (string) $corpAction->action_type,
            (string) $corpAction->execution_date?->toDateString(),
            (string) $corpAction->amount_or_ratio,
        ]) : 'no_actions';

        // 5. OHLCV Bar content payload
        $barPayload = implode('|', [
            $ticker,
            $barDateStr,
            (string) $obs->open_price,
            (string) $obs->high_price,
            (string) $obs->low_price,
            (string) $obs->close_price,
            (string) $obs->volume,
            (string) $obs->classification,
        ]);

        $promptVersion = '1.0.0-on-demand';
        $evidenceHash = hash('sha256', implode('##', [
            $barPayload,
            $indicatorPayload,
            $fundPayload,
            $newsPayload,
            $actionPayload,
        ]));

        $cacheKey = hash('sha256', implode('##', [
            $evidenceHash,
            $this->modelId,
            $promptVersion,
        ]));

        // Check if valid explanation for this ticker and bar exists
        $existing = MarketAnalysis::whereJsonContains('structured_output->ticker', $ticker)
            ->whereJsonContains('structured_output->bar_date', $barDateStr)
            ->where('validation_status', 'valid_schema')
            ->latest('id')
            ->first();

        if ($existing) {
            $existingEvidenceHash = $existing->structured_output['evidence_hash'] ?? null;
            $existingCacheKey = $existing->structured_output['cache_key'] ?? null;

            // If an explicit evidence hash exists and differs from current evidence, regenerate
            $isEvidenceStale = $existingEvidenceHash !== null && $existingEvidenceHash !== $evidenceHash;
            $isKeyStale = $existingCacheKey !== null && $existingCacheKey !== $cacheKey;

            if (! $isEvidenceStale && ! $isKeyStale) {
                return [
                    'success' => true,
                    'is_cached' => true,
                    'narrative' => $existing->market_regime_summary,
                    'analysis_id' => $existing->id,
                    'model' => $existing->model_name,
                    'cache_key' => $cacheKey,
                    'evidence_hash' => $evidenceHash,
                    'usage' => $existing->structured_output['usage'] ?? null,
                    'latency_ms' => $existing->structured_output['latency_ms'] ?? 0,
                ];
            }
        }

        // 2. Check on-demand feature flag and unit testing mode
        if (! $this->onDemandEnabled || app()->runningUnitTests()) {
            return [
                'success' => false,
                'fallback_reason' => 'Inferensi AI on-demand dinonaktifkan atau dalam mode unit testing.',
            ];
        }

        // 3. Build strict evidence prompt
        $priceRange = $obs->high_price - $obs->low_price;
        $rangePct = $obs->low_price > 0 ? round(($priceRange / $obs->low_price) * 100, 2) : 0;
        $changeBar = $obs->close_price - $obs->open_price;
        $changeSign = $changeBar >= 0 ? '+' : '';
        $changePct = $obs->open_price > 0 ? round(($changeBar / $obs->open_price) * 100, 2) : 0;
        $volFmt = number_format($obs->volume, 0, ',', '.');
        $barDateIndo = $obs->bar_date->format('d M Y');

        $isValidatedUniverse = array_key_exists($ticker, BeiUniverseRegistry::VALIDATED_ML_UNIVERSE);

        $systemPrompt = "Anda adalah asisten kuantitatif Aether Market Intelligence.\n"
            ."Tugas Anda: Memberikan penjelasan analisis naratif yang objektif, berbasis data faktual tersimpan untuk emiten yang diminta.\n\n"
            ."ATURAN KETAT:\n"
            ."1. DILARANG MENGARANG: Gunakan HANYA data harga, volume, tanggal, dan probabilitas yang diberikan dalam evidence. Dilarang mengarang target harga baru atau tanggal masa depan.\n";

        if ($sig && $isValidatedUniverse) {
            $systemPrompt .= "2. PERTAHANKAN ABSTAIN (NO_SIGNAL): Status model ML adalah ABSTAIN. Anda DILARANG KERAS mengubah status abstain menjadi sinyal beli/jual atau rekomendasi spekulatif. Jelaskan bahwa keyakinan model berada di bawah ambang batas minimal 40.00% (Tau 0.70) sehingga model menolak berspekulasi guna melindungi modal.\n";
        } else {
            $systemPrompt .= "2. BATASAN VALIDASI (MODEL_NOT_VALIDATED_FOR_INSTRUMENT): Emiten ini ({$ticker}) di luar 10 konstituen ML tervalidasi. Probabilitas dan confidence score berstatus N/A. DILARANG menyebut threshold Tau 0.70 atau keyakinan di bawah 40% seolah-olah inferensi model telah berjalan. Tegaskan bahwa model belum divalidasi out-of-sample untuk instrumen ini dan analisis murni deskriptif.\n";
        }

        $systemPrompt .= "3. STRUKTUR JAWABAN (Pisahkan fakta, interpretasi, dan skenario):\n"
            ."   • FAKTA PASAR TERLAPOR: Ringkasan pergerakan bar harga OHLC, volume, dan rentang harga.\n"
            ."   • INTERPRETASI MODEL & STATUS VALIDASI: Penjelasan status model (Abstain jika tervalidasi, atau N/A jika belum divalidasi).\n"
            ."   • SKENARIO DAMPAK & RISIKO: Potensi dinamika pasar tanpa rekomendasi beli/jual.\n"
            ."4. LIKUIDITAS: DILARANG menyimpulkan likuiditas tinggi hanya dari volume transaksi satu hari. Volume satu hari hanya menggambarkan aktivitas sesi terkait, bukan likuiditas konsisten tanpa data rata-rata multi-hari.\n"
            .'5. FORMAT: Tulis dalam bahasa Indonesia yang ringkas, profesional, tanpa karakter markdown literal (*) atau (_). Gunakan bullet point (•).';

        $userPrompt = "Berikut adalah evidence faktual dari database untuk emiten {$ticker}:\n"
            ."• Emiten: {$ticker}\n"
            ."• Tanggal Bar: {$barDateIndo} (EOD Final via {$obs->source})\n"
            .'• Harga Penutupan: Rp '.number_format($obs->close_price, 2, ',', '.')."\n"
            .'• OHLC: Open Rp '.number_format($obs->open_price, 2, ',', '.').' | High Rp '.number_format($obs->high_price, 2, ',', '.').' | Low Rp '.number_format($obs->low_price, 2, ',', '.')."\n"
            ."• Volume: {$volFmt} lembar\n"
            .'• Rentang Harga High-Low: Rp '.number_format($priceRange, 2, ',', '.')." ({$rangePct}% rentang bar)\n"
            ."• Perubahan vs Open: {$changeSign}Rp ".number_format($changeBar, 2, ',', '.')." ({$changeSign}{$changePct}% vs Open bar {$barDateIndo})\n";

        if ($sig && $isValidatedUniverse) {
            $pUpPct = round($sig->probability_up * 100, 2);
            $pDownPct = round($sig->probability_down * 100, 2);
            $confPct = round($sig->confidence_score * 100, 2);
            $userPrompt .= "• Model ML: {$sig->model_name} ({$sig->model_version})\n"
                ."• Sinyal ML: {$sig->signal_status} (Arah: {$sig->direction})\n"
                ."• Estimasi Probabilitas: P(UP) {$pUpPct}% | P(DOWN) {$pDownPct}%\n"
                ."• Confidence Score: {$confPct}% (Ambang batas minimal: 40,00% / Tau 0.70)\n"
                ."• Alasan Abstain: {$sig->reason_for_abstain}\n";
        } else {
            $userPrompt .= "• Model ML: MODEL_NOT_VALIDATED_FOR_INSTRUMENT\n"
                ."• Estimasi Probabilitas: N/A\n"
                ."• Confidence Score: N/A\n"
                ."• Status Model: Model prediktif belum divalidasi/dikalibrasi untuk emiten {$ticker}. Analisis murni observasi teknikal faktual deskriptif tanpa probabilitas spekulatif.\n";
        }

        if ($news) {
            $userPrompt .= '• Berita Terkini: ['.($news->source_name ?: 'LKBN ANTARA').'] '.$news->title.' — '.($news->summary ?: '')."\n";
        } else {
            $userPrompt .= "• Berita Terkini: Tidak ada berita tersimpan untuk emiten ini dalam 7 hari terakhir.\n";
        }

        if ($hasFund) {
            $peStr = $metric->pe_ratio !== null ? "{$metric->pe_ratio}x" : 'N/A (Rugi Bersih)';
            $pbvStr = $metric->pbv_ratio !== null ? "{$metric->pbv_ratio}x" : 'N/A';
            $derStr = $metric->der_ratio !== null ? "{$metric->der_ratio}x" : 'N/A';
            $userPrompt .= "• Data Fundamental: EPS Rp {$metric->eps} | BVPS Rp {$metric->bvps} | PER {$peStr} | PBV {$pbvStr} | DER {$derStr}\n"
                ."• Status Fundamental: CALCULATED (Lengkap)\n";
        } else {
            $userPrompt .= "• Data Fundamental: BELUM TERSEDIA pada penyedia data publik yfinance.\n"
                ."• Batasan Analisis: PARTIAL_TECHNICAL_ONLY. Laporan keuangan belum terunduh, analisis murni teknikal harga EOD dan indikator kuantitatif.\n";
        }

        $userPrompt .= "\nBerikan penjelasan analisis naratif terstruktur sesuai aturan di atas.";

        // 4. Call 9router endpoint
        $payload = [
            'model' => $this->modelId,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'max_tokens' => $this->maxTokens,
            'temperature' => 0.2,
            'stream' => false,
        ];

        $t0 = microtime(true);
        try {
            $req = Http::timeout(max(35, $this->timeout));
            if (! empty($this->apiKey)) {
                $req = $req->withToken($this->apiKey);
            }
            $resp = $req->post($this->endpoint, $payload);
            $latencyMs = (int) round((microtime(true) - $t0) * 1000);

            if ($resp->successful()) {
                $content = $resp->json('choices.0.message.content');
                $usage = $resp->json('usage');

                if (! empty($content)) {
                    // Create persistent DB record
                    $run = AnalysisRun::create([
                        'run_id' => 'ONDEMAND_'.$ticker.'_'.now()->format('Ymd_His'),
                        'trigger_type' => 'telegram_on_demand',
                        'status' => 'completed',
                        'started_at' => now()->subMilliseconds($latencyMs),
                        'completed_at' => now(),
                    ]);

                    if (! $ev && $obs) {
                        $rawEvPayload = [
                            'ticker' => $ticker,
                            'bar_date' => $barDateStr,
                            'observation_id' => $obs->id,
                            'close_price' => $obs->close_price,
                            'evidence_hash' => $evidenceHash,
                        ];
                        $ev = EvidenceSnapshot::updateOrCreate(
                            [
                                'ticker' => $ticker,
                                'data_hash' => $evidenceHash,
                            ],
                            [
                                'snapshot_id' => 'SNAP_ONDEMAND_'.$ticker.'_'.substr($evidenceHash, 0, 16),
                                'market_regime' => 'ONDEMAND_RESEARCH',
                                'decision_date' => $barDateStr,
                                'features_snapshot' => $rawEvPayload,
                                'data_hash' => $evidenceHash,
                            ]
                        );
                    }

                    $analysis = MarketAnalysis::create([
                        'analysis_run_id' => $run->id,
                        'prompt_template_id' => null,
                        'model_name' => $this->modelId,
                        'prompt_version' => '1.0.0-on-demand',
                        'market_regime_summary' => trim($content),
                        'impact_direction' => 'unclear',
                        'impact_horizon' => 'multi_day',
                        'confidence_score' => $sig ? $sig->confidence_score : 0.50,
                        'structured_output' => [
                            'ticker' => $ticker,
                            'bar_date' => $barDateStr,
                            'evidence_snapshot_id' => $ev ? $ev->id : ($sig ? $sig->evidence_snapshot_id : null),
                            'evidence_hash' => $evidenceHash,
                            'cache_key' => $cacheKey,
                            'analysis_type' => $analysisType,
                            'has_fundamental' => $hasFund,
                            'latency_ms' => $latencyMs,
                            'usage' => $usage,
                            'model_version' => $this->modelId,
                            'generated_narrative' => trim($content),
                        ],
                        'validation_status' => 'valid_schema',
                    ]);

                    return [
                        'success' => true,
                        'is_cached' => false,
                        'narrative' => trim($content),
                        'analysis_id' => $analysis->id,
                        'model' => $this->modelId,
                        'usage' => $usage,
                        'latency_ms' => $latencyMs,
                    ];
                }
            }

            return [
                'success' => false,
                'fallback_reason' => 'Router AI merespons dengan HTTP '.$resp->status(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'fallback_reason' => 'Koneksi ke AI router terputus: '.$e->getMessage(),
            ];
        }
    }
}
