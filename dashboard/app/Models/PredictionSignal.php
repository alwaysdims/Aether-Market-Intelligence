<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PredictionSignal extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'probability_up' => 'float',
        'probability_down' => 'float',
        'max_probability' => 'float',
        'confidence_score' => 'float',
        'threshold_applied' => 'float',
        'decision_date' => 'date',
        'decision_timestamp' => 'datetime',
        'reference_price' => 'float',
        'reference_price_date' => 'date',
        'evaluation_due_date' => 'date',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'target_price' => 'float',
        'invalidation_price' => 'float',
        'technical_context' => 'array',
        'metadata' => 'array',
    ];

    public function scopeOperational(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $q) {
                $q->whereNull('metadata->operational_eligibility')
                    ->orWhere('metadata->operational_eligibility', 'OPERATIONAL');
            })
            ->where(function (Builder $q) {
                $q->whereNull('metadata->delivery_status')
                    ->orWhere('metadata->delivery_status', '!=', 'DRY_RUN_ARCHIVE');
            });
    }

    public function scopeDeliveredToUser(Builder $query): Builder
    {
        return $query->where('metadata->delivery_status', 'SENT')
            ->whereNotNull('telegram_message_id');
    }

    public function scopeMonitoringEligible(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where(function (Builder $delivered) {
                $delivered->where('metadata->delivery_status', 'SENT')
                    ->whereNotNull('telegram_message_id');
            })->orWhere('metadata->monitoring_cohort', 'SHADOW_TEST');
        })->where(function (Builder $q) {
            $q->whereNull('metadata->operational_eligibility')
                ->orWhere('metadata->operational_eligibility', '!=', 'NON_OPERATIONAL_ARCHIVE');
        });
    }

    public function evidenceSnapshot(): BelongsTo
    {
        return $this->belongsTo(EvidenceSnapshot::class);
    }

    public function analysisRun(): BelongsTo
    {
        return $this->belongsTo(AnalysisRun::class);
    }

    public function monitoringEvents(): HasMany
    {
        return $this->hasMany(MonitoringEvent::class, 'prediction_id', 'prediction_id');
    }

    public function telegramOutboxItems(): HasMany
    {
        return $this->hasMany(TelegramOutbox::class, 'prediction_id', 'prediction_id');
    }
}
