# LAPORAN FASE 7.0 — POST-SCHEDULE DATA FRESHNESS & FALLBACK AUDIT
## AETHER MARKET INTELLIGENCE

**Dokumen:** `reports/evaluations/phase7_0_post_schedule_readonly_audit.md`  
**Waktu Audit Aktual:** Senin, 19 Oktober 2026, 20:45 WIB  
**Jenis Audit:** STRICT READ-ONLY AUDIT (Tanpa Perubahan Sistem / Nol Mutasi Data)  
**Status Akhir:** **AUDIT COMPLETE — NO CHANGES MADE**

---

### 1. RINGKASAN STATUS EKSEKUSI & TEMUAN UTAMA

Eksekusi pertama Windows Task Scheduler `\HermesMarketIntelligence_EOD` pada Senin, 19 Oktober 2026 pukul 16:05 WIB telah diverifikasi berjalan dengan sukses teknis (Exit code 0, durasi pipeline 6,29 detik). Namun audit forensik menemukan dua kondisi operasional:

1. **Penyebab Tanggal Bar EOD Tidak Bertambah (Stale Data):**
   - **Fakta Terbukti:** Adapter `services/hermes_adapter.py` pada fungsi `handle_market_snapshots()` membaca secara statis file lokal `E:\hermes-stock-agent\data\raw_daily\{ticker}_1d.csv`.
   - File CSV lokal tersebut terakhir dimodifikasi pada **14 Oktober 2026 pukul 18:26 WIB** (1.391 bar).
   - Tidak ada modul atau perintah otomatis dalam pipeline `run_eod_pipeline.bat` maupun `hermes:sync-eod` yang melakukan pengunduhan (*ingestion/download*) data harian baru dari Yahoo Finance ke direktori `raw_daily/`.
   - Sumber Yahoo Finance daring terbukti telah memiliki candle penutupan final untuk tanggal **15 Oktober, 16 Oktober, dan 19 Oktober 2026**.
   - Keberhasilan pipeline `hermes:sync-eod` hanya menandakan proses query membaca CSV lokal dan query INSERT ke database berhasil dieksekusi, **bukan** jaminan bahwa data pasar yang diproses adalah data baru.

2. **Penyebab Analisis AI Tetap Berada dalam Status Fallback:**
   - **Fakta Terbukti:** Guard proteksi keselamatan pada `app/Services/AiMarketAnalysisService.php` (baris 41–48) mengevaluasi flag `!$this->liveInferenceEnabled && !$forceLive`.
   - Konfigurasi produksi `.env` mempertahankan `AI_LIVE_INFERENCE_ENABLED=false` dan runner batch `run_eod_pipeline.bat` tidak menyertakan argumen `--live-ai`.
   - Sistem secara deterministik langsung mengembalikan `buildFallbackAnalysis` dengan alasan:
     `"Inferensi live AI dinonaktifkan (AI_LIVE_INFERENCE_ENABLED=false). Memerlukan izin operasional eksplisit."`
   - Audit database lokal 9Router (`C:\Users\ASUS\AppData\Roaming\9router\db\data.sqlite`) membuktikan **0 permintaan jaringan** dikirim ke 9Router selama eksekusi pipeline pukul 16:05 WIB.

3. **Status Horizon Evaluator (30 Record Pending):**
   - 20 record lama (Run 3 & Run 4) dan 10 record baru (Run 5) seluruhnya berstatus `pending` dan `is_fallback = true`.
   - Karena snapshot yang di-ingest pada Run 5 memiliki `bar_date = 2026-10-14`, kalkulasi 5 hari bursa BEI menetapkan `target_eval_date = 2026-10-21` (identik dengan record lama).
   - Metrik akurasi AI murni tetap 0% dan terlindungi (tidak terdistorsi) karena logika evaluasi mengecualikan record fallback (`is_fallback = false`).

---

### 2. BUKTI AUDIT A — KESEGARAN DATA EOD (DATA FRESHNESS)

