# ANALISIS DETEKSI REZIM PASAR IHSG (FASE H3)
## AETHER MARKET INTELLIGENCE

**Tanggal Rilis:** 25 Oktober 2026  
**Periode Analisis:** 4 Januari 2021 s.d. 23 Oktober 2026 (1.398 bar harian IHSG `^JKSE`)  
**Metodologi:** Aturan Transparan Bebas Lookahead (*Zero-Lookahead Rule-Based Regime Classification*)  
**Status Audit:** VERIFIED & DETERMINISTIC

---

### 1. Definisi & Kriteria 4 Rezim Pasar

Klasifikasi rezim pasar diturunkan murni dari data harga penutupan IHSG harian pada *decision timestamp* (16:00 WIB) menggunakan fungsi jendela ke belakang (*backward-looking*):

1. **High Volatility (Volatilitas Ekstrem):**
   - Kriteria: Volatilitas tahunan 20-hari ($\sigma_{\text{ann}} = \text{std}(\text{return}_1) \times \sqrt{252}$) $\ge 25\%$.
   - Karakteristik: Gejolak pasar tinggi, risiko *whipsaw* tajam, korelasi antar-aset meningkat.
2. **Bullish (Tren Menguat):**
   - Kriteria: $\text{Close} > \text{EMA}_{20}$ dan return momentum 20-hari ($r_{20} > +1,5\%$) dengan volatilitas tahunan $< 25\%$.
   - Karakteristik: Akumulasi institusional terarah, reli sektor perbankan dan *blue-chip*.
3. **Bearish (Tren Melemah):**
   - Kriteria: $\text{Close} < \text{EMA}_{20}$ dan return momentum 20-hari ($r_{20} < -1,5\%$) dengan volatilitas tahunan $< 25\%$.
   - Karakteristik: Tekanan distribusi asing, pelemahan indeks acuan.
4. **Sideways (Konsolidasi / Menyamping):**
   - Kriteria: Pergerakan harga di sekitar EMA-20 ($|r_{20}| \le 1,5\%$) atau sinyal saling meniadakan.
   - Karakteristik: Rentang sempit, likuiditas selektif, rentan sinyal palsu (*false breakout*).

---

### 2. Distribusi Empiris Rezim Pasar (2021–2026)

| Kategori Rezim | Jumlah Bar Bursa | Proporsi Sampel (%) | Rata-rata Volatilitas Tahunan |
|---|:---:|:---:|:---:|
| **SIDEWAYS** | 470 bar | **33.62%** | 11.24% |
| **BULLISH** | 469 bar | **33.55%** | 12.80% |
| **BEARISH** | 289 bar | **20.67%** | 15.42% |
| **HIGH_VOLATILITY** | 150 bar | **10.73%** | 28.65% |
| **INSUFFICIENT_HISTORY** | 20 bar | 1.43% | N/A (Warmup 20 bar pertama) |
| **TOTAL** | **1.398 bar** | **100.00%** | **13.85%** |

---

### 3. Stabilitas & Frekuensi Perpindahan Rezim (Regime Flips)

- **Total Perpindahan Rezim:** 254 kali perpindahan dalam 1.398 hari bursa.
- **Rata-rata Durasi Rezim:** **5,5 hari bursa**.
- **Analisis Kuantitatif:**
  Karena rata-rata durasi rezim pasar di BEI adalah 5,5 hari bursa, durasi ini **hampir identik dengan horizon prediksi model (5 hari bursa)**. Akibatnya, rezim sering kali berbalik (*flip*) di tengah-tengah masa tunggu evaluasi trade, sehingga menyuntikkan fitur rezim secara langsung ke dalam model justru menurunkan keakuratan prediksi.
