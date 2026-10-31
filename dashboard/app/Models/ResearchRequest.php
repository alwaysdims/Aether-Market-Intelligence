<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchRequest extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'as_of_time' => 'datetime',
        'research_metadata' => 'array',
    ];

    public function evidenceSnapshot(): BelongsTo
    {
        return $this->belongsTo(EvidenceSnapshot::class);
    }

    public function marketAnalysis(): BelongsTo
    {
        return $this->belongsTo(MarketAnalysis::class);
    }

    public function outbox(): BelongsTo
    {
        return $this->belongsTo(TelegramOutbox::class, 'outbox_id');
    }
}
