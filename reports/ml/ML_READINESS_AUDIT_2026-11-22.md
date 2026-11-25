# Audit Kesiapan ML dan Diagnosis Gemini

Tanggal: 2026-11-22
Status publikasi sinyal produksi: TIDAK AKTIF
Klasifikasi: VERIFIED untuk temuan kode, konfigurasi, artefak, dan evaluasi offline; PENDING untuk inferensi Gemini sukses dan data intraday lengkap.

## 1. Diagnosis Gemini

Request: `REQ_20261122_181203_BRIS_wbTi`
Correlation ID: `AI_20261122_111210_682ac1b5`

Bukti log:
- `started_at_utc=2026-11-22T11:12:10+00:00`
- `deadline_at_utc=2026-11-22T11:12:40+00:00`
- `duration_ms=30017`
- `total_ms=30012`
- `connect_ms=0`
- `first_byte_ms=0`
- `request_bytes=17486`
- `input_chars=15686`
- `output_bytes=0`
- `http_status=null`
- `returned_model=null`
- exception: `Illuminate\\Http\\Client\\ConnectionException`
- previous: `GuzzleHttp\\Exception\\NetworkTimeoutException`
- error: `cURL error 28: Operation timed out after 30010 milliseconds with 0 bytes received`
- endpoint: local 9router `/v1/chat/completions`

Effective configuration:
- `services.consultation_ai.timeout=30`
- source: runtime `AI_ROUTER_TIMEOUT=30`
- code default `CONSULTATION_AI_TIMEOUT` fallback: 60
- runtime `AI_ROUTER_TIMEOUT` overrides fallback chain
- queue `retry_after=90`
- research request deadline metadata: 10 minutes
- worker scheduler: every minute, `runInBackground`, `withoutOverlapping(2)`

Kesimpulan: batas 30 detik terbukti berasal dari effective HTTP client configuration. Belum ada bukti worker stale, router internal deadline, atau upstream menerima request. `0 bytes` hanya membuktikan tidak ada response body diterima pada client; tidak membuktikan upstream tidak menerima request. Worker tidak direload. Request Gemini tidak diulang.

Cache/startup:
- Guarded dispatcher memakai isolated cache path `C:/Users/ASUS/AppData/Local/Aether-Market-Intelligence/recovery/bootstrap-cache/dispatcher-config.php`.
- Runtime config readback menunjukkan timeout efektif 30.
- Process start timestamp tidak tersedia dari `wmic` pada shell ini; stale-worker claim tetap tidak terverifikasi.

## 2. Regression recovery

Recovery sebelumnya sudah lulus runtime acceptance. Tambahan regression test belum diterapkan pada Laravel suite karena route middleware bypasses guard under `app()->runningUnitTests()`; browser acceptance tetap bukti guard nyata.

Runtime facts retained:
- query-param recovery: lulus
- terminal polling stop: lulus
- reload tanpa provider/inference baru: lulus
- direct request tanpa capability: `403 FORBIDDEN_RESEARCH_ORIGIN`
- result recovery memakai stored result

Gap: dedicated isolated browser harness belum ditulis. Tidak mengulang full acceptance.

## 3. ML map

Training/evaluator:
- `quant-engine/src/ml_experiment_engine.py`: 5-day directional training, preprocessing train-only, validation threshold, holdout test, fee/slippage simulation.
- `quant-engine/src/walk_forward_evaluator.py`: walk-forward evaluation.
- `quant-engine/src/tahap_h_regime_and_shadow.py`: regime model, shadow prediction, ledger evaluation.
- `dashboard/app/Services/PostHorizonEvaluatorService.php`: application evaluator for stored AI analyses.
- `dashboard/app/Services/MarketMonitoringService.php`: prediction signal horizon monitoring.

Artifacts:
- `ml_research/models/*.joblib` exists.
- `data/shadow_predictions/shadow_predictions_ledger.json`: 20 records, all `PENDING`, all `NO_SIGNAL`.
- Model artifacts do not prove production inference. No verified active production receipt for current bar.

Feature schema/source:
- 12 EOD technical features: `ret_1`, `ret_3`, `ret_5`, `ema9_ratio`, `ema20_ratio`, `ema_spread`, `rsi14`, `atr14_ratio`, `rvol20`, `dist_support20`, `dist_resistance20`, `volatility20`.
- Source: local daily OHLCV snapshots built from project raw data. Feature computation is backward-looking.

Universe:
- 10 tickers: `BBCA`, `BBRI`, `BMRI`, `BBNI`, `ASII`, `TLKM`, `MAPI`, `ARTO`, `MEDC`, `SMRA`.
- Current production scope must remain separate from research universe.

Existing target/horizon:
- `Close[t+5] - Close[t]` directional target.
- Shadow evaluator uses daily close and a calendar `+7` due date.
- Existing application evaluator uses direction threshold `+/-0.50%`.
- These do not satisfy TP `+6%`, `+15%`, `+35%`, SL max `5%` contract.

Inference and Telegram:
- Shadow inference: `ShadowPredictionEngine.generate_shadow_predictions`.
- Historical/operational signals flow through `PredictionSignal`, `MarketMonitoringService`, `TelegramFormattingService`, and outbox services.
- `src/telegram_notifier.py` can send, but shadow generation itself has no Telegram call.
- Production ML remains disabled by policy and no active artifact/receipt gate is proven.

