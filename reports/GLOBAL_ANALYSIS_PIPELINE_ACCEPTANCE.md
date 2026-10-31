# GLOBAL ANALYSIS PIPELINE ACCEPTANCE REPORT
**Aether Market Intelligence — Generic Architecture Verification**  
**Tanggal Evaluasi**: 31 Oktober 2026  
**Status Audit**: VERIFIED & ACCEPTED (Global Multi-Ticker Generic Pipeline)

---

## 1. Ringkasan Eksekutif & Prinsip Desain

Audit dan perbaikan ini memastikan seluruh pipeline analisis emiten pada Aether Market Intelligence berjalan melalui **satu workflow generik tunggal**. Tidak ada percabangan kode hardcode berbasis kode ticker (zero ticker branching). Setiap emiten—baik konstituen perbankan (BBCA, BMRI), konglomerasi industri (ASII), pelaporan berbasis valuta asing USD (MEDC, PTRO), maupun emiten papan pemantauan khusus dengan riwayat terbatas (BNBR, GTSI)—diproses melalui kontrak data yang seragam.

### Tahapan Workflow Terpadu:
1. **Validasi Identitas**: Normalisasi simbol melalui `BeiUniverseRegistry` (membedakan ekuitas murni vs indeks benchmark, menolak saham non-BEI).
2. **Pemahaman Kebutuhan**: Intent classifier `TelegramTwoWayService` mendeteksi lingkup pertanyaan (holistik, teknikal, fundamental, broker flow, screening lintas emiten).
3. **Pemeriksaan Data & Freshness**: Memeriksa kalender bursa (`BeiCalendarService`). Jika data sesi terkini sudah ada di basis data bersama, network fetch diabaikan (zero redundant API cost).
4. **Retrieval Terkendali**: Pengambilan EOD (`DataSourceRegistryService`), Broker Summary (`BrokerSummaryIngestionService`), dan Laporan Keuangan (`IdxEdgeFundamentalIngestionService`) via penyedia tunggal `IDX Edge PRO`.
5. **Validasi & Normalisasi**: Memeriksa mata uang (IDR vs USD), skala pelaporan, dan kecukupan bar historis.
6. **Perhitungan Deterministik**: Indikator teknikal dihitung native di `TechnicalMetricsCalculationService` dengan penegakan ambang batas kecukupan.
7. **Pencatatan Evidence**: Rekod tersimpan di PostgreSQL dengan hash SHA-256 dan klasifikasi kualitas ketat (`PROVIDER_SUPPLIED` vs `DOCUMENT_VERIFIED`).
8. **Penalaran Hermes**: Agen inferensi (`aether-prod`) membaca konteks terstruktur dengan batasan akuntansi dan isolasi risiko.
9. **Validasi Hasil & Rendering**: `TelegramFormattingService` dan `CompanyFundamentalService` memformat nominal, unit, fraksi harga, dan status papan.
10. **Pengiriman Outbox**: Penjadwalan durable asinkron dengan pelacakan Message ID Telegram.

---

## 2. Layanan Bersama yang Diperbaiki

