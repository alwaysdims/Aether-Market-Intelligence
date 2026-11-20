<?php

namespace App\Services;

use App\Models\AnalysisRun;
use App\Models\TelegramOutbox;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MiddaySessionReportService
{
    public function __construct(
        protected BeiCalendarService $calendar,
        protected TelegramFormattingService $formatter
    ) {}

    /**
     * Evaluasi kelayakan data live Sesi 1 dari state WebSocket running trade.
     */
    public function evaluateLiveSession1Coverage(?string $statePath = null): array
    {
        $path = $statePath ?? storage_path('app/running_trade_state.json');

        if (! file_exists($path)) {
            return [
                'is_rg_ready' => false,
                'coverage_status' => 'UNOBSERVED',
                'observation_window' => 'TIDAK TERAMATI (File state belum ada)',
                'last_event_time' => null,
                'total_trades' => 0,
                'reconnect_count' => 0,
                'board_scope' => 'MIXED_BOARD_UNSEPARABLE',
                'board_rg_guaranteed' => false,
                'has_official_trade_id' => false,
                'has_event_timestamp' => false,
                'blockers' => $this->getTechnicalBlockers(),
                'provider_inquiries' => $this->getProviderInquiries(),
            ];
        }

        $raw = @file_get_contents($path);
        $state = json_decode((string) $raw, true);

        if (! is_array($state)) {
            return [
                'is_rg_ready' => false,
                'coverage_status' => 'UNOBSERVED',
                'observation_window' => 'TIDAK TERAMATI (File state korup / gagal decode)',
                'last_event_time' => null,
                'total_trades' => 0,
                'reconnect_count' => 0,
                'board_scope' => 'MIXED_BOARD_UNSEPARABLE',
                'board_rg_guaranteed' => false,
                'has_official_trade_id' => false,
                'has_event_timestamp' => false,
                'blockers' => $this->getTechnicalBlockers(),
                'provider_inquiries' => $this->getProviderInquiries(),
            ];
        }

        $todayDateStr = Carbon::now('Asia/Jakarta')->toDateString();
        $sessionCoverage = $state['session_coverage'] ?? [];
        $stateSessionDate = $state['session_date'] ?? $sessionCoverage['session_date'] ?? null;
        $heartbeatEpoch = (int) ($state['heartbeat_epoch'] ?? 0);
        $nowEpoch = time();
        $statusStr = $state['status'] ?? 'UNKNOWN';

        $sessionCoverage = $state['session_coverage'] ?? [];
        $boardScope = $state['board_scope'] ?? 'BOARD_SCOPE_UNVERIFIED';
        $boardRgGuaranteed = (bool) ($sessionCoverage['board_rg_guaranteed'] ?? false);
        $observedFrom = $sessionCoverage['observed_from'] ?? $sessionCoverage['observation_start_at'] ?? null;
        $observedUntil = $sessionCoverage['observed_until'] ?? $sessionCoverage['observation_end_at'] ?? $state['last_trade_at'] ?? null;
        $eventTimeStatus = $sessionCoverage['event_time_status'] ?? 'EVENT_TIME_UNVERIFIED';
        if ($observedFrom) {
            $sessionCoverage['observed_from'] = $observedFrom;
            $sessionCoverage['observed_until'] = $observedUntil;
            $sessionCoverage['window_status'] = 'SESSION_1_PARTIAL';
            $sessionCoverage['session_1_coverage'] = 'PARTIAL';
            $sessionCoverage['session_2_coverage'] = 'UNOBSERVED_UNTIL_PROVEN';
            $sessionCoverage['gap'] = $sessionCoverage['gap'] ?? 'NOT_ESTABLISHED';
            $sessionCoverage['event_time_status'] = $eventTimeStatus;
        }
        $counterStatus = $state['total_trades_counter_status'] ?? 'COUNTER_UNVERIFIED';
        $runCounters = $state['counter_state']['run'] ?? [];
        $trustedRunTrades = $runCounters['unique_accepted_live_trade_items'] ?? null;
        $totalTrades = $trustedRunTrades === null ? null : (int) $trustedRunTrades;
        $reconnectCount = (int) ($sessionCoverage['reconnect_count'] ?? $state['reconnect_count'] ?? 0);
        if ($counterStatus !== 'VERIFIED_RUN_SCOPED') {
            $counterStatus = 'COUNTER_UNVERIFIED';
        }
        $lastEventTime = $sessionCoverage['last_event_time'] ?? null;
        $obsStart = $sessionCoverage['observation_start_at'] ?? $state['connected_at'] ?? null;
        $obsEnd = $sessionCoverage['observation_end_at'] ?? $state['last_trade_at'] ?? null;

        // Evaluasi kategori status secara ketat dan terisolasi
        if ($stateSessionDate !== $todayDateStr) {
            $coverageStatus = 'SESSION_NOT_OBSERVED';
            $obsWindow = "TIDAK TERAMATI (State tanggal {$stateSessionDate} tidak berlaku untuk sesi {$todayDateStr})";
        } elseif ($statusStr === 'DISCONNECTED' || ($nowEpoch - $heartbeatEpoch > 60)) {
            $coverageStatus = ($statusStr === 'DISCONNECTED') ? 'COLLECTOR_DISCONNECTED' : 'STALE_STATE';
            $obsWindow = "TIDAK TERAMATI ({$coverageStatus}: Heartbeat terhenti / koneksi putus)";
        } elseif (($sessionCoverage['session_1_coverage'] ?? '') === 'UNOBSERVED') {
            $coverageStatus = 'SESSION_NOT_OBSERVED';
            $obsWindow = 'TIDAK TERAMATI (Supervisor aktif pada Sesi 2; aktivitas Sesi 1 09:00-12:00 WIB tidak terekam)';
        } elseif (! $obsStart) {
            $coverageStatus = 'SESSION_NOT_OBSERVED';
            $obsWindow = 'TIDAK TERAMATI (Stream belum tersambung pada Sesi 1)';
        } elseif (! $boardRgGuaranteed) {
            $coverageStatus = 'BOARD_SCOPE_UNVERIFIED';
            $obsWindow = "TERAMATI SEBAGIAN ({$obsStart} s.d. ".($obsEnd ?? 'sekarang').' WIB)';
        } else {
            $coverageStatus = 'PARTIAL_COVERAGE';
            $obsWindow = "TERAMATI ({$obsStart} s.d. ".($obsEnd ?? 'sekarang').' WIB)';
        }

        // Syarat RG siap: board_rg_guaranteed == true DAN total_trades > 0 DAN coverage memadai
        $isRgReady = $boardRgGuaranteed && ($totalTrades > 0);

        return [
            'is_rg_ready' => $isRgReady,
            'coverage_status' => $coverageStatus,
            'observation_window' => $obsWindow,
            'last_event_time' => $lastEventTime,
            'total_trades' => $totalTrades,
            'total_trades_status' => $counterStatus,
            'reconnect_count' => $reconnectCount,
            'board_scope' => $boardScope,
            'board_rg_guaranteed' => $boardRgGuaranteed,
            'has_official_trade_id' => (bool) ($sessionCoverage['has_official_trade_id'] ?? false),
            'has_event_timestamp' => (bool) ($sessionCoverage['has_event_timestamp'] ?? false),
            'blockers' => $this->getTechnicalBlockers($coverageStatus, $state),
            'provider_inquiries' => $this->getProviderInquiries(),
            'raw_state' => $state,
        ];
    }

    /**
     * Susun Laporan Sesi 1 ketika data RG belum memenuhi syarat.
     */
    public function buildSession1UnavailableReport(
        array $coverage,
        Carbon $sessionDate,
        Carbon $prevTradingDay,
        Collection $prevEodObservations,
        ?string $deterministicSynthesis = null
    ): string {
        $sessionDateStr = $sessionDate->format('d M Y');
        $prevDateStr = $prevTradingDay->format('d M Y');
        $nowWib = Carbon::now('Asia/Jakarta');

        $lines = [
            '⚠️ LAPORAN SESI 1 BELUM TERSEDIA (12:15 WIB)',
            '──────────────────────────────────────────',
            "• Tanggal Sesi      : {$sessionDateStr}",
            "• Rentang Pengamatan: {$coverage['observation_window']}",
            '• Last Event Time   : '.($coverage['last_event_time'] ?? 'N/A (Sesi 1 tidak teramati secara live)'),
            "• Waktu Publikasi   : {$nowWib->format('d M Y, H:i:s')} WIB",
            "• Papan Bursa (RG)  : {$coverage['board_scope']}",
            '• Status Evaluasi   : DATA_NOT_VALIDATED (Belum Memenuhi Syarat RG)',
            '• Total Transaksi   : '.($coverage['total_trades'] === null ? 'COUNTER_UNVERIFIED' : number_format($coverage['total_trades'], 0, ',', '.').' unique live items')." (Reconn: {$coverage['reconnect_count']})",
            '──────────────────────────────────────────',
            '🚫 BATASAN & PENYEBAB DATA_CAPABILITY_BLOCKED:',
        ];

        foreach ($coverage['blockers'] as $idx => $blocker) {
            $num = $idx + 1;
            $lines[] = "{$num}. {$blocker}";
        }

        $lines[] = '──────────────────────────────────────────';
        $lines[] = '📊 ACUAN PEMBANDING EOD SESI SEBELUMNYA:';
        $lines[] = "• Catatan: Penutupan EOD ({$prevDateStr}) ditampilkan murni sebagai baseline pembanding historis, bukan estimasi harga sesi berjalan.";

        foreach ($prevEodObservations->take(5) as $obs) {
            $close = number_format($obs->close_price, 0, ',', '.');
            $lines[] = sprintf('• %-4s : Rp %s (EOD %s, Board: %s)', $obs->ticker, $close, $prevDateStr, $obs->board_scope ?? 'RG');
        }

        if ($deterministicSynthesis) {
            $lines[] = '──────────────────────────────────────────';
            $lines[] = '📋 SINTESIS EVALUASI OPERASIONAL DETERMINISTIK:';
            $lines[] = trim($deterministicSynthesis);
        }

        $lines[] = '──────────────────────────────────────────';
        $lines[] = "Dipublikasikan oleh Aether Market Intelligence pada {$nowWib->format('d/m/Y H:i:s')} WIB";

        return implode("\n", $lines);
    }

    /**
     * Hitung agregat dan sinyal dari daftar trade RG yang tervalidasi.
     */
    public function aggregateValidatedTrades(array $trades, array $prevEodBars): array
    {
        $tickerAggregates = [];

        foreach ($trades as $t) {
            $ticker = $t['ticker'] ?? 'UNKNOWN';
            $price = (float) ($t['price'] ?? 0);
            $lot = (float) ($t['lot'] ?? 0);
            $board = $t['board'] ?? 'UNSPECIFIED';

            // Filter ketat: hanya terima board RG
            if ($board !== 'RG') {
                continue;
            }

            if (! isset($tickerAggregates[$ticker])) {
                $tickerAggregates[$ticker] = [
                    'ticker' => $ticker,
                    'trades_count' => 0,
                    'volume_lots' => 0.0,
                    'turnover_idr' => 0.0,
                    'first_price' => $price,
                    'last_price' => $price,
                    'high_price' => $price,
                    'low_price' => $price,
                    'board_scope' => 'RG',
                ];
            }

            $agg = &$tickerAggregates[$ticker];
            $agg['trades_count']++;
            $agg['volume_lots'] += $lot;
            $agg['turnover_idr'] += ($price * $lot * 100.0);
            $agg['last_price'] = $price;
            $agg['high_price'] = max($agg['high_price'], $price);
            $agg['low_price'] = min($agg['low_price'], $price);
        }

        // Hitung metrik komparasi terhadap previous EOD
        $results = [];
        $candidates = [];

        foreach ($tickerAggregates as $ticker => $agg) {
            $prevBar = $prevEodBars[$ticker] ?? null;
            $prevClose = $prevBar ? (float) ($prevBar['close_price'] ?? 0) : 0;
            $prevVolume = $prevBar ? (float) ($prevBar['volume'] ?? 0) : 0;

            $changePct = ($prevClose > 0)
                ? round((($agg['last_price'] - $prevClose) / $prevClose) * 100, 2)
                : 0.0;

            // Volume vs EOD ratio (DITANDAI TEGAS BUKAN SAME-TIME RVOL KARENA TIADANYA BASELINE INTRADAY SETARA)
            $volVsEodRatio = ($prevVolume > 0)
                ? round($agg['volume_lots'] / $prevVolume, 4)
                : 0.0;

            $entry = array_merge($agg, [
                'prev_close' => $prevClose,
                'change_pct' => $changePct,
                'prev_eod_volume' => $prevVolume,
                'volume_vs_prev_eod_ratio' => $volVsEodRatio,
                'same_time_rvol_available' => false, // Tiada baseline same-time
            ]);

            $results[$ticker] = $entry;

            // Aturan Kandidat Sinyal Eksplisit:
            // Strategi: MOMENTUM_RG_BREAKOUT_V1
            // Syarat: change_pct >= +2.0%, turnover >= Rp 10M, volume >= 50.000 lot
            if ($changePct >= 2.0 && $agg['turnover_idr'] >= 10_000_000_000 && $agg['volume_lots'] >= 50_000) {
                $candidates[] = [
                    'strategy' => 'MOMENTUM_RG_BREAKOUT_V1',
                    'ticker' => $ticker,
                    'decision_time' => '12:00:00 WIB',
                    'evidence_cutoff' => '12:00:00 WIB',
                    'trigger_condition' => sprintf('Penguatan +%.2f%% di atas previous close nominal dengan turnover Rp %.2f M dan volume %.0f lot', $changePct, $agg['turnover_idr'] / 1e9, $agg['volume_lots']),
                    'invalidation_condition' => sprintf('Penurunan harga di bawah harga pembukaan sesi (Rp %.0f) atau breakdown support intraday', $agg['first_price']),
                    'limitations' => 'Data tidak memuat same-time RVOL atau orderbook depth level 2; agresor flow buy/sell belum terdefinisi resmi',
                ];
            }
        }

        return [
            'aggregates' => $results,
            'candidates' => $candidates,
            'has_signals' => ! empty($candidates),
            'signal_status' => empty($candidates) ? 'BELUM ADA SINYAL' : 'KANDIDAT TERIDENTIFIKASI',
        ];
    }

    /**
     * Sintesis narasi awam via Gemini / 9router Hermes atau format terstruktur fallback.
     */
    public function synthesizeWithGemini(array $reportContext, bool $isRgReady): string
    {
        $nowWib = Carbon::now('Asia/Jakarta')->format('d M Y, H:i');

        if (! $isRgReady) {
            $prompt = <<<'PROMPT'
Anda adalah asisten kecerdasan pasar Aether Market Intelligence.
Buat ringkasan awam dalam bahasa Indonesia mengenai situasi sesi 1 bursa (12:15 WIB) dengan fakta terverifikasi:
1. Kondisi Sesi 1: Aliran data live Pasar Reguler (RG) saat ini tidak dapat disajikan karena stream WebSocket running trade mencampur seluruh board tanpa penanda RG (MIXED_BOARD_UNSEPARABLE) dan REST price dikarantina.
2. Perubahan Penting: Sesi berjalan tidak dapat dianalisis perubahannya secara presisi tanpa pemisahan Pasar Reguler; baseline penutupan kemarin hanya sebagai konteks pembanding.
3. Kandidat & Alasan: BELUM ADA SINYAL (tidak boleh mengarang sinyal dari data yang belum tervalidasi).
4. Risiko & Kondisi Pembatalan: Risiko kontaminasi crossing pasar negosiasi pada harga semu; batalkan analisis teknikal intraday hingga feed RG terisolasi.
5. Data yang Belum Tersedia: Indeks IHSG live intraday dan pemisahan board Pasar Reguler (RG).
Tulis secara padat, lugas, tanpa basa-basi, dan tanpa tanda bintang berlebihan.
PROMPT;
        } else {
            $prompt = 'Anda adalah asisten Aether Market Intelligence. Tulis ringkasan awam sesi 1 bursa berdasarkan kandidat dan metrik RG yang tervalidasi: '.json_encode($reportContext);
        }

        $endpoint = rtrim(config('services.ai_router.endpoint', env('AI_ROUTER_ENDPOINT', 'http://127.0.0.1:20128/v1')), '/').'/chat/completions';
        $modelId = config('services.ai_router.model_id', env('AI_MODEL_ID', 'ag/gemini-3.8-flash-high'));

        try {
            $resp = Http::timeout(10)->post($endpoint, [
                'model' => $modelId,
                'messages' => [
                    ['role' => 'system', 'content' => 'Anda asisten analis pasar modal profesional. Format teks polos, padat, tanpa markdown tebal berlebihan.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.2,
                'max_tokens' => 450,
            ]);

            if ($resp->successful()) {
                $content = $resp->json('choices.0.message.content');
                if (! empty($content)) {
                    return trim($content);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[MIDDAY_SYNTHESIS] Gagal menghubungi AI router: {$e->getMessage()}");
        }

        // Deterministic Fallback Synthesis
        return implode("\n", [
            '• Kondisi Sesi 1 : Aliran transaksi live belum memenuhi kualifikasi Pasar Reguler (RG) terisolasi.',
            '• Perubahan Kunci: Tiada kalkulasi candle/perubahan harga intraday sebelum pemisahan board tervalidasi.',
            '• Status Sinyal  : BELUM ADA SINYAL (Ketiadaan data RG mencegah pembentukan sinyal kuantitatif).',
            '• Risiko & Batal : Menghindari sinyal palsu akibat transaksi Pasar Negosiasi (NG) non-reguler.',
            '• Data Tertunda  : Kolom board resmi RG pada feed running-trade dan pergerakan indeks IHSG berjalan.',
        ]);
    }

    /**
     * Terbitkan laporan ke TelegramOutbox dengan due_at dan deduplikasi slot.
     */
    public function publishMiddayReport(string $reportText, Carbon $nowWib, string $slot = '12:15', bool $dryRun = false): array
    {
        $cleanSlot = str_replace(':', '', $slot);
        $dedupKey = "MARKET_UPDATE_{$nowWib->format('Ymd')}_{$cleanSlot}";

        if ($dryRun) {
            return [
                'status' => 'DRY_RUN',
                'dedup_key' => $dedupKey,
                'payload' => $reportText,
            ];
        }

        $existing = TelegramOutbox::where('dedup_key', $dedupKey)->first();
        if ($existing && $existing->status === 'SENT') {
            return [
                'status' => 'ALREADY_SENT',
                'outbox_id' => $existing->id,
                'telegram_message_id' => $existing->telegram_message_id,
            ];
        }

        $dueAt = Carbon::createFromFormat('Y-m-d H:i', "{$nowWib->format('Y-m-d')} {$slot}", 'Asia/Jakarta')->setTimezone('UTC');
        $runId = "RUN_MIDDAY_{$nowWib->format('Ymd')}_{$cleanSlot}_".Str::lower(Str::random(4));
        $chatId = config('services.telegram.chat_id', '5923895922');
        $triggerSource = 'SCHEDULED_MIDDAY';

        AnalysisRun::firstOrCreate(
            ['run_id' => $runId],
            [
                'trigger_type' => "midday_session_{$cleanSlot}",
                'status' => 'completed',
                'started_at' => $nowWib,
                'completed_at' => now('Asia/Jakarta'),
                'metadata' => [
                    'slot' => $slot,
                    'report_type' => 'MIDDAY_SESSION_REPORT',
                    'board_scope' => 'MIXED_BOARD_UNSEPARABLE',
                    'trigger_source' => $triggerSource,
                ],
            ]
        );

        $outboxItem = $this->formatter->enqueueOutbox(
            dedupKey: $dedupKey,
            messageType: 'SYSTEM_ALERT',
            payload: $reportText,
            predictionId: null,
            replyToMessageId: null,
            chatId: $chatId,
            dueAt: $dueAt,
            triggerSource: $triggerSource,
            metadata: [
                'report_type' => 'MIDDAY_SESSION_REPORT',
                'slot' => $slot,
                'session_date' => $nowWib->format('Y-m-d'),
                'run_id' => $runId,
                'chat_id' => $chatId,
                'due_at_wib' => "{$nowWib->format('Y-m-d')} {$slot} WIB",
            ]
        );

        return [
            'status' => 'ENQUEUED',
            'outbox_id' => $outboxItem->id,
            'due_at' => $dueAt->toIso8601String(),
            'dedup_key' => $dedupKey,
        ];
    }

    /**
     * Sintesis narasi deterministik tanpa ketergantungan external AI/LLM.
     */
    public function synthesizeDeterministic(array $coverage, bool $isRgReady): string
    {
        if (! $isRgReady) {
            return implode("\n", [
                "• Aliran Pasar   : Stream running trade berstatus {$coverage['board_scope']}; belum terbukti bebas dari pasar non-reguler.",
                "• Observasi Sesi : Kategori {$coverage['coverage_status']} ({$coverage['observation_window']}).",
                '• Keputusan Sinyal: DATA_CAPABILITY_BLOCKED (Dilarang mempublikasikan setup spekulatif tanpa data RG terisolasi).',
                '• Proteksi Risiko: Menghindari false breakout/crossing volume palsu pasar negosiasi.',
                '• Aksi Lanjutan  : Menunggu konfirmasi kontrak pemisahan papan resmi dari penyedia data.',
            ]);
        }

        return implode("\n", [
            '• Aliran Pasar   : Pasar Reguler (RG) tervalidasi.',
            '• Kandidat       : '.count($coverage['candidates'] ?? []).' kandidat memenuhi kriteria momentum.',
            '• Status Sinyal  : '.($coverage['signal_status'] ?? 'KANDIDAT TERIDENTIFIKASI'),
        ]);
    }

    protected function getTechnicalBlockers(string $coverageStatus = 'BOARD_SCOPE_UNVERIFIED', array $state = []): array
    {
        $blockers = [];

        if ($coverageStatus === 'COLLECTOR_DISCONNECTED') {
            $blockers[] = 'Koneksi supervisor WebSocket terputus atau tidak merespons (COLLECTOR_DISCONNECTED).';
        } elseif ($coverageStatus === 'STALE_STATE') {
            $blockers[] = 'State supervisor running trade terdeteksi usang/heartbeat tidak bergerak lebih dari 60 detik (STALE_STATE).';
        } elseif ($coverageStatus === 'SESSION_NOT_OBSERVED') {
            $blockers[] = 'Aktivitas perdagangan Sesi 1 (09:00 - 12:00 WIB) tidak terekam live oleh supervisor dan upstream tidak menyediakan endpoint replay historis berjalan (SESSION_NOT_OBSERVED).';
        }

        $blockers[] = 'Payload tick WebSocket wss://stock.arjum.com/ws/running-trade belum menyediakan atribut pembeda papan bursa (status resmi: BOARD_SCOPE_UNVERIFIED).';
        $blockers[] = 'Endpoint REST kuotasi intraday (/api/price) mencampur transaksi Pasar Negosiasi (NG) tanpa filter papan dan berstatus QUARANTINED.';
        $blockers[] = 'Nilai indeks komposit IHSG berjalan tidak disediakan dalam stream transaksi saham bursa.';

        return $blockers;
    }

    protected function getProviderInquiries(): array
    {
        return [
            'Q1: Cakupan Papan: Apakah stream wss://stock.arjum.com/ws/running-trade mencakup seluruh board (RG/NG/TN) atau murni Pasar Reguler (RG)?',
            'Q2: Atribut Papan: Apakah upstream dapat menambahkan field "board" pada payload trade?',
            'Q3: Timestamp Event: Apakah jam pada atribut "a" memiliki metadata tanggal bursa dan timezone resmi?',
            'Q4: Nomor Transaksi: Apakah nomor urut pada "tn" bersifat continuous across all exchange trades atau scoped per stream?',
            'Q5: Flag Aksi: Bagaimana definisi kalkulasi atribut "c" ("buy"/"sell") dan metrik "net_buy"/"net_sell" pada ringkasan buffer top5?',
            'Q6: Indeks Acuan: Ketersediaan data live indeks IHSG (^JKSE) berjalan.',
        ];
    }
}
