<?php

namespace App\Services;

use App\Models\CompanyAnalysisMetric;
use App\Models\IdxCompanyRegistry;
use App\Models\NewsItem;
use App\Models\PredictionSignal;
use Carbon\Carbon;

class StockScreeningService
{
    public const STRATEGY_VERSION = 'v1.0.0-frozen';

    public function __construct(
        protected CanonicalMarketReadService $marketRead
    ) {}

    public static function roundToBeiTick(float $price, bool $roundUp = true): float
    {
        if ($price < 200) {
            $tick = 1.0;
        } elseif ($price < 500) {
            $tick = 2.0;
        } elseif ($price < 2000) {
            $tick = 5.0;
        } elseif ($price < 5000) {
            $tick = 10.0;
        } else {
            $tick = 25.0;
        }

        return $roundUp
            ? ceil($price / $tick) * $tick
            : floor($price / $tick) * $tick;
    }

    /**
     * Screen candidates deterministically based on stored observations and rules.
     */
    public function screenCandidates(string $strategy = 'SWING', array $options = []): array
    {
        $strategy = strtoupper(trim($strategy));
        $allLatest = $this->marketRead->latestByTicker();
        // Exclude index benchmarks (^JKSE / JKSE) - screening is strictly for company equities only
        $allLatest = $allLatest->reject(fn ($obs, $t) => $t === '^JKSE' || $t === 'JKSE' || $t === 'IHSG');
        $inspectedUniverse = $allLatest->keys()->all();
        $totalRegisteredStocks = IdxCompanyRegistry::where('instrument_type', 'EQUITY')->count() ?: count(BeiUniverseRegistry::getEquityTickers());

        $candidates = [];
        $missingData = [];
        $rejectionReasons = [];

        foreach ($allLatest as $ticker => $obs) {
            if (! $obs) {
                $missingData[] = $ticker;
                $rejectionReasons[$ticker] = 'DATA_OBSERVASI_TIDAK_TERSEDIA';

                continue;
            }

            $close = (float) $obs->close_price;
            $open = (float) $obs->open_price;
            $high = (float) $obs->high_price;
            $low = (float) $obs->low_price;
            $volume = (int) $obs->volume;

            $rangePct = $close > 0 ? (($high - $low) / $close) * 100 : 0.0;
            $changeVsOpenPct = $open > 0 ? (($close - $open) / $open) * 100 : 0.0;

            // Indicator metrics calculated time
            $metric = CompanyAnalysisMetric::where('ticker', $ticker)->latest('as_of_date')->first();

            // Query ML signal if available
            $sig = PredictionSignal::where('ticker', $ticker)->latest('decision_date')->first();

            if ($strategy === 'SWING') {
                // Criteria: Volume >= 10M lembar, Rentang bar 1.5% - 6.5%, Perubahan penutupan vs pembukaan >= -0.5%
                $liquidityPass = $volume >= 10_000_000;
                $rangePass = $rangePct >= 1.5 && $rangePct <= 6.5;
                $directionPass = $changeVsOpenPct >= -0.5;

                if (! $liquidityPass) {
                    $rejectionReasons[$ticker] = 'AKTIVITAS_VOLUME_KURANG (Volume '.number_format($volume, 0, ',', '.').' < 10.000.000 lembar)';
                } elseif (! $rangePass) {
                    $rejectionReasons[$ticker] = $rangePct < 1.5
                        ? 'RENTANG_BAR_TERLALU_SEMPIT ('.round($rangePct, 2).'% < 1.5%)'
                        : 'RENTANG_BAR_TERLALU_LEBAR ('.round($rangePct, 2).'% > 6.5%)';
                } elseif (! $directionPass) {
                    $rejectionReasons[$ticker] = 'PERUBAHAN_PENUTUPAN_VS_PEMBUKAAN_TERLALU_RENDAH ('.round($changeVsOpenPct, 2).'% < -0.5%)';
                }

                if ($liquidityPass && $rangePass && $directionPass) {
                    $score = ($rangePct * 1.5) + ($changeVsOpenPct * 2.0);

                    // Check if ML inference matches the current bar date
                    $barDateStr = $obs->bar_date->toDateString();
                    $isMatchingBarDate = $sig && ($sig->decision_date?->toDateString() === $barDateStr);
                    $isValidatedUniverse = array_key_exists($ticker, BeiUniverseRegistry::VALIDATED_ML_UNIVERSE);

                    if ($isMatchingBarDate) {
                        $mlStatus = $sig->signal_status;
                        $maxProb = (float) $sig->max_probability;
                        $confScore = (float) $sig->confidence_score;
                        $mlConfidenceStr = round($confScore * 100, 2).'%';
                        $mlNote = "Model {$sig->model_name} {$sig->model_version} (Decision: {$sig->decision_date?->toDateString()}, Horizon: {$sig->predicted_horizon_days}d)";
                    } elseif ($isValidatedUniverse && $sig) {
                        // Historical prediction expired, no fresh inference on latest bar
                        $mlStatus = 'NO_ACTIVE_INFERENCE_FOR_BAR';
                        $maxProb = null;
                        $confScore = null;
                        $mlConfidenceStr = 'N/A';
                        $mlNote = "Inferensi historis {$sig->model_name} {$sig->model_version} ({$sig->decision_date?->toDateString()}, H+{$sig->predicted_horizon_days}) telah kadaluarsa. Tidak ada inferensi aktif untuk bar {$barDateStr}.";
                    } else {
                        $mlStatus = 'MODEL_NOT_VALIDATED_FOR_INSTRUMENT';
                        $maxProb = null;
                        $confScore = null;
                        $mlConfidenceStr = 'N/A';
                        $mlNote = 'Instrumen di luar 10 konstituen ML tervalidasi. Status N/A.';
                    }

                    $targetMath = round($high * 1.01, 2);
                    $targetTick = self::roundToBeiTick($targetMath, true);

                    $candidates[] = [
                        'ticker' => $ticker,
                        'contract_label' => 'KANDIDAT_LOLOS_FILTER',
                        'close_price' => $close,
                        'bar_date' => $barDateStr,
                        'bar_finality' => $obs->classification ?: 'EOD_FINAL',
                        'observation_id' => $obs->id,
                        'indicator_calculated_at' => $metric?->created_at?->toIso8601String() ?? 'N/A',
                        'metric_id' => $metric?->id ?? 'N/A',
                        'range_pct' => round($rangePct, 2),
                        'change_pct' => round($changeVsOpenPct, 2),
                        'volume' => $volume,
                        'score' => round($score, 2),
                        'ml_status' => $mlStatus,
                        'ml_max_probability' => $maxProb !== null ? round($maxProb * 100, 2).'%' : 'N/A',
                        'ml_confidence' => $mlConfidenceStr,
                        'ml_note' => $mlNote,
                        'support_level' => $low,
                        'resistance_level' => $high,
                        'target_price_math' => $targetMath,
                        'target_price_tick' => $targetTick,
                        'target_price' => $targetMath,
                        'invalidation_price' => $low,
                        'evaluation_horizon_days' => 5,
                        'friction_scenario_pct' => 0.80,
                        'friction_allowance_pct' => 0.80,
                        'ambiguous_rule' => 'AMBIGUOUS jika target dan invalidation tersentuh bersamaan pada candle yang sama tanpa urutan intraday.',
                        'invalidation_condition' => 'Tesis swing batal jika harga Low menembus di bawah batas Low bar (Rp '.number_format($low, 2, ',', '.').').',
                        'reasons' => [
                            'Aktivitas volume aktif '.number_format($volume, 0, ',', '.').' lembar (ambang >= 10.000.000 lembar).',
                            'Rentang bar '.round($rangePct, 2).'% dalam batas volatilitas dinamis (1.5% - 6.5%).',
                            'Perubahan harga penutupan relatif terhadap pembukaan terkendali ('.($changeVsOpenPct >= 0 ? '+' : '').round($changeVsOpenPct, 2).'%, ambang >= -0.5%).',
                        ],
                    ];
                }
            } else {
                // DIVIDEND / DEFENSIVE strategy
                $defensiveTickers = ['BBCA', 'BBRI', 'BMRI', 'BBNI', 'TLKM', 'ICBP'];
                if (in_array($ticker, $defensiveTickers) && $volume >= 5_000_000) {
                    $candidates[] = [
                        'ticker' => $ticker,
                        'contract_label' => 'KANDIDAT_LOLOS_FILTER',
                        'close_price' => $close,
                        'bar_date' => $obs->bar_date->toDateString(),
                        'bar_finality' => $obs->classification ?: 'EOD_FINAL',
                        'observation_id' => $obs->id,
                        'indicator_calculated_at' => $metric?->created_at?->toIso8601String() ?? 'N/A',
                        'metric_id' => $metric?->id ?? 'N/A',
                        'range_pct' => round($rangePct, 2),
                        'change_pct' => round($changeVsOpenPct, 2),
                        'volume' => $volume,
                        'score' => 100.0,
                        'ml_status' => 'ABSTAIN',
                        'ml_max_probability' => 'N/A',
                        'ml_confidence' => 'N/A',
                        'ml_note' => 'Strategi defensif dividen non-ML.',
                        'support_level' => $low,
                        'resistance_level' => $high,
                        'target_price' => round($high * 1.01, 2),
                        'invalidation_price' => $low,
                        'evaluation_horizon_days' => 20,
                        'friction_allowance_pct' => 0.80,
                        'ambiguous_rule' => 'AMBIGUOUS jika target dan invalidation tersentuh bersamaan.',
                        'invalidation_condition' => 'Tesis defensif batal jika ada revisi penurunan dividen signifikan atau pemburukan laba bersih kuartalan.',
                        'reasons' => [
                            'Konstituen berkapitalisasi besar dengan rekam jejak dividen dan neraca mapan.',
                            'Likuiditas transaksi pasar primer sangat tinggi.',
                        ],
                    ];
                }
            }
        }

        // Sort candidates by score descending
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        return [
            'strategy' => $strategy,
            'strategy_version' => self::STRATEGY_VERSION,
            'outputs' => [
                'study_a_price_barrier_touch' => [
                    'status' => 'ACTIVE',
                    'description' => 'Studi sentuhan ambang batas harga matematis (High * 1.01 vs Low) dilaporkan secara bruto tanpa asumsi eksekusi.',
                ],
                'simulation_b_execution_strategy' => [
                    'status' => 'DOCUMENTED_PENDING_INTRADAY_VERIFICATION',
                    'description' => 'Simulasi eksekusi transaksi berbasis fraksi BEI. Return transaksi neto ditahan sampai data antrean bid/ask diverifikasi.',
                ],
            ],
            'criteria' => [
                'activity_volume' => 'Filter aktivitas volume >= 10.000.000 lembar saham',
                'volatility_range' => '1.5% <= (High - Low) / Close * 100 <= 6.5%',
                'close_vs_open' => '(Close - Open) / Open * 100 >= -0.5%',
                'universe_filter' => 'Saham ekuitas tercatat BEI saja (Indeks ^JKSE/JKSE/IHSG dikecualikan)',
            ],
            'evaluation_rules' => [
                'target_formula_math' => 'High * 1.01 (Ambang batas matematis studi sentuhan level)',
                'target_formula_tick' => 'CeilToBeiTick(High * 1.01) (Level order transaksi simulasi fraksi BEI)',
                'invalidation_formula' => 'Low bar candle acuan',
                'cohort_formation_time' => '2026-10-26 19:30:00 WIB',
                'protocol_refinement_time' => '2026-10-26 20:30:00 WIB',
                'config_hash_v1_frozen' => 'c51fa69e06b9b32c',
                'config_hash_v1_refinement' => '7e4d82b1fa90c37e',
                'forward_observation_start' => '2026-10-27 09:00:00 WIB (Sesi T+1 bursa berikutnya)',
                'horizon_days' => 5,
                'calendar_provider' => 'BeiCalendarService (Kalender Resmi Hari Bursa BEI 2026)',
                'evaluation_end_date' => '2026-11-02 16:00:00 WIB (Akhir sesi T+5)',
                'data_finality_rule' => 'Setelah 02-Nov-2026 16:00 WIB, jika bar EOD final belum tersimpan di DB, status WAITING_FOR_FINAL_DATA (tidak memaksakan outcome)',
                'friction_scenario_pct' => 0.80,
                'friction_description' => 'Asumsi skenario 0.80% (biaya transaksi bursa 0.40% round-trip + estimasi slippage 0.40%), bukan biaya universal',
                'ambiguous_candle_rule' => 'Jika target dan invalidation tersentuh pada bar yang sama tanpa urutan intraday, catat AMBIGUOUS',
                'gap_rule' => 'Gap up >= target membatalkan entry simulasi. Gap down <= stop mengeksekusi exit pada harga Open sesi.',
                'zero_buffer_rule' => 'Jika Close = Low bar acuan, posisi berstatus ZERO_TOLERANCE_INVALIDATION dan diskualifikasi dari simulasi.',
                'freshness_policy_next_cohort' => 'max_staleness_trading_days = 0 (hanya bar T0 hari evaluasi yang valid untuk kohort baru)',
            ],
            'inspected_universe' => $inspectedUniverse,
            'inspected_count' => count($inspectedUniverse),
            'total_registered_stocks' => $totalRegisteredStocks,
            'missing_data' => $missingData,
            'rejection_reasons' => $rejectionReasons,
            'as_of' => Carbon::now('Asia/Jakarta')->format('d M Y, H:i').' WIB',
            'candidates' => $candidates,
        ];
    }

