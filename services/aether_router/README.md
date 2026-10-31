# Aether Router Plugin for Hermes Gateway

Plugin resmi Hermes Gateway untuk mencegat (*intercept*) query Telegram terkait Aether Market Intelligence sebelum diteruskan ke agen LLM umum.

## Karakteristik & Kontrak Arsitektur
- **Kompatibilitas Hermes:** Hermes Agent Gateway v0.21.5+ (Platform Telegram via `python-telegram-bot`).
- **Nol Inferensi LLM:** Pesan pengguna dieksekusi secara deterministik murni melalui kueri basis data PostgreSQL `project-bot`.
- **Eksekusi Subprocess Aman:** Menggunakan executable PHP absolut tanpa shell interpolation, pembatasan panjang teks (500 karakter), dan pemutusan stdin (`stdin=DEVNULL`).
- **Pencegahan Respons Ganda:** Memanggil `ApplicationHandlerStop()` pada handler native PTB sehingga agen LLM inti tidak pernah memicu turn kedua.

## Struktur Berkas
- `plugin.yaml`: Manifest plugin Hermes Gateway.
- `__init__.py`: Handler native Telegram (`ctx.register_telegram_handler`).
- `install.py`: Skrip penyalinan berkas ke `%LOCALAPPDATA%\hermes\plugins\aether_router`.
- `uninstall.py`: Skrip pencabutan plugin (rollback).

## Prosedur Rollback
Jika diperlukan rollback:
```bash
python services/aether_router/uninstall.py
```
Lalu mulai ulang Hermes Gateway.
