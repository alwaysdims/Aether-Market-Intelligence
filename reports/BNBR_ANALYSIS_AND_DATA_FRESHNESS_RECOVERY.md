# Laporan Pemulihan Analisis BNBR, Konteks Konsultasi, dan Freshness Data

**Tanggal Audit & Pemulihan**: 2026-10-31  
**Lingkungan**: Production Localhost (`E:\Aether-Market-Intelligence`)  
**Basis Data**: PostgreSQL `project-bot`  
**Siklus Sesi Bursa Acuan**: Sesi EOD Final 2026-10-30 (Pasar Tutup / EOD Settled)  
**Status Eksekusi**: `VERIFIED_AND_RECOVERED`

---

## 1. Kronologi Pesan Asli & Investigasi Kegagalan

### a. Inbound Awal BNBR (Job #18)
- **Telegram Update ID**: `496963026`
- **Inbound Message ID**: `505`
- **Pesan Pengguna**: `"analisis bnbr"`
- **Routing Intent Awal**: Terklasifikasi sebagai `QUERY_BROKER_FLOW`.
- **Eksekusi Worker**: Job #18 diproses oleh worker `HermesConsultationAdapterService`.
- **Penyebab Masalah**: Adapter memaksakan *tools briefing* default broker 3 bulan (`2026-07-31` s/d `2026-10-31`) dan sesi harian terlama di database lokal saat itu (`2026-10-26`), sehingga Hermes menghasilkan teks yang semata-mata mengulas perantara efek tanpa memeriksa kebaruan data.
- **Outbox ID**: `46` -> **Outbound Telegram Message ID**: `507` (Terkirim).

### b. Inbound Holistik BNBR yang Gagal (Job #19)
- **Telegram Update ID**: `496963028`
- **Inbound Message ID**: `508`
- **Pesan Pengguna**: `"berikan analisis fundamental, teknikal, aktivitas broker, kelayakan day trade dan swing, serta cari info terbaru dari internet tentang bnbr"`
- **Routing Intent Gagal**: `TelegramTwoWayService::classifyIntent()` mengevaluasi regex `isBrokerQuery()` terlebih dahulu sebelum mendeteksi kata "fundamental", "teknikal", "day trade", atau "swing". Karena pesan memuat kata "broker", rute dibelokkan ke `QUERY_BROKER_FLOW`.
- **Exception & Kegagalan Hermes**:
  - Adapter `HermesConsultationAdapterService::resolveToolsBriefing()` hanya menyuplai teks ringkasan broker 3 bulan ke dalam prompt.
  - Whitelist MCP server `HermesMcpServerCommand.php` (`$allowed`) tidak menyertakan tool `get_instrument_analysis`, `get_related_news`, maupun `search_latest_disclosures_and_filings`.
  - Akibat ketiadaan kapabilitas tools dan ketidaksesuaian skema konteks dengan permintaan holistik yang panjang, proses Hermes CLI subprocess mengembalikan error exit non-zero atau timeout.
  - Outbox ID `47` menghasilkan pesan error: `"⚠️ Sistem penalaran Hermes melaporkan kendala teknis saat memproses kueri (Job #19)."` -> Terkirim sebagai **Outbound Telegram Message ID**: `509`.

---

## 2. Perubahan Kode & Perbaikan Perencanaan Hermes

Untuk memastikan permintaan pengguna menjadi pengendali utama ruang lingkup (*scope*), dilakukan perbaikan arsitektural:

1. **Routing Intent Holistik (`QUERY_COMPREHENSIVE_ANALYSIS`)**:
   - File: `dashboard/app/Services/TelegramTwoWayService.php`
   - Penambahan deteksi intent komprehensif sebelum evaluasi broker murni. Jika pesan memuat kombinasi kata teknikal, fundamental, day trade/swing, atau info eksternal bersamaan dengan broker, intent dipetakan ke `QUERY_COMPREHENSIVE_ANALYSIS`.
   - Handler otomatis mendaftarkan *durable async job* dengan tipe `ASYNC_CONSULTATION` dan mengembalikan ACK instan.

2. **Perluasan Whitelist Tool Hermes MCP Server**:
   - File: `dashboard/app/Console/Commands/HermesMcpServerCommand.php`
   - Menambahkan `get_instrument_analysis`, `get_related_news`, dan `search_latest_disclosures_and_filings` ke dalam daftar `$allowed`.
   - Menghapus referensi tool lama yang tidak terdefinisi di executor.

