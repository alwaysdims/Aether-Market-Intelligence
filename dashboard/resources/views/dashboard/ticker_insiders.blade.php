<div class="space-y-6">
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
        <div class="flex items-center justify-between border-b border-[#F5F1E8]/10 pb-3">
            <div>
                <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Transaksi Insider</h3>
                <p class="text-[11px] text-[#F5F1E8]/50">Tanggal, pihak, jenis transaksi, dan pagination provider. Nominal/unit tidak dihitung.</p>
            </div>
            <span class="text-[10px] font-mono text-[#F5F1E8]/50">Page {{ $insiders['data']['page'] ?? 'N/A' }} / {{ $insiders['data']['total_pages'] ?? 'N/A' }}</span>
        </div>
        @if($insiders['success'] ?? false)
            @php($insiderData = $insiders['data'])
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs font-mono">
                    <thead class="bg-[#071C35] text-[#F5F1E8]/50 uppercase text-[10px]"><tr><th class="p-2">Tanggal</th><th class="p-2">Nama/Pihak</th><th class="p-2">Jenis</th><th class="p-2">Posisi</th><th class="p-2">Nominal/Unit</th></tr></thead>
                    <tbody class="divide-y divide-[#F5F1E8]/5">
                    @forelse($insiderData['items'] as $item)
                        <tr><td class="p-2">{{ $item['date'] ?? 'N/A' }}</td><td class="p-2">{{ $item['name'] ?? 'N/A' }}</td><td class="p-2">{{ $item['action_type'] ?? 'N/A' }}</td><td class="p-2">{{ implode(', ', $item['badges'] ?? []) ?: 'N/A' }}</td><td class="p-2 text-[#F5F1E8]/50">{{ isset($item['current_value']) ? $item['current_value'] : 'N/A' }} / {{ $item['unit'] ?? 'NOT_DOCUMENTED' }}</td></tr>
                    @empty <tr><td colspan="5" class="p-4 text-center text-[#F5F1E8]/40">PROVIDER_EMPTY</td></tr> @endforelse
                    </tbody>
                </table>
            </div>
            <div class="flex items-center justify-between text-[10px] font-mono"><span>Total provider: {{ $insiderData['total'] ?? 'N/A' }}. Tampilan halaman: {{ $insiderData['count'] ?? 0 }}.</span><div class="flex gap-2">@if(($insiderData['page'] ?? 1) > 1)<a class="focus:outline-none focus:ring-1 focus:ring-[#E5C49B]" href="{{ route('dashboard.ticker', ['ticker' => $ticker, 'tab' => 'insiders', 'insider_page' => $insiderData['page'] - 1]) }}">Sebelumnya</a>@endif @if(($insiderData['page'] ?? 1) < ($insiderData['total_pages'] ?? 1))<a class="focus:outline-none focus:ring-1 focus:ring-[#E5C49B]" href="{{ route('dashboard.ticker', ['ticker' => $ticker, 'tab' => 'insiders', 'insider_page' => $insiderData['page'] + 1]) }}">Berikutnya</a>@endif</div></div>
        @else
            <p class="text-xs text-amber-300">{{ $insiders['error_type'] ?? 'DATA_NOT_AVAILABLE' }} — {{ $insiders['message'] ?? 'Insider belum tersedia.' }}</p>
        @endif
    </div>
</div>