<?php

namespace App\Console\Commands;

use App\Services\UniverseDiscoveryService;
use Illuminate\Console\Command;

class SyncUniverseRegistryCommand extends Command
{
    protected $signature = 'hermes:sync-universe-registry';

    protected $description = 'Reconcile and discover all BEI tickers from IDX Edge provider catalogue';

    public function handle(UniverseDiscoveryService $discovery): int
    {
        $this->info('Starting Universe Discovery & Registry Reconciliation from IDX Edge...');

        $result = $discovery->reconcileUniverse();

        if (! ($result['success'] ?? false)) {
            $this->error('Failed to reconcile universe.');

            return 1;
        }

        $this->info('Universe discovery completed:');
        $this->line("- Provider total reported: {$result['provider_total_reported']} across {$result['total_pages']} pages");
        $this->line("- Distinct tickers discovered: {$result['distinct_tickers_discovered']}");
        $this->line("- Total items processed: {$result['total_items_processed']}");
        $this->line("- Duplicates skipped: {$result['duplicates_skipped']}");
        $this->line("- Excluded invalid codes: {$result['excluded_invalid_codes']}");
        $this->line("- Newly registered tickers: {$result['new_registered']}");
        $this->line("- Updated existing tickers: {$result['updated_existing']}");
        $this->line("- Marked unavailable: {$result['marked_unavailable']}");
        $this->line("- Total BEI equities in registry: {$result['total_equities_in_registry']} (Available: {$result['available_equities']})");
        $this->line("- Total indices in registry: {$result['total_indices_in_registry']}");

        return 0;
    }
}