    /**
     * Compare 2 to 4 tickers deterministically.
     */
    public function compareTickers(array $tickers): array
    {
        $comparison = [];
        foreach (array_slice($tickers, 0, 4) as $tk) {
            $ticker = strtoupper(trim($tk));
            $obs = $this->marketRead->latest($ticker);
            $sig = PredictionSignal::where('ticker', $ticker)->latest('decision_date')->first();
            $newsCount = NewsItem::where('related_tickers', 'like', "%{$ticker}%")->count();

            if (! $obs) {
                $comparison[] = [
                    'ticker' => $ticker,
                    'has_data' => false,
                    'message' => 'Data observasi pasar belum tersedia di database.',
                ];

                continue;
            }

            $close = (float) $obs->close_price;
            $open = (float) $obs->open_price;
            $high = (float) $obs->high_price;
            $low = (float) $obs->low_price;
            $rangePct = $close > 0 ? (($high - $low) / $close) * 100 : 0.0;
            $changePct = $open > 0 ? (($close - $open) / $open) * 100 : 0.0;

            $comparison[] = [
                'ticker' => $ticker,
                'has_data' => true,
                'company_name' => BeiUniverseRegistry::KNOWN_BEI_ISSUERS[$ticker] ?? $ticker,
                'bar_date' => $obs->bar_date->toDateString(),
                'close_price' => $close,
                'range_pct' => round($rangePct, 2),
                'change_pct' => round($changePct, 2),
                'volume' => (int) $obs->volume,
                'state' => $this->marketRead->state($obs),
                'ml_signal' => $sig ? $sig->signal_status : 'MODEL_NOT_VALIDATED_FOR_INSTRUMENT',
                'ml_confidence' => $sig ? round((float) $sig->confidence_score * 100, 2).'%' : 'N/A',
                'news_count' => $newsCount,
                'observation_id' => $obs->id,
            ];
        }

        return $comparison;
    }

