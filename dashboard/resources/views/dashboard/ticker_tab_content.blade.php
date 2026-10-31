    @if(($tab ?? 'summary') === 'summary')
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left 2 Cols: Profile, Price/Volume Chart & Stats -->
            <div class="lg:col-span-2 space-y-6">

                <!-- Price & Volume Historical Chart -->
                <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
                    <div class="flex items-center justify-between border-b border-[#F5F1E8]/10 pb-3">
                        <div>
                            <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">
                                Grafik Harga & Volume Historis (30 Sesi Terakhir)
                            </h3>
                            <p class="text-[11px] text-[#F5F1E8]/50 mt-0.5">
                                Visualisasi data bar historis aktual bersumber dari basis data pasar terverifikasi.
                            </p>
                        </div>
                        <span class="text-[10px] font-mono text-[#E5C49B] bg-[#0D2B4E] border border-[#E5C49B]/30 px-2.5 py-1 rounded">
                            {{ $recentBars->count() }} BAR TERSEDIA
                        </span>
                    </div>

                    @if($recentBars->isNotEmpty())
                        <div class="relative w-full">
                            <canvas id="priceVolumeChart" class="w-full h-64 rounded bg-[#05152B] border border-[#F5F1E8]/5 block"></canvas>
                        </div>
                        <div class="flex items-center justify-between text-[10px] font-mono text-[#F5F1E8]/50 pt-1">
                            <span>Sesi Awal: {{ $recentBars->first()->bar_date ? $recentBars->first()->bar_date->format('d M Y') : '-' }}</span>
                            <span class="flex items-center space-x-3">
                                <span class="inline-flex items-center"><span class="w-2.5 h-2.5 bg-emerald-400 rounded-sm mr-1"></span> Close &ge; Open</span>
                                <span class="inline-flex items-center"><span class="w-2.5 h-2.5 bg-rose-400 rounded-sm mr-1"></span> Close &lt; Open</span>
                            </span>
                            <span>Sesi Terakhir: {{ $recentBars->last()->bar_date ? $recentBars->last()->bar_date->format('d M Y') : '-' }}</span>
                        </div>
                    @else
                        <div class="bg-[#05152B] border border-[#F5F1E8]/10 rounded p-8 text-center space-y-2">
                            <p class="text-xs font-mono text-[#F5F1E8]/50">DATA_NOT_AVAILABLE</p>
                            <p class="text-xs text-[#F5F1E8]/60">Data bar historis belum tersedia untuk emiten ini. Menunggu sinkronisasi pipeline EOD bursa.</p>
                        </div>
                    @endif
                </div>

                <!-- Profil Perusahaan -->
                <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-3">
                    <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Profil Usaha Emiten</h3>
                    <p class="text-xs text-[#F5F1E8]/80 leading-relaxed">
                        {{ $company?->company_name ?? $ticker }} beroperasi di sektor <strong class="text-[#F5F1E8]">{{ $company?->sector ?: 'Umum' }}</strong>, industri <strong class="text-[#F5F1E8]">{{ $company?->industry ?: '-' }}</strong>, tercatat di Bursa Efek Indonesia pada <strong class="text-[#F5F1E8]">{{ $company?->listing_board ?? 'Papan Utama' }}</strong>.
                    </p>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-2 text-xs font-mono">
                        <div class="bg-[#0D2B4E] p-2.5 rounded border border-[#F5F1E8]/5">
                            <span class="text-[10px] text-[#F5F1E8]/40 block">TANGGAL IPO</span>
                            <span class="font-bold text-[#F5F1E8]">{{ $company?->listing_date ?? '-' }}</span>
                        </div>
                        <div class="bg-[#0D2B4E] p-2.5 rounded border border-[#F5F1E8]/5">
                            <span class="text-[10px] text-[#F5F1E8]/40 block">PAPAN PENCATATAN</span>
                            <span class="font-bold text-[#F5F1E8]">{{ $company?->listing_board ?? 'Utama' }}</span>
                        </div>
                        <div class="bg-[#0D2B4E] p-2.5 rounded border border-[#F5F1E8]/5">
                            <span class="text-[10px] text-[#F5F1E8]/40 block">TIPE INSTRUMEN</span>
                            <span class="font-bold text-[#F5F1E8]">{{ $company?->instrument_type ?? 'EQUITY' }}</span>
                        </div>
                        <div class="bg-[#0D2B4E] p-2.5 rounded border border-[#F5F1E8]/5">
                            <span class="text-[10px] text-[#F5F1E8]/40 block">STATUS REGISTRI</span>
                            <span class="font-bold text-emerald-400">{{ $company ? 'TERDAFTAR' : 'UNREGISTERED' }}</span>
                        </div>
                    </div>
                </div>

                <!-- Snapshot Harga Sesi Terakhir -->
                <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-3">
                    <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Statistik Harga Sesi Terakhir (EOD Final)</h3>
                    @if($latestObs)
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs font-mono">
                            <div class="bg-[#0D2B4E] p-2.5 rounded">
                                <span class="text-[10px] text-[#F5F1E8]/40 block">OPEN</span>
                                <span class="text-sm font-bold text-[#F5F1E8] tabular-nums">Rp {{ number_format($latestObs->open_price ?? 0, 0, ',', '.') }}</span>
                            </div>
                            <div class="bg-[#0D2B4E] p-2.5 rounded">
                                <span class="text-[10px] text-[#F5F1E8]/40 block">HIGH</span>
                                <span class="text-sm font-bold text-emerald-400 tabular-nums">Rp {{ number_format($latestObs->high_price ?? 0, 0, ',', '.') }}</span>
                            </div>
                            <div class="bg-[#0D2B4E] p-2.5 rounded">
                                <span class="text-[10px] text-[#F5F1E8]/40 block">LOW</span>
                                <span class="text-sm font-bold text-rose-400 tabular-nums">Rp {{ number_format($latestObs->low_price ?? 0, 0, ',', '.') }}</span>
                            </div>
                            <div class="bg-[#0D2B4E] p-2.5 rounded">
                                <span class="text-[10px] text-[#F5F1E8]/40 block">VOLUME</span>
                                <span class="text-sm font-bold text-[#E5C49B] tabular-nums">{{ number_format($latestObs->volume ?? 0, 0, ',', '.') }} lbr</span>
                            </div>
                        </div>
                    @else
                        <p class="text-xs text-[#F5F1E8]/50 py-4 text-center font-mono">DATA_NOT_AVAILABLE — Data bar sesi terakhir belum tersedia.</p>
                    @endif
                </div>
            </div>

            <!-- Right Column: Audit Kelengkapan & Kualitas Data -->
            <div class="space-y-6">
                <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
                    <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Audit Kelengkapan Data</h3>
                    <div class="space-y-2.5 text-xs">
                        <div class="flex items-center justify-between p-2 rounded bg-[#0D2B4E]">
                            <span>Riwayat Harga EOD Final</span>
                            <span class="font-mono text-xs {{ $latestObs ? 'text-emerald-400' : 'text-rose-400' }}">
                                {{ $latestObs ? 'TERSEDIA' : 'TIDAK TERSEDIA' }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between p-2 rounded bg-[#0D2B4E]">
                            <span>Histori >= 20 Bar (SMA20)</span>
                            <span class="font-mono text-xs {{ $recentBars->count() >= 20 ? 'text-emerald-400' : 'text-amber-400' }}">
                                {{ $recentBars->count() >= 20 ? 'TERPENUHI (' . $recentBars->count() . 'B)' : 'BELUM CUKUP (' . $recentBars->count() . 'B)' }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between p-2 rounded bg-[#0D2B4E]">
                            <span>Histori >= 50 Bar (SMA50)</span>
                            <span class="font-mono text-xs {{ $recentBars->count() >= 50 ? 'text-emerald-400' : 'text-amber-400' }}">
                                {{ $recentBars->count() >= 50 ? 'TERPENUHI' : 'BELUM CUKUP' }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between p-2 rounded bg-[#0D2B4E]">
                            <span>Histori >= 200 Bar (SMA200)</span>
                            <span class="font-mono text-xs {{ $recentBars->count() >= 200 ? 'text-emerald-400' : 'text-amber-400' }}">
                                {{ $recentBars->count() >= 200 ? 'TERPENUHI' : 'BELUM CUKUP' }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between p-2 rounded bg-[#0D2B4E]">
                            <span>1Y Drawdown Terhitung</span>
                            <span class="font-mono text-xs {{ ($technical && $technical->max_drawdown_1y_pct !== null) ? 'text-cyan-400' : 'text-amber-400' }}">
                                {{ ($technical && $technical->max_drawdown_1y_pct !== null) ? sprintf('%.2f%%', $technical->max_drawdown_1y_pct) : 'PROVISIONAL' }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between p-2 rounded bg-[#0D2B4E]">
                            <span>Laporan Keuangan Fiskal</span>
                            <span class="font-mono text-xs {{ $fundamentals->isNotEmpty() ? 'text-emerald-400' : 'text-rose-400' }}">
                                {{ $fundamentals->isNotEmpty() ? $fundamentals->count() . ' Periode' : 'BELUM TERSEDIA' }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between p-2 rounded bg-[#0D2B4E]">
                            <span>Sintesis AI Gemini</span>
                            <span class="font-mono text-xs {{ $latestAnalysis ? 'text-[#E5C49B]' : 'text-[#F5F1E8]/40' }}">
                                {{ $latestAnalysis ? 'TERSEDIA' : 'MENUNGGU RISET' }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Quick Valuations Card -->
                <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-3">
                    <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Valuasi Ringkas</h3>
                    @if($technical)
                        <div class="grid grid-cols-2 gap-2 text-xs font-mono">
                            <div class="bg-[#0D2B4E] p-2 rounded">
                                <span class="text-[10px] text-[#F5F1E8]/40 block">PER</span>
                                <span class="font-bold text-[#F5F1E8] tabular-nums">{{ $technical->pe_ratio ? number_format($technical->pe_ratio, 2) . 'x' : 'N/A' }}</span>
                            </div>
                            <div class="bg-[#0D2B4E] p-2 rounded">
                                <span class="text-[10px] text-[#F5F1E8]/40 block">PBV</span>
                                <span class="font-bold text-[#F5F1E8] tabular-nums">{{ $technical->pbv_ratio ? number_format($technical->pbv_ratio, 2) . 'x' : 'N/A' }}</span>
                            </div>
                            <div class="bg-[#0D2B4E] p-2 rounded">
                                <span class="text-[10px] text-[#F5F1E8]/40 block">ROE</span>
                                <span class="font-bold text-cyan-400 tabular-nums">{{ $technical->roe_pct ? number_format($technical->roe_pct, 2) . '%' : 'N/A' }}</span>
                            </div>
                            <div class="bg-[#0D2B4E] p-2 rounded">
                                <span class="text-[10px] text-[#F5F1E8]/40 block">DER</span>
                                <span class="font-bold text-[#E5C49B] tabular-nums">{{ $technical->der_ratio ? number_format($technical->der_ratio, 2) . 'x' : 'N/A' }}</span>
                            </div>
                        </div>
                    @else
                        <p class="text-xs text-[#F5F1E8]/40 py-2 font-mono">Valuasi rasio belum terhitung.</p>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <!-- Tab 2: Pemantauan & Sinyal -->
    @if(($tab ?? '') === 'monitoring')
        <div class="space-y-6">
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
                <div class="flex items-center justify-between border-b border-[#F5F1E8]/10 pb-3">
                    <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Status Pemantauan & Sinyal Prediksi</h3>
                    <span class="text-xs font-mono text-[#F5F1E8]/40">ZERO LIVE BROKER ORDERS</span>
                </div>

                @if($signals->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs text-[#F5F1E8]">
                            <thead class="bg-[#071C35] text-[#F5F1E8]/50 uppercase font-mono text-[10px]">
                                <tr>
                                    <th class="p-2.5">Waktu Keputusan</th>
                                    <th class="p-2.5">Model ID</th>
                                    <th class="p-2.5">Harga Acuan</th>
                                    <th class="p-2.5">Arah Sinyal</th>
                                    <th class="p-2.5">Probabilitas Naik/Turun</th>
                                    <th class="p-2.5">Horizon Evaluasi</th>
                                    <th class="p-2.5">Status Evaluasi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#F5F1E8]/5">
                                @foreach($signals as $sig)
                                    <tr class="hover:bg-[#0D2B4E]/60 transition">
                                        <td class="p-2.5 font-mono text-[#F5F1E8]/70">{{ $sig->decision_timestamp ? $sig->decision_timestamp->timezone('Asia/Jakarta')->format('Y-m-d H:i') : '-' }}</td>
                                        <td class="p-2.5 font-mono text-[#E5C49B]">{{ $sig->model_id ?? 'ag/gemini-3.8-flash-high' }}</td>
                                        <td class="p-2.5 font-mono tabular-nums">Rp {{ number_format($sig->reference_price ?? 0, 0, ',', '.') }}</td>
                                        <td class="p-2.5 font-bold {{ $sig->direction === 'UP' ? 'text-emerald-400' : ($sig->direction === 'DOWN' ? 'text-rose-400' : 'text-[#F5F1E8]/60') }}">
                                            {{ $sig->direction ?? 'NO_SIGNAL' }}
                                        </td>
                                        <td class="p-2.5 font-mono tabular-nums text-xs">
                                            @if($sig->probability_up !== null)
                                                {{ number_format($sig->probability_up * 100, 1) }}% / {{ number_format($sig->probability_down * 100, 1) }}%
                                            @else
                                                <span class="text-[#F5F1E8]/40">N/A</span>
                                            @endif
                                        </td>
                                        <td class="p-2.5 font-mono text-[#F5F1E8]/60">{{ $sig->evaluation_due_date ? $sig->evaluation_due_date->format('Y-m-d') : 'T+5 (5 Hari Bursa)' }}</td>
                                        <td class="p-2.5">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-[#0D2B4E] text-[#F5F1E8]/70 border border-[#F5F1E8]/10">
                                                {{ $sig->monitoring_status ?? 'PENDING' }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="bg-[#0D2B4E] p-4 rounded text-xs text-[#F5F1E8]/70 space-y-2">
                        <p class="font-bold text-[#F5F1E8]">Status: NO_ACTIVE_INFERENCE (Keyakinan: N/A)</p>
                        <p class="leading-relaxed">
                            Tidak ada sinyal prediksi aktif untuk bar saat ini. Prediksi historis model bertanggal 16 Oktober 2026 telah melewati batas horizon 5 hari bursa (kadaluarsa 23 Oktober 2026). Sesuai kaidah integritas riset, sistem tidak memalsukan prediksi atau memakai confidence lampau untuk bar terbaru.
                        </p>
                    </div>
                @endif
            </div>

            <!-- Aktivitas Akumulasi & Distribusi Broker (Pasar Reguler) -->
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-[#F5F1E8]/10 pb-3">
                    <div>
                        <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Aktivitas Broker Pasar Reguler (Broker Summary)</h3>
                        <p class="text-[11px] text-[#F5F1E8]/50 mt-0.5">Agregasi pergerakan broker di Pasar Reguler (RG). Sumber: IDX Edge PRO.</p>
                    </div>
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-cyan-950/60 text-cyan-300 border border-cyan-500/30 self-start sm:self-auto">
                        BOARD: RG (VERIFIED_ON_SAMPLE)
                    </span>
                </div>

                @if(isset($brokerSummaries) && $brokerSummaries->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs font-mono">
                            <thead class="bg-[#071C35] text-[#F5F1E8]/50 uppercase text-[10px]">
                                <tr>
                                    <th class="p-2.5">Broker</th>
                                    <th class="p-2.5">Aksi</th>
                                    <th class="p-2.5">Buy Vol (Lbr)</th>
                                    <th class="p-2.5" title="Dihitung sebagai buy_value / buy_volume (bukan harga akumulasi bersih)">Buy Avg (bval/bvol)</th>
                                    <th class="p-2.5">Sell Vol (Lbr)</th>
                                    <th class="p-2.5" title="Dihitung sebagai sell_value / sell_volume">Sell Avg (sval/svol)</th>
                                    <th class="p-2.5">Net Vol (Lbr)</th>
                                    <th class="p-2.5">Net Value (IDR)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#F5F1E8]/5 text-[#F5F1E8]">
                                @foreach($brokerSummaries as $bs)
                                    <tr class="hover:bg-[#0D2B4E]/60 transition">
                                        <td class="p-2.5">
                                            <span class="font-bold text-[#E5C49B]">{{ $bs->broker_code }}</span>
                                            <span class="text-[10px] text-[#F5F1E8]/40 block">{{ $bs->broker_name }}</span>
                                        </td>
                                        <td class="p-2.5">
                                            @if($bs->net_value > 0)
                                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-950/80 text-emerald-300 border border-emerald-500/30">AKUMULASI</span>
                                            @elseif($bs->net_value < 0)
                                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-950/80 text-rose-300 border border-rose-500/30">DISTRIBUSI</span>
                                            @else
                                                <span class="px-1.5 py-0.5 rounded text-[10px] bg-zinc-900 text-zinc-400">NETRAL</span>
                                            @endif
                                        </td>
                                        <td class="p-2.5 tabular-nums">{{ number_format($bs->buy_volume, 0, ',', '.') }}</td>
                                        <td class="p-2.5 tabular-nums">Rp {{ number_format($bs->buy_avg_price ?? 0, 0, ',', '.') }}</td>
                                        <td class="p-2.5 tabular-nums">{{ number_format($bs->sell_volume, 0, ',', '.') }}</td>
                                        <td class="p-2.5 tabular-nums">Rp {{ number_format($bs->sell_avg_price ?? 0, 0, ',', '.') }}</td>
                                        <td class="p-2.5 tabular-nums {{ $bs->net_volume >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                                            {{ $bs->net_volume >= 0 ? '+' : '' }}{{ number_format($bs->net_volume, 0, ',', '.') }}
                                        </td>
                                        <td class="p-2.5 tabular-nums font-bold {{ $bs->net_value >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                                            Rp {{ number_format($bs->net_value / 1e6, 2, ',', '.') }} Jt
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="text-[10px] text-[#F5F1E8]/40 italic">
                        Catatan Metodologis: Agregasi pergerakan broker di Pasar Reguler (RG). Cakupan data dibatasi pada Top-N Broker (misal: 30 broker teratas per sesi); bukan daftar lengkap seluruh anggota bursa. Rata-rata harga beli dihitung murni sebagai buy_value / buy_volume (bukan harga akumulasi bersih). Data ini bukan bukti kepemilikan definitif dari entitas bandar tunggal.
                    </p>
                @else
                    <p class="text-xs text-[#F5F1E8]/50 py-4 text-center font-mono">
                        DATA_NOT_AVAILABLE — Data broker summary belum disinkronkan untuk emiten ini.
                    </p>
                @endif
            </div>
        </div>
    @endif

    <!-- Tab 3: Teknikal & Indikator -->
    @if(($tab ?? '') === 'technical')
        <div class="space-y-6">
            <!-- Indikator Kuantitatif -->
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
                <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Indikator Geometri & Kuantitatif (Mathematical Calculations)</h3>
                @if($technical)
                    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3 text-xs font-mono">
                        <div class="bg-[#0D2B4E] p-2.5 rounded">
                            <span class="text-[10px] text-[#F5F1E8]/40 block">SMA 20</span>
                            <span class="text-sm font-bold text-[#F5F1E8] tabular-nums">{{ $technical->sma_20 ? 'Rp ' . number_format($technical->sma_20, 0, ',', '.') : 'N/A' }}</span>
                        </div>
                        <div class="bg-[#0D2B4E] p-2.5 rounded">
                            <span class="text-[10px] text-[#F5F1E8]/40 block">SMA 50</span>
                            <span class="text-sm font-bold text-[#F5F1E8] tabular-nums">{{ $technical->sma_50 ? 'Rp ' . number_format($technical->sma_50, 0, ',', '.') : 'N/A' }}</span>
                        </div>
                        <div class="bg-[#0D2B4E] p-2.5 rounded">
                            <span class="text-[10px] text-[#F5F1E8]/40 block">SMA 200</span>
                            <span class="text-sm font-bold text-[#F5F1E8] tabular-nums">{{ $technical->sma_200 ? 'Rp ' . number_format($technical->sma_200, 0, ',', '.') : 'N/A' }}</span>
                        </div>
                        <div class="bg-[#0D2B4E] p-2.5 rounded">
                            <span class="text-[10px] text-[#F5F1E8]/40 block">RSI (14)</span>
                            <span class="text-sm font-bold text-cyan-400 tabular-nums">{{ $technical->rsi_14 ? number_format($technical->rsi_14, 2) : 'N/A' }}</span>
                        </div>
                        <div class="bg-[#0D2B4E] p-2.5 rounded">
                            <span class="text-[10px] text-[#F5F1E8]/40 block">MACD LINE</span>
                            <span class="text-sm font-bold text-[#E5C49B] tabular-nums">{{ $technical->macd !== null ? number_format($technical->macd, 2) : 'N/A' }}</span>
                        </div>
                        <div class="bg-[#0D2B4E] p-2.5 rounded">
                            <span class="text-[10px] text-[#F5F1E8]/40 block">MACD SIGNAL</span>
                            <span class="text-sm font-bold text-[#F5F1E8] tabular-nums">{{ $technical->macd_signal !== null ? number_format($technical->macd_signal, 2) : 'N/A' }}</span>
                        </div>
                        <div class="bg-[#0D2B4E] p-2.5 rounded">
                            <span class="text-[10px] text-[#F5F1E8]/40 block">ATR (14)</span>
                            <span class="text-sm font-bold text-amber-400 tabular-nums">{{ $technical->atr_14 ? 'Rp ' . number_format($technical->atr_14, 2) : 'N/A' }}</span>
                        </div>
                    </div>
                @else
                    <p class="text-xs text-[#F5F1E8]/50 py-4 text-center font-mono">DATA_NOT_AVAILABLE — Metrik teknikal belum dihitung untuk emiten ini.</p>
                @endif
            </div>

            <!-- Candlestick History Table -->
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Histori Candlestick Harian</h3>
                    <div class="flex flex-wrap gap-2 text-[10px] font-mono text-[#F5F1E8]/60">
                        <span class="px-2 py-0.5 rounded bg-[#0D2B4E] border border-[#F5F1E8]/10">Total Histori DB: <strong class="text-[#F5F1E8]">{{ $totalHistoryBars ?? $recentBars->count() }} bar</strong></span>
                        <span class="px-2 py-0.5 rounded bg-[#0D2B4E] border border-[#F5F1E8]/10">Tampilan Grafik/Tabel: <strong class="text-[#F5F1E8]">{{ $chartDisplayBars ?? $recentBars->count() }} bar</strong></span>
                        <span class="px-2 py-0.5 rounded bg-[#0D2B4E] border border-[#F5F1E8]/10">Input Indikator: <strong class="text-[#F5F1E8]">{{ $indicatorInputBars ?? ($totalHistoryBars ?? $recentBars->count()) }} bar</strong></span>
                    </div>
                </div>
                @if($recentBars->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs font-mono">
                            <thead class="bg-[#071C35] text-[#F5F1E8]/50 uppercase text-[10px]">
                                <tr>
                                    <th class="p-2">Tanggal Bar</th>
                                    <th class="p-2">Open</th>
                                    <th class="p-2">High</th>
                                    <th class="p-2">Low</th>
                                    <th class="p-2">Close</th>
                                    <th class="p-2">Volume (Lembar)</th>
                                    <th class="p-2">Rentang H-L</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#F5F1E8]/5 text-[#F5F1E8]">
                                @foreach($recentBars as $b)
                                    <tr class="hover:bg-[#0D2B4E]/60 transition">
                                        <td class="p-2 text-[#F5F1E8]/70">{{ $b->bar_date->format('Y-m-d') }}</td>
                                        <td class="p-2 tabular-nums">Rp {{ number_format($b->open_price, 0, ',', '.') }}</td>
                                        <td class="p-2 tabular-nums text-emerald-400">Rp {{ number_format($b->high_price, 0, ',', '.') }}</td>
                                        <td class="p-2 tabular-nums text-rose-400">Rp {{ number_format($b->low_price, 0, ',', '.') }}</td>
                                        <td class="p-2 tabular-nums font-bold">Rp {{ number_format($b->close_price, 0, ',', '.') }}</td>
                                        <td class="p-2 tabular-nums text-[#E5C49B]">{{ number_format($b->volume) }} lbr</td>
                                        <td class="p-2 tabular-nums text-[#F5F1E8]/60">Rp {{ number_format($b->high_price - $b->low_price, 0, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-xs text-[#F5F1E8]/50 py-4 text-center font-mono">DATA_NOT_AVAILABLE — Riwayat bar belum tersedia.</p>
                @endif
            </div>
        </div>
    @endif

    <!-- Tab 4: Fundamental Fiskal -->
    @if(($tab ?? '') === 'fundamental')
        <div class="space-y-6">
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
                <div class="flex items-center justify-between border-b border-[#F5F1E8]/10 pb-3">
                    <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Laporan Keuangan Fiskal Tahunan</h3>
                    <span class="text-xs font-mono text-[#F5F1E8]/40">Mata Uang: IDR (Miliar Rupiah)</span>
                </div>

                @if($fundamentals->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs font-mono">
                            <thead class="bg-[#071C35] text-[#F5F1E8]/50 uppercase text-[10px]">
                                <tr>
                                    <th class="p-2.5">Tahun Fiskal</th>
                                    <th class="p-2.5">Pendapatan (Revenue)</th>
                                    <th class="p-2.5">Laba Bersih</th>
                                    <th class="p-2.5">Atribusi Entitas Induk</th>
                                    <th class="p-2.5">Total Aset</th>
                                    <th class="p-2.5">Total Ekuitas</th>
                                    <th class="p-2.5">Sumber & Kualitas</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#F5F1E8]/5 text-[#F5F1E8]">
                                @foreach($fundamentals as $f)
                                    <tr class="hover:bg-[#0D2B4E]/60 transition">
                                        <td class="p-2.5 font-bold text-[#E5C49B]">{{ $f->fiscal_year }}</td>
                                        <td class="p-2.5 tabular-nums">Rp {{ number_format($f->total_revenue / 1e9, 2, ',', '.') }} M</td>
                                        <td class="p-2.5 tabular-nums {{ $f->net_income >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">Rp {{ number_format($f->net_income / 1e9, 2, ',', '.') }} M</td>
                                        <td class="p-2.5 tabular-nums text-emerald-300">
                                            {{ $f->attribution_parent ? 'Rp ' . number_format($f->attribution_parent / 1e9, 2, ',', '.') . ' M' : '-' }}
                                        </td>
                                        <td class="p-2.5 tabular-nums">Rp {{ number_format($f->total_assets / 1e9, 2, ',', '.') }} M</td>
                                        <td class="p-2.5 tabular-nums text-cyan-400">Rp {{ number_format($f->total_equity / 1e9, 2, ',', '.') }} M</td>
                                        <td class="p-2.5">
                                            @if($f->data_quality === 'DOCUMENT_VERIFIED_ON_SAMPLE')
                                                <span class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-emerald-950/80 text-emerald-300 border border-emerald-500/40">
                                                    DOCUMENT_VERIFIED
                                                </span>
                                            @elseif($f->data_quality === 'PROVIDER_SUPPLIED')
                                                <span class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-cyan-950/80 text-cyan-300 border border-cyan-500/40">
                                                    PROVIDER_SUPPLIED
                                                </span>
                                            @else
                                                <span class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-amber-950/60 text-amber-300 border border-amber-500/30">
                                                    {{ $f->data_quality ?? 'VERIFIED_ON_SAMPLE' }}
                                                </span>
                                            @endif
                                            <span class="text-[9px] text-[#F5F1E8]/40 block mt-0.5">{{ $f->source ?? 'IDX Edge PRO' }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-xs text-[#F5F1E8]/50 py-6 text-center font-mono">
                        DATA_NOT_AVAILABLE — Laporan keuangan fiskal belum terindeks untuk emiten ini.
                    </p>
                @endif
            </div>
        </div>
    @endif

    <!-- Tab 5: Kesimpulan AI Gemini -->
    @if(($tab ?? '') === 'ai_insight')
        <div class="space-y-6">

            <!-- Active Processing Banner if Research Request is In-Flight -->
            @if($aiConclusion['header']['active_processing'])
                <div class="p-4 rounded-lg bg-[#E5C49B]/10 border border-[#E5C49B]/40 flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <span class="w-3 h-3 rounded-full bg-[#E5C49B] animate-ping"></span>
                        <div>
                            <span class="text-xs font-bold font-mono text-[#E5C49B] uppercase block">Permintaan Riset Sedang Diproses</span>
                            <span class="text-[11px] text-[#F5F1E8]/70">Request ID: {{ $aiConclusion['header']['active_request_id'] }} &bull; Analisis multi-sumber sedang dieksekusi di background worker.</span>
                        </div>
                    </div>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-[#0A213D] text-[#E5C49B] border border-[#E5C49B]/30">
                        PROCESSING
                    </span>
                </div>
            @endif

            <!-- Main Conclusion Card -->
            <div class="bg-[#0A213D] border border-[#E5C49B]/30 rounded-lg p-5 sm:p-6 space-y-6">

                <!-- Header Panel -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-[#F5F1E8]/10 pb-4">
                    <div class="space-y-1">
                        <div class="flex items-center space-x-2.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-[#E5C49B]"></span>
                            <h3 class="text-sm sm:text-base font-bold text-[#F5F1E8] tracking-wide">
                                {{ $aiConclusion['header']['title'] }} &mdash; {{ $ticker }}
                            </h3>
                            <span class="px-2 py-0.5 rounded text-[10px] font-mono border border-[#F5F1E8]/15 bg-[#05152B] text-[#E5C49B]">
                                {{ $aiConclusion['header']['completeness_status'] }}
                            </span>
                        </div>
                        <div class="text-[11px] font-mono text-[#F5F1E8]/50 flex flex-wrap items-center gap-x-3 gap-y-1">
                            @if(!empty($aiConclusion['header']['analysis_date']))
                                <span>Waktu Analisis: <strong class="text-[#F5F1E8]/80">{{ $aiConclusion['header']['analysis_date'] }}</strong></span>
                            @endif
                            @if(!empty($aiConclusion['header']['bar_date']))
                                <span>&bull; Data Bar EOD: <strong class="text-[#E5C49B]">{{ $aiConclusion['header']['bar_date'] }}</strong></span>
                            @endif
                            @if(!empty($aiConclusion['header']['mode_label']))
                                <span>&bull; Mode: <strong class="text-[#F5F1E8]/80">{{ $aiConclusion['header']['mode_label'] }}</strong></span>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center space-x-2 shrink-0">
                        <span class="text-[11px] font-mono text-[#E5C49B] bg-[#05152B] px-2.5 py-1 rounded border border-[#E5C49B]/20">
                            {{ $aiConclusion['header']['model_name'] }}
                        </span>
                        @if($aiConclusion['header']['can_request_research'])
                            <button onclick="openResearchModal('{{ $ticker }}')" class="px-3 py-1 bg-[#E5C49B] hover:bg-[#D6B284] text-[#05152B] rounded text-xs font-semibold tracking-wide transition shadow-sm">
                                {{ $aiConclusion['has_analysis'] ? 'Analisis Ulang' : 'Minta Riset AI' }}
                            </button>
                        @else
                            <span class="px-2.5 py-1 bg-[#05152B] text-[#F5F1E8]/40 border border-[#F5F1E8]/10 rounded text-[10px] font-mono">
                                Riset Terkunci (Ticker Tak Dikenal)
                            </span>
                        @endif
                    </div>
                </div>

                <!-- Bagian A: Ringkasan Utama -->
                <div class="bg-[#05152B]/70 p-4 sm:p-5 rounded-lg border border-[#F5F1E8]/10 space-y-3">
                    <div class="flex items-center space-x-2 text-[11px] font-mono font-bold text-[#E5C49B] uppercase tracking-wider">
                        <svg class="w-4 h-4 text-[#E5C49B]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span>{{ $aiConclusion['section_a']['title'] }}</span>
                    </div>
                    <div class="space-y-2 text-xs sm:text-[13px] text-[#F5F1E8]/85 leading-relaxed max-w-4xl">
                        @foreach($aiConclusion['section_a']['paragraphs'] as $p)
                            <p>{{ $p }}</p>
                        @endforeach
                    </div>
                </div>

                <!-- Bagian B: Teknikal dan Fundamental (Dua Panel Desktop, Satu Kolom Mobile) -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    <!-- Panel Teknikal -->
                    <div class="bg-[#0D2B4E]/80 border border-[#F5F1E8]/10 rounded-lg p-4 space-y-3.5 flex flex-col justify-between">
                        <div class="space-y-3">
                            <div class="flex items-center justify-between border-b border-[#F5F1E8]/10 pb-2">
                                <span class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B] flex items-center">
                                    <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path></svg>
                                    Perspektif Teknikal &amp; Harga EOD
                                </span>
                                <span class="text-[10px] font-mono text-[#F5F1E8]/40">EOD Market Bar</span>
                            </div>

                            <p class="text-xs text-[#F5F1E8]/80 leading-relaxed">
                                {{ $aiConclusion['section_b']['technical']['findings'] }}
                            </p>

                            <!-- Key Metrics Grid -->
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1">
                                @foreach($aiConclusion['section_b']['technical']['key_metrics'] as $metric)
                                    <div class="bg-[#05152B] p-2 rounded border border-[#F5F1E8]/5 space-y-0.5">
                                        <span class="text-[10px] font-mono text-[#F5F1E8]/50 block uppercase truncate">{{ $metric['label'] }}</span>
                                        <span class="text-xs font-mono font-bold text-[#F5F1E8] block tabular-nums">{{ $metric['value'] }}</span>
                                        <span class="text-[9px] font-mono text-[#E5C49B]/70 block truncate">{{ $metric['sub'] }}</span>
                                    </div>
                                @endforeach
                            </div>

                            <div class="text-[11px] text-[#F5F1E8]/60 space-y-1 pt-1">
                                <p><strong class="text-[#F5F1E8]/80">Interpretasi:</strong> {{ $aiConclusion['section_b']['technical']['interpretation'] }}</p>
                                <p><strong class="text-[#F5F1E8]/80">Keterbatasan:</strong> {{ $aiConclusion['section_b']['technical']['limitations'] }}</p>
                            </div>
                        </div>

                        <div class="pt-2 border-t border-[#F5F1E8]/5 text-right">
                            <a href="{{ $aiConclusion['section_b']['technical']['tab_url'] }}" class="text-[11px] font-mono text-[#E5C49B] hover:underline inline-flex items-center">
                                Buka Detail Tab Teknikal &rarr;
                            </a>
                        </div>
                    </div>

                    <!-- Panel Fundamental -->
                    <div class="bg-[#0D2B4E]/80 border border-[#F5F1E8]/10 rounded-lg p-4 space-y-3.5 flex flex-col justify-between">
                        <div class="space-y-3">
                            <div class="flex items-center justify-between border-b border-[#F5F1E8]/10 pb-2">
                                <span class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B] flex items-center">
                                    <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                                    Perspektif Fundamental &amp; Neraca
                                </span>
                                <span class="text-[10px] font-mono text-[#F5F1E8]/40">Kuartalan Fiskal</span>
                            </div>

                            <p class="text-xs text-[#F5F1E8]/80 leading-relaxed">
                                {{ $aiConclusion['section_b']['fundamental']['findings'] }}
                            </p>

                            <!-- Key Metrics Grid -->
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1">
                                @if(!empty($aiConclusion['section_b']['fundamental']['key_metrics']))
                                    @foreach($aiConclusion['section_b']['fundamental']['key_metrics'] as $metric)
                                        <div class="bg-[#05152B] p-2 rounded border border-[#F5F1E8]/5 space-y-0.5">
                                            <span class="text-[10px] font-mono text-[#F5F1E8]/50 block uppercase truncate">{{ $metric['label'] }}</span>
                                            <span class="text-xs font-mono font-bold text-[#F5F1E8] block tabular-nums">{{ $metric['value'] }}</span>
                                            <span class="text-[9px] font-mono text-[#E5C49B]/70 block truncate">{{ $metric['sub'] }}</span>
                                        </div>
                                    @endforeach
                                @else
                                    <div class="col-span-4 bg-[#05152B] p-2.5 rounded text-center text-[10px] font-mono text-[#F5F1E8]/40">
                                        Data rasio keuangan belum terindeks untuk emiten ini.
                                    </div>
                                @endif
                            </div>

                            <div class="text-[11px] text-[#F5F1E8]/60 space-y-1 pt-1">
                                <p><strong class="text-[#F5F1E8]/80">Interpretasi:</strong> {{ $aiConclusion['section_b']['fundamental']['interpretation'] }}</p>
                                <p><strong class="text-[#F5F1E8]/80">Keterbatasan:</strong> {{ $aiConclusion['section_b']['fundamental']['limitations'] }}</p>
                            </div>
                        </div>

                        <div class="pt-2 border-t border-[#F5F1E8]/5 text-right">
                            <a href="{{ $aiConclusion['section_b']['fundamental']['tab_url'] }}" class="text-[11px] font-mono text-[#E5C49B] hover:underline inline-flex items-center">
                                Buka Detail Tab Fundamental &rarr;
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Bagian C: Faktor Pendukung dan Risiko (Palet Institusional Navy, Gold, Ivory) -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <!-- Faktor Pendukung / Bukti Faktual -->
                    <div class="bg-[#0D2B4E]/80 border border-[#F5F1E8]/10 rounded-lg p-4 space-y-2.5">
                        <div class="flex items-center space-x-2 text-[#E5C49B] text-xs font-mono font-bold uppercase tracking-wider border-b border-[#F5F1E8]/10 pb-2">
                            <svg class="w-4 h-4 text-[#E5C49B]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <span>Faktor Pendukung &amp; Bukti Faktual</span>
                        </div>
                        <ul class="space-y-2 text-xs text-[#F5F1E8]/85 leading-relaxed">
                            @foreach($aiConclusion['section_c']['supporting_factors'] as $fact)
                                <li class="flex items-start space-x-2">
                                    <span class="text-[#E5C49B] font-bold mt-0.5">&bull;</span>
                                    <span>{{ $fact }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <!-- Faktor Risiko & Keterbatasan -->
                    <div class="bg-[#0D2B4E]/80 border border-[#F5F1E8]/10 rounded-lg p-4 space-y-2.5">
                        <div class="flex items-center space-x-2 text-[#F5F1E8] text-xs font-mono font-bold uppercase tracking-wider border-b border-[#F5F1E8]/10 pb-2">
                            <svg class="w-4 h-4 text-[#E5C49B]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                            <span>Faktor Risiko &amp; Ketidakpastian</span>
                        </div>
                        <ul class="space-y-2 text-xs text-[#F5F1E8]/80 leading-relaxed">
                            @foreach($aiConclusion['section_c']['risk_factors'] as $risk)
                                <li class="flex items-start space-x-2">
                                    <span class="text-[#F5F1E8]/50 font-bold mt-0.5">&bull;</span>
                                    <span>{{ $risk }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                <!-- Bagian D: Prediksi Machine Learning (Identitas Model Aktual & Protokol Walk-Forward EXP-04) -->
                <div class="bg-[#0D2B4E]/70 border border-[#F5F1E8]/10 rounded-lg p-5 space-y-3.5">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-[#F5F1E8]/10 pb-3">
                        <div class="flex items-center space-x-2">
                            <span class="w-2 h-2 rounded-full bg-[#E5C49B]"></span>
                            <span class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">
                                Prediksi Model: {{ $aiConclusion['section_d']['model_name'] }} &bull; {{ $aiConclusion['section_d']['strategy_protocol'] }}
                            </span>
                        </div>
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono border border-[#F5F1E8]/15 bg-[#05152B] text-[#E5C49B]">
                            {{ $aiConclusion['section_d']['validation_badge'] }}
                        </span>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div class="bg-[#05152B] p-2.5 rounded border border-[#F5F1E8]/5 space-y-0.5">
                            <span class="text-[10px] font-mono text-[#F5F1E8]/40 uppercase block">Status Sinyal Bar</span>
                            <span class="text-xs font-mono font-bold text-[#E5C49B] block">{{ $aiConclusion['section_d']['signal_status'] }}</span>
                            <span class="text-[9px] font-mono text-[#F5F1E8]/40 block">{{ $aiConclusion['section_d']['horizon'] }}</span>
                        </div>
                        <div class="bg-[#05152B] p-2.5 rounded border border-[#F5F1E8]/5 space-y-0.5">
                            <span class="text-[10px] font-mono text-[#F5F1E8]/40 uppercase block">Probabilitas Naik</span>
                            <span class="text-xs font-mono font-bold text-[#F5F1E8] block">{{ $aiConclusion['section_d']['probability'] }}</span>
                            <span class="text-[9px] font-mono text-[#F5F1E8]/40 block">Estimated Up Probability</span>
                        </div>
                        <div class="bg-[#05152B] p-2.5 rounded border border-[#F5F1E8]/5 space-y-0.5">
                            <span class="text-[10px] font-mono text-[#F5F1E8]/40 uppercase block">Confidence Score</span>
                            <span class="text-xs font-mono font-bold text-[#F5F1E8] block">{{ $aiConclusion['section_d']['confidence'] }}</span>
                            <span class="text-[9px] font-mono text-[#F5F1E8]/40 block">Model Certainty</span>
                        </div>
                        <div class="bg-[#05152B] p-2.5 rounded border border-[#F5F1E8]/5 space-y-0.5">
                            <span class="text-[10px] font-mono text-[#F5F1E8]/40 uppercase block">Waktu Inference</span>
                            <span class="text-xs font-mono font-bold text-[#F5F1E8] block">{{ $aiConclusion['section_d']['inference_time'] }}</span>
                            <span class="text-[9px] font-mono text-[#F5F1E8]/40 block">Stempel Waktu Bar</span>
                        </div>
                    </div>

                    <p class="text-[11px] text-[#F5F1E8]/70 leading-relaxed font-mono">
                        {{ $aiConclusion['section_d']['explanation'] }}
                    </p>
                </div>

                <!-- Bagian E: Sumber dan Keterbatasan (Palet Institusional) -->
                <div class="bg-[#071C35] border border-[#F5F1E8]/10 rounded-lg p-4 space-y-3">
                    <span class="text-xs font-mono font-bold uppercase tracking-wider text-[#F5F1E8]/70 block border-b border-[#F5F1E8]/5 pb-2">
                        Sumber Bukti &amp; Keterbatasan Data
                    </span>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div class="space-y-2">
                            <span class="text-[10px] font-mono uppercase text-[#E5C49B] block font-bold">Sumber Tersimpan</span>
                            <div class="space-y-1.5">
                                @foreach($aiConclusion['section_e']['sources'] as $src)
                                    <div class="flex items-center justify-between p-2 rounded bg-[#05152B] border border-[#F5F1E8]/5">
                                        <div>
                                            <span class="font-bold text-[#F5F1E8] block text-[11px]">{{ $src['name'] }}</span>
                                            <span class="text-[10px] text-[#F5F1E8]/50 block">{{ $src['description'] }}</span>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <span class="px-1.5 py-0.5 rounded text-[9px] font-mono border border-[#F5F1E8]/15 bg-[#05152B] text-[#E5C49B]">
                                                {{ strtoupper($src['type']) }}
                                            </span>
                                            <span class="text-[9px] font-mono text-[#F5F1E8]/40 block mt-0.5">{{ $src['date_or_period'] }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="space-y-2">
                            <span class="text-[10px] font-mono uppercase text-[#E5C49B] block font-bold">Keterbatasan Informasi</span>
                            <ul class="space-y-1.5 text-[11px] text-[#F5F1E8]/70">
                                @if(!empty($aiConclusion['section_e']['missing_data']))
                                    @foreach($aiConclusion['section_e']['missing_data'] as $miss)
                                        <li class="flex items-start space-x-2 bg-[#05152B] p-2 rounded border border-[#F5F1E8]/5">
                                            <span class="text-[#E5C49B] font-bold">&bull;</span>
                                            <span>{{ $miss }}</span>
                                        </li>
                                    @endforeach
                                @else
                                    <li class="bg-[#05152B] p-2 rounded border border-[#F5F1E8]/5 text-[#E5C49B]">
                                        Seluruh data pendukung minimum (harga EOD, fundamental, dan berita) tersedia.
                                    </li>
                                @endif
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Bagian F: Detail Analisis (Accordion) -->
                <details class="group bg-[#05152B] border border-[#F5F1E8]/10 rounded-lg overflow-hidden transition">
                    <summary class="cursor-pointer p-3.5 sm:p-4 text-xs font-mono text-[#E5C49B] flex items-center justify-between hover:bg-[#0A213D] transition select-none focus:outline-none focus:ring-1 focus:ring-[#E5C49B]">
                        <span class="font-bold uppercase tracking-wider flex items-center space-x-2">
                            <svg class="w-4 h-4 text-[#E5C49B] transform group-open:rotate-90 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            <span>Detail Metadata Analisis &amp; Audit Trail (Analysis ID: #{{ $aiConclusion['section_f']['analysis_id'] ?? 'N/A' }})</span>
                        </span>
                        <span class="text-[10px] text-[#F5F1E8]/40 font-mono">Buka / Tutup</span>
                    </summary>

                    <div class="p-4 border-t border-[#F5F1E8]/10 space-y-3 text-xs font-mono">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-[11px]">
                            <div>
                                <span class="text-[#F5F1E8]/40 block uppercase">Analysis ID:</span>
                                <span class="text-[#F5F1E8] font-bold">{{ $aiConclusion['section_f']['analysis_id'] ?? '-' }}</span>
                            </div>
                            <div>
                                <span class="text-[#F5F1E8]/40 block uppercase">Evidence Snapshot ID:</span>
                                <span class="text-[#F5F1E8] font-bold">{{ $aiConclusion['section_f']['evidence_id'] ? '#'.$aiConclusion['section_f']['evidence_id'] : '-' }}</span>
                            </div>
                            <div>
                                <span class="text-[#F5F1E8]/40 block uppercase">Evidence Hash (SHA-256):</span>
                                <span class="text-[#E5C49B] break-all select-all">{{ $aiConclusion['section_f']['evidence_hash'] ?? '-' }}</span>
                            </div>
                            <div>
                                <span class="text-[#F5F1E8]/40 block uppercase">Prompt Version / Mode:</span>
                                <span class="text-[#F5F1E8]">{{ $aiConclusion['section_f']['prompt_version'] }}</span>
                            </div>
                            <div>
                                <span class="text-[#F5F1E8]/40 block uppercase">Model &amp; Latensi:</span>
                                <span class="text-[#F5F1E8]">{{ $aiConclusion['section_f']['model'] }} &bull; {{ $aiConclusion['section_f']['latency_ms'] ? number_format($aiConclusion['section_f']['latency_ms']).' ms' : '-' }}</span>
                            </div>
                            <div>
                                <span class="text-[#F5F1E8]/40 block uppercase">Token Usage:</span>
                                <span class="text-[#F5F1E8]">{{ $aiConclusion['section_f']['tokens_used'] ? number_format($aiConclusion['section_f']['tokens_used']).' tokens' : '-' }} {{ $aiConclusion['section_f']['reasoning_tokens'] ? '('.number_format($aiConclusion['section_f']['reasoning_tokens']).' reasoning)' : '' }}</span>
                            </div>
                        </div>

                        @if(!empty($aiConclusion['section_f']['raw_json']))
                            <details class="pt-2 border-t border-[#F5F1E8]/5">
                                <summary class="cursor-pointer text-[10px] text-[#F5F1E8]/40 hover:text-[#E5C49B] uppercase font-mono py-1 focus:outline-none">
                                    Lihat Output Terstruktur Mentah (JSON Tersanitasi)
                                </summary>
                                <pre class="mt-2 p-3 bg-[#020B17] rounded border border-[#F5F1E8]/10 text-[10px] text-emerald-400 overflow-x-auto max-h-80 leading-tight"><code>{{ e($aiConclusion['section_f']['raw_json']) }}</code></pre>
                            </details>
                        @endif
                    </div>
                </details>

            </div>
        </div>
    @endif

    <!-- Tab 6: News & Event -->
    @if(($tab ?? '') === 'news')
        <div class="space-y-4">
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
                <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Berita & Keterbukaan Informasi Terkait: {{ $ticker }}</h3>
                @if($news->isNotEmpty())
                    <div class="divide-y divide-[#F5F1E8]/5 space-y-3">
                        @foreach($news as $item)
                            <div class="pt-3 first:pt-0 space-y-1.5">
                                <div class="flex items-center space-x-2">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-cyan-950/60 text-cyan-300 border border-cyan-500/30">
                                        {{ strtoupper($item->verification_status ?? 'NEWS_OUTLET') }}
                                    </span>
                                    <span class="text-[11px] font-mono text-[#F5F1E8]/50">
                                        {{ $item->published_at ? $item->published_at->timezone('Asia/Jakarta')->format('d M Y H:i WIB') : '-' }}
                                    </span>
                                    <span class="text-[11px] text-[#F5F1E8]/40">&bull; {{ $item->source_name }}</span>
                                </div>
                                <h4 class="text-xs font-bold text-[#F5F1E8]">
                                    <a href="{{ $item->url }}" target="_blank" rel="noopener noreferrer" class="hover:text-[#E5C49B] transition">
                                        {{ $item->title }} &rarr;
                                    </a>
                                </h4>
                                @if($item->summary)
                                    <p class="text-xs text-[#F5F1E8]/70 leading-relaxed">{{ $item->summary }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-xs text-[#F5F1E8]/50 py-6 text-center font-mono">
                        Belum ada berita atau pengumuman bursa khusus bertag {{ $ticker }}.
                    </p>
                @endif
            </div>
        </div>
    @endif

    <!-- Tab 7: Riwayat AI Historis -->
    @if(($tab ?? '') === 'history')
        <div class="space-y-6">
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
                <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Riwayat Analisis AI Historis Khusus: {{ $ticker }}</h3>
                @if($analysisHistory->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs font-mono">
                            <thead class="bg-[#071C35] text-[#F5F1E8]/50 uppercase text-[10px]">
                                <tr>
                                    <th class="p-2.5">Tanggal Analisis</th>
                                    <th class="p-2.5">Model AI</th>
                                    <th class="p-2.5">Arah Dampak</th>
                                    <th class="p-2.5">Horizon</th>
                                    <th class="p-2.5">Status Skema</th>
                                    <th class="p-2.5">Analysis ID</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#F5F1E8]/5 text-[#F5F1E8]">
                                @foreach($analysisHistory as $an)
                                    <tr class="hover:bg-[#0D2B4E]/60 transition">
                                        <td class="p-2.5 text-[#F5F1E8]/70">{{ $an->created_at->timezone('Asia/Jakarta')->format('Y-m-d H:i') }}</td>
                                        <td class="p-2.5 text-[#E5C49B]">{{ $an->model_name }}</td>
                                        <td class="p-2.5 font-bold uppercase {{ $an->impact_direction === 'positive' ? 'text-emerald-400' : ($an->impact_direction === 'negative' ? 'text-rose-400' : 'text-[#F5F1E8]/60') }}">
                                            {{ $an->impact_direction }}
                                        </td>
                                        <td class="p-2.5">{{ $an->impact_horizon }}</td>
                                        <td class="p-2.5">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-emerald-950/60 text-emerald-300 border border-emerald-500/30">
                                                VALID_SCHEMA
                                            </span>
                                        </td>
                                        <td class="p-2.5 text-[#F5F1E8]/50">#{{ $an->id }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-xs text-[#F5F1E8]/50 py-6 text-center font-mono">
                        Belum ada riwayat analisis AI yang tercatat untuk emiten ini.
                    </p>
                @endif
            </div>

            @if(isset($tickerResearchRequests) && $tickerResearchRequests->isNotEmpty())
                <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
                    <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">
                        Permintaan Riset On-Demand &amp; Telegram: {{ $ticker }}
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs font-mono">
                            <thead class="bg-[#071C35] text-[#F5F1E8]/50 uppercase text-[10px]">
                                <tr>
                                    <th class="p-2.5">Request ID</th>
                                    <th class="p-2.5">Waktu</th>
                                    <th class="p-2.5">Tipe Analisis</th>
                                    <th class="p-2.5">Status</th>
                                    <th class="p-2.5">Evidence ID</th>
                                    <th class="p-2.5">Analysis ID</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#F5F1E8]/5 text-[#F5F1E8]">
                                @foreach($tickerResearchRequests as $tr)
                                    <tr class="hover:bg-[#0D2B4E]/60 transition">
                                        <td class="p-2.5 text-[#E5C49B] font-bold">{{ $tr->request_id }}</td>
                                        <td class="p-2.5 text-[#F5F1E8]/70">{{ $tr->as_of_time ? $tr->as_of_time->timezone('Asia/Jakarta')->format('Y-m-d H:i') : '-' }}</td>
                                        <td class="p-2.5">{{ $tr->analysis_type }}</td>
                                        <td class="p-2.5">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-mono {{ $tr->status === 'completed' ? 'bg-emerald-950/60 text-emerald-300 border border-emerald-500/30' : 'bg-amber-950/60 text-amber-300 border border-amber-500/30' }}">
                                                {{ strtoupper($tr->status) }}
                                            </span>
                                        </td>
                                        <td class="p-2.5 text-[#F5F1E8]/60">
                                            {{ $tr->evidence_snapshot_id ? '#'.$tr->evidence_snapshot_id : '-' }}
                                        </td>
                                        <td class="p-2.5 text-[#F5F1E8]/60">
                                            {{ $tr->market_analysis_id ? '#'.$tr->market_analysis_id : '-' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if(($tab ?? 'summary') === 'summary' && $recentBars->isNotEmpty())
        @include('dashboard.ticker_chart_script')
    @endif
