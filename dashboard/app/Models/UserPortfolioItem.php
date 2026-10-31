<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPortfolioItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'lots' => 'integer',
        'shares' => 'integer',
        'avg_buy_price' => 'float',
    ];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(UserPortfolio::class, 'portfolio_id');
    }
}
