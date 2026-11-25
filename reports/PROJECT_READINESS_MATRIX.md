# Matriks Kesiapan Proyek Aether Market Intelligence

**Versi**: 3.0.0 • **Tanggal Audit**: 1 November 2026 WIB  
**Metodologi Skor**:
- `NOT_STARTED`: 0.00
- `PARTIAL`: 0.25
- `IMPLEMENTED`: 0.50
- `VERIFIED_TEST`: 0.75
- `VERIFIED_OPERATIONAL`: 1.00

---

## 1. Matriks Kesiapan Rekayasa Produk (Product Engineering Readiness)

| requirement_id | Fitur / Komponen | Bobot | Status | Faktor Skor | Weighted Score | Bukti Audit | Gap |
|---|---|:---:|---|:---:|:---:|---|---|
| **FR-01** | Direktori Seluruh Kandidat Registri BEI | 8 | VERIFIED_TEST | 0.75 | 6.00 | `BeiUniverseRegistry::KNOWN_BEI_ISSUERS` (84+ emiten divalidasi ketat), pencarian/filter di `/market`, normalisasi `^JKSE` | Seluruh 900+ emiten bursa belum terdaftar penuh di registri kanonikal (baru 84+ terdaftar; sisanya mengandalkan riwayat `market_observations`) |
| **FR-02** | Harga Kanonikal & Batas Data Valid | 8 | VERIFIED_OPERATIONAL | 1.00 | 8.00 | `MarketObservation` `source = 'IDX Edge PRO'`, status `VALID`, canary test BBCA/TLKM/BMRI/BULL | Endpoint intraday `/api/price` masih `QUARANTINED` akibat kontaminasi Pasar Negosiasi (NG) |
| **FR-03** | Detail Emiten (7 Tab Navigasi) | 7 | VERIFIED_TEST | 0.75 | 5.25 | Blade component terpadu, X-Tab partial rendering, browser test tab summary & broker | Tab News & Event masih mengandalkan LKBN Antara lokal |
| **FR-04** | Chart & Volume EOD Historis | 7 | VERIFIED_OPERATIONAL | 1.00 | 7.00 | Integrasi candlestick canvas, 25-200 bar tersinkronisasi, anti-synthetic fill | Timeframe saat ini hanya EOD 1 hari (belum ada bar mingguan/bulanan) |
| **FR-05** | Teknikal & Fundamental Deterministik | 8 | VERIFIED_OPERATIONAL | 1.00 | 8.00 | `TechnicalMetricsCalculationService` batas bar ketat (20, 50, 200), `CompanyFundamentalService` IDR/USD & atribusi induk | Beberapa emiten belum merilis laporan tahunan FY2025 di API |
| **FR-06** | Konsultasi Gemini melalui Hermes Worker | 9 | VERIFIED_OPERATIONAL | 1.00 | 9.00 | Profil `aether-prod`, oneshot adapter, session isolation per job, schema `aether.analysis.v3` | Reasoning budget 60 detik membatasi konsultasi ultra-panjang |
| **FR-07** | Antrean Job Asinkron & Non-blocking Inbound | 8 | VERIFIED_OPERATIONAL | 1.00 | 8.00 | `ScheduledTelegramJob` (latensi ack <200ms), worker 1 menit via Task Scheduler OS, token fencing | Tidak ada |
| **FR-08** | Analisis & Screening Akumulasi Broker | 8 | VERIFIED_OPERATIONAL | 1.00 | 8.00 | Top-N broker, Buy/Sell Avg kotor, kontinuitas sesi terakhir, `BrokerAccumulationScreeningService` | Data broker pasar reguler adalah sampel Top-N, bukan bursa komprehensif |
| **FR-09** | Retrieval Berita & Dokumen Keterbukaan | 7 | VERIFIED_TEST | 0.75 | 5.25 | Skema `document_chunks`, layanan `DocumentRetrievalService`, uji fixture; dokumen seeder ditandai `UNVERIFIED_PROVENANCE` & dikeluarkan dari jalur operasional | 0 dokumen operasional terverifikasi asal (raw file/PDF belum terarsip di storage/app/evidence); belum layak VERIFIED_OPERATIONAL |
| **FR-10** | Penjadwalan Laporan & Kalender Bursa | 6 | VERIFIED_TEST | 0.75 | 4.50 | `run_scheduled_dispatcher.bat` (1 menit), morning briefing & evening review console commands | Laporan sesi intraday (09:15, 12:15, 15:15) masih diblokir akibat status kuarantin |
| **FR-11** | Publikasi Telegram Multipart & Idempoten | 7 | VERIFIED_OPERATIONAL | 1.00 | 7.00 | `TelegramFormattingService` split 3900 char, outbox dedup key, pengiriman multipart Msg 491/492 & Msg 538/539 | Retry Telegram dapat menghasilkan jeda beberapa detik pada jaringan lambat |
| **FR-12** | Pemantauan & Siklus Hidup Prediksi | 5 | VERIFIED_TEST | 0.75 | 3.75 | `PredictionSignal` horizon 5 hari bursa, anti-lookahead, evaluasi post-horizon otomatis | Evaluasi outcome membutuhkan waktu tunggu 5 hari bursa kalender |
| **FR-13** | Audit Trail AI & Evidence Snapshot | 5 | VERIFIED_OPERATIONAL | 1.00 | 5.00 | Snapshot SHA-256 tersimpan di DB sebelum publikasi, raw JSON di dashboard, validasi otorisasi | Tidak ada |
| **FR-14** | Profil, Pengaturan & System Health | 3 | VERIFIED_TEST | 0.75 | 2.25 | Endpoint `/settings`, `/profile`, `/health`, runtime probe bertimestamp, zero secret leak | Unggah logo raster belum memiliki crop interaktif di UI |
| **FR-15** | Permintaan Analisis via Web Dashboard | 4 | VERIFIED_OPERATIONAL | 1.00 | 4.00 | Query-param recovery `/research?request_id=...`, capability-protected fetch, terminal polling stop, reload result cards, History read-back tanpa reinference; live BRIS request `REQ_20261122_181203_BRIS_wbTi` | Gemini inference masih fallback eksternal; History evaluasi opsional tetap `UNREADABLE_OR_NOT_AVAILABLE` |
| **TOTAL** | **Assertion: 15 Functional Requirements** | **100** | — | — | **91.00** | **Total Skor Terbobot = sum(weighted_score) = 91.00%** | **Kesiapan Rekayasa Produk: 91.00%** |

