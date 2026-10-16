# PRODUCT REQUIREMENTS DOCUMENT (PRD)
## HERMES MARKET INTELLIGENCE

**Versi Dokumen:** 1.0.0  
**Status:** Aktif / Fase Pengembangan 2  
**Tanggal:** 16 Oktober 2026  
**Target Lingkungan:** On-Premise Localhost (Windows 11)  

---

## 1. IDENTITAS PROYEK

- **Nama Proyek:** Hermes Market Intelligence
- **Jenis Sistem:** Platform intelijen pasar modal berbasis AI terpadu (Control Plane Web, Mesin Kuantitatif Python, dan Transmisi Notifikasi Telegram).
- **Tujuan Utama:** Mengumpulkan data pasar resmi BEI (Bursa Efek Indonesia), memproses broker summary & indikator teknikal non-lookahead, mengagregasi berita pasar modal, menganalisis dampak informasi menggunakan model LLM lokal berbobot tinggi, menyimpan histori secara terstruktur di PostgreSQL, serta mendistribusikan ringkasan tervalidasi ke dashboard lokal dan bot Telegram.
- **Status Pengembangan:** Fondasi Laravel 13, database relasional PostgreSQL 16, dan view Blade telah selesai diuji (Fase 1). Integrasi adapter mesin analitik Python `E:\hermes-stock-agent` dan pipeline berita sedang dibangun (Fase 2).

---

## 2. LATAR BELAKANG DAN MASALAH

Pasar modal Indonesia (BEI) memiliki karakteristik unik dengan fraksi harga berjenjang, batasan auto-rejection asimetris/simetris, serta dominasi transaksi institusi/broker yang terekam pada ringkasan broker harian (Broker Summary). Investor dan analis independen sering menghadapi tantangan:
1. **Fragmentasi Data:** Data harga (OHLCV), broker summary, dan berita emiten tersebar di berbagai platform tanpa satu repositori historis lokal yang terpusat.
2. **Beban Analisis Manual:** Menyusun rekapitulasi data penutupan pasar (EOD), menyaring anomali volume (RVOL), dan memeriksa berita emiten membutuhkan waktu signifikan setiap sore hari.
3. **Ketiadaan Audit Trail:** Keputusan analisis pasar harian sering tidak terdokumentasi, menyulitkan evaluasi post-mortem mengenai apakah kesimpulan analitik masa lalu terbukti akurat atau bias.
4. **Desinkronisasi Fakta vs Opini:** Berita pasar modal di internet kerap mencampuradukkan rilis resmi keterbukaan informasi emiten dengan rumor spekulatif.
5. **Ketiadaan Pemisahan Risiko:** Strategi kuantitatif sering kali langsung dihubungkan ke eksekusi modal tanpa melalui tahap validasi walk-forward dan forward paper trading yang ketat.

*Catatan Integritas:* Sistem ini dirancang sebagai instrumen riset dan intelijen objektif, bukan skema penasihat investasi otomatis. Sistem **tidak menjanjikan keuntungan finansial atau kepastian arah pasar**, melainkan menyajikan probabilitas berbasis data dan disiplin manajemen risiko.

---

## 3. TUJUAN DAN INDIKATOR KEBERHASILAN

### A. Kriteria Teknis (Engineering Bar)
- **K1:** Seluruh komponen inti (PostgreSQL, Router AI Lokal, Adapter Hermes, Dashboard Laravel) berjalan stabil pada localhost Windows 11.
- **K2:** Latensi health-check internal database < 15 ms, dan AI Router probe < 500 ms.
- **K3:** Job scheduler Laravel dan cron eksekusi tercatat di database dengan status (`completed`, `failed`), waktu mulai, durasi presisi milidetik, dan pesan error tersanitasi.
- **K4:** Transmisi pesan Telegram terbukti idempotent: tidak ada pengiriman sinyal atau ringkasan ganda untuk run ID dan hash konten yang sama.
- **K5:** Isolasi proses terjamin: kegagalan salah satu konektor data eksternal tidak merusak atau menghentikan keseluruhan pipeline sistem.

