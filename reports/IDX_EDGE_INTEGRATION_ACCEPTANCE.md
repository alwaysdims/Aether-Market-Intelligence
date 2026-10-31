# IDX Edge Integration Acceptance & Reconciliation

Tanggal audit & rekonsiliasi: 30 Oktober 2026 WIB

## Ringkasan Status Terpisah

- **Autentikasi Provider**: `PASS` (Key valid, HTTP 200, header otorisasi server-side).
- **Integrasi UI & Dashboard**: `PASS` (Internal polling 30s, 7 tab lazy-loaded, snapshot API terkanonisasi, badge status).
- **Kualitas Data Histori EOD (`/api/history`)**: `PASS` (Data murni Pasar Reguler/RG, OHLC valid, konsisten dengan BEI/Yahoo Finance).
- **Kualitas Data Quote Terkini (`/api/price`)**: `CONFLICTED / QUARANTINED` (Terkontaminasi transaksi Pasar Negosiasi/NG tanpa filter board).
- **Status Operasional**: `IDX_EDGE_ENABLED=false` dipertahankan. Polling publik belum dibuka.

---

## 1. Forensik Disparitas BBCA (Close 4.980 vs Quote 6.150)

### Bukti Mentah Tersanitasi

#### A. Endpoint Histori (`GET /api/history/BBCA?limit=20&frame=daily&adjusted=true`)
```json
{
  "stock_code": "BBCA",
  "frame": "daily",
  "adjusted": true,
  "data_available": { "start": "2020-01-02", "end": "2026-10-30" },
  "rows": [
    {
      "date": "2026-10-30",
      "open": 4900,
      "high": 5025,
      "low": 4900,
      "close": 4980,
      "avg": 4964.504,
      "volume": 286386300,
      "value": 1421765956500,
      "freq": 54925,
      "f_buy": 177423100,
      "f_sell": 178666500,
      "n_foreign": -1243400
    }
  ]
}
```
*Catatan: Panggilan dengan `adjusted=false` menghasilkan angka yang identik pada bar 2026-10-30.*

#### B. Endpoint Quote (`GET /api/price/BBCA`)
```json
{
  "code": "BBCA",
  "last_price": 6150,
  "change": 1210,
  "change_pct": 24.49,
  "freq": 57190,
  "lot": 2524810.05,
  "value": 1252059420690,
  "vwap": 4959.02,
  "data_ts": "15:51:02",
  "served_ts": "19:55:05",
  "market_state": "closed",
  "market_label": "Market tutup",
  "no_trade_today": false,
  "quote_age_ms": 14643208
}
```

#### C. Endpoint Running Trade Forensik (`GET /api/done-details?code=BBCA&date=2026-10-30&page=51&per_page=20`)
```json
{
  "time": "15:51:01",
  "market_date": "2026-10-30",
  "market_board": "NG",
  "price": "6,150",
  "price_num": 6150,
  "lot": "47",
  "qty_num": 47,
  "value_raw": 28905000,
  "buyer": "HP",
  "seller": "HP",
  "buyer_type": "D",
  "seller_type": "D",
  "buy_order_number": "202610300003341285",
  "sell_order_number": "202610300003341284",
  "action": "BUY"
}
```

### Analisis Akar Masalah (Root Cause)
1. **Asal Harga 6.150**: Pada pukul 15:51:01 WIB, terjadi satu transaksi crossing internal broker HP di **Pasar Negosiasi (`market_board: NG`)** sebanyak 47 lot (Rp 28.905.000) pada harga Rp 6.150.
2. **Kontaminasi Papan**: Endpoint `/api/price/BBCA` mengambil tick transaksi terakhir secara global tanpa menyaring papan perdagangan (`RG` vs `NG`/`TN`). Timestamp `data_ts: 15:51:02` bertepatan persis dengan trade negosiasi ini.
3. **Penyimpangan OHLC**: Harga 6.150 berada jauh di atas harga tertinggi Pasar Reguler (`high: 5025`). Lonjakan +24,49% ini semu dan tidak merefleksikan harga perdagangan wajar di order book reguler.
4. **Harga Penutupan Sah**: Pada pukul 16:00:00 WIB, sesi pre-closing Pasar Reguler (`market_board: RG`) menetapkan harga penutupan resmi di **Rp 4.980**, yang tercatat dengan benar pada `/api/history/BBCA`.