---

## Runtime Acceptance 23 November 2026

- `IMPLEMENTED`: `aether:screen-ml-bbca` memakai IDX Edge client existing, feature contract BBCA v2, artifact loader fail-closed, evidence, signal, dan Telegram outbox.
- `LIVE_VERIFIED`: launcher resmi memakai `project_bot_recovered_20261115_022935/aether_ingestion`; provider raw/adjusted HTTP `200`; bar `2026-11-23`; inference aktual `MODEL_ABSTAIN`; probability `0.02610197179968863`; threshold `0.93`.
- `LIVE_VERIFIED`: Evidence `#223`, Outbox `#41`, Telegram `message_id=729`, status `SENT`; read-back `SENT_ALREADY` terverifikasi melalui launcher resmi. Receipt lama `#5/#8/message_id=727` tetap tidak direplay.
- `TESTED`: rerun command idempoten; tidak membuat evidence/outbox/message kedua. Adapter regression `3 passed`; PHP syntax dan `git diff --check` lulus.
- `IMPLEMENTED`: halaman `/signal-monitoring` HTTP `200`; monitoring memakai `MarketMonitoringService` existing.
- `IMPLEMENTED`: scheduler Laravel existing memuat `aether:screen-ml-bbca --live` pada `16:15 WIB`; tidak membuat task atau worker baru.
- `BLOCKED`: model masih hanya BBCA; ticker lain tidak diinferensi. Produksi tetap `NOT_PROMOTED`; akurasi 90% tidak diklaim.

## 2. Perhitungan Persentase Kesiapan Berbobot

$$\text{Progress \%} = \sum (\text{Bobot} \times \text{Skor Status})$$

- **FR-01**: $8 \times 0.75 = 6.00$ *(VERIFIED_TEST: registry kanonikal mencakup 84+ emiten tervalidasi, belum 100% dari 900+ emiten BEI)*
- **FR-02**: $8 \times 1.00 = 8.00$
- **FR-03**: $7 \times 0.75 = 5.25$
- **FR-04**: $7 \times 1.00 = 7.00$
- **FR-05**: $8 \times 1.00 = 8.00$
- **FR-06**: $9 \times 1.00 = 9.00$
- **FR-07**: $8 \times 1.00 = 8.00$
- **FR-08**: $8 \times 1.00 = 8.00$
- **FR-09**: $7 \times 0.75 = 5.25$ *(VERIFIED_TEST: 0 dokumen operasional terverifikasi penuh; chunk seeder berstatus UNVERIFIED_PROVENANCE dan dikeluarkan dari retrieval operasional)*
- **FR-10**: $6 \times 0.75 = 4.50$
- **FR-11**: $7 \times 1.00 = 7.00$
- **FR-12**: $5 \times 0.75 = 3.75$
- **FR-13**: $5 \times 1.00 = 5.00$
- **FR-14**: $3 \times 0.75 = 2.25$
- **FR-15**: $4 \times 1.00 = 4.00$

