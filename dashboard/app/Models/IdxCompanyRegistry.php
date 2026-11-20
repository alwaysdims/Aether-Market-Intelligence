<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IdxCompanyRegistry extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'listing_date' => 'date',
        'snapshot_date' => 'date',
        'is_validated_ml' => 'boolean',
        'shares_outstanding' => 'integer',
        'first_bar_date' => 'date',
        'last_bar_date' => 'date',
        'last_success_at' => 'datetime',
        'adjusted_bars_count' => 'integer',
        'raw_bars_count' => 'integer',
        'backfill_metadata' => 'array',
        'eod_retry_attempts' => 'integer',
        'eod_target_session' => 'date',
        'eod_next_retry_at' => 'datetime',
        'eod_last_attempt_at' => 'datetime',
    ];

    public function fundamentals(): HasMany
    {
        return $this->hasMany(CompanyFundamental::class, 'ticker', 'ticker');
    }

    public function corporateActions(): HasMany
    {
        return $this->hasMany(CompanyCorporateAction::class, 'ticker', 'ticker');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(CompanyAnalysisMetric::class, 'ticker', 'ticker');
    }
}
