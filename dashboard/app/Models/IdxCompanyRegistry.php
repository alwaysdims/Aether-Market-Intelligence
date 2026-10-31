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
