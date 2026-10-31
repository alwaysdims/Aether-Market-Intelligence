# Aether Market Intelligence ⚡

<p align="center">
  <img src="dashboard/public/images/logo.png" alt="Aether Market Intelligence Logo" width="128" height="128" style="border-radius: 20px;">
</p>

Platform intelijen pasar modal Indonesia (Bursa Efek Indonesia / BEI) berbasis integrasi web dashboard, mesin analitik kuantitatif Python (Hermes Stock Agent), model penalaran AI Gemini 3.8 Flash High, dan notifikasi Telegram terpadu.

**Repositori Resmi:** [https://github.com/alwaysdims/Aether-Market-Intelligence](https://github.com/alwaysdims/Aether-Market-Intelligence)  

> ⚠️ **DISCLAIMER RISET, BATASAN SISTEM & ETIKA AUDIT:**  
> 1. Sistem ini dibuat untuk tujuan riset kuantitatif, otomasi pengolahan data pasar, dan evaluasi strategi perdagangan saham secara simulasi (paper trading). Sistem **TIDAK TERHUBUNG DENGAN BROKER LIVE**, **TIDAK MENGIRIM ORDER SUNGGUHAN**, dan **TIDAK MENJAMIN KEUNTUNGAN FINANSIAL**.  
> 2. **Audit Evaluasi Historis:** 30 evaluasi historis dengan target 21 Oktober 2026 berstatus *rule-based fallback non-directional* (`unclear`) dan dihasilkan dari 3 batch pengujian pada bar acuan yang sama (14 Oktober 2026). Hasil evaluasi ini berstatus `neutral` dan **TIDAK BOLEH** diklaim sebagai bukti akurasi model AI atau sebagai 30 observasi independen.

---

## 1. Struktur Folder Konsolidasi

Seluruh pengembangan, operasi, dan demo dijalankan dari root tunggal `E:\Aether-Market-Intelligence`:

```text
E:\Aether-Market-Intelligence/
├── dashboard/                  # Laravel Control Plane (Blade, Eloquent, API, Web UI)
│   ├── app/Console/Commands/   # HermesSyncEod, HermesEvaluateHorizon, HermesMonitor, HermesBackfillSnapshots
│   ├── app/Services/           # HermesAdapterService, PostHorizonEvaluatorService, BeiCalendarService, SystemHealthService
│   ├── tests/Feature/          # EvaluatorRecoveryTest, EodIngestionHardeningTest, AiMarketAnalysisProvenanceTest
│   └── ...
├── services/                   # Python Interop Adapter (Jembatan CLI JSON Contract)
│   ├── hermes_adapter.py       # Auto-download Yahoo Finance, EOD validator, dynamic engine resolution
│   └── test_hermes_adapter.py  # 9 Unit test adapter
├── quant-engine/               # Core Hermes Stock Agent Engine (Riset Kuantitatif & Simulator)
│   ├── src/                    # Backtester, paper_trading_engine, calendar_utils, feature_engineering
│   ├── tests/                  # 64 pytest test suite
│   ├── data/raw_daily/         # Baseline 1.391 bar (hingga 14 Okt 2026) - TERPISAH & STERIL
│   └── .git/                   # Git repository mandiri
├── data/                       # Data operasional dashboard & adapter
│   └── raw_daily/              # 1.398 bar (hingga 23 Okt 2026) untuk 10 saham aktif IDX
├── scripts/                    # Runner dan utilitas operasional Windows
│   ├── start_dashboard.bat     # Launcher web server localhost:8000 (aman, hanya web)
│   ├── check_system.bat        # Diagnostic 6 pilar read-only (nol tulis, nol request AI)
│   ├── run_eod_pipeline.bat    # Hardened runner EOD otomatis (dipanggil Task Scheduler)
│   └── run_scheduler.bat       # Runner scheduler:run
├── run_eod_pipeline.bat        # Root launcher wrapper
├── logs/                       # Log eksekusi root (scheduler_eod.log)
├── reports/                    # Seluruh laporan evaluasi dan audit historis
├── docs/                       # Dokumentasi arsitektur, panduan presentasi, dan git
└── README.md
```

---

## 2. Status Fitur Terverifikasi

| Komponen / Fitur | Status | Deskripsi Hasil Verifikasi |
| :--- | :---: | :--- |
| **Laravel Control Plane** | `PASS` | 73 feature & unit test lulus (249 assertions). Database testing terisolasi di `:memory:`. |
| **Python Adapter Bridge** | `PASS` | 9 unit test lulus. Validasi OHLCV, auto-download multi-ticker, dynamic path engine. |
| **Quant Engine (EXP-04)** | `PASS` | 64 pytest lulus. Akuntansi kas non-negatif, zero same-bar event inversion, lot sizing BEI. |
| **Data Pasar Operasional** | `PASS` | 130 snapshot EOD tersimpan di PostgreSQL (termasuk 10 bar final 21 Okt 2026). |
| **Post-Horizon Evaluator** | `PASS` | 30 record target 21 Okt dievaluasi `neutral` (`actual_price_date` tepat 21 Okt). 10 record target 27 Okt tetap `pending`. |
| **Windows Task Scheduler**| `PASS` | Task `\HermesMarketIntelligence_EOD` aktif menunjuk `E:\Aether-Market-Intelligence\scripts\run_eod_pipeline.bat`. |
| **Sistem AI Governance**   | `PASS` | Mode operasional aman `AI_LIVE_INFERENCE_ENABLED=false` aktif. Zero biaya, zero request ke 9Router. |

---

## 3. Langkah Menjalankan Demo Presentasi

Untuk keperluan presentasi dosen atau pengujian lokal:

### 1. Menjalankan Web Dashboard:
Cukup klik ganda atau jalankan melalui terminal:
```cmd
E:\Aether-Market-Intelligence\scripts\start_dashboard.bat
```
Buka browser pada: **`http://localhost:8000`**

### 2. Memeriksa Diagnostik Sistem (Read-Only):
Jalankan script pemeriksaan cepat tanpa risiko modifikasi data atau inferensi berbayar:
```cmd
E:\Aether-Market-Intelligence\scripts\check_system.bat
```

Panduan lengkap skenario presentasi per halaman dapat dibaca di:
👉 **[`docs/PANDUAN_PRESENTASI.md`](docs/PANDUAN_PRESENTASI.md)**

---

## 4. Hasil Pengujian Otomatis (146/146 PASS)

Semua pengujian pada ketiga pilar modular berhasil dijalankan dengan hasil **100% Lulus**:
- **Laravel PHPUnit:** 73 tests passed, 249 assertions (`dashboard/`)
- **Python Adapter:** 9 tests passed (`services/`)
- **Quant Engine Pytest:** 64 tests passed (`quant-engine/`)
- **Total:** 146 tests passed.

Untuk menjalankan seluruh tes:
```bash
# 1. Tes Dashboard Laravel
cd E:\Aether-Market-Intelligence\dashboard && php artisan test

# 2. Tes Adapter Python
cd E:\Aether-Market-Intelligence\services && python test_hermes_adapter.py

# 3. Tes Quant Engine
cd E:\Aether-Market-Intelligence\quant-engine && python -m pytest tests/
```

---

## 5. Konfigurasi Task Scheduler Windows

Jadwal sinkronisasi EOD bursa otomatis terdaftar pada Windows Task Scheduler:
- **Nama Task:** `\HermesMarketIntelligence_EOD`
- **Status:** `Ready`
- **Jadwal:** Setiap hari bursa (Senin – Jumat) pukul 16:05:00 WIB
- **Target Action:** `E:\Aether-Market-Intelligence\scripts\run_eod_pipeline.bat`
- **Pengaturan Daya:** `DisallowStartIfOnBatteries = False`, `StopIfGoingOnBatteries = False`, `WakeToRun = True`, `StartWhenAvailable = True`.

---

## 6. Prosedur Rollback Terpisah

Sistem memisahkan mekanisme rollback menjadi 3 komponen independen:

1. **Rollback Kode:**
   Folder lama `E:\Project-bot` dan `E:\hermes-stock-agent` dipertahankan utuh sebagai cadangan sementara. Pengguna dapat kembali ke folder lama kapan saja.
2. **Rollback Task Scheduler:**
   Jalankan perintah berikut di PowerShell jika ingin mengembalikan task ke folder lama:
   ```powershell
   $service = New-Object -ComObject("Schedule.Service"); $service.Connect(); $task = $service.GetFolder("\").GetTask("HermesMarketIntelligence_EOD"); $def = $task.Definition; $def.Actions.Item(1).Path = "E:\Project-bot\run_eod_pipeline.bat"; $def.Actions.Item(1).WorkingDirectory = "E:\Project-bot"; $service.GetFolder("\").RegisterTaskDefinition("HermesMarketIntelligence_EOD", $def, 4, $null, $null, 3)
   ```
3. **Rollback Database PostgreSQL:**
   Pulihkan snapshot database sebelum eksekusi backfill:
   ```bash
   psql.exe -h 127.0.0.1 -p 5432 -U postgres -d project-bot -f "E:\backups_pre_consolidation_20261021\project_bot_backup_20261024_211314.sql"
   ```

---

## 7. Referensi Dokumentasi & Laporan Audit
- [Laporan Akhir Tahap I: Database PostgreSQL, Pemantauan 14 Hari & Telegram Dua Arah (reports/TAHAP_I_FINAL_REPORT.md)](reports/TAHAP_I_FINAL_REPORT.md)
- [Audit Arsitektur & Analisis Gap Tahap I (reports/TAHAP_I_GAP_ANALYSIS.md)](reports/TAHAP_I_GAP_ANALYSIS.md)
- [Siklus Hidup Prediksi & Kontrak Status (docs/PREDICTION_LIFECYCLE.md)](docs/PREDICTION_LIFECYCLE.md)
- [Integrasi Telegram Dua Arah & Protokol Interaksi (docs/TELEGRAM_TWO_WAY.md)](docs/TELEGRAM_TWO_WAY.md)
- [Pemantauan Pasar Bursa & Deteksi Event (docs/MARKET_MONITORING.md)](docs/MARKET_MONITORING.md)
- [Panduan Restart, Pemulihan Sistem & Rollback (docs/RESTART_AND_RECOVERY.md)](docs/RESTART_AND_RECOVERY.md)
- [Laporan Akhir Tahap H: Data Forward, Rezim Pasar & Prediksi Bayangan (reports/ml/TAHAP_H_FINAL_REPORT.md)](reports/ml/TAHAP_H_FINAL_REPORT.md)
- [Status Pengumpulan Data Forward Harian (reports/data/forward_collection_status.md)](reports/data/forward_collection_status.md)
- [Analisis Deteksi Rezim Pasar IHSG (reports/ml/market_regime_analysis.md)](reports/ml/market_regime_analysis.md)
- [Ablation Study Makroekonomi & Rezim (reports/ml/macro_ablation.md)](reports/ml/macro_ablation.md)
- [Protokol Prediksi Bayangan & Evaluasi Horizon (reports/ml/shadow_prediction_protocol.md)](reports/ml/shadow_prediction_protocol.md)
- [Laporan Akhir Tahap G: Integritas Backtest, Ekspansi Data & Prediksi (reports/ml/TAHAP_G_FINAL_REPORT.md)](reports/ml/TAHAP_G_FINAL_REPORT.md)
- [Audit Integritas Backtest & Rekonsiliasi Kas (reports/ml/backtest_integrity_audit.md)](reports/ml/backtest_integrity_audit.md)
- [Audit Validasi Temporal & Walk-Forward Overlap (reports/ml/temporal_validation_audit.md)](reports/ml/temporal_validation_audit.md)
- [Laporan Feature Ablation Study (reports/ml/feature_ablation.md)](reports/ml/feature_ablation.md)
- [Laporan Perbandingan Model Tahap G (reports/ml/tahap_g_model_comparison.md)](reports/ml/tahap_g_model_comparison.md)
- [Analisis Prediksi Selektif & Target 90% (reports/ml/selective_prediction_analysis.md)](reports/ml/selective_prediction_analysis.md)
- [Validasi Sinyal Berita & Disiplin Point-in-Time (reports/news/news_signal_validation.md)](reports/news/news_signal_validation.md)
- [Registri Sumber Data Gratis Rp0 (docs/DATA_SOURCES.md)](docs/DATA_SOURCES.md)
- [Kamus Data & Spesifikasi Provenance (docs/DATA_DICTIONARY.md)](docs/DATA_DICTIONARY.md)
- [Protokol Eksperimen Machine Learning (docs/ML_EXPERIMENT_PROTOCOL.md)](docs/ML_EXPERIMENT_PROTOCOL.md)
- [Laporan Audit & Reproduksi ML Lama (reports/ml/baseline_reproduction.md)](reports/ml/baseline_reproduction.md)
- [Laporan Audit Kualitas Data EOD (reports/ml/data_quality_audit.md)](reports/ml/data_quality_audit.md)
- [Panduan Presentasi & Demo Dosen](docs/PANDUAN_PRESENTASI.md)
- [Panduan Manajemen Git Dual-Repo](docs/GIT_MANAGEMENT.md)
- [Panduan Restart & Startup Server](docs/PANDUAN_RESTART_DAN_STARTUP.md)
- [Laporan Konsolidasi & Evaluator Recovery](reports/consolidation_and_evaluator_recovery.md)
