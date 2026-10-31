<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'event_time' => 'datetime',
        'first_observed_at' => 'datetime',
        'price_observed' => 'float',
        'metadata' => 'array',
    ];

    public function predictionSignal(): BelongsTo
    {
        return $this->belongsTo(PredictionSignal::class, 'prediction_id', 'prediction_id');
    }
}