Exact inactive conditions:
- `AI_LIVE_INFERENCE_ENABLED=false` disables EOD live AI path.
- `AI_LIVE_INFERENCE_EOD_ENABLED=false` disables EOD inference.
- Missing artifact/receipt must yield `MODEL_UNAVAILABLE` or `NO_ACTIVE_INFERENCE_FOR_BAR`; it must not expose probability as current inference.
- Non-validated instruments yield `MODEL_NOT_VALIDATED_FOR_INSTRUMENT`.

## 4. Contract evaluator correction

Added isolated evaluator:
- `quant-engine/src/contract_evaluator.py`
- `quant-engine/tests/test_contract_evaluator.py`

Rules enforced:
- entry: next available trading-session open plus one tick adverse slippage
- stop: maximum 5% below entry
- targets: +6%, +15%, +35%
- horizon: five available trading sessions
- same-bar TP and SL: `AMBIGUOUS`, excluded from hit-rate denominator
- buy fee: 0.15%
- sell fee: 0.25%
- expired trade exits at fifth-session close
- missing horizon: `DATA_INSUFFICIENT`, excluded from coverage

Intraday gap:
- available 15-minute data covers `2026-07-21` through `2026-10-14` only.
- No complete current intraday acceptance dataset. Intraday contract remains `PENDING_DATA`.

## 5. Reproducible swing baseline

Dataset:
- 10 tickers, local daily OHLCV.
- 13,740 evaluated decision samples.
- decision range: `2021-03-01` through `2026-11-13`.
- split: train 6,930; validation 2,370; test 4,440.
- coverage: 100% samples have five later sessions.

| Target | Train hit rate | Validation hit rate | Test hit rate | Test mean net return | Test ambiguous |
|---|---:|---:|---:|---:|---:|
| TP +6% | 15.7788% | 13.6287% | 18.2762% | -1.0731% | 8 |
| TP +15% | 1.8764% | 0.5907% | 2.1401% | -1.0773% | 1 |
| TP +35% | 0.0577% | 0.0000% | 0.0901% | -1.0630% | 0 |

Test signals: 4,440 decisions per target. Costs included: 0.15% buy fee, 0.25% sell fee, one tick adverse entry/exit treatment. These are rule-based opportunity baselines, not ML accuracy.

No model tuning or production publication performed. No live broker order, synthetic trade, Telegram publication, or ML activation performed.

## 6. Readiness decision

- Gemini live inference: `PENDING`, exact client timeout evidence exists; upstream cause unverified.
- Recovery flow: `VERIFIED_OPERATIONAL` from prior runtime acceptance; dedicated isolated browser harness: `PENDING`.
- Existing ML artifacts: `RESEARCH_ONLY`; production eligibility: `NOT VERIFIED`.
- Contract evaluator: `VERIFIED` by isolated tests and offline execution for swing data.
- Intraday evaluator coverage: `PENDING_DATA`.
- Model promotion: `REJECTED / NOT AUTHORIZED` until leakage-safe model metrics use this contract, artifact receipt, current-bar proof, and independent shadow outcome evidence.

## 7. Addendum: Gemini correction and contract-label candidates

Gemini acceptance request baru: `REQ_20261122_185722_BRIS_DSw4`.

- Evidence snapshot: `217`.
- Market analysis: `178`.
- Status tersimpan: `completed`.
- Launcher resmi menyelaraskan `AI_ROUTER_TIMEOUT=60`, `CONSULTATION_AI_TIMEOUT=60`, dan `CONSULTATION_AI_MAX_RETRIES=0`.
- Worker duration: `67713 ms`.
- AI latency: `50670 ms`.
- Requested/returned model: `null` / `null` pada hasil tersimpan.
- HTTP status dan transport telemetry: tidak tersedia pada hasil tersimpan.
- `fully_verified=false`.
- `completion_scope=PARTIAL_COMPONENTS`.
- Kesimpulan: timeout client terkoreksi, tetapi Gemini belum terbukti sehat.

Evaluator diperketat:
- Entry, TP, SL, dan exit dibulatkan ke tick BEI.
- Opening gap dan gap-through stop diproses konservatif.
- `AMBIGUOUS` memakai exit stop konservatif dan tetap dikeluarkan dari denominator hit-rate.
- `expected_dates` memaksa daftar sesi eksternal; bar hilang menghasilkan `DATA_INSUFFICIENT`.
- Due date shadow memakai bar sesi kelima, bukan kalender `+7`.
- Regression suite: `9 passed`.

Kandidat contract-label:
- Experiment: `EXP_SWING_CONTRACT_TARGETS_V1`.
- Dataset hash: `076b6119fbf042f53d11a7bb377f549095a0dd0b68caafb32ba7e82928d3400c`.
- Universe kandidat artifact: 10 ticker cohort eksplisit; bukan seluruh registry.
- Pipeline sekarang memiliki `discover_local_universe()` untuk menemukan seluruh file `data/cleaned/*_cleaned.csv` yang valid. Cohort 10 tetap dipakai untuk menjaga artifact `EXP_SWING_CONTRACT_TARGETS_V1` tidak berubah.
- Features: 12 EOD OHLCV.

