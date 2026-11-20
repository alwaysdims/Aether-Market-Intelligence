<?php

require 'dashboard/vendor/autoload.php';
$app = require_once 'dashboard/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\IdxCompanyRegistry;

echo "=== MEMULAI AUDIT PEMBENTUKAN DATASET SWING OHLCV ===\n";

// 1. Ambil seluruh emiten COMPLETE
$completeTickers = IdxCompanyRegistry::where('backfill_status', 'COMPLETE')
    ->orderBy('ticker')
    ->pluck('ticker')
    ->toArray();

$totalCompleteTickers = count($completeTickers);
echo "Jumlah emiten COMPLETE: {$totalCompleteTickers}\n";

// Batas-batas Split Kronologis Berdasarkan Cakupan Aktual Semesta
$splitRanges = [
    'TRAIN' => ['start' => '2024-09-25', 'end' => '2025-10-31'],
    'VALIDATION' => ['start' => '2025-11-03', 'end' => '2026-04-30'],
    'TEST' => ['start' => '2026-05-02', 'end' => '2026-10-23'],
    'UNMATURED_BUFFER' => ['start' => '2026-10-26', 'end' => '2026-11-04'],
];

// Metrik Audit
$metrics = [
    'total_raw_adjusted_bars' => 0,
    'exclusions' => [
        'WARMUP_MA50' => 0,
        'ZERO_VOLUME' => 0,
        'CORRUPT_PRICE' => 0,
        'FORWARD_TARGET_UNAVAILABLE_OR_IMMATURE' => 0,
        'PURGED_AT_SPLIT_BOUNDARY' => 0,
    ],
    'eligible_samples_total' => 0,
    'splits' => [
        'TRAIN' => ['samples' => 0, 'tickers' => [], 'dates' => [], 'classes' => ['UP' => 0, 'DOWN' => 0, 'NEUTRAL' => 0]],
        'VALIDATION' => ['samples' => 0, 'tickers' => [], 'dates' => [], 'classes' => ['UP' => 0, 'DOWN' => 0, 'NEUTRAL' => 0]],
        'TEST' => ['samples' => 0, 'tickers' => [], 'dates' => [], 'classes' => ['UP' => 0, 'DOWN' => 0, 'NEUTRAL' => 0]],
    ],
    'classes_all' => ['UP' => 0, 'DOWN' => 0, 'NEUTRAL' => 0],
];

// Proses per batch emiten (misal per 100 emiten) agar memori aman
$chunkSize = 100;
$chunks = array_chunk($completeTickers, $chunkSize);
$processedCount = 0;

