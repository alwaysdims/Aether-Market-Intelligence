<?php

namespace App\Console\Commands;

use App\Models\EodSessionQueue;
use App\Services\BeiCalendarService;
use App\Services\DailyEodRefreshService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RefreshDailyEodCommand extends Command
{
    protected $signature = 'hermes:refresh-eod 
                            {--tickers= : Specific comma-separated tickers to refresh}
                            {--broker : Also sync broker summary for updated tickers}
                            {--overlap=20 : Number of overlap bars to request (min 20)}
                            {--retry-only : Hanya proses emiten dalam antrean retry yang sudah jatuh tempo}
                            {--canonical-gap : Hanya proses emiten yang belum memiliki kedua mode canonical pada sesi target}
                            {--session= : Tanggal sesi target (YYYY-MM-DD), default sesi pasar tertutup terbaru}
                            {--limit= : Maksimal jumlah emiten yang diproses dalam pemanggilan ini}
                            {--metrics-only : Hitung metrik dari bar lokal tanpa fetch provider}
                            {--trigger-source= : Trigger provenance, e.g. RECOVERY_RETRY}
                            {--reconcile-interrupted : Reconcile one stale PROCESSING lease without fetching provider data}
                            {--dry-run : Resolve target session and queue eligibility without DB processing}';

    protected $description = 'Automated daily EOD incremental refresh for closed market session with persistent queue and lock heartbeat';

    public function handle(DailyEodRefreshService $refreshService): int
    {
        $expectedDatabase = (string) getenv('AETHER_ACTIVE_DATABASE');
        $expectedRole = (string) getenv('AETHER_WRITER_ROLE');
        if ($expectedDatabase === '' || $expectedRole === '') {
            $this->error('EOD_WRITER_ABORT missing explicit writer identity');

            return self::FAILURE;
        }
        $identity = DB::selectOne("SELECT current_database() AS database, current_user AS user_name, current_schema() AS schema_name, current_setting('search_path') AS search_path");
        if ($identity->database !== $expectedDatabase || $identity->user_name !== $expectedRole || $identity->schema_name !== 'public') {
            $this->error('EOD_WRITER_ABORT identity mismatch after bootstrap');

            return self::FAILURE;
        }

        $targetSession = $this->option('session');
        $calendar = app(BeiCalendarService::class);
        $sessionDate = $refreshService->resolveTargetSessionDate($targetSession);
        $tickerOpt = $this->option('tickers');
        $syncBroker = (bool) $this->option('broker');
        $overlap = max(20, (int) $this->option('overlap'));
        $retryOnly = (bool) $this->option('retry-only');
        $canonicalGapOnly = (bool) $this->option('canonical-gap');
        $metricsOnly = (bool) $this->option('metrics-only');
        $reconcileInterrupted = (bool) $this->option('reconcile-interrupted');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $triggerSource = (string) ($this->option('trigger-source') ?: 'NATURAL_SCHEDULE');

        if ($reconcileInterrupted && ! $targetSession) {
            $this->error('--reconcile-interrupted requires --session=YYYY-MM-DD');

            return self::FAILURE;
        }

        if ($metricsOnly && $targetSession === null) {
            $this->error('--metrics-only requires --session=YYYY-MM-DD');

            return self::FAILURE;
        }

        $tickers = $tickerOpt
            ? array_filter(array_map('trim', explode(',', strtoupper($tickerOpt))))
            : null;

        // No-session EOD always uses calendar resolver. Recovery must pass explicit --session.
        // This prevents a stale recovery queue from hijacking the regular EOD target.

        $isTradingDay = $calendar->isTradingDay($sessionDate);
        $sessionHasClosed = $calendar->sessionHasClosed($sessionDate);
        $canRegisterQueue = $calendar->canRegisterQueue($sessionDate);
        $canFetchEodNow = $calendar->canFetchEodNow($sessionDate);
        $calendarReason = $calendar->calendarReason($sessionDate);

        if ($this->option('dry-run')) {
            $existingQueue = EodSessionQueue::where('session_date', $sessionDate)->first();
            $this->line('DRY_RUN session_date: '.$sessionDate);
            $this->line('DRY_RUN is_trading_day: '.($isTradingDay ? 'true' : 'false'));
            $this->line('DRY_RUN session_has_closed: '.($sessionHasClosed ? 'true' : 'false'));
            $this->line('DRY_RUN can_register_queue: '.($canRegisterQueue ? 'true' : 'false'));
            $this->line('DRY_RUN can_fetch_eod_now: '.($canFetchEodNow ? 'true' : 'false'));
            $this->line('DRY_RUN calendar_reason: '.($calendarReason ?? 'NONE'));
            $this->line('DRY_RUN queue_exists: '.($existingQueue ? 'true' : 'false'));
            $this->line('DRY_RUN queue_status: '.($existingQueue?->status ?? 'NOT_CREATED'));
            $this->line('DRY_RUN would_register_pending: '.($canRegisterQueue && ! $existingQueue ? 'true' : 'false'));

            return self::SUCCESS;
        }

        if ($reconcileInterrupted) {
            return $this->reconcileInterruptedQueue($sessionDate);
        }

        if (! $canRegisterQueue) {
            $this->warn("Sesi {$sessionDate} tidak terdaftar sebagai hari bursa: {$calendarReason}.");

            return self::SUCCESS;
        }

        // 1. Persistent Session Queue registration before attempting lock

        $queueItem = EodSessionQueue::firstOrCreate(
            ['session_date' => $sessionDate],
            [
                'status' => 'PENDING',
                'priority' => 1,
                'target_tickers' => $tickers,
                'attempts' => 0,
            ]
        );

        if (! $canFetchEodNow) {
            $this->info("Sesi {$sessionDate} terdaftar PENDING; fetch EOD ditahan sampai cutoff 16:05 WIB.");

            return self::SUCCESS;
        }

        // 2. Lock Acquisition with Lease Check (Cache Lock + Heartbeat Timeout)
        $lockKey = 'hermes:lock:refresh-eod';
        $lock = Cache::lock($lockKey, 300);
        if (! $lock->get()) {
            $lockOwnerPid = (int) Cache::get('hermes:lock:refresh-eod:pid', 0);
            $isAlive = false;
            if ($lockOwnerPid > 0) {
                $output = shell_exec("tasklist /FI \"PID eq {$lockOwnerPid}\" 2>nul");
                if ($output && str_contains($output, (string) $lockOwnerPid)) {
                    $isAlive = true;
                }
            }

            // Check if queue heartbeat is stale (older than 5 minutes) even if PID lookalike exists
            $processingItem = EodSessionQueue::where('status', 'PROCESSING')
                ->where('session_date', $sessionDate)
                ->first();
            $heartbeatStale = $processingItem && $processingItem->last_heartbeat_at && $processingItem->last_heartbeat_at->diffInMinutes(now('UTC')) >= 5;

            if ($isAlive && ! $heartbeatStale) {
                $queueItem->update([
                    'status' => 'QUEUED_WAITING_LOCK',
                    'lock_owner_pid' => $lockOwnerPid,
                    'last_error' => "Proses refresh EOD lain sedang aktif pada PID {$lockOwnerPid}.",
                ]);
                $this->warn("Proses refresh EOD lain sedang aktif pada PID {$lockOwnerPid}. Sesi {$sessionDate} diamankan di antrean persisten (QUEUED_WAITING_LOCK).");

                return 0;
            }

            $staleReason = $heartbeatStale ? "Heartbeat usang (>5m) pada PID {$lockOwnerPid}" : "PID {$lockOwnerPid} tidak aktif";
            $this->info("Stale lock terdeteksi ({$staleReason}). Mengambil alih lock...");
            Cache::lock($lockKey)->forceRelease();
            $lock = Cache::lock($lockKey, 300);
            $lock->get();
        }

        $myPid = getmypid();
        $leaseToken = 'lease_'.bin2hex(random_bytes(16)).'_pid'.$myPid;
        Cache::put('hermes:lock:refresh-eod:pid', $myPid, 300);
        Cache::put('hermes:lock:refresh-eod:owner_token', $leaseToken, 300);

        $queueItem->update([
            'status' => 'PROCESSING',
            'lock_owner_pid' => $myPid,
            'lease_owner_token' => $leaseToken,
            'started_at' => now('UTC'),
            'last_heartbeat_at' => now('UTC'),
            'attempts' => $queueItem->attempts + 1,
            'last_error' => null,
            'stats' => array_merge($queueItem->stats ?? [], [
                'owner_process_created_at' => $this->processCreationTime($myPid),
                'lease_started_at_utc' => now('UTC')->toIso8601String(),
            ]),
        ]);

        try {
            $targetDesc = $canonicalGapOnly ? 'canonical gap' : ($retryOnly ? 'pending retry queue' : ($tickers ? implode(', ', $tickers) : 'all active BEI equities'));
            $this->info("Starting Daily EOD Refresh for session {$sessionDate} ({$targetDesc}, Overlap: {$overlap} bars)...");

            // Heartbeat callback: verifies lock ownership, renews cache lock & updates queue heartbeat on each ticker
            $heartbeatCallback = function (string $currentTicker, int $total, int $processed) use ($queueItem, $leaseToken) {
                // Heartbeat update on DB queue
                $queueItem->update([
                    'last_heartbeat_at' => now('UTC'),
                    'stats' => array_merge($queueItem->stats ?? [], [
                        'current_ticker' => $currentTicker,
                        'total_eligible' => $total,
                        'processed_count' => $processed,
                        'last_heartbeat_wib' => now('Asia/Jakarta')->toIso8601String(),
                    ]),
                ]);

                // Renew cache lease
                Cache::put('hermes:lock:refresh-eod:owner_token', $leaseToken, 300);
                Cache::put('hermes:lock:refresh-eod:pid', getmypid(), 300);
            };

            $result = $refreshService->refreshDailyEod($tickers, $overlap, $syncBroker, $retryOnly, $sessionDate, $limit, $heartbeatCallback, $metricsOnly, $canonicalGapOnly);

            $this->info("EOD Refresh completed for session: {$result['expected_session_date']}");
            $this->line("- Total eligible tickers: {$result['total_eligible']}");
            $this->line("- Already fresh: {$result['already_fresh']}");
            $this->line("- Updated: {$result['updated']}");
            $this->line("- Waiting for session data: {$result['waiting_for_session']}");
            $this->line("- Fetch errors: {$result['failed']}");
            $this->line("- Total new bars ingested: {$result['total_new_bars_ingested']}");
            $this->line("- Metrics updated: {$result['metrics_updated']}");
            $this->line("- Metrics insufficient input: {$result['metrics_insufficient_input']}");
            $this->line("- Metrics failed: {$result['metrics_failed']}");
            $this->line('- Execution complete: '.($result['execution_complete'] ? 'YES' : 'NO'));
            $this->line('- Selected scope complete: '.($result['selected_scope_complete'] ? 'YES' : 'NO'));
            $this->line('- Universe data coverage complete: '.($result['universe_data_coverage_complete'] ? 'YES' : 'NO (Universe incomplete)'));
            $this->line('- Data coverage complete: '.($result['data_coverage_complete'] ? 'YES' : 'NO (Universe incomplete)'));

            if (! empty($result['backlog_tickers'])) {
                $this->warn('Backlog sample: '.implode(', ', $result['backlog_tickers']));
            }

            $semanticStatus = $result['failed'] > 0
                ? 'FAILED'
                : (($result['waiting_for_session'] > 0 || ! $result['data_coverage_complete']) ? 'WAITING' : 'COMPLETE');
            $semanticExitCode = $semanticStatus === 'COMPLETE' ? 0 : ($semanticStatus === 'WAITING' ? 75 : 1);
            $hasProviderFailure = collect($result['retry_evidence'] ?? [])
                ->contains(function (array $evidence): bool {
                    foreach (['adjusted_request', 'raw_request'] as $requestKey) {
                        if (str_starts_with((string) ($evidence[$requestKey]['error_type'] ?? ''), 'PROVIDER_')) {
                            return true;
                        }
                    }

                    return false;
                });
            $hasProviderWaiting = (int) $result['waiting_for_session'] > 0;
            $hasBacklogRemaining = ! $result['data_coverage_complete'];
            $semanticReasons = [];
            if (! $result['selected_scope_complete']) {
                $semanticReasons[] = 'SELECTED_SCOPE_INCOMPLETE';
            }
            if ($hasBacklogRemaining) {
                $semanticReasons[] = 'BACKLOG_REMAINING';
            }
            if ($hasProviderWaiting) {
                $semanticReasons[] = 'PROVIDER_WAITING_OBSERVED';
            }
            if ($hasProviderFailure) {
                $semanticReasons[] = 'PROVIDER_FAILURE';
            }
            if ($semanticReasons === []) {
                $semanticReasons[] = 'ALL_REPORTED_GATES_PASS';
            }
            $semanticReason = $result['failed'] > 0
                ? ($hasProviderFailure ? 'PROVIDER_FAILURE' : 'INTERNAL_FAILURE')
                : ($hasProviderWaiting
                    ? 'PROVIDER_WAITING_OBSERVED'
                    : ($hasBacklogRemaining ? 'BACKLOG_REMAINING' : 'ALL_REPORTED_GATES_PASS'));
            $universeRemaining = (int) ($result['universe_remaining'] ?? 0);
            $this->line("EOD child_semantic_status={$semanticStatus} exit_code={$semanticExitCode} reason={$semanticReason}");
            $this->line('EOD semantic_reasons='.implode(',', $semanticReasons));
            $this->line('EOD summary selected_scope_complete='.($result['selected_scope_complete'] ? 'true' : 'false')." updated={$result['updated']} waiting={$result['waiting_for_session']} failed={$result['failed']} universe_remaining={$universeRemaining} semantic_reason={$semanticReason} exit_code={$semanticExitCode}");
            $queueStatus = $semanticStatus === 'COMPLETE'
                ? 'COMPLETED'
                : ($semanticStatus === 'WAITING' ? 'PENDING' : 'FAILED');

            $stats = array_merge($queueItem->stats ?? [], [
                'expected_session_date' => $result['expected_session_date'],
                'trigger_source' => $triggerSource,
                'total_eligible' => $result['total_eligible'],
                'already_fresh' => $result['already_fresh'],
                'updated' => $result['updated'],
                'waiting_for_session' => $result['waiting_for_session'],
                'failed' => $result['failed'],
                'bars_ingested' => $result['total_new_bars_ingested'],
                'metrics_updated' => $result['metrics_updated'],
                'metrics_insufficient_input' => $result['metrics_insufficient_input'],
                'metrics_failed' => $result['metrics_failed'],
                'metric_evidence' => $result['metric_evidence'],
                'execution_complete' => $result['execution_complete'],
                'selected_scope_complete' => $result['selected_scope_complete'],
                'data_coverage_complete' => $result['data_coverage_complete'],
                'universe_data_coverage_complete' => $result['universe_data_coverage_complete'],
                'universe_fully_fresh' => $result['universe_fully_fresh'],
                'backlog_count' => $result['backlog_count'],
            ]);
            $stats = $this->mergeRetryEvidenceStats(
                $stats,
                $result['retry_evidence'],
                (int) $queueItem->attempts,
                now('UTC')->toIso8601String(),
            );
            $queueItem->update([
                'status' => $queueStatus,
                'completed_at' => $semanticStatus === 'COMPLETE' ? now('UTC') : null,
                'lock_owner_pid' => null,
                'lease_owner_token' => null,
                'stats' => $stats,
            ]);
            $this->line('EOD retry_evidence_receipt='.json_encode([
                'session_date' => $result['expected_session_date'],
                'queue_attempt' => (int) $queueItem->attempts,
                'retry_evidence' => $result['retry_evidence'],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $semanticExitCode;
        } catch (\Throwable $e) {
            $queueItem->update([
                'status' => 'FAILED',
                'last_error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            // Guard release: only release lock if this process STILL owns the lock by owner token
            $currentOwnerToken = (string) Cache::get('hermes:lock:refresh-eod:owner_token', '');
            if ($currentOwnerToken === $leaseToken) {
                Cache::forget('hermes:lock:refresh-eod:owner_token');
                Cache::forget('hermes:lock:refresh-eod:pid');
                $lock->release();
            }

            // 3. Process next queued session if waiting
            $nextQueued = EodSessionQueue::whereIn('status', ['PENDING', 'QUEUED_WAITING_LOCK'])
                ->orderBy('priority', 'desc')
                ->orderBy('session_date', 'asc')
                ->first();

            if ($nextQueued && $nextQueued->id !== $queueItem->id && ! $limit) {
                $this->info("Menemukan sesi tertunda di antrean persisten ({$nextQueued->session_date->toDateString()}). Memulai pemrosesan otomatis...");
                $this->call('hermes:refresh-eod', [
                    '--session' => $nextQueued->session_date->toDateString(),
                    '--broker' => $syncBroker,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeRetryEvidenceStats(array $stats, array $incoming, int $queueAttempt, string $recordedAt): array
    {
        $stats['retry_evidence'] = array_replace($stats['retry_evidence'] ?? [], $incoming);
        $history = $stats['retry_evidence_history'] ?? [];
        if ($incoming !== []) {
            $history[] = [
                'queue_attempt' => $queueAttempt,
                'recorded_at' => $recordedAt,
                'evidence' => $incoming,
            ];
        }
        $stats['retry_evidence_history'] = $history;

        return $stats;
    }

    /**
     * Reconcile one abandoned lease without fetching provider data.
     */
    private function reconcileInterruptedQueue(string $sessionDate): int
    {
        $queueItem = EodSessionQueue::where('session_date', $sessionDate)
            ->whereIn('status', ['PROCESSING', 'PENDING'])
            ->whereNotNull('lease_owner_token')
            ->latest('id')
            ->first();

        if (! $queueItem) {
            $this->line("EOD_RECONCILE status=NO_STALE_LEASE session={$sessionDate}");

            return self::SUCCESS;
        }

        $originalStatus = (string) $queueItem->status;
        $stats = is_array($queueItem->stats) ? $queueItem->stats : [];
        $leaseToken = (string) ($queueItem->lease_owner_token ?? '');
        $lastHeartbeat = $queueItem->last_heartbeat_at;
        $stale = $lastHeartbeat !== null && $lastHeartbeat->copy()->setTimezone('UTC')->lte(now('UTC')->subMinutes(5));
        $cachedToken = (string) Cache::get('hermes:lock:refresh-eod:owner_token', '');
        $ownerPid = (int) ($queueItem->lock_owner_pid ?? 0);
        $ownerProcess = $this->processDetails($ownerPid);

        if ($leaseToken === '' || ($cachedToken !== '' && $cachedToken !== $leaseToken) || ! $stale || $ownerProcess['alive']) {
            $this->error('EOD_RECONCILE_ABORT owner active, lease current, or token mismatch');

            return self::FAILURE;
        }

        if ($originalStatus === 'PENDING') {
            $updated = EodSessionQueue::whereKey($queueItem->id)
                ->where('status', 'PENDING')
                ->where('lease_owner_token', $leaseToken)
                ->where('last_heartbeat_at', '<=', now('UTC')->subMinutes(5))
                ->update([
                    'lock_owner_pid' => null,
                    'lease_owner_token' => null,
                    'last_error' => 'ORPHANED_LEASE_CLEARED: owner process absent; queue remains PENDING.',
                    'updated_at' => now('UTC'),
                ]);

            if ($updated !== 1) {
                $this->error('EOD_RECONCILE_ABORT queue changed during compare-and-swap');

                return self::FAILURE;
            }

            Cache::forget('hermes:lock:refresh-eod:owner_token');
            Cache::forget('hermes:lock:refresh-eod:pid');
            $this->line("EOD_RECONCILE status=ORPHANED_LEASE_CLEARED session={$sessionDate} queue_id={$queueItem->id} queue_status=PENDING checkpoint_preserved=true");

            return self::SUCCESS;
        }

        $reconciledAt = now('UTC')->toIso8601String();
        $checkpoint = array_merge($stats, [
            'recovery_status' => 'INTERRUPTED_RUN_RECONCILED',
            'recovery_run_id' => $stats['run_id'] ?? null,
            'recovery_reconciled_at_utc' => $reconciledAt,
            'recovery_owner_pid' => $ownerPid,
            'recovery_owner_process_created_at' => $stats['owner_process_created_at'] ?? null,
            'recovery_last_heartbeat_at' => $lastHeartbeat?->toIso8601String(),
            'recovery_owner_alive' => false,
            'recovery_checkpoint_preserved' => true,
        ]);

        $updated = EodSessionQueue::whereKey($queueItem->id)
            ->where('status', 'PROCESSING')
            ->where('lease_owner_token', $leaseToken)
            ->where('last_heartbeat_at', '<=', now('UTC')->subMinutes(5))
            ->update([
                'status' => 'FAILED',
                'last_error' => 'INTERRUPTED_RUN: owner process absent after stale lease; checkpoint preserved.',
                'stats' => $checkpoint,
                'lock_owner_pid' => null,
                'lease_owner_token' => null,
                'updated_at' => now('UTC'),
            ]);

        if ($updated !== 1) {
            $this->error('EOD_RECONCILE_ABORT queue changed during compare-and-swap');

            return self::FAILURE;
        }

        Cache::forget('hermes:lock:refresh-eod:owner_token');
        Cache::forget('hermes:lock:refresh-eod:pid');
        Cache::lock('hermes:lock:refresh-eod')->forceRelease();
        $this->line("EOD_RECONCILE status=INTERRUPTED_RUN_RECONCILED session={$sessionDate} queue_id={$queueItem->id} checkpoint_preserved=true");

        return self::SUCCESS;
    }

    /**
     * @return array{alive: bool, creation_date: string}
     */
    private function processDetails(int $pid): array
    {
        if ($pid <= 0 || PHP_OS_FAMILY !== 'Windows') {
            return ['alive' => false, 'creation_date' => ''];
        }

        $filter = 'ProcessId='.$pid;
        $command = 'powershell.exe -NoProfile -NonInteractive -Command '.escapeshellarg("Get-CimInstance Win32_Process -Filter '$filter' | Select-Object ProcessId,CreationDate | ConvertTo-Json -Compress");
        $raw = trim((string) shell_exec($command));
        if ($raw === '') {
            return ['alive' => false, 'creation_date' => ''];
        }

        $details = json_decode($raw, true);
        if (! is_array($details) || (int) ($details['ProcessId'] ?? 0) !== $pid) {
            return ['alive' => false, 'creation_date' => ''];
        }

        return ['alive' => true, 'creation_date' => (string) ($details['CreationDate'] ?? '')];
    }

    private function processCreationTime(int $pid): string
    {
        return $this->processDetails($pid)['creation_date'];
    }
}
