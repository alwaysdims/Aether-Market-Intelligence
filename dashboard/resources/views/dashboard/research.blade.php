@extends('layouts.app')

@section('page_title', 'Riset AI')

@section('content')
<div class="space-y-6 max-w-[1280px] mx-auto" id="research-page">
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-xl p-6">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
            <div>
                <p class="text-[10px] font-mono uppercase tracking-[0.2em] text-[#E5C49B]">Evidence-driven research</p>
                <h1 class="mt-2 text-2xl font-bold text-[#F5F1E8]">Riset AI</h1>
                <p class="mt-2 max-w-2xl text-sm leading-relaxed text-[#F5F1E8]/60">Permintaan masuk queue backend. Halaman hanya menampilkan status, evidence, provenance, dan hasil yang sudah tersimpan.</p>
            </div>
            <span class="px-2.5 py-1 rounded border border-amber-500/30 bg-amber-950/40 text-[10px] font-mono text-amber-300">NO LIVE BROKER ORDER</span>
        </div>
    </div>

    <form id="researchForm" class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-xl p-5 space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="researchCompanySearch" class="block text-[10px] font-mono uppercase text-[#F5F1E8]/50 mb-1">Cari emiten</label>
                <input id="researchCompanySearch" type="search" autocomplete="off" placeholder="Cari kode atau nama perusahaan..." class="w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded-lg px-3 py-2.5 text-sm text-[#F5F1E8] placeholder-[#F5F1E8]/30 focus:outline-none focus:border-[#E5C49B]">
                <label for="researchTicker" class="sr-only">Emiten</label>
                <select id="researchTicker" required class="mt-2 h-11 w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded-lg px-3 py-2 text-sm text-[#F5F1E8] focus:outline-none focus:border-[#E5C49B] focus:ring-1 focus:ring-[#E5C49B]/50">
                    <option value="">Pilih emiten</option>
                    @foreach($companies as $company)
                        <option value="{{ $company->ticker }}" data-search="{{ strtolower($company->ticker.' '.$company->company_name) }}" {{ $ticker === $company->ticker ? 'selected' : '' }}>{{ $company->ticker }} — {{ $company->company_name }}</option>
                    @endforeach
                </select>
                <p id="researchCompanyCount" class="mt-1 text-[10px] font-mono text-[#F5F1E8]/40">{{ $companies->count() }} emiten dari registry DB</p>
            </div>
            <div>
                <label for="researchType" class="block text-[10px] font-mono uppercase text-[#F5F1E8]/50 mb-1">Jenis analisis</label>
                <select id="researchType" class="w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded-lg px-3 py-2.5 text-sm text-[#F5F1E8] focus:outline-none focus:border-[#E5C49B]">
                    <option value="COMPREHENSIVE">Komprehensif</option>
                    <option value="TECHNICAL">Teknikal</option>
                    <option value="FUNDAMENTAL">Fundamental</option>
                    <option value="SWING">Swing</option>
                </select>
            </div>
        </div>
        <div>
            <label for="researchQuestion" class="block text-[10px] font-mono uppercase text-[#F5F1E8]/50 mb-1">Pertanyaan opsional</label>
            <textarea id="researchQuestion" maxlength="1000" rows="3" placeholder="Contoh: Apa keterbatasan data teknikal sesi terakhir?" class="w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded-lg px-3 py-2.5 text-sm text-[#F5F1E8] placeholder-[#F5F1E8]/30 focus:outline-none focus:border-[#E5C49B]"></textarea>
        </div>
        <div class="flex items-center justify-between gap-3 border-t border-[#F5F1E8]/10 pt-4">
            <p id="researchFormNote" class="text-xs text-[#F5F1E8]/50">Request ID dan hasil tersimpan dapat dipulihkan setelah reload.</p>
            <button id="researchSubmit" type="submit" class="shrink-0 px-4 py-2.5 bg-[#E5C49B] hover:bg-[#D6B284] text-[#05152B] rounded-lg text-xs font-semibold">Mulai Analisis</button>
        </div>
    </form>

    <section id="researchProgress" class="hidden bg-[#0A213D] border border-[#F5F1E8]/10 rounded-xl p-5" aria-live="polite">
        <div class="flex items-center justify-between gap-3 mb-4">
            <div><h2 class="text-sm font-bold uppercase tracking-wide">Progres backend</h2><p id="researchRequestId" class="mt-1 text-[10px] font-mono text-[#E5C49B]"></p></div>
            <span id="researchStatus" class="px-2 py-1 rounded border border-[#F5F1E8]/15 text-[10px] font-mono text-[#F5F1E8]/70">-</span>
        </div>
        <div id="researchStages" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2"></div>
        <p id="researchStageDetail" class="mt-4 text-xs text-[#F5F1E8]/60"></p>
    </section>

    <section id="researchResult" class="hidden space-y-4">
        <div class="flex items-center justify-between gap-3"><h2 class="text-lg font-bold">Hasil tersimpan</h2><div class="flex items-center gap-3"><a id="researchHistoryLink" href="{{ route('dashboard.history') }}" class="text-[10px] font-mono text-[#E5C49B] hover:underline">Buka History</a><span id="researchResultScope" class="text-[10px] font-mono text-amber-300"></span></div></div>
        <div id="researchResultCards" class="grid grid-cols-1 md:grid-cols-2 gap-4"></div>
    </section>
