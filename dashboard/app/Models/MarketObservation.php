<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketObservation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'observation_time' => 'datetime',
        'bar_date' => 'date',
        'open_price' => 'float',
        'high_price' => 'float',
        'low_price' => 'float',
        'close_price' => 'float',
        'volume' => 'integer',
        'is_eod_final' => 'boolean',
        'delay_minutes' => 'integer',
        'published_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'fetched_at' => 'datetime',
        'available_at' => 'datetime',
    ];
}
