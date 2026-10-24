# LAPORAN FASE 6.8 — EOD SCHEDULER READINESS & SAFE EXECUTION AUDIT
## AETHER MARKET INTELLIGENCE

**Dokumen:** `reports/evaluations/phase6_8_eod_scheduler_readiness.md`  
**Waktu Audit Aktual:** Sabtu, 17 Oktober 2026, 09:15 WIB  
**Status Akhir:** **READY WITH WARNINGS** (Scheduler Siap, Gerbang Proteksi Live AI Aktif, Evaluasi Horizon Terkunci)

---

### 1. RINGKASAN EKSEKUSI

Fase 6.8 telah menyelesaikan audit kesiapan operasional End-of-Day (EOD) pipeline, verifikasi Windows Task Scheduler `HermesMarketIntelligence_EOD`, penelusuran alur data dan zona waktu, serta pengamanan ketat agar pipeline terjadwal tidak memicu inferensi live AI secara otomatis tanpa izin baru dari pengguna.

1. **Audit Windows Task Scheduler:**
   - Task `HermesMarketIntelligence_EOD` terverifikasi aktif dengan status `Ready` dan jadwal resmi: **Senin s.d. Jumat pukul 16:05 WIB**. Jadwal berikutnya: **Senin, 19 Oktober 2026 pukul 16:05 WIB**.
   - Skrip batch `E:\Project-bot\run_eod_pipeline.bat` terkonfigurasi dengan benar: berpindah direktori ke `E:\Project-bot\laravel-app` dan mengeksekusi PHP CLI `C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe` dengan output terarah ke `storage\logs\eod_pipeline_output.log`.
2. **Pencegahan Inferensi Live Tanpa Izin (Active Safe Gate):**
   - Menjawab temuan bahwa `HermesSyncEodCommand` sebelumnya akan memanggil live API 9Router secara otomatis saat dijalankan terjadwal di hari kerja, sistem telah ditambahkan gerbang kontrol eksplisit:
     * Parameter lingkungan `AI_LIVE_INFERENCE_ENABLED=false` ditetapkan pada `.env`, `.env.example`, dan `config/services.php`.
     * `AiMarketAnalysisService` mengawal metode `analyze()`: jika `live_inference_enabled = false` dan flag `--live-ai` tidak diberikan, sistem secara otomatis mengalihkan proses ke fallback yang aman dengan pesan: *"Inferensi live AI dinonaktifkan (AI_LIVE_INFERENCE_ENABLED=false). Memerlukan izin operasional eksplisit."*
     * Ditambahkan opsi `--live-ai` pada perintah `hermes:sync-eod` sebagai flag eksplisit manual.
   - Dengan proteksi ini, saat Task Scheduler berjalan pada Senin, 19 Oktober 2026 pukul 16:05 WIB, pipeline akan mengumpulkan data pasar, berita, dan mendaftarkan evaluasi fallback tanpa memicu inferensi live ke 9Router hingga pengguna memberikan izin eksplisit.
3. **Validasi Dry-Run Non-Live:**
   - Pengujian `php artisan hermes:sync-eod --trigger=scheduled` pada hari Sabtu membuktikan proteksi akhir pekan aktif: `[SCHEDULED] Hari ini (2026-10-17) bukan hari bursa aktif BEI. Sinkronisasi dilewati.`
   - Pengujian `php artisan hermes:evaluate-horizon` mengonfirmasi 20 record belum jatuh tempo (`skipped_not_due: 20`, `evaluated: 0`) dan tidak mengubah data historis.
4. **Hasil Pengujian Regresi Master:**
   - Seluruh 130 pengujian regresi lintas sistem lulus 100% (60 Laravel, 6 Adapter, 64 Quant Engine).
   - 20 record evaluasi historis tetap 100% utuh berstatus `fallback` dan `pending`.

---

### 2. KLASIFIKASI BUKTI

- **VERIFIED (Terbukti secara empiris dan kode):**
  * **Status Task Scheduler:** Task `\HermesMarketIntelligence_EOD` aktif (`Status: Ready`, Next Run: `10/19/2026 4:05:00 PM`).
  * **Integritas Skrip Batch:** `run_eod_pipeline.bat` menggunakan absolute path yang tepat menuju Laragon PHP binary dan logging path.
  * **Gerbang Proteksi Live AI:** Variabel `AI_LIVE_INFERENCE_ENABLED=false` aktif di `laravel-app/.env` dan `config/services.php`.
  * **Dry-Run Weekend Guard:** Perintah terjadwal otomatis keluar dengan exit code 0 saat mendeteksi akhir pekan/hari libur BEI.
  * **Dry-Run Horizon Guard:** Perintah evaluasi horizon mengonfirmasi 20 record pending belum jatuh tempo (target: 2026-10-21).
  * **Hasil Pengujian Regresi 100% Lulus:** 60 tes Laravel (194 assertions), 6 tes Python Adapter, 64 tes Quant Engine (Total: 130 tes lulus).
  * **Integritas Data Historis:** 20 record evaluasi historis tetap utuh berstatus `fallback = true`, `validation_status = 'fallback'`, dan `status = 'pending'` dengan outcome NULL.
