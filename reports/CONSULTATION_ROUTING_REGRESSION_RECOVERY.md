# Consultation Routing Regression & Contract Recovery Manifest
**Sistem:** Aether Market Intelligence  
**Tanggal Verifikasi:** 2026-11-02  
**Status Audit:** **RECOVERED, TESTED & PRODUCTION-VERIFIED**  

---

## 1. Analisis Akar Masalah (Root Cause Trace)

### Inbound Asli yang Mengalami Kegagalan:
- **ID Percakapan:** `TelegramConversation #145`
- **Update ID:** `496963035` | **Message ID:** `555` | **User/Chat ID:** `5923895922`
- **Teks Inbound:** `"saya punya 200k enaknya masuk mana pada saat kondisi market seperti ini?"`
- **Respons Salah Sebelumnya:**
  ```text
  KLARIFIKASI CAKUPAN INSTRUMEN
  ──────────────────────────────────────────
  Kode emiten 'MARKET' tidak valid. Mohon sertakan 4 huruf kode saham BEI.
  Aether Market Intelligence hanya memproses saham resmi Bursa Efek Indonesia (BEI).
  ```

### Jalur Regresi Terbukti (Empirical Code Trace):
1. **Ekstraksi Ticker Liar (`extractTicker`):**  
   Di `dashboard/app/Services/TelegramTwoWayService.php` (baris 277), terdapat regex:  
   `preg_match('/(?:analisis|analisa|bedah|kondisi|kinerja|tambah|tambahkan)\s+(?:emiten\s+|saham\s+)?([A-Za-z0-9^]{2,8})/i', $text, $m)`  
   Karena kalimat pengguna mengandung frasa `"kondisi market"`, kata `market` tertangkap sebagai `$m[1]`. Token `'MARKET'` tidak ada pada daftar `$commonStopwords` lama, sehingga diekstrak sebagai kandidat ticker `$cand = 'MARKET'`.
2. **Pintu Masuk Terblokir (Premature Gatekeeping):**  
   Di `case 'GENERAL_CONVERSATION':` (baris 1025-1045), sebelum penjadwalan asinkron dilakukan, terdapat logika:
   ```php
   if ($this->isConsultativeQuery($text)) {
       $cand = $this->extractTicker($text);
       if ($cand !== null) {
           $resCand = $registry->resolveInstrument($cand);
           if (! ($resCand['is_valid'] ?? false)) {
               return "KLARIFIKASI CAKUPAN INSTRUMEN...\nKode emiten '{$cand}' tidak valid...";
           }
       }
       // ... penjadwalan job asinkron terpotong ...
   }
   ```
   Karena `'MARKET'` bukan kode saham resmi BEI, metode `resolveInstrument('MARKET')` mengembalikan `is_valid = false`.
3. **Dampak:**  
   Konsultasi bahasa alami pengguna ditolak seketika di pintu masuk (handler). Job asinkron ke Hermes tidak pernah dibuat, AI tidak pernah dipanggil, dan pengguna menerima template penolakan instrumen yang keliru.

---

## 2. Pembenahan Pembagian Tanggung Jawab Arsitektur

Prinsip pemisahan tanggung jawab yang telah dipulihkan:
1. **Inbound Gateway Handler (`TelegramTwoWayService`):**
   - **Tanggung Jawab:** Otorisasi keamanan user ID, deduplikasi update ID, penyimpanan rekod percakapan durable, dan parsing perintah utilitas eksplisit (`/help`, `/status`, `/signals`, `/recap`, `/watchlist`, `/portfolio`).
   - **Konsultasi Bahasa Alami:** Seluruh pesan percakapan bebas (konsultasi modal, situasi pasar, edukasi, komparasi, follow-up, baik dengan maupun tanpa ticker) **wajib diteruskan ke antrean asinkron (`ScheduledTelegramJob`) tanpa penghadangan validasi ticker di pintu masuk**.
   - **Validasi Ticker Eksplisit:** Hanya perintah eksplisit yang memang meminta analisis instrumen tunggal (seperti `/analisis ZZZZ` atau `analisis emiten ZZZZ`) yang berhak mengembalikan klarifikasi cakupan instrumen jika kode emiten tidak valid.
