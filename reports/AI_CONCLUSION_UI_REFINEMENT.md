# Laporan Perbaikan Antarmuka Tab Kesimpulan AI & Finalisasi Penerimaan Sistem Aether

**Workspace:** `E:\Aether-Market-Intelligence`  
**Aplikasi:** `E:\Aether-Market-Intelligence\dashboard`  
**Tanggal Evaluasi:** 27 Oktober 2026  
**Status UI:** Berbasis DESIGN.MD; kesesuaian dengan aset Stitch belum diverifikasi.  
**Screenshot Bukti Terverifikasi:**
- **BBCA (Emiten Lengkap & Konstituen ML):** `C:\Users\ASUS\AppData\Local\hermes\cache\screenshots\browser_screenshot_e463e34a45d04aff96a7dc706982072e.png`
- **UNKNOWN (Ticker Belum Terdaftar):** `C:\Users\ASUS\AppData\Local\hermes\cache\screenshots\browser_screenshot_9f689d3d959947eeb6a8a14c54fca2d1.png`

---

## 1. Penyimpangan Terakhir yang Ditutup (Final Corrections)

1. **Identitas Model Aktual vs Protokol Strategi:**
   - Label model prediktif Machine Learning diganti menjadi model artefak riil: `RandomForest_v1.0`.
   - EXP-04 diposisikan dan dilabeli secara tepat sebagai: `Strategi Aturan Teknikal (EXP-04)` (bukan protokol walk-forward).
2. **Standardisasi Palet Warna Institusional:**
   - Menghapus aksen cyan/amber mencolok dari tab Kesimpulan AI.
   - Mengembalikan visual secara ketat ke palet institusional:
     * **Navy:** `#05152B`, `#0A213D`, `#0D2B4E` (background & kontainer kartu).
     * **Gold:** `#E5C49B`, `#D6B284` (aksen, judul, ikon, tombol aktif, badge).
     * **Ivory:** `#F5F1E8`, `#F5F1E8/85`, `#F5F1E8/60`, `#F5F1E8/10` (tipografi & garis pembatas).
   - Faktor Pendukung dan Faktor Risiko kini menggunakan ikon semantik (`checkmark` dan `warning triangle`) dengan kontras palet gading dan emas.
3. **Label Fallback & Atribusi Sumber Bersih:**
   - Fallback tanpa analisis Gemini dilabeli secara eksplisit: `"Ringkasan data — bukan analisis Gemini"`.
   - Menampilkan sumber data aktual: `Yahoo Finance (data sekunder publik)` dan tanggal observasi riil.
   - Menghapus istilah keliru `"EOD resmi BEI"` untuk data Yahoo Finance; ditegaskan sebagai feed sekunder publik, bukan kliring resmi BEI.
4. **Pencegahan Riset Ticker Tak Dikenal (UNKNOWN):**
   - Ticker tidak dikenal (`UNKNOWN`) dikunci total (`Riset Terkunci (Ticker Tak Dikenal)`) dan dinonaktifkan (`disabled`) sebelum instrumen tervalidasi di registri BEI.
   - Emiten valid terdaftar di registri BEI namun belum lengkap data tetap diizinkan meminta riset on-demand.
5. **Pemisahan Status Kuantitatif ML:**
   - **Anggota Universe:** Memisahkan instrumen 10 konstituen (`WALK_FORWARD_VALIDATED_CONSTITUENT`) dari non-konstituen (`NON_UNIVERSE_INSTRUMENT`).
   - **Inference Bar Aktif:** Jika model belum dijalankan untuk bar tanggal bersangkutan, status dilaporkan sebagai `NO_ACTIVE_INFERENCE_FOR_BAR` dengan probabilitas dan confidence `N/A`.
   - **Hasil ABSTAIN Riil:** Hanya ditampilkan jika inferensi memang telah dijalankan pada bar tersebut dan model secara eksplisit memutuskan abstain guna membatasi risiko spekulasi.
