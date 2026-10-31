<?php

namespace App\Console\Commands;

use App\Services\BrokerSummaryIngestionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hermes:sync-idx-edge-broker {ticker=BBCA : Kode saham 4 huruf} {--date= : Tanggal sesi YYYY-MM-DD} {--flow=all : Filter flow all, F, D}')]
#[Description('Sinkronisasi terkontrol aktivitas broker summary IDX Edge (Pasar Reguler) untuk sampel saham')]
class SyncIdxEdgeBrokerCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BrokerSummaryIngestionService $service): int
    {
        $ticker = strtoupper((string) $this->argument('ticker'));
        $date = $this->option('date') ?: null;
        $flow = (string) $this->option('flow');

        $this->info("Menjalankan sinkronisasi broker summary IDX Edge untuk {$ticker} (tanggal: ".($date ?? 'today').", flow: {$flow})...");

        $result = $service->syncDailySummary($ticker, $date, $flow);

        if (! $result['success']) {
            $this->error("Sinkronisasi gagal: [{$result['error_type']}] {$result['message']}");

            return self::FAILURE;
        }

        $this->info("Sinkronisasi BERHASIL: {$result['saved_records']} data broker tersimpan (Board: {$result['board']}).");
        $this->line('Top Buyers: '.json_encode(array_column($result['top_buyers'], 'broker_code')));
        $this->line('Top Sellers: '.json_encode(array_column($result['top_sellers'], 'broker_code')));

        return self::SUCCESS;
    }
}
