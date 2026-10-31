<?php

namespace App\Console\Commands;

use App\Services\BeiCalendarService;
use App\Services\MarketMonitoringService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class HermesMonitorMarketCommand extends Command
{
    protected $signature = 'hermes:monitor-market {--ingest : Also ingest latest EOD observations from cleaned data}';

    protected $description = 'Monitor active predictions during market hours, track EOD finality, detect offline gaps, and evaluate expirations';

    public function handle(MarketMonitoringService $monitorService, BeiCalendarService $calendar): int
    {
        $this->info('=== AETHER MARKET MONITORING & TRACKING ===');

        $nowWib = Carbon::now('Asia/Jakarta');
        $session = $calendar->getMarketSessionStatus($nowWib);

        $this->line("Waktu Pemantauan : {$nowWib->format('d M Y, H:i:s')} WIB");
        $this->line('Status Sesi BEI  : '.($session['is_open'] ? 'TERBUKA' : 'TUTUP')." ({$session['session']})");
        $this->line("Keterangan Sesi  : {$session['description']}");
        $this->line('Sumber Data      : EOD Tracking via Yahoo Finance (15-min delayed / EOD final)');

        if ($this->option('ingest')) {
            $this->info('Ingesting observations from cleaned data...');
            $ingested = $monitorService->ingestObservationsFromCleanedData();
            $this->info("Ingested new observations: {$ingested}");
        }

        $res = $monitorService->runMonitoringCycle();

        $this->table(
            ['Metrik Pemantauan', 'Nilai'],
            [
                ['Sinyal Aktif Diperiksa', $res['active_signals_checked']],
                ['Sinyal Kedaluwarsa (14 Hari)', $res['signals_expired']],
                ['Evaluasi Horizon 5-Hari Selesai', $res['signals_evaluated']],
                ['Event Pemantauan Dihasilkan', $res['events_generated']],
                ['Celah Offline Terdeteksi', $res['gaps_detected']],
            ]
        );

        $this->info('Pemantauan selesai dijalankan.');

        return 0;
    }
}
