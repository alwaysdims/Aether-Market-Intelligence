# LAPORAN PENERIMAAN OPERASIONAL: RISET DATA ON-DEMAND & LIVE AI TERKONTROL
## AETHER MARKET INTELLIGENCE — TAHAP I LANJUTAN

**Tanggal Evaluasi:** 25 Oktober 2026, 19:40 WIB  
**Status Audit:** VERIFIED & RESOLVED (Uji Nyata Pengguna: PENDING PENGGUNA)  
**Lingkungan:** Windows 11, PostgreSQL 16 (`project-bot`), Laravel 11, Hermes Gateway, 9router (`ag/gemini-3.8-flash-high`)  
**Root Proyek:** `E:\Aether-Market-Intelligence`

---

### 1. RINGKASAN EKSEKUTIF

Sistem Aether Market Intelligence telah berhasil ditingkatkan dari batasan 10 ticker statis menjadi sistem **Riset Data On-Demand & Grounded AI** yang mendukung seluruh emiten terdaftar di Bursa Efek Indonesia (BEI/IDX). Seluruh proses berjalan di bawah batasan ketat:
- **Biaya Sumber Data Rp0:** Pengambilan data murni menggunakan sumber publik gratis (Yahoo Finance `.JK`, LKBN ANTARA RSS, GDELT).
- **Zero Live Trading & Zero Broker Orders:** Nol pemanggilan broker, nol order beli/jual riil maupun sintetis.
- **Store-First, Analyze-Later:** Data wajib divalidasi dan disimpan ke basis data PostgreSQL sebelum dianalisis oleh AI.
- **Anti-Halusinasi & Proteksi Abstain:** Model machine learning dipertahankan ketat (`NO_SIGNAL_ABSTAIN`), dan emiten di luar 10 konstituen awal diberi status eksplisit `MODEL_NOT_VALIDATED_FOR_INSTRUMENT` tanpa mengarang probabilitas.
- **Eksekusi Asinkron:** Pengguna menerima konfirmasi instan di Telegram, sementara pengumpulan dan inferensi berjalan di latar belakang tanpa risiko timeout gateway.

---

### 2. BUKTI NYATA EKSEKUTI RISET TERKONTROL (EMITEN NON-UNIVERSE AWAL: ICBP)

Pengujian eksekusi nyata dilakukan pada instrumen **ICBP (PT Indofood CBP Sukses Makmur Tbk)** yang berada di luar 10 ticker awal:

| Parameter Evaluasi | Nilai Faktual Tercatat | Keterangan Integritas |
|---|---|---|
| **Request ID** | `REQ_20261025_192658_ICBP_5zJe` | Dibuat unik dan tercatat di tabel `research_requests` |
| **Status Pekerjaan** | `completed` | Alur selesai tanpa exception |
| **Klasifikasi Freshness** | `MISSING` -> `AVAILABLE_FRESH` | Terdeteksi belum ada di DB, lalu ditarik dari Yahoo Finance |
| **MarketObservation ID** | `111` | Disimpan di tabel `market_observations` |
| **Emiten & Bursa** | `ICBP` (Bursa Efek Indonesia / IDX) | Terverifikasi resmi melalui Yahoo Finance `ICBP.JK` |
| **Tanggal Bar & Finalitas** | `2026-10-23` (EOD Final settlement) | Bar penutupan resmi sesi bursa terakhir |
| **Harga OHLCV** | Open: Rp 6.000,00 \| High: Rp 6.075,00 \| Low: Rp 5.950,00 \| Close: Rp 5.975,00 \| Volume: 4.808.600 | Data numerik positif dan konsisten |
| **Rentang Bar & Return** | High-Low: Rp 125,00 (2,10%) \| Return vs Open: -Rp 25,00 (-0,42%) | Dihitung murni dari bar OHLC tersimpan |
| **Arsip Raw Immutable** | `E:\Aether-Market-Intelligence\data\raw_immutable\20261025\ICBP_192701.json` | Snapshot JSON tersimpan di disk lokal |
| **Cryptographic Hash** | `1b83da1a65e24313d1b1adf8fea13b85769aa57401bcdfa2e1aaca88d8b5d842` | SHA-256 diverifikasi identik |
| **EvidenceSnapshot ID** | `12` (`EVD_20261023_ICBP_1b83da1a`) | Terikat ke entitas bukti analisis |
| **MarketAnalysis ID** | `16` | Disimpan di tabel `market_analyses` |
| **Model AI & Routing** | `ag/gemini-3.8-flash-high` via 9router (`127.0.0.1:20128/v1`) | HTTP 200, valid schema output |
| **Status Model ML** | `MODEL_NOT_VALIDATED_FOR_INSTRUMENT` | Terbukti: Tidak ada probabilitas buatan, nol klaim palsu |
| **Outbox Message ID** | `2` | Antrean balasan Telegram terdaftar dengan `reply_to_message_id` |
| **Latensi End-to-End** | `19.592 ms` (~19,5 detik) | Meliputi unduhan data, validasi, hashing, DB commit, dan inferensi AI |

---

### 3. AUDIT KONTROL & KEAMANAN SISTEM

1. **Pemisahan Feature Flags:**
   - `AI_LIVE_INFERENCE_ON_DEMAND_ENABLED=true` (Aktif khusus kueri chat pengguna).
   - `AI_LIVE_INFERENCE_EOD_ENABLED=false` (EOD batch scheduler tetap tanpa AI).
   - `AI_LIVE_INFERENCE_ENABLED=false` (Global lock tetap aman).
