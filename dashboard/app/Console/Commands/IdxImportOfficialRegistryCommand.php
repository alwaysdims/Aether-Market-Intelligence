<?php

namespace App\Console\Commands;

use App\Models\IdxCompanyRegistry;
use App\Models\IdxIngestionJob;
use App\Services\BeiUniverseRegistry;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class IdxImportOfficialRegistryCommand extends Command
{
    protected $signature = 'idx:import-official-registry {file : Path file JSON atau CSV resmi BEI yang diunduh pengguna}';

    protected $description = 'Impor daftar resmi emiten BEI dari berkas ekspor pengguna (mengatasi blokir Cloudflare resmi)';

    public function handle(): int
    {
        $filePath = $this->argument('file');

        if (! File::exists($filePath)) {
            $this->error("Berkas tidak ditemukan: {$filePath}");

            return 1;
        }

        $content = File::get($filePath);
        $rawHash = hash('sha256', $content);
        $today = Carbon::now('Asia/Jakarta')->toDateString();

        $this->info("Membaca berkas resmi: {$filePath} (SHA-256: ".substr($rawHash, 0, 16).'...)');

        $records = [];
        if (str_ends_with(strtolower($filePath), '.json')) {
            $decoded = json_decode($content, true);
            $items = $decoded['data'] ?? ($decoded['companies'] ?? $decoded);
            if (is_array($items)) {
                foreach ($items as $item) {
                    $code = $item['Code'] ?? ($item['ticker'] ?? ($item['KodeEmiten'] ?? null));
                    $name = $item['Name'] ?? ($item['company_name'] ?? ($item['NamaEmiten'] ?? null));
                    if ($code && strlen(trim($code)) === 4) {
                        $records[] = [
                            'ticker' => strtoupper(trim($code)),
                            'company_name' => trim($name ?? $code),
                            'listing_date' => $item['ListingDate'] ?? ($item['listing_date'] ?? null),
                            'listing_board' => $item['Board'] ?? ($item['listing_board'] ?? 'Utama'),
                            'sector' => $item['Sector'] ?? ($item['sector'] ?? 'General'),
                            'shares_outstanding' => isset($item['Shares']) ? (int) $item['Shares'] : null,
                            'instrument_type' => 'EQUITY',
                            'listing_status' => 'ACTIVE',
                        ];
                    }
                }
            }
        } elseif (str_ends_with(strtolower($filePath), '.csv')) {
            $lines = explode("\n", $content);
            $header = null;
            foreach ($lines as $line) {
                $cols = str_getcsv(trim($line));
                if (! $header) {
                    $header = array_map('strtolower', $cols);

                    continue;
                }
                if (count($cols) >= 2) {
                    $code = strtoupper(trim($cols[0]));
                    if (strlen($code) === 4 && ctype_alpha($code)) {
                        $records[] = [
                            'ticker' => $code,
                            'company_name' => trim($cols[1] ?? $code),
                            'listing_date' => $cols[2] ?? null,
                            'listing_board' => $cols[3] ?? 'Utama',
                            'sector' => $cols[4] ?? 'General',
                            'shares_outstanding' => isset($cols[5]) ? (int) preg_replace('/\D/', '', $cols[5]) : null,
                            'instrument_type' => 'EQUITY',
                            'listing_status' => 'ACTIVE',
                        ];
                    }
                }
            }
        }

        if (empty($records)) {
            $this->error('Validasi gagal: Isi berkas tidak memiliki struktur data emiten BEI yang valid. Nama file saja bukan bukti keabsahan sumber.');

            return 1;
        }

        $sourceLabel = 'OFFICIAL_BEI_EXPORT_USER_PROVIDED (Count: '.count($records).', SHA256: '.substr($rawHash, 0, 12).')';
        $inserted = 0;
        $updated = 0;

        foreach ($records as $rec) {
            $ticker = $rec['ticker'];
            $isValidated = array_key_exists($ticker, BeiUniverseRegistry::VALIDATED_ML_UNIVERSE);

            $reg = IdxCompanyRegistry::updateOrCreate(
                ['ticker' => $ticker],
                [
                    'company_name' => $rec['company_name'],
                    'listing_date' => $rec['listing_date'],
                    'listing_board' => $rec['listing_board'],
                    'sector' => $rec['sector'],
                    'shares_outstanding' => $rec['shares_outstanding'],
                    'instrument_type' => 'EQUITY',
                    'listing_status' => 'ACTIVE',
                    'is_validated_ml_universe' => $isValidated,
                    'snapshot_date' => $today,
                    'source' => $sourceLabel,
                    'raw_hash' => $rawHash,
                ]
            );

            if ($reg->wasRecentlyCreated) {
                $inserted++;
            } else {
                $updated++;
            }

            IdxIngestionJob::updateOrCreate(
                [
                    'ticker' => $ticker,
                    'job_type' => 'METRICS',
                ],
                [
                    'status' => 'PENDING',
                    'retry_count' => 0,
                    'as_of_date' => $today,
                ]
            );
        }

        $this->info("Sukses mengimpor berkas resmi: {$inserted} baru, {$updated} diperbarui. Total: ".count($records));

        return 0;
    }
}
