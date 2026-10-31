<?php

namespace App\Services;

use App\Models\AnalysisRun;
use App\Models\EvidenceSnapshot;
use App\Models\TelegramConversation;
use Illuminate\Support\Facades\Log;

class AiConversationService
{
    protected string $endpoint;

    protected string $modelId;

    protected string $apiKey;

    protected int $timeout;

    protected bool $onDemandEnabled;

    public function __construct()
    {
        $this->endpoint = (string) config('services.ai_router.endpoint', 'http://127.0.0.1:20128/v1');
        $this->modelId = (string) config('services.ai_router.model_id', 'ag/gemini-3.8-flash-high');
        $this->apiKey = (string) config('services.ai_router.api_key', '');
        $this->timeout = (int) config('services.ai_router.timeout', 20);
        $this->onDemandEnabled = (bool) config('services.ai_router.on_demand_enabled', true);
    }

    /**
     * Handle conversational / free-text / follow-up chat with multi-turn context.
     */
    public function handleConversation(
        string $inboundText,
        string $chatId,
        string $userId,
        ?string $contextSignalId,
        array &$provenance
    ): string {
        $startTime = microtime(true);

        // Check if on-demand AI is enabled
        if (! $this->onDemandEnabled) {
            $provenance['ai_status'] = 'DISABLED_BY_FLAG';

            return "Halo. Layanan AI percakapan interaktif saat ini sedang dinonaktifkan oleh konfigurasi operasional.\n"
                .'Anda tetap dapat menggunakan perintah deterministik seperti /status, /recap, atau meminta analisis emiten (contoh: "analisis emiten SMRA").';
        }

        // 1. Fetch recent conversation history for this chat (last 4 turns)
        $historyTurns = TelegramConversation::where('chat_id', $chatId)
            ->whereNotNull('outbound_reply_text')
            ->where('outbound_reply_text', '!=', '')
            ->latest('id')
            ->take(4)
            ->get()
            ->reverse();

        // 2. Identify any recently discussed ticker in history
        $recentTicker = null;
        foreach ($historyTurns as $turn) {
            if ($turn->inbound_text && preg_match('/\b(BBCA|BBRI|BMRI|BBNI|ASII|TLKM|MAPI|ARTO|MEDC|SMRA|ICBP|UNVR|GOTO|ADRO|KLBF)\b/i', $turn->inbound_text, $m)) {
                $recentTicker = strtoupper($m[1]);
            }
        }

        // 3. Build System Prompt & Instructions
        $systemPrompt = "Kamu adalah asisten intelijen pasar modal Aether Market Intelligence (BEI/IDX).\n"
            ."Tugas: Melayani seluruh kebutuhan analisis dan percakapan seputar saham Bursa Efek Indonesia (BEI) dalam bahasa Indonesia yang alami, ringkas, terstruktur, dan profesional.\n\n"
            ."Ruang Lingkup Layanan:\n"
            ."1. Pembelajaran dan edukasi saham: Jelaskan konsep pasar modal, rasio valuasi (PER, PBV), profitabilitas (ROE), dividen, serta cara membandingkan saham secara mendalam dan mudah dipahami.\n"
            ."2. Analisis teknikal & fundamental: Gunakan data harga bar EOD, indikator teknikal tersimpan, dan berita Antara News.\n"
            ."3. Konsultasi analitis portofolio: Bantu pengguna mengevaluasi kepemilikan saham, menghitung bobot posisi, floating PnL, alokasi target awal 50% trading / 50% investasi, dan risiko konsentrasi. Jika kepemilikan belum dicatat, tanyakan ticker, lot, dan harga beli rata-rata.\n"
            ."4. Screening & rekomendasi: Gunakan kriteria kuantitatif terstruktur (likuiditas, rentang bar, stabilitas). Rekomendasi analitis dibedakan dari sinyal ML formal.\n"
            ."5. Prediksi ML & Sinyal: Model ML Aether berstatus default ABSTAIN (NO_SIGNAL) jika keyakinan < 40% (Tau 0.70) untuk melindungi modal. Model ML 5 hari tidak berlaku untuk keputusan investasi jangka panjang.\n"
            .'6. Konteks percakapan: Gunakan konteks emiten yang baru dibahas ('.($recentTicker ?: 'belum ada emiten spesifik').") untuk pertanyaan lanjutan seperti 'berita apa yang mendukung?', 'kenapa abstain?', atau 'bagaimana risikonya?'.\n\n"
            ."Batasan dan Larangan Keras:\n"
            ."1. TOLAK SINGKAT pertanyaan di luar saham dan operasional Aether (misal: resep masakan, cuaca, hiburan, coding umum). Jawab singkat: 'Aether berfokus khusus pada analisis saham Bursa Efek Indonesia (BEI) dan intelijen pasar modal. Ada yang bisa dibantu terkait saham BEI?'\n"
            ."2. Dilarang mengarang harga, probabilitas, atau kepemilikan portofolio fiktif. Dilarang menjanjikan keuntungan pasti.\n"
            ."3. Eksekusi broker dan order pasar TIDAK TERSEDIA (Aether murni sistem intelijen dan analisis, bukan platform trading/brokerage).\n"
            ."4. Format teks bersih: jangan gunakan format markdown yang berantakan atau karakter asterisk '*' yang tidak perlu.\n"
            ."5. ATRIBUSI SUMBER & GROUNDING ANALISIS BUKTI:\n"
            ."   - Bedakan fakta laporan, perhitungan deterministik, risiko keuangan, dan penyebab harga bursa.\n"
            ."   - Tanggal bar harga pasar wajib disebutkan (contoh: 27 Oktober 2026 Rp 2.870) dan persentase perubahan berbasis prev close.\n"
            ."   - Margin Laba: Pendapatan naik (+59,56%) dan laba bruto turun (-2,63%) menyimpulkan gross margin menyusut dari 14,17% ke 8,64% (-5,53 poin persentase). Jangan mengarang penyebab lemahnya pricing power atau komponen biaya tanpa bukti catatan.\n"
            ."   - Operasi Dihentikan: Catatan 40 menunjukkan hasil operasi anak perusahaan yang dilepas (PTKMS) sebesar US$ 7,62M. Divestasi saham 30 Juni 2026 menghasilkan rugi pelepasan US$ 5,80M di beban lain-lain. Laba periode berjalan US$ 9,17M terdiri dari operasi dilanjutkan US$ 1,55M dan operasi dihentikan US$ 7,62M. Laba pemilik induk US$ 10,79M (nonpengendali rugi US$ -1,62M). Jangan menyebutnya 'laba semu' dan jangan mengurangkan angka yang beda basis atribusi.\n"
            ."   - Struktur Modal: Rasio total liabilitas / total ekuitas 3,75x (Bukan utang berbunga/ekuitas). Rasio total liabilitas / ekuitas induk 4,44x.\n"
            ."   - Jangan mengasumsikan penurunan harga pasar ke Rp 2.870 otomatis disebabkan oleh beban liabilitas/bunga tanpa bukti order-flow atau berita transaksi bursa.\n"
            .'   - Keterbatasan Valuasi: Jangan mengarang angka PER/PBV atau kurs IDR/USD. Pembukuan USD vs transaksi saham IDR memerlukan kurs settlement resmi yang belum terverifikasi.';

        // 4. Construct messages payload
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        foreach ($historyTurns as $turn) {
            $messages[] = [
                'role' => 'user',
                'content' => $turn->inbound_text,
            ];
            // Truncate previous assistant response to 300 chars to conserve context tokens
            $assistantShort = mb_substr($turn->outbound_reply_text, 0, 300);
            $messages[] = [
                'role' => 'assistant',
                'content' => $assistantShort,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $inboundText,
        ];

        // 5. Invoke 9router with structured application tools
        $toolExecutor = app(AiToolExecutorService::class);
        $tools = $toolExecutor->getToolSchemas();

        $payload = [
            'model' => $this->modelId,
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
            'max_tokens' => 500,
            'temperature' => 0.3,
            'stream' => false,
        ];

        try {
            $resp = $this->curlRequestWithRetry($payload, $this->timeout, 2);
            $httpCode = $resp['http_code'];
            $rawResponse = $resp['raw'];
            $curlError = $resp['error'];

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($httpCode === 200 && $rawResponse) {
                $data = $this->parseResponseData($rawResponse);
                $choice = $data['choices'][0]['message'] ?? [];

                // Check if Gemini selected an application tool
                if (! empty($choice['tool_calls'])) {
                    $toolCall = $choice['tool_calls'][0];
                    $toolCallId = $toolCall['id'] ?? ('call_'.uniqid());
                    $toolName = $toolCall['function']['name'] ?? '';
                    $toolArgs = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];

                    // Contextual fallback: if ticker is omitted in tool args, inject recentTicker
                    if (in_array($toolName, ['add_watchlist', 'get_instrument_analysis', 'get_related_news']) && empty($toolArgs['ticker']) && $recentTicker) {
                        $toolArgs['ticker'] = $recentTicker;
                    }

                    $provenance['ai_status'] = 'LIVE_TOOL_CALL_EXECUTED';
                    $provenance['model_used'] = $this->modelId;
                    $provenance['tool_name'] = $toolName;
                    $provenance['tool_args'] = $toolArgs;
                    $provenance['latency_ms'] = $durationMs;
                    $provenance['tokens_used'] = $data['usage']['total_tokens'] ?? null;
                    $provenance['recent_context_ticker'] = $recentTicker;

                    $toolOutput = $toolExecutor->executeTool($toolName, $toolArgs, $userId, $chatId, $provenance);

                    // For analytical follow-ups, synthesize tool output into natural Indonesian
                    if (in_array($toolName, ['get_related_news', 'screen_stocks', 'compare_stocks', 'get_instrument_analysis', 'calculate_capital_allocation', 'search_latest_disclosures_and_filings', 'get_broker_summary', 'get_broker_accumulation', 'get_company_fundamentals'])) {
                        // Calculate remaining time against total budget (default 45s)
                        $elapsedTurn1 = (int) round((microtime(true) - $startTime) * 1000);
                        $remainingSec = $this->timeout - (int) ceil($elapsedTurn1 / 1000);
                        if ($remainingSec <= 0) {
                            Log::warning('[AiConversationService] Absolute deadline budget exhausted before synthesis. Returning tool output directly.');

                            return $toolOutput;
                        }

                        $synthesis = $this->synthesizeToolOutput($messages, $choice, $toolCallId, $toolOutput, $remainingSec);
                        if ($synthesis) {
                            $finalText = $synthesis;
                        } else {
                            $finalText = $toolOutput;
                        }
                    } else {
                        $finalText = $toolOutput;
                    }

                    // Snapshot evidence and record analysis run for full audit traceability
                    if (is_array($provenance)) {
                        try {
                            $evidenceHash = hash('sha256', $toolOutput);
                            $ev = EvidenceSnapshot::create([
                                'ticker' => $recentTicker ?: 'MARKET',
                                'snapshot_type' => 'TOOL_OUTPUT_'.$toolName,
                                'data_hash' => $evidenceHash,
                                'raw_payload' => ['tool' => $toolName, 'output' => $toolOutput],
                                'captured_at' => now(),
                            ]);

                            $run = AnalysisRun::create([
                                'run_id' => 'CONSULT_'.$toolName.'_'.now()->format('Ymd_His').'_'.substr(uniqid(), -4),
                                'trigger_type' => 'TELEGRAM_CONSULTATION',
                                'status' => 'completed',
                                'started_at' => now()->subMilliseconds((int) round((microtime(true) - $startTime) * 1000)),
                                'completed_at' => now(),
                                'metadata' => [
                                    'ticker' => $recentTicker ?: 'MARKET',
                                    'run_type' => 'ASYNC_AI_CONSULTATION',
                                    'model_used' => $this->modelId,
                                    'narrative' => $finalText,
                                ],
                            ]);

                            $provenance['ai_analysis_id'] = $run->id;
                            $provenance['ai_evidence_id'] = $ev->id;
                        } catch (\Throwable $e) {
                            Log::warning('[AiConversationService] Failed to record AnalysisRun/Evidence: '.$e->getMessage());
                        }
                    }

                    return $finalText;
                }

                $content = $choice['content'] ?? null;

                if ($content && trim($content) !== '') {
                    // Sanitize markdown artifacts for clean plain text display
                    $cleanContent = $this->sanitizeResponse(trim($content));

                    $provenance['ai_status'] = 'LIVE_COMPLETION_SUCCESS';
                    $provenance['model_used'] = $this->modelId;
                    $provenance['latency_ms'] = $durationMs;
                    $provenance['tokens_used'] = $data['usage']['total_tokens'] ?? null;
                    $provenance['recent_context_ticker'] = $recentTicker;

                    return $cleanContent;
                }
            }

            Log::warning('[AiConversationService] Failed or empty response from 9router', [
                'http_code' => $httpCode,
                'error' => $curlError,
                'duration_ms' => $durationMs,
            ]);

            $provenance['ai_status'] = 'HTTP_FAILURE_OR_TIMEOUT';
            $provenance['http_code'] = $httpCode;

            return "Halo. Saya dapat menerima pesan Anda, namun server penalaran AI sedang mengalami jeda respons (HTTP {$httpCode}).\n\n"
                .'Silakan ulangi pesan Anda atau gunakan perintah deterministik seperti /status, /recap, atau "analisis emiten <TICKER>".';

        } catch (\Throwable $e) {
            Log::error('[AiConversationService] Exception calling 9router: '.$e->getMessage());
            $provenance['ai_status'] = 'EXCEPTION';
            $provenance['error'] = $e->getMessage();

            return "Halo. Mohon maaf, terjadi kendala saat menghubungkan ke mesin penalaran AI.\n"
                .'Sistem data pasar tetap beroperasi normal. Gunakan /status atau /help untuk bantuan.';
        }
    }

