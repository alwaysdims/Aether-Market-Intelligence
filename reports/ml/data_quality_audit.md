# LAPORAN AUDIT KUALITAS DATA & INTEGRITAS EOD
## AETHER MARKET INTELLIGENCE

**Tanggal Audit:** 24 Oktober 2026, 23:35 WIB  
**Direktori Data Bersih:** `data/cleaned/`  
**Direktori Karantina:** `data/quarantine/`  
**Direktori Snapshot Imutabel:** `data/raw_immutable/`  
**Status Kualitas Keseluruhan:** `PASS — VERIFIED HIGH INTEGRITY`

---

## 1. Ringkasan Eksekutif

Audit kualitas data dilakukan terhadap seluruh 10 saham universe likuid Bursa Efek Indonesia (BEI) dari periode **4 Januari 2021 hingga 23 Oktober 2026** (1.398 sesi perdagangan). Audit memvalidasi batas fisik OHLCV, integritas tanggal, deteksi volume nol, pemisahan bar intraday vs EOD final, dan konsistensi penyesuaian harga.

| Parameter Audit | Hasil Observasi | Evaluasi |
| :--- | :---: | :---: |
| **Total Bar Dianalisis** | 13.936 bar | Lengkap |
| **Total Bar Bersih Tervalidasi** | 13.936 bar (100.0%) | **PASS** |
| **Pelanggaran Batas OHLC** (`High < Low`, `Price <= 0`) | 0 bar (0.00%) | **PASS** |
| **Bar Bervolume Nol** | 0 bar (0.00%) | **PASS** |
| **Bar Duplikat Tanggal** | 0 bar | **PASS** |
| **Konflik Harga Terkarantina** (Deviasi > 0.5%) | 0 bar | **PASS** |
| **Status Freshness Universe** | 10 dari 10 Ticker (100%) | **FRESH_CURRENT (23 Okt 2026)** |
| **Integritas Waktu Monotonik** | Seluruh 10 Ticker Monotonik Meningkat | **PASS** |

---

## 2. Rincian Metrik Kualitas per Ticker

```text
Ticker | Total Bar | Tanggal Mulai | Tanggal Akhir | Nulls | Vol Nol | OHLC Valid | Status Freshness
------ | :-------: | :-----------: | :-----------: | :---: | :-----: | :--------: | :---------------
BBCA   | 1.392     | 2021-01-04    | 2026-10-23    | 0     | 0       | 100%       | FRESH_CURRENT
BBRI   | 1.384     | 2021-01-04    | 2026-10-23    | 0     | 0       | 100%       | FRESH_CURRENT
BMRI   | 1.396     | 2021-01-04    | 2026-10-23    | 0     | 0       | 100%       | FRESH_CURRENT
BBNI   | 1.395     | 2021-01-04    | 2026-10-23    | 0     | 0       | 100%       | FRESH_CURRENT
ASII   | 1.386     | 2021-01-04    | 2026-10-23    | 0     | 0       | 100%       | FRESH_CURRENT
TLKM   | 1.396     | 2021-01-04    | 2026-10-23    | 0     | 0       | 100%       | FRESH_CURRENT
MAPI   | 1.398     | 2021-01-04    | 2026-10-23    | 0     | 0       | 100%       | FRESH_CURRENT
ARTO   | 1.398     | 2021-01-04    | 2026-10-23    | 0     | 0       | 100%       | FRESH_CURRENT
MEDC   | 1.393     | 2021-01-04    | 2026-10-23    | 0     | 0       | 100%       | FRESH_CURRENT
SMRA   | 1.398     | 2021-01-04    | 2026-10-23    | 0     | 0       | 100%       | FRESH_CURRENT
```

*Catatan perbedaan bar minor:* Perbedaan kecil pada jumlah bar (1.384 s.d. 1.398) disebabkan oleh suspensi bursa temporer historis atau hari libur aksi korporasi spesifik emiten, bukan karena kehilangan data (data loss) sistematis.

---

## 3. Provenance & Aturan Karantina Konflik

1. **Aturan Deteksi Konflik:**
   Setiap bar penutupan baru dibandingkan dengan bar penutupan sebelumnya pada tanggal yang sama. Apabila deviasi harga penutupan melebihi ambang batas toleransi **0.5%** tanpa adanya pencatatan stock split, data baru otomatis dialihkan ke karantina:
   `data/quarantine/conflicts_{SYMBOL}_{TIMESTAMP}.json`.
2. **Nol Smoothing / Nol Sintetis:**
   Data yang berbeda tidak pernah dirata-ratakan (smoothing) secara sembunyi-sembunyi.
3. **Penyimpanan Imutabel:**
   Salinan data bersih dibekukan pada `data/raw_immutable/` dan diverifikasi dengan checksum berkala untuk menjamin reproduktibilitas penelitian masa depan.
