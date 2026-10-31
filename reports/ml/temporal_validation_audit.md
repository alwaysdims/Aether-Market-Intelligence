# AUDIT VALIDASI TEMPORAL & DATA LEAKAGE (FASE G2)
## AETHER MARKET INTELLIGENCE

**Tanggal Audit:** 25 Oktober 2026  
**Status Audit:** VERIFIED & COMPLIANT  
**Klasifikasi Bukti:** VERIFIED (Kode deterministik dan verifikasi kalender bursa)

---

### 1. Eliminasi Walk-Forward Fold Overlap

Pada audit awal `quant-engine/src/walk_forward_evaluator.py`, terdeteksi bahwa Fold 2 (uji sepanjang tahun 2025) dan Fold 3 (uji Juli 2025 s.d. Oktober 2026) memiliki tumpang tindih periode uji selama 6 bulan (1 Juli – 31 Desember 2025). Akibatnya, sinyal dan tanggal pada H2 2025 dihitung ganda sebagai dua observasi independen.

**Solusi & Konfigurasi Baru (100% Non-Overlapping):**
- **Fold 1:**
  * Train: `2021-01-01` s.d. `2022-12-31` (2 tahun)
  * Validation: `2023-01-01` s.d. `2023-12-31` (1 tahun)
  * Test: `2024-01-01` s.d. `2024-12-31` (1 tahun penuh)
- **Fold 2:**
  * Train: `2021-01-01` s.d. `2023-12-31` (3 tahun, expanding window)
  * Validation: `2024-01-01` s.d. `2024-12-31` (1 tahun)
  * Test: `2025-01-01` s.d. `2025-12-31` (1 tahun penuh)
- **Fold 3:**
  * Train: `2021-01-01` s.d. `2024-12-31` (4 tahun, expanding window)
  * Validation: `2025-01-01` s.d. `2025-12-31` (1 tahun)
  * Test: `2026-01-01` s.d. `2026-10-23` (10 bulan berjalan)

**Hasil Verifikasi Non-Overlapping Walk-Forward:**
```text
 fold | strategy              | test_period                | executed_trades | win_rate_pct | profit_factor | portfolio_return_pct | max_drawdown_pct
    1 | Baseline Trend        | 2024-01-01 to 2024-12-31   |              58 |        25.86 |          0.30 |               -40.22 |            41.45
    1 | Logistic Regression   | 2024-01-01 to 2024-12-31   |              96 |        32.29 |          0.51 |               -41.06 |            47.28
    1 | HistGradientBoosting  | 2024-01-01 to 2024-12-31   |              76 |        31.58 |          0.43 |               -41.96 |            43.40
    2 | Baseline Trend        | 2025-01-01 to 2025-12-31   |              60 |        31.67 |          0.45 |               -36.48 |            38.87
    2 | Logistic Regression   | 2025-01-01 to 2025-12-31   |             125 |        35.20 |          0.67 |               -35.40 |            38.64
    2 | HistGradientBoosting  | 2025-01-01 to 2025-12-31   |             107 |        28.97 |          0.53 |               -52.63 |            52.88
    3 | Baseline Trend        | 2026-01-01 to 2026-10-23   |              60 |        30.00 |          0.46 |               -30.79 |            33.18
    3 | Logistic Regression   | 2026-01-01 to 2026-10-23   |              87 |        21.84 |          0.32 |               -59.63 |            61.46
    3 | HistGradientBoosting  | 2026-01-01 to 2026-10-23   |              93 |        32.26 |          0.47 |               -48.87 |            50.30
```
*Total hari bursa yang dihitung ganda: 0 hari (0%).*

---

### 2. Audit Embargo 5 Hari Bursa & Kebocoran Label (Label Boundary Leakage)

1. **Aturan Embargo:**
   Karena label prediksi menggunakan horizon 5 hari bursa forward ($t+5$), sinyal yang muncul pada $t-4$ sampai $t$ di akhir partisi Train akan meregangkan evaluasi harganya ke periode Validation jika tidak di-embargo.
2. **Pencegahan pada Kode:**
   - Pada `walk_forward_evaluator.py`:
     ```python
     train_s = [s for s in all_setups if s["signal_time"].date() <= tr_end and s["exit_time"].date() <= tr_end]
     ```
     Kondisi `s["exit_time"].date() <= tr_end` secara ketat membuang seluruh transaksi yang tanggal keluarnya melewati akhir periode Train.
   - Pada `ml_experiment_engine.py`:
     Embargo eksplisit 5 hari bursa disisipkan di antara partisi:
     * Embargo 1: `2023-12-30` s.d. `2024-01-08` (5 hari bursa dibuang)
     * Embargo 2: `2024-12-31` s.d. `2025-01-08` (5 hari bursa dibuang)

---

### 3. Audit Pra-Pemrosesan (Scaling, Imputation, Thresholding)

| Komponen | Status | Verifikasi Implementasi |
|---|---|---|
| Imputasi Nilai Hilang | Lulus (Bebas Leakage) | Median dihitung hanya pada `X_train`; nilai median Train digunakan untuk mengisi NaN pada Validation dan Test |
| Standard Scaler | Lulus (Bebas Leakage) | `scaler.fit(X_train)` dijalankan secara independen; Test hanya menerima `scaler.transform(X_test)` |
| Penentuan Threshold | Lulus (Bebas Leakage) | Ambang batas probabilitas model diteliti dan dikalibrasi pada Validation set, dibekukan sebelum diterapkan ke Test set |
| Fitur Berita | Lulus (Point-in-Time) | Disaring dengan `published_at <= decision_date 16:00 WIB` |
| Corporate Actions | Lulus | yfinance split adjustments dievaluasi konsisten di seluruh baris; tidak ada jump buatan |
