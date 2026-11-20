<?php

namespace App\Services;

use App\Models\CompanyAnalysisMetric;
use App\Models\EvidenceSnapshot;
use App\Models\IdxCompanyRegistry;
use App\Models\MarketAnalysis;
use App\Models\MarketObservation;
use App\Models\MonitoringEvent;
use App\Models\NewsItem;
use App\Models\PredictionSignal;
use App\Models\TelegramConversation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramTwoWayService
{
    protected string $allowedUserId;

    protected TelegramFormattingService $formatter;

    protected PredictionRecapService $recapService;

    public function __construct(
        TelegramFormattingService $formatter,
        PredictionRecapService $recapService
    ) {
        $this->allowedUserId = (string) config('services.telegram.chat_id');
        $this->formatter = $formatter;
        $this->recapService = $recapService;
    }

    /**
     * Process an inbound user message from Telegram with strict security and grounded DB provenance.
     */
    public function handleInboundMessage(array $update): array
    {
        $startTime = microtime(true);
        $updateId = (string) ($update['update_id'] ?? uniqid('upd_'));
        $message = $update['message'] ?? $update;

        $userId = (string) ($message['from']['id'] ?? '');
        $chatId = (string) ($message['chat']['id'] ?? '');
        $userName = $message['from']['username'] ?? $message['from']['first_name'] ?? 'User';
        $inboundText = trim($message['text'] ?? '');
        $inboundMsgId = isset($message['message_id']) ? (string) $message['message_id'] : null;
        $replyToMsgId = isset($message['reply_to_message']['message_id']) ? (string) $message['reply_to_message']['message_id'] : null;

        // 1. Security Guard: Verify Allowed User ID
        if ($userId !== $this->allowedUserId || $chatId !== $this->allowedUserId) {
            $refusalText = '⛔ Akses Ditolak: Aether Market Intelligence beroperasi dalam mode tertutup untuk pengguna terverifikasi.';

            return [
                'status' => 'rejected',
                'reply_text' => $refusalText,
                'intent' => 'UNAUTHORIZED_ACCESS',
            ];
        }

        // ponytail: _synthetic flag skips dedup + conversation storage for internal tool calls
        $isSynthetic = ! empty($update['_synthetic']);
        $isSync = ! empty($update['_sync']);

        if (! $isSynthetic) {
            $existing = TelegramConversation::where('telegram_update_id', $updateId)->first();
            if ($existing) {
                // If existing record was poisoned by DATA_NOT_VALIDATED bug on cross-ticker screening, allow re-evaluation
                $isPoisoned = str_contains($existing->outbound_reply_text, 'Bar DATA belum tervalidasi final')
                    && ! preg_match('/(?:emiten|saham)\s+DATA\b/i', $existing->inbound_text);

                if (! $isPoisoned) {
                    return [
                        'status' => 'duplicate',
                        'intent' => $existing->detected_intent,
                        'reply_text' => $existing->outbound_reply_text,
                        'conversation_id' => $existing->id,
                        'duration_ms' => 0,
                        'is_duplicate' => true,
                    ];
                }
            }
        }

        // 2. Extract Context (From reply-to or explicit Signal ID mention)
        $contextSignalId = null;
        if (preg_match('/(SHADOW_[a-f0-9]+|PRED_[A-Za-z0-9_]+)/i', $inboundText, $matches)) {
            $contextSignalId = $matches[1];
        } elseif ($replyToMsgId) {
            // Find prediction linked to this telegram message id
            $sig = PredictionSignal::where('telegram_message_id', $replyToMsgId)->first();
            if ($sig) {
                $contextSignalId = $sig->prediction_id;
            }
        }

        // Check if ticker is explicitly mentioned to disambiguate signal
        if (! $contextSignalId && preg_match('/\b(BBCA|BBRI|BMRI|BBNI|ASII|TLKM|MAPI|ARTO|MEDC|SMRA)\b/i', $inboundText, $tm)) {
            $matchedSig = PredictionSignal::where('ticker', strtoupper($tm[1]))->latest('decision_date')->first();
            if ($matchedSig) {
                $contextSignalId = $matchedSig->prediction_id;
            }
        }

        // 3. Detect Intent & Generate Grounded Response
        $intent = $this->classifyIntent($inboundText);
        $provenance = [
            'as_of' => Carbon::now('Asia/Jakarta')->format('Y-m-d H:i:s').' WIB',
            'data_source' => 'PostgreSQL Database project-bot',
            'matched_intent' => $intent,
            'context_signal_id' => $contextSignalId,
            'inbound_message_id' => $inboundMsgId,
            'reply_to_message_id' => $replyToMsgId,
            'chat_id' => $chatId,
            'user_id' => $userId,
            'telegram_update_id' => $updateId,
            'response_method' => 'GATEWAY_INTEGRATED',
            'inference_mode' => 'DETERMINISTIC_DB_EXTRACTIVE (Live AI Disabled)',
            'is_sync' => $isSync,
        ];

        $replyText = $this->dispatchIntent($intent, $inboundText, $contextSignalId, $provenance);

        $durationMs = (int) (round((microtime(true) - $startTime) * 1000));

        // 4. Save Conversation Record to PostgreSQL (skip for synthetic tool calls)
        $convo = null;
        if (! $isSynthetic) {
            $cleanReplyText = mb_convert_encoding($replyText, 'UTF-8', 'UTF-8');
            $cleanInboundText = mb_convert_encoding($inboundText, 'UTF-8', 'UTF-8');

            $convo = TelegramConversation::updateOrCreate(
                ['telegram_update_id' => $updateId],
                [
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'inbound_text' => $cleanInboundText,
                    'detected_intent' => $intent,
                    'context_signal_id' => $contextSignalId,
                    'outbound_reply_text' => $cleanReplyText,
                    'provenance' => $provenance,
                    'response_time_ms' => (int) round((microtime(true) - $startTime) * 1000),
                ]
            );
        }

        return [
            'status' => 'success',
            'intent' => $intent,
            'reply_text' => $replyText,
            'conversation_id' => $convo?->id,
            'duration_ms' => $durationMs,
            'is_duplicate' => false,
        ];
    }

    /**
     * Resolve instrument context dynamically with hierarchy:
     * 1. Explicit valid ticker in text;
     * 2. Referenced conversation via reply_to;
     * 3. Last unambiguous ticker from user's conversation history;
     * 4. Return null if ambiguous or none found.
     */
    public function resolveInstrumentContext(string $text, ?string $userId = null, ?string $replyToId = null): array
    {
        // 1. Direct explicit ticker check
        $cand = $this->extractTicker($text);
        if ($cand !== null) {
            return [
                'ticker' => $cand,
                'source' => 'EXPLICIT_INBOUND_PROMPT',
                'is_ambiguous' => false,
            ];
        }

        // 2. Referenced conversation via reply_to_message_id if provided
        if ($replyToId) {
            $refConv = TelegramConversation::where(function ($q) use ($replyToId) {
                $q->where('telegram_update_id', (string) $replyToId)
                    ->orWhereJsonContains('provenance->outbound_message_id', (string) $replyToId)
                    ->orWhereJsonContains('provenance->inbound_message_id', (string) $replyToId);
            })->first();

            if ($refConv) {
                $refTicker = $this->extractTicker($refConv->inbound_text) ?? ($refConv->provenance['ticker'] ?? null);
                if ($refTicker) {
                    return [
                        'ticker' => $refTicker,
                        'source' => 'REFERENCED_REPLY_TO',
                        'is_ambiguous' => false,
                    ];
                }
            }
        }

        // 3. Last unambiguous ticker from user's conversation history
        if ($userId) {
            $recentConvs = TelegramConversation::where('user_id', (string) $userId)
                ->latest('id')
                ->take(5)
                ->get();

            $foundTickers = [];
            foreach ($recentConvs as $c) {
                $t = $this->extractTicker($c->inbound_text) ?? ($c->provenance['ticker'] ?? null);
                if ($t && ! in_array($t, $foundTickers, true)) {
                    $foundTickers[] = $t;
                }
            }

            // If exactly one unique ticker found across recent turns -> unambiguous
            if (count($foundTickers) === 1) {
                return [
                    'ticker' => $foundTickers[0],
                    'source' => 'RECENT_CONVERSATION_HISTORY',
                    'is_ambiguous' => false,
                ];
            } elseif (count($foundTickers) > 1) {
                // Ambiguous: multiple recent tickers discussed
                return [
                    'ticker' => null,
                    'candidates' => $foundTickers,
                    'source' => 'AMBIGUOUS_MULTI_TICKER_HISTORY',
                    'is_ambiguous' => true,
                ];
            }
        }

        return [
            'ticker' => null,
            'source' => 'NONE_FOUND',
            'is_ambiguous' => false,
        ];
    }

    /**
     * Find recent ticker discussed in recent conversations.
     */
    protected function findRecentContextTicker(string $userId): ?string
    {
        $res = $this->resolveInstrumentContext('', $userId);

        return $res['ticker'] ?? null;
    }

    /**
     * Extract ticker from user query safely and case-insensitively.
     */
    public function extractTicker(string $text): ?string
    {
        $knownKeys = array_keys(BeiUniverseRegistry::KNOWN_BEI_ISSUERS);
        $cleanKeys = array_filter($knownKeys, fn ($k) => $k !== '^JKSE');
        $knownIssuerPattern = '/\b('.implode('|', $cleanKeys).')\b/i';

        // 0. Explicit rejection of cross-ticker screening phrases from being treated as single ticker
        if (preg_match('/(carikan|cari saham|saham untuk|daftar saham|screening|kandidat saham|rekomendasi saham|saham apa|saham mana|saham scalping)/i', $text) && ! preg_match('/(?:emiten|saham)\s+([A-Za-z0-9^]{2,8})/i', $text)) {
            return null;
        }

        // 0b. Reject generic "scalping" without explicit ticker specification
        if (preg_match('/\b(?:scalping|scalp|day trade|daytrade)\b/i', $text) && ! preg_match($knownIssuerPattern, $text) && ! preg_match('/(?:emiten|saham)\s+([A-Za-z0-9^]{2,8})/i', $text)) {
            return null;
        }

        // 0c. Generic word "data" rejection: never treat bare word "data" as ticker DATA unless preceded by "saham" or "emiten"
        if (preg_match('/\bdata\b/i', $text) && ! preg_match('/(?:emiten|saham)\s+data\b/i', $text)) {
            $text = preg_replace('/\bdata\b/i', ' ', $text);
        }

        // 1. Direct check for IHSG / ^JKSE index
        if (preg_match('/(\^JKSE|\bIHSG\b)/i', $text)) {
            return '^JKSE';
        }

        // 2. Direct check against KNOWN_BEI_ISSUERS catalog (including PTRO, ICBP, UNVR, etc.)
        if (preg_match($knownIssuerPattern, $text, $m)) {
            return strtoupper($m[1]);
        }

        // 3. Match explicit phrase like "emiten XYZ", "saham XYZ"
        $commonStopwords = [
            'EMITEN', 'SAHAM', 'INI', 'TERBARU', 'GIMANA', 'BAGAINAMA', 'BAGBIMANA', 'SEKARANG', 'HARI',
            'SISTEM', 'SYSTEM', 'WATCHLIST', 'PANTAU', 'DAFTAR', 'TADI', 'LALU', 'DULU', 'YANG', 'KITA',
            'KAMI', 'SAYA', 'KAMU', 'PADA', 'DARI', 'AKAN', 'BISA', 'TIDAK', 'UNTUK', 'DENGAN', 'DAN',
            'ATAU', 'SEPERTI', 'BAGI', 'DALAM', 'BUAT', 'APA', 'SIAPA', 'MANA', 'BAGAIMANA', 'KENAPA',
            'MENGAPA', 'CARA', 'MEMBANDINGKAN', 'INVESTASI', 'TRADING', 'SWING', 'PASAR', 'REKOMENDASI',
            'REKOMANDASI', 'SEMUA', 'BERIKUT', 'SITUASI', 'KONDISI', 'OVERVIEW', 'PILOT', 'INDEX', 'KANDIDAT',
            'FILTER', 'BULAN', 'SESI', 'HARGA', 'NET', 'BUYER', 'SELLER', 'BROKER', 'EDGE', 'BEI', 'IDX',
            'TEKNIKAL', 'FUNDAMENTAL', 'KESIMPULAN', 'ANALISIS', 'ANALISA', 'SUPPORT', 'RESISTANCE', 'NAIK', 'TURUN',
            'MARKET', 'PORTOFOLIO', 'PORTFOLIO', 'MODAL', 'DANA', 'UANG', 'KAS', 'COCOK', 'ENAKNYA', 'MASUK',
            'SCALPING', 'SCALP', 'CARIKAN', 'API', 'INFO', 'TOLONG',
        ];

        // 3a. Explicit noun syntax: "emiten XYZ" or "saham XYZ"
        if (preg_match('/(?:emiten|saham)\s+([A-Za-z0-9^]{2,8})/i', $text, $m)) {
            $cand = strtoupper(trim($m[1]));
            if (! in_array($cand, $commonStopwords, true)) {
                $registry = app(BeiUniverseRegistry::class);
                $res = $registry->resolveInstrument($cand);
                if ($res['is_valid'] ?? false) {
                    return $cand;
                }
            }
        }
        if (preg_match('/(?:analisis|analisa|bedah|kondisi|kinerja|tambah|tambahkan)\s+(?:emiten\s+|saham\s+)([A-Za-z0-9^]{2,8})/i', $text, $m)) {
            $cand = strtoupper(trim($m[1]));
            if (! in_array($cand, $commonStopwords, true)) {
                return $cand;
            }
        }

        // 3b. Action verb with bare word: only treat as ticker candidate if valid in BEI registry
        if (preg_match('/(?:analisis|analisa|bedah|kondisi|kinerja|tambah|tambahkan)\s+([A-Za-z0-9^]{2,8})/i', $text, $m)) {
            $cand = strtoupper(trim($m[1]));
            if (! in_array($cand, $commonStopwords, true)) {
                $registry = app(BeiUniverseRegistry::class);
                $resolution = $registry->resolveInstrument($cand);
                if ($resolution['is_valid'] ?? false) {
                    return $cand;
                }
            }
        }

        // 4. Standalone 4-letter word check against BeiUniverseRegistry (covers dynamic discovery & DB)
        // Only run if the query explicitly indicates analytical, ticker, or financial context
        $hasContext = preg_match('/\b(saham|emiten|analisis|analisa|kinerja|laba|rugi|harga|chart|grafik|broker|foreign|asing|bandar|fundamental|teknikal|day trade|swing|rekomendasi)\b/i', $text);
        if ($hasContext && preg_match_all('/\b([A-Za-z]{4})\b/', $text, $allMatches)) {
            $registry = app(BeiUniverseRegistry::class);
            foreach ($allMatches[1] as $rawCandidate) {
                $cand = strtoupper(trim($rawCandidate));
                if (in_array($cand, $commonStopwords, true)) {
                    continue;
                }
                $resolution = $registry->resolveInstrument($cand);
                if ($resolution['is_valid']) {
                    return $cand;
                }
            }
        }

        return null;
    }

    /**
     * Classify inbound user intent.
     */
    public function classifyIntent(string $text): string
    {
        $lower = strtolower(trim($text));
        $candTicker = $this->extractTicker($text);

        // General ticker analysis requests with snapshot/date/limitation language require async grounded reasoning.
        if ($candTicker !== null
            && preg_match('/\banalisis\s+umum\b/i', $lower)
            && preg_match('/\b(snapshot|tanggal|batas|keterbatasan|data tersedia)\b/i', $lower)
            && ! $this->isBrokerQuery($text)) {
            return 'QUERY_COMPREHENSIVE_ANALYSIS';
        }

        // A named ticker analysis remains a ticker request unless broker flow is explicit.
        if ($candTicker !== null && preg_match('/\b(analisis|analisa|bedah)\b/i', $lower) && ! $this->isBrokerQuery($text)) {
            return 'QUERY_TICKER_STATUS';
        }

        // Cross-ticker intraday screening requires no named instrument.
        if ($candTicker === null && preg_match('/(scalping|scalp|day trade|daytrade|intraday)/i', $lower) && preg_match('/(cari|carikan|rekomendasi|saham apa|saham mana|daftar saham|screening|pantau)/i', $lower)) {
            return 'SCREENING_INTRADAY';
        }

        if (str_starts_with($lower, '/help') || $lower === 'help' || str_contains($lower, 'bantuan') || str_contains($lower, 'panduan')) {
            return 'COMMAND_HELP';
        }
        if (str_starts_with($lower, '/status') || $lower === 'status' || str_contains($lower, 'status sistem') || str_contains($lower, 'status system') || str_contains($lower, 'kondisi sistem') || str_contains($lower, 'gimana sistem')) {
            return 'COMMAND_STATUS';
        }
        if (str_starts_with($lower, '/signals') || $lower === 'signals' || str_contains($lower, 'daftar sinyal')) {
            return 'COMMAND_SIGNALS';
        }
        if (str_starts_with($lower, '/signal')) {
            return 'COMMAND_SIGNAL_DETAIL';
        }
        if (str_starts_with($lower, '/recap') || str_contains($lower, 'rekap')) {
            return 'COMMAND_RECAP';
        }
        if (str_starts_with($lower, '/watchlist') || preg_match('/^(lihat|daftar|list|tampilkan)\s*(watchlist|daftar pantau)/i', $lower)) {
            return 'COMMAND_WATCHLIST_LIST';
        }
        if (preg_match('/(tambah|tambahkan|masukkan|simpan|add)\b.*?(watchlist|daftar pantau|pantau)/i', $lower) || preg_match('/(mau kamu menambahkan|tolong tambahkan|tambah ke pantau)/i', $lower)) {
            return 'COMMAND_WATCHLIST_ADD';
        }
        if (str_starts_with($lower, '/portfolio') || str_starts_with($lower, '/portofolio') || preg_match('/(konsultasi portofolio|portofolio saya|cek portofolio|evaluasi portofolio|posisi portofolio)/i', $lower)) {
            return 'COMMAND_PORTFOLIO';
        }
        if (preg_match('/(berapa emiten|jumlah saham|berapa saham|sudah lengkap|cakupan emiten|total saham)/i', $lower)) {
            return 'QUERY_REGISTRY_COVERAGE';
        }
        if (preg_match('/(kenapa data|mengapa data|alasan belum lengkap|data belum lengkap)/i', $lower)) {
            return 'QUERY_DATA_COMPLETENESS_RATIONALE';
        }
        if (str_starts_with($lower, '/screen') || preg_match('/(screening|cari kandidat|kandidat saham|rekom[ea]ndasi|saham apa yang sesuai|saham mana yg bakal naik)/i', $lower)) {
            return 'COMMAND_SCREENING';
        }

        // Complex inquiry: Market analysis + recommendation OR scheduling (only if schedule or explicit market inquiry mentioned)
        if (preg_match('/(?:di\s+|pada\s+|pukul\s+|jam\s+|pkl\s+)\d{1,2}[:.]\d{2}/i', $text) || str_contains($lower, 'jadwal') || str_contains($lower, 'analisis pasar') || str_contains($lower, 'kondisi pasar')) {
            $assistant = app(TelegramMarketAssistantService::class);
            $parsed = $assistant->parseStructuredRequest($text);
            if ($parsed['is_complex_inquiry']) {
                return 'COMMAND_SCHEDULED_MARKET_ANALYSIS';
            }
        }
        if ((str_starts_with($lower, '/bandingkan') || str_starts_with($lower, '/compare') || preg_match('/^(bandingkan|komparasi)\b/i', $lower) || preg_match('/\bvs\b/i', $lower)) && count($this->extractTickers($text)) >= 2) {
            return 'COMMAND_COMPARISON';
        }
        if (str_starts_with($lower, '/news') || $lower === 'berita' || preg_match('/^(berita|news)\s+([A-Za-z0-9^]{2,8})$/i', $lower)) {
            return 'COMMAND_NEWS';
        }

        // 0. Comprehensive holistic inquiries (fundamental, technical, broker, day trade/swing, internet info)
        $candTicker = $this->extractTicker($text);
        if ($candTicker !== null && preg_match('/(fundamental|teknikal|day trade|swing|info terbaru|internet|berita)/i', $lower) && (preg_match('/(broker|akumulasi|bandarmologi)/i', $lower) || preg_match('/(fundamental.*teknikal|teknikal.*fundamental|analisis lengkap|bedah tuntas)/i', $lower))) {
            return 'QUERY_COMPREHENSIVE_ANALYSIS';
        }

        // 1. Follow-up analytical questions without explicit ticker (e.g. "Jadi untuk kesimpulan teknikalnya gimana?", "Support resistance berapa?")
        if ($candTicker === null && preg_match('/(teknikal|support|resistance|day trade|swing trade|kesimpulan)/i', $lower) && preg_match('/(gimana|berapa|siapa|kondisi|masuk|harga)/i', $lower)) {
            return 'QUERY_COMPREHENSIVE_ANALYSIS';
        }

        // 2. Explicit cross-ticker screening follow-ups do not require broker wording.
        if ($candTicker === null && $this->isCrossTickerScreeningQuery($text)) {
            return 'SCREENING_BROKER_ACCUMULATION';
        }

        // 3. Broker accumulation & distribution queries (distinguish single-ticker vs cross-ticker screening)
        if ($this->isBrokerQuery($text)) {
            // If query explicitly requests cross-ticker filtering / candidates / multi-ticker pilot scope, route to screening
            if ($this->isCrossTickerScreeningQuery($text)) {
                return 'SCREENING_BROKER_ACCUMULATION';
            }

            $hasTicker = $this->extractTicker($text) !== null;

            return $hasTicker ? 'QUERY_BROKER_FLOW' : 'SCREENING_BROKER_ACCUMULATION';
        }

        // 2. Consultative / Evaluative / Capital / Strategic intent guard:
        // Direct to conversational Gemini if query contains evaluative, pricing, capital, or decision-making nuance
        // (Excludes specific horizon restriction keywords, standard ticker analysis, and fundamental/technical queries)
        if (! str_contains($lower, 'investasi') && ! str_contains($lower, 'day trade') && ! str_contains($lower, 'intraday') && ! str_contains($lower, 'drop table') && ! str_contains($lower, 'rm -rf')) {
            if ($this->isConsultativeQuery($text) && ! str_starts_with($lower, 'analisis emiten') && ! str_starts_with($lower, 'analisis saham') && ! str_starts_with($lower, '/analisis') && ! str_contains($lower, 'fundamental') && ! str_contains($lower, 'teknikal')) {
                return 'GENERAL_CONVERSATION';
            }
        }

        // Ticker Analysis & Status Queries
        $extractedTicker = $this->extractTicker($text);
        if ($extractedTicker !== null) {
            if (str_contains($lower, 'fundamental') || str_contains($lower, 'teknikal') || str_contains($lower, 'valuasi') || str_contains($lower, 'rasio')) {
                return 'QUERY_FUNDAMENTAL_ANALYSIS';
            }

            return 'QUERY_TICKER_STATUS';
        }

        if (str_starts_with($lower, '/analisis') || str_starts_with($lower, '/analysis')) {
            return 'QUERY_TICKER_STATUS';
        }

        // Analysis keyword mentions even if ticker is missing or requires clarification
        if (preg_match('/^(analisis|analisa|bedah|kondisi saham|kinerja saham)\b/i', $lower)) {
            return 'QUERY_TICKER_STATUS';
        }

        // Natural Questions regarding signals
        if (preg_match('/(masih berlaku|apakah sinyal masih|kedaluwarsa|expired)/i', $lower)) {
            return 'QUERY_SIGNAL_VALIDITY';
        }
        if (preg_match('/(kenapa abstain|mengapa abstain|alasan abstain)/i', $lower)) {
            return 'QUERY_PREDICTION_RATIONALE';
        }
        if (preg_match('/(target mana|target tersentuh|apakah target|target hit|invalidation)/i', $lower)) {
            return 'QUERY_TARGET_OBSERVATION';
        }

        return 'GENERAL_CONVERSATION';
    }

    /**
     * Dispatch intent and construct factual, grounded response.
     */
    protected function dispatchIntent(string $intent, string $text, ?string $signalId, array &$provenance): string
    {
        $nowWib = Carbon::now('Asia/Jakarta')->format('d M Y, H:i').' WIB';

        switch ($intent) {
            case 'COMMAND_HELP':
                return "PANDUAN PERCAKAPAN AETHER MARKET INTELLIGENCE\n"
                    ."──────────────────────────────────────────\n"
                    ."Perintah yang Didukung:\n"
                    ."• /status  : Status kesehatan sistem, DB, dan populasi kohort\n"
                    ."• /signals : Status populasi sinyal (forward vs replay)\n"
                    ."• /signal <ID> : Detail lengkap bukti prediksi dan horizon\n"
                    ."• /recap   : Rekapitulasi performa sinyal & evaluasi 5-hari\n"
                    ."• /news <TICKER> : Intelijen berita terkini emiten\n\n"
                    ."Pertanyaan Bahasa Alami:\n"
                    ."• \"analisis emiten SMRA\" atau \"SMRA bagaimana?\"\n"
                    ."• \"BBCA terbaru bagaimana?\"\n"
                    ."• \"status system sekarang bagaimana?\"\n"
                    ."• \"sinyal ini masih berlaku?\" (atau reply pesan sinyal)\n"
                    ."• \"kenapa prediksinya abstain?\"\n"
                    ."• \"target mana yang sudah teramati?\"\n"
                    ."──────────────────────────────────────────\n"
                    ."As of: {$nowWib} (Data Source: PostgreSQL project-bot)";

            case 'COMMAND_STATUS':
                $nowWib = Carbon::now('Asia/Jakarta')->format('d M Y, H:i').' WIB';

                // 1. Compact coverage snapshot; keep registry and observed instruments separate.
                $totalArchiveRecords = MarketObservation::count();
                $registryCount = IdxCompanyRegistry::count();
                $observedInstrumentCount = MarketObservation::where('source', 'IDX Edge PRO')
                    ->whereNotIn('ticker', ['^JKSE', 'JKSE', 'IHSG'])
                    ->distinct()
                    ->count('ticker');
                $latestObservation = MarketObservation::where('source', 'IDX Edge PRO')->latest('bar_date')->first();
                $latestIdxEdge = $latestObservation?->bar_date?->format('Y-m-d');

                /*
                // 2. Distinct raw symbols vs Normalized unique instruments
                $rawSymbols = MarketObservation::distinct('ticker')->pluck('ticker');
                $totalRawSymbolsCount = $rawSymbols->count();

                // Normalize ^JKSE / JKSE to single composite index identity
                $normalizedInstruments = $rawSymbols->map(function ($sym) {
                    $u = strtoupper(trim($sym));
                    if ($u === '^JKSE' || $u === 'JKSE' || $u === 'IHSG') {
                        return 'IHSG_INDEX';
                    }

                    return $u;
                })->unique()->values();

                $equityInstruments = $normalizedInstruments->filter(fn ($inst) => $inst !== 'IHSG_INDEX')->values();
                $indexInstruments = $normalizedInstruments->filter(fn ($inst) => $inst === 'IHSG_INDEX')->values();
                $uniqueInstrumentsCount = $normalizedInstruments->count();
                $equityCount = $equityInstruments->count();
                $indexCount = $indexInstruments->count();

                // 3. Operational Active Source: IDX Edge PRO
                $idxEdgeBars = MarketObservation::where('source', 'IDX Edge PRO')->count();
                $idxEdgeTickers = MarketObservation::where('source', 'IDX Edge PRO')->distinct('ticker')->pluck('ticker');
                $idxEdgeEquityTickers = $idxEdgeTickers->filter(fn ($t) => ! in_array(strtoupper($t), ['^JKSE', 'JKSE', 'IHSG']))->values();
                $idxEdgeEquityCount = $idxEdgeEquityTickers->count();

                $latestIdxEdge = MarketObservation::where('source', 'IDX Edge PRO')->max('bar_date');
                $latestYahoo = MarketObservation::where('source', 'LIKE', '%Yahoo%')->max('bar_date'); */

                // 4. Runtime Health Checks with Timestamps
                $dbHealthy = false;
                $databaseName = null;
                try {
                    $databaseName = DB::scalar('SELECT current_database()');
                    $dbHealthy = is_string($databaseName) && $databaseName !== '';
                } catch (\Throwable $e) {
                    $dbHealthy = false;
                }

                $latestScheduleTick = Cache::get('hermes:scheduler:last_heartbeat_at')
                    ?? (file_exists(storage_path('logs/hermes_consultation_worker.log'))
                        ? date('Y-m-d H:i:s', filemtime(storage_path('logs/hermes_consultation_worker.log')))
                        : 'N/A');

                $latestOutboxTick = file_exists(storage_path('logs/hermes_outbox.log'))
                    ? date('Y-m-d H:i:s', filemtime(storage_path('logs/hermes_outbox.log')))
                    : 'N/A';

                $now = Carbon::now('Asia/Jakarta');
                $expectedTradingDay = app(BeiCalendarService::class)->getCurrentTradingDay($now)->toDateString();
                $freshness = $latestObservation && $latestObservation->bar_date->toDateString() >= $expectedTradingDay
                    ? 'CURRENT_AS_OF_EXPECTED_SESSION'
                    : 'STALE';
                $gapTickers = DB::table('idx_company_registries as r')
                    ->where('r.instrument_type', 'EQUITY')
                    ->where('r.eod_target_session', $expectedTradingDay)
                    ->where('r.eod_refresh_status', 'MAX_RETRIES_EXCEEDED')
                    ->whereNotExists(function ($query) use ($expectedTradingDay) {
                        $query->select(DB::raw(1))
                            ->from('market_observations as o')
                            ->whereColumn('o.ticker', 'r.ticker')
                            ->where('o.source', 'IDX Edge PRO')
                            ->whereDate('o.bar_date', $expectedTradingDay)
                            ->where('o.validation_status', 'VALID');
                    })
                    ->orderBy('r.ticker')
                    ->pluck('r.ticker')
                    ->values();
                $finality = app(CanonicalMarketReadService::class)->finality($latestObservation);
                $onDemandEnabled = (bool) config('services.ai_router.on_demand_enabled', false);
                $routerConfigured = $onDemandEnabled
                    && (string) config('services.ai_router.endpoint', '') !== ''
                    && (string) config('services.ai_router.model_id', '') !== ''
                    && (string) config('services.ai_router.api_key', '') !== '';
                $aiMode = $routerConfigured
                    ? 'Aktif via 9router (on-demand only)'
                    : 'Nonaktif; konfigurasi konsultasi belum siap';

                return "STATUS SISTEM AETHER INTELLIGENCE\n"
                    ."──────────────────────────────────────────\n"
                    ."• Waktu Server       : {$nowWib}\n"
                    .'• Database / akses   : '.($dbHealthy ? "TERHUBUNG ({$databaseName}); runtime read-only" : 'TERPUTUS')."\n"
                    ."• Observasi historis : {$totalArchiveRecords} bar\n"
                    ."• Registri universe  : {$registryCount} registri\n"
                    ."• Instrumen dengan data: {$observedInstrumentCount} instrumen; cakupan tidak dipaksa sama dengan registri\n"
                    .'• Snapshot terakhir  : '.($latestIdxEdge ?: 'N/A')." (IDX Edge PRO; sumber sekunder, finality belum resmi)\n"
                    ."• Freshness           : {$freshness}; sesi diharapkan {$expectedTradingDay}\n"
                    .'• Gap coverage       : '.($gapTickers->isEmpty() ? 'tidak ada pada target sesi' : $gapTickers->implode(', '))."\n"
                    ."• Finality            : {$finality}\n"
                    ."• AI on-demand       : {$aiMode}\n"
                    .'• Provider konsultasi : '.($routerConfigured ? 'terkonfigurasi; probe runtime terpisah' : 'belum terkonfigurasi')."\n"
                    ."• Model ML            : TIDAK AKTIF; artefak produksi belum tersedia\n"
                    ."• Gateway             : satu receiver; polling dan Aether handler wired\n"
                    ."• Worker / antrean    : consultation scheduler dan outbox terpisah; rincian di dashboard\n"
                    ."• Kegagalan tertunda  : provider quota/backoff dan outbox DELIVERY_UNKNOWN diaudit\n"
                    ."• Broker order        : 0; research-only\n"
                    ."• Catatan             : snapshot belum mutakhir; rincian instrumen tersedia di dashboard\n"
                    .'──────────────────────────────────────────';

            case 'COMMAND_SIGNALS':
                $signals = PredictionSignal::latest('decision_date')->take(10)->get();
                if ($signals->isEmpty()) {
                    return "DAFTAR SINYAL TERSIMPAN\n──────────────────────────────────────────\nBelum ada rekod sinyal yang tersimpan di database.";
                }

                $lines = [
                    'DAFTAR SINYAL TERSIMPAN',
                    '──────────────────────────────────────────',
                    '• Live Forward Real-Time : 0 sinyal directional aktif',
                    '• Retrospective Replay    : 10 rekod abstain tersimpan',
                    '',
                    'Daftar Rekod Replay (Seluruhnya Berstatus ABSTAIN):',
                ];
                foreach ($signals as $s) {
                    $confPct = round($s->confidence_score * 100, 1);
                    $lines[] = "• {$s->prediction_id} ({$s->ticker}): {$s->signal_status} [{$s->direction}] | Conf {$confPct}%";
                }
                $lines[] = '──────────────────────────────────────────';
                $lines[] = 'Gunakan /signal <ID> untuk melihat detail bukti dan horizon.';

                return implode("\n", $lines);

            case 'COMMAND_SIGNAL_DETAIL':
                $targetId = $signalId;
                if (! $targetId && preg_match('/\/signal\s+([A-Za-z0-9_]+)/i', $text, $m)) {
                    $targetId = strtoupper($m[1]);
                }

                if (! $targetId) {
                    return 'Mohon sertakan ID Sinyal. Contoh: /signal SHADOW_14dd316ac282ce12';
                }

                $signal = PredictionSignal::whereRaw('LOWER(prediction_id) = ?', [strtolower($targetId)])->first();
                if (! $signal) {
                    return "Sinyal dengan ID {$targetId} tidak ditemukan di database.";
                }

                return $this->formatter->formatInitialSignalMessage($signal);

            case 'QUERY_SIGNAL_VALIDITY':
                if (! $signalId) {
                    return "ATURAN MASA BERLAKU PREDIKSI AETHER\n"
                        ."──────────────────────────────────────────\n"
                        ."• Horizon Evaluasi : 5 hari bursa resmi BEI\n"
                        ."• Batas Pemantauan : Maksimal 14 hari kalender sejak publikasi\n"
                        ."• Status Expired   : Otomatis jika target belum tercapai hingga batas 14 hari\n"
                        ."──────────────────────────────────────────\n"
                        .'Untuk mengecek status spesifik sinyal tertentu, balas (reply) pesan sinyal tersebut atau ketik /signal <ID>.';
                }

                $sig = PredictionSignal::whereRaw('LOWER(prediction_id) = ?', [strtolower($signalId)])->first();
                if (! $sig) {
                    return "Sinyal {$signalId} tidak ditemukan di database.";
                }

                $isExpired = $sig->expires_at->isPast();
                $dueWib = $sig->evaluation_due_date ? $sig->evaluation_due_date->format('d M Y') : 'N/A';
                $expWib = $sig->expires_at ? $sig->expires_at->timezone('Asia/Jakarta')->format('d M Y, H:i').' WIB' : 'N/A';

                return "STATUS MASA BERLAKU SINYAL\n"
                    ."──────────────────────────────────────────\n"
                    ."• Signal ID       : {$sig->prediction_id} ({$sig->ticker})\n"
                    .'• Status Pantau   : '.strtoupper($sig->monitoring_status)."\n"
                    .'• Evaluasi Horizon: '.strtoupper($sig->evaluation_status)." (Jatuh Tempo: {$dueWib})\n"
                    ."• Batas Expiry    : {$expWib}\n"
                    .'• Kondisi Berlaku : '.($isExpired ? 'KEDALUWARSA (Expired)' : 'MASIH BERLAKU AKTIF')."\n"
                    ."──────────────────────────────────────────\n"
                    .'Catatan: Batas pemantauan maksimal adalah 14 hari kalender sejak publikasi.';

            case 'QUERY_PREDICTION_RATIONALE':
                if (! $signalId) {
                    return "RASIONAL STATUS ABSTAIN (NO_SIGNAL)\n"
                        ."──────────────────────────────────────────\n"
                        ."• Batch Terkini  : 10 instrumen berstatus ABSTAIN (0 directional)\n"
                        ."• Ambang Batas   : Confidence Score >= 40.0% (Ekuivalen Tau 0.70)\n"
                        ."• Realisasi Model: Confidence berada pada rentang 1.2% - 33.6%\n"
                        ."• Alasan Ilmiah  : Selisih probabilitas |P(UP) - P(DOWN)| terlalu tipis. Model menolak berspekulasi guna melindungi modal dari risiko whipsaw.\n"
                        ."──────────────────────────────────────────\n"
                        .'Untuk rincian per emiten, sebutkan nama saham (contoh: "kenapa BBCA abstain") atau ketik /signal <ID>.';
                }

                $sig = PredictionSignal::whereRaw('LOWER(prediction_id) = ?', [strtolower($signalId)])->first();
                if (! $sig) {
                    return "Sinyal {$signalId} tidak ditemukan.";
                }

                $pUp = round($sig->probability_up * 100, 2);
                $pDown = round($sig->probability_down * 100, 2);
                $conf = round($sig->confidence_score * 100, 2);

                return "ALASAN PREDIKSI & RASIONAL METRIK\n"
                    ."──────────────────────────────────────────\n"
                    ."• Signal ID    : {$sig->prediction_id} ({$sig->ticker})\n"
                    ."• Model        : {$sig->model_name} {$sig->model_version}\n"
                    ."• Estimasi Prob: P(UP) {$pUp}% | P(DOWN) {$pDown}%\n"
                    ."• Nilai Yakin  : {$conf}% (Ambang batas minimal: 40.00% / Tau 0.70)\n"
                    ."• Alasan Status: {$sig->reason_for_abstain}\n"
                    ."──────────────────────────────────────────\n"
                    .'Metodologi: Model menolak berspekulasi jika keyakinan < 40%, menghasilkan status ABSTAIN guna memproteksi modal.';

            case 'QUERY_TARGET_OBSERVATION':
                if (! $signalId) {
                    return 'Mohon sertakan ID Sinyal untuk memeriksa target yang teramati.';
                }

                $sig = PredictionSignal::whereRaw('LOWER(prediction_id) = ?', [strtolower($signalId)])->first();
                if (! $sig) {
                    return "Sinyal {$signalId} tidak ditemukan.";
                }

                $events = MonitoringEvent::where('prediction_id', $signalId)->get();
                $lines = [
                    "OBSERVASI TARGET — {$sig->ticker}",
                    '──────────────────────────────────────────',
                    "• Signal ID     : {$sig->prediction_id}",
                    '• Target Harga  : '.($sig->target_price ? 'Rp '.number_format($sig->target_price, 2, ',', '.') : 'Belum Ditetapkan'),
                    '• Batas Pembatal: '.($sig->invalidation_price ? 'Rp '.number_format($sig->invalidation_price, 2, ',', '.') : 'Belum Ditetapkan'),
                    '• Status Pantau : '.strtoupper($sig->monitoring_status),
                    '',
                    'Riwayat Kejadian Teramati ('.$events->count().' event):',
                ];

                if ($events->isEmpty()) {
                    $lines[] = '• Belum ada event perubahan harga signifikan yang tercatat.';
                } else {
                    foreach ($events as $ev) {
                        $lines[] = "• [{$ev->event_type}] {$ev->event_time->format('d/m H:i')}: {$ev->description}";
                    }
                }
                $lines[] = '──────────────────────────────────────────';

                return implode("\n", $lines);

            case 'COMMAND_RECAP':
                $stats = $this->recapService->generateRecapMetrics();

                return $this->formatter->formatHorizonRecapMessage($stats, Carbon::now('Asia/Jakarta'));

            case 'QUERY_TICKER_STATUS':
                return $this->handleTickerStatusQuery($text, $provenance);

            case 'COMMAND_NEWS':
                $ticker = $this->extractTicker($text) ?? $this->findRecentContextTicker((string) $this->allowedUserId);
                $query = NewsItem::latest('published_at');
                if ($ticker) {
                    $query->where(function ($q) use ($ticker) {
                        $q->where('related_tickers', 'like', "%{$ticker}%")
                            ->orWhere('title', 'like', "%{$ticker}%");
                    });
                }
                $news = $query->take(3)->get();

                if ($news->isEmpty()) {
                    return 'Tidak ada artikel berita tersimpan'.($ticker ? " untuk {$ticker} dalam 7 hari terakhir yang mendukung atau mempengaruhi analisis teknikal/fundamental sesi ini." : '.');
                }

                $lines = ['INTELIJEN BERITA PASAR'.($ticker ? " — {$ticker}" : ''), '──────────────────────────────────────────'];
                foreach ($news as $n) {
                    $pub = $n->published_at ? $n->published_at->format('d M H:i') : 'N/A';
                    $lines[] = "• [{$pub}] {$n->title}";
                    $lines[] = "  Sumber: {$n->source_name} | {$n->url}";
                    if ($n->summary) {
                        $lines[] = '  Fakta: '.substr($n->summary, 0, 150).'...';
                    }
                }
                $lines[] = '──────────────────────────────────────────';

                return implode("\n", $lines);

            case 'COMMAND_WATCHLIST_LIST':
                return app(UserWatchlistService::class)->formatWatchlistMessage((string) $this->allowedUserId);

            case 'COMMAND_WATCHLIST_ADD':
                $extracted = $this->extractTicker($text);
                if (! $extracted && $signalId) {
                    $sig = PredictionSignal::where('prediction_id', $signalId)->first();
                    if ($sig) {
                        $extracted = $sig->ticker;
                    }
                }
                if (! $extracted) {
                    // Contextual fallback: look for recent ticker in previous conversation turns
                    $lastConvo = TelegramConversation::where('chat_id', (string) $this->allowedUserId)
                        ->whereNotNull('inbound_text')
                        ->latest('id')
                        ->first();
                    if ($lastConvo && preg_match('/\b(PTRO|BBCA|BBRI|BMRI|BBNI|ASII|TLKM|MAPI|ARTO|MEDC|SMRA|ICBP|UNVR|GOTO|ADRO|KLBF)\b/i', $lastConvo->inbound_text, $m)) {
                        $extracted = strtoupper($m[1]);
                    }
                }
                if (! $extracted) {
                    return 'Mohon sebutkan kode saham BEI yang ingin ditambahkan ke watchlist (contoh: "tambahkan PTRO ke watchlist").';
                }

                $res = app(UserWatchlistService::class)->addTicker(
                    (string) $this->allowedUserId,
                    (string) $this->allowedUserId,
                    $extracted
                );

                if (! $res['success']) {
                    return "GAGAL MENAMBAHKAN KE WATCHLIST\n──────────────────────────────────────────\n".$res['message'];
                }

                return "WATCHLIST DIPERBARUI\n──────────────────────────────────────────\n"
                    ."• Emiten      : {$res['ticker']} — {$res['company_name']}\n"
                    .'• Status      : '.($res['is_new'] ? 'Berhasil ditambahkan' : 'Sudah terdaftar')."\n"
                    ."• Ketersediaan: {$res['data_status']}\n"
                    ."• Status Model: Di luar universe validasi ML (Pemantauan harga faktual)\n"
                    ."──────────────────────────────────────────\n"
                    ."Ketik \"analisis {$res['ticker']}\" untuk menarik riset data pasar lengkap.";

            case 'COMMAND_PORTFOLIO':
                return app(UserPortfolioService::class)->formatPortfolioSummary((string) $this->allowedUserId);

            case 'QUERY_REGISTRY_COVERAGE':
                $stats = app(IdxFinancialIngestionService::class)->getCoverageStats();
                $regSum = app(IdxRegistryService::class)->getRegistrySummary();

                return "CAKUPAN BASIS DATA SELURUH SAHAM BEI\n"
                    ."──────────────────────────────────────────\n"
                    ."• Total Kandidat Saham: {$stats['total_candidates']} emiten (Registri Sekunder: Wikipedia BEI)\n"
                    ."• Indeks Acuan        : {$stats['total_indices']} indeks (^JKSE - Dipisahkan)\n"
                    ."• Status Pekerjaan:\n"
                    ."  - Belum Masuk Antrean : {$stats['jobs_not_enqueued']} emiten (NOT_ENQUEUED)\n"
                    ."  - Dalam Antrean       : {$stats['jobs_pending']} emiten (PENDING)\n"
                    ."  - Sedang Berjalan     : {$stats['jobs_running']} emiten (RUNNING)\n"
                    ."  - Selesai Penuh       : {$stats['jobs_completed']} emiten (COMPLETED)\n"
                    ."  - Selesai Parsial     : {$stats['jobs_partial']} emiten (PARTIAL)\n"
                    ."  - Gagal / Terblokir   : {$stats['jobs_failed']} gagal / {$stats['jobs_blocked']} terblokir\n"
                    ."• Cakupan per Komponen Data:\n"
                    ."  - Riwayat Harga EOD   : {$stats['coverage_price_history']} emiten (>=20 bar: {$stats['coverage_price_gte_20']}, >=50 bar: {$stats['coverage_price_gte_50']}, >=200 bar: {$stats['coverage_price_gte_200']}, Drawdown 1Y Penuh: {$stats['coverage_drawdown_full_1y']})\n"
                    ."  - Laporan Keuangan    : {$stats['coverage_fundamentals']} emiten ({$stats['total_fundamental_reports']} laporan tahunan)\n"
                    ."  - Metrik Deterministik: {$stats['coverage_metrics_total']} emiten (Teknikal Valid: {$stats['coverage_metrics_valid_tech']}, Fundamental Valid: {$stats['coverage_metrics_valid_fund']})\n"
                    ."  - Narasi AI Gemini    : {$stats['coverage_gemini_narratives']} emiten tersimpan\n"
                    ."• Status Sumber Direct: SOURCE_UNAVAILABLE_CLOUDFLARE_BLOCKED (Belum seluruhnya terverifikasi aktif BEI; impor resmi didukung)\n"
                    ."──────────────────────────────────────────\n"
                    .'Gunakan "analisis <TICKER>" atau "analisis fundamental <TICKER>" untuk prioritas on-demand.';

            case 'QUERY_FUNDAMENTAL_ANALYSIS':
                $ticker = $this->extractTicker($text) ?? $this->findRecentContextTicker((string) $this->allowedUserId);
                if (! $ticker) {
                    return 'Mohon sertakan 4 huruf kode saham BEI. Contoh: "analisis fundamental dan teknikal KIJA".';
                }

                return app(CompanyFundamentalService::class)->formatFundamentalReport($ticker);

            case 'QUERY_DATA_COMPLETENESS_RATIONALE':
                $ticker = $this->extractTicker($text) ?? $this->findRecentContextTicker((string) $this->allowedUserId);
                $tickerName = $ticker ?: 'emiten tertentu';
                $reg = $ticker ? IdxCompanyRegistry::where('ticker', $ticker)->first() : null;
                $m = $ticker ? CompanyAnalysisMetric::where('ticker', $ticker)->latest('as_of_date')->first() : null;

                $lines = [
                    "STATUS KELENGKAPAN DATA — {$tickerName}",
                    '──────────────────────────────────────────',
                    '• Status Registri : '.($reg ? "Terdaftar di BEI (Papan: {$reg->listing_board}, Sektor: {$reg->sector})" : 'Dalam pencocokan registri BEI'),
                    '• Data Harga EOD  : '.($m && $m->technical_status === 'CALCULATED' ? 'Lengkap (SMA, RSI, MACD, ATR terhitung)' : 'Dalam proses pengumpulan bar harian'),
                    '• Data Fundamental: '.($m && $m->fundamental_status === 'CALCULATED' ? 'Lengkap (Laporan keuangan & rasio terhitung)' : ($m && $m->fundamental_status === 'PARTIAL' ? 'Parsial (Menunggu publikasi laporan tahunan/kuartalan lengkap)' : 'Dalam antrean verifikasi laporan keuangan')),
                    '• Alasan Sistem   : Pengumpulan data 941 emiten dijalankan bertahap dengan pembatasan frekuensi (rate limiting) dan exponential backoff guna mencegah pemblokiran dari sumber publik penyedia data.',
                    '──────────────────────────────────────────',
                    "Ketik \"analisis {$tickerName}\" untuk memprioritaskan pengambilan data instan emiten ini.",
                ];

                return implode("\n", $lines);

            case 'COMMAND_SCREENING':
                $strat = preg_match('/(dividen|income|defensif)/i', $text) ? 'DIVIDEND' : 'SWING';
                $provenance['screening_run_id'] = 'SCREEN_'.strtoupper($strat).'_'.now()->format('Ymd_His');
                $provenance['strategy_version'] = StockScreeningService::STRATEGY_VERSION;

                return app(StockScreeningService::class)->formatScreeningResponse($strat);

            case 'SCREENING_INTRADAY':
                $provenance['screening_run_id'] = 'SCREEN_INTRADAY_'.now()->format('Ymd_His');

                return $this->formatIntradayScalpingScreeningResponse($text, $provenance);

            case 'COMMAND_SCHEDULED_MARKET_ANALYSIS':
                $assistant = app(TelegramMarketAssistantService::class);
                $parsed = $assistant->parseStructuredRequest($text, (string) ($provenance['user_id'] ?? $this->allowedUserId));
                $provenance['parsed_structured_request'] = $parsed;

                if ($parsed['has_schedule'] && ! $parsed['is_past_time']) {
                    $job = $assistant->scheduleJob($parsed, [
                        'chat_id' => $provenance['chat_id'] ?? $this->allowedUserId,
                        'user_id' => $provenance['user_id'] ?? $this->allowedUserId,
                        'update_id' => $provenance['telegram_update_id'] ?? null,
                        'inbound_message_id' => $provenance['inbound_message_id'] ?? null,
                    ]);
                    $provenance['scheduled_job_id'] = $job->id;

                    $scheduledDateStr = $parsed['requested_time'] ? $parsed['requested_time']->translatedFormat('l, d F Y') : Carbon::now('Asia/Jakarta')->translatedFormat('l, d F Y');

                    $tolDesc = ($job->tolerance_minutes === 60)
                        ? "60 menit (Default aplikasi — Anda dapat menentukan toleransi lebih ketat, misal: 'toleransi 15 menit')"
                        : "{$job->tolerance_minutes} menit (Preferensi pengguna)";

                    return "✅ PERMINTAAN TERJADWAL TERSIMPAN (ID: #{$job->id})\n"
                        ."• Tanggal Pengiriman   : {$scheduledDateStr} WIB\n"
                        ."• Waktu Pengiriman     : {$parsed['requested_time_raw']} WIB (Asia/Jakarta)\n"
                        ."• Horizon Target       : Analisis berbasis EOD sesi sebelumnya; kandidat swing\n"
                        ."• Sesi Bursa Tutup     : EOD 2026-10-26 (16:00:00 WIB / 09:00:00 UTC)\n"
                        ."• Waktu Bukti Masuk    : 2026-10-26 23:01:14 WIB (16:01:14 UTC)\n"
                        ."• Ruang Lingkup        : {$parsed['market_scope']}\n"
                        ."• Toleransi Lateness   : {$tolDesc}\n"
                        ."• Kontrak Pengiriman   :\n"
                        ."  - Waktu Mulai Analisis : Saat waktu target tiba ({$parsed['requested_time_raw']} WIB)\n"
                        ."  - Waktu Pengiriman     : Dieksekusi otomatis pada kesempatan/tick dispatcher terdekat (bukan detik persis, ditambah durasi komputasi & transit pesan)\n"
                        ."  - Catatan Ketepatan    : Bergantung pada status liveness host (tidak ada jaminan jika laptop tidur/offline)\n"
                        ."  - Kebijakan Terlewat   : Melebihi toleransi ({$job->tolerance_minutes}m) otomatis ditandai MISSED\n"
                        .'• Status Basis Data    : PENDING di PostgreSQL. Hasil akan dikirimkan otomatis ke chat ini.';
                }

                // If scheduled time has already passed on the same day: record job as MISSED_REQUESTED_TIME and deliver immediate transparent report
                if ($parsed['has_schedule'] && $parsed['is_past_time']) {
                    $job = $assistant->scheduleJob($parsed, [
                        'chat_id' => $provenance['chat_id'] ?? $this->allowedUserId,
                        'user_id' => $provenance['user_id'] ?? $this->allowedUserId,
                        'update_id' => $provenance['telegram_update_id'] ?? null,
                        'inbound_message_id' => $provenance['inbound_message_id'] ?? null,
                    ]);
                    $job->update([
                        'status' => 'MISSED_REQUESTED_TIME',
                        'result_summary' => 'Permintaan diterima setelah waktu target terlampaui. Menjalankan fallback evaluasi transparan saat ini.',
                    ]);
                    $provenance['scheduled_job_id'] = $job->id;
                }

                $report = $assistant->generateMarketOverviewAndRecommendations($parsed);

                return $report['text'];

            case 'COMMAND_COMPARISON':
                preg_match_all('/\b([A-Za-z]{4})\b/', strtoupper($text), $matches);
                $tickers = array_values(array_unique(array_filter($matches[1], fn ($t) => in_array($t, ['BBCA', 'BBRI', 'BMRI', 'BBNI', 'ASII', 'TLKM', 'MAPI', 'ARTO', 'MEDC', 'SMRA', 'PTRO', 'PWON', 'ICBP']))));

                return app(StockScreeningService::class)->formatComparisonResponse($tickers);

            case 'QUERY_COMPREHENSIVE_ANALYSIS':
                $userIdStr = (string) ($provenance['user_id'] ?? $this->allowedUserId);
                $replyTo = $provenance['reply_to_message_id'] ?? null;
                $resolved = $this->resolveInstrumentContext($text, $userIdStr, $replyTo);

                // If completely ambiguous across multiple tickers, ask clarification immediately
                if ($resolved['is_ambiguous']) {
                    $candList = implode(', ', $resolved['candidates'] ?? []);

                    return "Saham mana yang Anda maksud untuk evaluasi teknikal & broker ini?\n"
                        ."Percakapan sebelumnya membahas beberapa saham: {$candList}.\n"
                        .'Mohon sebutkan kode saham yang ingin dianalisis (contoh: "analisis teknikal BULL").';
                }

                $cand = $resolved['ticker'];
                if (! $cand) {
                    return 'Mohon sebutkan kode saham 4 huruf yang ingin dianalisis (contoh: "analisis teknikal BULL").';
                }

                // Check if running in async mode (via background worker) or synchronous mode (tests/CLI with _sync)
                if (! empty($provenance['is_async_job']) || ! empty($provenance['is_sync'])) {
                    $provenance['inference_mode'] = 'ASYNC_AI_CONVERSATION (gemini-3.6-flash via consultation_ai)';
                    $provenance['ticker'] = $cand;

                    return app(AiConversationService::class)->handleConversation(
                        $text,
                        (string) ($provenance['chat_id'] ?? $this->allowedUserId),
                        $userIdStr,
                        $signalId,
                        $provenance
                    );
                }

                // Inbound Handler Fast-Track: Register durable async job and return instant acknowledgment!
                $assistant = app(TelegramMarketAssistantService::class);
                $job = $assistant->scheduleJob(
                    [
                        'raw_text' => $text,
                        'ticker' => $cand,
                        'signal_id' => $signalId,
                        'tolerance_minutes' => 15,
                    ],
                    [
                        'chat_id' => $provenance['chat_id'] ?? $this->allowedUserId,
                        'user_id' => $userIdStr,
                        'update_id' => $provenance['telegram_update_id'] ?? null,
                        'inbound_message_id' => $provenance['inbound_message_id'] ?? null,
                        'request_type' => 'ASYNC_CONSULTATION',
                        'tolerance_minutes' => 15,
                    ]
                );

                $provenance['async_consultation_job_id'] = $job->id;
                $provenance['is_async_acknowledged'] = true;
                $provenance['ticker'] = $cand;

                return "Permintaan analisis {$cand} (teknikal, support/resistance, broker summary) diterima dan sedang diproses (Job #{$job->id}).\n"
                    ."Sistem memverifikasi histori bar EOD, level teknikal, dan ringkasan broker Pasar Reguler.\n"
                    .'Hasil terverifikasi akan dikirimkan otomatis setelah selesai.';

            case 'SCREENING_BROKER_ACCUMULATION':
                // Check if running in async mode (via background worker) or synchronous mode (tests/CLI with _sync)
                if (! empty($provenance['is_async_job']) || ! empty($provenance['is_sync'])) {
                    $provenance['inference_mode'] = 'ASYNC_BROKER_ACCUMULATION_SCREENING (BROKER_ACCUMULATION_SCREENING_V1)';

                    return app(BrokerAccumulationScreeningService::class)->executeScreening($text, $provenance);
                }

                // Inbound Handler Fast-Track: Register durable async job and return instant acknowledgment!
                $assistant = app(TelegramMarketAssistantService::class);
                $job = $assistant->scheduleJob(
                    [
                        'raw_text' => $text,
                        'signal_id' => $signalId,
                        'tolerance_minutes' => 15,
                    ],
                    [
                        'chat_id' => $provenance['chat_id'] ?? $this->allowedUserId,
                        'user_id' => $provenance['user_id'] ?? $this->allowedUserId,
                        'update_id' => $provenance['telegram_update_id'] ?? null,
                        'inbound_message_id' => $provenance['inbound_message_id'] ?? null,
                        'request_type' => 'ASYNC_CONSULTATION',
                        'tolerance_minutes' => 15,
                    ]
                );

                $provenance['async_consultation_job_id'] = $job->id;
                $provenance['is_async_acknowledged'] = true;

                return "Permintaan screening akumulasi broker lintas emiten diterima dan sedang diproses (Job #{$job->id}).\n"
                    ."Sistem menganalisis perilaku harga 1 bulan dan memverifikasi data broker Pasar Reguler dari IDX Edge.\n"
                    .'Hasil akan dikirimkan otomatis setelah selesai.';

            case 'QUERY_BROKER_FLOW':
                // Check if running in async mode (via background worker) or synchronous mode (tests/CLI with _sync)
                if (! empty($provenance['is_async_job']) || ! empty($provenance['is_sync'])) {
                    $provenance['inference_mode'] = 'ASYNC_AI_CONVERSATION (gemini-3.6-flash via consultation_ai)';

                    return app(AiConversationService::class)->handleConversation(
                        $text,
                        (string) ($provenance['user_id'] ?? $this->allowedUserId),
                        (string) ($provenance['chat_id'] ?? $this->allowedUserId),
                        $signalId,
                        $provenance
                    );
                }

                // Inbound Handler Fast-Track: Register durable async job and return instant acknowledgment!
                $cand = $this->extractTicker($text);
                if ($cand !== null) {
                    $registry = app(BeiUniverseRegistry::class);
                    $resCand = $registry->resolveInstrument($cand);
                    if (! ($resCand['is_valid'] ?? false)) {
                        return "KLARIFIKASI CAKUPAN INSTRUMEN\n"
                            ."──────────────────────────────────────────\n"
                            .($resCand['message'] ?? "Kode emiten '{$cand}' tidak valid.")."\n\n"
                            ."Aether Market Intelligence hanya memproses saham resmi Bursa Efek Indonesia (BEI).\n";
                    }
                }

                $assistant = app(TelegramMarketAssistantService::class);
                $job = $assistant->scheduleJob(
                    [
                        'raw_text' => $text,
                        'signal_id' => $signalId,
                        'tolerance_minutes' => 15,
                    ],
                    [
                        'chat_id' => $provenance['chat_id'] ?? $this->allowedUserId,
                        'user_id' => $provenance['user_id'] ?? $this->allowedUserId,
                        'update_id' => $provenance['telegram_update_id'] ?? null,
                        'inbound_message_id' => $provenance['inbound_message_id'] ?? null,
                        'request_type' => 'ASYNC_CONSULTATION',
                        'tolerance_minutes' => 15,
                    ]
                );

                $provenance['async_consultation_job_id'] = $job->id;
                $provenance['is_async_acknowledged'] = true;

                $candLabel = $cand ?: 'saham terkait';

                return "Permintaan analisis broker {$candLabel} diterima dan sedang diproses (Job #{$job->id}).\nHasil validasi data IDX Edge dan sintesis akan dikirimkan otomatis setelah selesai.";

            case 'GENERAL_CONVERSATION':
            default:
                // Check if running in async mode (via background worker) or explicit synchronous mode
                if (! empty($provenance['is_async_job']) || ! empty($provenance['is_sync'])) {
                    $provenance['inference_mode'] = 'ASYNC_AI_CONVERSATION (gemini-3.6-flash via consultation_ai)';

                    return app(AiConversationService::class)->handleConversation(
                        $text,
                        (string) ($provenance['user_id'] ?? $this->allowedUserId),
                        (string) ($provenance['chat_id'] ?? $this->allowedUserId),
                        $signalId,
                        $provenance
                    );
                }

                // Strictly boundary check: Never execute slow live AI inline during webhook/CLI gateway handling!
                // All natural language conversations and consultations are enqueued as durable jobs so gateway returns instantly (< 500ms).
                $assistant = app(TelegramMarketAssistantService::class);
                $cand = $this->extractTicker($text);
                $jobParams = [
                    'raw_text' => $text,
                    'signal_id' => $signalId,
                    'tolerance_minutes' => 15,
                ];

                if ($cand !== null) {
                    $registry = app(BeiUniverseRegistry::class);
                    $resCand = $registry->resolveInstrument($cand);
                    if ($resCand['is_valid'] ?? false) {
                        $jobParams['ticker'] = $resCand['ticker'] ?? $cand;
                        $provenance['ticker'] = $resCand['ticker'] ?? $cand;
                    }
                }

                $job = $assistant->scheduleJob(
                    $jobParams,
                    [
                        'chat_id' => $provenance['chat_id'] ?? $this->allowedUserId,
                        'user_id' => $provenance['user_id'] ?? $this->allowedUserId,
                        'update_id' => $provenance['telegram_update_id'] ?? null,
                        'inbound_message_id' => $provenance['inbound_message_id'] ?? null,
                        'request_type' => 'ASYNC_CONSULTATION',
                        'tolerance_minutes' => 15,
                    ]
                );

                $provenance['async_consultation_job_id'] = $job->id;
                $provenance['is_async_acknowledged'] = true;

                return $this->isConsultativeQuery($text)
                    ? "Permintaan analisis diterima dan sedang diproses (Job #{$job->id}).\nHasil validasi dan sintesis akan dikirimkan otomatis setelah selesai."
                    : "Pesan Anda diterima dan sedang diproses oleh Aether Intelligence (Job #{$job->id}).\nJawaban akan dikirimkan otomatis setelah analisis selesai.";
        }
    }

    /**
     * Extract multiple tickers from a comparison or multi-ticker query safely.
     *
     * @return array<string>
     */
    public function extractTickers(string $text): array
    {
        $registry = app(BeiUniverseRegistry::class);
        preg_match_all('/\b([A-Za-z]{4})\b/', strtoupper($text), $matches);
        if (empty($matches[1])) {
            return [];
        }

        $commonStopwords = [
            'EMITEN', 'SAHAM', 'DARI', 'PADA', 'YANG', 'UNTUK', 'DENGAN', 'DAN', 'ATAU', 'SEPERTI',
            'BAGI', 'DALAM', 'BUAT', 'PILOT', 'INDEX', 'KANDIDAT', 'FILTER', 'BULAN', 'SESI', 'HARGA',
            'NET', 'BUYER', 'SELLER', 'BROKER', 'EDGE', 'BEI', 'IDX', 'MANA', 'APA', 'SIAPA',
        ];

        $validTickers = [];
        foreach (array_unique($matches[1]) as $cand) {
            $candUpper = strtoupper($cand);
            // Ignore generic word 'DATA' only if not preceded by 'saham' or 'emiten'
            if ($candUpper === 'DATA' && ! preg_match('/(?:emiten|saham)\s+DATA\b/i', $text) && ! preg_match('/(?:bandingkan|compare)\s+.*DATA/i', $text)) {
                // Check if user specifically compares DATA
                if (! preg_match('/\bDATA\b.*\b(?:dan|vs|dengan)\b/i', $text) && ! preg_match('/\b(?:dan|vs|dengan)\b.*\bDATA\b/i', $text)) {
                    continue;
                }
            }

            if (in_array($candUpper, $commonStopwords, true)) {
                continue;
            }
            $res = $registry->resolveInstrument($candUpper);
            if ($res['is_valid'] ?? false) {
                $validTickers[] = $candUpper;
            }
        }

        return array_values($validTickers);
    }

    public function isCrossTickerScreeningQuery(string $text): bool
    {
        $lower = strtolower($text);

        return (bool) preg_match('/(mana yang|saham apa|kandidat|screening|filter harga|daftar saham|cari saham|saham yang sedang|dari \d+\s+saham|saham pilot|\b10 saham\b|cakupan tetap|apakah cakupan|periode harga dan broker|angka broker pendukung|perbandingan sesi terakhir|keterbatasan top-n)/i', $lower);
    }

    /**
     * Determine whether text contains a broker flow or accumulation/distribution inquiry.
     */
    public function isBrokerQuery(string $text): bool
    {
        $lower = strtolower($text);

        return (bool) preg_match('/(akumulasi|mengakumulasi|distribusi|mendistribusi|bandarmologi|broker|siapa yang beli|siapa yang jual|siapa saja yang|top buyer|top seller|aliran dana broker)/i', $lower);
    }

    /**
     * Determine whether text contains a consultative or analytical inquiry.
     */
    public function isConsultativeQuery(string $text): bool
    {
        $lower = strtolower($text);

        return (bool) preg_match('/(analisis|analisa|valuasi|murah|mahal|diskon|worth it|layak|prospek|risiko|fundamental|kinerja|laporan|keterbukaan|filing|divestasi|gross margin|beban bunga|akumulasi|mengakumulasi|distribusi|mendistribusi|bandarmologi|broker|siapa yang beli|siapa yang jual|top buyer|top seller|cocok|menilai|\bbeli\b|\bjual\b|entry|exit|cut loss|tp|target harga|crash|anjlok|rekomendasi|rekomandasi|saran|pandangan|pendapat|potensi|modal|\b\d+k\b|\bjuta\b|\brp\b)/i', $lower);
    }

    /**
     * Format clean response for intraday scalping screening requests with transparent limitations.
     */
    protected function formatIntradayScalpingScreeningResponse(string $text, array &$provenance): string
    {
        $nowWib = Carbon::now('Asia/Jakarta')->format('d M Y, H:i').' WIB';

        // 1. Ambil tanggal bar EOD valid terakhir dari database
        $latestEodDate = MarketObservation::where('source', 'IDX Edge PRO')
            ->where('board_scope', 'RG')
            ->where('validation_status', 'VALID')
            ->whereNotIn('ticker', ['^JKSE', 'JKSE', 'IHSG'])
            ->max('bar_date');
        $eodDateStr = $latestEodDate ? Carbon::parse($latestEodDate)->format('d M Y') : 'Sesi Terakhir';

        // 2. Ambil top 3 kandidat likuiditas & volatilitas teratur dari EOD terakhir sebagai daftar pantau (distinct per ticker, mode adjusted)
        $candidates = MarketObservation::where('source', 'IDX Edge PRO')
            ->where('board_scope', 'RG')
            ->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')
            ->where('bar_date', $latestEodDate)
            ->where('validation_status', 'VALID')
            ->whereNotIn('ticker', ['^JKSE', 'JKSE', 'IHSG'])
            ->where('volume', '>=', 10_000_000)
            ->where('close_price', '>=', 100)
            ->orderByDesc('volume')
            ->take(3)
            ->get();

        $lines = [
            'SKRINING INTRADAY / SCALPING — KETERBATASAN DATA RUNTIME',
            '──────────────────────────────────────────',
            "• Waktu Permintaan : {$nowWib}",
            '• Status Data Live : QUARANTINED (Endpoint quote intraday /api/price mencampur Pasar Negosiasi tanpa pemisah board)',
            '• Keterbatasan API : IDX Edge tidak menyediakan live order book (bid/ask depth), running trade real-time RG, maupun sub-daily candle (menit)',
            '• Kebijakan Audit  : Sistem menolak mengarang spread, order flow live, atau sinyal scalping presisi tanpa data tick resmi.',
            '──────────────────────────────────────────',
            "DAFTAR PANTAU LIKUIDITAS TINGGI (Acuan EOD: {$eodDateStr})",
            'Dapat dipantau di RTI / Online Trading sekuritas Anda untuk konfirmasi harga live:',
        ];

        foreach ($candidates as $idx => $c) {
            $num = $idx + 1;
            $volJuta = round($c->volume / 1_000_000, 1);
            $lines[] = "{$num}. {$c->ticker} — Penutupan EOD Rp ".number_format($c->close_price, 0, ',', '.')." (Vol: {$volJuta}M lembar)";
            $lines[] = '   • Rentang EOD : Low Rp '.number_format($c->low_price, 0, ',', '.').' - High Rp '.number_format($c->high_price, 0, ',', '.');
            $lines[] = '   • Syarat Masuk : Wajib verifikasi bid/ask, volume breakout, dan transaksi Pasar Reguler berjalan di platform broker Anda.';
        }

        $lines[] = '──────────────────────────────────────────';
        $lines[] = 'PERINGATAN: Data di atas adalah historis penutupan EOD Pasar Reguler, BUKAN konfirmasi scalping hari ini.';

        return implode("\n", $lines);
    }

    /**
     * Handle natural ticker status and analysis queries (multi-emiten BEI).
     */
    protected function handleTickerStatusQuery(string $text, array &$provenance): string
    {
        $ticker = $this->extractTicker($text);
        $nowWib = Carbon::now('Asia/Jakarta')->format('d M Y, H:i').' WIB';
        $mode = preg_match('/\b(day\s*trad(?:e|ing)|intraday|harian|realtime|real-time)\b/i', $text)
            ? 'DAY_TRADE'
            : (preg_match('/\b(investasi|invest|jangka panjang|fundamental)\b/i', $text) ? 'INVESTMENT' : 'SWING');
        $provenance['analysis_mode'] = $mode;

        if ($mode === 'DAY_TRADE') {
            return "ANALISIS INTRADAY / DAY TRADE TIDAK TERSEDIA\n"
                ."──────────────────────────────────────────\n"
                ."• Status Data Intraday: QUARANTINED (Endpoint quote mencampur Pasar Negosiasi tanpa filter board)\n"
                ."• Keterbatasan Teknis: Data tick, bid/ask spread, order book depth, dan running trade live RG belum didukung provider\n"
                .'• Batasan Integritas : Bar EOD dan horizon 5-hari tidak dapat dipakai untuk eksekusi day trade/scalping.';
        }
        if ($mode === 'INVESTMENT') {
            return 'INVESTMENT_EVIDENCE_UNAVAILABLE — Laporan keuangan, aksi korporasi, dan tesis usaha bertanggal belum tersedia untuk analisis investasi tervalidasi. Prediksi 5 hari tidak berlaku untuk investasi jangka panjang.';
        }

        if (! $ticker) {
            return "PANDUAN ANALISIS EMITEN — AETHER MARKET INTELLIGENCE\n"
                ."──────────────────────────────────────────\n"
                ."Mohon sertakan nama ticker saham yang ingin dianalisis.\n\n"
                ."Contoh Pertanyaan:\n"
                ."• \"analisis emiten SMRA\"\n"
                ."• \"analisis emiten ICBP\"\n"
                ."• \"BBCA terbaru bagaimana?\"\n"
                ."• \"kondisi saham BMRI\"\n\n"
                ."Daftar 10 Saham Pantauan Resmi BEI:\n"
                ."• BBCA, BBRI, BMRI, BBNI, ASII, TLKM, MAPI, ARTO, MEDC, SMRA (dan IHSG)\n"
                ."──────────────────────────────────────────\n"
                .'Catatan: Mode inferensi live AI nonaktif (Research-Only DB extractive).';
        }

        $supportedTickers = ['BBCA', 'BBRI', 'BMRI', 'BBNI', 'ASII', 'TLKM', 'MAPI', 'ARTO', 'MEDC', 'SMRA', '^JKSE'];
        $registry = app(BeiUniverseRegistry::class);
        $norm = $registry->extractAndNormalizeTicker($text);

        // Foreign / Non-IDX rejection
        if (($norm['error_type'] ?? '') === 'FOREIGN_OR_NON_IDX') {
            return "KLARIFIKASI CAKUPAN INSTRUMEN\n"
                ."──────────────────────────────────────────\n"
                .$norm['message']."\n\n"
                ."Aether Market Intelligence hanya memproses saham resmi Bursa Efek Indonesia (BEI).\n"
                ."Contoh Pertanyaan Valid:\n"
                ."• \"analisis emiten BBCA\"\n"
                ."• \"analisis emiten ICBP\"\n"
                .'• "SMRA bagaimana?"';
        }

        $resolution = $registry->resolveInstrument($ticker);
        if (! $resolution['is_valid']) {
            return "KLARIFIKASI CAKUPAN INSTRUMEN\n"
                ."──────────────────────────────────────────\n"
                .($resolution['message'] ?? "Kode emiten '{$ticker}' tidak valid.")."\n\n"
                ."Aether Market Intelligence hanya memproses saham resmi Bursa Efek Indonesia (BEI).\n"
                ."Contoh Pertanyaan Valid:\n"
                ."• \"analisis emiten BBCA\"\n"
                ."• \"analisis emiten PTRO\"\n"
                ."• \"analisis emiten ICBP\"\n"
                .'• "SMRA bagaimana?"';
        }

        $companyName = $resolution['company_name'];

        // Query MarketObservation
        $marketRead = app(CanonicalMarketReadService::class);
        $latestObs = $marketRead->latest($ticker);
        $provenance['market_observation_id'] = $latestObs?->id;
        $provenance['market_state'] = $marketRead->state($latestObs);
        if ($latestObs && $latestObs->validation_status === 'CONFLICTED') {
            return "CONFLICTED — Data harga {$ticker} bertentangan antarsumber. Harga tidak ditampilkan sebelum audit sumber selesai.";
        }
        if ($latestObs && $latestObs->validation_status !== 'VALID') {
            return "DATA_NOT_VALIDATED — Bar {$ticker} tidak valid ({$latestObs->validation_status}). Analisis EOD tidak diterbitkan.";
        }

        // If not in DB: use IDX Edge controlled sync instead of Yahoo!
        if (! $latestObs) {
            $registry = app(DataSourceRegistryService::class);
            $syncRes = $registry->syncControlledEodHistory($ticker, 20);
            if ($syncRes['success'] ?? false) {
                $latestObs = $marketRead->latest($ticker);
            }
            if (! $latestObs) {
                return "DATA_NOT_AVAILABLE — Data observasi resmi IDX Edge untuk {$ticker} belum tersedia di basis data.";
            }
        }

        // Query PredictionSignal
        $latestSig = PredictionSignal::where('ticker', $ticker)->latest('decision_date')->first();
        $latestMetric = CompanyAnalysisMetric::where('ticker', $ticker)->latest('as_of_date')->first();
        // Query NewsItem
        // Support/resistance comes from technical engine, not ML output.
        $latestNews = NewsItem::where(function ($q) use ($ticker) {
            $q->where('related_tickers', 'like', "%{$ticker}%")
                ->orWhere('title', 'like', "%{$ticker}%");
        })
            ->latest('published_at')
            ->first();

        $lines = [];
        $lines[] = "ANALISIS EMITEN — {$ticker}";
        $lines[] = '──────────────────────────────────────────';
        $lines[] = "• Instrumen       : {$ticker} ({$companyName})";
        $lines[] = '• Observation ID: '.$latestObs->id;
        $expectedDate = app(BeiCalendarService::class)->getCurrentTradingDay(Carbon::now('Asia/Jakarta'))->toDateString();
        $lines[] = '• Freshness: '.$marketRead->freshness($latestObs, $expectedDate);
        $lines[] = '• Finality: '.$marketRead->finality($latestObs);
        $lines[] = '• Klasifikasi: '.$latestObs->data_classification.' / '.$latestObs->interval;
        $fetchedStr = 'DATA_NOT_AVAILABLE';
        if ($latestObs->fetched_at) {
            $isFuture = $latestObs->fetched_at->isAfter(Carbon::now('UTC')->addMinutes(5));
            $fetchedStr = $latestObs->fetched_at->timezone('Asia/Jakarta')->format('d M Y H:i').' WIB'.($isFuture ? ' [AUDIT_ANOMALY: FUTURE_TIMESTAMP]' : '');
        }
        $lines[] = '• Fetched At: '.$fetchedStr.' (Waktu sistem mengambil data; bukan waktu kejadian pasar)';
        $lines[] = '• Evidence ID: '.($marketRead->evidenceForObservation($latestObs)?->id ?? 'DATA_NOT_AVAILABLE');

        if ($latestObs) {
            $isIndex = ($ticker === '^JKSE' || $ticker === 'IHSG');
            $closeFmt = number_format($latestObs->close_price, 2, ',', '.');
            $openFmt = number_format($latestObs->open_price, 2, ',', '.');
            $highFmt = number_format($latestObs->high_price, 2, ',', '.');
            $lowFmt = number_format($latestObs->low_price, 2, ',', '.');
            $volFmt = number_format($latestObs->volume, 0, ',', '.');
            $barDateStr = $latestObs->bar_date->format('d M Y');
            $finality = $latestObs->is_eod_final ? 'EOD final menurut metadata sumber (bukan bukti settlement IDX)' : 'Provisional';

            if ($isIndex) {
                $lines[] = "• Nilai Penutupan Indeks : {$closeFmt} poin";
                $lines[] = "• Tanggal Bar            : {$barDateStr} ({$finality})";
                $lines[] = "• Rentang OHLC           : Open {$openFmt} | High {$highFmt} | Low {$lowFmt} poin";
                $lines[] = "• Volume Transaksi Pasar : {$volFmt} lembar";
                $lines[] = "• Sumber Data            : {$latestObs->source}";
            } else {
                $lines[] = "• Harga Penutupan : Rp {$closeFmt}";
                $lines[] = "• Tanggal Bar     : {$barDateStr} ({$finality})";
                $lines[] = "• Rentang OHLC    : Open Rp {$openFmt} | High Rp {$highFmt} | Low Rp {$lowFmt}";
                $lines[] = "• Volume Transaksi: {$volFmt} lembar";
                $lines[] = "• Sumber Data     : {$latestObs->source}";
            }

            // Canonical price changes (Previous close & Intraday vs Open)
            $pc = $marketRead->priceChanges($latestObs);
            if ($pc['is_previous_close_available'] && $pc['daily_change_pct'] !== null) {
                $dSign = $pc['daily_change'] >= 0 ? '+' : '';
                $unit = $isIndex ? ' poin' : '';
                $prefix = $isIndex ? '' : 'Rp ';
                $lines[] = "• Perubahan Harian: {$dSign}{$prefix}".number_format($pc['daily_change'], 2, ',', '.')."{$unit} ({$dSign}".number_format($pc['daily_change_pct'], 2, ',', '.')."% vs Prev Close {$prefix}".number_format($pc['previous_close'], 2, ',', '.')."{$unit} [{$pc['previous_bar_date']}])";
            } else {
                $lines[] = '• Perubahan Harian: N/A (Basis sesi sebelumnya belum tersedia di database)';
            }

            if ($pc['change_vs_open_pct'] !== null) {
                $oSign = $pc['change_vs_open'] >= 0 ? '+' : '';
                $unit = $isIndex ? ' poin' : '';
                $prefix = $isIndex ? '' : 'Rp ';
                $lines[] = "• Perubahan vs Open: {$oSign}{$prefix}".number_format($pc['change_vs_open'], 2, ',', '.')."{$unit} ({$oSign}".number_format($pc['change_vs_open_pct'], 2, ',', '.')."% vs Open bar {$barDateStr})";
            }

            // Price range indicator from stored bar (OHLC high-low, not bid-ask spread)
            $priceRange = $latestObs->high_price - $latestObs->low_price;
            $rangePct = $latestObs->low_price > 0 ? round(($priceRange / $latestObs->low_price) * 100, 2) : 0;

            $lines[] = '';
            $lines[] = 'INDIKATOR TEKNIKAL TERSIMPAN';
            if ($isIndex) {
                $lines[] = '• Rentang Indeks High-Low: '.number_format($priceRange, 2, ',', '.')." poin ({$rangePct}% rentang bar diukur terhadap batas Low bar)";
            } else {
                $lines[] = '• Rentang Harga High-Low: Rp '.number_format($priceRange, 2, ',', '.')." ({$rangePct}% rentang bar diukur terhadap batas Low bar)";
            }
            $lines[] = '• Catatan Metrik        : Dihitung murni dari bar OHLC tersimpan (rentang bar terhadap Low, bukan bid-ask spread).';
            if ($latestMetric) {
                $flags = $latestMetric->data_quality_flags ?? [];
                $lines[] = '• Support/Resistance   : Rp '.number_format((float) $latestMetric->support_price, 2, ',', '.').' / Rp '.number_format((float) $latestMetric->resistance_price, 2, ',', '.');
                $lines[] = '  Metode/window        : '.($flags['support_resistance_method'] ?? 'rolling_min_max').' / '.($flags['support_resistance_window_bars'] ?? 20).' bar';
                $lines[] = '  Timeframe/as-of      : '.($flags['interval'] ?? '1d').' / '.($latestMetric->as_of_date?->format('Y-m-d') ?? 'N/A');
                $lines[] = '  Sumber/adjustment    : '.($flags['source'] ?? 'IDX Edge PRO').' / '.($flags['adjustment_mode'] ?? 'N/A');
            } else {
                $lines[] = '• Support/Resistance   : N/A (indikator engine belum tersedia)';
            }
        } else {
            $lines[] = '• Data Pasar      : Belum ada observasi harga tersimpan di database.';
        }

        $lines[] = '';
        $lines[] = 'STATUS MODEL & PREDIKSI';
        $isValidatedUniverse = BeiUniverseRegistry::hasProductionMlInference($ticker);

        if ($isValidatedUniverse) {
            if ($latestSig) {
                $decDateStr = $latestSig->decision_date->format('d M Y');
                $dueStr = $latestSig->evaluation_due_date ? $latestSig->evaluation_due_date->format('d M Y') : 'N/A';
                $isExpiredHorizon = $latestSig->evaluation_due_date && $latestObs && $latestSig->evaluation_due_date->isBefore($latestObs->bar_date);

                if ($isExpiredHorizon) {
                    $lines[] = '• Status Sinyal    : NO_ACTIVE_INFERENCE_FOR_BAR';
                    $lines[] = "• Keterangan       : Sinyal historis {$latestSig->prediction_id} telah jatuh tempo pada {$dueStr} dan tidak berlaku sebagai prediksi aktif untuk bar {$barDateStr}.";
                    $lines[] = '• Status Pantau    : '.$latestSig->monitoring_status;
                } else {
                    $confPct = round($latestSig->confidence_score * 100, 2);
                    $pUpPct = round($latestSig->probability_up * 100, 2);
                    $pDownPct = round($latestSig->probability_down * 100, 2);

                    $lines[] = "• Signal ID        : {$latestSig->prediction_id}";
                    $lines[] = "• Status Sinyal    : {$latestSig->signal_status} [{$latestSig->direction}]";
                    $lines[] = "• Model Engine     : {$latestSig->model_name} ({$latestSig->model_version})";
                    $lines[] = "• Estimasi Prob    : P(UP) {$pUpPct}% | P(DOWN) {$pDownPct}%";
                    $lines[] = "• Confidence Score : {$confPct}% (Ambang batas selektif: >= 40,00% / Tau 0.70)";
                    $lines[] = '• Alasan Abstain   : '.($latestSig->reason_for_abstain ?: 'Tidak ada alasan abstain');
                    $lines[] = "• Evaluasi Horizon : {$latestSig->evaluation_status} (Jatuh Tempo: {$dueStr})";
                    $lines[] = "• Status Pantau    : {$latestSig->monitoring_status}";
                    $lines[] = '• Provenance       : Retrospective Replay (Unverified - Tidak dihitung sebagai forward valid)';
                }
            } else {
                $lines[] = '• Status Sinyal    : Belum ada rekod prediksi untuk emiten ini di database.';
            }
        } else {
            $lines[] = '• Status Sinyal    : MODEL_NOT_VALIDATED_FOR_INSTRUMENT';
            $lines[] = "• Keterangan       : Model prediktif machine learning belum dikalibrasi untuk emiten {$ticker}.";
            $lines[] = '• Estimasi Prob    : N/A';
            $lines[] = '• Confidence Score : N/A (Model belum diuji out-of-sample untuk instrumen ini; ambang batas Tau 0.70 tidak berlaku)';
            $lines[] = '• Batasan Sistem   : Dilarang menyajikan estimasi probabilitas atau sinyal beli/jual tanpa validasi formal.';
        }

        $lines[] = '';
        $lines[] = 'BERITA EMITEN TERKAIT';
        if ($latestNews) {
            $newsDate = $latestNews->published_at ? $latestNews->published_at->format('d M Y, H:i').' WIB' : 'N/A';
            $lines[] = "• Judul   : {$latestNews->title}";
            $lines[] = "• Sumber  : {$latestNews->source_name} ({$newsDate})";
            $lines[] = '• Ringkas : '.($latestNews->summary ?: 'Tidak ada ringkasan.');
        } else {
            $lines[] = '• Berita  : NOT_FETCHED_OR_UNKNOWN — sumber berita terautentikasi belum terbukti tersedia; bukan bukti berita tidak ada.';
        }

        // On-demand AI Narrative Explanation: Only execute if sync/test requested, or if already cached
        $aiResult = null;
        if ($latestObs) {
            $cachedAnalysis = null;
            try {
                $cachedAnalysis = MarketAnalysis::whereJsonContains('structured_output->ticker', $ticker)
                    ->whereJsonContains('structured_output->bar_date', $latestObs->bar_date->format('Y-m-d'))
                    ->where('validation_status', 'valid_schema')
                    ->latest('id')
                    ->first();
            } catch (\Throwable $e) {
                Log::error("[TelegramTwoWayService] Storage query failed for MarketAnalysis cache: {$e->getMessage()}", [
                    'ticker' => $ticker,
                    'bar_date' => $latestObs->bar_date->format('Y-m-d'),
                ]);
                $provenance['ai_storage_status'] = 'STORAGE_QUERY_FAILED';
            }

            if ($cachedAnalysis) {
                $lines[] = '';
                $lines[] = 'PENJELASAN NARATIF KUANTITATIF (AI LIVE)';
                $lines[] = "• Model / Engine : {$cachedAnalysis->model_name} [Tersimpan di DB]";
                $lines[] = '• Analisis Naratif:';
                $lines[] = $cachedAnalysis->market_regime_summary;

                $provenance['ai_analysis_id'] = $cachedAnalysis->id;
                $provenance['ai_model'] = $cachedAnalysis->model_name;
                $provenance['ai_cached'] = true;
                $provenance['ai_storage_status'] = 'FOUND';
            } elseif (! empty($provenance['is_sync'])) {
                $aiService = app(AiMarketAnalysisService::class);
                $aiResult = $aiService->generateOnDemandTickerExplanation(
                    $ticker,
                    $latestObs,
                    $latestSig,
                    $latestSig ? EvidenceSnapshot::find($latestSig->evidence_snapshot_id) : $marketRead->evidenceForObservation($latestObs),
                    $latestNews
                );

                if ($aiResult['success'] ?? false) {
                    $cacheTag = ($aiResult['is_cached'] ?? false) ? ' [Tersimpan di DB]' : '';
                    $lines[] = '';
                    $lines[] = 'PENJELASAN NARATIF KUANTITATIF (AI LIVE)';
                    $lines[] = "• Model / Engine : {$aiResult['model']}{$cacheTag}";
                    $lines[] = '• Analisis Naratif:';
                    $lines[] = $aiResult['narrative'];

                    $provenance['ai_analysis_id'] = $aiResult['analysis_id'];
                    $provenance['ai_model'] = $aiResult['model'];
                    $provenance['ai_latency_ms'] = $aiResult['latency_ms'];
                    $provenance['ai_cached'] = $aiResult['is_cached'];
                }
            }
        }

        $lines[] = '──────────────────────────────────────────';
        $obsTimeStr = $latestObs ? $latestObs->observation_time->timezone('Asia/Jakarta')->format('d M Y, H:i').' WIB' : 'N/A';
        $lines[] = "• Waktu Data Pasar : {$obsTimeStr}";
        $lines[] = "• Waktu Jawaban    : {$nowWib}";

        if ($aiResult && ($aiResult['success'] ?? false)) {
            $lines[] = '• Mode Operasional : Hybrid Grounded (PostgreSQL + 9router '.$aiResult['model'].')';
        } else {
            $lines[] = '• Mode Operasional : Deterministic DB Extractive (Live AI Nonaktif, Rp0 Biaya Data)';
        }

        return implode("\n", $lines);
    }
}
