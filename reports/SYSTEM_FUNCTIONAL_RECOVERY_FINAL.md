# Aether Market Intelligence — Functional Recovery Final Report

Tanggal bukti: 2026-11-11
Dashboard: http://127.0.0.1:8000
Database operasional: project_bot_recovery_20261110
Mode: recovery, dashboard read-only

## 1. Ringkasan keputusan

Tidak ada batch discovery, registry upsert, OHLCV recovery, writer, scheduler, outbox, Telegram broadcast, training, replay, atau WebSocket collector live yang dijalankan pada tahap ini.

Alasan: tidak ada kebutuhan penulisan data setelah audit read-only. Baseline backup tetap sesuai dan tidak berubah. Data mutation audit berjalan memakai role aether_ingestion dan tidak melakukan INSERT, UPDATE, DELETE, TRUNCATE, atau DDL.

## 2. Baseline dan perubahan data

Baseline dipertahankan:

- File: storage/backups/incident_20261110/project_bot_recovery_20261110_pre_ingestion_baseline_v2_20261110.dump
- Ukuran: 45,557,608 bytes
- SHA-256: d7e82123767766fe80268f2e4b42d2c9c456ad7c2176ded89744d14f591ae8e6
- pg_restore --list: exit 0, 407 baris TOC

Audit read-only role aether_ingestion:

- current_database(): project_bot_recovery_20261110
- current_user: aether_ingestion
- current_schema(): public
- market_observations: 513,381
- idx_company_registries: 500
- eod_session_queues: 3
- data_revision_archives: 2
- cache: 2,028
- cache_locks: 0
- observation date: 2024-10-01 sampai 2026-11-06
- distinct observation dates: 501
- backlog: 2026-11-04, 2026-11-05, 2026-11-06; semua PARTIAL_BACKLOG
- 2026-11-07: Sabtu, tidak menjadi target sesi

Timestamp maksimum yang terbaca berada pada 2026-11-07 untuk observations, registry, dan revision archive. Tidak ada perubahan yang dieksekusi oleh audit ini. Perbandingan byte-level terhadap seluruh isi database tidak dapat dibuktikan dari dump custom tanpa restore terisolasi; karena itu kesimpulan dibatasi pada tabel yang dapat dibaca dan bukti runtime.

Tabel evidence, receipt, prediction, analysis, dan telegram_outbox ditolak role ingestion sesuai least privilege. Ini bukan error pemulihan; ini batas akses yang disengaja.

## 3. Verifikasi backup baru

Backup baru tidak dibuat karena audit read-only tidak menemukan batch mutation yang akan dijalankan.

Percobaan diagnosis pg_dump memakai executable:

C:/Program Files/PostgreSQL/16/bin/pg_dump.exe

Hasil fail-closed:

- exit code: 1
- output archive diagnostik dihapus setelah pemeriksaan
- stderr tersanitasi:
  pg_dump: error: query failed: ERROR: permission denied for table analysis_runs
  pg_dump: detail: Query was: LOCK TABLE ...

Tidak ada grant luas, akun superuser, atau perubahan privilege untuk melewati kegagalan. Baseline lama tetap menjadi backup valid dan tidak ditimpa.

## 4. Identitas runtime

Browser `/api/health`:

- database: project_bot_recovery_20261110
- user: aether_runtime
- schema: public
- market_observations: 513,381
- ingestion: disabled_for_recovery
- writer_active: false
- scheduler_active: false

Launcher writer sudah dipermanenkan di scripts/launch_ingestion_writer.php. Mode default tetap verify-only. DPAPI credential tidak dicetak. Kesesuaian DPAPI lintas akun Windows belum diklaim; konteks manual sebelumnya adalah DIMZZZ\\ASUS, sedangkan Task Scheduler memakai display account ASUS.

## 5. Perbaikan kode

| Fungsi | Status | Perbaikan / bukti |
|---|---|---|
| Test database isolation | WORKING_VERIFIED | phpunit.xml dan bootstrap test memaksa SQLite in-memory; tidak memakai recovery DB. |
| Health database identity | WORKING_VERIFIED | Health memakai SQL identity PostgreSQL dan guard SQLite untuk test. Browser membuktikan recovery DB/aether_runtime. |
| Health subsystem separation | WORKING_VERIFIED | checkAll memisahkan database, ingestion, AI model, Hermes, collector, Telegram, delivery. HTTP 200 tidak disamakan dengan semua sehat. |
| Running-trade health | WORKING_VERIFIED | State collector dibaca tanpa menganggap no-trade sebagai connection failure. Board tetap BOARD_SCOPE_UNVERIFIED. |
| WebSocket close handling | IMPLEMENTED_NOT_WIRED | Import close exceptions, close 4401/4403/4408 non-retryable, temporary error exponential backoff+jitter. Live collector belum dijalankan. |
| WebSocket parser/state | IMPLEMENTED_NOT_WIRED | Existing parser mempertahankan t/c/a/p/l/v/pc/tn, atomic state replace, session isolation, bounded dedup metadata. Live payload acceptance belum terbukti. |
| Midday RG safety | WORKING_VERIFIED | Laporan memakai `DATA_NOT_VALIDATED (Belum Memenuhi Syarat RG)` dan tidak mengganti data live dengan EOD. Test lulus. |
| Dashboard ticker/chart | WORKING_VERIFIED | Existing 7 tabs dan chart path diuji melalui feature tests; label test diselaraskan dengan UI aktual. |
| Gemini Google adapter | WORKING_VERIFIED (isolated) | Payload/schema, auth no-retry, redaction diuji dengan Http::fake. Provider smoke live tidak dijalankan. |
| Telegram trading plan | WORKING_VERIFIED (isolated) | RG guard, tick size, plan metrics, capability block diuji. Live Telegram E2E tetap PENDING. |