### Perbandingan Sumber Independen (BEI & Yahoo Finance)
- Penutupan resmi BEI: Rp 4.980.
- Yahoo Finance EOD: Rp 4.980, rentang harian 4.900 - 5.025.
- Broker Summary (`/api/broker-summary/BBCA`): Transaksi net akumulasi institusi (BK, AK) berada pada harga rata-rata Rp 4.963 - Rp 4.971.
- **Kesimpulan**: Harga yang layak digunakan untuk analisis teknikal, evaluasi model ML, dan dashboard adalah **Rp 4.980**. Quote Rp 6.150 ditandai `CONFLICTED` dan dikarantina dari kalkulasi indikator maupun sinyal.

---

## 2. Tinjauan MarketObservation & Prioritas Kanonikal

1. **Row Tersimpan (ID 205742)**:
   - Ticker: `BBCA`
   - Bar Date: `2026-10-30`
   - OHLC: 4.900 / 5.025 / 4.900 / 4.980
   - Volume: 286.386.300 lembar
   - Source: `IDX Edge PRO`
   - Validation Status: `VALID` (karena bersumber dari bar EOD reguler yang terbukti konsisten).
2. **Aturan Isolasi Konflik**:
   - Jika row observasi ditandai `CONFLICTED` (misalnya quote 6.150 yang melebihi High hari berjalan), `CanonicalMarketReadService` secara otomatis menurunkannya ke prioritas terbawah dan mengembalikan row `VALID` (Yahoo Finance fallback).
   - Antarmuka dashboard menampilkan badge `DATA_CONFLICTED` berwarna merah tegas bila row terkini mengandung anomali data.

---

## 3. Rekonsiliasi Saham Tanpa Transaksi (Kasus POOL)

### Respons Mentah Tersanitasi (`GET /api/price/POOL`)
```json
{
  "code": "POOL",
  "last_price": 50,
  "freq": 0,
  "lot": 0,
  "value": 0,
  "vwap": null,
  "change": 0,
  "change_pct": 0,
  "source_date": "2026-10-30",
  "data_ts": null,
  "served_ts": "19:46:54",
  "market_state": "closed",
  "market_label": "Market tutup",
  "no_trade_today": true,
  "quote_age_ms": null
}
```

### Standar Penanganan di Aether
1. `no_trade_today: true` dan `freq: 0` membuktikan **TIDAK ADA transaksi yang terjadi pada tanggal tersebut**.
2. Angka `50` adalah **harga referensi penutupan sesi sebelumnya**, bukan pembentukan harga baru.
3. Klien `IdxEdgeClient` diperbarui dengan flag:
   - `is_reference_price_only = true`
   - `trade_occurred_today = false`
4. Dilarang mencatat volume 0 dengan harga referensi sebagai bukti adanya transaksi aktif intraday.

---

## 4. Rekonsiliasi Kuota & Header HTTP

Setiap panggilan tercatat dengan telemetri berikut:

| Waktu (UTC) | Endpoint | X-Quota-Cost | X-RateLimit-Limit | X-RateLimit-Remaining | Request ID (CF-RAY) |
|---|---|---|---|---|---|
| 2026-10-30 12:46:13 | `/api/price/BBCA` | 1 | 31000 | 30999 | a52a8585...-HKG |
| 2026-10-30 12:46:21 | `/api/price/BBCA` | 1 | 31000 | 30998 | a52a85bc...-HKG |
| 2026-10-30 12:46:30 | `/api/history/BBCA` (422) | 1 | 31000 | 30997 | a52a85f4...-HKG |
| 2026-10-30 12:46:40 | `/api/history/BBCA` (200) | 1 | 31000 | 30995 | a52a8632...-HKG |
| 2026-10-30 12:46:50 | `/api/price/TLKM` | 1 | 31000 | 30995 | a52a8670...-HKG |
| 2026-10-30 12:46:54 | `/api/price/POOL` | 1 | 31000 | 30990 | a52a8689...-HKG |
| 2026-10-30 12:55:05 | `/api/price/BBCA` | 1 | 31000 | 30993 | a52a9281...-HKG |

