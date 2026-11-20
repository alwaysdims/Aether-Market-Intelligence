# Laporan Penyelesaian V1 — Aether Market Intelligence

Tanggal pemeriksaan akhir: 13 November 2026, 10:55 WIB (SEAST)
Ruang lingkup: `E:\Aether-Market-Intelligence` saja. `E:\aether-v2` tidak disentuh.

## Addendum — ASII ML Truth and Consultation Model Fix 19 November 2026

Status: FIXED FOR FUTURE RESULTS; model 3.6 acceptance BLOCKED by provider availability.

- ASII `REQ_20261119_081042_ASII_4YMC` / Evidence `#190` preserved unchanged.
- Root cause: `BeiUniverseRegistry::VALIDATED_ML_UNIVERSE` was a ticker allowlist, but downstream code treated membership as proof of production model artifact, inference receipt, and valid probabilities. No production model registry or artifact/inference receipt was found.
- Fix: production ML proof now requires `hasProductionMlInference()`. It returns false until artifact and inference receipt exist. Future dashboard and Telegram results show `N/A`, `NO_ACTIVE_INFERENCE_FOR_BAR`, or `MODEL_NOT_VALIDATED_FOR_INSTRUMENT`; legacy RandomForest values are not reused.
- Fundamental status now reads actual component snapshots. Available `INCOME_STATEMENT`, `BALANCE_SHEET`, and `CASH_FLOW_REPORT` data cannot become “not fetched” through stale freshness mapping.
- Future refresh metadata maps valid fetched bars to `AVAILABLE_FRESH`; `NOT_FETCHED` remains a component state, not a blanket research freshness result.
- Consultation configuration is unified under `services.consultation_ai` for dashboard on-demand and Telegram conversation paths. Target model is `gemini-3.6-flash`. Requested model, returned model, configuration source, latency, and fallback reason remain persisted.
- Provider probe: `ag/gemini-3.6-flash` through local router returned HTTP `503` with upstream `404 NOT_FOUND`; `gemini-3.6-flash` alias timed out; Google `gemini-3.6-flash` endpoint timed out. Acceptance with returned 3.6 model is BLOCKED. No 3.8 fallback was used.
- Broker helper now requests exact seven-calendar-day range: `end_date` through `end_date - 6 days`; provider session coverage remains explicit.
- Verification: `19` focused tests, `88` assertions, PASS. PHP lint and scoped `git diff --check` PASS. Blade cache PASS.
- Residual: real ASII new-request acceptance, Telegram new-message parity, and Gemini 3.6 successful inference remain BLOCKED until provider exposes a valid 3.6 model contract. Histories remain unchanged.

## Addendum — Evidence Binding and Claim Validation Fix 19 November 2026

Status: PARTIAL_COMPONENTS; Gemini LIVE_VERIFIED pada request baru. Riset AI belum FULLY_VERIFIED.

- Root cause exact: evidence selection used ticker/date latest lookup, while request persistence did not bind snapshot identity through analysis creation. Adjacent same-ticker requests could select another request's evidence. Analysis `#152` exposed this as structured evidence `#186` versus request Evidence `#187`.
- Fix: request-scoped evidence ID now flows snapshot -> prompt -> validator -> analysis -> response -> history. Request-scoped snapshot identity prevents cross-request `latest()` reuse. Adjusted and raw observations keep separate archive/hash and request IDs.
- Invariant: request evidence ID, analysis evidence ID, and result evidence ID must match before completion. Mismatch is `INTERNAL_EVIDENCE_LINK_MISMATCH`, not `completed`. Historical records are not rewritten.
- Historical warning: known legacy evidence-link mismatch is surfaced separately. It does not alter Analysis `#152` or Evidence `#187`.
- Claim fix: fundamental availability reads actual `INCOME_STATEMENT`, `BALANCE_SHEET`, and `CASH_FLOW_REPORT` snapshots. `NOT_FETCHED`, `EMPTY_RESPONSE`, and `NO_RELEVANT_ITEMS_FOUND` remain distinct. `NOT_FETCHED` is never rendered as no news.
- Regression: same-ticker request-scope test passed. Focused provenance/freshness/technical/API-first tests passed: `28` tests, `148` assertions. PHP lint and Blade cache passed. Scoped `git diff --check` passed.
- New acceptance: request `REQ_20261119_081737_UNVR_RPmE`; Evidence `#192`; Analysis `#157`; request completed through existing dispatcher; model `ag/gemini-3.8-flash-high`; no fallback; `PARTIAL_COMPONENTS`; `PARTIAL_NOT_FULLY_VERIFIED`. Raw and adjusted records both carry this request ID with separate hashes.
- Acceptance content: three fundamental reports were present as API snapshots; news was `NOT_FETCHED` with provider-contract reason; ML remained separate and inactive; no synthetic confidence/probability claim accepted; broker/insider scope remained provider-limited or unverified.
- Reload acceptance: dashboard `/market/UNVR?tab=summary&fresh=acceptance-reload` loaded two charts with Chart.js and no browser console errors. Full history-page read-back and real Telegram parity remain pending.
- Migration `2026_11_19_120000_add_technical_context_to_prediction_signals.php` remains not run. Production storage authorization and backup gate remain pending.
- Residual: authenticated news contract unavailable; ownership capability unverified; EOD natural receipt unproven; mobile gesture runtime proof incomplete; full Telegram suite previously timed out; ML remains disabled.

## Addendum — AI Research End-to-End Correction 19 November 2026

Status: PARTIAL_COMPONENTS; Gemini LIVE_VERIFIED pada request baru.

- Diagnosis BREN `REQ_20261118_235618_BREN_SvsB` / Evidence `#185`: `AiMarketAnalysisService` menggabungkan on-demand Gemini dengan `dashboard_ai.eod_inference_enabled`. ML/EOD nonaktif memaksa `NOT_RUN`, walau jalur on-demand terotorisasi pada `ai_router`.
- Fix generik: on-demand Gemini memakai flag on-demand tersendiri dan `ai_router` endpoint/model. `AI_LIVE_INFERENCE_EOD_ENABLED=false` tidak lagi memblokir riset Gemini. ML tetap tidak aktif dan tidak menghasilkan probabilitas.
- Fallback reason kini dibedakan: `TEST_ENVIRONMENT`, `DISABLED_BY_CONFIGURATION`, `AUTH_FAILED`, `RATE_LIMITED`, `TIMEOUT`, dan `INVALID_RESPONSE`.
- Collector kini mengirim snapshot aktual per komponen ke prompt Gemini. Fundamental mengambil `INCOME_STATEMENT`, `BALANCE_SHEET`, dan `CASH_FLOW_REPORT`; insider mengambil halaman provider; broker memakai hasil sync provider; news tetap `NOT_FETCHED` bila kontrak authenticated belum tersedia.
- Provenance fix: setiap endpoint memakai `payload_sha256` endpoint sendiri. Hash history canonical tidak lagi ditempel ke fundamental, insider, atau news. Adjusted canonical yang raw hash/path null tetap dinyatakan linkage belum terbukti.
- Completion scope kini `FULL_COMPONENTS` hanya jika raw+adjusted linkage, indikator, broker, tiga laporan fundamental, insider, dan news semuanya memenuhi status. Request baru menghasilkan `PARTIAL_COMPONENTS` karena news `NOT_FETCHED` dan adjusted raw linkage null.
- Acceptance UI request baru: `REQ_20261119_002226_BREN_9pCv`. Dispatcher existing memproses tanpa manual worker. Status `COMPLETED`; Evidence `#187`; Analysis `#152`; requested/returned model `ag/gemini-3.8-flash-high`; validation `valid_schema`; latency `24675 ms`; scope `PARTIAL_COMPONENTS`; verification `PARTIAL_NOT_FULLY_VERIFIED`.
- Component evidence request baru: raw history `REFRESHED_API`, adjusted history `REFRESHED_API`, three financial reports `REFRESHED_API`, insider `REFRESHED_API`, news `NOT_FETCHED`. No fallback used.
- Historical BREN request/evidence records were not edited or replayed. New request created new evidence.
- Chart correction: IHSG and ticker volume no longer label volume as `poin`; IHSG volume now says `unit provider; bukan poin` until provider volume unit is documented. Price axis remains `Poin IHSG`.
- Verification: `56/56` tests passed, `252` assertions; PHP lint passed for changed services; scoped `git diff --check` passed.
- Still open: adjusted canonical raw linkage, authenticated news, ownership schema, mobile/touch/zoom acceptance, and natural EOD rotation receipt.

## Addendum — Scope Correction 16 November 2026

Status: PARTIALLY_RECOVERED.

- Dashboard GET `/market/DEWA` live verified HTTP 200. Page shows `MODE PEMULIHAN: READER READ-ONLY`, `AVAILABLE_BUT_INSUFFICIENT_FOR_REQUEST`, local latest bar `2026-11-13`.
- Dashboard research POST now has local-origin, CSRF, session capability HMAC, ticker registry validation, active-request deduplication, fixed writer bridge, and no free shell input.
- Reader remains read-only. Research request persistence uses `scripts/launch_app_writer.php --research-request` with fixed JSON stdin and runtime role `aether_ingestion`; Telegram is not queued by this path.
- Worker ownership remains one guarded dispatcher. `aether:process-research-queue --limit=2` is registered in the existing scheduler owner. Queue claim uses PostgreSQL `FOR UPDATE SKIP LOCKED`, status transition `pending` to `collecting`, owner metadata, and backend deadline filtering.
- UI status polling persists `request_id`, continues with backoff after the initial 20 x 3-second window, and restores status after page reload. Backend `deadline_at` determines `DEADLINE_EXCEEDED`.
- Focused isolated acceptance: `33/33` tests passed, `154` assertions. PHP lint, Blade cache, writer contract, EOD semantic contract, and scoped `git diff --check` passed.
- Live POST acceptance remains `PENDING`: writer bridge reached the candidate DB but `aether_ingestion` lacked DML and sequence privileges on `public.research_requests`. Additive grant script is prepared; no admin password was requested in chat and no grant was applied during this run.
- ARTA read-back reconciled: registry `UPDATED`, raw and adjusted canonical observations both valid for `2026-11-16`, source `IDX Edge PRO`, board `RG`. Historical adjusted provider attempt remains `success=false` with empty bars and no provider error fields. ARTA must not enter canonical gap solely because that attempt failed; provider cause remains `NOT VERIFIED`.
- Shared IDX Edge client now exposes generic `price`, `history`, `broker`, `brokerAccumulation`, `financialStatements`, `insiders`, `screenerLatest`, and `movers` contracts. Insider/screener/movers remain `PROVIDER_CAPABILITY_UNVERIFIED` because provider response schemas and entitlement are not verified; flags stay disabled and no synthetic response is created.
- Existing Gemini consultation and scheduled Telegram delivery remain separate from shadow publication. This correction did not call Telegram, replay Job `#6`, retry Outbox `#5`, restart collector, enable ML, or enable strategy signals.

Operational ACL command, run locally with masked admin prompt only:

`powershell.exe -NoProfile -File E:\Aether-Market-Intelligence\scripts\grant_candidate_research_request_writer.ps1`

Required read-back: database `project_bot_recovered_20261115_022935`; role `aether_ingestion`; `research_requests` SELECT/INSERT/UPDATE true; sequence USAGE/SELECT true; DELETE/TRUNCATE false. Until this read-back exists, live dashboard E2E remains `PENDING`.

## Addendum — Recovery Closure Work 18 November 2026

### Operational recovery completed

- Dashboard persistence acceptance remains passed. Launcher and task were not changed during this closure work.
- Read-only EOD audit used current candidate DB state, not historical counts: database `project_bot_recovered_20261115_022935`; role `aether_ingestion`; `writes_executed=false`; lock owner fields null.
- EOD queue row `id=6`, target session `2026-11-17`, status `PENDING`, attempts `23`, `updated=17`, `failed=0`, `metrics_failed=0`, `backlog_count=3`; lock owner and lease token are null, with heartbeat `2026-11-17 17:06:18`.
- Current canonical coverage read-back: eligible `499`; complete raw+adjusted `454`; raw-only `0`; adjusted-only `0`; neither `45`; conflicted/invalid `0`. Metrics match `451/454`; three complete ticker pairs lack strict metric match.
- Full gap classification from current candidate DB: `NEW` = 42 backlog internal eligible (`TIFA,TINS,TKIM,TLDN,TLKM,TMAS,TOBA,TOTL,TOTO,TOWR,TPIA,TPMA,TRAM,TRGU,TRIM,TRIO,TRST,TRUK,TSPC,TUGU,UANG,UCID,ULTJ,UNIC,UNSP,UNTR,UNVR,VICI,VICO,VISI,VKTR,VOKS,WBSA,WIFI,WIIM,WIKA,WINS,WOMF,WOOD,WSKT,YULE,YUPI`); `EXHAUSTED` = 2 provider residuals (`SRIL` attempts `9`, last attempt `2026-11-17 17:05:13+07`, provider bar last `2026-11-09`; `SUGI` attempts `8`, last attempt `2026-11-17 17:05:23+07`, provider bar last `2026-11-09`); `PENDING_LOCAL_LIMITER` = 1 (`TDPM` attempts `7`, last attempt `2026-11-17 17:06:13+07`, `LOCAL_RATE_LIMIT`, no provider HTTP response). `DUE`, `DEFERRED`, and `INTERNAL_FAILURE` = `0`.
- `NEW` rows are internal backlog with no prior target-session attempt. Existing owner selects backlog automatically, then processes queued older sessions after regular session completion. Regular target remains authoritative; old backlog does not replace it.
- Metric reconciliation: `BACH`, `JECX`, and `RANS` have valid partial indicators with `SMA200=INSUFFICIENT_INPUT`; this is expected input insufficiency, not metric defect. Dates, source `IDX Edge PRO`, board `RG`, adjustment `PROVIDER_BACK_ADJUSTED`, and formulas match.
- Recovery test fixture corrected to exact annual financial-statement query and isolated budget `6000`. Fundamental case now passes `1/1`, `14` assertions; full `BrokerSummaryAndFundamentalIngestionTest` passes `5/5`, `50` assertions.
- Natural receipt `20261118_000504_2c3bf06970254c7aa1dbc6dde521e04b` / child `20261118_000505auto_3602bfadeb90` proved target/resolved session `2026-11-17`, trigger `TASK_SCHEDULER_EOD`, selected `20`, updated `17`, waiting `3`, failed `0`, remaining `45`, semantic `WAITING`, exit `75`, lock released.
- Current retry evidence distinguishes provider waiting from internal failure. `SRIL` and `SUGI` returned HTTP `200` older bars ending `2026-11-09`, with no provider error; classify as provider session-data residual, not internal failure. `TDPM` returned `LOCAL_RATE_LIMIT` with no HTTP response. Client checks local RPM before quota reservation, so this event did not consume provider quota; queue execution and ticker retry counters remain separate. No counter reset or forced fetch performed.
- `BOARD_SCOPE_UNVERIFIED` and `EVENT_TIME_UNVERIFIED` remain. IHSG remains `EVENT_DATE_UNVERIFIED`; no local timestamp was used as event date.

### Riset AI acceptance

- One new UI request completed: `REQ_20261118_111556_BBCA_FKsO`.
- Existing dispatcher path processed request into persisted analysis `#149`; page displayed ticker `BBCA`, bar date `2026-11-17`, source `IDX Edge PRO`, `STRUCTURED_FALLBACK`, and `NO_ACTIVE_INFERENCE_FOR_BAR`.
- Global AI History showed matching BBCA run `ONDEMAND_FALLBACK_BBCA_20261118_041606009`.
- BBCA detail history remained identical after reload. No reinference occurred.
- Evidence state was shown as stored evidence. Model ML remained `MODEL_NOT_VALIDATED_FOR_INSTRUMENT`; no buy/sell signal was emitted.
- Defect fixed: UI labels incorrectly named Gemini for generic/fallback analysis. Labels now say `Histori Analisis AI`, `Kesimpulan AI`, and `Narasi AI`. `STRUCTURED_FALLBACK` model provenance remains unchanged.
- Verification: Blade cache passed; focused suite passed `41/41` tests and `192` assertions. Browser reload confirmed corrected label and unchanged fallback result.

### Open internal defects

- `IdxExpansionAndFundamentalAnalysisTest` fixture defect fixed. Fixture now seeds source, board scope, interval, classification, adjustment mode, and validation status. Isolated suite passes `9/9` tests, `46` assertions.
- Current EOD queue remains `PENDING` while provider/backlog work continues. Continuation planner source acceptance passes `3` cases and EOD hardening passes `22/22`; natural future-session scheduler acceptance remains `PENDING` until session exists naturally. No command per batch is required by planner; depth remains capped at `8` with timeout propagation.
- Live database read-back for new research request was attempted against wrong default DB and failed closed; API/UI evidence is valid, but direct candidate-table read-back remains open.
- Issuer profile and news remain `PARTIAL` where source provenance or fields are unavailable. No issuer description or news fact is synthesized.
- `git diff --check` reports pre-existing trailing whitespace in unrelated files. No cleanup was applied.

### Residual provider limits

- `SRIL`, `SUGI`, and `TDPM` lack target-session provider bars. Do not classify as internal failure.
- Provider board scope, event timestamp, and some endpoint entitlement remain unverified.

### Implementasi aplikasi lanjutan

- Running Trade UI now shows only Waktu, Ticker, Harga, Lot, Aksi. BUY is green, SELL is red, unknown values are neutral `N/A`. Board column, `BOARD_SCOPE_UNVERIFIED` badge, and board-scope paragraph were removed. Existing collector state API remains the sole source; no PostgreSQL write path exists in `runningTradeState`. Polling stops on `pagehide`.
- Movers UI now has only Top Gainer and Top Loser tabs. Query remains `/api/movers?by=chg&n=10`; provider rows, units, and unknown values remain unmodified or `N/A`.
- IHSG summary now labels `IHSG — Indeks Harga Saham Gabungan` and renders last value, point/percent change, prev close, open, high, and low without substituting open for prev close. Current chart contract uses authenticated `interval=60`; runtime acceptance is recorded in the chart addendum below.
- Broker summary ticker generik tetap memakai `IDX Edge PRO`, board `RG`, unit `SHARES`, currency `IDR`, nullable average, dan Top-N API clamp `1..5`. API memberi periode, seri cumulative flow, top buyers/sellers, serta disclaimer bahwa flow bukan inventori atau P&L bandar.
- Fundamental annual ingestion kini membaca `INCOME_STATEMENT`, `BALANCE_SHEET`, dan `CASH_FLOW_REPORT`. Operating cash flow, capital expenditure, dan free cash flow disimpan hanya jika field provider tersedia. Nilai kosong tetap `null`.
- Tab Fundamental menampilkan arus kas operasi dan free cash flow. Currency, scale, dan consolidation yang belum terbukti tetap memicu `NOMINAL_INTERPRETATION_BLOCKED`.
- Chart detail memakai seri historis RSI14 Wilder dan MACD 12/26/9 dari bar tersimpan. Tidak lagi mengulang nilai terakhir pada seluruh grafik. Kalender hanya memakai bar valid yang tersedia; tidak membuat candle hari libur.
- Support/resistance null tampil `N/A`, bukan `Rp 0`.
- EOD orchestrator memiliki planner continuation lintas sesi. Natural receipt `20261118_203507_dc913a28301a483eaf3e13548b7781d8.terminal.json` membuktikan parent target `2026-11-18` dan child continuation target `2026-11-16`, keduanya `TASK_SCHEDULER_EOD`; regular target tidak diganti. Target baru: `updated=20`, `remaining=479`, `semantic=WAITING`, `exit=75`. Continuation lama: `attempted=3`, `waiting=3`, `remaining=3`, `failed=0`, canonical read-back `writes_executed=false`, lock fields null. Planner tetap bounded dengan `max_batches=3` dan continuation depth cap `8`; tidak membuat owner kedua atau command per batch.

### Acceptance aplikasi lanjutan

IMPLEMENTED
- Running Trade presentation, two-tab Movers widget, IHSG summary fields, fixture corrections, and pagehide polling cleanup implemented.

TESTED
- Continuation planner self-test: `PASS cases=3`.
- EOD gap selector self-test: `PASS cases=5`.
- EOD unit/hardening suite: `22/22` tests, `116` assertions, PASS.
- `IdxExpansionAndFundamentalAnalysisTest`: `9/9` tests, `46` assertions, PASS.
- Combined defect/UI regression subset: `44/44` tests, `207` assertions, PASS.
- PHP lint and Blade cache: PASS.
- Running Trade DB-write audit found no write in `runningTradeState`; endpoint reads existing state JSON.

LIVE_VERIFIED
- Dashboard DOM shows Running Trade columns Waktu, Ticker, Harga, Lot, Aksi only.
- DOM shows provider BUY/SELL values with green/red styling and disconnected status with historical event and received timestamps.
- Dashboard DOM shows only Top Gainer and Top Loser tabs.
- Movers provider query remains `/api/movers?by=chg&n=10`.
- Candidate dashboard remains reader read-only on existing Task Scheduler owner.

DONE
- Authenticated IHSG chart probe through existing `IdxEdgeClient` succeeded without exposing credential. HTTP `200`, `application/json` schema accepted, `code=IHSG`, `date=2026-11-18`, `count=66`, `source=live`, `market.status=closed`, `prev_close=5304.755`, response timestamp `2026-11-18T14:08:31+00:00`, cache expiry about one second.
- Authenticated insider probe through existing `IdxEdgeClient` succeeded for `BBCA` without exposing credential. HTTP `200`, schema keys `stock_code,count,total,page,page_size,total_pages,items`, `total=85`, page `1/9`, `count=10`, item dates `2026-03-25`; parser validation passed.
- EOD natural receipt now audited from actual artifacts, not future assumption. Parent target `2026-11-18` and continuation target `2026-11-16` use `TASK_SCHEDULER_EOD`. Latest receipt `20261118_213503_cbe69afc3acb460fbdbbd9db172e93b9`: session 16 selected `3`, attempted `3`, updated `0`, waiting `3`, failed `0`, remaining `3`, exit `75`; raw requests skipped as `TARGET_SESSION_UNAVAILABLE`, adjusted responses HTTP `200` but ended `2026-11-09`. Sesi 17 has not yet received a later natural-run turn, so acceptance remains `PENDING`; no claim that backlog rotation is complete. Read-only preview keeps session 17 at `454/454` raw/adjusted and session 18 at `120/118`; no sessions merged.

PARTIAL
- IHSG one-minute chart is now `VERIFIED` on runtime sample: authenticated `/api/chart/IHSG?interval=60&limit=2000`, HTTP `200`, session `2026-11-18`, `322` provider bars, unique ascending timestamps. This proves sampled runtime support, not permanent provider uptime.
- Insider schema, pagination, dates, name/party, action type, and position are validated. Browser verified page `2/9`, provider total `85`, and `10` rows. Unit and board scope remain `NOT_DOCUMENTED`/`UNVERIFIED`; nominal calculations stay blocked. Feature status is `PARTIAL`, not fully verified.
- Mobile keyboard traversal has no dedicated browser trace. Polling lifecycle has one active timer, `inFlight` guard, `pagehide` cleanup, and `pageshow` recovery. Browser script inspection found no console errors.
- Issuer profile and news remain `PARTIAL`: available source fields render, unavailable provenance or description stays absent.
- Financial statements and ratios are `PARTIAL/DATA-DEPENDENT`: valid rows render; nominal interpretation remains blocked only where currency, scale, or consolidation metadata is missing.
- Broker accumulation remains provider-supplied with period and unit limitations explicitly shown; flow is not inventory or profit proof.
- AI history and structured conclusion are `DONE` for stored research request `REQ_20261118_111556_BBCA_FKsO`, analysis `#149`, evidence `183`; reload did not reinfer. Gemini and `STRUCTURED_FALLBACK` labels remain distinct.

BLOCKED
- One-minute IHSG chart no longer blocked. Runtime sample is `VERIFIED`; retain sampled-runtime scope and do not generalize to uptime outside observed response.
- Insider production activation blocked until unit and board scope contract is proven.
- ML remains `REJECTED/RESEARCH`. Outbox `#5` not retried. Historical records preserved.

## Addendum — Chart Contract and API-First Research, 18 November 2026

### Implemented

- Dokumentasi provider dikoreksi: `interval` memakai detik; `interval=60` didukung saham dan IHSG; chart bars memakai `[timestamp,open,high,low,close,volume]`; `date` adalah tanggal sesi; menit tanpa data tidak diinterpolasi.
- Dokumentasi `docs/dokumentasi_api.md` dan `docs/ai/AETHER_AI_ANALYSIS_PLAYBOOK.md` kini membedakan dukungan kontrak dokumentasi dari acceptance runtime authenticated.
- `IdxEdgeClient::chart()` memakai `/api/chart/{code}?interval=60&limit=2000`. `chartIhsg()` memakai endpoint IHSG yang sama, tanpa pemetaan ticker diam-diam.
- Chart detail mendukung `1D`, `1M`, `3M`, `6M`, `1Y` tanpa document reload.
- `1D` memakai provider intraday satu-menit. Epoch diparse sekali dengan `Asia/Jakarta`. Timestamp sama di-merge, timestamp baru di-append, duplikasi ditolak.
- Rentang daily memakai `/api/history/{code}` dengan `frame=daily`, tanggal kalender eksplisit, `limit=500`, dan `adjusted` terpisah.
- Daily response diurutkan ascending sebelum render. `data_available=false` menjadi `PROVIDER_EMPTY`.
- Frontend memakai request sequence guard, satu `chartInFlight`, polling 30 detik untuk `1D`, `pagehide` cleanup, dan `pageshow` recovery.
- IHSG history tidak diaktifkan tanpa bukti endpoint history. IHSG intraday memakai `/api/chart/IHSG` langsung.

### Runtime acceptance

- `BBCA`: `1D` HTTP `200`, interval `60`, session `2026-11-18`, `335` bars. Daily: `1M=23`, `3M=66`, `6M=127`, `1Y=240`; tiap response memiliki tanggal kalender eksplisit sampai `2026-11-18`.
- `BMRI`: `1D` HTTP `200`, interval `60`, session `2026-11-18`, `335` bars.
- `DEWA`: `1D` HTTP `200`, interval `60`, session `2026-11-18`, `333` bars. Kasus histori pendek tidak dipaksa mengisi bar yang tidak tersedia.
- `IHSG`: `1D` HTTP `200`, interval `60`, session `2026-11-18`, `322` bars. Data tetap berasal dari endpoint `IHSG`.
- Endpoint range dipanggil berturut-turut melalui browser console tanpa document reload. Response daily BBCA diurutkan ascending: `2026-10-19` sampai `2026-11-18` untuk `1M`, `2026-08-18` sampai `2026-11-18` untuk `3M`.
- Browser runtime sempat menemukan `ENDPOINT_DISABLED` untuk equity chart. Flag `IDX_EDGE_CHART_ENABLED=true` ditambahkan, config cache dibersihkan, task dashboard existing direstart, lalu acceptance equity lulus.
- API failure path tetap fail-closed dengan HTTP non-2xx dan error type provider. Tidak ada fallback lokal diam-diam pada chart API.
- Bukti persistensi riset lama tetap valid: evidence dan hasil tersimpan, history reload tidak melakukan inferensi ulang. EOD tetap background-owned oleh scheduler existing.

### Status belum terbukti

- Acceptance browser penuh untuk respons silang cepat dan append timestamp baru memerlukan provider berubah selama sesi; implementasi guard dan merge sudah ada, tetapi bukti live pergantian timestamp belum tersedia.
- History provider untuk emiten histori pendek dengan `data_available=false` belum dibuktikan live pada ticker tertentu; kontrak failure path diuji melalui isolated HTTP fake.
- Loading/error/stale/market-closed state tersedia melalui response status dan notice; dedicated visual keyboard/mobile trace belum dijalankan.
- Tidak ada perubahan Running Trade, ML, histori, scheduler EOD, owner, atau Outbox `#5`.

## Addendum — API-First Research and EOD Fairness, 18 November 2026

### Riset API-first

- `OnDemandDataCollectorService` kini refreshes raw daily history (`limit=500`), adjusted daily history (`limit=500`), annual fundamental, and paginated insider components before evidence enrichment.
- Each component records status, sanitized endpoint, parameters, fetched_at, date, provenance, raw hash, and fallback reason.
- News remains `LOCAL_CANONICAL_ONLY` with explicit fallback reason because no authenticated provider news contract is enabled.
- Evidence receives component refresh status, broker refresh status, refresh completion time, canonical raw/adjusted links, and metric provenance.
- Intraday chart data remains separate from daily indicators. No adjustment equivalence is inferred.
- Existing analysis history path remains immutable. Opening history does not call inference.
- No new scheduler, owner, worker, ML activation, Running Trade change, history deletion, or Outbox `#5` retry.

### EOD backlog fairness

- Read-only target audit at `2026-11-18 23:04:38+07:00` found:
  - `2026-11-16`: `496/496`, `PENDING`, `eligible_now=true`.
  - `2026-11-17`: `454/454`, `PENDING`, `eligible_now=true`.
  - `2026-11-18`: `300/296`, `PENDING`, `eligible_now=true`.
  - `2026-11-13`: `496/496`, `COMPLETED`, `needs_recovery=true`, but `eligible_now=false`, `STATUS_NOT_RETRYABLE`.
- Root cause: selector sorted eligible prior sessions ascending and always selected oldest session 16 November. Session 17 was not exhausted or deferred.
- Fix: selector now chooses nearest eligible prior backlog session descending. Session 17 gets turn before session 16 on next natural continuation.
- Planner fixture: `EOD_CONTINUATION_PLANNER_SELF_TEST=PASS cases=4`.
- Natural receipt after selector fix is not yet available. Acceptance remains `PENDING`; no manual EOD trigger was used.

### Exact remaining blockers

- Natural Task Scheduler receipt must prove session `2026-11-17` receives next continuation turn.
- Riset live end-to-end request must prove new request → API refresh → evidence → analysis → page result → AI History → reload without inference. Existing stored analysis reload proof remains valid; new component-refresh evidence requires live candidate read-back.
- Mobile and keyboard dedicated browser acceptance remains open.

## Ringkasan hasil

Status pemulihan operasional: CLOSED_WITH_EXTERNAL_RESIDUALS.

Status keseluruhan aplikasi: PARTIALLY_RECOVERED.

Pemulihan ditutup karena tidak ada defect internal penghambat pada selector, owner EOD, lock release, canonical read-back, limiter lokal, atau test fixture. Coverage provider yang belum lengkap tetap residual eksternal, bukan syarat untuk mengubah data historis.

Status keseluruhan sebelumnya: PARTIALLY_RECOVERED

Dashboard kandidat pemulihan berjalan dalam mode hanya-baca dan menampilkan data pemulihan. Pengujian database reguler telah diisolasi. Perbaikan discovery registry, dashboard, chart read path, health AI, dan retrieval dokumen telah diterapkan serta diuji terbatas.

Proyek belum selesai 100%. Writer EOD belum dibuktikan aman untuk diaktifkan, berita belum memiliki kontrak provenance lengkap, dan alur Telegram end-to-end belum dibuktikan memakai `update_id` masuk serta `message_id` keluar nyata. Scheduler tetap Disabled.

## Status per komponen

| Komponen | Status | Bukti aktual |
|---|---|---|
| Isolasi database test | FIXED_AND_VERIFIED | `DatabaseTestIsolationGuardTest`: koneksi operasi ditolak pasca-bootstrap. Test PostgreSQL hanya opt-in lewat `TEST_PG_*`; tanpa konfigurasi, 2 test skip aman. |
| Backup dan restore | FIXED_AND_VERIFIED | Restore dump baseline ke database unik berhasil sebelumnya dengan hash cocok; 39 tabel, 513.381 `market_observations`, 500 registry, 151 evidence, 16 prediksi. Database sumber tidak diubah. |
| Runtime recovery | FIXED_AND_VERIFIED | Preflight 13 Nov 2026 mengembalikan DB `project_bot_recovery_20261110`, role `aether_runtime`, 513.381 observasi, 500 registry, 0 tabel writable, 0 sequence writable, `default_transaction_read_only=true`. |
| Dashboard kandidat | FIXED_AND_VERIFIED | Port `8010` listening. Smoke HTTP memberi 200 untuk `/`, `/market`, `/news`, `/history`, `/health`, `/settings`, `/profile`, `/api/health`. Browser `/health` dan `/market` termuat tanpa console error. |
| Dashboard detail tujuh tab | IMPLEMENTED_NOT_VERIFIED | Controller/tab read paths telah diperbaiki sebelumnya, tetapi seluruh tujuh tab untuk emiten nyata belum melalui acceptance browser lengkap. |
| Chart dan indikator | IMPLEMENTED_NOT_VERIFIED | GET chart tidak lagi memicu backfill; basis indikator diselaraskan dengan mode candle. Acceptance browser semua kombinasi chart belum lengkap. |
| Discovery registry | FIXED_AND_VERIFIED | Staging/checkpoint diterapkan pada `UniverseDiscoveryService`. Partial discovery tidak mempromosikan atau menandai ticker `UNAVAILABLE`; resume checkpoint dan promosi lengkap diuji. 3 test, 23 assertion lulus. |
| EOD writer dan backlog | NOT_FIXED | Audit menemukan defect kritis pada retry per sesi/mode, finality, serta ownership writer. Writer dan scheduler tidak diaktifkan. Backlog 4–6 Nov serta sesi berikutnya belum diproses ulang. |
| Gemini dashboard | IMPLEMENTED_NOT_VERIFIED | Health memisahkan Google dashboard dari 9router konsultasi. Browser menunjukkan Google `DEGRADED`: endpoint terjangkau, inferensi valid tersimpan belum ada. Tidak ada klaim inferensi berhasil. |
| 9router konsultasi | IMPLEMENTED_NOT_VERIFIED | Browser health menunjukkan model router tersedia. Tidak ada test inferensi/consultation end-to-end dalam pemeriksaan akhir. |
| News ingestion/provenance | FIXED_AND_VERIFIED_PARTIAL | Candidate `news_items` berisi 5 record, 5 URL unik, raw path/hash lengkap, `first_seen_at`, dan `available_at`. Writer identity terbukti. Evidence span/related ticker belum tersedia. |
| Document retrieval | FIXED_AND_VERIFIED | Retrieval kini memverifikasi path dalam root proyek, file ada, SHA-256, dan ukuran bila tersedia; raw hilang/tamper ditolak. Fallback news tanpa provenance dihapus. 3 test fokus, 13 assertion lulus. |
| Telegram/outbox | IMPLEMENTED_NOT_VERIFIED | Tidak ada bukti inbound numeric `update_id` dan receipt outbound `message_id` cocok pada pemeriksaan ini. Tidak diklaim end-to-end. |
| Scheduler Windows | NOT_FIXED | Task `AetherMarketMonitoring_15min`, `AetherScheduledDispatcher_1min`, `AetherWorkers_5min`, dan `HermesMarketIntelligence_EOD` semuanya Disabled pada 13 Nov. EOD terakhir tercatat 6 Nov dengan result `267014`. |
| Collector WebSocket | BLOCKED_EXTERNAL | Browser market menunjukkan `MIXED_BOARD_UNSEPARABLE`, `DISCONNECTED`, 0 trade. Tidak ada bukti handshake upstream, payload board RG, heartbeat provider, reconnect, atau isolasi papan. |
| ML/realtime claims | FIXED_AND_VERIFIED | UI market menyatakan EOD acuan, `PROVISIONAL`, dan feed board tidak terpisah; tidak diklaim realtime atau RG-only. |

## Perbaikan yang diterapkan

### Keamanan data dan test

- `dashboard/app/Providers/AppServiceProvider.php`
  - Guard koneksi database test fail-closed setelah bootstrap.
- `dashboard/tests/Feature/PostgresTimezoneAndDeadlineSemanticsTest.php`
  - PostgreSQL integration test hanya jalan dengan `TEST_PG_*`, database test bernama khusus, role non-superuser, dan pemeriksaan identitas SQL.
  - Fallback credential di source dihapus.
- `dashboard/tests/Feature/DatabaseTestIsolationGuardTest.php`
  - Regression test koneksi operasional pasca-bootstrap.

### Startup pemulihan hanya-baca

- `scripts/launch_readonly_dashboard.php`
  - Memuat credential runtime dari secret store luar repository, menjalankan preflight identity/privilege, cache dan runtime path terpisah.
- `dashboard/bootstrap/app.php`, `dashboard/routes/web.php`, `scripts/start_dashboard.bat`
  - Kandidat pemulihan memakai konfigurasi/pemisahan runtime sendiri dan route mutasi tidak diregistrasikan.
- `dashboard/app/Http/Controllers/DashboardController.php`
  - Read path chart tidak menjalankan backfill/provider writer. Read path broker kandidat tidak melakukan provider side effect.
- `dashboard/resources/views/layouts/app.blade.php`, `dashboard/resources/views/dashboard/profile.blade.php`
  - Kontrol writer dinonaktifkan dalam mode pemulihan; route POST yang sengaja tidak diregistrasikan tidak lagi menyebabkan Blade 500.

### Health AI

- `dashboard/app/Services/SystemHealthService.php`
- `dashboard/resources/views/dashboard/health.blade.php`

Health membedakan:

1. Konfigurasi/endpoint Google dashboard AI.
2. Ada/tidaknya hasil inferensi valid tersimpan.
3. 9router untuk konsultasi serta ketersediaan model.

Health tidak menyebut 9router sebagai AI dashboard Google.

### Discovery registry dan EOD safety

- `dashboard/database/migrations/2026_11_13_090000_create_universe_discovery_staging_tables.php`
- `dashboard/app/Services/UniverseDiscoveryService.php`
- `dashboard/tests/Feature/UniverseDiscoverySafetyTest.php`

Discovery memakai run staging dan checkpoint. Registry hanya dipromosikan jika halaman provider lengkap dan denominator cocok. Kegagalan halaman menghasilkan `PARTIAL` dan tidak mengubah availability registry.

### Provenance dokumen

- `dashboard/app/Services/DocumentRetrievalService.php`
- `dashboard/tests/Feature/StructuredAnalysisAndRetrievalTest.php`

Sebelum chunk dikembalikan, service memeriksa `raw_file_path`, batas root proyek, keberadaan file, hash SHA-256, serta ukuran ketika metadata menyediakannya. Jalur fallback `news_items` diputus karena tabel tersebut belum memiliki provenance setara dan sebelumnya memaksa label `VERIFIED`.

## Identitas database dan role aktif

Preflight runtime 13 Nov 2026:

- Database: `project_bot_recovery_20261110`
- Role: `aether_runtime`
- Schema aktif: `pg_catalog`, dengan `public` dalam search path
- `market_observations`: 513.381
- `idx_company_registries`: 500
- Tabel writable: 0
- Sequence writable: 0
- `default_transaction_read_only`: `true`
- Secret tercetak: `false`

Tidak ada password, token, atau connection string rahasia dicantumkan di laporan ini.

## Backup dan pemulihan