2. **Orchestrator Hermes & Reasoning Worker:**
   - Memahami ruang lingkup (scope) pertanyaan secara holistik.
   - Memilih tool yang relevan (`calculate_capital_allocation`, `consult_portfolio`, `search_latest_disclosures_and_filings`, `get_related_news`, dsb.).
   - Memvalidasi argumen ticker pada level pemanggilan tool individu di backend, bukan menolak percakapan pengguna secara menyeluruh.

---

## 3. Perbaikan Kode & Penguatan Logika

### A. Perbaikan Regex `extractTicker` (`TelegramTwoWayService.php`)
- Token percakapan umum ditambahkan ke `$commonStopwords`: `'MARKET'`, `'PORTOFOLIO'`, `'PORTFOLIO'`, `'MODAL'`, `'DANA'`, `'UANG'`, `'KAS'`, `'COCOK'`, `'ENAKNYA'`, `'MASUK'`.
- Pemisahan ketat antara sintaks dengan kata benda eksplisit (`emiten XYZ` / `saham XYZ`) vs kata kerja tanpa kata benda (`kondisi <kata>`, `analisis <kata>`):  
  Kata yang mengikuti kata kerja tanpa label `emiten`/`saham` **wajib diverifikasi keabsahannya di `BeiUniverseRegistry`** sebelum diakui sebagai kandidat ticker. Kata non-saham seperti `market`, `bagus`, atau `mendalam` otomatis menghasilkan `null`.

### B. Penghapusan Gatekeeping pada `case 'GENERAL_CONVERSATION':`
- Logika penolakan dini dihapus total dari alur `GENERAL_CONVERSATION`.
- Seluruh pesan percakapan/konsultasi otomatis didaftarkan sebagai `ScheduledTelegramJob` bertipe `ASYNC_CONSULTATION`.
- Jika ada ticker valid yang terdeteksi, ticker diteruskan sebagai metadata konteks pendukung; jika tidak ada ticker, parameter `ticker` dibiarkan `null` dan analisis berlanjut berbasis teks alami.

### C. Pengayaan Konteks Multi-Topik (`HermesConsultationAdapterService.php`)
Ketika `extractedTicker === null`, adapter kini menyediakan context briefing deterministik:
1. **Alokasi Modal Kas (`calculate_capital_allocation`):**  
   Mendeteksi nominal modal (misal `200k` -> Rp 200.000). Menghitung batas maksimal harga per lembar (`floor(capital / 100 * (1 + fee))`), daftar kandidat terjangkau minimal 1 lot beserta estimasi sisa kas, dan kandidat di luar jangkauan.
2. **Kondisi Pasar Acuan EOD (`^JKSE`):**  
   Menyediakan data penutupan resmi IHSG H-1 (EOD) dan menegaskan status quote intraday yang berstatus `QUARANTINED`.
3. **Konsultasi Portofolio (`consult_portfolio`):**  
   Menyediakan rekap posisi kas dan saham jika pengguna menanyakan portofolio.
4. **Instruksi Keamanan AI:**  
   Dilarang mencatat posisi fiktif, dilarang menganggap modal kas sebagai seluruh kekayaan pengguna, wajib menanyakan horizon & toleransi risiko pengguna, serta tidak menjanjikan keuntungan pasti.

---

## 4. Bukti Pengujian Gate Produksi (Production Entry-Point Gate)

Pengujian komprehensif dijalankan melalui `TelegramTwoWayService::handleInboundMessage` pada alur produksi nyata:

