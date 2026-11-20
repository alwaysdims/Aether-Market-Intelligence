<?php

require 'dashboard/vendor/autoload.php';
$app = require_once 'dashboard/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\IdxCompanyRegistry;

echo "=== EKSTRAKSI DATASET OFFLINE SWING OHLCV DENGAN KALENDER BURSA KANONIKAL ===\n";

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
echo "Total canonical trading days (>= 100 tickers): {$totalCalendarDays} (From {$calendar[0]} to {$calendar[$totalCalendarDays - 1]})\n";

// Tentukan mapping T+5 kanonikal dan T+1 kanonikal
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

// 2. Definisi batas split kalender
$splitRanges = [
    'TRAIN' => ['start' => '2024-12-05', 'end' => '2025-10-31'],
    'VALIDATION' => ['start' => '2025-11-03', 'end' => '2026-04-30'],
    'TEST' => ['start' => '2026-05-02', 'end' => '2026-10-23'],
    'RESERVED_HISTORICAL_WINDOW' => ['start' => '2026-10-26', 'end' => '2026-11-04'],
];

// Ambil list emiten COMPLETE resmi registri (964 emiten)
$completeTickers = IdxCompanyRegistry::where('backfill_status', 'COMPLETE')
    ->orderBy('ticker')
    ->pluck('ticker')
    ->toArray();

echo "Emiten registri COMPLETE: " . count($completeTickers) . "\n";

// Buka file CSV output
$csvPath = 'reports/swing_ohlcv_dataset_features.csv';
$csvFile = fopen($csvPath, 'w');

// Header CSV
fputcsv($csvFile, [
    'ticker',
    'bar_date',
    'split',
    'open',
    'high',
    'low',
    'close',
    'volume',
    'ret_1d',
    'ret_5d',
    'ret_20d',
    'close_to_sma20',
    'close_to_sma50',
    'atr14_norm',
    'hl_spread_norm',
    'volume_to_sma20',
    'turnover_idr',
    'log_turnover',
    't_plus_1_open',
    't_plus_5_close',
    'ret_5d_forward',
    'label_class',
    'target_date',
]);

$exclusions = [
    'WARMUP_MA50' => 0,
    'ZERO_VOLUME' => 0,
    'CORRUPT_GEOMETRY' => 0,
    'CALENDAR_T5_UNAVAILABLE' => 0,
    'TICKER_T5_MISSING_GAP' => 0,
    'PURGED_SPLIT_BOUNDARY' => 0,
    'RESERVED_HISTORICAL_WINDOW' => 0,
    'NON_FINITE_FEATURES' => 0,
];

$sampleCounts = [
    'TRAIN' => ['UP' => 0, 'DOWN' => 0, 'NEUTRAL' => 0, 'total' => 0],
    'VALIDATION' => ['UP' => 0, 'DOWN' => 0, 'NEUTRAL' => 0, 'total' => 0],
    'TEST' => ['UP' => 0, 'DOWN' => 0, 'NEUTRAL' => 0, 'total' => 0],
];

$chunkSize = 100;
$chunks = array_chunk($completeTickers, $chunkSize);
$processed = 0;
$totalEligible = 0;