Status: FIXED_AND_VERIFIED

Dump baseline incident dipulihkan sebelumnya ke database verifikasi baru yang unik, tanpa mengubah sumber. Verifikasi mencatat hash dump cocok, 39 tabel, 513.381 observasi, 500 registry, 151 evidence, dan 16 prediksi.

Database lama, recovery database, dan dump tidak dihapus atau ditimpa.

## Cakupan data dan backlog

- Bar terbaru yang terbukti dari recovery: 6 November 2026.
- Seluruh harga dashboard yang terlihat pada `/market` diberi sumber `IDX Edge PRO`, mode `UNADJUSTED`, tanggal EOD, dan badge `PROVISIONAL` bila finality provider belum terbukti.
- Browser `/market` menampilkan registry dan 26 row tabel pada viewport pemeriksaan; ini bukan bukti kelengkapan seluruh semesta atau finality EOD.
- Backlog sesi 4–6 November dan sesi setelahnya belum dijalankan kembali karena writer belum melewati gate finality, retry-key per sesi/mode, provenance, dan idempotensi.

## Pengujian dan build yang benar-benar dijalankan

13 Nov 2026:

```text
php artisan test --compact \
  tests/Feature/DatabaseTestIsolationGuardTest.php \
  tests/Feature/PostgresTimezoneAndDeadlineSemanticsTest.php \
  tests/Feature/UniverseDiscoverySafetyTest.php \
  tests/Feature/StructuredAnalysisAndRetrievalTest.php \
  tests/Feature/DashboardTest.php
```

Hasil: 30 test dieksekusi; 28 pass; 131 assertion; 2 skip aman.

Test tambahan yang telah dijalankan selama pemulihan:

```text
DatabaseTestIsolationGuardTest.php: 1 pass, 2 assertion
PostgresTimezoneAndDeadlineSemanticsTest.php: 2 skip aman tanpa TEST_PG_*
UniverseDiscoverySafetyTest.php: 3 pass, 23 assertion
StructuredAnalysisAndRetrievalTest focus: 3 pass, 13 assertion
```

Frontend:

```text
cd dashboard && npm run build
```

Hasil: PASS. Vite 8.3.5 membangun 314 module.

Pemeriksaan PHP:

```text
php -l app/Services/DocumentRetrievalService.php
php -l app/Services/UniverseDiscoveryService.php
```

Hasil: PASS.

Catatan kualitas tree: `git diff --check` masih FAIL pada trailing whitespace di perubahan pre-existing:

- `dashboard/app/Services/HermesConsultationAdapterService.php`
- `dashboard/resources/views/dashboard/history.blade.php`

Tidak diubah karena berada di luar diff perbaikan ini dan working tree sejak awal sangat kotor.

## Runtime dan browser yang diuji

Kandidat: `http://127.0.0.1:8010`

Smoke HTTP PASS, masing-masing 200:

```text
/
/market
/news
/history
/health
/settings
/profile
/api/health
```

Browser:

- `/health`: PASS. Mode pemulihan tampil; PostgreSQL recovery ditampilkan; Google dashboard AI `DEGRADED`; 9router konsultasi terpisah; tidak ada console error pada inspeksi.
- `/market`: PASS. Mode pemulihan tampil; registry/emiten termuat; harga diberi tanggal/sumber EOD dan status `PROVISIONAL`; WebSocket ditampilkan `DISCONNECTED` dan board `MIXED_BOARD_UNSEPARABLE`, bukan sebagai realtime RG.

Browser checks tidak membuktikan POST writer, live provider ingest, user-authenticated flow, atau Telegram transport.

## Status scheduler, worker, Gemini, Telegram, collector

### Scheduler

Status: NOT_FIXED

Semua task berikut Disabled pada pemeriksaan akhir:

- `\AetherMarketMonitoring_15min`
- `\AetherScheduledDispatcher_1min`
- `\AetherWorkers_5min`
- `\HermesMarketIntelligence_EOD`

Tidak diaktifkan karena writer/outbox/receipt belum memperoleh bukti acceptance lengkap.

### Worker dan EOD

Status: NOT_FIXED

Tidak dijalankan terhadap recovery database. Audit EOD terdahulu mendeteksi defect penting terkait discovery partial, retry state per sesi/mode, dan finality. Discovery partial telah diperbaiki dan diuji; sisa gate writer belum selesai.

### Gemini

Status: VERIFIED_LIVE_CONSULTATION

Recovery Job `#2` menjalankan satu inferensi terbatas melalui `ag/gemini-3.8-flash-high`. Evidence `#153` tersimpan sebelum Analysis `#141`; hasil terhubung ke Job `#2` dan Outbox `#6`.

### Telegram

Status: VERIFIED_LIVE_CONSULTATION_DELIVERY

Rantai nyata terbukti: inbound `update_id=496963060`, inbound `message_id=658`, recovery Job `#2`, outbound numeric `message_id=660`, dan receipt Outbox `#6`. Direct outbound `message_id=659` tetap tidak berubah. Outbox `#5` tetap `DELIVERY_UNKNOWN` dan tidak dikirim ulang.

### Collector realtime

Status: BLOCKED_EXTERNAL

Tidak ada bukti koneksi upstream/provider serta board scope RG. UI menyatakan `DISCONNECTED`, 0 trade, dan `MIXED_BOARD_UNSEPARABLE`. Intraday screening harus tetap `DATA_CAPABILITY_BLOCKED` sampai kontrak upstream terbukti.

## Menjalankan ulang setelah laptop restart

1. Pastikan Windows Task Scheduler tetap Disabled sampai gate writer dinyatakan lulus.
2. Jalankan preflight tanpa membuka HTTP server:

```text
cd E:\Aether-Market-Intelligence
php scripts/launch_readonly_dashboard.php --verify-only
```

Expected safe evidence: database recovery, role `aether_runtime`, nol write privilege, read-only transaction default.

3. Jalankan dashboard kandidat pada port terpisah:

```text
php scripts/launch_readonly_dashboard.php --serve --port=8010
```

4. Buka `http://127.0.0.1:8010`.

Port 8000 lama tidak dijadikan target cutover karena sebelumnya dimiliki proses tanpa izin penghentian. Jangan paksa mengambil port itu tanpa hak OS yang sesuai.

## Rollback

Rollback dashboard recovery tanpa menghapus database:

1. Hentikan hanya proses PHP kandidat yang listen pada port 8010.
2. Jangan hapus `project_bot_recovery_20261110`, database lama, atau dump baseline.
3. Pulihkan launcher/config lama hanya jika identitas SQL target lama telah diverifikasi eksplisit.
4. Bangun ulang cache tersanitasi pada runtime target yang dipilih.
5. Verifikasi `current_database`, `current_user`, `search_path`, serta privilege role sebelum server HTTP dibuka.

## Masalah belum selesai dan tindakan berikutnya

### 1. Writer EOD

Status: NOT_FIXED

Bukti: audit EOD menemukan defect kritis/high pada finality, retry key per ticker saja, dan workflow refresh. Discovery staging sudah diperbaiki; seluruh refresh/backfill writer belum diuji pada database terisolasi dengan provider nyata.

Dampak: menjalankan EOD dapat memberi status lengkap yang salah atau mencampur retry sesi/mode.

Tindakan berikutnya:

1. Tambah test-first untuk kunci retry `instrument_id + target_session + adjustment_mode`.
2. Uji kedua mode `UNADJUSTED` dan `PROVIDER_BACK_ADJUSTED` wajib `VALID` sebelum `UPDATED`.
3. Terapkan status provisional ketika finality provider belum terbukti.
4. Uji satu ingestion terbatasi pada database writer khusus, kemudian read-back idempotensi/revision archive sebelum scheduler diaktifkan.

### 2. Berita

Status: NOT_FIXED

Bukti: recovery `news_items=0`; schema belum memiliki `first_seen_at`/`available_at`; RSS/raw body belum diarsipkan/hash; impact masih berisiko berbasis keyword umum.

Dampak: anti-lookahead, badge source/fact verification, dan dampak emiten belum dapat dipercaya.

Tindakan berikutnya:

1. Migrasi additive untuk `first_seen_at`, `available_at`, raw path/hash/content hash, dan status provenance kanonikal.
2. Arsipkan raw RSS/body append-only sebelum parsing.
3. Satukan mapper dengan registry dan simpan alasan match.
4. Jadikan impact default `UNCLEAR`/hipotesis sampai memiliki span bukti raw.
5. Uji provider terbatas dalam database writer khusus; jangan aktifkan fallback tersembunyi.

### 3. Telegram, outbox, scheduler

Status: IMPLEMENTED_NOT_VERIFIED

Bukti: scheduler Disabled; audit Telegram read-only tidak selesai pada sesi sebelumnya; tidak ada receipt live.

Dampak: belum boleh mengklaim receiver tunggal, deduplication, deadline, heartbeat, recovery macet, maupun delivery tepat sekali.

Tindakan berikutnya:

1. Selesaikan audit source `NARRATIVE_EXIT`, ownership receiver, lease token, deadline/heartbeat dan dedup key.
2. Jalankan test isolated queue/outbox.
3. Setelah writer lulus, lakukan satu test Telegram allowlist dengan user owner; catat numeric `update_id` dan outbound `message_id` nyata.
4. Aktifkan satu scheduler owner per job saja setelah receipt dan exit code runner diverifikasi.

### 4. Detail tujuh tab

Status: IMPLEMENTED_NOT_VERIFIED

Bukti: HTTP route smoke dan market directory lulus, tetapi tab per emiten belum semua dibuka melalui browser dengan data, empty state, denied provider, dan pending queue.

Tindakan berikutnya: lakukan matrix browser untuk satu ticker berdata dan satu ticker tanpa data, tujuh tab, chart API, broker/running trade, news/history/research state. Jangan klik aksi mutasi pada candidate read-only.

### 5. Config cache / historic audit rows

Status: IMPLEMENTED_NOT_VERIFIED

Bukti: browser health menampilkan row audit lama dengan identity `project-bot`; preflight current runtime menunjukkan `project_bot_recovery_20261110`. Row lama bukan bukti runtime kandidat sekarang.

Tindakan berikutnya: tampilkan identity snapshot pada setiap health row atau beri badge legacy; jangan menghapus audit historis. Verifikasi config cache tidak memuat credential ter-resolve sebelum commit/deploy.

## Addendum 16 November 2026 — Job #5 transport dan grounding dipisah

### Trace transport Job #5

- Inbound: `update_id=496963070`, inbound `message_id=684`.
- Job: `#5`, `ASYNC_CONSULTATION`, trigger aktual `AetherScheduledDispatcher_1min`; worker PID `18128`.
- Evidence: `#156`.
- Analysis: `#144`.
- Outbox jawaban asli: `#9`, status `SENT`, outbound Telegram `message_id=686`.
- Correction evidence: `#157`.
- Correction outbox: `#10`, `SYSTEM_CORRECTION`, status `SENT`, outbound Telegram `message_id=687`.
- Database runtime: `project_bot_recovered_20261115_022935`; role `aether_ingestion`; schema `public`.
- Job #5 dan Outbox #9 tetap immutable. Outbox #5 `DELIVERY_UNKNOWN` tetap tidak disentuh.

### Penilaian kualitas grounding Job #5

Status: `MATERIAL_GROUNDING_ERROR_CORRECTED`.

- Evidence #156 memuat payload `get_instrument_analysis` yang tidak memuat broker rows.
- Audit read-only candidate DB menemukan 50 broker rows BBCA.
- Snapshot broker: sesi `2026-11-13`, board `RG`, flow `all`, unit `SHARES`, source `IDX Edge PRO`, fetched `2026-11-15T13:30:30Z`.
- Bar: `2026-11-13`, board `RG`, `PROVIDER_BACK_ADJUSTED`, fetched `2026-11-15T08:57:39Z`, finality `PROVISIONAL`.
- Metrik teknikal terakhir: `2026-11-06`; metrik tidak disamarkan sebagai metrik bar `2026-11-13`.
- Root cause: nested synthetic ticker handler bypassed broker-aware deterministic payload. LLM lalu menyimpulkan broker tidak tersedia dari payload yang memang tidak memuat rows.
- Kontrak diperbaiki: data ada tetapi tidak dimuat disebut `tidak tercakup dalam analisis`; broker summary menyebut periode, board, source, unit, fetched_at, dan batas Top-N; Top-N tidak disebut net seluruh pasar atau inventori investor.
- Status model dipisah: ML produksi `TIDAK AKTIF; artefak produksi belum tersedia`; `NO_ACTIVE_INFERENCE_FOR_BAR` bukan `NO_SIGNAL_ABSTAIN`.
- Freshness memakai kalender BEI dan cutoff request. Finality tetap terpisah `PROVISIONAL`.
- Koreksi dikirim deterministik melalui Outbox #10 tanpa inferensi berbayar ulang.

### Penilaian transport

Status: `VERIFIED` untuk inbound, dispatcher, job, evidence, analysis, outbox jawaban asli, correction outbox, dan outbound receipts `686` serta `687`.

### Penilaian kualitas jawaban

Status jawaban asli: `NOT VERIFIED` karena klaim broker material salah.
Status koreksi: `VERIFIED` untuk payload deterministik dan delivery correction. Ini bukan bukti bahwa seluruh analisis Gemini lama benar.

### Uji perbaikan

- Fixture SQLite terisolasi: `BrokerSummaryAndFundamentalIngestionTest::test_grounded_instrument_analysis_keeps_broker_snapshot_and_ml_state_separate` — `1` test, `6` assertions, pass.
- Tidak menjalankan framework test pada candidate DB.
- PHP lint lulus untuk launcher, tool executor, conversation service, dan fixture test.
- `git diff --check` lulus untuk file perbaikan yang disentuh.

## Addendum 16 November 2026 — technical metrics dan kesiapan briefing pagi

### Root cause metrik BBCA tertinggal

- Bar adjusted BBCA tersedia sampai `2026-11-13`.
- `market_observations`: `506` bar adjusted valid, `506` bar raw valid, `0` bar invalid, rentang valid `2024-10-01` sampai `2026-11-13`.
- Source: `IDX Edge PRO`.
- Board: `RG`.
- Interval: `1d`.
- Adjustment: `PROVIDER_BACK_ADJUSTED` untuk indikator; raw `UNADJUSTED` hanya untuk coverage EOD.
- Bug call graph: cabang `FRESH_ALREADY` melakukan `continue` sebelum `TechnicalMetricsCalculationService` dipanggil.
- Bug provenance: jalur ingest lama memanggil `calculateForTicker($ticker)` tanpa tanggal sesi, sehingga `as_of_date` memakai tanggal proses.
- Log lama membuktikan kalkulasi menghasilkan `as_of_date=2026-11-15` dengan `last_date=2026-11-13`; write gagal dan tidak boleh dianggap metrik sesi baru.
- Write gagal karena `aether_ingestion` tidak memiliki `INSERT/UPDATE` pada `company_analysis_metrics`.
- Row kandidat tetap `as_of_date=2026-11-06`, `updated_at=2026-11-06 12:55:16`.

### Kontrak formula dan kecukupan input

- SMA20: minimum `20` bar.
- SMA50: minimum `50` bar.
- SMA200: minimum `200` bar.
- RSI14: minimum `15` bar; Wilder RMA dengan seed SMA 14 perubahan harga.
- ATR14: minimum `15` bar; Wilder RMA dengan seed 14 True Range.
- MACD: minimum `35` bar; EMA `12` minus EMA `26`, signal EMA `9`.
- Formula version/provenance tersimpan dalam `data_quality_flags.formulas` dan `data_quality_flags.adjustment_mode`.
- Gap atau bar invalid tidak dihitung sebagai input valid. Tidak ada angka buatan saat input tidak cukup.

### Perbaikan pipeline EOD

- `DailyEodRefreshService` kini menghitung metrik pada dua jalur: bar baru dan `FRESH_ALREADY`.
- Kedua jalur meneruskan `calculateForTicker($ticker, $expectedSessionDate)`.
- Hasil dipisah menjadi `UPDATED`, `INSUFFICIENT_INPUT`, dan `FAILED`.
- Evidence metrik menyimpan tanggal acuan, source, board, interval, adjustment, jumlah input valid/invalid, rentang input, dan provenance formula.
- Queue EOD menyimpan `metrics_updated`, `metrics_insufficient_input`, `metrics_failed`, dan `metric_evidence`.
- Metrik gagal tidak mengubah keberhasilan ingestion bar.
- Idempotensi bar tetap diuji; metrik memakai `updateOrCreate(ticker, as_of_date)`.

### Canary BBCA dan read-back

- Guarded canary: `scripts/launch_ingestion_writer.php --canary --session=2026-11-13 --tickers=BBCA`.
- Identity: database `project_bot_recovered_20261115_022935`, role `aether_ingestion`, schema `public`.
- Ingestion result: `Already fresh=1`, `Updated=0`, `Fetch errors=0`, `Execution complete=YES`.
- Technical metric result: `PENDING_GRANT`; kalkulasi terpicu, tetapi upsert ditolak ACL.
- Tidak ada batch universe dijalankan.
- Paket admin review-only: `scripts/grant_candidate_metrics_access.sql`.
- Paket hanya memberi `SELECT, INSERT, UPDATE` pada `company_analysis_metrics` dan `USAGE, SELECT` pada `company_analysis_metrics_id_seq`.
- `DELETE`, `TRUNCATE`, `REFERENCES`, dan `TRIGGER` tetap ditolak.
- Grant tidak dieksekusi oleh runtime role. Password tidak diminta melalui chat.

Dashboard read-back:

- Endpoint chart: `/api/market/BBCA/chart-data?range=1M&mode=adjusted`.
- Bar terbaru: `2026-11-13`.
- Mode: `PROVIDER_BACK_ADJUSTED`.
- Source: `IDX Edge PRO (Pasar Reguler)`.
- `current_metrics`: seluruh nilai `null` karena row metrik sesi baru belum dapat ditulis.
- Dashboard tidak mengangkat metrik `2026-11-06` sebagai metrik `2026-11-13`.

### Validasi payload analisis BBCA

- Bar dan metrik tetap bertanggal terpisah.
- Broker: `50` rows, periode `2026-11-13`, board `RG`, unit `SHARES`, Top-N `50`, source `IDX Edge PRO`, `fetched_at=2026-11-15T13:30:30Z`.
- News candidate: `5` rows, `5` URL unik, provenance raw/timestamp lengkap, status `UNVERIFIED`.
- ML produksi: tidak aktif; `model_rf.pkl` dan `manifest.json` tidak tersedia.
- Freshness memakai kalender BEI dan cutoff request.
- Finality tetap `PROVISIONAL`; tidak dicampur dengan freshness.
- Data tersedia, data tidak dimuat, data kosong, dan data stale tetap dibedakan.
- Correction deterministic `message_id=687` bukan bukti kualitas sintesis Gemini berikutnya.
- Regression fixture grounding lulus: `1` test, `6` assertions.

### Kesiapan briefing pagi

- `schedule:list` menunjukkan owner Laravel hanya briefing `08:30`, update `09:15/12:15/15:15`, outbox, konsultasi, dan evening review.
- EOD tidak terdaftar di Laravel scheduler; owner tetap `HermesMarketIntelligence_EOD` guarded.
- `AetherScheduledDispatcher_1min`: `Ready`.
- `AetherWorkers_5min`: `Disabled`.
- Briefing memakai sesi EOD sebelumnya dan menolak sinyal expired melalui dedup/idempotency path.
- Slot intraday tetap `QUARANTINED`; tidak boleh mengklaim realtime, scalping, atau probabilitas ML.
- Snapshot dan metrik harus bertanggal; metrik BBCA sesi `2026-11-13` belum siap sebelum grant admin.
- Deadline, writer identity, outbox, dan receipt harus diverifikasi pada trigger alami.
- EOD trigger nyata tetap `PENDING`; tidak diganti canary manual.

### Job #4 dan Job #5

- Job #4 tetap tidak diubah: `started_at=2026-11-14T23:19:04Z`, Evidence `2026-11-15T06:19:12Z`, Analysis `2026-11-15T06:19:29Z`, latency `33930 ms`.
- Timestamp discrepancy Job #4 tetap `OPEN`; penyebab belum terbukti.
- Transport Job #5 dan correction: `VERIFIED`.
- Jawaban asli Job #5: `NOT VERIFIED` karena grounding broker salah.
- Correction deterministic: `VERIFIED` untuk payload dan delivery, bukan bukti sintesis Gemini baru.
- Evidence asli, Analysis asli, Outbox asli `SENT`, Outbox correction `#10`, dan Outbox #5 `DELIVERY_UNKNOWN` tetap dipertahankan.
- Tidak ada correction kedua, inferensi ulang, replay, atau pengiriman ulang.

### Hasil setelah grant metrik

- Admin grant dijalankan pada candidate DB dengan `postgres`; postgres tidak dipakai sebagai runtime.
- Writer aktual diverifikasi: database `project_bot_recovered_20261115_022935`, role `aether_ingestion`, schema `public`.
- Privilege aktual: metric `SELECT=true`, `INSERT=true`, `UPDATE=true`, `DELETE=false`, `TRUNCATE=false`; sequence `USAGE=true`, `SELECT=true`.
- Writer `--verify-only`: `VERIFIED`, `observations=518371`, `writer_started=false` pada jalur audit.

### Hasil canary BBCA

- Session: `2026-11-13`.
- Run 1: `Already fresh=1`, `Updated=0`, `Metrics updated=1`, `INSUFFICIENT_INPUT=0`, `FAILED=0`, new bars `0`.
- Read-back metric: `as_of_date=2026-11-13`, `technical_status=CALCULATED`, `input_bars=506`, `last_date=2026-11-13`, row count `1`.
- Formula provenance tersimpan: SMA, Wilder RSI14, Wilder ATR14, MACD EMA `(12,26,9)`.
- Dashboard API read-back: bar `2026-11-13`; SMA20 `5126.5`, SMA50 `5767.6`, SMA200 `6342.03`, RSI14 `53.56`, MACD `-100.4`, ATR14 `196.9`.
- Run 2 identik: `Already fresh=1`, new bars `0`, `Metrics updated=1`, `FAILED=0`.
- Metric row count tetap `1`; canonical bar audit menemukan `duplicate_keys=[]`, revision count `0`.

### Batch metrik bertahap

- Pre-run anti-join memilih tepat `5` ticker dengan raw+adjusted valid pada sesi `2026-11-13` dan metric row sesi belum ada:
  `AADI`, `AALI`, `ABDA`, `ABMM`, `ACES`.
- Guarded batch run 1: selected `5`, already fresh `5`, new bars `0`, metrics updated `5`, insufficient `0`, failed `0`, selected scope complete `YES`.
- Read-back seluruh batch: `as_of_date=2026-11-13`, `technical_status=CALCULATED`, metric row count `1` per ticker; input bars `AADI=460`, lainnya `506`; `last_date=2026-11-13`.
- Guarded batch run 2 identik: new bars `0`, metrics updated `5`, insufficient `0`, failed `0`.
- Canonical bar audit untuk `BBCA,AADI,AALI,ABDA,ABMM,ACES`: setiap ticker memiliki tepat satu raw dan satu adjusted row valid; `duplicate_keys=[]`, `revision_count=0`.
- Batch hanya mencakup ticker yang lolos input gate. Ticker tanpa raw+adjusted valid tetap bukan `FAILED`; statusnya `INSUFFICIENT_INPUT` dan belum diproses.
- Full-universe metric backfill belum dijalankan.

### Kesiapan laporan pagi setelah grant

- Writer metric path sudah `VERIFIED` untuk candidate DB dan role runtime.
- Snapshot BBCA dan lima ticker batch memiliki tanggal sesi eksplisit `2026-11-13`.
- Briefing tetap memakai EOD sesi sebelumnya; slot intraday tetap `QUARANTINED`.
- Tidak ada klaim realtime, scalping, atau probabilitas ML.
- `AetherScheduledDispatcher_1min` tetap `Ready`; `AetherWorkers_5min` tetap `Disabled`.
- EOD natural trigger sukses tetap `PENDING`; canary guarded bukan bukti trigger berkala.
- Receipt pagi belum diverifikasi pada run ini.

### Blocker tersisa

- Full metric backfill bertahap belum selesai.
- EOD natural trigger sukses tetap `PENDING`.
- Realtime WebSocket tetap `DATA_CAPABILITY_BLOCKED`.
- ML produksi tetap tidak aktif.

## Kesimpulan

Grant minimum lulus. Canary BBCA dan batch lima ticker memperbarui metrik deterministik dengan tanggal sesi benar, formula/provenance tersimpan, dan idempotensi teruji. Ingestion bar tetap terpisah dari status metrik; tidak ada angka buatan atau retry inferensi Telegram.

Outbox #5 tetap `DELIVERY_UNKNOWN`; histori Job #5, Evidence asli, Analysis asli, dan Outbox `SENT` tidak diubah. EOD tetap `PENDING` sampai trigger alami guarded berhasil. Finality IDX Edge tetap `PROVISIONAL`; tidak ada klaim realtime atau ML.

## Pembaruan operasional 15 November 2026 — provider authentication gate

- Jalur worker: `scripts/launch_app_writer.php` → Laravel `AiConversationService`.
- Provider: `custom_local_router`.
- Endpoint dan model tidak diganti: `http://127.0.0.1:20128/v1`, `ag/gemini-3.8-flash-high`.
- Akar HTTP 401: worker memakai `AI_API_KEY`, sementara konfigurasi worker kosong; credential aktif tersedia sebagai `OPENAI_API_KEY` pada profile Hermes `aether-prod`.
- Perubahan terbatas: launcher membaca `OPENAI_API_KEY` dari profile `.env` hanya saat `AI_API_KEY` kosong. Nilai tidak dicetak atau disimpan ulang.
- Probe aktual worker: `READY`, HTTP `200`, credential present `true`, `transport_error=none`, `secret_printed=false`.
- Probe memakai `stream=false`, `max_tokens=8`, satu request, tanpa retry otomatis.
- `Retry-After` terdeteksi pada respons sukses router lokal; tidak ada 429 pada probe. Tidak ada job baru dibuat berdasarkan probe ini saja.

## Pembaruan operasional 15 November 2026 — canary dan coverage

- Canary `update_id=496963067` tercatat pada receipt ledger.
- Inbound `message_id=674` terbaca dari durable `telegram_conversations.provenance`.
- Aether intercept dan bridge sukses; outbound `message_id=675` tersimpan.
- Tidak ada core-agent turn atau provider call untuk canary status.
- Router source/plugin hash sama setelah penambahan trace inbound ID.
- Formatter status tidak lagi mencetak daftar ticker atau label `Sesi Resmi Terverifikasi`.
- Audit coverage read-only: database candidate, role `aether_ingestion`, writes `false`.
- Registry `500`; observed IDX Edge instruments `499`; registry tanpa observasi IDX Edge: `GOTOM`.
- ACL EOD: `eod_session_queues SELECT=false`.
- ACL news: `SELECT=true`, `INSERT=true`, `UPDATE=true`, `DELETE=false`.
- Outbox #5 tetap `DELIVERY_UNKNOWN`; tidak ada retry.

## Pembaruan operasional 15 November 2026 — E2E otomatis Job #4

- E2E otomatis: `VERIFIED`.
- Inbound: `update_id=496963068`, inbound `message_id=676`.
- Intent: `QUERY_COMPREHENSIVE_ANALYSIS`, ticker BBCA.
- Job: `#4`, `ASYNC_CONSULTATION`, status `COMPLETED`, delivery `SENT`.
- Scheduler trigger: job diproses oleh `\\AetherWorkers_5min`; tidak ada worker manual dan tidak ada `schtasks /Run`.
- Job timing: `scheduled_at=2026-11-14T23:16:16Z`, `started_at=2026-11-14T23:19:04Z`, latency `33930 ms`.
- Evidence: `#155`, tersedia `2026-11-15T06:19:12Z`.
- Analysis: `#143`, selesai `2026-11-15T06:19:29Z`.
- Outbox: `#8`, status `SENT`, retry count `0`.
- Outbound receipt: Telegram `message_id=678`, tersimpan `2026-11-15T06:19:38Z`.
- Core Hermes turn: tidak ada untuk Job #4; jalur berasal dari durable job dan scheduler worker.
- Evidence mendahului analysis. Outbox #5 tetap `DELIVERY_UNKNOWN` dan tidak disentuh.

## Pembaruan operasional 15 November 2026 — EOD grant gate

- Candidate backup setelah aktivasi: `VERIFIED`.
- Backup: `E:/Aether-Market-Intelligence/storage/backups/candidate_20261115/project_bot_recovered_20261115_022935_pre_eod_news_20261115_133534.dump`.
- Backup bytes: `45579452`.
- Backup SHA-256: `4e783e5d4a987fe08ca2bc2ba8a7e076e620650e419b7bb190fb560260dc0fec`.
- `pg_restore --list`: `PASS`.
- Baseline dan bukti insiden tidak disentuh.
- Call graph membuktikan `eod_session_queues` bukan SELECT-only: `firstOrCreate`, heartbeat `update`, status `update`, dan queue promotion `update` dipanggil.
- Grant minimum queue: `SELECT,INSERT,UPDATE` plus sequence `USAGE,SELECT`; DELETE/TRUNCATE tetap ditolak.
- Script grant-only kandidat diperbarui: `scripts/grant_candidate_eod_access.sql`.
- Script admin lokal: `scripts/apply_candidate_eod_access.ps1`.
- Audit writer setelah grant terbaru: `eod_session_queues SELECT=true, INSERT=true, UPDATE=true, DELETE=false`; `news_items SELECT=true, INSERT=true, UPDATE=true, DELETE=false`; writes dari audit: `false`.
- EOD dry-run target kalender: sesi `2026-11-13`, trading day `true`, sesi tertutup `true`, fetch diizinkan `true`.
- EOD canary BBCA tidak lulus: command exit `0`, tetapi `total eligible=0`, `updated=0`, `bars_ingested=0`, `execution_complete=YES`, `selected_scope_complete=NO`. Pengulangan menghasilkan angka sama, sehingga idempotensi provider belum teruji.
- Queue canary tercatat `COMPLETED` dengan `attempts=2` dan `bars_ingested=0`; ini bukan bukti ingest berhasil.
- Audit berikutnya menemukan registry kandidat tidak mengembalikan BBCA maupun GOTO; penyebab scope belum dijelaskan. Tidak menjalankan backlog atau mengubah registry.
- Perintah news nyata mengembalikan `NEWS_INGESTED 5`, tetapi verifikasi identitas menunjukkan `current_database=project_bot_recovery_20261110`, `current_user=postgres`. Lima record masuk DB recovery lama, bukan kandidat. Hasil tidak boleh dihitung sebagai news ingestion kandidat; tidak diulang sebelum target writer dikunci.
- Browser `/news` juga menampilkan `MODE PEMULIHAN`, bukan bukti dashboard kandidat.
- Timestamp Job #4 belum dijelaskan. Record historis tetap utuh. Nilai `started_at=2026-11-14T23:19:04Z`, evidence `2026-11-15T06:19:12Z`, analysis `2026-11-15T06:19:29Z`, dan latency `33930 ms` belum direkonsiliasi dengan scheduler tick atau serializer. Konfigurasi UTC saja tidak menjelaskan selisih tujuh jam; tidak ada koreksi timestamp.
- Job #4 output mencatat `NO_ACTIVE_INFERENCE_FOR_BAR`, bukan `NO_SIGNAL_ABSTAIN`. Fundamental dan berita 7 hari dinyatakan tidak tercakup snapshot; tidak mengklaim seluruh database kosong.

## Pembaruan operasional — runtime target gate

- EOD/news dan jadwal pemanggilnya ditahan.
- Root cause salah target: `dashboard/bootstrap/cache/config.php` berisi koneksi stale ke `project_bot_recovery_20261110` dengan user `postgres`. Plain `php artisan` memakai cache itu jika launcher terjaga tidak digunakan.
- `scripts/launch_ingestion_writer.php --verify-only` sekarang membuktikan `project_bot_recovered_20261115_022935`, role `aether_ingestion`, `observations=513381`, tanpa mutation.
- `scripts/launch_app_writer.php --coverage-acl-audit` membuktikan kandidat/aether_ingestion, queue SELECT/INSERT/UPDATE, news SELECT/INSERT/UPDATE, DELETE false, tanpa writes.
- Plain `php artisan hermes:ingest-news --limit=1` sekarang fail-closed: `NEWS_WRITER_ABORT missing explicit writer identity`.
- EOD dan news command memeriksa `current_database()`, `current_user`, `current_schema()`, dan `search_path` setelah bootstrap sebelum HTTP/provider atau write.
- Expected identity hanya diterima dari explicit `AETHER_ACTIVE_DATABASE` dan `AETHER_WRITER_ROLE`; fallback config tidak dipakai sebagai gate.
- `scripts/run_eod_pipeline.bat` memiliki hard stop exit `78`; legacy plain Artisan tidak dapat bypass writer guard.
- `\\HermesMarketIntelligence_EOD`: Disabled. `\\AetherWorkers_5min`: tetap Ready untuk consultation/outbox; EOD publication tetap HELD.
- EOD canary sebelumnya tidak valid: queue `#1` `COMPLETED`, attempts `2`, `total_eligible=0`, `bars_ingested=0`, `selected_scope_complete=false`. Tidak ada provider bar yang ditulis. Idempotensi belum terbukti.
- Lima news nyata sebelumnya masuk ke database lama `project_bot_recovery_20261110` sebagai `postgres`, bukan kandidat. Record ID `1..5`, URL, hash, raw archive, published/fetched/available timestamps dipertahankan. Tidak dihapus atau dipindahkan. Dampak: candidate news coverage tetap NOT VERIFIED.
- Browser `/news` berasal dari runtime recovery lama, bukan bukti dashboard kandidat.
- Tidak ada grant, restore, backup ulang, Telegram replay, atau outbox `#5` mutation pada tahap ini.
- Timestamp Job #4 tetap terbuka: UTC configuration belum menjelaskan selisih tujuh jam dengan latency `33930 ms`; raw timestamps dan historical records tidak diubah.

## Pembaruan operasional — canary guarded writer

- Backup pre-EOD/news tetap cocok: file `project_bot_recovered_20261115_022935_pre_eod_news_20261115_133534.dump`, SHA-256 `4e783e5d4a987fe08ca2bc2ba8a7e076e620650e419b7bb190fb560260dc0fec`.
- Launcher resmi `scripts/launch_ingestion_writer.php` memakai `AETHER_ACTIVE_DATABASE=project_bot_recovered_20261115_022935` dan `AETHER_WRITER_ROLE=aether_ingestion`.
- Post-bootstrap identity: database kandidat, user `aether_ingestion`, schema `public`, search path `"$user", public`.
- Cache writer dipindah ke file cache terisolasi. Tidak ada grant `cache_locks` dan tidak ada restore tambahan.
- Scope audit read-only sebelum canary: `BBCA` dan `GOTO` ada, `instrument_type=EQUITY`, `provider_availability=AVAILABLE`, `backfill_status=COMPLETE`, sehingga keduanya `ACCEPTED`. Keduanya sebelumnya tersaring karena predicate SQL `!=` menolak NULL secara implisit; predicate EOD diperbaiki untuk menerima NULL selain `UNSUPPORTED_SHARE_CLASS`.
- Sebelum canary, BBCA raw dan adjusted terakhir `2026-11-06`; GOTO raw dan adjusted terakhir `2026-11-06`.
- Canary EOD BBCA target sesi `2026-11-13`: scope `1`, updated `1`, provider bars `5`, fetch errors `0`, execution complete `YES`, selected scope complete `YES`, universe coverage tetap `NO`.
- Canary kedua identik: scope `1`, already fresh `1`, updated `0`, new bars `0`. Idempotensi BBCA `VERIFIED`; tidak ada duplikasi.
- Post-canary scope audit: BBCA raw dan adjusted terakhir `2026-11-13`; GOTO tetap `2026-11-06`. GOTO backlog belum dijalankan.
- Raw/adjusted BBCA tersimpan terpisah dengan `source=IDX Edge PRO`, `board_scope=RG`, `validation_status=VALID`, `is_eod_final` dan `finality_basis` dibaca dari record aktual. Tidak ada revision archive baru karena provider tidak merevisi bar pada pengulangan.
- Queue `#1` sebelumnya tetap utuh sebagai jejak no-op kosong; tidak dihapus atau ditulis ulang.
- News kandidat melalui `scripts/launch_app_writer.php --news --limit=5`: `NEWS_INGESTED 5`.
- News read-back kandidat: database kandidat, `aether_ingestion`, count `5`, distinct URL `5`, raw complete `5`, timestamps complete `5`. Lima raw archive hash diverifikasi ulang dan seluruh actual SHA-256 cocok dengan `raw_payload_hash`.
- News records menyimpan URL, sumber Antara, `published_at`, `first_seen_at`, `available_at`, raw path, raw hash, dan `related_tickers=[]`. Tidak ada relevansi ticker yang dikarang.
- Lima record salah target pada DB lama tetap dipertahankan dan tidak disalin ulang.
- HTTP `/news` dan `/market/BBCA`: `200`. Browser menampilkan data BBCA sesi `2026-11-13` dan lima artikel, tetapi label runtime masih `MODE PEMULIHAN`; UI candidate identity belum VERIFIED. HTTP 200 bukan bukti target UI.
- GOTO backlog dan full-universe EOD tetap ditahan sampai checkpoint berikutnya. Publikasi laporan terkini belum diaktifkan.
- Outbox `#5` tetap `DELIVERY_UNKNOWN`; tidak disentuh. Konsultasi/outbox worker tetap dipertahankan.
- Timestamp Job #4 tetap terbuka dan tidak diubah.

## Pembaruan operasional — backlog, guarded scheduler, dan batas news

