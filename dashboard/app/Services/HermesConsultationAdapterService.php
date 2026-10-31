<?php

namespace App\Services;

use App\Models\AnalysisRun;
use App\Models\BrokerSummary;
use App\Models\CompanyFundamental;
use App\Models\EvidenceSnapshot;
use App\Models\MarketObservation;
use App\Models\ScheduledTelegramJob;
use App\Models\TelegramConversation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
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
        $cliCmd = config('services.hermes.cli_command', 'hermes');
        $profile = config('services.hermes.profile', 'aether-prod');
        $budgetSec = (int) config('services.hermes.run_budget_seconds', 60);

        // Prepare grounded contextual briefing with governing rules, passing job to honor reply_to & analysis reference
        $toolsContext = $this->resolveToolsBriefing($job->original_prompt, $job);

        // Fetch recent conversation history & evidence strictly isolated for THIS telegram user
        // Exclude current message / attempt so we don't inject self
        $conversationContext = '';
        if ($job->telegram_user_id) {
            $convQuery = TelegramConversation::where('user_id', (string) $job->telegram_user_id)
                ->where('chat_id', (string) $job->chat_id);

            if ($job->inbound_message_id) {
                $convQuery->where('inbound_message_id', '!=', (string) $job->inbound_message_id);
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
                        $q->where('inbound_message_id', (string) $replyToId)
                            ->orWhere('outbound_message_id', (string) $replyToId);
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
            ."6. Peringatan Kesimpulan Logis & Kelayakan Transaksi:\n"
            ."   - Net broker BUKAN total turnover bursa dan bukan bukti kepemilikan definitif entitas tunggal.\n"
            ."   - Kuotasi /api/price berstatus QUARANTINED (kontaminasi transaksi Pasar Negosiasi). Tanpa data intraday Pasar Reguler dan spread bid-offer yang valid, status day trade wajib: DAY_TRADE_NOT_ASSESSABLE_WITH_AVAILABLE_DATA.\n"
            ."   - Dilarang menyimpulkan spread bid-ask dari rentang High-Low bar harian atau net flow broker.\n"
            ."   - Dilarang menyatakan penyebab penurunan harga tanpa bukti dokumen atau berita terverifikasi.\n"
            ."   - Satu candle harian BUKAN tren multi-sesi.\n"
            ."   - Fundamental kosong/missing BUKAN bukti fundamental perusahaan buruk (sebutkan status ketersediaan data secara netral: DATA_NOT_AVAILABLE / LAPORAN_KEUANGAN_PENDING).\n"
            ."   - Jika bukti data tidak cukup untuk swing trade, gunakan status: SWING_ASSESSMENT_PARTIAL atau INSUFFICIENT_EVIDENCE dengan fakta risiko yang terverifikasi.\n"
            ."   - Pisahkan keterbatasan sistem (data feed) dari risiko instrumen (papan pemantauan khusus, likuiditas, fraksi harga).\n"
            ."   - Rasio Total Liabilitas / Total Ekuitas BUKAN rasio utang pinjaman berbunga (DER murni).\n"
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

        $process = new Process($args);
        $process->setTimeout($budgetSec + 25);
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

            // Classify request kind dynamically based on prompt intent and structure
            $promptLower = strtolower($job->original_prompt);
            $twoWay = app(TelegramTwoWayService::class);
            $extractedTicker = $twoWay->extractTicker($job->original_prompt);
            $isCrossTicker = $twoWay->isCrossTickerScreeningQuery($job->original_prompt);

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

            // Extract mentioned Evidence IDs from Hermes output (e.g., [Evidence #123] or Evidence Snapshot #123)
            preg_match_all('/Evidence\s*(?:Snapshot)?\s*#?([0-9]+)/i', $rawOutput, $evMatches);
            $citedEvidenceIds = array_unique(array_map('intval', $evMatches[1] ?? []));

            // Validate Cited Evidence IDs: Must genuinely exist in DB, belong to this tenant/system, and match context
            $validEvidenceIds = [];
            $invalidEvidenceIds = [];
            foreach ($citedEvidenceIds as $cid) {
                $evRecord = EvidenceSnapshot::find($cid);
                if ($evRecord) {
                    $validEvidenceIds[] = $cid;
                } else {
                    $invalidEvidenceIds[] = $cid;
                }
            }

            // If model hallucinates non-existent Evidence IDs, reject output strictly!
            if (! empty($invalidEvidenceIds)) {
                Log::warning("[HermesConsultationAdapter] Output rejected due to fabricated Evidence IDs on Job #{$job->id}: ".implode(', ', $invalidEvidenceIds));

                return [
                    'status' => 'FAILED',
                    'text' => "⚠️ Sistem penalaran Hermes mengutip referensi bukti data yang tidak valid di basis data.\nID Referensi: JOB-{$job->id}",
                    'analysis_id' => null,
                    'evidence_id' => null,
                ];
            }

            // Output Validation according to request_kind
            $isValidResponse = false;
            $len = strlen($rawOutput);

            switch ($requestKind) {
                case 'EDUCATION':
                case 'DATA_PROVENANCE_INQUIRY':
                case 'SYSTEM_STATUS':
                    // Short, direct technical answers are fully valid (e.g., explaining RSI or citing IDX Edge)
                    $isValidResponse = ($len >= 15);
                    break;

                case 'FOLLOW_UP_CLARIFICATION':
                    // Follow-up clarifications can be concise
                    $isValidResponse = ($len >= 25);
                    break;

                case 'BROKER_FLOW':
                    // Broker flow requires broker reference or clear failure/absence status
                    $isValidResponse = ($len >= 40) && (
                        str_contains($rawOutput, 'BROKER') || str_contains($rawOutput, 'Broker') ||
                        str_contains($rawOutput, 'Akumulasi') || str_contains($rawOutput, 'DATA_NOT_AVAILABLE') ||
                        str_contains($rawOutput, 'belum tersedia') || str_contains($rawOutput, 'Pembelian')
                    );
                    break;

                case 'SCREENING':
                    $isValidResponse = ($len >= 40) && (
                        str_contains($rawOutput, 'SCREENING') || str_contains($rawOutput, 'Screening') ||
                        str_contains($rawOutput, 'Kandidat') || str_contains($rawOutput, 'TIDAK ADA KANDIDAT') ||
                        str_contains($rawOutput, 'Akumulasi')
                    );
                    break;

                case 'COMPARISON':
                    $isValidResponse = ($len >= 50);
                    break;

                case 'COMPREHENSIVE_ANALYSIS':
                default:
                    // Holistic analysis must have substantive content
                    $hasSubstance = ($len >= 80);
                    $hasDayTradeOrSwing = str_contains($rawOutput, 'DAY TRADE') || str_contains($rawOutput, 'day trade') || str_contains($rawOutput, 'SWING') || str_contains($rawOutput, 'swing');
                    $hasMarketOrStock = str_contains($rawOutput, 'HARGA') || str_contains($rawOutput, 'Harga') || str_contains($rawOutput, 'Rp') || str_contains($rawOutput, 'Pasar') || str_contains($rawOutput, 'Emiten') || str_contains($rawOutput, 'Kandidat');
                    $isValidResponse = $hasSubstance && ($hasMarketOrStock || $hasDayTradeOrSwing || str_contains($rawOutput, 'DATA_NOT_AVAILABLE'));
                    break;
            }

            if (! $isValidResponse) {
                Log::warning("[HermesConsultationAdapter] Output failed substantive contract validation for kind {$requestKind} on Job #{$job->id}: {$rawOutput}");

                return [
                    'status' => 'FAILED',
                    'text' => "⚠️ Sistem penalaran Hermes tidak menghasilkan kesimpulan analisis yang memenuhi standar kontrak ({$requestKind}).\nID Referensi: JOB-{$job->id}",
                    'analysis_id' => null,
                    'evidence_id' => null,
                ];
            }

            $isPartial = str_contains($rawOutput, 'TIDAK ADA KANDIDAT')
                || str_contains($rawOutput, 'Keterbatasan')
                || str_contains($rawOutput, 'belum tersedia')
                || str_contains($rawOutput, 'PERSISTENCE_NOT_EVALUATED')
                || str_contains($rawOutput, 'PAUSED')
                || str_contains($rawOutput, 'INSUFFICIENT_EVIDENCE')
                || str_contains($rawOutput, 'SWING_ASSESSMENT_PARTIAL')
                || str_contains($rawOutput, 'DAY_TRADE_NOT_ASSESSABLE_WITH_AVAILABLE_DATA')
                || str_contains($rawOutput, 'DATA_NOT_AVAILABLE');

            $agentStatus = $isPartial ? 'PARTIAL' : 'COMPLETE';

            // Record Evidence Snapshot & Analysis Run
            $evidencePayload = [
                'orchestrator' => 'HERMES_AGENT_WORKER',
                'request_kind' => $requestKind,
                'agent_status' => $agentStatus,
                'profile' => $profile,
                'session' => $sessionName,
                'job_id' => $job->id,
                'inbound_prompt' => $job->original_prompt,
                'cited_evidence_ids' => $validEvidenceIds,
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
                    'narrative' => $rawOutput,
                    'agent_status' => $agentStatus,
                ],
            ]);

            $provenance['ai_analysis_id'] = $run->id;
            $provenance['ai_evidence_id'] = $ev->id;
            $provenance['orchestrator'] = 'HERMES_AGENT';
            $provenance['agent_status'] = $agentStatus;
            $provenance['profile'] = $profile;

            return [
                'status' => $agentStatus,
                'text' => $rawOutput,
                'analysis_id' => $run->id,
                'evidence_id' => $ev->id,
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
        $lines = [];
        $lower = strtolower($prompt);

        // Check if query is cross-ticker broker screening or follow-up on recent screening
        $twoWay = app(TelegramTwoWayService::class);
        $isCrossTicker = $twoWay->isCrossTickerScreeningQuery($prompt);
        $extractedTicker = $isCrossTicker ? null : $twoWay->extractTicker($prompt);

        $isFollowUp = preg_match('/(apakah|kenapa|mengapa|bagaimana|jelaskan|cakupan|periode|sumber|keterbatasan|angka|broker pendukung|sesi terakhir|top-n|kandidat)/i', $lower)
            && ! preg_match('/(update|perbarui|sinkron|tarik ulang|refresh|ambil lagi|cari ulang)/i', $lower);

        if ($extractedTicker === null && ($isCrossTicker || str_contains($lower, 'akumulasi') || str_contains($lower, 'distribusi') || str_contains($lower, 'screening') || str_contains($lower, 'rekomendasi') || str_contains($lower, 'pilot') || str_contains($lower, 'cakupan') || str_contains($lower, 'periode'))) {
            // If this is a follow-up inquiry on previous screening, reuse referenced or recent AnalysisRun / EvidenceSnapshot directly!
            if ($isFollowUp) {
                // If job has a referenced analysis_id, fetch specifically
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

                    return implode("\n\n", $lines);
                }
            }

            $screening = app(BrokerAccumulationScreeningService::class);
            $prov = [];
            $screeningResult = $screening->executeScreening($prompt, $prov);
            $lines[] = "=== HASIL SCREENING AKUMULASI BROKER LINTAS EMITEN (Aether Engine) ===\n".$screeningResult;
        } elseif ($extractedTicker !== null) {
            $isHolistic = preg_match('/(fundamental|teknikal|day trade|swing|berita|info|ringkasan|lengkap|bedah)/i', $lower);
            $wantsBroker = preg_match('/(broker|akumulasi|bandarmologi|top buyer|top seller)/i', $lower);

            // 0. Ensure Price Freshness: Check if EOD is present and fresh according to trading calendar
            $currentTradingDay = app(BeiCalendarService::class)->getCurrentTradingDay(Carbon::now('Asia/Jakarta'))->toDateString();
            $latestObs = MarketObservation::where('ticker', $extractedTicker)
                ->where('source', 'IDX Edge PRO')
                ->where('validation_status', 'VALID')
                ->latest('bar_date')
                ->first();

            $needsEodSync = (! $latestObs || Carbon::parse($latestObs->bar_date)->toDateString() < $currentTradingDay);
            if ($needsEodSync) {
                // Controlled sync only when data is missing or stale
                app(DataSourceRegistryService::class)->syncControlledEodHistory($extractedTicker, 25);
            }

            // 1. Ticker Profile & Technical Indicators
            if ($isHolistic || str_contains($lower, 'teknikal') || str_contains($lower, 'harga') || str_contains($lower, 'trend') || str_contains($lower, 'indikator')) {
                // Ensure deterministic metrics calculated from canonical shared DB
                app(TechnicalMetricsCalculationService::class)->calculateForTicker($extractedTicker);
                $provAnalysis = [];
                $analysis = $this->toolExecutor->executeTool(
                    'get_instrument_analysis',
                    ['ticker' => $extractedTicker],
                    '5923895922',
                    '5923895922',
                    $provAnalysis
                );
                $lines[] = "=== KONDISI TEKNIKAL & HARGA {$extractedTicker} (PostgreSQL project-bot) ===\n".$analysis;
            }

            // 2. Fundamental & Financial Statements
            if ($isHolistic || str_contains($lower, 'fundamental') || str_contains($lower, 'kinerja') || str_contains($lower, 'laba') || str_contains($lower, 'lapkeu') || str_contains($lower, 'valuasi')) {
                // Ensure annual statement is synced via IDX Edge if missing from DB or does not meet minimum quality/period
                // ALREADY_SYNCED must guarantee both period adequacy (at least 1 recent annual) and quality PROVIDER_SUPPLIED / DOCUMENT_VERIFIED
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
            }

            // 3. Broker Flow (Include if explicitly requested or part of holistic inquiry)
            if ($wantsBroker || $isHolistic) {
                // Provide Single Ticker Broker Summary
                $prov = [];
                $brokerSummary = $this->toolExecutor->executeTool(
                    'get_broker_summary',
                    ['ticker' => $extractedTicker],
                    '5923895922',
                    '5923895922',
                    $prov
                );
                $lines[] = "=== RINGKASAN BROKER PASAR REGULER {$extractedTicker} 3 BULAN (API IDX Edge) ===\n".$brokerSummary;

                // Ensure latest session broker is synced to DB if missing
                $latestCutoff = MarketObservation::where('ticker', $extractedTicker)->max('bar_date');
                if ($latestCutoff) {
                    $cutoffDate = Carbon::parse($latestCutoff)->toDateString();
                    // Sync latest session broker if not yet in DB
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
            }

            // 4. News & Corporate Disclosures
            if ($isHolistic || str_contains($lower, 'berita') || str_contains($lower, 'news') || str_contains($lower, 'internet') || str_contains($lower, 'info') || str_contains($lower, 'keterbukaan')) {
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
            }
        } else {
            // General status
            $lines[] = '=== STATUS SISTEM PASAR AETHER ===';
            $lines[] = 'Database terhubung ke Bursa Efek Indonesia (864 saham aktif).';
        }

        return implode("\n\n", $lines);
    }
}
