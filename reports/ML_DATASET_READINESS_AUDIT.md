# LAPORAN AUDIT KESIAPAN DATASET MACHINE LEARNING (IDX EQUITIES)
**Aether Market Intelligence Platform**  
**Tanggal Audit**: 04 November 2026  
**Status Evaluasi**:
- **Swing OHLCV Retrospektif**: **READY_FOR_RETROSPECTIVE_OHLCV_EXPERIMENT** (Manifest Berversi Terbit, Eksklusi & Split Empiris Lolos, Bukan Klaim Siap Produksi).
- **Swing Multi-Modal (Broker/Fundamental)**: **BLOCKED / EXCLUDED** (Ketiadaan Provenance Rilis Point-in-Time Independen).
- **Scalping Intraday**: **BLOCKED** (Board Scope Unverified, Resolusi Bar EOD Tidak Layak untuk Scalping).
- **Aksi Model**: **TRAINING, PERUBAHAN THRESHOLD, DAN PENGGANTIAN MODEL OPERASIONAL TETAP DITAHAN PENUH (HOLD)**.

---

## 1. Rekonsiliasi Semesta, Eksklusi Empiris & Sampel Riil

### A. Rekonsiliasi 973 Seri Adjusted vs 964 Status COMPLETE
Audit menyeluruh database PostgreSQL (`project-bot`) membuktikan hubungan antara tabel `market_observations` (seri `PROVIDER_BACK_ADJUSTED`) dan `idx_company_registries`:

| Kategori Semesta | Jumlah | Rincian & Status |
|---|---|---|
| **COMPLETE di Registry** | **964** | Emiten ekuitas reguler dengan data historis memadai ($\ge 200$ bar). |
| **EXCLUDED_INDEX di Registry** | 1 | Indeks komposit acuan bursa: `^JKSE`. |
| **SHORT_LISTING_HISTORY di Registry** | 1 | Emiten baru listing (< 200 bar bursa: `BACH`). |
| **INCOMPLETE_DATA_GAP di Registry** | 6 | Emiten dengan bar $< 200$ akibat suspensi bursa panjang/transisi IPO: `EMMI, JECX, JELI, PRDL, RANS, WBSA`. |
| **Index Alias di Observasi** | 1 | Alias indeks `IHSG` yang tersimpan di observasi namun bukan emiten registri. |
| **TOTAL SERI ADJUSTED DI OBSERVASI** | **973** | **$964 + 1 + 1 + 6 + 1 = 973$ ticker.** |

Sementara itu, 6 emiten terdaftar di `idx_company_registries` yang **TIDAK memiliki seri adjusted** di observasi adalah:
1. **FAILED (5 emiten)**: `FREN, MASA, MFIN, PRAS, CNTX` (Ditolak oleh *schema guard* karena feed provider mengembalikan harga non-positif/zero pada bar historis).
2. **UNSUPPORTED_SHARE_CLASS (1 emiten)**: `GOTOM` (Efek saham dengan hak suara multipel / Multiple Voting Shares).
*Total Registri*: $973 - 1\text{ (IHSG)} + 6 = \mathbf{978}$ emiten terdaftar.

---

## 2. Batasan Eksperimen Awal: Fitur OHLCV Murni

### A. Pengeluaran Broker Flow & Fundamental
Eksperimen awal dibatasi secara ketat pada fitur teknikal/OHLCV.
- **Alasan Pengeluaran Broker Summary & Fundamental**:
  Fitur broker summary dan laporan keuangan historis belum memiliki timestamp rilis publik (*point-in-time publication timestamp*) yang terverifikasi secara independen. Mengikutsertakan snapshot broker retrospektif berisiko menimbulkan *lookahead bias* akibat revisi kliring atau *restatement* laporan keuangan.
- **Keterbatasan Seri Provider Back-Adjusted**:
  Seri `PROVIDER_BACK_ADJUSTED` disesuaikan secara surut (*back-adjusted*) terhadap aksi korporasi terkini. Rasio penyesuaian diperlakukan sebagai proksi historis, namun rentan terhadap *survivorship drift* pada emiten yang mengalami perubahan modal ekstrem.

### B. Daftar Eksklusi Sampel Riil (Bukan Estimasi Flat 55 Bar)
Pemeriksaan dilakukan secara bar-by-bar pada 964 emiten COMPLETE ($478.311$ total raw adjusted bars):

