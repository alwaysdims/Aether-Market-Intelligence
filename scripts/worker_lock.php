<?php

/**
 * Worker Lock Manager with Heartbeat, Owner Token, and Process Liveness.
 * Prevents overlap, recovers from dead PIDs/crashes/reboots safely,
 * and prevents older instances from releasing newer instances' locks.
 */

$rootDir = dirname(__DIR__);
$lockFile = $rootDir . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'workers.lock';

$action = $argv[1] ?? 'status';
$token = null;
$parentPid = 0;

for ($i = 2; $i < count($argv); $i++) {
    if (str_starts_with($argv[$i], '--token=')) {
        $token = substr($argv[$i], 8);
    } elseif (str_starts_with($argv[$i], '--ppid=')) {
        $parentPid = (int) substr($argv[$i], 7);
    }
}

if (!$token) {
    $token = 'WTOK_' . getmypid() . '_' . bin2hex(random_bytes(4));
}

function getParentPid(): int {
    $myPid = getmypid();
    $ppid = trim((string) @shell_exec("powershell -NoProfile -Command \"(Get-CimInstance Win32_Process -Filter \\\"ProcessId={$myPid}\\\").ParentProcessId\" 2>nul"));
    if ($ppid && is_numeric($ppid) && (int) $ppid > 0) {
        return (int) $ppid;
    }
    return $myPid;
}

if ($parentPid <= 0) {
    $parentPid = getParentPid();
}

function getProcessDetails(int $pid): array {
    if ($pid <= 0) {
        return ['alive' => false, 'image' => '', 'creation_date' => ''];
    }
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $out = [];
        exec("tasklist /FI \"PID eq {$pid}\" /FO CSV /NH 2>nul", $out);
        $found = false;
        $image = '';
        foreach ($out as $line) {
            if (str_contains($line, (string) $pid)) {
                $cols = str_getcsv($line);
                if (isset($cols[1]) && (int) $cols[1] === $pid) {
                    $found = true;
                    $image = strtolower(trim($cols[0] ?? ''));
                    break;
                }
            }
        }
        if (!$found) {
            return ['alive' => false, 'image' => '', 'creation_date' => ''];
        }

        $cmd = "powershell -NoProfile -Command \"(Get-CimInstance Win32_Process -Filter \\\"ProcessId={$pid}\\\").CreationDate\" 2>nul";
        $cDate = trim((string) @shell_exec($cmd));

        return ['alive' => true, 'image' => $image, 'creation_date' => $cDate];
    }

    $alive = function_exists('posix_kill') ? posix_kill($pid, 0) : true;
    return ['alive' => $alive, 'image' => 'posix', 'creation_date' => ''];
}

function verifyLockProcess(array $lockData): array {
    $pid = (int) ($lockData['pid'] ?? 0);
    if ($pid <= 0) {
        return ['valid' => false, 'reason' => 'PID dalam berkas lock tidak valid / kosong'];
    }

    $details = getProcessDetails($pid);
    if (!$details['alive']) {
        return ['valid' => false, 'reason' => "Process pemilik PID {$pid} sudah mati (crash / host reboot)"];
    }

    $allowedImages = ['php.exe', 'cmd.exe', 'powershell.exe', 'pwsh.exe', 'bash.exe', 'sh.exe', 'python.exe'];
    if (!in_array($details['image'], $allowedImages, true)) {
        return ['valid' => false, 'reason' => "PID reuse terdeteksi: PID {$pid} telah dialihkan ke proses non-worker ({$details['image']})"];
    }

    if (!empty($lockData['image']) && $details['image'] !== strtolower($lockData['image'])) {
        return ['valid' => false, 'reason' => "PID reuse terdeteksi: Image berubah dari {$lockData['image']} menjadi {$details['image']}"];
    }

    if (!empty($lockData['process_start_time']) && !empty($details['creation_date'])) {
        if ($details['creation_date'] !== $lockData['process_start_time']) {
            return ['valid' => false, 'reason' => "PID reuse terdeteksi: Waktu pembuatan proses berbeda ({$details['creation_date']} != {$lockData['process_start_time']})"];
        }
    }

    return ['valid' => true, 'reason' => ''];
}