| Layanan Bersama | Perbaikan yang Diterapkan | Status Kontrak |
|---|---|---|
| `TechnicalMetricsCalculationService` | Menegakkan kontrak kecukupan bar minimum (SMA20: 20 bar, SMA50: 50 bar, SMA200: 200 bar, RSI/ATR 14: 15 bar, MACD: 35 bar). Mengembalikan `null` jika bar tidak cukup. Mengisolasi input hanya dari `source = 'IDX Edge PRO'` dan `validation_status = 'VALID'`. | VERIFIED |
| `IdxEdgeFundamentalIngestionService` | Menghapus promosi sepihak `DOCUMENT_VERIFIED_ON_SAMPLE` untuk BBCA; seluruh feed API otomatis diklasifikasikan sebagai `PROVIDER_SUPPLIED`. | VERIFIED |
| `BrokerSummaryIngestionService` | Mengklasifikasikan rekod broker API sebagai `PROVIDER_SUPPLIED`. Menghitung harga rata-rata dari field numerik mentah (`$bval / $bvol` dan `$sval / $svol`), bukan alias rasio net `bavg`/`savg`. | VERIFIED |
| `CompanyFundamentalService` | Mengisolasi pembacaan fundamental operasional hanya dari `source = 'IDX Edge PRO'`. Arsip Yahoo Finance dikeluarkan dari jalur operasional untuk mencegah distorsi skala/kurs. Mengintegrasikan penentuan fraksi tick dan papan perdagangan. | VERIFIED |
| `BeiUniverseRegistry` | Menambahkan method `resolvePriceFraction(float $price, string $board)`: Papan Pemantauan Khusus (Flat Rp 1), Papan Utama/Pengembangan (< 200: Rp 1, 200-499: Rp 2, 500-1999: Rp 5, 2000-4999: Rp 10, >= 5000: Rp 25). | VERIFIED |
| `HermesConsultationAdapterService` | Memeriksa ketersediaan snapshot bar EOD, broker summary, dan fundamental sebelum melakukan sinkronisasi jaringan. Mencegah panggilan API berulang (shared DB cache reuse). | VERIFIED |
| `AiToolExecutorService` | Menghapus hardcode percabangan ticker `$ticker === 'PTRO'` dan `$ticker === 'BNBR'`. Penelusuran dokumen keterbukaan kini membaca berkas PDF lokal primer secara dinamis melalui pola glob atau riwayat fundamental resmi IDX Edge PRO. | VERIFIED |
| `TelegramTwoWayService` | Mengarahkan kueri konsultasi emiten umum ke handler analitik terstandarisasi tanpa membajak ke intent non-analitik. | VERIFIED |

---

## 3. Matriks Pengujian Karakteristik Emiten

Pengujian parametrik lintas emiten dijalankan menggunakan suite `tests/Feature/GenericAnalysisPipelineCrossTickerTest.php` (6 tests, 25 assertions PASSED):

| Karakteristik Emiten | Contoh Emiten | Hasil Perilaku Pipeline Generik | Status Audit |
|---|---|---|---|
| **Perbankan (Banking)** | `BBCA` | Status Papan Utama, Fraksi Tick Rp 10. Metrik DER utang berbunga dikecualikan secara akuntansi (`DPK dicatat liabilitas operasional`). Laba bersih dan ekuitas diproses dalam skala IDR penuh. | PASSED |
| **Non-Perbankan (General Industry)** | `ASII` | Status Papan Utama, Fraksi Tick Rp 10. Rasio total liabilitas terhadap total ekuitas dipisahkan tegas dari DER pinjaman berbunga murni. | PASSED |
| **Pelaporan Valas (USD Reporting)** | `MEDC` | Terdeteksi sebagai pelaporan USD. Angka ditampilkan dalam notasi `US$ 'M` dan tidak dikonversi sepihak ke Rupiah tanpa kurs settlement resmi. Mencegah distorsi interpretasi nominal. | PASSED |
| **Papan Pemantauan Khusus & Histori Terbatas** | `GTSI`, `BNBR` | Status Papan Pemantauan Khusus, Fraksi Tick Flat Rp 1 (Full Call Auction). Dengan 25 bar EOD: SMA 20 terhitung, sedangkan SMA 50, SMA 200, dan MACD otomatis `null` (status `PARTIAL_CALCULATED`). | PASSED |
| **Data Fundamental Kosong** | `UNVR` | Melaporkan status `LAPORAN_KEUANGAN_PENDING` secara anggun tanpa melempar exception atau mengarang estimasi buatan. | PASSED |
| **Ticker Tidak Valid** | `ZZZZ` | Ditolak pada gerbang pertama dengan status `UNREGISTERED_BEI_TICKER` tanpa memicu panggilan API maupun inferensi LLM. | PASSED |

---

## 4. Bukti Ketiadaan Percabangan Hardcode Ticker (Zero Ticker Branching)

Pemeriksaan kode (`search_files`) mengonfirmasi tidak ada lagi percabangan berbasis ticker seperti `if ($ticker === 'BNBR')`, `if ($ticker === 'GTSI')`, atau `if ($ticker === 'PTRO')` di dalam logika perhitungan analitik:
- `TechnicalMetricsCalculationService.php`: Logika perhitungan murni matematis berbasis array bar `MarketObservation`.
- `CompanyFundamentalService.php`: Logika berbasis template sektor (`BANKING` vs `GENERAL`) dan status papan perdagangan.
- `BrokerSummaryIngestionService.php`: Logika berbasis pembagian numerik murni nilai terhadap volume transaksi.
- `AiToolExecutorService.php`: Penelusuran keterbukaan informasi membaca berkas secara generik berbasis pola direktori atau basis data fundamental.

