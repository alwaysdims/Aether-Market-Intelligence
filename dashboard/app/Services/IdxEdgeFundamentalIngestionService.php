<?php

namespace App\Services;

use App\Models\CompanyFundamental;
use App\Models\DataRevisionArchive;
use App\Models\DataSource;

class IdxEdgeFundamentalIngestionService
{
    public function __construct(
        protected IdxEdgeClient $client,
        protected DataSourceRegistryService $registry
    ) {}

    /**
     * Synchronize annual financial statements for a ticker.
     *
     * @return array<string, mixed>
     */
    public function syncAnnualStatements(string $ticker, int $limit = 2): array
    {
        $ticker = strtoupper(trim($ticker));

        $source = DataSource::where('name', 'IDX Edge Fundamental')->first();
        if ($source) {
            $source->update(['last_attempt_at' => now('UTC')]);
        }

        // 1. Fetch Income Statement
        $incRes = $this->client->financialStatements($ticker, 'INCOME_STATEMENT', 'annually', $limit);
        if (! $incRes['success'] || empty($incRes['data']['items'])) {
            $isFetchFailed = ! ($incRes['success'] ?? false);
            $errorType = $isFetchFailed ? ($incRes['error_type'] ?? 'FETCH_FAILED') : 'PROVIDER_EMPTY';
            $errorMsg = $incRes['message'] ?? ($isFetchFailed ? 'Gagal mengambil income statement.' : 'Penyedia mengembalikan respons kosong (tidak ada item laporan keuangan).');

            if ($source) {
                $source->update([
                    'last_error_at' => now('UTC'),
                    'last_error_message' => $errorMsg,
                    'health' => 'degraded',
                ]);
            }

            return [
                'success' => false,
                'error_type' => $errorType,
                'message' => $errorMsg,
            ];
        }

        // 2. Fetch Balance Sheet
        $balRes = $this->client->financialStatements($ticker, 'BALANCE_SHEET', 'annually', $limit);
        $balItems = $balRes['success'] ? ($balRes['data']['items'] ?? []) : [];
        $balByYear = [];
        foreach ($balItems as $bItem) {
            $yr = (int) ($bItem['year'] ?? 0);
            if ($yr > 0) {
                $balByYear[$yr] = $bItem['data'] ?? [];
            }
        }

        $savedCount = 0;
        $latestRecord = null;

        foreach ($incRes['data']['items'] as $incItem) {
            $year = (int) ($incItem['year'] ?? 0);
            if ($year <= 0) {
                continue;
            }

            $incData = $incItem['data'] ?? [];
            $balData = $balByYear[$year] ?? [];

            // Detect industry mapping: Bank vs Non-Bank
            $isBank = isset($incData['pendapatan_dan_beban_operasional']);
            if ($isBank) {
                $rev = (float) ($incData['pendapatan_dan_beban_operasional']['pendapatan_bunga'] ?? 0);
                $grossProfit = null;
                $opIncome = (float) ($incData['laba_operasional'] ?? 0);
            } else {
                $rev = (float) ($incData['penjualan_dan_pendapatan_usaha'] ?? 0);
                $grossProfit = isset($incData['laba_bruto']) ? (float) $incData['laba_bruto'] : null;
                $opIncome = isset($incData['laba_rugi_sebelum_pajak_penghasilan']) ? (float) $incData['laba_rugi_sebelum_pajak_penghasilan'] : null;
            }

            $ebt = isset($incData['laba_rugi_sebelum_pajak_penghasilan']) ? (float) $incData['laba_rugi_sebelum_pajak_penghasilan'] : null;
            $tax = isset($incData['pendapatan_beban_pajak']) ? (float) $incData['pendapatan_beban_pajak'] : null;
            $netIncome = (float) ($incData['laba_rugi'] ?? 0);

            // Attribution
            $attrParent = isset($incData['laba_rugi_yang_dapat_diatribusikan']['laba_rugi_yang_dapat_diatribusikan_ke_entitas_induk'])
                ? (float) $incData['laba_rugi_yang_dapat_diatribusikan']['laba_rugi_yang_dapat_diatribusikan_ke_entitas_induk']
                : null;
            $attrNonCtrl = isset($incData['laba_rugi_yang_dapat_diatribusikan']['laba_rugi_yang_dapat_diatribusikan_ke_kepentingan_non_pengendali'])
                ? (float) $incData['laba_rugi_yang_dapat_diatribusikan']['laba_rugi_yang_dapat_diatribusikan_ke_kepentingan_non_pengendali']
                : null;

            // Basic EPS
            $eps = isset($incData['laba_rugi_per_saham']['laba_per_saham_dasar_diatribusikan_kepada_pemilik_entitas_induk']['laba_rugi_per_saham_dasar_dari_operasi_yang_dilanjutkan'])
                ? (float) $incData['laba_rugi_per_saham']['laba_per_saham_dasar_diatribusikan_kepada_pemilik_entitas_induk']['laba_rugi_per_saham_dasar_dari_operasi_yang_dilanjutkan']
                : null;

            // Balance Sheet metrics
            $totAssets = isset($balData['aset']['total']) ? (float) $balData['aset']['total'] : (isset($balData['aset']) && is_numeric($balData['aset']) ? (float) $balData['aset'] : null);
            $totEquity = isset($balData['liabilitas_dana_syirkah_temporer_dan_ekuitas']['ekuitas']['total'])
                ? (float) $balData['liabilitas_dana_syirkah_temporer_dan_ekuitas']['ekuitas']['total']
                : (isset($balData['ekuitas']['total']) ? (float) $balData['ekuitas']['total'] : null);
            $totLiab = isset($balData['liabilitas_dana_syirkah_temporer_dan_ekuitas']['liabilitas']['total'])
                ? (float) $balData['liabilitas_dana_syirkah_temporer_dan_ekuitas']['liabilitas']['total']
                : null;

            // Evidence & Quality classification:
            // High rigor principle: All automated API ingested statements are PROVIDER_SUPPLIED.
            // Do NOT promote to DOCUMENT_VERIFIED without an actual verified primary PDF filing artifact.
            $quality = 'PROVIDER_SUPPLIED';

            $dataHash = hash('sha256', "{$ticker}|ANNUAL|{$year}|{$rev}|{$netIncome}|{$totAssets}|{$totEquity}|IDX Edge PRO");

            $knownUsdTickers = ['MEDC', 'ITMG', 'INDY', 'PTRO', 'MBMA', 'AMMN'];
            $isUsdReporter = in_array($ticker, $knownUsdTickers, true)
                || (isset($incItem['data']['currency']) && strtoupper($incItem['data']['currency']) === 'USD');

            $conversionMeta = [];
            if ($isUsdReporter) {
                $conversionMeta = [
                    'original_currency' => 'USD',
                    'reported_currency' => 'IDR',
                    'exchange_rate' => null,
                    'rate_date' => null,
                    'conversion_method' => 'UNKNOWN_PROVIDER_CONVERSION',
                    'usage_limitation' => 'Valuasi dan rasio laba tidak boleh menjadi basis valuasi utama atau dibandingkan langsung dengan emiten pelaporan IDR murni tanpa normalisasi kurs resmi.',
                ];
            }

            $existing = CompanyFundamental::where([
                'ticker' => $ticker,
                'report_type' => 'ANNUAL',
                'fiscal_year' => $year,
                'fiscal_quarter' => null,
            ])->first();

            if ($existing && $existing->raw_data_hash !== $dataHash) {
                $revCount = DataRevisionArchive::where('record_type', 'company_fundamental')
                    ->where('record_id', $existing->id)
                    ->count();

                DataRevisionArchive::create([
                    'record_type' => 'company_fundamental',
                    'record_id' => $existing->id,
                    'ticker' => $ticker,
                    'period_or_date' => (string) $year,
                    'revision_number' => $revCount + 1,
                    'previous_payload' => $existing->raw_payload,
                    'previous_hash' => $existing->raw_data_hash,
                    'new_payload' => [
                        'income_statement' => $incItem,
                        'balance_sheet' => $balData,
                    ],
                    'new_hash' => $dataHash,
                    'changes' => [
                        'old' => [
                            'total_revenue' => $existing->total_revenue,
                            'net_income' => $existing->net_income,
                            'attribution_parent' => $existing->attribution_parent,
                            'total_equity' => $existing->total_equity,
                        ],
                        'new' => [
                            'total_revenue' => $rev,
                            'net_income' => $netIncome,
                            'attribution_parent' => $attrParent,
                            'total_equity' => $totEquity,
                        ],
                    ],
                    'source' => 'IDX Edge PRO',
                    'reason' => 'FINANCIAL_STATEMENT_RESTATEMENT_DETECTED',
                ]);
            }

            $record = CompanyFundamental::updateOrCreate(
                [
                    'ticker' => $ticker,
                    'report_type' => 'ANNUAL',
                    'fiscal_year' => $year,
                    'fiscal_quarter' => null,
                ],
                [
                    'period_end_date' => "{$year}-12-31",
                    'currency' => 'IDR',
                    'unit' => 'IDR',
                    'is_audited' => true,
                    'is_restated' => false,
                    'total_revenue' => $rev,
                    'gross_profit' => $grossProfit,
                    'operating_income' => $opIncome,
                    'ebt' => $ebt,
                    'tax_expense' => $tax,
                    'net_income' => $netIncome,
                    'attribution_parent' => $attrParent,
                    'attribution_non_controlling' => $attrNonCtrl,
                    'eps_basic' => $eps,
                    'total_assets' => $totAssets,
                    'total_liabilities' => $totLiab,
                    'total_equity' => $totEquity,
                    'source' => 'IDX Edge PRO',
                    'data_quality' => $quality,
                    'raw_data_hash' => $dataHash,
                    'raw_payload' => [
                        'income_statement' => $incItem,
                        'balance_sheet' => $balData,
                        'conversion_metadata' => $conversionMeta,
                    ],
                ]
            );

            if ($latestRecord === null) {
                $latestRecord = $record;
            }
            $savedCount++;
        }

        if ($source) {
            $source->update([
                'last_success_at' => now('UTC'),
                'last_error_at' => null,
                'last_error_message' => null,
                'health' => 'healthy',
                'data_quality' => 'VERIFIED_ON_SAMPLE',
            ]);
        }

        return [
            'success' => true,
            'ticker' => $ticker,
            'saved_records' => $savedCount,
            'latest_year' => $latestRecord?->fiscal_year,
            'latest_net_income' => $latestRecord?->net_income,
            'latest_attribution_parent' => $latestRecord?->attribution_parent,
            'data_quality' => $latestRecord?->data_quality,
            'synced_at' => now('UTC')->toIso8601String(),
        ];
    }
}
