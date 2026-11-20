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
        $forward = PredictionSignal::operational();
        $forwardTotal = (clone $forward)->count();
        $publishedDirectional = (clone $forward)->where('signal_status', 'PUBLISHED_DIRECTIONAL')->count();
        $screeningCandidates = (clone $forward)->where('signal_status', 'CANDIDATE')->count();
        $abstained = (clone $forward)->where('signal_status', 'NO_SIGNAL_ABSTAIN')->count();
        $coveragePct = ($forwardTotal > 0) ? round((($publishedDirectional + $screeningCandidates) / $forwardTotal) * 100, 2).'%' : 'N/A';

        // Disaggregate by Strategy / Origin Origin
        $mlSignalsCount = (clone $forward)->where(function ($q) {
            $q->where('model_name', 'RandomForest')->orWhere('model_name', 'EXP-04');
        })->count();
        $ruleScreeningCount = (clone $forward)->where('model_name', 'like', '%SCREENING%')->count();
        $agentConsultationCount = (clone $forward)->where(function ($q) {
            $q->where('model_name', 'like', '%HERMES%')->orWhere('model_name', 'like', '%GEMINI%');
        })->count();

        // Monitoring status breakdown
        $activeCount = (clone $forward)->whereIn('monitoring_status', ['ACTIVE', 'MONITORING'])->count();
        $targetHitCount = (clone $forward)->where('monitoring_status', 'TARGET_HIT')->count();
        $invalidatedCount = (clone $forward)->where('monitoring_status', 'INVALIDATED')->count();
        $ambiguousCount = (clone $forward)->where('monitoring_status', 'AMBIGUOUS')->count();
        $expiredCount = (clone $forward)->where('monitoring_status', 'EXPIRED')->count();

        // 5-Trading-Day Horizon evaluation breakdown
        $evalPending = (clone $forward)->where('evaluation_status', 'PENDING')->count();
        $evalCorrect = (clone $forward)->where('evaluation_status', 'EVALUATED_CORRECT')->count();
        $evalIncorrect = (clone $forward)->where('evaluation_status', 'EVALUATED_INCORRECT')->count();
        $evalNeutral = (clone $forward)->where('evaluation_status', 'EVALUATED_NEUTRAL')->count();
        $evalMissing = (clone $forward)->where('evaluation_status', 'CANNOT_EVALUATE_MISSING_DATA')->count();

        // Provisional breakdown (separated from final)
        $evalProvisionalCorrect = (clone $forward)->where('evaluation_status', 'EVALUATED_PROVISIONAL_CORRECT')->count();
        $evalProvisionalIncorrect = (clone $forward)->where('evaluation_status', 'EVALUATED_PROVISIONAL_INCORRECT')->count();
        $evalProvisionalNeutral = (clone $forward)->where('evaluation_status', 'EVALUATED_PROVISIONAL_NEUTRAL')->count();
        $evalProvisionalTotal = $evalProvisionalCorrect + $evalProvisionalIncorrect + $evalProvisionalNeutral;

        $evalDenominator = $evalCorrect + $evalIncorrect;
        $directionalAccuracy = ($evalDenominator > 0) ? round(($evalCorrect / $evalDenominator) * 100, 2).'%' : 'N/A (Belum Ada Jatuh Tempo)';

        // Target touch rate (separate from directional accuracy)
        $directionalTotal = $publishedDirectional + $screeningCandidates;
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
            'screening_candidates' => $screeningCandidates,
            'no_signal_abstained' => $abstained,
            'coverage_pct' => $coveragePct,

            // Cohort Breakdown by Origin
            'ml_signals_count' => $mlSignalsCount,
            'rule_screening_count' => $ruleScreeningCount,
            'agent_consultation_count' => $agentConsultationCount,

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
            'eval_provisional_total' => $evalProvisionalTotal,
            'eval_provisional_correct' => $evalProvisionalCorrect,
            'eval_provisional_incorrect' => $evalProvisionalIncorrect,
            'eval_provisional_neutral' => $evalProvisionalNeutral,
            'eval_missing_data' => $evalMissing,
            'directional_accuracy' => $directionalAccuracy,
            'eval_denominator' => $evalDenominator,

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
