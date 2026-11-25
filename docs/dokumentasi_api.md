# Dokumentasi API IDX Edge PRO untuk Aether Market Intelligence

> **Versi:** 1.0 · **Tanggal:** 16 November 2026 · **Zona waktu pasar:** Asia/Jakarta (WIB)  
> **Referensi provider:** https://stock.arjum.com/api-docs  
> **Tujuan:** Kontrak integrasi data pasar, screener, emiten, dan streaming untuk Aether (Laravel, Python collector, PostgreSQL, quant-engine, Telegram).  
- **Status:** Dokumentasi integrasi berdasarkan kontrak provider terbaru dan authenticated/live acceptance terpisah. Dukungan `interval=60` terdokumentasi untuk saham dan IHSG; runtime acceptance tetap wajib membuktikan response aktual per endpoint.

## 1. Prinsip integrasi Aether

IDX Edge PRO digunakan sebagai **sumber data eksternal**, bukan sebagai engine sinyal trading. Semua data harus melewati `ingest -> raw evidence -> schema validation -> freshness check -> normalization -> feature builder -> inference/evaluation -> dashboard/Telegram`. Respons HTTP 200 tidak otomatis berarti datanya segar, lengkap, maupun cocok untuk inferensi.

**Penting:** Semua sumber data yang dibahas dalam permintaan ada pada daftar API ini, tetapi *ketersediaan endpoint tidak membuktikan kelengkapan historis, cakupan seluruh emiten, jaminan latensi, SLA, kualitas data, maupun hak redistribusi*. Uji pada akun dan paket aktual sebelum mengganti seluruh collector lama.

### Daftar endpoint

| Kategori | Method | Endpoint | Fungsi untuk Aether | Status spesifikasi |
|---|---|---|---|---|
| Pasar | GET | `/api/price/IHSG` | Snapshot IHSG | URL diberikan pengguna |
| Pasar | GET | `/api/chart/{code}?interval=60&limit=2000` | OHLCV candlestick intraday | `interval` dalam detik; `60` didukung saham dan IHSG; authenticated runtime tetap wajib |
| Pasar | GET | `/api/history/{code}?frame=daily&adjusted=true&limit=500&start_date=YYYY-MM-DD&end_date=YYYY-MM-DD` | OHLCV harian | Rentang kalender eksplisit; `data_available` dan tanggal listing wajib dihormati |
| Screener | GET | `/api/screener/latest` | Screener terkini | Nama endpoint tersedia; skema belum diverifikasi |
| Screener | GET | `/api/movers` | Top gain/lose, value, volume, frekuensi | Nama endpoint tersedia; query/skema belum diverifikasi |
| Detail emiten | GET | `/api/financial-statements/{code}` | Laba rugi, neraca, arus kas | Parameter dan respons parsial diberikan pengguna |
| Detail emiten | GET | `/api/insiders/{code}` | Transaksi insider | Nama endpoint tersedia; skema belum diverifikasi |
| Detail emiten | GET | `/api/broker-accumulation/{code}` | Akumulasi broker historis | Nama endpoint tersedia; skema belum diverifikasi |
| Streaming | WSS | `/ws/price` | Harga dan perubahan persentase | Spesifikasi beta diberikan pengguna |
| Streaming | WSS | `/ws/running-trade` | Matched trade dan Top 5 aktif | Spesifikasi beta diberikan pengguna |

## 2. Base URL, autentikasi dan keamanan

```text
REST_BASE_URL=https://stock.arjum.com
WSS_PRICE_URL=wss://stock.arjum.com/ws/price
WSS_RUNNING_TRADE_URL=wss://stock.arjum.com/ws/running-trade
IDX_EDGE_API_KEY=<isi melalui secret manager atau environment>
```

REST menggunakan header `X-API-Key` dan `Accept: application/json`. WebSocket memerlukan header `X-API-Key` saat handshake; **WebSocket browser standar tidak mendukung penambahan header kustom** sehingga akses sebaiknya dari Python/Node backend, bukan frontend Laravel atau JavaScript browser langsung. Menurut informasi pengguna, kedua fitur WebSocket adalah **beta khusus paid user** dan kontraknya bisa berubah.

