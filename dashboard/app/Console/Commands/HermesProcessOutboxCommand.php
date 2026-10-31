<?php

namespace App\Console\Commands;

use App\Services\TelegramFormattingService;
use Illuminate\Console\Command;

class HermesProcessOutboxCommand extends Command
{
    protected $signature = 'hermes:process-outbox {--live : Send real Telegram messages via Hermes CLI}';

    protected $description = 'Process queued Telegram outbox items (defaults to safe mock mode)';

    public function handle(TelegramFormattingService $formatter): int
    {
        $isLive = $this->option('live');
        $this->info('=== MEMPROSES TELEGRAM OUTBOX QUEUE ===');
        $this->line('Mode: '.($isLive ? 'LIVE HERMES CLI' : 'MOCK (SAFE ISOLATION)'));

        $res = $formatter->processOutboxQueue(mockOnly: ! $isLive);

        $this->table(
            ['Metrik Antrean', 'Nilai'],
            [
                ['Total Diproses', $res['processed']],
                ['Berhasil Terkirim', $res['sent']],
                ['Gagal / Tertunda', $res['failed']],
            ]
        );

        $this->info('Pemrosesan outbox selesai.');

        return 0;
    }
}
