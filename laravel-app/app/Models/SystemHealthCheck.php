<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemHealthCheck extends Model
{
    protected $guarded = [];

    protected $casts = [
        'details' => 'array',
        'checked_at' => 'datetime',
    ];
}