2. **Karantina Konflik Harga (> 0.5%):**
   - Aturan deteksi konflik aktif. Jika terdapat dua penyedia dengan selisih harga penutupan `> 0.5%`, data diberi status `CONFLICTED` dan dikarantina, tanpa dirata-ratakan secara sembunyi-sembunyi.
3. **Penyaringan Simbol Asing & Kripto:**
   - Simbol non-BEI (`AAPL`, `TSLA`, `BTC`, `ETH`) otomatis dicegat dan ditolak dengan pesan klarifikasi bahwa sistem beroperasi khusus untuk emiten BEI.
4. **Zero Broker Calls & Zero Synthetic Data:**
   - Database kueri membuktikan:
     * `PredictionSignal::where('signal_status', 'LIVE_BROKER_ORDER')->count() == 0`
     * `MarketObservation::where('source', 'SYNTHETIC_GENERATOR')->count() == 0`

---

### 4. HASIL SUITE PENGUJIAN REGRESI (100% PASS)

Pengujian otomatis dieksekusi melalui PHPUnit dengan database terisolasi:

```text
Tests: 115 passed (503 assertions)
Duration: 77.19s
Status: PASS 100% (Exit Code: 0)
```

Rincian modul pengujian:
1. `OnDemandResearchAndDataFreshnessTest`: 10 passed (55 assertions)
   - Uji cache data fresh (tanpa re-fetch tak perlu).
   - Uji pengumpulan emiten baru (`ICBP`) dan pembuatan evidence.
   - Uji permintaan intraday pada akhir pekan (`AVAILABLE_BUT_INSUFFICIENT`).
   - Uji kalender BEI dan hari libur nasional 2026.
   - Uji pelabelan `MODEL_NOT_VALIDATED_FOR_INSTRUMENT` untuk saham di luar 10 universe.
   - Uji penolakan simbol asing/kripto (`AAPL`, `TSLA`).
   - Uji deteksi konflik harga dan karantina.
   - Uji antrean asinkron dan worker eksekusi.
   - Uji routing dua arah Telegram untuk universe luas.
   - Uji nol broker calls dan nol data sintetis.
2. `TelegramTickerAnalysisAndTimeoutTest`: 11 passed (93 assertions).
3. `TahapILanjutanRoutingAndAutomationTest`: 10 passed (69 assertions).
4. `TahapILifecycleAndTelegramTest`: 11 passed (37 assertions).
5. Seluruh unit/feature test pendukung (EOD ingestion, provenance, monitoring, outbox): 73 passed (249 assertions).

---

### 5. MATRIKS STATUS KELULUSAN AUDIT (A–J)

| Seksi Spesifikasi | Deskripsi Komponen | Status | Hasil Verifikasi |
|---|---|---|---|
| **A** | Audit dan Kontrol Live AI | **VERIFIED** | 3 flag terisolasi; inferensi murni on-demand; zero shell execution |
| **B** | Kontrak Permintaan & Normalisasi Ticker | **VERIFIED** | Ekstraksi terstruktur, normalisasi case-insensitive, penolakan asing |
| **C** | Data Inventory & Freshness Policy | **VERIFIED** | 6 klasifikasi kesegaran, kalender BEI, pemisahan 3 aturan kedaluwarsa |
| **D** | Riset AI yang Terkontrol & Kolektor Rp0 | **VERIFIED** | Yahoo Finance EOD, Antara News, limit kuota, deduplikasi DB |
| **E** | Validasi & Penyimpanan Skema PostgreSQL | **VERIFIED** | Migrasi `research_requests`, raw immutable SHA-256, deteksi konflik |
| **F** | Analisis Grounded Setelah Data Tersimpan | **VERIFIED** | Evidence-first, NO_SIGNAL dilindungi, MODEL_NOT_VALIDATED aktif |
| **G** | Penanganan Asinkron Telegram Tanpa Timeout | **VERIFIED** | Acknowledgement instan, worker background, balasan outbox terdeduplikasi |
| **H** | Tampilan Dashboard & Rekap | **VERIFIED** | Tabel riset on-demand aktif di `dashboard/overview` |
| **I** | Suite Pengujian Regresi Lengkap | **VERIFIED** | 115 tests passed 100% (503 assertions, exit code 0) |
| **J** | Verifikasi Aktual & Dokumentasi | **VERIFIED** | Eksekusi nyata ICBP selesai; seluruh 5 dokumen lengkap |
| **Interaktif** | Uji Pesan Telegram Nyata Pengguna | **PENDING** | Menunggu pengiriman pesan nyata oleh pengguna di Telegram |

---

### 6. PETUNJUK PENGUJIAN TELEGRAM NYATA OLEH PENGGUNA

Sistem pada Hermes Gateway dan database PostgreSQL telah aktif dan siap menerima pesan pengujian nyata di Telegram `@Info_BosKuu_bot`:

1. **Uji Emiten Baru (Di luar 10 Saham Awal):**
   Kirimkan pesan:
   `analisis emiten ICBP`
   *Respons yang Diharapkan:* Bot memberikan respons lengkap (harga Rp 5.975, EOD 23 Okt 2026, status MODEL_NOT_VALIDATED_FOR_INSTRUMENT, dan narasi grounded AI).

2. **Uji Emiten Validated Universe (10 Saham Awal):**
   Kirimkan pesan:
   `analisis emiten SMRA`
   *Respons yang Diharapkan:* Bot menyajikan analisis SMRA dengan status `NO_SIGNAL_ABSTAIN` (Confidence 1,24% < 40,00%).

3. **Uji Penolakan Simbol Asing:**
   Kirimkan pesan:
   `analisis emiten AAPL`
   *Respons yang Diharapkan:* Bot memberikan klarifikasi penolakan instrumen luar negeri dan menyarankan emiten BEI.
