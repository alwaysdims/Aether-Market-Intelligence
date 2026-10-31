<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataRevisionArchive extends Model
{
    protected $fillable = [
        'record_type',
        'record_id',
        'ticker',
        'period_or_date',
        'revision_number',
        'previous_payload',
        'previous_hash',
        'new_payload',
        'new_hash',
        'changes',
        'source',
        'reason',
    ];

    protected $casts = [
        'previous_payload' => 'array',
        'new_payload' => 'array',
        'changes' => 'array',
        'revision_number' => 'integer',
    ];
}