foreach ($chunks as $chunkIndex => $tickerChunk) {
    // Ambil seluruh bar untuk chunk ini
    $bars = DB::table('market_observations')
        ->whereIn('ticker', $tickerChunk)
        ->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')
        ->where('board_scope', 'RG')
        ->where('validation_status', 'VALID')
        ->orderBy('ticker')
        ->orderBy('bar_date')
        ->select('ticker', 'bar_date', 'open_price', 'high_price', 'low_price', 'close_price', 'volume')
        ->get()
        ->groupBy('ticker');

    foreach ($tickerChunk as $ticker) {
        $tickerBars = $bars->get($ticker);
        if (!$tickerBars || $tickerBars->isEmpty()) {
            continue;
        }

        $barArray = $tickerBars->values()->all();
        $totalBars = count($barArray);
        $metrics['total_raw_adjusted_bars'] += $totalBars;

        for ($i = 0; $i < $totalBars; $i++) {
            $current = $barArray[$i];
            $date = $current->bar_date;
            $open = (float) $current->open_price;
            $high = (float) $current->high_price;
            $low = (float) $current->low_price;
            $close = (float) $current->close_price;
            $vol = (float) $current->volume;

            // 1. Cek Corrupt Price
            if ($open <= 0 || $high <= 0 || $low <= 0 || $close <= 0 || $high < $low) {
                $metrics['exclusions']['CORRUPT_PRICE']++;
                continue;
            }

            // 2. Cek Warmup MA50 (50 bar pertama per emiten)
            if ($i < 50) {
                $metrics['exclusions']['WARMUP_MA50']++;
                continue;
            }

            // 3. Cek Ketersediaan Target Bar T+5
            if ($i + 5 >= $totalBars) {
                $metrics['exclusions']['FORWARD_TARGET_UNAVAILABLE_OR_IMMATURE']++;
                continue;
            }

            $targetBar = $barArray[$i + 5];
            $targetClose = (float) $targetBar->close_price;
            $targetDate = $targetBar->bar_date;

            if ($targetClose <= 0) {
                $metrics['exclusions']['CORRUPT_PRICE']++;
                continue;
            }

            // 4. Cek Zero Volume (Likuiditas eksekusi)
            if ($vol <= 0) {
                $metrics['exclusions']['ZERO_VOLUME']++;
                continue;
            }

            // 5. Cek Split & Purging
            $currentSplit = null;
            if ($date >= $splitRanges['TRAIN']['start'] && $date <= $splitRanges['TRAIN']['end']) {
                $currentSplit = 'TRAIN';
            } elseif ($date >= $splitRanges['VALIDATION']['start'] && $date <= $splitRanges['VALIDATION']['end']) {
                $currentSplit = 'VALIDATION';
            } elseif ($date >= $splitRanges['TEST']['start'] && $date <= $splitRanges['TEST']['end']) {
                $currentSplit = 'TEST';
            } else {
                // Di luar split (misal di buffer 2026-10-26 s.d. 2026-11-04)
                $metrics['exclusions']['FORWARD_TARGET_UNAVAILABLE_OR_IMMATURE']++;
                continue;
            }

            // True Purging: jika targetDate melewati split_end_date, sampel di-purge!
            if ($targetDate > $splitRanges[$currentSplit]['end']) {
                $metrics['exclusions']['PURGED_AT_SPLIT_BOUNDARY']++;
                continue;
            }

            // 6. Hitung Return Forward & Kelas Label Fixed ±1.5%
            $ret = ($targetClose - $close) / $close;
            $class = 'NEUTRAL';
            if ($ret > 0.015) {
                $class = 'UP';
            } elseif ($ret < -0.015) {
                $class = 'DOWN';
            }

            $metrics['eligible_samples_total']++;
            $metrics['classes_all'][$class]++;

            $metrics['splits'][$currentSplit]['samples']++;
            $metrics['splits'][$currentSplit]['tickers'][$ticker] = true;
            $metrics['splits'][$currentSplit]['dates'][$date] = true;
            $metrics['splits'][$currentSplit]['classes'][$class]++;
        }
    }

    $processedCount += count($tickerChunk);
    echo "Diproses: {$processedCount} / {$totalCompleteTickers} emiten...\n";
}

echo "\n=== HASIL AUDIT DATASET SWING OHLCV ===\n";
echo "Total Raw Bars: " . $metrics['total_raw_adjusted_bars'] . "\n";
echo "Total Eligible Samples: " . $metrics['eligible_samples_total'] . "\n";
echo "Distribusi Seluruh Sampel:\n";
print_r($metrics['classes_all']);

echo "\nRincian Eksklusi:\n";
print_r($metrics['exclusions']);

echo "\nRincian per Split:\n";
foreach (['TRAIN', 'VALIDATION', 'TEST'] as $sp) {
    $data = $metrics['splits'][$sp];
    $tickerCount = count($data['tickers']);
    $dateCount = count($data['dates']);
    $sampleCount = $data['samples'];
    $classes = $data['classes'];
    $minD = !empty($data['dates']) ? min(array_keys($data['dates'])) : 'none';
    $maxD = !empty($data['dates']) ? max(array_keys($data['dates'])) : 'none';

    echo "--- SPLIT: {$sp} ---\n";
    echo "Rentang Tanggal: {$minD} s.d. {$maxD} ({$dateCount} tanggal bursa)\n";
    echo "Jumlah Ticker Aktif: {$tickerCount}\n";
    echo "Jumlah Sampel Eligible: {$sampleCount}\n";
    echo "Distribusi Kelas: UP={$classes['UP']}, DOWN={$classes['DOWN']}, NEUTRAL={$classes['NEUTRAL']}\n";
    if ($sampleCount > 0) {
        $upPct = round($classes['UP'] / $sampleCount * 100, 2);
        $downPct = round($classes['DOWN'] / $sampleCount * 100, 2);
        $neuPct = round($classes['NEUTRAL'] / $sampleCount * 100, 2);
        echo "Persentase Kelas: UP={$upPct}%, DOWN={$downPct}%, NEUTRAL={$neuPct}%\n";
    }
}

