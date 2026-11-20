<?php

/**
 * Apply pending dashboard migrations to the recovery database with an in-process identity gate.
 *
 * Usage:
 *   php scripts/apply_recovery_migrations.php --pretend   (dry run, prints SQL only)
 *   php scripts/apply_recovery_migrations.php --apply     (executes pending migrations)
 *
 * The DB credentials are supplied by scripts/apply_recovery_migrations.ps1 through the
 * child-process environment. Nothing is written to .env and no secret is printed.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);

$expectedDatabase = getenv('AETHER_ACTIVE_DATABASE') ?: 'project_bot_recovered_20261115_022935';
$allowedUsers = ['postgres', 'aether_migrator'];
if (! preg_match('/^project_bot_recovered_[0-9]{8}_[0-9]{6}$/', $expectedDatabase)) {
    fwrite(STDERR, "MIGRATION_ABORT active database is not a recovered candidate\n");
    exit(3);
}

$mode = $argv[1] ?? '';
if (! in_array($mode, ['--pretend', '--apply'], true)) {
    fwrite(STDERR, "usage: php scripts/apply_recovery_migrations.php --pretend|--apply\n");
    exit(2);
}

require $root.'/dashboard/vendor/autoload.php';

// Use a disposable external cache path. Never read or recreate repository config cache.
$configCache = getenv('APP_CONFIG_CACHE') ?: (getenv('LOCALAPPDATA') ?: $root).'/Aether-Market-Intelligence/recovery/bootstrap-cache/migration-config.php';
$configCache = str_replace('\\', '/', $configCache);
$hadConfigCache = is_file($configCache);
if ($hadConfigCache && ! @unlink($configCache)) {
    fwrite(STDERR, "MIGRATION_ABORT cannot clear cached config\n");
    exit(3);
}

$app = require $root.'/dashboard/bootstrap/app.php';
$kernel = $app->make(ConsoleKernel::class);
$kernel->bootstrap();

register_shutdown_function(static function () use ($hadConfigCache): void {
    if (! $hadConfigCache) {
        return;
    }
    try {
        ob_start();
        Artisan::call('config:cache');
        ob_end_clean();
    } catch (Throwable) {
        // Cache restore is best-effort; the DB outcome is already reported.
    }
});

try {
    $identity = DB::selectOne('select current_database() as db, current_user as usr, current_schema() as sch');
} catch (Throwable $e) {
    fwrite(STDERR, "MIGRATION_ABORT connection failed: ".$e->getMessage()."\n");
    exit(3);
}

$database = (string) $identity->db;
$user = (string) $identity->usr;

if ($database !== $expectedDatabase || ! in_array($user, $allowedUsers, true)) {
    fwrite(STDERR, sprintf(
        "MIGRATION_ABORT identity gate failed: database=%s user=%s (expected %s, user in %s)\n",
        $database,
        $user,
        $expectedDatabase,
        implode('|', $allowedUsers)
    ));
    exit(3);
}

if ((bool) DB::selectOne('select pg_is_in_recovery() as r')->r) {
    fwrite(STDERR, "MIGRATION_ABORT database is a standby replica\n");
    exit(3);
}

$applied = DB::table('migrations')->pluck('migration')->all();
$files = array_map(fn ($path) => basename($path, '.php'), glob($root.'/dashboard/database/migrations/*.php'));
sort($files);
$pending = array_values(array_diff($files, $applied));
$orphans = array_values(array_diff($applied, $files));

echo json_encode([
    'mode' => ltrim($mode, '-'),
    'identity' => ['database' => $database, 'user' => $user, 'schema' => (string) $identity->sch],
    'applied_count' => count($applied),
    'file_count' => count($files),
    'pending' => $pending,
    'applied_without_file' => $orphans,
], JSON_UNESCAPED_SLASHES).PHP_EOL;

if ($orphans !== []) {
    fwrite(STDERR, "MIGRATION_ABORT migrations table references missing files\n");
    exit(4);
}

if ($pending === []) {
    echo "NOTHING_PENDING\n";
    exit(0);
}

$arguments = ['--force' => true, '--no-interaction' => true];
if ($mode === '--pretend') {
    $arguments['--pretend'] = true;
}

$status = Artisan::call('migrate', $arguments);
echo Artisan::output();

if ($mode === '--pretend') {
    echo $status === 0 ? "PRETEND_OK\n" : "PRETEND_FAILED\n";
    exit($status === 0 ? 0 : 5);
}

$after = DB::table('migrations')->pluck('migration')->all();
$remaining = array_values(array_diff($files, $after));
echo json_encode([
    'migrated_count' => count($after),
    'remaining_pending' => $remaining,
    'news_items_columns_present' => (bool) DB::selectOne("select count(*) > 0 as ok from information_schema.columns where table_schema='public' and table_name='news_items' and column_name='first_seen_at'")->ok,
    'universe_discovery_runs' => DB::selectOne("select to_regclass('public.universe_discovery_runs') is not null as ok")->ok,
    'universe_discovery_items' => DB::selectOne("select to_regclass('public.universe_discovery_items') is not null as ok")->ok,
    'market_observations' => (int) DB::selectOne('select count(*) as c from public.market_observations')->c,
    'idx_company_registries' => (int) DB::selectOne('select count(*) as c from public.idx_company_registries')->c,
    'news_items' => (int) DB::selectOne('select count(*) as c from public.news_items')->c,
], JSON_UNESCAPED_SLASHES).PHP_EOL;

exit($status === 0 && $remaining === [] ? 0 : 5);
