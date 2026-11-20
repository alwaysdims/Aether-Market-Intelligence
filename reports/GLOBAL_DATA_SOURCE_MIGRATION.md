# Global Data Source Migration & Deprecation Manifest
**Sistem:** Aether Market Intelligence  
**Tanggal Penerimaan:** 2026-11-02  
**Status Audit:** **MIGRATED & VERIFIED**  

---

## 1. Ringkasan Eksekutif & Prinsip Tata Kelola Data

Seluruh jalur data pasar bursa (harga, histori OHLCV, moving averages, broker summary, fundamental fiskal emiten) telah dimigrasikan dari penyedia lama (`Yahoo Finance / yfinance` dan `Index Alpha`) ke sumber operasional tunggal: **Aether Connector -> IDX Edge PRO (`https://stock.arjum.com/api-docs`)**.

### Prinsip Operasional yang Ditegakkan:
1. **Zero Unverified Fallback:** Tidak ada fallback senyap ke Yahoo Finance atau Index Alpha. Jika data IDX Edge belum tersedia, status dinyatakan `WAITING_FOR_SESSION_DATA` atau `DATA_NOT_AVAILABLE`, bukan mengambil data dari feed pihak ketiga yang tidak tersinkronisasi.
2. **Karantina Intraday (`/api/price` = QUARANTINED):** Quote intraday tetap berada dalam status karantina karena pencampuran data Pasar Negosiasi (NG) dan Pasar Reguler (RG). Dilarang keras menghasilkan sinyal trading atau rekomendasi scalping spekulatif berbasis feed quote sebelum pemisahan papan bursa diverifikasi.
3. **Pemisahan Fisik Raw vs Adjusted:** Seri harga nominal (`UNADJUSTED`) dan seri harga disesuaikan aksi korporasi (`PROVIDER_BACK_ADJUSTED`) disimpan secara terpisah di PostgreSQL dengan constraint unik kanonikal `uq_obs_canonical_eod_v2`.
4. **Isolasi Arsip Historis:** Arsip Yahoo 210.621 bar non-referensial telah terkompresi aman di `dashboard/storage/app/backups/unreferenced_yahoo_observations_archive.jsonl.gz` (SHA-256: `1a2886e74f44f81685bc25bfbf0e668a337bff8779c58e7950f9748429314263`). Arsip tidak dihapus sembarangan dan dipertahankan sebagai artefak penelitian masa lalu.

---

## 2. Matriks Inventarisasi Job, Consumer & Jalur Data

