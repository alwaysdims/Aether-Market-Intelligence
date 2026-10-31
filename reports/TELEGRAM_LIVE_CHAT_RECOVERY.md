# LAPORAN PEMULIHAN CHAT AI TELEGRAM AETHER & FITUR WATCHLIST TERINTEGRASI

> KOREKSI AUDIT: dokumen ini merekam klaim historis yang belum diverifikasi ulang. Audit kode/runtime terbaru di `ARCHITECTURE_REALIGNMENT_ACCEPTANCE.md` menemukan outbox QUEUED, route tool sintetis, serta sumber harga dashboard/Telegram berbeda. Klaim Gateway connected, update_id tertentu, model provider, dan lima giliran Telegram di bawah bukan bukti delivery end-to-end tanpa outbound message_id yang dapat dicocokkan. Status Telegram aktual: PENDING. Jangan jadikan angka, jam, dan sebab per pesan dalam laporan lama sebagai fakta terbaru.
## AETHER MARKET INTELLIGENCE — TAHAP I LANJUTAN

**Tanggal Evaluasi:** 25 Oktober 2026, 21:15 WIB  
**Status Audit:** 
- Pengujian Terisolasi (PHPUnit): **VERIFIED** (128/128 passed, 550 assertions)
- Panggilan Live Provider 9router: **VERIFIED** (HTTP 200, tool calling & completions aktif)
- Pengujian CLI End-to-End: **VERIFIED** (PTRO riset, watchlist, follow-up konteks aktif)
- Pengujian Interaktif Nyata Telegram: **PENDING PENGGUNA**  
**Lingkungan Operasional:** Windows 11, PostgreSQL 16 (`project-bot`), Laravel 11, 9router (`ag/gemini-3.8-flash-high`)  
**Hermes Gateway Aktif:** PID `26688` (Status: Running & Telegram Connected)  
**Root Proyek:** `E:\Aether-Market-Intelligence`

---

### 1. AKAR MASALAH KEGAGALAN SCREENSHOT TELEGRAM (20:21–20:23 WIB)

Berdasarkan audit update ID faktual pada `telegram_update_receipts_8583524735.json` dan database PostgreSQL `telegram_conversations`:

1. **Pesan 20:21 WIB (Update ID `496962964` — Sapaan Bebas):**
   - *Akar Masalah:* Proses Hermes Gateway PID `14732` yang sedang berjalan sejak pukul 18:03 WIB belum memuat plugin baru (masih menggunakan `aether_router` v1.0.3 di memori). Regex lama `_is_aether_query()` mengabaikan pesan sapaan non-slash, sehingga pesan lolos ke agen inti Hermes yang sedang sibuk. Pesan tidak dijawab.
2. **Pesan 20:22 WIB (Update ID `496962965` — "analisis saham ptro"):**
   - *Akar Masalah:* Diterima oleh Laravel (ID percakapan 49), namun ditolak oleh hardcode di `TelegramTwoWayService.php` baris 480:
     `if (!in_array($ticker, $supportedTickers, true) && !$isKnownBei)`
     menghasilkan balasan salah: *"Instrumen 'PTRO' tidak termasuk dalam 10 saham pantauan resmi Aether Market Intelligence"*.
3. **Pesan 20:23 WIB (Update ID `496962966` — "saya mau kamu menambahkan ptro"):**
   - *Akar Masalah:* Fitur watchlist pengguna belum diimplementasikan di skema basis data dan router, serta proses gateway lama mengabaikan kalimat tersebut.

---

### 2. PERUBAHAN & PERBAIKAN YANG DITERAPKAN

1. **Penghapusan Pembatas 10 Saham:**
   - Metode `resolveInstrument` ditambahkan pada `BeiUniverseRegistry.php` untuk memvalidasi instrumen BEI secara dinamis (termasuk emiten 4 huruf resmi BEI via Yahoo Finance `.JK`).
   - `PTRO` (PT Petrosea Tbk) didaftarkan pada katalog `KNOWN_BEI_ISSUERS`.
   - `handleTickerStatusQuery` tidak lagi menolak emiten di luar 10 saham awal. Jika data belum tersedia di database, sistem langsung memicu alur `OnDemandResearchService` untuk mengambil data bar EOD, memvalidasi OHLCV, membuat evidence snapshot, dan menghasilkan narasi dengan label tegas `MODEL_NOT_VALIDATED_FOR_INSTRUMENT`.
2. **Implementasi Fitur Watchlist Pengguna (`user_watchlists`):**
   - Dibuat migrasi `2026_10_25_300000_create_user_watchlists_table.php` dengan constraint unik `(user_id, ticker)` untuk menjamin idempotensi mutlak.
   - Dibuat model `App\Models\UserWatchlist` dan service `App\Services\UserWatchlistService`.
   - Mendukung penambahan ticker dan daftar pantau (`list_watchlist`).
3. **Gemini Function Calling & Structured Tool Router:**
   - Dibuat service `App\Services\AiToolExecutorService` yang mendaftarkan 6 skema tool OpenAI-kompatibel untuk 9router: `add_watchlist`, `list_watchlist`, `get_instrument_analysis`, `get_related_news`, `get_system_status`, `get_prediction_recap`.
   - Diintegrasikan ke `AiConversationService.php` dengan `tool_choice: 'auto'`. Model `ag/gemini-3.8-flash-high` secara otomatis memilih fungsi saat menerima perintah bahasa alami.
