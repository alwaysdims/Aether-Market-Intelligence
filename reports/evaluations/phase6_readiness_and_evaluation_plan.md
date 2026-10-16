# LAPORAN EVALUASI & RENCANA OPERASIONAL FASE 6
## AETHER MARKET INTELLIGENCE + HERMES STOCK AGENT

**Dokumen:** `reports/evaluations/phase6_readiness_and_evaluation_plan.md`  
**Waktu Audit:** Sabtu, 17 Oktober 2026, 00:45 WIB  
**Penyusun:** Senior Quantitative Research Engineer, Data Integrity Auditor & Backend Reliability Engineer  
**Status Evaluasi:** Read-Only Verification, Historical Metadata Reconciliation & Gatekeeping  

---

## 1. COMMIT, BRANCH, DAN KONDISI WORKING TREE

### Repositori A: `E:\Project-bot` (Laravel Control Plane & Dashboard)
- **Branch:** `main`
- **Commit Terakhir:** `7b996b9` ("Implement Phase 5: Operational monitoring CLI, strict content-hash Telegram deduplication, and out-of-sample audit tests")
- **Status Working Tree:** Bersih (`working tree clean`), tidak ada uncommitted changes.
- **Kondisi Database:** PostgreSQL 16.1 (`project-bot` di port 5432), 20 prediksi post-horizon berstatus `pending`.

### Repositori B: `E:\hermes-stock-agent` (Quant Analysis & Paper Trading Engine)
- **Branch:** `main`
- **Commit Terakhir:** `2c974b2` ("Audit and fix forward paper trading timestamps, candle finality, and execution scheduling")
- **Status Working Tree:** Bersih pada file produksi.
- **Manifest Eksperimen Beku:** `reports/evaluations/frozen_experiments_manifest.json`  
  *SHA-256 Hash:* `3f5d2ecd6551724926e623b1adab2aa5bf3c3c2499f4d20c80ad5e8c55be0b44` (Valid & Utuh).
- **Status Portofolio Forward:** EXP-01 (Kas Rp500.000,00) & EXP-04 (Kas Rp500.000,00). Nol transaksi dieksekusi.

---

## 2. FAKTA YANG DIVERIFIKASI VS FAKTA YANG BELUM BISA DIVERIFIKASI

### Fakta Terverifikasi (PASS):
1. **Pemisahan Model:** Model resmi terkunci pada `ag/gemini-3.8-flash-high` melalui 9router lokal (:20128). Tidak ada model eksternal yang disubstitusi.
2. **Ketiadaan Order Broker Riil:** Tidak ada koneksi API broker, kredensial akun sekuritas, atau transmisi order ke pasar riil. Seluruh eksekusi berjalan 100% simulasi paper trading.
3. **Immutability Prediksi Awal:** Seluruh 20 rekaman prediksi mempertahankan harga referensi penutupan EOD 14 Oktober 2026 tanpa pernah ditimpa data harga masa depan.
4. **Validasi Kalender BEI:** Perhitungan 5 hari bursa melompati akhir pekan dan libur resmi secara benar. Tanggal target resmi adalah **Rabu, 21 Oktober 2026**.
5. **Deduplikasi Pesan Telegram:** Jendela deduplikasi 18 jam berbasis `content_hash` aktif; pesan identik berulang dicegah dengan status `skipped_duplicate`.
6. **Pendaftaran Task Scheduler:** Tugas `HermesMarketIntelligence_EOD` terdaftar resmi di Windows Task Scheduler (`Status: Ready`, next run: 19 Oktober 2026 pukul 16:05 WIB).

### Fakta yang Belum Bisa Diverifikasi (BLOCKED):
1. **Akurasi Model Gemini Aktif:** Belum dapat dihitung karena pada 2 run terakhir router lokal menghasilkan mode fallback kuantitatif (0 sampel inferensi Gemini yang valid tersimpan di evaluasi).
2. **Kinerja Out-of-Sample EXP-04 vs EXP-01:** Belum dapat dihitung karena jumlah transaksi tertutup bursa forward aktif masih $N = 0$.
3. **Outcome Prediksi Post-Horizon:** Belum dapat dievaluasi karena data penutupan sesi 21 Oktober 2026 belum terbentuk (bursa masih berada di sesi 16 Oktober).

---

## 3. HASIL PEMERIKSAAN & REKONSILIASI METADATA FALLBACK

### Temuan Ketidakkonsistenan Awal:
- Prediksi ID 1–10 dibuat pada Fase 3 sebelum penambahan kolom `is_fallback` dan `validation_status` ke tabel `post_horizon_evaluations`.
- Ketika migrasi `add_fallback_columns` dijalankan di Fase 4, baris ID 1–10 menerima nilai default kolom (`is_fallback = false`, `validation_status = 'valid_schema'`).
- Namun rekaman induk `market_analyses` ID 3 secara faktual mencatat `validation_status = 'fallback'`.
- Prediksi ID 11–20 dibuat pada Fase 4 setelah kolom tersedia dan mencatat `is_fallback = true` secara akurat.

