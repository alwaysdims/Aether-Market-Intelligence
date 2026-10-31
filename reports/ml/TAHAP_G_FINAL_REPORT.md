# LAPORAN AKHIR TAHAP G: INTEGRITAS BACKTEST, EKSPANSI DATA & PENELITIAN SINYAL PREDIKTIF
## AETHER MARKET INTELLIGENCE

**Tanggal Rilis:** 25 Oktober 2026  
**Klasifikasi Proyek:** Sistem Analisis Pasar Modal & Riset Kuantitatif Saham IDX  
**Lingkungan Operasional:** `E:\Aether-Market-Intelligence`  
**Status Keseluruhan:** VERIFIED, AUDITED & HARDENED

---

### RINGKASAN JAWABAN 10 PERTANYAAN MANDATORI TAHAP G

#### 1. Apakah backtester sudah valid?
**Status: VERIFIED.**  
Simulator `DailyPortfolioSimulator` pada `quant-engine/src/daily_eod_backtester.py` telah diaudit dan diverifikasi memenuhi seluruh kaidah akuntansi portofolio institusional:
- Pembatasan kas ketat: Kas modal tidak pernah bernilai negatif (`cash >= 0`).
- Kapasitas portofolio terikat: Maksimal 5 posisi serentak, alokasi per posisi 20% modal.
- Aturan fisik Bursa Efek Indonesia (BEI): Minimum 1 lot (100 lembar), fraksi lot ditolak.
- Friksi bursa riil: Biaya beli 0,15%, biaya jual 0,25%, dan slippage 1 tick BEI.
- Urutan peristiwa same-bar teratur: Peristiwa EXIT selalu diproses sebelum peristiwa ENTRY pada bar yang sama untuk membebaskan kas.

#### 2. Apa penyebab return ekstrem (-1094.96% & -1039.17%)?
**Status: VERIFIED & IDENTIFIED.**  
Akar masalahnya adalah **bug akumulasi linear naif tanpa kendala portofolio (*unconstrained sum bug*)** pada fungsi helper `simulate_trade_returns` di `quant-engine/src/ml_experiment_engine.py`:
- Sistem mengeksekusi ke-4.140 sinyal beli secara bersamaan dengan meminjam alokasi fiktif Rp100.000 per posisi tanpa mengecek sisa kas.
- Akibat kerugian rata-rata -1,32% per trade karena friksi bursa BEI, akumulasi kerugian fiktif mencapai -Rp5,46 juta pada modal Rp500.000, menyebabkan ekuitas minus (-Rp4,96 juta) dan return semu -1094,96%.
- **Hasil Riil Pasca-Rekonsiliasi (dengan pembatasan kas modal Rp500.000):**
  * Logistic Regression: 157 trade dieksekusi, Win Rate 33,76%, Return Riil **-75,29%** (Ekuitas akhir Rp123.552,46).
  * HistGradientBoosting: 151 trade dieksekusi, Win Rate 32,45%, Return Riil **-73,27%** (Ekuitas akhir Rp133.668,49).
  * Random Forest: 148 trade dieksekusi, Win Rate 35,12%, Return Riil **-62,40%** (Ekuitas akhir Rp187.980,10).

#### 3. Apakah fold overlap sudah diperbaiki?
**Status: VERIFIED.**  
Tumpang tindih 6 bulan antara Fold 2 dan Fold 3 pada `quant-engine/src/walk_forward_evaluator.py` telah diperbaiki menjadi 3 partisi uji yang **100% non-overlapping**:
- Fold 1 Test: `2024-01-01` s.d. `2024-12-31` (1 tahun penuh)
- Fold 2 Test: `2025-01-01` s.d. `2025-12-31` (1 tahun penuh)
- Fold 3 Test: `2026-01-01` s.d. `2026-10-23` (10 bulan berjalan)
Nol tanggal atau transaksi yang dihitung ganda.

#### 4. Apakah terdapat data leakage?
**Status: VERIFIED CLEAN (Bebas Leakage).**  
- Seluruh 20 fitur teknikal dan makro dihitung murni dengan fungsi jendela ke belakang (*rolling backward-looking*).
- Embargo 5 hari bursa diterapkan secara fisik: transaksi yang keluar melintasi batas akhir partisi Train dibuang dari proses fitting.
- Standard scaler dan imputasi nilai median hanya di-fit pada partisi Train (`X_train`), lalu di-transform ke Validation dan Test.
- Ambang batas keyakinan model ditentukan murni pada Validation set, bukan Test set.

#### 5. Apakah model mengungguli baseline?
**Status: FAILED (Model Belum Mengungguli Baseline).**  
- **Majority Down Baseline:** Akurasi **54,84%**, ROC-AUC 0.5000.
- **Random Forest (Model Terbaik):** Akurasi **53,09%**, Balanced Accuracy 50,61%, ROC-AUC **0.5060**.
- **HistGradientBoosting:** Akurasi 51,97%, Balanced Accuracy 50,39%, ROC-AUC 0.5031.
- **Logistic Regression:** Akurasi 51,35%, Balanced Accuracy 49,19%, ROC-AUC 0.4966.
Seluruh model machine learning menghasilkan akurasi di bawah baseline naif dan ROC-AUC mendekati tebakan acak (0.50).

