# INTEGRASI TELEGRAM DUA ARAH & PROTOKOL INTERAKSI (TELEGRAM TWO-WAY)
## AETHER MARKET INTELLIGENCE — TAHAP I LANJUTAN

Dokumen ini mendefinisikan arsitektur komunikasi Telegram dua arah, format pesan, penanganan pesan balasan (*reply chaining*), routing percakapan berbasis Gemini 9router (*tool calling*), serta manajemen watchlist dan riset on-demand multi-emiten BEI.

---

### 1. Arsitektur Transport & Aturan Penerima Tunggal (Single Inbound Receiver)

1. **Pemilik Inbound Updates:**
   - Proses **Hermes Gateway** (`Hermes_Gateway.vbs`, PID aktif di Windows background service) adalah satu-satunya pemilik polling koneksi bot Telegram (`@Info_BosKuu_bot`).
   - Dilarang menjalankan proses background `getUpdates` atau webhook mandiri kedua yang bersaing menggunakan token bot yang sama karena akan menimbulkan tabrakan update (*HTTP 409 Conflict*).
2. **Jalur Eksekusi Dua Arah (Plugin `aether_router` v1.1.0):**
   - Plugin menargetkan pesan teks pengguna allowlisted (`5923895922`); prioritas group dan cakupan seluruh command belum dibuktikan lewat Telegram nyata. Jangan klaim 100% interception sebelum update_id dan outbound message_id cocok.
   - Plugin memanggil `php artisan hermes:telegram-chat`; jalur worker riset dan outbox terpisah. Perintah watchlist dan jalur bebas wajib diuji saat runtime.
   - Hasil dieksekusi dan dijawab langsung ke Telegram oleh handler plugin, lalu proses propagation dihentikan menggunakan `ApplicationHandlerStop()`.

---

### Kontrak mode dan bukti

`analisis PTRO untuk day trade` tidak boleh memakai EOD sebagai realtime; saat ini dijawab `INTRADAY_DATA_UNAVAILABLE`. Permintaan investasi tanpa laporan fundamental tervalidasi dijawab `INVESTMENT_EVIDENCE_UNAVAILABLE`. Untuk swing, periksa instrumen, sumber, sesi BEI, data valid, evidence ID, dan horizon model sebelum menampilkan probabilitas. Watchlist tidak mengubah universe ML. Hasil riset asinkron baru sah setelah tersimpan; delivery Telegram baru sah setelah receipt message_id nyata.

Diketahui belum tuntas: percakapan tool masih dapat membuat update sintetis; worker riset dapat balapan; outbox sudah mengklaim item atomik tetapi konkurensi lintas proses serta receipt live belum diuji; dashboard market dan Telegram belum memakai tabel harga kanonikal sama. Tidak ada bukti lima giliran Telegram aktual baru. Jangan mengutip uji CLI sebagai bukti transport.

### 2. Format Pesan Sinyal & Penghapusan Tombol Tindakan

Sesuai instruksi audit dan kebijakan perlindungan risiko finansial, **seluruh tombol perdagangan otomatis telah dihapus secara permanen**:
- ❌ HAPUS: `View Signal`
- ❌ HAPUS: `One Click Follow`
- ❌ HAPUS: `Set Auto Trading`
- ❌ HAPUS: Rekomendasi leverage dan ukuran lot spekulatif.

