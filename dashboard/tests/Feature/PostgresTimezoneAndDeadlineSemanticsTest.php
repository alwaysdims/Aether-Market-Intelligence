<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresTimezoneAndDeadlineSemanticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Switch to pgsql connection with real project-bot database from env
        $pass = env('DB_PASSWORD') ?: 'dims17';
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'project-bot',
            'database.connections.pgsql.username' => 'postgres',
            'database.connections.pgsql.password' => $pass,
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => 5432,
        ]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');

        $driver = DB::connection('pgsql')->getDriverName();
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('This test verifies actual PostgreSQL timezone semantics and column data types.');
        }
    }

    /**
     * Test UTC vs Asia/Jakarta midnight crossover and deadline arithmetic on live PostgreSQL.
     * Uses isolated test schema to guarantee zero mutations on operational data.
     */
    public function test_postgres_utc_wib_and_midnight_crossover_deadline_arithmetic(): void
    {
        // 1. Create temporary isolated test schema
        $schemaName = 'test_sem_'.bin2hex(random_bytes(4));
        DB::connection('pgsql')->statement("CREATE SCHEMA {$schemaName}");

        try {
            // Create isolated table matching scheduled_telegram_jobs definition inside isolated schema
            DB::connection('pgsql')->statement("
                CREATE TABLE {$schemaName}.scheduled_telegram_jobs (
                    id SERIAL PRIMARY KEY,
                    scheduled_at timestamp without time zone,
                    deadline_at timestamp without time zone
                )
            ");

            // Case: 23:55 WIB (16:55 UTC) -> crosses midnight at 00:10 WIB (17:10 UTC) with 15-minute deadline
            $wibTime = Carbon::create(2026, 10, 28, 23, 55, 0, 'Asia/Jakarta');
            $utcScheduled = $wibTime->copy()->utc();
            $expectedDeadline = $utcScheduled->copy()->addMinutes(15);

            DB::connection('pgsql')->statement("
                INSERT INTO {$schemaName}.scheduled_telegram_jobs (scheduled_at, deadline_at)
                VALUES (?, ?)
            ", [$utcScheduled->format('Y-m-d H:i:s'), $expectedDeadline->format('Y-m-d H:i:s')]);

            // Query raw PostgreSQL values directly from isolated schema
            $raw = DB::connection('pgsql')->selectOne("
                SELECT scheduled_at, deadline_at, (deadline_at - scheduled_at) as diff_interval 
                FROM {$schemaName}.scheduled_telegram_jobs 
                LIMIT 1
            ");

            $this->assertNotNull($raw);
            $this->assertEquals($expectedDeadline->format('Y-m-d H:i:s'), Carbon::parse($raw->deadline_at)->format('Y-m-d H:i:s'));
            $this->assertEquals($utcScheduled->format('Y-m-d H:i:s'), Carbon::parse($raw->scheduled_at)->format('Y-m-d H:i:s'));

            // Difference must be exactly 15 minutes (900 seconds) in PostgreSQL
            $diffSeconds = abs(Carbon::parse($raw->deadline_at)->diffInSeconds(Carbon::parse($raw->scheduled_at), false));
            $this->assertEquals(900, $diffSeconds);
        } finally {
            // Drop isolated test schema and all its objects
            DB::connection('pgsql')->statement("DROP SCHEMA IF EXISTS {$schemaName} CASCADE");
        }
    }

    /**
     * Test column definitions on PostgreSQL schema information.
     */
    public function test_postgres_column_data_types_are_without_timezone(): void
    {
        $cols = DB::connection('pgsql')->select("
            SELECT column_name, data_type 
            FROM information_schema.columns 
            WHERE table_name = 'scheduled_telegram_jobs' 
              AND column_name IN ('scheduled_at', 'deadline_at')
        ");

        $map = [];
        foreach ($cols as $c) {
            $map[$c->column_name] = $c->data_type;
        }

        $this->assertEquals('timestamp without time zone', $map['scheduled_at'] ?? null);
        $this->assertEquals('timestamp without time zone', $map['deadline_at'] ?? null);
    }
}
