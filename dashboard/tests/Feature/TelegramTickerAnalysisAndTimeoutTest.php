<?php

namespace Tests\Feature;

use App\Models\AnalysisRun;
use App\Models\MarketAnalysis;
use App\Models\MarketObservation;
use App\Models\NewsItem;
use App\Models\PredictionSignal;
use App\Models\TelegramConversation;
use App\Services\TelegramTwoWayService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramTickerAnalysisAndTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected TelegramTwoWayService $twoWayService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoWayService = app(TelegramTwoWayService::class);

        // Seed SMRA & BBCA reference data in isolated testing SQLite DB
        $this->seedTestData();
    }

    protected function seedTestData(): void
    {
        // 1. SMRA data
        MarketObservation::create([
            'ticker' => 'SMRA',
            'bar_date' => '2026-10-23',
            'observation_time' => Carbon::parse('2026-10-23 16:00:00', 'Asia/Jakarta'),
            'open_price' => 256.0,
            'high_price' => 266.0,
            'low_price' => 254.0,
            'close_price' => 260.0,
            'volume' => 32107600,
            'source' => 'Yahoo Finance v8 / yfinance',
            'is_eod_final' => true,
            'delay_minutes' => 15,
            'data_hash' => 'hash_smra_test',
        ]);

        PredictionSignal::create([
            'prediction_id' => 'SHADOW_aebf1bb0101398b8',
            'ticker' => 'SMRA',
            'decision_date' => '2026-10-16',
            'decision_timestamp' => Carbon::parse('2026-10-16 16:00:00', 'Asia/Jakarta'),
            'published_at' => Carbon::parse('2026-10-16 16:05:00', 'Asia/Jakarta'),
            'model_name' => 'RandomForest',
            'model_version' => 'RandomForest_v1.0',
            'feature_schema_hash' => 'hash_schema_smra',
            'reference_price' => 230.0,
            'reference_price_date' => '2026-10-16',
            'probability_up' => 0.4938,
            'probability_down' => 0.5062,
            'max_probability' => 0.5062,
            'confidence_score' => 0.0124,
            'signal_status' => 'NO_SIGNAL_ABSTAIN',
            'direction' => 'ABSTAIN',
            'reason_for_abstain' => 'CONFIDENCE_BELOW_THRESHOLD (Confidence: 0.01 < 0.40)',
            'predicted_horizon_days' => 5,
            'evaluation_due_date' => '2026-10-23',
            'expires_at' => Carbon::parse('2026-10-30 16:05:00', 'Asia/Jakarta'),
            'monitoring_status' => 'NOT_MONITORED',
            'evaluation_status' => 'EVALUATED_NEUTRAL',
        ]);

        // 2. BBCA data with news
        MarketObservation::create([
            'ticker' => 'BBCA',
            'bar_date' => '2026-10-23',
            'observation_time' => Carbon::parse('2026-10-23 16:00:00', 'Asia/Jakarta'),
            'open_price' => 5175.0,
            'high_price' => 5225.0,
            'low_price' => 5000.0,
            'close_price' => 5025.0,
            'volume' => 495623900,
            'source' => 'Yahoo Finance v8 / yfinance',
            'is_eod_final' => true,
            'delay_minutes' => 15,
            'data_hash' => 'hash_bbca_test',
        ]);

        PredictionSignal::create([
            'prediction_id' => 'SHADOW_14dd316ac282ce12',
            'ticker' => 'BBCA',
            'decision_date' => '2026-10-16',
            'decision_timestamp' => Carbon::parse('2026-10-16 16:00:00', 'Asia/Jakarta'),
            'published_at' => Carbon::parse('2026-10-16 16:05:00', 'Asia/Jakarta'),
            'model_name' => 'RandomForest',
            'model_version' => 'RandomForest_v1.0',
            'feature_schema_hash' => 'hash_schema_bbca',
            'reference_price' => 5100.0,
            'reference_price_date' => '2026-10-16',
            'probability_up' => 0.6505,
            'probability_down' => 0.3495,
            'max_probability' => 0.6505,
            'confidence_score' => 0.3010,
            'signal_status' => 'NO_SIGNAL_ABSTAIN',
            'direction' => 'ABSTAIN',
            'reason_for_abstain' => 'CONFIDENCE_BELOW_THRESHOLD (Confidence: 0.30 < 0.40)',
            'predicted_horizon_days' => 5,
            'evaluation_due_date' => '2026-10-23',
            'expires_at' => Carbon::parse('2026-10-30 16:05:00', 'Asia/Jakarta'),
            'monitoring_status' => 'NOT_MONITORED',
            'evaluation_status' => 'EVALUATED_NEUTRAL',
        ]);

        NewsItem::create([
            'title' => 'Laba Bersih Konsolidasian BBCA Tumbuh Stabil di Kuartal III',
            'url' => 'https://example.com/bbca-q3',
            'source_name' => 'LKBN ANTARA',
            'published_at' => Carbon::parse('2026-10-22 10:00:00', 'Asia/Jakarta'),
            'fetched_at' => Carbon::parse('2026-10-22 10:05:00', 'Asia/Jakarta'),
            'summary' => 'PT Bank Central Asia Tbk mencatatkan pertumbuhan kredit yang solid didorong oleh segmen korporasi.',
            'related_tickers' => ['BBCA'],
        ]);
    }

    protected function makeUpdate(string $text, string $updateId = 'test_upd_1', string $msgId = '100'): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $msgId,
                'from' => ['id' => '5923895922', 'username' => 'TestOwner'],
                'chat' => ['id' => '5923895922'],
                'text' => $text,
                'reply_to_message' => null,
            ],
        ];
    }

    public function test_analisis_smra_various_natural_wordings(): void
    {
        $variations = [
            'analisis emiten smra',
            'analisis SMRA',
            'SMRA bagaimana?',
            'kondisi saham SMRA',
            'SMRA terbaru bagaimana?',
        ];

        foreach ($variations as $idx => $v) {
            $res = $this->twoWayService->handleInboundMessage($this->makeUpdate($v, "smra_test_{$idx}", (string) (100 + $idx)));
            $this->assertEquals('QUERY_TICKER_STATUS', $res['intent'], "Intent mismatch for '{$v}'");
            $this->assertStringContainsString('ANALISIS EMITEN — SMRA', $res['reply_text']);
            $this->assertStringContainsString('Rp 260,00', $res['reply_text']);
            $this->assertStringContainsString('Rentang Harga High-Low', $res['reply_text']);
            $this->assertStringContainsString('Perubahan vs Open', $res['reply_text']);
            $this->assertStringContainsString('SHADOW_aebf1bb0101398b8', $res['reply_text']);
            $this->assertStringContainsString('NO_SIGNAL_ABSTAIN [ABSTAIN]', $res['reply_text']);
            $this->assertStringContainsString('EOD final menurut metadata sumber (bukan bukti settlement IDX)', $res['reply_text']);
            $this->assertStringNotContainsString('*', $res['reply_text'], "Raw asterisks found in '{$v}'");
            $this->assertStringNotContainsString('$SMRA', $res['reply_text'], "Raw dollar ticker found in '{$v}'");
        }
    }

    public function test_unrecognized_ticker_returns_clarification(): void
    {
        $res = $this->twoWayService->handleInboundMessage($this->makeUpdate('analisis emiten XYZ', 'upd_unrec_1', '201'));
        $this->assertEquals('QUERY_TICKER_STATUS', $res['intent']);
        $this->assertStringContainsString('KLARIFIKASI CAKUPAN INSTRUMEN', $res['reply_text']);
        $this->assertStringContainsString("Kode emiten 'XYZ' tidak valid", $res['reply_text']);
        $this->assertStringContainsString('Aether Market Intelligence hanya memproses saham resmi Bursa Efek Indonesia (BEI)', $res['reply_text']);
    }

    public function test_missing_ticker_in_analysis_returns_guidance(): void
    {
        $res = $this->twoWayService->handleInboundMessage($this->makeUpdate('analisis emiten', 'upd_miss_1', '202'));
        $this->assertEquals('QUERY_TICKER_STATUS', $res['intent']);
        $this->assertStringContainsString('PANDUAN ANALISIS EMITEN', $res['reply_text']);
        $this->assertStringContainsString('Mohon sertakan nama ticker saham', $res['reply_text']);
    }

    public function test_news_displayed_when_available(): void
    {
        $res = $this->twoWayService->handleInboundMessage($this->makeUpdate('analisis emiten BBCA', 'upd_bbca_1', '203'));
        $this->assertEquals('QUERY_TICKER_STATUS', $res['intent']);
        $this->assertStringContainsString('ANALISIS EMITEN — BBCA', $res['reply_text']);
        $this->assertStringContainsString('Rp 5.025,00', $res['reply_text']);
        $this->assertStringContainsString('Laba Bersih Konsolidasian BBCA Tumbuh Stabil', $res['reply_text']);
        $this->assertStringContainsString('LKBN ANTARA', $res['reply_text']);
    }

    public function test_news_empty_handled_gracefully(): void
    {
        $res = $this->twoWayService->handleInboundMessage($this->makeUpdate('analisis emiten SMRA', 'upd_smra_news_1', '204'));
        $this->assertStringContainsString('Tidak ada berita tersimpan untuk emiten ini dalam 7 hari terakhir', $res['reply_text']);
    }

    public function test_empty_market_data_for_supported_ticker_handled_gracefully(): void
    {
        // BMRI has no seed data in this test, triggering on-demand research flow
        $res = $this->twoWayService->handleInboundMessage($this->makeUpdate('kondisi saham BMRI', 'upd_bmri_1', '205'));
        $this->assertEquals('QUERY_TICKER_STATUS', $res['intent']);
        $this->assertStringContainsString('Permintaan riset data on-demand untuk emiten BMRI', $res['reply_text']);
        $this->assertStringContainsString('MISSING', $res['reply_text']);
        $this->assertStringContainsString('REQ_', $res['reply_text']);
    }

    public function test_idempotent_duplicate_update_handling(): void
    {
        $upd = $this->makeUpdate('analisis emiten SMRA', 'upd_idemp_1', '300');
        $res1 = $this->twoWayService->handleInboundMessage($upd);
        $this->assertFalse($res1['is_duplicate']);
        $this->assertDatabaseHas('telegram_conversations', ['telegram_update_id' => 'upd_idemp_1']);

        // Second time with identical update_id
        $res2 = $this->twoWayService->handleInboundMessage($upd);
        $this->assertTrue($res2['is_duplicate']);
        $this->assertEquals(1, TelegramConversation::where('telegram_update_id', 'upd_idemp_1')->count());
    }

    public function test_status_command_separates_cohorts_cleanly(): void
    {
        $res = $this->twoWayService->handleInboundMessage($this->makeUpdate('status system sekarang bagaimana?', 'upd_stat_1', '401'));
        $this->assertEquals('COMMAND_STATUS', $res['intent']);
        $this->assertStringContainsString('STATUS SISTEM AETHER INTELLIGENCE', $res['reply_text']);
        $this->assertStringContainsString('Live Forward Directional : 0 sinyal aktif', $res['reply_text']);
        $this->assertStringContainsString('Retrospective Replay      : 10 rekod abstain', $res['reply_text']);
        $this->assertStringContainsString('Evaluasi Historis Lama   : 30 rekod', $res['reply_text']);
        $this->assertStringContainsString('EOD Otomatis Nonaktif', $res['reply_text']);
        $this->assertStringNotContainsString('*', $res['reply_text']);
    }

    public function test_signals_command_explains_replay_abstain_records(): void
    {
        $res = $this->twoWayService->handleInboundMessage($this->makeUpdate('/signals', 'upd_sig_1', '402'));
        $this->assertEquals('COMMAND_SIGNALS', $res['intent']);
        $this->assertStringContainsString('DAFTAR SINYAL TERSIMPAN', $res['reply_text']);
        $this->assertStringContainsString('Live Forward Real-Time : 0 sinyal directional aktif', $res['reply_text']);
        $this->assertStringContainsString('Retrospective Replay    : 10 rekod abstain tersimpan', $res['reply_text']);
        $this->assertStringNotContainsString('*', $res['reply_text']);
    }

    public function test_unauthorized_user_is_safely_rejected(): void
    {
        $upd = [
            'update_id' => 'upd_unauth_1',
            'message' => [
                'message_id' => '999',
                'from' => ['id' => '111222333', 'username' => 'Hacker'],
                'chat' => ['id' => '111222333'],
                'text' => 'analisis emiten SMRA',
                'reply_to_message' => null,
            ],
        ];

        $res = $this->twoWayService->handleInboundMessage($upd);
        $this->assertEquals('UNAUTHORIZED_ACCESS', $res['intent']);
        $this->assertStringContainsString('Akses Ditolak', $res['reply_text']);
    }

    public function test_ai_on_demand_cached_explanation_is_returned_instantaneously(): void
    {
        $run = AnalysisRun::create([
            'run_id' => 'TEST_RUN_ONDEMAND',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // Seed cached analysis in DB
        MarketAnalysis::create([
            'analysis_run_id' => $run->id,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'prompt_version' => '1.0.0-on-demand',
            'market_regime_summary' => "FAKTA PASAR TERLAPOR:\n• Bar EOD 23 Okt 2026 penutupan Rp 260,00.\n\nINTERPRETASI MODEL:\n• Model abstain karena confidence 1,24% di bawah ambang batas.",
            'impact_direction' => 'unclear',
            'impact_horizon' => 'multi_day',
            'confidence_score' => 0.0124,
            'structured_output' => [
                'ticker' => 'SMRA',
                'bar_date' => '2026-10-23',
                'latency_ms' => 150,
                'model_version' => 'ag/gemini-3.8-flash-high',
            ],
            'validation_status' => 'valid_schema',
        ]);

        $res = $this->twoWayService->handleInboundMessage($this->makeUpdate('analisis emiten smra', 'upd_ai_cache_1', '777'));
        $this->assertEquals('QUERY_TICKER_STATUS', $res['intent']);
        $this->assertStringContainsString('PENJELASAN NARATIF KUANTITATIF (AI LIVE)', $res['reply_text']);
        $this->assertStringContainsString('FAKTA PASAR TERLAPOR', $res['reply_text']);
        $this->assertStringContainsString('INTERPRETASI MODEL', $res['reply_text']);
        $this->assertStringContainsString('[Tersimpan di DB]', $res['reply_text']);
        $this->assertStringContainsString('Hybrid Grounded (PostgreSQL + 9router ag/gemini-3.8-flash-high)', $res['reply_text']);
    }
}