- Web runtime HTTP identity: VERIFIED melalui `/api/runtime-identity` pada port `8000`.
- Database web: `project_bot_recovered_20261115_022935`.
- Role web: `aether_runtime`.
- Web role tetap read-only; mutation flags tetap `false`.
- EOD GOTO canary: VERIFIED, session `2026-11-13`, 5 bar baru, 0 failed.
- EOD GOTO repeat: VERIFIED idempotent, 0 bar baru.
- EOD batch checkpoint: dua batch unik, masing-masing `20/20`, masing-masing 100 bar baru, 0 failed.
- Batch pertama dan kedua tidak diulang pada scope sama.
- Backlog audit read-only memakai seluruh registry EQUITY yang bukan `UNSUPPORTED_SHARE_CLASS`.
- Audit menunjukkan ticker yang sudah fresh pada `2026-11-13` dipisahkan dari ticker yang masih `2026-11-06`.
- `GOTOM` tetap dalam registry dan tidak dihapus; gap observation tetap direkonsiliasi sebagai coverage gap.
- Selected scope complete tidak dipromosikan menjadi universe complete.
- News candidate tetap 5 record dan 5 URL unik setelah ingestion ulang terkontrol.
- Schema news candidate terbukti memiliki `fetched_at`, `first_seen_at`, `available_at`, `raw_payload_path`, dan `raw_payload_hash` melalui writer read-back.
- Migration ledger memiliki `2026_11_13_113000_add_provenance_to_news_items_table.php` dengan kolom provenance tersebut.
- Writer memakai `public.news_items` pada DB candidate dan role `aether_ingestion`.
- Bug ditemukan: ingestion lama menimpa `available_at` pada setiap fetch ulang.
- Writer diperbaiki: konten sama mempertahankan `available_at`; hash berbeda membuat versi baru dengan `available_at` baru.
- Bukti waktu pertama sebelum ingestion candidate tidak tersedia; tidak ada backdating.
- `fetched_at` tetap waktu pengambilan ulang, bukan waktu pertama tersedia.
- XML task lama disimpan di `storage/incidents/20261114_test_runner_wipe/HermesMarketIntelligence_EOD_pre_guarded_20261115.xml`.
- Action task EOD diganti ke `scripts/run_guarded_eod.bat` melalui `run_silent.vbs`.
- Runner memakai lock directory, identity gate pada `launch_ingestion_writer.php`, rate limit `30`, dan log terpisah.
- Task `HermesMarketIntelligence_EOD` enabled, state `Ready`.
- Action task: `wscript.exe` → `scripts/run_silent.vbs` → `scripts/run_guarded_eod.bat` → `scripts/run_guarded_eod.ps1`.
- Task account: `ASUS`; overlap policy remains `MultipleInstances=2`; runner lock prevents concurrent writer ownership.
- Manual exact-scope run: `CMNP,CMNT,CMRY,CNMA,COCO`, session `2026-11-13`, exit `0`, `Updated: 0`, `Already fresh: 5`, `new bars: 0`.
- Exact-scope repeat: same 5 tickers and session, exit `0`, `Already fresh: 5`, `Updated: 0`, `new bars: 0`.
- Canonical read-back: 10 rows, 5 tickers × 2 modes, no duplicate keys, unchanged hashes/prices/volumes, revision archive count `0`.
- Explicit idempotency scope VERIFIED. This proves local canonical idempotency after first provider run; it does not prove provider response stability beyond returned canonical rows.
- Launcher accepts `--tickers=T1,T2,...`, validates 1–20 unique tickers, and forwards selector to Artisan.
- Batch timeout is 600 seconds for max 20 tickers. Daily runner timeout is 1,800 seconds.
- Isolated news revision script `scripts/news_revision_isolated.php`: VERIFIED without candidate DB.
- No news scheduler task exists. Candidate news remains guarded-writer verified, but scheduled news activation is BLOCKED until one dedicated guarded news owner is registered.
- Trigger berkala EOD belum terjadi setelah task enabled; manual action and scheduler registration remain separate evidence.
- Task enabled because exact-scope idempotency, exit code, lock release, and resumable bounded batch gates passed.
- Candidate news remained unchanged by isolated test.

- Timestamp Job #4 belum terjelaskan; histori tidak diubah.
- Outbox `#5` tetap `DELIVERY_UNKNOWN`; retry tidak dilakukan.
- Backup candidate dan bukti insiden tetap dipertahankan.


## Pembaruan operasional — web identity, GOTO, batch EOD, dan news dedup

- Endpoint diagnostik read-only port `8000`: `/api/runtime-identity`.
- Web process PID: `18384`, listener `127.0.0.1:8000`.
- Web SQL identity: database `project_bot_recovered_20261115_022935`, user `aether_runtime`, schema `public`, search path `public`.
- Web privileges: `schema_create=false`, `news_mutation=false`, `observation_mutation=false`.
- Web read-back: BBCA latest bar `2026-11-13`, source `IDX Edge PRO`, board `RG`, validation `VALID`, finality `PROVISIONAL_POST_MARKET`; news count `5`, distinct URLs `5`.
- Web mode label `MODE PEMULIHAN` describes access mode, not database identity or data freshness.
- GOTO canary target `2026-11-13`: eligible `1`, updated `1`, new bars `5`, failed `0`, selected scope complete `YES`.
- GOTO repeat: already fresh `1`, updated `0`, new bars `0`; idempotency `VERIFIED`.
- EOD batch checkpoint 1: `20/20` eligible, `20` updated, `100` new bars, `0` failures, selected scope complete `YES`, universe coverage `NO`.
- EOD batch checkpoint 2: `20/20` eligible, `20` updated, `100` new bars, `0` failures, selected scope complete `YES`, universe coverage `NO`.
- Scope audit after batches: BBCA and GOTO raw/adjusted latest `2026-11-13`; GOTOM remains separate gap without registry deletion or denominator change.
- News repeat through guarded app writer ran twice. Candidate read-back remains `count=5`, `distinct_urls=5`, `raw_complete=5`, `timestamps_complete=5`; `first_seen_at` remained unchanged, URL deduplication held, and `related_tickers=[]` remained unmodified. `available_at` reflects latest successful fetch, while historical `published_at` remains unchanged.
- Raw archive SHA-256 values continue to match stored `raw_payload_hash` values.
- HTTP `/news` and `/market/BBCA`: `200`; browser rendered five news articles and BBCA session `2026-11-13`.
- `AetherWorkers_5min`: Ready, consultation/outbox ownership retained. `HermesMarketIntelligence_EOD`: Disabled.
- EOD automation remains HELD because scheduled action still invokes legacy `scripts/run_eod_pipeline.bat`, whose hard stop prevents plain Artisan writes. Manual guarded batches are not scheduler proof.
- No current-report publication activation. Finality remains provisional where provider does not attest finality.
- Timestamp Job #4 remains unresolved; raw historical records and serializer outputs were not changed.


## Pembaruan operasional 13 November 2026

### Penyebab dashboard kosong

Status: FIXED_AND_VERIFIED

Penyebab bukan data dashboard recovery kosong. Pengguna membuka `http://127.0.0.1:8000`, yang sebelumnya adalah proses legacy PID 2808 dengan konfigurasi efektif database `project-bot`, role `postgres`. Instance itu memiliki 1.000 observasi tetapi 0 registri emiten. Direktori pasar menjadi kosong karena registry tidak ada pada instance yang dipakai pengguna.

Instance kandidat sebelumnya memakai port 8010 dengan database pemulihan dan role baca-saja. Dua instance berbeda berjalan bersamaan. Ini membuat smoke test 8010 tidak membuktikan dashboard pengguna.

Tindakan:

1. Proses legacy port 8000 dihentikan.
2. Proses kandidat 8010 dihentikan.
3. `scripts/launch_readonly_dashboard.php --serve --port=8000` dijalankan.
4. Port utama tunggal sekarang `http://127.0.0.1:8000`.

Launcher menetapkan database `project_bot_recovery_20261110`, role `aether_runtime`, `PGOPTIONS=-c default_transaction_read_only=on`, storage/cache/log runtime di `%LOCALAPPDATA%\Aether-Market-Intelligence\recovery`, serta tidak memakai akun `postgres` rutin.

### Data yang terlihat pada URL utama

Status: FIXED_AND_VERIFIED untuk sampel BBCA; cakupan seluruh emiten tidak diekstrapolasi dari sampel.

Browser pada `http://127.0.0.1:8000/market/BBCA` menampilkan:

- Emiten: Bank Central Asia Tbk. / BBCA.
- Harga penutupan: Rp5.200.
- Bar EOD: 2026-11-06.
- Volume: 160.152.800 lembar.
- Sumber: `IDX Edge PRO`, board `RG`.
- Grafik adjusted dan raw tersedia melalui endpoint chart.
- 18 bar untuk tampilan 1 bulan, indikator ATR 14 dan RSI 14 tampil.
- Riwayat lokal memuat 30 bar untuk audit halaman; SMA20 tersedia, SMA50/SMA200 ditandai belum cukup.
- Fundamental halaman menampilkan 4 periode dan rasio yang tersedia.

Jejak data browser yang diperiksa: registry BBCA → `CanonicalMarketReadService`/`DashboardController` → `/api/market/BBCA/chart-data?range=1M&mode=adjusted` → canvas chart. Tidak ada console error pada halaman ringkasan. Endpoint raw dan adjusted merespons data dari sumber/board/validasi yang sama.

Sampel browser tab BBCA:

- Ringkasan: data harga, chart, indikator, fundamental tersedia.
- Pemantauan: satu rekam historis `ABSTAIN` tampil sebagai historis; status evaluasi tidak diklaim sebagai sinyal forward.
- Teknikal, Fundamental, Kesimpulan AI, News, Riwayat: HTTP 200 dan konten/empty state jelas.
- Broker: `DATA_NOT_AVAILABLE`; tidak diisi nol atau nilai sintetis.
- News: `0`; tidak diisi fallback palsu.
- Kesimpulan AI/histori: gunakan empty/fallback yang secara eksplisit membedakan ringkasan data dari inferensi Gemini ketika record tidak ada.

Semua tujuh endpoint tab BBCA merespons 200. Ini hanya bukti route dan konten sampel, bukan bukti semua emiten memiliki broker, berita, fundamental, atau analisis.

### Cakupan database recovery aktual

Status: VERIFIED untuk read-only audit dengan role `aether_ingestion`.

- `market_observations`: 513.381.
- `idx_company_registries`: 500.
- Rentang observasi: 2024-10-01 sampai 2026-11-06.
- `eod_session_queues`: 3, untuk 2026-11-04, 2026-11-05, 2026-11-06, seluruhnya `PARTIAL_BACKLOG`.
- `news_items`, `market_analyses`, `research_requests`, `broker_summaries`, dan `company_fundamentals` terbaca kosong pada audit shell default lama; dashboard browser recovery tetap membuktikan relasi BBCA/fundamental yang tersedia pada instance utama. Jangan memakai shell default untuk operasi recovery karena shell itu masih mewarisi `.env` legacy `project-bot/postgres`.

### Writer EOD dan backup

Status: BLOCKED_EXTERNAL / NOT_FIXED

`aether_ingestion` lulus verifikasi identitas ke database pemulihan dengan 513.381 observasi. Preview read-only membuktikan backlog 4–6 November 2026. Setiap sesi memiliki 499 ticker adjusted dan 497 ticker unadjusted, sehingga belum lengkap.

Canary tidak dijalankan. Backup baru wajib berhasil sebelum penulisan pertama. `scripts/create_recovery_backup.php` gagal fail-closed karena `aether_ingestion` tidak memiliki SELECT atas `analysis_runs`; `pg_dump` berhenti pada permission denied. Tidak ada grant luas ditambahkan dan tidak ada mutasi canary dilakukan.

Informasi yang dibutuhkan untuk melanjutkan: role backup khusus, read-only, dengan SELECT lengkap yang diperlukan `pg_dump`, tanpa INSERT/UPDATE/DELETE/DDL. Sesudah itu: buat backup, verifikasi `pg_restore --list`, jalankan satu canary ticker/sesi, read-back raw+adjusted+provenance+revision archive, ulangi canary sama untuk membuktikan idempotensi, baru batch terbatas.

### News provenance

Status: IMPLEMENTED_NOT_VERIFIED pada database recovery.

Provider RSS ANTARA merespons satu item nyata pada 13 November 2026. Adapter lama hanya memberi title, URL, summary, `published_at_raw`, dan waktu fetch; ia belum memberi raw RSS/hash/available_at.

Perubahan baru:

- Migrasi `2026_11_13_113000_add_provenance_to_news_items_table.php` menambah `first_seen_at`, `available_at`, `raw_payload_path`, `raw_payload_hash`, dan `source_feed_url`.
- Command `hermes:ingest-news --limit=N` menyimpan payload provider mentah yang dipakai aplikasi ke storage lokal, SHA-256, waktu tersedia/fetch, waktu publikasi provider bila dapat diparse, sumber feed, URL, serta deduplikasi URL.
- Status verifikasi provider dipertahankan. Artikel `unverified` tidak dinaikkan menjadi verified.

Test SQLite `NewsIngestionCommandTest`: 1 pass, 9 assertion. Migrasi belum diterapkan ke recovery karena runtime role memang baca-saja dan tidak ada role migrasi khusus. Tidak ada artikel recovery ditulis.

### Scheduler, Telegram, Gemini, dan realtime

Status: DISABLED / NOT VERIFIED.

Empat Windows Task Scheduler terkait masih Disabled: `AetherMarketMonitoring_15min`, `AetherScheduledDispatcher_1min`, `AetherWorkers_5min`, dan `HermesMarketIntelligence_EOD`. Tidak diaktifkan karena backup writer gagal dan Telegram E2E belum memiliki inbound numeric `update_id` serta outbound `message_id` nyata.

`routes/console.php` masih mendefinisikan writer/outbox/research jobs. Definisi internal bukan bukti scheduler sedang berjalan. Jangan jalankan `php artisan` langsung dari shell legacy karena dapat memakai `project-bot/postgres`; gunakan launcher eksplisit setelah role operasional dipisah.

Tidak ada inferensi Gemini nyata dijalankan. Tidak ada fallback disebut sukses. Tidak ada WebSocket/RG realtime diklaim aktif.

### Verifikasi perubahan pembaruan ini

- `php artisan test --compact tests/Feature/NewsIngestionCommandTest.php tests/Feature/DashboardTest.php tests/Feature/UniverseDiscoverySafetyTest.php tests/Feature/DatabaseTestIsolationGuardTest.php`: 20 pass, 108 assertion.
- `npm run build`: lulus, Vite 8.3.5.
- Syntax PHP command dan migrasi news: lulus.
- `git diff --check` pada file news baru: lulus.

### Menjalankan ulang setelah restart

Gunakan satu launcher utama dari root proyek:

`php scripts/launch_readonly_dashboard.php --serve --port=8000`

Buka `http://127.0.0.1:8000`. Jangan menjalankan server Laravel legacy pada 8000 atau 8010 dengan `.env` default. Dashboard ini sengaja masih read-only sampai gate backup, canary EOD, migrasi news, dan Telegram E2E terpenuhi.

### Backup role provisioning follow-up

Status: PARTIALLY_APPLIED_PENDING_VERIFICATION

Provisioning `aether_backup` dijalankan manual dan mencapai pemeriksaan akhir. Bukti yang dilaporkan sebelum kegagalan: target database benar, `is_superuser=false`, `bypass_rls=false`, `can_connect=true`, `can_use_public_schema=true`, `can_create_public_schema=false`, `rls_enabled_tables=0`, `rls_forced_tables=0`, dan `unreadable_tables=0`.

Kegagalan pada pemeriksaan sequence adalah defect script, bukan bukti grant sebelumnya dibatalkan: `ERROR: "pg_toast_60665" is not a sequence`. Query lama memakai OID `pg_class`; planner PostgreSQL dapat mengevaluasi fungsi privilege terhadap relasi non-sequence sebelum filter relkind diterapkan.

Perbaikan: `scripts/provision_aether_backup.sql` serta `scripts/verify_aether_backup.sql` sekarang membaca hanya `information_schema.sequences` dengan `sequence_schema = 'public'`. Tidak ada perubahan password atau grant tambahan dibuat oleh perbaikan ini.

`create_recovery_backup.php` kini hanya menerima credential DPAPI lokal `aether_backup` dari `%LOCALAPPDATA%\Aether-Market-Intelligence\recovery\credentials\aether_backup.dpapi.txt`; role ingestion tidak lagi dipakai untuk backup.

Backup role dan credential DPAPI kemudian terverifikasi. Dekripsi DPAPI exit 0, credential tidak kosong, secret tidak dicetak. Backup baru dibuat menggunakan `aether_backup`:

- Dump: `storage/backups/incident_20261110/project_bot_recovery_20261110_pre_recovery_20261113_124849.dump`.
- Exit code: 0.
- Ukuran: 45.557.982 byte.
- SHA-256: `d0ee1046660fd4222f4773d9d5730107025d241633063ce0c58cd8ec5174d343`.
- `pg_restore --list`: lulus; 407 TOC entry dan 39 `TABLE DATA` entry.
- Client `pg_dump` dan `pg_restore`: PostgreSQL 16.1.

Restore verification pertama membuat database terisolasi `aether_backup_verify_20261113_132749`. Database sumber tetap utuh dan `aether_backup` masih dapat membaca 513.381 observasi, 500 registry, hingga 2026-11-06.

Read-back target oleh `aether_backup` ditolak `permission denied for table market_observations`. Ini sesuai desain: role backup hanya diberi CONNECT pada database target recovery, bukan database verifikasi. Karena hasil restore tidak memiliki receipt admin, perbandingan tabel, count, constraint, dan sequence belum dapat dinyatakan VERIFIED.

Perbandingan pertama sebelumnya memberi foreign key sumber 0 dan restore 15. Count sumber lama memakai `information_schema.table_constraints`, yang dapat menyembunyikan constraint berdasarkan visibilitas role. Ini tidak membuktikan restore salah.

Verifier existing read-only baru: `scripts/verify_existing_recovery_restore.ps1`. Script menerima database verifikasi existing, memakai prompt administrator lokal untuk dua koneksi, dan tidak memuat `createdb`, `pg_restore`, atau `dropdb`. Ia membandingkan tabel, seluruh count tabel, index, foreign key dari `pg_catalog` dengan schema tabel `public` identik, definisi PK/unique/FK/check/exclusion, kolom NOT NULL, serta konfigurasi dan `last_value` sequence dari `pg_sequences`. Nilai sequence dibandingkan sebagai state sequence sendiri, bukan terhadap `MAX(id)`.

Script memverifikasi identity kedua koneksi, menyimpan receipt JSON tersanitasi, dan hanya menetapkan `VERIFIED` jika semua perbandingan lulus. Static contract dan syntax PowerShell lulus. Database `aether_backup_verify_20261113_132749` tidak disentuh oleh pembuatan verifier.

Migrasi recovery, canary writer, news ingest, Gemini, Telegram, dan scheduler tetap ditahan sampai receipt restore terisolasi `VERIFIED`.

### Hasil verifikasi restore terisolasi (VERIFIED)

`scripts/verify_existing_recovery_restore.ps1 -VerificationDatabase aether_backup_verify_20261113_132749` dijalankan pengguna dengan password administrator lokal (tidak melalui chat, tidak tercatat di command line).

Hasil: `status=VERIFIED`, `mode=VERIFY_EXISTING_READ_ONLY`.

Seluruh pembanding bernilai `true`: `server_version`, `summary`, `table_counts`, `constraints`, `not_null_constraints`, `indexes`, `sequences`.

Angka sumber dan restore identik:

- Tabel `39`, index `163`, foreign key `15`, sequence `34`.
- `market_observations` `513381`, `idx_company_registries` `500`, `max_bar_date` `2026-11-06`.
- `evidence_snapshots` `151`, `prediction_signals` `16`.
- Constraint `78` (PK/unique/FK), NOT NULL `403`, definisi index `163`, sequence `34` dengan `last_value` sama.

Selisih foreign key `0` versus `15` sebelumnya adalah artefak query `information_schema` lama, bukan kerusakan restore. Verifier baru membaca `pg_catalog` dengan filter schema `public` dan definisi constraint, sehingga kedua sisi terbaca `15`.

Receipt JSON tersimpan oleh script penerima (path receipt dicetak script saat dijalankan). Database verifikasi tidak diubah oleh verifier.

### Migrasi recovery

Status: BELUM_DITERAPKAN, script siap.

`migrations` berisi `32` baris, file migrasi `34`. Dua migrasi tertunda:

- `2026_11_13_090000_create_universe_discovery_staging_tables`
- `2026_11_13_113000_add_provenance_to_news_items_table`

Bukti state: `universe_discovery_runs` dan `universe_discovery_items` tidak ada; `news_items.first_seen_at` tidak ada; `news_items` berisi `0` baris; `market_observations` tetap `513381`; `idx_company_registries` tetap `500`. Tabel lama `staging_universe_discovery` (`963` baris) adalah tabel berbeda, bukan hasil migrasi baru.

Tidak ada role migrasi yang dapat dipakai tanpa password: tabel `public` dimiliki `postgres`, dan `aether_ingestion`/`aether_runtime` bukan owner sehingga tidak boleh `ALTER TABLE`. Karena itu migrasi dijalankan lewat script yang meminta password administrator lokal secara tersembunyi.

Script baru:

- `scripts/apply_recovery_migrations.php` — gate identitas in-process (`current_database` harus `project_bot_recovery_20261110`, user harus `postgres` atau `aether_migrator`), menolak standby replica, membandingkan file migrasi versus tabel `migrations`, menolak bila ada baris migrasi tanpa file, mendukung `--pretend` dan `--apply`.
- `scripts/apply_recovery_migrations.ps1` — prompt password tersembunyi, mengisi `DB_*` hanya pada lingkungan proses anak, menghapus `DASHBOARD_CANDIDATE`, menjalankan dry-run lebih dulu lalu apply.

Catatan teknis: `dashboard/bootstrap/cache/config.php` dihapus sebelum boot agar override `DB_*` berlaku (config cache akan mengunci kredensial legacy `project-bot/postgres`), lalu dibuat ulang otomatis oleh `register_shutdown_function` setelah proses selesai.

Uji negatif: dengan password salah, script berhenti `MIGRATION_ABORT connection failed` pada gate sebelum ada DDL, `config.php` pulih, tidak ada mutasi database. Syntax PHP dan PowerShell lulus.

Perintah (dijalankan pengguna, password tidak masuk chat):

```
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "E:\Aether-Market-Intelligence\scripts\apply_recovery_migrations.ps1"
```

### Perbaikan EOD dan uji idempotensi

Tiga defect ditutup, dengan test regresi:

1. `resolveExpectedSessionDate()` salah window. Syarat lama `$nowWib->hour >= 16 && $nowWib->minute >= 5` membuat pukul `17:00`–`17:04` WIB kembali ke sesi kemarin. Kini memakai perbandingan `$nowWib->greaterThanOrEqualTo(setTime(16, 5))`.
2. Insert bar idempoten pada jalur balapan. `ingestBars` dan `ingestNominalBars` memakai pola cek-lalu-insert; bila dua worker menulis bar kanonik yang sama, insert kedua melempar unique violation. Kini `QueryException` SQLSTATE `23505` diperlakukan sebagai "sudah ada" dan tidak menggagalkan run. Unique index `uq_obs_canonical_eod_v2` tetap menjadi pengaman akhir.
3. Finalitas tidak pernah naik. Semua bar ditulis `is_eod_final=false` dengan `finality_basis=PROVISIONAL_POST_MARKET` dan tidak ada jalur promosi, sehingga konsumen yang memfilter `is_eod_final=true` (mis. `MarketMonitoringService`, `DataFreshnessPolicyService`) tidak pernah melihat data sesi. Ditambahkan `promoteSupersededBars()`: bar yang sudah disusul sesi lebih baru dipromosikan ke `is_eod_final=true` dengan `finality_basis=SUPERSEDED_BY_NEWER_SESSION`; bar sesi terbaru tetap provisional.

Batas yang disengaja (dicatat di kode): finalitas mengikuti progres sesi provider, bukan rekonsiliasi settlement resmi BEI. Rekonsiliasi file settlement perlu ditambahkan bila sumber resmi terverifikasi tersedia.

Bukti test:

- `tests/Unit/DailyEodRefreshUpsertAndRevisionTest.php`: 9 pass, 50 assertion (termasuk 2 test baru: cut-off 17:00 dan promosi finalitas).
- Suite gabungan news + dashboard + discovery + isolation guard + EOD: 29 pass, 158 assertion.

### Canary EOD

`php scripts/launch_ingestion_writer.php --dry-run --session=2026-11-06` (role `aether_ingestion`, identitas SQL terverifikasi) memberi:

```
DRY_RUN session_date: 2026-11-06
DRY_RUN is_trading_day: true
DRY_RUN session_has_closed: true
DRY_RUN can_register_queue: true
DRY_RUN can_fetch_eod_now: true
DRY_RUN calendar_reason: NONE
DRY_RUN queue_exists: true
DRY_RUN queue_status: PARTIAL_BACKLOG
DRY_RUN would_register_pending: false
```

Canary fetch nyata tidak dapat dijalankan: `IDX_EDGE_ENABLED=false` dan tidak ada kredensial provider terverifikasi di `.env` dashboard. Status: `BLOCKED_EXTERNAL`. Idempotensi dibuktikan pada level unit (run kedua tidak menambah bar, revisi provider terarsip, promosi finalitas deterministik), bukan pada data provider live.

### Gemini

Status: FIXED_AND_VERIFIED (konektivitas dan konten).

Pemeriksaan live minimal (satu prompt, `maxOutputTokens=128`, tanpa menulis database, key tidak dicetak):

- `http_status` `200`, `finish_reason` `STOP`, `reply_text` `OK`, latensi `10.117 ms`, `totalTokenCount` `114` (termasuk `thoughtsTokenCount` `108`).
- Model `gemini-3.6-flash` pada `generativelanguage.googleapis.com` merespons normal.

Catatan: percobaan pertama timeout 45 detik dan percobaan dengan `maxOutputTokens=16` berhenti `MAX_TOKENS` karena token thinking menghabiskan budget. Ini perilaku model, bukan kegagalan auth.

### Telegram dan scheduler

Status: `IMPLEMENTED_NOT_VERIFIED` / tetap Disabled.

- `dashboard/.env` hanya memuat `TELEGRAM_CHAT_ID` lewat default config; tidak ada bot token di aplikasi. Pengiriman nyata bergantung pada transport Hermes CLI: `hermes send --to telegram:<chat_id> --file <tmp> --json`, dan message_id non-numerik dianggap gagal (`Transport sukses tanpa receipt Telegram message_id numerik.`).
- Transport Hermes juga belum terkonfigurasi: `%LOCALAPPDATA%\hermes\config.yaml` hanya punya `platforms.telegram.extra.drop_pending_on_cold_boot`, tanpa entri token (`grep -c 'bot_token|token_file|BOT_TOKEN'` = `0`), env `TELEGRAM_BOT_TOKEN` tidak ada, dan tidak ada direktori `credentials`. CLI tersedia (`Hermes Agent v0.21.5`), gateway Telegram tidak. Status: `BLOCKED_EXTERNAL`.
- Belum ada inbound `update_id` nyata dan pasangan outbound `message_id` nyata, sehingga Telegram E2E tidak dinyatakan lulus.
- Empat scheduled task masih `Disabled`: `AetherMarketMonitoring_15min`, `AetherScheduledDispatcher_1min`, `AetherWorkers_5min`, `HermesMarketIntelligence_EOD`.

Syarat aktivasi yang sudah terpenuhi: backup+restore `VERIFIED`, EOD idempotensi/finalitas terbukti di level test, migrasi siap.

Syarat yang belum terpenuhi: migrasi belum diterapkan, news ingest belum berjalan nyata, canary EOD live terblokir provider, Telegram E2E belum ada receipt.

## Pembaruan lanjutan 13 November 2026 (EOD live, role, template)

### Receipt restore dicatat

Receipt `storage/incidents/20261110_operational_data_loss/recovery-existing-restore-verification-20261113_140505.json` dibaca langsung dari disk.

- `status=VERIFIED`, `mode=VERIFY_EXISTING_READ_ONLY`.
- `comparisons`: `server_version`, `summary`, `table_counts`, `constraints`, `not_null_constraints`, `indexes`, `sequences` semuanya `true`.
- Sumber `project_bot_recovery_20261110`, target `aether_backup_verify_20261113_132749`.
- Kedua sisi: `39` tabel, `163` index, `15` FK, `34` sequence, `513381` observasi, `500` registry, `151` evidence snapshot, `16` prediction signal, `max_bar_date` `2026-11-06`.

Backup/restore tidak diulang. Tidak ada provisioning ulang.

### Canary EOD live dan pembersihan backlog sesi

Mode `--canary` dan `--batch` ditambahkan ke `scripts/launch_ingestion_writer.php`:

- `--canary` mensyaratkan `--session=YYYY-MM-DD` dan tepat satu `--tickers=CODE` (regex `^[A-Z]{4}$`).
- `--batch` mensyaratkan `--session` dan `--limit=1..300`, dengan `--rpm` opsional `1..30`.
- `IDX_EDGE_ENABLED=true` hanya diinjeksi ke lingkungan proses anak; master switch `.env` tidak diubah.
- STDIN proses anak memakai pipe yang langsung ditutup, sehingga launcher aman dijalankan non-interaktif.

Hasil nyata:

1. Audit coverage menemukan backlog `2026-11-04..06` hanya pada dua ticker: `PSAT` dan `RATU` (missing mode `UNADJUSTED`), konsisten dengan `499` adjusted vs `497` nominal.
2. Canary `PSAT` sesi `2026-11-06` mengisi bar nominal dari provider (`fetched_at` `2026-11-13 07:54:59` UTC).
3. Canary `PSAT` diulang: `Total new bars ingested: 0`, dan `fetched_at` bar yang sama tidak berubah → tidak ada penulisan ulang (idempotensi terbukti pada data live).
4. Canary `RATU` menutup coverage; `eod_session_queues` `2026-11-06` berubah `PARTIAL_BACKLOG` → `COMPLETED`.
5. Canary `2026-11-05` dan `2026-11-04` membuat kedua antrean itu `COMPLETED`.
6. Coverage ulang: `2026-11-04` `0` missing, `2026-11-05` `0` missing, `2026-11-06` `0` missing.

Kuota provider terpantau dari ledger cache: `idx-edge:quota:used:2026-11-13` = `2` unit setelah canary (respons identik dilayani cache), entitlement limit `31000`, remaining `30995`. Kunci ledger memakai prefix `aether-market-intelligence-cache-`.

Backlog sesi berikutnya ditemukan: `2026-11-09..2026-11-12` hanya terisi `2` ticker (dari canary yang mengambil overlap 20 bar). Batch pemulihan dijalankan bertahap lewat `--batch` dengan `--rpm=30` setelah uji `--limit=10` memberi `Updated: 10`, `Fetch errors: 0`, `40` bar baru tanpa `429`.

### Defect EOD tambahan yang ditutup

Jalur "cek fresh" dapat mengisi bar nominal yang hilang namun tetap melaporkan `Already fresh: 1`, `Updated: 0`, `new bars: 0` — laporan tidak sesuai fakta tulis. Kini hasil pengisian itu dihitung sebagai `updated` dengan checkpoint `NOMINAL_BACKFILLED` dan status registry `UPDATED`.

Test: `tests/Unit/DailyEodRefreshUpsertAndRevisionTest.php` menjadi 10 pass / 55 assertion (tambah 1 test `test_missing_nominal_bar_refilled_on_fresh_path_is_reported_as_updated`).

### Pemisahan role: temuan penting

Peta privilege aktual pada `project_bot_recovery_20261110`:

- `aether_ingestion`: `INSERT/UPDATE/SELECT` hanya pada `market_observations`, `idx_company_registries`, `eod_session_queues`, `data_revision_archives`, `company_analysis_metrics`.
- `aether_migrator`: `INSERT/UPDATE/SELECT` pada seluruh tabel utama, termasuk `news_items`, `analysis_runs`, `market_analyses`, `broker_summaries`, `telegram_outbox`.
- `aether_runtime`: `SELECT` saja (read-only ketat).

Konsekuensi:

- Migrasi additive tetap butuh `postgres` karena `ALTER TABLE` hanya boleh dilakukan owner tabel, dan seluruh tabel `public` dimiliki `postgres`. Grant DML tidak menutup kebutuhan ini.
- Ingestion berita, inferensi AI tersimpan, dan broker summary tidak dapat dijalankan oleh `aether_ingestion`; `permission denied for table analysis_runs` terbukti saat percobaan inferensi pertama.
- Tidak ada credential `aether_migrator` tersimpan di DPAPI, sehingga jalur tulis aplikasi belum dapat dipakai tanpa satu langkah lokal.

### prompt_templates

Keputusan: jalur analisis tidak memerlukan pengisian template untuk berfungsi.

`AiMarketAnalysisService::analyze()` mengambil `PromptTemplate` kode `MARKET_INTELLIGENCE_DAILY`; bila tidak ada, ia memakai prompt bawaan di kode (`'You are Aether Market Intelligence AI...'` dan `'Analisis data pasar:\n{market_summary}'`), lalu menyimpan `prompt_template_id=null` dan `prompt_version='1.0.0'`. Jadi tabel kosong adalah degradasi yang disengaja, bukan kegagalan. Seeder umum tetap tidak dijalankan pada database recovery; pengisian template, bila diperlukan untuk audit prompt, harus lewat migrasi data terarah dan ditinjau.

### Broker summary

`broker_summaries` masih `0` baris. Penyebab bukan bug kode: endpoint `broker` aktif secara default (`IDX_EDGE_BROKER_ENABLED=true`) tetapi master switch provider `IDX_EDGE_ENABLED=false`, dan tidak ada ticker yang pernah dijalankan melalui `SyncIdxEdgeBrokerCommand`. Penulisan `broker_summaries` hanya mungkin lewat peran dengan grant DML, yaitu `aether_migrator`. Status: `BLOCKED_EXTERNAL` untuk data, `IMPLEMENTED_NOT_VERIFIED` untuk jalur tulis.

### Gemini tersimpan

Verifikasi konektivitas: `FIXED_AND_VERIFIED` (HTTP `200`, `finish_reason=STOP`, balasan `OK`).

Verifikasi persistensi: `BLOCKED_EXTERNAL`. Percobaan menulis `analysis_runs` lewat `aether_ingestion` gagal `permission denied for table analysis_runs`; nilai itu benar secara desain. Dibutuhkan credential `aether_migrator` sebelum inferensi nyata dapat disimpan dan ditampilkan pada tab `ai_insight`.

### Langkah lokal minimum yang dibutuhkan

Dua perintah, masing-masing meminta input tersembunyi, tidak menulis secret ke chat, dan tidak memakai `postgres` untuk operasi rutin:

1. Migrasi additive (butuh owner `postgres` karena `ALTER TABLE`):
   `powershell.exe -NoProfile -ExecutionPolicy Bypass -File "E:\Aether-Market-Intelligence\scripts\apply_recovery_migrations.ps1"`
2. Simpan password role `aether_migrator` ke DPAPI (sekali saja):
   `powershell.exe -NoProfile -ExecutionPolicy Bypass -File "E:\Aether-Market-Intelligence\scripts\save_aether_migrator_secret.ps1"`

Setelah (2) selesai, ingestion berita, inferensi AI tersimpan, dan broker summary dijalankan lewat role migrator tanpa `postgres`.

### Cleanup probe Telegram sintetis

Status: `VERIFIED`.

Percobaan `remove_synthetic_telegram_probe.ps1 -Apply` menghasilkan `psql.exe: ERROR: syntax error at or near "$"`. Ini bukan kegagalan password PostgreSQL. SQL delete memakai PowerShell double-quoted here-string dengan `DO `$`, sehingga PostgreSQL menerima `DO $` dan penutup `$;`, bukan dollar quote PL/pgSQL `DO $$ ... $$;`.

Perbaikan memakai single-quoted here-string agar `DO $$ ... $$;` utuh, menulis SQL UTF-8 tanpa BOM ke file sementara, lalu menjalankan `psql --file`, bukan `--command`. Jika `psql` gagal, file SQL diagnostik tanpa credential dipindahkan ke directory cleanup. Archive dibaca ulang dan identity record diverifikasi sebelum transaksi DELETE.

Cleanup ulang oleh administrator lokal berhasil: `status=synthetic_telegram_probe_removed`, `remaining_synthetic_probe=0`. Receipt archive: `storage/incidents/20261110_operational_data_loss/telegram_probe_cleanup/synthetic-telegram-probe-20261113-215705.json`.

Audit read-only pasca-cleanup membuktikan DB `project_bot_recovery_20261110`, role `aether_ingestion`, `conversation_count=0`, `exact_cleanup_candidate_count=0`, dan related `scheduled_telegram_jobs`, `telegram_outbox`, serta `notification_logs` masing-masing `0`. Scope archive tetap record tunggal: update `999999991`, inbound message `999999992`, `/status`, tanpa outbound receipt.

Static cleanup contract, `git diff --check`, parser Windows PowerShell 5.1, serta archive JSON UTF-8 tanpa BOM lulus. Runtime test PostgreSQL terisolasi tetap unavailable karena `TEST_PG_DATABASE`, `TEST_PG_USERNAME`, dan `TEST_PG_PASSWORD` tidak tersedia; cleanup produksi dinyatakan VERIFIED dari receipt administrator dan read-back exact scope.

### Aktivasi Telegram dan gateway

Status: `BLOCKED_RUNTIME`; Telegram E2E dan scheduler tetap Disabled.

Profile `aether-prod` kini memiliki `platforms.telegram.enabled=true` dan `drop_pending_on_cold_boot=false`. Default profile Telegram dinonaktifkan agar satu token tidak dipoll dua receiver. Plugin active instance cocok dengan source dan memakai `scripts/launch_app_writer.php`, JSON inbound receipt, DB recovery `project_bot_recovery_20261110`, dan role `aether_ingestion`. Test router Python `1/1`, launcher contract, serta Laravel `HermesTelegramChatCommandTest` `2/2` lulus.

Restart gateway gagal memulai process stabil. Penyebab aktual: `hermes: no dependency environment is committed for this install; run hermes pm repair`. `hermes pm repair` lalu gagal dengan `WinError 5` pada `C:\Users\ASUS\AppData\Local\hermes\tools\.displaced-b875f8b7244644a19e5ffc1a6729bf61\DLLs\libcrypto-3-x64.dll`. Tidak ada receiver aktif. Tidak ada klaim polling sehat atau Telegram E2E.

Gateway runtime sekarang `VERIFIED`, tetapi Telegram E2E Aether tetap `PENDING`. Default profile Telegram `false`; `aether-prod` `true`; satu host multiplexer PID `19792` hanya memiliki adapter `aether-prod:telegram` berstatus `connected`. Plugin byte-identik dimuat dari `C:\Users\ASUS\AppData\Local\hermes\profiles\aether-prod\plugins\aether_router`; log profile mencatat `Aether Router MessageHandler wired successfully into PTB Application.` dan `[Telegram] Wired native handlers from plugin 'aether_router'` pada 17:50:10 WIB. Bridge memakai `scripts/launch_app_writer.php`, DB recovery `project_bot_recovery_20261110`, dan role `aether_ingestion`.

Pesan BBCA pertama bukan E2E Aether: gateway log membuktikan core Hermes mengonsumsinya pada 17:22:21 WIB dan mengirim respons 4.212 karakter pada 17:23:59 WIB. Handler Aether baru ter-wire pada 17:40:57 WIB. Dua cold boot setelah itu mencatat `drop_pending_on_cold_boot=false` dan tidak ada dispatch `AetherRouter`; update tersebut telah melewati polling offset dan tidak akan direplay. Tidak ada offset mundur, replay otomatis, atau update sintetis.

Percobaan E2E berikutnya harus memakai satu pesan baru, dicatat terpisah. Status `VERIFIED` hanya jika numeric inbound `update_id`, inbound `message_id`, persisted conversation/job/analysis, outbound numeric `message_id`, dan persisted receipt exact cocok.

### Percobaan Telegram E2E-2 dan worker konsultasi

Status: `NOT VERIFIED`; scheduler tetap Disabled.

Bridge menerima percobaan E2E-2 dengan `update_id=496963058` dan inbound `message_id=654`. Ia menyimpan conversation `#2`, membentuk `ScheduledTelegramJob #1` dengan `request_type=ASYNC_CONSULTATION`, lalu mengirim ACK Telegram `message_id=655`; receipt ACK tersimpan. SQL identity bridge dan audit adalah database `project_bot_recovery_20261110`, role `aether_ingestion`.

