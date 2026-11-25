@extends('layouts.app')

@section('page_title', 'Market Monitor')

@section('content')
<div class="space-y-6 max-w-[1600px] mx-auto">

    @if($pageObservations->isNotEmpty() && $pageObservations->every(fn ($observation) => $marketRead->state($observation) !== 'FINAL'))
        <div class="mb-4 rounded border border-amber-500/30 bg-amber-950/30 px-4 py-2 text-xs text-amber-200">DATA_NOT_AVAILABLE — harga acuan terkarantina; status sumber dipertahankan.</div>
    @endif

    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-xl p-5">
        <div class="flex items-center space-x-2.5 mb-1">
            <span class="w-2.5 h-2.5 rounded-full bg-emerald-400"></span>
            <h1 class="text-lg font-bold text-[#F5F1E8] tracking-tight uppercase">Direktori Pasar BEI</h1>
            <x-badge variant="neutral">SAHAM EKUITAS</x-badge>
        </div>
        <p class="text-xs text-[#F5F1E8]/60">Daftar emiten dan harga sesi terakhir berasal dari API IDX Edge.</p>
        <p class="mt-2 text-[10px] font-mono text-emerald-300">Registry DB + Batch API: {{ number_format($companies->total(), 0, ',', '.') }} emiten. Harga diperbarui otomatis tiap 60 detik.</p>
    </div>

    <!-- Filter & Search Controls Bar (Server-Side) -->
    <x-card>
        <form action="{{ route('dashboard.market') }}" method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3.5">
            <input type="hidden" name="type" value="equity">

            <!-- Search Input -->
            <div class="lg:col-span-1">
                <label class="block text-[10px] font-mono uppercase text-[#F5F1E8]/50 mb-1">Pencarian Ticker / Nama</label>
                <div class="relative">
                    <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Ketik kode (cth: BBCA)..." class="w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded-lg px-3 py-1.5 text-xs text-[#F5F1E8] placeholder-[#F5F1E8]/30 focus:outline-none focus:border-[#E5C49B]">
                    @if($search ?? '')
                        <a href="{{ route('dashboard.market', request()->except('search', 'page')) }}" class="absolute right-2.5 top-1.5 text-xs text-[#F5F1E8]/40 hover:text-[#F5F1E8]">&times;</a>
                    @endif
                </div>
            </div>

            <!-- Sorting Column -->
            <div>
                <label class="block text-[10px] font-mono uppercase text-[#F5F1E8]/50 mb-1">Urutan</label>
                <select name="sort" onchange="this.form.submit()" class="w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded-lg px-3 py-1.5 text-xs text-[#F5F1E8] focus:outline-none focus:border-[#E5C49B]">
                    <option value="ticker" {{ ($sort ?? 'ticker') === 'ticker' ? 'selected' : '' }}>Kode Ticker (A-Z)</option>
                    <option value="company_name" {{ ($sort ?? '') === 'company_name' ? 'selected' : '' }}>Nama Emiten</option>
                    <option value="listing_board" {{ ($sort ?? '') === 'listing_board' ? 'selected' : '' }}>Papan Pencatatan</option>
                </select>
            </div>

            <!-- Submit Button & Reset -->
            <div class="flex items-end space-x-2">
                <button type="submit" class="flex-1 px-3 py-1.5 bg-[#E5C49B] hover:bg-[#D6B284] text-[#05152B] rounded-lg text-xs font-semibold tracking-wide transition">
                    Filter
                </button>
                <a href="{{ route('dashboard.market', ['type' => $type ?? 'equity']) }}" class="px-3 py-1.5 bg-[#0D2B4E] hover:bg-[#153660] text-[#F5F1E8]/70 hover:text-[#F5F1E8] rounded-lg text-xs transition border border-[#F5F1E8]/10">
                    Reset
                </a>
            </div>
        </form>
    </x-card>

    <!-- Financial Data Table Container -->
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-xl overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-[#F5F1E8]">
                <thead class="bg-[#071C35] text-[#F5F1E8]/50 font-semibold uppercase tracking-wider text-[10px] font-mono border-b border-[#F5F1E8]/10">
                    <tr>
                        <th class="px-4 py-3">Emiten & Papan</th>
                        <th class="px-4 py-3 text-right">Penutupan EOD</th>
                        <th class="px-4 py-3 text-right">Perubahan Sesi</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#F5F1E8]/5">
                    @forelse($companies as $company)
                        @php
                            $obs = $pageObservations->get($company->ticker);
                            $obsState = $obs ? $marketRead->state($obs) : 'DATA_NOT_AVAILABLE';
                            $api = $apiPrices[$company->ticker] ?? [];
                            $apiData = ($api['success'] ?? false) ? ($api['data'] ?? []) : [];
                            $closePrice = $obsState === 'FINAL' ? ($obs->close_price ?? null) : null;
                            $pc = $priceChangesByTicker[$company->ticker] ?? [
                                'is_previous_close_available' => isset($apiData['change'], $apiData['change_pct']),
                                'daily_change' => $apiData['change'] ?? null,
                                'daily_change_pct' => $apiData['change_pct'] ?? null,
                                'previous_bar_date' => $apiData['source_date'] ?? null,
                            ];
                        @endphp
                        <tr class="hover:bg-[#0D2B4E]/60 transition group">
                            <!-- Emiten & Papan -->
                            <td class="px-4 py-3">
                                <a href="{{ route('dashboard.ticker', $company->ticker) }}" class="font-bold text-[#E5C49B] group-hover:underline text-sm font-mono block">
                                    {{ $company->ticker }}
                                </a>
                                <span class="text-[11px] text-[#F5F1E8]/70 block truncate max-w-[200px]" title="{{ $company->company_name }}">
                                    {{ $company->company_name }}
                                </span>
                            </td>

                            <td class="px-4 py-3 font-mono tabular-nums text-sm font-semibold text-right">
                                @if($closePrice !== null)
                                    Rp{{ number_format($closePrice, 0, ',', '.') }}
                                    <span class="block text-[9px] text-[#F5F1E8]/40 font-mono">Observation ID: {{ $obs->id }}</span>
                                @elseif($obsState === 'CONFLICTED')
                                    <span class="text-rose-400 font-normal text-xs">CONFLICTED</span>
                                @elseif($obsState === 'PROVISIONAL')
                                    <span class="text-amber-300 font-normal text-xs">PROVISIONAL</span>
                                @else
                                    <span class="text-[#F5F1E8]/30 font-normal text-xs">DATA_NOT_AVAILABLE</span>
                                @endif
                            </td>

                            <!-- Perubahan Harian (Right aligned tabular) -->
                            <td class="px-4 py-3 font-mono tabular-nums text-xs text-right">
                                @if($pc && $pc['is_previous_close_available'] && $pc['daily_change_pct'] !== null)
                                    @php $dChg = $pc['daily_change_pct']; @endphp
                                    <div class="{{ $dChg > 0 ? 'text-emerald-400' : ($dChg < 0 ? 'text-rose-400' : 'text-[#F5F1E8]/60') }} font-semibold">
                                        {{ $pc['daily_change'] > 0 ? '+' : '' }}{{ number_format($pc['daily_change'], 0, ',', '.') }} ({{ $dChg > 0 ? '+' : '' }}{{ number_format($dChg, 2, ',', '.') }}%)
                                    </div>
                                    <span class="block text-[9px] text-[#F5F1E8]/40">
                                        vs Prev ({{ $pc['previous_bar_date'] }})
                                    </span>
                                @else
                                    <span class="text-[#F5F1E8]/30 block font-normal">N/A</span>
                                @endif
                            </td>

                            <!-- Aksi Detail -->
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('dashboard.ticker', $company->ticker) }}" class="px-2.5 py-1 rounded-lg bg-[#0D2B4E] hover:bg-[#E5C49B] hover:text-[#05152B] text-[#F5F1E8] text-xs font-medium transition border border-[#F5F1E8]/10 inline-flex items-center">
                                    Detail &rarr;
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-12 text-center">
                                <x-empty-state
                                    title="Tidak Ada Emiten Ditemukan"
                                    message="Tidak ada rekod instrumen yang memenuhi parameter pencarian atau filter yang ditentukan."
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Server-side Pagination Links -->
        @if(isset($companies) && $companies->hasPages())
            <div class="bg-[#071C35] px-4 py-3 border-t border-[#F5F1E8]/10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <div class="text-xs text-[#F5F1E8]/50 font-mono">
                    Menampilkan <span class="font-bold text-[#F5F1E8]">{{ $companies->firstItem() }}</span> - <span class="font-bold text-[#F5F1E8]">{{ $companies->lastItem() }}</span> dari <span class="font-bold text-[#E5C49B]">{{ $companies->total() }}</span> emiten
                </div>
                <div class="text-xs">
                    {{ $companies->links() }}
                </div>
            </div>
        @endif
    </div>

</div>

<script>
    window.setTimeout(() => window.location.reload(), 60000);
</script>
@endsection

