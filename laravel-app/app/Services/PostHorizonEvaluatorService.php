<?php

namespace App\Services;

use App\Models\MarketAnalysis;
use App\Models\MarketSnapshot;
use App\Models\PostHorizonEvaluation;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

class PostHorizonEvaluatorService
{
    protected BeiCalendarService $calendar;

    public function __construct(BeiCalendarService $calendar)
    {
        $this->calendar = $calendar;
    }

    /**
     * Register predictions from MarketAnalysis into PostHorizonEvaluation.
     * Idempotent: will not duplicate evaluation rows for the same analysis, ticker, and horizon.
     */
    public function registerPredictions(MarketAnalysis $analysis, int $horizonDays = 5): array
    {
        $created = [];
        $analysisRun = $analysis->analysisRun;
        $decisionDate = $analysisRun ? $analysisRun->started_at->toDateString() : $analysis->created_at->toDateString();
        $decisionTimestamp = $analysisRun ? $analysisRun->started_at : $analysis->created_at;

        // Extract targeted tickers from structured output or fallback to available snapshots
        $structured = $analysis->structured_output ?? [];
        $impactedTickers = $structured['impacted_tickers'] ?? [];
        $tickersToEvaluate = [];

        if (!empty($impactedTickers) && is_array($impactedTickers)) {
            foreach ($impactedTickers as $it) {
                if (is_array($it) && !empty($it['ticker'])) {
                    $tickersToEvaluate[$it['ticker']] = $it['direction'] ?? $analysis->impact_direction;
                }
            }
        }

        // If no explicit tickers listed, check snapshots recorded in the same run
        if (empty($tickersToEvaluate)) {
            $snapshots = MarketSnapshot::where('analysis_run_id', $analysis->analysis_run_id)->get();
            if ($snapshots->isEmpty()) {
                // Fallback to latest snapshots
                $latestDate = MarketSnapshot::max('bar_date');
                if ($latestDate) {
                    $snapshots = MarketSnapshot::where('bar_date', $latestDate)->get();
                }
            }

            foreach ($snapshots as $snap) {
                $tickersToEvaluate[$snap->ticker] = $analysis->impact_direction;
            }
        }

        foreach ($tickersToEvaluate as $ticker => $predictedDir) {
            // Find reference price on or immediately before decision date
            $refSnap = MarketSnapshot::where('ticker', $ticker)
                ->where('bar_date', '<=', $decisionDate)
                ->orderByDesc('bar_date')
                ->first();

            if (!$refSnap) {
                // If no past snapshot, find closest available snapshot
                $refSnap = MarketSnapshot::where('ticker', $ticker)->orderBy('bar_date')->first();
            }

            if (!$refSnap) {
                continue;
            }

            $refBarDate = Carbon::parse($refSnap->bar_date);
            $targetEvalDate = $this->calendar->addTradingDays($refBarDate, $horizonDays);

            $eval = PostHorizonEvaluation::updateOrCreate(
                [
                    'market_analysis_id' => $analysis->id,
                    'ticker' => $ticker,
                    'horizon_trading_days' => $horizonDays,
                ],
                [
                    'analysis_run_id' => $analysis->analysis_run_id,
                    'decision_date' => $refBarDate->toDateString(),
                    'decision_timestamp' => $decisionTimestamp,
                    'reference_price' => $refSnap->close_price,
                    'reference_price_date' => $refBarDate->toDateString(),
                    'target_eval_date' => $targetEvalDate->toDateString(),
                    'predicted_direction' => $predictedDir ?: 'unclear',
                    'predicted_confidence' => $analysis->confidence_score,
                    'model_name' => $analysis->model_name,
                    'prompt_version' => $analysis->prompt_version,
                    'is_fallback' => ($analysis->validation_status === 'fallback'),
                    'validation_status' => $analysis->validation_status ?? 'valid_schema',
                    'status' => 'pending',
                ]
            );

            $created[] = $eval;
        }

        return $created;
    }