### B. Kriteria Kualitas Analisis (Intelligence Bar)
- **K6:** Pemisahan tegas antara fakta sumber resmi (keterbukaan IDX/laporan keuangan) dengan estimasi/opini pasar.
- **K7:** Analisis inferensi AI (`ag/gemini-3.8-flash-high`) selalu menghasilkan format JSON terstruktur yang lolos validasi skema sebelum disajikan atau disimpan.
- **K8:** Seluruh analisis memiliki referensi silang terhadap data harga EOD resmi dan timestamp yang dapat diaudit.

### C. Kriteria Performa Riset & Trading (Research Bar)
- **K9:** Forward paper trading beroperasi dengan pembatasan modal simulasi eksak (Rp500.000,00), fraksi lot bulat (100 lembar), slippage minimal 1 tick BEI, serta komisi beli 0,15% dan komisi jual 0,25%.
- **K10:** Sinyal yang terbentuk di luar jendela bursa resmi atau memiliki candle belum final ditolak secara deterministik (`INVALID_CANDLE_NOT_FINAL` atau `EXPIRED_STALE_WINDOW`).

---

## 4. PENGGUNA DAN USE CASES

Pengguna utama sistem adalah Pengembang/Operator Kuantitatif Tunggal yang mengoperasikan sistem di workstation lokal.

| Use Case ID | Aktor | Judul Use Case | Deskripsi Alur |
|---|---|---|---|
| **UC-01** | Operator | Memantau Status Eksekutif | Membuka `/` dashboard untuk melihat metrik operasional harian, status koneksi komponen, dan ringkasan rezim pasar terkini. |
| **UC-02** | Operator | Monitoring Data Pasar Saham | Membuka `/market` untuk memeriksa tabel harga EOD resmi, volume, ATR14, dan status data 10 saham likuid BEI. |
| **UC-03** | Operator | Memantau Berita & Fakta Emiten | Membuka `/news` untuk meninjau agregasi peristiwa pasar dengan label verifikasi (`official_statement`, `verified_fact`, `unverified`). |
| **UC-04** | Operator / Scheduler | Menjalankan Pipeline Analisis EOD | Scheduler mengeksekusi pipeline pada penutupan bursa (16:05 WIB), memanggil Hermes Adapter, inferensi Gemini AI, mencatat run, dan memperbarui database. |
| **UC-05** | Operator | Meninjau Histori Inferensi AI | Membuka `/history` untuk memeriksa prompt template version, respon JSON inferensi, dan skor keyakinan model. |
| **UC-06** | Operator | Manajemen Prompt Template | Membuka `/settings` untuk meninjau prompt sistem dan instruksi AI yang sedang aktif tanpa hard-coding kode. |
| **UC-07** | Operator | Audit Diagnostik & Health Check | Membuka `/health` untuk memeriksa latensi PostgreSQL, ketersediaan port 20128 9router, dan jalur folder Hermes. |
| **UC-08** | Operator | Evaluasi Pasca-Horizon Pasar | Membandingkan kesimpulan analisis dampak tempo hari dengan pergerakan harga aktual setelah 3-5 hari bursa. |

---

## 5. RUANG LINGKUP SISTEM

