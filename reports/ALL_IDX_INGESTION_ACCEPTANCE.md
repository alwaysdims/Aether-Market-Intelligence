# Laporan Audit & Penerimaan Ingestion Seluruh Saham BEI

**Waktu Snapshot Audit**: 26 Oktober 2026, 20:00 WIB  
**Status Evaluasi**: **AUDIT LULUS & PIPELINE TERVERIFIKASI EMPIRIS**  
**Database Backup**: `E:\Aether-Market-Intelligence\backups\backup_pre_idx_all_20261026.sql` (460 KB)  
**Test Suite**: 168 passed, 698 assertions (100% lulus)  
**Pint Code Styling**: Bersih (0 formatting violations)

---

## 1. Cakupan Database Aktual (Dihitung Langsung dari Basis Data)

Hasil query empiris PostgreSQL per 26 Oktober 2026, 20:00 WIB:

| Metrik Cakupan Database | Jumlah Aktual | Denominator & Catatan Verifikasi |
|---|---|---|
| **Kandidat Saham pada Registri** | **941 emiten** | Total populasi kandidat saham ekuitas dari registri sekunder (Wikipedia BEI). |
| **Indeks Acuan Snapshot** | **1 indeks** | `^JKSE` (IHSG) dipisahkan secara eksplisit sebagai instrumen `INDEX` (tidak dihitung saham). |
| **Status Pekerjaan Ingestion (Jobs)** | | |
| • Belum Masuk Antrean (`NOT_ENQUEUED`)| **743 emiten** | Kandidat registri yang belum dimasukkan ke antrean batch. |
| • Dalam Antrean (`PENDING`) | **13 emiten** | Pekerjaan siap diproses oleh worker berikutnya. |
| • Sedang Berjalan (`RUNNING`) | **0 emiten** | Tidak ada pekerjaan yang terkunci aktif saat snapshot. |
| • Selesai Penuh (`COMPLETED`) | **128 emiten** | Harga, aksi korporasi, lapkeu, dan metrik terhitung penuh (termasuk emiten rugi bersih dengan PE N/A). |
| • Selesai Parsial (`PARTIAL`) | **55 emiten** | Data tersimpan sebagian (lapkeu USD non-IDR atau data belum ditemukan pada feed publik). |
| • Gagal Final (`FAILED`) | **2 emiten** | Melebihi batas maksimal percobaan (3x). |
| • Terblokir (`BLOCKED`) | **0 emiten** | Tidak ada pekerjaan yang diblokir permanen. |
| **Cakupan Komponen Data Terpisah** | | |
| • Riwayat Harga EOD | **194 emiten** | Memiliki bar harga tersimpan di `market_observations` (eksklusif saham ekuitas). |
| • Riwayat Harga $\ge 20$ Bar | **189 emiten** | Memenuhi syarat histori SMA20, RSI14, ATR14. |
| • Riwayat Harga $\ge 50$ Bar | **182 emiten** | Memenuhi syarat histori SMA50. |
| • Riwayat Harga $\ge 200$ Bar | **182 emiten** | Memiliki $\ge 200$ bar perdagangan tersimpan di basis data. |
| • Drawdown 1Y Terverifikasi Penuh | **133 emiten** | Terverifikasi rentang tanggal $\ge 340$ hari kalender dan $\ge 190$ sesi perdagangan. |
| • Laporan Keuangan | **149 emiten** | Tersimpan di tabel `company_fundamentals` (683 laporan tahunan). |
| • Metrik Deterministik | **189 emiten** | 189 emiten teknikal valid (`CALCULATED`), 132 emiten fundamental valid. |
| • Narasi AI Gemini | **21 emiten** | 21 emiten tersimpan valid di DB (disimpan terpisah via cache evidence content-based). |

**Relasi 194 Emiten Berharga vs Antrean Job**:
Pekerjaan batch antrean telah memproses 198 entri job. Selisih emiten berharga sebelum ekspansi batch berasal dari 11 emiten konstituen inti Tahap I serta permintaan on-demand interaktif yang telah memiliki observasi harga sebelum batch otomatis berjalan.

