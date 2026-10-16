<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'related_tickers' => 'array',
        'published_at' => 'datetime',
        'fetched_at' => 'datetime',
    ];
}
