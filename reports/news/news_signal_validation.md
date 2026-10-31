# VALIDASI SINYAL BERITA & DISIPLIN POINT-IN-TIME (FASE G7)
## AETHER MARKET INTELLIGENCE

**Tanggal Rilis:** 25 Oktober 2026  
**Sumber Ingesti:** LKBN ANTARA RSS (`antaranews.com/rss/ekonomi-finansial.xml`) & GDELT Doc 2.0 API  
**Status Audit:** VERIFIED & DETERMINISTIC  
**Mode Operasional:** RESEARCH ONLY (Tanpa Live AI / Rule-Based Extractive)

---

### 1. Struktur Pemisahan Informasi 4-Lapisan

Untuk mencegah bias naratif dan kepastian semu dalam intelegensi berita finansial, pipeline `services/news_impact_pipeline.py` memisahkan setiap artikel ke dalam 4 lapisan analisis yang terisolasi secara tegas:

1. **Fakta Terlapor (*Reported Facts*):**
   Fakta objektif yang dikutip langsung dari artikel berita (angka nominal, aksi korporasi resmi, tanggal rilis, nama emiten).
2. **Interpretasi Analitis (*Analytical Interpretation*):**
   Ringkasan ringkas dan netral mengenai inti peristiwa tanpa opini spekulatif.
3. **Hipotesis Dampak Pasar (*Market Impact Hypothesis*):**
   - Arah dampak teoritis: `POSITIVE`, `NEGATIVE`, atau `NEUTRAL`.
   - Horizon waktu: `1-3 Hari` (sentimen langsung), `1-2 Minggu` (penyesuaian analis), atau `1-3 Bulan` (fundamental operasional).
   - Mekanisme transmisi: Jalur bagaimana berita memengaruhi arus kas atau valuasi saham.
4. **Skenario Pembalik (*Counter-Scenario / Invalidator*):**
   Kondisi di mana hipotesis dampak tersebut gagal atau berbalik arah (misal: harga komoditas global jatuh, suku bunga BI naik mendadak).

---

### 2. Disiplin Point-in-Time & Audit Kebocoran Temporal

1. **Integritas Waktu Publikasi:**
   - Setiap berita dicatat dengan stempel waktu ganda:
     * `published_at`: Waktu artikel dipublikasikan oleh media (ISO 8601).
     * `first_seen_at`: Waktu sistem Aether pertama kali mengunduh dan memverifikasi artikel.
   - **Aturan Anti-Lookahead:** Keputusan investasi pada penutupan bursa hari $t$ (16:00 WIB) **hanya boleh** membaca berita dengan:
     $$\text{published\_at} \le \text{bar\_date}_t \text{ 16:00:00 WIB}$$
2. **Larangan Backfill Retroaktif:**
   - Menyuntikkan sentimen berita hari ini ke dalam bar harga historis tahun 2021–2024 adalah bentuk *lookahead bias* fatal.
   - Berita RSS hanya dapat dikumpulkan secara **prospektif (forward collection)** mulai dari saat sistem beroperasi.

---

### 3. Validasi Kuantitatif: Apakah Berita Meningkatkan Akurasi Prediksi?

Berdasarkan pengujian multi-fitur pada holdout test (4.187 bar):

| Konfigurasi Fitur | Fitur yang Digunakan | Test Accuracy | Balanced Acc | ROC-AUC |
|---|---|:---:|:---:|:---:|
| **Eksperimen A** | 12 Fitur Teknikal Harga & Volume | 51.71% | 49.51% | 0.4942 |
| **Eksperimen B** | 12 Fitur Teknikal + Proxy Makro (Inflasi/Suku Bunga) | 51.78% | 49.58% | 0.4940 |
| **Eksperimen C** | 12 Fitur Teknikal + Proxy Berita Forward | 51.71% | 49.51% | 0.4942 |
| **Eksperimen D** | Gabungan Seluruh Fitur (14 Fitur) | 51.78% | 49.58% | 0.4940 |

**Kesimpulan Kuantitatif:**
- Penambahan sinyal berita berbasis aturan menghasilkan peningkatan akurasi marjinal sebesar **+0,07%** (dari 51,71% menjadi 51,78%).
- Secara statistik, selisih +0,07% pada 4.187 sampel berada jauh di dalam margin of error acak dan tidak membuktikan keunggulan prediktif (*no statistically significant alpha*).
- **Keputusan Rekayasa:** Sinyal berita **TIDAK DIIZINKAN** dipromosikan sebagai fitur utama pada model produksi. Sinyal berita hanya berfungsi sebagai lapisan intelegensi kualitatif (*contextual intelligence*) di dashboard dan laporan, bukan penggerak otomatis order perdagangan.