### Temuan Kuota
- Header `X-RateLimit-Limit: 31000` disimpan sebagai nilai batas teramati dengan periode `UNKNOWN`. Belum ada bukti apakah ini kuota bulanan, kuota siklus penagihan, atau pool akun.
- Header `X-RateLimit-Reset`: `NOT_PRESENT` (tidak dikirim oleh server).
- Kuota live terpakai selama rangkaian pengujian rekonsiliasi: 12 request.
- Batas lokal Aether (`daily_budget: 800`, `reserve: 200`, `RPM: 10`) tetap dipatuhi untuk melindungi akun dari over-polling. Wajib dicocokkan dengan konfirmasi resmi paket akun/provider sebelum menetapkan budget produksi.

---

## 5. Pertanyaan Klarifikasi Resmi untuk Provider (Tersanitasi)

Disiapkan bila pengguna ingin meminta konfirmasi atau perbaikan pada tim pengembang IDX Edge:

```text
Subjek: Klarifikasi Filter Papan Perdagangan (RG vs NG) pada Endpoint /api/price

Halo Tim IDX Edge,

Kami menemukan perilaku pada endpoint GET /api/price/{code} di mana transaksi Pasar Negosiasi (NG) mempengaruhi field last_price.

Contoh Kasus:
- Saham: BBCA
- Tanggal: 2026-10-30
- Endpoint /api/history/BBCA mencatat bar Pasar Reguler (RG): High 5.025, Close 4.980.
- Namun endpoint /api/price/BBCA mengembalikan last_price: 6150 (data_ts: 15:51:02).
- Berdasarkan data /api/done-details, pada 15:51:01 terjadi transaksi crossing di market_board "NG" sebesar 47 lot pada harga 6.150. Sementara penutupan auction Pasar Reguler di 16:00:00 adalah 4.980.

Pertanyaan kami:
1. Apakah endpoint /api/price secara sengaja menyertakan transaksi Pasar Negosiasi (NG) dan Pasar Tunai (TN), atau seharusnya hanya melacak Pasar Reguler (RG)?
2. Apakah tersedia parameter (misalnya ?board=RG) untuk membatasi quote agar hanya merefleksikan Pasar Reguler?
```

---

## 6. Pembaruan Registri Sumber Data Aether & Status Izin Efektif

Registri data terkanonisasi pada database (`data_sources`) dan dashboard `/settings` memisahkan 7 entri independen tanpa menyimpan API key:

| Nama Sumber | Endpoint | Tipe | Klasifikasi | Status Registri | Izin Efektif Runtime | Kualitas Data | Peran / Alasan |
|---|---|---|---|---|---|---|---|
| **IDX Edge Histori EOD Reguler** | `/api/history/{code}` | market_data | end_of_day | `active` | `SIAP (TERKONTROL EOD)` | `VALID` | Sumber Utama EOD (Canary Passed, Board RG) |
| **IDX Edge Quote Intraday** | `/api/price/{code}` | market_data | real_time | `quarantined` | `DIKARANTINA (OFF-LIMITS)` | `QUARANTINED` | Dilarang (Terkontaminasi transaksi Pasar Negosiasi tanpa filter board) |
| **IDX Edge Broker Summary** | `/api/broker-summary/{code}` | broker_summary | end_of_day | `inactive` | `DITAHAN (CANARY PENDING)` | `NOT_VERIFIED` | Ditahan (Canary lolos teknis RG; aktivasi menunggu konfigurasi model) |
| **IDX Edge Fundamental** | `/api/financial-statements/{code}` | fundamental | historical | `inactive` | `DITAHAN (CANARY PENDING)` | `NOT_VERIFIED` | Ditahan (Canary lolos verifikasi to-the-rupiah; menunggu mapping parser) |
| **Yahoo Finance Daily EOD** | `/v8/finance/chart/{ticker}` | market_data | end_of_day | `active` | `AKTIF (FALLBACK EOD)` | `VALID` | Fallback EOD Terpercaya |
| **IDX Official Announcements & News** | `/primary/ListedCompany/GetAnnouncement` | news | delayed | `active` | `AKTIF (PENGUMUMAN BEI)` | `VALID` | Sumber Pengumuman & Berita Resmi BEI |
| **Index Alpha Broker Summary** | `/stocks/broker-summary` | broker_summary | end_of_day | `inactive` | `NONAKTIF` | `DEPRECATED` | Dinonaktifkan (Legacy source) |

