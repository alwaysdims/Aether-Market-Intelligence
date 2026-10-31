<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class BeiCalendarService
{
    /**
     * Official Indonesian public holidays / BEI non-trading days for 2026.
     * Matches E:\hermes-stock-agent\src\calendar_utils.py.
     */
    public const BEI_HOLIDAYS_2026 = [
        '2026-01-01',  // Tahun Baru Masehi
        '2026-01-16',  // Isra Mi'raj
        '2026-02-17',  // Tahun Baru Imlek
        '2026-03-20',  // Hari Suci Nyepi
        '2026-03-21',  // Hari Raya Idul Fitri
        '2026-03-22',  // Cuti Bersama Idul Fitri
        '2026-03-23',  // Cuti Bersama Idul Fitri
        '2026-04-03',  // Wafat Yesus Kristus
        '2026-05-01',  // Hari Buruh
        '2026-05-14',  // Kenaikan Yesus Kristus
        '2026-05-28',  // Hari Raya Idul Adha
        '2026-06-01',  // Hari Lahir Pancasila
        '2026-06-16',  // Tahun Baru Islam
        '2026-08-17',  // Hari Kemerdekaan RI
        '2026-08-26',  // Maulid Nabi Muhammad SAW
        '2026-12-25',  // Hari Raya Natal
    ];

    /**
     * Check if a given date is an official BEI trading day (Monday - Friday, non-holiday).
     */
    public function isTradingDay(CarbonInterface|string $date): bool
    {
        $dt = $date instanceof CarbonInterface ? $date->copy() : Carbon::parse($date);

        // Weekend check: Saturday = 6, Sunday = 0 in Carbon dayOfWeek
        if ($dt->isWeekend()) {
            return false;
        }

        $dateStr = $dt->format('Y-m-d');

        return ! in_array($dateStr, self::BEI_HOLIDAYS_2026, true);
    }

    /**
     * Check if market closing candle is final (on trading day and time >= 16:00 WIB).
     */
    public function isEodFinal(?CarbonInterface $now = null): bool
    {
        $dt = ($now ?? Carbon::now('Asia/Jakarta'))->copy()->timezone('Asia/Jakarta');

        if (! $this->isTradingDay($dt)) {
            return false;
        }

        // Market closes officially at 16:00 WIB
        return $dt->hour >= 16;
    }

    /**
     * Get the next official BEI trading day.
     */
    public function getNextTradingDay(CarbonInterface|string|null $date = null): Carbon
    {
        $dt = $date ? ($date instanceof CarbonInterface ? $date->copy() : Carbon::parse($date)) : Carbon::now('Asia/Jakarta');
        $curr = $dt->copy()->addDay()->startOfDay();

        while (! $this->isTradingDay($curr)) {
            $curr->addDay();
        }

        return $curr;
    }

    /**
     * Get the most recent official BEI trading day (today if trading day, else rolled back).
     */
    public function getCurrentTradingDay(CarbonInterface|string|null $date = null): Carbon
    {
        $dt = $date ? ($date instanceof CarbonInterface ? $date->copy() : Carbon::parse($date)) : Carbon::now('Asia/Jakarta');
        $curr = $dt->copy()->startOfDay();

        while (! $this->isTradingDay($curr)) {
            $curr->subDay();
        }

        return $curr;
    }

    /**
     * Get the immediate previous official BEI trading day strictly before the given date.
     */
    public function getPreviousTradingDay(CarbonInterface|string|null $date = null): Carbon
    {
        $dt = $date ? ($date instanceof CarbonInterface ? $date->copy() : Carbon::parse($date)) : Carbon::now('Asia/Jakarta');
        $curr = $dt->copy()->subDay()->startOfDay();

        while (! $this->isTradingDay($curr)) {
            $curr->subDay();
        }

        return $curr;
    }

    /**
     * Add N trading days to a starting date, skipping weekends and BEI holidays.
     */
    public function addTradingDays(CarbonInterface|string $date, int $days): Carbon
    {
        $dt = $date instanceof CarbonInterface ? $date->copy() : Carbon::parse($date);
        $curr = $dt->copy()->startOfDay();
        $added = 0;

        while ($added < $days) {
            $curr->addDay();
            if ($this->isTradingDay($curr)) {
                $added++;
            }
        }

        return $curr;
    }

    /**
     * Determine the next scheduled EOD job execution timestamp (16:05 WIB on trading day).
     */
    public function getNextScheduledRunTime(?CarbonInterface $now = null): Carbon
    {
        $dt = ($now ?? Carbon::now('Asia/Jakarta'))->copy()->timezone('Asia/Jakarta');

        // Check if today is a trading day and time is before 16:05 WIB
        if ($this->isTradingDay($dt)) {
            $todayRun = $dt->copy()->setTime(16, 5, 0);
            if ($dt->lessThan($todayRun)) {
                return $todayRun;
            }
        }

        // Otherwise find next trading day at 16:05 WIB
        $nextDay = $this->getNextTradingDay($dt);

        return $nextDay->setTime(16, 5, 0)->timezone('Asia/Jakarta');
    }

    /**
     * Get detailed market session status (Session 1, Midday Break, Session 2, Closed).
     */
    public function getMarketSessionStatus(?CarbonInterface $now = null): array
    {
        $dt = ($now ?? Carbon::now('Asia/Jakarta'))->copy()->timezone('Asia/Jakarta');

        if (! $this->isTradingDay($dt)) {
            return [
                'is_open' => false,
                'session' => 'CLOSED',
                'description' => 'Pasar Bursa Tutup (Akhir Pekan atau Hari Libur Resmi BEI)',
                'timestamp_wib' => $dt->format('Y-m-d H:i:s'),
            ];
        }

        $isFriday = ($dt->dayOfWeek === Carbon::FRIDAY);
        $timeStr = $dt->format('H:i');

        // Pre-opening: 08:45 - 08:59 WIB
        if ($timeStr >= '08:45' && $timeStr < '09:00') {
            return [
                'is_open' => true,
                'session' => 'PRE_OPENING',
                'description' => 'Sesi Pra-Pembukaan Bursa (08:45 - 08:59 WIB)',
                'timestamp_wib' => $dt->format('Y-m-d H:i:s'),
            ];
        }

        // Sesi 1
        $s1End = $isFriday ? '11:30' : '12:00';
        if ($timeStr >= '09:00' && $timeStr < $s1End) {
            return [
                'is_open' => true,
                'session' => 'SESSION_1',
                'description' => "Sesi Perdagangan Pagi (09:00 - {$s1End} WIB)",
                'timestamp_wib' => $dt->format('Y-m-d H:i:s'),
            ];
        }

        // Jeda Siang
        $s2Start = $isFriday ? '14:00' : '13:30';
        if ($timeStr >= $s1End && $timeStr < $s2Start) {
            return [
                'is_open' => false,
                'session' => 'MIDDAY_BREAK',
                'description' => "Jeda Istirahat Siang Bursa ({$s1End} - {$s2Start} WIB)",
                'timestamp_wib' => $dt->format('Y-m-d H:i:s'),
            ];
        }

        // Sesi 2
        if ($timeStr >= $s2Start && $timeStr < '15:45') {
            return [
                'is_open' => true,
                'session' => 'SESSION_2',
                'description' => "Sesi Perdagangan Siang/Sore ({$s2Start} - 15:45 WIB)",
                'timestamp_wib' => $dt->format('Y-m-d H:i:s'),
            ];
        }

        // Pra-penutupan & Pasca-penutupan: 15:45 - 16:15 WIB
        if ($timeStr >= '15:45' && $timeStr <= '16:15') {
            return [
                'is_open' => false,
                'session' => 'POST_CLOSING',
                'description' => 'Sesi Pembentukan Harga Penutupan & Pasca-Penutupan (15:45 - 16:15 WIB)',
                'timestamp_wib' => $dt->format('Y-m-d H:i:s'),
            ];
        }

        return [
            'is_open' => false,
            'session' => 'CLOSED',
            'description' => 'Pasar Bursa Tutup di Luar Jam Operasional',
            'timestamp_wib' => $dt->format('Y-m-d H:i:s'),
        ];
    }
}
