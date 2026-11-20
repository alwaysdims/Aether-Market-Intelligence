<?php
/**
 * Application-writer launcher (news ingestion, broker summary, Gemini narrative).
 *
 * Uses the aether_ingestion DPAPI credential with grant-only operational rights.
 * Never used for migrations (owner-only DDL) and never with postgres.
 *
 * Modes:
 *   --news --limit=N
 *   --broker --tickers=BBCA --session=YYYY-MM-DD
 *   --narrative --tickers=BBCA
 *   --telegram-chat --text=/status --user=5923895922 --update-id=123 --message-id=456
 *   --record-telegram-receipt --update-id=123 --message-id=456 --outbound-message-id=789
 *   --consultation-queue --job-id=1
 *   --telegram-audit --job-id=1
 *   --grounding-correction --job-id=1
 *   --requeue-consultation --update-id=123 --message-id=456
 *   --recovery-consultation --update-id=123 --message-id=456 --supersedes-job-id=1
 *   --reconcile-outbox --outbox-id=5
 *   --process-outbox --job-id=1
 *   --provider-probe
 *   --research-request (JSON payload on stdin; dashboard request bridge)
 *   --coverage-acl-audit
 *   --analysis-audit --tickers=BBCA
 *   --analysis-record-audit --analysis-id=152 --evidence-id=187
 *   --briefing-audit
 *   --briefing-acl-audit
 *   --briefing-slot-audit --date=YYYY-MM-DD
 *   --shadow-canary --run-id=WSRUN_YYYYMMDD_HHMMSS_xxxxxxxx [--dry-run|--live]
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

$root = 'E:/Aether-Market-Intelligence';
$dashboard = $root.'/dashboard';
$db = getenv('AETHER_ACTIVE_DATABASE') ?: 'project_bot_recovered_20261115_022935';
$role = 'aether_ingestion';
$host = '127.0.0.1';
$port = '5432';
$credentialFile = $root.'/storage/incidents/20261110_operational_data_loss/ingestion-credentials/aether_ingestion.dpapi.txt';
$decryptor = $root.'/scripts/decrypt_ingestion_secret.ps1';
$runtime = $root.'/storage/incidents/20261110_operational_data_loss/ingestion-writer-runtime/app-writer';

$mode = $argv[1] ?? '';

// Reuse active Hermes profile credential without printing or persisting its value.
$hermesProfileEnv = rtrim((string) (getenv('LOCALAPPDATA') ?: 'C:/Users/ASUS/AppData/Local'), '/\\').'/hermes/profiles/aether-prod/.env';
if ((string) getenv('AI_API_KEY') === '' && is_file($hermesProfileEnv)) {
    $profileEnv = file($hermesProfileEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($profileEnv as $line) {
        if (preg_match('/^OPENAI_API_KEY\\s*=\\s*["\']?([^"\']+)["\']?\\s*$/', trim($line), $match) && $match[1] !== '') {
            putenv('AI_API_KEY='.$match[1]);
            $_ENV['AI_API_KEY'] = $match[1];
            $_SERVER['AI_API_KEY'] = $match[1];
            break;
        }
    }
}
$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(limit|tickers|session|date|text|user|update-id|message-id|reply-to|outbound-message-id|job-id|supersedes-job-id|outbox-id|run-id|analysis-id|evidence-id)=(.*)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    }
}

if (! in_array($mode, ['--news', '--broker', '--narrative', '--telegram-chat', '--record-telegram-receipt', '--consultation-queue', '--telegram-audit', '--grounding-correction', '--requeue-consultation', '--recovery-consultation', '--reconcile-outbox', '--process-outbox', '--provider-probe', '--research-request', '--research-request-audit', '--research-request-status', '--research-acceptance-audit', '--coverage-session-audit', '--coverage-acl-audit', '--analysis-audit', '--analysis-record-audit', '--briefing-audit', '--briefing-acl-audit', '--briefing-slot-audit', '--news-audit', '--shadow-canary'], true)) {
    fwrite(STDERR, "app writer disabled; supported modes: --news, --broker, --narrative, --telegram-chat, --record-telegram-receipt, --consultation-queue, --telegram-audit, --requeue-consultation, --recovery-consultation, --reconcile-outbox, --process-outbox\n");
    exit(2);
}

$limit = isset($options['limit']) ? (int) $options['limit'] : 10;
if ($mode === '--news' && ($limit < 1 || $limit > 20)) {
    fwrite(STDERR, "news --limit must be 1..20\n");
    exit(2);
}

$ticker = isset($options['tickers']) ? strtoupper($options['tickers']) : null;
$session = $options['session'] ?? null;
if ($mode === '--broker' && ($ticker === null || ! preg_match('/^[A-Z]{4}$/', $ticker) || $session === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $session))) {
    fwrite(STDERR, "broker requires --tickers=CODE and --session=YYYY-MM-DD\n");
    exit(2);
}
if (in_array($mode, ['--narrative', '--analysis-audit'], true) && ($ticker === null || ! preg_match('/^[A-Z]{4}$/', $ticker))) {
    fwrite(STDERR, "{$mode} requires --tickers=CODE\n");
    exit(2);
}
if ($mode === '--briefing-slot-audit' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($options['date'] ?? '')))) {
    fwrite(STDERR, "briefing-slot-audit requires --date=YYYY-MM-DD\n");
    exit(2);
}
if ($mode === '--shadow-canary' && (! preg_match('/^WSRUN_[0-9]{8}_[0-9]{6}_[a-z0-9]{8}$/', (string) ($options['run-id'] ?? '')))) {
    fwrite(STDERR, "shadow-canary requires --run-id=WSRUN_YYYYMMDD_HHMMSS_xxxxxxxx\n");
    exit(2);
}
if ($mode === '--shadow-canary' && ! in_array('--live', array_slice($argv, 1), true) && ! in_array('--dry-run', array_slice($argv, 1), true)) {
    fwrite(STDERR, "shadow-canary requires --dry-run or --live\n");
    exit(2);
}
if ($mode === '--shadow-canary' && in_array('--live', array_slice($argv, 1), true) && in_array('--dry-run', array_slice($argv, 1), true)) {
    fwrite(STDERR, "shadow-canary cannot combine --dry-run and --live\n");
    exit(2);
}
if ($mode === '--shadow-canary' && in_array('--live', array_slice($argv, 1), true)) {
    $liveShadowCanary = true;
} else {
    $liveShadowCanary = false;
}
if ($mode === '--shadow-canary' && in_array('--dry-run', array_slice($argv, 1), true)) {
    $dryShadowCanary = true;
} else {
    $dryShadowCanary = false;
}
if ($mode === '--telegram-chat') {
    foreach (['text', 'user', 'update-id', 'message-id'] as $required) {
        if (($options[$required] ?? '') === '') {
            fwrite(STDERR, "telegram-chat requires --{$required}\n");
            exit(2);
        }
    }
    if (mb_strlen($options['text']) > 500 || ! ctype_digit($options['user']) || ! ctype_digit($options['update-id']) || ! ctype_digit($options['message-id'])) {
        fwrite(STDERR, "telegram-chat requires text up to 500 characters and numeric Telegram IDs\n");
        exit(2);
    }
}
if ($mode === '--record-telegram-receipt') {
    foreach (['update-id', 'message-id', 'outbound-message-id'] as $required) {
        if (! ctype_digit((string) ($options[$required] ?? ''))) {
            fwrite(STDERR, "record-telegram-receipt requires numeric --{$required}\n");
            exit(2);
        }
    }
}
if (in_array($mode, ['--requeue-consultation', '--recovery-consultation'], true)) {
    foreach (['update-id', 'message-id'] as $required) {
        if (! ctype_digit((string) ($options[$required] ?? ''))) {
            fwrite(STDERR, "{$mode} requires numeric --{$required}\n");
            exit(2);
        }
    }
}
if ($mode === '--recovery-consultation' && (! ctype_digit((string) ($options['supersedes-job-id'] ?? '')) || (int) $options['supersedes-job-id'] < 1)) {
    fwrite(STDERR, "recovery-consultation requires positive numeric --supersedes-job-id\n");
    exit(2);
}
if ($mode === '--consultation-queue' && (($options['job-id'] ?? '') !== '' && (! ctype_digit((string) $options['job-id']) || (int) $options['job-id'] < 1))) {
    fwrite(STDERR, "consultation-queue --job-id must be positive numeric when provided\n");
    exit(2);
}
if ($mode === '--process-outbox' && (($options['job-id'] ?? '') !== '' && (! ctype_digit((string) $options['job-id']) || (int) $options['job-id'] < 1))) {
    fwrite(STDERR, "process-outbox --job-id must be positive numeric when provided\n");
    exit(2);
}
if (in_array($mode, ['--telegram-audit', '--grounding-correction'], true) && (! ctype_digit((string) ($options['job-id'] ?? '')) || (int) $options['job-id'] < 1)) {
    fwrite(STDERR, "{$mode} requires positive numeric --job-id\n");
    exit(2);
}

if (! is_file($credentialFile) || filesize($credentialFile) <= 0) {
    fwrite(STDERR, "aether_ingestion credential missing at {$credentialFile}\n");
    exit(3);
}

foreach ([$runtime.'/framework/cache/data', $runtime.'/framework/cache/locks', $runtime.'/framework/sessions', $runtime.'/framework/views', $runtime.'/logs'] as $dir) {
    if (! is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
}

$proc = proc_open(
    ['C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe', '-NoProfile', '-NonInteractive', '-File', $decryptor, '-Path', $credentialFile],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root
);
if (! is_resource($proc)) {
    fwrite(STDERR, "credential decrypt process failed\n");
    exit(4);
}
fclose($pipes[0]);
$password = trim(stream_get_contents($pipes[1]));
stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($proc) !== 0 || $password === '') {
    fwrite(STDERR, "credential decrypt failed\n");
    exit(4);
}

foreach ([
    'APP_ENV' => 'recovery-app-writer',
    'DASHBOARD_CANDIDATE' => '0',
    'APP_DEBUG' => 'false',
    'APP_CONFIG_CACHE' => (getenv('LOCALAPPDATA') ?: $root).'/Aether-Market-Intelligence/recovery/bootstrap-cache/app-writer-config.php',
    'LARAVEL_STORAGE_PATH' => $runtime,
    'DB_CONNECTION' => 'pgsql',
    'DB_HOST' => $host,
    'DB_PORT' => $port,
    'DB_DATABASE' => $db,
    'DB_USERNAME' => $role,
    'DB_PASSWORD' => $password,
    // Writer runs through a narrow bridge; do not inherit recovery reader's PGOPTIONS.
    'PGOPTIONS' => null,
    'AETHER_ACTIVE_DATABASE' => $db,
    'AETHER_WRITER_ROLE' => $role,
    'CACHE_STORE' => 'file',
    'DB_CACHE_CONNECTION' => '',
    'DB_CACHE_TABLE' => '',
    'DB_CACHE_LOCK_CONNECTION' => '',
    'DB_CACHE_LOCK_TABLE' => '',
    'SESSION_DRIVER' => 'file',
    'QUEUE_CONNECTION' => 'sync',
    'AI_LIVE_INFERENCE_ENABLED' => 'false',
    'AI_LIVE_INFERENCE_ON_DEMAND_ENABLED' => in_array($mode, ['--telegram-chat', '--narrative', '--consultation-queue', '--requeue-consultation', '--recovery-consultation', '--research-request'], true) ? 'true' : 'false',
    'AI_LIVE_INFERENCE_EOD_ENABLED' => 'false',
    'HERMES_ORCHESTRATOR_ENABLED' => 'false',
    'IDX_EDGE_ENABLED' => in_array($mode, ['--broker', '--research-request', '--provider-probe'], true) ? 'true' : 'false',
    'TELEGRAM_ENABLED' => 'false',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
$password = '';

require $dashboard.'/vendor/autoload.php';
$app = require $dashboard.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$runtimeGate = \Illuminate\Support\Facades\DB::selectOne("SELECT current_database() AS database, current_user AS user_name, current_schema() AS schema_name, current_setting('default_transaction_read_only') AS default_transaction_read_only, has_table_privilege(current_user, 'public.research_requests', 'SELECT,INSERT,UPDATE') AS request_dml, has_sequence_privilege(current_user, 'public.research_requests_id_seq', 'USAGE,SELECT') AS request_sequence, has_table_privilege(current_user, 'public.research_requests', 'DELETE,TRUNCATE') AS destructive");
if (! $runtimeGate
    || $runtimeGate->database !== $db
    || $runtimeGate->user_name !== $role
    || $runtimeGate->schema_name !== 'public'
    || $runtimeGate->default_transaction_read_only !== 'off'
    || ! filter_var($runtimeGate->request_dml, FILTER_VALIDATE_BOOL)
    || ! filter_var($runtimeGate->request_sequence, FILTER_VALIDATE_BOOL)
    || filter_var($runtimeGate->destructive, FILTER_VALIDATE_BOOL)) {
    fwrite(STDERR, "APP_WRITER_ABORT post-bootstrap runtime gate failed\n");
    exit(5);
}

if ($mode === '--news-audit') {
    $rows = \Illuminate\Support\Facades\DB::select("SELECT id, url, source_name, published_at, first_seen_at, available_at, raw_payload_path, raw_payload_hash, related_tickers FROM public.news_items ORDER BY id DESC LIMIT 5");
    echo json_encode([
        'status' => 'READ_ONLY',
        'identity' => \Illuminate\Support\Facades\DB::selectOne("SELECT current_database() AS database, current_user AS user_name, current_schema() AS schema_name, current_setting('search_path') AS search_path"),
        'count' => (int) \Illuminate\Support\Facades\DB::scalar('SELECT count(*) FROM public.news_items'),
        'distinct_urls' => (int) \Illuminate\Support\Facades\DB::scalar('SELECT count(DISTINCT url) FROM public.news_items'),
        'raw_complete' => (int) \Illuminate\Support\Facades\DB::scalar("SELECT count(*) FROM public.news_items WHERE raw_payload_path IS NOT NULL AND raw_payload_hash IS NOT NULL"),
        'timestamps_complete' => (int) \Illuminate\Support\Facades\DB::scalar("SELECT count(*) FROM public.news_items WHERE first_seen_at IS NOT NULL AND available_at IS NOT NULL"),
        'rows' => $rows,
        'writes_executed' => false,
        'secret_printed' => false,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === '--coverage-acl-audit') {
    $tables = ['eod_session_queues', 'news_items'];
    $privileges = [];
    foreach ($tables as $table) {
        $privileges[$table] = [
            'select' => (bool) \Illuminate\Support\Facades\DB::selectOne("select has_table_privilege('aether_ingestion', 'public.{$table}', 'SELECT') as ok")->ok,
            'insert' => (bool) \Illuminate\Support\Facades\DB::selectOne("select has_table_privilege('aether_ingestion', 'public.{$table}', 'INSERT') as ok")->ok,
            'update' => (bool) \Illuminate\Support\Facades\DB::selectOne("select has_table_privilege('aether_ingestion', 'public.{$table}', 'UPDATE') as ok")->ok,
            'delete' => (bool) \Illuminate\Support\Facades\DB::selectOne("select has_table_privilege('aether_ingestion', 'public.{$table}', 'DELETE') as ok")->ok,
        ];
    }
    $missing = \Illuminate\Support\Facades\DB::select("select r.ticker from public.idx_company_registries r where not exists (select 1 from public.market_observations o where o.ticker = r.ticker and o.source = 'IDX Edge PRO') order by r.ticker");
    echo json_encode([
        'status' => 'READ_ONLY',
        'identity' => \Illuminate\Support\Facades\DB::selectOne('select current_database() as database, current_user as user_name, current_schema() as schema_name'),
        'counts' => [
            'registries' => (int) \Illuminate\Support\Facades\DB::scalar('select count(*) from public.idx_company_registries'),
            'observed_idx_edge_instruments' => (int) \Illuminate\Support\Facades\DB::scalar("select count(distinct ticker) from public.market_observations where source = 'IDX Edge PRO' and ticker not in ('^JKSE', 'JKSE', 'IHSG')"),
        ],
        'registries_without_idx_edge_observation' => array_map(static fn ($row) => $row->ticker, $missing),
        'acl' => $privileges,
        'writes_executed' => false,
        'secret_printed' => false,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === '--provider-probe') {
    $client = app(\App\Services\IdxEdgeClient::class);
    $results = [];
    $probeRun = gmdate('Ymd_His');
    $receiptDir = 'E:/Aether-Market-Intelligence/storage/runtime/provider-capability-probes/'.$probeRun;
    if (! is_dir($receiptDir)) {
        mkdir($receiptDir, 0700, true);
    }
    $sanitize = static function (mixed $value) use (&$sanitize): mixed {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $keyText = strtolower((string) $key);
                $clean[$key] = preg_match('/key|token|secret|password|authorization|credential/i', $keyText)
                    ? '[REDACTED]'
                    : $sanitize($item);
            }
            return $clean;
        }
        return is_scalar($value) || $value === null ? $value : '[REDACTED]';
    };
    $definitions = [
        'price_ihsg' => ['ticker' => 'BMRI', 'path' => '/api/price/IHSG'],
        'chart' => ['ticker' => 'BMRI', 'path' => '/api/chart/BMRI?interval=300'],
        'screener' => ['ticker' => 'BMRI', 'path' => '/api/screener/latest'],
        'movers' => ['ticker' => 'BMRI', 'path' => '/api/movers?by=chg&n=10'],
        'financial_statements' => ['ticker' => 'BMRI', 'path' => '/api/financial-statements/BMRI?report_type=INCOME_STATEMENT&period=quarterly&limit=12'],
        'insiders' => ['ticker' => 'BBCA', 'path' => '/api/insiders/BBCA'],
        'broker_accumulation' => ['ticker' => 'BBCA', 'path' => '/api/broker-accumulation/BBCA'],
    ];
    foreach ($definitions as $endpointKey => $definition) {
        $probe = $client->probeCapabilityEndpoint($endpointKey, $definition['ticker']);
        $raw = $sanitize($probe['raw_response'] ?? null);
        $rawPath = $receiptDir.'/'.$endpointKey.'.raw.json';
        file_put_contents($rawPath, json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX);
        $results[$endpointKey] = [
            'status' => ($probe['success'] ?? false) ? 'HTTP_SUCCESS_SCHEMA_UNVERIFIED' : 'PROVIDER_CAPABILITY_UNVERIFIED',
            'error_type' => $probe['error_type'] ?? null,
            'http_status' => $probe['http_status'] ?? null,
            'message' => $probe['message'] ?? null,
            'path' => $definition['path'],
            'raw_response_path' => $rawPath,
            'raw_response_hash' => hash_file('sha256', $rawPath),
            'raw_response_saved' => true,
            'schema_keys' => $probe['data']['top_level_keys'] ?? [],
            'array_keys' => $probe['data']['array_keys'] ?? [],
            'received_at' => $probe['received_at'] ?? null,
            'response_period' => $probe['response_period'] ?? null,
            'quota' => $probe['quota'] ?? null,
        ];
    }
    $receiptPath = $receiptDir.'/receipt.json';
    file_put_contents($receiptPath, json_encode([
        'status' => 'READ_ONLY',
        'retrieved_at_utc' => gmdate('c'),
        'provider' => 'IDX_EDGE',
        'endpoints' => $results,
        'flags_enabled' => ['insiders' => false, 'screener' => false, 'movers' => false],
        'secret_printed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo json_encode(['status' => 'READ_ONLY', 'receipt_path' => $receiptPath, 'endpoints' => $results, 'flags_enabled' => ['insiders' => false, 'screener' => false, 'movers' => false], 'writes_executed' => false, 'secret_printed' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === '--provider-probe-legacy') {
    $endpoint = rtrim((string) config('services.ai_router.endpoint'), '/').'/chat/completions';
    $model = (string) config('services.ai_router.model_id');
    $apiKey = (string) config('services.ai_router.api_key');
    $payload = json_encode([
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => 'Reply only: PROVIDER_READY']],
        'stream' => false,
        'max_tokens' => 8,
    ], JSON_THROW_ON_ERROR);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => array_filter([
            'Content-Type: application/json',
            $apiKey !== '' ? 'Authorization: Bearer '.$apiKey : null,
        ]),
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $retryAfter = curl_getinfo($ch, CURLINFO_RETRY_AFTER);
    $curlError = curl_error($ch);
    curl_close($ch);
    echo json_encode([
        'status' => $httpCode === 200 && is_string($raw) && $raw !== '' ? 'READY' : 'BLOCKED',
        'provider' => 'custom_local_router',
        'endpoint' => preg_replace('/:\/\/[^\/]+/', '://[LOCAL]', $endpoint),
        'model' => $model,
        'credential_present' => $apiKey !== '',
        'http_status' => $httpCode,
        'retry_after_present' => $retryAfter !== false && $retryAfter !== null && $retryAfter !== '',
        'transport_error' => $curlError !== '' ? 'present' : 'none',
        'secret_printed' => false,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit($httpCode === 200 ? 0 : 7);
}

try {
    $identity = \Illuminate\Support\Facades\DB::selectOne('select current_database() as db, current_user as usr');
} catch (Throwable $e) {
    fwrite(STDERR, "APP_WRITER_ABORT connection failed\n");
    exit(5);
}

if ($identity->db !== $db || $identity->usr !== $role) {
    fwrite(STDERR, "APP_WRITER_ABORT identity mismatch: {$identity->db}/{$identity->usr}\n");
    exit(5);
}

if ($mode === '--research-request-audit') {
    $audit = \Illuminate\Support\Facades\DB::selectOne("SELECT current_database() AS database, current_user AS user_name, current_schema() AS schema_name, current_setting('default_transaction_read_only') AS default_transaction_read_only, has_table_privilege(current_user, 'public.research_requests', 'SELECT,INSERT,UPDATE') AS request_dml, has_sequence_privilege(current_user, 'public.research_requests_id_seq', 'USAGE,SELECT') AS request_sequence, has_table_privilege(current_user, 'public.research_requests', 'DELETE,TRUNCATE') AS destructive, (SELECT count(*) FROM public.research_requests) AS request_count");
    echo json_encode(['status' => 'RESEARCH_REQUEST_AUDIT', 'identity' => $audit, 'writes_executed' => false, 'secret_printed' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === '--research-request-status') {
    $requestId = $argv[2] ?? '';
    $request = \Illuminate\Support\Facades\DB::table('research_requests')->where('request_id', $requestId)->first();
    echo json_encode(['status' => 'RESEARCH_REQUEST_STATUS', 'request' => $request, 'writes_executed' => false, 'secret_printed' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === '--research-acceptance-audit') {
    $connection = \Illuminate\Support\Facades\DB::connection();
    $timeContext = $connection->selectOne("SELECT current_setting('TimeZone') AS session_timezone, current_setting('DateStyle') AS date_style, now() AS db_now_timestamptz, localtimestamp AS db_local_timestamp_without_timezone, timezone('UTC', now()) AS utc_timestamp_without_timezone, timezone('Asia/Jakarta', now()) AS wib_timestamp_without_timezone");
    $requests = $connection->select("SELECT r.request_id, r.ticker, r.status, r.created_at AS request_created_at, r.updated_at AS request_updated_at, r.evidence_snapshot_id, r.market_analysis_id, r.error_reason, e.id AS evidence_row_id, e.created_at AS evidence_created_at, e.updated_at AS evidence_updated_at, e.snapshot_id, e.decision_date::text AS evidence_date, e.data_hash AS evidence_hash, e.features_snapshot::text AS evidence_features_json, a.id AS analysis_row_id, a.model_name, a.validation_status, a.structured_output->>'bar_date' AS analysis_bar_date, a.structured_output->>'fallback_reason' AS fallback_reason, a.structured_output->>'model_status' AS model_status, a.structured_output->>'latency_ms' AS latency_ms, a.structured_output->>'requested_model' AS requested_model, a.structured_output->>'returned_model' AS returned_model, a.structured_output->>'endpoint' AS analysis_endpoint, a.structured_output->>'effective_configuration_source' AS effective_configuration_source FROM public.research_requests r LEFT JOIN public.evidence_snapshots e ON e.id = r.evidence_snapshot_id LEFT JOIN public.market_analyses a ON a.id = r.market_analysis_id WHERE r.request_id IN ('REQ_20261116_225823_DEWA_LmvC','REQ_20261116_230400_BBCA_Uyz0','REQ_20261116_230718_TLKM_7ZNj','REQ_20261117_032147_DEWA_k5vq','REQ_20261117_040043_BBCA_ck0s','REQ_20261117_040045_TLKM_nYgX','REQ_20261117_134313_BJTM_KONF') ORDER BY r.id");
    $observations = $connection->select("SELECT ticker, id, bar_date::text AS bar_date, source, board_scope, adjustment_mode, validation_status, raw_payload_hash, research_request_id FROM public.market_observations WHERE ticker IN ('DEWA','BBCA','TLKM','BJTM') AND source='IDX Edge PRO' AND board_scope='RG' AND adjustment_mode='UNADJUSTED' ORDER BY ticker, bar_date DESC LIMIT 30");
    $metrics = $connection->select("SELECT ticker, as_of_date::text AS as_of_date, technical_status, data_quality_flags->>'adjustment_mode' AS metric_adjustment_mode, data_quality_flags->>'source' AS metric_source, data_quality_flags->>'board_scope' AS metric_board_scope, data_quality_flags->>'input_bars' AS metric_input_bars, data_quality_flags->>'last_date' AS metric_last_date FROM public.company_analysis_metrics WHERE ticker IN ('DEWA','BBCA','TLKM','BJTM') ORDER BY ticker, as_of_date DESC LIMIT 16");
    $brokers = $connection->select("SELECT ticker, summary_date::text AS summary_date, count(*)::int AS rows FROM public.broker_summaries WHERE ticker IN ('DEWA','BBCA','TLKM','BJTM') GROUP BY ticker, summary_date ORDER BY ticker, summary_date DESC LIMIT 16");
    $reconciliation = $connection->select("SELECT ticker, id, research_request_id, bar_date::text AS bar_date, interval, data_classification, source, board_scope, adjustment_mode, validation_status, raw_payload_path, raw_payload_hash, data_hash FROM public.market_observations WHERE ticker IN ('DEWA','TLKM','BJTM') AND bar_date='2026-11-16' ORDER BY ticker, adjustment_mode, id");
    $evidenceFeatures = $connection->select("SELECT e.id, e.snapshot_id, e.ticker, e.decision_date::text AS decision_date, e.data_hash, e.features_snapshot::text AS evidence_features_json, e.features_snapshot->>'bar_date' AS feature_bar_date, e.features_snapshot->>'adjustment_mode' AS feature_adjustment_mode, e.features_snapshot->>'source' AS feature_source, e.features_snapshot->>'board_scope' AS feature_board_scope, e.features_snapshot->>'validation_status' AS feature_validation_status, e.features_snapshot->>'input_bars' AS feature_input_bars FROM public.evidence_snapshots e WHERE e.id IN (SELECT evidence_snapshot_id FROM public.research_requests WHERE request_id IN ('REQ_20261117_032147_DEWA_k5vq','REQ_20261117_040045_TLKM_nYgX')) ORDER BY e.id");
    echo json_encode(['status'=>'RESEARCH_ACCEPTANCE_AUDIT','time_context'=>$timeContext,'requests'=>$requests,'observations'=>$observations,'metrics'=>$metrics,'brokers'=>$brokers,'reconciliation'=>$reconciliation,'evidence_features'=>$evidenceFeatures,'config'=>['dashboard_ai_provider'=>config('services.dashboard_ai.provider'),'dashboard_ai_model'=>config('services.dashboard_ai.model'),'dashboard_ai_endpoint'=>preg_replace('/:\/\/[^\/]+/','://[LOCAL]',(string) config('services.dashboard_ai.base_url')),'on_demand_enabled'=>(bool) config('services.dashboard_ai.on_demand_enabled'),'api_key_present'=>(string) config('services.dashboard_ai.api_key') !== ''],'writes_executed'=>false,'secret_printed'=>false], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === '--coverage-session-audit') {
    $session = $options['session'] ?? '2026-11-16';
    if (! preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $session)) {
        fwrite(STDERR, "coverage-session-audit requires --session=YYYY-MM-DD\\n");
        exit(2);
    }
    $connection = \Illuminate\Support\Facades\DB::connection();
    $eligible = (int) $connection->scalar("SELECT count(*) FROM public.idx_company_registries WHERE instrument_type='EQUITY' AND (backfill_status IS NULL OR backfill_status <> 'UNSUPPORTED_SHARE_CLASS')");
    $sql = "WITH eligible AS (SELECT ticker FROM public.idx_company_registries WHERE instrument_type='EQUITY' AND (backfill_status IS NULL OR backfill_status <> 'UNSUPPORTED_SHARE_CLASS')), flags AS (SELECT e.ticker, bool_or(o.adjustment_mode='UNADJUSTED' AND o.source='IDX Edge PRO' AND o.board_scope='RG' AND o.validation_status='VALID') AS raw, bool_or(o.adjustment_mode='PROVIDER_BACK_ADJUSTED' AND o.source='IDX Edge PRO' AND o.board_scope='RG' AND o.validation_status='VALID') AS adjusted, bool_or(o.validation_status IN ('CONFLICTED','OHLC_INVALID')) AS conflicted_or_invalid FROM eligible e LEFT JOIN public.market_observations o ON o.ticker=e.ticker AND o.bar_date=:session GROUP BY e.ticker), classified AS (SELECT f.*, CASE WHEN conflicted_or_invalid THEN 'conflicted_or_invalid' WHEN raw AND adjusted THEN 'complete_raw_adjusted' WHEN raw THEN 'raw_only' WHEN adjusted THEN 'adjusted_only' ELSE 'neither' END AS partition FROM flags f), categories AS (SELECT * FROM (VALUES ('complete_raw_adjusted'), ('raw_only'), ('adjusted_only'), ('neither'), ('conflicted_or_invalid')) AS v(partition)) SELECT categories.partition, count(classified.ticker)::int AS tickers FROM categories LEFT JOIN classified ON classified.partition=categories.partition GROUP BY categories.partition ORDER BY CASE categories.partition WHEN 'complete_raw_adjusted' THEN 1 WHEN 'raw_only' THEN 2 WHEN 'adjusted_only' THEN 3 WHEN 'neither' THEN 4 ELSE 5 END";
    $partitions = $connection->select($sql, ['session' => $session]);
    $metrics = $connection->select("WITH eligible AS (SELECT ticker FROM public.idx_company_registries WHERE instrument_type='EQUITY' AND (backfill_status IS NULL OR backfill_status <> 'UNSUPPORTED_SHARE_CLASS')), complete AS (SELECT e.ticker FROM eligible e WHERE EXISTS (SELECT 1 FROM public.market_observations o WHERE o.ticker=e.ticker AND o.bar_date=:session AND o.source='IDX Edge PRO' AND o.board_scope='RG' AND o.adjustment_mode='UNADJUSTED' AND o.validation_status='VALID') AND EXISTS (SELECT 1 FROM public.market_observations o WHERE o.ticker=e.ticker AND o.bar_date=:session AND o.source='IDX Edge PRO' AND o.board_scope='RG' AND o.adjustment_mode='PROVIDER_BACK_ADJUSTED' AND o.validation_status='VALID')) SELECT count(*) FILTER (WHERE EXISTS (SELECT 1 FROM public.company_analysis_metrics m WHERE m.ticker=complete.ticker AND m.as_of_date=:session AND m.technical_status='CALCULATED' AND m.data_quality_flags->>'last_date'=m.as_of_date::text AND m.data_quality_flags->>'as_of_date'=m.as_of_date::text AND m.data_quality_flags->>'adjustment_mode'='PROVIDER_BACK_ADJUSTED' AND m.data_quality_flags->>'source'='IDX Edge PRO' AND m.data_quality_flags->>'board_scope'='RG'))::int AS metric_match, count(*)::int AS complete_raw_adjusted FROM complete", ['session' => $session]);
    $strictResiduals = $connection->select("WITH eligible AS (
            SELECT ticker FROM public.idx_company_registries
            WHERE instrument_type='EQUITY' AND (backfill_status IS NULL OR backfill_status <> 'UNSUPPORTED_SHARE_CLASS')
        ), loose_complete AS (
            SELECT e.ticker FROM eligible e
            WHERE EXISTS (SELECT 1 FROM public.market_observations o WHERE o.ticker=e.ticker AND o.bar_date=:session AND o.source='IDX Edge PRO' AND o.board_scope='RG' AND o.adjustment_mode='UNADJUSTED' AND o.validation_status='VALID')
              AND EXISTS (SELECT 1 FROM public.market_observations o WHERE o.ticker=e.ticker AND o.bar_date=:session AND o.source='IDX Edge PRO' AND o.board_scope='RG' AND o.adjustment_mode='PROVIDER_BACK_ADJUSTED' AND o.validation_status='VALID')
        ), strict_complete AS (
            SELECT e.ticker FROM eligible e
            WHERE EXISTS (SELECT 1 FROM public.market_observations o WHERE o.ticker=e.ticker AND o.bar_date=:session AND o.interval='1d' AND o.data_classification='PUBLIC_MARKET_EOD' AND o.source='IDX Edge PRO' AND o.board_scope='RG' AND o.adjustment_mode='UNADJUSTED' AND o.validation_status='VALID')
              AND EXISTS (SELECT 1 FROM public.market_observations o WHERE o.ticker=e.ticker AND o.bar_date=:session AND o.interval='1d' AND o.data_classification='PUBLIC_MARKET_EOD' AND o.source='IDX Edge PRO' AND o.board_scope='RG' AND o.adjustment_mode='PROVIDER_BACK_ADJUSTED' AND o.validation_status='VALID')
        ), strict_metric AS (
            SELECT DISTINCT m.ticker FROM public.company_analysis_metrics m
            WHERE m.as_of_date=:session AND m.technical_status IN ('CALCULATED','PARTIAL_CALCULATED')
                          AND m.close_price IS NOT NULL
                          AND m.rsi_14 IS NOT NULL
                          AND m.sma_20 IS NOT NULL
                          AND m.sma_50 IS NOT NULL
                          AND m.macd IS NOT NULL
                          AND m.atr_14 IS NOT NULL
                          AND m.data_quality_flags->>'last_date'=m.as_of_date::text
                          AND m.data_quality_flags->>'as_of_date'=m.as_of_date::text
                          AND m.data_quality_flags->>'adjustment_mode'='PROVIDER_BACK_ADJUSTED'
                          AND m.data_quality_flags->>'source'='IDX Edge PRO'
                          AND m.data_quality_flags->>'board_scope'='RG'
                          AND jsonb_exists_all(m.data_quality_flags->'formulas', ARRAY['sma','rsi','atr','macd'])
        )
        SELECT l.ticker,
            CASE WHEN s.ticker IS NULL THEN 'CANONICAL_KEY_MISMATCH'
                 WHEN m.ticker IS NULL THEN 'METRIC_MISMATCH'
                 ELSE 'NONE' END AS classification
        FROM loose_complete l
        LEFT JOIN strict_complete s ON s.ticker=l.ticker
        LEFT JOIN strict_metric m ON m.ticker=l.ticker
        WHERE s.ticker IS NULL OR m.ticker IS NULL
        ORDER BY l.ticker", ['session'=>$session]);
    echo json_encode(['status'=>'COVERAGE_SESSION_AUDIT','session'=>$session,'eligible'=>$eligible,'partitions'=>$partitions,'metrics'=>$metrics,'strict_residuals'=>$strictResiduals,'writes_executed'=>false,'secret_printed'=>false], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === '--research-request') {
    $rawPayload = stream_get_contents(STDIN, 16384);
    $payload = json_decode($rawPayload ?: '', true);
    $ticker = is_array($payload) ? strtoupper(trim((string) ($payload['ticker'] ?? ''))) : '';
    $analysisTypeUpper = is_array($payload) ? strtoupper(trim((string) ($payload['analysis_type'] ?? 'COMPREHENSIVE'))) : '';
    $analysisType = strtolower($analysisTypeUpper);
    $question = is_array($payload) ? trim((string) ($payload['question'] ?? '')) : '';
    $allowedTypes = ['COMPREHENSIVE', 'TECHNICAL', 'FUNDAMENTAL', 'SWING'];

    if (! is_array($payload) || ! preg_match('/^[A-Z]{4}$/', $ticker) || ! in_array($analysisTypeUpper, $allowedTypes, true)) {
        echo json_encode(['status' => 'REJECTED_INVALID_REQUEST', 'reason' => 'ticker_or_analysis_type_invalid', 'secret_printed' => false], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(2);
    }

    $company = \App\Models\IdxCompanyRegistry::where('ticker', $ticker)->first();
    if (! $company) {
        echo json_encode(['status' => 'REJECTED_UNKNOWN_TICKER', 'ticker' => $ticker, 'secret_printed' => false], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(2);
    }

    $active = \App\Models\ResearchRequest::where('ticker', $ticker)
        ->whereIn('status', ['pending', 'collecting', 'analyzing'])
        ->latest('id')
        ->first();
    if ($active) {
        echo json_encode(['status' => 'DEDUPLICATED', 'request_id' => $active->request_id, 'ticker' => $ticker, 'research_status' => strtoupper($active->status), 'secret_printed' => false], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }

    $requestId = 'REQ_'.now('Asia/Jakarta')->format('Ymd_His')."_{$ticker}_".\Illuminate\Support\Str::random(4);
    $deadline = now('UTC')->addMinutes(10);
    $request = \App\Models\ResearchRequest::create([
        'request_id' => $requestId,
        'chat_id' => null,
        'user_id' => 'web_analyst',
        'ticker' => $ticker,
        'company_name' => $company->company_name,
        'exchange' => 'IDX',
        'analysis_type' => $analysisType,
        'data_type_requested' => 'eod',
        'horizon' => 'multi_day',
        'as_of_time' => now('UTC'),
        'status' => 'pending',
        'freshness_classification' => 'NOT_FETCHED',
        'research_metadata' => [
            'source' => 'web_dashboard_writer_bridge',
            'actor' => 'web_analyst',
            'authorization_scope' => 'research_request:create',
            'analysis_type' => $analysisType,
            'question' => $question !== '' ? $question : null,
            'deadline_at' => $deadline->toIso8601String(),
            'requested_at' => now('UTC')->toIso8601String(),
        ],
    ]);

    echo json_encode(['status' => 'QUEUED', 'request_id' => $request->request_id, 'ticker' => $ticker, 'research_status' => 'PENDING', 'deadline_at' => $deadline->toIso8601String(), 'writer_identity' => ['database' => $identity->db, 'user' => $identity->usr], 'telegram_queued' => false, 'secret_printed' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === '--briefing-acl-audit') {
    $tables = ['analysis_runs', 'evidence_snapshots', 'research_requests', 'prediction_signals', 'monitoring_events', 'telegram_outbox', 'notification_logs', 'scheduled_telegram_jobs'];
    $sequences = ['analysis_runs_id_seq', 'evidence_snapshots_id_seq', 'research_requests_id_seq', 'prediction_signals_id_seq', 'monitoring_events_id_seq', 'telegram_outbox_id_seq', 'notification_logs_id_seq', 'scheduled_telegram_jobs_id_seq'];
    $acl = [];
    foreach ($tables as $table) {
        $acl[$table] = [
            'select' => (bool) \Illuminate\Support\Facades\DB::selectOne("SELECT has_table_privilege(current_user, 'public.{$table}', 'SELECT') AS ok")->ok,
            'insert' => (bool) \Illuminate\Support\Facades\DB::selectOne("SELECT has_table_privilege(current_user, 'public.{$table}', 'INSERT') AS ok")->ok,
            'update' => (bool) \Illuminate\Support\Facades\DB::selectOne("SELECT has_table_privilege(current_user, 'public.{$table}', 'UPDATE') AS ok")->ok,
            'delete' => (bool) \Illuminate\Support\Facades\DB::selectOne("SELECT has_table_privilege(current_user, 'public.{$table}', 'DELETE') AS ok")->ok,
            'truncate' => (bool) \Illuminate\Support\Facades\DB::selectOne("SELECT has_table_privilege(current_user, 'public.{$table}', 'TRUNCATE') AS ok")->ok,
        ];
    }
    foreach ($sequences as $sequence) {
        $exists = (bool) \Illuminate\Support\Facades\DB::scalar("SELECT to_regclass('public.{$sequence}') IS NOT NULL");
        $acl[$sequence] = $exists ? [
            'usage' => (bool) \Illuminate\Support\Facades\DB::selectOne("SELECT has_sequence_privilege(current_user, 'public.{$sequence}', 'USAGE') AS ok")->ok,
            'select' => (bool) \Illuminate\Support\Facades\DB::selectOne("SELECT has_sequence_privilege(current_user, 'public.{$sequence}', 'SELECT') AS ok")->ok,
        ] : ['exists' => false];
    }
    $writes = [
        'analysis_runs' => ['select', 'insert', 'update'],
        'evidence_snapshots' => ['select', 'insert', 'update'],
        'research_requests' => ['select', 'insert', 'update'],
        'prediction_signals' => ['select', 'insert', 'update'],
        'monitoring_events' => ['select', 'insert'],
        'telegram_outbox' => ['select', 'insert', 'update'],
        'notification_logs' => ['select', 'insert', 'update'],
        'scheduled_telegram_jobs' => ['select', 'update'],
    ];
    $required = true;
    foreach ($writes as $table => $privileges) {
        foreach ($privileges as $privilege) {
            $required = $required && $acl[$table][$privilege];
        }
    }
    foreach (['analysis_runs_id_seq', 'evidence_snapshots_id_seq', 'prediction_signals_id_seq', 'monitoring_events_id_seq', 'telegram_outbox_id_seq', 'notification_logs_id_seq'] as $sequence) {
        $required = $required && (($acl[$sequence]['usage'] ?? false) && ($acl[$sequence]['select'] ?? false));
    }
    $destructive = false;
    foreach ($tables as $table) {
        $destructive = $destructive || $acl[$table]['delete'] || $acl[$table]['truncate'];
    }
    echo json_encode([
        'status' => $required && ! $destructive ? 'READ_ONLY_ACL_READY' : 'READ_ONLY_ACL_INSUFFICIENT',
        'identity' => ['database' => $identity->db, 'user' => $identity->usr],
        'required_privileges_satisfied' => $required,
        'destructive_privileges_present' => $destructive,
        'acl' => $acl,
        'writes_executed' => false,
        'secret_printed' => false,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit($required && ! $destructive ? 0 : 7);
}

if ($mode === '--briefing-slot-audit') {
    $briefingDate = (string) $options['date'];
    $compactDate = str_replace('-', '', $briefingDate);
    $analysisRuns = \App\Models\AnalysisRun::where('trigger_type', 'morning_briefing_0830')
        ->where(function ($query) use ($briefingDate, $compactDate) {
            $query->where('metadata->briefing_date', $briefingDate)
                ->orWhere('run_id', 'like', "RUN_BRIEFING_{$compactDate}_%");
        })
        ->orderBy('id')
        ->get(['id', 'run_id', 'status', 'trigger_type', 'started_at', 'completed_at', 'error_message', 'metadata']);
    $signals = \App\Models\PredictionSignal::whereDate('decision_date', $briefingDate)
        ->whereRaw("metadata->>'plan_id' LIKE ?", ["PLAN_%_{$compactDate}_0830_%"])
        ->orderBy('id')
        ->get(['id', 'prediction_id', 'ticker', 'signal_status', 'monitoring_status', 'evaluation_status', 'decision_date', 'published_at', 'telegram_message_id', 'metadata']);
    $outboxes = \App\Models\TelegramOutbox::where('message_type', 'SYSTEM_ALERT')
        ->where('metadata->briefing_date', $briefingDate)
        ->orderBy('id')
        ->get(['id', 'dedup_key', 'status', 'retry_count', 'telegram_message_id', 'sent_at', 'error_message', 'payload_text', 'created_at', 'updated_at', 'metadata']);
    echo json_encode([
        'status' => 'READ_ONLY',
        'identity' => ['database' => $identity->db, 'user' => $identity->usr],
        'briefing_date' => $briefingDate,
        'slot' => '08:30',
        'analysis_runs' => $analysisRuns,
        'candidate_count' => $signals->count(),
        'candidates' => $signals,
        'outbox_count' => $outboxes->count(),
        'outboxes' => $outboxes,
        'payloads' => $outboxes->map(fn ($row) => ['id' => $row->id, 'payload_text' => $row->payload_text]),
        'receipt_count' => $outboxes->filter(fn ($row) => $row->telegram_message_id !== null)->count(),
        'writes_executed' => false,
        'provider_called' => false,
        'gemini_called' => false,
        'secret_printed' => false,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === '--briefing-audit') {
    $before = [
        'outbox' => (int) \Illuminate\Support\Facades\DB::scalar('SELECT count(*) FROM public.telegram_outbox'),
        'analysis_runs' => (int) \Illuminate\Support\Facades\DB::scalar('SELECT count(*) FROM public.analysis_runs'),
    ];
    $status = \Illuminate\Support\Facades\Artisan::call('hermes:morning-briefing', ['--dry-run' => true]);
    $after = [
        'outbox' => (int) \Illuminate\Support\Facades\DB::scalar('SELECT count(*) FROM public.telegram_outbox'),
        'analysis_runs' => (int) \Illuminate\Support\Facades\DB::scalar('SELECT count(*) FROM public.analysis_runs'),
    ];
    echo json_encode([
        'status' => $status === 0 && $before === $after ? 'READY_DRY_RUN' : 'FAILED_DRY_RUN',
        'identity' => ['database' => $identity->db, 'user' => $identity->usr],
        'command_exit' => $status,
        'before' => $before,
        'after' => $after,
        'writes_executed' => false,
        'outbox_created' => false,
        'receipt_verified' => false,
        'intraday_claims' => false,
        'secret_printed' => false,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit($status === 0 && $before === $after ? 0 : 7);
}

if ($mode === '--analysis-record-audit') {
    $analysisId = (int) ($options['analysis-id'] ?? 0);
    $evidenceId = (int) ($options['evidence-id'] ?? 0);
    $analysis = \App\Models\MarketAnalysis::find($analysisId);
    $evidence = \App\Models\EvidenceSnapshot::find($evidenceId);
    $request = \App\Models\ResearchRequest::where('market_analysis_id', $analysisId)->first();
    $output = $analysis?->structured_output ?? [];
    $features = $evidence?->features_snapshot ?? [];
    echo json_encode([
        'status' => 'READ_ONLY',
        'identity' => ['database' => $identity->db, 'user' => $identity->usr],
        'analysis' => $analysis?->only(['id', 'model_name', 'validation_status', 'confidence_score', 'structured_output', 'created_at']),
        'evidence' => $evidence?->only(['id', 'snapshot_id', 'ticker', 'decision_date', 'features_snapshot', 'related_news_snapshot', 'data_hash']),
        'request' => $request?->only(['request_id', 'ticker', 'status', 'evidence_snapshot_id', 'market_analysis_id', 'research_metadata']),
        'checks' => [
            'relation' => $request?->evidence_snapshot_id === $evidenceId && $request?->market_analysis_id === $analysisId,
            'ticker_match' => ($output['ticker'] ?? $evidence?->ticker) === $evidence?->ticker,
            'bar_date_match' => ($output['bar_date'] ?? null) === ($features['bar_date'] ?? $evidence?->decision_date?->toDateString()),
            'evidence_source' => $features['source'] ?? null,
            'evidence_unit_fields_present' => array_key_exists('volume', $features),
            'trading_plan_present' => is_array($output['trading_plan'] ?? null),
        ],
        'writes_executed' => false,
        'provider_called' => false,
        'gemini_called' => false,
        'secret_printed' => false,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === '--analysis-audit') {
    $executor = app(\App\Services\AiToolExecutorService::class);
    $reflection = new \ReflectionMethod($executor, 'formatGroundedInstrumentAnalysis');
    $reflection->setAccessible(true);
    $payload = $reflection->invoke($executor, $ticker);
    echo json_encode([
        'status' => 'READ_ONLY',
        'identity' => ['database' => $identity->db, 'user' => $identity->usr],
        'ticker' => $ticker,
        'payload' => $payload,
        'provider_called' => false,
        'gemini_called' => false,
        'writes_executed' => false,
        'outbox_created' => false,
        'secret_printed' => false,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === '--telegram-audit') {
    $privileges = \Illuminate\Support\Facades\DB::selectOne("
        SELECT
            current_database() AS db,
            current_user AS usr,
            current_schema() AS schema,
            has_table_privilege('aether_ingestion', 'public.scheduled_telegram_jobs', 'SELECT') AS job_select,
            has_table_privilege('aether_ingestion', 'public.scheduled_telegram_jobs', 'INSERT') AS job_insert,
            has_table_privilege('aether_ingestion', 'public.scheduled_telegram_jobs', 'UPDATE') AS job_update,
            has_table_privilege('aether_ingestion', 'public.scheduled_telegram_jobs', 'DELETE') AS job_delete,
            has_table_privilege('aether_ingestion', 'public.telegram_conversations', 'SELECT') AS conv_select,
            has_table_privilege('aether_ingestion', 'public.telegram_conversations', 'INSERT') AS conv_insert,
            has_table_privilege('aether_ingestion', 'public.telegram_conversations', 'UPDATE') AS conv_update,
            has_table_privilege('aether_ingestion', 'public.telegram_conversations', 'DELETE') AS conv_delete,
            has_table_privilege('aether_ingestion', 'public.telegram_outbox', 'SELECT') AS outbox_select,
            has_table_privilege('aether_ingestion', 'public.telegram_outbox', 'INSERT') AS outbox_insert,
            has_table_privilege('aether_ingestion', 'public.telegram_outbox', 'UPDATE') AS outbox_update,
            has_table_privilege('aether_ingestion', 'public.telegram_outbox', 'DELETE') AS outbox_delete,
            has_sequence_privilege('aether_ingestion', 'public.scheduled_telegram_jobs_id_seq', 'USAGE') AS job_seq_usage,
            has_sequence_privilege('aether_ingestion', 'public.scheduled_telegram_jobs_id_seq', 'SELECT') AS job_seq_select,
            has_sequence_privilege('aether_ingestion', 'public.telegram_conversations_id_seq', 'USAGE') AS conv_seq_usage,
            has_sequence_privilege('aether_ingestion', 'public.telegram_conversations_id_seq', 'SELECT') AS conv_seq_select,
            has_sequence_privilege('aether_ingestion', 'public.telegram_outbox_id_seq', 'USAGE') AS outbox_seq_usage,
            has_sequence_privilege('aether_ingestion', 'public.telegram_outbox_id_seq', 'SELECT') AS outbox_seq_select
    ");

    $job = \App\Models\ScheduledTelegramJob::find((int) $options['job-id']);
    if (! $job) {
        $conversation = \App\Models\TelegramConversation::where('provenance->async_consultation_job_id', (int) $options['job-id'])->first();
        $outboxes = \App\Models\TelegramOutbox::where('metadata->scheduled_job_id', (int) $options['job-id'])
            ->orderBy('id')
            ->get(['id', 'status', 'retry_count', 'retry_after', 'telegram_message_id', 'sent_at', 'error_message', 'created_at', 'updated_at', 'metadata']);
        echo json_encode([
            'status' => 'missing_job',
            'identity' => ['database' => $identity->db, 'user' => $identity->usr, 'schema' => $privileges->schema],
            'privileges' => $privileges,
            'job_id' => (int) $options['job-id'],
            'conversation' => $conversation?->only(['id', 'telegram_update_id', 'inbound_message_id', 'detected_intent', 'response_time_ms', 'created_at', 'updated_at', 'provenance']),
            'outboxes' => $outboxes,
            'table_counts' => [
                'scheduled_telegram_jobs' => \App\Models\ScheduledTelegramJob::count(),
                'telegram_conversations' => \App\Models\TelegramConversation::count(),
                'telegram_outbox' => \App\Models\TelegramOutbox::count(),
            ],
            'recent_jobs' => \App\Models\ScheduledTelegramJob::latest('id')->limit(5)->get(['id', 'telegram_update_id', 'inbound_message_id', 'request_type', 'status', 'delivery_status', 'created_at', 'updated_at']),
            'recent_conversations' => \App\Models\TelegramConversation::latest('id')->limit(5)->get(['id', 'telegram_update_id', 'chat_id', 'user_id', 'inbound_text', 'detected_intent', 'outbound_reply_text', 'provenance', 'created_at', 'updated_at']),
            'recent_outboxes' => \App\Models\TelegramOutbox::latest('id')->limit(5)->get(['id', 'status', 'telegram_message_id', 'created_at', 'updated_at', 'metadata']),
            'analysis' => null,
            'evidence' => null,
        ], JSON_THROW_ON_ERROR);
        exit(7);
    }

    $conversation = $job->telegram_update_id
        ? \App\Models\TelegramConversation::where('telegram_update_id', $job->telegram_update_id)->first()
        : null;
    $outbox = $job->outbox_id
        ? \App\Models\TelegramOutbox::find($job->outbox_id)
        : \App\Models\TelegramOutbox::where('metadata->scheduled_job_id', $job->id)->latest('id')->first();
    $correctionOutbox = \App\Models\TelegramOutbox::where('metadata->supersedes_job_id', $job->id)
        ->where('message_type', 'SYSTEM_CORRECTION')
        ->latest('id')
        ->first();

    $jobParameters = is_array($job->parameters) ? $job->parameters : [];
    $auditTicker = strtoupper((string) ($jobParameters['ticker'] ?? 'BBCA'));
    $latestBrokerDate = \App\Models\BrokerSummary::where('ticker', $auditTicker)->max('summary_date');
    $brokerRows = $latestBrokerDate
        ? \App\Models\BrokerSummary::where('ticker', $auditTicker)
            ->whereDate('summary_date', $latestBrokerDate)
            ->where('flow_type', 'all')
            ->orderByDesc('net_value')
            ->get(['ticker', 'summary_date', 'broker_code', 'broker_name', 'board', 'buy_volume', 'buy_value', 'buy_avg_price', 'sell_volume', 'sell_value', 'sell_avg_price', 'net_volume', 'net_value', 'flow_type', 'unit', 'currency', 'source', 'data_quality', 'fetched_at'])
        : collect();
    $technicalMetric = \App\Models\CompanyAnalysisMetric::where('ticker', $auditTicker)->latest('as_of_date')->first();
    $latestAdjustedBars = \App\Models\MarketObservation::where('ticker', $auditTicker)
        ->where('source', 'IDX Edge PRO')
        ->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')
        ->where('validation_status', 'VALID')
        ->orderByDesc('bar_date')
        ->limit(5)
        ->get(['ticker', 'bar_date', 'open_price', 'high_price', 'low_price', 'close_price', 'volume', 'board_scope', 'adjustment_mode', 'validation_status', 'fetched_at', 'available_at']);
    $evidenceRecord = $job->evidence_id ? \App\Models\EvidenceSnapshot::find($job->evidence_id) : null;

    echo json_encode([
        'status' => 'ok',
        'identity' => ['database' => $identity->db, 'user' => $identity->usr, 'schema' => $privileges->schema],
        'privileges' => $privileges,
        'job' => $job->only([
            'id', 'telegram_update_id', 'inbound_message_id', 'request_type', 'status', 'delivery_status',
            'scheduled_at', 'deadline_at', 'started_at', 'heartbeat_at', 'worker_pid', 'worker_token',
            'worker_process_created_at', 'attempts', 'analysis_id', 'evidence_id', 'outbox_id',
            'outbound_message_id', 'analysis_completed_at', 'executed_at', 'sent_at', 'latency_ms', 'error_message',
        ]),
        'conversation' => $conversation?->only(['id', 'telegram_update_id', 'inbound_message_id', 'detected_intent', 'response_time_ms', 'created_at', 'updated_at', 'provenance']),
        'outbox' => $outbox?->only(['id', 'status', 'retry_count', 'retry_after', 'telegram_message_id', 'sent_at', 'error_message', 'created_at', 'updated_at', 'metadata']),
        'correction_outbox' => $correctionOutbox?->only(['id', 'status', 'retry_count', 'retry_after', 'telegram_message_id', 'sent_at', 'error_message', 'created_at', 'updated_at', 'metadata']),
        'analysis' => $job->analysis_id ? \App\Models\AnalysisRun::find($job->analysis_id)?->only(['id', 'run_id', 'status', 'trigger_type', 'metadata', 'created_at', 'completed_at']) : null,
        'evidence' => $job->evidence_id ? \App\Models\EvidenceSnapshot::find($job->evidence_id)?->only(['id', 'snapshot_id', 'ticker', 'decision_date', 'data_hash', 'created_at']) : null,
        'read_only_grounding_audit' => [
            'ticker' => $auditTicker,
            'broker_snapshot_date' => $latestBrokerDate,
            'broker_row_count' => $brokerRows->count(),
            'broker_rows' => $brokerRows,
            'technical_metric' => $technicalMetric?->only(['id', 'ticker', 'as_of_date', 'technical_status', 'close_price', 'sma_20', 'sma_50', 'sma_200', 'rsi_14', 'macd', 'macd_signal', 'macd_hist', 'support_price', 'resistance_price', 'data_quality_flags']),
            'latest_adjusted_bars' => $latestAdjustedBars,
            'evidence_features_snapshot' => $evidenceRecord?->features_snapshot,
            'writes_executed' => false,
        ],
    ], JSON_THROW_ON_ERROR);
    exit(0);
} elseif ($mode === '--requeue-consultation') {
    $updateId = (string) $options['update-id'];
    $messageId = (string) $options['message-id'];
    $conversation = \App\Models\TelegramConversation::where('telegram_update_id', $updateId)
        ->where('provenance->inbound_message_id', $messageId)
        ->first();
    if (! $conversation) {
        echo json_encode(['status' => 'NOT_VERIFIED', 'reason' => 'conversation_update_message_pair_not_found', 'telegram_update_id' => $updateId, 'inbound_message_id' => $messageId], JSON_THROW_ON_ERROR);
        exit(7);
    }
    $existingJob = \App\Models\ScheduledTelegramJob::where('telegram_update_id', $updateId)->first();
    if ($existingJob) {
        echo json_encode(['status' => 'EXISTS', 'job_id' => $existingJob->id, 'telegram_update_id' => $updateId], JSON_THROW_ON_ERROR);
        exit(0);
    }
    $job = app(\App\Services\TelegramMarketAssistantService::class)->scheduleJob([
        'raw_text' => $conversation->inbound_text,
        'ticker' => 'BBCA',
        'tolerance_minutes' => 15,
    ], [
        'chat_id' => $conversation->chat_id,
        'user_id' => $conversation->user_id,
        'update_id' => $updateId,
        'inbound_message_id' => $messageId,
        'request_type' => 'ASYNC_CONSULTATION',
        'tolerance_minutes' => 15,
    ]);
    echo json_encode(['status' => 'QUEUED', 'job_id' => $job->id, 'telegram_update_id' => $updateId, 'inbound_message_id' => $messageId, 'request_type' => $job->request_type, 'payload_preserved' => true], JSON_THROW_ON_ERROR);
    exit(0);
} elseif ($mode === '--recovery-consultation') {
    $updateId = (string) $options['update-id'];
    $messageId = (string) $options['message-id'];
    $supersededJobId = (int) $options['supersedes-job-id'];
    $conversation = \App\Models\TelegramConversation::where('telegram_update_id', $updateId)
        ->where('provenance->inbound_message_id', $messageId)
        ->first();
    $supersededJob = \App\Models\ScheduledTelegramJob::find($supersededJobId);
    if (! $conversation || ! $supersededJob || (string) $supersededJob->telegram_update_id !== $updateId || (string) $supersededJob->inbound_message_id !== $messageId) {
        echo json_encode(['status' => 'NOT_VERIFIED', 'reason' => 'recovery_source_pair_or_job_not_found', 'telegram_update_id' => $updateId, 'inbound_message_id' => $messageId, 'supersedes_job_id' => $supersededJobId], JSON_THROW_ON_ERROR);
        exit(7);
    }
    $existingRecovery = \App\Models\ScheduledTelegramJob::where('telegram_update_id', $updateId)
        ->where('parameters->recovery_attempt', true)
        ->first();
    if ($existingRecovery) {
        echo json_encode(['status' => 'EXISTS', 'job_id' => $existingRecovery->id, 'telegram_update_id' => $updateId, 'recovery_attempt' => true], JSON_THROW_ON_ERROR);
        exit(0);
    }
    $job = app(\App\Services\TelegramMarketAssistantService::class)->scheduleJob([
        'raw_text' => $conversation->inbound_text,
        'ticker' => 'BBCA',
        'tolerance_minutes' => 15,
        'recovery_attempt' => true,
        'supersedes_job_id' => $supersededJobId,
        'recovery_source_conversation_id' => $conversation->id,
    ], [
        'chat_id' => $conversation->chat_id,
        'user_id' => $conversation->user_id,
        'update_id' => $updateId,
        'inbound_message_id' => $messageId,
        'request_type' => 'ASYNC_CONSULTATION',
        'tolerance_minutes' => 15,
    ]);
    echo json_encode(['status' => 'QUEUED', 'job_id' => $job->id, 'telegram_update_id' => $updateId, 'inbound_message_id' => $messageId, 'request_type' => $job->request_type, 'recovery_attempt' => true, 'supersedes_job_id' => $supersededJobId, 'payload_preserved' => true], JSON_THROW_ON_ERROR);
    exit(0);
} elseif ($mode === '--reconcile-outbox') {
    $outboxId = (int) $options['outbox-id'];
    $outbox = \App\Models\TelegramOutbox::find($outboxId);
    if (! $outbox) {
        echo json_encode(['status' => 'NOT_VERIFIED', 'reason' => 'outbox_not_found', 'outbox_id' => $outboxId], JSON_THROW_ON_ERROR);
        exit(7);
    }
    if ($outbox->telegram_message_id || $outbox->status === 'SENT') {
        echo json_encode(['status' => 'SENT_ALREADY', 'outbox_id' => $outbox->id, 'telegram_message_id' => $outbox->telegram_message_id], JSON_THROW_ON_ERROR);
        exit(0);
    }
    if (! in_array($outbox->status, ['QUEUED', 'PROCESSING'], true)) {
        echo json_encode(['status' => 'UNCHANGED', 'outbox_id' => $outbox->id, 'outbox_status' => $outbox->status], JSON_THROW_ON_ERROR);
        exit(0);
    }
    $outbox->update([
        'status' => 'DELIVERY_UNKNOWN',
        'retry_after' => null,
        'error_message' => 'Delivery outcome unresolved after transport exception; manual reconciliation required before any retry.',
        'metadata' => array_merge((array) $outbox->metadata, ['delivery_reconciliation' => 'UNKNOWN', 'reconciled_at' => now()->toISOString(), 'retry_blocked' => true]),
    ]);
    $jobId = $outbox->metadata['scheduled_job_id'] ?? null;
    if ($jobId) {
        $job = \App\Models\ScheduledTelegramJob::find($jobId);
        if ($job && ! $job->outbound_message_id) {
            $job->update(['status' => 'DELIVERY_UNKNOWN', 'delivery_status' => 'DELIVERY_UNKNOWN', 'error_message' => $outbox->error_message]);
        }
    }
    echo json_encode(['status' => 'DELIVERY_UNKNOWN', 'outbox_id' => $outbox->id, 'telegram_message_id' => null, 'retry_blocked' => true], JSON_THROW_ON_ERROR);
    exit(0);
} elseif ($mode === '--grounding-correction') {
    $jobId = (int) $options['job-id'];
    $job = \App\Models\ScheduledTelegramJob::find($jobId);
    if (! $job || $job->status !== 'COMPLETED' || $job->delivery_status !== 'SENT' || ! $job->outbound_message_id) {
        echo json_encode(['status' => 'NOT_VERIFIED', 'reason' => 'source_job_not_immutable_sent', 'job_id' => $jobId], JSON_THROW_ON_ERROR);
        exit(7);
    }
    $outbox = $job->outbox_id ? \App\Models\TelegramOutbox::find($job->outbox_id) : null;
    if (! $outbox || $outbox->status !== 'SENT' || ! $outbox->telegram_message_id) {
        echo json_encode(['status' => 'NOT_VERIFIED', 'reason' => 'source_outbox_not_immutable_sent', 'job_id' => $jobId], JSON_THROW_ON_ERROR);
        exit(7);
    }
    $parameters = is_array($job->parameters) ? $job->parameters : [];
    $ticker = strtoupper((string) ($parameters['ticker'] ?? 'BBCA'));
    $observation = \App\Models\MarketObservation::where('ticker', $ticker)
        ->where('source', 'IDX Edge PRO')
        ->where('board_scope', 'RG')
        ->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')
        ->where('validation_status', 'VALID')
        ->latest('bar_date')
        ->first();
    if (! $observation) {
        echo json_encode(['status' => 'NOT_VERIFIED', 'reason' => 'canonical_bar_missing', 'job_id' => $jobId, 'ticker' => $ticker], JSON_THROW_ON_ERROR);
        exit(7);
    }
    $brokerDate = \App\Models\BrokerSummary::where('ticker', $ticker)
        ->where('board', 'RG')->where('source', 'IDX Edge PRO')->where('unit', 'SHARES')->where('flow_type', 'all')
        ->whereDate('summary_date', '<=', $observation->bar_date->toDateString())->max('summary_date');
    $brokers = $brokerDate ? \App\Models\BrokerSummary::where('ticker', $ticker)
        ->where('board', 'RG')->where('source', 'IDX Edge PRO')->where('unit', 'SHARES')->where('flow_type', 'all')
        ->whereDate('summary_date', $brokerDate)->orderByDesc('net_value')->get() : collect();
    $dedupKey = 'GROUNDING_CORRECTION_JOB_'.$jobId;
    $existing = \App\Models\TelegramOutbox::where('dedup_key', $dedupKey)->first();
    if ($existing) {
        echo json_encode(['status' => 'EXISTS', 'job_id' => $jobId, 'outbox_id' => $existing->id, 'evidence_id' => $existing->metadata['evidence_id'] ?? null], JSON_THROW_ON_ERROR);
        exit(0);
    }
    $metric = \App\Models\CompanyAnalysisMetric::where('ticker', $ticker)->latest('as_of_date')->first();
    $metricUsable = $metric && $metric->as_of_date && $metric->as_of_date->toDateString() <= $observation->bar_date->toDateString();
    $buyers = $brokers->where('net_value', '>', 0)->sortByDesc('net_value')->take(3);
    $sellers = $brokers->where('net_value', '<', 0)->sortBy('net_value')->take(3);
    $brokerLine = static fn ($row, string $sign): string => $row->broker_code.' '.$sign.'Rp '.number_format(abs((float) $row->net_value) / 1e9, 2, ',', '.').' M';
    $correctionText = implode("\n", [
        "KOREKSI GROUNDING — {$ticker} — merujuk jawaban Job #{$jobId}",
        'Jawaban sebelumnya keliru menyatakan data broker tidak tersedia.',
        "Evidence kanonik: bar {$observation->bar_date->toDateString()}, board RG, source IDX Edge PRO, adjustment PROVIDER_BACK_ADJUSTED, fetched_at ".($observation->fetched_at?->toIso8601String() ?: 'tidak tercakup').'.',
        "Broker tersedia: {$brokers->count()} row; periode sesi {$brokerDate}; board RG; flow all; unit SHARES; source IDX Edge PRO; fetched_at ".($brokers->max('fetched_at')?->toIso8601String() ?: 'tidak tercakup').'.',
        'Top net buyers: '.($buyers->isEmpty() ? 'tidak tercakup' : $buyers->map(fn ($row) => $brokerLine($row, '+'))->implode('; ')),
        'Top net sellers: '.($sellers->isEmpty() ? 'tidak tercakup' : $sellers->map(fn ($row) => $brokerLine($row, '-'))->implode('; ')),
        'Top-N adalah sampel perantara pada snapshot tersebut, bukan net seluruh pasar atau inventori investor.',
        'Teknikal: metrik terakhir '.($metric?->as_of_date?->toDateString() ?: 'tidak tercakup').($metricUsable ? ' dapat dipakai.' : ' tertinggal dari bar; RSI/SMA/support/resistance tidak diklaim.'),
        'Model ML produksi: TIDAK AKTIF; artefak produksi belum tersedia. NO_ACTIVE_INFERENCE_FOR_BAR bukan NO_SIGNAL_ABSTAIN.',
        'Status finality: PROVISIONAL. Koreksi ini memakai evidence deterministik; tidak mengulang inferensi berbayar.',
    ]);
    $evidence = \App\Models\EvidenceSnapshot::create([
        'snapshot_id' => 'EV_CORRECTION_JOB_'.$jobId.'_'.now()->format('Ymd_His'),
        'ticker' => $ticker,
        'decision_date' => $observation->bar_date->toDateString(),
        'market_regime' => 'SIDEWAYS',
        'features_snapshot' => ['type' => 'GROUNDING_CORRECTION', 'supersedes_job_id' => $jobId, 'bar' => $observation->only(['id', 'bar_date', 'close_price', 'board_scope', 'adjustment_mode', 'fetched_at']), 'broker_date' => $brokerDate, 'broker_row_count' => $brokers->count(), 'metric_as_of_date' => $metric?->as_of_date?->toDateString(), 'ml_status' => 'NO_ACTIVE_INFERENCE_FOR_BAR'],
        'data_hash' => hash('sha256', $correctionText),
    ]);
    $dedupKey = 'GROUNDING_CORRECTION_JOB_'.$jobId;
    $existing = \App\Models\TelegramOutbox::where('dedup_key', $dedupKey)->first();
    if ($existing) {
        echo json_encode(['status' => 'EXISTS', 'job_id' => $jobId, 'outbox_id' => $existing->id, 'evidence_id' => $evidence->id], JSON_THROW_ON_ERROR);
        exit(0);
    }
    $correctionOutbox = \App\Models\TelegramOutbox::create([
        'dedup_key' => $dedupKey,
        'chat_id' => $job->chat_id,
        'reply_to_message_id' => $job->inbound_message_id,
        'message_type' => 'SYSTEM_CORRECTION',
        'payload_text' => $correctionText,
        'status' => 'QUEUED',
        'retry_count' => 0,
        'trigger_source' => 'GROUNDING_AUDIT',
        'metadata' => ['correction' => true, 'supersedes_job_id' => $jobId, 'source_outbox_id' => $outbox->id, 'evidence_id' => $evidence->id, 'paid_inference_repeated' => false],
    ]);
    echo json_encode(['status' => 'QUEUED', 'job_id' => $jobId, 'source_outbox_id' => $outbox->id, 'correction_outbox_id' => $correctionOutbox->id, 'evidence_id' => $evidence->id, 'old_job_unchanged' => true, 'old_outbox_unchanged' => true, 'payload' => $correctionText], JSON_THROW_ON_ERROR);
    exit(0);
} elseif ($mode === '--consultation-queue') {
    $arguments = ['--limit' => 5, '--live' => true];
    if (($options['job-id'] ?? '') !== '') {
        $arguments['--job-id'] = (int) $options['job-id'];
    }
    $status = \Illuminate\Support\Facades\Artisan::call('hermes:process-consultation-queue', $arguments);
} elseif ($mode === '--process-outbox') {
    $arguments = ['--live' => true];
    if (($options['job-id'] ?? '') !== '') {
        $arguments['--job-id'] = (int) $options['job-id'];
    }
    $status = \Illuminate\Support\Facades\Artisan::call('hermes:process-outbox', $arguments);
} elseif ($mode === '--shadow-canary') {
    $arguments = [
        '--run-id' => $options['run-id'],
        '--live' => $liveShadowCanary,
        '--dry-run' => $dryShadowCanary,
    ];
    $status = \Illuminate\Support\Facades\Artisan::call('hermes:shadow-canary', $arguments);
} elseif ($mode === '--news') {
    $status = \Illuminate\Support\Facades\Artisan::call('hermes:ingest-news', ['--limit' => $limit]);
} elseif ($mode === '--broker') {
    try {
        $status = \Illuminate\Support\Facades\Artisan::call('hermes:sync-idx-edge-broker', ['ticker' => $ticker, '--date' => $session]);
    } catch (Throwable $e) {
        $diagnostic = preg_replace('/(?:password|token|secret|api[_ -]?key|authorization)\s*[=:]\s*[^\s,;}]+/i', '[REDACTED]', $e->getMessage()) ?: 'Broker writer failed';
        $diagnostic = preg_replace('/[\r\n\t]+/', ' ', $diagnostic) ?: 'Broker writer failed';
        $diagnostic = substr($diagnostic, 0, 1000);
        fwrite(STDERR, "APP_WRITER_ERROR {$diagnostic}\n");
        exit(6);
    }
} elseif ($mode === '--narrative') {
    $status = \Illuminate\Support\Facades\Artisan::call('idx:process-narratives', ['--ticker' => $ticker, '--limit' => 1]);
} elseif ($mode === '--telegram-chat') {
    $arguments = [
        '--text' => $options['text'],
        '--user' => $options['user'],
        '--update-id' => $options['update-id'],
        '--message-id' => $options['message-id'],
        '--json' => true,
    ];
    if (($options['reply-to'] ?? '') !== '') {
        $arguments['--reply-to'] = $options['reply-to'];
    }
    try {
        $status = \Illuminate\Support\Facades\Artisan::call('hermes:telegram-chat', $arguments);
    } catch (Throwable $e) {
        $diagnostic = preg_replace('/(?:password|token|secret|api[_ -]?key|authorization)\\s*[=:]\\s*[^\\s,;}]+/i', '[REDACTED]', $e->getMessage()) ?: 'Telegram chat command failed';
        $diagnostic = preg_replace('/[\\r\\n\\t]+/', ' ', $diagnostic) ?: 'Telegram chat command failed';
        $diagnostic = substr($diagnostic, 0, 1000);
        fwrite(STDERR, "APP_WRITER_ERROR {$diagnostic}\\n");
        echo json_encode([
            'status' => 'error',
            'error_type' => 'WRITER_EXCEPTION',
            'error' => $diagnostic,
            'telegram_update_id' => (string) $options['update-id'],
            'inbound_message_id' => (string) $options['message-id'],
        ], JSON_THROW_ON_ERROR);
        exit(6);
    }
} else {
    $status = \Illuminate\Support\Facades\Artisan::call('hermes:record-telegram-receipt', [
        '--update-id' => $options['update-id'],
        '--inbound-message-id' => $options['message-id'],
        '--outbound-message-id' => $options['outbound-message-id'],
        '--json' => true,
    ]);
}

echo \Illuminate\Support\Facades\Artisan::output();
exit($status === 0 ? 0 : 6);
