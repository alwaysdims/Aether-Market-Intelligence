<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;

Route::get('/', [DashboardController::class, 'overview'])->name('dashboard.overview');
Route::get('/market', [DashboardController::class, 'market'])->name('dashboard.market');
Route::get('/news', [DashboardController::class, 'news'])->name('dashboard.news');
Route::get('/history', [DashboardController::class, 'history'])->name('dashboard.history');
Route::get('/health', [DashboardController::class, 'health'])->name('dashboard.health');
Route::get('/settings', [DashboardController::class, 'settings'])->name('dashboard.settings');

// API Health Check
Route::get('/api/health', [DashboardController::class, 'apiHealth'])->name('api.health');
