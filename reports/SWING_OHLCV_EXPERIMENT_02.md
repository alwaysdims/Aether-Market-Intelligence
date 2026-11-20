# LAPORAN EKSPERIMEN SWING OHLCV OFFLINE KEDUA (EXP-02)
**Aether Market Intelligence Platform**  
**Tanggal Eksperimen**: 04 November 2026  
**Status Eksperimen**: **SELESAI (ISOLASI TOTAL - ZERO PRODUCTION IMPACT)**  
**Keputusan Akhir (Final Verdict)**: **`REJECTED`** (Seluruh Ablation A, B, dan C Gagal Acceptance Gate 1 dan Gate 4).

---

## 1. Koreksi Metodologis & Batasan Integritas

1. **Status Periode 26 Okt – 04 Nov 2026**:
   Ditetapkan strictly sebagai **RESERVED HISTORICAL WINDOW / REPLAY**. Dilarang diberi label forward atau mengklaim timestamp keputusan lampau. Forward shadow sejati hanya berlaku untuk data masa depan yang dibekukan pasca-eksperimen ini.
2. **Realistic Decision Time**:
   Waktu rilis EOD tidak diasumsikan instan pada pukul 16:30 WIB. Ketersediaan data bergantung pada `available_at` kliring bursa. Prediksi yang terlambat dicatat pada waktu riil dan dilarang di-backdate.
3. **Likuiditas Nominal Murni**:
   Nilai transaksi dihitung murni dari data harga dan volume unadjusted:
   $$\text{Turnover}_{\text{nominal}} = \text{Close}_{\text{unadjusted}} \times \text{Volume}_{\text{unadjusted}}$$
   Dilarang mengalikan harga adjusted dengan volume adjusted untuk proksi omzet nominal rupiah.
4. **Peran Dynamic Cost-Aware Hurdle**:
   Hurdle $\theta_t = \text{Friction Drag}(\text{Close}_t) + 1.0\%$ merupakan proksi seleksi keputusan, **BUKAN jaminan transaksi pasti untung**. Return transaksi riil tetap dihitung dari selisih eksekusi Open $t+1$ ke Close $t+5$ dikurangi 2 tick slippage dan komisi bursa $0.40\%$.

---

## 2. Dataset EXP-02 & Audit Integrity

- **File Dataset**: `reports/swing_ohlcv_dataset_exp02.csv`
- **SHA-256 Hash**: `0d6587ab20b3baca3b2e3dd16e4b96e2fc7a8c28e99c650c3eea1c63f553257b`
- **Total Bar Eligible**: **359.505 bar** (Train: 171.487 • Validation: 94.510 • Test: 93.508).
- **Distribusi Kelas Target Dynamic Hurdle**:
  * Train: NEUTRAL = 53.3%, DOWN = 25.1%, UP = 21.6% (Majority class bergeser ke **NEUTRAL**).
  * Test: NEUTRAL = 48.9%, DOWN = 32.5%, UP = 18.6%.
- **Dampak Filter Kelayakan B (Point-in-Time $\text{Close}_t \ge 200$ & $\text{Turnover 20D} \ge 500\text{ Juta IDR}$)**:
  * Test Universe Total: 93.508 bar.
  * Test Eligible Subset B: **30.395 bar** (**Coverage Universe: 32.51%**; 67.49% semesta tereliminasi akibat harga di bawah fraksi Rp 200 atau likuiditas rendah).

---

## 3. Hasil Komparasi Terkendali: Ablation A vs B vs C

Evaluasi dijalankan pada Test Set Mei - Oktober 2026 (status: **Exploratory Exposed Benchmark**):
- **Ablation A**: Model Linier LogReg dievaluasi pada seluruh universe uji ($N=93.508$).
- **Ablation B**: Prediksi Model A existing yang difilter secara ketat pada subset lolos syarat eksekusi point-in-time ($N=30.395$, tanpa training ulang).
- **Ablation C**: Model Non-Linier HistGradientBoosting yang dilatih pada Train subset B dan dievaluasi pada sampel subset B yang sama ($N=30.395$).

