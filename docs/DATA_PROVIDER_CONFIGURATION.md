# Provider Operasional — IDX Edge

Versi 3.0. Kebijakan target, bukan hasil probe baru.

## 1. Endpoint dan permission

| Endpoint | Tujuan | Kebijakan |
|---|---|---|
| /api/history/{code} | OHLCV history | Pilot valid sesuai source/board/adjustment; perluas bertahap |
| /api/broker-summary/{code} | Broker periode | Validasi tanggal, Top-N, gross/net dan unit; finalitas tidak diattest provider |
| /api/broker-accumulation/{code} | Aktivitas broker historis | Verifikasi definisi agregasi dan bucket waktu; jangan mengasumsikan seri lengkap |
| /api/financial-statements/{code} | Fundamental | Mapping bank/non-bank/currency, provenance per field |
| /api/price/{code}, /api/price/list | Quote | QUARANTINED untuk RG sampai pemisahan board terbukti |
| /api/done-details | Transaksi | Penelitian RG: pagination, urutan, ID, koreksi, delay dan biaya wajib diuji |
| /api/screener/latest, /api/analysis/{code}, /api/movers | Discovery dan analisis provider | Metodologi, tanggal, dan board diketahui sebelum digunakan; bukan ground truth ML |
| /api/search, /api/market-cap | Identitas/discovery | Simbol feed bukan bukti legal listing aktif; perhatikan pagination |
| /api/insiders/{code}, /api/seasonal/{code} | Konteks tambahan | Tanggal publikasi dan metodologi harus diketahui |

Daftar berasal dari dokumentasi provider yang disampaikan pengguna; schema aktual diverifikasi saat integrasi. MCP Streamable HTTP tidak membuktikan streaming harga. Atribusi tetap IDX Edge, bukan API resmi bursa.

## 2. Kuota

HTTP request, MCP call, quota unit, dan token LLM dihitung terpisah. Menurut dokumentasi yang diberikan: harga batch lima kode = lima unit; financial report_type all = tiga unit; parameter tidak valid 422 dapat berbiaya. Catat biaya dari header atau metadata aktual. Request HTTP berbiaya nol tetap dihitung satu HTTP request.

Batas 31.000 pernah teramati, tetapi periode/reset belum terkonfirmasi. Jangan menyimpulkan kuota harian atau bulanan hanya dari angka itu. Angka sisa kuota bukan konstanta dokumentasi.

Budget lokal 800 unit ditambah reserve 200 per hari dan 10 RPM merupakan pembatas pilot lama, bukan entitlement paket. Nilai efektif dan prioritas chat/laporan/backfill ditetapkan operator. Jangan menaikkan otomatis berdasarkan dugaan paket 30 ribu per hari. Parameter fields menghemat payload/token, tidak otomatis menghemat kuota.

## 3. Cache dan coalescing

Key mencakup endpoint, parameter canonical, periode, board, adjustment, dan namespace akun tanpa secret. Gunakan backend cache bersama lintas proses; simpan created_at, read_at, expires_at, source cutoff dan evidence asal.

TTL per endpoint dikonfigurasi eksplisit. Entri kedaluwarsa tidak disebut fresh. Arsip persisten dapat menjawab pertanyaan historis dengan label yang tepat. Evidence baru tidak mengatur ulang usia data asal.

Atomic lock memeriksa cache kembali setelah lock diperoleh. Masa lease mencakup batas HTTP dan retry yang benar-benar mungkin berjalan. Lock wait tetap tunduk pada deadline caller. Pelepasan memeriksa ownership. Uji dua proses nyata, bukan dua pemanggilan berurutan.

Browser hanya membaca API internal. Banyak viewer berbagi hasil kolektor, tidak memicu provider per tab.

## 4. Secret dan konfigurasi

Secret hanya di dashboard/.env atau secret profile lokal; .env.example kosong dan .env diabaikan Git. Jangan mencetak konfigurasi lengkap yang memuat key. Pemeriksaan cukup key_set=true/false dan izin efektif endpoint. Jangan menaruh secret di URL, argument command, konteks model, atau error.

Global flag, permission per endpoint, kualitas, dan health dipisahkan. Menonaktifkan IDX Edge tidak mengaktifkan Yahoo otomatis. Rollback menampilkan data terakhir bertanggal serta alasan degraded. Paket ini tidak mengandung kredensial.

## 5. Berita dan dokumen

Berita/IR memakai sumber lain jika API tidak menyediakan. Simpan URL, publisher, published_at bila diketahui, fetched_at, hash, dan hasil ekstraksi. Retrieval HTTP berhasil berbeda dari ekstraksi angka berhasil. HTTP 403 berarti ACCESS_BLOCKED, bukan otomatis bukti perlu lisensi berbayar.

Hak penyimpanan, pelatihan, dan penayangan mengikuti ketentuan provider yang berlaku; keberhasilan autentikasi tidak membuktikan seluruh hak tersebut. Informasi syarat yang belum diketahui dicatat, tanpa mengarang kewajiban baru atau mengklaim izin telah diperoleh.