### Tindakan Rekonsiliasi Terverifikasi (Audit Trail):
Untuk menjamin tidak ada prediksi fallback yang diklasifikasikan secara keliru sebagai penalaran AI Gemini, telah dibuat migrasi terarah:
`2026_10_16_174023_reconcile_historical_fallback_metadata_in_evaluations.php`
- **Tindakan:** Mengupdate `is_fallback = true` dan `validation_status = 'fallback'` khusus untuk baris yang terhubung ke `market_analyses` dengan status fallback.
- **Batasan:** Tidak mengubah `decision_date`, `reference_price`, `target_eval_date`, `status`, atau kolom evaluasi apa pun.
- **Hasil:** Seluruh 20 baris prediksi kini secara jujur dan konsisten terlabeli sebagai `is_fallback = true`. Metrik penalaran AI murni melaporkan 0 sampel valid (bebas dari distorsi klaim performa AI).

---

## 4. CHECKLIST OPERASIONAL & BUKTI SCHEDULER WINDOWS

### Status Konfigurasi & Lingkungan:
- **Task Scheduler Name:** `\HermesMarketIntelligence_EOD`
- **Host / User:** `DIMZZZ / ASUS`
- **Next Scheduled Execution:** `10/19/2026 4:05:00 PM` (Senin, 19 Oktober 2026 pukul 16:05:00 WIB)
- **Batch Script:** `E:\Project-bot\run_eod_pipeline.bat`
- **Interpreter:** `C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe`
- **Log File:** `E:\Project-bot\laravel-app\storage\logs\eod_pipeline_output.log`

### Bukti Eksekusi Akhir Pekan (Saturday Run Check):
Panggilan manual `cmd.exe /c "E:\Project-bot\run_eod_pipeline.bat"` menghasilkan log:
```
[SCHEDULED] Hari ini (2026-10-17) bukan hari bursa aktif BEI. Sinkronisasi dilewati.
=== MEMULAI EVALUASI POST-HORIZON 5 HARI BURSA ===
Tanggal As-Of Evaluasi: 2026-10-17
Hasil Pemrosesan:
 - Dievaluasi Selesai : 0
 - Belum Jatuh Tempo  : 20
 - Data Tidak Ada     : 0
```
*Kesimpulan:* Proteksi kalender bursa bekerja sempurna. Tidak ada komputasi yang terbuang atau data yang terkorupsi saat pasar tutup.

---

## 5. GERBANG EVALUASI 21 OKTOBER 2026

### Syarat Gerbang Evaluasi (Evaluation Gate):
1. **Tanggal Kalender:** Evaluasi hanya boleh dijalankan setelah tanggal sistem >= 21 Oktober 2026.
2. **Finalitas Candle:** Candle penutupan bursa 21 Oktober 2026 harus telah ditutup secara resmi (>= 16:00 WIB).
3. **Ketersediaan Data:** File OHLCV harian untuk 10 saham universe harus terunduh lengkap dan valid (bebas null, duplikat, atau volume nol mencurigakan).
4. **Idempotensi:** Eksekusi berulang tidak boleh menghasilkan baris ganda di tabel evaluasi atau mengubah `actual_price` yang sudah final.

### Jadwal Operasional Mendatang:
- **Senin, 19 Oktober 2026 (16:05 WIB):** Task Scheduler menjalankan EOD sync hari perdagangan ke-3 horizon.
- **Selasa, 20 Oktober 2026 (16:05 WIB):** Task Scheduler menjalankan EOD sync hari perdagangan ke-4 horizon.
- **Rabu, 21 Oktober 2026 (16:05 WIB):** Task Scheduler menjalankan EOD sync hari perdagangan ke-5 (Target Final).
- **Rabu, 21 Oktober 2026 (16:15 WIB):** Evaluator otomatis menyelesaikan 20 prediksi yang jatuh tempo.

---

## 6. DEFINISI METRIK, BATAS INFERENSI, & RISIKO KETERGANTUNGAN SILANG

### Definisi Metrik Horizon 5 Hari Bursa (+5D):
$$\text{Return Aktual} = \frac{\text{Harga Penutupan}(t+5) - \text{Harga Referensi}(t)}{\text{Harga Referensi}(t)} \times 100\%$$
- **Ambang Batas Arah Eksplisit:**
  * $\text{Return Aktual} > +0,5000\% \rightarrow \text{positive}$
  * $\text{Return Aktual} < -0,5000\% \rightarrow \text{negative}$
  * $-0,5000\% \le \text{Return Aktual} \le +0,5000\% \rightarrow \text{flat}$
- **Penanganan Arah `unclear` / Netral:**
  Seluruh 20 prediksi saat ini memiliki `predicted_direction = 'unclear'`.
  *Aturan Metodologis:* Prediksi `unclear` **tidak boleh dipaksakan dinilai sebagai benar atau salah**. Prediksi ini dilaporkan dalam kategori `neutral`, dan dikeluarkan dari penyebut metrik directional accuracy.