- **REPORTED:**
  * Pengaturan daya Windows Task Scheduler memiliki opsi `Stop On Battery Mode, No Start On Batteries`.
- **NOT VERIFIED:**
  * Eksekusi otomatis Task Scheduler pada Senin, 19 Oktober 2026 pukul 16:05 WIB (karena waktu tersebut belum terjadi).
- **PENDING:**
  * Izin baru dari pengguna untuk mengaktifkan live inference (`AI_LIVE_INFERENCE_ENABLED=true` atau flag `--live-ai`).
  * Kedatangan candle penutupan pasar BEI pada 21 Oktober 2026 (>= 16:00 WIB) untuk pelaksanaan evaluasi horizon.

---

### 3. KONFIGURASI DAN RIWAYAT TASK SCHEDULER

Hasil audit detail Windows Task Scheduler via `schtasks /query /v`:
- **Task Name:** `\HermesMarketIntelligence_EOD`
- **Status:** `Ready`
- **Next Run Time:** `10/19/2026 4:05:00 PM` (Senin, 19 Oktober 2026 pukul 16:05 WIB)
- **Schedule Type:** Weekly (Senin, Selasa, Rabu, Kamis, Jumat pukul 16:05)
- **Task To Run:** `E:\Project-bot\run_eod_pipeline.bat`
- **Logon Mode:** `Interactive only` (User: `ASUS`)
- **Last Run Time:** `11/30/1999 12:00:00 AM` (Belum pernah dipicu otomatis oleh scheduler)
- **Last Result:** `267011` (`0x41303` = Task terdaftar dan siap dijalankan)
- **Lokasi Log Eksekusi:** `E:\Project-bot\laravel-app\storage\logs\eod_pipeline_output.log`
- **Mekanisme Deteksi Kegagalan:** Exit code proses dicatat ke log, dan error kritis memicu `sendErrorAlert` ke Telegram dengan cooldown 6 jam.

---

### 4. HASIL AUDIT ALUR EOD

Penelusuran menyeluruh terhadap alur pipeline EOD (`HermesSyncEodCommand`):
1. **Pemeriksaan Kalender BEI:** `BeiCalendarService::isTradingDay()` memvalidasi kalender libur bursa 2026 dan akhir pekan.
2. **Pemeriksaan Finalitas Candle:** `BeiCalendarService::isEodFinal()` memastikan waktu lokal WIB >= 16:00 WIB sebelum memproses EOD terjadwal.
3. **Pencegahan Konkurensi:** Cache lock mutex 10 menit (`hermes_sync_eod_mutex`) mencegah tumpang tindih proses.
4. **Pengambilan Data Snapshot:** Mengambil 10 saham IDX dari `hermes_adapter.py`. Data divalidasi dan disimpan ke `market_snapshots`.
5. **Pengambilan Berita Modal:** Mengambil feed RSS resmi Antara News Finansial, diperbarui ke tabel `news_items`.
6. **Analisis AI Intelijen Pasar:**
   - Jika `AI_LIVE_INFERENCE_ENABLED=true` (atau `--live-ai`): Mengirim permintaan ke 9Router dengan `max_tokens: 2500`.
   - Jika `AI_LIVE_INFERENCE_ENABLED=false`: Menghasilkan analisis fallback terstruktur secara lokal tanpa memanggil jaringan AI.
7. **Pendaftaran Prediksi Post-Horizon:** Mendaftarkan prediksi ke `post_horizon_evaluations` dengan horizon 5 hari bursa ke depan.
8. **Evaluasi Prediksi Jatuh Tempo:** Mengevaluasi record yang target evaluasinya telah tercapai (hanya jika data penutupan final tersedia).
9. **Notifikasi Telegram (@Info_BosKuu_bot):** Mengirim ringkasan EOD dengan perlindungan deduplikasi hash 18 jam.

**Audit Penanganan Zona Waktu (WIB vs UTC):**
- Database PostgreSQL menyimpan timestamp dalam format UTC (`timestamptz`).
- Seluruh logika bisnis penanggalan bursa menggunakan `now('Asia/Jakarta')` (WIB, UTC+7).
- Logika deduplikasi Telegram telah diperbaiki pada Fase 6.7 agar menggunakan jendela relatif 18 jam sehingga kebal terhadap perbedaan pergantian hari antara UTC dan WIB.

---

### 5. BUKTI DRY-RUN TANPA INFERENSI LIVE

Uji coba dry-run non-live dilakukan secara nyata:
1. **Perintah Terjadwal di Akhir Pekan:**
   - Perintah: `php artisan hermes:sync-eod --trigger=scheduled`
   - Output: `[SCHEDULED] Hari ini (2026-10-17) bukan hari bursa aktif BEI. Sinkronisasi dilewati.`
   - Bukti: Nol query HTTP dikirimkan, nol token terpakai.
