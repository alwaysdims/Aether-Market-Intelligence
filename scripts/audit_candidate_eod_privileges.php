<?php

$db = 'project_bot_recovered_20261115_022935';
$role = 'aether_ingestion';
$root = 'E:/Aether-Market-Intelligence';
$credentialFile = $root.'/storage/incidents/20261110_operational_data_loss/ingestion-credentials/aether_ingestion.dpapi.txt';
$decryptor = $root.'/scripts/decrypt_ingestion_secret.ps1';

$proc = proc_open(
    ['C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe', '-NoProfile', '-NonInteractive', '-File', $decryptor, '-Path', $credentialFile],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root
);
if (! is_resource($proc)) {
    fwrite(STDERR, "credential decrypt process failed\n");
    exit(2);
}
fclose($pipes[0]);
$password = trim(stream_get_contents($pipes[1]));
stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$decryptExit = proc_close($proc);
if ($decryptExit !== 0 || $password === '') {
    fwrite(STDERR, "credential decrypt failed\n");
    exit(3);
}

try {
    $pdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname={$db}", $role, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query("SELECT current_database() AS database, current_user AS role, current_schema() AS schema, current_setting('search_path') AS search_path")->fetch(PDO::FETCH_ASSOC);
    $tables = [
        'eod_session_queues' => 'SELECT,INSERT,UPDATE',
        'idx_company_registries' => 'SELECT,UPDATE',
        'market_observations' => 'SELECT,INSERT,UPDATE',
        'data_revision_archives' => 'SELECT,INSERT',
        'market_snapshots' => 'SELECT,INSERT,UPDATE',
        'company_analysis_metrics' => 'SELECT,INSERT,UPDATE',
        'data_sources' => 'SELECT',
    ];
    $tablePrivileges = [];
    foreach ($tables as $table => $required) {
        $stmt = $pdo->prepare('SELECT has_table_privilege(current_user, :table_name, :required)');
        $stmt->execute(['table_name' => "public.{$table}", 'required' => $required]);
        $tablePrivileges[$table] = ['required' => $required, 'granted' => (bool) $stmt->fetchColumn()];
    }

    $writtenColumns = [
        'idx_company_registries' => ['eod_refresh_status', 'eod_target_session', 'eod_retry_attempts', 'eod_next_retry_at', 'eod_last_attempt_at', 'eod_last_error', 'last_bar_date', 'last_success_at', 'failure_reason', 'updated_at'],
        'eod_session_queues' => ['status', 'lock_owner_pid', 'lease_owner_token', 'attempts', 'started_at', 'completed_at', 'last_heartbeat_at', 'last_error', 'stats', 'updated_at'],
        'market_observations' => ['ticker', 'bar_date', 'interval', 'data_classification', 'source', 'adjustment_mode', 'observation_time', 'open_price', 'high_price', 'low_price', 'close_price', 'volume', 'is_eod_final', 'delay_minutes', 'data_hash', 'timezone', 'currency', 'finality_basis', 'validation_status', 'board_scope', 'fetched_at', 'available_at', 'updated_at'],
        'market_snapshots' => ['ticker', 'bar_date', 'data_source_id', 'source_timestamp', 'open_price', 'high_price', 'low_price', 'close_price', 'volume', 'data_classification', 'indicators', 'updated_at'],
        'company_analysis_metrics' => ['ticker', 'as_of_date', 'close_price', 'return_1d_pct', 'return_5d_pct', 'return_20d_pct', 'sma_20', 'sma_50', 'sma_200', 'rsi_14', 'macd', 'macd_signal', 'macd_hist', 'atr_14', 'atr_14_pct', 'rvol_20', 'support_price', 'resistance_price', 'technical_trend', 'technical_status', 'data_quality_flags', 'updated_at'],
    ];
    $columnPrivileges = [];
    foreach ($writtenColumns as $table => $columns) {
        foreach ($columns as $column) {
            $stmt = $pdo->prepare('SELECT has_column_privilege(current_user, :table_name, :column_name, :privilege)');
            $stmt->execute(['table_name' => "public.{$table}", 'column_name' => $column, 'privilege' => 'UPDATE']);
            $columnPrivileges[] = ['table' => $table, 'column' => $column, 'update_granted' => (bool) $stmt->fetchColumn()];
        }
    }

    $destructive = [];
    foreach (array_keys($tables) as $table) {
        $stmt = $pdo->prepare('SELECT has_table_privilege(current_user, :table_name, :required)');
        $stmt->execute(['table_name' => "public.{$table}", 'required' => 'DELETE,TRUNCATE']);
        $destructive[$table] = (bool) $stmt->fetchColumn();
    }

    $explicitColumnGrants = $pdo->query("SELECT table_name, column_name, privilege_type FROM information_schema.column_privileges WHERE grantee = current_user AND table_schema = 'public' AND table_name IN ('idx_company_registries','eod_session_queues','market_observations','market_snapshots','company_analysis_metrics') ORDER BY table_name, column_name, privilege_type")->fetchAll(PDO::FETCH_ASSOC);
    $schemaColumns = $pdo->query("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name IN ('idx_company_registries','eod_session_queues','market_observations','market_snapshots','company_analysis_metrics') ORDER BY table_name, ordinal_position")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'READ_ONLY',
        'identity' => $identity,
        'table_privileges' => $tablePrivileges,
        'column_privileges_effective' => $columnPrivileges,
        'explicit_column_grants' => $explicitColumnGrants,
        'schema_columns' => $schemaColumns,
        'destructive_privileges' => $destructive,
        'writes_executed' => false,
        'secret_printed' => false,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally {
    $password = null;
}
