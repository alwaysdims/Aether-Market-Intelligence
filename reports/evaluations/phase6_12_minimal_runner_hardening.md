# LAPORAN FASE 6.12 — MINIMAL RUNNER HARDENING
## AETHER MARKET INTELLIGENCE

**Dokumen:** `reports/evaluations/phase6_12_minimal_runner_hardening.md`  
**Waktu Audit Aktual:** Minggu, 18 Oktober 2026, 19:15 WIB  
**Status Akhir:** **READY FOR SCHEDULE** (Hardening Runner Selesai, Failure Masking Tuntas, 5 Skenario Harness Lulus 100%)

---

### 1. KONDISI AWAL DAN CHECKSUM RUNNER

Sebelum modifikasi dilakukan:
- **Lokasi File Runner:** `E:\Project-bot\run_eod_pipeline.bat`
- **Ukuran File Awal:** 508 bytes
- **Checksum SHA-256 Awal:** `34990e5736561b5228682434ef2069c657d6739c6a287e985211f992aeb4390e`
- **Isi Kode Awal:**
  ```cmd
  @echo off
  REM Aether Market Intelligence - Direct EOD Pipeline Runner
  REM Scheduled at 16:05 WIB (Monday-Friday) on Windows Task Scheduler.

  cd /d "E:\Project-bot\laravel-app"
  "C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe" artisan hermes:sync-eod --trigger=scheduled >> "E:\Project-bot\laravel-app\storage\logs\eod_pipeline_output.log" 2>&1
  "C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe" artisan hermes:evaluate-horizon >> "E:\Project-bot\laravel-app\storage\logs\eod_pipeline_output.log" 2>&1
  ```
- **Setelah Modifikasi (Hardened):**
  * Ukuran File Baru: 1.975 bytes
  * Checksum SHA-256 Baru: `5d847cda2b73f9ca651478e10c7a745c640d204be945d139e4d9d5b38d495616`

---

### 2. AKAR MASALAH FAILURE MASKING

Pada runner lama, perintah eksekusi disusun secara sekuensial tanpa evaluasi nilai `%errorlevel%`:
1. Jika langkah 1 (`hermes:sync-eod`) gagal dengan exit code nonzero (misal 1 akibat koneksi putus atau timeout), `cmd.exe` tetap melanjutkan eksekusi baris berikutnya.
2. Langkah 2 (`hermes:evaluate-horizon`) tetap berjalan. Jika evaluasi mengembalikan exit code 0 (karena mendeteksi 20 record belum jatuh tempo), maka exit code proses batch diakhiri dengan nilai `0`.
3. Akibatnya, Windows Task Scheduler mencatat status `Last Result: 0x0` (Sukses), menyembunyikan kegagalan sinkronisasi data dari pemantauan operasional (*failure masking*).

---

### 3. RINGKASAN DIFF PERUBAHAN

Perubahan diterapkan secara presisi dan minimal pada `E:\Project-bot\run_eod_pipeline.bat`:
```diff
--- a/E:\Project-bot\run_eod_pipeline.bat
+++ b/E:\Project-bot\run_eod_pipeline.bat
@@ -1,7 +1,58 @@
 @echo off
-REM Aether Market Intelligence - Direct EOD Pipeline Runner
+REM Aether Market Intelligence - Direct EOD Pipeline Runner (Hardened)
 REM Scheduled at 16:05 WIB (Monday-Friday) on Windows Task Scheduler.
 
-cd /d "E:\Project-bot\laravel-app"
-"C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe" artisan hermes:sync-eod --trigger=scheduled >> "E:\Project-bot\laravel-app\storage\logs\eod_pipeline_output.log" 2>&1
-"C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe" artisan hermes:evaluate-horizon >> "E:\Project-bot\laravel-app\storage\logs\eod_pipeline_output.log" 2>&1
+set APP_DIR=E:\Project-bot\laravel-app
+set LOG_FILE=%APP_DIR%\storage\logs\eod_pipeline_output.log
+set PHP_BIN=C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe
+
+if not exist "%APP_DIR%" (
+    echo [%date% %time%] [FATAL] Direktori kerja tidak ditemukan: %APP_DIR%
+    exit /b 1
+)
+
+cd /d "%APP_DIR%"
+if errorlevel 1 (
+    echo [%date% %time%] [FATAL] Gagal berpindah ke direktori: %APP_DIR%
+    exit /b 1
+)
+
+if not exist "%PHP_BIN%" (
+    echo [%date% %time%] [FATAL] PHP executable tidak ditemukan: %PHP_BIN% >> "%LOG_FILE%" 2>&1
+    exit /b 2
+)
+
+REM Verifikasi keberadaan direktori log
+set LOG_DIR=%APP_DIR%\storage\logs
+if not exist "%LOG_DIR%" (
+    mkdir "%LOG_DIR%" 2>nul
+    if errorlevel 1 (
+        echo [%date% %time%] [FATAL] Direktori log tidak dapat dibuat: %LOG_DIR%
+        exit /b 3
+    )
+)
+
+echo =============================================================================== >> "%LOG_FILE%" 2>&1
+if errorlevel 1 (
+    echo [%date% %time%] [FATAL] Gagal menulis ke log file: %LOG_FILE%
+    exit /b 3
+)
+echo [START] EOD Pipeline: %date% %time% >> "%LOG_FILE%" 2>&1
+echo =============================================================================== >> "%LOG_FILE%" 2>&1
+
+"%PHP_BIN%" artisan hermes:sync-eod --trigger=scheduled >> "%LOG_FILE%" 2>&1
+set SYNC_EXIT=%errorlevel%
+if %SYNC_EXIT% neq 0 (
+    echo [%date% %time%] [ERROR] hermes:sync-eod gagal dengan exit code %SYNC_EXIT%. Evaluasi horizon dibatalkan. >> "%LOG_FILE%" 2>&1
+    exit /b %SYNC_EXIT%
+)
+
+"%PHP_BIN%" artisan hermes:evaluate-horizon >> "%LOG_FILE%" 2>&1
+set EVAL_EXIT=%errorlevel%
+if %EVAL_EXIT% neq 0 (
+    echo [%date% %time%] [ERROR] hermes:evaluate-horizon gagal dengan exit code %EVAL_EXIT%. >> "%LOG_FILE%" 2>&1
+    exit /b %EVAL_EXIT%
+)
+
+echo [FINISH] EOD Pipeline Selesai Sukses: %date% %time% >> "%LOG_FILE%" 2>&1
+exit /b 0
```