| Metrik Evaluasi | ABLATION A (Full Universe Linear + Hurdle) | ABLATION B (Model A Difilter ke Subset B) | ABLATION C (HistGradientBoosting pada Subset B) |
|---|---|---|---|
| **Sampel Uji Evaluasi** | 93.508 bar | 30.395 bar (Common Sample) | 30.395 bar (Common Sample) |
| **Arsitektur Model** | Logistic Regression ($C=0.01$, balanced) | Prediksi Model A yang difilter ke Subset B | HistGradientBoosting (100 trees, lr 0.05, reg 1.0) |
| **Balanced Accuracy OOS** | **42.70%** (vs Base 33.33%) | **41.60%** (vs Base 33.33%) | **41.62%** (vs Base 33.33%) |
| **Macro F1-Score** | 0.4167 | 0.4068 | 0.4154 |
| **OvR Macro ROC-AUC** | **0.6126** | **0.6063** | **0.6110** |
| **Threshold Abstain ($\tau_{\text{up}}$)** | 0.42 | 0.42 | 0.42 |
| **Coverage Keputusan UP** | **2.97%** (2.781 skenario) | **5.23%** (1.591 skenario) | **19.50%** (5.928 skenario) |
| **Precision UP Selektif** | 32.40% (vs prior 18.6%) | 35.14% (vs prior 20.1%) | 32.93% (vs prior 20.1%) |
| **Rata-rata Gross Return** | **-0.88%** | **-0.84%** | **-1.30%** |
| **Rata-rata Net Return** | **-2.73%** | **-2.36%** | **-2.73%** |
| **Win Rate Pasca-Biaya** | 33.84% | 37.08% | 36.47% |
| **Paired Block Bootstrap 95% CI** | $[+0.0764, +0.1074]$ ($P=0.00\%$) | $[+0.0620, +0.0984]$ ($P=0.00\%$) | $[+0.0690, +0.0971]$ ($P=0.00\%$) |

---

## 4. Analisis Diagnostik & Temuan Empiris

1. **Dampak Dynamic Hurdle (Ablation A vs EXP-01)**:
   - Dynamic hurdle memangkas coverage keputusan UP dari 26.16% (EXP-01) menjadi 2.97% (Ablation A).
   - Kerugian neto berkurang dari $-3.47\%$ menjadi $-2.73\%$, namun rata-rata gross return tetap negatif ($-0.88\%$). Hurdle berhasil menyaring saham bervolatilitas rendah, namun tidak menciptakan keunggulan arah (*directional edge*).
2. **Dampak Filter Kelayakan Eksekusi (Ablation B vs Ablation A)**:
   - Filter B mengeliminasi 67.49% semesta (menyisakan 30.395 bar likuid $\ge \text{Rp } 200$).
   - Win rate meningkat ke **37.08%** dan kerugian neto membaik ke **$-2.36\%$** (terbaik di antara seluruh konfigurasi). Namun gross return tetap negatif ($-0.84\%$).
3. **Perbandingan Model A vs Model C pada Subset B yang Sama (Ablation B vs C)**:
   - Pada subset B yang sama ($N=30.395$), Model A (Linier) mencatat presisi $35.14\%$ dan net return $-2.36\%$.
   - Model C (HistGradientBoosting) mencatat presisi lebih rendah ($32.93\%$), gross return lebih buruk ($-1.30\%$ vs $-0.84\%$), dan net return lebih buruk ($-2.73\%$ vs $-2.36\%$).
   - *Batasan Kesimpulan*: Model C tidak memberikan perbaikan performa ekonomi dibanding baseline linier pada sampel yang sama. Temuan ini terbatas pada arsitektur, parameter, dan 9 fitur teknikal yang diuji, serta bersifat eksploratif pada exposed historical benchmark.

---

## 5. Verifikasi Acceptance Gate & Keputusan Akhir

