# Laporan Pemulihan Resolusi Instrumen Global Aether Market Intelligence

**Status**: RESOLVED & DELIVERED • **Versi**: 3.2.0 • **Tanggal**: 1 November 2026 WIB  
**Kasus**: Regresi Klasifikasi Instrumen FORE (PT Fore Kopi Indonesia Tbk.) pada Job #30

---

## 1. Kronologi & Penyelidikan Insiden Job #30

### a. Input Asli Pengguna & Scope
- **Inbound Message ID**: `546` (Update ID: `496963034`, Job ID: `30`)
- **Teks Prompt Asli**:
  *"Analisis FORE dari fundamental, teknikal, aktivitas broker, dan berita terbaru. Pisahkan penilaian day trade dan swing. Sebutkan tanggal data, informasi yang belum tersedia, serta mana yang berasal dari cache dan pengambilan baru."*
- **Scope yang Diminta**: 4 dimensi analitis (Fundamental, Teknikal, Broker Flow, Berita/Keterbukaan), pemisahan Day Trade vs Swing, dan audit provenance lengkap (cache vs fresh fetch).

### b. Akar Masalah Kegagalan Resolusi
1. **Penyebab Utama**: `BeiUniverseRegistry.php` hanya mencocokkan ticker terhadap array katalog statis `KNOWN_BEI_ISSUERS` dan baris historis yang sudah ada di tabel `market_observations`.
2. **Ketiadaan Discovery Lapisan 2**: Saat sebuah emiten terdaftar resmi di BEI namun belum masuk katalog manual dan belum pernah ditarik bar historisnya, registri langsung mengembalikan error `UNREGISTERED_BEI_TICKER`.
3. **Halusinasi Negatif Model**: Prompt adapter Hermes tidak menyuntikkan data pasar untuk FORE karena ketiadaan data lokal, sehingga model LLM mengklaim bahwa FORE *"tidak terdaftar dalam semesta emiten aktif BEI"* dan *"kemungkinan belum IPO"*.
4. **Fakta Sebenarnya**: FORE adalah **PT Fore Kopi Indonesia Tbk.**, emiten tercatat resmi di BEI yang aktif ditransaksikan pada Pasar Reguler (Board RG) dan memiliki histori harga, ringkasan broker, serta laporan keuangan auditan resmi di penyedia data BEI (`IDX Edge PRO`).

---

## 2. Arsitektur Resolusi Instrumen Berjenjang (Multi-Layer Generic Resolution)

Untuk mencegah edit kode manual pada setiap penambahan emiten baru di BEI, sistem resolusi instrumen kini diperbarui menjadi 3 lapis generik yang berlaku global:

```
[Inbound Prompt / Ticker Candidate]
               │
               ▼
   [Layer 1: Registri Lokal]
   ├── Array KNOWN_BEI_ISSUERS
   ├── Tabel IdxCompanyRegistry (942 Emiten BEI)
   └── Tabel market_observations
         │
         ├──► (Ditemukan) ──► Validasi Sukses (LOCAL_DB_READ / LOCAL_CACHE)
         │
         ▼ (Tidak Ditemukan)
   [Layer 2: Provider Discovery on-demand]
   ├── Panggil IdxEdgeClient::search($ticker) -> GET /api/search?q=...
   ├── Cocokkan exact stock_code, stock_name, dan last_date
   └── Jika cocok: Simpan otomatis ke IdxCompanyRegistry (snapshot_date & verified provenance)
         │
         ├──► (Cocok) ──► Validasi Sukses (PROVIDER_DISCOVERY_VERIFIED)
         │
         ▼ (Pencarian Habis / Kosong)
   [Layer 3: Penolakan Terukur & Sanitasi]
   └── Return UNREGISTERED_BEI_TICKER dengan pesan: "Kode 'XYZ' tidak ditemukan dalam katalog bursa lokal maupun hasil pencarian penyedia data BEI."
```

### Kode yang Dimodifikasi:
1. `config/services.php`:
   - Sub-flag `services.idx_edge.endpoints.search` diaktifkan (`true`).
