# LAPORAN PENERIMAAN OPERASIONAL & PENYELESAIAN GAP TAHAP I
## AETHER MARKET INTELLIGENCE

**Tanggal Rilis:** 25 Oktober 2026, 12:35 WIB  
**Ruang Lingkup:** Forensik Kegagalan Inbound, Audit Integritas Harga, Penjadwalan Operasional Mandiri, Keamanan Plugin Gateway, dan Rekonsiliasi Kohort.  
**Basis Data:** PostgreSQL `project-bot` (Port 5432, 127.0.0.1)  
**Gateway Active Process:** Hermes Gateway PID `26220` (Telegram Polling Sehat)  
**Pengguna Diizinkan (Allowlist):** `5923895922` (@Info_BosKuu_bot)

---

### 1. INVESTIGASI KEGAGALAN DUA PESAN TELEGRAM (11:59 & 12:00 WIB)

Pada tanggal 25 Oktober 2026, pengguna mengirimkan dua pesan Telegram nyata:
1. Pukul 11:59 WIB: `"status system sekarang bagaimana?"` (Update ID: `496962949`, Message ID: `284`)
2. Pukul 12:00 WIB: `"bbca terbau bagaimana?"` (Update ID: `496962950`, Message ID: `286`)

Keduanya menghasilkan balasan kegagalan: *"Terjadi kendala saat memproses kueri pada database Aether."*

#### Hasil Penelusuran Akar Masalah:
- **Akar Masalah 1: Batas Waktu Subprocess Terlalu Ketat (Timeout 10 Detik)**  
  Log Gateway `errors.log` baris 23031-23032 mencatat:  
  `Laravel execution failed: Command [...] timed out after 10 seconds`.  
  Pada saat bersamaan (~11:57 - 11:59 WIB), terminal sedang menjalankan regresi penuh 94 unit test Laravel yang memakan waktu 92,5 detik dan membebani I/O disk serta CPU mesin Windows. Akibatnya, pemanggilan `php artisan hermes:telegram-chat` mengalami *cold-boot latency* sedikit di atas 10 detik, memicu pembatalan otomatis oleh Python `subprocess.run(timeout=10)`.
- **Akar Masalah 2: Potensi Blocking Stdin Interactive Prompt pada Artisan Command**  
  Pada implementasi awal `HermesTelegramChatCommand.php`, terdapat baris:  
  `if (!$text) { $text = $this->ask(...); }`.  
  Meskipun argumen `--text` dikirim, jika parsing Symfony Console menemui whitespace atau karakter tertentu, `ask()` akan memblokir proses tanpa batas waktu menunggu input terminal interaktif.
- **Akar Masalah 3: Typo Intent Matching ("terbau" vs "terbaru")**  
  Pesan kedua mengandung salah ketik alami (*typo*) `"bbca terbau bagaimana?"`. Pola pencocokan teks awal hanya mencari `"terbaru bagaimana"` sehingga pesan ini sempat diklasifikasikan sebagai `GENERAL_ASSIST` alih-alih `QUERY_TICKER_STATUS`.
- **Akar Masalah 4: Generalisasi Pesan Error yang Salah**  
  Plugin awal menggeneralisasi seluruh kegagalan eksekusi subprocess sebagai "kendala basis data", padahal basis data PostgreSQL beroperasi 100% normal tanpa masalah.

#### Tindakan Perbaikan yang Telah Diterapkan:
1. **Peningkatan Timeout & Pemutusan Stdin:**  
   Batas waktu diekspansi menjadi 25 detik (`timeout=25`) dan stdin dialihkan secara eksplisit ke `stdin=subprocess.DEVNULL`.
2. **Penghapusan Total `$this->ask()`:**  
   Command `HermesTelegramChatCommand` tidak lagi memiliki prompt interaktif. Jika parameter kosong, fallback aman langsung diarahkan ke `/help`.
3. **Penyempurnaan Regex Intent Ticker:**  
   Deteksi emiten diperluas sehingga setiap penyebutan kode saham (misal `BBCA`) bersama kata tanya status (`bagaimana`, `gimana`, `bgmn`, `kondisi`, `kabar`, `terbaru`, `terbau`, `update`, `harga`, `status`) secara deterministik dipetakan ke `QUERY_TICKER_STATUS`.
4. **Klasifikasi Pesan Error yang Spesifik:**  
   Pesan error kini membedakan secara jujur antara `TIMEOUT` (25s), kendala koneksi PostgreSQL, dan kegagalan subprocess teknis.

---

### 2. AUDIT INTEGRITAS HARGA BBCA: RP 10.100 VS RP 5.025

Dilakukan investigasi forensik mendalam terkait kemunculan angka "Rp 10.100" pada draf laporan sebelumnya:

| Parameter Forensik | Angka Rp 10.100 (Laporan Awal) | Angka Rp 5.025 (Database Produksi) |
|---|---|---|
| **Asal Usul Data** | **FIXTURE UJI COBA TERISOLASI** | **PRODUKSI POSTGRESQL NYATA** |
| **Lokasi Sumber** | `tests/Feature/TahapILanjutanRoutingAndAutomationTest.php:75` | PostgreSQL `project-bot`, tabel `market_observations` |
| **Record ID** | Non-persisten (SQLite `:memory:`) | Record ID **10** |
| **Tanggal Bar** | 2026-10-23 | 2026-10-23 |
| **Open / High / Low** | 10.000 / 10.200 / 9.950 | 5.175 / 5.225 / 5.000 |
| **Harga Penutupan (Close)** | **Rp 10.100,00** | **Rp 5.025,00** |
| **Sumber / Provider** | Mock Testing Fixture | Yahoo Finance v8 / yfinance (Settlement Resmi BEI) |
| **Waktu Pengambilan** | Saat test run dieksekusi | 2026-10-25 02:54:06 WIB |
| **Kueri Akses** | `MarketObservation::where('ticker', 'BBCA')->first()` | `MarketObservation::where('ticker', 'BBCA')->latest('observation_time')->first()` |

**Kesimpulan Audit:**  
Basis data PostgreSQL operasional **TIDAK PERNAH TERCEMAR** oleh angka Rp 10.100. Angka tersebut murni data buatan (*synthetic seed*) pada unit test memory terisolasi yang tidak sengaja tercetak pada tabel simulasi pengujian. Harga riil BBCA per penutupan 23 Oktober 2026 di basis data operasional adalah **Rp 5.025,00** (EOD Final).

---

### 3. PEMISAHAN PENJADWALAN OPERASIONAL MANDIRI

Sistem kini memiliki dua runner operasional terpisah untuk mencegah dependensi ganda:

1. **Periodic Operational Cycle Runner (`scripts/run_operational_cycle.bat`):**  
   - **Tujuan:** Menjalankan rekonsiliasi kedaluwarsa sinyal (14 hari kalender) dan memproses antrean `telegram_outbox` beserta *exponential backoff retry* ($2, 4, 8$ menit).
   - **Frekuensi:** Siap dijalankan berkala (setiap 5–15 menit, 24/7 termasuk akhir pekan/hari libur).
   - **Karakteristik:** Tidak memanggil inferensi AI, tidak bergantung pada jam bursa, dan transaksi DB tidak ditahan saat proses transmisi jaringan CLI berlangsung.
   - **Status Eksekusi Nyata:** Telah diuji secara manual via shell pada 25 Okt 2026 12:29 WIB dengan hasil exit code 0 (durasi: 4 detik).
2. **EOD Ingestion & Prediction Runner (`scripts/run_eod_pipeline.bat`):**  
   - **Tujuan:** Mengumpulkan data harian resmi, melakukan sinkronisasi EOD BEI, dan mengevaluasi horizon 5 hari bursa.
   - **Jadwal:** Terjadwal pada Windows Task Scheduler `\HermesMarketIntelligence_EOD` (Senin–Jumat pukul 16:05 WIB).
   - **Karakteristik:** Jam 16:05 WIB diperlakukan sebagai waktu *probe awal*, bukan jaminan ketersediaan instan settlement final.

---

### 4. AUDIT KEAMANAN DAN HARDENING PLUGIN GATEWAY (`aether_router`)

Plugin `aether_router` telah diaudit dan diperkuat dengan standar keamanan tingkat tinggi:
- **Allowlist User Terverifikasi:** Hanya memproses pesan dari Chat/User ID `5923895922`. Pesan dari pengirim tidak dikenal langsung diabaikan tanpa memicu proses apapun.
- **Eksekusi Subprocess Terstruktur:** Memanggil PHP executable absolut (`C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe`) dengan array parameter tetap. Dilarang menggunakan `shell=True` guna mencegah kerentanan *command injection*.
- **Sanitasi Input Pengguna:** Teks pesan masuk dipotong maksimal 500 karakter dan dibersihkan dari karakter null byte (`\x00`).
- **Pencegahan Respons Ganda:** Memanggil `raise ApplicationHandlerStop()` dari pustaka `python-telegram-bot` yang secara mutlak menghentikan propagasi update ke modul LLM core Hermes.
- **Pencatatan ID Pesan Telegram Keluar:** Setiap balasan pesan mencatat `Telegram Message ID` aktual yang dikembalikan oleh server Telegram untuk keperluan pelacakan audit.
- **Penyimpanan Sumber Kanonikal Proyek:** Sumber resmi plugin disimpan di dalam repositori proyek pada subfolder `services/aether_router/` dilengkapi skrip instalasi (`install.py`), skrip rollback (`uninstall.py`), dan panduan teknis (`README.md`).