6. **Penguatan Kolektor Data EOD (`services/fetch_ticker_eod.py` & `OnDemandDataCollectorService.php`):**
   - Validasi ketat `math.isfinite()` untuk seluruh harga OHLC.
   - Validasi volume non-negatif integer dan parsing tanggal format ISO `YYYY-MM-DD`.
   - Strict JSON serialization (`allow_nan=False`).
   - Pencatatan eksplisit apabila bar terakhir tidak lengkap/NaN: mencatat `discarded_latest_bar`, `discarded_bar_date`, `discarded_reason`, dan `replacement_bar_date` di `research_metadata`, mencegah data lama diam-diam diklaim sebagai data teranyar.
7. **Sidebar Desktop Terkunci (Fixed):**
   - Menggunakan `md:sticky md:top-0 md:h-screen md:shrink-0` dengan scroll area navigasi mandiri. Footer status mesin dan database tetap terkunci di bawah layar saat konten utama digulung.

---

## 2. Hasil Pengujian & Verifikasi

### A. Automated Test Suite (PHPUnit)
Eksekusi pengujian suite aplikasi penuh pasca-koreksi:
```text
Tests: 179 passed (779 assertions)
Duration: 56.01s
Database: SQLite :memory: terisolasi
Status: 100% PASSED (0 failures, 0 errors)
```
- Mencakup pengujian unit baru `tests/Unit/AiConclusionPresenterTest.php` (7 tests, 48 assertions) yang memvalidasi:
  * Pemisahan label model `RandomForest_v1.0` dan protokol EXP-04.
  * Status `NO_ACTIVE_INFERENCE_FOR_BAR` saat inferensi bar belum dihitung.
  * Atribusi sumber sekunder publik tanpa klaim resmi BEI untuk Yahoo Finance.
  * Penguncian tombol riset untuk ticker tidak dikenal (`UNKNOWN`).
  * Izin riset untuk emiten valid terdaftar.

### B. Verifikasi Browser & Visual DOM
1. **Emiten BBCA (`/market/BBCA?tab=ai_insight`):**
   - Judul model: `PREDIKSI MODEL: RANDOMFOREST_V1.0 • WALK-FORWARD VALIDATION PROTOCOL (EXP-04)`.
   - Status: `WALK_FORWARD_VALIDATED_CONSTITUENT`, sinyal `NO_ACTIVE_INFERENCE_FOR_BAR`, probabilitas `N/A`, confidence `N/A`.
   - Palet visual: Bersih dengan kontras navy, gold, ivory.
2. **Emiten UNKNOWN (`/market/UNKNOWN?tab=ai_insight`):**
   - Tombol riset berstatus `disabled` dengan teks `Riset Terkunci (Ticker Tak Dikenal)`.
   - Header ringkasan: `RINGKASAN DATA — BUKAN ANALISIS GEMINI`.
   - Prediksi: `NON_UNIVERSE_INSTRUMENT` dengan seluruh metrik `N/A`.

---

## 3. Status Integrasi Telegram & Observasi Forward

- **Gateway & Worker Runtime:**
  * `hermes.exe` dan `Telegram.exe` aktif pada host lokal.
  * Task Scheduler `\AetherWorkers_5min` aktif memproses antrean dispatcher outbox dan worker riset.
  * Chat saham Gemini aktif melalui Local AI Router `ag/gemini-3.8-flash-high`.
- **Pengujian Telegram Interaktif:**
  * Berstatus **PENDING** menunggu pesan riil dari akun Telegram pengguna.
  * Pesan Uji 1: `"Analisis fundamental dan teknikal BBCA, jelaskan kesimpulan dan risikonya."`
  * Pesan Uji 2: `"Berita apa yang mendukung kesimpulan tadi?"`
  * Sistem siap mencatat: numeric `update_id`, `inbound_message_id`, `request_id`, `evidence_snapshot_id`, `analysis_id`, model riil, `outbound_message_id`, dan latensi end-to-end tanpa membuat pesan sintetis.
- **Observasi Forward Kohort SWING_FILTER_V1 (27 Oktober 2026):**
  * Output A (Studi Sentuhan Barrier): Berstatus `WAITING_FOR_FINAL_DATA`. Pukul 16:05 WIB adalah probe ketersediaan data, bukan jaminan finalitas. Data Yahoo tetap dilabeli sumber sekunder publik.
  * Output B (Simulasi Return Neto): Tetap ditahan (*withheld*) karena keterbatasan metodologi eksekusi riil.
  * Tidak ada training ulang model dan tidak ada perubahan parameter/threshold produksi.