---

### 4. HASIL SETIAP SKENARIO PENGUJIAN ISOLASI BESERTA EXIT CODE

Pengujian dilakukan menggunakan harness pengujian terisolasi `run_isolated_tests_6_12.py` pada direktori scratch tanpa mengeksekusi pipeline produksi secara prematur:

| Skenario Pengujian | Hasil Exit Code | Output Log Diagnostik | Status |
| :--- | :--- | :--- | :--- |
| **Skenario 1: Sync OK & Eval OK** | `0` | Kedua langkah dieksekusi lengkap; memuat marker `[START]` dan `[FINISH]`. | **VERIFIED PASS** |
| **Skenario 2: Sync Gagal (exit 1)** | `1` | Langkah 2 **dibatalkan total**; mencatat: `[ERROR] hermes:sync-eod gagal dengan exit code 1. Evaluasi horizon dibatalkan.` | **VERIFIED PASS** |
| **Skenario 3: Sync OK & Eval Gagal (exit 5)** | `5` | Langkah 1 sukses; langkah 2 gagal exit 5; mencatat: `[ERROR] hermes:evaluate-horizon gagal dengan exit code 5.` Marker `[FINISH]` tidak dicetak. | **VERIFIED PASS** |
| **Skenario 4: PHP Binary Tidak Ditemukan** | `2` | Terdeteksi sebelum command berjalan; mencatat: `[FATAL] PHP executable tidak ditemukan.` | **VERIFIED PASS** |
| **Skenario 5: Direktori Log Gagal Dibuat/Ditulis** | `3` | Output stderr/stdout menampilkan: `[FATAL] Direktori log tidak dapat dibuat.` Tidak ada klaim sukses palsu. | **VERIFIED PASS** |

---

### 5. BUKTI LANGKAH KEDUA TIDAK BERJALAN SETELAH LANGKAH PERTAMA GAGAL

Berdasarkan log hasil eksekusi Skenario 2 pada harness:
```text
=============================================================================== 
[START] EOD Pipeline: Sun 10/18/2026 19:10:52.60 
=============================================================================== 
[MOCK] Step execution FAILED with code 1
[Sun 10/18/2026 19:10:52.68] [ERROR] hermes:sync-eod gagal dengan exit code 1. Evaluasi horizon dibatalkan. 
```
- Bukti faktual: Jumlah eksekusi mock step tercatat tepat 1 kali.
- Evaluasi horizon tidak pernah dipanggil, dan exit code yang dikembalikan ke sistem pemanggil adalah exit code asli dari langkah pertama (`1`).

---

### 6. VERIFIKASI ACTION TASK SCHEDULER

Pemeriksaan ulang konfigurasi Task Scheduler Windows via `schtasks /query /v`:
- **Task Name:** `\HermesMarketIntelligence_EOD`
- **Status:** `Ready`
- **Next Run Time:** `10/19/2026 4:05:00 PM`
- **Action / Task To Run:** `E:\Project-bot\run_eod_pipeline.bat`
- **Kesimpulan:** Action Task Scheduler terverifikasi tetap menunjuk ke file runner yang sama tanpa memerlukan perubahan konfigurasi task pada sistem operasi.

---

### 7. STATUS AI LIVE DAN BUKTI TIDAK ADA REQUEST 9ROUTER

