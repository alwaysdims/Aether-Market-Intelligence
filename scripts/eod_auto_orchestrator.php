<?php

date_default_timezone_set('Asia/Jakarta');

function eod_orchestrator_manifest_status(int $exitCode, ?array $manifest): array
{
    if ($exitCode === 10 && ($manifest['status'] ?? null) === 'NO_GAP') {
        return ['action' => 'COMPLETE', 'reason' => 'ALL_CANONICAL_GAPS_CLOSED', 'remaining' => 0];
    }
    if ($exitCode === 11 && ($manifest['status'] ?? null) === 'NO_ELIGIBLE_GAP') {
        return [
            'action' => 'WAITING',
            'reason' => 'DEFERRED_OR_EXHAUSTED_GAPS',
            'remaining' => (int) ($manifest['total_gap_count'] ?? 0),
        ];
    }

    return ['action' => 'PROCESS', 'reason' => null, 'remaining' => null];
}

function eod_orchestrator_next_action(array $result, int $remainingSeconds): string
{
    if ($remainingSeconds < 120) {
        return 'STOP_BUDGET';
    }
    if ((int) ($result['failed'] ?? 0) > 0) {
        return 'STOP_FAILURE';
    }
    if ((int) ($result['remaining'] ?? 0) === 0) {
        return 'COMPLETE';
    }
    if ((int) ($result['updated'] ?? 0) > 0) {
        return 'CONTINUE';
    }
    if ((int) ($result['waiting'] ?? 0) > 0) {
        return 'STOP_PROVIDER_WAITING';
    }
    if ((int) ($result['updated'] ?? 0) <= 0) {
        return 'STOP_NO_PROGRESS';
    }
    return ((int) ($result['remaining'] ?? 0) === 0) ? 'COMPLETE' : 'CONTINUE';
}

function eod_orchestrator_parse_summary(string $stdout): array
{
    $summaries = eod_orchestrator_parse_batch_summaries($stdout);
    return $summaries === []
        ? ['updated' => 0, 'waiting' => 0, 'failed' => 1, 'remaining' => null, 'semantic_reason' => 'NO_SUMMARY']
        : $summaries[array_key_last($summaries)];
}

function eod_orchestrator_parse_batch_summaries(string $stdout): array
{
    $pattern = '/EOD summary selected_scope_complete=(true|false) .*?updated=(\d+) .*?waiting=(\d+) .*?failed=(\d+) .*?universe_remaining=(\d+) .*?semantic_reason=([A-Z_,]+) exit_code=(\d+)/';
    preg_match_all($pattern, $stdout, $matches, PREG_SET_ORDER);
    return array_map(static fn (array $m): array => [
        'selected_scope_complete' => $m[1] === 'true',
        'updated' => (int) $m[2],
        'waiting' => (int) $m[3],
        'failed' => (int) $m[4],
        'remaining' => (int) $m[5],
        'semantic_reason' => $m[6],
        'exit_code' => (int) $m[7],
    ], $matches);
}

function eod_orchestrator_parse_batch_counts(string $stdout): array
{
    $pattern = '/- Total eligible tickers: (\d+).*?- Already fresh: (\d+).*?- Updated: (\d+)/s';
    preg_match_all($pattern, $stdout, $matches, PREG_SET_ORDER);
    return array_map(static fn (array $m): array => [
        'attempted' => (int) $m[1],
        'already_fresh' => (int) $m[2],
        'updated' => (int) $m[3],
    ], $matches);
}

function eod_orchestrator_parse_retry_evidence_receipt(string $stdout): ?array
{
    $receipt = null;
    foreach (preg_split('/\R/', trim($stdout)) as $line) {
        if (! str_starts_with($line, 'EOD retry_evidence_receipt=')) {
            continue;
        }
        $decoded = json_decode(substr($line, strlen('EOD retry_evidence_receipt=')), true);
        if (is_array($decoded)) {
            $receipt = $decoded;
        }
    }

    return $receipt;
}

function eod_orchestrator_parse_json_line(string $stdout): ?array
{
    foreach (array_reverse(preg_split('/\R/', trim($stdout))) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
}

function eod_orchestrator_run(array $command, string $cwd, int $timeoutSeconds = 300): array
{
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (! is_resource($process)) {
        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'process_open_failed', 'timed_out' => false];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeoutSeconds;
    $timedOut = false;
    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (! $status['running']) {
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(250000);
    } while (true);
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return ['exit_code' => $timedOut ? 124 : $exitCode, 'stdout' => $stdout, 'stderr' => $stderr, 'timed_out' => $timedOut];
}

function eod_orchestrator_write_json(string $path, array $payload): void
{
    $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));
    file_put_contents($temporary, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX);
    rename($temporary, $path);
}

