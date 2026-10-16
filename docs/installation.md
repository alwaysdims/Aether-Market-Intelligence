# PANDUAN INSTALASI & PENGOPERASIAN LOKAL

## 1. Kebutuhan Sistem Terverifikasi
- OS: Windows 11
- PHP: 8.3+ (ekstensi `pdo_pgsql`, `pgsql`, `curl`, `mbstring`)
- Composer: 2.8+
- Node.js: 20+ & npm
- PostgreSQL: 16.x (Database: `project-bot`, User: `postgres`)
- Python: 3.14.x (Environment aktif dengan `pandas`, `yfinance`, `scikit-learn`)
- AI Model: `ag/gemini-3.8-flash-high` pada router lokal `http://127.0.0.1:20128/v1`

## 2. Struktur Direktori Proyek
```
E:\
├── Project-bot\
│   ├── docs\            # Dokumentasi arsitektur, PRD, dan kontrak API
│   ├── laravel-app\     # Control Plane Laravel 13 web dashboard & database
│   └── services\        # Python adapter bridge ke Hermes Stock Agent
└── hermes-stock-agent\  # Engine analitik kuantitatif pasar BEI mandiri
```

## 3. Menjalankan Dashboard Lokal
Buka terminal dan jalankan:
```bash
cd E:/Project-bot/laravel-app
php artisan serve
```
Akses melalui browser di: `http://localhost:8000`

## 4. Menjalankan Unit/Feature Test
```bash
cd E:/Project-bot/laravel-app
php artisan test
```

## 5. Health Check API
```bash
curl http://localhost:8000/api/health
```
Mengembalikan status real-time kesehatan database, AI router, dan folder Hermes Agent.
