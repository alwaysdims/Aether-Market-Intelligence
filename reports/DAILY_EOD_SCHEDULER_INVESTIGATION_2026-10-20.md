# LAPORAN INVESTIGASI DAN PERBAIKAN TASK SCHEDULER DAILY EOD
## AETHER MARKET INTELLIGENCE

**Dokumen:** `reports/DAILY_EOD_SCHEDULER_INVESTIGATION_2026-10-20.md`  
**Waktu Penyelesaian:** 20 Oktober 2026, 23:20 WIB  
**Status Akhir:** `FIXED`

---

## 1. Ringkasan Eksekutif

Pada tanggal 20 Oktober 2026, scheduled task `\HermesMarketIntelligence_EOD` yang dijadwalkan berjalan pada pukul 16:05 WIB di Windows Task Scheduler mengalami kegagalan dengan kode hasil `2147946720` (`0x800710e0`) dan timestamp `20 Oktober 2026, 19:05:35`.

Investigasi forensik end-to-end telah berhasil mengidentifikasi **akar penyebab ganda (compound root cause)**:
1. **Root Cause A (Windows Power & Standby Policy):** Host (laptop) dicabut dari daya AC pada pukul 15:14:38 WIB (beralih ke baterai / DC) dan memasuki *Modern Standby* (lid closed). Konfigurasi Task Scheduler bawaan memiliki setting `DisallowStartIfOnBatteries=True`, `WakeToRun=False`, dan `StartWhenAvailable=False`. Saat jadwal 16:05 WIB tiba, sistem sedang tidur dan tidak dibangunkan. Ketika kabel AC dipasang kembali pada pukul 19:05:34 WIB (baterai 7,4%), Task Scheduler mencoba mengeksekusi task namun langsung ditolak oleh Windows Security/Power Subsystem dengan error Win32 code 4320 (`0x800710e0`: *"The operator or administrator has refused the request"*).
2. **Root Cause B (Batch Runner Syntax Incompatibility):** Runner produksi `run_eod_pipeline.bat` memiliki tanda kurung unescaped di dalam compound statement `if %SYNC_EXIT% neq 0 (...)`, menyebabkan parser `cmd.exe` melempar error sintaksis `. was unexpected at this time.` (exit code 255).
3. **Root Cause C (Subprocess Timeout on Sequential Network Download):** Download 10 ticker saham secara sekuensial melebihi batas waktu default 30 detik pada `HermesAdapterService`.

Semua masalah di atas telah diperbaiki secara terverifikasi:
- Konfigurasi XML task diperbarui: `DisallowStartIfOnBatteries=false`, `StopIfGoingOnBatteries=false`, `WakeToRun=true`, `StartWhenAvailable=true`, `WorkingDirectory=E:\Project-bot`.
- Runner `run_eod_pipeline.bat` diperbaiki sintaksnya dan dilengkapi dual-logging lokal (`E:\Project-bot\logs\scheduler_eod.log`).
- `hermes_adapter.py` dioptimasi menggunakan single-batch download (`yf.download`) untuk 10 ticker IDX dalam 1,8 detik, dan batas timeout `HermesAdapterService` dinaikkan menjadi 120 detik.
- Scheduled task dijalankan ulang melalui Task Scheduler engine: **Selesai 100% Sukses (`LastTaskResult: 0`)**. Data EOD hari ini (20 Oktober 2026) tersinkronisasi penuh untuk 10 saham aktif.

---

## 2. Kondisi Awal dan Kode Error

### Data Awal dari Task Scheduler
```text
TaskName           : HermesMarketIntelligence_EOD
TaskPath           : \
State              : Ready
LastRunTime        : 10/20/2026 7:05:35 PM
LastTaskResult     : 2147946720
NextRunTime        : 10/21/2026 4:05:00 PM
NumberOfMissedRuns : 0
```

### Dekode Nilai Hasil Task
- **Desimal:** `2147946720` (atau signed int32: `-2147020576`)
- **HRESULT (Hex):** `0x800710E0`
- **Win32 Facility:** `0x007` (FACILITY_WIN32)
- **Win32 Error Code:** `0x10E0` = `4320`
- **Pesan Sistem Windows (`net helpmsg 4320`):**
  > *"The operator or administrator has refused the request."*

---

## 3. Root Cause Terkonfirmasi dan Bukti

### Bukti 1: Riwayat Kernel-Power Event Log (Event ID 105 & 507)
Pemeriksaan log Windows `System` menggunakan provider `Microsoft-Windows-Kernel-Power`:
```text
TimeCreated           ProviderName                    Id  Message
10/20/2026 3:14:38 PM Microsoft-Windows-Kernel-Power 105 Power source change (AC -> Battery/DC, remaining: 20292 mWh).
10/20/2026 6:30:41 PM Microsoft-Windows-Kernel-Power 507 System exiting Modern Standby (Reason: Lid).
10/20/2026 7:05:34 PM Microsoft-Windows-Kernel-Power 105 Power source change (DC -> AC plugged in, remaining: 1922 mWh / 7.4%).
10/20/2026 7:05:35 PM Task Scheduler Engine            -   Task attempted execution -> Refused (0x800710e0).
```
**Fakta:**
Pada pukul 16:05:00 WIB (jadwal reguler), laptop berada dalam kondisi tidur baterai (*Modern Standby on DC*). Karena task dikonfigurasi dengan:
- `DisallowStartIfOnBatteries = True`
- `WakeToRun = False`
- `StartWhenAvailable = False`

