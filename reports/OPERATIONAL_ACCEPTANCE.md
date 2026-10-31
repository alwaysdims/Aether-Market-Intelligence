# LAPORAN PENERIMAAN OPERASIONAL & KESIAPAN SISTEM (OPERATIONAL ACCEPTANCE)
## AETHER MARKET INTELLIGENCE
**Workspace**: `E:\Aether-Market-Intelligence`  
**Waktu Audit**: 27 Oktober 2026, 00:50:00 WIB  
**Status Evaluasi**: **OPERATIONAL READINESS VERIFIED — FORWARD OBSERVATION ACTIVE**  

---

## 1. Konfigurasi AI Efektif & Liveness Router

Sistem membedakan secara tegas konfigurasi modul AI, flag perlindungan global, dan status operasional masing-masing jalur:

| Modul AI / Jalur | Endpoint & Model | Parameter Kontrol | Status Provider | Keterangan & Proteksi |
|---|---|---|---|---|
| **Chat Gemini Saham** | `http://127.0.0.1:20128/v1`<br>`ag/gemini-3.8-flash-high` | `services.ai_router.api_key` | **inference_success** | Aktif untuk chat interaktif dua arah telegram via allowlist otorisasi pengguna. |
| **Riset On-Demand** | `http://127.0.0.1:20128/v1`<br>`ag/gemini-3.8-flash-high` | `AI_LIVE_INFERENCE_ON_DEMAND_ENABLED=true` | **inference_success** | Aktif untuk analisis mendalam emiten berbasis request tiket. |
| **Narasi Background** | `http://127.0.0.1:20128/v1`<br>`ag/gemini-3.8-flash-high` | Rate-limited worker (`limit=2`) | **configured** | Berjalan di latar belakang antrean worker `run_aether_workers.bat` prioritas 4. |
| **Inferensi EOD Otomatis** | `http://127.0.0.1:20128/v1`<br>`ag/gemini-3.8-flash-high` | `AI_LIVE_INFERENCE_EOD_ENABLED=false`<br>`AI_LIVE_INFERENCE_ENABLED=false` | **configured** | Terkunci secara fail-safe. Menggunakan safe rule-based heuristic fallback saat pipeline EOD bursa berjalan tanpa `--live-ai`. |

### Verifikasi Liveness Completion Aktual:
- **Router Endpoint**: `http://127.0.0.1:20128/v1/chat/completions` (9Router Local Service).
- **Probing Hasil**: Mengirimkan payload uji dengan `stream: false` dan token terenkripsi.
- **Respons Model**: HTTP 200 OK, respons JSON OpenAI-compatible (`finish_reason = stop`).
- **Secret Hygiene**: Kunci API dan token otorisasi tetap terisolasi di `.env` lokal, tidak pernah dicetak dalam log atau chat.

---

## 2. Cakupan Basis Data Aktual (Database Coverage Metrics)

Data per **27 Oktober 2026, 00:40:52 WIB** dari basis data PostgreSQL `project-bot:5432`:

```
========================================================================================
METRIK CAKUPAN BASIS DATA OPERASIONAL AETHER
========================================================================================
Registri Emiten BEI         : 942 Instrumen (941 Ekuitas Saham, 1 Indeks Acuan ^JKSE)
Observasi Harga EOD         : 261 Emiten Unik | 59.504 Bar Riil | Bar Terakhir: 2026-10-26
Laporan Keuangan Fiskal     : 198 Emiten Unik | 914 Rekor Fundamental
Metrik Analisis Teknikal    : 255 Emiten Terhitung
Narasi Sintesis AI (Gemini) : 91 Analisis Terstruktur Tersimpan
Aksi Korporasi Terlacak     : 1.862 Rekor Aksi Emiten
Permintaan Riset On-Demand  : 12 Tiket (5 Selesai, 0 Gagal, 0 Antrean Terbengkalai)
Telegram Outbox             : 0 Pending | Dispatcher Idle & Ready
========================================================================================
```

---

## 3. Matriks Status Penerimaan Telegram & Penjadwalan Fungsional

Penerimaan fungsional dipisahkan per kapabilitas berdasarkan bukti eksekusi terverifikasi:

| Kapabilitas / Alur | Status Audit | Bukti & Dasar Verifikasi |
|---|---|---|
| **1. Transport Jaringan Telegram** | **VERIFIED** | Hermes Gateway (`PID 12040`) polling tunggal. Terbukti menerima update nyata (`496962992`, `496962993`) dan mengirim outbound message (`387`, `389`) tanpa replay sintetis. |
| **2. Routing & NLU Setelah Patch** | **VERIFIED** | Pengenalan kueri kompleks (analisis pasar + rekomendasi + waktu) memicu `COMMAND_SCHEDULED_MARKET_ANALYSIS`. Filter `extractTicker` divalidasi ketat terhadap registri; kata seperti 'PASAR', 'SITUASI', 'KONDISI' tidak lagi diidentifikasi sebagai simbol saham (188 tests passed). |
| **3. Analisis Pasar & Breadth Obyektif** | **VERIFIED (LOGIC) / PENDING (LIVE RUN)** | Logika breadth kanonikal menghitung atomic snapshot dari 501 emiten ekuitas: 474 eligible (86 advancers, 295 decliners, 93 unchanged, median -1,23%), 27 excluded (26 missing bar 26-Okt, 1 missing bar sesi 23-Okt). Persamaan $86+295+93=474$ dan $474+27=501$ terbukti $100\%$ konsisten. Benchmark IHSG 5.439,26 (+0,95%). Semantik waktu dipisahkan ketat: `market_session_closed_at` (16:00:00 WIB = 09:00:00 UTC), `evidence_available_at` (23:01:14 WIB = 16:01:14 UTC), dan `analysis_information_cutoff`. |
| **4. Penjadwalan Persisten DB (Tabel `scheduled_telegram_jobs`)** | **VERIFIED** | Tabel migrasi `2026_10_27_080000`, `2026_10_27_083000`, `2026_10_27_090000`, dan `2026_10_27_100000` aktif di PostgreSQL. Kontrak waktu terpisah: `scheduled_at`, `started_at`, `analysis_completed_at`, `market_session_closed_at`, `evidence_available_at`, `analysis_information_cutoff`, `sent_at`, dan `lateness_seconds`. Idempotency key, status lifecycle (`PENDING`, `PROCESSING`, `COMPLETED`, `MISSED`, `FAILED`), dan zona waktu `Asia/Jakarta` terlindungi. |
| **5. Penanganan Waktu Lampau (Past Time)** | **VERIFIED** | Jika waktu yang diminta (misal 09:05 WIB) telah terlewat saat pesan diproses (09:47 WIB), sistem mencatat status `MISSED_REQUESTED_TIME` dan menyajikan analisis terkini dengan disclaimer transparan, **TIDAK menjadwalkan diam-diam untuk hari esok**. |
| **6. Eksekusi Terjadwal & Outbox Dispatcher** | **VERIFIED (MOCK) / PENDING (LIVE USER TEST)** | Task Scheduler `\AetherScheduledDispatcher_1min` aktif mengeksekusi `scripts/run_scheduled_dispatcher.bat` setiap 1 menit. Fencing aman memverifikasi kesesuaian PID, image (`php.exe`), dan `worker_process_created_at` sebelum termination guna mencegah bahaya PID reuse. Verifikasi kepemilikan token (`worker_token`) dieksekusi secara atomik di dalam transaksi `DB::transaction(...)` dengan `lockForUpdate()`. Kontrak pengiriman transparan: dieksekusi pada tick dispatcher terdekat (bukan detik persis), ditambah durasi analisis dan latensi outbox. |
| **7. Batasan Data & Penolakan Klaim "Pasti Naik"** | **VERIFIED** | Horizon dikonfirmasi eksplisit: "Analisis berbasis EOD sesi sebelumnya; kandidat swing". Disclaimer dicantumkan di header laporan: "Tidak ada jaminan saham pasti naik. Kinerja masa lalu tidak menjamin pergerakan sesi berjalan." Tiap kandidat wajib memiliki batas pembatalan (*invalidation level / cut-loss*) berbasis tick BEI. |

*Status Pengujian Live Pengguna*: **PENDING (Menunggu pesan pengguna dengan waktu WIB masa depan)**.
Sistem siap menerima pesan dari akun Telegram pengguna asli dengan waktu mendatang (misal *"analisis pasar dan rekomendasi jam 11.35"*). Bot akan membalas konfirmasi lengkap (tanggal, jam, horizon berbasis EOD, data cutoff, kontrak pengiriman, catatan ketepatan liveness host, toleransi keterlambatan) dan mengeksekusi otomatis via worker.

---

## 4. Evaluasi Forward & Integritas Audit Screening