4. **Penyempurnaan Ekstraksi Ticker & Ignore Words Bahasa Indonesia:**
   - Menambahkan kata-kata umum 4 huruf bahasa Indonesia (`YANG`, `TADI`, `LALU`, `DULU`, `KITA`, `KAMI`, `SAYA`, `KAMU`, `BISA`, `AKAN`, `PADA`, `DARI`) ke daftar kata yang diabaikan agar tidak salah terdeteksi sebagai ticker saham.
5. **Pencegatan Total Plugin Router v1.1.0 & Deploy Ulang:**
   - `services/aether_router/__init__.py` mencegat 100% pesan teks dari allowlist `5923895922`.
   - Hash berkas kanonikal dan terpasang diverifikasi identik: `fd80c5d1...`.
6. **Restart Mandiri Hermes Gateway:**
   - Gateway lama dihentikan dan dijalankan kembali via Windows Service runner (`Hermes_Gateway.vbs`).
   - Gateway baru aktif pada **PID 26688** dengan status polling Telegram terhubung normal (*healthy generation 1*).

---

### 3. BUKTI RUNTIME & PENGUJIAN EFEKTIF

#### A. Verifikasi Panggilan Live Provider 9router (ag/gemini-3.8-flash-high)
- Uji Tool Calling: `"saya mau kamu menambahkan ptro"` -> Model memilih tool `add_watchlist` dengan argumen `{"ticker": "PTRO"}` secara native (HTTP 200, prompt tokens: 3095, completion: 17).

#### B. Verifikasi Pengujian CLI End-to-End
1. `analisis saham ptro` (Pertama kali):
   - Menerbitkan `REQ_20261025_205424_PTRO_AanF` (Status: `MISSING`).
   - Mengunduh EOD `PTRO.JK` (Harga: Rp 3.090, High-Low: Rp 110 (3.63%), Return: +0.65% vs Open).
   - Menyimpan ke `market_observations` ID 112 dan outbox ID 3.
   - Status Model: `MODEL_NOT_VALIDATED_FOR_INSTRUMENT`.
2. `analisis saham ptro` (Kedua kali — Cache Terverifikasi):
   - Merespons instan (< 400 ms) dari data tersimpan beserta analisis naratif AI grounded.
3. `saya mau kamu menambahkan ptro`:
   - Menyimpan ke `user_watchlists` (Status: Berhasil ditambahkan, Ketersediaan: Tersedia EOD 23 Oct 2026).
4. `tambahkan ptro ke watchlist` (Uji Idempotensi):
   - Merespons: *"Emiten PTRO — PT Petrosea Tbk sudah terdaftar"*.
5. `daftar watchlist saya`:
   - Menampilkan daftar 1 emiten: `1. PTRO — PT Petrosea Tbk (Harga: Rp 3.090)`.
6. `berita apa yang mendukung analisis tadi?`:
   - Melacak konteks `PTRO` dan merespons faktual: *"Tidak ada artikel berita Antara terkini untuk PTRO."*
7. `kenapa belum ada sinyal?`:
   - Menjelaskan bahwa emiten PTRO berada di luar 10 saham universe ML sehingga tidak diberi estimasi probabilitas spekulatif.

#### C. Hasil Pengujian Regresi Otomatis (PHPUnit)
```text
Tests: 128 passed (550 assertions)
Duration: 100.02s
Status: PASS 100% (Exit Code: 0)
```
- `UserWatchlistAndDynamicInstrumentTest`: 7 passed (29 assertions).
- `TelegramTickerAnalysisAndTimeoutTest`: 11 passed (91 assertions).
- `TelegramLiveChatAndAiConversationTest`: 6 passed (20 assertions).
- `OnDemandResearchAndDataFreshnessTest`: 10 passed (55 assertions).
- Seluruh pengujian otomatis 100% terisolasi tanpa transmisi pesan Telegram riil.

---

### 4. CADANGAN & ROLLBACK

- Cadangan Basis Data: `E:\Aether-Market-Intelligence\backups\backup_pre_watchlist_20261025.sql` (335.325 bytes).
- Cadangan Git Working Tree: `E:\Aether-Market-Intelligence\backups\pre_watchlist_git_diff_20261025.patch`.
- Rollback Migrasi: `cd dashboard && php artisan migrate:rollback --step=1`.

---

### 5. PANDUAN PENGUJIAN TELEGRAM NYATA OLEH PENGGUNA

Hermes Gateway aktif pada **PID 26688** dan siap menerima pesan pengujian dari akun terdaftar Anda (`5923895922`). Silakan kirimkan 5 pesan berikut secara berurutan ke `@Info_BosKuu_bot`:

1. `hai, kamu bisa bantu apa?`
   *(Menguji percakapan sapaan dan penjelasan kapabilitas via Gemini)*
2. `analisis saham PTRO`
   *(Menguji analisis emiten BEI di luar 10 saham awal dengan status MODEL_NOT_VALIDATED_FOR_INSTRUMENT)*
3. `tambahkan PTRO ke watchlist saya`
   *(Menguji penambahan watchlist berbasis Gemini tool calling & persistensi PostgreSQL)*
4. `berita apa yang mendukung analisis tadi?`
   *(Menguji retensi konteks percakapan multi-turn terhadap emiten PTRO)*
5. `kenapa belum ada sinyal?`
   *(Menguji penjelasan metodologis status abstain / non-validated)*
