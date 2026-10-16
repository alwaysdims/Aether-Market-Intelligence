# LAPORAN FASE 6.1 — PRE-EVALUATION GATE & POST-HORIZON VALIDATION
## AETHER MARKET INTELLIGENCE + HERMES STOCK AGENT

**Dokumen:** `reports/evaluations/phase6_1_pre_evaluation_gate.md`  
**Waktu Audit:** Sabtu, 17 Oktober 2026, 01:10 WIB  
**Auditor:** Senior Backend Engineer, Quantitative Research Auditor & Data Integrity Engineer  
**Status Evaluasi:** Pre-Evaluation Gate Check (Strict Read-Only & Immutability Verification)  

---

## 1. COMMIT, BRANCH, DAN WORKING TREE AKTUAL

### Repositori Utama: `E:\Project-bot` (Laravel Control Plane)
- **Branch:** `main`
- **Commit Terakhir:** `755e3de` ("Phase 6: Reconcile historical fallback metadata and establish Phase 6 readiness & evaluation plan")
- **Status Working Tree:** Bersih (`working tree clean`), tidak ada uncommitted changes.
- **Integritas Database:** PostgreSQL 16.1 (`project-bot` di port 5432).

### Repositori Quant Engine: `E:\hermes-stock-agent`
- **Branch:** `main`
- **Commit Terakhir:** `2c974b2` ("Audit and fix forward paper trading timestamps, candle finality, and execution scheduling")
- **Status Working Tree:** Bersih pada file produksi.
- **Manifest Eksperimen Beku:** `3f5d2ecd6551724926e623b1adab2aa5bf3c3c2499f4d20c80ad5e8c55be0b44` (Valid & Utuh).
- **Status Portofolio:** EXP-01 & EXP-04 memegang kas Rp500.000,00 utuh (0 posisi terbuka, 0 transaksi tertutup).

---

## 2. FILE DAN FUNGSI YANG DIPERIKSA

1. `app/Services/PostHorizonEvaluatorService.php`:
   - `registerPredictions()`: Idempotensi via `updateOrCreate`, penentuan tanggal target via kalender BEI.
   - `evaluatePending()`: Pengecekan anti-lookahead (`$asOfDateStr < $targetDateStr`), query candle target, validasi harga positif (`$refPrice > 0 && $actualPrice > 0`), kalkulasi return, rubrik `unclear` -> `neutral`.
   - `getMetricsSummary()`: Pemisahan akurasi AI murni vs fallback, isolasi kategori `neutral` dari penyebut directional.
2. `app/Console/Commands/HermesEvaluateHorizonCommand.php`:
   - Penanganan opsi `--as-of`, `--force`, serta pelaporan metrik evaluasi ke konsol.
3. `app/Services/BeiCalendarService.php`:
   - `isTradingDay()`: Validasi hari kerja non-akhir pekan dan non-libur BEI 2026.
   - `isEodFinal()`: Penegakan finalitas candle jam 16:00 WIB.
   - `addTradingDays()`: Penambahan 5 hari bursa melompati libur akhir pekan.
4. `database/migrations/2026_10_16_174023_reconcile_historical_fallback_metadata_in_evaluations.php`:
   - Penegakan kebenaran metadata historis fallback pada rekaman ID 1–10.
5. `app/Models/PostHorizonEvaluation.php`, `MarketAnalysis.php`, `MarketSnapshot.php`:
   - Relasi Eloquent, casting tanggal, dan integritas kolom.

---

## 3. HASIL AUDIT PRE-EVALUATION GATE (PASS, BLOCKED, ACTION REQUIRED)

### A. PASS (Fakta dan Kontrol Terverifikasi):
1. **Status Seluruh 20 Prediksi Masih PENDING:**
   Query database membuktikan 20/20 prediksi memiliki `status = 'pending'`, `actual_price = NULL`, `actual_return_pct = NULL`, `outcome = NULL`. Tidak ada outcome prematur yang diisi sebelum waktunya.
