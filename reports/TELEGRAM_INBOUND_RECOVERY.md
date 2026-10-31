# LAPORAN PEMULIHAN INBOUND TELEGRAM & AUDIT TRANSPORT JARINGAN

**Tanggal Audit:** Selasa, 27 Oktober 2026  
**Workspace:** `E:\Aether-Market-Intelligence`  
**Host:** Windows 11 (UTC+07:00 / Asia/Jakarta)  
**Status Evaluasi:** PENDING (Menunggu Inbound Uji Sederhana "cek bot" Pengguna)

---

## 1. REKONSILIASI WAKTU AUDIT & ZONA WAKTU

Terdapat perbedaan persepsi waktu log sebelumnya akibat konversi zona waktu UTC dan WIB (WIB = UTC+07:00):

| Peristiwa / Komponen | Timestamp UTC | Timestamp WIB (Host) | Bukti Audit & Keterangan |
|---|---|---|---|
| **Waktu Host Sekarang** | `2026-10-27 07:32:46 UTC` | `2026-10-27 14:32:46 WIB` | Verifikasi OS via terminal `date -u` & `date`. |
| **Gateway Start / Reconnect Terakhir** | `2026-10-27 13:31:46 UTC`*(log stamp)* / `2026-10-27 06:31:46 UTC`*(reconciled)* | `2026-10-27 13:31:46 WIB` | PID 16796 (sebelumnya PID 12040 terminasi unclean). |
| **Penjelasan Selisih 20:31 WIB vs 12:07 WIB** | - | - | Log Hermes menggunakan format waktu lokal host (`13:31:46` di log adalah **13:31 WIB**, bukan UTC). Penafsiran sebelumnya mengira 13:31 adalah UTC sehingga menghasilkan 20:31 WIB. Faktanya: Gateway reconnect pukul **13:31 WIB**, sedangkan dispatcher log terakhir pukul **12:07 WIB** berjalan pada siklus terpisah di latar belakang. |
| **Polling Sukses Terakhir (Confirmed)** | `2026-10-27 07:11:09 UTC` | `2026-10-27 14:11:09 WIB` | `gateway.log`: `Telegram polling recovered: getUpdates progressing (generation 2)`. |
| **Update Telegram Terakhir Diterima** | `2026-10-27 02:00:29 UTC` | `2026-10-27 09:00:29 WIB` | `telegram_update_receipts_8583524735.json`: Update ID `496962993` (pesan jam 09.05). |
| **Dispatcher Terakhir Berjalan** | `2026-10-27 05:07:03 UTC` | `2026-10-27 12:07:03 WIB` | Task Scheduler `\AetherScheduledDispatcher_1min` mengeksekusi `scripts\run_scheduled_dispatcher.bat`. |

---

## 2. VERIFIKASI IDENTITAS BOT & KONFIGURASI AKTIF

- **Bot Username:** `@Info_BosKuu_bot`
- **Bot ID (Numeric):** `8583524735`
- **Allowlist Pengguna Terdaftar:** `5923895922` (`Orlando Dimas Saputra_FT_PTIK_SNBP`)
- **Gateway Process State:**
  * PID: `16796` (`python hermes_cli\main.py gateway run`)
  * Startup Time: `179308265139` (aktif stabil sejak reconnect)
  * Status Socket: Control pipe aktif di `\\.\pipe\hermes-gateway-62888fc090722868`
  * State Platform Telegram: `connected` (`gateway_state.json`, updated `14:11:09 WIB` / `07:11:09 UTC`)
- **Plugin Registered:** `aether_router` v1.1.0 (Wired native PTB handler di group 0, mendahului core LLM loop).

---

## 3. AUDIT RECEIVER & PENYEBAB PESAN TIDAK DITERIMA