#### A. Rantai Alur Kode Pengambilan Snapshot
1. File Runner `run_eod_pipeline.bat` memanggil:
   `"C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe" artisan hermes:sync-eod --trigger=scheduled`
2. Command `HermesSyncEodCommand.php` (baris 79) memanggil:
   `$snapResp = $adapter->getMarketSnapshots();`
3. Service `HermesAdapterService.php` (baris 76) memanggil adapter eksternal Python:
   `python E:\Project-bot\services\hermes_adapter.py market_snapshots`
4. Adapter `services/hermes_adapter.py` (baris 102–130) membaca file CSV dari direktori:
   `raw_dir = os.path.join(HERMES_STOCK_AGENT_ROOT, "data", "raw_daily")`
   dan mengambil baris terakhir:
   `last_row = df_ind.iloc[-1]`
   `bar_date = str(last_row[date_col])[:10]`

#### B. Audit File Lokal vs Data Daring Yahoo Finance
Audit membuktikan bahwa seluruh file CSV di `E:\hermes-stock-agent\data\raw_daily\` tidak pernah diperbarui sejak diunduh manual pada 14 Oktober 2026:

| Ticker | Bar Terakhir CSV Lokal (`raw_daily`) | Timestamp File CSV Lokal | Bar Terakhir di DB (`market_snapshots`) | Bar Terakhir Yahoo Finance Live | Ketersediaan 15 Okt | Ketersediaan 16 Okt | Ketersediaan 19 Okt |
| :--- | :--- | :--- | :--- | :--- | :---: | :---: | :---: |
| **BBCA** | 2026-10-14 (Rp6.125) | 2026-10-14 18:26:24 | 2026-10-14 (Run 5) | 2026-10-19 (Rp4.820) | TERSEDIA | TERSEDIA | TERSEDIA |
| **BBRI** | 2026-10-14 (Rp3.090) | 2026-10-14 18:26:25 | 2026-10-14 (Run 5) | 2026-10-19 (Rp2.540) | TERSEDIA | TERSEDIA | TERSEDIA |
| **BMRI** | 2026-10-14 (Rp3.960) | 2026-10-14 18:26:25 | 2026-10-14 (Run 5) | 2026-10-19 (Rp3.360) | TERSEDIA | TERSEDIA | TERSEDIA |
| **BBNI** | 2026-10-14 (Rp3.410) | 2026-10-14 18:26:25 | 2026-10-14 (Run 5) | 2026-10-19 (Rp2.960) | TERSEDIA | TERSEDIA | TERSEDIA |
| **ASII** | 2026-10-14 (Rp4.810) | 2026-10-14 18:26:26 | 2026-10-14 (Run 5) | 2026-10-19 (Rp4.130) | TERSEDIA | TERSEDIA | TERSEDIA |
| **TLKM** | 2026-10-14 (Rp2.250) | 2026-10-14 18:26:26 | 2026-10-14 (Run 5) | 2026-10-19 (Rp1.770) | TERSEDIA | TERSEDIA | TERSEDIA |
| **MAPI** | 2026-10-14 (Rp1.565) | 2026-10-14 18:26:26 | 2026-10-14 (Run 5) | 2026-10-19 (Rp1.230) | TERSEDIA | TERSEDIA | TERSEDIA |
| **ARTO** | 2026-10-14 (Rp775)   | 2026-10-14 18:26:26 | 2026-10-14 (Run 5) | 2026-10-19 (Rp585)   | TERSEDIA | TERSEDIA | TERSEDIA |
| **MEDC** | 2026-10-14 (Rp1.545) | 2026-10-14 18:26:26 | 2026-10-14 (Run 5) | 2026-10-19 (Rp1.260) | TERSEDIA | TERSEDIA | TERSEDIA |
| **SMRA** | 2026-10-14 (Rp264)   | 2026-10-14 18:26:27 | 2026-10-14 (Run 5) | 2026-10-19 (Rp220)   | TERSEDIA | TERSEDIA | TERSEDIA |

#### C. Verifikasi Query `hermes:monitor`
Pada `HermesMonitorCommand.php` baris 70:
`$latestSnap = MarketSnapshot::latest('bar_date')->first();`
Query ini mengambil nilai maksimum kolom `bar_date` dari tabel `market_snapshots`. Karena seluruh snapshot yang masuk (termasuk 10 snapshot terbaru pada Run 5) memiliki `bar_date = '2026-10-14'`, maka monitor secara valid dan faktual melaporkan tanggal 14 Oktober 2026. Query monitor telah benar; sumber datalah yang belum pernah mengalirkan bar baru.

---

### 3. BUKTI AUDIT B — PENYEBAB FALLBACK AI

#### A. Jalur Kode Penentu Fallback
Pada `app/Services/AiMarketAnalysisService.php` baris 41–48:
```php
// Safe gate: Prevent unintended live inference unless explicitly enabled
if (!$this->liveInferenceEnabled && !$forceLive) {
    return $this->buildFallbackAnalysis(
        $run,
        $marketContext,
        $template,
        'Inferensi live AI dinonaktifkan (AI_LIVE_INFERENCE_ENABLED=false). Memerlukan izin operasional eksplisit.'
    );
}
```
Nilai variabel pada runtime operasional 19 Oktober:
- `$this->liveInferenceEnabled` = `false` (dibaca dari `.env`)
- `$forceLive` = `false` (karena dipanggil scheduler tanpa `--live-ai`)
- Hasil: Eksekusi langsung bercabang ke `buildFallbackAnalysis()` tanpa menyentuh blok HTTP request (baris 80–120).

#### B. Audit Database Record Analisis (Run 5)
Data pada tabel `market_analyses` untuk ID 5 (`analysis_run_id = 5`):
- `model_name`: `ag/gemini-3.8-flash-high`
- `validation_status`: `fallback`
- `impact_direction`: `unclear`
- `confidence_score`: `0.5`
- `structured_output.fallback_reason`: `"Inferensi live AI dinonaktifkan (AI_LIVE_INFERENCE_ENABLED=false). Memerlukan izin operasional eksplisit."`
- `created_at`: `2026-10-19 09:05:28 UTC` (16:05:28 WIB).

#### C. Bukti Ketiadaan Request ke 9Router
Audit tabel `usageHistory` pada database SQLite lokal 9Router (`C:\Users\ASUS\AppData\Roaming\9router\db\data.sqlite`):
- Rentang waktu eksekusi scheduler: `2026-10-19 09:05:00 UTC` s/d `2026-10-19 09:06:00 UTC`.
- Total request tercatat dari sistem Aether: **0 request**.
- Terbukti secara mutlak bahwa pipeline tidak memicu inferensi berbayar atau konsumsi token ke 9Router.

*Catatan Teks Notifikasi Telegram:*  
Teks notifikasi Telegram memuat peringatan *"Analisis menggunakan HEURISTIK TEKNIKAL FALLBACK akibat router AI tidak merespon/timeout"*. Teks ini berasal dari string template statis pada `TelegramNotificationService.php` baris 112 ketika `validation_status !== 'valid_schema'`. Penyebab aktual di database tetap valid tercatat akibat *kill-switch policy* (`AI_LIVE_INFERENCE_ENABLED=false`), bukan karena koneksi 9Router timeout.

---

### 4. BUKTI AUDIT C — KELOMPOK PREDIKSI & HORIZON EVALUATOR

Total prediksi terdaftar di database saat ini berjumlah 30 record, terbagi ke dalam 3 kelompok run:

| Kelompok | Analysis Run ID | Market Analysis ID | Tanggal Eksekusi Pipeline | Decision Date Tercatat | Reference Price Date | Target Eval Date (BEI +5D) | Jumlah Instrumen | Status | Fallback Flag |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Kelompok 1** (Fase 3/4 Awal) | `3` | `3` | 2026-10-16 17:01 WIB | 2026-10-14 | 2026-10-14 | **2026-10-21** | 10 saham | `pending` | `TRUE` |
| **Kelompok 2** (Fase 4 Dry-run) | `4` | `4` | 2026-10-16 17:03 WIB | 2026-10-14 | 2026-10-14 | **2026-10-21** | 10 saham | `pending` | `TRUE` |
| **Kelompok 3** (Scheduler 19 Okt) | `5` | `5` | 2026-10-19 16:05 WIB | 2026-10-14 | 2026-10-14 | **2026-10-21** | 10 saham | `pending` | `TRUE` |

#### Mengapa Kelompok 3 Memiliki Tanggal Keputusan 14 Oktober?
Pada `PostHorizonEvaluatorService.php` (baris 63–78):
```php
$refSnap = MarketSnapshot::where('ticker', $ticker)
    ->where('bar_date', '<=', $decisionDate)
    ->orderByDesc('bar_date')
    ->first();
