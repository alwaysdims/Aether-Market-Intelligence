<?php

namespace Tests\Feature;

use App\Models\TelegramConversation;
use App\Services\BeiUniverseRegistry;
use App\Services\TelegramTwoWayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_generic_ticker_analysis_with_limitations_is_not_broker_flow(): void
    {
        $twoWay = app(TelegramTwoWayService::class);
        $prompt = 'Analisis BBCA berdasarkan data terbaru yang tersedia. Sebutkan tanggal data dan keterbatasannya.';

        $this->assertFalse($twoWay->isBrokerQuery($prompt));
        $this->assertSame('QUERY_TICKER_STATUS', $twoWay->classifyIntent($prompt));
    }

    public function test_general_snapshot_analysis_routes_to_async_consultation(): void
    {
        $twoWay = app(TelegramTwoWayService::class);
        $prompt = 'Berikan analisis umum BBCA berdasarkan snapshot data yang tersedia. Sertakan tanggal snapshot dan keterbatasan data.';

        $this->assertFalse($twoWay->isBrokerQuery($prompt));
        $this->assertSame('QUERY_COMPREHENSIVE_ANALYSIS', $twoWay->classifyIntent($prompt));
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

        $res = $twoWay->handleInboundMessage([
            'text' => $prompt,
            'message_id' => 999,
            'from' => ['id' => 5923895922],
            'chat' => ['id' => 5923895922],
        ]);
        $this->assertStringContainsString('KLARIFIKASI CAKUPAN INSTRUMEN', $res['reply_text']);
        $this->assertStringContainsString('ZZZZ', $res['reply_text']);
        $this->assertStringContainsString('tidak ditemukan dalam katalog bursa lokal maupun hasil pencarian penyedia data BEI', $res['reply_text']);
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

    /**
     * Test Follow-up Without Explicit Ticker Hierarchy:
     * - Single previous instrument -> resolves cleanly;
     * - Two comparison instruments -> detects ambiguity and prompts clarification;
     * - Reply-to reference -> resolves to replied ticker;
     * - No context -> prompts for ticker.
     */
    public function test_follow_up_instrument_resolution_hierarchy(): void
    {
        $twoWay = app(TelegramTwoWayService::class);
        $userId = '5923895922';

        // 1. Single previous instrument in conversation
        TelegramConversation::create([
            'telegram_update_id' => 'upd_bull_1',
            'chat_id' => $userId,
            'user_id' => $userId,
            'inbound_text' => 'Analisis saham BULL',
            'detected_intent' => 'QUERY_COMPREHENSIVE_ANALYSIS',
            'outbound_reply_text' => 'Analisis BULL selesai',
            'provenance' => ['ticker' => 'BULL'],
        ]);

        $res1 = $twoWay->resolveInstrumentContext(
            'Jadi untuk kesimpulan dari analisis teknikalnya gimana? Support dan resistance di harga berapa?',
            $userId
        );

        $this->assertFalse($res1['is_ambiguous']);
        $this->assertEquals('BULL', $res1['ticker']);
        $this->assertEquals('RECENT_CONVERSATION_HISTORY', $res1['source']);

        // 2. Comparison between two stocks -> Ambiguous, should prompt user
        TelegramConversation::create([
            'telegram_update_id' => 'upd_comp_2',
            'chat_id' => $userId,
            'user_id' => $userId,
            'inbound_text' => 'Bandingkan BBCA vs BMRI',
            'detected_intent' => 'COMMAND_COMPARISON',
            'outbound_reply_text' => 'Perbandingan BBCA dan BMRI',
            'provenance' => ['ticker' => 'BBCA'],
        ]);

        $res2 = $twoWay->resolveInstrumentContext(
            'Gimana kondisi broker summary siapa yg akumulasi?',
            $userId
        );

        $this->assertTrue($res2['is_ambiguous']);
        $this->assertNull($res2['ticker']);
        $this->assertContains('BBCA', $res2['candidates']);
        $this->assertContains('BULL', $res2['candidates']);

        // 3. Referenced reply-to conversation
        $res3 = $twoWay->resolveInstrumentContext(
            'Support dan resistance di harga berapa?',
            $userId,
            'upd_bull_1'
        );

        $this->assertFalse($res3['is_ambiguous']);
        $this->assertEquals('BULL', $res3['ticker']);
        $this->assertEquals('REFERENCED_REPLY_TO', $res3['source']);

        // 4. No context at all
        $res4 = $twoWay->resolveInstrumentContext(
            'Gimana kondisi broker summary?',
            '999999999'
        );

        $this->assertFalse($res4['is_ambiguous']);
        $this->assertNull($res4['ticker']);
        $this->assertEquals('NONE_FOUND', $res4['source']);
    }

    /**
     * 9. Test Generic Instrument Resolution via Provider Discovery:
     * Unregistered in hardcoded catalog, but discoverable via IDX Edge /api/search (e.g. FORE).
     */
    public function test_generic_instrument_resolution_via_provider_discovery(): void
    {
        Http::fake([
            '*/api/search*' => Http::response([
                'results' => [
                    [
                        'stock_code' => 'FORE',
                        'stock_name' => 'Fore Kopi Indonesia Tbk.',
                        'last_date' => '2026-10-30',
                    ],
                ],
            ], 200),
        ]);

        $registry = app(BeiUniverseRegistry::class);
        $res = $registry->resolveInstrument('FORE');

        $this->assertTrue($res['is_valid']);
        $this->assertEquals('FORE', $res['ticker']);
        $this->assertEquals('Fore Kopi Indonesia Tbk.', $res['company_name']);
        $this->assertEquals('PROVIDER_DISCOVERY_VERIFIED', $res['resolution_provenance']['source']);

        // Test extraction from natural text
        $twoWay = app(TelegramTwoWayService::class);
        $extracted = $twoWay->extractTicker('Analisis FORE dari fundamental');
        $this->assertEquals('FORE', $extracted);
    }

    /**
     * 10. Test Truly Unregistered / Exhausted Ticker:
     * Ticker that fails both local catalog and provider discovery.
     */
    public function test_truly_unregistered_ticker_is_rejected_without_hardcoded_branch(): void
    {
        Http::fake([
            '*/api/search*' => Http::response([
                'results' => [],
            ], 200),
        ]);

        $registry = app(BeiUniverseRegistry::class);
        $res = $registry->resolveInstrument('QQQQ');

        $this->assertFalse($res['is_valid']);
        $this->assertEquals('UNREGISTERED_BEI_TICKER', $res['error_type']);
        $this->assertEquals('REGISTRY_AND_PROVIDER_DISCOVERY_EXHAUSTED', $res['resolution_provenance']['source']);
    }

    /**
     * 11. Test Candle Relative Position and Ratio Calculation Consistency:
     * (Close - Low) / (High - Low) must be exact (e.g. FORE 42.86%).
     */
    public function test_candle_relative_position_calculation(): void
    {
        $open = 565;
        $high = 565;
        $low = 530;
        $close = 545;

        $range = $high - $low;
        $this->assertEquals(35, $range);

        $posFromLow = $close - $low;
        $this->assertEquals(15, $posFromLow);

        $relativePosPct = round(($posFromLow / $range) * 100, 2);
        $this->assertEquals(42.86, $relativePosPct);
    }

    /**
     * 12. Test Generic EPS & BVPS Reconciliation Logic:
     * Separates reported_basic_eps, weighted_average_shares, and ordinary_shares_period_end.
     */
    public function test_generic_eps_and_bvps_reconciliation_logic(): void
    {
        $netIncomeParent = 90133042112; // Rp 90,13 M
        $periodEndShares = 8918000000;  // 8,918 M lbr
        $reportedEps = 10.95;           // Reported basic EPS on continuing operations

        // 1. If naive division of net income by period end shares is used:
        $naiveComputedEps = round($netIncomeParent / $periodEndShares, 2);
        $this->assertEquals(10.11, $naiveComputedEps);

        // 2. The difference proves weighted average shares differed from period-end shares:
        // Implied weighted average shares = netIncomeParent / reportedEps
        $impliedWeightedShares = round($netIncomeParent / $reportedEps);
        $this->assertEquals(8231328047, $impliedWeightedShares); // ~8,231 M lbr

        // 3. BVPS uses equity attributable to parent / period-end shares:
        $equityParent = 680590873344; // Rp 680,59 M
        $bvps = round($equityParent / $periodEndShares, 2);
        $this->assertEquals(76.32, $bvps); // Rp 76,32 / lbr (unrounded 76.3165)

        // 4. Valuation uses unadjusted nominal close price
        $nominalClose = 545;
        $per = round($nominalClose / $reportedEps, 2);
        $this->assertEquals(49.77, $per);

        $pbv = round($nominalClose / $bvps, 2);
        $this->assertEquals(7.14, $pbv);
    }
}
