<?php

namespace App\Presenters;

use App\Models\CompanyAnalysisMetric;
use App\Models\IdxCompanyRegistry;
use App\Models\MarketAnalysis;
use App\Models\MarketObservation;
use App\Models\ResearchRequest;
use Illuminate\Support\Collection;

class AiConclusionPresenter
{
    /**
     * List of 10 walk-forward validated constituents.
     */
    public const VALIDATED_CONSTITUENTS = [
        'BBCA', 'BBRI', 'BMRI', 'BBNI', 'TLKM', 'ASII', 'MAPI', 'ARTO', 'MEDC', 'SMRA',
    ];

    /**
     * Present and normalize AI conclusion payload for view rendering.
     */
    public static function present(
        string $ticker,
        ?IdxCompanyRegistry $company,
        ?MarketAnalysis $latestAnalysis,
        ?MarketObservation $latestObs,
        ?CompanyAnalysisMetric $technical,
        Collection $fundamentals,
        Collection $news,
        Collection $signals,
        ?ResearchRequest $activeRequest = null
    ): array {
        $ticker = strtoupper(trim($ticker));
        $isConstituent = in_array($ticker, self::VALIDATED_CONSTITUENTS, true);
        $hasAnalysis = $latestAnalysis !== null;
        $struct = $latestAnalysis?->structured_output ?? [];
        $isValidIssuer = ($company !== null) || ($ticker === '^JKSE');

        // 1. Parse Narrative Sections if available (Schema 2)
        $parsedSections = self::parseNarrativeSections($latestAnalysis);

        // 2. Active Request Status
        $activeProcessing = false;
        $activeRequestId = null;
        if ($activeRequest && in_array(strtolower($activeRequest->status), ['pending', 'collecting', 'analyzing'], true)) {
            $activeProcessing = true;
            $activeRequestId = $activeRequest->request_id;
        }

        // 3. Header Data
        $analysisDate = $latestAnalysis?->created_at
            ? $latestAnalysis->created_at->timezone('Asia/Jakarta')->format('d M Y, H:i').' WIB'
            : null;
        $barDate = $struct['bar_date'] ?? $latestObs?->bar_date?->format('Y-m-d') ?? null;
        $analysisType = $struct['analysis_type'] ?? ($hasAnalysis ? 'ANALISIS_GEMINI_EOD' : null);
        $modeLabel = self::formatAnalysisType($analysisType, $fundamentals->isNotEmpty());

        $header = [
            'title' => 'Kesimpulan AI',
            'ticker' => $ticker,
            'company_name' => $company?->company_name ?? ($isValidIssuer ? $ticker : 'Emiten Belum Terdaftar BEI'),
            'is_valid_issuer' => $isValidIssuer,
            'can_request_research' => $isValidIssuer,
            'analysis_date' => $analysisDate,
            'bar_date' => $barDate,
            'horizon' => $struct['impact_horizon'] ?? 'Multi_day (+5D)',
            'mode_label' => $modeLabel,
            'model_name' => $latestAnalysis?->model_name ?? 'ag/gemini-3.8-flash-high',
            'completeness_status' => ! $isValidIssuer
                ? 'Ticker Tidak Terdaftar'
                : self::determineCompleteness($hasAnalysis, $fundamentals->isNotEmpty(), $latestObs !== null),
            'active_processing' => $activeProcessing,
            'active_request_id' => $activeRequestId,
        ];

        // 4. Section A: Ringkasan Utama
        $paragraphs = [];
        $isFallbackSummary = false;
        $summaryTitle = 'Ringkasan Eksekutif & Perilaku Emiten';

        if (! empty($parsedSections['summary_paragraphs'])) {
            $paragraphs = $parsedSections['summary_paragraphs'];
        } elseif (! empty($latestAnalysis?->market_regime_summary)) {
            $cleaned = self::cleanText($latestAnalysis->market_regime_summary);
            $paragraphs = array_values(array_filter(explode("\n\n", $cleaned)));
            if (empty($paragraphs)) {
                $paragraphs = [$cleaned];
            }
        } elseif ($latestObs) {
            $isFallbackSummary = true;
            $summaryTitle = 'Ringkasan data — bukan analisis Gemini';
            $closeStr = number_format($latestObs->close_price, 0, ',', '.');
            $openStr = number_format($latestObs->open_price, 0, ',', '.');
            $volStr = number_format($latestObs->volume, 0, ',', '.');
            $barDateStr = $latestObs->bar_date?->format('d M Y') ?? 'sesi terakhir';
            $actualSource = $latestObs->source ?? 'Yahoo Finance (data sekunder publik)';

            $paragraphs[] = "Ringkasan data — bukan analisis Gemini. Belum ada sintesis naratif AI Gemini tersimpan untuk emiten {$ticker}. "
                ."Berdasarkan data pasar sekunder ({$actualSource}) per {$barDateStr}, harga tercatat Rp {$closeStr} "
                ."(Open: Rp {$openStr}) dengan akumulasi volume transaksi {$volStr} lembar saham.";
            $paragraphs[] = 'Evaluasi prediktif dan sintesis narasi mendalam dapat dipicu menggunakan permintaan riset on-demand.';
        } else {
            $isFallbackSummary = true;
            $summaryTitle = 'Ringkasan data — bukan analisis Gemini';
            $paragraphs[] = "Ringkasan data — bukan analisis Gemini. Belum ada catatan harga pasar sekunder maupun analisis AI Gemini untuk emiten {$ticker}. "
                .'Menunggu sinkronisasi pipeline data.';
        }

        // 5. Section B: Panel Teknikal & Fundamental
        $technicalPanel = self::buildTechnicalPanel($ticker, $latestObs, $technical, $parsedSections);
        $fundamentalPanel = self::buildFundamentalPanel($ticker, $fundamentals, $struct, $parsedSections);

        // 6. Section C: Faktor Pendukung & Risiko
        $supportingFactors = ! empty($parsedSections['facts'])
            ? $parsedSections['facts']
            : (is_array($struct['verified_facts'] ?? null) ? array_map([self::class, 'cleanText'], $struct['verified_facts']) : []);

        if (empty($supportingFactors) && $latestObs) {
            $supportingFactors[] = 'Penutupan harga EOD Rp '.number_format($latestObs->close_price, 0, ',', '.').' ('.($latestObs->bar_date ? $latestObs->bar_date->format('d/m/Y') : 'EOD').').';
            if ($latestObs->volume > 0) {
                $supportingFactors[] = 'Volume transaksi sesi berjalan tercatat '.number_format($latestObs->volume, 0, ',', '.').' lembar saham.';
            }
        }

        $riskFactors = ! empty($parsedSections['risks'])
            ? $parsedSections['risks']
            : (is_array($struct['risks'] ?? null) ? array_map([self::class, 'cleanText'], $struct['risks']) : []);

        if (empty($riskFactors)) {
            $riskFactors[] = 'Volatilitas harga harian bergantung pada likuiditas antrean order book pasar reguler.';
            $riskFactors[] = 'Data observasi satu sesi tidak mencerminkan rata-rata partisipasi likuiditas multi-hari.';
        }

        // 7. Section D: Prediksi Machine Learning
        $mlPrediction = self::buildMlPrediction($ticker, $signals, $isConstituent, $latestObs);

        // 8. Section E: Sumber & Keterbatasan
        $sourcesAndLimitations = self::buildSourcesAndLimitations($ticker, $latestObs, $fundamentals, $news, $latestAnalysis);

        // 9. Section F: Detail Analisis (Accordion)
        $auditMetadata = [
            'analysis_id' => $latestAnalysis?->id,
            'evidence_id' => $latestAnalysis?->evidence_snapshot_id ?? $struct['evidence_snapshot_id'] ?? null,
            'evidence_hash' => $struct['evidence_hash'] ?? null,
            'model' => $latestAnalysis?->model_name ?? 'ag/gemini-3.8-flash-high',
            'prompt_version' => $latestAnalysis?->prompt_version ?? 'v1.0.0-grounded',
            'latency_ms' => $struct['latency_ms'] ?? null,
            'tokens_used' => $struct['usage']['total_tokens'] ?? null,
            'reasoning_tokens' => $struct['usage']['completion_tokens_details']['reasoning_tokens'] ?? null,
            'raw_json' => ! empty($struct) ? json_encode($struct, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ];

        return [
            'has_analysis' => $hasAnalysis,
            'header' => $header,
            'section_a' => [
                'title' => $summaryTitle,
                'is_fallback' => $isFallbackSummary,
                'paragraphs' => array_slice($paragraphs, 0, 2),
            ],
            'section_b' => [
                'technical' => $technicalPanel,
                'fundamental' => $fundamentalPanel,
            ],
            'section_c' => [
                'supporting_factors' => array_slice($supportingFactors, 0, 5),
                'risk_factors' => array_slice($riskFactors, 0, 5),
            ],
            'section_d' => $mlPrediction,
            'section_e' => $sourcesAndLimitations,
            'section_f' => $auditMetadata,
        ];
    }

    /**
     * Parse structured sections from narrative text.
     */
    protected static function parseNarrativeSections(?MarketAnalysis $analysis): array
    {
        if (! $analysis) {
            return ['summary_paragraphs' => [], 'facts' => [], 'risks' => []];
        }

        $text = $analysis->structured_output['generated_narrative'] ?? $analysis->market_regime_summary ?? '';
        if (trim($text) === '') {
            return ['summary_paragraphs' => [], 'facts' => [], 'risks' => []];
        }

        $lines = explode("\n", str_replace("\r", '', $text));
        $currentSection = 'intro';
        $sections = [
            'intro' => [],
            'fakta' => [],
            'interpretasi' => [],
            'risiko' => [],
        ];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            // Detect section headers even if preceded by bullet or emoji
            $cleanHeaderCandidate = strtoupper(trim(ltrim($trimmed, "•-* \t")));
            if (str_starts_with($cleanHeaderCandidate, 'FAKTA PASAR') || str_starts_with($cleanHeaderCandidate, 'BUKTI KUNCI')) {
                $currentSection = 'fakta';

                continue;
            } elseif (str_starts_with($cleanHeaderCandidate, 'INTERPRETASI MODEL') || str_starts_with($cleanHeaderCandidate, 'STATUS VALIDASI')) {
                $currentSection = 'interpretasi';

                continue;
            } elseif (str_starts_with($cleanHeaderCandidate, 'SKENARIO DAMPAK') || str_starts_with($cleanHeaderCandidate, 'FAKTOR RISIKO') || (str_starts_with($cleanHeaderCandidate, 'RISIKO') && str_ends_with($trimmed, ':'))) {
                $currentSection = 'risiko';

                continue;
            }

            $cleanedLine = self::cleanBullet($trimmed);
            if ($cleanedLine !== '') {
                $sections[$currentSection][] = $cleanedLine;
            }
        }

        $summaryParagraphs = [];
        if (! empty($sections['interpretasi'])) {
            $summaryParagraphs[] = implode(' ', $sections['interpretasi']);
        }
        if (! empty($sections['intro'])) {
            $summaryParagraphs[] = implode(' ', $sections['intro']);
        }
        if (empty($summaryParagraphs) && ! empty($sections['fakta'])) {
            $summaryParagraphs[] = $sections['fakta'][0];
        }

        return [
            'summary_paragraphs' => $summaryParagraphs,
            'facts' => $sections['fakta'],
            'risks' => $sections['risiko'],
        ];
    }