2. `app/Services/IdxEdgeClient.php`:
   - Penambahan method `search(string $query)` yang memanggil `/api/search` dengan caching 5 menit dan normalisasi respons.
   - Pendaftaran endpoint `search` pada `hasAnyEndpointAllowed()`.
3. `app/Services/BeiUniverseRegistry.php`:
   - Pembaruan method `resolveInstrument($ticker)`: mengecek `IdxCompanyRegistry`, memanggil Layer 2 provider discovery secara on-demand, dan menyimpan profil baru secara persisten.
   - Pembaruan `extractAndNormalizeTicker($text)`: delegasi pencocokan 4 huruf ke resolusi berjenjang.
4. `app/Services/TelegramTwoWayService.php`:
   - Penambahan stopword `NAIK` dan `TURUN` untuk mencegah salah ekstraksi.
   - Evaluasi kandidat 4 huruf mandiri melalui `BeiUniverseRegistry::resolveInstrument()`.
5. `app/Services/HermesConsultationAdapterService.php`:
   - Penyuntikan blok `"=== IDENTITAS RESMI EMITEN ==="` ke prompt input Hermes sebelum penalaran.
   - Penegakan aturan: Larangan keras mengklaim emiten *"tidak terdaftar di BEI"* atau *"belum IPO"* jika data komponen kosong.
6. `app/Services/UnifiedAnalysisRendererService.php`:
   - Penambahan badge status `INSTRUMENT_UNRESOLVED` / `INSTRUMENT_RESOLUTION_FAILED` -> `🔍 KLARIFIKASI CAKUPAN INSTRUMEN`.

---

## 3. Matriks Hasil Komponen FORE yang Berhasil Diperoleh

Setelah identitas FORE terverifikasi sebagai **PT Fore Kopi Indonesia Tbk.**, seluruh komponen analitis berhasil diambil secara otomatis:

| Komponen | Status | Sumber | Periode / Tanggal | Rincian Temuan Kuantitatif |
|---|---|---|---|---|
| **Identitas Emiten** | `AVAILABLE` | `PROVIDER_DISCOVERY_VERIFIED` (IDX Edge) | 2026-10-30 | PT Fore Kopi Indonesia Tbk. (Papan Reguler BEI). |
| **Harga EOD & OHLC** | `AVAILABLE` | `IDX Edge PRO` (Sinkronisasi Otomatis) | 30 Okt 2026 | Open: Rp 565, High: Rp 565, Low: Rp 530, Close: **Rp 545** (-3,54%), Volume: 3.011.500 lembar. |
| **Fundamental** | `AVAILABLE` | `IDX Edge PRO` (Laporan Keuangan Tahunan) | FY 2025 & FY 2024 | FY 2025: Pendapatan Rp 1.497,13 M, Laba Bersih Rp 90,13 M, Ekuitas Rp 680,59 M, EPS Rp 10,95, PER 49,77x, PBV 7,14x. |
| **Broker Flow** | `AVAILABLE` | `IDX Edge PRO` (Board RG 3 Bulan) | 01 Agu – 01 Nov 2026 | **Top Net Buyers**: ZP (+Rp 15,21 M / 18,11M lbr), KI (+Rp 11,07 M / 14,76M lbr), YU (+Rp 10,06 M / 13,58M lbr).<br>**Top Net Sellers**: XL (-Rp 16,11 M / 21,09M lbr), HP (-Rp 8,44 M / 10,54M lbr), CC (-Rp 5,54 M / 7,19M lbr). |
| **Berita & Filing** | `AVAILABLE_EMPTY` | PostgreSQL Antara News & Document Chunks | 01 Nov 2026 | 0 dokumen spesifik dalam 7 hari terakhir (dilaporkan transparan tanpa fabrikasi). |
| **Day Trade** | `NOT_ASSESSABLE` | Penegakan Kontrak Sistem | Real-time | *Belum cukup data untuk menentukan entry day trade.* (Feed intraday Pasar Reguler berstatus QUARANTINED). |
| **Swing Trade** | `PARTIAL` | Metodologi Kuantitatif Aether | Multi-sesi | *Penilaian swing masih terbatas.* (Harga Rp 545 berada di bawah Gross Buy Avg Top Buyer Rp 745–809, belum ada pembalikan arah terkonfirmasi). |

