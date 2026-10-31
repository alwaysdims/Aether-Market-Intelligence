# Telegram Dua Arah dan Hermes Reasoning Worker

## 1. Routing

Satu receiver memakai profil gateway default. Profil aether-prod bukan receiver. Handler memvalidasi identitas/allowlist, dedup, menyimpan conversation, job, dan referensi konteks, kemudian mengirim ACK. Konsultasi, broker, screening, perbandingan, dan follow-up semuanya worker asinkron. Tidak ada retrieval atau LLM di handler.

Fast path hanya utilitas pasti seperti /help dan /status yang membaca data ringan dengan batas durasi. Jangan menganggap setiap kata setelah saham/emiten adalah ticker. Screening tanpa ticker sah; multi-ticker sah; ticker eksplisit yang tidak ditemukan dijelaskan. Klarifikasi hanya jika ambiguitas memengaruhi eksekusi.

## 2. Penalaran dan tools

Hermes memilih tools Aether sesuai profil: data analisis, broker, fundamental, screening, compare, berita/dokumen, portofolio, dan status. Daftar tools aktual harus dibuktikan runtime, bukan sekadar keberadaan method PHP.

Tools melewati konektor Aether dengan budget, cache, validasi, dan evidence. Jangan menggabungkan MCP provider langsung dan REST sebagai dua jalur pengambilan tanpa koordinasi.

screen_stocks(strategy=BROKER_ACCUMULATION) boleh menjadi alias jika dispatch eksplisit ke BrokerAccumulationScreeningService dan regresi membuktikan tidak masuk SWING_FILTER_V1. Schema tool harus mempertahankan ticker, jenis permintaan, periode dan konteks. Batasi fields, pages, serta payload; jangan memasukkan seluruh raw history besar ke model.

Satu penalaran per job. Jika Hermes aktif, jalur lama tidak melakukan completion untuk sintesis yang sama. Cegah rekursi gateway -> artisan -> worker Hermes -> plugin Telegram -> job baru. Isolasi session per user/conversation, dengan lock dan absolute deadline. Follow-up tentang cakupan/sumber memakai analisis sebelumnya; tidak otomatis mengulang screening.

## 3. Delivery

Simpan hasil dan evidence sebelum outbox. ACK dan jawaban akhir memiliki receipt berbeda. Agent tidak mengirim hasil analitis langsung di luar mekanisme publikasi Laravel.

Pemecahan pesan mempertimbangkan batas Telegram, encoding, entities/markup, ruang penomoran, dan batas karakter yang benar. Maksimal 3.900 merupakan default konservatif, bukan pengganti pengujian Unicode. Simpan receipt numerik setiap bagian. Hanya semua bagian SENT yang berarti pengiriman lengkap.

Timeout bisa menghasilkan DELIVERY_UNKNOWN. Rekonsiliasi sebelum retry agar tidak menggandakan pesan. Tidak ada klaim exactly-once mutlak ketika transport tidak memberi idempotency end-to-end. Payload SENT tidak ditulis ulang; koreksi berupa erratum baru.

## 4. Recovery

Update sama didedup. Pesan baru dengan teks sama adalah pesan baru; dapat memakai cache atau ditautkan ke job berjalan secara eksplisit. Recovery menyimpan update/conversation asli dan attempt terpisah, bukan synthetic inbound.

INTERNAL_TEST_WITH_LIVE_DELIVERY berbeda dari pemulihan pesan pengguna asli. Worker manual berbeda dari trigger OS otomatis. Jika lebih dari tiga menit tidak ada hasil pada pilot, periksa job asli sebelum meminta resend; tiga menit adalah ambang diagnosis, bukan SLA.

Setiap kegagalan analisis dicatat dan pemberitahuan diantrekan. Jika transport sedang gagal, pemberitahuan juga bisa tertunda; tampilkan statusnya di dashboard dan jangan mengklaim pengguna sudah diberi tahu tanpa receipt.

## 5. Penerimaan

Rantai bukti: inbound asli -> job -> ACK receipt -> trigger OS -> trace agent -> tool request/cache/evidence -> analisis -> outbox parts -> final receipts. Periksa isi sesuai pertanyaan; SENT saja belum cukup.

Uji worker crash, duplicate update, 429/timeout provider, send outcome tidak pasti, multipart partial failure, follow-up, dan false-positive ticker seperti PILOT. Pengujian internal memakai fixture terisolasi. Tidak menyatakan akurasi ML meningkat karena tes transport lulus.