    /**
     * Build Technical Panel for Section B.
     */
    protected static function buildTechnicalPanel(
        string $ticker,
        ?MarketObservation $obs,
        ?CompanyAnalysisMetric $tech,
        array $parsedSections
    ): array {
        $keyMetrics = [];

        if ($obs) {
            $keyMetrics[] = [
                'label' => 'Harga Close',
                'value' => 'Rp '.number_format($obs->close_price, 0, ',', '.'),
                'sub' => $obs->bar_date ? $obs->bar_date->format('d/m/Y') : 'EOD',
            ];
            $rangeVal = ($obs->high_price && $obs->low_price && $obs->close_price > 0)
                ? (($obs->high_price - $obs->low_price) / $obs->close_price) * 100
                : null;
            $keyMetrics[] = [
                'label' => 'Rentang Sesi',
                'value' => $rangeVal !== null ? number_format($rangeVal, 2, ',', '.').'%' : 'N/A',
                'sub' => 'High vs Low',
            ];
            $keyMetrics[] = [
                'label' => 'Volume Sesi',
                'value' => number_format($obs->volume, 0, ',', '.'),
                'sub' => 'Lembar Saham',
            ];
        }

        if ($tech) {
            if ($tech->sma_20 !== null) {
                $keyMetrics[] = [
                    'label' => 'SMA 20',
                    'value' => 'Rp '.number_format($tech->sma_20, 0, ',', '.'),
                    'sub' => 'Trend 20-Bar',
                ];
            }
            if ($tech->rsi_14 !== null) {
                $keyMetrics[] = [
                    'label' => 'RSI (14)',
                    'value' => number_format($tech->rsi_14, 2, ',', '.'),
                    'sub' => $tech->rsi_14 > 70 ? 'Overbought' : ($tech->rsi_14 < 30 ? 'Oversold' : 'Neutral'),
                ];
            }
        }

        return [
            'findings' => ! empty($parsedSections['facts'][0])
                ? $parsedSections['facts'][0]
                : ($obs ? 'Observasi penutupan terakhir pada Rp '.number_format($obs->close_price, 0, ',', '.').'.' : 'Data teknikal belum mencukupi.'),
            'key_metrics' => array_slice($keyMetrics, 0, 4),
            'interpretation' => 'Pergerakan harga teknikal dievaluasi berbasis harga penutupan dan ambang batas geometric bursa.',
            'limitations' => 'Data bar tunggal tidak mencerminkan rata-rata likuiditas konsisten multi-hari.',
            'tab_url' => route('dashboard.ticker', ['ticker' => $ticker, 'tab' => 'technical']),
        ];
    }

