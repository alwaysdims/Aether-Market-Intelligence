<?php

namespace App\Console\Commands;

use App\Models\ScheduledTelegramJob;
use App\Models\TelegramOutbox;
use App\Services\TelegramMarketAssistantService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class HermesProcessConsultationQueueCommand extends Command
{
    protected $signature = 'hermes:process-consultation-queue {--limit=5 : Maximum number of consultation jobs to process} {--mock : Run in mock mode without live Telegram delivery} {--live : Execute real Telegram delivery via Hermes CLI}';

    protected $description = 'Dedicated fast-track worker for asynchronous AI consultation jobs';

    public function handle(TelegramMarketAssistantService $service): int
    {
        $isLive = $this->option('live');
        $mockOnly = ! $isLive;
        $limit = (int) ($this->option('limit') ?: 5);

        // 1. Reclaim stale leases if worker is confirmed DEAD
        $staleCandidates = ScheduledTelegramJob::where('status', 'PROCESSING')
            ->where('request_type', 'ASYNC_CONSULTATION')
            ->where(function ($q) {
                $q->whereNull('heartbeat_at')
                    ->orWhere('heartbeat_at', '<', now()->subMinutes(3));
            })
            ->get();

        foreach ($staleCandidates as $staleJob) {
            $isAlive = $service->getProcessDetails($staleJob->worker_pid)['alive'];
            if (! $isAlive) {
                $staleJob->update(['status' => 'PENDING', 'worker_pid' => null, 'worker_token' => null]);
                $this->warn("Reclaimed dead consultation job #{$staleJob->id} to PENDING.");
            }
        }

        // 2. Fetch pending async consultation jobs
        $jobs = ScheduledTelegramJob::where('status', 'PENDING')
            ->where('request_type', 'ASYNC_CONSULTATION')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at', 'asc')
            ->limit($limit)
            ->get();

        if ($jobs->isEmpty()) {
            return 0;
        }

        $this->info("Memproses {$jobs->count()} pekerjaan konsultasi AI asinkron...");

        foreach ($jobs as $job) {
            // Absolute deadline enforcement (compare strictly in UTC to prevent timezone skew)
            $deadlineUtc = $job->deadline_at ? Carbon::parse($job->deadline_at)->utc() : null;
            if ($deadlineUtc && Carbon::now('UTC')->greaterThan($deadlineUtc)) {
                // Ensure job is not already completed/sent before marking MISSED
                if ($job->status === 'COMPLETED' || $job->outbound_message_id) {
                    continue;
                }

                $job->update([
                    'status' => 'MISSED',
                    'delivery_status' => 'FAILED',
                    'error_message' => "Deadline pekerjaan terlampaui ({$deadlineUtc->toIso8601String()}).",
                ]);

                if ($job->chat_id) {
                    try {
                        TelegramOutbox::create([
                            'dedup_key' => 'MISSED_JOB_'.$job->id.'_'.time(),
                            'chat_id' => $job->chat_id,
                            'reply_to_message_id' => $job->inbound_message_id,
                            'message_type' => 'SYSTEM_ALERT',
                            'payload_text' => "⚠️ Permintaan analisis (Job #{$job->id}) melewati batas waktu operasional (deadline).\nSistem membatalkan pemrosesan untuk menjaga konsistensi.",
                            'status' => 'QUEUED',
                            'metadata' => [
                                'scheduled_job_id' => $job->id,
                                'worker_token' => $job->worker_token,
                                'reason' => 'DEADLINE_EXCEEDED',
                            ],
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('[HermesProcessConsultationQueueCommand] Failed to queue deadline notice: '.$e->getMessage());
                    }
                }

                $this->warn("Job #{$job->id} ditandai MISSED (deadline terlampaui).");

                continue;
            }

            $res = $service->executeScheduledJob($job, mockOnly: $mockOnly);

            if ($res['status'] === 'success') {
                $this->info("Job #{$job->id} selesai. OutboundMsg: {$res['outbound_message_id']} ({$res['duration_ms']} ms)");
            } else {
                $this->error("Job #{$job->id} gagal: ".($res['error'] ?? 'Unknown error'));
            }
        }

        return 0;
    }
}
