<?php

namespace Tests\Feature;

use App\Models\TelegramOutbox;
use App\Services\BeiCalendarService;
use App\Services\MiddaySessionReportService;
use App\Services\TelegramFormattingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MiddaySessionReportTest extends TestCase
{
    use RefreshDatabase;

    protected MiddaySessionReportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $calendar = app(BeiCalendarService::class);
        $formatter = app(TelegramFormattingService::class);
        $this->service = new MiddaySessionReportService($calendar, $formatter);
    }

    public function test_operational_midday_report_unavailable_when_rg_board_unseparated(): void
    {
        // 1. Simulasikan state WebSocket dengan board MIXED_BOARD_UNSEPARABLE
        $tempStatePath = tempnam(sys_get_temp_dir(), 'state_').'.json';
        file_put_contents($tempStatePath, json_encode([
            'status' => 'CONNECTED',
            'connected_at' => '2026-11-04 13:44:06 WIB',
            'last_trade_at' => '2026-11-04 16:20:48 WIB',
            'total_trades' => 378925,
            'reconnect_count' => 0,
            'board_scope' => 'MIXED_BOARD_UNSEPARABLE',
            'session_coverage' => [
                'window_status' => 'PARTIAL_WINDOW',
                'observation_start_at' => '2026-11-04 13:44:06',
                'observation_end_at' => '2026-11-04 16:20:48',
                'last_event_time' => null,
                'reconnect_count' => 0,
                'trades_count' => 378925,
                'board_scope' => 'MIXED_BOARD_UNSEPARABLE',
                'board_rg_guaranteed' => false,
                'has_official_trade_id' => false,
                'has_event_timestamp' => false,
            ],
            'session_aggregates' => [],
        ]));

        $coverage = $this->service->evaluateLiveSession1Coverage($tempStatePath);
        @unlink($tempStatePath);

        // Verifikasi evaluasi integritas: RG TIDAK SIAP
        $this->assertFalse($coverage['is_rg_ready']);
        $this->assertEquals('MIXED_BOARD_UNSEPARABLE', $coverage['board_scope']);
        $this->assertFalse($coverage['board_rg_guaranteed']);
        $this->assertNotEmpty($coverage['blockers']);
        $this->assertNotEmpty($coverage['provider_inquiries']);

        // 2. Susun Laporan
        $sessionDate = Carbon::parse('2026-11-04 12:15:00', 'Asia/Jakarta');
        $prevDate = Carbon::parse('2026-11-03 16:00:00', 'Asia/Jakarta');
        $prevObservations = collect([
            (object) ['ticker' => 'BBCA', 'close_price' => 4840, 'board_scope' => 'RG'],
            (object) ['ticker' => 'BBRI', 'close_price' => 2660, 'board_scope' => 'RG'],
        ]);

        $report = $this->service->buildSession1UnavailableReport(
            coverage: $coverage,
            sessionDate: $sessionDate,
            prevTradingDay: $prevDate,
            prevEodObservations: $prevObservations
        );

        // Verifikasi teks laporan
        $this->assertStringContainsString('⚠️ LAPORAN SESI 1 BELUM TERSEDIA (12:15 WIB)', $report);
        $this->assertStringContainsString('DATA_NOT_VALIDATED (Belum Memenuhi Syarat RG)', $report);
        $this->assertStringContainsString('BATASAN & PENYEBAB DATA_CAPABILITY_BLOCKED', $report);
        $this->assertStringContainsString('ACUAN PEMBANDING EOD SESI SEBELUMNYA:', $report);
        $this->assertStringContainsString('BBCA : Rp 4.840 (EOD 03 Nov 2026, Board: RG)', $report);
    }

    public function test_isolated_replay_validated_rg_trades_calculates_metrics_and_detects_signals(): void
    {
        // Transaksi replay dengan papan RG pasti
        $replayTrades = [
            ['ticker' => 'BBCA', 'price' => 5000.0, 'lot' => 30000.0, 'board' => 'RG'],
            ['ticker' => 'BBCA', 'price' => 5000.0, 'lot' => 30000.0, 'board' => 'RG'],
            ['ticker' => 'DEWA', 'price' => 110.0, 'lot' => 5000.0, 'board' => 'NG'], // Harusnya terfilter (Non-RG)
        ];

        $prevEodBars = [
            'BBCA' => ['ticker' => 'BBCA', 'close_price' => 4840.0, 'volume' => 100000.0],
            'DEWA' => ['ticker' => 'DEWA', 'close_price' => 100.0, 'volume' => 50000.0],
        ];

        $res = $this->service->aggregateValidatedTrades($replayTrades, $prevEodBars);

        $this->assertTrue($res['has_signals']);
        $this->assertEquals('KANDIDAT TERIDENTIFIKASI', $res['signal_status']);
        $this->assertArrayHasKey('BBCA', $res['aggregates']);
        $this->assertArrayNotHasKey('DEWA', $res['aggregates']); // Papan NG wajib tereliminasi

        $bbca = $res['aggregates']['BBCA'];
        $this->assertEquals(60000.0, $bbca['volume_lots']);
        $this->assertEquals(30000000000.0, $bbca['turnover_idr']); // 5000 * 60000 * 100 = 30M
        $this->assertEquals(3.31, $bbca['change_pct']); // (5000 - 4840) / 4840 * 100 = 3.305... -> 3.31%
        $this->assertEquals(0.6, $bbca['volume_vs_prev_eod_ratio']);
        $this->assertFalse($bbca['same_time_rvol_available']);

        // Verifikasi kandidat sinyal
        $candidate = $res['candidates'][0];
        $this->assertEquals('MOMENTUM_RG_BREAKOUT_V1', $candidate['strategy']);
        $this->assertEquals('BBCA', $candidate['ticker']);
        $this->assertEquals('12:00:00 WIB', $candidate['decision_time']);
        $this->assertEquals('12:00:00 WIB', $candidate['evidence_cutoff']);
        $this->assertStringContainsString('Penguatan +3.31%', $candidate['trigger_condition']);
        $this->assertStringContainsString('Penurunan harga di bawah harga pembukaan', $candidate['invalidation_condition']);
    }

    public function test_isolated_replay_when_no_candidate_meets_criteria_emits_belum_ada_sinyal(): void
    {
        // Transaksi RG kecil di bawah threshold kenaikan (+0.41%)
        $replayTrades = [
            ['ticker' => 'BMRI', 'price' => 3620.0, 'lot' => 1000.0, 'board' => 'RG'],
        ];

        $prevEodBars = [
            'BMRI' => ['ticker' => 'BMRI', 'close_price' => 3600.0, 'volume' => 50000.0],
        ];

        $res = $this->service->aggregateValidatedTrades($replayTrades, $prevEodBars);

        $this->assertFalse($res['has_signals']);
        $this->assertEquals('BELUM ADA SINYAL', $res['signal_status']);
        $this->assertEmpty($res['candidates']);
    }

    public function test_outbox_enqueue_with_due_at_and_deduplication(): void
    {
        $nowWib = Carbon::parse('2026-11-04 12:15:00', 'Asia/Jakarta');
        $reportText = '⚠️ LAPORAN SESI 1 BELUM TERSEDIA';

        // Enqueue pertama
        $res1 = $this->service->publishMiddayReport($reportText, $nowWib, '12:15', false);
        $this->assertEquals('ENQUEUED', $res1['status']);
        $this->assertEquals('MARKET_UPDATE_20261104_1215', $res1['dedup_key']);

        $outbox = TelegramOutbox::find($res1['outbox_id']);
        $this->assertNotNull($outbox);
        $this->assertEquals('MARKET_UPDATE_20261104_1215', $outbox->dedup_key);
        // Due at 12:15 WIB = 05:15 UTC
        $this->assertEquals('2026-11-04 05:15:00', $outbox->due_at->format('Y-m-d H:i:s'));

        // Tandai outbox telah terkirim
        $outbox->status = 'SENT';
        $outbox->telegram_message_id = '999888';
        $outbox->save();

        // Enqueue kedua dengan slot sama wajib ditolak idempoten
        $res2 = $this->service->publishMiddayReport($reportText, $nowWib, '12:15', false);
        $this->assertEquals('ALREADY_SENT', $res2['status']);
        $this->assertEquals('999888', $res2['telegram_message_id']);
    }
}
