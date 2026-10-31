# LAPORAN PENERIMAAN INTEGRASI UI (UI INTEGRATION ACCEPTANCE)
## AETHER MARKET INTELLIGENCE DASHBOARD
**Workspace**: `E:\Aether-Market-Intelligence`  
**Aplikasi**: `E:\Aether-Market-Intelligence\dashboard`  
**Waktu Pengujian**: 27 Oktober 2026, 00:45:00 WIB  
**Status Desain**: **Berbasis DESIGN.MD; kesesuaian dengan aset Stitch belum diverifikasi.**  

---

## 1. Ringkasan Eksekutif

Pengujian penerimaan integrasi antarmuka pengguna (UI) Aether Market Intelligence telah diselesaikan secara menyeluruh melalui kombinasi otomasi peramban per-komponen (browser automation), pengujian unit/fitur terisolasi (`phpunit`), dan audit runtime skema database PostgreSQL nyata (`project-bot:5432`). Seluruh kendala crash HTTP 500, ambiguitas skema kolom basis data, sintaks kompilasi Blade, serta penanganan kasus parsial dan unknown emiten telah diperbaiki sepenuhnya.

### Metrik Pengujian Kunci:
- **PHPUnit Test Suite**: 172 tests, 729 assertions, **0 failures, 0 errors** (100% Passed).
- **Rute Web Utama (HTTP Status)**:
  * `GET /` -> HTTP 200 OK
  * `GET /market` -> HTTP 200 OK (server-side pagination, search, sort, filter ekuitas vs indeks acuan)
  * `GET /market/{ticker}` -> HTTP 200 OK (7 Tab: Ringkasan, Pemantauan, Teknikal, Fundamental, AI Gemini, News & Event, Riwayat AI)
  * `GET /news` -> HTTP 200 OK
  * `GET /history` -> HTTP 200 OK
  * `GET /profile` -> HTTP 200 OK
  * `GET /health` -> HTTP 200 OK
  * `GET /settings` -> HTTP 200 OK
- **Console Errors JavaScript**: **0 errors** pada seluruh halaman yang diuji.
- **Visual Evidence**: Tersimpan pada `C:\Users\ASUS\AppData\Local\hermes\cache\screenshots\browser_screenshot_1229e87c75b247898fa4952c8dce0fb1.png`.

---

## 2. Temuan Masalah & Akar Penyebab (Root Cause) & Solusi

### A. Inkonsistensi Skema Kolom `is_index` vs `instrument_type`
- **Gejala**: HTTP 500 pada route `/` dan `/market` dengan error `column "is_index" does not exist`.
- **Akar Penyebab**: Query Eloquent mengasumsikan kolom boolean `is_index`, sedangkan skema riil tabel `idx_company_registries` menggunakan kolom string `instrument_type` bernilai `'EQUITY'` dan `'INDEX'`.
- **Solusi**: Memperbarui query pada `DashboardController.php` dengan `where('instrument_type', 'EQUITY')` dan `where('instrument_type', 'INDEX')`.

### B. Crash Kompilasi Blade pada Sintaks `@php(...)`
- **Gejala**: ParseError `syntax error, unexpected token "else", expecting end of file` pada `market.blade.php` dan `ticker.blade.php`.
- **Akar Penyebab**: Directive inline `@php(...)` pada Laravel 11/12 tidak menutup blok PHP secara otomatis, merusak hirarki control directive `@if` dan `@forelse`.
- **Solusi**: Mengganti seluruh directive inline menjadi blok `@php ... @endphp` eksplisit.

### C. Constraint Violation pada Permintaan Riset (`research_requests`)
- **Gejala**: Error HTTP 500 saat POST `/api/research` (`column "request_payload" does not exist` dan `NOT NULL constraint failed: as_of_time`).
- **Akar Penyebab**: Kolom metadata pada tabel adalah `research_metadata`, bukan `request_payload`, dan kolom `as_of_time` bersifat non-nullable.
- **Solusi**: Memperbaiki mapping field pada `DashboardController::submitResearch()` dengan `research_metadata` dan menyertakan `'as_of_time' => now()`.

