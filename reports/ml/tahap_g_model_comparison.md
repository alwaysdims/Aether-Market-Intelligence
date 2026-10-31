# LAPORAN PERBANDINGAN MODEL MACHINE LEARNING (FASE G5)
## AETHER MARKET INTELLIGENCE

**Tanggal Rilis:** 25 Oktober 2026  
**Metodologi:** Evaluasi out-of-sample holdout test (2025-01-09 s.d. 2026-10-16, 4.187 bar)  
**Target:** Arah Return 5 Hari Bursa Forward ($(Close_{t+5} - Close_t) / Close_t > 0$)  
**Status Audit:** VERIFIED & DETERMINISTIC

---

### 1. Tabel Komparasi Utama (Benchmark vs Model Machine Learning)

| Metrik Evaluasi | Majority Baseline | Logistic Regression | HistGradientBoosting | Random Forest (100 Trees) |
|---|:---:|:---:|:---:|:---:|
| **Test Accuracy** | **54.84%** (Majority Down) | 51.35% | 51.97% | **53.09%** |
| **Balanced Accuracy** | 50.00% | 49.19% | 50.39% | **50.61%** |
| **Precision (Kelas Naik / 1)** | 0.00% (Selalu Down) | 45.41% | 46.32% | **48.85%** |
| **Recall (Kelas Naik / 1)** | 0.00% | 98.57% | 96.67% | 91.24% |
| **F1-Score** | 0.0000 | 0.6225 | 0.6263 | **0.6361** |
| **ROC-AUC** | 0.5000 | 0.4966 | 0.5031 | **0.5060** |
| **PR-AUC** | 0.4516 | 0.4502 | 0.4527 | **0.4612** |
| **Brier Score** | 0.2476 | 0.2520 | 0.2541 | **0.2498** |
| **Jumlah Trade Riil (Simulator)** | 0 trade | 157 trade | 151 trade | 148 trade |
| **Win Rate Perdagangan Riil** | N/A | 33.76% | 32.45% | **35.12%** |
| **Portfolio Return Riil (IDR 500k)** | -10.58% (Buy & Hold IHSG) | -75.29% | -73.27% | **-62.40%** |
| **Max Portfolio Drawdown** | 16.42% (IHSG) | 75.29% | 73.27% | **64.12%** |
| **Ekuitas Akhir Portofolio** | N/A | Rp123.552,46 | Rp133.668,49 | **Rp187.980,10** |

---

### 2. Kinerja Per Emiten (Random Forest Model)

| Ticker | Sektor | Jumlah Sampel | Akurasi (%) | ROC-AUC | Catatan Perilaku |
|---|---|:---:|:---:|:---:|---|
| **ARTO** | Digital Bank / High Beta | 419 | **62.77%** | **0.6054** | Volatilitas tinggi memberikan sinyal momentum lebih tajam |
| **SMRA** | Properti | 419 | **58.47%** | 0.4973 | Sering terperangkap tren menyamping |
| **MAPI** | Ritel Konsumen | 419 | **56.09%** | **0.5763** | Pola mean-reversion cukup terdeteksi |
| **BBCA** | Bank Konvensional | 416 | 52.40% | 0.4769 | Pergerakan sangat stabil, noise tinggi pada 5-hari |
| **BMRI** | Bank BUMN | 419 | 52.03% | 0.5050 | Mengikuti IHSG secara umum |
| **ASII** | Otomotif / Konglomerasi | 419 | 51.79% | 0.5282 | Sinyal siklikal moderat |
| **BBNI** | Bank BUMN | 419 | 51.31% | 0.4980 | Noise tinggi |
| **BBRI** | Bank Mikro | 419 | 49.88% | 0.4841 | Tren turun 2025-2026 menekan akurasi model long-bias |
| **MEDC** | Energi / Migas | 419 | 48.45% | 0.4696 | Sangat dipengaruhi harga komoditas global, bukan teknikal |
| **TLKM** | Telekomunikasi | 419 | 47.73% | 0.4231 | Underperformance konsisten |

---

### 3. Kinerja Per Rezim Pasar (Market Regime Breakdown)

| Rezim Pasar | Kriteria Penentu | Jumlah Sampel | Akurasi Model | ROC-AUC | Kesimpulan Kuantitatif |
|---|---|:---:|:---:|:---:|---|
| **BULL REGIME** | IHSG > EMA-20 | 2.347 (56,1%) | **53.39%** | **0.5243** | Model memiliki sedikit keunggulan prediktif ketika pasar sedang dalam tren menguat |
| **BEAR REGIME** | IHSG $\le$ EMA-20 | 1.840 (43,9%) | 52.72% | 0.4866 | Pada pasar melemah, model kehilangan daya prediksi (AUC < 0.50) akibat false breakout |

---

### 4. Kesimpulan Evaluasi Model

1. **Model Belum Mengungguli Baseline Naif:**
   Akurasi tertinggi yang dicapai model (53,09% oleh Random Forest) masih berada di bawah akurasi *Majority Down Baseline* (54,84%).
2. **Friksi Bursa Mendominasi:**
   Dalam simulasi perdagangan riil dengan biaya bursa BEI (0,40% round-trip) dan slippage 1 tick, seluruh model mengalami penurunan ekuitas (-62% hingga -75%). Tanpa filter selektif yang ketat, frekuensi trading yang tinggi menghancurkan modal akibat biaya transaksi.
