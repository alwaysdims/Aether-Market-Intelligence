<?php

namespace App\Services;

use RuntimeException;

class DashboardResearchRequestBridgeService
{
    /** @return array<string, mixed> */
    public function audit(): array
    {
        return $this->runWriter(['--research-request-audit']);
    }

    /** @return array<string, mixed> */
    public function queue(string $ticker, string $analysisType, ?string $question = null): array
    {
        return $this->runWriter(['--research-request'], json_encode([
            'ticker' => $ticker,
            'analysis_type' => $analysisType,
            'question' => $question,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function runWriter(array $arguments, ?string $stdin = null): array
    {
        $root = dirname(base_path());
        $php = PHP_BINARY ?: 'php';
        if (strtolower(pathinfo($php, PATHINFO_BASENAME)) === 'php-cgi.exe') {
            $php = dirname($php).DIRECTORY_SEPARATOR.'php.exe';
        }
        $writer = $root.'/scripts/launch_app_writer.php';
        $runtime = (string) (getenv('LARAVEL_STORAGE_PATH') ?: storage_path());
        $bridgeLog = rtrim($runtime, '/\\').DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'dashboard_research_bridge.log';
        if (! is_dir(dirname($bridgeLog))) {
            @mkdir(dirname($bridgeLog), 0700, true);
        }
        $payload = $stdin ?? '';

        if (! is_file($writer)) {
            throw new RuntimeException('Writer bridge tidak tersedia.');
        }

        $startedAt = microtime(true);
        $childEnvironment = getenv();
        unset($childEnvironment['PGOPTIONS']);
        $process = proc_open(
            array_merge([$php, $writer], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $childEnvironment,
        );

        if (! is_resource($process)) {
            @file_put_contents($bridgeLog, json_encode([
                'recorded_at' => now('UTC')->toIso8601String(),
                'failure_point' => 'PROCESS_CREATE_FAILED',
                'php' => $php,
                'cwd' => $root,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
            throw new RuntimeException('Writer bridge gagal dimulai.');
        }

        $processState = proc_get_status($process);
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 20);
        stream_set_timeout($pipes[2], 20);
        $stdout = stream_get_contents($pipes[1]);
        $stdoutMeta = stream_get_meta_data($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $stderrMeta = stream_get_meta_data($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $result = json_decode(trim($stdout), true);
        if (! is_array($result)) {
            $sanitize = static function (string $value): string {
                $value = preg_replace('/(?:password|token|secret|api[_ -]?key|authorization)\\s*[=:]\\s*[^\\s,;}]+/i', '[REDACTED]', $value) ?: '';

                return substr(preg_replace('/[\\r\\n\\t]+/', ' ', $value) ?: '', 0, 2000);
            };
            @file_put_contents($bridgeLog, json_encode([
                'recorded_at' => now('UTC')->toIso8601String(),
                'failure_point' => 'RECEIPT_JSON_PARSE_FAILED',
                'executable' => $php,
                'cwd' => $root,
                'process_created' => true,
                'child_pid' => $processState['pid'] ?? null,
                'child_running_at_start' => $processState['running'] ?? null,
                'elapsed_ms' => $elapsedMs,
                'timeout' => (bool) (($stdoutMeta['timed_out'] ?? false) || ($stderrMeta['timed_out'] ?? false)),
                'exit_code' => $exitCode,
                'stdout' => $sanitize($stdout),
                'stderr' => $sanitize($stderr),
                'json_error' => json_last_error_msg(),
            ], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
            throw new RuntimeException($exitCode === 124 ? 'Writer bridge timeout.' : 'Writer bridge mengembalikan respons invalid.');
        }

        if ($exitCode !== 0 || in_array($result['status'] ?? null, ['REJECTED_INVALID_REQUEST', 'REJECTED_UNKNOWN_TICKER'], true)) {
            return $result + ['status' => 'WRITER_REJECTED', 'stderr_present' => trim($stderr) !== ''];
        }

        return $result;
    }
}