$$\text{Total Skor Terbobot} = 6.00 + 8.00 + 5.25 + 7.00 + 8.00 + 9.00 + 8.00 + 8.00 + 5.25 + 4.50 + 7.00 + 3.75 + 5.00 + 2.25 + 4.00 = \mathbf{91.00\%}$$

*(Catatan Rekonsiliasi Audit: Skor rekayasa produk dihitung ulang secara programatis dari seluruh 15 baris menjadi **91.00%** [91.00 / 100]. Nilai baris TOTAL terbukti identik dengan jumlah eksak weighted_score seluruh requirement).*

---

## 3. Laporan Kesiapan Terpisah Sesuai Kategori

1. **Kesiapan Rekayasa Produk Keseluruhan (PRD Scope)**: **91.00%**
   Arsitektur dasar, kontrol antrean, keamanan data, isolasi proses Hermes, dan pipeline kanonikal telah terpasang dan terverifikasi. Direktori emiten bursa (FR-01) dan retrieval dokumen (FR-09) berada pada level VERIFIED_TEST (0.75).
2. **Kesiapan Layanan EOD & Konsultasi Asinkron (Ready for Use)**: **95.00%**  
   Layanan EOD, konsultasi dua arah Telegram, analisis komprehensif 6 dimensi, screening broker lintas emiten, dan ringkasan audit telah terbukti beroperasi penuh secara otomatis di background.
3. **Kesiapan Intraday Realtime**: **15.00% (QUARANTINED)**  
   Data intraday tick `/api/price` diblokir operasional akibat kontaminasi transaksi Pasar Negosiasi (NG) dari provider. Sinyal intraday 09:15, 12:15, dan 15:15 sengaja dinonaktifkan demi keselamatan modal pengguna.
4. **Validitas Prediktif Model ML**: **TERPISAH DARI REKAYASA SISTEM (EVALUASI JATUH TEMPO)**  
   Akurasi model ML tidak digabungkan dengan persentase engineering perangkat lunak. Evaluasi berbasis calendar-day true target purging (+5 Hari Bursa BEI) saat ini mencatat metrik historis ~60.0% pada sampel teruji, dan target 90% dilarang diklaim tanpa audit forward-test independen.

---

## 4. Klasifikasi Status Fitur

### A. Fitur Stabil & Terverifikasi Operasional
- Kontrak data tunggal IDX Edge PRO Pasar Reguler (Board RG).
- Isolasi sesi penalaran Hermes oneshot profile `aether-prod`.
- Penanganan inbound Telegram non-blocking (<200 ms) dengan ACK instan.
- Task Scheduler OS Windows memicu worker `hermes:process-consultation-queue` setiap 1 menit.
- Skema database kanonikal `market_analyses` berbasis JSON containment.
- Pembatalan alert basi terhadap worker token yang sedang aktif.
- Sanitasi pesan kegagalan pengguna tanpa kebocoran detail teknis database/host.

### B. Fitur Selesai Rekayasa, Menunggu Waktu / Evaluasi Operasional Luas
- Evaluasi post-horizon 5 hari bursa (`PostHorizonEvaluation`).
- Scheduled briefing pagi (08:30 WIB) dan review malam (20:00 WIB).
- Pelacakan dokumen dan keterbukaan informasi di `document_chunks`.

### C. Fitur Belum Optimal
- Ekstraksi tabel keuangan catatan kaki laporan keuangan (masih mengandalkan ringkasan rasio formal).
- Polling antrean otomatis pada antarmuka web dashboard (pengguna masih me-refresh halaman).
- Multi-timeframe bar candlestick (saat ini baru harian EOD).

### D. Fitur Belum Dikerjakan / Ditunda
- Pipeline rekomendasi intraday 09:15, 12:15, dan 15:15 BSJP (menunggu pemisahan board Pasar Reguler dari provider bursa).
- Pencarian vektor dokumen (pgvector/hybrid) ditunda sampai skala dokumen lokal memerlukan pengindeksan embedding.

