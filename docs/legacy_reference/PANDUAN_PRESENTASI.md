# PANDUAN PRESENTASI & DEMO SISTEM
## AETHER MARKET INTELLIGENCE

Dokumen ini disusun sebagai panduan resmi demonstrasi sistem Aether Market Intelligence untuk keperluan akademik / presentasi dosen dan pengujian operasional.

---

## 1. Prasyarat dan Cara Menyalakan Sistem

### A. Prasyarat Lingkungan (Host Windows 11)
- **PHP:** PHP 8.3.7 (tersedia di `C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe` atau PATH sistem).
- **PostgreSQL Database:** Service `postgresql-x64-16` aktif di port 5432 (database: `project-bot`).
- **Python Environment:** Python 3.14 dengan pustaka `pandas`, `yfinance`, `scikit-learn`, `pytest`.
- **9Router Service (Opsional):** Berjalan di port lokal `http://127.0.0.1:20128` (hanya jika live AI diuji; secara default live AI dinonaktifkan demi keselamatan operasional).
- **Hermes Gateway (Opsional):** PID background aktif untuk pengiriman alert Telegram.

### B. Cara Menyalakan Dashboard
Cukup jalankan script launcher web yang telah disediakan:
```cmd
E:\Aether-Market-Intelligence\scripts\start_dashboard.bat
```
Script ini **hanya** menjalankan Laravel Web Server pada `http://127.0.0.1:8000`. Script ini tidak mengubah database, tidak memicu request AI, dan tidak mengirim alert Telegram.

### C. Cara Menjalankan Diagnostik Cepat (Read-Only)
Sebelum membuka browser, verifikasi kesehatan sistem menggunakan:
```cmd
E:\Aether-Market-Intelligence\scripts\check_system.bat
```
Pemeriksaan ini 100% read-only dan menampilkan status 6 pilar operasional.

---

## 2. Cara Membuka Dashboard Web

Buka browser modern (Google Chrome, Microsoft Edge, atau Mozilla Firefox) dan navigasikan ke:
```text
http://localhost:8000
```
atau
```text
http://127.0.0.1:8000
```

---

## 3. Urutan Skenario Demonstrasi

Untuk memberikan alur presentasi yang terstruktur dan meyakinkan di depan dosen penguji, ikuti urutan halaman berikut:

### Skenario 1: Halaman Utama (Overview / Control Plane)
- **URL:** `http://localhost:8000/`
- **Poin Presentasi:**
  1. Jelaskan arsitektur kontrol panel: Control plane berbasis Laravel Blade + Tailwind CDN + Alpine.js yang menghubungkan model kuantitatif Python dengan antarmuka web interaktif.
  2. Tunjukkan metrik ringkasan pasar EOD: Status 10 universe saham likuid IDX (BBCA, BBRI, BMRI, BBNI, ASII, TLKM, MAPI, ARTO, MEDC, SMRA).
  3. Tunjukkan status Run terakhir: ID eksekusi, trigger, dan durasi sinkronisasi data.

### Skenario 2: Data Pasar (Market Data & Snapshots)
- **URL:** `http://localhost:8000/snapshots` (atau tab Data Pasar)
- **Poin Presentasi:**
  1. Tunjukkan integritas data OHLCV historis: Total 130 bar snapshot tersimpan di database PostgreSQL.
  2. Jelaskan indikator teknikal turunan non-lookahead yang dihitung langsung dari data: ATR-14 (Average True Range), RVOL-20 (Relative Volume), dan SMA-20.
  3. Jelaskan pemisahan data: Data operasional dashboard terisolasi di `data/raw_daily/` dan tidak mencemari data baseline riset kuantitatif di `quant-engine/data/raw_daily/`.

### Skenario 3: Analisis Pasar & AI Governance (Intelligence & Fallback)
- **URL:** `http://localhost:8000/analyses` (atau tab Analisis)
- **Poin Presentasi:**
  1. **Konsep AI Governance:** Sistem dirancang dengan prinsip *Fail-Safe AI*. Ketika inferensi live AI dinonaktifkan (`AI_LIVE_INFERENCE_ENABLED=false`) atau terjadi kegagalan jaringan/kuota pada LLM, sistem secara otomatis beralih ke *Safe Rule-Based Fallback* tanpa pernah menghentikan pipeline bursa.
  2. Tunjukkan provenance audit: Setiap analisis mencatat model target (`ag/gemini-3.8-flash-high`), status validasi, dan penanda fallback (`is_fallback=true`).

