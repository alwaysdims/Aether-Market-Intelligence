<?php
$root = dirname(__DIR__);
$db = getenv('AETHER_ACTIVE_DATABASE') ?: 'project_bot_recovered_20261115_022935';
$user = getenv('DB_USERNAME') ?: 'postgres';
$password = getenv('DB_PASSWORD') ?: '';
$mode = $argv[1] ?? '--pretend';
if (! in_array($mode, ['--pretend', '--apply'], true)) { fwrite(STDERR, "usage: sync_bei_registry.php --pretend|--apply\n"); exit(2); }
if (! preg_match('/^project_bot_recovered_[0-9]{8}_[0-9]{6}$/', $db)) { fwrite(STDERR, "identity_gate_failed\n"); exit(3); }
$path = $root.'/dashboard/storage/app/idx_registry_2026-11-20.json';
$payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$rows = $payload['items'] ?? [];
if (count($rows) !== 945) { fwrite(STDERR, "source_count_gate_failed\n"); exit(4); }
$seen = [];
foreach ($rows as $row) { $ticker = strtoupper(trim((string) ($row['ticker'] ?? ''))); if (! preg_match('/^[A-Z0-9]{4,5}$/', $ticker)) { fwrite(STDERR, "ticker_format_gate_failed\n"); exit(5); } $seen[$ticker] = true; }
if (count($seen) !== 945) { fwrite(STDERR, "ticker_uniqueness_gate_failed\n"); exit(6); }
if ($mode === '--pretend') { echo json_encode(['mode'=>'pretend','database'=>$db,'source_count'=>count($rows),'unique_tickers'=>count($seen),'writes_executed'=>false], JSON_UNESCAPED_SLASHES).PHP_EOL; exit(0); }
if ($password === '') { fwrite(STDERR, "missing_admin_password\n"); exit(7); }
$pdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname={$db}", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$identity = $pdo->query('SELECT current_database() AS db, current_user AS usr, pg_is_in_recovery() AS standby')->fetch(PDO::FETCH_ASSOC);
if ($identity['db'] !== $db || $identity['standby'] === true || ! in_array($identity['usr'], ['postgres', 'aether_migrator'], true)) { throw new RuntimeException('identity_gate_failed'); }
$pdo->beginTransaction();
$sql = "INSERT INTO idx_company_registries (ticker, company_name, listing_board, instrument_type, listing_status, source, snapshot_date, created_at, updated_at) VALUES (:ticker, :company_name, 'BEI', 'EQUITY', 'ACTIVE', 'BEI Ringkasan Saham 2026-11-20', '2026-11-20', NOW(), NOW()) ON CONFLICT (ticker) DO UPDATE SET company_name=EXCLUDED.company_name, listing_board=EXCLUDED.listing_board, instrument_type=EXCLUDED.instrument_type, listing_status=EXCLUDED.listing_status, source=EXCLUDED.source, snapshot_date=EXCLUDED.snapshot_date, updated_at=NOW()";
$stmt = $pdo->prepare($sql);
foreach ($rows as $row) { $stmt->execute(['ticker'=>strtoupper(trim((string) $row['ticker'])), 'company_name'=>(string) $row['company_name']]); }
$count = (int) $pdo->query("SELECT count(*) FROM idx_company_registries WHERE instrument_type='EQUITY' AND listing_board='BEI'")->fetchColumn();
$zyrx = $pdo->query("SELECT company_name FROM idx_company_registries WHERE ticker='ZYRX'")->fetchColumn();
if ($count !== 945 || $zyrx !== 'Zyrexindo Mandiri Buana Tbk.') { $pdo->rollBack(); throw new RuntimeException('readback_gate_failed'); }
$pdo->commit();
$password = null;
echo json_encode(['mode'=>'apply','database'=>$db,'source_count'=>count($rows),'db_count'=>$count,'zyrx'=>$zyrx,'writes_executed'=>true], JSON_UNESCAPED_SLASHES).PHP_EOL;
