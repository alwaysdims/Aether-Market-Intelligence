<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalysisRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function marketAnalyses()
    {
        return $this->hasMany(MarketAnalysis::class);
    }
}