    /**
     * Format clean Indonesian screening summary.
     */
    public function formatScreeningResponse(string $strategy = 'SWING'): string
    {
        $res = $this->screenCandidates($strategy);
        $universeCount = count($res['inspected_universe']);
        $universePreview = implode(', ', array_slice($res['inspected_universe'], 0, 10)).($universeCount > 10 ? ' ... (+'.($universeCount - 10).' lainnya)' : '');

        $lines = [
            "SCREENING PASAR SAHAM BEI — STRATEGI {$res['strategy']} ({$res['strategy_version']})",
            '──────────────────────────────────────────',
            "• Waktu Analisis : {$res['as_of']}",
            "• Cakupan Data   : {$res['inspected_count']} emiten aktif dievaluasi dari {$res['total_registered_stocks']} saham tercatat BEI (Indeks ^JKSE tidak dihitung)",
            "• Universe Dicek : {$universePreview}",
            '• Status Kontrak : KANDIDAT LOLOS FILTER (Bukan rekomendasi terbukti & bukan sinyal ML)',
            '• Format Keluaran: Output A (Sentuhan Barrier Bruto: AKTIF) | Output B (Simulasi Transaksi: Terdokumentasi, return neto ditahan)',
            '• Kriteria Filter: Aktivitas volume >= 10M, Rentang 1.5%-6.5%, Penutupan vs Pembukaan >= -0.5%',
            '• Asumsi Friksi  : Asumsi skenario 0.80% (biaya transaksi bursa 0.40% round-trip + estimasi slippage 0.40%), bukan biaya universal',
            '• Finalitas Data : WAITING_FOR_FINAL_DATA jika bar EOD final belum tersimpan di DB saat deadline',
            '• Aturan Ambigu  : AMBIGUOUS jika target & pembatalan tersentuh pada candle sama',
            '──────────────────────────────────────────',
        ];

        if (empty($res['candidates'])) {
            $lines[] = 'Tidak ada emiten yang memenuhi ambang batas parameter strategi saat ini.';
            $lines[] = 'Sistem memprioritaskan perlindungan modal (disiplin defensif).';

            return implode("\n", $lines);
        }

        $lines[] = "KANDIDAT LOLOS FILTER ({$res['strategy']}):";
        foreach (array_slice($res['candidates'], 0, 3) as $idx => $c) {
            $num = $idx + 1;
            $chgSign = $c['change_pct'] >= 0 ? '+' : '';
            $lines[] = "{$num}. {$c['ticker']} — Rp ".number_format($c['close_price'], 2, ',', '.')." [{$c['contract_label']}]";
            $lines[] = "   • Bar Pasar   : {$c['bar_date']} ({$c['bar_finality']}) | Obs ID: {$c['observation_id']}";
            $lines[] = "   • Indikator   : Dihitung {$c['indicator_calculated_at']} | Metric ID: {$c['metric_id']}";
            $lines[] = "   • Pergerakan  : Rentang bar {$c['range_pct']}% | Penutupan vs Pembukaan: {$chgSign}{$c['change_pct']}%";
            $lines[] = '   • Aktivitas   : Volume '.number_format($c['volume'], 0, ',', '.').' lembar';
            $lines[] = "   • Status ML   : {$c['ml_status']} (Keyakinan: {$c['ml_confidence']})";
            $lines[] = "   • Catatan ML  : {$c['ml_note']}";
            $lines[] = '   • Level Kunci : Target Math Rp '.number_format($c['target_price_math'] ?? $c['target_price'], 2, ',', '.').' | Order Tick BEI Rp '.number_format($c['target_price_tick'] ?? $c['target_price'], 2, ',', '.').' | Batal Rp '.number_format($c['invalidation_price'], 2, ',', '.');
            $lines[] = "   • Pembatalan  : {$c['invalidation_condition']}";
            $lines[] = '   • Alasan Lolos: '.implode(' ', $c['reasons']);
        }

        $lines[] = '──────────────────────────────────────────';
        $lines[] = 'PERINGATAN RISIKO: Hasil filter ini merupakan observasi kuantitatif sebelum outcome (forward study). Return harga bukan keuntungan aktual.';

        return implode("\n", $lines);
    }

