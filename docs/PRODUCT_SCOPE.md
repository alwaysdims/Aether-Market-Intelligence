# Cakupan Produk Aether — Asisten Saham Berbasis Gemini + ML

Aether melayani seluruh kebutuhan analitis dan operasional pasar modal saham Bursa Efek Indonesia (BEI):
1. **Pertanyaan dan Pembelajaran Saham**: Edukasi konsep pasar modal, rasio keuangan (PER, PBV, ROE, DER, Dividend Yield), cara analisis dan komparasi saham berbasis Gemini 3.8 Flash High.
2. **Analisis Teknikal dan Fundamental**: Pemrosesan bar EOD resmi, indikator teknikal (ATR, RVOL), dan telaah berita pasar Antara News.
3. **Konsultasi Analitis Portofolio**: Perhitungan posisi lot/lembar, modal pembelian (cost basis), nilai pasar terkini, floating PnL, alokasi target awal 50% trading / 50% investasi, dan risiko konsentrasi. Murni analitis — tanpa transaksi broker.
4. **Screening dan Rekomendasi Kuantitatif**: Pemeringkatan terstruktur berbasis rentang bar volatilitas (SWING), dividen defensif (DIVIDEND), dan komparasi 2-4 emiten terdaftar BEI.
5. **Prediksi ML & Integritas Sinyal**: RandomForest v1.0 (horizon 5 hari bursa, ambang keyakinan Tau 0.70). Status default `NO_SIGNAL (ABSTAIN)`. Emiten di luar validasi formal berstatus `MODEL_NOT_VALIDATED_FOR_INSTRUMENT`.
6. **Watchlist & Pemantauan**: Pengelolaan daftar pantau dinamis pengguna allowlisted.
7. **Pemeriksaan Sistem & Notifikasi**: Status kesehatan pipeline, audit database, dan notifikasi outbox Telegram.

## Matriks Mode dan Batasan Operasional

| Mode | Kontrak Layanan | Batas & Proteksi |
|---|---|---|
| **Day Trade** | Diberi respons `INTRADAY_DATA_UNAVAILABLE` | Belum ada feed intraday tick realtime. Bar EOD pukul 16:05 WIB dilarang dipakai untuk spekulasi harian. |
| **Swing** | Bar EOD, rentang volatilitas bar, dan prediksi ML 5 hari jika tervalidasi | Horizon 5 hari bursa. Jika keyakinan < 40%, sistem wajib ABSTAIN untuk melindungi modal. |
| **Investasi** | Analisis fundamental deskriptif & edukatif via Gemini | Prediksi ML 5 hari tidak berlaku untuk jangka panjang. Sinyal ML dilarang disamarkan sebagai rekomendasi investasi. |
| **Portofolio** | Evaluasi alokasi, floating PnL, dan risiko konsentrasi | Target preferensi awal 50/50. Data kepemilikan hanya dicatat atas input pengguna. Nol eksekusi broker. |
| **Screening** | Filter kuantitatif terhadap universe tersimpan | Menyatakan universe aktual yang diperiksa. Dilarang mengklaim 'terbaik seluruh BEI' jika hanya memeriksa subset emiten. |
| **Non-Saham** | Ditolak singkat secara sopan | Aether berfokus khusus pada saham BEI dan intelijen pasar modal. |

## Antarmuka dan Integritas

- **Telegram Bot**: Antarmuka interaktif utama dua arah (`@Info_BosKuu_bot`).
- **Dashboard Web**: Antarmuka visual Laravel untuk Overview, Market Monitor, News, AI History, dan Analisis Portofolio.
- **Auditabilitas**: Semua analisis terikat pada Evidence Snapshot dan Market Observation ID kanonikal yang sama.

