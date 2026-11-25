<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class BeiCalendarService
{
    public const CALENDAR_VERSION = 'BEI_OFFICIAL_HOLIDAY_2024_2026_V1';

    public const BEI_HOLIDAYS_2024 = [
        '2024-01-01', '2024-02-08', '2024-02-09', '2024-03-11', '2024-03-12',
        '2024-04-08', '2024-04-09', '2024-04-10', '2024-04-11', '2024-04-12', '2024-04-15',
        '2024-05-01', '2024-05-09', '2024-05-10', '2024-05-23', '2024-05-24',
        '2024-06-17', '2024-06-18', '2024-09-16', '2024-11-27', '2024-12-25', '2024-12-26', '2024-12-31',
    ];

    public const BEI_HOLIDAYS_2025 = [
        '2025-01-01', '2025-01-27', '2025-01-28', '2025-01-29', '2025-03-28', '2025-03-31',
        '2025-04-01', '2025-04-02', '2025-04-03', '2025-04-04', '2025-04-07', '2025-04-18',
        '2025-05-01', '2025-05-12', '2025-05-13', '2025-05-29', '2025-05-30',
        '2025-06-06', '2025-06-09', '2025-06-27', '2025-09-05', '2025-12-25', '2025-12-26', '2025-12-31',
    ];

    public const BEI_HOLIDAYS_2026 = [
        '2026-01-01', '2026-01-16', '2026-02-16', '2026-02-17', '2026-03-18', '2026-03-19',
        '2026-03-20', '2026-03-23', '2026-03-24', '2026-04-03', '2026-05-01', '2026-05-14',
        '2026-05-15', '2026-05-27', '2026-05-28', '2026-06-01', '2026-06-16', '2026-08-17',
        '2026-08-25', '2026-12-24', '2026-12-25', '2026-12-31',
    ];

    public const BEI_HOLIDAYS = [
        ...self::BEI_HOLIDAYS_2024,
        ...self::BEI_HOLIDAYS_2025,
        ...self::BEI_HOLIDAYS_2026,
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

        return ! in_array($dateStr, self::BEI_HOLIDAYS, true);
    }

    /**
     * Check whether requested session has closed at supplied wall-clock time.
     */
    public function sessionHasClosed(CarbonInterface|string $sessionDate, ?CarbonInterface $now = null): bool
    {
        $target = $sessionDate instanceof CarbonInterface
            ? $sessionDate->copy()->timezone('Asia/Jakarta')->startOfDay()
            : Carbon::parse($sessionDate, 'Asia/Jakarta')->startOfDay();
        $dt = ($now ?? Carbon::now('Asia/Jakarta'))->copy()->timezone('Asia/Jakarta');

        return $this->isTradingDay($target)
            && $target->lessThan($dt->copy()->startOfDay())
            || ($this->isTradingDay($target)
                && $target->isSameDay($dt)
                && $dt->format('H:i') >= '16:05');
    }

    public function canRegisterQueue(CarbonInterface|string $sessionDate): bool
    {
        return $this->isTradingDay($sessionDate);
    }

    public function canFetchEodNow(CarbonInterface|string $sessionDate, ?CarbonInterface $now = null): bool
    {
        return $this->sessionHasClosed($sessionDate, $now);
    }

    /**
     * Check if market closing candle is final (on trading day and time >= 16:00 WIB).
     */
    public function isEodFinal(?CarbonInterface $now = null): bool
    {
        return $this->sessionHasClosed(($now ?? Carbon::now('Asia/Jakarta'))->toDateString(), $now);
    }

    public function calendarReason(CarbonInterface|string $sessionDate): ?string
    {
        $dt = $sessionDate instanceof CarbonInterface ? $sessionDate->copy() : Carbon::parse($sessionDate, 'Asia/Jakarta');
        if ($dt->isSaturday() || $dt->isSunday()) {
            return 'WEEKEND_'.strtoupper($dt->format('l'));
        }
        if (in_array($dt->format('Y-m-d'), self::BEI_HOLIDAYS, true)) {
            return 'BEI_HOLIDAY';
        }

        return null;
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

    /**
     * Get list of trading days between two dates inclusive.
     *
     * @return array<int, string> List of 'YYYY-MM-DD'
     */
    public function getTradingDaysBetween(CarbonInterface|string $startDate, CarbonInterface|string $endDate): array
    {
        $start = ($startDate instanceof CarbonInterface ? $startDate->copy() : Carbon::parse($startDate))->startOfDay();
        $end = ($endDate instanceof CarbonInterface ? $endDate->copy() : Carbon::parse($endDate))->startOfDay();

        if ($start->gt($end)) {
            return [];
        }

        $days = [];
        $curr = $start->copy();
        while ($curr->lte($end)) {
            if ($this->isTradingDay($curr)) {
                $days[] = $curr->toDateString();
            }
            $curr->addDay();
        }

        return $days;
    }
}