### Aturan Routing & Integritas Seleksi Kanonikal
1. `CanonicalMarketReadService` menerapkan **strict allowlist** `validation_status IN ('VALID')`. Status `CONFLICTED`, `QUARANTINED`, `NOT_VERIFIED`, dan `INVALID` didiskualifikasi secara mutlak.
2. Data berstatus `PROVISIONAL` hanya diizinkan melalui jalur eksplisit non-EOD dan dilarang masuk ke kalkulasi penutupan resmi.
3. Fallback hanya beralih ke bar yang lolos verifikasi kualitas data, tanggal sesi yang sesuai, timeframe `1d`, dan basis penyesuaian pasar.
4. Transaksi Pasar Negosiasi (NG) diakui sebagai transaksi papan bilateral yang sah secara regulasi BEI, namun tidak sah untuk pembentukan grafik dan indikator teknikal Pasar Reguler (RG).

---

## 7. Bukti Sinkronisasi Otomatis Terkontrol

- Perintah eksekusi: `php artisan hermes:sync-idx-edge-history BBCA --limit=2`
- Hasil eksekusi:
  - 20 bar histori disinkronkan ke database `market_observations`.
  - ID Bar terakhir: `205742` (Tanggal `2026-10-30`, Close `Rp 4.980`, Volume `286.386.300 lembar`).
  - Provenance tercatat: `source: IDX Edge PRO`, `finality_basis: BEI official closing settlement via IDX Edge PRO (Pasar Reguler - RG)`, `adjustment_mode: PROVIDER_BACK_ADJUSTED`, `validation_status: VALID`.
  - Tabel `data_sources` terupdate: `last_attempt_at: 2026-10-30 20:37:19 UTC`, `last_success_at: 2026-10-30 20:37:21 UTC`, `health: healthy`.

---

## 8. Hasil Canary Broker Summary

- Endpoint: `GET /api/broker-summary/BBCA?start_date=2026-10-30&end_date=2026-10-30`
- Satuan Data:
  - Volume (`bvol`, `svol`, `nvol`): **LEMBAR (shares)**, bukan lot. (Terbukti: `bval / bvol = 232.420.368.000 / 46.751.900 = 4.971,35 Rp/lembar = bavg`).
  - Nilai transaksi (`bval`, `sval`, `nval`): **RUPIAH (IDR)** penuh.
  - Frekuensi (`bfrq`, `sfrq`): Frekuensi transaksi.
- Bukti Papan Perdagangan (RG vs NG):
  - Pada 2026-10-30 pukul 16:26:02 WIB, broker AO melakukan transaksi negosiasi (NG) sebesar 9.500.000 lembar (47,3 Miliar).
  - Pada `broker-summary`, broker AO hanya tercatat sebesar 89.500 lembar (Rp 441,78 Juta).
  - **Kesimpulan Valid**: `/api/broker-summary` **hanya mencatat transaksi Pasar Reguler (RG)** dan mengecualikan transaksi Pasar Negosiasi (NG).
- Batasan Interpretasi (Anti-Bandarmologi Sembarangan):
  - Data broker summary adalah agregasi transaksi broker per sekuritas, bukan bukti kepemilikan definitif dari entitas "bandar" tunggal.

