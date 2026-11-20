# Laporan Penerimaan Visualisasi Chart.js, Indikator Teknikal & Panel Broker
**Sistem:** Aether Market Intelligence  
**Komponen:** Modul Visualisasi Frontend (Vite 5 + Chart.js + Financial Candlestick Plugin)  
**Tanggal Penerimaan:** 2026-11-02  
**Status Audit:** **VERIFIED (Pass)**  

---

## 1. Ringkasan Pengujian & Arsitektur Visual

Visualisasi canvas manual lama pada `dashboard/resources/views/dashboard/ticker_chart_script.blade.php` telah sepenuhnya digantikan dengan bundel produksi Chart.js berbasis npm/Vite tanpa ketergantungan CDN runtime eksternal.

### Komponen Inti Terintegrasi:
- `chart.js` (v4.5.1)
- `chartjs-chart-financial` (v0.2.1) — Modul resmi `CandlestickController`, `CandlestickElement`, `OhlcController`, `OhlcElement`
- `chartjs-adapter-date-fns` & `date-fns` — Adaptor sumbu waktu ISO 8601 berbasis time scale / timeseries scale
- Arsitektur Dual-Canvas:
  * Canvas Utama: Candlestick interaktif + Moving Average Overlay (SMA 20, SMA 50, SMA 200)
  * Sub-Canvas: Panel terpisah yang dapat dipilih dinamis (Volume harian, RSI 14, MACD Histogram/Signal, atau Akumulasi Broker Deret Waktu)

---

## 2. Hasil Uji Browser & Fungsionalitas Interaktif

Pengujian dilakukan secara otomatis menggunakan peramban lokal headless Chrome pada URL `http://127.0.0.1:8000/market/BBCA?tab=summary` dengan evaluasi konsol JavaScript dan verifikasi visual:

| Fitur / Kontrol | Kondisi Uji | Hasil Verifikasi Empiris | Status |
|---|---|---|---|
| **Registrasi Bundle** | Inisialisasi Vite `app.js` | `window.Chart` memuat controller: `bar`, `line`, `candlestick`, `ohlc`. Zero console errors. | **VERIFIED** |
| **Candlestick OHLC** | Bar harian BBCA | Sumbu harga Rp 4.500 s/d Rp 9.000, candle naik hijau (`#34d399`), candle turun merah (`#f87171`), sumbu tanggal `dd MMM` / `MMM yyyy`. | **VERIFIED** |
| **Overlay SMA 20/50/200** | Checkbox toggle independen | Tiga garis tren (SMA20 emas, SMA50 sian, SMA200 mawar) terhitung dari rentang historis lengkap dengan warmup, bukan hanya bar yang sedang ditampilkan. | **VERIFIED** |
| **Filter Rentang Waktu** | Tombol `1M`, `3M`, `6M`, `1Y` | `1M` menampilkan 20 bar, `3M` menampilkan 62 bar, `1Y` menampilkan 238 bar. Timeframe tetap candle harian EOD. | **VERIFIED** |
| **Toggle Raw vs Adjusted** | Tombol `Adj` vs `Raw` | Mengambil data dari endpoint internal `/api/market/{ticker}/chart-data?mode=raw|adjusted`. Badge status memperbarui label `RAW (Nominal/Unadjusted)` vs `ADJUSTED (Back-adjusted)`. | **VERIFIED** |
| **Sub-Panel Volume** | Tab `Volume` | Bar chart volume dengan warna selaras candle (hijau jika close >= open, merah jika close < open). Sumbu Y dalam jutaan lembar. | **VERIFIED** |
| **Sub-Panel RSI(14)** | Tab `RSI(14)` | Garis RSI dengan garis referensi horizontal putus-putus pada level Overbought (70) dan Oversold (30). | **VERIFIED** |
| **Sub-Panel MACD** | Tab `MACD` | Histogram bar berwarna (hijau positif, merah negatif), MACD line, dan Signal line. | **VERIFIED** |
| **Sub-Panel Akumulasi Broker** | Tab `Akumulasi Broker` | Menampilkan time-series net buy kumulatif broker teratas (AK, CC, DX, RX, YU, ZP) sepanjang sumbu tanggal yang sinkron dengan harga. | **VERIFIED** |
| **Tabel Top Buyers/Sellers** | Tampilan detail broker | Tabel Top 3 Net Buyers (YU, DX, ZP) dan Top 3 Net Sellers (CC, AK, RX) dengan format nilai Miliar Rupiah dan penafian regulasi resmi. | **VERIFIED** |
| **Lifecycle & Memori** | Pergantian tab, resize, Back/Forward | Instans grafik lama dihancurkan sebelum pembuatan grafik baru (`priceChart.destroy()`). Tanpa `setInterval` polling. Tanpa pemanggilan LLM/Gemini saat navigasi. | **VERIFIED** |

---

### Bukti Tangkapan Layar Tambahan Multi-Kategori Emiten (Audit 02 Nov 2026):
- **Tangkapan Layar Saham Keuangan (BBCA):**  
  `C:\Users\ASUS\AppData\Local\hermes\cache\screenshots\browser_screenshot_4e95ff050a314250b6a2deb55ae8926c.png`  
  * Candlestick EOD rapi, SMA 20/50/200 melintasi sumbu harga Rp 4.500 s/d Rp 7.000, sub-panel volume selaras candle, ATR 14: Rp 241,07, RSI 14: 26,98.
- **Tangkapan Layar Saham Infrastruktur / Telko (TLKM):**  
  `C:\Users\ASUS\AppData\Local\hermes\cache\screenshots\browser_screenshot_86a69f7a11644f1ba02e7b29b3407bf0.png`  
  * Candlestick EOD tajam, SMA 20 melintasi harga Rp 1.700 s/d Rp 2.400, sub-panel volume aktif, ATR 14: Rp 130,00, RSI 14: 43,61.
- **Tangkapan Layar Saham Industri / Konglomerasi (ASII):**  
  `C:\Users\ASUS\AppData\Local\hermes\cache\screenshots\browser_screenshot_8c38c4cec4ca44d8b0adbb254c3a84c1.png`  
  * Candlestick EOD tajam, SMA 20/50/200 melintasi harga Rp 3.800 s/d Rp 5.800, sub-panel volume aktif, ATR 14: Rp 153,57, RSI 14: 51,20. Zero JS errors.

**Keterangan Tangkapan Layar:**
1. Header grafik menampilkan judul `GRAFIK INTERAKTIF CANDLESTICK & INDIKATOR`, badge `ADJUSTED (Back-adjusted)`, dan status pasar `Data EOD sesi sebelumnya (2026-10-30) — intraday belum tersedia`.
2. Toolbar rentang `1M`, `3M`, `6M`, `1Y` (aktif: `1Y` / `1M`), mode `Adj` (aktif) dan `Raw`.
3. Checkbox SMA 20, 50, 200 aktif dengan ketiga garis moving average melintasi candlestick (dihitung dari warmup historis 500 bar).
4. Sub-panel aktif `Akumulasi Broker` memplot garis pergerakan kumulatif 6 sekuritas utama dalam rentang -4.000 M s/d +4.000 M.
5. Tabel Top 3 Net Buyers dan Top 3 Net Sellers ter-render di bawah panel dengan catatan penafian inventori: `* Gross buy/sell average bukan posisi inventori tersisa.`
