# LAPORAN CONTOH ANALISIS DAMPAK BERITA FINANSIAL & EKSPERIMEN FITUR
## AETHER MARKET INTELLIGENCE

**Tanggal Rilis:** 24 Oktober 2026, 23:50 WIB  
**Sumber Ingesti:** LKBN ANTARA RSS (`ekonomi-finansial.xml`) & GDELT Doc 2.0 API  
**Metode Analisis:** Rule-Based Extractive Heuristic (`rule_based_extractive`)  
**Status AI Live:** `DISABLED (Nol Inferensi Berbayar / Nol Request 9Router)`

---

## 1. Spesifikasi & Format Keluaran Analisis Berita

Setiap berita yang diindeks oleh sistem diproses melalui pipeline ekstraksi terstruktur:
1. **Pemisahan Fakta vs Opini:** Ekstraksi kalimat deklaratif faktual ke dalam `key_facts`.
2. **Pemetaan Entitas (Ticker Mapping):** Mencocokkan kata kunci emiten dan sektor terhadap 10 saham universe BEI.
3. **Klasifikasi Arah Dampak:** Memberikan estimasi dampak (`positive`, `negative`, `mixed`, `unclear`).
4. **Mekanisme Kausal & Pembatal:** Menjelaskan jalur transmisi ekonomi dan kondisi yang dapat membatalkan dampak (*counter-scenario*).
5. **Transparansi Metodologi:** Diberi label eksplisit `analysis_method = rule_based_extractive` untuk mencegah klaim palsu seolah-olah dihasilkan oleh model penalaran AI murni.

---

## 2. Contoh Nyata Hasil Ekstraksi Berita Terverifikasi

### Contoh 1: Sektor Perbankan / BPR & Regulasi Keuangan
```json
{
  "id": "ANTARA_88715669e3c9",
  "headline": "LPS terima sita lelang BPR KRI Rp2,7 miliar terkait kasus tipikor",
  "source": "LKBN ANTARA News",
  "url": "https://www.antaranews.com/berita/5807987/lps-terima-sita-lelang-bpr-kri-rp27-miliar-terkait-kasus-tipikor",
  "published_at": "Fri, 23 Oct 2026 19:24:30 +0700",
  "first_seen_at": "2026-10-24T23:40:14.398794+07:00",
  "key_facts": [
    "Lembaga Penjamin Simpanan (LPS) menerima uang hasil sita lelang Rp2,78 miliar terkait kasus tindak pidana korupsi BPR KRI.",
    "Pemulihan aset dilakukan untuk mengoptimalkan pengembalian dana penjaminan simpanan nasabah."
  ],
  "summary": "LPS menerima pengembalian dana sita lelang sebesar Rp2,78 miliar dari penanganan kasus BPR KRI guna menjaga kepercayaan publik terhadap perbankan.",
  "related_tickers": ["BBRI", "BMRI", "BBNI", "BBCA"],
  "related_sectors": ["Perbankan BUMN", "Perbankan Swasta"],
  "impact_direction": "positive",
  "impact_horizon": "1_to_5_days",
  "impact_mechanism": "Penguatan kredibilitas dan efektivitas resolusi penjaminan simpanan oleh LPS meredakan kekhawatiran risiko sistemik di industri perbankan.",
  "counter_scenario": "Munculnya kasus gagal bayar baru pada bank perekonomian rakyat lain yang memerlukan intervensi likuiditas besar.",
  "analysis_method": "rule_based_extractive",
  "limitations": "Ekstraksi heuristik berbasis kata kunci; tidak membaca dokumen audit mendalam otoritas."
}
```

