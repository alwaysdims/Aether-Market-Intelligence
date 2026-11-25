<?php

$root = dirname(__DIR__);
$dashboard = $root.'/dashboard';
$database = getenv('AETHER_ACTIVE_DATABASE') ?: 'project_bot_recovered_20261115_022935';
$role = 'aether_ingestion';
$credentialFile = $root.'/storage/incidents/20261110_operational_data_loss/ingestion-credentials/aether_ingestion.dpapi.txt';
$localAppData = str_replace('\\', '/', (string) getenv('LOCALAPPDATA'));
$decryptor = $root.'/scripts/decrypt_ingestion_secret.ps1';
$runtimeRoot = $localAppData.'/Aether-Market-Intelligence/recovery';
$storage = $runtimeRoot.'/runtime';
$configCache = $runtimeRoot.'/bootstrap-cache/readonly-dashboard-uncached.php';
$runId = 'dashboard_'.gmdate('Ymd\THis\Z').'_'.bin2hex(random_bytes(4));
$runStartedAt = gmdate('c');
$sourceHash = hash_file('sha256', __FILE__);
$runLogDir = $runtimeRoot.'/runtime/logs/dashboard-runs';
if (! is_dir($runLogDir)) {
    @mkdir($runLogDir, 0700, true);
}
$runReceiptPath = $runLogDir.'/'.$runId.'.jsonl';

