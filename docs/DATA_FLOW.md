# Alur data resmi dan celah implementasi

## Flowchart permintaan Telegram

```text
update Telegram → Gateway tunggal → allowlist from.id DAN chat.id
    → dedup update_id → klasifikasi mode dan ticker/konteks
    ├─ status/rekap/watchlist → layanan DB → simpan percakapan → balas
    ├─ sapaan saham → percakapan 9router berbatas tool → simpan → balas
    ├─ DAY_TRADE → INTRADAY_DATA_UNAVAILABLE → simpan → balas
    ├─ INVESTMENT tanpa bukti fundamental → INVESTMENT_EVIDENCE_UNAVAILABLE → simpan → balas
    └─ SWING/riset → cek cukup/final/fresh/konflik
          ├─ cukup → baca evidence + analisis tersimpan → simpan percakapan → balas
          └─ kurang → ResearchRequest → ACK → worker → koleksi → hasil tersimpan
                                                    → outbox → delivery terkonfirmasi
```

## Flowchart pipeline analisis

```text
permintaan/jadwal → cek data tersedia, histori, bursa, publikasi, sumber
→ koleksi bila perlu → arsip raw + hash → validasi OHLCV/finalitas/konflik
→ commit observasi & evidence → fitur → ML hanya bila kontrak valid
→ penjelasan AI berbasis ID evidence → simpan MarketAnalysis/PredictionSignal
→ outbox/sajian dashboard → kirim → simpan receipt message_id
```

Fakta kode: `OnDemandDataCollectorService.php` menulis `market_observations` dan `evidence_snapshots`; `HermesSyncEodCommand.php` menulis `market_snapshots`. Dashboard market membaca `market_snapshots`; Telegram ticker membaca `market_observations`. Hubungan antarkeduanya BELUM kanonikal. Data konflik saat ini masih disimpan sebagai observation `CONFLICTED`; tidak boleh dirender sebagai fakta harga valid. `is_eod_final=true` yang dipasang kolektor Yahoo bukan bukti settlement resmi IDX; perlu pemeriksaan finalitas aktual. `DataFreshnessPolicyService` menghitung sesi bursa (akhir pekan tetap memakai sesi terakhir), tetapi pemilihan berdasarkan observation_time bisa salah untuk bar_date lebih tua. Tidak ada feed intraday produksi terverifikasi.

Satu jawaban tidak boleh mengubah bar hasil LLM menjadi harga. Setiap keluaran perlu mode, ticker, observation/evidence/analysis ID, bar as-of, waktu fetch, sumber, versi model/prompt, status validation, dan horizon bila ada. Klaim berhasil disimpan setelah transaksi benar-benar tercatat; klaim terkirim hanya setelah receipt Telegram memiliki ID nyata.
