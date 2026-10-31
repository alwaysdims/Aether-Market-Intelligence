# PRD — Aether Market Intelligence

Versi 3.0.0 • 31 Oktober 2026 WIB • Status: kontrak produk target, implementasi bertahap.

## 1. Masalah dan tujuan

Aether membantu pengguna menelaah saham Indonesia dengan harga, teknikal, fundamental, aktivitas broker, berita, dan dokumen perusahaan yang dapat ditelusuri. Produk menyediakan konsultasi Telegram dua arah, dashboard per emiten, laporan terjadwal, dan pemantauan hasil kandidat. Masalah utama yang harus diselesaikan: data lintas waktu/sumber tercampur, jawaban tidak sesuai pertanyaan, job diam setelah acknowledgment, dan rekomendasi tidak ditindaklanjuti.

Keberhasilan berarti pertanyaan terjawab sesuai cakupan dengan angka yang dapat direproduksi, hasil tersimpan sebelum publikasi, delivery terukur, dan outcome dinilai berdasarkan aturan yang dibekukan. Tidak menjanjikan saham pasti naik atau akurasi 90%.

## 2. Pengguna dan cakupan

Pengguna awal adalah operator/analis lokal dan akun Telegram yang diizinkan. Bukan layanan publik multi-tenant. Otorisasi dan isolasi data tetap wajib. Semua kandidat registri dapat ditemukan di direktori, meskipun histori/analisis belum tersedia. Label registri sekunder tidak boleh berubah menjadi legal listing aktif terverifikasi.

Dalam cakupan: konsultasi saham/portofolio, watchlist, perbandingan, screening, berita/dampak, histori evidence, evaluasi rekomendasi, profil/branding, dan system health.
Di luar cakupan: eksekusi broker, jaminan keuntungan, promosi model otomatis, pengubahan kode/secret melalui chat, dan klaim realtime tanpa data valid. Preferensi 50/50 trading/investasi adalah preferensi analitis, bukan posisi atau alokasi yang otomatis dieksekusi.

## 3. Keputusan stack

Laravel + Blade + Vite + Tailwind v4 dipertahankan. Versi runtime harus diperiksa dari composer.lock dan CLI saat implementasi; laporan terakhir menyebut Laravel 13.36.0/PHP 8.3.7, bukan jaminan kompatibilitas yang sudah diaudit di paket ini. PostgreSQL 16, Python environment proyek, Hermes profil produksi, Gemini via 9router, dan Windows Task Scheduler tetap. Tidak menggunakan n8n. Tidak mengganti framework demi mempercepat tab sebelum profiling.

## 4. Navigasi produk

Sidebar utama hanya Dashboard, Market Monitor, News & Event, AI History. Profil dropdown memuat Profil Saya, System Health, Pengaturan. Profil dapat mengubah nama dan logo. Dashboard menonjolkan pasar, jadwal, kandidat, outcome; diagnostik database dan worker di System Health.

Detail emiten memiliki tujuh tab: Ringkasan, Pemantauan, Teknikal, Fundamental, Kesimpulan AI, News & Event, Riwayat AI. Sektor/subsektor/industri disajikan sesuai taksonomi/sumber yang dicatat; field tidak tersedia tidak ditebak. Contoh pemetaan UI finance/bank harus mengikuti klasifikasi sumber yang aktif.

## 5. Kebutuhan fungsional dan penerimaan

| ID | Kebutuhan | Penerimaan |
|---|---|---|
| FR-01 | Direktori seluruh kandidat registri | Pencarian ticker/nama; sektor, industri, watchlist, coverage, tanggal data; indeks terpisah; emiten tanpa data tetap terlihat |
| FR-02 | Harga kanonikal | IDX Edge valid, board/basis/timeframe sesuai; daily change memakai sesi sebelumnya yang tepat; gap -> N/A; indeks dalam poin |
| FR-03 | Detail emiten | Tujuh tab dapat ditautkan langsung; fallback/empty state jelas; current price tidak mengganti bar acuan analisis historis |
| FR-04 | Chart dan volume | EOD valid sekarang; sumber, tanggal, adjustment, satuan, rentang terlihat; tidak ada candle sintetis; market closed tidak mengosongkan chart |
| FR-05 | Teknikal/fundamental | Kalkulasi deterministik dengan input/version/coverage; annual vs interim, IDR vs USD, induk vs konsolidasian jelas; N/A bukan nol |
| FR-06 | Konsultasi Gemini melalui Hermes | Pertanyaan dipahami, tool sesuai; jawaban memakai evidence; follow-up dapat menjawab dari analisis sebelumnya tanpa fetch ulang |
| FR-07 | Job asinkron | Durable sebelum kerja panjang; ack cepat; semua pertanyaan konsultatif termasuk follow-up tidak menjalankan LLM di handler |
| FR-08 | Broker satu/multi emiten | Periode dipertahankan; net/gross berbeda; subset/Top-N diungkap; tanpa klaim identitas bandar |
| FR-09 | Berita dan dampak | Berita global vs ticker; URL/waktu/klaim; fakta terpisah interpretasi; DB kosong bukan bukti internet kosong |
| FR-10 | Jadwal | Kalender dan sesi versioned; owner tunggal; laporan berdasarkan kemampuan data; trigger/late/delivery terukur |
| FR-11 | Publikasi | Analisis/evidence disimpan sebelum outbox; receipt setiap bagian; timeout tidak dianggap sukses atau pasti gagal |
| FR-12 | Pemantauan | Setiap kandidat actionable punya kontrak evaluasi sebelum publikasi; follow-up sampai horizon dan rekap 30 hari |
| FR-13 | AI History | Pencarian emiten/job/channel/status/periode; asal rule/agent/ML; detail evidence; output mentah di accordion bukan konten utama |
| FR-14 | Profil/setting/health | Profil berotorisasi; upload raster tervalidasi; sumber efektif/blocked reasons; probe bertimestamp; tidak menampilkan secret |
| FR-15 | Permintaan analisis web | Klik eksplisit membuat job; progress, hasil, retry terkontrol; navigasi halaman tidak memanggil LLM |