    /**
     * Build Fundamental Panel for Section B.
     */
    protected static function buildFundamentalPanel(
        string $ticker,
        Collection $fundamentals,
        array $struct,
        array $parsedSections
    ): array {
        $latestFund = $fundamentals->first();
        $hasFundamental = $latestFund !== null || ! empty($struct['has_fundamental']);
        $keyMetrics = [];

        if ($latestFund) {
            if ($latestFund->per !== null) {
                $keyMetrics[] = [
                    'label' => 'P/E Ratio',
                    'value' => number_format($latestFund->per, 2, ',', '.').'x',
                    'sub' => 'Valuasi Laba',
                ];
            }
            if ($latestFund->pbv !== null) {
                $keyMetrics[] = [
                    'label' => 'P/BV Ratio',
                    'value' => number_format($latestFund->pbv, 2, ',', '.').'x',
                    'sub' => 'Nilai Buku',
                ];
            }
            if ($latestFund->roe !== null) {
                $keyMetrics[] = [
                    'label' => 'ROE',
                    'value' => number_format($latestFund->roe * 100, 2, ',', '.').'%',
                    'sub' => 'Profitabilitas',
                ];
            }
            if ($latestFund->der !== null) {
                $keyMetrics[] = [
                    'label' => 'DER',
                    'value' => number_format($latestFund->der, 2, ',', '.').'x',
                    'sub' => 'Solvabilitas',
                ];
            }
        }

        return [
            'has_fundamental' => $hasFundamental,
            'findings' => $hasFundamental
                ? ($latestFund ? 'Laporan fiskal kuartal terakhir tercatat dengan PER '.($latestFund->per ? number_format($latestFund->per, 1).'x' : 'N/A').'.' : 'Indikator fundamental tercatat dalam analisis sintetis.')
                : 'Laporan keuangan fiskal belum tersedia pada penyedia data sekunder.',
            'key_metrics' => array_slice($keyMetrics, 0, 4),
            'interpretation' => $hasFundamental
                ? 'Rasio valuasi fiskal digunakan untuk mengukur margin of safety tanpa rekayasa target harga.'
                : 'Analisis berjalan murni teknikal tanpa asumsi valuasi neraca.',
            'limitations' => 'Laporan keuangan penyedia data belum diaudit point-in-time publikasi resmi BEI.',
            'tab_url' => route('dashboard.ticker', ['ticker' => $ticker, 'tab' => 'fundamental']),
        ];
    }

