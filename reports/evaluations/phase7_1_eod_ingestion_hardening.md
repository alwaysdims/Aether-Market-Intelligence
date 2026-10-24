# LAPORAN FASE 7.1 — EOD DATA INGESTION HARDENING
## AETHER MARKET INTELLIGENCE

**Dokumen:** `reports/evaluations/phase7_1_eod_ingestion_hardening.md`  
**Waktu Penyelesaian:** 19 Oktober 2026, 22:15 WIB  
**Status Audit & Implementasi:** `IMPLEMENTED — TESTS PASSED — LIVE AI DISABLED`

---

## 1. Ringkasan Eksekutif

Audit Fase 7.0 mengidentifikasi bahwa eksekusi terjadwal pertama pada 19 Oktober 2026 pukul 16:05 WIB selesai dengan `exit code 0`, namun tetap memproses bar penutupan pasar 14 Oktober 2026. Hal ini terjadi karena adapter lama (`services/hermes_adapter.py`) hanya membaca file CSV statis tanpa mekanisme pengunduhan EOD otomatis, dan pipeline Laravel tidak memiliki validasi kesegaran data sehingga tetap mendaftarkan prediksi fallback baru dari data stale.

Fase 7.1 telah menyelesaikan hardening menyeluruh pada seluruh rantai ingesti data EOD:
1. **Modul Pengunduhan Otomatis & Validasi Ketat:** `services/hermes_adapter.py` kini memiliki kemampuan auto-download harian dari Yahoo Finance untuk 10 ticker aktif (BBCA, BBRI, BMRI, BBNI, ASII, TLKM, MAPI, ARTO, MEDC, SMRA), memvalidasi batas nilai OHLCV, integritas tanggal non-lookahead, serta menyimpan pembaruan secara atomik ke direktori operasional `E:\Project-bot\data\raw_daily`.
2. **Isolasi Repository Kuantitatif:** Repository `E:\hermes-stock-agent` diisolasi sepenuhnya tanpa modifikasi kode atau baseline data, menjaga 64 unit test pytest tetap lulus 100%.
3. **Proteksi Kegagalan Ingesti & Stale Data:** Jika data belum final atau unduhan gagal, `HermesSyncEodCommand` menolak memproses data stale, membatalkan registrasi prediksi, tidak menjalankan inferensi AI, menandai run sebagai `failed`, dan mengembalikan `Command::FAILURE` (exit code 1).
4. **Anti-Duplikasi Evaluator & Perbaikan Query Tanggal:** Ditambahkan guard anti-duplikasi pada `PostHorizonEvaluatorService` untuk mencegah duplikasi prediksi pada tanggal bar acuan yang sama, serta diperbaiki perbandingan tanggal snapshot (`whereDate`) yang sebelumnya terhambat format timestamp.
5. **Verifikasi Pengujian Otomatis:** Seluruh 137 pengujian (64 Laravel PHPUnit dengan 215 assertions, 9 Python adapter tests, dan 64 Hermes Stock Agent tests) lulus 100%.

---

## 2. File yang Berubah dan Alasan Perubahan

| File | Perubahan Utama | Alasan Teknis |
| :--- | :--- | :--- |
| `services/hermes_adapter.py` | Tambah `validate_eod_dataframe()`, `download_eod_data()`, `merge_and_save_eod_csv()`, dan perbarui `handle_market_snapshots()` | Mengunduh candle EOD terbaru secara otomatis, memvalidasi integritas bar, merge atomik ke CSV, serta memverifikasi status kesegaran (`is_fresh`). |
| `services/test_hermes_adapter.py` | Tambah 3 method pengujian unit (total 9 tests) | Memvalidasi seluruh skenario: data valid, unduhan parsial, respons kosong, duplikasi tanggal, nilai OHLCV tidak valid, data stale, dan preservasi file atomik. |
| `laravel-app/app/Services/HermesAdapterService.php` | Parameter `strictFreshness` dan `autoDownload` pada `getMarketSnapshots()` | Memungkinkan pipeline EOD menegakkan verifikasi kesegaran data secara eksplisit ke adapter Python. |
| `laravel-app/app/Console/Commands/HermesSyncEodCommand.php` | Validasi status `is_fresh` dan penanganan fail-fast | Menghentikan pipeline segera jika ingesti data gagal atau data stale; mencegah pembuatan prediksi dan inferensi AI pada data tidak mutakhir. |
| `laravel-app/app/Services/PostHorizonEvaluatorService.php` | Anti-duplikasi guard, query `whereDate`, dan default fallback atribut | Mencegah duplikasi pending prediction untuk bar acuan yang sama, memperbaiki pencocokan snapshot acuan pada tanggal yang sama, dan mencegah exception NOT NULL. |
| `laravel-app/tests/Feature/EodIngestionHardeningTest.php` | File pengujian Feature baru (4 skenario integrasi) | Menguji proteksi kegagalan sinkronisasi, pencegahan registrasi prediksi dari data gagal, isolasi AI live, dan keakuratan monitor. |
| `.gitignore` | Menambahkan pola `data/` | Mencegah file CSV data harian hasil unduhan operasional mengotori commit Git. |

