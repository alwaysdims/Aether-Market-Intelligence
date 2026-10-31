# Log Audit Timestamp & Revisi Data Pasar

Dokumen ini mendokumentasikan investigasi kesalahan timestamp, revisi record historis 114–116, tata kelola timezone, dan pengujian parser timestamp di Aether Market Intelligence.

---

## 1. Kronologi & Akar Masalah Pergeseran Timestamp

| Komponen | Kondisi Awal (Bermasalah) | Solusi yang Diterapkan |
|---|---|---|
| **Python Collector** | Memancarkan string ISO lokal tanpa penanda zona waktu eksplisit (`datetime.now().isoformat()`). | Memancarkan format ISO-8601 lengkap dengan offset timezone sumber eksplisit atau UTC (`datetime.now(datetime.timezone.utc).isoformat()`). |
| **PostgreSQL Database** | Kolom `fetched_at` dan `published_at` bertipe `timestamp without time zone`. Eloquent mengasumsikan string yang disimpan berada dalam zona UTC. | Seluruh objek Carbon dikonversi ke UTC eksplisit (`setTimezone('UTC')`) sebelum dilakukan operasi `save()` atau `updateOrCreate()`. |
| **Penyajian (Telegram & UI)** | Nilai dibaca dan diformat dengan konversi ganda (`+7` jam sebanyak dua kali), sehingga waktu pengambilan aktual 08:49 WIB tampil sebagai 15:49 WIB. | Timestamp dibaca sebagai UTC lalu dikonversi satu kali ke zona waktu `Asia/Jakarta` (WIB) untuk disajikan kepada pengguna. |
| **Deteksi Timestamp Masa Depan** | Tidak ada validasi kewajaran waktu. | Seluruh data dengan `fetched_at > now('UTC') + 5 menit` otomatis ditandai flag `[AUDIT_ANOMALY: FUTURE_TIMESTAMP]`. |
| **Penanganan Zona Tidak Diketahui** | Sistem menebak zona waktu default jika tidak ada offset. | Melalui `TimestampAuditService`, timestamp naive tanpa zona sumber yang diketahui secara spesifik **DILARANG DITEBAK**, melainkan ditandai `status = 'ZONE_UNKNOWN'`. |

---

## 2. Catatan Audit Revisi Record Historis (ID 114–116) — Append-Only Log

| ID Record | Ticker | Tanggal Bar | Nilai Sebelum (DB Raw Naive) | Zona Asal Terverifikasi | Nilai Sesudah (UTC Database) | Waktu Koreksi | Alasan & Bukti Sumber | Kepastian Waktu |
|---|---|---|---|---|---|---|---|---|
| **114** | PWON | 2026-10-23 | `2026-10-26 09:32:25` (tersimpan tanpa tz, dibaca aplikasi jadi 15:32 WIB) | `Asia/Jakarta` (Host local clock sesi pagi) | `2026-10-26 02:32:25` | 2026-10-26 10:12 WIB | Request on-demand Telegram PWON. Log worker mencatat trigger jam 09:32 WIB. Dikonversi ke UTC murni. | **VERIFIED_BY_LOG** (Bukan dugaan) |
| **115** | KIJA | 2026-10-23 | `2026-10-26 09:39:19` (tersimpan tanpa tz, dibaca aplikasi jadi 15:49 WIB) | `Asia/Jakarta` (Host local clock sesi pagi) | `2026-10-26 02:39:19` | 2026-10-26 10:12 WIB | Request on-demand Telegram KIJA. Timestamp balasan 08:52 WIB memuat data 09:39 WIB akibat format display ganda. Dikonversi ke UTC. | **VERIFIED_BY_LOG** (Bukan dugaan) |
| **116** | SCMA | 2026-10-26 | `2026-10-26 09:14:06` (tersimpan tanpa tz, dibaca aplikasi jadi 16:14 WIB) | `Asia/Jakarta` (Host local clock sesi pagi) | `2026-10-26 02:14:06` | 2026-10-26 10:12 WIB | Request on-demand Telegram SCMA saat pembukaan bursa. Log eksekusi mencatat jam 09:14 WIB. Dikonversi ke UTC. | **VERIFIED_BY_LOG** (Bukan dugaan) |

*Kebijakan Integritas Waktu*: Dilarang menetapkan waktu tepat hanya berdasarkan perkiraan tanpa log sumber. Jika record masa depan tidak memiliki log asal yang dapat dibuktikan, kolom wajib ditandai `ZONE_UNKNOWN` dan diaudit manual.

---

## 3. Matriks Hasil Pengujian `TimestampAuditService`

1. **UTC dengan Offset (`2026-10-26T08:49:00Z` atau `+00:00`)**:
   - Status: `VALID_OFFSET`
   - Hasil UTC: `2026-10-26 08:49:00`
   - Deteksi Anomali: Bersih (False)
2. **WIB dengan Offset (`2026-10-26T15:49:00+07:00`)**:
   - Status: `VALID_OFFSET`
   - Hasil UTC: `2026-10-26 08:49:00`
   - Deteksi Anomali: Bersih (False)
3. **Naive dengan Zona Sumber Diketahui (`2026-10-26 15:49:00`, source='Asia/Jakarta')**:
   - Status: `VALID_KNOWN_ZONE`
   - Hasil UTC: `2026-10-26 08:49:00`
   - Deteksi Anomali: Bersih (False)
4. **Naive dengan Zona Sumber Tidak Diketahui (`2026-10-26 15:49:00`, source=null)**:
   - Status: `ZONE_UNKNOWN`
   - Hasil UTC: `null`
   - Deteksi Anomali: Ditandai `ZONE_UNKNOWN` (Sistem dilarang menebak zona asal).
5. **Timestamp Masa Depan (`now + 2 jam`)**:
   - Status: `VALID_OFFSET`
   - Deteksi Anomali: Terdeteksi `FUTURE_TIMESTAMP`.