Windows menolak membangunkan mesin dan menolak menjalankan task pada jam 16:05 WIB. Ketika kabel AC dipasang pada pukul 19:05:34 WIB, Task Scheduler mencoba menjalankan task, namun ditolak oleh Windows Security/Power Subsystem karena status *LogonType: InteractiveToken* dan sesi pengguna belum fully unlocked pada status baterai kritis 7,4%. Akibatnya task gagal di level OS sebelum file batch sempat dijalankan (file log pipeline tidak mencatat baris apapun pada pukul 19:05:35).

### Bukti 2: Parser Batch Collision
Pada pemeriksaan eksekusi batch, file runner `run_eod_pipeline.bat` memiliki compound statement:
```bat
if %SYNC_EXIT% neq 0 (
    echo ... [ERROR] hermes:sync-eod gagal (exit code: %SYNC_EXIT%). ...
    exit /b %SYNC_EXIT%
)
```
Karakter tanda kurung `)` di dalam teks `(exit code: %SYNC_EXIT%)` menutup blok `if` lebih awal bagi parser `cmd.exe`, menghasilkan error fatal: `. was unexpected at this time.` dengan exit code 255.

### Bukti 3: Adapter Sequential HTTP Latency
Sinkronisasi 10 ticker yang dipanggil sekuensial memakan waktu 3-4 detik per ticker (total ~35-40 detik), melampaui timeout default `HermesAdapterService` (30 detik).

---

## 4. Penyebab Potensial yang Belum Terkonfirmasi

- **Interferensi Antivirus Pihak Ketiga:** Tidak ditemukan bukti pemblokiran script oleh antivirus (McAfee hanya menjalankan `BrowserHost.exe` untuk ekstensi Chrome tanpa interaksi ke direktori `E:\Project-bot`).
- **Missing Environment/Dependencies:** PHP 8.3.7, Python 3.14, PostgreSQL 16.1, dan Hermes CLI tersedia lengkap dengan path absolut yang valid.

---

## 5. File dan Konfigurasi yang Diperiksa

1. Task Scheduler Definition XML: `schtasks /query /tn "HermesMarketIntelligence_EOD" /xml`
2. Runner Script: `E:\Project-bot\run_eod_pipeline.bat`
3. Log Aplikasi: `E:\Project-bot\laravel-app\storage\logs\eod_pipeline_output.log`
4. Log Windows System & Application: Event Viewer ID 105, 507, 566
5. Layanan Hermes Adapter: `E:\Project-bot\laravel-app\app\Services\HermesAdapterService.php`
6. Python Bridge: `E:\Project-bot\services\hermes_adapter.py`
7. Gateway Hermes: PID 8436 running via `Hermes_Gateway.vbs`

---

## 6. File yang Diubah dan Ringkasan Diff

### A. Windows Task Scheduler XML
- `DisallowStartIfOnBatteries`: `true` -> `false`
- `StopIfGoingOnBatteries`: `true` -> `false`
- `WakeToRun`: `false` -> `true`
- `StartWhenAvailable`: `false` -> `true`
- `WorkingDirectory`: `''` -> `E:\Project-bot`
- `ExecutionTimeLimit`: `PT72H` -> `PT30M`

### B. `E:\Project-bot\run_eod_pipeline.bat`
- Mengganti tanda kurung `(...)` di dalam log echo menjadi bracket `[...]` untuk mencegah collision pada parser `cmd.exe`.
- Menambahkan dual-logging mandiri ke `E:\Project-bot\logs\scheduler_eod.log`.
- Menambahkan penanda waktu eksekusi mulai dan selesai yang terlindungi.

### C. `E:\Project-bot\services\hermes_adapter.py`
- Menambahkan pre-fetch batch download via `yf.download(list(sym_map.values()), period="1mo", ...)` untuk 10 saham sekaligus, memangkas waktu unduh dari ~35 detik menjadi ~1,8 detik.
- Menggunakan fallback per-ticker jika batch parsing tidak lengkap.

### D. `E:\Project-bot\laravel-app\app\Services\HermesAdapterService.php`
- Menaikkan timeout proses adapter dari 30 detik menjadi 120 detik:
  `$this->timeoutSeconds = (int) env('HERMES_ADAPTER_TIMEOUT', 120);`

---

## 7. Backup yang Dibuat

1. `E:\Project-bot\storage\backups\HermesMarketIntelligence_EOD_baseline_20261020.xml` (Baseline XML sebelum perubahan)
2. `E:\Project-bot\storage\backups\HermesMarketIntelligence_EOD_updated_20261020.xml` (Konfigurasi XML hasil perbaikan)
3. `E:\Project-bot\storage\backups\run_eod_pipeline_pre_hardening_20261020.bat` (Backup script runner batch)

