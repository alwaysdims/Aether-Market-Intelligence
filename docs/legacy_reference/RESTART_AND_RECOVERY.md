# PANDUAN RESTART, PEMULIHAN SISTEM & ROLLBACK (RESTART AND RECOVERY)
## AETHER MARKET INTELLIGENCE

Dokumen ini memuat prosedur operasional standar (SOP) setelah mesin Windows dihidupkan ulang (*reboot*), pemulihan celah pemantauan (*gap recovery*), dan prosedur pemulihan terpisah untuk kode, scheduler, dan database.

---

### 1. Komponen Otomatis Pasca-Restart Windows

Saat mesin Windows 11 di-restart atau dinyalakan kembali, komponen berikut berjalan secara otomatis tanpa intervensi manual:

1. **PostgreSQL Database Service:**
   - Status service Windows: `postgresql-x64-16` diatur ke `AUTO_START`.
   - Melayani koneksi pada `127.0.0.1:5432` dengan database `project-bot`.
2. **Hermes Gateway Service:**
   - Terpasang pada Windows Startup folder:
     `C:\Users\ASUS\AppData\Roaming\Microsoft\Windows\Start Menu\Programs\Startup\Hermes_Gateway.vbs`
   - Berjalan di latar belakang (Background WScript) menghubungkan Hermes CLI dan bot Telegram `@Info_BosKuu_bot`.
3. **Windows Task Scheduler Jobs:**
   - Task `\HermesMarketIntelligence_EOD`: Terdaftar aktif dengan jadwal Senin – Jumat pukul 16:05:00 WIB untuk eksekusi pipeline EOD.
   - Task `\AetherWorkers_5min`: Terdaftar aktif berjalan setiap 5 menit mengeksekusi `scripts\run_aether_workers.bat` (memproses antrean riset on-demand, outbox Telegram live, backfill registri BEI, dan narasi background).
   - Menggunakan konfigurasi daya tangguh:
     * `DisallowStartIfOnBatteries = False` (Dapat berjalan dengan daya baterai)
     * `StopIfGoingOnBatteries = False` (Tidak mati saat charger dicabut)
     * `WakeToRun = True` (Membangunkan mesin dari mode standby)
4. **Local AI 9Router Service:**
   - Melayani endpoint `http://127.0.0.1:20128/v1` dengan model `ag/gemini-3.8-flash-high`.
   - Dipanggil untuk chat Telegram interaktif dan riset emiten on-demand.

---

### 2. Langkah Pemeriksaan Diagnostik Mandiri

Setelah mesin menyala, jalankan pemeriksaan cepat untuk memastikan seluruh subsistem siap:

```bash
cd E:\Aether-Market-Intelligence
scripts\check_system.bat
```
Atau melalui artisan di sub-direktori `dashboard`:
```bash
cd E:\Aether-Market-Intelligence\dashboard
php artisan hermes:monitor
```

**Verifikasi Output:**
- PostgreSQL DB: `CONNECTED` (127.0.0.1:5432)
- Task Scheduler: `Ready (Next Run: 16:05 WIB)`
- Hermes Gateway: `RUNNING (PID tercatat)`
- Model Live AI: `DISABLED (Safe Heuristic Fallback)`
- Status Broker: `NO LIVE ORDERS (Research Only)`

---

### 3. Perintah Manual yang Diperlukan Jika Ingin Membuka Dashboard Web

Jika ingin mendemonstrasikan antarmuka grafis kepada dosen atau penguji:

```bash
cd E:\Aether-Market-Intelligence
scripts\start_dashboard.bat
```
Buka browser pada: `http://127.0.0.1:8000`.  
*Catatan: Script ini murni menjalankan server web Laravel tanpa memicu penulisan data atau pengiriman pesan keluar.*

---

### 4. Prosedur Pemulihan Terpisah (Separation of Concerns Rollback)

Sistem memisahkan mekanisme pemulihan menjadi 3 komponen independen:

#### A. Rollback Kode & Skrip
Folder lama `E:\Project-bot` dan `E:\hermes-stock-agent` dipertahankan utuh sebagai cadangan arsip steril. Jika diperlukan, seluruh pekerjaan dapat ditinjau ulang tanpa risiko kehilangan histori.

#### B. Rollback Windows Task Scheduler
Jika ingin mengalihkan task scheduler kembali ke runner lama:
```powershell
schtasks /change /tn "HermesMarketIntelligence_EOD" /tr "E:\Project-bot\run_eod_pipeline.bat"
```

#### C. Rollback Database PostgreSQL
Cadangan database sebelum perubahan Tahap I tersimpan secara aman di:
`E:\backups_pre_consolidation_20261021\project_bot_backup_tahap_i_20261025_094602.sql` (168.090 bytes).

Perintah pemulihan database (jika diperlukan darurat):
```bash
"C:\Program Files\PostgreSQL\16\bin\psql.exe" -h 127.0.0.1 -p 5432 -U postgres -d project-bot -f "E:\backups_pre_consolidation_20261021\project_bot_backup_tahap_i_20261025_094602.sql"
```
*(Catatan: Masukkan password postgres pada prompt tertutup saat diminta).*

