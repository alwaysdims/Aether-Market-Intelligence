@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <!-- Top System Health Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <!-- PostgreSQL Card -->
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 flex items-center justify-between">
            <div>
                <p class="text-xs text-slate-400 uppercase font-semibold">PostgreSQL DB</p>
                <p class="text-lg font-bold {{ ($health['database']['status'] ?? '') === 'healthy' ? 'text-emerald-400' : 'text-rose-400' }}">
                    {{ ucfirst($health['database']['status'] ?? 'Unknown') }}
                </p>
                <p class="text-xs text-slate-500">{{ $health['database']['latency_ms'] ?? 0 }} ms &bull; Port 5432</p>
            </div>
            <div class="w-10 h-10 rounded-full {{ ($health['database']['status'] ?? '') === 'healthy' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-rose-500/10 text-rose-400' }} flex items-center justify-center text-lg">
                🐘
            </div>
        </div>

        <!-- AI Model Card -->
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 flex items-center justify-between">
            <div>
                <p class="text-xs text-slate-400 uppercase font-semibold">Gemini 3.8 Flash High</p>
                <p class="text-lg font-bold {{ ($health['ai_model']['status'] ?? '') === 'healthy' ? 'text-emerald-400' : 'text-rose-400' }}">
                    {{ ucfirst($health['ai_model']['status'] ?? 'Unknown') }}
                </p>
                <p class="text-xs text-slate-500">9router :20128 &bull; {{ $health['ai_model']['latency_ms'] ?? 0 }} ms</p>
            </div>
            <div class="w-10 h-10 rounded-full {{ ($health['ai_model']['status'] ?? '') === 'healthy' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-rose-500/10 text-rose-400' }} flex items-center justify-center text-lg">
                🤖
            </div>
        </div>

        <!-- Hermes Agent Card -->
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 flex items-center justify-between">
            <div>
                <p class="text-xs text-slate-400 uppercase font-semibold">Hermes Engine</p>
                <p class="text-lg font-bold {{ ($health['hermes_agent']['status'] ?? '') === 'healthy' ? 'text-emerald-400' : 'text-rose-400' }}">
                    {{ ucfirst($health['hermes_agent']['status'] ?? 'Unknown') }}
                </p>
                <p class="text-xs text-slate-500">Python 3.14 &bull; EOD Paper</p>
            </div>
            <div class="w-10 h-10 rounded-full {{ ($health['hermes_agent']['status'] ?? '') === 'healthy' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-rose-500/10 text-rose-400' }} flex items-center justify-center text-lg">
                📈
            </div>
        </div>

        <!-- News Processed Card -->
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 flex items-center justify-between">
            <div>
                <p class="text-xs text-slate-400 uppercase font-semibold">Berita Diproses</p>
                <p class="text-lg font-bold text-sky-400">{{ $newsCountToday }}</p>
                <p class="text-xs text-slate-500">{{ $activeDataSources }} sumber data aktif</p>
            </div>
            <div class="w-10 h-10 rounded-full bg-sky-500/10 text-sky-400 flex items-center justify-center text-lg">
                📰
            </div>
        </div>
    </div>

    <!-- Warnings / Stale Sources Alert -->
    @if($staleSources->isNotEmpty())
        <div class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-4 flex items-start space-x-3">
            <span class="text-xl">⚠️</span>
            <div>
                <h3 class="text-sm font-semibold text-amber-400">Peringatan Sumber Data Kedaluwarsa / Degraded</h3>
                <ul class="text-xs text-amber-200 mt-1 list-disc list-inside">
                    @foreach($staleSources as $stale)
                        <li>{{ $stale->name }} (Status: {{ $stale->status }}) &bull; Error: {{ $stale->last_error_message ?? 'N/A' }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <!-- Main Overview Content -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Latest Market Regime & Analysis -->
        <div class="lg:col-span-2 bg-slate-800 border border-slate-700 rounded-xl p-5 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-700 pb-3">
                <h2 class="text-base font-bold text-white flex items-center space-x-2">
                    <span>🧠</span>
                    <span>Ringkasan Intelijen Pasar Terakhir</span>
                </h2>
                @if($lastAnalysis)
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold
                        {{ $lastAnalysis->impact_direction === 'positive' ? 'bg-emerald-500/20 text-emerald-300' :
                           ($lastAnalysis->impact_direction === 'negative' ? 'bg-rose-500/20 text-rose-300' : 'bg-slate-700 text-slate-300') }}">
                        Arah: {{ strtoupper($lastAnalysis->impact_direction) }}
                    </span>
                @endif
            </div>

            @if($lastAnalysis)
                <div class="space-y-3">
                    <p class="text-sm text-slate-300 leading-relaxed">{{ $lastAnalysis->market_regime_summary }}</p>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 pt-2 text-xs">
                        <div class="bg-slate-900/60 p-2.5 rounded-lg border border-slate-700/60">
                            <span class="text-slate-500 block">Horizon Dampak:</span>
                            <span class="text-slate-200 font-semibold">{{ ucfirst($lastAnalysis->impact_horizon) }}</span>
                        </div>
                        <div class="bg-slate-900/60 p-2.5 rounded-lg border border-slate-700/60">
                            <span class="text-slate-500 block">Model AI:</span>
                            <span class="text-slate-200 font-mono font-semibold">{{ $lastAnalysis->model_name }}</span>
                        </div>
                        <div class="bg-slate-900/60 p-2.5 rounded-lg border border-slate-700/60">
                            <span class="text-slate-500 block">Waktu Analisis:</span>
                            <span class="text-slate-200">{{ $lastAnalysis->created_at->timezone('Asia/Jakarta')->format('d M Y H:i WIB') }}</span>
                        </div>
                    </div>
                </div>
            @else
                <div class="py-8 text-center text-slate-500 text-sm">
                    Belum ada analisis yang dijalankan. Jalankan run perdana melalui Scheduler atau tombol trigger.
                </div>
            @endif
        </div>

        <!-- Latest Pipeline Run Status -->
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 space-y-4">
            <h2 class="text-base font-bold text-white flex items-center space-x-2 border-b border-slate-700 pb-3">
                <span>⚙️</span>
                <span>Status Job Terakhir</span>
            </h2>

            @if($lastRun)
                <div class="space-y-3 text-xs">
                    <div class="flex justify-between items-center py-1 border-b border-slate-700/50">
                        <span class="text-slate-400">Run ID:</span>
                        <span class="font-mono text-slate-200 font-semibold">{{ $lastRun->run_id }}</span>
                    </div>
                    <div class="flex justify-between items-center py-1 border-b border-slate-700/50">
                        <span class="text-slate-400">Pemicu:</span>
                        <span class="text-slate-200">{{ ucfirst($lastRun->trigger_type) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-1 border-b border-slate-700/50">
                        <span class="text-slate-400">Status:</span>
                        <span class="px-2 py-0.5 rounded font-semibold {{ $lastRun->status === 'completed' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' }}">
                            {{ strtoupper($lastRun->status) }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center py-1 border-b border-slate-700/50">
                        <span class="text-slate-400">Durasi:</span>
                        <span class="text-slate-200">{{ number_format($lastRun->duration_seconds, 2) }} detik</span>
                    </div>
                    <div class="flex justify-between items-center py-1">
                        <span class="text-slate-400">Dimulai:</span>
                        <span class="text-slate-200">{{ $lastRun->started_at->timezone('Asia/Jakarta')->format('d/m/Y H:i:s WIB') }}</span>
                    </div>
                </div>
            @else
                <div class="py-8 text-center text-slate-500 text-xs">
                    Belum ada riwayat pipeline run.
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