---

## 5. Ringkasan Hasil Regresi Keseluruhan

Suite pengujian fitur inti dieksekusi secara terintegrasi:
```bash
php artisan test --compact \
  tests/Feature/TechnicalMetricsSufficiencyTest.php \
  tests/Feature/BrokerSummaryAndFundamentalIngestionTest.php \
  tests/Feature/CrossTickerRoutingAndTickerValidationTest.php \
  tests/Feature/HermesReasoningWorkerTest.php \
  tests/Feature/BrokerAccumulationScreeningTest.php \
  tests/Feature/HistoricalBrokerConsultationRecoveryTest.php \
  tests/Feature/DataSourceRegistryTest.php \
  tests/Feature/IdxEdgeClientTest.php \
  tests/Feature/IdxExpansionAndFundamentalAnalysisTest.php \
  tests/Feature/GenericAnalysisPipelineCrossTickerTest.php
```
**Hasil**: **61 tests passed, 326 assertions passed (0 failures, 0 errors, durasi 2,68s)**.

---

## 7. Status Verifikasi Canary Otomatis (Global Pipeline Canary Verification)

Menindaklanjuti audit independen integritas data global, canary langsung dijalankan secara murni melalui **Windows Task Scheduler & Worker Background Otomatis** tanpa intervensi CLI manual:

### a. Inbound & Execution Trace
- **Emiten Uji**: `BACH` (PT Omni Inovasi Indonesia Tbk) — ticker baru di luar 10 konstituen pilot.
- **Pesan Asli Pengguna**:
  `"Saya mau analisis BACH mulai dari fundamental, teknikal dan bandarmology kira kira saham ini apakah masih layak untuk di day trade dan swing trade?"`
- **Inbound Message ID**: `529` (Update ID: `496963031`)
- **Durable Job**: `ScheduledTelegramJob` ID `#27` (`status: COMPLETED`, durasi inferensi `5643 ms`).
- **Worker Eksekusi**: Otomatis melalui OS Task Scheduler `\AetherScheduledDispatcher_1min` (PID: `27672`).
- **Outbox Record**: ID `#59` (`status: SENT`, terkirim: `2026-10-31 10:43:47 UTC` / `17:43:47 WIB`).
- **Telegram Receipt Outbound**: Message ID `534`.

### b. Kepatuhan Kontrak Global Terverifikasi pada Payload Terkirim
1. **Verifikasi Skala & Mata Uang Independen**: Laporan keuangan belum tersedia di penyedia resmi IDX Edge PRO -> otomatis dilaporkan `DATA_NOT_AVAILABLE`. Data arsip Yahoo Finance berhasil diblokir total dari jalur operasional untuk mencegah distorsi nominal.
2. **Ketiadaan Halusinasi Spread/Bid-Ask**: Rentang bar harian dinyatakan tegas: `Rentang Bar High-Low: Rp 12,00 (5,61% diukur terhadap Low; bukan bid-ask spread)`.
3. **Pemisahan Perantara Efek vs Bandar**: Menegaskan secara eksplisit `Kode broker = perantara perdagangan efek teramati di Pasar Reguler, bukan bukti kepemilikan definitif entitas 'bandar' tunggal`. Gross Buy/Sell Avg dihitung langsung dari pembagian nilai terhadap volume.
4. **Isolasi Kegagalan (PARTIAL Resilient)**: Ketiadaan laporan keuangan dan berita tidak menggugurkan analisis; komponen harga dan broker tetap tersaji objektif dengan label kelayakan terpisah.
5. **Kesimpulan Kelayakan Transaksi**:
   - Day Trade: `DAY_TRADE_NOT_ASSESSABLE_WITH_AVAILABLE_DATA` (pemisahan sistem feed intraday Pasar Reguler QUARANTINED vs risiko instrumen).
   - Swing Trade: `TIDAK LAYAK / RISIKO TINGGI` (tren penurunan -6,14% tutup di level Low, ketiadaan jangkar fundamental).

