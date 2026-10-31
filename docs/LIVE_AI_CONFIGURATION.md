# KONFIGURASI LIVE AI & TATA KELOLA MODEL 9ROUTER
## AETHER MARKET INTELLIGENCE — TAHAP I LANJUTAN

Dokumen ini mendokumentasikan pemetaan fitur (*feature flags*), arsitektur integrasi 9router lokal, batas kuota akun hulu, tata kelola keamanan prompt, serta mekanisme pemulihan (*kill-switch*).

---

### 1. PEMETAAN FITUR & ISOLASI FLAG

Untuk mencegah efek samping inferensi otomatis massal, sistem menggunakan tiga lapis *feature flags* independen pada `dashboard/.env`:

```ini
# 1. Pemicu Inferensi Interaktif Telegram (On-Demand)
# Diaktifkan khusus untuk menjelaskan emiten atau berita saat pengguna bertanya di chat.
AI_LIVE_INFERENCE_ON_DEMAND_ENABLED=true

# 2. Pemicu Inferensi Otomatis Pipeline EOD (Batch Schedulers)
# Tetap nonaktif agar sinkronisasi harian pukul 16:05 WIB berjalan deterministik tanpa memakan kuota LLM.
AI_LIVE_INFERENCE_EOD_ENABLED=false

# 3. Kunci Global Runtime Operasional
# Dipertahankan false untuk memblokir inferensi tak terduga di luar handler yang diotorisasi.
AI_LIVE_INFERENCE_ENABLED=false
```

---

### 2. ARSITEKTUR KONEKSI 9ROUTER LOKAL

| Parameter | Konfigurasi Operasional | Keterangan |
|---|---|---|
| **Endpoint Base URL** | `http://127.0.0.1:20128/v1` | Reverse proxy lokal 9router yang berjalan sebagai Windows background service. |
| **Model ID** | `ag/gemini-3.8-flash-high` | Model yang telah dikonfigurasi dan diotorisasi pengguna. |
| **Autentikasi** | `AI_API_KEY` (Bearer Token) | Disimpan di `.env` lokal; dilarang keras dicetak dalam log atau chat. |
| **Timeout HTTP** | 18 detik | Jika 9router lambat, sistem otomatis *fallback* ke data deterministik PostgreSQL. |
| **Max Tokens** | 600 tokens | Membatasi panjang respons agar ringkas dan ekonomis. |
| **Temperature** | 0.2 | Suhu rendah untuk konsistensi kuantitatif faktual. |
| **Streaming** | `false` | Memastikan respons diterima utuh sebelum divalidasi ke basis data. |

> **Peringatan Kuota Akun Hulu:**
> Meskipun 9router berjalan pada localhost port 20128, router ini meneruskan permintaan ke endpoint Google Gemini hulu menggunakan akun pengguna yang sudah dikonfigurasi. Setiap inferensi mengonsumsi token akun tersebut. Oleh karena itu, sistem menerapkan **deduplikasi basis data** agar permintaan berulang untuk emiten dan tanggal bar yang sama tidak memicu panggilan AI baru.

---

### 3. TATA KELOLA PROMPT & GROUNDING ANTI-HALUSINASI

Prompt sistem yang dikirimkan ke model mengunci aturan kontraktual:
1. **Dilarang Mengarang Fakta:** Model hanya boleh membaca parameter yang disuntikkan dari baris `EvidenceSnapshot` (Harga OHLC, volume, rentang persentase, tanggal bar, status model).
2. **Perlindungan Status ABSTAIN (NO_SIGNAL):** Jika model ML berstatus abstain karena *confidence score* berada di bawah ambang batas (Tau 0.70 / 40.00%), LLM dilarang keras merekomendasikan beli/jual atau berspekulasi arah harga.
3. **Penanganan Universe Non-Validasi:** Untuk saham di luar 10 konstituen awal, model wajib menyatakan status `MODEL_NOT_VALIDATED_FOR_INSTRUMENT` dan hanya menyajikan analisis deskriptif teknikal berbasis bar harga tersimpan.
4. **Pembersihan Markup:** Dilarang menggunakan karakter asterisk `*` atau garis bawah `_` yang dapat merusak tampilan teks di Telegram.

---

### 4. SIKLUS HIDUP DATA ANALISIS DI POSTGRESQL

Setiap inferensi AI yang berhasil wajib melalui proses penyimpanan terstruktur:
1. Dicatat dalam tabel `analysis_runs` dengan `trigger_type = 'telegram_on_demand'`.
2. Dicatat dalam tabel `market_analyses` dengan:
   - `model_name = 'ag/gemini-3.8-flash-high'`
   - `prompt_version = '1.0.0-on-demand'`
   - `structured_output` berisi token usage, latensi milidetik, referensi evidence snapshot, dan teks narasi.
   - `validation_status = 'valid_schema'`.

---

### 5. PROSEDUR ROLLBACK / EMERGENCY KILL-SWITCH

Jika kuota inferensi menipis atau pengguna ingin mematikan live AI seketika:
1. Buka berkas `E:\Aether-Market-Intelligence\dashboard\.env`.
2. Ubah nilai flag:
   ```ini
   AI_LIVE_INFERENCE_ON_DEMAND_ENABLED=false
   ```
3. Tidak diperlukan migrasi basis data atau *rollback* kode sumber. Bot Telegram akan langsung kembali beroperasi 100% dalam mode deterministik ekstraktif basis data.

---

### 6. GEMINI FUNCTION CALLING & TOOL EXECUTION

Model `ag/gemini-3.8-flash-high` melalui 9router mendukung function calling OpenAI-kompatibel. Sistem mendaftarkan skema tool aplikasi berikut:
- `add_watchlist`: Menambahkan emiten ke watchlist pengguna.
- `list_watchlist`: Menampilkan daftar pantau pengguna.
- `get_instrument_analysis`: Mengambil analisis mendalam (EOD, indikator, status model ML).
- `get_related_news`: Mengambil berita Antara News terverifikasi.
- `get_system_status`: Mengecek status kesehatan PostgreSQL dan pipeline.
- `get_prediction_recap`: Mengambil evaluasi performa 5-hari bursa.
- `consult_portfolio`: Melihat ringkasan analisis portofolio, saldo kas, floating PnL, alokasi 50/50, dan risiko konsentrasi.
- `record_portfolio_position`: Mencatat posisi saham pengguna (ticker, lots, avg_buy_price, allocation_type).
- `screen_stocks`: Screening saham kuantitatif terstruktur (SWING atau DIVIDEND).
- `compare_stocks`: Komparasi multi-emiten BEI (2-4 saham).

Sistem menerapkan protokol 2-turn tool calling: hasil tool dieksekusi secara deterministik di Laravel, lalu diteruskan kembali ke Gemini untuk merangkum sintesis naratif bahasa Indonesia yang grounded dan mudah dipahami, dengan fallback aman ke output teks terstruktur bila sintesis gagal. Setiap pemanggilan fungsi dieksekusi secara aman oleh `AiToolExecutorService` tanpa membuka akses terminal shell atau query SQL mentah kepada model.
