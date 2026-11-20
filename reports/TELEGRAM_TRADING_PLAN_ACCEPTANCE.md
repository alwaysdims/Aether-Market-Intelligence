# LAPORAN AKSEPTASI & PEMULIHAN FUNGSI TRADING PLAN TELEGRAM
**Aether Market Intelligence** | Tanggal: 04 November 2026

---

## 1. Pemisahan Jalur Telegram vs Dashboard
- **Jalur Telegram Intraday (Deterministik & Cepat)**:
  - Menggunakan layanan `App\Services\TelegramTradingPlanService`.
  - **Zero LLM Dependency**: Telegram alert intraday dirancang murni melalui perhitungan kode deterministik tanpa menunggu inferensi narasi panjang Gemini / 9router.
  - Latensi eksekusi pemindaian & rendering: **< 150 ms** untuk seluruh universe ekuitas.
  - Sinyal dan trading plan terkirim tepat waktu langsung ke `telegram_outbox` dengan UTC `due_at` yang presisi.
- **Jalur Dashboard Emiten (Analitik & Gemini AI)**:
  - Mempertahankan penuh 7 tab analitik emiten di `/market/{ticker}`, chart interaktif, metrik fundamental, broker summary, dan ringkasan AI via Gemini `ag/gemini-3.8-flash-high`.
  - Model dan threshold ML produksi tidak diubah.

---

## 2. Inventarisasi & Kemampuan Kontrak Data IDX Edge
| Komponen | Status Kemampuan | Keterangan & Batasan |
| :--- | :--- | :--- |
| **WebSocket Running Trade** | `CONNECTED` (PID 18392) | Mengalirkan trade live (`t`, `p`, `l`, `c`). **TIDAK memiliki field `board`**. Dilabeli **`BOARD_SCOPE_UNVERIFIED`** / **`MIXED_BOARD_UNSEPARABLE`**. |
| **Papan Transaksi (Board)** | `UNVERIFIED` | Provider belum menyediakan filter khusus RG pada payload WebSocket running-trade. Dilarang menebak filtering NG hanya dari harga/lot. |
| **Event Timestamp** | `UNAVAILABLE_PER_TICK` | Server mengirim `time: null` pada tiap tick event transaksi; sistem mencatat `received_at` lokal (tidak diklaim sebagai latensi sumber). |
| **Trade ID / Sequence** | `UNAVAILABLE_PER_TICK` | Server mengirim `trade_id: null` pada tick; deduplikasi menggunakan ring buffer signature. Klaim volume komprehensif intra-detik ditahan. |
| **REST Quote (`/api/price`)**| `QUARANTINED` | Tetap dikarantina karena mencampur data Pasar Negosiasi (NG) tanpa pemisah board. |
| **Canonical EOD (`/api/history`)**| `VERIFIED_RG_100%` | Sumber resmi tervalidasi Pasar Reguler (RG) untuk 962 emiten BEI. Dipakai sebagai baseline acuan pra-pasar. |

---

## 3. Implementasi 5 Slot Jadwal Bursa
| Slot | Judul & Sasaran | Sumber Data & Metodologi | Status Sinyal |
| :--- | :--- | :--- | :--- |
| **08:30 WIB** | **Trading Plan Pra-Pasar** | Full Universe EOD H-1 tervalidasi + Berita Resmi ANTARA. Entry bersyarat (bukan harga live). | `SETUP BERSYARAT` (Scalping & Swing) |
| **09:15 WIB** | **Screening Scalping & Swing** | Observasi berjalan sejak pembukaan. Memisahkan setup intraday vs swing multi-hari. Label `PARTIAL_WINDOW`. | `SETUP BERSYARAT` |
| **12:15 WIB** | **Ringkasan Sesi 1 & Rencana Sesi 2** | Evaluasi status kandidat pagi (masih berlaku / batal / tercapai). Rencana aksi Sesi 2. | `EVALUASI_AKTIF` |
| **15:15 WIB** | **Alert BSJP (Beli Sore Jual Pagi)** | Emiten closing strong mendekati High H-1 dengan akumulasi volume. Pengungkapan risiko overnight & gap down. | `SETUP BERSYARAT` |
| **20:00 WIB** | **Rekap EOD & Evaluasi Trading Plan** | Realisasi outcome seluruh rencana trading terhadap bar penutupan EOD resmi dan broker summary. | `LEDGER_EVALUATED` |

---

## 4. Aturan Perhitungan & Formula Strategi
- **Fraksi Tick BEI Resmi**:
  * Harga < Rp 200 : Fraksi 1 tick = Rp 1
  * Rp 200 - Rp 500 : Fraksi 1 tick = Rp 2
  * Rp 500 - Rp 2.000 : Fraksi 1 tick = Rp 5
  * Rp 2.000 - Rp 5.000 : Fraksi 1 tick = Rp 10
  * Harga >= Rp 5.000 : Fraksi 1 tick = Rp 25
- **Evaluasi Risiko Konservatif**:
  * Ketika entry berupa rentang (contoh: Rp 2.100 - Rp 2.170), risiko dihitung terhadap **harga entry tertinggi (terburuk)** (Rp 2.170), bukan angka minimum agar rasio tidak dipercantik secara artifisial.
- **Asumsi Biaya & Slippage**:
  * Biaya transaksi total: 0,40% (Beli 0,15%, Jual 0,25% + pajak).
  * Slippage: 1 tick saat masuk dan 1 tick saat keluar (total 2 tick).
  * Net Reward dihitung setelah memotong estimasi biaya dan slippage.