Rekonsiliasi universe:
- Source snapshot `dashboard/storage/app/idx_registry_2026-11-20.json`: 945 unique equity tickers, snapshot `2026-11-20`.
- Manifest retrospektif `reports/SWING_OHLCV_DATASET_MANIFEST.json`: total registry `978`, complete `964`, index `1`, short listing `1`, incomplete gaps `6`, failed schema `5`, unsupported share class `1`.
- Angka `946` tidak memiliki bukti source/manifest yang ditemukan pada repository. Status: `NOT VERIFIED`.
- `964 complete` berarti registry internal berstatus `backfill_status=COMPLETE`; belum berarti seluruh 964 pasangan raw+adjusted, seluruh bar valid, dan seluruh label eligible.
- Alasan exclusions tersimpan sebagai bucket manifest: `WARMUP_MA50`, `ZERO_VOLUME`, `CORRUPT_PRICE`, `FORWARD_TARGET_UNAVAILABLE_OR_IMMATURE`, `PURGED_AT_SPLIT_BOUNDARY`.
- Source 945 dan manifest 978 berbeda snapshot/lingkup. Tidak boleh dijumlahkan atau dipakai sebagai denominator tunggal.
- Manifest retrospektif menyebut source IDX Edge PRO, board `RG`, dan adjustment provider back-adjusted. CSV lokal yang benar-benar dipakai hanya bertanda `unadjusted_nominal`; hubungan feature adjusted dan execution nominal belum terbukti.
- Tidak ada fetch tambahan dilakukan.

Defect universe fixed: training discovery tidak lagi bergantung pada daftar 10 ticker secara default. Artifact kandidat tetap cohort 10 agar hasil exposed benchmark dan verdict tidak berubah.

Diagnosis kandidat:
- TP6: sinyal sedikit; precision `36.3636%`, coverage `1.3136%`, date-block CI `22.4199%–46.7949%` pada 52 hari terpilih.
- TP15: precision `10%`, coverage `4.2990%`, date-block CI `4.9517%–13.4088%` pada 138 hari terpilih.
- TP35: precision `0%`; tidak ada sukses pada kedua kandidat.
- Masalah utama: label TP15/TP35 sangat jarang, kalibrasi/ranking tidak stabil, dan sampel TP6 terpilih kecil. Data coverage dan target rarity lebih dominan daripada kekurangan fitur tambahan yang belum point-in-time.
- Baseline arah return lama tidak dibandingkan langsung dengan target-hit precision.

Eksperimen berikutnya wajib dibekukan sebelum training: gunakan registry snapshot tunggal, validasi raw+adjusted pair per ticker, kontrak calendar canonical, kandidat label TP terpisah, threshold dari validation, date-block bootstrap, dan budget maksimal 2 model x 3 target x 1 training run. Broker/fundamental hanya boleh ditambah jika histori point-in-time tersedia.

Shadow provenance tambahan:
- Setiap record wajib mempertahankan `prediction_id`, `ticker`, `decision_timestamp`, `model_version`, `feature_schema_hash`, `reference_close`, threshold policy, dan original probabilities.
- Evaluator result wajib menyimpan evaluator version, contract, status, exit date/price, fee/slippage, ambiguous flag, dan data sufficiency.
- Tidak ada probabilitas retroaktif.

Production gate tetap `REJECTED / NOT AUTHORIZED`.

- Features: 12 EOD OHLCV.
- Seed: `42`.
- Split: train `7230`, validation `2269`, test `4187`.
- TP6 Logistic Regression: 55 sinyal, coverage `1.3136%`, precision `36.3636%`, ROC-AUC `0.678328`, successes `20`.
- TP15 Logistic Regression: 180 sinyal, coverage `4.2990%`, precision `10.0000%`, ROC-AUC `0.680799`, successes `18`.
- TP35: Logistic Regression `0` success; HistGradientBoosting `0` success.
- Tidak ada kandidat layak promosi.
- Test set sudah exposed benchmark; bukan untouched holdout.

Shadow ledger:
- 20 record lama dievaluasi melalui owner existing.
- Prediksi historis tidak ditulis ulang.
- Hasil `PENDING/NO_SIGNAL` bukan bukti performa.

Intraday tetap `PENDING_DATA`: manifest hanya mencakup `2026-07-21` sampai `2026-10-14`, interval `15m`.

Final gate tetap `REJECTED / NOT AUTHORIZED`. ML production, Telegram publication, dan broker order tetap nonaktif.

## 8. Audit final: return, universe, eksperimen, dan live metadata

### 8.1 Return dan drawdown

Defect ditemukan pada `_contract_summary()` lama: seluruh net return keputusan dikalikan berurutan tanpa modal, cash lockup, posisi bersamaan, lot sizing, atau rejection. Nilai `-100%` dan `100%` dari jalur itu adalah `trade_series_compounded_return_pct` dan `trade_series_max_drawdown_pct`, bukan `portfolio_return_pct`.

Perbaikan:
- Statistik rangkaian diberi nama eksplisit `trade_series_*`.
- Metrik portfolio memakai `DailyPortfolioSimulator` existing.
- Simulator memakai modal awal `500000`, max `5` posisi, alokasi `20%`, lot BEI `100` saham, cash constraint, fee `0.15%/0.25%`, adverse tick slippage, dan mark-to-market.
- `portfolio_return_pct`, `max_portfolio_drawdown_pct`, `final_mtm_equity`, rejection, executed trades, dan fee dilaporkan terpisah.
- Regression baru: `quant-engine/tests/test_ml_swing_contract_experiment.py`.

Hasil test kandidat:
- TP6 Logistic Regression: 55 signal, 35 executed, mean net trade `-1.296626%`, portfolio return `-28.96%`, max drawdown `43.06%`.
- TP6 HistGradientBoosting: 18 signal, 15 executed, mean net trade `-2.402766%`, portfolio return `-18.97%`, max drawdown `27.36%`.
- TP15 Logistic Regression: 180 signal, 84 executed, mean net trade `-1.093937%`, portfolio return `-51.21%`, max drawdown `61.46%`.
- TP15 HistGradientBoosting: 26 signal, 17 executed, mean net trade `-4.674561%`, portfolio return `-18.40%`, max drawdown `18.40%`.
- TP35 Logistic Regression: 486 signal, 82 executed, mean net trade `-2.086775%`, portfolio return `-76.83%`, max drawdown `76.91%`.
- TP35 HistGradientBoosting: 9 signal, 4 executed, mean net trade `-1.820422%`, portfolio return `-5.21%`, max drawdown `5.98%`.