Intent awal tercatat `QUERY_BROKER_FLOW`; ACK menyebut broker summary. Ini routing salah untuk pertanyaan analisis BBCA umum, bukan sekadar teks ACK salah. Akar penyebabnya predicate broker yang menganggap kata `keterbatasan` sebagai broker flow. Predicate kini hanya menerima istilah broker eksplisit; query analisis ticker umum diprioritaskan sebagai `QUERY_TICKER_STATUS`, follow-up screening lintas ticker tetap `SCREENING_BROKER_ACCUMULATION`, dan ticker katalog dengan `day trade` tidak lagi salah menjadi screening intraday. Regression `CrossTickerRoutingAndTickerValidationTest` lulus `13/13`, `58` assertions pada SQLite. Worker tidak berjalan karena scheduler Disabled. Job `#1` tetap `PENDING` tanpa `worker_pid`, heartbeat, lease, attempt, analysis, evidence, outbox, atau error sampai worker fenced satu-job dijalankan. Saat itu deadline 15 menit telah lewat; worker menandai job `MISSED` dan membuat outbox pemberitahuan deadline `#5`.

Historis insiden sebelum candidate cutover: dispatcher gagal sebelum claim karena grant `aether_ingestion` belum efektif untuk `telegram_outbox FOR UPDATE`; audit saat itu menemukan tabel Telegram kosong. Fakta ini tidak menggambarkan state candidate saat ini.

PostgreSQL log historis mencatat dua permission error dispatcher, tanpa bukti `DELETE` atau `TRUNCATE`. Grant script hanya `GRANT/REVOKE`. State candidate terkini dibuktikan pada audit Job `#2`, Evidence `#153`, dan Outbox `#6`.

### Perbaikan akses Python Hermes

Status: `FIXED_AND_VERIFIED` untuk runtime Python; Chromium tetap masalah terpisah.

`hermes pm doctor` awalnya menunjukkan Python `PASS` hanya pada PowerShell elevated, sedangkan PowerShell biasa menerima `UnauthorizedAccessException` saat mengakses Python internal Hermes. Penyebab terbukti pada entry Python: `C:\Users\ASUS\AppData\Local\hermes\tools\python-3.14.7+202****0901-win32-x64` memiliki inheritance protected dan ACL hanya `SYSTEM`, `Administrators`, serta `OWNER RIGHTS`. Token standar `DIMZZZ\ASUS` tidak memiliki `ReadAndExecute`.

Perbaikan dibatasi pada entry Python tersebut dan seluruh child: explicit `DIMZZZ\ASUS:(OI)(CI)RX`. Inheritance entry tetap protected; tidak ada `Everyone`, perubahan AppData global, maupun grant tulis. `hermes pm doctor` sesudahnya mengonfirmasi Python `✓ 3.14.7+202****0901`.

Doctor masih gagal hanya untuk Chromium dengan `realized bytes do not match recorded digest`. Ini adalah mismatch tree digest pada entry `chromium-1208`, bukan bukti Python rusak. Digest fakta tetap `0196875d5cb3c08e1ab029722253d1eec471920c64976416ce087e28ac4a5559`; hash tidak diubah atau dilewati.

### Pembaruan isolasi test 14 November 2026 (sesi pemulihan)

Penguatan isolasi test setelah insiden data:

1. `dashboard/bootstrap/app.php` diperkuat: test runner yang tidak memuat `phpunit.xml` resmi
   (ditandai `AETHER_TEST_CONFIG_APPROVED=1` oleh `dashboard/phpunit.xml`) berhenti sebelum
   Laravel bootstrap dengan exit 78. Sebelumnya hanya memaksa SQLite; sekarang seluruh akses
   DITOLAK dulu.

2. `dashboard/phpunit.xml` menambahkan `<env name="AETHER_TEST_CONFIG_APPROVED" value="1"/>`.
   PHPUnit tanpa config ini tidak mendapat marker tersebut dan gagal sebelum test.

3. `bootstrap/app.php` juga menghapus `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` dari
   environment proses sehingga test tidak dapat mewarisi kredensial PostgreSQL operasional
   bahkan jika cache config tidak bisa dibersihkan.

4. `APP_CONFIG_CACHE` di-override ke `bootstrap/cache/testing-config.php` (bukan direktori
   proyek) agar config cache produksi tidak termuat selama test.

Bukti terverifikasi:

- Root tanpa `-c`: exit 2, `SECURITY FAIL-CLOSED: PHPUnit must load dashboard/phpunit.xml.`
- Root dengan `-c dashboard/phpunit.xml`: `2 passed, 4 assertions` (SQLite).
- Direktori `dashboard` tanpa `-c`: `2 passed, 4 assertions` (SQLite).
- `aether_test_runner`: non-superuser, tidak dapat CONNECT `project_bot_recovery_20261110`.
- `aether_ingestion`, `aether_runtime`, `aether_backup`: tidak dapat CONNECT `aether_test_isolated`.

Mekanisme pencegahan berlapis (bukan hanya satu guard):

a. PHPUnit exit sebelum Laravel bootstrap jika config resmi tidak dimuat.
b. Jika bypass dengan env `APP_ENV=testing` tanpa marker, AppServiceProvider mendeteksi
   runner dan menolak koneksi non-SQLite.
c. Jika config cache ada, `DB_*` PostgreSQL dihapus dari environment sebelum cache dimuat.
d. `aether_test_runner` tidak memiliki CONNECT ke database operasional secara database-level.

### Audit administrator read-only 14 November 2026 (VERIFIED)

Receipt: `storage/incidents/20261114_test_runner_wipe/admin_readonly_audit_20261114_200918.txt`
Identitas: `project_bot_recovery_20261110`, role `postgres`, waktu `2026-11-14 20:09:22 WIB`.
Mode: `BEGIN READ ONLY`, tanpa restore, grant, DDL, DELETE, atau TRUNCATE.

Temuan admin read-only (exact count, bukan estimasi):

- 40 tabel `public`; seluruhnya dimiliki `postgres`.
- Semua 39 tabel data bernilai `0` (exact count via `\gexec`). `migrations` = `34`.
- `cache.estimated_dead_rows=31`, `cache_locks.estimated_dead_rows=10`,
  `idx_company_registries.estimated_dead_rows=1`, `telegram_conversations.estimated_dead_rows=3`.
  Dead rows konsisten dengan data pernah ditulis lalu hilang, bukan tabel yang belum pernah dipakai.
- `telegram_conversations_id_seq.last_value=3` terbukti via admin: sequence telah digunakan
  tiga kali, tetapi tabel conversations kosong.
- Struktur saat ini: `150` constraint, `170` indeks, `40` tabel.
- Baseline receipt `20261113_140505`: `39` tabel, `163` indeks, `15` FK, `34` sequence.
- Gap struktur dibanding baseline: 1 tabel baru, 7 indeks baru, sequence turun dari 34 menjadi 12.
  Gap ini konsisten dengan migrasi `create_universe_discovery_staging_tables` pernah diterapkan
  (tambah 2 tabel, beberapa indeks) lalu seluruh data dan sebagian sequence hilang.
- `aether_ingestion` punya SELECT/INSERT/UPDATE pada tabel Telegram; DELETE=false. REVOKE tidak
  terbukti terjadi setelah grant diterapkan.
- Penyebab hilangnya data: jalur PHPUnit root tanpa `-c` adalah jalur risiko terbukti.
  Command exact, role efektif, dan seluruh rantai mutasi tetap `NOT VERIFIED`.

### Paket pemulihan kandidat

Script baru: `scripts/restore_baseline_to_new_candidate.ps1`

Kontrak:
- Memverifikasi SHA-256 dump wajib = `d0ee1046660fd4222f4773d9d5730107025d241633063ce0c58cd8ec5174d343`.
- Hanya membuat DB dengan nama `project_bot_recovered_yyyyMMdd_HHmmss`; tidak bisa diparameterkan
  ke nama sembarang.
- Menolak jika DB target sudah ada.
- Tidak ada `dropdb`, `--clean`, `--if-exists`, GRANT, REVOKE, migrasi, atau akses outbox.
- Memverifikasi hasil restore: 39 tabel, 163 indeks, 15 FK, 34 sequence, 513381 observasi,
  500 registry, 151 evidence, 16 prediksi, `max_bar_date=2026-11-06`.
- Menyimpan receipt JSON tersanitasi ke `storage/incidents/20261114_test_runner_wipe/`.
- Parser PowerShell PASS. Contract scan PASS.
- Dump lama dan DB insiden tidak disentuh.

Jalankan (membutuhkan password administrator lokal, tidak melalui chat):

```
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "E:\Aether-Market-Intelligence\scripts\restore_baseline_to_new_candidate.ps1"
```

Setelah receipt `VERIFIED_BASELINE_CANDIDATE`, langkah berikutnya (masing-masing terpisah):
1. Jalankan `fix_dashboard_read_permissions_20261114.sql` via admin untuk grant runtime dan backup.
2. Jalankan `apply_recovery_migrations.ps1` untuk 2 migrasi additive tertunda.
3. Perbarui target database pada semua launcher dan bridge.
4. Verifikasi SQL identity aktual dari web, worker, dan bridge setelah switch.
5. Rekonsiliasi outbox Telegram yang ada; jangan redispatch otomatis outbox lama.
6. Siapkan worker konsultasi fenced, buktikan berjalan via `launch_app_writer.php`, baru minta E2E-3.

E2E-3 tetap blocked sampai langkah 1–5 selesai dan receipt kandidat diperoleh.

Status sesi 15 November 2026:

- Receipt `VERIFIED_BASELINE_CANDIDATE` dibaca: kandidat `project_bot_recovered_20261115_022935`, semua pembanding cocok.
- Launcher dashboard, bootstrap recovery, app writer, ingestion writer, dan migration helper diarahkan ke kandidat unik.
- Cache konfigurasi writer/migrasi dipindahkan ke runtime luar repository.
- Paket `scripts/activate_candidate_database.ps1` dibuat dan diperiksa: preflight ACL, migration pretend/apply, grant minimum, serta read-back identity/privilege/count.
- Parser PowerShell, SQL contract, PHP lint, dan `git diff --check` lulus.
- Aktivasi belum berjalan karena prompt password administrator lokal tidak dijawab. Tidak ada grant, migrasi, restore, atau perubahan database pada sesi ini.
- Dua server lama masih listen pada port 8000. Cutover belum dilakukan agar tidak mematikan instance tanpa aktivasi kandidat yang berhasil.
- Outbox/job kandidat belum direkonsiliasi melalui dispatcher. Tidak ada pesan lama dikirim ulang.
- Scheduler tetap Disabled. E2E-3 tetap blocked.

Perintah lokal yang belum dijalankan:

```
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "E:\Aether-Market-Intelligence\scripts\activate_candidate_database.ps1"
```

Perintah meminta password administrator melalui prompt tersembunyi. Jangan kirim password melalui chat.


Audit read-only terbaru memakai identitas SQL `project_bot_recovery_20261110` / `aether_ingestion` / schema `public`. Hasil disimpan pada `storage/incidents/20261114_test_runner_wipe/recovery_audit_20261114.json`.

Bukti saat ini:

- Catalog PostgreSQL menemukan `40` tabel `public`, `150` constraint, `170` indeks, dan `12` sequence.
- Semua `40` tabel dimiliki `postgres`.
- Writer dapat membaca `19` tabel grant operasional. Semua `19` bernilai `0`, termasuk `market_observations`, registry, evidence, prediction, analysis, conversation, job, dan outbox. Tidak ada rentang tanggal tersisa pada tabel tersebut.
- `21` tabel lain belum dapat dihitung oleh writer karena `SELECT` memang tidak diberikan. Status count tabel tersebut: `NOT VERIFIED`, bukan diklaim kosong.
- Receipt restore read-only `20261113_140505` sebelumnya menyatakan baseline sumber memiliki `39` tabel, `163` indeks, `15` FK, `34` sequence, `513381` observasi, `500` registry, `151` evidence, `16` prediksi, dan `max_bar_date=2026-11-06`.
- Maka minimal record yang terbukti tidak lagi tersedia melalui writer: `513381` observasi, `500` registry, `151` evidence, `16` prediksi, `139` analysis run, `135` market analyses, serta semua record Telegram yang dapat dibaca.
- Structure berubah versus receipt baseline: `40` tabel/`170` indeks/`12` sequence saat ini versus `39`/`163`/`34` pada receipt. Penyebab perubahan struktur belum teratribusi penuh.
- Sequence `telegram_conversations_id_seq=3`, tetapi tabel conversation kosong. Ini konsisten dengan record pernah dibuat lalu hilang, tetapi bukan bukti metode penghapusan.
- Log PostgreSQL tidak merekam `DELETE` atau `TRUNCATE` untuk tabel Telegram. Tidak ada audit statement lengkap untuk mengatribusikan seluruh perubahan data atau struktur.

Akar penyebab yang didukung bukti:

- Jalur PHPUnit dari root tanpa `-c dashboard/phpunit.xml` sebelumnya memakai config default non-test dan pernah memungkinkan trait `RefreshDatabase` menjangkau koneksi default. Ini adalah jalur risiko terbukti.
- Waktu penciptaan file relation utama di PostgreSQL adalah `2026-11-14 18:40:16 WIB`; checkpoint PostgreSQL mulai `18:40:44 WIB` lalu menulis `1048` file sync.
- Bukti tersebut tidak sendiri membuktikan command tepat, role efektif, atau seluruh rantai mutasi. Claim command wipe spesifik dan role `postgres` ditandai `NOT VERIFIED` sampai audit admin/statement log lengkap tersedia.

Proteksi test sekarang:

- `AppServiceProvider` mengaktifkan guard untuk `APP_ENV=testing`, `runningUnitTests()`, konstanta PHPUnit, atau argv PHPUnit.
- Test hanya menerima SQLite `:memory:` atau database PostgreSQL bernama `_test_` dengan role non-superuser.
- Dari root tanpa `-c`, test ditolak `SECURITY FAIL-CLOSED` sebelum test berjalan; exit `2`.
- Dari root dengan `-c dashboard/phpunit.xml`, test guard lulus di SQLite in-memory: `2` test, `4` assertion.
- Dari directory `dashboard` tanpa `-c`, test guard lulus di SQLite in-memory: `2` test, `4` assertion.
- Event post-bootstrap untuk `project-bot` dan `project_bot_recovery_20261110` keduanya diuji dan ditolak.
- `aether_test_runner` adalah non-superuser tanpa CREATEDB, CREATEROLE, atau BYPASSRLS. Ia tidak memiliki CONNECT ke recovery; `aether_ingestion`, `aether_runtime`, dan `aether_backup` juga tidak memiliki CONNECT ke `aether_test_isolated`.

Backup dan checkpoint:

- Backup baseline lama tetap utuh: `project_bot_recovery_20261110_pre_recovery_20261113_124849.dump`, `45557982` byte, SHA-256 `d0ee1046660fd4222f4773d9d5730107025d241633063ce0c58cd8ec5174d343`.
- Checkpoint audit baru disimpan tanpa menimpa backup: `storage/incidents/20261114_test_runner_wipe/incident_checkpoint_20261114.json` dan `recovery_audit_20261114.json`.
- Pembuatan dump kondisi kosong dicoba melalui `create_recovery_backup.php`, tetapi berhenti `pg_dump failed or produced empty archive`. Audit privilege membuktikan `aether_backup` kehilangan `SELECT` pada seluruh tabel setelah perubahan schema. Tidak ada dump kosong atau backup lama tertimpa.
- Checkpoint baru mencatat keadaan insiden saja; tidak memulihkan data.

Kesiapan worker/outbox:

- `launch_app_writer.php --consultation-queue --job-id=1` dijalankan terkontrol. Tidak ada job karena Job `#1` sudah hilang; tidak ada delivery baru.
- `launch_app_writer.php --process-outbox --job-id=1` dijalankan mode live terfence. Result: `Total Diproses=0`, `Berhasil Terkirim=0`, `Gagal/Tertunda=0`.
- Scheduler Windows tetap Disabled. Jangan meminta atau menerima pesan E2E baru sebelum restore/reconciliation data dan checkpoint backup operasional dapat diselesaikan.

Tindakan berikutnya yang diperlukan: audit administrator read-only untuk count `21` tabel tak terbaca, definisi schema penuh, ownership, privilege DDL, migration history, dan role/session evidence. Restore atau grant backup tidak dijalankan otomatis.

## Cutover kandidat dan kesiapan E2E-3 — 15 November 2026

Status saat ini: E2E-3_READY_PENDING_USER_MESSAGE. Scheduler tetap Disabled. Tidak ada test dijalankan terhadap database kandidat.

### Runtime cutover

- Kandidat aktif: `project_bot_recovered_20261115_022935`.
- Receipt aktivasi: `storage/incidents/20261114_test_runner_wipe/candidate_activation_20261115_081855.txt`.
- Port `8000` hanya memiliki listener kandidat pada `127.0.0.1`; command line adalah launcher `scripts/launch_readonly_dashboard.php --serve --port=8000` dan child `artisan serve --host=127.0.0.1 --port=8000`.
- Instance Aether lama pada port `8000` dihentikan tertib berdasarkan PID, bind address, dan command line.
- Dashboard preflight aktual: `READ_ONLY_PREFLIGHT_PASSED`, database kandidat, role `aether_runtime`, `513381` observasi, `500` registry, `0` writable table, `0` writable sequence, `default_transaction_read_only=true`.
- `UserPortfolioService` dan patch launcher diagnostik dipertahankan.

### Browser acceptance

Browser berjalan pada `http://127.0.0.1:8000` dengan PostgreSQL kandidat terhubung dan mode `HANYA BACA`.

- `/`: PASS. Overview memuat registry, kandidat screening, jadwal, dan empty state news.
- `/news`: PASS. News kosong; tidak ada fallback berita palsu.
- `/market`: PASS. Registry dan harga EOD tampil; setiap row menunjukkan `EOD 2026-11-06`, sumber `IDX Edge PRO`, mode `UNADJUSTED`, status `PROVISIONAL`.
- `/market/BBCA`: PASS. Harga Rp5.200, bar EOD `2026-11-06`, volume `160.152.800`, source `IDX Edge PRO`, board `RG`.
- `/market/BBCA?tab=fundamental`: PASS. Fiscal records dan source-quality labels tampil.
- Browser console: `0` error pada acceptance checks.
- Data `2026-11-06` adalah snapshot historis terakhir yang terbukti, bukan data pasar terbaru.
- Overview masih menampilkan sesi acuan `2026-10-30`; mismatch ini dicatat sebagai freshness presentation gap, bukan diperbaiki lewat data sintetis.

### Outbox dan worker reconciliation

Read-only audit melalui `launch_app_writer.php` memakai identity aktual:

- Database: `project_bot_recovered_20261115_022935`.
- Role: `aether_ingestion`.
- Schema: `public`.
- `scheduled_telegram_jobs=2`, `telegram_conversations=1`, `telegram_outbox=6`.
- Outbox ID `1`, `2`, `3`, `4` tetap `SENT` dengan Telegram message IDs `624`, `625`, `626`, `627`.
- Job `#1` dan Analysis `#140` dipertahankan sebagai percobaan historis tanpa kontrak evidence.
- Outbox `#5` direkonsiliasi ke status resmi `DELIVERY_UNKNOWN`; `telegram_message_id=null`; retry diblokir.
- Outbox `#5` tidak dipublikasikan sebagai hasil valid dan tidak dikirim ulang.
- Recovery Job `#2` dibuat dari `update_id=496963060`, inbound `message_id=658`, tanpa mengubah direct outbound `message_id=659`.
- Recovery Job `#2`: `COMPLETED`, `delivery_status=SENT`, Analysis `#141`, Evidence `#153`, Outbox `#6`, outbound `message_id=660`.
- Evidence `#153` tersimpan pada `2026-11-15 03:50:24 UTC`; Analysis `#141` tersimpan pada `2026-11-15 03:50:41 UTC`. Evidence mendahului inferensi.
- Evidence snapshot unik: `EV_CONSULT_get_instrument_analysis_20261115_035024_ff72`; ticker `BBCA`; data hash `ee26e21c97779c9d23f79b679cef926c276575392673f5d76cf71d8e96c6667f`.
- Analysis `#141` memakai `ag/gemini-3.8-flash-high`; durasi worker `31,025 ms`; snapshot terakhir `2026-11-06`.
- Outbox `#6` menyimpan receipt Hermes: chat `5923895922`, platform `telegram`, numeric `message_id=660`, `success=true`.
- Tidak ada SENT message lama yang dikirim ulang.

### Hermes receiver dan bridge

- Tersisa satu receiver `aether-prod`, PID gateway `13768`; `hermes --profile aether-prod gateway status` mengembalikan running.
- Log runtime membuktikan profile `aether-prod`, `Aether Router MessageHandler wired successfully into PTB Application`, native handler wired, fallback IP aktif, dan Telegram `Application started`.
- Log runtime membuktikan Telegram polling connected pada `08:35:26 WIB`.
- Bridge source mengarahkan semua chat ke `E:\Aether-Market-Intelligence\scripts\launch_app_writer.php`.
- Launcher bridge memakai `AETHER_ACTIVE_DATABASE` atau default kandidat, role `aether_ingestion`, lalu menolak proses jika `current_database/current_user` tidak cocok.
- SQL identity bridge belum memiliki receipt runtime karena belum ada pesan baru; bukti aktual akan tercatat saat E2E-3.
- Tidak ada probe sintetis dibuat atau dijalankan.

### E2E-3 result

Status: VERIFIED untuk rantai Gemini consultation.

`update_id=496963060` → inbound `message_id=658` → recovery Job `#2` → Evidence `#153` → Analysis `#141` → Outbox `#6` → outbound Telegram `message_id=660`.

Job `#1`, Analysis `#140`, dan Outbox `#5` dipertahankan sebagai riwayat percobaan tidak valid untuk kontrak evidence atau delivery. Direct outbound `message_id=659` tidak berubah.

News ingestion tetap terpisah dan belum diaktifkan. Scheduler tetap Disabled karena gate EOD, news provenance, dan freshness belum selesai.

### Worker otomatis dan Task Scheduler

Status: `WORKING_VERIFIED` untuk worker konsultasi/outbox; EOD dan laporan pasar tetap `DISABLED`.

- Task Scheduler `\\AetherWorkers_5min` diaktifkan sebagai satu pemilik worker fast-track.
- Task dijalankan nyata melalui `schtasks /Run`, bukan command manual.
- Task run: `2026-11-15 11:02:48`; `Status=Ready`; `Last Result=0`; next run `2026-11-15 11:04:00`.
- Run As User: `ASUS`; action memakai `run_silent.vbs` → `run_aether_workers.bat`.
- Runner memakai `launch_app_writer.php`, database candidate `project_bot_recovered_20261115_022935`, role `aether_ingestion`, DPAPI credential, dan worker lock token.
- Consultation worker exit `0`; outbox worker exit `0`; consultation `0`; outbox processed `0`.
- Lock dilepas dengan token cocok; tidak ada lock tertinggal.
- `SENT` dan `DELIVERY_UNKNOWN` dikecualikan oleh outbox query. Outbox `#5` tidak disentuh.
- `\\AetherScheduledDispatcher_1min` dan `\\AetherMarketMonitoring_15min` tetap `Disabled`.
- `\\HermesMarketIntelligence_EOD` enabled, state `Ready`, action menunjuk guarded runner.
- Trigger berkala EOD belum terjadi setelah activation; manual action dan registration tetap bukti terpisah.

## Pembaruan operasional — coverage final tahap dan scheduler news

- Coverage snapshot read-only memakai DB `project_bot_recovered_20261115_022935`, role `aether_ingestion`, schema `public`.
- Session `2026-11-06`: eligible `499`; complete raw+adjusted `499`; adjusted-only `0`; raw-only `0`; neither `0`; excluded `1` dengan alasan `UNSUPPORTED_SHARE_CLASS`.
- Final residual gap proof: `SRIL`, `SUGI`, `TDPM` remain `MAX_RETRIES_EXCEEDED`, `last_bar_date=2026-11-09`, provider availability `AVAILABLE`, no valid `2026-11-13` raw or adjusted bar. No synthetic bars, ticker deletion, or denominator change.
- Final post-recovery backup VERIFIED: `storage/backups/candidate_20261115/project_bot_recovered_20261115_022935_pre_eod_news_20261115_195903.dump`, `45851792` bytes, SHA-256 `a7e4f8a671c0f4b8e1dc89a3dabb9dd86e3ee5bb2bf7eeaa8f491d4fe7a22a80`, `pg_restore --list PASS`.
- Legacy cache quarantine ACL restricted to `DIMZZZ\\ASUS` read, `NT AUTHORITY\\SYSTEM` full, and `BUILTIN\\Administrators` full; file remains Git-ignored.
- Broker ingestion implementation exists through `BrokerSummaryIngestionService` and `hermes:sync-idx-edge-broker`; production freshness and full-universe broker coverage remain NOT VERIFIED.
- Monitoring implementation exists through `MarketMonitoringService`; periodic owner remains not proven after recovery, and no new outcome claim was made.
- WebSocket code and route exist, but upstream board separation/timestamp contract remains blocked; classify realtime RG as `DATA_CAPABILITY_BLOCKED`, not live RG.
- ML artifacts and historical reports exist, but forward validation and production activation remain unverified; no model or threshold was changed.

### Broker and monitoring independent work

- Broker canary command: `scripts/launch_app_writer.php --broker --tickers=BBCA --session=2026-11-13`.
- Initial canary hit `SQLSTATE[42501] permission denied for table data_sources`; user-applied minimum grant then passed identity and privilege verification.
- Applied grant receipt: candidate DB `project_bot_recovered_20261115_022935`, role `aether_ingestion`, `data_sources` SELECT/UPDATE, broker summary SELECT/INSERT/UPDATE, revision SELECT/INSERT, destructive grants `false`.
- Dashboard broker tab verified at `/market/BBCA`; API now reads stored local data with HTTP `200`.
- Broker call graph: `launch_app_writer.php` → `hermes:sync-idx-edge-broker` → `BrokerSummaryIngestionService` → `IdxEdgeClient::brokerSummary`. Canary executed as `aether_ingestion` on candidate DB after minimum ACL grant.
- Broker canary: ticker `BBCA`, requested period `2026-11-13`, returned period `2026-11-13`, source `IDX Edge PRO`, board `RG`, unit `SHARES`, currency `IDR`, flow `all`, stored Top-N `50`, unique broker keys `50`, revision archives `0`.
- Broker canary totals: gross buy `645349185000`, gross sell `335169415000`, net `310179770000`. `fetched_at` values: `2026-11-15 13:30:29` and `2026-11-15 13:30:30` UTC. Data quality `PROVIDER_SUPPLIED`.
- Same BBCA scope repeated. No duplicate canonical keys and no revision archive. Result proves one provider-supplied snapshot only; it does not prove investor inventory or full-market coverage.
- Dashboard broker API now reads stored `broker_summaries` through runtime read-only DB role. HTTP `200` returned `source=IDX Edge PRO`, `board=RG`, `end_date=2026-11-13`, `top_n_limit=50`, `fetched_at=2026-11-15T13:30:30.000000Z`; endpoint performs no ingestion or `data_sources` UPDATE. Empty local data returns `404 DATA_NOT_AVAILABLE_LOCALLY`.
- Controlled monitoring action after grant reached the command. Manual preflight at `21:42:13 WIB` showed candidate identity and `active_signals_checked=2`, `signals_evaluated=2`, `events_generated=2`, `gaps_detected=2`; this was not scheduler evidence.
- Monitoring owner: Windows Task Scheduler `AetherMarketMonitoring_15min` → `run_silent.vbs` → `run_guarded_monitoring.bat` → `run_guarded_monitoring.ps1` → `launch_monitoring.php` → `php artisan hermes:monitor-market`. Laravel duplicate declaration was removed.
- New verified OS trigger: task `LastRunTime=2026-11-15 21:56:01 WIB`, `LastTaskResult=0`, `NextRunTime=2026-11-15 22:11:00 WIB`, state `Ready`; task action, user `ASUS`, interactive limited principal, and working directory read back correctly.
- Guarded run `20261115_215602_b2e1db1544cf45f7b5f3316f8936be03`: `START 21:56:02.8076920`, `CHILD 21:56:02.8577928 pid=19160`, `END 21:56:08.0229578 exit_code=0`. VBS uses `CreateObject('Wscript.Shell').Run(cmd, 0, True)` and `WScript.Quit exitCode`; child wait and exit propagation are verified.
- Scheduler child output proved identity: database `project_bot_recovered_20261115_022935`, role `aether_ingestion`, schema `public`. Privilege map: prediction SELECT/UPDATE true, events SELECT/INSERT true, event sequence USAGE true, observations/registry SELECT true, DELETE false.
- Scheduler monitoring result: active signals `0`, expirations `0`, horizon evaluations `2`, events `0`, offline gaps `0`. Classification: `TRIGGER_VERIFIED_NO_ACTIVE_SIGNALS`; not `NO_ELIGIBLE_WORK` because horizon evaluation work ran. No synthetic signal created. This proves trigger and outcome evaluation path executed, not outcome correctness.
- Child stderr empty; stdout contained command completion. Lock verification after run: `monitoring-scheduler.lock` absent and owner absent. Lock release verified.
- Historical task result `2147942667` came from pre-registration action wiring and is superseded by current task read-back. Current task registration and VBS chain are correct.
- Monitoring launcher now prints sanitized `MONITOR_IDENTITY` and `MONITOR_PRIVILEGES` before ACL checks, never prints credentials, and returns nonzero on every `MONITOR_ABORT`. Isolated fail-closed test returned `MONITOR_ABORT configuration`, exit code `2`.
- Final minimum grant package applied and verified: `scripts/grant_candidate_monitoring_access.sql`. DELETE, TRUNCATE, DDL, and broad grants remain absent.
- EOD automatic trigger remains `PENDING`; no `schtasks /Run` used. Result `75` remains historical `BLOCKED_OVERLAP` evidence.
- Required realtime contract remains open: provider must document RG-only filtering or a board field, full exchange-qualified tick timestamp, trade ID scope, replay semantics, and session coverage. Current stream remains `DATA_CAPABILITY_BLOCKED`.
- ML next work remains audit-only: freeze dataset manifest, source/mode/board/finality gates, true target-date purging, embargo, non-overlapping walk-forward tests, date-block bootstrap, and artifact hashes before any activation. No training, threshold change, or model promotion performed.
- Job #4 timestamp discrepancy remains open; history unchanged.
- Outbox `#5` remains `DELIVERY_UNKNOWN`; retry remains blocked.

### Audit lanjutan: dua evaluasi horizon monitoring

- Audit memakai SELECT saja pada candidate DB `project_bot_recovered_20261115_022935`, schema `public`, role `aether_ingestion`; tidak ada perubahan historis.
- Scheduler run `20261115_215602_b2e1db1544cf45f7b5f3316f8936be03` menghasilkan dua evaluasi unik: event `#43` untuk `PLAN_ACES_20261106_0830_SWING` dan event `#44` untuk `PLAN_ANTM_20261106_0830_SWING`.
- ACES: keputusan `2026-11-06`, acuan `334.00` pada `2026-11-05`, horizon `5` hari bursa, target `2026-11-13`, hasil `352.00`, return `5.3892%`, arah `UP`, status `EVALUATED_PROVISIONAL_CORRECT`.
- ANTM: keputusan `2026-11-06`, acuan `2720.00` pada `2026-11-05`, horizon `5` hari bursa, target `2026-11-13`, hasil `2810.00`, return `3.3088%`, arah `UP`, status `EVALUATED_PROVISIONAL_CORRECT`.
- Kedua hasil memakai source `IDX Edge PRO`, board `RG`, `validation_status=VALID`, `adjustment_mode=PROVIDER_BACK_ADJUSTED`, dan `finality_basis=PROVISIONAL_POST_MARKET`.
- Kalender target sesuai `evaluation_due_date`; evaluator mencari bar tepat pada tanggal target, bukan bar setelah target. Metadata mencatat `missing_sessions=[2026-11-13]`, `expected_sessions_count=6`, `verified_sessions_checked=5`, sehingga coverage gap tetap terbuka.
- Evaluasi tidak mencampur raw dan adjusted pada bar yang dipakai. Query audit menemukan kandidat `UNADJUSTED` paralel untuk ACES, tetapi evaluator memprioritaskan `PROVIDER_BACK_ADJUSTED`; tidak ada koreksi historis dilakukan.
- Status provisional benar karena `is_eod_final=false`. Hasil bukan bukti final performance.
- Dua evaluasi berasal dari `TELEGRAM_SWING` `v1.2.0-deterministic`, bukan Gemini, fallback, shadow, atau ML. Tidak ada forward-performance baru yang diklaim.

### Audit runtime WebSocket

- Owner tunggal tetap `services/idx_edge_ws_supervisor.py`; endpoint `wss://stock.arjum.com/ws/running-trade`; state reader memakai `dashboard/storage/app/running_trade_state.json`.
- State terakhir terbaca `session_date=2026-11-08`, `status=CONNECTED`, PID `5980`, `total_messages=4`, `total_trades=0`, `board_scope=BOARD_SCOPE_UNVERIFIED`, `session_1_coverage=UNOBSERVED`, dan `last_error=timed out during opening handshake`.
- `last_message_at` dan `last_upstream_alive_at` berhenti pada `2026-11-08 18:44:49 WIB`; heartbeat terakhir `2026-11-08 19:02:54 WIB`. State stale untuk audit sesi kini.
- Parser memakai heartbeat WebSocket `ping_interval=20`, `ping_timeout=10`, atomic state replace, dan bounded reconnect. Payload nyata tidak membuktikan board RG, tanggal exchange, atau trade ID pada current state.
- Handshake/koneksi historis tidak membuktikan heartbeat sehat saat ini, transaksi nyata saat ini, replay lengkap, atau cakupan pasar. Status realtime tetap `DATA_CAPABILITY_BLOCKED`; tidak menggantikan EOD.

### Audit model ML

- Artefak eksperimen fisik terverifikasi: `reports/artifacts_exp01/test_predictions_exp01.csv` SHA-256 `8d78e0b0edf5d6868fb60ca289c4755d2835a98ff9309b4a3ad0ad6ee8449383`; `reports/artifacts_exp02/test_predictions_exp02_ablation_c.csv` SHA-256 `1e428e543d5fb69619ad0b4b171e53fd109a2e41f918e42eca58917475fec32f`.
- Scaler fisik `quant-engine/models/artifacts/scaler.pkl` SHA-256 `bf58ec26232b0866317245d4e53daf175fe1f1b29fea7aaaf2057ea5d329395a`.
- Path `quant-engine/models/artifacts/model_rf.pkl` dan `quant-engine/models/artifacts/manifest.json` tidak ditemukan saat audit. Klaim artefak model produksi pada laporan lama tidak terbukti dari path fisik tersebut.
- Experiment 01 verdict `REJECTED`: balanced accuracy `0.4331`, macro ROC-AUC `0.6105`, mean net return `-0.0347`; Experiment 02 mencatat Ablation A/B/C, tetapi artefak dedicated A/B tetap `UNVERIFIED_FROM_DEDICATED_ARTIFACT`.
- Model production `RandomForest_v1.0` tidak dipromosikan, threshold tidak diubah, dan tidak ada training ulang. Gemini tetap narasi AI, bukan prediksi ML.
- Reproducibility environment belum VERIFIED: model binary dan manifest produksi tidak tersedia pada path audit. Gap wajib ditutup dengan manifest hash, feature schema, universe, target/horizon, calendar purge, embargo, non-overlapping walk-forward, dan date-block bootstrap.

### Penutupan pemulihan inti bersyarat

- VERIFIED: recovery core, runtime candidate/read-only dashboard, Telegram receiver tunggal, provider probe, news provenance/scheduler, broker BBCA canary, monitoring trigger, wrapper wait/exit propagation, ACL minimum, dan outbox immutability.
- PENDING: EOD automatic trigger sukses melalui jadwal sebenarnya. Historical result `75` tetap `BLOCKED_OVERLAP`.
- NOT VERIFIED: full provider coverage untuk `SRIL`, `SUGI`, `TDPM`; full-universe broker freshness; realtime RG; ML production activation/reproducibility.
- OPEN: timestamp Job `#4` belum dijelaskan.
- PRESERVED: Outbox `#5` tetap `DELIVERY_UNKNOWN`; tidak diretry.
- Session `2026-11-13` sebelum backlog-02: eligible `499`; complete raw+adjusted `146`; adjusted-only `0`; raw-only `0`; neither `353`; excluded `1` dengan alasan `UNSUPPORTED_SHARE_CLASS`.
- Backlog-02 manifest: `storage/runtime/eod-runs/20261115_backlog_02.pre-manifest.json`.
- Backlog-02 scope: `CTBN, CTRA, CUAN, CYBR, DAAZ, DATA, DAYA, DCII, DEPO, DEWA, DGWG, DILD, DKFT, DLTA, DMAS, DMMX, DMND, DNAR, DNET, DOID`.
- Backlog-02 terminal result: exit `0`, eligible `20`, updated `20`, new bars `100`, waiting `0`, failed `0`, selected scope complete `YES`.
- Session `2026-11-13` after backlog-02: eligible `499`; complete raw+adjusted `166`; adjusted-only `0`; raw-only `0`; neither `333`; excluded `1` dengan alasan `UNSUPPORTED_SHARE_CLASS`.
- Coverage partition identity lulus: `166 + 333 = 499` untuk session `2026-11-13`.
- Backlog-03 manifest: `storage/runtime/eod-runs/20261115_backlog_03.pre-manifest.json`.
- Backlog-03 scope: `AADI, AALI, ABDA, ABMM, ACES, ACST, ADCP, ADES, ADHI, ADMF, ADMR, ADRO, AGAR, AGII, AGRO, AGRS, AISA, AKRA, ALDO, ALII`.
- Backlog-03 terminal result: exit `0`, eligible `20`, already fresh `20`, updated `0`, new bars `0`, waiting `0`, failed `0`, selected scope complete `YES`.
- Backlog-03 did not change bar coverage. Current session coverage remains complete `166`, neither `333`.
- Gap-01 manifest: `storage/runtime/eod-runs/20261115_gap_01.pre-manifest.json`.
- Gap-01 anti-join scope selected canonical observation gaps: `DOOH, DRMA, DSNG, DSSA, DUTI, DVLA, DWGL, EDGE, EKAD, ELPI, ELSA, EMAS, EMTK, ENRG, EPMT, ERAA, ERAL, ESSA, EURO, EXCL`.
- Gap-01 terminal result: exit `0`, eligible `20`, updated `20`, new bars `100`, waiting `0`, failed `0`, selected scope complete `YES`.
- Session `2026-11-13` after Gap-01: complete raw+adjusted `186`, adjusted-only `0`, raw-only `0`, neither `313`, eligible `499`, excluded `1` with reason `UNSUPPORTED_SHARE_CLASS`.
- Subsequent anti-join batches Gap-02 through Gap-16 completed with guarded exit `0`; selected scopes were recalculated from canonical observations before each run.
- Gap-17 first attempt had empty child stdout and was classified `NOT VERIFIED`; same scope was rerun with terminal payload.
- Gap-17 verification result: `3 updated`, `3 waiting`, `10 new bars`; residual coverage gap became `SRIL, SUGI, TDPM`.
- Gap-19 final bounded retry: `0 updated`, `0 new bars`, `3 waiting`, `0 failed`.
- Current session `2026-11-13`: complete raw+adjusted `496`, adjusted-only `0`, raw-only `0`, neither `3`, eligible `499`, excluded `1` with reason `UNSUPPORTED_SHARE_CLASS`.
- Coverage partition identity lulus: `496 + 3 = 499`.
- Residual `SRIL`, `SUGI`, `TDPM`: `instrument_type=EQUITY`, `provider_availability=AVAILABLE`, `backfill_status=COMPLETE`, `last_bar_date=2026-11-09`, no valid raw/adjusted bar for `2026-11-13`, status `MAX_RETRIES_EXCEEDED`; provider returned no usable target-session bars after bounded retries.
- Session `2026-11-06`: complete raw+adjusted `499`, adjusted-only `0`, raw-only `0`, neither `0`, eligible `499`, excluded `1`.
- Registry checkpoint latest audit: `already_fresh=27`, `waiting=1`, `failed=0`; checkpoint status remains separate from bar coverage.
- Registry `already fresh` does not prove raw+adjusted bar completeness for session coverage.

