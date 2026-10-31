# LAPORAN AUDIT ARSITEKTUR & ANALISIS GAP (TAHAP 1)
## AETHER MARKET INTELLIGENCE

**Tanggal Rilis:** 25 Oktober 2026  
**Ruang Lingkup:** Audit Komprehensif Arsitektur, Pipeline Prediksi, dan Integrasi Telegram  
**Klasifikasi Bukti:** VERIFIED / REPORTED / NOT VERIFIED / MISSING / BLOCKED  

---

### 1. Matriks Audit Komprehensif Subsistem

| No | Komponen Subsistem | Klasifikasi Status | Bukti Faktual & Verifikasi Teknis | Keterangan / Tindakan Mitigasi |
|---|---|:---:|---|---|
| 1 | **Kolektor EOD & Raw Ingestion** | **VERIFIED** | `services/forward_data_collector.py` mengumpulkan EOD 10 emiten + IHSG dalam 2,11s; arsip raw di `data/raw_immutable/{YYYYMMDD}/`. | Beroperasi stabil Rp0 via Yahoo Finance v8 / yfinance. |
| 2 | **Data Cleaned & Karantina** | **VERIFIED** | `data/cleaned/` memuat 11 file CSV bersih (13.936 bar). Deviasi >0.5% masuk `data/quarantine/`. | Nol synthetic price interpolation. |
| 3 | **Feature Engineering & Scaler** | **VERIFIED** | 15 fitur teknikal + momentum. Scaler `quant-engine/models/artifacts/scaler.pkl` fit murni pada Train set. | Lookahead-free (rolling backward). |
| 4 | **Model Artifacts & Cutoff** | **VERIFIED** | Random Forest `model_rf.pkl` dan manifest JSON di `quant-engine/models/artifacts/`. Cutoff Train: 2023-12-20, Holdout Test: 2025-01-09 s.d. 2026-10-16. | Embargo 5 hari bursa membersihkan irisan antar-partisi. |
| 5 | **Skema Probabilitas & Urutan Kelas** | **VERIFIED** | Scikit-learn binary classification (`classes_ = [0, 1]`). $P(\text{DOWN}) = P(0)$, $P(\text{UP}) = P(1)$. Jumlah probabilitas $= 1.0000 \pm 10^{-6}$. | Predicted class: $\text{argmax}(P)$. Max probability: $\max(P) \in [0.5, 1.0]$. |
| 6 | **Skor Keyakinan vs Threshold $\tau = 0.70$** | **VERIFIED** | $\text{Confidence} = 2 \times \max(P) - 1 = |P(\text{UP}) - 0.50| \times 2$. Nilai $\tau = 0.70$ ekuivalen eksak dengan Confidence $\ge 0.40$. | Jika Conf $< 0.40 \implies$ status wajib `NO_SIGNAL_ABSTAIN`. |
| 7 | **Evaluator Horizon & Tanggal Finalitas** | **VERIFIED** | `BeiCalendarService::addTradingDays($date, 5)` menghitung 5 hari bursa secara tepat, melewati akhir pekan dan 16 hari libur BEI 2026. | Waktu finalitas candle penutupan bursa ditetapkan $\ge 16:00$ WIB. |
| 8 | **Database Utama (PostgreSQL)** | **VERIFIED** | Database `project-bot` pada PostgreSQL 16.1 (port 5432). 6 tabel baru ditambahkan pada migrasi `2026_10_25_100000`. | Tabel: `evidence_snapshots`, `prediction_signals`, `market_observations`, `monitoring_events`, `telegram_outbox`, `telegram_conversations`. |
| 9 | **Migrasi Shadow Ledger JSON** | **VERIFIED** | `hermes:migrate-shadow-ledger` berhasil memigrasikan 10 rekod shadow ke PostgreSQL secara transaksional & idempotent. | Berkas JSON asli dipertahankan sebagai arsip; rekod kedua di-skip 100%. |
| 10 | **Jadwal EOD Task Scheduler** | **VERIFIED** | `schtasks /query /tn "HermesMarketIntelligence_EOD"` Ready pukul 16:05 WIB. Runner dinamis ke `scripts/run_eod_pipeline.bat`. | Pengaturan baterai dan bangun dari sleep aktif. |
| 11 | **Transport Telegram & Gateway** | **VERIFIED** | Hermes Gateway aktif (PID: 10420) sebagai penerima inbound updates tunggal. Outbound memakai Hermes CLI `hermes send`. | Dilarang menjalankan process getUpdates ganda. |
| 12 | **Penghapusan Tombol Auto-Trading** | **VERIFIED** | Seluruh tombol interaktif (`View Signal`, `One Click Follow`, `Set Auto Trading`) telah dihapus total dari kode pemformat Telegram. | Tidak ada endpoint broker, tidak ada sizing spekulatif. |
| 13 | **Kontrol Akses Pengguna (Allowlist)** | **VERIFIED** | `TelegramTwoWayService` memeriksa User ID `5923895922`. User tidak sah ditolak dengan status `REJECTED`. | Input pengguna diperlakukan murni sebagai data pasif. |
| 14 | **Pemantauan Jam Bursa Intraday** | **REPORTED** | `MarketMonitoringService` dan command `hermes:monitor-market` diimplementasikan dengan klasifikasi 5 sesi BEI. | Sumber data gratis terbatas pada EOD (Yahoo Finance delay 15m). Klaim tick streaming realtime dinyatakan belum tersedia. |
| 15 | **Isolasi Evaluasi Fallback Historis** | **VERIFIED** | 30 rekod evaluasi fallback lama di `post_horizon_evaluations` dipisahkan secara fisik dan analitis dari kohort forward baru. | Denominator nol menghasilkan `N/A`, bukan 0% atau error division-by-zero. |

---

### 2. Temuan Gap & Koreksi Kesimpulan Tahap H

1. **Koreksi Hipotesis Rezim Pasar:**
   - *Laporan Sebelumnya:* Penurunan ROC-AUC dari 0.5131 ke 0.4851 disimpulkan sebagai "bukti pasti overfitting dan multikolinearitas".
   - *Koreksi Audit:* Penurunan tersebut merupakan degradasi empiris out-of-sample pada holdout test. Multikolinearitas atau overfitting adalah hipotesis teoretis yang memerlukan uji diagnostik lanjutan (seperti VIF dan analisis pruning). Kesimpulan dikoreksi menjadi: *fitur rezim pasar IHSG terbukti menurunkan kinerja generalisasi out-of-sample sehingga dilarang masuk ke model produksi*.
2. **Koreksi Integritas Hash vs Bebas Leakage:**
   - *Laporan Sebelumnya:* Hash SHA-256 berkas disebut sebagai "bukti data bebas leakage".
   - *Koreksi Audit:* Hash hanya membuktikan integritas bit (bahwa berkas tidak berubah sejak disimpan). Bebas leakage dibuktikan oleh **disiplin stempel waktu (temporal audit)**: memastikan bahwa tidak ada observasi di atas tanggal penutupan hari $t$ yang dikonsumsi oleh model pada hari $t$.
3. **Koreksi Jumlah Tes Lulus vs Akurasi Model:**
   - *Penegasan:* Kelulusan 164 tes otomatis membuktikan kebenaran fungsional perangkat lunak, BUKAN bukti keberhasilan prediksi pasar keuangan. Model tetap berstatus `NO_SIGNAL / ABSTAIN` karena keyakinan berada di bawah ambang batas selektif (Confidence ~0.30 < 0.40).