2. **Perintah Evaluasi Horizon:**
   - Perintah: `php artisan hermes:evaluate-horizon`
   - Output: `Dievaluasi Selesai: 0, Belum Jatuh Tempo: 20, Data Tidak Ada: 0`
   - Bukti: 20 record evaluasi tetap berstatus pending dan outcome NULL.
3. **Audit Monitoring Sistem:**
   - Perintah: `php artisan hermes:monitor`
   - Output: Seluruh 6 pilar terpantau normal (`NIHIL error dalam 24 jam terakhir`).

---

### 6. HASIL PENGUJIAN REGRESI AKTUAL

Seluruh test suite dijalankan secara nyata pada lingkungan pengujian terisolasi:
1. **Laravel PHPUnit:** **60 LULUS (194 assertions, durasi 62,5 detik)**  
   `cd E:/Project-bot/laravel-app && php artisan test`
   *(Termasuk uji pencegahan live inference `test_live_inference_disabled_triggers_safe_fallback_without_network_request`)*.
2. **Python Adapter Service:** **6 LULUS (durasi 5,8 detik)**  
   `cd E:/Project-bot/services && python test_hermes_adapter.py`
3. **Hermes Stock Agent Baseline:** **64 LULUS (durasi 40,9 detik)**  
   `cd E:/hermes-stock-agent && python -m pytest tests/`
- **Total Kumulatif:** **130 pengujian lulus (100%)**.

---

### 7. INTEGRITAS DATA HISTORIS

Pemeriksaan langsung pada database PostgreSQL `project-bot`:
- **Total `market_analyses`:** Tetap 4 baris.
- **Total `post_horizon_evaluations`:** Tetap 20 baris.
  * Status: 20/20 `pending`.
  * Metadata fallback: 20/20 `is_fallback = true` dan `validation_status = 'fallback'`.
  * Nilai outcome: 20/20 `outcome = NULL`, `actual_price = NULL`, `actual_return_pct = NULL`.
  * Target evaluasi: 20/20 tepat pada `2026-10-21`.
  * Tanggal keputusan: 20/20 tepat pada `2026-10-14`.
- **Kesimpulan:** Nol manipulasi data historis. Seluruh catatan awal tetap murni.

---

### 8. RISIKO OPERASIONAL DAN REKOMENDASI

1. **Risiko Daya Laptop/Baterai:**
   - Task Scheduler memiliki flag bawaan `Stop On Battery Mode, No Start On Batteries`.
   - **Rekomendasi:** Pastikan laptop terhubung ke pengisi daya (AC Power) pada hari kerja pukul 16:05 WIB agar scheduler tidak dicegah berjalan oleh Windows.
2. **Prosedur Pemberian Izin Live Inference:**
   - Saat pengguna memutuskan untuk mengizinkan live inference pada pipeline produksi, langkah yang diperlukan adalah:
     * Ubah baris pada `E:\Project-bot\laravel-app\.env`:
       ```ini
       AI_LIVE_INFERENCE_ENABLED=true
       ```
     * Jalankan `php artisan config:clear` untuk memastikan cache konfigurasi diperbarui.
   - Tanpa langkah di atas, pipeline akan tetap beroperasi dengan mode fallback yang aman tanpa risiko biaya/kuota yang tidak terkontrol.
3. **Kesiapan Menuju 21 Oktober 2026:**
   - Evaluasi horizon 5 hari bursa akan jatuh tempo pada Rabu, 21 Oktober 2026 pukul 16:00 WIB.
   - Jangan menjalankan evaluasi dengan parameter paksa (`--force`) sebelum tanggal dan jam tersebut.

---

### 9. KEPUTUSAN STATUS AKHIR

**STATUS: READY WITH WARNINGS**

**Dasar Keputusan:**
1. **READY:**
   - Windows Task Scheduler `HermesMarketIntelligence_EOD` terverifikasi aktif, berjadwal tepat, dan terhubung ke batch runner yang valid.
   - Mekanisme perlindungan live inference (`AI_LIVE_INFERENCE_ENABLED=false`) telah aktif di level konfigurasi, kode service, dan artisan command.
   - Dry-run non-live membuktikan pipeline EOD mengawal hari libur, waktu candle final, dan masa jatuh tempo evaluasi dengan sempurna.
   - Seluruh 130 pengujian regresi lulus 100%.
   - Database produksi dan 20 record historis 100% terlindungi tanpa manipulasi.
2. **WARNINGS:**
   - Eksekusi live otomatis Task Scheduler pertama baru akan berlangsung pada **Senin, 19 Oktober 2026 pukul 16:05 WIB** (terjadwal aman dengan mode fallback hingga izin live diberikan).
   - Evaluasi horizon 20 record tetap menunggu kedatangan data penutupan pasar **21 Oktober 2026**.
   - Laptop harus dalam kondisi terhubung ke daya AC saat jam eksekusi scheduler.
   - Nol commit dan nol push Git dibuat.