    /**
     * Parse raw response body whether it is standard JSON or SSE chunked format.
     */
    protected function parseResponseData(string $raw): array
    {
        $trimmed = trim($raw);
        if (str_starts_with($trimmed, '{')) {
            return json_decode($trimmed, true) ?: [];
        }

        // Parse SSE chunks (handles delta.content and delta.tool_calls; strictly ignores reasoning_content)
        $content = '';
        $toolCalls = [];
        $usage = null;
        $lines = explode("\n", $trimmed);
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data: ') && $line !== 'data: [DONE]') {
                $jsonStr = substr($line, 6);
                $chunk = json_decode($jsonStr, true);
                if (! is_array($chunk)) {
                    continue;
                }

                if (isset($chunk['usage'])) {
                    $usage = $chunk['usage'];
                }

                $delta = $chunk['choices'][0]['delta'] ?? null;
                if (! is_array($delta)) {
                    continue;
                }

                // Strictly accumulate user-facing content (never reasoning_content)
                if (isset($delta['content']) && is_string($delta['content'])) {
                    $content .= $delta['content'];
                }

                // Handle streaming tool_calls if present
                if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $tc) {
                        $idx = $tc['index'] ?? 0;
                        if (! isset($toolCalls[$idx])) {
                            $toolCalls[$idx] = [
                                'id' => $tc['id'] ?? ('call_'.uniqid()),
                                'type' => 'function',
                                'function' => [
                                    'name' => $tc['function']['name'] ?? '',
                                    'arguments' => $tc['function']['arguments'] ?? '',
                                ],
                            ];
                        } else {
                            if (! empty($tc['id'])) {
                                $toolCalls[$idx]['id'] = $tc['id'];
                            }
                            if (! empty($tc['function']['name'])) {
                                $toolCalls[$idx]['function']['name'] .= $tc['function']['name'];
                            }
                            if (isset($tc['function']['arguments'])) {
                                $toolCalls[$idx]['function']['arguments'] .= $tc['function']['arguments'];
                            }
                        }
                    }
                }
            }
        }

        $message = [
            'role' => 'assistant',
            'content' => $content !== '' ? $content : null,
        ];
        if (! empty($toolCalls)) {
            $message['tool_calls'] = array_values($toolCalls);
        }

        return [
            'choices' => [
                [
                    'message' => $message,
                ],
            ],
            'usage' => $usage,
        ];
    }

    /**
     * Synthesize tool output into a conversational grounded answer via Gemini (turn 2).
     */
    protected function synthesizeToolOutput(array $originalMessages, array $assistantChoice, string $toolCallId, string $toolOutput, int $timeoutSec = 10): ?string
    {
        try {
            $messages = $originalMessages;
            $messages[] = [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => $toolCallId,
                        'type' => 'function',
                        'function' => [
                            'name' => $assistantChoice['tool_calls'][0]['function']['name'] ?? 'tool',
                            'arguments' => $assistantChoice['tool_calls'][0]['function']['arguments'] ?? '{}',
                        ],
                    ],
                ],
            ];
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCallId,
                'content' => mb_substr($toolOutput, 0, 1500),
            ];

            $payload = [
                'model' => $this->modelId,
                'messages' => $messages,
                'max_tokens' => 450,
                'temperature' => 0.2,
                'stream' => false,
            ];

            $resp = $this->curlRequestWithRetry($payload, $timeoutSec, 1);
            $code = $resp['http_code'];
            $raw = $resp['raw'];

            if ($code === 200 && $raw) {
                $data = $this->parseResponseData($raw);
                $text = $data['choices'][0]['message']['content'] ?? null;
                if ($text && trim($text) !== '') {
                    return $this->sanitizeResponse(trim($text));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[AiConversationService] Tool synthesis turn failed: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Resilient cURL request with global deadline and bounded retries for transient failures.
     */
    protected function curlRequestWithRetry(array $payload, int $timeout, int $maxRetries = 1): array
    {
        $url = rtrim($this->endpoint, '/').'/chat/completions';
        $jsonPayload = json_encode($payload);
        $headers = [
            'Content-Type: application/json',
            'Authorization: '.($this->apiKey ? 'Bearer '.$this->apiKey : 'Bearer default'),
        ];

        $attempt = 0;
        $lastHttpCode = 0;
        $lastError = '';
        $rawResponse = null;
        $startTime = microtime(true);

        while ($attempt <= $maxRetries) {
            $attempt++;
            $elapsed = (microtime(true) - $startTime);
            $remaining = $timeout - (int) ceil($elapsed);
            if ($remaining <= 0) {
                // Absolute deadline reached, abort retry
                break;
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, max(1, $remaining));

            $rawResponse = curl_exec($ch);
            $lastHttpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $lastError = curl_error($ch);
            curl_close($ch);

            // Success
            if ($lastHttpCode === 200 && $rawResponse) {
                return [
                    'success' => true,
                    'http_code' => 200,
                    'raw' => $rawResponse,
                    'error' => null,
                ];
            }

            // Client errors (4xx except 429) should NOT be retried
            if ($lastHttpCode >= 400 && $lastHttpCode < 500 && $lastHttpCode !== 429) {
                break;
            }

            // Brief backoff if remaining time allows
            if ($attempt <= $maxRetries) {
                $elapsedNow = (microtime(true) - $startTime);
                if (($timeout - $elapsedNow) > 3) {
                    usleep(300000); // 300ms
                } else {
                    break;
                }
            }
        }

        return [
            'success' => false,
            'http_code' => $lastHttpCode,
            'raw' => $rawResponse,
            'error' => $lastError,
        ];
    }

    /**
     * Clean markdown asterisks and formatting artifacts.
     */
    protected function sanitizeResponse(string $text): string
    {
        // Remove bold/italic markdown asterisks
        $cleaned = str_replace(['**', '*'], '', $text);
        // Normalize multiple blank lines
        $cleaned = preg_replace("/\n{3,}/", "\n\n", $cleaned);

        return trim($cleaned);
    }
}