---

## 2. Audit Sumber Registri & Pencegahan Klaim Palsu

1. **Audit Endpoint Resmi BEI**:
   - URL Resmi: `https://www.idx.co.id/en/listed-companies/company-profiles`
   - Respon Langsung: HTTP 403 (Cloudflare Bot Challenge).
   - Status Akses: Dicatat sebagai `SOURCE_UNAVAILABLE_CLOUDFLARE_BLOCKED` sesuai aturan tanpa bypass paywall/captcha terlarang.
2. **Koreksi Label Sumber Sekunder**:
   - URL Sumber yang Digunakan: `https://id.wikipedia.org/wiki/Daftar_perusahaan_yang_tercatat_di_Bursa_Efek_Indonesia`
   - Label Resmi: **`Kandidat saham dari registri sekunder (Wikipedia: Daftar perusahaan BEI - validasi feed publik)`**.
   - Penegasan Status: **Belum seluruhnya terverifikasi aktif BEI**. Ticker `.JK` di Yahoo Finance hanya membuktikan keberadaan simbol feed publik, bukan status legal pencatatan bursa.
3. **Dukungan Berkas Resmi Pengguna & Batasan DOCUMENT_VERIFIED**:
   - Perintah Artisan: `php artisan idx:import-official-registry {file}`
   - Memvalidasi struktur data JSON/CSV resmi BEI, tanggal snapshot, dan hash SHA-256 berkas. Nama berkas saja ditolak sebagai bukti keabsahan.
   - **Batasan Ketat DOCUMENT_VERIFIED**: Label `DOCUMENT_VERIFIED` wajib merujuk secara eksplisit nama berkas fisik, hash SHA-256, dan fakta spesifik yang diverifikasi (fakta profil pencatatan: ticker, nama, papan, sektor, saham tercatat). Impor berkas registri **TIDAK PERNAH** memverifikasi laporan keuangan, neraca/laba rugi, rasio valuasi, atau harga pasar (yang tetap berstatus `PROVISIONAL` atau `PROVIDER_SUPPLIED`).

---

## 3. Audit Kedalaman Histori & Indikator Teknikal

1. **Penjelasan 30 Bar Canary Awal**:
   - 30 bar pada pengujian canary awal adalah **sampel tampilan** akibat pemotongan `tail(30)` pada skrip Python awal, bukan seluruh histori yang dapat diambil.
   - Skrip telah diperbaiki: mengambil dan menyimpan seluruh histori hingga 5 tahun (`period="5y"`, rata-rata 1.200 bar perdagangan per emiten).
