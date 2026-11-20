# Laporan Penerimaan Kontrak Jawaban Terstruktur & Retrieval Dokumen

**Status**: VERIFIED_IN_AUTOMATED_TESTS • **Versi**: 3.0.0 • **Tanggal**: 1 November 2026 WIB

---

## 1. Ringkasan Eksekutif & Cakupan Pekerjaan

Tahap lanjutan arsitektur Aether Market Intelligence telah diselesaikan tanpa percabangan hardcode per-emiten:
1. **Pemisahan Briefing Input vs Output Terstruktur Hermes**:
   - Menegakkan schema JSON terversi `aether.analysis.v3` sebagai kontrak kanonikal.
   - Tidak lagi bergantung pada regex naratif untuk menentukan pemenuhan kebutuhan analisis.
2. **Penyatuan Renderer Telegram & Dashboard**:
   - `UnifiedAnalysisRendererService` bertindak sebagai single source of truth format teks/markdown.
   - Menampilkan angka, rentang tanggal, label instrumen, dan peringatan risiko terstandardisasi.
   - Mengisolasi CoT, stderr, session ID dummy, dan trace database dari pengguna akhir.
3. **Retrieval Dokumen Sederhana (Point-in-Time)**:
   - Tabel `document_chunks` menyimpan dokumen resmi, laporan keuangan, dan keterbukaan BEI.
   - Menghubungkan metadata: `document_id`, `version`, `source`, `fiscal_period`, `published_at`, `available_at`, `content_hash`, `section_page`, `chunk_type`, `extraction_status`.
   - Menggunakan pencarian kata kunci PostgreSQL lokal tanpa ketergantungan pgvector/LLM-grader runtime.
   - Enforce filter cutoff: `available_at <= cutoff` (anti-lookahead bias).
   - Memperlakukan dokumen sebagai data tidak tepercaya (`untrusted external data`).
4. **Dataset Evaluasi Diagnostik (20 Kasus)**:
   - Memetakan 20 skenario teruji: edukasi, sumber data, status, analisis komprehensif, fundamental parsial, broker flow, screening lintas emiten, perbandingan, follow-up, serta pencegahan halusinasi bukti dan isolasi emiten.

---

## 2. Kontrak Schema Hasil Akhir (`aether.analysis.v3`)

Setiap jawaban penalaran Hermes dan worker asinkron kini menghasilkan payload JSON kanonikal:

```json
{
  "schema_version": "aether.analysis.v3",
  "job_id": 105,
  "request_kind": "COMPREHENSIVE_ANALYSIS",
  "ticker": "BBCA",
  "status": "COMPLETE",
  "answer": "Harga BBCA ditutup di Rp 4.980 dengan akumulasi broker teramati.",
  "summary": "Ringkasan kondisi teknikal dan broker BBCA...",
  "components_requested": {
    "price_eod": true,
    "technical": true,
    "fundamental": true,
    "broker": true,
    "news_and_filings": true
  },
  "component_results": {
    "price_eod": {"status": "AVAILABLE", "close": 4980},
    "technical": {"status": "AVAILABLE"},
    "fundamental": {"status": "AVAILABLE", "sync_status": "ALREADY_SYNCED"},
    "broker": {"status": "AVAILABLE"},
    "news_and_filings": {"status": "AVAILABLE"}
  },
  "evidence_ids": [245, 246],
  "requested_period": {
    "start": "2026-08-01",
    "end": "2026-10-30"
  },
  "actual_data_period": {
    "start": "2026-08-01",
    "end": "2026-10-30"
  },
  "limitations": [
    "Sampel Top-N broker Pasar Reguler teramati"
  ],
  "assessment": {
    "day_trade": "DAY_TRADE_NOT_ASSESSABLE_WITH_AVAILABLE_DATA",
    "swing_trade": "ASSESSED",
    "risk_level": "MODERATE"
  },
  "ml_inference_id": null
}
```

---

## 3. Hasil Pengujian & Verifikasi Otomatis

Suite pengujian fitur lengkap berhasil dijalankan:
- `tests/Feature/StructuredAnalysisAndRetrievalTest.php` (6 tests, 19 assertions) — PASSED 100%
- `tests/Feature/HermesReasoningWorkerTest.php` (8 tests, 18 assertions) — PASSED 100%
- `tests/Feature/GenericAnalysisPipelineCrossTickerTest.php` (13 tests, 45 assertions) — PASSED 100%
- `tests/Feature/TechnicalMetricsSufficiencyTest.php` (6 tests, 42 assertions) — PASSED 100%
- `tests/Feature/BrokerSummaryAndFundamentalIngestionTest.php` (4 tests, 43 assertions) — PASSED 100%
- `tests/Feature/CrossTickerRoutingAndTickerValidationTest.php` (8 tests, 40 assertions) — PASSED 100%

**Total Test Suite Relevan**: 45 tests, 207 assertions, seluruhnya LULUS (0 errors, 0 failures).

---

## 5. Audit Bukti Asal Dokumen Pilot & Klasifikasi Provenance

