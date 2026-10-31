# Kontrak Data, Evidence, dan Jawaban v3

## 1. Source policy

Harga/histori/broker/fundamental operasional hanya IDX Edge sesuai izin endpoint. Yahoo dan Index Alpha historis bukan fallback operasional. Registry Wikipedia adalah kandidat sekunder, bukan bukti listing aktif. Berita/dokumen dari sumber resmi IR/BEI atau media dengan URL dan waktu terverifikasi; kegagalan akses diberi status spesifik tanpa dugaan lisensi.
Kualitas endpoint bersifat kontekstual: history RG pilot valid tidak memvalidasi quote/movers/screener lain. Board RG_VERIFIED_ON_SAMPLE bukan jaminan semua endpoint/semua tanggal. Konflik/quarantined/invalid dikeluarkan dari evidence yang membutuhkan data valid. Simpan rejected raw dengan alasan, jangan rata-rata lintas provider.

## 2. Identitas dan semantik

- instrument_id canonical, aliases ^JKSE/JKSE/IHSG -> satu benchmark jika memang instrumen sama. Index poin, saham IDR/lembar, volume unit eksplisit.
- bar_date/sesi, interval,board,source,adjustment mode,revision adalah bagian identitas seri.
- finite prices>0; low<=open/close<=high; volume>=0 integer bila schema unit shares; no synthetic fill.
- Daily change = close_t - close_previous_expected_session; pct = change/prev*100. Missing exact previous -> N/A dan gap reason, bukan vsopen.
- Vsopen = (close-open)/open*100 dan selalu label terpisah.
- Period return = (end_close/baseline_close-1)*100 dengan dates, adjustment sama dan corporate action consistency. Baseline wajib tidak diganti first available diam-diam.

## 3. Waktu dan periode

Database/log internal memakai UTC yang didefinisikan per tipe kolom. Jangan mengubah tipe timestamp massal sebagai obat bug timezone. Document naive UTC columns dan source zone, tes roundtrip PostgreSQL dan midnight crossover. Timestamp eksternal ber-offset diparse sebagai instant lalu format WIB sekali. Naive unknown -> ZONE_UNKNOWN, tidak ditebak.
Pisahkan event_time,bar_date,provider_published_at nullable,first_seen_at,fetched_at,validated_at/available_at,analysis_information_cutoff,analysis_generated_at,sent_at. Jangan membuat published_at dari fetched_at. Tanggal saja tidak membuktikan available pada jam tertentu.
Freshness,validity,finality dan market state adalah dimensi terpisah. Pukul16:05 probe availability, pukul20 broker OBSERVED_SNAPSHOT/FINALITY_UNATTESTED_BY_PROVIDER bila tidak ada kontrak finalitas. Data terlambat setelah cutoff tidak boleh ikut point-in-time backtest.

Requested period dapat rolling month atau bulan kalender spesifik: frasa ambigu dijelaskan/ditanyakan. Untuk permintaan Oktober: broker aggregation 1 Oktober sampai sesi tersedia terakhir<=31 Oktober. Close30September boleh dipakai baseline return; transaksi30September tidak otomatis termasuk Oktober. Simpan requested_period, actual_data_period dan baseline_date terpisah; bila provider tidak menghormati filter, status PERIOD_MISMATCH dan jangan tampilkan sebagai kecocokan penuh.

## 4. Broker

Buy avg=buy_value/buy_volume jika denominator>0, sell avg=sell_value/sell_volume. Net=value_buy-value_sell dan net_volume=buy_volume-sell_volume. Net/value ratio bukan basis posisi tersisa. Null bukan nol. Tanpa jual hanya jika field zero eksplisit dan dalam cakupan yang diketahui.
Top-N adalah subset; buyer+seller sum zero hanya untuk market set lengkap dengan definisi sama, tidak dipaksakan pada subset. Broker intermediasi banyak akun; tidak mengungkap pemilik manfaat/bandar.
Monthly net buy + last-session net buy -> NET_BUY_ON_LAST_SESSION. Absent Top-N -> NOT_OBSERVED_IN_TOP_N. No last session evidence -> PERSISTENCE_NOT_EVALUATED. Net sell last session dapat dilabel NET_SELL_ON_LAST_SESSION, bukan klaim tren berbalik. Continuous pattern memerlukan seri subperiode dan definisi terpisah.
Rasio custom = sum positive net buy Top-3 / sum gross buy observed Top-20 *100. Jelaskan denominator dan sample coverage; denominator0/null -> N/A. Bukan ownership share atau total market inflow.