### E. Blocker Eksternal
- **Kontaminasi Data Intraday Provider**: Endpoint harga intraday `/api/price` mencampur transaksi Pasar Negosiasi tanpa filter parameter `board=RG` yang efektif di server penyedia.

---

## 5. Tiga Prioritas Rekayasa Berikutnya

1. **Akses DB minimum**: Audit dan grant `SELECT` dashboard pada DB runtime `project_bot_recovered_20261115_022935`, role `aether_ingestion`, tanpa superuser/destructive privilege.
2. **Riset AI browser acceptance**: Buktikan request baru sampai terminal, relasi request/evidence/analysis, reload, dan history tanpa reinference.
3. **Pemisahan Board Pasar Reguler Provider**: Berkoordinasi atau memfilter secara independen transaksi Pasar Negosiasi pada endpoint quote agar pipeline intraday dapat dibuka kembali dengan aman.

## Addendum — Kesiapan Presentasi 22 November 2026

Runtime terbukti:
- Dashboard listener `127.0.0.1:8000`, PID terakhir saat audit `9376` sebelum restart berikutnya.
- Database `project_bot_recovered_20261115_022935`.
- Role `aether_ingestion`.
- Schema `public`, search path `public`.
- `market_observations` terbaca: `523331` row.
- Registry runtime sebelumnya terbukti `945` emiten `EQUITY` BEI; angka 84+ di bagian lama sudah stale.

LIVE_VERIFIED:
- `/` HTTP `200` setelah warm-up; response terakhir sekitar 7.09 detik.
- `/market/ACES?tab=summary` HTTP `200`.
- `/history` HTTP `200`.
- `/health` HTTP `200`.
- `/profile` HTTP `200`.
- `/research` HTTP `200`.
- `/screener` HTTP `200`.
- `npm run build` lulus, Vite `8.3.5`.
- Focused tests: `40` passed, `174` assertions.
- PHP lint, Pint, Blade cache, dan `git diff --check` lulus.

IMPLEMENTED/TESTED:
- Sidebar Screener di bawah Market Monitor.
- Route `/screener`.
- Provider screener parser, filter ticker/nama, filter bucket, link ticker ke detail.
- Status provider failure dibedakan dari valid empty response.
- UI memberi label `STATISTIK PROVIDER`; tidak menyebut WR event sebagai akurasi ML.
- Test Screener provider rows dan filtering ditambahkan.
- Model konsultasi efektif: requested config `ag/gemini-3.8-flash-high` melalui `services.consultation_ai`.
- AI History menampilkan model dari config efektif, bukan label `gemini/gemini-3.6-flash` hardcode.

PENDING/BLOCKED:
- Screener live provider belum dibuktikan. Runtime flag `IDX_EDGE_SCREENER_ENABLED` masih false; halaman menampilkan `PROVIDER_CAPABILITY_UNVERIFIED` dengan benar. Jangan mengaktifkan tanpa probe schema/entitlement authenticated.
- AI consultation health: endpoint dan model tersedia, tetapi health probe mencatat `inference_succeeded=false`. Gemini live acceptance belum boleh disebut lulus.
- Dashboard health membaca dashboard AI `gemini-3.6-flash`, sedangkan jalur konsultasi memakai `ag/gemini-3.8-flash-high`. Ini dua konfigurasi berbeda; keduanya belum boleh disebut satu model.
- ACL runtime belum lengkap untuk tabel lama seperti `user_watchlists`, `post_horizon_evaluations`, `system_health_checks`, dan `company_fundamentals`. Fallback membuat halaman HTTP 200, tetapi tidak membuktikan data terbaca.
- `/api/runtime-identity` normal production mengembalikan 404/empty message karena route dibatasi candidate mode. Identitas runtime dibuktikan melalui `/api/health` dan launcher.
- Intraday tetap quarantine karena board scope Pasar Reguler belum terbukti bersih.
- Berita/dokumen primer terverifikasi belum lengkap.
- ML tidak diaktifkan dan belum tervalidasi untuk seluruh emiten.
- Telegram live delivery belum dibuktikan dengan pasangan numeric inbound `update_id` dan outbound `message_id`.

## Addendum — Closure Riset AI dan History 22 November 2026

