<?php

namespace App\Services;

use App\Models\MonitoringEvent;
use App\Models\PredictionSignal;
use App\Models\ResearchRequest;
use App\Models\ScheduledTelegramJob;
use App\Models\TelegramOutbox;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class TelegramFormattingService
{
    protected string $defaultChatId;

    public function __construct()
    {
        $this->defaultChatId = (string) config('services.telegram.chat_id');
    }

    /**
     * Format initial directional signal message according to strict Tahap I rules.
     * (NO action buttons, NO auto-trade, NO leverage).
     */
    public function formatInitialSignalMessage(PredictionSignal $signal): string
    {
        $nowWib = Carbon::now('Asia/Jakarta')->translatedFormat('d M Y, H:i').' WIB';
        $decDateWib = $signal->decision_date->format('d M Y');
        $pubWib = $signal->published_at ? $signal->published_at->timezone('Asia/Jakarta')->format('d M Y, H:i').' WIB' : 'N/A';
        $dueWib = $signal->evaluation_due_date->format('d M Y');
        $expWib = $signal->expires_at->timezone('Asia/Jakarta')->format('d M Y, H:i').' WIB';

        $pUpPct = round($signal->probability_up * 100, 2);
        $pDownPct = round($signal->probability_down * 100, 2);
        $confPct = round($signal->confidence_score * 100, 2);

        $lines = [];
        $lines[] = '🎯 AETHER MARKET INTELLIGENCE — PREDIKSI SINYAL';
        $lines[] = '══════════════════════════════════════════════════';
        $lines[] = '⚠️ MODE RISET KUANTITATIF (RESEARCH ONLY) — BUKAN SARAN INVESTASI';
        $lines[] = '';
        $lines[] = '📌 IDENTITAS SINYAL';
        $lines[] = "• Signal ID     : {$signal->prediction_id}";
        $lines[] = "• Saham / Ticker: {$signal->ticker} (Bursa Efek Indonesia)";
        $lines[] = '• Status Sinyal : '.strtoupper($signal->signal_status);
        $lines[] = '• Arah Prediksi : '.strtoupper($signal->direction).($signal->direction === 'DOWN' ? ' (Bukan Instruksi Short Selling)' : '');
        $lines[] = '';
        $lines[] = '💵 HARGA & WAKTU ACUAN';
        $lines[] = '• Harga Acuan   : Rp '.number_format($signal->reference_price, 2, ',', '.')." ({$decDateWib})";
        $lines[] = '• Kualitas Data : EOD Final (Yahoo Finance v8 / Delayed 15m)';
        $lines[] = "• Waktu Publikasi: {$pubWib}";
        $lines[] = "• Horizon Uji   : 5 Hari Bursa (Jatuh Tempo: {$dueWib})";
        $lines[] = "• Batas Expiry  : Maksimal 14 Hari Kalender ({$expWib})";
        $lines[] = '';
        $lines[] = '🤖 MODEL & ESTIMASI PROBABILITAS';
        $lines[] = "• Model / Versi : {$signal->model_name} ({$signal->model_version})";
        $lines[] = "• P(Naik / UP)  : {$pUpPct}% | P(Turun / DOWN): {$pDownPct}%";
        $lines[] = "• Tingkat Yakin : {$confPct}% (Ambang Batas Selektif: >= 40.00% / Tau 0.70)";
        $lines[] = '• Target Harga  : '.($signal->target_price ? 'Rp '.number_format($signal->target_price, 2, ',', '.') : 'Sesuai Horizon 5-Hari');
        $lines[] = '• Batas Pembatal: '.($signal->invalidation_price ? 'Rp '.number_format($signal->invalidation_price, 2, ',', '.') : 'Level Support/Resistance');
        $lines[] = '';

        // News evidence if available
        $evidence = $signal->evidenceSnapshot;
        $newsList = $evidence ? ($evidence->related_news_snapshot ?? []) : [];
        if (! empty($newsList)) {
            $lines[] = '📰 INTELEJENSI BERITA & POTENSI DAMPAK';
            foreach (array_slice($newsList, 0, 2) as $n) {
                $lines[] = '• '.($n['title'] ?? 'Berita Pasar');
                $lines[] = '  Sumber: '.($n['source'] ?? 'LKBN ANTARA').' | '.($n['url'] ?? '');
            }
            $lines[] = '';
        }

        $lines[] = '──────────────────────────────────────────────────';
        $lines[] = '⚠️ Dilarang menggunakan leverage atau trading otomatis.';
        $lines[] = 'Perubahan harga pasar bukan representasi keuntungan pengguna.';

        return implode("\n", $lines);
    }

    /**
     * Format daily digest for abstained / NO_SIGNAL predictions (anti-spam).
     */
    public function formatNoSignalDigest(array $signals, Carbon $date): string
    {
        $dateStr = $date->format('d M Y');
        $lines = [];
        $lines[] = '📋 AETHER MARKET INTELLIGENCE — DIGEST HARIAN NO_SIGNAL';
        $lines[] = '════════════════════════════════════════════════════════';
        $lines[] = "Tanggal Bursa: {$dateStr} (Penutupan EOD 16:00 WIB)";
        $lines[] = 'Status       : SELURUH INSTRUMEN ABSTAIN (NO_SIGNAL)';
        $lines[] = '';
        $lines[] = 'Sistem menerapkan protokol selective prediction dengan ambang batas keyakinan minimal 40.00% (Tau 0.70). Tidak ada instrumen yang memenuhi kriteria arah yang andal:';
        $lines[] = '';

        foreach ($signals as $s) {
            $ticker = is_array($s) ? $s['ticker'] : $s->ticker;
            $conf = is_array($s) ? ($s['confidence_score'] ?? $s['confidence'] ?? 0) : $s->confidence_score;
            $pUp = is_array($s) ? ($s['probability_up'] ?? 0) : $s->probability_up;
            $confPct = round($conf * 100, 1);
            $pUpPct = round($pUp * 100, 1);
            $lines[] = "• {$ticker}: Conf {$confPct}% (P(UP) {$pUpPct}%) -> ABSTAIN";
        }

        $lines[] = '';
        $lines[] = '🛡️ Keputusan NO_SIGNAL melindungi modal dari friksi bursa & sinyal palsu.';
        $lines[] = '════════════════════════════════════════════════════════';

        return implode("\n", $lines);
    }

    /**
     * Split text into chunks preserving line breaks within max character limits.
     */
    public function splitTextByLines(string $text, int $maxLen = 3900): array
    {
        if (mb_strlen($text) <= $maxLen) {
            return [$text];
        }

        $lines = explode("\n", $text);
        $chunks = [];
        $current = '';

        foreach ($lines as $line) {
            if ($current !== '' && (mb_strlen($current) + mb_strlen($line) + 1) > $maxLen) {
                $chunks[] = trim($current);
                $current = '';
            }

            while (mb_strlen($line) > $maxLen) {
                $chunks[] = mb_substr($line, 0, $maxLen);
                $line = mb_substr($line, $maxLen);
            }

            $current = ($current === '') ? $line : $current."\n".$line;
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }

    /**
     * Format monitoring update message to be sent as a reply to initial message.
     */
    public function formatMonitoringUpdateReply(PredictionSignal $signal, MonitoringEvent $event): string
    {
        $eventTimeWib = $event->event_time->timezone('Asia/Jakarta')->format('d M Y, H:i').' WIB';
        $lines = [];
        $lines[] = "🔔 PEMBARUAN PEMANTAUAN — {$signal->ticker}";
        $lines[] = '──────────────────────────────────────────';
        $lines[] = "Signal ID   : {$signal->prediction_id}";
        $lines[] = "Waktu Event : {$eventTimeWib}";
        $lines[] = 'Tipe Event  : '.strtoupper($event->event_type);

        if ($event->price_observed !== null) {
            $lines[] = 'Harga Amati : Rp '.number_format($event->price_observed, 2, ',', '.');
        }

        $lines[] = "Keterangan  : {$event->description}";
        $lines[] = '';
        $lines[] = 'Status Sinyal Saat Ini : '.strtoupper($signal->monitoring_status);
        $lines[] = 'Evaluasi 5-Hari Bursa  : '.strtoupper($signal->evaluation_status);
        $lines[] = '──────────────────────────────────────────';

        return implode("\n", $lines);
    }

    /**
     * Format comprehensive recap message.
     */
    public function formatHorizonRecapMessage(array $stats, Carbon $date): string
    {
        $dateStr = $date->format('d M Y');
        $lines = [];
        $lines[] = '📊 AETHER MARKET INTELLIGENCE — REKAP PREDIKSI';
        $lines[] = '══════════════════════════════════════════════';
        $lines[] = "Periode Laporan : As of {$dateStr}";
        $lines[] = '';
        $lines[] = '📈 POPULASI KOHORT SINYAL';
        $lines[] = '• Retrospective Replay (Unverified) : '.($stats['total_signals'] ?? 0).' (10 Abstain)';
        $lines[] = '• Live Forward Real-Time Terbit     : 0 (Menunggu sesi live bursa)';
        $lines[] = '• Rasio Cakupan Directional         : '.($stats['coverage_pct'] ?? '0%');
        $lines[] = '';
        $lines[] = '🔍 HASIL PEMANTAUAN (14 HARI)';
        $lines[] = '• Sinyal Aktif           : '.($stats['active'] ?? 0);
        $lines[] = '• Target Tersentuh       : '.($stats['target_hit'] ?? 0);
        $lines[] = '• Terbatal (Invalidated) : '.($stats['invalidated'] ?? 0);
        $lines[] = '• Ambigu (Same-bar touch): '.($stats['ambiguous'] ?? 0);
        $lines[] = '• Kedaluwarsa (Expired)  : '.($stats['expired'] ?? 0);
        $lines[] = '';
        $lines[] = '🎯 HASIL EVALUASI HORIZON 5-HARI';
        $lines[] = '• Menunggu (Pending)     : '.($stats['eval_pending'] ?? 0);
        $lines[] = '• Benar (Correct)        : '.($stats['eval_correct'] ?? 0);
        $lines[] = '• Salah (Incorrect)      : '.($stats['eval_incorrect'] ?? 0);
        $lines[] = '• Netral / Abstain       : '.($stats['eval_neutral'] ?? 0);
        $lines[] = '• Akurasi Arah Sinyal    : '.($stats['directional_accuracy'] ?? 'N/A');
        $lines[] = '';
        $lines[] = 'ℹ️ Catatan Provenance: 10 sinyal bertanggal 16 Okt berstatus Retrospective Replay (tidak dihitung sebagai forward live). 30 evaluasi fallback lama diisolasi terpisah.';
        $lines[] = '══════════════════════════════════════════════';

        return implode("\n", $lines);
    }

    /**
     * Enqueue a message into the transactional Telegram Outbox.
     */
    public function enqueueOutbox(
        string $dedupKey,
        string $messageType,
        string $payload,
        ?string $predictionId = null,
        ?string $replyToMessageId = null,
        ?string $chatId = null
    ): TelegramOutbox {
        return TelegramOutbox::firstOrCreate(
            ['dedup_key' => $dedupKey],
            [
                'prediction_id' => $predictionId,
                'chat_id' => $chatId ?? $this->defaultChatId,
                'reply_to_message_id' => $replyToMessageId,
                'message_type' => $messageType,
                'payload_text' => $payload,
                'status' => 'QUEUED',
                'retry_count' => 0,
            ]
        );
    }

    /**
     * Process pending items in outbox queue with atomic claim, concurrency protection, and backoff.
     * When mockOnly is true, updates state without calling actual Telegram network API.
     */
    public function processOutboxQueue(bool $mockOnly = true): array
    {
        $sentCount = 0;
        $failedCount = 0;
        $supersededCount = 0;

        // Atomic claim: fetch items with skipLocked to protect against concurrent workers
        $itemsToProcess = DB::transaction(function () {
            return TelegramOutbox::where(function ($q) {
                $q->where('status', 'QUEUED')
                    ->orWhere(function ($q2) {
                        // Reclaim stale leases older than 5 minutes
                        $q2->where('status', 'PROCESSING')
                            ->where('updated_at', '<', now()->subMinutes(5));
                    });
            })
                ->where(function ($q) {
                    $q->whereNull('retry_after')
                        ->orWhere('retry_after', '<=', now());
                })
                ->where('retry_count', '<', 3)
                ->lockForUpdate()
                ->take(10)
                ->get()
                ->each(fn (TelegramOutbox $item) => $item->update(['status' => 'PROCESSING']));
        });

        foreach ($itemsToProcess as $item) {
            if ((string) $item->chat_id !== (string) config('services.telegram.chat_id')) {
                $item->update(['status' => 'SUPERSEDED_UNSENT', 'error_message' => 'Tujuan tidak ada dalam allowlist aplikasi.']);
                $supersededCount++;

                continue;
            }
            if ($item->message_type === 'INBOUND_REPLY') {
                $requestId = $item->metadata['request_id'] ?? null;
                $request = $requestId ? ResearchRequest::where('request_id', $requestId)->first() : null;
                if (! $request || ! ctype_digit((string) $request->inbound_message_id)) {
                    $item->update(['status' => 'SUPERSEDED_UNSENT', 'error_message' => 'Permintaan riset tidak terhubung ke message_id inbound Telegram numerik.']);
                    $supersededCount++;

                    continue;
                }
            }
            // Check superseded / expired policy: If prediction already EXPIRED or CANCELLED, do not broadcast stale alert
            if ($item->prediction_id) {
                $sig = PredictionSignal::where('prediction_id', $item->prediction_id)->first();
                if ($sig && in_array($sig->monitoring_status, ['EXPIRED', 'CANCELLED']) && in_array($item->message_type, ['INITIAL_SIGNAL', 'MONITORING_UPDATE'])) {
                    $item->update([
                        'status' => 'SUPERSEDED_UNSENT',
                        'error_message' => "Sinyal {$item->prediction_id} telah berstatus {$sig->monitoring_status}. Notifikasi outbox dibatalkan sesuai kebijakan anti-stale.",
                        'metadata' => array_merge($item->metadata ?? [], ['superseded_at' => now()->toISOString()]),
                    ]);
                    $supersededCount++;

                    continue;
                }
            }

            // Check superseded policy for ScheduledTelegramJob alerts:
            // Alert batalkan jika:
            // 1. Job sudah COMPLETED dengan outbound_message_id
            // 2. Job sedang dalam pemulihan aktif (PROCESSING dengan worker_token lebih baru daripada saat alert dibuat)
            // 3. Alert berasal dari worker_token lama yang telah kedaluwarsa/disupersede
            $jobId = $item->metadata['scheduled_job_id'] ?? null;
            if ($jobId && in_array($item->message_type, ['SYSTEM_ALERT', 'DEADLINE_ALERT'])) {
                $schedJob = ScheduledTelegramJob::find($jobId);
                if ($schedJob) {
                    $alertToken = $item->metadata['worker_token'] ?? null;
                    $currentToken = $schedJob->worker_token;
                    $jobCompleted = ($schedJob->status === 'COMPLETED' && $schedJob->outbound_message_id);
                    $recoveryInProgress = ($schedJob->status === 'PROCESSING' && (! $alertToken || ($currentToken && $currentToken !== $alertToken)));

                    if ($jobCompleted || $recoveryInProgress) {
                        $reasonText = $jobCompleted
                            ? "Pekerjaan #{$jobId} telah selesai dan terkirim dengan sukses (Msg ID: {$schedJob->outbound_message_id}). Notifikasi alert dibatalkan."
                            : "Pekerjaan #{$jobId} sedang diproses dalam attempt pemulihan aktif (Token: {$currentToken}). Alert attempt lama dibatalkan.";

                        $item->update([
                            'status' => 'SUPERSEDED_UNSENT',
                            'error_message' => $reasonText,
                            'metadata' => array_merge($item->metadata ?? [], [
                                'superseded_at' => now()->toISOString(),
                                'resolved_by_job_status' => $schedJob->status,
                                'superseded_reason' => $jobCompleted ? 'JOB_COMPLETED' : 'RECOVERY_IN_PROGRESS',
                            ]),
                        ]);
                        $supersededCount++;

                        continue;
                    }
                }
            }

            // Mark lease as PROCESSING
            $item->update(['status' => 'PROCESSING']);

            if ($mockOnly) {
                $mockMsgId = 'MOCK_TG_'.uniqid();
                $item->update([
                    'status' => 'SENT',
                    'sent_at' => now(),
                    'telegram_message_id' => $mockMsgId,
                ]);

                // If this is an initial signal, update prediction's telegram_message_id
                if ($item->prediction_id && $item->message_type === 'INITIAL_SIGNAL') {
                    PredictionSignal::where('prediction_id', $item->prediction_id)
                        ->update(['telegram_message_id' => $mockMsgId]);
                }

                $sentCount++;
            } else {
                // Real delivery via Hermes CLI
                try {
                    $cliCmd = config('services.hermes.cli_command', 'hermes');
                    $tempDir = storage_path('app/temp');
                    @mkdir($tempDir, 0755, true);

                    $chunks = $this->splitTextByLines($item->payload_text, 3900);
                    $existingMeta = is_array($item->metadata) ? $item->metadata : [];
                    $sentMessageIds = $existingMeta['all_message_ids'] ?? [];
                    $lastOutput = null;

                    // Resume delivery from unsent chunks to prevent duplicating chunks on retry
                    $startChunkIdx = count($sentMessageIds);

                    foreach ($chunks as $idx => $chunk) {
                        if ($idx < $startChunkIdx) {
                            continue; // Skip already sent parts
                        }

                        $chunkHeader = count($chunks) > 1 ? '('.($idx + 1).'/'.count($chunks).")\n" : '';
                        $tempFile = $tempDir.DIRECTORY_SEPARATOR.'tg_'.uniqid().'.txt';
                        file_put_contents($tempFile, $chunkHeader.$chunk);

                        $targetChat = $item->chat_id ?: config('services.telegram.chat_id', '5923895922');
                        $args = [$cliCmd, 'send', '--to', "telegram:{$targetChat}", '--file', $tempFile, '--json'];

                        $process = new Process($args);
                        $process->setTimeout(60);
                        $process->run();
                        @unlink($tempFile);

                        if (! $process->isSuccessful()) {
                            // Update already sent chunk progress so retry won't resend earlier chunks
                            $item->update([
                                'metadata' => array_merge($existingMeta, [
                                    'all_message_ids' => $sentMessageIds,
                                    'total_parts' => count($chunks),
                                ]),
                            ]);
                            throw new \RuntimeException($process->getErrorOutput() ?: 'Hermes send failed');
                        }

                        $output = json_decode($process->getOutput(), true) ?: ['raw' => trim($process->getOutput())];
                        $msgId = $output['message_id'] ?? $output['id'] ?? null;
                        if (! is_scalar($msgId) || ! ctype_digit((string) $msgId)) {
                            throw new \RuntimeException('Transport sukses tanpa receipt Telegram message_id numerik.');
                        }
                        $sentMessageIds[] = (string) $msgId;
                        $lastOutput = $output;
                    }

                    $primaryMsgId = end($sentMessageIds);
                    $item->update([
                        'status' => 'SENT',
                        'sent_at' => now(),
                        'telegram_message_id' => $primaryMsgId,
                        'metadata' => array_merge($item->metadata ?? [], [
                            'cli_result' => $lastOutput,
                            'all_message_ids' => $sentMessageIds,
                            'total_parts' => count($chunks),
                        ]),
                    ]);
                    $this->syncJobDeliveryStatus($item, 'COMPLETED', 'SENT', $primaryMsgId, now());
                    $sentCount++;
                } catch (\Exception $e) {
                    $newRetry = $item->retry_count + 1;
                    $isTimeout = ($e instanceof ProcessTimedOutException);
                    $newStatus = $isTimeout ? 'DELIVERY_UNKNOWN' : (($newRetry >= 3) ? 'FAILED' : 'QUEUED');
                    $backoffMinutes = (int) pow(2, $newRetry);

                    $item->update([
                        'retry_count' => $newRetry,
                        'status' => $newStatus,
                        'retry_after' => ($newStatus === 'QUEUED') ? now()->addMinutes($backoffMinutes) : null,
                        'error_message' => ($isTimeout ? 'Timeout transport CLI (hasil pengiriman belum pasti): ' : 'Pengecualian transport: ').$e->getMessage(),
                    ]);
                    $this->syncJobDeliveryStatus($item, $newStatus, $newStatus, null, null, $item->error_message);
                    $failedCount++;
                }
            }
        }

        return [
            'processed' => count($itemsToProcess),
            'sent' => $sentCount,
            'failed' => $failedCount,
            'superseded' => $supersededCount,
        ];
    }

    protected function syncJobDeliveryStatus(
        TelegramOutbox $item,
        string $jobStatus,
        string $deliveryStatus,
        ?string $outboundMsgId = null,
        ?\DateTimeInterface $sentAt = null,
        ?string $errorMessage = null
    ): void {
        $jobId = $item->metadata['scheduled_job_id'] ?? null;
        if (! $jobId) {
            return;
        }

        try {
            $job = ScheduledTelegramJob::find($jobId);
            if ($job) {
                $updates = [
                    'status' => $jobStatus,
                    'delivery_status' => $deliveryStatus,
                ];
                if ($outboundMsgId !== null) {
                    $updates['outbound_message_id'] = $outboundMsgId;
                }
                if ($sentAt !== null) {
                    $updates['sent_at'] = $sentAt;
                }
                if ($errorMessage !== null) {
                    $updates['error_message'] = $errorMessage;
                }
                $job->update($updates);
            }
        } catch (\Throwable $e) {
            Log::warning("[TelegramFormattingService] Failed to sync job status #{$jobId}: ".$e->getMessage());
        }
    }
}
