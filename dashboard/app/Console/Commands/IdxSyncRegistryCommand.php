<?php

namespace App\Console\Commands;

use App\Services\IdxRegistryService;
use Illuminate\Console\Command;

class IdxSyncRegistryCommand extends Command
{
    protected $signature = 'idx:sync-registry';

    protected $description = 'Sinkronisasi seluruh emiten tercatat Bursa Efek Indonesia (BEI) ke registri database.';

    public function handle(IdxRegistryService $registryService): int
    {
        $this->info('1. Memulai sinkronisasi registri seluruh saham BEI...');
        $res = $registryService->syncRegistry();

        if (! ($res['success'] ?? false)) {
            $this->error('Gagal menyinkronkan registri: '.($res['error'] ?? 'Unknown error'));

            return Command::FAILURE;
        }

        $this->info("   -> Berhasil menyinkronkan {$res['total_equities']} saham biasa dan {$res['total_indices']} indeks.");
        $this->line("   -> Snapshot Date : {$res['snapshot_date']}");
        $this->line("   -> Sumber Data   : {$res['source']}");
        $this->line("   -> Status Resmi  : {$res['official_status']}");
        $this->line("   -> Hash Arsip    : {$res['raw_hash']}");
        $this->line("   -> Total di DB   : {$res['total_in_db']} (Saham: {$res['equities_in_db']} | Indeks: {$res['indices_in_db']})");

        return Command::SUCCESS;
    }
}