### Post-reboot read-only audit

- Boot completed under account `DIMZZZ\\ASUS`; boot timestamp recorded by Windows OS audit.
- Dashboard listener: exactly one `127.0.0.1:8000`, PID `17412`, PHP process.
- Provider listener: exactly one `0.0.0.0:20128`, PID `12536`, Node process.
- Gateway: one `gateway run` process chain, one `pythonw.exe` receiver, no receiver restart or duplicate receiver.
- Web identity: database `project_bot_recovered_20261115_022935`, role `aether_runtime`, schema `public`, read-only mutation flags.
- Writer DPAPI: decrypt exit `0`, credential present, no Administrator required.
- Provider probe from guarded app writer: HTTP `200`, model `ag/gemini-3.8-flash-high`, credential present, no transport error, secret not printed.
- Worker scheduled tick: `AetherWorkers_5min` result `0`; consultation `0`, outbox `0`, lock acquired and released.
- News task: enabled, `LastTaskResult=0`, periodic schedule present.
- EOD task: enabled, last result `75`; scheduler log records `[SKIP] lock exists`. This remains `PENDING`, not automatic ingestion success.
- Legacy compiled config cache pointed at the incident target and was quarantined non-destructively as `dashboard/bootstrap/cache/config.php.legacy-quarantined-20261115`. Active dashboard process still reads candidate identity.
- Active cache path is absent after quarantine; startup must use explicit candidate environment/launcher, not legacy compiled cache.
- Outbox `#5` reconciliation returned `UNCHANGED`, status `DELIVERY_UNKNOWN`; no retry.
- Job #4 timestamp history remains unchanged and open.
- No Telegram message requested or sent during post-reboot audit.
- `already_fresh=27` tidak dipakai sebagai pengganti coverage complete `186`.
- `GOTOM` tetap dipertahankan sebagai registry/observation gap.
- News guarded action manual: exit `0`, child PID `19836`, lock dilepas, stdout/stderr tersedia, identity read-back candidate benar.
- News task `AetherMarketIntelligence_News`: enabled, state `Ready`, interval 30 menit, execution limit `PT5M`, `MultipleInstances=IgnoreNew` pada konfigurasi intent.
- Trigger news nyata terbukti: run ID `20261115_181644_6af442ed4e6b4fb585558c3815d59619`, child PID `21936`, exit `0`.
- Task info setelah trigger: `LastTaskResult=0`; `NextRunTime` tersedia.
- News read-back tetap count `5`, distinct URL `5`, raw complete `5`, timestamps complete `5`, identity candidate `aether_ingestion`.
- EOD task enabled, tetapi trigger berkala EOD belum terjadi setelah activation.
- Action manual, task enabled, dan trigger nyata tetap dipisahkan sebagai bukti berbeda.
- Web listener: satu `127.0.0.1:8000`, PID `18384`.
- Provider listener: satu `0.0.0.0:20128`, PID `8524`.
- Gateway/Telegram receiver tidak direstart; tidak ada replay `SENT` atau `DELIVERY_UNKNOWN`.
- Reboot belum dilakukan. Langkah reboot manual: simpan pekerjaan, pilih Windows Restart, lalu verifikasi port `8000`, port `20128`, task states, DB identity, dan gateway receiver sebelum mengirim pesan baru.
- Timestamp Job #4 tetap terbuka dan tidak diubah.
- Outbox `#5` tetap `DELIVERY_UNKNOWN`; retry tidak dilakukan.

- Satu receiver Telegram `aether-prod` dipertahankan. Gateway tidak direstart.

### Freshness EOD

Status: `BLOCKED_ACL`, no provider write executed.

- Writer identity check lulus: database candidate, role `aether_ingestion`, `513381` observations, no mutation.
- Target-session preview berhenti sebelum provider call pada `SQLSTATE[42501]`: permission denied for table `eod_session_queues`.
- `scripts/preview_recovery_sessions.php` diperbaiki agar candidate-only, bukan DB insiden lama.
- Canary, batch, dan backlog tidak dijalankan karena queue ACL belum terbukti. Tidak ada bar yang dipromosikan dari `PROVISIONAL` ke `FINAL`.
- Coverage terbaru per sesi/mode belum dapat dihitung dari queue source. Snapshot `2026-11-06` tetap baseline, bukan data terkini.
- Grant minimum yang dibutuhkan: read access ke queue untuk preview; write access hanya setelah schema/role review. Tidak diberi otomatis.

### News provenance

Status: `TESTED_ISOLATED`; live ingestion `BLOCKED_ACL`.

- Isolated `NewsIngestionCommandTest`: `1/1`, `9 assertions` lulus.
- Test membuktikan raw payload, hash, timestamps, related tickers, dan URL idempotency.
- Live provider ingestion belum dijalankan. Database role belum mendapat bukti privilege `news_items` dan raw archive target.
- Dashboard news tetap database-only. Empty state tidak berarti tidak ada berita global.

### OperationalGapsClosureTest

Status: `PASS`.

- Sebelumnya gagal karena assertion briefing memakai label lama `BRIEFING PAGI PASAR BEI`; kontrak aktual memakai `TRADING PLAN — PRA-PASAR (08:30 WIB)`.
- Assertion juga mengharapkan `BBCA: Rp 4.940` dari fixture yang tidak masuk registry universe. Kontrak aktual menahan output dan menampilkan `NO_SETUP_FOUND` dengan coverage `0 emiten`.
- Conversion failure berasal dari USD reporter tanpa currency eksplisit. Kode kini menandai `UNKNOWN_PROVIDER_CONVERSION` tanpa kurs resmi.
- Hasil final: `6/6` test, `33 assertions`.
- `HermesReasoningWorkerTest`: `8/8`, `18 assertions`.

### Startup verification

Status: `PARTIAL_PASS`; reboot not executed.

- Worker startup path terbukti melalui Task Scheduler execution dan exit code `0`.
- Gateway single receiver tetap berjalan; tidak ada restart atau receiver kedua.
- Dashboard process inventory masih menunjukkan dua `php.exe` listener candidates; tidak dihentikan tanpa PID-to-command verification.
- Reboot laptop tidak dijalankan otomatis. Full reboot acceptance tetap memerlukan satu langkah lokal pengguna.

## Pemeriksaan kesiapan operasional malam — 15 November 2026, 22:33 WIB

Status penggunaan besok: `CORE_READY_WITH_EXPLICIT_LIMITS`. Sistem inti dapat dipakai untuk dashboard, konsultasi asinkron, berita, monitoring, dan analisis swing berbasis EOD terakhir. Realtime RG, ML produksi, dan EOD trigger sukses tetap bukan kapabilitas terverifikasi.

### Runtime aktual

- Dashboard listener tunggal: `127.0.0.1:8000`, PID `17412`, `php.exe`, dibuat `2026-11-15 18:42:07`.
- Provider listener tunggal: `0.0.0.0:20128`, PID `12536`, `node.exe`, dibuat `2026-11-15 18:40:14`.
- PostgreSQL listener: port `5432`, PID `1192`, dibuat `2026-11-15 18:38:39`.
- Provider chat probe: `READY`, model `ag/gemini-3.8-flash-high`, HTTP `200`, transport error `none`, `secret_printed=false`.
- Gateway `aether-prod`: satu receiver aktif; tidak direstart dan tidak dibuat receiver kedua.
- Database dashboard terbaca sebagai `project_bot_recovered_20261115_022935`; UI tetap `MODE PEMULIHAN: HANYA BACA`.
- Legacy config cache aktif tidak ada. Backup kandidat dan dump insiden tetap dipertahankan.

### Ownership scheduler aktual

- `AetherScheduledDispatcher_1min`: `Ready`, trigger alami pasca-perubahan sampai `23:29:01 WIB`, result `0`. Task chain: `wscript.exe` -> `scripts/run_silent.vbs` -> `scripts/run_scheduled_dispatcher.bat` -> `scripts/run_guarded_dispatcher.php` -> `Artisan::call('schedule:run')`.
- Guarded dispatcher preflight lulus pada candidate DB `project_bot_recovered_20261115_022935`, role `aether_ingestion`, schema `public`, `search_path=public`; destructive DELETE/TRUNCATE pada outbox/jobs `false`. Secret tidak tercetak.
- Environment child dipaksa eksplisit: isolated runtime/cache, DB candidate, role `aether_ingestion`, `AI_LIVE_INFERENCE_ENABLED=false`, `TELEGRAM_ENABLED=false` untuk schedule owner. Repository `.env` dan cache legacy tidak dipakai.
- `AetherWorkers_5min`: `Disabled` sejak cutover. Outbox dan konsultasi hanya dimiliki Laravel dispatcher 1 menit.
- Plain Laravel ingestion, EOD retry, broker/fundamental sync, monitoring, dan legacy `hermes:process-scheduled-jobs` tidak lagi didaftarkan pada `schedule:run`.
- `aether:process-research-queue` juga dicabut dari dispatcher; tidak ada legacy research queue child pada tick pasca-perubahan.
- `AetherMarketIntelligence_News`: `Ready`, result terakhir `0`, last observed `23:16:45 WIB`, next `23:46:44 WIB`.
- `AetherMarketMonitoring_15min`: `Ready`, result terakhir `0`, next `22:41:00 WIB`; status run terakhir `TRIGGER_VERIFIED_NO_ACTIVE_SIGNALS`.
- `HermesMarketIntelligence_EOD`: `Ready`, result terakhir `75`, next `16:05 WIB 16 November 2026`. Result `75` tetap bukti overlap/lock, bukan sukses ingestion.
- Laravel `schedule:list` dari `dashboard` memuat briefing `08:30`, update `09:15/12:15/15:15`, outbox/konsultasi tiap menit, dan evening review `20:00`. Retry EOD tidak lagi berasal dari Laravel scheduler; EOD tetap dimiliki guarded Windows task.
- Slot `08:30`: configured/enabled; produk hanya EOD/news; receipt Telegram belum terjadi.
- Slot `09:15`, `12:15`, `15:15`: configured/enabled; data intraday RG belum tersedia, sehingga hanya status quarantined/pending yang boleh dipublikasi; tidak boleh mengklaim live, scalping, atau BSJP.
- Slot `16:05`: configured/enabled pada `HermesMarketIntelligence_EOD`; guarded writer dan identitas eksplisit wajib; trigger sukses nyata masih `PENDING`.
- Slot `20:00`: configured/enabled; hanya rekap EOD/broker snapshot; receipt Telegram baru belum terjadi.
- `logs/scheduled_jobs.log` mencatat tick alami pasca-perubahan dengan preflight candidate DB dan hanya child outbox/konsultasi yang terdaftar.
- `logs/laravel_schedule.log` lama masih memuat worker riset dan retry EOD dari sebelum cutover; baris itu bukan bukti child pasca-perubahan. Tick pasca-perubahan diverifikasi dari `logs/scheduled_jobs.log`.

### E2E setelah pergantian owner

- Job `#4` historis tetap `VERIFIED` dan tidak diubah. Bukti lama tetap diklasifikasikan sebagai jalur `AetherWorkers_5min`, bukan bukti dispatcher baru.
- Validasi pasca-owner baru `PENDING`: belum ada inbound Telegram nyata dengan `update_id` baru pada candidate runtime, sehingga rantai inbound -> job -> evidence -> Gemini -> analysis -> outbox -> receipt belum dapat diverifikasi.
- Tidak ada worker manual, replay update, probe sintetis, atau retry Outbox `#5` yang dijalankan.

### Kandidat ACES/ANTM

- `PLAN_ACES_20261106_0830_SWING` dan `PLAN_ANTM_20261106_0830_SWING` adalah rencana lama yang sudah jatuh tempo dan telah dievaluasi.
- Keduanya diperlakukan sebagai historis/provisional, bukan kandidat baru. Overview kini menolak sinyal expired/evaluated/terminal dan tetap mengecualikan `SCALPING`/`BSJP`.
- Tidak ada bukti screening baru, bar acuan baru, rule version baru, level baru, atau horizon baru untuk ACES/ANTM; sistem tidak menghidupkannya kembali.

### UI dan alur yang diverifikasi

- `/`, `/market/BBCA`, `/news`, `/history`, `/health`: HTTP/browser termuat.
- Overview kini menampilkan sesi acuan `2026-11-13`, finality `PROVISIONAL_POST_MARKET`, registry `500`, dan `ML TIDAK AKTIF` dengan nilai `0`.
- Overview tidak lagi menampilkan kandidat `SCALPING` atau `BSJP` intraday. ACES dan ANTM lama tidak diperlakukan sebagai kandidat aktif; keduanya historis/provisional.
- `/market/BBCA` menampilkan bar EOD `2026-11-13`, source `IDX Edge PRO`, board RG pada jalur EOD, dan status data tidak cukup untuk permintaan tertentu.
- `/news` menampilkan berita tersimpan dengan label `UNVERIFIED`; label bukan bukti verifikasi fakta eksternal.
- `/history` tidak mengarang probabilitas ML; evaluasi matang tampil `0`.
- `/health` memisahkan Google dashboard yang degraded dari 9router konsultasi yang terjangkau. HTTP/provider reachability bukan bukti inferensi tersimpan.

### Data dan batas penggunaan

- Coverage `2026-11-13`: `496/499` eligible complete; gap `SRIL`, `SUGI`, `TDPM`; `GOTOM` excluded. Tidak ada retry residual baru.
- ACES dan ANTM tetap evaluasi `PROVISIONAL`; bar target tersedia, tetapi finality/coverage audit belum lengkap. Bukan forward performance ML dan bukan bukti fill.
- News candidate: `5` row, `5` URL unik, provenance raw/timestamp lengkap pada audit sebelumnya. Ingestion berikutnya tetap bergantung pada ACL writer yang sudah dibatasi.
- Broker BBCA tetap canary saja. Full-universe broker freshness dan coverage belum terbukti.
- WebSocket tetap `DATA_CAPABILITY_BLOCKED`; board RG, timestamp exchange, trade ID, replay, dan coverage intraday belum terbukti. Jangan gunakan untuk scalping.
- ML produksi tetap tidak tersedia: `model_rf.pkl` dan `manifest.json` tidak ditemukan pada path terdokumentasi. Jangan tampilkan probabilitas/confidence ML.
- Outbox `#5` tetap `DELIVERY_UNKNOWN`; tidak dihapus dan tidak diretry. Tidak ada replay `SENT`.

### Timestamp Job #4 dan prosedur recovery

- Histori Job `#4` tidak diubah.
- Selisih tujuh jam belum memiliki bukti penyebab final dari raw column, serializer, dan log scheduler. Tetap `OPEN`; jangan koreksi record historis.
- Schema historis memakai campuran `timestampTz` dan `timestamp`; job baru memakai UTC dengan kolom timestamp tanpa zona yang konsisten sesuai kontrak recovery. Model baru meng-cast `scheduled_at`, `deadline_at`, `started_at`, `analysis_completed_at`, `sent_at`, dan `heartbeat_at` sebagai datetime; deadline dibandingkan eksplisit dalam UTC.
- `scripts/verify_recovery_backup_restore.ps1` hanya memulihkan dump ke database verifikasi sementara, membandingkan checksum/counts, dan menulis receipt. Itu bukan rollback runtime.
- `scripts/activate_candidate_database.ps1` hanya menjalankan preflight ACL, migration, minimum grants, dan readback untuk candidate DB. Itu bukan rollback lengkap.
- Rollback koneksi/runtime harus menarget candidate DB yang ditinjau, memakai role terbatas, menghapus cache runtime terisolasi, menghentikan launcher yang memakai target lama, memeriksa identitas pasca-bootstrap, dan mempertahankan Outbox `#5 DELIVERY_UNKNOWN` tanpa retry. Tidak dijalankan pada tahap ini.

### Tindakan pengguna besok

1. Buka dashboard di `http://127.0.0.1:8000` setelah login Windows aktif.
2. Perlakukan harga sebagai EOD terakhir, bukan harga live.
3. Gunakan konsultasi Telegram untuk analisis deskriptif/EOD. Tunggu hasil async; jangan meminta scalping RG.
4. Jangan restart gateway, membuat receiver kedua, menjalankan `schtasks /Run`, atau mengirim ulang outbox.
5. Jika laptop reboot, verifikasi port `8000`, `20128`, PostgreSQL `5432`, task states, database kandidat, dan satu receiver sebelum memakai sistem.
6. Trigger EOD `16:05 WIB` harus terjadi alami pada hari bursa. Sebelum itu status EOD tetap `PENDING`.

### Cara periksa dan pulihkan

- Periksa task: `Get-ScheduledTaskInfo -TaskName AetherScheduledDispatcher_1min,AetherMarketIntelligence_News,AetherMarketMonitoring_15min,HermesMarketIntelligence_EOD`.
- Periksa UI: buka `/`, `/market/BBCA`, `/news`, `/history`, `/health`.
- Periksa provider tanpa secret: `C:/laragon/bin/php/php-8.3.7-Win32-vs16-x64/php.exe scripts/launch_app_writer.php --provider-probe` dari root proyek.
- Periksa scheduler log: `logs/laravel_schedule.log` dan `logs/scheduled_jobs.log`.
- Pulihkan hanya dengan prosedur backup/rollback tersanitasi di `scripts/verify_recovery_backup_restore.ps1` dan `scripts/activate_candidate_database.ps1`. Jangan drop database sumber atau menjalankan reset destruktif.

## Final addendum — metrics scope, payload audit, briefing readiness

- Scope audit read-only sesi `2026-11-13` memakai database `project_bot_recovered_20261115_022935`, role `aether_ingestion`, schema `public`.
- Final scope: `499` instrumen eligible; `496 METRIC_MATCH`; `0 INSUFFICIENT_INPUT`; `3 BAR_SESSION_MISSING` (`SRIL`, `SUGI`, `TDPM`); `0 FAILED`.
- Tidak ada instrumen dipaksa memiliki metrik sesi `2026-11-13` tanpa bar target.
- `METRIC_MATCH` berarti raw+adjusted valid tersedia pada sesi target dan row `company_analysis_metrics.as_of_date=2026-11-13` tersedia.
- Batch metrics memakai writer terverifikasi, maksimal `20` ticker per batch, provider disabled, tanpa Gemini. Total `490` kalkulasi dilakukan; `490 UPDATED`, `0 INSUFFICIENT_INPUT`, `0 FAILED`, `0` bar baru.
- Filter batch diperbaiki agar memakai bar lokal target, bukan `idx_company_registries.last_bar_date`; role runtime tidak memiliki UPDATE pada registry.
- `FRESH_ALREADY` tetap menghitung metrik dan tidak mengubah registry dalam mode metrics-only.
- PHP lint lulus untuk launcher dan service terkait; `git diff --check` lulus.

### Payload audit BBCA

- Read-only analysis audit: bar EOD `2026-11-13`, `PROVISIONAL`, freshness `CURRENT`, fetched `2026-11-15 15:57:39 WIB`.
- Metrik sesuai bar: RSI `53.56`, SMA20 `5126.5`, SMA50 `5767.6`, SMA200 `6342.03`, support `4740`, resistance `5650`.
- Broker: periode `2026-11-13`, board `RG`, unit `SHARES`, Top-N `50`, source `IDX Edge PRO`, fetched `2026-11-15 20:30:30 WIB`.
- Top-N dijelaskan sebagai sampel broker, bukan net seluruh pasar atau inventori investor.
- ML produksi tetap `TIDAK AKTIF`; status `NO_ACTIVE_INFERENCE_FOR_BAR`, bukan `NO_SIGNAL_ABSTAIN`.
- News tetap dijelaskan sebagai cakupan snapshot tersimpan, status `UNVERIFIED`.
- Freshness dan finality tetap terpisah.
- Audit membuktikan `provider_called=false`, `gemini_called=false`, `writes_executed=false`, `outbox_created=false`.
- Evidence, Analysis, dan jawaban SENT lama tidak diubah.

### Briefing readiness

- `hermes:morning-briefing` terjadwal weekday `08:30` WIB dengan `withoutOverlapping(10)` dan `runInBackground()`.
- Briefing memakai previous trading day EOD; plan mencatat session reference, coverage, board scope, dan freshness.
- Intraday feed tetap `QUARANTINED`; briefing tidak mengklaim realtime atau ML.
- Guarded dispatcher preflight lulus pada candidate DB dan role `aether_ingestion`; cache/storage terisolasi; destructive outbox/jobs privilege false.
- Briefing dry-run candidate lulus: `command_exit=0`, outbox sebelum/sesudah `10/10`, analysis runs `144/144`, `writes_executed=false`, `outbox_created=false`.
- Kesiapan command `READY_DRY_RUN`; ini bukan bukti trigger alami atau receipt Telegram.
- Tidak ada briefing nyata dijalankan ulang atau dikirim.
- Audit Telegram timeout tidak memicu replay. Job `#5`, Outbox `#5`, Outbox `#9`, dan correction Outbox `#10` tetap dipertahankan.

## Operational scheduling gate — 16 November 2026 07:52 WIB

- Task `AetherScheduledDispatcher_1min`: `Enabled`, `Ready`, last natural tick `07:52:01 WIB`, `Last Result=0`, next tick `07:53:00 WIB`.
- Registered command remains `wscript.exe` -> `scripts/run_silent.vbs` -> `scripts/run_scheduled_dispatcher.bat` -> `scripts/run_guarded_dispatcher.php`.
- Dispatcher preflight: candidate database `project_bot_recovered_20261115_022935`, role `aether_ingestion`, schema `public`, search path `public`, destructive outbox/jobs privileges `false`.
- Briefing schedule: weekday `08:30 WIB`; actual `2026-11-16` trigger not yet reached at audit time. No manual briefing or `schtasks /Run` used.
- Historical `hermes_morning_briefing.log` lines are not current-trigger evidence. Current trigger state: `CONFIGURED/ENABLED`, `TRIGGERED PENDING`, `ANALYSIS PENDING`, `DELIVERED PENDING`.
- Read-only publication ACL audit found one real gap: `prediction_signals INSERT=false`; `prediction_signals_id_seq USAGE=false`, `SELECT=false`.
- Minimum grant prepared at `scripts/grant_candidate_briefing_prediction_access.sql`. It is not applied. No password was requested or printed.
- Required analysis/evidence/outbox/receipt table DML and sequences are present; DELETE/TRUNCATE remain absent.
- Grant must be applied by local PostgreSQL administrator before a briefing with candidates can publish. No trial publication is used to discover this error.
- Briefing template now states EOD `13 Nov 2026`, coverage `496/499`, gaps `SRIL/SUGI/TDPM`, finality `PROVISIONAL`, ML production inactive, news `UNVERIFIED`, and no live/scalping/intraday RG claim.
- Job `#4` remains read-only audited: `started_at=2026-11-14T23:19:04Z`, evidence `2026-11-15T06:19:12Z`, analysis `2026-11-15T06:19:29Z`, latency `33930 ms`; timestamp cause remains `OPEN`.
- EOD task next natural slot remains `16:05 WIB`; automatic success remains `PENDING`.

## Briefing natural trigger and delivery — 16 November 2026 08:30 WIB

- ACL writer verified before slot: database `project_bot_recovered_20261115_022935`, role `aether_ingestion`; `prediction_signals INSERT=true`; `prediction_signals_id_seq USAGE=true`, `SELECT=true`; destructive grants `false`.
- Natural dispatcher evidence: Task Scheduler tick `08:30:01 WIB`; child schedule log recorded `artisan hermes:morning-briefing` at `08:30:02`; guarded child preflight passed with candidate DB and role.
- Command result: `AnalysisRun #145`, run ID `RUN_BRIEFING_20261116_083003_t8mk`, status `completed`, started `08:30:03 WIB`, completed `08:30:06 WIB`, error `null`.
- Reference session: EOD `13 Nov 2026`; finality `PROVISIONAL`; ML production remains inactive; intraday feed remains quarantined.
- Candidates: `4` conditional plans — `ACES SCALPING`, `AMRT SCALPING`, `AADI SWING`, `ACES SWING`. Historical expired signals were not reused.
- Outbox: `#11`, dedup key `MORNING_BRIEFING_20261116`, status `SENT`, retry count `0`, sent `08:30:37 WIB`.
- Telegram receipt: two-part message IDs `688` and `689`; metadata records `all_message_ids=["688","689"]`. Delivery status `VERIFIED`.
- No `DELIVERY_UNKNOWN`, no retry, no duplicate publication. Outbox `#5`, Job `#4`, and Job `#5` history unchanged.
- Audit found `AnalysisRun.metadata.trigger_source=MANUAL_CLI` despite natural dispatcher execution. This is an attribution defect only; outbox/task timestamps prove natural trigger. Fixed for future scheduled runs with guarded `AETHER_SCHEDULED_TRIGGER=1`; historical `#11` is not rewritten.
- Payload read-back checks passed: EOD `13 Nov 2026`, coverage `496/499`, gaps `SRIL/SUGI/TDPM`, `PROVISIONAL`, ML inactive, news `UNVERIFIED`, live price unavailable, and no intraday RG claim.
- Historical payload `#11` still contains candidate headings `SCALPING`; its explicit disclaimer says no live price and no intraday RG signal. Future renders now label this class `WATCHLIST CONDITIONAL (EOD; BUKAN INTRADAY RG)`; historical payload remains immutable.

## Realtime feed audit — 16 November 2026 08:37 WIB

- Documentation read: `docs/dokumentasi_api.md`, WebSocket running-trade contract version `1.0`, dated `16 November 2026`.
- Provider contract: WSS `/ws/price` max `1` price connection/account; running-trade supports `snapshot`, `trade`, `top5`, and optional filter. `250 ms` is provider update characteristic, not measured end-to-end latency.
- Existing owner inspected: `services/idx_edge_ws_supervisor.py`, PID file `services/ws_supervisor.pid` contains `5980`.
- PID `5980` is not active. No active `idx_edge_ws_supervisor.py` process found. No dedicated WebSocket Task Scheduler entry found.
- State file is stale: `session_date=2026-11-08`, last heartbeat `2026-11-08 19:02:54 WIB`, last message `2026-11-08 18:44:49 WIB`, `total_trades=0`, `total_top5=0`, `last_error=timed out during opening handshake`, `board_scope=BOARD_SCOPE_UNVERIFIED`.
- Existing state does not prove current feed capability. Do not classify as realtime-ready, RG-ready, or `NO_SETUP_FOUND`.
- No new socket opened. No second owner started. No live sample claimed.
- Runtime status remains `DATA_CAPABILITY_BLOCKED`; ML signals remain disabled.
- Next safe step: owner-controlled feed recovery requires lock/process identity verification, provider entitlement/key check, one bounded handshake during an active session, sanitized samples with `received_at`, and explicit `BOARD_SCOPE_UNVERIFIED` unless provider sends board field. Do not use `boards.freq/lot/value` as RG proof.

## Addendum realtime running-trade — 16 November 2026 09:06–09:11 WIB

### Preflight dan single owner

- `09:06:15 WIB`: PID file lama `services/ws_supervisor.pid` berisi `5980`; PID tidak aktif.
- Tidak ada proses `idx_edge_ws_supervisor.py` aktif.
- Tidak ada Windows Task Scheduler khusus WebSocket.
- Tidak ada koneksi provider running-trade aktif saat preflight.
- Dependency runtime: Python `3.14.7`, `websockets` `17.1`; collector memakai `additional_headers`, sesuai API dependency terpasang.
- `dashboard/.env` memiliki `IDX_EDGE_API_KEY`; nilai tidak dibaca atau dicetak.
- Lock OS ditambahkan ke `services/idx_edge_ws_supervisor.py`; isolated contention check: first lock `PASS`, second lock `False`, release `PASS`.
- Raw frame evidence ditambahkan ke `logs/ws_running_trade_raw.jsonl`; secret tidak disimpan.

### Bounded live runtime

- Satu collector resmi dijalankan melalui `services/idx_edge_ws_supervisor.py`.
- Wrapper shell PID: `22016`; supervisor PID pada state: `1156`.
- Handshake: `CONNECTION_VERIFIED`.
- Provider endpoint: `wss://stock.arjum.com/ws/running-trade`.
- Connected: `09:06:30 WIB`.
- Error handshake/runtime: tidak ada.
- Reconnect: `0`.
- Batas observasi: collector dihentikan setelah sampel bounded; tidak ada collector kedua.

### Sampel raw dan parser

- Raw evidence file: `logs/ws_running_trade_raw.jsonl`, `1,575,354` bytes saat audit.
- Frame diterima: `1 snapshot`, `5 top5`, `5,611 trade`.
- State collector: `5,618` trade, `5` top5, `0` reconnect, `0` duplicate dropped.
- Counter reconciliation gap: raw JSONL berisi `5,617` frame valid, sedangkan state `total_messages=5,574`; selisih `43` belum dijelaskan dan tidak boleh disebut zero-loss. `total_trades=5,618` konsisten dengan `5,611` frame trade plus `7` trade pada snapshot awal.
- Trade IDs: `5,611` tersedia; `5,611` unik pada frame trade audit; duplicate `0` dalam sampel bounded.
- Ring buffer eviction: `3,618`; ini hanya batas proteksi burst, bukan bukti paket hilang.
- Field teramati: `t`, `c`, `a`, `p`, `l`, `v`, `pc`, `tn`, `ts`.
- Sampel mencatat ticker, harga, lot, nilai IDR, `tn`, event time, provider timestamp, `received_at`, snapshot/live classification.
- `a` dan `ts` tersedia pada sampel. `ts` diperlakukan sebagai provider timestamp; latensi end-to-end tidak diklaim.
- `boards.freq/lot/value` tidak dipakai sebagai bukti board RG.

### Status acceptance terpisah

- `CONNECTION_VERIFIED`: PASS untuk satu bounded handshake.
- `STREAM_RECEIVED`: PASS untuk snapshot, trade, top5, serta satu price snapshot nyata.
- Price canary: `CONNECTION_VERIFIED`; `hello=1`, `subscribed=1`, `price=1`; urutan hello → subscribe → subscribed → price lulus; error `null`.
- `SCHEMA_VERIFIED`: PARTIAL; bentuk payload cocok dengan dokumentasi, tetapi semantik `c`, `ts`, dan board belum tersertifikasi upstream.
- `BOARD_SCOPE`: `BOARD_SCOPE_UNVERIFIED`; payload trade tidak memiliki field board.
- `GAP/RECONNECT_HANDLING`: NOT OBSERVED pada canary; code memiliki bounded transient backoff dan berhenti pada 4401/4403/4408.
- `DISPLAY_READY`: PASS untuk read-only state API/dashboard; state setelah shutdown ditandai `STALE_NO_HEARTBEAT`.
- `SIGNAL_READY`: NO; intraday signal, ML, Gemini per tick, dan alert tetap disabled.

### Dashboard integration

- Dashboard tetap read-only dan tidak membuka koneksi provider.
- API `GET /api/market/running-trade-state` membaca state collector canonical.
- API read-back setelah shutdown mengembalikan `STALE_NO_HEARTBEAT`, `total_trades=5618`, `total_top5=5`, dan `BOARD_SCOPE_UNVERIFIED`.
- `SystemHealthService::checkCollector()` kini menghitung heartbeat age dan membedakan `healthy`, `degraded`, `down`, serta freshness `CURRENT/STALE/NOT_CONNECTED`.
- Harga live, waktu update, status koneksi, freshness, cakupan board, dan data EOD tetap dipisahkan.

### Realtime conclusion

Transport dan stream nyata sudah terbukti. RG scope belum terbukti. Intraday display boleh menampilkan observasi terpisah dengan freshness; canonical RG candle, signal, ML, Gemini tick inference, dan trading alert tetap blocked.

### Remaining acceptance gates

- `PENDING`: trigger EOD otomatis sukses pada hari bursa.
- `PENDING`: full provider coverage dan residual gap resolution dengan bukti baru.
- `PARTIAL_VERIFIED`: WebSocket connection dan live transaction stream; RG board scope, provider semantics, reconnect, dan latency end-to-end belum verified.
- `NOT VERIFIED`: ML artifact, schema, reproducibility, forward validation, inference activation, promotion.
- `OPEN`: rekonsiliasi timestamp Job `#4`.
- `PRESERVED`: Outbox `#5 DELIVERY_UNKNOWN`, histori evaluasi, dan backup insiden.

## Addendum continuous realtime owner — 16 November 2026 10:02–10:03 WIB

### Counter reconciliation

- Old bounded sample remains immutable: raw evidence `5,617` valid records, types `snapshot=1`, `trade=5,611`, `top5=5`; old state `total_messages=5,574`, `total_trades=5,618`; old state lacked `run_id`, `connection_id`, `flush_sequence`, nanosecond cutoff, raw-write counter, parse/reject counters, and shutdown marker.
- Old sample limitation: exact `43` frame difference cannot be attributed beyond a flush-boundary/process-stop artifact. No zero-loss claim.
- Old `5,611` trade frames versus `5,618` total trades: snapshot carried `7` trade items counted into state, while raw frame type count counted trade messages. Raw snapshot payload was not preserved with a comparable per-item state counter.
- New instrumentation adds `run_id`, `connection_id`, `received_epoch_ns`, `flush_sequence`, `last_flush_at`, `raw_frames_written`, `raw_frames_failed`, `parse_errors`, `rejected_messages`, `accepted_messages`, `total_snapshots`, `total_other_messages`, `connected_socket`, `last_market_event_at`, `disconnected_at`, and `stopped_at`.
- New run `WSRUN_20261116_100236_613826a8`, connection `WSRUN_20261116_100236_613826a8_CONN_1`: at nanosecond cutoff, raw `1,167` equals state `total_messages=1,167`; parse errors `0`; rejected `0`; raw writes `1,167`; failed raw writes `0`.

### Continuous owner status

- One official owner remains active: `services/idx_edge_ws_supervisor.py`, supervisor PID `19452`, one connection ID.
- Current state: `CONNECTED`, `connected_socket=true`, heartbeat advancing, last market event advancing, `BOARD_SCOPE_UNVERIFIED`.
- Current observed counters at read-back: `total_messages=2,800`, `total_trades=2,840`, `total_top5=9`, `total_snapshots=1`, `total_other_messages=0`, `parse_errors=0`, `rejected_messages=0`, `raw_frames_written=2,800`, `raw_frames_failed=0`, `reconnect_count=0`.
- Raw rotation limit: `50 MiB`; one rotated file retained when limit is reached.
- Dashboard remains read-only and reads local canonical state. Browser does not open provider sockets.
- Dashboard now displays run ID, connection ID, heartbeat, freshness, last market event, snapshot/delta count, message/trade count, parse/reject count, and `BOARD_SCOPE_UNVERIFIED`.
- No EOD indicator was changed to intraday. Signals, ML, Gemini per tick, and trading alerts remain disabled.

### Lifecycle and recovery status

- Graceful shutdown fields are implemented: `STOPPED`, `connected_socket=false`, `disconnected_at`, and `stopped_at` after verified signal handling.
- Prior process termination through external tool bypassed signal handling; state stayed `CONNECTED` and dashboard correctly downgraded it to `STALE_NO_HEARTBEAT`. This is evidence of crash/stale detection, not graceful-stop proof.
- Reconnect path remains bounded with jittered backoff and non-retry close codes `4401`, `4403`, and `4408`. No reconnect event was observed in current run.
- No claim of lossless transport. Replay availability remains unverified.

### Provider clarification required

Request written answers from provider:

1. Does `/ws/running-trade` contain only Regular Board (`RG`), or can it contain Negotiated (`NG`) and Cash (`TN`) trades?
2. Does each `trade` payload have an omitted board field, or is board scope guaranteed by account/subscription? Provide example with explicit board.
3. What is exact origin, timezone, and date contract for `ts`? Is it provider receive time, exchange event time, or another clock?
4. Is field `a` exchange match time in WIB? How does it represent date across midnight, replay, and session rollover?
5. What deterministic formula defines `c=buy/sell`? Is it aggressor classification, provider label, or another rule?
6. Does `tn` remain unique across all symbols and boards for one session? Does it reset daily? Can reconnect replay repeat it?
7. Does reconnect provide replay or gap recovery? Identify snapshot overlap and gap semantics.
8. Do price and running-trade streams share account connection limits and takeover behavior?

No provider contact was made. No synthetic frames were added.

### Acceptance state

- `CONNECTION_VERIFIED`: PASS.
- `STREAM_RECEIVED`: PASS for running-trade snapshot/trade/top5 and price hello → subscribe → subscribed → price.
- `SCHEMA_VERIFIED`: PARTIAL.
- `BOARD_SCOPE_VERIFIED`: NO; `BOARD_SCOPE_UNVERIFIED` remains visible.
- `GAP/RECONNECT_HANDLING`: code bounded; live reconnect/replay not observed.
- `DISPLAY_READY`: PASS with freshness and provenance fields.
- `SIGNAL_READY`: NO by policy.

## Addendum continuous realtime owner — patched run 16 November 2026 10:23–10:25 WIB

### Counter model correction

