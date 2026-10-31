# LAPORAN FORENSIK HASIL SMOKE TEST TAHAP I & AUDIT OPERASIONAL
## AETHER MARKET INTELLIGENCE

**Tanggal Rilis:** 25 Oktober 2026, 10:45 WIB  
**Ruang Lingkup:** Verifikasi End-to-End Transmisi Outbound & Inbound Nyata, Penelusuran Titik Kegagalan Dua Arah, dan Audit Bukti Operasional  
**Status Pengujian Keseluruhan:** OUTBOUND PASS / INBOUND ROUTING GAP (PARTIAL) / LIFECYCLE PASS  

---

### 1. Bukti Pengiriman Pesan Nyata (Real Outbound Telegram)

Pengiriman pesan smoke test dilakukan melalui jalur aplikasi resmi (**PostgreSQL `telegram_outbox` -> `TelegramFormattingService` Dispatcher -> Hermes CLI Transport**):

| Parameter Uji | Nilai Tercatat Faktual di DB / Log | Status Verifikasi |
|---|---|:---:|
| **Outbox Record ID** | `1` | **VERIFIED** |
| **Dedup Key** | `SMOKE_TEST_OUTBOUND_20261025_031843_5f72c06f` | **VERIFIED** |
| **Target Chat ID** | `5923895922` (Allowlisted User ID) | **VERIFIED** |
| **Identitas Bot Pengirim** | `@Info_BosKuu_bot` (Hermes Gateway Managed) | **VERIFIED** |
| **Waktu Pengiriman** | `25 Oct 2026, 10:19:03 WIB` (UTC: `03:19:03`) | **VERIFIED** |
| **Status Delivery DB** | **`SENT`** | **VERIFIED** |
| **Telegram Message ID** | **`278`** | **VERIFIED** |
| **Error Log** | `NONE` (Zero Exception) | **VERIFIED** |

**Payload Teks Terkirim (Dibuat Dinamis dari Database):**
```text
🎯 AETHER — UJI KONEKSI TAHAP I

Waktu: 25 Oct 2026, 10:18 WIB
Mode: Research-only
Data: EOD, bukan realtime tick
Status prediksi: 10 Sinyal Forward Terdaftar (10 Abstain / NO_SIGNAL, 0 Directional)

Pesan keluar sedang diuji.
Percakapan dua arah belum dinyatakan lulus.

Silakan balas pesan ini:
“Status sistem sekarang bagaimana?”

Ini pesan pengujian, bukan sinyal saham.
```

---

### 2. Audit Forensik Balasan Nyata Pengguna (Inbound Verification)

Pengguna telah membalas pesan smoke test secara nyata melalui aplikasi Telegram. Berikut hasil audit forensik dari log gateway, database pesan, dan pipeline aplikasi:

#### A. Data Pembaruan Masuk (Inbound Update)
- **Telegram Update ID:** `496962948` (Tercatat pada `C:\Users\ASUS\AppData\Local\hermes\telegram_update_receipts_8583524735.json`).
- **Telegram Message ID Pengguna:** **`279`** (Tercatat pada `state.db` record message id `20649`).
- **Waktu Masuk:** `25 Oct 2026, 10:32:48 WIB` (`1792899167.0` timestamp).
- **Pengirim (From):** `Orlando Dimas Saputra_FT_PTIK_SNBP` (User ID / Chat ID: `5923895922`).
- **Pemeriksaan Allowlist:** **PASS** (User ID `5923895922` terverifikasi diizinkan).
- **Teks Balasan Pengguna:** `'status system sekarang gimana’'`
- **Konteks Balasan (*Reply Context*):** `reply_to_id=None` (Pesan dikirim sebagai direct message baru, bukan swipe-reply langsung ke pesan 278).

#### B. Penelusuran Titik Kegagalan Routing (*Failure Point Breakdown*)

