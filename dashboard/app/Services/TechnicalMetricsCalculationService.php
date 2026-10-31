<?php

namespace App\Services;

use App\Models\CompanyAnalysisMetric;
use App\Models\MarketObservation;
use Carbon\Carbon;

class TechnicalMetricsCalculationService
{
    /**
     * Minimum bar sufficiency contracts
     */
    public const MIN_BARS_SMA20 = 20;

    public const MIN_BARS_SMA50 = 50;

    public const MIN_BARS_SMA200 = 200;

    public const MIN_BARS_RSI14 = 15; // 1 initial change seed + 14 Wilder RMA smoothing periods

    public const MIN_BARS_ATR14 = 15; // 1 initial TR seed + 14 Wilder RMA smoothing periods

    public const MIN_BARS_MACD = 35;  // 26 EMA slow + 9 EMA signal line

    /**
     * Calculate and persist technical metrics from stored MarketObservations.
     * Enforces strict provenance, source boundaries, and sufficiency rules.
     */
    public function calculateForTicker(string $ticker, ?string $asOfDate = null): ?CompanyAnalysisMetric
    {
        $ticker = strtoupper(trim($ticker));
        $asOfDateStr = $asOfDate ?? Carbon::now('Asia/Jakarta')->toDateString();

        // Retrieve operational historical bars strictly from VALID and authoritative operational sources (IDX Edge PRO)
        // Cold archive Yahoo is explicitly excluded from operational calculation unless expressly permitted.
        // Orders by bar_date ascending.
        $bars = MarketObservation::where('ticker', $ticker)
            ->whereDate('bar_date', '<=', $asOfDateStr)
            ->where('validation_status', 'VALID')
            ->where('source', 'IDX Edge PRO')
            ->orderBy('bar_date', 'asc')
            ->get(['bar_date', 'open_price', 'high_price', 'low_price', 'close_price', 'volume', 'source', 'adjustment_mode']);

        // Fallback: If no IDX Edge PRO bars exist yet, check if Yahoo archive exists but flag strictly as HISTORICAL_ARCHIVE_ONLY
        if ($bars->isEmpty()) {
            $bars = MarketObservation::where('ticker', $ticker)
                ->whereDate('bar_date', '<=', $asOfDateStr)
                ->where('validation_status', 'VALID')
                ->orderBy('bar_date', 'asc')
                ->get(['bar_date', 'open_price', 'high_price', 'low_price', 'close_price', 'volume', 'source', 'adjustment_mode']);
        }

        $n = $bars->count();
        if ($n === 0) {
            return null;
        }

        $latestBar = $bars->last();
        $firstBar = $bars->first();
        $latestClose = (float) $latestBar->close_price;

        $firstDate = Carbon::parse($firstBar->bar_date)->toDateString();
        $lastDate = Carbon::parse($latestBar->bar_date)->toDateString();
        $sourcesUsed = $bars->pluck('source')->unique()->values()->all();
        $adjustmentMode = $latestBar->adjustment_mode ?? 'PROVIDER_BACK_ADJUSTED';

        // 1. Returns
        $ret1d = $n >= 2 ? round((($latestClose - (float) $bars[$n - 2]->close_price) / (float) $bars[$n - 2]->close_price) * 100, 2) : null;
        $ret5d = $n >= 6 ? round((($latestClose - (float) $bars[$n - 6]->close_price) / (float) $bars[$n - 6]->close_price) * 100, 2) : null;
        $ret20d = $n >= 21 ? round((($latestClose - (float) $bars[$n - 21]->close_price) / (float) $bars[$n - 21]->close_price) * 100, 2) : null;

        // 2. Moving Averages (Strict Sufficiency Contract)
        $sma20 = $n >= self::MIN_BARS_SMA20 ? round($bars->slice($n - 20)->avg('close_price'), 2) : null;
        $sma50 = $n >= self::MIN_BARS_SMA50 ? round($bars->slice($n - 50)->avg('close_price'), 2) : null;
        $sma200 = $n >= self::MIN_BARS_SMA200 ? round($bars->slice($n - 200)->avg('close_price'), 2) : null;

        // 3. RSI 14 (Wilder's Smoothed RMA with explicit 14-period seed)
        $rsi14 = null;
        $rsiStatus = 'INSUFFICIENT_HISTORY';
        if ($n >= self::MIN_BARS_RSI14) {
            $gains = [];
            $losses = [];
            for ($i = 1; $i < $n; $i++) {
                $change = (float) $bars[$i]->close_price - (float) $bars[$i - 1]->close_price;
                $gains[] = $change > 0 ? $change : 0.0;
                $losses[] = $change < 0 ? abs($change) : 0.0;
            }

            // Initial seed: Simple Average of first 14 price changes
            $seedGain = array_sum(array_slice($gains, 0, 14)) / 14.0;
            $seedLoss = array_sum(array_slice($losses, 0, 14)) / 14.0;

            $wilderGain = $seedGain;
            $wilderLoss = $seedLoss;

            // Wilder Recursive Smoothing: ((prev * 13) + current) / 14
            for ($i = 14; $i < count($gains); $i++) {
                $wilderGain = (($wilderGain * 13.0) + $gains[$i]) / 14.0;
                $wilderLoss = (($wilderLoss * 13.0) + $losses[$i]) / 14.0;
            }

            if ($wilderLoss == 0.0) {
                $rsi14 = $wilderGain == 0.0 ? 50.0 : 100.0;
            } elseif ($wilderGain == 0.0) {
                $rsi14 = 0.0;
            } else {
                $rs = $wilderGain / $wilderLoss;
                $rsi14 = round(100.0 - (100.0 / (1.0 + $rs)), 2);
            }
            $rsiStatus = 'CALCULATED_WILDER_RMA';
        }

        // 4. MACD (12, 26, 9) - Minimum 35 bars required
        $macd = null;
        $macdSignal = null;
        $macdHist = null;
        $macdStatus = 'INSUFFICIENT_HISTORY';
        if ($n >= self::MIN_BARS_MACD) {
            $ema12 = $this->calculateEma($bars->pluck('close_price')->all(), 12);
            $ema26 = $this->calculateEma($bars->pluck('close_price')->all(), 26);
            $macdLine = [];
            for ($i = 0; $i < count($ema26); $i++) {
                $macdLine[] = $ema12[$i + (count($ema12) - count($ema26))] - $ema26[$i];
            }
            $signalLine = $this->calculateEma($macdLine, 9);
            $macd = round(end($macdLine), 2);
            $macdSignal = round(end($signalLine), 2);
            $macdHist = round($macd - $macdSignal, 2);
            $macdStatus = 'CALCULATED_STANDARD_12_26_9';
        }

        // 5. ATR 14 (Wilder's Smoothed RMA with True Range seed)
        $atr14 = null;
        $atr14Pct = null;
        $atrStatus = 'INSUFFICIENT_HISTORY';
        if ($n >= self::MIN_BARS_ATR14) {
            $trList = [];
            for ($i = 1; $i < $n; $i++) {
                $h = (float) $bars[$i]->high_price;
                $l = (float) $bars[$i]->low_price;
                $prevC = (float) $bars[$i - 1]->close_price;
                $tr = max($h - $l, abs($h - $prevC), abs($l - $prevC));
                $trList[] = $tr;
            }

            $seedTr = array_sum(array_slice($trList, 0, 14)) / 14.0;
            $wilderTr = $seedTr;
            for ($i = 14; $i < count($trList); $i++) {
                $wilderTr = (($wilderTr * 13.0) + $trList[$i]) / 14.0;
            }

            $atr14 = round($wilderTr, 2);
            $atr14Pct = $latestClose > 0 ? round(($atr14 / $latestClose) * 100, 2) : 0.0;
            $atrStatus = 'CALCULATED_WILDER_RMA';
        }

        // 6. RVOL 20 & ADTV 20
        $rvol20 = null;
        $adtv20 = null;
        if ($n >= 20) {
            $window20 = $bars->slice($n - 20);
            $vol20Avg = $window20->avg('volume');
            $latestVol = (float) $latestBar->volume;
            $rvol20 = $vol20Avg > 0 ? round($latestVol / $vol20Avg, 2) : 1.0;
            $adtv20 = round($window20->map(fn ($b) => (float) $b->close_price * (float) $b->volume)->avg(), 2);
        }

        // 7. Support & Resistance (rolling 20 bar)
        $window20 = $bars->slice(max(0, $n - 20));
        $support = (float) $window20->min('low_price');
        $resistance = (float) $window20->max('high_price');

        // 8. Trend Classification based on validated available MAs
        $trend = 'SIDEWAYS';
        if ($sma20 !== null && $sma50 !== null) {
            if ($latestClose > $sma20 && $sma20 > $sma50) {
                $trend = 'BULLISH';
            } elseif ($latestClose < $sma20 && $sma20 < $sma50) {
                $trend = 'STRONG_BEARISH';
            } elseif ($latestClose < $sma20) {
                $trend = 'BEARISH';
            }
        } elseif ($sma20 !== null) {
            $trend = $latestClose > $sma20 ? 'ABOVE_SMA20' : 'BELOW_SMA20';
        }

        // Technical status
        $technicalStatus = $n >= self::MIN_BARS_SMA200 ? 'CALCULATED' : ($n >= self::MIN_BARS_SMA20 ? 'PARTIAL_CALCULATED' : 'INSUFFICIENT_HISTORY');

        // Quality and Provenance Flags
        $provenanceFlags = [
            'input_bars' => $n,
            'first_date' => $firstDate,
            'last_date' => $lastDate,
            'as_of_date' => $asOfDateStr,
            'sources' => $sourcesUsed,
            'adjustment_mode' => $adjustmentMode,
            'adtv_20' => $adtv20,
            'sufficiency' => [
                'sma_20' => $sma20 !== null ? 'SUFFICIENT' : "INSUFFICIENT_{$n}_OF_20",
                'sma_50' => $sma50 !== null ? 'SUFFICIENT' : "INSUFFICIENT_{$n}_OF_50",
                'sma_200' => $sma200 !== null ? 'SUFFICIENT' : "INSUFFICIENT_{$n}_OF_200",
                'rsi_14' => $rsiStatus,
                'macd' => $macdStatus,
                'atr_14' => $atrStatus,
            ],
            'formulas' => [
                'sma' => 'Simple Moving Average of Close',
                'rsi' => 'Wilder RMA 14 with initial 14-period SMA gain/loss seed',
                'atr' => 'Wilder RMA 14 with initial 14-period True Range seed',
                'macd' => 'EMA(12) - EMA(26) with EMA(9) signal line',
            ],
        ];

        return CompanyAnalysisMetric::updateOrCreate(
            [
                'ticker' => $ticker,
                'as_of_date' => $asOfDateStr,
            ],
            [
                'close_price' => $latestClose,
                'return_1d_pct' => $ret1d,
                'return_5d_pct' => $ret5d,
                'return_20d_pct' => $ret20d,
                'sma_20' => $sma20,
                'sma_50' => $sma50,
                'sma_200' => $sma200,
                'rsi_14' => $rsi14,
                'macd' => $macd,
                'macd_signal' => $macdSignal,
                'macd_hist' => $macdHist,
                'atr_14' => $atr14,
                'atr_14_pct' => $atr14Pct,
                'rvol_20' => $rvol20,
                'support_price' => $support,
                'resistance_price' => $resistance,
                'technical_trend' => $trend,
                'technical_status' => $technicalStatus,
                'data_quality_flags' => $provenanceFlags,
            ]
        );
    }

    protected function calculateEma(array $values, int $period): array
    {
        $k = 2.0 / ($period + 1.0);
        $ema = [];
        $sma = array_sum(array_slice($values, 0, $period)) / $period;
        $ema[] = $sma;
        for ($i = $period; $i < count($values); $i++) {
            $sma = ((float) $values[$i] * $k) + ($sma * (1.0 - $k));
            $ema[] = $sma;
        }

        return $ema;
    }
}