| Kategori Eksklusi | Jumlah Bar | Persentase | Definisi & Alasan Pengeluaran |
|---|---|---|---|
| **WARMUP_MA50** | **48.200** | 10.08% | 50 bar pertama per emiten didedikasikan untuk stabilisasi SMA50, ATR14, MACD. Tidak boleh menjadi sampel keputusan. |
| **ZERO_VOLUME** | **51.293** | 10.72% | Bar dengan volume 0 (suspensi temporer / ketiadaan likuiditas). Saham tidak dapat dieksekusi pada harga pasar. |
| **CORRUPT_PRICE** | **0** | 0.00% | Bar dengan harga $\le 0$ atau $\text{High} < \text{Low}$. Seluruh data lolos verifikasi integritas harga. |
| **FORWARD_TARGET_UNAVAILABLE_OR_IMMATURE** | **6.618** | 1.38% | Bar di mana hari ke-$5$ forward ($T+5$) tidak tersedia (ujung seri suspensi atau buffer akhir 26 Okt - 04 Nov 2026). |
| **PURGED_AT_SPLIT_BOUNDARY** | **12.673** | 2.65% | Sampel di mana tanggal target $t+5$ melintasi batas akhir split (Train $\to$ Val atau Val $\to$ Test). Di-purge untuk mencegah forward leakage. |
| **SAMPEL ELIGIBLE TERVERIFIKASI** | **359.527** | **75.17%** | **Sampel keputusan bersih yang lolos seluruh syarat kelayakan.** |

---

## 3. Penyusunan Ulang Fold Berdasarkan Cakupan Aktual

### A. Cakupan Tanggal Riil Semesta Bursa
Analisis rentang kalender membuktikan bahwa rentang global `2023-06-07` hanya diisi oleh 9 saham suspensi. Semesta bursa 930+ emiten aktif baru memiliki bar konsisten mulai **25 September 2024**.
Oleh karena itu, rancangan fold disusun ulang secara realistis agar set Train tidak kosong atau tipis:

```text
[=========================== TIMELINE STRUKTUR SPLIT REKONSILIASI ===========================]
2024-09-25                  2024-12-05                                   2025-10-24   2025-11-03              2026-04-23   2026-05-04              2026-10-16   2026-10-26   2026-11-04
|----- Warmup 50 Bar ------|================= TRAIN SET =================|-- Purge --|==== VALIDATION SET ===|-- Purge --|======= TEST SET ======|-- Purge --|-- Buffer --|
(894 Ticker / 207 Hari)                                  (876 Ticker / 111 Hari)                 (868 Ticker / 112 Hari)     (Immature Holdout)
```

### B. Karakteristik & Distribusi per Split Setelah Purging
| Metrik Split | TRAIN SET | VALIDATION SET | TEST SET | SELURUH DATASET |
|---|---|---|---|---|
| **Rentang Tanggal** | 2024-12-05 s.d. 2025-10-24 | 2025-11-03 s.d. 2026-04-23 | 2026-05-04 s.d. 2026-10-16 | 2024-12-05 s.d. 2026-10-16 |
| **Jumlah Hari Bursa** | 207 hari | 111 hari | 112 hari | 430 hari |
| **Jumlah Ticker Aktif**| 894 emiten | 876 emiten | 868 emiten | 964 emiten |
| **Sampel Eligible** | **171.487** | **94.510** | **93.530** | **359.527** |
| **Kelas UP (+1)** | 57.428 (33.49%) | 32.584 (34.48%) | 29.862 (31.93%) | **119.874 (33.34%)** |
| **Kelas DOWN (-1)** | 60.682 (35.39%) | 37.424 (39.60%) | 39.803 (42.56%) | **137.909 (38.36%)** |
| **Kelas NEUTRAL (0)**| 53.377 (31.13%) | 24.502 (25.93%) | 23.865 (25.52%) | **101.744 (28.30%)** |
| **Majority Baseline** | **DOWN (35.39%)**| **DOWN (39.60%)**| **DOWN (42.56%)**| **DOWN (38.36%)** |

*Catatan Transparansi Evaluasi*:
Periode Test Set (Mei - Oktober 2026) digunakan sebagai pengujian out-of-sample retrospektif. Periode yang telah dipakai untuk menguji metode tidak boleh diklaim sebagai holdout murni yang belum pernah dilihat. Buffer data forward mendatang (26 Oktober 2026 ke depan) disisakan sebagai set pengujian live yang benar-benar belum tersentuh.

---

## 4. Pembekuan Label Fixed ±1,5% & Spesifikasi Biaya Riil

### A. Versi Rancangan Label: `LABEL_FIXED_PCT_V1`
- **Formulasi**:
  $$R_{t+5} = \frac{\text{Close}_{t+5} - \text{Close}_t}{\text{Close}_t}$$
  $$\text{Label} = \begin{cases} \text{UP (+1)}, & R_{t+5} > +0.015 \\ \text{DOWN (-1)}, & R_{t+5} < -0.015 \\ \text{NEUTRAL (0)}, & -0.015 \le R_{t+5} \le +0.015 \end{cases}$$