    /**
     * Build Machine Learning Prediction for Section D.
     */
    protected static function buildMlPrediction(
        string $ticker,
        Collection $signals,
        bool $isConstituent,
        ?MarketObservation $latestObs
    ): array {
        $barDateStr = $latestObs?->bar_date ? $latestObs->bar_date->format('Y-m-d') : null;

        // Find signal matching this observation bar date specifically
        $barSignal = $signals->first(function ($s) use ($barDateStr) {
            if (! $barDateStr || ! $s->decision_timestamp) {
                return false;
            }

            return $s->decision_timestamp->format('Y-m-d') === $barDateStr;
        });

        // 1. Universe status
        $badge = $isConstituent
            ? 'WALK_FORWARD_VALIDATED_CONSTITUENT'
            : 'NON_UNIVERSE_INSTRUMENT';

        // 2. Inference status for this specific bar
        $hasActiveInferenceForBar = ($barSignal !== null);

        // 3. Direction / Signal status
        if (! $isConstituent) {
            $signalStatus = 'NO_ACTIVE_INFERENCE_FOR_BAR';
            $confidence = 'N/A';
            $probability = 'N/A';
            $inferenceTime = 'N/A';
            $explanation = 'Instrumen di luar 10 konstituen strategi aturan teknikal EXP-04. Model belum dikalibrasi untuk instrumen ini. Sesuai tata kelola, estimasi probabilitas dan keyakinan wajib N/A.';
        } elseif (! $hasActiveInferenceForBar) {
            $signalStatus = 'NO_ACTIVE_INFERENCE_FOR_BAR';
            $confidence = 'N/A';
            $probability = 'N/A';
            $inferenceTime = 'N/A';
            $explanation = 'Instrumen masuk dalam 10 konstituen strategi aturan teknikal EXP-04, namun belum ada kalkulasi inferensi aktif untuk bar tanggal '.($barDateStr ?? 'terkini').'. Status dilaporkan NO_ACTIVE_INFERENCE_FOR_BAR tanpa meminjam inferensi historis.';
        } else {
            // Signal genuinely run for this specific bar
            $rawDir = strtoupper(trim($barSignal->predicted_direction ?? ''));
            if ($rawDir === 'NO_SIGNAL' || $rawDir === 'ABSTAIN' || str_contains($rawDir, 'ABSTAIN')) {
                $signalStatus = 'ABSTAIN (Hasil Sinyal Model)';
            } else {
                $signalStatus = $rawDir ?: 'ABSTAIN (Hasil Sinyal Model)';
            }
            $confidence = $barSignal->confidence !== null
                ? number_format($barSignal->confidence * 100, 1, ',', '.').'%'
                : 'N/A';
            $probability = $barSignal->up_probability !== null
                ? number_format($barSignal->up_probability * 100, 1, ',', '.').'%'
                : 'N/A';
            $inferenceTime = $barSignal->decision_timestamp->timezone('Asia/Jakarta')->format('d M Y, H:i').' WIB';
            $explanation = 'Inferensi model '.($barSignal->model_version ?? 'RandomForest_v1.0').' dijalankan untuk bar '.$barDateStr.'. Evaluasi threshold menghasilkan status '.$signalStatus.'.';
        }

        $modelArtifact = $barSignal?->model_version ?? 'RandomForest_v1.0';

        return [
            'is_constituent' => $isConstituent,
            'has_active_inference' => $hasActiveInferenceForBar,
            'validation_badge' => $badge,
            'model_name' => $modelArtifact,
            'strategy_protocol' => 'Strategi Aturan Teknikal (EXP-04)',
            'horizon' => 'H=5 (5 Hari Bursa)',
            'signal_status' => $signalStatus,
            'probability' => $probability,
            'confidence' => $confidence,
            'inference_time' => $inferenceTime,
            'explanation' => $explanation,
        ];
    }

