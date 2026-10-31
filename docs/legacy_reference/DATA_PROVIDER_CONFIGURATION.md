# Konfigurasi Provider Data IDX Edge

## Status

- Konektor REST backend: `VERIFIED` melalui tes HTTP mock.
- Koneksi MCP dan discovery 17 tool: `VERIFIED` tanpa pembuktian autentikasi data.
- Request data IDX Edge terautentikasi: `PENDING` sampai API key yang pernah terekspos di percakapan dirotasi.
- Fitur IDX Edge: mati secara default (`IDX_EDGE_ENABLED=false`).
- Yahoo Finance: tetap fallback berlabel jelas. Tidak dihapus.

## Konfigurasi Laravel

Simpan secret hanya di `dashboard/.env`, bukan source code atau browser:

```ini
IDX_EDGE_ENABLED=false
IDX_EDGE_BASE_URL="https://stock.arjum.com"
IDX_EDGE_API_KEY=""
IDX_EDGE_TIMEOUT=10
IDX_EDGE_DAILY_BUDGET=800
IDX_EDGE_DAILY_RESERVE=200
IDX_EDGE_REQUESTS_PER_MINUTE=10
IDX_EDGE_CACHE_TTL_SECONDS=30
```

Langkah aman:

1. Rotasi key yang pernah muncul di percakapan.
2. Masukkan key pengganti ke `dashboard/.env` melalui editor lokal atau secret prompt. Jangan tempel ke chat.
3. Biarkan `IDX_EDGE_ENABLED=false` selama canary belum selesai.
4. Bersihkan cache konfigurasi:

```bash
cd E:\Aether-Market-Intelligence\dashboard
php artisan config:clear
```

5. Verifikasi tanpa menampilkan secret:

```bash
php artisan config:show services.idx_edge
```

Jangan salin output bila runtime menampilkan key. Pemeriksaan aman hanya boleh menyatakan `set` atau `unset`.

## Konfigurasi MCP Hermes

Runtime terpasang: Hermes Agent `v0.21.5+3394.g54388a2`.

Server sudah tercatat:

- Nama: `idx-edge`
- URL: `https://stock.arjum.com/mcp`
- Transport: Streamable HTTP
- Tool ditemukan: 17

Pemeriksaan:

```bash
hermes mcp list
hermes mcp test idx-edge
```

Hasil audit 30 Oktober 2026: koneksi dan discovery tool berhasil, tetapi `hermes mcp test` melaporkan `Auth: none`. Karena itu status autentikasi MCP masih `PENDING`, bukan lulus. Jangan menganggap discovery tool sebagai bukti data terotorisasi.

Setelah key dirotasi, ulangi konfigurasi memakai prompt rahasia Hermes. Jangan menulis header `X-API-Key` langsung dalam command shell karena command dapat masuk history atau process list.

## Batas Kuota Lokal

Dokumentasi publik menyatakan:

- Kuota dasar: 1.000 request per hari.
- Reset: 00:00 WIB.
- REST dan MCP berbagi kuota.
- Header yang disebut: `X-Quota-Cost` dan `X-RateLimit-Remaining`.
- Batch harga: biaya per kode, bukan per HTTP request.

Konfigurasi Aether memakai budget 800 dan cadangan 200. Ini batas konservatif lokal, bukan klaim paket akun. Header teramati `X-RateLimit-Limit: 31000` dicatat dengan periode `UNKNOWN` (belum ada bukti apakah ini batas bulanan, billing cycle, atau pool akun) dan tidak dijadikan dasar menaikkan budget produksi. RPM provider dan concurrency tidak terdokumentasi; Aether membatasi 10 request/menit sampai provider memberi batas tertulis.

Cache backend dibagi berdasarkan request terkanonisasi. Browser tidak menerima API key dan tidak memanggil IDX Edge langsung.

## Proteksi Secret

- `dashboard/.env` sudah diabaikan Git.
- `.env.example` hanya memuat placeholder kosong.
- `IdxEdgeClient` tidak menaruh key dalam URL.
- Error meredaksi key dan pola `sk_*`.
- Header autentikasi tidak dicetak ke log.
- Automated tests memakai key palsu dan `Http::fake()`.

## Rollback Konfigurasi

```ini
IDX_EDGE_ENABLED=false
```

Lalu:

```bash
cd E:\Aether-Market-Intelligence\dashboard
php artisan config:clear
```

Rollback ini tidak menghapus data provider lama dan tidak mengubah model/threshold produksi.