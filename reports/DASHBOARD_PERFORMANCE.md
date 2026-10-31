# LAPORAN OPTIMASI PERFORMA DASHBOARD AETHER
**Subdivisi Rekayasa Performa & Antarmuka Institusional**  
**Tanggal Pengujian & Verifikasi:** 28 Oktober 2026  
**Status Evaluasi:** **VERIFIED, BENCHMARKED & DEPLOYED**  
**Lingkungan Eksekusi:** PHP 8.3.7 CLI Built-in Web Server (`127.0.0.1:8000`), PostgreSQL 16 (`project-bot`), Windows 11.

---

## 1. Analisis Akar Masalah (Root Cause Profiling)

Sebelum optimasi dilakukan, pengguna mengeluhkan jeda **3–5 detik** saat berpindah antar-tab detail emiten (`/market/{ticker}`). Profiling empiris menyeluruh mengungkapkan akar penyebab utama:

1. **Monolithic Data Fetching (Eager Tab Queries):**
   Pada setiap navigasi tab (misalnya hanya membuka Tab "Teknikal"), `DashboardController::tickerDetail` secara membabi buta mengeksekusi **22–23 query SQL** untuk seluruh data 7 tab (riwayat candlestick 30 hari, hitungan distinct bar_date, fundamental fiskal, corporate actions, AI narrative terbaru, paginasi riwayat analisis, riwayat research request, berita bursa, dan sinyal prediksi).
2. **Full Page Reload & Heavy Head Footprint:**
   Navigasi tab menggunakan tautan tag `<a>` konvensional. Setiap klik memicu penghancuran dan pembuatan ulang seluruh DOM halaman (Full Page Lifecycle) sebesar 40–70 KB HTML, memicu unduhan berulang aset eksternal dan parsing ulang stylesheet Tailwind CSS runtime (`cdn.tailwindcss.com`).
3. **Overhead Latensi Eksternal:**
   Pada cold reload, koneksi font Google (`Plus Jakarta Sans`) dan CDN Tailwind menambahkan penundaan transfer jaringan 200–800 ms per navigasi.

---

## 2. Tindakan Optimasi Terukur (Architectural Changes)

### A. Dynamic Lazy Loading Tab dengan Graceful Fallback
- **Pemuatan Berbasis Kebutuhan:** `DashboardController::tickerDetail` kini mengevaluasi tab aktif (`$tab`) dan hanya mengeksekusi query database yang relevan:
  * Tab *Monitoring*: Hanya mengambil `signals` dan data identitas emiten (10 query).
  * Tab *Technical*: Mengambil `recentBars`, `totalHistoryBars`, dan `CompanyAnalysisMetric` (12 query).
  * Tab *Fundamental*: Mengambil `CompanyFundamental` dan `CompanyCorporateAction` (11 query).
  * Tab *News*: Mengambil `NewsItem` terkait emiten (10 query).
- **Endpoint Parsial Ringan:** Ditambahkan deteksi header `X-Tab-Request: 1` / `Request::ajax()`. Jika permintaan berasal dari navigasi asinkron, controller mengembalikan view parsial `dashboard.ticker_tab_content` (ukuran transfer terpangkas drastis menjadi hanya **800 bytes s.d. 30 KB**).
- **Asynchronous Tab Switching Client:**
  * Navigasi berpindah secara instan tanpa kedipan full-page reload.
  * Shell header institusional, status watchlist, harga EOD, dan tombol riset tetap berada di layar (*persistent layout*).
  * Sinkronisasi URL penuh via `window.history.pushState` dan dukungan navigasi tombol browser *Back/Forward* via listener `popstate`.
  * **In-Memory Tab Caching:** Tab yang pernah dikunjungi disimpan dalam `Map()` memori per sesi peramban. Berpindah kembali ke tab sebelumnya terjadi **seketika (< 5 ms)** tanpa request HTTP ke server.
  * **Race-Condition & Stale Protection:** Menggunakan `AbortController` untuk membatalkan request tab lama jika pengguna mengeklik tab lain secara cepat.
  * State visual loading yang halus (`opacity-40` dan `pointer-events-none`) serta mekanisme penanganan error/retry jika koneksi terputus.

### B. Health Check & Liveness In-Request Memoization
- Audit `getBrandConfig()` membuktikan pemeriksaan PDO database kini dimemoize via static variable per siklus request web, mencegah pemanggilan koneksi ganda yang tidak perlu.

---

## 3. Data Perbandingan Sebelum vs Sesudah (Empirical Benchmarks)

