<?php

namespace Tests\Unit;

use App\Models\MarketObservation;
use App\Services\CanonicalMarketReadService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataReconciliationCalculationTest extends TestCase
{
    use RefreshDatabase;

    private CanonicalMarketReadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CanonicalMarketReadService::class);
    }

    public function test_bris_regression_case_percentage_calculation(): void
    {
        $prevBar = new MarketObservation([
            'ticker' => 'BRIS',
            'bar_date' => Carbon::parse('2026-10-23'),
            'open_price' => 1035,
            'high_price' => 1055,
            'low_price' => 1015,
            'close_price' => 1020,
            'volume' => 177786500,
        ]);

        $latestBar = new MarketObservation([
            'ticker' => 'BRIS',
            'bar_date' => Carbon::parse('2026-10-26'),
            'open_price' => 1030,
            'high_price' => 1070,
            'low_price' => 1025,
            'close_price' => 1055,
            'volume' => 115630800,
        ]);

        $changes = $this->service->priceChanges($latestBar, $prevBar);

        $this->assertSame(1055.0, $changes['close']);
        $this->assertSame(1030.0, $changes['open']);
        $this->assertSame(1020.0, $changes['previous_close']);
        $this->assertSame('2026-10-23', $changes['previous_bar_date']);

        // Expected daily change: +35 and +3.43%
        $this->assertSame(35.0, $changes['daily_change']);
        $this->assertSame(3.43, $changes['daily_change_pct']);

        // Expected change vs open: +25 and +2.43%
        $this->assertSame(25.0, $changes['change_vs_open']);
        $this->assertSame(2.43, $changes['change_vs_open_pct']);

        $this->assertSame('VALID_PREVIOUS_SESSION', $changes['basis_status']);
        $this->assertTrue($changes['is_previous_close_available']);
    }

    public function test_missing_previous_close_does_not_fallback_to_open_silently(): void
    {
        $latestBar = new MarketObservation([
            'ticker' => 'NEWB',
            'bar_date' => Carbon::parse('2026-10-26'),
            'open_price' => 500,
            'high_price' => 550,
            'low_price' => 490,
            'close_price' => 520,
            'volume' => 1000000,
        ]);

        // Null prevObs passed and no DB records
        $changes = $this->service->priceChanges($latestBar, null);

        $this->assertNull($changes['previous_close']);
        $this->assertNull($changes['daily_change']);
        $this->assertNull($changes['daily_change_pct']);
        $this->assertSame('NO_PREVIOUS_SESSION', $changes['basis_status']);
        $this->assertFalse($changes['is_previous_close_available']);

        // Intraday vs open must still be computed independently
        $this->assertSame(20.0, $changes['change_vs_open']);
        $this->assertSame(4.0, $changes['change_vs_open_pct']);
    }

    public function test_gap_history_disqualifies_daily_change(): void
    {
        // Expected previous trading session for 2026-10-26 (Monday) is 2026-10-23 (Friday).
        // If bar is from 2026-10-16 (gap of several days), it must be disqualified from daily change.
        $gapPrevBar = new MarketObservation([
            'ticker' => 'GAPP',
            'bar_date' => Carbon::parse('2026-10-16'),
            'open_price' => 1000,
            'high_price' => 1020,
            'low_price' => 980,
            'close_price' => 1000,
            'volume' => 500000,
        ]);

        $latestBar = new MarketObservation([
            'ticker' => 'GAPP',
            'bar_date' => Carbon::parse('2026-10-26'),
            'open_price' => 1050,
            'high_price' => 1080,
            'low_price' => 1040,
            'close_price' => 1060,
            'volume' => 800000,
        ]);

        $changes = $this->service->priceChanges($latestBar, $gapPrevBar);

        $this->assertFalse($changes['is_previous_close_available']);
        $this->assertNull($changes['daily_change']);
        $this->assertNull($changes['daily_change_pct']);
        $this->assertSame('HISTORICAL_GAP_DISQUALIFIED', $changes['basis_status']);
        $this->assertNotNull($changes['gap_reason']);
        $this->assertStringContainsString('Histori berlubang', $changes['gap_reason']);
        $this->assertSame('2026-10-23', $changes['expected_previous_bar_date']);
        $this->assertSame('2026-10-16', $changes['previous_bar_date']);
    }

    public function test_null_observation_returns_safe_data_not_available(): void
    {
        $changes = $this->service->priceChanges(null);

        $this->assertNull($changes['close']);
        $this->assertNull($changes['open']);
        $this->assertNull($changes['previous_close']);
        $this->assertNull($changes['daily_change']);
        $this->assertNull($changes['daily_change_pct']);
        $this->assertSame('DATA_NOT_AVAILABLE', $changes['basis_status']);
        $this->assertFalse($changes['is_previous_close_available']);
    }
}
