<?php

namespace App\Console\Commands;

use App\Models\DocumentChunk;
use Carbon\Carbon;
use Illuminate\Console\Command;

class IngestPilotDocumentChunksCommand extends Command
{
    protected $signature = 'hermes:ingest-pilot-documents {--force-test-environment : Explicit confirmation to seed synthetic fixtures in isolated test environment}';

    protected $description = 'Ingest pilot test fixture chunks into document_chunks table (TEST ONLY)';

    public function handle(): int
    {
        // Enforce strict test-only environment isolation:
        // Fixture seeder MUST ONLY write to automated test database (sqlite/:memory: or dedicated test DB).
        // It is strictly forbidden from executing on local or production operational database,
        // even if command line options are passed.
        if (! app()->environment('testing')) {
            $this->error('ENVIRONMENT_ISOLATION_ERROR: IngestPilotDocumentChunksCommand is strictly restricted to automated testing environments (APP_ENV=testing). Execution on operational host database is prohibited.');

            return 1;
        }

        $this->info('Starting Ingestion of Pilot Document Chunks (TEST ISOLATION)...');

        $chunks = [
            // 1. PT Bank Central Asia Tbk (BBCA) - Laporan Keuangan Tahunan FY 2025
            [
                'document_id' => 'DOC-BBCA-FY2025-AUDITED-001',
                'document_version' => 'v1.0',
                'ticker' => 'BBCA',
                'source' => 'IDX_FILING',
                'source_url' => 'https://www.idx.co.id/id/perusahaan-tercatat/laporan-keuangan-dan-tahunan/BBCA/2025/Tahunan',
                'fiscal_period' => 'FY2025',
                'published_at' => Carbon::parse('2026-02-15 08:30:00'),
                'first_seen_at' => Carbon::parse('2026-10-31 17:19:15'),
                'fetched_at' => Carbon::parse('2026-10-31 17:19:15'),
                'available_at' => Carbon::parse('2026-10-31 17:19:15'),
                'section_page' => 'CALK, Hal. 12',
                'chunk_type' => 'TABLE_FINANCIAL',
                'chunk_text' => 'PT Bank Central Asia Tbk dan Entitas Anak mencatat Laba Bersih Tahun Berjalan Konsolidasian yang dapat diatribusikan kepada pemilik entitas induk sebesar Rp 57.537.287.000.000 (Rp 57,54 Triliun) untuk tahun buku yang berakhir pada 31 Desember 2025. Total Aset tercatat sebesar Rp 1.586.828.536.000.000, Total Liabilitas sebesar Rp 1.285.492.310.000.000, dan Total Ekuitas sebesar Rp 301.336.226.000.000.',
                'extraction_status' => 'UNVERIFIED_PROVENANCE',
                'metadata' => [
                    'reporting_currency' => 'IDR',
                    'scale' => 'FULL_AMOUNT',
                    'unit' => 'Rupiah Penuh',
                    'audit_status' => 'AUDITED',
                    'auditor' => 'KAP Tanudiredja, Wibisana, Rintis & Rekan (PwC)',
                    'provenance_status' => 'UNVERIFIED_PROVENANCE',
                    'audit_note' => 'Hardcoded text in seeder; raw source file/PDF not in local evidence directory.',
                ],
            ],
            // 2. PT Bakrie & Brothers Tbk (BNBR) - Laporan Keuangan Konsolidasian FY 2025
            [
                'document_id' => 'DOC-BNBR-FY2025-AUDITED-001',
                'document_version' => 'v1.0',
                'ticker' => 'BNBR',
                'source' => 'IDX_FILING',
                'source_url' => 'https://bakrie-brothers.com/investor-relations/financial-reports/FY2025',
                'fiscal_period' => 'FY2025',
                'published_at' => Carbon::parse('2026-03-20 17:15:00'),
                'first_seen_at' => Carbon::parse('2026-10-31 17:19:15'),
                'fetched_at' => Carbon::parse('2026-10-31 17:19:15'),
                'available_at' => Carbon::parse('2026-10-31 17:19:15'),
                'section_page' => 'Laba Rugi, Hal. 4',
                'chunk_type' => 'TABLE_FINANCIAL',
                'chunk_text' => 'PT Bakrie & Brothers Tbk membukukan Laba Bersih yang dapat diatribusikan kepada pemilik entitas induk sebesar Rp 493.850.000.000 (Rp 493,85 Miliar) pada tahun buku 2025, dibandingkan laba bersih tahun 2024 sebesar Rp 236.420.000.000. Total Liabilitas perseroan tercatat Rp 3.420.150.000.000 dan Total Ekuitas Pemilik Induk Rp 2.890.300.000.000, menghasilkan rasio Total Liabilities to Total Equity sebesar 1,18x.',
                'extraction_status' => 'UNVERIFIED_PROVENANCE',
                'metadata' => [
                    'reporting_currency' => 'IDR',
                    'scale' => 'FULL_AMOUNT',
                    'unit' => 'Rupiah Penuh',
                    'audit_status' => 'AUDITED',
                    'provenance_status' => 'UNVERIFIED_PROVENANCE',
                    'audit_note' => 'Hardcoded text in seeder; raw source file/PDF not in local evidence directory.',
                ],
            ],
            // 3. PT Buana Lintas Lautan Tbk (BULL) - Keterbukaan Informasi & Laporan Operasional Armada
            [
                'document_id' => 'DOC-BULL-DISCLOSURE-202610-001',
                'document_version' => 'v1.0',
                'ticker' => 'BULL',
                'source' => 'IDX_FILING',
                'source_url' => 'https://www.idx.co.id/id/perusahaan-tercatat/keterbukaan-informasi/BULL/20261028',
                'fiscal_period' => 'Q3-2026',
                'published_at' => Carbon::parse('2026-10-28 11:00:00'),
                'first_seen_at' => Carbon::parse('2026-10-31 17:19:15'),
                'fetched_at' => Carbon::parse('2026-10-31 17:19:15'),
                'available_at' => Carbon::parse('2026-10-31 17:19:15'),
                'section_page' => 'Ket. Armada, Hal. 2',
                'chunk_type' => 'DISCLOSURE',
                'chunk_text' => 'PT Buana Lintas Lautan Tbk (BULL) mengumumkan pembaruan utilisasi armada tanker minyak dan gas. Tingkat utilisasi armada mencapai 94,8% pada periode sembilan bulan yang berakhir 30 September 2026. Perseroan mengoperasikan 24 kapal tanker aktif dengan kontrak sewa berjangka (time charter) jangka panjang yang menopang stabilitas arus kas operasional di tengah volatilitas tarif angkutan spot internasional.',
                'extraction_status' => 'UNVERIFIED_PROVENANCE',
                'metadata' => [
                    'reporting_currency' => 'USD',
                    'scale' => 'EXACT',
                    'unit' => 'US Dollar & Operational Metrics',
                    'disclosure_topic' => 'Fleet Utilization & Operational Update',
                    'provenance_status' => 'UNVERIFIED_PROVENANCE',
                    'audit_note' => 'Hardcoded text in seeder; raw source file/PDF not in local evidence directory.',
                ],
            ],
            // 4. PT Petrosea Tbk (PTRO) - Laporan Keuangan Tengah Tahunan (USD Reporting) - ARSIP INVALID LAMA
            [
                'document_id' => 'DOC-PTRO-HY2026-001',
                'document_version' => 'v1.0',
                'ticker' => 'PTRO',
                'source' => 'IDX_FILING',
                'source_url' => 'https://www.idx.co.id/id/perusahaan-tercatat/laporan-keuangan-dan-tahunan/PTRO/2026/II',
                'fiscal_period' => 'HY2026',
                'published_at' => Carbon::parse('2026-08-30 16:45:00'),
                'first_seen_at' => Carbon::parse('2026-10-30 14:20:00'),
                'fetched_at' => Carbon::parse('2026-10-30 14:20:00'),
                'available_at' => Carbon::parse('2026-10-30 14:20:00'),
                'section_page' => 'Posisi Keuangan, Hal. 2',
                'chunk_type' => 'TABLE_FINANCIAL',
                'chunk_text' => 'PT Petrosea Tbk menyajikan laporan keuangan dalam mata uang Dolar Amerika Serikat (USD). Total Pendapatan per 30 Juni 2026 mencapai US$ 318.450.000 dengan Laba Bersih periode berjalan sebesar US$ 14.820.000. Total Aset mencapai US$ 842.100.000 dan Liabilitas sebesar US$ 560.300.000. Angka disajikan dalam satuan US Dollar penuh tanpa pembulatan jutaan otomatis.',
                'extraction_status' => 'INVALIDATED_HISTORICAL_RECORD',
                'metadata' => [
                    'reporting_currency' => 'USD',
                    'scale' => 'FULL_AMOUNT',
                    'unit' => 'US Dollar',
                    'audit_status' => 'INVALIDATED',
                    'provenance_status' => 'INVALIDATED_REPLACED_BY_PRIMARY_PDF',
                    'audit_note' => 'Arsip lama tidak valid. Digantikan oleh chunk terverifikasi dari PDF primer fisik storage/app/evidence/filings/Financial-Statements-30062026-PTRO.pdf.',
                ],
            ],
            // 4b. PT Petrosea Tbk (PTRO) - Verifikasi Nyata PDF Primer Hal. 3
            [
                'document_id' => 'DOC-PTRO-HY2026-ACTUAL-001',
                'document_version' => 'v1.0',
                'ticker' => 'PTRO',
                'source' => 'INVESTOR_RELATIONS_FILING',
                'source_url' => 'https://petrosea.com/wp-content/uploads/2026/09/Financial-Statements-30062026-PTRO.pdf',
                'fiscal_period' => 'HY2026',
                'published_at' => Carbon::parse('2026-09-17 16:47:18', 'UTC'),
                'first_seen_at' => Carbon::parse('2026-10-28 15:44:00', 'Asia/Jakarta'),
                'fetched_at' => Carbon::parse('2026-10-28 15:44:00', 'Asia/Jakarta'),
                'available_at' => Carbon::parse('2026-10-28 16:00:00', 'Asia/Jakarta'),
                'section_page' => 'Laba Rugi Konsolidasian, Hal. 3',
                'chunk_type' => 'TABLE_FINANCIAL',
                'chunk_text' => 'PT Petrosea Tbk dan Entitas Anaknya membukukan total pendapatan (revenues) sebesar US$ 543.983 ribu untuk periode enam bulan yang berakhir pada tanggal 30 Juni 2026, meningkat 59,56% dibandingkan US$ 340.937 ribu pada periode yang sama tahun 2025. Beban usaha tercatat sebesar US$ 496.958 ribu, menghasilkan laba bruto US$ 47.025 ribu (gross margin 8,64%).',
                'extraction_status' => 'VERIFIED',
                'metadata' => [
                    'reporting_currency' => 'USD',
                    'scale' => 'THOUSAND_USD',
                    'unit' => 'US$ Ribu',
                    'audit_status' => 'LIMITED_REVIEW',
                    'provenance_status' => 'VERIFIED_OPERATIONAL_RAW_FILE',
                    'raw_file_path' => 'storage/app/evidence/filings/Financial-Statements-30062026-PTRO.pdf',
                    'raw_file_hash' => '4e78582a07f404f8f7dfd6deae1c8764f0bb36665c0ce6f479ba31a47a926eb3',
                    'file_size_bytes' => 5206530,
                ],
            ],
            // 4c. PT Petrosea Tbk (PTRO) - Verifikasi Nyata PDF Primer Catatan 40 Hal. 42-44
            [
                'document_id' => 'DOC-PTRO-HY2026-ACTUAL-002',
                'document_version' => 'v1.0',
                'ticker' => 'PTRO',
                'source' => 'INVESTOR_RELATIONS_FILING',
                'source_url' => 'https://petrosea.com/wp-content/uploads/2026/09/Financial-Statements-30062026-PTRO.pdf',
                'fiscal_period' => 'HY2026',
                'published_at' => Carbon::parse('2026-09-17 16:47:18', 'UTC'),
                'first_seen_at' => Carbon::parse('2026-10-28 15:44:00', 'Asia/Jakarta'),
                'fetched_at' => Carbon::parse('2026-10-28 15:44:00', 'Asia/Jakarta'),
                'available_at' => Carbon::parse('2026-10-28 16:00:00', 'Asia/Jakarta'),
                'section_page' => 'Operasi Dihentikan, Catatan 40, Hal. 42-44',
                'chunk_type' => 'DISCLOSURE',
                'chunk_text' => 'Laba periode berjalan dari operasi dilanjutkan tercatat US$ 1.550 ribu, sedangkan laba periode berjalan dari operasi dihentikan (discontinued operations) merefleksikan hasil pelepasan 99,995% saham PTKMS sebesar US$ 7.620 ribu. Total laba periode berjalan tercatat US$ 9.170 ribu. Laba yang dapat diatribusikan kepada pemilik entitas induk adalah US$ 10.787 ribu, setelah memperhitungkan rugi kepentingan nonpengendali sebesar US$ -1.617 ribu.',
                'extraction_status' => 'VERIFIED',
                'metadata' => [
                    'reporting_currency' => 'USD',
                    'scale' => 'THOUSAND_USD',
                    'unit' => 'US$ Ribu',
                    'audit_status' => 'LIMITED_REVIEW',
                    'provenance_status' => 'VERIFIED_OPERATIONAL_RAW_FILE',
                    'raw_file_path' => 'storage/app/evidence/filings/Financial-Statements-30062026-PTRO.pdf',
                    'raw_file_hash' => '4e78582a07f404f8f7dfd6deae1c8764f0bb36665c0ce6f479ba31a47a926eb3',
                    'file_size_bytes' => 5206530,
                ],
            ],
            // 5. Dokumen Bermasalah / Extraction Failure Sample untuk membuktikan transparansi audit
            [
                'document_id' => 'DOC-CORRUPT-SAMPLE-001',
                'document_version' => 'v1.0',
                'ticker' => 'ZZZZ',
                'source' => 'IDX_FILING',
                'source_url' => 'https://www.idx.co.id/id/perusahaan-tercatat/invalid-link',
                'fiscal_period' => 'FY2025',
                'published_at' => Carbon::parse('2026-01-10 10:00:00'),
                'first_seen_at' => Carbon::parse('2026-01-10 10:05:00'),
                'fetched_at' => Carbon::parse('2026-10-30 14:25:00'),
                'available_at' => Carbon::parse('2026-01-10 10:10:00'),
                'section_page' => 'Lampiran Rusak, Hal. 99',
                'chunk_type' => 'TABLE_FINANCIAL',
                'chunk_text' => '[EXTRACTION_FAILED: Optical Character Recognition failed due to corrupted PDF binary stream and invalid PDF trailer table.]',
                'extraction_status' => 'FAILED_EXTRACTION',
                'metadata' => [
                    'error_code' => 'ERR_PDF_CORRUPT',
                    'attempted_parser' => 'SpatiePdfToText',
                    'is_test_fixture' => true,
                    'provenance_status' => 'SYNTHETIC_TEST_FIXTURE',
                ],
            ],
        ];

        $insertedCount = 0;
        foreach ($chunks as $chunk) {
            $contentHash = hash('sha256', $chunk['chunk_text']);
            DocumentChunk::updateOrCreate(
                ['document_id' => $chunk['document_id']],
                array_merge($chunk, ['content_hash' => $contentHash])
            );
            $insertedCount++;
        }

        $this->info("Successfully ingested {$insertedCount} pilot document chunks into document_chunks table.");

        return 0;
    }
}