switch ($action) {
    case 'acquire':
        if (file_exists($lockFile)) {
            $content = @file_get_contents($lockFile);
            $data = json_decode($content, true);

            $stale = false;
            $staleReason = '';

            if (!$data) {
                $stale = true;
                $staleReason = 'Format lock berkas korup / bukan JSON valid';
            } else {
                $verification = verifyLockProcess($data);
                if (!$verification['valid']) {
                    $stale = true;
                    $staleReason = $verification['reason'];
                } else {
                    // Process pemilik MASIH HIDUP dan identitas terverifikasi valid!
                    // Langkah kerja panjang (>15 menit) TIDAK BOLEH kehilangan lock.
                    echo "[BUSY] Worker aktif sedang berjalan (PID: {$data['pid']}, Image: {$data['image']}, Token: {$data['token']}, Started: {$data['started_at']})\n";
                    exit(1);
                }
            }

            if ($stale) {
                echo "[RECOVER] Stale lock terdeteksi: {$staleReason}. Menghapus lock lama...\n";
                @unlink($lockFile);
            }
        }

        // Atomic file creation via 'x' mode (O_CREAT | O_EXCL)
        // Mencegah double acquisition saat dua proses melakukan recovery bersamaan
        $h = @fopen($lockFile, 'x');
        if (!$h) {
            echo "[RACE] Gagal akuisisi atomik, instance lain baru saja mengambil lock\n";
            exit(1);
        }

        $myDetails = getProcessDetails($parentPid > 0 ? $parentPid : getmypid());

        $lockData = [
            'pid' => $parentPid > 0 ? $parentPid : getmypid(),
            'lock_script_pid' => getmypid(),
            'image' => $myDetails['image'] ?: 'cmd.exe',
            'process_start_time' => $myDetails['creation_date'] ?: null,
            'token' => $token,
            'host' => gethostname(),
            'started_at' => date('c'),
            'heartbeat_at' => date('c'),
            'heartbeat_type' => 'STEP_BOUNDARY',
        ];

        fwrite($h, json_encode($lockData, JSON_PRETTY_PRINT));
        fclose($h);

        echo "[ACQUIRED] Lock berhasil diperoleh. Token: {$token}\n";
        exit(0);

    case 'heartbeat':
        if (!file_exists($lockFile)) {
            echo "[HEARTBEAT_FAIL] Lock file tidak ditemukan\n";
            exit(1);
        }

        $data = json_decode(@file_get_contents($lockFile), true);
        if (!$data || ($data['token'] ?? '') !== $token) {
            echo "[HEARTBEAT_IGNORED] Token mismatch (Lock dimiliki oleh: " . ($data['token'] ?? 'NONE') . ")\n";
            exit(1);
        }

        $data['heartbeat_at'] = date('c');
        @file_put_contents($lockFile, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
        echo "[HEARTBEAT_OK] Heartbeat diperbarui untuk token: {$token}\n";
        exit(0);

    case 'release':
        if (!file_exists($lockFile)) {
            echo "[RELEASE_OK] Lock sudah tidak ada\n";
            exit(0);
        }

        $data = json_decode(@file_get_contents($lockFile), true);
        if (!$data || ($data['token'] ?? '') !== $token) {
            echo "[RELEASE_IGNORED] Lock dimiliki oleh instance baru (Token: " . ($data['token'] ?? 'NONE') . "). Instance lama tidak menghapus lock baru.\n";
            exit(0);
        }

        @unlink($lockFile);
        echo "[RELEASE_OK] Lock berhasil dilepas untuk token: {$token}\n";
        exit(0);

    case 'status':
    default:
        if (!file_exists($lockFile)) {
            echo "Status: IDLE (Tidak ada lock aktif)\n";
            exit(0);
        }

        $data = json_decode(@file_get_contents($lockFile), true);
        $verification = $data ? verifyLockProcess($data) : ['valid' => false];
        $alive = $verification['valid'];
        echo "Status: " . ($alive ? "RUNNING" : "STALE") . "\n";
        echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
        exit($alive ? 0 : 2);
}