---

### 5. Pengelolaan Plugin Aether Router & Pemulihan Operasional

1. **Pemasangan Ulang Plugin:**
   ```bash
   python E:\Aether-Market-Intelligence\services\aether_router\install.py
   ```
2. **Pencabutan Plugin (Rollback):**
   ```bash
   python E:\Aether-Market-Intelligence\services\aether_router\uninstall.py
   ```
3. **Runner Siklus Operasional Berkala:**
   Runner independen 24/7 (mengecek expiry 14 hari dan memproses antrean outbox dengan proteksi concurrency):
   ```bash
   E:\Aether-Market-Intelligence\scripts\run_operational_cycle.bat
   ```

---

### 6. Checklist Verifikasi Pasca-Reboot Fisik (Panduan Pengguna)

Sebelum pengguna me-restart komputer secara fisik, status pemulihan pasca-reboot adalah **PENDING**. Setelah reboot selesai, lakukan pengecekan berikut:
- [ ] 1. Buka Task Manager / PowerShell, pastikan PostgreSQL service berjalan di port 5432.
- [ ] 2. Periksa apakah proses Hermes Gateway aktif (`tasklist | findstr python`).
- [ ] 3. Jalankan `E:\Aether-Market-Intelligence\scripts\run_operational_cycle.bat` untuk memverifikasi antrean outbox dan rekonsiliasi expiry.
- [ ] 4. Kirim pesan Telegram `/status` ke `@Info_BosKuu_bot` untuk memverifikasi plugin `aether_router` otomatis aktif kembali.
- [ ] 5. Buka Task Scheduler Windows, pastikan task `\HermesMarketIntelligence_EOD` berstatus `Ready`.

---

### 7. Pemulihan & Rollback Modul Riset On-Demand (Multi-Emiten BEI)

1. **Cadangan Basis Data:**
   Tersimpan di `E:\Aether-Market-Intelligence\backups\backup_pre_ondemand_20261025.sql` (290.069 bytes).
2. **Rollback Migrasi Skema Riset On-Demand:**
   Jika ingin mencabut tabel `research_requests` dan kolom tambahan pada `market_observations`:
   ```bash
   cd E:\Aether-Market-Intelligence\dashboard
   php artisan migrate:rollback --step=1
   ```
3. **Emergency Kill-Switch Live AI:**
   Jika ingin mematikan panggilan AI tanpa mengubah kode:
   Ubah di `dashboard/.env`:
   ```ini
   AI_LIVE_INFERENCE_ON_DEMAND_ENABLED=false
   ```
4. **Pemrosesan Manual Antrean Riset:**
   ```bash
   php artisan aether:process-research-queue --limit=5
   ```

---

### 8. Pemulihan Modul Watchlist & Gateway Restart

1. **Cadangan Basis Data Terkini:**
   Tersimpan di `E:\Aether-Market-Intelligence\backups\backup_pre_watchlist_20261025.sql` (335.325 bytes).
2. **Rollback Migrasi Watchlist:**
   ```bash
   cd E:\Aether-Market-Intelligence\dashboard
   php artisan migrate:rollback --step=1
   ```
3. **Verifikasi Gateway tanpa membuat receiver kedua:** `hermes gateway status`; cocokkan PID, waktu start, hash plugin terpasang, dan log penerimaan dengan update_id. Jangan jalankan VBS kedua sebelum proses lama berhenti dan receiver tunggal dibuktikan.
4. **Rollback kode / scheduler / DB terpisah:** Jangan menjalankan `migrate:rollback --step=1` secara membabi buta: verifikasi `php artisan migrate:status` dan nama migrasi target, serta cek dump dan restorasi di database terisolasi terlebih dahulu. Backup file bukan bukti dapat direstorasi.
---

### 9. Worker Otomatis 5-Menit (Dispatcher Outbox + Riset On-Demand)

**Ditambahkan 26 Oktober 2026.**

Task Scheduler `AetherWorkers_5min` menjalankan `scripts/run_aether_workers.bat` setiap 5 menit. Bat ini menjalankan dua artisan command secara berurutan:
1. `aether:process-research-queue --limit=5` — memproses antrean riset pending.
2. `hermes:process-outbox --live` — memproses antrean outbox Telegram.

Log tersimpan di `E:\Aether-Market-Intelligence\logs\aether_workers.log`.

**Verifikasi:**
```bash
schtasks.exe /Query /TN "AetherWorkers_5min" /V /FO LIST
```
Pastikan `Last Result = 0` dan `Status = Ready`.

**Catatan:** Jangan buat scheduler per-menit — terlalu agresif dan memperlambat sistem. 5 menit cukup untuk outbox dan riset. EOD tetap dijalankan oleh `HermesMarketIntelligence_EOD` jam 16:05.

---

### 10. Pelurusan Arsitektur (Ringkasan Perubahan 25-26 Oktober 2026)

