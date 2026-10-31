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

        $pending = ResearchRequest::where('status', 'pending')
            ->orderBy('id', 'asc')
            ->take($limit)
            ->get();

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
