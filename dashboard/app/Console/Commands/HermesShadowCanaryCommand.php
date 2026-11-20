<?php

namespace App\Console\Commands;

use App\Models\EvidenceSnapshot;
use App\Models\TelegramOutbox;
use App\Services\TelegramFormattingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HermesShadowCanaryCommand extends Command
{
    protected $signature = 'hermes:shadow-canary
        {--run-id= : Exact active collector run ID}
        {--live : Send one descriptive canary through the official outbox}
        {--dry-run : Read and render without writing evidence or outbox}';

    protected $description = 'Publish one globally deduplicated descriptive shadow-feed canary';

    private const DEDUP_KEY = 'SHADOW_DESCRIPTIVE_CANARY_V1';

    private const CANARY_TYPE = 'SHADOW_DESCRIPTIVE_CANARY';

    private const THRESHOLD_IDR = 100000000;

    public function handle(TelegramFormattingService $formatter): int
    {
        $runId = trim((string) $this->option('run-id'));
        if (! preg_match('/^WSRUN_[0-9]{8}_[0-9]{6}_[a-z0-9]{8}$/', $runId)) {
            $this->error('run-id must identify one collector run.');

            return self::FAILURE;
        }

        $statePath = base_path('../dashboard/storage/app/running_trade_state.json');
        if (! is_file($statePath)) {
            $this->error('collector state file missing.');

            return self::FAILURE;
        }

        $state = json_decode((string) file_get_contents($statePath), true);
        if (! is_array($state) || ($state['run_id'] ?? null) !== $runId || ($state['status'] ?? null) !== 'CONNECTED' || ! ($state['connected_socket'] ?? false)) {
            $this->error('collector run is not the active connected owner.');

            return self::FAILURE;
        }

        $artifacts = $state['run_artifacts'] ?? [];
        $summaryPath = $artifacts['summary'] ?? null;
        if (! is_string($summaryPath) || ! is_file($summaryPath)) {
            $this->error('run-scoped shadow summary missing.');

            return self::FAILURE;
        }

        $summary = json_decode((string) file_get_contents($summaryPath), true);
        if (! is_array($summary) || ($summary['status'] ?? null) !== 'OBSERVED_FEED_ACTIVITY_ONLY') {
            $this->error('run-scoped shadow summary invalid.');

            return self::FAILURE;
        }

        $candidatePath = $artifacts['notification_candidates'] ?? null;
        $candidateCount = is_string($candidatePath) && is_file($candidatePath)
            ? count(file($candidatePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
            : 0;

        $connectionId = (string) ($state['connection_id'] ?? '');
        $coverage = $state['session_coverage'] ?? [];
        $observedFrom = (string) ($summary['observed_from_received_at_utc'] ?? 'NOT OBSERVED');
        $observedUntil = (string) ($summary['observed_until_received_at_utc'] ?? 'NOT OBSERVED');
        $transactions = (int) ($summary['transactions_count'] ?? 0);
        $valueIdr = (float) ($summary['total_value_idr'] ?? 0);
        $tickerCount = (int) ($state['shadow_summary']['tickers'] ? count($state['shadow_summary']['tickers']) : ($summary['ticker_count'] ?? 0));
        $sessionDate = (string) ($state['session_date'] ?? 'UNKNOWN');

        if ($transactions < 1 || $valueIdr <= 0 || $tickerCount < 1) {
            $this->error('no threshold-qualified observed activity available.');

            return self::FAILURE;
        }

        $payload = implode("\n", [
            'CANARY DESKRIPTIF — SHADOW FEED',
            'Status: OBSERVED_FEED_ACTIVITY_ONLY',
            "Run: {$runId}",
            "Periode received UTC: {$observedFrom} s.d. {$observedUntil}",
            "Transaksi memenuhi threshold >= Rp 100.000.000: {$transactions}",
            'Total nilai teramati: Rp '.number_format($valueIdr, 0, ',', '.'),
            "Cakupan ticker: {$tickerCount}",
            "Kandidat notifikasi lokal: {$candidateCount}",
            "Tanggal sesi: {$sessionDate}",
            'Cakupan sesi: '.($coverage['window_status'] ?? 'PARTIAL_SESSION_UNTIL_PROVEN'),
            'Event time: EVENT_TIME_UNVERIFIED',
            'Board: BOARD_SCOPE_UNVERIFIED',
            '',
            'Ini sampel aktivitas feed yang teramati, bukan total transaksi pasar.',
            'Bukan net buying, bukan akumulasi broker, bukan rekomendasi beli/jual, dan bukan sinyal ML.',
            'Replay recovery, lossless transport, RG scope, dan latency end-to-end belum terbukti.',
            'Publikasi shadow berkala tetap DISABLED setelah canary ini.',
        ]);

        $evidenceData = [
            'canary_type' => self::CANARY_TYPE,
            'run_id' => $runId,
            'connection_id' => $connectionId,
            'summary' => $summary,
            'candidate_count' => $candidateCount,
            'session_date' => $sessionDate,
            'session_coverage' => $coverage,
            'publication' => 'DESCRIPTIVE_CANARY_ONCE',
            'shadow_publication' => 'DISABLED',
            'event_time_status' => 'EVENT_TIME_UNVERIFIED',
            'board_scope' => 'BOARD_SCOPE_UNVERIFIED',
            'market_total_claim' => 'NOT_CLAIMED',
            'net_buying_claim' => 'NOT_CLAIMED',
            'ml_called' => false,
            'gemini_called' => false,
        ];
        ksort($evidenceData);
        $dataHash = hash('sha256', json_encode($evidenceData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        if ($this->option('dry-run')) {
            $this->line(json_encode([
                'status' => 'READY_DRY_RUN',
                'run_id' => $runId,
                'dedup_key' => self::DEDUP_KEY,
                'payload' => $payload,
                'data_hash' => $dataHash,
                'writes_executed' => false,
                'publication' => 'NOT_SENT',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if (! $this->option('live')) {
            $this->error('live is required for one canary publication.');

            return self::FAILURE;
        }

        $existing = TelegramOutbox::where('dedup_key', self::DEDUP_KEY)->first();
        if ($existing) {
            if (in_array($existing->status, ['QUEUED', 'PROCESSING'], true)) {
                $formatter->processOutboxQueue(mockOnly: false, onlyOutboxId: $existing->id);
                $existing->refresh();
                $messageId = (string) ($existing->telegram_message_id ?? '');
                $sent = $existing->status === 'SENT' && ctype_digit($messageId);
                $this->line(json_encode([
                    'status' => $sent ? 'VERIFIED' : 'NOT_VERIFIED',
                    'dedup_key' => self::DEDUP_KEY,
                    'outbox_id' => $existing->id,
                    'outbox_status' => $existing->status,
                    'telegram_message_id' => $existing->telegram_message_id,
                    'publication' => 'ONE_DESCRIPTIVE_CANARY_RESUMED',
                    'retry_allowed' => false,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return $sent ? self::SUCCESS : self::FAILURE;
            }

            $this->line(json_encode([
                'status' => 'ALREADY_USED',
                'dedup_key' => self::DEDUP_KEY,
                'outbox_id' => $existing->id,
                'outbox_status' => $existing->status,
                'telegram_message_id' => $existing->telegram_message_id,
                'evidence_id' => $existing->metadata['evidence_id'] ?? null,
                'payload_contract' => [
                    'observed_feed_only' => str_contains($existing->payload_text, 'bukan total transaksi pasar'),
                    'not_net_buying' => str_contains($existing->payload_text, 'Bukan net buying'),
                    'not_recommendation' => str_contains($existing->payload_text, 'bukan rekomendasi beli/jual'),
                    'not_ml_signal' => str_contains($existing->payload_text, 'bukan sinyal ML'),
                    'publication_disabled_after' => str_contains($existing->payload_text, 'Publikasi shadow berkala tetap DISABLED'),
                ],
                'publication' => 'ONE_CANARY_GLOBAL_LIMIT',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $result = DB::transaction(function () use ($dataHash, $evidenceData, $payload, $runId, $connectionId) {
            $existing = TelegramOutbox::where('dedup_key', self::DEDUP_KEY)->lockForUpdate()->first();
            if ($existing) {
                return ['existing' => $existing];
            }

            $evidence = EvidenceSnapshot::create([
                'snapshot_id' => 'EV_SHADOW_CANARY_'.substr($runId, 6),
                'ticker' => 'MARKET',
                'decision_date' => Carbon::now('Asia/Jakarta')->toDateString(),
                'market_regime' => 'UNVERIFIED_FEED_ACTIVITY',
                'features_snapshot' => $evidenceData,
                'related_news_snapshot' => null,
                'data_hash' => $dataHash,
            ]);

            $outbox = TelegramOutbox::create([
                'dedup_key' => self::DEDUP_KEY,
                'chat_id' => (string) config('services.telegram.chat_id'),
                'message_type' => 'SYSTEM_ALERT',
                'payload_text' => $payload,
                'status' => 'QUEUED',
                'retry_count' => 0,
                'trigger_source' => 'SHADOW_CANARY_ONCE',
                'metadata' => [
                    'canary_type' => self::CANARY_TYPE,
                    'evidence_id' => $evidence->id,
                    'run_id' => $runId,
                    'connection_id' => $connectionId,
                    'shadow_publication' => 'DISABLED',
                    'global_one_shot' => true,
                    'retry_policy' => 'NO_SECOND_CANARY',
                ],
            ]);

            return ['evidence' => $evidence, 'outbox' => $outbox];
        });

        if (isset($result['existing'])) {
            $existing = $result['existing'];
            $this->line(json_encode(['status' => 'ALREADY_USED', 'dedup_key' => self::DEDUP_KEY, 'outbox_id' => $existing->id], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $outbox = $result['outbox'];
        $formatter->processOutboxQueue(mockOnly: false, onlyOutboxId: $outbox->id);
        $outbox->refresh();

        $messageId = (string) ($outbox->telegram_message_id ?? '');
        $sent = $outbox->status === 'SENT' && ctype_digit($messageId);
        $this->line(json_encode([
            'status' => $sent ? 'VERIFIED' : 'NOT_VERIFIED',
            'evidence_id' => $result['evidence']->id,
            'outbox_id' => $outbox->id,
            'telegram_message_id' => $outbox->telegram_message_id,
            'outbox_status' => $outbox->status,
            'dedup_key' => self::DEDUP_KEY,
            'run_id' => $runId,
            'publication' => 'ONE_DESCRIPTIVE_CANARY',
            'shadow_publication' => 'DISABLED',
            'retry_allowed' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $sent ? self::SUCCESS : self::FAILURE;
    }
}
