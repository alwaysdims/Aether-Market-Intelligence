<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketSnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'indicators' => 'array',
        'source_timestamp' => 'datetime',
        'bar_date' => 'date',
    ];
}
