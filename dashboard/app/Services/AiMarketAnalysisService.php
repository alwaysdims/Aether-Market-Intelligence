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
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
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

    protected string $provider;

    protected string $googleBaseUrl;

    protected int $maxRetries;

    protected ?string $configurationError = null;

    public function __construct()
    {
        $this->provider = app()->runningUnitTests() ? (string) config('services.dashboard_ai.provider', 'google') : 'consultation_ai';
        $this->googleBaseUrl = rtrim((string) config('services.dashboard_ai.base_url', 'https://generativelanguage.googleapis.com'), '/');
        $configuredModel = app()->runningUnitTests()
            ? config('services.dashboard_ai.model', 'gemini-3.6-flash')
            : config('services.consultation_ai.model_id');
        $this->modelId = is_string($configuredModel) ? trim($configuredModel) : '';
        if ($this->provider === 'consultation_ai' && $this->modelId === '') {
            $this->configurationError = 'AI_MODEL_NOT_CONFIGURED';
        }
        $this->endpoint = $this->provider === 'google'
            ? $this->googleBaseUrl.'/v1beta/models/'.rawurlencode($this->modelId).':generateContent'
            : rtrim((string) config('services.consultation_ai.endpoint'), '/').'/chat/completions';
        $this->apiKey = $this->provider === 'google'
            ? config('services.dashboard_ai.api_key')
            : (string) config('services.consultation_ai.api_key', '');
        $this->timeout = (int) config('services.consultation_ai.timeout', config('services.dashboard_ai.timeout', 45));
        $this->maxTokens = (int) config('services.dashboard_ai.max_output_tokens', 2500);
        $this->maxRetries = max(0, (int) config('services.consultation_ai.max_retries', 1));
        $this->liveInferenceEnabled = (bool) config('services.dashboard_ai.eod_inference_enabled', false);
        // On-demand Gemini is independent from ML/EOD inference gates.
        $this->onDemandEnabled = (bool) config('services.dashboard_ai.on_demand_enabled', false)
            || (bool) config('services.ai_router.on_demand_enabled', true);
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

        $result = $this->generateGoogleContent($systemPrompt, $fullPrompt, true);
        $analysisData = $result['ok'] ? $result['data'] : null;
        $validationStatus = $result['ok'] ? 'valid_schema' : $result['validation_status'];
        $fallbackReason = $result['ok'] ? '' : $result['reason'];
        if ($analysisData) {
            $analysisData['_usage'] = $result['usage'];
            $analysisData['_finish_reason'] = $result['finish_reason'];
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
            'confidence_score' => $analysisData['confidence_score'] ?? null,
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
            'confidence_score' => null,
            'impacted_tickers' => [],
            'verified_facts' => ['Data snapshot EOD diarsipkan secara otomatis'],
            'unverified_points' => [],
            'risks' => [$fallbackReason],
            'unknowns' => ['Arah likuiditas sesi berikutnya'],
            'fallback_reason' => $fallbackReason,
            'model_status' => 'NOT_RUN',
            'requested_model' => $this->modelId,
            'returned_model' => null,
            'effective_configuration_source' => 'services.consultation_ai (CONSULTATION_MODEL_ID/CONSULTATION_AI_ENDPOINT/CONSULTATION_AI_API_KEY)',
        ];

        return MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'prompt_template_id' => $template?->id,
            'model_name' => 'STRUCTURED_FALLBACK',
            'prompt_version' => $template ? $template->version : '1.0.0',
            'market_regime_summary' => $analysisData['market_regime_summary'],
            'impact_direction' => 'unclear',
            'impact_horizon' => 'multi_day',
            'confidence_score' => null,
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
        ?CompanyAnalysisMetric $metric = null,
        array $componentSnapshot = [],
        ?array $brokerSnapshot = null,
        ?string $researchRequestId = null,
        ?string $userQuestion = null
    ): array {
        $barDateStr = $obs->bar_date->format('Y-m-d');
        $fundamentalStatuses = data_get($componentSnapshot, 'bar.component_refresh.fundamental', data_get($componentSnapshot, 'bar_refresh.component_refresh.fundamental', data_get($componentSnapshot, 'fundamental', [])));
        $hasFundamentalComponent = is_array($fundamentalStatuses)
            && collect($fundamentalStatuses)->contains(fn ($item) => is_array($item) && ($item['status'] ?? null) === 'REFRESHED_API');
        $hasFund = ($metric && $metric->fundamental_status === 'CALCULATED') || $hasFundamentalComponent;
        $analysisType = $hasFund ? 'FUNDAMENTAL_AND_TECHNICAL_COMPONENTS_AVAILABLE' : 'PARTIAL_TECHNICAL_ONLY';
        $newsStatus = data_get($componentSnapshot, 'bar.component_refresh.news.status', data_get($componentSnapshot, 'bar_refresh.component_refresh.news.status', data_get($componentSnapshot, 'news.status', 'STATUS_UNKNOWN')));

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

        // Cache only within exact request evidence binding. Never reuse another request's analysis.
        $existing = $researchRequestId
            ? MarketAnalysis::whereJsonContains('structured_output->ticker', $ticker)
                ->whereJsonContains('structured_output->bar_date', $barDateStr)
                ->whereJsonContains('structured_output->research_request_id', $researchRequestId)
                ->whereJsonContains('structured_output->evidence_snapshot_id', $ev?->id)
                ->where('validation_status', 'valid_schema')
                ->latest('id')
                ->first()
            : null;

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
                    'requested_model' => $existing->structured_output['requested_model'] ?? $this->modelId,
                    'returned_model' => $existing->structured_output['returned_model'] ?? null,
                    'inference_telemetry' => $existing->structured_output['inference_telemetry'] ?? null,
                    'cache_key' => $cacheKey,
                    'evidence_hash' => $evidenceHash,
                    'usage' => $existing->structured_output['usage'] ?? null,
                    'latency_ms' => $existing->structured_output['latency_ms'] ?? 0,
                ];
            }
        }

        // 2. Check on-demand feature flag and unit testing mode
        if (app()->runningUnitTests()) {
            return [
                'success' => false,
                'fallback_reason' => 'TEST_ENVIRONMENT',
            ];
        }
        if (! $this->onDemandEnabled) {
            return [
                'success' => false,
                'fallback_reason' => 'DISABLED_BY_CONFIGURATION',
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

        $isValidatedUniverse = BeiUniverseRegistry::hasProductionMlInference($ticker);

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
        $systemPrompt .= "\n6. TRADING PLAN KONDISIONAL: Selalu sertakan trading plan bersyarat, meskipun pengguna tidak menulis kata trading plan. Wajib pisahkan Fakta Provider, Entry Condition, Area Entry, Cut Loss, Target, Risk/Reward, Horizon, Invalidasi, dan Status Model. Jika model belum tervalidasi, labeli CONDITIONAL_DESCRIPTIVE dan jangan beri sinyal beli/jual pasti. Jangan membuat level yang tidak ada di evidence. Rencana long wajib memenuhi entry > stop loss > 0, target di atas entry, risiko maksimal 5% dari batas entry terburuk, horizon maksimal lima hari bursa, serta mulai berlaku dan expiry. Jika tidak lolos, tulis Belum ada rencana yang memenuhi batas risiko dan alasan spesifik.";
        $systemPrompt .= "\n7. KEUANGAN: PER null hanya berarti PER tidak tersedia. Jangan menyebut rugi bersih tanpa akun laba/rugi, angka, dan periode. Bedakan laporan kuartal tunggal dan kumulatif/YTD. Gunakan mapping akun sesuai payload bank atau nonbank.";
        if ($userQuestion !== null && trim($userQuestion) !== '') {
            $systemPrompt .= "\n8. PERTANYAAN PENGGUNA: Jawab pertanyaan berikut secara eksplisit berdasarkan evidence: ".trim($userQuestion);
        }

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
        } elseif ($newsStatus === 'NOT_FETCHED') {
            $userPrompt .= "• Berita Terkini: NOT_FETCHED — sumber berita terautentikasi belum diambil. Jangan menyimpulkan berita tidak ada.\n";
        } elseif ($newsStatus === 'EMPTY_RESPONSE') {
            $userPrompt .= "• Berita Terkini: EMPTY_RESPONSE — provider merespons kosong. Ini bukan bukti semua berita tidak ada.\n";
        } elseif ($newsStatus === 'NO_RELEVANT_ITEMS_FOUND') {
            $userPrompt .= "• Berita Terkini: NO_RELEVANT_ITEMS_FOUND — pencarian provider selesai tanpa item relevan pada rentang yang dipakai.\n";
        } else {
            $userPrompt .= "• Berita Terkini: STATUS_UNKNOWN — ketersediaan berita tidak dapat dipastikan.\n";
        }

        if ($hasFund && $metric) {
            $peStr = $metric->pe_ratio !== null ? "{$metric->pe_ratio}x" : 'N/A (PER tidak tersedia; laba/rugi belum disimpulkan)';
            $pbvStr = $metric->pbv_ratio !== null ? "{$metric->pbv_ratio}x" : 'N/A';
            $derStr = $metric->der_ratio !== null ? "{$metric->der_ratio}x" : 'N/A';
            $userPrompt .= "• Data Fundamental Terhitung: EPS Rp {$metric->eps} | BVPS Rp {$metric->bvps} | PER {$peStr} | PBV {$pbvStr} | DER {$derStr}\n"
                ."• Status Fundamental: CALCULATED_FROM_COMPONENTS\n";
        } elseif ($hasFund) {
            $userPrompt .= "• Data Fundamental: tersedia pada snapshot provider. Gunakan nilai aktual dalam DATA KOMPONEN, jangan menyatakan laporan tidak tersedia.\n"
                ."• Status Fundamental: REFRESHED_API_COMPONENTS_AVAILABLE; rasio yang tidak tersedia tetap N/A.\n";
        } else {
            $userPrompt .= "• Data Fundamental: NOT_FETCHED_OR_UNAVAILABLE — jangan menyatakan laporan tidak tersedia tanpa status component snapshot.\n"
                ."• Batasan Analisis: PARTIAL_TECHNICAL_ONLY.\n";
        }

        $userPrompt .= "\nDATA KOMPONEN AKTUAL DAN STATUS PROVENANCE:\n";
        $userPrompt .= mb_substr(json_encode([
            'components' => $componentSnapshot,
            'broker' => $brokerSnapshot,
            'evidence_id' => $ev?->id,
            'evidence_hash' => $ev?->data_hash,
            'research_request_id' => $researchRequestId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 12000);
        $userPrompt .= "\nJangan menyebut komponen sebagai tersedia jika statusnya FETCH_FAILED atau NOT_FETCHED.\n";
        $userPrompt .= "\nBerikan penjelasan analisis naratif terstruktur sesuai aturan di atas.";

        // 4. Call official Google Gemini endpoint
        $t0 = microtime(true);
        $result = $this->generateGoogleContent($systemPrompt, $userPrompt, false);
        $latencyMs = (int) round((microtime(true) - $t0) * 1000);

        if ($result['ok']) {
            $content = $result['text'];
            $usage = $result['usage'];

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
                    'confidence_score' => $sig ? $sig->confidence_score : null,
                    'structured_output' => [
                        'ticker' => $ticker,
                        'bar_date' => $barDateStr,
                        'evidence_snapshot_id' => $ev ? $ev->id : ($sig ? $sig->evidence_snapshot_id : null),
                        'evidence_hash' => $evidenceHash,
                        'research_request_id' => $researchRequestId,
                        'cache_key' => $cacheKey,
                        'analysis_type' => $analysisType,
                        'has_fundamental' => $hasFund,
                        'latency_ms' => $latencyMs,
                        'usage' => $usage,
                        'requested_model' => $this->modelId,
                        'returned_model' => $result['returned_model'] ?? null,
                        'endpoint' => preg_replace('/:\/\/[^\/]+/', '://[LOCAL]', $this->endpoint),
                        'effective_configuration_source' => 'services.consultation_ai (CONSULTATION_MODEL_ID/CONSULTATION_AI_ENDPOINT/CONSULTATION_AI_API_KEY)',
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
                    'requested_model' => $result['requested_model'] ?? $this->modelId,
                    'returned_model' => $result['returned_model'] ?? null,
                    'inference_telemetry' => $result['telemetry'] ?? null,
                    'usage' => $usage,
                    'latency_ms' => $latencyMs,
                ];
            }
        }

        $reason = (string) ($result['reason'] ?? 'INFERENCE_UNAVAILABLE');
        $fallbackReason = $this->classifyTradingPlanUnavailability($reason);
        $inferenceTelemetry = $result['telemetry'] ?? null;
        $reasonText = $fallbackReason['display'];
        $narrative = implode("\n", [
            "FAKTA PASAR TERLAPOR — {$ticker}",
            '• Bar EOD: '.$obs->bar_date->toDateString().' via '.$obs->source,
            '• OHLC: Open '.$obs->open_price.' | High '.$obs->high_price.' | Low '.$obs->low_price.' | Close '.$obs->close_price,
            '• Volume sesi: '.$obs->volume.' lembar',
            'INTERPRETASI MODEL & STATUS VALIDASI',
            '• Narasi LLM tidak tersedia. Analisis ini hanya fallback terstruktur berbasis evidence tersimpan.',
            '• Model ML: MODEL_NOT_VALIDATED_FOR_INSTRUMENT; status ini terpisah dari analisis deskriptif.',
            'SKENARIO TRADING BERSYARAT',
            '• Belum ada rencana yang memenuhi batas risiko: '.$reasonText,
            '• Fallback timeout/gagal tidak membuktikan tidak ada rencana yang layak.',
            '• Support/resistance tetap ditampilkan sebagai referensi historis, bukan otomatis cut loss.',
        ]);
        $run = AnalysisRun::create([
            'run_id' => 'ONDEMAND_FALLBACK_'.$ticker.'_'.now()->format('Ymd_Hisv'),
            'trigger_type' => 'dashboard_on_demand',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $analysis = MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'prompt_template_id' => null,
            'model_name' => 'STRUCTURED_FALLBACK',
            'prompt_version' => '1.0.0-on-demand-fallback',
            'market_regime_summary' => $narrative,
            'impact_direction' => 'unclear',
            'impact_horizon' => 'multi_day',
            'confidence_score' => null,
            'structured_output' => [
                'ticker' => $ticker,
                'bar_date' => $obs->bar_date->toDateString(),
                'evidence_snapshot_id' => $ev?->id,
                'research_request_id' => $researchRequestId,
                'fallback_reason' => $reason,
                'requested_model' => $this->modelId !== '' ? $this->modelId : null,
                'returned_model' => null,
                'trading_plan_unavailability' => $fallbackReason,
                'inference_telemetry' => $inferenceTelemetry,
                'configuration_source' => 'services.consultation_ai (CONSULTATION_MODEL_ID/CONSULTATION_AI_ENDPOINT/CONSULTATION_AI_API_KEY)',
                'generated_narrative' => $narrative,
                'model_status' => 'NOT_RUN',
                'returned_model' => null,
                'endpoint' => preg_replace('/:\/\/[^\/]+/', '://[LOCAL]', $this->endpoint),
                'effective_configuration_source' => 'services.consultation_ai (CONSULTATION_MODEL_ID/CONSULTATION_AI_ENDPOINT/CONSULTATION_AI_API_KEY)',
                'metric_status' => $metric ? 'AVAILABLE' : 'NOT_AVAILABLE',
            ],
            'validation_status' => 'fallback',
        ]);

        return [
            'success' => true,
            'is_cached' => false,
            'narrative' => $narrative,
            'analysis_id' => $analysis->id,
            'model' => 'STRUCTURED_FALLBACK',
            'requested_model' => $this->modelId,
            'returned_model' => null,
            'fallback_reason' => $reason,
            'trading_plan_unavailability' => $fallbackReason,
            'inference_telemetry' => $inferenceTelemetry,
            'usage' => null,
            'latency_ms' => is_array($inferenceTelemetry) ? ($inferenceTelemetry['duration_ms'] ?? null) : null,
            'fallback_latency_ms' => 0,
        ];
    }

    /** @return array{code: string, display: string} */
    protected function classifyTradingPlanUnavailability(string $reason): array
    {
        $normalized = strtoupper($reason);
        if (str_contains($normalized, 'TIMEOUT') || str_contains($normalized, 'CONNECTION') || str_contains($normalized, 'HTTP')) {
            return ['code' => 'INFERENCE_UNAVAILABLE', 'display' => 'inference timeout/gagal; analisis AI belum selesai'];
        }
        if (str_contains($normalized, 'INSUFFICIENT') || str_contains($normalized, 'NOT_ENOUGH') || str_contains($normalized, 'DATA')) {
            return ['code' => 'DATA_INSUFFICIENT', 'display' => 'data tidak cukup; komponen pendukung belum lengkap'];
        }
        if (str_contains($normalized, 'RISK') || str_contains($normalized, 'VALIDATOR')) {
            return ['code' => 'PLAN_REJECTED_RISK', 'display' => 'rencana dihasilkan tetapi ditolak validator karena risiko melebihi 5%'];
        }
        if (str_contains($normalized, 'NO_SETUP') || str_contains($normalized, 'NO SETUP')) {
            return ['code' => 'AI_NO_SETUP', 'display' => 'AI menilai tidak ada setup yang memenuhi syarat'];
        }

        return ['code' => 'INFERENCE_UNAVAILABLE', 'display' => 'inference gagal; analisis AI belum selesai'];
    }

    /** @return array<string, mixed> */
    protected function generateGoogleContent(string $systemPrompt, string $userPrompt, bool $json): array
    {
        if ($this->provider === 'consultation_ai') {
            $baseTelemetry = [
                'requested_model' => $this->modelId !== '' ? $this->modelId : null,
                'returned_model' => null,
                'endpoint' => preg_replace('/:\/\/[^\/]+/', '://[LOCAL]', $this->endpoint),
                'configuration_source' => 'services.consultation_ai',
            ];
            if ($this->configurationError !== null) {
                return [
                    'ok' => false,
                    'validation_status' => 'blocked',
                    'reason' => $this->configurationError,
                    'requested_model' => null,
                    'returned_model' => null,
                    'usage' => null,
                    'finish_reason' => null,
                    'telemetry' => $baseTelemetry,
                ];
            }
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
            $correlationId = 'AI_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(4));
            $startedAt = microtime(true);
            $requestBytes = strlen((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $inputChars = mb_strlen((string) ($systemPrompt.$userPrompt));
            $deadlineAt = $startedAt + $this->timeout;
            $timing = ['connect_ms' => null, 'first_byte_ms' => null, 'total_ms' => null];
            try {
                $request = Http::timeout($this->timeout)
                    ->withOptions(['on_stats' => function (TransferStats $stats) use (&$timing, $startedAt): void {
                        $timing['total_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
                        $handlerStats = $stats->getHandlerStats();
                        $timing['connect_ms'] = isset($handlerStats['connect_time']) ? (int) round($handlerStats['connect_time'] * 1000) : null;
                        $timing['first_byte_ms'] = isset($handlerStats['starttransfer_time']) ? (int) round($handlerStats['starttransfer_time'] * 1000) : null;
                    }])
                    ->acceptJson()
                    ->asJson()
                    ->withHeaders(['X-Aether-Correlation-ID' => $correlationId]);
                if ($this->apiKey !== '') {
                    $request = $request->withToken($this->apiKey);
                }
                $response = $request->post($this->endpoint, $payload);
                $transport = [
                    'correlation_id' => $correlationId,
                    'started_at_utc' => gmdate('c', (int) $startedAt),
                    'deadline_at_utc' => gmdate('c', (int) $deadlineAt),
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'http_status' => $response->status(),
                    'curl_errno' => 'NOT_AVAILABLE_HTTP_CLIENT',
                    'curl_error' => null,
                    'connect_ms' => $timing['connect_ms'],
                    'first_byte_ms' => $timing['first_byte_ms'],
                    'total_ms' => $timing['total_ms'],
                    'request_bytes' => $requestBytes,
                    'input_chars' => $inputChars,
                    'output_bytes' => $response->body() !== '' ? strlen($response->body()) : 0,
                    'usage' => $response->json('usage'),
                    'requested_model' => $this->modelId,
                    'returned_model' => $response->json('model'),
                ];
                Log::info('[AI_CONSULTATION_PROBE]', $transport);
                if (! $response->successful()) {
                    $status = $response->status();
                    $reason = match (true) {
                        $status === 401 || $status === 403 => 'AUTH_FAILED',
                        $status === 404 => 'MODEL_NOT_FOUND',
                        $status === 408 || $status === 504 => 'TIMEOUT',
                        $status === 429 => 'RATE_LIMITED',
                        $status >= 400 && $status < 500 => 'PROTOCOL_OR_CONFIGURATION_ERROR',
                        default => 'PROVIDER_ERROR',
                    };
                    $providerMessage = trim((string) $response->json('error.message', ''));
                    $sanitizedMessage = $providerMessage !== ''
                        ? preg_replace('/(api[_ -]?key|authorization|bearer|token)\s*[:=]?\s*[^\s,;]+/i', '$1=[REDACTED]', $providerMessage)
                        : null;

                    return [
                        'ok' => false,
                        'validation_status' => 'fallback',
                        'reason' => $reason,
                        'provider_error' => $sanitizedMessage,
                        'requested_model' => $this->modelId,
                        'returned_model' => $transport['returned_model'],
                        'usage' => null,
                        'finish_reason' => null,
                        'telemetry' => $transport,
                    ];

                }
                $text = $response->json('choices.0.message.content');
                if (! is_string($text) || trim($text) === '') {
                    return [
                        'ok' => false,
                        'validation_status' => 'invalid_schema',
                        'reason' => 'INVALID_RESPONSE',
                        'requested_model' => $this->modelId,
                        'returned_model' => $transport['returned_model'],
                        'usage' => $response->json('usage'),
                        'finish_reason' => $response->json('choices.0.finish_reason'),
                        'telemetry' => $transport,
                    ];
                }

                return [
                    'ok' => true,
                    'data' => null,
                    'text' => trim($text),
                    'usage' => $response->json('usage'),
                    'finish_reason' => $response->json('choices.0.finish_reason'),
                    'requested_model' => $this->modelId,
                    'returned_model' => $response->json('model'),
                    'telemetry' => $transport,
                ];
            } catch (\Throwable $e) {
                Log::warning('AI router on-demand request failed: '.$e::class, [
                    'correlation_id' => $correlationId,
                    'started_at_utc' => gmdate('c', (int) $startedAt),
                    'deadline_at_utc' => gmdate('c', (int) $deadlineAt),
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'exception_class' => get_class($e),
                    'previous_exception_class' => $e->getPrevious() ? get_class($e->getPrevious()) : null,
                    'http_status' => null,
                    'curl_errno' => 'NOT_AVAILABLE_HTTP_CLIENT',
                    'curl_error' => preg_replace('/(api[_ -]?key|authorization|bearer|token)\s*[:=]?\s*[^\s,;]+/i', '$1=[REDACTED]', $e->getMessage()),
                    'connect_ms' => $timing['connect_ms'],
                    'first_byte_ms' => $timing['first_byte_ms'],
                    'total_ms' => $timing['total_ms'] ?? (int) round((microtime(true) - $startedAt) * 1000),
                    'request_bytes' => $requestBytes,
                    'input_chars' => $inputChars,
                    'output_bytes' => 0,
                    'usage' => null,
                    'requested_model' => $this->modelId,
                    'returned_model' => null,
                ]);
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                $reason = $e instanceof ConnectionException ? 'UPSTREAM_CONNECTION_FAILURE_UNCLASSIFIED' : 'UPSTREAM_EXCEPTION';

                return ['ok' => false, 'validation_status' => 'fallback', 'reason' => $reason, 'provider_error' => preg_replace('/(api[_ -]?key|authorization|bearer|token)\s*[:=]?\s*[^\s,;]+/i', '$1=[REDACTED]', $e->getMessage()), 'requested_model' => $this->modelId, 'returned_model' => null, 'usage' => null, 'finish_reason' => null, 'telemetry' => [
                    'correlation_id' => $correlationId,
                    'started_at_utc' => gmdate('c', (int) $startedAt),
                    'deadline_at_utc' => gmdate('c', (int) $deadlineAt),
                    'duration_ms' => $durationMs,
                    'http_status' => null,
                    'connect_ms' => $timing['connect_ms'],
                    'first_byte_ms' => $timing['first_byte_ms'],
                    'total_ms' => $timing['total_ms'] ?? $durationMs,
                    'request_bytes' => $requestBytes,
                    'input_chars' => $inputChars,
                    'output_bytes' => 0,
                    'usage' => null,
                    'requested_model' => $this->modelId,
                    'returned_model' => null,
                    'exception_class' => get_class($e),
                    'previous_exception_class' => $e->getPrevious() ? get_class($e->getPrevious()) : null,
                    'error' => preg_replace('/(api[_ -]?key|authorization|bearer|token)\s*[:=]?\s*[^\s,;]+/i', '$1=[REDACTED]', $e->getMessage()),
                ]];
            }
        }
        if ($this->provider !== 'google') {
            return ['ok' => false, 'validation_status' => 'blocked', 'reason' => 'Provider dashboard AI tidak didukung.', 'usage' => null, 'finish_reason' => null];
        }
        if (empty($this->apiKey)) {
            return ['ok' => false, 'validation_status' => 'blocked', 'reason' => 'GEMINI_API_KEY belum dikonfigurasi.', 'usage' => null, 'finish_reason' => null];
        }

        $generationConfig = ['temperature' => 0.2, 'maxOutputTokens' => $this->maxTokens];
        if ($json) {
            $generationConfig['responseMimeType'] = 'application/json';
        }
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
            'generationConfig' => $generationConfig,
        ];

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders(['x-goog-api-key' => $this->apiKey, 'Content-Type' => 'application/json'])
                    ->post($this->endpoint, $payload);
            } catch (\Throwable $e) {
                if ($attempt < $this->maxRetries) {
                    usleep((int) (250000 * (2 ** $attempt)));

                    continue;
                }
                Log::warning('Google Gemini request failed: '.$e::class);

                return ['ok' => false, 'validation_status' => 'fallback', 'reason' => 'Google Gemini timeout atau koneksi gagal.', 'usage' => null, 'finish_reason' => null];
            }

            $status = $response->status();
            if ($response->successful()) {
                $candidate = $response->json('candidates.0');
                $text = $response->json('candidates.0.content.parts.0.text');
                $finish = $candidate['finishReason'] ?? null;
                if (! is_string($text) || trim($text) === '') {
                    return ['ok' => false, 'validation_status' => 'invalid_schema', 'reason' => 'Respons Google Gemini kosong atau terblokir.', 'usage' => $response->json('usageMetadata'), 'finish_reason' => $finish];
                }
                if ($json) {
                    $data = json_decode($text, true);
                    if (! is_array($data) || empty($data['market_regime_summary']) || ! is_string($data['market_regime_summary'])) {
                        return ['ok' => false, 'validation_status' => 'invalid_schema', 'reason' => 'JSON Google Gemini tidak memenuhi schema dashboard.', 'usage' => $response->json('usageMetadata'), 'finish_reason' => $finish];
                    }

                    return ['ok' => true, 'data' => $data, 'text' => $text, 'usage' => $response->json('usageMetadata'), 'finish_reason' => $finish];
                }

                return ['ok' => true, 'data' => null, 'text' => $text, 'usage' => $response->json('usageMetadata'), 'finish_reason' => $finish];
            }

            if (! in_array($status, [408, 429, 500, 502, 503, 504], true) || $attempt >= $this->maxRetries) {
                Log::warning('Google Gemini HTTP failure', ['status' => $status]);

                return ['ok' => false, 'validation_status' => 'fallback', 'reason' => "Google Gemini HTTP {$status}.", 'usage' => null, 'finish_reason' => null];
            }
            usleep((int) (250000 * (2 ** $attempt)));
        }

        return ['ok' => false, 'validation_status' => 'fallback', 'reason' => 'Google Gemini retry exhausted.', 'usage' => null, 'finish_reason' => null];
    }
}