| Job / Consumer | Pemilik / Runner | Jadwal | Sumber Lama | Sumber Efektif | Data Capability | Status Operasional | Bukti Pengujian / Catatan |
|---|---|---|---|---|---|---|---|
| `volume-explosion-radar` (`a6735049deb7`) | Cron Hermes | 09:20 WIB (Senin-Jumat) | Yahoo Finance (`yf.download`) | **PAUSED / BLOCKED** | INTRADAY_VOLUME_EXPLOSION | **PAUSED (BLOCKED_DATA_CAPABILITY)** | Jeda karena feed intraday RG belum terbukti bebas Pasar Negosiasi. Unit volume lama salah tafsir (shares vs lots). |
| `morning-spike-alert` (`5fdd8b516cd0`) | Cron Hermes | 09:30 WIB (Senin-Jumat) | Yahoo Finance 5m (`yfinance`) | **PAUSED / BLOCKED** | INTRADAY_MORNING_SPIKE | **PAUSED (BLOCKED_DATA_CAPABILITY)** | Jeda karena feed intraday dikarantina dan jendela 09:00-09:30 bersifat parsial tanpa pembanding same-time RVOL yang sah. |
| `bsjp-alert` (`ca841bacc334`) | Cron Hermes | 15:15 WIB (Senin-Jumat) | Yahoo Finance (`bsjp_screener.py`) | **PAUSED** | EOD_BREAKOUT_SCAN | **PAUSED** | Dijeda guna mencegah pengambilan duplikat Yahoo. Dialihkan ke pipeline EOD kanonikal Aether. |
| `sesi1-midday-alert` (`18c0493348ce`) | Cron Hermes | 11:30 WIB (Senin-Jumat) | Yahoo Finance (`test_15m.py`) | **PAUSED / BLOCKED** | MIDDAY_INTRADAY_BREAKOUT | **PAUSED (BLOCKED_DATA_CAPABILITY)** | Jeda karena quote intraday RG dikarantina. |
| `aftermarket-1800` (`aae0b9de2f44`) | Cron Hermes | 18:00 WIB (Senin-Jumat) | Yahoo Finance (`aftermarket_reporter.py`) | **PAUSED** | EOD_SCAN_POST_MARKET | **PAUSED** | Dialihkan ke pipeline EOD Aether. |
| `broker-update-1915` (`c10d0003d52b`) | Cron Hermes | 19:15 WIB (Senin-Jumat) | Index Alpha (`api.indexalpha.id`) | **PAUSED** | BROKER_SUMMARY | **PAUSED** | Jalur Index Alpha dimatikan. Broker summary harian diambil langsung via endpoint IDX Edge PRO `/api/broker-summary` dan `/api/broker-accumulation`. |
| `hermes:morning-briefing` | Laravel Scheduler (`console.php`) | 08:30 WIB (Senin-Jumat) | Mixed Observation | **Aether DB (IDX Edge PRO)** | CANONICAL_EOD_PREV_SESSION | **ACTIVE** | Membaca bar penutupan resmi H-1 dari IDX Edge PRO (`adjustment_mode = PROVIDER_BACK_ADJUSTED`). Tanpa feed intraday. |
| `hermes:refresh-eod --broker` | Task Scheduler `\HermesMarketIntelligence_EOD` | 16:05 WIB (Senin-Jumat) | Script EOD lama / Yahoo canary | **IDX Edge PRO API** (`/api/history`, `/api/broker-summary`) | EOD_INCREMENTAL_REFRESH | **ACTIVE** | Overlap 20 bar, deteksi hash revision, arsip perubahan data korporasi, broker summary OBSERVED_SNAPSHOT. |
| `hermes:backfill-universe` | Runner OS `run_universe_backfill.bat` | Background / On-Demand | None (tidak ada backfill universe) | **IDX Edge PRO API** (`/api/history`) | UNIVERSE_1Y_BACKFILL | **ACTIVE (RESUMABLE)** | 266 emiten complete (259.704 bar tersimpan di PostgreSQL), checkpoint database, rate-limiting 60 RPM, proteksi cadangan 200 unit. |
| `hermes:monitor-market` | Laravel Scheduler / Task Scheduler | 00:05 WIB & 16:05 WIB | Yahoo CSV cleaned fallback | **Aether DB (IDX Edge PRO)** | POST_HORIZON_MONITORING | **ACTIVE** | Fallback CSV Yahoo di-deprecate. Evaluasi horizon 5 hari dan expiry 14 hari strictly membaca bar valid IDX Edge PRO. |
| `hermes:process-consultation-queue` | Laravel Scheduler | Setiap menit (24/7) | AI Direct | **Hermes Consultation Worker** | ASYNC_AI_CONSULTATION | **ACTIVE** | Terlindungi dengan alokasi reserve kuota minimal 200 unit. |
| Chart.js Interactive Suite | Dashboard Controller (`web.php`) | On-Demand (Web UI) | Canvas render manual (Yahoo data) | **Aether JSON API** (`/api/market/{ticker}/chart-data`) | CANONICAL_EOD_AND_BROKER_CHART | **ACTIVE (VERIFIED)** | Membaca data kanonikal PostgreSQL IDX Edge PRO: Candlestick, Volume, SMA20/50/200, RSI14, MACD, panel akumulasi broker. |

---

## 3. Audit Rumus, Satuan & Formula pada Alert Lama

Audit mendalam terhadap rekod dan skrip alert lama (`scan_0930_results.csv`, `analyze_top5_bars.py`, `s1_today_scan.csv`):

### 1. Kasus Emiten ADMR (Kerancuan Satuan Juta Lot vs Lembar)
- **Fakta Data:** Sesi 2026-10-30 mencatat volume `18.694.300` lembar saham (shares), dengan turnover tercatat Rp 24.396.061.500 (Rp 24,4 Miliar) pada harga rata-rata Rp 1.305.
- **Kekeliruan Alert Lama:** Alert lama menyebut volume sebagai `18,7 juta lot`. Dalam aturan bursa BEI, 1 lot = 100 lembar saham. `18.694.300` lembar adalah `186.943 lot` (~0,187 juta lot). Menyebutnya `18,7 juta lot` menghasilkan kesalahan skala 100x lipat (mengimplikasikan transaksi fiktif Rp 2,44 Triliun).
- **Koreksi Terpasang:** Seluruh kode sistem Aether secara eksplisit membedakan `volume_shares` (lembar) dan `volume_lots` (`floor(volume_shares / 100)`). Turnover transaksi menggunakan nilai resmi provider (`value_idr`), bukan taksiran kasar `close * volume`.

