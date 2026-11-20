# LAPORAN AKSEPTASI & VERIFIKASI WEBSOCKET RUNNING TRADE IDX EDGE
**Aether Market Intelligence** | Tanggal: 04 November 2026

---

## 1. Status Implementasi & Verifikasi Operasional
- **Jalur Upstream WebSocket**: `VERIFIED_OPERATIONAL` (Handshake HTTP 101, Stream Live Active, UI Polling Active).
  - URL: `wss://stock.arjum.com/ws/running-trade`
  - Waktu Verifikasi Live Sesi Aktif: 04 November 2026, 13:44:50 WIB
  - Upstream Service: `services/idx_edge_ws_supervisor.py` (Single managed owner, PID 11240).
  - Status Pasar: "open" (`Sesi 2 berlangsung (13:30-15:50 WIB)`).
  - Total Pesan Upstream Diterima: > 1.000 pesan (terdiri dari tipe `snapshot`, `trade`, dan `top5`).
  - Total Transaksi Diterima: > 1.500 transaksi berjalan.
  - Sampel Payload Trade Nyata (Tersanitasi):
    ```json
    {
      "trade_id": null,
      "ticker": "BBCA",
      "price": 4920.0,
      "lot": 1.0,
      "c_raw": "buy",
      "board": "MIXED_OR_UNSPECIFIED",
      "event_time": null,
      "received_at": "2026-11-04 13:44:38"
    }
    ```
  - Temuan Kontrak Skema Provider:
    * `t` = ticker (misal `BBCA`, `BRIS`, `DEWA`, `BOBA`).
    * `p` = harga transaksi.
    * `l` = volume transaksi dalam satuan lot.
    * `c` = string aksi (`buy` / `sell`). **Belum terbukti sebagai aggressor order flow pasti** (tidak boleh dipakai mengarang buy pressure).
    * `time` = tidak disediakan per tick (event time bernilai `null`).
    * `board` = tidak disediakan per tick (berstatus `MIXED_OR_UNSPECIFIED`).
  - Web UI Monitor (`/market`): `VERIFIED_OPERATIONAL`.
    * Tabel bertambah otomatis setiap 3 detik via AJAX `GET /api/market/running-trade-state` tanpa refresh halaman.
    * Status koneksi menampilkan `ONLINE (1551 TRADES) | Sesi 2 berlangsung (13:30-15:50 WIB)`.
    * Transaksi terakhir terbarui secara realtime (`2026-11-04 13:44:50`).

---

## 2. Pemetaan Skema Terverifikasi dari Live Upstream Stream

Uji sampel live soket pada sesi 5 November 2026 membuktikan upstream mengirim payload dengan field singkatan berikut:

| Simbol Upstream | Field Target Sistem | Tipe Data | Deskripsi & Batasan Integritas |
| :--- | :--- | :--- | :--- |
| `t` | `ticker` | string | Kode emiten BEI (misal `INOV`, `BBRI`, `UVCR`). |
| `a` | `exchange_match_time` | string (HH:MM:SS) | Waktu pencocokan bursa mentah. **Hanya memuat jam**, tanpa tanggal atau timezone. Tanggal tidak boleh diasumsikan hari ini saat replay. |
| `tn` | `exchange_trade_number`| string | Nomor unik transaksi BEI (misal `"202611050000562305"`). Disimpan murni sebagai string. Gap nomor tidak membuktikan pesan hilang (bisa transaksi di luar subskripsi). |
| `p` | `price_idr` | float | Harga pelaksanaan transaksi per lembar saham. |
| `l` | `volume_lots` | float | Volume transaksi dalam satuan lot (1 lot = 100 lembar). |
| `v` | `transaction_value_idr`| float | Nilai nominal transaksi kotor dalam IDR (misal Rp 675.100). |
| `pc` | `provider_change_pct` | float | Perubahan persentase harga dari acuan provider. |
| `ts` | `provider_ts_raw` | float | Unix epoch float timestamp yang dikirim provider (misal `1793848718.6899142` -> `10:18:38.689 WIB`). **Disimpan mentah terpisah dari `received_at`**. Tidak boleh disebut exchange event time sebelum asal muasal waktu (apakah matching time BEI atau server provider) dibuktikan secara resmi. |
| `c` | `provider_action` | string | Aksi penyedia (`buy` / `sell`). **Bukan aggressor order flow atau broker accumulation pasti**. |
| `board` | `board_scope` | *absent* | **TIDAK ADA PADA PAYLOAD UPSTREAM**. Status tetap: **`BOARD_SCOPE_UNVERIFIED`**. |

### 2.1 Batasan Ring Buffer Deduplikasi `tn` (2.000 Entri)
- Ring buffer `seen_trade_keys` berkapasitas 2.000 item memberi perlindungan deduplikasi transaksi terhadap burst koneksi berulang dan perputaran snapshot.
- **Keterbatasan Terukur**:
  * Jika terjadi replay stream atau lonjakan transaksi bursa lebih dari 2.000 trade di antara interval koneksi, item tertua akan di-evict dari buffer memory.
  * Oleh karena itu, sistem mencatat status dedup sebagai **`PARTIAL_BURST_PROTECTION_ONLY`**, dan **TIDAK MENGKLAIM agregasi sesi bebas duplikasi secara mutlak** tanpa persisted audit ledger transaksi di level database.

