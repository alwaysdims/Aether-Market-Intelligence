# LAPORAN PENERIMAAN INTEGRASI HERMES SEBAGAI WORKER PENALARAN
**Aether Market Intelligence — Consultation Orchestrator Migration**  
**Tanggal Evaluasi**: 31 Oktober 2026  
**Status**: VERIFIED & READY FOR CANARY ACTIVATION  
**Orchestrator**: `HERMES_AGENT (ag/gemini-3.8-flash-high)`

---

## 1. DESAIN ARSITEKTUR & PEMISAHAN PERAN

Sesuai ketetapan arsitektur sistem:
* **Laravel Framework 13**: Tetap menjadi **pusat kendali operasional tunggal** (penanganan inbound webhook/router, penjadwalan durable jobs `scheduled_telegram_jobs`, transaksional outbox `telegram_outboxes`, penyimpanan bukti `evidence_snapshots`, pencatatan histori `analysis_runs`, dan pengiriman ke gateway).
* **Hermes Agent (via profile `aether-prod`)**: Berperan sebagai **agent penalaran (reasoning) dan sintesis analitis**.
* **Model LLM**: Tetap menggunakan konfigurasi resmi `ag/gemini-3.8-flash-high` melalui proxy lokal 9router.
* **Prinsip Kepemilikan Tunggal (Single Reasoning Owner)**: Dalam setiap job konsultasi, hanya satu orchestrator yang berjalan. Jika mode Hermes aktif, Laravel tidak lagi memanggil Gemini secara paralel atau duplikatif.
* **Tanpa n8n**: Seluruh alur berjalan di atas stack terverifikasi Hermes + Laravel + PostgreSQL + Windows Task Scheduler.

---

## 2. MEKANISME INTEGRASI HERMES YANG DIGUNAKAN

### 2.1. Profil Produksi Khusus & Terisolasi (`aether-prod`)
* Dibuat menggunakan perintah CLI resmi: `hermes profile create aether-prod`.
* Direktori home profil: `C:\Users\ASUS\AppData\Local\hermes\profiles\aether-prod`.
* Konfigurasi profil (`config.yaml`):
  - Model: `ag/gemini-3.8-flash-high` (custom provider via 9router).
  - Batasan iterasi: `agent.max_turns: 8`.
  - Platform toolsets internal: `platform_toolsets.cli: []` (seluruh tool built-in seperti shell, terminal bebas, browser, file modification, dan web scraping dinonaktifkan dari konteks model).
  - Personality (`SOUL.md`): Diselaraskan menjadi konsultan pasar modal Aether yang berbasis bukti dan patuh pada etika keuangan pasar modal.

### 2.2. Pemanggilan Subproses Resmi & Bounded
* Menggunakan runtime CLI resmi: `hermes -p aether-prod chat --query-file <path> --oneshot -Q --run-budget 60 -c <session> --create-if-missing`.
* **Parameter Keamanan**:
  - `--oneshot` & `-Q`: Mode senyap dan non-interaktif tanpa banner/spinner, mengembalikan teks jawaban murni ke stdout.
  - `--run-budget 60`: Batas waktu proses keras 60 detik.
  - `-c aether_user_{user_id} --create-if-missing`: Isolasi konteks percakapan per user Telegram dengan riwayat interaksi multi-turn.
* **Atomic Session Lock**: Mencegah race condition antar-pekerjaan untuk user yang sama dengan `Cache::lock("hermes:session_lock:aether_user_{user_id}", 90)->block(15)`.

---

## 3. PENYATUAN JALUR TOOLS & TATA KELOLA DATA

1. **Jalur Terkendali Aether Engine**: Hermes tidak diberikan akses langsung ke API eksternal tanpa pengawasan. Seluruh data pasar diinjeksi melalui briefing terstruktur atau tool executor resmi (`AiToolExecutorService`):
   - Ringkasan broker Pasar Reguler (`get_broker_summary`) via IDX Edge PRO dengan atomic lock coalescing.
   - Laporan keuangan emiten (`get_company_fundamentals`) dengan normalisasi mata uang (IDR vs USD).
   - Screening akumulasi broker (`BrokerAccumulationScreeningService`) berbasis kontrak `BROKER_ACCUMULATION_SCREENING_V1`.
2. **Karantina Quote Intraday**: Quote `/api/price` tetap berstatus `QUARANTINED` / `CONFLICTED` akibat kontaminasi transaksi crossing Pasar Negosiasi (NG). Hermes dilarang menggunakan endpoint harga intraday liar.
3. **Pemberantasan Fallback Yahoo**: Seluruh jalur operasional murni mengandalkan canonical DB dan IDX Edge PRO.
4. **Ringkasan Kompak (Context Efficiency)**: Data yang disajikan ke Hermes diringkas dalam bentuk metrik utama (Gross Buy Avg, Gross Sell Avg, Rasio Net/Vol, Top-3 Net Buy, Konsentrasi, Keberlanjutan Sesi Terakhir) untuk menghemat token dan menjaga ketajaman penalaran.

