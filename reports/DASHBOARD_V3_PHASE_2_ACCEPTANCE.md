# Laporan Penerimaan Dashboard V3 Tahap 2: Tujuh Tab Detail Emiten

**Status**: ACCEPTED & VERIFIED • **Versi**: 3.2.0 • **Tanggal**: 1 November 2026 WIB  
**Workspace**: `E:\Aether-Market-Intelligence` • **Arsitektur**: Laravel 13 + Tailwind v4 / Vite 8 + PostgreSQL + Hermes Engine

---

## 1. Ringkasan Eksekutif & Implementasi 7 Tab

Detail emiten (`/market/{ticker}`) telah ditingkatkan sepenuhnya ke arsitektur **Dashboard V3 Institusional** dengan 7 tab navigasi asinkron (*lazy-loaded* via `X-Tab-Request`, cache memori klien 5 menit tanpa mem-cache status `PROCESSING`, sinkronisasi URL query dan `window.history.pushState` / `popstate`):

| Tab | Nama Tab | Isi & Kapabilitas Faktual | Sumber Data Operasional |
| :--- | :--- | :--- | :--- |
| **Tab 1** | **Ringkasan** | Profil usaha bersumber, sektor, industri, tanggal IPO, papan bursa, harga penutupan EOD terkini, grafik EOD + volume (30 sesi), statistik OHLCV sesi terakhir, audit kelengkapan 4 dimensi data. | `IDX Edge PRO` / `IdxCompanyRegistry` |
| **Tab 2** | **Pemantauan & Sinyal** | Status watchlist, riwayat evaluasi sinyal prediksi kuantitatif (`PredictionSignal`), ringkasan aktivitas broker Pasar Reguler (Top 30 Net Buyers & Sellers), pemisahan Gross Buy Avg vs Gross Sell Avg, catatan metodologis perantara nasabah. | `BrokerSummary` (IDX Edge PRO RG) |
| **Tab 3** | **Teknikal & Indikator** | Indikator matematis (SMA 20, SMA 50, SMA 200, RSI 14, MACD, ATR 14), batas kecukupan bar (`input_bars`), rentang bar historis, rolling 20-bar low/high sebagai batas historis (bukan jaminan pantulan). | `TechnicalMetricsCalculationService` |
| **Tab 4** | **Fundamental Fiskal** | Panel rekonsiliasi saham & valuasi: pemisahan `reported_basic_eps`, `implied_weighted_average_shares`, `ordinary_shares_period_end`, mode penyesuaian harga (`price_adjustment_mode`), tabel laporan tahunan multi-tahun, atribusi laba induk vs NCI, rasio solvabilitas `TOTAL_LIABILITIES_TO_TOTAL_EQUITY`. | `CompanyFundamental` (IDX Edge PRO) |
| **Tab 5** | **Kesimpulan AI Gemini** | Format terstruktur kanonikal (`UnifiedAnalysisRendererService` / `AiConclusionPresenter`): ringkasan awam, perspektif teknikal & fundamental, faktor pendukung faktual, faktor risiko konkret, status kelengkapan komponen, batasan informasi, detail audit trail (raw JSON tersanitasi dalam collapsible panel, bukan isi utama). | `MarketAnalysis` / `EvidenceSnapshot` |
| **Tab 6** | **News & Event** | Feed berita & keterbukaan informasi terindeks per ticker, tanggal publikasi, waktu diperoleh (*ingested_at*), verifikasi sumber (Antara / Keterbukaan BEI), pemisahan fakta rilis vs interpretasi AI. | `NewsItem` / `DocumentChunk` |
| **Tab 7** | **Riwayat AI Historis** | Riwayat seluruh analisis AI historis khusus emiten, model AI, arah dampak, horizon waktu, status skema validasi, serta tabel log permintaan riset on-demand & Telegram dengan penautan `Evidence ID` dan `Analysis ID`. | `MarketAnalysis` / `ResearchRequest` |

---

## 2. Koreksi Metadata & Rekonsiliasi Valuasi Global

1. **Pemisahan Metrik Saham**:
   - `reported_basic_eps`: EPS dasar yang dilaporkan emiten dalam laporan laba rugi resmi (misal FORE FY 2025: **Rp 10,95**).
   - `ordinary_shares_period_end`: Jumlah saham beredar pada tanggal snapshot/akhir periode (misal FORE: **8.918.000.000 lembar**), menjadi penyebut resmi untuk BVPS (**Rp 76,32**) dan PBV (**7,14x**).
   - `implied_weighted_average_shares`: Dihitung murni sebagai $\text{Laba Induk} / \text{Reported EPS}$ (misal FORE: Rp 90,13 M / Rp 10,95 = **8.231.328.047 lembar**). Diberi label transparan sebagai rata-rata tertimbang implisit tanpa klaim penyebab tanpa bukti dokumen resmi.