    /**
     * Evaluate all pending predictions whose target_eval_date has been reached.
     * Strictly avoids evaluating predictions that have not reached their 5-day horizon.
     */
    public function evaluatePending(?CarbonInterface $asOf = null, bool $force = false): array
    {
        $asOfDateStr = ($asOf ?? Carbon::now('Asia/Jakarta'))->copy()->timezone('Asia/Jakarta')->format('Y-m-d');
        $query = PostHorizonEvaluation::query();

        if (!$force) {
            $query->where('status', 'pending');
        }

        $pendingEvaluations = $query->get();
        $evaluatedCount = 0;
        $skippedCount = 0;
        $missingDataCount = 0;

        foreach ($pendingEvaluations as $eval) {
            $targetDateStr = Carbon::parse($eval->target_eval_date)->format('Y-m-d');

            // Strict anti-lookahead check: do not evaluate before target date
            if ($asOfDateStr < $targetDateStr && !$force) {
                $skippedCount++;
                continue;
            }

            // Find actual snapshot on or after target_eval_date
            $actualSnap = MarketSnapshot::where('ticker', $eval->ticker)
                ->where('bar_date', '>=', $targetDateStr)
                ->orderBy('bar_date')
                ->first();

            if (!$actualSnap) {
                // If target date is over 30 days past and still no data, mark as cannot_evaluate
                $targetCarbon = Carbon::parse($targetDateStr);
                $asOfCarbon = Carbon::parse($asOfDateStr);
                if ($asOfCarbon->diffInDays($targetCarbon) > 30) {
                    $eval->update([
                        'status' => 'cannot_evaluate',
                        'outcome' => 'missing_data',
                        'rubric_notes' => 'Data harga tidak tersedia setelah 30 hari kalender.',
                        'evaluated_at' => now(),
                    ]);
                    $missingDataCount++;
                } else {
                    $skippedCount++;
                }
                continue;
            }

            $refPrice = (float) $eval->reference_price;
            $actualPrice = (float) $actualSnap->close_price;

            if ($refPrice <= 0 || $actualPrice <= 0) {
                $eval->update([
                    'status' => 'cannot_evaluate',
                    'outcome' => 'missing_data',
                    'rubric_notes' => 'Harga referensi atau harga aktual tidak valid (<= 0).',
                    'evaluated_at' => now(),
                ]);
                $missingDataCount++;
                continue;
            }

            $returnPct = round((($actualPrice - $refPrice) / $refPrice) * 100.0, 4);

            // Determine actual direction threshold (+/- 0.5% buffer for flat market)
            $actualDir = 'flat';
            if ($returnPct > 0.50) {
                $actualDir = 'positive';
            } elseif ($returnPct < -0.50) {
                $actualDir = 'negative';
            }

            // Evaluate prediction rubric
            $predictedDir = strtolower($eval->predicted_direction);
            $outcome = 'neutral';
            $notes = '';

            if (in_array($predictedDir, ['unclear', 'mixed', 'neutral'], true)) {
                $outcome = 'neutral';
                $notes = "Prediksi bersifat non-directional ('{$predictedDir}'). Dinilai netral.";
            } elseif ($predictedDir === $actualDir) {
                $outcome = 'correct';
                $notes = "Prediksi '{$predictedDir}' sesuai arah aktual {$actualDir} (Return {$returnPct}%).";
            } else {
                $outcome = 'incorrect';
                $notes = "Prediksi '{$predictedDir}' bertolak belakang dengan arah aktual {$actualDir} (Return {$returnPct}%).";
            }

            $eval->update([
                'actual_price' => $actualPrice,
                'actual_price_date' => $actualSnap->bar_date,
                'actual_return_pct' => $returnPct,
                'actual_direction' => $actualDir,
                'status' => 'evaluated',
                'outcome' => $outcome,
                'rubric_notes' => $notes,
                'evaluated_at' => now(),
            ]);

            $evaluatedCount++;
        }

        return [
            'evaluated_count' => $evaluatedCount,
            'skipped_not_due' => $skippedCount,
            'missing_data_count' => $missingDataCount,
        ];
    }

