@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div>
        <h2 class="text-xl font-bold text-white">Histori Analisis AI (Gemini 3.8 Flash High)</h2>
        <p class="text-xs text-slate-400">Audit trail lengkap seluruh eksekusi analisis pasar, prompt version, dan payload JSON.</p>
    </div>

    <!-- Post-Horizon Evaluation Summary Metrics Card -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 space-y-4 shadow">
        <div class="flex items-center justify-between border-b border-slate-700 pb-3">
            <h3 class="text-sm font-bold text-white flex items-center space-x-2">
                <span>🎯</span>
                <span>Evaluasi Akurasi Post-Horizon (5 Hari Bursa)</span>
            </h3>
            <span class="text-xs font-mono text-sky-400 font-semibold">
                {{ $evalMetrics['total_registered'] }} Prediksi Terlacak &bull; {{ $evalMetrics['total_pending'] }} Menunggu Jatuh Tempo
            </span>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-xs font-mono">
            <div class="bg-slate-900/60 p-3 rounded-lg border border-slate-700/60">
                <span class="text-slate-400 block text-[11px]">Akurasi Directional:</span>
                <span class="text-lg font-bold text-sky-400">{{ $evalMetrics['accuracy_pct'] }}%</span>
                <span class="text-slate-500 block text-[10px]">Dari {{ $evalMetrics['directional_count'] }} prediksi directional</span>
            </div>
            <div class="bg-slate-900/60 p-3 rounded-lg border border-slate-700/60">
                <span class="text-slate-400 block text-[11px]">Baseline Always Positive:</span>
                <span class="text-lg font-bold text-slate-300">{{ $evalMetrics['baseline_always_positive_pct'] }}%</span>
                <span class="text-slate-500 block text-[10px]">Benchmark buy-and-hold</span>
            </div>
            <div class="bg-slate-900/60 p-3 rounded-lg border border-slate-700/60">
                <span class="text-slate-400 block text-[11px]">Hasil Evaluasi:</span>
                <span class="text-xs text-slate-200 block pt-1">
                    <span class="text-emerald-400 font-bold">{{ $evalMetrics['correct_count'] }} Benar</span> / 
                    <span class="text-rose-400 font-bold">{{ $evalMetrics['incorrect_count'] }} Salah</span> / 
                    <span class="text-slate-400">{{ $evalMetrics['neutral_count'] }} Netral</span>
                </span>
            </div>
            <div class="bg-slate-900/60 p-3 rounded-lg border border-slate-700/60">
                <span class="text-slate-400 block text-[11px]">Status Belum Selesai:</span>
                <span class="text-lg font-bold text-amber-400">{{ $evalMetrics['total_pending'] }} Pending</span>
                <span class="text-slate-500 block text-[10px]">Anti-lookahead aktif</span>
            </div>
        </div>

        <!-- Recent Post-Horizon Evaluations Table -->
        <div class="overflow-x-auto pt-2">
            <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-900/80 text-slate-400 font-semibold uppercase tracking-wider border-b border-slate-700 text-[10px]">
                    <tr>
                        <th class="px-3 py-2">Ticker</th>
                        <th class="px-3 py-2">Tgl Keputusan</th>
                        <th class="px-3 py-2">Harga Ref (EOD)</th>
                        <th class="px-3 py-2">Arah Prediksi</th>
                        <th class="px-3 py-2">Target Evaluasi (+5D)</th>
                        <th class="px-3 py-2">Harga Aktual</th>
                        <th class="px-3 py-2">Return Aktual</th>
                        <th class="px-3 py-2">Status Outcome</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-700/60 font-mono text-[11px]">
                    @forelse($evaluations as $ev)
                        <tr class="hover:bg-slate-700/30 transition">
                            <td class="px-3 py-2 font-bold text-white">{{ $ev->ticker }}</td>
                            <td class="px-3 py-2">{{ $ev->decision_date->format('Y-m-d') }}</td>
                            <td class="px-3 py-2 text-sky-300 font-semibold">Rp{{ number_format($ev->reference_price, 0, ',', '.') }}</td>
                            <td class="px-3 py-2">
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold
                                    {{ $ev->predicted_direction === 'positive' ? 'bg-emerald-500/20 text-emerald-300' :
                                       ($ev->predicted_direction === 'negative' ? 'bg-rose-500/20 text-rose-300' : 'bg-slate-700 text-slate-400') }}">
                                    {{ strtoupper($ev->predicted_direction) }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-slate-300">{{ $ev->target_eval_date->format('Y-m-d') }}</td>
                            <td class="px-3 py-2">{{ $ev->actual_price ? 'Rp' . number_format($ev->actual_price, 0, ',', '.') : '-' }}</td>
                            <td class="px-3 py-2 {{ ($ev->actual_return_pct ?? 0) >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                                {{ $ev->actual_return_pct !== null ? sprintf('%+.2f%%', $ev->actual_return_pct) : '-' }}
                            </td>
                            <td class="px-3 py-2">
                                @if($ev->status === 'pending')
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-500/20 text-amber-300">
                                        PENDING (+5D)
                                    </span>
                                @elseif($ev->outcome === 'correct')
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/20 text-emerald-300">
                                        CORRECT
                                    </span>
                                @elseif($ev->outcome === 'incorrect')
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-500/20 text-rose-300">
                                        INCORRECT
                                    </span>
                                @else
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-slate-700 text-slate-300">
                                        {{ strtoupper($ev->outcome ?? $ev->status) }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-3 py-6 text-center text-slate-500 font-sans">
                                Belum ada entri evaluasi post-horizon tersimpan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
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
