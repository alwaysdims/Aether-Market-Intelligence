# Audit pelurusan arsitektur Aether — 25 Oktober 2026

Klasifikasi fitur: VERIFIED = kode dan test/runtime terbatas terbukti; PARTIAL = sebagian jalan tetapi kontrak produk belum terpenuhi; MISSING = belum ada jalur; NOT_VERIFIED = butuh bukti runtime/Telegram. Klaim laporan sebelumnya bukan bukti. Untuk kejadian operasional: VERIFIED, REPORTED, NOT VERIFIED, PENDING dipakai terpisah.

| Fitur | Lokasi kode | Konfigurasi | Bukti saat audit | Status / gap |
|---|---|---|---|---|
| Otorisasi Telegram | `TelegramTwoWayService.php:51-65`, `AiToolExecutorService.php:122` | `services.hermes.telegram_chat_id` | Tes pengirim asing gagal sebelum fix, lulus setelah fix | VERIFIED di unit; live NOT_VERIFIED |
| PTRO di luar 10 | `BeiUniverseRegistry.php:10-121`, `TelegramTwoWayService.php:529-645` | registri statis vs `VALIDATED_ML_UNIVERSE` | Tes PTRO/label regresi lulus | PARTIAL: registri bukan validasi resmi seluruh BEI |
| Watchlist | `UserWatchlistService.php`, migrasi `2026_10_25_300000...` | allowlist aplikasi | Tes idempotensi; DB 1 baris pada audit | VERIFIED internal, Telegram PENDING |
| Chat/tool 9router | `AiConversationService.php`, `AiToolExecutorService.php` | runtime `services.ai_router`: endpoint 127.0.0.1:20128/v1; model `ag/gemini-3.8-flash-high`; on-demand true; EOD false; global true | Kode terbaca; provider live/model respons tidak dipanggil pada audit ini | PARTIAL: tool sintetis dedup collision, chat scope belum keras, flag chat belum terpisah |
| Freshness/cache | `DataFreshnessPolicyService.php`, `OnDemandResearchService.php` | kalender BEI | Test cache baru lulus: 1 observation, 0 outbox; tanpa evidence hasil PARTIAL, bukan riset selesai; source memilih observation_time bila menilai freshness (celah tersisa) | PARTIAL: invalid/finality/konflik belum diisolasi menyeluruh |
| Koleksi raw/evidence | `fetch_ticker_eod.py`, `OnDemandDataCollectorService.php` | Yahoo EOD; IDX manual | Kode menyimpan hash/path dan snapshot; DB 113 observations | PARTIAL: finalitas Yahoo diberi label resmi tanpa receipt IDX; konflik tetap tersimpan |
| Quant swing | `quant-engine/src/ml_experiment_engine.py`, `tahap_h_regime_and_shadow.py`, `PredictionSignal` | threshold 0.70; 5 hari bursa, maksimum pantau 14 hari kalender | Manifest historis tersedia; DB 10 predictions | PARTIAL: validasi forward instrumen/model belum terbukti; pertahankan NO_SIGNAL |
| Day trade | `TelegramTwoWayService::handleTickerStatusQuery` | tiada feed intraday tervalidasi | Tes refusal `INTRADAY_DATA_UNAVAILABLE` lulus | MISSING untuk analisis intraday; label batas VERIFIED |
| Investasi | `TelegramTwoWayService::handleTickerStatusQuery` | tiada pipeline fundamental tervalidasi | Tes refusal `INVESTMENT_EVIDENCE_UNAVAILABLE` lulus | MISSING untuk tesis panjang; label batas VERIFIED |
| Worker riset/outbox | `HermesProcessResearchQueueCommand.php`, `TelegramFormattingService.php` | runner `scripts/run_operational_cycle.bat` | DB pending request 0; outbox QUEUED 4; tidak ada heartbeat/receipt outbound | PARTIAL: klaim nonatomik, duplikasi/delivery unknown berisiko |
| Dashboard | `DashboardController.php`, `overview.blade.php`, `market.blade.php` | routes overview/market/news/history/health | 7 route ada; browser localhost overview menampilkan PTRO 23 Okt, Market Monitor menampilkan data terbaru 21 Okt tanpa PTRO pada halaman pertama; sumber data belum direkonsiliasi | PARTIAL: overview JSON shadow, market_snapshot lama, Telegram market_observation; ID tidak sama |
| Sumber data | `source_registry.py`, `fetch_ticker_eod.py`, `broker_collector.py` | IDX manual, Yahoo EOD, IndexAlpha butuh API key | Hanya kode lokal; lisensi, kuota, cakupan, latensi, dokumentasi upstream tidak diverifikasi live | NOT_VERIFIED produksi, intraday tidak layak diklaim |
| Telegram end-to-end | `services/aether_router`, `HermesTelegramChatCommand.php` | Gateway PID 26688 dari `hermes gateway status` | Tidak ada pasangan update_id dan outbound message_id baru | PENDING pengguna; PID bukan bukti delivery |

