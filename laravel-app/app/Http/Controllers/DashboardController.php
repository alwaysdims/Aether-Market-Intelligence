<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DataSource;
use App\Models\MarketSnapshot;
use App\Models\NewsItem;
use App\Models\MarketAnalysis;
use App\Models\AnalysisRun;
use App\Models\PromptTemplate;
use App\Models\SystemHealthCheck;
use App\Services\SystemHealthService;

class DashboardController extends Controller
{
    protected SystemHealthService $healthService;

    public function __construct(SystemHealthService $healthService)
    {
        $this->healthService = $healthService;
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

        return view('dashboard.overview', compact(
            'health',
            'lastRun',
            'lastAnalysis',
            'newsCountToday',
            'activeDataSources',
            'staleSources'
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
        $runs = AnalysisRun::with('marketAnalyses')
            ->latest('started_at')
            ->paginate(15);

        return view('dashboard.history', compact('runs'));
    }

    /**
     * System Health Page
     */
    public function health()
    {
        $health = $this->healthService->checkAll();
        $recentChecks = SystemHealthCheck::latest('checked_at')->take(30)->get();

        return view('dashboard.health', compact('health', 'recentChecks'));
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