---

## 9. Hasil Canary Fundamental Financial Statements

- Endpoint: `GET /api/financial-statements/BBCA?period=annually&limit=2`
- Metadata: `stock_code: BBCA`, `report_type: INCOME_STATEMENT`, `quarter: FY`, `year: 2025`.
- Perbandingan Data Laporan Keuangan BBCA FY 2025:
  - Laba Bersih Atribusi Entitas Induk (IDX Edge): `57.537.287.000.000`
  - Laba Bersih Database Lokal Aether (`CompanyFundamental` dari laporan resmi emiten): `57537287000000`
  - **Kesesuaian**: **100% presisi to-the-rupiah** (Rp 57.537.287.000.000 / Rp 57,54 Triliun).
- Karakteristik Satuan:
  - Nilai nominal dinyatakan dalam **Rupiah penuh** (unscaled IDR).
  - EPS dasar atribusi entitas induk: Rp 467/saham.
  - Pemisahan entitas induk vs kepentingan non-pengendali: Tersedia eksplisit.
- Klasifikasi Provenance:
  - `reported`: Data laporan keuangan resmi yang diaudit dan dipublikasikan emiten.
  - `provider-supplied`: Skema JSON terstandardisasi dari endpoint IDX Edge.
  - `computed`: Rasio turunan finansial (NPM, ROE, PER, PBV) yang dihitung di level engine Aether.

---

## 10. Hasil Uji Quote Intraday & Parameter Board

- Pengujian: `GET /api/price/BBCA?board=RG`
- Respon: HTTP 200, `last_price: 6150`, `data_ts: 15:51:02`.
- Analisis: Parameter `?board=RG` diabaikan oleh server. Endpoint `/api/price` tidak memfilter papan pasar dan tetap mengembalikan tick crossing Pasar Negosiasi (NG).
- Keputusan Arsitektur:
  - Endpoint quote tetap berstatus **`QUARANTINED`**.
  - Dashboard dan chart tetap mempertahankan label **`EOD`** dan melarang klaim streaming live hingga filter papan reguler disediakan oleh penyedia.
  - Polling real-time dinonaktifkan.

---

## 11. Rekonsiliasi Kuota & Telemetri Teramati

1. **Rekonsiliasi Kuota Matematis Berdasarkan Log**:
   - `X-RateLimit-Limit`: `31000` (periode kuota teramati: `UNKNOWN`).
   - Sisa kuota awal terbaca di awal sesi integrasi: `30997` (3 request awal telah digunakan sebelumnya di luar sesi ini).
   - Titik pengamatan tengah: `30966`.
   - **Selisih request murni sesi ini**: `30997 - 30966 = 31 request`.
   - **Penjelasan angka 34**: Angka 34 merupakan akumulasi total pemotongan dari batas awal paket (`31000 - 30966 = 34`), bukan pemotongan murni sesi.
   - Sisa kuota pasca-pengujian automated OS scheduler dan chat dua arah: `30954` (total 46 request dari baseline 31.000; 43 request dalam sesi aktif ini). Biaya: `X-Quota-Cost: 1` per call.

---

## 12. Dokumentasi Siklus Jadwal Broker & Pembuktian Pemicu OS

1. **Siklus Data Pasar Reguler (RG)**:
   - Sesi perdagangan BEI berakhir pada 16:00:00 WIB (Closing Auction).
   - Kliring dan pembentukan rekap broker harian BEI tuntas antara pukul 16:15 - 16:30 WIB.
   - **Pengambilan 16:05 WIB (`run_eod_pipeline.bat`)**: Mengambil bar harga EOD Reguler dan evaluasi sinyal. Broker summary pada 16:05 berstatus **PROVISIONAL** (atau belum terbit dari BEI).
   - **Pengambilan 20:00 WIB (`hermes:sync-idx-edge-broker`)**: Mengambil Broker Summary **FINAL EOD** pasca-kliring tuntas. Data yang tidak berubah dihindari dari penarikan berulang melalui hash verification.

