<?php
require_once __DIR__.'/eod_result_classifier.php';
$root = 'E:/Aether-Market-Intelligence/dashboard';
$role = 'aether_ingestion';
$db = getenv('AETHER_ACTIVE_DATABASE') ?: 'project_bot_recovered_20261115_022935';
$host = '127.0.0.1';
$port = '5432';
$credentialFile = 'E:/Aether-Market-Intelligence/storage/incidents/20261110_operational_data_loss/ingestion-credentials/aether_ingestion.dpapi.txt';
$decryptor = 'E:/Aether-Market-Intelligence/scripts/decrypt_ingestion_secret.ps1';
$runtime = 'E:/Aether-Market-Intelligence/storage/incidents/20261110_operational_data_loss/ingestion-writer-runtime';
$mode = $argv[1] ?? '--verify-only';
$session = null;
$tickers = null;
$limit = null;
$explicitTickers = null;
$rpm = null;
$retryOnly = false;
$canonicalGapOnly = false;
$triggerSource = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--session=')) $session = substr($arg, 10);
    if (str_starts_with($arg, '--tickers=')) {
        $tickers = strtoupper(substr($arg, 10));
        $explicitTickers = array_values(array_filter(array_map('trim', explode(',', $tickers))));
    }
    if (str_starts_with($arg, '--limit=')) $limit = (int) substr($arg, 8);
    if (str_starts_with($arg, '--rpm=')) $rpm = (int) substr($arg, 6);
    if ($arg === '--retry-only') $retryOnly = true;
    if ($arg === '--canonical-gap') $canonicalGapOnly = true;
    if (str_starts_with($arg, '--trigger-source=')) $triggerSource = substr($arg, 17);
}
if (!in_array($mode, ['--verify-only', '--scope-audit', '--backlog-audit', '--metrics-audit', '--metrics-scope-audit', '--metrics-batch-audit', '--metrics-batch', '--list-target-sessions', '--dry-run', '--canary', '--batch', '--eod-daily', '--reconcile-interrupted'], true)) { fwrite(STDERR, "writer activation disabled; unsupported mode\n"); exit(2); }
if (in_array($mode, ['--scope-audit', '--backlog-audit', '--metrics-audit', '--metrics-scope-audit', '--metrics-batch-audit', '--metrics-batch', '--reconcile-interrupted'], true) && ($session === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $session))) { fwrite(STDERR, "{$mode} requires --session=YYYY-MM-DD\n"); exit(2); }
if (in_array($mode, ['--metrics-audit', '--metrics-scope-audit'], true) && $mode === '--metrics-audit' && ($tickers === null || !preg_match('/^[A-Z0-9^.-]{1,10}$/', $tickers))) { fwrite(STDERR, "--metrics-audit requires exactly one --tickers=CODE\n"); exit(2); }
if (in_array($mode, ['--metrics-batch-audit', '--metrics-batch'], true) && ($limit === null || $limit < 1 || $limit > 20)) { fwrite(STDERR, "{$mode} requires --limit=1..20\n"); exit(2); }
if ($mode === '--metrics-batch' && ($explicitTickers === null || count($explicitTickers) < 1 || count($explicitTickers) > 20 || count($explicitTickers) !== count(array_unique($explicitTickers)))) { fwrite(STDERR, "--metrics-batch requires 1..20 unique --tickers=CODE values\n"); exit(2); }
if (($mode === '--dry-run' || $mode === '--canary' || $mode === '--batch') && $session !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $session)) { fwrite(STDERR, "invalid session format\n"); exit(2); }
if ($mode === '--canary') {
    if ($session === null || $tickers === null || !preg_match('/^[A-Z]{4}$/', $tickers)) {
        fwrite(STDERR, "canary requires --session=YYYY-MM-DD and exactly one --tickers=CODE\n");
        exit(2);
    }
}
if ($mode === '--batch') {
    if ($session === null || $limit === null || $limit < 1 || $limit > 20) {
        fwrite(STDERR, "batch requires --session=YYYY-MM-DD and --limit=1..20\n");
        exit(2);
    }
    if ($explicitTickers !== null && (count($explicitTickers) < 1 || count($explicitTickers) > 20 || count($explicitTickers) !== count(array_unique($explicitTickers)) || array_filter($explicitTickers, fn ($ticker) => !preg_match('/^[A-Z0-9^.-]{1,10}$/', $ticker)))) {
        fwrite(STDERR, "batch --tickers requires 1..20 unique valid ticker codes\n");
        exit(2);
    }
    if ($rpm !== null && ($rpm < 1 || $rpm > 30)) {
        fwrite(STDERR, "batch --rpm must be 1..30\n");
        exit(2);
    }
}
if (!is_file($credentialFile) || filesize($credentialFile) <= 0 || !is_file($decryptor)) { fwrite(STDERR, "credential store or decryptor unavailable\n"); exit(3); }
foreach ([$runtime.'/framework/cache/data', $runtime.'/framework/cache/locks', $runtime.'/framework/sessions', $runtime.'/framework/views', $runtime.'/logs'] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0700, true);
}
$out = []; $exit = 0;
$cmd = ['C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe','-NoProfile','-NonInteractive','-File',$decryptor,'-Path',$credentialFile];
$proc = proc_open($cmd, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $root);
if (is_resource($proc)) {
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc);
}
$password = trim($out ?? '');
unset($err);
if ($exit !== 0 || $password === '') { fwrite(STDERR, "credential decrypt failed\n"); exit(4); }
$pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db", $role, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$identity = $pdo->query("SELECT current_database() AS database, current_user AS user_name, current_schema() AS schema_name, current_setting('search_path') AS search_path")->fetch(PDO::FETCH_ASSOC);
$observations = (int) $pdo->query('SELECT count(*) FROM public.market_observations')->fetchColumn();
if ($identity['database'] !== $db || $identity['user_name'] !== $role || $identity['schema_name'] !== 'public') { fwrite(STDERR, "writer SQL identity mismatch\n"); exit(5); }
$proof = ['status'=>'VERIFIED','database'=>$identity['database'],'user'=>$identity['user_name'],'schema'=>$identity['schema_name'],'search_path'=>$identity['search_path'],'observations'=>$observations,'credential_store'=>'Windows DPAPI protected file','secret_printed'=>false,'writer_started'=>false,'mutation_executed'=>false];
file_put_contents('E:/Aether-Market-Intelligence/storage/incidents/20261110_operational_data_loss/ingestion-writer-identity-20261110.json', json_encode($proof, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
if ($mode === '--metrics-scope-audit') {
    $stmt = $pdo->prepare("WITH scope AS (
        SELECT r.ticker,
            count(DISTINCT o.id) FILTER (WHERE o.bar_date = :session AND o.adjustment_mode = 'PROVIDER_BACK_ADJUSTED') AS adjusted_target_rows,
            count(DISTINCT o.id) FILTER (WHERE o.bar_date = :session AND o.adjustment_mode = 'UNADJUSTED') AS raw_target_rows,
            count(DISTINCT o.id) FILTER (WHERE o.adjustment_mode = 'PROVIDER_BACK_ADJUSTED' AND o.bar_date <= :session) AS adjusted_history_rows,
            max(o.bar_date) FILTER (WHERE o.adjustment_mode = 'PROVIDER_BACK_ADJUSTED' AND o.bar_date <= :session) AS adjusted_last_date,
            max(o.bar_date) FILTER (WHERE o.adjustment_mode = 'UNADJUSTED' AND o.bar_date <= :session) AS raw_last_date,
            count(DISTINCT m.id) FILTER (WHERE m.as_of_date = :session) AS metric_target_rows,
            count(DISTINCT m.id) FILTER (WHERE m.as_of_date = :session
                AND m.data_quality_flags->>'last_date' = m.as_of_date::text
                AND m.data_quality_flags->>'as_of_date' = m.as_of_date::text
                AND m.data_quality_flags->>'source' = 'IDX Edge PRO'
                AND m.data_quality_flags->>'board_scope' = 'RG'
                AND m.data_quality_flags->>'adjustment_mode' = 'PROVIDER_BACK_ADJUSTED'
                AND jsonb_exists_all(m.data_quality_flags->'formulas', ARRAY['sma','rsi','atr','macd'])) AS metric_matching_rows,
            max((m.data_quality_flags->>'input_bars')::int) FILTER (WHERE m.as_of_date = :session) AS metric_input_bars,
            max(m.as_of_date) AS latest_metric_date
        FROM public.idx_company_registries r
        LEFT JOIN public.market_observations o ON o.ticker = r.ticker
            AND o.interval = '1d'
            AND o.data_classification = 'PUBLIC_MARKET_EOD'
            AND o.source = 'IDX Edge PRO'
            AND o.board_scope = 'RG'
            AND o.validation_status = 'VALID'
            AND o.bar_date <= :session
        LEFT JOIN public.company_analysis_metrics m ON m.ticker = r.ticker
            AND m.as_of_date <= :session
        WHERE r.instrument_type = 'EQUITY'
            AND (r.backfill_status IS NULL OR r.backfill_status <> 'UNSUPPORTED_SHARE_CLASS')
        GROUP BY r.ticker
    )
    SELECT ticker, adjusted_target_rows, raw_target_rows, adjusted_history_rows,
        adjusted_last_date::text, raw_last_date::text, metric_target_rows, metric_matching_rows, metric_input_bars,
        latest_metric_date::text,
        CASE
            WHEN adjusted_target_rows = 1 AND raw_target_rows = 1 AND metric_matching_rows = 1 THEN 'METRIC_MATCH'
            WHEN adjusted_target_rows = 1 AND raw_target_rows = 1 AND metric_target_rows = 1 THEN 'METRIC_STALE_INPUT'
            WHEN adjusted_target_rows = 1 AND raw_target_rows = 1 AND adjusted_history_rows < 15 THEN 'INSUFFICIENT_INPUT'
            WHEN adjusted_target_rows = 1 AND raw_target_rows = 1 THEN 'METRIC_NEEDS_CALC'
            WHEN adjusted_target_rows = 0 AND raw_target_rows = 0 THEN 'BAR_SESSION_MISSING'
            ELSE 'BAR_SESSION_MISSING'
        END AS status,
        CASE
            WHEN adjusted_target_rows = 1 AND raw_target_rows = 1 AND metric_matching_rows = 1 THEN 'Bar raw+adjusted lengkap; metrik sesuai sesi dan input adjusted aktual.'
            WHEN adjusted_target_rows = 1 AND raw_target_rows = 1 AND metric_target_rows = 1 THEN 'Bar raw+adjusted lengkap; row metric target stale terhadap input adjusted aktual.'
            WHEN adjusted_target_rows = 1 AND raw_target_rows = 1 AND adjusted_history_rows < 15 THEN 'Bar target lengkap; histori adjusted kurang dari warmup RSI/ATR.'
            WHEN adjusted_target_rows = 1 AND raw_target_rows = 1 THEN 'Bar raw+adjusted lengkap; metrik sesi belum ada.'
            WHEN adjusted_target_rows = 0 AND raw_target_rows = 0 THEN 'Bar target raw dan adjusted tidak tersedia.'
            ELSE 'Salah satu mode bar target tidak tersedia.'
        END AS reason
    FROM scope ORDER BY ticker");
    $stmt->bindValue(':session', $session);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $counts = [];
    foreach ($rows as $row) {
        $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
    }
    $summary = isset($argv) && in_array('--summary', $argv, true);
    if ($summary) {
        $examples = [];
        foreach ($rows as $row) {
            $examples[$row['status']][] = $row['ticker'];
        }
        foreach ($examples as $status => $tickersForStatus) {
            $examples[$status] = array_values($tickersForStatus);
        }
        echo json_encode(['status'=>'READ_ONLY','identity'=>$identity,'target_session'=>$session,'counts'=>$counts,'failed_count'=>0,'examples'=>$examples,'writes_executed'=>false,'secret_printed'=>false], JSON_UNESCAPED_SLASHES).PHP_EOL;
    } else {
        echo json_encode(['status'=>'READ_ONLY','identity'=>$identity,'target_session'=>$session,'counts'=>$counts,'failed_count'=>0,'scope'=>$rows,'writes_executed'=>false,'secret_printed'=>false], JSON_UNESCAPED_SLASHES).PHP_EOL;
    }
    exit(0);
}
if ($mode === '--metrics-batch-audit') {
    $stmt = $pdo->prepare("WITH eligible AS (
        SELECT r.ticker,
            max(o.bar_date) FILTER (WHERE o.adjustment_mode = 'PROVIDER_BACK_ADJUSTED') AS adjusted_date,
            max(o.bar_date) FILTER (WHERE o.adjustment_mode = 'UNADJUSTED') AS raw_date,
            max(m.as_of_date) AS metric_date,
            count(*) FILTER (WHERE o.adjustment_mode = 'PROVIDER_BACK_ADJUSTED') AS adjusted_rows,
            count(*) FILTER (WHERE o.adjustment_mode = 'UNADJUSTED') AS raw_rows
        FROM public.idx_company_registries r
        LEFT JOIN public.market_observations o ON o.ticker = r.ticker
            AND o.bar_date = :session
            AND o.interval = '1d'
            AND o.data_classification = 'PUBLIC_MARKET_EOD'
            AND o.source = 'IDX Edge PRO'
            AND o.board_scope = 'RG'
            AND o.validation_status = 'VALID'
        LEFT JOIN public.company_analysis_metrics m ON m.ticker = r.ticker
            AND m.as_of_date = :session
        WHERE r.instrument_type = 'EQUITY'
            AND (r.backfill_status IS NULL OR r.backfill_status <> 'UNSUPPORTED_SHARE_CLASS')
        GROUP BY r.ticker
    )
    SELECT ticker, adjusted_date::text, raw_date::text, metric_date::text, adjusted_rows, raw_rows,
        CASE WHEN adjusted_date = :session AND raw_date = :session THEN 'READY'
             ELSE 'INSUFFICIENT_INPUT' END AS input_status
    FROM eligible
    WHERE adjusted_date = :session AND raw_date = :session AND metric_date IS NULL
    ORDER BY ticker LIMIT :limit");
    $stmt->bindValue(':session', $session);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'READ_ONLY','identity'=>$identity,'target_session'=>$session,'selected_count'=>count($rows),'scope'=>$rows,'writes_executed'=>false,'secret_printed'=>false], JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}
if ($mode === '--metrics-audit') {
    $stmt = $pdo->prepare("SELECT ticker, as_of_date, technical_status, close_price, rsi_14, sma_20, sma_50, sma_200, macd, atr_14, data_quality_flags, created_at, updated_at
        FROM public.company_analysis_metrics WHERE ticker = :ticker AND as_of_date <= :session ORDER BY as_of_date DESC LIMIT 1");
    $stmt->execute(['ticker' => $tickers, 'session' => $session]);
    $metric = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $stmt = $pdo->prepare("SELECT count(*) FROM public.company_analysis_metrics WHERE ticker = :ticker AND as_of_date = :session");
    $stmt->execute(['ticker' => $tickers, 'session' => $session]);
    $metricRowCount = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT
        has_table_privilege(current_user, 'public.company_analysis_metrics', 'SELECT') AS metric_select,
        has_table_privilege(current_user, 'public.company_analysis_metrics', 'INSERT') AS metric_insert,
        has_table_privilege(current_user, 'public.company_analysis_metrics', 'UPDATE') AS metric_update,
        has_table_privilege(current_user, 'public.company_analysis_metrics', 'DELETE') AS metric_delete,
        has_table_privilege(current_user, 'public.company_analysis_metrics', 'TRUNCATE') AS metric_truncate,
        has_sequence_privilege(current_user, 'public.company_analysis_metrics_id_seq', 'USAGE') AS sequence_usage,
        has_sequence_privilege(current_user, 'public.company_analysis_metrics_id_seq', 'SELECT') AS sequence_select");
    $stmt->execute();
    $privileges = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare("SELECT
        count(*) FILTER (WHERE validation_status = 'VALID') AS valid_bars,
        count(*) FILTER (WHERE validation_status <> 'VALID') AS invalid_bars,
        min(bar_date) FILTER (WHERE validation_status = 'VALID') AS first_valid_date,
        max(bar_date) FILTER (WHERE validation_status = 'VALID') AS last_valid_date,
        count(*) FILTER (WHERE adjustment_mode = 'PROVIDER_BACK_ADJUSTED' AND source = 'IDX Edge PRO' AND board_scope = 'RG' AND interval = '1d' AND validation_status = 'VALID') AS adjusted_valid_bars,
        count(*) FILTER (WHERE adjustment_mode = 'UNADJUSTED' AND source = 'IDX Edge PRO' AND board_scope = 'RG' AND interval = '1d' AND validation_status = 'VALID') AS raw_valid_bars
        FROM public.market_observations WHERE ticker = :ticker AND bar_date <= :session");
    $stmt->execute(['ticker' => $tickers, 'session' => $session]);
    $input = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare("SELECT adjustment_mode, count(*)::int AS rows, min(bar_date)::text AS first_date, max(bar_date)::text AS last_date
        FROM public.market_observations
        WHERE ticker = :ticker AND bar_date <= :session AND interval = '1d' AND data_classification = 'PUBLIC_MARKET_EOD'
            AND source = 'IDX Edge PRO' AND board_scope = 'RG' AND validation_status = 'VALID'
        GROUP BY adjustment_mode ORDER BY adjustment_mode");
    $stmt->execute(['ticker' => $tickers, 'session' => $session]);
    $canonicalInputs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'READ_ONLY','identity'=>$identity,'ticker'=>$tickers,'target_session'=>$session,'metric'=>$metric,'metric_row_count'=>$metricRowCount,'metric_privileges'=>$privileges,'input'=>$input,'canonical_inputs'=>$canonicalInputs,'writes_executed'=>false,'secret_printed'=>false], JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}
if (in_array($mode, ['--scope-audit', '--backlog-audit'], true)) {
    $where = $mode === '--scope-audit' ? "r.ticker IN ('BBCA','GOTO')" : "r.instrument_type = 'EQUITY' AND (r.backfill_status IS NULL OR r.backfill_status <> 'UNSUPPORTED_SHARE_CLASS')";
    $stmt = $pdo->prepare("SELECT r.ticker, r.instrument_type, r.provider_availability, r.backfill_status, r.last_bar_date,
        r.eod_refresh_status, r.eod_retry_attempts,
        (SELECT max(o.bar_date) FROM market_observations o WHERE o.ticker = r.ticker AND o.bar_date = :session AND o.source = 'IDX Edge PRO' AND o.adjustment_mode = 'UNADJUSTED' AND o.board_scope = 'RG' AND o.validation_status = 'VALID' AND o.interval = '1d') AS raw_last_bar_date,
        (SELECT max(o.bar_date) FROM market_observations o WHERE o.ticker = r.ticker AND o.bar_date = :session AND o.source = 'IDX Edge PRO' AND o.adjustment_mode = 'PROVIDER_BACK_ADJUSTED' AND o.board_scope = 'RG' AND o.validation_status = 'VALID' AND o.interval = '1d') AS adjusted_last_bar_date
        FROM idx_company_registries r WHERE {$where} ORDER BY r.ticker");
    $stmt->bindValue(':session', $session);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['eligibility'] = $row['instrument_type'] === 'EQUITY' && $row['backfill_status'] !== 'UNSUPPORTED_SHARE_CLASS' ? 'ACCEPTED' : 'REJECTED';
        $row['eligibility_reason'] = $row['eligibility'] === 'ACCEPTED' ? 'EQUITY and supported share class' : 'not EQUITY or UNSUPPORTED_SHARE_CLASS';
    }
    unset($row);
    echo json_encode(['status'=>'READ_ONLY','identity'=>$identity,'target_session'=>$session,'scope'=>$rows,'writes_executed'=>false,'secret_printed'=>false], JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}
if ($mode === '--verify-only') {
    echo json_encode(['status'=>'VERIFIED','mode'=>$mode,'database'=>$db,'role'=>$role,'observations'=>$observations,'writer_started'=>false,'mutation_executed'=>false,'secret_printed'=>false], JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}
$env = getenv();
foreach ([
    'APP_ENV'=>'recovery-writer','APP_DEBUG'=>'false','APP_CONFIG_CACHE'=>(getenv('LOCALAPPDATA') ?: 'E:/Aether-Market-Intelligence').'/Aether-Market-Intelligence/recovery/bootstrap-cache/ingestion-config.php','LARAVEL_STORAGE_PATH'=>$runtime,
    'DB_CONNECTION'=>'pgsql','DB_HOST'=>$host,'DB_PORT'=>$port,'DB_DATABASE'=>$db,'DB_USERNAME'=>$role,'DB_PASSWORD'=>$password,
    'AETHER_ACTIVE_DATABASE'=>$db,'AETHER_WRITER_ROLE'=>$role,
    'CACHE_STORE'=>'file','DB_CACHE_CONNECTION'=>'','DB_CACHE_TABLE'=>'','DB_CACHE_LOCK_CONNECTION'=>'','DB_CACHE_LOCK_TABLE'=>'',
    'SESSION_DRIVER'=>'file','QUEUE_CONNECTION'=>'sync','AI_LIVE_INFERENCE_ENABLED'=>'false','AI_LIVE_INFERENCE_ON_DEMAND_ENABLED'=>'false','AI_LIVE_INFERENCE_EOD_ENABLED'=>'false','HERMES_ORCHESTRATOR_ENABLED'=>'false','TELEGRAM_ENABLED'=>'false'
] as $key=>$value) { $env[$key]=$value; putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value; }
if ($mode === '--reconcile-interrupted') {
    putenv('IDX_EDGE_ENABLED=false'); $_ENV['IDX_EDGE_ENABLED'] = 'false'; $_SERVER['IDX_EDGE_ENABLED'] = 'false'; $env['IDX_EDGE_ENABLED'] = 'false';
    $artisanArgs = [PHP_BINARY, 'artisan', 'hermes:refresh-eod', '--reconcile-interrupted', '--session='.$session, '--trigger-source=RECOVERY_INTERRUPTED_RUN'];
    $process = proc_open($artisanArgs, [0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]], $pipes, $root, $env);
    if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
    $childStdout = is_resource($process) && isset($pipes[1]) ? stream_get_contents($pipes[1]) : '';
    $childStderr = is_resource($process) && isset($pipes[2]) ? stream_get_contents($pipes[2]) : '';
    if (isset($pipes[1]) && is_resource($pipes[1])) fclose($pipes[1]);
    if (isset($pipes[2]) && is_resource($pipes[2])) fclose($pipes[2]);
    $status = is_resource($process) ? proc_close($process) : 7;
    echo $childStdout;
    fwrite(STDERR, $childStderr);
    $password = '';
    exit($status);
}
if ($mode === '--list-target-sessions') {
    $process = proc_open([PHP_BINARY, 'E:/Aether-Market-Intelligence/scripts/preview_recovery_sessions.php'], [0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]], $pipes, $root, $env);
} elseif ($mode === '--metrics-batch') {
    putenv('IDX_EDGE_ENABLED=false'); $_ENV['IDX_EDGE_ENABLED'] = 'false'; $_SERVER['IDX_EDGE_ENABLED'] = 'false'; $env['IDX_EDGE_ENABLED'] = 'false';
    $artisanArgs = [PHP_BINARY, 'artisan', 'hermes:refresh-eod', '--metrics-only', '--session='.$session, '--limit='.$limit, '--tickers='.implode(',', $explicitTickers)];
    $process = proc_open($artisanArgs, [0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]], $pipes, $root, $env);
} elseif ($mode === '--canary') {
    putenv('IDX_EDGE_ENABLED=true'); $_ENV['IDX_EDGE_ENABLED'] = 'true'; $_SERVER['IDX_EDGE_ENABLED'] = 'true'; $env['IDX_EDGE_ENABLED'] = 'true';
    $process = proc_open([PHP_BINARY, 'artisan', 'hermes:refresh-eod', '--tickers='.$tickers, '--session='.$session, '--limit=1'], [0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]], $pipes, $root, $env);
} elseif ($mode === '--batch') {
    putenv('IDX_EDGE_ENABLED=true'); $_ENV['IDX_EDGE_ENABLED'] = 'true'; $_SERVER['IDX_EDGE_ENABLED'] = 'true'; $env['IDX_EDGE_ENABLED'] = 'true';
    if ($rpm !== null) {
        putenv('IDX_EDGE_REQUESTS_PER_MINUTE='.$rpm); $_ENV['IDX_EDGE_REQUESTS_PER_MINUTE'] = (string) $rpm; $_SERVER['IDX_EDGE_REQUESTS_PER_MINUTE'] = (string) $rpm; $env['IDX_EDGE_REQUESTS_PER_MINUTE'] = (string) $rpm;
    }
    $artisanArgs = [PHP_BINARY, 'artisan', 'hermes:refresh-eod', '--session='.$session, '--limit='.$limit];
    if ($retryOnly) $artisanArgs[] = '--retry-only';
    if ($canonicalGapOnly) $artisanArgs[] = '--canonical-gap';
    if ($triggerSource !== null) $artisanArgs[] = '--trigger-source='.$triggerSource;
    if ($explicitTickers !== null) $artisanArgs[] = '--tickers='.implode(',', $explicitTickers);
    $process = proc_open($artisanArgs, [0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]], $pipes, $root, $env);
} elseif ($mode === '--eod-daily') {
    // Production EOD for the calendar-resolved session; full universe, no ad-hoc session/limit.
    $dailyRpm = $rpm ?? 30;
    putenv('IDX_EDGE_ENABLED=true'); $_ENV['IDX_EDGE_ENABLED'] = 'true'; $_SERVER['IDX_EDGE_ENABLED'] = 'true'; $env['IDX_EDGE_ENABLED'] = 'true';
    putenv('IDX_EDGE_REQUESTS_PER_MINUTE='.$dailyRpm); $_ENV['IDX_EDGE_REQUESTS_PER_MINUTE'] = (string) $dailyRpm; $_SERVER['IDX_EDGE_REQUESTS_PER_MINUTE'] = (string) $dailyRpm; $env['IDX_EDGE_REQUESTS_PER_MINUTE'] = (string) $dailyRpm;
    $process = proc_open([PHP_BINARY, 'artisan', 'hermes:refresh-eod'], [0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]], $pipes, $root, $env);
} else {
    $artisanArgs = ['hermes:refresh-eod', '--dry-run'];
    if ($session !== null) $artisanArgs[] = '--session='.$session;
    $process = proc_open([PHP_BINARY, 'artisan', ...$artisanArgs], [0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]], $pipes, $root, $env);
}
if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
$childStdout = is_resource($process) && isset($pipes[1]) ? stream_get_contents($pipes[1]) : '';
$childStderr = is_resource($process) && isset($pipes[2]) ? stream_get_contents($pipes[2]) : '';
if (isset($pipes[1]) && is_resource($pipes[1])) fclose($pipes[1]);
if (isset($pipes[2]) && is_resource($pipes[2])) fclose($pipes[2]);
$status = is_resource($process) ? proc_close($process) : 7;
$cleanStdout = preg_replace('/^EOD semantic_status=.*$/m', '', $childStdout);
echo $cleanStdout;
fwrite(STDERR, $childStderr);
if ($mode === '--eod-daily' || $mode === '--batch') {
    $classified = classify_eod_result($childStdout, $childStderr, $status);
    fwrite(STDOUT, "EOD semantic_status={$classified['semantic_status']} exit_code={$classified['exit_code']} reason={$classified['reason']}\n");
    $status = $classified['exit_code'];
}
$password = '';
exit($status);
