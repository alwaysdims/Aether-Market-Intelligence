# Laporan Penerimaan Dashboard V3 Tahap 3: Pemantauan Rekomendasi & Rekap Hasil

**Status**: ACCEPTED & VERIFIED • **Versi**: 3.3.0 • **Tanggal**: 1 November 2026 WIB  
**Workspace**: `E:\Aether-Market-Intelligence` • **Arsitektur**: Laravel 13 + Tailwind v4 / Vite 8 + PostgreSQL + Hermes Engine

---

## 1. Ringkasan Eksekutif & Verifikasi UI Tahap 2

1. **Pemeriksaan Tab Monitoring & Technical**:
   - Tab 2 (Pemantauan): Menyajikan tabel timeline per rekomendasi dengan kolom lengkap (`SIGNAL / PRED ID`, `ASAL / MODEL`, `WAKTU PUBLIKASI`, `HARGA ACUAN VS TERKINI`, `ARAH SINYAL`, `TARGET & BATAS`, `JATUH TEMPO`, `STATUS EVALUASI`).
   - Tab 3 (Teknikal): Mengunci input bar pada batas data aktual (maksimal 30 bar untuk grafik, input bar indikator sesuai kecukupan matematis: SMA20=20, SMA50=50, SMA200=200; jika data kurang, bernilai `null` / `N/A`, tanpa mengisi bar sintetis fiktif).
   - Keterangan Sumber EPS: Dilabeli secara tegas sebagai **`PROVIDER_SUPPLIED`** (Feed IDX Edge PRO / Emiten), bukan dokumen terverifikasi audit fisik BEI sebelum ada bukti PDF.
2. **Kesesuaian Tampilan Mobile & Desktop**:
   - Diuji pada viewport desktop (1440/1280) dan mobile (768/390px): navigasi tab horizontal responsif dengan scroll mendatar, teks tabular-nums rapi, dan kartu ringkasan menyusut adaptif tanpa overflow.

---

## 2. Hubungan Rekomendasi dengan Sistem Monitoring

Alur publikasi kandidat hingga evaluasi ditegakkan secara ketat dan konsisten:
- **Atribut Rekomendasi Terstruktur**:
  Setiap rekomendasi *actionable* wajib memiliki:
  1. `prediction_id`: Pengidentifikasi unik sinyal (misal `REC_SCREEN_BBCA_20261030` atau `SHADOW_...`).
  2. `ticker`: Kode saham resmi BEI 4 huruf.
  3. `origin / model_name`: Asal model/strategi (misal: `RandomForest`, `SCREENING_RULE_SWING`, `HERMES_AGENT_CONSULTATION`).
  4. `feature_schema_hash` / `config_hash`: Hash konfigurasi beku yang tidak dapat diubah setelah terbit.
  5. `published_at` & `decision_timestamp`: Stempel waktu keputusan (WIB/UTC) sebelum sesi bursa yang dievaluasi.
  6. `reference_price` & `reference_price_date`: Harga acuan resmi EOD bursa.
  7. `predicted_horizon_days` & `evaluation_due_date`: Batas horizon trading days (T+5 hari bursa resmi BEI melalui `BeiCalendarService`).
  8. `target_price` & `invalidation_price`: Target profit dan batas pembatal (hanya untuk strategi yang menetapkannya; jika tidak ada, berstatus `Horizon Only`).
- **Penyaringan Non-Actionable**:
  Kueri edukasi umum, daftar pergerakan broker tanpa sinyal, dan konsultasi deskriptif dilarang otomatis mendaftarkan sinyal arah ke tabel `prediction_signals`.

---

## 3. Pemisahan Tiga Jenis Evaluasi

Sistem memisahkan secara fisik dan analitis tiga kelas sinyal untuk mencegah pencampuran akurasi:
1. **Machine Learning (`RandomForest` / `EXP-04`)**:
   - Dievaluasi murni pada 10 emiten konstituen tervalidasi out-of-sample.
   - Horizon: T+5 hari bursa kalender BEI.
2. **Deterministic Screening Rule (`SCREENING_RULE_SWING`)**:
   - Sesuai kontrak `BROKER_ACCUMULATION_SCREENING_V1` atau `SWING_FILTER_V1`.
   - Menguji persistensi akumulasi broker dan target harga tick terdefinisi.
3. **Agent Consultation (`HERMES_REASONING_WORKER`)**:
   - Sesuai kontrak terdaftar sebelum publikasi, dengan penautan `Evidence Snapshot` dan `Market Analysis ID`.

Jendela evaluasi rekap 30 hari adalah jendela waktu pelaporan visual (*reporting window*), **bukan** horizon universal pergerakan saham.

---

## 4. Otomatisasi Outcome & Penanganan Kasus Ambigu / Expired

Layanan `MarketMonitoringService` (dijalankan terjadwal oleh task scheduler via `hermes:monitor-market` dan pipeline EOD `run_eod_pipeline.bat`):
1. **Pemeriksaan Kualitas Data**:
   - Evaluasi hanya membaca bar EOD resmi `is_eod_final = true`, status `VALID`, dan bersumber dari `IDX Edge PRO`. Data kuotasi intraday (`QUARANTINED`) diblokir dari proses evaluasi.
