<?php

namespace App\Services;

use App\Models\DataRevisionArchive;
use App\Models\DataSource;
use App\Models\IdxCompanyRegistry;
use App\Models\MarketObservation;
use App\Models\MarketSnapshot;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DailyEodRefreshService
{
    public function __construct(
        protected IdxEdgeClient $client,
        protected BeiCalendarService $calendar,
        protected TechnicalMetricsCalculationService $metricsService,
        protected BrokerSummaryIngestionService $brokerService
    ) {}

    /**
     * Resolve expected session date: if market not closed yet today (<16:05 WIB), fallback to previous closed trading day.
     */
    public function resolveExpectedSessionDate(): string
    {
        $nowWib = now('Asia/Jakarta');
        if ($this->calendar->isTradingDay($nowWib)) {
            // Sesi hari ini dianggap siap direfresh jika sudah melewati cut-off 16:05 WIB
            if ($nowWib->greaterThanOrEqualTo($nowWib->copy()->setTime(16, 5))) {
                return $this->calendar->getCurrentTradingDay($nowWib)->toDateString();
            }

            return $this->calendar->getPreviousTradingDay($nowWib)->toDateString();
        }

        return $this->calendar->getCurrentTradingDay($nowWib)->toDateString();
    }

    public function resolveTargetSessionDate(?string $explicitSession = null): string
    {
        return $explicitSession !== null
            ? Carbon::parse($explicitSession, 'Asia/Jakarta')->toDateString()
            : $this->resolveExpectedSessionDate();
    }

    /**
     * Run daily EOD incremental refresh across the universe.
     *
     * @param  array<int, string>|null  $specificTickers
     * @return array<string, mixed>
     */
    public function refreshDailyEod(
        ?array $specificTickers = null,
        int $overlapBars = 20,
        bool $syncBroker = false,
        bool $retryOnly = false,
        ?string $targetSessionDate = null,
        ?int $limit = null,
        ?callable $heartbeatCallback = null,
        bool $metricsOnly = false,
        bool $canonicalGapOnly = false
    ): array {
        $nowWib = now('Asia/Jakarta');
        $expectedSessionDate = $targetSessionDate !== null
            ? Carbon::parse($targetSessionDate)->toDateString()
            : $this->resolveExpectedSessionDate();

        $query = IdxCompanyRegistry::where('instrument_type', 'EQUITY')
            ->where(function ($q) {
                $q->whereNull('backfill_status')->orWhere('backfill_status', '!=', 'UNSUPPORTED_SHARE_CLASS');
            })
            ->orderByRaw("CASE WHEN last_bar_date >= '{$expectedSessionDate}' THEN 2 ELSE 1 END")
            ->orderBy('ticker');

        if ($canonicalGapOnly) {
            $query->where(function ($q) use ($expectedSessionDate) {
                foreach (['UNADJUSTED', 'PROVIDER_BACK_ADJUSTED'] as $mode) {
                    $q->orWhereNotExists(function ($sub) use ($expectedSessionDate, $mode) {
                        $sub->selectRaw('1')
                            ->from('market_observations as canonical_gap_probe')
                            ->whereColumn('canonical_gap_probe.ticker', 'idx_company_registries.ticker')
                            ->where('canonical_gap_probe.bar_date', $expectedSessionDate)
                            ->where('canonical_gap_probe.interval', '1d')
                            ->where('canonical_gap_probe.data_classification', 'PUBLIC_MARKET_EOD')
                            ->where('canonical_gap_probe.source', 'IDX Edge PRO')
                            ->where('canonical_gap_probe.board_scope', 'RG')
                            ->where('canonical_gap_probe.validation_status', 'VALID')
                            ->where('canonical_gap_probe.adjustment_mode', $mode);
                    });
                }
            });
        } elseif ($retryOnly) {
            $query->whereDate('eod_target_session', $expectedSessionDate)
                ->where(function ($q) use ($expectedSessionDate) {
                    $q->whereIn('eod_refresh_status', ['WAITING_FOR_SESSION_DATA', 'RETRY_SCHEDULED', 'PARTIAL_NOMINAL_MISSING'])
                        ->orWhereNull('last_bar_date')
                        ->orWhere('last_bar_date', '<', $expectedSessionDate)
                        ->orWhereNotExists(function ($sub) use ($expectedSessionDate) {
                            $sub->selectRaw('1')
                                ->from('market_observations as coverage_probe')
                                ->whereColumn('coverage_probe.ticker', 'idx_company_registries.ticker')
                                ->where('coverage_probe.bar_date', $expectedSessionDate)
                                ->where('coverage_probe.source', 'IDX Edge PRO')
                                ->where('coverage_probe.board_scope', 'RG')
                                ->where('coverage_probe.validation_status', 'VALID')
                                ->where('coverage_probe.adjustment_mode', 'UNADJUSTED');
                        })
                        ->orWhereNotExists(function ($sub) use ($expectedSessionDate) {
                            $sub->selectRaw('1')
                                ->from('market_observations as coverage_probe')
                                ->whereColumn('coverage_probe.ticker', 'idx_company_registries.ticker')
                                ->where('coverage_probe.bar_date', $expectedSessionDate)
                                ->where('coverage_probe.source', 'IDX Edge PRO')
                                ->where('coverage_probe.board_scope', 'RG')
                                ->where('coverage_probe.validation_status', 'VALID')
                                ->where('coverage_probe.adjustment_mode', 'PROVIDER_BACK_ADJUSTED');
                        });
                })->where('eod_retry_attempts', '<', 5)
                ->where(function ($q) {
                    $q->whereNull('eod_next_retry_at')
                        ->orWhereRaw('eod_next_retry_at <= CURRENT_TIMESTAMP');
                });
        }

        if (! empty($specificTickers)) {
            $query->whereIn('ticker', array_map('strtoupper', $specificTickers));
        }

        if ($metricsOnly) {
            $query->whereExists(function ($sub) use ($expectedSessionDate) {
                $sub->selectRaw('1')
                    ->from('market_observations as metric_adjusted_probe')
                    ->whereColumn('metric_adjusted_probe.ticker', 'idx_company_registries.ticker')
                    ->where('metric_adjusted_probe.bar_date', $expectedSessionDate)
                    ->where('metric_adjusted_probe.source', 'IDX Edge PRO')
                    ->where('metric_adjusted_probe.board_scope', 'RG')
                    ->where('metric_adjusted_probe.interval', '1d')
                    ->where('metric_adjusted_probe.data_classification', 'PUBLIC_MARKET_EOD')
                    ->where('metric_adjusted_probe.adjustment_mode', 'PROVIDER_BACK_ADJUSTED')
                    ->where('metric_adjusted_probe.validation_status', 'VALID');
            })->whereExists(function ($sub) use ($expectedSessionDate) {
                $sub->selectRaw('1')
                    ->from('market_observations as metric_raw_probe')
                    ->whereColumn('metric_raw_probe.ticker', 'idx_company_registries.ticker')
                    ->where('metric_raw_probe.bar_date', $expectedSessionDate)
                    ->where('metric_raw_probe.source', 'IDX Edge PRO')
                    ->where('metric_raw_probe.board_scope', 'RG')
                    ->where('metric_raw_probe.interval', '1d')
                    ->where('metric_raw_probe.data_classification', 'PUBLIC_MARKET_EOD')
                    ->where('metric_raw_probe.adjustment_mode', 'UNADJUSTED')
                    ->where('metric_raw_probe.validation_status', 'VALID');
            })->where(function ($q) use ($expectedSessionDate) {
                $q->whereNotExists(function ($sub) use ($expectedSessionDate) {
                    $sub->selectRaw('1')
                        ->from('company_analysis_metrics as metric_probe')
                        ->whereColumn('metric_probe.ticker', 'idx_company_registries.ticker')
                        ->where('metric_probe.as_of_date', $expectedSessionDate);
                })->orWhereExists(function ($sub) use ($expectedSessionDate) {
                    $sub->selectRaw('1')
                        ->from('company_analysis_metrics as metric_probe')
                        ->whereColumn('metric_probe.ticker', 'idx_company_registries.ticker')
                        ->where('metric_probe.as_of_date', $expectedSessionDate)
                        ->where(function ($stale) use ($expectedSessionDate) {
                            $stale->whereRaw("(metric_probe.data_quality_flags->>'last_date') IS DISTINCT FROM ?", [$expectedSessionDate])
                                ->orWhereRaw("(metric_probe.data_quality_flags->>'as_of_date') IS DISTINCT FROM ?", [$expectedSessionDate])
                                ->orWhereRaw("(metric_probe.data_quality_flags->>'source') IS DISTINCT FROM 'IDX Edge PRO'")
                                ->orWhereRaw("(metric_probe.data_quality_flags->>'board_scope') IS DISTINCT FROM 'RG'")
                                ->orWhereRaw("(metric_probe.data_quality_flags->>'adjustment_mode') IS DISTINCT FROM 'PROVIDER_BACK_ADJUSTED'");
                        });
                });
            });
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $escalated = IdxCompanyRegistry::where('instrument_type', 'EQUITY')
            ->where('backfill_status', '!=', 'UNSUPPORTED_SHARE_CLASS')
            ->where('eod_retry_attempts', '>=', 5)
            ->get(['ticker', 'eod_retry_attempts', 'eod_next_retry_at', 'eod_last_error'])
            ->map(fn ($row) => [
                'ticker' => $row->ticker,
                'attempts' => (int) $row->eod_retry_attempts,
                'next_retry_at' => $row->eod_next_retry_at?->toIso8601String(),
                'last_error' => $row->eod_last_error,
                'escalation' => 'MANUAL_PROVIDER_REVIEW_REQUIRED',
            ])->values()->all();
        $tickers = $query->get();

        $totalEligible = $tickers->count();
        $alreadyFresh = 0;
        $updated = 0;
        $waitingForSession = 0;
        $failed = 0;
        $barsIngested = 0;
        $backlogTickers = [];
        $checkpoints = [];
        $retryEvidence = [];
        $metricsUpdated = 0;
        $metricsInsufficient = 0;
        $metricsFailed = 0;
        $metricEvidence = [];

        foreach ($tickers as $tickerModel) {
            $ticker = $tickerModel->ticker;
            if (! $metricsOnly && $tickerModel->eod_target_session?->toDateString() !== $expectedSessionDate) {
                $tickerModel->update([
                    'eod_refresh_status' => 'IDLE',
                    'eod_target_session' => $expectedSessionDate,
                    'eod_retry_attempts' => 0,
                    'eod_next_retry_at' => null,
                    'eod_last_attempt_at' => null,
                    'eod_last_error' => null,
                ]);
                $tickerModel->refresh();
            }
            $lastBarDate = $tickerModel->last_bar_date?->toDateString();

            // 1. Verify completeness of both modes before declaring fresh
            $hasBothModes = false;
            $nominalRefilled = false;
            if ($metricsOnly || ($lastBarDate !== null && $lastBarDate >= $expectedSessionDate)) {
                // 1. Check completeness of both modes in local DB
                $hasRawLocal = $this->whereSessionDate(MarketObservation::where('ticker', $ticker), $expectedSessionDate)

                    ->where('source', 'IDX Edge PRO')
                    ->where('board_scope', 'RG')
                    ->where('adjustment_mode', 'UNADJUSTED')
                    ->where('validation_status', 'VALID')
                    ->exists();
                $hasAdjLocal = $this->whereSessionDate(MarketObservation::where('ticker', $ticker), $expectedSessionDate)

                    ->where('source', 'IDX Edge PRO')
                    ->where('board_scope', 'RG')
                    ->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')
                    ->where('validation_status', 'VALID')
                    ->exists();

                // If adjusted exists but nominal unadjusted is missing, fetch nominal
                if ($hasAdjLocal && ! $hasRawLocal) {
                    $retryEvidence[$ticker] = [
                        'session_date' => $expectedSessionDate,
                        'attempt_before' => (int) $tickerModel->eod_retry_attempts,
                        'adjusted_request' => $this->skippedRequestEvidence('PROVIDER_BACK_ADJUSTED', 'ADJUSTED_ALREADY_CANONICAL'),
                        'raw_request' => $this->skippedRequestEvidence('UNADJUSTED', 'NOT_ATTEMPTED'),
                    ];
                    try {
                        $rawResp = $this->client->history($ticker, $overlapBars, false, null, null, true);
                        $retryEvidence[$ticker]['raw_request'] = $this->providerRequestEvidence('UNADJUSTED', $rawResp);
                        if (($rawResp['success'] ?? false) && ! empty($rawResp['data']['bars'])) {
                            $this->ingestNominalBars($ticker, $rawResp['data']['bars']);
                            $hasRawLocal = $this->whereSessionDate(MarketObservation::where('ticker', $ticker), $expectedSessionDate)
                                ->where('adjustment_mode', 'UNADJUSTED')
                                ->where('validation_status', 'VALID')
                                ->exists();
                            $nominalRefilled = $hasRawLocal;
                        }
                    } catch (Throwable $e) {
                        Log::warning("Retry nominal fetch failed for {$ticker}: {$e->getMessage()}");
                    }
                }

                if ($hasRawLocal && $hasAdjLocal) {
                    $hasBothModes = true;
                }
            }

            if ($hasBothModes) {
                if ($metricsOnly) {
                    $alreadyFresh++;
                    $metricResult = $this->calculateMetricsForSession($ticker, $expectedSessionDate);
                    $metricEvidence[$ticker] = $metricResult;
                    if ($metricResult['status'] === 'UPDATED') {
                        $metricsUpdated++;
                    } elseif ($metricResult['status'] === 'INSUFFICIENT_INPUT') {
                        $metricsInsufficient++;
                    } else {
                        $metricsFailed++;
                    }
                    $checkpoints[$ticker] = [
                        'status' => 'METRICS_ONLY',
                        'last_bar_date' => $lastBarDate,
                        'metrics' => $metricResult,
                    ];
                } elseif ($nominalRefilled) {
                    $updated++;
                    $tickerModel->update([
                        'eod_refresh_status' => 'UPDATED',
                        'eod_target_session' => $expectedSessionDate,
                        'eod_next_retry_at' => null,
                        'eod_last_error' => null,
                        'last_success_at' => now('UTC'),
                        'eod_retry_attempts' => 0,
                    ]);
                    $checkpoints[$ticker] = [
                        'status' => 'NOMINAL_BACKFILLED',
                        'last_bar_date' => $lastBarDate,
                    ];
                } else {
                    $alreadyFresh++;
                    $tickerModel->update([
                        'eod_refresh_status' => 'FRESH_ALREADY',
                        'eod_target_session' => $expectedSessionDate,
                        'eod_next_retry_at' => null,
                        'eod_last_error' => null,
                    ]);
                    $metricResult = $this->calculateMetricsForSession($ticker, $expectedSessionDate);
                    $metricEvidence[$ticker] = $metricResult;
                    if ($metricResult['status'] === 'UPDATED') {
                        $metricsUpdated++;
                    } elseif ($metricResult['status'] === 'INSUFFICIENT_INPUT') {
                        $metricsInsufficient++;
                    } else {
                        $metricsFailed++;
                    }
                    $checkpoints[$ticker] = [
                        'status' => 'FRESH_ALREADY',
                        'last_bar_date' => $lastBarDate,
                        'metrics' => $metricResult,
                    ];
                }
                if ($heartbeatCallback) {
                    $heartbeatCallback($ticker, $totalEligible, $alreadyFresh + $updated + $failed + $waitingForSession);
                }

                continue;
            }

            // Check persistent retry backoff: if next_retry_at is in the future and not specific ticker call, skip
            if (empty($specificTickers) && $tickerModel->eod_next_retry_at !== null && $tickerModel->eod_next_retry_at->isFuture()) {
                $waitingForSession++;
                $backlogTickers[] = $ticker;
                $checkpoints[$ticker] = [
                    'status' => 'RETRY_BACKOFF_WAITING',
                    'next_retry_at' => $tickerModel->eod_next_retry_at->toIso8601String(),
                ];

                continue;
            }

            if ($heartbeatCallback) {
                $heartbeatCallback($ticker, $totalEligible, $alreadyFresh + $updated + $failed + $waitingForSession);
            }

            // 2. Fetch recent overlap bars (minimum 20 bars allowed by provider)
            // Pass isEod = true so the request can access the dedicated EOD quota reserve
            $retryEvidence[$ticker] = [
                'session_date' => $expectedSessionDate,
                'attempt_before' => (int) $tickerModel->eod_retry_attempts,
                'adjusted_request' => $this->skippedRequestEvidence('PROVIDER_BACK_ADJUSTED', 'NOT_ATTEMPTED'),
                'raw_request' => $this->skippedRequestEvidence('UNADJUSTED', 'ADJUSTED_REQUEST_PENDING'),
            ];
            $response = $this->client->history($ticker, $overlapBars, true, null, null, true);
            $retryEvidence[$ticker]['adjusted_request'] = $this->providerRequestEvidence('PROVIDER_BACK_ADJUSTED', $response);
            $retryEvidence[$ticker]['attempt_after_http'] = (int) $tickerModel->eod_retry_attempts;

            if (! ($response['success'] ?? false) || empty($response['data']['bars'])) {
                $errType = $response['error_type'] ?? '';
                $err = $response['message'] ?? 'Gagal mengambil bar terbaru.';

                if ($errType === 'LOCAL_RATE_LIMIT') {
                    $availableIn = (int) ($response['available_in'] ?? $response['meta']['available_in'] ?? 3);
                    sleep(max(1, $availableIn));
                    // Retry once after rate limit cooling
                    $response = $this->client->history($ticker, $overlapBars, true, null, null, true);
                    $retryEvidence[$ticker]['adjusted_request'] = $this->providerRequestEvidence('PROVIDER_BACK_ADJUSTED', $response);
                    if (($response['success'] ?? false) && ! empty($response['data']['bars'])) {
                        // Successfully fetched on retry
                        goto PROCESS_BARS;
                    }
                    $errType = $response['error_type'] ?? '';
                    $err = $response['message'] ?? 'Gagal mengambil bar terbaru setelah rate limit.';
                }

                if ($errType === 'DAILY_BUDGET_RESERVED') {
                    // Local budget reserved: NOT a provider failure
                    $tickerModel->update([
                        'eod_refresh_status' => 'BUDGET_RESERVED_LOCAL',
                        'eod_target_session' => $expectedSessionDate,
                        'eod_last_attempt_at' => now('UTC'),
                        'eod_last_error' => 'Alokasi kuota harian lokal tercapai. Menunggu jendela reset.',
                    ]);
                    $failed++;
                    $backlogTickers[] = $ticker;

                    continue;
                }

                $failed++;
                $backlogTickers[] = $ticker;
                $attempts = (int) $tickerModel->eod_retry_attempts + 1;
                $nextRetry = $attempts < 5 ? now('Asia/Jakarta')->addMinutes($attempts * 15) : null;
                $statusStr = $attempts >= 5 ? 'MAX_RETRIES_EXCEEDED' : 'RETRY_SCHEDULED';

                $tickerModel->update([
                    'eod_refresh_status' => $statusStr,
                    'eod_target_session' => $expectedSessionDate,
                    'eod_retry_attempts' => $attempts,
                    'eod_next_retry_at' => $nextRetry,
                    'eod_last_attempt_at' => now('UTC'),
                    'eod_last_error' => substr($err, 0, 255),
                ]);

                $checkpoints[$ticker] = [
                    'status' => 'FETCH_ERROR',
                    'message' => $err,
                    'retry_attempts' => $attempts,
                    'next_retry_at' => $nextRetry?->toIso8601String(),
                ];

                continue;
            }

            PROCESS_BARS:
            $bars = $response['data']['bars'];
            usort($bars, fn ($a, $b) => strcmp($a['date'], $b['date']));
            $latestFetchedBar = end($bars);
            $latestFetchedDate = $latestFetchedBar['date'] ?? null;

            // 3. Probe availability: check if provider has published the target session yet
            if ($latestFetchedDate < $expectedSessionDate) {
                $retryEvidence[$ticker]['raw_request'] = $this->skippedRequestEvidence('UNADJUSTED', 'TARGET_SESSION_UNAVAILABLE');
                $waitingForSession++;
                $backlogTickers[] = $ticker;
                $attempts = (int) $tickerModel->eod_retry_attempts + 1;
                $nextRetry = $attempts < 5 ? now('Asia/Jakarta')->addMinutes($attempts * 15) : null;
                $statusStr = $attempts >= 5 ? 'MAX_RETRIES_EXCEEDED' : 'WAITING_FOR_SESSION_DATA';

                $tickerModel->update([
                    'eod_refresh_status' => $statusStr,
                    'eod_target_session' => $expectedSessionDate,
                    'eod_retry_attempts' => $attempts,
                    'eod_next_retry_at' => $nextRetry,
                    'eod_last_attempt_at' => now('UTC'),
                    'eod_last_error' => "Provider data session {$latestFetchedDate} < target {$expectedSessionDate}",
                ]);

                $checkpoints[$ticker] = [
                    'status' => 'WAITING_FOR_SESSION_DATA',
                    'latest_available_date' => $latestFetchedDate,
                    'expected_date' => $expectedSessionDate,
                    'retry_attempts' => $attempts,
                    'next_retry_at' => $nextRetry?->toIso8601String(),
                ];
                // Still ingest what was returned if newer than local DB
                if ($lastBarDate === null || $latestFetchedDate > $lastBarDate) {
                    $this->ingestBars($ticker, $bars);
                    $tickerModel->update([
                        'last_bar_date' => $latestFetchedDate,
                        'last_success_at' => now('UTC'),
                    ]);
                }

                continue;
            }

            // 4. Session data available: ingest bars and update checkpoint
            $ingestStats = $this->ingestBars($ticker, $bars);
            $ingestedCount = $ingestStats['new_bars'];
            $barsIngested += ($ingestedCount + ($ingestStats['revised_bars'] ?? 0));

            // Also fetch and ingest UNADJUSTED (nominal) bars for the same session to ensure consistency
            usleep(250000); // 250ms spacing between adjusted and raw request
            if ($heartbeatCallback) {
                $heartbeatCallback($ticker, $totalEligible, $alreadyFresh + $updated + $failed + $waitingForSession);
            }
            try {
                $rawResp = $this->client->history($ticker, $overlapBars, false, null, null, true);
                $retryEvidence[$ticker]['raw_request'] = $this->providerRequestEvidence('UNADJUSTED', $rawResp);
                if (($rawResp['success'] ?? false) && ! empty($rawResp['data']['bars'])) {
                    $rawBars = $rawResp['data']['bars'];
                    $this->ingestNominalBars($ticker, $rawBars);
                } else {
                    // Nominal unadjusted missing from upstream provider
                    $retryEvidence[$ticker]['raw_provider_response'] = [
                        'success' => (bool) ($rawResp['success'] ?? false),
                        'error_type' => $rawResp['error_type'] ?? null,
                        'message' => $rawResp['message'] ?? null,
                        'http_status' => $rawResp['http_status'] ?? null,
                        'transport_status' => $rawResp['transport_status'] ?? null,
                        'response_period' => $rawResp['response_period'] ?? null,
                        'retry_after_seconds' => $rawResp['retry_after_seconds'] ?? null,
                        'latency_ms' => $rawResp['latency_ms'] ?? null,
                    ];
                    $retryEvidence[$ticker]['raw_evidence_status'] = 'PROVIDER_EMPTY_OR_FAILED';
                    $attempts = (int) $tickerModel->eod_retry_attempts + 1;
                    $nextRetry = $attempts < 5 ? now('Asia/Jakarta')->addMinutes($attempts * 15) : null;
                    $tickerModel->update([
                        'eod_refresh_status' => 'PARTIAL_NOMINAL_MISSING',
                        'eod_target_session' => $expectedSessionDate,
                        'eod_retry_attempts' => $attempts,
                        'eod_next_retry_at' => $nextRetry,
                        'eod_last_error' => 'Upstream provider returned empty unadjusted nominal bars for session '.$expectedSessionDate,
                    ]);
                }
            } catch (Throwable $e) {
                Log::warning("Nominal bars ingestion failed for {$ticker}: {$e->getMessage()}");
                $attempts = (int) $tickerModel->eod_retry_attempts + 1;
                $nextRetry = $attempts < 5 ? now('Asia/Jakarta')->addMinutes($attempts * 15) : null;
                $tickerModel->update([
                    'eod_refresh_status' => 'NOMINAL_INGEST_ERROR',
                    'eod_target_session' => $expectedSessionDate,
                    'eod_retry_attempts' => $attempts,
                    'eod_next_retry_at' => $nextRetry,
                    'eod_last_error' => 'Nominal ingestion exception: '.$e->getMessage(),
                ]);
            }
            usleep(250000); // 250ms spacing before downstream processing

            // Invalidate ticker-level caches immediately
            Cache::forget("ticker:detail:{$ticker}");
            Cache::forget("ticker:metrics:{$ticker}");

            // Sinkronkan juga ke MarketSnapshot untuk kompatibilitas evaluasi & downstream
            try {
                $latestBar = end($bars);
                if ($latestBar && ($latestBar['validation_status'] ?? 'VALID') === 'VALID') {
                    $ds = DataSource::where('type', 'market_data')->first();
                    MarketSnapshot::updateOrCreate(
                        [
                            'ticker' => $ticker,
                            'bar_date' => $latestFetchedDate,
                        ],
                        [
                            'data_source_id' => $ds?->id,
                            'source_timestamp' => Carbon::parse("{$latestFetchedDate} 16:00:00", 'Asia/Jakarta')->utc(),
                            'open_price' => $latestBar['open'],
                            'high_price' => $latestBar['high'],
                            'low_price' => $latestBar['low'],
                            'close_price' => $latestBar['close'],
                            'volume' => $latestBar['volume'],
                            'data_classification' => 'PUBLIC_MARKET_EOD',
                            'indicators' => [
                                'source' => 'IDX Edge PRO',
                                'adjustment_mode' => 'PROVIDER_BACK_ADJUSTED',
                                'value_idr' => $latestBar['value_idr'] ?? null,
                            ],
                        ]
                    );
                }
            } catch (Throwable $e) {
                Log::warning("MarketSnapshot sync failed for {$ticker}: {$e->getMessage()}");
            }

            // Only mark as UPDATED if BOTH modes are verified in local DB
            $this->promoteSupersededBars($ticker);

            $hasRawModeSaved = $this->whereSessionDate(MarketObservation::where('ticker', $ticker), $expectedSessionDate)
                ->where('source', 'IDX Edge PRO')
                ->where('board_scope', 'RG')
                ->where('adjustment_mode', 'UNADJUSTED')
                ->where('validation_status', 'VALID')
                ->exists();
            $hasAdjustedModeSaved = $this->whereSessionDate(MarketObservation::where('ticker', $ticker), $expectedSessionDate)
                ->where('source', 'IDX Edge PRO')
                ->where('board_scope', 'RG')
                ->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')
                ->where('validation_status', 'VALID')
                ->exists();
            $hasBothModesSaved = $hasRawModeSaved && $hasAdjustedModeSaved;

            if (! $hasBothModesSaved) {
                // If nominal unadjusted is missing, treat strictly as backlog
                $backlogTickers[] = $ticker;
                $waitingForSession++;
            } else {
                $tickerModel->update([
                    'last_bar_date' => $latestFetchedDate,
                    'last_success_at' => now('UTC'),
                    'failure_reason' => null,
                    'eod_refresh_status' => 'UPDATED',
                    'eod_target_session' => $expectedSessionDate,
                    'eod_retry_attempts' => 0,
                    'eod_next_retry_at' => null,
                    'eod_last_attempt_at' => now('UTC'),
                    'eod_last_error' => null,
                ]);

                // 5. Deterministic Technical Indicator Recalculation
                $metricResult = $this->calculateMetricsForSession($ticker, $expectedSessionDate);
                $metricEvidence[$ticker] = $metricResult;
                if ($metricResult['status'] === 'UPDATED') {
                    $metricsUpdated++;
                } elseif ($metricResult['status'] === 'INSUFFICIENT_INPUT') {
                    $metricsInsufficient++;
                } else {
                    $metricsFailed++;
                }

                // 6. Optional: Sync Broker Summary for this session
                if ($syncBroker) {
                    try {
                        $this->brokerService->syncBrokerSummary($ticker, $expectedSessionDate);
                    } catch (Throwable $e) {
                        Log::warning("Broker sync failed for {$ticker}: {$e->getMessage()}");
                    }
                }

                $updated++;
                $checkpoints[$ticker] = [
                    'status' => 'UPDATED',
                    'new_bars_count' => $ingestedCount,
                    'latest_bar_date' => $latestFetchedDate,
                ];
            }

            if ($heartbeatCallback) {
                $heartbeatCallback($ticker, $totalEligible, $alreadyFresh + $updated + $failed + $waitingForSession);
            }

            usleep(300000); // 300ms yield to smooth requests towards 60 RPM
        }

        if ($barsIngested > 0) {
            Cache::forget('market:tickers_with_data');
            Cache::forget('dashboard:overview:counts');
            Cache::forget('dashboard:overview:idx_coverage');
            Cache::forget('dashboard:overview:screening_summary');
        }

        $executionComplete = true;
        $selectedScopeComplete = ($totalEligible > 0 && ($alreadyFresh + $updated === $totalEligible) && empty($backlogTickers));
        $supportedTickers = IdxCompanyRegistry::query()
            ->where('instrument_type', 'EQUITY')
            ->where('backfill_status', '!=', 'UNSUPPORTED_SHARE_CLASS')
            ->where('ticker', '!=', 'GOTOM')
            ->pluck('ticker')
            ->unique();
        $universeRaw = MarketObservation::query()
            ->whereIn('ticker', $supportedTickers)
            ->where('bar_date', $expectedSessionDate)
            ->where('source', 'IDX Edge PRO')
            ->where('board_scope', 'RG')
            ->where('validation_status', 'VALID')
            ->where('adjustment_mode', 'UNADJUSTED')
            ->distinct()
            ->pluck('ticker');
        $universeAdjusted = MarketObservation::query()
            ->whereIn('ticker', $supportedTickers)
            ->where('bar_date', $expectedSessionDate)
            ->where('source', 'IDX Edge PRO')
            ->where('board_scope', 'RG')
            ->where('validation_status', 'VALID')
            ->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')
            ->distinct()
            ->pluck('ticker');
        $universeCompleteTickers = $universeRaw->intersect($universeAdjusted);
        $universeDataCoverageComplete = $supportedTickers->isNotEmpty()
            && $universeCompleteTickers->count() === $supportedTickers->count();

        return [
            'execution_complete' => $executionComplete,
            'selected_scope_complete' => $selectedScopeComplete,
            'data_coverage_complete' => $universeDataCoverageComplete,
            'universe_data_coverage_complete' => $universeDataCoverageComplete,
            'expected_session_date' => $expectedSessionDate,
            'total_eligible' => $totalEligible,
            'already_fresh' => $alreadyFresh,
            'updated' => $updated,
            'waiting_for_session' => $waitingForSession,
            'failed' => $failed,
            'metrics_updated' => $metricsUpdated,
            'metrics_insufficient_input' => $metricsInsufficient,
            'metrics_failed' => $metricsFailed,
            'metric_evidence' => $metricEvidence,
            'total_new_bars_ingested' => $barsIngested,
            'universe_fully_fresh' => $universeDataCoverageComplete,
            'universe_complete_count' => $universeCompleteTickers->count(),
            'universe_remaining' => max(0, $supportedTickers->count() - $universeCompleteTickers->count()),
            'backlog_count' => count($backlogTickers),
            'backlog_tickers' => array_slice($backlogTickers, 0, 50),
            'retry_evidence' => $retryEvidence,
            'escalated' => $escalated,
            'timestamp' => now('Asia/Jakarta')->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function providerRequestEvidence(string $mode, array $response): array
    {
        $success = (bool) ($response['success'] ?? false);
        $bars = $response['data']['bars'] ?? [];

        return [
            'performed' => true,
            'status' => 'PERFORMED',
            'mode' => $mode,
            'success' => $success,
            'error_type' => $response['error_type'] ?? null,
            'message' => $success ? null : ($response['message'] ?? 'Gagal mengambil bar terbaru.'),
            'provider_message' => $response['message'] ?? null,
            'http_status' => $response['http_status'] ?? null,
            'transport_status' => $response['transport_status'] ?? null,
            'response_period' => $response['response_period'] ?? null,
            'retry_after_seconds' => $response['retry_after_seconds'] ?? null,
            'bar_dates' => array_values(array_unique(array_filter(array_map(fn ($bar) => $bar['date'] ?? null, $bars)))),
            'validation_statuses' => array_values(array_unique(array_filter(array_map(fn ($bar) => $bar['validation_status'] ?? null, $bars)))),
        ];
    }

    /** @return array<string, mixed> */
    private function skippedRequestEvidence(string $mode, string $reason): array
    {
        return [
            'performed' => false,
            'status' => 'SKIPPED',
            'reason' => $reason,
            'mode' => $mode,
            'success' => false,
            'bar_dates' => [],
            'validation_statuses' => [],
        ];
    }

    /**
     * Recalculate technical metrics only after canonical adjusted bars exist for session.
     *
     * @return array<string, mixed>
     */
    protected function calculateMetricsForSession(string $ticker, string $sessionDate): array
    {
        $input = MarketObservation::query()
            ->where('ticker', $ticker)
            ->where('source', 'IDX Edge PRO')
            ->where('board_scope', 'RG')
            ->where('interval', '1d')
            ->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')
            ->whereDate('bar_date', '<=', $sessionDate);
        $validCount = (clone $input)->where('validation_status', 'VALID')->count();
        $invalidCount = (clone $input)->where('validation_status', '!=', 'VALID')->count();
        $firstDate = (clone $input)->where('validation_status', 'VALID')->min('bar_date');
        $lastDate = (clone $input)->where('validation_status', 'VALID')->max('bar_date');

        try {
            $metric = $this->metricsService->calculateForTicker($ticker, $sessionDate);
            if ($metric === null) {
                return [
                    'status' => 'INSUFFICIENT_INPUT',
                    'ticker' => $ticker,
                    'as_of_date' => $sessionDate,
                    'source' => 'IDX Edge PRO',
                    'board_scope' => 'RG',
                    'interval' => '1d',
                    'adjustment_mode' => 'PROVIDER_BACK_ADJUSTED',
                    'valid_input_bars' => $validCount,
                    'invalid_input_bars' => $invalidCount,
                    'first_input_date' => $firstDate,
                    'last_input_date' => $lastDate,
                ];
            }

            return [
                'status' => 'UPDATED',
                'ticker' => $ticker,
                'as_of_date' => $sessionDate,
                'technical_status' => $metric->technical_status,
                'valid_input_bars' => $validCount,
                'invalid_input_bars' => $invalidCount,
                'first_input_date' => $firstDate,
                'last_input_date' => $lastDate,
                'provenance' => $metric->data_quality_flags,
            ];
        } catch (Throwable $e) {
            Log::warning("Metrics calc failed for {$ticker} at {$sessionDate}: {$e->getMessage()}");

            return [
                'status' => 'FAILED',
                'ticker' => $ticker,
                'as_of_date' => $sessionDate,
                'valid_input_bars' => $validCount,
                'invalid_input_bars' => $invalidCount,
                'error' => substr($e->getMessage(), 0, 255),
            ];
        }
    }

    /**
     * Promote provisional bars that a newer session has superseded to settled state.
     *
     * ponytail: finality follows provider session progression, not BEI settlement reconciliation.
     * Add official settlement-file reconciliation once a verified BEI source is available.
     */
    protected function promoteSupersededBars(string $ticker): int
    {
        $latestSession = MarketObservation::where('ticker', $ticker)
            ->where('interval', '1d')
            ->where('data_classification', 'PUBLIC_MARKET_EOD')
            ->where('source', 'IDX Edge PRO')
            ->where('validation_status', 'VALID')
            ->max('bar_date');

        if ($latestSession === null) {
            return 0;
        }

        return MarketObservation::where('ticker', $ticker)
            ->where('interval', '1d')
            ->where('data_classification', 'PUBLIC_MARKET_EOD')
            ->where('source', 'IDX Edge PRO')
            ->where('validation_status', 'VALID')
            ->where('is_eod_final', false)
            ->where('bar_date', '<', $latestSession)
            ->update([
                'is_eod_final' => true,
                'finality_basis' => 'SUPERSEDED_BY_NEWER_SESSION',
                'updated_at' => now(),
            ]);
    }

    /**
     * Apply date equality compatible with SQLite's test date storage while keeping PostgreSQL indexed equality.
     */
    protected function whereSessionDate($query, string $sessionDate)
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? $query->whereDate('bar_date', $sessionDate)
            : $query->where('bar_date', $sessionDate);
    }

    /**
     * Ingest bars idempotently into market_observations.
     * Detects hash changes on overlap, archives revisions, and checks for missing sessions.
     *
     * @param  array<int, array<string, mixed>>  $bars
     * @return array<string, int>
     */
    protected function ingestBars(string $ticker, array $bars): array
    {
        $newCount = 0;
        $revisedCount = 0;

        foreach ($bars as $bar) {
            $date = $bar['date'];
            $dataHash = hash('sha256', "{$ticker}|{$date}|{$bar['open']}|{$bar['high']}|{$bar['low']}|{$bar['close']}|{$bar['volume']}|IDX Edge PRO|PROVIDER_BACK_ADJUSTED");

            $existing = MarketObservation::where('ticker', $ticker)
                ->whereDate('bar_date', $date)
                ->where('interval', '1d')
                ->where('data_classification', 'PUBLIC_MARKET_EOD')
                ->where('source', 'IDX Edge PRO')
                ->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')
                ->first();

            if ($existing) {
                if ($existing->data_hash !== null && $existing->data_hash !== $dataHash) {
                    // Archive previous payload before revision
                    $revNumber = DataRevisionArchive::where('record_type', 'MarketObservation')
                        ->where('record_id', (string) $existing->id)
                        ->count() + 1;

                    DataRevisionArchive::create([
                        'record_type' => 'MarketObservation',
                        'record_id' => (string) $existing->id,
                        'ticker' => $ticker,
                        'period_or_date' => $date,
                        'revision_number' => $revNumber,
                        'previous_payload' => $existing->toArray(),
                        'previous_hash' => $existing->data_hash,
                        'new_payload' => $bar,
                        'new_hash' => $dataHash,
                        'changes' => [
                            'open' => ['old' => $existing->open_price, 'new' => $bar['open']],
                            'high' => ['old' => $existing->high_price, 'new' => $bar['high']],
                            'low' => ['old' => $existing->low_price, 'new' => $bar['low']],
                            'close' => ['old' => $existing->close_price, 'new' => $bar['close']],
                            'volume' => ['old' => $existing->volume, 'new' => $bar['volume']],
                        ],
                        'source' => 'IDX Edge PRO',
                        'reason' => 'EOD_OVERLAP_HASH_MISMATCH_OR_CORPORATE_ACTION',
                    ]);

                    $existing->update([
                        'observation_time' => Carbon::parse("{$date} 16:00:00", 'Asia/Jakarta')->utc(),
                        'open_price' => $bar['open'],
                        'high_price' => $bar['high'],
                        'low_price' => $bar['low'],
                        'close_price' => $bar['close'],
                        'volume' => $bar['volume'],
                        'data_hash' => $dataHash,
                        'fetched_at' => now('UTC'),
                        'available_at' => now('UTC'),
                    ]);

                    try {
                        app(PostHorizonEvaluatorService::class)->recalculateForRevisedBar($ticker, $date, (float) $bar['close']);
                    } catch (Throwable $e) {
                        Log::warning("Outcome recalculation failed for revised bar {$ticker} on {$date}: {$e->getMessage()}");
                    }

                    $revisedCount++;
                }
            } else {
                try {
                    MarketObservation::create([
                        'ticker' => $ticker,
                        'bar_date' => $date,
                        'interval' => '1d',
                        'data_classification' => 'PUBLIC_MARKET_EOD',
                        'source' => 'IDX Edge PRO',
                        'adjustment_mode' => 'PROVIDER_BACK_ADJUSTED',
                        'observation_time' => Carbon::parse("{$date} 16:00:00", 'Asia/Jakarta')->utc(),
                        'open_price' => $bar['open'],
                        'high_price' => $bar['high'],
                        'low_price' => $bar['low'],
                        'close_price' => $bar['close'],
                        'volume' => $bar['volume'],
                        'is_eod_final' => false, // Bar pasca-penutupan pasar berstatus provisional hingga verifikasi rekonsiliasi settlement
                        'delay_minutes' => 0,
                        'data_hash' => $dataHash,
                        'timezone' => 'Asia/Jakarta',
                        'currency' => 'IDR',
                        'finality_basis' => 'PROVISIONAL_POST_MARKET',
                        'validation_status' => $bar['validation_status'] ?? 'VALID',
                        'board_scope' => in_array($ticker, ['^JKSE', 'JKSE', 'IHSG'], true) ? 'INDEX' : 'RG',
                        'fetched_at' => now('UTC'),
                        'available_at' => now('UTC'),
                    ]);
                    $newCount++;
                } catch (QueryException $e) {
                    // Concurrent writer won the canonical unique key; the row already exists.
                    if (($e->errorInfo[0] ?? '') !== '23505') {
                        throw $e;
                    }
                }
            }
        }

        return [
            'new_bars' => $newCount,
            'revised_bars' => $revisedCount,
        ];
    }

    /**
     * Ingest UNADJUSTED (nominal) bars idempotently into market_observations.
     *
     * @param  array<int, array<string, mixed>>  $bars
     */
    protected function ingestNominalBars(string $ticker, array $bars): void
    {
        foreach ($bars as $bar) {
            $date = $bar['date'];
            $dataHash = hash('sha256', "{$ticker}|{$date}|{$bar['open']}|{$bar['high']}|{$bar['low']}|{$bar['close']}|{$bar['volume']}|IDX Edge PRO|UNADJUSTED");

            $existing = MarketObservation::where('ticker', $ticker)
                ->whereDate('bar_date', $date)
                ->where('interval', '1d')
                ->where('data_classification', 'PUBLIC_MARKET_EOD')
                ->where('source', 'IDX Edge PRO')
                ->where('adjustment_mode', 'UNADJUSTED')
                ->first();

            if ($existing) {
                if ($existing->data_hash !== null && $existing->data_hash !== $dataHash) {
                    $existing->update([
                        'observation_time' => Carbon::parse("{$date} 16:00:00", 'Asia/Jakarta')->utc(),
                        'open_price' => $bar['open'],
                        'high_price' => $bar['high'],
                        'low_price' => $bar['low'],
                        'close_price' => $bar['close'],
                        'volume' => $bar['volume'],
                        'data_hash' => $dataHash,
                        'fetched_at' => now('UTC'),
                        'available_at' => now('UTC'),
                    ]);
                }
            } else {
                try {
                    MarketObservation::create([
                        'ticker' => $ticker,
                        'bar_date' => $date,
                        'interval' => '1d',
                        'data_classification' => 'PUBLIC_MARKET_EOD',
                        'source' => 'IDX Edge PRO',
                        'adjustment_mode' => 'UNADJUSTED',
                        'observation_time' => Carbon::parse("{$date} 16:00:00", 'Asia/Jakarta')->utc(),
                        'open_price' => $bar['open'],
                        'high_price' => $bar['high'],
                        'low_price' => $bar['low'],
                        'close_price' => $bar['close'],
                        'volume' => $bar['volume'],
                        'is_eod_final' => false,
                        'delay_minutes' => 0,
                        'data_hash' => $dataHash,
                        'timezone' => 'Asia/Jakarta',
                        'currency' => 'IDR',
                        'finality_basis' => 'PROVISIONAL_POST_MARKET',
                        'validation_status' => $bar['validation_status'] ?? 'VALID',
                        'board_scope' => in_array($ticker, ['^JKSE', 'JKSE', 'IHSG'], true) ? 'INDEX' : 'RG',
                        'fetched_at' => now('UTC'),
                        'available_at' => now('UTC'),
                    ]);
                } catch (QueryException $e) {
                    // Concurrent writer won the canonical unique key; the row already exists.
                    if (($e->errorInfo[0] ?? '') !== '23505') {
                        throw $e;
                    }
                }
            }
        }
    }
}
