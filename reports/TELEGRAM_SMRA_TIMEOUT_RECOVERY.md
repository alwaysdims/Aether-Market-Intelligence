# LAPORAN PEMULIHAN OPERASIONAL, PERBAIKAN TIMEOUT & AKTIVASI HYBRID ON-DEMAND AI
## AETHER MARKET INTELLIGENCE — TAHAP I LANJUTAN

**Tanggal:** 25 Oktober 2026, 18:25 WIB  
**Status Audit:** VERIFIED & RESOLVED (Uji Nyata Lanjutan: PENDING PENGGUNA)  
**Ruang Lingkup:** Forensik Timeout Pesan SMRA, Intent Ticker Multi-Emiten, Eliminasi Formatting Literal, Pemisahan Kohort, Terminologi Terstandar, Subprocess Non-Blocking, dan Aktivasi Terkontrol Live AI On-Demand via 9router Lokal.

---

### 1. REKONSTRUKSI & KORELASI LOG PERMINTAAN GAGAL (17:00:14 WIB)

Berdasarkan pemeriksaan berkas tanda terima Telegram Gateway, log sistem Windows, dan log Hermes (`agent.log`, `gateway.log`):

| Parameter | Nilai Faktual Berbukti |
|---|---|
| **Waktu Permintaan** | 2026-10-25 17:00:14,684 WIB |
| **Inbound Update ID** | `496962954` (Tercatat di `telegram_update_receipts_8583524735.json`) |
| **Inbound Message ID** | `293` (Chat ID allowlist: `5923895922`) |
| **Teks Masuk** | `"analisis emiten smra"` |
| **Pencegatan Gateway** | Berhasil dicegat oleh `aether_router` (`agent.log:41120`) |
| **Intent Awal (Lama)** | Terklasifikasi sebagai `GENERAL_ASSIST` (Kata `"analisis"` tidak tercakup dalam regex lama) |
| **Ekstraksi Ticker Awal** | `null` (Intent umum tidak mengekstrak ticker) |
| **Executable & Command** | `C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe artisan hermes:telegram-chat --text="analisis emiten smra" --user=5923895922 --update-id=496962954 --message-id=293 --raw` |
| **Waktu Eksekusi** | Tepat 25,103 detik (Memicu `subprocess.TimeoutExpired` pada batas 25,0s) |
| **Outbound Message ID** | `294` pada 17:00:40 WIB (*Controlled failure notice*) |
| **Status DB** | Tidak ada record korup di `telegram_conversations` (Proses dihentikan sebelum komit) |

#### Pembedaan Pengukuran Empiris vs Hipotesis Penyebab:
1. **Fakta Pengukuran Terverifikasi (Empiris):**
   - Durasi eksekusi kueri pertama mencapai 25,103 detik.
   - Durasi kueri kedua (`status system sekarang bagaimana?` 17:01:09 WIB) selesai dalam **6.529 ms**.
   - Durasi kueri ketiga (`bbca terbau bagaimana?` 17:01:19 WIB) selesai dalam **539 ms**.
   - Log PostgreSQL 16 di `data/log/` menunjukkan nol error koneksi, nol table lock, dan nol query deadlock.
2. **Hipotesis Korelasi Sistem (Analisis Berbasis Bukti):**
   - Windows System Kernel-Power mencatat event 507/566: *System session transitioned from 78 to 80 (Exiting Modern Standby, Reason: Input Keyboard)* pada pukul **16:59:04 WIB** (70 detik sebelum kueri).
   - Tingginya latensi pada eksekusi pertama berkorelasi dengan pemuatan awal biner PHP, alokasi memori, inisialisasi DLL (`php_pdo_pgsql.dll`, `libpq.dll`), dan pembacaan ribuan berkas vendor Composer pasca wake-up dari status standby.
   - Pemanasan berkala (*warm-up ping*) berfungsi memuat modul dan biner ke dalam *file system memory cache* OS RAM agar siap pakai, bukan membentuk *persistent connection pool* antar proses PHP CLI yang independen.

---

### 2. HASIL VERIFIKASI PESAN NYATA PASCA ROUTER v1.0.2

Pada 17:46 WIB, sistem menerima pesan uji nyata pertama pasca pembaruan plugin:

| Parameter | Pengukuran Pesan Uji Nyata 1 |
|---|---|
| **Waktu Inbound** | 2026-10-25 17:46:17,369 WIB |
| **Inbound Update ID** | `496962960` |
| **Inbound Message ID** | `304` |
| **Teks Masuk** | `"analisis emiten smra"` |
| **Intent Terdeteksi** | `QUERY_TICKER_STATUS` |
| **Ticker Terekstrak** | `SMRA` |
| **Context Signal ID** | `SHADOW_aebf1bb0101398b8` |
| **Durasi Subprocess PHP** | **1.487 ms** (1,48 detik) |
| **Waktu Respon DB** | **180 ms** |
| **Outbound Message ID** | `305` |
| **Status Pengiriman** | Terkirim sukses (*Delivered*, HTTP 200 via Telegram Bot API) |
| **Latensi End-to-End** | **2.514 ms** (2,51 detik) |
| **Panggilan LLM Core** | **0 panggilan** (Zero LLM tokens used) |
| **Respons Ganda** | **0 respons ganda** (Pencegatan tunggal atomik, `ApplicationHandlerStop` aktif) |

