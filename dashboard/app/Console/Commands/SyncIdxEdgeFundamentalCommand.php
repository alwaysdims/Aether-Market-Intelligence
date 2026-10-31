<?php

namespace App\Console\Commands;

use App\Services\IdxEdgeFundamentalIngestionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hermes:sync-idx-edge-fundamental {ticker=BBCA : Kode saham 4 huruf} {--limit=2 : Jumlah periode}')]
#[Description('Sinkronisasi terkontrol laporan keuangan fundamental IDX Edge untuk sampel saham')]
class SyncIdxEdgeFundamentalCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(IdxEdgeFundamentalIngestionService $service): int
    {
        $ticker = strtoupper((string) $this->argument('ticker'));
        $limit = (int) $this->option('limit');

        $this->info("Menjalankan sinkronisasi fundamental IDX Edge untuk {$ticker} (limit: {$limit})...");

        $result = $service->syncAnnualStatements($ticker, $limit);

        if (! $result['success']) {
            $this->error("Sinkronisasi gagal: [{$result['error_type']}] {$result['message']}");

            return self::FAILURE;
        }

        $this->info("Sinkronisasi BERHASIL: {$result['saved_records']} laporan keuangan tersimpan (Tahun: {$result['latest_year']}, Net Income: Rp {$result['latest_net_income']}, Kualitas: {$result['data_quality']}).");

        return self::SUCCESS;
    }
}
