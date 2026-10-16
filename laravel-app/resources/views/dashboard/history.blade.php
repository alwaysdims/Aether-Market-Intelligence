@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div>
        <h2 class="text-xl font-bold text-white">Histori Analisis AI (Gemini 3.8 Flash High)</h2>
        <p class="text-xs text-slate-400">Audit trail lengkap seluruh eksekusi analisis pasar, prompt version, dan payload JSON.</p>
    </div>

    <div class="space-y-4">
        @forelse($runs as $run)
            <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 space-y-4 shadow">
                <!-- Run Header -->
                <div class="flex items-center justify-between border-b border-slate-700 pb-3">
                    <div class="flex items-center space-x-3">
                        <span class="font-mono text-sm font-bold text-sky-400">{{ $run->run_id }}</span>
                        <span class="text-xs px-2 py-0.5 rounded font-semibold {{ $run->status === 'completed' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' }}">
                            {{ strtoupper($run->status) }}
                        </span>
                        <span class="text-xs text-slate-400 font-medium">Pemicu: {{ ucfirst($run->trigger_type) }}</span>
                    </div>
                    <div class="text-xs text-slate-400">
                        {{ $run->started_at->timezone('Asia/Jakarta')->format('d M Y H:i:s WIB') }}
                        @if($run->duration_seconds)
                            &bull; {{ number_format($run->duration_seconds, 2) }} detik
                        @endif
                    </div>
                </div>

                <!-- Run Analyses -->
                @if($run->marketAnalyses->isNotEmpty())
                    <div class="space-y-3">
                        @foreach($run->marketAnalyses as $analysis)
                            <div class="bg-slate-900/60 border border-slate-700/60 rounded-lg p-4 space-y-2">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-2">
                                        <span class="text-xs font-semibold px-2 py-0.5 rounded
                                            {{ $analysis->impact_direction === 'positive' ? 'bg-emerald-500/20 text-emerald-300' :
                                               ($analysis->impact_direction === 'negative' ? 'bg-rose-500/20 text-rose-300' : 'bg-slate-700 text-slate-300') }}">
                                            {{ strtoupper($analysis->impact_direction) }}
                                        </span>
                                        <span class="text-xs text-slate-400 font-mono">{{ $analysis->model_name }} &bull; Prompt v{{ $analysis->prompt_version }}</span>
                                    </div>
                                    <span class="text-xs text-slate-400">Horizon: {{ ucfirst($analysis->impact_horizon) }}</span>
                                </div>
                                <p class="text-xs text-slate-200 leading-relaxed">{{ $analysis->market_regime_summary }}</p>

                                @if($analysis->structured_output)
                                    <details class="text-[11px] text-slate-400 pt-1">
                                        <summary class="cursor-pointer hover:text-sky-300 font-mono">Lihat Output Terstruktur (JSON)</summary>
                                        <pre class="mt-2 bg-slate-950 p-3 rounded border border-slate-800 text-slate-300 overflow-x-auto font-mono text-[10px]">{{ json_encode($analysis->structured_output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </details>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-xs text-slate-500 italic">Tidak ada output analisis tersimpan pada run ini.</p>
                @endif
            </div>
        @empty
            <div class="bg-slate-800 border border-slate-700 rounded-xl p-12 text-center text-slate-500 text-sm">
                Belum ada histori analisis pasar yang tercatat.
            </div>
        @endforelse

        @if($runs->hasPages())
            <div class="p-4">
                {{ $runs->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