- **Disiplin Eksekusi**:
  * Harga menyentuh level pemicu **TIDAK MEMBUKTIKAN ORDER TERISI (FILL)**.
  * Tanpa janji profit minimum.

---

## 5. Audit Slot 15:15 yang Dilaporkan Hilang (04 November 2026)
- **Akar Masalah Empiris**:
  * Pada pukul 08:00:03 UTC (15:00:03 WIB), Laravel Scheduler menjalankan command `hermes:backfill-universe --raw --limit=100` secara sinkron (foreground).
  * Proses backfill berjalan selama **19 menit 33 detik** dan baru selesai pada 08:19:36 UTC (15:19:36 WIB).
  * Karena `hermes:backfill-universe` memblokir thread `schedule:run`, jadwal 15:15:00 WIB terlewatkan (*missed tick*) oleh scheduler.
- **Solusi & Pencegahan**:
  * Menambahkan `->runInBackground()` pada `hermes:backfill-universe` di `routes/console.php`. Seluruh proses batch panjang kini terisolasi di background process terpisah.
  * Menambahkan validasi freshness (`publishPlanToOutbox(..., freshnessMinutes: 45)`): jika alert tertunda > 45 menit, sistem menandai `EXPIRED_STALE_ALERT` dan **menolak mengirim alert basi** sebagai peluang aktif.

---

## 6. Contoh Output Telegram Aktual (Dry-Run Verifikasi)
```
📢 TRADING PLAN — PRA-PASAR (08:30 WIB)
──────────────────────────────────────────
• Tanggal & Waktu : 04 Nov 2026, 16:56:16 WIB
• Sesi Acuan Data : 2026-11-03
• Cakupan Pantau : Seluruh Universe Ekuitas Terdaftar BEI (Filter Volume & Volatilitas EOD)
• Status Papan RG : RG (Pasar Reguler Baseline EOD)
• Status Sinyal : SETUP BERSYARAT (CONDITIONAL)
──────────────────────────────────────────
🎯 [BREN] — SCALPING
• Alasan Masuk : Momentum penutupan H-1 menguat +1.4% dengan volume aktif 17.6M lembar dan rentang volatilitas 11.2%
• Pemicu Entry : Breakout & bertahan di atas Rp 2.170 dengan konfirmasi antrean bid tebal pada pembukaan
• Zona Entry : Rp 2.100 - Rp 2.170
• Target (TP) : Rp 2.230 (+2.76% bruto, est net +1.44%)
• Cut Loss (CL) : Rp 2.050 (-5.53%)
• Reward / Risk : 1 : 0.50 (Estimasi Fee & Slippage: Rp 29)
• Horizon : 1 hari bursa (Sesi 1 - 2)
• Masa Berlaku : 2026-11-04 15:50 WIB
• Kondisi Batal : Penurunan harga di bawah Rp 2.050 atau breakdown level support H-1
• Risiko Utama : Potensi false breakout saat pembukaan; likuiditas Pasar Reguler belum teruji di awal sesi
──────────────────────────────────────────
🎯 [BJTM] — SWING
• Alasan Masuk : Konsolidasi sehat H-1 dengan rentang 2.8% dan likuiditas kuat 14.5M lembar saham
• Pemicu Entry : Uji level support Rp 492 dengan pantulan volume stabil
• Zona Entry : Rp 492 (Batas toleransi 1 tick: Rp 494)
• Target (TP) : Rp 525 (+6.71% bruto, est net +5.49%)
• Cut Loss (CL) : Rp 476 (-3.25%)
• Reward / Risk : 1 : 2.06 (Estimasi Fee & Slippage: Rp 6)
• Horizon : 3 - 10 hari bursa
• Masa Berlaku : 09 Nov 2026
• Kondisi Batal : Penutupan harian breakdown di bawah Low H-1 (Rp 476)
• Risiko Utama : Risiko tekanan jual indeks umum; sentimen pasar makro regional
──────────────────────────────────────────
ℹ️ CATATAN & BATASAN METODOLOGI:
• Harga menyentuh level tidak membuktikan order terisi (fill).
• Tanpa janji profit minimum. Selalu gunakan batas risiko ketat.
──────────────────────────────────────────
Dipublikasikan secara deterministik oleh Aether Market Intelligence
```

---

## 7. Bukti Pengujian Terisolasi
- File Uji: `dashboard/tests/Feature/TelegramTradingPlanTest.php`
- Hasil: **12 tests, 80 assertions, 100% PASS** (0.86s).
- File Uji Terpadu: `MiddaySessionReportTest.php` + `TelegramTradingPlanTest.php`
- Hasil: **16 tests, 116 assertions, 100% PASS** (0.91s).

---

## 8. Integrasi ke Monitoring Dashboard & Siklus Hidup State Machine
- **Pencatatan Idempoten Sebelum Publikasi**:
  * Seluruh kandidat trading plan dicatat ke tabel `prediction_signals` dengan prefix `PLAN_{TICKER}_{Ymd}_{SLOT}_{STRATEGY}`.
  * Fitur numerik (entry, target, cut loss, estimasi biaya/slippage, horizon) dibekukan dalam `evidence_snapshots` (`data_hash` SHA-256).
  * Status awal diset ke **`WAITING_FOR_TRIGGER`** (Setup Bersyarat).
  * Setiap siklus hidup mencatat audit log ke `monitoring_events` (`PLAN_RECORDED`, `ALERT_QUEUED`, `ALERT_DELIVERED`, `TRIGGER_OBSERVED`, `TARGET_HIT`, `INVALIDATED`, `AMBIGUOUS_HIT`).
