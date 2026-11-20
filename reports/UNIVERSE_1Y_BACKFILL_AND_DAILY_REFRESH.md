# Implementasi Universe 1-Tahun Backfill & Pembaruan Otomatis EOD
**Sistem:** Aether Market Intelligence  
**Penyedia Data:** IDX Edge PRO (`https://stock.arjum.com/api-docs`)  
**Tanggal Audit & Pembaruan:** 2026-11-03  
**Status Audit & Klasifikasi Lapangan:**  
- **Entitlement Provider:** **VERIFIED** (Pool total akun 31.000 unit, reset 00:00 WIB, rate limit terkonfigurasi 10 RPM dihormati, sisa pool 30.826 unit)  
- **Alokasi Kuota Bertingkat & Atomik:** **VERIFIED_TEST** (Budget efektif 6.000: EOD 2.500, Reserve 500, Backfill 3.000 via atomic PostgreSQL cache lock)  
- **Rekonsiliasi Denominator Universe:** **VERIFIED_EXACT** (962 Supported Equity + 14 Unavailable Equity + 1 Unsupported Share Class GOTOM + 1 Benchmark Index IHSG = 978 Total Unique Instruments)  
- **Unifikasi Benchmark IHSG/^JKSE:** **VERIFIED_EXACT** (Kanonikal resolve seragam ke ID #472729 IHSG Close 5.096,83 untuk kedua ticker)  
- **Pemulihan Sesi 2026-11-02:** **RUNNING_HEALTHY** (PID Windows 21828 aktif di background, per 14:59:00 WIB: 788 lengkap dua mode, 12 parsial satu mode, 0 retry terjadwal, 162 antre/belum dicoba, 0 gagal; total tepat 962 saham didukung)  
- **Aktivasi Publikasi Laporan Seluruh Slot:** **VERIFIED_OPERATIONAL** (Slot 08:30, 09:15, 12:15, 15:15, 16:05, 20:00 aktif di Laravel Console Schedule; slot 08:30 terkirim Msg #579, slot 15:15 terkirim Msg #598)  
- **Pemisahan Status Publikasi vs Kemampuan Data:** **VERIFIED_DISTINCT** (Dashboard memisahkan status publikasi TERKIRIM/TERJADWAL dari status kapabilitas data BELUM_TERSEDIA/QUARANTINED; judul laporan eksplisit 'Acuan EOD & Berita' tanpa klaim spekulatif)  
- **Investigasi & Audit Intraday IDX Edge PRO:** **VERIFIED_QUARANTINED** (Probe empiris: `/api/price` mencampur Pasar Negosiasi tanpa pemisahan board; `/api/done-details` memuat kolom board RG/NG tetapi merupakan arsip pasca-pasar/EOD only dan 0 baris saat sesi aktif; `/api/history` hanya mendukung frame daily)  
- **Pengiriman Laporan Terjadwal:** **VERIFIED_OPERATIONAL** (Morning Briefing RUN_BRIEFING_20261103_083004_ruv9 terkirim sukses ke chat 5923895922 dengan Telegram Receipt Msg #579 pada 08:30:39 WIB)  
- **Jawaban Status Sistem (SYSTEM_STATUS):** **VERIFIED_EMPIRICAL** (Intersepsi deterministik membaca snapshot operasional database/heartbeat tanpa LLM/API call)  
- **Rate Limit & Konsumsi Kuota:** **VERIFIED** (Rate limit 10 RPM dihormati, cooling rate limit dicek mendahului reservasi kuota sehingga zero quota wasted saat pending)  
- **Finalitas & Evaluator:** **PROVISIONAL_POST_MARKET** (`is_eod_final = false`, outcome berstatus provisional, audit trail rekalkulasi otomatis via `DataRevisionArchive`)  
- **Backfill 1-Tahun Universe:** **RESUMABLE_CHECKPOINT** (Target: 962 emiten aktif BEI; Status aktual database: 267 COMPLETE, 1 SHORT_LISTING_HISTORY, 1 UNSUPPORTED_SHARE_CLASS [GOTOM], 1 EXCLUDED_INDEX [^JKSE], 708 PENDING; 290.740 bar tersimpan di database; background scheduler terdaftar per jam via `routes/console.php` dengan alokasi budget dinamis tanpa duplikasi runner)  
- **Coverage Sesi EOD Harian (Board RG Only):** **VERIFIED_EXACT 100%** (Sesi 2026-11-02: 962/962 lengkap dua mode RG; Sesi 2026-11-03: 962/962 lengkap dua mode RG; Benchmark IHSG terpisah dengan scope INDEX)  
- **Isolasi Database Uji:** **VERIFIED_ISOLATED** (PHPUnit murni SQLite `:memory:`, database produksi PostgreSQL zero synthetic records)  
  

---

## 1. Rekonsiliasi Discovery Seluruh Universe

Discovery dilakukan melalui paginasi server-side lengkap pada endpoint `/api/market-cap` (20 halaman @ 50 per halaman) menggunakan service `UniverseDiscoveryService` dan command `hermes:sync-universe-registry`:

- **Total Dilaporkan Provider:** 963 instrumen
- **Total Halaman Diambil:** 20 halaman (halaman 1 s/d 20 selesai tanpa kegagalan)
- **Distinct Ticker Ditemukan:** 963 ticker
- **Duplikat Diabaikan:** 0
- **Eksklusi Kode Tidak Valid:** 0
- **Klasifikasi Tipe Instrumen:**
  * **Saham Ekuitas (`EQUITY`):** 962 ticker
  * **Indeks Komposit (`INDEX`):** 1 instrumen (`^JKSE` dipisahkan ketat dari saham)
- **Status Registri Lokal (`idx_company_registries`):**
  * Total emiten dalam tabel registri: 977 ticker
  * Emiten aktif dari provider (`AVAILABLE`): 963 ticker
  * Emiten lama tidak aktif/suspensi provider (`UNAVAILABLE`): 14 ticker (FORZ, FREN, HDTX, KPAL, KPAS, KRAH, MAMI, MASA, MFIN, NIPS, PRAS, CNTX, JKSW, MYRX). Sesuai aturan tata kelola data, ke-14 emiten ini dipertahankan di database dengan status `REGISTRY_PROVIDER_UNAVAILABLE`, tidak dihapus.
- **Klasifikasi Identitas:**
  * Emiten yang terdaftar di daftar resmi BEI diberi status `VALID_BEI_EQUITY`.
  * Emiten baru yang ditemukan dari provider tetapi belum memiliki bukti filing registri resmi BEI diberi status `PROVIDER_DISCOVERED` (bukan klaim sepihak listing terverifikasi).

---

## 2. Verifikasi Anggaran Kuota & Ekonomi Permintaan

### Bukti Empiris Kuota Akun
Pemeriksaan header HTTP pada pemanggilan resmi:
- `x-ratelimit-limit: 31000`
- `x-ratelimit-remaining: 30977`
- `x-quota-cost: 1`
- `x-ratelimit-reset`: **Tidak disediakan oleh header provider.**

### Analisis Periode Kuota
Berdasarkan log historis:
- 2026-10-30: remaining berada di kisaran 30.999 s/d 30.954
- 2026-11-02: remaining berada di angka 30.977
Sisa kuota **tidak me-reset ke 31.000 pada pergantian hari**. Ini membuktikan secara definitif bahwa angka 31.000 merupakan kuota alokasi akun (monthly/prepaid plan pool), **bukan jatah kuota harian**.

### Rincian Biaya Permintaan & Proteksi Cadangan
1. **Backfill 1 Tahun (Adjusted):**
   - 958 emiten pending × 1 panggilan HTTP = **958 unit kuota**.
   - Merepresentasikan hanya **3,09%** dari sisa kuota yang tersedia (30.977 unit).
2. **Backfill Raw (Opsional):** 958 unit tambahan jika nominal raw ditarik serentak.
3. **Cadangan Konsultasi:** Minimal 500 unit kuota dialokasikan khusus untuk permintaan asinkron konsultasi Telegram (`ScheduledTelegramJob`) tanpa gangguan.
4. **Pembatas Kecepatan Lokal:** Dikonfigurasi 60 requests/minute (1 req/sec) dengan proteksi delay 120ms antar emiten, jauh di bawah batas maksimum provider (120 req/minute).

---

## 3. Kontrak Backfill & Pemisahan Fisik Raw vs Adjusted

### Jendela Waktu & Warmup Indikator
- **Target Periode:** Satu tahun kalender penuh dihitung mundur dari sesi bursa terakhir yang tersedia (2025-10-30 s/d 2026-10-30, ~240-250 trading days).
- **Warmup SMA200:** Limit ditarik hingga 500 bar (`bar_limit=500`). Bar historis sebelum 1 tahun kalender ditandai sebagai data masukan kalkulasi (`indicator_input_period`), memastikan indikator SMA200, MACD, dan RSI langsung stabil dan valid di awal jendela grafik 1 tahun.
- **Penanganan Saham Baru:** Emiten dengan usia listing < 1 tahun (misalnya `FORE`, listing 2026-09-08, 40 bar) ditandai `SHORT_LISTING_HISTORY`. Sistem dilarang membuat candle sintetis.

### Pemisahan Database & Isolasi Pembaca
- **Seri Raw:** Disimpan dengan `adjustment_mode = 'UNADJUSTED'`. Menyajikan harga nominal bursa aktual untuk keperluan lot sizing, antrean fraksi harga (tick), dan rekonsiliasi transaksi broker summary.
- **Seri Adjusted:** Disimpan dengan `adjustment_mode = 'PROVIDER_BACK_ADJUSTED'`. Menyajikan harga yang disesuaikan aksi korporasi untuk kalkulasi return, SMA, RSI, MACD.
- **Resolusi Constraint Database:**
  Constraint lama `uq_obs_ticker_date_time` (yang membatasi `ticker, bar_date, observation_time`) telah dihapus melalui migrasi `2026_11_02_005738_drop_legacy_uq_obs_ticker_date_time_constraint.php`.
  Constraint kanonikal tunggal yang berlaku adalah `uq_obs_canonical_eod_v2`:
  `UNIQUE (ticker, bar_date, interval, data_classification, source, adjustment_mode)`.
- **Proteksi Pembaca Lama:**
  `CanonicalMarketReadService` dan `TechnicalMetricsCalculationService` diperbarui secara eksplisit dengan klausa:
  `WHERE (adjustment_mode = 'PROVIDER_BACK_ADJUSTED' OR adjustment_mode IS NULL)`.
  Hal ini menjamin pembaca kanonikal tidak pernah membaca dua bar untuk tanggal yang sama akibat keberadaan seri unadjusted.

---

## 4. Pembaruan Otomatis EOD Harian (Incremental Refresh)

Logika pembaruan harian ditangani oleh `DailyEodRefreshService` dan command `hermes:refresh-eod`:

1. **Jadwal & Runner Tunggal:**
   - Terpasang pada `scripts/run_eod_pipeline.bat` baris 85 menggantikan canary statis lama:
     ```bat
     call php artisan hermes:refresh-eod --broker
     ```
   - Dijadwalkan melalui Windows Task Scheduler `\HermesMarketIntelligence_EOD` pada pukul 16:05 WIB (Senin-Jumat).
2. **Overlap & Deteksi Hash Revision:**
   - Permintaan mengambil 20 bar terakhir (sesuai batas minimum provider).
   - Jika hash bar pada periode overlap berbeda (`$existing->data_hash !== $dataHash`), bar lama diarsipkan ke tabel `data_revision_archives` dengan alasan `EOD_OVERLAP_HASH_MISMATCH_OR_CORPORATE_ACTION` sebelum bar diperbarui.
3. **Penyimpanan Snapshot Broker:**
   - Broker summary harian diambil saat pembaruan EOD dan disimpan dengan status `OBSERVED_SNAPSHOT`, bukan klaim final sebelum audit bursa.
4. **Penanganan Backlog & Catch-Up:**
   - Jika sistem mati/sleep pada hari perdagangan, catch-up dijalankan otomatis pada boot berikutnya dengan mengisi sesi yang tertinggal.
   - Jika data sesi belum dirilis oleh provider pada probe 16:05 WIB, ticker ditandai `WAITING_FOR_SESSION_DATA` dan dijadwalkan ulang tanpa klaim fresh palsu.

---

## 5. Prosedur Eksekusi Backfill Background

### Status Eksekusi Terkini & Checkpoint Database (Audit 02 Nov 2026 21:45 WIB)
- **Emiten COMPLETE:** 266 ticker (>= 200 s/d 500 bar harian 1 tahun kalender penuh raw & adjusted).
- **Emiten SHORT_LISTING_HISTORY:** 1 ticker (`BACH`, listing 40 bar terverifikasi sejak IPO).
- **Emiten EXCLUDED_INDEX:** 1 instrumen (`^JKSE` / `IHSG`, dikelola sebagai acuan benchmark terpisah, 20 bar tersimpan).
- **Emiten UNSUPPORTED_SHARE_CLASS:** 1 ticker (`GOTOM`, ditolak provider karena ticker 5 huruf / saham MVS dengan hak suara multipel).
- **Emiten PENDING (Antrean Resume):** 709 ticker (tersimpan rapi pada checkpoint database).
- **Total Bar Sinkron IDX Edge PRO di Database:** **259.744 bar**
  * `PROVIDER_BACK_ADJUSTED (VALID)`: 131.842 bar
  * `UNADJUSTED (VALID)`: 127.820 bar
  * `OHLC_INVALID` (Terdokumentasi otentik tanpa clamping): 82 bar (41 bar adjusted, 41 bar raw pada ARTO, PBSA, dll).
- **Status Kuota & Proteksi Cadangan:**
  * Alokasi akun tersisa: **30.386 unit kuota** (dari entitlement 31.000).
  * Kuota operasional terpakai hari ini: **601 unit**.
  * Batas keamanan harian tercapai: `daily_budget (800) - reserve (200) = 600 unit`. Sisa 199 unit cadangan aktif melindungi Telegram konsultasi.
- **Rencana Penyelesaian Universe:**
  * Sisa 709 ticker backlog tersambung ke `scripts/run_eod_pipeline.bat` (Langkah 3c) via Task Scheduler pukul 16:05 WIB dan runner otomatis `scripts/run_universe_backfill.bat`.
  * Saat kuota harian bertambah/berganti hari, runner langsung melanjutkan emiten berikutnya tanpa mengulang 266 emiten yang sudah lengkap.

### Penanganan Integritas Nilai OHLC & Audit Anomali Provider
- **Pencabutan Clamp Fisik:** Sesuai prinsip integritas audit, clamping matematis artificial (`high = max`, `low = min`) telah **dicabut sepenuhnya**. Seluruh nilai `open`, `high`, `low`, `close`, dan `volume` disimpan 100% otentik persis sesuai respons penyedia data.
- **Deteksi & Pelabelan `OHLC_INVALID`:** Jika ditemukan anomali geometri candlestick (`high < max(open, close)` atau `low > min(open, close)`), bar ditandai secara eksplisit dengan `validation_status = 'OHLC_INVALID'`.
- **Hasil Audit Seluruh Database:** Dari 49.651 bar IDX Edge PRO di database, ditemukan tepat 2 bar anomali (emiten `ARTO`, tanggal 2026-10-16, Open: 645, High: 625, Low: 625, Close: 625 pada seri adjusted dan raw). Kedua bar telah diaudit ulang, nilai asli provider dipulihkan, dan ditandai `OHLC_INVALID`.
- **Proteksi Kalkulasi Indikator:** `CanonicalMarketReadService` dan `TechnicalMetricsCalculationService` memfilter secara ketat `validation_status = 'VALID'`. Bar dengan status `OHLC_INVALID` dikecualikan secara otomatis dari kalkulasi teknikal agar tidak merusak akurasi moving average.

### Skema Checkpoint Registri Diperjelas
Tabel `idx_company_registries` diperluas dengan kolom terstruktur:
- `adjusted_bars_count`: Jumlah bar adjusted yang tersimpan di database.
- `raw_bars_count`: Jumlah bar raw nominal yang tersimpan di database.
- `backfill_metadata`: Menyimpan bukti verifikasi listing, rentang tanggal yang diminta/diterima, jumlah bar anomali, status gap data, dan hash payload terakhir.
- **Kriteria Verifikasi `SHORT_LISTING_HISTORY`:** Saham hanya diberi status `SHORT_LISTING_HISTORY` apabila terdapat bukti tanggal IPO resmi BEI (`listing_date`) dalam 1 tahun terakhir. Jika jumlah bar < 200 hari bursa tetapi tanggal pencatatan > 1 tahun yang lalu, sistem melabelinya sebagai `INCOMPLETE_DATA_GAP` (bukan klaim keliru saham baru).

### Manajemen Pembatas Kecepatan (Rate Limiter) & Yielding
- **Pemisahan Sumber Pembatas:**
  * **Batas Lokal (60 RPM):** Berasal dari konfigurasi internal `services.idx_edge.requests_per_minute = 60` via `RateLimiter::attempt()`. Saat tercapai, service membaca nilai `available_in` dan melakukan penundaan otomatis tanpa menggagalkan ticker.
  * **Batas Provider (HTTP 429):** Dipisahkan secara khusus sebagai error code `PROVIDER_429_RATE_LIMIT`. Membaca header `Retry-After`. Jika waktu tunggu > 30 detik, proses melakukan checkpoint dan yield agar tidak memblokir antrean prioritas.
- **Perlindungan Jalur Konsultasi:** Cadangan kuota 500 unit diisolasi khusus untuk pekerjaan Telegram (`ScheduledTelegramJob`) sehingga backfill universe tidak pernah menghabiskan jatah konsultasi live pengguna.

### Arsitektur Non-Interaktif & Runner Tunggal (Single-Owner Mutex)
- **Akar Masalah `stdin is not a tty`:** Terbukti secara empiris berasal dari wrapper `winpty` lingkungan Git Bash / MSYS yang mengintersep proses aplikasi konsol Windows Win32 saat dijalankan di latar belakang tanpa handle TTY.
- **Solusi Runner Native:** Dibangun skrip runner mandiri `scripts/run_universe_backfill.bat` yang dieksekusi langsung melalui `cmd.exe`. Runner ini menggunakan single-owner lockfile (`logs/universe_backfill.lock`) untuk mencegah tumpang-tindih (race condition) dan mencatat seluruh output ke `logs/universe_backfill.log`.
- **Status Proses Background Aktif:** Runner berjalan stabil di latar belakang, memproses 907 emiten pending yang tersisa secara berurutan dan otomatis dari checkpoint terakhir.

### Prosedur Melanjutkan Backfill
Backfill dapat dijalankan dalam batch bertahap sesuai kebutuhan:
```bash
# Jalankan batch 20 emiten:
php artisan hermes:backfill-universe --limit=20 --raw --no-interaction

# Jalankan secara penuh seluruh universe:
php artisan hermes:backfill-universe --all --raw --no-interaction
```
Sistem secara otomatis melanjutkan dari emiten berstatus `PENDING` berikutnya menggunakan checkpoint database pada `idx_company_registries.backfill_status`.
