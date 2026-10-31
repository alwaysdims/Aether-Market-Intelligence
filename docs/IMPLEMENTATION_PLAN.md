# Rencana Implementasi dan Acceptance v3

## 1. Basis dan batas

Paket ini tidak mengubah aplikasi. Status berikut berdasarkan laporan pengguna, bukan pemeriksaan repository langsung.

Dilaporkan sudah ada: tujuh tab lazy loading, Vite, polling internal, IDX Edge pilot sepuluh saham, persistensi broker/fundamental, job asinkron, Hermes reasoning, worker OS, dan outbox multipart.

Perlu dicocokkan dengan bukti: konteks follow-up, periode broker yang tepat, cache metadata, screening hanya dari sumber operasional, eksekusi laporan sampai receipt, monitoring seluruh kandidat, serta kesiapan intraday RG. Jangan menyebut semua hal ini belum diimplementasikan atau sudah terverifikasi tanpa pemeriksaan.

## 2. Tahap pengerjaan

| Tahap | Pekerjaan | Gate |
|---|---|---|
| 0 | Baca repo, backup/diff, petakan state/tabel/flags/tasks aktual | Gap nyata; tidak membuat receiver/queue baru |
| 1 | Kontrak source/waktu/periode/cache; routing follow-up dan delivery | Satu pesan asli otomatis, isi benar, tanpa Yahoo fallback |
| 2 | Shell, tokens, shared components, Dashboard dan Market Monitor | Search/pagination/keyboard/mobile, kandidat tanpa data tetap terlihat |
| 3 | Tujuh tab dan Kesimpulan AI | Empty/loading/partial/history, tanggal fakta dan analisis berbeda, tanpa raw JSON utama |
| 4 | Monitoring, AI History, laporan EOD | Asal rekomendasi terpisah, horizon beku, actual scheduled delivery |
| 5 | Perluasan ingestion IDX Edge terukur | Coverage/sumber/freshness tepat; biaya dan cache tercatat |
| 6 | Penelitian kelayakan intraday | RG, delay, kelengkapan, koreksi dan biaya terverifikasi sebelum jadwal intraday aktif |

Desain menggunakan data aktual atau fixture yang hanya hidup di lingkungan test. Jangan menulis dummy ke production. Tidak perlu migrasi framework, SSE/WebSocket, atau layanan terdistribusi tanpa kebutuhan terukur.

## 3. Acceptance matrix

| ID | Skenario | Bukti minimum |
|---|---|---|
| AC01 | Emiten valid tanpa histori | Halaman dan coverage kosong; riset diizinkan; ticker unknown diblokir |
| AC02 | Daily change dan vs open | Fixture sesi sebelumnya, hari libur, gap; tanpa fallback |
| AC03 | Broker historis | Periode diminta/aktual/baseline; raw, tool trace dan evidence hash |
| AC04 | Screening lintas emiten | Funnel dan config hash; shortlist eksplisit; tidak jatuh ke filter swing |
| AC05 | Cache/coalescing | Dua proses dengan backend bersama, satu HTTP call; TTL expired ditangani; evidence tidak bocor |
| AC06 | Async delivery | Handler singkat, worker independen, receipts per bagian, unknown handling |
| AC07 | Follow-up | Evidence lama yang cukup -> tanpa fetch/rerun; konteks user terisolasi |
| AC08 | Performa UI | Host/dataset/version; TTFB/render/query/payload serta p50/p95 cold/warm |
| AC09 | Kalender dan laporan | Versi kalender, trigger aktual, lateness, persist sebelum publish |
| AC10 | Outcome | Origin, aturan/horizon beku, ambiguous/missing, tanpa inferensi usang |
| AC11 | Keamanan | Tool allowlist, secret redaction, dokumen tidak bisa memberi izin tools, auth/upload |
| AC12 | Rollback | Tidak ada inferensi/pengiriman ganda; histori dipertahankan |

Regresi mengikuti perubahan. Jumlah tes bukan metrik mutu finansial. Browser QA pada 390/768/1280/1440 dan keyboard. Uji numerik dipisah dari mock transport/LLM. Uji PostgreSQL yang memutasi data menggunakan database/schema test terisolasi.

## 4. Handoff ke Hermes

Pasang dokumen setelah review diff; jangan menjalankan instruksi legacy. Mulai tahap 0–1, lalu perubahan UI tahap 2–3. Laporkan berkas berubah, alasan, pengujian relevan, screenshot aktual, dan keterbatasan. Tetap satu receiver, pertahankan job asli yang sedang berjalan, jangan mengubah model/threshold produksi, melakukan order, membeli layanan, atau commit/push otomatis.