    /**
     * Build Sources and Limitations for Section E.
     */
    protected static function buildSourcesAndLimitations(
        string $ticker,
        ?MarketObservation $obs,
        Collection $fundamentals,
        Collection $news,
        ?MarketAnalysis $analysis
    ): array {
        $sources = [];

        // Market data source
        if ($obs) {
            $sourceName = $obs->source ?? 'Yahoo Finance (data sekunder publik)';
            $isYahoo = str_contains(strtolower($sourceName), 'yahoo');
            $sources[] = [
                'name' => $sourceName,
                'type' => $obs->is_eod_final ? 'provider-supplied' : 'provisional',
                'date_or_period' => $obs->bar_date ? $obs->bar_date->format('d M Y') : 'Sesi Terakhir',
                'description' => $isYahoo
                    ? 'Harga penutupan EOD feed publik Yahoo Finance (data sekunder, bukan kliring resmi BEI).'
                    : 'Harga penutupan EOD penyedia data.',
            ];
        }

        // Fundamentals source
        if ($fundamentals->isNotEmpty()) {
            $latestF = $fundamentals->first();
            $period = $latestF->period_year ? "FY{$latestF->period_year} Q".($latestF->period_quarter ?? '4') : 'Laporan Keuangan';
            $sources[] = [
                'name' => 'Registri Fundamental BEI / Yahoo Finance',
                'type' => 'provider-supplied',
                'date_or_period' => $period,
                'description' => 'Neraca, laba rugi, dan kelipatan rasio valuasi fiskal.',
            ];
        }

        // News source
        if ($news->isNotEmpty()) {
            $sources[] = [
                'name' => 'Agregasi Berita & Pengumuman Keterbukaan',
                'type' => 'document-verified',
                'date_or_period' => '7 Hari Terakhir',
                'description' => $news->count().' artikel terindeks dengan stempel waktu terverifikasi.',
            ];
        }

        $missingData = [];
        if (! $obs) {
            $missingData[] = 'Bar harga penutupan EOD belum tersinkronisasi.';
        }
        if ($fundamentals->isEmpty()) {
            $missingData[] = 'Laporan keuangan fiskal belum tersedia.';
        }
        if ($news->isEmpty()) {
            $missingData[] = 'Nihil artikel berita atau keterbukaan informasi dalam 7 hari terakhir.';
        }

        return [
            'sources' => $sources,
            'missing_data' => $missingData,
        ];
    }

