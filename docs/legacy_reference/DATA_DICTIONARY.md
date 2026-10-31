# KAMUS DATA & SPESIFIKASI PROVENANCE
## AETHER MARKET INTELLIGENCE

Dokumen ini mendefinisikan skema, tipe data, satuan, dan metadata provenance untuk seluruh entitas data pasar, makroekonomi, berita, serta evaluasi pada sistem Aether Market Intelligence.

---

## 1. Skema Data Pasar (Market Snapshot EOD)

Entitas ini merekam candle penutupan harian dan indikator teknikal non-lookahead untuk setiap saham universe.

| Nama Atribut | Tipe Data | Nullable | Satuan / Format | Definisi & Aturan Integritas |
| :--- | :--- | :---: | :--- | :--- |
| `ticker` | `VARCHAR(10)` | Tidak | Kode Saham (e.g. `BBCA`) | Simbol emiten terdaftar di Bursa Efek Indonesia (tanpa akhiran `.JK` di DB). |
| `bar_date` | `DATE` | Tidak | `YYYY-MM-DD` | Tanggal sesi perdagangan bursa berlangsung. |
| `open_price` | `DECIMAL(12,2)` | Tidak | IDR (Rupiah) | Harga pembukaan resmi sesi 1 bursa (Pukul 09:00 WIB). Wajib `> 0`. |
| `high_price` | `DECIMAL(12,2)` | Tidak | IDR (Rupiah) | Harga tertinggi yang terbentuk sepanjang hari perdagangan. Wajib `>= max(open, close)`. |
| `low_price` | `DECIMAL(12,2)` | Tidak | IDR (Rupiah) | Harga terendah yang terbentuk sepanjang hari perdagangan. Wajib `<= min(open, close)` dan `> 0`. |
| `close_price`| `DECIMAL(12,2)` | Tidak | IDR (Rupiah) | Harga penutupan resmi pasar reguler (Pukul 16:00 WIB). |
| `volume` | `BIGINT` | Tidak | Lembar Saham | Total volume perdagangan yang terjadi selama sesi reguler. Wajib `>= 0`. |
| `data_classification` | `VARCHAR(20)` | Tidak | Enum: `end_of_day`, `intraday` | Penanda finalitas bar. Hanya boleh bernilai `end_of_day` jika data ditarik setelah 16:00 WIB pada hari bursa resmi. |
| `indicators` | `JSONB` | Ya | Objek JSON | Fitur teknikal turunan: `{"atr14": float, "rvol20": float, "sma20": float, "rsi14": float}`. |
| `source_timestamp` | `TIMESTAMPTZ` | Tidak | ISO-8601 (Asia/Jakarta) | Waktu lokal saat sistem mengunduh bar dari penyedia data. |

### Metadata Provenance Waktu:
Setiap bar raw data juga melacak 5 stempel waktu point-in-time:
- **`event_time`:** Timestamp penutupan pasar BEI (16:00:00 WIB pada `bar_date`).
- **`published_at`:** Timestamp penyedia data (Yahoo Finance) mempublikasikan bar penutupan final.
- **`first_seen_at`:** Timestamp pipeline Aether pertama kali melihat data tersebut tersedia di API.
- **`fetched_at`:** Timestamp saat proses download lokal menyimpan data ke disk.
- **`available_at`:** Timestamp saat data telah divalidasi dan sah digunakan untuk inferensi (>= 16:05 WIB).

### Provenance Penyesuaian Harga (Adjustment Mode):
- **`unadjusted_nominal`:** Harga aktual bursa tanpa penyesuaian dividen/split (dipakai untuk lot sizing, batasan cash portofolio, dan tick limit).
- **`split_adjusted`:** Harga historis disesuaikan dengan aksi stock split atau reverse split agar deret waktu kontinu tanpa lonjakan artifisial.
- **`dividend_adjusted`:** Harga disesuaikan dengan dividen tunai (total return basis). Digunakan untuk evaluasi kinerja buy & hold jangka panjang.

---

## 2. Skema Berita & Analisis Dampak (News Item)

Merekam artikel berita finansial resmi, ekstraksi entitas, dan evaluasi dampak terstruktur tanpa live AI.

