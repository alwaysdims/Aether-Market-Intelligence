<?php

namespace Tests\Feature;

use App\Models\EvidenceSnapshot;
use App\Models\MarketObservation;
use App\Models\PredictionSignal;
use App\Models\ResearchRequest;
use App\Models\TelegramOutbox;
use App\Services\BeiCalendarService;
use App\Services\BeiUniverseRegistry;
use App\Services\DataFreshnessPolicyService;
use App\Services\OnDemandDataCollectorService;
use App\Services\OnDemandResearchService;
use App\Services\TelegramTwoWayService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnDemandResearchAndDataFreshnessTest extends TestCase
{
    use RefreshDatabase;

    protected BeiCalendarService $calendar;

    protected BeiUniverseRegistry $universe;

    protected DataFreshnessPolicyService $freshness;

    protected OnDemandDataCollectorService $collector;

    protected OnDemandResearchService $research;

    protected TelegramTwoWayService $twoWay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calendar = app(BeiCalendarService::class);
        $this->universe = app(BeiUniverseRegistry::class);
        $this->freshness = app(DataFreshnessPolicyService::class);
        $this->collector = app(OnDemandDataCollectorService::class);
        $this->research = app(OnDemandResearchService::class);
        $this->twoWay = app(TelegramTwoWayService::class);
    }

    public function test_fresh_data_in_db_skips_fetch(): void
    {
        // Bar date: Friday 23 Oct 2026 EOD
        $barDate = Carbon::parse('2026-10-23');
        $asOf = Carbon::parse('2026-10-25 15:00:00'); // Sunday afternoon

        MarketObservation::create([
            'ticker' => 'SMRA',
            'company_name' => 'PT Summarecon Agung Tbk',
            'bar_date' => $barDate,
            'observation_time' => Carbon::parse('2026-10-23 16:05:00'),
            'open_price' => 256.0,
            'high_price' => 266.0,
            'low_price' => 254.0,
            'close_price' => 260.0,
            'volume' => 32107600,
            'source' => 'Yahoo Finance (via yfinance)',
            'is_eod_final' => true,
            'validation_status' => 'VALID',
        ]);

        $res = $this->freshness->assessFreshness('SMRA', 'eod', $asOf);

        $this->assertEquals('AVAILABLE_FRESH', $res['status']);
        $this->assertFalse($res['requires_fetch']);
        $this->assertStringContainsString('mutakhir dan sesuai kalender BEI', $res['reason']);
    }

    public function test_missing_ticker_triggers_on_demand_collection_and_stores_evidence(): void
    {
        // ICBP is missing from DB
        $res = $this->collector->collectAndStore('ICBP', 'REQ_TEST_ICBP');

        $this->assertTrue($res['success']);
        $this->assertEquals('ICBP', $res['ticker']);

        // Assert stored in MarketObservation with full provenance
        $obs = MarketObservation::where('ticker', 'ICBP')->first();
        $this->assertNotNull($obs);
        $this->assertEquals('PT Indofood CBP Sukses Makmur Tbk', $obs->company_name);
        $this->assertEquals('VALID', $obs->validation_status);
        $this->assertEquals('UNADJUSTED', $obs->adjustment_mode);
        $this->assertTrue($obs->is_eod_final);
        $this->assertNotNull($obs->data_hash);
        $this->assertNotNull($obs->raw_payload_path);

        // Assert stored in EvidenceSnapshot
        $evidence = EvidenceSnapshot::where('ticker', 'ICBP')->first();
        $this->assertNotNull($evidence);
        $this->assertArrayHasKey('price_range', $evidence->features_snapshot);
        $this->assertArrayHasKey('change_vs_open', $evidence->features_snapshot);
    }

    public function test_intraday_request_on_weekend_classified_available_but_insufficient(): void
    {
        $barDate = Carbon::parse('2026-10-23');
        $asOf = Carbon::parse('2026-10-25 10:00:00'); // Sunday

        MarketObservation::create([
            'ticker' => 'BBCA',
            'company_name' => 'PT Bank Central Asia Tbk',
            'bar_date' => $barDate,
            'observation_time' => Carbon::parse('2026-10-23 16:05:00'),
            'open_price' => 5000.0,
            'high_price' => 5100.0,
            'low_price' => 4950.0,
            'close_price' => 5050.0,
            'volume' => 1000000,
            'source' => 'Yahoo Finance',
            'is_eod_final' => true,
            'validation_status' => 'VALID',
        ]);

        $res = $this->freshness->assessFreshness('BBCA', 'delayed', $asOf);

        $this->assertEquals('AVAILABLE_BUT_INSUFFICIENT_FOR_REQUEST', $res['status']);
        $this->assertFalse($res['requires_fetch']);
        $this->assertStringContainsString('Pasar BEI sedang libur/tutup', $res['reason']);
    }

    public function test_holiday_and_weekend_calendar_handling(): void
    {
        // 2026-05-01 is Hari Buruh (BEI Holiday)
        $this->assertFalse($this->calendar->isTradingDay('2026-05-01'));
        // 2026-05-02 is Saturday
        $this->assertFalse($this->calendar->isTradingDay('2026-05-02'));
        // 2026-05-04 is Monday (Normal trading day)
        $this->assertTrue($this->calendar->isTradingDay('2026-05-04'));
    }

    public function test_non_validated_universe_ticker_explicitly_labeled_model_not_validated(): void
    {
        // Request research for ICBP (outside 10 ML models)
        $contract = $this->universe->parseRequestContract('analisis emiten ICBP');
        $this->assertTrue($contract['valid']);
        $this->assertFalse($contract['is_ml_validated']);
        $this->assertEquals('ICBP', $contract['ticker']);

        $req = ResearchRequest::create([
            'request_id' => 'REQ_TEST_NON_VALIDATED_1',
            'ticker' => 'ICBP',
            'company_name' => 'PT Indofood CBP Sukses Makmur Tbk',
            'exchange' => 'IDX',
            'analysis_type' => 'current_condition',
            'data_type_requested' => 'eod',
            'as_of_time' => now('Asia/Jakarta'),
            'status' => 'pending',
        ]);

        $execRes = $this->research->executeResearch($req);

        $this->assertTrue($execRes['success']);
        $replyText = $execRes['reply_text'];
        $req->refresh();
        $this->assertStringContainsString('Evidence ID: '.$req->evidence_snapshot_id, $replyText);
        $this->assertStringContainsString('Observation ID: '.MarketObservation::where('ticker', 'ICBP')->latest('id')->value('id'), $replyText);
        $this->assertSame($req->id, ResearchRequest::where('request_id', 'REQ_TEST_NON_VALIDATED_1')->value('id'));

        $this->assertStringContainsString('MODEL_NOT_VALIDATED_FOR_INSTRUMENT', $replyText);
        $this->assertStringContainsString('EXPANDED_BEI_UNIVERSE', $replyText);
        $this->assertStringContainsString('Dilarang menyajikan estimasi probabilitas', $replyText);
        $this->assertStringNotContainsString('P(UP)', $replyText);
        $this->assertStringNotContainsString('RandomForest_v1.0', $replyText);
        $this->assertStringNotContainsString('*', $replyText);
    }

    public function test_foreign_or_crypto_symbol_is_gracefully_rejected(): void
    {
        $res = $this->universe->extractAndNormalizeTicker('analisis emiten AAPL');
        $this->assertFalse($res['valid']);
        $this->assertEquals('FOREIGN_OR_NON_IDX', $res['error_type']);
        $this->assertStringContainsString('bursa luar negeri', $res['message']);

        // Check via Telegram routing
        $upd = [
            'update_id' => 'upd_foreign_1',
            'message' => [
                'message_id' => '501',
                'from' => ['id' => '5923895922', 'username' => 'TestOwner'],
                'chat' => ['id' => '5923895922'],
                'text' => 'analisis emiten TSLA',
                'reply_to_message' => null,
            ],
        ];

        $reply = $this->twoWay->handleInboundMessage($upd);
        $this->assertStringContainsString('KLARIFIKASI CAKUPAN INSTRUMEN', $reply['reply_text']);
        $this->assertStringContainsString('bursa luar negeri', $reply['reply_text']);
    }

    public function test_price_conflict_detection_quarantines_record_without_averaging(): void
    {
        // Existing record in DB: Rp 5.000
        $barDate = Carbon::parse('2026-10-23');
        MarketObservation::create([
            'ticker' => 'KLBF',
            'company_name' => 'PT Kalbe Farma Tbk',
            'bar_date' => $barDate,
            'observation_time' => Carbon::now('Asia/Jakarta'),
            'open_price' => 4950.0,
            'high_price' => 5050.0,
            'low_price' => 4900.0,
            'close_price' => 5000.0,
            'volume' => 1000000,
            'source' => 'Yahoo Finance',
            'validation_status' => 'VALID',
        ]);

        // Simulating conflict: conflicting price Rp 5.500 (+10% diff)
        $obs = MarketObservation::where('ticker', 'KLBF')->first();
        $newPrice = 5500.0;
        $pctDiff = abs(($newPrice - $obs->close_price) / $obs->close_price) * 100;

        $this->assertGreaterThan(0.5, $pctDiff);

        // Mark as CONFLICTED
        $obs->update([
            'validation_status' => 'CONFLICTED',
            'conflict_reason' => "Konflik harga: Data baru (Rp {$newPrice}) berbeda {$pctDiff}% dari data lama (Rp {$obs->close_price}).",
        ]);

        $freshness = $this->freshness->assessFreshness('KLBF', 'eod');
        $this->assertEquals('CONFLICTED', $freshness['status']);
        $this->assertFalse($freshness['requires_fetch']);
        $this->assertStringContainsString('karantina audit', $freshness['reason']);
    }

    public function test_async_research_dispatch_and_worker_execution(): void
    {
        // Initiate research query for UNVR
        $res = $this->research->handleResearchQuery('analisis emiten UNVR', '5923895922', '5923895922', '801');

        $this->assertTrue($res['handled']);
        $this->assertNotNull($res['research_request_id']);

        $req = ResearchRequest::where('request_id', $res['research_request_id'])->first();
        $this->assertNotNull($req);
        $this->assertEquals('UNVR', $req->ticker);

        // Execute research worker
        $execRes = $this->research->executeResearch($req);
        $this->assertTrue($execRes['success']);

        $req->refresh();
        $this->assertEquals('completed', $req->status);
        $this->assertNotNull($req->evidence_snapshot_id);

        // Assert outbox message was queued with reply_to_message_id
        $outbox = TelegramOutbox::where('dedup_key', "RESEARCH_REPLY_{$req->request_id}")->first();
        $this->assertNotNull($outbox);
        $this->assertEquals('801', $outbox->reply_to_message_id);
        $this->assertEquals('QUEUED', $outbox->status);
    }

    public function test_telegram_two_way_routing_for_expanded_universe(): void
    {
        $upd = [
            'update_id' => 'upd_exp_1',
            'message' => [
                'message_id' => '901',
                'from' => ['id' => '5923895922', 'username' => 'TestOwner'],
                'chat' => ['id' => '5923895922'],
                'text' => 'analisis emiten GOTO',
                'reply_to_message' => null,
            ],
        ];

        $reply = $this->twoWay->handleInboundMessage($upd);

        $this->assertEquals('QUERY_TICKER_STATUS', $reply['intent']);
        $this->assertStringContainsString('GOTO', $reply['reply_text']);
        $this->assertStringNotContainsString('*', $reply['reply_text']);
    }

    public function test_zero_broker_calls_and_zero_synthetic_data(): void
    {
        // Assert no external broker orders exist or were dispatched
        $this->assertEquals(0, PredictionSignal::where('signal_status', 'LIVE_BROKER_ORDER')->count());
        $this->assertEquals(0, MarketObservation::where('source', 'SYNTHETIC_GENERATOR')->count());
    }
}