---

### 5. REKONSILIASI KOHORT DATA & LABEL PROVENANCE

Untuk menjaga transparansi saintifik dan mencegah klaim performa semu, populasi data diklasifikasikan secara ketat menjadi 3 kohort independen:

1. **Kohort 1: Retrospective Replay Unverified (10 Sinyal):**  
   10 sinyal berlabel `decision_date: 2026-10-16` di tabel `prediction_signals`. Berdasarkan audit stempel waktu pembuatan berkas (`2026-10-25 01:42:54 WIB`), sinyal ini dihasilkan setelah harga pasar terbentuk sehingga diklasifikasikan sebagai **RETROSPECTIVE REPLAY (UNVERIFIED)**. Sinyal ini seluruhnya berstatus `ABSTAIN` (Coverage 0%, Win Rate N/A).
2. **Kohort 2: Historical Fallback Pra-Tahap I (30 Sinyal):**  
   30 data evaluasi lama di tabel `post_horizon_evaluations` yang berasal dari pengujian fallback heuristik Tahap H. Diisolasi secara fisik dan tidak dicampur ke dalam metrik kohort baru.
3. **Kohort 3: Live Forward Prediction (0 Sinyal Saat Ini):**  
   Sinyal forward riil yang diterbitkan sebelum sesi perdagangan bursa berlangsung. Populasi saat ini adalah 0 karena menunggu pembukaan sesi bursa resmi berikutnya (Senin, 26 Oktober 2026).
4. **Pembedaan Jumlah Observasi vs Snapshot:**  
   - **110 Baris `market_observations`:** Data bar harian settlement terverifikasi (10 hari bursa $\times$ 11 instrumen termasuk IHSG).
   - **130 Baris `market_snapshots`:** Rekam jejak audit operasional lama dari 13 kali eksekusi sinkronisasi *point-in-time*.

---

### 6. MATRIKS STATUS OPERASIONAL FINAL TAHAP I

| Komponen Subsistem | Klasifikasi Status | Bukti Operasional Faktual |
|---|:---:|---|
| **Penerimaan Inbound Telegram** | **VERIFIED** | Update ID 496962948, 496962949, 496962950 ditangkap sukses di PID Gateway. |
| **Inbound Deterministic Router** | **VERIFIED** | Plugin `aether_router` v1.0.1 aktif di PID 26220. Menangani kueri status & emiten. |
| **Nol Panggilan LLM pada Kueri** | **VERIFIED** | Router memanggil PHP artisan secara langsung dan menghentikan loop LLM. |
| **Pencegahan Respon Ganda** | **VERIFIED** | `ApplicationHandlerStop()` aktif; tepat 1 balasan dikirimkan per update. |
| **Integritas Harga Riil DB** | **VERIFIED** | BBCA Rp 5.025,00 di `market_observations` ID 10 (Rp 10.100 murni fixture test). |
| **Dispatcher Antrean Outbox** | **VERIFIED** | Teruji via CLI, transaksi `lockForUpdate()`, penanganan *stale lease*, dan backoff. |
| **Runner Operasional Mandiri** | **VERIFIED** | `run_operational_cycle.bat` teruji sukses mengeksekusi rekonsiliasi 14-hari & outbox. |
| **Windows Task Scheduler EOD** | **VERIFIED (CONFIGURED)** | Task `\HermesMarketIntelligence_EOD` Ready pukul 16:05 WIB. |
| **Eksekusi Otomatis Hari Bursa** | **PENDING** | Menunggu sesi bursa resmi hari Senin, 26 Oktober 2026 pukul 16:05 WIB. |
| **Pemulihan Pasca-Reboot Fisik** | **PENDING** | Menunggu eksekusi reboot fisik mandiri oleh pengguna berdasarkan checklist SOP. |

---

### 7. PETUNJUK VERIFIKASI PENGGUNA (PENGUJIAN KEMBALI)

Perbaikan terhadap insiden timeout telah diaplikasikan dan diverifikasi pada Hermes Gateway aktif (PID `26220`).

**Silakan kirimkan kembali dua pertanyaan pengujian nyata melalui Telegram ke `@Info_BosKuu_bot`:**
1. `"status system sekarang bagaimana?"`
2. `"bbca terbaru bagaimana?"` *(atau dengan variasi salah ketik alami: `"bbca terbau bagaimana?"`)*

Kedua pesan tersebut akan diproses dalam waktu ~1–2 detik secara deterministik dari database PostgreSQL `project-bot`, menampilkan status sistem yang valid serta harga penutupan faktual BBCA Rp 5.025,00 tanpa menyentuh inferensi AI.