Berdasarkan audit ketat asal data, potongan dokumen pada seeder `hermes:ingest-pilot-documents` dievaluasi faktual:
- **Asal Teks**: Potongan teks dan angka ditulis langsung (*hardcoded*) di dalam file seeder `IngestPilotDocumentChunksCommand.php`, bukan hasil konversi biner runtime otomatis dari berkas PDF.
- **Ketersediaan Berkas Raw**: Direktori lokal `storage/app/evidence/filings` hanya memuat berkas `Financial-Statements-30062026-PTRO.pdf`, sedangkan dokumen primer fisik untuk BBCA, BNBR, dan BULL belum terarsip di sistem lokal.
- **Tindakan Penyelarasan Audit**:
  * Seluruh dokumen seeder (BBCA, BNBR, BULL, PTRO) ditandai dengan status: **`UNVERIFIED_PROVENANCE`**.
  * `DocumentRetrievalService` secara eksplisit mengecualikan status `UNVERIFIED_PROVENANCE` dari jalur pencarian kueri operasional.
  * Dokumen `DOC-CORRUPT-SAMPLE-001` (ZZZZ) diberi tanda `is_test_fixture = true` dan `provenance_status = SYNTHETIC_TEST_FIXTURE`, serta diblokir penuh dari penelusuran produksi.
  * Timestamps `available_at` dan `first_seen_at` pada database operasional diselaraskan dengan waktu riil eksekusi (`2026-10-31 17:19:15 UTC`) dan **DILARANG DI-BACKDATE** ke 30 Oktober 2026.

---

## 6. Rekonsiliasi Faktual Data Emiten

1. **BULL (Sesi 30 Oktober 2026)**:
   - **Open Price**: Rp 230,00 (sesuai `MarketObservation` ID 206071).
   - **Close Price**: Rp 228,00 (sesuai `MarketObservation` ID 206071; penurunan -6,56% dari penutupan sesi 29 Oktober Rp 244,00).
   - **Koreksi Angka 230**: Angka 230 adalah harga pembukaan (Open), bukan harga penutupan (Close). Narasi yang menyatakan Close Rp 230 telah dikoreksi menjadi Close Rp 228 (Low Rp 228, High Rp 240).

2. **PTRO (Laporan Keuangan Konsolidasian Interim H1 2026 - Periode 6 Bulan Berakhir 30 Juni 2026)**:
   - **Sumber Bukti Primer**: Berkas resmi `storage/app/evidence/filings/Financial-Statements-30062026-PTRO.pdf` (Hal. 3, Laporan Laba Rugi dan Penghasilan Komprehensif Lain Konsolidasian Interim).
   - **Pendapatan (Revenues)**: **US$ 543.983 ribu** (US$ 543,98 Juta; naik +59,56% YoY vs US$ 340.937 ribu pada H1 2025). Angka seeder US$ 318,45 Juta ditolak sebagai halusinasi/salah seeder.
   - **Rekonsiliasi Laba Periode Berjalan (US$ 9.170 ribu vs US$ 14.820 ribu)**:
     * Laba periode berjalan operasi dilanjutkan: **US$ 1.550 ribu** (Hal. 3 PDF).
     * Laba periode berjalan operasi dihentikan (Catatan 40, Hal. 42-44 PDF): **US$ 7.620 ribu**.
     * **Total Laba Periode Berjalan**: $\text{US\$ } 1.550 \text{ ribu} + \text{US\$ } 7.620 \text{ ribu} = \mathbf{\text{US\$ } 9.170 \text{ ribu}}$.
     * Angka US$ 14.820 ribu pada seeder terbukti salah hitung/fiktif tanpa dukungan baris laporan keuangan resmi.
   - **Atribusi Laba Bersih (Hal. 3 PDF)**:
     * Laba yang dapat diatribusikan kepada pemilik entitas induk: **US$ 10.787 ribu** (US$ 10,79 Juta).
     * Rugi yang dapat diatribusikan kepada kepentingan nonpengendali: **US$ -1.617 ribu**.
     * Total Atribusi: $\text{US\$ } 10.787 \text{ ribu} - \text{US\$ } 1.617 \text{ ribu} = \mathbf{\text{US\$ } 9.170 \text{ ribu}}$.

3. **BNBR (Rasio Solvabilitas & Perubahan Numerator Liabilitas)**:
   - **Status Dokumen Primer**: **UNVERIFIED (BELUM TERSEDIA BERKAS FISIK PDF)**.
   - **Fakta Data Database**:
     * Angka Liabilitas Rp 4.090,30 Miliar dan Ekuitas Rp 3.926,47 Miliar (Rasio 1,04x) berasal dari record `CompanyFundamental` (ID 546, sumber IDX Edge PRO).
     * Angka Liabilitas Rp 3.420,15 Miliar dan Ekuitas Rp 2.890,30 Miliar (Rasio 1,18x) adalah teks buatan manual di command seeder `IngestPilotDocumentChunksCommand.php`.
   - **Penegasan Audit**: Karena berkas fisik PDF laporan keuangan BNBR FY 2025 belum terarsip di direktori lokal, sistem dilarang mengarang penjelasan akuntansi (seperti pemisahan non-controlling interest) tanpa dasar dokumen primer. Angka seeder 1,18x dinyatakan **INVALID_PROVENANCE / UNVERIFIED**. Label solvabilitas resmi tetap menggunakan data database terkontrol IDX Edge PRO dengan rasio 1,04x (`TOTAL_LIABILITIES_TO_TOTAL_EQUITY`).

