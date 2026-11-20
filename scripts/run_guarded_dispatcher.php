<?php
/**
 * Candidate-only Laravel scheduler wrapper.
 * Decrypts runtime credential, sets isolated environment, verifies SQL identity,
 * then runs schedule:run and durable Telegram jobs. Never uses repository .env.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$root = 'E:/Aether-Market-Intelligence';
$dashboard = $root.'/dashboard';
$database = 'project_bot_recovered_20261115_022935';
$role = 'aether_ingestion';
$credentialFile = $root.'/storage/incidents/20261110_operational_data_loss/ingestion-credentials/aether_ingestion.dpapi.txt';
$decryptor = $root.'/scripts/decrypt_ingestion_secret.ps1';
$localAppData = str_replace('\\', '/', (string) getenv('LOCALAPPDATA'));
$runtime = ($localAppData !== '' ? $localAppData : $root).'/Aether-Market-Intelligence/recovery/runtime/dispatcher';
$configCache = ($localAppData !== '' ? $localAppData : $root).'/Aether-Market-Intelligence/recovery/bootstrap-cache/dispatcher-config.php';

function dispatcherAbort(string $reason, int $code): never
{
    fwrite(STDERR, json_encode([
        'status' => 'DISPATCHER_ABORT',
        'reason' => $reason,
        'secret_printed' => false,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit($code);
}

if (! preg_match('/^project_bot_recovered_[0-9]{8}_[0-9]{6}$/', $database)) {
    dispatcherAbort('candidate_database_name_required', 78);
}
if (! is_file($credentialFile) || filesize($credentialFile) <= 0 || ! is_file($decryptor)) {
    dispatcherAbort('runtime_credential_or_decryptor_missing', 78);
}
if (is_file($configCache)) {
    dispatcherAbort('dispatcher_config_cache_already_exists', 78);
}

foreach ([$runtime.'/framework/cache/data', $runtime.'/framework/cache/locks', $runtime.'/framework/sessions', $runtime.'/framework/views', $runtime.'/logs', dirname($configCache)] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
        dispatcherAbort('isolated_runtime_directory_unavailable', 78);
    }
}

$process = proc_open([
    'C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe',
    '-NoProfile', '-NonInteractive', '-File', $decryptor, '-Path', $credentialFile,
], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
if (! is_resource($process)) {
    dispatcherAbort('credential_decrypt_process_unavailable', 78);
}
fclose($pipes[0]);
$password = trim(stream_get_contents($pipes[1]));
stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$decryptExit = proc_close($process);
if ($decryptExit !== 0 || $password === '') {
    dispatcherAbort('credential_decrypt_failed', 78);
}

$profileEnv = rtrim($localAppData !== '' ? $localAppData : 'C:/Users/ASUS/AppData/Local', '/\\').'/hermes/profiles/aether-prod/.env';
$routerKey = '';
if (is_file($profileEnv)) {
    foreach (file($profileEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^OPENAI_API_KEY\s*=\s*["\']?([^"\']+)["\']?\s*$/', trim($line), $match)) {
            $routerKey = $match[1];
            break;
        }
    }
}

$environment = [
    'APP_ENV' => 'recovery-dispatcher',
    'APP_DEBUG' => 'false',
    'APP_CONFIG_CACHE' => $configCache,
    'LARAVEL_STORAGE_PATH' => $runtime,
    'AETHER_ACTIVE_DATABASE' => $database,
    'AETHER_WRITER_ROLE' => $role,
    'DB_CONNECTION' => 'pgsql',
    'DB_URL' => '',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '5432',
    'DB_DATABASE' => $database,
    'DB_USERNAME' => $role,
    'DB_PASSWORD' => $password,
    'CACHE_STORE' => 'file',
    'DB_CACHE_CONNECTION' => '',
    'DB_CACHE_TABLE' => '',
    'DB_CACHE_LOCK_CONNECTION' => '',
    'DB_CACHE_LOCK_TABLE' => '',
    'SESSION_DRIVER' => 'file',
    'QUEUE_CONNECTION' => 'sync',
    'AI_LIVE_INFERENCE_ENABLED' => 'false',
    'AI_LIVE_INFERENCE_ON_DEMAND_ENABLED' => 'true',
    'AI_LIVE_INFERENCE_EOD_ENABLED' => 'false',
    'AI_ROUTER_ENDPOINT' => 'http://127.0.0.1:20128/v1',
    'AI_MODEL_ID' => 'ag/gemini-3.8-flash-high',
    'AI_API_KEY' => $routerKey,
    'HERMES_ORCHESTRATOR_ENABLED' => 'false',
    // Research worker needs provider access; EOD remains owned by guarded EOD runner.
    'IDX_EDGE_ENABLED' => 'true',
    'TELEGRAM_ENABLED' => 'false',
    'AETHER_SCHEDULED_TRIGGER' => '1',
];
foreach ($environment as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
$password = '';
$routerKey = '';

chdir($dashboard);
require $dashboard.'/vendor/autoload.php';
$app = require $dashboard.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

try {
    $identity = DB::selectOne("SELECT current_database() AS database, current_user AS user_name, current_schema() AS schema_name, current_setting('search_path') AS search_path");
    $destructive = DB::selectOne("SELECT has_table_privilege(current_user, 'public.telegram_outbox', 'DELETE,TRUNCATE') AS outbox_destructive, has_table_privilege(current_user, 'public.scheduled_telegram_jobs', 'DELETE,TRUNCATE') AS jobs_destructive");
} catch (Throwable $error) {
    dispatcherAbort('post_bootstrap_identity_query_failed', 78);
}
if ($identity->database !== $database || $identity->user_name !== $role || $identity->schema_name !== 'public' || (bool) $destructive->outbox_destructive || (bool) $destructive->jobs_destructive) {
    dispatcherAbort('post_bootstrap_identity_or_privilege_mismatch', 78);
}

echo json_encode([
    'status' => 'DISPATCHER_PREFLIGHT_PASSED',
    'database' => $identity->database,
    'role' => $identity->user_name,
    'schema' => $identity->schema_name,
    'search_path' => $identity->search_path,
    'outbox_destructive' => (bool) $destructive->outbox_destructive,
    'jobs_destructive' => (bool) $destructive->jobs_destructive,
    'config_cache' => $configCache,
    'storage' => $runtime,
    'secret_printed' => false,
], JSON_UNESCAPED_SLASHES).PHP_EOL;

$scheduleExit = Artisan::call('schedule:run');
echo Artisan::output();
exit($scheduleExit);
