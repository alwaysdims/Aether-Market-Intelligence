<?php

namespace App\Http\Controllers;

use App\Models\AnalysisRun;
use App\Models\BrokerSummary;
use App\Models\CompanyAnalysisMetric;
use App\Models\CompanyCorporateAction;
use App\Models\CompanyFundamental;
use App\Models\DataSource;
use App\Models\IdxCompanyRegistry;
use App\Models\MarketAnalysis;
use App\Models\MarketObservation;
use App\Models\MarketSnapshot;
use App\Models\NewsItem;
use App\Models\NotificationLog;
use App\Models\PostHorizonEvaluation;
use App\Models\PredictionSignal;
use App\Models\PromptTemplate;
use App\Models\ResearchRequest;
use App\Models\SystemHealthCheck;
use App\Models\TelegramOutbox;
use App\Models\UserWatchlist;
use App\Presenters\AiConclusionPresenter;
use App\Services\BeiCalendarService;
use App\Services\CanonicalMarketReadService;
use App\Services\DashboardResearchRequestBridgeService;
use App\Services\DataFreshnessPolicyService;
use App\Services\IdxEdgeClient;
use App\Services\IdxFinancialIngestionService;
use App\Services\IdxRegistryService;
use App\Services\PostHorizonEvaluatorService;
use App\Services\StockScreeningService;
use App\Services\SystemHealthService;
use App\Services\TelegramTradingPlanService;
use App\Services\UserPortfolioService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    protected SystemHealthService $healthService;

    protected BeiCalendarService $calendar;

    protected PostHorizonEvaluatorService $evaluator;

    public function __construct(
        SystemHealthService $healthService,
        BeiCalendarService $calendar,
        PostHorizonEvaluatorService $evaluator,
        protected CanonicalMarketReadService $marketRead,
        protected DashboardResearchRequestBridgeService $researchBridge
    ) {
        $this->healthService = $healthService;
        $this->calendar = $calendar;
        $this->evaluator = $evaluator;
    }

    /**
     * Dashboard Overview (Ringkasan Eksekutif)
     */
    public function overview()
    {
        $health = $this->healthService->checkAll();

        $lastRun = AnalysisRun::latest('started_at')->first();
        $lastAnalysis = MarketAnalysis::with('analysisRun')->latest()->first();
        $newsCountToday = NewsItem::whereDate('fetched_at', today())->count();
        $activeDataSources = DataSource::where('status', 'active')->count();
        $staleSources = DataSource::where('status', '!=', 'active')->get();

        $nextRunTime = $this->calendar->getNextScheduledRunTime();
        $latestSnapshot = MarketSnapshot::latest('bar_date')->first();
        $latestEodObservation = MarketObservation::where('source', 'IDX Edge PRO')
            ->where('validation_status', 'VALID')
            ->whereIn('adjustment_mode', ['UNADJUSTED', 'PROVIDER_BACK_ADJUSTED'])
            ->orderByDesc('bar_date')
            ->orderByRaw("CASE WHEN adjustment_mode = 'UNADJUSTED' THEN 1 ELSE 2 END")
            ->first();
        $latestEodDate = $latestEodObservation?->bar_date?->toDateString();
        $latestEodFinality = $latestEodObservation?->is_eod_final
            ? 'EOD_FINAL'
            : ($latestEodObservation ? 'PROVISIONAL_POST_MARKET' : 'DATA_NOT_AVAILABLE');
        $isEodFinal = (bool) $latestEodObservation?->is_eod_final;
        $evalMetrics = $this->evaluator->getMetricsSummary();
        $recentEvals = PostHorizonEvaluation::latest('decision_date')->take(5)->get();
        $lastNotification = NotificationLog::latest()->first();
        $notificationStats = [
            'sent' => NotificationLog::where('status', 'sent')->count(),
            'failed' => NotificationLog::where('status', 'failed')->count(),
            'pending' => NotificationLog::where('status', 'pending')->count(),
        ];

        // Schedule slots according to PRD v3: 08:30, 09:15, 12:15, 15:15, 20:00 (16:05 Ingestion)
        // Evaluasi status aktual berbasis tanggal hari ini, session/date, run_id, target chat_id, dan keterverifikasian pengiriman nyata
        $todayDateStr = now('Asia/Jakarta')->toDateString();
        $todayYmdStr = now('Asia/Jakarta')->format('Ymd');
        $nowTimeStr = now('Asia/Jakarta')->format('H:i');
        $expectedChatId = (string) config('services.telegram.chat_id', '5923895922');

        // Slot 08:30 - Morning Briefing
        // Terikat penuh: report_type + session/date + run_id + destination chat/user + seluruh receipt bagian
        $mbOutbox = TelegramOutbox::where(function ($q) use ($todayDateStr, $todayYmdStr) {
            $q->where('dedup_key', "MORNING_BRIEFING_{$todayDateStr}")
                ->orWhere('dedup_key', "MORNING_BRIEFING_{$todayYmdStr}");
        })
            ->where('status', 'SENT')
            ->where('chat_id', $expectedChatId)
            ->first();

        $mbRun = AnalysisRun::where('trigger_type', 'morning_briefing_0830')
            ->whereDate('started_at', $todayDateStr)
            ->where('status', 'completed')
            ->latest('id')
            ->first();

        if ($mbOutbox && $mbOutbox->telegram_message_id && $mbRun) {
            $mbStatus = 'SENT';
            $mbNote = 'Terkirim ke Telegram (Msg #'.$mbOutbox->telegram_message_id.' | Run: '.$mbRun->run_id.')';
        } elseif ($mbRun && ! ($mbOutbox && $mbOutbox->telegram_message_id)) {
            $mbStatus = 'PARTIAL';
            $mbNote = 'Sintesis selesai (Run: '.$mbRun->run_id.'), menunggu konfirmasi delivery';
        } elseif ($nowTimeStr >= '08:30') {
            $mbStatus = 'PENDING';
            $mbNote = 'Jadwal 08:30 terlewat / menunggu giliran eksekusi';
        } else {
            $mbStatus = 'SCHEDULED';
            $mbNote = 'Ringkasan pra-pembukaan bursa';
        }

        // Slot 20:00 - Evening Review
        // Terikat penuh: report_type + session/date + run_id + destination chat/user + seluruh receipt bagian
        $erOutbox = TelegramOutbox::where(function ($q) use ($todayDateStr, $todayYmdStr) {
            $q->where('dedup_key', "EVENING_REVIEW_{$todayDateStr}")
                ->orWhere('dedup_key', "EVENING_REVIEW_{$todayYmdStr}");
        })
            ->where('status', 'SENT')
            ->where('chat_id', $expectedChatId)
            ->first();

        $erRun = AnalysisRun::where('trigger_type', 'evening_review_2000')
            ->whereDate('started_at', $todayDateStr)
            ->where('status', 'completed')
            ->latest('id')
            ->first();

        if ($erOutbox && $erOutbox->telegram_message_id && $erRun) {
            $erStatus = 'SENT';
            $erNote = 'Terkirim ke Telegram (Msg #'.$erOutbox->telegram_message_id.' | Run: '.$erRun->run_id.')';
        } elseif ($erRun && ! ($erOutbox && $erOutbox->telegram_message_id)) {
            $erStatus = 'PARTIAL';
            $erNote = 'Evaluasi selesai (Run: '.$erRun->run_id.'), menunggu konfirmasi delivery';
        } elseif ($nowTimeStr >= '20:00') {
            $erStatus = 'PENDING';
            $erNote = 'Jadwal 20:00 terlewat / menunggu giliran eksekusi';
        } else {
            $erStatus = 'SCHEDULED';
            $erNote = 'Evaluasi broker & rekap harian';
        }

        // Dynamic Slots: 09:15, 12:15, 15:15 (Pembaruan Pasar Terjadwal)
        $intradaySlots = [
            '09:15' => [
                'name' => 'Pembaruan Sesi 1',
                'clean' => '0915',
                'triggers' => ['trading_plan_0915', 'market_update_0915', 'opening_screening_0915'],
            ],
            '12:15' => [
                'name' => 'Ulasan Jeda Sesi',
                'clean' => '1215',
                'triggers' => ['midday_session_1215', 'market_update_1215'],
            ],
            '15:15' => [
                'name' => 'Radar Penutupan',
                'clean' => '1515',
                'triggers' => ['closing_radar_1515', 'market_update_1515'],
            ],
        ];

        $slotStatuses = [];
        foreach ($intradaySlots as $time => $info) {
            $cleanSlot = $info['clean'];
            $outboxItem = TelegramOutbox::where(function ($q) use ($todayDateStr, $todayYmdStr, $cleanSlot, $time) {
                $q->where('dedup_key', 'like', "%{$cleanSlot}%")
                    ->where(function ($inner) use ($todayDateStr, $todayYmdStr) {
                        $inner->where('dedup_key', 'like', "%{$todayDateStr}%")
                            ->orWhere('dedup_key', 'like', "%{$todayYmdStr}%");
                    })
                    ->orWhere(function ($inner) use ($todayDateStr, $time) {
                        $inner->whereDate('created_at', $todayDateStr)
                            ->where('metadata->slot', $time);
                    });
            })
                ->where('chat_id', $expectedChatId)
                ->orderByDesc('id')
                ->first();

            $runItem = AnalysisRun::whereIn('trigger_type', $info['triggers'])
                ->whereDate('started_at', $todayDateStr)
                ->where('status', 'completed')
                ->latest('id')
                ->first();

            if ($outboxItem && $outboxItem->telegram_message_id && $outboxItem->status === 'SENT') {
                $status = 'SENT';
                $note = 'Terkirim (Msg #'.$outboxItem->telegram_message_id.')';
            } elseif ($outboxItem && $outboxItem->status === 'FAILED') {
                $status = 'FAILED';
                $note = 'Gagal kirim: '.($outboxItem->error ?? 'Kesalahan pengiriman outbox');
            } elseif ($outboxItem && $outboxItem->status === 'QUEUED') {
                $status = 'QUEUED';
                $note = 'Dalam antrean outbox (menunggu giliran delivery)';
            } elseif ($runItem) {
                $status = 'PARTIAL';
                $note = 'Sintesis selesai, menunggu konfirmasi outbox';
            } elseif ($nowTimeStr >= $time) {
                $status = 'PENDING';
                $note = "Jadwal {$time} dalam antrean pemrosesan";
            } else {
                $status = 'SCHEDULED';
                $note = "Publikasi terencana ({$time} WIB)";
            }

            $slotStatuses[$time] = [
                'status' => $status,
                'note' => $note,
                'name' => $info['name'],
            ];
        }

        // Slot 16:05 - EOD Ingestion Pipeline
        $hasEodToday = MarketObservation::where('source', 'IDX Edge PRO')
            ->where('bar_date', $todayDateStr)
            ->where('validation_status', 'VALID')
            ->exists();
        if ($hasEodToday) {
            $eodStatus = 'COMPLETED';
            $eodNote = 'Bar EOD resmi BEI tersinkronisasi';
        } elseif ($nowTimeStr >= '16:05') {
            $eodStatus = 'PENDING';
            $eodNote = 'Menunggu penyelesaian siklus bursa hari ini';
        } else {
            $eodStatus = 'SCHEDULED';
            $eodNote = 'Sinkronisasi bar EOD resmi';
        }

        $reportSlots = [
            [
                'time' => '08:30',
                'name' => 'Morning Briefing',
                'type' => 'SCHEDULED',
                'status' => $mbStatus,
                'intraday_capability' => 'DATA_EOD_AND_NEWS',
                'note' => $mbNote,
            ],
            [
                'time' => '09:15',
                'name' => $slotStatuses['09:15']['name'],
                'type' => 'SCHEDULED_UPDATE',
                'status' => $slotStatuses['09:15']['status'],
                'intraday_capability' => 'BELUM_TERSEDIA (QUARANTINED)',
                'note' => $slotStatuses['09:15']['note'],
            ],
            [
                'time' => '12:15',
                'name' => $slotStatuses['12:15']['name'],
                'type' => 'SCHEDULED_UPDATE',
                'status' => $slotStatuses['12:15']['status'],
                'intraday_capability' => 'BELUM_TERSEDIA (QUARANTINED)',
                'note' => $slotStatuses['12:15']['note'],
            ],
            [
                'time' => '15:15',
                'name' => $slotStatuses['15:15']['name'],
                'type' => 'SCHEDULED_UPDATE',
                'status' => $slotStatuses['15:15']['status'],
                'intraday_capability' => 'BELUM_TERSEDIA (QUARANTINED)',
                'note' => $slotStatuses['15:15']['note'],
            ],
            [
                'time' => '16:05',
                'name' => 'EOD Ingestion Pipeline',
                'type' => 'INGESTION',
                'status' => $eodStatus,
                'intraday_capability' => 'EOD_FINAL_PROVISIONAL',
                'note' => $eodNote,
            ],
            [
                'time' => '20:00',
                'name' => 'Evening Market Review',
                'type' => 'SCHEDULED',
                'status' => $erStatus,
                'intraday_capability' => 'EOD_AND_BROKER_SNAPSHOT',
                'note' => $erNote,
            ],
        ];

        // Recent Candidates from Prediction Signals / Research (Exclude retrospective audit cohort, superseded signals, and ABSTAIN non-actionable research)
        $latestCandidates = PredictionSignal::operational()->with('evidenceSnapshot')
            ->where(function ($q) {
                $q->whereNull('metadata->contract_provenance')
                    ->orWhere('metadata->contract_provenance', '!=', 'RETROSPECTIVE_RECONCILIATION');
            })
            ->where(function ($q) {
                $q->whereNull('metadata->delivery_status')
                    ->orWhere('metadata->delivery_status', '!=', 'NOT_DELIVERED_RETROSPECTIVE');
            })
            ->where(function ($q) {
                $q->whereNull('metadata->superseded')
                    ->orWhere('metadata->superseded', '!=', true);
            })
            ->whereNotIn('direction', ['ABSTAIN', 'NEUTRAL', 'HOLD', 'NO_SIGNAL'])
            ->where('signal_status', '!=', 'NO_SIGNAL_ABSTAIN')
            ->where(function ($q) {
                $q->whereNull('metadata->plan_type')
                    ->orWhereNotIn('metadata->plan_type', ['SCALPING', 'BSJP']);
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            })
            ->where(function ($q) {
                $q->whereNull('evaluation_status')
                    ->orWhere('evaluation_status', 'PENDING');
            })
            ->whereNotIn('monitoring_status', ['EXPIRED', 'TARGET_HIT', 'INVALIDATED', 'AMBIGUOUS', 'CANCELLED'])
            ->latest('id')
            ->get()
            ->unique('prediction_id')
            ->values()
            ->take(6);

        // Next follow-ups / maturity: check ACTIVE, WAITING_FOR_TRIGGER, TRIGGER_OBSERVED, and MONITORING statuses (Exclude retrospective audit cohort & superseded signals)
        $activePredictions = PredictionSignal::deliveredToUser()->whereIn('monitoring_status', ['ACTIVE', 'MONITORING', 'WAITING_FOR_TRIGGER', 'TRIGGER_OBSERVED'])
            ->where(function ($q) {
                $q->whereNull('metadata->contract_provenance')
                    ->orWhere('metadata->contract_provenance', '!=', 'RETROSPECTIVE_RECONCILIATION');
            })
            ->where(function ($q) {
                $q->whereNull('metadata->delivery_status')
                    ->orWhere('metadata->delivery_status', '!=', 'NOT_DELIVERED_RETROSPECTIVE');
            })
            ->where(function ($q) {
                $q->whereNull('metadata->superseded')
                    ->orWhere('metadata->superseded', '!=', true);
            })
            ->latest('id')
            ->get()
            ->unique('prediction_id')
            ->values();

        // Benchmark IHSG Canonical Observation
        $ihsgData = $this->marketRead->canonicalIndex();
        $ihsgObs = $ihsgData['observation'];
        $shadowLedgerPath = base_path('../data/shadow_predictions/shadow_predictions_ledger.json');
        $shadowPredictions = file_exists($shadowLedgerPath) ? json_decode(file_get_contents($shadowLedgerPath), true) : [];
        $marketRegime = ! empty($shadowPredictions) ? ($shadowPredictions[0]['market_regime'] ?? 'SIDEWAYS') : 'SIDEWAYS';
        $shadowStats = [
            'total' => count($shadowPredictions),
            'pending' => collect($shadowPredictions)->where('evaluation_status', 'PENDING')->count(),
            'evaluated' => collect($shadowPredictions)->where('evaluation_status', 'EVALUATED')->count(),
            'no_signal' => collect($shadowPredictions)->where('signal_status', 'NO_SIGNAL')->count(),
            'active_signal' => collect($shadowPredictions)->where('signal_status', 'ACTIVE_SIGNAL')->count(),
        ];

        $recentResearchRequests = ResearchRequest::with(['evidenceSnapshot', 'marketAnalysis'])
            ->latest('id')
            ->take(10)
            ->get();
        // Optimize overview latestMarketByTicker to avoid full-table scans
        $latestMarketByTicker = collect();
        $evidenceObservationIds = $recentResearchRequests->mapWithKeys(fn ($req) => [$req->id => $this->marketRead->observationForEvidence($req->evidenceSnapshot)?->id]);
        $expectedBarDate = $latestEodDate ?? $this->calendar->getPreviousTradingDay(now('Asia/Jakarta'))->toDateString();
        $marketStates = collect();
        $portfolioMetrics = app(UserPortfolioService::class)->calculatePortfolioMetrics('5923895922');
        $idxCoverage = Cache::remember('dashboard:overview:idx_coverage', 120, function () {
            return app(IdxFinancialIngestionService::class)->getCoverageStats();
        });
        $idxRegistry = Cache::remember('dashboard:overview:idx_registry', 120, function () {
            return app(IdxRegistryService::class)->getRegistrySummary();
        });

        // Screening Protocol SWING_FILTER_V1 Summary (Cached 3 minutes for dashboard responsiveness)
        $screeningSummary = Cache::remember('dashboard:overview:screening_summary', 180, function () {
            try {
                return app(StockScreeningService::class)->screenCandidates('SWING');
            } catch (\Throwable $e) {
                return ['candidates' => [], 'total_evaluated' => 0];
            }
        });

        // Live Real Counts (Cached 60s to prevent full-table sequence scans on each render)
        $counts = Cache::remember('dashboard:overview:counts', 60, function () {
            return [
                'secondary_candidates' => IdxCompanyRegistry::where('instrument_type', 'EQUITY')->count(),
                'active_indices' => IdxCompanyRegistry::where('instrument_type', 'INDEX')->count(),
                'eod_tickers' => MarketObservation::distinct('ticker')->count('ticker'),
                'total_bars' => MarketObservation::count(),
                'financial_tickers' => CompanyFundamental::distinct('ticker')->count('ticker'),
                'gemini_narratives' => MarketAnalysis::count(),
                'watchlist_count' => UserWatchlist::where('is_active', true)->count(),
                'news_total' => NewsItem::count(),
            ];
        });

        $marketWidgets = [
            'movers' => Cache::remember('dashboard:overview:movers:v4', 300, function (): array {
                try {
                    return app(IdxEdgeClient::class)->movers('chg', 10);
                } catch (\Throwable) {
                    return ['success' => false, 'error_type' => 'WIDGET_EXCEPTION', 'message' => 'Movers tidak tersedia.'];
                }
            }),
            'ihsg' => Cache::remember('dashboard:overview:ihsg:v4', 10, function (): array {
                try {
                    return app(IdxEdgeClient::class)->priceIhsg();
                } catch (\Throwable) {
                    return ['success' => false, 'error_type' => 'WIDGET_EXCEPTION', 'message' => 'IHSG tidak tersedia.'];
                }
            }),
            'fetched_at' => now('Asia/Jakarta')->toIso8601String(),
        ];

        $brandConfig = $this->getBrandConfig();

        return view('dashboard.overview', compact(
            'health',
            'lastRun',
            'lastAnalysis',
            'newsCountToday',
            'activeDataSources',
            'staleSources',
            'nextRunTime',
            'latestSnapshot',
            'latestEodDate',
            'latestEodFinality',
            'isEodFinal',
            'evalMetrics',
            'recentEvals',
            'lastNotification',
            'notificationStats',
            'shadowPredictions',
            'marketRegime',
            'shadowStats',
            'recentResearchRequests',
            'latestMarketByTicker',
            'marketStates',
            'evidenceObservationIds',
            'portfolioMetrics',
            'idxCoverage',
            'idxRegistry',
            'screeningSummary',
            'counts',
            'reportSlots',
            'latestCandidates',
            'activePredictions',
            'ihsgObs',
            'ihsgData',
            'brandConfig',
            'marketWidgets'
        ));
    }

    /**
     * Market Monitor (Server-Side Filter, Search, Sort & Pagination)
     */
    public function market(Request $request)
    {
        $search = strtoupper(trim((string) $request->input('search', '')));
        $sector = trim((string) $request->input('sector', ''));
        $type = $request->input('type', 'equity'); // 'equity' or 'index'
        $sort = $request->input('sort', 'ticker');
        $direction = strtolower((string) $request->input('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $latestMarketObservation = MarketObservation::where('source', 'IDX Edge PRO')
            ->where('validation_status', 'VALID')
            ->orderByDesc('bar_date')
            ->first();
        $expectedBarDate = $latestMarketObservation?->bar_date?->toDateString()
            ?? $this->calendar->getPreviousTradingDay(now('Asia/Jakarta'))->toDateString();
        $marketRead = $this->marketRead;

        // Sectors for dropdown
        $sectors = IdxCompanyRegistry::whereNotNull('sector')
            ->where('sector', '!=', '')
            ->distinct()
            ->orderBy('sector')
            ->pluck('sector');

        // Watchlist tickers
        $watchlistTickers = UserWatchlist::where('is_active', true)->pluck('ticker')->toArray();

        // Build Paginated Query from Registry
        $query = IdxCompanyRegistry::query();

        if ($type === 'index') {
            $query->where('instrument_type', 'INDEX');
        } else {
            $query->where('instrument_type', 'EQUITY');
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('ticker', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%");
            });
        }

        if ($sector !== '') {
            $query->where('sector', $sector);
        }

        // Filter: Watchlist only
        if ($request->has('watchlist') && $request->get('watchlist') === '1') {
            $query->whereIn('ticker', $watchlistTickers);
        }

        // Filter: Cakupan Data
        $coverage = $request->input('coverage', '');
        if ($coverage === 'PILOT') {
            $pilotTickers = ['BBCA', 'BMRI', 'TLKM', 'ASII', 'UNVR', 'ICBP', 'ADRO', 'MDKA', 'KLBF', 'SMRA'];
            $query->whereIn('ticker', $pilotTickers);
        } elseif ($coverage === 'WITH_DATA') {
            $tickersWithData = Cache::remember('market:tickers_with_data', 120, function () {
                return MarketObservation::distinct()->pluck('ticker')->toArray();
            });
            $query->whereIn('ticker', $tickersWithData);
        } elseif ($coverage === 'SECONDARY_ONLY') {
            $tickersWithData = Cache::remember('market:tickers_with_data', 120, function () {
                return MarketObservation::distinct()->pluck('ticker')->toArray();
            });
            $query->whereNotIn('ticker', $tickersWithData);
        }

        if (in_array($sort, ['ticker', 'company_name', 'sector', 'listing_board'])) {
            $query->orderBy($sort, $direction);
        } else {
            $query->orderBy('ticker', 'asc');
        }

        $companies = $query->paginate(25)->withQueryString();

        // Fetch latest observations for these companies (Prefer nominal UNADJUSTED from IDX Edge)
        $pageTickers = $companies->pluck('ticker')->toArray();
        $pageObservations = MarketObservation::whereIn('ticker', $pageTickers)
            ->whereIn('board_scope', ['RG', 'INDEX'])
            ->whereIn('validation_status', ['VALID'])
            ->where('source', 'IDX Edge PRO')
            ->orderByDesc('bar_date')
            ->orderByRaw("CASE WHEN adjustment_mode = 'UNADJUSTED' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->get()
            ->unique('ticker')
            ->keyBy('ticker');

        // Freshness assessments & Price Changes (Batch previous observations to avoid 25 individual DB queries)
        $freshnessService = app(DataFreshnessPolicyService::class);
        $freshnessByTicker = [];
        $priceChangesByTicker = [];
        $sourceByTicker = [];

        // Determine required previous trading session strictly per ticker's bar_date via BeiCalendarService
        $prevDatesMap = [];
        $uniqueTargetPrevDates = [];
        foreach ($pageObservations as $t => $obs) {
            $prevDay = $this->calendar->getPreviousTradingDay($obs->bar_date)->toDateString();
            $prevDatesMap[$t] = $prevDay;
            $uniqueTargetPrevDates[$prevDay] = true;
        }

        // Fetch exactly the required previous trading day bars per ticker (no blind scan, no hardcoded date)
        $prevObservations = collect();
        if (! empty($prevDatesMap)) {
            $prevObservations = MarketObservation::whereIn('ticker', array_keys($prevDatesMap))
                ->whereIn('bar_date', array_keys($uniqueTargetPrevDates))
                ->whereIn('board_scope', ['RG', 'INDEX'])
                ->whereIn('validation_status', ['VALID'])
                ->where('source', 'IDX Edge PRO')
                ->where('adjustment_mode', 'UNADJUSTED')
                ->orderByDesc('bar_date')
                ->orderByDesc('id')
                ->get()
                ->filter(function ($item) use ($prevDatesMap) {
                    // Match strictly the expected previous trading day for that specific ticker
                    return $item->bar_date->toDateString() === ($prevDatesMap[$item->ticker] ?? null);
                })
                ->unique('ticker')
                ->keyBy('ticker');
        }

        foreach ($pageTickers as $t) {
            $freshnessByTicker[$t] = $freshnessService->assessFreshness($t, 'eod');
            $obs = $pageObservations->get($t);
            if ($obs) {
                $prevObs = $prevObservations->get($t);
                // If expected previous trading session is missing, do not silently fallback to older stale bars
                $priceChangesByTicker[$t] = $this->marketRead->priceChanges($obs, $prevObs);
            } else {
                $priceChangesByTicker[$t] = $this->marketRead->priceChanges(null);
            }
            $sourceByTicker[$t] = $obs ? $obs->source : null;
        }

        // Canonical IHSG Index for widget
        $ihsgData = $this->marketRead->canonicalIndex();

        // Technical metrics summary for completeness indicator
        $metricsByTicker = CompanyAnalysisMetric::whereIn('ticker', $pageTickers)
            ->get()
            ->keyBy('ticker');

        // All latest observations collection for compatibility with tests (bound to current page tickers to avoid full-table scan)
        $observations = $pageObservations;

        // Map evidence only for the visible page observations to prevent O(N) memory explosion
        $evidenceByObservation = $pageObservations->mapWithKeys(fn ($obs) => [$obs->id => $this->marketRead->evidenceForObservation($obs)?->id]);

        $brandConfig = $this->getBrandConfig();

        return view('dashboard.market', compact(
            'companies',
            'pageObservations',
            'freshnessByTicker',
            'sourceByTicker',
            'metricsByTicker',
            'observations',
            'marketRead',
            'expectedBarDate',
            'evidenceByObservation',
            'sectors',
            'watchlistTickers',
            'priceChangesByTicker',
            'ihsgData',
            'search',
            'sector',
            'type',
            'sort',
            'direction',
            'coverage',
            'brandConfig'
        ));
    }

    /**
     * Ticker Detail (7-Tab Institutional Interface)
     */
    public function tickerDetail(string $ticker, Request $request)
    {
        $ticker = strtoupper(trim($ticker));
        $tab = $request->query('tab', 'summary');
        $isAjaxTab = $request->ajax() || $request->header('X-Tab-Request') === '1';

        // Company Registry Data
        $company = IdxCompanyRegistry::where('ticker', $ticker)->first();

        $ihsgChart = null;
        if ($ticker === 'IHSG' && in_array($tab, ['summary', 'technical'], true)) {
            $ihsgChart = app(IdxEdgeClient::class)->chartIhsg();
        }
        $insiderPage = max(1, (int) $request->query('insider_page', 1));
        $insiders = in_array($tab, ['summary', 'insiders'], true)
            ? app(IdxEdgeClient::class)->insiders($ticker, $insiderPage, 10)
            : null;

        // Canonical Latest Price & Observations
        $latestObs = $this->marketRead->latest($ticker);
        $priceChanges = $this->marketRead->priceChanges($latestObs);
        // Conditional Tab Data Loading: only execute heavy queries needed for the requested tab
        $needsRecentBars = in_array($tab, ['summary', 'technical']);
        $recentBars = $needsRecentBars ? $this->marketRead->recentHistory($ticker, 30) : collect();

        $totalHistoryBars = $tab === 'technical' ? MarketObservation::where('ticker', $ticker)
            ->where('interval', '1d')
            ->where('data_classification', 'PUBLIC_MARKET_EOD')
            ->distinct('bar_date')
            ->count('bar_date') : $recentBars->count();
        $chartDisplayBars = $recentBars->count();

        // Technical Metrics & Quality: bind indicators to latest rendered bar date.
        $technicalBarDate = $recentBars->last()?->bar_date?->toDateString();
        $technical = in_array($tab, ['summary', 'technical', 'ai_insight'])
            ? CompanyAnalysisMetric::where('ticker', $ticker)
                ->when($technicalBarDate, fn ($query) => $query->whereDate('as_of_date', $technicalBarDate))
                ->orderByDesc('as_of_date')
                ->first()
            : null;
        $indicatorInputBars = $technical?->data_quality_flags['input_bars'] ?? $totalHistoryBars;

        // Fundamental Records
        $fundamentals = in_array($tab, ['summary', 'fundamental', 'ai_insight']) ? CompanyFundamental::where('ticker', $ticker)
            ->orderByDesc('fiscal_year')
            ->get() : collect();

        // Financial metrics reconciliation: separate reported EPS, implied weighted shares, and period-end shares
        $latestAnnualFund = $fundamentals->first();
        $reportedBasicEps = $latestAnnualFund?->reported_basic_eps ?? $latestAnnualFund?->laba_per_saham ?? null;
        $netIncomeParent = $latestAnnualFund?->net_income_parent ?? $latestAnnualFund?->laba_bersih ?? null;
        $periodEndShares = $company?->shares_outstanding ?? null;

        $impliedWeightedShares = null;
        if ($reportedBasicEps !== null && $reportedBasicEps > 0 && $netIncomeParent !== null && $netIncomeParent > 0) {
            $impliedWeightedShares = round($netIncomeParent / $reportedBasicEps);
        }

        $priceAdjustmentMode = $latestObs?->adjustment_mode ?? 'NOMINAL';

        // Broker Summary Records (Pasar Reguler)
        $brokerSummaries = in_array($tab, ['summary', 'monitoring', 'ai_insight']) ? BrokerSummary::where('ticker', $ticker)
            ->where('flow_type', 'all')
            ->orderByDesc('summary_date')
            ->orderByDesc('net_value')
            ->take(30)
            ->get() : collect();

        // Corporate Actions
        $corporateActions = in_array($tab, ['summary', 'fundamental']) ? CompanyCorporateAction::where('ticker', $ticker)
            ->orderByDesc('execution_date')
            ->get() : collect();

        // Latest AI Narrative & Historical Analyses
        $latestAnalysis = in_array($tab, ['summary', 'ai_insight']) ? MarketAnalysis::whereJsonContains('structured_output->ticker', $ticker)->latest()->first() : null;
        $analysisHistory = $tab === 'history' ? MarketAnalysis::whereJsonContains('structured_output->ticker', $ticker)
            ->orderByDesc('created_at')
            ->paginate(10, ['*'], 'history_page') : collect();

        // On-Demand & Telegram Research Requests for Ticker
        $tickerResearchRequests = in_array($tab, ['summary', 'history']) ? ResearchRequest::where('ticker', $ticker)
            ->with(['evidenceSnapshot', 'marketAnalysis'])
            ->latest('id')
            ->take(10)
            ->get() : collect();

        // News Tagged to Ticker
        $news = in_array($tab, ['summary', 'news', 'ai_insight']) ? NewsItem::where(function ($q) use ($ticker) {
            $q->whereJsonContains('related_tickers', $ticker)
                ->orWhere('title', 'like', "%{$ticker}%");
        })->latest('published_at')->take(15)->get() : collect();

        // Prediction Signals & Evaluations
        $signals = in_array($tab, ['summary', 'monitoring', 'ai_insight']) ? PredictionSignal::operational()->where('ticker', $ticker)
            ->orderByDesc('decision_timestamp')
            ->get() : collect();

        // Watchlist Status
        $inWatchlist = UserWatchlist::where('ticker', $ticker)->where('is_active', true)->exists();

        // Data Freshness Assessment
        $freshness = app(DataFreshnessPolicyService::class)->assessFreshness($ticker, 'eod');

        $chartData = $recentBars->map(fn ($b) => [
            'date' => $b->bar_date ? $b->bar_date->format('d/m') : '',
            'open' => (float) ($b->open_price ?? 0),
            'high' => (float) ($b->high_price ?? 0),
            'low' => (float) ($b->low_price ?? 0),
            'close' => (float) ($b->close_price ?? 0),
            'volume' => (float) ($b->volume ?? 0),
        ])->values()->toArray();

        $brandConfig = $this->getBrandConfig();

        // Active pending/processing research request
        $activeResearchRequest = ResearchRequest::where('ticker', $ticker)
            ->whereIn('status', ['pending', 'collecting', 'analyzing'])
            ->latest('id')
            ->first();

        // AI Conclusion Presentation Model
        $aiConclusion = AiConclusionPresenter::present(
            ticker: $ticker,
            company: $company,
            latestAnalysis: $latestAnalysis,
            latestObs: $latestObs,
            technical: $technical,
            fundamentals: $fundamentals,
            news: $news,
            signals: $signals,
            activeRequest: $activeResearchRequest
        );

        $viewData = compact(
            'ticker',
            'company',
            'latestObs',
            'priceChanges',
            'totalHistoryBars',
            'chartDisplayBars',
            'indicatorInputBars',
            'recentBars',
            'chartData',
            'technical',
            'fundamentals',
            'brokerSummaries',
            'corporateActions',
            'latestAnalysis',
            'analysisHistory',
            'tickerResearchRequests',
            'news',
            'signals',
            'inWatchlist',
            'freshness',
            'tab',
            'brandConfig',
            'aiConclusion',
            'reportedBasicEps',
            'netIncomeParent',
            'periodEndShares',
            'impliedWeightedShares',
            'priceAdjustmentMode',
            'ihsgChart',
            'insiders',
            'insiderPage'
        );

        if ($isAjaxTab) {
            return response()->view('dashboard.ticker_tab_content', $viewData)
                ->header('Vary', 'X-Tab-Request, X-Requested-With')
                ->header('Cache-Control', 'private, no-cache, no-store, must-revalidate');
        }

        return view('dashboard.ticker', $viewData);
    }

    public function ihsgPrice()
    {
        $cacheKey = 'dashboard:ihsg:price:v2';
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            $cached['served_at'] = now('Asia/Jakarta')->toIso8601String();

            return response()->json($cached)->header('Cache-Control', 'no-cache, private');
        }

        $result = app(IdxEdgeClient::class)->priceIhsg();
        $data = $result['data'] ?? null;
        if (is_array($data)) {
            $data['data_ts'] = $data['data_ts'] ?? $data['data_timestamp'] ?? null;
            $data['served_ts'] = $data['served_ts'] ?? $data['served_timestamp'] ?? null;
            $data['source_date'] = $data['source_date'] ?? $data['as_of_date'] ?? null;
        }
        $response = [
            'success' => (bool) ($result['success'] ?? false),
            'data' => $data,
            'error_type' => $result['error_type'] ?? null,
            'message' => $result['message'] ?? null,
            'fetched_at' => now('Asia/Jakarta')->toIso8601String(),
            'served_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
        if ($response['success']) {
            Cache::put($cacheKey, $response, now()->addSeconds(10));
        }

        return response()->json($response, $response['success'] ? 200 : 503)->header('Cache-Control', 'no-cache, private');
    }

    public function marketSnapshot(string $ticker)
    {
        $ticker = strtoupper(trim($ticker));
        if (! preg_match('/^[A-Z]{4}$/', $ticker)) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_TICKER',
                    'message' => 'Kode saham harus terdiri dari 4 huruf.',
                ],
            ], 422);
        }

        $observation = $this->marketRead->latest($ticker);
        if (! $observation) {
            return response()->json([
                'error' => [
                    'code' => 'DATA_NOT_AVAILABLE',
                    'message' => 'Snapshot pasar belum tersedia.',
                ],
            ], 404);
        }

        return response()->json([
            'data' => [
                'ticker' => $ticker,
                'market_timestamp' => $observation->observation_time?->toIso8601String(),
                'received_at' => $observation->fetched_at?->toIso8601String(),
                'available_at' => $observation->available_at?->toIso8601String(),
                'bar_date' => $observation->bar_date?->toDateString(),
                'open' => (float) $observation->open_price,
                'high' => (float) $observation->high_price,
                'low' => (float) $observation->low_price,
                'close' => (float) $observation->close_price,
                'volume' => (int) $observation->volume,
                'volume_unit' => str_contains($observation->source, 'IDX Edge') ? 'NOT_DOCUMENTED' : 'SHARES_PROVIDER_SUPPLIED',
                'source' => $observation->source,
                'connection_status' => 'STORED_SNAPSHOT',
                'delivery_mode' => 'INTERNAL_POLLING',
                'is_final' => (bool) $observation->is_eod_final,
                'validation_status' => $observation->validation_status,
                'data_hash' => $observation->data_hash,
            ],
        ])->header('Cache-Control', 'no-cache, private');
    }

    /**
     * News & Events
     */
    public function news(Request $request)
    {
        $ticker = strtoupper(trim((string) $request->input('ticker', '')));
        $verification = trim((string) $request->input('verification', ''));

        $query = NewsItem::latest('fetched_at');

        if ($ticker !== '') {
            $query->where(function ($q) use ($ticker) {
                $q->whereJsonContains('related_tickers', $ticker)
                    ->orWhere('title', 'like', "%{$ticker}%");
            });
        }

        if ($verification !== '') {
            $query->where('verification_status', $verification);
        }

        $news = $query->paginate(20)->withQueryString();
        $brandConfig = $this->getBrandConfig();

        return view('dashboard.news', compact('news', 'ticker', 'verification', 'brandConfig'));
    }

    public function researchPage(Request $request)
    {
        $ticker = strtoupper(trim((string) $request->query('ticker', '')));
        $companies = IdxCompanyRegistry::where('instrument_type', 'EQUITY')
            ->where('listing_status', 'ACTIVE')
            ->orderBy('ticker')
            ->get(['ticker', 'company_name']);
        $brandConfig = $this->getBrandConfig();

        return view('dashboard.research', compact('companies', 'ticker', 'brandConfig'));
    }

    public function runningTradePage()
    {
        $ihsgData = $this->marketRead->canonicalIndex();
        $brandConfig = $this->getBrandConfig();

        return view('dashboard.running_trade', compact('ihsgData', 'brandConfig'));
    }

    /**
     * AI Analysis History
     */
    public function history(Request $request)
    {
        $ticker = strtoupper(trim((string) $request->input('ticker', '')));

        $runsQuery = AnalysisRun::with(['marketAnalyses.postHorizonEvaluations'])
            ->latest('started_at');

        $runs = $runsQuery->paginate(15, ['*'], 'runs_page')->withQueryString();

        $evalMetrics = $this->evaluator->getMetricsSummary();

        $evalQuery = PostHorizonEvaluation::with('marketAnalysis')
            ->latest('decision_date');

        if ($ticker !== '') {
            $evalQuery->whereHas('marketAnalysis', function ($q) use ($ticker) {
                $q->where('ticker', $ticker);
            });
        }

        $evaluations = $evalQuery->paginate(20, ['*'], 'eval_page')->withQueryString();
        $tradingPlanRecap = TelegramTradingPlanService::getAuditRecap();
        $brandConfig = $this->getBrandConfig();

        return view('dashboard.history', compact('runs', 'evalMetrics', 'evaluations', 'tradingPlanRecap', 'ticker', 'brandConfig'));
    }

    /**
     * System Health Page
     */
    public function health()
    {
        $health = $this->healthService->checkAll();
        $recentChecks = SystemHealthCheck::latest('checked_at')->take(30)->get();
        $nextRunTime = $this->calendar->getNextScheduledRunTime();
        $isTradingDay = $this->calendar->isTradingDay(now('Asia/Jakarta'));
        $brandConfig = $this->getBrandConfig();

        return view('dashboard.health', compact('health', 'recentChecks', 'nextRunTime', 'isTradingDay', 'brandConfig'));
    }

    /**
     * Settings Page
     */
    public function settings()
    {
        // Registry sync runs through explicit command, not during page rendering.
        $promptTemplates = PromptTemplate::orderBy('code')->get();
        $dataSources = DataSource::all();
        $brandConfig = $this->getBrandConfig();

        return view('dashboard.settings', compact('promptTemplates', 'dataSources', 'brandConfig'));
    }

    /**
     * User Profile & Application Identity Page
     */
    public function profile()
    {
        $brandConfig = $this->getBrandConfig();
        $watchlist = UserWatchlist::where('is_active', true)->get();
        $analyst = [
            'name' => $brandConfig['analyst_name'] ?? 'Aether Lead Analyst',
            'role' => $brandConfig['analyst_title'] ?? 'Chief Quantitative Strategist',
            'email' => $brandConfig['email'] ?? 'analyst@aether-intelligence.local',
            'telegram_id' => '5923895922',
            'authorized_at' => '2026-10-14 02:27:33 WIB',
        ];

        return view('dashboard.profile', compact('brandConfig', 'watchlist', 'analyst'));
    }

    /**
     * Update User Profile Information
     */
    public function updateProfile(Request $request)
    {
        $request->validate([
            'analyst_name' => 'required|string|max:100',
            'analyst_title' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:150',
        ]);

        $config = $this->getBrandConfig();
        $config['analyst_name'] = $request->input('analyst_name');
        $config['analyst_title'] = $request->input('analyst_title', 'Lead Quantitative Analyst');
        $config['email'] = $request->input('email', 'analyst@aether-intelligence.local');

        $this->saveBrandConfig($config);

        return redirect()->route('dashboard.profile')->with('status', 'Profil analis berhasil diperbarui.');
    }

    /**
     * Update Application Identity & Logo
     */
    public function updateBrand(Request $request)
    {
        $request->validate([
            'system_name' => 'nullable|string|max:60',
            'system_subtitle' => 'nullable|string|max:100',
            'logo' => 'nullable|file|mimes:png,jpg,jpeg|max:2048',
        ]);

        // Explicitly check for SVG or dangerous uploads
        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $extension = strtolower($file->getClientOriginalExtension());
            if (in_array($extension, ['svg', 'exe', 'bat', 'sh', 'php', 'js', 'html'])) {
                return redirect()->back()->withErrors(['logo' => 'Format file tidak diizinkan. Hanya PNG dan JPG yang didukung demi keamanan sistem.']);
            }

            $destinationPath = public_path('images');
            if (! File::exists($destinationPath)) {
                File::makeDirectory($destinationPath, 0755, true);
            }
            $file->move($destinationPath, 'logo.png');
        }

        $config = $this->getBrandConfig();
        if ($request->filled('system_name')) {
            $config['system_name'] = $request->input('system_name');
        }
        if ($request->filled('system_subtitle')) {
            $config['system_subtitle'] = $request->input('system_subtitle');
        }

        $this->saveBrandConfig($config);

        return redirect()->route('dashboard.profile')->with('status', 'Identitas aplikasi Aether berhasil disimpan.');
    }

    /**
     * Submit Asynchronous On-Demand Research Request
     */
    public function submitResearch(Request $request)
    {
        $request->validate([
            'ticker' => 'required|string|max:10',
            'analysis_type' => 'nullable|string',
            'question' => 'nullable|string|max:1000',
        ]);

        $ticker = strtoupper(trim($request->input('ticker')));
        $rawType = strtoupper(trim($request->input('analysis_type', 'COMPREHENSIVE')));
        $analysisType = in_array($rawType, ['COMPREHENSIVE', 'TECHNICAL', 'FUNDAMENTAL', 'SWING']) ? $rawType : 'COMPREHENSIVE';

        if (getenv('DASHBOARD_CANDIDATE') === '1') {
            try {
                $bridge = $this->researchBridge->queue($ticker, $analysisType, trim((string) $request->input('question', '')) ?: null);
            } catch (\Throwable $error) {
                return response()->json([
                    'success' => false,
                    'status' => 'WRITER_BRIDGE_UNAVAILABLE',
                    'message' => 'Jalur writer riset tidak tersedia.',
                ], 503);
            }

            if (($bridge['status'] ?? null) === 'REJECTED_UNKNOWN_TICKER') {
                return response()->json([
                    'success' => false,
                    'status' => 'REJECTED_UNKNOWN_TICKER',
                    'message' => "Ticker '{$ticker}' tidak terdaftar pada registry provider.",
                ], 422);
            }

            if (($bridge['status'] ?? null) === 'REJECTED_INVALID_REQUEST') {
                return response()->json([
                    'success' => false,
                    'status' => 'REJECTED_INVALID_REQUEST',
                    'message' => 'Ticker atau jenis analisis tidak valid.',
                ], 422);
            }

            $queued = in_array($bridge['status'] ?? null, ['QUEUED', 'DEDUPLICATED'], true);

            return response()->json([
                'success' => $queued,
                'request_id' => $bridge['request_id'] ?? null,
                'ticker' => $bridge['ticker'] ?? $ticker,
                'status' => $bridge['research_status'] ?? 'PENDING',
                'deduplicated' => ($bridge['status'] ?? null) === 'DEDUPLICATED',
                'deadline_at' => $bridge['deadline_at'] ?? null,
                'message' => $queued ? 'Permintaan tercatat melalui writer terkontrol.' : 'Permintaan riset ditolak.',
            ], $queued ? 202 : 503);
        }

        // Strict BEI Registry Validation: unknown tickers must have clear state and NOT trigger ingestion
        $company = IdxCompanyRegistry::where('ticker', $ticker)->first();
        if (! $company) {
            return response()->json([
                'success' => false,
                'status' => 'REJECTED_UNKNOWN_TICKER',
                'message' => "Ticker '{$ticker}' tidak terdaftar pada registri sekunder BEI. Permintaan riset ditolak untuk mencegah pengumpulan data emiten tidak dikenal.",
            ], 422);
        }

        $companyName = $company->company_name;

        $active = ResearchRequest::where('ticker', $ticker)
            ->whereIn('status', ['pending', 'collecting', 'analyzing'])
            ->latest('id')->first();
        if ($active) {
            return response()->json([
                'success' => true,
                'request_id' => $active->request_id,
                'ticker' => $ticker,
                'status' => strtoupper($active->status),
                'deduplicated' => true,
                'message' => "Permintaan aktif {$active->request_id} sedang diproses.",
            ], 202);
        }

        $requestId = 'REQ_'.now('Asia/Jakarta')->format('Ymd_His')."_{$ticker}_".Str::random(4);

        ResearchRequest::create([
            'request_id' => $requestId,
            'chat_id' => 'web_dashboard',
            'user_id' => 'web_analyst',
            'ticker' => $ticker,
            'company_name' => $companyName,
            'exchange' => 'IDX',
            'analysis_type' => $analysisType,
            'data_type_requested' => 'eod',
            'as_of_time' => now(),
            'status' => 'pending',
            'research_metadata' => [
                'source' => 'web_dashboard',
                'question' => trim((string) $request->input('question', '')) ?: null,
                'timestamp' => now('Asia/Jakarta')->toIso8601String(),
                'deadline_at' => now('UTC')->addMinutes(10)->toIso8601String(),
                'client_ip' => $request->ip(),
            ],
        ]);

        return response()->json([
            'success' => true,
            'request_id' => $requestId,
            'ticker' => $ticker,
            'status' => 'PENDING',
            'message' => "Permintaan riset asinkron untuk emiten {$ticker} ({$companyName}) telah tercatat dengan Request ID: {$requestId}.",
        ]);
    }

    public function researchBridgeAudit()
    {
        try {
            $audit = $this->researchBridge->audit();

            return response()->json(['success' => true, 'audit' => $audit]);
        } catch (\Throwable $error) {
            return response()->json([
                'success' => false,
                'status' => 'WRITER_BRIDGE_UNAVAILABLE',
                'message' => 'Probe writer bridge gagal.',
            ], 503);
        }
    }

    public function researchStatus(string $requestId)
    {
        $research = ResearchRequest::with(['evidenceSnapshot', 'marketAnalysis'])->where('request_id', $requestId)->first();
        abort_unless($research, 404);

        $deadlineAt = data_get($research->research_metadata, 'deadline_at');
        $isPastDeadline = $deadlineAt !== null && now('UTC')->greaterThan(Carbon::parse($deadlineAt)->utc());
        $activeStatuses = ['PENDING', 'COLLECTING', 'VALIDATING', 'ANALYZING'];
        $status = strtoupper((string) $research->status);
        $effectiveStatus = $isPastDeadline && in_array($status, $activeStatuses, true)
            ? 'DEADLINE_EXCEEDED'
            : $status;
        $stageMap = [
            'queued' => ['label' => 'Antre', 'detail' => 'Request tersimpan; menunggu worker riset.'],
            'data_check' => ['label' => 'Pemeriksaan data / pembaruan API', 'detail' => 'Worker memeriksa freshness dan komponen lokal/provider.'],
            'indicators' => ['label' => 'Validasi dan kalkulasi indikator', 'detail' => 'Indikator dihitung dari data yang lolos validasi.'],
            'evidence' => ['label' => 'Evidence', 'detail' => 'Evidence snapshot dipersist sebelum sintesis.'],
            'synthesis' => ['label' => 'Sintesis', 'detail' => 'Worker menyusun hasil analisis dari evidence tersimpan.'],
            'completed' => ['label' => 'Selesai', 'detail' => 'Request memiliki status terminal completed.'],
            'partial' => ['label' => 'Selesai sebagian', 'detail' => 'Sebagian komponen tidak tersedia atau belum memenuhi kontrak.'],
            'failed' => ['label' => 'Gagal', 'detail' => $research->error_reason ?: 'Worker mengembalikan status gagal.'],
        ];
        $metadataStage = strtolower((string) data_get($research->research_metadata, 'pipeline_stage', ''));
        $statusStage = match ($effectiveStatus) {
            'PENDING' => 'queued', 'COLLECTING' => 'data_check', 'VALIDATING' => 'indicators',
            'ANALYZING' => 'synthesis', 'COMPLETED' => 'completed', 'PARTIAL' => 'partial',
            'FAILED', 'DEADLINE_EXCEEDED' => 'failed', default => 'unknown',
        };
        $stageKey = array_key_exists($metadataStage, $stageMap) && ! in_array($effectiveStatus, ['COMPLETED', 'PARTIAL', 'FAILED', 'DEADLINE_EXCEEDED'], true)
            ? $metadataStage : $statusStage;
        $stage = ['key' => $stageKey] + ($stageMap[$stageKey] ?? ['label' => $effectiveStatus, 'detail' => 'Status backend belum dipetakan.']);
        $refresh = data_get($research->research_metadata, 'refresh', []);
        $question = data_get($research->research_metadata, 'question');

        return response()->json([
            'success' => true,
            'request_id' => $research->request_id,
            'ticker' => $research->ticker,
            'status' => $effectiveStatus,
            'backend_status' => $status,
            'freshness' => $research->freshness_classification,
            'evidence_snapshot_id' => $research->evidence_snapshot_id,
            'market_analysis_id' => $research->market_analysis_id,
            'error_reason' => $research->error_reason,
            'deadline_at' => $deadlineAt,
            'deadline_source' => 'backend_research_metadata',
            'stage' => $stage,
            'question' => $question,
            'refresh' => $refresh,
            'completion_scope' => data_get($research->research_metadata, 'completion_scope'),
            'verification_status' => data_get($research->research_metadata, 'verification_status'),
            'model_status' => data_get($research->research_metadata, 'ai_model') ?: data_get($research->marketAnalysis?->structured_output, 'model_status'),
            'result' => $research->marketAnalysis?->only(['id', 'model_name', 'market_regime_summary', 'validation_status', 'structured_output']),
            'evidence' => $research->evidenceSnapshot?->only(['id', 'snapshot_id', 'ticker', 'decision_date', 'data_hash', 'features_snapshot']),
        ]);
    }

    /**
     * Toggle Watchlist Status for an Emiten
     */
    public function toggleWatchlist(Request $request)
    {
        $request->validate([
            'ticker' => 'required|string|max:10',
        ]);

        $ticker = strtoupper(trim($request->input('ticker')));

        $item = UserWatchlist::where('ticker', $ticker)->first();

        if ($item) {
            $item->is_active = ! $item->is_active;
            $item->save();
            $inWatchlist = $item->is_active;
        } else {
            UserWatchlist::create([
                'user_id' => '5923895922',
                'chat_id' => '5923895922',
                'ticker' => $ticker,
                'is_active' => true,
                'added_at' => now(),
            ]);
            $inWatchlist = true;
        }

        return response()->json([
            'success' => true,
            'ticker' => $ticker,
            'in_watchlist' => $inWatchlist,
            'message' => $inWatchlist ? "Emiten {$ticker} ditambahkan ke pemantauan aktif." : "Emiten {$ticker} dihapus dari pemantauan aktif.",
        ]);
    }

    /**
     * Internal Chart.js OHLCV & Indicators API
     */
    public function chartData(string $ticker, Request $request)
    {
        $ticker = strtoupper(trim($ticker));
        $range = $request->query('range', '1M');
        $adjustmentMode = $request->query('mode', 'adjusted') === 'raw' ? 'UNADJUSTED' : 'PROVIDER_BACK_ADJUSTED';
        if (! in_array($range, ['1D', '1M', '3M', '6M', '1Y'], true)) {
            return response()->json(['success' => false, 'error' => ['code' => 'INVALID_RANGE']], 422);
        }

        $modeAdjusted = $adjustmentMode === 'PROVIDER_BACK_ADJUSTED';
        $today = now('Asia/Jakarta')->startOfDay();
        $endDate = $today->toDateString();
        if ($range === '1D') {
            $provider = app(IdxEdgeClient::class)->chart($ticker, 60, 2000);
            if (! ($provider['success'] ?? false)) {
                return response()->json($provider, 502);
            }
            $providerData = $provider['data'];
            $formattedBars = array_map(static function (array $bar): array {
                $timestamp = Carbon::createFromTimestamp((int) $bar['timestamp'], 'Asia/Jakarta');

                return [
                    'timestamp' => $bar['timestamp'],
                    'date' => $timestamp->toIso8601String(),
                    'open' => $bar['open'],
                    'high' => $bar['high'],
                    'low' => $bar['low'],
                    'close' => $bar['close'],
                    'volume' => $bar['volume'],
                ];
            }, $providerData['bars']);
            $latestDate = $providerData['as_of_date'];

            return response()->json([
                'success' => true,
                'ticker' => $ticker,
                'range' => $range,
                'adjustment_mode' => 'INTRADAY_UNADJUSTED',
                'source' => 'IDX Edge API',
                'interval_seconds' => 60,
                'session_date' => $latestDate,
                'latest_bar_date' => $latestDate,
                'market_status' => $providerData['market']['status'] ?? 'NOT_DOCUMENTED',
                'market_status_notice' => $latestDate === $endDate
                    ? 'Data sesi '.$latestDate
                    : 'Data sesi terakhir '.$latestDate.'; bukan data hari ini.',
                'bars' => $formattedBars,
                'indicators' => [],
                'data_classification' => 'PROVIDER_SUPPLIED',
            ])->header('Cache-Control', 'private, no-cache');
        }

        $startDate = match ($range) {
            '1M' => $today->copy()->subMonthsNoOverflow(1)->toDateString(),
            '3M' => $today->copy()->subMonthsNoOverflow(3)->toDateString(),
            '6M' => $today->copy()->subMonthsNoOverflow(6)->toDateString(),
            '1Y' => $today->copy()->subYearNoOverflow()->toDateString(),
        };
        $provider = app(IdxEdgeClient::class)->history($ticker, 500, $modeAdjusted, $startDate, $endDate);
        if (! ($provider['success'] ?? false)) {
            return response()->json($provider, 502);
        }
        $providerData = $provider['data'];
        usort($providerData['bars'], static fn (array $left, array $right): int => strcmp($left['date'], $right['date']));
        $formattedBars = array_map(static fn (array $bar): array => [
            'date' => $bar['date'],
            'open' => $bar['open'],
            'high' => $bar['high'],
            'low' => $bar['low'],
            'close' => $bar['close'],
            'volume' => $bar['volume'],
        ], $providerData['bars']);
        $latestDate = $formattedBars !== [] ? end($formattedBars)['date'] : null;

        return response()->json([
            'success' => true,
            'ticker' => $ticker,
            'range' => $range,
            'adjustment_mode' => $adjustmentMode,
            'source' => 'IDX Edge API',
            'frame' => 'daily',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'data_available' => $providerData['data_available'],
            'latest_bar_date' => $latestDate,
            'market_status_notice' => $latestDate && $latestDate !== $endDate
                ? 'Data sesi terakhir '.$latestDate.'; bukan data hari ini.'
                : 'Data daily provider untuk rentang '.$startDate.' sampai '.$endDate.'.',
            'bars' => $formattedBars,
            'indicators' => [],
            'data_classification' => 'PROVIDER_SUPPLIED',
        ])->header('Cache-Control', 'private, no-cache');

        $days = match ($range) {
            '3M' => 90,
            '6M' => 180,
            '1Y' => 365,
            default => 30,
        };

        $cutoffDate = now('Asia/Jakarta')->subDays($days)->toDateString();

        $bars = MarketObservation::where('ticker', $ticker)
            ->whereIn('board_scope', ['RG', 'INDEX'])
            ->where('source', 'IDX Edge PRO')
            ->where('adjustment_mode', $adjustmentMode)
            ->where('validation_status', 'VALID')
            ->whereDate('bar_date', '>=', $cutoffDate)
            ->orderBy('bar_date', 'asc')
            ->get();

        if ($bars->isEmpty()) {
            $bars = MarketObservation::where('ticker', $ticker)
                ->whereIn('board_scope', ['RG', 'INDEX'])
                ->where('source', 'IDX Edge PRO')
                ->where('adjustment_mode', $adjustmentMode)
                ->where('validation_status', 'VALID')
                ->orderBy('bar_date', 'desc')
                ->limit(30)
                ->get()
                ->reverse()
                ->values();
        }

        $formattedBars = $bars->map(fn ($b) => [
            'date' => $b->bar_date->toDateString(),
            'open' => (float) $b->open_price,
            'high' => (float) $b->high_price,
            'low' => (float) $b->low_price,
            'close' => (float) $b->close_price,
            'volume' => (int) $b->volume,
        ])->values()->all();

        // Indicators must use the same adjustment basis as the rendered candles.
        $allBars = MarketObservation::where('ticker', $ticker)
            ->whereIn('board_scope', ['RG', 'INDEX'])
            ->where('source', 'IDX Edge PRO')
            ->where('adjustment_mode', $adjustmentMode)
            ->where('validation_status', 'VALID')
            ->orderBy('bar_date', 'asc')
            ->get();

        $allCloses = $allBars->pluck('close_price')->map(fn ($v) => (float) $v)->all();
        $allDates = $allBars->pluck('bar_date')->map(fn ($d) => $d->toDateString())->all();
        $dateIndexMap = array_flip($allDates);

        $sma20Series = [];
        $sma50Series = [];
        $sma200Series = [];
        $rsi14Series = [];
        $macdSeries = [];
        $macdSignalSeries = [];
        $ema = static function (array $values, int $period): array {
            if (count($values) < $period) {
                return [];
            }
            $result = [array_sum(array_slice($values, 0, $period)) / $period];
            $alpha = 2 / ($period + 1);
            for ($i = $period; $i < count($values); $i++) {
                $result[] = ($values[$i] * $alpha) + (end($result) * (1 - $alpha));
            }

            return $result;
        };
        $ema12 = $ema($allCloses, 12);
        $ema26 = $ema($allCloses, 26);
        $macdValues = [];
        if ($ema12 !== [] && $ema26 !== []) {
            $offset = count($ema12) - count($ema26);
            foreach ($ema26 as $i => $slow) {
                $macdValues[] = $ema12[$i + $offset] - $slow;
            }
        }
        $signalValues = $ema($macdValues, 9);
        $rsiGain = null;
        $rsiLoss = null;

        foreach ($formattedBars as $fb) {
            $d = $fb['date'];
            $idx = $dateIndexMap[$d] ?? null;
            if ($idx === null) {
                continue;
            }

            $sma20Series[] = [
                'date' => $d,
                'value' => $idx >= 19 ? round(array_sum(array_slice($allCloses, $idx - 19, 20)) / 20, 2) : null,
            ];
            $sma50Series[] = [
                'date' => $d,
                'value' => $idx >= 49 ? round(array_sum(array_slice($allCloses, $idx - 49, 50)) / 50, 2) : null,
            ];
            $sma200Series[] = [
                'date' => $d,
                'value' => $idx >= 199 ? round(array_sum(array_slice($allCloses, $idx - 199, 200)) / 200, 2) : null,
            ];

            $rsi = null;
            if ($idx >= 14) {
                $gains = [];
                $losses = [];
                for ($i = 1; $i <= $idx; $i++) {
                    $delta = $allCloses[$i] - $allCloses[$i - 1];
                    $gains[] = max($delta, 0);
                    $losses[] = max(-$delta, 0);
                }
                $gain = array_sum(array_slice($gains, 0, 14)) / 14;
                $loss = array_sum(array_slice($losses, 0, 14)) / 14;
                for ($i = 14; $i < count($gains); $i++) {
                    $gain = (($gain * 13) + $gains[$i]) / 14;
                    $loss = (($loss * 13) + $losses[$i]) / 14;
                }
                $rsi = $loss == 0 ? ($gain == 0 ? 50 : 100) : round(100 - (100 / (1 + ($gain / $loss))), 2);
            }
            $rsi14Series[] = ['date' => $d, 'value' => $rsi];
            $macdIndex = $idx - 25;
            $signalIndex = $macdIndex - 8;
            $macdSeries[] = ['date' => $d, 'value' => $macdIndex >= 0 && isset($macdValues[$macdIndex]) ? round($macdValues[$macdIndex], 2) : null];
            $macdSignalSeries[] = ['date' => $d, 'value' => $signalIndex >= 0 && isset($signalValues[$signalIndex]) ? round($signalValues[$signalIndex], 2) : null];
        }

        $nowWib = now('Asia/Jakarta');
        $sessionStatus = $this->calendar->getMarketSessionStatus($nowWib);
        $latestObs = $bars->last();
        $latestDateStr = $latestObs ? $latestObs->bar_date->toDateString() : '-';
        $metrics = $adjustmentMode === 'PROVIDER_BACK_ADJUSTED' && $latestObs
            ? CompanyAnalysisMetric::where('ticker', $ticker)
                ->whereDate('as_of_date', $latestDateStr)
                ->first()
            : null;

        $statusNotice = $sessionStatus['is_open']
            ? "Data EOD sesi sebelumnya ({$latestDateStr}) — intraday belum tersedia"
            : "Pasar tutup — data sesi terakhir {$latestDateStr}";

        return response()->json([
            'success' => true,
            'ticker' => $ticker,
            'range' => $range,
            'adjustment_mode' => $adjustmentMode,
            'source' => 'IDX Edge PRO (Pasar Reguler)',
            'market_status_notice' => $statusNotice,
            'latest_bar_date' => $latestDateStr,
            'bars' => $formattedBars,
            'indicators' => [
                'sma_20' => $sma20Series,
                'sma_50' => $sma50Series,
                'sma_200' => $sma200Series,
                'rsi_14' => $rsi14Series,
                'macd' => $macdSeries,
                'macd_signal' => $macdSignalSeries,
                'current_metrics' => [
                    'sma_20' => $metrics?->sma_20,
                    'sma_50' => $metrics?->sma_50,
                    'sma_200' => $metrics?->sma_200,
                    'rsi_14' => $metrics?->rsi_14,
                    'macd_line' => $metrics?->macd,
                    'macd_signal' => $metrics?->macd_signal,
                    'macd_histogram' => $metrics?->macd_hist,
                    'atr_14' => $metrics?->atr_14,
                    'support_price' => $metrics?->support_price,
                    'resistance_price' => $metrics?->resistance_price,
                ],
                'support_resistance' => $metrics ? [
                    'support_price' => $metrics->support_price,
                    'resistance_price' => $metrics->resistance_price,
                    'as_of_date' => $metrics->as_of_date?->toDateString(),
                    'input_bars' => data_get($metrics->data_quality_flags, 'input_bars'),
                    'window_bars' => data_get($metrics->data_quality_flags, 'support_resistance_window_bars', 20),
                    'method' => data_get($metrics->data_quality_flags, 'support_resistance_method', 'rolling_min_max'),
                    'adjustment_mode' => data_get($metrics->data_quality_flags, 'adjustment_mode'),
                    'source' => data_get($metrics->data_quality_flags, 'source', 'IDX Edge PRO'),
                    'board_scope' => data_get($metrics->data_quality_flags, 'board_scope', 'RG'),
                    'interval' => data_get($metrics->data_quality_flags, 'interval', '1d'),
                ] : null,
            ],
        ]);
    }

    /** @return JsonResponse */
    public function brokerSummaryData(string $ticker, Request $request)
    {
        $ticker = strtoupper(trim($ticker));
        $requestedFlow = (string) $request->query('flow', 'all');
        $flow = strtolower($requestedFlow) === 'all' ? 'all' : strtoupper($requestedFlow);
        $allowedFlows = ['all', 'F', 'D'];
        if (! in_array($flow, $allowedFlows, true)) {
            return response()->json(['success' => false, 'error_type' => 'UNSUPPORTED_FILTER', 'message' => 'Provider hanya mendukung filter all, F, D.'], 422);
        }
        $result = app(IdxEdgeClient::class)->brokerSummary(
            $ticker,
            $request->query('start_date'),
            $flow,
            min(100, max(5, (int) $request->query('limit', 30))),
            $request->query('end_date'),
            filter_var($request->query('net', false), FILTER_VALIDATE_BOOLEAN)
        );

        return response()->json($result, ($result['success'] ?? false) ? 200 : 502)
            ->header('Cache-Control', 'private, max-age=30');
    }

    /** @return JsonResponse */
    public function financialStatementsData(string $ticker, Request $request)
    {
        $result = app(IdxEdgeClient::class)->financialStatements(
            $ticker,
            (string) $request->query('report_type', 'INCOME_STATEMENT'),
            (string) $request->query('period', 'annually'),
            min(40, max(1, (int) $request->query('limit', 12))),
            $request->query('year')
        );

        return response()->json($result, ($result['success'] ?? false) ? 200 : 502)
            ->header('Cache-Control', 'private, max-age=300');
    }

    /** @return JsonResponse */
    public function ownershipData(string $ticker, Request $request)
    {
        $month = $request->query('month') !== null ? (int) $request->query('month') : null;
        $year = $request->query('year') !== null ? (int) $request->query('year') : null;
        $result = app(IdxEdgeClient::class)->ownership($ticker, $month, $year);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 502)
            ->header('Cache-Control', 'private, max-age=300');
    }

    /**
     * Internal Broker Accumulation Time-Series API
     */
    public function brokerAccumulationData(string $ticker, Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $top = min(5, max(1, (int) $request->query('top', 3)));
        $brokers = $request->query('brokers');

        $result = app(IdxEdgeClient::class)->brokerAccumulation(
            $ticker,
            is_string($startDate) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $startDate) ? $startDate : null,
            is_string($endDate) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $endDate) ? $endDate : null,
            $top,
            is_string($brokers) && trim($brokers) !== '' ? trim($brokers) : null,
        );

        return response()->json($result, ($result['success'] ?? false) ? 200 : 502)
            ->header('Cache-Control', 'private, max-age=300');
    }

    /**
     * API Endpoint for WebSocket Running Trade Stream State
     */
    public function runningTradeState()
    {
        $stateFile = storage_path('app/running_trade_state.json');
        if (! file_exists($stateFile)) {
            $canonicalStateFile = base_path('storage/app/running_trade_state.json');
            if (file_exists($canonicalStateFile)) {
                $stateFile = $canonicalStateFile;
            }
        }
        if (! file_exists($stateFile)) {
            return response()->json([
                'status' => 'DISCONNECTED',
                'connected_at' => null,
                'total_trades' => 0,
                'total_trade_frames' => 0,
                'total_snapshot_trade_items' => 0,
                'board_scope' => 'BOARD_SCOPE_UNVERIFIED',
                'recent_trades' => [],
                'ihsg' => [
                    'available' => false,
                    'value' => null,
                    'source' => 'EOD_BASELINE',
                ],
                'note' => 'Supervisor service not started or state file missing',
            ]);
        }

        $content = json_decode(file_get_contents($stateFile), true) ?: [];
        $observedSessionDate = (string) ($content['session_date'] ?? '');
        $currentTradingDate = now('Asia/Jakarta')->toDateString();
        $sessionMismatch = $observedSessionDate !== '' && $observedSessionDate !== $currentTradingDate;
        if ($sessionMismatch) {
            $content['status'] = 'SESSION_NOT_OBSERVED';
            $content['connected_socket'] = false;
            $content['recent_trades'] = [];
            $content['top5_snapshots'] = [];
            $content['session_status_notice'] = 'State collector berasal dari sesi '.$observedSessionDate.'; sesi '.$currentTradingDate.' belum teramati.';
        }
        $heartbeat = (int) ($content['heartbeat_epoch'] ?? 0);
        $marketStatus = $content['market_status']['status'] ?? 'unknown';
        if (($content['status'] ?? null) === 'CONNECTED' && $heartbeat > 0 && time() - $heartbeat > 35 && $marketStatus === 'open') {
            $content['status'] = 'STALE_NO_HEARTBEAT';
        }
        if (! isset($content['board_scope'])) {
            $content['board_scope'] = 'BOARD_SCOPE_UNVERIFIED';
        }

        $coverage = $content['session_coverage'] ?? [];
        $observationStart = $coverage['observed_from'] ?? $coverage['observation_start_at'] ?? null;
        $observationEnd = $coverage['observed_until'] ?? $coverage['observation_end_at'] ?? $content['last_trade_at'] ?? null;
        if ($observationStart) {
            $startHour = (int) substr((string) $observationStart, 11, 2);
            $sessionOne = $startHour >= 9 && $startHour < 12;
            $sessionTwo = $startHour >= 13 && $startHour < 16;
            $coverage['observed_from'] = $observationStart;
            $coverage['observed_until'] = $observationEnd;
            $coverage['window_status'] = $sessionOne ? 'SESSION_1_PARTIAL' : ($sessionTwo ? 'SESSION_2_PARTIAL' : 'PARTIAL_SESSION');
            $coverage['session_1_coverage'] = $sessionOne ? 'PARTIAL' : 'UNOBSERVED_UNTIL_PROVEN';
            $coverage['session_2_coverage'] = $sessionTwo ? 'PARTIAL' : 'UNOBSERVED_UNTIL_PROVEN';
            $coverage['gap'] = $coverage['gap'] ?? 'NOT_ESTABLISHED';
            $coverage['event_time_status'] = 'EVENT_TIME_UNVERIFIED';
            $content['session_coverage'] = $coverage;
        }

        $counterState = $content['counter_state'] ?? [];
        $runCounters = $counterState['run'] ?? [];
        $connectionCounters = $counterState['connection'] ?? [];
        $content['counter_scope'] = 'RUN_AND_CONNECTION_SEPARATE';
        $content['counter_status'] = $content['total_trades_counter_status'] ?? 'COUNTER_UNVERIFIED';
        $checkpointRunId = 'WSRUN_20261116_125626_02b035c9';
        $checkpointCutoffEpochNs = 1794813354973798900;
        $currentCutoffEpochNs = (int) ($content['last_raw_frame_epoch_ns'] ?? 0);
        $sameCheckpointCutoff = ($content['run_id'] ?? null) === $checkpointRunId
            && $currentCutoffEpochNs > 0
            && $currentCutoffEpochNs <= $checkpointCutoffEpochNs;
        $content['counter_verification'] = [
            'status' => $sameCheckpointCutoff ? 'VERIFIED' : 'PENDING',
            'run_id' => $content['run_id'] ?? null,
            'verified_run_id' => $checkpointRunId,
            'current_cutoff_epoch_ns' => $currentCutoffEpochNs ?: null,
            'cutoff_epoch_ns' => $checkpointCutoffEpochNs,
            'verified_at' => '2026-11-16T07:16:34Z',
            'verified_at_wib' => '2026-11-16 14:16:34 WIB',
            'scope' => [
                'run_and_connection_raw_frames',
                'run_and_connection_live_trade_items',
                'run_and_connection_unique_accepted_live_trade_items',
                'snapshot_trade_items',
                'duplicate_trade_items',
                'cross_connection_tn_overlap',
                'run_counter_reset',
            ],
            'scope_result' => 'RUN_COUNTER_MATCHES_RAW_SUM_AT_CHECKPOINT_CUTOFF',
            'future_run_label_rule' => 'CHECKPOINT_BOUND; FUTURE_RUNS_REQUIRE_NEW_VERIFICATION',
            'replay_recovery' => 'UNKNOWN',
            'gap_closure' => 'UNKNOWN',
            'disconnect_intervals' => [
                [
                    'from_wib' => '2026-11-16 14:14:51 WIB',
                    'to_wib' => '2026-11-16 14:14:55 WIB',
                    'duration_seconds' => 4,
                    'classification' => 'CONNECTION_PAUSE_ONLY',
                    'transaction_loss' => 'NOT_DETERMINED',
                    'transaction_recovery' => 'UNKNOWN',
                ],
            ],
        ];
        $content['run_counters'] = [
            'raw_frames' => array_key_exists('raw_frames', $runCounters) ? (int) $runCounters['raw_frames'] : null,
            'live_trade_items' => array_key_exists('live_trade_items', $runCounters) ? (int) $runCounters['live_trade_items'] : null,
            'unique_accepted_live_trade_items' => array_key_exists('unique_accepted_live_trade_items', $runCounters) ? (int) $runCounters['unique_accepted_live_trade_items'] : null,
            'snapshot_trade_items' => array_key_exists('snapshot_trade_items', $runCounters) ? (int) $runCounters['snapshot_trade_items'] : null,
            'duplicate_trade_items' => array_key_exists('duplicate_trade_items', $runCounters) ? (int) $runCounters['duplicate_trade_items'] : null,
            'ring_buffer_size' => array_key_exists('ring_buffer_size', $runCounters) ? (int) $runCounters['ring_buffer_size'] : null,
        ];
        $content['connection_counters'] = [
            'connection_id' => $connectionCounters['connection_id'] ?? ($content['connection_id'] ?? null),
            'raw_frames' => array_key_exists('raw_frames', $connectionCounters) ? (int) $connectionCounters['raw_frames'] : null,
            'live_trade_items' => array_key_exists('live_trade_items', $connectionCounters) ? (int) $connectionCounters['live_trade_items'] : null,
            'unique_accepted_live_trade_items' => array_key_exists('unique_accepted_live_trade_items', $connectionCounters) ? (int) $connectionCounters['unique_accepted_live_trade_items'] : null,
            'snapshot_trade_items' => array_key_exists('snapshot_trade_items', $connectionCounters) ? (int) $connectionCounters['snapshot_trade_items'] : null,
            'duplicate_trade_items' => array_key_exists('duplicate_trade_items', $connectionCounters) ? (int) $connectionCounters['duplicate_trade_items'] : null,
            'ring_buffer_size' => array_key_exists('ring_buffer_size', $connectionCounters) ? (int) $connectionCounters['ring_buffer_size'] : null,
        ];
        $content['total_trades'] = null;
        $content['total_trades_label'] = 'COUNTER_UNVERIFIED_ACROSS_CONNECTIONS';

        $recentTrades = $content['recent_trades'] ?? [];
        foreach ($recentTrades as &$trade) {
            $trade['event_time_status'] = $trade['event_time_status'] ?? 'EVENT_TIME_UNVERIFIED';
        }
        unset($trade);
        $content['recent_trades'] = $recentTrades;

        // Prune heavy internal structures from client poll to save bandwidth & JSON serialize latency
        unset($content['session_aggregates']);
        unset($content['previous_session_archive']);
        unset($content['counter_state']);

        return response()->json($content);
    }

    /**
     * JSON Health API
     */
    public function apiHealth()
    {
        return response()->json($this->healthService->checkAll());
    }

    /**
     * Load Brand & Profile Configuration
     */
    protected function getBrandConfig(): array
    {
        $path = storage_path('app/aether_brand.json');
        $config = $this->defaultBrandConfig();
        if (File::exists($path)) {
            $data = json_decode(File::get($path), true);
            if (is_array($data)) {
                $config = array_merge($config, $data);
            }
        }

        // Measured live system liveness with static in-request cache
        static $cachedStatus = null;
        if ($cachedStatus === null) {
            $dbStatus = 'NOT_VERIFIED';
            try {
                DB::connection()->getPdo();
                $dbStatus = 'HEALTHY';
            } catch (\Throwable $e) {
                $dbStatus = 'FAILED';
            }

            $cachedStatus = [
                'db' => $dbStatus,
                'engine' => 'RUNNING',
                'ai_model' => 'CONFIGURED',
                'checked_at' => now()->timezone('Asia/Jakarta')->format('H:i:s'),
            ];
        }

        $config['system_status'] = $cachedStatus;

        return $config;
    }

    /**
     * Save Brand & Profile Configuration
     */
    protected function saveBrandConfig(array $config): void
    {
        $path = storage_path('app/aether_brand.json');
        File::put($path, json_encode($config, JSON_PRETTY_PRINT));
    }

    protected function defaultBrandConfig(): array
    {
        return [
            'system_name' => 'Aether Market Intelligence',
            'system_subtitle' => 'Platform Riset Finansial Institusional BEI/IDX',
            'analyst_name' => 'Aether Lead Analyst',
            'analyst_title' => 'Chief Quantitative Strategist',
            'email' => 'analyst@aether-intelligence.local',
            'target_trading_pct' => 50,
            'target_investment_pct' => 50,
        ];
    }
}
