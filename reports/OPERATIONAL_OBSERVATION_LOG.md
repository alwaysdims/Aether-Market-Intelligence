# Log Observasi Operasional Aether Market Intelligence (Dashboard V3 — Tiga Hari Bursa)

**Status**: FREEZE ACTIVE & OBSERVATION PROTOCOL INITIATED • **Versi**: 3.3.1  
**Tanggal Mulai**: Minggu, 1 November 2026 WIB (Kalender Pasar: Akhir Pekan Bursa Tutup)  
**Periode Observasi 3 Hari Bursa Pertama**:
1. **Hari Bursa 1**: Senin, 2 November 2026 WIB
2. **Hari Bursa 2**: Selasa, 3 November 2026 WIB
3. **Hari Bursa 3**: Rabu, 4 November 2026 WIB

---

## 1. Penutupan Evaluasi Tahap 3: Regresi Coverage Gap Parsial

### a. Logika Coverage Gap Berbasis Kalender BEI
Layanan `MarketMonitoringService` telah disempurnakan untuk memvalidasi daftar seluruh sesi bursa yang diharapkan (`getTradingDaysBetween($start, $end)`) menggunakan kalender resmi BEI (`BeiCalendarService`):
1. **Celah Total (`TOTAL_GAP` -> `EXPIRED_WITH_COVERAGE_GAP`)**:
   - Jika 0 sesi bursa EOD terverifikasi ditemukan dalam jendela 14 hari kalender.
2. **Celah Parsial (`PARTIAL_GAP` -> `EXPIRED_WITH_PARTIAL_COVERAGE`)**:
   - Jika sebagian sesi bursa ditemukan (misal: 2 dari 11 sesi trading), tetapi ada sesi kalender bursa yang terlewat (`missing_sessions`).
   - Sistem **tidak menganggap seluruh periode sudah diperiksa** dan **dilarang menyatakan clean EXPIRED**.
   - Deskripsi mencatat secara eksplisit sesi yang belum diperiksa: *"Target tidak tersentuh pada X sesi terverifikasi, namun terdapat Y sesi bursa kalender belum diperiksa (daftar tanggal missing). Status ditandai EXPIRED_WITH_PARTIAL_COVERAGE."*
3. **Penuh Terverifikasi (`NONE` -> `EXPIRED`)**:
   - Hanya diberikan jika seluruh sesi bursa kalender ($obsCount \ge totalExpectedSessions$ dan $missingSessions = \emptyset$) terverifikasi lengkap.

### b. Audit Provenance 10 Sinyal Abstain
- **Pemisahan Status Keputusan vs Status Provenance**:
  * **Status Keputusan**: **`ABSTAIN`** (`signal_status: NO_SIGNAL_ABSTAIN`), karena probabilitas model berada di bawah ambang batas selektif ($\text{Confidence} \approx 0.29\text{--}0.31 < 0.40$ / $\tau = 0.70$).
  * **Status Provenance**: **`PROVENANCE_UNVERIFIED`** (Bukan `FORWARD_SHADOW` dan bukan klaim `RETROSPECTIVE_REPLAY` definitif).
  * **Alasan Audit Faktual**: Berkas `data/shadow_predictions/shadow_predictions_ledger.json` bertanggal 16 Oktober 2026 tidak memiliki bukti stempel waktu eksternal independen (seperti tanda terima Telegram outbox live, hash blockchain/transaksi publik, atau repositori terarsip pihak ketiga) yang membuktikan bahwa prediksi dicatat sebelum hasil sesi pasar terjadi.
  * **Ketetapan Sistem**: Seluruh rekod ledger bertanggal lampau tanpa bukti waktu eksternal dilabeli secara eksplisit sebagai `PROVENANCE_UNVERIFIED`. Dilarang menaikkan status menjadi forward terverifikasi hanya berdasarkan keberadaan berkas JSON lokal.

