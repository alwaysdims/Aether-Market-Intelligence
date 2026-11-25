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

        // 0. Auto-expire Scalping / Intraday conditional signals reaching timestamp expiry without proven RG fills
        $dueScalping = PredictionSignal::monitoringEligible()->whereIn('monitoring_status', ['WAITING_FOR_TRIGGER', 'TRIGGER_OBSERVED'])
            ->where('expires_at', '<=', $now)
            ->where(function ($q) {
                $q->where('predicted_horizon_days', '<=', 1)
                    ->orWhere('metadata->plan_type', 'SCALPING');
            })
            ->where(function ($q) {
                $q->whereNull('metadata->monitoring_cohort')
                    ->orWhere('metadata->monitoring_cohort', '!=', 'SHADOW_TEST');
            })
            ->get();

        foreach ($dueScalping as $sig) {
            DB::transaction(function () use ($sig, $now, &$report) {
                $sig->update([
                    'monitoring_status' => 'EXPIRED_WITH_PARTIAL_COVERAGE',
                    'evaluation_status' => 'UNVERIFIABLE_COVERAGE_GAP',
                    'metadata' => array_merge($sig->metadata ?? [], [
                        'actual_evaluation_price' => null,
                        'evaluated_at' => $now->toISOString(),
                        'expiry_reason' => 'INTRADAY_RG_UNVERIFIED_SESSION_CLOSED',
                        'limitation' => 'Stream running trade berstatus BOARD_SCOPE_UNVERIFIED tanpa kontrak pemisah RG; fill dan urutan tick tidak dapat dibuktikan dari EOD.',
                        'coverage_gap_detected' => true,
                    ]),
                ]);

                MonitoringEvent::create([
                    'prediction_id' => $sig->prediction_id,
                    'event_type' => 'HORIZON_EXPIRED',
                    'event_time' => $now,
                    'first_observed_at' => $now,
                    'price_observed' => null,
                    'description' => "Masa berlaku scalping sesi {$sig->ticker} berakhir pada {$now->format('H:i')} WIB. Stream intraday berstatus BOARD_SCOPE_UNVERIFIED; trigger/fill tidak dapat dibuktikan dari EOD semata. Status dipindahkan ke EXPIRED_WITH_PARTIAL_COVERAGE.",
                    'metadata' => [
                        'plan_type' => 'SCALPING',
                        'expires_at' => $sig->expires_at,
                        'reason' => 'INTRADAY_RG_UNVERIFIED_SESSION_CLOSED',
                        'evaluation_status' => 'UNVERIFIABLE_COVERAGE_GAP',
                    ],
                ]);
            });
            $report['signals_expired']++;
        }

        // 1. Monitor Active Directional & Conditional Predictions against latest observations
        $activeSignals = PredictionSignal::monitoringEligible()->whereIn('monitoring_status', ['ACTIVE', 'WAITING_FOR_TRIGGER', 'TRIGGER_OBSERVED'])->get();
        $report['active_signals_checked'] = $activeSignals->count();

        foreach ($activeSignals as $signal) {
            $latestObs = MarketObservation::where('ticker', $signal->ticker)
                ->where('observation_time', '>=', $signal->published_at ?: $signal->decision_timestamp)
                ->where('observation_time', '<=', $signal->expires_at)
                ->where('validation_status', 'VALID')
                ->where('is_eod_final', true)
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

            $tgt = (float) $signal->target_price;
            $inv = (float) $signal->invalidation_price;
            $ref = (float) $signal->reference_price;
            $h = (float) $latestObs->high_price;
            $l = (float) $latestObs->low_price;

            // 1. Verifikasi Waktu Delivery vs Waktu Bar Bursa
            $deliveredAt = $signal->metadata['sent_at'] ?? $signal->published_at;
            $isDelivered = ($signal->metadata['delivery_status'] ?? '') === 'SENT' && $deliveredAt !== null;
            $deliveredTime = $isDelivered ? Carbon::parse($deliveredAt, 'Asia/Jakarta') : null;

            // Jika sinyal belum terkirim ke pengguna, tandai sebagai skenario internal (bukan peluang aktif penerima)
            if (! $isDelivered) {
                $signal->update(['metadata' => array_merge($signal->metadata ?? [], [
                    'scenario_accessible_to_recipient' => false,
                    'scenario_type' => 'DECISION_TIME_INTERNAL',
                ])]);
            } else {
                // Periksa apakah delivery terjadi di tengah sesi bursa tanggal bar yang sama
                $sessionOpen = Carbon::parse($latestObs->bar_date->format('Y-m-d').' 09:00:00', 'Asia/Jakarta');
                $isSameDayMidSessionDelivery = ($deliveredTime->toDateString() === $latestObs->bar_date->format('Y-m-d')) && ($deliveredTime->gt($sessionOpen));

                if ($isSameDayMidSessionDelivery) {
                    // Bar EOD mencakup transaksi sebelum delivery; urutan intra-sesi tidak dapat ditentukan tanpa data tick
                    $signal->update(['metadata' => array_merge($signal->metadata ?? [], [
                        'intraday_sequence_proven' => 'UNVERIFIABLE_FROM_EOD_ALONE',
                        'sequence_limitation' => "Bar EOD {$latestObs->bar_date->format('Y-m-d')} mencakup waktu sebelum delivery ({$deliveredTime->format('H:i')} WIB). Urutan kejadian tidak dapat dipastikan.",
                    ])]);

                    // Jika High/Low menyentuh level pemicu pada bar yang sama dengan delivery di tengah sesi, tandai AMBIGUOUS
                    if ($signal->monitoring_status !== 'ACTIVE' && ($h >= $ref || $l <= $inv)) {
                        DB::transaction(function () use ($signal, $latestObs, $now, &$report, $deliveredTime) {
                            $signal->update(['monitoring_status' => 'AMBIGUOUS']);
                            MonitoringEvent::create([
                                'prediction_id' => $signal->prediction_id,
                                'event_type' => 'AMBIGUOUS_HIT',
                                'event_time' => $latestObs->observation_time,
                                'first_observed_at' => $now,
                                'price_observed' => $latestObs->close_price,
                                'description' => "Bar EOD mencakup transaksi sebelum delivery ({$deliveredTime->format('H:i:s')} WIB). Urutan kejadian pasca-delivery tidak dapat ditentukan tanpa tick intraday; status ditandai AMBIGUOUS.",
                                'metadata' => [
                                    'high' => $latestObs->high_price,
                                    'low' => $latestObs->low_price,
                                    'delivered_at' => $deliveredTime->toISOString(),
                                ],
                            ]);
                        });
                        $report['events_generated']++;

                        continue;
                    }
                }
            }

            // 2. Khusus BSJP: Jendela entry sore dan evaluasi exit sesi berikutnya
            if ($signal->model_name === 'TELEGRAM_BSJP' || ($signal->metadata['plan_type'] ?? '') === 'BSJP') {
                $nextDayObs = MarketObservation::where('ticker', $signal->ticker)
                    ->whereDate('bar_date', '>', $signal->decision_date)
                    ->where('validation_status', 'VALID')
                    ->where('is_eod_final', true)
                    ->orderBy('bar_date')
                    ->first();

                if ($nextDayObs) {
                    $nextOpen = (float) $nextDayObs->open_price;
                    $statusBsjp = null;
                    $descBsjp = null;

                    if ($nextOpen >= $tgt) {
                        $statusBsjp = 'TARGET_HIT';
                        $descBsjp = "Target harga BSJP Rp {$tgt} tercapai pada benchmark harga pembukaan sesi berikutnya (Open Rp {$nextOpen}). Catatan: harga open adalah benchmark evaluasi matematis, bukan bukti order fill pengguna.";
                    } elseif ($nextOpen <= $inv) {
                        $statusBsjp = 'INVALIDATED';
                        $descBsjp = "Batas cut loss BSJP Rp {$inv} tersentuh pada benchmark harga pembukaan sesi berikutnya (Open Rp {$nextOpen}). Eksekusi disiplin cut loss terkonfirmasi.";
                    } elseif ($nextDayObs->high_price >= $tgt && $nextDayObs->low_price <= $inv) {
                        $statusBsjp = 'AMBIGUOUS';
                        $descBsjp = "Target dan cut loss keduanya berada dalam rentang bar bursa sesi berikutnya ({$nextDayObs->bar_date->format('d M Y')}) tanpa urutan pasti.";
                    } elseif ($nextDayObs->high_price >= $tgt) {
                        $statusBsjp = 'TARGET_HIT';
                        $descBsjp = "Target harga BSJP Rp {$tgt} tercapai pada sesi perdagangan berikutnya ({$nextDayObs->bar_date->format('d M Y')}).";
                    } elseif ($nextDayObs->low_price <= $inv) {
                        $statusBsjp = 'INVALIDATED';
                        $descBsjp = "Batas cut loss BSJP Rp {$inv} tersentuh pada sesi perdagangan berikutnya ({$nextDayObs->bar_date->format('d M Y')}).";
                    }

                    if ($statusBsjp) {
                        DB::transaction(function () use ($signal, $nextDayObs, $now, &$report, $statusBsjp, $descBsjp, $nextOpen) {
                            $signal->update([
                                'monitoring_status' => $statusBsjp,
                                'evaluation_status' => $statusBsjp === 'TARGET_HIT' ? 'EVALUATED_CORRECT' : ($statusBsjp === 'INVALIDATED' ? 'EVALUATED_INCORRECT' : 'EVALUATED_NEUTRAL'),
                            ]);
                            MonitoringEvent::create([
                                'prediction_id' => $signal->prediction_id,
                                'event_type' => $statusBsjp,
                                'event_time' => $nextDayObs->observation_time,
                                'first_observed_at' => $now,
                                'price_observed' => $nextOpen,
                                'description' => $descBsjp,
                                'metadata' => [
                                    'benchmark_open_price' => $nextOpen,
                                    'observed_bar' => $nextDayObs->id,
                                ],
                            ]);
                        });
                        $report['events_generated']++;

                        continue;
                    }
                }
            }

            // State Machine Tahap 1: WAITING_FOR_TRIGGER (Menunggu pemicu entry aktif)
            if ($signal->monitoring_status === 'WAITING_FOR_TRIGGER') {
                // Sentuhan pemicu dan invalidasi bersamaan pada bar yang sama tanpa urutan pasti -> AMBIGUOUS
                if ($h >= $ref && $l <= $inv) {
                    DB::transaction(function () use ($signal, $latestObs, $now, &$report) {
                        $signal->update(['monitoring_status' => 'AMBIGUOUS']);
                        MonitoringEvent::create([
                            'prediction_id' => $signal->prediction_id,
                            'event_type' => 'AMBIGUOUS_HIT',
                            'event_time' => $latestObs->observation_time,
                            'first_observed_at' => $now,
                            'price_observed' => $latestObs->close_price,
                            'description' => "Pemicu entry Rp {$signal->reference_price} dan batas pembatalan Rp {$signal->invalidation_price} keduanya tersentuh pada bar {$latestObs->bar_date->format('d M Y')}. Urutan mikro-tick tidak pasti; status ditandai AMBIGUOUS.",
                            'metadata' => [
                                'high' => $latestObs->high_price,
                                'low' => $latestObs->low_price,
                                'entry_trigger' => $signal->reference_price,
                                'invalidation' => $signal->invalidation_price,
                            ],
                        ]);
                    });
                    $report['events_generated']++;

                    continue;
                }

                // Jika pemicu dan target keduanya berada pada bar yang sama, jangan menganggap target terjadi setelah trigger tanpa bukti -> AMBIGUOUS
                if ($h >= $ref && $h >= $tgt) {
                    DB::transaction(function () use ($signal, $latestObs, $now, &$report) {
                        $signal->update(['monitoring_status' => 'AMBIGUOUS']);
                        MonitoringEvent::create([
                            'prediction_id' => $signal->prediction_id,
                            'event_type' => 'AMBIGUOUS_HIT',
                            'event_time' => $latestObs->observation_time,
                            'first_observed_at' => $now,
                            'price_observed' => $latestObs->close_price,
                            'description' => "Pemicu entry Rp {$signal->reference_price} dan target Rp {$signal->target_price} keduanya berada dalam rentang bar bursa yang sama ({$latestObs->bar_date->format('d M Y')}). Urutan kejadian pemicu vs target tidak dapat dibuktikan tanpa tick; status ditandai AMBIGUOUS.",
                            'metadata' => [
                                'high' => $latestObs->high_price,
                                'entry_trigger' => $signal->reference_price,
                                'target' => $signal->target_price,
                            ],
                        ]);
                    });
                    $report['events_generated']++;

                    continue;
                }

                // Invalidation tersentuh sebelum pemicu aktif -> Skenario Batal
                if ($l <= $inv) {
                    DB::transaction(function () use ($signal, $latestObs, $now, &$report) {
                        $signal->update([
                            'monitoring_status' => 'INVALIDATED',
                            'evaluation_status' => 'EVALUATED_INCORRECT',
                        ]);
                        MonitoringEvent::create([
                            'prediction_id' => $signal->prediction_id,
                            'event_type' => 'INVALIDATED',
                            'event_time' => $latestObs->observation_time,
                            'first_observed_at' => $now,
                            'price_observed' => $latestObs->close_price,
                            'description' => "Batas invalidation / cut loss Rp {$signal->invalidation_price} tersentuh sebelum pemicu entry aktif pada bar {$latestObs->bar_date->format('d M Y')}. Skenario batal.",
                            'metadata' => [
                                'low' => $latestObs->low_price,
                                'invalidation' => $signal->invalidation_price,
                            ],
                        ]);
                    });
                    $report['events_generated']++;

                    continue;
                }

                // Pemicu entry teramati tersentuh (hanya jika target belum tersentuh pada bar yang sama)
                if ($h >= $ref) {
                    DB::transaction(function () use ($signal, $latestObs, $now, &$report) {
                        $signal->update(['monitoring_status' => 'TRIGGER_OBSERVED']);
                        MonitoringEvent::create([
                            'prediction_id' => $signal->prediction_id,
                            'event_type' => 'TRIGGER_OBSERVED',
                            'event_time' => $latestObs->observation_time,
                            'first_observed_at' => $now,
                            'price_observed' => $latestObs->close_price,
                            'description' => "Level pemicu entry Rp {$signal->reference_price} teramati tersentuh pada bar {$latestObs->bar_date->format('d M Y')}. Peringatan: sentuhan level bukan bukti order terisi (fill).",
                            'metadata' => [
                                'high' => $latestObs->high_price,
                                'entry_trigger' => $signal->reference_price,
                            ],
                        ]);
                    });
                    $report['events_generated']++;
                } else {
                    // Pemicu belum tersentuh dan belum batal
                    continue;
                }
            }

            // Target & Invalidation checks (untuk status TRIGGER_OBSERVED atau ACTIVE)
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
                            'description' => "Target {$signal->target_price} dan batasan pembatalan {$signal->invalidation_price} keduanya tersentuh pada rentang bar {$latestObs->bar_date->format('d M Y')}. Urutan mikro-tick tidak pasti; status ditandai AMBIGUOUS.",
                            'metadata' => [
                                'high' => $latestObs->high_price,
                                'low' => $latestObs->low_price,
                                'target' => $signal->target_price,
                                'invalidation' => $signal->invalidation_price,
                            ],
                        ]);
                    });
                    $report['events_generated']++;

                    continue;
                }

                // Hit target first
                if ($hitTarget) {
                    DB::transaction(function () use ($signal, $latestObs, $tgt, $now, &$report) {
                        $signal->update(['monitoring_status' => 'TARGET_HIT']);

                        MonitoringEvent::create([
                            'prediction_id' => $signal->prediction_id,
                            'event_type' => 'TARGET_HIT',
                            'event_time' => $latestObs->observation_time,
                            'first_observed_at' => $now,
                            'price_observed' => $tgt,
                            'description' => "Target harga {$tgt} tersentuh pada bar {$latestObs->bar_date->format('d M Y')}. Sinyal dinyatakan TARGET_HIT.",
                            'metadata' => ['target_price' => $tgt, 'observed_bar' => $latestObs->id],
                        ]);
                    });
                    $report['events_generated']++;

                    continue;
                }

                // Hit invalidation
                if ($hitInvalidation) {
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

        // 2. Evaluate 5-Trading-Day Horizon for Matured or Provisional Predictions
        $dueSignals = PredictionSignal::monitoringEligible()->where(function ($q) {
            $q->where('evaluation_status', 'PENDING')
                ->orWhere('evaluation_status', 'like', 'EVALUATED_PROVISIONAL%');
        })
            ->whereDate('evaluation_due_date', '<=', $now->format('Y-m-d'))
            ->get();

        foreach ($dueSignals as $dueSig) {
            $dueDateStr = is_string($dueSig->evaluation_due_date) ? $dueSig->evaluation_due_date : $dueSig->evaluation_due_date->format('Y-m-d');

            // Strict Data Source Isolation: only valid, non-quarantined EOD observations from IDX Edge PRO (RG only)
            $eodBar = MarketObservation::where('ticker', $dueSig->ticker)
                ->whereIn('board_scope', ['RG', 'INDEX'])
                ->whereDate('bar_date', $dueDateStr)
                ->where('source', 'IDX Edge PRO')
                ->where(fn ($q) => $q->where('adjustment_mode', 'PROVIDER_BACK_ADJUSTED')->orWhereNull('adjustment_mode'))
                ->where('validation_status', 'VALID')
                ->where('is_eod_final', true)
                ->first();

            // Fallback: If no exact adjustment tagged, accept validated observation but reject quarantined intraday
            if (! $eodBar) {
                $eodBar = MarketObservation::where('ticker', $dueSig->ticker)
                    ->whereIn('board_scope', ['RG', 'INDEX'])
                    ->whereDate('bar_date', $dueDateStr)
                    ->where('source', 'IDX Edge PRO')
                    ->where('validation_status', 'VALID')
                    ->where('is_eod_final', true)
                    ->first();
            }

            if ($eodBar) {
                $refPrice = (float) $dueSig->reference_price;
                $actualClose = (float) $eodBar->close_price;
                $isCorrect = false;
                $returnPct = 0.0;

                if ($refPrice > 0) {
                    $returnPct = round((($actualClose - $refPrice) / $refPrice) * 100, 4);
                    if ($dueSig->direction === 'UP') {
                        $isCorrect = ($actualClose > $refPrice);
                    } elseif ($dueSig->direction === 'DOWN') {
                        $isCorrect = ($actualClose < $refPrice);
                    }
                }

                $isProvisional = ! $eodBar->is_eod_final;
                $evalStatus = $isProvisional
                    ? ($isCorrect ? 'EVALUATED_PROVISIONAL_CORRECT' : 'EVALUATED_PROVISIONAL_INCORRECT')
                    : ($isCorrect ? 'EVALUATED_CORRECT' : 'EVALUATED_INCORRECT');

                $evalDesc = "Evaluasi horizon 5-hari untuk sinyal #{$dueSig->prediction_id}: Acuan={$refPrice}, Realisasi={$actualClose} ({$returnPct}%). Status: {$evalStatus}.";

                DB::transaction(function () use ($dueSig, $evalStatus, $evalDesc, $refPrice, $actualClose, $returnPct, $isProvisional, $now, &$report) {
                    $dueSig->update([
                        'evaluation_status' => $evalStatus,
                        'metadata' => array_merge($dueSig->metadata ?? [], [
                            'actual_evaluation_price' => $actualClose,
                            'actual_close_5d' => $actualClose,
                            'evaluation_return_pct' => $returnPct,
                            'return_5d_pct' => round($returnPct, 2),
                            'evaluated_at' => $now->toISOString(),
                            'is_provisional' => $isProvisional,
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
                            'is_provisional' => $isProvisional,
                            'evaluation_status' => $evalStatus,
                        ],
                    ]);
                });

                $report['signals_evaluated']++;
            }
        }

        // 3. Process Expirations for remaining active/conditional signals whose horizon has expired
        $expiredSignals = PredictionSignal::monitoringEligible()->whereIn('monitoring_status', ['ACTIVE', 'WAITING_FOR_TRIGGER', 'TRIGGER_OBSERVED'])
            ->where('expires_at', '<=', $now)
            ->where(function ($q) {
                $q->whereNull('metadata->monitoring_cohort')
                    ->orWhere('metadata->monitoring_cohort', '!=', 'SHADOW_TEST');
            })
            ->get();

        foreach ($expiredSignals as $sig) {
            // Audit check: Verify whether required market observation sessions were actually checked
            $expectedTradingDays = $this->calendar->getTradingDaysBetween($sig->published_at, $sig->expires_at);
            $totalExpectedSessions = count($expectedTradingDays);

            $observedSessions = MarketObservation::where('ticker', $sig->ticker)
                ->whereIn('board_scope', ['RG', 'INDEX'])
                ->where(function ($q) use ($sig) {
                    $q->whereBetween('bar_date', [
                        $sig->published_at->toDateString(),
                        $sig->expires_at->toDateString(),
                    ])->orWhereBetween('observation_time', [
                        $sig->published_at->copy()->startOfDay(),
                        $sig->expires_at->copy()->endOfDay(),
                    ]);
                })
                ->where('source', 'IDX Edge PRO')
                ->where('validation_status', 'VALID')
                ->where('is_eod_final', true)
                ->distinct()
                ->pluck('bar_date')
                ->map(fn ($d) => is_string($d) ? $d : $d->format('Y-m-d'))
                ->toArray();

            $obsCount = count($observedSessions);
            $missingSessions = array_values(array_diff($expectedTradingDays, $observedSessions));
            $isCompleteCoverage = ($obsCount >= $totalExpectedSessions && empty($missingSessions));
            $isZeroCoverage = ($obsCount === 0);

            if ($isZeroCoverage) {
                $statusToSet = 'EXPIRED_WITH_COVERAGE_GAP';
                $descriptionText = "Masa pemantauan telah berakhir pada {$now->format('d M Y H:i')} WIB, namun terdeteksi celah data total (0 dari {$totalExpectedSessions} sesi bursa EOD terverifikasi ditemukan). Status ditandai EXPIRED_WITH_COVERAGE_GAP.";
            } elseif (! $isCompleteCoverage) {
                $statusToSet = 'EXPIRED_WITH_PARTIAL_COVERAGE';
                $missingStr = implode(', ', array_slice($missingSessions, 0, 5));
                $descriptionText = "Masa pemantauan berakhir pada {$now->format('d M Y H:i')} WIB. Target tidak tersentuh pada {$obsCount} sesi terverifikasi, namun terdapat ".count($missingSessions)." sesi bursa kalender belum diperiksa ({$missingStr}). Status ditandai EXPIRED_WITH_PARTIAL_COVERAGE.";
            } else {
                $statusToSet = 'EXPIRED';
                $descriptionText = "Masa pemantauan telah berakhir pada {$now->format('d M Y H:i')} WIB. Target harga tidak tercapai dalam jendela masa berlaku (seluruh {$totalExpectedSessions} sesi bursa kalender terverifikasi lengkap).";
            }

            DB::transaction(function () use ($sig, $now, $statusToSet, $descriptionText, $obsCount, $totalExpectedSessions, $missingSessions, &$report) {
                $sig->update([
                    'monitoring_status' => $statusToSet,
                    'metadata' => array_merge($sig->metadata ?? [], [
                        'coverage_gap_detected' => ($obsCount < $totalExpectedSessions),
                        'coverage_gap_type' => ($obsCount === 0 ? 'TOTAL_GAP' : ($obsCount < $totalExpectedSessions ? 'PARTIAL_GAP' : 'NONE')),
                        'verified_sessions_checked' => $obsCount,
                        'expected_sessions_count' => $totalExpectedSessions,
                        'missing_sessions' => $missingSessions,
                    ]),
                ]);

                MonitoringEvent::create([
                    'prediction_id' => $sig->prediction_id,
                    'event_type' => $statusToSet,
                    'event_time' => $now,
                    'first_observed_at' => $now,
                    'price_observed' => $sig->reference_price,
                    'description' => $descriptionText,
                    'metadata' => [
                        'published_at' => $sig->published_at?->toISOString(),
                        'expires_at' => $sig->expires_at->toISOString(),
                        'verified_sessions_checked' => $obsCount,
                        'expected_sessions_count' => $totalExpectedSessions,
                        'missing_sessions_count' => count($missingSessions),
                        'lifecycle_stage' => 'MONITORING_EXPIRED',
                    ],
                ]);
            });

            $report['signals_expired']++;
        }

        return $report;
    }

    /**
     * Ingest new market observation from local cleaned CSVs into PostgreSQL idempotently.
     * DEPRECATED: Yahoo Finance fallback ingestion is permanently disabled in favor of unified IDX Edge PRO connector.
     */
    public function ingestObservationsFromCleanedData(): int
    {
        Log::warning('[DEPRECATION] ingestObservationsFromCleanedData called but Yahoo Finance ingestion is deactivated.');

        return 0;
    }
}