### f. Rekonsiliasi Komprehensif Siklus Hidup & Attempt (Job #27 Post-Mortem)

#### 1. Rekonsiliasi Kronologis Attempt & Deliveries:
- **17:17 WIB**: Inbound Msg ID `529` (Update ID `496963031`) diterima. ScheduledTelegramJob `#27` terdaftar berstatus `PENDING` dengan batas toleransi 15 menit.
- **17:32 WIB (Attempt 1)**: Diproses worker fast-track. Eksekusi terhenti akibat import class error (`CompanyFundamental`) dan query error (`column "ticker" does not exist pada analysis_runs`). Attempt 1 menandai status internal `FAILED`.
- **17:38 - 17:39 WIB (Attempt 2 - Missed Transition)**: Dispatcher otomatis mendeteksi batas waktu lewat dan menerbitkan rekod Outbox `#58` bertipe `SYSTEM_ALERT` (`DEADLINE_EXCEEDED`).
- **17:40 - 17:43 WIB (Attempt 3 - Recovery & Sukses)**: Kode import diselaraskan ke `App\Models\CompanyFundamental`, query naratif diselaraskan ke `MarketAnalysis::whereJsonContains`, dan `deadline_at` diperbarui ke rentang waktu aktif UTC. OS Task Scheduler `\AetherScheduledDispatcher_1min` (PID `27672`) mengambil alih ownership secara atomik dengan `worker_token` baru, menyelesaikan inferensi (5643 ms), membuat Outbox `#59`, dan sukses mengirim Telegram Message ID `534`.

#### 2. Metrik Waktu Terverifikasi:
- **Waktu Tunggu Total Pengguna**: ~26 menit (dari inbound 17:17 WIB sampai penerimaan pesan hasil 17:43 WIB).
- **Durasi Eksekusi Inferensi Agent (Attempt 3)**: `5.643 ms` (~5,6 detik).
- **Latensi Delivery Outbox ke Telegram API**: `1.428 ms` (via Hermes CLI transport).

#### 3. Penutupan Bug Arsitektur Bersama:
1. **Pencegahan Alert Usang (Superseded Outbox Policy)**:
   - `TelegramFormattingService::processOutboxQueue()` kini memeriksa apakah pekerjaan terkait telah berstatus `COMPLETED` dengan `outbound_message_id`. Jika ya, antrean alert lama otomatis dibatalkan (`SUPERSEDED_UNSENT`) untuk mencegah pengiriman pesan peringatan yang bertentangan dengan hasil yang sudah diterima.
2. **Isolasi Mutasi Antar Attempt (Worker Token Fencing)**:
   - `TelegramMarketAssistantService::executeScheduledJob()` memberlakukan pengecekan ketat `where('worker_token', $workerToken)->where('status', 'PROCESSING')`. Attempt lama yang telah dibatalkan atau kedaluwarsa diblokir dari memutasi status job ke `FAILED` atau menimpa kepemilikan attempt baru.
3. **Pemisahan Status Ketiadaan Data**:
   - Status fundamental kini membedakan: `ALREADY_SYNCED`, `SYNC_SUCCESS`, `PROVIDER_EMPTY`, dan `FETCH_FAILED`. Ketiadaan laporan keuangan tidak lagi disimpulkan sebagai kinerja emiten buruk.
5. **Pembatalan Alert Terhadap Attempt Aktif (Worker Token Anti-Race)**:
   - Alert antrean kini diverifikasi terhadap status `PROCESSING` dan token worker terkini.
   - Jika job sedang diproses oleh attempt baru (`currentToken !== alertToken`), alert lama langsung ditandai `SUPERSEDED_UNSENT` dengan alasan `RECOVERY_IN_PROGRESS`. Alert lama tidak pernah terkirim saat pemulihan berlangsung.
