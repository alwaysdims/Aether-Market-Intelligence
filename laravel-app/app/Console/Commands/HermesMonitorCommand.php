<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AnalysisRun;
use App\Models\MarketAnalysis;
use App\Models\MarketSnapshot;
use App\Models\NotificationLog;
use App\Models\PostHorizonEvaluation;
use App\Services\BeiCalendarService;
use App\Services\PostHorizonEvaluatorService;
use App\Services\SystemHealthService;
use Carbon\Carbon;

class HermesMonitorCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hermes:monitor';

    /**
     * The console command description.
     */
    protected $description = 'Tampilkan audit ringkasan status operasional harian, kesehatan pipeline, dan evaluasi post-horizon';

    /**
     * Execute the console command.
     */
    public function handle(
        BeiCalendarService $calendar,
        PostHorizonEvaluatorService $evaluator,
        SystemHealthService $healthService
    ) {
        $nowWib = Carbon::now('Asia/Jakarta');
        $this->info("===============================================================================");
        $this->info("         AUDIT OPERASIONAL & MONITORING HARIAN — AETHER MARKET INTELLIGENCE     ");
        $this->info("         Waktu Audit: " . $nowWib->format('d M Y, H:i:s') . " WIB                     ");
        $this->info("===============================================================================");

        // 1. Pipeline & Scheduler Execution Status
        $lastRun = AnalysisRun::latest('started_at')->first();
        $nextRun = $calendar->getNextScheduledRunTime($nowWib);
        $isTradingDay = $calendar->isTradingDay($nowWib);
        $isEodFinal = $calendar->isEodFinal($nowWib);

        $this->line("");
        $this->info("[1. PIPELINE & PENJADWALAN]");
        $runStatus = $lastRun ? strtoupper($lastRun->status) : 'BELUM PERNAH BERJALAN';
        $runId = $lastRun ? $lastRun->run_id : 'N/A';
        $runTime = $lastRun ? $lastRun->started_at->timezone('Asia/Jakarta')->format('d M Y H:i:s') . ' WIB' : 'N/A';
        $duration = $lastRun ? ($lastRun->duration_seconds ?? 0) . ' detik' : 'N/A';

        $this->table(['Parameter', 'Status / Nilai'], [
            ['Eksekusi Terakhir', "{$runId} ({$runTime})"],
            ['Status Hasil Run', $runStatus],
            ['Durasi Pemrosesan', $duration],
            ['Trigger Terakhir', $lastRun ? strtoupper($lastRun->trigger_type) : 'N/A'],
            ['Status Hari Bursa', $isTradingDay ? 'HARI PERDAGANGAN AKTIF' : 'LIBUR / AKHIR PEKAN'],
            ['Status Finalitas Candle', $isEodFinal ? 'FINAL EOD (>= 16:00 WIB)' : 'BELUM FINAL / INTRADAY'],
            ['Jadwal Eksekusi Berikutnya', $nextRun->format('d M Y, H:i') . ' WIB (16:05 WIB di Hari Bursa)'],
        ]);

        // 2. Market Data Ingestion Status
        $this->line("");
        $this->info("[2. INGESTI DATA PASAR (EOD OHLCV)]");
        $snapshotsCount = MarketSnapshot::count();
        $latestSnap = MarketSnapshot::latest('bar_date')->first();
        $distinctTickers = MarketSnapshot::distinct()->pluck('ticker')->toArray();
        $expectedTickers = ['BBCA', 'BBRI', 'BMRI', 'BBNI', 'ASII', 'TLKM', 'MAPI', 'ARTO', 'MEDC', 'SMRA'];
        $missingTickers = array_diff($expectedTickers, $distinctTickers);

        $this->table(['Parameter Data', 'Kondisi'], [
            ['Sumber Data', 'Yahoo Finance Daily EOD (via hermes_adapter.py)'],
            ['Bar Date Terakhir', $latestSnap ? $latestSnap->bar_date->format('d M Y') : 'N/A'],
            ['Cakupan Universe Saham', count($distinctTickers) . ' / ' . count($expectedTickers) . ' saham aktif'],
            ['Saham Hilang / Gagal', empty($missingTickers) ? 'NIHIL (Semua 10 saham sinkron)' : implode(', ', $missingTickers)],
            ['Total Bar Snapshot Tersimpan', "{$snapshotsCount} bar di database"],
        ]);

        // 3. AI Inference & Fallback Status
        $this->line("");
        $this->info("[3. MESIN AI & VALIDASI KUALITAS]");
        $lastAnalysis = MarketAnalysis::latest()->first();
        $totalAnalyses = MarketAnalysis::count();
        $fallbackAnalyses = MarketAnalysis::where('validation_status', 'fallback')->count();
        $validAnalyses = MarketAnalysis::where('validation_status', 'valid_schema')->count();

        $aiStatus = 'DOWN';
        $healthAi = $healthService->checkAiModel();
        if (($healthAi['status'] ?? '') === 'healthy') {
            $aiStatus = 'HEALTHY (:20128)';
        } elseif (($healthAi['status'] ?? '') === 'degraded') {
            $aiStatus = 'DEGRADED';
        }

        $this->table(['Komponen AI', 'Kondisi Terkini'], [
            ['Model Resmi Terkunci', 'ag/gemini-3.8-flash-high (Router 9router lokal)'],
            ['Status Konektivitas Endpoint', $aiStatus],
            ['Status Validasi Terakhir', $lastAnalysis ? strtoupper($lastAnalysis->validation_status) : 'N/A'],
            ['Arah Dampak Terakhir', $lastAnalysis ? strtoupper($lastAnalysis->impact_direction) : 'N/A'],
            ['Distribusi Analisis', "Valid: {$validAnalyses} | Fallback: {$fallbackAnalyses} | Total: {$totalAnalyses}"],
        ]);

        // 4. Telegram Notification Logs
        $this->line("");
        $this->info("[4. KANAL NOTIFIKASI TELEGRAM (@Info_BosKuu_bot)]");
        $tgSent = NotificationLog::where('channel', 'telegram')->where('status', 'sent')->count();
        $tgFailed = NotificationLog::where('channel', 'telegram')->where('status', 'failed')->count();
        $tgPending = NotificationLog::where('channel', 'telegram')->where('status', 'pending')->count();
        $tgSkipped = NotificationLog::where('channel', 'telegram')->where('status', 'skipped_duplicate')->count();
        $lastSent = NotificationLog::where('channel', 'telegram')->where('status', 'sent')->latest('sent_at')->first();

        $this->table(['Metrik Notifikasi', 'Nilai'], [
            ['Terkirim Sukses', "{$tgSent} pesan"],
            ['Gagal Kirim', "{$tgFailed} pesan"],
            ['Pending di Antrean', "{$tgPending} pesan"],
            ['Deduplikasi Dicegah (Skipped)', "{$tgSkipped} pesan (Anti-spam aktif)"],
            ['Pengiriman Terakhir', $lastSent ? $lastSent->sent_at->timezone('Asia/Jakarta')->format('d M Y H:i:s') . ' WIB' : 'Belum ada'],
        ]);

        // 5. Post-Horizon Evaluation Summary (+5 Hari Bursa)
        $this->line("");
        $this->info("[5. POST-HORIZON EVALUATOR (+5 HARI BURSA)]");
        $metrics = $evaluator->getMetricsSummary();

        // Count pending that are already due as of today vs not due
        $allPending = PostHorizonEvaluation::where('status', 'pending')->get();
        $dueReadyCount = 0;
        $notDueCount = 0;
        $todayStr = $nowWib->format('Y-m-d');

        foreach ($allPending as $p) {
            $targetStr = $p->target_eval_date->format('Y-m-d');
            if ($todayStr >= $targetStr) {
                $dueReadyCount++;
            } else {
                $notDueCount++;
            }
        }

        $this->table(['Metrik Evaluasi', 'Nilai'], [
            ['Total Prediksi Terdaftar', $metrics['total_registered'] . ' instrumen'],
            ['Belum Jatuh Tempo (Menunggu)', "{$notDueCount} prediksi (Target: +5 hari bursa)"],
            ['Jatuh Tempo Siap Selesai', "{$dueReadyCount} prediksi"],
            ['Selesai Dievaluasi', $metrics['total_evaluated'] . ' prediksi'],
            ['Tidak Dapat Dievaluasi', $metrics['total_cannot_evaluate'] . ' (Data hilang/suspensi)'],
            ['Akurasi Directional AI Murni', $metrics['ai_accuracy_pct'] . '% (' . $metrics['ai_evaluated_count'] . ' sampel dievaluasi)'],
            ['Akurasi Gabungan (Inc. Fallback)', $metrics['accuracy_pct'] . '%'],
            ['Baseline Always Positive (Buy & Hold)', $metrics['baseline_always_positive_pct'] . '%'],
        ]);

        // 6. Error & Recovery Actions
        $this->line("");
        $this->info("[6. LOG ERROR & REKOMENDASI PEMULIHAN]");
        $recentErrors = NotificationLog::where('status', 'failed')
            ->where('created_at', '>=', now()->subHours(24))
            ->latest()
            ->take(3)
            ->get();

        if ($recentErrors->isEmpty()) {
            $this->info(" -> NIHIL: Tidak ada error kegagalan dalam 24 jam terakhir. Sistem beroperasi normal.");
        } else {
            foreach ($recentErrors as $err) {
                $this->warn(" • [{$err->created_at->format('H:i:s')}] " . substr($err->error_message ?? $err->message_payload, 0, 100) . "...");
            }
            $this->line(" Tindakan Pemulihan Disarankan:");
            $this->line("  1. Periksa koneksi PostgreSQL di port 5432: 'php artisan db:monitor'");
            $this->line("  2. Periksa router AI di port 20128: curl http://127.0.0.1:20128/v1/models");
            $this->line("  3. Jalankan ulang sinkronisasi aman: 'php artisan hermes:sync-eod --force'");
        }

        $this->info("===============================================================================");
        return Command::SUCCESS;
    }
}