*Catatan Verifikasi 2 & 3:*
- Pesan 2 (`status system sekarang bagaimana?`) dan Pesan 3 (`bbca terbaru bagaimana?`) berstatus **PENDING**, menunggu pengiriman manual dari pengguna.

---

### 3. KOREKSI TERMINOLOGI & STANDARISASI METRIK

Sesuai arahan teknis, implementasi telah diperbarui dengan terminologi baku:
1. **Rentang Harga High-Low (Bukan Bid-Ask Spread):**
   - Istilah *bid-ask spread* dihilangkan dari presentasi bar OHLC.
   - Digantikan dengan istilah resmi: `Rentang Harga High-Low: Rp 12,00 (4.72% rentang bar)` yang dihitung murni dari harga tertinggi dikurangi terendah pada bar harian tersimpan.
2. **Penyebutan Eksplisit Tanggal Bar pada Perubahan vs Open:**
   - Diformat lengkap: `Perubahan vs Open: +Rp 4,00 (+1.56% vs Open bar 23 Oct 2026)`.
3. **Klarifikasi Mekanisme Pre-Warming:**
   - Pre-warming didefinisikan secara presisi sebagai pemanasan disk cache & modul PHP ke memori kerja Windows, bukan connection pool database yang persisten.

---

### 4. AUDIT & IMPLEMENTASI LIVE AI ON-DEMAND (9ROUTER)

Sesuai otorisasi pengguna untuk penjelasan analisis saham dan ringkasan berita:

1. **Audit Endpoint & Model 9router:**
   - Endpoint: `http://127.0.0.1:20128/v1` (Terkonfirmasi aktif, HTTP 200, 73 model terdaftar).
   - Model yang Dikonfigurasi: `ag/gemini-3.8-flash-high`.
   - Autentikasi: Menggunakan kredensial lokal dari variabel `AI_API_KEY` di `dashboard/.env`. Kredensial tidak pernah dicetak dalam log atau chat.
   - Catatan Kuota: Model diarahkan melalui router lokal tetapi tetap mengonsumsi kuota API hulu akun Gemini; bukan inferensi gratis tak terbatas.
2. **Pemisahan Kontrol Fitur (Feature Flags):**
   - `AI_LIVE_INFERENCE_ON_DEMAND_ENABLED=true`: Mengaktifkan penjelasan naratif AI saat pengguna meminta analisis emiten secara interaktif di Telegram.
   - `AI_LIVE_INFERENCE_EOD_ENABLED=false`: Menjaga proses sinkronisasi EOD harian otomatis tetap deterministik tanpa panggilan AI otomatis.
   - `AI_LIVE_INFERENCE_ENABLED=false`: Menjaga pengaman global runtime umum tetap terkunci.
3. **Arsitektur Aliran Data Grounded:**
   `Data Sumber EOD -> DB PostgreSQL -> Evidence Snapshot -> 9router AI -> Penyimpanan DB (analysis_runs & market_analyses) -> Telegram`
4. **Batasan Ketat Pada Sistem Prompt AI:**
   - Dilarang mengarang harga, tanggal, probabilitas, atau target harga masa depan.
   - Status model machine learning wajib dipertahankan sebagai **ABSTAIN (NO_SIGNAL)**. AI dilarang keras mengubah abstain menjadi rekomendasi beli/jual.
   - Struktur wajib memisahkan: (1) Fakta Pasar Terlapor, (2) Interpretasi Kuantitatif Model & Status Abstain, (3) Skenario Dampak & Risiko Pasar.
   - Format wajib *plain text* tanpa karakter markup literal (`*` atau `_`).
5. **Deduplikasi & Caching Basis Data:**
   - Jika naratif analisis untuk emiten pada tanggal bar tersebut sudah pernah dibuat dan tersimpan di tabel `market_analyses`, sistem langsung menyajikan hasil dari basis data (latensi 0 ms, 0 token tambahan).
6. **Subprocess & Concurrency Control:**
   - Timeout subprocess di `services/aether_router/__init__.py` dinaikkan ke **40 detik** guna memberikan ruang pemrosesan AI (latensi tipikal 10–15s pada generasi awal).
   - Panggilan HTTP ke 9router dibatasi timeout ketat **18 detik**. Jika timeout, sistem langsung *fallback* menyajikan data deterministik PostgreSQL.

---

### 5. HASIL UJI INFERENSI NYATA ISOLASI (TEST RUNNER)