Semua kandidat tetap belum memenuhi kriteria promosi produksi. Portfolio simulator tidak mengubah verdict.

### 8.2 Dataset aktual yang dipakai

- Registry source jelas: `945` unique ticker, snapshot `2026-11-20`.
- Angka `946`: `NOT_VERIFIED`, tidak dipakai sebagai denominator.
- Dataset kandidat: 10 ticker cohort, bukan registry penuh.
- File eligible aktual: 10 CSV lokal.
- Per ticker: 1389–1403 bar, rentang `2021-01-04` sampai `2026-11-02`.
- Semua schema required tersedia: `bar_date`, `open_price`, `high_price`, `low_price`, `close_price`.
- Duplikat tanggal: `0` per ticker.
- Null pada OHLCV: `0` per ticker.
- Adjustment lokal: `unadjusted_nominal`.
- Source column lokal tidak tersedia.
- Generator lokal `services/data_quality_pipeline.py` default provider `yahoo_finance`, sedangkan manifest retrospektif menyebut `IDX Edge PRO`; status source: `SOURCE_CONFLICT / NOT VERIFIED`.
- Manifest menyatakan board `RG`, adjustment feature `PROVIDER_BACK_ADJUSTED`, validation `VALID`; CSV aktual tetap hanya `unadjusted_nominal`.
- Fitur dan execution level belum memakai basis yang sama secara terbukti untuk seluruh dataset. Ini limitation data/provenance, bukan software defect.
- Canonical raw/adjusted pair per ticker belum terbukti untuk seluruh cohort.
- Label horizon matang: split candidate menghasilkan train `7230`, validation `2269`, test `4187`; `insufficient_data=0` pada test candidate.

Manifest 978 tetap retrospective registry scope. Tidak dicampur dengan source snapshot 945.

### 8.3 Eksperimen eksploratif

Budget dipatuhi: 2 model x 3 target x 1 run. Threshold dipilih dari validation dan dibekukan sebelum test. Test exposed, bukan untouched holdout. Hasil diberi status eksploratif.

Per target, test conservative hit rate memakai seluruh confirmed entry dan mempertahankan `AMBIGUOUS` dalam denominator evaluator. Selected-signal metrics memakai subset model; portfolio metrics memakai subset yang benar-benar dieksekusi simulator. `EXPIRED`, `SL`, `SL_GAP`, `TP`, `TP_GAP`, `AMBIGUOUS`, dan `DATA_INSUFFICIENT` tetap dipisahkan.

Baseline paired target-hit selesai tanpa training baru. Baseline memakai seluruh 4187 keputusan test, kontrak TP/SL/expiry/fee/slippage yang sama, dan `DailyPortfolioSimulator` yang sama.

| Target | Baseline executed | Rejection | Rejection reasons | Portfolio return | MTM max drawdown |
|---|---:|---:|---|---:|---:|
| TP6 | 160 | 4027 | INSUFFICIENT_FUNDS 3929; ALREADY_OPEN 98 | -94.92% | 95.51% |
| TP15 | 138 | 4049 | INSUFFICIENT_FUNDS 3982; ALREADY_OPEN 67 | -94.98% | 95.58% |
| TP35 | 136 | 4051 | INSUFFICIENT_FUNDS 3984; ALREADY_OPEN 67 | -94.97% | 95.57% |

Baseline dan kandidat sama-sama exploratory exposed benchmark. Perbandingan langsung tetap terbatas: `evaluate_swing()` sudah menambahkan entry tick, lalu `DailyPortfolioSimulator` menambahkan entry tick lagi saat membuka posisi. Kontrak execution belum identik secara implementasi. Jangan gunakan perbandingan ini untuk klaim model unggul.

### 8.4 Gemini live request

Request baru melalui launcher resmi dan worker existing:
- Request: `REQ_20261122_202938_BRIS_vUGD`.
- Evidence: `218`.
- Analysis: `179`.
- Status: `completed`.
- Duration: `70283 ms`.
- AI latency: `60031 ms`.
- Requested model: `ag/gemini-3.8-flash-high`.
- Returned model: `null`.
- Correlation ID: `AI_20261122_133017_6b2df4aa`.
- HTTP status: `null`.
- Error: `cURL error 28: Operation timed out after 60012 milliseconds with 0 bytes received`.
- Exception: `Illuminate\\Http\\Client\\ConnectionException`.
- Previous exception: `GuzzleHttp\\Exception\\NetworkTimeoutException`.
- Request bytes: `17486`.
- Input chars: `15686`.
- Output bytes: `0`.
- Fallback: `STRUCTURED_FALLBACK` / `INFERENCE_UNAVAILABLE`.
- `fully_verified=false`.
- `completion_scope=PARTIAL_COMPONENTS`.

Metadata bertahan pada request baru. Gemini tetap `PENDING`; timeout upstream cause tetap `CAUSE_NOT_VERIFIED`.

### 8.5 Shadow dan current-bar receipt

Shadow ledger tetap immutable. Record baru harus menyimpan model hash, feature schema hash, snapshot, cutoff, threshold policy, contract version, dan due date sesi kelima sebelum outcome.

