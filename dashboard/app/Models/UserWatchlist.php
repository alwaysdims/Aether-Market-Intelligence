<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserWatchlist extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'added_at' => 'datetime',
    ];
}
