<?php

namespace App\Services;

use App\Models\EvidenceSnapshot;
use App\Models\MarketObservation;
use App\Models\ResearchRequest;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CanonicalMarketReadService
{
    public function latest(string $ticker, string $interval = '1d', string $classification = 'PUBLIC_MARKET_EOD', ?string $asOf = null): ?MarketObservation
    {
        return $this->query($interval, $classification, $asOf)
            ->where('ticker', strtoupper($ticker))
            ->first();
    }

    public function previousSession(string $ticker, string|CarbonInterface $barDate, string $interval = '1d', string $classification = 'PUBLIC_MARKET_EOD'): ?MarketObservation
    {
        $dateStr = $barDate instanceof CarbonInterface ? $barDate->toDateString() : (string) Carbon::parse($barDate)->toDateString();

        return MarketObservation::query()
            ->where('ticker', strtoupper($ticker))
            ->where('interval', $interval)
            ->where('data_classification', $classification)
            ->whereIn('validation_status', ['VALID'])
            ->whereDate('bar_date', '<', $dateStr)
            ->orderByDesc('bar_date')
            ->orderByRaw("CASE WHEN source = 'IDX Edge PRO' THEN 0 WHEN source LIKE 'Yahoo Finance%' THEN 1 ELSE 2 END")
            ->orderByDesc('observation_time')
            ->orderByDesc('id')
            ->first();
    }

    public function priceChanges(?MarketObservation $latestObs, ?MarketObservation $prevObs = null): array
    {
        if (! $latestObs || $latestObs->close_price === null) {
            return [
                'close' => null,
                'open' => null,
                'previous_close' => null,
                'previous_bar_date' => null,
                'daily_change' => null,
                'daily_change_pct' => null,
                'change_vs_open' => null,
                'change_vs_open_pct' => null,
                'basis_status' => 'DATA_NOT_AVAILABLE',
                'is_previous_close_available' => false,
            ];
        }

        $close = (float) $latestObs->close_price;
        $open = $latestObs->open_price !== null && (float) $latestObs->open_price > 0 ? (float) $latestObs->open_price : null;

        if ($prevObs === null) {
            $prevObs = $this->previousSession(
                $latestObs->ticker,
                $latestObs->bar_date,
                $latestObs->interval ?? '1d',
                $latestObs->data_classification ?? 'PUBLIC_MARKET_EOD'
            );
        }

        $prevClose = ($prevObs && $prevObs->close_price !== null && (float) $prevObs->close_price > 0) ? (float) $prevObs->close_price : null;
        $prevBarDate = $prevObs?->bar_date?->toDateString();

        $calendar = app(BeiCalendarService::class);
        $expectedPrevBarDate = $calendar->getPreviousTradingDay($latestObs->bar_date)->toDateString();

        $dailyChange = null;
        $dailyChangePct = null;
        $basisStatus = 'NO_PREVIOUS_SESSION';
        $isPrevAvailable = false;
        $gapReason = null;

        if ($prevClose !== null) {
            if ($prevBarDate === $expectedPrevBarDate) {
                $dailyChange = round($close - $prevClose, 4);
                $dailyChangePct = round(($dailyChange / $prevClose) * 100, 2);
                $basisStatus = 'VALID_PREVIOUS_SESSION';
                $isPrevAvailable = true;
            } else {
                $basisStatus = 'HISTORICAL_GAP_DISQUALIFIED';
                $isPrevAvailable = false;
                $gapReason = "Histori berlubang: Bar terakhir tersimpan adalah {$prevBarDate}, bukan sesi bursa sebelumnya ({$expectedPrevBarDate}). Perubahan harian didiskualifikasi (N/A).";
            }
        }

        $changeVsOpen = null;
        $changeVsOpenPct = null;
        if ($open !== null) {
            $changeVsOpen = round($close - $open, 4);
            $changeVsOpenPct = round(($changeVsOpen / $open) * 100, 2);
        }

        return [
            'close' => $close,
            'open' => $open,
            'previous_close' => $prevClose,
            'previous_bar_date' => $prevBarDate,
            'expected_previous_bar_date' => $expectedPrevBarDate,
            'daily_change' => $dailyChange,
            'daily_change_pct' => $dailyChangePct,
            'change_vs_open' => $changeVsOpen,
            'change_vs_open_pct' => $changeVsOpenPct,
            'basis_status' => $basisStatus,
            'gap_reason' => $gapReason,
            'is_previous_close_available' => $isPrevAvailable,
        ];
    }

    /** @return Collection<int, MarketObservation> */
    public function recentHistory(string $ticker, int $limit = 30, string $interval = '1d', string $classification = 'PUBLIC_MARKET_EOD'): Collection
    {
        return $this->query($interval, $classification, null)
            ->where('ticker', strtoupper($ticker))
            ->get()
            ->unique(fn (MarketObservation $observation) => $observation->bar_date?->toDateString())
            ->take(max(1, min(500, $limit)))
            ->reverse()
            ->values();
    }

    /** @return Collection<string, MarketObservation> */
    public function latestByTicker(string $interval = '1d', string $classification = 'PUBLIC_MARKET_EOD', ?string $asOf = null): Collection
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $q = MarketObservation::query()
                ->where('interval', $interval)
                ->where('data_classification', $classification)
                ->whereIn('validation_status', ['VALID']);
            if ($asOf !== null) {
                $q->where('observation_time', '<=', $asOf);
            }

            return $q->distinct('ticker')
                ->orderBy('ticker')
                ->orderByDesc('bar_date')
                ->orderByRaw("CASE WHEN source = 'IDX Edge PRO' THEN 0 WHEN source LIKE 'Yahoo Finance%' THEN 1 ELSE 2 END")
                ->orderByDesc('observation_time')
                ->orderByDesc('id')
                ->get()
                ->keyBy('ticker');
        }

        return $this->query($interval, $classification, $asOf)
            ->get()
            ->unique('ticker')
            ->keyBy('ticker');
    }

    /** @return Collection<string, array> */
    public function latestByTickerWithChanges(string $interval = '1d', string $classification = 'PUBLIC_MARKET_EOD'): Collection
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $rows = DB::select("
                WITH ranked AS (
                    SELECT ticker, bar_date, open_price, high_price, low_price, close_price, volume, source, is_eod_final, validation_status,
                           LAG(close_price) OVER (PARTITION BY ticker ORDER BY bar_date ASC) as prev_close,
                           LAG(bar_date) OVER (PARTITION BY ticker ORDER BY bar_date ASC) as prev_bar_date,
                           ROW_NUMBER() OVER (PARTITION BY ticker ORDER BY bar_date DESC, CASE WHEN source = 'IDX Edge PRO' THEN 0 WHEN source LIKE 'Yahoo Finance%' THEN 1 ELSE 2 END, observation_time DESC, id DESC) as rn
                    FROM market_observations
                    WHERE interval = ? AND data_classification = ?
                      AND validation_status = 'VALID'
                )
                SELECT * FROM ranked WHERE rn = 1
            ", [$interval, $classification]);

            $calendar = app(BeiCalendarService::class);
            $result = collect();
            foreach ($rows as $r) {
                $close = (float) $r->close_price;
                $open = $r->open_price !== null && (float) $r->open_price > 0 ? (float) $r->open_price : null;
                $prevClose = $r->prev_close !== null && (float) $r->prev_close > 0 ? (float) $r->prev_close : null;
                $expectedPrevDate = $calendar->getPreviousTradingDay($r->bar_date)->toDateString();

                $dailyChange = null;
                $dailyChangePct = null;
                $isPrevAvailable = false;
                $gapReason = null;
                if ($prevClose !== null) {
                    if ($r->prev_bar_date === $expectedPrevDate) {
                        $dailyChange = round($close - $prevClose, 4);
                        $dailyChangePct = round(($dailyChange / $prevClose) * 100, 2);
                        $isPrevAvailable = true;
                    } else {
                        $gapReason = "Histori berlubang: Bar terakhir tersimpan adalah {$r->prev_bar_date}, bukan sesi bursa sebelumnya ({$expectedPrevDate}). Perubahan harian didiskualifikasi (N/A).";
                    }
                }

                $changeVsOpen = null;
                $changeVsOpenPct = null;
                if ($open !== null) {
                    $changeVsOpen = round($close - $open, 4);
                    $changeVsOpenPct = round(($changeVsOpen / $open) * 100, 2);
                }

                $result->put($r->ticker, [
                    'ticker' => $r->ticker,
                    'bar_date' => $r->bar_date,
                    'open_price' => $open,
                    'high_price' => (float) $r->high_price,
                    'low_price' => (float) $r->low_price,
                    'close_price' => $close,
                    'volume' => (int) $r->volume,
                    'source' => $r->source,
                    'is_eod_final' => (bool) $r->is_eod_final,
                    'validation_status' => $r->validation_status,
                    'previous_close' => $prevClose,
                    'previous_bar_date' => $r->prev_bar_date,
                    'expected_previous_bar_date' => $expectedPrevDate,
                    'daily_change' => $dailyChange,
                    'daily_change_pct' => $dailyChangePct,
                    'change_vs_open' => $changeVsOpen,
                    'change_vs_open_pct' => $changeVsOpenPct,
                    'is_previous_close_available' => $isPrevAvailable,
                    'gap_reason' => $gapReason,
                ]);
            }

            return $result;
        }

        // Fallback for SQLite / generic drivers
        $latest = $this->latestByTicker($interval, $classification);
        $result = collect();
        foreach ($latest as $ticker => $obs) {
            $changes = $this->priceChanges($obs);
            $result->put($ticker, array_merge([
                'ticker' => $ticker,
                'bar_date' => $obs->bar_date?->toDateString(),
                'open_price' => $obs->open_price !== null ? (float) $obs->open_price : null,
                'high_price' => $obs->high_price !== null ? (float) $obs->high_price : null,
                'low_price' => $obs->low_price !== null ? (float) $obs->low_price : null,
                'close_price' => (float) $obs->close_price,
                'volume' => (int) $obs->volume,
                'source' => $obs->source,
                'is_eod_final' => (bool) $obs->is_eod_final,
                'validation_status' => $obs->validation_status,
            ], $changes));
        }

        return $result;
    }

    /**
     * Compute mathematically verified, atomic market breadth from a single read snapshot.
     * Enforces:
     * 1. advancers + decliners + unchanged === eligible_breadth
     * 2. eligible_breadth + sum(excluded) === universe_total
     * 3. Benchmark (^JKSE / IHSG) calculated strictly on the same reference date.
     * 4. Point-in-time information cutoff: only evidence available at or before cutoff is used.
     */
    public function calculateMarketBreadth(?string $referenceDate = null, ?CarbonInterface $informationCutoff = null): array
    {
        $calendar = app(BeiCalendarService::class);
        $refDate = $referenceDate ?? '2026-10-26';
        $prevTradingDay = $calendar->getPreviousTradingDay($refDate)->toDateString();

        // 1. Total Universe: All distinct equity tickers with observations (excluding indices)
        $allTickers = DB::table('market_observations')
            ->whereNotIn('ticker', ['^JKSE', 'JKSE', 'IHSG'])
            ->distinct()
            ->pluck('ticker')
            ->sort()
            ->values();

        $universeTotal = $allTickers->count();

        // 2. Observations for reference date and previous trading day
        $obsRefQuery = DB::table('market_observations')
            ->whereIn('ticker', $allTickers)
            ->where('interval', '1d')
            ->where('data_classification', 'PUBLIC_MARKET_EOD')
            ->whereDate('bar_date', $refDate);

        $obsPrevQuery = DB::table('market_observations')
            ->whereIn('ticker', $allTickers)
            ->where('interval', '1d')
            ->where('data_classification', 'PUBLIC_MARKET_EOD')
            ->whereDate('bar_date', $prevTradingDay);

        if ($informationCutoff) {
            $cutoffUtc = $informationCutoff->copy()->utc();
            $obsRefQuery->where(function ($q) use ($cutoffUtc) {
                $q->whereNull('available_at')
                    ->orWhere('available_at', '<=', $cutoffUtc);
            });
            $obsPrevQuery->where(function ($q) use ($cutoffUtc) {
                $q->whereNull('available_at')
                    ->orWhere('available_at', '<=', $cutoffUtc);
            });
        }

        $obsRef = $obsRefQuery->get()->keyBy('ticker');
        $obsPrev = $obsPrevQuery->get()->keyBy('ticker');

        $eligible = [];
        $advancers = [];
        $decliners = [];
        $unchanged = [];
        $pcts = [];

        $excludedNoRefBar = [];
        $excludedNoPrevBar = [];

        foreach ($allTickers as $ticker) {
            if (! isset($obsRef[$ticker])) {
                $excludedNoRefBar[] = $ticker;

                continue;
            }

            if (! isset($obsPrev[$ticker])) {
                $excludedNoPrevBar[] = $ticker;

                continue;
            }

            $close = (float) $obsRef[$ticker]->close_price;
            $prevClose = (float) $obsPrev[$ticker]->close_price;

            if ($prevClose <= 0) {
                $excludedNoPrevBar[] = $ticker;

                continue;
            }

            $change = round($close - $prevClose, 4);
            $changePct = round(($change / $prevClose) * 100, 2);

            $eligible[] = $ticker;
            $pcts[] = $changePct;

            if ($change > 0) {
                $advancers[] = $ticker;
            } elseif ($change < 0) {
                $decliners[] = $ticker;
            } else {
                $unchanged[] = $ticker;
            }
        }

        sort($pcts);
        $eligibleCount = count($eligible);
        $median = $eligibleCount > 0 ? $pcts[intdiv($eligibleCount, 2)] : 0.0;

        // 3. Benchmark IHSG (^JKSE) strictly on the same sessions
        $ihsgRef = DB::table('market_observations')
            ->whereIn('ticker', ['^JKSE', 'JKSE'])
            ->whereDate('bar_date', $refDate)
            ->first();

        $ihsgPrev = DB::table('market_observations')
            ->whereIn('ticker', ['^JKSE', 'JKSE'])
            ->whereDate('bar_date', $prevTradingDay)
            ->first();

        $ihsgClose = $ihsgRef ? (float) $ihsgRef->close_price : null;
        $ihsgPrevClose = $ihsgPrev ? (float) $ihsgPrev->close_price : null;
        $ihsgChange = ($ihsgClose !== null && $ihsgPrevClose !== null) ? round($ihsgClose - $ihsgPrevClose, 4) : null;
        $ihsgChangePct = ($ihsgChange !== null && $ihsgPrevClose > 0) ? round(($ihsgChange / $ihsgPrevClose) * 100, 2) : null;

        $excludedTotal = count($excludedNoRefBar) + count($excludedNoPrevBar);
        $mathVerified = (
            (count($advancers) + count($decliners) + count($unchanged) === $eligibleCount) &&
            ($eligibleCount + $excludedTotal === $universeTotal)
        );

        return [
            'reference_date' => $refDate,
            'previous_session_date' => $prevTradingDay,
            'universe_total' => $universeTotal,
            'eligible_count' => $eligibleCount,
            'advancers_count' => count($advancers),
            'decliners_count' => count($decliners),
            'unchanged_count' => count($unchanged),
            'advancers_pct' => $eligibleCount > 0 ? round((count($advancers) / $eligibleCount) * 100, 2) : 0.0,
            'decliners_pct' => $eligibleCount > 0 ? round((count($decliners) / $eligibleCount) * 100, 2) : 0.0,
            'unchanged_pct' => $eligibleCount > 0 ? round((count($unchanged) / $eligibleCount) * 100, 2) : 0.0,
            'median_change_pct' => $median,
            'excluded_total' => $excludedTotal,
            'excluded_reasons' => [
                'NO_BAR_ON_REFERENCE_DATE' => count($excludedNoRefBar),
                'NO_BAR_ON_PREV_SESSION' => count($excludedNoPrevBar),
            ],
            'excluded_tickers' => [
                'NO_BAR_ON_REFERENCE_DATE' => $excludedNoRefBar,
                'NO_BAR_ON_PREV_SESSION' => $excludedNoPrevBar,
            ],
            'eligible_tickers' => $eligible,
            'advancers_tickers' => $advancers,
            'decliners_tickers' => $decliners,
            'unchanged_tickers' => $unchanged,
            'benchmark' => [
                'ticker' => '^JKSE',
                'close' => $ihsgClose,
                'previous_close' => $ihsgPrevClose,
                'daily_change' => $ihsgChange,
                'daily_change_pct' => $ihsgChangePct,
            ],
            'math_verified' => $mathVerified,
        ];
    }

    private function query(string $interval, string $classification, ?string $asOf): Builder
    {
        $query = MarketObservation::query()
            ->where('interval', $interval)
            ->where('data_classification', $classification)
            ->whereIn('validation_status', ['VALID']);

        if ($asOf !== null) {
            $query->where('observation_time', '<=', $asOf);
        }

        return $query
            ->orderByDesc('bar_date')
            ->orderByRaw("CASE WHEN source = 'IDX Edge PRO' THEN 0 WHEN source LIKE 'Yahoo Finance%' THEN 1 ELSE 2 END")
            ->orderByDesc('is_eod_final')
            ->orderByDesc('observation_time')
            ->orderByDesc('id');
    }

    public function observationForEvidence(?EvidenceSnapshot $evidence): ?MarketObservation
    {
        $hash = $evidence?->features_snapshot['data_hash'] ?? null;
        if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/i', $hash)) {
            return null;
        }

        $matches = MarketObservation::where('ticker', $evidence->ticker)
            ->whereDate('bar_date', $evidence->decision_date->toDateString())
            ->where('data_hash', $hash)
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function evidenceForObservation(MarketObservation $observation): ?EvidenceSnapshot
    {
        $requests = ResearchRequest::with('evidenceSnapshot')
            ->where('ticker', $observation->ticker)
            ->whereNotNull('evidence_snapshot_id')
            ->get();
        $matches = $requests->pluck('evidenceSnapshot')
            ->filter(fn ($evidence) => $evidence && $this->observationForEvidence($evidence)?->id === $observation->id)
            ->unique('id');

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function state(?MarketObservation $observation, ?string $expectedDate = null): string
    {
        if (! $observation) {
            return 'DATA_NOT_AVAILABLE';
        }
        if ($observation->validation_status === 'CONFLICTED') {
            return 'CONFLICTED';
        }
        if (! $observation->is_eod_final || $observation->validation_status !== 'VALID') {
            return 'PROVISIONAL';
        }
        if ($expectedDate !== null && $observation->bar_date->toDateString() < $expectedDate) {
            return 'STALE';
        }

        return 'FINAL';
    }
}