### Risiko Ketergantungan Silang (Cross-Sectional Correlation):
- Kesepuluh saham universe (`BBCA`, `BBRI`, `BMRI`, `BBNI`, `ASII`, `TLKM`, `MAPI`, `ARTO`, `MEDC`, `SMRA`) terdaftar pada tanggal bar yang sama (14 Oktober 2026).
- Return kesepuluh saham ini dipengaruhi oleh faktor beta pasar bersama (IHSG).
- **Larangan Metodologis:** Dilarang memperlakukan 10 instrumen ini sebagai 10 observasi independen dalam uji $t$ atau bootstrap ($N_{\text{eff}} \ll 10$). Laporan awal wajib disajikan secara deskriptif tanpa generalisasi statistik yang tidak berdasar.

---

## 7. STATUS FORWARD PAPER TRADING (EXP-01 VS EXP-04)

### Parameter Tetap (Zero Mutation):
| Parameter | Nilai Eksperimen | Status Audit |
|---|:---:|:---:|
| Modal Awal | Rp500.000,00 per portofolio | Terverifikasi utuh |
| Alokasi per Trade | Maks 20% (Rp100.000,00) | Terverifikasi |
| Posisi Konkuren | Maksimal 5 posisi | Terverifikasi |
| Ukuran Lot | 100 saham per lot | Terverifikasi |
| Biaya Beli / Jual | 0,15% / 0,25% | Terverifikasi |
| Slippage Entry / Exit | 1 tick / 1 tick | Terverifikasi |
| Aturan EXP-01 | Pullback EMA (RVOL $\ge 1,0$, Close Pos $\ge 0,0$) | Terverifikasi |
| Aturan EXP-04 | Pullback EMA + Konfirmasi Ketat (RVOL $\ge 1,5$, Close Pos $\ge 0,70$) | Terverifikasi |

### Rekonsiliasi Portofolio:
- **EXP-01:** Kas Rp500.000,00 | Posisi terbuka: 0 | Transaksi tertutup: 0 | Realized PnL: Rp0,00.
- **EXP-04:** Kas Rp500.000,00 | Posisi terbuka: 0 | Transaksi tertutup: 0 | Realized PnL: Rp0,00.
- **Audit Sinyal Kedaluwarsa:** Sinyal MAPI dan MEDC pada 15 Oktober 2026 ditolak secara benar dengan status `EXPIRED_STALE_WINDOW` karena waktu scan (11:57 WIB) melewati batas pembukaan bursa (09:00 WIB). Tidak ada transaksi sintetis yang disusupkan.

---

## 8. BUG YANG DITEMUKAN, TINGKAT KEPARAHAN, & TINDAKAN MINIMAL

| ID Temuan | Komponen | Deskripsi | Keparahan | Tindakan Minimal Diambil |
|---|---|---|:---:|---|
| **BUG-01** | `post_horizon_evaluations` | Baris 1–10 mewarisi default `is_fallback = false` dari migrasi, padahal analisis induk berstatus `fallback`. | Medium | **SELESAI DITANGANI:** Dibuat dan dijalankan migrasi `2026_10_16_174023` untuk merekonsiliasi metadata. Seluruh 20 baris kini tercatat sebagai `is_fallback = true`. |
| **BUG-02** | `Windows Task Scheduler` | Task terjadwal sebelumnya hanya terdokumentasi di batch script, belum didaftarkan ke daemon Windows. | Medium | **SELESAI DITANGANI:** Dijalankan `schtasks /create` untuk mendaftarkan task `HermesMarketIntelligence_EOD`. Status kini `Ready`. |

---

## 9. KRITERIA LANJUT, TUNDA, ATAU HENTIKAN EVALUASI

- **LANJUT SINKRONISASI RUTIN (PASS):** Biarkan Task Scheduler berjalan normal pada Senin–Rabu pukul 16:05 WIB.
- **TUNDA EVALUASI HORIZON (HOLD):** Jangan mengevaluasi outcome prediksi hingga penutupan resmi bursa Rabu, 21 Oktober 2026 pukul 16:00 WIB.
- **HENTIKAN JIKA TERJADI KORUPSI DATA (HALT):** Jika API data mengembalikan bar yang tidak lengkap atau terkontaminasi lookahead, hentikan eksekusi dan isolasi file raw.

---

## 10. PERINTAH OPERASIONAL RESMI

```bash
# 1. Audit status operasional real-time 6 pilar
cd E:/Project-bot/laravel-app
php artisan hermes:monitor

# 2. Periksa status antrean Task Scheduler Windows
schtasks /query /fo LIST /tn "HermesMarketIntelligence_EOD"

# 3. Jalankan pipeline sinkronisasi EOD manual (hari bursa)
php artisan hermes:sync-eod --trigger=scheduled

# 4. Jalankan evaluasi horizon jatuh tempo (setelah 21 Oktober 16:00 WIB)
php artisan hermes:evaluate-horizon

# 5. Jalankan test suite lengkap (44 Laravel + 6 Adapter + 64 Quant Engine)
cd E:/Project-bot/laravel-app && php artisan test
cd E:/Project-bot/services && python test_hermes_adapter.py
cd E:/hermes-stock-agent && python -m pytest tests/
```
