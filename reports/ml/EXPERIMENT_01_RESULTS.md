# LAPORAN EKSPERIMEN MACHINE LEARNING: EXP-01
**Evaluasi Prediksi Arah Return 5 Hari Bursa BEI (Multi-Day Horizon)**
**Aether Market Intelligence — Subdivisi Riset Kuantitatif & ML Terisolasi**
**Tanggal Rilis:** 28 Oktober 2026  
**Status Eksperimen:** SELESAI (AUDITED, DETERMINISTIC, NO DATA LEAKAGE)  
**Keputusan Promosi:** **REJECTED / TIDAK MEMENUHI SYARAT PROMOSI** (Model Tetap di Sandbox Riset, Model Produksi Tidak Berubah)

---

## 1. Ringkasan Eksekutif & Hasil Utama

Eksperimen pertama (EXP-01) dirancang dan dieksekusi secara ketat mengikuti protokol `reports/ml/NEXT_EXPERIMENT_PLAN.md`:
- **Tujuan Eksperimen:** Menguji apakah model ensemble pohon keputusan (*Random Forest* v1.0 dan *HistGradientBoosting*) serta model regresi (*Logistic Regression L2*) mampu mengungguli baseline naif mayoritas kelas dalam memprediksi arah pergerakan harga 5 hari bursa ($Close_{t+5} > Close_t$) pada universe 10 saham likuid BEI.
- **Dataset Snapshot:** Dibekukan secara independen dari berkas raw immutable (`E:\Aether-Market-Intelligence\data\raw_immutable\*_raw_2026-10-23.csv`) ke dalam SQLite dan CSV snapshot terverifikasi (`idx_top10_features_v1.sqlite`, SHA-256: `b1c92704590e177a...`, total 13.736 bar tervalidasi).
- **Protokol Pemisahan Waktu:**
  * **Train Set:** 2021-02-01 s.d. 2023-12-20 (7.030 bar).
  * **Purged Train Gap:** 5 hari bursa terakhir 2023 (50 bar dibuang untuk mencegah tumpang tindih outcome ke set validasi).
  * **Validation Set:** 2024-01-01 s.d. 2024-12-19 (2.319 bar, untuk fitting ambang batas keyakinan).
  * **Purged Val Gap:** 5 hari bursa terakhir 2024 (50 bar dibuang untuk mencegah leakage ke set test).
  * **Final Holdout Test Set:** 2025-01-02 s.d. 2026-10-16 (4.237 bar, seluruhnya memiliki realisasi harga penutupan resmi per 23 Oktober 2026).
- **Kesimpulan Utama:**
  * **Akurasi Baseline Naif (Majority Class Down):** **55,06%**
  * **Akurasi Random Forest (Reproduced v1.0):** **52,75%** (Balanced Accuracy: 50,80%, ROC-AUC: **0,5105**)
  * **Akurasi HistGradientBoosting:** **51,78%** (Balanced Accuracy: 50,69%, ROC-AUC: **0,5109**)
  * **Akurasi Logistic Regression L2:** **52,02%** (Balanced Accuracy: 49,83%, ROC-AUC: **0,4988**)
  * **Temuan Tegas:** **Tidak ada model kandidat yang mampu mengungguli baseline naif mayoritas kelas (55,06%) pada data out-of-sample murni.** ROC-AUC seluruh model berkisar antara 0,4988 hingga 0,5109 (setara tebakan acak / coin flip).

---

## 2. Metrik Kinerja Klasifikasi Holdout Test (4.237 Sampel Uji)

