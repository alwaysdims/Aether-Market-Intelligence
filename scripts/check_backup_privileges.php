<?php

$root = 'E:/Aether-Market-Intelligence';
$db = 'project_bot_recovery_20261110';
$role = 'aether_ingestion';
$cred = $root . '/storage/incidents/20261110_operational_data_loss/ingestion-credentials/aether_ingestion.dpapi.txt';
$dec = $root . '/scripts/decrypt_ingestion_secret.ps1';

$p = proc_open([
    'C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe',
    '-NoProfile',
    '-NonInteractive',
    '-File',
    $dec,
    '-Path',
    $cred,
], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
fclose($pipes[0]);
$pass = trim(stream_get_contents($pipes[1]));
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($p);

$pdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname={$db}", $role, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);

$backupPrivs = [];
foreach ($tables as $t) {
    $sel = $pdo->query("SELECT has_table_privilege('aether_backup', 'public.{$t}', 'SELECT')")->fetchColumn();
    $backupPrivs[$t] = (bool) $sel;
}

echo json_encode($backupPrivs, JSON_PRETTY_PRINT);