#### 6. Apakah berita meningkatkan prediksi?
**Status: FAILED (Berita Tidak Menghasilkan Alpha Signifikan).**  
Penambahan proxy sentimen berita LKBN ANTARA pada pengujian out-of-sample hanya meningkatkan akurasi sebesar **+0,07%** (dari 51,71% menjadi 51,78%), yang secara statistik merupakan kebisingan acak. Sinyal berita tidak diizinkan masuk ke model otomatis dan hanya berfungsi sebagai konteks kualitatif.

#### 7. Berapa precision dan coverage yang benar?
**Status: VERIFIED.**  
Berdasarkan analisis prediksi selektif (*Selective Prediction with Wilson 95% Confidence Interval*) pada 4.187 bar uji out-of-sample:
- Ambang batas standar $\tau = 0.50$: Coverage 100%, Presisi Gabungan 51,16% (Wilson CI [49.64%, 52.67%]), Presisi Sinyal UP 45,41%.
- Ambang batas selektif $\tau = 0.70$:
  * Random Forest: Coverage **0.00%** (**NO SIGNAL**).
  * HistGradientBoosting: Coverage 11,15% (467 sinyal), Presisi Gabungan 52,89% (Wilson CI [48.36%, 57.38%]).
- Ambang batas tinggi $\tau = 0.85$: HGB Coverage 0,33% (14 sinyal), Presisi 71,43% (Wilson CI [45.35%, 88.28%]).
- Ambang batas target $\tau = 0.90$:
  * Random Forest: **NO SIGNAL** (0 sinyal, Coverage 0,00%).
  * HistGradientBoosting: Hanya **2 sinyal** dari 4.187 peluang (Coverage 0,05%), Presisi 50,00% (1 benar, 1 salah), dengan Wilson CI membentang dari **9,45% hingga 90,55%**.
  * **Kesimpulan:** Target presisi 90% secara statistik **TIDAK TERCAPAI** dan tidak dapat diklaim berhasil.

#### 8. Apakah ada sinyal yang layak diuji prospektif?
**Status: NO SIGNAL (ABSTAIN).**  
Karena tidak ada sinyal dengan keyakinan memadai yang menghasilkan edge statistik di atas biaya friksi bursa BEI, sistem secara resmi mengeluarkan keputusan **NO SIGNAL / ABSTAIN**. Menolak bertransaksi adalah keputusan yang tepat dan rasional untuk melindungi modal dari *whipsaw* pasar.

#### 9. Apa keterbatasan dataset?
1. **Survivorship Bias:** Universe 10 saham likuid terkemuka tahun 2026 diuji mundur ke 2021, mengabaikan saham yang delisting atau masuk papan pemantauan khusus.
2. **Signal-to-Noise Ratio Rendah:** Horizon 5-hari pada data harga harian EOD sangat dipengaruhi volatilitas jangka pendek dan friksi biaya transaksi.
3. **Split vs Unadjusted Price:** Penyesuaian split otomatis dari yfinance membuat harga tampak kontinu, namun mendistorsi perhitungan kebutuhan kas nominal per 1 lot saham di masa lalu.
4. **Ketiadaan Data Berita Historis Granular:** Berita RSS publik hanya dapat dikumpulkan secara prospektif, sehingga tidak tersedia data sentimen historis point-in-time dari tahun 2021.

#### 10. Apa pekerjaan tahap berikutnya?
1. Mempertahankan pembekuan strategi terkontrol EXP-04 (Return -18,82%, MaxDD 21%) pada simulasi paper trading forward.
2. Melanjutkan akumulasi data forward EOD dan berita secara point-in-time via Task Scheduler tanpa intervensi manual.
3. Mengembangkan model filter rezim pasar berbasis makroekonomi (suku bunga BI dan inflasi) sebelum mencoba memprediksi arah saham individual.
4. Menghindari arsitektur deep learning yang rumit sebelum ada keunggulan prediktif yang terbukti pada data tabular sederhana.

---

### MATRIKS DOKUMEN & ARTEFAK TAHAP G YANG TELAH DITERBITKAN

1. `reports/ml/backtest_integrity_audit.md` (Audit integritas akuntansi kas & akar masalah return ekstrem)
2. `reports/ml/temporal_validation_audit.md` (Audit validasi temporal, eliminasi fold overlap & embargo bursa)
3. `reports/ml/feature_ablation.md` (Ablation study 4 subset fitur: Base 12, Momentum 15, IHSG 16, All 20)
4. `reports/ml/tahap_g_model_comparison.md` (Komparasi Baseline, LR, HGB, Random Forest per ticker & per rezim pasar)
5. `reports/ml/selective_prediction_analysis.md` (Analisis prediksi selektif, coverage vs presisi, Wilson 95% CI)
6. `reports/news/news_signal_validation.md` (Validasi integritas sinyal berita 4-lapisan & disiplin point-in-time)
7. `reports/ml/TAHAP_G_FINAL_REPORT.md` (Laporan akhir komprehensif Tahap G)
8. `quant-engine/tests/test_tahap_g_regression.py` (5 regression test suite otomatis)
9. `data/cleaned/^JKSE_cleaned.csv` (Data benchmark IHSG bersih 1.398 bar)

**Status Automated Test Suite:**
- Quant Engine Pytest: **77 passed** (100%)
- Python Services Adapter: **9 passed** (100%)
- Laravel PHPUnit Feature/Unit: **73 passed** (100%)
- **Total Pengujian Berhasil: 159 tests PASS 100%.**
