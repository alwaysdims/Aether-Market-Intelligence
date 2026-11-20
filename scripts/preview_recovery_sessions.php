<?php
$db = getenv('DB_DATABASE') ?: '';
$user = getenv('DB_USERNAME') ?: '';
$pass = getenv('DB_PASSWORD') ?: '';
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '5432';
if ($db !== 'project_bot_recovered_20261115_022935' || $user !== 'aether_ingestion' || $pass === '') { fwrite(STDERR, "preview SQL environment mismatch\n"); exit(2); }
$pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
$inspectionDate = $now->format('Y-m-d');
$cutoff = '16:05';
$holidays = ['2026-12-25','2026-08-26','2026-08-17','2026-06-16','2026-06-01','2026-05-28','2026-05-14','2026-05-01','2026-04-03','2026-03-23','2026-03-22','2026-03-21','2026-03-20','2026-02-17','2026-01-16','2026-01-01'];
$isTrading = static function (string $date) use ($holidays): bool { $d = new DateTimeImmutable($date); return (int)$d->format('N') < 6 && !in_array($date, $holidays, true); };
$queue = $pdo->query("SELECT session_date::text AS session_date,status,priority FROM public.eod_session_queues ORDER BY session_date")->fetchAll(PDO::FETCH_ASSOC);
$targets = [];
foreach ($queue as $row) {
    $date = $row['session_date'];
    if (! $isTrading($date)) continue;
    $closed = $date < $inspectionDate || ($date === $inspectionDate && $now->format('H:i') >= $cutoff);
    $coverage = $pdo->prepare("SELECT adjustment_mode,count(DISTINCT ticker)::int AS tickers,count(*)::int AS rows FROM public.market_observations WHERE source='IDX Edge PRO' AND board_scope='RG' AND validation_status='VALID' AND bar_date=? GROUP BY adjustment_mode ORDER BY adjustment_mode");
    $coverage->execute([$date]);
    $modes = $coverage->fetchAll(PDO::FETCH_ASSOC);
    $needsRecovery = count($modes) < 2 || count(array_filter($modes, fn ($mode): bool => (int) $mode['tickers'] < 499)) > 0;
    $queueStatus = strtoupper((string) $row['status']);
    $eligibleNow = $closed && $needsRecovery && in_array($queueStatus, ['PENDING', 'QUEUED_WAITING_LOCK'], true);
    $targets[] = [
        'target_session' => $date,
        'queue_status' => $queueStatus,
        'is_trading_day' => true,
        'inspection_date' => $inspectionDate,
        'inspection_time_wib' => $now->format('H:i:sP'),
        'cutoff_wib' => $cutoff,
        'cutoff_passed' => $closed,
        'coverage' => $modes,
        'needs_recovery' => $needsRecovery,
        'eligible_now' => $eligibleNow,
        'deferral_reason' => $eligibleNow ? null : (!$closed ? 'SESSION_NOT_CLOSED' : (!$needsRecovery ? 'NO_RECOVERY_NEEDED' : 'STATUS_NOT_RETRYABLE')),
    ];
}

$out=['status'=>'READ_ONLY_PREVIEW','database'=>$db,'user'=>$user,'inspection_date'=>$inspectionDate,'inspection_time_wib'=>$now->format('H:i:sP'),'cutoff_wib'=>$cutoff,'source_of_targets'=>'existing eod_session_queues intersected with BEI calendar','targets'=>$targets,'weekend_2026_11_07_excluded'=>true,'provider_http_executed'=>false,'writes_executed'=>false,'secret_printed'=>false];
file_put_contents('E:/Aether-Market-Intelligence/storage/incidents/20261110_operational_data_loss/recovery-session-preview-20261110.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);echo json_encode($out,JSON_UNESCAPED_SLASHES).PHP_EOL;
