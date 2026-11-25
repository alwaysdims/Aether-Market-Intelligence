    @if(($tab ?? 'summary') === 'summary')
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left 2 Cols: Profile, Price/Volume Chart & Stats -->
            <div class="lg:col-span-2 space-y-6">

                <!-- Price & Volume Historical Chart.js Interactive Suite -->
                <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4" id="chartSuiteContainer">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-[#F5F1E8]/10 pb-3">
                        <div>
                            <div class="flex items-center space-x-2">
                                <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">
                                    Grafik Interaktif Candlestick & Indikator
                                </h3>
                                <span id="chartModeBadge" class="text-[9px] font-mono px-2 py-0.5 rounded bg-emerald-950/80 text-emerald-300 border border-emerald-500/30">
                                    ADJUSTED (Back-adjusted)
                                </span>
                            </div>
                            <p id="chartMarketNotice" class="text-[11px] text-[#F5F1E8]/60 mt-1 font-mono">
                                Memuat data historis...
                            </p>
                        </div>

                        <!-- Controls Toolbar -->
                        <div class="flex flex-wrap items-center gap-2 text-xs font-mono">
                            <!-- Timeframe Range -->
                            <div class="inline-flex rounded-md shadow-xs bg-[#05152B] p-0.5 border border-[#F5F1E8]/10" id="rangeBtnGroup">
                                <button type="button" data-range="1D" class="chart-range-btn px-2.5 py-1 rounded text-[10px] font-bold transition text-[#F5F1E8]/60 hover:text-[#F5F1E8]">1D</button>
                                <button type="button" data-range="1M" class="chart-range-btn px-2.5 py-1 rounded text-[10px] font-bold transition bg-[#E5C49B] text-[#05152B]">1M</button>
                                <button type="button" data-range="3M" class="chart-range-btn px-2.5 py-1 rounded text-[10px] font-bold transition text-[#F5F1E8]/60 hover:text-[#F5F1E8]">3M</button>
                                <button type="button" data-range="6M" class="chart-range-btn px-2.5 py-1 rounded text-[10px] font-bold transition text-[#F5F1E8]/60 hover:text-[#F5F1E8]">6M</button>
                                <button type="button" data-range="1Y" class="chart-range-btn px-2.5 py-1 rounded text-[10px] font-bold transition text-[#F5F1E8]/60 hover:text-[#F5F1E8]">1Y</button>
                            </div>

                            <!-- Adjustment Mode -->
                            <div class="inline-flex rounded-md shadow-xs bg-[#05152B] p-0.5 border border-[#F5F1E8]/10" id="adjModeGroup">
                                <button type="button" data-mode="adjusted" class="chart-mode-btn px-2 py-1 rounded text-[10px] font-bold transition bg-[#0A213D] text-[#E5C49B] border border-[#E5C49B]/30">Adj</button>
                                <button type="button" data-mode="raw" class="chart-mode-btn px-2 py-1 rounded text-[10px] font-bold transition text-[#F5F1E8]/50 hover:text-[#F5F1E8]">Raw</button>
                            </div>
                        </div>
                    </div>

                    <!-- Indicator Toggles & Sub-panel Selector -->
                    <div class="flex flex-wrap items-center justify-between gap-2 text-[10px] font-mono pt-1 border-b border-[#F5F1E8]/5 pb-2">
                        <div class="flex items-center space-x-2" id="maToggles">
                            <span class="text-[#F5F1E8]/40">Overlay:</span>
                            <label class="inline-flex items-center space-x-1 cursor-pointer">
                                <input type="checkbox" id="toggleSma20" checked class="form-checkbox h-3 w-3 text-amber-400 rounded bg-[#05152B] border-amber-400/40">
                                <span class="text-amber-300 font-bold">SMA 20</span>
                            </label>
                            <label class="inline-flex items-center space-x-1 cursor-pointer">
                                <input type="checkbox" id="toggleSma50" checked class="form-checkbox h-3 w-3 text-cyan-400 rounded bg-[#05152B] border-cyan-400/40">
                                <span class="text-cyan-300 font-bold">SMA 50</span>
                            </label>
                            <label class="inline-flex items-center space-x-1 cursor-pointer">
                                <input type="checkbox" id="toggleSma200" checked class="form-checkbox h-3 w-3 text-rose-400 rounded bg-[#05152B] border-rose-400/40">
                                <span class="text-rose-300 font-bold">SMA 200</span>
                            </label>
                        </div>

                        <div class="flex items-center space-x-1" id="subPanelGroup">
                            <span class="text-[#F5F1E8]/40 mr-1">Panel:</span>
                            <button type="button" data-sub="volume" class="sub-tab-btn px-2 py-0.5 rounded text-[10px] font-bold bg-[#E5C49B] text-[#05152B]">Volume</button>
                            <button type="button" data-sub="rsi" class="sub-tab-btn px-2 py-0.5 rounded text-[10px] font-bold text-[#F5F1E8]/60 hover:text-[#F5F1E8] bg-[#05152B]">RSI(14)</button>
                            <button type="button" data-sub="macd" class="sub-tab-btn px-2 py-0.5 rounded text-[10px] font-bold text-[#F5F1E8]/60 hover:text-[#F5F1E8] bg-[#05152B]">MACD</button>
                            <button type="button" data-sub="broker" class="sub-tab-btn px-2 py-0.5 rounded text-[10px] font-bold text-[#F5F1E8]/60 hover:text-[#F5F1E8] bg-[#05152B]">Akumulasi Broker</button>
                        </div>
                    </div>

                    <!-- Canvases -->
                    <div class="space-y-2">
                        <!-- Main Candlestick Chart -->
                        <div class="relative w-full h-[280px] bg-[#05152B] rounded border border-[#F5F1E8]/10 p-2">
                            <canvas id="mainPriceChartCanvas" class="w-full h-full block"></canvas>
                        </div>

                        <!-- Sub-Indicator Chart (Volume / RSI / MACD / Broker) -->
                        <div class="relative w-full h-[140px] bg-[#05152B] rounded border border-[#F5F1E8]/10 p-2">
                            <canvas id="subIndicatorChartCanvas" class="w-full h-full block"></canvas>
                        </div>
                    </div>

                    <!-- Selected Candle OHLC -->
                    <div id="selectedCandleReadout" class="grid grid-cols-2 sm:grid-cols-6 gap-2 rounded border border-[#F5F1E8]/10 bg-[#05152B] p-2 text-[10px] font-mono" aria-live="polite">
                        <span class="col-span-2 sm:col-span-1 text-[#E5C49B]">Candle terakhir</span><span>O: <b data-ohlc="open">N/A</b></span><span>H: <b data-ohlc="high">N/A</b></span><span>L: <b data-ohlc="low">N/A</b></span><span>C: <b data-ohlc="close">N/A</b></span><span>Vol: <b data-ohlc="volume">N/A</b></span>
                    </div>

                    <!-- Footer Info Bar -->
                    <div class="flex flex-wrap items-center justify-between text-[10px] font-mono text-[#F5F1E8]/50 pt-1">
                        <span id="chartFooterProvenance">Sumber: API IDX Edge PRO (Pasar Reguler - RG)</span>
                        <div class="flex items-center space-x-4" id="chartFooterMetrics">
                            <span>ATR (14): <strong id="footerAtrVal" class="text-[#F5F1E8]">-</strong></span>
                            <span>RSI (14): <strong id="footerRsiVal" class="text-[#F5F1E8]">-</strong></span>
                        </div>
                    </div>

                    <!-- Broker Summary Details Table (Visible when Broker sub-panel active) -->
                    <div id="brokerSummaryDetails" class="hidden space-y-3 pt-3 border-t border-[#F5F1E8]/10" aria-live="polite">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2 text-[10px] font-mono">
                            <label class="space-y-1"><span class="text-[#F5F1E8]/50">Dari</span><input id="brokerStartDate" type="date" class="w-full rounded bg-[#05152B] border border-[#F5F1E8]/15 px-2 py-1 text-[#F5F1E8]"></label>
                            <label class="space-y-1"><span class="text-[#F5F1E8]/50">Sampai</span><input id="brokerEndDate" type="date" class="w-full rounded bg-[#05152B] border border-[#F5F1E8]/15 px-2 py-1 text-[#F5F1E8]"></label>
                            <label class="space-y-1"><span class="text-[#F5F1E8]/50">Top broker</span><select id="brokerTop" class="w-full rounded bg-[#05152B] border border-[#F5F1E8]/15 px-2 py-1 text-[#F5F1E8]"><option value="3" selected>3</option><option value="5">5</option><option value="1">1</option></select></label>
                            <label class="space-y-1"><span class="text-[#F5F1E8]/50">Broker kode</span><input id="brokerCodes" type="text" placeholder="BK,ZP,AK" class="w-full rounded bg-[#05152B] border border-[#F5F1E8]/15 px-2 py-1 text-[#F5F1E8]"></label>
                            <button id="brokerSummaryRefresh" type="button" class="rounded bg-[#E5C49B] px-2 py-1 font-bold text-[#05152B] focus:outline-none focus:ring-2 focus:ring-[#E5C49B]">Muat tren</button>
                        </div>
                        <div id="brokerSummaryStatus" class="text-[10px] text-[#F5F1E8]/50"></div>
                        <div id="brokerSummaryTable" class="max-h-72 overflow-auto rounded border border-[#F5F1E8]/10"></div>
                    </div>
                </div>

            </div>

            <!-- Right Column: Technical decision panel -->
            <div class="space-y-6">
                <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
                    <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Teknikal & Support / Resistance</h3>
                    @if($technical)
                        <div class="grid grid-cols-2 gap-3 text-xs font-mono">
                            <div class="bg-[#0D2B4E] p-3 rounded"><span class="text-[10px] text-[#F5F1E8]/40 block">SUPPORT</span><strong class="text-[#E5C49B]">{{ $technical->support_price !== null ? 'Rp '.number_format($technical->support_price, 0, ',', '.') : 'N/A' }}</strong></div>
                            <div class="bg-[#0D2B4E] p-3 rounded"><span class="text-[10px] text-[#F5F1E8]/40 block">RESISTANCE</span><strong class="text-[#E5C49B]">{{ $technical->resistance_price !== null ? 'Rp '.number_format($technical->resistance_price, 0, ',', '.') : 'N/A' }}</strong></div>
                            <div class="bg-[#0D2B4E] p-3 rounded"><span class="text-[10px] text-[#F5F1E8]/40 block">RSI (14)</span><strong class="text-cyan-300">{{ $technical->rsi_14 !== null ? number_format($technical->rsi_14, 2) : 'N/A' }}</strong></div>
                            <div class="bg-[#0D2B4E] p-3 rounded"><span class="text-[10px] text-[#F5F1E8]/40 block">MACD</span><strong class="text-[#F5F1E8]">{{ $technical->macd !== null ? number_format($technical->macd, 2) : 'N/A' }}</strong></div>
                        </div>
                        <p class="text-[10px] text-[#F5F1E8]/50 font-mono">As-of {{ data_get($technical->data_quality_flags, 'as_of_date', 'N/A') }} · {{ data_get($technical->data_quality_flags, 'input_bars', $totalHistoryBars) }} input bars · interval 1d</p>
                    @else
                        <p class="text-xs text-[#F5F1E8]/50 py-4 text-center font-mono">DATA_NOT_AVAILABLE — Metrik teknikal belum tersedia.</p>
                    @endif
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
                                    <th class="p-2.5">Signal / Pred ID</th>
                                    <th class="p-2.5">Asal / Model</th>
                                    <th class="p-2.5">Waktu Publikasi</th>
                                    <th class="p-2.5">Harga Acuan vs Terkini</th>
                                    <th class="p-2.5">Arah Sinyal</th>
                                    <th class="p-2.5">Target & Batas</th>
                                    <th class="p-2.5">Jatuh Tempo</th>
                                    <th class="p-2.5">Status Evaluasi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#F5F1E8]/5">
                                @foreach($signals as $sig)
                                    @php
                                        $currPrice = $latestObs?->close_price ?? $sig->reference_price;
                                        $priceDiffPct = $sig->reference_price > 0 ? round((($currPrice - $sig->reference_price) / $sig->reference_price) * 100, 2) : 0;
                                    @endphp
                                    <tr class="hover:bg-[#0D2B4E]/60 transition">
                                        <td class="p-2.5 font-mono text-[#E5C49B]">
                                            <span class="font-bold">{{ $sig->prediction_id }}</span>
                                            @if($sig->evidence_snapshot_id)
                                                <span class="text-[9px] text-[#F5F1E8]/40 block">Evidence #{{ $sig->evidence_snapshot_id }}</span>
                                            @endif
                                        </td>
                                        <td class="p-2.5 font-mono text-[#F5F1E8]/80">
                                            @if(str_starts_with($sig->model_name ?? '', 'TELEGRAM_') || ($sig->metadata['origin'] ?? '') === 'TELEGRAM_TRADING_PLAN')
                                                <span class="px-1.5 py-0.5 rounded text-[9px] font-bold bg-[#E5C49B]/20 text-[#E5C49B] border border-[#E5C49B]/30 inline-block mb-0.5">
                                                    TELEGRAM {{ $sig->metadata['plan_type'] ?? 'PLAN' }}
                                                </span>
                                                <span class="text-[9px] text-[#F5F1E8]/40 block">{{ $sig->model_version ?? 'v1.1' }}</span>
                                            @else
                                                <span>{{ $sig->model_name ?? ($sig->metadata['source'] ?? 'RULE_SCREENING') }}</span>
                                                <span class="text-[9px] text-[#F5F1E8]/40 block">v{{ $sig->model_version ?? '1.0' }}</span>
                                            @endif
                                        </td>
                                        <td class="p-2.5 font-mono text-[#F5F1E8]/70">
                                            {{ $sig->published_at ? $sig->published_at->timezone('Asia/Jakarta')->format('Y-m-d H:i') : ($sig->decision_timestamp ? $sig->decision_timestamp->timezone('Asia/Jakarta')->format('Y-m-d H:i') : '-') }}
                                            @if($sig->telegram_message_id)
                                                <span class="text-[9px] text-emerald-400 block font-semibold">Tg Receipt #{{ $sig->telegram_message_id }}</span>
                                            @elseif(($sig->metadata['contract_provenance'] ?? '') === 'RETROSPECTIVE_RECONCILIATION')
                                                <span class="text-[9px] text-purple-300 block font-semibold">Audit Retrospektif (Bukan Forward)</span>
                                            @elseif(($sig->metadata['delivery_status'] ?? '') === 'QUEUED')
                                                <span class="text-[9px] text-amber-400 block font-semibold">Outbox #{{ $sig->metadata['outbox_id'] ?? '?' }} (Antre)</span>
                                            @endif
                                        </td>
                                        <td class="p-2.5 font-mono tabular-nums">
                                            <span>Rp {{ number_format($sig->reference_price ?? 0, 0, ',', '.') }}</span>
                                            <span class="text-[10px] block {{ $priceDiffPct >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                                                Terkini: Rp {{ number_format($currPrice, 0, ',', '.') }} ({{ $priceDiffPct >= 0 ? '+' : '' }}{{ $priceDiffPct }}%)
                                            </span>
                                        </td>
                                        <td class="p-2.5 font-bold {{ $sig->direction === 'UP' ? 'text-emerald-400' : ($sig->direction === 'DOWN' ? 'text-rose-400' : 'text-[#F5F1E8]/60') }}">
                                            {{ $sig->direction ?? 'NO_SIGNAL' }}
                                        </td>
                                        <td class="p-2.5 font-mono text-[11px] tabular-nums">
                                            @if($sig->target_price)
                                                <span class="text-emerald-300 block">Tgt: Rp {{ number_format($sig->target_price, 0, ',', '.') }}</span>
                                            @endif
                                            @if($sig->invalidation_price)
                                                <span class="text-rose-300 block">Inv: Rp {{ number_format($sig->invalidation_price, 0, ',', '.') }}</span>
                                            @endif
                                            @if(! $sig->target_price && ! $sig->invalidation_price)
                                                <span class="text-[#F5F1E8]/40">Horizon Only</span>
                                            @endif
                                        </td>
                                        <td class="p-2.5 font-mono text-[#F5F1E8]/60">
                                            {{ $sig->evaluation_due_date ? $sig->evaluation_due_date->format('Y-m-d') : 'T+5' }}
                                        </td>
                                        <td class="p-2.5">
                                            <div class="space-y-0.5 font-mono">
                                                <span class="px-2 py-0.5 rounded text-[10px] block text-center {{ str_contains($sig->evaluation_status ?? '', 'PROVISIONAL') ? 'bg-amber-950/80 text-amber-300 border border-amber-500/30' : ($sig->evaluation_status === 'EVALUATED_CORRECT' ? 'bg-emerald-950/80 text-emerald-300 border border-emerald-500/30' : ($sig->evaluation_status === 'EVALUATED_INCORRECT' ? 'bg-rose-950/80 text-rose-300 border border-rose-500/30' : 'bg-[#0D2B4E] text-[#F5F1E8]/70 border border-[#F5F1E8]/10')) }}">
                                                    {{ str_contains($sig->evaluation_status ?? '', 'PROVISIONAL') ? 'PROVISIONAL (MENUNGGU EOD FINAL)' : ($sig->evaluation_status ?? 'PENDING') }}
                                                </span>
                                                <span class="text-[9px] text-[#F5F1E8]/40 block text-center">
                                                    Pantau: {{ $sig->monitoring_status ?? 'ACTIVE' }}
                                                </span>
                                            </div>
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
                        BOARD: RG (PROVIDER_SUPPLIED)
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
                                        <td class="p-2.5 tabular-nums">{{ $bs->buy_avg_price !== null ? 'Rp '.number_format($bs->buy_avg_price, 0, ',', '.') : 'N/A' }}</td>
                                        <td class="p-2.5 tabular-nums">{{ number_format($bs->sell_volume, 0, ',', '.') }}</td>
                                        <td class="p-2.5 tabular-nums">{{ $bs->sell_avg_price !== null ? 'Rp '.number_format($bs->sell_avg_price, 0, ',', '.') : 'N/A' }}</td>
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
                        Catatan Metodologis: Agregasi pergerakan broker di Pasar Reguler (RG). Cakupan data dibatasi pada Top-N Broker (misal: 30 broker teratas per sesi); bukan daftar lengkap seluruh anggota bursa. Rata-rata harga beli dihitung murni sebagai buy_value / buy_volume (bukan harga modal akumulasi bersih / cost basis inventori tersisa). Sekuritas bertindak sebagai perantara dari banyak nasabah independen, bukan entitas bandar tunggal.
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
                    @php($technicalFlags = $technical->data_quality_flags ?? [])
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs font-mono border-t border-[#F5F1E8]/10 pt-3">
                        <div class="bg-[#0D2B4E] p-2.5 rounded">
                            <span class="text-[10px] text-[#F5F1E8]/40 block">SUPPORT / RESISTANCE</span>
                            <span class="text-sm font-bold text-[#E5C49B] tabular-nums">{{ $technical->support_price !== null && $technical->resistance_price !== null ? 'Rp '.number_format($technical->support_price, 0, ',', '.').' / Rp '.number_format($technical->resistance_price, 0, ',', '.') : 'N/A' }}</span>
                            <span class="text-[9px] text-[#F5F1E8]/50 block">Rolling {{ data_get($technicalFlags, 'support_resistance_window_bars', 20) }} bar · {{ data_get($technicalFlags, 'support_resistance_method', 'rolling_min_max') }}</span>
                        </div>
                        <div class="bg-[#0D2B4E] p-2.5 rounded">
                            <span class="text-[10px] text-[#F5F1E8]/40 block">BASIS INDIKATOR</span>
                            <span class="text-sm font-bold text-cyan-400 block">{{ data_get($technicalFlags, 'as_of_date', 'N/A') }}</span>
                            <span class="text-[9px] text-[#F5F1E8]/50 block">{{ data_get($technicalFlags, 'adjustment_mode', 'PROVIDER_BACK_ADJUSTED') }} · {{ data_get($technicalFlags, 'source', 'IDX Edge PRO') }} · {{ data_get($technicalFlags, 'board_scope', 'RG') }} · {{ data_get($technicalFlags, 'input_bars', $indicatorInputBars) }} bar · interval 1d</span>
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
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4" id="apiFundamentalPanel">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div><h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Laporan Keuangan API</h3><p class="text-[10px] text-[#F5F1E8]/50">Nilai mentah provider. Currency, skala, konsolidasi, dan publikasi tidak ditebak.</p></div>
                    <div class="flex flex-wrap gap-2 text-[10px] font-mono">
                        <select id="financialReportType" class="rounded bg-[#05152B] border border-[#F5F1E8]/15 px-2 py-1"><option value="INCOME_STATEMENT">Laba Rugi</option><option value="BALANCE_SHEET">Neraca</option><option value="CASH_FLOW_REPORT">Arus Kas</option></select>
                        <select id="financialPeriod" class="rounded bg-[#05152B] border border-[#F5F1E8]/15 px-2 py-1"><option value="quarterly">Kuartalan</option><option value="annually">Tahunan</option></select>
                        <input id="financialYear" type="number" min="2000" max="2100" placeholder="Tahun" class="w-20 rounded bg-[#05152B] border border-[#F5F1E8]/15 px-2 py-1">
                    </div>
                </div>
                <div id="financialApiStatus" class="text-[10px] text-[#F5F1E8]/50" aria-live="polite">Memuat...</div><div id="financialApiTable" class="overflow-auto max-h-[28rem]"></div>
            </div>
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4" id="apiOwnershipPanel">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"><div><h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Kepemilikan Saham ≥1%</h3><p class="text-[10px] text-[#F5F1E8]/50">Snapshot akhir bulan, bukan posisi investor hari ini. Daftar tidak wajib berjumlah 100%.</p></div><div class="flex gap-2 text-[10px] font-mono"><input id="ownershipMonth" type="number" min="1" max="12" placeholder="Bulan" class="w-16 rounded bg-[#05152B] border border-[#F5F1E8]/15 px-2 py-1"><input id="ownershipYear" type="number" min="2000" max="2100" placeholder="Tahun" class="w-20 rounded bg-[#05152B] border border-[#F5F1E8]/15 px-2 py-1"></div></div>
                <div id="ownershipApiStatus" class="text-[10px] text-[#F5F1E8]/50" aria-live="polite">Ownership endpoint belum diaktifkan.</div><div id="ownershipApiTable" class="overflow-auto max-h-72"></div>
            </div>
            <script>
            (() => {
                const ticker = @json($ticker), esc = value => String(value ?? 'N/A').replace(/[<>&]/g, ''), display = value => value == null ? 'N/A' : esc(typeof value === 'object' ? JSON.stringify(value) : value), fmt = value => value == null ? 'N/A' : Number(value).toLocaleString('id-ID');
                let seq = 0;
                async function financial() { const id = ++seq, type = document.getElementById('financialReportType').value, period = document.getElementById('financialPeriod').value, year = document.getElementById('financialYear').value; const status = document.getElementById('financialApiStatus'), table = document.getElementById('financialApiTable'); status.textContent = 'Memuat laporan...'; try { const q = new URLSearchParams({ report_type: type, period, limit: '40' }); if (year) q.set('year', year); const r = await fetch(`/api/market/${encodeURIComponent(ticker)}/financial-statements?${q}`, { headers: { Accept: 'application/json' }, cache: 'no-store' }); const p = await r.json(); if (id !== seq) return; if (!r.ok || !p.success) throw new Error(p.message || p.error_type || `HTTP ${r.status}`); const items = p.data.items || []; const periods = [...new Set(items.map(x => `${x.year || 'N/A'} ${x.quarter ? 'Q'+x.quarter : ''}`.trim()))]; const keys = [...new Set(items.flatMap(x => Object.keys(x.data || {})))]; table.innerHTML = `<table class="w-full text-[10px] font-mono"><thead class="sticky top-0 bg-[#071C35]"><tr><th class="p-2 text-left">Akun</th>${periods.map(x => `<th class="p-2 text-right">${esc(x)}</th>`).join('')}</tr></thead><tbody>${keys.map(key => `<tr class="border-t border-[#F5F1E8]/5"><td class="p-2">${esc(key)}</td>${periods.map(periodLabel => { const item = items.find(x => `${x.year || 'N/A'} ${x.quarter ? 'Q'+x.quarter : ''}`.trim() === periodLabel); const value = item?.data?.[key]; return `<td class="p-2 text-right tabular-nums">${display(value)}</td>`; }).join('')}</tr>`).join('') || `<tr><td class="p-3" colspan="${periods.length + 1}">PROVIDER_EMPTY</td></tr>`}</tbody></table>`; status.textContent = `Source: IDX Edge API · ${type} · ${period} · ${items.length} periode`; } catch (e) { table.innerHTML = `<p class="p-3 text-amber-300">FETCH_FAILED — ${esc(e.message)}</p>`; status.textContent = 'Provider failure; bukan empty state.'; } }
                async function ownership() { const status = document.getElementById('ownershipApiStatus'), table = document.getElementById('ownershipApiTable'), q = new URLSearchParams(); const month = document.getElementById('ownershipMonth').value, year = document.getElementById('ownershipYear').value; if (month) q.set('month', month); if (year) q.set('year', year); try { const r = await fetch(`/api/market/${encodeURIComponent(ticker)}/ownership?${q}`, { headers: { Accept: 'application/json' }, cache: 'no-store' }); const p = await r.json(); if (!r.ok || !p.success) throw new Error(p.message || p.error_type || `HTTP ${r.status}`); if (!p.data.found) { status.textContent = 'FOUND_FALSE — periode tidak ditemukan.'; table.innerHTML = ''; return; } const rows = p.data.items || []; table.innerHTML = `<table class="w-full text-[10px] font-mono"><thead class="bg-[#071C35]"><tr><th class="p-2 text-left">Pemegang</th><th class="p-2 text-right">%</th><th class="p-2 text-right">MoM</th><th class="p-2 text-right">Total Shares</th><th class="p-2 text-right">Nilai Rp</th><th class="p-2 text-left">Tipe/Domisili</th></tr></thead><tbody>${rows.map(x => `<tr class="border-t border-[#F5F1E8]/5"><td class="p-2">${esc(x.name || x.shareholder_name)}</td><td class="p-2 text-right">${esc(x.ownership_pct)}</td><td class="p-2 text-right">${esc(x.change_pct_mom)}</td><td class="p-2 text-right">${fmt(x.total_shares)}</td><td class="p-2 text-right">${fmt(x.nilai_rp ?? x.value_rp)}</td><td class="p-2">${esc([x.investor_type, x.local_foreign, x.domicile].filter(Boolean).join(' / '))}</td></tr>`).join('') || '<tr><td colspan="6" class="p-3">PROVIDER_EMPTY</td></tr>'}</tbody></table>`; status.textContent = `Period: ${esc(p.data.period)} · Previous: ${esc(p.data.previous_period)} · MoM semantics: ${esc(p.data.change_pct_mom_semantics)}`; } catch (e) { status.textContent = `OWNERSHIP_NOT_AVAILABLE — ${esc(e.message)}`; table.innerHTML = ''; } }
                document.querySelectorAll('#financialReportType,#financialPeriod,#financialYear').forEach(x => x.addEventListener('change', financial)); document.querySelectorAll('#ownershipMonth,#ownershipYear').forEach(x => x.addEventListener('change', ownership)); financial(); ownership();
            })();
            </script>
            <div class="hidden">
            <!-- Legacy calculated summary retained for AI/history compatibility -->
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
                <div class="flex items-center justify-between border-b border-[#F5F1E8]/10 pb-3">
                    <div>
                        <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Rekonsiliasi Saham, Laba Induk & Valuasi</h3>
                        <p class="text-[11px] text-[#F5F1E8]/50 mt-0.5">Pemisahan Reported Basic EPS, Saham Rata-rata Tertimbang Implisit, dan Saham Akhir Periode.</p>
                    </div>
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-cyan-950/60 text-cyan-300 border border-cyan-500/30">
                        STATUS: PROVIDER_SUPPLIED
                    </span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs font-mono">
                    <div class="bg-[#0D2B4E] p-3 rounded border border-[#F5F1E8]/5">
                        <span class="text-[10px] text-[#F5F1E8]/40 block uppercase">Reported Basic EPS</span>
                        <span class="text-sm font-bold text-[#E5C49B] block mt-1">
                            {{ $reportedBasicEps !== null ? 'Rp ' . number_format($reportedBasicEps, 2, ',', '.') : 'N/A' }}
                        </span>
                        <span class="text-[10px] text-[#F5F1E8]/50 block mt-1">Dasar: Laporan Laba Rugi Resmi</span>
                    </div>

                    <div class="bg-[#0D2B4E] p-3 rounded border border-[#F5F1E8]/5">
                        <span class="text-[10px] text-[#F5F1E8]/40 block uppercase">Saham Akhir Periode (Ordinary)</span>
                        <span class="text-sm font-bold text-[#F5F1E8] block mt-1 tabular-nums">
                            {{ $periodEndShares ? number_format($periodEndShares, 0, ',', '.') . ' lbr' : 'N/A' }}
                        </span>
                        <span class="text-[10px] text-[#F5F1E8]/50 block mt-1">Dasar BVPS & Market Cap</span>
                    </div>

                    <div class="bg-[#0D2B4E] p-3 rounded border border-[#F5F1E8]/5">
                        <span class="text-[10px] text-[#F5F1E8]/40 block uppercase">Weighted Shares (Implisit)</span>
                        <span class="text-sm font-bold text-cyan-300 block mt-1 tabular-nums">
                            {{ $impliedWeightedShares ? number_format($impliedWeightedShares, 0, ',', '.') . ' lbr' : 'N/A' }}
                        </span>
                        <span class="text-[10px] text-[#F5F1E8]/50 block mt-1">Laba Induk / Reported EPS</span>
                    </div>

                    <div class="bg-[#0D2B4E] p-3 rounded border border-[#F5F1E8]/5">
                        <span class="text-[10px] text-[#F5F1E8]/40 block uppercase">Penyesuaian Harga (Adj Mode)</span>
                        <span class="text-sm font-bold text-emerald-400 block mt-1">
                            {{ $priceAdjustmentMode ?? 'NOMINAL' }}
                        </span>
                        <span class="text-[10px] text-[#F5F1E8]/50 block mt-1">Basis Harga Sesi Terkini</span>
                    </div>
                </div>

                <div class="bg-[#071C35] p-3 rounded border border-[#F5F1E8]/5 text-[11px] text-[#F5F1E8]/70 leading-relaxed">
                    <strong class="text-[#E5C49B]">Catatan Audit:</strong> Rasio laba bersih entitas induk terhadap jumlah saham akhir periode menghasilkan kalkulasi naif (misal pada FORE: Rp 90,13 M / 8,918 M lbr = Rp 10,11). Angka reported EPS sebesar Rp 10,95 mencerminkan rata-rata tertimbang saham beredar sepanjang tahun buku (~8,231 M lbr) akibat aksi korporasi / pencatatan saham di tengah periode. Sistem memisahkan basis ini secara transparan tanpa mengubah reported EPS resmi.
                </div>
            </div>

            <!-- Tabel Laporan Keuangan Fiskal Tahunan -->
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
                <div class="flex items-center justify-between border-b border-[#F5F1E8]/10 pb-3">
                    <h3 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">Laporan Keuangan Fiskal Tahunan</h3>
                    <span class="text-xs font-mono text-[#F5F1E8]/40">Nominal hanya dibandingkan jika currency, scale, dan consolidation terverifikasi.</span>
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
                                    <th class="p-2.5">Arus Kas Operasi</th>
                                    <th class="p-2.5">Free Cash Flow</th>
                                    <th class="p-2.5">Sumber & Kualitas</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#F5F1E8]/5 text-[#F5F1E8]">
                                @foreach($fundamentals as $f)
                                    <tr class="hover:bg-[#0D2B4E]/60 transition">
                                        <td class="p-2.5 font-bold text-[#E5C49B]">{{ $f->fiscal_year }}</td>
                                        @if(in_array(strtoupper((string) $f->currency), ['IDR', 'USD'], true) && in_array(strtoupper((string) $f->unit), ['IDR_FULL', 'USD_FULL'], true) && data_get($f->raw_payload, 'consolidation_scope') !== null)
                                            <td class="p-2.5 tabular-nums">{{ $f->currency }} {{ number_format($f->total_revenue, 2, ',', '.') }}</td>
                                            <td class="p-2.5 tabular-nums {{ $f->net_income >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">{{ $f->currency }} {{ number_format($f->net_income, 2, ',', '.') }}</td>
                                            <td class="p-2.5 tabular-nums text-emerald-300">{{ $f->attribution_parent !== null ? $f->currency.' '.number_format($f->attribution_parent, 2, ',', '.') : '-' }}</td>
                                            <td class="p-2.5 tabular-nums">{{ $f->currency }} {{ number_format($f->total_assets, 2, ',', '.') }}</td>
                                            <td class="p-2.5 tabular-nums text-cyan-400">{{ $f->currency }} {{ number_format($f->total_equity, 2, ',', '.') }}</td>
                                            <td class="p-2.5 tabular-nums">{{ $f->operating_cash_flow !== null ? $f->currency.' '.number_format($f->operating_cash_flow, 2, ',', '.') : 'N/A' }}</td>
                                            <td class="p-2.5 tabular-nums {{ $f->free_cash_flow !== null && $f->free_cash_flow >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">{{ $f->free_cash_flow !== null ? $f->currency.' '.number_format($f->free_cash_flow, 2, ',', '.') : 'N/A' }}</td>
                                        @else
                                            <td class="p-2.5 text-amber-300" colspan="7">NOMINAL_INTERPRETATION_BLOCKED — currency/scale/consolidation belum terverifikasi.</td>
                                        @endif
                                        <td class="p-2.5"><span class="text-[10px] font-mono text-amber-300">{{ $f->data_quality ?? 'NOT_VERIFIED' }}</span><span class="text-[9px] text-[#F5F1E8]/40 block mt-0.5">{{ $f->source ?? 'IDX Edge PRO' }}</span></td>
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
        </div>
    @endif

    <!-- Tab 5: Kesimpulan AI -->
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
                            @if($aiConclusion['header']['is_analysis_stale_for_latest_bar'])
                                <span class="text-amber-300">&bull; Narasi tersimpan lebih lama dari bar terbaru {{ $aiConclusion['header']['latest_observation_date'] }}.</span>
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
                            @if(!empty($aiConclusion['section_f']['historical_warning']))
                                <div class="sm:col-span-2 rounded border border-amber-500/30 bg-amber-950/20 p-3 text-amber-200">
                                    {{ $aiConclusion['section_f']['historical_warning'] }}
                                </div>
                            @endif
                            <div>
                                <span class="text-[#F5F1E8]/40 block uppercase">Evidence Link Status:</span>
                                <span class="text-[#F5F1E8] font-bold">{{ $aiConclusion['section_f']['evidence_link_status'] ?? 'UNKNOWN' }}</span>
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

    <!-- Tab 7: Insider data -->
    @if(($tab ?? '') === 'insiders')
        @include('dashboard.ticker_insiders')
    @endif

    <!-- Tab 8: Riwayat AI Historis -->
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

    @if(($tab ?? 'summary') === 'summary')
        @include('dashboard.ticker_chart_script')
    @endif