2. **Metadata Fallback Konsisten 100%:**
   Seluruh 20 prediksi memiliki `is_fallback = true` dan `validation_status = 'fallback'`. Tidak ada prediksi fallback yang diklaim sebagai hasil penalaran AI Gemini.
3. **Tanggal Target Sah Menurut Kalender BEI:**
   Seluruh 20 prediksi memiliki `decision_date = 2026-10-14` dan `target_eval_date = 2026-10-21`. Hari Rabu, 21 Oktober 2026 adalah hari bursa aktif BEI yang sah (bukan hari libur nasional).
4. **Formula Return Konsisten:**
   Formula return terverifikasi matematis: $\text{return} = ((\text{actual} - \text{ref}) / \text{ref}) \times 100\%$.
5. **Prediksi `unclear` Dikeluarkan dari Akurasi Directional:**
   Seluruh 20 prediksi saat ini memiliki `predicted_direction = 'unclear'`. Rubrik evaluator menetapkan outcome `neutral`, dan penyebut `directionalCount` ($N_{\text{correct}} + N_{\text{incorrect}}$) tidak memasukkan kategori netral.
6. **Proteksi Harga Positif:**
   Evaluator menolak harga referensi atau harga aktual $\le 0$ dan menandainya sebagai `cannot_evaluate` dengan catatan kegagalan data.
7. **Idempotensi Terbukti:**
   Pemanggilan berulang `evaluatePending()` tanpa force hanya memproses baris berstatus `pending`. Baris yang sudah `evaluated` tidak pernah diproses ulang atau ditimpa.
8. **Task Scheduler Windows Terdaftar & Siap:**
   Task `HermesMarketIntelligence_EOD` berstatus `Ready` dengan jadwal eksekusi berikutnya Senin, 19 Oktober 2026 pukul 16:05 WIB.

### B. BLOCKED (Evaluasi yang Belum Bisa Dilakukan):
1. **Ketiadaan Data Candle Penutupan 21 Oktober 2026:**
   Tanggal sistem saat ini adalah Sabtu, 17 Oktober 2026. Sesi perdagangan 21 Oktober 2026 belum terjadi, sehingga evaluasi outcome **terblokir secara sah** hingga candle 21 Oktober pukul 16:00 WIB terbentuk.
2. **Klaim Akurasi Model Gemini Aktif:**
   Terblokir karena 20 prediksi saat ini berasal dari mode fallback kuantitatif. Akurasi Gemini aktif secara jujur dilaporkan 0% atas 0 sampel.
3. **Evaluasi Out-of-Sample EXP-01 vs EXP-04:**
   Terblokir hingga akumulasi transaksi forward bursa aktif terkumpul ($N_{\text{closed}} = 0$).

### C. ACTION REQUIRED (Langkah Konkret Operasional):
1. Biarkan Task Scheduler mengeksekusi pipeline EOD harian pada Senin, 19 Oktober dan Selasa, 20 Oktober 2026 pukul 16:05 WIB untuk menyinkronkan data perantara.
2. Pada Rabu, 21 Oktober 2026 pasca-16:00 WIB, pastikan sync EOD mengunduh penutupan final 21 Oktober.
3. Jalankan `php artisan hermes:evaluate-horizon` untuk menyelesaikan 20 prediksi yang jatuh tempo.

---

## 4. HASIL PENGUJIAN AKTUAL

1. **Laravel PHPUnit Test Suite:**
   - Perintah: `cd E:/Project-bot/laravel-app && php artisan test`
   - Hasil: **44 LULUS (139 assertions, durasi 67,1 detik)**.
   - Skenario: Validasi kalender BEI, pencegahan evaluasi sebelum tanggal jatuh tempo, penanganan harga target hilang, deduplikasi Telegram 18 jam, idempotensi evaluasi, pemisahan metrik Gemini vs fallback, dan sanitasi rahasia.
2. **Python Adapter Unit Tests:**
   - Perintah: `cd E:/Project-bot/services && python test_hermes_adapter.py`
   - Hasil: **6 LULUS (durasi 10,4 detik)**.
