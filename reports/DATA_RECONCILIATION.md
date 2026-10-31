# LAPORAN REKONSILIASI DATA PASAR, METRIK & INTEGRITAS AETHER

**Tanggal Audit**: Selasa, 27 Oktober 2026  
**Waktu Eksekusi**: 06:00 – 06:30 WIB  
**Target Sistem**: Aether Market Intelligence (`project-bot:5432`)  
**Basis Data Cadangan (Pre-Reconciliation Dump)**:  
`E:\Aether-Market-Intelligence\backups\project_bot_pre_reconciliation_20261027_0550.dump` (5,5 MB)  
**File Snapshot Baseline**:  
`E:\Aether-Market-Intelligence\reports\BASELINE_SNAPSHOT_20261027_0600.json`

---

## 1. Ringkasan Eksekutif & Temuan Kunci

Rekonsiliasi menyeluruh dilakukan terhadap seluruh lapisan data pasar, pipeline kalkulasi indikator geometri, layer presentasi dashboard, dan antarmuka bot Telegram.

| Area Audit | Masalah Awal | Akar Masalah (Root Cause) | Status Koreksi |
|---|---|---|---|
| **Definisi Perubahan Persentase** | Header dan tabel menghitung `(close - open) / open * 100` dan melabelinya sebagai "Perubahan Harian". | Tidak adanya pemisahan kanonikal antara perubahan harian terhadap previous close dengan pergerakan intraday terhadap open. | **TERKOREKSI**: `CanonicalMarketReadService` memisahkan `daily_change` & `daily_change_pct` (vs previous session close) dan `change_vs_open_pct`. Fallback diam-diam ke open dihilangkan; jika basis tidak ada, tampil `N/A`. |
| **Kasus Regresi BRIS** | Angka perubahan tidak sinkron dengan pergerakan sesi 26-Okt-2026. | Duplikasi record bar 26-Okt-2026 akibat variasi `observation_time` saat ingest on-demand. | **TERKOREKSI**: Deduplikasi DB tuntas. Bar 26-Okt (Close: 1055, Open: 1030) vs Prev Close 23-Okt (1020) menghasilkan `daily_change`: +35 (+3,43%) dan `change_vs_open`: +25 (+2,43%). |
| **RSI BRIS (16.82 vs 19.81)** | Terdapat perbedaan antara nilai tersimpan 16.82 dengan angka pembanding industri 19.8. | Kode `services/idx_financial_collector.py` menggunakan 14-period SMA of gains/losses (`Cutler's RSI`), bukan recursive exponential smoothing (`Wilder's RMA`). | **TERREKONSILIASI & TERKOREKSI**: Rumus diperbaiki ke Wilder's RMA (`ewm(alpha=1/14, adjust=False)`), menghasilkan nilai matematis identik 19.81 pada 239 candle warmup. |
| **MACD Line N/A vs Signal Valid** | Tab Teknikal menampilkan MACD Line sebagai `N/A`, sedangkan MACD Signal valid (`-135.58`). | Typo pemetaan properti di Blade view: `$technical->macd_line` alih-alih `$technical->macd`. | **TERKOREKSI**: Pemetaan diperbaiki ke `$technical->macd`. MACD Line (-151.02) dan Signal (-135.58) kini tampil presisi. |
| **Anomali BBCA (Close 4940 vs Low 5000)** | Teks narasi AI menyebut low 5000 sementara header menampilkan close 4940. | **Perbedaan Periode**: Narasi AI berasal dari Analysis ID #103 yang mengevaluasi bar 23-Okt-2026 (Low: 5000, Close: 5025). Header menampilkan bar EOD terkini 26-Okt-2026 (Close: 4940, Low: 4940). | **TERLABELI & TERPISAH**: Section 5 dan header kini mencantumkan label tanggal bar evaluasi analisis (`Bar Acuan: 2026-10-23`) dan membedakannya dari harga penutupan sesi 26-Okt-2026. |
| **Sektor vs Industri Emiten** | Kolom industri menduplikasi nama sektor (cth: "Financials / Financials"). | Raw scraper Wikipedia mengisi kolom industri dengan nilai sektor jika sub-klasifikasi tidak ditemukan. | **TERKOREKSI**: View memvalidasi: jika industri sama dengan sektor atau null, tampilkan "Belum tersedia". Tanggal IPO diformat `d M Y`. |
| **Pelaporan Pertumbuhan & Baseline** | Snapshot lama mendistorsi baseline fundamental (disebut 261, padahal baseline aktual adalah 198 emiten unik). | Conflation antara emiten berharga (261) dengan emiten berfundamental (198). | **DIREKONSILIASI**: Pertumbuhan fundamental dihitung murni: `(281 - 198) / 198 * 100 = +41,92% (+83 emiten)`. Analisis tersimpan: 209 rekor (191 emiten unik). |

