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
use Illuminate\Support\Facades\Log;

class IdxFinancialIngestionService
{
    /**
     * Ingest and calculate metrics for a single ticker.
     */
    public function ingestTicker(string $ticker, bool $fullHistory = false): array
    {
        $ticker = strtoupper(trim($ticker));
        $pythonBin = env('PYTHON_BIN', 'C:/laragon/bin/python/python-3.10/python.exe');
        if (! file_exists($pythonBin)) {
            $pythonBin = 'python';
        }

        $scriptPath = base_path('../services/idx_financial_collector.py');
        $cmd = "\"{$pythonBin}\" \"{$scriptPath}\" \"{$ticker}\" 2>&1";

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        $rawOutput = implode("\n", $output);

        // Run python directly with JSON export
        $evalCmd = "\"{$pythonBin}\" -c \"import json, sys; sys.path.insert(0, r'".base_path('../services')."'); from idx_financial_collector import fetch_and_process_ticker; print(json.dumps(fetch_and_process_ticker('{$ticker}', ".($fullHistory ? 'True' : 'False').')))" 2>&1';

        $jsonOutput = [];
        exec($evalCmd, $jsonOutput);
        $rawJson = implode("\n", $jsonOutput);
        $data = json_decode($rawJson, true);

        if (! $data || ($data['success'] ?? false) !== true) {
            $err = $data['message'] ?? ($rawOutput ?: 'Gagal memproses data emiten via python collector');
            Log::warning("[IdxFinancialIngestion] Failed for {$ticker}: {$err}");

            return [
                'success' => false,
                'ticker' => $ticker,
                'error' => $err,
            ];
        }

        $asOfDate = Carbon::now('Asia/Jakarta')->toDateString();

        // 1. Persist recent bars to market_observations safely
        if (! empty($data['recent_bars'])) {
            $compName = IdxCompanyRegistry::where('ticker', $ticker)->value('company_name') ?? $ticker;
            $existingDates = MarketObservation::where('ticker', $ticker)->pluck('id', 'bar_date')->all();
            $nowUtc = Carbon::now('UTC');
            foreach ($data['recent_bars'] as $bar) {
                $barD = $bar['bar_date'];
                $attributes = [
                    'company_name' => $compName,
                    'open_price' => $bar['open'],
                    'high_price' => $bar['high'],
                    'low_price' => $bar['low'],
                    'close_price' => $bar['close'],
                    'volume' => $bar['volume'],
                    'observation_time' => $nowUtc,
                    'source' => 'Yahoo Finance (via yfinance)',
                    'is_eod_final' => true,
                    'fetched_at' => $nowUtc,
                    'first_seen_at' => $nowUtc,
                    'available_at' => $nowUtc,
                    'timezone' => 'Asia/Jakarta',
                    'currency' => 'IDR',
                    'interval' => '1d',
                    'data_classification' => 'PUBLIC_MARKET_EOD',
                    'finality_basis' => 'BEI official market closing settlement',
                    'adjustment_mode' => 'UNADJUSTED',
                    'validation_status' => 'VALID',
                    'data_hash' => hash('sha256', $ticker.$barD.$bar['close'].$bar['volume']),
                ];
                if (isset($existingDates[$barD])) {
                    MarketObservation::where('id', $existingDates[$barD])->update($attributes);
                } else {
                    $attributes['ticker'] = $ticker;
                    $attributes['bar_date'] = $barD;
                    MarketObservation::create($attributes);
                }
            }
        }

        // 2. Persist Corporate Actions
        $savedActions = 0;
        if (! empty($data['actions'])) {
            foreach ($data['actions'] as $act) {
                CompanyCorporateAction::updateOrCreate(
                    [
                        'ticker' => $ticker,
                        'action_type' => $act['action_type'],
                        'execution_date' => $act['execution_date'],
                    ],
                    [
                        'amount_or_ratio' => $act['amount_or_ratio'],
                        'source' => 'Yahoo Finance (via yfinance)',
                    ]
                );
                $savedActions++;
            }
        }

        // 3. Persist Financial Statements
        $savedReports = 0;
        if (! empty($data['financial_reports'])) {
            foreach ($data['financial_reports'] as $rep) {
                CompanyFundamental::updateOrCreate(
                    [
                        'ticker' => $ticker,
                        'report_type' => $rep['report_type'],
                        'fiscal_year' => $rep['fiscal_year'],
                        'fiscal_quarter' => $rep['fiscal_quarter'],
                    ],
                    [
                        'period_end_date' => $rep['period_end_date'],
                        'currency' => $rep['currency'] ?? 'IDR',
                        'total_revenue' => $rep['total_revenue'],
                        'gross_profit' => $rep['gross_profit'],
                        'operating_income' => $rep['operating_income'],
                        'net_income' => $rep['net_income'],
                        'total_assets' => $rep['total_assets'],
                        'total_liabilities' => $rep['total_liabilities'],
                        'total_equity' => $rep['total_equity'],
                        'total_debt' => $rep['total_debt'],
                        'operating_cash_flow' => $rep['operating_cash_flow'],
                        'capital_expenditure' => $rep['capital_expenditure'],
                        'free_cash_flow' => $rep['free_cash_flow'],
                        'source' => $rep['source'] ?? 'Yahoo Finance (via yfinance)',
                    ]
                );
                $savedReports++;
            }
        }

        // 4. Persist Deterministic Metrics
        $tm = $data['technical_metrics'] ?? [];
        $fm = $data['fundamental_metrics'] ?? [];

        $metric = CompanyAnalysisMetric::updateOrCreate(
            [
                'ticker' => $ticker,
                'as_of_date' => $asOfDate,
            ],
            [
                'close_price' => $tm['close_price'] ?? null,
                'return_1d_pct' => $tm['return_1d_pct'] ?? null,
                'return_5d_pct' => $tm['return_5d_pct'] ?? null,
                'return_20d_pct' => $tm['return_20d_pct'] ?? null,
                'sma_20' => $tm['sma_20'] ?? null,
                'sma_50' => $tm['sma_50'] ?? null,
                'sma_200' => $tm['sma_200'] ?? null,
                'rsi_14' => $tm['rsi_14'] ?? null,
                'macd' => $tm['macd'] ?? null,
                'macd_signal' => $tm['macd_signal'] ?? null,
                'macd_hist' => $tm['macd_hist'] ?? null,
                'atr_14' => $tm['atr_14'] ?? null,
                'atr_14_pct' => $tm['atr_14_pct'] ?? null,
                'rvol_20' => $tm['rvol_20'] ?? null,
                'max_drawdown_1y_pct' => $tm['max_drawdown_1y_pct'] ?? null,
                'support_price' => $tm['support_price'] ?? null,
                'resistance_price' => $tm['resistance_price'] ?? null,
                'technical_trend' => $tm['technical_trend'] ?? 'SIDEWAYS',
                'technical_status' => $tm['technical_status'] ?? 'NO_DATA',

                'pe_ratio' => $fm['pe_ratio'] ?? null,
                'pbv_ratio' => $fm['pbv_ratio'] ?? null,
                'roe_pct' => $fm['roe_pct'] ?? null,
                'roa_pct' => $fm['roa_pct'] ?? null,
                'der_ratio' => $fm['der_ratio'] ?? null,
                'net_profit_margin_pct' => $fm['net_profit_margin_pct'] ?? null,
                'operating_margin_pct' => $fm['operating_margin_pct'] ?? null,
                'revenue_growth_yoy_pct' => $fm['revenue_growth_yoy_pct'] ?? null,
                'net_income_growth_yoy_pct' => $fm['net_income_growth_yoy_pct'] ?? null,
                'dividend_yield_pct' => $fm['dividend_yield_pct'] ?? null,
                'eps' => $fm['eps'] ?? null,
                'bvps' => $fm['bvps'] ?? null,
                'sector_template' => $fm['sector_template'] ?? 'GENERAL',
                'fundamental_status' => $fm['fundamental_status'] ?? 'NO_DATA',
                'data_quality_flags' => array_merge(
                    $fm['data_quality_flags'] ?? [],
                    [
                        'adtv_20' => $tm['adtv_20'] ?? null,
                        'zero_vol_days_20' => $tm['zero_vol_days_20'] ?? null,
                        'indicators_meta' => $tm['indicators_meta'] ?? [],
                        'metric_sources' => $fm['metric_sources'] ?? [],
                        'shares_outstanding' => $fm['shares_outstanding'] ?? null,
                        'weighted_average_shares' => $fm['weighted_average_shares'] ?? null,
                        'dividend_cash_amount' => $fm['dividend_cash_amount'] ?? null,
                        'latest_dividend_date' => $fm['latest_dividend_date'] ?? null,
                        'liabilities_to_equity_ratio' => $fm['liabilities_to_equity_ratio'] ?? null,
                        'der_pure_debt_ratio' => $fm['der_pure_debt_ratio'] ?? null,
                        'pure_debt' => $fm['pure_debt'] ?? null,
                        'long_term_debt' => $fm['long_term_debt'] ?? null,
                        'current_debt' => $fm['current_debt'] ?? null,
                        'capital_lease_obligations' => $fm['capital_lease_obligations'] ?? null,
                    ]
                ),
            ]
        );

        return [
            'success' => true,
            'ticker' => $ticker,
            'as_of_date' => $asOfDate,
            'bars_stored' => count($data['recent_bars'] ?? []),
            'actions_stored' => $savedActions,
            'reports_stored' => $savedReports,
            'technical_trend' => $tm['technical_trend'] ?? 'N/A',
            'fundamental_status' => $fm['fundamental_status'] ?? 'NO_DATA',
            'duration_ms' => $data['duration_ms'] ?? 0,
            'data_quality_flags' => $fm['data_quality_flags'] ?? [],
            'partial_reason' => $fm['data_quality_flags']['partial_reason'] ?? null,
            'missing_components' => $fm['data_quality_flags']['missing_components'] ?? [],
            'retryable' => $fm['data_quality_flags']['retryable'] ?? false,
            'financial_currency' => $fm['data_quality_flags']['financial_currency'] ?? 'IDR',
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
