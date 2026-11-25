<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Universal rule-based screening seluruh registry, satu trading plan pagi melalui outbox existing.
Schedule::command('aether:screen-universal --live')
    ->weekdays()
    ->at('08:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/aether_screen_universal.log'));

// Jadwal Pembaruan Pasar Sesi 1 (Senin - Jumat 09:15 WIB)
Schedule::command('hermes:market-update 09:15')
    ->weekdays()
    ->at('09:15')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/hermes_market_update_0915.log'));

// Jadwal Ulasan Jeda Sesi Pasar (Senin - Jumat 12:15 WIB)
Schedule::command('hermes:market-update 12:15')
    ->weekdays()
    ->at('12:15')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/hermes_market_update_1215.log'));

// Jadwal Radar Penutupan Bursa (Senin - Jumat 15:15 WIB)
Schedule::command('hermes:market-update 15:15')
    ->weekdays()
    ->at('15:15')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/hermes_market_update_1515.log'));

// Catatan Arsitektur: Pekerjaan EOD Komprehensif 16:05 WIB (forward_data_collector,
// sync-eod, evaluate-horizon, monitor-market, sync-idx-edge-history, dan broker summary)
// DIMILIKI SECARA EKSKLUSIF oleh Windows Task Scheduler \HermesMarketIntelligence_EOD
// melalui scripts/run_eod_pipeline.bat. Tidak didaftarkan di sini untuk mencegah eksekusi ganda.

// Monitoring owned exclusively by Windows Task Scheduler AetherMarketMonitoring_15min.
// Keep command out of Laravel scheduler to prevent duplicate execution.

// Worker dashboard research memakai owner dispatcher yang sama; tidak membuat proses kedua.
Schedule::command('aether:process-research-queue --limit=2')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/aether_research_worker.log'));

// Jadwal Dispatcher Antrean Outbox Telegram (Setiap 5 Menit 24/7)
Schedule::command('hermes:process-outbox --live')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/hermes_outbox.log'));

// Worker Fast-Track Antrean Konsultasi Asinkron AI (Setiap Menit 24/7)
Schedule::command('hermes:process-consultation-queue --live --limit=5')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/hermes_consultation_worker.log'));

// Evening report reads candidate snapshots and owns only report/outbox publication.
Schedule::command('hermes:evening-review')
    ->weekdays()
    ->at('20:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/hermes_evening_review.log'));

// ML multi-ticker screening remains research-only until validation meets promotion gates.
Schedule::command('aether:screen-ml-universe --dry-run')
    ->weekdays()
    ->at('16:10')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/aether_ml_universe.log'));

// Legacy BBCA pilot remains separate and is not used for other tickers.
Schedule::command('aether:screen-ml-bbca --live')
    ->weekdays()
    ->at('16:15')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/aether_ml_bbca.log'));

// Broker/fundamental provider writes stay under guarded writer ownership.
// EOD/broker/fundamental ingestion has no Laravel owner. Windows guarded EOD owns it.

// EOD refresh, retry, and universe backfill are owned exclusively by
// Windows Task Scheduler \HermesMarketIntelligence_EOD through the guarded
// scripts/run_guarded_eod.bat -> launch_ingestion_writer.php chain.
// Do not register plain Artisan ingestion here: it can inherit legacy .env state.