$refBarDate = Carbon::parse($refSnap->bar_date);
$targetEvalDate = $this->calendar->addTradingDays($refBarDate, $horizonDays);
```
Karena snapshot yang tersimpan di database untuk Run 5 memiliki `bar_date = 2026-10-14`, maka `$refBarDate` bernilai `2026-10-14`. Penambahan 5 hari bursa BEI (`BeiCalendarService`) dari 14 Oktober (Rabu) menghasilkan:
- Kamis, 15 Okt (Hari 1)
- Jumat, 16 Okt (Hari 2)
- Senin, 19 Okt (Hari 3)
- Selasa, 20 Okt (Hari 4)
- Rabu, 21 Okt (Hari 5) -> **2026-10-21**.

Akibatnya, 10 record baru pada Kelompok 3 didaftarkan dengan target evaluasi yang sama persis dengan Kelompok 1 dan 2 (21 Oktober 2026).

#### Pemisahan Metrik Akurasi AI
Pada `PostHorizonEvaluatorService.php` (baris 240–248):
`$aiEvaluated = $evaluated->where('is_fallback', false);`
Metrik akurasi terbukti 100% aman dari distorsi karena seluruh 30 record berstatus `is_fallback = true` dan otomatis dipisahkan ke dalam metrik heuristik terpisah.

---

### 5. BUKTI AUDIT D — KELARASAN JADWAL (SCHEDULE MATCHING)

Audit pencocokan jadwal membuktikan keterkaitan langsung antara Windows Task Scheduler, Batch Runner, dan Database Laravel:

1. **Windows Task Scheduler (`\HermesMarketIntelligence_EOD`):**
   - Last Run Time: `10/19/2026 4:05:00 PM`
   - Last Result: `0` (Sukses)
   - Action: `E:\Project-bot\run_eod_pipeline.bat`
2. **Runner Log Header & Footer (`storage/logs/eod_pipeline_output.log`):**
   - `[START] EOD Pipeline: Mon 10/19/2026 16:05:01.23`
   - `[FINISH] EOD Pipeline Selesai Sukses: Mon 10/19/2026 16:05:41.78`
   - Durasi batch keseluruhan: 40,55 detik (termasuk bootstrap PHP, sinkronisasi, dan pengiriman Telegram).
3. **Database Laravel (`analysis_runs`):**
   - `run_id`: `RUN_20261019_160522_e0nf`
   - `trigger_type`: `scheduled`
   - `status`: `completed`
   - `started_at`: `2026-10-19 09:05:23 UTC` (16:05:23 WIB)
   - `completed_at`: `2026-10-19 09:05:28 UTC` (16:05:28 WIB)
   - `duration_seconds`: `6.29`
4. **Log Notifikasi Telegram (`notification_logs`):**
   - ID Log: `2`
   - Status: `sent` (dikirim ke `telegram:5923895922` pukul 16:05:40 WIB).

**Kesimpulan Audit D:** Eksekusi 19 Oktober terbukti 100% dipicu oleh Task Scheduler resmi Windows, menggunakan runner batch produksi yang telah di-hardening pada Fase 6.12, dan mencatat exit code 0 tanpa masking kegagalan.

---

### 6. DAMPAK TERHADAP KUALITAS DATA & METRIK

1. **Inkonsistensi Tanggal Pasar:** Data pasar di sistem tertinggal 3 hari bursa (15, 16, 19 Oktober). Pergerakan harga riil selama 3 hari tersebut belum tercermin di database Aether Market Intelligence.
2. **Duplikasi Prediksi Evaluator:** Tabel `post_horizon_evaluations` mencatat 30 record pending dengan harga referensi dan target evaluasi yang sama (`2026-10-21`).
3. **Validitas Metrik AI:** Tidak ada pencemaran metrik. AI accuracy tetap 0.0% (belum jatuh tempo) dan record fallback tidak dihitung sebagai performa model LLM.

---

### 7. REKOMENDASI PERBAIKAN BERDASARKAN PRIORITAS (UNTUK FASE BERIKUTNYA)

Rekomendasi teknis disusun berdasarkan urgensi arsitektur:

- **Prioritas 1 (Kritis — Data Pipeline Ingestion):**
  Lengkapi `handle_market_snapshots()` pada `services/hermes_adapter.py` agar mengintegrasikan pemanggilan `pipeline_collector.py` atau `yfinance` secara dinamis saat EOD dijalankan, sehingga file CSV harian terbarui secara otomatis sebelum snapshot dibaca.
- **Prioritas 2 (Penting — Anti-Duplikasi Horizon Evaluator):**
  Tambahkan guard pada `PostHorizonEvaluatorService::registerPredictions()`: jika tanggal bar terakhir snapshot (`bar_date`) identik dengan snapshot run sebelumnya yang sudah terdaftar, lewati pendaftaran prediksi baru untuk mencegah duplikasi evaluasi pada horizon yang sama.
- **Prioritas 3 (Operasional — Perbaikan Template Pesan Telegram):**
  Perbarui teks penjelasan fallback pada `TelegramNotificationService.php` agar secara akurat menampilkan pesan "AI Live Dinonaktifkan oleh Kebijakan Sistem" alih-alih teks default "router AI tidak merespon/timeout".
- **Prioritas 4 (Fitur — Inferensi Live 9Router):**
  Pertahankan `AI_LIVE_INFERENCE_ENABLED=false` hingga ingestion data pasar telah mutakhir (memuat bar 19 Oktober), lalu lakukan uji aktivasi inferensi live secara bertahap dengan izin pengguna.

---

### 8. TINDAKAN YANG SENGAJA TIDAK DILAKUKAN KARENA BATASAN READ-ONLY

Sesuai instruksi audit keselamatan Fase 7.0, tindakan berikut sengaja **TIDAK DILAKUKAN**:
1. Tidak mengubah kode sumber `services/hermes_adapter.py`, `HermesSyncEodCommand.php`, maupun `run_eod_pipeline.bat`.
2. Tidak mengunduh data baru atau menimpa file CSV di `E:\hermes-stock-agent\data\raw_daily\`.
3. Tidak mengubah, menghapus, atau memanipulasi 30 record evaluasi di database PostgreSQL `project-bot`.
4. Tidak mengubah konfigurasi `.env` atau mengaktifkan `AI_LIVE_INFERENCE_ENABLED`.
5. Tidak mengirim request pengujian ke 9Router (`http://127.0.0.1:20128/v1`).
6. Tidak menjalankan transaksi broker atau mengirim notifikasi eksternal baru.
7. Tidak melakukan commit atau push Git pada kedua repositori.

---

### 9. STATUS AKHIR

**STATUS: AUDIT COMPLETE — NO CHANGES MADE**

Seluruh sasaran audit kesegaran data EOD, penelusuran fallback AI, dan kelarasan jadwal Task Scheduler telah terjawab dengan bukti empiris lengkap. Sistem operasional berada dalam kondisi utuh dan aman tanpa modifikasi.