- History kosong memiliki penyebab SQL aktual, bukan asumsi ACL umum: eager-load `post_horizon_evaluations` gagal dengan `SQLSTATE[42501] permission denied for table post_horizon_evaluations`.
- Fix: query History utama hanya eager-load `marketAnalyses`; evaluasi post-horizon tetap opsional. Record `ONDEMAND_NZIA_20261121_105610` kini terbaca setelah reload.
- Bukti runtime: `/history` HTTP `200`, response 49,914 bytes, empty-state hilang, record analisis tampil, raw JSON tetap tersembunyi.
- Audit privilege `has_table_privilege` untuk tabel runtime: SELECT/INSERT/UPDATE/DELETE terbukti aktif pada role dashboard. Tidak ada REVOKE atau GRANT dijalankan.
- Consultation router telemetry ditambah: correlation ID, requested model, start/deadline, exception class, previous exception, HTTP status, sanitized error, connect/first-byte/total timing.
- Jalur aktif tetap `ag/gemini-3.8-flash-high`; `/v1/models` reachable bukan bukti inference. Inferensi baru belum dijalankan karena transport root cause belum terbukti.
- Status acceptance Riset AI: `PENDING`; jangan klaim Gemini sehat, returned model nyata, atau FULL_COMPONENTS.
- Telegram baru: `PENDING`; belum ada pesan uji diizinkan/terkirim. Running Trade: diagnosis existing belum selesai; koneksi kedua tidak dibuka.

## Addendum — Acceptance Worker Riset AI dan Konteks DB 22 November 2026

- Request baru `REQ_20261122_151555_BBCA_j84f` masuk melalui `scripts/launch_app_writer.php`, bukan fixture.
- Worker existing memproses natural queue. Status akhir `completed`; Evidence `209`; Analysis `173`; ticker `BBCA`; bar `2026-11-20`; freshness `AVAILABLE_FRESH`.
- Data berhasil tersimpan: technical metrics calculated, broker `REFRESHED_API` dengan periode provider/request `2026-11-14` sampai `2026-11-20`, dan tiga laporan financial component tersedia dalam snapshot. News tetap `NOT_FETCHED` karena kontrak authenticated belum tersedia.
- Evidence dan canonical observation terikat ke request ID. Raw dan adjusted payload memiliki hash/path immutable. Completion scope `PARTIAL_COMPONENTS`; verification `PARTIAL_NOT_FULLY_VERIFIED`.
- Jalur consultation aktif memakai `ag/gemini-3.8-flash-high`. Probe worker correlation ID `AI_20261122_081730_fd48b649` menerima HTTP `200`; durasi `27298 ms`; connect `1 ms`; first byte `27295 ms`; total `27297 ms`; exception chain kosong; sanitized curl error kosong.
- Request diterima router dan diteruskan sampai respons upstream. Tidak ada bukti antrean transport atau kegagalan koneksi pada probe ini. Health probe terpisah masih melaporkan `inference_succeeded=false`; status health lama tidak menimpa bukti request baru.
- History reload menampilkan request/evidence/analysis tersimpan tanpa provider call baru. Query utama tidak lagi gagal karena relasi evaluasi opsional.
- DB context aktual `/api/health`: database `project_bot_recovered_20261115_022935`, user `aether_ingestion`, schema `public`, search path `public`. CLI `tinker` tanpa launcher terbukti memakai database `project-bot`; hasilnya bukan bukti konteks HTTP. Tidak ada ACL change.
- `post_horizon_evaluations` pernah menghasilkan `SQLSTATE[42501]` pada request HTTP lama. Akses efektif dari konteks HTTP saat ini belum dapat dibuktikan melalui endpoint read-only khusus. History tetap tampil; status evaluasi harus dianggap `UNREADABLE_OR_NOT_AVAILABLE`, bukan kosong.
- Screener fixture rows, filter bucket/search, null/zero handling, dan link ticker lulus focused tests. Live Screener tetap `PROVIDER_EMPTY`.
- Telegram acceptance tetap `PENDING`: belum ada pesan pengguna baru yang diotorisasi, inbound `update_id`, dan outbound `message_id`. Tidak ada pesan sintetis dikirim.
- Running Trade tetap `DEGRADED / STALE_NO_HEARTBEAT`; owner/state/log dibaca dari runtime existing. Tidak ada koneksi kedua.

Skor 90% lama tidak dipakai sebagai skor final. Matriks perlu dihitung ulang setelah ACL, screener live, dan AI live acceptance selesai.