- `total_messages` counts accepted and rejected WebSocket frames.
- `total_trade_frames` counts `type=trade` frames.
- `total_trades` counts parsed trade items, not frames.
- `total_snapshot_trade_items` counts `snapshot.recent` items.
- Current invariant: `total_messages = snapshot frames + trade frames + top5 frames + other frames`; `total_trades = trade items + snapshot trade items`.
- Current run `WSRUN_20261116_102351_b8043d02`, connection `WSRUN_20261116_102351_b8043d02_CONN_1`:
  - raw frames through same nanosecond cutoff: `1,934`
  - raw types: `snapshot=1`, `trade=1,930`, `top5=3`
  - state: `total_messages=1,934`, `total_trade_frames=1,930`, `total_trades=1,980`, `total_snapshot_trade_items=50`, `total_top5=3`
  - parse errors `0`, rejected frames `0`, invalid items `0`, accepted frames `1,934`
  - raw writes `1,934`, raw write failures `0`, cutoff delta `0`
- Earlier `5,611` versus `5,618` was frame/item mixing: `5,611` trade frames plus `7` snapshot items. New counters prevent this ambiguity.
- Earlier raw/state difference `43` remains unresolved because old state had no run ID, connection ID, flush sequence, or nanosecond cutoff. Old raw evidence was not edited.

### Lifecycle and continuous owner

- Graceful stop verified twice through the same owner: `STOPPED`, `connected_socket=false`, `disconnected_at`, `stopped_at`, atomic flush completed, lock released.
- One patched owner is active now: Python PID `6652`, one connection ID, `services/idx_edge_ws_supervisor.py`.
- Current API/browser read-back: `process=ALIVE`, `socket=CONNECTED`, freshness `CURRENT`, heartbeat advancing, last market event advancing.
- Current read-back later in the same run: `total_messages=11,973`, `total_trade_frames=11,953`, `total_trades=12,003`, `total_snapshot_trade_items=50`, `total_top5=19`, `parse/reject/invalid=0/0/0`, `raw_frames_written=11,973`, `shadow_events_emitted=135`.
- One natural upstream close occurred in the previous run at `10:15:12 WIB`, with `upstream_close_code_1006`, followed by reconnect. Previous code failed to increment `reconnect_count`; patch now increments it for transient close codes. Current patched run has `reconnect_count=0`; no forced disconnect or synthetic recovery was executed.
- Process heartbeat, socket state, and last market event are separate fields. `CONNECTED` without fresh heartbeat is downgraded to `STALE_NO_HEARTBEAT`.
- Market break/close with no transaction is not classified as feed error when heartbeat remains fresh.

### Shadow event mode

- Shadow mode emits only `LARGE_TRADE_OBSERVED` when documented transaction value `v` reaches `IDR 100,000,000`.
- Ledger: `logs/ws_shadow_events.jsonl`, bounded to `10 MiB` with one rotated file.
- Current ledger has `135` events for current run.
- Every event carries `mode=SHADOW_ONLY`, `board_scope=BOARD_SCOPE_UNVERIFIED`, `action_classification=NOT_USED`, `publication=DISABLED`, `gemini_called=false`, and `ml_called=false`.
- Shadow mode does not publish signals, call Gemini, call ML, infer aggressor from `c`, or treat feed as RG.

### Provider contract result

Available local documentation describes beta stream shape and close/reconnect recommendations, but does not certify:

- RG-only versus mixed RG/NG/TN scope.
- Exact `ts` origin and timezone.
- Date/timezone contract for `a` beyond `HH:MM:SS`.
- Deterministic meaning of `c=buy/sell`.
- Global uniqueness and reset scope of `tn`.
- Reconnect replay or gap recovery.

Provider questions remain prepared in this report. No provider contact was made.

### Updated acceptance

- `COUNTER_RECONCILIATION`: PASS for patched run; old `43` gap remains `NOT EXPLAINED`.
- `SINGLE_CONTINUOUS_OWNER`: PASS; one verified Python owner and OS lock.
- `DASHBOARD_LIVE_STATE`: PASS; local state polling, current heartbeat, process/socket/event fields visible.
- `GRACEFUL_SHUTDOWN`: PASS; verified `STOPPED` fields and lock release.
- `RECONNECT_RECOVERY`: PARTIAL; one natural `1006` reconnect observed in prior run; replay/gap recovery not proven.
- `BOARD_SCOPE`: `BOARD_SCOPE_UNVERIFIED`.
- `TIMESTAMP_SEMANTICS`: PARTIAL; raw `a` and `ts` preserved, provider origin unverified.
- `SIGNAL_READY`: NO; shadow-only observation, ML disabled, Gemini per tick disabled.

## Addendum shadow quality and EOD readiness audit — 16 November 2026 10:41 WIB

### Shadow event rule

- Trigger is one parsed `type=trade` item with `value_idr >= 100000000`.
- Input fields: `v` as transaction value; fallback `p * l * 100`; `t`, `tn`, `p`, `l`, `a`, and raw `ts` are preserved.
- `c` is not used. Board remains `BOARD_SCOPE_UNVERIFIED`.
- Event window is `SINGLE_TRADE_ONLY`. No rolling indicator window or full-window requirement exists.
- Future notification candidates use ticker dedup and `60s` cooldown. Cooldown does not delete raw evidence or shadow events.
- Same ticker may emit repeatedly in the shadow ledger. This is observation count, not trading accuracy, aggressor flow, accumulation, or signal quality.
- Shadow ledger rotates at `10 MiB`; raw evidence rotates separately at `50 MiB`.

### Audit of reported 233-event prefix

- Run: `WSRUN_20261116_102351_b8043d02`.
- Connection: `WSRUN_20261116_102351_b8043d02_CONN_1`.
- Audited prefix: `233` events. Current run later reached `810` events.
- Event type: `LARGE_TRADE_OBSERVED=233`.
- Threshold failures: `0`.
- Source trade-number matches: `233/233`; each matched exactly one raw trade item.
- Shadow trade-number duplicates: `0`.
- Raw trade-number duplicate extras: `0`.
- Missing `received_at_utc` or `event_time_raw`: `0`.
- Snapshot overlap: `0`; snapshot had `50` items, and shadow emits only in live `trade` branch.
- Tickers represented: `39`.
- Most repeated tickers: `BMRI=61`, `PTRO=19`, `AADI=18`, `BUMI=15`, `BBRI=12`, `ASII=12`, `BBCA=11`.
- Repeated ticker events remain possible in the raw shadow ledger. Candidate notification cooldown is `60s`; raw events are never removed.
- Prefix event intervals: minimum `0s`, median `0s`, maximum `8s`.
- Same-ticker intervals: minimum `0s`, median `0s`, maximum `144s`.
- Every audited event had `publication=DISABLED`, `gemini_called=false`, `ml_called=false`, `action_classification=NOT_USED`, and `BOARD_SCOPE_UNVERIFIED`.

### Shadow examples for local review

- `RAJA` — `LARGE_TRADE_OBSERVED` — `Rp 109,100,000` — single trade window — source `a=10:23:53`, `received_at_utc=2026-11-16T03:23:52Z` — `NO_GAP_OBSERVED_BEFORE_EVENT` — `BOARD_SCOPE_UNVERIFIED`.
- `BUVA` — `LARGE_TRADE_OBSERVED` — `Rp 232,376,000` — single trade window — source `a=10:26:48`, `received_at_utc=2026-11-16T03:26:48Z` — `NO_GAP_OBSERVED_BEFORE_EVENT` — `BOARD_SCOPE_UNVERIFIED`.
- `SINI` — `LARGE_TRADE_OBSERVED` — `Rp 309,750,000` — single trade window — source `a=10:42:23`, `received_at_utc=2026-11-16T03:42:22Z` — `GAP_OBSERVED_BEFORE_EVENT` — `BOARD_SCOPE_UNVERIFIED`.
- Examples are local audit output only. No Telegram, Gemini, ML, or strategy publication occurred.

### Continuity and gap audit

- Current run raw frames: `85,755` at audit cutoff.
- Raw types at exact cutoff: `snapshot=1`, `trade=85,555`, `top5=199`.
- One received-frame interval exceeded `2s`: `2.402s`. No interval exceeded `10s`.
- This gap is an observation gap, not proof of packet loss. Provider replay and recovery remain unverified.
- Current session state: `market_status=open`, `Sesi 1 berlangsung (09:00-12:00 WIB)`.
- Session 1 coverage is `PARTIAL` from `10:23:52 WIB`; Session 2 remains `UNOBSERVED_UNTIL_PROVEN`. Any complete-session or full-day indicator remains blocked.
- `upstream_ping_latency_ms` reached `2559.19 ms` in one state read-back. This is transport heartbeat telemetry, not end-to-end market latency.
- No forced disconnect, replay, or second collector was used.
- Current process remains PID `6652`; collector was not restarted.

### Capability blockers by feature

- Monitoring display: allowed with current/freshness labels, event time, received time, and `BOARD_SCOPE_UNVERIFIED`.
- Descriptive shadow alert: local-only observation allowed; publication remains disabled. Must disclose single-trade window and gap status.
- RG claim: blocked until provider certifies board scope or supplies explicit board field.
- Order-flow/aggressor analysis: blocked; `c`, `net_buy`, and `net_sell` semantics unverified.
- Strategy signal: blocked; full window, RG scope, timestamp contract, replay behavior, and ML readiness are not proven.

### Reconciled raw-frame audit — same run and cutoff

- Run: `WSRUN_20261116_102351_b8043d02`.
- Cutoff: `received_epoch_ns=1794800443817954300`.
- Exact raw-frame total: `85,755`.
- Complete frame classification: `snapshot=1`, `trade=85,555`, `top5=199`.
- Exact sum: `1 + 85,555 + 199 = 85,755`.
- Previous component counters `trade=83,849` and `top5=192` were stale.
- Reported difference `1,713` is counter drift, not unexplained raw frames.
- No raw evidence was edited. No packet-loss cause was assigned.
- One received-frame interval exceeded `2s`: `2.402s`. No interval exceeded `10s`.
- Provider replay and reconnect recovery remain unverified.

### Session metadata and timestamp audit

- Session date: `2026-11-16`.
- `observed_from`: `2026-11-16 10:23:52` local WIB state.
- `observed_until`: read from current state; not full-session proof.
- Session 1: `PARTIAL`, not `UNOBSERVED`.
- Session 2: `UNOBSERVED_UNTIL_PROVEN` until its own observation begins.
- Gap: `NOT_ESTABLISHED`; no inferred missing interval.
- Live PID `6652` still holds pre-patch state. API and service readers normalize stale `SESSION_2_ONLY` to partial metadata without rewriting state or restarting collector.
- Host clock read-back: Windows 11, `SEAST +0700`; Python clock precision available at nanoseconds.
- Raw `a` is `HH:MM:SS`; raw `ts` provider epoch semantics remain uncertified.
- Sample `a` values often lead same-date `received_at` by about one second.
- `ts - received_at` audit: minimum `-8.0885s`, median `+1.1009s`, maximum `+1.2177s`, `85,555` trade items.
- These values cannot prove latency. Raw timestamps remain unchanged.
- Output status: `EVENT_TIME_UNVERIFIED`; latency claim: `NOT_CLAIMED`.

### Shadow local summary and notification candidate policy

- Ledger remains `SINGLE_TRADE_ONLY`; snapshot never emits shadow events.
- Ledger summary at `2026-11-16T04:27:58Z`: `1,907` large transactions, total observed value `Rp 567,855,584,311`, `117` tickers.
- Received range: `2026-11-16T03:23:52Z` to `2026-11-16T04:27:58Z`.
- This means activity inside observed feed only. It is not total market turnover and not net buying.
- Summary file: `logs/ws_shadow_summary.json`.
- Future notification candidates use ticker dedup plus `60s` cooldown.
- Raw evidence remains append-only and unaffected by cooldown.
- Candidate publication remains `DISABLED`.
- Historical ledger entries lack new `event_time_status`; audit marks all `1,907` as `EVENT_TIME_UNVERIFIED`.

### Isolated fixture verification

- File: `services/test_idx_edge_ws_supervisor_isolated.py`.
- Tests cover snapshot exclusion, duplicate trade-number handling, reconnect counter, unclear timestamp, value fallback, and notification cooldown.
- Result: `5 tests`, `OK`.
- Test path uses Python `unittest`; no candidate DB, operational DB, socket, or provider.

### EOD afternoon readiness audit

- Task `\\HermesMarketIntelligence_EOD`: enabled, weekly MON–FRI, next run `2026-11-16 16:05:00`, action `wscript.exe "E:\\Aether-Market-Intelligence\\scripts\\run_silent.vbs" "E:\\Aether-Market-Intelligence\\scripts\\run_guarded_eod.bat"`.
- Guarded action resolves to `run_guarded_eod.ps1`, then `scripts/launch_ingestion_writer.php --eod-daily`.
- Candidate DB: `project_bot_recovered_20261115_022935`.
- Runtime role: `aether_ingestion`.
- EOD writer sets `AI_LIVE_INFERENCE_ENABLED=false`, `AI_LIVE_INFERENCE_EOD_ENABLED=false`, and uses candidate DB/role environment.
- Task execution limit: `PT30M`; inner EOD timeout: `1740` seconds.
- Lock: `E:\\Aether-Market-Intelligence\\storage\\runtime\\eod-scheduler.lock`; absent at audit, so no active EOD run.
- Log capacity: EOD run directory `137` files, `488,470` bytes. EOD scheduler log `19,423` bytes. Available `E:` space `59,684,765,696` bytes.
- Last EOD task result: `75`; no natural successful EOD trigger has been observed. EOD remains `PENDING`.
- No manual EOD trigger was run.

### Updated acceptance

- `RAW_FRAME_RECONCILIATION`: PASS; exact same-cutoff classification sums to `85,755`.
- `SHADOW_RULE_AUDIT`: PASS for threshold, source linkage, snapshot exclusion, duplicate check, and publication disablement.
- `SHADOW_WINDOW_QUALITY`: NOT READY for indicator use; single-trade only, no full-window contract.
- `SHADOW_NOTIFICATION_POLICY`: CONFIGURED; ticker dedup and `60s` cooldown affect candidates only.
- `EVENT_TIME`: `EVENT_TIME_UNVERIFIED`; no latency claim.
- `RECONNECT_REPLAY_PROOF`: NOT OBSERVED; no claim that reconnect recovered missed trades.
- `CONTINUITY`: PARTIAL; one `2.402s` observed raw gap, no `>=10s` gap, but transport losslessness remains unproven.
- `SESSION_COVERAGE`: PARTIAL; Session 1 partial from `10:23:52`, Session 2 not observed in current window.
- `EOD_READINESS`: CONFIGURED and guarded; natural success remains `PENDING` until child exit, target session, coverage, metrics, queue, and lock release are read back.
- `SINGLE_OWNER`: PASS; current PID `8132`, one collector, no restart during this audit.
- `SIGNAL_READY`: NO; strategy signal, shadow publication, Gemini realtime, and ML remain disabled.

### Reconnect audit: `WSRUN_20261116_114926_b5d7fa64`

- Audit time: `2026-11-16 12:30:34 WIB`.
- Current owner: PID `8132`, one `idx_edge_ws_supervisor.py` process, status `CONNECTED`, `connected_socket=true`.
- Current heartbeat: `12:30:30 WIB`. Last market event and last message: `11:59:59 WIB`.
- Active socket is running-trade only: `wss://stock.arjum.com/ws/running-trade`. No current price socket exists under this owner.
- Price evidence file contains only three old frames; last frame `2026-11-16T02:21:12.667828+00:00Z`. Price takeover is not indicated.

#### Connection timeline

- `CONN_1`: raw first `04:49:26Z`; raw last `04:56:12Z`; observed raw duration `405.564s`; socket close timestamp missing.
- First transition after `CONN_1` has no close code or error record. Exact cause is `UNKNOWN`.
- Retry 1: connect `11:56:29.108`, `TimeoutError` `11:56:39.294`, attempt `10.186s`.
- Retry 2: connect `11:56:43.641`, `TimeoutError` `11:56:53.659`, attempt `10.018s`.
- Retry 3: connect `11:57:02.097`, `TimeoutError` `11:57:12.127`, attempt `10.030s`.
- Retry 4: connect `11:57:28.461`, `TimeoutError` `11:57:38.474`, attempt `10.013s`.
- Retry 5: connect `11:58:08.504`, connected `11:58:09.357`, attempt `0.853s`.
- Observed backoff between failed attempts: `4.347s`, `8.438s`, `16.334s`, `30.030s`.
- `CONN_6`: raw first `04:58:09Z`; raw last `04:59:59Z`; observed raw duration `110s`; minimum connected-to-audit duration `1,944.643s`.
- Five reconnects are state-recorded. Four have proven `TimeoutError`; one prior close transition lacks log evidence.
- Heartbeat is healthy. A keepalive timeout is not proven for this current run. Takeover is not proven. Close code `4408` is not observed.

#### Dedup, cooldown, snapshot

- Raw live trade items: `18,346`.
- Unique live `tn`: `18,346`.
- Duplicate live `tn` records: `0`.
- Snapshot items: `100` across two reconnect snapshots.
- Snapshot/live `tn` overlap: `0`.
- Shadow events: `147`; `CONN_1=126`, `CONN_6=21`.
- Snapshot shadow events: `0`.
- Shadow duplicate events: `0`.
- Notification candidates: `67`; unique candidate `tn`: `67`; `CONN_1=52`, `CONN_6=15`.
- Cooldown suppressions: `80`; cooldown remains `60s`.
- Candidate and shadow event identity remain distinct from raw event count.
- No same-ticker candidate appeared within `60s` across observed `CONN_1` and `CONN_6` events. Cross-connection suppression behavior is configured and state-preserved, but this audit did not observe a cross-connection suppression hit.
- Snapshot reconnect added zero shadow events, zero notification candidates, and zero snapshot/live overlap.

#### Session 2 and counter limitation

- At `12:30:34 WIB`, Session 2 has not started or has not been observed. No market-event motion proof exists for Session 2.
- Heartbeat healthy, socket connected, and new transactions are separate claims. New transactions were proven in `CONN_6` during the prior observed window only.
- Gap recovery and replay remain `UNKNOWN`.
- Collector state `total_trades=4,443` is not a cross-connection total. Snapshot reconnect handling resets this state counter.
- Raw evidence is authoritative for this audit: `18,346` live trade items across `CONN_1` and `CONN_6`.
- This counter reset blocks any claim that state counters alone represent full-run totals. No raw evidence was changed.

#### Canary and publication gate

- Canary already exists and is already sent: Evidence `#162`, Outbox `#13`, Telegram `message_id=691`.
- Fixed idempotency key: `SHADOW_DESCRIPTIVE_CANARY_V1`.
- No recreate. No retry. `DELIVERY_UNKNOWN` Outbox `#5` remains untouched.
- Periodic shadow publication, trading signals, ML, and Gemini realtime remain disabled.
- Natural EOD trigger at `16:05 WIB` remains untouched.
- Detailed machine-readable audit: `logs/ws_reconnect_audit_WSRUN_20261116_114926_b5d7fa64.json`.

#### Reconnect audit acceptance

- `RECONNECT_TIMELINE`: PARTIAL; four `TimeoutError` failures proven, first transition close code/error missing.
- `BACKOFF`: PROVEN; observed bounded sequence `4.347s`, `8.438s`, `16.334s`, `30.030s`.
- `PRICE_VS_RUNNING_TRADE`: PROVEN; current owner uses running-trade endpoint only.
- `TAKEOVER`: NOT PROVEN; no `4408` or equivalent evidence.
- `HEARTBEAT`: HEALTHY; not proof of market-event motion.
- `DEDUP`: PASS for observed raw `tn` records and candidate identity.
- `COOLDOWN`: PASS for aggregate suppression; cross-connection suppression hit not observed.
- `SNAPSHOT_RECONNECT`: PASS; no shadow/candidate alert and no snapshot/live overlap.
- `SESSION_2_MOTION`: PENDING; session has not started or has not been observed.
- `REPLAY_GAP`: UNKNOWN.

### Counter-fix handover and Session 2 observation

- Old owner `PID 1096`, run `WSRUN_20261116_125121_9f9cf002`, stopped gracefully at `12:56:01 WIB` after source validation.
- Handover checkpoint: `logs/ws_owner_handover_checkpoint_20261116_125300.json`.
- New owner: PID `11512`, run `WSRUN_20261116_125626_02b035c9`, one collector, one running-trade socket.
- New run is not continuation of old run. No raw evidence or historical run was rewritten.
- Counter source now separates run and connection scopes:
  - raw frames;
  - live trade items;
  - unique accepted live trade items;
  - snapshot replay items;
  - duplicate items;
  - ring-buffer size.
- Snapshot handling no longer assigns snapshot length to `total_trades` or resets run counters.
- Dedup scope is explicit: `RUN_SCOPED_BOUNDED_RING_BUFFER_TN`; protection remains bounded, not persistent ledger proof.
- Close/error state now records `connection_events`, close code, error type, handshake timeout flag, successful connections, reconnect attempts, and successful reconnects.
- API and health payload expose separate `run_counters` and `connection_counters`. Legacy `total_trades` is null with label `COUNTER_UNVERIFIED_ACROSS_CONNECTIONS`.
- Dashboard no longer renders legacy `total_trades` as valid cross-connection total.
- New runtime checkpoint: `logs/ws_counter_fix_runtime_checkpoint_WSRUN_20261116_125626_02b035c9.json`.

#### New-run runtime read-back

- At `13:03:48 WIB`: status `CONNECTED`, socket `true`, PID `11512`, collector count `1`.
- Heartbeat: `13:03:48 WIB`; last market event: `12:56:27 WIB`.
- Raw frames: run `1`, connection `1`.
- Live trade items: run `0`, connection `0`.
- Unique accepted live items: run `0`, connection `0`.
- Snapshot items: run `0`, connection `0`.
- Duplicate items: run `0`, connection `0`.
- Connection events: one `CONNECTED`; no reconnect yet.

#### Session 2 status

- Heartbeat is healthy.
- Socket is connected.
- New market transactions after Session 2 boundary: `0`.
- Market-event motion: `NOT_PROVEN`.
- Session 2: `NOT_OBSERVED_YET`.
- Gap/replay: `UNKNOWN`; no recovery claim.

#### Counter-fix acceptance

- `COUNTER_SCOPE`: PASS; run and connection counters separate.
- `SNAPSHOT_NO_RESET`: PASS in isolated fixture and new runtime initialization.
- `FRAME_ITEM_SEPARATION`: PASS; isolated fixture proves raw frame count differs from live item count.
- `CLOSE_ERROR_LEDGER`: IMPLEMENTED; no new close event yet in current run.
- `API_LABEL`: PASS; legacy total is `COUNTER_UNVERIFIED_ACROSS_CONNECTIONS`.
- `DASHBOARD_LABEL`: PASS in source; browser endpoint was unavailable during this audit, so visual browser rendering is `NOT VERIFIED`.
- `SESSION_2_MOTION`: PENDING; heartbeat and socket do not prove market transactions.
- `NEW_RUN_CONTINUITY`: NOT CLAIMED; checkpoint recovery is not implemented.
- Fixture command: `python -m unittest services.test_idx_edge_ws_supervisor_isolated -v`.
- Fixture result: exit code `0`; `11` tests; `34` assertion calls; candidate DB not used.
- Fixture proves trade → reconnect → snapshot → duplicate/new trade counter invariants, snapshot alert exclusion, frame/item separation, timeout metadata, and cooldown helper behavior.
- `SOURCE_DEPLOYED`: VERIFIED; counter schema, snapshot no-reset logic, scoped API labels, and close/error ledger are in source.
- `RUNTIME_VERIFIED`: PARTIAL; source runtime produced Session 2 market motion, but natural reconnect is not yet observed.
- Session 2 observation checkpoint: `logs/ws_session2_observation_checkpoint_WSRUN_20261116_125626_02b035c9.json`.
- Session 2 observation window: `13:30:00–13:36:18 WIB` (`378s` minimum).
- First new trade: `2026-11-16T06:30:00Z` / `13:30:00 WIB`, ticker `BRPT`, `tn=202611160000986079`, run `WSRUN_20261116_125626_02b035c9`, connection `..._CONN_1`.
- Last observed trade: `2026-11-16T06:36:17Z`, `tn=202611160001019130`.
- Raw read-back: `21,575` frames; `21,501` trade items; `1` snapshot; `73` top5.
- State read-back: `21,576` raw frames, `21,502` live items, `21,502` unique accepted live items, ring size `2,000`, duplicate items `0`.
- Dashboard API read-back: HTTP `200`, `CONNECTED`, socket `true`, heartbeat `13:36:15 WIB`, last message/event `13:36:17 WIB`, Session 2 `PARTIAL`.
- Heartbeat, socket connectivity, provider messages, and new transactions are recorded separately.
- Cross-connection counter verification remains `PENDING_UNTIL_NATURAL_RECONNECT`.
- `GAP_REPLAY`: `UNKNOWN`; no lossless or recovery claim.
- EOD task remains `Ready`, next natural trigger `16:05 WIB`; manual trigger remains false.
- Canary remains Evidence `#162` → Outbox `#13` → Telegram `message_id=691`; no recreate or retry.
- Active owner remains PID `11512`, run `WSRUN_20261116_125626_02b035c9`; no restart performed for this audit.
- EOD task remains `Ready`, next run `16:05 WIB`; no manual trigger.
- Periodic publication, strategy signals, ML, and Gemini realtime remain disabled.
- EOD natural trigger at `16:05 WIB` remains untouched.

### Controlled owner handover and latest runtime canary

- Previous owner `PID 6652`, run `WSRUN_20261116_102351_b8043d02`, stopped gracefully at `11:41:32 WIB`.
- Canary remains Evidence `#162` → Outbox `#13` → Telegram `message_id=691`; no recreate or retry.
- Active owner remains PID `11512`, run `WSRUN_20261116_125626_02b035c9`; no restart performed for this audit.
- EOD task remains `Ready`, next run `16:05 WIB`; no manual trigger.
- Periodic publication, strategy signals, ML, and Gemini realtime remain disabled.
- EOD natural trigger at `16:05 WIB` remains untouched.

### Controlled owner handover and latest runtime canary

- Previous owner `PID 6652`, run `WSRUN_20261116_102351_b8043d02`, stopped gracefully at `11:41:32 WIB`.
- Stop read-back: `STOPPED`, `connected_socket=false`, `flush_sequence=8565`, `raw_frames_written=250,561`, no collector process remained.
- Handover gap: `11:41:32` to `11:49:26 WIB`. No replay, recovery, or gap closure claim.
- Latest owner: `PID 8132`, run `WSRUN_20261116_114926_b5d7fa64`, one collector, one socket.
- Latest connection currently advanced to `WSRUN_20261116_114926_b5d7fa64_CONN_6`; reconnect count reached `5`. Reconnect replay remains unverified.
- Latest run artifact paths are run-scoped:
  - `logs/ws_shadow_summary_WSRUN_20261116_114926_b5d7fa64.json`
  - `logs/ws_shadow_notification_candidates_WSRUN_20261116_114926_b5d7fa64.jsonl`
- Latest live read-back: `snapshot=1`, `trade frames=23,980`, `top5=951`, `total messages=24,931`, `total trades=24,030`, parse/reject/invalid `0`, raw write failures `0`.
- Latest run shadow summary at checkpoint: `147` threshold transactions, `Rp 130,829,301,700`, `41` tickers, `67` notification candidates, `80` cooldown suppressions.
- Session metadata: `SESSION_1_PARTIAL` by reader normalization; collector-native state remains `PARTIAL_SESSION_UNTIL_PROVEN`, `observed_from=11:49:26 WIB`, Session 2 not yet observed.
- Runtime fields proven: `EVENT_TIME_UNVERIFIED`, `BOARD_SCOPE_UNVERIFIED`, snapshot exclusion, run-scoped summary, ticker cooldown, global candidate ledger path.

### One-shot descriptive Telegram canary

- Fixed global dedup key: `SHADOW_DESCRIPTIVE_CANARY_V1`.
- Dry-run passed with active run, run-scoped summary, zero writes, and explicit limitation text.
- First live attempt exposed and fixed processor closure capture bug for `onlyOutboxId`; no Telegram network send occurred before fix.
- Existing canary was resumed without creating new evidence or outbox rows.
- Evidence: `#162`.
- Outbox: `#13`, status `SENT`.
- Telegram outbound `message_id=691`.
- Canary send-time payload: `99` threshold transactions, `Rp 24,434,476,100` observed value, `27` tickers.
- Payload explicitly states feed sample only, not total market, not net buying, not recommendation, not ML signal, `EVENT_TIME_UNVERIFIED`, `BOARD_SCOPE_UNVERIFIED`, and periodic publication disabled.
- Global one-canary limit now blocks further publication. No retry or second canary allowed.
- Gemini realtime remained disabled. Consultation Gemini configuration remained unchanged.

### Canary acceptance

- `CONTROLLED_HANDOVER`: PASS; graceful stop, flush, lock owner release, one latest owner.
- `LATEST_RUNTIME_FEATURES`: PASS; cooldown suppression, run-scoped summary, event-time label, board label, snapshot exclusion.
- `CANARY_EVIDENCE`: PASS; Evidence `#162`.
- `CANARY_OUTBOX`: PASS; Outbox `#13`, fixed dedup key, `SENT`.
- `CANARY_TELEGRAM_RECEIPT`: VERIFIED; numeric outbound `message_id=691`.
- `PERIODIC_SHADOW_PUBLICATION`: DISABLED.
- `EOD`: still natural Task Scheduler-owned at `16:05 WIB`; no manual trigger, status remains `PENDING` until full post-run read-back.
- `Outbox #5`: unchanged `DELIVERY_UNKNOWN`; no retry.
- `CLAIMS_BLOCKED`: replay recovery, gap closure, lossless transport, full-session coverage, RG scope, end-to-end latency, net buying, strategy signal.

### EOD afternoon readiness audit

- Task `\\HermesMarketIntelligence_EOD`: enabled, weekly MON–FRI, next run `2026-11-16 16:05:00`, action `wscript.exe "E:\\Aether-Market-Intelligence\\scripts\\run_silent.vbs" "E:\\Aether-Market-Intelligence\\scripts\\run_guarded_eod.bat"`.
- Guarded action resolves to `run_guarded_eod.ps1`, then `scripts/launch_ingestion_writer.php --eod-daily`.
- Candidate DB: `project_bot_recovered_20261115_022935`.
- Runtime role: `aether_ingestion`.
- EOD writer sets `AI_LIVE_INFERENCE_ENABLED=false`, `AI_LIVE_INFERENCE_EOD_ENABLED=false`, and uses candidate DB/role environment.
- Task execution limit: `PT30M`; inner EOD timeout: `1740` seconds.
- Lock: `E:\\Aether-Market-Intelligence\\storage\\runtime\\eod-scheduler.lock`; absent at audit, so no active EOD run.
- Log capacity: EOD run directory `137` files, `488,470` bytes. EOD scheduler log `19,423` bytes. Available `E:` space `59,684,765,696` bytes.
- Last EOD task result: `75`; no natural successful EOD trigger has been observed. EOD remains `PENDING`.
- No manual EOD trigger was run.

### Updated acceptance

- `RAW_FRAME_RECONCILIATION`: PASS; exact same-cutoff classification sums to `85,755`.
- `SHADOW_RULE_AUDIT`: PASS for threshold, source linkage, snapshot exclusion, duplicate check, and publication disablement.
- `SHADOW_WINDOW_QUALITY`: NOT READY for indicator use; single-trade only, no full-window contract.
- `SHADOW_NOTIFICATION_POLICY`: CONFIGURED; ticker dedup and `60s` cooldown affect candidates only.
- `EVENT_TIME`: `EVENT_TIME_UNVERIFIED`; no latency claim.
- `RECONNECT_REPLAY_PROOF`: NOT OBSERVED; no claim that reconnect recovered missed trades.
- `CONTINUITY`: PARTIAL; one `2.402s` observed raw gap, no `>=10s` gap, but transport losslessness remains unproven.
- `SESSION_COVERAGE`: PARTIAL; Session 1 partial from `10:23:52`, Session 2 not observed in current window.
- `EOD_READINESS`: CONFIGURED and guarded; natural success remains `PENDING` until child exit, target session, coverage, metrics, queue, and lock release are read back.
- `SINGLE_OWNER`: PASS; PID `8132`, one collector, latest owner healthy.
- `SIGNAL_READY`: NO; strategy signal, shadow publication, Gemini realtime, and ML remain disabled.

### New audit: counter cutoff, disabled descriptive alert, EOD waiting

- Counter cutoff checkpoint: `logs/ws_counter_cutoff_audit_WSRUN_20261116_125626_02b035c9.json`.
- Same-cutoff recount at `state.last_raw_frame_epoch_ns=1794812172387075500` matched: `81,185` raw rows, `80,874` trade items, `80,874` unique `tn`; state counters matched exactly.
- Earlier `21,501` versus `21,502` was a non-atomic read cutoff. No packet-loss or counter-bug claim made.
- Natural reconnect has not occurred; cross-connection counter remains `PENDING_UNTIL_NATURAL_RECONNECT`.
- Descriptive alert configuration checkpoint: `logs/descriptive_alert_disabled_config_20261116.json`.
- Alert config remains disabled: `900s` summary interval, `1` global message/session, run/session idempotency key, minimum `300s` / `100` trades / `100` unique `tn` / `5` tickers, stale and gap holds, reconnect reconciliation hold.
- Config forbids net-buying, net-selling, buy-signal, sell-signal, recommendation, and market-total claims. No Gemini per tick.
- EOD audit checkpoint: `logs/eod_natural_trigger_audit_20261116_135600.json`.
- EOD target session: `2026-11-16`; candidate DB `project_bot_recovered_20261115_022935`; role `aether_ingestion`; schema `public`; eligible scope excludes `UNSUPPORTED_SHARE_CLASS`; provider `IDX Edge PRO`; RG and EOD classifications are configured.
- EOD natural tick remains `WAITING` for `16:05 WIB`; provider result, coverage, metrics, queue, child exit/heartbeat, SQL identity readback, and lock release remain `WAITING`.
- No manual trigger, collector restart, canary retry, periodic publication, or strategy signal performed.

### New audit: natural reconnect and cross-connection counter continuity

- Checkpoint: `logs/ws_reconnect_counter_checkpoint_WSRUN_20261116_125626_02b035c9.json`.
- Natural close: `14:14:51 WIB`, code `1006`, `ConnectionClosedError`, retryable, handshake timeout false.
- Natural reconnect: `14:14:55 WIB`, new connection `WSRUN_20261116_125626_02b035c9_CONN_2`.
- Run unchanged: `WSRUN_20261116_125626_02b035c9`; PID `11512`; collector count `1`.
- Runtime counters: `successful_connection_count=2`, `successful_reconnects=1`, `reconnect_attempts=1`.
- Same-cutoff readback matched raw sum: run raw frames `110,610`; run live trade items `110,188`; run unique accepted `110,188`; snapshot items `50`; duplicates `0`.
- Connection split: `CONN_1=105,500` frames / `105,091` trades; `CONN_2=5,110` frames / `5,097` trades.
- Cross-connection `tn` overlap: `0` observed.
- Run counter reset: `NOT_OBSERVED`.
- Dashboard API at `14:16:30 WIB`: HTTP `200`, `CONNECTED`, `CONN_2`, last message/event `14:16:30 WIB`.
- Reconnect continuity is verified for observed counters. Replay recovery, gap closure, and lossless transport remain unknown/unclaimed.
- `counter_status` remains `COUNTER_UNVERIFIED_ACROSS_CONNECTIONS` because broader replay and gap semantics are not certified.
- EOD natural trigger remains waiting for `16:05 WIB`; no manual trigger.
- Canary #13 remains SENT; no recreate or retry.

### API checkpoint-bound verification status

- API readback: HTTP `200`; run `WSRUN_20261116_125626_02b035c9`; current connection `..._CONN_3`.
- `counter_verification.status=VERIFIED` only because current run matches verified checkpoint run.
- Verified cutoff: `1794813354973798900`; verified at `2026-11-16 14:16:34 WIB`; scope lists raw/state counters, unique `tn`, snapshot, duplicates, cross-connection overlap, and reset behavior.
- Future run rule: `CHECKPOINT_BOUND; FUTURE_RUNS_REQUIRE_NEW_VERIFICATION`.
- Disconnect `14:14:51–14:14:55 WIB`: `4s`, classified `CONNECTION_PAUSE_ONLY`; transaction loss `NOT_DETERMINED`; recovery `UNKNOWN`.
- Replay recovery and gap closure remain `UNKNOWN`.
- Alert configuration remains disabled and JSON-valid.
- Collector remains PID `11512`, count `1`; no restart.
- EOD remains `Ready`, next `16:05 WIB`, last result `75`; natural data, metrics, queue, child exit, and lock release still pending.
- Subsequent `CONN_3` and `CONN_4` activity is post-checkpoint; API now returns `counter_verification.status=PENDING` for current cutoff `1794814758128968700`.
- Historical `VERIFIED` status remains limited to cutoff `1794813354973798900` at `2026-11-16 14:16:34 WIB`; no permanent VERIFIED label applies to later runtime state.
- Current `CONN_4` readback: `3,359` raw frames, `3,340` live items, `3,340` unique accepted, `50` snapshot items, `0` duplicates.
- Disconnect `CONN_3`: `14:37:46–14:37:50 WIB`; classified as connection pause only. Transaction loss and recovery remain undetermined.

### Natural EOD 2026-11-16 result

- Checkpoint: `logs/eod_natural_trigger_checkpoint_20261116_160532.json`.
- Task Scheduler natural tick: `VERIFIED` at `16:05:00 WIB`; no manual trigger.
- Guarded run: `20261116_160501_33eba57131074e55bc281dfd41bc0c8f`; child PID `23460`; target session `2026-11-16`.
- Guarded chain started and ended. Wrapper recorded `exit_code=0`, but child semantic result is `FAILED`.
- Failure: `SQLSTATE[42501]` — `permission denied for table idx_company_registries` while persisting `WAITING_FOR_SESSION_DATA`.
- DB identity read-only: `project_bot_recovered_20261115_022935`, role `aether_ingestion`, schema `public`, search path `\"$user\", public`.
- Provider last bar: `2026-11-13`; target `2026-11-16` raw and adjusted bars remain `WAITING`.
- Metrics scope: `BAR_SESSION_MISSING=499`; BBCA target metric row `0`; latest metric date `2026-11-13`.
- Queue status: `FAILED`; queue stats not available in readback. Lock absent after run, but this does not repair failed ingestion.
- Retry not executed. Permission path must be resolved first; scheduled bounded retry remains required.
- EOD status: `FAILED_DATA_PATH`; not `COMPLETE`, not `VERIFIED`.
- Collector PID `11512` dan canary remain unchanged; publication and strategy signal remain disabled.

### Addendum 16 November 2026 — bounded recovery, shared refresh, dan dashboard analysis

Status addendum: IMPLEMENTED_AND_ISOLATED_TESTED; live DEWA acceptance PENDING.

#### Recovery batch aktual

