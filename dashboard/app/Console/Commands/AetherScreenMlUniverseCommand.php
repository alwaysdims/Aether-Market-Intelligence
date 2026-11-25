<?php

namespace App\Console\Commands;

use App\Services\IdxEdgeClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AetherScreenMlUniverseCommand extends Command
{
    protected $signature = 'aether:screen-ml-universe {--dry-run : Run pooled research inference without persistence or delivery}';

    protected $description = 'Screen the frozen multi-ticker research cohort without promoting signals';

    /**
     * @var list<string>
     */
    private const COHORT = ['ARTO', 'ASII', 'BBCA', 'BBNI', 'BBRI', 'BMRI', 'MAPI', 'MEDC', 'SMRA', 'TLKM'];

    public function handle(IdxEdgeClient $client): int
    {
        $runtime = DB::selectOne('SELECT current_database() AS database, current_user AS user_name, current_schema() AS schema_name');
        if (! $runtime
            || $runtime->database !== 'project_bot_recovered_20261115_022935'
            || $runtime->user_name !== 'aether_ingestion'
            || $runtime->schema_name !== 'public') {
            $this->error('INFERENCE_BLOCKED:RUNTIME_IDENTITY_MISMATCH');

            return self::FAILURE;
        }

        $results = [];
        foreach (self::COHORT as $ticker) {
            $raw = $client->history($ticker, 500, false, null, null, true);
            $adjusted = $client->history($ticker, 500, true, null, null, true);
            if (! ($raw['success'] ?? false) || ! ($adjusted['success'] ?? false)) {
                $results[$ticker] = ['status' => 'DATA_UNAVAILABLE', 'reason' => 'PROVIDER_FAILURE'];

                continue;
            }

            $rawBars = $raw['data']['bars'] ?? [];
            $adjustedBars = $adjusted['data']['bars'] ?? [];
            $dates = array_values(array_intersect(
                array_map(static fn (array $bar): string => (string) ($bar['date'] ?? ''), $rawBars),
                array_map(static fn (array $bar): string => (string) ($bar['date'] ?? ''), $adjustedBars)
            ));
            if ($rawBars === [] || $adjustedBars === [] || $dates === []) {
                $results[$ticker] = ['status' => 'DATA_UNAVAILABLE', 'reason' => 'EMPTY_RAW_ADJUSTED_PAIR'];

                continue;
            }

            $barDate = max($dates);
            $payload = json_encode([
                'ticker' => $ticker,
                'raw' => $this->normalizeBars($rawBars),
                'adjusted' => $this->normalizeBars($adjustedBars),
                'data_cutoff' => "{$barDate}T16:00:00+07:00",
                'source' => 'IDX Edge PRO',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $command = 'python '.escapeshellarg(base_path('../quant-engine/run_runtime_multi_ticker_inference.py'));
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path('../quant-engine'));
            if (! is_resource($process)) {
                throw new RuntimeException('INFERENCE_BLOCKED:PYTHON_PROCESS_UNAVAILABLE');
            }
            fwrite($pipes[0], $payload);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            if ($exitCode !== 0) {
                $results[$ticker] = ['status' => 'INFERENCE_BLOCKED', 'reason' => trim($stderr ?: $stdout)];

                continue;
            }
            $receipt = json_decode(trim($stdout), true);
            $results[$ticker] = is_array($receipt)
                ? ['status' => $receipt['status'] ?? 'INVALID_RECEIPT', 'probability' => $receipt['probability'] ?? null, 'threshold' => $receipt['threshold'] ?? null, 'bar_date' => $receipt['bar_date'] ?? null, 'decision_id' => $receipt['decision_id'] ?? null]
                : ['status' => 'INFERENCE_BLOCKED', 'reason' => 'INVALID_RECEIPT'];
        }

        $this->line(json_encode([
            'status' => 'RESEARCH_ONLY',
            'production_publication' => 'DISABLED',
            'writes_executed' => false,
            'cohort' => self::COHORT,
            'as_of' => Carbon::now('Asia/Jakarta')->toIso8601String(),
            'results' => $results,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $bars */
    private function normalizeBars(array $bars): array
    {
        return array_values(array_map(static fn (array $bar): array => [
            'date' => (string) $bar['date'],
            'open' => (float) $bar['open'],
            'high' => (float) $bar['high'],
            'low' => (float) $bar['low'],
            'close' => (float) $bar['close'],
            'volume' => (float) $bar['volume'],
        ], $bars));
    }
}
