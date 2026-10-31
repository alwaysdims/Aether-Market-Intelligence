@extends('layouts.app')

@section('page_title', 'News & Peristiwa Pasar')

@section('content')
<div class="space-y-6">

    <!-- Header Strip -->
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center space-x-2 mb-1">
                <span class="w-2.5 h-2.5 rounded-full bg-cyan-400"></span>
                <h1 class="text-lg font-bold text-[#F5F1E8] tracking-tight uppercase">News & Peristiwa Pasar</h1>
            </div>
            <p class="text-xs text-[#F5F1E8]/60">
                Monitoring berita pasar modal dengan klasifikasi verifikasi fakta, pengumuman bursa, dan sentimen sektoral.
            </p>
        </div>

        <span class="text-xs text-[#E5C49B] bg-[#0D2B4E] border border-[#E5C49B]/30 px-3 py-1.5 rounded font-mono">
            LKBN ANTARA &bull; Keterbukaan IDX
        </span>
    </div>

    <!-- Filter Bar -->
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-4">
        <form action="{{ route('dashboard.news') }}" method="GET" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <!-- Ticker Filter -->
            <div>
                <label class="block text-[10px] font-mono uppercase text-[#F5F1E8]/50 mb-1">Filter Kode Emiten</label>
                <input type="text" name="ticker" value="{{ $ticker ?? '' }}" placeholder="Cth: BBCA, TLKM..." class="w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded px-3 py-1.5 text-xs text-[#F5F1E8] placeholder-[#F5F1E8]/30 uppercase font-mono focus:outline-none focus:border-[#E5C49B]">
            </div>

            <!-- Verification Status Filter -->
            <div>
                <label class="block text-[10px] font-mono uppercase text-[#F5F1E8]/50 mb-1">Klasifikasi Verifikasi</label>
                <select name="verification" onchange="this.form.submit()" class="w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded px-3 py-1.5 text-xs text-[#F5F1E8] focus:outline-none focus:border-[#E5C49B]">
                    <option value="">Semua Status</option>
                    <option value="official_statement" {{ ($verification ?? '') === 'official_statement' ? 'selected' : '' }}>OFFICIAL_STATEMENT (Resmi)</option>
                    <option value="verified_fact" {{ ($verification ?? '') === 'verified_fact' ? 'selected' : '' }}>VERIFIED_FACT (Fakta Tervalidasi)</option>
                    <option value="news_outlet" {{ ($verification ?? '') === 'news_outlet' ? 'selected' : '' }}>NEWS_OUTLET (Media Berita)</option>
                </select>
            </div>

            <!-- Actions -->
            <div class="flex items-end space-x-2">
                <button type="submit" class="flex-1 px-3 py-1.5 bg-[#E5C49B] hover:bg-[#D6B284] text-[#05152B] rounded text-xs font-semibold tracking-wide transition">
                    Cari Berita
                </button>
                <a href="{{ route('dashboard.news') }}" class="px-3 py-1.5 bg-[#0D2B4E] hover:bg-[#153660] text-[#F5F1E8]/70 hover:text-[#F5F1E8] rounded text-xs transition border border-[#F5F1E8]/10">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <!-- News List -->
    <div class="space-y-3">
        @forelse($news as $item)
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-4 hover:border-[#E5C49B]/30 transition space-y-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center space-x-2 text-xs">
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-semibold
                            {{ $item->verification_status === 'official_statement' ? 'bg-emerald-950/60 text-emerald-300 border border-emerald-500/30' :
                               ($item->verification_status === 'verified_fact' ? 'bg-cyan-950/60 text-cyan-300 border border-cyan-500/30' : 'bg-[#0D2B4E] text-[#F5F1E8]/70 border border-[#F5F1E8]/10') }}">
                            {{ strtoupper($item->verification_status ?? 'NEWS_OUTLET') }}
                        </span>
                        <span class="text-[#F5F1E8]/60 font-medium">{{ $item->source_name }}</span>
                        <span class="text-[#F5F1E8]/30">&bull;</span>
                        <span class="text-[#F5F1E8]/50 font-mono text-[11px]">
                            {{ $item->published_at ? $item->published_at->timezone('Asia/Jakarta')->format('d M Y H:i WIB') : 'Waktu tidak diketahui' }}
                        </span>
                    </div>

                    @if($item->related_tickers && count($item->related_tickers) > 0)
                        <div class="flex items-center space-x-1.5">
                            <span class="text-[10px] text-[#F5F1E8]/40 uppercase font-mono">Ticker:</span>
                            @foreach($item->related_tickers as $tick)
                                <a href="{{ route('dashboard.ticker', $tick) }}" class="text-[10px] bg-[#0D2B4E] hover:bg-[#E5C49B] hover:text-[#05152B] border border-[#F5F1E8]/10 text-[#E5C49B] font-mono font-bold px-1.5 py-0.5 rounded transition">
                                    {{ $tick }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                <h3 class="text-sm font-bold text-[#F5F1E8]">
                    <a href="{{ $item->url }}" target="_blank" rel="noopener noreferrer" class="hover:text-[#E5C49B] transition flex items-center">
                        {{ $item->title }}
                        <svg class="w-3.5 h-3.5 ml-1 text-[#F5F1E8]/40 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                    </a>
                </h3>

                @if($item->summary)
                    <p class="text-xs text-[#F5F1E8]/70 leading-relaxed">{{ $item->summary }}</p>
                @endif
            </div>
        @empty
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-12 text-center text-[#F5F1E8]/50 text-xs font-mono">
                Belum ada berita tersimpan yang sesuai dengan kriteria filter saat ini.
            </div>
        @endforelse

        <!-- Pagination -->
        @if($news->hasPages())
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg px-4 py-3 flex items-center justify-between text-xs">
                {{ $news->links() }}
            </div>
        @endif
    </div>

</div>
@endsection

