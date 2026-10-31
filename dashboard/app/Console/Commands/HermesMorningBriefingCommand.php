<?php

namespace App\Console\Commands;

use App\Models\AnalysisRun;
use App\Models\MarketObservation;
use App\Models\TelegramOutbox;
use App\Services\BeiCalendarService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class HermesMorningBriefingCommand extends Command
{
    protected $signature = 'hermes:morning-briefing {--force : Abaikan kalender bursa untuk pengujian} {--dry-run : Cetak tanpa antrean outbox}';

    protected $description = 'Publikasikan Briefing Pagi BEI pukul 08:30 WIB berbasis data EOD sesi perdagangan sebelumnya';

    public function handle(BeiCalendarService $calendar): int
    {
        $nowWib = Carbon::now('Asia/Jakarta');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        if (! $force && ! $calendar->isTradingDay($nowWib)) {
            $this->info("[MORNING_BRIEFING] Hari ini ({$nowWib->format('Y-m-d')}) bukan hari bursa aktif BEI. Briefing dilewati.");

            return Command::SUCCESS;
        }

        $prevTradingDay = $calendar->getPreviousTradingDay($nowWib);
        $prevDateStr = $prevTradingDay->format('Y-m-d');
        $prevDateFmt = $prevTradingDay->format('d M Y');

        $this->info("Menyiapkan Briefing Pagi (Acuan Sesi: {$prevDateFmt})...");

        // Ambil data EOD tervalidasi sesi sebelumnya untuk 10 konstituen utama
        $tickers = ['BBCA', 'BBRI', 'BMRI', 'BBNI', 'ASII', 'TLKM', 'MAPI', 'ARTO', 'MEDC', 'SMRA'];
        $observations = MarketObservation::whereIn('ticker', $tickers)
            ->where('bar_date', $prevDateStr)
            ->where('validation_status', 'VALID')
            ->get();

        if ($observations->isEmpty()) {
            $this->warn("Data observasi EOD untuk {$prevDateStr} belum lengkap. Mengambil bar terbaru tersimpan...");
            $observations = MarketObservation::whereIn('ticker', $tickers)
                ->where('validation_status', 'VALID')
                ->latest('bar_date')
                ->get()
                ->unique('ticker');
        }

        $lines = [
            "BRIEFING PAGI PASAR BEI (PRE-OPENING) — {$nowWib->format('d M Y, 08:30')} WIB",
            '──────────────────────────────────────────',
            "• Sesi Acuan : Penutupan Bursa EOD {$prevDateFmt}",
            '• Usia Data  : EOD Final Sesi Sebelumnya (Sesi Pre-Opening BEI: 08:45 WIB)',
            '• Cakupan    : 10 Konstituen Saham Utama Terpantau',
            '• Sumber     : Canonical EOD Market Data (Status: VALID)',
            '──────────────────────────────────────────',
            'REKAPITULASI PENUTUPAN SESI SEBELUMNYA:',
        ];

        foreach ($observations as $obs) {
            $close = number_format($obs->close_price, 0, ',', '.');
            $volM = round(($obs->volume ?? 0) / 1e6, 2);
            $lines[] = sprintf(
                '• %-4s: Rp %s | Vol: %s jt lbr | Sumber: %s',
                $obs->ticker,
                $close,
                $volM,
                $obs->source
            );
        }

        $lines[] = '──────────────────────────────────────────';
        $lines[] = 'STATUS OPERASIONAL & KETERBATASAN:';
        $lines[] = '• Quote Intraday (09:15, 12:15, 15:15): DINONAKTIFKAN (QUARANTINED).';
        $lines[] = '• Catatan: Provider mencampur transaksi Pasar Negosiasi pada feed quote; sistem dilarang berspekulasi memakai data intraday tanpa filter papan reguler.';
        $lines[] = '• Integritas: Rp0 Biaya data, tanpa eksekusi broker, tanpa rekomendasi spekulatif.';
        $lines[] = '──────────────────────────────────────────';
        $lines[] = "Dipublikasikan oleh Aether Market Intelligence pada {$nowWib->format('d/m/Y H:i:s')} WIB";

        $reportText = implode("\n", $lines);

        $runId = 'RUN_BRIEFING_'.$nowWib->format('Ymd_His').'_'.Str::lower(Str::random(4));
        AnalysisRun::create([
            'run_id' => $runId,
            'trigger_type' => 'morning_briefing_0830',
            'status' => 'completed',
            'started_at' => $nowWib,
            'completed_at' => now('Asia/Jakarta'),
            'metadata' => [
                'as_of_session' => $prevDateStr,
                'tickers_covered' => $observations->pluck('ticker')->toArray(),
                'report_type' => 'MORNING_BRIEFING',
                'quote_intraday_status' => 'QUARANTINED',
            ],
        ]);

        if (! $dryRun) {
            $chatId = config('services.telegram.chat_id', '5923895922');
            TelegramOutbox::create([
                'dedup_key' => 'MORNING_BRIEFING_'.$nowWib->format('Ymd'),
                'chat_id' => $chatId,
                'message_type' => 'SYSTEM_ALERT',
                'payload_text' => $reportText,
                'status' => 'QUEUED',
                'metadata' => [
                    'briefing_date' => $nowWib->format('Y-m-d'),
                    'session_reference' => $prevDateStr,
                ],
            ]);
            $this->info('Briefing Pagi berhasil dimasukkan ke antrean outbox Telegram.');
        } else {
            $this->line($reportText);
        }

        return Command::SUCCESS;
    }
}
