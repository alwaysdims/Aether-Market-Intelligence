# Pipeline Data Seluruh Saham BEI (IDX Universe Data Pipeline)

> ⚠️ **STATUS: HISTORIS / NONAKTIF (v2)**  
> Dokumen ini merupakan referensi teknis historis. Untuk kontrak aktif dan arsitektur terkini v3.0, rujuk ke:  
> • [architecture.md](architecture.md) (Arsitektur Komponen & Alur Data Aktif)  
> • [DATA_PROVIDER_CONFIGURATION.md](DATA_PROVIDER_CONFIGURATION.md) (Tata Kelola Sumber Data & Provider IDX Edge)  
> • Salinan arsip asli tersimpan di `docs/legacy_reference/ALL_IDX_DATA_PIPELINE.md`.

Dokumen ini mendefinisikan arsitektur, alur data, penanganan sumber resmi, checkpoint recovery, dan tata kelola ingestion seluruh saham tercatat di Bursa Efek Indonesia (BEI/IDX).

---

## 1. Arsitektur Registri Seluruh Emiten BEI

- **Populasi Saham Perusahaan**: 941 emiten tercatat (Snapshot: 26 Oktober 2026).
- **Pemisahan Indeks Acuan**: Indeks `^JKSE` (IHSG) dipisahkan secara eksplisit sebagai `instrument_type = 'INDEX'`, bukan emiten saham biasa.
- **Pengecualian**: Waran, rights, ETF, dan efek derivatif tidak dicampur ke dalam saham biasa perusahaan.
- **Audit Akses Sumber Resmi**:
  - Endpoint resmi BEI `https://www.idx.co.id/en/listed-companies/company-profiles` diproteksi Cloudflare Bot Challenge (HTTP 403).
  - Sesuai prinsip keamanan tanpa *bypass* paywall/captcha terlarang, sistem menandai status `SOURCE_UNAVAILABLE_CLOUDFLARE_BLOCKED` dan mengalihkan ke repositori mirror publik terverifikasi yang sah.
  - Snapshot registri mentah disimpan secara *immutable* di `data/raw_immutable/registry/idx_registry_YYYYMMDD.html` dengan hash integritas SHA-256 tersimpan di database (`caac89d8a9a99974...`).

---

## 2. Model Data & Skema PostgreSQL

Tabel migrasi `2026_10_26_200000_create_idx_market_expansion_tables.php`:
1. `idx_company_registries`: Ticker (4 huruf), nama perusahaan, tanggal pencatatan, papan (Utama, Pengembangan, Akselerasi, Pemantauan Khusus), sektor, industri, jumlah saham, status listing.
2. `company_fundamentals`: Laporan tahunan & kuartalan (pendapatan, laba kotor, laba operasional, laba bersih, aset, liabilitas, ekuitas, utang, arus kas operasi, capex, free cash flow).
3. `company_corporate_actions`: Aksi dividen tunai dan stock split bertanggal eksekusi.
4. `company_analysis_metrics`: Rasio fundamental deterministik (PER, PBV, ROE, ROA, DER, NPM, OPM, EPS, BVPS) dan indikator teknikal (SMA 20/50/200, RSI 14, MACD, ATR 14, RVOL 20, Support/Resistance, Trend).
5. `idx_ingestion_jobs`: Antrean batch, checkpointing, lease lock, dan retry recovery.

---

## 3. Alur Ingestion & Checkpoint Recovery

```text
Registri BEI (941 Emiten) ──► Ingestion Queue (idx_ingestion_jobs)
                                    │
       ┌────────────────────────────┴───────────────────────────┐
       ▼                                                        ▼
Canary Test (5 Sektor)                                     Batch Worker
BBCA, MEDC, ICBP, GOTO, PWON                       `idx:process-queue --limit=5`
       │                                                        │
       ▼ (Pass 100%)                                            ▼
Raw Daily & Multi-Year OHLCV                        yfinance Rate-Limited Scraper
Corporate Actions (Dividends/Splits)               (Timeout, Backoff, Circuit Breaker)
Financial Statements (Annual/Quarterly)                         │
       │                                                        ▼
       └───────────────────► Kalkulasi Deterministik (Tanpa Halusinasi LLM)
                                    │
                                    ▼
                         PostgreSQL Database Kanonikal
                                    │
                ┌───────────────────┴───────────────────┐
                ▼                                       ▼
        Telegram Dua Arah                       Dashboard Overview
  "analisis fundamental KIJA"             Cakupan Ingestion 941 Emiten
```

---

## 4. Mekanisme Keandalan (Reliability & Safety)

1. **Prioritas Chat Interaktif**: Pertanyaan Telegram langsung dilayani secara instan on-demand tanpa terblokir antrean backfill massal.
2. **Rate Limiting & Anti-Ban**: Sleep terukur 250ms per ticker, backoff eksponensial, dan timeout 30 detik untuk menghormati provider data publik tanpa proxy rotation agresif.
3. **Lease Locking**: Antrean dikunci menggunakan token UUID dengan batas waktu 5 menit. Jika proses terhenti atau laptop reboot, job yang kadaluarsa otomatis diambil alih worker berikutnya.
4. **Idempotensi**: Seluruh penulisan menggunakan `updateOrCreate` berbasis `['ticker', 'fiscal_year', 'fiscal_quarter']` atau `['ticker', 'as_of_date']`.