- **State Machine Transisi Siklus Hidup**:
  1. `WAITING_FOR_TRIGGER`: Menunggu harga High bar bursa menyentuh `reference_price` (pemicu entry).
  2. `TRIGGER_OBSERVED`: Pemicu teramati tersentuh (dengan catatan tegas: *sentuhan level bukan bukti order terisi*).
  3. `AMBIGUOUS`: Jika pemicu/target dan invalidation tersentuh bersamaan pada bar bursa yang sama tanpa urutan intraday mikro-tick yang terbukti.
  4. `TARGET_HIT`: Harga High >= `target_price` setelah pemicu aktif.
  5. `INVALIDATED`: Harga Low <= `invalidation_price` (cut loss) sebelum target tercapai.
  6. `EXPIRED`: Masa berlaku berakhir tanpa menyentuh pemicu atau target.
- **Tautan Tiga Area Dashboard**:
  1. **Tab 2 Pemantauan Emiten (`/market/{ticker}?tab=monitoring`)**:
     * Menampilkan baris sinyal khusus dengan pill `TELEGRAM [SCALPING / SWING / BSJP]`, waktu keputusan/publikasi, harga acuan vs harga penutupan terkini, target, batas invalidation, jatuh tempo, status pemantauan (`WAITING_FOR_TRIGGER`), ID evidence, serta receipt Telegram.
     * Bukti Tangkapan Layar Browser Live: `C:\Users\ASUS\AppData\Local\hermes\cache\screenshots\browser_screenshot_badfa1ff6adb4394b5d3ca9863de3fac.png`
  2. **Dashboard Overview (`/`)**:
     * Tabel kandidat menampilkan sinyal bersyarat `TG SCALPING` / `TG SWING` dengan badge `BERSYARAT`, membedakannya dari prediksi model ML.
  3. **Histori Analisis AI (`/history`)**:
     * Dilengkapi kartu **Audit Ledger: Rekapitulasi Trading Plan Telegram (Scalping, Swing, BSJP)** dengan metrik transparan:
       - Total Terdaftar vs Terkirim (`SENT`)
       - Target Hit Rate dengan Denominator Eksplisit (`matured_count`: Target / CL / Ambiguous / Expired)
       - Rincian per Strategi (Scalping, Swing, BSJP)
       - Disclaimer integritas data: return skenario simulasi bukan portofolio riil pengguna.

---

## 9. Rekonsiliasi Plan Terkirim & Penanganan Legacy
- Melalui fungsi `TelegramTradingPlanService::reconcileSentTradingPlans()`:
  * Outbox berstatus `SENT` ditelusuri.
  * Plan yang memiliki metadata kontrak lengkap ditautkan statusnya menjadi `SENT` beserta `telegram_message_id`.
  * Outbox lama tanpa kontrak entry/TP/CL eksplisit ditandai **`LEGACY_INCOMPLETE_CONTRACT`** dan tidak dikarang-karang levelnya secara retrospektif.
  * Sinyal dengan pengiriman gagal (`FAILED`) tidak dihitung sebagai rekomendasi yang diterima pengguna.
  * Tanpa siar ulang atau spam duplikasi pada pesan lama.

---

## 10. Penutupan Validasi Monitoring Trading Plan: Bukti Audit End-to-End & Penegakan 6 Aturan

### Aturan 1: Pemisahan delivery_status dari monitoring_status
- `delivery_status` disimpan secara terisolasi pada `metadata->delivery_status` (`DECISION_CREATED`, `QUEUED`, `SENT`, `FAILED`).
- `monitoring_status` strictly mengelola siklus state machine strategi (`WAITING_FOR_TRIGGER`, `TRIGGER_OBSERVED`, `TARGET_HIT`, `INVALIDATED`, `AMBIGUOUS`, `EXPIRED`, `EXPIRED_WITH_COVERAGE_GAP`, `EXPIRED_WITH_PARTIAL_COVERAGE`).
- Event delivery `SENT` **tidak pernah menimpa** `monitoring_status`. Terbukti pada unit test:
  `test_delivery_status_sent_does_not_overwrite_monitoring_status` PASS.

### Aturan 2: delivered_at vs Bar EOD Sama-Hari (Anti-Lookahead & Skenario Akses Penerima)
- Sinyal yang belum terkirim (`delivery_status != SENT` atau `delivered_at == null`) dilabeli `scenario_type = 'DECISION_TIME_INTERNAL'` dan `scenario_accessible_to_recipient = false`.
- Jika alert dikirim di tengah sesi bursa (`delivered_at > 09:00 WIB`) dan hanya tersedia bar agregat EOD harian:
  * Bar EOD mencakup transaksi sebelum delivery.
  * Sistem menandai `intraday_sequence_proven = 'UNVERIFIABLE_FROM_EOD_ALONE'`.
  * Sentuhan level pada bar yang sama tidak boleh diklaim sebagai target/trigger pasca-delivery, melainkan ditandai **`AMBIGUOUS`**. Terbukti pada unit test:
    `test_same_day_mid_session_delivery_with_eod_bar_is_ambiguous` PASS.

### Aturan 3: Trigger & Target Pada Bar Yang Sama Tanpa Bukti Tick
- Jika High >= Trigger dan High >= Target pada satu bar bursa yang sama:
  * Tanpa urutan mikro-tick, sistem **dilarang mengasumsikan** target terjadi setelah trigger.
  * Status wajib diset ke **`AMBIGUOUS`**. Terbukti pada unit test:
    `test_trigger_and_target_on_same_bar_without_ticks_is_ambiguous` PASS.
