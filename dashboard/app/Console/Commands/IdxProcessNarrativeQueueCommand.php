<?php

namespace App\Console\Commands;

use App\Models\CompanyAnalysisMetric;
use App\Models\MarketAnalysis;
use App\Models\MarketObservation;
use App\Models\NewsItem;
use App\Models\PredictionSignal;
use App\Services\AiMarketAnalysisService;
use App\Services\BeiUniverseRegistry;
use App\Services\StockScreeningService;
use Illuminate\Console\Command;

class IdxProcessNarrativeQueueCommand extends Command
{
    protected $signature = 'idx:process-narratives {--limit=2 : Jumlah emiten diproses per siklus} {--ticker= : Ticker spesifik}';

    protected $description = 'Process background queue for Gemini AI narratives with evidence cache and concurrency limits';

    public function handle(
        AiMarketAnalysisService $aiService,
        StockScreeningService $screeningService
    ): int {
        $limit = max(1, (int) $this->option('limit'));
        $specificTicker = $this->option('ticker') ? strtoupper(trim($this->option('ticker'))) : null;

        $targetTickers = [];
        if ($specificTicker) {
            $targetTickers = [$specificTicker];
        } else {
            // 1. Prioritas A: Kandidat yang lolos screening SWING
            $screening = $screeningService->screenCandidates('SWING');
            $screeningTickers = collect($screening['candidates'])->pluck('ticker')->toArray();

            // 2. Prioritas B: Konstituen tervalidasi ML (10 tickers)
            $mlTickers = array_keys(BeiUniverseRegistry::VALIDATED_ML_UNIVERSE);

            // 3. Prioritas C: Emiten yang memiliki data harga dan metrik
            $allEligibleTickers = MarketObservation::whereNotIn('ticker', ['^JKSE', 'JKSE', 'IHSG'])
                ->distinct('ticker')
                ->pluck('ticker')
                ->toArray();

            $ordered = array_values(array_unique(array_merge($screeningTickers, $mlTickers, $allEligibleTickers)));

            // Filter out tickers that ALREADY have an up-to-date valid narrative for their latest bar
            foreach ($ordered as $t) {
                $latestObs = MarketObservation::where('ticker', $t)->latest('bar_date')->first();
                if (! $latestObs) {
                    continue;
                }
                $barDateStr = $latestObs->bar_date->format('Y-m-d');
                $hasAnalysis = MarketAnalysis::whereJsonContains('structured_output->ticker', $t)
                    ->whereJsonContains('structured_output->bar_date', $barDateStr)
                    ->where('validation_status', 'valid_schema')
                    ->exists();

                if (! $hasAnalysis) {
                    $targetTickers[] = $t;
                    if (count($targetTickers) >= $limit) {
                        break;
                    }
                }
            }
        }

        if (empty($targetTickers)) {
            $this->info('Seluruh emiten terdaftar telah memiliki narasi AI terkini untuk bar terakhir.');

            return Command::SUCCESS;
        }

        $this->info('Memproses antrean narasi Gemini untuk '.count($targetTickers).' emiten...');

        $processed = 0;
        $failed = 0;
        $promptTokens = 0;
        $completionTokens = 0;
        $totalTokens = 0;

        foreach ($targetTickers as $t) {
            $obs = MarketObservation::where('ticker', $t)->latest('bar_date')->first();
            if (! $obs) {
                $this->warn("• {$t}: Data observasi bar harga tidak ditemukan.");
                $failed++;

                continue;
            }

            $sig = PredictionSignal::where('ticker', $t)->latest('decision_date')->first();
            $news = NewsItem::where('related_tickers', 'like', "%{$t}%")->latest('published_at')->first();
            $metric = CompanyAnalysisMetric::where('ticker', $t)->latest('as_of_date')->first();

            $this->line("• Menghasilkan narasi AI untuk {$t} (Bar: {$obs->bar_date->format('Y-m-d')})...");

            $res = $aiService->generateOnDemandTickerExplanation(
                $t,
                $obs,
                $sig,
                null,
                $news,
                $metric
            );

            if ($res['success'] ?? false) {
                $cacheNotice = ! empty($res['is_cached']) ? ' [CACHED]' : ' [LIVE_AI]';
                $usage = $res['usage'] ?? [];
                $pTokens = $usage['prompt_tokens'] ?? 0;
                $cTokens = $usage['completion_tokens'] ?? 0;
                $tTokens = $usage['total_tokens'] ?? 0;

                $promptTokens += $pTokens;
                $completionTokens += $cTokens;
                $totalTokens += $tTokens;

                $this->info("   -> Sukses{$cacheNotice}: {$t} (Analysis ID: {$res['analysis_id']}, Latency: {$res['latency_ms']}ms, Tokens: {$tTokens})");
                $processed++;
            } else {
                $reason = $res['fallback_reason'] ?? 'Gagal menghasilkan narasi';
                $this->warn("   -> Dilewati: {$t} ({$reason})");
                $failed++;
            }

            usleep(200000); // 200ms spacing
        }

        // Global statistics summary
        $allEligibleCount = MarketObservation::whereNotIn('ticker', ['^JKSE', 'JKSE', 'IHSG'])->distinct('ticker')->count('ticker');
        $allCompletedCount = MarketAnalysis::where('validation_status', 'valid_schema')
            ->distinct('structured_output->ticker')
            ->count();
        $allPendingCount = max(0, $allEligibleCount - $allCompletedCount);

        $this->info('=== RINGKASAN ANTREAN NARASI GEMINI ===');
        $this->line("• Populasi Eligible : {$allEligibleCount} emiten");
        $this->line("• Narasi Tersimpan  : {$allCompletedCount} emiten (Completed)");
        $this->line("• Menunggu Narasi   : {$allPendingCount} emiten (Pending)");
        $this->line("• Batch Berjalan    : Sukses: {$processed}, Gagal: {$failed}");
        $this->line("• Token Batch Ini   : Prompt: {$promptTokens}, Completion: {$completionTokens}, Total: {$totalTokens}");

        return Command::SUCCESS;
    }
}
