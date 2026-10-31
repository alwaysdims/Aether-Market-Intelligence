<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Jadwal Briefing Pagi Pasar BEI (Senin - Jumat 08:30 WIB)
Schedule::command('hermes:morning-briefing')
    ->weekdays()
    ->at('08:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/hermes_morning_briefing.log'));

// Catatan Arsitektur: Pekerjaan EOD Komprehensif 16:05 WIB (forward_data_collector,
// sync-eod, evaluate-horizon, monitor-market, sync-idx-edge-history, dan broker summary)
// DIMILIKI SECARA EKSKLUSIF oleh Windows Task Scheduler \HermesMarketIntelligence_EOD
// melalui scripts/run_eod_pipeline.bat. Tidak didaftarkan di sini untuk mencegah eksekusi ganda.

// Jadwal Pemantauan Pasar & Rekonsiliasi Expiry Tengah Malam (Setiap Hari 00:05 WIB)
Schedule::command('hermes:monitor-market')
    ->dailyAt('00:05')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/hermes_monitoring.log'));

// Jadwal Dispatcher Antrean Outbox Telegram (Setiap 5 Menit 24/7)
Schedule::command('hermes:process-outbox --live')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/hermes_outbox.log'));

// Jadwal Pemrosesan Antrean Riset On-Demand (Setiap Menit 24/7)
Schedule::command('aether:process-research-queue --limit=5')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/aether_research_queue.log'));

// Worker Fast-Track Antrean Konsultasi Asinkron AI (Setiap Menit 24/7)
Schedule::command('hermes:process-consultation-queue --live --limit=5')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/hermes_consultation_worker.log'));

// Jadwal Sinkronisasi Broker Summary Terkontrol (Senin - Jumat 20:00 WIB)
Schedule::command('hermes:sync-idx-edge-broker BBCA')
    ->weekdays()
    ->at('20:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/hermes_broker_summary.log'));

// Jadwal Rekap Pasar & Broker Summary Sore (Senin - Jumat 20:00 WIB)
Schedule::command('hermes:evening-review')
    ->weekdays()
    ->at('20:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/hermes_evening_review.log'));

// Jadwal Pembaruan Fundamental Terkontrol (Setiap Sabtu 08:00 WIB)
Schedule::command('hermes:sync-idx-edge-fundamental BBCA --limit=2')
    ->weeklyOn(6, '08:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(15)
    ->appendOutputTo(storage_path('logs/hermes_fundamental.log'));
