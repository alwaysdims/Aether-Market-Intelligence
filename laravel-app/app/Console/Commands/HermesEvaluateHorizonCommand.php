<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PostHorizonEvaluatorService;

class HermesEvaluateHorizonCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hermes:evaluate-horizon {--force : Paksa evaluasi tanpa menunggu target date (untuk simulasi)} {--as-of= : Tanggal evaluasi as-of (YYYY-MM-DD)}';

    /**
     * The console command description.
     */
    protected $description = 'Evaluasi akurasi prediksi arah AI terhadap pergerakan pasar 5 hari bursa setelah keputusan';

    /**
     * Execute the console command.
     */
    public function handle(PostHorizonEvaluatorService $evaluator)
    {
        $this->info("=== MEMULAI EVALUASI POST-HORIZON 5 HARI BURSA ===");

        $force = (bool) $this->option('force');
        $asOfStr = $this->option('as-of');
        $asOf = $asOfStr ? \Carbon\Carbon::parse($asOfStr, 'Asia/Jakarta') : now('Asia/Jakarta');

        $this->line("Tanggal As-Of Evaluasi: " . $asOf->format('Y-m-d') . ($force ? " [MODE FORCE]" : ""));

        $res = $evaluator->evaluatePending($asOf, $force);

        $this->info("Hasil Pemrosesan:");
        $this->line(" - Dievaluasi Selesai : {$res['evaluated_count']}");
        $this->line(" - Belum Jatuh Tempo  : {$res['skipped_not_due']}");
        $this->line(" - Data Tidak Ada     : {$res['missing_data_count']}");

        $metrics = $evaluator->getMetricsSummary();

        $this->newLine();
        $this->info("=== RINGKASAN METRIK PERFORMA AKURASI AI ===");
        $this->table(
            ['Metrik', 'Nilai'],
            [
                ['Total Prediksi Terdaftar', $metrics['total_registered']],
                ['Menunggu Jatuh Tempo (Pending)', $metrics['total_pending']],
                ['Sudah Dievaluasi', $metrics['total_evaluated']],
                ['Tidak Dapat Dievaluasi', $metrics['total_cannot_evaluate']],
                ['Prediksi Arah Benar (Correct)', $metrics['correct_count']],
                ['Prediksi Arah Salah (Incorrect)', $metrics['incorrect_count']],
                ['Prediksi Netral / Kualitatif', $metrics['neutral_count']],
                ['Akurasi Directional (%)', $metrics['accuracy_pct'] . '%'],
                ['Baseline Always Positive (%)', $metrics['baseline_always_positive_pct'] . '%'],
            ]
        );

        return Command::SUCCESS;
    }
}
