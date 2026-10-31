# HASIL EKSPERIMEN MACHINE LEARNING: EXP-03
**Klasifikasi Tiga Kelas (UP / NEUTRAL / DOWN) Berbasis Barrier Volatilitas ATR-14**
**Aether Market Intelligence — Subdivisi Riset Kuantitatif Terisolasi**
**Tanggal Rilis:** 28 Oktober 2026  
**Status Evaluasi:** SELESAI (3-CLASS WALK-FORWARD VERIFIED, PAIRED BLOCK BOOTSTRAPPED)  
**Keputusan Promosi:** **REJECTED / TIDAK MEMENUHI SYARAT PROMOSI** (Model Produksi Tetap Dipertahankan)

---

## 1. Distribusi Kelas & Tantangan Klasifikasi Tiga Kelas

Target adaptif $1.5 \times \text{ATR}_{14}$ menghasilkan distribusi kelas alami pada 13.593 observasi pasar BEI:
- **Kelas DOWN (0):** 1.796 sampel (**13,21%**)
- **Kelas NEUTRAL (1):** 9.825 sampel (**72,28%**)
- **Kelas UP (2):** 1.972 sampel (**14,51%**)

### Karakteristik Penting:
1. **Dominasi Pasar Netral:** Lebih dari 72% pergerakan 5 hari bursa berada di dalam rentang normal volatilitas (tidak breakout melebihi $1.5 \times \text{ATR}$).
2. **Implikasi Baseline:** Model naif `Baseline_Always_Neutral` secara otomatis memperoleh akurasi **70,22%** hanya dengan selalu menebak NEUTRAL, tetapi memiliki Balanced Accuracy hanya **33,33%** dan Macro-F1 **27,54%** (gagal mendeteksi satupun pergerakan tren besar).

---

## 2. Kinerja Model Tiga Kelas Lintas 3 Fold (6.602 Sampel OOF Unik)

| Model Evaluasi | Rata-rata Accuracy (%) | Balanced Accuracy (%) | Macro-F1 (%) | F1-DOWN (%) | F1-NEUTRAL (%) | F1-UP (%) | Multiclass Log Loss |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Baseline Always Neutral** | **70.22%** | 33.33% | 27.54% | 0.00% | **82.49%** | 0.00% | N/A |
| **Baseline Train Majority** | **70.22%** | 33.33% | 27.54% | 0.00% | **82.49%** | 0.00% | N/A |
| **HistGradientBoosting** | 55.30% | 34.45% | **34.25%** | 14.66% | 71.31% | **16.80%** | **0.9963** |
| **Random Forest** | 39.19% | **34.63%** | 31.64% | **20.31%** | 53.27% | 21.33% | 1.0707 |

---

## 3. Rincian Metrik & Matriks Konfusi per Fold

### A. Rincian Metrik per Fold
| Fold | Periode Evaluasi | Sampel Test | Distribusi Test (DOWN / NEU / UP) | Akurasi Baseline | Akurasi HGB (Macro-F1) | Akurasi RF (Macro-F1) |
| :---: | :--- | :---: | :---: | :---: | :---: | :---: |
| **Fold 1** | 2024-01-02 s.d. 2024-12-30 | 2.368 | 381 / 1.670 / 317 | 70.52% | 58.70% (34.04%) | 40.41% (32.46%) |
| **Fold 2** | 2025-01-02 s.d. 2025-12-30 | 2.354 | 319 / 1.697 / 338 | 72.09% | 56.24% (33.66%) | 38.95% (30.32%) |
| **Fold 3** | 2026-01-02 s.d. 2026-10-16 | 1.880 | 362 / 1.279 / 239 | 68.03% | 50.96% (35.06%) | 38.19% (32.13%) |

### B. Matriks Konfusi Agregat (Fold 1–3, Total 6.602 Bar)
- **HistGradientBoosting:**
  * Prediksi DOWN: 149 benar, 645 salah tebak (termasuk 78 actual UP)
  * Prediksi NEUTRAL: 3.370 benar, 769 actual DOWN salah, 611 actual UP salah
  * Prediksi UP: 153 benar, 501 salah tebak (termasuk 144 actual DOWN)
  * *Observasi:* Model cenderung bias ke kelas tengah (NEUTRAL), menghasilkan false negative besar pada tren kuat.
- **Random Forest (dengan Class Weight Balanced):**
  * Prediksi DOWN: 312 benar, 1.451 salah tebak (1.189 actual NEUTRAL)
  * Prediksi NEUTRAL: 1.994 benar, 453 actual DOWN salah, 373 actual UP salah
  * Prediksi UP: 286 benar, 1.201 salah tebak (969 actual NEUTRAL)
  * *Observasi:* Penyeimbangan bobot kelas memaksa model menerbitkan sinyal directional secara agresif, tetapi 75% sinyal directional ternyata mendarat di pasar netral/sideways.

---

## 4. Evaluasi Keputusan Directional (UP / DOWN)

