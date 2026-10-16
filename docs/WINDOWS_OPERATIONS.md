# PANDUAN OPERASIONAL & PENJADWALAN WINDOWS
## AETHER MARKET INTELLIGENCE

**Versi:** 2.0.0 (Rebranded from Hermes Market Intelligence)  
**Repositori Resmi:** [https://github.com/alwaysdims/Aether-Market-Intelligence](https://github.com/alwaysdims/Aether-Market-Intelligence)  
**Tanggal:** 17 Oktober 2026  
**Target Lingkungan:** Windows 11 On-Premise Workstation  

---

## 1. PENDAHULUAN & ARSITEKTUR OTOMASI

Aether Market Intelligence mengoperasikan pipeline analitik harian pasar modal Indonesia (BEI) secara otomatis tanpa ketergantungan pada server cloud eksternal. Otomasi diatur mengikuti ritme resmi perdagangan Bursa Efek Indonesia:
- **09:00 - 15:50 WIB:** Sesi perdagangan aktif (Intraday).
- **16:00 WIB:** Pasar resmi ditutup (Candle harian dinyatakan FINAL).
- **16:05 WIB:** Eksekusi otomatis pipeline EOD (Download OHLCV, feed berita, inferensi AI, dan pendaftaran prediksi).
- **16:15 WIB:** Eksekusi evaluasi post-horizon 5 hari bursa (Penyelesaian prediksi yang jatuh tempo).

---

## 2. METODE PENJADWALAN DI WINDOWS 11

### Pilihan A: Menggunakan Windows Task Scheduler (Disarankan untuk Produksi Lokal)

Windows Task Scheduler dapat mengeksekusi batch script `run_eod_pipeline.bat` secara andal tanpa memerlukan terminal terbuka.

#### Perintah Pendaftaran CLI (Administrator Prompt / PowerShell):
```cmd
schtasks /create /tn "HermesMarketIntelligence_EOD" /tr "E:\Project-bot\run_eod_pipeline.bat" /sc weekly /d MON,TUE,WED,THU,FRI /st 16:05 /ru "%USERNAME%"
```

#### Pengaturan Melalui GUI Task Scheduler (`taskschd.msc`):
1. Buka **Task Scheduler** di Windows.
2. Klik **Create Task...**
3. Pada tab **General**:
   - Nama: `HermesMarketIntelligence_EOD`
   - Pilih `Run only when user is logged on` atau `Run whether user is logged on or not`.
4. Pada tab **Triggers**:
   - Klik **New...** -> Pilih **Weekly**.
   - Centang **Monday, Tuesday, Wednesday, Thursday, Friday**.
   - Set waktu mulai: **16:05:00**.
5. Pada tab **Actions**:
   - Action: **Start a program**.
   - Program/script: `E:\Project-bot\run_eod_pipeline.bat`.
   - Start in (opsional): `E:\Project-bot\`.
6. Pada tab **Settings**:
   - Centang `If the task fails, restart every: 5 minutes` (Max 2 kali).
   - Centang `Stop the task if it runs longer than: 1 hour`.

---

### Pilihan B: Menjalankan Laravel Scheduler Worker (Background Runner)

Jika ingin scheduler internal Laravel (`routes/console.php`) yang mengatur menit per menit:
```cmd
schtasks /create /tn "Hermes_Laravel_Scheduler" /tr "E:\Project-bot\run_scheduler.bat" /sc minute /mo 1 /ru "%USERNAME%"
```
Script `run_scheduler.bat` akan memanggil `php artisan schedule:run` setiap 1 menit. Scheduler internal Laravel akan mengeksekusi job tepat pada 16:05 WIB di hari kerja (weekdays).

---

## 3. MEKANISME KEANDALAN (OPERATIONAL RELIABILITY)

### A. Validasi Kalender Hari Bursa BEI (`BeiCalendarService`)
- Ketika job terjadwal berjalan (`--trigger=scheduled`), sistem memeriksa kalender BEI 2026.
- Jika hari eksekusi adalah **Sabtu, Minggu, atau Hari Libur Resmi BEI**, pipeline **berhenti seketika dengan status sukses tanpa menjalankan request jaringan atau memodifikasi database**.
- Mencegah download data duplikat dan pemborosan komputasi saat bursa tutup.

### B. Proteksi Finalitas Data EOD (Anti Lookahead & Anti Incomplete Bar)
- Sinyal EOD hanya boleh diproses setelah penutupan bursa resmi (>= 16:00 WIB).
- Jika job terjadwal terpanggil sebelum 16:00 WIB, eksekusi ditolak dengan status peringatan data belum final.

### C. Proteksi Mutex Lock (Anti Tumpang Tindih)
- Perintah `hermes:sync-eod` dilindungi oleh `Cache::lock('hermes_sync_eod_mutex', 600)`.
- Jika proses sinkronisasi sebelumnya masih berjalan (misal akibat koneksi data lambat), proses kedua yang menyusul otomatis dibatalkan.

### D. Penanganan Error & Deduplikasi Notifikasi
- Jika terjadi kegagalan (misal network timeout atau router AI tidak merespon), status run dicatat sebagai `failed` (tidak pernah ditandai `completed`).
- Hash error dihitung dari `sha1(pesan + file + line)`.
- Jika error yang sama terjadi berulang dalam jendela 6 jam, pencatatan log alert di-cooldown agar tidak membanjiri log atau kanal Telegram.

---

## 4. PEMERIKSAAN LOG & DIAGNOSTIK MANDIRI

Seluruh output eksekusi otomatis dicatat ke file log lokal:
- Log EOD Pipeline: `E:\Project-bot\laravel-app\storage\logs\eod_pipeline_output.log`
- Log Scheduler: `E:\Project-bot\laravel-app\storage\logs\hermes_scheduler.log`
- Log Evaluator: `E:\Project-bot\laravel-app\storage\logs\hermes_evaluator.log`
- Log Aplikasi Laravel: `E:\Project-bot\laravel-app\storage\logs\laravel.log`

### Perintah Pemeriksaan Cepat:
```bash
# Cek 30 baris log terakhir
tail -n 30 "E:/Project-bot/laravel-app/storage/logs/eod_pipeline_output.log"

# Cek status kesehatan via CLI
cd E:/Project-bot/laravel-app
php artisan db:monitor
php artisan schedule:list
```
