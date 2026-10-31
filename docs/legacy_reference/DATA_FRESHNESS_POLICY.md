# KEBIJAKAN FRESHNESS & DATA INVENTORY TTL
## AETHER MARKET INTELLIGENCE — TAHAP I LANJUTAN

Dokumen ini menetapkan aturan klasifikasi ketersediaan dan kesegaran data (*data freshness*) per jenis data dan penyedia, kalender bursa, serta pemisahan tiga konsep kedaluwarsa yang berbeda.

---

### 1. PEMISAHAN TIGA KONSEP KEDALUWARSA

Dalam sistem Aether Market Intelligence, tiga konsep kedaluwarsa berikut dipisahkan secara tegas dan tidak boleh dicampuradukkan:

1. **Validitas Data Historis (Historical Validity):**
   - Data historis EOD penutupan bursa masa lalu **tidak pernah kedaluwarsa**. Candle penutupan 16 Oktober atau 23 Oktober tetap valid selamanya sebagai catatan fakta sejarah pasar.
2. **Kecukupan Data untuk Permintaan Saat Ini (Freshness for Current Request):**
   - Mengukur apakah data yang tersimpan di basis data sudah mencakup sesi perdagangan terakhir yang telah selesai diselesaikan (*settled*) sesuai kalender BEI.
   - Contoh: Pada hari Minggu, data EOD hari Jumat adalah data **paling mutakhir (AVAILABLE_FRESH)**. Pada hari Senin pukul 17:00 WIB, data hari Jumat sudah berstatus **STALE** karena sesi perdagangan Senin telah selesai dan memerlukan penarikan bar baru.
3. **Masa Berlaku Prediksi Sinyal (Prediction Lifecycle Expiry):**
   - Kontrak operasional Tahap I menetapkan batas pemantauan sinyal maksimal **14 hari kalender** sejak waktu publikasi. Jika dalam 14 hari target belum tercapai atau terinvalir, sinyal otomatis berstatus `EXPIRED`. Ini murni aturan siklus hidup prediksi, bukan aturan kedaluwarsa data pasar.

---

### 2. MATRIKS KLASIFIKASI KETERSEDIAAN DATA

Setiap kueri pengguna diuji terhadap enam status klasifikasi inventaris data:

| Status Klasifikasi | Kondisi Pemicu | Tindakan Sistem |
|---|---|---|
| **`AVAILABLE_FRESH`** | Data bar EOD di basis data cocok dengan tanggal sesi perdagangan terakhir BEI yang sudah selesai. | Analisis langsung dieksekusi dari basis data; tidak ada unduhan jaringan ulang. |
| **`AVAILABLE_BUT_INSUFFICIENT_FOR_REQUEST`** | Data EOD penutupan tersedia, tetapi pengguna meminta data intraday/real-time saat pasar bursa sedang tutup (akhir pekan, hari libur, malam hari). | Menyajikan data penutupan terakhir dan menjelaskan bahwa pasar sedang tutup. |
| **`STALE`** | Data bar tersimpan berasal dari tanggal sebelum sesi perdagangan bursa terakhir yang telah selesai (misal: hari Selasa masih menyimpan data Jumat). | Memicu pengambilan data (*fetch*) on-demand secara otomatis ke Yahoo Finance. |
| **`MISSING`** | Belum ada rekod data pasar sama sekali untuk emiten tersebut di tabel `market_observations`. | Memicu registrasi emiten baru, pengambilan data awal, dan verifikasi identitas BEI. |
| **`CONFLICTED`** | Dua sumber data publik mengembalikan harga penutupan yang berselisih `> 0.5%` pada tanggal yang sama. | Mengkarantina kedua data ke `data/quarantine/`; tidak merata-ratakan harga; memblokir bukti konflik masuk ke analisis valid. |
| **`SOURCE_UNAVAILABLE`** | Penyedia data publik mengalami *downtime*, rate limit (HTTP 429), atau firewall timeout. | Menyajikan pesan kegagalan terperinci tanpa menyalahkan basis data lokal. |

---

### 3. KEBIJAKAN TTL & JADWAL PUBLIKASI PER PENYEDIA

| Jenis Data | Penyedia / Endpoint | Jadwal Publikasi Resmi | Batas Delay yang Diketahui | Kebijakan TTL Operasional |
|---|---|---|---|---|
| **EOD Equities (IDX)** | Yahoo Finance (`.JK`) | Senin–Jumat 16:00 WIB | 15 menit pasca closing | Berlaku hingga pukul 16:05 WIB hari bursa berikutnya. |
| **Intraday Delayed** | Yahoo Finance (v8) | Sesi 1 & 2 Reguler BEI | 15 menit delayed | 30 menit selama jam bursa aktif. |
| **Berita Finansial** | LKBN ANTARA RSS | Real-time syndication | < 5 menit | Relevansi konteks emiten: 7 hari kalender. Batas arsip: 30 hari. |
| **Berita Global** | GDELT Project DOC 2.0 | 15 menit crawling index | 15–30 menit | Relevan untuk mendeteksi lonjakan isu dalam 14 hari. |
| **Makroekonomi (BI)** | Bank Indonesia | Bulanan (BI-Rate) / Harian (JISDOR 16:15 WIB) | Sesuai rilis resmi | 30 hari kalender sejak tanggal rilis. |
| **Statistik (BPS)** | BPS Open Data | Tanggal 1 setiap bulan (CPI) / Kuartalan (PDB) | Sesuai rilis resmi | 90 hari kalender sejak tanggal rilis. |

---

### 4. ATURAN HARI BURSA & KALENDER BEI 2026

Menggunakan `App\Services\BeiCalendarService` untuk validasi:
- Jam Buka Pasar Reguler: 09:00 WIB.
- Jam Tutup Pasar Reguler: 16:00 WIB.
- Waktu Finalitas EOD: Pukul 16:00 WIB pada hari bursa resmi (Senin–Jumat).
- Sesi pra-penutupan (*pre-closing*): 15:50–16:00 WIB.
- Akhir Pekan (Sabtu & Minggu): Bukan hari bursa; data EOD hari Jumat tetap berstatus `AVAILABLE_FRESH` sepanjang hari Sabtu dan Minggu.
- 16 Hari Libur Nasional Resmi BEI 2026 didaftarkan lengkap pada konstanta `BeiCalendarService::BEI_HOLIDAYS_2026`.
