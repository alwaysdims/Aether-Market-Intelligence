<?php

namespace App\Services;

use App\Models\CompanyFundamental;
use App\Models\EvidenceSnapshot;
use App\Models\MarketAnalysis;
use App\Models\MarketObservation;
use App\Models\NewsItem;
use App\Models\PredictionSignal;
use App\Models\ResearchRequest;
use App\Models\TelegramOutbox;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OnDemandResearchService
{
    public function __construct(
        protected BeiUniverseRegistry $universeRegistry,
        protected DataFreshnessPolicyService $freshnessService,
        protected OnDemandDataCollectorService $collectorService,
        protected TechnicalMetricsCalculationService $metricsService,
        protected BrokerSummaryIngestionService $brokerService,
        protected AiMarketAnalysisService $aiService,
        protected TelegramFormattingService $formatter
    ) {}

    /**
     * Handle incoming user research request.
     */
    public function handleResearchQuery(
        string $text,
        string $userId,
        string $chatId,
        ?string $messageId = null
    ): array {
        // 1. Parse Request Contract (Section B)
        $contract = $this->universeRegistry->parseRequestContract($text, $userId, $chatId, $messageId);

        if (! ($contract['valid'] ?? false)) {
            return [
                'handled' => true,
                'is_async' => false,
                'intent' => 'INVALID_OR_FOREIGN_TICKER',
                'reply_text' => $contract['message'] ?? 'Kode emiten tidak dikenal.',
            ];
        }

        $ticker = $contract['ticker'];
        $companyName = $contract['company_name'];
        $asOf = $contract['as_of_time'];

        // ponytail: prevent duplicate concurrent runs; link repeated query to active job
        $activeReq = ResearchRequest::where('ticker', $ticker)
            ->whereIn('status', ['pending', 'collecting', 'analyzing'])
            ->latest('id')
            ->first();

        if ($activeReq) {
            return [
                'handled' => true,
                'is_async' => true,
                'intent' => 'ON_DEMAND_RESEARCH_ALREADY_ACTIVE',
                'reply_text' => "Riset on-demand untuk emiten {$ticker} ({$companyName}) sedang diproses.\n"
                    ."• Request ID Aktif: {$activeReq->request_id}\n"
                    ."• Status: {$activeReq->status}\n"
                    .'Hasil analisis akan dikirimkan otomatis setelah proses validasi selesai.',
                'research_request_id' => $activeReq->request_id,
            ];
        }

        // 2. Assess Data Freshness (Section C)
        $freshness = $this->freshnessService->assessFreshness($ticker, $contract['data_type_requested'], $asOf);

        // Generate unique Request ID
        $requestId = 'REQ_'.now('Asia/Jakarta')->format('Ymd_His')."_{$ticker}_".Str::random(4);

        $researchReq = ResearchRequest::create([
            'request_id' => $requestId,
            'chat_id' => $chatId,
            'user_id' => $userId,
            'inbound_message_id' => $messageId,
            'ticker' => $ticker,
            'company_name' => $companyName,
            'exchange' => $contract['exchange'],
            'analysis_type' => $contract['analysis_type'],
            'data_type_requested' => $contract['data_type_requested'],
            'horizon' => $contract['horizon'],
            'as_of_time' => $asOf,
            'status' => 'pending',
            'freshness_classification' => $freshness['status'],
            'research_metadata' => [
                'query_text' => $text,
                'freshness_reason' => $freshness['reason'] ?? null,
                'is_ml_validated' => $contract['is_ml_validated'],
                'ml_status' => 'NOT_ACTIVE_ARTIFACT_OR_RECEIPT_MISSING',
                'started_at' => now('Asia/Jakarta')->toIso8601String(),
                'pipeline_stage' => 'queued',
            ],
        ]);

        // 3. If Data is already Fresh in DB, process immediately (Section C)
        if ($freshness['status'] === 'AVAILABLE_FRESH' && ! ($freshness['requires_fetch'] ?? false)) {
            $observation = $freshness['latest_obs'];
            $evidence = EvidenceSnapshot::where('ticker', $ticker)
                ->whereDate('decision_date', $observation->bar_date->toDateString())
                ->where('features_snapshot->research_request_id', $requestId)
                ->first();
            // Fresh legacy evidence is not safe for this request. Do not attach by latest ID.
            if (! $evidence) {
                $researchReq->update([
                    'status' => 'partial',
                    'error_reason' => 'EVIDENCE_MISSING_REQUEST_SCOPED',
                    'research_metadata' => array_merge($researchReq->research_metadata ?? [], [
                        'warning' => 'Fresh observation exists, but no request-scoped evidence snapshot exists.',
                    ]),
                ]);

                return [
                    'handled' => true,
                    'is_async' => false,
                    'intent' => 'ON_DEMAND_RESEARCH_PARTIAL',
                    'reply_text' => "Data EOD {$ticker} tersedia, tetapi evidence request-scoped belum tersimpan. Analisis belum dapat dinyatakan selesai.",
                    'research_request_id' => $requestId,
                ];
            }
            $researchReq->update([
                'status' => 'completed',
                'evidence_snapshot_id' => $evidence->id,
            ]);

            return [
                'handled' => true,
                'is_async' => false,
                'intent' => 'ON_DEMAND_RESEARCH_COMPLETED',
                'reply_text' => $this->formatResearchResponse($researchReq, $observation, null, $evidence, null, [
                    'success' => false,
                ]),
                'research_request_id' => $requestId,
            ];
        }

        // 4. Asynchronous Path: Send immediate acknowledgement, spawn background execution (Section G)
        $ackText = "Permintaan riset data on-demand untuk emiten {$ticker} ({$companyName}) telah diterima.\n"
            ."• Request ID: {$requestId}\n"
            ."• Status Data: {$freshness['status']}\n"
            .'• Keterangan: '.($freshness['reason'] ?? 'Mengambil data bursa terkini')."\n\n"
            .'Sedang mengambil data pasar resmi (Yahoo Finance / BEI) dan berita terkait. Hasil analisis lengkap akan dikirimkan otomatis setelah proses validasi selesai.';

        // Spawn background worker process
        $this->dispatchAsyncWorker($requestId);

        return [
            'handled' => true,
            'is_async' => true,
            'intent' => 'ON_DEMAND_RESEARCH_QUEUED',
            'reply_text' => $ackText,
            'research_request_id' => $requestId,
        ];
    }

    /**
     * Execute full research pipeline for a given request.
     */
    public function executeResearch(ResearchRequest $req): array
    {
        $ticker = $req->ticker;
        $companyName = $req->company_name ?? $ticker;

        $t0 = microtime(true);
        $metadata = $req->research_metadata ?? [];

        try {
            $deadlineAt = data_get($metadata, 'deadline_at');
            if ($deadlineAt && now('UTC')->greaterThan(Carbon::parse($deadlineAt)->utc())) {
                $timeoutText = "Batas waktu riset {$req->request_id} terlampaui sebelum pengambilan data selesai.";
                $req->update(['status' => 'failed', 'error_reason' => 'RESEARCH_TIMEOUT']);
                $this->queueOutboxReply($req, $timeoutText);

                return ['success' => false, 'status' => 'failed', 'reply_text' => $timeoutText];
            }

            // Step A: Collect & Store Data (if missing or stale)
            $metadata['pipeline_stage'] = 'data_check';
            $req->update(['status' => 'collecting', 'research_metadata' => $metadata]);
            $collectRes = $this->collectorService->collectAndStore($ticker, $req->request_id);

            if (! ($collectRes['success'] ?? false)) {
                $errType = $collectRes['error_type'] ?? 'COLLECTION_FAILED';
                $errMsg = $collectRes['message'] ?? 'Gagal mengambil data dari provider.';

                $req->update([
                    'status' => 'failed',
                    'error_reason' => $errType,
                    'research_metadata' => array_merge($metadata, [
                        'failed_at' => now('Asia/Jakarta')->toIso8601String(),
                        'error_message' => $errMsg,
                    ]),
                ]);

                $failText = "Gagal memproses riset on-demand untuk {$ticker} ({$companyName}).\n"
                    ."• Request ID: {$req->request_id}\n"
                    ."• Alasan: {$errMsg}\n"
                    ."• Kategori Error: {$errType}\n"
                    .'Data pasar tidak dapat diverifikasi secara sah dari sumber publik resmi.';

                $this->queueOutboxReply($req, $failText);

                return [
                    'success' => false,
                    'status' => 'failed',
                    'reply_text' => $failText,
                ];
            }

            $obs = $collectRes['observation'];
            $evidence = $collectRes['evidence'];
            $news = $collectRes['news'] ?? null;

            $metadata['pipeline_stage'] = 'indicators';
            $req->update(['research_metadata' => $metadata]);
            $metric = null;
            $metricStatus = 'NOT_LOADED';
            try {
                $metric = $this->metricsService->calculateForTicker($ticker, $obs->bar_date->toDateString());
                $metricStatus = $metric ? 'UPDATED' : 'INSUFFICIENT_INPUT';
            } catch (\Throwable $metricError) {
                $metricStatus = 'FETCH_FAILED';
                Log::warning("[OnDemandResearch] Metric refresh failed for {$ticker}: ".$metricError->getMessage());
            }

            $brokerStatus = 'NOT_LOADED';
            $brokerReason = null;
            $broker = null;
            try {
                $broker = $this->brokerService->syncDailySummary($ticker, $obs->bar_date->toDateString(), 'all', 50);
                $brokerStatus = ($broker['success'] ?? false) ? 'UPDATED' : 'FETCH_FAILED';
                $brokerReason = $broker['message'] ?? null;
            } catch (\Throwable $brokerError) {
                $brokerStatus = 'FETCH_FAILED';
                $brokerReason = $brokerError->getMessage();
                Log::warning("[OnDemandResearch] Broker refresh failed for {$ticker}: ".$brokerReason);
            }
            $adjustedCanonical = MarketObservation::where('ticker', $ticker)
                ->whereDate('bar_date', $obs->bar_date->toDateString())
                ->where('interval', '1d')
                ->where('data_classification', 'PUBLIC_MARKET_EOD')
                ->where('source', 'IDX Edge PRO')
                ->where('board_scope', 'RG')
                ->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')
                ->where('validation_status', 'VALID')
                ->first();
            $canonicalComponents = [
                'raw' => [
                    'status' => ($obs->adjustment_mode === 'UNADJUSTED' && $obs->validation_status === 'VALID') ? 'VALID' : 'NOT_VALID',
                    'id' => $obs->id,
                    'research_request_id' => $obs->research_request_id,
                    'date' => $obs->bar_date->toDateString(),
                    'source' => $obs->source,
                    'board_scope' => $obs->board_scope,
                    'interval' => $obs->interval,
                    'data_classification' => $obs->data_classification,
                    'adjustment_mode' => $obs->adjustment_mode,
                    'validation_status' => $obs->validation_status,
                    'data_hash' => $obs->data_hash,
                    'raw_payload_path' => $obs->raw_payload_path,
                    'raw_payload_hash' => $obs->raw_payload_hash,
                ],
                'adjusted' => [
                    'status' => $adjustedCanonical ? 'VALID' : 'MISSING',
                    'id' => $adjustedCanonical?->id,
                    'research_request_id' => $adjustedCanonical?->research_request_id,
                    'date' => $adjustedCanonical?->bar_date?->toDateString(),
                    'source' => $adjustedCanonical?->source,
                    'board_scope' => $adjustedCanonical?->board_scope,
                    'interval' => $adjustedCanonical?->interval,
                    'data_classification' => $adjustedCanonical?->data_classification,
                    'adjustment_mode' => $adjustedCanonical?->adjustment_mode ?? 'PROVIDER_BACK_ADJUSTED',
                    'validation_status' => $adjustedCanonical?->validation_status,
                    'data_hash' => $adjustedCanonical?->data_hash,
                    'raw_payload_path' => $adjustedCanonical?->raw_payload_path,
                    'raw_payload_hash' => $adjustedCanonical?->raw_payload_hash,
                ],
            ];
            $fundamentalStatuses = array_values(array_filter(array_map(
                static fn (mixed $item): ?string => is_array($item) ? ($item['status'] ?? null) : null,
                $collectRes['bar_refresh']['component_refresh']['fundamental'] ?? []
            )));
            $insiderStatus = data_get($collectRes, 'bar_refresh.component_refresh.insider.status');
            $newsStatus = data_get($collectRes, 'bar_refresh.component_refresh.news.status');
            $fullyVerified = $canonicalComponents['raw']['status'] === 'VALID'
                && $canonicalComponents['adjusted']['status'] === 'VALID'
                && $canonicalComponents['adjusted']['raw_payload_hash'] !== null
                && $metricStatus === 'UPDATED'
                && $brokerStatus === 'UPDATED'
                && $fundamentalStatuses !== []
                && ! in_array('FETCH_FAILED', $fundamentalStatuses, true)
                && $insiderStatus === 'REFRESHED_API'
                && $newsStatus === 'REFRESHED_API';
            $metadata['refresh'] = [
                'bar' => array_merge([
                    'status' => 'UPDATED',
                    'date' => $obs->bar_date->toDateString(),
                    'source' => $obs->source,
                    'raw_payload_path' => $obs->raw_payload_path,
                    'raw_payload_hash' => $obs->raw_payload_hash,
                ], $collectRes['bar_refresh'] ?? []),
                'metrics' => [
                    'status' => $metricStatus,
                    'date' => $metric?->as_of_date?->toDateString(),
                    'close' => $metric?->close_price,
                    'sma_20' => $metric?->sma_20,
                    'sma_50' => $metric?->sma_50,
                    'sma_200' => $metric?->sma_200,
                    'rsi_14' => $metric?->rsi_14,
                    'macd' => $metric?->macd,
                    'macd_signal' => $metric?->macd_signal,
                    'macd_histogram' => $metric?->macd_hist,
                    'atr_14' => $metric?->atr_14,
                    'support' => $metric?->support_price,
                    'resistance' => $metric?->resistance_price,
                    'timeframe' => '1d',
                    'provenance' => $metric?->data_quality_flags,
                ],
                'broker' => ['status' => $brokerStatus, 'date' => $obs->bar_date->toDateString(), 'reason' => $brokerReason],
                'canonical_components' => $canonicalComponents,
            ];
            $metadata['completion_scope'] = $fullyVerified ? 'FULL_COMPONENTS' : 'PARTIAL_COMPONENTS';
            $metadata['fully_verified'] = $fullyVerified;
            $metadata['verification_status'] = $fullyVerified ? 'FULLY_VERIFIED' : 'PARTIAL_NOT_FULLY_VERIFIED';
            if ($evidence) {
                $evidenceFeatures = $evidence->features_snapshot ?? [];
                $evidenceFeatures['canonical_components'] = $canonicalComponents;
                $evidenceFeatures['metrics_provenance'] = $metric?->data_quality_flags;
                $evidenceFeatures['component_refresh'] = data_get($collectRes, 'bar_refresh.component_refresh', data_get($evidenceFeatures, 'component_refresh', []));
                $evidenceFeatures['broker_refresh'] = [
                    'status' => $brokerStatus,
                    'date' => $obs->bar_date->toDateString(),
                    'reason' => $brokerReason,
                ];
                $evidenceFeatures['refresh_completed_at'] = now('UTC')->toIso8601String();
                $evidenceFeatures['verification_status'] = $metadata['verification_status'];
                $evidence->update(['features_snapshot' => $evidenceFeatures]);
            }
            $metadata['expected_date'] = $obs->bar_date->toDateString();
            $metadata['freshness_classification'] = $obs->validation_status === 'VALID'
                ? 'AVAILABLE_FRESH'
                : $req->freshness_classification;
            $req->update([
                'freshness_classification' => $metadata['freshness_classification'],
                'research_metadata' => $metadata,
            ]);

            if ($deadlineAt && now('UTC')->greaterThan(Carbon::parse($deadlineAt)->utc())) {
                throw new \RuntimeException('RESEARCH_TIMEOUT');
            }

            // Step B: Model & AI Narrative Analysis
            $metadata['pipeline_stage'] = 'synthesis';
            $req->update([
                'status' => 'analyzing',
                'research_metadata' => $metadata,
                'evidence_snapshot_id' => $evidence?->id,
            ]);

            // Check if ticker has prediction signal
            $sig = PredictionSignal::where('ticker', $ticker)->latest('decision_date')->first();

            $aiResult = $this->aiService->generateOnDemandTickerExplanation(
                $ticker,
                $obs,
                $sig,
                $evidence,
                $news,
                $metric,
                data_get($metadata, 'refresh', []),
                $broker,
                $req->request_id
            );

            if (! ($aiResult['success'] ?? false)) {
                $aiResult = $this->aiService->buildOnDemandFallback(
                    $ticker,
                    $obs,
                    $evidence,
                    $metric,
                    $aiResult['fallback_reason'] ?? 'Inferensi naratif tidak tersedia.',
                    $req->request_id
                );
            }

            $analysisId = $aiResult['analysis_id'] ?? null;
            $analysis = $analysisId ? MarketAnalysis::find($analysisId) : null;
            $analysisEvidenceId = $analysis?->structured_output['evidence_snapshot_id'] ?? null;
            $analysisRequestId = $analysis?->structured_output['research_request_id'] ?? null;
            $linkMatches = $evidence
                && $analysis
                && (int) $evidence->id === (int) $req->evidence_snapshot_id
                && (int) $evidence->id === (int) $analysisEvidenceId
                && $analysisRequestId === $req->request_id;
            if (! $linkMatches) {
                $req->update([
                    'status' => 'failed',
                    'error_reason' => 'INTERNAL_EVIDENCE_LINK_MISMATCH',
                    'research_metadata' => array_merge($metadata, [
                        'evidence_link_mismatch' => [
                            'request_evidence_id' => $req->evidence_snapshot_id,
                            'pipeline_evidence_id' => $evidence?->id,
                            'analysis_evidence_id' => $analysisEvidenceId,
                            'analysis_request_id' => $analysisRequestId,
                        ],
                    ]),
                ]);
                throw new \RuntimeException('INTERNAL_EVIDENCE_LINK_MISMATCH');
            }
            $req->market_analysis_id = $analysisId;

            // Step C: Format Output Cleanly
            $replyText = $this->formatResearchResponse($req, $obs, $sig, $evidence, $news, $aiResult);

            $finalStatus = 'completed';
            $errorReason = null;
            if ($req->analysis_type === 'fundamental') {
                $hasFund = CompanyFundamental::where('ticker', $ticker)->exists()
                    || collect(data_get($metadata, 'refresh.bar_refresh.component_refresh.fundamental', []))
                        ->contains(fn ($item) => is_array($item) && ($item['status'] ?? null) === 'REFRESHED_API');
                if (! $hasFund) {
                    $finalStatus = 'partial';
                    $errorReason = 'FUNDAMENTAL_DATA_UNAVAILABLE';
                    $replyText = "ANALISIS EMITEN — {$ticker} (STATUS: PARTIAL)\n"
                        ."──────────────────────────────────────────\n"
                        ."• Instrumen      : {$ticker} ({$companyName})\n"
                        ."• Status Riset   : PARTIAL — Data harga OHLC pasar berhasil diambil, namun laporan fundamental resmi (laba rugi, neraca, arus kas) belum tersedia di repositori publik.\n"
                        ."• Permintaan     : {$req->analysis_type}\n"
                        ."• Tindakan       : Permintaan tidak ditandai completed penuh untuk menjaga integritas data fundamental.\n"
                        ."──────────────────────────────────────────\n"
                        .$replyText;
                }
            }

            // Step D: Queue Outbox Message for Asynchronous Delivery
            $outbox = $this->queueOutboxReply($req, $replyText);
            if ($outbox) {
                $req->outbox_id = $outbox->id;
            }

            $durationMs = (int) round((microtime(true) - $t0) * 1000);
            $metadata['duration_ms'] = $durationMs;
            $metadata['completed_at'] = now('Asia/Jakarta')->toIso8601String();
            $metadata['ai_latency_ms'] = $aiResult['latency_ms'] ?? 0;
            $metadata['ai_model'] = $aiResult['model'] ?? null;
            $metadata['is_ai_cached'] = $aiResult['is_cached'] ?? false;

            $req->update([
                'status' => $finalStatus,
                'error_reason' => $errorReason,
                'market_analysis_id' => $analysisId,
                'research_metadata' => $metadata,
            ]);

            return [
                'success' => true,
                'status' => $finalStatus,
                'reply_text' => $replyText,
                'duration_ms' => $durationMs,
            ];

        } catch (\Throwable $e) {
            Log::error("[OnDemandResearch] Error executing request {$req->request_id}: ".$e->getMessage());

            $errorReason = $e->getMessage() === 'INTERNAL_EVIDENCE_LINK_MISMATCH'
                ? 'INTERNAL_EVIDENCE_LINK_MISMATCH'
                : 'INTERNAL_EXCEPTION';
            $req->update([
                'status' => 'failed',
                'error_reason' => $errorReason,
                'research_metadata' => array_merge($metadata, [
                    'exception' => $e->getMessage(),
                ]),
            ]);

            $errText = "Terjadi kesalahan internal saat memproses riset on-demand {$ticker}.\n"
                ."• Request ID: {$req->request_id}\n"
                .'• Error: '.$e->getMessage();

            $this->queueOutboxReply($req, $errText);

            return [
                'success' => false,
                'status' => 'failed',
                'reply_text' => $errText,
            ];
        }
    }

    /**
     * Dispatch background worker process non-blocking.
     */
    protected function dispatchAsyncWorker(string $requestId): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $artisanBin = base_path('artisan');
        $phpBin = PHP_BINARY ?: 'php';
        $cmd = "\"{$phpBin}\" \"{$artisanBin}\" aether:execute-research \"{$requestId}\" > NUL 2>&1";

        try {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $handle = popen('start /B '.$cmd, 'r');
                if ($handle === false) {
                    Log::warning("[OnDemandResearch] popen launch failed for {$requestId}, queued for worker recovery.");
                } else {
                    pclose($handle);
                }
            } else {
                exec($cmd.' &');
            }
        } catch (\Throwable $e) {
            Log::warning("[OnDemandResearch] popen launch exception for {$requestId}: ".$e->getMessage());
        }
    }

    /**
     * Queue reply in Telegram Outbox table with deduplication key.
     */
    protected function queueOutboxReply(ResearchRequest $req, string $replyText): ?TelegramOutbox
    {
        if (! $req->chat_id) {
            return null;
        }

        $dedupKey = "RESEARCH_REPLY_{$req->request_id}";

        return TelegramOutbox::updateOrCreate(
            ['dedup_key' => $dedupKey],
            [
                'chat_id' => $req->chat_id,
                'reply_to_message_id' => $req->inbound_message_id,
                'message_type' => 'INBOUND_REPLY',
                'payload_text' => $replyText,
                'status' => 'QUEUED',
                'metadata' => [
                    'request_id' => $req->request_id,
                    'ticker' => $req->ticker,
                ],
            ]
        );
    }

    /**
     * Format clean Telegram response without markdown asterisks.
     */
    public function formatResearchResponse(
        ResearchRequest $req,
        MarketObservation $obs,
        ?PredictionSignal $sig,
        ?EvidenceSnapshot $evidence,
        ?NewsItem $news,
        array $aiResult
    ): string {
        $ticker = $req->ticker;
        $companyName = $req->company_name ?? (BeiUniverseRegistry::KNOWN_BEI_ISSUERS[$ticker] ?? $ticker);
        $nowWib = now('Asia/Jakarta')->format('d M Y, H:i').' WIB';
        $barDateStr = $obs->bar_date->format('d M Y');

        $priceRange = $obs->high_price - $obs->low_price;
        $rangePct = $obs->low_price > 0 ? round(($priceRange / $obs->low_price) * 100, 2) : 0;
        $changeVsOpen = $obs->close_price - $obs->open_price;
        $changeSign = $changeVsOpen >= 0 ? '+' : '';
        $changePct = $obs->open_price > 0 ? round(($changeVsOpen / $obs->open_price) * 100, 2) : 0;

        $isValidatedUniverse = array_key_exists($ticker, BeiUniverseRegistry::VALIDATED_ML_UNIVERSE);

        $lines = [];
        $lines[] = "HASIL RISET ON-DEMAND — {$ticker}";
        $lines[] = '──────────────────────────────────────────';
        $lines[] = "• Request ID      : {$req->request_id}";
        $lines[] = '• Evidence ID: '.($evidence?->id ?? 'TIDAK_TERSEDIA');
        $lines[] = "• Observation ID: {$obs->id}";
        $lines[] = '• Analysis ID: '.($req->market_analysis_id ?? 'TIDAK_TERSEDIA');
        $lines[] = "• Instrumen       : {$ticker} ({$companyName})";
        $lines[] = "• Bursa           : {$req->exchange} (Bursa Efek Indonesia)";
        $lines[] = '• Harga Penutupan : Rp '.number_format($obs->close_price, 2, ',', '.');
        $lines[] = "• Tanggal Bar     : {$barDateStr} (EOD Final via {$obs->source})";
        $lines[] = '• Rentang OHLC    : Open Rp '.number_format($obs->open_price, 2, ',', '.').' | High Rp '.number_format($obs->high_price, 2, ',', '.').' | Low Rp '.number_format($obs->low_price, 2, ',', '.');
        $lines[] = '• Volume          : '.number_format($obs->volume, 0, ',', '.').' lembar';
        $lines[] = "• Sumber Data     : {$obs->source} ({$obs->data_classification})";

        $lines[] = '';
        $lines[] = 'INDIKATOR TEKNIKAL TERSIMPAN';
        $lines[] = '• Rentang Harga High-Low : Rp '.number_format($priceRange, 2, ',', '.')." ({$rangePct}% rentang bar)";
        $lines[] = "• Perubahan vs Open      : {$changeSign}Rp ".number_format($changeVsOpen, 2, ',', '.')." ({$changeSign}{$changePct}% vs Open bar {$barDateStr})";
        $lines[] = '• Catatan Metrik         : Dihitung murni dari bar OHLC tersimpan (rentang harga, bukan bid-ask spread).';

        $lines[] = '';
        $lines[] = 'STATUS MODEL & PREDIKSI';
        if ($isValidatedUniverse && $sig) {
            $confPct = round($sig->confidence_score * 100, 2);
            $pUpPct = round($sig->probability_up * 100, 2);
            $pDownPct = round($sig->probability_down * 100, 2);
            $dueStr = $sig->evaluation_due_date ? $sig->evaluation_due_date->format('d M Y') : 'N/A';

            $lines[] = '• Status Universe : VALIDATED_UNIVERSE (10 Saham Utama)';
            $lines[] = "• Status Sinyal   : {$sig->signal_status} [{$sig->direction}]";
            $lines[] = "• Model Engine    : {$sig->model_name} ({$sig->model_version})";
            $lines[] = "• Estimasi Prob   : P(UP) {$pUpPct}% | P(DOWN) {$pDownPct}%";
            $lines[] = "• Confidence Score: {$confPct}% (Ambang batas minimal: >= 40,00% / Tau 0.70)";
            $lines[] = '• Alasan Abstain  : '.($sig->reason_for_abstain ?: 'Tidak ada alasan abstain');
            $lines[] = "• Evaluasi Horizon: {$sig->evaluation_status} (Jatuh Tempo: {$dueStr})";
        } else {
            $lines[] = '• Status Universe : EXPANDED_BEI_UNIVERSE (Di luar 10 konstituen ML awal)';
            $lines[] = '• Status Sinyal   : MODEL_NOT_VALIDATED_FOR_INSTRUMENT';
            $lines[] = "• Keterangan      : Model prediktif machine learning belum divalidasi/dikalibrasi untuk emiten {$ticker}.";
            $lines[] = '• Estimasi Prob   : N/A';
            $lines[] = '• Confidence Score: N/A (Model belum diuji out-of-sample; ambang batas Tau 0.70 tidak berlaku)';
            $lines[] = '• Batasan Sistem  : Dilarang menyajikan estimasi probabilitas atau sinyal beli/jual tanpa validasi model formal.';
        }

        $lines[] = '';
        $lines[] = 'BERITA EMITEN TERKAIT';
        if ($news) {
            $newsDate = $news->published_at ? $news->published_at->format('d M Y, H:i').' WIB' : 'N/A';
            $lines[] = "• Judul   : {$news->title}";
            $lines[] = '• Sumber  : '.($news->source_name ?: 'LKBN ANTARA')." ({$newsDate})";
            $lines[] = '• Ringkas : '.($news->summary ?: 'Tidak ada ringkasan.');
        } else {
            $newsStatus = data_get($req->research_metadata, 'refresh.bar_refresh.component_refresh.news.status', 'STATUS_UNKNOWN');
            $newsMessage = match ($newsStatus) {
                'NOT_FETCHED' => 'NOT_FETCHED — sumber berita terautentikasi belum diambil; bukan bukti berita tidak ada.',
                'EMPTY_RESPONSE' => 'EMPTY_RESPONSE — provider merespons kosong; bukan bukti semua berita tidak ada.',
                'NO_RELEVANT_ITEMS_FOUND' => 'NO_RELEVANT_ITEMS_FOUND — pencarian selesai tanpa item relevan pada rentang provider.',
                default => 'STATUS_UNKNOWN — ketersediaan berita belum dapat dipastikan.',
            };
            $lines[] = '• Berita  : '.$newsMessage;
        }

        // AI Narrative Section
        if ($aiResult['success'] ?? false) {
            $cacheTag = ($aiResult['is_cached'] ?? false) ? ' [Tersimpan di DB]' : '';
            $lines[] = '';
            $lines[] = 'PENJELASAN NARATIF KUANTITATIF (AI LIVE)';
            $lines[] = "• Model Engine : {$aiResult['model']}{$cacheTag}";
            $lines[] = '• Narasi Grounded:';
            $lines[] = $aiResult['narrative'];
        }

        $lines[] = '──────────────────────────────────────────';
        $obsTimeStr = $obs->observation_time ? $obs->observation_time->timezone('Asia/Jakarta')->format('d M Y, H:i').' WIB' : 'N/A';
        $lines[] = "• Waktu Data Pasar : {$obsTimeStr}";
        $lines[] = "• Waktu Jawaban    : {$nowWib}";
        $lines[] = '• Batasan Riset    : Rp0 Biaya Data, Tanpa Eksekusi Broker, Tanpa Auto-Trading.';

        return implode("\n", $lines);
    }
}
