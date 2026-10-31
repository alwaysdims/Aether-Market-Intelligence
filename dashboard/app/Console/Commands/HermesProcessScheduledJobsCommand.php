<?php

namespace App\Console\Commands;

use App\Models\ScheduledTelegramJob;
use App\Services\TelegramMarketAssistantService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class HermesProcessScheduledJobsCommand extends Command
{
    protected $signature = 'hermes:process-scheduled-jobs {--mock : Run in mock mode without live Telegram CLI} {--live : Execute real Telegram delivery via Hermes CLI}';

    protected $description = 'Process due scheduled Telegram research and market recommendation jobs';

    public function handle(TelegramMarketAssistantService $service): int
    {
        $isLive = $this->option('live');
        $mockOnly = ! $isLive;

        $this->info('=== MEMPROSES JADWAL PEKERJAAN TELEGRAM AETHER ===');
        $this->line('Mode: '.($mockOnly ? 'MOCK (ISOLASI PENGUJIAN)' : 'LIVE HERMES CLI'));
        $this->line('Waktu Server: '.Carbon::now('Asia/Jakarta')->format('Y-m-d H:i:s').' WIB');

        // Active lease protection: Only reclaim stale leases if process is DEAD or properly fenced
        $staleCandidates = ScheduledTelegramJob::where('status', 'PROCESSING')
            ->where(function ($q) {
                $q->whereNull('heartbeat_at')
                    ->orWhere('heartbeat_at', '<', now()->subMinutes(5));
            })
            ->get();

        $reclaimedCount = 0;
        foreach ($staleCandidates as $staleJob) {
            $isAlive = $this->isPidAlive($staleJob->worker_pid);
            if (! $isAlive) {
                // Process is confirmed DEAD: safely reclaim
                $staleJob->update(['status' => 'PENDING', 'worker_pid' => null, 'worker_token' => null]);
                $reclaimedCount++;
            } else {
                // Process is STILL alive.
                // Do NOT reclaim just because heartbeat > 15m!
                // If deemed hung (> 30m without heartbeat), fence/kill old process first!
                if ($staleJob->heartbeat_at && $staleJob->heartbeat_at->lessThan(now()->subMinutes(30))) {
                    $this->warn("Worker PID {$staleJob->worker_pid} dinilai hang (heartbeat >30m). Melakukan fencing proses...");
                    $fenced = $this->fenceProcess($staleJob->worker_pid, $staleJob->worker_process_created_at);
                    if ($fenced) {
                        $staleJob->update(['status' => 'PENDING', 'worker_pid' => null, 'worker_token' => null, 'worker_process_created_at' => null]);
                        $reclaimedCount++;
                        $this->info("Fencing sukses. Job #{$staleJob->id} aman direclaim ke PENDING.");
                    } else {
                        $this->error("Fencing dibatalkan atau gagal untuk PID {$staleJob->worker_pid} (verifikasi identitas/creation_date tidak cocok atau kill gagal). Menolak reclaim.");
                    }
                } else {
                    $this->line("Worker PID {$staleJob->worker_pid} masih berjalan aktif. Lease dipertahankan.");
                }
            }
        }

        if ($reclaimedCount > 0) {
            $this->warn("Mereclaim {$reclaimedCount} pekerjaan stale kembali ke PENDING.");
        }

        // Fetch pending jobs that are due (exclude ASYNC_CONSULTATION which has its own fast-track worker)
        $now = now();
        $dueJobs = ScheduledTelegramJob::where('status', 'PENDING')
            ->where('request_type', '!=', 'ASYNC_CONSULTATION')
            ->where('scheduled_at', '<=', $now)
            ->orderBy('scheduled_at', 'asc')
            ->get();

        if ($dueJobs->isEmpty()) {
            $this->info('Tidak ada pekerjaan terjadwal yang jatuh tempo saat ini.');

            return 0;
        }

        $this->info("Menemukan {$dueJobs->count()} pekerjaan jatuh tempo.");

        $processed = 0;
        $completed = 0;
        $failed = 0;
        $missed = 0;

        foreach ($dueJobs as $job) {
            $processed++;

            // Check if job is overdue beyond its allowed tolerance
            $tolerance = $job->tolerance_minutes ?: 60;
            $delayMinutes = $job->scheduled_at ? $job->scheduled_at->diffInMinutes($now, false) : 0;
            if ($delayMinutes > $tolerance) {
                $job->update([
                    'status' => 'MISSED',
                    'error_message' => "Pekerjaan terlewat {$delayMinutes} menit (melebihi toleransi {$tolerance} menit) karena server/worker tidak aktif pada jam yang dijadwalkan.",
                ]);
                $missed++;
                $this->warn("Job #{$job->id} ditandai MISSED ({$delayMinutes} menit terlewat > toleransi {$tolerance} menit).");

                continue;
            }

            $res = $service->executeScheduledJob($job, mockOnly: $mockOnly);

            if ($res['status'] === 'success') {
                $completed++;
                $this->line("Job #{$job->id} selesai. OutboundMsg: {$res['outbound_message_id']} ({$res['duration_ms']} ms)");
            } else {
                $failed++;
                $this->error("Job #{$job->id} gagal: ".($res['error'] ?? 'Unknown error'));
            }
        }

        $this->table(
            ['Metrik', 'Jumlah'],
            [
                ['Total Diproses', $processed],
                ['Berhasil Dikirim', $completed],
                ['Terlewat (Missed)', $missed],
                ['Gagal', $failed],
            ]
        );

        return 0;
    }

    public function isPidAlive(?int $pid): bool
    {
        if (! $pid || $pid <= 0) {
            return false;
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            @exec("tasklist /FI \"PID eq {$pid}\" /NH 2>NUL", $output);
            $text = implode(' ', (array) $output);

            return str_contains($text, (string) $pid) && ! str_contains($text, 'No tasks');
        }

        return file_exists("/proc/{$pid}");
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

    public function fenceProcess(?int $pid, ?string $expectedCreationDate = null): bool
    {
        if (! $pid || $pid <= 0) {
            return false;
        }

        $details = $this->getProcessDetails($pid);
        if (! $details['alive']) {
            return true;
        }

        // PID REUSE PROTECTION:
        // Verify image is php/cmd
        if (! empty($details['image']) && ! str_contains($details['image'], 'php') && ! str_contains($details['image'], 'cmd')) {
            if ($this->output) {
                $this->error("PID reuse terdeteksi: PID {$pid} saat ini adalah '{$details['image']}', bukan worker PHP. Fencing dibatalkan demi keamanan.");
            }

            return false;
        }

        // Verify creation date if recorded
        if ($expectedCreationDate && ! empty($details['creation_date']) && $details['creation_date'] !== $expectedCreationDate) {
            if ($this->output) {
                $this->error("PID reuse terdeteksi: Waktu pembuatan proses berbeda ({$details['creation_date']} != {$expectedCreationDate}). Fencing dibatalkan.");
            }

            return false;
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            @exec("taskkill /F /PID {$pid} 2>NUL");
        } else {
            @exec("kill -9 {$pid} 2>/dev/null");
        }

        usleep(100000); // 100ms grace period

        return ! $this->isPidAlive($pid);
    }
}
