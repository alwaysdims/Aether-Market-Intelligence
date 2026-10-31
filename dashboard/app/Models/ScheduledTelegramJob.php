<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduledTelegramJob extends Model
{
    use HasFactory;

    protected $table = 'scheduled_telegram_jobs';

    protected $fillable = [
        'chat_id',
        'telegram_user_id',
        'telegram_update_id',
        'inbound_message_id',
        'original_prompt',
        'request_type',
        'parameters',
        'scheduled_at',
        'deadline_at',
        'started_at',
        'analysis_completed_at',
        'data_cutoff_at',
        'market_session_closed_at',
        'evidence_available_at',
        'analysis_information_cutoff',
        'bar_time',
        'data_available_at',
        'timezone',
        'status',
        'delivery_status',
        'worker_pid',
        'worker_token',
        'worker_process_created_at',
        'heartbeat_at',
        'idempotency_key',
        'executed_at',
        'sent_at',
        'analysis_id',
        'evidence_id',
        'outbox_id',
        'outbound_message_id',
        'result_summary',
        'error_message',
        'latency_ms',
        'lateness_seconds',
        'tolerance_minutes',
    ];

    protected $casts = [
        'parameters' => 'array',
        'scheduled_at' => 'datetime',
        'deadline_at' => 'datetime',
        'started_at' => 'datetime',
        'analysis_completed_at' => 'datetime',
        'data_cutoff_at' => 'datetime',
        'market_session_closed_at' => 'datetime',
        'evidence_available_at' => 'datetime',
        'analysis_information_cutoff' => 'datetime',
        'data_available_at' => 'datetime',
        'executed_at' => 'datetime',
        'sent_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'latency_ms' => 'integer',
        'lateness_seconds' => 'integer',
        'tolerance_minutes' => 'integer',
        'worker_pid' => 'integer',
        'analysis_id' => 'integer',
        'evidence_id' => 'integer',
        'outbox_id' => 'integer',
    ];

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isDue(): bool
    {
        return $this->status === 'PENDING' && now()->greaterThanOrEqualTo($this->scheduled_at);
    }
}
