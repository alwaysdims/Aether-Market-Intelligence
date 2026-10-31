<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyFundamental extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'period_end_date' => 'date',
        'filing_date' => 'date',
        'is_audited' => 'boolean',
        'is_restated' => 'boolean',
        'total_revenue' => 'double',
        'gross_profit' => 'double',
        'operating_income' => 'double',
        'net_income' => 'double',
        'total_assets' => 'double',
        'total_liabilities' => 'double',
        'total_equity' => 'double',
        'total_debt' => 'double',
        'operating_cash_flow' => 'double',
        'capital_expenditure' => 'double',
        'free_cash_flow' => 'double',
        'shares_outstanding' => 'integer',
        'attribution_parent' => 'double',
        'attribution_non_controlling' => 'double',
        'eps_basic' => 'double',
        'ebt' => 'double',
        'tax_expense' => 'double',
        'raw_payload' => 'array',
    ];
}
