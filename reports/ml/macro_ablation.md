# ABLATION STUDY: MAKROEKONOMI & REZIM PASAR (FASE H4)
## AETHER MARKET INTELLIGENCE

**Tanggal Rilis:** 25 Oktober 2026  
**Metodologi:** Evaluasi out-of-sample holdout test (2025-01-09 s.d. 2026-10-16, 4.187 bar)  
**Target:** Arah Return 5 Hari Bursa Forward ($(Close_{t+5} - Close_t) / Close_t > 0$)  
**Status Audit:** VERIFIED & DETERMINISTIC

---

### 1. Desain Eksperimen Ablation Study

Eksperimen dirancang untuk menguji hipotesis: *Apakah menyuntikkan fitur makroekonomi publik (World Bank Inflation & Real Interest Rate Spread) dan fitur rezim pasar IHSG dapat meningkatkan kemampuan prediksi arah harga 5-hari di atas fitur teknikal dasar?*

**4 Konfigurasi Subset Fitur:**
- **Konfigurasi A (Teknikal Base 12):** 12 fitur teknikal harga dan volume.
- **Konfigurasi B (Teknikal + Rezim Pasar - 20 Fitur):** 12 fitur dasar + one-hot encoding rezim pasar (`BULLISH`, `BEARISH`, `SIDEWAYS`, `HIGH_VOLATILITY`) + `ihsg_vol20`, `ihsg_ret20`, `ihsg_dd20`.
- **Konfigurasi C (Teknikal + Makroekonomi - 14 Fitur):** 12 fitur dasar + `macro_cpi` (World Bank Inflation) + `macro_rate_spread`.
- **Konfigurasi D (Gabungan Seluruh Fitur - 22 Fitur):** 12 teknikal + 8 fitur rezim + 2 fitur makro.

---

### 2. Hasil Evaluasi pada Holdout Test Set (4.187 Bar)

| Konfigurasi Fitur | Jumlah Fitur | Model Evaluasi | Test Accuracy | Balanced Acc | ROC-AUC | Kesimpulan Kuantitatif |
|---|:---:|---|:---:|:---:|:---:|---|
| **A (Teknikal Base 12)** | 12 | Random Forest | **52.59%** | 50.38% | **0.5131** | **Kinerja Terbaik** |
| | | HistGradientBoosting | 52.02% | **50.63%** | **0.5146** | Kinerja Terbaik HGB |
| **B (Teknikal + Rezim)** | 20 | Random Forest | 51.18% | 49.84% | 0.4851 | Penurunan tajam AUC (-0.028) |
| | | HistGradientBoosting | 50.97% | 49.48% | 0.4842 | Penurunan tajam AUC (-0.030) |
| **C (Teknikal + Makro)** | 14 | Random Forest | 52.57% | 50.39% | 0.5131 | Delta netral / 0.00% |
| | | HistGradientBoosting | 52.02% | 50.63% | 0.5146 | Delta netral / 0.00% |
| **D (Teknikal + Rezim + Makro)** | 22 | Random Forest | 50.39% | 48.64% | 0.4793 | Overfitting terparah (AUC < 0.50) |
| | | HistGradientBoosting | 50.97% | 49.48% | 0.4842 | Degradasi kinerja konsisten |

---

### 3. Temuan Kuantitatif & Keterbatasan Makro

1. **Ketidakcocokan Frekuensi Waktu (*Frequency Mismatch*):**
   - Data makroekonomi resmi publik (BPS, World Bank) dirilis dalam frekuensi tahunan atau bulanan dengan *publication lag* 1–3 bulan.
   - Menggunakan nilai makro tahunan statis pada horizon prediksi 5 hari bursa tidak memberikan varians yang berarti (*zero short-term variance*). Akibatnya, bobot model terhadap fitur makro mendekati nol.
2. **Rezim Pasar Memasukkan Derau Multikolinearitas:**
   - Menyuntikkan indikator rezim IHSG secara langsung ke model prediksi saham individual memicu degradasi ROC-AUC di bawah 0,50 (0,4851 pada RF dan 0,4842 pada HGB).
   - Rezim pasar lebih tepat digunakan sebagai **filter meta-portofolio** (misal: dilarang masuk posisi beli ketika pasar sedang `HIGH_VOLATILITY` atau `BEARISH`), bukan sebagai fitur masukan model supervised learning.