---

## 3. Alur Pengunduhan Sebelum dan Sesudah Perubahan

### Sebelum Perubahan (Fase 7.0):
```text
Task Scheduler (16:05 WIB) 
  -> run_eod_pipeline.bat
    -> hermes:sync-eod
      -> hermes_adapter.py --action market_snapshots
        -> Baca statis CSV di E:\hermes-stock-agent\data\raw_daily\
        -> Data CSV berakhir di 14 Oktober 2026 (stale)
      -> Laravel menganggap data sukses karena tidak ada error
      -> Menghasilkan analisis fallback
      -> Mendaftarkan 10 prediksi baru untuk tanggal acuan lama (14 Oktober)
    -> Pipeline selesai dengan exit code 0 (Data Stale Masked)
```

### Sesudah Perubahan (Fase 7.1):
```text
Task Scheduler (16:05 WIB) 
  -> run_eod_pipeline.bat
    -> hermes:sync-eod (strictFreshness=true, autoDownload=true)
      -> hermes_adapter.py --action market_snapshots
        -> Unduh batch EOD dari Yahoo Finance (yf.download)
        -> Validasi struktur OHLCV, batas nilai, dan konsistensi tanggal
        -> Merge dan simpan atomik (.tmp -> os.replace) ke E:\Project-bot\data\raw_daily\
        -> Periksa jam bursa (>= 16:00 WIB) dan tanggal bar penutupan hari ini
      -> KONDISI A: Data Valid & Mutakhir (is_fresh = true):
          -> Simpan MarketSnapshot mutakhir ke database
          -> Jalankan analisis fallback kebijakan aman
          -> Daftarkan prediksi baru (hanya jika bar acuan belum pernah didaftarkan)
          -> Selesai exit code 0
      -> KONDISI B: Unduhan Gagal / Belum Final / Stale (is_fresh = false):
          -> Batalkan proses EOD segera
          -> Catat error di AnalysisRun (status = failed)
          -> Update status DataSource menjadi 'degraded'
          -> TIDAK ADA prediksi baru didaftarkan
          -> TIDAK ADA inferensi AI dijalankan
          -> Kembalikan exit code 1 (Command::FAILURE)
          -> Runner batch menangkap exit code 1 dan membatalkan evaluasi horizon
```

---

## 4. Bukti Validasi Data per Ticker

Fungsi `validate_eod_dataframe` menegakkan 7 kriteria kepatuhan kontraktual:
1. **Kelengkapan Kolom:** Wajib memiliki `Open`, `High`, `Low`, `Close`, `Volume`.
2. **Ketiadaan Nilai Kosong:** 0 nilai null atau NaN pada bar valid.
3. **Kepositifan Harga:** `Open > 0`, `High > 0`, `Low > 0`, `Close > 0`.
4. **Volume Non-Negatif:** `Volume >= 0`.
5. **Integritas Geometris Bar:** `High >= Low`, `High >= max(Open, Close) * 0.999`, dan `Low <= min(Open, Close) * 1.001`.
6. **Integritas Temporal:** Tanggal harus berformat `YYYY-MM-DD`, tidak ada duplikasi tanggal, dan tidak ada bar di masa depan (`Date <= today_wib`).
7. **Penyimpanan Atomik:** Penyimpanan ke disk menggunakan file sementara `.tmp` dan fungsi atomic rename `os.replace` untuk mencegah korupsi file jika terjadi pemutusan daya tiba-tiba.