```bash
curl -sS 'https://stock.arjum.com/api/price/IHSG' \
  -H "X-API-Key: $IDX_EDGE_API_KEY" \
  -H 'Accept: application/json'
```

- Jangan hardcode API key pada source, file Markdown, repo, screenshot, URL, Telegram atau log. Gunakan `.env` yang tidak masuk version control atau secret manager.
- **Rotasi API key yang sempat dibagikan pada percakapan** sebelum penggunaan produksi.
- Gunakan satu worker terkoordinasi untuk masing-masing stream; khusus `/ws/price`, limit **maksimal 1 koneksi price per akun** dinyatakan eksplisit.
- Patuhi quota dan entitlement paket yang benar. Jangan mengasumsikan quota tetap atau semua fitur tersedia tanpa validasi akun.

## 3. REST API — data pasar

### 3.1 Harga IHSG terkini

```http
GET https://stock.arjum.com/api/price/IHSG
X-API-Key: <secret>
Accept: application/json
```

**Tujuan:** Snapshot indeks IHSG untuk gambaran pasar, perbandingan pergerakan indeks, konteks sinyal, dan dashboard. Struktur respons harga (nama field, timestamp, status, perubahan, volume) **belum diberikan**; petakan setelah mengambil sampel asli. Jangan menamai respons REST sebagai tick real-time tanpa membuktikan timestamp data dan frekuensi pembaruannya.

### 3.2 Chart OHLC intraday

```http
GET https://stock.arjum.com/api/chart/BMRI?interval=60&limit=2000
GET https://stock.arjum.com/api/chart/IHSG?interval=60&limit=2000
```

`interval` memakai DETIK. `interval=60` didukung saham dan IHSG. Respons memakai `bars=[[timestamp,open,high,low,close,volume]]`; `date` adalah tanggal sesi provider. Epoch diparse sekali lalu diformat ke `Asia/Jakarta`; jangan menambah offset kedua. Menit tanpa data tetap kosong dan tidak diinterpolasi.

Runtime acceptance tetap terpisah dari dukungan dokumentasi. Acceptance harus membuktikan HTTP authenticated, schema, sesi, urutan timestamp, duplikasi, dan status market.

Validasi tiap candle: `high >= max(open,close)`, `low <= min(open,close)`, `open/close > 0`, `volume >= 0`; urutan timestamp dan duplikasi ditolak. Jangan membuat candle hilang.

### 3.3 History harian

```http
GET https://stock.arjum.com/api/history/BBCA?frame=daily&adjusted=true&limit=500&start_date=2025-01-01&end_date=2026-01-01
```

Rentang 1M, 3M, 6M, dan 1Y memakai tanggal kalender eksplisit. `limit` maksimal 500. Periksa `data_available`, tanggal listing, serta cakupan tanggal respons sebelum menandai rentang lengkap. Raw dan adjusted tetap seri terpisah.

## 4. REST API — Screener

### 4.1 Screener Saham Terkini

```http
GET https://stock.arjum.com/api/screener/latest
```

**Pemakaian Aether:** halaman Screener, daftar kandidat riset dan snapshot lintas emiten. Jangan menganggap peringkat screener sebagai rekomendasi beli/jual atau skor ML Aether.

- **Path parameter:** tidak ada.
- **Query parameter dan struktur respons:** **PERLU VERIFIKASI** terhadap contoh JSON asli.
- **Field yang perlu diperiksa:** kode emiten, kategori/strategi screener, nilai indikator, skor, ranking, timestamp, as-of date, pagination, field null. Ini adalah daftar pemeriksaan, **bukan klaim field tersedia**.

### 4.2 Peringkat Harian — Movers

```http
GET https://stock.arjum.com/api/movers
```