Dalam operasional nyata, keputusan paling berisiko adalah ketika sistem memutuskan menerbitkan sinyal directional (**UP** atau **DOWN**). Evaluasi ini mengukur presisi directional di mana prediksi UP/DOWN yang ternyata berakhir NEUTRAL tetap dihitung sebagai kegagalan:

| Model Evaluasi | Cakupan Sinyal Directional (%) | Presisi Directional Nyata (%) | Sinyal Directional Salah (False Alarm) |
| :--- | :---: | :---: | :---: |
| **Baseline Always Neutral** | 0.00% (Selalu Pasif) | N/A | 0 sinyal |
| **HistGradientBoosting** | 28.60% (1.888 sinyal) | **16.26%** | **83.74% salah** (1.581 sinyal salah) |
| **Random Forest** | 57.50% (3.796 sinyal) | **15.88%** | **84.12% salah** (3.194 sinyal salah) |

*Temuan Finansial Kritis:* Presisi directional model hanya berkisar **15,8% – 16,3%**. Artinya, jika trader mengeksekusi rekomendasi directional model ini, lebih dari 83% posisi akan terjebak di pergerakan flat atau berlawanan arah, menghasilkan akumulasi kerugian biaya transaksi bursa ritel BEI yang masif.

---

## 5. Estimasi Ketidakpastian Paired Block Bootstrap (1.000 Replikasi)

- **Metodologi Bootstrap:** 1.000 resample menggunakan blok 5 hari bursa berturut-turut untuk memperhitungkan dependensi serial horizon 5-hari dan dependensi lintang 10 emiten.
- **Hasil Komparasi Berpasangan (Model vs Baseline Always-Neutral):**
  * **HistGradientBoosting:**
    - Selisih Macro-F1: **+6.94%** (95% CI: `[+5.34%, +8.57%]`). Proporsi replikasi bootstrap $\le 0$: **0,00%**.
    - Selisih Akurasi Mentah: **-14.74%** (95% CI: `[-16.69%, -12.81%]`).
    - Balanced Accuracy: 34.56% (95% CI: `[33.20%, 35.98%]`).
  * **Random Forest:**
    - Selisih Macro-F1: **+4.15%** (95% CI: `[+1.97%, +6.34%]`). Proporsi replikasi bootstrap $\le 0$: **0,00%**.
    - Selisih Akurasi Mentah: **-31.10%** (95% CI: `[-34.51%, -27.83%]`).
    - Balanced Accuracy: 34.76% (95% CI: `[32.66%, 37.00%])`.
- **Sensitivitas Panjang Blok (Block Size 3, 5, 10 Hari):**
  * Panjang blok 3 hari: $\text{CI}_{\Delta \text{Macro-F1}}$ `[+5.44%, +8.54%]`, $\text{CI}_{\text{BalAcc}}$ `[33.25%, 36.05%]`.
  * Panjang blok 5 hari: $\text{CI}_{\Delta \text{Macro-F1}}$ `[+5.30%, +8.65%]`, $\text{CI}_{\text{BalAcc}}$ `[33.06%, 35.89%]`.
  * Panjang blok 10 hari: $\text{CI}_{\Delta \text{Macro-F1}}$ `[+5.31%, +8.63%]`, $\text{CI}_{\text{BalAcc}}$ `[33.24%, 36.10%]`.
  * *Hasil stabil:* Variasi panjang blok tidak mengubah kesimpulan statistik.

---

## 6. Keputusan Promosi & Rekomendasi Riset

1. **Keputusan Promosi:** **REJECTED.**
   - Meskipun Macro-F1 model lebih tinggi daripada baseline naif (+6,9%), Balanced Accuracy model (34,5%) hanya marjinal di atas tebakan acak 3 kelas (33,33%).
   - Presisi directional sebesar 16% tidak layak untuk trading riil.
   - Model produksi di Aether (`RandomForest_v1.0` dengan status default `NO_SIGNAL / ABSTAIN`) tetap aman dan tidak diubah.
2. **Penyimpanan Artefak Lengkap:**
   - Manifest: `E:\Aether-Market-Intelligence\ml_research\configs\experiment_03_manifest.json`
   - Prediksi OOF: `E:\Aether-Market-Intelligence\ml_research\predictions\oof_predictions_exp03.csv`
   - Protokol: `reports/ml/EXPERIMENT_03_PROTOCOL.md`
   - Hasil: `reports/ml/EXPERIMENT_03_RESULTS.md`
3. **Rekomendasi Hipotesis EXP-04 (Meta-Labeling Asimetris):**
   - Beralih dari prediksi arah mandiri ke **Meta-Labeling Dua Tahap**:
     * Tahap 1: Gunakan aturan teknikal berbasis tren (misal: EXP-04 trend-pullback) untuk menyaring kandidat.
     * Tahap 2: Latih model biner khusus untuk memprediksi probabilitas keberhasilan sinyal teknikal tersebut ($P(\text{Trade Win}) > 0.60$) sebelum order diterbitkan.
