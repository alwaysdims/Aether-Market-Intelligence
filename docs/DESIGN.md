# Design System dan Spesifikasi UX — Aether v3

Status: spesifikasi target, bukan bukti perubahan UI. Acuan diskusi 30–31 Oktober 2026. UI lama berbasis DESIGN.MD; kecocokan aset Stitch belum diverifikasi. Tidak mengklaim screenshot Stitch sudah diunduh.

## 1. Arah visual

Dashboard analisis institusional, padat tetapi terbaca. Hierarki: pertanyaan/keputusan -> bukti utama -> risiko -> detail audit. Tidak menempatkan log teknis sebagai konten utama. Pertahankan Plus Jakarta Sans dan tiga warna brand.

| Token | Nilai | Penggunaan |
|---|---|---|
| color.background / navy | #05152B | Body, sidebar |
| color.surface.1 | #0A213D | Card, tabel, panel |
| color.surface.2 | #0D2B4E | Input, dropdown, panel bertingkat |
| color.accent / gold | #E5C49B | Aksi utama, navigasi aktif, focus |
| color.text / ivory | #F5F1E8 | Heading dan isi |
| color.border | Ivory transparan 12–18% | Pemisah; dekoratif, bukan satu-satunya indikator state |

Surface merupakan varian navy, bukan warna brand tambahan. Hindari aksen cyan/amber/merah baru. Naik/turun memakai +/−, panah dan teks; risiko memakai ikon serta judul. Uji kontras teks minimal WCAG AA untuk ukuran terkait dan focus terlihat; jangan mengandalkan opacity rendah pada teks penting.

Tipografi: body 14–16px, label 12–13px, judul halaman 24–28px; gunakan rem. Angka tabular, nilai kanan, label kiri. Nominal Indonesia, unit eksplisit: Rp, poin, lembar, miliar/triliun. Saat menyingkat nominal, nilai lengkap tersedia di detail yang dapat diakses keyboard, bukan hover saja.
Spacing skala 4/8/12/16/24/32px. Card radius 12px; input 8px. Satu CTA utama per seksi. Ikon konsisten, tanpa emoji dekoratif berlebihan. Motion 120–180ms dan hormati reduced motion.

## 2. Shell

Sidebar desktop 260px sticky setinggi viewport, brand/header dan footer shrink-0, navigasi scroll sendiri. Empat menu: Dashboard, Market Monitor, News & Event, AI History. Footer ringkas berisi status terakhir terukur/link health; jangan memberi Engine Active tanpa probe.
Topbar: breadcrumb, search ticker/nama, status pasar singkat, dropdown profil. Dropdown: Profil Saya, System Health, Pengaturan. Escape menutup, focus kembali ke pemicu. Mobile <768px drawer; konten satu kolom; overlay/focus trap dan tombol tutup accessible. Konten desktop max-width sekitar 1600px tanpa membatasi tabel secara berlebihan.

## 3. Dashboard (/)

Urutan:
1. Header status pasar, sesi acuan data, freshness dan cakupan. Market closed berbeda dari source unavailable.
2. Kartu ringkasan IHSG (poin), breadth eligible dan excluded, aktivitas pasar terukur. Missing benchmark -> empty state; tidak mengarang nilai.
3. Timeline laporan 08:30/09:15/12:15/15:15/20:00: scheduled, processing, completed, partial, blocked, missed, failed. 16:05 sebagai aktivitas ingestion, bukan rekomendasi tambahan.
4. Tabel kandidat terbaru: ticker, asal strategi, publikasi, harga acuan, horizon, status evaluasi. Filter strategi/tanggal/watchlist.
5. Tindak lanjut: jatuh tempo berikutnya, hasil horizon, target/invalidation terpisah, dan link rekap 30 hari.
6. Berita penting global/sektor dan emiten.
Jumlah row database, PID, log worker, latensi router ada di System Health. Seluruh angka cakupan dinamis, bukan copy snapshot laporan.

## 4. Market Monitor (/market)

Default direktori kandidat ekuitas registry, bukan hanya ticker yang sudah punya harga. Indeks tab terpisah dengan alias dinormalisasi. Toolbar search debounce ~300ms, filter sektor/subsektor/industri, watchlist, sumber/cakupan, freshness, tanggal sesi, reset. Query tersimpan di URL; server pagination default20, opsi20/50; sorting whitelist, null selalu terlihat sebagai N/A.
Kolom: ticker+nama, sektor/industri, close+tanggal sesi, daily change vs previous session, volume lembar, coverage/status, watchlist, link detail. Broker net hanya jika periode dan definisinya jelas; bukan kolom angka tanpa konteks. Klik ticker membuka detail; tombol watchlist tidak ikut memicu row navigation. Search/filter tidak memanggil provider/LLM.

## 5. Detail emiten (/market/{ticker})

Persistent header: ticker, nama, sektor/industri, watchlist, CTA Minta Analisis. Harga sumber valid, perubahan harian dan tanggal, intraday vs open hanya label terpisah. Tampilkan sumber/timeframe/board, market state, freshness/quality. Emiten valid tanpa histori tetap memiliki halaman dan dapat meminta riset; ticker tak dikenal -> penjelasan dan CTA disabled.
Tujuh tab memiliki URL ?tab=..., loading/error lokal, aria tab semantics; Back/Forward bekerja.