Ledger saat ini memiliki model version dan feature schema hash, serta record baru menyimpan `contract_version` dan `threshold_policy`. Current-bar inference receipt dan artifact-load receipt belum ditemukan pada jalur shadow existing. Karena itu tidak ada kandidat shadow baru yang dipublikasikan atau diperlakukan sebagai produksi.

Status evidence:
- Software defect: return label/statistics diperbaiki; metadata Gemini persistence diperbaiki.
- Data limitation: raw/adjusted mapping cohort penuh, source column lokal, registry 946, canonical calendar, current-bar receipt.
- Candidate result: seluruh selected portfolio return negatif; belum memenuhi kriteria promosi produksi.
- Forward evidence: shadow outcome baru belum matang dan current-bar receipt belum terbukti.

Verifikasi final:
- `10 passed` untuk regression evaluator, portfolio, dan Tahap G.
- `9 passed` evaluator/quality gate sebelumnya.
- `37 passed, 161 assertions` Laravel provenance/recovery suite.
- Python compile lulus.
- PHP lint lulus.
- `git diff --check` lulus.

Training offline diotorisasi dan selesai. Produksi tetap menunggu bukti kelayakan.
No Telegram send, broker order, scheduler mutation, worker restart, Outbox `#5` replay, commit, atau push dilakukan.

## 9. Kebutuhan masa depan

1. Simpan immutable raw dan adjusted payload hash untuk setiap cohort ticker.
2. Buktikan mapping adjusted feature ke raw execution level dengan corporate-action transform.
3. Tambah canonical IDX session provider.
4. Tambah current-bar inference receipt dan artifact hash pada shadow owner.
5. Buat untouched forward shadow cohort setelah cutoff baru.
6. Jalankan baseline target-hit dengan execution contract identik.
7. Jangan promosi sebelum portfolio metrics, uncertainty, artifact receipt, current-bar proof, dan mature shadow outcome lulus bersama.

Production status: `RESEARCH_ONLY / NOT_PROMOTED`. Kandidat belum memenuhi kriteria promosi produksi.

## 10. Execution correction V2

Perbaikan kontrak selesai tanpa retraining dan tanpa threshold baru.

- Evaluator hanya menghasilkan harga referensi yang dibulatkan ke tick.
- `DailyPortfolioSimulator` menjadi satu-satunya pemilik entry slippage, exit slippage, fee, cash debit, dan cash credit.
- Entry adverse slippage diterapkan sekali.
- Exit adverse slippage diterapkan sekali untuk stop, ambiguous, dan expiry.
- Fee tetap `0.15%` buy dan `0.25%` sell.
- Artifact lama, split, threshold, seed, dan dataset hash tetap dipakai.

Regression numerik membuktikan:

- Harga referensi `1000`, entry fill `1005`.
- Entry cost `1005 * 100 * 1.0015`.
- Stop reference `950`, stop fill `945`.
- Sell fee `945 * 100 * 0.0025`.
- Focused contract tests: `6 passed`.

Hasil evaluasi frozen V2:

| Target | Model | Signals | Executed | Rejected | Rejection reasons | Mean net trade | Portfolio return | MTM max drawdown |
|---|---|---:|---:|---:|---|---:|---:|---:|
| TP6 | Logistic Regression | 55 | 36 | 18 | INSUFFICIENT_FUNDS 4; ALREADY_OPEN 14 | -1.239201% | -19.47% | 37.39% |
| TP6 | HistGradientBoosting | 18 | 12 | 5 | ALREADY_OPEN 5 | -2.681704% | -5.60% | 13.85% |
| TP15 | Logistic Regression | 180 | 98 | 81 | ALREADY_OPEN 66; INSUFFICIENT_FUNDS 15 | -1.014507% | -41.64% | 54.38% |
| TP15 | HistGradientBoosting | 26 | 18 | 8 | ALREADY_OPEN 8 | -3.359799% | -7.83% | 10.82% |
| TP35 | Logistic Regression | 486 | 104 | 382 | INSUFFICIENT_FUNDS 317; ALREADY_OPEN 65 | -2.134617% | -69.25% | 71.41% |
| TP35 | HistGradientBoosting | 9 | 4 | 5 | ALREADY_OPEN 1; INSUFFICIENT_FUNDS 4 | -1.869827% | -4.01% | 5.38% |

Selected signals tetap lebih sedikit dari baseline all-signal. Portfolio return tidak membuktikan kualitas ranking model, karena baseline mengambil lebih banyak peluang dan mengalami exposure berbeda.

Starting cash V2 tetap `500000`. Simulator melepaskan cash saat `close_position`, menolak ticker yang masih open dengan `ALREADY_OPEN`, dan menolak lot pertama yang melewati cash dengan `INSUFFICIENT_FUNDS`. Event tie-break memproses exit sebelum entry pada timestamp sama. Perilaku ini sesuai kontrak, bukan bug sizing.

V2 artifact:

`reports/ml/swing_contract_candidate_results_execution_corrected_v2.json`

V1 lama dipertahankan sebagai bukti historis.

### 10.1 Boundary router terbaru

Effective application configuration tetap:

- Endpoint: `http://127.0.0.1:20128/v1/chat/completions`.
- Requested model: `ag/gemini-3.8-flash-high`.
- Consultation timeout: `60` seconds.
- EOD ML inference: disabled.
- On-demand consultation: enabled.

Application telemetry now records correlation ID, timestamp, model, duration, HTTP status, payload sizes, connect timing, first-byte timing, returned model, exception class, and sanitized error.

Last proven boundary remains application HTTP client:

