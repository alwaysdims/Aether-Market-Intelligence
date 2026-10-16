# ARSITEKTUR AETHER MARKET INTELLIGENCE

**Repositori Resmi:** [https://github.com/alwaysdims/Aether-Market-Intelligence](https://github.com/alwaysdims/Aether-Market-Intelligence)  

## 1. Ikhtisar Sistem
Aether Market Intelligence adalah sistem intelijen pasar modal Indonesia (Bursa Efek Indonesia / BEI) yang mengintegrasikan:
1. **Aether Laravel Control Plane (`E:\Project-bot\laravel-app`):** Dashboard web lokal, database PostgreSQL, queue, job scheduler, manajemen prompt template, dan histori analisis AI.
2. **Hermes Stock Agent (`E:\hermes-stock-agent`):** Mesin analitik kuantitatif Python independen (VPA akumulasi bandarmologi, Daily EOD market scanner, dan forward paper trading dual-portofolio EXP-01 vs EXP-04).
3. **Bot Telegram (`@Info_BosKuu_bot`):** Kanal transmisi notifikasi pasar resmi ke admin (Chat ID: `5923895922`).
4. **AI Reasoning Engine (Gemini 3.8 Flash High):** Model AI via endpoint router lokal `http://127.0.0.1:20128/v1` dengan identifier `ag/gemini-3.8-flash-high`.

## 2. Batasan Mutlak
- **Zero Live Execution:** Dilarang melakukan live trading, pengiriman order riil ke sekuritas, atau koneksi ke broker trading otomatis. Seluruh tracking bersifat paper/simulasi.
- **Model AI Terkunci:** Wajib menggunakan model `ag/gemini-3.8-flash-high`. Dilarang mengganti model secara otomatis.
- **Isolasi Repositori:** Repositori `E:\hermes-stock-agent` dipertahankan terpisah sebagai sub-engine mandiri. Komunikasi dilakukan melalui adapter di `E:\Project-bot\services\`.

## 3. Komponen & Alur Data
```
[ Pasar Modal BEI ]
   │
   ├─► Yahoo Finance (Data Harian EOD Resmi)
   └─► Index Alpha API (Broker Summary Pasca-Penutupan)
            │
            ▼
   [ Hermes Stock Agent ] (E:\hermes-stock-agent)
   ├── EOD Market Scanner & Technical Indicators
   ├── Bandarmologi Accumulation Scanner
   └── Forward Paper Trading Engine (EXP-01 vs EXP-04)
            │
            ▼ JSON Standard Contract
   [ Service Adapter ] (E:\Project-bot\services\hermes_adapter.py)
            │
            ▼
   [ Laravel Control Plane ] (E:\Project-bot\laravel-app)
   ├── Web Dashboard: Localhost (Blade + Tailwind + Alpine)
   ├── PostgreSQL 16: `project-bot` DB
   ├── AI Analysis Engine (HTTP Client ──► 9router :20128 ag/gemini-3.8-flash-high)
   └── Job Scheduler & Notification Dispatcher
            │
            ▼
   [ Bot Telegram ] (@Info_BosKuu_bot)
```

## 4. Skema Database PostgreSQL (`project-bot`)
1. `data_sources`: Status, tipe, dan klasifikasi seluruh sumber data pasar/berita.
2. `prompt_templates`: Template sistem dan instruksi AI yang dikelola secara berversi.
3. `analysis_runs`: Audit trail setiap eksekusi batch/pipeline analitik.
4. `market_snapshots`: Rekaman data OHLCV, volume, dan indikator teknikal per tanggal bar.
5. `news_items`: Berita pasar terverifikasi dengan penanda status fakta.
6. `market_analyses`: Output inferensi AI terstruktur (regime, dampak, sentimen, risiko).
7. `notification_logs`: Histori pesan Telegram terkirim dan deteksi hash anti-duplikasi.
8. `system_health_checks`: Log kesehatan berkala PostgreSQL, AI Router, dan Hermes Agent.
