# ANALISIS PREDIKSI SELEKTIF & PENELITIAN TARGET PRESISI 90% (FASE G6)
## AETHER MARKET INTELLIGENCE

**Tanggal Rilis:** 25 Oktober 2026  
**Metodologi:** Prediksi Selektif dengan Mekanisme Abstain (*Selective Prediction with Abstention*)  
**Populasi Uji Out-of-Sample:** 4.187 bar harian (9 Januari 2025 s.d. 16 Oktober 2026)  
**Taraf Signifikansi:** Interval Kepercayaan Wilson 95% (Wilson Score 95% Confidence Interval)  
**Status Integritas:** VERIFIED & DETERMINISTIC

---

### 1. Definisi & Protokol Prediksi Selektif

Dalam aplikasi finansial nyata, memaksakan model untuk memprediksi arah setiap bar setiap hari adalah kekeliruan metodologis. Sistem kuantitatif yang bertanggung jawab harus memiliki kemampuan untuk **ABSTAIN** (*No Signal*) ketika probabilitas keyakinan model tidak memadai.

**Aturan Penentuan Sinyal:**
- **Sinyal NAIK (UP):** $P(\text{Naik}) \ge \tau$ (Ambang Batas Keyakinan)
- **Sinyal TURUN (DOWN):** $P(\text{Naik}) \le (1 - \tau)$
- **ABSTAIN / NO SIGNAL:** Jika $(1 - \tau) < P(\text{Naik}) < \tau$

---

### 2. Matriks Presisi vs Coverage pada Berbagai Ambang Batas

#### A. Model HistGradientBoosting

| Ambang Batas ($\tau$) | Status Sinyal | Total Sinyal | Sinyal UP | Sinyal DOWN | Coverage Pasar (%) | Presisi Gabungan (%) | Wilson 95% CI | Presisi Sinyal UP (%) | Wilson UP 95% CI |
|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| **0.50** | ACTIVE_SIGNAL | 4.187 | 1.845 | 2.342 | 100.00% | 51.13% | [49.62%, 52.65%] | 45.80% | [43.54%, 48.08%] |
| **0.55** | ACTIVE_SIGNAL | 2.855 | 1.263 | 1.592 | 68.19% | 50.79% | [48.95%, 52.62%] | 46.32% | [43.58%, 49.08%] |
| **0.60** | ACTIVE_SIGNAL | 1.771 | 848 | 923 | 42.30% | 50.82% | [48.49%, 53.14%] | 46.82% | [43.48%, 50.18%] |
| **0.65** | ACTIVE_SIGNAL | 945 | 531 | 414 | 22.57% | 51.22% | [48.03%, 54.39%] | 49.34% | [45.11%, 53.58%] |
| **0.70** | ACTIVE_SIGNAL | 467 | 313 | 154 | 11.15% | 52.89% | [48.36%, 57.38%] | 52.40% | [46.87%, 57.87%] |
| **0.75** | ACTIVE_SIGNAL | 187 | 144 | 43 | 4.47% | 52.41% | [45.27%, 59.44%] | 52.08% | [43.98%, 60.08%] |
| **0.80** | ACTIVE_SIGNAL | 53 | 46 | 7 | 1.27% | 64.15% | [50.69%, 75.70%] | 60.87% | [46.46%, 73.61%] |
| **0.85** | ACTIVE_SIGNAL | 14 | 13 | 1 | 0.33% | 71.43% | [45.35%, 88.28%] | 69.23% | [42.37%, 87.32%] |
| **0.90** | ACTIVE_SIGNAL | **2** | 2 | 0 | **0.05%** | **50.00%** | **[9.45%, 90.55%]** | 50.00% | [9.45%, 90.55%] |

---

#### B. Model Random Forest (100 Trees)

| Ambang Batas ($\tau$) | Status Sinyal | Total Sinyal | Sinyal UP | Sinyal DOWN | Coverage Pasar (%) | Presisi Gabungan (%) | Wilson 95% CI | Presisi Sinyal UP (%) | Wilson UP 95% CI |
|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| **0.50** | ACTIVE_SIGNAL | 4.187 | 1.676 | 2.511 | 100.00% | 51.16% | [49.64%, 52.67%] | 45.41% | [43.04%, 47.80%] |
| **0.55** | ACTIVE_SIGNAL | 1.525 | 653 | 872 | 36.42% | 52.66% | [50.15%, 55.15%] | 48.85% | [45.04%, 52.68%] |
| **0.60** | ACTIVE_SIGNAL | 236 | 163 | 73 | 5.64% | 58.47% | [52.10%, 64.58%] | 55.21% | [47.55%, 62.64%] |
| **0.65** | ACTIVE_SIGNAL | 14 | 14 | 0 | 0.33% | 64.29% | [38.76%, 83.66%] | 64.29% | [38.76%, 83.66%] |
| **0.70** | **NO_SIGNAL** | **0** | 0 | 0 | **0.00%** | **N/A** | N/A | **N/A** | N/A |
| **0.75** | **NO_SIGNAL** | **0** | 0 | 0 | **0.00%** | **N/A** | N/A | **N/A** | N/A |
| **0.80** | **NO_SIGNAL** | **0** | 0 | 0 | **0.00%** | **N/A** | N/A | **N/A** | N/A |
| **0.85** | **NO_SIGNAL** | **0** | 0 | 0 | **0.00%** | **N/A** | N/A | **N/A** | N/A |
| **0.90** | **NO_SIGNAL** | **0** | 0 | 0 | **0.00%** | **N/A** | N/A | **N/A** | N/A |

---

### 3. Evaluasi Kritis terhadap Target Presisi 90%

Berdasarkan bukti empiris out-of-sample di atas:

1. **Target Presisi 90% TIDAK TERCAPAI pada Data Uji Riil:**
   - Tidak ada model yang mampu menghasilkan presisi 90% secara konsisten dan terverifikasi secara statistik.
   - Pada threshold $\tau = 0.90$, model HistGradientBoosting hanya menghasilkan **2 sinyal** dari 4.187 bar (coverage hanya 0,05%). Dari 2 sinyal tersebut, hanya 1 yang benar (presisi 50,00%). Interval kepercayaan Wilson 95% membentang sangat lebar dari **9,45% hingga 90,55%**, membuktikan bahwa tidak ada signifikansi statistik sama sekali.
   - Pada model Random Forest, pada ambang batas $\tau \ge 0.70$, model mengeluarkan **0 sinyal** (coverage 0,00%).
2. **Kesesuaian dengan Hukum Finansial Efisiensi Pasar:**
   - Dalam pasar saham terdaftar (BEI), pergerakan harga 5-hari forward mengandung tingkat kebisingan acak (*random noise*) yang sangat dominan.
   - Mengklaim presisi 90% pada prediksi arah harga 5-hari menggunakan indikator teknikal murni pada horizon out-of-sample adalah anomali matematis yang hampir pasti merupakan produk dari data leakage, p-hacking, atau over-fitting.
3. **Validitas Status "NO SIGNAL":**
   - Ketika model dihadapkan pada ketidakpastian tinggi, keputusan terbaik dan paling rasional secara kuantitatif adalah **ABSTAIN (NO SIGNAL)**.
   - Keputusan NO SIGNAL menyelamatkan modal portofolio dari friksi transaksi dan kerugian akibat tebakan acak. Status ini merupakan keluaran resmi yang sah dan valid dalam sistem Aether Market Intelligence.