---

## 4. Eksekusi Pemulihan Job #30

- **Job Pemulihan**: Job ID `#34` (mereferensikan Job ID `#30` dan Reply-To Message ID `#548`).
- **Pelaksana**: Worker otomatis OS Windows Task Scheduler (`\AetherScheduledDispatcher_1min`).
- **Status Akhir Job #34**: **`COMPLETED`**.
- **Outbox Terbit**: Outbox ID `#67` (`status: SENT`, terkirim otomatis ke Telegram).
- **Hasil Jawaban Telegram**:
  1. Mengakui secara eksplisit identitas resmi **PT Fore Kopi Indonesia Tbk. (FORE)**.
  2. Mengoreksi status listing sebelumnya yang keliru menyatakan belum terdaftar.
  3. Memaparkan seluruh 4 dimensi data faktual (EOD Close Rp 545, Fundamental FY 2025 Laba Rp 90,13 M, Top Broker 3 Bulan ZP/KI/YU vs XL/HP/CC).
  4. Memisahkan Day Trade (*Belum cukup data*) dan Swing Trade (*Penilaian swing masih terbatas*).
  5. Melampirkan audit provenance lengkap: EOD dari cache lokal, Broker Summary dari fresh query API IDX Edge, Laporan Keuangan dari database resmi.

---

---

## 6. Audit Observation ID #206335 & Konsistensi Sumber Global

### a. Rincian Observation ID #206335
- **Ticker**: `FORE`
- **Bar Date**: `2026-10-30` (Sesi bursa terakhir sebelum libur akhir pekan)
- **Data Source Asli**: `IDX Edge PRO` (sinkronisasi on-demand resmi Pasar Reguler / Board RG).
- **Validation Status**: `VALID`.
- **Adjustment**: `PROVIDER_BACK_ADJUSTED`.
- **Input Histori**: 20 bar harian dari `2026-10-05` s/d `2026-10-30`.
- **Koreksi Narasi Campuran**: Frasa `"API IDX Edge / PostgreSQL project-bot via Yahoo Finance"` dikoreksi total. Sumber operasional adalah **`IDX Edge PRO`** dan basis data lokal PostgreSQL `project-bot`. Narasi usang Yahoo Finance dihapus dari seluruh pipeline operasional aktif.

### b. Audit Metrik Finansial & Rasio Fundamental
- **Klasifikasi Data**: Berstatus **`PROVIDER_SUPPLIED`** (belum berstatus `DOCUMENT_VERIFIED` karena belum ada berkas fisik PDF laporan tahunan di storage).
- **Rasio Solvabilitas**:
  * Nilai: **0,32x**.
  * Label Akuntansi Resmi: **`TOTAL_LIABILITIES_TO_TOTAL_EQUITY`** (Rasio Total Liabilitas terhadap Total Ekuitas Induk).
  * Bukan rasio utang berbunga murni (*Interest-Bearing Debt to Equity*).
- **Rekonsiliasi Valuasi (Harga Sesi 30 Okt 2026 vs Lapkeu FY 2025)**:
  * Harga Penutupan: **Rp 545**.
  * EPS FY 2025: **Rp 10,95**.
  * PER Faktual: Rp 545 / Rp 10,95 = **49,77x**.
  * Ekuitas Induk FY 2025: Rp 680.590.873.344.
  * Saham Beredar: 8.918.000.000 lembar.
  * BVPS: Rp 76,31.
  * PBV Faktual: Rp 545 / Rp 76,31 = **7,14x**.

### c. Perhitungan Deterministik Posisi Candle Harian
- **Data Bar 30 Okt 2026**: Open Rp 565, High Rp 565, Low Rp 530, Close Rp 545.
- **Rentang Bar (High - Low)**: Rp 565 - Rp 530 = **Rp 35**.
- **Jarak dari Low (Close - Low)**: Rp 545 - Rp 530 = **Rp 15**.
- **Posisi Relatif dalam Rentang**: $(15 / 35) \times 100\% = \mathbf{42,86\%}$.
- **Koreksi Interpretasi**: Penutupan berada pada area **42,86% dari rentang harian** (area netral-bawah), BUKAN *"menutup mendekati low"* secara ekstrem. Satu bar candle ini tidak dapat disimpulkan sebagai tren distribusi multi-sesi tanpa bukti lebih lanjut.

