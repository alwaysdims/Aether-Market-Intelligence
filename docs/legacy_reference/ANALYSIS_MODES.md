# Kontrak mode analisis

| Mode | Bukti wajib | Model yang boleh dipakai | Respons bila tidak cukup |
|---|---|---|---|
| DAY_TRADE | Bar intraday bertimestamp, latensi/finalitas sumber terukur, kecukupan histori intraday | Belum terverifikasi | `INTRADAY_DATA_UNAVAILABLE`; EOD dan prediksi 5 hari tidak boleh disajikan sebagai intraday. |
| SWING | Bar EOD valid, provenance, kecukupan fitur, instrumen dalam kontrak model | Model 5 hari bursa hanya jika instrumen/versi dan evaluasinya memenuhi kontrak; `NO_SIGNAL_ABSTAIN` tidak boleh dinaikkan jadi arah | Label stale/missing/`MODEL_NOT_VALIDATED_FOR_INSTRUMENT`; deskriptif saja bagi PTRO. |
| INVESTMENT | Laporan keuangan dengan periode, waktu publikasi, fundamental, aksi korporasi, tesis dan risiko | Belum ada model panjang yang terbukti | `INVESTMENT_EVIDENCE_UNAVAILABLE`; tidak memakai probabilitas 5 hari. |

`BeiUniverseRegistry::VALIDATED_ML_UNIVERSE` ialah daftar sepuluh ticker awal, bukan pembuktian kecakapan semua model pada semua kondisi. `KNOWN_BEI_ISSUERS` hanya registri identitas, sedangkan `user_watchlists` menyimpan pilihan per pengguna. Manifest historis `quant-engine/models/artifacts/experiment_manifest.json` berisi evaluasi riset, tidak membuktikan akurasi forward live. Strategi intraday di `quant-engine/src/strategy.py` bukan pipeline day-trade Telegram siap produksi.

Horizon evaluasi prediksi swing: 5 hari bursa tepat. Masa pantau sinyal trading: maksimum 14 hari kalender dari publikasi. Freshness harga mengikuti sesi BEI terakhir yang sudah final; jadwal ulasan investasi ditentukan oleh laporan/kejadian, bukan expiry 14 hari. Training, evaluasi, inference dipisah. Preferensi 50/50 trading/investasi hanya mengurutkan informasi, tidak memicu pembagian dana atau broker order.
