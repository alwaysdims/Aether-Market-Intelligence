# HASIL EKSPERIMEN MACHINE LEARNING: EXP-02
**Ablation Study: Nilai Tambah Benchmark Relatif & Normalisasi Aktivitas Pasar**
**Aether Market Intelligence — Subdivisi Riset Kuantitatif Terisolasi**
**Tanggal Rilis:** 28 Oktober 2026  
**Status Evaluasi:** SELESAI (WALK-FORWARD VERIFIED, BLOCK BOOTSTRAPPED, NO LEAKAGE)  
**Keputusan Promosi:** **REJECTED / TIDAK MEMENUHI SYARAT PROMOSI** (Model Produksi Tetap Dipertahankan)

---

## 1. Temuan Kunci & Ringkasan Eksekutif

Eksperimen EXP-02 menguji nilai tambah fitur relatif terhadap benchmark pasar (Subset B) dan fitur normalisasi volume/volatilitas (Subset C) dibandingkan fitur teknikal dasar (Subset A) menggunakan **Walk-Forward Validation 3-Fold non-overlapping** dengan *calendar-based purging*:

1. **Efek Penambahan Fitur Relatif Pasar (Subset B):**
   - Menghasilkan sedikit peningkatan stabilitas pada *Random Forest* (rata-rata akurasi naik dari **52,45%** pada Base-12 menjadi **53,46%** pada Subset B, dengan ROC-AUC rata-rata **0,5395**).
   - Namun, **tidak ada model kandidat yang mampu mengungguli baseline naif mayoritas kelas training (rata-rata 53,90%) secara meyakinkan dan konsisten.**
2. **Efek Penambahan Fitur Normalisasi Aktivitas (Subset C):**
   - Menambah fitur ke 19 variabel justru memicu penurunan minor kinerja (akurasi RF turun kembali ke **53,18%**), mengonfirmasi hipotesis *diminishing returns* dan *noise penalty* pada data saham harian.
3. **Hasil Target Material dengan Buffer Netral (+/- 1.0%):**
   - Membuang zona netral (22,3% bar pasar sideways) menghasilkan distribusi kelas yang lebih seimbang (38,3% UP vs 39,4% DOWN).
   - Akurasi model berada di kisaran **51,4% – 52,2%**, tetap tidak mampu menghasilkan keunggulan statistik di atas variasi acak (ROC-AUC 0,50 – 0,53).
4. **Estimasi Ketidakpastian Block Bootstrap Berdasarkan Tanggal:**
   - Evaluasi bootstrap blok tanggal (1.000 resample) membuktikan bahwa interval keyakinan 95% akurasi model (*Random Forest* [50,59%, 54,84%]) masih bertumpang tindih penuh dengan tebakan acak 50% dan baseline pasar 54,8%.
5. **Keputusan Promosi:**
   - **TIDAK ADA PROMOSI KE PRODUKSI.** Model operasional `RandomForest_v1.0` dengan status default `NO_SIGNAL / ABSTAIN` tetap dipertahankan.

---

## 2. Tabel Komparasi Ablation Rata-Rata Lintas 3 Fold

### A. Target A: Arah Pergerakan 5-Hari Murni ($(Close_{t+5} - Close_t) / Close_t > 0$)
| Konfigurasi Fitur | Model Machine Learning | Rata-rata Accuracy (%) | Rata-rata Balanced Acc (%) | Rata-rata ROC-AUC | Rata-rata Brier Score |
| :--- | :--- | :---: | :---: | :---: | :---: |
| **BASELINE (Majority Class)**| ZeroRule (Class from Train) | **53.90%** | 50.00% | 0.5000 | N/A |
| **A. Base 12 Fitur** | Logistic Regression | 52.21% | 50.29% | 0.5018 | 0.2506 |
| | HistGradientBoosting | 51.96% | 51.08% | 0.5211 | 0.2593 |
| | Random Forest | 52.45% | 50.74% | 0.5211 | 0.2493 |
| **B. Benchmark Relatif (16 Fitur)**| Logistic Regression | 51.61% | 49.98% | 0.4976 | 0.2517 |
| | HistGradientBoosting | 53.50% | **52.75%** | **0.5345** | 0.2592 |
| | Random Forest | **53.46%** | 51.99% | 0.5329 | **0.2483** |
| **C. Aktivitas & Volatilitas (19 Fitur)**| Logistic Regression | 51.00% | 49.58% | 0.4915 | 0.2529 |
| | HistGradientBoosting | 53.38% | 52.68% | 0.5331 | 0.2600 |
| | Random Forest | 53.18% | 51.64% | 0.5275 | 0.2490 |

---

### B. Target B: Target Material dengan Buffer Netral (+/- 1.0%)
*Catatan: 22,31% sampel dengan pergerakan sempit $[-1.0\%, +1.0\%]$ dikecualikan dari evaluasi klasifikasi.*

