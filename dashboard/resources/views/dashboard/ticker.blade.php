@extends('layouts.app')

@section('page_title', 'Detail Emiten: ' . $ticker)

@section('content')
<div class="space-y-6">

    <!-- Emiten Header Institutional Strip -->
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <!-- Left Info -->
            <div class="space-y-1.5">
                <div class="flex items-center space-x-3">
                    <h1 class="text-2xl font-bold font-mono tracking-tight text-[#E5C49B]">{{ $ticker }}</h1>
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-[#0D2B4E] text-[#F5F1E8]/70 border border-[#F5F1E8]/10 uppercase">
                        {{ $company?->listing_board ?? 'Papan Utama' }}
                    </span>
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono border
                        {{ ($freshness['status'] ?? '') === 'AVAILABLE_FRESH' ? 'bg-emerald-950/60 text-emerald-300 border-emerald-500/30' : 'bg-amber-950/60 text-amber-300 border-amber-500/30' }}">
                        {{ $freshness['status'] ?? 'NOT_VERIFIED' }}
                    </span>
                    @if($latestObs && $latestObs->validation_status === 'CONFLICTED')
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono border bg-rose-950/80 text-rose-300 border-rose-500/40" title="{{ $latestObs->conflict_reason ?? 'Terdeteksi anomali/konflik data antar-papan atau antar-sumber' }}">
                            DATA_CONFLICTED
                        </span>
                    @endif
                </div>
                <h2 class="text-sm font-semibold text-[#F5F1E8]">
                    {{ $company?->company_name ?? 'Emiten Belum Terdaftar di Registri Sekunder' }}
                </h2>
                <div class="flex flex-wrap items-center gap-2 text-xs text-[#F5F1E8]/50">
                    <span>Sektor: <strong class="text-[#F5F1E8]/80">{{ $company?->sector ?: '-' }}</strong></span>
                    <span>&bull;</span>
                    <span>Industri: <strong class="text-[#F5F1E8]/80">{{ ($company?->industry && $company->industry !== $company->sector) ? $company->industry : 'Belum tersedia' }}</strong></span>
                    <span>&bull;</span>
                    <span>Tanggal IPO: <strong class="text-[#F5F1E8]/80 font-mono">{{ $company?->listing_date ? $company->listing_date->format('d M Y') : 'Belum tersedia' }}</strong></span>
                    <span>&bull;</span>
                    <span>Sumber: <strong id="marketDataSource" class="text-[#F5F1E8]/80 font-mono">{{ $latestObs?->source ?? 'DATA_NOT_AVAILABLE' }}</strong></span>
                    <span>&bull;</span>
                    <span id="marketConnectionStatus" class="font-mono">INTERNAL_POLLING</span>
                </div>
            </div>

            <!-- Right Price & Quick Actions -->
            <div class="flex flex-wrap items-center gap-4">
                <!-- Price Display -->
                <div class="bg-[#0D2B4E] border border-[#F5F1E8]/10 px-4 py-2.5 rounded text-right">
                    <span class="text-[10px] uppercase font-mono text-[#F5F1E8]/40 block">Harga Penutupan Terkini (EOD)</span>
                    <div class="flex items-baseline space-x-2 justify-end">
                        <span class="text-xl font-bold text-[#F5F1E8] font-mono tabular-nums">
                            @if($latestObs && $latestObs->close_price)
                                Rp {{ number_format($latestObs->close_price, 0, ',', '.') }}
                            @else
                                <span class="text-xs text-[#F5F1E8]/30 font-mono">DATA_NOT_AVAILABLE</span>
                            @endif
                        </span>
                        @if($priceChanges && $priceChanges['is_previous_close_available'] && $priceChanges['daily_change_pct'] !== null)
                            @php $dChg = $priceChanges['daily_change_pct']; @endphp
                            <span class="text-xs font-mono font-semibold tabular-nums {{ $dChg > 0 ? 'text-emerald-400' : ($dChg < 0 ? 'text-rose-400' : 'text-[#F5F1E8]/60') }}">
                                {{ $priceChanges['daily_change'] > 0 ? '+' : '' }}{{ number_format($priceChanges['daily_change'], 0, ',', '.') }} ({{ $dChg > 0 ? '+' : '' }}{{ number_format($dChg, 2, ',', '.') }}%)
                            </span>
                        @else
                            <span class="text-xs font-mono text-[#F5F1E8]/40">N/A</span>
                        @endif
                    </div>
                    <div class="text-[10px] text-[#F5F1E8]/40 font-mono block mt-0.5">
                        @if($priceChanges && $priceChanges['is_previous_close_available'])
                            <span>Perubahan Harian vs Prev Close Rp{{ number_format($priceChanges['previous_close'], 0, ',', '.') }} ({{ $priceChanges['previous_bar_date'] }})</span>
                        @else
                            <span>Perubahan Harian: Basis sesi sebelumnya N/A</span>
                        @endif
                    </div>
                    @if($priceChanges && $priceChanges['change_vs_open_pct'] !== null)
                        <div class="text-[10px] font-mono {{ $priceChanges['change_vs_open_pct'] > 0 ? 'text-emerald-400/80' : ($priceChanges['change_vs_open_pct'] < 0 ? 'text-rose-400/80' : 'text-[#F5F1E8]/40') }}">
                            Intraday vs Open: {{ $priceChanges['change_vs_open_pct'] > 0 ? '+' : '' }}{{ number_format($priceChanges['change_vs_open_pct'], 2, ',', '.') }}%
                        </div>
                    @endif
                    <span class="text-[9px] text-[#F5F1E8]/30 font-mono block mt-0.5">
                        Bar Sesi: {{ $latestObs?->bar_date ? $latestObs->bar_date->format('Y-m-d') : '-' }} &bull; Vol: {{ $latestObs?->volume ? number_format($latestObs->volume, 0, ',', '.') . ' lembar' : 'N/A' }}
                    </span>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center space-x-2">
                    <button onclick="toggleWatchlist('{{ $ticker }}', this)" title="{{ $inWatchlist ? 'Dalam Pemantauan Aktif' : 'Tambahkan ke Pemantauan' }}" class="px-3 py-2 rounded border text-xs font-medium transition flex items-center {{ $inWatchlist ? 'bg-[#E5C49B]/10 text-[#E5C49B] border-[#E5C49B]/40' : 'bg-[#0D2B4E] text-[#F5F1E8]/70 border-[#F5F1E8]/15 hover:text-[#E5C49B]' }}">
                        <svg class="w-3.5 h-3.5 mr-1.5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        {{ $inWatchlist ? 'Dipantau' : '+ Pantau' }}
                    </button>

                    @if($aiConclusion['header']['can_request_research'])
                        <button onclick="openResearchModal('{{ $ticker }}')" class="px-3 py-2 bg-[#E5C49B] hover:bg-[#D6B284] text-[#05152B] rounded text-xs font-semibold tracking-wide transition flex items-center shadow-sm">
                            <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                            Riset AI
                        </button>
                    @else
                        <button disabled title="Instrumen belum terdaftar di registri BEI. Riset dinonaktifkan sebelum validasi sah." class="px-3 py-2 bg-[#05152B] border border-[#F5F1E8]/10 text-[#F5F1E8]/40 rounded text-xs font-mono cursor-not-allowed flex items-center">
                            <svg class="w-3.5 h-3.5 mr-1.5 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m0 0v2m0-2h2m-2 0H10m11-3.5a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Riset Terkunci (Ticker Tak Dikenal)
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- 7-Tab Navigation Header -->
    <div class="border-b border-[#F5F1E8]/10 overflow-x-auto">
        <nav class="flex space-x-1 min-w-max pb-1" id="tickerTabNav">
            <!-- Tab 1: Ringkasan -->
            <a href="{{ route('dashboard.ticker', ['ticker' => $ticker, 'tab' => 'summary']) }}" data-tab="summary" class="tab-link px-3.5 py-2 text-xs font-medium rounded-t transition border-b-2 {{ ($tab ?? 'summary') === 'summary' ? 'active-tab bg-[#0A213D] text-[#E5C49B] border-[#E5C49B] font-semibold' : 'text-[#F5F1E8]/60 hover:text-[#F5F1E8] border-transparent hover:bg-[#0A213D]/40' }}">
                1. Ringkasan
            </a>

            <!-- Tab 2: Pemantauan -->
            <a href="{{ route('dashboard.ticker', ['ticker' => $ticker, 'tab' => 'monitoring']) }}" data-tab="monitoring" class="tab-link px-3.5 py-2 text-xs font-medium rounded-t transition border-b-2 {{ ($tab ?? '') === 'monitoring' ? 'active-tab bg-[#0A213D] text-[#E5C49B] border-[#E5C49B] font-semibold' : 'text-[#F5F1E8]/60 hover:text-[#F5F1E8] border-transparent hover:bg-[#0A213D]/40' }}">
                2. Pemantauan & Sinyal
            </a>

            <!-- Tab 3: Teknikal -->
            <a href="{{ route('dashboard.ticker', ['ticker' => $ticker, 'tab' => 'technical']) }}" data-tab="technical" class="tab-link px-3.5 py-2 text-xs font-medium rounded-t transition border-b-2 {{ ($tab ?? '') === 'technical' ? 'active-tab bg-[#0A213D] text-[#E5C49B] border-[#E5C49B] font-semibold' : 'text-[#F5F1E8]/60 hover:text-[#F5F1E8] border-transparent hover:bg-[#0A213D]/40' }}">
                3. Teknikal & Indikator
            </a>

            <!-- Tab 4: Fundamental -->
            <a href="{{ route('dashboard.ticker', ['ticker' => $ticker, 'tab' => 'fundamental']) }}" data-tab="fundamental" class="tab-link px-3.5 py-2 text-xs font-medium rounded-t transition border-b-2 {{ ($tab ?? '') === 'fundamental' ? 'active-tab bg-[#0A213D] text-[#E5C49B] border-[#E5C49B] font-semibold' : 'text-[#F5F1E8]/60 hover:text-[#F5F1E8] border-transparent hover:bg-[#0A213D]/40' }}">
                4. Fundamental Fiskal
            </a>

            <!-- Tab 5: Kesimpulan AI -->
            <a href="{{ route('dashboard.ticker', ['ticker' => $ticker, 'tab' => 'ai_insight']) }}" data-tab="ai_insight" class="tab-link px-3.5 py-2 text-xs font-medium rounded-t transition border-b-2 {{ ($tab ?? '') === 'ai_insight' ? 'active-tab bg-[#0A213D] text-[#E5C49B] border-[#E5C49B] font-semibold' : 'text-[#F5F1E8]/60 hover:text-[#F5F1E8] border-transparent hover:bg-[#0A213D]/40' }}">
                5. Kesimpulan AI Gemini
            </a>

            <!-- Tab 6: News & Event -->
            <a href="{{ route('dashboard.ticker', ['ticker' => $ticker, 'tab' => 'news']) }}" data-tab="news" class="tab-link px-3.5 py-2 text-xs font-medium rounded-t transition border-b-2 {{ ($tab ?? '') === 'news' ? 'active-tab bg-[#0A213D] text-[#E5C49B] border-[#E5C49B] font-semibold' : 'text-[#F5F1E8]/60 hover:text-[#F5F1E8] border-transparent hover:bg-[#0A213D]/40' }}">
                6. News & Event ({{ $news->count() }})
            </a>

            <!-- Tab 7: Riwayat AI -->
            <a href="{{ route('dashboard.ticker', ['ticker' => $ticker, 'tab' => 'history']) }}" data-tab="history" class="tab-link px-3.5 py-2 text-xs font-medium rounded-t transition border-b-2 {{ ($tab ?? '') === 'history' ? 'active-tab bg-[#0A213D] text-[#E5C49B] border-[#E5C49B] font-semibold' : 'text-[#F5F1E8]/60 hover:text-[#F5F1E8] border-transparent hover:bg-[#0A213D]/40' }}">
                7. Riwayat AI Historis
            </a>
        </nav>
    </div>

    <!-- Tab Dynamic Content Container -->
    <div id="tabContentContainer" class="relative min-h-[300px]">
        @include('dashboard.ticker_tab_content')
    </div>

</div>

<!-- Client-side HTML5 Canvas Chart Renderer -->
@include('dashboard.ticker_chart_script')

<script>
document.addEventListener('DOMContentLoaded', function () {
    const nav = document.getElementById('tickerTabNav');
    const container = document.getElementById('tabContentContainer');
    if (!nav || !container) return;

    // Scoped tab cache with TTL (5 minutes) and ticker awareness
    const CACHE_TTL_MS = 5 * 60 * 1000;
    const currentTicker = '{{ $ticker }}';
    const cache = new Map();
    let currentAbort = null;

    // Cache initial tab with timestamp and ticker metadata
    const initialActive = nav.querySelector('.tab-link.active-tab');
    if (initialActive) {
        const initTab = initialActive.dataset.tab;
        cache.set(initTab, {
            ticker: currentTicker,
            html: container.innerHTML,
            timestamp: Date.now()
        });
    }

    nav.addEventListener('click', function (e) {
        const link = e.target.closest('.tab-link');
        if (!link) return;

        // Allow ctrl/cmd + click to open in new tab normally
        if (e.metaKey || e.ctrlKey || e.shiftKey) return;
        e.preventDefault();

        const tab = link.dataset.tab;
        if (!tab) return;

        // Update nav active styles
        nav.querySelectorAll('.tab-link').forEach(l => {
            l.classList.remove('active-tab', 'bg-[#0A213D]', 'text-[#E5C49B]', 'border-[#E5C49B]', 'font-semibold');
            l.classList.add('text-[#F5F1E8]/60', 'border-transparent');
        });
        link.classList.add('active-tab', 'bg-[#0A213D]', 'text-[#E5C49B]', 'border-[#E5C49B]', 'font-semibold');
        link.classList.remove('text-[#F5F1E8]/60', 'border-transparent');

        // Update URL
        const url = new URL(link.href, window.location.origin);
        window.history.pushState({ tab: tab }, '', url.toString());

        loadTab(tab, link.href);
    });

    window.addEventListener('popstate', function (e) {
        const params = new URLSearchParams(window.location.search);
        const tab = params.get('tab') || 'summary';
        const link = nav.querySelector(`.tab-link[data-tab="${tab}"]`);
        if (link) {
            nav.querySelectorAll('.tab-link').forEach(l => {
                l.classList.remove('active-tab', 'bg-[#0A213D]', 'text-[#E5C49B]', 'border-[#E5C49B]', 'font-semibold');
                l.classList.add('text-[#F5F1E8]/60', 'border-transparent');
            });
            link.classList.add('active-tab', 'bg-[#0A213D]', 'text-[#E5C49B]', 'border-[#E5C49B]', 'font-semibold');
            link.classList.remove('text-[#F5F1E8]/60', 'border-transparent');
            loadTab(tab, link.href);
        }
    });

    function loadTab(tab, href) {
        const cached = cache.get(tab);
        const isFresh = cached && (Date.now() - cached.timestamp < CACHE_TTL_MS);
        const hasProcessing = cached && (cached.html.includes('PROCESSING') || cached.html.includes('animate-pulse') || cached.html.includes('SEDANG DIANALISIS'));

        if (isFresh && !hasProcessing) {
            container.innerHTML = cached.html;
            executeScripts(container);
            return;
        }

        if (currentAbort) {
            currentAbort.abort();
        }
        currentAbort = new AbortController();

        // Loading overlay
        container.classList.add('opacity-40', 'pointer-events-none');

        fetch(href, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Tab-Request': '1'
            },
            signal: currentAbort.signal
        })
        .then(res => {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.text();
        })
        .then(html => {
            cache.set(tab, {
                ticker: currentTicker,
                html: html,
                timestamp: Date.now()
            });
            container.innerHTML = html;
            container.classList.remove('opacity-40', 'pointer-events-none');
            executeScripts(container);
        })
        .catch(err => {
            if (err.name === 'AbortError') return;
            container.classList.remove('opacity-40', 'pointer-events-none');
            container.innerHTML = `
                <div class="bg-[#0A213D] border border-rose-500/30 rounded-lg p-6 text-center space-y-3">
                    <p class="text-xs font-mono text-rose-400">GAGAL MEMUAT TAB</p>
                    <p class="text-xs text-[#F5F1E8]/70">${err.message || 'Terjadi gangguan jaringan saat memuat konten tab.'}</p>
                    <button onclick="window.location.reload()" class="px-3 py-1.5 bg-[#0D2B4E] hover:bg-[#153660] text-[#E5C49B] text-xs font-mono rounded border border-[#E5C49B]/30">
                        Muat Ulang Halaman
                    </button>
                </div>
            `;
        });
    }

    function executeScripts(parent) {
        const scripts = parent.querySelectorAll('script');
        scripts.forEach(s => {
            const script = document.createElement('script');
            if (s.src) {
                script.src = s.src;
            } else {
                script.textContent = s.textContent;
            }
            document.body.appendChild(script);
            script.remove();
        });
    }

    const snapshotUrl = @json(route('dashboard.market.snapshot', ['ticker' => $ticker]));
    const sourceLabel = document.getElementById('marketDataSource');
    const connectionLabel = document.getElementById('marketConnectionStatus');
    let lastEvidenceKey = @json($latestObs?->data_hash ?? $latestObs?->updated_at?->toIso8601String());

    async function pollStoredSnapshot() {
        if (document.hidden) return;

        try {
            const response = await fetch(snapshotUrl, {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const payload = await response.json();
            const data = payload.data;
            sourceLabel.textContent = data.source;
            connectionLabel.textContent = data.is_final ? 'FINAL • INTERNAL_POLLING' : 'PROVISIONAL • INTERNAL_POLLING';

            const evidenceKey = data.data_hash || data.available_at || data.market_timestamp;
            if (lastEvidenceKey && evidenceKey && lastEvidenceKey !== evidenceKey) {
                cache.clear();
                const activeTab = nav.querySelector('.tab-link.active-tab')?.dataset.tab;
                if (activeTab === 'summary') {
                    const activeLink = nav.querySelector('.tab-link.active-tab');
                    loadTab('summary', activeLink.href);
                }
            }
            lastEvidenceKey = evidenceKey;
        } catch (error) {
            connectionLabel.textContent = 'DEGRADED • DATA TERSIMPAN';
        }
    }

    const snapshotPoller = window.setInterval(pollStoredSnapshot, 30000);
    window.addEventListener('pagehide', () => window.clearInterval(snapshotPoller), { once: true });
});
</script>
@endsection
