<?php

namespace Tests\Feature;

use App\Models\MarketObservation;
use App\Services\BeiUniverseRegistry;
use App\Services\TechnicalMetricsCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicalMetricsSufficiencyTest extends TestCase
{
    use RefreshDatabase;

    protected function createBars(string $ticker, int $count, string $endDate = '2026-10-30'): void
    {
        $end = Carbon::parse($endDate);
        for ($i = $count - 1; $i >= 0; $i--) {
            $date = $end->copy()->subDays($i * 2); // Spread across days
            MarketObservation::create([
                'ticker' => $ticker,
                'bar_date' => $date->toDateString(),
                'observation_time' => $date->toIso8601String(),
                'open_price' => 1000 + ($i * 10),
                'high_price' => 1050 + ($i * 10),
                'low_price' => 980 + ($i * 10),
                'close_price' => 1020 + ($i * 10),
                'volume' => 1000000,
                'source' => 'IDX Edge PRO',
                'validation_status' => 'VALID',
                'is_eod_final' => true,
            ]);
        }
    }

    public function test_zero_bars_returns_null(): void
    {
        $service = app(TechnicalMetricsCalculationService::class);
        $res = $service->calculateForTicker('TEST', '2026-10-30');
        $this->assertNull($res);
    }

    public function test_14_bars_has_no_rsi_and_no_sma20(): void
    {
        $this->createBars('B14B', 14);
        $service = app(TechnicalMetricsCalculationService::class);
        $res = $service->calculateForTicker('B14B', '2026-10-30');

        $this->assertNotNull($res);
        $this->assertNull($res->sma_20, 'SMA20 must be null for 14 bars');
        $this->assertNull($res->sma_50, 'SMA50 must be null for 14 bars');
        $this->assertNull($res->sma_200, 'SMA200 must be null for 14 bars');
        $this->assertNull($res->rsi_14, 'RSI14 must be null for 14 bars (minimum 15 bars for seed)');
        $this->assertNull($res->macd, 'MACD must be null for 14 bars');
        $this->assertNull($res->atr_14, 'ATR14 must be null for 14 bars');
        $this->assertSame('INSUFFICIENT_HISTORY', $res->technical_status);
    }

    public function test_25_bars_has_sma20_rsi_atr_but_no_sma50_or_sma200_or_macd(): void
    {
        $this->createBars('B25B', 25);
        $service = app(TechnicalMetricsCalculationService::class);
        $res = $service->calculateForTicker('B25B', '2026-10-30');

        $this->assertNotNull($res);
        $this->assertNotNull($res->sma_20, 'SMA20 must be calculated for 25 bars');
        $this->assertNull($res->sma_50, 'SMA50 must be null for 25 bars');
        $this->assertNull($res->sma_200, 'SMA200 must be null for 25 bars');
        $this->assertNotNull($res->rsi_14, 'RSI14 must be calculated for 25 bars');
        $this->assertNotNull($res->atr_14, 'ATR14 must be calculated for 25 bars');
        $this->assertNull($res->macd, 'MACD must be null for 25 bars (minimum 35 bars required)');
        $this->assertSame('PARTIAL_CALCULATED', $res->technical_status);

        $flags = $res->data_quality_flags;
        $this->assertSame('SUFFICIENT', $flags['sufficiency']['sma_20']);
        $this->assertSame('INSUFFICIENT_25_OF_50', $flags['sufficiency']['sma_50']);
        $this->assertSame('INSUFFICIENT_25_OF_200', $flags['sufficiency']['sma_200']);
        $this->assertSame('CALCULATED_WILDER_RMA', $flags['sufficiency']['rsi_14']);
        $this->assertSame('INSUFFICIENT_HISTORY', $flags['sufficiency']['macd']);
    }

    public function test_200_bars_has_full_indicators_calculated(): void
    {
        $this->createBars('B200', 200);
        $service = app(TechnicalMetricsCalculationService::class);
        $res = $service->calculateForTicker('B200', '2026-10-30');

        $this->assertNotNull($res);
        $this->assertNotNull($res->sma_20);
        $this->assertNotNull($res->sma_50);
        $this->assertNotNull($res->sma_200, 'SMA200 must be calculated for 200 bars');
        $this->assertNotNull($res->rsi_14);
        $this->assertNotNull($res->macd);
        $this->assertSame('CALCULATED', $res->technical_status);
        $this->assertSame('SUFFICIENT', $res->data_quality_flags['sufficiency']['sma_200']);
    }

    public function test_independent_rsi_benchmark_calculation(): void
    {
        // 16 constant closing prices followed by a drop
        // Test strict mathematical formula output
        $this->createBars('BRSI', 20);
        $service = app(TechnicalMetricsCalculationService::class);
        $res = $service->calculateForTicker('BRSI', '2026-10-30');

        $this->assertNotNull($res->rsi_14);
        $this->assertGreaterThanOrEqual(0.0, $res->rsi_14);
        $this->assertLessThanOrEqual(100.0, $res->rsi_14);
    }

    public function test_price_fraction_resolution_for_different_boards_and_price_levels(): void
    {
        // Papan Pemantauan Khusus (Flat Rp 1)
        $fracWatchlist = BeiUniverseRegistry::resolvePriceFraction(41.0, 'Papan Pemantauan Khusus');
        $this->assertSame(1, $fracWatchlist['tick_size']);
        $this->assertSame('PAPAN_PEMANTAUAN_KHUSUS', $fracWatchlist['board']);

        // Papan Utama < 200
        $fracUnder200 = BeiUniverseRegistry::resolvePriceFraction(150.0, 'Utama');
        $this->assertSame(1, $fracUnder200['tick_size']);

        // Papan Utama 200 - < 500
        $frac200 = BeiUniverseRegistry::resolvePriceFraction(350.0, 'Utama');
        $this->assertSame(2, $frac200['tick_size']);

        // Papan Utama 500 - < 2000
        $frac500 = BeiUniverseRegistry::resolvePriceFraction(1200.0, 'Utama');
        $this->assertSame(5, $frac500['tick_size']);

        // Papan Utama 2000 - < 5000
        $frac2000 = BeiUniverseRegistry::resolvePriceFraction(3100.0, 'Utama');
        $this->assertSame(10, $frac2000['tick_size']);

        // Papan Utama >= 5000 (e.g. BBCA, BMRI)
        $frac5000 = BeiUniverseRegistry::resolvePriceFraction(5000.0, 'Utama');
        $this->assertSame(25, $frac5000['tick_size']);
    }
}