Perubahan kunci:
1. `CanonicalMarketReadService` — satu jalur baca harga kanonikal (Telegram, Overview, Market Monitor).
2. Config key `services.hermes.telegram_chat_id` diunifikasi ke `services.telegram.chat_id`.
3. Tool sintetis (`AiToolExecutorService`) ditandai `_synthetic => true` agar tidak mengotori tabel percakapan.
4. Outbox: klaim atomik, allowlist enforced, INBOUND_REPLY tanpa message_id numerik → SUPERSEDED_UNSENT.
5. Evidence reference: `overview.blade.php` pakai `features_snapshot['reference_close']` bukan kolom `market_observation_id` yang tidak ada.
6. Harga acuan analisis historis terpisah dari harga terkini di Overview.

Laporan lengkap: `reports/ARCHITECTURE_REALIGNMENT_ACCEPTANCE.md`.

---

### 11. Perluasan Asisten Saham & Portofolio (26 Oktober 2026)

1. **Cadangan Basis Data:**
   Tersimpan di `E:\Aether-Market-Intelligence\backups\backup_pre_expansion_20261026.sql` (446.469 bytes).
2. **Rollback Migrasi Portofolio:**
   ```bash
   cd E:\Aether-Market-Intelligence\dashboard
   php artisan migrate:rollback --step=1
   ```
3. **Environment Python Worker:**
   Executable Python di Laragon (`C:\laragon\bin\python\python-3.10\python.exe`) telah dilengkapi pustaka `yfinance` terverifikasi.
4. **Jalur Responsif Chat Riset:**
   Command `aether:execute-research` langsung memicu pengiriman outbox seketika riset selesai tanpa menunggu siklus 5-menit, sementara Task Scheduler `AetherWorkers_5min` tetap aktif sebagai mekanisme recovery jaring pengaman.

---

### 12. Pemulihan Integrasi IDX Edge

Integrasi IDX Edge memakai feature flag dan default mati. Setelah restart:

```bash
cd E:\Aether-Market-Intelligence\dashboard
php artisan config:clear
php artisan config:show services.idx_edge
hermes mcp list
hermes mcp test idx-edge
```

Jangan tampilkan atau salin nilai `IDX_EDGE_API_KEY`. Pemeriksaan MCP harus menunjukkan auth header aktif sebelum request data dianggap terotorisasi. Tool discovery saja bukan bukti autentikasi.

Kill switch provider:

```ini
IDX_EDGE_ENABLED=false
```

Lalu:

```bash
php artisan config:clear
```

Pemulihan dipisahkan:

1. Kode: nonaktifkan feature flag atau kembalikan file konektor. Jangan reset perubahan pengguna lain.
2. Scheduler: fase awal tidak menambah task baru. Jangan membuat jadwal ganda.
3. Database: fase awal tidak menambah migration. Row Yahoo fallback tetap tersedia.
4. Secret: bila key masuk chat/log/history, rotasi di dashboard provider; jangan memakai key lama.
5. Kuota: bila `DAILY_BUDGET_RESERVED`, hentikan fetch tambahan. Jangan mengurangi cadangan untuk memaksa laporan.
6. Rate limit: hormati `Retry-After`; bila tidak ada, gunakan backoff dan polling rendah.

Dashboard memakai `INTERNAL_POLLING` ke database Aether setiap 30 detik pada halaman aktif. Ini bukan stream IDX Edge. Saat endpoint internal gagal, data terakhir tetap tampil dengan status `DEGRADED • DATA TERSIMPAN`.

Dokumen terkait:

- `docs/DATA_PROVIDER_CONFIGURATION.md`
- `docs/REALTIME_DASHBOARD.md`
- `reports/IDX_EDGE_INTEGRATION_ACCEPTANCE.md`

---

### 13. Pipeline Seluruh Saham BEI (IDX 941 Emiten & Metrics Engine)

1. **Cadangan Basis Data:**
   Tersimpan di `E:\Aether-Market-Intelligence\backups\backup_pre_idx_all_20261026.sql` (460 KB).
2. **Perintah CLI Operasional:**
   - Sinkronisasi Registri 941 Emiten BEI:
     ```bash
     php artisan idx:sync-registry
     ```
   - Uji Canary 5 Sektor:
     ```bash
     php artisan idx:process-queue --canary
     ```
   - Pemrosesan Antrean Bertahap (Rate-Limited):
     ```bash
     php artisan idx:process-queue --limit=5
     ```
   - Ingestion Emiten Spesifik On-Demand:
     ```bash
     php artisan idx:process-queue --ticker=KIJA
     ```
3. **Penyelesaian Timezone & Timestamp:**
   Seluruh timestamp penulisan database dikonversi ke UTC eksplisit. Tampilan di Telegram dan Dashboard diformat zona waktu `Asia/Jakarta` (WIB) tanpa pergeseran ganda (+7 jam). Data masa depan (> now + 5m) otomatis ditandai `[AUDIT_ANOMALY: FUTURE_TIMESTAMP]`.
4. **Rollback Migrasi Expansion Tables:**
   ```bash
   cd E:\Aether-Market-Intelligence\dashboard
   php artisan migrate:rollback --step=1
   ```