| Tahapan Alur Inbound | Status Faktual | Bukti Teknis & Diagnosa Forensik |
|---|:---:|---|
| **1. Penerimaan Gateway** | **PASS** | Hermes Gateway (PID: 10420) sukses menerima Update ID `496962948` pada `10:32:48 WIB`. |
| **2. Routing ke Laravel Service** | **FAIL (GAP)** | Hermes Gateway tidak memiliki hook otomatis untuk mem-pipe pesan ke `php artisan hermes:telegram-chat` atau service Laravel `TelegramTwoWayService`. Gateway langsung melahap pesan ke internal LLM agent loop miliknya (`agent:main:telegram:dm:5923895922`). |
| **3. Perekaman di PostgreSQL** | **FAIL** | Karena pesan tidak dirutekan ke Laravel saat masuk, tabel `telegram_conversations` awalnya kosong untuk update ini (baru tercatat saat disimulasikan via CLI). |
| **4. Akses Data & Pembentukan Jawaban** | **DEVIASI (AI)** | Alih-alih dieksekusi secara deterministik tanpa AI (`AI_LIVE_INFERENCE_ENABLED=false`) langsung dari PostgreSQL, Hermes Agent di Gateway menjalankan 16 API calls LLM (`ag/gemini-3.8-flash-high`) dan 15 perintah terminal shell (`ls`, `cat eod log`, `git log`) selama 303,9 detik. |
| **5. Pengiriman Balasan ke Telegram** | **PASS** | Hermes Gateway mengirimkan 1 respon balasan teks (822 karakter) pada `10:37:54 WIB` dengan Delivery Obligation ID `8f021f50ac010f85b0fdc07d` (Status: `delivered`). |
| **6. Pencegahan Respons Ganda** | **PASS** | Tepat 1 respon yang dikirimkan. Tidak ada duplikasi pesan keluar (*exactly-once delivered*). |

#### C. Isi Jawaban yang Diterima Pengguna di Telegram:
```text
Status sistem Aether Market Intelligence saat ini:

* **Koneksi Telegram**: Uji dua arah lulus. Pesan kamu diterima.
* **Status Pasar**: Tutup (hari Minggu). Sinkronisasi EOD dilewati.
* **Data Feed**: Mode EOD (bukan tick realtime). Terakhir sinkron final 23/24 Okt 2026.
* **Pipeline Prediksi**:
  * 40 prediksi terdaftar (semua pending, tunggu jatuh tempo evaluasi 5 hari bursa).
  * Batch terakhir: 10 sinyal status NO_SIGNAL / Abstain (0 directional).
* **AI Engine**: Mode fallback teknikal aktif. Upstream AI router sempat timeout/error.
* **Database & Task Scheduler**: Task aktif, scheduler siap jalan otomatis hari bursa berikutnya (Senin, 16:05 WIB).
* **Eksekusi Broker**: Zero trade (mode riset murni, tanpa order pasar).

Langkah lanjut: Tunggu pembukaan bursa Senin. Pipeline jalan otomatis pasca-penutupan.
```

---

### 3. Bukti Operasional & Pemisahan Status Komponen

Sesuai instruksi audit, status komponen dipisahkan secara tegas antara **Configured**, **Running**, dan **Successful Execution**:

