<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AnalysisRun;
use App\Models\MarketSnapshot;
use App\Models\NewsItem;
use App\Models\DataSource;
use App\Services\HermesAdapterService;
use App\Services\AiMarketAnalysisService;
use Illuminate\Support\Str;

class HermesSyncEodCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hermes:sync-eod {--trigger=cli : Trigger type (cli, scheduled, manual)}';

    /**
     * The console command description.
     */
    protected $description = 'Sinkronisasi data EOD BEI, broker summary, berita resmi, dan inferensi AI ke PostgreSQL';

    /**
     * Execute the console command.
     */
    public function handle(HermesAdapterService $adapter, AiMarketAnalysisService $aiService)
    {
        $startTime = microtime(true);
        $runId = 'RUN_' . now()->format('Ymd_His') . '_' . Str::lower(Str::random(4));
        $trigger = $this->option('trigger') ?: 'cli';

        $this->info("=== MEMULAI SINKRONISASI EOD HERMES [{$runId}] ===");

        // 1. Catat AnalysisRun awal
        $run = AnalysisRun::create([
            'run_id' => $runId,
            'trigger_type' => $trigger,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            // 2. Ambil Snapshot Pasar EOD
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

            // 3. Ambil Berita Pasar Resmi
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

            // 4. Jalankan Analisis AI Gemini 3.8 Flash High
            $this->line("3. Menjalankan inferensi intelijen pasar AI (ag/gemini-3.8-flash-high)...");
            $recentNewsList = $newsResp['data']['items'] ?? [];
            $analysis = $aiService->analyze($run, $marketSummaryText, $recentNewsList);

            $this->info("   -> Analisis AI selesai. Arah: " . strtoupper($analysis->impact_direction) . " (Status Validasi: {$analysis->validation_status})");

            // 5. Update Status Run
            $duration = round(microtime(true) - $startTime, 2);
            $run->update([
                'status' => 'completed',
                'completed_at' => now(),
                'duration_seconds' => $duration,
                'metadata' => [
                    'snapshots_count' => $insertedSnapshots,
                    'news_count' => $insertedNews,
                    'impact_direction' => $analysis->impact_direction,
                ],
            ]);

            $this->info("=== PIPELINE EOD SELESAI SUKSES DALAM {$duration} DETIK ===");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);
            $run->update([
                'status' => 'failed',
                'completed_at' => now(),
                'duration_seconds' => $duration,
                'error_message' => $e->getMessage(),
            ]);

            $this->error("=== PIPELINE EOD GAGAL: " . $e->getMessage() . " ===");
            return Command::FAILURE;
        }
    }
}
