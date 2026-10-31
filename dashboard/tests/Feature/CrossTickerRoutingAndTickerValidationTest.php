<?php

namespace Tests\Feature;

use App\Services\TelegramTwoWayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossTickerRoutingAndTickerValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_user_pilot_screening_phrase_routes_to_screening_broker_accumulation(): void
    {
        $twoWay = app(TelegramTwoWayService::class);
        $prompt = 'Dari 10 saham pilot IDX Edge, mana yang memenuhi filter harga belum naik banyak dan memiliki broker net buyer besar selama Oktober? Bandingkan broker utamanya dengan sesi terakhir. Jelaskan periode dan keterbatasannya.';

        $this->assertTrue($twoWay->isCrossTickerScreeningQuery($prompt));
        $this->assertTrue($twoWay->isBrokerQuery($prompt));
        $this->assertNull($twoWay->extractTicker($prompt)); // 'PILOT' must NOT be extracted as a ticker

        $intent = $twoWay->classifyIntent($prompt);
        $this->assertSame('SCREENING_BROKER_ACCUMULATION', $intent);
    }

    public function test_generic_screening_without_ticker_routes_to_screening_broker_accumulation(): void
    {
        $twoWay = app(TelegramTwoWayService::class);
        $prompt = 'cari saham yang diakumulasi bulan ini';

        $this->assertTrue($twoWay->isCrossTickerScreeningQuery($prompt));
        $this->assertTrue($twoWay->isBrokerQuery($prompt));
        $this->assertNull($twoWay->extractTicker($prompt));

        $intent = $twoWay->classifyIntent($prompt);
        $this->assertSame('SCREENING_BROKER_ACCUMULATION', $intent);
    }

    public function test_historical_broker_query_with_single_ticker_routes_to_query_broker_flow(): void
    {
        $twoWay = app(TelegramTwoWayService::class);
        $prompt = 'siapa yang beli bersih broker saham SMRA selama 3 bulan terakhir?';

        $this->assertSame('SMRA', $twoWay->extractTicker($prompt));
        $this->assertTrue($twoWay->isBrokerQuery($prompt));

        $intent = $twoWay->classifyIntent($prompt);
        $this->assertSame('QUERY_BROKER_FLOW', $intent);
    }

    public function test_comparison_query_extracts_all_tickers_and_routes_to_comparison(): void
    {
        $twoWay = app(TelegramTwoWayService::class);
        $prompt = 'bandingkan kinerja saham SMRA dan BMRI';

        $tickers = $twoWay->extractTickers($prompt);
        $this->assertCount(2, $tickers);
        $this->assertContains('SMRA', $tickers);
        $this->assertContains('BMRI', $tickers);

        $intent = $twoWay->classifyIntent($prompt);
        $this->assertSame('COMMAND_COMPARISON', $intent);
    }

    public function test_followup_screening_questions_route_to_screening_broker_accumulation(): void
    {
        $twoWay = app(TelegramTwoWayService::class);

        $prompts = [
            'Apakah cakupan tetap 10 saham pilot?',
            'Apakah periode harga dan broker dijelaskan terpisah?',
            'Apakah setiap kandidat memiliki angka broker pendukung?',
            'Apakah perbandingan sesi terakhir dan keterbatasan Top-N dijelaskan?',
        ];

        foreach ($prompts as $p) {
            $this->assertTrue($twoWay->isCrossTickerScreeningQuery($p), "Prompt '{$p}' should be cross-ticker");
            $this->assertSame('SCREENING_BROKER_ACCUMULATION', $twoWay->classifyIntent($p), "Prompt '{$p}' should route to SCREENING_BROKER_ACCUMULATION");
        }
    }

    public function test_explicit_invalid_ticker_is_rejected_properly(): void
    {
        $twoWay = app(TelegramTwoWayService::class);
        $prompt = 'analisis saham ZZZZ';

        $cand = $twoWay->extractTicker($prompt);
        $this->assertSame('ZZZZ', $cand);

        $res = $twoWay->handleInboundMessage([
            'text' => $prompt,
            'message_id' => 999,
            'from' => ['id' => 5923895922],
            'chat' => ['id' => 5923895922],
        ]);
        $this->assertStringContainsString('KLARIFIKASI CAKUPAN INSTRUMEN', $res['reply_text']);
        $this->assertStringContainsString('ZZZZ', $res['reply_text']);
        $this->assertStringContainsString('tidak terdaftar dalam semesta emiten BEI', $res['reply_text']);
    }

    public function test_exact_bnbr_holistic_inquiry_routes_to_comprehensive_analysis(): void
    {
        $twoWay = app(TelegramTwoWayService::class);
        $prompt = 'berikan analisis fundamental, teknikal, aktivitas broker, kelayakan day trade dan swing, serta cari info terbaru dari internet tentang bnbr';

        $method = new \ReflectionMethod(TelegramTwoWayService::class, 'classifyIntent');
        $method->setAccessible(true);
        $intent = $method->invoke($twoWay, $prompt);

        $this->assertEquals('QUERY_COMPREHENSIVE_ANALYSIS', $intent);
    }
}