| Komponen Subsistem | Configured | Running (Proses Aktif) | Successful Execution | Deskripsi Operasional & Bukti Nyata |
|---|:---:|:---:|:---:|---|
| **PostgreSQL Database** | **YA** | **YA** | **YA** | Service Windows `postgresql-x64-16` melayani port 5432. Database `project-bot` aktif melayani query aplikasi. |
| **Hermes Gateway Service** | **YA** | **YA** | **YA** | Shortcut `Hermes_Gateway.vbs` di folder Startup Windows. Proses berjalan stabil pada **PID: 10420**. |
| **EOD Ingestion Pipeline** | **YA** | **TERJADWAL** | **YA** | Windows Task Scheduler `\HermesMarketIntelligence_EOD` menunjuk `scripts\run_eod_pipeline.bat`. Eksekusi sukses terakhir: Jumat 23 Okt / Sabtu 24 Okt. |
| **Market Monitoring Worker** | **YA** | **TIDAK** | **MANUAL** | Service `MarketMonitoringService` dan command `hermes:monitor-market` teruji sukses secara manual/test, namun **belum berjalan sebagai daemon loop mandiri per menit**. |
| **Telegram Outbox Dispatcher** | **YA** | **TIDAK** | **ON-DEMAND** | Service `TelegramFormattingService` dan command `hermes:process-outbox` teruji sukses mengirim pesan Outbox ID 1, namun **belum berjalan sebagai background worker looping terus-menerus**. |
| **Inbound Webhook / Bridge** | **TIDAK** | **TIDAK** | **TIDAK** | Belum ada script bridging dari Hermes Gateway inbound events ke artisan `hermes:telegram-chat`. Gateway saat ini langsung mengonsumsi pesan ke LLM agennya sendiri. |

---

### 4. Koreksi Finalitas Data EOD & Karakteristik Latensi

1. **Koreksi Waktu Finalitas EOD:**
   - Ditegaskan bahwa **data EOD tidak dijamin pasti final tepat pukul 16:05 WIB**.
   - Sesi pasca-penutupan BEI berlangsung hingga pukul 16:15 WIB.
   - Sumber publik gratis (Yahoo Finance v8) memerlukan waktu propagasi dan agregasi broker yang biasanya baru stabil antara **16:15 hingga 17:30 WIB** (atau lebih lambat jika terdapat revisi transaksi negosiasi/kliring).
   - Jadwal 16:05 WIB pada Task Scheduler adalah jadwal peninjauan awal (*polling probe*), bukan jaminan finalitas data seketika.
2. **Karakteristik Latensi Yahoo Finance (`.JK`):**
   - Selama jam perdagangan bursa (09:00 – 15:49 WIB), endpoint chart Yahoo Finance v8 memiliki indikator `exchangeDataDelayedBy: 15` (kutipan tertunda 15–20 menit).
   - Pada hari libur dan akhir pekan (seperti hari ini, Minggu 25 Okt 2026), tidak adanya sinkronisasi adalah perilaku normal dan sah sesuai kalender bursa (`BeiCalendarService::isTradingDay() == false`).

---

### 5. Due Date Aktual Setiap Kohort Prediksi di Database

Database PostgreSQL saat ini memuat dua kohort data yang dipisahkan secara ketat:

#### A. Kohort Prediksi Forward Baru (`prediction_signals`)
- **Total Instrumen:** 10 saham (ARTO, ASII, BBCA, BBNI, BBRI, BMRI, MAPI, MEDC, SMRA, TLKM).
- **Tanggal Keputusan (*Decision Date*):** `2026-10-16` (Jumat).
- **Harga Acuan (*Reference Price*):** Harga penutupan EOD final 16 Okt 2026.
- **Horizon Evaluasi Utama:** Tepat 5 hari bursa resmi BEI (Senin 19 Okt, Selasa 20 Okt, Rabu 21 Okt, Kamis 22 Okt, Jumat 23 Okt).
- **Due Date Aktual Evaluasi 5-Hari:** **`2026-10-23`**.
- **Status Evaluasi:** **`EVALUATED_NEUTRAL`** (seluruh 10 emiten berstatus `ABSTAIN` karena skor keyakinan model $< 0.40$).
- **Batas Pemantauan Maksimal (*Expires At*):** **`2026-10-30 16:05:00 WIB`** (14 hari kalender).
- **Status Pemantauan:** `NOT_MONITORED` (karena status sinyal adalah `NO_SIGNAL_ABSTAIN`).

#### B. Kohort Evaluasi Fallback Historis Lama (`post_horizon_evaluations`)
- **Total Rekod:** 40 rekod (artefak pengujian konsolidasi masa lampau).
- **Due Date Target 21 Okt 2026:** 30 rekod bertarget 21 Okt 2026 berstatus `evaluated` (kategori *neutral / non-directional*).
- **Due Date Target 27 Okt 2026:** 10 rekod bertarget 27 Okt 2026 berstatus `pending`.
- **Kebijakan:** 40 rekod ini diisolasi penuh dari kohort forward baru dan tidak dimasukkan ke dalam metrik akurasi directional forward.