### d. Batasan Interpretasi Gross Buy Average Broker
- **Top Buyer 3 Bulan**: ZP (Gross Buy Avg Rp 809), KI (Gross Buy Avg Rp 751), YU (Gross Buy Avg Rp 745).
- **Aturan Penafsiran Resmi**:
  * Gross Buy Avg adalah rata-rata harga beli kotor seluruh transaksi broker dalam rentang 3 bulan, **BUKAN cost basis inventori bersih yang masih tersisa**.
  * Selisih harga pasar (Rp 545) terhadap rata-rata beli broker **tidak boleh dijadikan bukti valuasi murah, level support, maupun alasan utama kelayakan swing trade**.
  * Perantara efek mewakili banyak nasabah dan order flow yang dinamis.

### e. Klasifikasi Sumber Berita & Keterbukaan
- **Pemisahan Sumber**:
  * `LOCAL_DB_READ`: Pembacaan data tabel lokal `news_items` dan `document_chunks`.
  * `LOCAL_CACHE`: Penggunaan cache Redis/file terikat TTL.
  * `EXTERNAL_HTTP_FETCH`: Panggilan on-demand langsung ke API eksternal terotorisasi.
- **Transparansi Ketiadaan Berita**: Hasil penelusuran dilaporkan sebagai *"Tidak ditemukan pada sumber yang diperiksa (PostgreSQL Antara News & Indeks Dokumen BEI)"*, bukan klaim absolut bahwa *"tidak ada berita di dunia luar"*.

### f. Pemisahan Periode Diminta vs Periode Faktual
- **Requested Period**: 01 Agustus 2026 s/d 01 November 2026.
- **Actual Observed Period**: 01 Agustus 2026 s/d 30 Oktober 2026 (Sesi bursa Jumat terakhir; 31 Oktober dan 01 November adalah hari libur bursa tanpa transaksi perdagangan).

---

---

## 8. Rekonsiliasi Valuasi & Pemisahan Metrik Saham Global

### a. Pemisahan 4 Komponen Saham & Laba
Untuk seluruh emiten di BEI (berlaku generik tanpa percabangan khusus FORE), sistem memisahkan secara ketat empat entitas data:
1. **`reported_basic_eps`**: Laba per saham dasar yang dilaporkan emiten dalam laporan laba rugi resmi (misal FORE FY 2025: **Rp 10,95** per saham pada operasi yang dilanjutkan).
2. **`weighted_average_shares`**: Rata-rata tertimbang jumlah saham beredar sepanjang periode pelaporan fiskal (dasar kalkulasi EPS dasar sesuai PSAK).
3. **`ordinary_shares_period_end`**: Jumlah saham biasa yang beredar pada akhir periode atau tanggal snapshot registri (misal FORE per 26/30 Okt 2026: **8.918.000.000 lembar**), yang menjadi dasar perhitungan BVPS dan kapitalisasi pasar.
4. **`net_income_attributable_to_parent`**: Laba bersih periode berjalan yang diatribusikan secara spesifik kepada entitas induk (misal FORE FY 2025: **Rp 90.133.042.112**).

### b. Rekonsiliasi Matematika EPS FORE (Rp 10,11 vs Rp 10,95)
- **Kalkulasi Naif (Pembagian Saham Akhir Periode)**:
  $$\text{EPS Naif} = \frac{\text{Laba Induk Rp } 90.133.042.112}{8.918.000.000 \text{ lembar saham akhir periode}} = \mathbf{Rp\ 10,11}$$
- **Asal Angka Rp 10,95**:
  Merupakan **`reported_basic_eps`** resmi yang dilaporkan emiten pada laporan laba rugi auditan FY 2025 (`PROVIDER_SUPPLIED` via API IDX Edge PRO).