**Pemakaian Aether:** peringkat gainers, losers, nilai transaksi, volume dan frekuensi dalam dashboard/pencarian kandidat. **Nama parameter kategori dan bentuk respons belum dikonfirmasi**, jangan otomatis mengirim `type`, `sort`, `limit` atau parameter lainnya.

**Validasi:** satuan nilai transaksi (Rp), volume (lot/lembar), frekuensi (jumlah trade), persentase, scope papan/market dan tanggal sesi. Peringkat pada hari libur harus diberi label tanggal perdagangan terakhir, bukan "hari ini".

## 5. REST API — tab Detail Emiten

### 5.1 Laporan Keuangan

```http
GET /api/financial-statements/{code}
```

Mengambil data laba rugi, neraca atau arus kas untuk emiten secara kuartalan/tahunan.

| Parameter | Lokasi | Wajib | Nilai / default |
|---|---|---|---|
| `code` | path | Ya | Kode saham, contoh `BBCA` / `BMRI` |
| `report_type` | query | Tidak | `INCOME_STATEMENT` (default), `BALANCE_SHEET`, `CASH_FLOW_REPORT` |
| `period` | query | Tidak | `quarterly` (default), `annually` |
| `limit` | query | Tidak | integer default `12`, maksimum `40` |
| `year` | query | Tidak | String filter tahun spesifik |

Contoh:

```bash
curl -sS -G 'https://stock.arjum.com/api/financial-statements/BMRI' \
  -H "X-API-Key: $IDX_EDGE_API_KEY" \
  -H 'Accept: application/json' \
  --data-urlencode 'report_type=INCOME_STATEMENT' \
  --data-urlencode 'period=quarterly' \
  --data-urlencode 'limit=12'
```

**Contoh respons parsial berdasarkan materi pengguna, disederhanakan (bukan hasil request terbaru):**

```json
{
  "stock_code": "BBCA",
  "report_type": "INCOME_STATEMENT",
  "period": "quarterly",
  "count": 1,
  "items": [
    {
      "year": "2026",
      "quarter": "1",
      "label": "Q1 2026",
      "fetched_at": "2026-09-13 18:06:34",
      "data": {
        "pendapatan_dan_beban_operasional": {
          "pendapatan_bunga": 24592248000000,
          "beban_bunga": -7829473000000
        }
      }
    }
  ]
}
```

`count` di atas disesuaikan untuk contoh **satu item**; respons lengkap sebenarnya dapat memiliki lebih banyak akun/blok data dan periode. Field dalam `data` **tidak seragam untuk setiap jenis laporan atau emiten**. Hindari asumsi struktur tetap. Simpan `fetched_at`, tahun fiskal, kuartal, tanggal publikasi yang tervalidasi, dan kapan informasi benar-benar dapat diakses, agar tidak menimbulkan *look-ahead bias*.

### 5.2 Transaksi Insider

```http
GET https://stock.arjum.com/api/insiders/BBCA
```

**Pemakaian Aether:** tab Insider dan event timeline terkait transaksi orang dalam. `code` adalah path parameter wajib. Query filter, skema respons, ketersediaan sejarah, delay pengumuman **PERLU VERIFIKASI**.

Field yang **harus dicari dalam respons sebenarnya** (bukan field yang telah dikonfirmasi): identitas/posisi pelapor, tipe transaksi, jumlah, harga, waktu transaksi, waktu pengumuman, kepemilikan sebelum/sesudah dan koreksi. Jangan inferensi motif insider dari transaksi tunggal.

### 5.3 Akumulasi Broker Historis

```http
GET https://stock.arjum.com/api/broker-accumulation/BBCA
```

**Pemakaian Aether:** tab Broker Historis, analisis akumulasi/distribusi serta fitur broker-flow. `code` adalah path parameter wajib; query periode/tanggal dan struktur JSON **PERLU VERIFIKASI**. Jangan menyamakan endpoint ini dengan broker summary atau dengan order flow individu.

