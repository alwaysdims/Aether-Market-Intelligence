<?php

namespace Tests\Feature;

use App\Models\MarketObservation;
use App\Models\PredictionSignal;
use App\Models\TelegramOutbox;
use App\Services\BeiCalendarService;
use App\Services\CanonicalMarketReadService;
use App\Services\MarketMonitoringService;
use App\Services\TelegramFormattingService;
use App\Services\TelegramTradingPlanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramTradingPlanTest extends TestCase
{
    use RefreshDatabase;

    protected TelegramTradingPlanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $calendar = app(BeiCalendarService::class);
        $formatter = app(TelegramFormattingService::class);
        $marketRead = app(CanonicalMarketReadService::class);
        $this->service = new TelegramTradingPlanService($calendar, $formatter, $marketRead);
    }

    public function test_bei_tick_sizes_and_rounding(): void
    {
        // 1. Fraksi tick resmi BEI
        $this->assertEquals(1.0, TelegramTradingPlanService::getBeiTickSize(50.0));
        $this->assertEquals(1.0, TelegramTradingPlanService::getBeiTickSize(199.0));
        $this->assertEquals(2.0, TelegramTradingPlanService::getBeiTickSize(200.0));
        $this->assertEquals(2.0, TelegramTradingPlanService::getBeiTickSize(498.0));
        $this->assertEquals(5.0, TelegramTradingPlanService::getBeiTickSize(500.0));
        $this->assertEquals(5.0, TelegramTradingPlanService::getBeiTickSize(1995.0));
        $this->assertEquals(10.0, TelegramTradingPlanService::getBeiTickSize(2000.0));
        $this->assertEquals(10.0, TelegramTradingPlanService::getBeiTickSize(4990.0));
        $this->assertEquals(25.0, TelegramTradingPlanService::getBeiTickSize(5000.0));
        $this->assertEquals(25.0, TelegramTradingPlanService::getBeiTickSize(12500.0));

        // 2. Pembulatan tick
        $this->assertEquals(202.0, TelegramTradingPlanService::roundToBeiTick(201.0, true));
        $this->assertEquals(200.0, TelegramTradingPlanService::roundToBeiTick(201.0, false));
        $this->assertEquals(505.0, TelegramTradingPlanService::roundToBeiTick(502.0, true));
        $this->assertEquals(500.0, TelegramTradingPlanService::roundToBeiTick(502.0, false));
        $this->assertEquals(2010.0, TelegramTradingPlanService::roundToBeiTick(2005.0, true));
        $this->assertEquals(5025.0, TelegramTradingPlanService::roundToBeiTick(5010.0, true));
    }

    public function test_plan_metrics_calculation_and_cost_estimation(): void
    {
        // Open: 5000, TP1: 5300, TP2: 5750, TP3: 6200, CL: 4800
        $res = TelegramTradingPlanService::calculatePlanMetrics(5000.0, 5250.0, 4850.0, 0.0040);

        $this->assertEquals(5000.0, $res['entry_price']);
        $this->assertEquals(5300.0, $res['target_price']);
        $this->assertEquals(5300.0, $res['target_price_tp1']);
        $this->assertEquals(5750.0, $res['target_price_tp2']);
        $this->assertEquals(6200.0, $res['target_price_tp3']);
        $this->assertEquals(4800.0, $res['cut_loss_price']);
        $this->assertEquals(25.0, $res['tick_size']);

        // Gross Risk: 5000 - 4800 = 200 (4.0%)
        $this->assertEquals(200.0, $res['gross_risk_rp']);
        $this->assertEquals(4.0, $res['gross_risk_pct']);

        // Gross Reward: 5300 - 5000 = 300 (6.0%)
        $this->assertEquals(300.0, $res['gross_reward_rp']);
        $this->assertEquals(6.0, $res['gross_reward_pct']);

        // Reward / Risk = 300 / 200 = 1.5
        $this->assertEquals(1.5, $res['reward_risk_ratio']);

        // Cost estimation: 5000 * 0.0040 (20) + 2 * 25 (50) = 70 Rp
        $this->assertEquals(70.0, $res['estimated_cost_rp']);
        // Net Reward: 300 - 70 = 230 (4.6%)
        $this->assertEquals(4.6, $res['net_reward_pct']);
    }

    public function test_pre_market_plan_generation_and_rendering(): void
    {
        $nowWib = Carbon::parse('2026-11-04 08:30:00', 'Asia/Jakarta');
        $plan = $this->service->generatePreMarketPlan($nowWib);

        $this->assertEquals('TRADING PLAN — PRA-PASAR (08:30 WIB)', $plan['plan_title']);
        $this->assertArrayHasKey('candidates_scalping', $plan);
        $this->assertArrayHasKey('candidates_swing', $plan);

        $text = $this->service->renderTelegramTradingPlan($plan, $nowWib);
        $this->assertStringContainsString('TRADING PLAN — PRA-PASAR (08:30 WIB)', $text);
        $this->assertStringContainsString('Cakupan Pantau', $text);
        $this->assertStringContainsString('Harga menyentuh level tidak membuktikan order terisi (fill)', $text);
    }

    public function test_opening_observations_evaluation_logic_isolated_replay(): void
    {
        $eodBaselines = [
            'BBCA' => ['close' => 5000.0, 'high' => 5050.0, 'volume' => 50_000_000],
            'BBRI' => ['close' => 2000.0, 'high' => 2050.0, 'volume' => 30_000_000],
            'TLKM' => ['close' => 3000.0, 'high' => 3050.0, 'volume' => 20_000_000],
            'ASII' => ['close' => 4500.0, 'high' => 4550.0, 'volume' => 15_000_000],
        ];

        // 1. Uji Guard: Jika RG belum terverifikasi -> DATA_CAPABILITY_BLOCKED
        $unverifiedRes = $this->service->evaluateOpeningObservations([], $eodBaselines, ['BBCA'], false);
        $this->assertEquals('DATA_CAPABILITY_BLOCKED', $unverifiedRes['status_code']);

        // 2. Skenario Replay dengan RG Terverifikasi
        $openingAggregates = [
            // BBCA: Tetap dari pagi (retained) -> Naik +2.0% vs prev, +1.0% vs open, volume aktif
            'BBCA' => [
                'first_price' => 5050.0,
                'last_price' => 5100.0,
                'high_price' => 5125.0,
                'low_price' => 5050.0,
                'volume_lots' => 5000.0,
            ],
            // BBRI: Kandidat Baru (new setup) -> Naik +3.0% vs prev, +1.5% vs open, volume aktif
            'BBRI' => [
                'first_price' => 2030.0,
                'last_price' => 2060.0,
                'high_price' => 2070.0,
                'low_price' => 2020.0,
                'volume_lots' => 10000.0,
            ],
            // TLKM: Batal dari pagi (melemah / tidak lolos filter)
            'TLKM' => [
                'first_price' => 3000.0,
                'last_price' => 2980.0,
                'high_price' => 3010.0,
                'low_price' => 2970.0,
                'volume_lots' => 1500.0,
            ],
            // ASII: Tidak ada data di stream (unobserved)
        ];

        $morningWatchlist = ['BBCA', 'TLKM'];

        $res = $this->service->evaluateOpeningObservations($openingAggregates, $eodBaselines, $morningWatchlist, true);

        $this->assertEquals('SETUP_FOUND', $res['status_code']);
        $this->assertCount(2, $res['candidates']);

        // Cek ranking: BBRI (+3.0%) harus di atas BBCA (+2.0%)
        $this->assertEquals('BBRI', $res['candidates'][0]['ticker']);
        $this->assertEquals('NEW_INTRADAY_SETUP', $res['candidates'][0]['status_watchlist']);

        $this->assertEquals('BBCA', $res['candidates'][1]['ticker']);
        $this->assertEquals('RETAINED_FROM_MORNING', $res['candidates'][1]['status_watchlist']);

        // Cek pembatalan watchlist pagi
        $this->assertContains('TLKM', $res['cancelled_morning_watchlist']);

        // Cek coverage tracking
        $this->assertEquals(4, $res['coverage']['total_baseline']);
        $this->assertEquals(3, $res['coverage']['total_observed']);
        $this->assertEquals(1, $res['coverage']['unobserved_count']); // ASII unobserved
    }

    public function test_opening_screening_and_bsjp_plan_generation(): void
    {
        $nowWib = Carbon::parse('2026-11-04 09:15:00', 'Asia/Jakarta');
        $plan0915 = $this->service->generateOpeningScreeningPlan($nowWib);
        $text0915 = $this->service->renderTelegramTradingPlan($plan0915, $nowWib);

        $this->assertStringContainsString('TRADING PLAN — SCREENING INTRADAY PEMBUKAAN (09:15 WIB)', $text0915);
        $this->assertStringContainsString('DATA_CAPABILITY_BLOCKED', $text0915);

        // BSJP
        $nowWib1515 = Carbon::parse('2026-11-04 15:15:00', 'Asia/Jakarta');
        $planBsjp = $this->service->generateBsjpPlan($nowWib1515);
        $textBsjp = $this->service->renderTelegramTradingPlan($planBsjp, $nowWib1515);

        $this->assertStringContainsString('STATUS CAPABILITY BSJP (15:15 WIB)', $textBsjp);
        $this->assertStringContainsString('DATA_CAPABILITY_BLOCKED', $textBsjp);
        $this->assertStringContainsString('BSJP ditahan: observasi intraday RG tervalidasi dan fresh belum tersedia', $textBsjp);
    }

    public function test_outbox_publish_and_rejection_of_stale_alert(): void
    {
        // 1. Publish tepat waktu (selisih < 30 menit)
        $nowWib = Carbon::parse('2026-11-04 08:35:00', 'Asia/Jakarta');
        $res = $this->service->publishPlanToOutbox('08:30', 'TEST_PLAN_TEXT', $nowWib, false, 30);

        $this->assertEquals('ENQUEUED', $res['status']);
        $this->assertEquals('TRADING_PLAN_20261104_0830', $res['dedup_key']);

        $outbox = TelegramOutbox::find($res['outbox_id']);
        $this->assertNotNull($outbox);
        $this->assertEquals('2026-11-04 01:30:00', $outbox->due_at->format('Y-m-d H:i:s')); // 08:30 WIB = 01:30 UTC

        // 2. Uji Penolakan Alert Basi (misal jam 10:00 WIB untuk slot 08:30 WIB, selisih 90 menit)
        $lateWib = Carbon::parse('2026-11-04 10:00:00', 'Asia/Jakarta');
        $resStale = $this->service->publishPlanToOutbox('08:30', 'STALE_PLAN_TEXT', $lateWib, false, 30);

        $this->assertEquals('EXPIRED_STALE_ALERT', $resStale['status']);
        $this->assertEquals(90, $resStale['delay_minutes']);
    }

    public function test_record_trading_plan_signals_creates_ledger_and_evidence(): void
    {
        $nowWib = Carbon::parse('2026-11-04 08:30:00', 'Asia/Jakarta');
        $planData = [
            'plan_title' => 'TRADING PLAN TEST',
            'as_of_session' => '2026-11-03',
            'coverage_desc' => 'FULL_EOD',
            'board_scope' => 'RG',
            'candidates_scalping' => [
                [
                    'ticker' => 'BBCA',
                    'type' => 'SCALPING',
                    'reason' => 'Breakout momentum',
                    'trigger_condition' => 'Breakout Rp 5.000',
                    'entry_zone' => 'Rp 4.950 - Rp 5.000',
                    'metrics' => [
                        'entry_price' => 5000.0,
                        'target_price' => 5125.0,
                        'cut_loss_price' => 4875.0,
                        'gross_risk_pct' => 2.5,
                        'gross_reward_pct' => 2.5,
                        'reward_risk_ratio' => 1.0,
                        'estimated_cost_rp' => 70.0,
                        'net_reward_pct' => 1.1,
                    ],
                    'horizon' => '1 hari bursa',
                    'valid_until' => '15:50 WIB',
                    'invalidation' => 'Breakdown Rp 4.875',
                    'risk_factors' => 'False breakout',
                ],
            ],
            'candidates_swing' => [],
            'candidates_bsjp' => [],
        ];

        // 1. Record signals into ledger
        $signals = $this->service->recordTradingPlanSignals($planData, $nowWib, '08:30', false);

        $this->assertCount(1, $signals);
        $sig = $signals[0];

        $this->assertEquals('PLAN_BBCA_20261104_0830_SCALPING', $sig->prediction_id);
        $this->assertEquals('BBCA', $sig->ticker);
        $this->assertEquals('PUBLISHED_CONDITIONAL', $sig->signal_status);
        $this->assertEquals('WAITING_FOR_TRIGGER', $sig->monitoring_status);
        $this->assertEquals('PENDING', $sig->evaluation_status);
        $this->assertEquals(5000.0, (float) $sig->reference_price);
        $this->assertEquals(5125.0, (float) $sig->target_price);
        $this->assertEquals(4875.0, (float) $sig->invalidation_price);
        $this->assertNotNull($sig->evidence_snapshot_id);

        // 2. Publish to Outbox and verify QUEUED transition
        $res = $this->service->publishPlanToOutbox('08:30', 'REPORT_TEXT', $nowWib, false, 30, $planData);
        $this->assertEquals('ENQUEUED', $res['status']);

        $sig->refresh();
        $this->assertEquals('QUEUED', $sig->metadata['delivery_status']);
        $this->assertEquals($res['outbox_id'], $sig->metadata['outbox_id']);
    }

    public function test_state_machine_monitoring_waiting_for_trigger_and_outcomes(): void
    {
        $nowWib = Carbon::parse('2026-11-04 08:30:00', 'Asia/Jakarta');
        $planData = [
            'as_of_session' => '2026-11-03',
            'candidates_scalping' => [
                [
                    'ticker' => 'TLKM',
                    'type' => 'SCALPING',
                    'reason' => 'Momentum',
                    'trigger_condition' => 'Breakout Rp 2.000',
                    'entry_zone' => 'Rp 2.000',
                    'metrics' => [
                        'entry_price' => 2000.0,
                        'target_price' => 2080.0,
                        'cut_loss_price' => 1940.0,
                    ],
                    'invalidation' => 'Breakdown Rp 1.940',
                ],
            ],
            'candidates_swing' => [],
            'candidates_bsjp' => [],
        ];

        $signals = $this->service->recordTradingPlanSignals($planData, $nowWib, '08:30', false);
        $sig = $signals[0];
        $sig->update(['metadata' => array_merge($sig->metadata, ['monitoring_cohort' => 'SHADOW_TEST'])]);
        $this->assertEquals('WAITING_FOR_TRIGGER', $sig->monitoring_status);

        $monitoring = app(MarketMonitoringService::class);

        // Kasus A: Invalidation tersentuh sebelum pemicu aktif -> Skenario Batal (INVALIDATED)
        MarketObservation::create([
            'ticker' => 'TLKM',
            'bar_date' => '2026-11-04',
            'observation_time' => Carbon::parse('2026-11-04 09:30:00', 'Asia/Jakarta'),
            'open_price' => 1970,
            'high_price' => 1980, // Belum menyentuh 2000
            'low_price' => 1930,  // Menyentuh di bawah invalidation 1940
            'close_price' => 1950,
            'volume' => 1000000,
            'source' => 'IDX Edge PRO',
            'board_scope' => 'RG',
            'is_eod_final' => true,
            'validation_status' => 'VALID',
        ]);

        $monitoring->runMonitoringCycle(Carbon::parse('2026-11-04 10:00:00', 'Asia/Jakarta'));

        $sig->refresh();
        $this->assertEquals('INVALIDATED', $sig->monitoring_status);
        $this->assertEquals('EVALUATED_INCORRECT', $sig->evaluation_status);
    }

    public function test_audit_recap_and_reconciliation_of_sent_plans(): void
    {
        $nowWib = Carbon::parse('2026-11-04 08:30:00', 'Asia/Jakarta');
        $planData = [
            'as_of_session' => '2026-11-03',
            'candidates_scalping' => [
                [
                    'ticker' => 'ASII',
                    'type' => 'SCALPING',
                    'reason' => 'Test',
                    'trigger_condition' => 'Trigger',
                    'entry_zone' => 'Rp 5.000',
                    'metrics' => [
                        'entry_price' => 5000.0,
                        'target_price' => 5200.0,
                        'cut_loss_price' => 4850.0,
                    ],
                ],
            ],
            'candidates_swing' => [],
            'candidates_bsjp' => [],
        ];

        $this->service->publishPlanToOutbox('08:30', 'TEXT', $nowWib, false, 30, $planData);

        // Simulasi outbox terkirim dengan message ID Telegram
        $outbox = TelegramOutbox::where('dedup_key', 'TRADING_PLAN_20261104_0830')->first();
        $outbox->update([
            'status' => 'SENT',
            'sent_at' => now(),
            'telegram_message_id' => '998877',
        ]);

        // Rekonsiliasi outbox terkirim
        $recon = $this->service->reconcileSentTradingPlans();
        $this->assertGreaterThanOrEqual(1, $recon['reconciled_signals']);

        $sig = PredictionSignal::where('prediction_id', 'PLAN_ASII_20261104_0830_SCALPING')->first();
        $this->assertEquals('SENT', $sig->metadata['delivery_status']);
        $this->assertEquals('998877', $sig->telegram_message_id);

        // Uji Audit Recap
        $recap = TelegramTradingPlanService::getAuditRecap('all');
        $this->assertGreaterThanOrEqual(1, $recap['total_registered']);
        $this->assertGreaterThanOrEqual(1, $recap['total_delivered']);
        $this->assertArrayHasKey('by_strategy', $recap);
        $this->assertArrayHasKey('simulation_disclaimer', $recap);
    }

    public function test_delivery_status_sent_does_not_overwrite_monitoring_status(): void
    {
        $nowWib = Carbon::parse('2026-11-04 08:30:00', 'Asia/Jakarta');
        $planData = [
            'as_of_session' => '2026-11-03',
            'candidates_scalping' => [
                [
                    'ticker' => 'BBRI',
                    'type' => 'SCALPING',
                    'reason' => 'Test Delivery Separation',
                    'trigger_condition' => 'Trigger',
                    'entry_zone' => 'Rp 4.500',
                    'metrics' => [
                        'entry_price' => 4500.0,
                        'target_price' => 4600.0,
                        'cut_loss_price' => 4400.0,
                    ],
                ],
            ],
            'candidates_swing' => [],
            'candidates_bsjp' => [],
        ];

        $res = $this->service->publishPlanToOutbox('08:30', 'TEXT', $nowWib, false, 30, $planData);
        $sig = PredictionSignal::where('prediction_id', 'PLAN_BBRI_20261104_0830_SCALPING')->first();
        $this->assertEquals('WAITING_FOR_TRIGGER', $sig->monitoring_status);

        // Simulasi delivery outbox selesai
        $outbox = TelegramOutbox::find($res['outbox_id']);
        $outbox->update([
            'status' => 'SENT',
            'sent_at' => now(),
            'telegram_message_id' => '777888',
        ]);

        $this->service->reconcileSentTradingPlans();
        $sig->refresh();

        // delivery_status harus SENT, tapi monitoring_status TIDAK BOLEH berubah menjadi SENT
        $this->assertEquals('SENT', $sig->metadata['delivery_status']);
        $this->assertEquals('WAITING_FOR_TRIGGER', $sig->monitoring_status);
        $this->assertNotEquals('SENT', $sig->monitoring_status);
    }

    public function test_trigger_and_target_on_same_bar_without_ticks_is_ambiguous(): void
    {
        $nowWib = Carbon::parse('2026-11-04 08:30:00', 'Asia/Jakarta');
        $planData = [
            'as_of_session' => '2026-11-03',
            'candidates_scalping' => [
                [
                    'ticker' => 'MEDC',
                    'type' => 'SCALPING',
                    'reason' => 'Ambiguous test',
                    'trigger_condition' => 'Trigger',
                    'entry_zone' => 'Rp 1.200',
                    'metrics' => [
                        'entry_price' => 1200.0,
                        'target_price' => 1250.0,
                        'cut_loss_price' => 1160.0,
                    ],
                ],
            ],
            'candidates_swing' => [],
            'candidates_bsjp' => [],
        ];

        $signals = $this->service->recordTradingPlanSignals($planData, $nowWib, '08:30', false);
        $sig = $signals[0];
        $sig->update(['metadata' => array_merge($sig->metadata, ['monitoring_cohort' => 'SHADOW_TEST'])]);

        // Bar EOD yang menyentuh pemicu DAN target sekaligus
        MarketObservation::create([
            'ticker' => 'MEDC',
            'bar_date' => '2026-11-04',
            'observation_time' => Carbon::parse('2026-11-04 15:50:00', 'Asia/Jakarta'),
            'open_price' => 1180,
            'high_price' => 1260, // >= 1200 dan >= 1250!
            'low_price' => 1175,
            'close_price' => 1240,
            'volume' => 5000000,
            'source' => 'IDX Edge PRO',
            'board_scope' => 'RG',
            'is_eod_final' => true,
            'validation_status' => 'VALID',
        ]);

        $monitoring = app(MarketMonitoringService::class);
        $monitoring->runMonitoringCycle(Carbon::parse('2026-11-04 16:05:00', 'Asia/Jakarta'));

        $sig->refresh();
        // Tidak boleh mengasumsikan target terjadi setelah trigger tanpa bukti -> wajib AMBIGUOUS
        $this->assertEquals('AMBIGUOUS', $sig->monitoring_status);
    }

    public function test_horizon_freeze_and_bsjp_next_open_evaluation(): void
    {
        $nowWib = Carbon::parse('2026-11-04 15:15:00', 'Asia/Jakarta');
        $planData = [
            'as_of_session' => '2026-11-04',
            'candidates_scalping' => [],
            'candidates_swing' => [],
            'candidates_bsjp' => [
                [
                    'ticker' => 'ICBP',
                    'type' => 'BSJP',
                    'reason' => 'Closing spike',
                    'trigger_condition' => 'Closing near-high',
                    'entry_zone' => 'Rp 11.500',
                    'metrics' => [
                        'entry_price' => 11500.0,
                        'target_price' => 11800.0,
                        'cut_loss_price' => 11250.0,
                    ],
                ],
            ],
        ];

        $signals = $this->service->recordTradingPlanSignals($planData, $nowWib, '15:15', false);
        $sig = $signals[0];
        $sig->update(['metadata' => array_merge($sig->metadata, ['monitoring_cohort' => 'SHADOW_TEST'])]);

        // Verifikasi pembekuan horizon per kontrak
        $this->assertEquals('OVERNIGHT_NEXT_OPEN', $sig->metadata['horizon_type']);
        $this->assertEquals('PRE_DISPATCH_FROZEN_SNAPSHOT', $sig->metadata['contract_provenance']);

        // Simulasi bar bursa hari berikutnya dengan open melampaui target
        MarketObservation::create([
            'ticker' => 'ICBP',
            'bar_date' => '2026-11-05',
            'observation_time' => Carbon::parse('2026-11-05 09:05:00', 'Asia/Jakarta'),
            'open_price' => 11850, // Open di atas target 11800
            'high_price' => 11900,
            'low_price' => 11800,
            'close_price' => 11850,
            'volume' => 1000000,
            'source' => 'IDX Edge PRO',
            'board_scope' => 'RG',
            'is_eod_final' => true,
            'validation_status' => 'VALID',
        ]);

        $monitoring = app(MarketMonitoringService::class);
        $monitoring->runMonitoringCycle(Carbon::parse('2026-11-05 09:30:00', 'Asia/Jakarta'));

        $sig->refresh();
        $this->assertEquals('TARGET_HIT', $sig->monitoring_status);
        $this->assertEquals('EVALUATED_CORRECT', $sig->evaluation_status);
    }

    public function test_same_day_mid_session_delivery_with_eod_bar_is_ambiguous(): void
    {
        $nowWib = Carbon::parse('2026-11-04 09:15:00', 'Asia/Jakarta');
        $planData = [
            'as_of_session' => '2026-11-04',
            'candidates_scalping' => [
                [
                    'ticker' => 'ADRO',
                    'type' => 'SCALPING',
                    'reason' => 'Mid-session momentum',
                    'trigger_condition' => 'Breakout Rp 3.800',
                    'entry_zone' => 'Rp 3.800',
                    'metrics' => [
                        'entry_price' => 3800.0,
                        'target_price' => 3920.0,
                        'cut_loss_price' => 3700.0,
                    ],
                ],
            ],
            'candidates_swing' => [],
            'candidates_bsjp' => [],
        ];

        $res = $this->service->publishPlanToOutbox('09:15', 'TEXT', $nowWib, false, 30, $planData);
        $sig = PredictionSignal::where('prediction_id', 'PLAN_ADRO_20261104_0915_SCALPING')->first();

        // Simulasi delivery selesai pada 09:16 WIB (di tengah sesi bursa yang buka 09:00 WIB)
        $outbox = TelegramOutbox::find($res['outbox_id']);
        $outbox->update([
            'status' => 'SENT',
            'sent_at' => Carbon::parse('2026-11-04 09:16:00', 'Asia/Jakarta'),
            'telegram_message_id' => '555666',
        ]);

        $this->service->reconcileSentTradingPlans();
        $sig->refresh();
        $sig->update(['metadata' => array_merge($sig->metadata, ['monitoring_cohort' => 'SHADOW_TEST'])]);
        $this->assertEquals('SENT', $sig->metadata['delivery_status']);

        // Bar EOD tanggal yang sama (09:00 s.d. 15:50 WIB) dengan High >= 3800
        MarketObservation::create([
            'ticker' => 'ADRO',
            'bar_date' => '2026-11-04',
            'observation_time' => Carbon::parse('2026-11-04 15:50:00', 'Asia/Jakarta'),
            'open_price' => 3780,
            'high_price' => 3850, // >= 3800 pemicu entry
            'low_price' => 3750,
            'close_price' => 3820,
            'volume' => 15000000,
            'source' => 'IDX Edge PRO',
            'board_scope' => 'RG',
            'is_eod_final' => true,
            'validation_status' => 'VALID',
        ]);

        $monitoring = app(MarketMonitoringService::class);
        $monitoring->runMonitoringCycle(Carbon::parse('2026-11-04 16:05:00', 'Asia/Jakarta'));

        $sig->refresh();
        // Bar EOD mencakup transaksi sebelum delivery 09:16 WIB; urutan tidak dapat ditentukan tanpa data tick intraday -> wajib AMBIGUOUS
        $this->assertEquals('AMBIGUOUS', $sig->monitoring_status);
        $this->assertEquals('UNVERIFIABLE_FROM_EOD_ALONE', $sig->metadata['intraday_sequence_proven']);
    }
}
