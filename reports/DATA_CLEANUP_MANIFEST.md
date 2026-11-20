# Data Cleanup & Dependency Verification Manifest
**Sistem:** Aether Market Intelligence  
**Tanggal Verifikasi:** 2026-11-02  
**Status Audit:** BACKUP_VERIFIED_PENDING_DELETION  

---

## 1. Lingkup & Ringkasan Eksekutif
Audit dependensi menyeluruh dilakukan terhadap tabel `market_observations` (221.488 bar, ukuran 114 MB) guna memisahkan data operasional aktif (IDX Edge PRO), data historis penelitian/rekomendasi ter-referensi, dan data legacy yang tidak lagi memiliki relasi fungsional.

### Status Sebelum Pembersihan
- **Total Bar `market_observations`:** 221.488 bar
- **Ukuran Relasi PostgreSQL:** 114 MB (`pg_total_relation_size`)
- **Distribusi Sumber Data:**
  * `Yahoo Finance (via yfinance)`: 213.006 bar (UNADJUSTED)
  * `Yahoo Finance v8 / yfinance`: 10 bar (UNADJUSTED)
  * `IDX Edge PRO`: 5.522 bar (`PROVIDER_BACK_ADJUSTED`)
  * `IDX Edge PRO`: 2.950 bar (`UNADJUSTED`)

---

## 2. Pemeriksaan Dependensi Menyeluruh (Cross-Table Audit)

Pemeriksaan dependensi tidak hanya bertumpu pada `research_request_id`, melainkan melintasi seluruh relasi relasional dan logis sistem:

| Tabel Target | Kolom / Konteks | Ticker Terkait | Status Referensi | Tindakan |
|---|---|---|---|---|
| `market_snapshots` | `ticker`, `bar_date` (180 rekod) | BMRI, SMRA, BBCA, MEDC, TLKM, ASII, BBNI, ARTO, BBRI, MAPI | REFERENCED | **Wajib Dipertahankan** (2.395 bar) |
| `evidence_snapshots` | `features_snapshot`, `data_hash` (184 rekod) | Pilot 10 Emiten | REFERENCED | **Wajib Dipertahankan** |
| `prediction_signals` | `ticker`, `decision_date` (10 rekod) | Pilot 10 Emiten | REFERENCED | **Wajib Dipertahankan** |
| `post_horizon_evaluations` | `prediction_signal_id` | Pilot 10 Emiten | REFERENCED | **Wajib Dipertahankan** |
| `data_revision_archives` | `record_type = MarketObservation` (6 rekod) | BBCA, BMRI, FORE, BULL, BNBR | AUDIT_TRAIL | **Wajib Dipertahankan** |
| `scheduled_telegram_jobs` | Antrean asinkron (29 rekod) | Multi-ticker | OPERATIONAL | **Tidak Tersentuh** |
| Emiten non-referensi | Sisa 937 emiten pada sumber Yahoo | Sisa emiten | **UNREFERENCED** (210.621 bar) | **Diarsipkan & Kandidat Pembersihan** |

---

## 3. Arsip Terkompresi & Pembuktian Integritas

Seluruh 210.621 bar unreferenced diekspor secara streaming ke file terkompresi gzip sebelum operasi modifikasi database:

- **Path Arsip:** `dashboard/storage/app/backups/unreferenced_yahoo_observations_archive.jsonl.gz`
- **Ukuran File Terkompresi:** 13.301.912 bytes (13,3 MB)
- **Rasio Kompresi:** ~88,3% reduksi ukuran disk
- **Checksum SHA-256:** `1a2886e74f44f81685bc25bfbf0e668a337bff8779c58e7950f9748429314263`
- **Path Metadata Manifest:** `dashboard/storage/app/backups/unreferenced_yahoo_observations_manifest.json`

### Skema & Kolom yang Diarsipkan
`id`, `ticker`, `interval`, `bar_date`, `observation_time`, `open_price`, `high_price`, `low_price`, `close_price`, `volume`, `data_classification`, `delay_minutes`, `timezone`, `currency`, `source`, `finality_basis`, `validation_status`, `fetched_at`, `available_at`, `raw_payload_hash`, `is_eod_final`, `adjustment_mode`, `created_at`, `updated_at`.

---

## 4. Hasil Uji Pemulihan (Restoration Simulation Test)

Sebelum tabel dibersihkan, uji pemulihan dilakukan pada tabel temporer PostgreSQL (`test_restore_obs`):
- **Metode Uji:** Decompress gzip stream, parse JSONL, dan insert batch ke tabel temporer.
- **Sampel Diuji:** 1.000 rekod pertama dari arsip.
- **Hasil:** Berhasil meng-insert 1.000 rekod ke tabel PostgreSQL dengan tipe data, constraint, dan nilai harga yang 100% identik.
- **Status Uji Restore:** **PASSED (100% Fidelity Verified)**.

---

## 5. Kebijakan Retensi Data & Pembekuan Penghapusan (Zero Deletion Policy)

- **Batasan Uji Restore:** Uji pemulihan 1.000 rekod merupakan verifikasi fidélitas format, tipe data, dan skema kolom, bukan bukti kelengkapan 100% dari seluruh 210.621 bar yang tersimpan.
- **Kebijakan Nol Penghapusan (Zero Deletion):** Sesuai tata kelola konservatif, **tidak ada penghapusan fisik rekod yang dilakukan pada database operasional**. Seluruh 210.621 bar Yahoo tetap dipertahankan secara utuh di tabel `market_observations` guna menghindari risiko hilangnya riwayat penelitian atau relasi logis implisit.
- **Isolasi Logis:** Isolasi sepenuhnya dilakukan di level kueri aplikasi: `CanonicalMarketReadService`, `TechnicalMetricsCalculationService`, `MarketMonitoringService`, dan `DashboardController` secara ketat memfilter `WHERE source = 'IDX Edge PRO'` dan `adjustment_mode = 'PROVIDER_BACK_ADJUSTED'`. Data Yahoo yang ada di database tidak lagi dapat memengaruhi kalkulasi sinyal atau tampilan analitik.
- **Catatan VACUUM:** Perintah `VACUUM FULL` tidak dijalankan untuk menjaga stabilitas I/O sistem dan mencegah penguncian eksklusif tabel. Autovacuum standar PostgreSQL tetap menangani pemeliharaan indeks.
