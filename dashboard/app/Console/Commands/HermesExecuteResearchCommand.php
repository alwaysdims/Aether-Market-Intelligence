<?php

namespace App\Console\Commands;

use App\Models\ResearchRequest;
use App\Services\OnDemandResearchService;
use App\Services\TelegramFormattingService;
use Illuminate\Console\Command;

class HermesExecuteResearchCommand extends Command
{
    protected $signature = 'aether:execute-research {request_id}';

    protected $description = 'Execute on-demand data research and grounded AI narrative asynchronously';

    public function handle(OnDemandResearchService $researchService): int
    {
        $requestId = $this->argument('request_id');

        $req = ResearchRequest::where('request_id', $requestId)->first();
        if (! $req) {
            $this->error("Research request [{$requestId}] not found.");

            return 1;
        }

        if ($req->status === 'completed') {
            $this->info("Research request [{$requestId}] is already completed.");

            return 0;
        }

        $this->info("Executing research for request [{$requestId}] ({$req->ticker})...");
        $res = $researchService->executeResearch($req);

        // ponytail: flush outbox immediately so chat user gets result without waiting for 5-min scheduler
        app(TelegramFormattingService::class)->processOutboxQueue(app()->runningUnitTests());

        if ($res['success'] ?? false) {
            $this->info("Research request [{$requestId}] completed successfully in ".($res['duration_ms'] ?? 0).' ms.');

            return 0;
        }

        $this->error("Research request [{$requestId}] failed: ".($res['reply_text'] ?? 'Unknown error'));

        return 1;
    }
}