#### Contoh Pesan Awal (Initial Directional Signal):
```text
🎯 AETHER MARKET INTELLIGENCE — PREDIKSI SINYAL
══════════════════════════════════════════════════
⚠️ MODE RISET KUANTITATIF (RESEARCH ONLY) — BUKAN SARAN INVESTASI

📌 IDENTITAS SINYAL
• Signal ID     : PRED_20261023_BBCA_a1b2
• Saham / Ticker: $BBCA (Bursa Efek Indonesia)
• Status Sinyal : PUBLISHED_DIRECTIONAL
• Arah Prediksi : UP

💵 HARGA & WAKTU ACUAN
• Harga Acuan   : Rp 5.025,00 (23 Oct 2026)
• Kualitas Data : EOD Final (Yahoo Finance v8 / Delayed 15m)
• Waktu Publikasi: 23 Oct 2026, 16:05 WIB
• Horizon Uji   : 5 Hari Bursa (Jatuh Tempo: 30 Oct 2026)
• Batas Expiry  : Maksimal 14 Hari Kalender (06 Nov 2026, 16:05 WIB)

🤖 MODEL & ESTIMASI PROBABILITAS
• Model / Versi : RandomForest (v1.0)
• P(Naik / UP)  : 72.40% | P(Turun / DOWN): 27.60%
• Tingkat Yakin : 44.80% (Ambang Batas Selektif: >= 40.00% / Tau 0.70)
• Target Harga  : Rp 5.250,00
• Batas Pembatal: Rp 4.900,00

📰 INTELEJENSI BERITA & POTENSI DAMPAK
• Pertumbuhan Kredit Perbankan Kuartal III Capai 10.8%
  Sumber: LKBN ANTARA | https://www.antaranews.com/...

──────────────────────────────────────────────────
⚠️ Dilarang menggunakan leverage atau trading otomatis.
Perubahan harga pasar bukan representasi keuntungan pengguna.
```

#### Contoh Pesan Digest Harian NO_SIGNAL (Anti-Spam):
Jika model menolak berspekulasi karena keyakinan $< 40\%$, sistem mengirim 1 pesan ringkasan digest untuk mencegah spam:
```text
📋 AETHER MARKET INTELLIGENCE — DIGEST HARIAN NO_SIGNAL
════════════════════════════════════════════════════════
Tanggal Bursa: 23 Oct 2026 (Penutupan EOD 16:00 WIB)
Status       : SELURUH INSTRUMEN ABSTAIN (NO_SIGNAL)

Sistem menerapkan protokol selective prediction dengan ambang batas keyakinan minimal 40.00% (Tau 0.70). Tidak ada instrumen yang memenuhi kriteria arah yang andal:

• $BBCA: Conf 30.1% (P(UP) 65.1%) -> ABSTAIN
• $BBRI: Conf 29.9% (P(UP) 64.9%) -> ABSTAIN
• $BMRI: Conf 31.4% (P(UP) 65.7%) -> ABSTAIN
• $BBNI: Conf 28.3% (P(UP) 64.2%) -> ABSTAIN
• $ASII: Conf 27.6% (P(UP) 63.8%) -> ABSTAIN
• $TLKM: Conf 29.1% (P(UP) 64.5%) -> ABSTAIN
• $MAPI: Conf 32.5% (P(UP) 66.2%) -> ABSTAIN
• $ARTO: Conf 33.8% (P(UP) 66.9%) -> ABSTAIN
• $MEDC: Conf 28.0% (P(UP) 64.0%) -> ABSTAIN
• $SMRA: Conf 30.8% (P(UP) 65.4%) -> ABSTAIN

🛡️ Keputusan NO_SIGNAL melindungi modal dari friksi bursa & sinyal palsu.
════════════════════════════════════════════════════════
```

#### Contoh Pesan Pembaruan (Reply Update Chaining):
Dikirim dengan mengaitkan parameter `reply_to_message_id` ke ID pesan Telegram sinyal awal:
```text
🔔 PEMBARUAN PEMANTAUAN — $BBCA
──────────────────────────────────────────
Signal ID   : PRED_20261023_BBCA_a1b2
Waktu Event : 27 Oct 2026, 14:15 WIB
Tipe Event  : TARGET_HIT
Harga Amati : Rp 5.250,00
Keterangan  : Target harga 5.250 terkonfirmasi tersentuh pada bar 27 Oct 2026.

Status Sinyal Saat Ini : TARGET_HIT
Evaluasi 5-Hari Bursa  : PENDING
──────────────────────────────────────────
```

---

### 3. Dukungan Perintah & Percakapan Alami

Service `TelegramTwoWayService` melayani perintah dan percakapan berbasis database:

| Tipe Masukan | Contoh Input Pengguna | Respon & Sumber Data |
|---|---|---|
| Perintah Sistem | `/help` | Menampilkan panduan perintah dan contoh pertanyaan. |
| Perintah Status | `/status` | Menampilkan jumlah observasi harga, bar EOD terakhir, dan status DB. |
| Perintah Sinyal | `/signals` | Menampilkan daftar 5 sinyal forward terbaru beserta status dan probabilitas. |
| Detail Sinyal | `/signal <ID>` | Menampilkan rincian lengkap sinyal awal dan bukti snapshot. |
| Perintah Rekap | `/recap` | Menampilkan ringkasan populasi, pemantauan 14 hari, dan akurasi 5-hari. |
| Perintah Berita | `/news BBCA` | Menampilkan 3 berita terkini LKBN ANTARA terkait emiten. |
| Watchlist Tambah | *"tambahkan PTRO ke watchlist"* | Menambahkan emiten ke `user_watchlists` secara idempotent. |
| Watchlist Daftar | `/watchlist` atau *"daftar watchlist"* | Menampilkan seluruh saham di watchlist beserta harga terakhir. |
| Riset On-Demand | *"analisis saham PTRO"* | Mengambil data EOD via Yahoo Finance, memvalidasi OHLCV, dan membuat analisis deskriptif. |
| Konsultasi Portofolio | `/portfolio` atau *"saya mau konsultasi portofolio"* | Menghitung ringkasan portofolio: target 50% trading / 50% investasi, nilai pasar, floating PnL, konsentrasi risiko (Zero Broker Order). |
| Screening Kuantitatif | `/screen` atau *"cari kandidat saham untuk swing"* | Menampilkan kandidat saham berdasarkan rentang volatilitas bar, likuiditas volume, dan kondisi pembatalan tesis. |
| Komparasi Saham | `/bandingkan` atau *"bandingkan BBCA dan BBRI"* | Komparasi multi-emiten: harga penutupan, rentang bar, volume, status model ML, dan berita LKBN ANTARA. |
| Edukasi Saham | *"bagaimana cara membandingkan saham untuk investasi?"* | Gemini menjelaskan metodologi valuasi (PER, PBV), profitabilitas (ROE), solvabilitas (DER), dan horizon investasi. |
| Pertanyaan Non-Saham | *"resep membuat nasi goreng"* | Ditolak singkat secara sopan: Aether berfokus khusus pada saham BEI dan intelijen pasar modal. |
| Pertanyaan Kondisi | *"BBCA terbaru bagaimana?"* | Mengambil observasi penutupan terakhir, tanggal bar, dan status sinyal aktif dari DB. |
| Pertanyaan Validitas | *"Sinyal ini masih berlaku?"* | Memeriksa waktu `expires_at` (14 hari) dan tanggal jatuh tempo evaluasi 5 hari. |
| Pertanyaan Alasan | *"Kenapa prediksinya abstain?"* | Mengambil probabilitas model, ambang batas keyakinan, dan alasan penolakan dari DB. |
| Pertanyaan Target | *"Target mana yang sudah teramati?"* | Mengambil daftar event pemantauan dan harga observasi dari tabel `monitoring_events`. |
| Percakapan Bebas | *"hai, kamu bisa bantu apa?"* | Gemini `ag/gemini-3.8-flash-high` via 9router menjawab ramah dan menjelaskan kapabilitas sistem. |

---

### 4. Keamanan Percakapan & Kontrol Akses (Security Guard)

1. **Allowlist Pengguna Ketat:**
   - Hanya User ID `5923895922` yang diizinkan berinteraksi dengan bot.
   - Pesan dari pengirim yang tidak terdaftar ditolak secara aman (*Status: REJECTED, Intent: UNAUTHORIZED_ACCESS*).
2. **Perlindungan Terhadap Prompt Injection:**
   - Seluruh teks pesan masuk diperlakukan murni sebagai **data pasif**, bukan instruksi sistem atau eksekusi SQL mentah.
   - Dilarang membuka akses terminal shell, eksekusi SQL bebas, atau pencetakan kredensial `.env` melalui pesan percakapan.
3. **Pemisahan Mode Inferensi:**
   - On-demand AI diizinkan khusus untuk percakapan interaktif dan riset emiten (`AI_LIVE_INFERENCE_ON_DEMAND_ENABLED=true`).
   - Inferensi batch otomatis harian (EOD Pipeline) tetap terkunci nonaktif (`AI_LIVE_INFERENCE_EOD_ENABLED=false`).