Pengujian diukur menggunakan instrumen benchmark HTTP multi-run (5 replikasi per tab) pada instrumen `PTRO`:

| Tab Emiten | Sebelum Optimasi (Full Reload TTFB / Size) | Sesudah Optimasi: Cold/Fresh Load (Total Waktu / Size) | Sesudah Optimasi: In-Memory Warm Switch (Client Cache) | Penurunan Beban Transfer |
| :--- | :---: | :---: | :---: | :---: |
| **1. Ringkasan** | ~1.066 ms / 56.467 bytes | **558 ms** (p95: 967 ms) / 16.928 bytes | **< 5 ms** (Instant) | **-70,0%** |
| **2. Pemantauan** | ~1.400 ms / 40.967 bytes | **479 ms** (p95: 562 ms) / 1.451 bytes | **< 5 ms** (Instant) | **-96,5%** |
| **3. Teknikal** | ~1.927 ms / 69.107 bytes | **492 ms** (p95: 718 ms) / 29.591 bytes | **< 5 ms** (Instant) | **-57,2%** |
| **4. Fundamental** | ~1.656 ms / 45.809 bytes | **518 ms** (p95: 556 ms) / 6.293 bytes | **< 5 ms** (Instant) | **-86,3%** |
| **5. Kesimpulan AI** | ~938 ms / 69.668 bytes | **656 ms** (p95: 1.041 ms) / 30.152 bytes | **< 5 ms** (Instant) | **-56,7%** |
| **6. News & Event** | ~1.200 ms / 40.312 bytes | **457 ms** (p95: 688 ms) / 796 bytes | **< 5 ms** (Instant) | **-98,0%** |
| **7. Riwayat AI** | ~1.350 ms / 46.855 bytes | **480 ms** (p95: 499 ms) / 7.339 bytes | **< 5 ms** (Instant) | **-84,3%** |

*Catatan Migrasi Aset Vite Production:*
- Runtime script CDN `cdn.tailwindcss.com` telah dihapus total dari `layouts/app.blade.php`.
- Dikompilasi penuh via `@vite(['resources/css/app.css', 'resources/js/app.js'])` menjadi bundle CSS teroptimasi (`app-BEZ8C0z4.css`, 58,9 KB).
- Menghilangkan latensi parsing CSS client-side (300–700 ms) saat inisialisasi layout peramban.

*Aturan Invalidation & Cache Lifecycle:*
- Client cache berumur 5 menit (TTL 300.000 ms).
- Cache diskop per ticker aktif untuk mencegah kebocoran antar-emiten.
- Payload yang mengandung token status `PROCESSING`, `SEDANG DIANALISIS`, atau `animate-pulse` tidak dicache untuk menjamin auto-refresh saat hasil AI selesai.
- Server response partial menyertakan header proteksi `Vary: X-Tab-Request, X-Requested-With` dan `Cache-Control: private, no-cache, no-store, must-revalidate` untuk mencegah proxy salah menyajikan potongan HTML sebagai halaman utuh.

*Catatan Latensi Browser Nyata:*
- Waktu total pergantian tab di peramban pengguna (DOM swap + render) terpangkas dari **3.000–5.000 ms** menjadi **400–600 ms** pada klik pertama, dan **0 ms (instan)** pada pergantian berulang.

---

## 4. Verifikasi Fungsionalitas & Integritas Sistem

1. **Uji Peramban Mandiri (Chrome Headless CDP):**
   - Navigasi tab 1 -> tab 3 (Teknikal) -> tab 5 (Kesimpulan AI) -> tab 1 (Ringkasan) berhasil diverifikasi mulus via browser snapshot.
   - Grafik HTML5 Canvas Candlestick & Volume berhasil digambar ulang secara reaktif via fungsi `renderChart()` otonom.
   - URL browser sinkron `?tab=technical`, `?tab=ai_insight`, dan tombol browser `Back` berhasil mengembalikan tab dan URL tanpa kendala.
   - Konsol browser bersih dari galat JavaScript (0 JS errors).
2. **Integritas Prediksi & Sinyal:**
   - Navigasi tab murni membaca data tersimpan di PostgreSQL. **Nol** pemanggilan ke 9router, **nol** token Gemini terbuang, dan **nol** aktivitas pesan Telegram terpicu.
3. **Regresi Uji Fitur:**
   - Test suite fitur dashboard `DashboardTest.php` lulus sempurna: **14 passed, 70 assertions, 0 failure**.
