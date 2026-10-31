<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataSource extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
        'last_success_at' => 'datetime',
        'last_error_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'metadata' => 'array',
    ];
}