- Correlation ID: `AI_20261122_133017_6b2df4aa`.
- `connect_ms=1`.
- `first_byte_ms=0`.
- `output_bytes=0`.
- `http_status=null`.
- `cURL error 28` after `60012 ms`.

No router ingress or upstream dispatch receipt with matching correlation ID exists. Do not classify upstream cause beyond `CAUSE_NOT_VERIFIED`.

### 10.2 Artifact contract

Read-only pickle load succeeded for six artifacts. Hash receipts remain in `reports/ml/shadow_artifact_load_audit.json`.

V2 confirms model classes accept twelve feature columns after train-fitted median imputation and `StandardScaler`. This verifies load and preprocessing compatibility for retrospective evaluation only.

It does not prove current-bar inference. Pickles do not embed feature schema hash. Current-bar receipt, immutable input snapshot, and untouched forward holdout remain blockers.

### 10.3 Signal reconciliation V4

V4 merekonstruksi keputusan dari frozen model pickle, frozen preprocessing, frozen split, frozen threshold, dan evaluator versioned. Tidak ada training atau threshold selection baru. V1/V2 performance headlines superseded.

Output: `reports/ml/swing_contract_candidate_results_execution_corrected_v4.json`.

Simulator menyimpan `decision_id` pada entry, position, dan exit. Exit mismatch tidak menutup posisi lain. Entry-rejected exit dicatat `ENTRY_WAS_REJECTED`, bukan `UNPROCESSED_EXIT_EVENT`. Duplicate exit, ownership mismatch, missing position, dan open-at-end dipisah.

V4 reconciliation:

- TP6 Logistic: `55 = 44 EXECUTED_CLOSED + 0 OPEN + 11 REJECTED`.
- TP6 HistGradientBoosting: `18 = 16 EXECUTED_CLOSED + 0 OPEN + 2 REJECTED`.
- TP15 Logistic: `180 = 77 EXECUTED_CLOSED + 0 OPEN + 103 REJECTED`.
- TP15 HistGradientBoosting: `26 = 18 EXECUTED_CLOSED + 0 OPEN + 8 REJECTED`.
- TP35 Logistic: `486 = 98 EXECUTED_CLOSED + 0 OPEN + 388 REJECTED`.
- TP35 HistGradientBoosting: `9 = 3 EXECUTED_CLOSED + 0 OPEN + 6 REJECTED`.

Every V4 decision has one ledger record. `unprocessed_exit_events=0` for every candidate. Rejection reasons remain separate: `ALREADY_OPEN` and `INSUFFICIENT_FUNDS`.

Portfolio reconciliation:

- TP6 Logistic: ending MTM `376926.95`, return `-24.61%`, realized net P&L `-123073.05`, fees `24773.05`.
- TP6 HistGradientBoosting: ending MTM `445594.50`, return `-10.88%`, realized net P&L `-54405.50`, fees `9405.50`.
- TP15 Logistic: ending MTM `329710.75`, return `-34.06%`, realized net P&L `-170289.25`, fees `42789.25`.
- TP15 HistGradientBoosting: ending MTM `440939.60`, return `-11.81%`, realized net P&L `-59060.40`, fees `8660.40`.
- TP35 Logistic: ending MTM `205641.25`, return `-58.87%`, realized net P&L `-294358.75`, fees `69858.75`.
- TP35 HistGradientBoosting: ending MTM `506679.00`, return `+1.34%`, realized net P&L `6679.00`, fees `3321.00`.

Planned price risk uses rounded reference entry as denominator. V4 assertion passed for every decision: maximum planned risk `5.0%`. Realized gap/slippage loss and net loss after fees remain separate. This does not guarantee realized loss stays below `5%`.

### 10.4 Provenance dan calendar

Generator lokal ditemukan di `services/data_quality_pipeline.py`. Default provider di generator adalah `yahoo_finance`, tetapi default bukan bukti source aktual seluruh file. Manifest retrospektif menyebut `IDX Edge PRO`. Status source tetap `SOURCE_CONFLICT / NOT VERIFIED`.

CSV menyimpan `unadjusted_nominal`; tidak menyimpan raw payload hash, adjusted companion ID, corporate-action transform, atau per-bar source. Tidak ada bukti lokal cukup untuk memilih Yahoo atau IDX Edge sebagai source aktual. Tidak ada fetch tambahan dilakukan.

`dashboard/app/Services/BeiCalendarService.php` hanya memuat weekend dan daftar libur 2026. Itu tidak memvalidasi rentang dataset 2021–2026. Missing bar tetap bukan holiday. Status canonical session coverage: `NOT VERIFIED`.

### 10.5 Artifact loader dan router

Schema manifest versioned:

`quant-engine/models/contract_artifacts/EXP_SWING_CONTRACT_TARGETS_V1_schema_v2.json`

Loader riset fail-closed memverifikasi artifact SHA-256, schema hash, feature order, feature type, median train-only, dan `StandardScaler` train-only. Negative tests menolak `ARTIFACT_HASH_MISMATCH` dan `FEATURE_SCHEMA_MISMATCH`. Pickle tidak diubah. Loader belum diaktifkan untuk production inference.

Port `20128` terbukti listening pada PID `13260`. Application sends `X-Aether-Correlation-ID` dan menyimpan telemetry. Router ingress log dengan correlation ID matching belum ditemukan. Boundary terakhir tetap application HTTP client: `AI_20261122_133017_6b2df4aa`, `connect_ms=1`, `first_byte_ms=0`, `output_bytes=0`, `http_status=null`, `cURL error 28` setelah `60012 ms`.

### 10.6 Future-data blockers

