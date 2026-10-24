<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DataSource;
use App\Models\MarketSnapshot;
use App\Models\NewsItem;
use App\Models\MarketAnalysis;
use App\Models\AnalysisRun;
use App\Models\NotificationLog;
use App\Models\PromptTemplate;
use App\Models\SystemHealthCheck;
use App\Models\PostHorizonEvaluation;
use App\Services\SystemHealthService;
use App\Services\BeiCalendarService;
use App\Services\PostHorizonEvaluatorService;

class DashboardController extends Controller
{
    protected SystemHealthService $healthService;
    protected BeiCalendarService $calendar;
    protected PostHorizonEvaluatorService $evaluator;

    public function __construct(
        SystemHealthService $healthService,
        BeiCalendarService $calendar,
        PostHorizonEvaluatorService $evaluator
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
        $isEodFinal = $this->calendar->isEodFinal();
        $evalMetrics = $this->evaluator->getMetricsSummary();
        $recentEvals = PostHorizonEvaluation::latest('decision_date')->take(5)->get();
        $lastNotification = NotificationLog::latest()->first();
        $notificationStats = [
            'sent' => NotificationLog::where('status', 'sent')->count(),
            'failed' => NotificationLog::where('status', 'failed')->count(),
            'pending' => NotificationLog::where('status', 'pending')->count(),
        ];

        return view('dashboard.overview', compact(
            'health',
            'lastRun',
            'lastAnalysis',
            'newsCountToday',
            'activeDataSources',
            'staleSources',
            'nextRunTime',
            'latestSnapshot',
            'isEodFinal',
            'evalMetrics',
            'recentEvals',
            'lastNotification',
            'notificationStats'
        ));
    }

    /**
     * Market Monitor
     */
    public function market()
    {
        $snapshots = MarketSnapshot::latest('bar_date')
            ->orderBy('ticker')
            ->paginate(20);

        return view('dashboard.market', compact('snapshots'));
    }

    /**
     * News & Events
     */
    public function news()
    {
        $news = NewsItem::latest('fetched_at')->paginate(20);
        return view('dashboard.news', compact('news'));
    }

    /**
     * AI Analysis History
     */
    public function history()
    {
        $runs = AnalysisRun::with(['marketAnalyses.postHorizonEvaluations'])
            ->latest('started_at')
            ->paginate(15);

        $evalMetrics = $this->evaluator->getMetricsSummary();
        $evaluations = PostHorizonEvaluation::with('marketAnalysis')
            ->latest('decision_date')
            ->paginate(20);

        return view('dashboard.history', compact('runs', 'evalMetrics', 'evaluations'));
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

        return view('dashboard.health', compact('health', 'recentChecks', 'nextRunTime', 'isTradingDay'));
    }

    /**
     * Settings Page
     */
    public function settings()
    {
        $promptTemplates = PromptTemplate::orderBy('code')->get();
        $dataSources = DataSource::all();

        return view('dashboard.settings', compact('promptTemplates', 'dataSources'));
    }

    /**
     * JSON Health API
     */
    public function apiHealth()
    {
        return response()->json($this->healthService->checkAll());
    }
}