- **Alasan Penggantian Label ATR-Adaptif**:
  Label fixed $\pm 1.5\%$ memberikan ambang batas klasifikasi yang konstan, independen terhadap estimasi volatilitas lokal (ATR14) yang sering terdistorsi pada saham likuiditas rendah atau fraksi harga ekstrim.
- **Batasan Operasional**: Ini adalah versi rancangan eksperimen riset dan **TIDAK MENGUBAH THRESHOLD MODEL PRODUKSI OPERASIONAL**.

### B. Struktur Biaya Transaksi & Slippage Eksplisit (Tanpa Double Counting)
1. **Slippage per Sisi**:
   - Sisi Beli (Entry pada Open $t+1$): **1 tick adverse slippage** di atas harga Open.
     $$\text{Fill Price}_{\text{entry}} = \text{Open}_{t+1} + \text{Tick}(\text{Open}_{t+1})$$
   - Sisi Jual (Exit pada Close $t+5$): **1 tick adverse slippage** di bawah harga Close.
     $$\text{Fill Price}_{\text{exit}} = \text{Close}_{t+5} - \text{Tick}(\text{Close}_{t+5})$$
   - Total Slippage: **2 ticks adverse slippage** round-trip.
2. **Fee Transaksi per Sisi**:
   - Fee Beli: **0.15%** dari nilai nosional beli.
   - Fee Jual: **0.25%** dari nilai nosional jual (termasuk PPh final bursa 0.10%).
   - Total Fee: **0.40% round-trip**.
3. **Formulasi Return Bersih Simulasi (Net PnL)**:
   $$\text{Net Return} = \frac{\text{Fill Price}_{\text{exit}} \times (1 - 0.0025) - \text{Fill Price}_{\text{entry}} \times (1 + 0.0015)}{\text{Fill Price}_{\text{entry}} \times (1 + 0.0015)}$$
   Slippage telah diserap pada harga fill dan fee diterapkan pada masing-masing sisi tanpa perhitungan ganda (*zero double counting*).

---

## 5. Manifest Dataset Berversi & SHA-256 Hash

Manifest dataset telah disimpan pada file fisik berformat JSON:
- **Lokasi File**: `reports/SWING_OHLCV_DATASET_MANIFEST.json`
- **SHA-256 Hash**: `ecf692f7cc6f8b4793fc13d41f74c3e0d21f60864c4bfb9c36bbe4cbd329abb3`

Ringkasan parameter manifest:
```json
{
    "manifest_version": "1.0.0-ohlcv-retrospective",
    "dataset_name": "Aether_IDX_Equities_Swing_OHLCV_Dataset",
    "source": "IDX Edge PRO (Canonical Board RG)",
    "universe_breakdown": {
        "total_registries": 978,
        "complete_tickers": 964,
        "excluded_index": 1,
        "short_listing": 1,
        "incomplete_gaps": 6,
        "failed_schema": 5,
        "unsupported_share_class": 1
    },
    "sample_counts": {
        "total_adjusted_raw_bars": 478311,
        "total_eligible_samples": 359527
    },
    "exclusions": {
        "WARMUP_MA50": 48200,
        "ZERO_VOLUME": 51293,
        "CORRUPT_PRICE": 0,
        "FORWARD_TARGET_UNAVAILABLE_OR_IMMATURE": 6618,
        "PURGED_AT_SPLIT_BOUNDARY": 12673
    },
    "manifest_sha256_hash": "ecf692f7cc6f8b4793fc13d41f74c3e0d21f60864c4bfb9c36bbe4cbd329abb3"
}
```

---

## 6. Ringkasan Status & Blocker yang Tersisa

| Jalur Model | Status Kelayakan | Blocker yang Tersisa | Tindakan Operasional |
|---|---|---|---|
| **Swing OHLCV (Retrospektif)** | **READY_FOR_RETROSPECTIVE_OHLCV_EXPERIMENT** | Bukan kesiapan produksi. Terbatas pada simulasi offline berbasis OHLCV murni; membutuhkan audit komparatif baseline sebelum persetujuan live. | **TRAINING DITAHAN PENUH** sampai rancangan evaluasi disetujui. |
| **Swing Multi-Modal (Broker/Fund)** | **BLOCKED** | Ketiadaan bukti timestamp rilis point-in-time untuk broker summary dan laporan keuangan historis. | Dikeluarkan dari dataset eksperimen awal. |
| **Scalping Intraday** | **BLOCKED** | Stream WebSocket live `BOARD_SCOPE_UNVERIFIED` (belum memisahkan board RG), field `time` dan `trade_id` bursa null. | Dilarang menggunakan bar EOD untuk scalping. |

**Keputusan Akhir**:
Integritas dataset OHLCV telah diverifikasi secara matematis dan empiris. **Tidak ada model yang dilatih, tidak ada threshold produksi yang diubah, dan model operasional deterministik tetap berjalan tanpa gangguan.**