Validasi: definisi net buy/net sell, satuan lot/lembar/Rp, cakupan pasar, kode broker, timestamp/sesi, dan apakah data agregat termasuk revisi histori. Label indikasi akumulasi sebagai analisis **berdasarkan data broker**, bukan kepastian pembelian investor tertentu.

## 6. WebSocket — Price stream

**Alamat:** `wss://stock.arjum.com/ws/price`  
**Status:** beta, paid user, autentikasi handshake via `X-API-Key`.  
**Batas:** maksimal **10 kode per koneksi termasuk `IHSG`**, maksimal **1 koneksi price per akun**. Pembaruan diklaim **250 ms**; itu adalah karakteristik flush/update yang diberikan, **bukan bukti end-to-end latency tetap**.

### Protokol koneksi

1. Connect dari backend dengan API key.
2. Terima pesan `hello` (informasi `max_codes`, `flush_ms`).
3. **Dalam 15 detik** kirim subscribe.
4. Terima `subscribed`, lalu pesan `price` snapshot awal (`snapshot: true`).
5. Konsumsi delta `price` selama koneksi aktif; pada jeda perdagangan, data dapat tetap berupa snapshot terakhir.

```json
{"type":"subscribe","codes":["BBCA","BBRI","IHSG"]}
```

**Bentuk ringkas data harga yang diberikan dalam contoh pengguna:**

```json
{"type":"price","d":{"BBCA":[4920,1.65]}}
```

Dalam contoh ini `d[code] = [harga_terakhir, perubahan_persen]`. Bentuk lengkap `hello`, `subscribed`, atribut `snapshot` dan metadata waktu harus diinspeksi dari payload asli.

### Contoh Python (websockets sync API)

```python
import os
import json
from websockets.sync.client import connect

headers = {"X-API-Key": os.environ["IDX_EDGE_API_KEY"]}
with connect("wss://stock.arjum.com/ws/price", additional_headers=headers) as ws:
    print(json.loads(ws.recv()))  # hello
    ws.send(json.dumps({"type": "subscribe", "codes": ["BBCA", "BBRI", "IHSG"]}))
    print(json.loads(ws.recv()))  # subscribed (periksa type asli)
    for raw in ws:
        msg = json.loads(raw)
        if msg.get("type") == "price":
            print(msg.get("d"), msg.get("snapshot"))
        elif msg.get("type") == "error":
            print("Subscription error:", msg.get("message"))
```

## 7. WebSocket — Running trade dan Top 5 active

**Alamat:** `wss://stock.arjum.com/ws/running-trade`  
**Status:** beta, paid user, autentikasi melalui `X-API-Key`. Stream berisi trade matched, Top 5, snapshot serta respons filter. Tidak ada parameter query wajib yang disebutkan dalam materi pengguna.

### 7.1 Pesan `snapshot` — dikirim otomatis saat connect

```json
{
  "type": "snapshot",
  "market": {
    "status": "open",
    "label": "Sesi 1 Berlangsung",
    "session": 1,
    "session_end": "12:00"
  },
  "top": [
    {"ticker":"BBCA","metric":1420,"count":1420,"last_price":10250,"last_change_pct":1.48}
  ],
  "recent": [
    {"t":"BBCA","c":"buy","a":"09:15:32","p":10250,"l":50,"v":51250000,"pc":1.48,"tn":"1048293"}
  ]
}
```

`market.status` contoh nilai: `open`, `break`, `closed`, `weekend`. Jika pasar tutup atau istirahat, menurut dokumentasi pengguna `top` dan `recent` kosong pada snapshot.

### 7.2 Pesan `trade` — tiap match yang dikirim provider

```json
{
  "type": "trade",
  "data": {
    "t": "BBCA",
    "c": "buy",
    "a": "09:15:33",
    "p": 10250,
    "l": 100,
    "v": 102500000,
    "pc": 1.48,
    "tn": "1048294"
  }
}
```

