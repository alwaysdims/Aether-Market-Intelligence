<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'overview'])->name('dashboard.overview');
Route::get('/market', [DashboardController::class, 'market'])->name('dashboard.market');
Route::get('/market/{ticker}', [DashboardController::class, 'tickerDetail'])->name('dashboard.ticker');
Route::get('/news', [DashboardController::class, 'news'])->name('dashboard.news');
Route::get('/history', [DashboardController::class, 'history'])->name('dashboard.history');
Route::get('/health', [DashboardController::class, 'health'])->name('dashboard.health');
Route::get('/settings', [DashboardController::class, 'settings'])->name('dashboard.settings');
Route::get('/profile', [DashboardController::class, 'profile'])->name('dashboard.profile');
Route::post('/profile', [DashboardController::class, 'updateProfile'])->name('dashboard.profile.update');
Route::post('/settings/brand', [DashboardController::class, 'updateBrand'])->name('dashboard.brand.update');
Route::post('/api/research', [DashboardController::class, 'submitResearch'])->name('dashboard.research.submit');
Route::post('/api/watchlist/toggle', [DashboardController::class, 'toggleWatchlist'])->name('dashboard.watchlist.toggle');
Route::get('/api/market/{ticker}/snapshot', [DashboardController::class, 'marketSnapshot'])->name('dashboard.market.snapshot');

// API Health Check
Route::get('/api/health', [DashboardController::class, 'apiHealth'])->name('api.health');