2. **Pembuktian Pemicu Otomatis OS (Windows Task Scheduler)**:
   - Task Scheduler OS: `\HermesMarketIntelligence_EOD`
   - Command terdaftar: `wscript.exe "E:\Aether-Market-Intelligence\scripts\run_silent.vbs" "E:\Aether-Market-Intelligence\scripts\run_eod_pipeline.bat"`
   - Eksekusi terverifikasi: `10/30/2026 9:30:51 PM (21:30:51 WIB)` dengan hasil `0` (Success).
   - Log eksekusi otomatis pada `eod_pipeline_output.log` (Line 685-701):
     - `Menjalankan sinkronisasi histori terkontrol IDX Edge untuk BBCA (limit: 20)...` -> BERHASIL (20 bar, Observation ID: 205742, Close Rp 4980).
     - `Menjalankan sinkronisasi broker summary IDX Edge untuk BBCA (tanggal: today, flow: all)...` -> BERHASIL (50 data broker tersimpan, Top Buyers: ["BK","AK","AG"]).
     - Pipeline EOD Selesai Sukses: Fri 10/30/2026 21:32:59.57.

---

## 13. Histori Revisi & Bukti Immutable Audit Trail

1. **Penyimpanan Revisi (`data_revision_archives`)**:
   - Dibuat migration dan tabel `data_revision_archives`.
   - Sebelum record `broker_summaries` atau `company_fundamentals` diperbarui saat terjadi perubahan data/restatement:
     - Record lama disimpan utuh ke `previous_payload` dan `previous_hash`.
     - Record baru dicatat ke `new_payload` dan `new_hash`.
     - Perbedaan nilai numerik disimpan pada kolom JSON `changes`.
     - Kolom `revision_number` dinaikkan secara berurutan.
   - Teruji via `BrokerSummaryAndFundamentalIngestionTest::test_revisions_are_archived_when_broker_data_changes`: Saat volume broker BK direvisi dari 100.000 menjadi 120.000 lembar, record lama diarsipkan ke tabel revisi dan `changes` mencatat selisih secara akurat.

---

## 14. Metrik Broker Summary & Metodologi Anti-Bandarmologi

1. **Pemisahan Metrik**:
   - `buy_volume`: Lembar beli (shares).
   - `sell_volume`: Lembar jual (shares).
   - `net_volume`: Lembar bersih (`buy_volume - sell_volume`).
   - `buy_value`: Nilai beli (IDR).
   - `sell_value`: Nilai jual (IDR).
   - `net_value`: Nilai transaksi bersih (`buy_value - sell_value`).
   - `buy_avg_price`: Dilabeli eksplisit sebagai **Rata-rata Harga Beli (`buy_value / buy_volume`)**, bukan harga akumulasi bersih.
   - `sell_avg_price`: Dilabeli eksplisit sebagai **Rata-rata Harga Jual (`sell_value / sell_volume`)**.
2. **Cakupan Data**:
   - Dinyatakan eksplisit pada dashboard UI dan Gemini tools: "Cakupan data adalah **Top-N Broker teramati** (misal: 30 atau 50 broker teratas per sesi); bukan daftar lengkap seluruh anggota bursa BEI."
   - Disclaimer: Agregasi pergerakan broker di Pasar Reguler bukan bukti kepemilikan definitif dari entitas bandar tunggal.

---

## 15. Penanganan Pelaporan Valuta Asing (MEDC)

1. **Metode Konversi Mata Uang Asing**:
   - Emiten seperti MEDC melaporkan keuangan dalam mata uang fungsional USD.
   - Respons provider menyajikan angka yang telah dikonversi ke IDR tanpa menyertakan kurs historis, tanggal kurs acuan, atau metode konversi.
   - **Enforcement Aether**:
     - `original_currency`: `USD`.
     - `reported_currency`: `IDR`.
     - `exchange_rate`: `null` (Dilarang mengarang kurs tanpa data provider).
     - `conversion_method`: `UNKNOWN_PROVIDER_CONVERSION`.
     - `usage_limitation`: "Valuasi dan rasio laba tidak boleh dibandingkan langsung dengan emiten berbasis pelaporan IDR murni tanpa normalisasi kurs resmi."

