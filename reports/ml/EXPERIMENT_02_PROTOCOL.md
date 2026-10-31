# PROTOKOL PENELITIAN MACHINE LEARNING: EXP-02
**Ablation Study: Nilai Tambah Benchmark Relatif & Normalisasi Aktivitas Pasar**
**Aether Market Intelligence — Subdivisi Riset Kuantitatif Terisolasi**
**Tanggal Registrasi Protokol:** 28 Oktober 2026  
**Status Audit:** FROZEN PROTOCOL (DIBEKUKAN SEBELUM EVALUASI AKHIR)

---

## 1. Audit Validitas & Pelajaran dari EXP-01

Berdasarkan audit pipeline pada EXP-01:
1. **Majority Baseline Deterministik:** Baseline kelas mayoritas wajib dihitung murni dari set data *Train* fold yang bersangkutan, bukan dari set evaluasi (*Test*).
2. **Penentuan Target t+5 Sesuai Sesi Bursa Riil:** Target 5 hari bursa wajib diverifikasi terhadap kalender perdagangan bursa BEI riil (`true_target_market_date`). Jika emiten mengalami suspensi perdagangan atau *missing bar*, pergeseran bar tidak boleh diasumsikan sebagai 5 hari kalender.
3. **Pemisahan & Purging Berbasis Tanggal Selesai Aktual (*label_end_date*):** Purging data latih wajib dilakukan dengan mengecualikan seluruh sampel yang tanggal selesainya (`true_target_market_date`) melintasi batas akhir partisi train (`train_end`), bukan hanya membuang N bar secara naif.
4. **Penanganan Return Nol & Flat Market:** Pergerakan harga 5-hari bernilai persis 0.00% (terjadi pada 3,87% sampel) dikelompokkan ke dalam kelas 0 (non-positif) pada klasifikasi biner arah murni, dan dieksplorasi secara terpisah pada target material dengan zona netral (*neutral zone*).
5. **Status Holdout EXP-01:** Periode 2025–2026 pada EXP-01 telah menjadi *known benchmark* dan tidak boleh lagi diklaim sebagai *untouched holdout* untuk penyesuaian hyperparameter lanjutan. Oleh karena itu, EXP-02 beralih ke metodologi **Walk-Forward Validation 3-Fold non-overlapping** lintas waktu.

---

## 2. Hipotesis & Ruang Pencarian Eksperimen EXP-02

### A. Hipotesis Kuantitatif
- **Hipotesis H1 (Benchmark Relatif):** Penambahan return relatif saham terhadap indeks pasar equal-weight BEI (`rel_ret_1`, `rel_ret_5`) mengurangi dampak kebisingan makro dan meningkatkan kemampuan model membedakan saham yang memiliki kekuatan relatif (*relative strength*).
- **Hipotesis H2 (Normalisasi Aktivitas & Volatilitas):** Mengukur aktivitas volume dalam unit skor deviasi standar 20-hari (`vol_zscore20`) dan rasio rentang pergerakan terhadap ATR (`move_5_to_atr`) memberikan sinyal kompresi/ekspansi volatilitas yang lebih prediktif daripada volume mentah.
- **Hipotesis H3 (Target Material dengan Buffer Netral):** Mengabaikan pergerakan sempit (zona netral $[-1.0\%, +1.0\%]$) membersihkan derau pasar (*market noise*) dan meningkatkan separasi kelas prediktif.

### B. Definisi 3 Subset Fitur untuk Uji Ablation
1. **Subset A (Base 12 Fitur):**
   - Momentum: `ret_1`, `ret_3`, `ret_5`
   - Trend/Moving Average: `ema9_ratio`, `ema20_ratio`, `ema_spread`
   - Osilator & Volatilitas: `rsi14`, `atr14_ratio`, `volatility20`
   - Volume & Level Kunci: `rvol20`, `dist_support20`, `dist_resistance20`
2. **Subset B (Base + Benchmark Relatif - 16 Fitur):**
   - 12 fitur dasar + `bench_ret_1` (return harian pasar), `bench_ret_5` (return 5 hari pasar), `rel_ret_1` ($ret_1 - bench\_ret_1$), `rel_ret_5` ($ret_5 - bench\_ret_5$).
3. **Subset C (Base + Benchmark + Normalisasi Aktivitas - 19 Fitur):**
   - 16 fitur Subset B + `vol_zscore20` (z-score volume 20 hari), `move_5_to_atr` (perubahan harga 5 hari dibagi ATR14), `vol_ratio_5_20` (rasio volatilitas 5 hari terhadap 20 hari).

---

## 3. Desain Partisi Walk-Forward & Estimasi Ketidakpastian

### A. 3-Fold Expanding Walk-Forward Non-Overlapping
| Fold | Periode Training | Purged Train Boundary | Periode Validasi (Threshold Selection) | Purged Val Boundary | Periode Test Out-of-Sample |
| :---: | :--- | :--- | :--- | :--- | :--- |
| **Fold 1** | 2021-03-01 s.d. 2022-12-23 | Target $\le$ 2022-12-31 | 2023-01-02 s.d. 2023-12-22 | Target $\le$ 2023-12-31 | 2024-01-02 s.d. 2024-12-30 (2.350 bar) |
| **Fold 2** | 2021-03-01 s.d. 2023-12-22 | Target $\le$ 2023-12-31 | 2024-01-02 s.d. 2024-12-22 | Target $\le$ 2024-12-31 | 2025-01-02 s.d. 2025-12-30 (2.339 bar) |
| **Fold 3** | 2021-03-01 s.d. 2024-12-22 | Target $\le$ 2024-12-31 | 2025-01-02 s.d. 2025-06-23 | Target $\le$ 2025-06-30 | 2025-07-01 s.d. 2026-10-16 (3.149 bar) |

*Catatan Purging:* Sampel pada jendela akhir partisi yang target 5-harinya melintasi batas partisi dibuang (*purged*) secara ketat berdasarkan tanggal bursa sesungguhnya.

### B. Estimasi Ketidakpastian Block Bootstrap Berdasarkan Tanggal
Karena harga saham pada tanggal yang sama memiliki ketergantungan lintang (*cross-sectional correlation*), evaluasi out-of-sample tidak memperlakukan bar saham sebagai observasi independen. Ketidakpastian diestimasi menggunakan **Block Bootstrap by Trading Date (1.000 resamples)** untuk menghasilkan 95% Confidence Interval empiris yang realistis.
