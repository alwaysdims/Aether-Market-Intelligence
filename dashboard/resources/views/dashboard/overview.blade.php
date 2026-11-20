@extends('layouts.app')

@section('page_title', 'Dashboard')

@section('content')
<div class="space-y-6 max-w-[1600px] mx-auto">

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        @php
        $moversWidget = $marketWidgets['movers'] ?? ['success' => false, 'error_type' => 'DATA_NOT_AVAILABLE'];
        $ihsgWidget = $marketWidgets['ihsg'] ?? ['success' => false, 'error_type' => 'DATA_NOT_AVAILABLE'];
        @endphp

        <x-card title="Peringkat Harian" subtitle="/api/movers?by=chg&n=10">
            @if($moversWidget['success'] ?? false)
            @php $moversData = $moversWidget['data'] ?? []; @endphp
            <div class="flex gap-2 mb-3" role="tablist" aria-label="Peringkat harian">
                <button type="button"
                    class="mover-tab rounded bg-[#E5C49B] px-3 py-1 text-[10px] font-bold text-[#05152B]"
                    data-group="gainers" role="tab" aria-selected="true">Top Gainer</button>
                <button type="button" class="mover-tab rounded px-3 py-1 text-[10px] font-bold text-[#F5F1E8]/60"
                    data-group="losers" role="tab" aria-selected="false">Top Loser</button>
            </div>
            @foreach(['gainers' => $moversData['gainers'] ?? [], 'losers' => $moversData['losers'] ?? []] as $group =>
            $rows)
            <div class="mover-panel overflow-x-auto {{ $group === 'losers' ? 'hidden' : '' }}"
                data-group-panel="{{ $group }}">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-[#F5F1E8]/10 text-[#F5F1E8]/50">
                            <th class="py-2">#</th>
                            <th class="py-2">Saham</th>
                            <th class="py-2 text-right">Harga</th>
                            <th class="py-2 text-right">Perubahan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F1E8]/5">
                        @forelse($rows as $rank => $row)
                        @php $positive = $group === 'gainers'; @endphp
                        <tr>
                            <td class="py-2">{{ $rank + 1 }}</td>
                            <td class="py-2 font-mono font-semibold">{{ $row['code'] ?? 'N/A' }}</td>
                            <td class="py-2 text-right font-mono">
                                {{ is_numeric($row['last_price'] ?? null) ? number_format((float) $row['last_price'], 2, ',', '.') : 'N/A' }}
                            </td>
                            <td
                                class="py-2 text-right font-mono {{ $positive ? 'text-emerald-400' : 'text-rose-400' }}">
                                {{ is_numeric($row['change'] ?? null) ? ($row['change'] >= 0 ? '+' : '') . number_format((float) $row['change'], 2, ',', '.') : 'N/A' }}
                                /
                                {{ is_numeric($row['change_pct'] ?? null) ? number_format((float) $row['change_pct'], 2, ',', '.') . '%' : 'N/A' }}
                            </td>
                        </tr>
                        @empty <tr>
                            <td colspan="5" class="py-6 text-center text-[#F5F1E8]/40">Provider mengembalikan daftar
                                kosong.</td>
                        </tr> @endforelse
                    </tbody>
                </table>
            </div>
            @endforeach
            @else
            <x-empty-state title="Movers belum tersedia"
                message="{{ $moversWidget['message'] ?? 'Provider belum menyediakan snapshot movers.' }}" /> @endif
        </x-card>

        <x-card title="IHSG — Indeks Harga Saham Gabungan" subtitle="Harga indeks dan status pasar">
            @php $ihsg = $ihsgWidget['data'] ?? []; $fmt = fn ($value, $dec = 2) => is_numeric($value) ? number_format((float) $value, $dec, ',', '.') : 'N/A'; @endphp
            <div id="ihsgPriceWidget" data-endpoint="{{ url('/api/price/IHSG') }}" data-market-state="{{ $ihsg['market_state'] ?? 'UNKNOWN' }}">
                <div id="ihsgLastPrice" class="text-3xl font-bold font-mono tabular-nums">{{ $fmt($ihsg['last_price'] ?? null) }}</div>
                <div id="ihsgChange" class="mt-1 font-mono">{{ is_numeric($ihsg['change'] ?? null) ? (($ihsg['change'] >= 0 ? '+' : '') . $fmt($ihsg['change'])) : 'N/A' }} poin / {{ is_numeric($ihsg['change_pct'] ?? null) ? (($ihsg['change_pct'] >= 0 ? '+' : '') . $fmt($ihsg['change_pct']) . '%') : 'N/A' }}</div>
                <dl class="mt-4 grid grid-cols-2 gap-3 text-xs">
                    @foreach(['previous_close' => 'Prev close', 'open' => 'Open', 'high' => 'High', 'low' => 'Low'] as $key => $label)
                    <div><dt class="text-[#F5F1E8]/50">{{ $label }}</dt><dd id="ihsg{{ str_replace('_', '', ucwords($key, '_')) }}" class="font-mono">{{ $fmt($ihsg[$key] ?? null) }}</dd></div>
                    @endforeach
                </dl>
                <div class="mt-4 space-y-1 text-[10px] text-[#F5F1E8]/50">
                    <p>Status: <span id="ihsgMarketStatus">{{ $ihsg['market_label'] ?? $ihsg['market_state'] ?? 'N/A' }}</span></p>
                    <p>Data: <span id="ihsgDataTs">{{ $ihsg['data_ts'] ?? $ihsg['data_timestamp'] ?? ($ihsg['source_date'] ?? 'N/A') }}</span> WIB</p>
                    <p>Diperbarui aplikasi: <span id="ihsgServedTs">{{ $ihsg['served_ts'] ?? $ihsgWidget['fetched_at'] ?? 'N/A' }}</span> WIB</p>
                    <p id="ihsgConnectionStatus" class="text-emerald-300">Data tersambung</p>
                </div>
                <div class="mt-5 border-t border-[#F5F1E8]/10 pt-4">
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <div><h3 class="text-xs font-bold text-[#F5F1E8]">Harga IHSG</h3><p id="ihsgChartNotice" class="text-[10px] text-[#F5F1E8]/50 font-mono">Memuat chart intraday...</p></div>
                        <span class="text-[10px] font-mono text-[#F5F1E8]/40">1D · interval 60 detik</span>
                    </div>
                    <div class="relative h-[360px] w-full rounded border border-[#F5F1E8]/10 bg-[#05152B] p-1"><canvas id="ihsgIntradayCanvas" class="block h-full w-full" aria-label="Chart intraday IHSG satu sesi"></canvas></div>
                </div>
            </div>
            <script>
            (() => {
                const canvas = document.getElementById('ihsgIntradayCanvas'), notice = document.getElementById('ihsgChartNotice');
                if (!canvas) return;
                const ticker = 'IHSG', currentRange = '1D', currentMode = 'raw';
                let bars = [], chartInFlight = null;
                const palette = { grid: 'rgba(245,241,232,.10)', text: 'rgba(245,241,232,.58)', bull: '#34d399', bear: '#f87171', ma20: '#f5b942', ma50: '#67b7e8', ma200: '#d7dee8', macd: '#60a5fa', signal: '#f5b942' };
                const ema = (values, period) => { const out = Array(values.length).fill(null); if (values.length < period) return out; let value = values.slice(0, period).reduce((sum, item) => sum + item, 0) / period; out[period - 1] = value; const alpha = 2 / (period + 1); for (let i = period; i < values.length; i++) { value = alpha * values[i] + (1 - alpha) * value; out[i] = value; } return out; };
                const drawLine = (ctx, points, color, width, mapX, mapY) => { ctx.beginPath(); let started = false; points.forEach((value, index) => { if (value == null) return; const x = mapX(index), y = mapY(value); if (!started) { ctx.moveTo(x, y); started = true; } else ctx.lineTo(x, y); }); if (started) { ctx.strokeStyle = color; ctx.lineWidth = width; ctx.stroke(); } };
                function draw() {
                    const rect = canvas.getBoundingClientRect(), dpr = window.devicePixelRatio || 1, width = Math.max(320, rect.width), height = Math.max(280, rect.height); canvas.width = width * dpr; canvas.height = height * dpr; const ctx = canvas.getContext('2d'); ctx.setTransform(dpr, 0, 0, dpr, 0, 0); ctx.clearRect(0, 0, width, height);
                    if (!bars.length) { ctx.fillStyle = palette.text; ctx.font = '12px monospace'; ctx.fillText('DATA_NOT_AVAILABLE — bar intraday IHSG belum tersedia.', 16, 28); return; }
                    const left = 10, right = width - 48, top = 10, bottom = height - 16, gap = 14, mainBottom = Math.floor(top + (bottom - top - gap * 2) * .62), macdBottom = Math.floor(mainBottom + gap + (bottom - top - gap * 2) * .20), volTop = macdBottom + gap; const closes = bars.map(bar => Number(bar.close)), lows = bars.map(bar => Number(bar.low)), highs = bars.map(bar => Number(bar.high)), min = Math.min(...lows), max = Math.max(...highs), pad = Math.max((max - min) * .08, .01), yMin = min - pad, yMax = max + pad, x = index => left + (right - left) * (bars.length === 1 ? .5 : index / (bars.length - 1)), y = value => mainBottom - (value - yMin) / (yMax - yMin) * (mainBottom - top);
                    ctx.font = '10px monospace'; ctx.strokeStyle = palette.grid; ctx.fillStyle = palette.text; for (let i = 0; i <= 4; i++) { const gy = top + (mainBottom - top) * i / 4; ctx.beginPath(); ctx.moveTo(left, gy); ctx.lineTo(right, gy); ctx.stroke(); ctx.fillText((yMax - (yMax - yMin) * i / 4).toFixed(2), right + 5, gy + 3); } ctx.strokeStyle = palette.grid; ctx.beginPath(); ctx.moveTo(left, mainBottom + gap / 2); ctx.lineTo(right, mainBottom + gap / 2); ctx.moveTo(left, macdBottom + gap / 2); ctx.lineTo(right, macdBottom + gap / 2); ctx.stroke();
                    const candleWidth = Math.max(1, Math.min(7, (right - left) / Math.max(bars.length, 1) * .65)); bars.forEach((bar, index) => { const cx = x(index), bullish = Number(bar.close) >= Number(bar.open); ctx.strokeStyle = bullish ? palette.bull : palette.bear; ctx.fillStyle = ctx.strokeStyle; ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(cx, y(Number(bar.high))); ctx.lineTo(cx, y(Number(bar.low))); ctx.stroke(); const yo = y(Number(bar.open)), yc = y(Number(bar.close)); ctx.fillRect(cx - candleWidth / 2, Math.min(yo, yc), candleWidth, Math.max(1, Math.abs(yc - yo))); });
                    drawLine(ctx, ema(closes, 20), palette.ma20, 1.2, x, y); drawLine(ctx, ema(closes, 50), palette.ma50, 1.1, x, y); drawLine(ctx, ema(closes, 200), palette.ma200, 1, x, y);
                    const fast = ema(closes, 12), slow = ema(closes, 26), macd = closes.map((_, i) => fast[i] != null && slow[i] != null ? fast[i] - slow[i] : null), valid = macd.filter(value => value != null), signal = ema(valid, 9), signalFull = Array(macd.length - signal.length).fill(null).concat(signal), macdValues = valid.length ? valid.concat(signal.filter(value => value != null)) : [0], macdMin = Math.min(0, ...macdValues), macdMax = Math.max(0, ...macdValues), mapMacd = value => macdBottom - (value - macdMin) / Math.max(macdMax - macdMin, .0001) * (macdBottom - (mainBottom + gap)); ctx.strokeStyle = palette.grid; ctx.beginPath(); ctx.moveTo(left, mapMacd(0)); ctx.lineTo(right, mapMacd(0)); ctx.stroke(); macd.forEach((value, index) => { if (value == null) return; ctx.fillStyle = value >= 0 ? palette.bull : palette.bear; ctx.fillRect(x(index) - candleWidth / 2, mapMacd(0), candleWidth, mapMacd(value) - mapMacd(0)); }); drawLine(ctx, macd, palette.macd, 1.1, x, mapMacd); drawLine(ctx, signalFull, palette.signal, 1.1, x, mapMacd);
                    const volumes = bars.map(bar => Number(bar.volume) || 0), volumeMax = Math.max(...volumes, 1); bars.forEach((bar, index) => { ctx.fillStyle = Number(bar.close) >= Number(bar.open) ? palette.bull : palette.bear; const barHeight = Number(bar.volume) / volumeMax * (bottom - volTop); ctx.fillRect(x(index) - candleWidth / 2, bottom - barHeight, candleWidth, barHeight); });
                    ctx.fillStyle = palette.text; const labels = [0, Math.floor((bars.length - 1) / 2), bars.length - 1]; labels.forEach(index => { if (bars[index]) ctx.fillText(new Date(Number(bars[index].timestamp) * 1000).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }), x(index) - 17, bottom + 12); });
                }
                async function load() { try { chartInFlight = fetch(`/api/market/IHSG/chart-data?range=1D&mode=raw`, { headers: { 'Accept': 'application/json' }, cache: 'no-store' }); const response = await chartInFlight; const payload = await response.json(); if (!response.ok || !payload.success) throw new Error(payload.message || 'Chart IHSG gagal dimuat.'); bars = (payload.bars || []).filter(bar => { const parts = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(Number(bar.timestamp) * 1000)); const minutes = Number(parts.find(part => part.type === 'hour')?.value || 0) * 60 + Number(parts.find(part => part.type === 'minute')?.value || 0); return minutes >= 540 && minutes <= 960; }); notice.textContent = `${payload.market_status_notice || 'Data sesi provider'} · ${bars.length} bar`; draw(); } catch (error) { notice.textContent = 'DATA_NOT_AVAILABLE — chart intraday gagal dimuat.'; draw(); } }
                new ResizeObserver(draw).observe(canvas.parentElement); load();
            })();
            </script>
            <script>
            (() => {
                const root = document.getElementById('ihsgPriceWidget'); if (!root) return;
                const endpoint = root.dataset.endpoint, els = Object.fromEntries(['LastPrice','Change','PreviousClose','Open','High','Low','MarketStatus','DataTs','ServedTs','ConnectionStatus'].map(k => [k, document.getElementById('ihsg' + k)]));
                let timer = null, inFlight = false, sequence = 0, hidden = document.hidden, retryDelay = 0;
                const fmt = value => Number.isFinite(Number(value)) ? Number(value).toLocaleString('id-ID', { maximumFractionDigits: 2 }) : 'N/A';
                const text = value => value == null || value === '' ? 'N/A' : String(value);
                const paint = (response, token) => { if (token !== sequence || !response?.success || !response.data) return; const d = response.data, sign = Number(d.change) > 0 ? 'text-emerald-400' : Number(d.change) < 0 ? 'text-rose-400' : 'text-[#F5F1E8]/70'; els.LastPrice.textContent = fmt(d.last_price); els.Change.textContent = `${d.change == null ? 'N/A' : (Number(d.change) >= 0 ? '+' : '') + fmt(d.change)} poin / ${d.change_pct == null ? 'N/A' : (Number(d.change_pct) >= 0 ? '+' : '') + fmt(d.change_pct) + '%'}`; els.Change.className = `mt-1 font-mono ${sign}`; els.PreviousClose.textContent = fmt(d.previous_close); els.Open.textContent = fmt(d.open); els.High.textContent = fmt(d.high); els.Low.textContent = fmt(d.low); els.MarketStatus.textContent = text(d.market_label || d.market_state); els.DataTs.textContent = text(d.data_ts || d.data_timestamp || d.source_date); els.ServedTs.textContent = text(d.served_ts || response.served_at); els.ConnectionStatus.textContent = 'Data tersambung'; els.ConnectionStatus.className = 'text-emerald-300'; root.dataset.marketState = d.market_state || 'UNKNOWN'; };
                const refresh = async () => { if (hidden || inFlight) return; inFlight = true; const token = ++sequence; const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 8000); try { const response = await fetch(endpoint, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal }); const body = await response.json(); if (!response.ok) { const retryAfter = Number(response.headers.get('Retry-After')); retryDelay = Number.isFinite(retryAfter) && retryAfter > 0 ? Math.min(retryAfter * 1000, 120000) : Math.min(retryDelay ? retryDelay * 2 : 5000, 120000); throw new Error(body.message || 'IHSG request gagal'); } retryDelay = 0; paint(body, token); } catch (error) { if (token === sequence) { els.ConnectionStatus.textContent = 'Data tertunda; nilai terakhir dipertahankan'; els.ConnectionStatus.className = 'text-amber-300'; } } finally { clearTimeout(timeout); inFlight = false; schedule(); } };
                const schedule = () => { clearTimeout(timer); if (!hidden) timer = setTimeout(refresh, retryDelay || (/OPEN|TRADING/i.test(root.dataset.marketState) ? 10000 : 60000)); };
                const show = () => { hidden = false; refresh(); }; const hide = () => { hidden = true; clearTimeout(timer); timer = null; }; document.addEventListener('visibilitychange', () => document.hidden ? hide() : show()); window.addEventListener('pageshow', show); window.addEventListener('pagehide', hide); refresh();
            })();
            </script>
        </x-card>
    </div>

    <!-- 3. Jadwal Laporan & Status Aktual (PRD v3) -->
    <x-card title="Jadwal Laporan & Status Aktual" subtitle="Siklus briefing pasar terjadwal dan status radar intraday">
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @foreach($reportSlots as $slot)
            <div
                class="bg-[#0D2B4E]/60 border border-[#F5F1E8]/10 rounded-lg p-3 space-y-1.5 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="font-mono text-xs font-bold text-[#E5C49B]">{{ $slot['time'] }} WIB</span>
                        @if($slot['status'] === 'COMPLETED' || $slot['status'] === 'SENT')
                        <x-badge variant="success">{{ $slot['status'] === 'SENT' ? 'TERKIRIM' : 'SELESAI' }}</x-badge>
                        @elseif($slot['status'] === 'BLOCKED')
                        <x-badge variant="danger">BLOCKED</x-badge>
                        @elseif($slot['status'] === 'SCHEDULED')
                        <x-badge variant="info">TERJADWAL</x-badge>
                        @elseif($slot['status'] === 'PENDING')
                        <x-badge variant="warning">ANTRE</x-badge>
                        @else
                        <x-badge variant="neutral">{{ $slot['status'] }}</x-badge>
                        @endif
                    </div>
                    <div class="text-xs font-semibold text-[#F5F1E8] truncate">{{ $slot['name'] }}</div>
                    <div class="text-[10px] text-[#F5F1E8]/45 leading-tight mt-1">{{ $slot['note'] }}</div>
                </div>
                @if(isset($slot['intraday_capability']))
                <div
                    class="pt-1.5 border-t border-[#F5F1E8]/5 text-[9px] font-mono {{ str_contains($slot['intraday_capability'], 'QUARANTINED') ? 'text-amber-400/80' : 'text-emerald-400/80' }}">
                    Data: {{ $slot['intraday_capability'] }}
                </div>
                @endif
            </div>
            @endforeach
        </div>
    </x-card>

    <!-- 4. Dua Kolom: Kandidat Terbaru & Tindak Lanjut Rekomendasi -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Kandidat Terbaru (Screening & Rekomendasi) -->
        <x-card title="Kandidat Screening & Analisis Terbaru"
            subtitle="Hasil seleksi strategi BROKER_ACCUMULATION_SCREENING_V1 & sinyal aktif">
            <x-slot:headerAction>
                <a href="{{ route('dashboard.market') }}"
                    class="text-xs font-semibold text-[#E5C49B] hover:underline flex items-center">
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
                                <a href="{{ route('dashboard.ticker', ['ticker' => $cand->ticker]) }}"
                                    class="hover:text-[#E5C49B]">
                                    {{ $cand->ticker }}
                                </a>
                            </td>
                            <td class="py-2.5">
                                @if(str_starts_with($cand->model_name ?? '', 'TELEGRAM_') || ($cand->metadata['origin']
                                ?? '') === 'TELEGRAM_TRADING_PLAN')
                                <span class="font-bold text-[#E5C49B]">
                                    TG {{ $cand->metadata['plan_type'] ?? 'PLAN' }}
                                </span>
                                @else
                                <span
                                    class="font-semibold {{ $cand->direction === 'BULLISH' || $cand->direction === 'UP' ? 'text-emerald-400' : ($cand->direction === 'BEARISH' || $cand->direction === 'DOWN' ? 'text-rose-400' : 'text-[#F5F1E8]/60') }}">
                                    {{ $cand->direction ?? 'ABSTAIN' }}
                                </span>
                                @endif
                            </td>
                            <td class="py-2.5 text-right font-mono tabular-nums text-[#F5F1E8]/80">
                                Rp {{ number_format($cand->reference_price ?? 0, 0, ',', '.') }}
                            </td>
                            <td class="py-2.5 text-right font-mono tabular-nums text-[#E5C49B]">
                                {{ $cand->target_price ? 'Rp ' . number_format($cand->target_price, 0, ',', '.') : 'N/A' }}
                            </td>
                            <td class="py-2.5 text-center font-mono text-[10px]">
                                @if($cand->signal_status === 'PUBLISHED_CONDITIONAL')
                                <span
                                    class="px-2 py-0.5 rounded text-[10px] bg-amber-950/80 text-amber-300 border border-amber-500/30">
                                    BERSYARAT
                                </span>
                                @else
                                <x-badge
                                    variant="{{ $cand->signal_status === 'ACTIVE_SIGNAL' ? 'success' : 'neutral' }}">
                                    {{ $cand->signal_status }}
                                </x-badge>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <x-empty-state title="Belum Ada Kandidat Aktif"
                message="Penyaringan kriteria BROKER_ACCUMULATION_SCREENING_V1 memerlukan kondisi pasar yang memenuhi batas konsentrasi & net flow." />
            @endif
        </x-card>

        <!-- Tindak Lanjut Rekomendasi (Monitoring Horizon) -->
        <x-card title="Tindak Lanjut & Siklus Hidup Rekomendasi"
            subtitle="Pemantauan masa berlaku (5 hari bursa horizon, maks 14 hari kalender)">
            <x-slot:headerAction>
                <a href="{{ route('dashboard.history') }}"
                    class="text-xs font-semibold text-[#E5C49B] hover:underline flex items-center">
                    Rekap Riwayat 30 Hari &rarr;
                </a>
            </x-slot:headerAction>

            @if(isset($activePredictions) && $activePredictions->count() > 0)
            <div class="space-y-3">
                @foreach($activePredictions as $pred)
                <div
                    class="bg-[#0D2B4E]/40 border border-[#F5F1E8]/10 rounded-lg p-3 flex items-center justify-between">
                    <div>
                        <div class="flex items-center space-x-2">
                            <span class="font-mono font-bold text-sm text-[#F5F1E8]">{{ $pred->ticker }}</span>
                            <x-badge variant="{{ $pred->direction === 'BULLISH' ? 'success' : 'neutral' }}">
                                {{ $pred->direction }}</x-badge>
                            @php
                            $planType = $pred->metadata['plan_type'] ?? ($pred->predicted_horizon_days == 1 ? 'SCALPING'
                            : 'SWING');
                            $slot = $pred->metadata['slot'] ?? ($pred->metadata['outbox_id'] == 116 ? '08:30 WIB' :
                            'Slot ' . ($pred->metadata['outbox_id'] ?? 'EOD'));
                            $horizonLabel = ($planType === 'SCALPING' || $pred->predicted_horizon_days == 1) ? 'Horizon
                            1D (Intraday Sesi)' : 'Horizon 5D (T+5)';
                            $targetDateLabel = $pred->expires_at ? \Carbon\Carbon::parse($pred->expires_at)->format('d M
                            H:i') : ($pred->evaluation_due_date ?
                            \Carbon\Carbon::parse($pred->evaluation_due_date)->format('d M') : 'N/A');
                            @endphp
                            <span
                                class="text-[9px] bg-[#E5C49B]/10 text-[#E5C49B] px-1.5 py-0.5 rounded font-mono">{{ $planType }}
                                &bull; {{ $slot }}</span>
                            <span class="text-[10px] text-[#F5F1E8]/40 font-mono">Expiry: {{ $targetDateLabel }}</span>
                        </div>
                        <div class="text-xs text-[#F5F1E8]/60 mt-1">
                            Acuan: Rp {{ number_format($pred->reference_price, 0, ',', '.') }} &bull; Target: Rp
                            {{ number_format($pred->target_price, 0, ',', '.') }} &bull; Invalidation: Rp
                            {{ number_format($pred->invalidation_price, 0, ',', '.') }}
                        </div>
                    </div>
                    <div class="text-right font-mono text-[11px]">
                        <span class="text-[#E5C49B] block">{{ $pred->monitoring_status }}</span>
                        <span class="text-[#F5F1E8]/50 text-[10px]">{{ $horizonLabel }}</span>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <x-empty-state title="Tidak Ada Rekomendasi Aktif Menunggu Evaluasi"
                message="Seluruh rekod sinyal sebelumnya telah berstatus dievaluasi atau abstain. Sistem tidak mempublikasikan sinyal spekulatif tanpa konfirmasi kuantitatif." />
            @endif
        </x-card>
    </div>

    <!-- 5. Berita Penting & Sentimen Pasar Terkini -->
    <x-card title="Berita & Sentimen Pasar Terkini"
        subtitle="Pengamatan peristiwa makro, sektoral, dan keterbukaan informasi emiten">
        <x-slot:headerAction>
            <a href="{{ route('dashboard.news') }}"
                class="text-xs font-semibold text-[#E5C49B] hover:underline flex items-center">
                Lihat Seluruh Berita &rarr;
            </a>
        </x-slot:headerAction>

        @php
        $sampleNews = \App\Models\NewsItem::latest('published_at')->take(4)->get();
        @endphp

        @if($sampleNews->count() > 0)
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach($sampleNews as $n)
            <div
                class="bg-[#0D2B4E]/30 border border-[#F5F1E8]/10 rounded-lg p-3.5 space-y-1.5 hover:border-[#E5C49B]/30 transition">
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
        <x-empty-state title="Belum Ada Berita Terbaru"
            message="Kolektor berita pasar belum merekam artikel dalam siklus EOD berjalan." />
        @endif
    </x-card>

</div>
<script>
    (() => {
        document.querySelectorAll('.mover-tab').forEach(tab => tab.addEventListener('click', () => {
            const group = tab.dataset.group;
            document.querySelectorAll('.mover-tab').forEach(item => {
                const active = item === tab;
                item.setAttribute('aria-selected', active ? 'true' : 'false');
                item.classList.toggle('bg-[#E5C49B]', active);
                item.classList.toggle('text-[#05152B]', active);
                item.classList.toggle('text-[#F5F1E8]/60', !active);
            });
            document.querySelectorAll('.mover-panel').forEach(panel => panel.classList.toggle('hidden',
                panel.dataset.groupPanel !== group));
        }));
    })();

</script>
@endsection
