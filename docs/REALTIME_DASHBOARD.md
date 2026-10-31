# Dashboard Data Pasar Bertahap

## Tahap Aktif

Dashboard memakai polling API internal Aether:

```text
GET /api/market/{ticker}/snapshot
```

Ini bukan provider streaming. MCP Streamable HTTP juga bukan bukti streaming harga.

Alur:

1. Browser memuat snapshot awal dari server-rendered `MarketObservation` kanonikal.
2. Satu poll per halaman emiten aktif berjalan setiap 30 detik.
3. Poll berhenti saat tab browser tersembunyi.
4. Poll hanya membaca database Aether; tidak memanggil IDX Edge per tab.
5. Saat `data_hash`, `available_at`, atau market timestamp berubah, cache tujuh tab dibersihkan.
6. Tab ringkasan aktif dimuat ulang; tab lain tetap lazy-loaded.
7. `pagehide` menghentikan interval.

## Kontrak Snapshot

Respons memuat:

- `ticker`
- `market_timestamp`
- `received_at`
- `available_at`
- `bar_date`
- OHLC
- `volume`
- `volume_unit`
- `source`
- `connection_status`
- `delivery_mode=INTERNAL_POLLING`
- `is_final`
- `validation_status`
- `data_hash`

Untuk IDX Edge, unit volume histori masih `NOT_DOCUMENTED`. UI tidak boleh mengubahnya menjadi lembar atau lot tanpa bukti endpoint spesifik.

## Pemilihan Provider

Urutan kanonikal:

1. Row valid IDX Edge.
2. Row valid Yahoo Finance fallback.
3. Provider lain.
4. Row `CONFLICTED` selalu paling akhir.

Histori chart mendeduplikasi tanggal dan mempertahankan row provider berprioritas tertinggi. Harga lintas provider tidak dirata-rata atau digabung.

## Status UI

- `FINAL • INTERNAL_POLLING`: bar tersimpan berstatus final.
- `PROVISIONAL • INTERNAL_POLLING`: snapshot tersimpan belum final.
- `DEGRADED • DATA TERSIMPAN`: API internal gagal; chart terakhir tetap tampil.
- `DATA_NOT_AVAILABLE`: belum ada observation.

Saat pasar tutup, chart terakhir tidak dikosongkan. Poll browser tidak menjadi pemilik monitoring rekomendasi; worker backend tetap independen.

## Timeframe

Dokumentasi publik IDX Edge membuktikan histori `daily`, `weekly`, dan `monthly`. Intraday candle 1 menit tidak terdokumentasi. Dashboard saat ini hanya menampilkan `1d`; jangan tampilkan timeframe intraday sampai provider membuktikan dukungan dan skema finality.

Quote dengan volume kumulatif harian tidak boleh direkonstruksi sebagai histori candle satu menit lengkap. Jika polling quote kelak disimpan, klasifikasinya harus `sampled/partial` dan menangani reset sesi.

## SSE atau WebSocket

Belum dipakai. Polling internal dipilih sebagai tahap awal karena:

- stack lokal memakai PHP development server;
- koneksi panjang berisiko memblokir worker server pengembangan;
- provider belum membuktikan stream harga;
- polling database tidak menambah kuota provider per tab.

SSE hanya layak setelah server produksi multi-worker dan subscriber count terukur. WebSocket tidak ditambahkan tanpa kebutuhan terukur.

## Verifikasi

Automated:

```bash
cd E:\Aether-Market-Intelligence\dashboard
php artisan test --compact tests/Feature/RealtimeDashboardApiTest.php
php artisan test --compact tests/Feature/DashboardTest.php
npm run build
```

Browser harus membuktikan:

- halaman emiten memuat tanpa error console;
- tujuh tab tetap lazy-loaded;
- hanya satu request snapshot periodik per halaman;
- perubahan evidence membatalkan cache tanpa duplikasi candle.