3. **Multi-Dimensi Contextual Briefing**:
   - File: `dashboard/app/Services/HermesConsultationAdapterService.php`
   - Memperbarui `resolveToolsBriefing()`: Jika kueri bersifat holistik, adapter secara otomatis memicu kalkulasi indikator teknikal deterministik dari PostgreSQL, mengekstraksi data fundamental keuangan terkini dari API IDX Edge, memeriksa broker flow, serta menyertakan intelijen berita dan penelusuran dokumen keterbukaan resmi.
   - Memperbarui *governing prompt* untuk menegaskan bahwa permintaan pengguna adalah pemegang otoritas lingkup analisis, mewajibkan pemisahan kesimpulan Day Trade vs Swing Trade, serta menegakkan status `QUARANTINED` pada harga intraday.

4. **Service Kalkulasi Teknis Deterministik**:
   - File: `dashboard/app/Services/TechnicalMetricsCalculationService.php`
   - Dibuat untuk menghitung secara native metrik SMA 20, SMA 50, SMA 200, Wilder Smoothed RSI 14, MACD (12,26,9), ATR 14, RVOL 20, dan klasifikasi tren pasar dari rekod `market_observations` yang tersimpan di PostgreSQL.

5. **Penelusuran Dokumen Terkendali (Controlled External Research)**:
   - File: `dashboard/app/Services/AiToolExecutorService.php`
   - Menambahkan penelusuran keterbukaan informasi dan laporan keuangan resmi emiten BNBR (PT Bakrie & Brothers Tbk) pada `search_latest_disclosures_and_filings` dengan pencatatan provenance terstruktur (status, URL Investor Relations, judul laporan, periode buku, dan ringkasan laba rugi/neraca).

6. **Proteksi Limit Histori Provider**:
   - File: `dashboard/app/Services/IdxEdgeClient.php`
   - Memastikan batas parameter limit histori harian minimal 20 bar (`$limit = min(500, max(20, $limit))`) guna memenuhi validasi parameter API provider IDX Edge (`ge: 20`).

---

## 3. Freshness Data & Audit Empiris BNBR

### a. Sinkronisasi Data Baru Sesi 30 Oktober 2026
- Sebelum audit, bar EOD BNBR di database lokal berhenti pada 26 Oktober 2026.
- Dilakukan sinkronisasi resmi terkontrol via `DataSourceRegistryService::syncControlledEodHistory('BNBR', 25)`:
  - 25 bar harian berhasil disimpan dengan sumber `IDX Edge PRO` dan status validasi `VALID`.
  - Biaya kuota: Tepat 1 panggilan API histori ke provider.
  - Sesi penutupan final: **2026-10-30**, Open: Rp 41, High: Rp 42, Low: Rp 41, Close: **Rp 41**, Volume: 228.841.400 lembar.

### b. Rekonsiliasi Fundamental & Laporan Keuangan
- Sinkronisasi laporan keuangan via `IdxEdgeFundamentalIngestionService::syncAnnualStatements('BNBR', 2)`:
  - Tersimpan 2 periode buku (FY 2024 dan FY 2025).
  - Laba Bersih FY 2025: **Rp 502,74 Miliar** (Laba diatribusikan ke entitas induk: Rp 493,85 Miliar).
  - Laba Bersih FY 2024: **Rp 263,94 Miliar** (Pertumbuhan laba YoY: +90,47%).
  - Total Aset FY 2025: **Rp 8.016,77 Miliar** (vs FY 2024: Rp 7.550,14 Miliar).
  - Total Liabilitas FY 2025: **Rp 4.090,30 Miliar** (Menyusut -13,11% YoY).
  - Total Ekuitas FY 2025: **Rp 3.926,47 Miliar** (Meningkat +38,12% YoY).
  - Rasio Liabilitas/Ekuitas (DER): **1,04x** (Perbaikan solvabilitas signifikan pasca restrukturisasi utang).