### 2. Kasus Emiten SIMP (Distorsi Persentase terhadap VWAP)
- **Fakta Data:** Skrip `analyze_top5_bars.py` menghitung VWAP intraday 30 menit dari bar 5 menit Yahoo Finance: `vwap = sum(close * volume) / sum(volume)`.
- **Kekeliruan Alert Lama:**
  1. Bar intraday Yahoo Finance mengikutsertakan transaksi Pasar Negosiasi (block crossing), sehingga harga transaksi crossing mendistorsi rata-rata harga pasar reguler.
  2. Formula memakai `close` bar 5 menit dikalikan volume bar 5 menit, bukan harga per transaksi aktual (running trade ticks).
  3. Mengklaim deviasi `((last_price - vwap) / vwap) * 100` sebagai "aggressor buy flow", padahal arah agresi beli/jual (haka/haki) tidak dapat disimpulkan dari OHLC tanpa data orderbook bid/ask queue.
- **Koreksi Terpasang:** Field volume tidak lagi dikonversi menjadi "buy flow" spekulatif. Status quote intraday dikunci dalam `BLOCKED_DATA_CAPABILITY` dan `QUARANTINED` hingga data tick pasar reguler terisolasi.

### 3. Kasus Emiten RALS (Inversi Rasio Risiko Trigger 376 vs Stop 366)
- **Fakta Data:** Pada pemindaian sesi 1 (`s1_today_scan.csv`), harga penutupan berada di Rp 376, dengan titik stop loss diusulkan pada Rp 366 (titik penutupan hari sebelumnya) dan target resistensi Rp 380 (titik tertinggi 5 hari).
- **Kekeliruan Alert Lama:** Alert mempublikasikan rekomendasi dengan narasi "Risk:Reward 1:3", yang merupakan halusinasi prompt LLM.
  * Jarak risiko (Downside Risk): `376 - 366 = 10 poin (-2,66%)`.
  * Jarak target (Upside Reward): `380 - 376 = 4 poin (+1,06%)`.
  * Rasio Risk:Reward aktual: `4 : 10 = 0,4 : 1` (ekspektasi matematis negatif, risiko 2,5x lebih besar dari potensi keuntungan).
- **Koreksi Terpasang:** Seluruh perhitungan nominal risiko, jarak persentase cut loss, dan rasio reward-to-risk wajib dihitung deterministik oleh kode program (PHP/Python math), dilarang diserahkan pada estimasi teks model LLM.

---

## 4. Jalur Lama yang Dihapus / Dinonaktifkan

1. **Skrip Kolektor Yahoo Finance (`E:\hermes-stock-agent\src\data_collector.py`):** Dinonaktifkan dari alur operasional. Digantikan oleh klien kanonikal `IdxEdgeClient.php`.
2. **Kolektor Broker Index Alpha (`E:\hermes-stock-agent\src\broker_collector.py`):** Pemanggilan `api.indexalpha.id` dihentikan. Digantikan oleh endpoint `/api/broker-summary/{code}` dan `/api/broker-accumulation/{code}` pada IDX Edge PRO.
3. **Fallback CSV Cleaned Yahoo (`MarketMonitoringService::ingestObservationsFromCleanedData`):** Dinonaktifkan secara permanen dengan logging deprecation warning.
4. **Alur Deteksi Lonjakan Intraday (`explosion_detector.py`):** Dihentikan sementara (`PAUSED`) karena ketiadaan feed intraday Pasar Reguler yang tervalidasi.

---

## 5. Nota Koreksi Publikasi (Single Concise Correction Receipt)

Teks koreksi ringkas yang siap digunakan sebagai rujukan administratif tanpa spamming saluran pesan:

```
[CATATAN INTEGRITAS & KOREKSI METRIK DATA PASAR]
Sistem Aether Market Intelligence telah menonaktifkan alert radar intraday legacy (Volume Explosion & Morning Spike) akibat ditemukannya inkonsistensi pada feed pihak ketiga terdahulu (kerancuan pelaporan satuan volume lembar vs lot pada ADMR, distorsi VWAP Pasar Negosiasi pada SIMP, dan ketidaktepatan kalkulasi rasio risiko pada RALS).

Mulai sesi ini, seluruh metrik harga, teknikal, dan broker bursa disatukan ke feed kanonikal IDX Edge PRO Pasar Reguler (RG) dengan validasi matematis deterministik dan karantina ketat pada data quote intraday yang belum terverifikasi.
```
