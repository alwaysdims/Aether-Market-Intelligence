# AUDIT DAN REPRODUKSI HASIL MACHINE LEARNING LAMA
## AETHER MARKET INTELLIGENCE

**Tanggal Audit:** 2026-10-24 23:34:38 WIB  
**Dokumen Referensi:** `quant-engine/reports/evaluations/daily_eod_report.md`  
**Engine Kode:** `quant-engine/src/daily_eod_backtester.py` & `quant-engine/src/walk_forward_evaluator.py`  
**Dataset Acuan:** 10 Saham Likuid BEI (`quant-engine/data/raw_daily/`, 2021-01-04 s.d. 2026-10-14)  
**Status Audit:** `VERIFIED & RECONCILED`

---

## 1. Pemetaan Script, Dataset, Fitur, dan Label Lama

| Komponen | File / Modul | Deskripsi Implementasi |
| :--- | :--- | :--- |
| **Pipeline Fitur** | `src/daily_eod_backtester.py` (`compute_daily_features`) | 12 fitur teknikal: `ret_1, ret_3, ret_5, ema9_ratio, ema20_ratio, ema_spread, rsi14, atr14, atr14_ratio, rvol20, dist_support20, dist_resistance20, volatility20`. |
| **Definisi Label** | `src/daily_eod_backtester.py` (`extract_daily_setups`) | **Triple-Barrier / TP-before-SL:** Target = Entry + 2*ATR, Stop = Entry - 1*ATR dalam horizon 5 hari bursa. Label = 1.0 jika target disentuh sebelum stop; 0.0 jika stop disentuh, expired, atau gap down. |
| **Model ML 1** | `LogisticRegression(C=0.1, max_iter=500, random_state=42)` | Model linear dengan standard scaling dan seleksi threshold top 20% quantile (80th percentile) pada set validasi. |
| **Model ML 2** | `HistGradientBoostingClassifier(max_iter=60, min_samples_leaf=30, random_state=42)` | Model tree non-linear dengan seleksi threshold top 20% quantile pada set validasi. |
| **Simulator Portofolio** | `DailyPortfolioSimulator` | Modal Rp500.000, alokasi 20% per trade (Rp100.000), lot sizing BEI 100 lembar, fee beli 0.15%, fee jual 0.25%, slippage 1 tick. |

---

## 2. Rekonsiliasi Ketidaksesuaian Tabel Lama vs Hasil Reproduksi

### A. Tabel Lama pada `daily_eod_report.md` (Catatan Pra-Perbaikan):
- Baseline: 11 closed trades, win rate **45,45%** (5 win / 11)
- Logistic Regression: 8 closed trades, win rate **37,50%** (3 win / 8)
- HistGradientBoosting: 2 closed trades, win rate **0,00%** (0 win / 2)

### B. Penyebab Sebenarnya (Root Cause):
1. **Bukan Akurasi Arah Seluruh Prediksi:**  
   Angka "win rate 37,50%" dan "0,00%" tersebut **bukanlah akurasi arah** prediksi model secara umum. Model Logistic Regression sebenarnya mendeteksi **932 sinyal** dan HistGradientBoosting mendeteksi **914 sinyal** pada periode test (2025–2026).
2. **Kendala Modal Mikro & Lot Sizing BEI:**  
   Modal awal simulasi adalah Rp500.000 dengan batas alokasi Rp100.000 per posisi. Saham berharga >Rp1.000 (seperti BBCA Rp5.000–Rp10.000, BMRI Rp3.500–Rp7.000, BBNI Rp3.000–Rp5.500) membutuhkan minimal Rp300.000–Rp1.000.000 per 1 lot (100 lembar). Akibatnya, lebih dari **600 sinyal ditolak** karena dana tidak mencukupi (`INSUFFICIENT_FUNDS`).
3. **Bug Inversi Event Bar yang Sama (Telah Diperbaiki):**  
   Pada kode awal sebelum commit `40fd2f2`, event keluar dieksekusi sebelum event masuk pada bar yang sama, sehingga kas terkunci secara semu selama 1,8 tahun dan hanya menyisakan 11, 8, dan 2 trade yang sempat tertutup.

### C. Hasil Reproduksi Terkini (Setelah Perbaikan Event Accounting):
```text
Strategi                         | Sinyal | Dieksekusi | Ditolak Dana | Win Rate | Net PnL (IDR) | Total Return | Max Drawdown
-------------------------------- | :----: | :--------: | :----------: | :------: | :-----------: | :----------: | :-----------
1. Baseline Trend Pullback       | 443    | 104        | 249          | 26.92%   | -Rp262.053    | -52.41%      | 53.11%
2. Daily Logistic Regression     | 932    | 202        | 576          | 27.23%   | -Rp339.972    | -67.99%      | 71.21%
3. Daily HistGradientBoosting    | 914    | 159        | 605          | 25.16%   | -Rp404.192    | -80.84%      | 80.88%
```
*Temuan Utama:* Tanpa filter tren pasar dan manajemen risiko ketat, mengeksekusi seluruh sinyal model ML murni menghasilkan kerugian besar akibat biaya friksi transaksi ritel BEI (-52% s.d. -80%). Inilah alasan mengapa penelitian beralih ke eksperimen beku EXP-04 yang membatasi drawdown.