    /**
     * Compute comprehensive objective performance metrics.
     */
    public function getMetricsSummary(): array
    {
        $totalRegistered = PostHorizonEvaluation::count();
        $totalPending = PostHorizonEvaluation::where('status', 'pending')->count();
        $totalCannotEvaluate = PostHorizonEvaluation::where('status', 'cannot_evaluate')->count();
        $evaluated = PostHorizonEvaluation::where('status', 'evaluated')->get();

        $totalEvaluated = $evaluated->count();
        $correctCount = $evaluated->where('outcome', 'correct')->count();
        $incorrectCount = $evaluated->where('outcome', 'incorrect')->count();
        $neutralCount = $evaluated->where('outcome', 'neutral')->count();

        $directionalCount = $correctCount + $incorrectCount;
        $accuracyPct = $directionalCount > 0 ? round(($correctCount / $directionalCount) * 100, 2) : 0.0;

        // Separate AI Genuine reasoning metrics from rule-based fallback predictions
        $aiEvaluated = $evaluated->where('is_fallback', false);
        $aiCorrect = $aiEvaluated->where('outcome', 'correct')->count();
        $aiIncorrect = $aiEvaluated->where('outcome', 'incorrect')->count();
        $aiDirectional = $aiCorrect + $aiIncorrect;
        $aiAccuracyPct = $aiDirectional > 0 ? round(($aiCorrect / $aiDirectional) * 100, 2) : 0.0;

        $fallbackEvaluated = $evaluated->where('is_fallback', true);
        $fallbackCount = PostHorizonEvaluation::where('is_fallback', true)->count();

        // Baseline: Always Positive (Buy & Hold directional bias)
        $actualPositiveCount = $evaluated->where('actual_direction', 'positive')->count();
        $baselineAlwaysPositivePct = $totalEvaluated > 0 ? round(($actualPositiveCount / $totalEvaluated) * 100, 2) : 0.0;

        // Confusion matrix for directional predictions
        $confusionMatrix = [
            'pred_pos_act_pos' => $evaluated->where('predicted_direction', 'positive')->where('actual_direction', 'positive')->count(),
            'pred_pos_act_neg' => $evaluated->where('predicted_direction', 'positive')->where('actual_direction', 'negative')->count(),
            'pred_neg_act_pos' => $evaluated->where('predicted_direction', 'negative')->where('actual_direction', 'positive')->count(),
            'pred_neg_act_neg' => $evaluated->where('predicted_direction', 'negative')->where('actual_direction', 'negative')->count(),
        ];

        // Breakdown per ticker
        $tickerBreakdown = [];
        $tickers = PostHorizonEvaluation::distinct()->pluck('ticker');
        foreach ($tickers as $t) {
            $tEvals = $evaluated->where('ticker', $t);
            $tCorrect = $tEvals->where('outcome', 'correct')->count();
            $tIncorrect = $tEvals->where('outcome', 'incorrect')->count();
            $tDir = $tCorrect + $tIncorrect;

            $tickerBreakdown[$t] = [
                'total' => PostHorizonEvaluation::where('ticker', $t)->count(),
                'evaluated' => $tEvals->count(),
                'correct' => $tCorrect,
                'incorrect' => $tIncorrect,
                'accuracy_pct' => $tDir > 0 ? round(($tCorrect / $tDir) * 100, 2) : 0.0,
            ];
        }

        return [
            'total_registered' => $totalRegistered,
            'total_pending' => $totalPending,
            'total_evaluated' => $totalEvaluated,
            'total_cannot_evaluate' => $totalCannotEvaluate,
            'correct_count' => $correctCount,
            'incorrect_count' => $incorrectCount,
            'neutral_count' => $neutralCount,
            'directional_count' => $directionalCount,
            'accuracy_pct' => $accuracyPct,
            'ai_accuracy_pct' => $aiAccuracyPct,
            'ai_evaluated_count' => $aiEvaluated->count(),
            'fallback_count' => $fallbackCount,
            'baseline_always_positive_pct' => $baselineAlwaysPositivePct,
            'confusion_matrix' => $confusionMatrix,
            'ticker_breakdown' => $tickerBreakdown,
        ];
    }
}
