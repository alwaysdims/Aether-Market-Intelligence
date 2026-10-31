# HASIL EKSPERIMEN MACHINE LEARNING: EXP-02R
**Evaluasi Ulang Koreksi Tumpang Tindih Walk-Forward & Uji Inferensi Berpasangan**
**Aether Market Intelligence — Subdivisi Riset Kuantitatif Terisolasi**
**Tanggal Rilis:** 28 Oktober 2026  
**Status Evaluasi:** SELESAI (KOREKSI FOLD BERSIH, BOOTSTRAP BLOK BERPASANGAN, AUDIT KALENDER PENUH)  
**Keputusan Promosi:** **REJECTED / TIDAK MEMENUHI SYARAT PROMOSI** (Model Produksi Tetap Dipertahankan)

---

## 1. Audit & Rekonsiliasi Koreksi EXP-02 vs EXP-02R

### A. Temuan Tumpang Tindih Jendela Test pada EXP-02 Lama
- Pada manifest EXP-02 lama, partisi test tercatat:
  * Fold 2 Test: `2025-01-01` s.d. `2025-12-31`
  * Fold 3 Test: `2025-07-01` s.d. `2026-10-16`
- **Konsekuensi:** Terjadi tumpang tindih 184 hari kalender (2025-H2 dihitung dua kali pada evaluasi agregat EXP-02 lama).
- **Tindakan Koreksi (EXP-02R):** Jendela test Fold 3 dikoreksi menjadi **`2026-01-01 s.d. 2026-10-16`**, menghasilkan partisi evaluasi test out-of-sample yang **100% non-overlapping** (saling lepas). Artefak EXP-02 lama ditandai sebagai `SUPERSEDED` di `experiment_02_manifest.superseded.json`.
- Seluruh periode 2024 s.d. 2026-10-16 secara resmi diklasifikasikan sebagai **`KNOWN BENCHMARK`**, bukan *untouched holdout*.

### B. Rentang Partisi Walk-Forward Final EXP-02R (Non-Overlapping)
| Fold | Train Window (Calendar Purged) | Validation Window (Threshold Fit) | Test Window (Out-of-Sample) | Bar Test Unik | Decision Dates |
| :---: | :--- | :--- | :--- | :---: | :---: |
| **Fold 1** | 2021-03-01 s.d. 2022-12-23 | 2023-01-02 s.d. 2023-12-22 | 2024-01-02 s.d. 2024-12-30 | 2.368 bar | 237 hari |
| **Fold 2** | 2021-03-01 s.d. 2023-12-22 | 2024-01-02 s.d. 2024-12-22 | 2025-01-02 s.d. 2025-12-30 | 2.354 bar | 236 hari |
| **Fold 3** | 2021-03-01 s.d. 2024-12-22 | 2025-01-02 s.d. 2025-12-22 | 2026-01-02 s.d. 2026-10-16 | 1.880 bar | 188 hari |
| **Total** | — | — | **2024-01-02 s.d. 2026-10-16** | **6.602 bar** | **661 hari** |

*Verifikasi Duplikasi:* Nol duplikat bar `(symbol, bar_date, model)` pada berkas prediksi out-of-fold (`oof_predictions_exp02r.csv`).

---

## 2. Audit Kalender Bursa 5 Hari & Missing Outcome (1,48%)

- **Kaidah Target Kalender:** Target pergerakan 5 hari bursa wajib merujuk pada sesi perdagangan bursa BEI ke-5 (`true_target_market_date`), bukan sekadar menggeser 5 bar internal emiten (`bar_shift -5`).
- **Hasil Audit Target:**
  * Total observasi bersih tervalidasi: 13.546 bar.
  * Observasi dengan sesi ke-5 bursa yang tercapai: 13.454 bar.
  * **Observasi dengan Target Hilang (Missing Target Outcome):** **43 bar (0,32% dari total bar bersih).**
- **Klasifikasi Akar Masalah 43 Bar Hilang:**
  * **Missing Provider Bar (43 bar):** Tersebar pada 42 tanggal berbeda di mana 8 atau 9 dari 10 saham tetap diperdagangkan, namun 1 saham tidak memiliki bar harga di provider Yahoo Finance (BBRI 14 bar, ASII 11 bar, BBCA 6 bar, MEDC 5 bar, BBNI 3 bar, BMRI 2 bar, TLKM 2 bar).
  * **Audit Bukti Suspensi:** Berdasarkan pencarian dokumen dan pengumuman bursa, tidak ada catatan resmi suspensi BEI pada tanggal-tanggal tersebut untuk emiten berkapitalisasi besar (blue chip). Oleh karena itu, ketiadaan bar diklasifikasikan secara jujur sebagai **`PROVIDER_OMISSION_UNCONFIRMED_SUSPENSION`**, bukan diasumsikan sebagai suspensi resmi bursa.
  * **Penanganan:** 43 bar tersebut **dikecualikan dari evaluasi out-of-sample**, tidak diganti dengan tanggal setelahnya (*no arbitrary forward-filling*).

