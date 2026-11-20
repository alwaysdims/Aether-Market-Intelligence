<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PostgresTimezoneAndDeadlineSemanticsTest extends TestCase
{
    private ?string $schemaName = null;

    protected function setUp(): void
    {
        parent::setUp();

        $database = getenv('TEST_PG_DATABASE') ?: '';
        $username = getenv('TEST_PG_USERNAME') ?: '';
        $password = getenv('TEST_PG_PASSWORD');

        if ($database === '' || $username === '' || $password === false) {
            $this->markTestSkipped('Set TEST_PG_DATABASE, TEST_PG_USERNAME, and TEST_PG_PASSWORD to run PostgreSQL integration tests.');
        }

        if (! preg_match('/(?:^|_)test(?:_|$)/i', $database)) {
            throw new RuntimeException('TEST_PG_DATABASE must identify a dedicated test database.');
        }

        config([
            'database.connections.pgsql_test' => [
                'driver' => 'pgsql',
                'host' => getenv('TEST_PG_HOST') ?: '127.0.0.1',
                'port' => getenv('TEST_PG_PORT') ?: '5432',
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => getenv('TEST_PG_SSLMODE') ?: 'prefer',
            ],
        ]);

        DB::purge('pgsql_test');
        $connection = DB::connection('pgsql_test');
        $identity = $connection->selectOne(<<<'SQL'
            SELECT current_database() AS database,
                   current_user AS user_name,
                   current_schema() AS schema_name,
                   rolsuper
            FROM pg_roles
            WHERE rolname = current_user
        SQL);

        if ($identity?->database !== $database || $identity?->user_name !== $username || $identity?->schema_name !== 'public') {
            throw new RuntimeException('PostgreSQL test connection identity mismatch.');
        }

        if ((bool) ($identity->rolsuper ?? true)) {
            throw new RuntimeException('PostgreSQL integration tests require a limited, non-superuser role.');
        }

        $this->schemaName = 'test_sem_'.bin2hex(random_bytes(4));
        $connection->statement("CREATE SCHEMA {$this->schemaName}");
        $connection->statement("
            CREATE TABLE {$this->schemaName}.scheduled_telegram_jobs (
                id SERIAL PRIMARY KEY,
                scheduled_at timestamp without time zone,
                deadline_at timestamp without time zone
            )
        ");
    }

    protected function tearDown(): void
    {
        if ($this->schemaName !== null) {
            DB::connection('pgsql_test')->statement("DROP SCHEMA IF EXISTS {$this->schemaName} CASCADE");
            DB::purge('pgsql_test');
        }

        parent::tearDown();
    }

    public function test_postgres_utc_wib_and_midnight_crossover_deadline_arithmetic(): void
    {
        $wibTime = Carbon::create(2026, 10, 28, 23, 55, 0, 'Asia/Jakarta');
        $utcScheduled = $wibTime->copy()->utc();
        $expectedDeadline = $utcScheduled->copy()->addMinutes(15);

        DB::connection('pgsql_test')->statement("
            INSERT INTO {$this->schemaName}.scheduled_telegram_jobs (scheduled_at, deadline_at)
            VALUES (?, ?)
        ", [$utcScheduled->format('Y-m-d H:i:s'), $expectedDeadline->format('Y-m-d H:i:s')]);

        $raw = DB::connection('pgsql_test')->selectOne("
            SELECT scheduled_at, deadline_at, (deadline_at - scheduled_at) AS diff_interval
            FROM {$this->schemaName}.scheduled_telegram_jobs
            LIMIT 1
        ");

        $this->assertNotNull($raw);
        $this->assertEquals($expectedDeadline->format('Y-m-d H:i:s'), Carbon::parse($raw->deadline_at)->format('Y-m-d H:i:s'));
        $this->assertEquals($utcScheduled->format('Y-m-d H:i:s'), Carbon::parse($raw->scheduled_at)->format('Y-m-d H:i:s'));

        $diffSeconds = abs(Carbon::parse($raw->deadline_at)->diffInSeconds(Carbon::parse($raw->scheduled_at), false));
        $this->assertEquals(900, $diffSeconds);
    }

    public function test_postgres_column_data_types_are_without_timezone(): void
    {
        $columns = DB::connection('pgsql_test')->select("
            SELECT column_name, data_type
            FROM information_schema.columns
            WHERE table_schema = ?
              AND table_name = 'scheduled_telegram_jobs'
              AND column_name IN ('scheduled_at', 'deadline_at')
        ", [$this->schemaName]);

        $map = [];
        foreach ($columns as $column) {
            $map[$column->column_name] = $column->data_type;
        }

        $this->assertEquals('timestamp without time zone', $map['scheduled_at'] ?? null);
        $this->assertEquals('timestamp without time zone', $map['deadline_at'] ?? null);
    }
}