- Run `20261116_194139_654006de6015405fb8f2d7c7b981d891`: 20 ticker, `18 updated`, `1 WAITING`, `1 FAILED`, `19` bar baru, `18` metrik updated, semantic `FAILED exit_code=1`.
- Run `20261116_194533_f899a0c697db467a931fe3db7697258d`: 20 ticker, `20 updated`, `0 WAITING`, `0 FAILED`, `20` bar baru, `20` metrik updated; output masih memuat terminal semantic ganda (`WAITING exit_code=75` lalu `FAILED exit_code=1`), sehingga histori wrapper tidak ditulis ulang.
- Coverage read-only canonical sebelum batch berikut: target `2026-11-16`, `461` ticker belum lengkap, `38` lengkap dengan metrik, `1` adjusted-only (`AGRS`). Angka `461` adalah residual coverage, bukan seluruhnya terbukti provider gap.
- Provider gap terbukti hanya untuk ticker yang memiliki response provider tanpa target bar atau response gagal/empty. Ticker residual lain tetap `NOT CHECKED` sampai provider fetch aktual terbukti.
- `AGRS` tetap `adjusted-only`: adjusted ada, raw target belum terbukti.
- Manifest berikutnya disimpan di `storage/runtime/eod-runs/20261115_eod_20261116_gap_03.pre-manifest.json`; selector memakai canonical observation gap target `2026-11-16`, source `IDX Edge PRO`, board `RG`, status `VALID`, dua adjustment mode.
- Command lokal berikut hanya untuk ditampilkan setelah user memberi approval lokal. Tidak dijalankan pada audit ini:

```text
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "E:\Aether-Market-Intelligence\scripts\run_guarded_eod.ps1" --mode=--batch --session=2026-11-16 --limit=20 --rpm=30 --retry-only --trigger-source=RECOVERY_RETRY --tickers=AADI,AGRS,ASRI,ASSA,ATIC,AUTO,AVIA,AYAM,BABP,BACA,BACH,BALI,BANK,BBCA,BBHI,BBKP,BBLD,BBMD,BBNI,BBRI
```

- Receipt lokasi: `storage/runtime/eod-runs/`, runner log `logs/scheduler_eod_guarded.log`, manifest `storage/runtime/eod-runs/20261115_eod_20261116_gap_03.pre-manifest.json`.
- Perlindungan overlap: `storage/runtime/eod-scheduler.lock` dengan owner token; runner menolak lock aktif (`exit 75`), timeout batch `600s`, maksimal `20` ticker, rate `30 RPM`, child semantic exit dipropagasikan.
- `--retry-only` tidak mencakup semua canonical gap secara otomatis; command tetap memakai daftar ticker dari manifest. Manifest gap-03 belum boleh dianggap hasil batch baru karena belum ada eksekusi/read-back.

#### Audit satu FAILED

- Ticker: `ARTA`.
- Raw request: provider `success=true`, target bar `2026-11-16`, validation `VALID`.
- Adjusted request: provider `success=false`, `bar_dates=[]`, `validation_statuses=[]`, `performed=true`, `error_type` dan `message` tidak dikirim pada receipt lama.
- Efek DB: registry ARTA `eod_refresh_status=UPDATED`, `last_bar_date=2026-11-16`, `eod_last_error=NULL`; read-back menemukan raw dan adjusted target valid, board `RG`, source `IDX Edge PRO`, close `2030.00`. Karena itu satu aggregate `FAILED` tidak diubah menjadi `WAITING`; histori tetap dipertahankan. Provider cause exact: `NOT VERIFIED`.
- Defect internal yang terbukti: retry evidence lama kehilangan `provider_message` dan fallback message saat response provider gagal tanpa pesan. `DailyEodRefreshService` kini menyimpan `error_type`, fallback `message`, `provider_message`, dan `latency_ms` untuk nominal failure. Tidak ada histori lama yang diubah.

#### Shared refresh dan dashboard analysis

- `OnDemandDataCollectorService` memakai `IdxEdgeClient::history()` endpoint `/api/history/{ticker}` dengan `limit`, `frame=daily`, `adjusted`, dan `is_eod=true`; valid bar dipersist sebagai `MarketObservation` melalui writer service.
- Raw payload sekarang disimpan immutable di `dashboard/storage/app/raw_immutable/{YYYYMMDD}/{TICKER}_{SHA256}.json`; `raw_payload_hash` cocok dengan isi bar JSON.
- `OnDemandResearchService` menjalankan urutan bar refresh → metric calculation → broker summary fetch/persist → evidence-linked analysis. Metric/provider failure diberi status terpisah dan tidak menghapus bar valid.
- Dashboard reader tetap read-only. Request aktif didedup per ticker untuk status `pending`, `collecting`, atau `analyzing`; endpoint status baru `GET /api/research/{requestId}` membaca status, freshness, evidence, analysis, error, dan refresh provenance.
- UI dashboard melakukan polling status terbatas 20 percobaan × 3 detik. Gemini tidak dipanggil dari proses render halaman; analisis dijalankan oleh worker `aether:execute-research`.
- Status dibedakan: `NOT_LOADED`, `UPDATED`, `INSUFFICIENT_INPUT`, `FETCH_FAILED`; provider empty/error tidak dipaksa menjadi complete.

#### Test dan blocker

- Isolated SQLite fixture gabungan: `21 tests, 21 passed, 123 assertions`.
- PHP lint seluruh file refresh/analysis/runner terkait: pass. `git diff --check` pada file target: pass.
- Browser DEWA read-only acceptance: `VERIFIED_PARTIAL`; `/market/DEWA` HTTP 200, ticker `DEWA`, `Darma Henwa Tbk`, freshness `AVAILABLE_BUT_INSUFFICIENT_FOR_REQUEST`, bar terakhir `2026-11-13`, mode recovery tampil, console tidak menunjukkan error pada pemeriksaan.
- Live request → writer → provider → evidence → analysis untuk DEWA: `BLOCKED_RUNTIME_READ_ONLY`; request mutasi sengaja tidak dikirim karena recovery route hanya-baca. Tidak ada bypass, Telegram baru, replay Job `#6`, atau perubahan Outbox `#5`.
- EOD universe completion: `PENDING`; target provider coverage belum lengkap. `461` residual tidak dilabeli seluruhnya sebagai provider blocker.
- Collector tunggal PID `11512` dipertahankan. ML, strategy signal, shadow publication, dan Telegram send tetap disabled.
## Addendum — Acceptance Research dan Snapshot Regression

### Tiga request live

Request diproses dispatcher alami. Tidak ada replay request lama.

- DEWA `REQ_20261116_225823_DEWA_LmvC`: `completed`; Evidence `#164`; Analysis `#136`; evidence/analysis bar `2026-10-20`; model `STRUCTURED_FALLBACK`; validation `fallback`.
- BBCA `REQ_20261116_230400_BBCA_Uyz0`: `completed`; Evidence `#165`; Analysis `#137`; evidence/analysis bar `2026-10-20`; model `STRUCTURED_FALLBACK`; validation `fallback`.
- TLKM `REQ_20261116_230718_TLKM_7ZNj`: `completed`; Evidence `#166`; Analysis `#138`; evidence/analysis bar `2026-10-20`; model `gemini-3.6-flash`; validation `valid_schema`.

Relasi DB terbukti melalui `research_requests.evidence_snapshot_id`, `research_requests.market_analysis_id`, `evidence_snapshots.id`, dan `market_analyses.id`. Tidak ada evidence atau analysis historis yang dihapus atau diubah.

Canonical observation terbaru sebelum acceptance:

- BBCA: `2026-11-13`, `IDX Edge PRO`, board `RG`, `UNADJUSTED`, `VALID`.
- DEWA: latest canonical `2026-11-13` pada audit read-only.
- TLKM: latest canonical `2026-11-13` pada audit read-only.

Metrik tersimpan sesuai tanggal canonical terbaru yang tersedia pada audit: BBCA `2026-11-13`, DEWA `2026-11-13`, TLKM `2026-11-13`, status `CALCULATED`.

Broker tersimpan terpisah dari bar/metrics: BBCA punya periode `2026-11-13` dan `2026-10-20`; DEWA dan TLKM punya periode `2026-10-20`. Periode broker tidak dipakai sebagai tanggal bar.

### Snapshot regression

Bug ditemukan pada pemilihan bar provider. History IDX Edge dikembalikan oldest-last, lalu `end($bars)` memilih `2026-10-20` walau canonical valid terbaru adalah `2026-11-13`.

Perbaikan:

- Sort bar valid berdasarkan `date` ascending, lalu pilih tanggal maksimum.
- Jika provider tetap mengembalikan bar lebih tua dari canonical `IDX Edge PRO` + board `RG` + `UNADJUSTED` + `VALID`, canonical terbaru dipertahankan.
- Payload provider tetap disimpan pada `storage/app/raw_immutable/...`.
- Metadata refresh menandai `PROVIDER_OLDER_THAN_CANONICAL`, menyimpan tanggal provider, tanggal canonical, source, raw path, dan raw hash.

Acceptance lama tidak diubah. Acceptance terbatas baru wajib membuktikan status tersebut sebelum backlog EOD.

### Coverage sesi `2026-11-16`

Partisi read-only eksklusif atas 499 eligible registry ticker:

- complete raw + adjusted: `38`
- raw-only: `0`
- adjusted-only: `1`
- conflicted/invalid: `0`
- neither: `460`
- metric match pada complete: `38`

Jumlah partisi `499`; tidak double count. Angka ini hanya coverage target session `2026-11-16`, bukan 499 instrumen yang memiliki histori. `neither` belum boleh disebut seluruhnya provider blocker.

### AI provenance

- DEWA/BBCA fallback karena dashboard AI runtime tidak memakai inferensi yang berhasil untuk request tersebut. Stored model name `STRUCTURED_FALLBACK`, validation `fallback`, dan structured output menyimpan `model_status=NOT_RUN` serta `fallback_reason`.
- Fallback bukan inferensi Gemini.
- TLKM returned model `gemini-3.6-flash`, provider `google`, endpoint tersanitasi `https://[LOCAL]` pada audit config. Config source dashboard adalah `services.dashboard_ai`: `GEMINI_MODEL`, `GEMINI_BASE_URL`, `GEMINI_API_KEY`.
- Worker dispatcher legacy router config tetap requested `ag/gemini-3.8-flash-high` melalui `AI_MODEL_ID` dan `AI_ROUTER_ENDPOINT`; itu bukan model returned TLKM.
- Tidak ada model atau credential yang diganti untuk meluluskan acceptance.

### Runtime guard

Child writer menghapus inherited `PGOPTIONS`, lalu menjalankan post-bootstrap gate untuk database, role, schema, `default_transaction_read_only=off`, request-table DML, sequence privilege, dan destructive privilege denial. Reader dashboard tetap read-only.

### EOD

Runner resmi bounded belum dijalankan. Percobaan command sebelumnya berhenti pada safety approval timeout sebelum eksekusi. Jangan klaim batch EOD berhasil.

### Koreksi acceptance setelah snapshot fix

Acceptance lama BBCA/TLKM tetap historis dan tidak diubah:

- BBCA `REQ_20261116_230400_BBCA_Uyz0`: bar/evidence/analysis/metrics/broker `2026-10-20`; model `STRUCTURED_FALLBACK`; fallback reason `Google Gemini timeout atau koneksi gagal.`
- TLKM `REQ_20261116_230718_TLKM_7ZNj`: bar/evidence/analysis/metrics/broker `2026-10-20`; model `gemini-3.6-flash`; latency `84401 ms`.

Acceptance baru DEWA memakai dispatcher alami dan membuktikan pemilihan tanggal terbaru:

- Request `REQ_20261117_032147_DEWA_k5vq`: `completed`.
- Observation, evidence `#167`, analysis `#139`, metrics, dan broker: `2026-11-16`.
- Source: `IDX Edge PRO`; board canonical: `RG`; adjustment: `UNADJUSTED`; validation: `VALID`.
- Evidence snapshot: `EVD_20261116_DEWA_763337d1`.
- Analysis: model `gemini-3.6-flash`, validation `valid_schema`, latency `71719 ms`.
- API read-back HTTP `200`; request-to-evidence-to-analysis IDs match.
- Raw provider payload path/hash tersimpan; canonical date tidak turun ke `2026-10-20`.

### AI configuration reconciliation

- DEWA/BBCA historical fallback reason terbukti: `Google Gemini timeout atau koneksi gagal.` Stored model `STRUCTURED_FALLBACK`; `model_status=NOT_RUN`; bukan inferensi Gemini.
- TLKM historical returned model: `gemini-3.6-flash`, latency `84401 ms`.
- DEWA fixed acceptance returned model: `gemini-3.6-flash`, latency `71719 ms`.
- Requested dashboard model: `gemini-3.6-flash`; effective source: `services.dashboard_ai` using `GEMINI_MODEL`, `GEMINI_BASE_URL`, and `GEMINI_API_KEY`.
- Sanitized dashboard endpoint: `https://[LOCAL]`.
- Separate dispatcher router config remains `AI_MODEL_ID=ag/gemini-3.8-flash-high` and `AI_ROUTER_ENDPOINT=http://127.0.0.1:20128/v1`. It is not returned model for dashboard Gemini calls.
- No model or credential was changed for acceptance.

### Fallback UI

Research result UI now renders model, validation status, bar date, and fallback reason using DOM text nodes. Fallback output cannot be labeled as Gemini. Historical fallback rows remain immutable.

## Addendum — Pemulihan Lanjutan 2026-11-17

### Audit coverage setelah acceptance DEWA

Audit terakhir yang berhasil dibaca ulang: `2026-11-17T04:16:14+07:00`.
Receipt read-only: `C:\Users\ASUS\AppData\Local\hermes\cache\terminal-output\out-1794863143-9732-68b0.log`.

Target sesi eksplisit: `2026-11-16`.

- Eligible registry: `499`.
- `METRIC_MATCH`: `38`.
- `BAR_SESSION_MISSING`: `461`.
- Failed: `0`.
- Writes executed: `false`.
- Secret printed: `false`.

Acceptance ticker tidak otomatis menaikkan coverage. Read-back membuktikan:

- DEWA: adjusted target `0`, raw target `0`, metric target `1`; latest local bar raw/adjusted `2026-11-13`; latest metric `2026-11-16`; status `BAR_SESSION_MISSING`.
- BBCA: adjusted target `0`, raw target `0`, metric target `0`; latest local bar raw/adjusted `2026-11-13`; latest metric `2026-11-13`; status `BAR_SESSION_MISSING`.
- TLKM: adjusted target `0`, raw target `1`, metric target `1`; latest local adjusted `2026-11-13`, raw `2026-11-16`; latest metric `2026-11-16`; status `BAR_SESSION_MISSING`.

DEWA acceptance request metadata membuktikan refresh request berhasil memakai bar, metric, dan broker `2026-11-16`. Coverage audit canonical sesi memakai dua mode `UNADJUSTED` + `PROVIDER_BACK_ADJUSTED`, sehingga acceptance request tidak dihitung sebagai coverage lengkap.

### Request baru melalui dashboard dan dispatcher alami

Tidak ada replay atau perubahan request historis.

- BBCA `REQ_20261117_040043_BBCA_ck0s`: `FAILED`; `PROVIDER_CONNECTION_FAILED`; provider history timeout setelah `11508 ms`; evidence/analysis tidak dibuat; browser history menampilkan `FAILED`.
- TLKM `REQ_20261117_040045_TLKM_nYgX`: `COMPLETED`; evidence `#168`; analysis `#140`; bar `2026-11-16`; metrics `2026-11-16`, `UPDATED`; broker `2026-11-16`, `UPDATED`; model returned `gemini-3.6-flash`; validation `valid_schema`; browser history menampilkan `COMPLETED`.
- TLKM raw payload hash/path tersimpan pada request metadata. Worker owner dan timestamps membuktikan dispatcher alami, bukan worker manual.
- BBCA canonical terbaru yang terbaca tetap `2026-11-13`; request baru gagal sebelum refresh. Status stale/parsial dipertahankan, bukan dipaksa complete.

Acceptance audit read-only juga menunjukkan:

- DEWA fixed request `REQ_20261117_032147_DEWA_k5vq`: evidence `#167`, analysis `#139`, bar/metrics/broker `2026-11-16`.
- BBCA historical request tetap immutable pada `2026-10-20`, fallback `STRUCTURED_FALLBACK`.
- TLKM historical request tetap immutable pada `2026-10-20`; request baru terpisah pada evidence `#168` dan analysis `#140`.

### Adjustment consistency

- Dashboard chart default menampilkan `ADJUSTED (Back-adjusted)`.
- Tombol `Adj` dan `Raw` terpisah.
- Chart provenance browser: `API IDX Edge PRO (Pasar Reguler - RG)`.
- Technical page menampilkan input indicator `507 bar`; fixture browser tidak menampilkan support/resistance pada panel itu.
- Telegram consultation path menghitung support/resistance dari `CompanyAnalysisMetric`, memakai rolling 20 bar, `as_of_date`, dan `input_bars`; output menyebut basis bar EOD dan window.
- Support/resistance tidak boleh dibandingkan dengan harga raw tanpa label basis adjustment. Technical calculation dan canonical query wajib dibaca terpisah untuk `PROVIDER_BACK_ADJUSTED` versus `UNADJUSTED`.
- Tidak ada bukti browser live yang menampilkan support/resistance untuk DEWA. Status: `NOT VERIFIED` pada UI detail, bukan data yang diada-adakan.

### Fallback fixture/browser

Fixture fallback lulus melalui isolated PHPUnit: `33/33 tests`, `163 assertions`.

Browser fixture production tidak diputus provider. Browser read-only membuktikan halaman recovery, label `STRUCTURED_FALLBACK` pada historical BBCA, dan status `gemini-3.6-flash` pada TLKM. Fallback reason tersimpan sebagai `Google Gemini timeout atau koneksi gagal.` dan model fallback bukan Gemini.

### Dashboard dan Telegram refresh boundary

- Dashboard research memakai `OnDemandResearchService::executeResearch()`.
- Pipeline generik dashboard: collect bar → calculate metric → sync broker → evidence-linked analysis.
- Telegram consultation memakai `HermesConsultationAdapterService` dan `AiToolExecutorService`; technical path memakai `TechnicalMetricsCalculationService`, `get_instrument_analysis`, broker service, dan evidence tracking.
- Shared service evidence yang terbukti: `OnDemandDataCollectorService`, `TechnicalMetricsCalculationService`, `BrokerSummaryIngestionService`, `EvidenceSnapshot`, dan canonical market reads.
- Model boleh berbeda sesuai konfigurasi. Dashboard request returned model TLKM: `gemini-3.6-flash`. Historical Telegram/router row: `ag/gemini-3.8-flash-high`. Tidak ada model atau credential yang diubah.
- Full live Telegram E2E refresh/evidence delivery tidak diuji pada addendum ini. Status: `PENDING`, bukan `VERIFIED`.

### EOD backlog dan execution guard

Runner resmi tidak dijalankan ulang setelah safety guard memblokir command yang sama sebelum eksekusi. Tidak ada batch EOD baru, write baru, atau semantic result baru.

Command lokal aktual dengan scope eksplisit:

`powershell.exe -NoProfile -ExecutionPolicy Bypass -File "E:\Aether-Market-Intelligence\scripts\run_guarded_eod.ps1" --mode=--batch --session=2026-11-16 --limit=20 --rpm=30 --retry-only --trigger-source=RECOVERY_RETRY`

- Scope: canonical gap, maksimal `20` ticker, `30 RPM`, sesi `2026-11-16`, retry-only.
- Runner: `E:\Aether-Market-Intelligence\scripts\run_guarded_eod.ps1`.
- Receipt directory: `E:\Aether-Market-Intelligence\storage\runtime\eod-runs`.
- Lock read-back sebelum pemeriksaan: `EOD_LOCK=FREE`.
- Scheduler read-back: `\\AetherScheduledDispatcher_1min` `Ready`; next run `11/17/2026 4:06:00 AM`; last run `11/17/2026 4:05:01 AM`.
- Guard block: approval timeout; command tidak dieksekusi. Jangan klaim checkpoint, manifest, provider request, identity gate, atau semantic exit untuk batch ini.
- Collector, scheduler, backup, histori, Outbox `#5`, ML, dan strategy signals tidak diubah.

### Penutupan status

Defect internal:

- Canonical coverage audit tidak menganggap request acceptance sebagai dua-mode EOD coverage. Ini konsisten dengan guard, tetapi request metadata dan coverage canonical perlu dibaca sebagai dua kontrak berbeda.
- BBCA/TLKM/DEWA dashboard pages tetap menampilkan `AVAILABLE_BUT_INSUFFICIENT_FOR_REQUEST` karena canonical adjusted/raw target belum lengkap.
- BBCA request baru gagal provider timeout sebelum evidence/analysis.

Belum diuji:

- Batch EOD resmi setelah guard approval.
- Full live Telegram refresh → evidence → analysis → outbound delivery.
- Support/resistance browser detail dengan provenance adjustment yang terlihat.
- Natural scheduler completion untuk recovery batch.

Gap provider terbukti:

- BBCA history request: `cURL error 28`, timeout `11508 ms`, `0 bytes received`.
- Provider capability `insiders`, `screener`, `movers`: tetap `CLIENT_IMPLEMENTED / PROVIDER_CAPABILITY_UNVERIFIED`, flags disabled.
- Board scope real-time RG: tetap `BOARD_SCOPE_UNVERIFIED`.
- Full universe completion: belum terbukti.

Coverage final yang dapat dipertanggungjawabkan: `38/499` `METRIC_MATCH`; `461/499` `BAR_SESSION_MISSING`; `0` failed dalam audit read-only; DEWA `0/0` target bars, BBCA `0/0`, TLKM `1/0` raw/adjusted target mode. EOD recovery tetap `PENDING`.

## Addendum — Koreksi Penutupan 17 November 2026

Status keseluruhan: `PARTIALLY_RECOVERED`.

Layanan yang pulih:

- Dashboard reader, research request bridge, existing dispatcher, evidence/analysis persistence, raw provider archive, metrics calculation, broker refresh terpisah, dan UI technical provenance tersedia.
- DEWA dan TLKM request baru mencapai analisis on-demand dengan status komponen terbaca.
- Provider raw archive DEWA dan TLKM membuktikan bar provider `2026-11-16`, `adjusted=false`, frame `daily`, dan `data_available.end=2026-11-16`.

Backlog dan defect terbuka:

- EOD canonical recovery belum selesai. `Failed=0` pada audit read-only hanya berarti tidak ada row audit gagal; bukan bukti ingestion sukses.
- Coverage dua-mode tetap tidak lengkap: raw dan `PROVIDER_BACK_ADJUSTED` harus sama-sama `VALID` pada key canonical yang sama.
- DEWA raw canonical `id=518450` valid untuk `2026-11-16`, source `IDX Edge PRO`, board `RG`, interval `1d`, classification `PUBLIC_MARKET_EOD`, adjustment `UNADJUSTED`, hash `8c95e85a...222f47`, tetapi `research_request_id=REQ_20261117_051218_DEWA_j30q`, bukan fixed acceptance `REQ_20261117_032147_DEWA_k5vq`. Status linked provenance: `PROVENANCE_MISMATCH`.
- TLKM raw canonical `id=518451` valid dengan hash `cab16fde...10e54` dan `research_request_id=REQ_20261117_040045_TLKM_nYgX`. Adjusted canonical target belum terbukti pada audit exact-key.
- Evidence DEWA `#167` dan TLKM `#168` menyimpan decision date `2026-11-16` dan source `IDX Edge PRO`, tetapi snapshot lama tidak memuat adjustment mode, board scope, validation status, atau input bar count pada feature keys. Evidence provider harus dibedakan dari canonical persistence.
- Metrics DEWA dan TLKM memiliki row `as_of_date=2026-11-16`, status `CALCULATED`. Itu membuktikan metric row, bukan bukti lengkap bahwa raw dan adjusted canonical input target tersedia.
- Analysis/metric menyebut `2026-11-16` karena on-demand pipeline memakai tanggal bar raw provider untuk evidence dan `calculateForTicker()`. Canonical audit memakai filter lebih ketat: `ticker + bar_date + interval + data_classification + source + board_scope + adjustment_mode + validation_status`. Query menemukan raw row, tetapi tidak menemukan pasangan adjusted exact-key. Tidak ada bar dibuat atau tanggal diganti.

Kontrak acceptance baru:

- `completed` boleh berarti proses analisis selesai dengan komponen parsial.
- Metadata wajib memuat `completion_scope`, `fully_verified`, `verification_status`, `canonical_components.raw`, dan `canonical_components.adjusted`.
- `PARTIAL_COMPONENTS` tidak boleh disebut current atau fully verified.
- Indikator wajib mengikat `as_of_date`, `input_bars`, `adjustment_mode`, `source`, `board_scope`, `interval`, dan metode.
- Evidence provider archive dan canonical observation adalah bukti berbeda. Keduanya harus direkonsiliasi dengan hash dan canonical key.

Dashboard support/resistance:

- Dashboard sekarang mengirim dan merender support/resistance dari `CompanyAnalysisMetric`.
- Metode: `rolling_min_max`; window: `20` bar.
- UI menampilkan `as_of_date`, adjustment mode, source, board, dan jumlah input bar.
- Chart tetap default `ADJUSTED (Back-adjusted)`; tombol `Raw` terpisah.
- Telegram contract tidak dianggap membuktikan dashboard contract. Browser DOM pascapatch belum diverifikasi; status live UI tetap `PENDING`.

Runner resmi backlog:

Command yang didukung setelah selector diperbaiki:

`powershell.exe -NoProfile -ExecutionPolicy Bypass -File "E:\Aether-Market-Intelligence\scripts\run_guarded_eod.ps1" --mode=--batch --session=2026-11-16 --limit=20 --rpm=30 --canonical-gap --trigger-source=RECOVERY_CANONICAL_GAP`

- `--canonical-gap` memilih semua registry equity yang kehilangan salah satu dari dua canonical mode; tidak bergantung pada `--retry-only`.
- Selector exact key memakai source `IDX Edge PRO`, board `RG`, interval `1d`, classification `PUBLIC_MARKET_EOD`, validation `VALID`, dan mode `UNADJUSTED`/`PROVIDER_BACK_ADJUSTED`.
- Command belum dijalankan pada addendum ini. Execution guard sebelumnya memblokir sebelum child process; tidak ada manifest, provider result, checkpoint, semantic exit, atau lock-release result baru.
- Setelah approval baru, audit wajib membaca manifest scope, provider result, raw/adjusted canonical, metric match, semantic exit, checkpoint, dan lock release. Batch berikutnya harus memakai selector canonical gap yang sama tanpa fresh-scope ulang.
- Ticker belum diperiksa tidak boleh diklasifikasikan sebagai provider blocker.

Backup, collector, scheduler, histori, dan Outbox `#5` dipertahankan. ML dan strategy signals tetap disabled. Tidak ada retry Outbox `#5`, collector restart, receiver kedua, atau trigger manual.

## Addendum — Rekonsiliasi Internal dan Provider Capability 17 November 2026

Status tetap `PARTIALLY_RECOVERED`.

Metric reconciliation cutoff `2026-11-16`:

- Eligible: `499`.
- `METRIC_MATCH`: `58`.
- `METRIC_STALE_INPUT`: `0`.
- `BAR_SESSION_MISSING`: `441`.
- Failed audit: `0`.
- Audit read-only: `writes_executed=false`.
- `METRIC_MATCH` kini wajib cocok pada target date, `last_date`, `as_of_date`, source `IDX Edge PRO`, board `RG`, mode `PROVIDER_BACK_ADJUSTED`, dan formula `sma/rsi/atr/macd`.
- `38` metric stale sebelumnya dihitung ulang dari input adjusted lokal yang memadai melalui metrics-only writer. Provider fetch tidak dilakukan.
- Metric row dan histori evidence tetap dipertahankan. Tidak ada bar, evidence, analisis, atau histori request yang dihapus.
- Residual `441` ticker disimpan pada `storage/runtime/eod-runs/20261117_coverage_20261116_residual_missing.manifest.json`.

Batch canonical-gap terakhir:

- Receipt `20261117_092118_b32c2b714f474d02b8b0d4e88f165a36` memproses selected scope `20/20`.
- Updated `20`, new bars `19`, metrics `20`, fetch errors `0`, waiting selected tickers `0`.
- `WAITING_PROVIDER` muncul karena `universe_data_coverage_complete=false`, bukan karena selected scope fetch failure.
- Batch result tidak menyimpan ticker-level waiting list karena selected scope selesai. Residual universe exact adalah `441 BAR_SESSION_MISSING`, bukan provider failure yang boleh diasumsikan.
- `Updated=20` tidak berarti universe `499/499` memiliki dua canonical mode.

Manifest gate:

- Metrics recompute manifest: `storage/runtime/eod-runs/20261117_metrics_recompute_20261116.manifest.json`.
- Canonical next manifest: `storage/runtime/eod-runs/20261115_canonical_next_20261116.pre-manifest.json`; scope exact `20` ticker, dibaca sebelum batch.
- `scripts/run_guarded_eod.ps1` kini mewajibkan `--manifest` untuk mode batch, memeriksa file, session, ticker count, dan meneruskan ticker manifest ke writer.
- Runner batch canonical-gap berikutnya belum dijalankan.

Provider capability audit:

- Dokumentasi provider mengonfirmasi GET `/api/insiders/{code}`, `/api/screener/latest`, dan `/api/movers`. Parameter query dan response contract belum lengkap.
- Bounded app-context read-only probe memakai satu GET per endpoint, shared rate limit, dan credential runtime sah.
- Ketiga endpoint mengembalikan HTTP success dengan top-level keys:
  - insiders: `stock_code`, `count`, `total`, `page`, `page_size`, `total_pages`, `items`.
  - screener: `date`, `source`, `rows`, `raw`, `cached_for_seconds`.
  - movers: `by`, `gainers`, `losers`, `count`, `market`.
- Status tetap `HTTP_SUCCESS_SCHEMA_UNVERIFIED`, bukan enabled capability.
- Flags `insiders`, `screener`, dan `movers` tetap `false`.
- Sanitized raw receipts dan SHA-256 tersimpan pada `storage/runtime/provider-capability-probes/20261117_031117/`.
- Endpoint client failure status dikoreksi menjadi `PROVIDER_CAPABILITY_UNVERIFIED`; tidak ada mapping produksi yang diaktifkan.

DEWA temporal provenance:

- Evidence request `REQ_20261117_032147_DEWA_k5vq` dan canonical ingestion request `REQ_20261117_051218_DEWA_j30q` berbeda jalur waktu.
- Perbedaan request ID tidak otomatis defect. Evidence #167 tetap evidence provider dengan hash dan decision date `2026-11-16`.
- Canonical raw observation `#518450` berasal dari request `REQ_20261117_051218_DEWA_j30q`, hash `8c95e85a...222f47`.
- Evidence #167 memiliki feature hash berbeda (`763337d1...`) dan tidak ditautkan retroaktif ke canonical row #518450.
- Kesimpulan: evidence provider valid sebagai snapshot jalur research; canonical linkage terpisah dan status tetap `PROVENANCE_MISMATCH` sampai relasi temporal/hash dapat dibuktikan.

TLKM:

- Raw evidence/canonical bar `2026-11-16` tersedia.
- Adjusted target exact-key belum tersedia.
- Tidak ada klaim metric current untuk `2026-11-16`.
- Analisis raw tetap historis dengan keterbatasan `adjusted canonical NOT_PROVEN`; raw analysis tidak dipromosikan sebagai two-mode current metric.

Command resmi batch canonical-gap berikutnya, belum dijalankan:

`powershell.exe -NoProfile -ExecutionPolicy Bypass -File "E:\Aether-Market-Intelligence\scripts\run_guarded_eod.ps1" --mode=--batch --session=2026-11-16 --limit=20 --rpm=30 --canonical-gap --manifest="E:\Aether-Market-Intelligence\storage\runtime\eod-runs\20261115_canonical_next_20261116.pre-manifest.json" --trigger-source=RECOVERY_CANONICAL_GAP`

Gate command: identity `aether_ingestion`/candidate DB, lock, manifest session and scope, provider rate `30 RPM`, semantic exit, checkpoint, and lock release. Do not run same blocked path without new local approval.

No ML, strategy signals, Telegram retry, Outbox `#5` retry, collector restart, receiver second, or history mutation.

## Addendum — Rekonsiliasi Interrupted EOD 17 November 2026

Status tetap `PARTIALLY_RECOVERED`.

Run terputus:

- Run ID `20261117_135555_36ff7ce3eba94cbf8a580bfdb01ec5d4`.
- Target session dari queue: `2026-11-16`.
- Wrapper mode: `--eod-daily`; manifest tidak digunakan.
- Child PID `5480`, dibuat `2026-11-17T13:55:56.0451895+07:00`.
- Parent metadata PID `21200`; tidak aktif saat audit.
- Heartbeat terakhir `2026-11-17T13:57:26.1215522+07:00`; `stdout_bytes=0`, `stderr_bytes=0`, `child_exited=false`.
- Wrapper log tidak memiliki `[END]` atau `[FAILED]` untuk run ini.
- Metadata run hanya menyimpan mode `--eod-daily`, tanpa `trigger_source` dan tanpa session eksplisit.
- Task Scheduler membuktikan task `\\HermesMarketIntelligence_EOD` tersedia, tetapi bukti yang tersedia tidak mengikat task itu ke run `13:55`; provenance trigger run: `NOT VERIFIED`.
- Penyebab penghentian tidak tercatat pada wrapper, child stderr, atau Task Scheduler event yang tersedia: `CAUSE_UNKNOWN`.
- Tidak ada proses terkait aktif saat rekonsiliasi. Pemeriksaan memakai PID dan creation-time query; PID `19760` milik queue lease juga tidak aktif.

Canonical read-back:

- Queue `#5` semula `PROCESSING`, lalu direkonsiliasi melalui owner resmi.
- State akhir `FAILED`, `last_error=INTERRUPTED_RUN: owner process absent after stale lease; checkpoint preserved.`
- Lease token dan PID dikosongkan setelah compare-and-swap berhasil.
- Checkpoint dipertahankan: trigger `RECOVERY_CANONICAL_GAP`, `current_ticker=DMMX`, `processed_count=25`, `updated=18`, `failed=1`, `bars_ingested=19`, `metrics_updated=18`, `total_eligible=499`, `selected_scope_complete=false`.
- Recovery metadata: `INTERRUPTED_RUN_RECONCILED`, owner PID `19760`, owner alive `false`, checkpoint preserved `true`.
- Tidak ada asumsi bahwa ketiadaan terminal receipt berarti tidak ada write.

Coverage read-back cutoff `2026-11-16`:

- Eligible `499`.
- Complete raw+adjusted `160`.
- Raw-only `1`.
- Adjusted-only `0`.
- Neither `338`.
- Strict complete-bar metric match `159/160`; standalone metric scope audit reports `160` because it includes raw-only TLKM.
- Mismatch audit: `TLKM` metric row `as_of_date=2026-11-16` has `last_date=2026-11-13`; adjusted target exact-key remains unproven. No recompute, provider fetch, or date substitution was performed.

Lifecycle fix:

- Added guarded `--reconcile-interrupted --session=YYYY-MM-DD` path.
- Recovery requires stale heartbeat, absent owner process, non-empty persisted lease token, and no conflicting live cache token.
- Recovery uses compare-and-swap on queue status, lease token, and heartbeat.
- Recovery never marks `COMPLETE`, never deletes queue, and never fetches provider data.
- Runner now writes terminal receipt for timeout and runner exception.
- Fresh canonical gap manifest regenerated as `storage/runtime/eod-runs/20261115_canonical_next7_20261116.pre-manifest.json`; scope remains 20 tickers.
- `canonical_next7` remains deferred per instruction. No batch command rerun.

Validation:

- PHP lint passed for changed PHP files.
- Pint passed.
- Recovery execution returned `EOD_RECONCILE status=INTERRUPTED_RUN_RECONCILED ... checkpoint_preserved=true`.
- Writer identity verification passed with `mutation_executed=false`.
- Outbox `#5`, ML, strategy signals, collector, receiver, and historical records unchanged.

## Addendum — EOD 17 November Target Isolation and Recovery State

Status tetap `PARTIALLY_RECOVERED`.

Waktu audit: `2026-11-17T17:18:00+07:00` WIB.

EOD reguler 17 November:

- Task `\\HermesMarketIntelligence_EOD` terpicu pada `2026-11-17T16:05:01.5683628+07:00` WIB.
- Run ID `20261117_160501_bd63dc7e7e8a4ce3aa04e464d6e71fef`.
- Wrapper mode `--eod-daily`; metadata tidak menyimpan session eksplisit.
- Child PID `11972`; receipt tersedia.
- Actual resolved target pada run: `2026-11-16`, bukan `2026-11-17`.
- Run hasil: `updated=276`, `waiting=3`, `failed=0`, `metrics_updated=496`, `exit_code=75`.
- Waiting ticker: `SRIL`, `SUGI`, `TDPM`.
- Semantic reason: `SELECTED_SCOPE_INCOMPLETE,BACKLOG_REMAINING,PROVIDER_WAITING_OBSERVED`.
- Receipt: `storage/runtime/eod-runs/20261117_160501_bd63dc7e7e8a4ce3aa04e464d6e71fef.terminal.json`.
- Coverage session `2026-11-17`: `0/499` raw+adjusted, `499` neither, `0` metric match. Tidak digabung dengan recovery `2026-11-16`.
- Dry-run sesudah patch resolver memilih `session_date: 2026-11-17`, `session_has_closed=true`, `can_fetch_eod_now=true`, queue belum dibuat.

Target isolation fix:

- `RefreshDailyEodCommand` tidak lagi mengambil queue `PENDING` lama saat `--session` tidak diberikan.
- EOD reguler no-session selalu memakai calendar resolver.
- Recovery wajib memakai `--session=2026-11-16` eksplisit.
- Dry-run no-session sekarang menghasilkan `2026-11-17`.
- PHP lint dan Pint lulus.

Recovery session `2026-11-16`:

- Sebelum EOD 17 November: `220/499` raw+adjusted.
- Setelah EOD 17 November salah target: `496/499` raw+adjusted.
- Residual aktual: `SRIL`, `SUGI`, `TDPM`.
- Raw-only: `0`; adjusted-only: `0`; neither: `3`.
- Strict metric match: `492/496`.
- Residual metric classification: `BACH`, `JECX`, `RANS`, `WBSA` — `INSUFFICIENT_INPUT`, bukan provider failure.
- `BACH`: SMA20 `SUFFICIENT`, SMA50 `SUFFICIENT`, SMA200 `INSUFFICIENT_92_OF_200`, RSI14 `CALCULATED_WILDER_RMA`, ATR14 `CALCULATED_WILDER_RMA`, MACD `CALCULATED_STANDARD_12_26_9`.
- `JECX`: SMA20 `SUFFICIENT`, SMA50 `SUFFICIENT`, SMA200 `INSUFFICIENT_93_OF_200`, RSI14 `CALCULATED_WILDER_RMA`, ATR14 `CALCULATED_WILDER_RMA`, MACD `CALCULATED_STANDARD_12_26_9`.
- `RANS`: SMA20 `SUFFICIENT`, SMA50 `SUFFICIENT`, SMA200 `INSUFFICIENT_90_OF_200`, RSI14 `CALCULATED_WILDER_RMA`, ATR14 `CALCULATED_WILDER_RMA`, MACD `CALCULATED_STANDARD_12_26_9`.
- `WBSA`: SMA20 `SUFFICIENT`, SMA50 `SUFFICIENT`, SMA200 `INSUFFICIENT_148_OF_200`, RSI14 `CALCULATED_WILDER_RMA`, ATR14 `CALCULATED_WILDER_RMA`, MACD `CALCULATED_STANDARD_12_26_9`.
- Tidak ada histori sintetis atau fetch berulang untuk memenuhi SMA200.

