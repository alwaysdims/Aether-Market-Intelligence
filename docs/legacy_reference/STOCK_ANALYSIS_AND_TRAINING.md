# DOKUMENTASI METODE ANALISIS SAHAM, TRAINING MODEL, DAN EVALUASI PERFORMA
## AETHER MARKET INTELLIGENCE

**Versi Dokumen:** 2.0.0 (Rebranded from Hermes Market Intelligence)  
**Repositori Resmi:** [https://github.com/alwaysdims/Aether-Market-Intelligence](https://github.com/alwaysdims/Aether-Market-Intelligence)  
**Tanggal:** 17 Oktober 2026  
**Repositori Terkait:** `E:\Project-bot` & `E:\hermes-stock-agent`  
**Status Operasional:** Research & Forward Paper Trading (Zero Live Execution)  

---

## 1. PENDAHULUAN & PRINSIP DASAR

Dokumen ini menjelaskan secara teknis dan matematis metodologi pengumpulan data, analisis teknikal berbasis aturan, inferensi dampak berita berbantuan LLM (Gemini 3.8 Flash High), riwayat audit model prediktif (Machine Learning), serta protokol evaluasi performa pada sistem **Aether Market Intelligence** dengan mesin kuantitatif **Hermes Stock Agent**.

### Prinsip Kuantitatif Utama:
1. **Pemisahan Tegas Fakta vs Opini vs Sinyal:** Data harga resmi penutupan (EOD) dan keterbukaan informasi emiten adalah fakta historis; analisis LLM adalah interpretasi berkonteks; sinyal kuantitatif adalah hipotesis berbasis kondisi data.
2. **Anti Lookahead & Data Leakage:** Seluruh fitur teknikal dan keputusan sinyal hanya menggunakan data yang telah resmi ditutup pada waktu keputusan ($t \le \text{16:00 WIB}$).
3. **Pemodelan Friksi Riil:** Seluruh pengujian strategi memperhitungkan komisi beli 0,15%, komisi jual 0,25%, pembulatan 1 lot = 100 lembar saham, fraksi tick BEI, serta adverse slippage 1 tick pada order pasar.
4. **Zero Guaranteed Profit:** Tidak ada klaim kepastian keuntungan. Seluruh model machine learning terdahulu terbukti menghasilkan ekspektasi negatif out-of-sample di pasar BEI, sehingga live trading dilarang secara mutlak.

---

## 2. JALUR C1 — ANALISIS BERBASIS ATURAN & INDIKATOR TEKNIKAL

Jalur C1 beroperasi pada modul analitik murni di `E:\hermes-stock-agent\src\`. Jalur ini mengekstraksi data pasar EOD dan broker summary pasca-penutupan bursa.

### A. Data Sumber & Universe Saham
- **Universe Aktif (10 Saham Likuid BEI):** BBCA, BBRI, BMRI, BBNI, ASII, TLKM, MAPI, ARTO, MEDC, SMRA.
- **Data OHLCV:** Diambil melalui `data_collector.py` dari Yahoo Finance API v8 (`data/raw_daily/`), berstatus Adjusted Close untuk dividen/split.
- **Data Broker Summary:** Diambil melalui `broker_collector.py` dari Index Alpha API (`api.indexalpha.id`) pasca-penutupan sistem kliring KPEI (~19:00 WIB).

### B. Indikator Teknikal yang Terverifikasi di Kode
Berdasarkan implementasi `src/feature_engineering.py` dan `src/accumulation_scanner.py`:
1. **Average True Range (ATR-14):** Mengukur volatilitas absolut harga untuk penentuan dinamis jarak target laba (1.5x ATR) dan pembatas rugi / stop-loss (1.0x ATR).
2. **Relative Volume (RVOL-20):**
   $$\text{RVOL}_{t} = \frac{\text{Volume}_{t}}{\frac{1}{20}\sum_{i=1}^{20} \text{Volume}_{t-i}}$$
   Mengukur anomali partisipasi pasar terhadap rata-rata 20 hari bursa sebelumnya (bebas lookahead).
3. **Exponential Moving Average (EMA-9, EMA-20, SMA-200):** Menentukan tren arah primer dan filter pullback (`Close > EMA20` dan `EMA9 > EMA20`).
4. **Relative Strength Index (RSI-14):** Mengidentifikasi zona momentum wajar (rentang 45.0 s.d. 65.0).
5. **Bar Close Position (Kualitas Penutupan Candle):**
   $$\text{Close Position} = \frac{\text{Close} - \text{Low}}{\text{High} - \text{Low}}$$
   Nilai berkisar 0,0 s.d. 1,0. Menunjukkan apakah candle ditutup di area tertinggi bar (tekanan beli dominan) atau area terendah bar (tekanan jual dominan).

### C. Logika Akumulasi Broker & VPA (`accumulation_scanner.py`)
Modul ini mengevaluasi struktur harga terhadap aliran volume:
1. **Deteksi Tekanan Jual Berat (Severe Distribution):**
   Kondisi: `(Chg 1D <= -3.5% and RVOL >= 1.5x) or (Close < EMA20 and Chg 3D <= -6.0%)`. Menghasilkan kategori "Tekanan Jual Dominan" dan diskualifikasi sinyal beli.
2. **Stabilitas Basis & Retraksi Sehat (Base Stability):** Menilai kedekatan harga terhadap level support teknikal dan jarak terhadap resistance.
3. **Penyerap Volume (Volume Absorption):** Menilai apakah lonjakan volume di dekat area support menghasilkan candlestick penolakan penurunan (rejection candle).

### D. Kalender Perdagangan & Aturan Eksekusi
- Mengacu pada `calendar_utils.py` yang memuat hari libur resmi BEI 2026.
- **Waktu Pembuatan Sinyal:** Pukul 16:00 WIB (Candle harian telah FINAL).
- **Waktu Eksekusi Skenario:** Pukul 09:00 WIB pada hari bursa berikutnya ($t+1$). Sinyal pada Jumat 16:00 WIB otomatis dijadwalkan ke Senin 09:00 WIB.
- **Filter Opening Gap:** Jika harga pembukaan $t+1$ melompat $> 2.0\%$ di atas harga penutupan $t$, sinyal dibatalkan demi menghindari pembelian di puncak anomali pre-opening.

---

## 3. JALUR C2 — ANALISIS BERITA PASAR DENGAN GEMINI 3.8 FLASH HIGH

Jalur C2 mengintegrasikan analisis wacana dan peristiwa makro/emiten menggunakan model bahasa berukuran besar **`ag/gemini-3.8-flash-high`** melalui endpoint OpenAI-compatible lokal (`http://127.0.0.1:20128/v1`).

### A. Pipeline 10 Tahap Analisis Berita
```
[ 1. Ingestion Feed ] ──► Pengambilan berita dari RSS resmi IDX / Portal Keterbukaan Informasi.
         │
[ 2. Sanitasi & URL ] ──► Pengecekan keunikan URL dan hash konten (anti-duplikasi).
         │
[ 3. Pemisahan Fakta] ──► Klasifikasi status: 'official_statement', 'verified_fact', 'unverified'.
         │
[ 4. Ticker Linking ] ──► Pencocokan kode emiten 4 huruf (regex `\b[A-Z]{4}\b` terdaftar BEI).
         │
[ 5. Sanitasi Context]──► Pemangkasan boilerplate HTML, penyusunan payload ringkas < 2.000 token.
         │
[ 6. Prompting LLM ]  ──► Injeksi ke prompt berversi (MARKET_INTELLIGENCE_DAILY v1.0.0).
         │
[ 7. JSON Validation] ──► Validasi skema ketat terhadap output JSON Gemini.
         │
[ 8. Audit DB Storage]──► Penyimpanan ke tabel `market_analyses` & `analysis_runs` di PostgreSQL.
         │
[ 9. Dashboard View ] ──► Penayangan di halaman /overview dan /news dengan label verifikasi.
         │
[ 10. Telegram Alert] ──► Pengiriman notifikasi ke @Info_BosKuu_bot jika berdampak material.
```

### B. Kontrak Output Inferensi AI (Wajib JSON)
Model AI diinstruksikan untuk tidak mengarang angka target harga atau proyeksi keuntungan. Respon wajib mematuhi skema:
```json
{
  "market_regime_summary": "Ringkasan kondisi pasar objektif 1-2 paragraf.",
  "impact_direction": "positive | negative | mixed | unclear",
  "impact_horizon": "intraday | multi_day | long_term",
  "confidence_score": 0.85,
  "impacted_tickers": [
    {
      "ticker": "MEDC",
      "rationale": "Kenaikan harga minyak acuan dunia WTI di atas $85/barel.",
      "support": 1485,
      "resistance": 1630
    }
  ],
  "verified_facts": [
    "Emiten mengumumkan jadwal pembagian dividen interim pada keterbukaan IDX."
  ],
  "unverified_points": [
    "Isu akuisisi blok migas baru yang masih berstatus rumor pasar."
  ],
  "risks": [
    "Volatilitas kurs rupiah terhadap dolar AS",
    "Pelemahan permintaan komoditas global"
  ],
  "unknowns": [
    "Besaran pasti belanja modal kuartal IV yang belum dipublikasikan"
  ]
}
```

*Catatan Kritis:* Sentimen berita positif **BUKAN sinyal beli otomatis**. Analisis AI bertindak sebagai lapisan konteks kualitatif yang harus diverifikasi silang dengan kondisi teknikal dan likuiditas pasar.

---

## 4. JALUR C3 — AUDIT MODEL PREDIKSI HARGA & MACHINE LEARNING

### A. Audit Forensik Model ML Terdahulu (`reports/evaluations/v3/`)
Pada penelitian V3 di repositori `E:\hermes-stock-agent`, telah dibangun dan diuji model prediktif supervised learning menggunakan 13.910 bar data harian (2021–2026):
1. **Model yang Diuji:** Logistic Regression (LR) dan Histogram-based Gradient Boosting (HistGradientBoosting / HGB).
2. **Fitur Input (26 Fitur Bebas Lookahead):** Return logaritmik 1D/3D/5D, Volatilitas ATR-14, RVOL-20, Rasio Volume Buyer/Seller, Posisi Candle Close, Jarak SMA-200.
3. **Target Prediksi:** Triple-Barrier Labeling ($y=1$ jika mencapai target 1.5x ATR sebelum stop loss 1.0x ATR dalam 5 hari bursa).
4. **Metode Validasi:** Walk-Forward Expanding Window 3-Fold (Train 2021–2023, Test 2024; Train 2021–2024, Test 2025; Train 2021–2025, Test 2025–2026).

### B. Temuan Kegagalan Statistik Model ML
Hasil pengujian out-of-sample (02 Januari 2025 s.d. 14 Oktober 2026, modal awal Rp500.000):
- **Logistic Regression:** ROC-AUC **0,5419**, Balanced Accuracy **0,5164**, Net Return **-67,99%**, Max Drawdown **71,21%**.
- **HistGradientBoosting:** ROC-AUC **0,5138**, Balanced Accuracy **0,5145**, Net Return **-80,84%**, Max Drawdown **80,88%**.
- **Date-Cluster Bootstrap (5.000 Replikasi):** 95% Confidence Interval return per trade Logistic Regression berada pada rentang **$[-2,04\%, -0,54\%]$**, dengan probabilitas ekspektasi positif $P(\text{Expectancy} > 0)$ hanya **0,06%**.

**Kesimpulan Ilmiah:** Model machine learning yang dilatih pada indikator teknikal murni tidak memiliki keunggulan prediktif (*no statistical edge*) di BEI. Hasil bruto yang tampak positif langsung terhapus oleh friksi transaksi ritel BEI (komisi total 0,40% dan spread fraksi tick). Oleh sebab itu, **seluruh model ML dilarang digunakan untuk live trading**.

### C. Riset Strategi Terkontrol (Controlled Experiments EXP-01 s.d. EXP-06)
Untuk mencari baseline aturan yang lebih robust tanpa manipulasi data (*anti data snooping*), dilakukan pengujian 6 hipotesis terkontrol pada periode out-of-sample yang sama:

| ID | Nama Strategi | Aturan Kunci | Trades | Win Rate | Profit Factor | Net Return | Max Drawdown | Status |
|:---:|:---|:---|:---:|:---:|:---:|:---:|:---:|:---:|
| **EXP-01** | Baseline Pullback (Kontrol) | EMA Trend + RSI 45-65 + RVOL $\ge$ 1.0 | 104 | 27,88% | 0,46 | -52,41% | 53,11% | Ditolak |
| **EXP-02** | Filter Makro SMA200 | Sinyal hanya jika Close > SMA200 | 65 | 21,54% | 0,29 | -55,07% | 55,59% | Ditolak |
| **EXP-03** | Filter Harga $\ge$ Rp1.000 | Menghindari saham murah fraksi besar | 82 | 24,39% | 0,37 | -58,10% | 59,34% | Ditolak |
| **EXP-04** | Konfirmasi Volume & Candle | RVOL $\ge$ 1.5 & Close Position $\ge$ 0.70 | **41** | **36,59%** | **0,58** | **-18,82%** | **21,00%** | **Kandidat Riset Forward** |
| **EXP-05** | Asymmetrical Payoff | Target 2.0x ATR / Stop 0.8x ATR | 106 | 21,70% | 0,43 | -55,81% | 56,83% | Ditolak |
| **EXP-06** | Horizon Singkat | Exit paksa hari ke-3 | 115 | 25,22% | 0,30 | -64,99% | 65,83% | Ditolak |

### D. Konfigurasi Forward Paper Trading yang Dibekukan
Strategi **EXP-04** terpilih mendampingi kontrol **EXP-01** dalam simulasi forward berjalan. Konfigurasi parameter dibekukan secara permanen pada `reports/evaluations/frozen_experiments_manifest.json` (SHA-256: `3f5d2ecd6551724926e623b1adab2aa5bf3c3c2499f4d20c80ad5e8c55be0b44`):
- Modal awal virtual: Rp500.000,00 per portofolio.
- Maksimal posisi serentak: 5 saham (alokasi 20% kas per transaksi).
- Fraksi lot bulat: 100 lembar saham.
- Dilarang mengubah threshold parameter di tengah jalan (*anti forward data snooping*).

---

## 5. KLARIFIKASI DEFINISI: TRAINING VS INFERENCE VS AI REASONING

| Istilah | Definisi Operasional di Sistem Hermes | Karakteristik Beban Komputasi |
|---|---|---|
| **Model Training** | Proses penyesuaian bobot matematis model ML (misal Logistic Regression) menggunakan dataset historis masa lalu di disk. | CPU/RAM intensif, dijalankan offline di Python. |
| **Model Inference** | Perhitungan probabilitas atau sinyal dari model yang sudah dilatih terhadap bar data pasar terkini. | Sangat cepat (< 10 ms per saham). |
| **AI News Analysis** | Evaluasi wacana teks dan penalaran dampak berita oleh model bahasa besar (`ag/gemini-3.8-flash-high`) via prompt terstruktur. **Bukan training ulang bobot model.** | Panggilan HTTP I/O ke 9router lokal (:20128). |
| **Backtesting** | Rekonstruksi simulasi portofolio di masa lalu dengan mematuhi batasan kas, fraksi lot, slippage, dan waktu pasar diskrit. | Dijalankan per batch di `daily_eod_backtester.py`. |
| **Forward Paper Trading**| Pelacakan performa strategi secara forward hari demi hari pada data baru yang belum pernah dilihat saat riset dibuat, tanpa modal nyata. | Dijalankan setiap hari bursa via `forward_paper_runner.py`. |

---

## 6. AUDIT TRAIL, KETERLACAKAN, DAN POST-HORIZON BENCHMARKING

Setiap inferensi AI dan sinyal pasar yang terbentuk wajib mencatat metadata berikut di PostgreSQL:
1. `run_id`: UUID unik setiap eksekusi batch (misal `RUN_20261016_160500_a1b2`).
2. `bar_date`: Tanggal candle resmi yang dianalisis.
3. `source_timestamp`: Waktu rilis data dari Yahoo Finance atau feed berita.
4. `prompt_version`: Versi template prompt yang digunakan (misal `MARKET_INTELLIGENCE_DAILY v1.0.0`).
5. `model_id`: Identifier model resmi (`ag/gemini-3.8-flash-high`).
6. `message_hash`: Hash SHA-256 payload notifikasi untuk pencegahan duplikasi pesan Telegram.

### Post-Horizon Evaluation:
Setelah melewati horizon analisis (3 hari bursa untuk berita intraday, 5 hari bursa untuk sinyal swing EOD), modul evaluator membandingkan:
$$\Delta P = \frac{\text{Close}_{t+5} - \text{Close}_{t}}{\text{Close}_{t}} \times 100\%$$
terhadap `impact_direction` AI untuk mengukur akurasi arah penalaran kualitatif tanpa bias konfirmasi.