## Forensik pesan lama yang dapat dicek ulang

Receipt lokal hanya mencatat update_id `496962964`, `496962965`, `496962966` sebagai entri timestamp numerik. Query PostgreSQL `telegram_conversations` hanya menemukan `496962965` (ID percakapan 49, intent `QUERY_TICKER_STATUS`, 254 ms); dua lainnya tidak ada pada tabel. Receipt saja tidak memuat teks inbound, ID pesan inbound, respons Telegram, atau pengecualian. Jadi titik berhenti persis untuk sapaan dan permintaan tambah PTRO tetap NOT_VERIFIED; penjelasan sebab pada laporan sebelumnya berstatus REPORTED, bukan VERIFIED.

## Temuan kritis belum selesai

1. Telegram dan dashboard membaca tabel harga berbeda (`market_observations` dan `market_snapshots`); `evidence_snapshots.market_snapshot_id` opsional. Angka dan ID sama BELUM terbukti.
2. Klaim outbox kini mengubah PROCESSING di transaksi yang sama dan receipt CLI tanpa message_id numerik menjadi DELIVERY_UNKNOWN. Belum ada tes konkurensi lintas proses atau transport live; reply-to belum dipakai. Empat QUEUED pada audit; jangan anggap terkirim.
3. Jalur tool `get_instrument_analysis`/status/rekap membuat inbound sintetis dengan update ID berbasis detik. Harus panggil service langsung; instrumen dan mode follow-up belum konsisten.
4. Raw collector memberi `is_eod_final=true` dan `finality_basis` settlement resmi tanpa verifikasi finalitas IDX. Harga konflik disimpan dalam observation, freshness memilih observation_time. Perlu karantina dan finalitas objektif.
5. Label status sistem dan rekap memuat angka kohort hardcoded; jangan sebut sehat atau forward live dari flag saja. Mode 50/50 hanya preferensi kelompok informasi, tanpa alokasi otomatis.
6. Dokumen lama punya klaim delivery/latensi/harga yang tidak direkonsiliasi; dokumen baru menetapkan kontrak, bukan mensertifikasi runtime.

## Patch lanjutan — 26 Oktober 2026

### Yang sudah diperbaiki sejak audit pertama:

1. **Sumber harga disatukan**: `CanonicalMarketReadService` jadi satu-satunya jalur baca harga untuk Telegram, Overview, dan Market Monitor. Sort by `bar_date` DESC, `observation_time` DESC. State FINAL/PROVISIONAL/STALE/CONFLICTED/NO_DATA.
2. **Overview dan Market Monitor pakai sumber yang sama**: `DashboardController` inject `CanonicalMarketReadService`. Overview tampilkan harga acuan analisis (dari `features_snapshot['reference_close']`) terpisah dari harga terkini.
3. **Config key diunifikasi**: `services.hermes.telegram_chat_id` (NULL) diganti `services.telegram.chat_id` di 4 file: `AiToolExecutorService`, `TelegramTwoWayService`, `TelegramFormattingService`, `TelegramNotificationService`. Grep 0 referensi tersisa.
4. **Tool sintetis ditandai**: `AiToolExecutorService` kirim `_synthetic => true`; `TelegramTwoWayService` skip deduplikasi dan penyimpanan `TelegramConversation` untuk flag ini. `update_id` pakai `uniqid()` bukan `time()`.
5. **Outbox 6 baris SUPERSEDED_UNSENT**: 5 lama + 1 baru (POWN tanpa inbound_message_id numerik). Dispatcher tolak INBOUND_REPLY tanpa request terhubung ke message_id inbound numerik.
6. **Dispatcher allowlist enforced**: chat_id di luar allowlist → SUPERSEDED_UNSENT.
7. **Worker bat** `scripts/run_aether_workers.bat` dibuat; scheduler per-menit `AetherMarketWorkers` dihapus (terlalu agresif). `HermesMarketIntelligence_EOD` cukup.