---

## 3. Hasil Pengujian Routing & Grounding NLP
1. **Pencarian Scalping Lintas Saham**:
   - Inbound: *"carikan saham scalping, ambil data dari API"*
   - Intent: `SCREENING_INTRADAY` (Ticker: `NULL`).
   - Hasil: Ditangani via `formatIntradayScalpingScreeningResponse()`, memberikan transparansi batas intraday dan daftar pantau EOD Pasar Reguler (RG).
2. **Analisis Ticker Emiten DATA**:
   - Inbound: *"analisis saham DATA"*
   - Intent: `QUERY_TICKER_STATUS` (Ticker: `DATA`).
   - Hasil: Emiten `DATA` (PT Remala Abadi Tbk) di-resolve valid tanpa terblokir stopword.
3. **Perbandingan Dua Saham (DATA vs BBCA)**:
   - Inbound: *"bandingkan DATA dan BBCA"*
   - Intent: `COMMAND_COMPARISON` (Tickers: `["DATA", "BBCA"]`).
   - Hasil: Dua instrumen diidentifikasi presisi.
## 4. Keterbatasan Skema Upstream WebSocket & Formulasi Pertanyaan Provider

### 4.1 Bukti Ketiadaan Field Upstream (Bukan Pemotongan Adapter)
Berdasarkan inspeksi payload mentah langsung dari soket `wss://stock.arjum.com/ws/running-trade` via `scripts/inspect_ws_schema.py` dan parser supervisor `services/idx_edge_ws_supervisor.py`:
- Struktur paket transaksi mentah (`trade`) dari upstream murni hanya berisi: `{"t": "<ticker>", "p": <price>, "l": <lot>, "c": "<buy/sell>"}`.
- Field `board` atau `market_board`: **TIDAK DIKIRIMKAN OLEH UPSTREAM SERVER**.
- Field `time` atau `timestamp`: **TIDAK DIKIRIMKAN OLEH UPSTREAM SERVER** (bernilai `null`).
- Field `id` / `trade_id` / `sequence_id`: **TIDAK DIKIRIMKAN OLEH UPSTREAM SERVER** (bernilai `null`).
- Kesimpulan: **Bukan akibat pemotongan/normalisasi adapter lokal**. Upstream IDX Edge memang tidak menyiarkan atribut pembeda papan dan identitas transaksi.

### 4.2 Daftar Pertanyaan Spesifik untuk Provider (Status: Draf Internal, Menunggu Instruksi)
Berikut daftar pertanyaan klarifikasi teknis yang disiapkan untuk diajukan ke pihak provider IDX Edge:
1. **Cakupan Papan Perdagangan (Board Scope)**:
   - Apakah stream `wss://stock.arjum.com/ws/running-trade` mencakup transaksi Pasar Negosiasi (NG) dan Pasar Tunai (TN), atau murni transaksi Pasar Reguler (RG)?
   - Jika mencakup multi-board, apakah memungkinkan untuk menyertakan field `board` (misal: `"RG"`, `"NG"`, `"TN"`) pada setiap objek transaksi?
2. **Timestamp Bursa Resmi**:
   - Apakah terdapat field waktu transaksi bursa resmi (WIB) yang dapat diaktifkan pada stream transaksi, guna menghindari bias latensi jaringan lokal server (`received_at`)?
3. **Identitas & Sekuens Transaksi**:
   - Apakah upstream memiliki nomor urut transaksi (`seq_id`) atau `trade_id` resmi BEI untuk mendeteksi paket running trade yang hilang atau terduplikasi saat re-koneksi?
4. **Cakupan Universe & Replay**:
   - Apakah stream menyiarkan transaksi seluruh 900+ emiten bursa secara serentak, atau terdapat batasan likuiditas/volume tertentu?
   - Apakah endpoint WebSocket mendukung replay transaksi intraday dari pembukaan sesi jika koneksi klien terputus di tengah sesi?

---

## 5. Status Verifikasi Kontrak & Kemampuan Sistem
- **Kontrak Papan Pasar Reguler (RG) Live**: **`BLOCKED_EXTERNAL_CAPABILITY`**.
  * Alasan: Upstream tidak menyediakan pemisah board (`BOARD_SCOPE_UNVERIFIED`). Sinyal intraday live ditahan `DATA_CAPABILITY_BLOCKED`.
- **Logika Evaluasi Screening Pembukaan**: **`VERIFIED_TEST`**.
  * Diuji via unit test isolated replay `TelegramTradingPlanTest::test_opening_observations_evaluation_logic_isolated_replay`.
  * Membuktikan pemisahan kandidat baru, tetap, batal, dan coverage gap tanpa mengarang feed live.
