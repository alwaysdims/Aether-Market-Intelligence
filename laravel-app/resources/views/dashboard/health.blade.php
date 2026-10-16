@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white">System Health & Diagnostik Komponen</h2>
            <p class="text-xs text-slate-400">Status kesehatan waktu nyata seluruh komponen Hermes Market Intelligence.</p>
        </div>
        <button onclick="window.location.reload()" class="px-3 py-1.5 bg-sky-600 hover:bg-sky-500 text-white rounded-lg text-xs font-semibold shadow transition">
            🔄 Refresh Health Check
        </button>
    </div>

    <!-- Active Component Status Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- PostgreSQL Health -->
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-sm font-bold text-white flex items-center space-x-2">
                    <span>🐘</span>
                    <span>PostgreSQL Database</span>
                </span>
                <span class="px-2 py-0.5 rounded text-xs font-bold {{ ($health['database']['status'] ?? '') === 'healthy' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' }}">
                    {{ strtoupper($health['database']['status'] ?? 'UNKNOWN') }}
                </span>
            </div>
            <div class="text-xs text-slate-400 space-y-1 font-mono">
                <p>Latensi: <span class="text-white font-bold">{{ $health['database']['latency_ms'] ?? 0 }} ms</span></p>
                <p>Database: {{ $health['database']['details']['database'] ?? 'project-bot' }}</p>
                <p>Host: 127.0.0.1:5432</p>
            </div>
        </div>

        <!-- AI Router Health -->
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-sm font-bold text-white flex items-center space-x-2">
                    <span>🤖</span>
                    <span>Gemini 3.8 Flash High</span>
                </span>
                <span class="px-2 py-0.5 rounded text-xs font-bold {{ ($health['ai_model']['status'] ?? '') === 'healthy' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' }}">
                    {{ strtoupper($health['ai_model']['status'] ?? 'UNKNOWN') }}
                </span>
            </div>
            <div class="text-xs text-slate-400 space-y-1 font-mono">
                <p>Latensi: <span class="text-white font-bold">{{ $health['ai_model']['latency_ms'] ?? 0 }} ms</span></p>
                <p>Endpoint: 127.0.0.1:20128</p>
                <p>Model ID: ag/gemini-3.8-flash-high</p>
            </div>
        </div>

        <!-- Hermes Engine Health -->
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-sm font-bold text-white flex items-center space-x-2">
                    <span>📈</span>
                    <span>Hermes Stock Agent</span>
                </span>
                <span class="px-2 py-0.5 rounded text-xs font-bold {{ ($health['hermes_agent']['status'] ?? '') === 'healthy' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' }}">
                    {{ strtoupper($health['hermes_agent']['status'] ?? 'UNKNOWN') }}
                </span>
            </div>
            <div class="text-xs text-slate-400 space-y-1 font-mono">
                <p>Status Path: <span class="text-white font-bold">Terhubung</span></p>
                <p>Path: E:\hermes-stock-agent</p>
                <p>Mode: EOD Paper Only</p>
            </div>
        </div>
    </div>

    <!-- Health Log Table -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 space-y-4">
        <h3 class="text-sm font-bold text-white">Log Pengecekan Kesehatan Terbaru</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-900/80 text-slate-400 font-semibold uppercase tracking-wider border-b border-slate-700">
                    <tr>
                        <th class="px-4 py-2.5">Waktu Cek</th>
                        <th class="px-4 py-2.5">Komponen</th>
                        <th class="px-4 py-2.5">Status</th>
                        <th class="px-4 py-2.5">Latensi</th>
                        <th class="px-4 py-2.5">Detail</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-700/60 font-mono">
                    @forelse($recentChecks as $check)
                        <tr>
                            <td class="px-4 py-2.5">{{ $check->checked_at->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') }} WIB</td>
                            <td class="px-4 py-2.5 text-white font-semibold">{{ $check->component }}</td>
                            <td class="px-4 py-2.5">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold {{ $check->status === 'healthy' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' }}">
                                    {{ strtoupper($check->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5">{{ $check->latency_ms }} ms</td>
                            <td class="px-4 py-2.5 text-slate-400 text-[11px]">{{ json_encode($check->details) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-slate-500">Belum ada riwayat pengecekan.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