---

## 3. Metrik Klasifikasi Machine Learning Murni (Out-of-Sample Test Set)

Metrik performa klasifikasi biner terhadap label triple-barrier pada set uji (4.160 observasi test):

```text
Metrik Evaluasi                | Logistic Regression (LR) | HistGradientBoosting (HGB)
------------------------------ | :----------------------: | :-------------------------:
Total Sampel Test              | 4.160                    | 4.160
Positive Labels (Actual Hits)  | 819 (19.69%)             | 819 (19.69%)
Negative Labels (Loss/Expired) | 3.341 (80.31%)           | 3.341 (80.31%)
Threshold Probabilitas         | >= 0.2454 (Top 20% Val)  | >= 0.2709 (Top 20% Val)
True Positives (TP)            | 205                      | 199
False Positives (FP)           | 727                      | 715
True Negatives (TN)            | 2.614                    | 2.626
False Negatives (FN)           | 614                      | 620
Precision (Selective)          | 22.00%                   | 21.77%
Recall                         | 25.03%                   | 24.30%
F1-Score                       | 0.2342                   | 0.2297
Balanced Accuracy              | 51.64%                   | 51.45%
ROC-AUC                        | 0.5419                   | 0.5138
PR-AUC                         | 0.2272                   | 0.2142
Brier Score (Kalibrasi)        | 0.1587                   | 0.1662
```

*Analisis Kritis:*
1. **Edge Prediktif Lemah:** ROC-AUC model LR (0.5419) dan HGB (0.5138) hanya sedikit berada di atas tebakan acak (0.5000).
2. **Selective Precision Rendah:** Mengambil top 20% probabilitas tertinggi hanya menghasilkan presisi 22.00% (LR) dan 21.77% (HGB), di mana baseline prevalensi positif acak adalah 19.69%. Model hanya memberikan peningkatan marjinal +2.3% di atas distribusi acak.

---

## 4. Audit Tumpang Tindih Fold Walk-Forward (Fold Overlap)

Pada `quant-engine/src/walk_forward_evaluator.py`, konfigurasi fold yang lama adalah:
- **Fold 1:** Train 2021–2022 | Val 2023 | Test: **2024-01-01 s.d. 2024-12-31**
- **Fold 2:** Train 2021–2023 | Val 2024 | Test: **2025-01-01 s.d. 2025-12-31**
- **Fold 3:** Train 2021–2024 | Val 2025-01–2025-06 | Test: **2025-07-01 s.d. 2026-10-14**

### Temuan Pelanggaran Independensi:
1. **Overlap Fold 2 & Fold 3:**  
   Periode **1 Juli 2025 s.d. 31 Desember 2025 (132 hari bursa)** berada di dalam Test Set Fold 2 DAN sekaligus di dalam Test Set Fold 3.
2. **Kebocoran Validasi:**  
   Periode 1 Januari 2025 s.d. 30 Juni 2025 berada di Test Set Fold 2, tetapi dipakai sebagai Validation Set pada Fold 3.
3. **Koreksi Wajib:**  
   Sinyal pada periode tumpang tindih ini tidak boleh diagregasi sebagai observasi independen. Desain eksperimen baru wajib menerapkan pembagian partisi non-overlapping dengan *purging & embargo* 5 hari bursa antar-split.

---

## 5. Audit Kebocoran Data (Data Leakage Checklist)

1. **Fitur Masa Depan (Future Lookahead):** `PASS` (Bebas).  
   Seluruh 12 fitur teknikal harian dihitung menggunakan operator rolling/ewm ke belakang strictly dari bar t.
2. **Kebocoran Label Menembus Batas Split:** `PASS` (Bebas pada train_setups).  
   Kode membuang setup yang tanggal keluarnya (`exit_time`) menembus `train_end` atau `val_end`.
3. **Scaling dan Imputasi Global:** `PASS` (Bebas).  
   Median kolom dan `StandardScaler` di-fit strictly pada `X_train`, kemudian hanya di-transform pada validation dan test set.
4. **Pemilihan Threshold Memakai Test Set:** `PASS` (Bebas).  
   Ambang batas seleksi probabilitas dihitung dari persentil 80 pada set validasi, lalu dibekukan sebelum diaplikasikan ke test set.
5. **Survivorship Bias:** `IDENTIFIED` (Ada bias seleksi).  
   10 saham dipilih berdasarkan status likuiditas masa kini (2026), lalu diuji mundur hingga 2021. Saham yang delisting atau bangkrut antara 2021–2026 tidak ada di universe.
