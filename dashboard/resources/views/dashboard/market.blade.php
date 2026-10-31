@extends('layouts.app')

@section('page_title', 'Market Monitor')

@section('content')
<div class="space-y-6 max-w-[1600px] mx-auto">

    <!-- Header & Universe Overview -->
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-xl p-5 flex flex-col md:flex-row md:items-center md:justify-between gap-4 shadow-sm">
        <div>
            <div class="flex items-center space-x-2.5 mb-1">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-400"></span>
                <h1 class="text-lg font-bold text-[#F5F1E8] tracking-tight uppercase">Direktori & Radar Pasar BEI</h1>
                <x-badge variant="neutral">REGISTRI BEI</x-badge>
            </div>
            <p class="text-xs text-[#F5F1E8]/60">
                Pencarian instrumen terdaftar, cakupan histori EOD, klasifikasi kebaruan data, dan pemisahan tegas ekuitas vs indeks acuan.
            </p>
        </div>

        <!-- Tab Switcher: Saham Ekuitas vs Indeks Acuan -->
        <div class="flex items-center bg-[#05152B] p-1 rounded-lg border border-[#F5F1E8]/10 text-xs font-medium">
            <a href="{{ route('dashboard.market', array_merge(request()->except('type', 'page'), ['type' => 'equity'])) }}" class="px-3.5 py-1.5 rounded-md transition {{ ($type ?? 'equity') === 'equity' ? 'bg-[#0A213D] text-[#E5C49B] font-semibold border border-[#E5C49B]/30' : 'text-[#F5F1E8]/60 hover:text-[#F5F1E8]' }}">
                Saham Ekuitas
            </a>
            <a href="{{ route('dashboard.market', array_merge(request()->except('type', 'page'), ['type' => 'index'])) }}" class="px-3.5 py-1.5 rounded-md transition {{ ($type ?? '') === 'index' ? 'bg-[#0A213D] text-[#E5C49B] font-semibold border border-[#E5C49B]/30' : 'text-[#F5F1E8]/60 hover:text-[#F5F1E8]' }}">
                Indeks Acuan (^JKSE)
            </a>
        </div>
    </div>

    <!-- Filter & Search Controls Bar (Server-Side) -->
    <x-card>
        <form action="{{ route('dashboard.market') }}" method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3.5">
            <input type="hidden" name="type" value="{{ $type ?? 'equity' }}">

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

            <!-- Sector Filter -->
            <div>
                <label class="block text-[10px] font-mono uppercase text-[#F5F1E8]/50 mb-1">Sektor Industri</label>
                <select name="sector" onchange="this.form.submit()" class="w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded-lg px-3 py-1.5 text-xs text-[#F5F1E8] focus:outline-none focus:border-[#E5C49B]">
                    <option value="">Semua Sektor</option>
                    @foreach($sectors ?? [] as $sec)
                        <option value="{{ $sec }}" {{ ($sector ?? '') === $sec ? 'selected' : '' }}>{{ $sec }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Cakupan Data Filter -->
            <div>
                <label class="block text-[10px] font-mono uppercase text-[#F5F1E8]/50 mb-1">Cakupan Data</label>
                <select name="coverage" onchange="this.form.submit()" class="w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded-lg px-3 py-1.5 text-xs text-[#F5F1E8] focus:outline-none focus:border-[#E5C49B]">
                    <option value="">Semua Semesta Registri</option>
                    <option value="PILOT" {{ ($coverage ?? '') === 'PILOT' ? 'selected' : '' }}>10 Saham Pilot IDX Edge</option>
                    <option value="WITH_DATA" {{ ($coverage ?? '') === 'WITH_DATA' ? 'selected' : '' }}>Memiliki Riwayat EOD</option>
                    <option value="SECONDARY_ONLY" {{ ($coverage ?? '') === 'SECONDARY_ONLY' ? 'selected' : '' }}>Hanya Registri Sekunder (Belum Sync)</option>
                </select>
            </div>

            <!-- Sorting Column -->
            <div>
                <label class="block text-[10px] font-mono uppercase text-[#F5F1E8]/50 mb-1">Urutan</label>
                <select name="sort" onchange="this.form.submit()" class="w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded-lg px-3 py-1.5 text-xs text-[#F5F1E8] focus:outline-none focus:border-[#E5C49B]">
                    <option value="ticker" {{ ($sort ?? 'ticker') === 'ticker' ? 'selected' : '' }}>Kode Ticker (A-Z)</option>
                    <option value="company_name" {{ ($sort ?? '') === 'company_name' ? 'selected' : '' }}>Nama Emiten</option>
                    <option value="sector" {{ ($sort ?? '') === 'sector' ? 'selected' : '' }}>Sektor</option>
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
                        <th class="px-4 py-3">Sektor / Industri</th>
                        <th class="px-4 py-3 text-right">Harga Terkini</th>
                        <th class="px-4 py-3 text-right">Perubahan Sesi</th>
                        <th class="px-4 py-3">Sesi & Sumber</th>
                        <th class="px-4 py-3 text-center">Status Data</th>
                        <th class="px-4 py-3 text-center">Radar</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#F5F1E8]/5">
                    @forelse($companies as $company)
                        @php
                            $obs = $pageObservations->get($company->ticker);
                            $fresh = $freshnessByTicker[$company->ticker] ?? null;
                            $inWatch = in_array($company->ticker, $watchlistTickers ?? []);
                            $closePrice = $obs ? $obs->close_price : null;
                            $pc = $priceChangesByTicker[$company->ticker] ?? null;
                            $source = $sourceByTicker[$company->ticker] ?? null;
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
                                <span class="text-[9px] font-mono px-1.5 py-0.2 rounded bg-[#0D2B4E] text-[#F5F1E8]/40 border border-[#F5F1E8]/10 inline-block mt-0.5">
                                    {{ $company->listing_board ?? 'Utama' }}
                                </span>
                            </td>

                            <!-- Sektor / Industri -->
                            <td class="px-4 py-3">
                                <span class="block text-xs text-[#F5F1E8]/80">{{ $company->sector ?: '-' }}</span>
                                <span class="block text-[10px] text-[#F5F1E8]/40 truncate max-w-[150px]">{{ ($company->industry && $company->industry !== $company->sector) ? $company->industry : ($company->sub_industry ?: 'Belum tersedia') }}</span>
                            </td>

                            <!-- Harga Terkini (Right aligned tabular) -->
                            <td class="px-4 py-3 font-mono tabular-nums text-sm font-semibold text-right">
                                @php
                                    $obsState = ($obs && isset($marketRead)) ? $marketRead->state($obs) : 'VALID';
                                    $obsQuarantined = in_array($obsState, ['PROVISIONAL', 'CONFLICTED', 'MISSING']);
                                @endphp
                                @if($obs && !$obsQuarantined && $closePrice !== null)
                                    <div class="text-right">Rp {{ number_format($closePrice, 0, ',', '.') }}</div>
                                    <span class="text-[9px] text-[#F5F1E8]/40 font-mono block text-right">ID: #{{ $obs->id }}</span>
                                @else
                                    <span class="text-[#F5F1E8]/30 font-normal text-xs text-right block">DATA_NOT_AVAILABLE</span>
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

                            <!-- Sesi & Sumber -->
                            <td class="px-4 py-3 text-xs">
                                @if($obs)
                                    <span class="block font-mono text-[#F5F1E8]">{{ $obs->bar_date->format('Y-m-d') }}</span>
                                    <span class="block text-[10px] text-[#E5C49B] font-mono">{{ $source ?? 'IDX Edge PRO' }}</span>
                                @else
                                    <span class="text-[#F5F1E8]/40 font-mono text-[11px]">Registri Sekunder</span>
                                    <span class="block text-[10px] text-[#F5F1E8]/30">Belum tersinkron</span>
                                @endif
                            </td>

                            <!-- Status Data / Freshness -->
                            <td class="px-4 py-3 text-center">
                                @if($obs)
                                    <x-badge variant="success">TERVERIFIKASI</x-badge>
                                @else
                                    <x-badge variant="neutral">REGISTRI ONLY</x-badge>
                                @endif
                            </td>

                            <!-- Watchlist Toggle Star Button -->
                            <td class="px-4 py-3 text-center">
                                <button onclick="toggleWatchlist('{{ $company->ticker }}', this)" title="{{ $inWatch ? 'Dalam Radar Monitoring' : 'Tambahkan ke Radar' }}" class="p-1 rounded-md border transition {{ $inWatch ? 'text-[#E5C49B] border-[#E5C49B]/40 bg-[#E5C49B]/10' : 'text-[#F5F1E8]/30 border-[#F5F1E8]/10 hover:text-[#E5C49B] hover:border-[#E5C49B]/30' }}">
                                    <svg class="w-4 h-4 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                </button>
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
                            <td colspan="8" class="px-4 py-12 text-center">
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
@endsection

