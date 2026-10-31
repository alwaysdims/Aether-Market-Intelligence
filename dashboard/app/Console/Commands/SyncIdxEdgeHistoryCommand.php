<?php

namespace App\Console\Commands;

use App\Services\DataSourceRegistryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hermes:sync-idx-edge-history {ticker=BBCA : Kode saham 4 huruf} {--limit=20 : Jumlah bar histori}')]
#[Description('Sinkronisasi terkontrol histori EOD IDX Edge untuk satu instrumen tervalidasi')]
class SyncIdxEdgeHistoryCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DataSourceRegistryService $registry): int
    {
        $ticker = strtoupper((string) $this->argument('ticker'));
        $limit = (int) $this->option('limit');

        $this->info("Menjalankan sinkronisasi histori terkontrol IDX Edge untuk {$ticker} (limit: {$limit})...");

        $result = $registry->syncControlledEodHistory($ticker, $limit);

        if (! $result['success']) {
            $this->error("Sinkronisasi gagal: [{$result['error_type']}] {$result['message']}");

            return self::FAILURE;
        }

        $this->info("Sinkronisasi BERHASIL: {$result['synced_bars']} bar disinkronkan. ID Terakhir: {$result['latest_observation_id']} ({$result['latest_date']}: Close Rp {$result['latest_close']})");

        return self::SUCCESS;
    }
}
