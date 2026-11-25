<?php

namespace App\Console\Commands;

use App\Services\UniversalRuleBasedScreeningService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class AetherScreenUniversalCommand extends Command
{
    protected $signature = 'aether:screen-universal {--live : Enqueue the generated research plan to the existing Telegram outbox}';

    protected $description = 'Screen all registered IDX equities from IDX Edge batch quotes';

    public function handle(UniversalRuleBasedScreeningService $screening): int
    {
        try {
            $result = $screening->run(Carbon::now('Asia/Jakarta'), (bool) $this->option('live'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode([
            'status' => $result['status'],
            'registry_count' => $result['registry_count'],
            'provider_count' => $result['provider_count'],
            'candidate_count' => $result['candidate_count'],
            'evidence_id' => $result['evidence_id'],
            'outbox_id' => $result['outbox_id'],
            'live_delivery_requested' => $result['live_delivery_requested'],
            'writes_executed' => $result['writes_executed'],
            'message' => $result['message'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if ($this->option('live') && $result['outbox_id']) {
            $this->info("Outbox #{$result['outbox_id']} queued. Existing outbox worker owns Telegram delivery.");
        }

        return self::SUCCESS;
    }
}
