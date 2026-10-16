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
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <!-- PostgreSQL Health -->
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-white flex items-center space-x-1.5">
                    <span>🐘</span>
                    <span>PostgreSQL</span>
                </span>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold {{ ($health['database']['status'] ?? '') === 'healthy' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' }}">
                    {{ strtoupper($health['database']['status'] ?? 'UNKNOWN') }}
                </span>
            </div>
            <div class="text-[11px] text-slate-400 font-mono">
                <p>Latensi: <span class="text-white font-bold">{{ $health['database']['latency_ms'] ?? 0 }} ms</span></p>
                <p>Port: 5432</p>
            </div>
        </div>

        <!-- AI Router Health -->
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-white flex items-center space-x-1.5">
                    <span>🤖</span>
                    <span>Gemini 3.8</span>
                </span>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold {{ ($health['ai_model']['status'] ?? '') === 'healthy' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' }}">
                    {{ strtoupper($health['ai_model']['status'] ?? 'UNKNOWN') }}
                </span>
            </div>
            <div class="text-[11px] text-slate-400 font-mono">
                <p>Latensi: <span class="text-white font-bold">{{ $health['ai_model']['latency_ms'] ?? 0 }} ms</span></p>
                <p>Port: 20128</p>
            </div>
        </div>

        <!-- Hermes Engine Health -->
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-white flex items-center space-x-1.5">
                    <span>📈</span>
                    <span>Hermes Engine</span>
                </span>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold {{ ($health['hermes_agent']['status'] ?? '') === 'healthy' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' }}">
                    {{ strtoupper($health['hermes_agent']['status'] ?? 'UNKNOWN') }}
                </span>
            </div>
            <div class="text-[11px] text-slate-400 font-mono">
                <p>Python: 3.14.7</p>
                <p>Mode: EOD Paper</p>
            </div>
        </div>

        <!-- Telegram Channel Health -->
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-white flex items-center space-x-1.5">
                    <span>✈️</span>
                    <span>Telegram Bot</span>
                </span>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold {{ ($health['telegram_channel']['status'] ?? '') === 'healthy' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-amber-500/20 text-amber-400' }}">
                    {{ strtoupper($health['telegram_channel']['status'] ?? 'UNKNOWN') }}
                </span>
            </div>
            <div class="text-[11px] text-slate-400 font-mono">
                <p>Bot: @Info_BosKuu</p>
                <p>Status: {{ strtoupper($health['telegram_channel']['details']['last_status'] ?? 'READY') }}</p>
            </div>
        </div>

        <!-- BEI Calendar & EOD Finality -->
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-white flex items-center space-x-1.5">
                    <span>📅</span>
                    <span>Jadwal Bursa</span>
                </span>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold {{ $isTradingDay ? 'bg-emerald-500/20 text-emerald-400' : 'bg-amber-500/20 text-amber-400' }}">
                    {{ $isTradingDay ? 'HARI BURSA' : 'LIBUR BURSA' }}
                </span>
            </div>
            <div class="text-[11px] text-slate-400 font-mono">
                <p>Target: 16:05 WIB</p>
                <p>Next: {{ $nextRunTime->format('d/m/Y H:i') }}</p>
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
