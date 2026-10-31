<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserPortfolio extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'cash_balance' => 'float',
        'target_trading_pct' => 'float',
        'target_investment_pct' => 'float',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(UserPortfolioItem::class, 'portfolio_id');
    }
}
