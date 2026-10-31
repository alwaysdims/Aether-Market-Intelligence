<?php

namespace Tests\Feature;

use App\Models\MarketObservation;
use App\Services\CanonicalMarketReadService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdxEdgeProviderPriorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_reader_prefers_valid_idx_edge_over_newer_yahoo_row(): void
    {
        $this->observation('Yahoo Finance (via yfinance)', 5000, '2026-10-30 10:01:00', 'VALID', true);
        $edge = $this->observation('IDX Edge PRO', 4990, '2026-10-30 10:00:00', 'VALID', true);

        $latest = app(CanonicalMarketReadService::class)->latest('BBCA');

        $this->assertSame($edge->id, $latest?->id);
        $this->assertSame('IDX Edge PRO', $latest?->source);
    }

    public function test_canonical_reader_falls_back_when_idx_edge_is_conflicted(): void
    {
        $yahoo = $this->observation('Yahoo Finance (via yfinance)', 5000, '2026-10-30 10:00:00', 'VALID', true);
        $this->observation('IDX Edge PRO', 4990, '2026-10-30 10:01:00', 'CONFLICTED', true);

        $latest = app(CanonicalMarketReadService::class)->latest('BBCA');

        $this->assertSame($yahoo->id, $latest?->id);
    }

    public function test_provider_rows_coexist_for_same_bar_without_overwrite(): void
    {
        $this->observation('Yahoo Finance (via yfinance)', 5000, '2026-10-30 10:00:00', 'VALID', true);
        $this->observation('IDX Edge PRO', 4990, '2026-10-30 10:01:00', 'VALID', true);

        $this->assertSame(2, MarketObservation::where('ticker', 'BBCA')->count());
        $this->assertDatabaseHas('market_observations', ['source' => 'Yahoo Finance (via yfinance)', 'close_price' => 5000]);
        $this->assertDatabaseHas('market_observations', ['source' => 'IDX Edge PRO', 'close_price' => 4990]);
    }

    public function test_recent_history_deduplicates_same_date_using_provider_priority(): void
    {
        $this->observation('Yahoo Finance (via yfinance)', 5000, '2026-10-30 10:01:00', 'VALID', true);
        $this->observation('IDX Edge PRO', 4990, '2026-10-30 10:00:00', 'VALID', true);

        $history = app(CanonicalMarketReadService::class)->recentHistory('BBCA');

        $this->assertCount(1, $history);
        $this->assertSame('IDX Edge PRO', $history->first()->source);
    }

    private function observation(string $source, float $close, string $time, string $validation, bool $final): MarketObservation
    {
        return MarketObservation::create([
            'ticker' => 'BBCA',
            'observation_time' => Carbon::parse($time, 'Asia/Jakarta')->utc(),
            'bar_date' => '2026-10-30',
            'open_price' => $close,
            'high_price' => $close,
            'low_price' => $close,
            'close_price' => $close,
            'volume' => 1000,
            'source' => $source,
            'interval' => '1d',
            'data_classification' => 'PUBLIC_MARKET_EOD',
            'is_eod_final' => $final,
            'validation_status' => $validation,
        ]);
    }
}