function eod_orchestrator_extract_batch_tickers(string $manifestPath): array
{
    $data = json_decode((string) file_get_contents($manifestPath), true);
    return is_array($data) && is_array($data['tickers'] ?? null) ? array_values($data['tickers']) : [];
}

function eod_orchestrator_source_hashes(string $root): array
{
    $files = [
        'runner' => $root.'/scripts/run_guarded_eod.ps1',
        'orchestrator' => $root.'/scripts/eod_auto_orchestrator.php',
        'writer' => $root.'/scripts/launch_ingestion_writer.php',
        'manifest_builder' => $root.'/scripts/build_gap_batch_manifest_v2.php',
        'gap_selector' => $root.'/scripts/eod_gap_selector.php',
        'classifier' => $root.'/scripts/eod_result_classifier.php',
        'eod_service' => $root.'/dashboard/app/Services/DailyEodRefreshService.php',
        'provider_client' => $root.'/dashboard/app/Services/IdxEdgeClient.php',
    ];
    $hashes = [];
    foreach ($files as $name => $path) {
        if (!is_file($path)) {
            $hashes[$name] = null;
            continue;
        }
        $hashes[$name] = hash_file('sha256', $path);
    }
    return $hashes;
}

function eod_orchestrator_select_prior_pending_session(array $targets, string $currentSession): ?string
{
    $sessions = [];
    foreach ($targets as $target) {
        $session = (string) ($target['target_session'] ?? '');
        $status = (string) ($target['queue_status'] ?? '');
        $needsRecovery = array_key_exists('needs_recovery', $target) ? (bool) $target['needs_recovery'] : true;
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $session)
            && $session < $currentSession
            && $needsRecovery
            && in_array($status, ['PENDING', 'QUEUED_WAITING_LOCK'], true)) {
            $sessions[] = $session;
        }
    }
    rsort($sessions);
    return $sessions[0] ?? null;
}

function eod_orchestrator_prior_pending_session(string $php, string $writer, string $root, string $currentSession): ?string
{
    $preview = eod_orchestrator_run([$php, $writer, '--list-target-sessions'], $root, 30);
    if ($preview['exit_code'] !== 0) {
        return null;
    }
    $data = eod_orchestrator_parse_json_line($preview['stdout']);
    return is_array($data) && is_array($data['targets'] ?? null)
        ? eod_orchestrator_select_prior_pending_session($data['targets'], $currentSession)
        : null;
}

function eod_orchestrator_canonical_audit(string $root, string $session, array $tickers): array
{
    $command = [PHP_BINARY, $root.'/scripts/audit_eod_batch_persistence.php', '--session='.$session, '--tickers='.implode(',', $tickers)];
    $pipes = [];
    $process = proc_open($command, [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, $root);
    if (!is_resource($process)) return ['exit_code'=>127, 'stdout'=>'', 'stderr'=>'audit_process_open_failed'];
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['exit_code'=>proc_close($process), 'stdout'=>$stdout, 'stderr'=>$stderr];
}

function eod_orchestrator_main(array $argv): int
{
    $root = dirname(__DIR__);
    $php = PHP_BINARY;
    $writer = $root.'/scripts/launch_ingestion_writer.php';
    $manifestBuilder = $root.'/scripts/build_gap_batch_manifest_v2.php';
    $runDir = $root.'/storage/runtime/eod-runs';
    $session = null;
    $rpm = 30;
    $maxBatches = 3;
    $budgetSeconds = 1680;
    $triggerSource = 'TASK_SCHEDULER_EOD';
    $scheduledAt = date(DATE_ATOM);
    $parentRunId = null;
    $continuationDepth = 0;
    $planOnly = false;

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--session=')) $session = substr($arg, 10);
        if (str_starts_with($arg, '--rpm=')) $rpm = (int) substr($arg, 6);
        if (str_starts_with($arg, '--max-batches=')) $maxBatches = (int) substr($arg, 14);
        if (str_starts_with($arg, '--budget-seconds=')) $budgetSeconds = (int) substr($arg, 17);
        if (str_starts_with($arg, '--trigger-source=')) $triggerSource = substr($arg, 17);
        if (str_starts_with($arg, '--scheduled-at=')) $scheduledAt = substr($arg, 15);
        if (str_starts_with($arg, '--parent-run-id=')) $parentRunId = substr($arg, 16);
        if (str_starts_with($arg, '--continuation-depth=')) $continuationDepth = (int) substr($arg, 22);
        if ($arg === '--plan-only') $planOnly = true;
    }
    if ($rpm < 1 || $rpm > 30 || $maxBatches < 1 || $maxBatches > 20 || $budgetSeconds < 120) {
        fwrite(STDERR, "invalid orchestrator bounds\n");
        return 2;
    }
    if ($session !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $session)) {
        fwrite(STDERR, "invalid session format\n");
        return 2;
    }
    foreach ([$writer, $manifestBuilder] as $dependency) {
        if (! is_file($dependency)) {
            fwrite(STDERR, "orchestrator dependency missing: {$dependency}\n");
            return 3;
        }
    }
    if (! is_dir($runDir)) mkdir($runDir, 0700, true);

    $runId = date('Ymd_His').'auto_'.bin2hex(random_bytes(6));
    $startedAt = microtime(true);
    $checkpoint = $runDir.'/'.$runId.'.checkpoint.json';
    $state = [
        'status' => 'RUNNING', 'run_id' => $runId, 'parent_run_id' => $parentRunId, 'scheduled_at' => $scheduledAt,
        'trigger_source' => $triggerSource, 'timezone' => 'Asia/Jakarta',
        'source_hashes' => eod_orchestrator_source_hashes($root),
        'target_session' => null, 'resolved_session' => null, 'database' => getenv('AETHER_ACTIVE_DATABASE') ?: 'project_bot_recovered_20261115_022935',
        'role' => getenv('AETHER_WRITER_ROLE') ?: 'aether_ingestion', 'rate_limit_rpm' => $rpm,
        'budget_seconds' => $budgetSeconds, 'max_batches' => $maxBatches, 'batch_results' => [],
    ];

    if ($session === null) {
        $dry = eod_orchestrator_run([$php, $writer, '--dry-run'], $root, 30);
        $session = preg_match('/DRY_RUN session_date:\s*(\d{4}-\d{2}-\d{2})/', $dry['stdout'], $m) ? $m[1] : null;
        if ($dry['exit_code'] !== 0 || $session === null) {
            $state['status'] = 'FAILED'; $state['reason'] = 'SESSION_RESOLUTION_FAILED';
            eod_orchestrator_write_json($checkpoint, $state);
            fwrite(STDERR, $dry['stderr']);
            return 1;
        }
    }
    $state['target_session'] = $session;
    $state['resolved_session'] = $session;
    eod_orchestrator_write_json($checkpoint, $state);

    if ($planOnly) {
        $manifest = eod_orchestrator_run([$php, $manifestBuilder, '0', $runId.'_plan', $session], $root);
        echo $manifest['stdout'];
        fwrite(STDERR, $manifest['stderr']);
        $state['status'] = $manifest['exit_code'] === 10 ? 'NO_GAP' : ($manifest['exit_code'] === 0 ? 'PLAN_READY' : 'FAILED');
        $state['plan_exit_code'] = $manifest['exit_code'];
        $state['plan'] = eod_orchestrator_parse_json_line($manifest['stdout']);
        eod_orchestrator_write_json($checkpoint, $state);
        return $manifest['exit_code'] === 10 ? 0 : ($manifest['exit_code'] === 0 ? 0 : 1);
    }

    $attempted = 0; $alreadyFresh = 0; $updated = 0; $canonicalVerified = 0; $metricVerified = 0; $waiting = 0; $failed = 0; $remaining = null; $selectedComplete = true;
    $stopReason = 'BACKLOG_REMAINING'; $action = 'CONTINUE';
    $batchResults = [];
    for ($batch = 1; $batch <= $maxBatches; $batch++) {
        $remainingBudget = $budgetSeconds - (int) (microtime(true) - $startedAt);
        if ($remainingBudget < 120) { $action = 'STOP_BUDGET'; $stopReason = 'BUDGET_NEAR_EXHAUSTION'; break; }
        $manifest = eod_orchestrator_run([$php, $manifestBuilder, '0', $runId.'_batch'.str_pad((string) $batch, 2, '0', STR_PAD_LEFT), $session], $root, 30);
        $manifestData = eod_orchestrator_parse_json_line($manifest['stdout']);
        $manifestStatus = eod_orchestrator_manifest_status($manifest['exit_code'], $manifestData);
        if ($manifestStatus['action'] === 'COMPLETE') {
            $remaining = 0; $action = 'COMPLETE'; $stopReason = $manifestStatus['reason']; break;
        }
        if ($manifestStatus['action'] === 'WAITING') {
            $remaining = $manifestStatus['remaining']; $action = 'STOP_DEFERRED'; $stopReason = $manifestStatus['reason']; break;
        }
        if ($manifest['exit_code'] !== 0 || ! is_array($manifestData) || ($manifestData['status'] ?? null) !== 'READY') {
            $failed++; $action = 'STOP_FAILURE'; $stopReason = 'MANIFEST_FAILURE'; break;
        }
        $tickers = $manifestData['tickers'] ?? [];
        $count = count($tickers);
        $manifestPath = $manifestData['path'] ?? null;
        $manifestFile = is_string($manifestPath) ? str_replace('\\/', '/', $manifestPath) : null;
        $manifestFileData = $manifestFile && is_file($manifestFile) ? json_decode((string) file_get_contents($manifestFile), true) : null;
        if ($count < 1 || $count > 20 || count(array_unique($tickers)) !== $count || ($manifestFileData['target_session'] ?? null) !== $session) {
            $failed++; $action = 'STOP_FAILURE'; $stopReason = 'MANIFEST_CONTRACT_FAILURE'; break;
        }
        $result = eod_orchestrator_run([
            $php, $writer, '--batch', '--session='.$session, '--limit='.$count, '--rpm='.$rpm,
            '--canonical-gap', '--trigger-source='.$triggerSource, '--tickers='.implode(',', $tickers),
        ], $root.'/dashboard', max(120, min(600, $remainingBudget - 60)));
        $base = $runDir.'/'.$runId.'.batch'.str_pad((string) $batch, 2, '0', STR_PAD_LEFT);
        file_put_contents($base.'.stdout.log', $result['stdout']);
        file_put_contents($base.'.stderr.log', $result['stderr']);
        echo $result['stdout']; fwrite(STDERR, $result['stderr']);
        $summary = $result['timed_out']
            ? ['selected_scope_complete' => false, 'updated' => 0, 'waiting' => 0, 'failed' => 1, 'remaining' => null, 'semantic_reason' => 'BATCH_TIMEOUT', 'exit_code' => 124]
            : eod_orchestrator_parse_summary($result['stdout']);
        $batchCounts = eod_orchestrator_parse_batch_counts($result['stdout']);
        $summary['attempted'] = $batchCounts[array_key_last($batchCounts)]['attempted'] ?? $count;
        $summary['already_fresh'] = $batchCounts[array_key_last($batchCounts)]['already_fresh'] ?? 0;
        $summary['retry_evidence_receipt'] = eod_orchestrator_parse_retry_evidence_receipt($result['stdout']);
        $summary['batch'] = $batch; $summary['manifest_path'] = $manifestData['path'] ?? null; $summary['timed_out'] = $result['timed_out'];
        $summary['run_id'] = $runId; $summary['resolved_session'] = $session; $summary['database'] = $state['database']; $summary['role'] = $state['role']; $summary['schema'] = 'public';
        $summary['manifest_builder_command'] = "php scripts/build_gap_batch_manifest_v2.php 0 {$runId}_batch".str_pad((string) $batch, 2, '0', STR_PAD_LEFT)." {$session}";
        $summary['writer_command'] = "php scripts/launch_ingestion_writer.php --batch --session={$session} --limit={$count} --rpm={$rpm} --canonical-gap --trigger-source={$triggerSource} --tickers=".implode(',', $tickers);
        $summary['stdout_path'] = $base.'.stdout.log'; $summary['stderr_path'] = $base.'.stderr.log';
        $audit = eod_orchestrator_canonical_audit($root, $session, $tickers);
        $auditData = eod_orchestrator_parse_json_line($audit['stdout']);
        $summary['canonical_audit_path'] = $base.'.canonical-audit.json';
        file_put_contents($summary['canonical_audit_path'], $audit['stdout']);
        $summary['canonical_readback'] = $auditData;
        $summary['canonical_readback_complete'] = $audit['exit_code'] === 0 && (($auditData['canonical_complete'] ?? false) === true);
        if (($summary['selected_scope_complete'] ?? false) === true && !$summary['canonical_readback_complete']) {
            $summary['failed'] = max(1, (int) $summary['failed']);
            $summary['semantic_reason'] = 'INTERNAL_VERIFICATION_FAILED';
            $summary['exit_code'] = 1;
            $state['batch_results'][] = $summary; eod_orchestrator_write_json($checkpoint, $state);
            $batchResults[] = $summary;
            $attempted += $summary['attempted']; $alreadyFresh += $summary['already_fresh']; $updated += $summary['updated'];
            $failed++; $selectedComplete = false; $action = 'STOP_FAILURE'; $stopReason = 'INTERNAL_VERIFICATION_FAILED';
            break;
        }
        $state['batch_results'][] = $summary; eod_orchestrator_write_json($checkpoint, $state);
        $batchResults[] = $summary;
        $attempted += $summary['attempted']; $alreadyFresh += $summary['already_fresh']; $updated += $summary['updated'];
        $canonicalVerified += (int) ($auditData['canonical_complete_count'] ?? 0);
        $metricVerified += (int) ($auditData['metric_match_count'] ?? 0);
        $waiting += $summary['waiting']; $failed += $summary['failed'];
        $remaining = $auditData['remaining_gap_count'] ?? $summary['remaining']; $selectedComplete = $selectedComplete && ($summary['selected_scope_complete'] ?? false) && $summary['canonical_readback_complete'];
        $action = eod_orchestrator_next_action($summary, $budgetSeconds - (int) (microtime(true) - $startedAt));
        if ($action !== 'CONTINUE') {
            $stopReason = match ($action) {
                'COMPLETE' => 'ALL_CANONICAL_GAPS_CLOSED', 'STOP_PROVIDER_WAITING' => 'PROVIDER_WAITING_OBSERVED',
                'STOP_FAILURE' => 'INTERNAL_OR_PROVIDER_FAILURE', 'STOP_NO_PROGRESS' => 'NO_PROGRESS',
                'STOP_BUDGET' => 'BUDGET_NEAR_EXHAUSTION', default => 'BACKLOG_REMAINING',
            };
            break;
        }
    }
    $remaining ??= 0;
    $state['status'] = $action === 'COMPLETE' ? 'COMPLETE' : 'WAITING';
    $state['completed_at'] = date(DATE_ATOM); $state['stop_reason'] = $stopReason;
    $exit = $action === 'COMPLETE' ? 0 : ($failed > 0 ? 1 : 75);
    $state['aggregate'] = [
        'selected_scope_complete' => $selectedComplete,
        'batch_count' => count($batchResults),
        'attempted' => $attempted,
        'already_fresh' => $alreadyFresh,
        'updated' => $updated,
        'canonical_verified' => $canonicalVerified,
        'metric_verified' => $metricVerified,
        'waiting' => $waiting,
        'failed' => $failed,
        'universe_remaining' => $remaining,
        'semantic_reason' => $stopReason,
        'exit_code' => $exit,
        'batch_results' => $batchResults,
    ];
    eod_orchestrator_write_json($checkpoint, $state);
    $semantic = $exit === 0 ? 'COMPLETE' : ($exit === 75 ? 'WAITING' : 'FAILED');
    echo "EOD child_semantic_status={$semantic} exit_code={$exit} reason={$stopReason}\n";
    echo "EOD semantic_reasons={$stopReason}\n";
    echo 'EOD summary selected_scope_complete='.($selectedComplete ? 'true' : 'false')." batch_count=".count($batchResults)." attempted={$attempted} already_fresh={$alreadyFresh} updated={$updated} canonical_verified={$canonicalVerified} metric_verified={$metricVerified} waiting={$waiting} failed={$failed} universe_remaining={$remaining} semantic_reason={$stopReason} exit_code={$exit}\n";
    echo "EOD semantic_status={$semantic} exit_code={$exit} reason={$stopReason}\n";
    echo "EOD checkpoint_path={$checkpoint} resolved_session={$session} trigger_source={$triggerSource} timezone=Asia/Jakarta\n";

    if ($continuationDepth < 8 && $failed === 0) {
        $priorSession = eod_orchestrator_prior_pending_session($php, $writer, $root, $session);
        if ($priorSession !== null) {
            echo "EOD continuation target_session={$priorSession} parent_session={$session} depth=".($continuationDepth + 1)."\n";
            $continuation = eod_orchestrator_run([
                $php, __FILE__, '--session='.$priorSession, '--rpm='.$rpm,
                '--max-batches='.$maxBatches, '--budget-seconds='.$budgetSeconds,
                '--trigger-source='.$triggerSource, '--scheduled-at='.$scheduledAt,
                '--parent-run-id='.$runId, '--continuation-depth='.($continuationDepth + 1),
            ], $root, max(120, min($budgetSeconds, 1680)));
            echo $continuation['stdout'];
            fwrite(STDERR, $continuation['stderr']);
            if ($continuation['timed_out'] || $continuation['exit_code'] !== 0) {
                return $continuation['timed_out'] ? 124 : $continuation['exit_code'];
            }
        }
    }
    return $exit;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(eod_orchestrator_main($argv));
}

