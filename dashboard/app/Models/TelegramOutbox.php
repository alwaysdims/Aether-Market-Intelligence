<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramOutbox extends Model
{
    protected $table = 'telegram_outbox';

    protected $guarded = ['id'];

    protected $casts = [
        'sent_at' => 'datetime',
        'retry_after' => 'datetime',
        'retry_count' => 'integer',
        'metadata' => 'array',
    ];

    public function predictionSignal(): BelongsTo
    {
        return $this->belongsTo(PredictionSignal::class, 'prediction_id', 'prediction_id');
    }
}
