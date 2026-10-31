<?php

namespace Tests\Unit;

use App\Models\IdxCompanyRegistry;
use App\Models\MarketAnalysis;
use App\Models\MarketObservation;
use App\Models\PredictionSignal;
use App\Models\ResearchRequest;
use App\Presenters\AiConclusionPresenter;
use Carbon\Carbon;
use Tests\TestCase;

class AiConclusionPresenterTest extends TestCase
{
    /**
     * Test complete Schema 2 analysis (on-demand generated narrative).
     */
    public function test_presenter_with_complete_schema2_analysis(): void
    {
        $analysis = new MarketAnalysis([
            'id' => 999,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'created_at' => now(),
            'market_regime_summary' => "FAKTA PASAR TERLAPOR:\n• Bar 26 Oct 2026. Open Rp 492,00, Close Rp 488,00.\n• Volume 8.100 lembar.\n\nINTERPRETASI MODEL & STATUS VALIDASI:\n• Status: MODEL_NOT_VALIDATED_FOR_INSTRUMENT.\n\nSKENARIO DAMPAK & RISIKO:\n• Rentang sempit tandai volatilitas rendah.\n• Risiko likuiditas order book.",
            'structured_output' => [
                'ticker' => 'BBCA',
                'bar_date' => '2026-10-26',
                'analysis_type' => 'FULL_FUNDAMENTAL_AND_TECHNICAL',
                'latency_ms' => 1250,
                'evidence_hash' => str_repeat('b', 64),
                'usage' => ['total_tokens' => 1500, 'prompt_tokens' => 1200, 'completion_tokens' => 300],
                'generated_narrative' => "FAKTA PASAR TERLAPOR:\n• Bar 26 Oct 2026. Open Rp 492,00, Close Rp 488,00.\n• Volume 8.100 lembar.\n\nINTERPRETASI MODEL & STATUS VALIDASI:\n• Status: MODEL_NOT_VALIDATED_FOR_INSTRUMENT.\n\nSKENARIO DAMPAK & RISIKO:\n• Rentang sempit tandai volatilitas rendah.\n• Risiko likuiditas order book.",
            ],
        ]);

        $obs = new MarketObservation([
            'close_price' => 488,
            'open_price' => 492,
            'high_price' => 495,
            'low_price' => 485,
            'volume' => 8100,
            'bar_date' => Carbon::parse('2026-10-26'),
        ]);

        $data = AiConclusionPresenter::present(
            ticker: 'BBCA',
            company: null,
            latestAnalysis: $analysis,
            latestObs: $obs,
            technical: null,
            fundamentals: collect(),
            news: collect(),
            signals: collect(),
            activeRequest: null
        );

        $this->assertTrue($data['has_analysis']);
        $this->assertSame('Kesimpulan AI', $data['header']['title']);
        $this->assertSame('Ringkasan Eksekutif & Perilaku Emiten', $data['section_a']['title']);
        $this->assertFalse($data['section_a']['is_fallback']);
        $this->assertNotEmpty($data['section_a']['paragraphs']);
        $this->assertCount(2, $data['section_c']['risk_factors']);
        $this->assertSame(999, $data['section_f']['analysis_id']);
        $this->assertSame('ag/gemini-3.8-flash-high', $data['section_f']['model']);
        $this->assertFalse($data['header']['active_processing']);
    }

    /**
     * Test Schema 1 analysis (structured array with verified_facts and risks).
     */
    public function test_presenter_with_legacy_schema1_analysis(): void
    {
        $analysis = new MarketAnalysis([
            'id' => 888,
            'model_name' => 'ag/gemini-3.8-flash-high',
            'created_at' => now(),
            'market_regime_summary' => 'Pasar sideways dengan pergerakan stabil.',
            'structured_output' => [
                'verified_facts' => ['Inflasi inti terkendali', 'Volume perdagangan konsisten'],
                'risks' => ['Kenaikan suku bunga The Fed', 'Pelemahan nilai tukar'],
                'confidence_score' => null,
            ],
        ]);

        $data = AiConclusionPresenter::present(
            ticker: 'BMRI',
            company: null,
            latestAnalysis: $analysis,
            latestObs: null,
            technical: null,
            fundamentals: collect(),
            news: collect(),
            signals: collect(),
            activeRequest: null
        );

        $this->assertTrue($data['has_analysis']);
        $this->assertCount(2, $data['section_c']['supporting_factors']);
        $this->assertCount(2, $data['section_c']['risk_factors']);
        $this->assertStringContainsString('Pasar sideways', $data['section_a']['paragraphs'][0]);
    }

    /**
     * Test fallback when emiten has no AI analysis.
     */
    public function test_presenter_with_empty_analysis(): void
    {
        $obs = new MarketObservation([
            'close_price' => 1000,
            'open_price' => 1000,
            'high_price' => 1020,
            'low_price' => 990,
            'volume' => 500000,
            'bar_date' => Carbon::parse('2026-10-26'),
            'source' => 'Yahoo Finance (via yfinance)',
        ]);

        $data = AiConclusionPresenter::present(
            ticker: 'UNKNOWN_TEST',
            company: null,
            latestAnalysis: null,
            latestObs: $obs,
            technical: null,
            fundamentals: collect(),
            news: collect(),
            signals: collect(),
            activeRequest: null
        );

        $this->assertFalse($data['has_analysis']);
        $this->assertTrue($data['section_a']['is_fallback']);
        $this->assertSame('Ringkasan data — bukan analisis Gemini', $data['section_a']['title']);
        $this->assertNotEmpty($data['section_a']['paragraphs']);
        $this->assertStringContainsString('Ringkasan data — bukan analisis Gemini', $data['section_a']['paragraphs'][0]);
        $this->assertStringContainsString('data pasar sekunder', $data['section_a']['paragraphs'][0]);
        $this->assertStringNotContainsString('data pasar resmi BEI', $data['section_a']['paragraphs'][0]);
        $this->assertSame('N/A', $data['section_d']['confidence']);
        $this->assertSame('NON_UNIVERSE_INSTRUMENT', $data['section_d']['validation_badge']);
        $this->assertSame('NO_ACTIVE_INFERENCE_FOR_BAR', $data['section_d']['signal_status']);
    }