### A. Di Dalam Ruang Lingkup (In-Scope)
1. **Control Plane Web Lokal:** Dashboard Laravel 13 responsif (Blade + Tailwind + Alpine.js).
2. **Konektivitas Mesin Python:** Adapter terisolasi (`E:\Project-bot\services\hermes_adapter.py`) yang menjembatani Laravel dengan `E:\hermes-stock-agent`.
3. **Penyimpanan Relasional:** PostgreSQL 16 untuk snapshot pasar, histori inferensi, berita, prompt template berversi, dan log notifikasi.
4. **Agregasi Data Resmi:** Konsumsi data harga harian EOD Yahoo Finance dan broker summary pasca-tutup bursa Index Alpha.
5. **Feed Berita Berizin/Publik:** Pengambilan berita emiten melalui RSS feed publik terverifikasi (keterbukaan IDX dan media keuangan resmi).
6. **AI Market Reasoning:** Ekstraksi kesimpulan berbasis prompt engineering terstruktur via model `ag/gemini-3.8-flash-high`.
7. **Simulasi Forward Paper Trading:** Pelacakan portofolio hipotetis terkontrol (EXP-01 vs EXP-04) dengan akuntansi MTM kas riil.
8. **Transmisi Telegram Otomatis:** Pengiriman sinyal riset dan rekapitulasi sesi ke operator melalui bot `@Info_BosKuu_bot`.

### B. Di Luar Ruang Lingkup (Out-of-Scope)
1. **Live Broker Trading:** Dilarang keras mengirim order riil, menghubungkan Open API sekuritas live, atau melakukan penempatan dana nyata.
2. **Klaim Prediksi Real-Time Intraday:** Provider data eksternal berstatus EOD atau delayed 10-15 menit, sehingga sistem tidak melayani scalping intraday instan.
3. **Fine-Tuning Bobot LLM Otomatis:** Bobot model Gemini 3.8 Flash High tidak di-fine-tune lokal; adaptasi dilakukan melalui few-shot prompt context dan schema output strict.
4. **Multi-Tenant User Management Publik:** Sistem ditujukan untuk single-operator lokal, tanpa registrasi pengguna publik berbasis SaaS.

---

## 6. KEBUTUHAN FUNGSIONAL (FUNCTIONAL REQUIREMENTS)

| ID | Modul | Deskripsi Kebutuhan | Kriteria Penerimaan (Acceptance Criteria) |
|---|---|---|---|
| **FR-001** | Dashboard | Ringkasan Eksekutif Terpadu | Menampilkan status 3 komponen utama, jumlah berita hari ini, ringkasan analisis terakhir, dan log job terakhir dalam waktu muat < 200 ms. |
| **FR-002** | Market Data | Snapshot Data Pasar EOD | Menerima data OHLCV 10 saham likuid BEI (BBCA, BBRI, BMRI, BBNI, ASII, TLKM, UNTR, PGAS, PTBA, ADRO) dan indikator teknikal (ATR14, RVOL20). |
| **FR-003** | News Ingestion | Pengumpulan Berita Terstruktur | Menyimpan berita emiten dengan field judul, URL, nama media, tanggal terbit, waktu ambil, ringkasan, dan status verifikasi fakta. |
| **FR-004** | Deduplikasi | Anti-Duplikasi Berita & Sinyal | URL berita berindeks unik. Sinyal paper trading unik berdasarkan kombinasi `ticker + bar_date + strategy_id`. |
| **FR-005** | Adapter Engine | Jembatan Subprocess Python | `hermes_adapter.py` menerima parameter CLI `--action <action> --payload <json>`, mengembalikan JSON standar dengan field `ok`, `data`, `errors`. |
| **FR-006** | AI Inference | Integrasi Gemini 3.8 Flash High | Mengirim konteks pasar terstruktur ke 9router lokal (`http://127.0.0.1:20128/v1`), memvalidasi respons JSON dengan schema `market_regime_summary`, `impact_direction`, dsb. |
| **FR-007** | Prompt Versioning | Pengelolaan Template Prompt | Tabel `prompt_templates` menyimpan versi prompt aktif. Setiap analisis merekam foreign key id dan string versi prompt yang digunakan. |
| **FR-008** | Database Storage | Audit Trail Analisis Utuh | Setiap eksekusi pipeline merekam record `analysis_runs` dengan `duration_seconds` dan status (`completed`/`failed`). |
| **FR-009** | Scheduler | Penjadwalan Tugas Otomatis | Menjadwalkan tugas harian EOD bursa (16:05 WIB) dan pemeriksaan integritas data bursa di hari kerja (Senin - Jumat). |
| **FR-010** | Telegram Bridge | Transmisi Notifikasi Idempotent | Mengirim ringkasan tervalidasi via Hermes CLI bridge (`hermes send`) dan mencatat hash konten pada `notification_logs`. |
| **FR-011** | Health Check | Monitoring Komponen Waktu Nyata | Endpoint `/api/health` memeriksa koneksi database PostgreSQL, availability port 20128, dan direktori `E:\hermes-stock-agent`. |
| **FR-012** | Paper Trading | Isolasi Forward EXP-01 vs EXP-04 | Menyimpan state portofolio terpisah di `data/paper_trading/`, mengeksekusi harga Open resmi hari bursa berikutnya, menolak opening gap > 2%. |

