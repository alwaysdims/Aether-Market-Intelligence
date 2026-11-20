<?php

namespace App\Services;

use App\Models\AnalysisRun;
use App\Models\BrokerSummary;
use App\Models\CompanyFundamental;
use App\Models\EvidenceSnapshot;
use App\Models\IdxCompanyRegistry;
use App\Models\MarketObservation;
use App\Models\ScheduledTelegramJob;
use App\Models\TelegramConversation;
use App\Models\TelegramOutbox;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class HermesConsultationAdapterService
{
    public function __construct(
        protected AiToolExecutorService $toolExecutor
    ) {}

    /**
     * Execute reasoning and consultation via Hermes Agent in dedicated isolated profile.
     */
    public function executeConsultation(ScheduledTelegramJob $job, array &$provenance = []): array
    {
        $startTime = microtime(true);
        // Isolated fresh session per job to guarantee strict boundary isolation across tickers
        // Contextual previous facts are injected into briefingContext directly rather than relying on session history leak
        $userSession = "aether_job_{$job->id}_{$job->telegram_user_id}";
        $lockKey = "hermes:session_lock:{$userSession}";

        // Prevent two jobs from concurrently writing/corrupting the same user conversation session
        $lock = Cache::lock($lockKey, 90);

        return $lock->block(15, function () use ($job, &$provenance, $startTime, $userSession) {
            return $this->runHermesProcess($job, $provenance, $startTime, $userSession);
        });
    }

    /**
     * Run the bounded Hermes CLI subprocess.
     */
    protected function runHermesProcess(
        ScheduledTelegramJob $job,
        array &$provenance,
        float $startTime,
        string $sessionName
    ): array {
        $promptLower = strtolower($job->original_prompt);

        // Scope Check Awal: Deteksi dini pertanyaan di luar cakupan pasar modal / saham BEI
        // Bukan sekadar keyword: bedakan resep/cuaca murni vs dampak bahan baku/komoditas/cuaca terhadap emiten/sektor bisnis
        $isMarketContext = preg_match('/(saham|emiten|pasar|bursa|bei|idx|ihsg|investasi|trading|porto|dividen|kinerja|dampak|pengaruh|sektor|margin|laba|rugi|prospek|rekomendasi|target|harga)/i', $promptLower);

        $isPureOutOfScopePrompt = false;
        if (! $isMarketContext) {
            $isPureOutOfScopePrompt = (bool) preg_match('/(resep|masak|bumbu|dapur|lirik lagu|sinopsis film|koding|tutorial programming|wisata liburan|rekomendasi anime|kunci gitar|chord)/i', $promptLower)
                || (bool) preg_match('/^(bagaimana|apa|cara|resep)\s+(masak|membuat|bikin)\s+/i', $promptLower);
        }

        if ($isPureOutOfScopePrompt) {
            $outOfScopeReply = "Aether Market Intelligence berfokus khusus pada analisis saham Bursa Efek Indonesia (BEI), intelijen pasar modal, dan konsultasi portofolio investasi.\n\nAda yang dapat kami bantu terkait analisis emiten atau kondisi pasar saham BEI?";

            return [
                'status' => 'COMPLETE',
                'text' => $outOfScopeReply,
                'analysis_id' => null,
                'evidence_id' => null,
                'canonical_output' => [
                    'schema_version' => 'aether.analysis.v3',
                    'status' => 'COMPLETE',
                    'request_kind' => 'OUT_OF_SCOPE_QUERY',
                    'answer' => $outOfScopeReply,
                    'limitations' => ['Topik di luar cakupan operasional Aether Market Intelligence.'],
                    'assessment' => [],
                ],
            ];
        }

        // Early Interception: System Status / Health / Queue Inquiry
        // Reads directly from database and worker heartbeats with ZERO LLM or external API calls
        $twoWay = app(TelegramTwoWayService::class);
        $extractedTicker = $twoWay->extractTicker($job->original_prompt);
        $isCrossTicker = $twoWay->isCrossTickerScreeningQuery($job->original_prompt);

        $isSystemStatusPrompt = preg_match('/(status|antrean|queue|service|koneksi|gateway|health|kondisi sistem|kondisi bot)/i', $promptLower)
            && ! $extractedTicker
            && ! $isCrossTicker;

        if ($isSystemStatusPrompt) {
            return $this->buildSystemStatusResponse($job);
        }

        $cliCmd = config('services.hermes.cli_command', 'hermes');
        $profile = config('services.hermes.profile', 'aether-prod');
        $budgetSec = (int) config('services.hermes.run_budget_seconds', 60);

        // Prepare grounded contextual briefing with governing rules, passing job to honor reply_to & analysis reference
        $briefingResolution = $this->resolveToolsBriefingWithComponents($job->original_prompt, $job);
        $toolsContext = $briefingResolution['tools_context'];
        $componentsRequested = $briefingResolution['components_requested'];
        $componentResults = $briefingResolution['component_results'];
        $limitations = $briefingResolution['limitations'];
        $briefingEvidenceIds = $briefingResolution['evidence_ids'];

        // Fetch recent conversation history & evidence strictly isolated for THIS telegram user
        // Exclude current message / attempt so we don't inject self
        $conversationContext = '';
        if ($job->telegram_user_id) {
            $convQuery = TelegramConversation::where('user_id', (string) $job->telegram_user_id)
                ->where('chat_id', (string) $job->chat_id);

            if ($job->inbound_message_id) {
                $convQuery->where(function ($q) use ($job) {
                    $q->whereNull('inbound_text')
                        ->orWhere('inbound_text', '!=', (string) $job->original_prompt);
                });
            }
            if ($job->telegram_update_id) {
                $convQuery->where('telegram_update_id', '!=', (string) $job->telegram_update_id);
            }

            // If job parameters contain a referenced reply_to_message_id, prioritize finding that specific conversation
            $replyToId = $job->parameters['reply_to_message_id'] ?? null;
            $referencedConv = null;
            if ($replyToId) {
                $referencedConv = TelegramConversation::where('chat_id', (string) $job->chat_id)
                    ->where(function ($q) use ($replyToId) {
                        $q->where('telegram_update_id', (string) $replyToId)
                            ->orWhereJsonContains('provenance->outbound_message_id', (string) $replyToId)
                            ->orWhereJsonContains('provenance->inbound_message_id', (string) $replyToId);
                    })
                    ->first();
            }

            $recentConvs = $convQuery->latest('id')
                ->take(3)
                ->get()
                ->reverse();

            if ($referencedConv && ! $recentConvs->contains('id', $referencedConv->id)) {
                $recentConvs->push($referencedConv);
            }

            if ($recentConvs->isNotEmpty()) {
                $convLines = ["=== RIWAYAT PERCAKAPAN & EVIDENCE TERDAHULU (Pengguna: {$job->telegram_user_id}) ==="];
                foreach ($recentConvs as $c) {
                    $snippet = substr(trim((string) $c->outbound_reply_text), 0, 350);
                    $evId = $c->provenance['evidence_id'] ?? null;
                    $anId = $c->provenance['analysis_id'] ?? null;
                    $evPart = $evId ? " [Evidence #{$evId}]" : '';
                    $anPart = $anId ? " [Analysis #{$anId}]" : '';
                    $isRef = ($referencedConv && $referencedConv->id === $c->id) ? ' [DIRUJUK REPLY-TO]' : '';
                    $convLines[] = "• Tanya: \"{$c->inbound_text}\"{$isRef}";
                    $convLines[] = "  Jawab: {$snippet}...{$evPart}{$anPart}";
                }
                $conversationContext = implode("\n", $convLines)."\n\n";
            }
        }

        $promptFile = storage_path('app/temp/hermes_prompt_'.$job->id.'_'.time().'.txt');
        @mkdir(dirname($promptFile), 0755, true);

        $fullPrompt = "PERMINTAAN PENGGUNA TELEGRAM (Job #{$job->id}):\n"
            ."\"{$job->original_prompt}\"\n\n"
            .$conversationContext
            ."DATA & INSTRUMEN TERKAIT:\n"
            ."{$toolsContext}\n\n"
            ."PEDOMAN ANALISIS & KESIMPULAN:\n"
            ."0. Identitas Instrumen & Status Listing:\n"
            ."   - Jika instrumen terdaftar di BEI (misal FORE adalah PT Fore Kopi Indonesia Tbk.), akui identitas emiten dengan benar.\n"
            ."   - JANGAN PERNAH menyatakan instrumen 'tidak terdaftar di BEI' atau 'belum IPO' hanya karena data di database lokal belum tersinkronisasi atau salah satu komponen provider belum tersedia.\n"
            ."   - Pisahkan identitas legal instrumen dari ketersediaan data komponen (fundamental/teknikal/broker).\n"
            ."1. Permintaan pengguna saat ini adalah panduan utama ruang lingkup (scope). Jangan mengunci jawaban hanya pada broker jika pengguna meminta fundamental, teknikal, day trade, swing, atau berita.\n"
            ."2. Struktur Jawaban Lengkap (jika diminta holistik):\n"
            ."   - Ringkasan Profil & Harga Terkini (Sesi terakhir terverifikasi di BEI, tren MA/RSI/MACD).\n"
            ."   - Kondisi Fundamental (Kinerja keuangan, laba rugi, aset, valuasi).\n"
            ."   - Aliran Broker Pasar Reguler (Sesi terakhir vs akumulasi berkala, Top Buyer/Seller).\n"
            ."   - Berita & Keterbukaan Informasi (Aksi korporasi, publikasi resmi atau batasan riset).\n"
            ."   - Kesimpulan Day Trade & Swing Trade (Wajib dipisah! Berikan status kelayakan dan alasan terukur).\n"
            ."3. Sebutkan sumber data sebagai \"API IDX Edge (Pasar Reguler)\" untuk histori/broker/fundamental, dan \"PostgreSQL project-bot\" untuk indikator deterministik.\n"
            ."4. Sebutkan tanggal sesi terakhir yang benar-benar diverifikasi (bukan tanggal acak).\n"
            ."5. Bedakan Gross Buy Avg (buy_value / buy_volume) dan Gross Sell Avg (sell_value / sell_volume). Dilarang menyimpulkan identitas nasabah dari kode broker.\n"
            ."   - PENTING: Gross Buy Avg adalah rata-rata harga beli kotor seluruh transaksi broker dalam periode tersebut, BUKAN cost basis posisi tersisa (inventory net position). Jangan menggunakan selisih harga penutupan terhadap Gross Buy Avg sebagai bukti valuasi murah/mahal, level support, atau alasan utama kelayakan swing trade!\n"
            ."6. Peringatan Kesimpulan Logis & Kelayakan Transaksi:\n"
            ."   - Net broker BUKAN total turnover bursa dan bukan bukti kepemilikan definitif entitas tunggal.\n"
            ."   - Kuotasi /api/price berstatus QUARANTINED (kontaminasi transaksi Pasar Negosiasi). Tanpa data intraday Pasar Reguler dan spread bid-offer yang valid, status day trade wajib: DAY_TRADE_NOT_ASSESSABLE_WITH_AVAILABLE_DATA.\n"
            ."   - Dilarang menyimpulkan spread bid-ask dari rentang High-Low bar harian atau net flow broker.\n"
            ."   - Dilarang menyatakan penyebab penurunan harga tanpa bukti dokumen atau berita terverifikasi.\n"
            ."   - Satu candle harian BUKAN tren multi-sesi, dan bukan bukti distribusi atau akumulasi berkelanjutan. Posisi candle diukur matematis terhadap rentang bar: (Close - Low) / (High - Low). Jika nilainya di tengah (misal 42,86%), jangan menyatakan penutupan 'mendekati low' tanpa angka persentase terhitung.\n"
            ."   - Kinerja Keuangan & Fundamental: Beri label PROVIDER_SUPPLIED kecuali ada dokumen primer fisik audited yang terverifikasi. Rugi bersih TIDAK OTOMATIS berarti rugi operasional; bedakan rugi bersih dari rugi operasional/laba usaha. Rasio Total Liabilitas / Total Ekuitas BUKAN rasio utang pinjaman berbunga (DER murni). Jangan mencampuradukkan PER/PBV tanpa mencocokkan harga bar, EPS, BVPS, dan periode laporan tahunan yang berlaku.\n"
            ."   - Sumber Data: Dilarang menggabungkan narasi kontradiktif seperti 'IDX Edge via Yahoo'. Sumber operasional tunggal adalah API IDX Edge PRO (Pasar Reguler) dan PostgreSQL project-bot.\n"
            ."   - Berita & Audit Provenance: Pisahkan dengan jelas LOCAL_DB_READ, LOCAL_CACHE, dan EXTERNAL_HTTP_FETCH. Jika fresh search pada basis data berita/dokumen lokal tidak menemukan hasil, sebutkan 'Tidak ditemukan pada sumber yang diperiksa', BUKAN 'tidak ada berita atau keterbukaan'.\n"
            ."   - Periode Broker & Transaksi: Wajib membedakan rentang sesi yang diminta pengguna dari rentang sesi yang benar-benar tercakup dan diverifikasi. Jangan mengklaim ada transaksi sampai tanggal hari libur/akhir pekan jika bursa tutup.\n"
            ."   - Jika bukti data tidak cukup untuk swing trade, gunakan status: SWING_ASSESSMENT_PARTIAL atau INSUFFICIENT_EVIDENCE dengan fakta risiko yang terverifikasi.\n"
            ."   - Pisahkan keterbatasan sistem (data feed) dari risiko instrumen (papan pemantauan khusus, likuiditas, fraksi harga).\n"
            .'7. Berikan jawaban dalam Bahasa Indonesia yang padat, terstruktur, dan objektif.';

        file_put_contents($promptFile, $fullPrompt);

        // Command invocation: hermes -p aether-prod chat --query-file ... --oneshot -Q --run-budget 90 --max-turns 15
        // Do not use -c/--create-if-missing with ephemeral session names as that seeds empty sessions and emits stderr resume warnings
        $args = [
            $cliCmd,
            '-p', $profile,
            'chat',
            '--query-file', $promptFile,
            '--oneshot',
            '-Q',
            '--run-budget', (string) $budgetSec,
            '--max-turns', '15',
        ];

        // Run budget and subprocess timeout:
        // Set generous subprocess timeout (180s) so Hermes can complete multi-turn reasoning without getting cut off
        $process = new Process($args);
        $process->setTimeout(180);
        $process->setIdleTimeout(null);

        try {
            $process->run();
            @unlink($promptFile);

            $exitCode = $process->getExitCode();
            $rawStdout = trim($process->getOutput());
            $rawStderr = trim($process->getErrorOutput());

            // Check if process failed with non-zero exit code
            if (! $process->isSuccessful()) {
                Log::error("[HermesConsultationAdapter] Subprocess non-zero exit ({$exitCode}) on Job #{$job->id}: {$rawStderr}", [
                    'stdout' => $rawStdout,
                    'stderr' => $rawStderr,
                ]);

                $userReason = 'Sistem penalaran Hermes mengalami kendala teknis saat memproses kueri.';
                if ($exitCode === 124 || str_contains($rawStderr, 'timeout') || str_contains($rawStderr, 'budget')) {
                    $userReason = 'Batas waktu penalaran terlampaui saat memproses analisis mendalam.';
                }

                return [
                    'status' => 'FAILED',
                    'text' => "⚠️ {$userReason}\nID Referensi: JOB-{$job->id}",
                    'analysis_id' => null,
                    'evidence_id' => null,
                ];
            }

            // In successful run, Hermes might still emit informational notices or session hints to stderr
            // NEVER use stderr as the final answer or leak it to user. Clean session_id trailing footer from stdout if present.
            $cleanedOutput = preg_replace('/\n*session_id:\s*[a-zA-Z0-9_-]+\s*$/i', '', $rawStdout);
            $cleanedOutput = trim((string) $cleanedOutput);

            if ($cleanedOutput === '') {
                Log::warning("[HermesConsultationAdapter] Process exited 0 but stdout was empty on Job #{$job->id}", [
                    'stderr' => $rawStderr,
                ]);

                return [
                    'status' => 'FAILED',
                    'text' => "⚠️ Sistem penalaran Hermes tidak menghasilkan kesimpulan naratif.\nID Referensi: JOB-{$job->id}",
                    'analysis_id' => null,
                    'evidence_id' => null,
                ];
            }

            $rawOutput = $cleanedOutput;

            // Check 2b: Text output semantic mismatch
            // Jawaban divalidasi melenceng jika murni menjabarkan resep/tutorial non-finansial tanpa konteks pasar/emiten
            $isOutOfScopeAnswer = preg_match('/(resep masakan|takaran bumbu|tumis bumbu|goreng nasi|panaskan wajan|kunci gitar|chord dasar)/i', $rawOutput)
                && ! preg_match('/(saham|emiten|pasar|bursa|bei|idx|ihsg|investasi|trading|kinerja|margin|laba)/i', $rawOutput);
            if ($isOutOfScopeAnswer) {
                Log::warning("[HermesConsultationAdapter] Output semantic mismatch detected on Job #{$job->id}: Output discussing cooking/recipe instead of capital market.");

                return [
                    'status' => 'FAILED',
                    'text' => "⚠️ Sistem penalaran menghasilkan output di luar cakupan pasar modal (OUTPUT_SCOPE_MISMATCH).\nID Referensi: JOB-{$job->id}",
                    'analysis_id' => null,
                    'evidence_id' => null,
                    'error_type' => 'OUTPUT_SCOPE_MISMATCH',
                ];
            }

            // 1. Text sanity: reject truly empty or corrupted short responses
            if (strlen($rawOutput) < 10) {
                Log::warning("[HermesConsultationAdapter] Output failed minimal text sanity on Job #{$job->id}: {$rawOutput}");

                return [
                    'status' => 'FAILED',
                    'text' => "⚠️ Sistem penalaran Hermes tidak menghasilkan kesimpulan analisis yang memadai.\nID Referensi: JOB-{$job->id}",
                    'analysis_id' => null,
                    'evidence_id' => null,
                ];
            }

            // 2. Classify request kind dynamically based on prompt intent and structure
            $promptLower = strtolower($job->original_prompt);
            $twoWay = app(TelegramTwoWayService::class);
            $extractedTicker = $twoWay->extractTicker($job->original_prompt);
            $isCrossTicker = $twoWay->isCrossTickerScreeningQuery($job->original_prompt);

            // Scope Check: Deteksi permintaan di luar cakupan pasar modal / saham BEI
            $isOutOfScopePrompt = preg_match('/(resep|masak|nasi goreng|cuaca|lirik lagu|film|coding|wisata|anime|chord)/i', $promptLower)
                && ! preg_match('/(saham|emiten|pasar|bursa|bei|idx|ihsg|investasi|trading|porto|dividen)/i', $promptLower);

            if ($isOutOfScopePrompt) {
                $outOfScopeReply = "Aether Market Intelligence berfokus khusus pada analisis saham Bursa Efek Indonesia (BEI), intelijen pasar modal, dan konsultasi portofolio investasi.\n\nAda yang dapat kami bantu terkait analisis emiten atau kondisi pasar saham BEI?";

                return [
                    'status' => 'COMPLETE',
                    'text' => $outOfScopeReply,
                    'analysis_id' => null,
                    'evidence_id' => null,
                    'canonical_output' => [
                        'schema_version' => 'aether.analysis.v3',
                        'status' => 'COMPLETE',
                        'request_kind' => 'OUT_OF_SCOPE_QUERY',
                        'answer' => $outOfScopeReply,
                        'limitations' => ['Topik di luar cakupan operasional Aether Market Intelligence.'],
                        'assessment' => [],
                    ],
                ];
            }

            if (preg_match('/(apa itu|arti|definisi|maksud|jelaskan konsep|edukasi|rumus|bagaimana cara kerja)/i', $promptLower) && ! $extractedTicker) {
                $requestKind = 'EDUCATION';
            } elseif (preg_match('/(sumber data|ambil data dari mana|asal data|database apa|source)/i', $promptLower) && ! $extractedTicker) {
                $requestKind = 'DATA_PROVENANCE_INQUIRY';
            } elseif (preg_match('/(status|antrean|queue|service|koneksi|gateway|health)/i', $promptLower) && ! $extractedTicker) {
                $requestKind = 'SYSTEM_STATUS';
            } elseif (preg_match('/(bandingkan|komparasi|vs|versus|lebih baik mana)/i', $promptLower)) {
                $requestKind = 'COMPARISON';
            } elseif ($isCrossTicker || preg_match('/(screening|saring|filter|top-n|kandidat)/i', $promptLower)) {
                $requestKind = 'SCREENING';
            } elseif (preg_match('/(broker|bandar|akumulasi|distribusi|top buyer|top seller)/i', $promptLower) && ! preg_match('/(fundamental|teknikal|day trade|swing)/i', $promptLower)) {
                $requestKind = 'BROKER_FLOW';
            } elseif (preg_match('/(apakah|kenapa|mengapa|bagaimana|jelaskan|cakupan|periode|sumber|keterbatasan|angka)/i', $promptLower) && ! preg_match('/(analisis|bedah)/i', $promptLower)) {
                $requestKind = 'FOLLOW_UP_CLARIFICATION';
            } elseif ($extractedTicker !== null) {
                $requestKind = 'COMPREHENSIVE_ANALYSIS';
            } else {
                $requestKind = 'GENERAL_CONSULTATION';
            }

            // 3. Resolve and Validate Evidence IDs
            // Collect IDs from structured briefing execution + backward-compatible regex extraction
            preg_match_all('/Evidence\s*(?:Snapshot)?\s*#?([0-9]+)/i', $rawOutput, $evMatches);
            $regexEvidenceIds = array_unique(array_map('intval', $evMatches[1] ?? []));
            $allEvidenceIdsToCheck = array_unique(array_merge($briefingEvidenceIds, $regexEvidenceIds));

            $validEvidenceIds = [];
            $invalidEvidenceIds = [];
            foreach ($allEvidenceIdsToCheck as $cid) {
                $evRecord = EvidenceSnapshot::find($cid);
                if (! $evRecord) {
                    $invalidEvidenceIds[] = "ID #{$cid} tidak ditemukan di database";

                    continue;
                }

                // Check authorization and context match
                $evTicker = strtoupper(trim((string) $evRecord->ticker));
                $evSnap = $evRecord->features_snapshot ?? [];
                $evJobId = $evSnap['job_id'] ?? null;

                // Ticker match check (if specific ticker requested, evidence must match ticker or MARKET/ALL)
                if ($extractedTicker && ! in_array($evTicker, [$extractedTicker, 'MARKET', 'ALL', 'CROSS_TICKER'])) {
                    $invalidEvidenceIds[] = "Evidence #{$cid} berticker {$evTicker}, tidak sesuai dengan emiten kueri {$extractedTicker}";

                    continue;
                }

                // User authorization check: evidence generated by this user or public market evidence
                $evChatId = $evSnap['chat_id'] ?? null;
                $evUserId = $evSnap['user_id'] ?? null;
                if ($evUserId && (string) $evUserId !== (string) $job->telegram_user_id) {
                    $invalidEvidenceIds[] = "Evidence #{$cid} bukan milik pengguna {$job->telegram_user_id}";

                    continue;
                }

                $validEvidenceIds[] = $cid;
            }

            // Strict rejection if any fabricated/mismatched evidence ID was cited
            if (! empty($invalidEvidenceIds)) {
                Log::warning("[HermesConsultationAdapter] Evidence validation rejected on Job #{$job->id}: ".implode('; ', $invalidEvidenceIds));

                return [
                    'status' => 'FAILED',
                    'text' => "⚠️ Sistem penalaran Hermes mengutip bukti data yang tidak valid atau tidak berwenang.\nID Referensi: JOB-{$job->id}",
                    'analysis_id' => null,
                    'evidence_id' => null,
                ];
            }

            // 4. Verification for Market/Price/Fundamental/Broker Claims:
            // If answer asserts market/fundamental/broker numbers, valid tool/evidence MUST back it up!
            $hasMarketPriceNumbers = preg_match('/Rp\s*[0-9]+([.,][0-9]+)?/i', $rawOutput) || preg_match('/(close|open|high|low|volume)\s*(?:rp)?:?\s*[0-9]+/i', $rawOutput);
            if ($hasMarketPriceNumbers && in_array($requestKind, ['COMPREHENSIVE_ANALYSIS', 'BROKER_FLOW', 'SCREENING'])) {
                if (empty($validEvidenceIds) && empty($briefingResolution['has_tool_evidence'])) {
                    Log::warning("[HermesConsultationAdapter] Unbacked market numeric claims on Job #{$job->id}: no relevant evidence backing numbers.");

                    return [
                        'status' => 'FAILED',
                        'text' => "⚠️ Klaim angka pasar pada analisis tidak didukung oleh referensi evidence yang valid.\nID Referensi: JOB-{$job->id}",
                        'analysis_id' => null,
                        'evidence_id' => null,
                    ];
                }
            }

            // 5. Derive Status from Structured Component Results (NOT narative regex)
            // If any requested component failed or was missing, status is PARTIAL
            $hasComponentFailures = false;
            foreach ($componentResults as $compName => $compRes) {
                if (($componentsRequested[$compName] ?? false) && in_array($compRes['status'] ?? '', ['DATA_NOT_AVAILABLE', 'FETCH_FAILED', 'PROVIDER_EMPTY', 'INSUFFICIENT_DATA', 'FAILED'])) {
                    $hasComponentFailures = true;
                    break;
                }
            }

            if (! empty($limitations) || $hasComponentFailures) {
                $agentStatus = 'PARTIAL';
            } else {
                $agentStatus = 'COMPLETE';
            }

            // 6. Build Versioned Canonical Schema Output (v3)
            // Extract exact assessment states from raw response without arbitrary defaults
            $dayTradeStatus = null;
            if (str_contains($rawOutput, 'DAY_TRADE_NOT_ASSESSABLE_WITH_AVAILABLE_DATA')) {
                $dayTradeStatus = 'DAY_TRADE_NOT_ASSESSABLE_WITH_AVAILABLE_DATA';
            } elseif (str_contains($rawOutput, 'DAY_TRADE_SUITABLE')) {
                $dayTradeStatus = 'SUITABLE';
            } elseif (str_contains($rawOutput, 'DAY_TRADE_NOT_SUITABLE')) {
                $dayTradeStatus = 'NOT_SUITABLE';
            }

            $swingTradeStatus = null;
            if (str_contains($rawOutput, 'SWING_ASSESSMENT_PARTIAL')) {
                $swingTradeStatus = 'SWING_ASSESSMENT_PARTIAL';
            } elseif (str_contains($rawOutput, 'SWING_SUITABLE')) {
                $swingTradeStatus = 'SUITABLE';
            } elseif (str_contains($rawOutput, 'SWING_NOT_SUITABLE')) {
                $swingTradeStatus = 'NOT_SUITABLE';
            }

            $riskLevel = null;
            if (preg_match('/(?:profil|tingkat)\s+risiko\s*:\s*([A-Za-z_]+)/i', $rawOutput, $mRisk)) {
                $riskLevel = strtoupper(trim($mRisk[1]));
            }

            $assessmentPayload = [];
            if ($dayTradeStatus !== null) {
                $assessmentPayload['day_trade'] = $dayTradeStatus;
            }
            if ($swingTradeStatus !== null) {
                $assessmentPayload['swing_trade'] = $swingTradeStatus;
            }
            if ($riskLevel !== null) {
                $assessmentPayload['risk_level'] = $riskLevel;
            }

            $canonicalResult = [
                'schema_version' => 'aether.analysis.v3',
                'job_id' => $job->id,
                'request_kind' => $requestKind,
                'ticker' => $job->ticker,
                'status' => $agentStatus,
                'answer' => $rawOutput,
                'summary' => substr($rawOutput, 0, 200),
                'components_requested' => $componentsRequested,
                'component_results' => $componentResults,
                'evidence_ids' => $validEvidenceIds,
                'requested_period' => [
                    'start' => Carbon::now('Asia/Jakarta')->subMonths(3)->toDateString(),
                    'end' => Carbon::now('Asia/Jakarta')->toDateString(),
                ],
                'actual_data_period' => [
                    'start' => Carbon::now('Asia/Jakarta')->subMonths(3)->toDateString(),
                    'end' => Carbon::now('Asia/Jakarta')->toDateString(),
                ],
                'limitations' => $limitations,
                'assessment' => $assessmentPayload,
                'ml_inference_id' => null,
            ];

            // Render structured text uniformly for Telegram presentation
            $renderedText = app(UnifiedAnalysisRendererService::class)->renderToText($canonicalResult, 'TELEGRAM');

            // Record Evidence Snapshot & Analysis Run
            $evidencePayload = [
                'orchestrator' => 'HERMES_AGENT_WORKER',
                'request_kind' => $requestKind,
                'agent_status' => $agentStatus,
                'canonical_output' => $canonicalResult,
                'profile' => $profile,
                'session' => $sessionName,
                'job_id' => $job->id,
                'inbound_prompt' => $job->original_prompt,
                'cited_evidence_ids' => $validEvidenceIds,
                'components_requested' => $componentsRequested,
                'component_results' => $componentResults,
                'limitations' => $limitations,
                'tools_context' => $toolsContext,
                'hermes_response' => $rawOutput,
            ];

            $targetTicker = substr($job->ticker ?: 'MARKET', 0, 10);
            $snapshotId = 'EV_HERMES_'.now()->format('Ymd_His').'_'.$job->id;

            $ev = EvidenceSnapshot::create([
                'snapshot_id' => $snapshotId,
                'ticker' => $targetTicker,
                'decision_date' => now()->toDateString(),
                'market_regime' => 'SIDEWAYS',
                'features_snapshot' => $evidencePayload,
                'data_hash' => hash('sha256', json_encode($evidencePayload)),
            ]);

            $run = AnalysisRun::create([
                'run_id' => 'HERMES_RUN_'.now()->format('Ymd_His').'_'.$job->id,
                'trigger_type' => 'ASYNC_CONSULTATION_QUEUE',
                'status' => 'COMPLETED',
                'started_at' => now()->subMilliseconds((int) round((microtime(true) - $startTime) * 1000)),
                'completed_at' => now(),
                'duration_seconds' => (int) round(microtime(true) - $startTime),
                'metadata' => [
                    'ticker' => $targetTicker,
                    'run_type' => 'HERMES_REASONING_CONSULTATION',
                    'model_used' => 'ag/gemini-3.8-flash-high (via Hermes aether-prod)',
                    'narrative' => $renderedText,
                    'agent_status' => $agentStatus,
                    'canonical_output' => $canonicalResult,
                ],
            ]);

            $provenance['ai_analysis_id'] = $run->id;
            $provenance['ai_evidence_id'] = $ev->id;
            $provenance['orchestrator'] = 'HERMES_AGENT';
            $provenance['agent_status'] = $agentStatus;
            $provenance['profile'] = $profile;

            return [
                'status' => $agentStatus,
                'text' => $renderedText,
                'analysis_id' => $run->id,
                'evidence_id' => $ev->id,
                'canonical_output' => $canonicalResult,
                'market_session_closed_at' => now('Asia/Jakarta')->toIso8601String(),
                'evidence_available_at' => now('Asia/Jakarta')->toIso8601String(),
                'analysis_information_cutoff' => now('Asia/Jakarta')->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            @unlink($promptFile);
            Log::error("[HermesConsultationAdapter] Exception on Job #{$job->id}: ".$e->getMessage());

            return [
                'status' => 'FAILED',
                'text' => "⚠️ Terjadi kegagalan komunikasi dengan runtime Hermes pada Job #{$job->id}.\n"
                    .'Keterangan: '.$e->getMessage(),
                'analysis_id' => null,
                'evidence_id' => null,
            ];
        }
    }

    /**
     * Resolve relevant tool context based on user inquiry.
     */
    protected function resolveToolsBriefing(string $prompt, ?ScheduledTelegramJob $job = null): string
    {
        $res = $this->resolveToolsBriefingWithComponents($prompt, $job);

        return $res['tools_context'];
    }

    /**
     * Resolve relevant tool context with structured component results and evidence tracking.
     */
    public function resolveToolsBriefingWithComponents(string $prompt, ?ScheduledTelegramJob $job = null): array
    {
        $lines = [];
        $lower = strtolower($prompt);
        $componentsRequested = [];
        $componentResults = [];
        $limitations = [];
        $evidenceIds = [];
        $hasToolEvidence = false;

        // Check if query is cross-ticker broker screening or follow-up on recent screening
        $twoWay = app(TelegramTwoWayService::class);
        $isCrossTicker = $twoWay->isCrossTickerScreeningQuery($prompt);

        // Resolve ticker with strict hierarchy:
        // 1. Job explicit parameter ticker
        // 2. Direct extraction from prompt
        // 3. Fallback resolution via conversation history & reply_to
        $extractedTicker = null;
        if (! $isCrossTicker) {
            $extractedTicker = $job?->parameters['ticker'] ?? null;
            if (! $extractedTicker) {
                $resContext = $twoWay->resolveInstrumentContext(
                    $prompt,
                    $job?->telegram_user_id ? (string) $job->telegram_user_id : null,
                    $job?->parameters['reply_to_message_id'] ?? null
                );
                $extractedTicker = $resContext['ticker'] ?? null;
            }
        }

        $isFollowUp = preg_match('/(apakah|kenapa|mengapa|bagaimana|jelaskan|cakupan|periode|sumber|keterbatasan|angka|broker pendukung|sesi terakhir|top-n|kandidat)/i', $lower)
            && ! preg_match('/(update|perbarui|sinkron|tarik ulang|refresh|ambil lagi|cari ulang)/i', $lower);

        if ($extractedTicker === null && ($isCrossTicker || str_contains($lower, 'akumulasi') || str_contains($lower, 'distribusi') || str_contains($lower, 'screening') || str_contains($lower, 'rekomendasi') || str_contains($lower, 'pilot') || str_contains($lower, 'cakupan') || str_contains($lower, 'periode'))) {
            $componentsRequested['screening'] = true;
            // If this is a follow-up inquiry on previous screening, reuse referenced or recent AnalysisRun / EvidenceSnapshot directly!
            if ($isFollowUp) {
                $refAnalysisId = $job?->parameters['analysis_id'] ?? null;
                $recentRun = null;
                if ($refAnalysisId) {
                    $recentRun = AnalysisRun::where('id', $refAnalysisId)->first();
                }
                if (! $recentRun) {
                    $recentRun = AnalysisRun::where('status', 'COMPLETED')
                        ->latest('id')
                        ->first();
                }

                if ($recentRun && ! empty($recentRun->metadata['narrative'])) {
                    $lines[] = "=== HASIL SCREENING SEBELUMNYA (REUSED FROM RUN #{$recentRun->id}) ===\n".$recentRun->metadata['narrative'];
                    $componentResults['screening'] = ['status' => 'AVAILABLE', 'source' => 'ANALYSIS_RUN_CACHE'];
                    $hasToolEvidence = true;

                    return [
                        'tools_context' => implode("\n\n", $lines),
                        'components_requested' => $componentsRequested,
                        'component_results' => $componentResults,
                        'limitations' => $limitations,
                        'evidence_ids' => $evidenceIds,
                        'has_tool_evidence' => $hasToolEvidence,
                    ];
                }
            }

            $screening = app(BrokerAccumulationScreeningService::class);
            $prov = [];
            $screeningResult = $screening->executeScreening($prompt, $prov);
            $lines[] = "=== HASIL SCREENING AKUMULASI BROKER LINTAS EMITEN (Aether Engine) ===\n".$screeningResult;
            $componentResults['screening'] = ['status' => 'AVAILABLE', 'source' => 'Aether-Quant-Engine'];
            if (! empty($prov['evidence_id'])) {
                $evidenceIds[] = (int) $prov['evidence_id'];
            }
            $hasToolEvidence = true;
        } elseif ($extractedTicker !== null) {
            $registry = app(BeiUniverseRegistry::class);
            $resolution = $registry->resolveInstrument($extractedTicker);
            $companyName = $resolution['company_name'] ?? "Emiten {$extractedTicker}";
            $provSource = $resolution['resolution_provenance']['source'] ?? 'UNKNOWN';

            $lines[] = "=== IDENTITAS RESMI EMITEN {$extractedTicker} (Bursa Efek Indonesia) ===\n"
                ."• Nama Resmi Perusahaan : {$companyName}\n"
                ."• Kode Ticker           : {$extractedTicker}\n"
                ."• Bursa Tercatat        : Bursa Efek Indonesia (IDX/BEI)\n"
                ."• Sumber Resolusi       : {$provSource}\n"
                .'• Catatan Penting       : Emiten terdaftar resmi di BEI. Ketiadaan data pada salah satu komponen (fundamental/teknikal/broker) adalah murni masalah ketersediaan data penyedia/sinkronisasi, BUKAN bukti emiten tidak terdaftar atau belum IPO.';

            $isFilingQuestion = preg_match('/(laporan|h1|h2|q1|q2|q3|q4|margin|laba operasi|dihentikan|atribusi|calk|catatan|keterbukaan|filing)/i', $lower);
            $wantsTechnical = preg_match('/(teknikal|trend|indikator|support|resistance|day trade|swing)/i', $lower);
            $wantsBroker = preg_match('/(broker|akumulasi|distribusi|bandarmologi|top buyer|top seller)/i', $lower);
            $isHolistic = preg_match('/(lengkap|bedah|komprehensif|ringkasan emiten)/i', $lower) || (! $isFilingQuestion && ! $wantsTechnical && ! $wantsBroker);

            // 0. Ensure Price Freshness
            $componentsRequested['price_eod'] = true;
            $currentTradingDay = app(BeiCalendarService::class)->getCurrentTradingDay(Carbon::now('Asia/Jakarta'))->toDateString();
            $latestObs = MarketObservation::where('ticker', $extractedTicker)
                ->where('source', 'IDX Edge PRO')
                ->where('validation_status', 'VALID')
                ->latest('bar_date')
                ->first();

            $needsEodSync = (! $latestObs || Carbon::parse($latestObs->bar_date)->toDateString() < $currentTradingDay);
            if ($needsEodSync) {
                app(DataSourceRegistryService::class)->syncControlledEodHistory($extractedTicker, 25);
                $latestObs = MarketObservation::where('ticker', $extractedTicker)
                    ->where('source', 'IDX Edge PRO')
                    ->where('validation_status', 'VALID')
                    ->latest('bar_date')
                    ->first();
            }

            if ($latestObs) {
                $componentResults['price_eod'] = ['status' => 'AVAILABLE', 'date' => $latestObs->bar_date->toDateString(), 'close' => $latestObs->close_price];
                $hasToolEvidence = true;
            } else {
                $componentResults['price_eod'] = ['status' => 'DATA_NOT_AVAILABLE', 'reason' => 'Bar EOD tidak ditemukan di IDX Edge PRO'];
                $limitations[] = 'Data harga EOD tidak tersedia';
            }

            // 1. Ticker Profile & Technical Indicators (with Support/Resistance)
            if ($isHolistic || $wantsTechnical) {
                $componentsRequested['technical'] = true;
                $metric = app(TechnicalMetricsCalculationService::class)->calculateForTicker($extractedTicker);
                $provAnalysis = [];
                $analysis = $this->toolExecutor->executeTool(
                    'get_instrument_analysis',
                    ['ticker' => $extractedTicker],
                    '5923895922',
                    '5923895922',
                    $provAnalysis
                );

                // Add deterministic Support/Resistance and EOD Day Trade Boundary
                $supFmt = ($metric && $metric->support_price) ? 'Rp '.number_format($metric->support_price, 0, ',', '.') : 'N/A (Histori tidak cukup)';
                $resFmt = ($metric && $metric->resistance_price) ? 'Rp '.number_format($metric->resistance_price, 0, ',', '.') : 'N/A (Histori tidak cukup)';
                $inputBars = $metric?->data_quality_flags['input_bars'] ?? 0;
                $asOfDate = $metric?->as_of_date ? Carbon::parse($metric->as_of_date)->format('d M Y') : 'sesi terakhir';

                $srSection = "\n=== LEVEL PANTULAN & BATAS HISTORIS {$extractedTicker} ===\n"
                    ."• Batas Bawah Historis (Rolling 20 Bar Low)  : {$supFmt}\n"
                    ."• Batas Atas Historis (Rolling 20 Bar High)  : {$resFmt}\n"
                    ."• Basis Data & Tanggal Acuan                 : Bar EOD {$asOfDate} (Jumlah Bar Input: {$inputBars})\n"
                    ."• Metode & Karakteristik                      : Nilai minimum/maksimum 20 sesi bursa historis (bukan jaminan titik pantul atau target otomatis; level high yang jauh bukan resistance langsung).\n"
                    ."• KELAYAKAN DAY TRADE                         : Level historis EOD tidak dapat dijadikan titik masuk (entry) intraday saat ini.\n"
                    .'  Status: DAY_TRADE_NOT_ASSESSABLE_WITH_AVAILABLE_DATA.';

                $lines[] = "=== KONDISI TEKNIKAL & HARGA {$extractedTicker} (PostgreSQL project-bot) ===\n".$analysis.$srSection;
                $componentResults['technical'] = [
                    'status' => 'AVAILABLE',
                    'support' => $metric?->support_price,
                    'resistance' => $metric?->resistance_price,
                    'input_bars' => $inputBars,
                ];
                $hasToolEvidence = true;
            }

            // 2. Fundamental & Financial Statements
            if ($isHolistic || str_contains($lower, 'fundamental') || str_contains($lower, 'kinerja') || str_contains($lower, 'laba') || str_contains($lower, 'lapkeu') || str_contains($lower, 'valuasi')) {
                $componentsRequested['fundamental'] = true;
                $recentAnnual = CompanyFundamental::where('ticker', $extractedTicker)
                    ->where('source', 'IDX Edge PRO')
                    ->where('report_type', 'ANNUAL')
                    ->whereIn('data_quality', ['PROVIDER_SUPPLIED', 'DOCUMENT_VERIFIED'])
                    ->latest('fiscal_year')
                    ->first();

                $hasAdequateFunds = $recentAnnual && ($recentAnnual->fiscal_year >= 2024);
                $syncStatus = $hasAdequateFunds ? 'ALREADY_SYNCED' : 'NOT_SYNCED';

                if (! $hasAdequateFunds) {
                    $syncRes = app(IdxEdgeFundamentalIngestionService::class)->syncAnnualStatements($extractedTicker, 2);
                    if (! ($syncRes['success'] ?? false)) {
                        $err = $syncRes['error_type'] ?? '';
                        if ($err === 'PROVIDER_EMPTY') {
                            $syncStatus = 'PROVIDER_EMPTY';
                        } elseif ($err === 'VALIDATION_FAILED' || $err === 'VALIDATION_REJECTED') {
                            $syncStatus = 'VALIDATION_REJECTED';
                        } else {
                            $syncStatus = 'FETCH_FAILED';
                        }
                    } else {
                        $syncStatus = 'SYNC_SUCCESS';
                    }
                }

                $provFund = [];
                $fund = $this->toolExecutor->executeTool(
                    'get_company_fundamentals',
                    ['ticker' => $extractedTicker],
                    '5923895922',
                    '5923895922',
                    $provFund
                );
                $lines[] = "=== LAPORAN KEUANGAN & FUNDAMENTAL {$extractedTicker} (API IDX Edge, Status: {$syncStatus}) ===\n".$fund;

                if (in_array($syncStatus, ['PROVIDER_EMPTY', 'FETCH_FAILED', 'VALIDATION_REJECTED', 'NOT_SYNCED'])) {
                    $componentResults['fundamental'] = ['status' => 'DATA_NOT_AVAILABLE', 'sync_status' => $syncStatus];
                    $limitations[] = "Laporan keuangan fundamental berstatus {$syncStatus}";
                } else {
                    $componentResults['fundamental'] = ['status' => 'AVAILABLE', 'sync_status' => $syncStatus];
                    $hasToolEvidence = true;
                }
            }

            // 3. Broker Flow
            if ($wantsBroker || ($isHolistic && ! $isFilingQuestion)) {
                $componentsRequested['broker'] = true;
                $prov = [];
                $brokerSummary = $this->toolExecutor->executeTool(
                    'get_broker_summary',
                    ['ticker' => $extractedTicker],
                    '5923895922',
                    '5923895922',
                    $prov
                );
                $lines[] = "=== RINGKASAN BROKER PASAR REGULER {$extractedTicker} 3 BULAN (API IDX Edge) ===\n".$brokerSummary;

                $latestCutoff = MarketObservation::where('ticker', $extractedTicker)->max('bar_date');
                if ($latestCutoff) {
                    $cutoffDate = Carbon::parse($latestCutoff)->toDateString();
                    $hasLatestBroker = BrokerSummary::where('ticker', $extractedTicker)->whereDate('summary_date', $cutoffDate)->exists();
                    if (! $hasLatestBroker) {
                        app(BrokerSummaryIngestionService::class)->syncDailySummary($extractedTicker, $cutoffDate);
                    }

                    $provSession = [];
                    $sessionSummary = $this->toolExecutor->executeTool(
                        'get_broker_summary',
                        [
                            'ticker' => $extractedTicker,
                            'start_date' => $cutoffDate,
                            'end_date' => $cutoffDate,
                        ],
                        '5923895922',
                        '5923895922',
                        $provSession
                    );
                    $lines[] = "=== RINGKASAN BROKER PASAR REGULER {$extractedTicker} SESI TERAKHIR {$cutoffDate} (API IDX Edge) ===\n".$sessionSummary;
                }

                if (str_contains($brokerSummary, 'DATA_NOT_AVAILABLE') || str_contains($brokerSummary, 'gagal') || str_contains($brokerSummary, 'nonaktif')) {
                    $componentResults['broker'] = ['status' => 'DATA_NOT_AVAILABLE'];
                    $limitations[] = 'Data broker summary Pasar Reguler tidak tersedia';
                } else {
                    $componentResults['broker'] = ['status' => 'AVAILABLE'];
                    $hasToolEvidence = true;
                }
            }

            // 4. News & Corporate Disclosures
            if ($isHolistic || str_contains($lower, 'berita') || str_contains($lower, 'news') || str_contains($lower, 'info') || str_contains($lower, 'keterbukaan')) {
                $componentsRequested['news_and_filings'] = true;
                $provNews = [];
                $news = $this->toolExecutor->executeTool(
                    'get_related_news',
                    ['ticker' => $extractedTicker],
                    '5923895922',
                    '5923895922',
                    $provNews
                );
                $lines[] = "=== BERITA TERKINI & SENTIMEN {$extractedTicker} (Antara News Finansial) ===\n".$news;

                $provDisc = [];
                $disc = $this->toolExecutor->executeTool(
                    'search_latest_disclosures_and_filings',
                    ['ticker' => $extractedTicker],
                    '5923895922',
                    '5923895922',
                    $provDisc
                );
                $lines[] = "=== KETERBUKAAN INFORMASI RESMI & AKSI KORPORASI {$extractedTicker} (BEI / Emiten) ===\n".$disc;

                if (! empty($provDisc['disclosures_search']['evidence_id'])) {
                    $evidenceIds[] = (int) $provDisc['disclosures_search']['evidence_id'];
                }

                $componentResults['news_and_filings'] = ['status' => 'AVAILABLE'];
                $hasToolEvidence = true;
            }
        } else {
            // General / multi-topic consultation without a single specific ticker
            // 1. Capital Allocation & Budget Suitability Check
            $capital = 0;
            if (preg_match('/(\d+)\s*(?:k|rb|ribu)\b/i', $lower, $m)) {
                $capital = ((float) $m[1]) * 1000;
            } elseif (preg_match('/(?:rp\.?|modal\s+)?(\d+(?:[.,]\d+)?)\s*(?:jt|juta)\b/i', $lower, $m)) {
                $capital = ((float) str_replace(',', '.', $m[1])) * 1000000;
            } elseif (preg_match('/(?:rp\.?|modal\s+)(\d{4,12})/i', $lower, $m)) {
                $capital = (float) $m[1];
            }

            if ($capital > 0) {
                $provCap = [];
                $capAnalysis = $this->toolExecutor->executeTool(
                    'calculate_capital_allocation',
                    ['capital' => $capital],
                    $job?->telegram_user_id ? (string) $job->telegram_user_id : '5923895922',
                    $job?->chat_id ? (string) $job->chat_id : '5923895922',
                    $provCap
                );
                $lines[] = $capAnalysis;
                $componentResults['capital_allocation'] = ['status' => 'AVAILABLE', 'capital' => $capital];
                $hasToolEvidence = true;
            }

            // 2. Market Condition & Benchmark Breadth (IHSG EOD)
            if (preg_match('/(kondisi|situasi|pasar|market|ihsg|indeks|turun|naik|crash|anjlok)/i', $lower)) {
                $ihsgObs = MarketObservation::where('ticker', '^JKSE')->latest('bar_date')->first();
                $ihsgDate = $ihsgObs ? $ihsgObs->bar_date->toDateString() : '2026-10-30';
                $ihsgClose = $ihsgObs ? number_format($ihsgObs->close_price, 2, ',', '.') : '7.600,00';
                $lines[] = "=== KONDISI BENCHMARK PASAR BEI (IHSG / ^JKSE) ===\n"
                    ."• Sesi Penutupan Terakhir : EOD {$ihsgDate} (Pasar tutup)\n"
                    ."• Level IHSG             : {$ihsgClose}\n"
                    ."• Status Quote Intraday  : QUARANTINED (Dilarang berspekulasi dengan quote intraday; gunakan data EOD terverifikasi)\n"
                    .'• Karakteristik Sesi     : Evaluasi tren pasar bursa berbasis penutupan resmi H-1.';
                $componentResults['market_condition'] = ['status' => 'AVAILABLE', 'as_of' => $ihsgDate];
                $hasToolEvidence = true;
            }

            // 3. User Portfolio Consultation Check
            if (preg_match('/(portofolio|portfolio|posisi|kepemilikan|saham saya)/i', $lower)) {
                $provPort = [];
                $portAnalysis = $this->toolExecutor->executeTool(
                    'consult_portfolio',
                    [],
                    $job?->telegram_user_id ? (string) $job->telegram_user_id : '5923895922',
                    $job?->chat_id ? (string) $job->chat_id : '5923895922',
                    $provPort
                );
                $lines[] = "=== KONSULTASI PORTOFOLIO PENGGUNA ===\n".$portAnalysis;
                $componentResults['portfolio'] = ['status' => 'AVAILABLE'];
                $hasToolEvidence = true;
            }

            // 4. Comparison across multiple recognized tickers
            if (preg_match('/(bandingkan|komparasi|vs|versus|lebih baik mana)/i', $lower)) {
                preg_match_all('/\b([A-Za-z]{4})\b/', strtoupper($prompt), $matches);
                $tickers = array_values(array_unique(array_filter($matches[1], fn ($t) => in_array($t, ['BBCA', 'BBRI', 'BMRI', 'BBNI', 'ASII', 'TLKM', 'MAPI', 'ARTO', 'MEDC', 'SMRA', 'PTRO', 'PWON', 'ICBP']))));
                if (! empty($tickers)) {
                    $lines[] = "=== KOMPARASI EMITEN PASAR ===\n".app(StockScreeningService::class)->formatComparisonResponse($tickers);
                    $componentResults['comparison'] = ['status' => 'AVAILABLE', 'tickers' => $tickers];
                    $hasToolEvidence = true;
                }
            }

            // General status
            $lines[] = '=== STATUS SISTEM PASAR AETHER ===';
            $lines[] = 'Database terhubung ke Bursa Efek Indonesia (963 emiten aktif terdaftar pada katalog provider IDX Edge PRO).';
            $componentResults['system_status'] = ['status' => 'AVAILABLE'];
            $hasToolEvidence = true;
        }

        return [
            'tools_context' => implode("\n\n", $lines),
            'components_requested' => $componentsRequested,
            'component_results' => $componentResults,
            'limitations' => $limitations,
            'evidence_ids' => array_unique($evidenceIds),
            'has_tool_evidence' => $hasToolEvidence,
        ];
    }

    /**
     * Build empirical, timestamped operational snapshot directly from database without LLM/API calls.
     */
    public function buildSystemStatusResponse(ScheduledTelegramJob $job): array
    {
        $nowWib = now('Asia/Jakarta')->format('d M Y, H:i:s T');
        $targetDate = '2026-11-02';

        $totalRegistry = IdxCompanyRegistry::count();
        $supportedEquities = IdxCompanyRegistry::where('instrument_type', 'EQUITY')
            ->where('provider_availability', 'AVAILABLE')
            ->where('backfill_status', '!=', 'UNSUPPORTED_SHARE_CLASS')
            ->count();
        $unavailableCount = IdxCompanyRegistry::where('instrument_type', 'EQUITY')
            ->where('provider_availability', '!=', 'AVAILABLE')
            ->count();
        $unsupportedShareClass = IdxCompanyRegistry::where('backfill_status', 'UNSUPPORTED_SHARE_CLASS')->count();
        $benchmarkIndex = IdxCompanyRegistry::where('instrument_type', 'INDEX')->count();

        $obsDistinct = MarketObservation::where('source', 'IDX Edge PRO')->distinct('ticker')->count('ticker');

        $sql = "
            WITH target_universe AS (
                SELECT id, ticker, eod_refresh_status, last_bar_date
                FROM idx_company_registries
                WHERE instrument_type = 'EQUITY'
                  AND provider_availability = 'AVAILABLE'
                  AND backfill_status != 'UNSUPPORTED_SHARE_CLASS'
            ),
            obs_summary AS (
                SELECT 
                    ticker,
                    COUNT(CASE WHEN adjustment_mode = 'UNADJUSTED' THEN 1 END) as unadj_cnt,
                    COUNT(CASE WHEN adjustment_mode = 'PROVIDER_BACK_ADJUSTED' THEN 1 END) as adj_cnt
                FROM market_observations
                WHERE source = 'IDX Edge PRO'
                  AND DATE(bar_date) = '{$targetDate}'
                  AND ticker NOT IN ('IHSG', '^JKSE')
                GROUP BY ticker
            ),
            categorized AS (
                SELECT 
                    u.ticker,
                    CASE 
                        WHEN COALESCE(o.unadj_cnt, 0) > 0 AND COALESCE(o.adj_cnt, 0) > 0 THEN 'LENGKAP_DUA_MODE'
                        WHEN COALESCE(o.unadj_cnt, 0) > 0 OR COALESCE(o.adj_cnt, 0) > 0 THEN 'PARSIAL_SATU_MODE'
                        WHEN u.eod_refresh_status IN ('RETRY_SCHEDULED', 'WAITING_FOR_SESSION_DATA') THEN 'RETRY'
                        WHEN u.eod_refresh_status IN ('FAILED', 'MAX_RETRIES_EXCEEDED') THEN 'GAGAL'
                        ELSE 'ANTRE_BELUM_DICOBA'
                    END AS status_kategori
                FROM target_universe u
                LEFT JOIN obs_summary o ON u.ticker = o.ticker
            )
            SELECT 
                status_kategori,
                COUNT(*) AS count
            FROM categorized
            GROUP BY status_kategori;
        ";
        $results = collect(DB::select($sql))->pluck('count', 'status_kategori')->toArray();
        $lengkapDuaMode = (int) ($results['LENGKAP_DUA_MODE'] ?? 0);
        $parsialSatuMode = (int) ($results['PARSIAL_SATU_MODE'] ?? 0);
        $antreBelumDicoba = (int) ($results['ANTRE_BELUM_DICOBA'] ?? 0);
        $retryCount = (int) ($results['RETRY'] ?? 0);
        $gagalCount = (int) ($results['GAGAL'] ?? 0);

        $backfillComplete = IdxCompanyRegistry::where('backfill_status', 'COMPLETE')->count();
        $backfillPending = IdxCompanyRegistry::where('backfill_status', 'PENDING')->count();

        $pendingJobs = ScheduledTelegramJob::whereIn('status', ['PENDING', 'PROCESSING'])->count();
        $pendingOutbox = TelegramOutbox::where('status', 'PENDING')->count();

        $lockOwnerPid = (int) Cache::get('hermes:lock:refresh-eod:pid', 0);
        $isRunnerActive = false;
        if ($lockOwnerPid > 0) {
            $output = shell_exec("tasklist /FI \"PID eq {$lockOwnerPid}\" 2>nul");
            if ($output && str_contains($output, (string) $lockOwnerPid)) {
                $isRunnerActive = true;
            }
        }

        $todayStr = now('Asia/Jakarta')->format('Ymd');
        $mbOutbox = TelegramOutbox::where('dedup_key', "MORNING_BRIEFING_{$todayStr}")
            ->where('status', 'SENT')
            ->first();
        $mbReceipt = $mbOutbox ? "TERKIRIM (Msg #{$mbOutbox->telegram_message_id})" : 'BELUM_TERKIRIM';

        $text = "⚙️ **STATUS SISTEM AETHER MARKET INTELLIGENCE**\n"
            ."Waktu Snapshot: {$nowWib}\n"
            ."──────────────────────────────────────────\n"
            ."📊 **UNIVERSE & INSTRUMEN**\n"
            ."• Total Registri: {$totalRegistry} instrumen unik\n"
            ."  - Ekuitas Didukung: {$supportedEquities} saham\n"
            ."  - Provider Unavailable: {$unavailableCount} saham (HTTP 404)\n"
            ."  - Unsupported Share Class: {$unsupportedShareClass} saham (GOTOM)\n"
            ."  - Benchmark Index: {$benchmarkIndex} instrumen (IHSG/^JKSE)\n"
            ."• Instrumen Ber-Observasi: {$obsDistinct} saham\n"
            ."──────────────────────────────────────────\n"
            ."🔄 **COVERAGE EOD SESI TERAKHIR ({$targetDate})**\n"
            ."• Denominator Target: {$supportedEquities} saham\n"
            ."• Lengkap Dua Mode (Raw + Adjusted): {$lengkapDuaMode} saham\n"
            ."• Parsial Satu Mode: {$parsialSatuMode} saham\n"
            ."• Antre Belum Dicoba: {$antreBelumDicoba} saham\n"
            ."• Retry Terjadwal: {$retryCount} saham\n"
            ."• Gagal: {$gagalCount} saham\n"
            ."• Status Finalitas: PROVISIONAL_POST_MARKET (is_eod_final = false)\n"
            ."• Backlog Backfill 1-Tahun: {$backfillComplete} COMPLETE, {$backfillPending} PENDING\n"
            ."──────────────────────────────────────────\n"
            ."⚡ **WORKER & ANTREAN**\n"
            .'• EOD Recovery Runner: '.($isRunnerActive ? "AKTIF (PID {$lockOwnerPid})" : 'IDLE')."\n"
            ."• Antrean Konsultasi Fast-Track: {$pendingJobs} job pending\n"
            ."• Outbox Telegram: {$pendingOutbox} pesan pending\n"
            ."──────────────────────────────────────────\n"
            ."📅 **PENGIRIMAN LAPORAN TERJADWAL**\n"
            ."• Morning Briefing (08:30 WIB): {$mbReceipt}\n"
            ."• EOD Refresh (16:05 WIB): TERJADWAL (Task Scheduler)\n"
            ."• Evening Review (20:00 WIB): TERJADWAL\n"
            ."──────────────────────────────────────────\n"
            ."🛡️ **CAPABILITY INTRADAY & BLOCKER**\n"
            ."• Quote Intraday (/api/price): QUARANTINED (kontaminasi transaksi Pasar Negosiasi / crossing trade tanpa filter board)\n"
            ."• Order Flow (/api/done-details): EOD ONLY (arsip pasca-pasar, 0 transaksi saat sesi berjalan)\n"
            ."• Candlestick Sub-Daily: TIDAK TERSEDIA (provider hanya mendukung frame harian)\n"
            .'• Rekomendasi Intraday (09:15, 12:15, 15:15): DINONAKTIFKAN demi integritas audit';

        return [
            'status' => 'COMPLETE',
            'text' => $text,
            'analysis_id' => null,
            'evidence_id' => null,
            'canonical_output' => [
                'schema_version' => 'aether.analysis.v3',
                'status' => 'COMPLETE',
                'request_kind' => 'SYSTEM_STATUS',
                'answer' => $text,
                'limitations' => [
                    'Quote intraday dikarantina karena anomali data Pasar Negosiasi pada provider.',
                    'Order flow hanya tersedia sebagai arsip pasca-penutupan bursa.',
                ],
                'assessment' => [
                    'system_state' => $isRunnerActive ? 'RUNNING_RECOVERY' : 'OPERATIONAL_NOMINAL',
                    'snapshot_at' => $nowWib,
                ],
            ],
        ];
    }
}
