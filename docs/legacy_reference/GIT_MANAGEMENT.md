# PANDUAN MANAJEMEN GIT
## AETHER MARKET INTELLIGENCE

Dokumen ini menjelaskan strategi pemeliharaan histori Git untuk proyek Aether Market Intelligence pasca-konsolidasi.

---

## 1. Arsitektur Dual Repository Mandiri

Untuk menjaga kemurnian riwayat commit tanpa risiko konflik merge atau rewrite histori yang merusak, proyek ini menggunakan arsitektur repositori mandiri berlapis:

1. **Root Control Plane Repository (`E:\Aether-Market-Intelligence`):**
   - **Histori Asal:** Berakar dari repositori `E:\Project-bot` (commit terakhir: `12748a6`).
   - **Remote:** `origin` menunjuk `https://github.com/alwaysdims/Aether-Market-Intelligence.git`.
   - **Ruang Lingkup:** Mengelola kode `dashboard/` (Laravel), `services/` (Python Adapter), `scripts/` (runner batch), `docs/`, `reports/`, dan konfigurasi operasional root.
   - **Proteksi Isolasi:** Root `.gitignore` secara eksplisit mengabaikan folder `quant-engine/`, `data/`, `logs/`, `storage/`, dan `dashboard/.env`.

2. **Quant Engine Sub-Repository (`E:\Aether-Market-Intelligence\quant-engine`):**
   - **Histori Asal:** Berakar dari repositori `E:\hermes-stock-agent` (commit terakhir: `2c974b2`).
   - **Remote:** `origin` menunjuk `https://github.com/alwaysdims/bot-telegram.git`.
   - **Ruang Lingkup:** Mengelola modul penelitian kuantitatif (`src/`), engine paper trading (`paper_trading_engine.py`), runner simulasi bursa, dan 64 unit test pytest (`tests/`).
   - **Folder Git Terpisah:** Memiliki folder `.git` internal sendiri yang tidak terlacak oleh repositori root.

---

## 2. Aturan & Kebijakan Kerja Git

1. **Zero Auto-Commit & Zero Auto-Push:**
   - Tidak ada proses otomatis (script, cron, runner, atau agen AI) yang diizinkan melakukan `git commit` atau `git push`.
   - Seluruh commit dan push harus diinspeksi dan disetujui secara manual oleh pemilik repositori.
2. **Perlindungan Rahasia (Secret Protection):**
   - Dilarang keras melakukan commit terhadap file `.env`, file kredensial database, atau token bot Telegram.
   - Periksa status staged files dengan `git status` dan `git diff --staged` sebelum membuat commit.
3. **Pemisahan Commit Berdasarkan Domain:**
   - Perubahan pada dashboard web, laporan operasional, atau adapter dikomit di repositori root (`E:\Aether-Market-Intelligence`).
   - Perubahan pada strategi trading, algoritma teknikal, atau engine backtest dikomit di dalam direktori quant engine (`E:\Aether-Market-Intelligence\quant-engine`).

---

## 3. Alur Kerja Pengelolaan Manual

### A. Untuk Perubahan pada Dashboard / Adapter / Dokumen:
```bash
cd E:\Aether-Market-Intelligence
git status
git diff
# Jika perubahan telah diverifikasi:
git add <file_yang_diubah>
git commit -m "Deskripsi perubahan terverifikasi"
# Push hanya jika diperintahkan secara eksplisit:
# git push origin main
```

### B. Untuk Perubahan pada Quant Engine / Algoritma Trading:
```bash
cd E:\Aether-Market-Intelligence\quant-engine
git status
git diff
# Jika perubahan telah diverifikasi:
git add <file_yang_diubah>
git commit -m "Deskripsi pembaruan model kuantitatif"
# Push hanya jika diperintahkan secara eksplisit:
# git push origin main
```

---

## 4. Cadangan Riwayat Sebelum Konsolidasi

Jika diperlukan peninjauan terhadap status kedua repositori tepat sebelum konsolidasi dilakukan, seluruh diff dan manifest telah diarsipkan di:
- `E:\backups_pre_consolidation_20261021\project_bot_tracked.patch`
- `E:\backups_pre_consolidation_20261021\project_bot_status.txt`
- `E:\backups_pre_consolidation_20261021\hermes_stock_agent_tracked.patch`
- `E:\backups_pre_consolidation_20261021\hermes_stock_agent_status.txt`
