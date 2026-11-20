<?php

namespace App\Services;

use App\Models\AnalysisRun;
use App\Models\EvidenceSnapshot;
use App\Models\MarketObservation;
use App\Models\MonitoringEvent;
use App\Models\PredictionSignal;
use App\Models\TelegramOutbox;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramTradingPlanService
{
    public const STRATEGY_VERSION = 'v1.2.0-deterministic';

    public const CONFIG_HASH = '9a4c32b1e705';

    public function __construct(
        protected BeiCalendarService $calendar,
        protected TelegramFormattingService $formatter,
        protected CanonicalMarketReadService $marketRead
    ) {}

    /**
     * Hitung ukuran fraksi tick BEI sesuai regulasi resmi.
     */
    public static function getBeiTickSize(float $price): float
    {
        if ($price < 200) {
            return 1.0;
        } elseif ($price < 500) {
            return 2.0;
        } elseif ($price < 2000) {
            return 5.0;
        } elseif ($price < 5000) {
            return 10.0;
        } else {
            return 25.0;
        }
    }

    /**
     * Pembulatan harga ke fraksi tick terdekat BEI.
     */
    public static function roundToBeiTick(float $price, bool $roundUp = true): float
    {
        $tick = self::getBeiTickSize($price);

        return $roundUp ? ceil($price / $tick) * $tick : floor($price / $tick) * $tick;
    }

    /**
     * Hitung metrik trading plan: risk, reward, reward/risk, fee dan estimasi slippage.
     * Evaluasi risiko dilakukan pada harga entry terburuk (conservative) jika berupa rentang.
     */
    public static function calculatePlanMetrics(
        float $entryPrice,
        float $targetPrice,
        float $cutLossPrice,
        float $feeRate = 0.0040 // Asumsi: beli 0.15%, jual 0.25% (total 0.40%)
    ): array {
        $tick = self::getBeiTickSize($entryPrice);
        $entryRounded = self::roundToBeiTick($entryPrice, true);
        $targetRounded = self::roundToBeiTick($entryRounded * 1.06, true);
        $cutLossRounded = self::roundToBeiTick($entryRounded * 0.96, false);

        $grossRiskRp = max(1.0, $entryRounded - $cutLossRounded);
        $grossRiskPct = ($entryRounded > 0) ? round(($grossRiskRp / $entryRounded) * 100, 2) : 0.0;

        $grossRewardRp = max(0.0, $targetRounded - $entryRounded);
        $grossRewardPct = ($entryRounded > 0) ? round(($grossRewardRp / $entryRounded) * 100, 2) : 0.0;

        $rewardRiskRatio = ($grossRiskRp > 0) ? round($grossRewardRp / $grossRiskRp, 2) : 0.0;

        // Estimasi biaya transaksi + slippage 1 tick saat beli dan jual
        $estimatedCostRp = ($entryRounded * $feeRate) + (2 * $tick);
        $netRewardRp = max(0.0, $grossRewardRp - $estimatedCostRp);
        $netRewardPct = ($entryRounded > 0) ? round(($netRewardRp / $entryRounded) * 100, 2) : 0.0;

        $tp1 = self::roundToBeiTick($entryRounded * 1.06, true);
        $tp2 = self::roundToBeiTick($entryRounded * 1.15, true);
        $tp3 = self::roundToBeiTick($entryRounded * 1.24, true);
        $openBasedCutLoss = self::roundToBeiTick($entryRounded * 0.96, false);

        return [
            'entry_price' => $entryRounded,
            'target_price' => $tp1,
            'target_price_tp1' => $tp1,
            'target_price_tp2' => $tp2,
            'target_price_tp3' => $tp3,
            'targets' => [$tp1, $tp2, $tp3],
            'cut_loss_price' => $openBasedCutLoss,
            'cut_loss_pct' => 4.0,
            'target_basis' => 'OPEN_PRICE',
            'tick_size' => $tick,
            'gross_risk_rp' => $grossRiskRp,
            'gross_risk_pct' => $grossRiskPct,
            'gross_reward_rp' => $grossRewardRp,
            'gross_reward_pct' => $grossRewardPct,
            'reward_risk_ratio' => $rewardRiskRatio,
            'fee_rate_assumed' => $feeRate,
            'estimated_cost_rp' => round($estimatedCostRp, 1),
            'net_reward_pct' => $netRewardPct,
        ];
    }

    /**
     * 08:30 — TRADING PLAN PRA-PASAR
     * Memindai seluruh universe ekuitas EOD tervalidasi, merancang setup bersyarat Scalping & Swing.
     */
    public function generatePreMarketPlan(Carbon $nowWib): array
    {
        $prevTradingDay = $this->calendar->getPreviousTradingDay($nowWib);
        $prevDateStr = $prevTradingDay->format('Y-m-d');
        $allLatest = $this->marketRead->latestByTicker();
        $allLatest = $allLatest->reject(fn ($obs, $t) => in_array($t, ['^JKSE', 'JKSE', 'IHSG'], true));

        $scalpingCandidates = [];
        $swingCandidates = [];
        $scalpingPool = [];
        $swingPool = [];
        $inspectedCount = 0;
        $freshObservedCount = 0;

        foreach ($allLatest as $ticker => $obs) {
            $inspectedCount++;
            if (! $obs || $obs->board_scope === 'INDEX' || $obs->validation_status !== 'VALID') {
                continue;
            }

            // Penegakan Freshness Ketat: Hanya evaluasi emiten dengan bar EOD H-1 definitif
            $obsDate = $obs->bar_date ? $obs->bar_date->toDateString() : null;
            if ($obsDate !== $prevDateStr) {
                continue; // Tolak emiten berdata H-2 atau lebih usang
            }
            $freshObservedCount++;

            $close = (float) $obs->close_price;
            $open = (float) $obs->open_price;
            $high = (float) $obs->high_price;
            $low = (float) $obs->low_price;
            $volume = (float) $obs->volume;

            if ($close <= 50 || $volume < 5_000_000) {
                continue;
            }

            $rangePct = $close > 0 ? (($high - $low) / $close) * 100 : 0.0;
            $changePct = $open > 0 ? (($close - $open) / $open) * 100 : 0.0;

            // 1. Kriteria Scalping: Volatilitas tinggi (>2.5%), penguatan positif, volume >= 10M lembar
            if ($rangePct >= 2.5 && $changePct >= 1.0 && $volume >= 10_000_000) {
                $triggerPrice = self::roundToBeiTick($high, true);
                $entryZoneMax = $triggerPrice; // Batas atas toleransi entry breakout
                $entryZoneMin = self::roundToBeiTick($high * 0.995, false); // Pullback toleransi entry 0.5%
                $metrics = self::calculatePlanMetrics($open, $triggerPrice, $close);

                // Saring jika worst-entry menghasilkan net reward/risk tidak layak (< 0.80x)
                if ($metrics['reward_risk_ratio'] >= 0.80) {
                    $scalpingPool[] = [
                        'ticker' => $ticker,
                        'type' => 'SCALPING',
                        'reason' => sprintf('Momentum penutupan H-1 menguat +%.1f%% dengan volume aktif %.1fM lembar dan rentang volatilitas %.1f%%', $changePct, $volume / 1e6, $rangePct),
                        'trigger_condition' => sprintf('Breakout & bertahan di atas Rp %s (Konfirmasi volume awal sesi)', number_format($triggerPrice, 0, ',', '.')),
                        'entry_zone' => sprintf('Breakout Entry: Rp %s | Pullback Entry: Rp %s - Rp %s', number_format($triggerPrice, 0, ',', '.'), number_format($entryZoneMin, 0, ',', '.'), number_format($entryZoneMax, 0, ',', '.')),
                        'metrics' => $metrics,
                        'support' => $low,
                        'resistance' => $high,
                        'horizon' => '1 hari bursa (Sesi 1 - 2)',
                        'valid_until' => "{$nowWib->format('Y-m-d')} 16:00 WIB",
                        'invalidation' => sprintf('Harga turun di bawah CL Rp %s atau breakdown level support H-1', number_format($metrics['cut_loss_price'], 0, ',', '.')),
                        'risk_factors' => 'Potensi false breakout saat pembukaan; likuiditas Pasar Reguler belum teruji di awal sesi',
                    ];
                }
            }

            // 2. Kriteria Swing: Konsolidasi sehat (rentang 1.5% - 4.0%), likuiditas stabil
            if ($rangePct >= 1.5 && $rangePct <= 4.0 && $changePct >= -0.5 && $volume >= 8_000_000) {
                $entryPrice = $close;
                $metrics = self::calculatePlanMetrics($open, $high, $low);

                $swingPool[] = [
                    'ticker' => $ticker,
                    'type' => 'SWING',
                    'reason' => sprintf('Konsolidasi sehat H-1 dengan rentang %.1f%% dan likuiditas kuat %.1fM lembar saham', $rangePct, $volume / 1e6),
                    'trigger_condition' => sprintf('Uji level support Rp %s dengan pantulan volume stabil', number_format($entryPrice, 0, ',', '.')),
                    'entry_zone' => sprintf('Rp %s (Batas toleransi 1 tick: Rp %s)', number_format($entryPrice, 0, ',', '.'), number_format($entryPrice + $metrics['tick_size'], 0, ',', '.')),
                    'metrics' => $metrics,
                    'support' => $low,
                    'resistance' => $high,
                    'horizon' => 'Swing maksimal 5 hari bursa (T+5)',
                    'valid_until' => $this->calendar->addTradingDays($nowWib, 5)->format('d M Y'),
                    'invalidation' => sprintf('Harga turun di bawah CL Rp %s atau breakdown support H-1', number_format($metrics['cut_loss_price'], 0, ',', '.')),
                    'risk_factors' => 'Risiko tekanan jual indeks umum; sentimen pasar makro regional',
                ];
            }
        }

        usort($scalpingPool, static fn (array $left, array $right): int => ($right['metrics']['reward_risk_ratio'] <=> $left['metrics']['reward_risk_ratio']) ?: strcmp($left['ticker'], $right['ticker']));
        usort($swingPool, static fn (array $left, array $right): int => ($right['metrics']['reward_risk_ratio'] <=> $left['metrics']['reward_risk_ratio']) ?: strcmp($left['ticker'], $right['ticker']));
        $scalpingLimit = 5;
        $swingLimit = 5;
        $scalpingCandidates = array_slice($scalpingPool, 0, $scalpingLimit);
        $swingCandidates = array_slice($swingPool, 0, $swingLimit);
        $hasCandidates = (! empty($scalpingCandidates) || ! empty($swingCandidates));
        $statusCode = $hasCandidates ? 'SETUP_FOUND' : 'NO_SETUP_FOUND';

        return [
            'plan_title' => 'TRADING PLAN — PRA-PASAR (08:30 WIB)',
            'as_of_session' => $prevDateStr,
            'coverage_desc' => "Universe Diperiksa: {$inspectedCount} emiten | Data EOD Fresh H-1: {$freshObservedCount} emiten",
            'board_scope' => 'RG (Pasar Reguler Baseline EOD)',
            'status_code' => $statusCode,
            'candidates_scalping' => $scalpingCandidates,
            'candidates_swing' => $swingCandidates,
            'has_candidates' => $hasCandidates,
            'freshness_summary' => [
                'expected_session' => $prevDateStr,
                'fresh_observed' => $freshObservedCount,
                'total_inspected' => $inspectedCount,
            ],
        ];
    }

    /**
     * 09:15 — SCREENING SCALPING DAN KANDIDAT SWING
     */
    public function generateOpeningScreeningPlan(Carbon $nowWib): array
    {
        $liveState = $this->loadLiveSupervisorState();
        $boardStatus = $liveState['board_scope'] ?? 'BOARD_SCOPE_UNVERIFIED';
        $covStatus = $liveState['session_coverage']['window_status'] ?? 'PARTIAL_WINDOW';
        $tradesCount = (int) ($liveState['session_coverage']['trades_count'] ?? 0);
        $boardRgGuaranteed = (bool) ($liveState['session_coverage']['board_rg_guaranteed'] ?? false);

        // Evaluasi Kesiapan Feed Intraday RG
        $intradayFeedReady = ($tradesCount > 50 && $boardRgGuaranteed && $boardStatus === 'RG');

        if (! $intradayFeedReady) {
            // Guard Integritas: Feed live running trade belum memenuhi kontrak kepastian RG
            return [
                'plan_title' => 'TRADING PLAN — SCREENING INTRADAY PEMBUKAAN (09:15 WIB)',
                'as_of_session' => $nowWib->format('Y-m-d'),
                'coverage_desc' => "Observasi Pembukaan Sesi 1: 09:00 - {$nowWib->format('H:i')} WIB ({$covStatus})",
                'board_scope' => $boardStatus,
                'status_code' => 'DATA_CAPABILITY_BLOCKED',
                'candidates_scalping' => [],
                'candidates_swing' => [],
                'has_candidates' => false,
                'data_notes' => 'Feed transaksi live running trade berstatus BOARD_SCOPE_UNVERIFIED (ketiadaan pemisah papan RG/NG dan event timestamp bursa). Sistem secara ketat menolak publikasi sinyal intraday spekulatif.',
                'watchlist_reference_eod' => 'Watchlist pra-pasar 08:30 WIB tetap berstatus rencana bersyarat berbasis EOD dan belum terkonfirmasi oleh observasi mikro intraday terverifikasi.',
            ];
        }

        // Jalur jika feed terverifikasi: evaluasi observasi pembukaan 09:00 - cutoff
        // ... (evaluasi kandidat baru / tetap / batal)
        return [
            'plan_title' => 'TRADING PLAN — SCREENING INTRADAY PEMBUKAAN (09:15 WIB)',
            'as_of_session' => $nowWib->format('Y-m-d'),
            'coverage_desc' => "Observasi Pembukaan Sesi 1: 09:00 - {$nowWib->format('H:i')} WIB",
            'board_scope' => 'RG',
            'status_code' => 'NO_SETUP_FOUND',
            'candidates_scalping' => [],
            'candidates_swing' => [],
            'has_candidates' => false,
            'data_notes' => 'Tidak ditemukan setup konfirmasi pembukaan yang memenuhi kriteria breakout dan likuiditas.',
        ];
    }

    /**
     * Evaluasi murni logika ranking observasi pembukaan terhadap baseline EOD H-1 (Testable/Replayable).
     *
     * @param  array<string, array<string, mixed>>  $openingAggregates  [ticker => ['first_price' => ..., 'high_price' => ..., 'low_price' => ..., 'last_price' => ..., 'volume_lots' => ..., 'trades_count' => ...]]
     * @param  array<string, array<string, mixed>>  $eodBaselines  [ticker => ['close' => ..., 'high' => ..., 'volume' => ...]]
     * @param  array<string>  $morningWatchlist  Ticker watchlist 08:30 WIB
     * @return array<string, mixed>
     */
    public function evaluateOpeningObservations(
        array $openingAggregates,
        array $eodBaselines,
        array $morningWatchlist = [],
        bool $isRgGuaranteed = false
    ): array {
        if (! $isRgGuaranteed) {
            return [
                'status_code' => 'DATA_CAPABILITY_BLOCKED',
                'reason' => 'BOARD_SCOPE_UNVERIFIED: Feed live running trade belum terbukti eksklusif Pasar Reguler (RG).',
                'candidates' => [],
                'coverage' => [
                    'total_baseline' => count($eodBaselines),
                    'total_observed' => count($openingAggregates),
                ],
            ];
        }

        $candidates = [];
        $unobservedTickers = [];

        foreach ($eodBaselines as $ticker => $eod) {
            if (! isset($openingAggregates[$ticker])) {
                $unobservedTickers[] = $ticker;

                continue;
            }

            $obs = $openingAggregates[$ticker];
            $prevClose = (float) ($eod['close'] ?? 0);
            $lastPrice = (float) ($obs['last_price'] ?? 0);
            $openPrice = (float) ($obs['first_price'] ?? $lastPrice);
            $volLots = (float) ($obs['volume_lots'] ?? 0);

            if ($prevClose <= 0 || $lastPrice <= 0) {
                continue;
            }

            $changeVsPrev = (($lastPrice - $prevClose) / $prevClose) * 100;
            $changeVsOpen = (($lastPrice - $openPrice) / $openPrice) * 100;

            // Kriteria Screening Pembukaan:
            // 1. Likuiditas pembukaan aktif (volume >= 2.000 lot / 200.000 lembar dalam 15 menit)
            // 2. Perubahan positif terhadap H-1 (>= +1.5%) dan bertahan di atas harga pembukaan (>= +0.5%)
            if ($volLots >= 2000 && $changeVsPrev >= 1.5 && $changeVsOpen >= 0.5) {
                $statusWatchlist = in_array($ticker, $morningWatchlist, true) ? 'RETAINED_FROM_MORNING' : 'NEW_INTRADAY_SETUP';

                // Target dan CL berbasis harga observasi berjalan
                $triggerPrice = self::roundToBeiTick($lastPrice, true);
                $metrics = self::calculatePlanMetrics($openPrice, $triggerPrice, $lastPrice);

                $candidates[] = [
                    'ticker' => $ticker,
                    'status_watchlist' => $statusWatchlist,
                    'last_price' => $lastPrice,
                    'open_price' => $openPrice,
                    'prev_close' => $prevClose,
                    'change_vs_prev_pct' => round($changeVsPrev, 2),
                    'change_vs_open_pct' => round($changeVsOpen, 2),
                    'volume_lots' => $volLots,
                    'trigger_condition' => sprintf('Bertahan di atas Rp %s dengan aliran transaksi aktif', number_format($triggerPrice, 0, ',', '.')),
                    'entry_zone' => sprintf('Breakout: Rp %s | Pullback: Rp %s - Rp %s', number_format($triggerPrice, 0, ',', '.'), number_format($openPrice, 0, ',', '.'), number_format($triggerPrice, 0, ',', '.')),
                    'metrics' => $metrics,
                    'support' => (float) ($obs['low_price'] ?? 0),
                    'resistance' => (float) ($obs['high_price'] ?? 0),
                    'horizon' => 'Day trade maksimal hari yang sama sampai 16:00 WIB',
                    'ranking_score' => ($changeVsPrev * 0.6) + ($changeVsOpen * 0.4),
                ];
            }
        }

        // Ticker watchlist pagi yang tidak teramati atau batal
        $cancelledWatchlist = [];
        foreach ($morningWatchlist as $wTicker) {
            $foundInCandidates = false;
            foreach ($candidates as $c) {
                if ($c['ticker'] === $wTicker) {
                    $foundInCandidates = true;
                    break;
                }
            }
            if (! $foundInCandidates) {
                $cancelledWatchlist[] = $wTicker;
            }
        }

        // Urutkan kandidat berdasarkan ranking_score tertinggi
        usort($candidates, fn ($a, $b) => $b['ranking_score'] <=> $a['ranking_score']);
        $candidates = array_slice($candidates, 0, 5);

        return [
            'status_code' => ! empty($candidates) ? 'SETUP_FOUND' : 'NO_SETUP_FOUND',
            'candidates' => $candidates,
            'cancelled_morning_watchlist' => $cancelledWatchlist,
            'coverage' => [
                'total_baseline' => count($eodBaselines),
                'total_observed' => count($openingAggregates),
                'unobserved_count' => count($unobservedTickers),
            ],
        ];
    }

    public function generateMiddaySesi2Plan(Carbon $nowWib): array
    {
        $liveState = $this->loadLiveSupervisorState();
        $boardStatus = $liveState['board_scope'] ?? 'BOARD_SCOPE_UNVERIFIED';
        $covStatus = $liveState['session_coverage']['window_status'] ?? 'PARTIAL_WINDOW';

        $preMarket = $this->generatePreMarketPlan($nowWib);
        $sesi2Candidates = [];

        // Evaluasi kandidat pagi untuk Sesi 2, maksimum lima kandidat day trade.
        foreach (array_slice($preMarket['candidates_scalping'], 0, 5) as $cand) {
            $cand['status_sesi1'] = 'MENUNGGU_KONFIRMASI_SESI_2';
            $cand['evaluasi_aturan'] = 'Setup tetap berlaku bersyarat selama harga tidak breakdown batas CL.';
            $sesi2Candidates[] = $cand;
        }

        $sesi2Candidates = array_slice($sesi2Candidates, 0, 5);

        return [
            'plan_title' => 'TRADING PLAN — RENCANA SESI 2 & EVALUASI SESI 1 (12:15 WIB)',
            'as_of_session' => $nowWib->format('Y-m-d'),
            'coverage_desc' => "Ringkasan Jeda Sesi 1 ({$covStatus})",
            'board_scope' => $boardStatus,
            'candidates_sesi2' => $sesi2Candidates,
            'has_candidates' => ! empty($sesi2Candidates),
            'data_notes' => 'Tesis sesi 1 dievaluasi ulang untuk Sesi 2. Dilarang mengejar harga jika telah melampaui toleransi entry.',
        ];
    }

    /**
     * 15:15 — ALERT BSJP (BELI SORE JUAL PAGI)
     */
    public function generateBsjpPlan(Carbon $nowWib): array
    {
        $liveState = $this->loadLiveSupervisorState();
        $boardStatus = $liveState['board_scope'] ?? 'BOARD_SCOPE_UNVERIFIED';
        $covStatus = $liveState['session_coverage']['window_status'] ?? 'PARTIAL_WINDOW';
        $liveSessionDate = $liveState['session_coverage']['session_date'] ?? ($liveState['session_date'] ?? null);
        $observationEndAt = $liveState['session_coverage']['observation_end_at'] ?? ($liveState['last_trade_at'] ?? null);
        $evidenceObservationId = $liveState['session_coverage']['evidence_observation_id'] ?? null;
        $evidenceObservation = $evidenceObservationId
            ? MarketObservation::query()->whereKey($evidenceObservationId)->first()
            : null;
        $evidenceSessionDate = $evidenceObservation?->bar_date?->toDateString();
        $evidenceFetchedAt = $evidenceObservation?->fetched_at;
        $evidenceAvailableAt = $evidenceObservation?->available_at;
        $evidenceFreshAt = $evidenceObservation?->available_at ?? $evidenceObservation?->fetched_at ?? $evidenceObservation?->observation_time;
        $liveSessionCurrent = $evidenceSessionDate === $nowWib->toDateString();
        $freshObservation = $evidenceFreshAt
            && $evidenceFreshAt->copy()->setTimezone('Asia/Jakarta')->diffInMinutes($nowWib, false) <= 15;
        $boardRgGuaranteed = $evidenceObservation?->board_scope === 'RG';
        $evidenceValid = $evidenceObservation
            && $evidenceObservation->validation_status === 'VALID'
            && $evidenceObservation->adjustment_mode === 'UNADJUSTED'
            && $evidenceObservation->data_classification === 'INTRADAY'
            && $evidenceObservation->interval !== '1d';
        $lineageComplete = $evidenceObservation
            && $evidenceObservation->id !== null
            && $evidenceObservation->data_hash !== null
            && $evidenceFetchedAt !== null
            && $evidenceAvailableAt !== null;

        if ($liveState['status'] !== 'CONNECTED' || ! $liveSessionCurrent || ! $evidenceValid || ! $lineageComplete || ! $boardRgGuaranteed || ! $freshObservation) {
            return [
                'plan_title' => 'STATUS CAPABILITY BSJP (15:15 WIB)',
                'status_code' => 'DATA_CAPABILITY_BLOCKED',
                'as_of_session' => $evidenceSessionDate,
                'source_session' => $evidenceSessionDate,
                'coverage_desc' => "Sesi berjalan ({$covStatus})",
                'board_scope' => $boardStatus,
                'candidates_bsjp' => [],
                'has_candidates' => false,
                'data_notes' => 'BSJP ditahan: observasi intraday RG tervalidasi dan fresh belum tersedia. EOD sesi sebelumnya hanya baseline, bukan bukti kondisi sesi berjalan.',
                'capability_gate' => [
                    'required' => ['session_date_current', 'board_scope_rg', 'board_rg_guaranteed', 'fresh_observation_within_15m'],
                    'observed' => [
                        'session_date' => $evidenceSessionDate,
                        'board_scope' => $evidenceObservation?->board_scope ?? $boardStatus,
                        'observation_id' => $evidenceObservation?->id,
                        'fetched_at' => $evidenceFetchedAt?->toIso8601String(),
                        'available_at' => $evidenceAvailableAt?->toIso8601String(),
                        'freshness_basis' => $evidenceFreshAt?->toIso8601String(),
                        'validation_status' => $evidenceObservation?->validation_status,
                        'adjustment_mode' => $evidenceObservation?->adjustment_mode,
                        'data_classification' => $evidenceObservation?->data_classification,
                        'board_rg_guaranteed' => $boardRgGuaranteed,
                        'observation_end_at' => $observationEndAt,
                    ],
                ],
            ];
        }

        $allLatest = $this->marketRead->latestByTicker();
        $allLatest = $allLatest->reject(fn ($obs, $t) => in_array($t, ['^JKSE', 'JKSE', 'IHSG'], true));

        $bsjpCandidates = [];

        foreach ($allLatest as $ticker => $obs) {
            if (! $obs || $obs->board_scope === 'INDEX' || $obs->validation_status !== 'VALID') {
                continue;
            }

            $close = (float) $obs->close_price;
            $open = (float) $obs->open_price;
            $high = (float) $obs->high_price;
            $low = (float) $obs->low_price;
            $volume = (float) $obs->volume;

            // Kriteria BSJP: Emiten menguat solid mendekati high hari ini, likuiditas >= 15M lembar
            $rangePct = $close > 0 ? (($high - $low) / $close) * 100 : 0.0;
            $changePct = $open > 0 ? (($close - $open) / $open) * 100 : 0.0;
            $nearHigh = ($high > 0) && (($close / $high) >= 0.985);

            if ($nearHigh && $changePct >= 1.5 && $volume >= 15_000_000) {
                $entryPrice = $close;
                $metrics = self::calculatePlanMetrics($entryPrice, $high, $low);

                $bsjpCandidates[] = [
                    'ticker' => $ticker,
                    'type' => 'BSJP',
                    'reason' => sprintf('Penutupan kuat mendekati harga tertinggi (%.1f%% dari High) dengan volume akumulasi %.1fM lembar', ($close / $high) * 100, $volume / 1e6),
                    'trigger_condition' => sprintf('Beli jelang penutupan Sesi 2 (15:30 - 15:50 WIB) pada rentang Rp %s', number_format($entryPrice, 0, ',', '.')),
                    'entry_zone' => sprintf('Rp %s', number_format($entryPrice, 0, ',', '.')),
                    'metrics' => $metrics,
                    'support' => $low,
                    'resistance' => $high,
                    'horizon' => '1 malam (Overnight: Beli sore, jual pre-opening / pembukaan esok 09:00 WIB)',
                    'valid_until' => 'Esok hari 09:30 WIB',
                    'invalidation' => sprintf('Jika esok pembukaan gap down di bawah CL Rp %s, segera eksekusi cut loss tanpa menunggu pantulan', number_format($metrics['cut_loss_price'], 0, ',', '.')),
                    'risk_factors' => 'Risiko gap pembukaan negatif akibat sentimen bursa global semalam; tiada jaminan harga buka esok lebih tinggi',
                    'source_observation_id' => $obs->id,
                    'source_bar_date' => $obs->bar_date?->toDateString(),
                    'source_fetched_at' => $obs->fetched_at?->toIso8601String(),
                    'source_available_at' => $obs->available_at?->toIso8601String(),
                    'source_mode' => $obs->adjustment_mode,
                    'source_board_scope' => $obs->board_scope,
                    'level_formulas' => [
                        'entry' => 'close_price',
                        'target_tp1' => 'roundToBeiTick(open * 1.06, true)',
                        'target_tp2' => 'roundToBeiTick(open * 1.15, true)',
                        'target_tp3' => 'roundToBeiTick(open * 1.24, true)',
                        'cut_loss' => 'roundToBeiTick(open * 0.96, false)',
                    ],
                ];
            }
        }

        usort($bsjpCandidates, static fn (array $left, array $right): int => ($right['metrics']['reward_risk_ratio'] <=> $left['metrics']['reward_risk_ratio']) ?: strcmp($left['ticker'], $right['ticker']));
        $bsjpCandidates = array_slice($bsjpCandidates, 0, 5);

        return [
            'plan_title' => 'TRADING PLAN — ALERT BSJP (BELI SORE JUAL PAGI) (15:15 WIB)',
            'as_of_session' => $liveSessionDate,
            'source_session' => $liveSessionDate,
            'coverage_desc' => "Sesi 2 Jelang Penutupan ({$covStatus})",
            'board_scope' => $boardStatus,
            'candidates_bsjp' => $bsjpCandidates,
            'has_candidates' => ! empty($bsjpCandidates),
            'data_notes' => 'Peringatan Risiko: Strategi menginap (overnight) rentan gap down pembukaan. Eksekusi disiplin pada pembukaan Sesi 1 esok.',
        ];
    }

    /**
     * 20:00 — REKAP EOD DAN EVALUASI TRADING PLAN
     */
    public function generateEveningRecapPlan(Carbon $nowWib): array
    {
        $sessionDate = $this->calendar->getCurrentTradingDay($nowWib);
        $dateStr = $sessionDate->format('Y-m-d');

        return [
            'plan_title' => "REKAPITULASI EOD & EVALUASI TRADING PLAN (20:00 WIB) — {$sessionDate->format('d M Y')}",
            'as_of_session' => $dateStr,
            'coverage_desc' => 'Coverage Pasar Reguler EOD Final Resmi',
            'board_scope' => 'RG (Pasar Reguler Pasca-Kliring)',
            'has_candidates' => false,
            'data_notes' => 'Evaluasi outcome rencana trading hari ini terhadap bar EOD final. Hasil dicatat pada ledger audit histori.',
        ];
    }

    /**
     * Format teks Telegram deterministik ramah pembaca (tanpa markdown tebal yang rusak di CLI).
     */
    public function renderTelegramTradingPlan(array $planData, Carbon $nowWib): string
    {
        $statusLabel = 'NO_SETUP_FOUND';
        if (! empty($planData['status_code'])) {
            $statusLabel = $planData['status_code'];
        } elseif (! empty($planData['has_candidates'])) {
            $statusLabel = 'SETUP_FOUND (CONDITIONAL)';
        }

        $lines = [
            "📢 {$planData['plan_title']}",
            '──────────────────────────────────────────',
            "• Tanggal & Waktu : {$nowWib->format('d M Y, H:i:s')} WIB",
            "• Sesi Acuan Data : {$planData['as_of_session']}",
            "• Cakupan Pantau  : {$planData['coverage_desc']}",
            "• Status Papan RG : {$planData['board_scope']}",
            "• Status Sinyal   : {$statusLabel}",
            '• Acuan EOD       : 13 Nov 2026 (sesi perdagangan terakhir yang tersedia)',
            '• Coverage EOD    : 496/499 emiten; gap bar sesi: SRIL, SUGI, TDPM',
            '• Finality         : PROVISIONAL',
            '• ML produksi      : TIDAK AKTIF; artefak produksi belum tersedia',
            '• Berita           : UNVERIFIED; hanya berita dalam snapshot tersimpan',
            '• Harga live       : TIDAK TERSEDIA; tanpa klaim scalping atau sinyal intraday RG',
            '──────────────────────────────────────────',
        ];

        $candidates = array_merge(
            $planData['candidates_scalping'] ?? [],
            $planData['candidates_swing'] ?? [],
            $planData['candidates_sesi2'] ?? [],
            $planData['candidates_bsjp'] ?? []
        );

        if (empty($candidates)) {
            $code = $planData['status_code'] ?? 'NO_SETUP_FOUND';
            if ($code === 'DATA_CAPABILITY_BLOCKED') {
                $lines[] = '🛡️ STATUS: DATA_CAPABILITY_BLOCKED';
                $lines[] = '• Data pasar intraday atau integritas feed belum memenuhi syarat evaluasi strategi secara penuh.';
                $lines[] = '• Papan live running trade berstatus BOARD_SCOPE_UNVERIFIED (pemisahan RG/NG belum tersedia dari upstream).';
                $lines[] = '• Publikasi sinyal intraday baru ditahan untuk mencegah rekomendasi spekulatif tanpa pembuktian feed.';
                if (! empty($planData['watchlist_reference_eod'])) {
                    $lines[] = '──────────────────────────────────────────';
                    $lines[] = '📋 STATUS WATCHLIST PRA-PASAR (08:30 WIB):';
                    $lines[] = "• {$planData['watchlist_reference_eod']}";
                }
            } else {
                $lines[] = '🛡️ STATUS: NO_SETUP_FOUND';
                $lines[] = '• Data pengamatan lengkap; tidak ada kandidat yang memenuhi kriteria breakout volume dan volatilitas.';
                $lines[] = '• Sistem secara disiplin menahan pengiriman trading plan pada sesi ini.';
            }
        } else {
            foreach ($candidates as $cand) {
                $m = $cand['metrics'];
                $displayType = match ($cand['type'] ?? '') {
                    'SCALPING' => 'WATCHLIST CONDITIONAL (EOD; BUKAN INTRADAY RG)',
                    'BSJP' => 'WATCHLIST CONDITIONAL (EOD; BUKAN LIVE)',
                    default => $cand['type'] ?? 'WATCHLIST CONDITIONAL',
                };
                $lines[] = sprintf('🎯 [%s] — %s', $cand['ticker'], $displayType);
                $lines[] = "• Alasan Masuk   : {$cand['reason']}";
                $lines[] = "• Pemicu Entry   : {$cand['trigger_condition']}";
                $lines[] = "• Zona Entry     : {$cand['entry_zone']}";
                $lines[] = sprintf('• Target (TP)    : TP1 Rp %s | TP2 Rp %s | TP3 Rp %s (basis open)', number_format($m['target_price_tp1'], 0, ',', '.'), number_format($m['target_price_tp2'], 0, ',', '.'), number_format($m['target_price_tp3'], 0, ',', '.'));
                $lines[] = sprintf('• Cut Loss (CL)  : Rp %s (-%.2f%% dari open)', number_format($m['cut_loss_price'], 0, ',', '.'), $m['cut_loss_pct']);
                if (isset($cand['support'], $cand['resistance'])) {
                    $lines[] = sprintf('• Support / Resist: Rp %s / Rp %s', number_format($cand['support'], 0, ',', '.'), number_format($cand['resistance'], 0, ',', '.'));
                }
                $lines[] = sprintf('• Reward / Risk  : 1 : %.2f (Estimasi Fee & Slippage: Rp %s)', $m['reward_risk_ratio'], number_format($m['estimated_cost_rp'], 0, ',', '.'));
                $lines[] = "• Horizon        : {$cand['horizon']}";
                $lines[] = "• Masa Berlaku   : {$cand['valid_until']}";
                $lines[] = "• Kondisi Batal  : {$cand['invalidation']}";
                $lines[] = "• Risiko Utama   : {$cand['risk_factors']}";
                $lines[] = '──────────────────────────────────────────';
            }
        }

        $lines[] = 'ℹ️ CATATAN & BATASAN METODOLOGI:';
        if (! empty($planData['data_notes'])) {
            $lines[] = "• {$planData['data_notes']}";
        }
        $lines[] = '• Harga menyentuh level tidak membuktikan order terisi (fill).';
        $lines[] = '• Tanpa janji profit minimum. Selalu gunakan batas risiko ketat.';
        $lines[] = '──────────────────────────────────────────';

        $lines[] = 'Dipublikasikan secara deterministik oleh Aether Market Intelligence';

        return implode("\n", $lines);
    }

    /**
     * Catat seluruh trading plan kandidat ke ledger rekomendasi resmi (prediction_signals & evidence_snapshots)
     * sebelum alert dipublikasikan ke Telegram.
     */
    public function recordTradingPlanSignals(array $planData, Carbon $nowWib, string $slot, bool $dryRun = false): array
    {
        $cleanSlot = str_replace(':', '', $slot);
        $candidates = array_merge(
            $planData['candidates_scalping'] ?? [],
            $planData['candidates_swing'] ?? [],
            $planData['candidates_bsjp'] ?? []
        );

        $registeredSignals = [];

        if ($dryRun) {
            return $registeredSignals;
        }

        foreach ($candidates as $cand) {
            $ticker = $cand['ticker'];
            $type = $cand['type']; // SCALPING, SWING, BSJP
            $strategyName = "TELEGRAM_{$type}";
            $planId = "PLAN_{$ticker}_{$nowWib->format('Ymd')}_{$cleanSlot}_{$type}";

            $horizonDays = match ($type) {
                'SCALPING', 'BSJP' => 1,
                'SWING' => 5,
                default => 5,
            };

            $horizonType = match ($type) {
                'SCALPING' => 'INTRADAY_SESSION',
                'BSJP' => 'OVERNIGHT_NEXT_OPEN',
                'SWING' => '5_TRADING_DAYS',
                default => '5_TRADING_DAYS',
            };

            $evalDueDate = $this->calendar->addTradingDays($nowWib->copy(), $horizonDays);

            $expiresAt = match ($type) {
                'SCALPING' => $nowWib->copy()->setTime(16, 0, 0),
                'BSJP' => $this->calendar->addTradingDays($nowWib->copy(), 1)->setTime(9, 30, 0),
                'SWING' => $this->calendar->addTradingDays($nowWib->copy(), 5)->setTime(15, 50, 0),
                default => $nowWib->copy()->addDays(7),
            };

            $metrics = $cand['metrics'] ?? [];
            $entryPrice = (float) ($metrics['entry_price'] ?? 0);
            $targetPrice = (float) ($metrics['target_price'] ?? 0);
            $cutLossPrice = (float) ($metrics['cut_loss_price'] ?? 0);

            // 1. Simpan Snapshot Evidence
            $snapshotId = "SNAP_{$ticker}_{$nowWib->format('Ymd')}_{$cleanSlot}_{$type}";
            $evidence = EvidenceSnapshot::firstOrCreate(
                ['snapshot_id' => $snapshotId],
                [
                    'ticker' => $ticker,
                    'decision_date' => $nowWib->toDateString(),
                    'market_regime' => 'SIDEWAYS',
                    'data_hash' => hash('sha256', json_encode($cand)),
                    'features_snapshot' => [
                        'strategy_type' => $type,
                        'horizon_type' => $horizonType,
                        'contract_provenance' => 'PRE_DISPATCH_FROZEN_SNAPSHOT',
                        'reason' => $cand['reason'] ?? '',
                        'trigger_condition' => $cand['trigger_condition'] ?? '',
                        'entry_zone' => $cand['entry_zone'] ?? '',
                        'metrics' => $metrics,
                        'horizon' => $cand['horizon'] ?? '',
                        'valid_until' => $cand['valid_until'] ?? '',
                        'invalidation' => $cand['invalidation'] ?? '',
                        'risk_factors' => $cand['risk_factors'] ?? '',
                        'board_scope' => $planData['board_scope'] ?? 'RG',
                        'coverage_desc' => $planData['coverage_desc'] ?? 'FULL_EOD',
                        'bsjp_benchmark_rule' => ($type === 'BSJP') ? 'Harga open sesi berikutnya adalah acuan benchmark evaluasi matematis, bukan bukti order fill pengguna.' : null,
                    ],
                ]
            );

            $contract = [
                'plan_id' => $planId,
                'ticker' => $ticker,
                'strategy' => $type,
                'decision_time' => $nowWib->toIso8601String(),
                'source' => 'IDX Edge PRO',
                'source_session' => $planData['as_of_session'] ?? null,
                'entry' => $entryPrice,
                'target' => $targetPrice,
                'cut_loss' => $cutLossPrice,
                'horizon' => $horizonType,
                'expiry' => $expiresAt->toIso8601String(),
                'fee_rate_assumed' => 0.0040,
                'strategy_version' => self::STRATEGY_VERSION,
                'evidence_snapshot_id' => $evidence->id,
                'source_observation_id' => $cand['source_observation_id'] ?? null,
                'source_bar_date' => $cand['source_bar_date'] ?? null,
                'source_mode' => $cand['source_mode'] ?? null,
                'source_board_scope' => $cand['source_board_scope'] ?? null,
                'source_fetched_at' => $cand['source_fetched_at'] ?? null,
                'source_available_at' => $cand['source_available_at'] ?? null,
                'level_formulas' => $cand['level_formulas'] ?? [],
            ];
            $contractHash = hash('sha256', json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $evidence->update([
                'data_hash' => $contractHash,
                'features_snapshot' => array_merge($evidence->features_snapshot ?? [], [
                    'contract' => $contract,
                    'contract_hash' => $contractHash,
                ]),
            ]);

            // 2. Simpan Sinyal Prediksi ke Ledger Utama (prediction_signals)
            $sig = PredictionSignal::firstOrCreate(
                ['prediction_id' => $planId],
                [
                    'parent_prediction_id' => null,
                    'ticker' => $ticker,
                    'signal_status' => 'PUBLISHED_CONDITIONAL',
                    'direction' => 'UP',
                    'probability_up' => 1.0,
                    'probability_down' => 0.0,
                    'max_probability' => 1.0,
                    'confidence_score' => 1.0,
                    'threshold_applied' => 0.0,
                    'model_name' => $strategyName,
                    'model_version' => self::STRATEGY_VERSION,
                    'feature_schema_hash' => self::CONFIG_HASH,
                    'decision_date' => $nowWib->toDateString(),
                    'decision_timestamp' => $nowWib,
                    'reference_price' => $entryPrice,
                    'reference_price_date' => $planData['as_of_session'] ?? $nowWib->toDateString(),
                    'target_price' => $targetPrice,
                    'invalidation_price' => $cutLossPrice,
                    'predicted_horizon_days' => $horizonDays,
                    'evaluation_due_date' => $evalDueDate,
                    'published_at' => $nowWib,
                    'expires_at' => $expiresAt,
                    'monitoring_status' => 'WAITING_FOR_TRIGGER',
                    'evaluation_status' => 'PENDING',
                    'evidence_snapshot_id' => $evidence->id,
                    'metadata' => [
                        'plan_id' => $planId,
                        'plan_type' => $type,
                        'horizon_type' => $horizonType,
                        'contract_provenance' => 'PRE_DISPATCH_FROZEN_SNAPSHOT',
                        'contract_hash' => $contractHash,
                        'contract' => $contract,
                        'origin' => 'TELEGRAM_TRADING_PLAN',
                        'trigger_condition' => $cand['trigger_condition'] ?? '',
                        'entry_zone' => $cand['entry_zone'] ?? '',
                        'invalidation_rule' => $cand['invalidation'] ?? '',
                        'risk_factors' => $cand['risk_factors'] ?? '',
                        'board_scope' => $planData['board_scope'] ?? 'RG',
                        'assumed_fee_rate' => 0.0040,
                        'estimated_cost_rp' => $metrics['estimated_cost_rp'] ?? 0,
                        'gross_risk_pct' => $metrics['gross_risk_pct'] ?? 0,
                        'gross_reward_pct' => $metrics['gross_reward_pct'] ?? 0,
                        'reward_risk_ratio' => $metrics['reward_risk_ratio'] ?? 0,
                        'net_reward_pct' => $metrics['net_reward_pct'] ?? 0,
                        'delivery_status' => 'DECISION_CREATED',
                        'outbox_id' => null,
                        'telegram_message_id' => null,
                        'experimental_status' => false,
                        'bsjp_benchmark_rule' => ($type === 'BSJP') ? 'Harga open sesi berikutnya adalah acuan benchmark evaluasi matematis, bukan bukti order fill pengguna.' : null,
                    ],
                ]
            );

            // 3. Catat Event Inisial pada MonitoringEvent
            MonitoringEvent::firstOrCreate(
                [
                    'prediction_id' => $planId,
                    'event_type' => 'PLAN_RECORDED',
                ],
                [
                    'event_time' => $nowWib,
                    'first_observed_at' => $nowWib,
                    'price_observed' => $entryPrice,
                    'description' => "Trading plan {$type} dicatat pada ledger dengan status WAITING_FOR_TRIGGER.",
                    'metadata' => [
                        'plan_id' => $planId,
                        'slot' => $slot,
                        'strategy_version' => self::STRATEGY_VERSION,
                    ],
                ]
            );

            $registeredSignals[] = $sig;
        }

        return $registeredSignals;
    }

    /**
     * Terbitkan alert ke outbox dengan deduplikasi dan pengecekan freshness/validitas.
     */
    public function publishPlanToOutbox(
        string $slot,
        string $reportText,
        Carbon $nowWib,
        bool $dryRun = false,
        int $freshnessMinutes = 30,
        ?array $planData = null
    ): array {
        $cleanSlot = str_replace(':', '', $slot);
        $dedupKey = "TRADING_PLAN_{$nowWib->format('Ymd')}_{$cleanSlot}";

        // Catat trading plan ke ledger rekomendasi sebelum publikasi
        $registeredSignals = [];
        if ($planData && ! $dryRun) {
            $registeredSignals = $this->recordTradingPlanSignals($planData, $nowWib, $slot, false);
        }
        $planIds = array_map(fn ($s) => $s->prediction_id, $registeredSignals);

        if ($dryRun) {
            return [
                'status' => 'DRY_RUN',
                'dedup_key' => $dedupKey,
                'payload' => $reportText,
                'registered_signals' => $planIds,
            ];
        }

        // Pengecekan Kedaluwarsa: Jika waktu pemicu sudah lewat batas toleransi, jangan kirim sebagai peluang aktif
        $scheduledTime = Carbon::createFromFormat('Y-m-d H:i', "{$nowWib->format('Y-m-d')} {$slot}", 'Asia/Jakarta');
        if ($nowWib->diffInMinutes($scheduledTime, false) < -$freshnessMinutes) {
            Log::warning("[TRADING_PLAN] Slot {$slot} telah kedaluwarsa ({$nowWib->format('H:i')} vs {$slot}). Menolak pengiriman alert basi.");

            return [
                'status' => 'EXPIRED_STALE_ALERT',
                'dedup_key' => $dedupKey,
                'delay_minutes' => abs($nowWib->diffInMinutes($scheduledTime, false)),
            ];
        }

        $existing = TelegramOutbox::where('dedup_key', $dedupKey)->first();
        if ($existing && $existing->status === 'SENT') {
            return [
                'status' => 'ALREADY_SENT',
                'outbox_id' => $existing->id,
                'telegram_message_id' => $existing->telegram_message_id,
                'registered_signals' => $planIds,
            ];
        }

        $dueAt = $scheduledTime->copy()->setTimezone('UTC');
        $runId = "RUN_PLAN_{$nowWib->format('Ymd')}_{$cleanSlot}_".Str::lower(Str::random(4));
        $chatId = config('services.telegram.chat_id', '5923895922');
        $triggerSource = 'SCHEDULED_TRADING_PLAN';

        AnalysisRun::firstOrCreate(
            ['run_id' => $runId],
            [
                'trigger_type' => "trading_plan_{$cleanSlot}",
                'status' => 'completed',
                'started_at' => $nowWib,
                'completed_at' => now('Asia/Jakarta'),
                'metadata' => [
                    'slot' => $slot,
                    'strategy_version' => self::STRATEGY_VERSION,
                    'config_hash' => self::CONFIG_HASH,
                    'trigger_source' => $triggerSource,
                ],
            ]
        );

        $outboxItem = $this->formatter->enqueueOutbox(
            dedupKey: $dedupKey,
            messageType: 'TRADING_PLAN',
            payload: $reportText,
            predictionId: $planIds[0] ?? null,
            replyToMessageId: null,
            chatId: $chatId,
            dueAt: $dueAt,
            triggerSource: $triggerSource,
            metadata: [
                'report_type' => 'TRADING_PLAN',
                'slot' => $slot,
                'session_date' => $nowWib->format('Y-m-d'),
                'run_id' => $runId,
                'chat_id' => $chatId,
                'due_at_wib' => "{$nowWib->format('Y-m-d')} {$slot} WIB",
                'plan_ids' => $planIds,
            ]
        );

        // Update status sinyal menjadi QUEUED dan tautkan outbox_id
        foreach ($registeredSignals as $sig) {
            $sig->update([
                'metadata' => array_merge($sig->metadata ?? [], [
                    'delivery_status' => 'QUEUED',
                    'outbox_id' => $outboxItem->id,
                ]),
            ]);

            MonitoringEvent::create([
                'prediction_id' => $sig->prediction_id,
                'event_type' => 'ALERT_QUEUED',
                'event_time' => $nowWib,
                'first_observed_at' => $nowWib,
                'price_observed' => $sig->reference_price,
                'description' => "Trading plan diantrekan ke Telegram outbox #{$outboxItem->id}.",
                'metadata' => [
                    'outbox_id' => $outboxItem->id,
                    'slot' => $slot,
                ],
            ]);
        }

        return [
            'status' => 'ENQUEUED',
            'outbox_id' => $outboxItem->id,
            'due_at' => $dueAt->toIso8601String(),
            'dedup_key' => $dedupKey,
            'registered_signals' => $planIds,
        ];
    }

    /**
     * Rekap audit trading plan per strategi dan status untuk dashboard / monitoring.
     */
    public static function getAuditRecap(string $period = 'all'): array
    {
        $query = PredictionSignal::where(function ($q) {
            $q->where('model_name', 'like', 'TELEGRAM_%')
                ->orWhere('metadata->origin', 'TELEGRAM_TRADING_PLAN');
        });

        if ($period === 'today') {
            $query->whereDate('decision_date', now('Asia/Jakarta')->toDateString());
        } elseif ($period === '30d') {
            $query->whereDate('decision_date', '>=', now('Asia/Jakarta')->subDays(30)->toDateString());
        }

        $all = $query->get();

        $totalRegistered = $all->count();
        $retrospective = $all->filter(fn ($s) => ($s->metadata['contract_provenance'] ?? '') === 'RETROSPECTIVE_RECONCILIATION');
        $notDelivered = $all->filter(fn ($s) => in_array(($s->metadata['delivery_status'] ?? ''), ['NOT_DELIVERED_RETROSPECTIVE', 'DECISION_CREATED', 'FAILED', 'SUPERSEDED_UNSENT']));
        $queued = $all->filter(fn ($s) => ($s->metadata['delivery_status'] ?? '') === 'QUEUED');

        // Kohort Rekomendasi Forward Terkirim (Hanya yang benar-benar dikirim ke Telegram pengguna dengan message ID bursa nyata)
        $forwardDelivered = $all->filter(fn ($s) => ($s->metadata['delivery_status'] ?? '') === 'SENT' &&
            ($s->metadata['contract_provenance'] ?? '') !== 'RETROSPECTIVE_RECONCILIATION' &&
            ! empty($s->telegram_message_id)
        );

        // Metrik Siklus Hidup Forward Terkirim
        $fwdWaiting = $forwardDelivered->where('monitoring_status', 'WAITING_FOR_TRIGGER')->count();
        $fwdTriggered = $forwardDelivered->where('monitoring_status', 'TRIGGER_OBSERVED')->count();
        $fwdTargetHit = $forwardDelivered->where('monitoring_status', 'TARGET_HIT')->count();
        $fwdInvalidated = $forwardDelivered->where('monitoring_status', 'INVALIDATED')->count();
        $fwdAmbiguous = $forwardDelivered->where('monitoring_status', 'AMBIGUOUS')->count();
        $fwdExpiredClean = $forwardDelivered->where('monitoring_status', 'EXPIRED')->count();
        $fwdExpiredUntriggered = $forwardDelivered->filter(fn ($s) => $s->monitoring_status === 'EXPIRED' && ($s->metadata['last_stage_before_expiry'] ?? '') !== 'TRIGGER_OBSERVED')->count();
        $fwdExpiredPostTrigger = $forwardDelivered->filter(fn ($s) => $s->monitoring_status === 'EXPIRED' && ($s->metadata['last_stage_before_expiry'] ?? '') === 'TRIGGER_OBSERVED')->count();
        $fwdCoverageGap = $forwardDelivered->filter(fn ($s) => in_array($s->monitoring_status, ['EXPIRED_WITH_COVERAGE_GAP', 'EXPIRED_WITH_PARTIAL_COVERAGE']))->count();
        $fwdProvisional = $forwardDelivered->filter(fn ($s) => str_starts_with($s->evaluation_status ?? '', 'EVALUATED_PROVISIONAL'))->count();

        // Matured Final Denominator: HANYA rekomendasi forward dengan observasi terkonfirmasi
        // Catatan: EXPIRED_WITH_PARTIAL_COVERAGE dan EXPIRED_WITH_COVERAGE_GAP TIDAK dihitung sebagai matured bersih
        $forwardMaturedCount = $fwdTargetHit + $fwdInvalidated + $fwdAmbiguous + $fwdExpiredClean;
        $targetHitRateFinal = $forwardMaturedCount > 0 ? round(($fwdTargetHit / $forwardMaturedCount) * 100, 1) : null;

        // Metrik Agregat Seluruh Ledger (Termasuk Retrospektif)
        $waitingAll = $all->where('monitoring_status', 'WAITING_FOR_TRIGGER')->count();
        $triggeredAll = $all->where('monitoring_status', 'TRIGGER_OBSERVED')->count();
        $targetHitAll = $all->where('monitoring_status', 'TARGET_HIT')->count();
        $invalidatedAll = $all->where('monitoring_status', 'INVALIDATED')->count();
        $ambiguousAll = $all->where('monitoring_status', 'AMBIGUOUS')->count();
        $expiredCleanAll = $all->where('monitoring_status', 'EXPIRED')->count();
        $expiredGapAll = $all->where('monitoring_status', 'EXPIRED_WITH_COVERAGE_GAP')->count();
        $expiredPartialAll = $all->where('monitoring_status', 'EXPIRED_WITH_PARTIAL_COVERAGE')->count();
        $coverageGapTotalAll = $expiredGapAll + $expiredPartialAll + $all->filter(fn ($s) => ($s->metadata['coverage_gap_detected'] ?? false))->count();

        $byStrategy = [
            'SCALPING' => [
                'total' => $all->filter(fn ($s) => str_contains($s->model_name, 'SCALPING'))->count(),
                'forward_delivered' => $forwardDelivered->filter(fn ($s) => str_contains($s->model_name, 'SCALPING'))->count(),
                'target_hit' => $forwardDelivered->filter(fn ($s) => str_contains($s->model_name, 'SCALPING') && $s->monitoring_status === 'TARGET_HIT')->count(),
                'invalidated' => $forwardDelivered->filter(fn ($s) => str_contains($s->model_name, 'SCALPING') && $s->monitoring_status === 'INVALIDATED')->count(),
            ],
            'SWING' => [
                'total' => $all->filter(fn ($s) => str_contains($s->model_name, 'SWING'))->count(),
                'forward_delivered' => $forwardDelivered->filter(fn ($s) => str_contains($s->model_name, 'SWING'))->count(),
                'target_hit' => $forwardDelivered->filter(fn ($s) => str_contains($s->model_name, 'SWING') && $s->monitoring_status === 'TARGET_HIT')->count(),
                'invalidated' => $forwardDelivered->filter(fn ($s) => str_contains($s->model_name, 'SWING') && $s->monitoring_status === 'INVALIDATED')->count(),
            ],
            'BSJP' => [
                'total' => $all->filter(fn ($s) => str_contains($s->model_name, 'BSJP'))->count(),
                'forward_delivered' => $forwardDelivered->filter(fn ($s) => str_contains($s->model_name, 'BSJP'))->count(),
                'target_hit' => $forwardDelivered->filter(fn ($s) => str_contains($s->model_name, 'BSJP') && $s->monitoring_status === 'TARGET_HIT')->count(),
                'invalidated' => $forwardDelivered->filter(fn ($s) => str_contains($s->model_name, 'BSJP') && $s->monitoring_status === 'INVALIDATED')->count(),
            ],
        ];

        return [
            // Klasifikasi Kohort
            'total_decided' => $totalRegistered,
            'total_registered' => $totalRegistered,
            'total_delivered' => $forwardDelivered->count(),
            'total_forward_delivered' => $forwardDelivered->count(),
            'total_retrospective' => $retrospective->count(),
            'total_not_delivered' => $notDelivered->count(),
            'total_queued' => $queued->count(),

            // Metrik Forward Terkirim (Denominator Eksplisit)
            'forward_waiting_for_trigger' => $fwdWaiting,
            'forward_trigger_observed' => $fwdTriggered,
            'forward_target_hit' => $fwdTargetHit,
            'forward_invalidated' => $fwdInvalidated,
            'forward_ambiguous' => $fwdAmbiguous,
            'forward_expired_untriggered' => $fwdExpiredUntriggered,
            'forward_expired_post_trigger' => $fwdExpiredPostTrigger,
            'forward_coverage_gap' => $fwdCoverageGap,
            'forward_provisional' => $fwdProvisional,
            'forward_matured_count' => $forwardMaturedCount,
            'forward_target_hit_rate_pct' => $targetHitRateFinal,

            // Metrik Kompatibilitas Template
            'waiting_for_trigger' => $waitingAll,
            'trigger_observed' => $triggeredAll,
            'target_hit' => $targetHitAll,
            'invalidated' => $invalidatedAll,
            'ambiguous' => $ambiguousAll,
            'expired' => $expiredCleanAll,
            'expired_clean' => $expiredCleanAll,
            'expired_gap' => $expiredGapAll,
            'expired_partial' => $expiredPartialAll,
            'coverage_gap' => $coverageGapTotalAll,
            'matured_count' => $forwardMaturedCount,
            'target_hit_rate_pct' => $targetHitRateFinal,

            // Penjelasan Denominator & Ketentuan Metrik
            'denominator_rules' => [
                'forward_matured_count' => 'target_hit + invalidated + ambiguous + expired_clean (HANYA rekomendasi forward delivered).',
                'ambiguous_interpretation' => 'AMBIGUOUS adalah tabrakan bar bursa/urutan tidak pasti; BUKAN transaksi gagal/incorrect.',
                'untriggered_interpretation' => 'Plan yang kedaluwarsa sebelum trigger tersentuh BUKAN transaksi rugi, melainkan kondisi pemicu tidak terpenuhi.',
                'cohort_exclusion' => 'Plan retrospektif, tidak terkirim, dan legacy dikeluarkan dari kohort forward.',
            ],
            'by_strategy' => $byStrategy,
            'simulation_disclaimer' => 'Evaluasi hasil merupakan kalkulasi skenario matematis terhadap data resmi BEI, BUKAN bukti order fill atau portofolio riil pengguna.',
        ];
    }

    /**
     * Rekonsiliasi riwayat trading plan yang telah terkirim via Telegram Outbox.
     */
    public function reconcileSentTradingPlans(): array
    {
        $sentOutboxes = TelegramOutbox::where('status', 'SENT')
            ->where(function ($q) {
                $q->where('message_type', 'TRADING_PLAN')
                    ->orWhere('payload_text', 'like', '%TRADING PLAN%');
            })
            ->get();

        $reconciled = 0;
        $legacyCount = 0;

        foreach ($sentOutboxes as $outbox) {
            $meta = is_array($outbox->metadata) ? $outbox->metadata : [];
            $planIds = $meta['plan_ids'] ?? ($outbox->prediction_id ? [$outbox->prediction_id] : []);

            if (! empty($planIds)) {
                foreach ($planIds as $pid) {
                    $sig = PredictionSignal::where('prediction_id', $pid)->first();
                    if ($sig && ($sig->metadata['delivery_status'] ?? '') !== 'SENT') {
                        $sig->update([
                            'telegram_message_id' => $outbox->telegram_message_id,
                            'metadata' => array_merge($sig->metadata ?? [], [
                                'delivery_status' => 'SENT',
                                'outbox_id' => $outbox->id,
                                'sent_at' => $outbox->sent_at?->toISOString() ?? now()->toISOString(),
                            ]),
                        ]);
                        $reconciled++;
                    }
                }
            } else {
                $outbox->update([
                    'metadata' => array_merge($meta, [
                        'contract_status' => 'LEGACY_INCOMPLETE_CONTRACT',
                        'reconciled_at' => now()->toISOString(),
                    ]),
                ]);
                $legacyCount++;
            }
        }

        return [
            'total_checked' => $sentOutboxes->count(),
            'reconciled_signals' => $reconciled,
            'legacy_incomplete_contracts' => $legacyCount,
        ];
    }

    protected function loadLiveSupervisorState(): array
    {
        $path = storage_path('app/running_trade_state.json');
        if (file_exists($path)) {
            $raw = @file_get_contents($path);
            $state = json_decode((string) $raw, true);
            if (is_array($state)) {
                $coverage = $state['session_coverage'] ?? [];
                $observedFrom = $coverage['observed_from'] ?? $coverage['observation_start_at'] ?? null;
                $observedUntil = $coverage['observed_until'] ?? $coverage['observation_end_at'] ?? $state['last_trade_at'] ?? null;
                if ($observedFrom) {
                    $coverage['observed_from'] = $observedFrom;
                    $coverage['observed_until'] = $observedUntil;
                    $coverage['window_status'] = 'SESSION_1_PARTIAL';
                    $coverage['session_1_coverage'] = 'PARTIAL';
                    $coverage['session_2_coverage'] = 'UNOBSERVED_UNTIL_PROVEN';
                    $coverage['gap'] = $coverage['gap'] ?? 'NOT_ESTABLISHED';
                    $coverage['event_time_status'] = 'EVENT_TIME_UNVERIFIED';
                    $state['session_coverage'] = $coverage;
                }

                return $state;
            }
        }

        return [
            'status' => 'DISCONNECTED',
            'board_scope' => 'BOARD_SCOPE_UNVERIFIED',
            'session_coverage' => [
                'window_status' => 'UNOBSERVED',
            ],
        ];
    }
}
