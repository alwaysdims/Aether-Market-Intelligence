# RENCANA RISET & EKSPERIMEN MACHINE LEARNING AETHER
**Dokumen Perencanaan Eksperimen Terpisah (Tanpa Mengubah Model Produksi)**
**Target Sasaran:** Evaluasi Arah & Ketepatan Prediksi Multi-Day (Horizon 5 Hari Bursa)
**Tanggal:** 28 Oktober 2026

---

## 1. Sasaran & Filosofi Penelitian

- **Tujuan Utama:** Mengembangkan arsitektur model klasifikasi arah pergerakan harga saham BEI yang andal, tahan terhadap rezim volatilitas pasar, dan memiliki kalibrasi probabilitas yang jujur.
- **Sasaran Metrik:** Angka akurasi 90% ditetapkan sebagai **arah sasaran riset ilmiah jangka panjang**, BUKAN target numerik yang dipaksakan dengan cara:
  * Menurunkan coverage pasar secara diam-diam (cherry-picking sampel).
  * Melakukan pemilihan saham setelah hasil pergerakan harga diketahui (*lookahead selection*).
  * Mempersempit label evaluasi hanya pada saham-saham berkapitalisasi tertentu yang sedang rally.
- **Model Produksi Tetap Terisolasi:** Eksperimen ini dijalankan pada lingkungan sandbox/shadow terpisah. Model produksi yang saat ini berjalan tidak disentuh atau diganti sebelum lulus uji evaluasi forward shadow.

---

## 2. Metodologi Dataset & Pembagian Waktu (Point-in-Time)

### A. Snapshot Dataset & Versi Fitur
- Dataset dibekukan dalam format snapshot berkas terkompresi (Parquet / SQLite immutable) per periode.
- Skema fitur diberi versi eksplisit (misal: `FEATURE_SCHEMA_V2.0`) dengan hash unik integritas data.

### B. Pembagian Waktu Bersih (Purged & Embargoed Time-Series Split)
- **Pembagian Lintas Emiten Serentak:** Tanggal cutoff pembagian data training, validasi, dan test harus identik untuk semua 501 saham di BEI guna mencegah kebocoran informasi makro lintas emiten.
- **Purging & Gap Window:** Karena target prediksi menggunakan horizon multi-day (5 hari bursa), terdapat jeda overlap data. Oleh karena itu, diterapkan *purging window* minimal 5 hari bursa antara akhir set data training dan awal set data validasi/test untuk menghilangkan bias korelasi serial label.
- **Preprocessing Hanya pada Training:** Standarisasi, imputasi, normalisasi, dan seleksi fitur hanya dihitung (*fitted*) menggunakan statistik data training, lalu ditransformasikan ke set validasi dan test tanpa re-fitting.

### C. Ketersediaan Data Fundamental & Berita (Point-in-Time Availability)
- Data fundamental (laporan keuangan kuartalan) dan berita hanya dapat dimasukkan sebagai fitur jika timestamp `available_at` (waktu publikasi resmi ke publik) terbukti mendahului waktu penutupan pasar saat keputusan prediksi dibuat (`decision_timestamp`).

---

## 3. Matriks Evaluasi & Kalibrasi Komprehensif

Evaluasi model eksperimen wajib melaporkan metrik multi-dimensi secara terbuka:

1. **Akurasi & Keseimbangan:**
   - Accuracy murni.
   - Balanced Accuracy (untuk menangani ketidakseimbangan kelas Bullish / Bearish / Sideways).
2. **Kinerja Klasifikasi:**
   - Precision & Recall per kelas arah.
   - Area Under ROC Curve (ROC-AUC) dan PR-AUC.
3. **Keandalan Probabilitas (Calibration):**
   - Brier Score dan Expected Calibration Error (ECE).
   - Kalibrasi probabilitas memastikan bahwa ketika model menyatakan keyakinan 80%, tingkat keberhasilan empiris mendekati 80%.
4. **Coverage & Ketidakpastian:**
   - Persentase cakupan saham yang memiliki sinyal aktif vs abstain.
   - Interval estimasi ketidakpastian prediktif.
5. **Evaluasi Tersegregasi:**
   - Evaluasi per periode waktu (misal: masa konsolidasi vs masa crash IHSG).
   - Evaluasi per sektor industri (perbankan, energi, infrastruktur, konsumer).
   - Evaluasi per rezim likuiditas (LQ45 vs lapis dua/tiga).

---

## 4. Jalur Promosi: Forward Shadow Evaluation

Sebelum model baru dapat dipertimbangkan menggantikan model produksi:
1. **Backtest Out-of-Sample Selesai:** Lulus evaluasi pada dataset test historis yang belum pernah dilihat model.
2. **Forward Shadow Execution:** Model dijalankan secara otomatis setiap hari setelah penutupan bursa dalam mode *shadow* (prediksi dicatat dan dikunci hash-nya sebelum sesi bursa berikutnya dibuka, tanpa mengirim sinyal ke pengguna).
3. **Verifikasi Horizon Nyata:** Hasil prediksi shadow dievaluasi setelah 5 hari bursa menggunakan data realisasi penutupan resmi.
4. **Kriteria Kelulusan Promosi:** Model kandidat harus membuktikan performa yang lebih unggul dibandingkan baseline sederhana (Buy-and-Hold IHSG, Momentum 5-hari, dan Random Forest v1.0) selama minimal 20 sesi perdagangan forward berturut-turut tanpa anomali kalibrasi.