| Field | Tipe | Arti |
|---|---|---|
| `t` | string | ticker |
| `c` | string | label `buy` atau `sell` yang diberikan provider |
| `a` | string | waktu matched trade `HH:MM:SS` (WIB menurut jadwal provider; konfirmasi timezone payload) |
| `p` | number | harga/lembar (Rp) |
| `l` | number | volume (lot); **1 lot = 100 lembar** |
| `v` | number | nilai transaksi (Rp) |
| `pc` | number | persentase perubahan harga |
| `tn` | string | nomor transaksi bursa |

Jangan otomatis menyamakan `c=buy/sell` dengan identitas/niat satu pihak; verifikasi definisi klasifikasi aggressor yang dipakai provider. Gunakan `tn` bersama tanggal/sumber sebagai kandidat deduplication key, bukan asumsi bahwa nilainya unik selamanya.

### 7.3 Pesan `top5` — sekitar setiap 5 detik

```json
{
  "type": "top5",
  "top": [
    {"ticker":"BBRI","metric":1820,"count":1820,"last_price":5400,"last_change_pct":-0.46}
  ],
  "boards": {
    "freq": [],
    "lot": [],
    "value": [],
    "net_buy": [],
    "net_sell": []
  },
  "window_seconds": 300
}
```

Array `boards` di atas sengaja kosong sebagai **contoh bentuk, bukan snapshot riil**. Setiap board umumnya memiliki item `{ticker, metric, count, last_price, last_change_pct}`. `top` sama dengan `boards.freq` demi kompatibilitas.

| Board | Makna / urutan |
|---|---|
| `freq` | frekuensi/jumlah trade, terbesar dahulu |
| `lot` | total lot, terbesar dahulu |
| `value` | total rupiah transaksi, terbesar dahulu |
| `net_buy` | net rupiah positif, terbesar dahulu |
| `net_sell` | net rupiah negatif, terkecil dahulu |

`window_seconds` menunjukkan usia transaksi tertua yang masih ada di buffer, **tidak selalu 300**; saat aktif bisa lebih kecil. Board bukan agregat satu hari penuh kecuali provider menyatakan demikian.

### 7.4 Filter per koneksi

Client **mengirim pesan di socket yang sudah terbuka**, bukan sebagai query URL. Opsional; tanpa filter semua trade dikirim. Maksimal **1 pesan filter per detik per koneksi**; yang lebih cepat dapat diabaikan. Filter berlaku pada frame trade; jangan berasumsi filter juga membatasi board Top 5.

```json
{
  "type": "filter",
  "action": "buy",
  "codes": ["BBCA", "BBRI"],
  "min_lot": 1000,
  "min_value": 100000000
}
```

| Field | Nilai | Aturan |
|---|---|---|
| `action` | `buy`, `sell`, `null` | `null` = semua |
| `codes` | array, `null` | Maksimal 50 kode, 4 karakter per kode; `null` = semua |
| `min_lot` | angka >= 0, `null` | Minimal lot, `null` = tak dibatasi |
| `min_value` | angka >= 0, `null` | Minimal Rupiah, `null` = tak dibatasi |

Respons sukses:

```json
{
  "type": "filter_applied",
  "filter": {"action":"buy","codes":["BBCA"],"min_lot":1000,"min_value":null},
  "recent": [],
  "matched": 37
}
```

`recent` adalah **maksimal 50 trade cocok terakhir** untuk **mengganti isi tabel** UI, bukan ditambahkan lagi ke histori lama. `matched` = jumlah transaksi cocok dalam buffer. Saat tutup, `recent=[]`, `matched=0`.

Contoh respons gagal:

```json
{"type":"filter_error","message":"action invalid: 'hold', harus 'buy', 'sell', atau None"}
```

Untuk reset: `{"type":"filter"}`. Pesan filter invalid tidak memutus koneksi.

### 7.5 Jam bursa dan rollover harian

Provider mengirim stream dari satu koneksi backend:

```text
WSS: wss://stock.arjum.com/ws/running-trade
Hari: Senin-Jumat
Sesi 1: 09:00-12:00 WIB (Jumat sampai 11:30 WIB)
Istirahat: 12:00-13:30 WIB (Jumat 11:30-13:30 WIB)
Sesi 2: 13:30-15:50 WIB
Pre-closing: sampai 16:00 WIB
```

