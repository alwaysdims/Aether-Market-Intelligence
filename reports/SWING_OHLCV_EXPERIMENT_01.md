# LAPORAN EKSPERIMEN SWING OHLCV OFFLINE PERTAMA (EXP-01)
**Aether Market Intelligence Platform**  
**Tanggal Eksperimen**: 04 November 2026  
**Status Eksperimen**: **SELESAI (ISOLASI TOTAL - ZERO PRODUCTION IMPACT)**  
**Keputusan Akhir (Final Verdict)**: **`REJECTED`** (Gagal Acceptance Gate 1 & Gate 4).

---

## 1. Integritas Dataset & Audit Pemeriksaan Akhir

Dataset diekstraksi menggunakan kalender bursa kanonikal resmi BEI (Board RG) dan diverifikasi secara ketat sebelum proses fitting:

| Parameter Integritas | Status Audit | Keterangan & Nilai |
|---|---|---|
| **Dataset CSV Path** | Terverifikasi | `reports/swing_ohlcv_dataset_features.csv` |
| **Dataset SHA-256 Hash** | Terkunci | `b9dabf986858c801e309557b2f25090121d67909038592565d0da485b2a89caf` |
| **Kode Eksperimen** | Terkunci | `scripts/run_swing_ohlcv_experiment_01.py` |
| **Duplikasi Sampel** | **0 (Nol)** | Pengujian `df.duplicated(['ticker', 'bar_date'])` menghasilkan 0 baris duplikat. |
| **Kelengkapan Fitur** | **100% Finite** | 9 fitur teknikal (ret_1d, ret_5d, ret_20d, close/sma20, close/sma50, atr14_norm, hl_spread_norm, vol/sma20, log_turnover) bebas dari NaN/Inf. |
| **Kalender Horizon T+5** | Kanonikal BEI | Horizon T+5 dihitung berdasarkan sesi perdagangan bursa nyata ($N=503$ hari), bukan sekadar shift indeks 5 bar. |
| **Penanganan Gap Saham** | Terisolasi | 699 bar di mana saham tidak memiliki data pada tanggal T+5 kanonikal dikeluarkan (`TICKER_T5_MISSING_GAP`). |
| **True Purging Batas Split** | Terkunci | 12.659 bar yang targetnya melintasi batas Train $\to$ Val atau Val $\to$ Test di-purge. |
| **Reserved Historical Window** | Terkunci | 1.800 bar pada periode 26 Okt - 04 Nov 2026 dipisahkan sebagai data historis terisolasi (bukan holdout live forward). |
| **Total Sampel Bersih** | **359.505** | Train: 171.487 • Validation: 94.510 • Test: 93.508 bar keputusan. |

---

## 2. Protokol Pembekuan Eksperimen & Optimasi Validasi

Pencarian hiperparameter dan aturan keputusan dilakukan secara strictly terisolasi pada **VALIDATION SET** dengan anggaran komputasi 6 konfigurasi:

- **Model Kandidat**: Multiclass Logistic Regression (Multinomial L-BFGS, Max Iter 500).
- **Ruang Pencarian Hyperparameter**:
  * $C \in \{0.01, 0.1, 1.0\}$
  * $\text{class\_weight} \in \{\text{None}, \text{'balanced'}\}$
- **Hasil Evaluasi Validasi**:
  * $C=0.01, \text{weight}=\text{'balanced'} \to \text{Balanced Accuracy}=0.4307, \text{Macro AUC}=0.6174, \text{Macro F1}=0.4060$.
- **Konfigurasi Terbaik Dibekukan**:
  $$\text{Frozen Hyperparameters: } \{C: 0.01, \text{class\_weight}: \text{'balanced'}\}$$
- **Tuning Threshold Abstain ($\tau_{\text{up}}$) pada Validasi**:
  * $\tau=0.33 \to \text{Coverage}=63.17\%, \text{Precision}=37.87\%$
  * $\tau=0.38 \to \text{Coverage}=30.93\%, \text{Precision}=38.76\%$ (Presisi tertinggi dengan $N \ge 1.000$)
  * $\tau=0.42 \to \text{Coverage}=10.94\%, \text{Precision}=37.71\%$
  * $\tau_{\text{up}} = 0.38$ **dibekukan sebelum membuka Test Set**.

