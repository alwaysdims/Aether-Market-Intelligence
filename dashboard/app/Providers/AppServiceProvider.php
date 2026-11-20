<?php

namespace App\Providers;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $isTesting = app()->environment('testing')
            || app()->runningUnitTests()
            || defined('PHPUNIT_COMPOSER_INSTALL')
            || (isset($_SERVER['argv'][0]) && str_contains($_SERVER['argv'][0], 'phpunit'));

        $isGuardedRuntime = (string) getenv('AETHER_ACTIVE_DATABASE') !== ''
            && (string) getenv('AETHER_WRITER_ROLE') !== '';

        if (! $isTesting && ! $isGuardedRuntime) {
            return;
        }

        if ($isTesting) {
            $this->assertSafeTestConnection(config('database.default'), config('database.connections.'.config('database.default'), []));
        }

        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use ($isTesting, $isGuardedRuntime): void {
            if ($isTesting) {
                $this->assertSafeTestConnection($event->connectionName, $event->connection->getConfig());
                $this->assertPostgresIdentity($event->connection);
            }

            if ($isGuardedRuntime) {
                $this->assertGuardedRuntimeIdentity($event->connection);
            }
        });

        if ($isGuardedRuntime && DB::connection()->getPdo()) {
            $this->assertGuardedRuntimeIdentity(DB::connection());
        }
    }

    /** @param array<string, mixed> $config */
    private function assertSafeTestConnection(string $connectionName, array $config): void
    {
        $driver = (string) ($config['driver'] ?? '');
        $database = (string) ($config['database'] ?? '');
        $username = (string) ($config['username'] ?? '');

        if ($driver === 'sqlite' && $database === ':memory:') {
            return;
        }

        if ($driver === 'pgsql'
            && preg_match('/(?:^|_)test(?:_|$)/i', $database) === 1
            && $username !== ''
            && strtolower($username) !== 'postgres') {
            return;
        }

        throw new RuntimeException(
            "SECURITY FAIL-CLOSED: Test connection '{$connectionName}' must use SQLite :memory: or a dedicated PostgreSQL test database with a limited role."
        );
    }

    private function assertPostgresIdentity(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }
        $expectedDatabase = (string) $connection->getConfig('database');
        $expectedUsername = (string) $connection->getConfig('username');
        $statement = $connection->getPdo()->query(<<<'SQL'
            SELECT current_database() AS database,
                   current_user AS user_name,
                   current_schema() AS schema_name,
                   rolsuper
            FROM pg_roles
            WHERE rolname = current_user
        SQL);
        $identity = $statement === false ? false : $statement->fetch(\PDO::FETCH_ASSOC);

        if ($identity === false
            || $identity['database'] !== $expectedDatabase
            || $identity['user_name'] !== $expectedUsername
            || $identity['schema_name'] !== 'public'
            || filter_var($identity['rolsuper'], FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('SECURITY FAIL-CLOSED: PostgreSQL test connection identity mismatch or role is superuser.');
        }
    }

    private function assertGuardedRuntimeIdentity(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('SECURITY FAIL-CLOSED: Guarded runtime requires PostgreSQL.');
        }

        $expectedDatabase = (string) getenv('AETHER_ACTIVE_DATABASE');
        $expectedUsername = (string) getenv('AETHER_WRITER_ROLE');
        $identity = $connection->selectOne('SELECT current_database() AS database, current_user AS user_name, current_schema() AS schema_name, rolsuper FROM pg_roles WHERE rolname = current_user');

        if (! $identity
            || $identity->database !== $expectedDatabase
            || $identity->user_name !== $expectedUsername
            || $identity->schema_name !== 'public'
            || (bool) $identity->rolsuper) {
            throw new RuntimeException('SECURITY FAIL-CLOSED: Guarded runtime database or role identity mismatch.');
        }
    }
}