Di luar jam aktif, koneksi boleh tetap terbuka. Tidak adanya frame `trade` atau `top5` bukan otomatis error. Baca `snapshot.market.status`: `open`, `break`, `closed`, atau `weekend`.

Collector Aether memakai timezone `Asia/Jakarta` untuk `session_date`, rollover, heartbeat, dan tampilan timestamp. Collector tidak memakai timezone OS untuk tanggal sesi. Pada tanggal WIB baru, collector membuat counter dan agregat sesi baru; state sesi sebelumnya masuk `previous_session_archive` dan tidak dihitung ulang.

Invarian harian:

```text
session_date = tanggal Asia/Jakarta saat collector mulai
session_aggregates = sesi aktif saja
recent_trades = snapshot/recent sesi aktif saja
previous_session_archive = baca-saja untuk histori
```

### 7.6 Autentikasi, close code, dan reconnect

Handshake wajib memakai header `X-API-Key` dari environment atau secret manager. Jangan tulis key ke source, log, dokumentasi, Telegram, atau output terminal.

| Close code | Status | Tindakan |
|---|---|---|
| `4401` | API key invalid, expired, banned, atau inactive | Stop retry otomatis; periksa credential melalui secret manager |
| `4403` | Paid user required | Tandai entitlement blocked; jangan membuka koneksi kedua |
| `4408` | Connection superseded/takeover | Audit owner dan proses lain; jangan reconnect cepat sebelum owner tunggal terbukti |

Untuk close sementara lain, gunakan exponential backoff `2s, 5s, 10s`, maksimum `30s`. Lock OS mencegah owner kedua. Reconnect bukan bukti data gap sudah pulih.

### 7.7 Contoh Python running trade

```python
import os
import json
from websockets.sync.client import connect

headers = {"X-API-Key": os.environ["IDX_EDGE_API_KEY"]}
with connect("wss://stock.arjum.com/ws/running-trade", additional_headers=headers) as ws:
    snapshot = json.loads(ws.recv())
    print("market:", snapshot.get("market"))
    ws.send(json.dumps({"type": "filter", "codes": ["BBCA", "BBRI"]}))
    for raw in ws:
        msg = json.loads(raw)
        if msg.get("type") == "trade":
            print("trade:", msg.get("data"))
        elif msg.get("type") == "top5":
            print("boards:", msg.get("boards"))
        elif msg.get("type") in ("filter_applied", "filter_error"):
            print("filter:", msg)
```

## 8. Jam bursa, freshness, reconnect dan error

Jam yang disebut dalam spesifikasi provider yang diberikan pengguna:

| Kondisi | Senin–Kamis (WIB) | Jumat (WIB) |
|---|---|---|
| Sesi 1 | 09:00–12:00 | 09:00–11:30 |
| Istirahat | 12:00–13:30 | 11:30–13:30 |
| Sesi 2 / aliran aktif | 13:30–15:50 | 13:30–15:50 |

Sumber juga menyebut **pre-closing hingga 16:00**. Jangan mengasumsikan seluruh mekanisme perdagangan pre-closing menghasilkan stream match seperti sesi reguler. Jadwal bisa berubah karena libur bursa, penyesuaian BEI, dan perubahan vendor; validasi kalender BEI sebelum menyimpulkan stream terputus bermasalah.

| WebSocket close code | Makna menurut dokumentasi pengguna | Tindakan |
|---|---|---|
| `4401` | API key invalid, nonaktif, banned atau kedaluwarsa | Hentikan retry otomatis; periksa kredensial |
| `4403` | Akun bukan paid user | Hentikan retry; periksa paket/izin |
| `4408` | Koneksi diambil alih worker lain | Periksa duplikasi proses; **jangan reconnect agresif** |
| `1008` | Pada price socket: tidak subscribe dalam 15 detik | Perbaiki urutan handshake/subscription |