| Nama Atribut | Tipe Data | Nullable | Satuan / Format | Definisi & Aturan Integritas |
| :--- | :--- | :---: | :--- | :--- |
| `headline` | `TEXT` | Tidak | Teks Judul | Judul asli berita yang dipublikasikan oleh kantor berita resmi. |
| `source` | `VARCHAR(50)` | Tidak | Nama Sumber (e.g. `ANTARA News`) | Lembaga pers atau portal penerbit berita terverifikasi. |
| `url` | `TEXT` | Tidak | URL valid | Tautan permanen ke artikel asli. Digunakan sebagai kunci unik deduplikasi (SHA-1 hash). |
| `published_at`| `TIMESTAMPTZ` | Tidak | ISO-8601 | Waktu resmi publikasi artikel oleh media massa. |
| `first_seen_at`| `TIMESTAMPTZ` | Tidak | ISO-8601 | Waktu pipeline Aether pertama kali mengindeks feed artikel tersebut. |
| `key_facts` | `JSONB` | Tidak | Array of Strings | Butir-butir fakta objektif yang diekstrak langsung dari teks berita. |
| `summary` | `TEXT` | Tidak | Ringkasan Teks | Ringkasan ringkas dan faktual (maksimal 3-5 kalimat). |
| `related_tickers` | `JSONB` | Tidak | Array of Strings | Simbol emiten IDX yang terkait langsung dengan isi berita (e.g. `["BBCA", "BMRI"]`). |
| `impact_direction`| `VARCHAR(20)` | Tidak | Enum: `positive`, `negative`, `mixed`, `unclear` | Arah estimasi sentimen/dampak terhadap emiten/sektor terkait. |
| `impact_horizon` | `VARCHAR(30)` | Tidak | Enum: `intraday`, `1_to_5_days`, `medium_term` | Rentang waktu perkiraan mekanisme dampak berita mempengaruhi persepsi pasar. |
| `impact_mechanism`| `TEXT` | Tidak | Penjelasan Kausal | Jalur transmisi ekonomi/keuangan (e.g. "Kenaikan suku bunga menekan margin bunga bersih"). |
| `counter_scenario`| `TEXT` | Tidak | Pembatal / Risiko Alternatif | Kondisi pasar atau data riil yang dapat membatalkan dampak yang diantisipasi. |
| `analysis_method` | `VARCHAR(50)` | Tidak | Enum: `rule_based_extractive`, `llm_reasoning_fallback` | Pelabelan jujur mengenai metode ekstraksi yang digunakan. |

---

## 3. Skema Evaluasi Post-Horizon (+5 Hari Bursa)

Merekam pelacakan kebenaran prediksi terhadap return 5 hari bursa berikutnya secara anti-lookahead.

| Nama Atribut | Tipe Data | Nullable | Satuan / Format | Definisi |
| :--- | :--- | :---: | :--- | :--- |
| `ticker` | `VARCHAR(10)` | Tidak | Kode Saham | Simbol saham yang diprediksi. |
| `decision_date` | `DATE` | Tidak | `YYYY-MM-DD` | Tanggal keputusan prediksi dibuat. |
| `reference_price` | `DECIMAL(12,2)` | Tidak | IDR | Harga penutupan acuan saat prediksi dirilis. |
| `reference_price_date` | `DATE` | Tidak | `YYYY-MM-DD` | Tanggal bar yang menyediakan harga acuan. |
| `predicted_direction` | `VARCHAR(20)` | Tidak | Enum: `positive`, `negative`, `unclear` | Arah pergerakan yang diproyeksikan oleh model. |
| `target_eval_date` | `DATE` | Tidak | `YYYY-MM-DD` | Tanggal jatuh tempo evaluasi (+5 hari bursa resmi kalender BEI). |
| `actual_price` | `DECIMAL(12,2)` | Ya | IDR | Harga penutupan pasar reguler persis pada `target_eval_date`. |
| `actual_price_date` | `DATE` | Ya | `YYYY-MM-DD` | Tanggal candle yang dipakai mengevaluasi. Wajib persis sama dengan `target_eval_date`. |
| `actual_return_pct` | `DECIMAL(8,4)` | Ya | Persen (%) | Return penutupan: `((actual_price - reference_price) / reference_price) * 100`. |
| `outcome` | `VARCHAR(20)` | Ya | Enum: `correct`, `incorrect`, `neutral`, `missing_data` | Hasil verifikasi kebenaran arah prediksi. |
| `status` | `VARCHAR(20)` | Tidak | Enum: `pending`, `evaluated`, `cannot_evaluate` | Status pemrosesan evaluasi. |

---

## 4. Skema Seri Makroekonomi & Suku Bunga

Merekam indikator moneter dan global untuk pengujian fitur gabungan makro.

| Seri ID | Nama Indikator | Sumber Resmi | Frekuensi | Satuan |
| :--- | :--- | :--- | :--- | :--- |
| `BI_RATE` | BI-Rate (7-Day Reverse Repo Rate) | Bank Indonesia | Bulanan | Persentase (% p.a.) |
| `JISDOR_USDIDR` | Jakarta Interbank Spot Dollar Rate | Bank Indonesia | Harian (16:15 WIB) | IDR per 1 USD |
| `CPI_INFLATION` | Indeks Harga Konsumen (YoY & MoM) | BPS / World Bank | Bulanan / Tahunan | Persentase (%) |
| `FEDFUNDS` | Effective Federal Funds Rate | Federal Reserve / FRED | Harian | Persentase (% p.a.) |
| `BRENT_CRUDE` | Crude Oil Price (Brent / WTI) | FRED / EIA | Harian | USD per Barel |
| `IHSG_INDEX` | Indeks Harga Saham Gabungan (^JKSE) | Yahoo Finance / BEI | Harian | Poin Indeks |