1. **Jumlah Polling Receiver:**
   - TUNGGAL: Hanya Hermes Gateway (`PID 16796`) yang memegang token `8583524735`.
   - Tidak ada proses polling ganda atau konflik getUpdates (`409 Conflict: terminated by other getUpdates` = 0).
2. **Status Webhook:**
   - Webhook tidak aktif (Gateway murni beroperasi dalam mode polling long-poll PTB).
3. **Drop Pending Updates Policy:**
   - Parameter `platforms.telegram.extra.drop_pending_on_cold_boot: false` aktif.
   - Gateway secara eksplisit mempertahankan update yang tertahan saat cold boot (`Cold boot: preserving Telegram updates queued while offline`).
4. **Allowlist Pengirim & Titik Drop:**
   - Allowlist pengirim dikonfigurasi ketat pada User ID `5923895922`.
   - `aether_router` memeriksa: `sender_id in ALLOWLISTED_USERS`. Jika cocok, pesan dicegat langsung ke Laravel CLI tanpa mencapai LLM.
   - Tidak ada catatan drop allowlist pada log aktif.
5. **Akar Masalah Ketiadaan Pesan Masuk:**
   - Audit `telegram_update_receipts_8583524735.json` dan `gateway.log` menunjukkan Update ID terakhir yang pernah dikirimkan server Telegram ke polling receiver adalah **`496962993`** (pukul 09:00:29 UTC / 16:00:29 WIB).
   - Selama periode 13:46 hingga 14:10 WIB, sempat terjadi degradasi jaringan ke IP sticky `149.154.166.110` (ConnectTimeout) yang pulih pada pukul **14:11:09 WIB (generation 2)**.
   - Telegram Bot API belum mengirimkan payload pembaruan baru dari aplikasi client pengguna, atau pesan pengguna dikirimkan saat transisi jaringan sedang terjadi sehingga belum terdispatch oleh server Telegram.

---

## 4. BUKTI KESEHATAN KONEKSI TELEGRAM

- **Status Polling:** RECOVERED & HEALTHY (`generation 2`, `getUpdates progressing`).
- **Heartbeat & Transport:**
  * Probe getUpdates berjalan normal tanpa exception.
  * `pending_update_count` pada server Bot API: 0 (tidak ada backlog antrean yang tertahan tanpa dikonsumsi).
  * TLS and HTTPX Request: Beroperasi normal di port 443.

---

## 5. OBSERVABILITAS TRANSPORT & MATRIKS STATUS

| Dimensi Observabilitas | Status Terkini | Waktu Terverifikasi |
|---|---|---|
| **Proses Gateway Hidup** | **VERIFIED** (PID 16796) | Berjalan kontinu |
| **Polling Transport Sehat** | **VERIFIED** | `14:11:09 WIB` (`07:11:09 UTC`) |
| **Routing Plugin Aether Sehat** | **VERIFIED** | Handler group 0 wired |
| **Update ID Terakhir Diterima** | **`496962993`** | `2026-10-27 09:00:29 WIB` |
| **Live Inbound Pengguna Baru** | **PENDING** | Menunggu inbound uji sederhana |

---

## 6. PROTOKOL UJI INBOUND PALING SEDERHANA

Untuk memverifikasi jalur inbound tanpa komplikasi scheduling atau lateness data:

1. **Uji Sederhana Tahap 1:**
   - Pengguna mengirimkan pesan:
     ```text
     cek bot
     ```
   - Verifikasi rantai transit:
     `Telegram Client -> Bot API -> Polling Gateway (Update ID Baru) -> update_admission -> aether_router -> Laravel hermes:telegram-chat -> Outbound Telegram Reply (Msg ID)`.
2. **Uji Jadwal Tahap 2 (Setelah Tahap 1 Sukses):**
   - Setelah respons "cek bot" terbukti terkirim dan tercatat di `telegram_conversations`, pengguna mengirimkan perintah jadwal dengan target waktu WIB mendatang (misalnya 10–15 menit ke depan dari waktu sekarang).
