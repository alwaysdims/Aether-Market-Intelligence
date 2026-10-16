<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AnalysisRun;
use App\Models\MarketSnapshot;
use App\Models\NewsItem;
use App\Models\DataSource;
use App\Models\NotificationLog;
use App\Services\HermesAdapterService;
use App\Services\AiMarketAnalysisService;
use App\Services\BeiCalendarService;
use App\Services\PostHorizonEvaluatorService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class HermesSyncEodCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hermes:sync-eod {--trigger=cli : Trigger type (cli, scheduled, manual)} {--force : Abaikan validasi hari bursa / finalitas waktu} {--no-telegram : Jangan kirim notifikasi ke Telegram}';

    /**
     * The console command description.
     */
    protected $description = 'Sinkronisasi data EOD BEI, broker summary, berita resmi, dan inferensi AI ke PostgreSQL';

    /**
     * Execute the console command.
     */
    public function handle(
        HermesAdapterService $adapter,
        AiMarketAnalysisService $aiService,
        BeiCalendarService $calendar,
        PostHorizonEvaluatorService $evaluator,
        \App\Services\TelegramNotificationService $telegram
    ) {
        $startTime = microtime(true);
        $trigger = $this->option('trigger') ?: 'cli';
        $force = (bool) $this->option('force');
        $nowWib = now('Asia/Jakarta');

        // 1. Validasi Kalender Bursa & Finalitas Candle untuk Trigger Terjadwal
        if ($trigger === 'scheduled' && !$force) {
            if (!$calendar->isTradingDay($nowWib)) {
                $this->info("[SCHEDULED] Hari ini ({$nowWib->format('Y-m-d')}) bukan hari bursa aktif BEI. Sinkronisasi dilewati.");
                return Command::SUCCESS;
            }

            if (!$calendar->isEodFinal($nowWib)) {
                $this->warn("[SCHEDULED] Waktu saat ini ({$nowWib->format('H:i')} WIB) sebelum jam penutupan bursa (16:00 WIB). Data EOD belum final. Sinkronisasi dibatalkan.");
                return Command::SUCCESS;
            }
        }

        // 2. Cegah Job Berjalan Bersamaan (Mutex Lock 10 menit)
        $lock = Cache::lock('hermes_sync_eod_mutex', 600);
        if (!$lock->get()) {
            $this->warn("Sinkronisasi EOD sedang berjalan oleh proses lain. Eksekusi dibatalkan untuk mencegah tumpang tindih.");
            return Command::FAILURE;
        }

        $runId = 'RUN_' . $nowWib->format('Ymd_His') . '_' . Str::lower(Str::random(4));
        $this->info("=== MEMULAI SINKRONISASI EOD HERMES [{$runId}] ===");

        // 3. Catat AnalysisRun awal
        $run = AnalysisRun::create([
            'run_id' => $runId,
            'trigger_type' => $trigger,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            // 4. Ambil Snapshot Pasar EOD
            $this->line("1. Mengambil market snapshots dari Hermes Stock Agent...");
            $snapResp = $adapter->getMarketSnapshots();

            $insertedSnapshots = 0;
            $marketSummaryText = "Rekapitulasi Pasar Saham BEI:\n";

            if ($snapResp['ok'] && !empty($snapResp['data']['snapshots'])) {
                $ds = DataSource::where('type', 'market_data')->first();
                if ($ds) {
                    $ds->update(['last_success_at' => now(), 'status' => 'active']);
                }

                foreach ($snapResp['data']['snapshots'] as $s) {
                    MarketSnapshot::create([
                        'data_source_id' => $ds?->id,
                        'analysis_run_id' => $run->id,
                        'ticker' => $s['ticker'],
                        'bar_date' => $s['bar_date'],
                        'source_timestamp' => $s['source_timestamp'],
                        'open_price' => $s['open_price'],
                        'high_price' => $s['high_price'],
                        'low_price' => $s['low_price'],
                        'close_price' => $s['close_price'],
                        'volume' => $s['volume'],
                        'data_classification' => $s['data_classification'],
                        'indicators' => $s['indicators'],
                    ]);
                    $insertedSnapshots++;

                    $marketSummaryText .= "- {$s['ticker']}: Close Rp" . number_format($s['close_price']) . " (ATR: {$s['indicators']['atr14']}, RVOL: {$s['indicators']['rvol20']}x)\n";
                }
                $this->info("   -> Berhasil menyimpan {$insertedSnapshots} snapshot pasar.");
            } else {
                $this->warn("   -> Tidak ada snapshot pasar baru / peringatan: " . ($snapResp['error'] ?? 'None'));
            }

            // 5. Ambil Berita Pasar Resmi
            $this->line("2. Mengambil berita pasar modal dari feed resmi...");
            $newsResp = $adapter->fetchNewsFeed(null, 10);
            $insertedNews = 0;

            if ($newsResp['ok'] && !empty($newsResp['data']['items'])) {
                $dsNews = DataSource::where('type', 'news')->first();
                if ($dsNews) {
                    $dsNews->update(['last_success_at' => now(), 'status' => 'active']);
                }

                foreach ($newsResp['data']['items'] as $item) {
                    NewsItem::updateOrInsert(
                        ['url' => $item['url']],
                        [
                            'title' => $item['title'],
                            'source_name' => $item['source_name'],
                            'published_at' => now(),
                            'fetched_at' => now(),
                            'summary' => $item['summary'],
                            'verification_status' => $item['verification_status'],
                            'related_tickers' => json_encode($item['related_tickers']),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                    $insertedNews++;
                }
                $this->info("   -> Berhasil menyinkronkan {$insertedNews} berita terverifikasi.");
            }

            // 6. Jalankan Analisis AI Gemini 3.8 Flash High
            $this->line("3. Menjalankan inferensi intelijen pasar AI (ag/gemini-3.8-flash-high)...");
            $recentNewsList = $newsResp['data']['items'] ?? [];
            $analysis = $aiService->analyze($run, $marketSummaryText, $recentNewsList);

            $this->info("   -> Analisis AI selesai. Arah: " . strtoupper($analysis->impact_direction) . " (Status Validasi: {$analysis->validation_status})");

            // 7. Daftarkan Prediksi ke Post-Horizon Evaluator & Jalankan Evaluasi Pending
            $this->line("4. Mendaftarkan prediksi ke post-horizon evaluator (5 hari bursa)...");
            $evalPredictions = $evaluator->registerPredictions($analysis, 5);
            $this->info("   -> Terdaftar " . count($evalPredictions) . " instrumen evaluasi horizon.");

            $evalResult = $evaluator->evaluatePending($nowWib);
            $this->line("   -> Evaluasi pending: {$evalResult['evaluated_count']} selesai, {$evalResult['skipped_not_due']} belum jatuh tempo.");

            // 8. Update Status Run
            $duration = round(microtime(true) - $startTime, 2);
            $run->update([
                'status' => 'completed',
                'completed_at' => now(),
                'duration_seconds' => $duration,
                'metadata' => [
                    'snapshots_count' => $insertedSnapshots,
                    'news_count' => $insertedNews,
                    'impact_direction' => $analysis->impact_direction,
                    'evaluations_registered' => count($evalPredictions),
                ],
            ]);

            // 9. Kirim Ringkasan Laporan ke Telegram
            if (!$this->option('no-telegram')) {
                $this->line("5. Mengirim ringkasan laporan ke Telegram @Info_BosKuu_bot...");
                $tgResult = $telegram->sendEodNotification($run, $analysis);
                if ($tgResult['status'] === 'sent') {
                    $this->info("   -> Notifikasi Telegram terkirim sukses (Log ID: {$tgResult['log_id']}).");
                } elseif ($tgResult['status'] === 'skipped_duplicate') {
                    $this->line("   -> " . $tgResult['message']);
                } else {
                    $this->warn("   -> Pengiriman Telegram gagal: " . ($tgResult['error'] ?? 'Unknown error') . " (Hasil analisis tetap tersimpan aman).");
                }
            }

            $this->info("=== PIPELINE EOD SELESAI SUKSES DALAM {$duration} DETIK ===");
            $lock->release();
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);
            $run->update([
                'status' => 'failed',
                'completed_at' => now(),
                'duration_seconds' => $duration,
                'error_message' => $e->getMessage(),
            ]);

            // Kirim peringatan error dengan proteksi cooldown 6 jam
            $telegram->sendErrorAlert($e->getMessage(), [
                'run_id' => $runId,
                'source' => 'HermesSyncEodCommand',
            ]);

            $this->error("=== PIPELINE EOD GAGAL: " . $e->getMessage() . " ===");
            $lock->release();
            return Command::FAILURE;
        }
    }
}
