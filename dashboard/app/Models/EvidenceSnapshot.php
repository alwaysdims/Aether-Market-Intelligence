<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvidenceSnapshot extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'decision_date' => 'date',
        'features_snapshot' => 'array',
        'related_news_snapshot' => 'array',
    ];

    public function marketSnapshot(): BelongsTo
    {
        return $this->belongsTo(MarketSnapshot::class);
    }

    public function predictionSignals(): HasMany
    {
        return $this->hasMany(PredictionSignal::class);
    }
}
