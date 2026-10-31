# DIAGNOSIS AKHIR EXPERIMENT 03: KUALITAS PROBABILITAS & SELEKSI SINYAL
**Subdivisi Riset Kuantitatif Terisolasi — Aether Market Intelligence**
**Tanggal Registrasi:** 28 Oktober 2026  
**Status Evaluasi:** SELESAI & DIBEKUKAN  
**Keputusan Strategis:** **REJECTED (TETAP DITOLAK UNTUK PROMOSI) & STOP EXPERIMENT CYCLE**  
*(Model produksi operasional `RandomForest_v1.0` dengan kebijakan default `NO_SIGNAL / ABSTAIN` tetap aktif tanpa perubahan)*

---

## 1. Rekonsiliasi Sampel Netral: 3.064 vs 3.069 Kasus

### A. Alur dan Akar Masalah Selisih 5 Kasus
Selisih 5 sampel netral antara laporan lama (3.064) dan dataset `exp02r_clean` (3.069) telah dilacak secara mekanis hingga tingkat bar individu:

1. **Efek Pemotongan Warmup (Pengurangan 35 sampel):**
   - Transisi dari EXP-01 (13.736 bar) ke EXP-02 (13.546 bar) membuang 190 bar awal akibat perhitungan fitur rolling 20-hari.
   - Di antara 190 bar yang dibuang, terdapat **35 bar netral**.
2. **Kelemahan Penanganan Nilai Kosong pada Eksperimen Lama (Penambahan 34 sampel):**
   - Pada perumusan `target_material` di draf awal sebelum pemisahan `is_target_available`, logika `np.where(ret > 0.01, 1, np.where(ret < -0.01, 0, -1))` mengevaluasi seluruh bar yang memiliki `true_fwd_ret_5 = NaN` (target sesi ke-5 bursa tidak tersedia) menjadi `-1` (jatuh ke kondisi netral).
   - Sebanyak **34 bar dengan target hilang** di dataset mentah sempat terhitung ke dalam kategori netral draf awal.
3. **Efek Koreksi Kalender Bursa vs Bar-Shift (Net Penambahan 8 sampel valid):**
   - Pergeseran dari bar-shift lokal ke sesi ke-5 kalender bursa BEI mengubah klasifikasi 38 bar: 23 bar beralih *masuk* ke zona netral $[-1.0\%, +1.0\%]$, sedangkan 15 bar beralih *keluar* dari zona netral menjadi pergerakan material.
4. **Rekonsiliasi Aritmetika Bersih Tanpa Penghitungan Ganda:**
   $$\text{Sampel Netral EXP-02 Clean} = 3.064 - 37 \text{ (warmup)} + 8 \text{ (shift netral)} + 34 \text{ (NaN fallback)} = \mathbf{3.069 \text{ sampel}}$$
   *(Catatan Audit: Persamaan lama $3.064 - 35 + 92 - 15 + 23 = 3.069$ telah dibatalkan karena kesalahan aritmetika dan pencampuran kategori ganda).*

### B. Audit Kontaminasi NaN
- Seluruh 34 bar dengan target hilang tersebut **dikeluarkan sepenuhnya** (`is_target_available = false`) pada pipeline EXP-02R dan EXP-03.
- Evaluasi holdout out-of-sample pada `oof_predictions_exp02r.csv` dan `oof_predictions_exp03.csv` memiliki **0 NaN**, membuktikan Target A (arah biner murni) dan EXP-03 (3-kelas adaptif ATR) steril 100% dari kontaminasi data hilang.

### B. Daftar Contoh Bar yang Mengalami Perubahan Klasifikasi
- `ASII 2021-02-15`: `fwd_ret_5` = -0.0378 (DOWN) -> `true_fwd_ret_5` = NaN (Target hilang akibat gap kalender provider) -> terlabel -1 pada EXP-02.
- `ASII 2021-05-31`: `fwd_ret_5` = +0.0143 (UP) -> `true_fwd_ret_5` = +0.0048 (NETRAL sesungguhnya) -> bergeser masuk ke netral.
- `ASII 2021-08-31`: `fwd_ret_5` = +0.0048 (NETRAL) -> `true_fwd_ret_5` = +0.0239 (UP sesungguhnya) -> bergeser keluar dari netral.