// Simpan Manifest JSON
$manifestData = [
    'manifest_version' => '1.0.0-ohlcv-retrospective',
    'created_at_utc' => now('UTC')->toIso8601String(),
    'dataset_name' => 'Aether_IDX_Equities_Swing_OHLCV_Dataset',
    'source' => 'IDX Edge PRO (Canonical Board RG)',
    'universe_breakdown' => [
        'total_registries' => 978,
        'complete_tickers' => 964,
        'excluded_index' => 1,
        'short_listing' => 1,
        'incomplete_gaps' => 6,
        'failed_schema' => 5,
        'unsupported_share_class' => 1,
    ],
    'sample_counts' => [
        'total_adjusted_raw_bars' => $metrics['total_raw_adjusted_bars'],
        'total_eligible_samples' => $metrics['eligible_samples_total'],
    ],
    'exclusions' => $metrics['exclusions'],
    'class_distribution_overall' => $metrics['classes_all'],
    'split_specifications' => [
        'TRAIN' => [
            'date_range' => ['start' => '2024-09-25', 'end' => '2025-10-31'],
            'tickers_count' => count($metrics['splits']['TRAIN']['tickers']),
            'trading_dates_count' => count($metrics['splits']['TRAIN']['dates']),
            'samples_count' => $metrics['splits']['TRAIN']['samples'],
            'classes' => $metrics['splits']['TRAIN']['classes'],
        ],
        'VALIDATION' => [
            'date_range' => ['start' => '2025-11-03', 'end' => '2026-04-30'],
            'tickers_count' => count($metrics['splits']['VALIDATION']['tickers']),
            'trading_dates_count' => count($metrics['splits']['VALIDATION']['dates']),
            'samples_count' => $metrics['splits']['VALIDATION']['samples'],
            'classes' => $metrics['splits']['VALIDATION']['classes'],
        ],
        'TEST' => [
            'date_range' => ['start' => '2026-05-02', 'end' => '2026-10-23'],
            'tickers_count' => count($metrics['splits']['TEST']['tickers']),
            'trading_dates_count' => count($metrics['splits']['TEST']['dates']),
            'samples_count' => $metrics['splits']['TEST']['samples'],
            'classes' => $metrics['splits']['TEST']['classes'],
        ],
        'FORWARD_UNMATURED_BUFFER' => [
            'date_range' => ['start' => '2026-10-26', 'end' => '2026-11-04'],
            'purpose' => 'Holding buffer for immature T+5 labels; strictly excluded from model evaluation.',
        ],
    ],
    'label_specification' => [
        'target_type' => 'FIXED_PERCENTAGE_DIRECTIONAL',
        'horizon' => '5_TRADING_DAYS_CLOSE_TO_CLOSE',
        'threshold' => '±1.50%',
        'classes' => ['UP', 'DOWN', 'NEUTRAL'],
        'formula' => 'ret = (Close_{t+5} - Close_t) / Close_t; UP if ret > 0.015, DOWN if ret < -0.015, else NEUTRAL',
    ],
    'friction_costs_and_slippage' => [
        'broker_fee_round_trip' => '0.40% (0.15% buy, 0.25% sell)',
        'slippage_per_side' => '1 tick entry, 1 tick exit (Total 2 ticks adverse slippage)',
        'dynamic_cost_formula' => 'friction_cost(P) = 0.0040 + (2 * tick_size(P)) / P',
    ],
];

$manifestJson = json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$manifestHash = hash('sha256', $manifestJson);
$manifestData['manifest_sha256_hash'] = $manifestHash;

$manifestPath = 'reports/SWING_OHLCV_DATASET_MANIFEST.json';
file_put_contents($manifestPath, json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\nManifest tersimpan di: {$manifestPath}\n";
echo "SHA-256 Hash Manifest: {$manifestHash}\n";
