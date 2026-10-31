# PROTOKOL EKSPERIMEN MACHINE LEARNING & EVALUASI
## AETHER MARKET INTELLIGENCE

Dokumen ini mendefinisikan protokol eksperimen machine learning yang dapat direproduksi (*reproducible*), bebas kebocoran data (*leakage-free*), dan selaras dengan evaluator horizon 5 hari bursa kalender BEI.

---

## 1. Definisi Target Prediksi & Kesejajaran Evaluator

1. **Target Utama (5-Day Directional Return):**
   Model memprediksi arah pergerakan return penutupan (*close-to-close*) persis 5 hari bursa ke depan:
   $$\text{Return}_{5d} = \frac{\text{Close}_{t+5} - \text{Close}_t}{\text{Close}_t}$$
2. **Formulasi Biner (Directional Classification):**
   $$y_t = \begin{cases} 1 & \text{jika } \text{Return}_{5d} > 0.00 \\ 0 & \text{jika } \text{Return}_{5d} \le 0.00 \end{cases}$$
3. **Pembedaan Tegas vs Triple-Barrier:**
   Target arah *close-to-close* **TIDAK DICAMPUR** dengan label Take-Profit before Stop-Loss (TP-before-SL). Evaluator post-horizon bursa mengukur harga penutupan resmi pada hari jatuh tempo ke-5, bukan sentuhan intraday pertama.

---

## 2. Partisi Waktu & Purging Embargo

Untuk mencegah kebocoran temporal akibat horizon 5 hari bursa, seluruh 10 saham universe dipartisi secara serentak menggunakan batas tanggal absolut kalender bursa:

```text
2021-01-04 ------------------> 2023-12-20 [TRAIN SET: 7.230 sampel]
      [EMBARGO 1: 5 Hari Bursa - DIBUANG DARI EVALUASI]
2024-01-09 ------------------> 2024-12-19 [VALIDATION SET: 2.269 sampel]
      [EMBARGO 2: 5 Hari Bursa - DIBUANG DARI EVALUASI]
2025-01-09 ------------------> 2026-10-16 [HOLDOUT TEST SET: 4.187 sampel]
```

### Aturan Partisi Waktu:
- **Zero Overlap:** Rentang waktu Train, Validation, dan Test tidak memiliki satu hari pun yang bertumpang tindih.
- **Embargo 5 Hari:** Observasi yang tanggal targetnya (`t+5`) menyeberang ke periode berikutnya dibuang (*purged*) agar label masa depan tidak membocorkan informasi ke set evaluasi.

---

## 3. Disiplin Preprocessing & Kalibrasi

1. **Fit Hanya pada Train:**
   - Nilai median kolom untuk imputasi `NaN` dihitung strictly dari `X_train`.
   - `StandardScaler` di-fit strictly pada `X_train`, kemudian diaplikasikan secara pasif (`transform`) ke `X_val` dan `X_test`.
2. **Seleksi Threshold & Tuning pada Validation:**
   - Ambang batas probabilitas (decision threshold) dan kalibrasi probabilitas hanya boleh dioptimalkan pada `Validation Set`.
   - `Holdout Test Set` dibekukan secara mutlak dan hanya dievaluasi satu kali pada akhir eksperimen tanpa penyesuaian hyperparameter ulang (*no post-hoc leakage*).

---

## 4. Metrik Evaluasi Komprehensif

Setiap pengujian model wajib melaporkan:
1. **Akurasi & Keseimbangan:** `Accuracy` dan `Balanced Accuracy`.
2. **Kualitas Klasifikasi Tiap Kelas:** `Precision`, `Recall`, `F1-Score` untuk kelas Positif (Naik) dan Negatif (Turun/Flat).
3. **Pemisahan Probabilitas:** `ROC-AUC` (Area under ROC curve) dan `PR-AUC` (Precision-Recall curve).
4. **Kalibrasi Probabilitas:** `Brier Score` (kedekatan probabilitas model terhadap realitas biner).
5. **Analisis Presisi Selektif:** Evaluasi tingkat presisi pada berbagai batas keyakinan model (50%, 60%, 70%, 80%, 90%) beserta ukuran sampel (*coverage*) dan peluang yang dilewati (*passed opportunities*).
6. **Simulasi Finansial Riil:** Kinerja portofolio setelah memperhitungkan biaya friksi bursa ritel BEI (Fee Beli 0.15%, Fee Jual 0.25%, dan Slippage 1 tick).
