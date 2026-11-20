import {
    Chart,
    BarController,
    BarElement,
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    TimeScale,
    TimeSeriesScale,
    CategoryScale,
    Tooltip,
    Legend
} from 'chart.js';
import { CandlestickController, CandlestickElement, OhlcController, OhlcElement } from 'chartjs-chart-financial';
import 'chartjs-adapter-date-fns';

Chart.register(
    BarController,
    BarElement,
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    TimeScale,
    TimeSeriesScale,
    CategoryScale,
    Tooltip,
    Legend,
    CandlestickController,
    CandlestickElement,
    OhlcController,
    OhlcElement
);

window.Chart = Chart;
console.log('Chart.js + Financial Candlestick registered successfully.');
