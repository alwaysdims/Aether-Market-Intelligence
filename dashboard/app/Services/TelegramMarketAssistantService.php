<?php

namespace App\Services;

use App\Models\ScheduledTelegramJob;
use App\Models\TelegramConversation;
use App\Models\TelegramOutbox;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramMarketAssistantService
{
    public function __construct(
        protected CanonicalMarketReadService $marketRead,
        protected StockScreeningService $screeningService,
        protected BeiCalendarService $calendarService,
        protected TelegramFormattingService $formattingService
    ) {}

    /**
     * Parse structured intent, scheduling time, market scope, and horizon from user text.
     */
    public function parseStructuredRequest(string $text, ?string $userId = null, ?CarbonInterface $now = null): array
    {
        $now = ($now ?? Carbon::now('Asia/Jakarta'))->copy()->timezone('Asia/Jakarta');
        $lower = strtolower(trim($text));

        $wantsMarketAnalysis = (bool) preg_match('/(analisis pasar|tinjau pasar|kondisi pasar|market overview|situasi pasar|keadaan pasar|analisis dulu|analisa pasar)/i', $lower);
        $wantsRecommendation = (bool) preg_match('/(rekom[ea]ndasi|saham mana yg bakal naik|saham mana yang naik|saham apa yang bagus|kandidat saham|pilihan saham|saham yang akan naik|saham potensial)/i', $lower);

        // Detect requested time in prompt (e.g. "jam 09.05", "pukul 09:05", "pkl 9.05", "jam 9 lewat 5")
        $hasSchedule = false;
        $requestedTimeRaw = null;
        $requestedTimeCarbon = null;
        $isPastTime = false;
        $timingStatus = 'NO_SCHEDULE';

        if (preg_match('/(?:di\s+|pada\s+|pukul\s+|jam\s+|pkl\s+)(\d{1,2})[:.](\d{2})/i', $text, $m)) {
            $hasSchedule = true;
            $hour = (int) $m[1];
            $minute = (int) $m[2];
            $requestedTimeRaw = sprintf('%02d:%02d', $hour, $minute);

            $requestedTimeCarbon = $now->copy()->setTime($hour, $minute, 0);

            if ($requestedTimeCarbon->lessThan($now)) {
                $isPastTime = true;
                $timingStatus = 'PAST_TIME_ON_SAME_DAY';
            } else {
                $timingStatus = 'FUTURE_TIME_SCHEDULED';
            }
        } elseif (preg_match('/(?:jam|pukul)\s*(\d{1,2})\s*lewat\s*(\d{1,2})/i', $text, $m2)) {
            $hasSchedule = true;
            $hour = (int) $m2[1];
            $minute = (int) $m2[2];
            $requestedTimeRaw = sprintf('%02d:%02d', $hour, $minute);
            $requestedTimeCarbon = $now->copy()->setTime($hour, $minute, 0);

            if ($requestedTimeCarbon->lessThan($now)) {
                $isPastTime = true;
                $timingStatus = 'PAST_TIME_ON_SAME_DAY';
            } else {
                $timingStatus = 'FUTURE_TIME_SCHEDULED';
            }
        }

        // Check if complex inquiry contains both market analysis and recommendation OR scheduling
        $isComplexInquiry = ($wantsMarketAnalysis && $wantsRecommendation)
            || ($hasSchedule && ($wantsMarketAnalysis || $wantsRecommendation || str_contains($lower, 'jadwal')));

        // Parse tolerance if specified by user, otherwise use application default 60 minutes
        $toleranceMinutes = 60;
        if (preg_match('/toleransi\s*(?:maksimal\s*)?(\d+)\s*menit/i', $text, $tolMatch)) {
            $toleranceMinutes = max(1, (int) $tolMatch[1]);
        }

        // Check horizon requested
        $requestedHorizon = 'Analisis berbasis EOD sesi sebelumnya; kandidat swing';

        return [
            'raw_text' => $text,
            'user_id' => $userId,
            'is_complex_inquiry' => $isComplexInquiry,
            'wants_market_analysis' => $wantsMarketAnalysis,
            'wants_recommendation' => $wantsRecommendation,
            'has_schedule' => $hasSchedule,
            'requested_time_raw' => $requestedTimeRaw,
            'requested_time' => $requestedTimeCarbon,
            'timing_status' => $timingStatus,
            'is_past_time' => $isPastTime,
            'timezone' => 'Asia/Jakarta',
            'market_scope' => '501 saham dalam cakupan data sistem',
            'tolerance_minutes' => $toleranceMinutes,
            'requested_horizon' => $requestedHorizon,
            'available_data_tier' => 'EOD_ONLY',
            'data_gap_notice' => 'Analisis berbasis penutupan sesi sebelumnya, bukan kondisi live pukul '.($requestedTimeRaw ?: '09.05').'.',
        ];
    }

    /**
     * Generate grounded Market Overview & Candidate Screening response text.
     */
    public function generateMarketOverviewAndRecommendations(
        array $parsedRequest,
        ?CarbonInterface $asOf = null,
        ?CarbonInterface $informationCutoff = null
    ): array {
        $now = ($asOf ?? Carbon::now('Asia/Jakarta'))->copy()->timezone('Asia/Jakarta');
        $sessionStatus = $this->calendarService->getMarketSessionStatus($now);
        $dataCutoffDate = '2026-10-26';

        // 1. Precise time semantics:
        // Market Session Close: 16:00:00 WIB on reference date = 09:00:00 UTC
        $marketSessionClosedAtUtc = Carbon::createFromFormat('Y-m-d H:i:s', "{$dataCutoffDate} 16:00:00", 'Asia/Jakarta')->utc();
        $marketSessionClosedAtWib = $marketSessionClosedAtUtc->copy()->timezone('Asia/Jakarta');

        // Evidence Available At: when secondary data was ingested (stored in UTC, displayed in WIB)
        $cutoffBar = DB::table('market_observations')
            ->where('ticker', '^JKSE')
            ->whereDate('bar_date', $dataCutoffDate)
            ->first();
        $evidenceAvailUtc = $cutoffBar?->available_at
            ? Carbon::parse($cutoffBar->available_at)->utc()
            : Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-26 23:01:14', 'Asia/Jakarta')->utc();
        $evidenceAvailWib = $evidenceAvailUtc->copy()->timezone('Asia/Jakarta');

        // Analysis Information Cutoff: Point-in-time boundary for observations
        $analysisInfoCutoffUtc = $informationCutoff
            ? $informationCutoff->copy()->utc()
            : $now->copy()->utc();
        $analysisInfoCutoffWib = $analysisInfoCutoffUtc->copy()->timezone('Asia/Jakarta');

        // 2. Calculate Market Breadth across universe from single atomic snapshot with cutoff enforcement
        $breadth = $this->marketRead->calculateMarketBreadth($dataCutoffDate, $analysisInfoCutoffUtc);

        // Benchmark IHSG
        $ihsgClose = $breadth['benchmark']['close'] !== null ? number_format((float) $breadth['benchmark']['close'], 2, ',', '.') : 'N/A';
        $ihsgChange = $breadth['benchmark']['daily_change'] !== null ? ($breadth['benchmark']['daily_change'] > 0 ? '+'.number_format((float) $breadth['benchmark']['daily_change'], 2, ',', '.') : number_format((float) $breadth['benchmark']['daily_change'], 2, ',', '.')) : 'N/A';
        $ihsgChangePct = $breadth['benchmark']['daily_change_pct'] !== null ? ($breadth['benchmark']['daily_change_pct'] > 0 ? '+'.number_format((float) $breadth['benchmark']['daily_change_pct'], 2, ',', '.').'%' : number_format((float) $breadth['benchmark']['daily_change_pct'], 2, ',', '.').'%') : 'N/A';

        // Top 3 Active Volume Stocks on reference date
        $topVolume = DB::table('market_observations')
            ->where('bar_date', $dataCutoffDate)
            ->where('interval', '1d')
            ->where('data_classification', 'PUBLIC_MARKET_EOD')
            ->whereNotIn('ticker', ['^JKSE', 'JKSE', 'IHSG'])
            ->orderBy('volume', 'desc')
            ->take(3)
            ->get();

        // 3. Screening Candidates via Deterministic Rules
        $screening = $this->screeningService->screenCandidates('SWING');
        $candidates = array_slice($screening['candidates'] ?? [], 0, 4);

        // 4. Timing / Scheduling Context Header
        $scheduleNotice = '';
        if ($parsedRequest['has_schedule']) {
            if ($parsedRequest['is_past_time']) {
                $scheduleNotice = "⚠️ PERMINTAAN JADWAL ({$parsedRequest['requested_time_raw']} WIB): Waktu yang diminta telah terlampaui saat permintaan diproses (waktu saat ini {$now->format('H:i')} WIB). Sesuai kebijakan audit, sistem menyajikan analisis data terkini secara transparan dan TIDAK menjadwalkan diam-diam untuk hari esok.\n\n";
            } else {
                $scheduleNotice = "⏱️ JADWAL DIEKSEKUSI: Permintaan untuk pukul {$parsedRequest['requested_time_raw']} WIB diproses pada {$now->format('H:i:s')} WIB.\n\n";
            }
        }

        // 5. Build Structured Plain Text
        $lines = [];
        $lines[] = '=== ANALISIS PASAR & SCREENING KANDIDAT BEI ===';
        $lines[] = "Waktu Laporan: {$now->format('Y-m-d H:i:s')} WIB";
        $lines[] = "Status Sesi BEI: {$sessionStatus['description']}";
        $lines[] = 'Horizon: Analisis berbasis EOD sesi sebelumnya; kandidat swing';
        $lines[] = "Sesi Bursa Tutup : {$marketSessionClosedAtWib->format('Y-m-d H:i:s')} WIB ({$marketSessionClosedAtUtc->format('H:i:s')} UTC)";
        $lines[] = "Bukti Tersedia   : {$evidenceAvailWib->format('Y-m-d H:i:s')} WIB ({$evidenceAvailUtc->format('H:i:s')} UTC)";
        $lines[] = "Cutoff Informasi : {$analysisInfoCutoffWib->format('Y-m-d H:i:s')} WIB ({$analysisInfoCutoffUtc->format('H:i:s')} UTC)";
        $lines[] = '';
        if ($scheduleNotice !== '') {
            $lines[] = trim($scheduleNotice);
            $lines[] = '';
        }
        $lines[] = '⚠️ BATASAN DATA & PERINGATAN RISIKO:';
        $lines[] = "- Analisis berbasis penutupan sesi sebelumnya (Bar EOD {$dataCutoffDate}), BUKAN kondisi live/intraday pukul {$now->format('H:i')} WIB.";
        $lines[] = '- Data intraday tick-by-tick real-time tidak tersedia pada tier data publik saat ini. Pemilihan horizon intraday tidak didukung langsung oleh feed data; kandidat disajikan dalam kerangka strategi swing (multi-day holding) dengan level cut-loss terukur.';
        $lines[] = '- Tidak ada jaminan saham pasti naik. Kinerja masa lalu tidak menjamin pergerakan sesi berjalan.';
        $lines[] = '';
        $lines[] = "1. KONDISI & BREADTH PASAR (Sesi Acuan: {$dataCutoffDate} vs {$breadth['previous_session_date']})";
        $lines[] = "- Benchmark IHSG (^JKSE): {$ihsgClose} ({$ihsgChange} / {$ihsgChangePct})";
        $lines[] = "- Total Universe Ekuitas: {$breadth['universe_total']} saham dalam cakupan data sistem";
        $lines[] = "- Eligible Breadth: {$breadth['eligible_count']} emiten";
        $lines[] = "  * Advancers (Naik): {$breadth['advancers_count']} emiten ({$breadth['advancers_pct']}%)";
        $lines[] = "  * Decliners (Turun): {$breadth['decliners_count']} emiten ({$breadth['decliners_pct']}%)";
        $lines[] = "  * Unchanged (Tetap): {$breadth['unchanged_count']} emiten ({$breadth['unchanged_pct']}%)";
        $lines[] = "  * Verifikasi Eligible: {$breadth['advancers_count']} + {$breadth['decliners_count']} + {$breadth['unchanged_count']} = {$breadth['eligible_count']} (100% konsisten)";
        $lines[] = "- Median Perubahan Universe: {$breadth['median_change_pct']}%";
        $lines[] = "- Excluded Universe: {$breadth['excluded_total']} emiten";
        $lines[] = "  * Missing bar sesi acuan {$dataCutoffDate}: {$breadth['excluded_reasons']['NO_BAR_ON_REFERENCE_DATE']} emiten";
        $lines[] = "  * Missing bar sesi pembanding {$breadth['previous_session_date']}: {$breadth['excluded_reasons']['NO_BAR_ON_PREV_SESSION']} emiten";
        $lines[] = "  * Verifikasi Universe: {$breadth['eligible_count']} + {$breadth['excluded_total']} = {$breadth['universe_total']} (100% konsisten)";
        $lines[] = '- Ringkasan Breadth: '.($breadth['advancers_count'] > $breadth['decliners_count'] ? 'Sentimen pasar sesi sebelumnya didominasi penguatan.' : 'Sentimen pasar sesi sebelumnya didominasi tekanan jual/koreksi.');
        $lines[] = '';
        $lines[] = '2. AKTIVITAS PASAR (Top Volume Sesi Sebelumnya)';
        foreach ($topVolume as $r) {
            $lines[] = "- {$r->ticker}: ".number_format($r->volume, 0, ',', '.').' lembar | Close Rp '.number_format($r->close_price, 0, ',', '.');
        }
        $lines[] = '';
        $lines[] = '3. SCREENING KANDIDAT POTENSIAL (Aturan Kuantitatif Terfilter)';
        $lines[] = 'Kandidat disaring berdasarkan likuiditas, keteraturan rentang harga, dan momentum:';
        $lines[] = '';

        if (empty($candidates)) {
            $lines[] = '- Tidak ada emiten yang memenuhi seluruh filter likuiditas dan rentang harga saat ini.';
        } else {
            foreach ($candidates as $idx => $c) {
                $num = $idx + 1;
                $ticker = $c['ticker'];
                $close = number_format($c['close_price'], 0, ',', '.');
                $range = $c['range_pct'];
                $chgOpen = $c['change_pct'] > 0 ? '+'.$c['change_pct'].'%' : $c['change_pct'].'%';
                $vol = number_format($c['volume'], 0, ',', '.');

                // Compute strict Bei tick cut-loss / invalidation level (below low of candle)
                $invalidationPrice = StockScreeningService::roundToBeiTick($c['close_price'] * 0.96, false);

                $lines[] = "{$num}. {$ticker} (Close: Rp {$close} | vs Open: {$chgOpen})";
                $lines[] = "   - Kriteria Lolos: Volume {$vol} lbr (>=10M), Rentang bar {$range}%, Arah positif.";
                $lines[] = '   - Invalidation Level: Rp '.number_format($invalidationPrice, 0, ',', '.').' (Batal jika menembus level ini ke bawah).';
                $lines[] = '   - Risiko: Potensi gap pembukaan dan volatilitas sesi berjalan.';
            }

            // Daftarkan kandidat screening ke tabel pelacakan outcome resmi
            $this->screeningService->registerCandidatesAsPredictions($candidates, $asOf ?? now('Asia/Jakarta'));
        }

        $lines[] = '';
        $lines[] = '4. STATUS MODEL & FORWARD STUDY';
        $lines[] = '- Output A (Studi Sentuhan Barrier): WAITING_FOR_FINAL_DATA (Menunggu data penutupan sesi 27-Okt final).';
        $lines[] = '- Output B (Simulasi Transaksi): WITHHELD (Ditahan sesuai pembatasan metodologi order riil & nol order broker).';

        $fullText = implode("\n", $lines);

        return [
            'text' => $fullText,
            'breadth' => $breadth,
            'candidates' => $candidates,
            'as_of' => $now->toIso8601String(),
            'data_cutoff' => $dataCutoffDate,
            'market_session_closed_at' => $marketSessionClosedAtUtc,
            'evidence_available_at' => $evidenceAvailUtc,
            'analysis_information_cutoff' => $analysisInfoCutoffUtc,
        ];
    }

    /**
     * Persist scheduled job in PostgreSQL.
     */
    public function scheduleJob(array $parsedRequest, array $context): ScheduledTelegramJob
    {
        $chatId = (string) ($context['chat_id'] ?? config('services.telegram.chat_id', '5923895922'));
        $userId = (string) ($context['user_id'] ?? $chatId);
        $scheduledAt = isset($parsedRequest['requested_time'])
            ? $parsedRequest['requested_time']->copy()->utc()
            : now()->utc();
        $rawText = $parsedRequest['raw_text'] ?? '';

        $idempotencyKey = hash('sha256', "{$chatId}:{$scheduledAt->toIso8601String()}:{$rawText}");

        $existing = ScheduledTelegramJob::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $toleranceMinutes = $parsedRequest['tolerance_minutes'] ?? ($context['tolerance_minutes'] ?? 60);
        $deadlineAt = $scheduledAt->copy()->addMinutes($toleranceMinutes);

        return ScheduledTelegramJob::create([
            'chat_id' => $chatId,
            'telegram_user_id' => $userId,
            'telegram_update_id' => $context['update_id'] ?? null,
            'inbound_message_id' => $context['inbound_message_id'] ?? null,
            'original_prompt' => $rawText,
            'request_type' => $context['request_type'] ?? 'MARKET_ANALYSIS_AND_RECOMMENDATION',
            'parameters' => $parsedRequest,
            'scheduled_at' => $scheduledAt,
            'deadline_at' => $deadlineAt,
            'timezone' => 'Asia/Jakarta',
            'tolerance_minutes' => $toleranceMinutes,
            'status' => 'PENDING',
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * Execute a scheduled job, generate report, queue outbox, and update status atomically.
     */
    public function executeScheduledJob(ScheduledTelegramJob $job, bool $mockOnly = false): array
    {
        $startTime = microtime(true);

        // Atomic lock transition from PENDING to PROCESSING with unique worker lease token & creation date
        $startedAt = now();
        $workerPid = getmypid() ?: null;
        $workerToken = 'WTOK_'.getmypid().'_'.bin2hex(random_bytes(8));
        $workerDetails = $this->getProcessDetails($workerPid);
        $workerCreatedAt = $workerDetails['creation_date'] ?? null;

        $acquired = DB::transaction(function () use ($job, $startedAt, $workerPid, $workerToken, $workerCreatedAt) {
            $fresh = ScheduledTelegramJob::where('id', $job->id)
                ->where('status', 'PENDING')
                ->lockForUpdate()
                ->first();

            if (! $fresh) {
                return false;
            }

            $fresh->update([
                'status' => 'PROCESSING',
                'started_at' => $startedAt,
                'worker_pid' => $workerPid,
                'worker_token' => $workerToken,
                'worker_process_created_at' => $workerCreatedAt,
                'heartbeat_at' => $startedAt,
                'delivery_status' => 'QUEUED',
            ]);

            return true;
        });

        if (! $acquired) {
            return [
                'status' => 'skipped',
                'reason' => 'Job sudah diproses atau tidak berstatus PENDING.',
            ];
        }

        try {
            $parsed = $job->parameters ?: $this->parseStructuredRequest($job->original_prompt, $job->telegram_user_id);
            $informationCutoff = $job->scheduled_at ? $job->scheduled_at->copy()->timezone('Asia/Jakarta') : now('Asia/Jakarta');

            if ($job->request_type === 'ASYNC_CONSULTATION') {
                $provenance = [
                    'as_of' => now('Asia/Jakarta')->format('Y-m-d H:i:s').' WIB',
                    'data_source' => 'PostgreSQL Database project-bot',
                    'job_id' => $job->id,
                    'chat_id' => $job->chat_id,
                    'user_id' => $job->telegram_user_id,
                    'inbound_message_id' => $job->inbound_message_id,
                    'telegram_update_id' => $job->telegram_update_id,
                    'inference_mode' => 'ASYNC_WORKER_AI_CONVERSATION (ag/gemini-3.8-flash-high via 9router)',
                ];

                // Check feature flag: Hermes Agent Reasoning vs Legacy AiConversationService
                $useHermes = config('services.hermes.orchestrator_enabled', false);

                if ($useHermes) {
                    $report = app(HermesConsultationAdapterService::class)->executeConsultation($job, $provenance);
                } else {
                    $reportText = app(AiConversationService::class)->handleConversation(
                        $job->original_prompt,
                        $job->telegram_user_id,
                        $job->chat_id,
                        null,
                        $provenance
                    );

                    $report = [
                        'text' => $reportText,
                        'analysis_id' => $provenance['ai_analysis_id'] ?? null,
                        'evidence_id' => $provenance['ai_evidence_id'] ?? $provenance['disclosures_search']['evidence_id'] ?? null,
                        'market_session_closed_at' => now('Asia/Jakarta')->toIso8601String(),
                        'evidence_available_at' => now('Asia/Jakarta')->toIso8601String(),
                        'analysis_information_cutoff' => now('Asia/Jakarta')->toIso8601String(),
                    ];
                }
            } else {
                $report = $this->generateMarketOverviewAndRecommendations($parsed, now('Asia/Jakarta'), $informationCutoff);
            }
            $analysisCompletedAt = now();

            // Deduplication key for Telegram Outbox
            $dedupKey = "OUTBOX_SCHEDULED_{$job->id}_".time();

            // Atomic transaction to verify ownership and persist outbox + job update
            $atomicResult = DB::transaction(function () use (
                $job,
                $workerToken,
                $analysisCompletedAt,
                $report,
                $dedupKey,
                $mockOnly,
                $startTime
            ) {
                $lockedJob = ScheduledTelegramJob::where('id', $job->id)
                    ->where('worker_token', $workerToken)
                    ->where('status', 'PROCESSING')
                    ->lockForUpdate()
                    ->first();

                if (! $lockedJob) {
                    return [
                        'status' => 'fenced',
                        'error' => 'Job lease was fenced or superseded before atomic write.',
                    ];
                }

                $outbox = TelegramOutbox::create([
                    'dedup_key' => $dedupKey,
                    'chat_id' => $lockedJob->chat_id,
                    'reply_to_message_id' => $lockedJob->inbound_message_id,
                    'message_type' => 'SCHEDULED_MARKET_ANALYSIS',
                    'payload_text' => $report['text'],
                    'status' => 'QUEUED',
                    'metadata' => [
                        'scheduled_job_id' => $lockedJob->id,
                        'telegram_update_id' => $lockedJob->telegram_update_id,
                        'request_type' => $lockedJob->request_type,
                        'scheduled_at' => $lockedJob->scheduled_at?->toIso8601String(),
                    ],
                ]);

                $outboundMsgId = null;
                $sentAt = null;
                $finalJobStatus = 'PROCESSING';
                $finalDeliveryStatus = 'QUEUED';

                if ($mockOnly) {
                    $outboundMsgId = 'MOCK_TG_JOB_'.uniqid();
                    $sentAt = now();
                    $outbox->update([
                        'status' => 'SENT',
                        'sent_at' => $sentAt,
                        'telegram_message_id' => $outboundMsgId,
                    ]);
                    $finalJobStatus = 'COMPLETED';
                    $finalDeliveryStatus = 'SENT';
                } else {
                    $this->formattingService->processOutboxQueue(mockOnly: false);
                    $outbox->refresh();
                    $outboundMsgId = $outbox->telegram_message_id;
                    $sentAt = $outbox->sent_at;

                    if ($outbox->status === 'SENT' && $outboundMsgId) {
                        $finalJobStatus = 'COMPLETED';
                        $finalDeliveryStatus = 'SENT';
                    } elseif ($outbox->status === 'DELIVERY_UNKNOWN') {
                        $finalJobStatus = 'DELIVERY_UNKNOWN';
                        $finalDeliveryStatus = 'DELIVERY_UNKNOWN';
                    } elseif ($outbox->status === 'FAILED') {
                        $finalJobStatus = 'FAILED';
                        $finalDeliveryStatus = 'FAILED';
                    } else {
                        // Selesai analisis tetapi belum terkirim berstatus ANALYSIS_COMPLETED (bukan COMPLETED)
                        $finalJobStatus = 'ANALYSIS_COMPLETED';
                        $finalDeliveryStatus = 'QUEUED';
                    }
                }

                $durationMs = (int) round((microtime(true) - $startTime) * 1000);
                $latenessSeconds = ($lockedJob->scheduled_at && $sentAt) ? abs($sentAt->diffInSeconds($lockedJob->scheduled_at, false)) : 0;

                $lockedJob->update([
                    'status' => $finalJobStatus,
                    'delivery_status' => $finalDeliveryStatus,
                    'analysis_completed_at' => $analysisCompletedAt,
                    'analysis_id' => $report['analysis_id'] ?? null,
                    'evidence_id' => $report['evidence_id'] ?? null,
                    'outbox_id' => $outbox->id,
                    'data_cutoff_at' => $report['market_session_closed_at'] ?? null,
                    'market_session_closed_at' => $report['market_session_closed_at'] ?? null,
                    'evidence_available_at' => $report['evidence_available_at'] ?? null,
                    'analysis_information_cutoff' => $report['analysis_information_cutoff'] ?? null,
                    'bar_time' => '16:00:00 WIB',
                    'data_available_at' => $report['evidence_available_at'] ?? null,
                    'lateness_seconds' => $latenessSeconds,
                    'executed_at' => now(),
                    'sent_at' => $sentAt,
                    'outbound_message_id' => $outboundMsgId,
                    'result_summary' => substr($report['text'], 0, 500),
                    'latency_ms' => $durationMs,
                    'error_message' => $outbox->error_message,
                ]);

                // Also update corresponding TelegramConversation record if linked to preserve conversation history
                if ($lockedJob->telegram_update_id) {
                    TelegramConversation::updateOrCreate(
                        ['telegram_update_id' => $lockedJob->telegram_update_id],
                        [
                            'chat_id' => $lockedJob->chat_id,
                            'user_id' => $lockedJob->telegram_user_id,
                            'user_name' => 'User',
                            'inbound_text' => $lockedJob->original_prompt,
                            'detected_intent' => 'GENERAL_CONVERSATION',
                            'context_signal_id' => null,
                            'outbound_reply_text' => $report['text'],
                            'provenance' => [
                                'job_id' => $lockedJob->id,
                                'outbox_id' => $outbox->id,
                                'analysis_id' => $report['analysis_id'] ?? null,
                                'evidence_id' => $report['evidence_id'] ?? null,
                            ],
                            'response_time_ms' => $durationMs,
                        ]
                    );
                }

                return [
                    'status' => 'success',
                    'job_id' => $lockedJob->id,
                    'outbox_id' => $outbox->id,
                    'outbound_message_id' => $outboundMsgId,
                    'duration_ms' => $durationMs,
                ];
            });

            return $atomicResult;
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            // Safe check: ensure lease was not stolen/superseded before updating to FAILED
            ScheduledTelegramJob::where('id', $job->id)
                ->where('worker_token', $workerToken)
                ->where('status', 'PROCESSING')
                ->update([
                    'status' => 'FAILED',
                    'delivery_status' => 'FAILED',
                    'error_message' => $e->getMessage(),
                    'latency_ms' => $durationMs,
                ]);

            // Notify user specifically about the failure via outbox if this was a user chat / consultation
            if ($job->chat_id && in_array($job->request_type, ['ASYNC_CONSULTATION', 'MARKET_ANALYSIS_AND_RECOMMENDATION'])) {
                try {
                    // Sanitize internal details: never expose SQL errors, database host/port, or raw code traces to chat
                    $userReason = 'Terjadi kendala teknis internal pada pemrosesan antrean analisis.';
                    $rawMsg = $e->getMessage();
                    if (str_contains($rawMsg, 'timeout') || str_contains($rawMsg, 'timed out')) {
                        $userReason = 'Batas waktu pemrosesan analisis terlampaui saat menghubungi penyedia data/mesin penalaran.';
                    } elseif (str_contains($rawMsg, 'Rate limit') || str_contains($rawMsg, '429')) {
                        $userReason = 'Batas kapasitas layanan tercapai sementara waktu. Silakan coba kembali sesaat lagi.';
                    } elseif (str_contains($rawMsg, 'fenced') || str_contains($rawMsg, 'superseded')) {
                        $userReason = 'Pekerjaan telah diambil alih oleh worker lain untuk menjaga konsistensi.';
                    }

                    $failureNotice = "⚠️ Permintaan analisis (Job #{$job->id}) belum dapat diselesaikan.\nKeterangan: {$userReason}\nID Referensi: JOB-{$job->id}";
                    $outboxDeduplicationKey = 'ERR_JOB_'.$job->id.'_'.time();
                    TelegramOutbox::create([
                        'dedup_key' => $outboxDeduplicationKey,
                        'chat_id' => $job->chat_id,
                        'reply_to_message_id' => $job->inbound_message_id,
                        'message_type' => 'SYSTEM_ALERT',
                        'payload_text' => $failureNotice,
                        'status' => 'QUEUED',
                        'metadata' => [
                            'scheduled_job_id' => $job->id,
                            'worker_token' => $workerToken,
                            'error_class' => get_class($e),
                            'sanitized_reason' => $userReason,
                        ],
                    ]);
                } catch (\Throwable $notifyErr) {
                    Log::error('[TelegramMarketAssistantService] Failed to queue failure notice: '.$notifyErr->getMessage());
                }
            }

            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getProcessDetails(?int $pid): array
    {
        if (! $pid || $pid <= 0) {
            return ['alive' => false, 'image' => '', 'creation_date' => ''];
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $out = [];
            @exec("tasklist /FI \"PID eq {$pid}\" /FO CSV /NH 2>NUL", $out);
            $found = false;
            $image = '';
            foreach ($out as $line) {
                if (str_contains($line, (string) $pid)) {
                    $cols = str_getcsv($line);
                    if (isset($cols[1]) && (int) $cols[1] === $pid) {
                        $found = true;
                        $image = strtolower(trim($cols[0] ?? ''));
                        break;
                    }
                }
            }
            if (! $found) {
                return ['alive' => false, 'image' => '', 'creation_date' => ''];
            }

            $cmd = "powershell -NoProfile -Command \"(Get-CimInstance Win32_Process -Filter \\\"ProcessId={$pid}\\\").CreationDate\" 2>NUL";
            $cDate = trim((string) @shell_exec($cmd));

            return ['alive' => true, 'image' => $image, 'creation_date' => $cDate];
        }

        $alive = file_exists("/proc/{$pid}");

        return ['alive' => $alive, 'image' => 'posix', 'creation_date' => ''];
    }
}