### D. Penanganan Emiten Tidak Dikenal (`UNKNOWN`)
- **Gejala**: Risiko ticker fiktif memicu pengumpulan data liar atau dianggap emiten bursa valid.
- **Solusi**: 
  * Validasi ketat registri bursa pada `submitResearch()`: Ticker yang tidak terdaftar pada `idx_company_registries` langsung ditolak dengan status HTTP 422 `REJECTED_UNKNOWN_TICKER`.
  * Halaman `/market/{ticker}` untuk ticker tidak dikenal menampilkan graceful state transparan: *"Emiten Belum Terdaftar di Registri Sekunder"* tanpa exception.

---

## 3. Verifikasi Interaksi Antarmuka Pengguna

| Fitur / Komponen | Skenario Pengujian | Hasil Pengujian | Bukti Verifikasi |
|---|---|---|---|
| **Dropdown Profil** | Klik tombol avatar (`@e12`), klik luar / tekan `Escape` | **VERIFIED** | Menu terbuka menampilkan Profil, Health, Pengaturan; menutup saat tombol Escape ditekan. |
| **Simpan Profil Analis** | Mengubah nama analis menjadi `Dr. Budi Santoso, CFA`, submit form | **VERIFIED** | Form POST sukses, redirect 302, perubahan tersimpan persisten di `aether_brand.json` pasca-refresh halaman. |
| **Keamanan Upload Logo** | Upload berkas valid (PNG) vs berkas tidak valid (.svg / .exe) | **VERIFIED** | Berkas .svg ditolak dengan pesan validasi *"Format file tidak diizinkan. Hanya PNG dan JPG yang didukung"*. PNG tersimpan di `public/images/logo.png`. |
| **Watchlist Per-Pengguna** | Toggle bintang emiten (AALI) pada `/market` | **VERIFIED** | Mengubah state tombol dari *"Tambahkan"* menjadi *"Dalam Pemantauan Aktif"* secara asinkron tanpa reload halaman penuh. |
| **Modal Riset On-Demand** | Input ticker valid (`BBCA`) vs tidak dikenal (`UNKNOWN`) | **VERIFIED** | `BBCA` menghasilkan Request ID `REQ_20261027_003341_BBCA_LbK0` (HTTP 200). `UNKNOWN` ditolak dengan HTTP 422 `REJECTED_UNKNOWN_TICKER`. |
| **Sinkronisasi Lintas Layar** | Permintaan riset muncul pada detail emiten dan AI History | **VERIFIED** | `REQ_20261027_003341_BBCA_LbK0` langsung tertera pada Tab 7 emiten `/market/BBCA?tab=history` dan tabel on-demand `/`. |
| **Filter & Pagination** | Pencarian kode emiten dengan pagination | **VERIFIED** | Query string `search` dan `type` tetap terjaga saat berpindah halaman melalui `withQueryString()`. |
| **Grafik Harga & Volume** | Visualisasi canvas responsif pada Tab Ringkasan | **VERIFIED** | Render native HTML5 Canvas menggunakan data aktual EOD 30 bar. Data kosong menampilkan state `DATA_NOT_AVAILABLE`. |
| **Responsivitas Layar Kecil** | Tampilan mobile (lebar 390px dan 768px) | **VERIFIED** | `hasHorizontalOverflow: false`. Tabel dan chart terbungkus scrolling horizontal halus tanpa memotong kontainer induk. |

---

## 4. Kepatuhan Audit & Batasan Operasional
- **Zero Live Broker Orders**: Seluruh alokasi dan riset bertindak sebagai indikator analitis institusional; tidak ada modul eksekusi broker live.
- **Isolasi Testing**: 100% automated test menggunakan SQLite in-memory `:memory:` dan mock transport. Tidak ada pemanggilan API live LLM atau pengiriman Telegram saat test suite berjalan.
- **Status Identitas Desain**: Sesuai mandat pengujian, implementasi ini diberi label transparan: *"Berbasis DESIGN.MD; kesesuaian dengan aset Stitch belum diverifikasi"*.
