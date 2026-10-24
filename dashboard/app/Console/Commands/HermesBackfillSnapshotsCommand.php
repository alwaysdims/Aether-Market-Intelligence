<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MarketSnapshot;
use App\Models\DataSource;
use App\Models\PostHorizonEvaluation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class HermesBackfillSnapshotsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hermes:backfill-snapshots 
                            {--date=2026-10-21 : Tanggal bar EOD yang akan di-backfill (format: YYYY-MM-DD)} 
                            {--dry-run : Lakukan simulasi validasi tanpa menulis ke database}
                            {--force-write : Eksekusi penulisan nyata ke database (memerlukan persetujuan)}';

    /**
     * The console command description.
     */
    protected $description = 'Pemulihan dan backfill idempotent data snapshot EOD historis ke tabel market_snapshots';

    protected array $expectedTickers = [
        'BBCA', 'BBRI', 'BMRI', 'BBNI', 'ASII',
        'TLKM', 'MAPI', 'ARTO', 'MEDC', 'SMRA'
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $targetDate = $this->option('date') ?: '2026-10-21';
        $dryRun = (bool) $this->option('dry-run');
        $forceWrite = (bool) $this->option('force-write');

        if (!$dryRun && !$forceWrite) {
            $dryRun = true;
        }

        $modeLabel = $dryRun ? '[MODE SIMULASI / DRY-RUN]' : '[MODE EKSEKUSI NYATA]';
        $this->info("=== JALUR PEMULIHAN DATA HISTORIS EOD {$modeLabel} ===");
        $this->line("Target Tanggal Bar EOD : {$targetDate}");

        // Validate date format
        try {
            $targetCarbon = Carbon::parse($targetDate)->startOfDay();
        } catch (\Throwable $e) {
            $this->error("Format tanggal tidak valid: {$targetDate}");
            return Command::FAILURE;
        }

        $rawDir = base_path('../data/raw_daily');
        if (!is_dir($rawDir)) {
            $this->error("Direktori data operasional tidak ditemukan: {$rawDir}");
            return Command::FAILURE;
        }

        $snapshotsToInsert = [];
        $validationErrors = [];
        $existingCount = 0;
        $conflictCount = 0;

        foreach ($this->expectedTickers as $ticker) {
            $csvFile = $rawDir . DIRECTORY_SEPARATOR . "{$ticker}_1d.csv";
            if (!file_exists($csvFile)) {
                $validationErrors[] = "{$ticker}: File CSV tidak ditemukan di {$csvFile}";
                continue;
            }

            // Read CSV
            $lines = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (empty($lines)) {
                $validationErrors[] = "{$ticker}: CSV kosong";
                continue;
            }

            $header = str_getcsv(array_shift($lines));
            $dateIdx = array_search('Date', $header);
            $openIdx = array_search('Open', $header);
            $highIdx = array_search('High', $header);
            $lowIdx = array_search('Low', $header);
            $closeIdx = array_search('Close', $header);
            $volIdx = array_search('Volume', $header);

            if ($dateIdx === false || $openIdx === false || $highIdx === false || $lowIdx === false || $closeIdx === false || $volIdx === false) {
                $validationErrors[] = "{$ticker}: Header CSV tidak lengkap";
                continue;
            }

            $foundRow = null;
            $allRows = [];
            foreach ($lines as $line) {
                $row = str_getcsv($line);
                $allRows[] = $row;
                if (($row[$dateIdx] ?? '') === $targetDate) {
                    $foundRow = $row;
                }
            }

            if (!$foundRow) {
                $validationErrors[] = "{$ticker}: Bar tanggal {$targetDate} tidak ditemukan dalam CSV";
                continue;
            }

            $open = (float) $foundRow[$openIdx];
            $high = (float) $foundRow[$highIdx];
            $low = (float) $foundRow[$lowIdx];
            $close = (float) $foundRow[$closeIdx];
            $volume = (float) $foundRow[$volIdx];

            // Validate OHLCV logic
            if ($open <= 0 || $high <= 0 || $low <= 0 || $close <= 0) {
                $validationErrors[] = "{$ticker}: Harga <= 0 terdeteksi";
                continue;
            }

            if ($high < $low || $high < $open || $high < $close || $low > $open || $low > $close) {
                $validationErrors[] = "{$ticker}: Logika High/Low/Open/Close tidak konsisten (H:{$high}, L:{$low}, O:{$open}, C:{$close})";
                continue;
            }

            if ($volume < 0) {
                $validationErrors[] = "{$ticker}: Volume negatif ({$volume})";
                continue;
            }

            // Simple ATR14 calculation from preceding bars
            $trList = [];
            $volList = [];
            $closeList = [];
            $foundTargetIdx = null;

            for ($i = 0; $i < count($allRows); $i++) {
                if (($allRows[$i][$dateIdx] ?? '') === $targetDate) {
                    $foundTargetIdx = $i;
                    break;
                }
            }

            if ($foundTargetIdx !== null && $foundTargetIdx >= 14) {
                for ($k = $foundTargetIdx - 13; $k <= $foundTargetIdx; $k++) {
                    $cCurr = (float) $allRows[$k][$closeIdx];
                    $hCurr = (float) $allRows[$k][$highIdx];
                    $lCurr = (float) $allRows[$k][$lowIdx];
                    $cPrev = (float) $allRows[$k - 1][$closeIdx];
                    $tr = max($hCurr - $lCurr, abs($hCurr - $cPrev), abs($lCurr - $cPrev));
                    $trList[] = $tr;
                    $volList[] = (float) $allRows[$k][$volIdx];
                    $closeList[] = $cCurr;
                }
            }

            $atr14 = !empty($trList) ? round(array_sum($trList) / count($trList), 2) : 0.0;
            $avgVol = !empty($volList) ? array_sum($volList) / count($volList) : 1.0;
            $rvol20 = $avgVol > 0 ? round($volume / $avgVol, 2) : 1.0;
            $sma20 = !empty($closeList) ? round(array_sum($closeList) / count($closeList), 2) : $close;

            // Check existing in DB
            $existingSnap = MarketSnapshot::where('ticker', $ticker)
                ->whereDate('bar_date', $targetDate)
                ->first();

            $statusInDb = 'READY_TO_INSERT';
            if ($existingSnap) {
                if (abs((float)$existingSnap->close_price - $close) < 0.01) {
                    $statusInDb = 'IDEMPOTENT_MATCH';
                    $existingCount++;
                } else {
                    $statusInDb = 'CONFLICT';
                    $conflictCount++;
                }
            }

            $snapshotsToInsert[] = [
                'ticker' => $ticker,
                'bar_date' => $targetDate,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => $volume,
                'atr14' => $atr14,
                'rvol20' => $rvol20,
                'sma20' => $sma20,
                'status_in_db' => $statusInDb,
            ];
        }

        if (!empty($validationErrors)) {
            $this->error("Validasi gagal untuk beberapa instrumen:");
            foreach ($validationErrors as $err) {
                $this->line(" - {$err}");
            }
            return Command::FAILURE;
        }

        // Show table of snapshots to insert
        $this->newLine();
        $this->info("1. Rincian Snapshot EOD yang Divalidasi ({$targetDate}):");
        $tableData = [];
        foreach ($snapshotsToInsert as $s) {
            $tableData[] = [
                $s['ticker'],
                $s['bar_date'],
                number_format($s['open'], 2),
                number_format($s['high'], 2),
                number_format($s['low'], 2),
                number_format($s['close'], 2),
                number_format($s['volume']),
                $s['atr14'],
                $s['rvol20'],
                $s['status_in_db']
            ];
        }

        $this->table(
            ['Ticker', 'Bar Date', 'Open', 'High', 'Low', 'Close', 'Volume', 'ATR14', 'RVOL20', 'Status DB'],
            $tableData
        );

        // Check impact on PostHorizonEvaluation
        $pendingEvals = PostHorizonEvaluation::where('status', 'pending')
            ->whereDate('target_eval_date', $targetDate)
            ->get();

        $this->newLine();
        $this->info("2. Dampak terhadap Evaluasi Post-Horizon yang Jatuh Tempo:");
        $this->line("Total pending evaluations untuk target {$targetDate} : {$pendingEvals->count()} record");

        if ($pendingEvals->isNotEmpty()) {
            $evalPreview = [];
            $closePrices = collect($snapshotsToInsert)->pluck('close', 'ticker')->toArray();

            foreach ($pendingEvals as $p) {
                $refPrice = (float) $p->reference_price;
                $targetClose = $closePrices[$p->ticker] ?? null;
                $returnPct = ($refPrice > 0 && $targetClose !== null)
                    ? round((($targetClose - $refPrice) / $refPrice) * 100, 2)
                    : null;

                $actualDir = 'flat';
                if ($returnPct !== null) {
                    if ($returnPct > 0.50) $actualDir = 'positive';
                    elseif ($returnPct < -0.50) $actualDir = 'negative';
                }

                $predDir = strtolower($p->predicted_direction);
                $expectedOutcome = 'neutral';
                if (in_array($predDir, ['unclear', 'mixed', 'neutral'], true)) {
                    $expectedOutcome = 'neutral';
                } elseif ($predDir === $actualDir) {
                    $expectedOutcome = 'correct';
                } else {
                    $expectedOutcome = 'incorrect';
                }

                $evalPreview[] = [
                    $p->id,
                    $p->ticker,
                    $p->reference_price_date->format('Y-m-d'),
                    number_format($refPrice, 2),
                    $targetClose !== null ? number_format($targetClose, 2) : 'N/A',
                    $returnPct !== null ? "{$returnPct}%" : 'N/A',
                    $p->predicted_direction,
                    $actualDir,
                    $expectedOutcome,
                ];
            }

            $this->table(
                ['ID', 'Ticker', 'Ref Date', 'Ref Price', 'Target Close', 'Return', 'Predicted', 'Actual Dir', 'Preview Outcome'],
                $evalPreview
            );
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn("==========================================================================");
            $this->warn(" STATUS DRY-RUN: SUKSES");
            $this->warn(" Tidak ada data yang ditulis ke database PostgreSQL.");
            $this->warn(" Total bar tervalidasi siap tulis  : " . count($snapshotsToInsert));
            $this->warn(" Total record evaluasi siap unblock: " . $pendingEvals->count());
            $this->warn(" Untuk mengeksekusi penulisan nyata, jalankan:");
            $this->warn("   php artisan hermes:backfill-snapshots --date={$targetDate} --force-write");
            $this->warn("==========================================================================");
            return Command::SUCCESS;
        }

        // Execution mode (only when --force-write is passed)
        if ($conflictCount > 0) {
            $this->error("ABORT: Ditemukan {$conflictCount} konflik harga dengan data snapshot yang sudah ada di database. Penulisan dibatalkan demi keamanan.");
            return Command::FAILURE;
        }

        $this->info("Menulis snapshot pasar ke database PostgreSQL dalam transaksi database...");
        $insertedCount = 0;

        DB::transaction(function () use ($snapshotsToInsert, &$insertedCount) {
            $ds = DataSource::where('type', 'market_data')->first();
            foreach ($snapshotsToInsert as $s) {
                if ($s['status_in_db'] === 'IDEMPOTENT_MATCH') {
                    continue; // Skip bar yang nilainya sudah identik
                }

                MarketSnapshot::updateOrCreate(
                    [
                        'ticker' => $s['ticker'],
                        'bar_date' => $s['bar_date'],
                        'data_classification' => 'end_of_day',
                    ],
                    [
                        'data_source_id' => $ds?->id,
                        'analysis_run_id' => null,
                        'source_timestamp' => now('Asia/Jakarta'),
                        'open_price' => $s['open'],
                        'high_price' => $s['high'],
                        'low_price' => $s['low'],
                        'close_price' => $s['close'],
                        'volume' => $s['volume'],
                        'indicators' => [
                            'atr14' => $s['atr14'],
                            'rvol20' => $s['rvol20'],
                            'sma20' => $s['sma20'],
                        ],
                    ]
                );
                $insertedCount++;
            }
        });

        $this->info("Berhasil menulis {$insertedCount} snapshot pasar ke database PostgreSQL secara idempotent dalam satu transaksi.");
        return Command::SUCCESS;
    }
}
