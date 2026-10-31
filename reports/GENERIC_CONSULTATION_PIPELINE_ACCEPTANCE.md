# Laporan Penerimaan Pipeline Konsultasi Umum Seluruh Emiten BEI
**Dokumen:** `reports/GENERIC_CONSULTATION_PIPELINE_ACCEPTANCE.md`  
**Status:** VERIFIED & OPERATIONAL  
**Waktu Audit:** 2026-10-29 02:30 WIB  
**Target:** Seluruh Emiten Terdaftar BEI (Generalisasi Tanpa Hardcode Ticker)

---

## 1. Akar Masalah Insiden Job #7 (DEWA)

Pada saat pengguna mengirimkan permintaan konsultasi untuk emiten `DEWA`:
- **Inbound Update:** Bot menerima pesan dan membuat record `ScheduledTelegramJob` #7 dengan request type `ASYNC_CONSULTATION`.
- **Eksekusi Worker:** Worker otomatis `hermes:process-consultation-queue --live` berhasil mengklaim Job #7, mengeksekusi pipeline analisis AI (Gemini 2.5) dan tool retrieval secara tuntas dalam 25 detik (`2026-10-28 16:04:47 UTC`), menghasilkan record outbox #28.
- **Titik Hambat (Root Cause):** Pada dispatcher pengiriman live (`TelegramFormattingService.php`), batas waktu timeout subprocess pengiriman CLI Telegram (`hermes.EXE send`) dipatok 30 detik (`$process->setTimeout(30)`). Karena traffic network atau antrean internal transport gateway saat pengiriman outbox #28, subprocess CLI melewati batas 30 detik (`ProcessTimedOutException: The process "C:\Users\ASUS\AppData\Local\hermes\bin\hermes.EXE" exceeded the timeout of 30 seconds`). Outbox #28 tertahan berstatus `FAILED` dan Job #7 tetap berstatus `PROCESSING`.

---

## 2. Pemulihan & Bukti Pengiriman Job #7

- **Perbaikan Timeout Dispatcher:** Timeout subprocess pengiriman Telegram CLI dinaikkan dari 30 detik menjadi 60 detik di `TelegramFormattingService.php` baris 295 untuk mengakomodasi jitter jaringan secara aman.
- **Pemulihan Idempotent Outbox #28 & Job #7:**
  * Outbox #28 dipulihkan dari `FAILED` kembali ke antrean `QUEUED`.
  * Dispatcher live mengeksekusi pengiriman outbox #28 secara sukses tanpa duplikasi job.
  * **Status Outbox #28:** `SENT`.
  * **Outbound Telegram Message ID:** **448** (merujuk ke Chat ID `5923895922`, Inbound Message ID `445`).
  * **Status Akhir Job #7:** `COMPLETED` / `SENT`.

---

## 3. Generalisasi Pipeline Konsultasi Emiten BEI (Tanpa Hardcode Ticker)

1. **Resolusi Instrumen & Universe Registry (`BeiUniverseRegistry.php`):**
   - Registry diperluas mencakup pemetaan umum emiten BEI seperti `DEWA` (`PT Darma Henwa Tbk`), serta fallback dinamis untuk setiap 4 huruf kapital ticker BEI (`Emiten Terdaftar BEI (XXXX)`).
   - Pipeline tidak mensyaratkan penambahan kode per emiten baru.

2. **Tool Keterbukaan & Laporan Keuangan Dinamis (`AiToolExecutorService.php`):**
   - Kasus `search_latest_disclosures_and_filings` dirancang menangani emiten apa pun secara deterministik.
   - Jika dokumen PDF resmi tersertifikasi belum terindeks lokal (misalnya emiten non-PTRO), sistem secara eksplisit mengembalikan status `NOT_FOUND_IN_CHECKED_SOURCES` beserta batasan sumber publik IDX tanpa mengarang angka atau melakukan fallback halusinasi.
   - AI Gemini diarahkan menyajikan **analisis parsial** berdasarkan pergerakan harga historis pasar dan rasio fundamental yang tersedia, secara terbuka menyatakan data laporan keuangan kuartalan belum terindeks resmi, dan memandu pengguna tentang batasan valuasi yang belum dapat dihitung pasti.

3. **Isolasi Worker & Proteksi Antrean:**
   - Worker konsultasi cepat (`hermes:process-consultation-queue`) berjalan terpisah dari worker scheduled backfill panjang (`hermes:process-scheduled-jobs`).
   - Klausul filter ketat memastikan kedua worker tidak saling merebut atau memicu `MISSED` prematur.
   - Watchdog dan auto-reclaim dead worker mendeteksi worker yang mati tanpa meninggalkan job menggantung.