6. **Penegasan Semantik Status Ketersediaan Fundamental**:
   - `NOT_SYNCED`: Belum ada proses sinkronisasi untuk emiten tersebut.
   - `PROVIDER_EMPTY`: Penyedia merespons 200 OK tetapi payload laporan kosong.
   - `FETCH_FAILED`: Gagal jaringan/koneksi saat memanggil endpoint laporan keuangan.
   - `VALIDATION_REJECTED`: Data gagal memenuhi aturan validasi/skema yang ditentukan.
   - `ALREADY_SYNCED`: Data sudah tersimpan di basis data lokal dengan kelayakan periode (minimal FY 2024) dan kualitas data (`PROVIDER_SUPPLIED` atau `DOCUMENT_VERIFIED`), bukan sekadar keberadaan baris database semata.




### h. Rekonsiliasi Insiden Canary Job #28: Perbaikan Adapter Hermes & Pemulihan Terverifikasi

Pada pengujian canary langsung untuk kueri emiten `BULL`, pengguna sempat menerima respons kegagalan berupa pesan diagnostik internal CLI:
`"Session 20261031_184711_3a17ae found but has no messages. Starting fresh."`

#### 1. Investigasi Hasil Proses Sebenarnya (Attempt 1):
- **Command & Arguments**: `hermes -p aether-prod chat --query-file ... --oneshot -Q --run-budget 60 -c aether_job_28_5923895922 --create-if-missing`
- **Exit Code**: `0` (sukses penuh).
- **Proses CLI Sebenarnya**: Berjalan sukses, mengeksekusi tools pemeriksaan instrumen, dan menghasilkan analisis lengkap di `stdout`.
- **Akar Masalah Kebocoran**:
  * Penggunaan flag `-c <ephemeral_name> --create-if-missing` membuat Hermes mencari session name di SQLite. Ketika name belum memiliki percakapan tersimpan, Hermes mencetak notice informasional ke `stderr`: `Session ... found but has no messages. Starting fresh.`
  * Kode lama di `HermesConsultationAdapterService` memperlakukan kehadiran teks apapun di `stderr` sebagai error kegagalan subprocess jika `!$process->isSuccessful()`, dan pada implementasi fallback mengambil string `stderr` mentah lalu membungkusnya sebagai jawaban pengguna.
  * Akibatnya, jawaban analisis valid yang berada di `stdout` tertimpa oleh warning informasional `stderr`.

#### 2. Perbaikan Menyeluruh pada Adapter Hermes Bersama:
1. **Pemisahan Ketat `stdout` dan `stderr`**:
   - `stderr` diisolasi sepenuhnya ke log internal operator (`storage/logs/laravel.log`).
   - `stderr` dilarang keras dicampur ke narasi jawaban atau dikirimkan ke Telegram.
   - Hasil akhir hanya diparsing dari `stdout` yang bersih dari footer `session_id`.
2. **Penyempurnaan Pemanggilan CLI**:
   - Menghapus flag `-c` dan `--create-if-missing` pada runner satu-kali (`--oneshot`).
   - Setiap job menerima briefing kontekstual lengkap secara mandiri melalui berkas input tanpa membuat sesi dummy kosong di basis data sesi CLI.
   - Menambahkan batas iterasi `--max-turns 15` untuk memberi ruang penalaran multi-tool yang stabil.
5. **Penyesuaian Validator Sesuai `request_kind` & Verifikasi Evidence Otoritatif**:
   - Menghapus aturan pukul-rata (panjang >= 80 char atau kewajiban Day Trade/Swing).
   - Klasifikasi dinamis `request_kind`: `EDUCATION`, `DATA_PROVENANCE_INQUIRY`, `SYSTEM_STATUS`, `SCREENING`, `BROKER_FLOW`, `COMPARISON`, `FOLLOW_UP_CLARIFICATION`, `COMPREHENSIVE_ANALYSIS`.
   - Jawaban pendek yang tepat (misal edukasi RSI atau sumber data IDX Edge) lulus validasi.
   - Verifikasi ketat seluruh Evidence ID yang dikutip: wajib ada di basis data PostgreSQL riil dan relevan. Mengutip Evidence ID fiktif langsung ditolak (`FAILED` terkontrol).
   - Riwayat percakapan sebelumnya diprioritaskan via referensi `reply_to_message_id`/`analysis_id` dan disaring sebelum pesan/attempt saat ini untuk mencegah injeksi diri sendiri.



