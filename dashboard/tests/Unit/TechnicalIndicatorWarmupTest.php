<?php

namespace Tests\Unit;

use App\Services\TechnicalMetricsCalculationService;
use Tests\TestCase;

class TechnicalIndicatorWarmupTest extends TestCase
{
    public function test_short_display_range_keeps_indicator_warmup_series(): void
    {
        $closes = array_map(static fn (int $i): float => 100 + ($i * 0.5), range(0, 249));
        $engine = app(TechnicalMetricsCalculationService::class);
        $series = $engine->calculateIndicatorSeries($closes);

        $this->assertCount(250, $series['sma_50']);
        $this->assertNotNull($series['sma_50'][249]);
        $this->assertNotNull($series['sma_200'][249]);
        $this->assertNotNull($series['macd'][249]);
        $this->assertNotNull($series['macd_signal'][249]);
        $this->assertNull($series['sma_200'][198]);
    }
}
