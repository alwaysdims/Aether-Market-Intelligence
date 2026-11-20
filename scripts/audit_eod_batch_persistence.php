<?php

$db = getenv('AETHER_ACTIVE_DATABASE') ?: 'project_bot_recovered_20261115_022935';
$role = getenv('AETHER_WRITER_ROLE') ?: 'aether_ingestion';
$root = 'E:/Aether-Market-Intelligence';
$credentialFile = $root.'/storage/incidents/20261110_operational_data_loss/ingestion-credentials/aether_ingestion.dpapi.txt';
$decryptor = $root.'/scripts/decrypt_ingestion_secret.ps1';
$session = null;
$tickers = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--session=')) $session = substr($arg, 10);
    if (str_starts_with($arg, '--tickers=')) $tickers = array_values(array_filter(array_map('trim', explode(',', strtoupper(substr($arg, 10))))));
}
if ($session === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $session) || $tickers === null || count($tickers) < 1 || count($tickers) > 20 || count($tickers) !== count(array_unique($tickers))) {
    fwrite(STDERR, "audit requires --session=YYYY-MM-DD and 1..20 unique --tickers=CODE values\n");
    exit(2);
}
if (!is_file($credentialFile) || !is_file($decryptor)) {
    fwrite(STDERR, "credential store or decryptor unavailable\n");
    exit(3);
}
$proc = proc_open(['C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe', '-NoProfile', '-NonInteractive', '-File', $decryptor, '-Path', $credentialFile], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $root);
if (!is_resource($proc)) exit(4);
fclose($pipes[0]);
$password = trim(stream_get_contents($pipes[1]));
stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
if (proc_close($proc) !== 0 || $password === '') exit(4);
try {
    $pdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname={$db}", $role, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    unset($password);
    $identity = $pdo->query("SELECT current_database() AS database, current_user AS user_name, current_schema() AS schema_name, current_setting('TimeZone') AS timezone")->fetch(PDO::FETCH_ASSOC);
    if ($identity['database'] !== $db || $identity['user_name'] !== $role || $identity['schema_name'] !== 'public') throw new RuntimeException('SQL identity mismatch');
    $placeholders = implode(',', array_fill(0, count($tickers), '?'));
    $bars = $pdo->prepare("SELECT ticker, adjustment_mode, count(*)::int AS rows, max(id)::int AS max_id, max(updated_at)::text AS updated_at
        FROM public.market_observations
        WHERE ticker IN ({$placeholders}) AND bar_date = ? AND interval = '1d'
          AND data_classification = 'PUBLIC_MARKET_EOD' AND source = 'IDX Edge PRO'
          AND board_scope = 'RG' AND validation_status = 'VALID'
        GROUP BY ticker, adjustment_mode");
    $bars->execute([...$tickers, $session]);
    $barRows = $bars->fetchAll(PDO::FETCH_ASSOC);
    $metrics = $pdo->prepare("SELECT ticker, count(*)::int AS rows, max(updated_at)::text AS updated_at,
            max(data_quality_flags->>'last_date') AS last_date,
            max(data_quality_flags->>'as_of_date') AS as_of_date,
            max(data_quality_flags->>'adjustment_mode') AS adjustment_mode,
            max(data_quality_flags->>'source') AS source,
            max(data_quality_flags->>'board_scope') AS board_scope
        FROM public.company_analysis_metrics
        WHERE ticker IN ({$placeholders}) AND as_of_date = ?
        GROUP BY ticker");
    $metrics->execute([...$tickers, $session]);
    $metricRows = $metrics->fetchAll(PDO::FETCH_ASSOC);
    $byTicker = [];
    foreach ($tickers as $ticker) {
        $byTicker[$ticker] = ['ticker'=>$ticker, 'session'=>$session, 'raw_rows'=>0, 'adjusted_rows'=>0, 'metric_rows'=>0, 'canonical_complete'=>false, 'metric_status'=>'METRIC_MISSING'];
    }
    foreach ($barRows as $row) {
        if (!isset($byTicker[$row['ticker']])) continue;
        if ($row['adjustment_mode'] === 'UNADJUSTED') $byTicker[$row['ticker']]['raw_rows'] = (int) $row['rows'];
        if ($row['adjustment_mode'] === 'PROVIDER_BACK_ADJUSTED') $byTicker[$row['ticker']]['adjusted_rows'] = (int) $row['rows'];
        $byTicker[$row['ticker']]['bar_updated_at_'.$row['adjustment_mode']] = $row['updated_at'];
    }
    foreach ($metricRows as $row) {
        if (!isset($byTicker[$row['ticker']])) continue;
        $byTicker[$row['ticker']]['metric_rows'] = (int) $row['rows'];
        $byTicker[$row['ticker']]['metric_status'] = ((int) $row['rows'] === 1 && $row['last_date'] === $session && $row['as_of_date'] === $session) ? 'METRIC_MATCH' : 'METRIC_STALE_OR_INVALID';
        $byTicker[$row['ticker']]['metric_updated_at'] = $row['updated_at'];
        $byTicker[$row['ticker']]['metric_input_last_date'] = $row['last_date'];
        $byTicker[$row['ticker']]['metric_input_as_of_date'] = $row['as_of_date'];
    }
    foreach ($byTicker as &$row) $row['canonical_complete'] = $row['raw_rows'] === 1 && $row['adjusted_rows'] === 1;
    unset($row);
    $rows = array_values($byTicker);
    $canonicalComplete = count(array_filter($rows, fn(array $row): bool => $row['canonical_complete'])) === count($rows);
    $metricMatch = count(array_filter($rows, fn(array $row): bool => $row['metric_status'] === 'METRIC_MATCH'));
    $remainingStmt = $pdo->prepare("SELECT count(*)::int FROM public.idx_company_registries r WHERE r.instrument_type = 'EQUITY'
        AND (r.backfill_status IS NULL OR r.backfill_status <> 'UNSUPPORTED_SHARE_CLASS')
        AND (NOT EXISTS (SELECT 1 FROM public.market_observations o WHERE o.ticker = r.ticker AND o.bar_date = ? AND o.interval = '1d' AND o.data_classification = 'PUBLIC_MARKET_EOD' AND o.source = 'IDX Edge PRO' AND o.adjustment_mode = 'UNADJUSTED' AND o.board_scope = 'RG' AND o.validation_status = 'VALID')
        OR NOT EXISTS (SELECT 1 FROM public.market_observations o WHERE o.ticker = r.ticker AND o.bar_date = ? AND o.interval = '1d' AND o.data_classification = 'PUBLIC_MARKET_EOD' AND o.source = 'IDX Edge PRO' AND o.adjustment_mode = 'PROVIDER_BACK_ADJUSTED' AND o.board_scope = 'RG' AND o.validation_status = 'VALID'))");
    $remainingStmt->execute([$session, $session]);
    $remaining = (int) $remainingStmt->fetchColumn();
    echo json_encode(['status'=>'READ_ONLY_CANONICAL_AUDIT','identity'=>$identity,'target_session'=>$session,'requested_tickers'=>$tickers,'canonical_complete'=>$canonicalComplete,'canonical_complete_count'=>count(array_filter($rows, fn(array $row): bool => $row['canonical_complete'])),'metric_match_count'=>$metricMatch,'remaining_gap_count'=>$remaining,'rows'=>$rows,'writes_executed'=>false,'secret_printed'=>false], JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($canonicalComplete ? 0 : 10);
} catch (Throwable $e) {
    unset($password);
    fwrite(STDERR, 'canonical audit failed: '.$e->getMessage().PHP_EOL);
    exit(5);
}
