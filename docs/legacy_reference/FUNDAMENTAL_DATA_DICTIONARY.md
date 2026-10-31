# Kamus Data Fundamental & Metrik Rasio Keuangan

Dokumen ini mendefinisikan seluruh variabel keuangan, rumus kalkulasi deterministik, penanganan nilai ekstrem/negatif, dan template perlakuan sektor khusus di Aether Market Intelligence.

---

## 1. Definisi Variabel Keuangan Pokok

| Variabel | Kolom Database | Satuan | Deskripsi |
|---|---|---|---|
| **Total Revenue** | `total_revenue` | IDR | Pendapatan usaha konsolidasi dalam periode fiskal bersangkutan. |
| **Gross Profit** | `gross_profit` | IDR | Laba kotor (Pendapatan dikurangi beban pokok penjualan). |
| **Operating Income**| `operating_income`| IDR | Laba usaha sebelum beban bunga dan pajak (EBIT). |
| **Net Income** | `net_income` | IDR | Laba bersih yang diatribusikan kepada pemilik entitas induk. |
| **Total Assets** | `total_assets` | IDR | Total seluruh aset lancar dan tidak lancar pada tanggal pelaporan neraca. |
| **Total Debt** | `total_debt` | IDR | Total kewajiban berbunga jangka pendek dan jangka panjang. |
| **Total Equity** | `total_equity` | IDR | Ekuitas pemegang saham (Common Stock Equity). |
| **Operating Cash Flow**| `operating_cash_flow`| IDR | Arus kas bersih yang dihasilkan dari aktivitas operasional. |
| **Free Cash Flow** | `free_cash_flow` | IDR | Arus kas operasi dikurangi belanja modal (Capital Expenditure). |

---

## 2. Rumus dan Tata Kelola Rasio Keuangan Deterministik

### A. Valuasi
1. **Price to Earnings Ratio (PER)**:
   $$\text{PER} = \frac{\text{Harga Penutupan EOD Terakhir}}{\text{Trailing Earnings Per Share (EPS)}}$$
   - **Aturan Integritas**: Jika EPS $\le 0$, PER bernilai negatif. Sistem **DILARANG KERAS** menganggap PER negatif sebagai saham murah (undervalued). Sistem menandai flag `NEGATIVE_EARNINGS` yang mengindikasikan emiten sedang menderita kerugian bersih.
2. **Price to Book Value (PBV)**:
   $$\text{PBV} = \frac{\text{Harga Penutupan EOD Terakhir}}{\text{Book Value Per Share (BVPS)}}$$
   - BVPS dihitung dari $\frac{\text{Total Ekuitas}}{\text{Jumlah Saham Beredar}}$.

### B. Profitabilitas
1. **Return on Equity (ROE)**:
   $$\text{ROE} = \frac{\text{Laba Bersih Tahunan}}{\text{Total Ekuitas}} \times 100\%$$
2. **Return on Assets (ROA)**:
   $$\text{ROA} = \frac{\text{Laba Bersih Tahunan}}{\text{Total Aset}} \times 100\%$$
3. **Net Profit Margin (NPM)**:
   $$\text{NPM} = \frac{\text{Laba Bersih}}{\text{Total Pendapatan}} \times 100\%$$
4. **Operating Profit Margin (OPM)**:
   $$\text{OPM} = \frac{\text{Laba Operasional}}{\text{Total Pendapatan}} \times 100\%$$

### C. Solvabilitas & Struktur Modal
1. **Debt to Equity Ratio (DER)**:
   $$\text{DER} = \frac{\text{Total Utang Berbunga}}{\text{Total Ekuitas}}$$
   - **Template Khusus Perbankan (BANKING)**: Rasio DER konvensional **DITANDAI N/A (DIKECUALIKAN)** untuk sektor perbankan dan jasa keuangan, karena simpanan dana pihak ketiga (DPK) bank secara teknis dicatat sebagai liabilitas operasional, bukan utang korporasi biasa.

### D. Imbal Hasil & Pertumbuhan
1. **Dividend Yield**:
   $$\text{Dividend Yield} = \frac{\text{Total Dividen Tunai 1 Tahun}}{\text{Harga Penutupan EOD Terakhir}} \times 100\%$$
2. **Revenue Growth YoY**:
   $$\text{Pertumbuhan} = \frac{\text{Pendapatan Tahun Berjalan} - \text{Pendapatan Tahun Sebelumnya}}{\text{Pendapatan Tahun Sebelumnya}} \times 100\%$$
3. **Net Income Growth YoY**:
   $$\text{Pertumbuhan} = \frac{\text{Laba Bersih Tahun Berjalan} - \text{Laba Bersih Tahun Sebelumnya}}{|\text{Laba Bersih Tahun Sebelumnya}|} \times 100\%$$