---

## 3. Hasil Evaluasi Out-of-Sample (OOS) Test Set (Strictly Once)

Evaluasi pada **TEST SET (93.508 sampel | 868 emiten | 04 Mei 2026 s.d. 16 Okt 2026)** hanya dijalankan tepat satu kali tanpa modifikasi susulan:

### A. Metrik Klasifikasi Statistik
| Metrik Evaluasi | Model Kandidat (EXP-01) | Baseline Majority (DOWN) | Baseline Naive Momentum | Status vs Baseline |
|---|---|---|---|---|
| **Balanced Accuracy** | **43.31%** | 33.33% | 34.09% | Unggul $+9.98\%$ vs Majority |
| **Macro F1-Score** | **0.4115** | 0.1990 | 0.2868 | Unggul $+0.2125$ vs Majority |
| **OvR Macro ROC-AUC** | **0.6105** | 0.5000 | 0.5000 | Diskriminatif ($> 0.500$) |
| **Precision (UP)** | **35.61%** | 0.00% | 33.15% | Marjinal di atas prior ($31.93\%$) |
| **Recall (UP)** | **21.17%** | 0.00% | 40.21% | Sangat konservatif |
| **Precision (DOWN)** | **51.10%** | 42.56% | 43.10% | Cukup tajam menangkap penurunan |

### B. Confusion Matrix pada Test Set (Baris = Aktual, Kolom = Prediksi)
```text
                 Pred NEUTRAL    Pred UP    Pred DOWN      Total Aktual
Aktual NEUTRAL      13.913        3.927       6.024          23.864
Aktual UP           10.344        6.321      13.188          29.853
Aktual DOWN         12.206        7.505      20.080          39.791
Total Prediksi      36.463       17.753      39.292          93.508
```

### C. Keputusan Selektif (Abstain Rule $\tau_{\text{up}} \ge 0.38$)
- **Coverage**: **26.16%** ($24.459$ bar dari $93.508$ bar uji).
- **Precision UP Selektif**: **34.29%** (Hanya meningkat tipis $+2.36\%$ dari base rate UP $31.93\%$).

### D. Uji Signifikansi Statistik Paired Block Bootstrap ($B = 1.000$ Blok Tanggal Bursa)
Resampling dilakukan secara blok tanggal kalender (panjang blok = 5 hari bursa kanonikal):
- **vs Majority Class Baseline**:
  * $\Delta \text{ Balanced Accuracy} = +10.01\%$
  * 95% Confidence Interval: $[+8.10\%, +11.60\%]$
  * $P(\text{Model} \le \text{Majority}) = \mathbf{0.00\%}$ (Signifikan secara statistik).
- **vs Naive Momentum Baseline**:
  * $\Delta \text{ Balanced Accuracy} = +9.34\%$
  * 95% Confidence Interval: $[+4.63\%, +13.96\%]$
  * $P(\text{Model} \le \text{Momentum}) = \mathbf{0.00\%}$ (Signifikan secara statistik).

---

## 4. Simulasi Skenario Transaksi (Bukan Return Portofolio Riil)

Simulasi portofolio dijalankan strictly dengan batasan bursa Indonesia: **TIDAK ADA POSISI SHORT UNTUK LABEL DOWN**. Hanya skenario sinyal UP terarah ($\tau_{\text{up}} \ge 0.38$) yang diuji:

### A. Koreksi Metodologi: Pemisahan Rata-Rata Skenario vs Return Portofolio
- **Sifat Skenario T+5**: Setiap bar keputusan menghasilkan skenario horizon 5 hari bursa yang saling tumpang tindih (*overlapping evaluation horizons*).
- **Bukan Portofolio Riil**: Rata-rata return di bawah ini merupakan rata-rata aritmatika per skenario independen, **BUKAN return portofolio riil**. Menjumlahkan return skenario tumpang tindih secara kumulatif adalah cacat metodologis (mengasumsikan modal tak terbatas dan likuiditas instan).
- **Aturan Eksekusi**:
  * Entry: Open Sesi 1 hari $t+1$ $+ 1$ tick adverse slippage.
  * Exit: Close Sesi 2 hari $t+5$ $- 1$ tick adverse slippage.
  * Fee Transaksi: Beli $0.15\%$, Jual $0.25\%$ (Total fee $0.40\%$ round-trip).
  * Total Skenario Terarah UP: **24.459 skenario** (dari 93.508 bar uji).

### B. Perbandingan Komparatif: Model UP vs Universe vs Baseline Momentum
Menggunakan aturan entry/exit/biaya yang identik pada seluruh 93.508 bar uji:

| Kelompok Skenario | Jumlah Skenario ($N$) | Win Rate ($>0$) | Rata-rata Gross Return | Rata-rata Net Return | Rata-rata Slippage (2 Tick) |
|---|---|---|---|---|---|
| **Universe Eligible (Seluruh Bar Test)** | 93.508 | 28.61% | -0.71% | **-2.88%** | 1.81% |
| **Naive Momentum (SMA20 > SMA50)** | 50.359 | 29.37% | -0.66% | **-2.96%** | 1.95% |
| **Kandidat Model UP ($\tau_{\text{up}} \ge 0.38$)** | 24.459 | 30.97% | -1.26% | **-3.47%** | 1.87% |

*Temuan Komparatif*:
Meskipun Model UP memiliki win rate sedikit lebih tinggi ($30.97\%$ vs $28.61\%$), rata-rata gross return model ($-1.26\%$) justru **LEBIH BURUK** daripada universe acak ($-0.71\%$) dan momentum baseline ($-0.66\%$). Model memilih saham-saham yang mengalami koreksi lebih dalam pasca-keputusan.

### C. Diagnosis Rinci Skenario Model UP

1. **Performa per Bulan (Kalender 2026)**:
   - `2026-05`: $N=3.774$, Win Rate $15.4\%$, Gross $-7.94\%$, Net **$-9.83\%$**.
   - `2026-06`: $N=5.789$, Win Rate $35.4\%$, Gross $+0.37\%$, Net **$-1.79\%$**.
   - `2026-07`: $N=4.426$, Win Rate $38.9\%$, Gross $+1.66\%$, Net **$-0.58\%$**.
   - `2026-08`: $N=3.488$, Win Rate $34.7\%$, Gross $+1.59\%$, Net **$-0.57\%$**.
   - `2026-09`: $N=4.299$, Win Rate $29.8\%$, Gross $-0.67\%$, Net **$-2.80\%$**.
   - `2026-10`: $N=2.683$, Win Rate $27.3\%$, Gross $-4.81\%$, Net **$-7.78\%$**.
   *Catatan*: Pada bulan-bulan di mana gross return positif (Juli & Agustus), total drag friksi ($2.27\%$) tetap menyeret net return ke area negatif.

2. **Performa per Kelompok Likuiditas (Turnover Quintiles)**:
   - `Q1 (Terendah)`: $N=1.515$, Win Rate $34.9\%$, Gross $+0.45\%$, Net **$-1.30\%$**, Avg Slippage $1.36\%$.
   - `Q2`: $N=3.129$, Win Rate $31.8\%$, Gross $-0.31\%$, Net **$-2.51\%$**, Avg Slippage $1.83\%$.
   - `Q3`: $N=5.244$, Win Rate $30.1\%$, Gross $-0.61\%$, Net **$-3.29\%$**, Avg Slippage $2.34\%$.
   - `Q4`: $N=6.986$, Win Rate $30.1\%$, Gross $-1.42\%$, Net **$-3.86\%$**, Avg Slippage $2.10\%$.
   - `Q5 (Kelompok Likuiditas Tertinggi)`: $N=7.585$, Win Rate $31.2\%$, Gross $-2.28\%$, Net **$-4.08\%$**, Avg Slippage $1.44\%$.
   *Catatan*: Q5 didefinisikan secara empiris sebagai kelompok nilai transaksi tertinggi (bukan otomatis blue chips). Pada Q5, penurunan kotor paling dalam ($-2.28\%$).

3. **Performa per Rentang Harga (Tick Size Brackets)**:
   - `< 200 (Tick Rp 1, Dampak $\ge 1\%$)`: $N=12.011$, Win Rate $29.1\%$, Net **$-3.95\%$**, Avg Slippage **$2.61\%$**.
   - `200 - 499 (Tick Rp 2)`: $N=5.268$, Win Rate $32.2\%$, Net **$-3.16\%$**, Avg Slippage $1.36\%$.
   - `500 - 1.999 (Tick Rp 5)`: $N=4.871$, Win Rate $33.0\%$, Net **$-3.05\%$**, Avg Slippage $1.17\%$.
   - `2.000 - 4.999 (Tick Rp 10)`: $N=1.550$, Win Rate $35.2\%$, Net **$-2.39\%$**, Avg Slippage $0.68\%$.
   - `\ge 5.000 (Tick Rp 25)`: $N=759$, Win Rate $30.4\%$, Net **$-3.01\%$**, Avg Slippage $0.58\%$.
   *Catatan*: Saham di bawah Rp 200 menanggung beban slippage tertinggi ($2.61\%$), memperparah kerugian bersih.

4. **Performa per Bin Probabilitas UP**:
   - `[0.38, 0.42)`: $N=17.296$, Win Rate $30.7\%$, Gross $-1.17\%$, Net **$-3.44\%$**.
   - `[0.42, 0.46)`: $N=5.847$, Win Rate $31.0\%$, Gross $-1.65\%$, Net **$-3.77\%$**.
   - `[0.46, 0.50)`: $N=1.115$, Win Rate $33.7\%$, Gross $-1.25\%$, Net **$-3.22\%$**.
   - `[0.50, 1.00)`: $N=201$, Win Rate **$42.3\%$**, Gross **$+2.98\%$**, Net **$+1.16\%$**.
   *Catatan Audit Status Temuan $P \ge 0.50$*:
   Hasil positif pada bin $P \ge 0.50$ berstatus **TEMUAN EKSPLORATIF PASCA-TEST (POST-HOC OBSERVATION)**, BUKAN threshold yang telah tervalidasi. Bin ini hanya terdiri dari **201 sampel** yang tersebar pada **53 ticker unik** dan **82 tanggal keputusan unik** (terkonsentrasi pada emiten kapitalisasi mikro seperti ALKA, MDIA, AGAR, FORU). Ukuran sampel terlalu kecil dan tidak boleh dipakai sebagai klaim keberhasilan threshold.

---

## 5. Keselarasan Label Statistik vs Sasaran Transaksi

- **Overnight Gap (Open $t+1$ vs Close $t$)**: Rata-rata $+0.22\%$ (terjadi lonjakan harga pembukaan sebelum eksekusi).
- **Korelasi Return**: Korelasi antara return label Close-to-Close dan return transaksi Open-to-Close adalah $0.9739$.
- **Discrepancy (Kontradiksi Klasifikasi vs Transaksi)**:
  Dari $8.388$ sampel di mana label statistiknya benar-benar UP ($R_{\text{close}} > +1.5\%$), terdapat **$241$ skenario ($2.87\%$)** yang menghasilkan return transaksi gross **NEGATIF** akibat lonjakan harga pembukaan (*open gap up*) yang kemudian tergerus sepanjang horizon.
- **Koreksi Akar Masalah Return Negatif**:
  Ketidakselarasan label dan friksi transaksi memperbesar kerugian, tetapi **bukan satu-satunya penyebab utama**. Rata-rata gross return model sendiri sudah bernilai negatif ($-1.26\%$) bahkan sebelum memperhitungkan slippage dan komisi. Ini membuktikan fitur teknikal linier yang digunakan belum memiliki keunggulan pemisahan (*alpha/discriminative edge*) terhadap arah pasar.
- **Dekomposisi Friksi**:
  * Rata-rata Slippage (2 tick adverse): **$1.87\%$**.
  * Fee Transaksi (round-trip): **$0.40\%$**.
  * Total Drag Friksi Rata-rata: **$2.27\%$**.
  * Beban gesekan riil ($2.27\%$) memperparah hasil return kotor yang sudah negatif menjadi defisit neto $-3.47\%$.

---

## 6. Verifikasi Acceptance Gate & Keputusan Akhir

| Gate | Syarat Penerimaan | Hasil Evaluasi Test Set | Status Gate |
|---|---|---|---|
| **Gate 1** | Balanced Accuracy OOS $> 54.0\%$ | **43.31%** | ❌ **FAIL** (Defisit 10.69%) |
| **Gate 2** | Macro ROC-AUC OOS $\ge 0.580$ | **0.6105** | ✔️ **PASS** (Melampaui target) |
| **Gate 3** | Paired Bootstrap 95% CI $\Delta > 0$ dan $P \le 0 < 5\%$ | $\Delta = +10.01\%$, CI $[+8.10\%, +11.60\%]$, $P=0.00\%$ | ✔️ **PASS** (Signifikan) |
| **Gate 4** | Positive Net Economic Return Pasca-Friksi | **-3.47%** | ❌ **FAIL** (Negatif) |

### KEPUTUSAN FINAL: **`REJECTED`**
Model kandidat berbasis Logistic Regression OHLCV offline pertama **DITOLAK SECARA RESMI** untuk promosi ke tahap *forward shadow*, karena gagal memenuhi ambang akurasi minimum ($> 54\%$) dan menghasilkan ekspektasi return bersih negatif ($-3.47\%$) akibat friksi tick dan komisi bursa.

---

## 7. Protokol Terkontrol Eksperimen Berikutnya (EXP-02)

*Catatan Status Data*: **Periode Test Mei - Oktober 2026 telah terpapar pada EXP-01 dan berstatus EXPOSED HISTORICAL BENCHMARK (tidak lagi menjadi untouched holdout). Evaluasi validasi akhir dialihkan ke RESERVED HISTORICAL WINDOW (26 Okt - 04 Nov 2026) dan Forward Timestamped Logging.**

### A. Rangkaian Ablation Bertahap (Isolasi Faktor Tunggal)
Perubahan dilarang dilakukan secara sekaligus dalam satu paket besar. EXP-02 dipecah menjadi 3 pengujian berurutan pada sampel yang terstandarisasi:

1. **Ablation A: Model Linier Existing + Dynamic Cost-Aware Hurdle Labeling**
   - Model: Multinomial Logistic Regression (arsitektur dan fitur identik EXP-01).
   - Perubahan: Hanya formulasi label target yang diubah dari fixed $\pm 1.5\%$ menjadi ambang berbasis friksi instrumen.
   - Tujuan: Mengukur apakah kegagalan EXP-01 murni akibat batas label statis yang terlalu sempit.

2. **Ablation B: Ablation A + Filter Kelayakan Eksekusi (Execution Eligibility Guard)**
   - Perubahan: Menambahkan aturan saring pra-keputusan point-in-time (eliminasi saham $\text{Close}_t < \text{Rp } 200$ dan likuiditas point-in-time rendah).
   - Evaluasi Komparatif: Menilai perubahan performa pada sampel bersama (*common eligible universe*) dan mencatat dampak filter terhadap penyusutan coverage serta pergeseran baseline.

3. **Ablation C: Non-Linear Regularized Model (LightGBM / Gradient Boosting) pada Universe B**
   - Perubahan: Mengganti model linier dengan pohon keputusan gradient boosting untuk menangkap interaksi non-linier antara volatilitas, rasio moving average, dan likuiditas.
   - Anggaran Komputasi: Maksimal 12 kombinasi hiperparameter yang di-fit strictly pada Train dan dipilih pada Validation.

---

### B. Formulasi Lengkap Label Dynamic Cost-Aware Hurdle

Ambang batas dibentuk secara matematis untuk melampaui biaya gesekan transaksi riil dengan margin keamanan:

1. **Waktu Informasi Tersedia**:
   Pada tanggal keputusan $t$ (16:30 WIB), harga penutupan resmi $\text{Close}_t$ telah definitif.
   Fraksi tick instrumen $\text{Tick}(\text{Close}_t)$ dihitung strictly dari $\text{Close}_t$:
   $$\text{Friction Drag}(P_t) = 0.0040 + \frac{2 \times \text{Tick}(P_t)}{P_t}$$
   Margin keunggulan ekonomi minimum ditetapkan konstan $\alpha_{\text{margin}} = 1.0\% = 0.010$.
   Maka batas dinamis instrumen:
   $$\theta_t = \text{Friction Drag}(\text{Close}_t) + \alpha_{\text{margin}} = 0.0140 + \frac{2 \times \text{Tick}(\text{Close}_t)}{\text{Close}_t}$$

2. **Hubungan Label dengan Entry Eksekusi**:
   - Dilarang keras memakai harga Open $t+1$ untuk menyaring kelayakan atau membentuk fitur pada tanggal $t$ (anti-lookahead).
   - Label statistik arah dihitung terhadap realisasi penutupan horizon:
     $$R_{t+5} = \frac{\text{Close}_{t+5} - \text{Close}_t}{\text{Close}_t}$$
   - **Definisi 3 Kelas EXP-02**:
     $$\text{Label}_t = \begin{cases} 
     \text{UP (+1)}, & R_{t+5} > +\theta_t \\
     \text{DOWN (-1)}, & R_{t+5} < -\theta_t \\
     \text{NEUTRAL (0)}, & -\theta_t \le R_{t+5} \le +\theta_t 
     \end{cases}$$

---

### C. Pembentukan Likuiditas Point-in-Time (Anti-Lookahead)

- **Larangan**: Dilarang membagi kelompok kuintil likuiditas menggunakan turnover rata-rata seluruh periode test atau data masa depan.
- **Formulasi Point-in-Time**:
  Kuintil likuiditas pada tanggal $t$ wajib dihitung secara *cross-sectional* hanya menggunakan data yang tersedia pada hari $t$:
  $$\text{Turnover 20D}(i, t) = \frac{1}{20} \sum_{k=0}^{19} \left( \text{Close}_{i, t-k} \times \text{Volume}_{i, t-k} \right)$$
  Batas kuintil Q1 s.d. Q5 dihitung lintas saham pada tanggal $t$ secara *leave-one-out*.

---

### D. Pembekuan Konfigurasi & Aturan Evaluasi

1. **Isolasi Penuh**:
   - Preprocessing (`StandardScaler`), seleksi fitur, dan penentuan bobot kelas **strictly fit pada Train Set**.
   - Pemilihan threshold abstain $\tau$, kalibrasi probabilitas, dan pemilihan model terbaik **strictly pada Validation Set**.
   - Parameter dibekukan penuh sebelum menyentuh Test Set.
2. **Evaluasi Test Set Terpapar (Mei - Okt 2026)**:
   - Dijalankan tepat satu kali sebagai tolak ukur komparasi historis terhadap EXP-01.
3. **Protokol Validasi Forward Bertimestamp (Forward Shadow Protocol)**:
   - Evaluasi tanpa polusi dilakukan menggunakan **Reserved Historical Window (26 Okt - 04 Nov 2026)** dan inferensi forward harian:
     * Prediksi di-generate pada 16:30 WIB setiap hari bursa sebelum outcome diketahui.
     * Hash SHA-256 prediksi dibekukan dan dicatat ke log lokal dengan timestamp sistem.
     * Tidak ada pengiriman sinyal ke Telegram publik dan tidak ada perubahan model produksi operasional.
4. **Status Eksekusi**:
   **SELURUH TRAINING MODEL EXP-02 DITAHAN PENUH** sampai protokol di atas selesai ditinjau.

---

## 8. Lokasi Artefak Terisolasi (Audit Trail)

Seluruh artefak eksperimen disimpan secara terisolasi tanpa mencemari sistem produksi:
- **Laporan Eksperimen**: `reports/SWING_OHLCV_EXPERIMENT_01.md`
- **Dataset Fitur CSV**: `reports/swing_ohlcv_dataset_features.csv` (SHA-256: `b9dabf986858c801e309557b2f25090121d67909038592565d0da485b2a89caf`)
- **File Prediksi Test OOS**: `reports/artifacts_exp01/test_predictions_exp01.csv` (SHA-256: `8d78e0b0edf5d6868fb60ca289c4755d2835a98ff9309b4a3ad0ad6ee8449383`)
- **File Ringkasan JSON**: `reports/artifacts_exp01/experiment_01_summary.json`
- **Skrip Ekstraksi, Runner & Diagnostik**:
  * `scripts/extract_experiment_dataset.php`
  * `scripts/run_swing_ohlcv_experiment_01.py`
  * `scripts/diagnose_exp01.py`

**Keputusan Operasional**:
Sesuai batasan protokol, **tidak ada tuning ulang pada test set yang sama**, tidak ada threshold operasional yang diubah, dan sistem produksi tetap berjalan murni pada pipeline deterministik PHP/SQL existing.
