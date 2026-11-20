<?php

namespace App\Console\Commands;

use App\Models\AnalysisRun;
use App\Models\MonitoringEvent;
use App\Models\TelegramOutbox;
use App\Services\BeiCalendarService;
use App\Services\TelegramFormattingService;
use App\Services\TelegramTradingPlanService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class HermesMorningBriefingCommand extends Command
{
    protected $signature = 'hermes:morning-briefing {--force : Abaikan kalender bursa untuk pengujian} {--dry-run : Cetak tanpa antrean outbox}';

    protected $description = 'Publikasikan Briefing Pagi BEI pukul 08:30 WIB berbasis data EOD sesi perdagangan sebelumnya';

    public function handle(
        BeiCalendarService $calendar,
        TelegramFormattingService $formatter,
        TelegramTradingPlanService $tradingPlanService
    ): int {
        $nowWib = Carbon::now('Asia/Jakarta');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        if (! $force && ! $calendar->isTradingDay($nowWib)) {
            $this->info("[MORNING_BRIEFING] Hari ini ({$nowWib->format('Y-m-d')}) bukan hari bursa aktif BEI. Briefing dilewati.");

            return Command::SUCCESS;
        }

        $prevTradingDay = $calendar->getPreviousTradingDay($nowWib);
        $prevDateStr = $prevTradingDay->format('Y-m-d');
        $prevDateFmt = $prevTradingDay->format('d M Y');

        $this->info("Menyiapkan Briefing Pagi & Trading Plan Pra-Pasar (Acuan Sesi: {$prevDateFmt})...");

        // 1. Generate Trading Plan Pra-Pasar Terstruktur (08:30 WIB)
        $planData = $tradingPlanService->generatePreMarketPlan($nowWib);
        $reportText = $tradingPlanService->renderTelegramTradingPlan($planData, $nowWib);

        // Dry-run must not write signals, evidence, monitoring events, or outbox rows.
        $registeredSignals = $dryRun
            ? []
            : $tradingPlanService->recordTradingPlanSignals($planData, $nowWib, '08:30', false);
        $planIds = array_map(fn ($s) => $s->prediction_id, $registeredSignals);

        if ($dryRun) {
            $this->line($reportText);
            $this->info('[PREVIEW / DRY-RUN] Briefing Pagi selesai tanpa membuat outbox atau rekod eksekusi produksi.');

            return Command::SUCCESS;
        }

        $dedupKey = 'MORNING_BRIEFING_'.$nowWib->format('Ymd');
        $existing = TelegramOutbox::where('dedup_key', $dedupKey)->first();
        if ($existing && $existing->status === 'SENT') {
            $this->info("Briefing Pagi hari ini sudah terkirim (Outbox #{$existing->id}, Msg ID #{$existing->telegram_message_id}). Menolak penyiaran ulang.");

            return Command::SUCCESS;
        }

        $dueAt = Carbon::createFromFormat('Y-m-d H:i', "{$nowWib->format('Y-m-d')} 08:30", 'Asia/Jakarta')->setTimezone('UTC');
        $runId = 'RUN_BRIEFING_'.$nowWib->format('Ymd_His').'_'.Str::lower(Str::random(4));
        $chatId = config('services.telegram.chat_id', '5923895922');
        $triggerSource = filter_var(env('AETHER_SCHEDULED_TRIGGER', false), FILTER_VALIDATE_BOOL)
            ? 'SCHEDULED'
            : ($this->input->isInteractive() ? 'MANUAL_CLI' : 'SCHEDULED');

        $candidateTickers = array_map(fn ($c) => $c['ticker'], array_merge(
            $planData['candidates_scalping'] ?? [],
            $planData['candidates_swing'] ?? [],
            $planData['candidates_bsjp'] ?? []
        ));

        AnalysisRun::firstOrCreate(
            ['run_id' => $runId],
            [
                'trigger_type' => 'morning_briefing_0830',
                'status' => 'completed',
                'started_at' => $nowWib,
                'completed_at' => now('Asia/Jakarta'),
                'metadata' => [
                    'as_of_session' => $prevDateStr,
                    'tickers_covered' => $candidateTickers,
                    'report_type' => 'MORNING_BRIEFING',
                    'quote_intraday_status' => 'QUARANTINED',
                    'trigger_source' => $triggerSource,
                ],
            ]
        );

        $outboxItem = $formatter->enqueueOutbox(
            dedupKey: $dedupKey,
            messageType: 'SYSTEM_ALERT',
            payload: $reportText,
            predictionId: $planIds[0] ?? null,
            replyToMessageId: null,
            chatId: $chatId,
            dueAt: $dueAt,
            triggerSource: $triggerSource,
            metadata: [
                'report_type' => 'MORNING_BRIEFING',
                'briefing_date' => $nowWib->format('Y-m-d'),
                'session_reference' => $prevDateStr,
                'run_id' => $runId,
                'chat_id' => $chatId,
                'due_at_wib' => "{$nowWib->format('Y-m-d')} 08:30 WIB",
                'plan_ids' => $planIds,
            ]
        );

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
                    'slot' => '08:30',
                ],
            ]);
        }

        $this->info("Briefing Pagi berhasil diantrekan (Outbox #{$outboxItem->id}, Status: {$outboxItem->status}, Due At: {$dueAt->toIso8601String()}).");

        // Optimasi: Trigger atomic outbox processing immediately if due_at <= now, preserving lease and deduplication
        if (! $dryRun && ! empty($outboxItem->id)) {
            $formatter->processOutboxQueue(mockOnly: app()->runningUnitTests());
        }

        return Command::SUCCESS;
    }
}