</div>

<script>
(() => {
    const form = document.getElementById('researchForm');
    const ticker = document.getElementById('researchTicker');
    const companySearch = document.getElementById('researchCompanySearch');
    const companyCount = document.getElementById('researchCompanyCount');
    const type = document.getElementById('researchType');
    const question = document.getElementById('researchQuestion');
    const submit = document.getElementById('researchSubmit');
    const progress = document.getElementById('researchProgress');
    const requestId = document.getElementById('researchRequestId');
    const status = document.getElementById('researchStatus');
    const stages = document.getElementById('researchStages');
    const detail = document.getElementById('researchStageDetail');
    const result = document.getElementById('researchResult');
    const resultScope = document.getElementById('researchResultScope');
    const cards = document.getElementById('researchResultCards');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const capability = document.querySelector('meta[name="aether-research-auth"]').content;
    const storageKey = 'aether.research.request_id';
    const terminal = new Set(['COMPLETED', 'PARTIAL', 'FAILED', 'MISSED', 'DEADLINE_EXCEEDED']);
    let pollToken = 0;

    const stageOrder = [
        ['queued', 'Diterima / antre'],
        ['data_check', 'Pemeriksaan data / API'],
        ['indicators', 'Kalkulasi indikator'],
        ['synthesis', 'Evidence / sintesis'],
        ['completed', 'Selesai']
    ];

    function text(value) { return value === null || value === undefined || value === '' ? '-' : String(value); }
    function card(title, value, tone = 'default') {
        const el = document.createElement('article');
        const tones = {
            default: 'border-[#F5F1E8]/10 bg-[#0A213D]',
            accent: 'border-[#E5C49B]/30 bg-[#0D2B4E]',
            warning: 'border-amber-500/30 bg-amber-950/20'
        };
        el.className = `rounded-xl border p-4 ${tones[tone] || tones.default}`;
        const h = document.createElement('h3'); h.className = 'text-[10px] font-mono uppercase tracking-[0.12em] text-[#E5C49B]'; h.textContent = title;
        const p = document.createElement('p'); p.className = 'mt-3 whitespace-pre-wrap break-words text-sm leading-6 text-[#F5F1E8]/80'; p.textContent = text(value);
        el.append(h, p); return el;
    }
    function renderStages(currentKey, currentStatus) {
        stages.replaceChildren();
        const active = stageOrder.findIndex(item => item[0] === currentKey);
        stageOrder.forEach((item, index) => {
            const el = document.createElement('div');
            const reached = currentStatus === 'COMPLETED' || (active >= 0 && index <= active);
            el.className = `rounded-lg border p-3 ${reached ? 'border-[#E5C49B]/40 bg-[#E5C49B]/10' : 'border-[#F5F1E8]/10 bg-[#071C35]'}`;
            const label = document.createElement('div'); label.className = 'text-xs font-semibold'; label.textContent = item[1];
            const state = document.createElement('div'); state.className = 'mt-1 text-[10px] font-mono text-[#F5F1E8]/50'; state.textContent = reached ? 'Tahap selesai' : 'Menunggu';
            el.append(label, state); stages.appendChild(el);
        });
        if (currentStatus === 'PARTIAL' || currentStatus === 'FAILED' || currentStatus === 'DEADLINE_EXCEEDED') {
            const el = document.createElement('div'); el.className = 'rounded-lg border border-amber-500/30 bg-amber-950/20 p-3';
            const label = document.createElement('div'); label.className = 'text-xs font-semibold text-amber-200'; label.textContent = currentStatus === 'PARTIAL' ? 'Selesai sebagian' : 'Jalur terminal non-sukses';
            const state = document.createElement('div'); state.className = 'mt-1 text-[10px] font-mono text-amber-300/70'; state.textContent = 'STATUS_BACKEND_NYATA'; el.append(label, state); stages.appendChild(el);
        }
    }
    function human(value) {
        const labels = {
            PARTIAL_COMPONENTS: 'Selesai dengan data terbatas',
            PARTIAL_NOT_FULLY_VERIFIED: 'Selesai dengan data terbatas',
            PARTIAL: 'Selesai dengan data terbatas',
            COMPLETED: 'Selesai',
            AVAILABLE_FRESH: 'Data acuan tersedia dan segar',
            NOT_FETCHED: 'Belum diambil',
            MODEL_NOT_VALIDATED_FOR_INSTRUMENT: 'Model belum divalidasi untuk emiten ini',
            NO_ACTIVE_INFERENCE_FOR_BAR: 'Tidak ada inferensi aktif untuk tanggal bar',
            NOT_FETCHED_OR_UNKNOWN: 'Belum diambil atau belum diketahui'
        };
        return labels[value] || text(value);
    }
    function line(label, value) { return `${label}: ${human(value)}`; }
    function number(value) {
        return value === null || value === undefined || value === '' || Number.isNaN(Number(value))
            ? 'N/A'
            : new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(Number(value));
    }
    function normalizeReport(report, type) {
        if (!report || typeof report !== 'object') return { type, status: 'NOT_AVAILABLE', items: [], currency: null, scale: null };
        const raw = report.snapshot && typeof report.snapshot === 'object' ? report.snapshot : report;
        let items = Array.isArray(raw.items) ? raw.items : [];
        if (!items.length && raw.reports && typeof raw.reports === 'object') {
            items = Object.entries(raw.reports).map(([period, data]) => ({ period, data }));
        }
        if (!items.length && raw.data && typeof raw.data === 'object' && !Array.isArray(raw.data)) {
            items = [{ period: raw.period || raw.year || 'N/A', data: raw.data }];
        }
        const periodRank = item => {
            const label = String(item.label || item.period || item.year || '');
            const year = Number((label.match(/(?:19|20)\d{2}/) || [0])[0]);
            const quarter = Number((label.match(/Q([1-4])/i) || [0, 0])[1]);
            const date = Date.parse(label);
            return [year, quarter, Number.isNaN(date) ? 0 : date];
        };
        items.sort((a, b) => {
            const ar = periodRank(a); const br = periodRank(b);
            return br[0] - ar[0] || br[1] - ar[1] || br[2] - ar[2];
        });
        return { type, status: report.status || raw.status || 'AVAILABLE', items, currency: raw.currency || report.currency || null, scale: raw.scale || raw.unit || report.scale || null, source: raw.source || report.provenance || null };
    }
    function reportValue(report, aliases) {
        const aliasesLower = aliases.map(alias => alias.toLowerCase());
        for (const item of report.items) {
            const walk = value => {
                if (!value || typeof value !== 'object') return null;
                for (const [key, candidate] of Object.entries(value)) {
                    if (aliasesLower.includes(key.toLowerCase()) && (typeof candidate === 'number' || (typeof candidate === 'string' && candidate.trim() !== '' && !Number.isNaN(Number(candidate))))) return { key, value: Number(candidate), period: item.period || item.label || item.year || 'N/A' };
                }
                for (const child of Object.values(value)) { const found = walk(child); if (found) return found; }
                return null;
            };
            const found = walk(item.data || item);
            if (found) return found;
        }
        return null;
    }
    function annualReportRows(report, aliases) {
        return report.items.map(item => {
            const period = item.year || item.period || item.label || 'N/A';
            const value = reportValue({ items: [item] }, aliases);
            return `${period}: ${value ? number(value.value) : 'N/A'}`;
        }).join('\n') || 'N/A';
    }
    function cleanNarrative(value) {
        if (!value) return 'Data belum cukup.';
        return String(value)
            .replace(/\r\n/g, '\n')
            .split('\n')
            .map(line => line.replace(/^\s*[•*-]\s*/, '').trim())
            .filter(Boolean)
            .join('\n');
    }
    function renderData(data) {
        progress.classList.remove('hidden');
        requestId.textContent = `Request ID: ${text(data.request_id)}`;
        status.textContent = human(data.status);
        renderStages(data.stage?.key || data.refresh?.pipeline_stage || 'queued', data.status);
        detail.textContent = text(data.stage?.detail || data.error_reason || 'Status dibaca dari backend.');
        if (!terminal.has(data.status)) return;
        result.classList.remove('hidden');
        resultScope.textContent = `${human(data.completion_scope)} / ${human(data.verification_status)}`;
        cards.replaceChildren();
        const output = data.result?.structured_output || {};
        const refresh = data.refresh || {};
        const metrics = refresh.metrics || output.technical || output.technical_metrics || {};
        const componentRefresh = refresh.bar?.component_refresh || {};
        const broker = output.broker || refresh.broker?.snapshot || componentRefresh.broker?.snapshot || {};
        const fundamentalRefreshRaw = componentRefresh.fundamental || refresh.fundamental || {};
        const fundamentalRefresh = Array.isArray(fundamentalRefreshRaw)
            ? Object.fromEntries(fundamentalRefreshRaw.map(item => [item.parameters?.report_type || item.report_type || 'UNKNOWN', item]))
            : fundamentalRefreshRaw;
        const fundamental = output.fundamental || output.financial || {};
        const providerItems = report => Array.isArray(report?.items) ? report.items : [];
        const providerPeriods = report => providerItems(report).map(item => item.label || item.period || item.year || 'N/A').join(', ') || 'N/A';
        const providerAccounts = report => providerItems(report).flatMap(item => Object.keys(item.data || {})).slice(0, 8).join(', ') || 'N/A';
        const reportFromRefresh = type => ({ ...(fundamentalRefresh[type] || {}), ...(fundamentalRefresh[type]?.snapshot || fundamental[type] || {}) });
        const news = output.news || componentRefresh.news || {};
        const technicalText = [
            line('Harga penutupan', metrics.close ?? metrics.close_price),
            line('SMA20', metrics.sma_20), line('SMA50', metrics.sma_50), line('SMA200', metrics.sma_200),
            line('RSI14', metrics.rsi_14), line('MACD', metrics.macd), line('Signal', metrics.macd_signal),
            line('Histogram', metrics.macd_histogram), line('ATR14', metrics.atr_14),
            line('Support', metrics.support), line('Resistance', metrics.resistance),
            line('Timeframe', metrics.timeframe || refresh.bar?.timeframe || '1d'),
            line('Tanggal', output.bar_date || refresh.bar?.date),
            line('Adjustment', metrics.provenance?.adjustment_mode || refresh.bar?.adjustment_mode || 'N/A'),
            line('Metode support/resistance', metrics.provenance?.support_resistance_method || 'N/A'),
            line('Window support/resistance', metrics.provenance?.support_resistance_window_bars ? `${metrics.provenance.support_resistance_window_bars} bar` : 'N/A'),
            line('Status indikator', metrics.provenance?.sufficiency ? JSON.stringify(metrics.provenance.sufficiency) : 'N/A')
        ] .join('\n');
        const topBuyerText = Array.isArray(broker.top_buyers) ? broker.top_buyers.map(row => `${row.broker_code}: ${human(row.net_value)}`).join(', ') || 'Data kosong' : human(broker.top_buyers || broker.top_buyer);
        const topSellerText = Array.isArray(broker.top_sellers) ? broker.top_sellers.map(row => `${row.broker_code}: ${human(row.net_value)}`).join(', ') || 'Data kosong' : human(broker.top_sellers || broker.top_seller);
        const brokerText = [
            line('Periode mulai', broker.start_date), line('Periode akhir', broker.end_date),
            line('Sesi tercakup', broker.sessions_covered ?? 'Tidak disediakan provider'),
            line('Top buyer', topBuyerText), line('Top seller', topSellerText), line('Net', broker.net_value ?? broker.net)
        ].join('\n');
        const income = normalizeReport(reportFromRefresh('INCOME_STATEMENT'), 'INCOME_STATEMENT');
        const balance = normalizeReport(reportFromRefresh('BALANCE_SHEET'), 'BALANCE_SHEET');
        const cash = normalizeReport(reportFromRefresh('CASH_FLOW_REPORT'), 'CASH_FLOW_REPORT');
        const financialReports = [income, balance, cash];
        const financialConclusion = (() => {
            const statuses = financialReports.map(report => `${report.type}: ${report.status}`);
            const netIncome = reportValue(income, ['laba_rugi', 'laba_rugi_bersih', 'laba_rugi_yang_dapat_diatribusikan', 'net_income', 'profit_loss']);
            const assets = reportValue(balance, ['aset', 'total_aset', 'total_assets']);
            const operatingCash = reportValue(cash, ['arus_kas_dari_aktivitas_operasi', 'arus_kas_operasi', 'operating_cash_flow']);
            const revenue = reportValue(income, ['pendapatan', 'pendapatan_usaha', 'penjualan_dan_pendapatan_usaha', 'revenue', 'sales']);
            const annualNetProfit = annualReportRows(income, ['laba_rugi', 'laba_rugi_bersih', 'laba_rugi_yang_dapat_diatribusikan', 'net_income', 'profit_loss']);
            const annualRevenue = annualReportRows(income, ['pendapatan', 'pendapatan_usaha', 'penjualan_dan_pendapatan_usaha', 'revenue', 'sales']);
            const available = financialReports.filter(report => report.items.length > 0).length;
            if (!available) return `Laporan keuangan belum tersedia. Status: ${statuses.join('; ')}.`;
            const lines = [`${available}/3 laporan memiliki snapshot tahunan tersimpan.`, `Status: ${statuses.join('; ')}.`, `Pendapatan per tahun:\n${annualRevenue}`, `Net profit per tahun:\n${annualNetProfit}`];
            if (netIncome) lines.push(`Net profit terbaru: akun ${netIncome.key}, periode ${netIncome.period}: ${number(netIncome.value)}.`);
            else lines.push('Akun laba/rugi dan periode pendukung tidak ditemukan; laba/rugi tidak disimpulkan.');
            if (assets) lines.push(`Akun ${assets.key}, periode ${assets.period}: ${number(assets.value)}.`);
            if (operatingCash) lines.push(`Akun ${operatingCash.key}, periode ${operatingCash.period}: ${number(operatingCash.value)}.`);
            lines.push('PER null tidak dipakai untuk menyimpulkan rugi. Mata uang/skala hanya ditampilkan jika provider menyediakannya.');
            return lines.join('\n');
        })();
        const reportText = report => {
            const periods = report.items.map(item => item.label || item.period || item.year || 'N/A');
            const accounts = report.items.flatMap(item => Object.keys(item.data || {})).slice(0, 10);
            return [`Jenis: ${report.type}`, `Status: ${report.status}`, `Periode terbaru: ${periods[0] || 'N/A'}`, `Periode pembanding: ${periods[1] || 'N/A'}`, `Akun tersedia: ${accounts.join(', ') || 'N/A'}`, `Sumber: ${report.source || 'N/A'}`, `Mata uang: ${report.currency || 'N/A'}`, `Skala: ${report.scale || 'N/A'}`].join('\n');
        };
        const financialText = [`Kesimpulan: ${financialConclusion}`, ...financialReports.map(reportText)].join('\n\n');
        const brokerConclusion = (() => {
            const buyers = Array.isArray(broker.top_buyers) ? broker.top_buyers : [];
            const sellers = Array.isArray(broker.top_sellers) ? broker.top_sellers : [];
            const buyer = buyers[0]; const seller = sellers[0];
            if (!buyer && !seller) return 'Broker summary belum tersedia dari API IDX Edge. Akumulasi/distribusi belum dapat disimpulkan.';
            return `Kesimpulan API: buyer net terbesar ${buyer?.broker_code || 'N/A'} Rp ${number(buyer?.net_value ?? null)}; seller net terbesar ${seller?.broker_code || 'N/A'} Rp ${number(seller?.net_value === null || seller?.net_value === undefined ? null : Math.abs(seller.net_value))}.\nIni observasi broker pada periode provider, bukan bukti kepemilikan pengendali. Akumulasi dinilai dominan hanya bila net buyer dan konsentrasi top broker konsisten; data satu periode tidak membuktikan tren berkelanjutan.`;
        })();
        const brokerDisplayText = `${brokerConclusion}\n\n${[
            line('Periode mulai', broker.start_date), line('Periode akhir', broker.end_date),
            line('Sesi tercakup', broker.sessions_covered ?? 'Tidak disediakan provider'),
            line('Top buyer', topBuyerText), line('Top seller', topSellerText), line('Net', broker.net_value ?? broker.net)
        ].join('\n')}`;
        const aiFailed = ['STRUCTURED_FALLBACK', 'NOT_RUN'].includes(String(data.result?.model_name || data.model_status || '')) || data.result?.validation_status === 'fallback';
        const explicitPlan = output.trading_plan || output.scenario || output.plan || {};
        const fallbackEntry = Number(metrics.resistance);
        const fallbackStop = Number(metrics.support);
        const fallbackRisk = fallbackEntry > 0 ? ((fallbackEntry - fallbackStop) / fallbackEntry) * 100 : Infinity;
        const fallbackPlan = Number.isFinite(fallbackEntry) && Number.isFinite(fallbackStop)
            && fallbackEntry > fallbackStop && fallbackStop > 0 && fallbackRisk <= 5
            ? {
                model_status: 'CONDITIONAL_DESCRIPTIVE',
                entry_condition: `Penutupan terkonfirmasi di atas resistance Rp ${number(fallbackEntry)} pada timeframe 1d.`,
                entry_area: `Rp ${number(fallbackEntry)}`,
                cut_loss: `Rp ${number(fallbackStop)}`,
                target: `Rp ${number(fallbackEntry + (fallbackEntry - fallbackStop))}`,
                risk_reward: `1:1; risiko ${number(fallbackRisk)}%`,
                horizon: 'Maksimal 5 hari bursa',
                expires_at: 'Bar berikutnya atau invalidasi tercapai',
            }
            : {};
        const plan = Object.keys(explicitPlan).length ? explicitPlan : fallbackPlan;
        const rawEntry = Number(plan.entry_price ?? plan.entry ?? metrics.resistance);
        const rawStop = Number(plan.stop_loss ?? plan.cut_loss_price ?? metrics.support);
        const rawTarget = Number(plan.target_price ?? plan.take_profit_price ?? (fallbackPlan.target ? fallbackEntry + (fallbackEntry - fallbackStop) : NaN));
        const hasLongPlan = Number.isFinite(rawEntry) && Number.isFinite(rawStop) && Number.isFinite(rawTarget);
        const riskPct = hasLongPlan && rawEntry > 0 ? ((rawEntry - rawStop) / rawEntry) * 100 : null;
        const planAccepted = hasLongPlan && rawEntry > rawStop && rawStop > 0 && rawTarget > rawEntry && riskPct <= 5;
        const planData = planAccepted ? plan : null;
        const planUnavailable = data.ai_provenance?.trading_plan_unavailability;
        const planUnavailableText = planUnavailable?.display || (hasLongPlan
            ? `Rencana ditolak validator setelah pembulatan: risiko ${number(riskPct)}%; batas maksimal 5%.`
            : 'Data belum cukup; komponen entry, stop, atau target belum lengkap.');
        const planText = [
            line('Status plan', planData ? (planData.model_status || 'CONDITIONAL_DESCRIPTIVE') : 'Belum ada rencana yang memenuhi batas risiko'),
            line('Alasan plan tidak tersedia', planData ? 'Tidak ada' : planUnavailableText),
            line('Reason code', planData ? 'PLAN_ACCEPTED' : (planUnavailable?.code || 'PLAN_UNAVAILABLE')),
            line('Syarat entry', planData?.entry_condition || 'Konfirmasi penutupan di atas resistance dengan timeframe dan tanggal acuan yang sama.'),
            line('Area entry', planData?.entry_area || planData?.entry_price || 'N/A'),
            line('Cut loss / invalidasi', planData?.cut_loss || planData?.invalidation || (hasLongPlan ? `Ditolak: stop ${number(rawStop)} tidak memenuhi batas risiko ${number(riskPct)}%.` : 'N/A: level entry, stop, atau target tidak lengkap.')),
            line('Target', planData?.target || planData?.take_profit || (hasLongPlan ? `Ditolak: target ${number(rawTarget)} tidak lolos validasi.` : 'N/A')),
            line('Risiko / reward', planData?.risk_reward || (riskPct !== null ? `Ditolak: risiko ${number(riskPct)}%; batas maksimal 5%.` : 'N/A')),
            line('Horizon', planData?.horizon || 'N/A: belum ada horizon yang lolos validasi.'),
            line('Berlaku sampai', planData?.expires_at || planData?.expiry_date || 'N/A'),
            line('Status model', 'MODEL_NOT_VALIDATED_FOR_INSTRUMENT dicatat terpisah; bukan alasan mengosongkan rencana Gemini.')
        ] .join('\n');
        const narrative = cleanNarrative(data.result?.market_regime_summary || output.conclusion);
        const aiMessage = aiFailed
            ? 'Model produksi tidak aktif untuk instrumen ini. Narasi di bawah hanya structured fallback.'
            : 'Narasi tersimpan dari backend.';
        const missingText = [
            news.status === 'NOT_FETCHED' ? 'Berita: belum diambil dari provider terautentikasi.' : null,
            broker.start_date ? null : 'Broker: periode atau sesi tidak disediakan provider.',
            Object.values(fundamentalRefresh).some(item => item?.status === 'REFRESHED_API') ? null : 'Fundamental: laporan provider belum tersedia.',
            data.freshness === 'AVAILABLE_FRESH' ? null : `Data acuan: ${human(data.freshness)}`
        ].filter(Boolean).join('\n') || 'Tidak ada kekosongan data yang terdeteksi.';
        const conclusion = card('Kesimpulan', narrative, aiFailed ? 'warning' : 'accent');
        conclusion.classList.add('md:col-span-2');
        cards.append(
            conclusion,
            card('Skenario trading', planText, aiFailed ? 'warning' : 'default'),
            card('Data acuan', `Ticker: ${text(data.ticker)}\nBar: ${output.bar_date || refresh.bar?.date || '-'}\nStatus: ${human(data.freshness)}\nMode adjustment: ${refresh.bar?.adjustment_mode || refresh.canonical_components?.adjusted?.adjustment_mode || '-'}`),
            card('Teknikal', technicalText), card('Broker akumulasi / distribusi', brokerDisplayText),
            card('Laporan keuangan', financialText), card('Data belum tersedia', missingText, missingText === 'Tidak ada kekosongan data yang terdeteksi.' ? 'default' : 'warning'),
            card('Status analisis', aiMessage, aiFailed ? 'warning' : 'accent')
        );
        if (aiFailed) {
            resultScope.textContent += ' / AI tidak tersedia';
        }
    }
    async function poll(id, initialDelay = 0) {
        const token = ++pollToken;
        let attempt = 0;
        if (initialDelay) await new Promise(resolve => setTimeout(resolve, initialDelay));
        while (token === pollToken) {
            try {
                const response = await fetch(`{{ url("/api/research") }}/${encodeURIComponent(id)}`, { headers: { 'X-Aether-Research-Auth': capability, 'Accept': 'application/json' } });
                if (!response.ok) throw new Error(response.status === 403 ? 'REQUEST_ACCESS_DENIED' : `HTTP_${response.status}`);
                const data = await response.json();
                renderData(data);
                localStorage.setItem(storageKey, id);
                if (terminal.has(data.status)) { localStorage.removeItem(storageKey); return; }
            } catch (error) {
                detail.textContent = error.message === 'REQUEST_ACCESS_DENIED'
                    ? 'Request tidak dapat diakses dari sesi/origin ini.'
                    : 'Status belum terbaca. Request ID tetap disimpan untuk recovery.';
                return;
            }
            attempt += 1;
            await new Promise(resolve => setTimeout(resolve, attempt < 10 ? 3000 : 10000));
        }
    }
    function filterCompanies() {
        const query = companySearch.value.trim().toLowerCase();
        let visible = 0;
        Array.from(ticker.options).forEach(option => {
            const match = option.value === '' || query === '' || option.dataset.search.includes(query);
            option.hidden = !match;
            if (option.value !== '' && match) visible += 1;
        });
        companyCount.textContent = `${visible} emiten cocok dari {{ $companies->count() }} registry DB`;
        if (ticker.value && ticker.selectedOptions[0]?.hidden) ticker.value = '';
    }
    companySearch.addEventListener('input', filterCompanies);
    form.addEventListener('submit', async event => {
        event.preventDefault();
        submit.disabled = true; submit.textContent = 'Mencatat...';
        try {
            const response = await fetch('{{ route("dashboard.research.submit") }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Aether-Research-Auth': capability, 'Accept': 'application/json' }, body: JSON.stringify({ ticker: ticker.value, analysis_type: type.value, question: question.value }) });
            const data = await response.json();
            if (!data.success) throw new Error(data.message || data.status || 'RESEARCH_REJECTED');
            localStorage.setItem(storageKey, data.request_id); progress.classList.remove('hidden'); renderData({ request_id: data.request_id, ticker: data.ticker, status: data.status, stage: { key: 'queued', detail: data.message } }); poll(data.request_id);
        } catch (error) {
            detail.textContent = error.message; progress.classList.remove('hidden'); status.textContent = 'FAILED_TO_QUEUE';
        } finally { submit.disabled = false; submit.textContent = 'Mulai Analisis'; }
    });
    const queryRequestId = new URLSearchParams(window.location.search).get('request_id');
    const saved = queryRequestId || localStorage.getItem(storageKey);
    if (queryRequestId || saved) {
        localStorage.setItem(storageKey, saved);
        poll(saved, 0);
    }
})();
</script>
@endsection
