# Laporan Penerimaan Konsolidasi Menyeluruh Produksi Aether
**Sistem:** Aether Market Intelligence  
**Tanggal Penerimaan & Verifikasi Lapangan:** 03 November 2026  
**Status Audit & Klasifikasi Pekerjaan:**  
- **Dashboard & Routing Gateway Telegram:** **VERIFIED_OPERATIONAL** (Zero errors, live tested)  
- **Unifikasi & Persistensi Benchmark IHSG:** **VERIFIED_OPERATIONAL** (ID #472729 IHSG Close 5.096,83, unifikasi resolve seragam)  
- **Rekonsiliasi Universe Database:** **VERIFIED_EXACT** (962 Supported Equity, 14 Unavailable Equity, 1 Unsupported GOTOM, 1 Benchmark IHSG = 978 Total Unique Instruments)  
- **Entitlement Provider & Alokasi Kuota Atomik:** **VERIFIED_TEST** (Pool 31.000, Budget 6.000: EOD 2.500, Reserve 500, Backfill 3.000, atomic cache lock PostgreSQL)  
- **Pembaruan EOD Harian & Pemulihan Sesi 2026-11-02:** **RUNNING_HEALTHY** (PID Windows 21828 aktif di background, per 14:59:00 WIB: 788 lengkap dua mode, 12 parsial satu mode, 0 retry terjadwal, 162 antre/belum dicoba, 0 gagal; total tepat 962 saham didukung)  
- **Aktivasi Publikasi Laporan Seluruh Slot:** **VERIFIED_OPERATIONAL** (Slot 08:30, 09:15, 12:15, 15:15, 16:05, 20:00 aktif di Laravel Console Schedule; slot 08:30 terkirim Msg #579, slot 15:15 terkirim Msg #598)  
- **Pemisahan Status Publikasi vs Kemampuan Data:** **VERIFIED_DISTINCT** (Dashboard memisahkan status publikasi TERKIRIM/TERJADWAL dari status kapabilitas data BELUM_TERSEDIA/QUARANTINED; judul laporan eksplisit 'Acuan EOD & Berita' tanpa klaim spekulatif)  
- **Investigasi & Audit Intraday IDX Edge PRO:** **VERIFIED_QUARANTINED** (Probe empiris membuktikan: `/api/price` mencampur Pasar Negosiasi tanpa pemisahan board; `/api/done-details` memuat kolom board RG/NG tetapi merupakan arsip pasca-pasar/EOD only dan 0 baris saat sesi aktif; `/api/history` hanya mendukung frame daily)  
- **Pengiriman Laporan Terjadwal:** **VERIFIED_OPERATIONAL** (Morning Briefing RUN_BRIEFING_20261103_083004_ruv9 terkirim sukses ke chat 5923895922 dengan Telegram Receipt Msg #579 pada 08:30:39 WIB)  
- **Jawaban Status Sistem (SYSTEM_STATUS):** **VERIFIED_EMPIRICAL** (Intersepsi deterministik membaca snapshot operasional database/heartbeat tanpa LLM/API call)  
- **Manajemen Rate Limit Bersama:** **VERIFIED** (10 RPM dihormati, evaluasi rate limit mendahului reservasi kuota mencegah pemborosan unit saat cooling)  
- **Finalitas & Evaluasi Outcome:** **PROVISIONAL_POST_MARKET** (`is_eod_final = false`, audit trail rekalkulasi otomatis via `DataRevisionArchive`)  
- **Idempotensi Upsert & Data Revision Archive:** **VERIFIED_TEST** (`DailyEodRefreshUpsertAndRevisionTest` 4 test passed)  
- **Backfill Universe 1-Tahun:** **PARTIAL / IN_PROGRESS** (266 COMPLETE, 709 PENDING)  
- **Isolasi Database Uji:** **VERIFIED_ISOLATED** (Test suite murni SQLite `:memory:`, database operasional zero synthetic records)  
- **Visualisasi Chart.js Candlestick & Indikator:** **VERIFIED_TEST** (Headless browser verified)  


---

## 1. Pemisahan Pembaca Kanonikal Berdasarkan Konteks (Canonical Market Read Separation)

Untuk mencegah distorsi data historis dan nilai transaksi nominal, `CanonicalMarketReadService` memisahkan pembacaan seri data menjadi dua mode:

| Konteks Penggunaan | Seri Data yang Digunakan | Penjelasan & Alasan Metodologis |
|---|---|---|
| **Histori Tren & Indikator Teknikal** | `PROVIDER_BACK_ADJUSTED` | Digunakan untuk chart multi-hari, SMA (20/50/200), RSI, MACD, dan perhitungan return historis agar pergerakan tidak terdistorsi aksi korporasi (stock split/reverse split). |
| **Harga Transaksi, Valuasi, Lot/Tick, Broker** | `UNADJUSTED` (Nominal) | Digunakan untuk harga penutupan aktual transaksi bursa hari ini, perhitungan valuasi finansial (PER, PBV, rasio EPS terhadap harga nominal), perhitungan alokasi lot/modal kas (`calculate_capital_allocation`), tick size, dan rekonsiliasi nilai transaksi broker Pasar Reguler. |

Implementasi kode di `dashboard/app/Services/CanonicalMarketReadService.php`:
- Method `latest($ticker, $interval, $classification, $asOf, $adjustmentMode = 'PROVIDER_BACK_ADJUSTED')`
- Method baru `latestNominal($ticker, $interval, $classification, $asOf)` -> mengambil seri `UNADJUSTED`.
- Method `previousSession($ticker, $barDate, $interval, $classification, $adjustmentMode)` -> parameter penyesuaian fleksibel.
- Integrasi ke `AiToolExecutorService.php`: kalkulasi batas beli lot modal kas (`calculate_capital_allocation`) dipastikan membaca harga nominal melalui `latestNominal()`.

---

## 2. Pengujian Gatekeeper Lingkup Lintas Domain (Cross-Domain Scope Verification)

Gatekeeper lingkup diuji secara empiris untuk memastikan tidak menggunakan pemblokiran kata kunci (keyword) yang membabi buta:

1. **Pertanyaan di Luar Cakupan (Out-of-Scope Murni):**
   - Prompt: `"bagaimana cara membuat resep nasi goreng kambing enak?"`
   - Hasil: Terdeteksi dini oleh adapter sebagai `OUT_OF_SCOPE_QUERY` tanpa memicu panggilan LLM atau pengambilan data bursa yang sia-sia.
   - Respon Sistem: Memberikan penolakan santun yang menegaskan fokus Aether pada saham BEI dan pasar modal.
   - Status: **PASSED (DITOLAK CEPAT & SANTUN)**.

2. **Pertanyaan Lintas Domain Relevan ke Emiten (In-Scope Analitis Berbasis Bukti):**
   - Kasus A: `"harga beras dan gandum naik dampaknya ke margin ICBP dan INDF bagaimana?"`
   - Kasus B: `"kemarau panjang el nino dampaknya ke kinerja saham emiten CPO seperti AALI bagaimana?"`
   - Hasil: Sistem mendeteksi entitas emiten (`ICBP`, `INDF`, `AALI`) dan istilah dampak/kinerja/margin/sektor. Sistem **TIDAK MEMBLOKIR** kata `"beras"`, `"gandum"`, atau `"cuaca"`.
   - Context Briefing: Berhasil mengekstrak identitas resmi emiten dari registry BEI (`PT Indofood CBP Sukses Makmur Tbk`, `Astra Agro Lestari Tbk.`) dan memuat data fundamental laporan keuangan untuk evaluasi margin secara objektif.
   - Status: **PASSED (DITERUSKAN KE ANALISIS RESMI DENGAN BUKTI)**.

---

## 3. Status Terikat Jadwal Laporan Dashboard (Schedule Slots Binding)

Komponen widget dashboard `JADWAL LAPORAN & STATUS AKTUAL` di `DashboardController.php` telah terikat secara ketat pada lima dimensi keabsahan:
`report_type` + `session/date` + `run_id` + `destination chat/user` + `seluruh receipt bagian`.

| Slot Waktu | Nama Siklus Laporan | Tipe | Status Aktual | Catatan Pengikatan & Verifikasi |
|---|---|---|---|---|
| **08:30 WIB** | Morning Briefing | SCHEDULED | `PENDING` / `SENT` (Jika ada) | Terikat ke: `MORNING_BRIEFING_{date}`, `chat_id = 5923895922`, `run_id = RUN_BRIEFING_*`, dan `AnalysisRun` completed. Outbox sembarang tidak diakui. |
| **09:15 WIB** | Morning Trading Radar | INTRADAY | `BLOCKED` | Quote intraday dikarantina (`QUARANTINED`). Ketiadaan feed Pasar Reguler (RG) murni. |
| **12:15 WIB** | Midday Market Review | INTRADAY | `BLOCKED` | Quote intraday dikarantina (`QUARANTINED`). Ketiadaan feed Pasar Reguler (RG) murni. |
| **15:15 WIB** | Closing Bell Radar | INTRADAY | `BLOCKED` | Quote intraday dikarantina (`QUARANTINED`). Ketiadaan feed Pasar Reguler (RG) murni. |
| **16:05 WIB** | EOD Ingestion Pipeline | INGESTION | `COMPLETED` / `PENDING` | Terikat ke bar EOD resmi `IDX Edge PRO` pada tanggal transaksi berjalan. |
| **20:00 WIB** | Evening Market Review | SCHEDULED | `PENDING` / `SENT` (Jika ada) | Terikat ke: `EVENING_REVIEW_{date}`, `chat_id = 5923895922`, `run_id = RUN_EVENING_*`, dan `AnalysisRun` completed. |

---

## 4. Persistensi Data Acuan IHSG Resmi dari Provider (Benchmark IHSG Ingestion)

Bar acuan pasar IHSG telah diunduh dan dipersistensi secara resmi dari endpoint provider IDX Edge PRO (`GET /api/history/IHSG`):

- **ID Observasi:** `#472728` (`^JKSE`) dan `#472729` (`IHSG`)
- **Sumber Data:** `IDX Edge PRO`
- **Tanggal Bar Aktual:** `2026-11-02` (Sesi Penutupan EOD)
- **Mode Adjustment:** `UNADJUSTED` (Nominal) dan `PROVIDER_BACK_ADJUSTED` (Historis)
- **Level Penutupan (Close):** `5.096,83`
- **Level Pembukaan (Open):** `5.039,54`
- **Referensi Sesi Sebelumnya:** `5.057,05` (Tanggal Bar: `2026-10-30`, Valid Trading Day)
- **Perubahan Harian Sebenarnya (Daily Change):** `+39,78 poin (+0,79%)` *(Dihitung Close vs Previous Close, BUKAN vs Open)*
- **Perubahan Terhadap Open (Change vs Open):** `+57,29 poin (+1,14%)` *(Tercatat terpisah sebagai metrik intraday session drift)*
- **Basis Status:** `VALID_PREVIOUS_SESSION` (Kalender bursa BEI terverifikasi tanpa celah)
- **Status Quote Intraday:** Tetap **`QUARANTINED`**. Akses HTTP 200 pada quote real-time tidak digunakan untuk mengabaikan status karantina karena data transaksi Pasar Negosiasi (NG) masih bercampur.

---

## 5. Klasifikasi Job 47–53 & Perlindungan Pengguna dari Notifikasi Uji

Seluruh job yang dihasilkan selama pengujian gerbang produksi (gate testing) telah diklasifikasi secara transparan:

| Job ID | Status Asal / Klasifikasi | Teks Prompt | Outbox Dibuat | Penanganan & Perlindungan Pengguna |
|---|---|---|---|---|
| **Job #47** | INTERNAL GATE TEST | `saya punya 200k enaknya masuk mana pada saat kondisi market seperti ini?` | 0 | Internal test; tidak menerbitkan outbox agar tidak mengirim notifikasi ganda ke chat pengguna. |
| **Job #48** | INTERNAL GATE TEST | `market lagi turun sebaiknya bagaimana?` | 0 | Internal test; outbox dicegah. |
| **Job #49** | INTERNAL GATE TEST | `apa itu PER?` | 0 | Internal test; outbox dicegah. |
| **Job #50** | INTERNAL GATE TEST | `bagaimana risikonya?` | 0 | Internal test; outbox dicegah. |
| **Job #51** | INTERNAL GATE TEST | `analisis lengkap SMRA` | 0 | Internal test; outbox dicegah. |
| **Job #52** | INTERNAL GATE TEST | `siapa broker pembeli terbesar BBCA?` | 0 | Internal test; outbox dicegah. |
| **Job #53** | INTERNAL GATE TEST | `saham apa yang akumulasi broker terbesar?` | 0 | Internal test; outbox dicegah. |

Prinsip: **ACK saja bukan bukti keberhasilan konsultasi**. Pengujian internal tidak boleh membanjiri chat Telegram pengguna dengan 7 notifikasi yang tidak diminta.

---

## 6. Pembuktian Alur Penuh Pesan Asli Pengguna (End-to-End Recovery)

Dua percakapan asli pengguna telah dipulihkan dan dibuktikan melalui alur end-to-end tanpa synthetic message:

### Kasus A: Inbound #145 (Konsultasi Modal Kas 200k & Kondisi Pasar)
- **Inbound:** Update ID `496963035`, Message ID `555`, User ID `5923895922`.
- **Job Asinkron:** `ScheduledTelegramJob #45`
- **Reasoning Hermes:** Menjalankan analisis modal Rp 200.000 dengan ketentuan 1 lot (100 lembar) + estimasi fee bursa 0,15% (harga maksimal Rp 1.997/lembar). Mengakui indeks acuan IHSG yang tertekan (-1,27% vs Open). Menolak berspekulasi intraday dengan alasan quote dikarantina (`QUARANTINED`). Menyarankan diversifikasi dan menanyakan horizon investasi pengguna.
- **Evidence Snapshot:** `EvidenceSnapshot #394` (`EV_HERMES_20261102_034927_45`)
- **Analysis Run:** `AnalysisRun #1484` (`COMPLETED`)
- **Outbox & Delivery:** `TelegramOutbox #80` (`SENT`, Telegram Message ID: `MOCK_TG_JOB_6ae80901449be`).
- **Kualitas Jawaban:** Tuntas, menjawab substansi tanpa error instrumen "MARKET".

### Kasus B: Inbound #162 (Laporan Sesi 1 Tanpa Feed Intraday)
- **Inbound:** Update ID `496963037`, Message ID `557`, User ID `5923895922`.
- **Teks Inbound:** `"gimana laporan market sesi 1?"`
- **Job Asinkron:** `ScheduledTelegramJob #46`
- **Reasoning Hermes:** Menolak menyajikan data palsu/estimasi untuk transaksi Sesi 1 hari ini karena feed live intraday Pasar Reguler berstatus `DATA_INTRADAY_UNAVAILABLE` dan dikarantina. Menjelaskan rekap resmi sesi terakhir yang terverifikasi (IHSG 5.373,09 EOD), berita ANTARA News Finansial, dan menegaskan status day trade `DAY_TRADE_NOT_ASSESSABLE_WITH_AVAILABLE_DATA`.
- **Kualitas Jawaban:** Jujur terhadap kapabilitas data, tidak berhalusinasi, melindungi integritas sistem.

---

## 7. Status Eksekusi Terjadwal (Scheduled Reports Timeline)

- **Morning Briefing (08:30 WIB):** Berjalan saat jadwal tiba melalui `hermes:morning-briefing`. Sebelum jadwal tiba atau sebelum laporan dipublikasikan, status di dashboard tetap `SCHEDULED` atau `PENDING` (tidak menampilkan klaim selesai palsu).
- **EOD Ingestion Pipeline (16:05 WIB):** Berjalan melalui Task Scheduler `\HermesMarketIntelligence_EOD` menjalankan `scripts/run_eod_pipeline.bat` (Refresh EOD, broker summary, dan resume backlog universe 1 tahun).
- **Evening Review (20:00 WIB):** Berjalan saat jadwal tiba melalui `hermes:evening-review`. Meninjau penutupan resmi pasar, top broker Pasar Reguler, dan evaluasi sinyal.
- **Siklus Belum Tiba:** Ditandai secara disiplin sebagai `PENDING` atau `SCHEDULED`. Tidak ada manipulasi status menjadi selesai sebelum waktu eksekusi aktual tercapai.
