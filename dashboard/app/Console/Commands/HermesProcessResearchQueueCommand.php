<?php

namespace App\Console\Commands;

use App\Models\ResearchRequest;
use App\Services\OnDemandResearchService;
use Illuminate\Console\Command;

class HermesProcessResearchQueueCommand extends Command
{
    protected $signature = 'aether:process-research-queue {--limit=5}';

    protected $description = 'Process pending on-demand research requests from the database queue';

    public function handle(OnDemandResearchService $researchService): int
    {
        $limit = (int) $this->option('limit');

        $claimed = collect();
        \DB::transaction(function () use ($limit, &$claimed): void {
            $claimed = ResearchRequest::where('status', 'pending')
                ->where(function ($query) {
                    $query->whereNull('research_metadata->deadline_at')
                        ->orWhereRaw("(research_metadata->>'deadline_at')::timestamptz > ?", [now('UTC')->toIso8601String()]);
                })
                ->orderBy('id', 'asc')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->limit(max(1, min($limit, 5)))
                ->get();

            foreach ($claimed as $request) {
                $metadata = is_array($request->research_metadata) ? $request->research_metadata : [];
                $metadata['worker_claimed_at'] = now('UTC')->toIso8601String();
                $metadata['worker_owner'] = gethostname();
                $request->update([
                    'status' => 'collecting',
                    'research_metadata' => $metadata,
                ]);
            }
        });

        $pending = $claimed;

        if ($pending->isEmpty()) {
            $this->line('No pending research requests in queue.');

            return 0;
        }

        $this->info('Found '.$pending->count().' pending research requests.');

        foreach ($pending as $req) {
            $this->info("Processing {$req->request_id} ({$req->ticker})...");
            $researchService->executeResearch($req);
        }

        return 0;
    }
}