- **Slot Trading Plan Pra-Pasar 08:30 WIB**: **`VERIFIED_OPERATIONAL`** (Outbox #116, Telegram Msg #613).
- **Insiden Replay 09:15 WIB**: **`RESOLVED_WITH_DISCLOSURE`** (Koreksi disiarkan via Outbox #118, Telegram Msg #615).
   - Inbound: *"Lalu untuk kesimpulan teknikalnya gimana?"*
   - Hasil: Merujuk konteks pengguna terakhir (`BBCA`) dan mengeksekusi evaluasi teknikal & broker secara asinkron.
5. **Koreksi Interpretasi Generik**:
   - Diterapkan pada `AiConversationService.php` dan `HermesConsultationAdapterService.php`:
     - Satu candle bukan bukti tren multi-sesi.
     - Rugi bersih tidak otomatis berarti rugi operasional.
     - Gross average broker bukan cost basis atau level support pasti.
     - Periode broker yang diminta wajib dipisahkan dari periode faktual database.

---

## 4. Bukti Resit Pemulihan Msg 607 (Update ID: 496963044)
- **Status Database**: Percakapan ID #177 diperbarui secara idempoten dengan balasan screening scalping yang benar.
- **Outbox Delivery**: Tidak ada pengiriman ACK ganda; pesan status pengujian telah diselesaikan via worker outbox existing.

---

## 5. Integrasi Laporan Jeda Sesi 1 (12:15 WIB) Berbasis Data Pasar Reguler
- **Layanan Baru**: `App\Services\MiddaySessionReportService`
- **Evaluasi Kelayakan RG**:
  * Sistem memeriksa parameter `session_coverage.board_rg_guaranteed` dan cakupan waktu Sesi 1 (09:00 - 12:00 WIB).
  * Pada pengamatan operasional tanggal 04 November 2026, status ditetapkan **`DATA_NOT_VALIDATED`** karena:
    1. Kolom `board` absen pada payload tick WebSocket (`MIXED_BOARD_UNSEPARABLE`).
    2. Endpoint REST `/api/price` masih dikarantina (`QUARANTINED`).
    3. Stream collector baru aktif pada Sesi 2 (13:44 WIB), sehingga rentang Sesi 1 tidak teramati secara live.
- **Penegakan Judul & Anti-Substitusi Palsu**:
  * Jika RG belum tervalidasi, laporan secara eksplisit berjudul: **`⚠️ LAPORAN SESI 1 BELUM TERSEDIA (12:15 WIB)`**.
  * Dilarang mengganti data sesi berjalan dengan daftar harga EOD kemarin. Data penutupan EOD sesi sebelumnya hanya dicantumkan sebagai **Acuan Pembanding Baseline Historis**.
- **Blocker Teknis Spesifik & Pertanyaan Provider (IDX Edge / Arjum)**:
  * Q1: Apakah WebSocket `wss://stock.arjum.com/ws/running-trade` dapat difilter khusus Pasar Reguler (RG)?
  * Q2: Apakah dapat ditambahkan field `"board": "RG"` pada skema payload transaksi?
  * Q3: Apakah flag `"c"` ("buy"/"sell") merefleksikan market aggressor definitif bursa atau hanya status broker?
  * Q4: Apakah server dapat menyertakan timestamp event resmi bursa (`time`) dan trade identifier unik (`trade_id`)?
- **Agregasi Replay & Generator Sinyal (Teruji Terisolasi)**:
  * Pengujian terisolasi (`tests/Feature/MiddaySessionReportTest.php` - 4 passed, 36 assertions).
  * Menghitung perubahan harga terhadap nominal previous close, omzet transaksi (IDR), dan volume (lot).
  * Menolak label same-time RVOL tanpa baseline intraday setara (dilabeli rasio volume vs full EOD H-1).
  * Strategi `MOMENTUM_RG_BREAKOUT_V1`: memuat decision timestamp (`12:00:00 WIB`), evidence cutoff, kondisi pemicu, dan kondisi pembatalan. Jika tidak ada kandidat yang memenuhi syarat, sistem secara ketat menerbitkan **`BELUM ADA SINYAL`**.
- **Sintesis Gemini / Hermes**:
  * Menghasilkan ringkasan awam 5 poin: Kondisi Sesi 1, Perubahan Kunci, Status Sinyal, Risiko & Pembatalan, serta Data Tertunda.
- **Penerbitan & Outbox**:
  * Menggunakan slot `12:15` dengan `dedup_key` berbasis tanggal dan slot (`MARKET_UPDATE_YYYYMMDD_1215`).
  * Kolom `due_at` tersimpan dalam UTC (`05:15:00 UTC` = `12:15:00 WIB`) dan mencegah siar ulang idempoten.

---

## 6. Batasan & Tindak Lanjut
- Kuotasi `/api/price` (REST) dan live stream WebSocket running trade tetap **dikarantina** dari komputasi candle Pasar Reguler (RG) dan sinyal trading otomatis.
- Tampilan running trade di `/market` menyajikan label peringatan integritas data yang transparan bagi operator.
- Refresh EOD dan pipeline backfill historis satu tahun tetap berjalan di jalur isolasi RG tanpa gangguan runner duplikat.
