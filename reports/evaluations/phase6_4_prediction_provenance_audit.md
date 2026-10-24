# LAPORAN FASE 6.4 — PREDICTION PROVENANCE & FALLBACK ROOT-CAUSE AUDIT
## AETHER MARKET INTELLIGENCE

**Dokumen:** `reports/evaluations/phase6_4_prediction_provenance_audit.md`  
**Waktu Audit:** Sabtu, 17 Oktober 2026, 01:35 WIB  
**Status Kesiapan Akhir:** **READY WITH WARNINGS**  

---

### RINGKASAN KLASIFIKASI BUKTI

- **VERIFIED (Terbukti secara empiris dan kode):**
  * **Akar Masalah Fallback:** Terbukti secara faktual melalui log `storage/logs/laravel.log` (baris 1081, 1082, 1085, 1096). Seluruh pemanggilan inferensi AI pada Run 1, 2, 3, dan 4 mengembalikan `HTTP 401 Unauthorized: {"error":{"message":"Missing API key","type":"authentication_error","code":"invalid_api_key"}}` dari endpoint router lokal `127.0.0.1:20128/v1/chat/completions`.
  * **Mekanisme Pemicu:** Dalam `AiMarketAnalysisService.php`, parameter `$this->apiKey = env('AI_API_KEY', '')` bernilai string kosong karena variabel `AI_API_KEY` tidak didefinisikan pada `.env`. Akibatnya, request HTTP dikirim tanpa header `Authorization: Bearer <key>`, sehingga proxy lokal 9router menolak request dengan status HTTP 401.
  * **Perilaku Graceful Fallback:** Sistem secara tepat menangkap kode non-200, mencatat peringatan di log, dan memanggil `buildFallbackAnalysis()` dengan status `fallback` dan arah `unclear`.
  * **Integritas Provenance 20 Record:** Tepat 20 baris pada `post_horizon_evaluations` di PostgreSQL berasal dari analisis fallback (Run 3 dan Run 4). Seluruh 20 baris berstatus `pending`, memiliki `is_fallback = true`, `validation_status = 'fallback'`, dan `predicted_direction = 'unclear'`. Tidak ada satu pun baris yang menyamarkan fallback sebagai hasil inferensi Gemini.
  * **Pemisahan Metrik AI:** Post-horizon evaluator secara ketat mengecualikan baris `is_fallback = true` dari metrik `ai_accuracy_pct`. Akurasi penalaran Gemini aktif dilaporkan 0% atas 0 sampel valid ($N_{\text{Gemini}} = 0$).
  * **Pengujian Komprehensif:** 9 pengujian baru pada `AiMarketAnalysisProvenanceTest` lulus 100% (total 53 pengujian Laravel dengan 169 assertions, 6 pengujian Adapter, 64 pengujian Quant Engine).
- **REPORTED:**
  * Model yang ditargetkan pada prompt template adalah `ag/gemini-3.8-flash-high` melalui 9router lokal.
- **NOT VERIFIED:**
  * Keberhasilan inferensi langsung ke Gemini menggunakan live API key pada environment produksi (karena audit dilarang menggunakan kuota live atau memicu pemanggilan eksternal berbayar tanpa otorisasi pengguna).
- **PENDING:**
  * Pemberian otorisasi/konfigurasi `AI_API_KEY` di `.env` lokal jika pengguna menghendaki inferensi LLM langsung.
  * Jatuh tempo evaluasi horizon pada 21 Oktober 2026.

---

### 1. COMMIT, BRANCH, DAN WORKING TREE

- **Repositori:** `E:\Project-bot`
- **Branch:** `main`
- **Commit Baseline:** `12748a6`
- **Status Working Tree:** Bersih dari perubahan destruktif; hanya penambahan pengujian isolasi provenance dan pencatatan parameter `fallback_reason` pada `AiMarketAnalysisService.php`.
- **Repositori Kuantitatif (`E:\hermes-stock-agent`):** Commit `2c974b2`, bersih dan terisolasi tanpa modifikasi.

---

### 2. DIAGRAM ALUR PREDIKSI AKTUAL