| Metrik Evaluasi | Baseline (Majority Down) | Logistic Regression L2 | HistGradientBoosting | Random Forest (Reproduced v1.0) |
| :--- | :---: | :---: | :---: | :---: |
| **Accuracy** | **55.06%** | 52.02% | 51.78% | 52.75% |
| **Balanced Accuracy** | 50.00% | 49.83% | 50.69% | **50.80%** |
| **ROC-AUC Score** | 0.5000 | 0.4988 | **0.5109** | 0.5105 |
| **Brier Score (Kalibrasi)** | N/A | 0.2507 | 0.2615 | **0.2499** |
| **Precision Kelas 1 (UP)** | 0.00% | 44.64% | 45.81% | **46.22%** |
| **Recall Kelas 1 (UP)** | 0.00% | 28.20% | **39.92%** | 31.51% |
| **F1-Score Kelas 1 (UP)** | 0.0000 | 0.3457 | **0.4266** | 0.3748 |
| **Precision Kelas 0 (DOWN)** | 55.06% | 54.94% | 55.62% | **55.63%** |
| **Recall Kelas 0 (DOWN)** | **100.00%** | 71.45% | 61.47% | 70.08% |
| **Confusion Matrix (TN / FP / FN / TP)** | 2333 / 0 / 1904 / 0 | 1667 / 666 / 1367 / 537 | 1434 / 899 / 1144 / 760 | 1635 / 698 / 1304 / 600 |

---

## 3. Analisis Prediksi Selektif & Eksplorasi Ambang Batas 90%

Analisis dilakukan dengan menerapkan filter keyakinan $\tau$ (*confidence threshold*), di mana sinyal aktif hanya diterbitkan jika probabilitas model $\ge \tau$ (untuk arah UP) atau $\le (1 - \tau)$ (untuk arah DOWN). Jika tidak memenuhi, model memilih abstain (*NO SIGNAL*). Interval kepercayaan dihitung menggunakan *Wilson Score 95% Confidence Interval*.

### A. Random Forest (Reproduced v1.0)
| Ambang $\tau$ | Sinyal Aktif | Coverage (%) | Jumlah Benar | Akurasi (%) | Wilson 95% CI | Jumlah Abstain |
| :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **0.50** | 4.237 | 100.00% | 2.235 | 52.75% | [51.24%, 54.25%] | 0 |
| **0.55** | 1.724 | 40.69% | 934 | 54.18% | [51.82%, 56.52%] | 2.513 |
| **0.60** | 337 | 7.95% | 184 | 54.60% | [49.26%, 59.83%] | 3.900 |
| **0.65** | 69 | 1.63% | 43 | 62.32% | [50.52%, 72.82%] | 4.168 |
| **0.70** | 3 | 0.07% | 3 | 100.00% | [43.85%, 100.00%] | 4.234 |
| **0.75** | 0 | 0.00% | 0 | N/A | N/A | 4.237 |
| **0.80** | 0 | 0.00% | 0 | N/A | N/A | 4.237 |
| **0.85** | 0 | 0.00% | 0 | N/A | N/A | 4.237 |
| **0.90** | 0 | 0.00% | 0 | N/A | N/A | 4.237 |

### B. HistGradientBoosting
| Ambang $\tau$ | Sinyal Aktif | Coverage (%) | Jumlah Benar | Akurasi (%) | Wilson 95% CI | Jumlah Abstain |
| :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **0.50** | 4.237 | 100.00% | 2.194 | 51.78% | [50.28%, 53.28%] | 0 |
| **0.55** | 2.862 | 67.55% | 1.491 | 52.10% | [50.26%, 53.92%] | 1.375 |
| **0.60** | 1.690 | 39.89% | 901 | 53.31% | [50.93%, 55.68%] | 2.547 |
| **0.65** | 962 | 22.70% | 501 | 52.08% | [48.92%, 55.22%] | 3.275 |
| **0.70** | 485 | 11.45% | 245 | 50.52% | [46.08%, 54.94%] | 3.752 |
| **0.75** | 215 | 5.07% | 105 | 48.84% | [42.23%, 55.48%] | 4.022 |
| **0.80** | 84 | 1.98% | 48 | 57.14% | [46.48%, 67.18%] | 4.153 |
| **0.85** | 32 | 0.76% | 15 | 46.88% | [30.87%, 63.55%] | 4.205 |
| **0.90** | 2 | 0.05% | 1 | 50.00% | [9.45%, 90.55%] | 4.235 |

