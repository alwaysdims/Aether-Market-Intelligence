@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div>
        <h2 class="text-xl font-bold text-white">Pengaturan & Konfigurasi Sistem</h2>
        <p class="text-xs text-slate-400">Kelola prompt template berversi, model AI terkunci, dan sumber data.</p>
    </div>

    <!-- AI Model Lock Info -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 space-y-3">
        <h3 class="text-sm font-bold text-white flex items-center space-x-2">
            <span>🔒</span>
            <span>Konfigurasi Model AI (Terkunci)</span>
        </h3>
        <p class="text-xs text-slate-300">
            Sesuai Master Requirement, model AI dikunci ke <span class="text-sky-400 font-mono font-bold">ag/gemini-3.8-flash-high</span> melalui router lokal 9router di <span class="text-sky-400 font-mono">http://127.0.0.1:20128/v1</span>. Sistem dilarang mengganti model secara otomatis.
        </p>
    </div>

    <!-- Prompt Templates -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 space-y-4">
        <h3 class="text-sm font-bold text-white">Prompt Template Terdaftar</h3>
        <div class="space-y-3">
            @foreach($promptTemplates as $template)
                <div class="bg-slate-900/60 border border-slate-700/60 rounded-lg p-4 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="font-mono text-xs font-bold text-sky-400">{{ $template->code }} (v{{ $template->version }})</span>
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold {{ $template->is_active ? 'bg-emerald-500/20 text-emerald-400' : 'bg-slate-700 text-slate-400' }}">
                            {{ $template->is_active ? 'AKTIF' : 'NON-AKTIF' }}
                        </span>
                    </div>
                    <div class="text-xs text-slate-400 space-y-1">
                        <p><span class="text-slate-500 font-semibold">Model ID:</span> <span class="font-mono text-slate-300">{{ $template->model_id }}</span></p>
                        <p><span class="text-slate-500 font-semibold">System Prompt:</span> <span class="text-slate-300">{{ $template->system_prompt }}</span></p>
                        <details class="pt-1">
                            <summary class="cursor-pointer text-slate-400 hover:text-sky-300 text-[11px]">Lihat User Prompt Template</summary>
                            <pre class="mt-2 bg-slate-950 p-3 rounded border border-slate-800 text-slate-300 whitespace-pre-wrap font-mono text-[10px]">{{ $template->user_prompt_template }}</pre>
                        </details>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <!-- Data Sources -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 space-y-4">
        <h3 class="text-sm font-bold text-white">Konektor Sumber Data</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-900/80 text-slate-400 font-semibold uppercase tracking-wider border-b border-slate-700">
                    <tr>
                        <th class="px-4 py-2.5">Nama Sumber</th>
                        <th class="px-4 py-2.5">Tipe</th>
                        <th class="px-4 py-2.5">Klasifikasi</th>
                        <th class="px-4 py-2.5">Status</th>
                        <th class="px-4 py-2.5">Base URL</th>
                        <th class="px-4 py-2.5">Sukses Terakhir</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-700/60 font-mono text-[11px]">
                    @foreach($dataSources as $ds)
                        <tr>
                            <td class="px-4 py-2.5 font-bold text-white font-sans">{{ $ds->name }}</td>
                            <td class="px-4 py-2.5">{{ $ds->type }}</td>
                            <td class="px-4 py-2.5">{{ $ds->data_classification }}</td>
                            <td class="px-4 py-2.5">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold {{ $ds->status === 'active' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' }}">
                                    {{ strtoupper($ds->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-slate-400">{{ $ds->base_url ?? '-' }}</td>
                            <td class="px-4 py-2.5 text-slate-400">{{ $ds->last_success_at ? $ds->last_success_at->timezone('Asia/Jakarta')->format('d/m/Y H:i') : '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
