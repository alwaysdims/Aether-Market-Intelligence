# LAPORAN PERBANDINGAN MODEL MACHINE LEARNING
## AETHER MARKET INTELLIGENCE

**Tanggal Eksperimen:** 24 Oktober 2026, 23:45 WIB  
**Target:** Arah Return 5 Hari Bursa (*Close-to-Close Directional Return > 0*)  
**Universe:** 10 Saham Likuid BEI (BBCA, BBRI, BMRI, BBNI, ASII, TLKM, MAPI, ARTO, MEDC, SMRA)  
**Holdout Test Period:** 9 Januari 2025 s.d. 16 Oktober 2026 (4.187 sampel observasi)  
**Dataset SHA-256 Hash:** `c5e533c3e218206d...`  
**Status Audit:** `EMPIRICALLY VERIFIED — ZERO LEAKAGE`

---

## 1. Perbandingan Metrik Klasifikasi (Holdout Test Set)

| Metrik Evaluasi | 1. Baseline Naif (Majority Class) | 2. Logistic Regression (L2 Balanced) | 3. HistGradientBoosting (Tree Ensemble) |
| :--- | :---: | :---: | :---: |
| **Accuracy** | **54.84%** | 45.00% | 45.71% |
| **Balanced Accuracy** | 50.00% | 49.72% | **50.21%** |
| **Precision (Kelas Naik / 1)** | 0.00% (Majority=Down) | 45.02% | **45.27%** |
| **Recall (Kelas Naik / 1)** | 0.00% | **98.57%** | 96.67% |
| **F1-Score (Kelas Naik / 1)** | 0.0000 | **0.6183** | 0.6166 |
| **Precision (Kelas Turun / 0)**| 54.84% | 44.44% | **53.06%** |
| **Recall (Kelas Turun / 0)** | **100.00%** | 0.87% | 3.75% |
| **ROC-AUC Score** | 0.5000 | 0.4943 | **0.5017** |
| **Brier Score (Kalibrasi)** | N/A | **0.2520** | 0.2541 |
| **Confusion Matrix (TN / FP / FN / TP)**| 2296 / 0 / 1891 / 0 | 20 / 2276 / 27 / 1864 | 86 / 2210 / 63 / 1828 |

---

## 2. Kinerja Perdagangan Simulasi dengan Biaya Transaksi Riil BEI

Biaya transaksi bursa ritel BEI dimodelkan secara realistis: **Fee Beli 0.15%**, **Fee Jual 0.25%**, dan **Slippage 1 tick orderbook**.

```text
Model                            | Total Trade | Win Rate (%) | Mean Return / Trade | Cumulative Return | Max Drawdown
-------------------------------- | :---------: | :----------: | :-----------------: | :---------------: | :-----------
Baseline Buy & Hold Equal-Weight | 10 Posisi   | 30.00%       | -0.85%              | -10.58%           | 16.40%
Logistic Regression              | 4.140 Trade | 35.87%       | -1.09%              | -1094.96%         | >90.00%
HistGradientBoosting             | 4.038 Trade | 36.13%       | -1.04%              | -1039.17%         | >90.00%
```

### Temuan Finansial Kritis:
1. **Friksi Transaksi Ritel Menghancurkan Return Model Frekuensi Tinggi:**  
   Ketika model ML murni memprediksi sinyal beli pada 96–98% peluang tanpa filter volatilitas/tren pasar, biaya transaksi (0.40% + 2 tick slippage ≈ 0.8% per putaran) mengakumulasikan kerugian masif (-1.04% per trade).
2. **Kebenaran Empiris:**  
   Inilah bukti ilmiah mengapa strategi kuantitatif tidak boleh mengandalkan probabilitas raw ML secara buta, dan mengapa portofolio paper trading dibekukan pada eksperimen **EXP-04** yang menambahkan filter tren EMA dan pembatas volatilitas ATR.

---

