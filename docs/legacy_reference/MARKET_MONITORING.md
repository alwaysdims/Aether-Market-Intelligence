# PEMANTAUAN PASAR BURSA & DETEKSI EVENT (MARKET MONITORING)
## AETHER MARKET INTELLIGENCE

Dokumen ini mendefinisikan jadwal operasional pemantauan bursa Efek Indonesia (BEI), penanganan keterlambatan data gratis, deteksi celah pemantauan (*offline gap*), dan aturan evaluasi target harga.

---

### 1. Kalender & Sesi Perdagangan BEI (Waktu Indonesia Barat / UTC+7)

Sistem `BeiCalendarService` mengatur pembagian sesi perdagangan resmi bursa saham:

| Hari Bursa | Sesi Perdagangan | Rentang Waktu (WIB) | Status Sistem |
|---|---|---|---|
| **Senin – Kamis** | Pra-Pembukaan | 08:45 – 08:59 | Persiapan Antrean / Pantau Pembukaan |
| | **Sesi I (Pagi)** | **09:00 – 12:00** | **Pemantauan Pasar Aktif** |
| | Jeda Istirahat Siang | 12:00 – 13:30 | Pasar Tutup Sementara (Jeda Siang) |
| | **Sesi II (Siang)** | **13:30 – 15:45** | **Pemantauan Pasar Aktif** |
| | Pra-Penutupan & Pasca | 15:45 – 16:15 | Pembentukan Harga Penutupan Resmi |
| **Jumat** | Pra-Pembukaan | 08:45 – 08:59 | Persiapan Antrean / Pantau Pembukaan |
| | **Sesi I (Pagi)** | **09:00 – 11:30** | **Pemantauan Pasar Aktif** |
| | Jeda Sholat Jumat | 11:30 – 14:00 | Pasar Tutup Sementara (Jeda Khusus) |
| | **Sesi II (Siang)** | **14:00 – 15:45** | **Pemantauan Pasar Aktif** |
| | Pra-Penutupan & Pasca | 15:45 – 16:15 | Pembentukan Harga Penutupan Resmi |
| **Sabtu – Minggu** | Akhir Pekan | Sepanjang Hari | Tutup Total (Tidak Ada Polling) |
| **Hari Libur BEI** | Libur Nasional | Sepanjang Hari | Tutup Total (Sesuai Kalender Libur) |

---

### 2. Batasan Keterlambatan Data Gratis & Transparansi EOD

1. **Ketersediaan Sumber Data Rp0:**
   - Data pasar bebas biaya yang digunakan saat ini bersumber dari Yahoo Finance v8 (`yfinance`).
   - Yahoo Finance untuk bursa BEI (`.JK`) memiliki keterlambatan minimum **15 menit (delayed 15m)** pada jam bursa berjalan, dan baru menjadi **EOD Final** setelah pukul 16:05 WIB.
2. **Klaim Transparan:**
   - Sistem **secara tegas menolak** menyebut data ini sebagai "realtime tick streaming".
   - Pada antarmuka, laporan, dan bot Telegram, status data selalu ditandai sebagai:  
     `EOD Tracking via Yahoo Finance (15-min delayed / EOD final)`.
   - Pemantauan intraday menit-ke-menit dinyatakan sebagai **keterbatasan operasional** selama sumber data bursa komersial berbayar (IDX Direct Feed) belum dilisensikan.

---

### 3. Logika Evaluasi Target & Batas Pembatal

Pada setiap observasi bar harga penutupan, sistem mengevaluasi sinyal aktif (`monitoring_status = 'ACTIVE'`):

1. **Batas Temporal yang Sah:**
   - Hanya observasi dengan $\text{observation\_time} > \text{published\_at}$ dan $\text{observation\_time} \le \text{expires\_at}$ yang boleh diproses.
   - **Larangan Lookahead:** Dilarang menggunakan nilai *high/low* sebelum waktu publikasi sinyal untuk mengklaim target telah tersentuh sesudahnya.
2. **Penanganan Bar Ambigu (*Ambiguous Hit*):**
   - Jika dalam satu bar harian harga tertinggi menyentuh target ($\text{High} \ge \text{Target}$) DAN harga terendah menyentuh batas pembatal ($\text{Low} \le \text{Invalidation}$), sistem tidak dapat memastikan peristiwa mana yang terjadi lebih dahulu tanpa rekaman mikro-tick.
   - Pada kondisi ini, sistem secara deterministik menandai status sebagai **`AMBIGUOUS`** dan mencatat event `AMBIGUOUS_HIT`. Sinyal ini tidak dihitung sebagai kemenangan (*win*) sepihak.
3. **Prediksi Turun (DOWN) Bukan Short Sell:**
   - Bursa Efek Indonesia adalah pasar berbasis reguler *long-only* (tanpa fasilitas naked short selling bagi investor ritel).
   - Prediksi DOWN berfungsi sebagai sinyal defensif (peringatan mengamankan modal, menunda pembelian, atau merealisasikan keuntungan), bukan instruksi membuka posisi *short* dengan leverage.

---

### 4. Penanganan Pemulihan Sistem & Celah Offline (Offline Gap Recovery)

1. **Deteksi Celah Pemantauan:**
   - Jika mesin server/laptop mati, sleep, atau terputus koneksi internet selama lebih dari 1 hari perdagangan (selisih waktu observasi terakhir $\ge 2$ hari), saat service kembali aktif sistem mendeteksi lonjakan waktu ini.
   - Sistem mencatat event **`GAP_OFFLINE_DETECTED`** di tabel `monitoring_events`.
2. **Larangan Fabrikasi Data:**
   - Sistem **dilarang mengarang atau mensintesis** pergerakan harga intraday fiktif untuk menutupi masa offline.
   - Evaluasi hanya dilanjutkan menggunakan data bar resmi yang benar-benar tercatat di bursa setelah sistem pulih kembali.