- **Implikasi Saham Rata-rata Tertimbang (*Weighted Average Shares*)**:
  $$\text{Rata-rata Tertimbang Saham Implisit} = \frac{\text{Rp } 90.133.042.112}{\text{Rp } 10,95} \approx \mathbf{8.231.328.047\ \text{lembar}}$$
  Selisih antara 8,231 Miliar lembar (rata-rata tertimbang) dan 8,918 Miliar lembar (akhir periode) mencerminkan adanya aksi korporasi/penerbitan saham baru (IPO) yang terjadi di tengah tahun buku 2025, sehingga jumlah saham beredar meningkat menjelang penutupan periode.
- **Aturan Sistem**: Sistem **tidak mengganti** reported EPS dasar resmi menggunakan jumlah saham akhir periode, dan selalu mencantumkan basis rujukan (`[Basis: REPORTED_INCOME_STATEMENT, Periode: FY 2025]`).

### c. Penegakan Basis PER dan BVPS/PBV
- **Price-to-Earnings Ratio (PER)**:
  * Wajib menyebut basis EPS dan harga sesi yang dipakai: `P/E Sesi 30 Okt 2026 vs EPS FY 2025`.
  * Formula: $\text{Rp } 545 / \text{Rp } 10,95 = \mathbf{49,77x}$.
- **Book Value Per Share (BVPS) & Price-to-Book Value (PBV)**:
  * Wajib mencatat atribusi ekuitas dan tanggal snapshot saham:
    $$\text{BVPS} = \frac{\text{Ekuitas Entitas Induk FY 2025 (Rp } 680.590.873.344\text{)}}{\text{Saham Akhir Periode (}8.918.000.000\text{ lembar)}} = \mathbf{Rp\ 76,32}$$
  * Formula PBV: $\text{Rp } 545 / \text{Rp } 76,32 = \mathbf{7,14x}$ (`[Basis: P/B Sesi 30 Okt 2026 vs BVPS per 30 Okt 2026]`).
  * Jika basis ekuitas atau tanggal saham belum diverifikasi, status wajib dilabeli **`PROVISIONAL`** atau **`N/A`**.

### d. Verifikasi Arti `PROVIDER_BACK_ADJUSTED` vs Harga Nominal
- Nilai `PROVIDER_BACK_ADJUSTED` pada kolom `adjustment_mode` di `market_observations` menandakan bahwa seri harga historis masa lalu telah disesuaikan oleh penyedia data untuk aksi korporasi (seperti *stock split* atau dividen saham) guna menjaga kontinuitas kalkulasi return persentase dan indikator teknikal (SMA/RSI/MACD).
- **Pemisahan Ketat**:
  1. Harga penutupan terkini (misal Rp 545) adalah **harga nominal aktual sesi bursa** yang digunakan untuk fraksi tick (Rp 5) dan kalkulasi lot transaksi (1 lot = Rp 54.500).
  2. Dilarang mencampuradukkan harga yang di-adjust dengan jumlah saham beredar nominal atau EPS nominal.

---

## 9. Status Keterbatasan yang Belum Terselesaikan

1. **Feed Intraday**: Quote intraday Pasar Reguler (`/api/price`) tetap berada dalam status **`QUARANTINED`** karena kontaminasi transaksi Pasar Negosiasi (NG). Keputusan Day Trade tetap berstatus **`DAY_TRADE_NOT_ASSESSABLE_WITH_AVAILABLE_DATA`**.
2. **Model Machine Learning**: Model inferensi ML terkalibrasi out-of-sample saat ini terbatas pada 10 emiten konstituen awal (`BBCA`, `BBRI`, `BMRI`, `BBNI`, `ASII`, `TLKM`, `MAPI`, `ARTO`, `MEDC`, `SMRA`). Emiten lain (termasuk FORE) berstatus **`MODEL_NOT_VALIDATED_FOR_INSTRUMENT`**.
3. **Dokumen Primer PDF**: Emiten di luar pilot fisik (seperti FORE, BNBR, BULL) belum memiliki file PDF auditan lokal di storage, sehingga status fundamental tetap dibatasi pada **`PROVIDER_SUPPLIED`**.