Hasil audit validasi pada 10 ticker aktif:
```text
Ticker | Simbol Bursa | Status Validasi | Kolom Lengkap | Nilai Positif | Rentang H/L Valid | Zero Duplicate
BBCA   | BBCA.JK      | VALID (PASS)    | 5/5 Kolom     | Ya            | Ya                | Ya
BBRI   | BBRI.JK      | VALID (PASS)    | 5/5 Kolom     | Ya            | Ya                | Ya
BMRI   | BMRI.JK      | VALID (PASS)    | 5/5 Kolom     | Ya            | Ya                | Ya
BBNI   | BBNI.JK      | VALID (PASS)    | 5/5 Kolom     | Ya            | Ya                | Ya
ASII   | ASII.JK      | VALID (PASS)    | 5/5 Kolom     | Ya            | Ya                | Ya
TLKM   | TLKM.JK      | VALID (PASS)    | 5/5 Kolom     | Ya            | Ya                | Ya
MAPI   | MAPI.JK      | VALID (PASS)    | 5/5 Kolom     | Ya            | Ya                | Ya
ARTO   | ARTO.JK      | VALID (PASS)    | 5/5 Kolom     | Ya            | Ya                | Ya
MEDC   | MEDC.JK      | VALID (PASS)    | 5/5 Kolom     | Ya            | Ya                | Ya
SMRA   | SMRA.JK      | VALID (PASS)    | 5/5 Kolom     | Ya            | Ya                | Ya
```

---

## 5. Kebijakan untuk Data Stale dan Data Belum Final

1. **Jadwal Sebelum 16:00 WIB di Hari Bursa:**
   - `HermesSyncEodCommand` memeriksa status `isEodFinal(nowWib)`. Jika waktu sistem belum mencapai penutupan pasar BEI (16:00 WIB), pipeline scheduled tidak memaksakan penarikan data dan keluar dengan pesan status bahwa pasar belum tutup.
2. **Jadwal Pasca 16:00 WIB dengan Data Belum Dipublikasikan:**
   - Jika Yahoo Finance mengalami keterlambatan dalam menerbitkan penutupan final hari berjalan, bar yang terbaca akan memiliki tanggal `< expected_trading_date`.
   - Adapter menandai `is_fresh = False` dan mengembalikan pesan error eksplisit: `"Data EOD belum final atau masih stale. Bar paling lama: {bar_date}, tanggal diharapkan: {expected_date}."`
   - Data lokal lama tidak diubah.
   - Database tidak mencatat snapshot palsu.
3. **Pemberian Dispensasi Eksplisit (`--force`):**
   - Flag `--force` ditujukan khusus untuk pengujian regresi atau catch-up manual di luar jam bursa. Pada mode ini, pemeriksaan finalitas dilonggarkan, namun pada mode terjadwal normal (`--trigger=scheduled`), penegakan kesegaran bersifat mutlak.

---

## 6. Hasil Pengujian Otomatis

Pengujian dilakukan secara otomatis menggunakan mock data dan pengujian integrasi terisolasi:

```text
================================================================================
HASIL REKAPITULASI SUITE PENGUJIAN OTOMATIS
================================================================================
1. Laravel PHPUnit (E:\Project-bot\laravel-app):
   - Total Tests      : 64 test cases
   - Total Assertions : 215 assertions
   - Status           : PASSED (100%)
   - Durasi           : 67.2 detik
   - Exit Code        : 0

2. Python Adapter Bridge Tests (E:\Project-bot\services):
   - Total Tests      : 9 test cases
   - Status           : PASSED (100%)
   - Durasi           : 8.7 detik
   - Exit Code        : 0

3. Hermes Stock Agent Tests (E:\hermes-stock-agent):
   - Total Tests      : 64 test cases
   - Status           : PASSED (100%)
   - Durasi           : 54.1 detik
   - Exit Code        : 0
================================================================================
TOTAL PENGUJIAN SISTEM: 137 TEST CASES LULUS (0 FAILED, EXIT CODE 0)
================================================================================
```

