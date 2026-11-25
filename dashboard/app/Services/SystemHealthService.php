<?php

namespace App\Services;

use App\Models\MarketAnalysis;
use App\Models\NotificationLog;
use App\Models\SystemHealthCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SystemHealthService
{
    private function sqlIdentity(): array
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return [
                'database' => config('database.connections.sqlite.database'),
                'user_name' => null,
                'schema_name' => null,
                'search_path' => null,
            ];
        }

        return (array) DB::selectOne("select current_database() as database, current_user as user_name, current_schema() as schema_name, current_setting('search_path') as search_path");
    }

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
                    'sql_identity' => $this->sqlIdentity(),
                    'market_observations' => (int) DB::table('market_observations')->count(),
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

        if (! config('services.dashboard_candidate.read_only_health', false)) {
            try {
                SystemHealthCheck::create($result);
            } catch (\Throwable) {
                // Production read role may not have write grant for health audit.
            }
        }

        return $result;
    }

    /**
     * Check dashboard AI configuration and Google model reachability without running inference.
     */
    public function checkDashboardAi(): array
    {
        $start = microtime(true);
        $provider = (string) config('services.dashboard_ai.provider', 'google');
        $model = (string) config('services.dashboard_ai.model', '');
        $baseUrl = rtrim((string) config('services.dashboard_ai.base_url', ''), '/');
        $apiKey = (string) config('services.dashboard_ai.api_key', '');
        $configured = $provider === 'google' && $model !== '' && $baseUrl !== '' && $apiKey !== '';
        $reachable = false;

        try {
            if ($configured) {
                $response = Http::timeout(5)
                    ->withHeaders(['x-goog-api-key' => $apiKey])
                    ->get($baseUrl.'/v1beta/models/'.rawurlencode($model));
                $reachable = $response->successful()
                    && in_array('generateContent', $response->json('supportedGenerationMethods', []), true);
            }

            $lastStored = MarketAnalysis::where('model_name', $model)->latest('id')->first();
            $validated = $lastStored?->validation_status === 'valid_schema';
            $result = [
                'component' => 'dashboard_ai',
                'status' => ! $configured ? 'not_configured' : ($reachable && $validated ? 'healthy' : 'degraded'),
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'details' => [
                    'provider' => $provider,
                    'model' => $model,
                    'configuration_available' => $configured,
                    'endpoint_reachable' => $reachable,
                    'inference_succeeded' => $validated,
                    'result_validated' => $validated,
                    'result_stored' => $lastStored !== null,
                    'last_validation_status' => $lastStored?->validation_status,
                    'last_stored_at' => $lastStored?->created_at,
                    'smoke_inference_executed' => false,
                ],
                'checked_at' => now(),
            ];
        } catch (\Throwable $e) {
            $result = [
                'component' => 'dashboard_ai',
                'status' => 'down',
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'details' => [
                    'provider' => $provider,
                    'model' => $model,
                    'configuration_available' => $configured,
                    'endpoint_reachable' => false,
                    'inference_succeeded' => false,
                    'result_validated' => false,
                    'result_stored' => false,
                    'error_class' => $e::class,
                    'smoke_inference_executed' => false,
                ],
                'checked_at' => now(),
            ];
        }

        if (! config('services.dashboard_candidate.read_only_health', false)) {
            try {
                SystemHealthCheck::create($result);
            } catch (\Throwable) {
                $result['details']['persistence'] = 'UNAVAILABLE_PERMISSION_DENIED';
            }
        }

        return $result;
    }

    /**
     * Check consultation model availability in local 9router without running inference.
     */
    public function checkConsultationRouter(): array
    {
        $start = microtime(true);
        $endpoint = rtrim((string) config('services.consultation_ai.endpoint', ''), '/');
        $model = (string) config('services.consultation_ai.model_id', '');

        try {
            $response = Http::timeout(3)->get($endpoint.'/models');
            $available = $response->successful()
                && collect($response->json('data', []))->contains('id', $model);

            return [
                'component' => 'consultation_router',
                'status' => $available ? 'healthy' : 'degraded',
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'details' => [
                    'provider' => 'consultation_ai',
                    'model' => $model,
                    'configuration_available' => $endpoint !== '' && $model !== '',
                    'endpoint_reachable' => $response->successful(),
                    'model_available' => $available,
                    'inference_succeeded' => false,
                    'smoke_inference_executed' => false,
                ],
                'checked_at' => now(),
            ];
        } catch (\Throwable $e) {
            return [
                'component' => 'consultation_router',
                'status' => 'down',
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'details' => [
                    'provider' => 'consultation_ai',
                    'model' => $model,
                    'configuration_available' => $endpoint !== '' && $model !== '',
                    'endpoint_reachable' => false,
                    'model_available' => false,
                    'inference_succeeded' => false,
                    'smoke_inference_executed' => false,
                    'error_class' => $e::class,
                ],
                'checked_at' => now(),
            ];
        }
    }

    /**
     * Backward-compatible alias for dashboard AI health.
     */
    public function checkAiModel(): array
    {
        return $this->checkDashboardAi();
    }

    /**
     * Check Hermes Stock Agent project availability.
     */
    public function checkHermesAgent(): array
    {
        $localQuant = base_path('../quant-engine');
        if (is_dir($localQuant) && file_exists($localQuant.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'paper_trading_engine.py')) {
            $path = $localQuant;
        } else {
            $path = 'E:\\hermes-stock-agent';
        }
        $exists = is_dir($path) && file_exists($path.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'paper_trading_engine.py');

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

        if (! config('services.dashboard_candidate.read_only_health', false)) {
            try {
                SystemHealthCheck::create($result);
            } catch (\Throwable) {
                // Production read role may not have write grant for health audit.
            }
        }

        return $result;
    }

    /**
     * Check Telegram Gateway channel via Hermes CLI.
     */
    public function checkTelegramChannel(): array
    {
        $lastLog = NotificationLog::where('channel', 'telegram')->latest()->first();
        $isCliAvailable = false;

        try {
            $cmd = PHP_OS_FAMILY === 'Windows' ? 'where hermes' : 'which hermes';
            $output = shell_exec($cmd);
            $isCliAvailable = ! empty($output);
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

        if (! config('services.dashboard_candidate.read_only_health', false)) {
            try {
                SystemHealthCheck::create($result);
            } catch (\Throwable) {
                // Production read role may not have write grant for health audit.
            }
        }

        return $result;
    }

    /**
     * Check shared running-trade collector state without claiming market liveness.
     */
    public function checkCollector(): array
    {
        $path = storage_path('app/running_trade_state.json');
        if (! is_file($path)) {
            $canonicalPath = base_path('storage/app/running_trade_state.json');
            if (is_file($canonicalPath)) {
                $path = $canonicalPath;
            }
        }
        $state = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $status = $state['status'] ?? 'DISCONNECTED';
        $marketStatus = $state['market_status']['status'] ?? 'unknown';
        $heartbeatEpoch = (int) ($state['heartbeat_epoch'] ?? 0);
        $heartbeatAge = $heartbeatEpoch > 0 ? max(0, now()->timestamp - $heartbeatEpoch) : null;
        $isStale = $status === 'CONNECTED' && ($heartbeatAge === null || $heartbeatAge > 35);
        $transport = $isStale ? 'degraded' : (in_array($status, ['CONNECTED', 'CONNECTING'], true) ? 'healthy' : 'down');

        $counterState = $state['counter_state'] ?? [];
        $runCounters = $counterState['run'] ?? [];
        $connectionCounters = $counterState['connection'] ?? [];

        return [
            'component' => 'running_trade_collector',
            'status' => $transport,
            'latency_ms' => 0,
            'details' => [
                'status' => $isStale ? 'STALE_NO_HEARTBEAT' : $status,
                'market_status' => $marketStatus,
                'board_scope' => $state['board_scope'] ?? 'BOARD_SCOPE_UNVERIFIED',
                'counter_status' => $state['total_trades_counter_status'] ?? 'COUNTER_UNVERIFIED',
                'run_counters' => [
                    'raw_frames' => $runCounters['raw_frames'] ?? null,
                    'live_trade_items' => $runCounters['live_trade_items'] ?? null,
                    'unique_accepted_live_trade_items' => $runCounters['unique_accepted_live_trade_items'] ?? null,
                    'snapshot_trade_items' => $runCounters['snapshot_trade_items'] ?? null,
                    'duplicate_trade_items' => $runCounters['duplicate_trade_items'] ?? null,
                    'ring_buffer_size' => $runCounters['ring_buffer_size'] ?? null,
                ],
                'connection_counters' => [
                    'connection_id' => $connectionCounters['connection_id'] ?? ($state['connection_id'] ?? null),
                    'raw_frames' => $connectionCounters['raw_frames'] ?? null,
                    'live_trade_items' => $connectionCounters['live_trade_items'] ?? null,
                    'unique_accepted_live_trade_items' => $connectionCounters['unique_accepted_live_trade_items'] ?? null,
                    'snapshot_trade_items' => $connectionCounters['snapshot_trade_items'] ?? null,
                    'duplicate_trade_items' => $connectionCounters['duplicate_trade_items'] ?? null,
                    'ring_buffer_size' => $connectionCounters['ring_buffer_size'] ?? null,
                ],
                'total_trades' => null,
                'total_trades_label' => 'COUNTER_UNVERIFIED_ACROSS_CONNECTIONS',
                'total_trade_frames' => (int) ($state['total_trade_frames'] ?? 0),
                'total_snapshot_trade_items' => (int) ($state['total_snapshot_trade_items'] ?? 0),
                'process_alive_last_observed_at' => $state['process_alive_last_observed_at'] ?? null,
                'connected_socket' => (bool) ($state['connected_socket'] ?? false),
                'last_market_event_at' => $state['last_market_event_at'] ?? null,
                'last_message_at' => $state['last_message_at'] ?? null,
                'last_trade_at' => $state['last_trade_at'] ?? null,
                'heartbeat_age_seconds' => $heartbeatAge,
                'freshness' => $isStale ? 'STALE' : ($status === 'CONNECTED' ? 'CURRENT' : 'NOT_CONNECTED'),
            ],
            'checked_at' => now(),
        ];
    }

    /**
     * Distinguish delivery records from transport health.
     */
    public function checkDelivery(): array
    {
        $lastLog = NotificationLog::where('channel', 'telegram')->latest()->first();
        $status = $lastLog?->status ?? 'none';

        return [
            'component' => 'delivery',
            'status' => $status === 'sent' ? 'healthy' : ($status === 'failed' ? 'degraded' : 'not_verified'),
            'latency_ms' => 0,
            'details' => [
                'last_status' => $status,
                'last_sent_at' => $lastLog && $lastLog->status === 'sent' ? $lastLog->sent_at : null,
                'outbound_test_executed' => false,
            ],
            'checked_at' => now(),
        ];
    }

    /**
     * Run all health checks.
     */
    public function checkAll(): array
    {
        $dashboardAi = $this->checkDashboardAi();

        return [
            'database' => $this->checkDatabase(),
            'ingestion' => [
                'component' => 'ingestion',
                'status' => 'disabled_for_recovery',
                'latency_ms' => 0,
                'details' => ['writer_active' => false, 'scheduler_active' => false],
                'checked_at' => now(),
            ],
            'dashboard_ai' => $dashboardAi,
            'consultation_router' => $this->checkConsultationRouter(),
            'ai_model' => $dashboardAi,
            'hermes_agent' => $this->checkHermesAgent(),
            'running_trade_collector' => $this->checkCollector(),
            'telegram_channel' => $this->checkTelegramChannel(),
            'delivery' => $this->checkDelivery(),
        ];
    }
}