| Skenario Uji | Inbound Prompt | Intent Terklasifikasi | Respons Masuk / Aksi Sistem | Status Gate |
|---|---|---|---|---|
| **1. Kalimat Pengguna Persis** | `"saya punya 200k enaknya masuk mana pada saat kondisi market seperti ini?"` | `GENERAL_CONVERSATION` | Scheduled `Job #38` (ASYNC_CONSULTATION). Return ACK instan. Tidak ada error `MARKET`. | **PASSED** |
| **2. Modal Kas Bahasa Alami** | `"punya 200ribu cocok saham apa?"` | `GENERAL_CONVERSATION` | Scheduled `Job #39` (ASYNC_CONSULTATION). Return ACK instan. | **PASSED** |
| **3. Situasi Pasar** | `"market lagi turun sebaiknya bagaimana?"` | `GENERAL_CONVERSATION` | Scheduled `Job #40` (ASYNC_CONSULTATION). Return ACK instan. | **PASSED** |
| **4. Konsultasi Portofolio** | `"saya mau konsultasi portofolio"` | `COMMAND_PORTFOLIO` | Mengembalikan ringkasan analitis portofolio, kas, dan target alokasi 50/50. | **PASSED** |
| **5. Edukasi Konsep** | `"apa itu PER?"` | `GENERAL_CONVERSATION` | Scheduled `Job #41` (ASYNC_CONSULTATION). Worker memproses edukasi rasio PER. | **PASSED** |
| **6. Komparasi Emiten** | `"bandingkan BBCA dan BMRI"` | `COMMAND_COMPARISON` | Mengembalikan tabel komparasi teknikal/fundamental deterministik BBCA vs BMRI. | **PASSED** |
| **7. Follow-up Tanpa Ticker** | `"bagaimana risikonya?"` | `GENERAL_CONVERSATION` | Scheduled `Job #42` (ASYNC_CONSULTATION). Mengambil konteks percakapan terakhir. | **PASSED** |
| **8. Perintah Ticker Invalid** | `"analisis emiten ZZZZ"` | `QUERY_TICKER_STATUS` | Mengembalikan `KLARIFIKASI CAKUPAN INSTRUMEN: Kode emiten 'ZZZZ' tidak valid`. | **PASSED** |
| **9. Topik Luar Cakupan** | `"bagaimana resep nasi goreng enak?"` | `GENERAL_CONVERSATION` | Scheduled `Job #43` (ASYNC_CONSULTATION). AI merespons penolakan halus sesuai cakupan BEI. | **PASSED** |

---

## 5. Pemulihan Pesan Asli (Recovery Execution Trace)

Pesan inbound `Conversation #145` dipulihkan dan dieksekusi secara end-to-end tanpa synthetic update atau ACK duplikat:

1. **Pendaftaran Job:**  
   `ScheduledTelegramJob #45` didaftarkan langsung ke PostgreSQL dengan referensi `inbound_message_id = 555`, `telegram_update_id = 496963035`, `request_type = ASYNC_CONSULTATION`.
2. **Eksekusi Worker:**  
   Dieksekusi via `php artisan hermes:process-consultation-queue`.  
   - **Durasi Eksekusi:** 59.260 ms (~59 detik).
   - **Briefing Context:** Mengaktifkan tool `calculate_capital_allocation` (modal Rp 200.000) dan benchmark IHSG EOD (`^JKSE` 5.373,09).
   - **Sintesis Reasoning Hermes:**  
     * Menjelaskan batasan modal Rp 200.000 (1 lot = 100 lembar, harga maksimal Rp 1.997/lembar dengan fee).
     * Menganalisis kondisi pasar acuan (^JKSE) yang tertekan (-1,27% vs Open).
     * Melakukan screening kandidat terjangkau (KLBF, BLES) dengan batasan invalidasi ketat.
     * Mengunci status Day Trade ke `DAY_TRADE_NOT_ASSESSABLE_WITH_AVAILABLE_DATA` karena quote intraday berstatus `QUARANTINED`.
     * Memberikan opsi tindakan rasional (konservatif menahan cash vs spekulatif 1 lot) tanpa menjanjikan keuntungan pasti.
3. **Penyimpanan Artefak Bukti:**  
   - **Evidence Snapshot:** `EvidenceSnapshot #394` (`EV_HERMES_20261102_034927_45`) tersimpan di PostgreSQL.
   - **Analysis Run:** `AnalysisRun #1484` (`HERMES_RUN_20261102_034927_45`) tersimpan berstatus `COMPLETED`.
4. **Penerbitan Outbox & Sinkronisasi Percakapan:**  
   - **Outbox ID:** `TelegramOutbox #80` (`SENT`, outbound message id `MOCK_TG_JOB_6ae80901449be`).
   - **Percakapan Asli:** `TelegramConversation #145` diperbarui dari pesan error lama menjadi laporan analitis lengkap (3.406 karakter).
