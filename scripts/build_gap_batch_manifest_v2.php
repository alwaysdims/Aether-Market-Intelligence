<?php

require_once __DIR__.'/eod_gap_selector.php';

$offset = max(0, (int) ($argv[1] ?? 0));
$attempt = $argv[2] ?? ('gap_'.str_pad((string) (int) ($offset / 20 + 1), 2, '0', STR_PAD_LEFT));
$targetSession = $argv[3] ?? null;
if ($targetSession === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetSession)) {
    fwrite(STDERR, "target session must use YYYY-MM-DD\n");
    exit(2);
}

$db = getenv('AETHER_ACTIVE_DATABASE') ?: 'project_bot_recovered_20261115_022935';
$role = getenv('AETHER_WRITER_ROLE') ?: 'aether_ingestion';
$root = 'E:/Aether-Market-Intelligence';
$decryptor = $root.'/scripts/decrypt_ingestion_secret.ps1';
$credentialFile = $root.'/storage/incidents/20261110_operational_data_loss/ingestion-credentials/aether_ingestion.dpapi.txt';
$process = proc_open(
    ['C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe', '-NoProfile', '-NonInteractive', '-File', $decryptor, '-Path', $credentialFile],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root,
);
if (! is_resource($process)) {
    exit(2);
}
fclose($pipes[0]);
$password = trim(stream_get_contents($pipes[1]));
stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0 || $password === '') {
    exit(2);
}

$pdo = new PDO(
    "pgsql:host=127.0.0.1;port=5432;dbname={$db}",
    $role,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
unset($password);
$sql = <<<'SQL'
WITH universe AS (
    SELECT ticker, eod_target_session, eod_retry_attempts, eod_next_retry_at
    FROM public.idx_company_registries
    WHERE instrument_type = 'EQUITY'
      AND (backfill_status IS NULL OR backfill_status <> 'UNSUPPORTED_SHARE_CLASS')
), coverage AS (
    SELECT u.ticker,
           u.eod_target_session,
           u.eod_retry_attempts,
           u.eod_next_retry_at,
           COALESCE(bool_or(o.adjustment_mode = 'UNADJUSTED'), false) AS raw,
           COALESCE(bool_or(o.adjustment_mode = 'PROVIDER_BACK_ADJUSTED'), false) AS adj
    FROM universe u
    LEFT JOIN public.market_observations o
      ON o.ticker = u.ticker
     AND o.bar_date = :target_session
     AND o.interval = '1d'
     AND o.data_classification = 'PUBLIC_MARKET_EOD'
     AND o.source = 'IDX Edge PRO'
     AND o.board_scope = 'RG'
     AND o.validation_status = 'VALID'
    GROUP BY u.ticker, u.eod_target_session, u.eod_retry_attempts, u.eod_next_retry_at
)
SELECT ticker,
       eod_target_session::text,
       eod_retry_attempts,
       eod_next_retry_at::text,
       raw,
       adj,
       CASE
           WHEN NOT raw AND NOT adj THEN 'neither'
           WHEN NOT raw THEN 'raw_missing'
           ELSE 'adjusted_missing'
       END AS gap
FROM coverage
WHERE NOT (raw AND adj)
ORDER BY ticker
SQL;
$stmt = $pdo->prepare($sql);
$stmt->execute(['target_session' => $targetSession]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$row) {
    $row['raw'] = filter_var($row['raw'], FILTER_VALIDATE_BOOL);
    $row['adj'] = filter_var($row['adj'], FILTER_VALIDATE_BOOL);
    $row['eod_retry_attempts'] = (int) $row['eod_retry_attempts'];
}
unset($row);

if ($rows === []) {
    echo json_encode([
        'status' => 'NO_GAP',
        'target_session' => $targetSession,
        'total_gap_count' => 0,
        'offset' => $offset,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(10);
}

$partitions = eod_gap_selector_partition($rows, $targetSession, new DateTimeImmutable('now'));
$selectedRows = eod_gap_selector_select($partitions, 20, $offset);
if ($selectedRows === []) {
    echo json_encode([
        'status' => 'NO_ELIGIBLE_GAP',
        'target_session' => $targetSession,
        'total_gap_count' => $partitions['total_gap_count'],
        'eligible_count' => $partitions['eligible_count'],
        'deferred_count' => count($partitions['deferred']),
        'exhausted_count' => count($partitions['exhausted']),
        'deferred_tickers' => array_column($partitions['deferred'], 'ticker'),
        'exhausted_tickers' => array_column($partitions['exhausted'], 'ticker'),
        'offset' => $offset,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(11);
}

$tickers = array_column($selectedRows, 'ticker');
$manifest = [
    'status' => 'PRE_RUN_MANIFEST',
    'attempt_id' => $attempt,
    'database' => $db,
    'role' => $role,
    'target_session' => $targetSession,
    'selection_policy' => 'CANONICAL_DB_GAP_NEW_THEN_DUE_V1',
    'total_gap_count' => $partitions['total_gap_count'],
    'eligible_count' => $partitions['eligible_count'],
    'deferred_count' => count($partitions['deferred']),
    'exhausted_count' => count($partitions['exhausted']),
    'tickers' => $tickers,
    'pre_condition' => $selectedRows,
    'command_sanitized' => 'scripts/run_guarded_eod.bat --mode=--batch --session='.$targetSession.' --limit='.count($tickers).' --rpm=30 --tickers='.implode(',', $tickers),
    'writes_executed' => false,
];
$path = $root.'/storage/runtime/eod-runs/20261115_'.$attempt.'.pre-manifest.json';
file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX);
echo json_encode([
    'status' => 'READY',
    'path' => $path,
    'tickers' => $tickers,
    'selection_policy' => $manifest['selection_policy'],
    'total_gap_count' => $partitions['total_gap_count'],
    'eligible_count' => $partitions['eligible_count'],
    'deferred_count' => count($partitions['deferred']),
    'exhausted_count' => count($partitions['exhausted']),
], JSON_UNESCAPED_SLASHES).PHP_EOL;
