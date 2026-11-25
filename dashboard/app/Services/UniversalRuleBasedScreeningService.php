<?php

namespace App\Services;

use App\Models\EvidenceSnapshot;
use App\Models\IdxCompanyRegistry;
use App\Models\MonitoringEvent;
use App\Models\PredictionSignal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UniversalRuleBasedScreeningService
{
    public function __construct(
        protected IdxEdgeClient $client,
        protected TelegramFormattingService $formatter,
    ) {}

    /**
     * Screen active IDX equities from one batch quote response.
     * Current provider contract exposes latest price/activity, not full OHLC features.
     * Therefore output stays rule-based research-only.
     *
     * @return array<string, mixed>
     */
    public function run(Carbon $nowWib, bool $live = false): array
    {
        $runtime = DB::selectOne('SELECT current_database() AS database, current_user AS user_name, current_schema() AS schema_name');
        if (! $runtime
            || $runtime->database !== 'project_bot_recovered_20261115_022935'
            || $runtime->user_name !== 'aether_ingestion'
            || $runtime->schema_name !== 'public') {
            throw new RuntimeException('SCREENING_BLOCKED:RUNTIME_IDENTITY_MISMATCH');
        }

        $tickers = IdxCompanyRegistry::query()
            ->where('instrument_type', 'EQUITY')
            ->where(function ($query): void {
                $query->whereNull('listing_status')->orWhere('listing_status', 'ACTIVE');
            })
            ->whereNotIn('ticker', ['^JKSE', 'JKSE', 'IHSG'])
            ->pluck('ticker')
            ->map(static fn (mixed $ticker): string => strtoupper(trim((string) $ticker)))
            ->filter(static fn (string $ticker): bool => preg_match('/^[A-Z]{4}$/', $ticker) === 1)
            ->unique()->values()->all();

        if ($tickers === []) {
            throw new RuntimeException('SCREENING_BLOCKED:EMPTY_EQUITY_REGISTRY');
        }

        $response = $this->client->priceList($tickers);
        if (! ($response['success'] ?? false)) {
            throw new RuntimeException('SCREENING_BLOCKED:PROVIDER_'.($response['error_type'] ?? 'FAILURE'));
        }

        $stocks = $response['data']['stocks'] ?? [];
        $candidates = [];
        foreach ($stocks as $ticker => $quote) {
            $price = (float) ($quote['last_price'] ?? 0);
            $changePct = $quote['change_pct'] !== null ? (float) $quote['change_pct'] : null;
            $value = (float) ($quote['value'] ?? 0);
            $frequency = (int) ($quote['freq'] ?? 0);
            if ($price <= 0 || $changePct === null || $value <= 0) {
                continue;
            }
            if ($changePct < 1.0 || $changePct > 10.0 || $value < 100_000_000 || $frequency < 1) {
                continue;
            }
            $candidates[] = [
                'ticker' => strtoupper((string) $ticker),
                'price' => $price,
                'change_pct' => round($changePct, 2),
                'value_idr' => $value,
                'frequency' => $frequency,
                'source_date' => $quote['source_date'] ?? null,
                'data_ts' => $quote['data_ts'] ?? null,
                'rule_status' => 'SETUP_FOUND_RESEARCH_ONLY',
                'rule_basis' => 'BATCH_QUOTE_MOMENTUM_AND_ACTIVITY',
                'risk_note' => 'Belum ada verifikasi OHLC intraday, order book, board scope, atau fill.',
            ];
        }
        usort($candidates, static fn (array $a, array $b): int => $b['value_idr'] <=> $a['value_idr']);
        $candidates = array_slice($candidates, 0, 10);

        $runDate = $nowWib->format('Y-m-d');
        $dedupKey = 'UNIVERSAL_RULE_SCREEN_'.$runDate;
        $payload = [
            'run_type' => 'UNIVERSAL_RULE_BASED_SCREENING',
            'status' => 'RESEARCH_ONLY',
            'production_publication' => 'DISABLED',
            'registry_count' => count($tickers),
            'provider_count' => count($stocks),
            'candidate_count' => count($candidates),
            'provider_payload_hash' => $response['payload_sha256'] ?? null,
            'candidates' => $candidates,
            'limitations' => ['No ML probability', 'No broker order', 'No synthetic trade', 'Provider quote is not OHLC validation'],
        ];

        $evidence = EvidenceSnapshot::firstOrCreate(
            ['snapshot_id' => 'UNIVERSAL_RULE_'.$runDate],
            [
                'ticker' => 'MARKET',
                'decision_date' => $runDate,
                'market_regime' => 'RULE_BASED_RESEARCH_ONLY',
                'features_snapshot' => $payload,
                'related_news_snapshot' => null,
                'data_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            ]
        );

        $message = $this->renderTelegramPlan($nowWib, $payload);
        $registeredSignals = [];
        foreach ($candidates as $candidate) {
            $predictionId = 'UNIVERSAL_'.$candidate['ticker'].'_'.$runDate;
            $signal = PredictionSignal::firstOrCreate(
                ['prediction_id' => $predictionId],
                [
                    'ticker' => $candidate['ticker'],
                    'signal_status' => 'PUBLISHED_CONDITIONAL',
                    'direction' => 'UP',
                    'probability_up' => 0.0,
                    'probability_down' => 0.0,
                    'max_probability' => 0.0,
                    'confidence_score' => 0.0,
                    'threshold_applied' => 0.0,
                    'feature_schema_hash' => substr(hash('sha256', 'UNIVERSAL_BATCH_QUOTE_V1'), 0, 32),
                    'model_name' => 'RULE_BASED_SCREENING',
                    'model_version' => 'UNIVERSAL_BATCH_QUOTE_V1',
                    'decision_date' => $runDate,
                    'decision_timestamp' => $nowWib->copy()->utc(),
                    'reference_price' => $candidate['price'],
                    'reference_price_date' => $candidate['source_date'] ?: $runDate,
                    'predicted_horizon_days' => 5,
                    'evaluation_due_date' => $nowWib->copy()->addWeekdays(5)->toDateString(),
                    'published_at' => $nowWib->copy()->utc(),
                    'expires_at' => $nowWib->copy()->addWeekdays(5)->setTime(15, 50)->utc(),
                    'monitoring_status' => 'WAITING_FOR_TRIGGER',
                    'evaluation_status' => 'PENDING',
                    'evidence_snapshot_id' => $evidence->id,
                    'metadata' => [
                        'origin' => 'UNIVERSAL_RULE_BASED_SCREENING',
                        'research_only' => true,
                        'delivery_status' => 'DECISION_CREATED',
                        'rule_status' => $candidate['rule_status'],
                        'rule_basis' => $candidate['rule_basis'],
                        'provider_data_ts' => $candidate['data_ts'],
                    ],
                ]
            );
            MonitoringEvent::firstOrCreate(
                ['prediction_id' => $predictionId, 'event_type' => 'PLAN_RECORDED'],
                [
                    'event_time' => $nowWib,
                    'first_observed_at' => $nowWib,
                    'price_observed' => $candidate['price'],
                    'description' => 'Universal rule-based research plan masuk monitoring dashboard.',
                    'metadata' => ['evidence_id' => $evidence->id, 'research_only' => true],
                ]
            );
            $registeredSignals[] = $signal;
        }
        $outbox = null;
        if ($live) {
            $outbox = $this->formatter->enqueueOutbox(
                dedupKey: $dedupKey,
                messageType: 'SYSTEM_ALERT',
                payload: $message,
                predictionId: $registeredSignals[0]?->prediction_id,
                chatId: (string) config('services.telegram.chat_id'),
                triggerSource: 'UNIVERSAL_RULE_BASED_SCREENING',
                metadata: ['evidence_id' => $evidence->id, ...$payload]
            );
            foreach ($registeredSignals as $signal) {
                $signal->update(['metadata' => array_merge($signal->metadata ?? [], [
                    'delivery_status' => 'QUEUED',
                    'outbox_id' => $outbox->id,
                ])]);
            }
        }

        return [
            'status' => 'RESEARCH_ONLY',
            'writes_executed' => true,
            'live_delivery_requested' => $live,
            'registry_count' => count($tickers),
            'provider_count' => count($stocks),
            'candidate_count' => count($candidates),
            'evidence_id' => $evidence->id,
            'outbox_id' => $outbox?->id,
            'dedup_key' => $dedupKey,
            'message' => $message,
            'candidates' => $candidates,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function renderTelegramPlan(Carbon $nowWib, array $payload): string
    {
        $lines = [
            '📢 AETHER — SCREENING SAHAM BEI',
            '──────────────────────────────────────────',
            'Mode: RESEARCH_ONLY / RULE_BASED',
            'Waktu: '.$nowWib->format('d M Y, H:i').' WIB',
            'Universe Registry: '.($payload['registry_count'] ?? 0).' emiten',
            'Quote IDX Edge diterima: '.($payload['provider_count'] ?? 0),
            'Kandidat: '.($payload['candidate_count'] ?? 0),
            '',
            'KANDIDAT TRADING PLAN BERSYARAT:',
        ];
        foreach ($payload['candidates'] as $candidate) {
            $lines[] = sprintf(
                '• %s | Rp %s | +%s%% | nilai Rp %s',
                $candidate['ticker'],
                number_format($candidate['price'], 0, ',', '.'),
                number_format($candidate['change_pct'], 2, ',', '.'),
                number_format($candidate['value_idr'], 0, ',', '.')
            );
            $lines[] = '  Status: SETUP_FOUND_RESEARCH_ONLY | Rule: momentum + aktivitas';
        }
        if (($payload['candidate_count'] ?? 0) === 0) {
            $lines[] = '• NO_SETUP_FOUND pada quote yang lolos rule.';
        }
        $lines[] = '';
        $lines[] = 'Bukan sinyal ML, bukan order broker, bukan jaminan profit.';
        $lines[] = 'Harga live, board scope, OHLC, dan fill tetap NOT_VERIFIED.';

        return implode("\n", $lines);
    }
}
