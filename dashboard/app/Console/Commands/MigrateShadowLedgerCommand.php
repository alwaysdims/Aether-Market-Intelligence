<?php

namespace App\Console\Commands;

use App\Models\EvidenceSnapshot;
use App\Models\PredictionSignal;
use App\Services\BeiCalendarService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateShadowLedgerCommand extends Command
{
    protected $signature = 'hermes:migrate-shadow-ledger {--dry-run : Simulate migration without writing to database}';

    protected $description = 'Idempotently migrate shadow_predictions_ledger.json into PostgreSQL prediction_signals table';

    public function handle(BeiCalendarService $calendar): int
    {
        $this->info('=== IDEMPOTENT SHADOW LEDGER MIGRATION ===');
        $isDryRun = $this->option('dry-run');

        $ledgerPath = base_path('../data/shadow_predictions/shadow_predictions_ledger.json');
        if (! file_exists($ledgerPath)) {
            $this->error("Ledger file not found at: {$ledgerPath}");

            return 1;
        }

        $rawContent = file_get_contents($ledgerPath);
        $records = json_decode($rawContent, true);
        if (! is_array($records)) {
            $this->error('Invalid JSON content in shadow ledger.');

            return 1;
        }

        $this->info('Found '.count($records).' records in JSON ledger.');

        $migratedCount = 0;
        $skippedCount = 0;

        foreach ($records as $item) {
            $predId = $item['prediction_id'] ?? null;
            if (! $predId) {
                continue;
            }

            // Check if already migrated
            if (PredictionSignal::where('prediction_id', $predId)->exists()) {
                $skippedCount++;

                continue;
            }

            $decisionDate = Carbon::parse($item['decision_date'])->startOfDay();
            $decisionTs = Carbon::parse($item['decision_timestamp']);
            $publishedAt = $decisionTs->copy()->addMinutes(5); // 16:05 WIB
            $expiresAt = $publishedAt->copy()->addDays(14); // Exactly 14 calendar days

            // Correct 5 trading days due date using BeiCalendarService
            $evaluationDueDate = $calendar->addTradingDays($decisionDate, 5);

            $pUp = (float) ($item['probability_up'] ?? 0.5);
            $pDown = (float) ($item['probability_down'] ?? (1.0 - $pUp));
            $maxP = max($pUp, $pDown);
            $conf = (float) ($item['confidence'] ?? (abs($pUp - 0.5) * 2));

            $signalStatus = $item['signal_status'] ?? 'NO_SIGNAL';
            $direction = $item['direction'] ?? 'ABSTAIN';

            // Map string representations to standard enum keys
            $dbSignalStatus = ($signalStatus === 'NO_SIGNAL') ? 'NO_SIGNAL_ABSTAIN' : 'PUBLISHED_DIRECTIONAL';
            $dbDirection = in_array(strtoupper($direction), ['UP', 'DOWN', 'ABSTAIN'], true) ? strtoupper($direction) : 'ABSTAIN';
            $dbMonitoringStatus = ($dbDirection === 'ABSTAIN') ? 'NOT_MONITORED' : 'ACTIVE';
            $dbEvaluationStatus = 'PENDING';

            $snapshotId = "EVD_{$decisionDate->format('Ymd')}_{$item['ticker']}_".substr($item['feature_schema_hash'] ?? 'default', 0, 8);

            if (! $isDryRun) {
                DB::transaction(function () use (
                    $item, $predId, $snapshotId, $decisionDate, $decisionTs,
                    $publishedAt, $expiresAt, $evaluationDueDate, $pUp, $pDown, $maxP, $conf,
                    $dbSignalStatus, $dbDirection, $dbMonitoringStatus, $dbEvaluationStatus
                ) {
                    // 1. Evidence Snapshot
                    $snapshot = EvidenceSnapshot::firstOrCreate(
                        ['snapshot_id' => $snapshotId],
                        [
                            'ticker' => $item['ticker'],
                            'decision_date' => $decisionDate->format('Y-m-d'),
                            'market_regime' => $item['market_regime'] ?? 'SIDEWAYS',
                            'features_snapshot' => [
                                'reference_close' => $item['reference_close'] ?? null,
                                'feature_schema_hash' => $item['feature_schema_hash'] ?? null,
                                'source' => 'shadow_predictions_ledger_v1',
                            ],
                            'related_news_snapshot' => [],
                            'data_hash' => hash('sha256', json_encode($item)),
                        ]
                    );

                    // 2. Prediction Signal
                    PredictionSignal::create([
                        'prediction_id' => $predId,
                        'ticker' => $item['ticker'],
                        'signal_status' => $dbSignalStatus,
                        'direction' => $dbDirection,
                        'probability_up' => $pUp,
                        'probability_down' => $pDown,
                        'max_probability' => $maxP,
                        'confidence_score' => $conf,
                        'threshold_applied' => 0.7000,
                        'model_name' => 'RandomForest',
                        'model_version' => $item['model_version'] ?? 'v1.0',
                        'feature_schema_hash' => $item['feature_schema_hash'] ?? '307744cc7087',
                        'reason_for_abstain' => $item['reason_for_abstain'] ?? 'CONFIDENCE_BELOW_THRESHOLD',
                        'decision_date' => $decisionDate->format('Y-m-d'),
                        'decision_timestamp' => $decisionTs,
                        'reference_price' => (float) ($item['reference_close'] ?? 0),
                        'reference_price_date' => $decisionDate->format('Y-m-d'),
                        'predicted_horizon_days' => 5,
                        'evaluation_due_date' => $evaluationDueDate->format('Y-m-d'),
                        'published_at' => $publishedAt,
                        'expires_at' => $expiresAt,
                        'monitoring_status' => $dbMonitoringStatus,
                        'evaluation_status' => $dbEvaluationStatus,
                        'evidence_snapshot_id' => $snapshot->id,
                        'metadata' => [
                            'original_ledger_id' => $predId,
                            'migrated_from' => 'shadow_predictions_ledger.json',
                            'migrated_at' => now()->toISOString(),
                        ],
                    ]);
                });
            }

            $migratedCount++;
        }

        $this->info('Migration completed. '.($isDryRun ? '[DRY-RUN] Would migrate: ' : 'Migrated: ')."{$migratedCount}, Skipped existing: {$skippedCount}");

        return 0;
    }
}
