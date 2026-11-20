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

$identity = $pdo->query('SELECT current_database() as db, current_user as usr, current_schema() as sch')->fetch(PDO::FETCH_ASSOC);
$roles = $pdo->query(<<<'SQL'
    SELECT rolname, rolcanlogin, rolsuper, rolcreatedb, rolcreaterole, rolbypassrls
    FROM pg_roles
    WHERE rolname IN ('aether_ingestion', 'aether_runtime', 'aether_backup', 'aether_migrator')
    ORDER BY rolname
SQL)->fetchAll(PDO::FETCH_ASSOC);
$databaseAccess = $pdo->query(<<<'SQL'
    SELECT datname,
           has_database_privilege('aether_ingestion', datname, 'CONNECT') AS ingestion_can_connect,
           has_database_privilege('aether_runtime', datname, 'CONNECT') AS runtime_can_connect,
           has_database_privilege('aether_backup', datname, 'CONNECT') AS backup_can_connect,
           has_database_privilege('aether_test_runner', datname, 'CONNECT') AS test_runner_can_connect
    FROM pg_database
    WHERE datname IN ('project_bot_recovery_20261110', 'aether_test_isolated')
    ORDER BY datname
SQL)->fetchAll(PDO::FETCH_ASSOC);
$testRoles = $pdo->query(<<<'SQL'
    SELECT rolname, rolcanlogin, rolsuper, rolcreatedb, rolcreaterole, rolbypassrls
    FROM pg_roles
    WHERE rolname ILIKE '%test%'
    ORDER BY rolname
SQL)->fetchAll(PDO::FETCH_ASSOC);

// 1. Table inventory from pg_tables
$pgTables = $pdo->query("SELECT tablename, tableowner FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_ASSOC);

$tables = array_column($pgTables, 'tablename');

$inventory = [];
foreach ($pgTables as $pt) {
    $t = $pt['tablename'];
    $row = ['table' => $t, 'owner' => $pt['tableowner']];
    try {
        $row['count'] = (int) $pdo->query("SELECT count(*) FROM public.{$t}")->fetchColumn();
    } catch (Throwable $e) {
        $row['count_error'] = $e->getMessage();
    }

    // Date range
    $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='{$t}'")->fetchAll(PDO::FETCH_COLUMN);
    $dateCol = null;
    foreach (['observation_date', 'decision_date', 'decision_timestamp', 'published_at', 'created_at', 'sent_at'] as $c) {
        if (in_array($c, $cols, true)) {
            $dateCol = $c;
            break;
        }
    }
    if ($dateCol && isset($row['count']) && $row['count'] > 0) {
        $row['date_col'] = $dateCol;
        $row['min_date'] = $pdo->query("SELECT min({$dateCol})::text FROM public.{$t}")->fetchColumn();
        $row['max_date'] = $pdo->query("SELECT max({$dateCol})::text FROM public.{$t}")->fetchColumn();
    } else {
        $row['date_col'] = $dateCol;
        $row['min_date'] = null;
        $row['max_date'] = null;
    }
    $inventory[$t] = $row;
}

// 2. Constraints inventory
$constraints = $pdo->query("
    SELECT tc.constraint_name, tc.table_name, tc.constraint_type
    FROM information_schema.table_constraints tc
    WHERE tc.table_schema = 'public'
    ORDER BY tc.table_name, tc.constraint_name
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Indexes inventory
$indexes = $pdo->query("
    SELECT tablename, indexname, indexdef
    FROM pg_indexes
    WHERE schemaname = 'public'
    ORDER BY tablename, indexname
")->fetchAll(PDO::FETCH_ASSOC);

// 4. Sequences inventory
$sequences = $pdo->query("
    SELECT sequence_name
    FROM information_schema.sequences
    WHERE sequence_schema = 'public'
    ORDER BY sequence_name
")->fetchAll(PDO::FETCH_COLUMN);

$seqValues = [];
foreach ($sequences as $s) {
    try {
        $val = $pdo->query("SELECT last_value FROM public.{$s}")->fetchColumn();
        $seqValues[$s] = $val;
    } catch (Throwable $e) {
        $seqValues[$s] = 'error: ' . $e->getMessage();
    }
}

echo json_encode([
    'identity' => $identity,
    'roles' => $roles,
    'database_access' => $databaseAccess,
    'test_roles' => $testRoles,
    'total_tables' => count($tables),
    'tables' => $inventory,
    'total_constraints' => count($constraints),
    'total_indexes' => count($indexes),
    'total_sequences' => count($sequences),
    'sequences' => $seqValues,
], JSON_PRETTY_PRINT);
