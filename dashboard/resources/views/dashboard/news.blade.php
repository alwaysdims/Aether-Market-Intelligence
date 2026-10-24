@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white">News & Peristiwa Pasar</h2>
            <p class="text-xs text-slate-400">Monitoring berita pasar modal dengan klasifikasi verifikasi fakta.</p>
        </div>
    </div>

    <div class="space-y-4">
        @forelse($news as $item)
            <div class="bg-slate-800 border border-slate-700 rounded-xl p-4 hover:border-slate-600 transition">
                <div class="flex items-start justify-between">
                    <div class="space-y-1">
                        <div class="flex items-center space-x-2">
                            <span class="text-xs font-semibold px-2 py-0.5 rounded
                                {{ $item->verification_status === 'official_statement' ? 'bg-emerald-500/20 text-emerald-300' :
                                   ($item->verification_status === 'verified_fact' ? 'bg-sky-500/20 text-sky-300' : 'bg-slate-700 text-slate-300') }}">
                                {{ strtoupper($item->verification_status) }}
                            </span>
                            <span class="text-xs text-slate-400 font-medium">{{ $item->source_name }}</span>
                            <span class="text-xs text-slate-500">&bull;</span>
                            <span class="text-xs text-slate-400">
                                {{ $item->published_at ? $item->published_at->timezone('Asia/Jakarta')->format('d M Y H:i WIB') : 'Waktu tidak diketahui' }}
                            </span>
                        </div>
                        <h3 class="text-sm font-bold text-white hover:text-sky-400 transition">
                            <a href="{{ $item->url }}" target="_blank" rel="noopener noreferrer">{{ $item->title }}</a>
                        </h3>
                        @if($item->summary)
                            <p class="text-xs text-slate-300 line-clamp-2 mt-1">{{ $item->summary }}</p>
                        @endif
                    </div>
                </div>

                @if($item->related_tickers && count($item->related_tickers) > 0)
                    <div class="mt-3 flex items-center space-x-2">
                        <span class="text-[11px] text-slate-500">Ticker terkait:</span>
                        @foreach($item->related_tickers as $tick)
                            <span class="text-[10px] bg-slate-900 border border-slate-700 text-sky-300 font-mono px-2 py-0.5 rounded">
                                {{ $tick }}
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <div class="bg-slate-800 border border-slate-700 rounded-xl p-12 text-center text-slate-500 text-sm">
                Belum ada berita tersimpan. Jalankan sinkronisasi berita melalui pipeline.
            </div>
        @endforelse

        @if($news->hasPages())
            <div class="p-4">
                {{ $news->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
