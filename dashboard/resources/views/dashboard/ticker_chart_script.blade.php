@if(($tab ?? 'summary') === 'summary' && $recentBars->isNotEmpty())
<script>
(function renderChart() {
    const canvas = document.getElementById('priceVolumeChart');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    ctx.scale(dpr, dpr);

    const width = rect.width;
    const height = rect.height;

    // Data points from real PHP array
    const data = @json($chartData ?? []);

    if (!data || data.length === 0) return;

    const padding = { top: 20, right: 65, bottom: 25, left: 15 };
    const chartHeight = height - padding.top - padding.bottom;
    const priceHeight = chartHeight * 0.75;
    const volumeHeight = chartHeight * 0.20;
    const chartWidth = width - padding.left - padding.right;

    let minPrice = Infinity;
    let maxPrice = -Infinity;
    let maxVol = 0;

    data.forEach(d => {
        if (d.low > 0 && d.low < minPrice) minPrice = d.low;
        if (d.high > maxPrice) maxPrice = d.high;
        if (d.volume > maxVol) maxVol = d.volume;
    });

    if (minPrice === Infinity) minPrice = 100;
    if (maxPrice === -Infinity) maxPrice = 110;
    const priceBuffer = (maxPrice - minPrice) * 0.05 || 10;
    minPrice -= priceBuffer;
    maxPrice += priceBuffer;

    const n = data.length;
    const barWidth = Math.max(3, Math.min(18, (chartWidth / n) * 0.65));
    const step = chartWidth / n;

    // Draw Price Horizontal Grid Lines
    ctx.strokeStyle = 'rgba(245, 241, 232, 0.06)';
    ctx.lineWidth = 1;
    ctx.fillStyle = 'rgba(245, 241, 232, 0.40)';
    ctx.font = '9px monospace';
    ctx.textAlign = 'left';

    const gridSteps = 4;
    for (let i = 0; i <= gridSteps; i++) {
        const y = padding.top + (priceHeight / gridSteps) * i;
        const p = maxPrice - ((maxPrice - minPrice) / gridSteps) * i;
        ctx.beginPath();
        ctx.moveTo(padding.left, y);
        ctx.lineTo(width - padding.right, y);
        ctx.stroke();
        ctx.fillText('Rp ' + Math.round(p).toLocaleString('id-ID'), width - padding.right + 6, y + 3);
    }

    // Render Candlesticks and Volume Bars
    data.forEach((d, i) => {
        const x = padding.left + i * step + step / 2;
        const isUp = d.close >= d.open;
        const color = isUp ? '#34d399' : '#f87171';

        // Price mapping function
        const getY = (val) => padding.top + priceHeight - ((val - minPrice) / (maxPrice - minPrice)) * priceHeight;

        const yHigh = getY(d.high);
        const yLow = getY(d.low);
        const yOpen = getY(d.open);
        const yClose = getY(d.close);

        // Wick
        ctx.strokeStyle = color;
        ctx.lineWidth = 1.2;
        ctx.beginPath();
        ctx.moveTo(x, yHigh);
        ctx.lineTo(x, yLow);
        ctx.stroke();

        // Body
        ctx.fillStyle = color;
        const bodyTop = Math.min(yOpen, yClose);
        const bodyHeight = Math.max(2, Math.abs(yClose - yOpen));
        ctx.fillRect(x - barWidth / 2, bodyTop, barWidth, bodyHeight);

        // Volume Bar
        if (maxVol > 0) {
            const volY = height - padding.bottom;
            const vHeight = (d.volume / maxVol) * volumeHeight;
            ctx.fillStyle = isUp ? 'rgba(52, 211, 153, 0.35)' : 'rgba(248, 113, 113, 0.35)';
            ctx.fillRect(x - barWidth / 2, volY - vHeight, barWidth, vHeight);
        }

        // X-axis date labels (every 5 bars)
        if (i % Math.ceil(n / 6) === 0 || i === n - 1) {
            ctx.fillStyle = 'rgba(245, 241, 232, 0.40)';
            ctx.font = '9px monospace';
            ctx.textAlign = 'center';
            ctx.fillText(d.date, x, height - padding.bottom + 16);
        }
    });
})();
</script>
@endif