### Evaluasi Kritis Ambang Batas 90%:
1. **Target 90% TIDAK TERCAPAI:** Pada ambang $\tau = 0.90$, Random Forest menghasilkan 0 sinyal (coverage 0%). HistGradientBoosting hanya menghasilkan 2 sinyal dari 4.237 peluang (coverage 0,05%), dengan akurasi 50% (1 benar, 1 salah) dan interval ketidakpastian Wilson membentang ekstrem dari 9,45% hingga 90,55%.
2. **Ketiadaan Edge Tanpa Filter Tambahan:** Mengandalkan fitur teknikal semata pada horizon 5 hari tidak memberikan separasi sinyal yang cukup untuk mencapai akurasi tinggi secara konsisten.

---

## 4. Evaluasi Tersegregasi per Sektor & Periode Waktu

### A. Evaluasi Kinerja per Sektor Industri
| Sektor | Emiten Cakupan | Jumlah Sampel | Akurasi Baseline (Majority Down) | Akurasi Random Forest | Akurasi HistGradientBoosting |
| :--- | :--- | :---: | :---: | :---: | :---: |
| **Consumer** | MAPI | 424 | 54.48% | 53.30% | **57.08%** |
| **Energy** | MEDC | 424 | 48.58% | 48.11% | **51.89%** |
| **Financials** | ARTO, BBCA, BBNI, BBRI, BMRI | 2.117 | **57.44%** | 53.76% | 51.44% |
| **Industrials** | ASII | 424 | 48.58% | **54.25%** | 52.83% |
| **Infrastructure** | TLKM | 424 | **51.65%** | 46.93% | 46.93% |
| **RealEstate** | SMRA | 424 | **60.14%** | 56.13% | 51.89% |

*Catatan Sektor:* Pada sektor perbankan (Financials) dan properti (RealEstate), tren penurunan pasar selama 2025–2026 menyebabkan strategi naif "selalu tebak turun" menghasilkan akurasi 57%–60%, sedangkan model ML mengalami *false recovery bias* yang menurunkan akurasi menjadi 51%–56%.

### B. Evaluasi Kinerja per Periode Waktu
| Periode | Rentang Tanggal | Jumlah Sampel | Akurasi Baseline | Akurasi Random Forest | Akurasi HistGradientBoosting |
| :--- | :--- | :---: | :---: | :---: | :---: |
| **2025 H1** | 2025-01-02 s.d. 2025-06-30 | 1.090 | **55.78%** | 52.39% | 52.11% |
| **2025 H2** | 2025-07-01 s.d. 2025-12-30 | 1.267 | 51.70% | 52.25% | **52.41%** |
| **2026 YTD**| 2026-01-02 s.d. 2026-10-16 | 1.880 | **56.91%** | 53.30% | 51.17% |

---

## 5. Keputusan & Protokol Produksi

1. **Status Model Produksi Tetap Aman:**
   - Model produksi di sistem operasional Aether (`RandomForest_v1.0`) **TIDAK DIUBAH** dan tidak dipromosikan.
   - Ambang batas keyakinan produksi $\tau = 0.70$ tetap berlaku, di mana status default sistem adalah **`NO_SIGNAL (ABSTAIN)`** untuk melindungi modal dari *overtrading* dan kerugian friksi biaya bursa.
2. **Penyimpanan Artefak Riset Terisolasi:**
   - Seluruh artefak tersimpan di `E:\Aether-Market-Intelligence\ml_research\`:
     * Dataset Snapshot Parquet/SQLite/CSV & Manifest: `ml_research/datasets/`
     * Konfigurasi Eksperimen: `ml_research/configs/experiment_01_config.json`
     * Prediksi Out-of-Sample: `ml_research/predictions/test_predictions_exp01.csv`
     * Model Binary: `ml_research/models/*.joblib`
3. **Rekomendasi Langkah Riset Berikutnya (EXP-02):**
   - Eksplorasi pembentukan label asimetris (misal: *triple-barrier volatility-adjusted* atau *volatility breakout*) daripada directional close-to-close murni.
   - Evaluasi penggunaan meta-labeling (model sekunder untuk memprediksi ukuran taruhan/validitas sinyal teknikal) untuk menghindari biaya friksi transaksi ritel BEI.