    /**
     * Format clean Indonesian comparison summary.
     */
    public function formatComparisonResponse(array $tickers): string
    {
        $comp = $this->compareTickers($tickers);

        $lines = [
            'KOMPARASI DATA PASAR EMITEN BEI',
            '──────────────────────────────────────────',
        ];

        foreach ($comp as $c) {
            if (! ($c['has_data'] ?? false)) {
                $lines[] = "• {$c['ticker']}: Data belum tersimpan di database.";

                continue;
            }

            $chgSign = $c['change_pct'] >= 0 ? '+' : '';
            $lines[] = "• {$c['ticker']} ({$c['company_name']}) — Rp ".number_format($c['close_price'], 2, ',', '.');
            $lines[] = "  Bar: {$c['bar_date']} ({$c['state']}) | Obs ID: {$c['observation_id']}";
            $lines[] = "  Fluktuasi Bar: Rentang {$c['range_pct']}% | vs Open: {$chgSign}{$c['change_pct']}%";
            $lines[] = '  Volume: '.number_format($c['volume'], 0, ',', '.')." lembar | Berita Terkini: {$c['news_count']} artikel";
            $lines[] = "  Status Model ML: {$c['ml_signal']} (Keyakinan: {$c['ml_confidence']})";
        }

        $lines[] = '──────────────────────────────────────────';
        $lines[] = 'Sumber Data: PostgreSQL CanonicalMarketReadService (Rp0 Biaya Data, Tanpa Transaksi Broker).';

        return implode("\n", $lines);
    }

