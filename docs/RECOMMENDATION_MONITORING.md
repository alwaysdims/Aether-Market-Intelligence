# Screening dan Pemantauan Rekomendasi

## 1. Pisahkan asal dan kontrak

Asal keluaran: RULE_SCREENING, AGENT_ANALYSIS, ML_INFERENCE. Kandidat yang diterbitkan sebagai rekomendasi dengan tindakan/horizon tertentu harus memiliki recommendation_id, ticker, published_at, evidence_cutoff, reference_bar/price, strategy_version, config_hash, origin, horizon, aturan evaluasi, dan evidence.

Konsultasi edukatif, daftar broker, atau perbandingan fakta tidak otomatis menjadi prediksi arah/target. Jika tabel prediction_signals dipakai bersama, asal dan strategi wajib eksplisit. Versi model boleh null untuk keluaran non-ML. Inferensi historis tidak menjadi sinyal forward aktif setelah kedaluwarsa.

## 2. Kontrak broker accumulation screening V1

Ini asumsi riset, bukan strategi tervalidasi. Konfigurasi yang disepakati:

| Parameter | Nilai |
|---|---|
| Rata-rata volume | Minimal 1.000.000 lembar per sesi |
| Return periode | Antara −30% dan +5% |
| Jarak dari low periode | Maksimal 15% |
| Minimum jumlah sesi | 10; bukan bukti otomatis histori satu bulan lengkap |
| Shortlist broker | Maksimal 5, diurutkan berdasarkan aturan likuiditas yang diumumkan |
| Top-3 net buy | Ambang Rp1 miliar |
| Konsentrasi | Minimal 10% terhadap gross buy Top-20 teramati |

Laporan terdahulu mencampur operator > dan >= pada ambang Rp1 miliar. Periksa dan tetapkan boundary yang disetujui sebelum freeze berikutnya; tulis dalam config hash dan tes tepat pada batas. Jangan diam-diam mengganti perilaku versi lama. Perubahan threshold harus menghasilkan versi baru, tanpa memilih angka setelah melihat kandidat.

Harga baseline harus berasal dari sesi yang ditentukan kontrak, bukan bar pertama yang kebetulan tersedia. Kecukupan seluruh periode dihitung terpisah dari minimum 10 sesi. Basis penyesuaian harga harus sama. Gunakan cutoff yang sama untuk membandingkan kandidat. Missing bar bukan bukti suspensi.

Funnel mencatat jumlah ticker unik: universe diminta, tersinkron dari sumber operasional, histori layak, lolos harga, masuk shortlist, broker berhasil diperiksa, data broker cukup, dan kandidat akhir. Gunakan assertion antarhimpunan, bukan clamp untuk menyembunyikan kesalahan. Jika universe lokal 10 dan broker diperiksa 5, ada 5 belum diperiksa; pecah alasan gagal filter, batas anggaran, atau kegagalan retrieval.

Periode broker harus sesuai permintaan. Baseline harga boleh sebelum periode transaksi. Respons agregat periode tidak dijumlahkan ulang. Perbandingan sesi terakhir memerlukan data broker sesi tersebut; biaya tambahan dicatat.

Label yang diperbolehkan: NET_BUY_ON_LAST_SESSION, NET_SELL_ON_LAST_SESSION, NOT_OBSERVED_IN_TOP_N, PERSISTENCE_NOT_EVALUATED. Dua jendela searah belum membuktikan pembelian terus-menerus. Kandidat kosong adalah hasil sah. Harga dekat low bukan bukti sudah bottoming.

## 3. Strategi swing dan studi lama

SWING_FILTER_V1 merupakan strategi terpisah: aktivitas volume minimal 10 juta lembar, rentang 1,5–6,5%, perubahan close terhadap open minimal −0,5%. Formula dan denominator versi lama dipertahankan pada evidence. Strategi ini bukan fallback pertanyaan broker.

Studi sentuhan barrier matematis berbeda dari simulasi eksekusi. High/low menyentuh level tidak membuktikan order terisi. Hasil neto simulasi tetap ditahan sesuai batas implementasi yang dilaporkan. Perubahan metodologi harus versi terpisah.

## 4. Outcome dan rekap

Horizon ML existing adalah lima hari bursa. Monitoring target/invalidation 14 hari kalender hanya berlaku pada kontrak sinyal yang menetapkannya. Rekap 30 hari kalender adalah jendela laporan, bukan perubahan expiry atau horizon universal. BSJP, intraday, dan tesis investasi memerlukan kontrak sendiri sebelum diaktifkan.

Simpan event evaluasi append-only: aturan beku, tanggal publikasi, harga acuan, jatuh tempo, lintasan harga, sumber/revisi aktual, aksi korporasi, dan alasan missing. Pergerakan sebelum publikasi tidak dihitung sebagai outcome. Target dan stop tersentuh pada candle sama tanpa urutan transaksi -> AMBIGUOUS. Data hilang -> menunggu atau CANNOT_EVALUATE_MISSING_DATA, tanpa forward-fill.

Dashboard menampilkan pending, jatuh tempo, evaluated, missing, ambiguous, expired, dan membedakan harga acuan dari harga terkini. Telegram dapat mengirim digest tindak lanjut terukur. Akurasi dan coverage dipisahkan per origin, strategi, model, serta kohort. Abstain tidak dihitung sebagai prediksi benar. Simpan seluruh kandidat termasuk yang gagal; jangan hanya merekam pemenang.
