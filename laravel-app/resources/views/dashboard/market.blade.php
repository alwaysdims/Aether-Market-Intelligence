@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white">Market Monitor — Saham Likuid BEI</h2>
            <p class="text-xs text-slate-400">Snapshot data harga resmi, volume, dan indikator teknikal dari Hermes Engine.</p>
        </div>
        <span class="text-xs text-sky-400 bg-sky-950/60 border border-sky-800/80 px-3 py-1.5 rounded-lg font-mono">
            Sumber: Yahoo Finance (IDX Daily EOD)
        </span>
    </div>

    <!-- Table Container -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden shadow">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-900/80 text-slate-400 font-semibold uppercase tracking-wider border-b border-slate-700">
                    <tr>
                        <th class="px-4 py-3">Ticker</th>
                        <th class="px-4 py-3">Tanggal Bar</th>
                        <th class="px-4 py-3">Harga Close</th>
                        <th class="px-4 py-3">Rentang (High - Low)</th>
                        <th class="px-4 py-3">Volume</th>
                        <th class="px-4 py-3">Klasifikasi Data</th>
                        <th class="px-4 py-3">Indikator Kunci</th>
                        <th class="px-4 py-3">Timestamp Sumber</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-700/60">
                    @forelse($snapshots as $snap)
                        <tr class="hover:bg-slate-700/40 transition">
                            <td class="px-4 py-3 font-bold text-white font-mono">{{ $snap->ticker }}</td>
                            <td class="px-4 py-3">{{ $snap->bar_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3 font-mono font-semibold text-sky-300">Rp{{ number_format($snap->close_price, 0, ',', '.') }}</td>
                            <td class="px-4 py-3 font-mono text-slate-400">
                                Rp{{ number_format($snap->high_price, 0, ',', '.') }} - Rp{{ number_format($snap->low_price, 0, ',', '.') }}
                            </td>
                            <td class="px-4 py-3 font-mono">{{ number_format($snap->volume) }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-700 text-slate-300">
                                    {{ strtoupper($snap->data_classification) }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                @if($snap->indicators)
                                    <div class="space-x-1 font-mono text-[10px]">
                                        <span class="text-slate-400">ATR: {{ $snap->indicators['atr14'] ?? '-' }}</span>
                                        <span class="text-slate-400">RVOL: {{ $snap->indicators['rvol20'] ?? '-' }}x</span>
                                    </div>
                                @else
                                    <span class="text-slate-500">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-400 text-[11px]">{{ $snap->source_timestamp->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center text-slate-500 text-sm">
                                Belum ada market snapshot tersimpan di database. Data akan terisi otomatis saat pipeline Hermes dijalankan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($snapshots->hasPages())
            <div class="p-4 border-t border-slate-700">
                {{ $snapshots->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