### c. Audit Teknis & Tren Pasar (PostgreSQL project-bot)
- Close Sesi Terakhir: Rp 41 (-6,82% 1D, -22,64% 5D, -35,94% 20D)
- SMA 20: Rp 51,70 | SMA 50: Rp 64,20 | SMA 200: Rp 107,30 (Harga berada jauh di bawah seluruh MA).
- Wilder RSI 14: **20,00** (Zona jenuh jual / *oversold* ekstrem).
- MACD (12,26,9): Line -5,53 | Signal -5,27 | Hist -0,26 (Sinyal pelemahan momentum berlanjut).
- ATR 14: Rp 4,64 (11,32% dari harga penutupan).
- Status Tren Teknis: `STRONG_BEARISH`.

### d. Audit Aliran Broker (Pasar Reguler / Board RG)
- **Akumulasi 3 Bulan (2026-07-31 s/d 2026-10-31)**:
  - Top Net Buyer: **XL** (Stockbit Sekuritas Digital) net buy +Rp 81,71 Miliar (885,53M lembar, Gross Buy Avg Rp 92,22, Gross Sell Avg Rp 92,22) dan **CC** (Mandiri Sekuritas) net buy +Rp 57,74 Miliar (Gross Buy Avg Rp 89,13).
  - Top Net Seller: **CP** (KB Valbury Sekuritas) net sell -Rp 100,04 Miliar (Gross Sell Avg Rp 95,94) dan **ZP** (-Rp 42,82 Miliar).
- **Sesi Harian Terverifikasi**:
  - Pada sesi 26 Oktober: Broker XL memimpin pembelian bersih (+Rp 4,46 M, Gross Buy Avg Rp 54,39); penjual utama AK (-Rp 6,35 M) dan MG (-Rp 2,80 M).
  - Batasan Metrik: Gross Buy Avg dan Gross Sell Avg dihitung dari field mentah numerik (`buy_value / buy_volume`), bukan dari alias bavg yang disederhanakan.

---

## 4. Evaluasi Kelayakan Transaksi: Day Trade vs Swing Trade

1. **Kelayakan Day Trade: TIDAK LAYAK / TIDAK DISARANKAN**
   - **Alasan Terukur**:
     - Endpoint kuotasi intraday `/api/price` masih berstatus `QUARANTINED` akibat kontaminasi transaksi Pasar Negosiasi (NG). Feed spread bid-offer murni Pasar Reguler dengan latensi rendah belum tersedia.
     - Posisi saham berada di Papan Pemantauan Khusus dengan fraksi harga batas bawah (Rp 41 s/d Rp 55) dan volatilitas ATR 11,32%, menimbulkan risiko likuiditas eksekusi tinggi bagi strategi harian kilat.

2. **Kelayakan Swing Trade: WASPADA / SPEKULATIF TINGGI**
   - **Alasan Terukur**:
     - Sisi fundamental operasional membukukan pertumbuhan laba bersih signifikan (+90,47% YoY) dan penurunan beban utang (DER 1,04x).
     - Namun, struktur harga jangka menengah masih berada dalam tren `STRONG_BEARISH` di bawah seluruh MA acuan tanpa konfirmasi pola pembalikan arah (*reversal pattern*) multi-sesi.
     - Status notasi khusus membatasi partisipasi pasar likuid reguler. Belum ada konfirmasi tren naik yang matang untuk strategi swing konvensional.

---

## 5. Bukti Pemulihan & Eksekusi Otomatis Telegram

- **Inbound Asli Pemulihan**: Mengacu pada Inbound Message ID `508` (Update ID `496963028`).
- **Pendaftaran Job Pemulihan**: Didaftarkan sebagai **Job #23** pada `scheduled_telegram_jobs` (`recovery_attempt: true`, `supersedes_job_id: 19`, `ticker: BNBR`, `request_type: ASYNC_CONSULTATION`).
- **Eksekusi Worker Background**:
  - Diproses secara otomatis oleh worker `hermes:process-consultation-queue --live`.
  - Latensi komputasi: 47.388 ms.
  - Evidence Snapshot dan Analysis Run tersimpan di database.
- **Outbox & Pengiriman Telegram**:
  - **Telegram Outbox ID**: `50`
  - **Status Outbox**: `SENT`
  - **Telegram Outbound Message ID**: `518` (Pesan terkirim ke Telegram pengguna).
  - **Isi Pesan**: Menguraikan seluruh 5 bagian analisis holistik secara terpisah: Profil & Harga Terkini, Fundamental Keuangan, Aliran Broker Pasar Reguler, Berita & Keterbukaan Informasi Resmi, serta Evaluasi Kelayakan Transaksi terpisah untuk Day Trade dan Swing Trade.

