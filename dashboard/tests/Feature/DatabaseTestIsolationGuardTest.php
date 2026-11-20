<?php

namespace Tests\Feature;

use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class DatabaseTestIsolationGuardTest extends TestCase
{
    public function test_runtime_guard_rejects_post_bootstrap_operational_connection_switch(): void
    {
        $connection = new SQLiteConnection(fn () => null, 'project-bot', '', [
            'name' => 'unsafe_test_connection',
            'driver' => 'pgsql',
            'database' => 'project-bot',
            'username' => 'postgres',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SECURITY FAIL-CLOSED');

        Event::dispatch(new ConnectionEstablished($connection));
    }

    public function test_runtime_guard_rejects_recovery_database_switch(): void
    {
        $connection = new SQLiteConnection(fn () => null, 'project_bot_recovery_20261110', '', [
            'name' => 'unsafe_recovery_connection',
            'driver' => 'pgsql',
            'database' => 'project_bot_recovery_20261110',
            'username' => 'aether_ingestion',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SECURITY FAIL-CLOSED');

        Event::dispatch(new ConnectionEstablished($connection));
    }
}
