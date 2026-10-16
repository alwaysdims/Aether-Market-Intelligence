<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostHorizonEvaluation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'decision_date' => 'date',
        'decision_timestamp' => 'datetime',
        'reference_price' => 'decimal:2',
        'reference_price_date' => 'date',
        'target_eval_date' => 'date',
        'predicted_confidence' => 'float',
        'actual_price' => 'decimal:2',
        'actual_price_date' => 'date',
        'actual_return_pct' => 'float',
        'is_fallback' => 'boolean',
        'evaluated_at' => 'datetime',
    ];

    public function marketAnalysis()
    {
        return $this->belongsTo(MarketAnalysis::class);
    }

    public function analysisRun()
    {
        return $this->belongsTo(AnalysisRun::class);
    }
}
