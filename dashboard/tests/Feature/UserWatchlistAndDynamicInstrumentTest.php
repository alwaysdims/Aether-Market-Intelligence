<?php

namespace Tests\Feature;

use App\Models\EvidenceSnapshot;
use App\Models\MarketObservation;
use App\Models\PredictionSignal;
use App\Models\ResearchRequest;
use App\Models\TelegramConversation;
use App\Models\UserWatchlist;
use App\Services\AiToolExecutorService;
use App\Services\BeiCalendarService;
use App\Services\BeiUniverseRegistry;
use App\Services\CanonicalMarketReadService;
use App\Services\DataFreshnessPolicyService;
use App\Services\OnDemandResearchService;
use App\Services\StockScreeningService;
use App\Services\TelegramTwoWayService;
use App\Services\UserPortfolioService;
use App\Services\UserWatchlistService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserWatchlistAndDynamicInstrumentTest extends TestCase
{
    use RefreshDatabase;

    protected TelegramTwoWayService $twoWayService;

    protected UserWatchlistService $watchlistService;

    protected BeiUniverseRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoWayService = app(TelegramTwoWayService::class);
        $this->watchlistService = app(UserWatchlistService::class);
        $this->registry = app(BeiUniverseRegistry::class);
    }

    protected function makeUpdate(string $text, string $updateId = 'upd_1', string $msgId = '101'): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $msgId,
                'from' => [
                    'id' => 5923895922,
                    'username' => 'TestOwner',
                ],
                'chat' => [
                    'id' => 5923895922,
                    'type' => 'private',
                ],
                'text' => $text,
                'date' => time(),
            ],
        ];
    }

    /**
     * 1. Test adding PTRO to watchlist is idempotent.
     */
    public function test_add_watchlist_idempotent(): void
    {
        $res1 = $this->watchlistService->addTicker('5923895922', '5923895922', 'PTRO');
        $this->assertTrue($res1['success']);
        $this->assertTrue($res1['is_new']);
        $this->assertEquals('PTRO', $res1['ticker']);
        $this->assertEquals('PT Petrosea Tbk', $res1['company_name']);

        // Second time should report already registered
        $res2 = $this->watchlistService->addTicker('5923895922', '5923895922', 'PTRO');
        $this->assertTrue($res2['success']);
        $this->assertFalse($res2['is_new']);
        $this->assertEquals(1, UserWatchlist::where('user_id', '5923895922')->where('ticker', 'PTRO')->count());
    }

    /**
     * 2. Test natural language adds PTRO to watchlist via Telegram routing.
     */
    public function test_natural_language_watchlist_add(): void
    {
        $res = $this->twoWayService->handleInboundMessage(
            $this->makeUpdate('saya mau kamu menambahkan ptro', 'upd_add_ptro', '201')
        );

        $this->assertEquals('success', $res['status']);
        $this->assertStringContainsString('WATCHLIST DIPERBARUI', $res['reply_text']);
        $this->assertStringContainsString('PTRO', $res['reply_text']);
        $this->assertStringContainsString('PT Petrosea Tbk', $res['reply_text']);
        $this->assertDatabaseHas('user_watchlists', [
            'ticker' => 'PTRO',
            'user_id' => '5923895922',
        ]);
    }

    /**
     * 3. Test listing user watchlist formats cleanly.
     */
    public function test_list_watchlist_formatting(): void
    {
        $this->watchlistService->addTicker('5923895922', '5923895922', 'PTRO');
        $this->watchlistService->addTicker('5923895922', '5923895922', 'BBCA');

        $text = $this->watchlistService->formatWatchlistMessage('5923895922');
        $this->assertStringContainsString('WATCHLIST PENGGUNA AETHER (2 Emiten)', $text);
        $this->assertStringContainsString('PTRO — PT Petrosea Tbk', $text);
        $this->assertStringContainsString('BBCA — PT Bank Central Asia Tbk', $text);
    }

    /**
     * 4. Test PTRO outside ML universe is labeled MODEL_NOT_VALIDATED_FOR_INSTRUMENT.
     */
    public function test_ptro_analysis_outside_ml_universe_labeled_not_validated(): void
    {
        // Seed PTRO observation
        MarketObservation::create([
            'ticker' => 'PTRO',
            'observation_time' => now(),
            'bar_date' => Carbon::parse('2026-10-23'),
            'open_price' => 3070.0,
            'high_price' => 3140.0,
            'low_price' => 3030.0,
            'close_price' => 3090.0,
            'volume' => 27460600,
            'source' => 'Yahoo Finance',
            'is_eod_final' => true,
        ]);

        $res = $this->twoWayService->handleInboundMessage(
            $this->makeUpdate('analisis saham ptro', 'upd_ptro_eval', '202')
        );

        $this->assertEquals('success', $res['status']);
        $this->assertStringContainsString('PTRO (PT Petrosea Tbk)', $res['reply_text']);
        $this->assertStringContainsString('MODEL_NOT_VALIDATED_FOR_INSTRUMENT', $res['reply_text']);
        $this->assertStringContainsString('Model prediktif machine learning belum dikalibrasi untuk emiten PTRO', $res['reply_text']);
    }

    /**
     * 5. Test tool execution resolves correctly via AiToolExecutorService.
     */
    public function test_ai_tool_executor_service(): void
    {
        $executor = app(AiToolExecutorService::class);
        $provenance = [];

        $reply = $executor->executeTool('add_watchlist', ['ticker' => 'ICBP'], '5923895922', '5923895922', $provenance);
        $this->assertStringContainsString('WATCHLIST DIPERBARUI', $reply);
        $this->assertStringContainsString('ICBP', $reply);
        $this->assertEquals('add_watchlist', $provenance['tool_executed']);
    }

    /**
     * 6. Test duplicate Telegram update ID is handled idempotently.
     */
    public function test_duplicate_telegram_update_id_is_idempotent(): void
    {
        $upd = $this->makeUpdate('status sistem', 'upd_dupe_check', '205');
        $res1 = $this->twoWayService->handleInboundMessage($upd);
        $this->assertFalse($res1['is_duplicate']);

        // Second submission of identical update ID
        $res2 = $this->twoWayService->handleInboundMessage($upd);
        $this->assertTrue($res2['is_duplicate']);
        $this->assertEquals($res1['reply_text'], $res2['reply_text']);
        $this->assertEquals(1, TelegramConversation::where('telegram_update_id', 'upd_dupe_check')->count());
    }

    public function test_fresh_observation_without_evidence_is_not_claimed_as_completed_research(): void
    {
        MarketObservation::create([
            'ticker' => 'PTRO', 'observation_time' => now(),
            'bar_date' => app(BeiCalendarService::class)->getCurrentTradingDay(now('Asia/Jakarta')->subDay())->toDateString(),
            'open_price' => 3070, 'high_price' => 3140, 'low_price' => 3030,
            'close_price' => 3090, 'volume' => 100, 'source' => 'Yahoo Finance',
            'is_eod_final' => true, 'validation_status' => 'VALID',
        ]);
        $result = app(OnDemandResearchService::class)->handleResearchQuery('analisis PTRO', '5923895922', '5923895922', '211');
        $this->assertStringNotContainsString('HASIL RISET ON-DEMAND', $result['reply_text']);
    }

    public function test_telegram_never_publishes_conflicted_price(): void
    {
        MarketObservation::create([
            'ticker' => 'PTRO', 'observation_time' => now(), 'bar_date' => '2026-10-23',
            'open_price' => 3070, 'high_price' => 3140, 'low_price' => 3030,
            'close_price' => 3090, 'volume' => 100, 'source' => 'Yahoo Finance',
            'is_eod_final' => true, 'validation_status' => 'CONFLICTED',
        ]);
        $result = $this->twoWayService->handleInboundMessage($this->makeUpdate('analisis PTRO', 'upd_conflict', '213'));
        $this->assertStringContainsString('CONFLICTED', $result['reply_text']);
        $this->assertStringNotContainsString('Rp 3.090', $result['reply_text']);
    }

    public function test_canonical_market_read_selects_same_observation_as_telegram_and_dashboard(): void
    {
        MarketObservation::create([
            'ticker' => 'PTRO', 'bar_date' => '2026-10-23', 'observation_time' => Carbon::parse('2026-10-23 16:30:00'),
            'open_price' => 3070, 'high_price' => 3140, 'low_price' => 3030, 'close_price' => 3090,
            'volume' => 100, 'source' => 'Yahoo Finance', 'is_eod_final' => true, 'validation_status' => 'VALID',
        ]);
        MarketObservation::create([
            'ticker' => 'PTRO', 'bar_date' => '2026-10-21', 'observation_time' => Carbon::parse('2026-10-25 10:00:00'),
            'open_price' => 2970, 'high_price' => 3040, 'low_price' => 2930, 'close_price' => 2990,
            'volume' => 100, 'source' => 'Yahoo Finance', 'is_eod_final' => true, 'validation_status' => 'VALID',
        ]);
        $selected = app(CanonicalMarketReadService::class)->latest('PTRO');
        $this->assertSame('2026-10-23', $selected->bar_date->toDateString());
        $this->assertSame($selected->id, app(CanonicalMarketReadService::class)->latestByTicker()->get('PTRO')->id);
        $response = $this->get(route('dashboard.market'));
        $response->assertOk()->assertSee('Observation ID: '.$selected->id)->assertSee('Rp3.090');
        $telegram = $this->twoWayService->handleInboundMessage($this->makeUpdate('analisis PTRO untuk swing', 'upd_canonical', '499'));
        $this->assertStringContainsString('Observation ID: '.$selected->id, $telegram['reply_text']);
        $this->assertStringContainsString('Rp 3.090,00', $telegram['reply_text']);
    }

    public function test_overview_historical_reference_does_not_use_latest_market_price(): void
    {
        $evidence = EvidenceSnapshot::create([
            'snapshot_id' => 'EVD_HISTORY_PTRO', 'ticker' => 'PTRO',
            'decision_date' => '2026-10-21', 'market_regime' => 'SIDEWAYS',
            'features_snapshot' => ['reference_close' => 2700], 'data_hash' => str_repeat('a', 64),
        ]);
        ResearchRequest::create([
            'request_id' => 'REQ_HISTORY_PTRO', 'ticker' => 'PTRO', 'as_of_time' => now(),
            'status' => 'completed', 'evidence_snapshot_id' => $evidence->id,
        ]);
        MarketObservation::create([
            'ticker' => 'PTRO', 'bar_date' => '2026-10-23', 'observation_time' => now(),
            'open_price' => 3070, 'high_price' => 3140, 'low_price' => 3030, 'close_price' => 3090,
            'volume' => 100, 'source' => 'Yahoo Finance', 'is_eod_final' => true, 'validation_status' => 'VALID',
        ]);
        $response = $this->get(route('dashboard.overview'));
        $response->assertOk()->assertSee('Harga acuan analisis: Rp2.700,00')->assertSee('Harga terakhir: Rp3.090,00');
        $response->assertSee('Observation ID acuan: DATA_NOT_AVAILABLE');
        $this->assertDatabaseHas('evidence_snapshots', ['id' => $evidence->id, 'data_hash' => str_repeat('a', 64)]);
    }

    public function test_evidence_resolves_observation_only_by_unique_hash_and_bar_date(): void
    {
        $evidence = EvidenceSnapshot::create([
            'snapshot_id' => 'EVD_HASH_PTRO', 'ticker' => 'PTRO', 'decision_date' => '2026-10-23',
            'features_snapshot' => ['data_hash' => str_repeat('b', 64), 'reference_close' => 3090],
            'data_hash' => str_repeat('c', 64),
        ]);
        MarketObservation::create([
            'ticker' => 'PTRO', 'bar_date' => '2026-10-23', 'observation_time' => now(),
            'open_price' => 3070, 'high_price' => 3140, 'low_price' => 3030, 'close_price' => 3090,
            'volume' => 100, 'source' => 'Yahoo Finance', 'is_eod_final' => true,
            'validation_status' => 'VALID', 'data_hash' => str_repeat('b', 64),
        ]);
        $resolver = app(CanonicalMarketReadService::class);
        $this->assertSame(1, $resolver->observationForEvidence($evidence)?->id);
        $evidence->features_snapshot = ['data_hash' => str_repeat('d', 64), 'reference_close' => 3090];
        $this->assertNull($resolver->observationForEvidence($evidence));
    }

    public function test_market_page_quarantines_provisional_and_conflicted_price(): void
    {
        foreach (['PTRO' => ['PROVISIONAL', false, 'VALID'], 'SMRA' => ['CONFLICTED', true, 'CONFLICTED']] as $ticker => [$state, $final, $validation]) {
            MarketObservation::create([
                'ticker' => $ticker, 'bar_date' => '2026-10-23', 'observation_time' => now(),
                'open_price' => 3070, 'high_price' => 3140, 'low_price' => 3030, 'close_price' => 3090,
                'volume' => 100, 'source' => 'Yahoo Finance', 'is_eod_final' => $final, 'validation_status' => $validation,
            ]);
            $this->assertSame($state, app(CanonicalMarketReadService::class)->state(app(CanonicalMarketReadService::class)->latest($ticker)));
        }
        $this->get(route('dashboard.market'))->assertOk()->assertSee('DATA_NOT_AVAILABLE')->assertDontSee('Rp3.090');
    }

    public function test_freshness_uses_latest_bar_date_not_latest_fetch_time(): void
    {
        foreach (['2026-10-23', '2026-10-21'] as $date) {
            MarketObservation::create([
                'ticker' => 'PTRO', 'bar_date' => $date,
                'observation_time' => $date === '2026-10-21' ? Carbon::parse('2026-10-25 10:00:00') : Carbon::parse('2026-10-23 16:30:00'),
                'open_price' => 3070, 'high_price' => 3140, 'low_price' => 3030,
                'close_price' => 3090, 'volume' => 100, 'source' => 'Yahoo Finance',
                'is_eod_final' => true, 'validation_status' => 'VALID',
            ]);
        }
        $result = app(DataFreshnessPolicyService::class)->assessFreshness('PTRO', 'eod', Carbon::parse('2026-10-25 15:00:00', 'Asia/Jakarta'));
        $this->assertSame('2026-10-23', $result['observed_date']);
        $this->assertSame('AVAILABLE_FRESH', $result['status']);
    }

    public function test_provisional_eod_bar_is_not_fresh(): void
    {
        MarketObservation::create([
            'ticker' => 'PTRO', 'observation_time' => now(),
            'bar_date' => app(BeiCalendarService::class)->getCurrentTradingDay(now('Asia/Jakarta')->subDay())->toDateString(),
            'open_price' => 3070, 'high_price' => 3140, 'low_price' => 3030,
            'close_price' => 3090, 'volume' => 100, 'source' => 'Yahoo Finance',
            'is_eod_final' => false, 'validation_status' => 'VALID',
        ]);
        $status = app(DataFreshnessPolicyService::class)->assessFreshness('PTRO');
        $this->assertNotSame('AVAILABLE_FRESH', $status['status']);
    }

    public function test_model_tool_denies_unauthorized_sender(): void
    {
        $provenance = [];
        $result = app(AiToolExecutorService::class)->executeTool('add_watchlist', ['ticker' => 'PTRO'], '123456', '5923895922', $provenance);
        $this->assertStringContainsString('Akses Ditolak', $result);
        $this->assertDatabaseCount('user_watchlists', 0);
    }

    public function test_conflicted_observation_cannot_be_marked_fresh(): void
    {
        MarketObservation::create([
            'ticker' => 'PTRO', 'observation_time' => now(),
            'bar_date' => app(BeiCalendarService::class)->getCurrentTradingDay(now('Asia/Jakarta')->subDay())->toDateString(),
            'open_price' => 3070, 'high_price' => 3140, 'low_price' => 3030,
            'close_price' => 3090, 'volume' => 100, 'source' => 'Yahoo Finance',
            'is_eod_final' => true, 'validation_status' => 'CONFLICTED',
        ]);
        $status = app(DataFreshnessPolicyService::class)->assessFreshness('PTRO');
        $this->assertSame('CONFLICTED', $status['status']);
    }

    public function test_fresh_request_does_not_repeat_collection_or_queue_duplicate_reply(): void
    {
        MarketObservation::create([
            'ticker' => 'PTRO', 'observation_time' => now(),
            'bar_date' => app(BeiCalendarService::class)->getCurrentTradingDay(now('Asia/Jakarta'))->toDateString(),
            'open_price' => 3070, 'high_price' => 3140, 'low_price' => 3030,
            'close_price' => 3090, 'volume' => 100, 'source' => 'Yahoo Finance',
            'is_eod_final' => true, 'validation_status' => 'VALID',
        ]);
        $result = app(OnDemandResearchService::class)->handleResearchQuery('analisis saham PTRO', '5923895922', '5923895922', '210');
        $this->assertFalse($result['is_async']);
        $this->assertDatabaseCount('telegram_outbox', 0);
        $this->assertDatabaseCount('market_observations', 1);
    }

    public function test_foreign_sender_cannot_replay_existing_update_id(): void
    {
        $authorized = $this->makeUpdate('status sistem', 'upd_replay', '212');
        $this->twoWayService->handleInboundMessage($authorized);
        $unauthorized = $authorized;
        $unauthorized['message']['from']['id'] = 123456;
        $result = $this->twoWayService->handleInboundMessage($unauthorized);
        $this->assertSame('rejected', $result['status']);
        $this->assertArrayNotHasKey('is_duplicate', $result);
    }

    public function test_foreign_sender_cannot_use_allowlisted_chat_id(): void
    {
        $update = $this->makeUpdate('tambahkan PTRO ke watchlist', 'upd_foreign', '207');
        $update['message']['from']['id'] = 123456;

        $result = $this->twoWayService->handleInboundMessage($update);

        $this->assertSame('rejected', $result['status']);
        $this->assertDatabaseCount('user_watchlists', 0);
    }

    public function test_day_trade_never_uses_eod_as_intraday(): void
    {
        MarketObservation::create([
            'ticker' => 'PTRO', 'observation_time' => now(), 'bar_date' => now()->toDateString(),
            'open_price' => 3070, 'high_price' => 3140, 'low_price' => 3030,
            'close_price' => 3090, 'volume' => 100, 'source' => 'Yahoo Finance',
            'is_eod_final' => true,
        ]);

        $result = $this->twoWayService->handleInboundMessage($this->makeUpdate('analisis PTRO untuk day trade', 'upd_day', '208'));
        $this->assertStringContainsString('INTRADAY_DATA_UNAVAILABLE', $result['reply_text']);
        $this->assertStringNotContainsString('Estimasi Prob', $result['reply_text']);
    }

    public function test_investment_does_not_use_five_day_prediction(): void
    {
        $result = $this->twoWayService->handleInboundMessage($this->makeUpdate('bagaimana kondisi SMRA untuk investasi?', 'upd_invest', '209'));
        $this->assertStringContainsString('INVESTMENT_EVIDENCE_UNAVAILABLE', $result['reply_text']);
        $this->assertStringNotContainsString('Estimasi Prob', $result['reply_text']);
    }

    /**
     * 7. Test prompt injection via fake news text cannot execute shell/tools.
     */
    public function test_prompt_injection_safety(): void
    {
        // Seed observation
        MarketObservation::create([
            'ticker' => 'SMRA',
            'observation_time' => now(),
            'bar_date' => Carbon::parse('2026-10-23'),
            'open_price' => 256.0,
            'high_price' => 266.0,
            'low_price' => 254.0,
            'close_price' => 260.0,
            'volume' => 32107600,
            'source' => 'Yahoo Finance',
            'is_eod_final' => true,
        ]);

        $maliciousText = 'analisis emiten SMRA; rm -rf /; DROP TABLE users; curl http://evil.com';
        $res = $this->twoWayService->handleInboundMessage(
            $this->makeUpdate($maliciousText, 'upd_inject', '206')
        );

        $this->assertEquals('success', $res['status']);
        // Extracted SMRA safely without executing arbitrary command
        $this->assertStringContainsString('ANALISIS EMITEN — SMRA', $res['reply_text']);
        $this->assertEquals(0, PredictionSignal::where('signal_status', 'LIVE_BROKER_ORDER')->count());
    }

    public function test_portfolio_empty_returns_unknown_actual_composition_and_50_50_target(): void
    {
        $portfolioService = app(UserPortfolioService::class);
        $summary = $portfolioService->formatPortfolioSummary('5923895922');

        $this->assertStringContainsString('KONSULTASI ANALITIS PORTOFOLIO SAHAM BEI', $summary);
        $this->assertStringContainsString('Target 50% Trading / 50% Investasi', $summary);
        $this->assertStringContainsString('Belum diketahui (menunggu input saham Anda)', $summary);
    }

    public function test_portfolio_records_position_and_calculates_deterministic_metrics(): void
    {
        // Seed observation for current market price
        MarketObservation::create([
            'ticker' => 'BBCA',
            'bar_date' => Carbon::parse('2026-10-23'),
            'observation_time' => now(),
            'open_price' => 5000.0,
            'high_price' => 5200.0,
            'low_price' => 4950.0,
            'close_price' => 5100.0,
            'volume' => 50000000,
            'is_eod_final' => true,
        ]);

        $portfolioService = app(UserPortfolioService::class);
        $res = $portfolioService->upsertPosition('5923895922', 'BBCA', 10, 5000.0, 'INVESTMENT');
        $this->assertTrue($res['success']);

        $metrics = $portfolioService->calculatePortfolioMetrics('5923895922');
        $this->assertTrue($metrics['has_positions']);
        $this->assertEquals(1000, $metrics['positions'][0]['shares']);
        $this->assertEquals(5000000.0, $metrics['total_cost_basis']);
        $this->assertEquals(5100000.0, $metrics['total_market_value']);
        $this->assertEquals(100000.0, $metrics['total_unrealized_pnl']);
        $this->assertEquals(2.0, round($metrics['total_pnl_pct'], 2));

        $summary = $portfolioService->formatPortfolioSummary('5923895922');
        $this->assertStringContainsString('BBCA — 10 lot', $summary);
        $this->assertStringContainsString('INVESTMENT', $summary);
    }

    public function test_stock_screening_and_comparison_deterministic_output(): void
    {
        MarketObservation::create([
            'ticker' => 'SMRA',
            'bar_date' => Carbon::parse('2026-10-23'),
            'observation_time' => now(),
            'open_price' => 256.0,
            'high_price' => 266.0,
            'low_price' => 254.0,
            'close_price' => 260.0,
            'volume' => 32107600,
            'is_eod_final' => true,
        ]);

        $screeningService = app(StockScreeningService::class);
        $screen = $screeningService->formatScreeningResponse('SWING');
        $this->assertStringContainsString('SCREENING PASAR SAHAM BEI', $screen);
        $this->assertStringContainsString('SMRA', $screen);
        $this->assertStringContainsString('Tesis swing batal jika', $screen);

        $comp = $screeningService->formatComparisonResponse(['SMRA', 'BBCA']);
        $this->assertStringContainsString('KOMPARASI DATA PASAR EMITEN BEI', $comp);
        $this->assertStringContainsString('SMRA', $comp);
    }

    public function test_on_demand_research_deduplicates_active_running_requests(): void
    {
        ResearchRequest::create([
            'request_id' => 'REQ_ACTIVE_TEST',
            'chat_id' => '5923895922',
            'user_id' => '5923895922',
            'inbound_message_id' => '888',
            'ticker' => 'INDF',
            'company_name' => 'PT Indofood Sukses Makmur Tbk',
            'exchange' => 'IDX',
            'analysis_type' => 'current_condition',
            'data_type_requested' => 'eod',
            'horizon' => 'multi_day',
            'as_of_time' => now(),
            'status' => 'collecting',
        ]);

        $research = app(OnDemandResearchService::class);
        $res = $research->handleResearchQuery('analisis INDF', '5923895922', '5923895922', '889');

        $this->assertEquals('ON_DEMAND_RESEARCH_ALREADY_ACTIVE', $res['intent']);
        $this->assertEquals('REQ_ACTIVE_TEST', $res['research_request_id']);
        $this->assertStringContainsString('sedang diproses', $res['reply_text']);
    }

    public function test_telegram_routes_portfolio_and_screening_intents(): void
    {
        $updatePort = $this->makeUpdate('saya mau konsultasi portofolio', 'upd_port', '991');
        $resPort = $this->twoWayService->handleInboundMessage($updatePort);
        $this->assertStringContainsString('KONSULTASI ANALITIS PORTOFOLIO', $resPort['reply_text']);

        $updateScreen = $this->makeUpdate('cari kandidat saham untuk swing', 'upd_scr', '992');
        $resScreen = $this->twoWayService->handleInboundMessage($updateScreen);
        $this->assertStringContainsString('SCREENING PASAR SAHAM BEI', $resScreen['reply_text']);
    }
}