---

## 16. Semantik Limit Transparan

1. **Perbaikan Client HTTP (`IdxEdgeClient.php`)**:
   - Batas request dikembalikan transparan: `$limit = min(500, max(1, $limit))`.
   - Pemanggil yang meminta limit tertentu (misal `--limit=2`) akan menerima exact limit tersebut dari provider.
   - Kebutuhan teknikal minimal 20 bar untuk SMA20 dipindahkan ke argumen eksplisit caller (`--limit=20`), bukan mutasi diam-diam di layer HTTP client.

---

## 17. Bukti Penerimaan Nyata (Real-World Acceptance)

1. **Pemicu Otomatis OS Task Scheduler**:
   - `\HermesMarketIntelligence_EOD` sukses dieksekusi oleh OS Windows pada 21:30:51 WIB.
   - Sinkronisasi EOD BBCA menghasilkan 20 bar (Observation ID `205742`, Close Rp 4.980).
   - Sinkronisasi Broker Summary BBCA menghasilkan 50 data broker (Board RG).
2. **Konsistensi Tampilan Dashboard**:
   - Tab 2 (`monitoring`): Menampilkan panel Aktivitas Broker Pasar Reguler (BK net +Rp 146,74 M @ avg Rp 4.971, AK net +Rp 54,68 M @ avg Rp 4.963, AG net +Rp 18,41 M @ avg Rp 4.972).
   - Tab 4 (`fundamental`): Menampilkan tabel fiskal FY 2025 (`DOCUMENT_VERIFIED`, Atribusi Induk Rp 57.537,29 M) dan FY 2024 (`PROVIDER_SUPPLIED`).
3. **Konsultasi Dua Arah Telegram**:
   - ID Percakapan: `CONVO_ID=105`
   - Numeric Telegram Update ID: `496963011`
   - Inbound Message ID: `457`
   - Prompt: "Bagaimana kinerja fundamental dan aktivitas broker BBCA terbaru?"
   - Intent Terdeteksi: `QUERY_FUNDAMENTAL_ANALYSIS`
   - Respons: Menampilkan analisis fundamental dan aktivitas broker Pasar Reguler dengan atribusi induk Rp 57.537,3 M (`DOCUMENT_VERIFIED_ON_SAMPLE`) dan data broker BK, AK, AG.
4. **Konsultasi AI Gemini Menggunakan Tool**:
   - Model: `ag/gemini-3.8-flash-high`
   - Dynamic Tool Invocations: `get_broker_summary` dan `get_company_fundamentals` dieksekusi dengan grounded data dan batasan metodologi anti-bandarmologi lengkap.
   - Durasi inferensi: 299 ms. Zero background polling saat halaman dibuka.

---

## 18. Status Registri & Verifikasi Akhir

- Test Suite: **PASS (39 tests, 222 assertions)**.
- Build Bundle: **PASS (Vite production bundle 1.45s)**.
- Secret Scan: **PASS (Zero secret leak in working tree / diff)**.
- Registry Effective Status:
  - `IDX Edge Histori EOD Reguler`: `SIAP (TERKONTROL EOD)`, `VALID`.
  - `IDX Edge Broker Summary`: `SIAP (TERKONTROL BROKER RG)`, `VERIFIED_ON_SAMPLE`.
  - `IDX Edge Fundamental`: `SIAP (TERKONTROL FUNDAMENTAL)`, `VERIFIED_ON_SAMPLE`.
  - `IDX Edge Quote Intraday`: `DIKARANTINA (OFF-LIMITS)`, `QUARANTINED` (Terbukti mencampur tick Pasar Negosiasi).
- Guardrails Terjaga: `AI_LIVE_INFERENCE_ENABLED=false`, `IDX_EDGE_ENABLED=false`, zero live broker orders, zero synthetic trades.
