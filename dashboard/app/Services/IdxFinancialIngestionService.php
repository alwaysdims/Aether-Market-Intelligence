<?php

namespace App\Services;

use App\Models\CompanyAnalysisMetric;
use App\Models\CompanyCorporateAction;
use App\Models\CompanyFundamental;
use App\Models\IdxCompanyRegistry;
use App\Models\IdxIngestionJob;
use App\Models\MarketAnalysis;
use App\Models\MarketObservation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class IdxFinancialIngestionService
{
    public function __construct(
        protected IdxEdgeClient $client,
        protected TechnicalMetricsCalculationService $metricsService,
        protected IdxEdgeFundamentalIngestionService $fundamentalService
    ) {}

    /**
     * Ingest operational OHLCV from IDX Edge only. Missing fundamentals/actions do not block bars.
     *
     * @return array<string, mixed>
     */
    public function ingestTicker(string $ticker, bool $fullHistory = false): array
    {
        $ticker = strtoupper(trim($ticker));
        $limit = $fullHistory ? 500 : 20;
        $adjusted = $this->client->history($ticker, $limit, true, null, null, true);
        $raw = $this->client->history($ticker, $limit, false, null, null, true);

        if (! ($adjusted['success'] ?? false) || empty($adjusted['data']['bars'])) {
            return [
                'success' => false,
                'ticker' => $ticker,
                'error' => $adjusted['message'] ?? 'DATA_UNAVAILABLE: IDX Edge history adjusted.',
                'error_type' => $adjusted['error_type'] ?? 'DATA_UNAVAILABLE',
            ];
        }

        $registryName = IdxCompanyRegistry::where('ticker', $ticker)->value('company_name') ?? $ticker;
        $savedBars = 0;
        foreach ([['bars' => $adjusted['data']['bars'], 'mode' => 'PROVIDER_BACK_ADJUSTED'], ['bars' => $raw['data']['bars'] ?? [], 'mode' => 'UNADJUSTED']] as $series) {
            foreach ($series['bars'] as $bar) {
                if (($bar['validation_status'] ?? 'VALID') !== 'VALID') {
                    continue;
                }
                MarketObservation::updateOrCreate(
                    [
                        'ticker' => $ticker,
                        'bar_date' => $bar['date'],
                        'interval' => '1d',
                        'data_classification' => 'PUBLIC_MARKET_EOD',
                        'source' => 'IDX Edge PRO',
                        'adjustment_mode' => $series['mode'],
                    ],
                    [
                        'company_name' => $registryName,
                        'open_price' => $bar['open'],
                        'high_price' => $bar['high'],
                        'low_price' => $bar['low'],
                        'close_price' => $bar['close'],
                        'volume' => $bar['volume'],
                        'observation_time' => Carbon::parse($bar['date'].' 16:00:00', 'Asia/Jakarta')->utc(),
                        'source_timestamp' => Carbon::parse($bar['date'].' 16:00:00', 'Asia/Jakarta')->utc(),
                        'is_eod_final' => true,
                        'fetched_at' => now('UTC'),
                        'first_seen_at' => now('UTC'),
                        'available_at' => now('UTC'),
                        'timezone' => 'Asia/Jakarta',
                        'currency' => 'IDR',
                        'finality_basis' => 'IDX Edge PRO Pasar Reguler RG EOD',
                        'board_scope' => 'RG',
                        'validation_status' => 'VALID',
                        'data_hash' => hash('sha256', $ticker.'|'.$bar['date'].'|'.$bar['close'].'|'.$bar['volume'].'|'.$series['mode']),
                    ]
                );
                $savedBars++;
            }
        }

        $fundamental = $this->fundamentalService->syncAnnualStatements($ticker, 2);
        $fundamentalStatus = ($fundamental['success'] ?? false) ? 'PROVIDER_SUPPLIED' : 'DATA_UNAVAILABLE';
        $metrics = $this->metricsService->calculateForTicker($ticker);

        return [
            'success' => true,
            'ticker' => $ticker,
            'bars_stored' => $savedBars,
            'actions_stored' => 0,
            'reports_stored' => $fundamental['saved_records'] ?? 0,
            'technical_trend' => is_array($metrics) ? ($metrics['technical_trend'] ?? 'N/A') : 'CALCULATED',
            'fundamental_status' => $fundamentalStatus,
            'partial_reason' => ($fundamental['success'] ?? false) ? null : 'DATA_UNAVAILABLE',
            'missing_components' => ($fundamental['success'] ?? false) ? [] : ['FUNDAMENTAL_STATEMENTS'],
            'retryable' => ! ($fundamental['success'] ?? false),
            'financial_currency' => 'N/A',
            'source_trace' => ['market' => 'IDX Edge PRO', 'fundamental' => $fundamental['success'] ? 'IDX Edge PRO' : 'DATA_UNAVAILABLE', 'fallback' => 'NONE'],
        ];
    }

    /**
     * Run small canary test across 5 diverse sectors (Banking, Energy, Consumer, Tech, Property).
     */
    public function runCanaryTest(): array
    {
        $canaryTickers = ['BBCA', 'MEDC', 'ICBP', 'GOTO', 'PWON'];
        $results = [];

        foreach ($canaryTickers as $ticker) {
            $res = $this->ingestTicker($ticker, false);
            $results[$ticker] = $res;
            usleep(200000); // 200ms spacing to respect rate limit
        }

        $successCount = collect($results)->where('success', true)->count();

        return [
            'canary_total' => count($canaryTickers),
            'success_count' => $successCount,
            'is_canary_passed' => $successCount === count($canaryTickers),
            'results' => $results,
        ];
    }

    /**
     * Get aggregate ingestion coverage statistics across the entire 941 BEI registry.
     */
    public function getCoverageStats(): array
    {
        $totalCandidates = IdxCompanyRegistry::where('instrument_type', 'EQUITY')->count();
        $totalIndices = IdxCompanyRegistry::where('instrument_type', 'INDEX')->count();

        $jobsEnqueued = IdxIngestionJob::count();
        $notEnqueued = max(0, $totalCandidates - $jobsEnqueued);

        $pending = IdxIngestionJob::where('status', 'PENDING')->count();
        $running = IdxIngestionJob::where('status', 'RUNNING')->count();
        $completed = IdxIngestionJob::where('status', 'COMPLETED')->count();
        $partial = IdxIngestionJob::where('status', 'PARTIAL')->count();
        $failed = IdxIngestionJob::where('status', 'FAILED')->count();
        $blocked = IdxIngestionJob::where('error_message', 'like', '%BLOCKED%')->count();

        // Strict equity-only scope (excludes ^JKSE index benchmark and non-equity instruments)
        $equityTickers = IdxCompanyRegistry::where('instrument_type', 'EQUITY')->pluck('ticker');

        $withPrice = MarketObservation::whereIn('ticker', $equityTickers)->distinct('ticker')->count('ticker');
        $withFundamentals = CompanyFundamental::whereIn('ticker', $equityTickers)->distinct('ticker')->count('ticker');
        $totalReports = CompanyFundamental::whereIn('ticker', $equityTickers)->count();
        $withActions = CompanyCorporateAction::whereIn('ticker', $equityTickers)->distinct('ticker')->count('ticker');
        $withMetrics = CompanyAnalysisMetric::whereIn('ticker', $equityTickers)->distinct('ticker')->count('ticker');

        // Detailed indicator history sufficiency
        $barCounts = DB::table('market_observations')
            ->select('ticker', DB::raw('count(*) as cnt'))
            ->whereIn('ticker', $equityTickers)
            ->groupBy('ticker')
            ->get();

        $gte20 = $barCounts->where('cnt', '>=', 20)->count();
        $gte50 = $barCounts->where('cnt', '>=', 50)->count();
        $gte200 = $barCounts->where('cnt', '>=', 200)->count();

        // 1-Year Max Drawdown with verified calendar span and session sufficiency
        $full1yDrawdown = CompanyAnalysisMetric::whereIn('ticker', $equityTickers)
            ->whereNotNull('max_drawdown_1y_pct')
            ->get()
            ->filter(function ($m) {
                $status = $m->data_quality_flags['indicators_meta']['drawdown_meta']['status']
                    ?? $m->data_quality_flags['drawdown_meta']['status']
                    ?? null;

                return $status === 'FULL_1Y_CALCULATED';
            })
            ->pluck('ticker')
            ->unique()
            ->count();

        $validTech = CompanyAnalysisMetric::whereIn('ticker', $equityTickers)->where('technical_status', 'CALCULATED')->distinct('ticker')->count('ticker');
        $validFund = CompanyAnalysisMetric::whereIn('ticker', $equityTickers)->where('fundamental_status', 'CALCULATED')->distinct('ticker')->count('ticker');

        $narrativeTickers = [];
        foreach (MarketAnalysis::all() as $a) {
            $t = $a->structured_output['ticker'] ?? null;
            if ($t) {
                $narrativeTickers[$t] = true;
            }
        }

        return [
            'total_registered_equities' => $totalCandidates,
            'total_candidates' => $totalCandidates,
            'total_indices' => $totalIndices,
            'jobs_enqueued' => $jobsEnqueued,
            'not_enqueued' => $notEnqueued,
            'jobs_not_enqueued' => $notEnqueued,
            'jobs_pending' => $pending,
            'jobs_running' => $running,
            'jobs_completed' => $completed,
            'jobs_partial' => $partial,
            'jobs_failed' => $failed,
            'jobs_blocked' => $blocked,
            'status_complete' => $completed,
            'status_partial' => $partial,
            'status_pending' => $pending,
            'equities_with_price_history' => $withPrice,
            'coverage_price_history' => $withPrice,
            'coverage_price_gte_20' => $gte20,
            'coverage_price_gte_50' => $gte50,
            'coverage_price_gte_200' => $gte200,
            'coverage_drawdown_full_1y' => $full1yDrawdown,
            'equities_with_fundamentals' => $withFundamentals,
            'coverage_fundamentals' => $withFundamentals,
            'total_fundamental_reports' => $totalReports,
            'equities_with_actions' => $withActions,
            'coverage_corporate_actions' => $withActions,
            'equities_with_metrics' => $withMetrics,
            'coverage_metrics_total' => $withMetrics,
            'coverage_metrics_valid_tech' => $validTech,
            'coverage_metrics_valid_fund' => $validFund,
            'coverage_gemini_narratives' => count($narrativeTickers),
        ];
    }
}
