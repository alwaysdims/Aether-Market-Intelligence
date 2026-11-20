<script>
(function() {
    const ticker = '{{ $ticker }}';
    let currentRange = '1M';
    let currentMode = 'adjusted';
    let currentSub = 'volume';
    let chartDataCache = {};
    let brokerDataCache = null;
    let requestSequence = 0;
    let chartInFlight = null;
    let intradayTimer = null;
    let pageHidden = false;

    let priceChart = null;
    let subChart = null;

    const noticeEl = document.getElementById('chartMarketNotice');
    const badgeEl = document.getElementById('chartModeBadge');
    const footerProvEl = document.getElementById('chartFooterProvenance');
    const footerAtrEl = document.getElementById('footerAtrVal');
    const footerRsiEl = document.getElementById('footerRsiVal');
    const brokerDetailsEl = document.getElementById('brokerSummaryDetails');

    function initControls() {
        document.querySelectorAll('.chart-range-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const range = e.target.getAttribute('data-range');
                if (range === currentRange) return;
                currentRange = range;
                document.querySelectorAll('.chart-range-btn').forEach(b => {
                    b.classList.remove('bg-[#E5C49B]', 'text-[#05152B]');
                    b.classList.add('text-[#F5F1E8]/60');
                });
                e.target.classList.add('bg-[#E5C49B]', 'text-[#05152B]');
                e.target.classList.remove('text-[#F5F1E8]/60');
                if (currentRange === '1D') startIntradayPolling();
                loadAndRender();
            });
        });

        document.querySelectorAll('.chart-mode-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const mode = e.target.getAttribute('data-mode');
                if (mode === currentMode) return;
                currentMode = mode;
                document.querySelectorAll('.chart-mode-btn').forEach(b => {
                    b.classList.remove('bg-[#0A213D]', 'text-[#E5C49B]', 'border', 'border-[#E5C49B]/30');
                    b.classList.add('text-[#F5F1E8]/50');
                });
                e.target.classList.add('bg-[#0A213D]', 'text-[#E5C49B]', 'border', 'border-[#E5C49B]/30');
                e.target.classList.remove('text-[#F5F1E8]/50');
                loadAndRender();
            });
        });

        ['toggleSma20', 'toggleSma50', 'toggleSma200'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', () => {
                    updateMaVisibility();
                });
            }
        });

        const brokerRefresh = document.getElementById('brokerSummaryRefresh');
        if (brokerRefresh) brokerRefresh.addEventListener('click', () => loadBrokerSummary());
        ['brokerTop', 'brokerCodes', 'brokerStartDate', 'brokerEndDate'].forEach(id => {
            const control = document.getElementById(id);
            if (control) control.addEventListener('change', () => {
                if (currentSub === 'broker') loadBrokerSummary();
            });
        });

        document.querySelectorAll('.sub-tab-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const sub = e.target.getAttribute('data-sub');
                if (sub === currentSub) return;
                currentSub = sub;
                document.querySelectorAll('.sub-tab-btn').forEach(b => {
                    b.classList.remove('bg-[#E5C49B]', 'text-[#05152B]');
                    b.classList.add('text-[#F5F1E8]/60', 'bg-[#05152B]');
                });
                e.target.classList.add('bg-[#E5C49B]', 'text-[#05152B]');
                e.target.classList.remove('text-[#F5F1E8]/60', 'bg-[#05152B]');

                if (brokerDetailsEl) {
                    if (sub === 'broker') {
                        brokerDetailsEl.classList.remove('hidden');
                    } else {
                        brokerDetailsEl.classList.add('hidden');
                    }
                }

                const cached = chartDataCache[`${currentRange}_${currentMode}`];
                if (cached) {
                    renderSubChart(cached);
                }
            });
        });
    }

    function mergeBars(previous, incoming) {
        const byTimestamp = new Map((previous || []).map(bar => [String(bar.timestamp ?? bar.date), bar]));
        (incoming || []).forEach(bar => byTimestamp.set(String(bar.timestamp ?? bar.date), bar));
        return Array.from(byTimestamp.values()).sort((left, right) => {
            const a = left.timestamp ?? Date.parse(left.date);
            const b = right.timestamp ?? Date.parse(right.date);
            return a - b;
        });
    }

    async function loadAndRender() {
        const cacheKey = `${currentRange}_${currentMode}`;
        if (noticeEl) noticeEl.textContent = `Memuat data ${currentRange} (${currentMode})...`;
        const sequence = ++requestSequence;
        let data = chartDataCache[cacheKey];
        const shouldFetch = currentRange === '1D' || !data;
        if (shouldFetch) {
            chartInFlight = fetch(`/api/market/${ticker}/chart-data?range=${encodeURIComponent(currentRange)}&mode=${encodeURIComponent(currentMode)}`, {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            }).then(async resp => {
                if (!resp.ok) throw new Error(`HTTP error ${resp.status}`);
                return resp.json();
            });
            try {
                const fresh = await chartInFlight;
                if (sequence !== requestSequence || pageHidden) return;
                if (currentRange === '1D' && data?.bars) fresh.bars = mergeBars(data.bars, fresh.bars);
                data = fresh;
                chartDataCache[cacheKey] = data;
            } catch (err) {
                if (sequence === requestSequence && noticeEl) noticeEl.textContent = 'Gagal memuat data provider.';
                return;
            } finally {
                chartInFlight = null;
            }
        }

        if (sequence !== requestSequence || pageHidden) return;
        renderAll(data);
    }

    function deriveIndicators(data) {
        if (data.indicators && data.indicators.rsi_14 && data.indicators.macd) return data;
        const bars = data.bars || [], closes = bars.map(b => Number(b.close));
        const points = (values) => bars.map((b, i) => ({ date: b.date, value: values[i] ?? null }));
        const sma = period => closes.map((_, i) => i + 1 >= period ? closes.slice(i + 1 - period, i + 1).reduce((a, v) => a + v, 0) / period : null);
        const rsi = closes.map(() => null), gains = [], losses = [];
        for (let i = 1; i < closes.length; i++) { const d = closes[i] - closes[i - 1]; gains.push(Math.max(d, 0)); losses.push(Math.max(-d, 0)); }
        if (gains.length >= 14) { let gain = gains.slice(0, 14).reduce((a, v) => a + v, 0) / 14, loss = losses.slice(0, 14).reduce((a, v) => a + v, 0) / 14; rsi[14] = loss === 0 ? (gain === 0 ? 50 : 100) : 100 - 100 / (1 + gain / loss); for (let i = 15; i < closes.length; i++) { gain = (gain * 13 + gains[i - 1]) / 14; loss = (loss * 13 + losses[i - 1]) / 14; rsi[i] = loss === 0 ? (gain === 0 ? 50 : 100) : 100 - 100 / (1 + gain / loss); } }
        const ema = (period) => { const out = closes.map(() => null); if (closes.length < period) return out; let value = closes.slice(0, period).reduce((a, v) => a + v, 0) / period; out[period - 1] = value; const alpha = 2 / (period + 1); for (let i = period; i < closes.length; i++) { value = alpha * closes[i] + (1 - alpha) * value; out[i] = value; } return out; };
        const e12 = ema(12), e26 = ema(26), macd = closes.map((_, i) => e12[i] !== null && e26[i] !== null ? e12[i] - e26[i] : null), signal = macd.map(() => null); const validMacd = macd.filter(v => v !== null); if (validMacd.length >= 9) { let value = validMacd.slice(0, 9).reduce((a, v) => a + v, 0) / 9, seen = 0; for (let i = 0; i < macd.length; i++) if (macd[i] !== null) { if (seen === 8) signal[i] = value; else if (seen > 8) { value = (2 / 10) * macd[i] + (8 / 10) * value; signal[i] = value; } seen++; } }
        data.indicators = { ...(data.indicators || {}), sma_20: points(sma(20)), sma_50: points(sma(50)), sma_200: points(sma(200)), rsi_14: points(rsi), macd: points(macd), macd_signal: points(signal) };
        data.indicator_timeframe = data.range === '1D' ? 'intraday_60_seconds' : 'daily_adjusted';
        return data;
    }

    function updateSelectedCandle(bar) {
        if (!bar) return;
        const format = value => value == null ? 'N/A' : Number(value).toLocaleString('id-ID', { maximumFractionDigits: 2 });
        document.querySelectorAll('[data-ohlc]').forEach(el => { const key = el.dataset.ohlc; el.textContent = key === 'volume' ? `${format(bar[key])} ${ticker === 'IHSG' ? 'unit provider; bukan poin' : 'lembar'}` : format(bar[key]); });
        const label = document.querySelector('#selectedCandleReadout span'); if (label) label.textContent = `${bar.date || 'Candle'}${dataRangeLabel()}`;
    }

    function dataRangeLabel() { return currentRange === '1D' ? ' · 1 menit' : ` · ${currentRange} daily adjusted`; }

    function renderAll(data) {
        data = deriveIndicators(data);
        if (!data || !data.bars || data.bars.length === 0) {
            if (noticeEl) noticeEl.textContent = 'DATA_NOT_AVAILABLE: Belum ada bar historis.';
            return;
        }

        if (noticeEl) noticeEl.textContent = data.market_status_notice || 'Data EOD historis terverifikasi';
        if (badgeEl) {
            if (data.adjustment_mode === 'UNADJUSTED') {
                badgeEl.textContent = 'RAW (Nominal/Unadjusted)';
                badgeEl.className = 'text-[9px] font-mono px-2 py-0.5 rounded bg-amber-950/80 text-amber-300 border border-amber-500/30';
            } else {
                badgeEl.textContent = 'ADJUSTED (Back-adjusted)';
                badgeEl.className = 'text-[9px] font-mono px-2 py-0.5 rounded bg-emerald-950/80 text-emerald-300 border border-emerald-500/30';
            }
        }

        if (footerProvEl) {
            footerProvEl.textContent = `${data.source || 'IDX Edge PRO'} • ${data.bars.length} bar ditampilkan (${data.latest_bar_date})`;
        }

        const metrics = data.indicators?.current_metrics || {};
        if (footerAtrEl) footerAtrEl.textContent = metrics.atr_14 ? `Rp ${parseFloat(metrics.atr_14).toLocaleString('id-ID', { minimumFractionDigits: 2 })}` : 'N/A';
        if (footerRsiEl) footerRsiEl.textContent = metrics.rsi_14 ? parseFloat(metrics.rsi_14).toFixed(2) : 'N/A';

        renderPriceChart(data);
        renderSubChart(data);
    }

    function renderPriceChart(data) {
        const canvas = document.getElementById('mainPriceChartCanvas');
        if (!canvas) return;

        if (priceChart) {
            priceChart.destroy();
            priceChart = null;
        }

        const bars = data.bars;
        const candleData = bars.map(b => ({
            x: Date.parse(b.date),
            o: b.open,
            h: b.high,
            l: b.low,
            c: b.close
        }));

        const sma20 = (data.indicators?.sma_20 || []).map(p => ({
            x: Date.parse(p.date),
            y: p.value
        })).filter(p => p.y !== null);

        const sma50 = (data.indicators?.sma_50 || []).map(p => ({
            x: Date.parse(p.date),
            y: p.value
        })).filter(p => p.y !== null);

        const sma200 = (data.indicators?.sma_200 || []).map(p => ({
            x: Date.parse(p.date),
            y: p.value
        })).filter(p => p.y !== null);

        const showSma20 = document.getElementById('toggleSma20')?.checked ?? true;
        const showSma50 = document.getElementById('toggleSma50')?.checked ?? true;
        const showSma200 = document.getElementById('toggleSma200')?.checked ?? true;

        const datasets = [
            {
                type: 'candlestick',
                label: `${ticker} Candlestick`,
                data: candleData,
                color: {
                    up: '#34d399',
                    down: '#f87171',
                    unchanged: '#94a3b8'
                },
                borderColor: {
                    up: '#34d399',
                    down: '#f87171',
                    unchanged: '#94a3b8'
                }
            },
            {
                type: 'line',
                label: 'SMA 20',
                data: sma20,
                borderColor: '#fbbf24',
                borderWidth: 1.5,
                pointRadius: 0,
                hidden: !showSma20,
                tension: 0.1
            },
            {
                type: 'line',
                label: 'SMA 50',
                data: sma50,
                borderColor: '#38bdf8',
                borderWidth: 1.5,
                pointRadius: 0,
                hidden: !showSma50,
                tension: 0.1
            },
            {
                type: 'line',
                label: 'SMA 200',
                data: sma200,
                borderColor: '#f43f5e',
                borderWidth: 1.5,
                pointRadius: 0,
                hidden: !showSma200,
                tension: 0.1
            }
        ];

        priceChart = new window.Chart(canvas.getContext('2d'), {
            type: 'candlestick',
            data: { datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                onHover: (event, active) => {
                    const point = active?.find(item => item.dataset?.type === 'candlestick' || item.dataset?.index === 0);
                    updateSelectedCandle(point ? bars[point.index] : bars[bars.length - 1]);
                },
                scales: {
                    x: {
                        type: 'time',
                        time: {
                            unit: currentRange === '1Y' ? 'month' : 'day',
                            displayFormats: {
                                day: 'dd MMM',
                                month: 'MMM yyyy'
                            }
                        },
                        grid: {
                            color: 'rgba(245, 241, 232, 0.05)'
                        },
                        ticks: {
                            color: 'rgba(245, 241, 232, 0.40)',
                            font: { family: 'monospace', size: 9 },
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 8
                        }
                    },
                    y: {
                        position: 'right',
                        grid: {
                            color: 'rgba(245, 241, 232, 0.05)'
                        },
                        ticks: {
                            color: 'rgba(245, 241, 232, 0.50)',
                            font: { family: 'monospace', size: 9 },
                            callback: (v) => 'Rp ' + Number(v).toLocaleString('id-ID')
                        }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0A213D',
                        titleColor: '#E5C49B',
                        titleFont: { family: 'monospace', size: 10, weight: 'bold' },
                        bodyColor: '#F5F1E8',
                        bodyFont: { family: 'monospace', size: 10 },
                        borderColor: 'rgba(229, 196, 155, 0.3)',
                        borderWidth: 1,
                        padding: 8,
                        callbacks: {
                            label: function(ctx) {
                                if (ctx.dataset.type === 'candlestick') {
                                    const d = ctx.raw;
                                    return [
                                        `O: Rp ${d.o?.toLocaleString('id-ID')}`,
                                        `H: Rp ${d.h?.toLocaleString('id-ID')}`,
                                        `L: Rp ${d.l?.toLocaleString('id-ID')}`,
                                        `C: Rp ${d.c?.toLocaleString('id-ID')}`
                                    ];
                                }
                                return `${ctx.dataset.label}: Rp ${Number(ctx.raw.y).toLocaleString('id-ID')}`;
                            }
                        }
                    }
                }
            }
        });
        attachPriceChartNavigation(canvas, bars);
        updateSelectedCandle(bars[bars.length - 1]);
    }

    function attachPriceChartNavigation(canvas, bars) {
        const chart = priceChart;
        if (!chart || !canvas) return;
        canvas.__navigationCleanup?.();
        const timestamps = bars.map(bar => Date.parse(bar.date)).filter(Number.isFinite);
        const fullMin = Math.min(...timestamps);
        const fullMax = Math.max(...timestamps);
        let dragging = false;
        let lastX = 0;
        let pointers = new Map();
        let pinchDistance = null;
        const clampRange = (min, max) => {
            const span = Math.max(86400000, max - min);
            const fullSpan = Math.max(86400000, fullMax - fullMin);
            if (span >= fullSpan) return [fullMin, fullMax];
            if (min < fullMin) { max += fullMin - min; min = fullMin; }
            if (max > fullMax) { min -= max - fullMax; max = fullMax; }
            return [Math.max(fullMin, min), Math.min(fullMax, max)];
        };
        const setRange = (min, max) => {
            [min, max] = clampRange(min, max);
            chart.options.scales.x.min = min;
            chart.options.scales.x.max = max;
            chart.update('none');
        };
        const currentRange = () => {
            const scale = chart.scales.x;
            return [scale.min ?? fullMin, scale.max ?? fullMax];
        };
        const onWheel = event => {
            event.preventDefault();
            const [min, max] = currentRange();
            const factor = event.deltaY < 0 ? 0.8 : 1.25;
            const center = (min + max) / 2;
            const half = (max - min) * factor / 2;
            setRange(center - half, center + half);
        };
        const onPointerDown = event => {
            canvas.setPointerCapture(event.pointerId);
            pointers.set(event.pointerId, event);
            if (pointers.size === 1) { dragging = true; lastX = event.clientX; }
            if (pointers.size === 2) pinchDistance = Math.abs([...pointers.values()][0].clientX - [...pointers.values()][1].clientX);
        };
        const onPointerMove = event => {
            if (!pointers.has(event.pointerId)) return;
            pointers.set(event.pointerId, event);
            if (pointers.size === 2 && pinchDistance) {
                const points = [...pointers.values()];
                const distance = Math.abs(points[0].clientX - points[1].clientX);
                const [min, max] = currentRange();
                const center = (min + max) / 2;
                const factor = pinchDistance / Math.max(1, distance);
                const half = (max - min) * Math.min(2, Math.max(0.5, factor)) / 2;
                setRange(center - half, center + half);
                pinchDistance = distance;
            } else if (dragging) {
                const [min, max] = currentRange();
                const delta = (event.clientX - lastX) * (max - min) / Math.max(1, canvas.clientWidth);
                setRange(min - delta, max - delta);
                lastX = event.clientX;
            }
        };
        const release = event => { pointers.delete(event.pointerId); dragging = false; if (pointers.size < 2) pinchDistance = null; };
        canvas.addEventListener('wheel', onWheel, { passive: false });
        canvas.addEventListener('pointerdown', onPointerDown);
        canvas.addEventListener('pointermove', onPointerMove);
        canvas.addEventListener('pointerup', release);
        canvas.addEventListener('pointercancel', release);
        canvas.addEventListener('pointerleave', release);
        canvas.__navigationCleanup = () => {
            canvas.removeEventListener('wheel', onWheel);
            canvas.removeEventListener('pointerdown', onPointerDown);
            canvas.removeEventListener('pointermove', onPointerMove);
            canvas.removeEventListener('pointerup', release);
            canvas.removeEventListener('pointercancel', release);
            canvas.removeEventListener('pointerleave', release);
        };
    }

    function updateMaVisibility() {
        if (!priceChart) return;
        priceChart.data.datasets[1].hidden = !document.getElementById('toggleSma20')?.checked;
        priceChart.data.datasets[2].hidden = !document.getElementById('toggleSma50')?.checked;
        priceChart.data.datasets[3].hidden = !document.getElementById('toggleSma200')?.checked;
        priceChart.update();
    }

    async function renderSubChart(data) {
        const canvas = document.getElementById('subIndicatorChartCanvas');
        if (!canvas) return;

        if (subChart) {
            subChart.destroy();
            subChart = null;
        }

        const bars = data.bars;

        if (currentSub === 'volume') {
            const volData = bars.map(b => ({
                x: Date.parse(b.date),
                y: b.volume,
                color: b.close >= b.open ? 'rgba(52, 211, 153, 0.5)' : 'rgba(248, 113, 113, 0.5)'
            }));

            subChart = new window.Chart(canvas.getContext('2d'), {
                type: 'bar',
                data: {
                    datasets: [{
                        label: 'Volume (lembar)',
                        data: volData,
                        backgroundColor: volData.map(d => d.color),
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    scales: {
                        x: {
                            type: 'time',
                            grid: { color: 'rgba(245, 241, 232, 0.05)' },
                            ticks: { display: false }
                        },
                        y: {
                            position: 'right',
                            grid: { color: 'rgba(245, 241, 232, 0.05)' },
                            ticks: {
                                color: 'rgba(245, 241, 232, 0.40)',
                                font: { family: 'monospace', size: 8 },
                                callback: (v) => {
                                    if (v >= 1e6) return (v / 1e6).toFixed(1) + 'M';
                                    if (v >= 1e3) return (v / 1e3).toFixed(0) + 'K';
                                    return v;
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0A213D',
                            titleColor: '#E5C49B',
                            bodyColor: '#F5F1E8',
                            bodyFont: { family: 'monospace', size: 10 },
                            callbacks: {
                                label: (ctx) => `Volume: ${Number(ctx.raw.y).toLocaleString('id-ID')} lembar`
                            }
                        }
                    }
                }
            });
        } else if (currentSub === 'rsi') {
            const rsiPoints = (data.indicators?.rsi_14 || []).map(p => ({
                x: Date.parse(p.date),
                y: p.value
            })).filter(p => p.y !== null);

            subChart = new window.Chart(canvas.getContext('2d'), {
                type: 'line',
                data: {
                    datasets: [
                        {
                            label: 'RSI (14)',
                            data: rsiPoints,
                            borderColor: '#38bdf8',
                            borderWidth: 1.5,
                            pointRadius: 0
                        },
                        {
                            label: 'Overbought (70)',
                            data: [{ x: rsiPoints[0]?.x, y: 70 }, { x: rsiPoints[rsiPoints.length - 1]?.x, y: 70 }],
                            borderColor: 'rgba(248, 113, 113, 0.4)',
                            borderDash: [4, 4],
                            borderWidth: 1,
                            pointRadius: 0
                        },
                        {
                            label: 'Oversold (30)',
                            data: [{ x: rsiPoints[0]?.x, y: 30 }, { x: rsiPoints[rsiPoints.length - 1]?.x, y: 30 }],
                            borderColor: 'rgba(52, 211, 153, 0.4)',
                            borderDash: [4, 4],
                            borderWidth: 1,
                            pointRadius: 0
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    scales: {
                        x: { type: 'time', ticks: { display: false }, grid: { color: 'rgba(245, 241, 232, 0.05)' } },
                        y: {
                            position: 'right',
                            min: 0,
                            max: 100,
                            grid: { color: 'rgba(245, 241, 232, 0.05)' },
                            ticks: { color: 'rgba(245, 241, 232, 0.40)', font: { family: 'monospace', size: 8 } }
                        }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        } else if (currentSub === 'macd') {
            const macdPoints = (data.indicators?.macd || []).map(p => ({ x: Date.parse(p.date), y: p.value })).filter(p => p.y !== null);
            const sigPoints = (data.indicators?.macd_signal || []).map(p => ({ x: Date.parse(p.date), y: p.value })).filter(p => p.y !== null);
            const signalByDate = new Map(sigPoints.map(p => [p.x, p.y]));
            const histPoints = macdPoints.map(p => {
                const histogram = p.y - (signalByDate.get(p.x) ?? 0);
                return {
                    x: p.x,
                    y: histogram,
                    color: histogram >= 0 ? 'rgba(52, 211, 153, 0.6)' : 'rgba(248, 113, 113, 0.6)'
                };
            });

            subChart = new window.Chart(canvas.getContext('2d'), {
                type: 'bar',
                data: {
                    datasets: [
                        {
                            type: 'bar',
                            label: 'Histogram',
                            data: histPoints,
                            backgroundColor: histPoints.map(p => p.color)
                        },
                        {
                            type: 'line',
                            label: 'MACD',
                            data: macdPoints,
                            borderColor: '#E5C49B',
                            borderWidth: 1.5,
                            pointRadius: 0
                        },
                        {
                            type: 'line',
                            label: 'Signal',
                            data: sigPoints,
                            borderColor: '#38bdf8',
                            borderWidth: 1.2,
                            pointRadius: 0
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    scales: {
                        x: { type: 'time', ticks: { display: false }, grid: { color: 'rgba(245, 241, 232, 0.05)' } },
                        y: {
                            position: 'right',
                            grid: { color: 'rgba(245, 241, 232, 0.05)' },
                            ticks: { color: 'rgba(245, 241, 232, 0.40)', font: { family: 'monospace', size: 8 } }
                        }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        } else if (currentSub === 'broker') {
            if (currentRange === '1D') {
                const brokerTable = document.getElementById('brokerSummaryTable');
                if (brokerTable) brokerTable.innerHTML = '<p class="p-3 text-[10px] text-[#F5F1E8]/60">Akumulasi broker historis tersedia mulai timeframe 1M. Timeframe 1D tidak memiliki kurva akumulasi broker.</p>';
                const brokerStatus = document.getElementById('brokerSummaryStatus');
                if (brokerStatus) brokerStatus.textContent = 'DATA_NOT_APPLICABLE_FOR_1D';
                return;
            }
            await loadBrokerSummary();
            if (brokerDataCache && brokerDataCache.series && brokerDataCache.series.length > 0) {
                const colors = ['#38bdf8', '#fbbf24', '#34d399', '#f43f5e', '#a855f7'];
                const datasets = brokerDataCache.series.map((s, idx) => ({
                    type: 'line',
                    label: `${s.broker_code} (${s.broker_name || ''})`,
                    data: s.points.map(p => ({
                        x: Date.parse(p.date),
                        y: p.cum_nval / 1e9
                    })),
                    borderColor: colors[idx % colors.length],
                    borderWidth: 1.5,
                    pointRadius: 0,
                    tension: 0.1
                }));

                subChart = new window.Chart(canvas.getContext('2d'), {
                    type: 'line',
                    data: { datasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: false,
                        scales: {
                            x: {
                                type: 'time',
                                ticks: { display: false },
                                grid: { color: 'rgba(245, 241, 232, 0.05)' }
                            },
                            y: {
                                position: 'right',
                                grid: { color: 'rgba(245, 241, 232, 0.05)' },
                                ticks: {
                                    color: 'rgba(245, 241, 232, 0.40)',
                                    font: { family: 'monospace', size: 8 },
                                    callback: (v) => v.toFixed(0) + ' M'
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    boxWidth: 8,
                                    font: { family: 'monospace', size: 9 },
                                    color: '#F5F1E8'
                                }
                            },
                            tooltip: {
                                backgroundColor: '#0A213D',
                                titleColor: '#E5C49B',
                                bodyFont: { family: 'monospace', size: 10 },
                                callbacks: {
                                    label: (ctx) => `${ctx.dataset.label}: Rp ${Number(ctx.raw.y).toFixed(2)} Miliar kumulatif`
                                }
                            }
                        }
                    }
                });

                renderBrokerTable(brokerDataCache);
            } else {
                const brokerTable = document.getElementById('brokerSummaryTable');
                if (brokerTable) brokerTable.innerHTML = '<p class="p-3 text-[10px] text-[#F5F1E8]/60">DATA_NOT_AVAILABLE — tren akumulasi broker historis belum tersedia untuk periode ini.</p>';
                if (brokerDetailsEl && !brokerTable?.innerHTML) brokerDetailsEl.innerHTML = `<p class="text-xs text-[#F5F1E8]/50 font-mono text-center py-2">Data broker belum tersedia untuk periode ini.</p>`;
            }
        }
    }

    let brokerSummarySequence = 0;
    async function loadBrokerSummary() {
        const table = document.getElementById('brokerSummaryTable');
        const status = document.getElementById('brokerSummaryStatus');
        const refresh = document.getElementById('brokerSummaryRefresh');
        if (!table || !status || !refresh) return;
        const sequence = ++brokerSummarySequence;
        const params = new URLSearchParams({
        top: document.getElementById('brokerTop')?.value || '3'
        });
        const brokers = document.getElementById('brokerCodes')?.value?.trim();
        if (brokers) params.set('brokers', brokers);
        const start = document.getElementById('brokerStartDate')?.value;
        const end = document.getElementById('brokerEndDate')?.value;
        if (start) params.set('start_date', start);
        if (end) params.set('end_date', end);
        if (!start && !end) {
            const chartData = chartDataCache[`${currentRange}_${currentMode}`] || {};
            const endDate = chartData.latest_bar_date || chartData.bars?.at(-1)?.date;
            const months = { '1M': 1, '3M': 3, '6M': 6, '1Y': 12 }[currentRange];
            if (endDate && months) {
                const endValue = new Date(`${endDate}T00:00:00Z`);
                const startValue = new Date(endValue);
                startValue.setUTCMonth(startValue.getUTCMonth() - months);
                params.set('start_date', startValue.toISOString().slice(0, 10));
                params.set('end_date', endDate.slice(0, 10));
            }
        }
        status.textContent = `Memuat tren akumulasi broker (${currentRange})...`;
        refresh.disabled = true;
        try {
            const response = await fetch(`/api/market/${encodeURIComponent(ticker)}/broker-accumulation?${params}`, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            const payload = await response.json();
            if (sequence !== brokerSummarySequence) return;
            if (!response.ok || !payload.success) throw new Error(payload.message || payload.error_type || `HTTP ${response.status}`);
            brokerDataCache = payload.data;
            renderBrokerSummary(payload.data);
            status.textContent = `Sumber: IDX Edge API · Periode: ${payload.data.start_date || 'N/A'}–${payload.data.end_date || 'N/A'} · Top buyer/seller: ${payload.data.top_buyers?.length || 0}/${payload.data.top_sellers?.length || 0}`;
        } catch (error) {
            if (sequence === brokerSummarySequence) {
                table.innerHTML = `<p class="p-3 text-xs text-amber-300">GAGAL_MEMUAT — ${String(error.message).replace(/[<>&]/g, '')}</p>`;
                status.textContent = 'Provider failure; bukan empty state.';
            }
        } finally {
            refresh.disabled = false;
        }
    }

    function renderBrokerSummary(data) {
        const table = document.getElementById('brokerSummaryTable');
        if (!table) return;
        const escape = value => String(value ?? 'N/A').replace(/[<>&"']/g, char => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#39;' }[char]));
        const money = value => value === null || value === undefined || !Number.isFinite(Number(value)) ? 'N/A' : `Rp ${Number(value).toLocaleString('id-ID')}`;
        const series = Array.isArray(data.series) ? data.series : [];
        const latest = series.length ? series[series.length - 1] : null;
        const latestDate = latest?.date || data.end_date || 'N/A';
        const brokerTotals = series.map(item => {
            const points = Array.isArray(item?.points) ? item.points : [];
            const point = points.length ? points[points.length - 1] : null;
            return { broker_code: item?.broker_code, broker_name: item?.broker_name, cum_nval: point?.cum_nval ?? null };
        }).filter(item => item.broker_code);
        const providerCodes = items => items.map(item => typeof item === 'string' ? item : item?.broker_code).filter(Boolean);
        const buyerCodes = providerCodes(Array.isArray(data.top_buyers) ? data.top_buyers : []);
        const sellerCodes = providerCodes(Array.isArray(data.top_sellers) ? data.top_sellers : []);
        const buyers = (buyerCodes.length ? brokerTotals.filter(item => buyerCodes.includes(item.broker_code)) : brokerTotals.filter(item => Number(item.cum_nval) > 0)).sort((left, right) => Number(right.cum_nval || 0) - Number(left.cum_nval || 0));
        const sellers = (sellerCodes.length ? brokerTotals.filter(item => sellerCodes.includes(item.broker_code)) : brokerTotals.filter(item => Number(item.cum_nval) < 0)).sort((left, right) => Number(left.cum_nval || 0) - Number(right.cum_nval || 0));
        const rows = (items, color) => items.map(item => {
            const code = typeof item === 'string' ? item : item?.broker_code;
            const name = typeof item === 'string' ? '' : item?.broker_name;
            const value = typeof item === 'object' ? (item?.cum_nval ?? item?.net_value) : null;
            return `<tr class="border-b border-[#F5F1E8]/5"><td class="p-2 font-bold ${color}">${escape(code)}</td><td class="p-2">${escape(name || '-')}</td><td class="p-2 text-right tabular-nums">${money(value)}</td></tr>`;
        }).join('') || '<tr><td colspan="3" class="p-3 text-center text-[#F5F1E8]/50">PROVIDER_EMPTY</td></tr>';
        const tableFor = (title, items, color) => `<section><h4 class="bg-[#071C35] p-2 text-[10px] font-bold uppercase">${title}</h4><table class="w-full text-[10px] font-mono"><thead class="bg-[#0A213D]"><tr><th class="p-2 text-left">Broker</th><th class="p-2 text-left">Nama</th><th class="p-2 text-right">Nilai kumulatif</th></tr></thead><tbody>${rows(items, color)}</tbody></table></section>`;
        table.innerHTML = `<div class="mb-2 text-[10px] text-[#F5F1E8]/60">As-of ${escape(latestDate)} · ${series.length} titik harian · Source: IDX Edge API</div><div class="grid grid-cols-1 md:grid-cols-2 gap-3">${tableFor('Top Akumulasi', buyers, 'text-emerald-400')}${tableFor('Top Distribusi', sellers, 'text-rose-400')}</div><p class="p-2 text-[10px] text-[#F5F1E8]/50">Net flow historis provider. Tidak teramati dalam Top-N bukan bukti broker berhenti membeli. Cakupan dan board mengikuti provider.</p>`;
    }

    function renderBrokerTable(brokerData) {
        renderBrokerSummary(brokerData);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initControls();
            loadAndRender();
        });
    } else {
        initControls();
        loadAndRender();
    }

    function startIntradayPolling() {
        if (intradayTimer || ticker === 'IHSG' && currentRange !== '1D') return;
        intradayTimer = window.setInterval(() => {
            if (!pageHidden && currentRange === '1D' && !chartInFlight) {
                delete chartDataCache[`1D_${currentMode}`];
                loadAndRender();
            }
        }, 30000);
    }

    window.addEventListener('pagehide', () => {
        pageHidden = true;
        if (intradayTimer) window.clearInterval(intradayTimer);
        intradayTimer = null;
    }, { once: true });
    window.addEventListener('pageshow', () => {
        pageHidden = false;
        if (currentRange === '1D') {
            delete chartDataCache[`1D_${currentMode}`];
            loadAndRender();
        }
        startIntradayPolling();
    });
    startIntradayPolling();
})();
</script>
