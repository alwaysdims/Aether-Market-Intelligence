# PROTOKOL RISET & VALIDASI SCREENING KUANTITATIF (FORWARD STUDY)
## AETHER MARKET INTELLIGENCE — STRATEGI SWING V1.0.0-FROZEN

**Tanggal Pembekuan Parameter Awal**: 26 Oktober 2026, 19:30:00 WIB  
**Tanggal Penyempurnaan Protokol**: 26 Oktober 2026, 20:30:00 WIB  
**Versi Strategi**: `SWING_FILTER_V1` (`v1.0.0-frozen` / refinement `v1.0.1`)  
**Status Evaluasi**: **FORWARD OBSERVATION ACTIVE (MENUNGGU OUTCOME T+5)**  
**Workspace**: `E:\Aether-Market-Intelligence`

---

## 1. Kronologi Pembekuan Protokol & Hash Konfigurasi (Append-Only)

Sesuai prinsip integritas audit, perubahan aturan dicatat secara *append-only* tanpa pemunduran timestamp:

1. **Versi 1.0.0-draf** (26 Oktober 2026, 17:30:00 WIB):
   - Draf aturan kuantitatif awal: Likuiditas volume $\ge 10$M, rentang bar 1.5%–6.5%, perubahan Close vs Open $\ge -0.5\%$.
   - Keterbatasan draf: Masih menyertakan simbol indeks acuan (`^JKSE`) dan terminologi subjektif *"tekanan jual dominan"*.
2. **Versi 1.0.0-frozen** (26 Oktober 2026, 19:30:00 WIB):
   - Pembekuan kandidat dan kriteria: Isolasi ketat indeks acuan, netralisasi terminologi arah penutupan relatif terhadap pembukaan, penautan evidence ID (Obs ID, Metric ID, timestamp indikator), dan isolasi inferensi ML kadaluarsa (status `N/A`).
   - Sebanyak 13 kandidat resmi dibekukan.
   - **Hash Konfigurasi Seleksi**: `sha256("EQUITY|VOL_10M|RANGE_1.5_6.5|CHG_OPEN_-0.5")` $\to$ **`c51fa69e06b9b32c`**.
3. **Versi 1.0.1-refinement** (26 Oktober 2026, 20:30:00 WIB):
   - Penyempurnaan protokol evaluasi forward: Pemisahan tegas dua keluaran (Studi Sentuhan Barrier Harga A vs Simulasi Strategi Transaksi B).
   - Penambahan rumus fraksi harga resmi BEI ($P_{\text{target\_tick}}$), identifikasi status batas khusus `ZERO_TOLERANCE_INVALIDATION` pada BCAP ($Close = Low$), audit keterlambatan feed yfinance pada bar 23-Okt (PTRO, SMRA, TLKM), dan penerapan status transisi `WAITING_FOR_FINAL_DATA` berbasis kalender resmi bursa `BeiCalendarService`.
   - **Hash Konfigurasi Evaluasi**: `sha256("SWING_V1_STUDY_A_TOUCH_B_EXEC_TICK_BEI_FEE_0.80")` $\to$ **`7e4d82b1fa90c37e`**.

---

## 2. Kontrak Screening & Pemisahan Dua Keluaran

Sistem membedakan secara tegas dua keluaran evaluasi:

### A. Output A: Studi Sentuhan Barrier Harga (Price Barrier Touch Study) — [STATUS: AKTIF]
- **Tujuan**: Menguji secara matematis apakah distribusi harga acuan menyentuh resisten $High \times 1.01$ atau menembus support $Low$ dalam horizon 5 hari bursa.
- **Level Harga**: Ambang desimal kontinu diperbolehkan ($P_{\text{target\_math}}$).
- **Pelaporan**: Hasil dilaporkan murni sebagai **hasil bruto (gross return)** dan status biner barrier (*TOUCHED_TARGET*, *TOUCHED_STOP*, *AMBIGUOUS*, *UNTOUCHED*).
- **Asumsi Eksekusi**: Nol asumsi keterisian order (order fill rate) dan nol asumsi antrean bid/ask.

### B. Output B: Simulasi Strategi dengan Entry/Exit (Execution Simulation) — [STATUS: DOKUMENTASI LENGKAP, RETURN NETO DITAHAN]
Sebelum outcome terjadi, seluruh aturan simulasi transaksi didokumentasikan secara formal:
1. **Waktu dan Harga Entry**:
   - Order beli limit dipasang pada harga pembukaan sesi berikutnya ($Open_{T+1}$, 27 Oktober 2026, 09:00:00 WIB).
   - **Posisi HANYA TERBENTUK JIKA**: Harga transaksi pasar sesi $T+1$ menjangkau harga Open ($Low_{T+1} \le Open_{T+1} \le High_{T+1}$).
2. **Pembatalan Sebelum Entry (Cancel Before Entry)**:
   - Jika terjadi *gap up* pembukaan pada $T+1$ di mana $Open_{T+1} \ge P_{\text{target\_tick}}$, setup otomatis **DIBATALKAN** (`MISSED_SETUP_GAP_UP`).
   - **Aturan Posisi Kosong**: Jika order beli belum terisi atau dibatalkan sebelum entry, **DILARANG KERAS** mencatat kerugian cutloss atau exit apapun.
3. **Penanganan Gap Down Pembukaan**:
   - Jika posisi sudah terbentuk pada $T+1$, kemudian pada sesi berikutnya terjadi *gap down* pembukaan di bawah stop ($Open_{T+k} \le P_{\text{invalidation}}$), posisi di-cutloss langsung pada harga $Open_{T+k}$ (slippage gap ditanggung penuh).
4. **Exit Target & Invalidation**:
   - Target Exit: Order jual limit pasif pada fraksi resmi BEI ($P_{\text{target\_tick}}$).
   - Invalidation Exit: Order stop market dieksekusi saat $Low \le P_{\text{invalidation}}$.
5. **Akhir Horizon**:
   - Jika hingga penutupan sesi $T+5$ (02 November 2026, 16:00:00 WIB) neither target nor stop tersentuh, posisi dilikuidasi pada harga penutupan sesi ($Close_{T+5}$).
6. **Asumsi Biaya Skenario**:
   - Ditetapkan **0.80% round-trip**: Biaya transaksi komisi broker & pungutan bursa (0.40%) + estimasi slippage spread (0.40%). Label ini wajib dinyatakan sebagai **asumsi skenario**, BUKAN biaya universal.
7. **Keterbatasan Mekanisme Pasar & Papan BEI**:
   - Pembulatan ke fraksi harga resmi BEI **BELUM MEMBUKTIKAN** order dapat dieksekusi di pasar nyata (*order fill certainty*).
   - Transaksi di bursa membutuhkan likuiditas antrean bid/ask pada papan reguler. Untuk saham pada papan pemantauan khusus (FCA / Full Periodic Call Auction), fraksi tick dan mekanisme pencocokan berbeda.
   - **Penetapan Sikap**: Selama forward study ini berjalan, **RETURN TRANSAKSI NETO SIMULASI B TIDAK AKAN DIKLAIM SEBAGAI REALISASI FINANSIAL**. Evaluasi kuantitatif utama difokuskan pada **Output A (Studi Sentuhan Barrier Bruto)**.

---

## 3. Kriteria Filter Beku (Frozen Filter Rules)

Parameter filter dibekukan pada versi `v1.0.0-frozen` dan tidak boleh diubah setelah melihat pergerakan harga:

| Parameter | Ambang Batas | Rumus / Definisi | Rasional Parameter |
| :--- | :--- | :--- | :--- |
| **Eligibilitas Instrumen** | Saham Ekuitas BEI | `instrument_type = 'EQUITY'` (Eksklusif `^JKSE`, `JKSE`, `IHSG`) | Menghindari anomali perputaran indeks komposit pada universe saham ekuitas. |
| **Kecukupan Data** | Bar EOD Final Tersimpan | `status = 'EOD_FINAL'`, bar pasar $\ge 1$ sesi | Menjamin verifikasi berbasis data tersimpan di basis data. |
| **Filter Aktivitas Volume** | $\ge 10.000.000$ lembar | $Volume \ge 10.000.000$ | Memastikan keaktifan transaksi pasar untuk penyerapan likuiditas posisi swing. |
| **Rentang Volatilitas Bar** | $1.5\% \le \text{Range} \le 6.5\%$ | $\frac{High - Low}{Close} \times 100$ | Mengeliminasi saham tidur ($<1.5\%$) dan anomali volatilitas ekstrem ($>6.5\%$). |
| **Arah Penutupan vs Open** | $\ge -0.5\%$ | $\frac{Close - Open}{Open} \times 100 \ge -0.5\%$ | Memfilter bar penutupan yang tertekan tajam di bawah harga pembukaan. |

---

## 3. Audit Formula Target, Invalidation, dan Pembulatan Fraksi Harga BEI

### A. Rumus Baku
1. **Target Ambang Matematis ($P_{\text{target\_math}}$)**:
   $$P_{\text{target\_math}} = High \times 1.01$$
   *Digunakan khusus untuk studi sentuhan level harga (continuous barrier).*
2. **Target Order Tick Transaksi ($P_{\text{target\_tick}}$)**:
   $$P_{\text{target\_tick}} = \text{CeilToBeiTick}(High \times 1.01)$$
   *Dibulatkan ke atas sesuai peraturan fraksi harga BEI No. II-A:*
   - Harga $< \text{Rp } 200$: Fraksi Rp 1
   - Harga $\text{Rp } 200 \le P < \text{Rp } 500$: Fraksi Rp 2
   - Harga $\text{Rp } 500 \le P < \text{Rp } 2.000$: Fraksi Rp 5
   - Harga $\text{Rp } 2.000 \le P < \text{Rp } 5.000$: Fraksi Rp 10
   - Harga $\ge \text{Rp } 5.000$: Fraksi Rp 25
3. **Batas Pembatalan / Invalidation ($P_{\text{invalidation}}$)**:
   $$P_{\text{invalidation}} = Low$$
   *Level harga terendah dari candle acuan pembentukan kohort.*

### B. Bukti Matematis Target Seluruh Kandidat
Semua target di bawah ini dihitung murni dari harga $High$ candle acuan sebelum outcome terjadi:
- **AMRT**: $High = 1.090 \implies 1.090 \times 1.01 = 1.100,90$. Fraksi Rp 5 $\implies P_{\text{target\_tick}} = 1.105,00$.
- **ASII**: $High = 4.580 \implies 4.580 \times 1.01 = 4.625,80$. Fraksi Rp 10 $\implies P_{\text{target\_tick}} = 4.630,00$.
- **AYAM**: $High = 500 \implies 500 \times 1.01 = 505,00$. Fraksi Rp 5 $\implies P_{\text{target\_tick}} = 505,00$.
- **BAPI**: $High = 18 \implies 18 \times 1.01 = 18,18$. Fraksi Rp 1 $\implies P_{\text{target\_tick}} = 19,00$.
- **BBNI**: $High = 3.470 \implies 3.470 \times 1.01 = 3.504,70$. Fraksi Rp 10 $\implies P_{\text{target\_tick}} = 3.510,00$.
- **BBRI**: $High = 2.920 \implies 2.920 \times 1.01 = 2.949,20$. Fraksi Rp 10 $\implies P_{\text{target\_tick}} = 2.950,00$.
- **BCAP**: $High = 22 \implies 22 \times 1.01 = 22,22$. Fraksi Rp 1 $\implies P_{\text{target\_tick}} = 23,00$.
- **BMBL**: $High = 28 \implies 28 \times 1.01 = 28,28$. Fraksi Rp 1 $\implies P_{\text{target\_tick}} = 29,00$.
- **BMRI**: $High = 3.920 \implies 3.920 \times 1.01 = 3.959,20$. Fraksi Rp 10 $\implies P_{\text{target\_tick}} = 3.960,00$.
- **BRIS**: $High = 1.070 \implies 1.070 \times 1.01 = 1.080,70$. Fraksi Rp 5 $\implies P_{\text{target\_tick}} = 1.085,00$.
- **PTRO**: $High = 3.140 \implies 3.140 \times 1.01 = 3.171,40$. Fraksi Rp 10 $\implies P_{\text{target\_tick}} = 3.180,00$.
- **SMRA**: $High = 266 \implies 266 \times 1.01 = 268,66$. Fraksi Rp 2 $\implies P_{\text{target\_tick}} = 270,00$.
- **TLKM**: $High = 2.250 \implies 2.250 \times 1.01 = 2.272,50$. Fraksi Rp 10 $\implies P_{\text{target\_tick}} = 2.280,00$.

---

## 5. Protokol Waktu, Kalender Bursa, dan Aturan Evaluasi Prospektif

1. **Waktu Pembentukan Kohort**: **26 Oktober 2026, 19:30:00 WIB** (Pasar BEI tutup).
2. **Awal Observasi Outcome**: **27 Oktober 2026, 09:00:00 WIB** (Sesi pembukaan bursa $T+1$ berikutnya).
   - **ATURAN ANTI-HINDSIGHT KETAT**:
     Pergerakan harga pada 26 Oktober sebelum 19:30 WIB adalah **data seleksi**, BUKAN outcome forward. Kohort 26 Oktober dilarang keras mengklaim keberhasilan dari candle 26 Oktober itu sendiri.
     Untuk 3 kandidat dengan bar acuan 23 Oktober 2026 (SMRA, TLKM, PTRO): Karena kohort dibekukan pada 26 Oktober 19:30 WIB, pergerakan sesi 26 Oktober TIDAK BOLEH diklaim sebagai forward outcome retrospektif. Observasi outcome forward untuk seluruh 13 kandidat resmi dimulai serentak pada sesi **27 Oktober 2026 ($T+1$)**!
3. **Audit Khusus Bar 23 Oktober untuk PTRO, SMRA, dan TLKM**:
   - **Pemisahan Keterlambatan Sumber vs Ketiadaan Transaksi**: Ketiga emiten (PTRO, SMRA, TLKM) adalah saham likuid yang aktif ditransaksikan di BEI pada hari Senin 26 Oktober 2026 (bukan saham suspensi atau libur). Bar 23 Oktober tercatat karena antrean batch ingestion yfinance memproses emiten secara alfabetis/ID, dan pada saat kohort dibekukan (19:30 WIB) runner batch baru menyelesaikan emiten berawalan A-B.
   - **Keputusan Integritas**: Input bar 23 Oktober untuk ketiga emiten ini **TETAP DIPERTAHANKAN** sesuai snapshot pembekuan (tidak diubah atau dimanipulasi setelah melihat hasil).
   - **Aturan Freshness Eksplisit untuk Kohort Berikutnya**: Mulai kohort berikutnya, diberlakukan aturan batas basi ketat:
     `max_staleness_trading_days = 0` (hanya bar penutupan $T_0$ hari evaluasi yang valid dimasukkan ke kohort baru; bar yang lebih tua otomatis didiskualifikasi).
4. **Kalender Bursa & Perbedaan Deadline Evaluasi vs Ketersediaan Data Final**:
   - **Kalender Resmi**: Evaluasi menggunakan `BeiCalendarService` yang mencakup hari libur resmi BEI 2026 dan aturan penutupan pasar 16:00 WIB.
   - **Horizon Evaluasi**: **5 Hari Bursa** ($T+1$ s/d $T+5$):
     • $T+1$: Selasa, 27 Oktober 2026
     • $T+2$: Rabu, 28 Oktober 2026
     • $T+3$: Kamis, 29 Oktober 2026
     • $T+4$: Jumat, 30 Oktober 2026
     • Akhir Pekan (31 Okt - 01 Nov): Pasar tutup (non-trading).
     • $T+5$: Senin, 02 November 2026.
   - **Deadline Kalender Evaluasi**: **02 November 2026, 16:00:00 WIB**.
   - **Penanganan Finalitas Data (Data Finality Policy)**:
     Saat deadline kalender 02 November 16:00 WIB tercapai, sistem **TIDAK BOLEH** memaksakan outcome prematur jika bar pasar $T+5$ dari feed belum terunduh atau belum terverifikasi `EOD_FINAL`.
     Status evaluasi secara resmi bertransisi ke **`WAITING_FOR_FINAL_DATA`** sampai bar penutupan final terverifikasi masuk ke PostgreSQL.
5. **Perlakuan Kasus Khusus (Edge Cases)**:
   - **Gap Harga (Price Gap)**:
     • *Gap Up pada $T+1$ ($Open_{T+1} \ge P_{\text{target}}$)*: Pada studi sentuhan harga, target tercapai. Pada simulasi transaksi, order dibatalkan (`GAP_UP_CANCELLED`) karena harga pembukaan melampaui rasio risk/reward yang wajar.
     • *Gap Down pada $T+1$ ($Open_{T+1} \le P_{\text{invalidation}}$)*: Pada simulasi transaksi, posisi langsung di-cutloss pada harga $Open_{T+1}$ (slippage gap ditanggung penuh).
   - **Batas Batal Sama dengan Harga Acuan ($Close = Low$)**:
     • Kandidat **BCAP** memiliki $Close = 21,00$ dan $Low = 21,00$. Karena batas batal berhimpitan dengan harga penutupan acuan, posisi ini berstatus **`ZERO_TOLERANCE_INVALIDATION`** dan didiskualifikasi dari simulasi transaksi karena tidak memiliki buffer toleransi fluktuasi normal.
   - **Aksi Korporasi (Corporate Actions)**:
     • Jika terjadi Stock Split, Reverse Split, Rights Issue, atau Dividen Tunai selama horizon 5 hari, harga target dan invalidation disesuaikan proporsional menggunakan faktor penyesuaian resmi BEI.
   - **Data Hilang / Suspensi Bursa (Missing Data / Suspension)**:
     • Jika emiten mengalami suspensi bursa $\ge 3$ hari berturut-turut dalam horizon 5 hari, observasi dicatat **`SUSPENDED_UNRESOLVED`**.
   - **Candle Ambigu (Ambiguous Candle)**:
     • Jika dalam satu bar harian $T+k$, $High \ge P_{\text{target}}$ DAN $Low \le P_{\text{invalidation}}$ secara simultan, hasil dicatat sebagai **`AMBIGUOUS`**. Dilarang mengasumsikan target tercapai lebih dahulu daripada stop tanpa bukti rekaman urutan intraday (tick/order book).

---

## 6. Kohort Kandidat Lolos Filter (Cohort 26 Oktober 2026, 19:30 WIB)

Populasi dievaluasi: **175 emiten aktif berharga EOD** dari total 941 saham tercatat BEI.  
Lolos filter: **13 emiten**. Sebanyak 162 emiten lainnya tidak lolos.

| No | Ticker | Bar Date | Obs ID | Open | High | Low | Close | Volume | Metric ID | Dihitung Pada | Target Math | Target Tick | Invalidation | Status Kasus Batas |
| :---: | :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :--- |
| 1 | **AMRT** | 2026-10-26 | 9411 | 1.050 | 1.090 | 1.045 | 1.085 | 25.398.100 | 46 | 2026-10-26 04:49 | 1.100,90 | 1.105,00 | 1.045,00 | Standar |
| 2 | **ASII** | 2026-10-26 | 15842 | 4.540 | 4.580 | 4.490 | 4.560 | 35.419.700 | 73 | 2026-10-26 09:34 | 4.625,80 | 4.630,00 | 4.490,00 | Standar |
| 3 | **AYAM** | 2026-10-26 | 18949 | 494 | 500 | 488 | 496 | 39.114.700 | 86 | 2026-10-26 09:55 | 505,00 | 505,00 | 488,00 | Standar |
| 4 | **BAPI** | 2026-10-26 | 21339 | 18 | 18 | 17 | 18 | 18.479.100 | 96 | 2026-10-26 10:11 | 18,18 | 19,00 | 17,00 | Standar |
| 5 | **BBNI** | 2026-10-26 | 23688 | 3.420 | 3.470 | 3.350 | 3.470 | 51.412.400 | 105 | 2026-10-26 10:29 | 3.504,70 | 3.510,00 | 3.350,00 | Standar |
| 6 | **BBRI** | 2026-10-26 | 23917 | 2.900 | 2.920 | 2.830 | 2.910 | 252.788.200 | 106 | 2026-10-26 10:29 | 2.949,20 | 2.950,00 | 2.830,00 | Standar |
| 7 | **BCAP** | 2026-10-26 | 25351 | 21 | 22 | 21 | 21 | 38.895.500 | 112 | 2026-10-26 10:39 | 22,22 | 23,00 | 21,00 | **ZERO_TOLERANCE_INVALIDATION** |
| 8 | **BMBL** | 2026-10-26 | 33950 | 28 | 28 | 27 | 28 | 11.152.400 | 148 | 2026-10-26 11:50 | 28,28 | 29,00 | 27,00 | Standar |
| 9 | **BMRI** | 2026-10-26 | 34418 | 3.900 | 3.920 | 3.810 | 3.910 | 228.301.800 | 150 | 2026-10-26 11:59 | 3.959,20 | 3.960,00 | 3.810,00 | Standar |
| 10 | **BRIS** | 2026-10-26 | 38958 | 1.030 | 1.070 | 1.025 | 1.055 | 115.630.800 | 169 | 2026-10-26 12:29 | 1.080,70 | 1.085,00 | 1.025,00 | Standar |
| 11 | **PTRO** | 2026-10-23 | 112 | 3.070 | 3.140 | 3.030 | 3.090 | 27.460.600 | N/A | N/A | 3.171,40 | 3.180,00 | 3.030,00 | Bar 23-Okt (Obs ID 112 kanonikal, ID 113 deduplikasi) |
| 12 | **SMRA** | 2026-10-23 | 100 | 256 | 266 | 254 | 260 | 32.107.600 | N/A | N/A | 268,66 | 270,00 | 254,00 | Bar 23-Okt (Outcome start 27-Okt) |
| 13 | **TLKM** | 2026-10-23 | 60 | 2.220 | 2.250 | 2.160 | 2.240 | 238.905.400 | N/A | N/A | 2.272,50 | 2.280,00 | 2.160,00 | Bar 23-Okt (Outcome start 27-Okt) |

*Catatan: Seluruh 13 kandidat berstatus **PENDING OUTCOME (Menunggu Sesi 27 Oktober s/d 02 November 2026)**.*

---

## 7. Audit Validasi ML Berdasarkan Artefak Riil

Terdapat pemisahan tegas antara 3 kategori instrumen dalam sistem:

1. **Instrumen yang Didukung Kontrak Model**:
   - 10 emiten konstituen inti dalam `BeiUniverseRegistry::VALIDATED_ML_UNIVERSE`:
     `BBCA`, `BBRI`, `BMRI`, `BBNI`, `ASII`, `TLKM`, `MAPI`, `ARTO`, `MEDC`, `SMRA`.
2. **Instrumen yang Benar-benar Memiliki Hasil Validasi Out-of-Sample**:
   - Hanya 10 emiten konstituen inti di atas yang telah diaudit validasi temporal out-of-sample (walk-forward CV) pada riset `reports/ml/TAHAP_G_FINAL_REPORT.md`.
   - 7 emiten non-konstituen (AMRT, AYAM, BAPI, BCAP, BMBL, BRIS, PTRO) **TIDAK PERNAH** divalidasi out-of-sample. Status: `MODEL_NOT_VALIDATED_FOR_INSTRUMENT` (Keyakinan: `N/A`).
3. **Instrumen dengan Inferensi Aktif untuk Bar Tersebut**:
   - **0 Emiten Memiliki Inferensi Aktif untuk Bar 23–26 Oktober 2026**.
   - Seluruh record inferensi tersimpan di PostgreSQL `prediction_signals` bertanggal keputusan `2026-10-16` dengan horizon $H=5$ yang jatuh tempo dan **kadaluarsa** per `2026-10-23`.
   - **Penegasan Ilmiah**: Record historis `NO_SIGNAL_ABSTAIN` pada 16 Oktober **BUKAN** bukti inferensi bar 26 Oktober. Keyakinan inferensi untuk bar terkini wajib dilaporkan **`N/A`**.

---

## 8. Keterbatasan Metodologi Riset & Integritas Data

1. **Bias Kelangsungan Hidup (Survivorship Bias)**:
   - Registri 941 saham berbasis data snapshot aktif per Oktober 2026. Emiten delisting masa lalu tidak tercakup.
2. **Keterbatasan Point-in-Time Laporan Keuangan**:
   - Ketiadaan metadata tanggal rilis publikasi resmi BEI dan histori restatement/revisi laporan keuangan pada penyedia yfinance melarang penggunaan fundamental historis sebagai point-in-time backtest.
3. **Pemberlakuan Hasil**:
   - Hasil bruto studi sentuhan harga dan hasil neto simulasi transaksi akan dilaporkan secara terpisah setelah outcome $T+5$ tercatat lengkap per 02 November 2026.

---

## 9. Status Evaluasi Forward Sesi 27 Oktober 2026 (Audit Pre-Market 07:15 WIB)

- **Waktu Audit Host**: Selasa, 27 Oktober 2026, 07:15:00 WIB.
- **Status Sesi Perdagangan BEI**: **SESSION_1 (SESI PERDAGANGAN PAGI: 09:00 - 12:00 WIB)**.
  * Sesi perdagangan berjalan 27 Oktober 2026 sedang berlangsung.
  * Pukul 16:05 WIB adalah probe ketersediaan data sekunder publik (Yahoo Finance), bukan jaminan finalitas bursa.
- **Status Evaluasi Output A (Studi Sentuhan Barrier)**:
  * Seluruh 13 kandidat berstatus **`WAITING_FOR_FINAL_DATA`** untuk sesi perdagangan 27 Oktober 2026 karena candle penutupan EOD belum terbentuk dan belum dipublikasikan oleh penyedia data sekunder publik.
  * Tidak ada data harga sintetis, asumsi intraday dini, atau rekayasa data yang diterapkan sebelum data bursa resmi ditutup dan memenuhi kebijakan finalitas.
- **Status Evaluasi Output B (Simulasi Strategi Eksekusi)**:
  * Status: **WITHHELD (DITAHAN)** pada implementasi saat ini.
  * Retur transaksi neto ditahan sesuai keterbatasan metodologi eksekusi riil dan ketiadaan order broker. Hal ini bukan larangan permanen bagi penelitian simulasi di masa mendatang; pembaruan metodologi wajib dirilis dalam versi terpisah.
- **Integritas Pembekuan Parameter**:
  * Seluruh level target $P_{\text{target\_tick}}$ dan $P_{\text{invalidation}}$ tetap terkunci persis sesuai tabel Bagian 6 tanpa modifikasi post-hoc.
  * Nol re-training model dan nol revisi kandidat.