---

## 3. Spesifikasi Universe Fitur Pasar (Equal-Weight Basket Top-10)

- **Label Universe:** `Equal-Weight Basket of Top-10 Liquid BEI Issuers` (BBCA, BBRI, BMRI, BBNI, ASII, TLKM, MAPI, ARTO, MEDC, SMRA).
- **Ketersediaan Point-in-Time:** Fitur `bench_ret_1` dan `bench_ret_5` dihitung murni dari harga penutupan hari $t$ ke belakang.
- **Konstituen Dinamis:** Pada tanggal di mana salah satu emiten memiliki missing bar di provider (misal: 9 emiten aktif), rata-rata equal-weight dihitung dari emiten yang aktif pada tanggal tersebut ($N=9$).
- **Inklusi Saham Target:** Saham target yang diprediksi saat ini termasuk ke dalam konstituen rata-rata basket ($1/10 = 10\%$ bobot). Opsi *Leave-One-Out (LOO)* benchmark dipertahankan untuk protokol penelitian EXP-03 mendatang guna menghindari manipulasi tersembunyi.

---

## 4. Evaluasi Target B (Conditional Material Target) Secara Transparan

- **Distribusi Target B (Buffer Netral +/- 1.0% setelah pembersihan 34 target hilang):**
  * UP (> +1.0%): 47,19% (2.488 bar dari 5.272 bar material valid)
  * DOWN (< -1.0%): 52,81% (2.784 bar dari 5.272 bar material valid)
  * FLAT / Netral ([-1.0%, +1.0%]): **20,15%** (1.330 bar dari total 6.602 bar OOF bersih)
- **Status Evaluasi:** Evaluasi Target B bersifat **kondisional (*conditional evaluation*)**, karena 1.330 bar netral (20,15%) disaring post-hoc dari evaluasi biner directional.
- **Koreksi Semantik Kritis:** Penghapusan sampel flat ini **BUKAN bukti kemampuan model untuk abstain secara mandiri**, melainkan penyaringan data post-hoc.
- **Hasil Kinerja Rekalkulasi Target B (Ablation Subset B pada 5.272 Bar Material Bersih):**
  * **Random Forest:** Rata-rata akurasi **53,15%** (Balanced Accuracy: 52,14%, ROC-AUC: 0,5347)
    - Fold 1 (2024): Akurasi 52,95%, ROC-AUC 0,5466 (N=1.883)
    - Fold 2 (2025): Akurasi 52,99%, ROC-AUC 0,5372 (N=1.859)
    - Fold 3 (2026): Akurasi 53,59%, ROC-AUC 0,5161 (N=1.530)
  * **HistGradientBoosting:** Rata-rata akurasi **53,76%** (Balanced Accuracy: 53,25%, ROC-AUC: 0,5376)
    - Fold 1 (2024): Akurasi 54,01%, ROC-AUC 0,5509 (N=1.883)
    - Fold 2 (2025): Akurasi 53,74%, ROC-AUC 0,5431 (N=1.859)
    - Fold 3 (2026): Akurasi 53,46%, ROC-AUC 0,5125 (N=1.530)
  * **Logistic Regression:** Rata-rata akurasi **50,78%** (Balanced Accuracy: 49,55%, ROC-AUC: 0,4888)
  * **Kesimpulan Target B:** Bahkan setelah 34 target hilang dibuang bersih dan sampel flat 1.330 bar dikeluarkan, akurasi model pada pergerakan material hanya berada di kisaran 53,15% – 53,76% dengan ROC-AUC 0,53. Tidak ada edge prediktif material di atas variasi acak. Seluruh model kandidat tetap berstatus **REJECTED FOR PRODUCTION**.

---

## 5. Hasil Kuantitatif Lintas Fold & Uji Berpasangan EXP-02R

### A. Metrik Kinerja Agregat per Fold (Target A: Arah 5-Hari Murni)
| Fold | Subset Fitur | Model ML | Sampel Test | Accuracy (%) | Balanced Acc (%) | ROC-AUC | Brier Score |
| :---: | :--- | :--- | :---: | :---: | :---: | :---: | :---: |
| **Fold 1** | **BASELINE** | **Majority Class (from Train)** | 2.368 | 53.34% | 50.00% | 0.5000 | N/A |
| (2024) | B (Benchmark Rel) | Random Forest | 2.368 | 53.67% | 52.52% | 0.5399 | 0.2483 |
| | B (Benchmark Rel) | HistGradientBoosting | 2.368 | **54.35%** | **53.82%** | **0.5552** | 0.2573 |
| | B (Benchmark Rel) | Logistic Regression | 2.368 | 52.70% | 51.22% | 0.5125 | 0.2505 |
| **Fold 2** | **BASELINE** | **Majority Class (from Train)** | 2.354 | **53.57%** | 50.00% | 0.5000 | N/A |
| (2025) | B (Benchmark Rel) | Random Forest | 2.354 | 52.85% | 51.71% | 0.5327 | 0.2487 |
| | B (Benchmark Rel) | HistGradientBoosting | 2.354 | 52.89% | 52.38% | 0.5328 | 0.2606 |
| | B (Benchmark Rel) | Logistic Regression | 2.354 | 51.23% | 49.85% | 0.4938 | 0.2520 |
| **Fold 3** | **BASELINE** | **Majority Class (from Train)** | 1.880 | **56.91%** | 50.00% | 0.5000 | N/A |
| (2026) | B (Benchmark Rel) | Random Forest | 1.880 | 53.56% | 50.90% | 0.5124 | 0.2481 |
| | B (Benchmark Rel) | HistGradientBoosting | 1.880 | 53.24% | 51.62% | 0.5054 | 0.2634 |
| | B (Benchmark Rel) | Logistic Regression | 1.880 | 51.60% | 48.28% | 0.4658 | 0.2536 |

