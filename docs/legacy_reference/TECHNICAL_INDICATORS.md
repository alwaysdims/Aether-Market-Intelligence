# Spesifikasi Indikator Teknikal Deterministik

Dokumen ini mendefinisikan rumus, parameter, dan ketentuan kalkulasi teknikal yang dihitung secara deterministik oleh `idx_financial_collector.py` dan disimpan di tabel `company_analysis_metrics`.

---

## 1. Parameter dan Rumus Indikator

| Indikator | Kolom | Parameter / Periode | Deskripsi & Rumus |
|---|---|---|---|
| **Simple Moving Average (SMA)** | `sma_20`, `sma_50`, `sma_200` | 20, 50, dan 200 candle harian | $\text{SMA}_N = \frac{1}{N} \sum_{i=0}^{N-1} \text{Close}_{t-i}$ |
| **Relative Strength Index (RSI)** | `rsi_14` | 14 candle (Wilder's RSI) | $\text{RSI} = 100 - \frac{100}{1 + \text{RS}}$ di mana $\text{RS} = \frac{\text{Rata-rata Gain}}{\text{Rata-rata Loss}}$ |
| **Moving Average Convergence Divergence (MACD)** | `macd`, `macd_signal`, `macd_hist` | Fast=12, Slow=26, Signal=9 | $\text{MACD Line} = \text{EMA}_{12} - \text{EMA}_{26}$<br>$\text{Signal Line} = \text{EMA}_9(\text{MACD Line})$<br>$\text{Histogram} = \text{MACD Line} - \text{Signal Line}$ |
| **Average True Range (ATR)** | `atr_14`, `atr_14_pct` | 14 candle | $\text{TR} = \max(\text{High} - \text{Low}, |\text{High} - \text{Close}_{\text{prev}}|, |\text{Low} - \text{Close}_{\text{prev}}|)$ |
| **Relative Volume (RVOL)** | `rvol_20` | 20 hari rata-rata volume | $\text{RVOL}_{20} = \frac{\text{Volume Sesi Terakhir}}{\text{Rata-rata Volume 20 Hari Sebelumnya}}$ |
| **Support & Resistance** | `support_price`, `resistance_price` | Rolling 20-day High & Low | $\text{Support} = \min(\text{Low}_{20})$, $\text{Resistance} = \max(\text{High}_{20})$ |
| **Max Drawdown 1 Tahun** | `max_drawdown_1y_pct` | 252 hari perdagangan | Penurunan persentase terbesar dari puncak harga tertinggi running peak. |

---

## 2. Klasifikasi Rezim Tren Pasar

| Status Tren | Definisi Kuantitatif |
|---|---|
| **STRONG_BULLISH** | $\text{SMA}_{20} > \text{SMA}_{50} > \text{SMA}_{200}$ |
| **BULLISH** | $\text{SMA}_{20} > \text{SMA}_{50}$ |
| **STRONG_BEARISH** | $\text{SMA}_{20} < \text{SMA}_{50} < \text{SMA}_{200}$ |
| **BEARISH** | $\text{SMA}_{20} < \text{SMA}_{50}$ |
| **SIDEWAYS** | SMA tidak membentuk susunan teratur atau fluktuasi dalam rentang sempit. |
| **INSUFFICIENT_HISTORY** | Riwayat bar $< 15$ hari (indikator warmup belum memadai). |

---

## 3. Aturan Proteksi Likuiditas

- **Dilarang Menyimpulkan Likuiditas Tinggi dari Volume 1 Hari**:
  Lonjakan volume 1 hari dapat terjadi karena rebalancing, crossing pasar negosiasi, atau spekulasi sesaat. Sistem mengukur likuiditas multi-hari menggunakan metrik `rvol_20` (dibandingkan terhadap rata-rata MA20 volume) sebelum menyimpulkan keaktifan likuiditas emiten.