Strategi reconnect untuk kehilangan koneksi/transient failures: exponential backoff dengan jitter (contoh 2, 5, 10, maksimum 30 detik), heartbeat, dan circuit breaker. **Jangan** ulangi loop reconnect untuk 4401/4403/4408 tanpa mengatasi penyebabnya.

Untuk REST, tangani timeout, JSON invalid, response kosong, serta status 401/403/429/5xx sesuai perilaku nyata provider. Header kuota seperti `X-RateLimit-Remaining` hanya dipakai **jika memang dikirim**, bukan dianggap wajib.

## 9. Pemetaan ke modul Aether

| Komponen Aether | Data provider | Tindakan |
|---|---|---|
| `services/` Python REST collector | IHSG, chart, screener, movers, fundamentals, insiders, broker history | Polling terjadwal, retry terbatas, simpan raw JSON |
| `services/` Python WS collector | price, running-trade, top5 | Single owner/worker, parsing streaming, monitoring feed |
| PostgreSQL evidence/observation | Semua endpoint | Append-only/raw evidence; event timestamp, `retrieved_at`, hash, as-of, status |
| `quant-engine/` | OHLCV, harga, broker flow, fundamentals, events | Normalisasi, indikator, feature engineering, backtest tanpa look-ahead |
| Laravel dashboard | screener/movers/detail emiten | Tampilkan status freshness dan provenance sumber |
| Telegram | hasil analisis yang sudah lolos gate | Kirim timestamp/risiko, jangan menyebutnya real-time jika data stale |

### Kontrak penyimpanan yang disarankan (internal Aether, bukan JSON provider)

```json
{
  "provider": "idx_edge_pro",
  "endpoint": "/api/movers",
  "ticker": null,
  "retrieved_at_utc": "<ISO-8601>",
  "provider_as_of": null,
  "http_status": 200,
  "schema_version": "unverified-v1",
  "freshness": "unknown",
  "payload_sha256": "<sha256>",
  "raw_payload": {}
}
```

Skema ini merupakan **usulan kontrak internal**, bukan format respons IDX Edge PRO. Jangan berikan contoh waktu fiktif sebagai waktu pengamatan aktual. Pada stream, simpan `received_at`, `event_time` bila disediakan, `market_session_date`, `connection_id`, dan `sequence/tn` untuk observabilitas serta deduplikasi.

### Penjadwalan yang disarankan

- **Intraday:** satu collector stream harga + trade; gunakan update delta untuk cache dashboard, bukan membuat request REST pada setiap tick.
- **REST screener/movers:** polling terkendali sesuai quota, kebutuhan tampilan, dan timestamp aktual.
- **Fundamental/insider/broker historical:** refresh lebih jarang dan berbasis ketersediaan data; tidak perlu dipanggil setiap 250 ms.
- **Setelah market close:** rekonsiliasi intraday vs data EOD, catat revision dan jalankan evaluator berdasarkan tanggal horizon yang tepat.
- **Di luar jam bursa:** koneksi bisa tetap aktif namun tidak ada delta; status tampil `MARKET_CLOSED`/`MARKET_BREAK`, bukan `FEED_ERROR` jika koneksi sehat.

### Quality gate sebelum sinyal

1. Timestamp provider dan waktu penerimaan tersedia atau dapat dinyatakan eksplisit `unknown`.
2. Semua payload lolos validasi tipe/angka; tidak ada perubahan schema tak tertangani.
3. Data cukup untuk window indikator/feature; missing bar atau recent trade tidak disamarkan.
4. Data fundamental dan insider memakai **availability date**, bukan hanya quarter/event date.
5. Latensi dan staleness berada di bawah ambang yang *ditetapkan Aether setelah pengukuran*, bukan ambang yang dikarang dari klaim 250 ms.
6. Model/backtest/walk-forward telah dievaluasi dan status inferensi aktif hanya jika benar-benar diaktifkan; jika belum, keluarkan `NO_SIGNAL` / `ABSTAIN`.