- Setelah trigger aktif, jika target dan invalidation tersentuh pada bar berikutnya secara bersamaan, status ditandai **`AMBIGUOUS`**.

### Aturan 4: Pembekuan Horizon Kontrak & Benchmark Evaluasi BSJP
- Horizon dibekukan dalam `metadata->horizon_type` dan snapshot evidence:
  * SCALPING: `'INTRADAY_SESSION'` (cutoff: 15:50 WIB hari bursa bersangkutan).
  * SWING: `'5_TRADING_DAYS'` (cutoff: 15:50 WIB T+5 hari bursa).
  * BSJP: `'OVERNIGHT_NEXT_OPEN'` (jendela entry: 15:15-15:50 WIB; jendela evaluasi exit: harga open sesi 1 hari bursa berikutnya).
- Aturan Evaluasi BSJP:
  * Menggunakan `open_price` bar bursa berikutnya sebagai benchmark evaluasi matematis.
  * Dilengkapi disclaimer eksplisit: *"Harga open berikutnya adalah benchmark evaluasi matematis, bukan bukti order fill pengguna."* Terbukti pada unit test:
    `test_horizon_freeze_and_bsjp_next_open_evaluation` PASS.

### Aturan 5: EXPIRED Bersih vs Coverage Gap & Denominator Final
- Status `EXPIRED` bersih hanya diberikan jika seluruh sesi bursa kalender terverifikasi lengkap (`observed_count >= expected_sessions` dan 0 celah sesi).
- Jika data pengamatan bolong/hilang:
  * 0 sesi terverifikasi -> `EXPIRED_WITH_COVERAGE_GAP`.
  * Sebagian sesi hilang -> `EXPIRED_WITH_PARTIAL_COVERAGE`.
- Denominator Target Hit Rate:
  * Denominator final **strictly** hanya menghitung sinyal matang lengkap:
    `matured_final_count = TARGET_HIT + INVALIDATED + AMBIGUOUS + EXPIRED_CLEAN`.
  * Outcome provisional dan coverage gap dipisahkan secara eksplisit pada kartu audit `/history`.

### Aturan 6: Rekonsiliasi 4 Plan Existing & Outbox #106
- Audit Outbox #106 (`MORNING_BRIEFING_20261104`, sent 08:30:39 WIB, `telegram_message_id = 601`):
  * Payload berisi daftar 10 konstituen EOD briefing legacy, **tanpa parameter numerik TP/CL**.
  * Diberi label audit: `contract_status = 'LEGACY_INCOMPLETE_CONTRACT'`, `reconciled_notes = 'Briefing legacy 10 konstituen EOD tanpa level TP/CL numerik.'`
- Audit 4 Plan Rekonstruksi (`PLAN_BREN_...`, `PLAN_CDIA_...`, `PLAN_BJTM_...`, `PLAN_DFAM_...`):
  * Kontrak dibentuk dari data EOD H-1 pasca-briefing.
  * Diberi label audit transparan:
    `contract_provenance = 'RETROSPECTIVE_RECONCILIATION'`,
    `delivery_status = 'NOT_DELIVERED_RETROSPECTIVE'`,
    `telegram_message_id = null`,
    `provenance_notes = 'Kontrak direkonstruksi dari data EOD H-1 pasca-briefing. Tidak terdapat dalam payload Outbox #106 yang disiarkan ke Telegram.'`
  * Tidak mengklaim receipt #601 secara palsu.

### Klasifikasi Status Contoh ADRO (Outbox #115 & Telegram Msg #555666)
- **Status Eksplisit**: **MURNI FIXTURE PENGUJIAN PHPUnit (`test_same_day_mid_session_delivery_with_eod_bar_is_ambiguous`) & ILUSTRASI DOKUMENTASI**.
- **Verifikasi Database Produksi**:
  * Record `PLAN_ADRO_20261104_0915_SCALPING` di PostgreSQL: **0 (TIDAK ADA)**.
  * Record Outbox `#115` atau message ID `#555666` di PostgreSQL: **0 (TIDAK ADA)**.
  * Fixture tersebut berjalan di dalam mock transaction test environment dan tidak pernah masuk ke database operasional, dashboard produksi, atau metrik forward.

### Rangkuman Ledger & Metrik Rekapitulasi Akhir (Database Produksi PostgreSQL)
1. **Total Plan Diputuskan**: **8 Plan**
   - Retrospektif Sesi 4 Nov: BREN, CDIA, BJTM, DFAM (4 Plan).
   - Live Forward Sesi 5 Nov (08:30 WIB): AADI, BREN, ACES, BJTM (4 Plan).
