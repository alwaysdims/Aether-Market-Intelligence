<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EodSessionQueue extends Model
{
    use HasFactory;

    protected $table = 'eod_session_queues';

    protected $guarded = ['id'];

    protected $casts = [
        'session_date' => 'date',
        'target_tickers' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'stats' => 'array',
    ];
}