foreach ($chunks as $chunk) {
    $bars = DB::table('market_observations')
        ->whereIn('ticker', $chunk)
        ->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')
        ->where('board_scope', 'RG')
        ->where('validation_status', 'VALID')
        ->orderBy('ticker')
        ->orderBy('bar_date')
        ->select('ticker', 'bar_date', 'open_price', 'high_price', 'low_price', 'close_price', 'volume')
        ->get()
        ->groupBy('ticker');

    foreach ($chunk as $ticker) {
        $tickerBars = $bars->get($ticker);
        if (!$tickerBars || $tickerBars->count() < 60) {
            continue;
        }

        $barArr = $tickerBars->values()->all();
        $numBars = count($barArr);

        // Pre-build index by bar_date for quick lookup
        $dateBarMap = [];
        for ($i = 0; $i < $numBars; $i++) {
            $dateBarMap[$barArr[$i]->bar_date] = $barArr[$i];
        }

        // Rolling arrays for features
        $closes = [];
        $highs = [];
        $lows = [];
        $volumes = [];
        $trs = []; // True Ranges

        for ($i = 0; $i < $numBars; $i++) {
            $b = $barArr[$i];
            $d = $b->bar_date;
            $o = (float) $b->open_price;
            $h = (float) $b->high_price;
            $l = (float) $b->low_price;
            $c = (float) $b->close_price;
            $v = (float) $b->volume;

            // Geometry check
            if ($o <= 0 || $h <= 0 || $l <= 0 || $c <= 0 || $h < $l || $h < $o || $h < $c || $l > $o || $l > $c) {
                $exclusions['CORRUPT_GEOMETRY']++;
                continue;
            }

            $closes[] = $c;
            $highs[] = $h;
            $lows[] = $l;
            $volumes[] = $v;

            // True Range
            if ($i == 0) {
                $tr = $h - $l;
            } else {
                $prevC = $closes[$i - 1];
                $tr = max($h - $l, abs($h - $prevC), abs($l - $prevC));
            }
            $trs[] = $tr;

            // Warmup check: need 50 bars
            if ($i < 50) {
                $exclusions['WARMUP_MA50']++;
                continue;
            }

            // Zero volume / illiquid check
            if ($v <= 0) {
                $exclusions['ZERO_VOLUME']++;
                continue;
            }

            // Check if date is in calendar
            if (!isset($dateToIndex[$d])) {
                continue;
            }

            // Canonical T+5 trading date
            if (!isset($tPlus5Map[$d])) {
                $exclusions['CALENDAR_T5_UNAVAILABLE']++;
                continue;
            }
            $targetCalDate = $tPlus5Map[$d];

            // T+1 trading date for entry simulation
            if (!isset($tPlus1Map[$d])) {
                $exclusions['CALENDAR_T5_UNAVAILABLE']++;
                continue;
            }
            $tPlus1CalDate = $tPlus1Map[$d];

            // Check if ticker has actual bar on targetCalDate and tPlus1CalDate
            if (!isset($dateBarMap[$targetCalDate])) {
                $exclusions['TICKER_T5_MISSING_GAP']++;
                continue;
            }
            if (!isset($dateBarMap[$tPlus1CalDate])) {
                $exclusions['TICKER_T5_MISSING_GAP']++;
                continue;
            }

            $targetBar = $dateBarMap[$targetCalDate];
            $tPlus1Bar = $dateBarMap[$tPlus1CalDate];

            $targetClose = (float) $targetBar->close_price;
            $tPlus1Open = (float) $tPlus1Bar->open_price;

            if ($targetClose <= 0 || $tPlus1Open <= 0) {
                $exclusions['CORRUPT_GEOMETRY']++;
                continue;
            }

            // Determine Split
            $split = null;
            if ($d >= $splitRanges['TRAIN']['start'] && $d <= $splitRanges['TRAIN']['end']) {
                $split = 'TRAIN';
            } elseif ($d >= $splitRanges['VALIDATION']['start'] && $d <= $splitRanges['VALIDATION']['end']) {
                $split = 'VALIDATION';
            } elseif ($d >= $splitRanges['TEST']['start'] && $d <= $splitRanges['TEST']['end']) {
                $split = 'TEST';
            } elseif ($d >= $splitRanges['RESERVED_HISTORICAL_WINDOW']['start']) {
                $exclusions['RESERVED_HISTORICAL_WINDOW']++;
                continue;
            } else {
                continue;
            }

            // True Purging: if targetCalDate > split_end, purge!
            if ($targetCalDate > $splitRanges[$split]['end']) {
                $exclusions['PURGED_SPLIT_BOUNDARY']++;
                continue;
            }

            // Compute Features
            $ret1d = ($c - $closes[$i - 1]) / $closes[$i - 1];
            $ret5d = ($c - $closes[$i - 5]) / $closes[$i - 5];
            $ret20d = ($c - $closes[$i - 20]) / $closes[$i - 20];

            // SMA20 & SMA50
            $slice20 = array_slice($closes, $i - 19, 20);
            $sma20 = array_sum($slice20) / 20.0;
            $closeToSma20 = $c / $sma20;

            $slice50 = array_slice($closes, $i - 49, 50);
            $sma50 = array_sum($slice50) / 50.0;
            $closeToSma50 = $c / $sma50;

            // ATR14
            $sliceTr14 = array_slice($trs, $i - 13, 14);
            $atr14 = array_sum($sliceTr14) / 14.0;
            $atr14Norm = $atr14 / $c;

            // Normalized HL spread
            $hlSpreadNorm = ($h - $l) / $c;

            // Volume to SMA20 Volume
            $sliceVol20 = array_slice($volumes, $i - 19, 20);
            $smaVol20 = array_sum($sliceVol20) / 20.0;
            $volToSma20 = $smaVol20 > 0 ? ($v / $smaVol20) : 1.0;

            // Turnover IDR
            $turnoverIdr = $c * $v;
            $logTurnover = $turnoverIdr > 0 ? log($turnoverIdr) : 0.0;

            // Finite check
            $featureVals = [$ret1d, $ret5d, $ret20d, $closeToSma20, $closeToSma50, $atr14Norm, $hlSpreadNorm, $volToSma20, $logTurnover];
            $isFinite = true;
            foreach ($featureVals as $fv) {
                if (!is_finite($fv) || is_nan($fv)) {
                    $isFinite = false;
                    break;
                }
            }
            if (!$isFinite) {
                $exclusions['NON_FINITE_FEATURES']++;
                continue;
            }

            // Target return & label
            $ret5dForward = ($targetClose - $c) / $c;
            $label = 'NEUTRAL';
            if ($ret5dForward > 0.015) {
                $label = 'UP';
            } elseif ($ret5dForward < -0.015) {
                $label = 'DOWN';
            }

            // Write row
            fputcsv($csvFile, [
                $ticker,
                $d,
                $split,
                $o,
                $h,
                $l,
                $c,
                $v,
                round($ret1d, 6),
                round($ret5d, 6),
                round($ret20d, 6),
                round($closeToSma20, 6),
                round($closeToSma50, 6),
                round($atr14Norm, 6),
                round($hlSpreadNorm, 6),
                round($volToSma20, 6),
                $turnoverIdr,
                round($logTurnover, 6),
                $tPlus1Open,
                $targetClose,
                round($ret5dForward, 6),
                $label,
                $targetCalDate,
            ]);

            $sampleCounts[$split][$label]++;
            $sampleCounts[$split]['total']++;
            $totalEligible++;
        }
    }

    $processed += count($chunk);
    echo "Progress: {$processed} / " . count($completeTickers) . " emiten processed...\n";
}

fclose($csvFile);

echo "\n=== SELESAI EKSTRAKSI DATASET ===\n";
echo "Total Sampel Eligible Tersimpan: {$totalEligible}\n";
echo "Rincian Eksklusi:\n";
print_r($exclusions);
echo "Rincian per Split:\n";
print_r($sampleCounts);

$sha256 = hash_file('sha256', $csvPath);
echo "Dataset CSV Path: {$csvPath}\n";
echo "SHA-256 Dataset: {$sha256}\n";