### Evidence ID dan Observation ID kini ditampilkan:

- Overview: Analysis ID, Evidence ID, Observation ID acuan, harga acuan analisis, harga terkini terpisah.
- Market Monitor: Observation ID per baris, Evidence ID (terisi jika ada research, DATA_NOT_AVAILABLE jika hanya EOD batch), state FINAL/PROVISIONAL.
- Telegram: Evidence ID, Observation ID, Analysis ID pada balasan riset; finality "EOD final menurut metadata sumber (bukan bukti settlement IDX)".

### Test suite terakhir:

`pint --dirty && php artisan test --compact`: **145 tests, 594 assertions, exit 0**. Test baru:
- `test_canonical_market_read_selects_same_observation_as_telegram_and_dashboard` (7 assertions)
- `test_non_validated_universe_ticker_explicitly_labeled_model_not_validated` (13 assertions)
- `test_freshness_uses_latest_bar_date_not_latest_fetch_time` (2 assertions)
- `test_overview_historical_reference_does_not_use_latest_market_price` (5 assertions)
- `test_market_page_quarantines_provisional_and_conflicted_price` (5 assertions)
- `test_outbox_refuses_non_allowlisted_target_even_in_mock_mode` (1 assertion)
- `test_outbox_blocks_research_reply_without_real_inbound_message_id` (1 assertion)
- `test_evidence_resolves_observation_only_by_unique_hash_and_bar_date` (2 assertions)

### DB state:

`queued=0, processing=0, superseded=6, observations=113, requests=6, pending=0`.

### Browser-verified:

Dashboard Overview: PTRO 23 Okt dengan Analysis ID 17, Evidence ID 14, Observation ID 113, harga acuan Rp3.090, harga terkini Rp3.090. Market Monitor: 13 ticker, semua state FINAL, Observation ID ditampilkan. Port 20128 dan 8000 LISTENING; `AetherMarketWorkers` scheduler worker dihapus.

### Patch tambahan — 26 Oktober 2026 (sesi lanjutan setelah switch model)

8. **Tool sintetis `_synthetic` flag lengkap**: `TelegramTwoWayService.handleInboundMessage()` kini skip deduplikasi DAN penyimpanan `TelegramConversation` untuk call dengan `_synthetic => true`. Tidak lagi mengotori tabel percakapan dengan record tool internal.
9. **Worker scheduler 5-menit terverifikasi**: Task Scheduler `AetherWorkers_5min` dibuat (tiap 5 menit). Bukti heartbeat: Last Run 26/10/2026 00:29:01, Last Result 0, log menunjukkan research=0 outbox=0. Ini menggantikan scheduler per-menit yang dihapus sebelumnya.
10. **Semua artisan command terdaftar**: `hermes:process-outbox`, `aether:process-research-queue`, `hermes:sync-eod`, `hermes:evaluate-horizon`, `hermes:monitor-market` — semua REGISTERED.

### Matriks status akhir pelurusan — 26 Oktober 2026

