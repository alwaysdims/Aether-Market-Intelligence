<?php

require __DIR__ . '/../dashboard/vendor/autoload.php';
$app = require __DIR__ . '/../dashboard/bootstrap/app.php';
$app->make("Illuminate\Contracts\Console\Kernel")->bootstrap();

$cfg = config("database.connections.pgsql");
$pass = $cfg["password"];
$host = $cfg["host"];
$port = $cfg["port"];
$user = $cfg["username"];
$db = $cfg["database"];

$backupDir = "E:\\Aether-Market-Intelligence\\storage\\backups\\incident_20261107";
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}
$dumpFile = $backupDir . "\\project_bot_pgdump_20261107.dump";
$restoreDb = "project_bot_restore_test";

putenv("PGPASSWORD=" . $pass);

echo "=== STEP 1: EXECUTING PG_DUMP ===\n";
$pgDumpBin = "C:\\Program Files\\PostgreSQL\\16\\bin\\pg_dump.exe";
$pgRestoreBin = "C:\\Program Files\\PostgreSQL\\16\\bin\\pg_restore.exe";
$createdbBin = "C:\\Program Files\\PostgreSQL\\16\\bin\\createdb.exe";
$dropdbBin = "C:\\Program Files\\PostgreSQL\\16\\bin\\dropdb.exe";
$psqlBin = "C:\\Program Files\\PostgreSQL\\16\\bin\\psql.exe";

$dumpCmd = "cmd /c \"\"{$pgDumpBin}\" -h {$host} -p {$port} -U {$user} -d {$db} -F c -b -v -f \"{$dumpFile}\"\" 2>&1";
exec($dumpCmd, $dumpOut, $dumpExit);

echo "pg_dump Exit Code: {$dumpExit}\n";
if ($dumpExit !== 0) {
    echo "pg_dump Error Output:\n" . implode("\n", array_slice($dumpOut, -10)) . "\n";
    exit(1);
}

$filesize = filesize($dumpFile);
$sha256 = hash_file("sha256", $dumpFile);
echo "Dump File: {$dumpFile}\n";
echo "File Size: " . number_format($filesize) . " bytes\n";
echo "SHA-256: {$sha256}\n\n";

echo "=== DUMP CONTENTS SUMMARY (pg_restore --list) ===\n";
$listCmd = "cmd /c \"\"{$pgRestoreBin}\" --list \"{$dumpFile}\"\" 2>&1";
exec($listCmd, $listOut, $listExit);
echo "Total TOC items in dump: " . count($listOut) . "\n";
$tableCountInDump = 0;
foreach ($listOut as $line) {
    if (strpos($line, " TABLE ") !== false && strpos($line, " TABLE DATA ") === false) {
        $tableCountInDump++;
    }
}
echo "Total TABLE definitions in dump TOC: {$tableCountInDump}\n\n";

echo "=== STEP 2: CREATING ISOLATED POSTGRESQL RESTORE DB ===\n";
// Drop existing test db if any
$dropCmd = "cmd /c \"\"{$dropdbBin}\" -h {$host} -p {$port} -U {$user} --if-exists {$restoreDb}\" 2>&1";
exec($dropCmd, $dropOut, $dropExit);

// Create fresh test db
$createCmd = "cmd /c \"\"{$createdbBin}\" -h {$host} -p {$port} -U {$user} {$restoreDb}\" 2>&1";
exec($createCmd, $createOut, $createExit);
echo "createdb Exit Code: {$createExit}\n";
if ($createExit !== 0) {
    echo "createdb Output:\n" . implode("\n", $createOut) . "\n";
    exit(1);
}

echo "\n=== STEP 3: RESTORING TO ISOLATED POSTGRESQL DB ({$restoreDb}) ===\n";
$restoreCmd = "cmd /c \"\"{$pgRestoreBin}\" -h {$host} -p {$port} -U {$user} -d {$restoreDb} --clean --if-exists \"{$dumpFile}\"\" 2>&1";
exec($restoreCmd, $restoreOut, $restoreExit);
echo "pg_restore Exit Code: {$restoreExit} (Note: pg_restore exit code 1 can occur for benign warnings on --clean)\n";

echo "\n=== STEP 4: VERIFYING RESTORED POSTGRESQL DATABASE VS SOURCE ===\n";
$pdoSource = new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdoTarget = new PDO("pgsql:host={$host};port={$port};dbname={$restoreDb}", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Compare tables
$tablesSource = $pdoSource->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$tablesTarget = $pdoTarget->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);

echo "Source Table Count: " . count($tablesSource) . "\n";
echo "Target Table Count: " . count($tablesTarget) . "\n";

$missingTables = array_diff($tablesSource, $tablesTarget);
if (!empty($missingTables)) {
    echo "MISSING TABLES IN RESTORE: " . implode(", ", $missingTables) . "\n";
} else {
    echo "All source tables present in restore DB.\n";
}

echo "\n--- RECORD COUNT COMPARISON ---\n";
printf("%-35s | %-12s | %-12s | %-10s\n", "Table Name", "Source Count", "Restore Count", "Diff");
echo str_repeat("-", 75) . "\n";

$totalDiff = 0;
foreach ($tablesSource as $t) {
    $cSource = (int)$pdoSource->query("SELECT COUNT(*) FROM \"{$t}\"")->fetchColumn();
    $cTarget = in_array($t, $tablesTarget) ? (int)$pdoTarget->query("SELECT COUNT(*) FROM \"{$t}\"")->fetchColumn() : -1;
    $diff = $cTarget - $cSource;
    $totalDiff += abs($diff);
    printf("%-35s | %-12d | %-12d | %-10d\n", $t, $cSource, $cTarget, $diff);
}

echo str_repeat("-", 75) . "\n";
echo "Total Absolute Record Count Diff Across All Tables: {$totalDiff}\n";

// Compare sequences
$seqSource = $pdoSource->query("SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema='public' ORDER BY sequence_name")->fetchAll(PDO::FETCH_COLUMN);
$seqTarget = $pdoTarget->query("SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema='public' ORDER BY sequence_name")->fetchAll(PDO::FETCH_COLUMN);
echo "\nSequence Count: Source=" . count($seqSource) . " | Target=" . count($seqTarget) . "\n";

// Compare constraints & indexes
$idxSource = $pdoSource->query("SELECT COUNT(*) FROM pg_indexes WHERE schemaname='public'")->fetchColumn();
$idxTarget = $pdoTarget->query("SELECT COUNT(*) FROM pg_indexes WHERE schemaname='public'")->fetchColumn();
echo "Indexes Count: Source={$idxSource} | Target={$idxTarget}\n";

$fkSource = $pdoSource->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_type='FOREIGN KEY'")->fetchColumn();
$fkTarget = $pdoTarget->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_type='FOREIGN KEY'")->fetchColumn();
echo "Foreign Keys Count: Source={$fkSource} | Target={$fkTarget}\n";

echo "\n=== REAL POSTGRESQL RESTORE VERIFICATION COMPLETE ===\n";