Rincian 12 Skenario Wajib Fase 7.1:
- [x] **Skenario 1 (Semua ticker valid):** Terverifikasi via `test_eod_validation_valid_and_invalid_scenarios`.
- [x] **Skenario 2 (Sebagian ticker gagal):** Terverifikasi via `test_market_snapshots_mock_freshness_enforcement`.
- [x] **Skenario 3 (Penyedia kosong):** Terverifikasi via `test_eod_validation_valid_and_invalid_scenarios`.
- [x] **Skenario 4 (Data stale):** Terverifikasi via `test_market_snapshots_mock_freshness_enforcement`.
- [x] **Skenario 5 (Duplikat & OHLCV invalid):** Terverifikasi via `test_eod_validation_valid_and_invalid_scenarios`.
- [x] **Skenario 6 (Belum final):** Terverifikasi via `test_market_snapshots_mock_freshness_enforcement`.
- [x] **Skenario 7 (Preservasi file lama):** Terverifikasi via `test_eod_atomic_csv_preservation_on_failure`.
- [x] **Skenario 8 (Prediksi tidak terdaftar saat gagal):** Terverifikasi via `test_scenario_8_and_10_predictions_not_registered_when_eod_fails`.
- [x] **Skenario 9 (Anti-duplikasi snapshot identik):** Terverifikasi via `test_scenario_9_identical_snapshot_does_not_duplicate_predictions`.
- [x] **Skenario 10 (Runner tangkap exit code gagal):** Terverifikasi via `test_scenario_8_and_10_predictions_not_registered_when_eod_fails`.
- [x] **Skenario 11 (Live AI tetap disabled):** Terverifikasi via `test_scenario_11_live_ai_remains_safely_disabled`.
- [x] **Skenario 12 (Keakuratan monitor):** Terverifikasi via `test_scenario_12_monitor_reports_accurate_latest_bar_date` dan `php artisan hermes:monitor`.

---

## 7. Kompatibilitas Scheduler & Isolasi Operasional

1. **Windows Task Scheduler:**
   - Task `\HermesMarketIntelligence_EOD` tetap memanggil `E:\Project-bot\run_eod_pipeline.bat`.
   - Batch runner yang diperkeras di Fase 6.12 memeriksa `%SYNC_EXIT%`. Jika `hermes:sync-eod` keluar dengan exit code 1, evaluasi horizon dibatalkan dan Task Scheduler mencatat kode kesalahan tersebut.
2. **Isolasi Live AI & Kerahasiaan Kredensial:**
   - Konfigurasi `AI_LIVE_INFERENCE_ENABLED=false` tetap aktif pada runtime operasional.
   - Tidak ada token, API key, atau header Authorization yang dicetak ke output log maupun laporan.
   - Database 9Router lokal membuktikan 0 request inferensi baru selama pelaksanaan Fase 7.1.
3. **Kepatuhan Git:**
   - Tidak ada commit atau push yang dilakukan pada kedua repositori.
   - Repositori `E:\hermes-stock-agent` tidak mengalami perubahan file terindeks (working tree bersih dari modifikasi fungsional).

---

## 8. Langkah Pemeriksaan Jadwal Berikutnya (Selasa, 20 Oktober 2026 16:05 WIB)

Setelah scheduler berjalan pada 20 Oktober 2026 pukul 16:05 WIB:
1. Periksa riwayat Task Scheduler:
   `schtasks /query /tn "HermesMarketIntelligence_EOD" /v /fo list`
   Pastikan `Last Run Time` tertulis `10/20/2026 4:05:00 PM` dan `Last Result` adalah `0`.
2. Periksa log eksekusi runner:
   `cat E:\Project-bot\laravel-app\storage\logs\eod_pipeline_output.log`
   Pastikan marker `[START]` dan `[FINISH]` ada dan mencatat keberhasilan sinkronisasi bar tanggal 20 Oktober 2026.
3. Periksa status operasional lewat CLI monitor:
   `php artisan hermes:monitor`
   Pastikan bagian `[2. INGESTI DATA PASAR]` melaporkan:
   `Bar Date Terakhir: 20 Oct 2026` dengan cakupan `10 / 10 saham aktif`.
4. Periksa evaluasi horizon:
   Pastikan tidak ada duplikasi record acuan lama dan record baru terdaftar untuk evaluasi +5 hari bursa (target 27 Oktober 2026).

---

## 9. Status Akhir

`IMPLEMENTED — TESTS PASSED — LIVE AI DISABLED`
Seluruh sasaran, kriteria pengujian, dan batasan keselamatan FASE 7.1 telah terpenuhi secara penuh.