## 6. Test terisolasi

Command:

APP_CONFIG_CACHE=bootstrap/cache/testing-config.php php artisan test --filter='MiddaySessionReportTest|TelegramTradingPlanTest|GoogleGeminiDashboardAnalysisTest|DashboardTest'

Result:

- 33 tests passed
- 205 assertions
- duration 51.859 s
- database test: SQLite in-memory
- recovery database tidak dipakai

Python syntax:

- python -m py_compile services/idx_edge_ws_supervisor.py scripts/probe_ws.py: passed

Probe:

- python scripts/probe_ws.py: exit 0
- probe tidak membuktikan handshake/live trade acceptance.

## 7. WebSocket / running trade

Endpoint configured: wss://stock.arjum.com/ws/running-trade

Server header: X-API-Key dari IDX_EDGE_API_KEY. Key tidak masuk browser atau log.

Current state evidence dari browser:

- status: DISCONNECTED
- total_trades: 0
- last_message_at: null
- last_trade_at: null
- board_scope: BOARD_SCOPE_UNVERIFIED
- IHSG live tidak tersedia; UI memakai EOD acuan bertanggal atau N/A

Status acceptance: BLOCKED_EXTERNAL_CAPABILITY / NOT VERIFIED.

Belum ada bukti handshake live, close code provider, atau trade live. Tidak ada collector kedua yang dijalankan. Tidak ada klaim RG-only, top5 live, atau transaksi hilang. Tidak ada broadcast atau order.

## 8. Function matrix ringkas

- Overview, Market, ticker detail, chart, history, settings, health: WORKING_VERIFIED melalui route/browser/isolated tests; recovery data tetap read-only.
- Registry discovery, OHLCV raw/back-adjusted recovery, revision upsert, retry worker: IMPLEMENTED_NOT_WIRED / DISABLED_FOR_RECOVERY; tidak dijalankan.
- Running trade/top5 live: IMPLEMENTED_NOT_WIRED; live acceptance belum terbukti.
- IHSG live: NOT_IMPLEMENTED atau BLOCKED_EXTERNAL_CAPABILITY pada stream ini; tidak dihitung dari trade/top5.
- Telegram inbound/live delivery: DISABLED_FOR_RECOVERY; tidak ada numeric update_id dan outbound message_id baru.
- Gemini dashboard: isolated contract verified; live provider request tidak dijalankan.
- Yahoo fallback: tidak digunakan sebagai jalur aktif.
- Scheduler, writer, outbox, publication, monitoring writer, training, replay: DISABLED_FOR_RECOVERY.

## 9. Task/process state

Disabled:

- \\HermesMarketIntelligence_EOD
- \\AetherScheduledDispatcher_1min
- \\AetherWorkers_5min
- \\AetherMarketMonitoring_15min
- writer ingestion
- outbox/publication/Telegram broadcast
- Gemini live inference
- training/replay

Alasan: recovery DB dipertahankan tanpa mutation sampai controlled backup/write gate baru dibutuhkan dan lulus.

## 10. Pekerjaan tersisa

1. Verifikasi SID akun Task Scheduler terhadap SID akun yang dapat mendekripsi DPAPI.
2. Dapatkan backup baru dengan hak least-privilege yang cukup untuk dump seluruh target, atau tetapkan prosedur backup resmi terpisah tanpa grant luas.
3. Setelah backup lulus, jalankan canary staging discovery melalui launcher permanen dan aether_ingestion.
4. Pulihkan registry dan OHLCV per sesi/mode dengan dry-run, provenance, idempotensi, dan coverage gate.
5. Jalankan satu collector WebSocket setelah owner/single-instance gate lulus.
6. Buktikan handshake, close-code behavior, parser payload nyata, top5 mode, dan state tanggal/sesi.
7. Verifikasi Telegram hanya dengan payload inbound numeric update_id dan outbound message_id nyata.

Kesimpulan: pemeriksaan kode, isolasi test, health separation, read-only UI, baseline preservation, dan kontrak parser/collector sudah diverifikasi sesuai bukti di atas. Data recovery dan live WebSocket acceptance belum selesai dan tidak boleh disebut selesai tanpa gate eksternal yang belum terpenuhi.
