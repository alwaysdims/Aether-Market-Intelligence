<?php

require 'dashboard/vendor/autoload.php';
$app = require_once 'dashboard/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\IdxCompanyRegistry;

echo "=== EKSTRAKSI DATASET SWING OHLCV EXP-02 (COST-AWARE HURDLE & NOMINAL TURNOVER) ===\n";

// 1. Dapatkan kalender bursa kanonikal terurut
$calendarQuery = DB::select("
    SELECT bar_date, count(distinct ticker) as active_tickers
    FROM market_observations
    WHERE board_scope = 'RG'
      AND bar_date >= '2024-09-01'
    GROUP BY bar_date
    HAVING count(distinct ticker) >= 100
    ORDER BY bar_date ASC
");

$calendar = [];
$dateToIndex = [];
foreach ($calendarQuery as $idx => $row) {
    $d = $row->bar_date;
    $calendar[$idx] = $d;
    $dateToIndex[$d] = $idx;
}

$totalCalendarDays = count($calendar);
echo "Total canonical trading days: {$totalCalendarDays} (From {$calendar[0]} to {$calendar[$totalCalendarDays - 1]})\n";

$tPlus5Map = [];
$tPlus1Map = [];
for ($i = 0; $i < $totalCalendarDays; $i++) {
    $curD = $calendar[$i];
    if ($i + 1 < $totalCalendarDays) {
        $tPlus1Map[$curD] = $calendar[$i + 1];
    }
    if ($i + 5 < $totalCalendarDays) {
        $tPlus5Map[$curD] = $calendar[$i + 5];
    }
}

// Batas split
$splitRanges = [
    'TRAIN' => ['start' => '2024-12-05', 'end' => '2025-10-31'],
    'VALIDATION' => ['start' => '2025-11-03', 'end' => '2026-04-30'],
    'TEST' => ['start' => '2026-05-02', 'end' => '2026-10-23'],
    'RESERVED_HISTORICAL_WINDOW' => ['start' => '2026-10-26', 'end' => '2026-11-04'],
];

function getTickSize($price) {
    if ($price < 200) return 1.0;
    if ($price < 500) return 2.0;
    if ($price < 2000) return 5.0;
    if ($price < 5000) return 10.0;
    return 25.0;
}

$completeTickers = IdxCompanyRegistry::where('backfill_status', 'COMPLETE')
    ->orderBy('ticker')
    ->pluck('ticker')
    ->toArray();

echo "Emiten registri COMPLETE: " . count($completeTickers) . "\n";

$csvPath = 'reports/swing_ohlcv_dataset_exp02.csv';
$csvFile = fopen($csvPath, 'w');

fputcsv($csvFile, [
    'ticker',
    'bar_date',
    'split',
    'open',
    'high',
    'low',
    'close',
    'volume',
    'unadjusted_close',
    'unadjusted_volume',
    'estimated_nominal_turnover',
    'ret_1d',
    'ret_5d',
    'ret_20d',
    'close_to_sma20',
    'close_to_sma50',
    'atr14_norm',
    'hl_spread_norm',
    'vol_to_sma20',
    'log_estimated_turnover',
    'friction_drag_close',
    'dynamic_hurdle_theta',
    't_plus_1_open',
    't_plus_5_close',
    'ret_5d_forward',
    'label_fixed_exp01',
    'label_dynamic_exp02',
    'target_date',
    'eligibility_filter_b',
]);

$chunkSize = 100;
$chunks = array_chunk($completeTickers, $chunkSize);
$processed = 0;
$totalEligible = 0;

foreach ($chunks as $chunk) {
    // Ambil bar PROVIDER_BACK_ADJUSTED
    $adjustedBars = DB::table('market_observations')
        ->whereIn('ticker', $chunk)
        ->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')
        ->where('board_scope', 'RG')
        ->where('validation_status', 'VALID')
        ->orderBy('ticker')
        ->orderBy('bar_date')
        ->select('ticker', 'bar_date', 'open_price', 'high_price', 'low_price', 'close_price', 'volume')
        ->get()
        ->groupBy('ticker');

    // Ambil bar UNADJUSTED (untuk nominal turnover)
    $unadjustedBars = DB::table('market_observations')
        ->whereIn('ticker', $chunk)
        ->where('adjustment_mode', 'UNADJUSTED')
        ->where('board_scope', 'RG')
        ->where('validation_status', 'VALID')
        ->orderBy('ticker')
        ->orderBy('bar_date')
        ->select('ticker', 'bar_date', 'close_price', 'volume')
        ->get()
        ->groupBy('ticker');

    foreach ($chunk as $ticker) {
        $tickerAdj = $adjustedBars->get($ticker);
        if (!$tickerAdj || $tickerAdj->count() < 60) {
            continue;
        }

        $tickerUnadj = $unadjustedBars->get($ticker);
        $unadjMap = [];
        if ($tickerUnadj) {
            foreach ($tickerUnadj as $ub) {
                $unadjMap[$ub->bar_date] = $ub;
            }
        }

        $barArr = $tickerAdj->values()->all();
        $numBars = count($barArr);

        $dateBarMap = [];
        for ($i = 0; $i < $numBars; $i++) {
            $dateBarMap[$barArr[$i]->bar_date] = $barArr[$i];
        }

        $closes = [];
        $highs = [];
        $lows = [];
        $volumes = [];
        $trs = [];
        $nominalTurnovers = [];

        for ($i = 0; $i < $numBars; $i++) {
            $b = $barArr[$i];
            $d = $b->bar_date;
            $o = (float) $b->open_price;
            $h = (float) $b->high_price;
            $l = (float) $b->low_price;
            $c = (float) $b->close_price;
            $v = (float) $b->volume;

            if ($o <= 0 || $h <= 0 || $l <= 0 || $c <= 0 || $h < $l || $h < $o || $h < $c || $l > $o || $l > $c) {
                continue;
            }

            // Dapatkan nominal unadjusted price dan volume jika tersedia
            $unadjClose = $c;
            $unadjVol = $v;
            if (isset($unadjMap[$d])) {
                $uRow = $unadjMap[$d];
                $unadjClose = (float) $uRow->close_price > 0 ? (float) $uRow->close_price : $c;
                $unadjVol = (float) $uRow->volume >= 0 ? (float) $uRow->volume : $v;
            }
            // Estimasi nilai transaksi nominal murni dari seri unadjusted
            $estNominalTurnover = $unadjClose * $unadjVol;

            $closes[] = $c;
            $highs[] = $h;
            $lows[] = $l;
            $volumes[] = $v;
            $nominalTurnovers[] = $estNominalTurnover;

            if ($i == 0) {
                $tr = $h - $l;
            } else {
                $prevC = $closes[$i - 1];
                $tr = max($h - $l, abs($h - $prevC), abs($l - $prevC));
            }
            $trs[] = $tr;

            if ($i < 50 || $v <= 0) {
                continue;
            }

            if (!isset($dateToIndex[$d]) || !isset($tPlus5Map[$d]) || !isset($tPlus1Map[$d])) {
                continue;
            }

            $targetCalDate = $tPlus5Map[$d];
            $tPlus1CalDate = $tPlus1Map[$d];

            if (!isset($dateBarMap[$targetCalDate]) || !isset($dateBarMap[$tPlus1CalDate])) {
                continue;
            }

            $targetBar = $dateBarMap[$targetCalDate];
            $tPlus1Bar = $dateBarMap[$tPlus1CalDate];

            $targetClose = (float) $targetBar->close_price;
            $tPlus1Open = (float) $tPlus1Bar->open_price;

            if ($targetClose <= 0 || $tPlus1Open <= 0) {
                continue;
            }

            $split = null;
            if ($d >= $splitRanges['TRAIN']['start'] && $d <= $splitRanges['TRAIN']['end']) {
                $split = 'TRAIN';
            } elseif ($d >= $splitRanges['VALIDATION']['start'] && $d <= $splitRanges['VALIDATION']['end']) {
                $split = 'VALIDATION';
            } elseif ($d >= $splitRanges['TEST']['start'] && $d <= $splitRanges['TEST']['end']) {
                $split = 'TEST';
            } else {
                continue;
            }

            if ($targetCalDate > $splitRanges[$split]['end']) {
                continue;
            }

            // Fitur
            $ret1d = ($c - $closes[$i - 1]) / $closes[$i - 1];
            $ret5d = ($c - $closes[$i - 5]) / $closes[$i - 5];
            $ret20d = ($c - $closes[$i - 20]) / $closes[$i - 20];

            $slice20 = array_slice($closes, $i - 19, 20);
            $sma20 = array_sum($slice20) / 20.0;
            $closeToSma20 = $c / $sma20;

            $slice50 = array_slice($closes, $i - 49, 50);
            $sma50 = array_sum($slice50) / 50.0;
            $closeToSma50 = $c / $sma50;

            $sliceTr14 = array_slice($trs, $i - 13, 14);
            $atr14 = array_sum($sliceTr14) / 14.0;
            $atr14Norm = $atr14 / $c;

            $hlSpreadNorm = ($h - $l) / $c;

            $sliceVol20 = array_slice($volumes, $i - 19, 20);
            $smaVol20 = array_sum($sliceVol20) / 20.0;
            $volToSma20 = $smaVol20 > 0 ? ($v / $smaVol20) : 1.0;

            $logEstTurnover = $estNominalTurnover > 0 ? log($estNominalTurnover) : 0.0;

            // Hitung Dynamic Hurdle Theta pada Close_t
            $tickSize = getTickSize($c);
            $frictionDrag = 0.0040 + (2.0 * $tickSize / $c);
            $alphaMargin = 0.010; // 1.0% margin aman
            $dynamicTheta = $frictionDrag + $alphaMargin;

            // Forward Return
            $ret5dForward = ($targetClose - $c) / $c;

            // Label EXP-01 (Fixed ±1.5%)
            $labelExp01 = 'NEUTRAL';
            if ($ret5dForward > 0.015) {
                $labelExp01 = 'UP';
            } elseif ($ret5dForward < -0.015) {
                $labelExp01 = 'DOWN';
            }

            // Label EXP-02 (Dynamic Hurdle)
            $labelExp02 = 'NEUTRAL';
            if ($ret5dForward > $dynamicTheta) {
                $labelExp02 = 'UP';
            } elseif ($ret5dForward < -$dynamicTheta) {
                $labelExp02 = 'DOWN';
            }

            // Filter Kelayakan B (Point-in-Time Close >= 200 dan Turnover 20D SMA >= 500 Juta IDR)
            $sliceTurnover20 = array_slice($nominalTurnovers, $i - 19, 20);
            $meanTurnover20d = array_sum($sliceTurnover20) / 20.0;
            $filterEligibleB = ($c >= 200.0 && $meanTurnover20d >= 500000000.0) ? 1 : 0;

            fputcsv($csvFile, [
                $ticker,
                $d,
                $split,
                $o,
                $h,
                $l,
                $c,
                $v,
                $unadjClose,
                $unadjVol,
                round($estNominalTurnover, 2),
                round($ret1d, 6),
                round($ret5d, 6),
                round($ret20d, 6),
                round($closeToSma20, 6),
                round($closeToSma50, 6),
                round($atr14Norm, 6),
                round($hlSpreadNorm, 6),
                round($volToSma20, 6),
                round($logEstTurnover, 6),
                round($frictionDrag, 6),
                round($dynamicTheta, 6),
                $tPlus1Open,
                $targetClose,
                round($ret5dForward, 6),
                $labelExp01,
                $labelExp02,
                $targetCalDate,
                $filterEligibleB,
            ]);

            $totalEligible++;
        }
    }

    $processed += count($chunk);
    echo "Progress: {$processed} / " . count($completeTickers) . " emiten processed...\n";
}

fclose($csvFile);

$sha256 = hash_file('sha256', $csvPath);
echo "Dataset EXP-02 Tersimpan: {$csvPath}\n";
echo "Total Sampel Eligible: {$totalEligible}\n";
echo "SHA-256 Dataset: {$sha256}\n";