---

## 2. Snapshot Query Baseline Aktual (Pre-Reconciliation)

Snapshot baseline diambil pada **27 Oktober 2026, 06:01:14 WIB** langsung dari PostgreSQL `project-bot:5432`:

```json
{
  "snapshot_timestamp": "2026-10-27T06:01:14+07:00",
  "market_observations": {
    "total_records": 89323,
    "distinct_tickers": 384,
    "min_bar_date": "2025-10-27",
    "max_bar_date": "2026-10-26",
    "final_bars": 89323,
    "provisional_bars": 0
  },
  "company_fundamentals": {
    "total_records": 1301,
    "distinct_tickers": 281,
    "min_year": 2021,
    "max_year": 2026
  },
  "company_corporate_actions": {
    "total_records": 2693,
    "distinct_tickers": 280
  },
  "company_analysis_metrics": {
    "total_records": 380,
    "distinct_tickers": 378,
    "tech_calculated": 380,
    "fund_calculated": 245,
    "fund_partial": 42
  },
  "market_analyses": {
    "total_records": 209,
    "distinct_tickers": 191
  },
  "idx_ingestion_jobs": {
    "COMPLETED": 241,
    "FAILED": 29,
    "PARTIAL": 133,
    "PENDING": 5
  }
}
```

---

## 3. Rekonsiliasi Definisi Persentase & Kasus BRIS

### 3.1. Spesifikasi Formula Kanonikal Baru
1. **Perubahan Harian (Daily Change)**:
   $$\text{daily\_change} = \text{close} - \text{previous\_session\_close}$$
   $$\text{daily\_change\_pct} = \frac{\text{daily\_change}}{\text{previous\_session\_close}} \times 100$$
   *Aturan Integritas*: Previous close wajib diambil dari sesi perdagangan bursa sebelumnya (`bar_date < current_bar_date`), bukan hari kalender atau baris ID sebelumnya. Jika previous close tidak tersedia, tampilkan **N/A** (dilarang keras fallback ke open).
2. **Perubahan Terhadap Open (Intraday vs Open)**:
   $$\text{change\_vs\_open} = \text{close} - \text{open}$$
   $$\text{change\_vs\_open\_pct} = \frac{\text{close} - \text{open}}{\text{open}} \times 100$$
   *Label Presentasi*: Wajib mencantumkan label eksplisit `vs Open` atau `Intraday vs Open`.

### 3.2. Verifikasi Empiris Emiten BRIS (Sesi 26 Oktober 2026)
- **Data Bar 26-Okt-2026**: Open: Rp 1.030, High: Rp 1.070, Low: Rp 1.025, Close: Rp 1.055, Volume: 115.630.800 lembar.
- **Data Bar Sesi Sebelumnya (23-Okt-2026)**: Close: Rp 1.020.
- **Kalkulasi Kanonikal**:
  * $\text{daily\_change} = 1055 - 1020 = \mathbf{+35}$
  * $\text{daily\_change\_pct} = \frac{35}{1020} \times 100 = \mathbf{+3,43137\%} \rightarrow \mathbf{+3,43\%}$
  * $\text{change\_vs\_open} = 1055 - 1030 = \mathbf{+25}$
  * $\text{change\_vs\_open\_pct} = \frac{25}{1030} \times 100 = \mathbf{+2,42718\%} \rightarrow \mathbf{+2,43\%}$