function recordDashboardStage(string $stage, array $data = []): void
{
    global $runId, $runStartedAt, $sourceHash, $runReceiptPath;
    $record = array_merge([
        'run_id' => $runId,
        'timestamp' => gmdate('c'),
        'run_started_at' => $runStartedAt,
        'source_hash' => $sourceHash,
        'stage' => $stage,
        'secret_printed' => false,
    ], $data);
    @file_put_contents($runReceiptPath, json_encode($record, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
}

recordDashboardStage('launcher_entered', [
    'mode' => $argv[1] ?? '--serve',
    'php_binary' => PHP_BINARY,
    'working_directory' => getcwd(),
]);

/** Report only controlled diagnostics; never print a PDO message or credential. */
function abortDashboard(string $stage, string $reason, int $exitCode, ?string $sqlState = null): never
{
    global $database, $role;
    $record = [
        'status' => 'PREFLIGHT_FAILED',
        'stage' => $stage,
        'reason' => $reason,
        'database' => $database,
        'role' => $role,
        'sqlstate' => $sqlState !== null && preg_match('/^[A-Z0-9]{5}$/', $sqlState) ? $sqlState : null,
        'secret_printed' => false,
    ];
    $diagnosticDir = ($runtimeRoot ?? ((getenv('LOCALAPPDATA') ?: sys_get_temp_dir()).'/Aether-Market-Intelligence/recovery')).'/runtime/logs';
    if (! is_dir($diagnosticDir)) {
        @mkdir($diagnosticDir, 0700, true);
    }
    @file_put_contents($diagnosticDir.'/dashboard-launcher-failure.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX);
    fwrite(STDERR, json_encode($record, JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit($exitCode);
}

$mode = $argv[1] ?? '--serve';
$port = 8000;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--port=')) {
        $portText = substr($argument, 7);
        if (! ctype_digit($portText)) {
            abortDashboard('arguments', 'port_must_be_numeric', 2);
        }
        $port = (int) $portText;
    }
}

if (! in_array($mode, ['--serve', '--verify-only', '--diagnostic'], true) || $port < 1024 || $port > 65535) {
    abortDashboard('arguments', 'unsupported_mode_or_port', 2);
}

recordDashboardStage('preflight_begin', [
    'mode' => $mode,
    'port' => $port,
    'php_binary' => PHP_BINARY,
    'php_ini' => php_ini_loaded_file(),
    'working_directory' => getcwd(),
    'root' => $root,
    'dashboard' => $dashboard,
]);

if ($mode === '--diagnostic') {
    $diagnosticDir = $runtimeRoot.'/runtime/logs';
    $diagnosticPath = $diagnosticDir.'/dashboard-launcher-diagnostic.json';
    $warnings = [];
    $writePath = $diagnosticDir.'/dashboard-diagnostic-write.tmp';
    if (! is_dir($diagnosticDir)) {
        @mkdir($diagnosticDir, 0700, true);
    }
    $writeOk = @file_put_contents($writePath, 'DASHBOARD_DIAGNOSTIC', LOCK_EX) !== false;
    if ($writeOk) {
        @unlink($writePath);
    }
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = ['severity' => $severity, 'message' => $message];
        return true;
    });
    $process = null;
    $childStatus = null;
    $childExit = null;
    $childStdout = '';
    $childStderr = '';
    $fileDescriptorExit = null;
    $fileDescriptorWarnings = [];
    $environmentChildExit = null;
    $environmentChildWarnings = [];
    $artisanChildExit = null;
    $artisanChildStdout = '';
    $artisanChildStderr = '';
    $serveHelpExit = null;
    $serveHelpStdout = '';
    $serveHelpStderr = '';
    $diagnosticEnvironment = getenv();
    try {
        $process = proc_open([PHP_BINARY, '-r', 'fwrite(STDOUT, "CHILD_OK");'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $childPipes, $root);
        if (is_resource($process)) {
            $childStatus = proc_get_status($process);
            fclose($childPipes[0]);
            $childStdout = stream_get_contents($childPipes[1]);
            $childStderr = stream_get_contents($childPipes[2]);
            fclose($childPipes[1]);
            fclose($childPipes[2]);
            $childExit = proc_close($process);
        }
        $fileOut = $diagnosticDir.'/dashboard-diagnostic-child.stdout.log';
        $fileErr = $diagnosticDir.'/dashboard-diagnostic-child.stderr.log';
        $fileProcess = proc_open([PHP_BINARY, '-r', 'fwrite(STDOUT, "FILE_CHILD_OK");'], [0 => ['pipe', 'r'], 1 => ['file', $fileOut, 'ab'], 2 => ['file', $fileErr, 'ab']], $filePipes, $dashboard);
        if (is_resource($fileProcess)) {
            fclose($filePipes[0]);
            $fileDescriptorExit = proc_close($fileProcess);
        }
        $environmentChild = proc_open([PHP_BINARY, '-r', 'fwrite(STDOUT, "ENV_CHILD_OK");'], [0 => ['pipe', 'r'], 1 => ['file', $fileOut, 'ab'], 2 => ['file', $fileErr, 'ab']], $environmentPipes, $dashboard, $diagnosticEnvironment);
        if (is_resource($environmentChild)) {
            fclose($environmentPipes[0]);
            $environmentChildExit = proc_close($environmentChild);
        }
        $artisanChild = proc_open([PHP_BINARY, $dashboard.'/artisan', '--version'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $artisanPipes, $dashboard);
        if (is_resource($artisanChild)) {
            fclose($artisanPipes[0]);
            $artisanChildStdout = stream_get_contents($artisanPipes[1]);
            $artisanChildStderr = stream_get_contents($artisanPipes[2]);
            fclose($artisanPipes[1]);
            fclose($artisanPipes[2]);
            $artisanChildExit = proc_close($artisanChild);
        }
        $serveHelp = proc_open([PHP_BINARY, $dashboard.'/artisan', 'serve', '--help'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $servePipes, $dashboard, $diagnosticEnvironment);
        if (is_resource($serveHelp)) {
            fclose($servePipes[0]);
            $serveHelpStdout = stream_get_contents($servePipes[1]);
            $serveHelpStderr = stream_get_contents($servePipes[2]);
            fclose($servePipes[1]);
            fclose($servePipes[2]);
            $serveHelpExit = proc_close($serveHelp);
        }
    } finally {
        restore_error_handler();
    }
    $record = [
        'status' => $childExit === 0 ? 'DIAGNOSTIC_COMPLETE' : 'DIAGNOSTIC_FAILED',
        'cwd' => getcwd(),
        'root' => $root,
        'dashboard' => $dashboard,
        'php_binary' => PHP_BINARY,
        'php_ini' => php_ini_loaded_file(),
        'proc_open_available' => function_exists('proc_open'),
        'runtime_log_dir' => $diagnosticDir,
        'runtime_log_dir_writable' => is_writable($diagnosticDir),
        'write_test' => $writeOk,
        'child_status' => $childStatus,
        'child_exit_code' => $childExit,
                'file_descriptor_child_exit_code' => $fileDescriptorExit,
                        'environment_child_exit_code' => $environmentChildExit,
                                'artisan_child_exit_code' => $artisanChildExit,
                                'artisan_child_stdout' => substr($artisanChildStdout, 0, 300),
                                'artisan_child_stderr' => substr($artisanChildStderr, 0, 500),
                                        'serve_help_exit_code' => $serveHelpExit,
                                        'serve_help_stdout' => substr($serveHelpStdout, 0, 300),
                                        'serve_help_stderr' => substr($serveHelpStderr, 0, 500),
                                        'child_stdout' => substr($childStdout, 0, 200),
        'child_stderr' => substr($childStderr, 0, 500),
        'warnings' => $warnings,
        'secret_printed' => false,
    ];
    file_put_contents($diagnosticPath, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX);
    echo json_encode($record, JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($record['status'] === 'DIAGNOSTIC_COMPLETE' ? 0 : 9);
}

$portProbe = @fsockopen('127.0.0.1', $port, $portError, $portMessage, 0.25);
if (is_resource($portProbe)) {
    fclose($portProbe);
    abortDashboard('port_bind', 'dashboard_port_already_in_use', 10);
}

if (! extension_loaded('pdo_pgsql')) {
    abortDashboard('php_extension', 'pdo_pgsql_is_not_loaded', 3);
}

if (is_file($configCache)) {
    abortDashboard('config_cache', 'dedicated_uncached_config_path_is_occupied_do_not_reuse_stale_config', 3);
}

if ($localAppData === '' || ! is_file($credentialFile) || filesize($credentialFile) === 0 || ! is_file($decryptor)) {
    abortDashboard('credential_store', 'runtime_dpapi_file_or_decryptor_unavailable', 3);
}

$process = proc_open([
    'C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe',
    '-NoProfile',
    '-NonInteractive',
    '-File',
    $decryptor,
    '-Path',
    $credentialFile,
], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);

if (! is_resource($process)) {
    abortDashboard('credential_decrypt', 'powershell_process_unavailable', 5);
}

fclose($pipes[0]);
$password = trim(stream_get_contents($pipes[1]));
stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$decryptExit = proc_close($process);

if ($decryptExit !== 0 || $password === '') {
    abortDashboard('credential_decrypt', 'dpapi_decrypt_failed_use_the_correct_windows_account', 6);
}

$stage = 'database_connect';
try {
    $pdo = new PDO(
        "pgsql:host=127.0.0.1;port=5432;dbname={$database}",
        $role,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $stage = 'production_session';
    $pdo->exec('SET SESSION search_path = public, pg_catalog');
    $stage = 'sql_identity';
    $identity = $pdo->query(<<<'SQL'
        SELECT current_database() AS database,
               current_user AS user_name,
               current_schema() AS schema_name,
               current_setting('default_transaction_read_only') AS default_transaction_read_only,
               has_database_privilege(current_user, current_database(), 'CONNECT') AS can_connect,
               has_schema_privilege(current_user, 'public', 'CREATE') AS can_create_schema,
               has_schema_privilege(current_user, 'public', 'USAGE') AS can_use_public,
               r.rolsuper AS is_superuser,
               r.rolcreatedb AS can_create_database,
               r.rolcreaterole AS can_create_role,
               r.rolbypassrls AS bypass_rls
        FROM pg_catalog.pg_roles r WHERE r.rolname = current_user
    SQL)->fetch(PDO::FETCH_ASSOC);
    $stage = 'table_privilege_check';
    $writableTables = (int) $pdo->query(<<<'SQL'
        SELECT count(*)
        FROM pg_catalog.pg_class c
        JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public'
          AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
          AND (
              has_table_privilege(current_user, c.oid, 'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')
              OR pg_has_role(current_user, c.relowner, 'USAGE')
          )
    SQL)->fetchColumn();
    $stage = 'sequence_privilege_check';
    $writableSequences = (int) $pdo->query(<<<'SQL'
        WITH sequences AS MATERIALIZED (
            SELECT c.oid, c.relowner
            FROM pg_catalog.pg_class c
            JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind = 'S'
        )
        SELECT count(*)
        FROM sequences
        WHERE has_sequence_privilege(current_user, oid, 'USAGE,UPDATE')
           OR pg_has_role(current_user, relowner, 'USAGE')
    SQL)->fetchColumn();

    $unsafe = $identity === false
        || $identity['database'] !== $database
        || $identity['user_name'] !== $role
        || $identity['schema_name'] !== 'public'
        || ! filter_var($identity['can_connect'], FILTER_VALIDATE_BOOL)
        || ! filter_var($identity['can_use_public'], FILTER_VALIDATE_BOOL);

    if ($unsafe) {
        abortDashboard('read_only_gate', 'sql_identity_or_read_only_privileges_are_unsafe', 6);
    }

    $stage = 'market_observations_read';
    $observations = (int) $pdo->query('SELECT count(*) FROM public.market_observations')->fetchColumn();
    $stage = 'idx_company_registries_read';
    $registries = (int) $pdo->query('SELECT count(*) FROM public.idx_company_registries')->fetchColumn();
} catch (Throwable $error) {
    $state = $error instanceof PDOException ? (string) ($error->errorInfo[0] ?? $error->getCode()) : null;
    $reason = match ($state) {
        '42501' => 'database_permission_denied',
        '42P01' => 'required_database_object_is_missing',
        '28P01', '28000' => 'runtime_database_authentication_failed',
        '08001', '08006' => 'database_connection_or_authentication_failed',
        default => 'database_check_failed_inspect_stage_and_sqlstate',
    };
    abortDashboard($stage, $reason, 7, $state);
}

$dataStatus = $observations === 0 || $registries === 0 ? 'EMPTY_DATA' : 'PRESENT_FRESHNESS_NOT_VERIFIED';

if ($mode === '--verify-only') {
    echo json_encode([
        'status' => 'PRODUCTION_PREFLIGHT_PASSED',
        'data_status' => $dataStatus,
        'mode' => 'production',
        'database' => $database,
        'role' => $role,
        'schema' => $identity['schema_name'],
        'search_path' => 'public, pg_catalog',
        'market_observations' => $observations,
        'idx_company_registries' => $registries,
        'writable_tables' => $writableTables,
        'writable_sequences' => $writableSequences,
        'default_transaction_read_only' => $identity['default_transaction_read_only'] === 'on',
        'secret_printed' => false,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if ($dataStatus === 'EMPTY_DATA') {
    fwrite(STDERR, "[WARNING] Database kosong pada tabel market/registry. Dashboard tidak memiliki data untuk ditampilkan.\n");
}

foreach (['runtime/framework/cache/data', 'runtime/framework/cache/locks', 'runtime/framework/sessions', 'runtime/framework/views', 'runtime/logs', 'bootstrap-cache'] as $directory) {
    $path = $runtimeRoot.'/'.$directory;
    if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
        fwrite(STDERR, "runtime storage unavailable\n");
        exit(8);
    }
}

$environment = getenv();
foreach ([
    'DASHBOARD_CANDIDATE' => '0',
    // run instrumentation records only key presence, never secret values.
    'AETHER_DASHBOARD_RUN_ID' => $runId,
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
    'APP_CONFIG_CACHE' => $configCache,
    'LARAVEL_STORAGE_PATH' => $storage,
    'DB_CONNECTION' => 'pgsql',
    'DB_URL' => '',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '5432',
    'DB_DATABASE' => $database,
    'DB_USERNAME' => $role,
    'DB_PASSWORD' => $password,
    'PGOPTIONS' => null,
    'CACHE_STORE' => 'file',
    'SESSION_DRIVER' => 'file',
    'QUEUE_CONNECTION' => 'sync',
    'DASHBOARD_CANDIDATE_READ_ONLY_HEALTH' => 'false',
    'AI_LIVE_INFERENCE_ENABLED' => 'false',
    'AI_LIVE_INFERENCE_ON_DEMAND_ENABLED' => 'true',
    'AI_ROUTER_ENDPOINT' => 'http://127.0.0.1:20128/v1',
    'AI_MODEL_ID' => 'ag/gemini-3.8-flash-high',
    'AI_ROUTER_TIMEOUT' => '60',
    'CONSULTATION_AI_TIMEOUT' => '60',
    'AI_LIVE_INFERENCE_EOD_ENABLED' => 'false',
    'HERMES_ORCHESTRATOR_ENABLED' => 'false',
    'IDX_EDGE_ENABLED' => 'true',
    'IDX_EDGE_MOVERS_ENABLED' => 'true',
    'IDX_EDGE_SCREENER_ENABLED' => 'true',
    'IDX_EDGE_PRICE_IHSG_ENABLED' => 'true',
    'TELEGRAM_ENABLED' => 'false',
] as $key => $value) {
    $environment[$key] = $value;
}
recordDashboardStage('preflight_complete', [
    'environment_keys' => array_values(array_intersect(array_keys($environment), ['APP_ENV', 'APP_DEBUG', 'APP_CONFIG_CACHE', 'LARAVEL_STORAGE_PATH', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'PGOPTIONS', 'CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION', 'DASHBOARD_CANDIDATE', 'AETHER_DASHBOARD_RUN_ID'])),
    'database' => $database,
    'role' => $role,
    'read_only' => false,
    'writable_tables' => $writableTables,
    'writable_sequences' => $writableSequences,
    'runtime_log_dir' => $runtimeLogDir ?? null,
]);
$password = '';
$pdo = null;

$runtimeLogDir = $runtimeRoot.'/runtime/logs';
$serverStdout = $runtimeLogDir.'/dashboard-server.stdout.log';
$serverStderr = $runtimeLogDir.'/dashboard-server.stderr.log';
$processMetadata = $runtimeLogDir.'/dashboard-process.json';
$serverStdoutReady = is_file($serverStdout) || @touch($serverStdout);
$serverStderrReady = is_file($serverStderr) || @touch($serverStderr);
recordDashboardStage('before_proc_open', [
    'command' => [PHP_BINARY, $dashboard.'/artisan', 'serve', '--host=127.0.0.1', "--port={$port}", '--no-reload'],
    'working_directory' => $dashboard,
    'descriptor_spec' => [
        'stdin' => ['pipe', 'r'],
        'stdout' => ['file', $serverStdout, 'ab'],
        'stderr' => ['file', $serverStderr, 'ab'],
    ],
    'stdout_ready' => $serverStdoutReady,
    'stderr_ready' => $serverStderrReady,
]);

fwrite(STDOUT, "Aether production dashboard: http://127.0.0.1:{$port}\n");
recordDashboardStage('proc_open_begin');
$serverWarnings = [];
set_error_handler(static function (int $severity, string $message) use (&$serverWarnings): bool {
    $serverWarnings[] = ['severity' => $severity, 'message' => $message];
    return true;
});
$server = proc_open(
    [PHP_BINARY, $dashboard.'/artisan', 'serve', '--host=127.0.0.1', "--port={$port}", '--no-reload'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $serverStdout, 'ab'],
        2 => ['file', $serverStderr, 'ab'],
    ],
    $serverPipes,
    $dashboard,
    $environment,
);
restore_error_handler();
recordDashboardStage('after_proc_open', [
    'returned_resource' => is_resource($server),
    'warnings' => $serverWarnings,
]);

if (! is_resource($server)) {
    $failurePath = $runtimeLogDir.'/dashboard-launcher-failure.json';
    $failure = [
        'status' => 'PREFLIGHT_FAILED',
        'stage' => 'server_start',
        'reason' => 'foreground_server_proc_open_failed',
        'proc_open_returned_resource' => false,
        'warnings' => $serverWarnings,
        'stdout_path' => $serverStdout,
        'stderr_path' => $serverStderr,
        'database' => $database,
        'role' => $role,
        'sqlstate' => null,
        'secret_printed' => false,
    ];
    file_put_contents($failurePath, json_encode($failure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX);
    recordDashboardStage('proc_open_failed', $failure);
    fwrite(STDERR, json_encode($failure, JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(9);
}

$status = proc_get_status($server);
recordDashboardStage('child_status_initial', [
    'pid' => $status['pid'] ?? null,
    'running' => $status['running'] ?? null,
    'exit_code' => $status['exitcode'] ?? null,
    'signaled' => $status['signaled'] ?? null,
    'command' => $status['command'] ?? null,
]);
$metadata = [
    'pid' => $status['pid'] ?? null,
    'parent_pid' => getmypid(),
    'creation_time' => gmdate('c'),
    'executable' => PHP_BINARY,
    'working_directory' => $dashboard,
    'port' => $port,
    'stdout' => $serverStdout,
    'stderr' => $serverStderr,
    'started_at' => gmdate('c'),
];
file_put_contents($processMetadata, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX);
fclose($serverPipes[0]);

for ($attempt = 0; $attempt < 30; $attempt++) {
    $probe = @fsockopen('127.0.0.1', $port, $probeError, $probeMessage, 0.25);
    if (is_resource($probe)) {
        fclose($probe);
        break;
    }
    usleep(250000);
}

if ($attempt === 30) {
    $status = proc_get_status($server);
    recordDashboardStage('child_not_ready', [
        'pid' => $status['pid'] ?? null,
        'running' => $status['running'] ?? null,
        'exit_code' => $status['exitcode'] ?? null,
        'signaled' => $status['signaled'] ?? null,
    ]);
    proc_terminate($server);
    $exitCode = 9;
    file_put_contents($processMetadata, json_encode($metadata + ['stopped_at' => gmdate('c'), 'exit_code' => $exitCode], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX);
    abortDashboard('server_start', 'foreground_server_not_ready', $exitCode);
}

$exitCode = proc_close($server);
recordDashboardStage('child_exit', ['exit_code' => $exitCode]);
file_put_contents($processMetadata, json_encode($metadata + ['stopped_at' => gmdate('c'), 'exit_code' => $exitCode], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX);
exit($exitCode);