## 10. Skrip audit respons REST sesungguhnya

Jalankan di server/laptop tempat secret tersedia. Skrip hanya mencetak status dan **nama top-level field**, tidak mencetak key maupun seluruh payload yang mungkin besar/sensitif. Diperlukan `pip install requests`.

```python
import os
import requests

BASE = "https://stock.arjum.com"
PATHS = [
    "/api/price/IHSG",
    "/api/chart/BMRI?interval=60&limit=2000",
    "/api/screener/latest",
    "/api/movers",
    "/api/financial-statements/BMRI?report_type=INCOME_STATEMENT&period=quarterly&limit=12",
    "/api/insiders/BBCA",
    "/api/broker-accumulation/BBCA",
]

session = requests.Session()
session.headers.update({
    "X-API-Key": os.environ["IDX_EDGE_API_KEY"],
    "Accept": "application/json",
})

for path in PATHS:
    try:
        response = session.get(BASE + path, timeout=20)
        print(path, "HTTP", response.status_code)
        response.raise_for_status()
        payload = response.json()
        print(" shape:", type(payload).__name__)
        if isinstance(payload, dict):
            print(" keys:", list(payload.keys())[:50])
        elif isinstance(payload, list):
            print(" length:", len(payload))
    except (requests.RequestException, ValueError) as error:
        print(" request/JSON failed:", type(error).__name__)
```

**Langkah berikutnya untuk Hermes:** inspeksi JSON dari tiap endpoint, catat schema nyata dan contoh respons sanitasi dalam fixture pengujian, kemudian buat parser bertipe dan test regresi untuk `null`, empty, missing, angka besar, timestamp, 429, serta schema drift. Implementasi parser **jangan dibuat dari contoh yang belum terverifikasi**.

## 11. Checklist integrasi sebelum dianggap siap produksi

- [ ] Key dirotasi, disimpan sebagai secret dan tidak bocor di log.
- [ ] Semua **7 REST endpoint** diuji dengan API key akun yang punya akses.
- [ ] Dua WebSocket diverifikasi saat sesi perdagangan serta saat break/tutup.
- [ ] Query/response riil screener, movers, insider, broker-accumulation sudah didokumentasikan.
- [ ] Interval candle dan unit volume diuji terhadap contoh respons.
- [ ] Tidak ada dua worker saling merebut price socket (`4408`).
- [ ] `tn`, date, retry dan idempotency diuji pada replay/reconnect.
- [ ] Freshness, timestamp, penyimpanan evidence dan status provisional tervalidasi.
- [ ] Kuota, paket, ketentuan penggunaan data dan izin redistribusi dikonfirmasi.
- [ ] Dashboard dan Telegram membedakan feed aktif, snapshot stale, pasar tutup, error dan sinyal research-only.
- [ ] Inference gate tidak mengirim sinyal aktif hanya karena WebSocket terhubung.

## 12. Provenance dan keterbatasan

- Halaman referensi: https://stock.arjum.com/api-docs (diakses 16 November 2026, halaman interaktif tidak mengekspos seluruh rincian JSON dalam alat pembaca teks).
- Rincian parameter laporan keuangan, contoh JSON, format streaming, jadwal, close code dan filter berasal dari **materi dokumentasi yang diberikan pengguna**, bukan dari uji jaringan terautentikasi.
- URL screener, movers, insiders dan broker-accumulation berasal dari daftar endpoint yang disampaikan pengguna. Parameter tambahan dan struktur respons endpoint tersebut **belum terverifikasi**.
- Jika dokumentasi provider berubah, **kontrak resmi yang terverifikasi pada saat integrasi** lebih tinggi prioritasnya daripada file ini. Karena WebSocket beta, sediakan schema-version checks dan fallback.

---

**Penempatan proyek yang disarankan:** `E:\\Aether-Market-Intelligence\\docs\\dokumentasi_api.md` (buat folder `docs/` bila belum ada). Nama file yang diminta: `dokumentasi_api.md`.