### Contoh 2: Sektor Keuangan Makro / Bulan Inklusi Keuangan
```json
{
  "id": "ANTARA_89b7dd6e0d41",
  "headline": "OJK Papua libatkan 23 industri jasa keuangan dalam BIK 2026",
  "source": "LKBN ANTARA News",
  "url": "https://www.antaranews.com/berita/5809535/ojk-papua-libatkan-23-industri-jasa-keuangan-dalam-bik-2026",
  "published_at": "Sat, 24 Oct 2026 18:22:35 +0700",
  "first_seen_at": "2026-10-24T23:40:14.398794+07:00",
  "key_facts": [
    "OJK Papua melibatkan 23 industri jasa keuangan dalam Bulan Inklusi Keuangan (BIK) 2026.",
    "Kegiatan bertujuan memperluas akses produk perbankan, pasar modal, dan asuransi di wilayah timur Indonesia."
  ],
  "summary": "OJK mendorong pemerataan akses keuangan di Papua melalui kolaborasi 23 institusi jasa keuangan pada BIK 2026.",
  "related_tickers": ["IHSG_MACRO"],
  "related_sectors": ["Makroekonomi Nasional", "Industri Keuangan"],
  "impact_direction": "unclear",
  "impact_horizon": "1_to_5_days",
  "impact_mechanism": "Informasi bersifat makro-sosial / seremonial tanpa transmisi langsung terhadap peningkatan laba bersih kuartalan emiten.",
  "counter_scenario": "Peningkatan signifikan pembukaan rekening baru atau penyaluran kredit produktif di wilayah tersebut.",
  "analysis_method": "rule_based_extractive",
  "limitations": "Analisis berbasis kata kunci terstruktur (rule-based heuristic) tanpa mempertimbangkan nuansa kontekstual mendalam LLM."
}
```

---

## 3. Disiplin Integritas Waktu (Point-in-Time Discipline)

Sesuai aturan penelitian:
> *"Untuk training historis, hanya pakai informasi yang tersedia sebelum decision timestamp. Berita yang baru dikumpulkan sekarang tidak boleh otomatis dipakai memprediksi masa lalu. Jika histori point-in-time tidak memadai, kumpulkan berita secara forward dahulu."*

1. **Aturan Penyaringan:**  
   Dalam simulasi historis, artikel berita hanya boleh mempengaruhi keputusan pada bar tanggal $t$ apabila:
   $$\text{published\_at} \le \text{decision\_timestamp}_t$$
2. **Ketiadaan Database Berita Historis Lengkap:**  
   Karena feed RSS dan API berita gratis hanya menyediakan berita berjalan (*rolling forward* 24–48 jam terakhir) dan tidak menyediakan akses gratis ke arsip berita 2021–2023, sistem **TIDAK MENGARANG** atau memalsukan sentimen berita untuk tahun-tahun masa lalu.
3. **Pengumpulan Berita Forward:**  
   Berita diindeks secara *forward* setiap hari pada jadwal EOD, disimpan secara imutabel di `data/news/`, dan baru dapat dijadikan fitur training setelah akumulasi data berjalan minimal 6–12 bulan.

---

## 4. Hasil Eksperimen Penambahan Fitur (A, B, C, D)

Eksperimen dilakukan pada Holdout Test Set (2025–2026) dengan model Logistic Regression yang terkalibrasi:

```text
Eksperimen                         | Fitur | Accuracy | Balanced Acc | ROC-AUC | Keterangan Metodologis
---------------------------------- | :---: | :------: | :----------: | :-----: | :---------------------
Exp A: Harga & Volume (Teknikal)   | 12    | 51.71%   | 49.51%       | 0.4942  | Baseline fitur teknikal murni
Exp B: Harga/Volume + Makro Proxy  | 13    | 51.78%   | 49.58%       | 0.4940  | Ditambah proxy tren makro IHSG
Exp C: Harga/Volume + Berita       | 13    | 51.71%   | 49.51%       | 0.4942  | Fitur sentimen berita forward
Exp D: Gabungan (Harga+Makro+News) | 14    | 51.78%   | 49.58%       | 0.4940  | Seluruh fitur gabungan
```

### Kesimpulan Eksperimen Fitur:
1. **Tidak Ada Peningkatan Otomatis:**  
   Penambahan fitur makro atau berita tanpa deret waktu historis yang padat dan terkalibrasi tidak meningkatkan performa out-of-sample (+0.07% accuracy, ROC-AUC tetap ~0.494).
2. **Kesesuaian dengan Hipotesis Pasar Efisien:**  
   Berita publik yang sudah terbit di portal media daring umumnya telah terdiskon dengan sangat cepat ke dalam pergerakan harga pasar, sehingga headline publik mentah memiliki nilai prediktif marjinal yang rendah terhadap return 5 hari bursa ke depan.