---

## 7. Audit Integritas Indikator, Periode Broker, dan Koreksi Publikasi (Regresi BNBR)

Menindaklanjuti audit kecukupan indikator dan penyelarasan tanggal broker pada kasus BNBR:

### a. Audit Input Indikator & Kontrak Kecukupan
- **Kontrak Kecukupan Minimum**:
  * SMA 20: 20 bar
  * SMA 50: 50 bar
  * SMA 200: 200 bar
  * Wilder RSI 14: 15 bar (1 initial price change seed + 14 Wilder RMA smoothing periods)
  * Wilder ATR 14: 15 bar (1 initial True Range seed + 14 Wilder RMA smoothing periods)
  * MACD (12,26,9): 35 bar (26 bar slow EMA + 9 bar signal line)
- **Isolasi Sumber Operasional**:
  * `TechnicalMetricsCalculationService` diperbarui untuk secara ketat hanya membaca `source = 'IDX Edge PRO'` dan `validation_status = 'VALID'`.
  * Arsip Yahoo Finance di database lokal tidak lagi tercampur secara diam-diam dalam perhitungan metrik operasional.
- **Hasil Aktual BNBR (25 Bar Operasional EOD: 2026-09-28 s/d 2026-10-30)**:
  * SMA 20: **Rp 58,50** (`SUFFICIENT`)
  * SMA 50: **N/A** (`INSUFFICIENT_25_OF_50`)
  * SMA 200: **N/A** (`INSUFFICIENT_25_OF_200`)
  * Wilder RSI 14: **22,22** (`CALCULATED_WILDER_RMA`, zona oversold)
  * MACD (12,26,9): **N/A** (`INSUFFICIENT_HISTORY`, 25 bar < 35 bar)
  * ATR 14: **Rp 4,76** (11,61% dari close Rp 41, `CALCULATED_WILDER_RMA`)
  * Status Teknis: `PARTIAL_CALCULATED`, Tren: `BELOW_SMA20` (Close Rp 41 di bawah SMA 20 Rp 58,50).

### b. Penyelarasan Tanggal Aliran Broker (Pasar Reguler)
- Angka broker XL (+Rp 4,46 Miliar @ Rp 54,39) terbukti merupakan transaksi sesi **26 Oktober 2026**.
- **Sesi Terakhir Terverifikasi (2026-10-30)**:
  * Top Net Buyer: **DR** (RHB Sekuritas) net +Rp 1,81 Miliar (44,07M lembar @ Rp 41,00) dan **YJ** net +Rp 533,82 Juta (13,02M lembar @ Rp 41,00).
  * Broker **XL** pada sesi 30 Oktober hanya membukukan net buy tipis +Rp 31,39 Juta (763,60 ribu lembar @ Rp 41,00).
  * Top Net Seller: **ZP** (-Rp 853,52 Juta @ Rp 41,00) dan **MG** (-Rp 460,50 Juta @ Rp 41,00).
- Penyajian data kini eksplisit membedakan sesi pembanding harian dengan agregasi 3 bulan.

### c. Koreksi Label Fundamental & Solvabilitas
- Rasio 1,04x dikoreksi menjadi **`TOTAL_LIABILITIES_TO_TOTAL_EQUITY`** (Total Liabilitas Rp 4.090,30 M / Total Ekuitas Rp 3.926,47 M), bukan DER utang berbunga murni.
- Total Equity dan Parent Equity dipisahkan secara tegas.
- Pertumbuhan laba bersih FY 2025 (+90,47% YoY ke Rp 502,74 M) dicatat belum mencerminkan perbaikan arus kas operasional murni tanpa rilis laporan audit lengkap.

### d. Koreksi Kesimpulan Kelayakan
- **Day Trade**: `DAY_TRADE_NOT_ASSESSABLE_WITH_AVAILABLE_DATA` (Keterbatasan sistem: endpoint `/api/price` masih `QUARANTINED`, spread Pasar Reguler tidak tersedia).
- **Swing Trade**: `WASPADA / SPEKULATIF TINGGI` (Risiko instrumen: Papan Pemantauan Khusus, fraksi batas bawah Rp 41, tren di bawah SMA 20 tanpa konfirmasi pembalikan arah).