    /**
     * Format Analysis Type Label.
     */
    protected static function formatAnalysisType(?string $type, bool $hasFund): string
    {
        if ($type === 'FULL_FUNDAMENTAL_AND_TECHNICAL' || ($type === 'COMPREHENSIVE' && $hasFund)) {
            return 'Komprehensif (Fundamental + Teknikal)';
        } elseif ($type === 'TECHNICAL' || ! $hasFund) {
            return 'Teknikal Saja (Harga & Volume EOD)';
        } elseif ($type === 'FUNDAMENTAL') {
            return 'Fundamental Fiskal';
        }

        return 'Analisis Intelijen Pasar';
    }

    /**
     * Determine Completeness Label.
     */
    protected static function determineCompleteness(bool $hasAnalysis, bool $hasFund, bool $hasObs): string
    {
        if (! $hasAnalysis) {
            return 'Belum Dianalisis';
        }
        if ($hasFund && $hasObs) {
            return 'Lengkap (Multi-Bukti)';
        }
        if ($hasObs) {
            return 'Parsial (Teknikal Saham)';
        }

        return 'Terbatas';
    }

    /**
     * Clean text: sanitize HTML, strip markdown bold asterisks, normalize spaces.
     */
    public static function cleanText(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        // 1. Strip script and HTML tags safely
        $stripped = strip_tags($text);

        // 2. Remove markdown asterisks (**bold** -> bold, *italic* -> italic)
        $cleanMd = preg_replace('/\*+([^*]+)\*+/', '$1', $stripped);

        // 3. Remove leading bullets if any
        $cleanBullets = preg_replace('/^[•\-\*\s]+/m', '', $cleanMd);

        // 4. Normalize spaces
        return trim(preg_replace('/[ \t]+/', ' ', $cleanBullets));
    }

    /**
     * Clean single bullet line.
     */
    public static function cleanBullet(string $line): string
    {
        $stripped = strip_tags($line);
        $cleanMd = preg_replace('/\*+([^*]+)\*+/', '$1', $stripped);
        $cleanBullets = ltrim($cleanMd, "•-* \t");

        return trim(preg_replace('/[ \t]+/', ' ', $cleanBullets));
    }
}
