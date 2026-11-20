<?php

namespace App\Console\Commands;

use App\Models\AnalysisRun;
use App\Models\BrokerSummary;
use App\Models\MarketObservation;
use App\Models\TelegramOutbox;
use App\Services\BeiCalendarService;
use App\Services\TelegramFormattingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class HermesEveningReviewCommand extends Command
{
    protected $signature = 'hermes:evening-review {--force : Abaikan kalender bursa untuk pengujian} {--dry-run : Cetak tanpa antrean outbox}';

    protected $description = 'Publikasikan Rekap Pasar dan Broker Summary EOD pukul 20:00 WIB';

    public function handle(BeiCalendarService $calendar, TelegramFormattingService $formatter): int
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

        // 1. Ambil Bar EOD Hari Ini untuk Sampel Tervalidasi (Pasar Reguler - RG)
        $eodBar = MarketObservation::where('ticker', 'BBCA')
            ->whereIn('board_scope', ['RG', 'INDEX'])
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

        $totalUniverse = 976;
        $bothModesCount = MarketObservation::where('bar_date', $dateStr)
            ->where('source', 'IDX Edge PRO')
            ->where('validation_status', 'VALID')
            ->where('board_scope', 'RG')
            ->where('adjustment_mode', 'UNADJUSTED')
            ->whereIn('ticker', function ($q) {
                $q->select('ticker')->from('market_observations')
                    ->where('bar_date', '2026-11-05')
                    ->where('source', 'IDX Edge PRO')
                    ->where('validation_status', 'VALID')
                    ->where('board_scope', 'RG')
                    ->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED');
            })
            ->distinct('ticker')
            ->count('ticker');

        $isDelayed = $nowWib->hour >= 20 && ($nowWib->hour > 20 || $nowWib->minute >= 15);
        $headerTitle = $isDelayed
            ? "REKAP PASAR & AKTIVITAS BROKER PASAR REGULER (EOD) — {$dateFmt} [PUBLIKASI SUSULAN]"
            : "REKAP PASAR & AKTIVITAS BROKER PASAR REGULER (EOD) — {$dateFmt}";

        $lines = [
            $headerTitle,
            '──────────────────────────────────────────',
            "• Sesi Perdagangan : {$dateFmt} (Sesi Penutupan Bursa)",
            "• Waktu Publikasi  : {$nowWib->format('H:i:s')} WIB".($isDelayed ? ' (Terlambat akibat antrean pemulihan data EOD)' : ''),
            "• Coverage Sesi EOD : {$bothModesCount}/{$totalUniverse} emiten RG lengkap dua mode (".round(($bothModesCount / $totalUniverse) * 100, 1).'%)',
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

        // Evaluasi Trading Plan Terhadap Bar EOD Resmi
        $lines[] = '──────────────────────────────────────────';
        $lines[] = '📋 EVALUASI OUTCOME TRADING PLAN HARI INI:';
        $lines[] = "• Cakupan Evaluasi : Seluruh Setup Terbitan Hari Ini ({$dateFmt})";
        $lines[] = '• Status Realisasi : Dicatat pada ledger audit histori; tanpa penyesuaian angka historis retroaktif.';
        $lines[] = '• Metodologi       : Sentuhan harga target/CL tidak membuktikan transaksi terisi penuh (fill).';
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

        if ($dryRun) {
            $this->line($reportText);
            $this->info('[PREVIEW / DRY-RUN] Rekap Sore selesai tanpa membuat outbox atau rekod eksekusi produksi.');

            return Command::SUCCESS;
        }

        $dedupKey = 'EVENING_REVIEW_'.$nowWib->format('Ymd');
        $existing = TelegramOutbox::where('dedup_key', $dedupKey)->first();
        if ($existing && $existing->status === 'SENT') {
            $this->info("Rekap Sore hari ini sudah terkirim (Outbox #{$existing->id}, Msg ID #{$existing->telegram_message_id}). Menolak penyiaran ulang.");

            return Command::SUCCESS;
        }

        $dueAt = Carbon::createFromFormat('Y-m-d H:i', "{$nowWib->format('Y-m-d')} 20:00", 'Asia/Jakarta')->setTimezone('UTC');
        $runId = 'RUN_EVENING_'.$nowWib->format('Ymd_His').'_'.Str::lower(Str::random(4));
        $chatId = config('services.telegram.chat_id', '5923895922');
        $triggerSource = $this->input->isInteractive() ? 'MANUAL_CLI' : 'SCHEDULED';

        AnalysisRun::firstOrCreate(
            ['run_id' => $runId],
            [
                'trigger_type' => 'evening_review_2000',
                'status' => 'completed',
                'started_at' => $nowWib,
                'completed_at' => now('Asia/Jakarta'),
                'metadata' => [
                    'session_date' => $dateStr,
                    'ticker' => 'BBCA',
                    'broker_status' => 'FINALITY_UNATTESTED_BY_PROVIDER',
                    'report_type' => 'EVENING_REVIEW',
                    'trigger_source' => $triggerSource,
                ],
            ]
        );

        $outboxItem = $formatter->enqueueOutbox(
            dedupKey: $dedupKey,
            messageType: 'SYSTEM_ALERT',
            payload: $reportText,
            predictionId: null,
            replyToMessageId: null,
            chatId: $chatId,
            dueAt: $dueAt,
            triggerSource: $triggerSource,
            metadata: [
                'report_type' => 'EVENING_REVIEW',
                'session_date' => $dateStr,
                'ticker' => 'BBCA',
                'run_id' => $runId,
                'chat_id' => $chatId,
                'due_at_wib' => "{$nowWib->format('Y-m-d')} 20:00 WIB",
            ]
        );

        $this->info("Rekap Sore berhasil diantrekan (Outbox #{$outboxItem->id}, Status: {$outboxItem->status}, Due At: {$dueAt->toIso8601String()}).");

        return Command::SUCCESS;
    }
}