2. **Penanganan Kasus Khusus**:
   - **Belum Jatuh Tempo**: Berstatus `PENDING` (Anti-lookahead aktif; tidak dinilai sebelum `evaluation_due_date`).
   - **Target Hit & Invalidation pada Bar yang Sama**: Ditandai **`AMBIGUOUS`** / `AMBIGUOUS_HIT` karena urutan mikro-tick dalam 1 candle harian tidak dapat dipastikan tanpa running trade tick-by-tick.
   - **Target Hit Tunggal**: High mencapai `target_price` -> `TARGET_HIT`.
   - **Invalidation Hit Tunggal**: Low menembus `invalidation_price` -> `INVALIDATED`.
   - **Masa Berlaku Habis (14 Hari Kalender)**: Ditandai **`EXPIRED`** dengan pencatatan `MonitoringEvent`.
   - **Immutabilitas**: Target dan aturan harga acuan tidak dapat diubah surut setelah hasil pasar keluar.

---

## 5. Tampilan Dashboard & Rekapitulasi

1. **Dashboard Utama (`/`)**:
   - Menampilkan kartu *Tindak Lanjut & Siklus Hidup Rekomendasi* dengan status jelas: jika belum ada yang jatuh tempo, menampilkan state kosong transparan *"Tidak Ada Rekomendasi Aktif Menunggu Evaluasi"* tanpa data palsu.
2. **Tab 2 Pemantauan (`/market/{ticker}?tab=monitoring`)**:
   - Timeline sinyal per emiten dengan harga acuan vs harga penutupan terkini, persentase perubahan harga, target/invalidation, dan status evaluasi.
3. **Halaman AI History (`/history`)**:
   - Rekap metrik Evaluasi Akurasi Post-Horizon dengan pemisahan denominator eksplisit: `N/A (Dari 0 directional matang)` dan `0 B / 0 S / 40 N` (40 sinyal menunggu jatuh tempo).
   - Menambahkan kolom **Tautan Analisis** yang menghubungkan langsung baris evaluasi ke `Analysis #ID` di detail emiten.
   - Screenshot aktual tersimpan di: `C:\Users\ASUS\AppData\Local\hermes\cache\screenshots\browser_screenshot_d9149a349016427b8125b89d377a4fdf.png` dan `browser_screenshot_67197ee5d3584e498c9a51973309e174.png`.

---

## 6. Integrasi Telegram & Anti-Spam

- Rekapitulasi evaluasi disampaikan melalui digest terukur via command `hermes:prediction-recap --telegram` ke tabel `telegram_outbox`.
- Kunci deduplikasi: `RECAP_{YYYYMMDD_H}` untuk mencegah pengiriman pesan ganda dalam jam yang sama.
- Sistem dilarang mengirim notifikasi per emiten secara berulang atau melakukan broadcast spam saat sistem pertama kali diaktifkan.

---

## 7. Bukti Pengujian Otomatis

Suite pengujian khusus `tests/Feature/RecommendationMonitoringAndOutcomeTest.php` dibuat dan dijalankan:
- `test_recommendation_registration_contract_preserves_immutable_rules`: PASS.
- `test_target_hit_detected_with_valid_final_eod_observation`: PASS.
- `test_ambiguous_hit_when_target_and_invalidation_touched_same_bar`: PASS.
- `test_horizon_evaluation_for_matured_prediction`: PASS.
- `test_future_prediction_not_evaluated_before_maturity`: PASS.
- **Hasil**: 5 tests, 12 assertions PASSED (durasi 637 ms).

---

## 8. Berkas Berubah

1. `dashboard/app/Services/MarketMonitoringService.php`:
   - Penegakan filter observasi EOD `is_eod_final = true`, status `VALID`, isolasi sumber `IDX Edge PRO`.
   - Pemisahan evaluasi `whereDate` untuk kompatibilitas SQLite dan PostgreSQL.
2. `dashboard/app/Services/PredictionRecapService.php`:
   - Pemisahan kohort ML, Screening Rule, dan Konsultasi Agent.
   - Denominator eksplisit untuk akurasi directional.
3. `dashboard/app/Http/Controllers/DashboardController.php`:
   - Penyelarasan kueri sinyal aktif memantau status `ACTIVE` dan `MONITORING`.
4. `dashboard/resources/views/dashboard/ticker_tab_content.blade.php`:
   - Penyempurnaan tabel monitoring di Tab 2 dengan kolom perbandingan harga terkini dan tautan evidence.
5. `dashboard/resources/views/dashboard/history.blade.php`:
   - Penambahan kolom Tautan Analisis dan penegakan label denominator matang pada metrik horizon.
6. `dashboard/tests/Feature/RecommendationMonitoringAndOutcomeTest.php`:
   - Test suite baru untuk siklus hidup rekomendasi, target hit, same-bar ambiguous, dan anti-lookahead.

---

---

## 10. Finalisasi Kontrak Monitoring & Tata Kelola Evaluasi