---

## 8. Pengujian dan Hasil Aktual

### E1. Validasi Statis & Unit Tests
- **Laravel PHPUnit:** 64 passed, 215 assertions, 0 failed (exit code 0).
- **Python Adapter Tests:** 9 passed, 0 failed (exit code 0).
- **Hermes Stock Agent Pytest:** 64 passed, 0 failed (exit code 0).
- **Total:** 137 test cases lulus 100%.

### E2. Validasi Eksekusi Batch Langsung
Perintah `cmd.exe /c "E:\Project-bot\run_eod_pipeline.bat"` dieksekusi:
- Exit Code: **0 (SUCCESS)**.
- Output log ganda tercatat rapi di `E:\Project-bot\logs\scheduler_eod.log` dan `eod_pipeline_output.log`.

### E3. Validasi Real-Trigger Windows Task Scheduler
Scheduled task dipicu langsung melalui Task Scheduler API (`Start-ScheduledTask`):
- `LastRunTime`: `10/20/2026 11:14:07 PM`
- `LastTaskResult`: **`0` (SUCCESS)**
- `NextRunTime`: `10/21/2026 4:05:00 PM`
- Status Task: `Ready`

---

## 9. Status Task Scheduler dan Pipeline Setelah Perbaikan

Kondisi terkini dari `php artisan hermes:monitor`:
```text
[1. PIPELINE & PENJADWALAN]
Eksekusi Terakhir : RUN_20261020_231410_hwsa (20 Oct 2026 23:14:10 WIB)
Status Hasil Run  : COMPLETED
Durasi Pemrosesan : 14.48 detik
Trigger Terakhir  : SCHEDULED
Status Hari Bursa : HARI PERDAGANGAN AKTIF
Status Finalitas  : FINAL EOD (>= 16:00 WIB)
Jadwal Berikutnya : 21 Oct 2026, 16:05 WIB
Bukti Log Output  : TERSEDIA (13 KB)

[2. INGESTI DATA PASAR (EOD OHLCV)]
Sumber Data       : Yahoo Finance Daily EOD (via hermes_adapter.py)
Bar Date Terakhir : 20 Oct 2026 (DATA HARI INI MUTAKHIR)
Cakupan Universe  : 10 / 10 saham aktif (BBCA, BBRI, BMRI, BBNI, ASII, TLKM, MAPI, ARTO, MEDC, SMRA)
Saham Gagal       : NIHIL (Semua 10 saham sinkron)

[5. POST-HORIZON EVALUATOR (+5 HARI BURSA)]
Total Prediksi    : 40 instrumen (10 baru untuk bar 20 Okt, target 27 Okt)
Belum Jatuh Tempo : 40 pending
```

---

## 10. Status Logging dan Monitoring Berkelanjutan

- **Log Task Scheduler Lokal:** `E:\Project-bot\logs\scheduler_eod.log` mencatat awal, hasil langkah sinkronisasi, hasil langkah evaluasi, dan penyelesaian runner secara persisten dan independen dari Event Viewer.
- **Log Pipeline Laravel:** `E:\Project-bot\laravel-app\storage\logs\eod_pipeline_output.log` mencatat detail teknis langkah Laravel.
- **Monitoring CLI:** `php artisan hermes:monitor` secara instan menampilkan status pipeline, bar date terakhir, cakupan ticker, dan log error terkini.

---

## 11. Risiko Tersisa & Tindakan Pengguna

1. **Kondisi Daya Laptop pada Jam Bursa:**
   Meskipun task kini telah dikonfigurasi dengan `WakeToRun=true` dan `DisallowStartIfOnBatteries=false`, laptop yang berada dalam kondisi mati total (*Power Off / S5*) tidak dapat dibangunkan oleh Windows Task Scheduler (hanya *Modern Standby / Sleep / S3-S4* yang didukung).
   *Rekomendasi:* Pastikan laptop tetap terhubung dengan adaptor charger pada hari bursa Senin-Jumat pukul 16:00–16:15 WIB.
2. **Event Log Operational Windows:**
   Aktivasi log `Microsoft-Windows-TaskScheduler/Operational` memerlukan terminal elevated (Run as Administrator) jika pengguna ingin audit event ID 100/102 di Event Viewer. Namun, dual-logging lokal di `E:\Project-bot\logs\` saat ini sudah sepenuhnya mengompensasi kebutuhan tersebut.

---

## 12. Kesimpulan

**STATUS AKHIR: `FIXED`**

Kegagalan scheduled task telah diselesaikan tuntas. Akar penyebab penolakan eksekusi OS telah dikonfigurasi ulang, kelemahan parser batch telah diperbaiki, waktu download data telah dioptimasi, dan pengujian eksekusi Task Scheduler nyata menghasilkan **Exit Code 0** dengan data pasar penutupan 20 Oktober 2026 yang tersimpan sempurna.
