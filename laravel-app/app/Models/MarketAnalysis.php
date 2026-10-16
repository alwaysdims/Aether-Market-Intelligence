<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketAnalysis extends Model
{
    protected $guarded = [];

    protected $casts = [
        'structured_output' => 'array',
        'confidence_score' => 'float',
    ];

    public function analysisRun()
    {
        return $this->belongsTo(AnalysisRun::class);
    }

    public function postHorizonEvaluations()
    {
        return $this->hasMany(PostHorizonEvaluation::class);
    }
}
