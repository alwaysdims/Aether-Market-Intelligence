@extends('layouts.app')

@section('page_title', 'Histori Analisis AI')

@section('content')
<div class="space-y-6">

    <!-- Header Strip -->
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center space-x-2 mb-1">
                <span class="w-2.5 h-2.5 rounded-full bg-[#E5C49B]"></span>
                <h1 class="text-lg font-bold text-[#F5F1E8] tracking-tight uppercase">Histori Analisis AI</h1>
            </div>
            <p class="text-xs text-[#F5F1E8]/60">
                Audit trail lengkap seluruh eksekusi analisis pasar, prompt version, snapshot evidence hash, dan payload JSON terstruktur.
            </p>
        </div>

        <span class="text-xs text-[#E5C49B] bg-[#0D2B4E] border border-[#E5C49B]/30 px-3 py-1.5 rounded font-mono">
            Model per record &bull; Target konsultasi: {{ config('services.consultation_ai.model_id') }} &bull; consultation_ai
        </span>
    </div>

    <!-- Post-Horizon Evaluation Summary Metrics Card -->
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4 shadow">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between border-b border-[#F5F1E8]/10 pb-3 gap-2">
            <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">
                Evaluasi Akurasi Post-Horizon (5 Hari Bursa BEI)
            </h3>
            <span class="text-xs font-mono text-[#E5C49B]">
                @if(($evaluationReadStatus ?? 'READABLE') !== 'READABLE')
                    Status evaluasi: {{ $evaluationReadStatus }}
                @else
                    {{ $evalMetrics['total_registered'] }} Prediksi Terlacak &bull; {{ $evalMetrics['total_pending'] }} Menunggu Jatuh Tempo
                @endif
            </span>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs font-mono">
            <div class="bg-[#0D2B4E] p-3 rounded border border-[#F5F1E8]/5">
                <span class="text-[#F5F1E8]/40 block text-[10px] uppercase">Akurasi Directional (ML/EXP-04)</span>
                <span class="text-lg font-bold text-[#E5C49B] tabular-nums">{{ $evalMetrics['accuracy_pct'] !== null ? $evalMetrics['accuracy_pct'] . '%' : 'N/A' }}</span>
                <span class="text-[#F5F1E8]/40 block text-[10px] mt-0.5">Dari {{ $evalMetrics['directional_count'] }} directional matang</span>
            </div>
            <div class="bg-[#0D2B4E] p-3 rounded border border-[#F5F1E8]/5">
                <span class="text-[#F5F1E8]/40 block text-[10px] uppercase">Benchmark Buy & Hold</span>
                <span class="text-lg font-bold text-[#F5F1E8] tabular-nums">{{ $evalMetrics['baseline_always_positive_pct'] !== null ? $evalMetrics['baseline_always_positive_pct'] . '%' : 'N/A' }}</span>
                <span class="text-[#F5F1E8]/40 block text-[10px] mt-0.5">Baseline always positive</span>
            </div>
            <div class="bg-[#0D2B4E] p-3 rounded border border-[#F5F1E8]/5">
                <span class="text-[#F5F1E8]/40 block text-[10px] uppercase">Rasio Keputusan Selesai</span>
                <span class="text-xs text-[#F5F1E8] block pt-1 tabular-nums">
                    <span class="text-emerald-400 font-bold">{{ $evalMetrics['correct_count'] }} B</span> / 
                    <span class="text-rose-400 font-bold">{{ $evalMetrics['incorrect_count'] }} S</span> / 
                    <span class="text-[#F5F1E8]/60">{{ $evalMetrics['neutral_count'] }} N</span>
                </span>
                <span class="text-[#F5F1E8]/40 block text-[9px] mt-0.5">Ket: N = 30 Fallback Historis + 10 Abstain Baru</span>
            </div>
            <div class="bg-[#0D2B4E] p-3 rounded border border-[#F5F1E8]/5">
                <span class="text-[#F5F1E8]/40 block text-[10px] uppercase">Status Pending (+5D)</span>
                <span class="text-lg font-bold text-amber-400 tabular-nums">{{ $evalMetrics['total_pending'] }} Menunggu</span>
                <span class="text-[#F5F1E8]/40 block text-[10px] mt-0.5">Anti-lookahead aktif</span>
            </div>
        </div>

        <!-- Recent Post-Horizon Evaluations Table -->
        <div class="overflow-x-auto pt-2">
        @if(($evaluationReadStatus ?? 'READABLE') !== 'READABLE')
            <p class="mb-2 text-[10px] font-mono text-amber-300">Status evaluasi: {{ $evaluationReadStatus }}. Data evaluasi tidak dipakai untuk mengosongkan History utama.</p>
        @endif
        <table class="w-full text-left text-xs text-[#F5F1E8]">
                <thead class="bg-[#071C35] text-[#F5F1E8]/50 uppercase tracking-wider font-mono text-[10px] border-b border-[#F5F1E8]/10">
                    <tr>
                        <th class="px-3 py-2.5">Ticker</th>
                        <th class="px-3 py-2.5">Tgl Keputusan</th>
                        <th class="px-3 py-2.5">Harga Acuan (EOD)</th>
                        <th class="px-3 py-2.5">Arah Prediksi</th>
                        <th class="px-3 py-2.5">Target Horizon (+5D)</th>
                        <th class="px-3 py-2.5">Harga Aktual</th>
                        <th class="px-3 py-2.5">Return Aktual</th>
                        <th class="px-3 py-2.5">Status Outcome</th>
                        <th class="px-3 py-2.5">Tautan Analisis</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#F5F1E8]/5 font-mono text-[11px]">
                    @forelse($evaluations as $ev)
                        <tr class="hover:bg-[#0D2B4E]/60 transition">
                            <td class="px-3 py-2 font-bold text-[#E5C49B]">
                                <a href="{{ route('dashboard.ticker', $ev->ticker) }}?tab=monitoring" class="hover:underline">{{ $ev->ticker }}</a>
                            </td>
                            <td class="px-3 py-2 text-[#F5F1E8]/70">{{ $ev->decision_date->format('Y-m-d') }}</td>
                            <td class="px-3 py-2 tabular-nums">Rp {{ number_format($ev->reference_price, 0, ',', '.') }}</td>
                            <td class="px-3 py-2">
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold
                                    {{ $ev->predicted_direction === 'positive' ? 'bg-emerald-950/60 text-emerald-300 border border-emerald-500/30' :
                                       ($ev->predicted_direction === 'negative' ? 'bg-rose-950/60 text-rose-300 border border-rose-500/30' : 'bg-[#0D2B4E] text-[#F5F1E8]/60 border border-[#F5F1E8]/10') }}">
                                    {{ strtoupper($ev->predicted_direction) }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-[#F5F1E8]/60">{{ $ev->target_eval_date->format('Y-m-d') }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ $ev->actual_price ? 'Rp ' . number_format($ev->actual_price, 0, ',', '.') : '-' }}</td>
                            <td class="px-3 py-2 tabular-nums {{ ($ev->actual_return_pct ?? 0) >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                                {{ $ev->actual_return_pct !== null ? sprintf('%+.2f%%', $ev->actual_return_pct) : '-' }}
                            </td>
                            <td class="px-3 py-2">
                                @if($ev->status === 'pending')
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-950/60 text-amber-300 border border-amber-500/30">
                                        PENDING (+5D)
                                    </span>
                                @elseif($ev->outcome === 'correct')
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-950/60 text-emerald-300 border border-emerald-500/30">
                                        BENAR (CORRECT)
                                    </span>
                                @elseif($ev->outcome === 'incorrect')
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-950/60 text-rose-300 border border-rose-500/30">
                                        SALAH (INCORRECT)
                                    </span>
                                @else
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-[#0D2B4E] text-[#F5F1E8]/60 border border-[#F5F1E8]/10">
                                        NETRAL
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-[10px] text-[#F5F1E8]/50">
                                @if($ev->market_analysis_id)
                                    <a href="{{ route('dashboard.ticker', $ev->ticker) }}?tab=ai_insight" class="text-[#E5C49B] hover:underline">
                                        Analysis #{{ $ev->market_analysis_id }}
                                    </a>
                                @else
                                    <span>-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-3 py-6 text-center text-[#F5F1E8]/40 font-mono text-xs">
                                Belum ada entri evaluasi post-horizon tersimpan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Auditable Ledger: Rekapitulasi Outcome Trading Plan Telegram -->
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4 shadow">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between border-b border-[#F5F1E8]/10 pb-3 gap-2">
            <div>
                <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">
                    Audit Ledger: Rekapitulasi Trading Plan Telegram (Scalping, Swing, BSJP)
                </h3>
                <p class="text-[11px] text-[#F5F1E8]/60 mt-0.5">
                    Evaluasi deterministik seluruh sinyal bersyarat terhadap data resmi BEI. Tanpa interpolasi atau asumsi agresor.
                </p>
            </div>
            <span class="text-xs font-mono text-[#E5C49B]">
                {{ $tradingPlanRecap['total_decided'] ?? 0 }} Diputuskan &bull; {{ $tradingPlanRecap['total_forward_delivered'] ?? 0 }} Forward Terkirim &bull; {{ $tradingPlanRecap['total_retrospective'] ?? 0 }} Retrospektif &bull; {{ $tradingPlanRecap['total_not_delivered'] ?? 0 }} Tidak Terkirim
            </span>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs font-mono">
            <div class="bg-[#0D2B4E] p-3 rounded border border-[#F5F1E8]/5">
                <span class="text-[#F5F1E8]/40 block text-[10px] uppercase">Hit Rate Forward (Denominator Matang)</span>
                <span class="text-lg font-bold text-emerald-400 tabular-nums">
                    {{ $tradingPlanRecap['forward_target_hit_rate_pct'] !== null ? $tradingPlanRecap['forward_target_hit_rate_pct'] . '%' : 'BELUM ADA' }}
                </span>
                <span class="text-[#F5F1E8]/40 block text-[10px] mt-0.5">
                    Dari {{ $tradingPlanRecap['forward_matured_count'] ?? 0 }} plan forward matang
                </span>
            </div>
            <div class="bg-[#0D2B4E] p-3 rounded border border-[#F5F1E8]/5">
                <span class="text-[#F5F1E8]/40 block text-[10px] uppercase">Status Pantau Berjalan</span>
                <span class="text-xs text-[#F5F1E8] block pt-1 tabular-nums">
                    <span class="text-amber-400 font-bold">{{ $tradingPlanRecap['waiting_for_trigger'] ?? 0 }} Menunggu Pemicu</span><br>
                    <span class="text-blue-400 font-bold">{{ $tradingPlanRecap['trigger_observed'] ?? 0 }} Pemicu Teramati</span>
                </span>
            </div>
            <div class="bg-[#0D2B4E] p-3 rounded border border-[#F5F1E8]/5">
                <span class="text-[#F5F1E8]/40 block text-[10px] uppercase">Hasil Forward Terkonfirmasi</span>
                <span class="text-xs text-[#F5F1E8] block pt-1 tabular-nums">
                    <span class="text-emerald-400 font-bold">{{ $tradingPlanRecap['forward_target_hit'] ?? 0 }} TP</span> /
                    <span class="text-rose-400 font-bold">{{ $tradingPlanRecap['forward_invalidated'] ?? 0 }} CL</span> /
                    <span class="text-amber-400 font-bold">{{ $tradingPlanRecap['forward_ambiguous'] ?? 0 }} Ambiguous</span>
                </span>
                <span class="text-[#F5F1E8]/40 block text-[9px] mt-0.5">
                    Exp: {{ $tradingPlanRecap['forward_expired_untriggered'] ?? 0 }} (no-trig) / {{ $tradingPlanRecap['forward_expired_post_trigger'] ?? 0 }} (post-trig)
                </span>
            </div>
            <div class="bg-[#0D2B4E] p-3 rounded border border-[#F5F1E8]/5">
                <span class="text-[#F5F1E8]/40 block text-[10px] uppercase">Rincian per Strategi (Forward)</span>
                <span class="text-[10px] text-[#F5F1E8] block pt-0.5 tabular-nums">
                    Scalp: {{ $tradingPlanRecap['by_strategy']['SCALPING']['forward_delivered'] ?? 0 }} &bull;
                    Swing: {{ $tradingPlanRecap['by_strategy']['SWING']['forward_delivered'] ?? 0 }} &bull;
                    BSJP: {{ $tradingPlanRecap['by_strategy']['BSJP']['forward_delivered'] ?? 0 }}
                </span>
                <span class="text-[#F5F1E8]/40 block text-[9px] mt-0.5">Gap Data: {{ $tradingPlanRecap['forward_coverage_gap'] ?? 0 }}</span>
            </div>
        </div>

        <div class="p-3 bg-[#071C35]/60 rounded border border-[#F5F1E8]/5 font-mono text-[11px] text-[#F5F1E8]/70">
            ℹ️ <span class="text-[#E5C49B]">Pemberitahuan Risiko & Integritas Data:</span> {{ $tradingPlanRecap['simulation_disclaimer'] ?? 'Sentuhan level pemicu bukan bukti order terisi (fill). Hasil skenario tidak mewakili posisi riil portofolio pengguna.' }}
        </div>
    </div>

    <!-- Execution Runs Audit List -->
    <div class="space-y-4">
        @forelse($runs as $run)
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4 shadow">
                <!-- Run Header -->
                <div class="flex flex-wrap items-center justify-between border-b border-[#F5F1E8]/10 pb-3 gap-2">
                    <div class="flex items-center space-x-3">
                        <span class="font-mono text-xs font-bold text-[#E5C49B]">{{ $run->run_id }}</span>
                        <span class="text-[10px] px-2 py-0.5 rounded font-mono font-semibold {{ $run->status === 'completed' ? 'bg-emerald-950/60 text-emerald-300 border border-emerald-500/30' : 'bg-rose-950/60 text-rose-300 border border-rose-500/30' }}">
                            {{ strtoupper($run->status) }}
                        </span>
                        <span class="text-xs text-[#F5F1E8]/50">Pemicu: {{ ucfirst($run->trigger_type) }}</span>
                    </div>
                    <div class="text-xs text-[#F5F1E8]/50 font-mono">
                        {{ $run->started_at->timezone('Asia/Jakarta')->format('d M Y H:i:s WIB') }}
                        @if($run->duration_seconds)
                            &bull; {{ number_format($run->duration_seconds, 2) }}s
                        @endif
                    </div>
                </div>

                <!-- Run Analyses -->
                @if($run->marketAnalyses->isNotEmpty())
                    <div class="space-y-3">
                        @foreach($run->marketAnalyses as $analysis)
                            <div class="bg-[#0D2B4E] border border-[#F5F1E8]/10 rounded-lg p-4 space-y-2">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex items-center space-x-2">
                                        <span class="text-[10px] font-mono font-semibold px-2 py-0.5 rounded
                                            {{ $analysis->impact_direction === 'positive' ? 'bg-emerald-950/60 text-emerald-300 border border-emerald-500/30' :
                                               ($analysis->impact_direction === 'negative' ? 'bg-rose-950/60 text-rose-300 border border-rose-500/30' : 'bg-[#071C35] text-[#F5F1E8]/70 border border-[#F5F1E8]/10') }}">
                                            {{ strtoupper($analysis->impact_direction) }}
                                        </span>
                                        <span class="text-xs text-[#E5C49B] font-mono">{{ $analysis->model_name }} &bull; Prompt v{{ $analysis->prompt_version }}</span>
                                    </div>
                                    <span class="text-[11px] text-[#F5F1E8]/50 font-mono">Horizon: {{ ucfirst($analysis->impact_horizon) }}</span>
                                </div>
                                <p class="text-xs text-[#F5F1E8]/80 leading-relaxed">{{ $analysis->market_regime_summary }}</p>

                                <span class="text-[10px] text-[#F5F1E8]/40 font-mono">Evidence dan output terstruktur tersimpan. JSON mentah disembunyikan.</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-xs text-[#F5F1E8]/40 italic">Tidak ada output analisis tersimpan pada run ini.</p>
                @endif
            </div>
        @empty
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-12 text-center text-[#F5F1E8]/50 text-xs font-mono">
                Belum ada histori analisis pasar yang tercatat.
            </div>
        @endforelse

        @if($runs->hasPages())
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg px-4 py-3 text-xs">
                {{ $runs->links() }}
            </div>
        @endif
    </div>

</div>
@endsection