- Canonical per-bar session membership 2021–2026.
- Raw/adjusted pair dan corporate-action transform evidence.
- Router ingress/upstream receipt.
- Router ingress/upstream receipt.
- Current-bar shadow receipt.
- Untouched forward holdout.
- Mature shadow outcomes.

### 10.7 Dataset provenance gate V1

Manifest: `reports/ml/dataset_manifest_idx_edge_bbca_v1.json`.

Manifest SHA-256: `b9997bf213d903acf89567c050c7171971e721dd8b357257e35e83aee1208143`.

Cohort eksplisit: `BBCA`.

- Source: `IDX Edge PRO`, dibuktikan oleh `market_observations.source`, `source_url`, raw archive path, dan raw payload hash pada latest paired fetch.
- Endpoint: `https://stock.arjum.com/api/history/BBCA`.
- Parameters: daily, limit `500`, raw `adjusted=false`, adjusted companion `adjusted=true`.
- Observed bars: `2024-10-08..2026-11-20`.
- Raw rows: `501`.
- Adjusted rows: `501`.
- Paired dates: `501`.
- Immutable archive-complete rows: `2`; full archive completeness belum terbukti.
- Sample fetched_at: `2026-11-21 10:27:18`.
- Raw sample hash: `3954d5cd477613cfc3e1b2f9c637b607080466b531d7175828a45880902a8060`.
- Adjusted sample hash: `e2265948d2bc31270882d466b8faf946848aeaab249173784e04c1c124b09539`.

Features wajib memakai `PROVIDER_BACK_ADJUSTED` setelah pairing. Execution dan target label wajib memakai `UNADJUSTED`. Selisih raw/adjusted tidak membuktikan corporate action tertentu.

Status cohort: `ELIGIBLE_FOR_EXPLORATORY_RESEARCH`, bukan training-ready. Kalender full `2024–2026` belum verified karena service existing hanya memuat holiday `2026`. Missing bar tetap missing.

### 10.8 Dataset linkage V2 dan ML gate

Manifest diperbarui: `reports/ml/dataset_manifest_idx_edge_bbca_v1.json`.

Manifest SHA-256 terbaru: `a177595b958243befb19c607e42a8c74029b8a38b9175c3f72b63676e211e16f`.

BBCA provenance read-back:

- DB raw valid RG rows: `501`; linked archive rows: `501`.
- DB adjusted valid RG rows: `501`; linked archive rows: `501`.
- Payload besar raw: `500` unique candles, `2024-10-16..2026-11-20`.
- Payload besar adjusted: `500` unique candles, tanggal identik dengan raw.
- Gap refresh raw: `6` candles, `2024-10-08`, `2024-10-09`, `2024-10-10`, `2024-10-11`, `2024-10-14`, `2024-10-15`.
- Gap refresh adjusted: `6` candles pada tanggal sama.
- `2024-10-12` dan `2024-10-13` tidak memiliki candle provider; keduanya weekend dates, bukan asumsi kalender.
- Raw gap payload hash: `f7666aeb4adfc489820c06568c2187a42a2984081632201866b53826b9f767f2`.
- Adjusted gap payload hash: `16735b7c39dc18432007319fe6af062d325891e1568f0d190b1f5f35a04da2b2`.
- Raw gap fetched_at: `2026-11-23T09:02:40+00:00`.
- Adjusted gap fetched_at: `2026-11-23T09:03:44+00:00`.
- Existing 500-row payload hashes tetap immutable.
- Payload raw/adjusted yang diperiksa memiliki tanggal identik dan OHLC identik pada `506` archived dates. Ini tidak membuktikan corporate-action absence atau adjustment semantics.

Linkage memakai satu payload untuk banyak candle. DB rows hanya menerima path/hash payload yang benar. Tidak ada fetched_at historis dibuat ulang.

Registry read-back:

- `idx_company_registries`: `945` unique `EQUITY` tickers.
- Angka eligible universe `947` tidak terbukti dari query registry ini.
- `945` registry snapshot dan `947` eligible universe tidak boleh digabung.
- BBCA tetap satu ticker exploratory cohort, bukan model seluruh emiten.

Leakage gate:

- V4 artifact loader receipt: `6` artifacts, seluruh hash SHA-256 format valid.
- V4: `training_repeated=false`; threshold tetap frozen validation-selected.
- Split existing: train `7230`, validation `2269`, test `4187`; purge gap diterapkan.
- Feature pipeline memakai legacy local CSV `data/cleaned`, bukan manifest IDX Edge BBCA.
- Dataset hash frozen V4 tetap `076b6119fbf042f53d11a7bb377f549095a0dd0b68caafb32ba7e82928d3400c`.
- Tidak ada training baru. Tidak ada test retuning.

Keputusan: eksperimen IDX Edge BBCA belum dimulai. Kalender canonical BEI 2024–2026 belum tersedia; fitur adjusted dan raw execution alignment belum terbukti untuk cohort training; artifact V4 legacy tidak boleh dipasangkan diam-diam dengan manifest baru. Status kandidat tetap exploratory/shadow-only dan produksi `RESEARCH_ONLY / NOT_PROMOTED`.

### 10.9 Kalender BEI V1 dan konflik tanggal

Calendar owner tetap `BeiCalendarService`; Python `calendar_utils.py` memakai versi sama: `BEI_OFFICIAL_HOLIDAY_2024_2026_V1`.

Manifest sumber: `dashboard/storage/app/calendar_sources/bei_calendar_sources_2024_2026.json`.

Sumber official IDX yang ditemukan:

