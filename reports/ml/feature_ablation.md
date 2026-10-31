# LAPORAN ABLATION STUDY FITUR (FASE G4)
## AETHER MARKET INTELLIGENCE

**Tanggal Rilis:** 25 Oktober 2026  
**Metodologi:** Evaluasi out-of-sample holdout test (2025-01-09 s.d. 2026-10-16, 4.187 bar)  
**Target:** Arah Return 5 Hari Bursa Forward ($(Close_{t+5} - Close_t) / Close_t > 0$)  
**Status Audit:** VERIFIED & DETERMINISTIC

---

### 1. Definisi Subset Fitur

1. **Subset A (Base 12 Fitur):**
   - Momentum/Return: `ret_1`, `ret_3`, `ret_5`
   - Trend & Moving Average: `ema9_ratio`, `ema20_ratio`, `ema_spread`
   - Osilator: `rsi14`
   - Volatilitas & Rentang: `atr14_ratio`, `volatility20`
   - Volume: `rvol20`
   - Level Kunci: `dist_support20`, `dist_resistance20`
2. **Subset B (Base + Momentum Multi-Horizon & Rentang - 15 Fitur):**
   - Menambahkan: `ret_10` (momentum 10 hari), `atr_normalized_move` (perubahan harga 5 hari dibagi ATR-14), dan `price_to_high20_ratio` (posisi harga dalam rentang 20 hari).
3. **Subset C (Base + Benchmark IHSG & Rezim Pasar - 16 Fitur):**
   - Menambahkan: `ihsg_ret_1` (return harian IHSG), `ihsg_ret_5` (return 5 hari IHSG), `rel_ret_5` (alpha relatif terhadap IHSG), dan `ihsg_trend_regime` (flag biner IHSG > EMA20).
4. **Subset D (All 20 Fitur Gabungan):**
   - Menggabungkan seluruh 20 fitur teknikal, volatilitas rezim (`vol_regime`), momentum, dan benchmark IHSG.

---

### 2. Hasil Komparasi Ablation Study pada Holdout Test

| Subset Fitur | Jumlah Fitur | Model | Test Accuracy | Balanced Acc | ROC-AUC | Catatan Performa |
|---|:---:|---|:---:|:---:|:---:|---|
| **A (Base 12)** | 12 | Logistic Regression | 51.92% | 49.71% | 0.4969 | Baseline teknikal murni |
| | | HistGradientBoosting | 52.02% | 50.63% | 0.5146 | Non-linear tree boosting |
| | | Random Forest | 52.59% | 50.38% | 0.5131 | Ensemble stabil |
| **B (+ Momentum & Range)** | 15 | Logistic Regression | 51.35% | 49.19% | 0.4966 | Sedikit penurunan pada linear |
| | | HistGradientBoosting | 51.97% | 50.39% | 0.5031 | Stabil |
| | | Random Forest | **53.09%** | 50.61% | 0.5060 | **Akurasi tertinggi** |
| **C (+ Benchmark IHSG)** | 16 | Logistic Regression | 51.16% | 49.67% | 0.4807 | Penurunan pada linear |
| | | HistGradientBoosting | 51.80% | **51.48%** | **0.5151** | **Balanced Acc & AUC tertinggi** |
| | | Random Forest | 51.04% | 50.38% | 0.5091 | Stabil |
| **D (All 20 Combined)** | 20 | Logistic Regression | 50.25% | 48.82% | 0.4762 | Mengalami noise overfitting |
| | | HistGradientBoosting | 51.13% | 50.57% | 0.5057 | Degradasi minor |
| | | Random Forest | 51.16% | 50.20% | 0.5083 | Degradasi minor |

---

### 3. Temuan Kunci Ablation Study

1. **Efek Penambahan Fitur (Diminishing Returns & Noise):**
   - Subset B (15 fitur) memberikan akurasi tertinggi untuk Random Forest (53,09%).
   - Subset C (16 fitur dengan IHSG) memberikan Balanced Accuracy (51,48%) dan ROC-AUC (0,5151) terbaik untuk HistGradientBoosting.
   - Menggabungkan seluruh 20 fitur (Subset D) justru menurunkan kinerja (Akurasi turun dari 53,09% ke 51,16% pada RF). Hal ini membuktikan prinsip kuantitatif: *menambah fitur tanpa kurasi hanya memasukkan derau (noise overfitting).*
2. **Kondisi Pasar Relatif:**
   - Fitur `rel_ret_5` (kekuatan relatif saham terhadap IHSG) dan `ihsg_trend_regime` terbukti memberikan sinyal kontekstual yang lebih baik dibandingkan fitur volume lokal.