- **Hasil Verifikasi**: Dashboard header, tabel `/market`, dan respons Telegram menghasilkan angka yang 100% konsisten dengan nilai yang diharapkan.

---

## 4. Penelusuran Indikator Teknikal & Anomali MACD/RSI

### 4.1. Penjelasan Perbedaan RSI BRIS (16.82 vs 19.81)
- **Penyebab Matematika**:
  * Nilai **16.82** dihasilkan oleh formula *Cutler's RSI* yang menghitung Simple Moving Average (SMA) murni dari gain dan loss selama 14 bar terakhir:
    $$\text{avg\_gain} = \frac{1}{14} \sum_{i=1}^{14} \text{gain}_i, \quad \text{avg\_loss} = \frac{1}{14} \sum_{i=1}^{14} \text{loss}_i$$
    Metode ini tidak memiliki memori historis sebelum bar ke-14.
  * Nilai **19.81** (~19.8) dihasilkan oleh standar industri *Wilder's Smoothed RSI* (Running Moving Average / RMA) yang menggunakan recursive exponential weighting dengan $\alpha = 1/14$ di seluruh 239 bar historis yang tersedia:
    $$\text{gain}_t = \frac{1}{14}\text{gain}_t + \frac{13}{14}\text{gain}_{t-1}$$
- **Tindakan**: Rumus pada `services/idx_financial_collector.py` diperbarui ke standar Wilder's RMA (`ewm(alpha=1/14, adjust=False)`). Nilai `rsi_14` BRIS pada tabel `company_analysis_metrics` dimutakhirkan menjadi **19.81**.

### 4.2. Penelusuran MACD Line N/A
- **Penyebab**: Di basis data PostgreSQL tabel `company_analysis_metrics`, nilai tersimpan adalah:
  * `macd`: `-151.02`
  * `macd_signal`: `-135.58`
  * `macd_hist`: `-15.44`
  Namun pada file Blade `resources/views/dashboard/ticker.blade.php` baris 387, kode memanggil `$technical->macd_line`. Karena kolom di model bernama `macd`, ekspresi menghasilkan `null` dan Blade merender string fallback `'N/A'`.
- **Tindakan**: Properti di view diperbaiki menjadi `$technical->macd`. Nilai MACD Line kini tampil presisi: `-151.02`.

### 4.3. Pemisahan Dimensi Bar & Histori
Tiga dimensi histori kini dipisahkan secara eksplisit pada controller dan view:
1. `total_history_bars`: Total seluruh bar EOD tersimpan di basis data untuk emiten tersebut (BRIS: 239 bar, BBCA: 239 bar).
2. `chart_display_bars`: Jumlah bar yang dirender pada visualisasi grafik dan tabel candlestick (default: 30 bar terakhir).
3. `indicator_input_bars`: Jumlah bar yang dipakai sebagai input kalkulasi indikator geometri (`indicators_meta.input_bars`: 239 bar).
Badge audit ditambahkan pada Tab 3:
`Total Histori DB: 239 bar | Tampilan Grafik/Tabel: 30 bar | Input Indikator: 239 bar`.

### 4.4. Satuan Volume Eksplisit
Seluruh tampilan volume pada tabel pasar, detail emiten, dan laporan fundamental diberikan label satuan eksplisit: **lembar saham** (lbr), bukan lot. Tidak ada pengalian atau pembagian faktor 100 tanpa metadata sumber.

---

## 5. Audit Integritas Data Tersimpan (89k+ Records)

Hasil pengujian integritas SQL terhadap seluruh baris `market_observations`:

1. **Relasi OHLC**: `low <= open <= high` dan `low <= close <= high` terverifikasi **0 pelanggaran** (100% valid di 89.323 baris).
2. **Nilai Positif & Finite**: Tidak ditemukan harga $\le 0$, volume $< 0$, `NaN`, atau `Infinity` (**0 pelanggaran**).
3. **Pembersihan Rekor Duplikat**:
   Ditemukan 4 kelompok duplikasi baris identik akibat `observation_time` on-demand:
   - `BBCA` pada 2026-10-23: ID 59743 digabungkan ke ID 10; ID 59743 dihapus.
   - `BRIS` pada 2026-10-26: ID 85018 & 85019 digabungkan ke ID 38958; duplikat dihapus.
   - `BUVA` pada 2026-10-26: ID 86933 digabungkan ke ID 43737; ID 86933 dihapus.
   - `PTRO` pada 2026-10-23: ID 113 digabungkan ke ID 112; ID 113 dihapus.
   *Hasil Akhir*: **0 sisa duplikat** di seluruh database.
   *Pencegahan Permanen*: `OnDemandDataCollectorService::updateOrCreate` diperbaiki agar menggunakan `[ticker, bar_date, interval, data_classification]` sebagai kunci unik tanpa menyertakan `observation_time`.