Queue and lock:

- Queue `#5`: session `2026-11-16`, status `PENDING`, attempts `23`.
- Queue execution count tetap terpisah dari provider retry evidence.
- Last error `null`; failed `0`; owner PID/token `null`.
- Lock filesystem: `FREE`.
- Outbox `#5` tetap `DELIVERY_UNKNOWN`; tidak retry.

Manifest berikutnya:

- `canonical_next10` stale karena seluruh 20 ticker sudah lengkap pada run `160501`; command tidak dijalankan.
- Manifest fresh residual: `E:\Aether-Market-Intelligence\storage\runtime\eod-runs\20261115_canonical_next11_20261116.pre-manifest.json`.
- Scope: `SRIL,SUGI,TDPM`; count `3`; session `2026-11-16`.
- Validasi manifest read-only menemukan residual masih gap.

Command lokal berikutnya, belum dijalankan:

`powershell.exe -NoProfile -ExecutionPolicy Bypass -File "E:\Aether-Market-Intelligence\scripts\run_guarded_eod.ps1" --mode=--batch --session=2026-11-16 --limit=3 --rpm=30 --canonical-gap --manifest="E:\Aether-Market-Intelligence\storage\runtime\eod-runs\20261115_canonical_next11_20261116.pre-manifest.json" --trigger-source=RECOVERY_CANONICAL_GAP`

Jalankan hanya setelah EOD reguler tidak aktif dan lock `FREE`. Jangan mengulang `next10` atau batch yang sudah diverifikasi.

## Addendum — Prioritas Pemulihan EOD Sesi 17 November 2026

Status tetap `PARTIALLY_RECOVERED`.

Run salah target dipertahankan sebagai histori, bukan keberhasilan EOD 17 November:

- Scheduled date: `2026-11-17`, trigger `16:05:01 WIB`.
- Run ID: `20261117_160501_bd63dc7e7e8a4ce3aa04e464d6e71fef`.
- Resolved target aktual: `2026-11-16`.
- Hasil aktual: `updated=276`, `waiting=3`, `failed=0`, `metrics_updated=496`, `exit_code=75`.
- Receipt: `storage/runtime/eod-runs/20261117_160501_bd63dc7e7e8a4ce3aa04e464d6e71fef.terminal.json`.
- Status: `TARGET_SESSION_MISMATCH`, bukan `VERIFIED_EOD_2026-11-17`.

Regression target isolation:

- Fixture SQLite terisolasi membuat queue recovery `2026-11-16` berstatus `PENDING`.
- Waktu test: `2026-11-17 16:10 WIB`, sesudah cutoff.
- No-session resolver memilih `2026-11-17` melalui kalender.
- Queue lama tidak menggantikan target kalender.
- Recovery eksplisit mempertahankan session yang diberikan.
- Hasil: `1 test`, `3 assertions`, PASS.
- Full EOD unit file: `12 tests`, `65 assertions`, PASS.
- Tidak ada framework test terhadap DB operasional atau pemulihan.

Recovery sesi `2026-11-17`:

- Proses EOD salah target sudah mati.
- Lock: `FREE`.
- Tidak ada owner EOD aktif saat preflight.
- Manifest baru valid: `E:\Aether-Market-Intelligence\storage\runtime\eod-runs\20261115_eod17_recovery_next1_20261117.pre-manifest.json`.
- Target session: `2026-11-17`.
- Scope: `AADI,AALI,ABDA,ABMM,ACES,ACST,ADCP,ADES,ADHI,ADMF,ADMR,ADRO,AGAR,AGII,AGRO,AGRS,AISA,AKRA,ALDO,ALII`.
- Count: `20`; overlap dengan batch sesi 16 November tidak digunakan sebagai bukti sesi sama.
- Runner command diblokir execution guard sebelum child launch.
- Tidak ada receipt recovery sesi 17 dan tidak ada write baru dari command blocked.

Command lokal prioritas sesi `2026-11-17`:

`powershell.exe -NoProfile -ExecutionPolicy Bypass -File "E:\Aether-Market-Intelligence\scripts\run_guarded_eod.ps1" --mode=--batch --session=2026-11-17 --limit=20 --rpm=30 --canonical-gap --manifest="E:\Aether-Market-Intelligence\storage\runtime\eod-runs\20261115_eod17_recovery_next1_20261117.pre-manifest.json" --trigger-source=RECOVERY_EOD_20261117`

Command belum dijalankan karena guard timeout tanpa consent. Jalankan setelah verifikasi owner EOD, proses, dan lock. Jangan mengganti target ke residual `2026-11-16`.

Running Trade belum disentuh. Perubahan UI ditahan sampai recovery EOD sesi 17 mendapat hasil bounded atau blocker provider terdokumentasi.

Outbox `#5` tetap `DELIVERY_UNKNOWN`; tidak retry. ML, strategy signals, collector, protocol, autentikasi, parsing, board mapping, timestamp, reconnect, threshold, dan konfigurasi feed tidak diubah.

## Addendum — EOD 17 November Blocker and Metric Read-back

Cutoff audit: `2026-11-17`, after `16:05 WIB`; all audits read-only.

Coverage remains separate:

| Session | Eligible | Raw+adjusted | Raw-only | Adjusted-only | Neither | Metric strict match |
|---|---:|---:|---:|---:|---:|---:|
| `2026-11-16` | `499` | `496` | `0` | `0` | `3` | `492/496` |
| `2026-11-17` | `499` | `0` | `0` | `0` | `499` | `0/0` |

Session `2026-11-16` residuals: `SRIL`, `SUGI`, `TDPM`. Last evidence remains external/provider waiting. No repeated retry without new data or bounded retry schedule.

Session `2026-11-17` has no canonical bars or metrics. Recovery batch did not start because execution guard timed out before child launch. No receipt, queue mutation, provider fetch, or canonical write resulted.

Indicator read-back for session `2026-11-16`:

| Ticker | Input bars | SMA20 | SMA50 | SMA200 | RSI14 | ATR14 | MACD | Classification |
|---|---:|---|---|---|---|---|---|---|
| `BACH` | `92` | `SUFFICIENT` | `SUFFICIENT` | `INSUFFICIENT_INPUT` (`92/200`) | `CALCULATED_WILDER_RMA` | `CALCULATED_WILDER_RMA` | `CALCULATED_STANDARD_12_26_9` | `PARTIAL_CALCULATED` |
| `JECX` | `93` | `SUFFICIENT` | `SUFFICIENT` | `INSUFFICIENT_INPUT` (`93/200`) | `CALCULATED_WILDER_RMA` | `CALCULATED_WILDER_RMA` | `CALCULATED_STANDARD_12_26_9` | `PARTIAL_CALCULATED` |
| `RANS` | `90` | `SUFFICIENT` | `SUFFICIENT` | `INSUFFICIENT_INPUT` (`90/200`) | `CALCULATED_WILDER_RMA` | `CALCULATED_WILDER_RMA` | `CALCULATED_STANDARD_12_26_9` | `PARTIAL_CALCULATED` |
| `WBSA` | `148` | `SUFFICIENT` | `SUFFICIENT` | `INSUFFICIENT_INPUT` (`148/200`) | `CALCULATED_WILDER_RMA` | `CALCULATED_WILDER_RMA` | `CALCULATED_STANDARD_12_26_9` | `PARTIAL_CALCULATED` |

No synthetic history, date substitution, provenance correction, or repeated provider fetch performed.

Internal defect status:

- Target isolation fix now uses `DailyEodRefreshService::resolveTargetSessionDate()`.
- No-session path uses calendar resolver.
- Explicit `--session=YYYY-MM-DD` path preserves supplied recovery session.
- Isolated SQLite regression: `12 tests`, `65 assertions`, PASS.
- PHP lint, Pint, and `git diff --check`: PASS.
- EOD 17 recovery still blocked by execution guard consent timeout.
- Local recovery command remains the session `2026-11-17` command above.
- Running Trade UI remains deferred until EOD recovery receives bounded execution or a documented external blocker.

Status remains `PARTIALLY_RECOVERED`.

## Addendum — EOD Single-Owner Auto-Resume

Run reconciliation:

- User-launched recovery result ditemukan dan diverifikasi, bukan diasumsikan dari terminal kosong.
- Run: `20261117_190051_0a4fc607cf8d49fc8fb195b7dc412629`.
- Scheduled/resolved session: `2026-11-17` / `2026-11-17`.
- Trigger source: `RECOVERY_EOD_20261117`.
- Scope: `20` ticker, `updated=20`, `waiting=0`, `failed=0`.
- Metrics: `20`.
- Coverage after read-back: `20/499` raw+adjusted, `479` neither.
- Semantic result: `WAITING`, `BACKLOG_REMAINING`, exit `75`.
- Lock released: `FREE`.
- Queue recovery session 16 November unchanged: `PENDING`, owner null.
- Receipt: `storage/runtime/eod-runs/20261117_190051_0a4fc607cf8d49fc8fb195b7dc412629.terminal.json`.

Automation changes:

- Existing owner remains `\\HermesMarketIntelligence_EOD`.
- Laravel scheduler remains without EOD ingestion owner.
- Natural `--eod-daily` now routes through `scripts/eod_auto_orchestrator.php`.
- Orchestrator resolves no-session target through guarded writer dry-run.
- Orchestrator creates fresh canonical-gap manifests per batch.
- Each batch is capped at `20` ticker and `30 RPM`.
- Orchestrator writes checkpoint JSON with scheduled date, trigger source, timezone, resolved session, identity, batch results, coverage, semantic reason, and exit code.
- Orchestrator stops on provider waiting, failure, no progress, timeout, or less than `120` seconds cleanup budget.
- Explicit recovery `--batch --session=... --manifest=...` path remains separate.
- Running Trade, collector, WebSocket protocol, Outbox `#5`, ML, and strategy signals unchanged.

Automation verification:

- Orchestrator decision self-test: `6 cases PASS`.
- PHP syntax: PASS.
- PowerShell parse: PASS.
- Plan-only session `2026-11-17`: PASS; fresh manifest created with `20` gap tickers; no provider call or write.
- Task owner inspection: PASS; one enabled EOD owner, `MultipleInstancesPolicy=IgnoreNew`, execution limit `00:30:00`.
- Multi-batch live acceptance: `NOT VERIFIED`; execution guard blocked task registration before scheduled retry state could be changed.
- Trigger-natural checkpoint pickup: `NOT VERIFIED`; next natural slot remains required.

Retry scheduling:

- `scripts/register_eod_retry_on_existing_task.ps1` created and PowerShell-parsed.
- Script verifies existing owner action before editing, then adds `PT30M` repetition for `PT08H`.
- Registration command was blocked before execution. Existing task remains unchanged.
- No second scheduler was created.

Remaining blockers:

- Automatic retry trigger is not yet registered because execution guard timed out without consent.
- Session `2026-11-17` remains `20/499` complete, `479` residual.
- Session `2026-11-16` residual external waiting remains `SRIL,SUGI,TDPM`.
- Status remains `PARTIALLY_RECOVERED`; no `FULL_COMPONENTS` claim.

## Addendum — Natural Retry Trigger 2026-11-17 20:05 WIB

Validator:

- `scripts/register_eod_retry_on_existing_task.ps1` now compares repetition semantically.
- `Interval` parses through `System.Xml.XmlConvert` and compares to `00:30:00`.
- `Duration` parses through `System.Xml.XmlConvert` and compares to `08:00:00`.
- Missing `StopAtDurationEnd` maps to Windows default `false`.
- Explicit `false` also maps to `false`.
- Validator parse: `POWERSHELL_PARSE=PASS`.
- No scheduler re-registration performed after validator fix.

Natural trigger evidence:

- Task: `HermesMarketIntelligence_EOD` at path `\\`.
- Trigger: `2026-11-17 20:05:00 WIB`.
- Task history: `LastRunTime=2026-11-17 20:05:00+07:00`, `LastTaskResult=75`.
- Child run: `20261117_200504_825cb163a4134d86a28eaea5f3c9e377`.
- Child PID: `17048`, exited by `20:09:05 WIB`.
- Target session: `2026-11-17`.
- Resolved session in child output: `2026-11-17`.
- Batch output: `updated=20`, `waiting=0`, `failed=0`, `metrics=20`.
- Semantic reason: `BACKLOG_REMAINING`.
- Semantic status: `WAITING`, exit `75`.
- Terminal receipt: `storage/runtime/eod-runs/20261117_200504_825cb163a4134d86a28eaea5f3c9e377.terminal.json`.
- Heartbeat: child exited; stdout `3079` bytes; stderr `0` bytes.
- Lock after run: `FREE`.

Checkpoint and continuation:

- Child output shows three 20-ticker batch summaries with remaining values `459`, `439`, and `419`.
- Persisted checkpoint referenced by that output is `20261117_130505auto_2f1ea7976a95.checkpoint.json`, not a new `20:05` checkpoint.
- The `20:05` terminal receipt reports only `updated=20`, `universe_remaining=459`.
- Session coverage read-back after trigger: `21/499` complete raw+adjusted, `1` raw-only, `478` neither.
- Coverage read-back does not reconcile with three claimed batches or receipt remaining `459`.
- Therefore continuation through all three batches is `REPORTED`, not `VERIFIED`.
- Checkpoint pickup is `REPORTED`, not `VERIFIED`, because new run metadata has `resolved_session=null` and `trigger_source=null`.
- No batch was run manually. No `schtasks /Run` used.

Next repetition:

- At audit `2026-11-17 20:14:51 WIB`, next trigger was `20:35:00 WIB`.
- Trigger `20:35` remains pending for natural observation.
- Expected behavior: select fresh session-17 gaps, exclude completed 20:05 scope, and preserve target session `2026-11-17`.

Protected state:

- Collector, realtime history, ML, strategy signals, and Outbox `#5` unchanged.
- Status remains `PARTIALLY_RECOVERED`.

## Addendum — Run Reconciliation and Fail-Closed Persistence Verification

Evidence freeze:

- Frozen directory: `storage/runtime/eod-runs/audit-freeze-20261117`.
- Frozen artifacts include run `20:05`, run `20:35`, manifests, stdout, stderr, metadata, heartbeats, terminal receipts, checkpoints, task XML, and SHA256 manifest.
- Historical artifacts were not rewritten.
- Run `20:35` was not stopped during audit.

Run `20:05` findings:

- Task history proved natural trigger at `2026-11-17 20:05:04+07:00`.
- Run: `20261117_200504_825cb163a4134d86a28eaea5f3c9e377`.
- Child PID: `17048`; child exited; lock released.
- Child stdout reported three batch summaries, but terminal receipt reported only first-batch totals.
- Old checkpoint `20261117_130505auto_2f1ea7976a95.checkpoint.json` was referenced by stdout.
- New run checkpoint was absent.
- Coverage after run: `21/499` complete raw+adjusted, `1` raw-only, `478` neither.
- Status: `MULTI_BATCH_PERSISTENCE_NOT_VERIFIED`.
- Status: `CHECKPOINT_CONTINUATION_NOT_VERIFIED`.

Run `20:35` findings:

- Task history proved natural trigger at `2026-11-17 20:35:04+07:00`.
- Run: `20261117_203503_02e6a055357e44d4a718dc8c40190923`.
- Target session: `2026-11-17`.
- Child PID: `22344`; child exited at `20:39:07 WIB`.
- Lock released: `FREE`.
- Three batches reported: `399`, `379`, `359` DB-reported remaining values.
- Manifests were fresh and non-overlapping with prior batch scopes.
- Read-only canonical audit verified all six audited manifests:
  - `20:05` batch 1: `20/20` canonical, `20/20` metric match.
  - `20:05` batch 2: `20/20` canonical, `20/20` metric match.
  - `20:05` batch 3: `20/20` canonical, `20/20` metric match.
  - `20:35` batch 1: `20/20` canonical, `20/20` metric match.
  - `20:35` batch 2: `20/20` canonical, `20/20` metric match.
  - `20:35` batch 3: `20/20` canonical, `20/20` metric match.
- SQL identity for audits: database `project_bot_recovered_20261115_022935`, user `aether_ingestion`, schema `public`, timezone `Asia/Bangkok`.
- Final coverage read-back: `140/499` complete raw+adjusted, `359` neither.
- Final metric audit: `139/140` strict match; `BACH` remains `METRIC_MISMATCH`.
- Canonical persistence: `VERIFIED` for six manifest scopes.
- Historical receipt/checkpoint linkage: old artifacts remain inconsistent; not relabeled.

Source fixes:

- `scripts/eod_auto_orchestrator.php` now writes checkpoint atomically through temporary file plus rename.
- Each batch stores run ID, parent run ID, session, DB role, schema, commands, manifest path, attempted, already-fresh, updated, canonical read-back, metric read-back, and DB-derived remaining count.
- Canonical read-back runs after each batch. Selected scope without canonical rows produces `INTERNAL_VERIFICATION_FAILED`, exit `1`, and stops continuation.
- Receipt parser reads latest summary, not first summary.
- Runner receipt now carries child checkpoint linkage and all batch results.
- `scripts/audit_eod_batch_persistence.php` added for read-only per-manifest canonical and metric verification.
- Existing task validator now compares ISO durations semantically and treats missing `StopAtDurationEnd` as Windows default `false`.
- Scheduler configuration was not re-registered.

Tests:

- Orchestrator self-test: `10 cases PASS`.
- PHP lint: PASS.
- Runner PowerShell parse: PASS.
- Validator PowerShell parse: PASS.
- `git diff --check`: PASS.
- No manual batch, `schtasks /Run`, worker, collector, dispatcher, news, monitoring, ML, strategy signal, or Outbox `#5` action used.

Required status labels:

- `TRIGGER_VERIFIED`.
- `MULTI_BATCH_PERSISTENCE_NOT_VERIFIED` for historical `20:05` receipt/checkpoint linkage.
- `CHECKPOINT_CONTINUATION_NOT_VERIFIED` for historical `20:05` checkpoint ownership.
- New source is fail-closed for future trigger acceptance.

Status remains `PARTIALLY_RECOVERED`.

## Addendum — Natural Run `21:05` and BACH Indicator Audit

Natural trigger:

- Task Scheduler trigger: `2026-11-17T21:05:01+07:00`.
- Run: `20261117_210501_1d0e605c08974705a29e995314a271a9`.
- Child: `20261117_210502auto_578e51295a10`.
- Parent linkage: receipt `parent_run_id=20261117_210501_1d0e605c08974705a29e995314a271a9`.
- Target session: `2026-11-17`.
- Trigger source: `TASK_SCHEDULER_EOD`.
- Scheduler state after run: `Ready`.
- Exit: `75`.
- Semantic: `WAITING`, `BACKLOG_REMAINING`.
- Lock: `FREE`.

Atomic checkpoint and receipt:

- Checkpoint: `storage/runtime/eod-runs/20261117_210502auto_578e51295a10.checkpoint.json`.
- Checkpoint contains `run_id`, `parent_run_id`, session, trigger source, `Asia/Jakarta`, database, role, schema, and three batch records.
- Receipt contains `batch_count=3`, `attempted=60`, `updated=60`, `canonical_verified=60`, `metric_verified=60`, `waiting=0`, `failed=0`, and `universe_remaining=299`.
- Each batch persisted `remaining`: `339 → 319 → 299`.
- Atomic checkpoint writer verified in source and self-test.
- Receipt aggregates all three batches.
- Source hash capture was added after this run; this run's receipt has no source hashes.

Scope and canonical read-back:

- Batch 1: 20 tickers, canonical `20/20`, metrics `20/20`, remaining `339`.
- Batch 2: 20 tickers, canonical `20/20`, metrics `20/20`, remaining `319`.
- Batch 3: 20 tickers, canonical `20/20`, metrics `20/20`, remaining `299`.
- Scope union: `60` tickers.
- Scope overlap: `0`.
- Manifest SHA-256 values were captured independently for all three manifests.
- Database identity: `project_bot_recovered_20261115_022935`.
- SQL role: `aether_ingestion`.
- Schema: `public`.
- Connection timezone: `Asia/Bangkok`.
- Application/calendar timezone: `Asia/Jakarta`.
- Explicit conversion required for operational calendar and displayed WIB timestamps.

Coverage after run:

- Complete raw+adjusted: `200/499`.
- Raw-only: `0`.
- Adjusted-only: `0`.
- Neither: `299`.
- Conflicted/invalid: `0`.
- Strict metric match: `199/200` before BACH classification.
- Strict residuals after partial-metric contract: `0`.
- No write in coverage audits.

BACH classification:

- Target session: `2026-11-17`.
- Adjusted input: `93` valid bars, `2026-07-08` through `2026-11-17`.
- Raw input: `93` valid bars, same date range.
- Valid indicators: close, RSI14, SMA20, SMA50, MACD, ATR14.
- SMA200: `INSUFFICIENT_93_OF_200`.
- Technical status: `PARTIAL_CALCULATED`.
- Classification: `INSUFFICIENT_INPUT_SMA200`, not provider failure and not provenance mismatch.
- Partial metrics now pass strict audit when required indicator values and provenance match.
- No historical row changed.

Runtime fix:

- Source hashes now persist in orchestrator checkpoint and runner receipt for future natural runs.
- Hash keys: runner, orchestrator, writer, manifest builder, classifier.
- No scheduler registration, collector restart, manual batch, or manual trigger used.
- One read-only audit SQL patch briefly omitted `WHERE`; PostgreSQL rejected it, no write occurred.
- `WHERE` restored and coverage audit rerun successfully.

Status markers:

- `TRIGGER_VERIFIED`.
- `MULTI_BATCH_PERSISTENCE_VERIFIED` for natural run `21:05`.
- `CHECKPOINT_PARENT_OWNERSHIP_VERIFIED` for this run's parent-child checkpoint linkage.
- Cross-run checkpoint continuation remains `CHECKPOINT_CONTINUATION_NOT_VERIFIED`; no claim made from this run alone.
- Historical `20:05` remains `MULTI_BATCH_PERSISTENCE_NOT_VERIFIED` and `CHECKPOINT_CONTINUATION_NOT_VERIFIED`.

Status remains `PARTIALLY_RECOVERED`.

## Addendum — Natural Run `21:35`, Riset AI, Running Trade, and ML Research Gate

Natural EOD trigger:

- Task Scheduler task: `HermesMarketIntelligence_EOD`, enabled, state `Ready`.
- Natural run: `20261117_213501_52d97427ae004abe929ab6faa7c8c25b`.
- Child/checkpoint: `20261117_213502auto_dfd57473f522`.
- Target/resolved session: `2026-11-17` / `2026-11-17`.
- Trigger source: `TASK_SCHEDULER_EOD`.
- Source hashes: `VERIFIED` against current `runner`, `orchestrator`, `writer`, `manifest_builder`, and `classifier` files.
- Scope: `20` unique tickers; no overlap with persisted prior scope was observed.
- Batch count: `1`; `attempted=20`; `updated=19`; `waiting=0`; `failed=1`.
- Canonical read-back: `19/20`; metric match `19/20`; `HOME` had `raw_rows=0`, `adjusted_rows=0`, `metric_rows=0`.
- DB-derived remaining: `280`.
- Read-only audit writes: `false`.
- Semantic result: `FAILED`, exit `1`, reason `PROVIDER_FAILURE`.
- Lock after run: `FREE`.
- Exact provider error detail was not present in terminal stdout/stderr; cause remains `PROVIDER_FAILURE`, not reclassified as `PROVIDER_WAITING_OBSERVED`.
- Retry state is persisted by `DailyEodRefreshService` with bounded attempts/backoff, but active retry execution is not claimed until a later natural trigger reads it back.
- Cross-run resume: `NOT VERIFIED`. Fresh no-overlap scope and remaining decrease were observed, but failed `HOME` prevents claiming healthy resume acceptance.

Coverage read-back after natural run:

- Complete raw+adjusted: `219/499`.
- Raw-only: `2`.
- Adjusted-only: `0`.
- Neither: `278`.
- Conflicted/invalid: `0`.
- Strict metric residual: `0` under partial-indicator contract.
- Coverage is session-specific, not history-instrument count.

Riset AI page:

- `GET /research?ticker=BBCA`: `VERIFIED_LIVE`; standalone page renders selected ticker, analysis type, optional question, submit control, backend progress region, evidence/result region, and safe text rendering.
- Sidebar menu `Riset AI`: `VERIFIED_LIVE`.
- Detail-emiten Riset AI link: `IMPLEMENTED`; target is URL-addressable `/research?ticker={ticker}`.
- Request lifecycle uses existing `ResearchRequest`, dedup, writer bridge, CSRF, session capability, backend deadline, request ID, localStorage reload recovery, and exponential polling backoff.
- Progress stage labels are backend-derived: queued, data check/provider refresh, indicators, evidence/synthesis, completed/partial/failed. No synthetic percentage is rendered.
- Result fields expose stored model, validation, evidence, freshness, adjustment, technical/broker/fundamental/news payloads, and limitations as escaped text.
- Isolated Laravel verification: `41 tests`, `41 passed`, `192 assertions` across research, dashboard, provenance, and freshness tests.
- Concurrent web request acceptance: `PENDING`; no operational concurrent writer test was run in recovery mode.

Running Trade page:

- `GET /running-trade`: `VERIFIED_LIVE`; dedicated page renders.
- Sidebar menu appears directly below `Market Monitor`.
- Polling uses existing `/api/market/running-trade-state`; no WebSocket engine, parser, board, timestamp, reconnect, threshold, credential, or collector process changed.
- Current observed state remains `DISCONNECTED`/empty collector state; this is rendered honestly, not replaced with synthetic trades.
- `BOARD_SCOPE_UNVERIFIED` and `EVENT_TIME_UNVERIFIED` remain.
- Listener duplication check: no second collector started; page uses one in-flight guard and one interval per page instance.

ML research:

- Existing artifacts and reports audited. Prior EXP-01/EXP-02/EXP-03 results remain research-only and are not activated.
- Dataset policy remains: OHLCV-only retrospective experiment may be evaluated offline; broker/fundamental multi-modal path is `BLOCKED` without independent point-in-time availability timestamps; intraday/scalping is `BLOCKED` under `BOARD_SCOPE_UNVERIFIED`.
- Existing manifest defines T+5 close-to-close label, `±1.50%` classes, chronological train/validation/test splits, purging/embargo, train-only scaling, fees, and slippage. Test period is exposed retrospective benchmark, not untouched forward holdout.
- Existing artifact results do not support promotion. New isolated scratch runs used copied CSVs and `OMP_NUM_THREADS=1`, `OPENBLAS_NUM_THREADS=1`, `MKL_NUM_THREADS=1`, `NUMEXPR_NUM_THREADS=1`; no repo artifact, DB row, request path, or production model changed.
- EXP-01 scratch result: dataset hash `b9dabf986858c801e309557b2f25090121d67909038592565d0da485b2a89caf`, `359505` rows, `900` tickers; test `93508`; balanced accuracy `43.31%` vs majority `33.33%`; macro F1 `0.4115`; ROC-AUC `0.6105`; UP coverage `26.16%`; UP precision `34.29%`; mean net return `-3.47%`; bootstrap CI vs majority `[+0.0810,+0.1160]`; verdict `REJECTED` because balanced accuracy and net-return gates failed.
- EXP-02 scratch result: `359505` rows; test eligible subset `30395`; Ablation A/B/C balanced accuracy `42.70%/41.60%/41.62%`; ROC-AUC `0.6126/0.6063/0.6110`; mean net return `-2.73%/-2.36%/-2.73%`; all three verdicts `REJECTED` because balanced accuracy and positive-net-return gates failed.
- No threshold was changed. No model artifact was promoted.
- ML training/production signal publication: `NOT RUN`; production promotion: `BLOCKED`; model mode remains `RESEARCH/SHADOW`.
- Quant tests: `12 passed, 1 failed`; failure is exact environment guard expecting `AI_LIVE_INFERENCE_ENABLED=false` in local `.env`. No model or production flag was changed to force pass.

Separated status:

- Maintenance operasional: `PARTIALLY_RECOVERED`; EOD source provenance fixed and verified on `21:35`, but provider failure and cross-run resume acceptance remain open.
- Kelengkapan UI: Riset AI `VERIFIED_LIVE`; Running Trade page `VERIFIED_LIVE`, data state `DISCONNECTED`.
- Kesiapan dataset: OHLCV retrospective `READY_FOR_RETROSPECTIVE_OHLCV_EXPERIMENT`; multi-modal and intraday `BLOCKED`.
- Hasil eksperimen ML: existing research artifacts only; no production claim; current frozen results do not pass promotion gate.
- Kelayakan promosi produksi: `BLOCKED`.

Protected state:

- No manual EOD trigger, batch, task registration, collector restart, second receiver, ML activation, strategy signal, live order, or Outbox `#5` retry performed.
- Historical receipts and checkpoints remain immutable.

## Addendum — Natural Run `22:35`, HOME Recovery, and Resume Boundary

Natural EOD trigger:

- Task Scheduler task: `HermesMarketIntelligence_EOD`, enabled, state `Ready` after completion.
- Natural run: `20261117_223505_a4f1d1d8152b4ee4b7257136c0bf12c9`.
- Child/checkpoint: `20261117_223506auto_9500d0bf1a24`.
- Target/resolved session: child checkpoint `2026-11-17` / `2026-11-17`.
- Trigger source: `TASK_SCHEDULER_EOD`.
- Source hashes: `VERIFIED` for runner, orchestrator, writer, manifest builder, and classifier at run time; recorded hashes matched files executed then.
- Scope: three manifests, `60` unique tickers, `0` overlap, each manifest `20` tickers.
- Progression: DB-derived remaining `220 -> 200 -> 180 -> 160`.
- Successful committed rows: `60`; canonical read-back `60/60`; metric match `60/60`; waiting `0`; failed `0`.
- Semantic result: `WAITING`, exit `75`, reason `BACKLOG_REMAINING`.
- Lock after run: `FREE`.
- HOME: current read-only registry shows `last_bar_date=2026-11-17`, `raw_last_bar_date=2026-11-17`, `adjusted_last_bar_date=2026-11-17`, `eod_refresh_status=UPDATED`, `eod_retry_attempts=0`. Previous `HOME` failure cause remains `UNKNOWN`; no historical transport detail was available.
- Retry evidence fix: `IdxEdgeClient` now preserves sanitized `http_status`, `transport_status`, `response_period`, `retry_after_seconds`, and mode-specific raw/adjusted evidence for future attempts. Focused EOD unit suite: `13 passed`, `72 assertions`; PHP lint and PowerShell parse passed.
- Retry semantics unchanged: bounded backoff remains; provider failure is not relabeled waiting without direct session-availability evidence; other tickers continue.

Resume classification:

- `CHECKPOINT_PARENT_OWNERSHIP_VERIFIED`: parent/current run IDs, child checkpoint, receipt aggregation, canonical audits, and lock release reconciled.
- `CHECKPOINT_CROSS_RUN_PICKUP_NOT_VERIFIED`: `22:35` manifests were built from current DB gaps and had no prior-checkpoint pickup marker. No-overlap scope proves selector progression, not checkpoint resume.
- Latest receipt predated receipt `target_session` propagation patch. Next natural run must read back top-level `target_session` plus `resolved_session`; no manual trigger allowed.

## Addendum - Active Implementation Checkpoint 18 November 2026

Status: PARTIALLY_RECOVERED. Implementasi UI masih berjalan.

EOD automatic:

- Latest read-only coverage for session `2026-11-17`: `454/499` complete raw+adjusted.
- Canonical gaps: `45` total, `42` eligible, `3` exhausted.
- Exhausted retry tickets: `SRIL`, `SUGI`, `TDPM`.
- `PTSN` recovered to raw+adjusted+metric complete.
- Canonical DB remains primary resume source. No synthetic checkpoint dependency added.
- Retry isolation now separates target sessions. Retry-only selection cannot inherit older-session state.
- Waiting tickers no longer stop batches that still commit updates.
- Legacy `scripts/build_gap_batch_manifest.php` now delegates to `scripts/build_gap_batch_manifest_v2.php`. Selector and orchestrator self-tests pass.
- Natural acceptance after code changes: `PENDING`. No manual EOD trigger used.
- Dashboard persistence diagnosis: duplicate owner proven. Old listener PID `17040`/later `19080` owned port `8000`; official launcher child could not bind and exited. Sanitized server logs captured at `C:\Users\ASUS\AppData\Local\Temp\sf_proc_00.out` and `C:\Users\ASUS\AppData\Local\Temp\sf_proc_00.err`.
- Launcher hardening landed: occupied-port preflight returns structured `dashboard_port_already_in_use`; batch no longer pauses at `Press any key`.
- Detached launcher redesign remains `PENDING`: first `cmd /c` and PowerShell `Start-Process` attempts returned `detached_server_launcher_failed`; no persistent-owner acceptance claimed.
- Dashboard Task Scheduler owner `AetherMarketIntelligence_Dashboard` was registered without credentials in arguments. Initial nested `wscript -> batch -> PHP` run hit `Last Result=10` from verified port collision with stale PID `21740`; stale tree was stopped after exact PID/command-line verification.
- Task action was changed to direct `php.exe launch_readonly_dashboard.php --serve`, then started once. Task read-back showed `Last Result=9`; sanitized launcher receipt recorded `stage=server_start`, `reason=foreground_server_process_unavailable`, `sqlstate=null`, `secret_printed=false`. No listener, server stdout, or server stderr was created. Persistent-owner acceptance remains `NOT VERIFIED`.
- Task Scheduler task remains registered and enabled, but no dashboard process is running. Do not retry start until `proc_open` failure is diagnosed under the `ASUS` Task Scheduler context.
- Existing-task diagnostic proved `proc_open()` works under Task Scheduler: PHP child exit `0`, pipe child exit `0`, file-redirection child exit `0`, Laravel `artisan --version` exit `0`, stdout `Laravel Framework 13.36.0`, stderr empty, runtime log write `true`, warnings empty. The first diagnostic receipt was falsely classified because it checked `is_resource($process)` after `proc_close()`; fixed to use child exit code.
- Dashboard persistence root cause proven and fixed. Run `dashboard_20261118T040301Z_cced7603` reached `proc_open` with `returned_resource=false`; exact warning was `proc_open(C:/Users/ASUS/AppData/Local/Aether-Market-Intelligence/recovery/logs/dashboard-server.stdout.log): Failed to open stream: No such file or directory`. Launcher created `recovery/runtime/logs`, but server descriptors targeted `recovery/logs`.
- Fix changed server log target to `C:\Users\ASUS\AppData\Local\Aether-Market-Intelligence\recovery\runtime\logs`. Run `dashboard_20261118T040344Z_e0f12998` reached `returned_resource=true`, child PID `21424`, `running=true`, `exit_code=-1`, with no warning.
- One controlled start through existing `\AetherMarketIntelligence_Dashboard` then produced one listener `127.0.0.1:8000`, PID `22020`, with PHP server parent chain `21424 -> 5636 -> 22020`. Task state was `Running`, `Last Run Time=11/18/2026 11:03:44 AM`, and `Last Result=267009` while server remained active. No duplicate task or launcher created.
- Persistence acceptance passed: listener remained present after launcher command returned, multiple browser/HTTP requests completed, and a 10-second idle interval preserved listener. Server stdout contains clean startup and request records; server stderr is empty. No new Blade parse error observed.
- Runtime identity passed from `/api/runtime-identity`: database `project_bot_recovered_20261115_022935`, user `aether_runtime`, `read_only=true`, writable tables `0`, writable sequences `0`, `writes_executed=false`, `secret_printed=false`.
- Live dashboard passed Movers/IHSG display: Movers rendered 10 provider rows with exact `by=chg&n=10`; IHSG rendered provider value/change with `As-of=N/A` and `EVENT_DATE_UNVERIFIED`, not guessed date. BBCA AI detail rendered `STRUCTURED_FALLBACK`, historical bar date, and `NO_ACTIVE_INFERENCE_FOR_BAR`.
- Browser console errors: empty. Horizontal overflow: false. Dashboard maintenance acceptance: `OPERATIONALLY_PERSISTENT`, with provider event date still unverified.


API sample acceptance:

- Bounded live probe covered 7 documented REST endpoints. All returned HTTP `200` in sample probe.
- Raw responses and SHA-256 receipts stored under `storage/runtime/provider-capability-probes/20261117_180403/`.
- Parser/client isolated acceptance passed `18 tests`, `76 assertions` before latest dashboard slice.
- Movers contract passed `1 test`, `4 assertions` after fixing root cause: display snapshot was incorrectly charged against backfill reserve and returned `DAILY_BUDGET_RESERVED`.
- Movers request now preserves exact `by=chg&n=10` query and typed result.
- IHSG uses separate `INDEX` capability. Equity quote remains quarantined.
- Insider and chart samples remain schema-validated only and quarantined because unit, board, or volume semantics remain incomplete.

Failed edit reconciliation:

- Earlier compact manifest rewrite did not land in legacy file. Current legacy file was reread and patched without overwriting other work.
- Wrapper now delegates to versioned manifest implementation. PHP lint, selector self-test, and orchestrator self-test passed.

UI status:

- Dashboard two-column Movers/IHSG implementation: IMPLEMENTED; backend client and registry gates tested. Production registry synchronized; live widget verification pending cache refresh.
- Detail-emiten restructuring: IMPLEMENTED for summary technical/support-resistance, chart/indicator surface, fundamental unit gate, broker nullable rendering, and structured fallback labels. Fundamental/insider/news depth remains capability-dependent.
- Running Trade engine and collector: NOT TOUCHED.
- ML training, promotion, strategy signals, and Outbox `#5` retry: NOT RUN.

Evidence classes remain separate: DOCUMENTED, IMPLEMENTED, TESTED_ISOLATED, and LIVE_VERIFIED.

# End active checkpoint


Coverage cutoff `2026-11-17`:

- Current read-only audit: `279 complete`, `220 neither`, `2 raw-only`, `0 adjusted-only`, `0 conflicted`; `writes_executed=false`.
- This cutoff is separate from EOD receipt progression. Do not add rows to the receipt from arithmetic subtraction.

Protected state:

- No manual EOD trigger, batch, task registration, collector restart, second receiver, ML activation, strategy signal, live order, or Outbox `#5` retry performed.
- Historical receipts and checkpoints remain immutable.



