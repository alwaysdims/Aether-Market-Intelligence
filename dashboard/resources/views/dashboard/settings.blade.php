@extends('layouts.app')

@section('page_title', 'Pengaturan & Konfigurasi Sistem')

@section('content')
<div class="space-y-6">

    <!-- Header Strip -->
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center space-x-2 mb-1">
                <span class="w-2.5 h-2.5 rounded-full bg-[#E5C49B]"></span>
                <h1 class="text-lg font-bold text-[#F5F1E8] tracking-tight uppercase">Pengaturan & Konfigurasi Sistem</h1>
            </div>
            <p class="text-xs text-[#F5F1E8]/60">
                Tata kelola prompt template berversi, model AI terkunci, guardrail inferensi langsung, dan konektor sumber data pasar modal BEI.
            </p>
        </div>

        <div class="flex items-center space-x-2">
            <span class="px-2.5 py-1 bg-[#05152B] border border-[#E5C49B]/30 rounded text-[11px] font-mono text-[#E5C49B]">
                INFERENCE_GUARD: STRICT
            </span>
        </div>
    </div>

    <!-- AI Model Lock & Inference Guard Banner -->
    <div class="bg-[#0A213D] border border-[#E5C49B]/30 rounded-lg p-5 space-y-3">
        <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B] flex items-center space-x-2">
            <span>🔒</span>
            <span>Konfigurasi Model AI & Guardrail Inferensi (Terkunci)</span>
        </h3>
        <p class="text-xs text-[#F5F1E8]/80 leading-relaxed">
            AI dashboard memakai provider <span class="text-[#E5C49B] font-mono font-bold">{{ strtoupper(config('services.dashboard_ai.provider', 'google')) }}</span>
            dengan model <span class="text-[#E5C49B] font-mono font-bold">{{ config('services.dashboard_ai.model', 'N/A') }}</span>.
            Konsultasi Hermes memakai 9router terpisah dengan model <span class="text-[#E5C49B] font-mono">{{ config('services.ai_router.model_id', 'N/A') }}</span>.
            Inferensi EOD dashboard: <span class="text-emerald-400 font-mono font-bold">{{ config('services.dashboard_ai.eod_inference_enabled') ? 'ENABLED' : 'DISABLED' }}</span>.
            Sistem tidak mengganti model otomatis dan tidak memiliki eksekusi order broker.
        </p>
    </div>

    <!-- Prompt Templates -->
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
        <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Prompt Template Terdaftar & Berversi</h3>
        <div class="space-y-3">
            @foreach($promptTemplates as $template)
                <div class="bg-[#05152B] border border-[#F5F1E8]/10 rounded-lg p-4 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="font-mono text-xs font-bold text-[#E5C49B]">{{ $template->code }} (v{{ $template->version }})</span>
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold {{ $template->is_active ? 'bg-emerald-950/60 text-emerald-300 border border-emerald-500/30' : 'bg-[#0A213D] text-[#F5F1E8]/40 border border-[#F5F1E8]/10' }}">
                            {{ $template->is_active ? 'AKTIF' : 'NON-AKTIF' }}
                        </span>
                    </div>
                    <div class="text-xs text-[#F5F1E8]/70 space-y-1">
                        <p><span class="text-[#F5F1E8]/40 font-mono">Model ID:</span> <span class="font-mono text-[#F5F1E8]">{{ $template->model_id }}</span></p>
                        <p><span class="text-[#F5F1E8]/40 font-mono">System Prompt:</span> <span class="text-[#F5F1E8]">{{ $template->system_prompt }}</span></p>
                        <details class="pt-1">
                            <summary class="cursor-pointer text-[#E5C49B] hover:underline text-[11px] font-mono">Lihat Template Prompt Pengguna</summary>
                            <pre class="mt-2 bg-[#0A213D] p-3 rounded border border-[#F5F1E8]/10 text-[#F5F1E8]/90 whitespace-pre-wrap font-mono text-[10px] leading-relaxed">{{ $template->user_prompt_template }}</pre>
                        </details>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <!-- Data Sources Registry -->
    @php
        $registryService = app(\App\Services\DataSourceRegistryService::class);
    @endphp
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
                <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Registri Sumber Data Pasar Modal (Data Sources)</h3>
                <p class="text-[11px] text-[#F5F1E8]/50 mt-0.5">Daftar konektor terkanonisasi dengan perincian peran, status izin runtime, kualitas data, dan alasan karantina.</p>
            </div>
            <span class="px-2.5 py-1 rounded text-[10px] font-mono bg-[#0D2B4E] text-[#F5F1E8]/70 border border-[#F5F1E8]/10 self-start sm:self-auto">
                Total: {{ $dataSources->count() }} Sumber
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-[#F5F1E8]">
                <thead class="bg-[#071C35] text-[#F5F1E8]/50 uppercase tracking-wider font-mono text-[10px] border-b border-[#F5F1E8]/10">
                    <tr>
                        <th class="px-4 py-2.5">Nama & Endpoint</th>
                        <th class="px-4 py-2.5">Peran & Tipe</th>
                        <th class="px-4 py-2.5">Klasifikasi</th>
                        <th class="px-4 py-2.5">Izin Operasional Runtime</th>
                        <th class="px-4 py-2.5">Kualitas & Bukti Verifikasi</th>
                        <th class="px-4 py-2.5">Health & Terakhir Dipakai</th>
                        <th class="px-4 py-2.5">Alasan Karantina / Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#F5F1E8]/5 font-mono text-[11px]">
                    @foreach($dataSources as $ds)
                        @php
                            $effective = $registryService->getEffectiveStatus($ds);
                        @endphp
                        <tr class="hover:bg-[#0D2B4E]/60 transition">
                            <td class="px-4 py-2.5">
                                <div class="font-bold text-[#F5F1E8] font-sans">{{ $ds->name }}</div>
                                <div class="text-[10px] text-[#F5F1E8]/40 font-mono">{{ rtrim($ds->base_url, '/') . '/' . ltrim($ds->endpoint, '/') }}</div>
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="text-[#E5C49B]">{{ $ds->metadata['role'] ?? $ds->type }}</div>
                                <div class="text-[10px] text-[#F5F1E8]/40 uppercase">{{ $ds->type }}</div>
                            </td>
                            <td class="px-4 py-2.5 text-[#F5F1E8]/80">
                                <span class="px-1.5 py-0.5 rounded text-[10px] bg-[#05152B] border border-[#F5F1E8]/10">
                                    {{ $ds->data_classification }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5">
                                @if($effective['effective_enabled'])
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-950/80 text-emerald-300 border border-emerald-500/40">
                                        {{ $effective['label'] }}
                                    </span>
                                @elseif($effective['badge'] === 'QUARANTINED')
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-950/80 text-rose-300 border border-rose-500/40">
                                        {{ $effective['label'] }}
                                    </span>
                                @elseif($effective['badge'] === 'HISTORICAL_ARCHIVE')
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-950/60 text-amber-300 border border-amber-500/30">
                                        {{ $effective['label'] }}
                                    </span>
                                @elseif($effective['badge'] === 'PENDING_CANARY')
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-950/60 text-amber-300 border border-amber-500/30">
                                        {{ $effective['label'] }}
                                    </span>
                                @elseif($effective['badge'] === 'DEPRECATED_LEGACY')
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-zinc-900 text-zinc-500 border border-zinc-700/50">
                                        {{ $effective['label'] }}
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-zinc-900 text-zinc-400 border border-zinc-700/50">
                                        {{ $effective['label'] }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                @if($ds->data_quality === 'VALID')
                                    <span class="px-1.5 py-0.5 rounded text-[10px] bg-emerald-950 text-emerald-300 border border-emerald-500/40 font-semibold">VALID</span>
                                    <div class="text-[9px] text-[#F5F1E8]/50 font-sans mt-0.5">Tervalidasi kanonikal bursa</div>
                                @elseif($ds->data_quality === 'VERIFIED_ON_SAMPLE')
                                    <span class="px-1.5 py-0.5 rounded text-[10px] bg-cyan-950 text-cyan-300 border border-cyan-500/30 font-semibold">VERIFIED_ON_SAMPLE</span>
                                    <div class="text-[9px] text-[#F5F1E8]/50 font-sans mt-0.5">Sampel operasional lolos</div>
                                @elseif($ds->data_quality === 'PROVIDER_SUPPLIED')
                                    <span class="px-1.5 py-0.5 rounded text-[10px] bg-indigo-950 text-indigo-300 border border-indigo-500/30 font-semibold">PROVIDER_SUPPLIED</span>
                                    <div class="text-[9px] text-[#F5F1E8]/50 font-sans mt-0.5">Data provider (tanpa audit baris)</div>
                                @elseif($ds->data_quality === 'QUARANTINED')
                                    <span class="px-1.5 py-0.5 rounded text-[10px] bg-rose-950 text-rose-300 border border-rose-500/40 font-semibold">QUARANTINED</span>
                                    <div class="text-[9px] text-rose-400 font-sans mt-0.5">Ditolak untuk sinyal reguler</div>
                                @elseif($ds->data_quality === 'HISTORICAL_ARCHIVE_ONLY')
                                    <span class="px-1.5 py-0.5 rounded text-[10px] bg-amber-950/80 text-amber-300 border border-amber-500/30 font-semibold">ARSIP_HISTORIS</span>
                                    <div class="text-[9px] text-[#F5F1E8]/50 font-sans mt-0.5">Read-only, non-operasional</div>
                                @elseif($ds->data_quality === 'DEPRECATED')
                                    <span class="px-1.5 py-0.5 rounded text-[10px] bg-zinc-900 text-zinc-500 border border-zinc-700/50 font-semibold">DEPRECATED</span>
                                    <div class="text-[9px] text-[#F5F1E8]/50 font-sans mt-0.5">Dinonaktifkan total</div>
                                @else
                                    <span class="px-1.5 py-0.5 rounded text-[10px] bg-amber-950/60 text-amber-400 font-semibold">NOT_VERIFIED</span>
                                    <div class="text-[9px] text-amber-400/70 font-sans mt-0.5">Belum ada uji independen</div>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-[#F5F1E8]/70 text-[10px]">
                                <div class="flex items-center gap-1.5">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $ds->health === 'healthy' ? 'bg-emerald-400' : ($ds->health === 'degraded' ? 'bg-amber-400' : 'bg-zinc-600') }}"></span>
                                    <span class="capitalize text-[#F5F1E8]/80 font-sans font-medium">{{ $ds->health }}</span>
                                </div>
                                @if($ds->last_success_at)
                                    <div class="text-emerald-400 mt-1">Sukses: {{ $ds->last_success_at->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}</div>
                                @else
                                    <div class="text-[#F5F1E8]/40 mt-1">Sukses: -</div>
                                @endif
                                @if($ds->last_attempt_at)
                                    <div class="text-[#F5F1E8]/40">Upaya: {{ $ds->last_attempt_at->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-[10px] text-[#F5F1E8]/70 max-w-xs font-sans">
                                <div>{{ $effective['reason'] }}</div>
                                @if($ds->quarantine_reason && $effective['badge'] === 'QUARANTINED')
                                    <div class="text-rose-400 font-mono text-[9px] mt-1">⚠️ {{ $ds->quarantine_reason }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection

