<?php
require_once __DIR__.'/eod_auto_orchestrator.php';

$manifestWaiting = eod_orchestrator_manifest_status(11, ['status' => 'NO_ELIGIBLE_GAP', 'total_gap_count' => 3]);
if ($manifestWaiting !== ['action' => 'WAITING', 'reason' => 'DEFERRED_OR_EXHAUSTED_GAPS', 'remaining' => 3]) {
    throw new RuntimeException('no eligible gap waiting contract failed');
}

$cases = [
    [['updated' => 20, 'waiting' => 0, 'failed' => 0, 'remaining' => 479], 300, 'CONTINUE'],
    [['updated' => 0, 'waiting' => 0, 'failed' => 0, 'remaining' => 479], 300, 'STOP_NO_PROGRESS'],
    [['updated' => 19, 'waiting' => 1, 'failed' => 0, 'remaining' => 480], 300, 'CONTINUE'],
    [['updated' => 0, 'waiting' => 1, 'failed' => 0, 'remaining' => 480], 300, 'STOP_PROVIDER_WAITING'],
    [['updated' => 19, 'waiting' => 0, 'failed' => 1, 'remaining' => 480], 300, 'STOP_FAILURE'],
    [['updated' => 20, 'waiting' => 0, 'failed' => 0, 'remaining' => 479], 90, 'STOP_BUDGET'],
    [['updated' => 20, 'waiting' => 0, 'failed' => 0, 'remaining' => 0], 300, 'COMPLETE'],
];
foreach ($cases as [$result, $remainingSeconds, $expected]) {
    $actual = eod_orchestrator_next_action($result, $remainingSeconds);
    if ($actual !== $expected) {
        fwrite(STDERR, "expected {$expected}, got {$actual}\n");
        exit(1);
    }
}
$stdout = implode(PHP_EOL, [
    'EOD summary selected_scope_complete=true updated=20 waiting=0 failed=0 universe_remaining=399 semantic_reason=BACKLOG_REMAINING exit_code=75',
    'EOD summary selected_scope_complete=true updated=20 waiting=0 failed=0 universe_remaining=379 semantic_reason=BACKLOG_REMAINING exit_code=75',
    'EOD summary selected_scope_complete=true updated=20 waiting=0 failed=0 universe_remaining=359 semantic_reason=BACKLOG_REMAINING exit_code=75',
]);
$summaries = eod_orchestrator_parse_batch_summaries($stdout);
if (count($summaries) !== 3 || $summaries[2]['remaining'] !== 359 || eod_orchestrator_parse_summary($stdout)['remaining'] !== 359) {
    throw new RuntimeException('multi-batch summary aggregation failed');
}
$receiptStdout = $stdout.PHP_EOL.'EOD retry_evidence_receipt={"session_date":"2026-11-17","queue_attempt":23,"retry_evidence":{"PTSN":{"raw_request":{"status":"SKIPPED"}}}}';
$retryReceipt = eod_orchestrator_parse_retry_evidence_receipt($receiptStdout);
if (($retryReceipt['queue_attempt'] ?? null) !== 23 || ($retryReceipt['retry_evidence']['PTSN']['raw_request']['status'] ?? null) !== 'SKIPPED') {
    throw new RuntimeException('retry evidence receipt parsing failed');
}
$checkpoint = sys_get_temp_dir().'/eod-checkpoint-'.bin2hex(random_bytes(4)).'.json';
eod_orchestrator_write_json($checkpoint, ['status'=>'RUNNING','batch_results'=>$summaries]);
$decoded = json_decode((string) file_get_contents($checkpoint), true);
if (($decoded['batch_results'][2]['remaining'] ?? null) !== 359) throw new RuntimeException('atomic checkpoint readback failed');
unlink($checkpoint);
$failed = eod_orchestrator_next_action(['updated'=>20,'waiting'=>0,'failed'=>1,'remaining'=>359], 300);
if ($failed !== 'STOP_FAILURE') throw new RuntimeException('rollback/error stop contract failed');
$stale = eod_orchestrator_next_action(['updated'=>0,'waiting'=>0,'failed'=>0,'remaining'=>359], 300);
if ($stale !== 'STOP_NO_PROGRESS') throw new RuntimeException('stale checkpoint/no-progress contract failed');
echo "EOD_ORCHESTRATOR_SELF_TEST=PASS cases=".(count($cases)+4).PHP_EOL;