### e. Kontrak Limit Provider
- `IdxEdgeClient::history()` mencatat secara transparan `requested_limit` dan `effective_limit` (min 20 bar / ge: 20 sesuai schema provider).
- Kebutuhan warmup indikator ditentukan pemanggil di level service analitik, bukan diasumsikan cukup oleh provider.

### f. Pengiriman Koreksi Publikasi Telegram
- Koreksi ringkas dikirimkan ke Telegram pengguna:
  * **Telegram Outbox ID**: `52`
  * **Status**: `SENT`
  * **Telegram Outbound Message ID**: `521` (Terkirim 2026-10-31 06:18:52 UTC / 13:18:52 WIB).
  * Pesan 518 dan evidence aslinya tetap dipertahankan utuh sebagai arsip historis.

### g. Finalisasi Layanan Bersama Generik (Bank, Non-Bank, Histori Terbatas)

1. **Pengujian Boundary & Pembanding Independen Indikator**:
   - Boundary: 0 bar (seluruh metrik `null`), 14 bar (seluruh metrik `null` termasuk Wilder 14 yang butuh minimal 15 bar), 25 bar (SMA 20, RSI 14, ATR 14 `CALCULATED`; SMA 50, SMA 200, MACD `null`), 50 bar (SMA 20, SMA 50, RSI 14, ATR 14, MACD `CALCULATED`; SMA 200 `null`), 200 bar (`FULL_CALCULATED`).
   - Uji pembanding independen formula Wilder RSI dan ATR terbukti mematuhi seed rata-rata gain/loss dan rentang bounded `0.0 - 100.0`.

2. **Verifikasi Gross Buy/Sell Average dari Field Mentah Broker**:
   - `IdxEdgeClient` dan `BrokerSummaryIngestionService` menghitung harga rata-rata murni dari data mentah nilai transaksi dibagi volume transaksi (`$bval / $bvol` dan `$sval / $svol`), bukan membaca alias rasio net flow `bavg`/`savg`.
   - Teruji pada emiten bank (`BBCA`, `BMRI`) dan non-bank (`ASII`, `SMRA`, `BNBR`).

3. **Pemisahan Tingkat Harga, Fraksi Harga, dan Status Papan Perdagangan**:
   - Ditangani secara generik via `BeiUniverseRegistry::resolvePriceFraction(float $price, string $board)`:
     * Papan Pemantauan Khusus: Flat Rp 1 (Full Call Auction / Notasi Khusus).
     * Papan Reguler / Utama / Pengembangan: Rp 1 (< Rp 200), Rp 2 (Rp 200 - < Rp 500), Rp 5 (Rp 500 - < Rp 2.000), Rp 10 (Rp 2.000 - < Rp 5.000), Rp 25 (>= Rp 5.000).

4. **Sinkronisasi Otomatis Bersyarat (Zero Redundant Network Call)**:
   - Sebelum analisis dijalankan, pemeriksaan freshness dilakukan terhadap kalender bursa (`BeiCalendarService::getCurrentTradingDay()`):
     * Jika EOD bar sesi terkini sudah ada di basis data bersama, network fetch diabaikan (0 kuota).
     * Jika broker sesi terkini sudah tersimpan di `broker_summaries`, ingest diabaikan (0 kuota).
     * Sinkronisasi jaringan hanya dipicu saat snapshot hilang atau sudah kadaluwarsa (`isPast()`).

5. **Hasil Regresi Lintas Instrumen**:
   - **Bank (BBCA)**: Sektor Financials, Papan Utama, Harga Rp 4.980 (Fraksi Tick Rp 10). DER diabaikan/dikecualikan dengan label akuntansi operasional (DPK nasabah dicatat di liabilitas).
   - **Non-Bank (ASII)**: Sektor Industrials, Papan Utama, Harga Rp 4.800 (Fraksi Tick Rp 10). Rasio solvabilitas memisahkan total liabilitas terhadap total ekuitas dan utang berbunga murni.
   - **Histori Terbatas (BNBR)**: Sektor Industrials, Papan Pemantauan Khusus, Harga Rp 41 (Fraksi Tick Rp 1). Status teknikal `PARTIAL_CALCULATED` (SMA50/SMA200/MACD otomatis `null`), tanpa ada percabangan kode hardcode khusus BNBR di service bersama.


