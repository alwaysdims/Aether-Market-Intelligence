<?php

namespace App\Console\Commands;

use App\Services\TelegramFormattingService;
use Illuminate\Console\Command;

class HermesProcessOutboxCommand extends Command
{
    protected $signature = 'hermes:process-outbox {--live : Send real Telegram messages via Hermes CLI} {--job-id= : Process only one scheduled Telegram job outbox}';

    protected $description = 'Process queued Telegram outbox items (defaults to safe mock mode)';

    public function handle(TelegramFormattingService $formatter): int
    {
        $isLive = $this->option('live');
        $jobId = $this->option('job-id');
        if ($jobId !== null && (! ctype_digit((string) $jobId) || (int) $jobId < 1)) {
            $this->error('job-id must be a positive integer.');

            return self::FAILURE;
        }

        $this->info('=== MEMPROSES TELEGRAM OUTBOX QUEUE ===');
        $this->line('Mode: '.($isLive ? 'LIVE HERMES CLI' : 'MOCK (SAFE ISOLATION)'));

        $res = $formatter->processOutboxQueue(mockOnly: ! $isLive, scheduledJobId: $jobId === null ? null : (int) $jobId);

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
