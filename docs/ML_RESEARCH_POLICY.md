# Kebijakan ML dan Penelitian

## 1. Posisi produksi

Model terakhir dilaporkan RandomForest_v1.0 dengan universe sepuluh saham awal dan horizon lima hari bursa. Sepuluh saham pilot IDX Edge tidak otomatis sama dengan universe validasi ML. Model pernah dilatih bukan bukti keunggulan prediksi. Eksperimen EXP-01, EXP-02R, dan EXP-03 dilaporkan ditolak untuk promosi. EXP-04 adalah strategi aturan teknikal, bukan algoritma atau protokol walk-forward.

Inferensi hanya boleh dipakai jika instrumen, skema fitur, adjustment, sumber, bar, horizon, dan kontrak artefaknya cocok. Alias model LLM juga bukan bukti identitas atau kemampuan model ML.

| Kondisi | Tampilan |
|---|---|
| Tidak ada inferensi aktif untuk bar | NO_ACTIVE_INFERENCE_FOR_BAR; probabilitas N/A |
| Instrumen di luar validasi | MODEL_NOT_VALIDATED_FOR_INSTRUMENT; probabilitas N/A |
| Inferensi sah tetapi di bawah threshold | ABSTAIN, dengan referensi inference ID |
| Inferensi historis/replay | Riwayat, bukan kartu sinyal aktif |

Formula confidence legacy binary = 2 × max(probability) − 1, dengan tau 0,70, adalah kontrak seleksi model tertentu; bukan akurasi dan bukan aturan universal multiclass. Peralihan sumber Yahoo ke IDX Edge memerlukan pemeriksaan kompatibilitas fitur, corporate action, dan distribusi data sebelum inferensi. Jangan mengubah model diam-diam.

## 2. Dataset rekomendasi dan sasaran 90%

90% merupakan aspirasi, bukan angka wajib. Rekap rekomendasi 30 hari tidak sendirinya cukup untuk melatih model yang andal. Label overlap, dependensi waktu, serta korelasi lintas ticker mengurangi jumlah sampel independen.

Simpan universe eligible, kandidat, reject, dan abstain beserta informasi yang tersedia saat keputusan. Jangan melatih hanya dari saham pilihan agent atau hasil yang sukses. Opini Gemini dan analisis provider bukan ground truth pergerakan harga.

## 3. Protokol eksperimen

- Snapshot raw immutable, manifest, hash, versi fitur dan konfigurasi.
- Definisi label, horizon, kalender, dan aturan missing ditetapkan sebelum fit.
- Split waktu yang sama lintas ticker; purge berdasarkan akhir label aktual.
- Preprocessing dan seleksi fitur hanya fit di train.
- Validation, calibration, dan test dipisah. Periode yang sudah sering dilihat menjadi known benchmark, bukan untouched holdout.
- Laporan accuracy, balanced accuracy, precision, recall, F1/AUC sesuai kelas, calibration, Brier/log loss, coverage, jumlah sampel, dan interval ketidakpastian.
- Paired block bootstrap mengikuti blok tanggal dan mempertahankan dependensi lintas ticker; evaluasi per periode, sektor, dan rezim.
- Threshold abstain dipilih pada validation; coverage tidak disembunyikan.
- Forward shadow sebelum promosi manual. Keuntungan simulasi dengan biaya dibahas terpisah dari akurasi arah.

Stopping rule sebelumnya dipertahankan: jangan mengulang algoritma/threshold dengan fitur teknikal yang sama tanpa hipotesis baru. Fitur broker, berita, atau foreign flow memerlukan provenance dan available_at yang mendukung point-in-time. Tidak ada training dari chat atau promosi otomatis setelah satu periode baik.
