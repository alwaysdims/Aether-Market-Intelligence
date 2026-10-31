# PANDUAN RISET DATA ON-DEMAND & EKSPANSI EMITEN BEI
## AETHER MARKET INTELLIGENCE — TAHAP I LANJUTAN

Dokumen ini mendefinisikan arsitektur, kontrak permintaan terstruktur, alur pengumpulan data, validasi integritas, inferensi grounded AI, dan pengiriman respons Telegram untuk fitur **Riset Data On-Demand Multi-Emiten BEI**.

---

### 1. ARSITEKTUR & PRINSIP UTAMA

Sistem Aether Market Intelligence memperluas cakupan analisis dari 10 ticker awal ke seluruh emiten terdaftar di Bursa Efek Indonesia (BEI/IDX) di bawah batasan operasional:
1. **Anggaran Data Rp0 (Nol Rupiah):** Hanya menggunakan sumber data publik gratis yang diizinkan (Yahoo Finance via yfinance, LKBN ANTARA RSS, GDELT, World Bank).
2. **Tanpa Trading Live & Tanpa Broker Order:** Sistem murni intelijen pasar dan riset kuantitatif. Dilarang keras melakukan order beli/jual riil atau simulasi tanpa audit.
3. **Penyimpanan Data Terlebih Dahulu (Store-First, Analyze-Later):** LLM tidak pernah menganalisis angka yang mengambang di memori percakapan. Seluruh angka wajib masuk validasi skema, dikomparasikan terhadap konflik, disimpan ke PostgreSQL `market_observations`, dan diikat ke `evidence_snapshots` sebelum diproses oleh AI.
4. **Perlindungan Status Model (Anti-Halusinasi):**
   - Emiten di dalam 10 saham universe (BBCA, BBRI, BMRI, BBNI, ASII, TLKM, MAPI, ARTO, MEDC, SMRA): Status `NO_SIGNAL_ABSTAIN` dilindungi ketat; AI dilarang mengubah abstain menjadi sinyal directional.
   - Emiten di luar 10 saham universe (contoh: ICBP, UNVR, GOTO, ADRO, dll.): Diberi label tegas `MODEL_NOT_VALIDATED_FOR_INSTRUMENT`. Dilarang mengarang probabilitas naik/turun atau target harga buatan.
5. **Eksekusi Asinkron Non-Blocking di Telegram:**
   - Permintaan data baru tidak memblokir event loop gateway.
   - Pengguna menerima tanda terima (*acknowledgement*) instan berisi Request ID.
   - Worker memproses unduhan, validasi, dan narasi di latar belakang, lalu mengirimkan hasil final melalui tabel `telegram_outbox`.

---

### 2. KONTRAK PERMINTAAN TERSTRUKTUR (REQUEST CONTRACT)

Setiap kueri bahasa alami dari pengguna dipetakan oleh `BeiUniverseRegistry` menjadi kontrak spesifikasi terstruktur:

| Komponen Kontrak | Nilai / Format | Keterangan Integritas |
|---|---|---|
| **Ticker Symbol** | 4 huruf kapital (e.g. `ICBP`, `UNVR`, `SMRA`) | Normalisasi *case-insensitive*. Ticker asing (`AAPL`, `TSLA`) dan kripto (`BTC`) ditolak dengan pesan edukasi. |
| **Exchange** | `IDX` (Bursa Efek Indonesia) | Basis data terisolasi untuk pasar modal Indonesia. |
| **Analysis Type** | `current_condition`, `news`, `historical`, `prediction` | Dipetakan dari intensi pengguna. |
| **Data Type** | `eod`, `delayed` (15m), `realtime` (pada tier gratis dipetakan ke 15m delayed) | Sesuai batas penyedia publik. |
| **Horizon** | `multi_day`, `5_days`, `intraday` | Horizon evaluasi default 5 hari bursa. |
| **As-Of Time** | Timestamp lokal `Asia/Jakarta` (WIB) | Waktu permintaan diajukan. |
| **Universe Class** | `VALIDATED_UNIVERSE` vs `EXPANDED_BEI_UNIVERSE` | Memisahkan model ML aktif dari analisis deskriptif. |

---

### 3. ALUR PENGUMPULAN & VALIDASI DATA (COLLECTOR PIPELINE)

Jika data emiten yang diminta berstatus `MISSING` atau `STALE`:
1. **Collector Invocation:** Script `services/fetch_ticker_eod.py` mengambil data dari Yahoo Finance untuk simbol `{TICKER}.JK`.
2. **Penyimpanan Raw Immutable:**
   - Disimpan ke `data/raw_immutable/{YYYYMMDD}/{TICKER}_{HHMMSS}.json`.
   - Dihitung *cryptographic hash* SHA-256 untuk menjamin auditabilitas tak terbantahkan.
3. **Validasi Kewajaran OHLCV (Zero Garbage In):**
   - Harga penutupan, pembukaan, tertinggi, terendah wajib `> 0`.
   - Volume perdagangan wajib `>= 0`.
   - `High >= max(Open, Close)` dan `Low <= min(Open, Close)`.
   - `bar_date <= tanggal bursa hari ini`.
4. **Pemeriksaan Konflik Data (Conflict Quarantine):**
   - Jika terdapat bar untuk tanggal yang sama dari sumber berbeda dengan selisih harga penutupan `> 0.5%`:
   - Data **TIDAK DIRATA-RATAKAN**.
   - Data diberi status `CONFLICTED` dan dikarantina ke direktori `data/quarantine/`.
5. **Komit Database:**
   - Data yang lolos validasi disimpan ke tabel PostgreSQL `market_observations` dengan metadata stempel waktu (`published_at`, `first_seen_at`, `fetched_at`, `available_at`) dan `data_hash`.
6. **Pembuatan Evidence Snapshot:**
   - Disimpan ke tabel `evidence_snapshots` dengan ID `EVD_{YYYYMMDD}_{TICKER}_{HASH8}`.

---

### 4. INFERENSI GROUNDED LIVE AI (9ROUTER)

Setelah evidence tersimpan di basis data:
1. Service membaca murni baris `EvidenceSnapshot` dan `MarketObservation`.
2. Sistem prompt mengunci aturan:
   - Dilarang mengarang angka di luar evidence snapshot.
   - Dilarang mengubah status abstain model ML.
   - Format wajib *plain text* tanpa karakter asteris `*` atau garis bawah `_`.
   - Struktur wajib: (1) Fakta Pasar Terlapor, (2) Interpretasi Kuantitatif & Status Model, (3) Skenario Risiko.
3. Endpoint: `http://127.0.0.1:20128/v1/chat/completions` menggunakan model `ag/gemini-3.8-flash-high`.
4. Hasil disimpan ke tabel `analysis_runs` dan `market_analyses`.
5. **Deduplikasi Caching:** Permintaan berikutnya untuk emiten dan tanggal bar yang sama disajikan langsung dari basis data dalam waktu 0 ms (0 token AI tambahan).

---

### 5. MANAJEMEN ANTRIAN TELEGRAM & SCHEDULER

- Perintah CLI untuk memproses antrian riset:
  ```bash
  php artisan aether:execute-research <REQUEST_ID>
  php artisan aether:process-research-queue --limit=5
  ```
- Scheduler Laravel (`routes/console.php`):
  Menjalankan `aether:process-research-queue` setiap 1 menit secara otomatis tanpa tumpang tindih (`withoutOverlapping`).
- Outbox Dispatcher (`hermes:process-outbox --live`):
  Mengirimkan balasan final ke pengguna dan menautkannya ke pesan awal pengguna (`reply_to_message_id`).
