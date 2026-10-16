<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class HermesAdapterService
{
    protected string $pythonPath;
    protected string $adapterScript;
    protected int $timeoutSeconds;

    public function __construct()
    {
        // Detect Python executable path or fallback to system python
        $this->pythonPath = env('HERMES_PYTHON_PATH', 'python');
        $this->adapterScript = base_path('../services/hermes_adapter.py');
        $this->timeoutSeconds = (int) env('HERMES_ADAPTER_TIMEOUT', 30);
    }

    /**
     * Execute an action on hermes_adapter.py and return parsed JSON.
     */
    public function execute(string $action, array $payload = []): array
    {
        $cmd = [
            $this->pythonPath,
            $this->adapterScript,
            '--action',
            $action,
            '--payload',
            json_encode($payload),
        ];

        try {
            $process = new Process($cmd);
            $process->setTimeout($this->timeoutSeconds);
            $process->run();

            $output = trim($process->getOutput());
            $decoded = json_decode($output, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            return [
                'ok' => false,
                'action' => $action,
                'generated_at_wib' => now()->toIso8601String(),
                'data' => null,
                'warnings' => [$process->getErrorOutput()],
                'error' => 'Gagal mengurai respons JSON dari Hermes Adapter: ' . substr($output, 0, 300),
            ];
        } catch (\Throwable $e) {
            Log::error("HermesAdapterService error on action [{$action}]: " . $e->getMessage());
            return [
                'ok' => false,
                'action' => $action,
                'generated_at_wib' => now()->toIso8601String(),
                'data' => null,
                'warnings' => [],
                'error' => 'Adapter exception: ' . $e->getMessage(),
            ];
        }
    }

    public function ping(): array
    {
        return $this->execute('ping');
    }

    public function getMarketSnapshots(array $symbols = []): array
    {
        return $this->execute('market_snapshots', empty($symbols) ? [] : ['symbols' => $symbols]);
    }

    public function getPaperTradingStatus(): array
    {
        return $this->execute('paper_trading_status');
    }

    public function getAccumulationSummary(array $symbols = []): array
    {
        return $this->execute('accumulation_summary', empty($symbols) ? [] : ['symbols' => $symbols]);
    }

    public function fetchNewsFeed(?string $feedUrl = null, int $limit = 15): array
    {
        $payload = ['limit' => $limit];
        if ($feedUrl) {
            $payload['feed_url'] = $feedUrl;
        }
        return $this->execute('fetch_news_feed', $payload);
    }
}