---

## 4. KONTRAK HASIL, AUDIT, & PENANGANAN KEGAGALAN

* **Pencatatan Bukti Sebelum Publikasi**: Setiap hasil penalaran Hermes dicatat sebagai rekod `evidence_snapshots` (data hash SHA-256) dan `analysis_runs` sebelum didaftarkan ke `telegram_outboxes`.
* **Status Pekerjaan Terpisah**:
  - Hasil agent: `COMPLETE` (penalaran berhasil diselesaikan).
  - Status Job Operasional (`ScheduledTelegramJob`): Tetap `PROCESSING` sampai pesan masuk ke outbox, dan baru menjadi `COMPLETED` setelah ada receipt Telegram numerik dari gateway.
* **Fail-Safe & Notifikasi Kegagalan**: Jika subproses Hermes gagal atau timeout, sistem menangkap error secara transparan dan mendaftarkan pesan kegagalan spesifik ke outbox pengguna. Sistem tidak akan mengalihkan ke jawaban template yang tidak relevan.

---

## 5. FEATURE FLAG & PROSEDUR ROLLBACK

Orchestrator penalaran dikendalikan melalui konfigurasi environment:
```env
# Konfigurasi Orchestrator Hermes
HERMES_CLI_COMMAND=hermes
HERMES_ORCHESTRATOR_ENABLED=true
HERMES_PROFILE=aether-prod
HERMES_RUN_BUDGET_SECONDS=60
```
* **Nilai Efektif Runtime Saat Ini**:
  - `config('services.hermes.orchestrator_enabled')`: **`TRUE`** (Aktif di runtime).
  - `env('HERMES_ORCHESTRATOR_ENABLED')`: **`TRUE`**.
  - Nilai pada template dokumentasi (`.env.example`): `false` (default aman untuk deployment baru).
* **Profil Eksekusi**: `aether-prod` (seluruh platform tools berbahaya seperti shell, terminal, file, dan web dinonaktifkan).
* **Status Kesiapan**: **`READY_FOR_LIVE_TEST`**.
* **Prosedur Rollback Instan**: Cukup ubah `HERMES_ORCHESTRATOR_ENABLED=false` pada `.env` dan jalankan `php artisan config:clear`. Pekerjaan konsultasi berikutnya otomatis kembali ke jalur `AiConversationService` tanpa downtime.

---

## 6. BUKTI CANARY INTERNAL TERVALIDASI (WITHOUT TELEGRAM DELIVERY)

Uji canary internal dieksekusi tanpa pengiriman ke jaringan Telegram:
* **Pertanyaan Canary**: `"siapa saja yang mengakumulasi saham SMRA selama 3 bulan terakhir?"`
* **Orchestrator Terpilih**: `HERMES_AGENT` via subproses CLI resmi (`hermes -p aether-prod`).
* **Tool Aether yang Terpanggil**: `get_broker_summary` (Ticker: `SMRA`, durasi 3 bulan dinamis).
* **Pencatatan Audit Provenance**:
  - `EvidenceSnapshot ID`: **`233`** (`snapshot_id`: `EV_HERMES_20261031_085612_9999`, data hash SHA-256 tersimpan).
  - `AnalysisRun ID`: **`1324`** (`run_id`: `HERMES_RUN_20261031_085612_9999`, status: `COMPLETED`).
  - `Agent Status`: **`PARTIAL`** (Tervalidasi sesuai kontrak karena memuat batasan sampel Top-N broker teramati).
  - `Telegram Delivery`: **NIL / BELUM DIKIRIM** (Murni pengujian internal; tidak diklaim sebagai live test).

---

## 7. HASIL PENGUJIAN REGRESI LENGKAP

* `HermesReasoningWorkerTest`: 3 tests, 6 assertions passed (621 ms).
* `BrokerAccumulationScreeningTest`: 6 tests, 22 assertions passed (740 ms).
* `HistoricalBrokerConsultationRecoveryTest`: 5 tests, 25 assertions passed.
* `OperationalGapsClosureTest`: 6 tests, 32 assertions passed.
* `BrokerSummaryAndFundamentalIngestionTest`: 4 tests, 43 assertions passed.
* `DataSourceRegistryTest`, `IdxEdgeClientTest`, `IdxEdgeProviderPriorityTest`, `RealtimeDashboardApiTest`, `DashboardTest`: 35 tests, 179 assertions passed.
* **Total Keseluruhan Suite**: **59 feature tests, 307 assertions passed (100% lulus)** dalam 30.73 detik. Zero secret leaks.

---

## 7. STATUS PEMBUKTIAN LIVE / AUTOMATISASI

* **Pengujian Unit & Feature**: `VERIFIED` (Semua alur feature flag, session lock, dan contextual injection terverifikasi).
* **Live Inbound Asli Baru**: Ditandai `LIVE_PENDING`. Menunggu pesan inbound Telegram asli berikutnya dari pengguna setelah runtime diaktifkan di production. Tidak menggunakan pesan sintetis untuk mengklaim otomatisasi live.
