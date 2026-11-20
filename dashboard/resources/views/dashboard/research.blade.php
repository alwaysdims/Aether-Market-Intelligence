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
                <select id="researchTicker" required size="6" class="mt-2 w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded-lg px-3 py-2 text-sm text-[#F5F1E8] focus:outline-none focus:border-[#E5C49B]">
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
        <div class="flex items-center justify-between"><h2 class="text-lg font-bold">Hasil tersimpan</h2><span id="researchResultScope" class="text-[10px] font-mono text-amber-300"></span></div>
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
    const terminal = new Set(['COMPLETED', 'PARTIAL', 'FAILED', 'DEADLINE_EXCEEDED']);
    let pollToken = 0;

    const stageOrder = [
        ['queued', 'Diterima / antre'],
        ['data_check', 'Pemeriksaan data / API'],
        ['indicators', 'Kalkulasi indikator'],
        ['synthesis', 'Evidence / sintesis'],
        ['completed', 'Selesai']
    ];

    function text(value) { return value === null || value === undefined || value === '' ? '-' : String(value); }
    function card(title, value) {
        const el = document.createElement('div');
        el.className = 'bg-[#0A213D] border border-[#F5F1E8]/10 rounded-xl p-4';
        const h = document.createElement('h3'); h.className = 'text-[10px] font-mono uppercase text-[#E5C49B]'; h.textContent = title;
        const p = document.createElement('p'); p.className = 'mt-2 whitespace-pre-wrap break-words text-sm text-[#F5F1E8]/80'; p.textContent = text(value);
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
        const broker = refresh.broker?.snapshot || refresh.bar?.component_refresh?.broker?.snapshot || output.broker || {};
        const fundamentalRefresh = refresh.bar?.component_refresh?.fundamental || refresh.fundamental || {};
        const fundamental = output.fundamental || output.financial || fundamentalRefresh;
        const providerItems = report => Array.isArray(report?.items) ? report.items : [];
        const providerPeriods = report => providerItems(report).map(item => item.label || item.period || item.year || 'N/A').join(', ') || 'N/A';
        const providerAccounts = report => providerItems(report).flatMap(item => Object.keys(item.data || {})).slice(0, 8).join(', ') || 'N/A';
        const news = output.news || {};
        const technicalText = [
            line('Harga penutupan', metrics.close ?? metrics.close_price),
            line('SMA20', metrics.sma_20), line('SMA50', metrics.sma_50), line('SMA200', metrics.sma_200),
            line('RSI14', metrics.rsi_14), line('MACD', metrics.macd), line('Signal', metrics.macd_signal),
            line('Histogram', metrics.macd_histogram), line('ATR14', metrics.atr_14),
            line('Support', metrics.support), line('Resistance', metrics.resistance),
            line('Timeframe', metrics.timeframe || refresh.bar?.timeframe),
            line('Tanggal', output.bar_date || refresh.bar?.date)
        ].join('\n');
        const topBuyerText = Array.isArray(broker.top_buyers) ? broker.top_buyers.map(row => `${row.broker_code}: ${human(row.net_value)}`).join(', ') || 'Data kosong' : human(broker.top_buyers || broker.top_buyer);
        const topSellerText = Array.isArray(broker.top_sellers) ? broker.top_sellers.map(row => `${row.broker_code}: ${human(row.net_value)}`).join(', ') || 'Data kosong' : human(broker.top_sellers || broker.top_seller);
        const brokerText = [
            line('Periode mulai', broker.start_date), line('Periode akhir', broker.end_date),
            line('Sesi tercakup', broker.sessions_covered ?? 'Tidak disediakan provider'),
            line('Top buyer', topBuyerText), line('Top seller', topSellerText), line('Net', broker.net_value ?? broker.net)
        ].join('\n');
        const fundamentalReports = Array.isArray(fundamentalRefresh)
            ? Object.fromEntries(fundamentalRefresh.map(item => [item.parameters?.report_type || item.snapshot?.report_type || '', item]))
            : fundamentalRefresh;
        const income = fundamentalReports.INCOME_STATEMENT?.snapshot || fundamental.INCOME_STATEMENT;
        const balance = fundamentalReports.BALANCE_SHEET?.snapshot || fundamental.BALANCE_SHEET;
        const cash = fundamentalReports.CASH_FLOW_REPORT?.snapshot || fundamental.CASH_FLOW_REPORT;
        const financialText = [
            line('Income statement periode', providerPeriods(income)), line('Income statement akun', providerAccounts(income)),
            line('Balance sheet periode', providerPeriods(balance)), line('Balance sheet akun', providerAccounts(balance)),
            line('Cash flow periode', providerPeriods(cash)), line('Cash flow akun', providerAccounts(cash)),
            line('Status', fundamentalRefresh.INCOME_STATEMENT?.status || fundamental.status)
        ].join('\n');
        const aiFailed = ['STRUCTURED_FALLBACK', 'NOT_RUN'].includes(String(data.result?.model_name || data.model_status || '')) || data.result?.validation_status === 'fallback';
        const plan = aiFailed ? {} : (output.trading_plan || output.scenario || output.plan || {});
        const planText = aiFailed ? 'AI tidak tersedia; entry, cut loss, dan target tidak dibuat.' : [
            line('Syarat entry', plan.entry_condition || plan.entry || 'Tidak dibuat'),
            line('Area entry', plan.entry_area || plan.entry_price),
            line('Cut loss / invalidasi', plan.cut_loss || plan.invalidation),
            line('Target', plan.target || plan.take_profit),
            line('Potensi / risiko', plan.potential_percent || plan.risk_percent || plan.risk_reward),
            line('Horizon', plan.horizon), line('Berlaku sampai', plan.expires_at || plan.expiry_date)
        ].join('\n');
        const aiMessage = aiFailed ? 'AI tidak tersedia; data backend tetap ditampilkan.' : '';
        cards.append(
            card('Kesimpulan singkat', data.result?.market_regime_summary || output.conclusion || 'Data belum cukup.'),
            card('Skenario trading', planText),
            card('Data acuan', `Ticker: ${text(data.ticker)}\nBar: ${output.bar_date || refresh.bar?.date || '-'}\nFreshness: ${human(data.freshness)}\nAdjustment: ${refresh.bar?.adjustment_mode || refresh.canonical_components?.adjusted?.adjustment_mode || '-'}`),
            card('Teknikal', technicalText), card('Broker tujuh hari kalender', brokerText),
            card('Laporan keuangan', financialText), card('Status AI', aiMessage || 'Narasi AI tersedia.')
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
                if (!response.ok) throw new Error(`HTTP_${response.status}`);
                const data = await response.json();
                renderData(data);
                localStorage.setItem(storageKey, id);
                if (terminal.has(data.status)) { localStorage.removeItem(storageKey); return; }
            } catch (error) {
                detail.textContent = 'Status belum terbaca. Reload recovery akan mempertahankan Request ID.';
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
    const saved = localStorage.getItem(storageKey);
    if (saved) poll(saved, 0);
})();
</script>
@endsection
