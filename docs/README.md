# Dokumentasi Aether Market Intelligence v3.0

Tanggal keputusan: 31 Oktober 2026 (Asia/Jakarta). Bahasa: Indonesia.
Workspace tujuan: `E:\Aether-Market-Intelligence`.

## Kedudukan dokumen

Paket ini menggantikan rancangan produk dan arsitektur pada docs.zip lama berdasarkan keputusan pengguna sampai 31 Oktober 2026. Dokumen adalah **kontrak target dan rencana implementasi**, bukan audit kode/runtime Windows. Penyusun membaca dokumen yang diberikan dan laporan percakapan; tidak mengakses repository, database, secret, atau gateway lokal. Angka cakupan, PID, kuota tersisa, test count, dan receipt tidak dibekukan sebagai kondisi sekarang.

Urutan otoritas: PRD untuk cakupan; DESIGN untuk UI; architecture untuk pembagian komponen; DATA_CONTRACTS untuk bukti dan semantik; SOP untuk operasi. Jika kode menyimpang, catat gap, jangan mengubah dokumentasi agar tampak lulus. Perubahan perilaku strategi wajib versi/config hash baru.

## Bacaan utama

| Dokumen | Isi |
|---|---|
| [PRD.md](PRD.md) | Tujuan, kebutuhan, jadwal, penerimaan, batas produk |
| [DESIGN.md](DESIGN.md) | Token visual, sidebar, tujuh tab, tabel, states, responsivitas |
| [architecture.md](architecture.md) | Komponen, alur, batas kepercayaan, diagram |
| [SYSTEM_ARCHITECTURE.md](SYSTEM_ARCHITECTURE.md) | Pengarah ke arsitektur tunggal |
| [DATA_CONTRACTS.md](DATA_CONTRACTS.md) | Sumber, waktu, periode, broker, evidence, contoh JSON |
| [DATA_PROVIDER_CONFIGURATION.md](DATA_PROVIDER_CONFIGURATION.md) | Kebijakan IDX Edge, cache, kuota, konfigurasi aman |
| [TELEGRAM_TWO_WAY.md](TELEGRAM_TWO_WAY.md) | Routing kontekstual, worker Hermes, delivery, recovery |
| [RECOMMENDATION_MONITORING.md](RECOMMENDATION_MONITORING.md) | Kontrak screening dan evaluasi hasil |
| [OPERATIONS_AND_RECOVERY.md](OPERATIONS_AND_RECOVERY.md) | Single owner scheduler, insiden, rollback |
| [ML_RESEARCH_POLICY.md](ML_RESEARCH_POLICY.md) | Batas model produksi dan penelitian |
| [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) | Tahap perubahan dan acceptance matrix |
| [DOCUMENTATION_MIGRATION.md](DOCUMENTATION_MIGRATION.md) | Pemetaan seluruh berkas lama |

## Keputusan inti

1. Tidak menggunakan n8n; Windows Task Scheduler + Laravel tetap dipakai.
2. Laravel pusat kendali; Hermes profil produksi adalah worker penalaran/tool selection, menggunakan konfigurasi model Gemini yang dipertahankan melalui 9router.
3. Python mengolah kuantitatif/ML; bukan semua kolektor wajib dipindah ke Python.
4. IDX Edge menjadi sumber operasional harga/histori/broker/fundamental. Yahoo arsip historis, bukan fallback otomatis.
5. Quote yang mencampur NG/RG tetap dikarantina. Chart sekarang EOD; intraday roadmap bersyarat, bukan klaim realtime.
6. Dashboard membaca hasil; tidak memicu agent saat navigasi/polling.
7. Kandidat aturan, analisis agent, dan inferensi ML memiliki asal/metrik terpisah.

`docs/legacy_reference/` memuat salinan dokumen lama **nonaktif**; instruksi, path, perintah, dan klaim di sana tidak boleh dijalankan sebagai SOP saat ini. Riwayat audit asli tidak ditulis ulang. Salin berkas v3 ke repo melalui review diff; paket ini sendiri tidak mengubah aplikasi.