## 6. Jadwal target, bukan janji kesiapan intraday

Semua waktu Asia/Jakarta. Kalender/sesi mengikuti sumber kalender terpelihara, termasuk Jumat, libur, dan perubahan sesi. Jam ini adalah jadwal laporan, bukan definisi finalitas provider.

| Jam | Produk | Bukti minimum | Kebijakan saat belum tersedia |
|---|---|---|---|
| 08:30 | Briefing sebelum pasar | EOD sesi sebelumnya, berita/dokumen yang tersedia sebelum cutoff, fundamental dan broker tersimpan | Laporan parsial berlabel tanggal/cakupan; tidak menyebut kondisi live |
| 09:15 | Aktivitas volume awal | Harga dan volume RG intraday valid, timestamp/delay, baseline pola volume pada jam sebanding | BLOCKED_DATA_CAPABILITY; jangan gantikan dengan rekomendasi EOD |
| 12:15 | Ulasan sesi pertama | Data RG sampai cutoff sesi pertama sesuai kalender hari tersebut, kelengkapan transaksi | Blokir analisis sesi jika data kurang; broker sesi opsional dan N/A jika tak tersedia |
| 15:15 | Kandidat BSJP | RG intraday, cutoff keputusan, aturan entry/exit dan horizon BSJP terpisah | Dinonaktifkan sampai kontrak data/strategi memenuhi syarat |
| 16:05 | Probe ingestion EOD | Cek apakah bar sesi telah tersedia/valid | WAITING_FOR_FINAL_DATA; retry terbatas; bukan jaminan finalitas |
| 20:00 | Rekap pascapasar | EOD sesuai sesi, broker OBSERVED_SNAPSHOT, berita, indikator dan fundamental bertanggal | PARTIAL jika belum lengkap, tanpa klaim broker settlement final |

Jadwal usulan tidak mengaktifkan inference EOD atau mengubah flag produksi secara otomatis. Jika laptop mati/sleep, simpan missed trigger dan terapkan toleransi/catch-up spesifik jenis laporan. Tidak mengirim rekomendasi kedaluwarsa sebagai tepat waktu.

## 7. Kontrak jawaban

Mulai dengan jawaban pertanyaan, bukan dump metadata. Ringkas alasan, bukti angka dan tanggal, risiko, batas cakupan, serta yang belum diketahui. Analisis historis mempertahankan harga/evidence saat diterbitkan. Tidak mengarang fetching, penyebab harga, confidence, dokumen atau berita. Probabilitas hanya dari inference ML sah; keyakinan bahasa agent bukan probabilitas kenaikan.

## 8. Target nonfungsional (belum SLA terukur)

- Handler durable acknowledgment target p95 <=2 detik pada host sehat, tanpa menunggu provider/LLM; pisahkan latency handler vs transit Telegram.
- Tab pertama target p95 <=800 ms pada dataset pilot dan host uji tercatat; perpindahan cache <=50 ms. TTFB, query, payload, browser render dilaporkan terpisah.
- Polling internal awal 30 detik, berhenti saat dokumen tersembunyi; tidak menghabiskan request provider per viewer.
- Deadline konsultasi menggunakan satu absolute deadline dan batas tool/cost; budget reasoning saat ini dilaporkan 60 detik, deadline job sebelumnya 15 menit. Nilai efektif dicatat, bukan menaikkan timeout sebagai solusi universal.
- Semua input numerik finite, OHLC valid, volume tidak negatif, source/timeframe/board/basis cocok; data cacat tidak masuk evidence operasional.
- UI bisa digunakan keyboard dan viewport 390/768/1280/1440; tanpa overflow halaman; tabel/chart boleh scroll lokal berlabel.
- User isolation, CSRF untuk mutasi web, allowlist Telegram, tools allowlist, secret redaction, backup/restore terdokumentasi.

## 9. Penerimaan bertahap

Internal test -> canary provider -> worker otomatis -> pesan Telegram asli dengan receipt -> pemeriksaan isi -> pemantauan horizon. Kelulusan satu ticker tidak berarti seluruh universe siap. INTERNAL_TEST, VERIFIED_AUTOMATIC, VERIFIED_LIVE_DELIVERY, CONTENT_VERIFIED, dan STRATEGY_VALIDATED adalah bukti berbeda. Tes aplikasi tidak membuktikan keuntungan/akurasi prediksi.

## 10. Prioritas implementasi

P0: routing/follow-up asinkron dan delivery; kontrak source/waktu/evidence. P1: desain dashboard dan detail emiten, screening pilot konsisten, jadwal EOD. P2: monitoring/outcome 30 hari dan perluasan ingestion terukur. P3: intraday setelah verifikasi RG/kelengkapan/delay/kuota. P4: penelitian ML baru jika ada hipotesis/data baru dan protokol terpisah.
