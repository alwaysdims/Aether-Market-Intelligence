<?php

namespace App\Console\Commands;

use App\Models\AnalysisRun;
use App\Models\BrokerSummary;
use App\Models\MarketObservation;
use App\Models\TelegramOutbox;
use App\Services\BeiCalendarService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class HermesEveningReviewCommand extends Command
{
    protected $signature = 'hermes:evening-review {--force : Abaikan kalender bursa untuk pengujian} {--dry-run : Cetak tanpa antrean outbox}';

    protected $description = 'Publikasikan Rekap Pasar dan Broker Summary EOD pukul 20:00 WIB';

    public function handle(BeiCalendarService $calendar): int
    {
        $nowWib = Carbon::now('Asia/Jakarta');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        if (! $force && ! $calendar->isTradingDay($nowWib)) {
            $this->info("[EVENING_REVIEW] Hari ini ({$nowWib->format('Y-m-d')}) bukan hari bursa aktif BEI. Rekap sore dilewati.");

            return Command::SUCCESS;
        }

        $sessionDate = $calendar->getCurrentTradingDay($nowWib);
        $dateStr = $sessionDate->format('Y-m-d');
        $dateFmt = $sessionDate->format('d M Y');

        $this->info("Menyiapkan Rekap Pasar & Broker Summary EOD (Sesi: {$dateFmt})...");

        // 1. Ambil Bar EOD Hari Ini untuk Sampel Tervalidasi
        $eodBar = MarketObservation::where('ticker', 'BBCA')
            ->where('bar_date', $dateStr)
            ->where('validation_status', 'VALID')
            ->first();

        // 2. Ambil Broker Summary Hari Ini untuk Sampel Tervalidasi
        $brokers = BrokerSummary::where('ticker', 'BBCA')
            ->whereDate('summary_date', $dateStr)
            ->where('flow_type', 'all')
            ->get();

        if ($brokers->isEmpty()) {
            // Ambil snapshot broker terakhir tersimpan
            $lastBroker = BrokerSummary::where('ticker', 'BBCA')->latest('summary_date')->first();
            if ($lastBroker) {
                $dateStr = $lastBroker->summary_date->format('Y-m-d');
                $dateFmt = $lastBroker->summary_date->format('d M Y');
                $brokers = BrokerSummary::where('ticker', 'BBCA')
                    ->whereDate('summary_date', $dateStr)
                    ->where('flow_type', 'all')
                    ->get();
            }
        }

        $topB = $brokers->where('net_value', '>', 0)->sortByDesc('net_value')->take(3);
        $topS = $brokers->where('net_value', '<', 0)->sortBy('net_value')->take(3);

        $lines = [
            "REKAP PASAR & AKTIVITAS BROKER PASAR REGULER (EOD) — {$dateFmt}",
            '──────────────────────────────────────────',
            "• Sesi Perdagangan : {$dateFmt} (Sesi Penutupan & Pasca-Kliring Bursa)",
            '• Status Data Broker : OBSERVED_SNAPSHOT (FINALITY_UNATTESTED_BY_PROVIDER)',
            '• Cakupan Emiten    : BBCA (Pilot Terverifikasi Saham Perbankan IDR)',
            '• Papan & Unit      : Pasar Reguler (RG) | Satuan: Lembar (Shares) & IDR',
            '• Sumber Data       : IDX Edge PRO EOD + Broker Summary Feed',
            '──────────────────────────────────────────',
        ];

        if ($eodBar) {
            $lines[] = 'RINGKASAN PENUTUPAN KANONIKAL BBCA:';
            $lines[] = sprintf(
                '• Close: Rp %s | High: Rp %s | Low: Rp %s | Vol: %s jt lbr',
                number_format($eodBar->close_price, 0, ',', '.'),
                number_format($eodBar->high_price ?? 0, 0, ',', '.'),
                number_format($eodBar->low_price ?? 0, 0, ',', '.'),
                round(($eodBar->volume ?? 0) / 1e6, 2)
            );
            $lines[] = '──────────────────────────────────────────';
        }

        $lines[] = 'AKTIVITAS AKUMULASI / DISTRIBUSI BROKER (TOP-N SAMPEL):';
        if ($topB->isNotEmpty()) {
            $bList = [];
            foreach ($topB as $b) {
                $avgP = $b->buy_avg_price ? ' @ avg Rp '.number_format($b->buy_avg_price, 0, ',', '.') : '';
                $bList[] = sprintf('%s (+Rp %.2f M%s)', $b->broker_code, $b->net_value / 1e9, $avgP);
            }
            $lines[] = '• Top Net Buyers : '.implode(', ', $bList);
        } else {
            $lines[] = '• Top Net Buyers : Nihil / Belum teramati.';
        }

        if ($topS->isNotEmpty()) {
            $sList = [];
            foreach ($topS as $s) {
                $avgP = $s->sell_avg_price ? ' @ avg Rp '.number_format($s->sell_avg_price, 0, ',', '.') : '';
                $sList[] = sprintf('%s (-Rp %.2f M%s)', $s->broker_code, abs($s->net_value) / 1e9, $avgP);
            }
            $lines[] = '• Top Net Sellers: '.implode(', ', $sList);
        } else {
            $lines[] = '• Top Net Sellers: Nihil / Belum teramati.';
        }

        $lines[] = '──────────────────────────────────────────';
        $lines[] = 'BATASAN METODOLOGI & INTEGRITAS:';
        $lines[] = '• Status Finalitas: Data broker berstatus OBSERVED_SNAPSHOT. Provider tidak menyediakan flag finalitas resmi atau jaminan bebas revisi settlement.';
        $lines[] = '• Cakupan Broker: Dibatasi pada Top-N Broker teramati; bukan daftar komprehensif seluruh anggota bursa.';
        $lines[] = '• Average Price: Dihitung murni sebagai buy_value / buy_volume atau sell_value / sell_volume (bukan harga akumulasi bersih).';
        $lines[] = '• Anti-Bandarmologi: Agregasi pergerakan broker bukan bukti kepemilikan definitif entitas bandar tunggal.';
        $lines[] = '• Quote Intraday: Tetap berstatus QUARANTINED (tidak digunakan untuk rekap reguler).';
        $lines[] = '──────────────────────────────────────────';
        $lines[] = "Dipublikasikan oleh Aether Market Intelligence pada {$nowWib->format('d/m/Y H:i:s')} WIB";

        $reportText = implode("\n", $lines);

        $runId = 'RUN_EVENING_'.$nowWib->format('Ymd_His').'_'.Str::lower(Str::random(4));
        AnalysisRun::create([
            'run_id' => $runId,
            'trigger_type' => 'evening_review_2000',
            'status' => 'completed',
            'started_at' => $nowWib,
            'completed_at' => now('Asia/Jakarta'),
            'metadata' => [
                'session_date' => $dateStr,
                'ticker' => 'BBCA',
                'broker_status' => 'FINALITY_UNATTESTED_BY_PROVIDER',
                'report_type' => 'EVENING_REVIEW',
            ],
        ]);

        if (! $dryRun) {
            $chatId = config('services.telegram.chat_id', '5923895922');
            TelegramOutbox::create([
                'dedup_key' => 'EVENING_REVIEW_'.$nowWib->format('Ymd'),
                'chat_id' => $chatId,
                'message_type' => 'SYSTEM_ALERT',
                'payload_text' => $reportText,
                'status' => 'QUEUED',
                'metadata' => [
                    'review_date' => $nowWib->format('Y-m-d'),
                    'session_reference' => $dateStr,
                ],
            ]);
            $this->info('Rekap Sore berhasil dimasukkan ke antrean outbox Telegram.');
        } else {
            $this->line($reportText);
        }

        return Command::SUCCESS;
    }
}