| Gate Evaluasi | Syarat Penerimaan | Ablation A | Ablation B | Ablation C |
|---|---|---|---|---|
| **Gate 1** | Balanced Accuracy OOS $> 54.0\%$ | ❌ **FAIL** (42.70%) | ❌ **FAIL** (41.60%) | ❌ **FAIL** (41.62%) |
| **Gate 2** | Macro ROC-AUC OOS $\ge 0.580$ | ✔️ **PASS** (0.6126) | ✔️ **PASS** (0.6063) | ✔️ **PASS** (0.6110) |
| **Gate 3** | Bootstrap 95% CI $\Delta > 0$ vs Majority | ✔️ **PASS** ($P=0.00\%$) | ✔️ **PASS** ($P=0.00\%$) | ✔️ **PASS** ($P=0.00\%$) |
| **Gate 4** | Positive Net Economic Return | ❌ **FAIL** (-2.73%) | ❌ **FAIL** (-2.36%) | ❌ **FAIL** (-2.73%) |
| **STATUS** | **Semua Gate Wajib Lolos** | **`REJECTED`** | **`REJECTED`** | **`REJECTED`** |

### KEPUTUSAN FINAL: **`REJECTED`**
Seluruh kandidat model eksperimen EXP-02 **DITOLAK SECARA RESMI**. Model berbasis OHLCV murni (baik linier maupun gradient boosting) terbukti tidak mampu menghasilkan alpha directional positif pasca-friksi pada semesta ekuitas bursa domestik. Tidak ada EXP-03 atau tuning ulang pada benchmark yang sama.

---

## 6. Protokol Forward Shadow Tanpa Rekomendasi Publik

Mengingat seluruh ablation offline ditolak, sistem **DILARANG** melakukan deployment ke produksi ataupun mengirim rekomendasi publik ke Telegram.

Jika di masa mendatang tim riset ingin mengamati perilaku model secara bayangan (*forward shadow*):
1. **Pemisahan Total**: Shadow model hanya berjalan sebagai *listener observer* lokal di background.
2. **Snapshot Saat Keputusan**: Hanya boleh menggunakan snapshot data yang berstatus definitif pasca-kliring harian (`is_eod_final = true` dan `available_at` tercatat).
3. **Log Imutabel Bertimestamp**: Prediksi dicatat ke file log lokal dengan hash SHA-256 sebelum jam pembukaan bursa $t+1$ (09:00 WIB). Dilarang keras melakukan backdating.
4. **Nol Rekomendasi Publik**: Tidak ada sinyal yang dipancarkan ke kanal Telegram dan tidak ada perubahan parameter model deterministik operasional.

---

## 7. Lokasi Artefak Terisolasi (Audit Trail)

- **Laporan Eksperimen**: `reports/SWING_OHLCV_EXPERIMENT_02.md`
- **Dataset Fitur CSV**: `reports/swing_ohlcv_dataset_exp02.csv`
  * SHA-256: `0d6587ab20b3baca3b2e3dd16e4b96e2fc7a8c28e99c650c3eea1c63f553257b`
- **Prediksi Test Ablation C**: `reports/artifacts_exp02/test_predictions_exp02_ablation_c.csv`
  * SHA-256: `1e428e543d5fb69619ad0b4b171e53fd109a2e41f918e42eca58917475fec32f`
- **Status Verifikasi Artefak Prediksi Ablation A dan B**:
  * **STATUS: BELUM TERVERIFIKASI PADA FILE ARTEFAK DEDIKASI TERSENDIRI**.
  * File CSV fisik yang tersimpan di disk `reports/artifacts_exp02/` saat ini hanya mencakup artefak inferensi Ablation C (`test_predictions_exp02_ablation_c.csv`).
  * Nilai metrik Ablation A dan B yang dilaporkan berasal dari evaluasi memori skrip `scripts/run_swing_ohlcv_experiment_02.py` (Model A dievaluasi pada full test dan disaring via `test_mask_b`).
  * Karena file prediksi individual per baris untuk Ablation A dan B belum diekspor ke file CSV fisik ber-hash independen, status pembuktian per-sampelnya ditandai **UNVERIFIED_FROM_DEDICATED_ARTIFACT** (tidak diubah atau dikarang).
- **Summary JSON**: `reports/artifacts_exp02/experiment_02_summary.json`
- **Skrip Ekstraksi & Runner**:
  * `scripts/extract_experiment_dataset_exp02.php`
  * `scripts/run_swing_ohlcv_experiment_02.py`