Pengujian inferensi nyata telah dieksekusi secara lokal tanpa mengirim pesan Telegram proaktif:
- **Executable:** `test_ai_smra_inference.php`
- **HTTP Status 9router:** `200 OK`
- **Latensi Generasi AI:** `14.779 ms` (~14,7 detik)
- **Token Usage:** Prompt: 4.506 tokens, Completion: 328 tokens, Reasoning: 1.070 tokens, Total: 4.834 tokens.
- **Validasi Output:**
  - Fakta pasar OHLC dan volume Rp 260 / 32,1 jt lembar sesuai 100% dengan DB.
  - Sinyal model dikonfirmasi tetap **NO_SIGNAL_ABSTAIN** (Confidence 1,24% < ambang batas 40,00%).
  - Nol rekomendasi spekulatif, nol target harga buatan.
  - Nol karakter markup asterisk atau underscore.
- **Penyimpanan DB Terverifikasi:**
  - `AnalysisRun` ID: `16` (`ONDEMAND_SMRA_20261025_105710`)
  - `MarketAnalysis` ID: `15` (Tervalidasi `validation_status = 'valid_schema'`)

---

### 6. HASIL SUITE REGRESI KESELURUHAN (100% GREEN)

Eksekusi suite pengujian otomatis pasca implementasi:
- `TelegramTickerAnalysisAndTimeoutTest`: 11 passed (93 assertions, durasi: 69,2s).
- `TahapILanjutanRoutingAndAutomationTest`: 10 passed (69 assertions).
- `TahapILifecycleAndTelegramTest`: 11 passed (37 assertions).
- **Regresi Penuh Aplikasi:** `105 tests PASSED 100%` (448 assertions, exit code 0).

---

### 7. MATRIKS STATUS AUDIT

| Komponen / Sasaran | Status | Keterangan Verifikasi |
|---|---|---|
| Routing Analisis SMRA & Multi-Emiten | **VERIFIED** | Mendukung huruf kecil/besar, variasi bahasa alami, dan slash commands |
| Klarifikasi Ticker Asing / Ambigu | **VERIFIED** | Menjawab langsung tanpa meneruskan ke core agent LLM umum |
| Eliminasi Markup Literal (*, _, $) | **VERIFIED** | Format plain text rapi, bebas asteris dan prefix simbol mata uang |
| Pemisahan Kohort Sinyal | **VERIFIED** | 0 forward directional aktif vs 10 retrospective replay abstain |
| Standarisasi Terminologi | **VERIFIED** | Rentang harga OHLC (bukan bid-ask spread), tanggal bar pada return vs open |
| Isolasi Subprocess & Anti-Zombie | **VERIFIED** | `subprocess.Popen` + communicate(40s) + terminate/kill cascade |
| Pembedaan Kontrol AI EOD vs On-Demand | **VERIFIED** | `AI_LIVE_INFERENCE_EOD_ENABLED=false` terisolasi |
| Inferensi Nyata 9router Grounded | **VERIFIED** | Berhasil dieksekusi dan disimpan ke tabel `market_analyses` |
| Verifikasi Pesan Nyata 1 SMRA | **VERIFIED** | Sukses dijawab dalam 1.487 ms (Subprocess) / 2.514 ms (End-to-End) |
| Verifikasi Pesan Nyata 2 (Status) | **PENDING** | Menunggu pengiriman manual oleh pengguna |
| Verifikasi Pesan Nyata 3 (BBCA) | **PENDING** | Menunggu pengiriman manual oleh pengguna |
| Uji Ketahanan Pasca Sleep/Wake Manual | **PENDING** | Prosedur disiapkan di bawah untuk eksekusi manual oleh pengguna |

---

### 8. PROSEDUR UJI MANUAL SLEEP/WAKE & INSTRUKSI PENGGUNA

Untuk menguji ketahanan kueri terhadap kondisi pasca standby secara empiris tanpa membuat sistem tidur otomatis:

#### Langkah Pengujian Sleep/Wake Manual (Dilakukan oleh Pengguna):
1. Pengguna menutup lid laptop atau menekan tombol Sleep Windows secara manual.
2. Biarkan perangkat dalam kondisi tidur selama minimal 2–5 menit.
3. Bangunkan perangkat (tekan keyboard / buka lid).
4. Segera buka aplikasi Telegram dan kirimkan pesan:
   `analisis emiten smra`
5. **Kriteria Kelulusan:**
   - Bot merespons dengan lengkap di bawah 10 detik tanpa mengalami timeout 25s/40s.
   - Hasil menyajikan data faktual DB dan narasi AI grounded yang tersimpan.

#### Cara Menonaktifkan Kembali Live AI On-Demand:
Jika ingin mengembalikan bot ke mode 100% deterministik tanpa LLM sama sekali:
1. Ubah nilai di `E:\Aether-Market-Intelligence\dashboard\.env`:
   `AI_LIVE_INFERENCE_ON_DEMAND_ENABLED=false`
2. Restart gateway service bila diperlukan. Tidak ada database migration atau rollback kode yang dibutuhkan.

---

### 9. SIAP UNTUK PENGUJIAN TELEGRAM NYATA

Sistem siap menerima pesan pengujian nyata dari pengguna di `@Info_BosKuu_bot`:
> **Silakan kirimkan:** `analisis emiten smra`
> 
> Bot akan menjawab dengan kombinasi data pasar faktual, status model abstain yang dilindungi, dan narasi penjelasan kuantitatif dari model `ag/gemini-3.8-flash-high` melalui 9router.