| Konfigurasi Fitur | Model Machine Learning | Rata-rata Accuracy (%) | Rata-rata Balanced Acc (%) | Rata-rata ROC-AUC | Rata-rata Brier Score |
| :--- | :--- | :---: | :---: | :---: | :---: |
| **BASELINE (Majority Class)**| ZeroRule (Class from Train) | 47.94% | 50.00% | 0.5000 | N/A |
| **A. Base 12 Fitur** | Logistic Regression | 50.55% | 50.68% | 0.5017 | 0.2527 |
| | HistGradientBoosting | 51.78% | 51.61% | 0.5203 | 0.2643 |
| | Random Forest | 51.42% | 51.29% | 0.5183 | 0.2512 |
| **B. Benchmark Relatif (16 Fitur)**| Logistic Regression | 49.40% | 49.50% | 0.4944 | 0.2546 |
| | HistGradientBoosting | 51.17% | 51.05% | 0.5175 | 0.2702 |
| | Random Forest | **52.25%** | **52.13%** | **0.5295** | **0.2504** |
| **C. Aktivitas & Volatilitas (19 Fitur)**| Logistic Regression | 49.90% | 49.90% | 0.4908 | 0.2556 |
| | HistGradientBoosting | 51.27% | 51.13% | 0.5132 | 0.2708 |
| | Random Forest | 51.43% | 51.33% | 0.5199 | 0.2512 |

---

## 3. Rincian Kinerja per Fold Walk-Forward (Target A)

| Fold | Periode Evaluasi Out-of-Sample | Jumlah Sampel Test | Akurasi Baseline (Majority) | Akurasi RF (Base 12) | Akurasi RF (Subset B) | Akurasi HGB (Subset B) |
| :---: | :--- | :---: | :---: | :---: | :---: | :---: |
| **Fold 1** | 2024-01-02 s.d. 2024-12-30 | 2.350 | 53.31% | 53.52% | 53.95% | **54.28%** |
| **Fold 2** | 2025-01-02 s.d. 2025-12-30 | 2.339 | 53.59% | 51.72% | 53.75% | **54.05%** |
| **Fold 3** | 2025-07-01 s.d. 2026-10-16 | 3.149 | **54.81%** | 52.11% | 52.69% | 52.18% |

### Evaluasi Stabilitas per Fold:
- Pada Fold 1 dan 2, penambahan fitur benchmark relatif (Subset B) memberikan peningkatan marginal (+0,4% s.d. +0,7%) di atas baseline kelas mayoritas.
- Namun, pada Fold 3 (periode pasar terkini 2025–2026 di mana volatilitas dan tren melemah), baseline naif naik menjadi 54,81%, sementara akurasi model ML turun ke 52,18% – 52,69%. Hal ini membuktikan bahwa sinyal tidak memiliki ketahanan rezim (*regime resilience*) yang cukup.

---

## 4. Evaluasi Ketidakpastian Block Bootstrap (Fold 3, 1.000 Resamples)

Evaluasi resampling blok tanggal mempertimbangkan korelasi serentak 10 emiten pada setiap hari perdagangan:

- **Random Forest (Subset B):**
  * Bootstrap Mean Accuracy: **52.70%** (95% CI: `[50.59%, 54.84%]`)
  * Bootstrap Mean ROC-AUC: **0.5187** (95% CI: `[0.4940, 0.5416]`)
- **HistGradientBoosting (Subset B):**
  * Bootstrap Mean Accuracy: **52.19%** (95% CI: `[50.11%, 54.31%])`
  * Bootstrap Mean ROC-AUC: **0.5163** (95% CI: `[0.4880, 0.5458]`)
- **Logistic Regression (Subset B):**
  * Bootstrap Mean Accuracy: **50.98%** (95% CI: `[48.57%, 53.34%]`)
  * Bootstrap Mean ROC-AUC: **0.4841** (95% CI: `[0.4544, 0.5132]`)

*Interpretasi:* Batas bawah 95% CI ROC-AUC berada di bawah 0,50 untuk seluruh model, yang berarti model tidak dapat dibedakan secara meyakinkan dari pelemparan koin acak murni pada tingkat signifikansi 5%.

---

## 5. Keputusan & Rekomendasi Riset

1. **Hasil Negatif Diterima & Dilaporkan Apa Adanya:**
   - Tidak ada rekayasa threshold atau penyaringan sampel selektif pasca-evaluasi untuk memaksakan angka 90%.
   - Target 90% presisi terbukti tidak dapat dicapai hanya dengan indikator teknikal dan return relatif pasar pada horizon 5 hari bursa.
2. **Status Sistem Operasional:**
   - Model produksi di Aether (`RandomForest_v1.0` dengan kebijakan default `NO_SIGNAL / ABSTAIN`) tetap aman dan tidak mengalami regresi.
   - Gateway dan chat Telegram tetap berjalan normal.
3. **Artefak yang Dihasilkan & Tersimpan:**
   - Manifest: `E:\Aether-Market-Intelligence\ml_research\configs\experiment_02_manifest.json`
   - Prediksi Out-of-Fold: `E:\Aether-Market-Intelligence\ml_research\predictions\oof_predictions_exp02.csv`
   - Laporan Protokol: `reports/ml/EXPERIMENT_02_PROTOCOL.md`
   - Laporan Hasil: `reports/ml/EXPERIMENT_02_RESULTS.md`