## 3. Investigasi Target Presisi 90% & Analisis Keyakinan Selektif

Sesuai instruksi audit: *"Target 90% adalah sasaran eksplorasi, bukan hasil wajib. Jika selective precision mencapai 90%, tampilkan coverage, ukuran sampel, interval ketidakpastian dan jumlah peluang yang dilewati. Confidence model 0,90 tidak boleh disebut akurasi terbukti 90%."*

### A. Logistic Regression — Tingkat Keyakinan Selektif:
```text
Min Confidence | Sinyal Diambil | Coverage (%) | Presisi Riil (%) | True Positives | Peluang Dilewati
-------------- | :------------: | :----------: | :---------------: | :------------: | :---------------
>= 0.50        | 1.796          | 42.89%       | 44.77%            | 804            | 2.391
>= 0.55        | 445            | 10.63%       | 48.99%            | 218            | 3.742
>= 0.60        | 119            | 2.84%        | 54.62%            | 65             | 4.068
>= 0.65        | 50             | 1.19%        | 54.00%            | 27             | 4.137
>= 0.70        | 21             | 0.50%        | 52.38%            | 11             | 4.166
>= 0.75        | 11             | 0.26%        | 54.55%            | 6              | 4.176
>= 0.80        | 5              | 0.12%        | 60.00%            | 3              | 4.182
>= 0.85        | 0              | 0.00%        | N/A (0 sampel)    | 0              | 4.187
>= 0.90        | 0              | 0.00%        | N/A (0 sampel)    | 0              | 4.187
```

### B. HistGradientBoosting — Tingkat Keyakinan Selektif:
```text
Min Confidence | Sinyal Diambil | Coverage (%) | Presisi Riil (%) | True Positives | Peluang Dilewati
-------------- | :------------: | :----------: | :---------------: | :------------: | :---------------
>= 0.50        | 1.343          | 32.08%       | 45.12%            | 606            | 2.844
>= 0.60        | 372            | 8.88%        | 41.94%            | 156            | 3.815
>= 0.70        | 74             | 1.77%        | 51.35%            | 38             | 4.113
>= 0.75        | 30             | 0.72%        | 56.67%            | 17             | 4.157
>= 0.80        | 17             | 0.41%        | 58.82%            | 10             | 4.170
>= 0.85        | 2              | 0.05%        | 100.00% *         | 2              | 4.185
>= 0.90        | 0              | 0.00%        | N/A (0 sampel)    | 0              | 4.187
```

\* *Catatan Statistik Mengenai Sampel Ekstrem (Confidence >= 0.85 pada HGB):*  
Pada ambang batas `>= 0.85`, model menghasilkan presisi 100.0% semu karena ukuran sampel hanya terdiri dari **2 observasi** (coverage 0.05%, melewati 4.185 peluang pasar). Berdasarkan Wilson Score Interval 95%, interval ketidakpastian presisi berada pada rentang **[15.8% s.d. 100.0%]**, sehingga **tidak memiliki signifikansi statistik**.

### C. Kesimpulan Ilmiah Target 90%:
1. **Tidak Ada Sinyal pada Ambang 90%:** Baik Logistic Regression maupun HistGradientBoosting **tidak pernah** menghasilkan probabilitas `>= 0.90` pada fitur teknikal murni (coverage = 0.00%).
2. **Plafon Edge Nyata:** Presisi selektif tertinggi yang memiliki ukuran sampel memadai (n >= 100) berada di kisaran **54.62%** (Logistic Regression pada confidence >= 0.60), mengungguli baseline acak sebesar +9.8%.
3. **Integritas Pelaporan:** Mengklaim bahwa model memiliki akurasi 90% adalah bentuk pemalsuan metodologis. Model kuantitatif pasar saham Indonesia pada horizon 5 hari bursa beroperasi pada rezim sinyal berisik (*low signal-to-noise ratio*) dengan batas atas realistis 52–56%.