3. **Hermes Quant Engine Baseline:**
   - Perintah: `cd E:/hermes-stock-agent && python -m pytest tests/`
   - Hasil: **64 LULUS (durasi 125,6 detik)**. Engine quant lama bebas regresi.

---

## 5. RISIKO EVALUASI PREMATUR, DATA HILANG, DAN DUPLIKASI

1. **Risiko Evaluasi Prematur:**
   Jika script evaluasi dipaksa berjalan sebelum tanggal 21 Oktober dengan opsi `--force` tanpa data aktual, evaluator akan mencari bar pasar yang ada ($\le 16\text{ Okt}$), menghasilkan lookahead bias dan pelanggaran horizon 5 hari.  
   *Mitigasi:* Runner terjadwal berjalan tanpa opsi `--force`, secara otomatis melewati 20 prediksi yang belum jatuh tempo.
2. **Risiko Data Hilang / Saham Suspensi:**
   Jika pada 21 Oktober ada saham yang disuspensi oleh bursa sehingga candle 21 Oktober tidak terbentuk.  
   *Mitigasi:* Evaluator mempertahankan status `pending` hingga 30 hari kalender. Jika setelah 30 hari data tetap tidak ada, status ditandai `cannot_evaluate` (`outcome = missing_data`) tanpa menghitungnya sebagai prediksi salah.
3. **Risiko Duplikasi Notifikasi & Data:**
   Jika scheduler mengeksekusi script berulang kali pada 21 Oktober.  
   *Mitigasi:* Kunci unik database `['market_analysis_id', 'ticker', 'horizon_trading_days']` dan filter `where('status', 'pending')` menjamin operasi bersifat idempotent. Deduplikasi Telegram berbasis hash 18 jam mencegah spam ke pengguna.

---

## 6. CHECKLIST SEBELUM DAN SESUDAH 21 OKTOBER 2026

### Sebelum 21 Oktober 2026 (Fase Monitoring):
- [x] Verifikasi 20 prediksi berstatus `pending` dan `is_fallback = true`.
- [x] Task Scheduler `HermesMarketIntelligence_EOD` aktif (`Ready`).
- [x] Eksekusi hari non-bursa (akhir pekan/libur) otomatis diskip tanpa error.
- [x] Port 5432 (PostgreSQL) dan 20128 (9router) aktif.
- [x] Kas EXP-01 dan EXP-04 tetap utuh Rp500.000,00 per portofolio.

### Pada 21 Oktober 2026 (Fase Gatekeeping & Evaluasi):
- [ ] Pukul 16:00 WIB: Sesi perdagangan BEI resmi ditutup.
- [ ] Pukul 16:05 WIB: Task Scheduler menjalankan `hermes:sync-eod --trigger=scheduled`.
- [ ] Verifikasi bar 21 Oktober 2026 untuk 10 saham universe tersimpan lengkap di `market_snapshots`.
- [ ] Pukul 16:15 WIB: Jalankan `php artisan hermes:evaluate-horizon`.
- [ ] Verifikasi 20 baris prediksi berubah status dari `pending` menjadi `evaluated` (atau `cannot_evaluate` jika ada data hilang).
- [ ] Cetak ringkasan evaluasi: `php artisan hermes:monitor`.

---

## 7. PERINTAH EVALUASI TERVERIFIKASI DARI KODE AKTUAL

```bash
# 1. Audit status menyeluruh sebelum evaluasi
cd E:/Project-bot/laravel-app
php artisan hermes:monitor

# 2. Periksa status antrean Task Scheduler Windows
schtasks /query /fo LIST /tn "HermesMarketIntelligence_EOD"

# 3. Jalankan evaluasi horizon jatuh tempo (Hanya setelah 21 Okt 2026 16:00 WIB)
php artisan hermes:evaluate-horizon

# 4. Verifikasi seluruh pengujian sistem
php artisan test
```
