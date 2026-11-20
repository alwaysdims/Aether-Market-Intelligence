<?php

use App\Http\Middleware\DashboardResearchAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// SECURITY FAIL-CLOSED GUARD: Prevent test runner execution against production PostgreSQL DB 'project-bot'
$isTestRunner = defined('PHPUNIT_COMPOSER_INSTALL')
    || (isset($_SERVER['argv']) && is_array($_SERVER['argv']) && count(array_filter($_SERVER['argv'], fn ($a) => str_contains($a, 'phpunit'))) > 0)
    || (getenv('APP_ENV') === 'testing')
    || (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'testing');

if ($isTestRunner && getenv('AETHER_TEST_CONFIG_APPROVED') !== '1') {
    fwrite(STDERR, "SECURITY FAIL-CLOSED: PHPUnit must load dashboard/phpunit.xml.\n");
    exit(78);
}

if ($isTestRunner) {
    // Tests use an uncached SQLite connection and never inherit repository PostgreSQL settings.
    foreach ([
        'APP_CONFIG_CACHE' => 'bootstrap/cache/testing-config.php',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'DB_URL' => '',
        'DB_HOST' => '',
        'DB_PORT' => '',
        'DB_USERNAME' => '',
        'DB_PASSWORD' => '',
    ] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

if (getenv('DASHBOARD_CANDIDATE') === '1') {
    foreach ([
        'APP_ENV' => 'recovery',
        'APP_DEBUG' => 'false',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '5432',
        'DB_DATABASE' => getenv('AETHER_ACTIVE_DATABASE') ?: 'project_bot_recovered_20261115_022935',
        'DB_USERNAME' => 'aether_runtime',
        'CACHE_STORE' => 'file',
        'SESSION_DRIVER' => 'file',
        'QUEUE_CONNECTION' => 'sync',
        'DASHBOARD_CANDIDATE_READ_ONLY_HEALTH' => 'true',
        'AI_LIVE_INFERENCE_ENABLED' => 'false',
        'AI_LIVE_INFERENCE_ON_DEMAND_ENABLED' => 'false',
        'AI_LIVE_INFERENCE_EOD_ENABLED' => 'false',
        'HERMES_ORCHESTRATOR_ENABLED' => 'false',
        'IDX_EDGE_ENABLED' => 'false',
        'TELEGRAM_ENABLED' => 'false',
    ] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

$candidateStoragePath = getenv('LARAVEL_STORAGE_PATH') ?: ($_ENV['LARAVEL_STORAGE_PATH'] ?? null);

$application = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'dashboard.research.access' => DashboardResearchAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

if ($candidateStoragePath !== null && $candidateStoragePath !== '') {
    $application->useStoragePath($candidateStoragePath);
}

if (getenv('DASHBOARD_CANDIDATE') === '1') {
    foreach (['C:/', 'E:/'] as $prefix) {
        $application->addAbsoluteCachePathPrefix($prefix);
    }
}

return $application;