```text
[Market Data Ingestion]
  │  (Yahoo Finance EOD via hermes_adapter.py)
  ▼
[Payload & Context Builder]
  │  (PromptTemplate: MARKET_INTELLIGENCE_DAILY + OHLCV + News)
  ▼
[AiMarketAnalysisService::analyze()]
  │  POST http://127.0.0.1:20128/v1/chat/completions
  │  Header Authorization: Bearer {AI_API_KEY}
  │
  ├───[ HTTP 200 & Valid JSON Schema ] ──────────────► MarketAnalysis (validation_status: valid_schema)
  │                                                     └─► PostHorizonEvaluation (is_fallback: false)
  │
  └───[ HTTP 401 / 429 / Timeout / Invalid JSON ] ────► AiMarketAnalysisService::buildFallbackAnalysis()
                                                        ├─► MarketAnalysis (validation_status: fallback)
                                                        │   ├─ impact_direction: 'unclear'
                                                        │   └─ fallback_reason: "HTTP 401: Missing API key"
                                                        └─► PostHorizonEvaluation (is_fallback: true)
```

---

### 3. PENYEBAB FALLBACK YANG DIBUKTIKAN (ROOT CAUSE)

Berdasarkan pemeriksaan log sistem pada baris 1081–1119 di `storage/logs/laravel.log`:
```text
[2026-10-16 13:49:13] local.WARNING: AI Router error [401]: {"error":{"message":"Missing API key","type":"authentication_error","code":"invalid_api_key"}}
[2026-10-16 13:50:25] local.WARNING: AI Router error [401]: {"error":{"message":"Missing API key","type":"authentication_error","code":"invalid_api_key"}}
[2026-10-16 16:34:51] local.WARNING: AI Router error [401]: {"error":{"message":"Missing API key","type":"authentication_error","code":"invalid_api_key"}}
[2026-10-16 17:03:06] local.WARNING: AI Router error [401]: {"error":{"message":"Missing API key","type":"authentication_error","code":"invalid_api_key"}}
```

**Bukti Rantai Kausalitas:**
1. Variabel `AI_API_KEY` tidak diatur di dalam file lingkungan `.env`.
2. Konstruktor `AiMarketAnalysisService` membaca `$this->apiKey = env('AI_API_KEY', '')`.
3. Pemeriksaan `if (!empty($this->apiKey))` bernilai false sehingga pemanggilan HTTP client Laravel tidak menyertakan token otorisasi Bearer.
4. Proxy lokal 9router (`127.0.0.1:20128`) menolak request chat completions tanpa API key dan mengembalikan status HTTP 401.
5. Service menangkap kode status HTTP 401 dan secara sah mengarahkan eksekusi ke `buildFallbackAnalysis()`.
6. Seluruh 20 prediksi yang terdaftar dari Run 3 dan Run 4 berstatus fallback bukan karena model Gemini gagal bernalar, melainkan karena permintaan tidak lolos gerbang otorisasi HTTP lokal.

---

### 4. STATUS KONFIGURASI PROVIDER

| Komponen Konfigurasi | Status | Keterangan |
| :--- | :--- | :--- |
| **AI Router Endpoint** | Terverifikasi Aktif | `http://127.0.0.1:20128/v1` (Proses 9router lokal berjalan) |
| **Model Resmi** | Terverifikasi Terdaftar | `ag/gemini-3.8-flash-high` terdaftar pada endpoint `/v1/models` |
| **AI API Key** | Kosong / Tidak Diatur | `AI_API_KEY` belum disetel di `.env` (memicu HTTP 401) |
| **Timeout Client** | Terkonfigurasi Aman | Default 30 detik (`AI_ROUTER_TIMEOUT`) |
| **Redaksi Rahasia** | Terverifikasi 100% | Kredensial tidak dicatat dalam log, commit, atau dokumen audit |

---

### 5. HASIL AUDIT PROVENANCE 20 RECORD HISTORIS

- **Tabel Sumber:** `market_analyses` (Record ID 3 dan ID 4).
  * Keduanya memiliki `validation_status = 'fallback'`.
  * Model yang dicatat: `ag/gemini-3.8-flash-high`.
  * Arah prediksi: `unclear`.