- 2024: `Peng-00233/BEI.POP/11-2024`, revisi Kalender Libur Bursa 2024.
- 2025: `Peng-00213/BEI.POP/10-2024`, Kalender Libur Bursa 2025.
- 2026: `Peng-00171/BEI.POP/09-2025`, Kalender Libur Bursa 2026.

Tanggal pengambilan: `2026-11-23T09:30:14Z`.

Direct PDF fetch mengembalikan `HTTP 403`; SHA-256 PDF tidak diklaim. Search index IDX memuat isi dan tanggal resmi. Source status: `INDEX_VERIFIED_PDF_DIRECT_FETCH_403`.

Kalender sudah mengintegrasikan holiday dates 2024–2026. Tidak ada candle sintetis.

Rekonsiliasi BBCA:

- Unique archived dates: `506` (`500 + 6`).
- DB paired dates: `501`.
- Raw payload rows: `506`.
- Adjusted payload rows: `506`.
- Semua payload bars memiliki field OHLC wajib.
- `2024-10-12` dan `2024-10-13`: provider tidak mengembalikan bar; status `WEEKEND_NOT_TRADING` berdasarkan weekday.
- `2026-08-26`: provider raw dan adjusted memiliki bar valid, tetapi kalender IDX mencantumkan `2026-08-25` sebagai Maulid Nabi. Status `SOURCE_CONFLICT / NOT VERIFIED`.
- Training-eligible archived rows: `506`, subject to five-session label maturity.
- Excluded dates: none from calendar conflict.
- `2026-08-25`: official Maulid Nabi holiday.
- `2026-08-26`: original raw and adjusted payload bar retained.
- Pilot rows: train `275`, validation `112`, test `93`.
- TP35: `DATA_INSUFFICIENT_TRAIN_CLASS`, train labels all `0`.

Keputusan: exclusion `2026-08-26` dibatalkan. `2026-08-25` adalah libur resmi; `2026-08-26` tetap dipakai. Pilot single-ticker selesai terpisah dari V4. Status dataset `SINGLE_TICKER_EXPLORATORY_PILOT_COMPLETED`; bukan model seluruh emiten.

### 10.10 Gemini acceptance BBCA baru

Request dibuat melalui writer existing, bukan replay:

- Request ID: `REQ_20261123_163323_BBCA_ZG3t`.
- DB row: `55`.
- Status: `completed`.
- Evidence ID: `222`.
- Analysis ID: `181`.
- Evidence date: `2026-11-20`.
- Source: `IDX Edge PRO`.
- Raw payload hash: `98d4365196f9cb631ddbe4622b684a8fdb42abc37e635be3b8d0addc2673a338`.
- Adjusted payload hash: `1a22870f2b393ecc374072aa1a1f5e5633024caa6ce7dbe45c6d5d9cd2058acd`.
- Requested model: `ag/gemini-3.8-flash-high`.
- Returned model: `gemini-3.8-flash-n`.
- HTTP status: `200`.
- Correlation ID: `AI_20261123_093414_8aec08eb`.
- Duration: `29873 ms`; first byte `29870 ms`; output `1832 bytes`; AI latency `29904 ms`.
- Inference telemetry usage: `12319` total tokens, `11814` prompt, `505` completion.
- `fully_verified=false`; verification status `PARTIAL_NOT_FULLY_VERIFIED`.
- Broker refresh: `FETCH_FAILED`, provider rate limit, no silent retry.
- News refresh: `NOT_FETCHED`, authenticated provider news contract unavailable.
- `telegram_queued=false`.

Inference result is stored and reloadable through `research-request-status`. Evidence and analysis IDs are present. No provider re-inference was used for verification.

Router receipt currently proves app request/response boundary only. Upstream dispatch mapping remains separate and unverified. HTTP `200` plus stored analysis proves this acceptance returned usable inference, not production model promotion.

### 10.11 Shadow inference status

No current-bar ML shadow receipt exists for BBCA. Existing source shadow mechanisms are not proof of execution. No legacy artifact was paired with new IDX Edge features. Status: `NO_ACTIVE_INFERENCE_FOR_BAR`.

### 10.12 Gemini router boundary

Process serving port `20128` terbukti `node.exe ... 9router/app/custom-server.js`, PID `13260`.

Router SQLite `requestDetails` saat audit berisi `0` rows. `usageHistory` berisi provider usage, tetapi tidak menyimpan correlation ID ingress atau upstream dispatch yang dapat menghubungkan request aplikasi. Router source yang berjalan tidak memiliki logging minimum untuk `X-Aether-Correlation-ID` pada HTTP app server `20128`.

MITM debug logger yang tersedia berada pada fasilitas proxy HTTPS port `443`, bukan server app port `20128`; tidak membuktikan request Aether. Tidak ada patch global atau restart dilakukan. Tidak ada acceptance kedua dijalankan.

Boundary terakhir tetap application HTTP client:

- correlation: `AI_20261122_133017_6b2df4aa`;
- `connect_ms=1`;
- `first_byte_ms=0`;
- `output_bytes=0`;
- `http_status=null`;
- `cURL error 28` setelah `60012 ms`;
- returned model `null`.

Perbaikan berikutnya membutuhkan patch source router app server yang benar, receipt sink tersanitasi, pemeriksaan request aktif, lalu satu restart terkontrol router saja. Worker, dashboard, collector, scheduler, dan receiver tidak boleh direstart.

Produksi tetap `RESEARCH_ONLY / NOT_PROMOTED`.


## Historical addendum

- Historical request `REQ_20261122_185722_BRIS_DSw4`, Evidence `217`, Analysis `178` tidak ditulis ulang.
- Metadata correction berlaku untuk request baru dan jalur berikutnya.