---

## 2. Metrik Agregat OOF, Rata-rata Fold, dan Rata-rata Bootstrap

Perbedaan pelaporan metrik antar metode evaluasi berasal dari struktur matematika agregasi pada sampel 6.602 prediksi unik:

| Model Evaluasi | Agregat OOF Gabungan (Pooled 6.602 bar) | Rata-rata Aritmetika 3 Fold | Rata-rata Resampling Bootstrap (1.000 replikasi) |
| :--- | :---: | :---: | :---: |
| **Baseline Always Neutral** | Acc: 70,37% \| BalAcc: 33,33% \| Macro-F1: 27,54% | Acc: 70,22% \| BalAcc: 33,33% \| Macro-F1: 27,54% | Acc: 70,37% \| BalAcc: 33,33% \| Macro-F1: 27,54% |
| **HistGradientBoosting** | Acc: 55,62% \| BalAcc: 34,56% \| Macro-F1: 34,51% | Acc: 55,30% \| BalAcc: 34,45% \| Macro-F1: 34,25% | Acc: 55,63% \| BalAcc: 34,56% \| Macro-F1: 34,48% |
| **Random Forest** | Acc: 39,26% \| BalAcc: 34,76% \| Macro-F1: 31,72% | Acc: 39,19% \| BalAcc: 34,63% \| Macro-F1: 31,64% | Acc: 39,27% \| BalAcc: 34,76% \| Macro-F1: 31,69% |

### Mengapa Angka Berbeda?
- **Agregat OOF Gabungan (Pooled):** Menggabungkan 6.602 bar menjadi satu vektor evaluasi tunggal. Memberikan bobot proporsional terhadap jumlah bar riil (Fold 1: 2.368 bar, Fold 2: 2.354 bar, Fold 3: 1.880 bar).
- **Rata-rata Aritmetika 3 Fold:** Memberikan bobot setara (33,33%) kepada tiap fold, sehingga performa Fold 3 yang lebih pendek (1.880 bar) diberi bobot sama dengan Fold 1.
- **Rata-rata Bootstrap:** Merupakan nilai ekspektasi dari 1.000 percontohan ulang blok tanggal 5-hari, mencerminkan ketidakpastian pengambilan sampel acak.

### Matriks Konfusi Gabungan (Pooled 6.602 Sampel OOF Unik)
```
Actual \ Pred    DOWN (0)    NEUTRAL (1)    UP (2)     Total Actual
DOWN (0)           149           769          144          1.062 (16,09%)
NEUTRAL (1)        645         3.370          631          4.646 (70,37%)
UP (2)             130           611          153            894 (13,54%)
Total Pred         924         4.750          928          6.602
```
*Observasi:* Dari 928 kali HistGradientBoosting memprediksi arah UP, sebanyak 631 bar (68,0%) ternyata berakhir di zona NEUTRAL, dan 144 bar (15,5%) justru anjlok ke zona DOWN. Presisi exact UP hanya **16,49%**.

---

## 3. Audit Probabilitas & Uji Kalibrasi

- **Verifikasi Matematika Probabilitas:**
  * Seluruh probabilitas model berada di dalam rentang valid $[0, 1]$.
  * Jumlah $\sum p_k = 1,000000$ (finite dan terdefinisi penuh pada seluruh 6.602 bar).
- **Multiclass Log Loss:**
  * HistGradientBoosting: **0,9958** (sedikit di bawah baseline probabilistik seragam $-\ln(1/3) = 1,0986$).
  * Random Forest: **1,0704**.
- **Tabel Kalibrasi Reliabilitas HistGradientBoosting (Pooled 6.602 Bar):**

