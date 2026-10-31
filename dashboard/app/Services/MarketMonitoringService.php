<?php

namespace App\Services;

use App\Models\MarketObservation;
use App\Models\MonitoringEvent;
use App\Models\PredictionSignal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MarketMonitoringService
{
    protected BeiCalendarService $calendar;

    public function __construct(BeiCalendarService $calendar)
    {
        $this->calendar = $calendar;
    }

    /**
     * Run full market monitoring cycle for all active predictions and data tracking.
     */
    public function runMonitoringCycle(?Carbon $customNow = null): array
    {
        $now = ($customNow ?? Carbon::now('Asia/Jakarta'))->copy()->timezone('Asia/Jakarta');
        $sessionInfo = $this->calendar->getMarketSessionStatus($now);

        $report = [
            'monitored_at' => $now->toISOString(),
            'session' => $sessionInfo,
            'active_signals_checked' => 0,
            'events_generated' => 0,
            'signals_expired' => 0,
            'signals_evaluated' => 0,
            'gaps_detected' => 0,
            'details' => [],
        ];

        // 1. Process Expirations (Max 14 calendar days from published_at)
        $expiredSignals = PredictionSignal::where('monitoring_status', 'ACTIVE')
            ->where('expires_at', '<=', $now)
            ->get();

        foreach ($expiredSignals as $sig) {
            DB::transaction(function () use ($sig, $now, &$report) {
                $sig->update(['monitoring_status' => 'EXPIRED']);

                MonitoringEvent::create([
                    'prediction_id' => $sig->prediction_id,
                    'event_type' => 'EXPIRED',
                    'event_time' => $now,
                    'first_observed_at' => $now,
                    'price_observed' => $sig->reference_price,
                    'description' => "Masa pemantauan maksimal 14 hari kalender telah berakhir pada {$now->format('d M Y H:i')} WIB. Target harga tidak tercapai dalam jendela masa berlaku.",
                    'metadata' => [
                        'published_at' => $sig->published_at?->toISOString(),
                        'expires_at' => $sig->expires_at->toISOString(),
                        'lifecycle_stage' => 'MONITORING_EXPIRED',
                    ],
                ]);
            });

            $report['signals_expired']++;
        }

        // 2. Evaluate 5-Trading-Day Horizon for Matured Predictions
        $dueSignals = PredictionSignal::where('evaluation_status', 'PENDING')
            ->where('evaluation_due_date', '<=', $now->format('Y-m-d'))
            ->get();

        foreach ($dueSignals as $dueSig) {
            $eodBar = MarketObservation::where('ticker', $dueSig->ticker)
                ->where('bar_date', $dueSig->evaluation_due_date->format('Y-m-d'))
                ->where('is_eod_final', true)
                ->first();

            if ($eodBar) {
                DB::transaction(function () use ($dueSig, $eodBar, $now, &$report) {
                    $refPrice = (float) $dueSig->reference_price;
                    $actualClose = (float) $eodBar->close_price;
                    $returnPct = ($refPrice > 0) ? round(($actualClose - $refPrice) / $refPrice * 100, 2) : 0.0;

                    if ($dueSig->direction === 'ABSTAIN') {
                        $evalStatus = 'EVALUATED_NEUTRAL';
                        $evalDesc = "Evaluasi horizon 5 hari bursa ({$dueSig->evaluation_due_date->format('d M Y')}): Return {$returnPct}%. Model berstatus ABSTAIN / NO_SIGNAL.";
                    } elseif ($dueSig->direction === 'UP') {
                        $evalStatus = ($returnPct > 0) ? 'EVALUATED_CORRECT' : (($returnPct < 0) ? 'EVALUATED_INCORRECT' : 'EVALUATED_NEUTRAL');
                        $evalDesc = "Evaluasi horizon 5 hari bursa ({$dueSig->evaluation_due_date->format('d M Y')}): Return {$returnPct}%. Prediksi UP dinyatakan {$evalStatus}.";
                    } else { // DOWN
                        $evalStatus = ($returnPct < 0) ? 'EVALUATED_CORRECT' : (($returnPct > 0) ? 'EVALUATED_INCORRECT' : 'EVALUATED_NEUTRAL');
                        $evalDesc = "Evaluasi horizon 5 hari bursa ({$dueSig->evaluation_due_date->format('d M Y')}): Return {$returnPct}%. Prediksi DOWN dinyatakan {$evalStatus}.";
                    }

                    $dueSig->update([
                        'evaluation_status' => $evalStatus,
                        'metadata' => array_merge($dueSig->metadata ?? [], [
                            'actual_close_5d' => $actualClose,
                            'return_5d_pct' => $returnPct,
                            'evaluated_at' => $now->toISOString(),
                        ]),
                    ]);

                    MonitoringEvent::create([
                        'prediction_id' => $dueSig->prediction_id,
                        'event_type' => 'HORIZON_EVALUATED',
                        'event_time' => $now,
                        'first_observed_at' => $now,
                        'price_observed' => $actualClose,
                        'description' => $evalDesc,
                        'metadata' => [
                            'reference_price' => $refPrice,
                            'actual_price' => $actualClose,
                            'return_pct' => $returnPct,
                            'outcome' => $evalStatus,
                        ],
                    ]);
                });

                $report['signals_evaluated']++;
            }
        }

        // 3. Monitor Active Directional Predictions against latest observations
        $activeSignals = PredictionSignal::where('monitoring_status', 'ACTIVE')->get();
        $report['active_signals_checked'] = $activeSignals->count();

        foreach ($activeSignals as $signal) {
            $latestObs = MarketObservation::where('ticker', $signal->ticker)
                ->where('observation_time', '>', $signal->published_at)
                ->where('observation_time', '<=', $signal->expires_at)
                ->latest('observation_time')
                ->first();

            if (! $latestObs) {
                continue;
            }

            // Check offline gap: if last observation is > 1 trading day older than current time
            $lastEvent = MonitoringEvent::where('prediction_id', $signal->prediction_id)->latest('event_time')->first();
            if ($lastEvent && $lastEvent->event_time->diffInDays($now) >= 2) {
                MonitoringEvent::create([
                    'prediction_id' => $signal->prediction_id,
                    'event_type' => 'GAP_OFFLINE_DETECTED',
                    'event_time' => $now,
                    'first_observed_at' => $now,
                    'description' => "Celah pemantauan offline terdeteksi ({$lastEvent->event_time->format('d/m H:i')} s.d. {$now->format('d/m H:i')} WIB). Data intraday pada masa offline tidak dikarang.",
                    'metadata' => ['gap_days' => $lastEvent->event_time->diffInDays($now)],
                ]);
                $report['gaps_detected']++;
            }

            // Target & Invalidation checks
            $tgt = (float) $signal->target_price;
            $inv = (float) $signal->invalidation_price;
            $h = (float) $latestObs->high_price;
            $l = (float) $latestObs->low_price;

            if ($tgt > 0 && $inv > 0) {
                $hitTarget = false;
                $hitInvalidation = false;

                if ($signal->direction === 'UP') {
                    $hitTarget = ($h >= $tgt);
                    $hitInvalidation = ($l <= $inv);
                } elseif ($signal->direction === 'DOWN') {
                    $hitTarget = ($l <= $tgt);
                    $hitInvalidation = ($h >= $inv);
                }

                // Ambiguous bar: both touched in single bar without micro-tick sequence
                if ($hitTarget && $hitInvalidation) {
                    DB::transaction(function () use ($signal, $latestObs, $now, &$report) {
                        $signal->update(['monitoring_status' => 'AMBIGUOUS']);

                        MonitoringEvent::create([
                            'prediction_id' => $signal->prediction_id,
                            'event_type' => 'AMBIGUOUS_HIT',
                            'event_time' => $latestObs->observation_time,
                            'first_observed_at' => $now,
                            'price_observed' => $latestObs->close_price,
                            'description' => "Target dan batas pembatal keduanya tersentuh pada bar bursa yang sama ({$latestObs->bar_date->format('d M Y')}). Urutan mikro-tick tidak pasti; status ditandai AMBIGUOUS.",
                            'metadata' => [
                                'high' => $latestObs->high_price,
                                'low' => $latestObs->low_price,
                                'target' => $signal->target_price,
                                'invalidation' => $signal->invalidation_price,
                            ],
                        ]);
                    });
                    $report['events_generated']++;
                } elseif ($hitTarget) {
                    DB::transaction(function () use ($signal, $latestObs, $tgt, $now, &$report) {
                        $signal->update(['monitoring_status' => 'TARGET_HIT']);

                        MonitoringEvent::create([
                            'prediction_id' => $signal->prediction_id,
                            'event_type' => 'TARGET_HIT',
                            'event_time' => $latestObs->observation_time,
                            'first_observed_at' => $now,
                            'price_observed' => $tgt,
                            'description' => "Target harga {$tgt} terkonfirmasi tersentuh pada bar {$latestObs->bar_date->format('d M Y')}.",
                            'metadata' => ['target_price' => $tgt, 'observed_bar' => $latestObs->id],
                        ]);
                    });
                    $report['events_generated']++;
                } elseif ($hitInvalidation) {
                    DB::transaction(function () use ($signal, $latestObs, $inv, $now, &$report) {
                        $signal->update(['monitoring_status' => 'INVALIDATED']);

                        MonitoringEvent::create([
                            'prediction_id' => $signal->prediction_id,
                            'event_type' => 'INVALIDATED',
                            'event_time' => $latestObs->observation_time,
                            'first_observed_at' => $now,
                            'price_observed' => $inv,
                            'description' => "Batas pembatal tesis {$inv} tersentuh pada bar {$latestObs->bar_date->format('d M Y')}. Sinyal dinyatakan INVALIDATED.",
                            'metadata' => ['invalidation_price' => $inv, 'observed_bar' => $latestObs->id],
                        ]);
                    });
                    $report['events_generated']++;
                }
            }
        }

        return $report;
    }

    /**
     * Ingest new market observation from local cleaned CSVs into PostgreSQL idempotently.
     */
    public function ingestObservationsFromCleanedData(): int
    {
        $cleanedDir = base_path('../data/cleaned');
        $tickers = ['BBCA', 'BBRI', 'BMRI', 'BBNI', 'ASII', 'TLKM', 'MAPI', 'ARTO', 'MEDC', 'SMRA', '^JKSE'];
        $insertedCount = 0;

        foreach ($tickers as $ticker) {
            $fpath = $cleanedDir.DIRECTORY_SEPARATOR."{$ticker}_cleaned.csv";
            if (! file_exists($fpath)) {
                continue;
            }

            $csv = array_map('str_getcsv', file($fpath));
            if (count($csv) < 2) {
                continue;
            }

            $headers = array_shift($csv);
            $headerMap = array_flip($headers);

            // Read last 10 bars
            $recentRows = array_slice($csv, -10);
            foreach ($recentRows as $row) {
                $barDate = $row[$headerMap['bar_date']] ?? null;
                if (! $barDate) {
                    continue;
                }

                $open = (float) ($row[$headerMap['open_price'] ?? $headerMap['Open']] ?? 0);
                $high = (float) ($row[$headerMap['high_price'] ?? $headerMap['High']] ?? 0);
                $low = (float) ($row[$headerMap['low_price'] ?? $headerMap['Low']] ?? 0);
                $close = (float) ($row[$headerMap['close_price'] ?? $headerMap['Close']] ?? 0);
                $vol = (int) ($row[$headerMap['volume'] ?? $headerMap['Volume']] ?? 0);

                $obsTime = Carbon::parse($barDate.' 16:00:00', 'Asia/Jakarta');
                $hash = hash('sha256', "{$ticker}_{$barDate}_{$close}_{$vol}");

                $obs = MarketObservation::firstOrCreate(
                    [
                        'ticker' => str_replace('^', '', $ticker),
                        'bar_date' => $barDate,
                        'observation_time' => $obsTime,
                    ],
                    [
                        'open_price' => $open,
                        'high_price' => $high,
                        'low_price' => $low,
                        'close_price' => $close,
                        'volume' => $vol,
                        'source' => 'Yahoo Finance v8 / yfinance',
                        'is_eod_final' => true,
                        'delay_minutes' => 15,
                        'data_hash' => $hash,
                    ]
                );

                if ($obs->wasRecentlyCreated) {
                    $insertedCount++;
                }
            }
        }

        return $insertedCount;
    }
}
