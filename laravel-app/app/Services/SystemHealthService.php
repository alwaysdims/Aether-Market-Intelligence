<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\SystemHealthCheck;

class SystemHealthService
{
    /**
     * Check PostgreSQL database connection.
     */
    public function checkDatabase(): array
    {
        $start = microtime(true);
        try {
            DB::connection()->getPdo();
            $latency = (int) round((microtime(true) - $start) * 1000);
            $result = [
                'component' => 'database',
                'status' => 'healthy',
                'latency_ms' => $latency,
                'details' => [
                    'connection' => config('database.default'),
                    'database' => config('database.connections.pgsql.database'),
                ],
                'checked_at' => now(),
            ];
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);
            $result = [
                'component' => 'database',
                'status' => 'down',
                'latency_ms' => $latency,
                'details' => ['error' => $e->getMessage()],
                'checked_at' => now(),
            ];
        }

        SystemHealthCheck::create($result);
        return $result;
    }

    /**
     * Check 9router AI Model endpoint.
     */
    public function checkAiModel(): array
    {
        $start = microtime(true);
        $url = 'http://127.0.0.1:20128/v1/models';
        try {
            $resp = Http::timeout(3)->get($url);
            $latency = (int) round((microtime(true) - $start) * 1000);
            $models = $resp->json('data', []);
            $hasGemini = collect($models)->contains('id', 'ag/gemini-3.8-flash-high');

            $result = [
                'component' => 'ai_model',
                'status' => ($resp->successful() && $hasGemini) ? 'healthy' : 'degraded',
                'latency_ms' => $latency,
                'details' => [
                    'endpoint' => $url,
                    'model' => 'ag/gemini-3.8-flash-high',
                    'available_in_router' => $hasGemini,
                ],
                'checked_at' => now(),
            ];
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);
            $result = [
                'component' => 'ai_model',
                'status' => 'down',
                'latency_ms' => $latency,
                'details' => ['error' => $e->getMessage()],
                'checked_at' => now(),
            ];
        }

        SystemHealthCheck::create($result);
        return $result;
    }

    /**
     * Check Hermes Stock Agent project availability.
     */
    public function checkHermesAgent(): array
    {
        $path = 'E:\\hermes-stock-agent';
        $exists = is_dir($path) && file_exists($path . '\\src\\paper_trading_engine.py');

        $result = [
            'component' => 'hermes_agent',
            'status' => $exists ? 'healthy' : 'down',
            'latency_ms' => 0,
            'details' => [
                'path' => $path,
                'accessible' => $exists,
            ],
            'checked_at' => now(),
        ];

        SystemHealthCheck::create($result);
        return $result;
    }

    /**
     * Check Telegram Gateway channel via Hermes CLI.
     */
    public function checkTelegramChannel(): array
    {
        $lastLog = \App\Models\NotificationLog::where('channel', 'telegram')->latest()->first();
        $isCliAvailable = false;
        
        try {
            $cmd = PHP_OS_FAMILY === 'Windows' ? 'where hermes' : 'which hermes';
            $output = shell_exec($cmd);
            $isCliAvailable = !empty($output);
        } catch (\Throwable $e) {
            $isCliAvailable = false;
        }

        $status = $isCliAvailable ? 'healthy' : 'degraded';

        $result = [
            'component' => 'telegram_channel',
            'status' => $status,
            'latency_ms' => 0,
            'details' => [
                'bot' => '@Info_BosKuu_bot',
                'cli_available' => $isCliAvailable,
                'last_sent_at' => $lastLog && $lastLog->status === 'sent' ? $lastLog->sent_at : null,
                'last_status' => $lastLog ? $lastLog->status : 'none',
            ],
            'checked_at' => now(),
        ];

        SystemHealthCheck::create($result);
        return $result;
    }

    /**
     * Run all health checks.
     */
    public function checkAll(): array
    {
        return [
            'database' => $this->checkDatabase(),
            'ai_model' => $this->checkAiModel(),
            'hermes_agent' => $this->checkHermesAgent(),
            'telegram_channel' => $this->checkTelegramChannel(),
        ];
    }
}