## 5. Fundamental

Simpan fiscal start/end, annual/interim/TTM, audited status, statement currency dan scale, consolidated vs parent, published/available dates, document hash/page. Revenue bank tidak disamakan sales nonbank; DER pengecualian bank mengikuti definisi metric.
PER membutuhkan EPS basis periode/mata uang sesuai harga; basic EPS vs shares period-end berbeda. BVPS gunakan equity parent dan ordinary shares sesuai date. Total liabilities/equity berbeda dari interest-bearing debt/equity. Unknown FX conversion -> UNKNOWN_PROVIDER_CONVERSION dengan rate/date nullable; jangan basis valuasi utama. Document verified hanya field/periode yang benar-benar dicocokkan, bukan seluruh respons provider.

## 6. Tool envelope (kontrak target)

```json
{
  "schema_version": "aether.tool.v3",
  "tool_execution_id": "opaque-id",
  "status": "PARTIAL",
  "instrument_ids": ["SMRA"],
  "requested_period": {"start": "2026-10-01", "end": "2026-10-31"},
  "actual_data_period": {"start": "2026-10-01", "end": "2026-10-30"},
  "baseline_date": "2026-09-30",
  "source": "IDX Edge",
  "board": "RG",
  "board_verification": "VERIFIED_ON_SAMPLE",
  "retrieval_mode": "LOCAL_CACHE",
  "http_request_count": 0,
  "quota_units": 0,
  "cache": {"created_at": "2026-10-31T02:00:00Z", "expires_at": "2026-10-31T02:05:00Z"},
  "evidence_ids": ["example-only"],
  "coverage": {"top_n": 20, "complete_universe": false},
  "limitations": ["Top-N teramati"],
  "data": {}
}
```

Contoh schema, bukan record aktual atau receipt. Production enum/types mengikuti schema terversi. Tool memilih HTTP_NEW, LOCAL_CACHE, PERSISTENT_ARCHIVE secara jujur; external request berbiaya0 tetap http count1. Cache age dihitung dari origin, bukan evidence baru. Cache TTL tidak sama dengan data valid historis; stale archive boleh dipakai eksplisit untuk pertanyaan historis, tidak disamarkan fresh.

## 7. Agent result target

```json
{
  "schema_version": "aether.analysis.v3",
  "job_id": "example-job",
  "status": "PARTIAL",
  "request_kind": "BROKER_SCREENING",
  "answer": "Jawaban ringkas berdasarkan evidence yang tersedia.",
  "evidence_ids": ["example-only"],
  "requested_period": {"start": "2026-10-01", "end": "2026-10-31"},
  "actual_data_period": {"start": "2026-10-01", "end": "2026-10-30"},
  "limitations": ["Cakupan pilot terbatas"],
  "recommendations": [],
  "ml_inference_id": null
}
```

Result COMPLETE tidak sama delivery SENT atau strategi validated. Laravel memeriksa references evidence milik job/user/cutoff, finite values dan kontrak strategi sebelum publish. Unknown source, malformed JSON, reference palsu -> validasi gagal/repair terbatas dalam deadline, bukan publish raw. Jangan menyertakan arbitrary LLM confidence harga.

## 8. Revisi

Raw immutable + revision archive sebelum upsert; unique identity tidak menghapus perbedaan source/period/board. Evidence merujuk versi tepat. Sent payload dan receipts immutable. Erratum baru menunjuk output lama dan alasan. Arsip bukan diubah untuk menyamai narasi terbaru.