---

## 7. KEBUTUHAN NON-FUNGSIONAL (NON-FUNCTIONAL REQUIREMENTS)

1. **Keamanan Secret & Kredensial:** Seluruh kredensial (token bot Telegram, password database) disimpan murni di file `.env` lokal. Dilarang keras menampilkan token atau kunci rahasia pada commit git, log eksekusi, respons web publik, maupun laporan markdown.
2. **Validasi Input & Output:** Seluruh payload JSON antara Laravel dan Python Adapter wajib melalui parsing schema yang ketat. Jika JSON tidak valid, sistem menetapkan status fallback tanpa menghentikan worker service.
3. **Timeout & Robustness:** Panggilan HTTP ke router AI dibatasi timeout 30 detik. Panggilan subprocess adapter dibatasi timeout 45 detik.
4. **Idempotency:** Eksekusi ulang job EOD pada tanggal bar yang sama tidak boleh menduplikasi baris transaksi kas atau sinyal yang sudah berstatus aktif.
5. **Konsistensi Zona Waktu:** Seluruh representasi waktu pasar menggunakan zona waktu resmi Bursa Efek Indonesia, yaitu `Asia/Jakarta` (WIB / UTC+07:00).
6. **Kinerja Workstation Lokal:** Konsumsi memori aplikasi web Laravel < 128 MB per request, dan eksekusi inferensi AI lokal menggunakan streaming/json payload terkompresi tanpa membanjiri buffer sistem.
7. **Maintainability & Modularity:** Direktori `E:\hermes-stock-agent` dipertahankan sebagai repositori mandiri. Tidak ada dependensi melingkar (circular dependency) antara Laravel dan core Python engine.

---

## 8. ARSITEKTUR DAN ALIRAN DATA

```
                           [ PASAR MODAL BEI ]
                                    │
               ┌────────────────────┴────────────────────┐
               ▼                                         ▼
   Yahoo Finance (Daily EOD)                 Index Alpha (Broker Summary)
   RSS Feed Berita Resmi IDX                 Keterbukaan Informasi Emiten
               │                                         │
               └────────────────────┬────────────────────┘
                                    ▼
                 [ HERMES STOCK AGENT ] (Python 3.14)
                 - EOD Technical Indicators (ATR, RVOL)
                 - Broker Accumulation / Distribution Logic
                 - Frozen Forward Paper Trading (EXP-01 / EXP-04)
                                    │
                                    ▼ JSON Contract (Subprocess / CLI)
                 [ SERVICES / HERMES ADAPTER ] (services/)
                                    │
                                    ▼ JSON REST / CLI Service Bridge
                 [ LARAVEL CONTROL PLANE ] (laravel-app/)
                 ├── Job Scheduler & Queue Worker
                 ├── Database Controller (PostgreSQL 16)
                 ├── Blade UI Dashboard (Localhost:8000)
                 └── AI Client Engine ──► [ 9router Local :20128 ]
                                          (ag/gemini-3.8-flash-high)
                                    │
                                    ▼ Idempotent Notification Bridge
                 [ BOT TELEGRAM ] (@Info_BosKuu_bot)
                 Admin ID: 5923895922 (Orlando Dimas Saputra)
```

