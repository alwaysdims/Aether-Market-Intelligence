<?php

namespace App\Console\Commands;

use App\Models\IdxCompanyRegistry;
use App\Models\IdxIngestionJob;
use App\Services\IdxFinancialIngestionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class IdxProcessIngestionQueueCommand extends Command
{
    protected $signature = 'idx:process-queue {--limit=5 : Jumlah emiten per batch} {--ticker= : Proses emiten spesifik} {--canary : Jalankan canary test 5 sektor}';

    protected $description = 'Memproses antrean pengambilan data harga, fundamental, dan metrik BEI bertahap dengan checkpoint recovery.';

    public function handle(IdxFinancialIngestionService $ingestionService): int
    {
        if ($this->option('canary')) {
            $this->info('1. Menjalankan canary test 5 sektor (BBCA, MEDC, ICBP, GOTO, PWON)...');
            $canary = $ingestionService->runCanaryTest();
            $this->line("   -> Sukses: {$canary['success_count']}/{$canary['canary_total']}");
            foreach ($canary['results'] as $tk => $r) {
                $status = ($r['success'] ?? false) ? 'OK' : 'FAIL';
                $this->line("      • [{$status}] {$tk}: bars={$r['bars_stored']} reports={$r['reports_stored']} trend={$r['technical_trend']}");
            }

            return $canary['is_canary_passed'] ? Command::SUCCESS : Command::FAILURE;
        }

        $specificTicker = $this->option('ticker');
        if ($specificTicker) {
            $ticker = strtoupper(trim($specificTicker));
            $this->info("Memproses data untuk emiten {$ticker}...");
            $res = $ingestionService->ingestTicker($ticker, false);
            if ($res['success'] ?? false) {
                $this->info("   -> Sukses: {$ticker} | Bars: {$res['bars_stored']} | Lapkeu: {$res['reports_stored']} | Tren: {$res['technical_trend']}");

                return Command::SUCCESS;
            } else {
                $this->error("   -> Gagal: {$ticker} - ".($res['error'] ?? 'Unknown error'));

                return Command::FAILURE;
            }
        }

        $limit = (int) $this->option('limit');
        $this->info("Memproses antrean batch ingestion BEI (limit {$limit} emiten)...");

        // 1. Ensure pending queue is replenished from NOT_ENQUEUED candidates
        $pendingJobsCount = IdxIngestionJob::whereIn('status', ['PENDING', 'RUNNING'])->count();
        if ($pendingJobsCount < $limit) {
            $enqueuedTickers = IdxIngestionJob::pluck('ticker')->toArray();
            $nextTickers = IdxCompanyRegistry::where('instrument_type', 'EQUITY')
                ->whereNotIn('ticker', $enqueuedTickers)
                ->orderBy('ticker', 'asc')
                ->take(max(15, $limit * 3))
                ->pluck('ticker');

            if ($nextTickers->isNotEmpty()) {
                $this->line("Mengisi antrean dengan {$nextTickers->count()} kandidat saham baru...");
                foreach ($nextTickers as $t) {
                    IdxIngestionJob::create([
                        'job_type' => 'METRICS',
                        'ticker' => $t,
                        'status' => 'PENDING',
                        'attempt_count' => 0,
                        'max_attempts' => 3,
                    ]);
                }
            }
        }

        // 2. Claim batch atomically with lease token
        $leaseToken = Str::uuid()->toString();
        $now = now();
        $leaseExpires = $now->copy()->addMinutes(5);

        $jobs = IdxIngestionJob::where(function ($q) use ($now) {
            $q->where('status', 'PENDING')
                ->orWhere(function ($q2) use ($now) {
                    $q2->where('status', 'RUNNING')
                        ->where('lease_expires_at', '<', $now);
                });
        })
            ->where('attempt_count', '<', 3)
            ->orderBy('id', 'asc')
            ->take($limit)
            ->get();

        // 3. Fallback to resume PARTIAL jobs if all PENDING are done (strictly retryable only)
        if ($jobs->isEmpty()) {
            $jobs = IdxIngestionJob::where('status', 'PARTIAL')
                ->where('attempt_count', '<', 3)
                ->where(function ($q) use ($now) {
                    $q->whereNull('lease_expires_at')
                        ->orWhere('lease_expires_at', '<', $now);
                })
                ->orderBy('id', 'asc')
                ->get()
                ->filter(function ($j) {
                    $isRetryable = $j->checkpoint_data['retryable'] ?? false;
                    if (! $isRetryable) {
                        return false;
                    }
                    $nextRetry = $j->checkpoint_data['next_retry_at'] ?? null;
                    if ($nextRetry && Carbon::parse($nextRetry)->isFuture()) {
                        return false;
                    }

                    return true;
                })
                ->take($limit);
        }

        if ($jobs->isEmpty()) {
            $this->info('Tidak ada pekerjaan pending atau partial dalam antrean.');

            return Command::SUCCESS;
        }

        $processed = 0;
        foreach ($jobs as $job) {
            $job->update([
                'status' => 'RUNNING',
                'lease_token' => $leaseToken,
                'lease_expires_at' => $leaseExpires,
                'started_at' => now(),
                'attempt_count' => $job->attempt_count + 1,
            ]);

            $ticker = $job->ticker;
            $this->line("• Mengambil data {$ticker} (Percobaan {$job->attempt_count}/{$job->max_attempts})...");
            $res = $ingestionService->ingestTicker($ticker, false);

            if ($res['success'] ?? false) {
                $isCalculated = ($res['fundamental_status'] ?? '') === 'CALCULATED';
                $partialReason = $res['partial_reason'] ?? ($isCalculated ? null : 'INCOMPLETE_DATA');
                $missingComps = $res['missing_components'] ?? [];
                $isRetryable = $res['retryable'] ?? false;
                $nextRetryAt = $isRetryable ? now()->addHours(6) : null;

                $job->update([
                    'status' => $isCalculated ? 'COMPLETED' : 'PARTIAL',
                    'progress_pct' => 100,
                    'completed_at' => now(),
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'checkpoint_data' => array_merge($res, [
                        'partial_reason' => $partialReason,
                        'missing_components' => $missingComps,
                        'retryable' => $isRetryable,
                        'next_retry_at' => $nextRetryAt?->toIso8601String(),
                    ]),
                ]);
                $this->info("   -> Sukses: {$ticker} [{$job->status}]".($partialReason ? " ({$partialReason})" : ''));
            } else {
                $isMax = $job->attempt_count >= $job->max_attempts;
                $job->update([
                    'status' => $isMax ? 'FAILED' : 'PENDING',
                    'error_message' => $res['error'] ?? 'Gagal mengambil data',
                    'lease_token' => null,
                    'lease_expires_at' => null,
                ]);
                $this->warn('   -> '.($isMax ? 'FAILED FINAL' : 'RETRY SCHEDULED').": {$ticker} - ".($res['error'] ?? ''));
            }

            $processed++;
            usleep(250000); // 250ms spacing between tickers to respect public provider rate limit
        }

        $this->info("Selesai memproses {$processed} pekerjaan antrean.");

        return Command::SUCCESS;
    }
}