### Skenario 4: Evaluator Horizon +5 Hari Bursa (Post-Horizon Evaluation)
- **URL:** `http://localhost:8000/evaluations` (atau tab Evaluasi)
- **Poin Presentasi:**
  1. **Integritas Anti-Lookahead:** Evaluasi prediksi hanya dapat diproses setelah horizon +5 hari bursa kalender BEI terlampaui dan candle penutupan resmi tersedia.
  2. **Audit 30 Evaluasi Target 21 Oktober 2026:**
     - 30 record historis telah berhasil dievaluasi dengan status `evaluated` dan outcome `neutral` karena berasal dari rule-based fallback non-directional (`unclear`).
     - Tunjukkan bahwa `actual_price_date` tepat 21 Oktober 2026 (sesuai horizon).
     - Jelaskan bahwa 30 record ini merupakan 3 batch historis dari bar acuan yang sama (14 Okt 2026), sehingga secara etis dihitung sebagai evaluasi fallback dan tidak diklaim sebagai akurasi directional AI.
  3. **10 Record Target 27 Oktober 2026:** Tunjukkan bahwa 10 record ini tetap berstatus `pending` karena tanggal 27 Oktober 2026 belum jatuh tempo.

### Skenario 5: Diagnostik & Pemantauan 6 Pilar (System Health)
- **URL:** `http://localhost:8000/health` (atau tab System Health)
- **Poin Presentasi:**
  1. Tunjukkan status 6 pilar kesehatan: Database PostgreSQL, Python Adapter, Hermes Quant Engine, Ingesti EOD, Router AI, dan Kanal Telegram.
  2. Jelaskan deteksi jalur dinamis quant-engine yang terintegrasi di dalam `quant-engine/`.

---

## 4. Penjelasan Engine Kuantitatif, AI, dan Evaluasi

Saat dosen meminta penjelasan mendalam mengenai logika di balik sistem:

1. **Quant Engine (`quant-engine/`):**
   - Mengadopsi arsitektur riset kuantitatif teruji dari Hermes Stock Agent.
   - Menggunakan model eksperimen EXP-04 (Selected Walk-Forward Strategy) yang telah diaudit bebas dari look-ahead bias dan same-bar event inversion.
   - Memiliki 64 unit test mandiri berbasis pytest yang memvalidasi pembukuan kas, lot sizing BEI (100 lembar per lot), dan batasan drawdown MTM.
2. **AI & Safe Fallback Layer:**
   - Model yang dikonfigurasi: Gemini 3.8 Flash High (melalui 9router port 20128).
   - Dynamic token budget dan penanganan truncation reasoning token (`finish_reason = length`).
   - Guard isolasi operasional: `AI_LIVE_INFERENCE_ENABLED=false` menjamin nol biaya API dan nol risiko halusinasi selama demo.
3. **Post-Horizon Evaluator:**
   - Menghitung return aktual: `(Actual Close - Ref Price) / Ref Price * 100%`.
   - Mengklasifikasikan hasil secara transparan: `correct`, `incorrect`, atau `neutral`.
   - Melindungi metrik akurasi: Denominator 0 menghasilkan `N/A`, mencegah klaim akurasi semu.

---

## 5. Fitur yang Terverifikasi vs Keterbatasan

| Area | Fitur Terverifikasi (PASS) | Keterbatasan Saat Ini |
| :--- | :--- | :--- |
| **Konsolidasi Root** | Seluruh dashboard, engine, script, log, dan adapter berjalan dari `E:\Aether-Market-Intelligence`. | Repositori lama `E:\Project-bot` dan `E:\hermes-stock-agent` masih dipertahankan sebagai cadangan sementara. |
| **Data Ingestion** | Batch download multi-ticker Yahoo Finance, validasi OHLCV, 130 bar tersimpan. | Ketergantungan pada koneksi internet dan API publik Yahoo Finance untuk pembaruan harian. |
| **Evaluasi Horizon** | Evaluasi 5 hari bursa strictly anti-lookahead; 30 record target 21 Okt selesai dievaluasi, 10 record target 27 Okt pending. | 30 record historis yang dievaluasi berstatus fallback `unclear` sehingga metrik akurasi directional AI murni masih berstatus `N/A`. |
| **Keamanan & Guard** | Live AI disabled, zero broker orders, zero live trading, token Telegram aman dari log. | Pengujian inferensi live AI memerlukan aktivasi manual `AI_LIVE_INFERENCE_ENABLED=true` dengan API key valid. |
| **Automasi Windows** | Task Scheduler `\HermesMarketIntelligence_EOD` aktif menunjuk runner baru pada 16:05 WIB. | Pengubahan parameter COM Task Scheduler secara interaktif memerlukan hak administratif Windows (UAC). |

---

## 6. Prosedur Darurat & Reset Demo

Jika terjadi gangguan selama presentasi:
- **Web server berhenti:** Jalankan ulang `scripts/start_dashboard.bat`.
- **Cek cepat kondisi sistem:** Jalankan `scripts/check_system.bat`.
- **Database rollback:** Jika ada perubahan tidak diinginkan pada database selama demo, pulihkan dari backup:
  ```bash
  psql.exe -h 127.0.0.1 -p 5432 -U postgres -d project-bot -f "E:\backups_pre_consolidation_20261021\project_bot_backup_20261024_211314.sql"
  ```
