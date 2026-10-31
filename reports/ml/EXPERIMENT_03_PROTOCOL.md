# PROTOKOL PENELITIAN MACHINE LEARNING: EXP-03
**Klasifikasi Tiga Kelas (UP / NEUTRAL / DOWN) Berbasis Barrier Volatilitas ATR-14**
**Aether Market Intelligence — Subdivisi Riset Kuantitatif Terisolasi**
**Tanggal Registrasi Protokol:** 28 Oktober 2026  
**Status Audit:** FROZEN PROTOCOL (DIBEKUKAN SEBELUM EVALUASI AKHIR)

---

## 1. Rekonsiliasi Sampel & Alur Data

### A. Alur Bertingkat Jumlah Sampel
1. **Raw Bar Data:** 13.936 bar dari 10 saham likuid BEI (periode 2021-01-04 s.d. 2026-10-23).
2. **Warmup & Fitur Valid (EXP-01):** 13.736 bar (200 bar pertama dibuang akibat rolling window 20 sesi bursa).
3. **Fitur Subset B Valid (EXP-03):** 13.686 bar (50 bar warmup benchmark 5-hari).
4. **Target Tersedia:** 13.593 bar (93 bar tidak memiliki target outcome yang valid karena missing provider bar atau akhir data).
5. **Partisi Out-of-Sample Walk-Forward (Fold 1–3):** **6.602 bar unik** (periode test 2024-01-02 s.d. 2026-10-16).

### B. Klarifikasi Klaim Denominator Netral (22,31% vs 22,62%)
- Pada laporan EXP-01/02, persentase **22,31%** dihitung dari denominator **13.736 bar** ($3.064 / 13.736 = 22,306\% \approx 22,31\%$).
- Pada dataset `exp02_clean` (13.546 bar), jumlah sampel netral $[-1.0\%, +1.0\%]$ adalah 3.069 bar ($3.069 / 13.546 = 22,656\% \approx 22,66\%$).
- Perbedaan 0,35% murni disebabkan perbedaan denominator antara dataset fitur EXP-01 (13.736 bar) dan dataset fitur bersih EXP-02 (13.546 bar), bukan rekayasa angka.

---

## 2. Definisi Target Tiga Kelas Adaptif ATR-14

Target pergerakan diukur pada akhir horizon 5 sesi bursa resmi:
$$r_5 = \frac{Close_{\text{target}} - Close_t}{Close_t}$$
$$\text{Barrier}_t = 1.5 \times \frac{\text{ATR}_{14, t}}{Close_t}$$

- **Kelas 2 (UP):** $r_5 > \text{Barrier}_t$
- **Kelas 0 (DOWN):** $r_5 < -\text{Barrier}_t$
- **Kelas 1 (NEUTRAL):** $-\text{Barrier}_t \le r_5 \le \text{Barrier}_t$

### Kaidah Protokol yang Dibekukan:
1. **Titik Evaluasi:** Mengklasifikasikan return kumulatif pada akhir sesi ke-5 bursa, bukan urutan sentuhan batas intraday.
2. **Ketiadaan Target:** Jika sesi ke-5 tidak memiliki harga penutupan resmi dari bursa, observasi dikeluarkan dari dataset (`EXCLUDED_MISSING_TARGET`), tidak diganti tanggal setelahnya.
3. **Integritas Waktu:** $\text{ATR}_{14, t}$ dihitung murni menggunakan bar hingga hari $t$ pukul 16:00 WIB. Faktor pengali 1.5 dibekukan dan dilarang dioptimasi pada test set.

---

## 3. Spesifikasi Fitur, Model & Pemisahan Waktu

- **Fitur Masukan:** Subset B (16 fitur teknikal dan benchmark pasar equal-weight top 10).
- **Model Kandidat:**
  * `RandomForestClassifier(n_estimators=100, max_depth=6, min_samples_leaf=20, random_state=42, class_weight='balanced')`
  * `HistGradientBoostingClassifier(max_iter=60, min_samples_leaf=30, random_state=42, class_weight='balanced')`
- **Model Pembanding:**
  * `Baseline_Always_Neutral`: Selalu memprediksi kelas 1 (NEUTRAL).
  * `Baseline_Train_Majority`: Memprediksi kelas mayoritas dari partisi latihan (selalu NEUTRAL, mencakup 72,28% sampel).
- **Metodologi Validasi:** 3-Fold Walk-Forward non-overlapping (2024, 2025, 2026-YTD) dengan calendar purging pada jendela horizon 5 hari. Periode test berstatus `KNOWN BENCHMARK`.

---

## 4. Metrik Tiga Kelas & Resampling Berpasangan

- **Metrik Utama:** Balanced Accuracy dan Macro-F1 (mencegah evaluasi bias pada kelas mayoritas NEUTRAL).
- **Metrik Directional:** Presisi dan coverage keputusan directional (UP/DOWN). Keputusan directional yang ternyata berujung NEUTRAL dihitung sebagai kesalahan (false positive directional).
- **Estimasi Ketidakpastian:** Paired Block Bootstrap berdasarkan tanggal (panjang blok 5 hari bursa, 1.000 resample). Hasil selisih dilaporkan sebagai proporsi replikasi bootstrap $\le 0$, bukan probabilitas posterior Bayes.
