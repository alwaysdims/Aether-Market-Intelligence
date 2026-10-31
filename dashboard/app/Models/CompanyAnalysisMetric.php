<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyAnalysisMetric extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'as_of_date' => 'date',
        'close_price' => 'double',
        'return_1d_pct' => 'double',
        'return_5d_pct' => 'double',
        'return_20d_pct' => 'double',
        'sma_20' => 'double',
        'sma_50' => 'double',
        'sma_200' => 'double',
        'rsi_14' => 'double',
        'macd' => 'double',
        'macd_signal' => 'double',
        'macd_hist' => 'double',
        'atr_14' => 'double',
        'atr_14_pct' => 'double',
        'rvol_20' => 'double',
        'max_drawdown_1y_pct' => 'double',
        'support_price' => 'double',
        'resistance_price' => 'double',
        'pe_ratio' => 'double',
        'pbv_ratio' => 'double',
        'roe_pct' => 'double',
        'roa_pct' => 'double',
        'der_ratio' => 'double',
        'net_profit_margin_pct' => 'double',
        'operating_margin_pct' => 'double',
        'revenue_growth_yoy_pct' => 'double',
        'net_income_growth_yoy_pct' => 'double',
        'dividend_yield_pct' => 'double',
        'eps' => 'double',
        'bvps' => 'double',
        'data_quality_flags' => 'array',
    ];
}
