<?php

namespace App\Console\Commands;

use App\Models\IdxCompanyRegistry;
use App\Services\UniverseBackfillService;
use Illuminate\Console\Command;

class BackfillUniverseCommand extends Command
{
    protected $signature = 'hermes:backfill-universe 
                            {--tickers= : Comma-separated list of tickers to backfill}
                            {--all : Backfill all active BEI equities}
                            {--force : Force re-backfill even for completed tickers}
                            {--limit= : Limit number of tickers to process in this run}
                            {--continue-from= : Resume backfill from this ticker alphabetically}
                            {--raw : Also backfill nominal/raw series alongside adjusted}
                            {--bar-limit=500 : Maximum bars to request per ticker (20-500)}';

    protected $description = 'Backfill 1-year OHLCV (+ SMA200 warmup) for BEI equities from IDX Edge PRO';

    public function handle(UniverseBackfillService $backfillService): int
    {
        $tickerOpt = $this->option('tickers');
        $allOpt = $this->option('all');
        $limitOpt = $this->option('limit') ? (int) $this->option('limit') : null;
        $continueFrom = $this->option('continue-from') ? strtoupper(trim($this->option('continue-from'))) : null;
        $includeRaw = (bool) $this->option('raw');
        $barLimit = min(500, max(20, (int) $this->option('bar-limit')));

        $tickers = [];

        if ($tickerOpt) {
            $tickers = array_filter(array_map('trim', explode(',', strtoupper($tickerOpt))));
        } else {
            $query = IdxCompanyRegistry::where('instrument_type', 'EQUITY')
                ->where('provider_availability', 'AVAILABLE')
                ->orderBy('ticker');

            if ($continueFrom) {
                $query->where('ticker', '>=', $continueFrom);
            }

            if (! $this->option('force')) {
                // By default process pending and failed/incomplete tickers first
                $query->whereIn('backfill_status', ['PENDING', 'FAILED', 'INCOMPLETE_DATA_GAP']);
            }

            if ($limitOpt !== null && $limitOpt > 0) {
                $query->limit($limitOpt);
            }

            $tickers = $query->pluck('ticker')->toArray();
        }

        if (empty($tickers)) {
            $this->warn('No tickers matched the backfill criteria.');

            return 0;
        }

        $totalCount = count($tickers);
        $this->info("Starting backfill for {$totalCount} tickers (Bar limit: {$barLimit}, Include Raw: ".($includeRaw ? 'YES' : 'NO').')...');

        $isDecorated = $this->output->isDecorated();
        $bar = $isDecorated ? $this->output->createProgressBar($totalCount) : null;
        if ($bar) {
            $bar->start();
        }

        $stats = $backfillService->backfillBatch($tickers, $includeRaw, $barLimit, function ($ticker, $current, $total, $res) use ($bar) {
            $status = $res['success'] ? ($res['backfill_status'] ?? 'OK') : ('FAIL: '.($res['message'] ?? 'Unknown error'));
            if ($bar) {
                $bar->advance();
            } else {
                $this->line("[{$current}/{$total}] {$ticker} [{$status}]");
            }
        });

        if ($bar) {
            $bar->finish();
            $this->newLine(2);
        }

        $this->info('Backfill batch completed:');
        $this->line("- Total tickers processed: {$stats['total_tickers']}");
        $this->line("- Complete (>= 200 bars): {$stats['completed']}");
        $this->line("- Short Listing History (< 200 bars): {$stats['short_listing']}");
        $this->line("- Failed: {$stats['failed']}");
        $this->line("- Total bars synced: {$stats['total_bars_synced']}");

        return $stats['failed'] > 0 ? 1 : 0;
    }
}