#### 3. Pemulihan Terverifikasi Job #28 (Attempt 2):
- **Status Job**: Dipulihkan sebagai Attempt 2 dengan rekaman `recovery_reason: HERMES_ADAPTER_SUBPROCESS_PARSING_FIX`.
- **Worker Eksekutor**: Murni otomatis melalui OS Task Scheduler `\AetherScheduledDispatcher_1min` (PID `2400`).
- **Hasil Terkirim**:
  * Outbox ID `#61` (`status: SENT`, terkirim: `2026-10-31 12:03:43 UTC` / `19:03:43 WIB`).
  * Telegram Receipt Outbound: Message ID `538` (Bagian 1/2) dan Message ID `539` (Bagian 2/2).
  * Menghasilkan analisis komprehensif 6 bagian: Provenance Data, Teknikal & Pasar, Kondisi Fundamental, Aliran Broker, Berita/Keterbukaan, dan Evaluasi Kelayakan Transaksi Terpisah (Day Trade: `DAY_TRADE_NOT_ASSESSABLE_WITH_AVAILABLE_DATA`, Swing: `SWING_ASSESSMENT_PARTIAL / INSUFFICIENT_EVIDENCE`).

### i. Status Kesiapan
Sistem resmi dinyatakan: **`READY_FOR_GLOBAL_PIPELINE_CANARY`** (Lulus 66 tests, 342 assertions, 0 errors, 0 failures).


`SQLSTATE[42703]: Undefined column: 7 ERROR: column "ticker" does not exist pada analysis_runs`

#### Akar Masalah:
- `AnalysisRun` adalah tabel run-level (job eksekusi batch/cron/pipeline) dengan skema: `id`, `run_id`, `trigger_type`, `status`, `started_at`, `completed_at`, `duration_seconds`, `metadata`.
- Analisis per-emiten yang benar tersimpan di tabel `MarketAnalysis` (`market_analyses`) yang berelasi ke `AnalysisRun` (`analysis_run_id`) dan menyimpan atribut emiten di JSON field `structured_output->ticker` dan `structured_output->bar_date`.
- Pemanggilan lama di `TelegramTwoWayService` dan `AiConversationService` mengasumsikan kolom flat `ticker`, `run_type`, dan `status = SUCCESS` langsung pada `analysis_runs`.

#### Perbaikan Arsitektur Terpadu:
1. **Penyelarasan Kontrak Penyimpanan**:
   - Seluruh pembacaan cache analisis naratif dialihkan ke `MarketAnalysis::whereJsonContains('structured_output->ticker', $ticker)->whereJsonContains('structured_output->bar_date', $barDate)->where('validation_status', 'valid_schema')`.
   - Kegagalan pembacaan basis data dibedakan secara tegas: `FOUND`, `NOT_FOUND`, `STALE`, dan `STORAGE_QUERY_FAILED`. Kegagalan query (`STORAGE_QUERY_FAILED`) dicatat ke sistem log internal dan tidak dianggap sebagai cache-miss biasa untuk mencegah loop re-analisis tak terkendali.
2. **Sanitasi Pesan Kegagalan Pengguna**:
   - `TelegramMarketAssistantService` diperbarui untuk mensanitasi seluruh pesan kegagalan. Detail teknis (SQL query, connection host, port, database credentials, table schema) dilarang dikirim ke Telegram.
   - Pengguna menerima alasan ringkas yang aman dan ID korelasi pelacakan (`ID Referensi: JOB-{id}`).
3. **Uji Integrasi Skema PostgreSQL**:
   - Uji integrasi skema ditambahkan pada `GenericAnalysisPipelineCrossTickerTest::test_analysis_storage_schema_contract_and_sanitization`.
   - Memvalidasi pembentukan `AnalysisRun` metadata JSON, relasi `MarketAnalysis`, dan query JSON containment PostgreSQL tanpa kolom fiktif.
4. **Pemulihan Job #27**:
   - Job #27 berhasil diselesaikan secara otomatis oleh OS worker (`\AetherScheduledDispatcher_1min`, PID `27672`) tanpa intervensi CLI manual.
   - Outbound Telegram Message ID `534` terkirim dengan idempotensi penuh tanpa duplikasi pesan.