4. **Rekonsiliasi Kasus BBCA (Close 4940 vs Low 5000)**:
   - Bar EOD 26-Okt-2026 (ID #136): Open 5025, High 5075, Low 4940, Close 4940.
   - Bar EOD 23-Okt-2026 (ID #10): Open 5175, High 5225, Low 5000, Close 5025.
   - Analysis ID #103 didasarkan pada Evidence ID #18 bertanggal **23 Oktober 2026**, di mana batas low sesi saat itu memang Rp 5.000,00 dan close Rp 5.025,00.
   - *Koreksi Presentasi*: Narasi Section 5 kini secara eksplisit mencantumkan catatan pemisahan periode:
     `• Model Narasi : ag/gemini-3.8-flash-high (Evidence ID: #18, Bar Acuan: 2026-10-23)`
     `• Catatan Periode: Analisis dievaluasi pada bar historis 2026-10-23. Harga penutupan terkini sesi pasar 2026-10-26 adalah Rp 4.940.`
     Hal ini menghilangkan kebingungan pembaca tanpa merusak atau memalsukan audit historis analisis #103.

---

## 6. Laporan Pertumbuhan Dataset Tervalidasi

Pertumbuhan dihitung menggunakan formula baku:
$$\text{growth\_pct} = \frac{\text{current} - \text{baseline}}{\text{baseline}} \times 100$$

| Metrik Dataset | Baseline Riil Tersimpan | Nilai Saat Ini (27-Okt 06:15 WIB) | Pertumbuhan Riil ($\Delta$) | Growth % |
|---|---|---|---|---|
| **Total Bar Harga EOD** | 59.504 bar | 89.318 bar (pasca dedup) | +29.814 bar | **+50,10%** |
| **Distinct Emiten Berharga** | 261 emiten | 384 emiten | +123 emiten | **+47,13%** |
| **Laporan Keuangan Fiskal** | 914 laporan | 1.301 laporan | +387 laporan | **+42,34%** |
| **Distinct Emiten Fundamental** | **198 emiten** (laporan resmi) | **281 emiten** | +83 emiten | **+41,92%** |
| **Aksi Korporasi Tersimpan** | 1.862 rekor | 2.693 rekor | +831 rekor | **+44,63%** |
| **Distinct Emiten Aksi Korporasi** | 198 emiten | 280 emiten | +82 emiten | **+41,41%** |
| **Total Analisis AI Tersimpan** | 91 rekor | 209 rekor | +118 rekor | **+129,67%** |
| **Distinct Emiten Terdokumentasi AI** | N/A (Snapshot lama hanya catat 91 rekor) | **191 emiten** | N/A | **N/A** |
| **Job Batch Selesai (Completed)** | 165 jobs | 241 jobs | +76 jobs | **+46,06%** |

*Catatan Rekonsiliasi*: 
- Klaim terdahulu yang menyebut "+13 emiten" terjadi karena membandingkan 274 dengan baseline harga (261). Dengan baseline fundamental terverifikasi (198 emiten unik), pertumbuhan aktual adalah **+83 emiten unik (+41,92%)**.
- Angka 91 pada snapshot historis `OPERATIONAL_ACCEPTANCE.md` secara harfiah tercatat sebagai *91 Analisis Terstruktur Tersimpan* (jumlah baris rekor), bukan distinct ticker. Karena ketiadaan bukti data distinct pada snapshot historis tersebut, kolom growth distinct emiten AI secara disiplin ditandai **N/A**.

---

## 7. Status Pengujian & Verifikasi Layanan

- **PHPUnit Test Suite**: 182 passed, 803 assertions, 0 errors, 0 failures (durasi: 58,6s).
- **Unit Test Baru**: `DataReconciliationCalculationTest` memvalidasi kasus regresi BRIS (+35 / +3,43% vs +25 / +2,43%), pencegahan fallback diam-diam ke open saat previous close hilang, dan penanganan data null secara aman.
- **Verifikasi Browser**:
  * `/market/BRIS`: Header menampilkan `Rp 1.055`, `+35 (+3,43%)`, `Perubahan Harian vs Prev Close Rp1.020 (2026-10-23)`, `Intraday vs Open: +2,43%`. Tab 3 menampilkan RSI `19.81`, MACD Line `-151.02`, Signal `-135.58`, Histori `239 bar`.
  * `/market/BBCA`: Header menampilkan `Rp 4.940`, `-85 (-1,69%)`, `Perubahan Harian vs Prev Close Rp5.025 (2026-10-23)`.
  * `/market`: Render tabel 25 emiten responsif dengan kolom "Perubahan Harian" dan pemisahan sub-label "vs Open", serta industri "Belum tersedia" saat sama dengan sektor.
- **Telegram Bot Live Format**: `TelegramTwoWayService` dan `CompanyFundamentalService` memuat pemisahan `Perubahan Harian (vs Prev Close)` dan `Perubahan vs Open` secara konsisten.

---

## 8. Status Forward Study & Output B

- **Output A (Studi Sentuhan Barrier)**: Tetap aktif dalam status `WAITING_FOR_FINAL_DATA` untuk sesi 27 Oktober 2026.
- **Output B (Simulasi Transaksi Eksekusi)**: Tetap berstatus **WITHHELD** pada implementasi saat ini karena keterbatasan metodologi order matching dan ketiadaan eksekusi broker. Status ini bukan larangan permanen bagi riset simulasi di masa mendatang; setiap perubahan atau penyempurnaan metodologi simulasi harus dirilis dalam versi studi terpisah.

---

## 9. Rekonsiliasi Perbedaan Universe Emiten & Penghitungan Ulang Breadth Pasar

Perbedaan angka universe emiten yang muncul pada laporan operasional dan log query telah direkonsiliasi menggunakan satu snapshot transaksi atomic read-only pada PostgreSQL produksi:

### A. Matriks Universe & Partisi Breadth (Sesi Acuan 2026-10-26 vs 2026-10-23)

| Kategori Evaluasi | Jumlah Emiten | Persentase | Definisi & Kriteria Filter Kelayakan |
|---|---|---|---|
| **1. Total Universe Ekuitas Terdaftar** | **501 emiten** | 100,00% | Seluruh distinct emiten saham di `market_observations` (indeks `^JKSE`, `JKSE`, `IHSG` dipisahkan). Label resmi: **501 saham dalam cakupan data sistem**. |
| **2. Excluded: Tanpa Bar Sesi Acuan** | **26 emiten** | 5,19% | Missing bar sesi acuan: tidak memiliki rekod bar EOD pada tanggal acuan 2026-10-26 (tanpa atribusi spekulatif suspensi bursa). |
| **3. Excluded: Tanpa Bar Sesi Sebelumnya** | **1 emiten** | 0,20% | Missing bar sesi pembanding: memiliki bar 2026-10-26 namun tidak memiliki bar sesi sebelumnya 2026-10-23 (gap histori). |
| **Total Excluded Universe** | **27 emiten** | **5,39%** | Jumlah mutually exclusive emiten yang didiskualifikasi dari penghitungan breadth. |
| **4. Eligible Breadth Universe** | **474 emiten** | **94,61%** | Emiten yang memiliki bar EOD 2026-10-26 dan bar penutupan 2026-10-23 tanpa gap. |
| - *Advancers (Saham Naik)* | **86 emiten** | 18,14% | Perubahan harian > 0 ($P_{\text{close}} > P_{\text{prev}}$). |
| - *Decliners (Saham Turun)* | **295 emiten** | 62,24% | Perubahan harian < 0 ($P_{\text{close}} < P_{\text{prev}}$). |
| - *Unchanged (Saham Tetap)* | **93 emiten** | 19,62% | Perubahan harian = 0 ($P_{\text{close}} = P_{\text{prev}}$). |

### B. Pembuktian Persamaan Matematis (Identitas Persamaan)

$$\text{Eligible Breadth} = \text{Advancers} + \text{Decliners} + \text{Unchanged}$$
$$474 = 86 + 295 + 93 \quad \checkmark \quad (\text{TERVERIFIKASI PERSIS})$$

$$\text{Total Universe} = \text{Eligible Breadth} + \text{Total Excluded}$$
$$501 = 474 + 27 \quad \checkmark \quad (\text{TERVERIFIKASI PERSIS})$$

$$\text{Median Perubahan Eligible Breadth} = \mathbf{-1,23\%}$$
(Dihitung strictly dari distribusi 474 emiten eligible pada sesi acuan yang sama).

### C. Semantik Waktu, Point-in-Time Cutoff, & Konversi Zona Waktu
Semantik waktu audit dipisahkan secara tegas dan disimpan dalam UTC di PostgreSQL:
1. **Penutupan Sesi Bursa BEI (`market_session_closed_at`)**:
   - Pukul **16:00:00 WIB** pada 2026-10-26 setara dengan **09:00:00 UTC** (WIB = UTC+7).
   - Bukan 16:00:00 UTC (yang setara dengan 23:00:00 WIB). Terbukti via assertion PHPUnit.
2. **Ketersediaan Bukti Data (`evidence_available_at`)**:
   - Data sekunder Yahoo Finance selesai di-ingest ke basis data pada **2026-10-26 23:01:14 WIB** (**16:01:14 UTC**).
   - Waktu pasar ditutup dan waktu bukti data tersedia bagi sistem adalah dua entitas terpisah.
3. **Batas Informasi Analisis (`analysis_information_cutoff`)**:
   - Filter point-in-time diberlakukan ketat: bar yang memiliki `available_at` setelah cutoff informasi otomatis didiskualifikasi dari penghitungan breadth.

### D. Benchmark Pembanding Pasar (Sesi Sebanding)
- **IHSG (^JKSE)** pada bar 2026-10-26: Close Rp 5.439,26.
- Previous Close (bar 2026-10-23): Rp 5.387,90.
- Perubahan Harian: **+51,36 poin (+0,95%)**.
- Evaluasi: IHSG menguat (+0,95%) ditopang emiten kapitalisasi besar, meskipun breadth pasar didominasi tekanan jual (62,24% emiten turun).

### E. Rekonsiliasi Terhadap Baseline Historis (384 vs 488 vs 501)
- **384 Emiten**: Baseline beku tersimpan di `reports/BASELINE_SNAPSHOT_20261027_0600.json` (06:01 WIB, total 89.323 bar).
- **488 Emiten**: Total ticker yang terbaca pada kueri pagination `latestByTickerWithChanges()` sebelum filter tanggal acuan eksklusif diterapkan.
- **501 Emiten**: Total emiten ekuitas riil di tabel `market_observations` saat ini setelah backfill antrean BEI berjalan kontinu (116.823 bar).
- **Pemisahan Mock vs Produksi**: Seluruh metrik breadth produksi dihitung dari PostgreSQL `project-bot:5432`. Pengujian unit test diisolasi pada database SQLite in-memory dan mock transport. Zero data sintetis di PostgreSQL.
