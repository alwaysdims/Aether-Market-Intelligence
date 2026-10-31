# Pemetaan Dokumentasi Lama ke v3

Paket sumber: docs.zip, 29 berkas Markdown. Seluruh salinan asli dipertahankan di legacy_reference. Tidak ada source code yang diperiksa atau diubah. Dokumen baru adalah hasil keputusan desain, bukan status deployment.

## Perubahan substantif

- PRD lama fase6/10ticker/E:\Project-bot diganti cakupan direktori kandidat, ingestion bertahap, workspace final, dan dashboard peremiten.
- Istilah Hermes Stock Agent/Python engine dipisahkan dari Hermes production reasoning agent dan quant-engine.
- Laravel directGemini konsultasi diganti satu reasoning owner Hermes; backend tetap pengendali tools/evidence/publication.
- Yahoo/Index Alpha aktif/fallback diubah IDXEdgeoperationalonly; arsip tidak dihapus.
- Finalitas berbasis jam tetap diganti quality/freshness/providerattestation terpisah.
- Dua arah sinkron diganti durableasync termasuk followup; receiver tetap satu.
- UI lama operasional menjadi analisis+outcome, sidebar4menu,profiledropdown,tujuh tab,navfast.
- ConfidenceLLMmandatory dihapus; probabilitas hanya MLsah, historicalexpired terpisah.
- Outcome rule/agent/MLdipisah,30harirekap bukan universalhorizon atau auto-training.
- Tidak ada n8n, noquoteintradaysampaidatavalid, no90%promise.

## Pemetaan berkas

| Berkas lama | Dokumen aktif/pengganti |
|---|---|
| `ALL_IDX_DATA_PIPELINE.md` | architecture.md + DATA_PROVIDER_CONFIGURATION.md |
| `ANALYSIS_MODES.md` | PRD.md + RECOMMENDATION_MONITORING.md |
| `DATA_DICTIONARY.md` | DATA_CONTRACTS.md |
| `DATA_FLOW.md` | architecture.md |
| `DATA_FRESHNESS_POLICY.md` | DATA_CONTRACTS.md |
| `DATA_PROVIDER_CONFIGURATION.md` | DATA_PROVIDER_CONFIGURATION.md |
| `DATA_SOURCES.md` | DATA_PROVIDER_CONFIGURATION.md |
| `FUNDAMENTAL_DATA_DICTIONARY.md` | DATA_CONTRACTS.md (detail rumus lama tetap referensi; cek implementasi) |
| `GIT_MANAGEMENT.md` | OPERATIONS_AND_RECOVERY.md (Git policy lama referensi) |
| `LIVE_AI_CONFIGURATION.md` | architecture.md + TELEGRAM_TWO_WAY.md |
| `MARKET_MONITORING.md` | RECOMMENDATION_MONITORING.md |
| `ML_EXPERIMENT_PROTOCOL.md` | ML_RESEARCH_POLICY.md |
| `ON_DEMAND_RESEARCH.md` | TELEGRAM_TWO_WAY.md + DATA_CONTRACTS.md |
| `PANDUAN_PRESENTASI.md` | PRD.md + architecture.md (presentasi lama perlu diregenerasi) |
| `PANDUAN_RESTART_DAN_STARTUP.md` | OPERATIONS_AND_RECOVERY.md |
| `PRD.md` | PRD.md |
| `PREDICTION_LIFECYCLE.md` | RECOMMENDATION_MONITORING.md |
| `PRODUCT_SCOPE.md` | PRD.md |
| `REALTIME_DASHBOARD.md` | DESIGN.md + architecture.md (EOD saat ini, bukan realtime) |
| `RESTART_AND_RECOVERY.md` | OPERATIONS_AND_RECOVERY.md |
| `STOCK_ANALYSIS_AND_TRAINING.md` | ML_RESEARCH_POLICY.md + RECOMMENDATION_MONITORING.md |
| `SYSTEM_ARCHITECTURE.md` | architecture.md (pengarah SYSTEM_ARCHITECTURE.md dipertahankan) |
| `TECHNICAL_INDICATORS.md` | DATA_CONTRACTS.md (detail formula lama referensi, validasi engine) |
| `TELEGRAM_TWO_WAY.md` | TELEGRAM_TWO_WAY.md |
| `TIMESTAMP_AUDIT_LOG.md` | legacy_reference/TIMESTAMP_AUDIT_LOG.md dipertahankan sebagai log historis; kebijakan aktif DATA_CONTRACTS.md |
| `WINDOWS_OPERATIONS.md` | OPERATIONS_AND_RECOVERY.md |
| `architecture.md` | architecture.md |
| `contracts.md` | DATA_CONTRACTS.md |
| `installation.md` | OPERATIONS_AND_RECOVERY.md (versi/executable verifikasi repo) |

## Cara menerapkan tanpa merusak riwayat

1. Buat branch/backup sesuai prosedur repo; tidak perlu commit otomatis.
2. Simpan arsip docs lama di lokasi historis. Jangan menghapus acceptance report/auditlog.
3. Pasang berkas aktif v3; update link README repo ke docs/README.md.
4. Jika dokumen detail lama masih dibutuhkan, berikan banner historis/pointer; jangan biarkan dua PRD/architecture aktif bertentangan.
5. Cocokkan schema, routes, commands, flags dan versi executable dengan kode saat implementasi. Tidak menjalankan command path lama dari legacy.
6. Tulis conformance diff status: dilaporkan ada, terbukti saat pemeriksaan, perlu perubahan, atau belum tersedia. Tidak mengubah status menjadi selesai hanya karena dokumennya selesai.
