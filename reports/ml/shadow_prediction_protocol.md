# PROTOKOL PREDIKSI BAYANGAN & EVALUASI HORIZON (FASE H5)
## AETHER MARKET INTELLIGENCE

**Tanggal Rilis:** 25 Oktober 2026  
**Status Operasional:** RESEARCH MODE / ACTIVE FORWARD SHADOW  
**Lokasi Buku Besar (*Ledger*):** `data/shadow_predictions/shadow_predictions_ledger.json`  
**Aturan Utama:** ZERO BROKER ORDERS, IMMUTABLE HISTORICAL PREDICTIONS, DEFAULT NO_SIGNAL

---

### 1. Struktur Entitas Prediksi Bayangan (*Shadow Prediction Schema*)

Setiap prediksi bayangan yang dihasilkan oleh modul `quant-engine/src/tahap_h_regime_and_shadow.py` disimpan dalam format JSON terstruktur yang memuat 20 atribut wajib:

```json
{
  "prediction_id": "SHADOW_bb6a6176ff63378b",
  "ticker": "BBCA",
  "decision_date": "2026-10-23",
  "decision_timestamp": "2026-10-23T16:00:00+07:00",
  "model_version": "RandomForest_v1.0",
  "feature_schema_hash": "b268fdff729f",
  "reference_close": 5075.0,
  "probability_up": 0.6505,
  "probability_down": 0.3495,
  "confidence": 0.301,
  "market_regime": "HIGH_VOLATILITY",
  "signal_status": "NO_SIGNAL",
  "direction": "ABSTAIN",
  "reason_for_abstain": "CONFIDENCE_BELOW_THRESHOLD (Confidence: 0.30 < 0.40)",
  "predicted_horizon_days": 5,
  "evaluation_due_date": "2026-10-30",
  "evaluation_status": "PENDING",
  "evaluated_at": null,
  "actual_price": null,
  "actual_return_5d": null,
  "outcome": null,
  "prediction_correct": null
}
```

---

### 2. Disiplin Kekekalan & Evaluasi Pasca-Horizon

1. **Kekekalan Rekod (*Immutability Guarantee*):**
   - Kolom keputusan (`decision_date`, `probability_up`, `confidence`, `signal_status`, `direction`, `reason_for_abstain`) bersifat **kekal (immutable)**. Setelah disimpan ke dalam berkas ledger, kolom ini dilarang diedit, ditimpa, atau dihapus.
2. **Evaluasi Terpisah Pasca-Jatuh Tempo:**
   - Evaluasi hanya dijalankan ketika tanggal bursa telah mencapai `evaluation_due_date`.
   - Modul `ShadowPredictionEngine.evaluate_shadow_ledger()` mencocokkan harga penutupan aktual dari data EOD bersih (`data/cleaned/{TICKER}_cleaned.csv`).
   - Sinyal berstatus `ABSTAIN` dicatat secara transparan dengan `prediction_correct = null` (tidak dihitung sebagai prediksi salah maupun prediksi benar).
3. **Pemisahan Status Prediksi:**
   - `PENDING`: Menunggu tanggal jatuh tempo 5 hari bursa.
   - `EVALUATED`: Telah diverifikasi dengan harga aktual.
   - `INVALID`: Tanggal bursa libur atau data penutupan tidak tersedia >30 hari.

---

### 3. Integrasi Kebijakan Perlindungan Modal

- Selama model belum membuktikan keunggulan prediktif di atas baseline naif pada evaluasi *out-of-sample*, nilai ambang batas keyakinan ($\tau$) dikunci pada $0.70$ (Confidence $\ge 0.40$).
- Jika keyakinan model $< 0.40$, sistem secara otomatis mengeluarkan status **`NO_SIGNAL` / `ABSTAIN`**, mencegah pembukaan transaksi fiktif di shadow portfolio.
