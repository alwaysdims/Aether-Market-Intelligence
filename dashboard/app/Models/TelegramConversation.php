<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramConversation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'provenance' => 'array',
        'response_time_ms' => 'integer',
    ];
}
