# Operasi Windows dan Recovery

Workspace final: `E:\Aether-Market-Intelligence`, aplikasi di `dashboard/`. Path lama `E:\Project-bot` dan `E:\hermes-stock-agent` tidak menjadi instruksi runtime. Quant engine berada di `quant-engine/`. Profil gateway dan reasoning dipisahkan. Verifikasi executable serta virtual environment aktual; jangan memaksakan versi Python dari dokumen lama.

## 1. Kepemilikan pemicu

| Task berdasarkan laporan | Peran target | Larangan |
|---|---|---|
| AetherScheduledDispatcher_1min | schedule:run; konsultasi, riset, jadwal pengguna, outbox, briefing | Tidak menahan pekerjaan panjang di foreground |
| AetherWorkers_5min | Ingestion/backfill dan narasi terbatas | Tidak menggandakan konsultasi/outbox milik dispatcher |
| HermesMarketIntelligence_EOD | run_eod_pipeline.bat pada 16:05 | Tidak diduplikasi jadwal Laravel untuk pekerjaan yang sama |

Periksa arguments, working directory, user, dan last result pada host saat implementasi. Deklarasi routes/console.php bukan bukti pemicu OS aktif. Trigger weekday tetap memeriksa kalender. Refresh fundamental Sabtu 08:00 bukan sesi bursa buka.

Pengiriman dilakukan pada tick dispatcher berikutnya, ditambah durasi komputasi dan transit. Laptop sleep/mati memengaruhi jadwal. Kebijakan keterlambatan dan catch-up harus spesifik per laporan/job, bukan asumsi toleransi universal.

## 2. Startup

1. Periksa PostgreSQL dan database target melalui probe tanpa secret.
2. Periksa koneksi 9router; completion canary kecil dilakukan bila diperlukan. Reachable berbeda dari completion berhasil.
3. Pastikan satu gateway dan polling benar-benar progressing; profil aether-prod tidak menerima Telegram. Pertahankan pending update saat restart.
4. Periksa task scheduler, heartbeat, izin efektif sumber, dan lock per antrean.
5. Periksa oldest pending, lease stale, delivery unknown/partial, dan data yang belum tersedia.
6. Build aset frontend jika berubah. Server dashboard tidak menjadi satu-satunya pemicu pekerjaan background.

## 3. Insiden job diam

Mulai dari pesan/job asli: durable acceptance -> claim/heartbeat -> tools HTTP -> completion -> validasi hasil -> outbox parts -> receipts. Jangan menyalahkan Gemini bila inbound belum masuk. Jangan menaikkan timeout tanpa trace. Seluruh general conversation dan follow-up konsultatif harus asinkron.

Recovery idempotent dan write memeriksa owner token. Pekerja aktif tidak direclaim hanya berdasarkan umur. Periksa identitas proses dan waktu pembuatannya sebelum pemulihan atau fencing. Jangan menghapus pending Telegram, memaksa offset, atau menjalankan getUpdates paralel dengan receiver.

Fallback IP transport hanya melalui mekanisme runtime yang didukung; hostname, SNI, dan TLS verification tetap aktif. Jangan membekukan IP dari laporan lama sebagai solusi universal.

## 4. Rollback

Feature flag reasoning diubah operator. Pekerjaan berjalan mempertahankan route/version yang tercatat atau dibatalkan secara eksplisit sebelum retry; tidak boleh dua jalur inferensi sekaligus. Menonaktifkan sumber berarti degraded dengan alasan, bukan mengaktifkan Yahoo otomatis. Quote quarantine dipertahankan.

Backup sebelum perubahan schema/data. Restore dan pengujian mutasi menggunakan database test terisolasi; jangan memakai production sebagai database pengujian. Tidak ada commit/push otomatis.

## 5. Bukti operasional

Catat konfigurasi nonrahasia, asal trigger, UTC/WIB, segmen durasi, token lease, identitas proses, hash payload, evidence, outbox, dan receipt. Pisahkan CONFIGURED, RUNNING, VERIFIED_AUTOMATIC, LIVE_DELIVERY, CONTENT_VERIFIED.

Simulasi dengan pengiriman live bukan bukti inbound asli. `schtasks /run` manual bukan bukti jadwal otomatis. Payload terkirim immutable; koreksi melalui erratum baru. Status health berasal probe bertimestamp, bukan sekadar file ada atau port terbuka.