2. **Penyesuaian Harga (*Price Adjustment Mode*)**:
   - Dicatat faktual dari kolom `adjustment_mode` di `MarketObservation` (misal: `PROVIDER_BACK_ADJUSTED` atau `NOMINAL`).
   - Tidak mencampuradukkan harga adjusted dengan kalkulasi lot bursa atau fraksi tick nominal.
3. **Penyajian Kesimpulan AI**:
   - Dilarang menampilkan JSON mentah sebagai isi utama tampilan.
   - Panel kesimpulan menyajikan narasi ekonomi ramah pembaca awam, kartu ringkasan eksekutif, metrik harga EOD, serta rincian faktor risiko.
   - Raw JSON tersanitasi hanya dapat diakses melalui panel lipat teknis (*collapsible details*) untuk kebutuhan audit teknis.

---

## 3. Hasil Verifikasi Visual & Pengujian Empiris

### a. Verifikasi Browser Headless (Chrome DevTools / Localhost)
- **FORE Detail (`/market/FORE?tab=summary` & `?tab=fundamental`)**:
  * Tampil harga EOD Rp 545 (-3,54%), High Rp 565, Low Rp 530, Vol 3.011.500 lembar.
  * Tab Fundamental menampilkan kartu rekonsiliasi: Reported Basic EPS Rp 10,95, Saham Akhir Periode 8.918.000.000 lbr, Weighted Shares Implisit 8.231.328.047 lbr, Mode Penyesuaian `PROVIDER_BACK_ADJUSTED`.
  * Status sumber: `PROVIDER_SUPPLIED`.
- **Emiten Tak Dikenal (`/market/ZZZZ?tab=summary`)**:
  * Tampil status elegan `DATA_NOT_AVAILABLE` tanpa broken UI.
  * Badge `MISSING` dan `UNREGISTERED` simetris.
  * Tombol riset nonaktif dengan label `Riset Terkunci (Ticker Tak Dikenal)`.
  * Screenshot tersimpan di: `C:\Users\ASUS\AppData\Local\hermes\cache\screenshots\browser_screenshot_6ffb384d4cc44c52afe22e1d9cf16c80.png`.
- **Console Errors**: 0 JavaScript error.
- **Vite Build**: Sukses dalam 3.85 detik (`npm run build`).

### b. Suite Pengujian Otomatis
- `php artisan test --compact tests/Feature/CrossTickerRoutingAndTickerValidationTest.php tests/Feature/IdxExpansionAndFundamentalAnalysisTest.php tests/Feature/StructuredAnalysisAndRetrievalTest.php`
- **Hasil**: 29 passed, 132 assertions PASSED (0 failures).

---

## 4. Berkas Berubah

1. `dashboard/app/Http/Controllers/DashboardController.php`:
   - Integrasi 4 metrik rekonsiliasi saham & valuasi (`reportedBasicEps`, `netIncomeParent`, `periodEndShares`, `impliedWeightedShares`, `priceAdjustmentMode`).
   - Penegakan filter `source = 'IDX Edge PRO'` dan `validation_status = 'VALID'` pada pembacaan observasi pasar.
2. `dashboard/resources/views/dashboard/ticker_tab_content.blade.php`:
   - Penambahan kartu rekonsiliasi valuasi pada Tab 4 (Fundamental).
   - Penyelarasan badge broker Pasar Reguler ke `PROVIDER_SUPPLIED`.
   - Penyempurnaan disclaimer perantara nasabah pada tabel broker summary.
3. `dashboard/app/Services/CompanyFundamentalService.php`:
   - Pemisahan basis reported EPS, saham akhir periode, dan saham rata-rata tertimbang implisit.
4. `dashboard/tests/Feature/CrossTickerRoutingAndTickerValidationTest.php`:
   - Unit test rekonsiliasi matematis EPS, BVPS, dan weighted shares.
5. `dashboard/tests/Feature/IdxExpansionAndFundamentalAnalysisTest.php`:
   - Penyelarasan observasi harga valid `IDX Edge PRO`.

---

## 5. Keterbatasan yang Belum Terselesaikan

1. **Feed Intraday**: Quote intraday Pasar Reguler (`/api/price`) tetap berada dalam status **`QUARANTINED`** karena kontaminasi transaksi Pasar Negosiasi (NG). Keputusan Day Trade tetap berstatus **`DAY_TRADE_NOT_ASSESSABLE_WITH_AVAILABLE_DATA`**.
2. **Model Machine Learning**: Model inferensi ML terkalibrasi out-of-sample saat ini terbatas pada 10 emiten konstituen awal (`BBCA`, `BBRI`, `BMRI`, `BBNI`, `ASII`, `TLKM`, `MAPI`, `ARTO`, `MEDC`, `SMRA`). Emiten lain (termasuk FORE) berstatus **`MODEL_NOT_VALIDATED_FOR_INSTRUMENT`**.
3. **Dokumen Primer PDF**: Emiten di luar pilot fisik (seperti FORE, BNBR, BULL) belum memiliki file PDF auditan lokal di storage, sehingga status fundamental tetap dibatasi pada **`PROVIDER_SUPPLIED`**.
