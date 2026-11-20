<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentChunk extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
        'published_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'fetched_at' => 'datetime',
        'available_at' => 'datetime',
    ];
}
