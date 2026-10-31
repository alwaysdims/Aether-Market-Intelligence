<?php

namespace App\Services;

use App\Models\AnalysisRun;
use App\Models\MarketAnalysis;
use App\Models\MarketSnapshot;
use App\Models\NewsItem;
use App\Models\NotificationLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class TelegramNotificationService
{
    protected string $chatId;

    protected string $cliCommand;

    public function __construct()
    {
        // Default Telegram chat ID for @Info_BosKuu_bot verified from Hermes config
        $this->chatId = (string) config('services.telegram.chat_id');
        $this->cliCommand = config('services.hermes.cli_command', 'hermes');
    }

    /**
     * Format standardized, objective EOD summary message according to Phase 4 rules.
     */
    public function formatEodMessage(AnalysisRun $run, ?MarketAnalysis $analysis = null): string
    {
        $analysis = $analysis ?? $run->marketAnalysis;
        $nowWib = Carbon::now('Asia/Jakarta')->translatedFormat('d M Y, H:i').' WIB';

        $lines = [];
        $lines[] = '📊 AETHER MARKET INTELLIGENCE — EOD REPORT';
        $lines[] = '══════════════════════════════════════════';
        $lines[] = "Waktu Analisis : {$nowWib}";
        $lines[] = 'Status Pipeline: '.strtoupper($run->status ?? 'UNKNOWN')." (ID: {$run->run_id})";

        // Market Data Finality & Source
        $latestSnapshot = MarketSnapshot::where('analysis_run_id', $run->id)->latest('bar_date')->first()
            ?? MarketSnapshot::latest('bar_date')->first();

        $barDateStr = $latestSnapshot ? $latestSnapshot->bar_date->format('d M Y') : 'Unknown';
        $dataClass = $latestSnapshot ? strtoupper($latestSnapshot->data_classification) : 'UNKNOWN';
        $lines[] = "Data Pasar     : {$barDateStr} ({$dataClass} FINAL via Yahoo Finance)";

        // AI Engine & Validation Status
        if ($analysis) {
            $modelName = $analysis->model_name ?? 'ag/gemini-3.8-flash-high';
            $valStatus = strtoupper($analysis->validation_status ?? 'UNKNOWN');
            $lines[] = "Mesin Intelijen: {$modelName} [{$valStatus}]";
        } else {
            $lines[] = 'Mesin Intelijen: ag/gemini-3.8-flash-high [NO_ANALYSIS]';
        }

        $lines[] = '──────────────────────────────────────────';

        if ($analysis) {
            $regime = $analysis->market_regime_summary ?? 'Tidak ada ringkasan rezim pasar.';
            $direction = strtoupper($analysis->impact_direction ?? 'UNCLEAR');
            $confidence = $analysis->confidence_score !== null ? round($analysis->confidence_score * 100).'%' : 'N/A';
            $horizon = strtoupper($analysis->impact_horizon ?? 'MULTI_DAY');

            $lines[] = '📈 REZIM PASAR & ARAH DAMPAK';
            $lines[] = "Arah Prediksi  : {$direction} (Keyakinan: {$confidence}, Horizon: {$horizon})";
            $lines[] = "Ringkasan      : {$regime}";
            $lines[] = '';

            // Impacted Tickers (Only explicitly recorded predictions)
            $structured = $analysis->structured_output ?? [];
            $tickers = $structured['impacted_tickers'] ?? [];
            if (! empty($tickers) && is_array($tickers)) {
                $lines[] = '🎯 INSTRUMEN TERDAMPAK (Eksplisit):';
                foreach (array_slice($tickers, 0, 5) as $item) {
                    if (is_array($item) && isset($item['ticker'])) {
                        $t = $item['ticker'];
                        $d = strtoupper($item['direction'] ?? $analysis->impact_direction ?? 'UNCLEAR');
                        $r = $item['rationale'] ?? '-';
                        $lines[] = "• \${$t} [{$d}]: {$r}";
                    }
                }
                $lines[] = '';
            }

            // News Ingestion Status
            $newsCount = NewsItem::whereDate('fetched_at', today())->count();
            if ($newsCount === 0) {
                $newsCount = NewsItem::count();
            }
            $lines[] = '📰 FEED BERITA PASAR';
            $lines[] = "Total Berita   : {$newsCount} item diproses dari Antara News Finansial.";

            // Fallback Warning
            if ($analysis->validation_status === 'fallback') {
                $lines[] = '';
                $lines[] = '⚠️ PERINGATAN OPERASIONAL:';
                $lines[] = 'Analisis menggunakan HEURISTIK TEKNIKAL FALLBACK akibat router AI tidak merespon/timeout. Bukan hasil penalaran model Gemini aktif.';
            }
        } else {
            $lines[] = '⚠️ Analisis pasar tidak tersedia untuk run ini.';
        }

        $lines[] = '──────────────────────────────────────────';
        $lines[] = '⚠️ Riset kuantitatif & simulasi paper intelligence.';
        $lines[] = 'Dilarang dijadikan rekomendasi transaksi atau jaminan keuntungan.';

        $message = implode("\n", $lines);

        // Telegram maximum length protection (4096 chars limit)
        if (mb_strlen($message) > 4000) {
            $message = mb_substr($message, 0, 3950)."\n\n... [Teks dipotong karena batas panjang pesan Telegram]";
        }

        return $message;
    }

    /**
     * Send EOD notification with strict idempotency and anti-duplicate guards.
     */
    public function sendEodNotification(AnalysisRun $run, ?MarketAnalysis $analysis = null, bool $force = false): array
    {
        $lockKey = 'telegram_send_lock_'.$run->id;
        $lock = Cache::lock($lockKey, 30);

        if (! $lock->get()) {
            return [
                'status' => 'skipped_concurrent',
                'message' => 'Proses pengiriman Telegram sedang berjalan oleh worker lain.',
            ];
        }

        try {
            $analysis = $analysis ?? $run->marketAnalysis;
            $payloadText = $this->formatEodMessage($run, $analysis);
            $contentHash = sha1($payloadText);
            $messageHash = sha1('EOD_CONTENT_'.$contentHash);

            // 1. Check if identical content was already sent recently (within 18 hours)
            $identicalLog = NotificationLog::where('channel', 'telegram')
                ->where('message_hash', $messageHash)
                ->where('status', 'sent')
                ->where('created_at', '>=', now()->subHours(18))
                ->first();

            if ($identicalLog && ! $force) {
                return [
                    'status' => 'skipped_duplicate',
                    'message' => "Laporan EOD identik untuk sesi pasar ini sudah pernah terkirim pada {$identicalLog->sent_at}.",
                    'log_id' => $identicalLog->id,
                ];
            }

            // 2. Check if a report for this specific run ID was already sent
            $existingRunLog = NotificationLog::where('channel', 'telegram')
                ->where('analysis_run_id', $run->id)
                ->where('status', 'sent')
                ->first();

            if ($existingRunLog && ! $force) {
                return [
                    'status' => 'skipped_duplicate',
                    'message' => "Laporan EOD untuk run {$run->run_id} sudah pernah terkirim pada {$existingRunLog->sent_at}.",
                    'log_id' => $existingRunLog->id,
                ];
            }

            // 3. If content has changed and prior message was sent within market session (last 18 hours or today), label as UPDATE
            $priorSentToday = NotificationLog::where('channel', 'telegram')
                ->where('status', 'sent')
                ->where(function ($q) {
                    $q->whereDate('created_at', today())
                        ->orWhere('created_at', '>=', now()->subHours(18));
                })
                ->exists();

            if ($priorSentToday && ($force || ! $identicalLog)) {
                $payloadText = "🔄 [PEMBARUAN / REVISI LAPORAN]\n".$payloadText;
            }

            // Create pending log
            $log = NotificationLog::create([
                'channel' => 'telegram',
                'destination' => "telegram:{$this->chatId}",
                'analysis_run_id' => $run->id,
                'market_analysis_id' => $analysis ? $analysis->id : null,
                'message_hash' => $messageHash,
                'message_payload' => $payloadText,
                'status' => 'pending',
            ]);

            // Execute via Hermes CLI
            $result = $this->executeCliSend($payloadText);

            if ($result['success']) {
                $log->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'error_message' => null,
                ]);

                return [
                    'status' => 'sent',
                    'message' => 'Laporan EOD berhasil dikirim ke Telegram.',
                    'log_id' => $log->id,
                    'output' => $result['output'],
                ];
            } else {
                $sanitizedError = $this->sanitizeSecret($result['error']);
                $log->update([
                    'status' => 'failed',
                    'error_message' => $sanitizedError,
                ]);

                return [
                    'status' => 'failed',
                    'message' => 'Hermes CLI gagal mengirim pesan ke Telegram.',
                    'error' => $sanitizedError,
                    'log_id' => $log->id,
                ];
            }
        } catch (\Throwable $e) {
            $sanitizedError = $this->sanitizeSecret($e->getMessage());
            Log::error("Telegram notification exception: {$sanitizedError}");

            return [
                'status' => 'failed',
                'message' => 'Exception saat memproses pengiriman Telegram.',
                'error' => $sanitizedError,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Send sanitized error notification with cooldown deduplication (6 hours).
     */
    public function sendErrorAlert(string $errorMessage, array $context = []): array
    {
        $sanitizedError = $this->sanitizeSecret($errorMessage);
        $errorHash = sha1($sanitizedError);

        // Cooldown check (6 hours)
        $recent = NotificationLog::where('channel', 'telegram')
            ->where('message_hash', $errorHash)
            ->where('created_at', '>=', now()->subHours(6))
            ->first();

        if ($recent) {
            return [
                'status' => 'skipped_duplicate',
                'message' => 'Error alert identik berada dalam masa cooldown 6 jam.',
            ];
        }

        $nowWib = Carbon::now('Asia/Jakarta')->translatedFormat('d M Y, H:i').' WIB';
        $lines = [
            '🚨 PERINGATAN SISTEM — AETHER MARKET INTELLIGENCE',
            '══════════════════════════════════════════════════',
            "Waktu : {$nowWib}",
            "Pesan : {$sanitizedError}",
        ];

        if (! empty($context['run_id'])) {
            $lines[] = 'Run ID: '.$this->sanitizeSecret($context['run_id']);
        }
        if (! empty($context['source'])) {
            $lines[] = 'Sumber: '.$this->sanitizeSecret($context['source']);
        }

        $lines[] = '──────────────────────────────────────────────────';
        $lines[] = 'Periksa log di storage/logs/eod_pipeline_output.log';

        $payload = implode("\n", $lines);

        $log = NotificationLog::create([
            'channel' => 'telegram',
            'destination' => "telegram:{$this->chatId}",
            'message_hash' => $errorHash,
            'message_payload' => $payload,
            'status' => 'pending',
            'error_message' => $sanitizedError,
        ]);

        $res = $this->executeCliSend($payload);
        if ($res['success']) {
            $log->update(['status' => 'sent', 'sent_at' => now()]);

            return ['status' => 'sent', 'log_id' => $log->id];
        } else {
            $log->update(['status' => 'failed', 'error_message' => $this->sanitizeSecret($res['error'])]);

            return ['status' => 'failed', 'error' => $res['error']];
        }
    }

    /**
     * Execute Hermes CLI send command via temp file.
     */
    protected function executeCliSend(string $message): array
    {
        // Strict test isolation: never send real Telegram messages during automated testing
        if (app()->runningUnitTests()) {
            if (str_contains($this->cliCommand, 'non_existent')) {
                return [
                    'success' => false,
                    'output' => '',
                    'error' => "Command not found: {$this->cliCommand}",
                ];
            }

            return [
                'success' => true,
                'output' => 'MOCK_TEST_DISPATCH_OK',
                'error' => null,
            ];
        }

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }

        $tempFile = $tempDir.DIRECTORY_SEPARATOR.'tg_'.uniqid().'_'.time().'.txt';
        file_put_contents($tempFile, $message);

        try {
            $process = new Process([
                $this->cliCommand,
                'send',
                '--to',
                "telegram:{$this->chatId}",
                '--file',
                $tempFile,
            ]);

            $process->setTimeout(30);
            $process->run();

            $output = $process->getOutput();
            $errorOutput = $process->getErrorOutput();

            if ($process->isSuccessful()) {
                return [
                    'success' => true,
                    'output' => trim($output),
                    'error' => null,
                ];
            } else {
                return [
                    'success' => false,
                    'output' => trim($output),
                    'error' => trim($errorOutput ?: $output ?: "Process exited with code {$process->getExitCode()}"),
                ];
            }
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'output' => '',
                'error' => $e->getMessage(),
            ];
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Sanitize secrets from strings before logging or transmission.
     */
    protected function sanitizeSecret(string $text): string
    {
        // Redact bot tokens, api keys, database passwords
        $patterns = [
            '/[0-9]{8,10}:[a-zA-Z0-9_-]{35}/' => '[REDACTED_BOT_TOKEN]',
            '/sk-[a-zA-Z0-9]{20,}/' => '[REDACTED_API_KEY]',
            '/password=[^\s;&]+/i' => 'password=[REDACTED]',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text);
    }
}
