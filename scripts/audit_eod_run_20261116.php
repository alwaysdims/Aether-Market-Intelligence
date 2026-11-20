<?php
$db = 'project_bot_recovered_20261115_022935';
$role = 'aether_ingestion';
$targetSession = $argv[1] ?? '2026-11-16';
if (! preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $targetSession)) {
    fwrite(STDERR, "invalid session format\\n");
    exit(2);
}
$root = 'E:/Aether-Market-Intelligence';
$credential = $root.'/storage/incidents/20261110_operational_data_loss/ingestion-credentials/aether_ingestion.dpapi.txt';
$decryptor = $root.'/scripts/decrypt_ingestion_secret.ps1';
$process = proc_open(['C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe','-NoProfile','-NonInteractive','-File',$decryptor,'-Path',$credential],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
fclose($pipes[0]);
$password = trim(stream_get_contents($pipes[1]));
stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0 || $password === '') { fwrite(STDERR, "credential decrypt failed\n"); exit(2); }
$pdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname={$db}", $role, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
unset($password);
$identity = $pdo->query("SELECT current_database() AS database, current_user AS role, current_schema() AS schema, current_setting('search_path') AS search_path")->fetch(PDO::FETCH_ASSOC);
$queue = $pdo->prepare("SELECT id, session_date::text, status, attempts, lock_owner_pid, lease_owner_token, last_error, stats, started_at, completed_at, last_heartbeat_at FROM public.eod_session_queues WHERE session_date = :session ORDER BY id DESC LIMIT 1");
$queue->execute(['session' => $targetSession]);
$row = $queue->fetch(PDO::FETCH_ASSOC) ?: null;
$stats = $row && is_string($row['stats']) ? json_decode($row['stats'], true) : ($row['stats'] ?? []);
$retry = $stats['retry_evidence'] ?? [];
$registry = [];
$observations = [];
foreach (array_keys($retry) as $ticker) {
    $q = $pdo->prepare("SELECT ticker, eod_refresh_status, eod_retry_attempts, eod_next_retry_at, eod_last_error, last_bar_date::text FROM public.idx_company_registries WHERE ticker = :ticker");
    $q->execute(['ticker' => $ticker]);
    $registry[$ticker] = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $q = $pdo->prepare("SELECT adjustment_mode, bar_date::text, source, board_scope, validation_status, close_price FROM public.market_observations WHERE ticker = :ticker AND bar_date = :session ORDER BY adjustment_mode");
    $q->execute(['ticker' => $ticker, 'session' => $targetSession]);
    $observations[$ticker] = $q->fetchAll(PDO::FETCH_ASSOC);
}
echo json_encode(['identity'=>$identity,'queue'=>$row ? array_merge($row, ['stats'=>$stats]) : null,'retry_evidence'=>$retry,'registry'=>$registry,'target_observations'=>$observations,'writes_executed'=>false,'secret_printed'=>false], JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