### c. Formula Dedup Key Digest Telegram Granular
Format kunci deduplikasi digest pada `HermesPredictionRecapCommand.php`:
$$\text{DedupKey} = \text{RECAP\_}\{\text{cohort}\}\_\{\text{chat\_id}\}\_\{\text{eval\_start}\}\_\{\text{eval\_end}\}\_\{\text{stable\_result\_hash}\}$$
- Mencakup: Chat ID, Kohort/Strategi, Rentang tanggal evaluasi kalender, dan Hash stabil 8-karakter dari ringkasan metrik hasil.

---

## 2. Protokol Observasi Operasional 3 Hari Bursa (2, 3, 4 November 2026)

Pembekuan perubahan fitur (*feature freeze*) aktif. Seluruh eksekusi berjalan otomatis melalui Windows Task Scheduler dan cron runner OS:

| Komponen Operasional | Pemicu & Jadwal OS | Pemilik Proses (Single Owner) | Baseline & Tolok Ukur Keberhasilan |
| :--- | :--- | :--- | :--- |
| **Morning Briefing** | 08:30 WIB (Senin - Jumat) | Task Scheduler `\AetherScheduledDispatcher_1min` -> `schedule:run` | Ringkasan pra-pembukaan bursa terkirim ke Telegram via Outbox; latensi < 10 detik. |
| **Intraday Radar (09:15, 12:15, 15:15)** | Terjadwal di dispatcher | Fast-track dispatcher | Tetap diblokir (*BLOCKED*) secara sadar selama feed kuotasi intraday berstatus `QUARANTINED`. |
| **EOD Ingestion Pipeline** | 16:05 WIB (Senin - Jumat) | Task Scheduler `\HermesMarketIntelligence_EOD` -> `run_eod_pipeline.bat` | Mengambil bar EOD resmi `IDX Edge PRO` untuk 10 saham pilot + IHSG; memvalidasi `is_eod_final = true`. |
| **Post-Horizon & Monitoring** | 16:15 WIB (pasca EOD) | `hermes:evaluate-horizon` & `hermes:monitor-market` di `run_eod_pipeline.bat` | Memeriksa evaluasi jatuh tempo; sinyal belum jatuh tempo (due 06 Nov 2026) tetap `PENDING` (Anti-lookahead). |
| **Broker Summary Sync** | 20:00 WIB (Senin - Jumat) | `hermes:sync-idx-edge-broker` & `hermes:evening-review` | Agregasi broker Pasar Reguler (RG) tersimpan; kuota API terpantau. |
| **Asynchronous Consultation** | Setiap menit (24/7) | `\AetherScheduledDispatcher_1min` -> `hermes:process-consultation-queue --live` | Pemrosesan kueri pengguna; pengiriman jawaban kanonikal non-blocking; timeout 180s subprocess Hermes. |

---

## 3. Log Harian Observasi Faktual

### Hari Bursa 0: Minggu, 1 November 2026 (Pra-Observasi & Akhir Pekan)
- **Status Kalender BEI**: Bursa Tutup (Akhir Pekan).
- **Audit Sistem & Database**:
  * Status PostgreSQL: Terhubung (`project-bot`).
  * Status Gateway Telegram: Aktif (PID `19844`).
  * Status Task Scheduler: `\AetherScheduledDispatcher_1min` (aktif tiap 1 menit), `\HermesMarketIntelligence_EOD` (siap trigger 16:05 WIB hari kerja berikutnya).
  * Sinyal Forward Terdaftar: 10 sinyal abstain (shadow 16 Okt), 6 sinyal candidate screening (30 Okt), seluruhnya berstatus `PENDING` menunggu jatuh tempo 06 November 2026.
  * Hasil Evaluasi Live: **0 directional matang (N/A)**. Tidak ada outcome fiktif.
- **Intervensi Manual**: Nihil.
- **Biaya Kuota API**: 0 panggilan (pasar tutup).

