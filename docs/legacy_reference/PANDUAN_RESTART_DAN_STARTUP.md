# PANDUAN STARTUP DAN OPERASIONAL SETELAH RESTART WORKSTATION
## AETHER MARKET INTELLIGENCE & HERMES STOCK AGENT

Dokumen: `E:\Project-bot\docs\PANDUAN_RESTART_DAN_STARTUP.md`  
Target Sistem: Windows 11 Workstation (Lokal On-Premise)  

---

### 1. RINGKASAN STATUS KOMPONEN SAAT REBOOT

Saat laptop dimatikan (shutdown) dan Anda login kembali ke Windows:

| Komponen Sistem | Status Otomatis Saat Login | Tindakan Pengguna |
| :--- | :--- | :--- |
| **PostgreSQL 16 (Port 5432)** | **OTOMATIS AKTIF** (Windows Service `AUTO_START`) | Tidak perlu dijalankan manual. |
| **Hermes Gateway (Telegram)** | **OTOMATIS AKTIF** (Via Startup `Hermes_Gateway.vbs`) | Berjalan di background; memproses cron Telegram. |
| **Windows Task Scheduler (EOD)** | **OTOMATIS AKTIF** (Task `HermesMarketIntelligence_EOD`) | Berjalan otomatis Senin-Jumat 16:05 WIB (wajib colok charger AC). |
| **9Router AI Proxy (Port 20128)** | **MANUAL** (Perlu dijalankan jika ingin AI aktif) | Buka terminal / command prompt, ketik: `9router -t`. |
| **Dashboard Web Laravel (Opsional)** | **MANUAL** (Hanya jika ingin melihat UI browser) | `cd E:\Project-bot\laravel-app && php artisan serve`. |

---

### 2. LANGKAH-LANGKAH MENJALANKAN SISTEM SETELAH LOGIN

#### Langkah 1: Pastikan Laptop Terhubung Charger (AC Power)
Windows Task Scheduler memiliki aturan penghemat baterai (`No Start On Batteries`). Jika laptop menggunakan baterai, scheduler jam 16:05 WIB tidak akan berjalan. Pastikan charger tercolok.

#### Langkah 2: Jalankan 9Router (Proxy AI Lokal)
Buka terminal baru (Git Bash / PowerShell / Command Prompt), lalu ketik:
```bash
9router -t
```
*Catatan:*
- Flag `-t` (tray) akan menjalankan 9Router di latar belakang (background / system tray).
- Port 20128 akan otomatis terbuka di `http://127.0.0.1:20128`.

#### Langkah 3: Verifikasi Kesehatan Sistem (Pemeriksaan 1 Menit)
Buka terminal di direktori Laravel:
```bash
cd E:\Project-bot\laravel-app
php artisan hermes:monitor
```
Pemeriksaan ini mengaudit 6 pilar secara instan:
1. Database PostgreSQL terhubung.
2. 9Router endpoint `:20128` berstatus HEALTHY.
3. Bot Telegram `@Info_BosKuu_bot` siap.
4. Snapshot pasar dan berita terkini sinkron.
5. 20 record evaluasi berstatus pending (anti-lookahead).

#### Langkah 4 (Opsional): Menjalankan Web Dashboard
Jika Anda ingin memantau antarmuka visual (overview, history, system health) melalui browser:
```bash
cd E:\Project-bot\laravel-app
php artisan serve
```
Lalu buka browser di: `http://127.0.0.1:8000`.

---

### 3. SKENARIO PENGUJIAN & EKSEKUSI MANUAL

#### A. Ingin Menjalankan Pipeline EOD Manual (Di luar Jadwal Otomatis)
Jika Anda ingin menyinkronkan data pasar dan berita secara langsung tanpa menunggu jam 16:05 WIB:
```bash
cd E:\Project-bot\laravel-app
php artisan hermes:sync-eod --force
```
*Catatan:* Flag `--force` mengabaikan validasi jam bursa untuk pengujian manual.

#### B. Ingin Mengaktifkan Inferensi Live AI Resmi
Jika Anda telah siap menggunakan kuota AI Gemini 3.8 Flash High melalui 9Router:
1. Buka file `E:\Project-bot\laravel-app\.env`.
2. Ubah parameter:
   ```ini
   AI_LIVE_INFERENCE_ENABLED=true
   ```
3. Bersihkan cache konfigurasi:
   ```bash
   cd E:\Project-bot\laravel-app
   php artisan config:clear
   ```

#### C. Memeriksa Log Hasil Penjadwalan Otomatis
Setelah pukul 16:05 WIB pada hari kerja, periksa hasil eksekusi otomatis dengan perintah:
```bash
tail -n 30 "E:\Project-bot\laravel-app\storage\logs\eod_pipeline_output.log"
```

---

### 4. CEKLIST TANGGAL KRUSIAL
- **Senin, 19 Oktober 2026 pukul 16:05 WIB:** Eksekusi pertama Task Scheduler hari bursa.
- **Rabu, 21 Oktober 2026 pukul 16:00 WIB:** Tanggal jatuh tempo evaluasi horizon 5 hari bursa untuk 20 prediksi awal.
