<?php

use App\Http\Controllers\DashboardController;
use App\Models\PredictionSignal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'overview'])->name('dashboard.overview');
Route::get('/market', [DashboardController::class, 'market'])->name('dashboard.market');
Route::get('/screener', [DashboardController::class, 'screener'])->name('dashboard.screener');
Route::get('/market/{ticker}', [DashboardController::class, 'tickerDetail'])->name('dashboard.ticker');
Route::get('/news', [DashboardController::class, 'news'])->name('dashboard.news');
Route::get('/history', [DashboardController::class, 'history'])->name('dashboard.history');
Route::get('/signal-monitoring', function () {
    $signals = PredictionSignal::query()
        ->where(function ($query) {
            $query->where('metadata->mode', 'EXPERIMENTAL')
                ->orWhere('metadata->monitoring_cohort', 'SHADOW_TEST')
                ->orWhere('metadata->origin', 'UNIVERSAL_RULE_BASED_SCREENING');
        })
        ->latest('decision_timestamp')
        ->limit(100)
        ->get();

    return view('dashboard.signal_monitoring', compact('signals'));
})->name('dashboard.signal_monitoring');
Route::get('/research', [DashboardController::class, 'researchPage'])->name('dashboard.research');
Route::get('/running-trade', [DashboardController::class, 'runningTradePage'])->name('dashboard.running_trade');
Route::get('/health', [DashboardController::class, 'health'])->name('dashboard.health');
Route::get('/settings', [DashboardController::class, 'settings'])->name('dashboard.settings');
Route::get('/profile', [DashboardController::class, 'profile'])->name('dashboard.profile');
Route::post('/api/research', [DashboardController::class, 'submitResearch'])
    ->middleware('dashboard.research.access')
    ->name('dashboard.research.submit');
Route::get('/api/research-bridge/audit', [DashboardController::class, 'researchBridgeAudit'])
    ->middleware('dashboard.research.access')
    ->name('dashboard.research.bridge.audit');
Route::get('/api/research/{requestId}', [DashboardController::class, 'researchStatus'])
    ->middleware('dashboard.research.access')
    ->name('dashboard.research.status');

// GET status uses same local/session boundary; middleware permits only POST today, so this route uses dedicated read guard.
if (getenv('DASHBOARD_CANDIDATE') !== '1') {
    Route::post('/profile', [DashboardController::class, 'updateProfile'])->name('dashboard.profile.update');
    Route::post('/settings/brand', [DashboardController::class, 'updateBrand'])->name('dashboard.brand.update');
    Route::post('/api/watchlist/toggle', [DashboardController::class, 'toggleWatchlist'])->name('dashboard.watchlist.toggle');
}
Route::get('/api/price/IHSG', [DashboardController::class, 'ihsgPrice'])->name('dashboard.price.ihsg');
Route::get('/api/market/{ticker}/snapshot', [DashboardController::class, 'marketSnapshot'])->name('dashboard.market.snapshot');
Route::get('/api/market/{ticker}/chart-data', [DashboardController::class, 'chartData'])->name('dashboard.market.chart_data');
Route::get('/api/market/{ticker}/broker-summary', [DashboardController::class, 'brokerSummaryData'])->name('dashboard.market.broker_summary');
Route::get('/api/market/{ticker}/financial-statements', [DashboardController::class, 'financialStatementsData'])->name('dashboard.market.financial_statements');
Route::get('/api/market/{ticker}/ownership', [DashboardController::class, 'ownershipData'])->name('dashboard.market.ownership');
Route::get('/api/market/{ticker}/broker-accumulation', [DashboardController::class, 'brokerAccumulationData'])->name('dashboard.market.broker_accum_data');
Route::get('/api/screener/latest', [DashboardController::class, 'screenerLatest'])->name('dashboard.screener.latest');
Route::get('/api/market/running-trade-state', [DashboardController::class, 'runningTradeState'])->name('dashboard.market.running_trade_state');

// API Health Check
Route::get('/api/health', [DashboardController::class, 'apiHealth'])->name('api.health');
Route::get('/api/runtime-identity', function () {
    abort_unless(getenv('DASHBOARD_CANDIDATE') === '1', 404);

    $identity = DB::selectOne("SELECT current_database() AS database, current_user AS user_name, current_schema() AS schema_name, current_setting('search_path') AS search_path");
    $privileges = DB::selectOne("SELECT has_schema_privilege(current_user, 'public', 'CREATE') AS schema_create, has_table_privilege(current_user, 'public.news_items', 'INSERT,UPDATE,DELETE') AS news_mutation, has_table_privilege(current_user, 'public.market_observations', 'INSERT,UPDATE,DELETE') AS observation_mutation");
    $bbca = DB::selectOne("SELECT ticker, bar_date, adjustment_mode, source, board_scope, validation_status, is_eod_final, finality_basis FROM public.market_observations WHERE ticker='BBCA' ORDER BY bar_date DESC, adjustment_mode LIMIT 2");
    $news = DB::selectOne('SELECT count(*) AS count, count(DISTINCT url) AS distinct_urls FROM public.news_items');

    return response()->json(['status' => 'READ_ONLY', 'identity' => $identity, 'privileges' => $privileges, 'bbca_latest' => $bbca, 'news' => $news, 'writes_executed' => false, 'secret_printed' => false]);
})->name('dashboard.runtime_identity');
