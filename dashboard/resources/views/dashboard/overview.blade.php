@extends('layouts.app')

@section('page_title', 'Dashboard')

@section('content')
<div class="space-y-6 max-w-[1600px] mx-auto">

    <!-- 1. Header Status Pasar & Sesi Acuan Data -->
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-xl p-5 shadow-sm flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
            <div class="flex items-center space-x-2.5 mb-1">
                <span class="w-2.5 h-2.5 rounded-full {{ $isEodFinal ? 'bg-emerald-400' : 'bg-amber-400' }} animate-pulse"></span>
                <h1 class="text-lg font-bold text-[#F5F1E8] tracking-tight uppercase">Intelijen Pasar Ekuitas BEI</h1>
                <x-badge variant="accent">SESI RESMI EOD</x-badge>
            </div>
            <p class="text-xs text-[#F5F1E8]/60">
                Data acuan operasional: <span class="font-medium text-[#F5F1E8]">IDX Edge PRO (Board RG)</span>. Arsip historis: <span class="text-[#F5F1E8]/40">Cold Storage Yahoo Finance</span>.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5 text-xs">
            <div class="bg-[#0D2B4E] border border-[#F5F1E8]/10 px-3 py-1.5 rounded-lg">
                <span class="text-[10px] text-[#F5F1E8]/40 block uppercase font-mono">Sesi Acuan Terakhir</span>
                <span class="font-mono text-[#E5C49B] font-semibold">{{ $expectedBarDate ?? '2026-10-30' }}</span>
            </div>

            <div class="bg-[#0D2B4E] border border-[#F5F1E8]/10 px-3 py-1.5 rounded-lg">
                <span class="text-[10px] text-[#F5F1E8]/40 block uppercase font-mono">Status Pasar</span>
                <span class="font-semibold {{ $isEodFinal ? 'text-emerald-400' : 'text-amber-400' }}">
                    {{ $isEodFinal ? 'PASAR TUTUP (EOD FINAL)' : 'SESI BERJALAN' }}
                </span>
            </div>

            <div class="bg-[#0D2B4E] border border-[#F5F1E8]/10 px-3 py-1.5 rounded-lg">
                <span class="text-[10px] text-[#F5F1E8]/40 block uppercase font-mono">Rezim Pasar Acuan</span>
                <span class="font-bold text-[#E5C49B] font-mono">{{ $marketRegime ?? 'SIDEWAYS' }}</span>
            </div>
        </div>
    </div>

    <!-- 2. Ringkasan Pasar dengan Cakupan Eligible -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Benchmark IHSG -->
        <x-card class="space-y-2">
            <div class="flex items-center justify-between text-[#F5F1E8]/50 text-xs">
                <span>Indeks Acuan</span>
                <span class="font-mono text-[10px] text-[#E5C49B]">IHSG (^JKSE)</span>
            </div>
            <div class="flex items-baseline space-x-2">
                @if($ihsgObs)
                    <span class="text-2xl font-bold text-[#F5F1E8] font-mono tabular-nums">{{ number_format($ihsgObs->close_price, 2, ',', '.') }}</span>
                    <span class="text-xs font-mono {{ ($ihsgObs->change_pct ?? 0) >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                        {{ ($ihsgObs->change_pct ?? 0) >= 0 ? '+' : '' }}{{ number_format($ihsgObs->change_pct ?? 0, 2, ',', '.') }}%
                    </span>
                @else
                    <span class="text-lg font-bold text-[#F5F1E8]/40 font-mono">N/A</span>
                    <span class="text-xs text-[#F5F1E8]/30">Data belum tersedia</span>
                @endif
            </div>
            <p class="text-[11px] text-[#F5F1E8]/50">
                Benchmark pasar komposit sesi resmi BEI.
            </p>
        </x-card>

        <!-- Cakupan Pilot IDX Edge -->
        <x-card class="space-y-2">
            <div class="flex items-center justify-between text-[#F5F1E8]/50 text-xs">
                <span>Cakupan Pilot IDX</span>
                <x-badge variant="success">OPERASIONAL AKTIF</x-badge>
            </div>
            <div class="flex items-baseline space-x-2">
                <span class="text-2xl font-bold text-emerald-400 font-mono tabular-nums">10</span>
                <span class="text-xs text-[#F5F1E8]/60">saham pilot</span>
            </div>
            <p class="text-[11px] text-[#F5F1E8]/50">
                Saham pilot terverifikasi lengkap (histori, broker RG, fundamental).
            </p>
        </x-card>

        <!-- Semesta Registri Terdaftar -->
        <x-card class="space-y-2">
            <div class="flex items-center justify-between text-[#F5F1E8]/50 text-xs">
                <span>Semesta Registri</span>
                <x-badge variant="neutral">KANDIDAT BEI</x-badge>
            </div>
            <div class="flex items-baseline space-x-2">
                <span class="text-2xl font-bold text-[#F5F1E8] font-mono tabular-nums">{{ number_format($counts['secondary_candidates'] ?? 862, 0, ',', '.') }}</span>
                <span class="text-xs text-[#F5F1E8]/60">ekuitas unik</span>
            </div>
            <p class="text-[11px] text-[#F5F1E8]/50">
                Instrumen tercatat di direktori registri sekunder BEI.
            </p>
        </x-card>

        <!-- Watchlist Pengguna -->
        <x-card class="space-y-2">
            <div class="flex items-center justify-between text-[#F5F1E8]/50 text-xs">
                <span>Watchlist Dipantau</span>
                <x-badge variant="accent">PRIORITAS</x-badge>
            </div>
            <div class="flex items-baseline space-x-2">
                <span class="text-2xl font-bold text-[#E5C49B] font-mono tabular-nums">{{ number_format($counts['watchlist_count'] ?? 0, 0, ',', '.') }}</span>
                <span class="text-xs text-[#F5F1E8]/60">saham pantauan</span>
            </div>
            <p class="text-[11px] text-[#F5F1E8]/50">
                Instrumen dalam radar monitoring aktif pengguna.
            </p>
        </x-card>
    </div>

    <!-- 3. Jadwal Laporan & Status Aktual (PRD v3) -->
    <x-card title="Jadwal Laporan & Status Aktual" subtitle="Siklus briefing pasar terjadwal dan status radar intraday">
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @foreach($reportSlots as $slot)
                <div class="bg-[#0D2B4E]/60 border border-[#F5F1E8]/10 rounded-lg p-3 space-y-1.5">
                    <div class="flex items-center justify-between">
                        <span class="font-mono text-xs font-bold text-[#E5C49B]">{{ $slot['time'] }} WIB</span>
                        @if($slot['status'] === 'COMPLETED')
                            <x-badge variant="success">SELESAI</x-badge>
                        @elseif($slot['status'] === 'BLOCKED')
                            <x-badge variant="danger">BLOCKED</x-badge>
                        @elseif($slot['status'] === 'SCHEDULED')
                            <x-badge variant="info">TERJADWAL</x-badge>
                        @else
                            <x-badge variant="neutral">{{ $slot['status'] }}</x-badge>
                        @endif
                    </div>
                    <div class="text-xs font-semibold text-[#F5F1E8] truncate">{{ $slot['name'] }}</div>
                    <div class="text-[10px] text-[#F5F1E8]/45 leading-tight">{{ $slot['note'] }}</div>
                </div>
            @endforeach
        </div>
    </x-card>

    <!-- 4. Dua Kolom: Kandidat Terbaru & Tindak Lanjut Rekomendasi -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Kandidat Terbaru (Screening & Rekomendasi) -->
        <x-card title="Kandidat Screening & Analisis Terbaru" subtitle="Hasil seleksi strategi BROKER_ACCUMULATION_SCREENING_V1 & sinyal aktif">
            <x-slot:headerAction>
                <a href="{{ route('dashboard.market') }}" class="text-xs font-semibold text-[#E5C49B] hover:underline flex items-center">
                    Buka Market Monitor &rarr;
                </a>
            </x-slot:headerAction>

            @if(isset($latestCandidates) && $latestCandidates->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-[#F5F1E8]/10 text-[#F5F1E8]/40 uppercase font-mono text-[10px]">
                                <th class="pb-2.5">Ticker</th>
                                <th class="pb-2.5">Arah / Sinyal</th>
                                <th class="pb-2.5 text-right">Harga Acuan</th>
                                <th class="pb-2.5 text-right">Target</th>
                                <th class="pb-2.5 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#F5F1E8]/5">
                            @foreach($latestCandidates as $cand)
                                <tr class="hover:bg-[#0D2B4E]/40 transition">
                                    <td class="py-2.5 font-bold font-mono text-[#F5F1E8]">
                                        <a href="{{ route('dashboard.ticker', ['ticker' => $cand->ticker]) }}" class="hover:text-[#E5C49B]">
                                            {{ $cand->ticker }}
                                        </a>
                                    </td>
                                    <td class="py-2.5">
                                        <span class="font-semibold {{ $cand->direction === 'BULLISH' ? 'text-emerald-400' : ($cand->direction === 'BEARISH' ? 'text-rose-400' : 'text-[#F5F1E8]/60') }}">
                                            {{ $cand->direction ?? 'ABSTAIN' }}
                                        </span>
                                    </td>
                                    <td class="py-2.5 text-right font-mono tabular-nums text-[#F5F1E8]/80">
                                        Rp {{ number_format($cand->reference_price ?? 0, 0, ',', '.') }}
                                    </td>
                                    <td class="py-2.5 text-right font-mono tabular-nums text-[#E5C49B]">
                                        {{ $cand->target_price ? 'Rp ' . number_format($cand->target_price, 0, ',', '.') : 'N/A' }}
                                    </td>
                                    <td class="py-2.5 text-center font-mono text-[10px]">
                                        <x-badge variant="{{ $cand->signal_status === 'ACTIVE_SIGNAL' ? 'success' : 'neutral' }}">
                                            {{ $cand->signal_status }}
                                        </x-badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <x-empty-state
                    title="Belum Ada Kandidat Aktif"
                    message="Penyaringan kriteria BROKER_ACCUMULATION_SCREENING_V1 memerlukan kondisi pasar yang memenuhi batas konsentrasi & net flow."
                />
            @endif
        </x-card>

        <!-- Tindak Lanjut Rekomendasi (Monitoring Horizon) -->
        <x-card title="Tindak Lanjut & Siklus Hidup Rekomendasi" subtitle="Pemantauan masa berlaku (5 hari bursa horizon, maks 14 hari kalender)">
            <x-slot:headerAction>
                <a href="{{ route('dashboard.history') }}" class="text-xs font-semibold text-[#E5C49B] hover:underline flex items-center">
                    Rekap Riwayat 30 Hari &rarr;
                </a>
            </x-slot:headerAction>

            @if(isset($activePredictions) && $activePredictions->count() > 0)
                <div class="space-y-3">
                    @foreach($activePredictions as $pred)
                        <div class="bg-[#0D2B4E]/40 border border-[#F5F1E8]/10 rounded-lg p-3 flex items-center justify-between">
                            <div>
                                <div class="flex items-center space-x-2">
                                    <span class="font-mono font-bold text-sm text-[#F5F1E8]">{{ $pred->ticker }}</span>
                                    <x-badge variant="{{ $pred->direction === 'BULLISH' ? 'success' : 'neutral' }}">{{ $pred->direction }}</x-badge>
                                    <span class="text-[10px] text-[#F5F1E8]/40 font-mono">Target: {{ $pred->evaluation_due_date ? \Carbon\Carbon::parse($pred->evaluation_due_date)->format('d M') : 'N/A' }}</span>
                                </div>
                                <div class="text-xs text-[#F5F1E8]/60 mt-1">
                                    Acuan: Rp {{ number_format($pred->reference_price, 0, ',', '.') }} &bull; Target: Rp {{ number_format($pred->target_price, 0, ',', '.') }} &bull; Invalidation: Rp {{ number_format($pred->invalidation_price, 0, ',', '.') }}
                                </div>
                            </div>
                            <div class="text-right font-mono text-[11px]">
                                <span class="text-[#E5C49B] block">{{ $pred->monitoring_status }}</span>
                                <span class="text-[#F5F1E8]/35 text-[10px]">Horizon 5D</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <x-empty-state
                    title="Tidak Ada Rekomendasi Aktif Menunggu Evaluasi"
                    message="Seluruh rekod sinyal sebelumnya telah berstatus dievaluasi atau abstain. Sistem tidak mempublikasikan sinyal spekulatif tanpa konfirmasi kuantitatif."
                />
            @endif
        </x-card>
    </div>

    <!-- 5. Berita Penting & Sentimen Pasar Terkini -->
    <x-card title="Berita & Sentimen Pasar Terkini" subtitle="Pengamatan peristiwa makro, sektoral, dan keterbukaan informasi emiten">
        <x-slot:headerAction>
            <a href="{{ route('dashboard.news') }}" class="text-xs font-semibold text-[#E5C49B] hover:underline flex items-center">
                Lihat Seluruh Berita &rarr;
            </a>
        </x-slot:headerAction>

        @php
            $sampleNews = \App\Models\NewsItem::latest('published_at')->take(4)->get();
        @endphp

        @if($sampleNews->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach($sampleNews as $n)
                    <div class="bg-[#0D2B4E]/30 border border-[#F5F1E8]/10 rounded-lg p-3.5 space-y-1.5 hover:border-[#E5C49B]/30 transition">
                        <div class="flex items-center justify-between text-[10px] text-[#F5F1E8]/40 font-mono">
                            <span>{{ $n->source ?? 'BEI / Media Resmi' }}</span>
                            <span>{{ $n->published_at ? \Carbon\Carbon::parse($n->published_at)->diffForHumans() : 'Baru saja' }}</span>
                        </div>
                        <h4 class="text-xs font-semibold text-[#F5F1E8] line-clamp-2 leading-snug">
                            @if($n->url)
                                <a href="{{ $n->url }}" target="_blank" rel="noopener" class="hover:text-[#E5C49B]">
                                    {{ $n->title }}
                                </a>
                            @else
                                {{ $n->title }}
                            @endif
                        </h4>
                        @if($n->summary)
                            <p class="text-[11px] text-[#F5F1E8]/50 line-clamp-2 leading-relaxed">
                                {{ $n->summary }}
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <x-empty-state
                title="Belum Ada Berita Terbaru"
                message="Kolektor berita pasar belum merekam artikel dalam siklus EOD berjalan."
            />
        @endif
    </x-card>

</div>
@endsection