---

## 4. Metrik Waktu & Durasi Eksekusi Job #7

- **Waktu Masuk Job (created_at):** `2026-10-28 16:04:19 UTC` (23:04:19 WIB)
- **Waktu Klaim Worker (started_at):** `2026-10-28 16:04:22 UTC` (Waktu antre: 3 detik)
- **Waktu Analisis Selesai (analysis_completed_at):** `2026-10-28 16:04:47 UTC` (Durasi penalaran/tools: 25 detik)
- **Evidence Snapshot ID:** `SNAP_FILING_PTRO_H1_2026` (diisolasi hanya untuk PTRO; DEWA mencatat sumber keterbukaan publik tanpa fallback sintetis).
- **Outbox ID:** #28 (`payload_chars: 2.124`).
- **Waktu Pengiriman Outbox (sent_at):** `2026-10-28 19:33:43 UTC` (02:33:43 WIB).
- **Telegram Outbound Message ID:** **448**.

---

## 6. Audit Delivery Otomatis, Klasifikasi Transport & Penemuan Sumber Multi-Emiten (29 Oktober 2026)

### A. Klasifikasi Status Transport & Delivery Recovery
- **Pemisahan Status Analisis vs Status Delivery:**
  * Status analisis AI/tool (`analysis_completed_at`) disimpan independen dari `delivery_status`.
  * Jika proses pengiriman menghadapi kendala, job tetap mencatat analisis tuntas (`status: COMPLETED`) dengan `delivery_status: DELIVERY_UNKNOWN` atau `FAILED` sesuai tingkat kepastian receipt, tidak menggantung di `PROCESSING`.
- **Klasifikasi `DELIVERY_UNKNOWN` vs `FAILED`:**
  * Timeout transport subprocess CLI (`ProcessTimedOutException`) atau keluaran sukses tanpa `message_id` numerik diklasifikasikan sebagai `DELIVERY_UNKNOWN` (hasil pengiriman belum pasti dan tidak boleh di-retry secara membabi buta agar tidak spam ke pengguna).
  * Error kegagalan non-timeout (misal kode status HTTP failure / exit code selain timeout) menaikkan `retry_count` dengan backoff eksponensial ($2^{\text{retry\_count}}$ menit) dan penjadwalan `retry_after`. Batas retry ditetapkan maksimal 3 kali sebelum status final `FAILED`.

### B. Validasi Instrumen & Resolusi Multi-Emiten
- **Anti-Arbitrary 4-Letter Regex:**
  * Validasi ticker 4 huruf di `BeiUniverseRegistry.php` dan `TelegramTwoWayService.php` tidak lagi menerima sembarang string 4 huruf.
  * Setiap kandidat diverifikasi silang terhadap katalog `KNOWN_BEI_ISSUERS` atau tabel `market_observations` di database.
  * Jika kandidat tidak terdaftar, kueri ditolak secara sopan dengan `KLARIFIKASI CAKUPAN INSTRUMEN` (`UNREGISTERED_BEI_TICKER`) tanpa membuat antrean job asinkron kosong.
  * Provenance resolusi mencatat sumber verifikasi (`source: KNOWN_BEI_ISSUERS_CATALOG` atau `DATABASE_MARKET_OBSERVATIONS`) beserta stempel waktu.

### C. Penemuan Sumber Umum (Source Discovery Pipeline)
- **Status Transparan Tanpa Fallback Sintetis:**
  * Emiten tanpa cache laporan keuangan lokal diproses melalui `search_latest_disclosures_and_filings` dengan mencatat sumber yang diperiksa (misal portal Keterbukaan Informasi BEI) dan metode `PUBLIC_SEARCH_CONNECTOR`.
  * Status retrieval eksplisit: `SOURCE_DISCOVERY_COMPLETED_NO_DOCUMENTS`, bukan `NOT_FOUND_IN_CHECKED_SOURCES` yang ambigu.
  * Keterbatasan didokumentasikan dalam metadata provenance tanpa mengarang URL dokumen atau memakai cache PTRO.
  * Gemini menyusun analisis parsial berdasarkan data harga pasar dan rasio finansial yang tersedia.

### D. Isolasi Pengujian Database
- Pengujian mutasi PostgreSQL (`PostgresTimezoneAndDeadlineSemanticsTest`) menggunakan skema isolasi sementara (`CREATE SCHEMA test_sem_...`) yang langsung di-drop secara otomatis (`DROP SCHEMA ... CASCADE`) pada blok `finally`, menjamin zero mutation pada data operasional `project-bot:5432`.

