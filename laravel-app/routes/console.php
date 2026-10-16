<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Jadwal Sinkronisasi EOD & Evaluasi Post-Horizon (Senin - Jumat 16:05 WIB)
Schedule::command('hermes:sync-eod --trigger=scheduled')
    ->weekdays()
    ->at('16:05')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/hermes_scheduler.log'));

// Jadwal Evaluasi Post-Horizon Harian (Senin - Jumat 16:15 WIB)
Schedule::command('hermes:evaluate-horizon')
    ->weekdays()
    ->at('16:15')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/hermes_evaluator.log'));