    /**
     * Register screening/Gemini candidates as formal PredictionSignal records
     * so they are tracked by MarketMonitoringService under an immutable outcome contract.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, PredictionSignal>
     */
    public function registerCandidatesAsPredictions(array $candidates, ?Carbon $publishedAt = null): array
    {
        $now = $publishedAt ?? Carbon::now('Asia/Jakarta');
        $calendar = app(BeiCalendarService::class);
        $saved = [];

        foreach ($candidates as $c) {
            $ticker = $c['ticker'] ?? null;
            if (! $ticker) {
                continue;
            }
            $barDate = $c['bar_date'] ?? $now->toDateString();
            $predId = 'REC_SCREEN_'.$ticker.'_'.str_replace('-', '', $barDate);
            $refPrice = (float) ($c['close_price'] ?? 0);
            $targetPrice = (float) ($c['target_price_tick'] ?? ($c['target_price'] ?? ($refPrice * 1.01)));
            $invalPrice = (float) ($c['invalidation_price'] ?? ($refPrice * 0.96));
            $dueTradingDay = $calendar->addTradingDays($barDate, 5);

            $sig = PredictionSignal::updateOrCreate(
                ['prediction_id' => $predId],
                [
                    'ticker' => $ticker,
                    'signal_status' => 'CANDIDATE',
                    'direction' => 'UP',
                    'probability_up' => 0.5000,
                    'probability_down' => 0.5000,
                    'max_probability' => 0.5000,
                    'confidence_score' => 0.0000,
                    'threshold_applied' => 0.7000,
                    'model_name' => 'SCREENING_RULE_SWING',
                    'model_version' => self::STRATEGY_VERSION,
                    'feature_schema_hash' => md5('SCREENING_SWING_v1.0.0-frozen'),
                    'decision_date' => $barDate,
                    'decision_timestamp' => $now,
                    'reference_price' => $refPrice,
                    'reference_price_date' => $barDate,
                    'predicted_horizon_days' => 5,
                    'evaluation_due_date' => $dueTradingDay->toDateString(),
                    'published_at' => $now,
                    'expires_at' => $now->copy()->addDays(14),
                    'monitoring_status' => 'ACTIVE',
                    'evaluation_status' => 'PENDING',
                    'target_price' => $targetPrice,
                    'invalidation_price' => $invalPrice,
                    'metadata' => [
                        'strategy' => 'SWING',
                        'strategy_version' => self::STRATEGY_VERSION,
                        'ml_status' => $c['ml_status'] ?? 'MODEL_NOT_VALIDATED_FOR_INSTRUMENT',
                        'reasons' => $c['reasons'] ?? [],
                        'source' => 'DETERMINISTIC_SCREENING_RULE',
                    ],
                ]
            );

            $saved[] = $sig;
        }

        return $saved;
    }
}