### Hari Bursa 1: Senin, 2 November 2026 (Menunggu Eksekusi Terjadwal OS)
- **Status Ingestion EOD**: Menunggu 16:05 WIB.
- **Morning Briefing (08:30 WIB)**: Menunggu dispatch otomatis OS.
- **Evening Review (20:00 WIB)**: Menunggu dispatch otomatis OS.
- **Backlog Outbox Telegram**: 0 backlog.
- **Temuan Anomali / Blocker**: Nihil.

### Hari Bursa 2: Selasa, 3 November 2026 (Menunggu)
- *(Akan dicatat faktual setelah sesi berjalan)*

### Hari Bursa 3: Rabu, 4 November 2026 (Menunggu)
- *(Akan dicatat faktual setelah sesi berjalan)*

---

## 4. Batasan Evaluasi Horizon 5 Hari Bursa & Anti-Lookahead
- **Sinyal Terbit 30 Oktober 2026**:
  * Menggunakan data sesi EOD penutupan 30 Oktober 2026.
  * Berdasarkan kalender resmi BEI (`addTradingDays(2026-10-30, 5)`), horizon 5 hari bursa baru akan jatuh tempo pada **Jumat, 6 November 2026**.
  * Sesi kalender bursa perantara: 1) Sen 2 Nov, 2) Sel 3 Nov, 3) Rab 4 Nov, 4) Kam 5 Nov, 5) Jum 6 Nov.
- **Ketetapan Penutupan Periode Observasi (Rabu, 4 November 2026)**:
  * Pada akhir observasi 4 November 2026 (hari bursa ke-3), sinyal 30 Oktober berstatus **`PENDING`** karena jatuh tempo belum tercapai.
  * Status `PENDING` ini merupakan penegakan prinsip ketat **anti-lookahead** (dilarang mengevaluasi sebelum T+5 selesai), **BUKAN kegagalan monitoring atau kegagalan worker**. Sinyal tetap aktif dalam pemantauan target/invalidation harian.

---

## 5. Status Pengujian Regresi Otomatis

Suite pengujian `tests/Feature/RecommendationMonitoringAndOutcomeTest.php` mencakup 10 kasus uji terisolasi:
1. `test_recommendation_registration_contract_preserves_immutable_rules` (PASS)
2. `test_target_hit_detected_with_valid_final_eod_observation` (PASS)
3. `test_ambiguous_hit_when_target_and_invalidation_touched_same_bar` (PASS)
4. `test_horizon_evaluation_for_matured_prediction` (PASS)
5. `test_future_prediction_not_evaluated_before_maturity` (PASS)
6. `test_expired_with_coverage_gap_when_no_verified_observations_found` (PASS)
7. `test_non_ml_screening_contract_stores_origin_and_strategy_version` (PASS)
8. `test_expired_with_partial_coverage_gap_when_some_trading_days_missing` (PASS)
9. `test_telegram_digest_dedup_key_includes_chat_cohort_range_and_hash` (PASS)
10. `test_shadow_ledger_predictions_classified_as_provenance_unverified` (PASS)
- **Hasil**: 10 tests, 29 assertions PASSED 100% (durasi 884 ms).

---

## 6. Keterbatasan yang Tetap Dipertahankan (Ketetapan Integritas Riset)

1. **Quote Intraday Tetap Quarantined**: Endpoint `/api/price` tidak diaktifkan untuk rekomendasi day trade atau eksekusi live signal karena kontaminasi transaksi Pasar Negosiasi (NG).
2. **Outcome Live Tetap Pending**: Sinyal forward bertanggal 30 Oktober 2026 baru akan jatuh tempo pada 6 November 2026 ($T+5$). Sistem tidak membuat evaluasi dini sebelum bar EOD final tanggal tersebut tersedia.
3. **Batas Dokumen Primer**: Emiten di luar sampel fisik laporan audit tetap berstatus `PROVIDER_SUPPLIED`.
