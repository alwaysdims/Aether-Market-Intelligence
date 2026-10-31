<?php

namespace Tests\Feature;

use Tests\TestCase;

class WorkerLockReliabilityTest extends TestCase
{
    private string $lockScript;

    private string $lockFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockScript = base_path('../scripts/worker_lock.php');
        $this->lockFile = base_path('../logs/workers.lock');
        @unlink($this->lockFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->lockFile);
        parent::tearDown();
    }

    public function test_two_concurrent_triggers_prevent_overlap(): void
    {
        exec("php {$this->lockScript} acquire --token=TOK_A --ppid=".getmypid(), $out1, $code1);
        $this->assertSame(0, $code1);

        exec("php {$this->lockScript} acquire --token=TOK_B --ppid=".getmypid(), $out2, $code2);
        $this->assertSame(1, $code2);
        $this->assertStringContainsString('BUSY', implode(' ', $out2));
    }

    public function test_job_over_10_minutes_with_fresh_heartbeat_is_not_treated_as_stale(): void
    {
        exec("php {$this->lockScript} acquire --token=TOK_LONG --ppid=".getmypid(), $out1, $code1);
        $this->assertSame(0, $code1);

        $data = json_decode(file_get_contents($this->lockFile), true);
        $data['started_at'] = date('c', time() - 1200); // 20 mins ago
        $data['heartbeat_at'] = date('c', time() - 30); // 30s ago
        file_put_contents($this->lockFile, json_encode($data, JSON_PRETTY_PRINT));

        exec("php {$this->lockScript} acquire --token=TOK_NEW", $out2, $code2);
        $this->assertSame(1, $code2);
        $this->assertStringContainsString('BUSY', implode(' ', $out2));
    }

    public function test_step_over_15_minutes_with_alive_process_does_not_lose_lock(): void
    {
        exec("php {$this->lockScript} acquire --token=TOK_LONG_STEP --ppid=".getmypid(), $out1, $code1);
        $this->assertSame(0, $code1);

        // Step takes > 15 minutes without heartbeat (e.g. 25 minutes ago)
        $data = json_decode(file_get_contents($this->lockFile), true);
        $data['started_at'] = date('c', time() - 1500); // 25 mins ago
        $data['heartbeat_at'] = date('c', time() - 1500); // 25 mins ago
        file_put_contents($this->lockFile, json_encode($data, JSON_PRETTY_PRINT));

        // Attempt acquire while process is STILL ALIVE
        exec("php {$this->lockScript} acquire --token=TOK_INTRUDER", $out2, $code2);
        $this->assertSame(1, $code2);
        $this->assertStringContainsString('BUSY', implode(' ', $out2));
    }

    public function test_pid_reuse_with_unrelated_image_is_recovered(): void
    {
        $fakeLock = [
            'pid' => getmypid(), // PID exists
            'image' => 'notepad.exe', // Mismatch! Current PID is php.exe
            'token' => 'TOK_REUSED_IMAGE',
            'started_at' => date('c', time() - 300),
            'heartbeat_at' => date('c', time() - 300),
        ];
        file_put_contents($this->lockFile, json_encode($fakeLock, JSON_PRETTY_PRINT));

        exec("php {$this->lockScript} acquire --token=TOK_RECOVERED_REUSE", $out, $code);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('PID reuse terdeteksi', implode(' ', $out));
        $this->assertStringContainsString('ACQUIRED', implode(' ', $out));
    }

    public function test_crash_or_reboot_dead_pid_is_recovered(): void
    {
        $fakeLock = [
            'pid' => 999991, // dead process
            'token' => 'TOK_CRASHED',
            'started_at' => date('c', time() - 300),
            'heartbeat_at' => date('c', time() - 300),
        ];
        file_put_contents($this->lockFile, json_encode($fakeLock, JSON_PRETTY_PRINT));

        exec("php {$this->lockScript} acquire --token=TOK_RECOVERED", $out, $code);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('RECOVER', implode(' ', $out));
        $this->assertStringContainsString('ACQUIRED', implode(' ', $out));
    }

    public function test_old_instance_cannot_release_new_instance_lock(): void
    {
        $newLock = [
            'pid' => getmypid(),
            'token' => 'TOK_NEW_OWNER',
            'started_at' => date('c'),
            'heartbeat_at' => date('c'),
        ];
        file_put_contents($this->lockFile, json_encode($newLock, JSON_PRETTY_PRINT));

        exec("php {$this->lockScript} release --token=TOK_OLD_STALE", $out, $code);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('RELEASE_IGNORED', implode(' ', $out));
        $this->assertFileExists($this->lockFile);

        $current = json_decode(file_get_contents($this->lockFile), true);
        $this->assertSame('TOK_NEW_OWNER', $current['token']);

        exec("php {$this->lockScript} release --token=TOK_NEW_OWNER", $out2, $code2);
        $this->assertSame(0, $code2);
        $this->assertFileDoesNotExist($this->lockFile);
    }
}
