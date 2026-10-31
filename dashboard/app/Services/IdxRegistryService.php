<?php

namespace App\Services;

use App\Models\IdxCompanyRegistry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class IdxRegistryService
{
    /**
     * Ingest or update the entire BEI listed company directory.
     */
    public function syncRegistry(): array
    {
        $pythonBin = env('PYTHON_BIN', 'C:/laragon/bin/python/python-3.10/python.exe');
        if (! file_exists($pythonBin)) {
            $pythonBin = 'python';
        }

        $scriptPath = base_path('../services/idx_registry_collector.py');
        $cmd = "\"{$pythonBin}\" \"{$scriptPath}\" --json 2>&1";

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        $rawOutput = implode("\n", $output);
        $data = null;

        // Extract JSON from output
        foreach ($output as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
                $decoded = json_decode($trimmed, true);
                if (isset($decoded['companies']) && isset($decoded['total_equities'])) {
                    $data = $decoded;
                    break;
                }
            }
        }

        if (! $data || ($data['success'] ?? false) !== true) {
            Log::error('[IdxRegistryService] Registry collector failed: '.$rawOutput);

            return [
                'success' => false,
                'error' => 'Gagal mengambil data registri BEI dari script kolektor.',
                'raw_output' => $rawOutput,
            ];
        }

        $snapshotDate = $data['snapshot_date'] ?? Carbon::now('Asia/Jakarta')->toDateString();
        $source = $data['source'] ?? 'Kandidat saham dari registri sekunder (Wikipedia: Daftar perusahaan BEI)';
        $rawHash = $data['raw_hash'] ?? null;

        $inserted = 0;
        $updated = 0;

        // 1. Ingest Equities
        foreach ($data['companies'] as $comp) {
            $ticker = strtoupper(trim($comp['ticker']));
            $isValidated = array_key_exists($ticker, BeiUniverseRegistry::VALIDATED_ML_UNIVERSE);

            $record = IdxCompanyRegistry::updateOrCreate(
                ['ticker' => $ticker],
                [
                    'company_name' => $comp['company_name'],
                    'listing_date' => $comp['listing_date'] ? Carbon::parse($comp['listing_date']) : null,
                    'listing_board' => $comp['listing_board'] ?? 'Utama',
                    'sector' => $comp['sector'] ?? 'General',
                    'industry' => $comp['sector'] ?? 'General',
                    'shares_outstanding' => $comp['shares_outstanding'] ?? null,
                    'instrument_type' => 'EQUITY',
                    'listing_status' => $comp['listing_status'] ?? 'ACTIVE',
                    'is_validated_ml' => $isValidated,
                    'source' => $source,
                    'snapshot_date' => $snapshotDate,
                    'raw_hash' => $rawHash,
                ]
            );

            if ($record->wasRecentlyCreated) {
                $inserted++;
            } else {
                $updated++;
            }
        }

        // 2. Ingest Index explicitly separated
        foreach ($data['indices'] as $idx) {
            IdxCompanyRegistry::updateOrCreate(
                ['ticker' => $idx['ticker']],
                [
                    'company_name' => $idx['company_name'],
                    'listing_date' => $idx['listing_date'] ? Carbon::parse($idx['listing_date']) : null,
                    'listing_board' => $idx['listing_board'] ?? 'Benchmark Index',
                    'sector' => $idx['sector'] ?? 'Composite Index',
                    'industry' => 'Index',
                    'shares_outstanding' => null,
                    'instrument_type' => 'INDEX',
                    'listing_status' => 'ACTIVE',
                    'is_validated_ml' => false,
                    'source' => $source,
                    'snapshot_date' => $snapshotDate,
                    'raw_hash' => $rawHash,
                ]
            );
        }

        return [
            'success' => true,
            'snapshot_date' => $snapshotDate,
            'source' => $source,
            'is_official_direct' => $data['is_official_direct'] ?? false,
            'official_status' => $data['official_status'] ?? 'UNKNOWN',
            'raw_hash' => $rawHash,
            'total_equities' => $data['total_equities'],
            'total_indices' => $data['total_indices'],
            'inserted' => $inserted,
            'updated' => $updated,
            'total_in_db' => IdxCompanyRegistry::count(),
            'equities_in_db' => IdxCompanyRegistry::where('instrument_type', 'EQUITY')->count(),
            'indices_in_db' => IdxCompanyRegistry::where('instrument_type', 'INDEX')->count(),
        ];
    }

    /**
     * Get summary metrics of registered BEI companies.
     */
    public function getRegistrySummary(): array
    {
        $totalEquities = IdxCompanyRegistry::where('instrument_type', 'EQUITY')->count();
        $totalIndices = IdxCompanyRegistry::where('instrument_type', 'INDEX')->count();
        $validatedMl = IdxCompanyRegistry::where('is_validated_ml', true)->count();
        $boards = IdxCompanyRegistry::where('instrument_type', 'EQUITY')
            ->selectRaw('listing_board, count(*) as count')
            ->groupBy('listing_board')
            ->pluck('count', 'listing_board')
            ->toArray();

        $sectors = IdxCompanyRegistry::where('instrument_type', 'EQUITY')
            ->selectRaw('sector, count(*) as count')
            ->groupBy('sector')
            ->pluck('count', 'sector')
            ->toArray();

        $latestSnapshot = IdxCompanyRegistry::latest('snapshot_date')->first();

        return [
            'total_equities' => $totalEquities,
            'total_indices' => $totalIndices,
            'validated_ml_stocks' => $validatedMl,
            'boards' => $boards,
            'sectors' => $sectors,
            'snapshot_date' => $latestSnapshot?->snapshot_date?->format('Y-m-d'),
            'source' => $latestSnapshot?->source ?? 'N/A',
            'raw_hash' => $latestSnapshot?->raw_hash ?? 'N/A',
        ];
    }
}