| Tab | Susunan |
|---|---|
| Ringkasan | Profil usaha dan sumber/tanggal profil; chart candlestick+volume; timeframe hanya yang tersedia; statistik harga; coverage |
| Pemantauan | Watchlist, rekomendasi dan outcome, broker net buy/sell periode dipilih, gross buy/sell avg terpisah; tampilan Top-N jelas |
| Teknikal | Trend/indikator ringkas, SMA/RSI/MACD/ATR, interpretasi dan keterbatasan; parameter,input bars,as-of; tabel histori paginated |
| Fundamental | Periode annual/interim, currency/unit, induk/NCI, income/balance/cash flow; rasio formula dan audit status; bank DER N/A |
| Kesimpulan AI | Jawaban eksekutif; panel teknikal/fundamental/broker/news; faktor pendukung dan risiko; ML opsional terpisah; sumber/unknowns; accordion audit |
| News & Event | Artikel ticker, aksi korporasi/dokumen, URL/publikasi/fetched; event date berbeda dari publication |
| Riwayat AI | Tabel analisis terjadwal/on-demand/Telegram; tanggal evidence, periode, status, versi, lihat perbandingan; payload raw di accordion |

Broker panel: pilih requested dates dan bandingkan sesi; actual period terlihat, Top-N limit dan yang belum diperiksa. Tidak muncul di Top-N -> NOT_OBSERVED_IN_TOP_N. Net buy periode+net buy satu sesi -> NET_BUY_ON_LAST_SESSION; bukan bukti continuous accumulation. Rasio net/volume bukan cost basis.

## 6. Chart

Fase saat ini EOD IDX Edge. Label: Histori EOD • sesi terakhir ... • sumber IDX Edge. Pasar tutup tampil banner netral, chart tetap. Tidak menyebut realtime karena polling internal. Intraday selector disabled dengan alasan sampai kontrak RG valid. Empty -> DATA_NOT_AVAILABLE, bukan chart dummy. Pisahkan total_history_bars, chart_display_bars, indicator_input_bars. Raw/split-adjusted/dividend-adjusted terlihat dan tidak dicampur.

## 7. Kesimpulan AI

Header: analysis time, data cutoff/bar, PARTIAL/COMPLETE, model alias dan orchestrator Hermes. Jawaban langsung sesuai pertanyaan, bukan metadata dump. Tiap klaim angka terhubung evidence. Jika belum ada agent output: Ringkasan data — bukan analisis Gemini. Tombol Minta Analisis memicu job eksplisit; tunjukkan job ID/status, bukan menunggu request web panjang. ML tidak divalidasi/expired -> N/A; jangan tampilkan sinyal historis di kartu aktif. Riwayat lama tetap bisa dibuka dengan label historis.

## 8. Global News & AI History

/news: filter global/ticker/sektor, kategori dokumen resmi/media/fakta belum terverifikasi; badge official hanya provenance yang mendukung. Ringkasan dampak adalah interpretasi, bukan penyebab harga terbukti.
/history: filter ticker,channel,job status,analysis date,strategy,origin. Detail memisahkan user prompt, jawaban, evidence, delivery receipts dan outcome. Jangan menampilkan raw JSON/CoT sebagai isi utama. Rekap ML tidak dicampur hasil rule/agent.

## 9. Status reusable

| Dimensi | Contoh |
|---|---|
| Pasar | Tutup / sesi berlangsung / kalender tidak tersedia |
| Sumber | IDX Edge / dokumen IR; canonical vs archive |
| Freshness | Sesuai sesi terakhir / tertinggal / gap |
| Kualitas | Valid / parsial / quarantined / invalid |
| Analisis | Menunggu / diproses / selesai / parsial / gagal |
| Delivery | Antre / sebagian terkirim / terkirim / gagal / belum terkonfirmasi |
| ML | Belum divalidasi / tanpa inferensi aktif / inferensi sah abstain |

Jangan menggabungkan fresh,final,connected dalam badge ACTIVE. Semua state membutuhkan teks. Empty,error,loading tidak sama; error mempertahankan data terakhir bertimestamp dan tombol retry eksplisit. Tidak ada stale data yang disamarkan sebagai baru.

## 10. Profil, settings, health

Profil: analyst name, preferensi tampilan/alokasi nontransaksional. Branding: PNG/JPG maksimal2MB, validasi isi gambar dan re-encode jika didukung, filename server-generated, SVG/executable ditolak; otorisasi mutasi dan cache busting. Setting: sumber izin efektif, blocked reason, periode kuota diketahui/UNKNOWN, budget lokal terpisah dari limit provider; secret hanya set/unset.
Health: receiver liveness/poll terakhir, worker heartbeat, oldest pending, failed/unknown delivery, DB probe, connection vs completion provider, task last run, waktu pengecekan. Cached check menampilkan timestamp/usia, tidak sekadar reachable=true.

## 11. Performa dan penerimaan visual

Persistent shell, active-tab conditional queries, partial fetch, AbortController, history API. Cache tab maksimal5 menit sebagai default UI, key mencakup ticker/user/filter/tab/evidence version; invalidasi saat job selesai atau data_hash berubah. Status processing tidak dicache sebagai hasil selesai. Poll hanya DB, berhenti hidden, satu timer aktif. Gunakan asset Vite lokal, bukan runtime Tailwind CDN; tidak mewajibkan SSE/WebSocket sekarang.
QA: viewport390/768/1280/1440; keyboard semua menu/tab/dialog; kontras; long ticker/name; unknown, missing fundamentals, currency unknown, quote quarantine, provider timeout, processing dan partial delivery. Tidak ada overflow halaman; tabel scroll lokal. Simpan screenshot implementasi aktual, jangan klaim match Stitch tanpa aset pembanding.