---

## 9. SUMBER DATA DAN KETERBATASANNYA

| Sumber Data | Tipe / Endpoint | Klasifikasi Waktu | Status Ketersediaan | Keterbatasan & Mitigasi |
|---|---|---|---|---|
| **Yahoo Finance** | `query1.finance.yahoo.com/v8` | Delayed 10-15m / EOD Final | Aktif | Rate limit sewaktu-waktu; mitigasi: caching EOD lokal di `data/raw_daily/` dan retry backoff. |
| **Index Alpha API** | `api.indexalpha.id` | EOD (~19:00 WIB) | Aktif (Kunci Terbatas) | Data baru final pasca-penutupan sistem kliring KPEI; mitigasi: cron job dijadwalkan pukul 19:15 WIB. |
| **IDX RSS / Media** | Feed Resmi IDX & Media Bisnis | Delayed 15-30m | Terverifikasi | Rentan perubahan struktur URL; mitigasi: parser feed XML/RSS standar dengan sanitasi tag HTML. |
| **9router AI** | `http://127.0.0.1:20128/v1` | Waktu Nyata Lokal | Aktif (PID 16376) | Bergantung pada service lokal Windows; mitigasi: health check periodik dan deteksi status endpoint. |

---

## 10. RISIKO, DEPENDENSI, DAN ROADMAP

### A. Manajemen Risiko
1. **Risiko Halusinasi AI:** Model AI berpotensi mengarang angka target harga jika diberikan prompt terbuka. *Solusi:* Prompt dikunci hanya untuk menilai rezim pasar (`positive`, `negative`, `mixed`, `unclear`), risiko, dan horizon dampak, dengan larangan eksplisit menyebut target harga spekulatif.
2. **Risiko Perubahan Format API:** Struktur data Yahoo Finance atau feed berita dapat berubah sewaktu-waktu. *Solusi:* Adapter dirancang dengan layer sanitasi dan fallback ke bar historis terakhir.
3. **Risiko Integritas Waktu Candle:** Eksekusi sinyal sebelum candle tutup resmi menyebabkan lookahead bias. *Solusi:* Aturan finalitas candle jam 16:00 WIB dan validasi `INVALID_CANDLE_NOT_FINAL` / `EXPIRED_STALE_WINDOW` ditegakkan di engine.

### B. Roadmap Pengembangan
- **Fase 0 (Selesai):** Audit lingkungan, toolchain Windows 11, status git, database, model AI router, dan jalur Telegram.
- **Fase 1 (Selesai):** Inisialisasi Laravel 13, integrasi PostgreSQL 16 (`project-bot`), migrasi 8 tabel, seeder, controller, view Blade, dan unit test dasar.
- **Fase 2 (Selesai):**
  * Penyusunan PRD, README, dan dokumentasi metodologi kuantitatif.
  * Implementasi Python Adapter (`E:\Project-bot\services\hermes_adapter.py`).
  * Abstraksi konektor berita pasar modal terverifikasi.
  * Service integrasi Laravel (`HermesAdapterService`) dan pipeline eksekusi EOD.
- **Fase 3 (Selesai):**
  * BEI Calendar Service (`BeiCalendarService`): validasi hari bursa 2026 & finalitas EOD.
  * Post-Horizon Evaluator (`PostHorizonEvaluatorService` & `hermes:evaluate-horizon`): evaluasi akurasi prediksi AI setelah 5 hari bursa.
  * Otomasi Scheduler Windows: Task Scheduler, batch script `run_eod_pipeline.bat`, proteksi mutex lock, dan cooldown deduplikasi error.
  * Integrasi metrik evaluasi & jadwal operasional pada dashboard Blade.
- **Fase 4 (Mendatang):** Evaluasi performa out-of-sample forward paper trading EXP-04 setelah akumulasi 30 transaksi tertutup dan pengiriman ringkasan Telegram idempotent.