- **Konfigurasi Operasional (`.env`):** `AI_LIVE_INFERENCE_ENABLED=false`.
- **Runtime Laravel Evaluation:** `config('services.ai_router.live_inference_enabled') = false`.
- **Pemeriksaan Argumen Runner:** Skrip `run_eod_pipeline.bat` terbukti **TIDAK** memuat flag `--live-ai` maupun `--force`.
- **Proteksi Gate:** `AiMarketAnalysisService::analyze()` otomatis mengalihkan proses ke fallback tanpa membuka koneksi jaringan HTTP ke port 20128.
- **SQLite 9Router:** Tidak ada penambahan entri transaksi pada `usageHistory`.

---

### 8. STATUS BACKUP DAN INTEGRITAS DATA HISTORIS

1. **Keberadaan Backup Database:**
   - File: `E:\Project-bot\storage\backups\project_bot_backup_pre_schedule_20261018.sql`
   - Ukuran: 87.055 bytes (SHA-256: `be3f24d894e145aa523aec9492eff812f3f03bd7143c791c8fdbb67f902aa3f6`).
   - Telah lulus uji restore pada Fase 6.10 dan aman dari tracking Git via `.gitignore`.
2. **Integritas 20 Record Evaluasi:**
   - Status: 20/20 `pending`.
   - Flag fallback: 20/20 `is_fallback = true` dan `validation_status = 'fallback'`.
   - Nilai outcome: 20/20 `outcome = NULL`, `actual_price = NULL`, `actual_return_pct = NULL`.
   - Tanggal target: 20/20 `2026-10-21`.
   - Tanggal keputusan: 20/20 `2026-10-14`.
   - Nol modifikasi pada database produksi selama fase hardening ini.

---

### 9. HASIL PENGUJIAN REGRESI MASTER & GIT STATUS

1. **Hasil Test Suite Aktual:**
   - **Laravel PHPUnit:** **60 LULUS (194 assertions, durasi 140,5 detik, exit code 0)**.
   - **Python Adapter Service:** **6 LULUS (durasi 8,6 detik, exit code 0)**.
   - **Hermes Stock Agent Baseline:** **64 LULUS (durasi 55,5 detik, exit code 0)**.
   - **Total Kumulatif:** **130 / 130 PENGUJIAN LULUS (100%)**.
2. **Status Git:**
   - Repositori quant `E:\hermes-stock-agent` tidak dimodifikasi.
   - Perubahan file di `E:\Project-bot` terbatas pada konfigurasi pra-jadwal yang telah divalidasi dan file runner `run_eod_pipeline.bat`.
   - **Nol commit dan nol push Git dibuat.**

---

### 10. RISIKO TERSISA DAN CHECKLIST OPERASIONAL SENIN 19 OKTOBER 2026

1. **Risiko Daya Listrik (Power Management):**
   - Task Scheduler memiliki opsi `No Start On Batteries`.
   - **Wajib:** Laptop harus terhubung ke pengisi daya AC sebelum pukul 16:05 WIB.
2. **Checklist Pra-Jadwal:**
   - [x] Hardening runner batch selesai dan teruji (Lolos).
   - [x] Action Task Scheduler menunjuk ke runner yang tepat (Lolos).
   - [x] Proteksi live AI aktif (`AI_LIVE_INFERENCE_ENABLED=false`) (Lolos).
   - [x] Backup database tersedia dan teruji (Lolos).
   - [ ] Hubungkan laptop ke charger listrik sebelum pukul 16:00 WIB pada hari Senin.
   - [ ] Pastikan workstation dalam kondisi aktif (tidak sleep/shutdown) pada pukul 16:05 WIB.
3. **Pemeriksaan Pasca-Jadwal (Senin Sore > 16:05 WIB):**
   - Cek riwayat scheduler: `schtasks /query /tn "HermesMarketIntelligence_EOD" /v`.
   - Cek log hasil eksekusi: `tail -n 30 "E:\Project-bot\laravel-app\storage\logs\eod_pipeline_output.log"`.
   - Cek audit 6 pilar: `cd /d E:\Project-bot\laravel-app && php artisan hermes:monitor`.

---

### 11. KEPUTUSAN STATUS AKHIR

**STATUS: READY FOR SCHEDULE**

**Dasar Keputusan:**
1. Masalah failure masking pada runner produksi telah diperbaiki secara minimal dan tuntas.
2. Seluruh 5 skenario kegagalan dan keberhasilan telah lulus pengujian isolasi 100%.
3. Task Scheduler Windows tetap terarah ke runner yang benar tanpa perlu modifikasi task OS.
4. Seluruh 130 pengujian regresi lintas repositori lulus 100%.
5. Mode live AI dinonaktifkan secara aman, data evaluasi historis 100% terjaga, dan backup database aman dari Git.
6. Tidak ada commit atau push Git yang dilakukan.