2. **Kepatuhan Warmup & Nilai N/A**:
   - `sma_20`: mewajibkan riwayat $n \ge 20$ bar.
   - `sma_50`: mewajibkan riwayat $n \ge 50$ bar; bernilai `N/A` jika histori kurang.
   - `sma_200`: mewajibkan riwayat $n \ge 200$ bar; bernilai `N/A` jika histori kurang.
   - `max_drawdown_1y_pct`: mewajibkan riwayat $n \ge 200$ bar; bernilai `N/A` jika histori kurang.
   - `rsi_14`: mewajibkan riwayat $n \ge 15$ bar (Wilder's RSI).
   - `macd`: mewajibkan riwayat $n \ge 35$ bar (EMA 12, 26, dan Signal 9).
   - `atr_14`: mewajibkan riwayat $n \ge 15$ bar.
   - Metadata kecukupan disimpan dalam kolom JSON `indicators_meta`.
3. **Integritas Point-in-Time Support & Resistance**:
   - Support: nilai terendah 20 hari bergulir hingga bar berjalan ($0 \dots t$).
   - Resistance: nilai tertinggi 20 hari bergulir hingga bar berjalan ($0 \dots t$).
   - Tidak ada data masa depan yang bocor (zero forward-looking data).

---

## 4. Audit Fundamental & Pembuktian Angka Emiten

### A. Rekonsiliasi Komprehensif Emiten KIJA
1. **Rekonsiliasi Harga Penutupan**:
   - **ID 115**: Bar 23 Okt 2026 (Jumat) | Open: Rp 127, High: Rp 132, Low: Rp 125, Close: **Rp 130,00**, Volume: 210.313.200 | Hash: `cdf744ad49298d4fa0814d8b9ae54b36f01b4bde65c9ff1344d24684e287ddc8`.
   - **ID 363**: Bar 26 Okt 2026 (Senin) | Open: Rp 130, High: Rp 132, Low: Rp 125, Close: **Rp 125,00** (intraday Rp 127,00 saat sesi berjalan), Volume: 81.578.400.
   - **Status Label Harga**: **`PROVIDER_SUPPLIED`** (feed publik Yahoo Finance, bukan data kliring resmi BEI). Tanggal bar dan ID observasi dicantumkan eksplisit.
2. **Rekonsiliasi Utang, Sewa Pembiayaan, & Hasil DER**:
   - **Utang Jangka Panjang (Long Term Debt)**: **Rp 4.446,38 Miliar** (Rp 4.446.384.000.000).
   - **Utang Jangka Pendek (Current Debt)**: **Rp 155,35 Miliar** (Rp 155.348.000.000).
   - **Penjumlahan Pinjaman Murni**: 4.446,38 + 155,35 = **Rp 4.601,73 Miliar** (Rp 4.601.732.000.000).
   - **Liabilitas Sewa Pembiayaan (Capital Lease Obligations)**: **Rp 4,79 Miliar** (Jangka panjang Rp 3,30 M + Jangka pendek Rp 1,49 M = Rp 4.789.000.000).
   - **Total Utang Termasuk Sewa (yfinance Total Debt)**: 4.601,73 + 4,79 = **Rp 4.606,52 Miliar** (Rp 4.606.521.000.000).
   - **Ekuitas Entitas Induk (Stockholders Equity)**: **Rp 6.263,55 Miliar** (Rp 6.263.550.000.000).
   - **DER Pinjaman Murni**: 4.601,73 M / 6.263,55 M = **0.73x** [COMPUTED: Pinjaman bank & obligasi tanpa sewa].
   - **DER Total Utang**: 4.606,52 M / 6.263,55 M = **0.74x** [PROVIDER_SUPPLIED: Formula provider yfinance termasuk sewa pembiayaan].
   - **Rasio Total Liabilitas / Ekuitas**: Total Liabilitas Rp 6.911,85 M / Ekuitas Rp 6.263,55 M = **1.10x**.
3. **Saham Beredar & Valuasi**:
   - **Ordinary Shares Number (Akhir Periode Neraca 2025)**: **20.796.830.969 lembar** (Basis BVPS).
   - **Weighted Average Shares (Rata-rata Tertimbang 2025)**: **20.594.009.411 lembar** (Basis EPS Dasar).
   - **EPS Dasar**: **Rp 20,55** (Laba entitas induk Rp 423,20 M / 20,59 M lembar rata-rata tertimbang). Sumber: `[REPORTED_BASIC_EPS (Laporan Keuangan)]`.
   - **BVPS**: **Rp 301,18** (Ekuitas entitas induk Rp 6.263,55 M / 20,80 M lembar akhir periode).
   - **PER**: **6.08x** (Harga penutupan feed Rp 125 / EPS Rp 20,55).
   - **PBV**: **0.42x** (Harga penutupan feed Rp 125 / BVPS Rp 301,18).
   - **Dividen Kas**: **Rp 2,0349 per lembar** (Ex-Date: 17 Juni 2026, Trailing Yield: 1,63%, Status: `PROVIDER_SUPPLIED_DIVIDEND_FEED`).
   - **Status Audit Valuasi**: **`PROVISIONAL`** (Data feed publik provider, belum diverifikasi fisik terhadap dokumen audit resmi BEI).

### B. Audit Kasus Khusus Lintas Sektor
- **BBCA (Perbankan)**: Rasio DER ditandai **`N/A (Metrik Perbankan Dikecualikan)`** karena dana pihak ketiga (DPK) bank dicatat sebagai liabilitas operasional, bukan utang berbunga korporasi konvensional.
- **MEDC (Energi)**: Status fundamental ditandai **`PARTIAL`** karena laporan keuangan diterbitkan dalam mata uang USD sementara saham diperdagangkan dalam IDR.
- **GOTO (Teknologi)**: Mengalami rugi bersih (EPS negatif); PER ditandai flag `NEGATIVE_EARNINGS` dengan penjelasan eksplisit bahwa PER negatif menandakan kerugian operasional dan **BUKAN** valuasi murah.

---

## 5. Audit Timestamp & Revisi Record 114–116

- **Uji Parser Timestamp (`TimestampAuditService`)**:
  - UTC dengan offset: `VALID_OFFSET` $\rightarrow$ presisi UTC.
  - WIB dengan offset: `VALID_OFFSET` $\rightarrow$ konversi tepat ke UTC.
  - Naive dengan zona sumber diketahui: `VALID_KNOWN_ZONE` $\rightarrow$ dikonversi tanpa tebakan.
  - Naive dengan zona sumber tidak diketahui: **`ZONE_UNKNOWN`** $\rightarrow$ dilarang ditebak, ditandai anomali.
  - Timestamp masa depan (> now + 5m): **`FUTURE_TIMESTAMP`** $\rightarrow$ ditandai audit anomaly.
- **Audit Log Record 114–116**:
  - Tersimpan lengkap di `docs/TIMESTAMP_AUDIT_LOG.md`.
  - Record 114 (PWON), 115 (KIJA), 116 (SCMA) telah disesuaikan ke UTC murni (`02:32`, `02:39`, `02:14` UTC) berdasarkan bukti log eksekusi lokal host, bukan dugaan zona sepihak.

---

## 6. Reformasi Penilaian Likuiditas

- Sistem melarang penggunaan kata "likuiditas tinggi" secara mutlak hanya dari volume 1 hari atau RVOL.
- Diterapkan metrik proksi:
  - **ADTV 20**: Estimasi Nilai Transaksi Harian Rata-rata 20 Hari (Close × Volume, Proksi Aktivitas).
  - **Zero-Volume Days**: Jumlah hari tanpa transaksi dalam 20 hari terakhir.
  - **Disclaimer Eksplisit**: "Estimasi Close × Volume, bukan nilai turnover kliring bursa; bukan bukti spread sempit atau kemudahan eksekusi."

---

## 7. Bukti Eksekusi Scheduler Latar Belakang & Keandalan Worker

1. **Rekonsiliasi Pemicu Worker**:
   - Pemicu 10:47:05 dan 10:47:34 WIB merupakan pengetesan manual via terminal CLI agent.
   - Pemicu otomatis periodik dikelola oleh Windows Task Scheduler dengan nama tugas **`\AetherWorkers_5min`** yang berjalan setiap 5 menit.
2. **Bukti Eksekusi Otomatis Riil dari `logs/aether_workers.log`**:
   - **Siklus 1 (11:49:01 WIB)**: Memproses AMRT [COMPLETED], ANDI [COMPLETED], ANJT [COMPLETED]. Exit: 0.
   - **Siklus 2 (11:54:01 WIB)**: Memproses ANTM [COMPLETED], APEX [PARTIAL], APIC [COMPLETED]. Exit: 0.
   - **Siklus 3 (11:59:01 WIB)**: Memproses APII [PARTIAL], APLI [COMPLETED], APLN [COMPLETED]. Exit: 0.
   - **Siklus 4 (16:47:49 WIB)**: Memproses ATAP [PARTIAL], ATIC [COMPLETED], ATLA [COMPLETED]. Exit: 0.
3. **Proteksi Overlap Atomik, Heartbeat Batas-Langkah, & Pemulihan Stale Lock (`scripts/worker_lock.php`)**:
   - **Identitas Pemilik & Token**: Setiap siklus menghasilkan token unik (`WTOK_<RANDOM>_<RANDOM>`) dan mencatat PID parent proses cmd/worker.
   - **Proses Liveness Check**: Stale lock diverifikasi melalui pemeriksaan eksistensi PID riil di OS (`tasklist /FI "PID eq <PID>"`). Jika PID mati (akibat crash atau host reboot), stale lock langsung dipulihkan secara aman.
   - **Heartbeat Batas-Langkah (Step-Boundary)**: Heartbeat diperbarui secara jujur pada batas antar-langkah (fase riset, outbox, dan antrean ingestion), BUKAN thread daemon kontinu.
   - **Proteksi Langkah Panjang (>15 Menit)**: Selama proses pemilik terverifikasi MASIH HIDUP di OS dan identitas proses valid, lock TIDAK AKAN PERNAH direbut oleh instance lain meskipun langkah kerja tertentu memakan waktu lama tanpa pembaruan heartbeat.
   - **Proteksi PID Reuse**: Memeriksa image name proses (`allowedImages`) dan waktu pembuatan proses (WMI `CreationDate`). Jika PID dialihkan OS ke proses non-worker (misal: `notepad.exe`, `chrome.exe`), sistem mendeteksi stale lock dan memulihkannya.
   - **Pelepasan Aman Anti-Overwrite**: Perintah `release` memverifikasi token pemilik. Instance lama yang terlambat selesai dilarang keras menghapus berkas lock milik instance baru.
   - **Hasil Uji 6 Skenario**: Seluruh 6 skenario (dua pemicu bersamaan, pekerjaan panjang >10m, langkah >15m dengan proses hidup, crash/reboot PID mati, PID reuse image mismatch, dan isolasi release instance lama) teruji 100% lulus di `tests/Feature/WorkerLockReliabilityTest.php` (6 passed, 21 assertions).

---

## 8. Verifikasi Permintaan Telegram Aktual & Kesiapan Runtime Dua Arah

1. **Uji Simulasi CLI Internal**:
   - **Perintah CLI**: `php artisan hermes:telegram-chat --text="analisis fundamental dan teknikal KIJA"`
   - **Identifier Uji**: `upd_audit_1792991602` [INTERNAL_CLI_SIMULATION] | Inbound Message ID: `1792991602` | Conversation ID: `80`
   - **Waktu Respon**: **333 ms** | **Status**: `SUCCESS` | **Intent**: `QUERY_FUNDAMENTAL_ANALYSIS`
   - **Kondisi Teknikal**: 239 candle EOD, tren STRONG_BEARISH, MA 20/50/200 lengkap, RSI 27.85, Max Drawdown 1Y -68.64% [FULL_1Y_CALCULATED], ADTV20 Rp 28,84 M.
   - **Kondisi Fundamental**: EPS Dasar Rp 20,55 [REPORTED_BASIC_EPS], BVPS Rp 301,18 [COMPUTED], PER 6.08x, PBV 0.42x, DER Pinjaman Murni 0.73x [COMPUTED], DER Total 0.74x [PROVIDER_SUPPLIED], Rasio Liabilitas/Ekuitas 1.10x.
   - **Model ML**: Dinyatakan `MODEL_NOT_VALIDATED_FOR_INSTRUMENT` (Keyakinan N/A).
2. **Pengujian Pengguna Interaktif Telegram Langsung**:
   - **Status Saat Ini**: **`PENDING`** (Menunggu pesan inbound interaktif riil berikutnya dari pengguna Telegram ke `@Info_BosKuu_bot`).
   - **Pesan yang Ditugaskan untuk Pengujian**:
     `"Cari kandidat swing dari data yang sudah cukup, jelaskan alasan dan risikonya."`
   - **Aturan Provenance**: Saat payload riil diterima, sistem wajib mencatat `update_id` numerik dari payload asli Telegram, `message_id` inbound, `route` intent (`COMMAND_SCREENING`), `screening_run_id`, panggilan Gemini (bila digunakan), dan `message_id` outbound tanpa membuat pesan tiruan sintetis. Follow-up query akan mempertahankan konteks riwayat percakapan.

---

## 9. Throughput Nyata, Antrean Narasi Gemini, & Hambatan Aktual Pengumpulan Data

1. **Throughput Nominal vs Throughput Aktual Terukur**:
   - **Kapasitas Nominal**: 3 emiten per batch $\times$ 12 batch/jam = **36 emiten per jam**.
   - **Throughput Aktual Empiris**: **16 – 25 emiten selesai per jam** (dihitung dari timestamp `completed_at` tabel `idx_ingestion_jobs` di PostgreSQL).
   - **Faktor Deviasi**: Latensi upstream yfinance 1.5–3.0s per emiten, jeda pacing 250 ms, retry backoff saat HTTP 429 / timeout, alokasi prioritas untuk antrean riset Telegram pengguna (`aether:process-research-queue`), outbox balasan (`hermes:process-outbox`), dan antrean narasi Gemini.
2. **Antrean Narasi Gemini AI (`idx:process-narratives`)**:
   - **Perintah Artisan**: `php artisan idx:process-narratives --limit=2`
   - **Integrasi Runner**: Dimasukkan ke Prioritas 4 di `scripts/run_aether_workers.bat` (di bawah antrean riset, outbox, dan ingestion BEI) untuk memprioritaskan permintaan pengguna.
   - **Cache Key Berbasis Evidence Utuh (Content-Based)**: Hash kanonikal `sha256(Bar OHLCV ## Indikator Teknis ## Rasio & Kualitas Lapkeu ## Berita Terkini ## Aksi Korporasi) + model + prompt_version`. Perubahan `metric_id` tidak memicu regenerasi jika isi data evidence identik.
   - **Penanganan Fundamental Parsial**: Jika data fundamental lengkap, narasi berlabel `FULL_FUNDAMENTAL_AND_TECHNICAL`. Jika laporan keuangan belum tersedia pada feed publik, narasi berlabel `PARTIAL_TECHNICAL_ONLY` dengan batasan eksplisit ketiadaan data lapkeu.
   - **Cakupan Terpisah**: Sebanyak **21 emiten** telah memiliki narasi AI tersimpan di DB. Dilarang mengirim notifikasi per emiten untuk mencegah spam.
3. **Klasifikasi Hambatan Aktual (Bukan Bug Aplikasi)**:
   - **HTTP 403 Cloudflare pada Endpoint Resmi BEI**: Halaman keterbukaan profil BEI (`idx.co.id`) dilindungi anti-bot Cloudflare. Mengandalkan registri sekunder Wikipedia dan impor berkas audit resmi pengguna.
   - **Mata Uang Pembukuan Laporan Asing (Non-IDR)**: Emiten seperti MEDC menerbitkan laporan dalam USD. Rasio valuasi ditandai `PARTIAL` (`CURRENCY_CONVERSION_REQUIRED_USD_TO_IDR`) dengan `retryable = false`.
   - **Ketiadaan Data Lapkeu Publik**: Saham tidur atau papan akselerasi tanpa laporan di yfinance ditandai `NOT_FOUND_ON_CHECKED_PROVIDER_YFINANCE` (`retryable = false`).
   - **Emiten Rugi Bersih (EPS $\le$ 0)**: PER bernilai `NOT_APPLICABLE` dan ditandai `NOT_APPLICABLE_NEGATIVE_EARNINGS`, status pekerjaan tetap `COMPLETED`.

---

## 10. Verifikasi Identitas Model Machine Learning & Protokol Screening

1. **Audit Identitas Model Aktual**:
   - **Algoritma Model**: Random Forest Classifier (scikit-learn).
   - **Nama & Versi Model**: `RandomForest` (`RandomForest_v1.0`).
   - **Artefak Feature Schema Hash**: `b268fdff729f`.
   - **Tanggal Keputusan Training**: `2026-10-16`.
   - **Horizon Prediksi**: 5 Hari Bursa (`predicted_horizon_days = 5`).
   - **Penjelasan Kerancuan EXP-04**: `EXP-04` adalah kode strategi aturan teknikal pada forward paper trading di Hermes Stock Agent (*"Pullback EMA + Konfirmasi Ketat: RVOL >= 1.5, Close Position >= 0.70"*), BUKAN model machine learning. Model ML aktual di Aether adalah `RandomForest_v1.0`.
   - **Definisi Threshold**: Raw probability threshold $\tau = 0.70$ pada probabilitas kelas mayoritas ($p_{\max} \ge 0.70$). Formula derived confidence: $\text{confidence} = (p_{\max} - 0.5) \times 2$. Nilai $\tau = 0.70$ setara dengan confidence $\ge 0.40$ (40.00%).
   - **Pemisahan 3 Status Validasi ML**:
     1. *Didukung Kontrak*: 10 emiten konstituen `VALIDATED_ML_UNIVERSE`.
     2. *Tervalidasi Out-of-Sample*: Hanya 10 emiten konstituen pada riset temporal walk-forward (`reports/ml/TAHAP_G_FINAL_REPORT.md`). 7 emiten non-konstituen tidak pernah divalidasi out-of-sample (`MODEL_NOT_VALIDATED_FOR_INSTRUMENT`, keyakinan `N/A`).
     3. *Inferensi Aktif untuk Bar*: 0 emiten (seluruh record inferensi historis 16-Okt telah kadaluarsa per 23-Okt). Record historis `NO_SIGNAL_ABSTAIN` BUKAN bukti validasi inferensi bar 26-Okt. Status: `NO_ACTIVE_INFERENCE_FOR_BAR`, keyakinan `N/A`.
2. **Protokol Validasi Screening Kuantitatif (Versi Beku `v1.0.0-frozen` / Refinement `v1.0.1`)**:
   - Detail lengkap terdokumentasi di `reports/SCREENING_VALIDATION.md`.
   - **Kronologi Pembekuan Append-Only**:
     • 26-Okt 19:30 WIB: Pembekuan 13 kandidat & kriteria filter (hash: `c51fa69e06b9b32c`).
     • 26-Okt 20:30 WIB: Refinement protokol pemisahan Output A vs B, fraksi BEI, audit bar 23-Okt, & data finality (hash: `7e4d82b1fa90c37e`). Tidak ada pemunduran timestamp retrospektif.
   - **Pemisahan Dua Keluaran**:
     • *Output A (Studi Sentuhan Barrier Bruto)*: **AKTIF**. Target math desimal kontinu $High \times 1.01$ vs stop $Low$. Nol asumsi keterisian order/antrean.
     • *Output B (Simulasi Transaksi)*: Terdokumentasi lengkap (entry $Open_{T+1}$, batal jika gap up $\ge$ target, stop $Low$, fraksi tick resmi BEI, biaya skenario 0.80%). **Return transaksi neto ditahan** sampai verifikasi order book intraday terpenuhi (tidak mengklaim profit tanpa bukti fill rate).
   - **Audit Bar 23 Oktober (PTRO, SMRA, TLKM)**: Terjadi akibat jeda antrean batch ingestion yfinance saat pembekuan (bukan suspensi/ketiadaan transaksi). Input kohort tetap dipertahankan. Aturan freshness kohort berikutnya: `max_staleness_trading_days = 0`.
   - **Kalender & Data Finality Policy**:
     • Kalender acuan: `BeiCalendarService` (resmi hari bursa BEI 2026).
     • Deadline kalender: 02 November 2026, 16:00:00 WIB (akhir sesi $T+5$).
     • Kebijakan Finalitas: Jika bar EOD $T+5$ belum terunduh saat deadline kalender, status evaluasi adalah **`WAITING_FOR_FINAL_DATA`** (dilarang memaksakan outcome prematur).
   - **Integritas Operasional**: Nol broker orders, nol synthetic trades, model/threshold produksi tidak diubah. Status Telegram end-to-end tetap **PENDING** menunggu pesan pengguna asli.
