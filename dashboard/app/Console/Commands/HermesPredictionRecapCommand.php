<?php

namespace App\Console\Commands;

use App\Services\PredictionRecapService;
use App\Services\TelegramFormattingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class HermesPredictionRecapCommand extends Command
{
    protected $signature = 'hermes:prediction-recap {--telegram : Enqueue recap to Telegram outbox}';

    protected $description = 'Generate comprehensive prediction recap separating forward predictions from historical fallbacks';

    public function handle(PredictionRecapService $recapService, TelegramFormattingService $formatter): int
    {
        $this->info('=== AETHER MARKET INTELLIGENCE — REKAP PREDIKSI ===');

        $stats = $recapService->generateRecapMetrics();
        $nowWib = Carbon::now('Asia/Jakarta');

        $this->table(
            ['Metrik Kohort Forward', 'Nilai'],
            [
                ['Total Prediksi Forward', $stats['total_signals']],
                ['Terbit (Directional)', $stats['published_directional']],
                ['Abstain (NO_SIGNAL)', $stats['no_signal_abstained']],
                ['Coverage Rasio', $stats['coverage_pct']],
                ['Sinyal Aktif Dipantau', $stats['active']],
                ['Target Tersentuh', $stats['target_hit']],
                ['Terbatal (Invalidated)', $stats['invalidated']],
                ['Ambigu (Same-bar)', $stats['ambiguous']],
                ['Kedaluwarsa (14 Hari)', $stats['expired']],
                ['Target Touch Rate', $stats['target_touch_rate']],
                ['Evaluasi 5-Hari Pending', $stats['eval_pending']],
                ['Evaluasi 5-Hari Benar', $stats['eval_correct']],
                ['Evaluasi 5-Hari Salah', $stats['eval_incorrect']],
                ['Evaluasi 5-Hari Netral/Abstain', $stats['eval_neutral']],
                ['Akurasi Arah (Directional)', $stats['directional_accuracy']],
            ]
        );

        $this->table(
            ['Isolasi Fallback Historis', 'Nilai'],
            [
                ['Total Record Fallback Lama', $stats['historical_fallback_evaluations']],
                ['Status Neutral (Non-directional)', $stats['historical_fallback_neutral']],
                ['Keterangan', $stats['historical_fallback_note']],
            ]
        );

        if ($this->option('telegram')) {
            $payload = $formatter->formatHorizonRecapMessage($stats, $nowWib);
            $chatId = (string) config('services.telegram.chat_id', 'global');
            $evalPeriodDate = $nowWib->format('Ymd');
            $cohortHash = substr(md5(json_encode([
                'forward_total' => $stats['total_signals'] ?? 0,
                'eval_correct' => $stats['eval_correct'] ?? 0,
                'eval_incorrect' => $stats['eval_incorrect'] ?? 0,
                'eval_pending' => $stats['eval_pending'] ?? 0,
            ])), 0, 8);

            // Granular dedup key: chat_id + strategy/cohort + eval_period + result_version_hash
            $dedupKey = "RECAP_COHORT_V1_{$chatId}_{$evalPeriodDate}_{$cohortHash}";
            $item = $formatter->enqueueOutbox($dedupKey, 'HORIZON_RECAP', $payload);
            $this->info("Recap successfully enqueued to Telegram Outbox (ID: {$item->id}, DedupKey: {$dedupKey}).");
        }

        return 0;
    }
}
