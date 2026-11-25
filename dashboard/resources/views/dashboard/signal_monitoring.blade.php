@extends('layouts.app')

@section('page_title', 'Pemantauan Sinyal')

@section('content')
<div class="space-y-6">
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5">
        <h1 class="text-lg font-bold text-[#F5F1E8] uppercase">Pemantauan Sinyal</h1>
        <p class="text-xs text-[#F5F1E8]/60 mt-2">Pantau rencana, harga, target, expiry, dan bukti pemantauan. Tidak membuktikan order broker atau broker fill.</p>
    </div>

    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg overflow-x-auto">
        <table class="w-full text-left text-xs text-[#F5F1E8]">
            <thead class="bg-[#071C35] text-[#F5F1E8]/50 uppercase font-mono text-[10px]">
                <tr>
                    <th class="px-3 py-3">Signal ID</th>
                    <th class="px-3 py-3">Ticker</th>
                    <th class="px-3 py-3">Mode</th>
                    <th class="px-3 py-3">Entry</th>
                    <th class="px-3 py-3">Stop</th>
                    <th class="px-3 py-3">TP1 / TP2 / TP3</th>
                    <th class="px-3 py-3">Status</th>
                    <th class="px-3 py-3">Delivery</th>
                    <th class="px-3 py-3">Expiry</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#F5F1E8]/5 font-mono text-[11px]">
                @forelse($signals as $signal)
                    <tr>
                        <td class="px-3 py-3">{{ $signal->prediction_id }}</td>
                        <td class="px-3 py-3 font-bold text-[#E5C49B]">{{ $signal->ticker }}</td>
                        <td class="px-3 py-3">{{ $signal->metadata['mode'] ?? 'SHADOW' }}</td>
                        <td class="px-3 py-3">{{ $signal->reference_price !== null ? 'Rp '.number_format($signal->reference_price, 0, ',', '.') : 'N/A' }}</td>
                        <td class="px-3 py-3">{{ $signal->invalidation_price !== null ? 'Rp '.number_format($signal->invalidation_price, 0, ',', '.') : 'N/A' }}</td>
                        <td class="px-3 py-3">{{ $signal->target_price !== null ? 'Rp '.number_format($signal->target_price, 0, ',', '.') : 'N/A' }}</td>
                        <td class="px-3 py-3">{{ $signal->monitoring_status }} / {{ $signal->evaluation_status }}</td>
                        <td class="px-3 py-3">{{ $signal->metadata['delivery_status'] ?? 'N/A' }}{{ $signal->telegram_message_id ? ' #'.$signal->telegram_message_id : '' }}</td>
                        <td class="px-3 py-3">{{ optional($signal->expires_at)->format('Y-m-d H:i:s') ?? 'N/A' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-3 py-8 text-center text-[#F5F1E8]/60">NO_SIGNAL_RECORDED — belum ada signal directional eksperimen tersimpan.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