### a. Pemisahan Empat Identitas Strategi & Model
Sistem memisahkan secara ketat empat entitas model/strategi tanpa saling menimpa atau mengubah kontrak historis setelah outcome:
1. **`RandomForest_v1.0` (ML)**: Model inferensi probabilitas kuantitatif out-of-sample pada 10 konstituen BEI dengan horizon T+5 hari bursa, ambang batas $\tau = 0.70$, dan fitur ter-hash `feature_schema_hash`.
2. **`EXP-04` (Strategi Aturan Teknikal)**: Evaluasi aturan berbasis geometri bursa dan filter teknikal deterministik.
3. **`SWING_FILTER_V1` (Filter Ayunan Harga)**: Filter pemilihan saham berbasis batas rentang harga dan likuiditas EOD.
4. **`BROKER_ACCUMULATION_SCREENING_V1` (Screening Akumulasi Broker)**: Screening aliran modal agregat broker Pasar Reguler dengan ambang batas net buy dan konsentrasi Top 3.
*Aturan Kontrak Non-ML*: Untuk sinyal screening non-ML, `model_name` dan `feature_schema_hash` diperbolehkan null/opsional, namun **wajib** mencatat `origin`, `strategy_version`, `config_hash`, `published_at`, `evidence_cutoff`, dan `evaluation_rule` yang berlaku.

### b. Pemisahan Siklus Hidup dan Horizon Evaluasi
- **Evaluasi Arah (T+5)**: Dievaluasi murni pada saat tanggal jatuh tempo horizon (`evaluation_due_date`) telah dicapai menggunakan harga penutupan resmi bar EOD final (`is_eod_final = true` & `validation_status = 'VALID'`).
- **Monitoring Target & Invalidation**: Dipantau setiap sesi bursa setelah tanggal publikasi hingga masa berlaku berakhir.
- **Expiry Kontrak**: Maksimal 14 hari kalender dari waktu publikasi.
- **Rekapitulasi 30 Hari**: Berfungsi murni sebagai jendela pelaporan (*reporting window*) untuk dashboard, bukan horizon universal sinyal.

### c. Penanganan Data Sesi Tidak Lengkap (*Coverage Gap*)
- Jika masa berlaku 14 hari tercapai tetapi data sesi bursa yang relevan tidak ditemukan (0 bar EOD terverifikasi), sistem **dilarang menyatakan clean EXPIRED** atau mengklaim target tidak tersentuh.
- Status diubah menjadi **`EXPIRED_WITH_COVERAGE_GAP`** dengan pencatatan `verified_sessions_checked = 0` pada metadata `MonitoringEvent`.
- Label `is_eod_final` tidak boleh menggantikan validasi sesi kalender bursa dan sumber data operasional (`IDX Edge PRO`).

### d. Rekonsiliasi 40 Neutral (40 N) pada Halaman AI History
Rasio keputusan selesai `0 B / 0 S / 40 N` pada halaman AI History merefleksikan komposisi audit:
- **30 Rekod**: Evaluasi fallback historis (*historical fallback evaluations*) pra-Tahap I yang dipisahkan secara analitis dari kohort forward.
- **10 Rekod**: Sinyal forward berstatus **`ABSTAIN` / `NO_SIGNAL_ABSTAIN`** pada 10 emiten konstituen (karena confidence score model berada di bawah ambang batas selektif $\ge 40\%$ / $\tau=0.70$).
- **Denominator Akurasi Directional**: Murni dihitung dari jumlah prediksi terarah yang jatuh tempo ($\text{Correct} + \text{Incorrect}$). Karena seluruh sinyal 40 N adalah non-directional/abstain, denominator directional bernilai **0**, sehingga akurasi directional berstatus transparan **`N/A (Dari 0 directional matang)`**.

### e. Deduplikasi Granular Outbox Telegram
- Kunci deduplikasi pesan digest tidak lagi sekadar berbasis jam (misal `RECAP_YYYYMMDD_H`), melainkan menggabungkan:
  $$\text{DedupKey} = \text{RECAP\_COHORT\_V1\_}\{\text{chat\_id}\}\_\{\text{eval\_period\_date}\}\_\{\text{cohort\_result\_hash}\}$$
- Menjamin pesan dengan versi hasil berbeda tidak tertahan secara keliru, dan hasil identik tidak terkirim berulang.


## 11. Keterbatasan yang Belum Terselesaikan

1. **Feed Intraday**: Quote intraday Pasar Reguler (`/api/price`) tetap berstatus **`QUARANTINED`** karena kontaminasi Pasar Negosiasi (NG). Keputusan Day Trade tetap berstatus **`DAY_TRADE_NOT_ASSESSABLE_WITH_AVAILABLE_DATA`**.
2. **Evaluasi Live Sinyal Berjalan**: Seluruh sinyal forward yang diterbitkan pada sesi 30 Oktober 2026 berstatus **`PENDING`** karena jatuh tempo evaluasi 5 hari bursa baru tercapai pada **06 November 2026**. Outcome live tetap dilaporkan `PENDING` sesuai prinsip anti-lookahead tanpa memalsukan data.

