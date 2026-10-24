<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PostHorizonEvaluatorService;

class HermesEvaluateHorizonCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hermes:evaluate-horizon {--force : Evaluasi ulang record; tetap mematuhi tanggal target dan finalitas} {--as-of= : Tanggal evaluasi as-of (YYYY-MM-DD)}';

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
        $asOf = $asOfStr 
            ? (str_contains($asOfStr, ':') ? \Carbon\Carbon::parse($asOfStr, 'Asia/Jakarta') : \Carbon\Carbon::parse($asOfStr, 'Asia/Jakarta')->startOfDay()) 
            : now('Asia/Jakarta');

        $this->line("Tanggal As-Of Evaluasi: " . $asOf->format('Y-m-d H:i:s') . ($force ? " [MODE FORCE]" : ""));

        $res = $evaluator->evaluatePending($asOf, $force);

        $this->info("Hasil Pemrosesan:");
        $this->line(" - Dievaluasi Selesai     : {$res['evaluated_count']}");
        $this->line(" - Belum Jatuh Tempo      : {$res['skipped_not_due']}");
        $this->line(" - Menunggu Penutupan     : {$res['waiting_for_close_count']}");
        $this->line(" - Menunggu Harga Target  : {$res['waiting_for_data_count']}");
        $this->line(" - Data Tidak Ada / Gugur : {$res['missing_data_count']}");

        $metrics = $evaluator->getMetricsSummary();

        $accStr = $metrics['accuracy_pct'] !== null ? "{$metrics['accuracy_pct']}%" : 'N/A';
        $aiAccStr = $metrics['ai_accuracy_pct'] !== null ? "{$metrics['ai_accuracy_pct']}% ({$metrics['ai_evaluated_count']} sampel)" : 'N/A';
        $baseStr = $metrics['baseline_always_positive_pct'] !== null ? "{$metrics['baseline_always_positive_pct']}%" : 'N/A';

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
                ['Akurasi Directional AI Murni', $aiAccStr],
                ['Akurasi Directional Gabungan', $accStr],
                ['Baseline Always Positive (%)', $baseStr],
            ]
        );

        return Command::SUCCESS;
    }
}