---

### B. Hasil Paired Block Bootstrap Berdasarkan Tanggal (6.602 Sampel OOF Unik)
- **Metodologi Bootstrap:** 1.000 kali percontohan ulang (*resampling*) menggunakan **blok 5 hari bursa berturut-turut** (panjang blok diselaraskan secara kausal dengan jeda tumpang tindih outcome horizon 5-hari).
- Seluruh 10 emiten pada tanggal yang terpilih di-resample bersama-sama untuk menjaga struktur kovariansi lintas emiten (*cross-sectional dependence*).
- Selisih dihitung langsung per resample: $\Delta = \text{Accuracy}_{\text{Model}} - \text{Accuracy}_{\text{Baseline}}$.

| Model Kandidat (Subset B) | Rata-rata Akurasi Model (95% CI) | Rata-rata Akurasi Baseline (95% CI) | Selisih Berpasangan $\Delta$ (95% CI) | ROC-AUC (95% CI) | Probabilitas Model $\le$ Baseline |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **Random Forest** | 53.34% [51.17%, 55.52%] | 54.42% [50.20%, 58.35%] | **-1.08% [-4.56%, +2.32%]** | 0.5299 [0.5053, 0.5542] | **73.10%** |
| **HistGradientBoosting** | 53.51% [51.51%, 55.42%] | 54.42% [50.20%, 58.35%] | **-0.91% [-4.92%, +3.22%]** | 0.5342 [0.5082, 0.5586] | **67.70%** |
| **Logistic Regression** | 51.89% [49.49%, 54.40%] | 54.42% [50.20%, 58.35%] | **-2.53% [-5.60%, +0.39%]** | 0.4936 [0.4664, 0.5189] | **94.90%** |

---

## 6. Interpretasi Kritis & Batasan Bukti

1. **Inferensi Berpasangan Tidak Signifikan:**
   - Interval keyakinan selisih akurasi berpasangan mencakup angka nol untuk kedua model non-linear ($\text{CI}_{\Delta}$ RF `[-4.56%, +2.32%]`, HGB `[-4.92%, +3.22%]`).
   - Probabilitas model lebih buruk atau sama dengan baseline naif mencapai **73,1% pada Random Forest** dan **67,7% pada HistGradientBoosting**.
2. **Keterbatasan Bukti:**
   - Penurunan performa pada Subset C tidak boleh disimpulkan secara definitif sebagai *overfitting* murni; penurunan tersebut juga konsisten dengan hipotesis bahwa fitur volatilitas dan volume lokal pada saham BEI memiliki korelasi tinggi dengan fitur dasar (multikolinearitas) tanpa menambahkan daya pisah ortogonal.
   - Pasar BEI pada 2025–2026 didominasi oleh pergerakan turun pada sektor perbankan dan properti, sehingga aturan naif *Always Predict Majority Down* menjadi benchmark yang sangat tangguh (56,91% di 2026).

---

## 7. Keputusan Promosi & Rekomendasi Eksperimen Berikutnya (EXP-03)

- **Keputusan Promosi:** **REJECTED.** Tidak ada keunggulan statistik yang signifikan atas baseline deterministik. Model produksi operasional `RandomForest_v1.0` (kebijakan default `NO_SIGNAL / ABSTAIN`) tetap dipertahankan.
- **Rekomendasi Hipotesis Tunggal untuk EXP-03:**
  * **Hipotesis Asimetris Volatility Breakout:** Beralih dari prediksi arah biner 5-hari (yang rentan terhadap kebisingan harga flat) ke formulasi **Klasifikasi 3-Kelas Riil (UP / NEUTRAL / DOWN)** dengan ambang batas adaptif berbasis volatilitas ATR ($|R_{t+5}| > 1.5 \times \text{ATR}_{14}$), di mana model dioptimalkan khusus untuk mendeteksi pergerakan tren kuat dan secara eksplisit melatih kelas abstain (*NEUTRAL*).
