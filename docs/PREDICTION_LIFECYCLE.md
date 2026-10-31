# SIKLUS HIDUP PREDIKSI & KONTRAK EVALUASI (PREDICTION LIFECYCLE)
## AETHER MARKET INTELLIGENCE

Dokumen ini mendefinisikan kontrak resmi siklus hidup prediksi, tata kelola status di PostgreSQL, aturan kedaluwarsa 14 hari, dan disiplin evaluasi 5 hari bursa.

---

### 1. Kontrak Prediksi & Definisi Probabilitas

Setiap prediksi kuantitatif yang diterbitkan oleh sistem Aether Market Intelligence tunduk pada kontrak matematika berikut:

1. **Target Prediksi:**
   Arah pengembalian modal 5 hari bursa forward (*5-trading-day forward close-to-close directional return*):
   $$R_{5d} = \frac{\text{Close}_{t+5} - \text{Close}_t}{\text{Close}_t}$$
   - **Label Biner:**
     * $y = 1$ (UP): Jika $R_{5d} > 0$
     * $y = 0$ (DOWN): Jika $R_{5d} \le 0$
2. **Definisi Probabilitas & Urutan Kelas:**
   - Jumlah kelas: 2 (Biner).
   - Urutan kelas scikit-learn: `classes_ = [0, 1]` di mana index 0 adalah DOWN, index 1 adalah UP.
   - Probabilitas per kelas:
     * $P(\text{DOWN}) = P(y = 0)$
     * $P(\text{UP}) = P(y = 1)$
     * Validitas: $P(\text{DOWN}) \ge 0$, $P(\text{UP}) \ge 0$, dan $P(\text{DOWN}) + P(\text{UP}) = 1.0000$.
   - **Predicted Class:**
     $$\text{Predicted Class} = \begin{cases} 1 \text{ (UP)}, & \text{jika } P(\text{UP}) > 0.50 \\ 0 \text{ (DOWN)}, & \text{jika } P(\text{DOWN}) > 0.50 \end{cases}$$
   - **Max Probability:**
     $$\max(P) = \max(P(\text{DOWN}), P(\text{UP})) \in [0.50, 1.00]$$
   - **Skor Keyakinan (Confidence Score):**
     $$\text{Confidence Score} = 2 \times \max(P) - 1 = |P(\text{UP}) - 0.50| \times 2 \in [0.00, 1.00]$$
3. **Hubungan Ambang Batas $\tau = 0.70$ dengan Confidence $0.40$:**
   - Sistem menetapkan ambang batas selektif $\tau = 0.70$.
   - Keputusan terbit directional hanya diambil jika:
     * Sinyal UP: $P(\text{UP}) \ge 0.70 \implies \text{Confidence} \ge 2(0.70) - 1 = 0.40$.
     * Sinyal DOWN: $P(\text{DOWN}) \ge 0.70 \iff P(\text{UP}) \le 0.30 \implies \text{Confidence} \ge 2(0.70) - 1 = 0.40$.
   - Jika probabilitas model berada di zona ketidakpastian ($0.30 < P(\text{UP}) < 0.70 \iff \text{Confidence} < 0.40$), sistem **wajib** menerbitkan status **`NO_SIGNAL_ABSTAIN`** dengan arah `ABSTAIN`.

---

### 2. Pemisahan 4 Dimensi Status (State Dimensions)

Sistem melarang penggunaan satu kolom enum tunggal untuk merepresentasikan seluruh keadaan sinyal. Status dipisahkan ke dalam 4 dimensi independen pada tabel `prediction_signals`:

| Dimensi Status | Nilai yang Diizinkan | Deskripsi Operasional |
|---|---|---|
| **1. Signal Status** | `CANDIDATE`<br>`PUBLISHED_DIRECTIONAL`<br>`NO_SIGNAL_ABSTAIN` | Status saat pembuatan: apakah sinyal memenuhi syarat terbit directional atau di-abstain karena keyakinan rendah. |
| **2. Monitoring Status** | `NOT_MONITORED`<br>`ACTIVE`<br>`TARGET_HIT`<br>`INVALIDATED`<br>`AMBIGUOUS`<br>`EXPIRED`<br>`CANCELLED` | Siklus pemantauan selama jendela maksimal 14 hari kalender. Sinyal abstain bernilai `NOT_MONITORED`. |
| **3. Evaluation Status** | `PENDING`<br>`EVALUATED_CORRECT`<br>`EVALUATED_INCORRECT`<br>`EVALUATED_NEUTRAL`<br>`CANNOT_EVALUATE_MISSING_DATA` | Status verifikasi arah harga pada akhir horizon tetap 5 hari bursa (`evaluation_due_date`). |
| **4. Delivery Status** | `QUEUED`<br>`SENT`<br>`FAILED`<br>`DELIVERY_UNKNOWN` | Status pengiriman pesan pada tabel `telegram_outbox`. |

---

### 3. Diagram Alur Transisi Status (State Transition Flowchart)

```text
[ Data EOD Masuk & Terverifikasi ]
              │
              ▼
   [ Evidence Snapshot DB ]
              │
              ▼
    [ Model ML Inference ]
              │
      Confidence >= 0.40?
     ├─── TIDAK ───► [ Signal: NO_SIGNAL_ABSTAIN | Monitoring: NOT_MONITORED ]
     │                      │
     │                      ▼
     │               [ Digest Harian EOD di Outbox ]
     │                      │
     │                      ▼ (Jatuh Tempo 5 Hari Bursa)
     │               [ Evaluation: EVALUATED_NEUTRAL ]
     │
     └─── YA ──────► [ Signal: PUBLISHED_DIRECTIONAL | Monitoring: ACTIVE ]
                            │
                            ▼
                     [ Outbox: INITIAL_SIGNAL ]
                            │
              ┌─────────────┴─────────────┐
              │ Selama Jendela 14 Hari:   │
              ├───────────────────────────┤
              │ 1. High >= Target         │──► Monitoring: TARGET_HIT
              │ 2. Low <= Invalidation    │──► Monitoring: INVALIDATED
              │ 3. Sentuh Keduanya        │──► Monitoring: AMBIGUOUS
              │ 4. Waktu > 14 Hari        │──► Monitoring: EXPIRED
              └─────────────┬─────────────┘
                            │
                            ▼ (Tepat 5 Hari Bursa)
                     [ Evaluasi Horizon: EVALUATED_CORRECT / INCORRECT ]
```

---

### 4. Aturan Waktu & Kedaluwarsa (Expiry Rules)

1. **Waktu Keputusan (*Decision Timestamp*):**
   - Penutupan resmi perdagangan bursa BEI hari $t$ pukul 16:00:00 WIB.
2. **Waktu Publikasi (*Published At*):**
   - Pukul 16:05:00 WIB setelah data EOD difinalisasi dan snapshot di-commit ke DB.
3. **Horizon Evaluasi Utama:**
   - Tepat **5 hari bursa** dihitung menggunakan kalender resmi BEI (`BeiCalendarService::addTradingDays($decisionDate, 5)`).
   - Menghindari akhir pekan dan hari libur bursa nasional.
4. **Batas Pemantauan Maksimal (*Expires At*):**
   - Tepat **14 hari kalender** sejak `published_at` (`expires_at = published_at + 14 days`).
   - Batas ini adalah jendela pemantauan peristiwa *target-touch* dan *invalidation*.
   - **Larangan Perpanjangan:** Dilarang memperpanjang waktu `expires_at` hanya karena target harga belum tercapai.
   - **Larangan Retrospektif:** Pergerakan harga setelah `expires_at` dilarang diklaim sebagai keberhasilan sinyal lama.
5. **Independensi Evaluasi Horizon dan Pemantauan:**
   - Evaluasi arah pada hari bursa ke-5 tetap dievaluasi sesuai harga penutupan hari bursa ke-5, terlepas dari apakah pemantauan 14 hari menghasilkan *target hit* atau *invalidation* di kemudian hari.
