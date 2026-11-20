<?php

namespace App\Console\Commands;

use App\Models\AnalysisRun;
use App\Models\MarketObservation;
use App\Models\TelegramOutbox;
use App\Services\BeiCalendarService;
use App\Services\HermesAdapterService;
use App\Services\MiddaySessionReportService;
use App\Services\TelegramFormattingService;
use App\Services\TelegramTradingPlanService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class HermesMarketUpdateCommand extends Command
{
    protected $signature = 'hermes:market-update 
                            {slot : Slot waktu (09:15, 12:15, 15:15)} 
                            {--force : Abaikan kalender bursa untuk pengujian} 
                            {--dry-run : Cetak tanpa antrean outbox}';

    protected $description = 'Publikasikan Pembaruan Pasar Terjadwal berbasis Acuan EOD & Berita ANTARA (09:15, 12:15, 15:15 WIB)';

    public function handle(
        BeiCalendarService $calendar,
        HermesAdapterService $adapter,
        TelegramFormattingService $formatter,
        MiddaySessionReportService $middayService,
        TelegramTradingPlanService $tradingPlanService
    ): int {
        $nowWib = Carbon::now('Asia/Jakarta');
        $slot = trim((string) $this->argument('slot'));
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $allowedSlots = ['09:15', '12:15', '15:15'];
        if (! in_array($slot, $allowedSlots, true)) {
            $this->error("Slot [{$slot}] tidak valid. Pilihan yang didukung: ".implode(', ', $allowedSlots));

            return Command::FAILURE;
        }

        if (! $force && ! $calendar->isTradingDay($nowWib)) {
            $this->info("[MARKET_UPDATE] Hari ini ({$nowWib->format('Y-m-d')}) bukan hari bursa aktif BEI. Pembaruan pasar {$slot} dilewati.");

            return Command::SUCCESS;
        }

        $prevTradingDay = $calendar->getPreviousTradingDay($nowWib);
        $prevDateStr = $prevTradingDay->format('Y-m-d');
        $prevDateFmt = $prevTradingDay->format('d M Y');

        // 1. Ambil Bar EOD Terakhir untuk Konstituen Utama (Pasar Reguler - RG)
        $tickers = ['BBCA', 'BBRI', 'BMRI', 'BBNI', 'ASII', 'TLKM', 'MAPI', 'ARTO', 'MEDC', 'SMRA'];
        $observations = MarketObservation::whereIn('ticker', $tickers)
            ->whereIn('board_scope', ['RG', 'INDEX'])
            ->where('bar_date', $prevDateStr)
            ->where('source', 'IDX Edge PRO')
            ->where(fn ($q) => $q->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')->orWhereNull('adjustment_mode'))
            ->where('validation_status', 'VALID')
            ->get();

        if ($observations->isEmpty()) {
            $observations = MarketObservation::whereIn('ticker', $tickers)
                ->where('source', 'IDX Edge PRO')
                ->where(fn ($q) => $q->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')->orWhereNull('adjustment_mode'))
                ->where('validation_status', 'VALID')
                ->orderByDesc('bar_date')
                ->get()
                ->unique('ticker');
        }

        // Penanganan Khusus Slot 12:15 WIB (Laporan Jeda Sesi 1 Berbasis Data Pasar Reguler)
        if ($slot === '12:15') {
            $this->info('Mengevaluasi kelayakan data live Pasar Reguler (RG) untuk Sesi 1 (12:15 WIB)...');
            $coverage = $middayService->evaluateLiveSession1Coverage();

            if (! $coverage['is_rg_ready']) {
                $this->warn('Data live RG belum memenuhi syarat. Menyusun Laporan Sesi 1 Belum Tersedia...');
                $deterministicSynthesis = $middayService->synthesizeDeterministic($coverage, false);
                $reportText = $middayService->buildSession1UnavailableReport(
                    coverage: $coverage,
                    sessionDate: $nowWib,
                    prevTradingDay: $prevTradingDay,
                    prevEodObservations: $observations,
                    deterministicSynthesis: $deterministicSynthesis
                );
            } else {
                $this->info('Data live RG tervalidasi. Menyusun Laporan Aktivitas Sesi 1...');
                $prevEodMap = $observations->keyBy('ticker')->toArray();
                $trades = $coverage['raw_state']['recent_trades'] ?? [];
                $aggResult = $middayService->aggregateValidatedTrades($trades, $prevEodMap);
                $deterministicSynthesis = $middayService->synthesizeDeterministic($aggResult, true);
                $reportText = "📊 LAPORAN AKTIVITAS SESI 1 PASAR REGULER (12:15 WIB)\n".json_encode($aggResult, JSON_PRETTY_PRINT);
            }

            if ($dryRun) {
                $this->line($reportText);
                $this->info('[PREVIEW / DRY-RUN] Selesai tanpa membuat outbox atau rekod eksekusi produksi.');

                return Command::SUCCESS;
            }

            $res = $middayService->publishMiddayReport($reportText, $nowWib, $slot, $dryRun);
            if ($res['status'] === 'ALREADY_SENT') {
                $this->info("Slot {$slot} hari ini sudah terkirim (Outbox #{$res['outbox_id']}, Msg ID #{$res['telegram_message_id']}). Menolak penyiaran ulang.");

                return Command::SUCCESS;
            }

            $this->info("Laporan Sesi 1 slot {$slot} berhasil diantrekan (Outbox #{$res['outbox_id']}, Due At: {$res['due_at']}).");

            return Command::SUCCESS;
        }

        // Penanganan Khusus Slot 09:15 WIB (Screening Scalping & Kandidat Swing)
        if ($slot === '09:15') {
            $this->info('Menyiapkan Trading Plan — Screening Scalping & Swing (09:15 WIB)...');
            $planData = $tradingPlanService->generateOpeningScreeningPlan($nowWib);
            $reportText = $tradingPlanService->renderTelegramTradingPlan($planData, $nowWib);

            if ($dryRun) {
                $this->line($reportText);
                $this->info('[PREVIEW / DRY-RUN] Selesai tanpa membuat outbox atau rekod eksekusi produksi.');

                return Command::SUCCESS;
            }

            $res = $tradingPlanService->publishPlanToOutbox($slot, $reportText, $nowWib, $dryRun, 45, $planData);
            if ($res['status'] === 'EXPIRED_STALE_ALERT') {
                $this->warn("Slot {$slot} telah kedaluwarsa ({$res['delay_minutes']} menit lewat). Menolak menyiarkan alert basi.");

                return Command::SUCCESS;
            }
            if ($res['status'] === 'ALREADY_SENT') {
                $this->info("Slot {$slot} hari ini sudah terkirim (Outbox #{$res['outbox_id']}). Menolak penyiaran ulang.");

                return Command::SUCCESS;
            }

            // Trigger atomic outbox processing immediately if due_at <= now, preserving lease and deduplication
            if (! $dryRun && ! empty($res['outbox_id'])) {
                app(TelegramFormattingService::class)->processOutboxQueue(mockOnly: app()->runningUnitTests());
            }

            $this->info("Trading Plan slot {$slot} berhasil diantrekan (Outbox #{$res['outbox_id']}, Due At: {$res['due_at']}).");

            return Command::SUCCESS;
        }

        // Penanganan Khusus Slot 15:15 WIB (Alert BSJP - Beli Sore Jual Pagi)
        if ($slot === '15:15') {
            $this->info('Menyiapkan Trading Plan — Alert BSJP (15:15 WIB)...');
            $planData = $tradingPlanService->generateBsjpPlan($nowWib);
            $reportText = $tradingPlanService->renderTelegramTradingPlan($planData, $nowWib);

            if ($dryRun) {
                $this->line($reportText);
                $this->info('[PREVIEW / DRY-RUN] Selesai tanpa membuat outbox atau rekod eksekusi produksi.');

                return Command::SUCCESS;
            }

            $res = $tradingPlanService->publishPlanToOutbox($slot, $reportText, $nowWib, $dryRun, 45, $planData);
            if ($res['status'] === 'EXPIRED_STALE_ALERT') {
                $this->warn("Slot {$slot} telah kedaluwarsa ({$res['delay_minutes']} menit lewat). Menolak menyiarkan peluang BSJP yang sudah basi.");

                return Command::SUCCESS;
            }
            if ($res['status'] === 'ALREADY_SENT') {
                $this->info("Slot {$slot} hari ini sudah terkirim (Outbox #{$res['outbox_id']}). Menolak penyiaran ulang.");

                return Command::SUCCESS;
            }

            $this->info("Trading Plan BSJP slot {$slot} berhasil diantrekan (Outbox #{$res['outbox_id']}, Due At: {$res['due_at']}).");

            return Command::SUCCESS;
        }

        // 2. Ambil Berita ANTARA Finansial Terverifikasi
        $newsItems = [];
        try {
            $newsResp = $adapter->fetchNewsFeed(null, 3);
            if (! empty($newsResp['data']['items'])) {
                $newsItems = array_slice($newsResp['data']['items'], 0, 3);
            }
        } catch (\Throwable $e) {
            $this->warn("Gagal mengambil berita terkini: {$e->getMessage()}");
        }

        // 3. Susun Teks Laporan Tanpa Klaim Palsu
        $lines = [
            "📢 {$slotTitle} — ACUAN EOD & BERITA",
            '──────────────────────────────────────────',
            "• Waktu Publikasi : {$nowWib->format('d M Y, H:i')} WIB",
            "• Sesi Acuan Data : EOD Terakhir ({$prevDateFmt})",
            '• Status Publikasi: TERJADWAL; RECEIPT TELEGRAM BELUM DIVERIFIKASI',
            '• Status Data RG  : BELUM TERSEDIA (QUARANTINED)',
            '──────────────────────────────────────────',
            '📊 ACUAN HARGA & INDIKATOR EOD SESI TERAKHIR:',
        ];

        foreach ($observations->take(5) as $obs) {
            $close = number_format($obs->close_price, 0, ',', '.');
            $lines[] = sprintf('• %-4s : Rp %s (EOD %s)', $obs->ticker, $close, $prevDateFmt);
        }

        if (! empty($newsItems)) {
            $lines[] = '──────────────────────────────────────────';
            $lines[] = '📰 BERITA EKONOMI & PASAR (LKBN ANTARA):';
            foreach ($newsItems as $news) {
                $title = trim($news['title'] ?? '');
                $lines[] = "• {$title}";
            }
        }

        $lines[] = '──────────────────────────────────────────';
        $lines[] = '🛡️ CATATAN INTEGRITAS & KETERBATASAN DATA:';
        $lines[] = '• Feed quote intraday (/api/price) berada dalam status QUARANTINED akibat percampuran data Pasar Negosiasi (NG) tanpa pemisah board.';
        $lines[] = '• Laporan ini murni memadukan baseline EOD terverifikasi dan berita resmi; tanpa klaim running trade, volume spike, atau entry spekulatif.';
        $lines[] = '──────────────────────────────────────────';
        $lines[] = "Dipublikasikan oleh Aether Market Intelligence pada {$nowWib->format('d/m/Y H:i:s')} WIB";

        $reportText = implode("\n", $lines);

        if ($dryRun) {
            $this->line($reportText);
            $this->info('[PREVIEW / DRY-RUN] Selesai tanpa membuat outbox atau rekod eksekusi produksi.');

            return Command::SUCCESS;
        }

        $cleanSlot = str_replace(':', '', $slot);
        $dedupKey = "MARKET_UPDATE_{$nowWib->format('Ymd')}_{$cleanSlot}";

        // Idempotency check: if already sent, do not re-broadcast or throw constraint errors
        $existing = TelegramOutbox::where('dedup_key', $dedupKey)->first();
        if ($existing && $existing->status === 'SENT') {
            $this->info("Slot {$slot} hari ini sudah terkirim (Outbox #{$existing->id}, Msg ID #{$existing->telegram_message_id}). Menolak penyiaran ulang.");

            return Command::SUCCESS;
        }

        $dueAt = Carbon::createFromFormat('Y-m-d H:i', "{$nowWib->format('Y-m-d')} {$slot}", 'Asia/Jakarta')->setTimezone('UTC');
        $runId = "RUN_UPDATE_{$nowWib->format('Ymd')}_{$cleanSlot}_".Str::lower(Str::random(4));
        $chatId = config('services.telegram.chat_id', '5923895922');
        $triggerSource = $this->input->isInteractive() ? 'MANUAL_CLI' : 'SCHEDULED';

        AnalysisRun::firstOrCreate(
            ['run_id' => $runId],
            [
                'trigger_type' => "market_update_{$cleanSlot}",
                'status' => 'completed',
                'started_at' => $nowWib,
                'completed_at' => now('Asia/Jakarta'),
                'metadata' => [
                    'as_of_session' => $prevDateStr,
                    'slot' => $slot,
                    'report_type' => 'MARKET_UPDATE',
                    'quote_intraday_status' => 'QUARANTINED',
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
                'report_type' => 'MARKET_UPDATE',
                'slot' => $slot,
                'briefing_date' => $nowWib->format('Y-m-d'),
                'session_reference' => $prevDateStr,
                'run_id' => $runId,
                'chat_id' => $chatId,
                'due_at_wib' => "{$nowWib->format('Y-m-d')} {$slot} WIB",
            ]
        );

        $this->info("Pembaruan pasar slot {$slot} berhasil diantrekan (Outbox #{$outboxItem->id}, Status: {$outboxItem->status}, Due At: {$dueAt->toIso8601String()}).");

        return Command::SUCCESS;
    }
}
