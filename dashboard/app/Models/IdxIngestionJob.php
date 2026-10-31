<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdxIngestionJob extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'checkpoint_data' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'progress_pct' => 'integer',
        'attempt_count' => 'integer',
        'max_attempts' => 'integer',
    ];
}