---

### 6. Hasil Pengujian Siklus Hidup pada DB Terisolasi (Fixture/Replay)

Pengujian komprehensif dijalankan pada database memori terisolasi (`:memory:`) tanpa menyentuh database operasional PostgreSQL:

```text
Feature Test: Tests\Feature\TahapILifecycleAndTelegramTest
✓ test_db_commit_before_telegram_outbox_enqueue
✓ test_db_rollback_does_not_enqueue_outbox
✓ test_inbound_duplicate_update_idempotency
✓ test_publication_boundary_and_14_day_expiry
✓ test_horizon_5_trading_days_skips_weekends_and_holidays
✓ test_ambiguous_candle_both_target_and_invalidation_hit
✓ test_offline_gap_detected_and_recorded
✓ test_no_signal_abstain_never_forced_to_directional
✓ test_unauthorized_telegram_user_rejected
✓ test_recap_metrics_separates_historical_fallbacks_from_forward_cohort
✓ test_probability_sum_and_confidence_definition

Hasil: 11 tests PASSED, 35 assertions (Durasi: 1,27 detik)
```

**Total Pengujian Seluruh Subsistem:**
- Laravel PHPUnit: **84 passed** (284 assertions).
- Python Services Adapter: **9 passed**.
- Quant Engine Pytest: **82 passed**.
- Total: **175 automated tests PASS 100%**.

---

### 7. Matriks Status Ringkas Tahap I Smoke Test & Verifikasi

| No | Komponen / Pengujian | Status | Bukti Faktual & Catatan Forensik |
|---|---|:---:|---|
| 1 | **Outbound Telegram Nyata** | **PASS** | Outbox ID 1 terkirim (Msg ID 278). Tercatat transaksional di PostgreSQL. |
| 2 | **Penerimaan Inbound Nyata** | **PASS** | Update ID `496962948` & Msg ID `279` diterima oleh Hermes Gateway. |
| 3 | **Routing Inbound ke Laravel** | **FAIL** | Gateway tidak merutekan ke service Laravel; langsung dilahap LLM internal agent. |
| 4 | **Perekaman `telegram_conversations`** | **FAIL** | Tidak otomatis tersimpan di PostgreSQL saat update masuk melalui gateway. |
| 5 | **Pengiriman Respon Balasan** | **PASS** | Gateway mengirimkan 1 balasan teks (822 char) pada 10:37:54 WIB (Delivery ID: `8f021f50ac010f85b0fdc07d`). |
| 6 | **Pencegahan Respon Ganda** | **PASS** | Tepat 1 respons yang dikirim (tidak ada pesan duplikat). |
| 7 | **Lifecycle Fixture pada Test DB** | **PASS** | 11 feature tests di SQLite `:memory:` lulus 100% (175 tests suite total). |
| 8 | **Task Scheduler & Background Services** | **PASS** | Task Scheduler Ready (16:05 WIB), Gateway aktif (PID 10420). |
| 9 | **Daemon Worker Monitor & Outbox** | **PENDING** | Teruji sukses on-demand, namun belum terpasang loop daemon per menit. |
| 10 | **Evaluasi Forward Pasar Nyata** | **PENDING** | Menunggu sesi bursa forward berikutnya (Senin, 26 Okt 2026). |

---

### 8. Status Git Repository

- Direktori: `E:\Aether-Market-Intelligence`
- Perubahan Baru: Migrasi database Tahap I, Model Eloquent, Services (MarketMonitoring, TelegramFormatting, TelegramTwoWay, PredictionRecap), CLI commands, test suites, dan dokumentasi lengkap di `docs/`.
- Sesuai batasan: **Tidak ada perintah commit atau push otomatis yang dijalankan**.
