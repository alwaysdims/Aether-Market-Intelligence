<?php

namespace App\Services;

use App\Models\MarketObservation;
use App\Models\NewsItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class DataFreshnessPolicyService
{
    public function __construct(
        protected BeiCalendarService $calendar
    ) {}

    /**
     * Freshness TTL and publication schedule policies per data type.
     */
    public const POLICIES = [
        'eod_market_data' => [
            'provider' => 'Yahoo Finance (yfinance)',
            'publication_schedule' => 'Setiap hari bursa pukul 16:00 WIB',
            'delay_minutes' => 15,
            'max_acceptable_delay_days' => 1, // Skip non-trading days
            'cost_tier' => 'Rp0 / Public',
        ],
        'intraday_delayed' => [
            'provider' => 'Yahoo Finance (yfinance)',
            'publication_schedule' => 'Sesi bursa reguler (09:00 - 16:00 WIB)',
            'delay_minutes' => 15,
            'max_acceptable_delay_minutes' => 30,
            'cost_tier' => 'Rp0 / Public',
        ],
        'news_articles' => [
            'provider' => 'LKBN ANTARA RSS & GDELT',
            'publication_schedule' => 'Real-time syndication',
            'relevance_window_days' => 7,
            'max_article_age_days' => 30,
            'cost_tier' => 'Rp0 / Public',
        ],
        'macro_indicators' => [
            'provider' => 'Bank Indonesia / BPS / World Bank',
            'publication_schedule' => 'Bulanan (CPI) / Kuartalan (PDB) / Tahunan',
            'validity_window_days' => 90,
            'cost_tier' => 'Rp0 / Public',
        ],
    ];

    /**
     * Evaluate data inventory and freshness for a specific ticker and requested data type.
     *
     * Returns one of:
     * - AVAILABLE_FRESH
     * - AVAILABLE_BUT_INSUFFICIENT_FOR_REQUEST
     * - STALE
     * - MISSING
     * - CONFLICTED
     * - SOURCE_UNAVAILABLE
     */
    public function assessFreshness(
        string $ticker,
        string $dataTypeRequested = 'eod',
        ?CarbonInterface $asOf = null
    ): array {
        $now = ($asOf ?? Carbon::now('Asia/Jakarta'))->copy()->timezone('Asia/Jakarta');

        // Determine the latest expected trading day
        $isTodayTrading = $this->calendar->isTradingDay($now);
        $isMarketClosedToday = ($now->hour >= 16);

        if ($isTodayTrading && $isMarketClosedToday) {
            $expectedEodDate = $now->copy()->startOfDay();
        } else {
            $expectedEodDate = $this->calendar->getCurrentTradingDay($now->copy()->subDay())->startOfDay();
        }

        // 1. Check MarketObservation in DB
        $latestObs = app(CanonicalMarketReadService::class)->latest($ticker);

        // 2. Check News in DB
        $recentNewsCount = NewsItem::where(function ($q) use ($ticker) {
            $q->where('related_tickers', 'like', "%{$ticker}%")
                ->orWhere('title', 'like', "%{$ticker}%");
        })
            ->where('published_at', '>=', $now->copy()->subDays(7))
            ->count();

        // Check for Conflicted records
        if ($latestObs && $latestObs->validation_status === 'CONFLICTED') {
            return [
                'status' => 'CONFLICTED',
                'ticker' => $ticker,
                'data_type' => $dataTypeRequested,
                'latest_obs' => $latestObs,
                'expected_date' => $expectedEodDate->format('Y-m-d'),
                'observed_date' => $latestObs->bar_date->format('Y-m-d'),
                'reason' => 'Terdapat konflik data harga penutupan antara sumber terdaftar (selisih > 0.5%). Memerlukan karantina audit.',
                'requires_fetch' => false,
            ];
        }

        // If no observation exists at all -> MISSING
        if (! $latestObs) {
            return [
                'status' => 'MISSING',
                'ticker' => $ticker,
                'data_type' => $dataTypeRequested,
                'latest_obs' => null,
                'expected_date' => $expectedEodDate->format('Y-m-d'),
                'observed_date' => null,
                'reason' => "Belum ada rekod data pasar tersimpan untuk emiten {$ticker} di database.",
                'requires_fetch' => true,
            ];
        }

        $obsDate = $latestObs->bar_date->copy()->startOfDay();

        // Case: User requested INTRADAY / REALTIME
        if ($dataTypeRequested === 'delayed' || $dataTypeRequested === 'realtime') {
            if (! $isTodayTrading) {
                // Market is closed on weekend / holiday -> Intraday cannot exist
                return [
                    'status' => 'AVAILABLE_BUT_INSUFFICIENT_FOR_REQUEST',
                    'ticker' => $ticker,
                    'data_type' => $dataTypeRequested,
                    'latest_obs' => $latestObs,
                    'expected_date' => $expectedEodDate->format('Y-m-d'),
                    'observed_date' => $obsDate->format('Y-m-d'),
                    'reason' => "Pasar BEI sedang libur/tutup. Data intraday tidak tersedia; data penutupan terakhir ({$obsDate->format('d M Y')}) tersedia.",
                    'requires_fetch' => false,
                ];
            }

            // Market is open today: check if we have today's observation within 30 min
            if ($obsDate->equalTo($now->copy()->startOfDay())) {
                $diffMinutes = $latestObs->observation_time->diffInMinutes($now);
                if ($diffMinutes <= 30) {
                    return [
                        'status' => 'AVAILABLE_FRESH',
                        'ticker' => $ticker,
                        'data_type' => $dataTypeRequested,
                        'latest_obs' => $latestObs,
                        'expected_date' => $expectedEodDate->format('Y-m-d'),
                        'observed_date' => $obsDate->format('Y-m-d'),
                        'reason' => "Data bar pasar hari ini tersedia dengan delay {$latestObs->delay_minutes} menit.",
                        'requires_fetch' => false,
                    ];
                }
            }

            return [
                'status' => 'STALE',
                'ticker' => $ticker,
                'data_type' => $dataTypeRequested,
                'latest_obs' => $latestObs,
                'expected_date' => $now->format('Y-m-d'),
                'observed_date' => $obsDate->format('Y-m-d'),
                'reason' => 'Data intraday perlu diperbarui untuk sesi bursa hari ini.',
                'requires_fetch' => true,
            ];
        }

        // A provisional bar cannot satisfy an EOD request, regardless of calendar date.
        if (! $latestObs->is_eod_final || $latestObs->validation_status !== 'VALID') {
            return [
                'status' => 'AVAILABLE_BUT_INSUFFICIENT_FOR_REQUEST',
                'ticker' => $ticker,
                'data_type' => 'eod',
                'latest_obs' => $latestObs,
                'expected_date' => $expectedEodDate->format('Y-m-d'),
                'observed_date' => $obsDate->format('Y-m-d'),
                'reason' => 'Bar tersimpan belum tervalidasi final untuk permintaan EOD.',
                'requires_fetch' => false,
            ];
        }

        // Case: Standard EOD
        // If observed bar date equals or is after expected trading day -> AVAILABLE_FRESH
        if ($obsDate->greaterThanOrEqualTo($expectedEodDate)) {
            return [
                'status' => 'AVAILABLE_FRESH',
                'ticker' => $ticker,
                'data_type' => 'eod',
                'latest_obs' => $latestObs,
                'expected_date' => $expectedEodDate->format('Y-m-d'),
                'observed_date' => $obsDate->format('Y-m-d'),
                'reason' => "Data EOD bar penutupan terakhir ({$obsDate->format('d M Y')}) mutakhir dan sesuai kalender BEI.",
                'requires_fetch' => false,
            ];
        }

        // Observed date is older than expected trading day -> STALE
        return [
            'status' => 'STALE',
            'ticker' => $ticker,
            'data_type' => 'eod',
            'latest_obs' => $latestObs,
            'expected_date' => $expectedEodDate->format('Y-m-d'),
            'observed_date' => $obsDate->format('Y-m-d'),
            'reason' => "Data pasar tersimpan bertanggal {$obsDate->format('d M Y')}, sedangkan sesi perdagangan terakhir BEI adalah {$expectedEodDate->format('d M Y')}.",
            'requires_fetch' => true,
        ];
    }
}