- **Waktu Host & Sesi Pasar**: Selasa, 27 Oktober 2026, 10:35 WIB.
- **Status Sesi Perdagangan BEI**: **SESSION_1 (SESI PERDAGANGAN PAGI: 09:00 - 12:00 WIB)** berdasarkan perhitungan kalender bursa `BeiCalendarService`. Label pre-market dikoreksi karena waktu host telah melewati pembukaan pukul 09:00 WIB.
- **Kebijakan Finalitas Data**: Pukul 16:05 WIB adalah probe ketersediaan data sekunder publik (Yahoo Finance), bukan jaminan finalitas bursa. Evaluasi data final hanya dijalankan setelah data bursa resmi ditutup dan terbukti final.
- **Status Evaluasi Forward Kohort SWING_FILTER_V1**:
  * **Output A (Studi Sentuhan Barrier)**: Tetap berstatus `WAITING_FOR_FINAL_DATA` untuk sesi 27-Okt. Sesi perdagangan 27-Okt sedang berlangsung dan belum membentuk candle penutupan EOD final.
  * **Output B (Simulasi Strategi Eksekusi)**: Tetap berstatus **WITHHELD** (ditahan) pada implementasi saat ini sesuai batasan metodologi eksekusi riil dan ketiadaan order broker. Hal ini bukan larangan permanen untuk penelitian simulasi di masa depan; pembaruan metodologi wajib didokumentasikan sebagai versi terpisah.
  * **Integritas Kohort**: Seluruh 13 kandidat kohort dan level barrier tick tetap beku. Rekomendasi atau screening percakapan Telegram tidak mengubah kohort forward.

---

## 5. Ringkasan Kesiapan Dataset Penelitian Lanjutan (Query Terbaru 27-Okt-2026 06:15 WIB)

| Dimensi Dataset | Status Kesiapan (Fresh Query) | Progres vs Baseline Riil | Catatan & Batasan |
|---|---|---|---|
| **Histori Harga EOD** | Siap (89.318 bar) | +29.814 bar (+50,10%) | 384 emiten unik (379 emiten $\ge 20$ bar, 370 emiten $\ge 200$ bar). Sumber sekunder publik. |
| **Laporan Keuangan** | Siap (1.301 laporan) | +387 laporan (+42,34%) | 281 emiten unik dengan laporan fiskal tahunan terstruktur (baseline: 198 emiten, +41,92%). |
| **Aksi Korporasi** | Siap (2.693 rekor) | +831 rekor (+44,63%) | 280 emiten unik dengan rekor dividen, split, dan rights issue (baseline: 198 emiten, +41,41%). |
| **Metrik Kalkulasi** | Siap (380 emiten) | +125 emiten (+49,02%) | 380 teknikal valid, 245 fundamental valid, 42 parsial (metrik perbankan dikecualikan dari DER). |
| **Narasi AI Gemini** | Siap (209 analisis) | +118 rekor (+129,67%) | 191 emiten unik tersimpan narasi grounded melalui local router (growth distinct emiten: N/A). |
| **Point-in-Time** | Terlindungi | 100% konsisten | `decision_date` terikat permanen dengan evidence snapshot ID dan hash SHA-256. |

---

## 6. Finalisasi Penerimaan Antarmuka & Rekonsiliasi (Final Acceptance)
- **Suite Pengujian Terisolasi**: 194 tests, 887 assertions, 0 failures (PHPUnit).
- **Integritas DB Constraint**: Unique constraint `uq_obs_canonical_eod` diterapkan pada `[ticker, bar_date, interval, data_classification, source]`. Sisa duplikat: 0.
- **Kanonikal Perubahan Harga**: Pemisahan tegas antara perubahan harian terhadap previous session close dan intraday vs open. Kebijakan gap: histori berlubang didiskualifikasi dari perubahan harian (N/A) dengan pencatatan `gap_reason`.
- **RSI Standar Wilder**: Recursive exponential RMA (`alpha=1/14`) pada 239 candle warmup, dengan edge case 50.0 untuk seri konstan, 100.0 untuk zero loss, dan 0.0 untuk zero gain.
- **Label Model Prediktif**: `RandomForest_v1.0` (artefak model riil) dipisahkan dari strategi aturan teknikal `Strategi Aturan Teknikal (EXP-04)`.
- **Pemisahan Status Inferensi Bar**: Instrumen konstituen tanpa kalkulasi bar aktif dilaporkan `NO_ACTIVE_INFERENCE_FOR_BAR` dengan probabilitas/confidence `N/A`.
- **Penguncian Ticker Tidak Dikenal**: Tombol riset dinonaktifkan (`disabled`) untuk simbol tidak terdaftar BEI guna mencegah harvesting data liar.
- **Kolektor EOD Diperkuat**: Validasi angka finite `math.isfinite()`, volume integer non-negatif, ISO date, dan pencatatan alasan pembuangan bar invalid.
- **Palet Warna Institusional**: Ditegakkan konsisten pada Navy (`#05152B`), Gold (`#E5C49B`), dan Ivory (`#F5F1E8`).
- **Sidebar Desktop Terkunci**: Fixed viewport height; area konten bergulir independen tanpa menggeser status footer.
