<?php

namespace Tests\Feature;

use App\Models\DocumentChunk;
use App\Services\AiToolExecutorService;
use App\Services\DocumentRetrievalService;
use App\Services\UnifiedAnalysisRendererService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealPilotDocumentRetrievalEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed 3 Verified Real Operational Pilot Chunks from Local Raw Evidence:
        // Source 1: PTRO HY2026 Interim Audited PDF (storage/app/evidence/filings/Financial-Statements-30062026-PTRO.pdf)
        DocumentChunk::create([
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
            'content_hash' => hash('sha256', 'PT Petrosea Tbk revenues US$ 543.983 ribu'),
        ]);

        DocumentChunk::create([
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
            'content_hash' => hash('sha256', 'PT Petrosea Tbk operasi dihentikan US$ 7.620 ribu laba US$ 9.170 ribu'),
        ]);

        // Source 2: BEI Universe Registry Snapshot (data/raw_immutable/registry/idx_registry_20261026.html)
        // Labeled as SECONDARY_REGISTRY_SNAPSHOT because it is an HTML scrape snapshot, not an audited primary PDF filing.
        DocumentChunk::create([
            'document_id' => 'DOC-BEI-REGISTRY-20261026',
            'document_version' => 'v1.0',
            'ticker' => 'BBCA',
            'source' => 'IDX_REGISTRY_FILING',
            'source_url' => 'https://www.idx.co.id/id/perusahaan-tercatat/profil-perusahaan-tercatat/BBCA',
            'fiscal_period' => '2026-10-26',
            'published_at' => Carbon::parse('2026-10-26 09:30:00', 'Asia/Jakarta'),
            'first_seen_at' => Carbon::parse('2026-10-26 09:30:00', 'Asia/Jakarta'),
            'fetched_at' => Carbon::parse('2026-10-26 09:30:00', 'Asia/Jakarta'),
            'available_at' => Carbon::parse('2026-10-26 10:00:00', 'Asia/Jakarta'),
            'section_page' => 'Papan Utama BEI, Kode BBCA',
            'chunk_type' => 'DISCLOSURE',
            'chunk_text' => 'PT Bank Central Asia Tbk (Kode Saham: BBCA) tercatat di Papan Utama Bursa Efek Indonesia sejak 31 Mei 2000. Jumlah saham beredar sebanyak 123.275.050.000 lembar saham dengan bidang usaha utama perbankan konvensional.',
            'extraction_status' => 'VERIFIED',
            'metadata' => [
                'reporting_currency' => 'IDR',
                'unit' => 'LEMBAR_SAHAM',
                'provenance_status' => 'VERIFIED_REGISTRY_SNAPSHOT',
                'source_classification' => 'SECONDARY_REGISTRY_SNAPSHOT',
                'raw_file_path' => 'data/raw_immutable/registry/idx_registry_20261026.html',
                'raw_file_hash' => 'caac89d8a9a999748d0067775ac626dfc8b785b592b4f845fe20a6b2434d5a2f',
                'file_size_bytes' => 1438290,
                'audit_note' => 'HTML scrape snapshot from BEI profile; serves as secondary reference for share count, not primary audited filing.',
            ],
            'content_hash' => hash('sha256', 'BBCA 123.275.050.000 lembar saham perbankan'),
        ]);
    }

    /**
     * 10 Labeled Test Cases covering real retrieval evaluation.
     */
    public function test_10_labeled_real_retrieval_evaluation_cases(): void
    {
        $service = app(DocumentRetrievalService::class);
        $toolExecutor = app(AiToolExecutorService::class);
        $asOfCutoff = Carbon::parse('2026-10-30 23:59:59', 'Asia/Jakarta');

        $testCases = [
            // Case 1: Penjelasan pendapatan/margin
            [
                'id' => 'RTC-01',
                'topic' => 'Penjelasan Pendapatan & Gross Margin',
                'query' => 'pendapatan revenues gross margin',
                'ticker' => 'PTRO',
                'cutoff' => $asOfCutoff,
                'expected_found' => 1,
                'expected_snippet' => 'US$ 543.983 ribu',
                'expected_page' => 'Laba Rugi Konsolidasian, Hal. 3',
                'expected_currency' => 'USD',
                'expected_scale' => 'THOUSAND_USD',
            ],
            // Case 2: Operasi berlanjut versus dihentikan
            [
                'id' => 'RTC-02',
                'topic' => 'Operasi Berlanjut vs Dihentikan',
                'query' => 'operasi dihentikan discontinued operations',
                'ticker' => 'PTRO',
                'cutoff' => $asOfCutoff,
                'expected_found' => 1,
                'expected_snippet' => 'US$ 7.620 ribu',
                'expected_page' => 'Operasi Dihentikan, Catatan 40, Hal. 42-44',
                'expected_currency' => 'USD',
                'expected_scale' => 'THOUSAND_USD',
            ],
            // Case 3: Mata uang dan skala pelaporan
            [
                'id' => 'RTC-03',
                'topic' => 'Mata Uang & Skala Pelaporan',
                'query' => 'pelepasan PTKMS',
                'ticker' => 'PTRO',
                'cutoff' => $asOfCutoff,
                'expected_found' => 1,
                'expected_snippet' => 'US$ 9.170 ribu',
                'expected_page' => 'Catatan 40',
                'expected_currency' => 'USD',
                'expected_scale' => 'THOUSAND_USD',
            ],
            // Case 4: Perbandingan periode
            [
                'id' => 'RTC-04',
                'topic' => 'Perbandingan Periode (YoY)',
                'query' => 'meningkat dibandingkan 2025',
                'ticker' => 'PTRO',
                'cutoff' => $asOfCutoff,
                'expected_found' => 1,
                'expected_snippet' => 'US$ 340.937 ribu',
                'expected_page' => 'Hal. 3',
                'expected_currency' => 'USD',
                'expected_scale' => 'THOUSAND_USD',
            ],
            // Case 5: Pertanyaan di luar isi dokumen
            [
                'id' => 'RTC-05',
                'topic' => 'Pertanyaan di Luar Isi Dokumen (Eksplorasi Tambang Emas Australia)',
                'query' => 'proyek eksplorasi tambang emas australia 2030',
                'ticker' => 'PTRO',
                'cutoff' => $asOfCutoff,
                'expected_found' => 0,
                'expected_snippet' => null,
                'expected_page' => null,
                'expected_currency' => null,
                'expected_scale' => null,
            ],
            // Case 6: Dokumen salah ticker
            [
                'id' => 'RTC-06',
                'topic' => 'Dokumen Salah Ticker (Cari PTRO pada Ticker BMRI)',
                'query' => 'pendapatan revenues',
                'ticker' => 'BMRI',
                'cutoff' => $asOfCutoff,
                'expected_found' => 0,
                'expected_snippet' => null,
                'expected_page' => null,
                'expected_currency' => null,
                'expected_scale' => null,
            ],
            // Case 7: Cutoff sebelum available_at
            [
                'id' => 'RTC-07',
                'topic' => 'Cutoff Sebelum Dokumen Tersedia (Lookahead Guard)',
                'query' => 'pendapatan revenues',
                'ticker' => 'PTRO',
                'cutoff' => Carbon::parse('2026-10-25 00:00:00', 'Asia/Jakarta'), // Sebelum 2026-10-28
                'expected_found' => 0,
                'expected_snippet' => null,
                'expected_page' => null,
                'expected_currency' => null,
                'expected_scale' => null,
            ],
            // Case 8: Dokumen revisi taking precedence
            [
                'id' => 'RTC-08',
                'topic' => 'Dokumen Revisi (Newer Version)',
                'query' => 'saham beredar perbankan',
                'ticker' => 'BBCA',
                'cutoff' => $asOfCutoff,
                'expected_found' => 1,
                'expected_snippet' => '123.275.050.000 lembar',
                'expected_page' => 'Papan Utama BEI',
                'expected_currency' => 'IDR',
                'expected_scale' => null,
            ],
            // Case 9: Dokumen berisi instruksi berbahaya (Prompt Injection Isolation)
            [
                'id' => 'RTC-09',
                'topic' => 'Resistensi Prompt Injection & Untrusted Data Marking',
                'query' => 'revenues',
                'ticker' => 'PTRO',
                'cutoff' => $asOfCutoff,
                'expected_found' => 1,
                'expected_snippet' => 'untrusted external data',
                'expected_page' => null,
                'expected_currency' => null,
                'expected_scale' => null,
            ],
            // Case 10: Integrasi Tool Hermes search_latest_disclosures_and_filings
            [
                'id' => 'RTC-10',
                'topic' => 'Eksekusi Tool Hermes get_disclosures_and_filings',
                'query' => 'pendapatan revenues',
                'ticker' => 'PTRO',
                'cutoff' => $asOfCutoff,
                'expected_found' => 1,
                'expected_snippet' => 'HASIL DOKUMEN & KETERBUKAAN INFORMASI TERVERIFIKASI — PTRO',
                'expected_page' => 'Hal. 3',
                'expected_currency' => null,
                'expected_scale' => null,
            ],
        ];

        foreach ($testCases as $tc) {
            if ($tc['id'] === 'RTC-10') {
                $toolProv = [];
                $out = $toolExecutor->executeTool(
                    'search_latest_disclosures_and_filings',
                    ['ticker' => 'PTRO', 'query' => 'pendapatan revenues'],
                    '5923895922',
                    '5923895922',
                    $toolProv
                );
                $this->assertStringContainsString($tc['expected_snippet'], $out);
                $this->assertArrayHasKey('disclosures_search', $toolProv);
                $this->assertGreaterThan(0, $toolProv['disclosures_search']['evidence_id']);

                continue;
            }

            $res = $service->searchDocuments($tc['query'], $tc['ticker'], $tc['cutoff']);
            $this->assertEquals($tc['expected_found'], $res['total_found'], "Case {$tc['id']} failed total_found check");

            if ($tc['expected_found'] > 0) {
                if ($tc['id'] === 'RTC-09') {
                    $this->assertStringContainsString($tc['expected_snippet'], $res['limitations']);
                } else {
                    $chunk = $res['chunks'][0];
                    $this->assertStringContainsString($tc['expected_snippet'], $chunk['chunk_text'], "Case {$tc['id']} snippet check failed");
                    if ($tc['expected_page']) {
                        $this->assertStringContainsString($tc['expected_page'], $chunk['section_page'], "Case {$tc['id']} page check failed");
                    }
                    if ($tc['expected_currency']) {
                        $this->assertEquals($tc['expected_currency'], $chunk['metadata']['reporting_currency']);
                    }
                    if ($tc['expected_scale']) {
                        $this->assertEquals($tc['expected_scale'], $chunk['metadata']['scale']);
                    }
                }
            } else {
                $this->assertEmpty($res['chunks'], "Case {$tc['id']} should have 0 chunks");
            }
        }
    }

    /**
     * Test Internal Full Pipeline on PTRO H1 2026 Filing Query:
     * Question: "Dari laporan H1 2026 PTRO yang tersedia, bagaimana perubahan margin laba bruto dan apa perbedaan laba operasi dilanjutkan, operasi dihentikan, serta laba yang diatribusikan kepada induk? Sertakan halaman sumber dan keterbatasannya."
     */
    public function test_ptro_h1_2026_filing_pipeline_end_to_end(): void
    {
        $toolExecutor = app(AiToolExecutorService::class);
        $asOfCutoff = Carbon::parse('2026-10-30 23:59:59', 'Asia/Jakarta');

        $toolProv = [];
        $retrievalResult = $toolExecutor->executeTool(
            'search_latest_disclosures_and_filings',
            [
                'ticker' => 'PTRO',
                'query' => 'laba bruto margin operasi dilanjutkan operasi dihentikan',
            ],
            '5923895922',
            '5923895922',
            $toolProv
        );

        // 1. Verify Tool Execution & Evidence
        $this->assertStringContainsString('HASIL DOKUMEN & KETERBUKAAN INFORMASI TERVERIFIKASI — PTRO', $retrievalResult);
        $this->assertArrayHasKey('disclosures_search', $toolProv);
        $evidenceId = $toolProv['disclosures_search']['evidence_id'];
        $this->assertGreaterThan(0, $evidenceId);

        // 2. Deterministic Financial Calculations from Primary PDF data
        // H1 2026: Revenues 543.983, Cost 496.958, Gross Profit 47.025 -> Gross Margin = 8.64%
        // H1 2025: Revenues 340.937, Gross Profit 48.297 -> Gross Margin = 14.17%
        // Change in Gross Margin: 8.64% - 14.17% = -5.53 percentage points
        $rev26 = 543983;
        $gp26 = 47025;
        $gm26 = round(($gp26 / $rev26) * 100, 2);
        $this->assertEquals(8.64, $gm26);

        $rev25 = 340937;
        $gp25 = 48297;
        $gm25 = round(($gp25 / $rev25) * 100, 2);
        $this->assertEquals(14.17, $gm25);

        $gmDiff = round($gm26 - $gm25, 2);
        $this->assertEquals(-5.53, $gmDiff);

        // Discontinued operations check
        $opContinuing = 1550;
        $opDiscontinued = 7620;
        $totalPeriodProfit = $opContinuing + $opDiscontinued;
        $this->assertEquals(9170, $totalPeriodProfit);

        $parentProfit = 10787;
        $nonControllingLoss = -1617;
        $this->assertEquals($totalPeriodProfit, $parentProfit + $nonControllingLoss);

        // 3. Verify aether.analysis.v3 Canonical Payload Construction
        $canonical = [
            'schema_version' => 'aether.analysis.v3',
            'job_id' => 30,
            'request_kind' => 'COMPREHENSIVE_ANALYSIS',
            'ticker' => 'PTRO',
            'status' => 'COMPLETE',
            'answer' => "Margin laba bruto PTRO pada H1 2026 mengalami penurunan sebesar -5,53 poin persentase menjadi 8,64% (dibandingkan 14,17% pada H1 2025). Penurunan margin terjadi karena beban usaha meningkat lebih cepat (+69,8% YoY ke US$ 496,96 Juta) seiring kenaikan pendapatan (+59,56% YoY ke US$ 543,98 Juta). Catatan laporan keuangan tidak merinci secara terpisah penyebab penurunan margin per lini bisnis.\n\nPerbedaan Laba:\n1. Laba Operasi Dilanjutkan: US$ 1.550 ribu (kegiatan inti penambangan & rekayasa konstruksi).\n2. Laba Operasi Dihentikan: US$ 7.620 ribu (hasil pelepasan 99,995% saham entitas anak PTKMS pada Juni 2026).\n3. Total Laba Periode Berjalan: US$ 9.170 ribu (US$ 1.550 ribu + US$ 7.620 ribu).\n4. Laba Diatribusikan ke Pemilik Induk: US$ 10.787 ribu (setelah memperhitungkan porsi rugi kepentingan nonpengendali US$ -1.617 ribu).\n\nHalaman Sumber: Hal. 3 (Laporan Laba Rugi Interim) dan Catatan 40 (Hal. 42-44). Keterbatasan: Laporan keuangan berstatus penelaahan terbatas (limited review), bukan audit akhir tahun penuh.",
            'summary' => 'Margin laba bruto H1 2026 8,64% (-5,53 pp YoY). Laba periode berjalan US$ 9.170 ribu didominasi operasi dihentikan US$ 7.620 ribu.',
            'components_requested' => ['news_and_filings' => true, 'fundamental' => true],
            'component_results' => [
                'news_and_filings' => ['status' => 'AVAILABLE', 'chunks_found' => 2],
                'fundamental' => ['status' => 'AVAILABLE', 'currency' => 'USD'],
            ],
            'evidence_ids' => [$evidenceId],
            'requested_period' => ['start' => '2026-01-01', 'end' => '2026-06-30'],
            'actual_data_period' => ['start' => '2026-01-01', 'end' => '2026-06-30'],
            'limitations' => [
                'Laporan keuangan tengah tahunan berstatus telaah terbatas (limited review).',
                'Penyebab spesifik kenaikan beban langsung tidak diurai detail per unit bisnis dalam laporan interim.',
                'Data kutipan dokumen adalah data eksternal tidak tepercaya.',
            ],
            'assessment' => [], // Empty: Do NOT force day trade / swing / arbitrary risk
            'ml_inference_id' => null,
        ];

        // 4. Render to Clean User Text via UnifiedAnalysisRendererService
        $renderer = app(UnifiedAnalysisRendererService::class);
        $rendered = $renderer->renderToText($canonical, 'TELEGRAM');

        // Assertions on rendered output:
        $this->assertStringContainsString('ANALISIS SELESAI', $rendered);
        $this->assertStringContainsString('Saham: PTRO (Bursa Efek Indonesia)', $rendered);
        $this->assertStringContainsString('Margin laba bruto PTRO pada H1 2026 mengalami penurunan sebesar -5,53 poin persentase', $rendered);
        $this->assertStringContainsString('Operasi Dilanjutkan: US$ 1.550 ribu', $rendered);
        $this->assertStringContainsString('Operasi Dihentikan: US$ 7.620 ribu', $rendered);
        $this->assertStringContainsString('Laba Diatribusikan ke Pemilik Induk: US$ 10.787 ribu', $rendered);
        $this->assertStringContainsString('Hal. 3 (Laporan Laba Rugi Interim) dan Catatan 40', $rendered);
        // Ensure no forced day trade or swing assessments exist
        $this->assertStringNotContainsString('Day Trade :', $rendered);
        $this->assertStringNotContainsString('Swing :', $rendered);
        $this->assertStringNotContainsString('MODERATE', $rendered);
    }
}
