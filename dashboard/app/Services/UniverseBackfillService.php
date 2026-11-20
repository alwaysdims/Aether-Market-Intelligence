<?php

namespace App\Services;

use App\Models\IdxCompanyRegistry;
use App\Models\MarketObservation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class UniverseBackfillService
{
    public function __construct(
        protected IdxEdgeClient $client,
        protected TechnicalMetricsCalculationService $metricsService
    ) {}

    /**
     * Backfill 1-year OHLCV (+ SMA200 warmup) for a single ticker.
     *
     * @return array<string, mixed>
     */
    public function backfillTicker(string $ticker, bool $includeRaw = false, int $limit = 500): array
    {
        $ticker = strtoupper(trim($ticker));
        $registry = IdxCompanyRegistry::where('ticker', $ticker)->first();

        // 0. Pre-validation: verify instrument class and ticker format compatibility
        if (strlen($ticker) !== 4 || ($registry && $registry->instrument_type === 'INDEX')) {
            $isIndex = ($registry && $registry->instrument_type === 'INDEX') || str_starts_with($ticker, '^');
            $status = $isIndex ? 'EXCLUDED_INDEX' : 'UNSUPPORTED_SHARE_CLASS';
            $reason = $isIndex
                ? 'Instrumen indeks komposit bursa (^JKSE), bukan saham ekuitas reguler'
                : 'Kelas efek tidak didukung provider: Ticker bukan 4 huruf reguler (MVS / Saham Khusus)';

            if ($registry) {
                $registry->update([
                    'backfill_status' => $status,
                    'failure_reason' => $reason,
                ]);
            }

            return [
                'ticker' => $ticker,
                'success' => false,
                'error_type' => $status,
                'message' => $reason,
                'bars_synced' => 0,
            ];
        }

        // 1. Fetch Adjusted History (Primary Operational Series) with retry on local rate limit
        $fetchAdjusted = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $fetchAdjusted = $this->client->history($ticker, $limit, true);
            if (($fetchAdjusted['error_type'] ?? '') === 'LOCAL_RATE_LIMIT') {
                $wait = max(2, (int) ($fetchAdjusted['available_in'] ?? 5));
                sleep($wait);

                continue;
            }
            break;
        }

        if (! ($fetchAdjusted['success'] ?? false) || empty($fetchAdjusted['data']['bars'])) {
            $reason = $fetchAdjusted['message'] ?? 'Gagal mengambil histori dari provider.';
            $errorType = $fetchAdjusted['error_type'] ?? 'FETCH_FAILED';

            // Jangan tandai emiten FAILED jika semata-mata kuota harian habis atau rate limit lokal
            // Tandai PENDING agar dapat di-resume secara elegan pada siklus berikutnya
            $newStatus = in_array($errorType, ['DAILY_BUDGET_RESERVED', 'LOCAL_RATE_LIMIT', 'PROVIDER_RATE_LIMIT'])
                ? 'PENDING'
                : 'FAILED';

            if ($registry) {
                $registry->update([
                    'backfill_status' => $newStatus,
                    'failure_reason' => $reason,
                ]);
            }

            return [
                'ticker' => $ticker,
                'success' => false,
                'error_type' => $errorType,
                'message' => $reason,
                'bars_synced' => 0,
            ];
        }

        $bars = $fetchAdjusted['data']['bars'];
        $dataAvailable = $fetchAdjusted['data']['data_available'] ?? null;
        $syncedAdjusted = 0;
        $firstDate = null;
        $lastDate = null;

        // Sort bars ascending by date
        usort($bars, fn ($a, $b) => strcmp($a['date'], $b['date']));

        foreach ($bars as $bar) {
            $date = $bar['date'];
            if ($firstDate === null) {
                $firstDate = $date;
            }
            $lastDate = $date;

            $dataHash = hash('sha256', "{$ticker}|{$date}|{$bar['open']}|{$bar['high']}|{$bar['low']}|{$bar['close']}|{$bar['volume']}|IDX Edge PRO|PROVIDER_BACK_ADJUSTED");

            MarketObservation::updateOrCreate(
                [
                    'ticker' => $ticker,
                    'bar_date' => $date,
                    'interval' => '1d',
                    'data_classification' => 'PUBLIC_MARKET_EOD',
                    'source' => 'IDX Edge PRO',
                    'adjustment_mode' => 'PROVIDER_BACK_ADJUSTED',
                ],
                [
                    'observation_time' => Carbon::parse("{$date} 16:00:00", 'Asia/Jakarta')->utc(),
                    'open_price' => $bar['open'],
                    'high_price' => $bar['high'],
                    'low_price' => $bar['low'],
                    'close_price' => $bar['close'],
                    'volume' => $bar['volume'],
                    'is_eod_final' => true,
                    'delay_minutes' => 0,
                    'data_hash' => $dataHash,
                    'timezone' => 'Asia/Jakarta',
                    'currency' => 'IDR',
                    'finality_basis' => ($bar['validation_status'] ?? 'VALID') === 'OHLC_INVALID'
                        ? 'IDX Edge PRO Back-adjusted [OHLC_INVALID]'
                        : 'BEI official closing settlement via IDX Edge PRO (Pasar Reguler - RG) - Back-adjusted',
                    'validation_status' => $bar['validation_status'] ?? 'VALID',
                    'board_scope' => 'RG',
                    'fetched_at' => now('UTC'),
                    'available_at' => now('UTC'),
                ]
            );
            $syncedAdjusted++;
        }

        // 2. Fetch Raw History if requested
        $syncedRaw = 0;
        if ($includeRaw) {
            $fetchRaw = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $fetchRaw = $this->client->history($ticker, $limit, false);
                if (($fetchRaw['error_type'] ?? '') === 'LOCAL_RATE_LIMIT') {
                    $wait = max(2, (int) ($fetchRaw['available_in'] ?? 5));
                    sleep($wait);

                    continue;
                }
                break;
            }
            if (($fetchRaw['success'] ?? false) && ! empty($fetchRaw['data']['bars'])) {
                foreach ($fetchRaw['data']['bars'] as $rBar) {
                    $rDate = $rBar['date'];
                    $rHash = hash('sha256', "{$ticker}|{$rDate}|{$rBar['open']}|{$rBar['high']}|{$rBar['low']}|{$rBar['close']}|{$rBar['volume']}|IDX Edge PRO|UNADJUSTED");

                    MarketObservation::updateOrCreate(
                        [
                            'ticker' => $ticker,
                            'bar_date' => $rDate,
                            'interval' => '1d',
                            'data_classification' => 'PUBLIC_MARKET_EOD',
                            'source' => 'IDX Edge PRO',
                            'adjustment_mode' => 'UNADJUSTED',
                        ],
                        [
                            'observation_time' => Carbon::parse("{$rDate} 16:00:00", 'Asia/Jakarta')->utc(),
                            'open_price' => $rBar['open'],
                            'high_price' => $rBar['high'],
                            'low_price' => $rBar['low'],
                            'close_price' => $rBar['close'],
                            'volume' => $rBar['volume'],
                            'is_eod_final' => true,
                            'delay_minutes' => 0,
                            'data_hash' => $rHash,
                            'timezone' => 'Asia/Jakarta',
                            'currency' => 'IDR',
                            'finality_basis' => ($rBar['validation_status'] ?? 'VALID') === 'OHLC_INVALID'
                                ? 'IDX Edge PRO Nominal/Raw [OHLC_INVALID]'
                                : 'BEI official closing settlement via IDX Edge PRO (Pasar Reguler - RG) - Nominal/Unadjusted',
                            'validation_status' => $rBar['validation_status'] ?? 'VALID',
                            'board_scope' => 'RG',
                            'fetched_at' => now('UTC'),
                            'available_at' => now('UTC'),
                        ]
                    );
                    $syncedRaw++;
                }
            }
        }

        // 3. Determine backfill status with verified listing date / data range evidence
        $backfillStatus = 'COMPLETE';
        $failureReason = null;
        $oneYearAgo = Carbon::now('Asia/Jakarta')->subYear()->startOfDay();

        if ($syncedAdjusted < 200) {
            $listingDate = $registry?->listing_date;
            if ($listingDate && $listingDate->greaterThanOrEqualTo($oneYearAgo)) {
                $backfillStatus = 'SHORT_LISTING_HISTORY';
            } else {
                $backfillStatus = 'INCOMPLETE_DATA_GAP';
                $failureReason = "Bar diterima ({$syncedAdjusted}) < 200 hari bursa padahal listing sejak ".($listingDate ? $listingDate->toDateString() : 'tidak tercatat');
            }
        }

        $metadata = [
            'requested_limit' => $limit,
            'include_raw' => $includeRaw,
            'data_available_reported' => $dataAvailable,
            'adjusted_bars_count' => $syncedAdjusted,
            'raw_bars_count' => $syncedRaw,
            'first_bar_date' => $firstDate,
            'last_bar_date' => $lastDate,
            'has_invalid_bars' => ($fetchAdjusted['has_invalid_bars'] ?? false) || ($fetchRaw['has_invalid_bars'] ?? false),
            'invalid_bars_count' => ($fetchAdjusted['invalid_bars_count'] ?? 0) + ($fetchRaw['invalid_bars_count'] ?? 0),
            'listing_date_verified' => $registry?->listing_date?->toDateString(),
            'is_short_listing_verified' => ($backfillStatus === 'SHORT_LISTING_HISTORY'),
            'last_success_at' => now('UTC')->toIso8601String(),
        ];

        if ($registry) {
            $registry->update([
                'first_bar_date' => $firstDate,
                'last_bar_date' => $lastDate,
                'adjusted_bars_count' => $syncedAdjusted,
                'raw_bars_count' => $syncedRaw,
                'last_success_at' => now('UTC'),
                'backfill_status' => $backfillStatus,
                'failure_reason' => $failureReason,
                'backfill_metadata' => $metadata,
            ]);
        }

        // 4. Calculate Deterministic Technical Metrics (SMA20, 50, 200, RSI14, MACD, ATR14)
        try {
            $this->metricsService->calculateForTicker($ticker);
        } catch (Throwable $e) {
            Log::warning("Failed to calculate technical metrics for {$ticker}: {$e->getMessage()}");
        }

        return [
            'ticker' => $ticker,
            'success' => true,
            'bars_synced_adjusted' => $syncedAdjusted,
            'bars_synced_raw' => $syncedRaw,
            'first_bar_date' => $firstDate,
            'last_bar_date' => $lastDate,
            'backfill_status' => $backfillStatus,
            'data_available' => $dataAvailable,
        ];
    }

    /**
     * Backfill a list of tickers with rate limiting and progress tracking.
     *
     * @param  array<int, string>  $tickers
     * @return array<string, mixed>
     */
    public function backfillBatch(array $tickers, bool $includeRaw = false, int $limit = 500, ?callable $onProgress = null): array
    {
        $total = count($tickers);
        $completed = 0;
        $shortListing = 0;
        $failed = 0;
        $totalBars = 0;
        $results = [];

        foreach ($tickers as $index => $ticker) {
            $res = $this->backfillTicker($ticker, $includeRaw, $limit);
            $results[$ticker] = $res;

            if ($res['success']) {
                $totalBars += ($res['bars_synced_adjusted'] ?? 0);
                if ($res['backfill_status'] === 'SHORT_LISTING_HISTORY') {
                    $shortListing++;
                } else {
                    $completed++;
                }
            } else {
                $failed++;
            }

            if ($onProgress) {
                $onProgress($ticker, $index + 1, $total, $res);
            }

            // Hentikan batch jika kuota harian operasional telah tercapai / dilindungi
            // Jangan buang-buang siklus memproses sisa ticker dengan kegagalan yang sama
            if (! $res['success'] && ($res['error_type'] ?? '') === 'DAILY_BUDGET_RESERVED') {
                Log::info("[UniverseBackfillService] Cadangan kuota harian tercapai pada {$ticker}. Batch dihentikan tertib untuk dilanjutkan pada siklus berikutnya.");
                break;
            }

            // Yield control between requests (350ms) to ensure cooperative scheduling and rate limit adherence
            usleep(350000);
        }

        return [
            'total_tickers' => $total,
            'completed' => $completed,
            'short_listing' => $shortListing,
            'failed' => $failed,
            'total_bars_synced' => $totalBars,
            'results' => $results,
        ];
    }
}