2. **Total Rekomendasi Forward Terkirim Lengkap**: **4 Plan**
   - Rilis Telegram Pra-Pasar 5 Nov 08:32:16 WIB (Outbox #116, Telegram Msg #613).
   - Seluruhnya berstatus `delivery_status = 'SENT'` dan `monitoring_status = 'WAITING_FOR_TRIGGER'`.
3. **Total Tidak Terkirim / Retrospektif**: **4 Plan**
   - Kohort retrospektif 4 Nov diisolasi dari metrik forward.

---

## 11. Audit Snapshot Data Keputusan 08:30 WIB (5 November 2026) & Reward/Risk Gap

Pada rilis publikasi live forward pertama (Slot Pra-Pasar 08:30 WIB, Outbox #116, Telegram Msg #613), dilakukan audit rekonsiliasi terhadap data masukan dan evaluasi reward/risk:

1. **Snapshot Data Keputusan**:
   - **Cutoff Timestamp**: `2026-11-05 08:31:47 WIB` (dievaluasi sebelum pasar buka pukul 09:00 WIB).
   - **Tanggal Bar Tersedia**:
     * 202 emiten menggunakan bar sesi `2026-11-04`.
     * 761 emiten masih menggunakan bar sesi `2026-11-03`.
     * 1 emiten menggunakan bar sesi `2026-11-02`.
     * 9 emiten delisted/suspensi menggunakan bar sesi `2025-07-18`.
   - **Koreksi Klaim Cakupan Universe**:
     * Judul pesan Telegram menyatakan seluruh semesta bursa diperiksa. Secara arsitektural, algoritma memeriksa bar EOD terakhir yang ada di database (973 emiten).
     * Namun karena 768 emiten belum ter-refresh ke sesi 4 November 2026 saat keputusan diambil, input keputusan merupakan **kombinasi sesi EOD 4 Nov (202 emiten) dan EOD 3 Nov (761 emiten)**.
     * Status kelengkapan universe saat keputusan ditandai: **`PARTIAL_EOD_COVERAGE_AT_DECISION`**.

2. **Audit Reward/Risk & Gap Kelayakan Ekonomi**:
   - **AADI (Scalping)**: Rentang Entry Rp 10.625 - Rp 10.725, TP Rp 11.000, CL Rp 10.400.
     * Best-entry (Rp 10.625): Gross Reward +3.53%, Risk -2.12% -> RR = **1.67x**. Net RR (pasca fee 0.40% + 2 tick slippage): **1.22x**.
     * Worst-entry (Rp 10.725): Gross Reward +2.56%, Risk -3.03% -> RR = **0.85x**. Net RR: **0.58x**.
   - **BREN (Scalping)**: Rentang Entry Rp 2.100 - Rp 2.170, TP Rp 2.230, CL Rp 2.050.
     * Best-entry (Rp 2.100): Gross Reward +6.19%, Risk -2.38% -> RR = **2.60x**. Net RR: **2.08x**.
     * Worst-entry (Rp 2.170): Gross Reward +2.76%, Risk -5.53% -> RR = **0.50x**. Net RR: **0.34x**.
   - **ACES (Swing EOD)**: Entry Rp 332, TP Rp 354 (+6.63%), CL Rp 320 (-3.61%) -> Gross RR = **1.83x**, Net RR = **1.45x**.
   - **BJTM (Swing EOD)**: Entry Rp 492, TP Rp 525 (+6.71%), CL Rp 476 (-3.25%) -> Gross RR = **2.06x**, Net RR = **1.64x**.
   - **Temuan Gap Strategi**:
     * Strategi scalping deterministik saat ini menerima kandidat selama rasio RR pada harga *best-entry* $\ge 1.5\text{x}$, namun **tidak membatasi rasio RR pada batas atas rentang (worst-entry)**.
     * Hal ini merupakan **`STRATEGY_ECONOMIC_GAP`** (kelemahan parameter strategi deterministik existing).
     * Sesuai tata kelola, kontrak 4 plan forward terkirim tetap dibekukan sebagai fakta historis tanpa perubahan surut atau penambahan threshold baru yang dipaksakan.

## 12. Audit Perbandingan Slot 08:30 WIB vs 09:15 WIB (5 November 2026) & Penegakan Pemisahan Produk

Pada penelusuran publikasi slot 08:30 WIB (Outbox #116, Tg Msg #613) dan 09:15 WIB (Outbox #117, Tg Msg #614):

1. **Hasil Perbandingan Faktual**:
   - **Slot 08:30 WIB (Pra-Pasar)**:
     * Produk: Watchlist rencana bersyarat berbasis EOD H-1.
     * Status: `SETUP_FOUND` (AADI, BREN, ACES, BJTM).
     * Evidence Snapshot: SNAP #1514 s.d. #1517.
   - **Slot 09:15 WIB (Screening Pembukaan Sesi 1)**:
     * Temuan: Implementasi awal `generateOpeningScreeningPlan()` memanggil langsung `generatePreMarketPlan()`, sehingga menduplikasi kandidat 08:30 WIB hanya dengan mengganti header sesi acuan.
     * Fakta Feed Intraday: WebSocket upstream berstatus `BOARD_SCOPE_UNVERIFIED` (tanpa kepastian RG dan tanpa event timestamp bursa), sehingga observasi pembukaan 09:00 - 09:15 WIB tidak dapat diagregasikan menjadi candle mikro RG yang valid.
     * Tindakan Koreksi (v1.2.0-deterministic):
       - `generateOpeningScreeningPlan()` direfaktor total.
       - Jika feed intraday belum memenuhi kontrak kepastian RG (`board_rg_guaranteed = false`), status hasil screening intraday **WAJIB** berstatus **`DATA_CAPABILITY_BLOCKED`**.
       - Watchlist pra-pasar 08:30 WIB ditampilkan terpisah sebagai referensi belum terkonfirmasi, **bukan sebagai `SETUP_FOUND` intraday baru**.

2. **Penyempurnaan Kontrak Entry & Horizon**:
   - Kontrak Scalping:
     * Memisahkan Breakout Entry dan Pullback Entry secara eksplisit pada teks trading plan.
     * Evaluasi risiko worst-entry diperketat (rasio reward/risk neto batas atas wajib $\ge 0.80\text{x}$).
   - Kontrak Swing:
     * Menyamakan teks horizon pesan ("Swing Horizon T+5 / 5 hari bursa kalender") secara konsisten dengan kolom database `horizon_days = 5` dan evaluasi otomatis pada T+5.
     * Tanggal kedaluwarsa dihitung menggunakan kalender bursa BEI (`addTradingDays($nowWib, 5)`), bukan hari kalender biasa (+5 hari sipil).

## 14. Rekonsiliasi EOD Sesi 4 November 2026 & Eliminasi Bottleneck Latensi

### 14.1 Snapshot Coverage Aktual Sesi 4 November 2026 (WIB 10:00:00)
- **Denominator Registri Saham BEI**: **978 Emiten**
- **Klasifikasi Status Registri**:
  * `COMPLETE` (Target didukung multi-tahun): **964 emiten**.
  * `INCOMPLETE_DATA_GAP` (Emiten IPO/gap): **6 emiten** (`WBSA, EMMI, JECX, JELI, PRDL, RANS`).
  * `FAILED` (Anomali skema upstream): **5 emiten** (`FREN, MASA, MFIN, PRAS, CNTX`).
  * `EXCLUDED_INDEX` (Benchmark IHSG): **1 entitas** (`^JKSE`).
  * `UNSUPPORTED_SHARE_CLASS` (Multiple share class): **1 emiten** (`GOTOM`).
  * `SHORT_LISTING_HISTORY`: **1 emiten** (`BACH`).
- **Kondisi Bar Sesi 4 November 2026 di Database (`market_observations`)**:
  * **Lengkap Dua Mode (Adjusted + Raw)**: **201 emiten** (195 status `COMPLETE` + 6 status `INCOMPLETE_DATA_GAP`).
  * **Mode Adjusted Saja**: **2 emiten** (`TRST, TRUK`).
  * **Mode Raw Saja**: **1 emiten** (`SCCO`).
  * **Total Emiten Memiliki Bar 4 Nov**: **204 emiten unik**.
- **Rekonsiliasi Backlog Sisa Target `COMPLETE` (766 Emiten)**:
  * Emiten Aktif Sesi Terakhir 3 Nov: **757 emiten** (Menunggu antrean refresh).
  * Emiten Delisted/Suspensi 18 Juli 2025: **9 emiten** (`KPAS, JKSW, FORZ, HDTX, KPAL, KRAH, MAMI, NIPS, MYRX` — tidak ada bar baru dari BEI).
  * Total Target `COMPLETE` Sisa: $757 + 9 = 766$ emiten.
  * Rekonsiliasi total: $198 \text{ (tercover)} + 766 \text{ (sisa)} = 964$ emiten `COMPLETE`.

### 14.2 Penegakan Freshness pada Screening Berikutnya
- Logika penarikan kandidat di `TelegramTradingPlanService::generatePreMarketPlan()` diperketat:
  * Variabel `freshObservedCount` dan `inspectedCount` ditampilkan pada deskripsi header.
  * Emiten dengan tanggal observasi $\ne H-1$ (misal $H-2$ atau $H-3$) **DITOLAK SECARA OTOMATIS** dari penentuan kandidat.
  * Mencegah sinyal trading plan terbit berbasis data harga stale.

### 14.4 Bukti Eksekusi Nyata Pemulihan Sesi 4 November 2026 (WIB 11:13:00)
- **Eksekusi Runner Background**:
  * Pemicu: `scripts/run_eod_session_recovery.bat` dijalankan sebagai proses background terisolasi.
  * PID / Owner Runner: **PID 21072** (PHP CLI process).
  * Lease Owner Token: `lease_c59688b3488caa7bdc4e8faf123abab3_pid21072`.
  * Status Antrean di `eod_session_queues`: **`PROCESSING`**.
  * Heartbeat Terverifikasi: `2026-11-05 04:13:11 UTC` (`11:13:11 WIB`).
  * Checkpoint Kemajuan Ticker: Bergerak aktif dari `AGAR` -> `AIMS` -> `AKSI` -> `AMIN` (25 emiten diproses).
  * Lokasi Log: `logs/session_recovery_20261104.log`.
  * Konsumsi Kuota: 187 request terpakai hari ini, sisa kuota entitlement provider: **30.813 request**.
- **Progres Pertambahan Coverage**:
  * Sebelum eksekusi: 203 bar adjusted, 202 bar raw (total emiten lengkap: 201).
  * Sesudah eksekusi berjalan (checkpoint 25): **228 bar adjusted, 227 bar raw** (+25 bar berhasil di-ingest).
- **Penjagaan Antrean Sesi 5 November 2026**:
  * Sesi 5 November telah dicatat secara persisten di `eod_session_queues` dengan status `PENDING` (ID #6).
  * Prioritas antrean: Sesi 4 Nov diselesaikan penuh terlebih dahulu; sesi 5 Nov dijaga tertahan (`held in queue`) hingga penutupan perdagangan resmi $\ge 16:05\text{ WIB}$ untuk mencegah pengambilan data parsial sebelum pasar tutup.
  * Runner sesi 4 Nov beroperasi dengan proteksi *single lock fence* (`hermes:lock:refresh-eod`), menjamin zero duplicate runner.
- **Kronologi Bertimestamp Nyata Database**:
  * `decision_at`: **09:15:02 WIB** (`started_at` pada `AnalysisRun::RUN_PLAN_20261105_0915_iabm`).
  * `queued_at`: **09:15:05 WIB** (`created_at = 2026-11-05 02:15:05 UTC` pada rekod `TelegramOutbox #117`). Jeda komputasi internal: **3 detik** (evaluasi plan 6.5 ms, render 0.04 ms, insert DB).
  * `claimed_at`: **09:15:20 WIB** (worker `hermes:process-outbox --live` mengklaim lock dan mengubah status ke `PROCESSING`). Jeda tunggu giliran cron scheduler: **15 detik**.
  * `sent_at`: **09:15:24 WIB** (`sent_at = 2026-11-05 02:15:24 UTC` pasca eksekusi Hermes CLI ke gateway Telegram). Jeda eksekusi pengiriman jaringan: **4 detik**.
  * `receipt`: **Telegram Message ID #614** (terverifikasi nyata di grup).
- **Koreksi Diagnosis Latensi**:
  * Klaim awal bahwa penulisan snapshot DB memakan 18 detik **TIDAK TERBUKTI**. Database insert hanya memakan waktu 3 detik.
  * Komponen terbesar dari jeda 22 detik adalah **15 detik waktu tunggu antrean di outbox** sebelum worker scheduler 1 menit (`AetherScheduledDispatcher_1min`) mengeksekusi claim `processOutboxQueue()`.
- **Solusi Bersama Terverifikasi**:
  * Command penjadwalan (`HermesMarketUpdateCommand`, `HermesMorningBriefingCommand`) kini memanggil langsung `processOutboxQueue()` pasca insert outbox berhasil.
  * Mengeliminasi 15 detik waktu tunggu cron, memangkas total latensi dari 22 detik menjadi **~4 detik**.

Dalam rilis strategi `v1.2.0-deterministic` (Config Hash: `9a4c32b1e705`), dilakukan rekonsiliasi parameter dan tata kelola risiko:

1. **Parameter Worst-Entry Net RR (Threshold: $\ge 0.80\text{x}$)**:
   - **Status Metodologis**: **`EXPERIMENTAL_RISK_PARAMETER`**.
   - **Konteks & Asal Usul**:
     * Ditambahkan untuk mencegah terbitnya plan dengan asimetri risiko terbalik pada batas atas zona beli (kasus BREN worst-entry bruto 0.50x / neto 0.34x).
     * Nilai ambang batas $0.80\text{x}$ merupakan batas toleransi pengaman eksperimental, **BUKAN GUARD YANG TELAH TERBUKTI PROFITABLE SECARA HISTORIS MAUPUN STATISTIS**.
     * Parameter ini dibekukan sebagai baseline kontrol risiko internal, dan tidak boleh diklaim sebagai jaminan keuntungan trading.

2. **Dua Kontrak Eksekusi Alternatif (Breakout vs Pullback)**:
   - Pesan trading plan menyajikan dua alternatif zona entry:
     * **Breakout Entry**: Masuk saat harga menembus level High H-1 secara momentum (toleransi max: level pemicu breakout).
     * **Pullback Entry**: Masuk saat harga menguji kembali area support pembukaan sebelum melanjutkan penguatan.
   - **Integritas Kontrak**: Keduanya merupakan **dua skenario pelaksanaan alternatif dari satu trading plan yang sama**. Ditegaskan bukan sebagai dua posisi trading independen terpisah.

3. **Pengukuran Latensi Pengiriman Nyata Pipeline**:
   - **Analisis Jeda 09:15:02 -> 09:15:24 (22 detik)**:
     * `09:15:02 WIB`: Scheduler men-trigger `hermes:market-update 09:15`.
     * `09:15:02 - 09:15:20 WIB` (~18 detik): Proses penulisan dan pembacaan snapshot evaluasi ke database/disk.
     * `09:15:20 WIB`: Outbox #117 dibuat status `QUEUED`.
     * `09:15:24 WIB`: Worker outbox fast-track mengeksekusi pengiriman Telegram via Hermes CLI dan menerima receipt Telegram Message #614.
     * Evaluasi Bottleneck: Jeda pengiriman bukan berasal dari jaringan Telegram (latensi API Telegram ~2-4 detik), melainkan jeda sinkronisasi antrean outbox worker lokal (cron 1 menit). Telah dioptimalkan untuk memicu flush outbox langsung pasca pembuatan pesan darurat/koreksi.
4. **Status Evaluasi Per Tanggal 04 Nov 2026 18:00 WIB**:
   - **Sudah Dapat Dievaluasi**: **2 Plan Scalping** (BREN & CDIA). Karena sesi perdagangan BEI 04 Nov 2026 telah berakhir dan hanya tersedia data bar EOD agregat tanpa tick intraday, status pemantauan tercatat **`EXPIRED_WITH_COVERAGE_GAP`** (tanpa mengarang urutan mikro-tick intraday).
   - **Belum Jatuh Tempo (Berjalan)**: **2 Plan Swing** (BJTM & DFAM). Status: **`WAITING_FOR_TRIGGER`** (Horizon T+5 jatuh tempo pada 11 Nov 2026).
5. **Metrik Hit Rate Forward Terkonfirmasi**: **BELUM ADA (0.0% dari 0 Plan Forward Matang)**.
   - Denominator matang bersifat eksplisit: hanya menghitung rekomendasi forward yang terkirim ke Telegram.
   - Ambiguous, coverage gap, dan setup tanpa trigger tidak disamakan dengan transaksi rugi.

### Bukti Visual Dashboard & Payload Aktual
- **Tangkapan Layar Dashboard Histori (`/history`)**:
  `C:\Users\ASUS\AppData\Local\hermes\cache\screenshots\browser_screenshot_30ca3f6755594bb6a01628c9996f3fd6.png`
  (Menampilkan kartu audit: `4 Diputuskan • 0 Forward Terkirim • 4 Retrospektif • 4 Tidak Terkirim`, `Hit Rate Forward: BELUM ADA`).
- **Tangkapan Layar Dashboard Overview (`/`)**:
  `C:\Users\ASUS\AppData\Local\hermes\cache\screenshots\browser_screenshot_8b8cd28e96194514aa5ed9e35b883cb2.png`
  (Menampilkan isolasi penuh: tabel kandidat hanya memuat screening konstituen pilot; 4 plan retrospektif diisolasi total dari overview publik).
- **Tangkapan Layar Detail Emiten Pemantauan (`/market/BREN?tab=monitoring`)**:
  `C:\Users\ASUS\AppData\Local\hermes\cache\screenshots\browser_screenshot_badfa1ff6adb4394b5d3ca9863de3fac.png`
  (Menampilkan baris pill emas `TELEGRAM SCALPING`, harga acuan Rp 2.170, target Rp 2.230, CL Rp 2.050, label: `Audit Retrospektif (Bukan Forward)`).
- **Payload Outbox #106 (Telegram Message ID #601, Terkirim 08:30:39 WIB)**:
  `BRIEFING PAGI PASAR BEI (PRE-OPENING) — 04 Nov 2026, 08:30 WIB` (Briefing 10 konstituen EOD tanpa TP/CL numerik). Dilabeli `LEGACY_INCOMPLETE_CONTRACT`.

---

## 11. Penutupan Implementasi & Ketentuan Observasi Produksi

### Definisi Matematis dan Formula 7 Metrik Rekapitulasi (Cohort Forward)

```text
1. TRIGGER RATE:
   Rumus       : (forward_trigger_observed / total_forward_delivered) * 100%
   Numerator   : Jumlah rekomendasi forward terkirim yang pemicu entry-nya terbukti tersentuh.
   Denominator : total_forward_delivered (Hanya rekomendasi forward yang delivered via Telegram).
   Catatan     : Mengukur efektivitas level pemicu masuk ke fase aktif.

2. TARGET-HIT RATE (KONFIRMASI AKURASI):
   Rumus       : (forward_target_hit / forward_triggered_matured) * 100%
   Numerator   : forward_target_hit (Hanya sinyal yang mencapai target setelah pemicu aktif).
   Denominator : forward_triggered_matured (forward_target_hit + forward_invalidated + forward_expired_post_trigger).
   Catatan     : AMBIGUOUS DIKELUARKAN TOTAL dari numerator maupun denominator. Jangan menyebut terkonfirmasi jika mencampuradukkan status ambigu.

3. AMBIGUOUS RATE:
   Rumus       : (forward_ambiguous / forward_matured_total) * 100%
   Numerator   : forward_ambiguous (Tabrakan target dan invalidation pada bar sama tanpa mikro-tick, atau bar EOD sama-hari pasca delivery tengah sesi).
   Denominator : forward_matured_total.
   Catatan     : Kategori data independen akibat limitasi resolusi bar; TIDAK BOLEH diklaim sebagai kegagalan/incorrect.

4. EXPIRED TANPA TRIGGER RATE:
   Rumus       : (forward_expired_untriggered / forward_matured_total) * 100%
   Numerator   : forward_expired_untriggered (Sinyal yang masa berlakunya habis saat masih WAITING_FOR_TRIGGER).
   Denominator : forward_matured_total.
   Catatan     : Setup tidak pernah aktif/terpicu; DILARANG disamakan dengan transaksi rugi atau drawdown portofolio.

5. EXPIRED SETELAH TRIGGER RATE:
   Rumus       : (forward_expired_post_trigger / forward_matured_total) * 100%
   Numerator   : forward_expired_post_trigger (Pemicu teramati tersentuh, namun target maupun CL tidak tersentuh hingga horizon habis).
   Denominator : forward_matured_total.

6. COVERAGE GAP RATE:
   Rumus       : (forward_coverage_gap / total_forward_delivered) * 100%
   Numerator   : forward_coverage_gap (EXPIRED_WITH_COVERAGE_GAP + EXPIRED_WITH_PARTIAL_COVERAGE).
   Denominator : total_forward_delivered.
   Catatan     : Menunjukkan proporsi rekomendasi yang pemantauannya terhalang oleh data bursa yang bolong/hilang.

7. PROVISIONAL OUTCOME RATE:
   Rumus       : (forward_provisional / total_forward_delivered) * 100%
   Numerator   : forward_provisional (Evaluasi sementara berbasis bar penutupan provisional pasca-pasar).
   Denominator : total_forward_delivered.
   Catatan     : Dipisahkan dari denominator evaluasi final sampai rekonsiliasi EOD harian resmi selesai.
```

### Prosedur Operasional Slot Otomatis Terjadwal
1. **Tidak Ada Siar Ulang Slot Kedaluwarsa**:
   Seluruh slot 04 Nov 2026 (08:30, 09:15, 12:15, 15:15) berstatus lewat dan dilarang disiarkan ulang.
2. **Slot Otomatis Berikutnya**:
   - `20:00 WIB` (04 Nov 2026): `hermes:evening-review` mempublikasikan rekap EOD & evaluasi trading plan ke Telegram.
   - `08:30 WIB` (Hari Bursa Berikutnya): Briefing pra-pasar & screening universe terstruktur.
     Jika syarat screening atau data RG belum memenuhi kualitas, pesan wajib mencantumkan `BELUM ADA SETUP` (tanpa membuat kandidat fiktif).
3. **Integritas Siklus Hidup**:
   Kontrak dan evidence di-snapshot SHA-256 sebelum dispatch. Tautan ke Outbox, Receipt Telegram, dan Dashboard tercatat otomatis setelah proses pengiriman riil berhasil.
4. **Kelangsungan Layanan**:
   Worker background scheduler 1-menit, supervisor WebSocket running trade (`PID 18392`), runner EOD harian, dan background backfill universe 100 emiten/jam tetap berjalan aktif tanpa gangguan.