    /**
     * Test constituent vs non-constituent ML predictions and bar inference separation.
     */
    public function test_presenter_ml_prediction_rules(): void
    {
        $obs = new MarketObservation([
            'ticker' => 'BBCA',
            'bar_date' => Carbon::parse('2026-10-26'),
            'close_price' => 4940,
        ]);

        // 1. BBCA without inference for this bar
        $bbcaNoSig = AiConclusionPresenter::present('BBCA', null, null, $obs, null, collect(), collect(), collect(), null);
        $this->assertTrue($bbcaNoSig['section_d']['is_constituent']);
        $this->assertSame('WALK_FORWARD_VALIDATED_CONSTITUENT', $bbcaNoSig['section_d']['validation_badge']);
        $this->assertSame('NO_ACTIVE_INFERENCE_FOR_BAR', $bbcaNoSig['section_d']['signal_status']);
        $this->assertSame('N/A', $bbcaNoSig['section_d']['confidence']);
        $this->assertSame('N/A', $bbcaNoSig['section_d']['probability']);

        // 2. BBCA with genuine inference signal for this bar
        $signal = new PredictionSignal([
            'ticker' => 'BBCA',
            'decision_timestamp' => Carbon::parse('2026-10-26 20:00:00'),
            'model_version' => 'RandomForest_v1.0',
            'predicted_direction' => 'NO_SIGNAL',
            'confidence' => 0.301,
            'up_probability' => 0.6505,
        ]);

        $bbcaWithSig = AiConclusionPresenter::present('BBCA', null, null, $obs, null, collect(), collect(), collect([$signal]), null);
        $this->assertTrue($bbcaWithSig['section_d']['has_active_inference']);
        $this->assertEquals('RandomForest_v1.0', $bbcaWithSig['section_d']['model_name']);
        $this->assertEquals('Strategi Aturan Teknikal (EXP-04)', $bbcaWithSig['section_d']['strategy_protocol']);
        $this->assertSame('ABSTAIN (Hasil Sinyal Model)', $bbcaWithSig['section_d']['signal_status']);
        $this->assertSame('30,1%', $bbcaWithSig['section_d']['confidence']);
        $this->assertSame('65,1%', $bbcaWithSig['section_d']['probability']);

        // 3. ASDM is not in 10 validated constituents
        $asdmData = AiConclusionPresenter::present('ASDM', null, null, $obs, null, collect(), collect(), collect([$signal]), null);
        $this->assertFalse($asdmData['section_d']['is_constituent']);
        $this->assertSame('NON_UNIVERSE_INSTRUMENT', $asdmData['section_d']['validation_badge']);
        $this->assertSame('NO_ACTIVE_INFERENCE_FOR_BAR', $asdmData['section_d']['signal_status']);
        $this->assertSame('N/A', $asdmData['section_d']['confidence']);
    }

    /**
     * Test unknown ticker vs valid issuer research permissions.
     */
    public function test_presenter_unknown_ticker_cannot_request_research(): void
    {
        // Unknown ticker (no company registry entry)
        $unknownData = AiConclusionPresenter::present('UNKNOWN', null, null, null, null, collect(), collect(), collect(), null);
        $this->assertFalse($unknownData['header']['is_valid_issuer']);
        $this->assertFalse($unknownData['header']['can_request_research']);
        $this->assertSame('Ticker Tidak Terdaftar', $unknownData['header']['completeness_status']);

        // Valid registered issuer without data
        $company = new IdxCompanyRegistry([
            'ticker' => 'BBCA',
            'company_name' => 'Bank Central Asia Tbk.',
        ]);
        $validData = AiConclusionPresenter::present('BBCA', $company, null, null, null, collect(), collect(), collect(), null);
        $this->assertTrue($validData['header']['is_valid_issuer']);
        $this->assertTrue($validData['header']['can_request_research']);
    }

    /**
     * Test active research request indication.
     */
    public function test_presenter_active_request_processing(): void
    {
        $activeReq = new ResearchRequest([
            'request_id' => 'REQ_TEST_ACTIVE_123',
            'status' => 'analyzing',
        ]);

        $data = AiConclusionPresenter::present('TLKM', null, null, null, null, collect(), collect(), collect(), $activeReq);
        $this->assertTrue($data['header']['active_processing']);
        $this->assertSame('REQ_TEST_ACTIVE_123', $data['header']['active_request_id']);
    }

    /**
     * Test sanitization strips unescaped tags and markdown asterisks.
     */
    public function test_presenter_sanitization(): void
    {
        $dirtyText = '**PERINGATAN**: Harga <script>alert(1)</script> berfluktuasi **tinggi**.';
        $cleaned = AiConclusionPresenter::cleanText($dirtyText);

        $this->assertStringNotContainsString('<script>', $cleaned);
        $this->assertStringNotContainsString('**', $cleaned);
        $this->assertSame('PERINGATAN: Harga alert(1) berfluktuasi tinggi.', $cleaned);
    }
}