- **Tabel Evaluasi:** `post_horizon_evaluations` (Record ID 1 s.d. 20).
  * Record 1 s.d. 10 (dari Analisis 3): `is_fallback = true`, `validation_status = 'fallback'`, `status = 'pending'`.
  * Record 11 s.d. 20 (dari Analisis 4): `is_fallback = true`, `validation_status = 'fallback'`, `status = 'pending'`.
  * Outcome: 20/20 bernilai `NULL` (tidak ada outcome yang diisi secara prematur).
  * Tanggal Target: 20/20 bernilai `2026-10-21`.
- **Kesimpulan Provenance:** Data historis 100% otentik dan tercatat secara jujur sebagai fallback. Tidak ada anomali atau data manipulasi.

---

### 6. PENGUJIAN ISOLASI & HASIL AKTUAL

Dibuat suite pengujian fitur terisolasi `tests/Feature/AiMarketAnalysisProvenanceTest.php` yang mencakup 9 skenario inti:
1. `test_valid_ai_response_creates_valid_schema_analysis` (Status: **PASS**)
2. `test_ai_router_timeout_triggers_fallback_with_reason` (Status: **PASS**)
3. `test_ai_router_http_error_triggers_fallback_with_status_code` (Status: **PASS**)
4. `test_empty_ai_response_triggers_fallback` (Status: **PASS**)
5. `test_invalid_json_format_triggers_fallback` (Status: **PASS**)
6. `test_empty_market_input_handled_gracefully` (Status: **PASS**)
7. `test_structured_fallback_records_correct_attributes` (Status: **PASS**)
8. `test_repeated_prediction_registration_is_strictly_idempotent` (Status: **PASS**)
9. `test_fallback_predictions_are_excluded_from_pure_ai_accuracy` (Status: **PASS**)

**Hasil Eksekusi Keseluruhan:**
- Laravel PHPUnit: **53 LULUS (169 assertions, 32,3 detik)**
- Python Adapter Service: **6 LULUS (5,7 detik)**
- Hermes Stock Agent Quant Baseline: **64 LULUS (47,8 detik)**

---

### 7. PERUBAHAN KODE MINIMAL (SURGICAL REPAIR)

- **File Diperbarui:** `app/Services/AiMarketAnalysisService.php`
  * Menambahkan parameter `$fallbackReason` pada method `buildFallbackAnalysis()`.
  * Menyimpan alasan spesifik kegagalan (misalnya `"AI Router mengembalikan respon HTTP 401."` atau `"Koneksi ke AI router terputus/timeout"`) ke dalam kolom `structured_output['fallback_reason']`.
  * Menjamin alasan fallback di masa depan tercatat secara eksplisit di database tanpa membocorkan kredensial.
- **File Pengujian Baru:** `tests/Feature/AiMarketAnalysisProvenanceTest.php`

---

### 8. RISIKO TERSISA & REKOMENDASI TINDAKAN

1. **Inferensi Live LLM Memerlukan API Key:**
   Jika pengguna ingin sistem melakukan inferensi Gemini secara aktif (bukan mode fallback), pengguna dapat menambahkan variabel `AI_API_KEY="kunci_anda"` pada file `.env` Laravel lokal.
   *Catatan Auditor:* Sistem dirancang tetap berjalan stabil dalam mode fallback kuantitatif jika kunci tidak disediakan.
2. **Evaluasi Terkunci Hingga 21 Oktober 2026:**
   Seluruh 20 record tetap berada dalam antrean pending hingga candle EOD 21 Oktober 2026 tersedia secara final.

---

### 9. KEPUTUSAN KESIAPAN AKHIR

**STATUS: READY WITH WARNINGS**

**Dasar Keputusan:**
- **READY:** Akar masalah fallback teridentifikasi secara pasti (HTTP 401 karena ketiadaan `AI_API_KEY`). Sistem penanganan fallback terbukti tangguh, aman, transparan, dan tidak pernah memalsukan akurasi AI. Test suite 100% lulus (123 total tes lintas sistem).
- **WARNINGS:**
  * Seluruh 20 prediksi historis berstatus fallback ($N_{\text{Gemini}} = 0$). Dilarang mengklaim keunggulan model AI sebelum ada prediksi valid yang terkumpul dan dievaluasi.
  * Evaluasi horizon pada 21 Oktober 2026 tetap menunggu kedatangan data pasar yang sebenarnya.
- **Git & Deployment:** Nol push ke remote; commit tidak dibuat tanpa perintah eksplisit pengguna.