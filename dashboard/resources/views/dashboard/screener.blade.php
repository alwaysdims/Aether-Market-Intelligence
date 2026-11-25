@extends('layouts.app')

@section('page_title', 'Screener')

@section('content')
<div class="space-y-6 max-w-[1600px] mx-auto">
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-xl p-5">
        <div class="flex items-center space-x-2.5 mb-1">
            <span class="w-2.5 h-2.5 rounded-full bg-[#E5C49B]"></span>
            <h1 class="text-lg font-bold text-[#F5F1E8] tracking-tight uppercase">Screener Provider</h1>
            <x-badge variant="neutral">STATISTIK PROVIDER</x-badge>
        </div>
        <p class="text-xs text-[#F5F1E8]/60">Data provider. Bukan skor ML Aether, probabilitas pribadi, atau jaminan return.</p>
        @if(($result['success'] ?? false))
            <p class="mt-2 text-[10px] font-mono text-emerald-300">Sumber: {{ $result['source'] ?? 'N/A' }} • Tanggal laporan: {{ $result['as_of_date'] ?? 'N/A' }} • Fetched: {{ $result['fetched_at'] ?? $result['received_at'] ?? 'N/A' }} • Hash: {{ $result['payload_sha256'] ?? 'N/A' }}</p>
        @else
            <p class="mt-2 text-[10px] font-mono text-amber-300">Status: {{ $result['error_type'] ?? 'PROVIDER_UNAVAILABLE' }} — {{ $result['message'] ?? 'Data screener belum tersedia.' }}</p>
        @endif
    </div>

    <x-card>
        <form action="{{ route('dashboard.screener') }}" method="GET" class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
            <div>
                <label class="block text-[10px] font-mono uppercase text-[#F5F1E8]/50 mb-1">Ticker / Nama</label>
                <input type="text" name="search" value="{{ $search }}" placeholder="Contoh: BBCA" class="w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded-lg px-3 py-1.5 text-xs text-[#F5F1E8] placeholder-[#F5F1E8]/30 focus:outline-none focus:border-[#E5C49B]">
            </div>
            <div>
                <label class="block text-[10px] font-mono uppercase text-[#F5F1E8]/50 mb-1">Bucket Provider</label>
                <select name="bucket" class="w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded-lg px-3 py-1.5 text-xs text-[#F5F1E8] focus:outline-none focus:border-[#E5C49B]">
                    <option value="">Semua bucket</option>
                    @foreach($buckets as $option)
                        <option value="{{ $option }}" @selected($bucket === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end space-x-2">
                <button type="submit" class="flex-1 px-3 py-1.5 bg-[#E5C49B] hover:bg-[#D6B284] text-[#05152B] rounded-lg text-xs font-semibold">Filter</button>
                <a href="{{ route('dashboard.screener') }}" class="px-3 py-1.5 bg-[#0D2B4E] text-[#F5F1E8]/70 rounded-lg text-xs border border-[#F5F1E8]/10">Reset</a>
            </div>
        </form>
    </x-card>

    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-xl overflow-hidden shadow-sm">
        @if(($result['success'] ?? false) && $items->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-[#F5F1E8]">
                    <thead class="bg-[#071C35] text-[#F5F1E8]/50 font-semibold uppercase tracking-wider text-[10px] font-mono">
                        <tr>
                            <th class="px-4 py-3">Ticker</th><th class="px-4 py-3">Bucket</th><th class="px-4 py-3">Summary</th><th class="px-4 py-3">WR Event</th><th class="px-4 py-3">Potential</th><th class="px-4 py-3">Drawdown</th><th class="px-4 py-3">Note</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F1E8]/5">
                        @foreach($items as $item)
                            @php($ticker = strtoupper((string) ($item['ticker'] ?? $item['stock_code'] ?? '')))
                            <tr class="hover:bg-[#0D2B4E]/60">
                                <td class="px-4 py-3"><a class="text-[#E5C49B] font-bold hover:underline" href="{{ route('dashboard.ticker', ['ticker' => $ticker]) }}">{{ $ticker }}</a><div class="text-[10px] text-[#F5F1E8]/50">{{ $item['stock_name'] ?? $item['company_name'] ?? 'N/A' }}</div></td>
                                <td class="px-4 py-3">{{ $item['bucket'] ?? $item['category'] ?? 'N/A' }}</td>
                                <td class="px-4 py-3">{{ $item['summary'] ?? 'N/A' }}</td>
                                <td class="px-4 py-3">{{ $item['wr_event'] ?? 'N/A' }}</td>
                                <td class="px-4 py-3">{{ $item['potential'] ?? 'N/A' }}</td>
                                <td class="px-4 py-3">{{ $item['drawdown'] ?? 'N/A' }}</td>
                                <td class="px-4 py-3">{{ $item['note'] ?? 'N/A' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif(($result['success'] ?? false))
            <div class="p-8 text-center text-sm text-[#F5F1E8]/60">Respons provider valid, tetapi tidak ada baris yang cocok.</div>
        @else
            <div class="p-8 text-center text-sm text-[#F5F1E8]/60">Screener belum dapat ditampilkan. Error provider tidak dianggap sebagai data kosong.</div>
        @endif
    </div>
</div>
<script>
(() => {
    const form = document.querySelector('form[action="{{ route('dashboard.screener') }}"]');
    const search = form?.querySelector('[name="search"]');
    const bucket = form?.querySelector('[name="bucket"]');
    const rows = [...document.querySelectorAll('tbody tr')];
    if (!form || rows.length === 0) return;
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const needle = (search?.value || '').trim().toUpperCase();
        const selected = bucket?.value || '';
        rows.forEach((row) => {
            const text = row.textContent.toUpperCase();
            const bucketText = row.children[1]?.textContent.trim() || '';
            row.hidden = (needle !== '' && !text.includes(needle)) || (selected !== '' && bucketText !== selected);
        });
    });
})();
</script>
@endsection
