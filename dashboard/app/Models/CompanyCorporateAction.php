<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyCorporateAction extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'execution_date' => 'date',
        'amount_or_ratio' => 'double',
        'details' => 'array',
    ];
}