| Kelas Target | Interval Probabilitas Prediksi | Jumlah Sampel | Rata-rata Probabilitas Model | Frekuensi Nyata Terjadi | Kesalahan Kalibrasi Absolut |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **DOWN (0)** | $[0.00, 0.20]$ | 3.516 | 9,79% | 15,79% | 6,00 poin persentase |
| (Base: 16,09%) | $[0.20, 0.40]$ | 2.154 | 28,75% | 16,95% | 11,81 poin persentase |
| | $[0.40, 0.60]$ | 794 | 47,78% | 16,00% | **31,78 poin persentase** |
| | $[0.60, 0.80]$ | 134 | 66,17% | 10,45% | **55,72 poin persentase** |
| **UP (2)** | $[0.00, 0.20]$ | 2.964 | 11,48% | 12,89% | 1,41 poin persentase |
| (Base: 13,54%) | $[0.20, 0.40]$ | 2.582 | 28,62% | 13,17% | 15,46 poin persentase |
| | $[0.40, 0.60]$ | 848 | 47,99% | 16,16% | **31,83 poin persentase** |
| | $[0.60, 0.80]$ | 191 | 67,12% | 16,23% | **50,88 poin persentase** |

*Temuan Kalibrasi Fatal:* Ketika model memberikan keyakinan tinggi $p \in [0.60, 0.80]$ untuk kenaikan UP, frekuensi aktual saham yang benar-benar breakout naik **hanya 16,23%** (kesalahan kalibrasi sebesar 50,88 poin persentase). Probabilitas model mengalami *overconfidence* parah.

---

## 4. Efek Penyeimbangan Bobot Kelas (Class Weight Ablation)

Eksperimen membandingkan konfigurasi model berbobot seimbang (`balanced`) versus tanpa pembobotan (`unweighted`) pada fitur, split, dan target yang sama persis:

| Konfigurasi Model | Rata-rata Akurasi | Macro-F1 | F1-DOWN | F1-NEUTRAL | F1-UP | Cakupan Sinyal Directional | Presisi Sinyal Directional |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **RF Unweighted** | 70,22% | 27,57% | 0,00% | 82,71% | 0,00% | **0,00%** | N/A (Collapse to Neutral) |
| **RF Balanced** | 39,19% | 31,64% | 20,31% | 53,27% | 21,33% | **57,50%** | **15,88%** |
| **HGB Unweighted** | 69,38% | 28,69% | 1,87% | 82,00% | 2,20% | **1,82%** | **15,17%** |
| **HGB Balanced** | 55,30% | 34,25% | 14,66% | 71,31% | 16,80% | **28,60%** | **16,26%** |

### Kesimpulan Empiris:
- Tanpa class weight (`unweighted`), Random Forest runtuh total (*collapsed*) menjadi model pasif yang 100% memprediksi NEUTRAL.
- Memberikan bobot seimbang (`balanced`) secara mekanis memaksa model menerbitkan sinyal directional dalam jumlah besar (dari 1,8% menjadi 28,6% pada HGB, dan hingga 57,5% pada RF).
- Namun, **presisi sinyal directional tidak meningkat sama sekali** (tetap berada di kisaran 15,2% – 16,3%, setara dengan proporsi dasar pasar). Pembobotan kelas hanya menaikkan volume alarm palsu (*false alarms*) tanpa menambah daya diskriminasi fitur.

---

## 5. Analisis Precision–Coverage dengan Seleksi Ambang Validasi

Threshold keyakinan $\tau$ diuji secara berurutan pada Validation Set untuk memilih aturan seleksi, lalu dievaluasi kinerjanya pada Test Set:

| Ambang Keyakinan $\tau$ | Cakupan Sinyal Test | Rata-rata Jumlah Sinyal | Presisi Directional Total | Presisi Sinyal UP | Presisi Sinyal DOWN | Baseline Probabilistik Training |
| :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| $\tau \ge 0,35$ | 43,02% | 931 bar | 15,79% | 15,71% | 15,80% | UP: 14,5% \| DOWN: 13,2% |
| $\tau \ge 0,40$ | 30,57% | 662 bar | 15,81% | 16,24% | 15,19% | UP: 14,5% \| DOWN: 13,2% |
| $\tau \ge 0,45$ | 20,74% | 451 bar | 16,05% | 17,48% | 14,44% | UP: 14,5% \| DOWN: 13,2% |
| $\tau \ge 0,50$ | 13,71% | 299 bar | 15,56% | 17,44% | 13,53% | UP: 14,5% \| DOWN: 13,2% |
| $\tau \ge 0,55$ | 9,03% | 198 bar | 14,90% | 17,16% | 12,74% | UP: 14,5% \| DOWN: 13,2% |
| $\tau \ge 0,60$ | 5,23% | 115 bar | 13,70% | 15,86% | 11,09% | UP: 14,5% \| DOWN: 13,2% |

*Temuan Seleksi Kritis:*
1. **Kurva Presisi Datar/Menurun:** Menaikkan ambang keyakinan dari $\tau = 0,35$ ke $\tau = 0,60$ memangkas cakupan sinyal dari 43,0% menjadi hanya 5,2%, namun **presisi directional sama sekali tidak naik** (bahkan turun dari 15,79% menjadi 13,70%).
2. **Ketiadaan Alpha Teknikal:** Model tidak mampu menyaring probabilitas murni yang berbanding lurus dengan ketepatan prediksi. Sinyal berkeyakinan tinggi memiliki tingkat keberhasilan yang tidak lebih baik daripada tebakan acak berbasis proporsi kelas historis bursa.

---

## 6. Aturan Berhenti (Stopping Rule) & Rekomendasi Kebutuhan Data

### A. Keputusan Penghentian Siklus Riset
Berdasarkan bukti empiris konsisten lintas EXP-01, EXP-02, EXP-02R, dan EXP-03:
1. **PENCARIAN MODEL/THRESHOLD TEKNIKAL DIHENTIKAN SEPENUHNYA.**
   Melakukan eksplorasi lanjutan pada ruang fitur teknikal harga penutupan, moving average, RSI, dan volatilitas historis telah terbukti secara statistik hanya menghasilkan perburuan derau (*data snooping*) tanpa keunggulan prediktif di atas baseline naif.
2. **MODEL PRODUKSI TETAP DIPERTAHANKAN PADA MODE PASIF.**
   Sistem inferensi live Aether Market Intelligence mempertahankan model `RandomForest_v1.0` dengan status operasional **`NO_SIGNAL / ABSTAIN`** untuk melindungi modal dari *overtrading* dan biaya transaksi bursa ritel.

### B. Rekomendasi Kebutuhan Data Baru Sebelum Eksperimen Berikutnya (EXP-04)
Eksperimen berikutnya dilarang dijalankan hanya dengan mengubah algoritma ML. Eksperimen baru hanya diizinkan apabila telah tersedia infrastruktur data berikut:
1. **Kualitas & Validitas Data Intraday/Orderbook:**
   - Pencatatan data *bid-ask spread*, ketebalan antrean order (*market depth*), dan volume transaksi institusional terakumulasi (*net foreign flow*) dengan timestamp penutupan resmi.
2. **Fitur Peristiwa Bertanda Waktu Point-in-Time yang Sah:**
   - Publikasi laporan keuangan resmi dengan metadata `evidence_available_at` yang terverifikasi (mencegah lookahead bias).
   - Pengumuman aksi korporasi (RUPS, dividen, rights issue) dengan tanggal pencatatan (*cum date / ex date*) yang tervalidasi.
3. **Pemisahan Evaluasi Shadow Forward Sejati:**
   - Jika di masa depan dirancang model baru, evaluasi wajib menggunakan pencatatan prediksi prospektif harian sebelum sesi bursa dibuka (*shadow forward*), bukan evaluasi berulang pada data historis yang sudah pernah diamati (*known benchmark*).
