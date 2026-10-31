<?php

namespace App\Services;

use App\Models\PostHorizonEvaluation;
use App\Models\PredictionSignal;
use App\Models\TelegramOutbox;

class PredictionRecapService
{
    /**
     * Generate comprehensive recap metrics strictly separating forward cohort from historical fallbacks.
     */
    public function generateRecapMetrics(): array
    {
        // 1. Forward Prediction Cohort (prediction_signals table)
        $forwardTotal = PredictionSignal::count();
        $publishedDirectional = PredictionSignal::where('signal_status', 'PUBLISHED_DIRECTIONAL')->count();
        $abstained = PredictionSignal::where('signal_status', 'NO_SIGNAL_ABSTAIN')->count();
        $coveragePct = ($forwardTotal > 0) ? round(($publishedDirectional / $forwardTotal) * 100, 2).'%' : 'N/A';

        // Monitoring status breakdown
        $activeCount = PredictionSignal::where('monitoring_status', 'ACTIVE')->count();
        $targetHitCount = PredictionSignal::where('monitoring_status', 'TARGET_HIT')->count();
        $invalidatedCount = PredictionSignal::where('monitoring_status', 'INVALIDATED')->count();
        $ambiguousCount = PredictionSignal::where('monitoring_status', 'AMBIGUOUS')->count();
        $expiredCount = PredictionSignal::where('monitoring_status', 'EXPIRED')->count();

        // 5-Trading-Day Horizon evaluation breakdown
        $evalPending = PredictionSignal::where('evaluation_status', 'PENDING')->count();
        $evalCorrect = PredictionSignal::where('evaluation_status', 'EVALUATED_CORRECT')->count();
        $evalIncorrect = PredictionSignal::where('evaluation_status', 'EVALUATED_INCORRECT')->count();
        $evalNeutral = PredictionSignal::where('evaluation_status', 'EVALUATED_NEUTRAL')->count();
        $evalMissing = PredictionSignal::where('evaluation_status', 'CANNOT_EVALUATE_MISSING_DATA')->count();

        $evalDenominator = $evalCorrect + $evalIncorrect;
        $directionalAccuracy = ($evalDenominator > 0) ? round(($evalCorrect / $evalDenominator) * 100, 2).'%' : 'N/A';

        // Target touch rate (separate from directional accuracy)
        $directionalTotal = $publishedDirectional;
        $targetTouchRate = ($directionalTotal > 0) ? round(($targetHitCount / $directionalTotal) * 100, 2).'%' : 'N/A';

        // 2. Telegram Delivery Status
        $tgQueued = TelegramOutbox::where('status', 'QUEUED')->count();
        $tgSent = TelegramOutbox::where('status', 'SENT')->count();
        $tgFailed = TelegramOutbox::where('status', 'FAILED')->count();
        $tgUnknown = TelegramOutbox::where('status', 'DELIVERY_UNKNOWN')->count();

        // 3. Historical Fallback Cohort (Legacy 30 evaluations in post_horizon_evaluations)
        $historicalFallbackTotal = PostHorizonEvaluation::where('is_fallback', true)->count();
        $historicalNeutral = PostHorizonEvaluation::where('is_fallback', true)->where('outcome', 'neutral')->count();

        return [
            // Forward Cohort
            'total_signals' => $forwardTotal,
            'published_directional' => $publishedDirectional,
            'no_signal_abstained' => $abstained,
            'coverage_pct' => $coveragePct,

            // 14-Day Monitoring
            'active' => $activeCount,
            'target_hit' => $targetHitCount,
            'invalidated' => $invalidatedCount,
            'ambiguous' => $ambiguousCount,
            'expired' => $expiredCount,
            'target_touch_rate' => $targetTouchRate,

            // 5-Trading-Day Horizon
            'eval_pending' => $evalPending,
            'eval_correct' => $evalCorrect,
            'eval_incorrect' => $evalIncorrect,
            'eval_neutral' => $evalNeutral,
            'eval_missing_data' => $evalMissing,
            'directional_accuracy' => $directionalAccuracy,

            // Delivery Status
            'telegram_queued' => $tgQueued,
            'telegram_sent' => $tgSent,
            'telegram_failed' => $tgFailed,
            'telegram_unknown' => $tgUnknown,

            // Historical Fallback (Strictly Isolated)
            'historical_fallback_evaluations' => $historicalFallbackTotal,
            'historical_fallback_neutral' => $historicalNeutral,
            'historical_fallback_note' => '30 evaluasi fallback historis pra-Tahap I dipisahkan secara fisik dan analitis dari kohort forward baru.',
        ];
    }
}
