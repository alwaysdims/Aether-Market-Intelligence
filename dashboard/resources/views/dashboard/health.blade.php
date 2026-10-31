@extends('layouts.app')

@section('page_title', 'System Health & Diagnostik')

@section('content')
<div class="space-y-6">

    <!-- Header Strip -->
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center space-x-2 mb-1">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-400"></span>
                <h1 class="text-lg font-bold text-[#F5F1E8] tracking-tight uppercase">System Health & Diagnostik Komponen</h1>
            </div>
            <p class="text-xs text-[#F5F1E8]/60">
                Pemantauan kesehatan waktu nyata infrastruktur Aether Market Intelligence (Database, AI Router, Worker, Scheduler, dan Saluran Notifikasi).
            </p>
        </div>

        <button onclick="window.location.reload()" class="px-3.5 py-2 bg-[#0D2B4E] hover:bg-[#153660] text-[#E5C49B] border border-[#E5C49B]/30 rounded text-xs font-semibold tracking-wide transition flex items-center">
            <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
            Refresh Diagnostik
        </button>
    </div>

    <!-- Active Component Status Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <!-- PostgreSQL Health -->
        <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-4 space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-[#F5F1E8] flex items-center space-x-1.5">
                    <span>🐘</span>
                    <span>PostgreSQL DB</span>
                </span>
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold {{ ($health['database']['status'] ?? '') === 'healthy' ? 'bg-emerald-950/60 text-emerald-300 border border-emerald-500/30' : 'bg-rose-950/60 text-rose-300 border border-rose-500/30' }}">
                    {{ strtoupper($health['database']['status'] ?? 'CONFIGURED') }}
                </span>
            </div>
            <div class="text-[11px] text-[#F5F1E8]/60 font-mono space-y-0.5">
                <p>Latensi: <span class="text-[#E5C49B] font-bold tabular-nums">{{ $health['database']['latency_ms'] ?? 0 }} ms</span></p>
                <p>Port: 5432 &bull; project-bot</p>
            </div>
        </div>

        <!-- AI Router Health -->
        <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-4 space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-[#F5F1E8] flex items-center space-x-1.5">
                    <span>🤖</span>
                    <span>Gemini 9router</span>
                </span>
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold {{ ($health['ai_model']['status'] ?? '') === 'healthy' ? 'bg-emerald-950/60 text-emerald-300 border border-emerald-500/30' : 'bg-rose-950/60 text-rose-300 border border-rose-500/30' }}">
                    {{ strtoupper($health['ai_model']['status'] ?? 'CONFIGURED') }}
                </span>
            </div>
            <div class="text-[11px] text-[#F5F1E8]/60 font-mono space-y-0.5">
                <p>Latensi: <span class="text-[#E5C49B] font-bold tabular-nums">{{ $health['ai_model']['latency_ms'] ?? 0 }} ms</span></p>
                <p>Port: 20128 &bull; Gemini 3.8</p>
            </div>
        </div>

        <!-- Hermes Engine Health -->
        <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-4 space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-[#F5F1E8] flex items-center space-x-1.5">
                    <span>📈</span>
                    <span>Hermes Pipeline</span>
                </span>
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold {{ ($health['hermes_agent']['status'] ?? '') === 'healthy' ? 'bg-emerald-950/60 text-emerald-300 border border-emerald-500/30' : 'bg-rose-950/60 text-rose-300 border border-rose-500/30' }}">
                    {{ strtoupper($health['hermes_agent']['status'] ?? 'RUNNING') }}
                </span>
            </div>
            <div class="text-[11px] text-[#F5F1E8]/60 font-mono space-y-0.5">
                <p>Engine: Python 3.10</p>
                <p>Lock: Atomic Liveness</p>
            </div>
        </div>

        <!-- Telegram Channel Health -->
        <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-4 space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-[#F5F1E8] flex items-center space-x-1.5">
                    <span>✈️</span>
                    <span>Telegram Gateway</span>
                </span>
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold {{ ($health['telegram_channel']['status'] ?? '') === 'healthy' ? 'bg-emerald-950/60 text-emerald-300 border border-emerald-500/30' : 'bg-amber-950/60 text-amber-300 border border-amber-500/30' }}">
                    {{ strtoupper($health['telegram_channel']['status'] ?? 'READY') }}
                </span>
            </div>
            <div class="text-[11px] text-[#F5F1E8]/60 font-mono space-y-0.5">
                <p>Bot: @Info_BosKuu_bot</p>
                <p>Status: {{ strtoupper($health['telegram_channel']['details']['last_status'] ?? 'READY') }}</p>
            </div>
        </div>

        <!-- BEI Calendar & EOD Schedule -->
        <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-4 space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-[#F5F1E8] flex items-center space-x-1.5">
                    <span>📅</span>
                    <span>Jadwal Bursa BEI</span>
                </span>
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold {{ $isTradingDay ? 'bg-emerald-950/60 text-emerald-300 border border-emerald-500/30' : 'bg-amber-950/60 text-amber-300 border border-amber-500/30' }}">
                    {{ $isTradingDay ? 'HARI_BURSA' : 'LIBUR_BURSA' }}
                </span>
            </div>
            <div class="text-[11px] text-[#F5F1E8]/60 font-mono space-y-0.5">
                <p>Target: 16:05 WIB EOD</p>
                <p>Next: {{ $nextRunTime ? $nextRunTime->format('d/m H:i') : '-' }}</p>
            </div>
        </div>
    </div>

    <!-- Health Log Table -->
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
        <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Audit Log Pengecekan Kesehatan Komponen Terbaru</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-[#F5F1E8]">
                <thead class="bg-[#071C35] text-[#F5F1E8]/50 uppercase tracking-wider font-mono text-[10px] border-b border-[#F5F1E8]/10">
                    <tr>
                        <th class="px-4 py-2.5">Waktu Pemeriksaan</th>
                        <th class="px-4 py-2.5">Nama Komponen</th>
                        <th class="px-4 py-2.5">Status Klasifikasi</th>
                        <th class="px-4 py-2.5">Latensi</th>
                        <th class="px-4 py-2.5">Metadata Hasil</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#F5F1E8]/5 font-mono text-[11px]">
                    @forelse($recentChecks as $check)
                        <tr class="hover:bg-[#0D2B4E]/60 transition">
                            <td class="px-4 py-2.5 text-[#F5F1E8]/60">{{ $check->checked_at->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') }} WIB</td>
                            <td class="px-4 py-2.5 text-[#E5C49B] font-bold">{{ $check->component }}</td>
                            <td class="px-4 py-2.5">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold {{ $check->status === 'healthy' ? 'bg-emerald-950/60 text-emerald-300 border border-emerald-500/30' : 'bg-rose-950/60 text-rose-300 border border-rose-500/30' }}">
                                    {{ strtoupper($check->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 tabular-nums">{{ $check->latency_ms }} ms</td>
                            <td class="px-4 py-2.5 text-[#F5F1E8]/50 text-[10px]">{{ json_encode($check->details) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-[#F5F1E8]/40 font-mono text-xs">Belum ada riwayat pengecekan tersimpan.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection

