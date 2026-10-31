# LAPORAN PEMULIHAN INBOUND TELEGRAM & PENANGANAN PESAN TAK TERJAWAB
**Aether Market Intelligence — Subsistem Chat & Transport Telegram**
**Tanggal Audit & Eksekusi:** 28 Oktober 2026 (00:30 WIB)
**Status Akhir:** `INBOUND_END_TO_END_VERIFIED` & `TRANSPORT_RECOVERED`

---

## 1. Kronologi & Pelacakan Payload Kasus Screenshot (27 Oktober 2026)

### Pesan Pengguna
- **Teks Asli:** `"kan ihsg lagi crash nih dan saya punya 200k kira kira saham apa yg harus saya beli ketika ihsg crash kaya gini"`
- **Waktu Masuk:** 27 Oktober 2026, 23:26 WIB
- **Sender User ID:** `5923895922` (Allowlisted)
- **Telegram Update ID:** `496962994` dan `496962995`

### Jejak Audit End-to-End
1. **Cloud Telegram:** Update tersimpan di server Telegram (`pending_update_count: 2`). Update ID asli tidak terhapus.
2. **Receiver Host (Hermes Gateway):** Proses Gateway PID 5976 lampau mengalami exit pada 23:20 WIB. Pada saat pesan dikirim pukul 23:26 WIB, receiver lokal dalam kondisi nonaktif.
3. **Pemulihan Transport:** Pada 28 Oktober 2026 pukul 00:28 WIB, Gateway dihidupkan ulang dengan konfigurasi background resmi (`Hermes_Gateway.vbs`) dan fallback rute IP DC5 `149.154.167.220` serta DC4 `149.154.166.110`.
4. **Penarikan Pesan:** Begitu polling Telegram aktif (`generation 1`), update `496962994` dan `496962995` ditarik secara otomatis ke host dan dicatat pada `C:\Users\ASUS\AppData\Local\hermes\telegram_update_receipts_8583524735.json`.
5. **Eksekusi Aether Router Plugin:** Pesan disalurkan ke subsistem backend Laravel via artisan `hermes:telegram-chat`.
6. **Outbound Reply:** Balasan acknowledgement dan routing dikirimkan ke Telegram pengguna:
   - Update ID `496962994` -> Balasan terkirim ke Telegram (Outbound Message ID: `398`).
   - Update ID `496962995` -> Balasan terkirim ke Telegram (Outbound Message ID: `399`).

---

## 2. Titik Kegagalan Aktual & Perbaikan yang Diterapkan

| Komponen | Gejala Awal | Penyebab Akar Masalah | Tindakan Perbaikan | Status Verifikasi |
| :--- | :--- | :--- | :--- | :--- |
| **Transport Telegram** | Polling macet / ConnectTimeout | DNS hostname `api.telegram.org` timeout (>14 detik) akibat degradasi rute ISP lokal host. | Mengonfigurasi `TELEGRAM_FALLBACK_IPS="149.154.167.220,149.154.166.110"` pada VBS runner background. SNI dan verifikasi TLS tetap utuh. | **VERIFIED** (Polling stabil, generation 1) |
| **Kredensial 9router** | HTTP 401 Unauthorized | `AI_API_KEY` pada `dashboard/.env` kosong sehingga panggilan ke 9router lokal ditolak. | Menyinkronkan kunci aman dari `.env` lokal ke `dashboard/.env` tanpa membocorkan nilai rahasia. | **VERIFIED** (HTTP 200 via 9router) |
| **Parser Jawaban SSE** | Respons AI kosong / parser error | 9router mengembalikan `text/event-stream` dengan delta `reasoning_content` (pemikiran CoT) mendahului `content`. | Memperbarui `AiConversationService::parseResponseData()` untuk memfilter hanya `delta.content`, mengabaikan `reasoning_content`, dan menangani buffering chunk. | **VERIFIED** (Test live completion sukses "AKTIF") |
| **On-Demand Collector** | Error import model | Namespace model `ResearchRequest` tidak di-import di `OnDemandDataCollectorService.php` (`Class not found`). | Menambahkan `use App\Models\ResearchRequest;` pada header file. | **VERIFIED** (10/10 test OnDemand lulus) |
| **Scheduled Test** | Assert mismatch akibat shift tanggal | Pengujian time-shifting menggunakan waktu riil sistem tanpa membekukan `Carbon::setTestNow()`. | Membungkus eksekusi test pada `ScheduledMarketAnalysisAndTelegramTest.php` dengan `Carbon::setTestNow()`. | **VERIFIED** (194/194 test suite passed) |

---

## 3. Pemisahan Jalur Chat dan Pipeline Prediksi EOD

1. **Prioritas Jalur Chat Interaktif:**
   - Ditangani langsung oleh `AetherRouter` (Group 0 di PTB) yang memanggil `TelegramTwoWayService` secara sinkron/asinkron cepat dengan batas timeout proses 40 detik.
   - Pesan tersimpan secara tahan lama (`telegram_conversations`) sebelum pekerjaan komputasi panjang dijalankan.
2. **Isolasi Beban EOD:**
   - Evaluasi horizon 5 hari, backfill 501 emiten, dan ringkasan batch berjalan di background batch script (`run_aether_workers.bat` / Task Scheduler).
   - Pekerjaan batch tidak mengunci tabel percakapan `telegram_conversations` dan tidak menahan proses receiver Gateway.
3. **Penyampaian Transparan ke Pengguna:**
   - Pertanyaan pasar modal (seperti modal Rp 200.000 pada kondisi IHSG) dijelaskan secara transparan dengan menyatakan tanggal data terakhir yang tersedia di database, batasan lot minimum BEI (100 lembar), dan klarifikasi profil risiko sebelum eksekusi riset.

---

## 4. Status Eksekusi Komponen

- **Provider Completion:** VERIFIED (9router `ag/gemini-3.8-flash-high` merespons sukses).
- **Gateway Polling:** VERIFIED (Proses `pythonw.exe` aktif, polling generation 1 sehat).
- **Pesan Pengguna:** VERIFIED (Ditarik dari server Telegram dengan Update ID `496962994` & `496962995`).
- **Jawaban Nyata Terkirim:** VERIFIED (Outbound Message ID: `398` & `399`).
- **Gateway Tetap Hidup:** VERIFIED (Proses berjalan stabil di latar belakang Windows).
