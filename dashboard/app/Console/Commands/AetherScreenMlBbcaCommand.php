<?php

namespace App\Console\Commands;

use App\Models\EvidenceSnapshot;
use App\Models\PredictionSignal;
use App\Models\TelegramOutbox;
use App\Services\IdxEdgeClient;
use App\Services\TelegramFormattingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AetherScreenMlBbcaCommand extends Command
{
    protected $signature = 'aether:screen-ml-bbca {--live : Persist evidence and enqueue one allowlisted Telegram status or signal} {--dry-run : Run provider and inference without persistence or delivery}';

    protected $description = 'Run frozen BBCA ML experiment through IDX Edge and existing Telegram outbox';

    public function handle(IdxEdgeClient $client, TelegramFormattingService $formatter): int
    {
        $runtime = DB::selectOne('SELECT current_database() AS database, current_user AS user_name, current_schema() AS schema_name');
        if (! $runtime
            || $runtime->database !== 'project_bot_recovered_20261115_022935'
            || $runtime->user_name !== 'aether_ingestion'
            || $runtime->schema_name !== 'public') {
            $this->error('INFERENCE_BLOCKED:RUNTIME_IDENTITY_MISMATCH');

            return self::FAILURE;
        }

        $now = Carbon::now('Asia/Jakarta');
        $raw = $client->history('BBCA', 500, false, null, null, true);
        $adjusted = $client->history('BBCA', 500, true, null, null, true);

        foreach (['raw' => $raw, 'adjusted' => $adjusted] as $name => $result) {
            if (! ($result['success'] ?? false)) {
                $this->error(json_encode([
                    'status' => 'INFERENCE_BLOCKED',
                    'stage' => "{$name}_history",
                    'reason' => $result['error_type'] ?? 'PROVIDER_FAILURE',
                    'message' => $result['message'] ?? 'Provider request failed.',
                ], JSON_UNESCAPED_UNICODE));

                return self::FAILURE;
            }
        }

        $rawBars = $raw['data']['bars'] ?? [];
        $adjustedBars = $adjusted['data']['bars'] ?? [];
        if ($rawBars === [] || $adjustedBars === []) {
            $this->error('INFERENCE_BLOCKED:PROVIDER_EMPTY_HISTORY');

            return self::FAILURE;
        }

        $dates = array_values(array_intersect(
            array_map(static fn (array $bar): string => (string) ($bar['date'] ?? ''), $rawBars),
            array_map(static fn (array $bar): string => (string) ($bar['date'] ?? ''), $adjustedBars)
        ));
        $barDate = max($dates);
        if ($barDate !== $now->toDateString()) {
            $this->error("INFERENCE_BLOCKED:STALE_DATA:{$barDate}");

            return self::FAILURE;
        }

        $payload = json_encode([
            'raw' => $this->normalizeBars($rawBars),
            'adjusted' => $this->normalizeBars($adjustedBars),
            'data_cutoff' => "{$barDate}T16:00:00+07:00",
            'source' => 'IDX Edge PRO',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $command = 'python '.escapeshellarg(base_path('../quant-engine/run_runtime_bbca_inference.py'));
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path('../quant-engine'));
        if (! is_resource($process)) {
            $this->error('INFERENCE_BLOCKED:PYTHON_PROCESS_UNAVAILABLE');

            return self::FAILURE;
        }
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $this->error('INFERENCE_BLOCKED:'.trim($stderr ?: $stdout));

            return self::FAILURE;
        }

        $receipt = json_decode(trim($stdout), true);
        if (! is_array($receipt) || ! isset($receipt['probability'], $receipt['artifact_sha256'], $receipt['schema_hash'])) {
            $this->error('INFERENCE_BLOCKED:INVALID_RECEIPT');

            return self::FAILURE;
        }
        $receipt['runtime_provider'] = [
            'raw_http_status' => $raw['http_status'] ?? null,
            'adjusted_http_status' => $adjusted['http_status'] ?? null,
            'raw_payload_sha256' => $raw['payload_sha256'] ?? null,
            'adjusted_payload_sha256' => $adjusted['payload_sha256'] ?? null,
            'raw_latency_ms' => $raw['latency_ms'] ?? null,
            'adjusted_latency_ms' => $adjusted['latency_ms'] ?? null,
        ];

        if (! $this->option('live')) {
            $this->line(json_encode(['status' => 'READY_DRY_RUN', 'receipt' => $receipt, 'writes_executed' => false], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $dedupKey = 'ML_EXPERIMENT_BBCA_'.$receipt['bar_date'];
        $existing = TelegramOutbox::where('dedup_key', $dedupKey)
            ->orWhere('metadata->inference_receipt->ticker', 'BBCA')
            ->where('metadata->inference_receipt->bar_date', $receipt['bar_date'])
            ->first();
        if ($existing) {
            $this->line(json_encode([
                'status' => 'ALREADY_PROCESSED',
                'dedup_key' => $dedupKey,
                'outbox_id' => $existing->id,
                'outbox_status' => $existing->status,
                'telegram_message_id' => $existing->telegram_message_id,
                'writes_executed' => false,
            ], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $result = DB::transaction(function () use ($receipt, $formatter, $now, $dedupKey): array {
            $decisionId = $receipt['decision_id'];
            if (TelegramOutbox::where('dedup_key', $dedupKey)->lockForUpdate()->exists()) {
                throw new RuntimeException('DEDUP_RACE_RETRY_BLOCKED');
            }
            $dataHash = hash('sha256', json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $evidence = EvidenceSnapshot::create([
                'snapshot_id' => 'EV_'.$decisionId,
                'ticker' => 'BBCA',
                'decision_date' => $receipt['bar_date'],
                'market_regime' => 'ML_EXPERIMENTAL',
                'features_snapshot' => ['inference_receipt' => $receipt],
                'related_news_snapshot' => null,
                'data_hash' => $dataHash,
            ]);

            $probability = (float) $receipt['probability'];
            $status = $receipt['status'];
            $message = implode("\n", [
                'AETHER — SWING',
                'BBCA | Status pemantauan',
                "ID: {$decisionId}",
                "Data: {$receipt['bar_date']} | cutoff {$receipt['data_cutoff']} | IDX Edge PRO",
                '',
                'Status: '.($status === 'EXPERIMENTAL_SELECTED' ? 'Rencana aktif' : 'Belum ada rencana aktif'),
                'Cakupan pemeriksaan: BBCA',
                $status === 'MODEL_ABSTAIN' ? 'Alasan: kondisi model belum memenuhi batas rencana.' : 'Rencana tersedia pada catatan pemantauan.',
                '',
                'Angka dan status pemeriksaan tersimpan pada catatan internal.',
                'Tidak ada order broker atau bukti broker fill.',
            ]);

            $signal = null;
            if ($status === 'EXPERIMENTAL_SELECTED') {
                $reference = (float) ($receipt['reference_price'] ?? 0);
                if ($reference <= 0) {
                    throw new RuntimeException('PLAN_REJECTED:REFERENCE_PRICE_INVALID');
                }
                $signal = PredictionSignal::create([
                    'prediction_id' => 'ML_'.$decisionId,
                    'ticker' => 'BBCA',
                    'signal_status' => 'PUBLISHED_DIRECTIONAL',
                    'direction' => 'UP',
                    'probability_up' => $probability,
                    'probability_down' => 1 - $probability,
                    'max_probability' => $probability,
                    'confidence_score' => $probability,
                    'threshold_applied' => $receipt['threshold'],
                    'model_name' => 'LOGISTIC_REGRESSION',
                    'model_version' => $receipt['contract_version'],
                    'feature_schema_hash' => substr($receipt['schema_hash'], 0, 32),
                    'decision_date' => $receipt['bar_date'],
                    'decision_timestamp' => $now,
                    'reference_price' => $reference,
                    'reference_price_date' => $receipt['bar_date'],
                    'predicted_horizon_days' => 5,
                    'evaluation_due_date' => $now->copy()->addWeekdays(5)->toDateString(),
                    'published_at' => $now,
                    'expires_at' => $now->copy()->addDays(7),
                    'monitoring_status' => 'WAITING_FOR_TRIGGER',
                    'evaluation_status' => 'PENDING',
                    'target_price' => $reference * 1.06,
                    'invalidation_price' => $reference * 0.95,
                    'evidence_snapshot_id' => $evidence->id,
                    'metadata' => ['mode' => 'EXPERIMENTAL', 'delivery_status' => 'DECISION_CREATED', 'inference_receipt' => $receipt],
                ]);
            }

            $outbox = $formatter->enqueueOutbox(
                dedupKey: $dedupKey,
                messageType: $signal ? 'INITIAL_SIGNAL' : 'SYSTEM_ALERT',
                payload: $message,
                predictionId: $signal?->prediction_id,
                chatId: (string) config('services.telegram.chat_id'),
                triggerSource: 'ML_EXPERIMENTAL_SCREENING',
                metadata: ['evidence_id' => $evidence->id, 'inference_receipt' => $receipt, 'mode' => 'EXPERIMENTAL']
            );

            return ['evidence_id' => $evidence->id, 'signal_id' => $signal?->prediction_id, 'outbox_id' => $outbox->id, 'outbox_status' => $outbox->status, 'message' => $message];
        });

        $this->line(json_encode(['status' => 'PERSISTED', 'receipt' => $receipt, 'records' => $result], JSON_UNESCAPED_UNICODE));
        if ($this->option('live')) {
            $sent = $formatter->processOutboxQueue(mockOnly: false, onlyOutboxId: $result['outbox_id']);
            $this->line(json_encode(['delivery_attempt' => $sent], JSON_UNESCAPED_UNICODE));
        }

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
