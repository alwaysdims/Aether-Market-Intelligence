# REGISTRI SUMBER DATA & AUDIT AKSES GRATIS (Rp0)
## AETHER MARKET INTELLIGENCE

Dokumen ini merupakan registri resmi seluruh sumber data pasar modal, ekonomi makro, dan berita finansial yang dievaluasi untuk proyek Aether Market Intelligence. Seluruh sumber data beroperasi di bawah batasan anggaran ketat **Rp0 (Nol Rupiah)** tanpa menggunakan paket berbayar, uji coba dengan kartu kredit, atau API privat broker yang tidak sah.

---

## 1. Matriks Status Operasional Sumber Data

| Nama Sumber | URL Resmi | Jenis Data | Frekuensi | Delay Status | Kebutuhan Key | Status Operasional | Hasil Audit & Probe |
| :--- | :--- | :--- | :--- | :--- | :---: | :---: | :--- |
| **Yahoo Finance (yfinance)** | [help.yahoo.com](https://help.yahoo.com/kb/SLN2352.html) | OHLCV EOD, Dividen, Stock Split, Benchmark ^JKSE | Daily EOD / 1d | 15 menit intraday, final EOD | Tidak | **ENABLED** | **PASS** — Berhasil mengambil bar penutupan 10 ticker IDX dan IHSG serta aksi korporasi historis. |
| **LKBN ANTARA RSS** | [antaranews.com/rss](https://www.antaranews.com/rss) | Berita Finansial & Makroekonomi Indonesia | Real-time Syndication | Real-time | Tidak | **ENABLED** | **PASS** — Feed XML `ekonomi-finansial.xml` valid, menyediakan judul, tanggal publikasi, dan ringkasan. |
| **World Bank API** | [data.worldbank.org](https://data.worldbank.org/) | Indikator Makro Tahunan (Inflasi CPI, Pertumbuhan PDB) | Tahunan | Sesuai vintage rilis | Tidak | **ENABLED** | **PASS** — Endpoint REST HTTPS `api.worldbank.org` aktif dan mengembalikan data deret waktu Indonesia. |
| **GDELT Project (DOC 2.0)** | [gdeltproject.org](https://www.gdeltproject.org/) | Indeks Berita Daring Global & Entitas Saham | 15 Menit | 15 menit web crawl | Tidak | **ENABLED (WITH BACKOFF)** | **CONDITIONAL** — Endpoint aktif, tetapi membutuhkan interval jeda (backoff) agar tidak terkena HTTP 429. |
| **Bursa Efek Indonesia (IDX)** | [idx.co.id](https://www.idx.co.id/) | Profil Emiten, Pengumuman Resmi, Laporan Keuangan | Harian / Event-driven | Disclose-time | Tidak | **MANUAL_IMPORT** | **BLOCKED WAF** — Mengembalikan HTTP 403 Forbidden untuk scraper Python otomatis. Akses resmi via unduhan manual terstruktur. |
| **KSEI Corporate Action** | [web.ksei.co.id](https://web.ksei.co.id/) | Jadwal Dividen Tunai, Rights Issue, RUPS | Event-driven | Event schedule | Tidak | **MANUAL_IMPORT** | **STATEFUL WEB** — Web portal menggunakan session ASP.NET dinamis. Data dividen diakses via Yahoo Finance actions. |
| **Bank Indonesia (BI)** | [bi.go.id](https://www.bi.go.id/) | BI-Rate (7-Day RR), Kurs Referensi JISDOR, SEKI | Bulanan / Harian (16:15 WIB) | Sesuai rilis resmi | Tidak | **MANUAL_IMPORT** | **SOCKET RESET** — Firewall BI memutus koneksi bot scraper (`WinError 10054`). Dikelola via file fixture CSV terstruktur. |
| **BPS Web API** | [webapi.bps.go.id](https://webapi.bps.go.id/) | Inflasi Bulanan, Pertumbuhan PDB, Neraca Perdagangan | Bulanan / Kuartalan | Sesuai jadwal rilis | Ya (Gratis) | **REGISTRATION_REQUIRED** | **KEY REQUIRED** — API resmi mewajibkan App ID / API key gratis. Menunggu registrasi mandiri pengguna. |
| **FRED / ALFRED** | [fred.stlouisfed.org](https://fred.stlouisfed.org/) | Faktor Makro Global (Fed Funds, DXY, US10Y, Minyak) | Harian / Bulanan | Historical Vintages | Ya (Gratis) | **REGISTRATION_REQUIRED** | **KEY REQUIRED** — API resmi aktif, mewajibkan API key 32-karakter gratis. Menunggu registrasi mandiri pengguna. |
| **Otoritas Jasa Keuangan (OJK)** | [ojk.go.id](https://ojk.go.id/) | Siaran Pers Regulasi, Pengawasan Perbankan | Event-driven | Real-time rilis | Tidak | **MANUAL_IMPORT** | **HEAVY HTML** — Portal berbasis ASP.NET tanpa API REST/RSS terstruktur. Diimpor manual untuk siaran berdampak tinggi. |
| **Twelve Data** | [twelvedata.com](https://twelvedata.com/) | Data Saham Internasional | EOD / Real-time | Delayed 1 hari | Ya | **DISABLED** | **CONDITIONAL PAID** — Simbol BEI (XIDX) membutuhkan paket berbayar; tier gratis tidak mendukung feed otomatis BEI. |
| **Alpha Vantage** | [alphavantage.co](https://www.alphavantage.co/) | Data Saham Global | Harian | Delayed | Ya | **DISABLED** | **INADEQUATE** — Tier gratis dibatasi 25 request/hari dan tidak mencakup instrumen IDX (`.JK`) secara konsisten. |

---

## 2. Langkah Pendaftaran Sumber Gratis yang Memerlukan Kunci

Untuk sumber data berstatus `REGISTRATION_REQUIRED`, pengguna dapat mengaktifkannya secara mandiri tanpa biaya:

### A. Pendaftaran BPS Web API (Badan Pusat Statistik)
1. Kunjungi portal resmi BPS Web API: [https://webapi.bps.go.id/developer/](https://webapi.bps.go.id/developer/).
2. Klik tombol **Daftar / Registrasi Akun**.
3. Isi data profil instansi / akademik Anda dan konfirmasi email.
4. Buat aplikasi baru untuk mendapatkan **App ID / Key** (32 karakter).
5. Masukkan kunci ke konfigurasi lingkungan lokal:
   ```ini
   BPS_API_KEY=your_registered_bps_key_here
   ```
   *Catatan: Selama kunci belum diisi, pipeline menggunakan data historis BPS melalui modul fixture terstruktur.*

### B. Pendaftaran FRED API (Federal Reserve Bank of St. Louis)
1. Kunjungi halaman pembuatan akun FRED: [https://fredaccount.stlouisfed.org/apikeys](https://fredaccount.stlouisfed.org/apikeys).
2. Buat akun gratis (menggunakan email institusi atau pribadi).
3. Ajukan permintaan API key untuk keperluan riset kuantitatif / akademik.
4. Salin 32 karakter API Key yang diberikan.
5. Masukkan kunci ke konfigurasi lingkungan lokal:
   ```ini
   FRED_API_KEY=your_32_character_fred_api_key_here
   ```

---

## 3. Kebijakan Integritas & Anti-Fabrikasi Data

1. **Nol Data Sintetis:** Dilarang keras mengisi gap data bursa atau hari libur dengan deret acak sintetis lalu mengklaimnya sebagai observasi historis.
2. **Karantina Data Konflik:** Jika dua sumber memberikan harga penutupan yang berbeda lebih dari 0.5% pada tanggal yang sama, data wajib dikarantina ke direktori `data/quarantine/` untuk diaudit secara manual, bukan dirata-ratakan secara sembunyi-sembunyi.
3. **Pembedaan Status Waktu:** Setiap bar data pasar dan berita mencatat lima stempel waktu:
   - `event_time`: Waktu peristiwa pasar terjadi di BEI (WIB).
   - `published_at`: Waktu data resmi dipublikasikan oleh sumber.
   - `first_seen_at`: Waktu sistem pertama kali mendeteksi/mengunduh data.
   - `fetched_at`: Waktu eksekusi penarikan lokal.
   - `available_at`: Waktu data sah digunakan oleh model prediksi tanpa look-ahead bias.
4. **Pemisahan Bar Intraday vs EOD Final:** Bar harian bursa BEI hanya diklasifikasikan sebagai `end_of_day` setelah pukul 16:00 WIB pada hari bursa resmi kalender BEI. Polling per menit dilarang keras diberi label sebagai "realtime tick".