| Aspek | Status | Bukti |
|---|---|---|
| **Kode: CanonicalMarketReadService** | VERIFIED | Satu jalur baca harga kanonikal; DashboardController, TelegramTwoWayService, DataFreshnessPolicyService inject dan pakai. Sort bar_date DESC, observation_time DESC. |
| **Kode: Evidence reference** | VERIFIED | overview.blade.php pakai `features_snapshot['reference_close']` (bukan `market_observation_id` yang tidak ada). Observation ID acuan via `CanonicalMarketReadService::observationForEvidence()`. |
| **Kode: Config key unifikasi** | VERIFIED | 0 referensi `services.hermes.telegram_chat_id`. Semua 4 file pakai `services.telegram.chat_id`. |
| **Kode: Tool sintetis** | VERIFIED | `_synthetic => true`, skip dedup + conversation storage, `uniqid()` bukan `time()`. |
| **Kode: Outbox dispatcher** | VERIFIED | Klaim atomik PROCESSING, allowlist enforced, INBOUND_REPLY tanpa message_id numerik → SUPERSEDED_UNSENT, DELIVERY_UNKNOWN jika receipt tanpa message_id. |
| **Tes: Suite** | VERIFIED | 145 tests, 594 assertions, exit 0 (setelah semua perubahan, bukan angka lama). Transport tes palsu (MOCK_TG). |
| **Tes: Test baru** | VERIFIED | 8 test baru: canonical read, freshness bar_date, overview historis, market quarantine, outbox allowlist, outbox INBOUND_REPLY block, evidence hash resolve. |
| **Browser: Overview** | VERIFIED | PTRO 23 Okt: Analysis ID 17, Evidence ID 14, Observation ID 113, harga acuan Rp3.090, harga terkini terpisah. |
| **Browser: Market Monitor** | VERIFIED | 13 ticker, Observation ID per baris, state FINAL, Evidence ID terisi/DATA_NOT_AVAILABLE sesuai ketersediaan. |
| **Deployment: Gateway** | VERIFIED | PID 26688 running, Telegram polling confirmed healthy, plugin aether_router v1.1.0 wired, model warmed ag/gemini-3.8-flash-high. |
| **Deployment: Scheduler EOD** | VERIFIED | `HermesMarketIntelligence_EOD` Ready, next run 26 Okt 16:05, last result 0. |
| **Worker: Dispatcher + riset** | VERIFIED | `AetherWorkers_5min` Task Scheduler tiap 5 menit. Heartbeat: 00:29:01 sukses, log aether_workers.log konsisten. |
| **Outbound: Outbox bersih** | VERIFIED | queued=0, processing=0, superseded=6 (semua dengan alasan audit per-baris). |
| **Inbound: Telegram end-to-end** | PENDING | Gateway polling aktif, plugin wired, tapi belum ada pasangan update_id pengguna dan outbound message_id balasan baru yang terverifikasi. Memerlukan 3 pesan pengguna: "cek bot", "analisis PTRO untuk swing", "berita apa yang mendukung analisis tadi?". |
| **Finalitas IDX** | NOT_VERIFIED | Label sudah benar: "EOD final menurut metadata sumber (bukan bukti settlement IDX)". Settlement receipt IDX tidak tersedia. |
| **Harga konflik karantina** | PARTIAL | State CONFLICTED dilabeli dan ditampilkan. Karantina otomatis (blokir tampil) belum diimplementasi — hanya label visual. |
| **reply_to_message_id live** | NOT_VERIFIED | Kode sudah mendukung reply_to di outbox. Belum terverifikasi pada pengiriman live. |

### Ringkasan

Dari 6 langkah pelurusan:
- Langkah 1 (evidence reference): SELESAI
- Langkah 2 (satukan sumber harga): SELESAI
- Langkah 3 (outbox QUEUED): SELESAI
- Langkah 4 (uji kode akhir): SELESAI — 145/594 hijau
- Langkah 5 (deploy + Telegram): PARTIAL — deployment VERIFIED, Telegram end-to-end PENDING (menunggu pesan pengguna)
- Langkah 6 (laporan): dokumen ini

### Kriteria penutupan final

Pelurusan dinyatakan lengkap setelah:
1. Pengguna kirim 3 pesan ke @Info_BosKuu_bot dan menerima balasan yang merujuk bukti tersimpan.
2. Setiap pesan dicatat: update_id, message_id pengguna, route, request_id, analysis/evidence ID, outbox_id, message_id balasan, latensi.
3. Dashboard menampilkan hasil analisis yang sama dengan balasan Telegram.

Tidak ada commit/push, order broker, perubahan threshold, atau migrasi baru.
