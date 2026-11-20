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
| **FR-15** | Permintaan Analisis via Web Dashboard | 4 | VERIFIED_TEST | 0.75 | 3.00 | Modal request research, durable job creation dari UI web, zero-LLM saat navigasi tab | Modal web saat ini memerlukan refresh untuk melihat status selesai |
| **TOTAL** | **Assertion: 15 Functional Requirements** | **100** | — | — | **90.00** | **Total Skor Terbobot = sum(weighted_score) = 90.00%** | **Kesiapan Rekayasa Produk: 90.00%** |

---

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
- **FR-15**: $4 \times 0.75 = 3.00$

$$\text{Total Skor Terbobot} = 6.00 + 8.00 + 5.25 + 7.00 + 8.00 + 9.00 + 8.00 + 8.00 + 5.25 + 4.50 + 7.00 + 3.75 + 5.00 + 2.25 + 3.00 = \mathbf{90.00\%}$$

*(Catatan Rekonsiliasi Audit: Skor rekayasa produk dihitung ulang secara programatis dari seluruh 15 baris menjadi **90.00%** [90.00 / 100]. Nilai baris TOTAL terbukti identik dengan jumlah eksak weighted_score seluruh requirement).*

---

## 3. Laporan Kesiapan Terpisah Sesuai Kategori

1. **Kesiapan Rekayasa Produk Keseluruhan (PRD Scope)**: **90.00%**  
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

1. **Pengayaan Ingesti Dokumen Lokal**: Memperluas pemecahan dokumen laporan keuangan berkala ke tabel `document_chunks` untuk emiten-emiten utama BEI.
2. **Penyempurnaan Realtime Polling Dashboard Web**: Menambahkan mekanisme polling status ringan via API internal untuk modal riset di dashboard.
3. **Pemisahan Board Pasar Reguler Provider**: Berkoordinasi atau memfilter secara independen transaksi Pasar Negosiasi pada endpoint quote agar pipeline intraday dapat dibuka kembali dengan aman.
