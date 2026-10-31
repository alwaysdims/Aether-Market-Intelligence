# Arsitektur kanonikal dan status implementasi

```text
Pengguna BEI ─ Telegram ─ Hermes Gateway (satu receiver) ─ aether_router
                                                  │
                                                  ▼
                          Laravel: otorisasi, intent/tool allowlist, konteks
                                    │                  │
                                 cache DB          ResearchRequest
                                    │                  ▼
                          PostgreSQL ← validasi ← kolektor Yahoo/IDX/manual
                                    │                  │
                          EvidenceSnapshot ← arsip raw + hash
                                    │
                            Quant/ML (kontrak 5 hari EOD saja)
                                    │
                           AI narasi terikat bukti
                                    │
                 MarketAnalysis/PredictionSignal → TelegramOutbox → Telegram
                                    │
                               Dashboard Laravel
```

Diagram menyatakan kontrak tujuan, bukan bukti seluruh panah aktif. Komponen operasional: Gemini melalui 9router memilih tool terbatas/menjelaskan fakta; kolektor memperoleh data; Laravel memvalidasi dan menyimpan; Quant menghitung fitur/model hanya untuk instrumen, frekuensi, horizon tervalidasi; PostgreSQL sumber kanonikal; Gateway transport inbound; dashboard membaca hasil DB. Tidak ada akses shell/SQL/broker bagi model. `AiToolExecutorService` masih punya pemanggilan inbound sintetis dan harus dihapus sebelum mengklaim kontrak ini terpenuhi.

## DFD konteks

```text
Pengguna ─[permintaan BEI]→ (Aether) ─[jawaban/status]→ Pengguna
Penyedia data ─[bar/artikel/dokumen]→ (Aether)
(Aether) ─[permintaan data]→ Penyedia data
9router ─[pilihan tool/narasi]→ (Aether) ─[prompt berbasis bukti]→ 9router
```

## DFD level 1 (aliran eksternal sama dengan konteks)

```text
Pengguna → (1 Gateway/otorisasi) → (2 rencana/intent) → (3 cek PostgreSQL)
                   │                    ↕ 9router             │
                   │                                      data kurang
                   │                                          ▼
                   │                                   (4 kolektor) ↔ Penyedia data
                   │                                          ▼
                   │                                 (5 validasi/arsip) → PostgreSQL
                   │                                          ▼
                   │                                 (6 fitur/ML/narasi) ↔ 9router
                   │                                          ▼
                   └──────── (7 balas/outbox) ← PostgreSQL → (8 dashboard)
                                      │
                                   Pengguna
```

## ERD berdasarkan migrasi aktual

```text
analysis_runs 1 ── N market_snapshots ── 0..N evidence_snapshots
analysis_runs 1 ── N market_analyses ── 0..N research_requests
analysis_runs 1 ── N prediction_signals N ── 0..1 evidence_snapshots
research_requests N ── 0..1 evidence_snapshots
research_requests N ── 0..1 telegram_outbox
market_analyses 1 ── N post_horizon_evaluations
users?   [TIDAK ADA FK]   user_watchlists(user_id, ticker UNIQUE)
users?   [TIDAK ADA FK]   user_portfolios(user_id, cash_balance, target_trading_pct, target_investment_pct)
user_portfolios 1 ── N    user_portfolio_items(portfolio_id, user_id, ticker, lots, shares, avg_buy_price, allocation_type)
market_observations(research_request_id STRING)   [BUKAN FK]
monitoring_events(prediction_id STRING)           [BUKAN FK]
telegram_outbox(prediction_id STRING)             [BUKAN FK]
telegram_conversations(context_signal_id STRING) [BUKAN FK]
```

`market_observations` tidak memiliki FK ke `market_snapshots`; snapshot berita dalam `evidence_snapshots.related_news_snapshot` berupa JSON, bukan FK `news_items`. Detail kolom: `dashboard/database/migrations/2026_10_16_041837_create_hermes_market_intelligence_tables.php`, `2026_10_25_100000_create_tahap_i_prediction_and_telegram_tables.php`, `2026_10_25_200000_create_on_demand_research_tables.php`, `2026_10_25_300000_create_user_watchlists_table.php`, `2026_10_26_100000_create_user_portfolios_tables.php`. Tidak boleh mengasumsikan harga dari dua tabel setara. Arsip raw dan evidence harus dibuat sebelum analisis; analisis serta outbox setelah commit, tanpa transaksi melintasi panggilan jaringan.

## Status Komponen & Perluasan Asisten Saham

1. **Portfolio Service & Storage**: Migrasi `2026_10_26_100000_create_user_portfolios_tables.php` menambahkan tabel `user_portfolios` dan `user_portfolio_items`. Target default 50% trading dan 50% investasi tersimpan sebagai konfigurasi preferensi pengguna. Nilai pasar dan floating PnL dihitung deterministik lewat `CanonicalMarketReadService`.
2. **Screening & Komparasi Kuantitatif**: `StockScreeningService` menyediakan screening strategi SWING (berdasarkan rentang volatilitas bar dan volume) serta DIVIDEND/defensif, plus komparasi multi-emiten.
3. **Pemberantasan Polusi Sintetis**: Tool internal di `AiToolExecutorService` dilengkapi flag `_synthetic => true` sehingga tidak mengotori tabel deduplikasi `telegram_conversations`.
4. **Flush Outbox Instan**: `HermesExecuteResearchCommand` langsung mengeksekusi `processOutboxQueue()` seketika riset on-demand selesai, memangkas latensi percakapan Telegram dari 5 menit menjadi 2-3 detik. Scheduled task `AetherWorkers_5min` difungsikan sebagai jaring pengaman recovery.

