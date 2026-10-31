<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $brandConfig['system_name'] ?? config('app.name', 'Aether Market Intelligence') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logo.png') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    <!-- Compiled Vite Assets (Tailwind CSS v4 + Terminal Palette) -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body {
            background-color: #05152B;
            color: #F5F1E8;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        .tabular-nums {
            font-variant-numeric: tabular-nums;
        }
        /* Institutional Fine Scrollbars */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #05152B;
        }
        ::-webkit-scrollbar-thumb {
            background: #153660;
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #E5C49B;
        }
    </style>
</head>
<body class="min-h-screen flex flex-col md:flex-row antialiased bg-[#05152B] text-[#F5F1E8]">

    <!-- Sidebar Mobile Backdrop -->
    <div id="sidebarBackdrop" onclick="toggleSidebar()" class="fixed inset-0 bg-black/70 z-40 hidden md:hidden transition-opacity"></div>

    <!-- Sidebar (Institutional Control Plane) -->
    <aside id="mainSidebar" class="fixed inset-y-0 left-0 z-50 w-64 bg-[#05152B] border-r border-[#F5F1E8]/10 flex flex-col transform -translate-x-full md:translate-x-0 md:sticky md:top-0 md:h-screen md:shrink-0 transition-transform duration-200 ease-in-out">
        <!-- Brand Header -->
        <div class="h-16 shrink-0 flex items-center justify-between px-5 border-b border-[#F5F1E8]/10">
            <a href="{{ route('dashboard.overview') }}" class="flex items-center space-x-3 group">
                <img src="{{ asset('images/logo.png') }}" alt="Aether Logo" class="w-8 h-8 rounded border border-[#E5C49B]/30 object-cover shadow-sm group-hover:border-[#E5C49B] transition">
                <div>
                    <span class="text-sm font-bold tracking-wider text-[#F5F1E8] block uppercase">AETHER</span>
                    <span class="text-[10px] tracking-widest text-[#E5C49B] uppercase font-mono block -mt-0.5">MARKET INTEL</span>
                </div>
            </a>
            <button onclick="toggleSidebar()" class="md:hidden text-[#F5F1E8]/70 hover:text-[#F5F1E8]">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <!-- Navigation Menu -->
        <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
            <div class="px-3 pb-2 text-[10px] font-semibold tracking-wider text-[#F5F1E8]/40 uppercase font-mono">Terminal Navigasi</div>

            <!-- 1. Dashboard -->
            <a href="{{ route('dashboard.overview') }}" class="flex items-center px-3 py-2.5 rounded text-xs font-medium transition {{ request()->routeIs('dashboard.overview') ? 'bg-[#0A213D] text-[#E5C49B] border border-[#E5C49B]/30 font-semibold' : 'text-[#F5F1E8]/70 hover:text-[#F5F1E8] hover:bg-[#0A213D]/60' }}">
                <svg class="w-4 h-4 mr-3 {{ request()->routeIs('dashboard.overview') ? 'text-[#E5C49B]' : 'text-[#F5F1E8]/40' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                Dashboard
            </a>

            <!-- 2. Market Monitor -->
            <a href="{{ route('dashboard.market') }}" class="flex items-center px-3 py-2.5 rounded text-xs font-medium transition {{ request()->routeIs('dashboard.market*') ? 'bg-[#0A213D] text-[#E5C49B] border border-[#E5C49B]/30 font-semibold' : 'text-[#F5F1E8]/70 hover:text-[#F5F1E8] hover:bg-[#0A213D]/60' }}">
                <svg class="w-4 h-4 mr-3 {{ request()->routeIs('dashboard.market*') ? 'text-[#E5C49B]' : 'text-[#F5F1E8]/40' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
                Market Monitor
            </a>

            <!-- 3. News & Event -->
            <a href="{{ route('dashboard.news') }}" class="flex items-center px-3 py-2.5 rounded text-xs font-medium transition {{ request()->routeIs('dashboard.news') ? 'bg-[#0A213D] text-[#E5C49B] border border-[#E5C49B]/30 font-semibold' : 'text-[#F5F1E8]/70 hover:text-[#F5F1E8] hover:bg-[#0A213D]/60' }}">
                <svg class="w-4 h-4 mr-3 {{ request()->routeIs('dashboard.news') ? 'text-[#E5C49B]' : 'text-[#F5F1E8]/40' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"></path></svg>
                News & Event
            </a>

            <!-- 4. AI History -->
            <a href="{{ route('dashboard.history') }}" class="flex items-center px-3 py-2.5 rounded text-xs font-medium transition {{ request()->routeIs('dashboard.history') ? 'bg-[#0A213D] text-[#E5C49B] border border-[#E5C49B]/30 font-semibold' : 'text-[#F5F1E8]/70 hover:text-[#F5F1E8] hover:bg-[#0A213D]/60' }}">
                <svg class="w-4 h-4 mr-3 {{ request()->routeIs('dashboard.history') ? 'text-[#E5C49B]' : 'text-[#F5F1E8]/40' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                AI History
            </a>
        </nav>

        <!-- Sidebar Engine & Health Footer -->
        <div class="p-3 shrink-0 border-t border-[#F5F1E8]/10 bg-[#05152B]">
            <a href="{{ route('dashboard.health') }}" class="block bg-[#0A213D] hover:bg-[#0D2B4E] p-2.5 rounded border border-[#F5F1E8]/10 space-y-1.5 text-[11px] transition group">
                <div class="flex items-center justify-between text-[#F5F1E8]/60">
                    <span class="flex items-center text-[#F5F1E8]/80 group-hover:text-[#E5C49B]">
                        <span class="w-1.5 h-1.5 rounded-full {{ ($brandConfig['system_status']['db'] ?? '') === 'HEALTHY' ? 'bg-emerald-400 animate-pulse' : 'bg-rose-400' }} mr-2"></span>
                        Status Sistem
                    </span>
                    <span class="text-[10px] font-mono text-[#E5C49B]">{{ $brandConfig['system_status']['checked_at'] ?? now()->format('H:i') }} WIB</span>
                </div>
                <div class="flex items-center justify-between text-[#F5F1E8]/50 text-[10px]">
                    <span>PostgreSQL</span>
                    <span class="{{ ($brandConfig['system_status']['db'] ?? '') === 'HEALTHY' ? 'text-emerald-400' : 'text-rose-400' }} font-mono font-bold">
                        {{ ($brandConfig['system_status']['db'] ?? '') === 'HEALTHY' ? 'TERHUBUNG' : 'CONFIGURED' }}
                    </span>
                </div>
                <div class="flex items-center justify-between text-[#F5F1E8]/50 text-[10px] pt-1 border-t border-[#F5F1E8]/5">
                    <span class="truncate">Hermes Engine</span>
                    <span class="font-mono text-[#E5C49B]">PROFIL PROD</span>
                </div>
            </a>
        </div>
    </aside>

    <!-- Main Content Layout Area -->
    <div class="flex-1 flex flex-col min-w-0">
        <!-- Topbar -->
        <header class="h-16 bg-[#05152B] border-b border-[#F5F1E8]/10 px-4 sm:px-6 flex items-center justify-between sticky top-0 z-30">
            <div class="flex items-center space-x-3">
                <button onclick="toggleSidebar()" class="md:hidden text-[#F5F1E8]/70 hover:text-[#F5F1E8] p-1.5 rounded hover:bg-[#0A213D]">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
                <!-- Breadcrumbs -->
                <div class="text-xs text-[#F5F1E8]/60 flex items-center space-x-1.5">
                    <span class="text-[#F5F1E8]/40">Aether</span>
                    <span class="text-[#F5F1E8]/30">/</span>
                    <span class="text-[#E5C49B] font-medium">@yield('page_title', 'Dashboard')</span>
                </div>
            </div>

            <div class="flex items-center space-x-3 sm:space-x-4">
                <!-- Emiten Search Bar -->
                <form action="{{ route('dashboard.market') }}" method="GET" class="relative hidden sm:block">
                    <input type="text" name="search" id="tickerSearchInput" value="{{ request('search') }}" placeholder="Cari emiten (cth: BBCA, TLKM)..." class="w-48 lg:w-64 bg-[#0A213D] border border-[#F5F1E8]/15 rounded px-3 py-1.5 text-xs text-[#F5F1E8] placeholder-[#F5F1E8]/35 focus:outline-none focus:border-[#E5C49B] transition">
                    <span class="absolute right-2.5 top-1.5 text-[10px] font-mono text-[#F5F1E8]/30 border border-[#F5F1E8]/15 px-1 rounded">/</span>
                </form>

                <!-- On-Demand Modal Trigger -->
                <button onclick="openResearchModal()" class="px-2.5 py-1.5 bg-[#E5C49B] hover:bg-[#D6B284] text-[#05152B] rounded text-xs font-semibold tracking-wide transition flex items-center shadow-sm">
                    <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path></svg>
                    Riset AI
                </button>

                <!-- Profile Dropdown -->
                <div class="relative">
                    <button onclick="toggleProfileDropdown()" id="profileDropdownBtn" class="flex items-center space-x-2 text-xs text-[#F5F1E8]/80 hover:text-[#F5F1E8] p-1.5 rounded hover:bg-[#0A213D] transition focus:outline-none">
                        <div class="w-7 h-7 rounded bg-[#0D2B4E] border border-[#E5C49B]/30 flex items-center justify-center font-bold text-[#E5C49B] text-xs">
                            {{ substr($brandConfig['analyst_name'] ?? 'AL', 0, 2) }}
                        </div>
                        <span class="hidden md:inline font-medium text-xs">{{ $brandConfig['analyst_name'] ?? 'Analyst' }}</span>
                        <svg class="w-3.5 h-3.5 text-[#F5F1E8]/50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>

                    <!-- Dropdown Content -->
                    <div id="profileDropdownMenu" class="hidden absolute right-0 mt-2 w-48 bg-[#0D2B4E] border border-[#E5C49B]/25 rounded shadow-xl py-1 z-50 text-xs">
                        <div class="px-3 py-2 border-b border-[#F5F1E8]/10">
                            <p class="font-semibold text-[#F5F1E8]">{{ $brandConfig['analyst_name'] ?? 'Aether Lead Analyst' }}</p>
                            <p class="text-[10px] text-[#E5C49B] truncate font-mono">{{ $brandConfig['analyst_title'] ?? 'Lead Quantitative Analyst' }}</p>
                        </div>
                        <a href="{{ route('dashboard.profile') }}" class="flex items-center px-3 py-2 text-[#F5F1E8]/80 hover:bg-[#0A213D] hover:text-[#E5C49B] transition">
                            <svg class="w-4 h-4 mr-2 text-[#F5F1E8]/50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                            Profil Saya
                        </a>
                        <a href="{{ route('dashboard.health') }}" class="flex items-center px-3 py-2 text-[#F5F1E8]/80 hover:bg-[#0A213D] hover:text-[#E5C49B] transition">
                            <svg class="w-4 h-4 mr-2 text-[#F5F1E8]/50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                            System Health
                        </a>
                        <a href="{{ route('dashboard.settings') }}" class="flex items-center px-3 py-2 text-[#F5F1E8]/80 hover:bg-[#0A213D] hover:text-[#E5C49B] transition">
                            <svg class="w-4 h-4 mr-2 text-[#F5F1E8]/50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                            Pengaturan
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Flash Status Alerts -->
        @if(session('status'))
            <div class="mx-4 sm:mx-6 mt-4 p-3 rounded bg-emerald-900/40 border border-emerald-500/30 text-emerald-200 text-xs flex items-center justify-between">
                <span>{{ session('status') }}</span>
                <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-emerald-200">&times;</button>
            </div>
        @endif

        @if($errors->any())
            <div class="mx-4 sm:mx-6 mt-4 p-3 rounded bg-rose-900/40 border border-rose-500/30 text-rose-200 text-xs">
                <ul class="list-disc list-inside space-y-1">
                    @foreach($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Main Viewport Content -->
        <main class="flex-1 px-4 sm:px-6 py-6 overflow-x-hidden">
            @yield('content')
        </main>

        <!-- Institutional Footer -->
        <footer class="bg-[#05152B] border-t border-[#F5F1E8]/10 py-3.5 px-6 text-center text-[11px] text-[#F5F1E8]/40">
            <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-2">
                <div>
                    {{ $brandConfig['system_name'] ?? 'Aether Market Intelligence' }} &bull; Arsitektur Riset Finansial Institusional BEI
                </div>
                <div class="font-mono text-[10px] text-[#E5C49B]/70">
                    UI berbasis DESIGN.MD, pencocokan terhadap screen Stitch belum diverifikasi &bull; Strictly Zero Live Broker Order
                </div>
            </div>
        </footer>
    </div>

    <!-- On-Demand Asynchronous Research Modal -->
    <div id="researchModal" class="fixed inset-0 bg-black/80 z-50 hidden items-center justify-center p-4">
        <div class="bg-[#0D2B4E] border border-[#E5C49B]/40 rounded-lg max-w-md w-full shadow-2xl p-5 space-y-4">
            <div class="flex items-center justify-between border-b border-[#F5F1E8]/10 pb-3">
                <div class="flex items-center space-x-2">
                    <div class="w-6 h-6 rounded bg-[#E5C49B] text-[#05152B] flex items-center justify-center font-bold text-xs">AI</div>
                    <h3 class="text-sm font-semibold text-[#F5F1E8]">Permintaan Riset On-Demand</h3>
                </div>
                <button onclick="closeResearchModal()" class="text-[#F5F1E8]/50 hover:text-[#F5F1E8] text-lg leading-none">&times;</button>
            </div>

            <p class="text-xs text-[#F5F1E8]/70 leading-relaxed">
                Kirim permintaan analisis kecerdasan buatan berbasis evidence multi-sumber (teknikal, fundamental, dan aksi korporasi). Proses berjalan asinkron tanpa eksekusi transaksi broker.
            </p>

            <form id="onDemandResearchForm" onsubmit="handleResearchSubmit(event)" class="space-y-3">
                <div>
                    <label class="block text-[11px] font-medium text-[#F5F1E8]/70 uppercase tracking-wider mb-1">Kode Emiten (BEI)</label>
                    <input type="text" id="modalTickerInput" required placeholder="Contoh: BBCA, TLKM, ASII" class="w-full bg-[#0A213D] border border-[#F5F1E8]/15 rounded px-3 py-2 text-xs text-[#F5F1E8] uppercase font-mono tracking-wider focus:outline-none focus:border-[#E5C49B]">
                </div>

                <div>
                    <label class="block text-[11px] font-medium text-[#F5F1E8]/70 uppercase tracking-wider mb-1">Fokus Analisis</label>
                    <select id="modalAnalysisType" class="w-full bg-[#0A213D] border border-[#F5F1E8]/15 rounded px-3 py-2 text-xs text-[#F5F1E8] focus:outline-none focus:border-[#E5C49B]">
                        <option value="COMPREHENSIVE">Komprehensif (Teknikal + Fundamental + Narasi Gemini)</option>
                        <option value="SWING">Penyaringan Ayunan Swing (SWING_FILTER_V1)</option>
                        <option value="TECHNICAL">Analisis Geometri & Indikator Teknikal</option>
                        <option value="FUNDAMENTAL">Audit Valuasi & Laporan Keuangan Fiskal</option>
                    </select>
                </div>

                <div id="modalResponseArea" class="hidden text-xs p-2.5 rounded"></div>

                <div class="flex items-center justify-end space-x-2 pt-2 border-t border-[#F5F1E8]/10">
                    <button type="button" onclick="closeResearchModal()" class="px-3 py-1.5 rounded text-xs text-[#F5F1E8]/60 hover:text-[#F5F1E8] hover:bg-[#0A213D] transition">Batal</button>
                    <button type="submit" id="modalSubmitBtn" class="px-4 py-1.5 bg-[#E5C49B] hover:bg-[#D6B284] text-[#05152B] rounded text-xs font-semibold tracking-wide transition">
                        Jalankan Riset
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Global Client-side Utilities -->
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('mainSidebar');
            const backdrop = document.getElementById('sidebarBackdrop');
            if (sidebar.classList.contains('-translate-x-full')) {
                sidebar.classList.remove('-translate-x-full');
                backdrop.classList.remove('hidden');
            } else {
                sidebar.classList.add('-translate-x-full');
                backdrop.classList.add('hidden');
            }
        }

        function toggleProfileDropdown() {
            const menu = document.getElementById('profileDropdownMenu');
            menu.classList.toggle('hidden');
        }

        document.addEventListener('click', function(event) {
            const btn = document.getElementById('profileDropdownBtn');
            const menu = document.getElementById('profileDropdownMenu');
            if (btn && menu && !btn.contains(event.target) && !menu.contains(event.target)) {
                menu.classList.add('hidden');
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
                e.preventDefault();
                const search = document.getElementById('tickerSearchInput');
                if (search) search.focus();
            }
            if (e.key === 'Escape') {
                closeResearchModal();
                const menu = document.getElementById('profileDropdownMenu');
                if (menu) menu.classList.add('hidden');
            }
        });

        function openResearchModal(prefillTicker = '') {
            const modal = document.getElementById('researchModal');
            const input = document.getElementById('modalTickerInput');
            const resArea = document.getElementById('modalResponseArea');
            if (modal) {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }
            if (input) {
                if (prefillTicker) input.value = prefillTicker;
                input.focus();
            }
            if (resArea) {
                resArea.className = 'hidden';
                resArea.innerHTML = '';
            }
        }

        function closeResearchModal() {
            const modal = document.getElementById('researchModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
        }

        async function handleResearchSubmit(e) {
            e.preventDefault();
            const ticker = document.getElementById('modalTickerInput').value.trim().toUpperCase();
            const analysisType = document.getElementById('modalAnalysisType').value;
            const submitBtn = document.getElementById('modalSubmitBtn');
            const resArea = document.getElementById('modalResponseArea');
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            submitBtn.disabled = true;
            submitBtn.innerText = 'Mengirim...';

            try {
                const response = await fetch("{{ route('dashboard.research.submit') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ ticker: ticker, analysis_type: analysisType })
                });

                const data = await response.json();
                resArea.classList.remove('hidden');

                if (data.success) {
                    resArea.className = 'text-xs p-2.5 rounded bg-emerald-950/60 border border-emerald-500/30 text-emerald-200';
                    resArea.innerHTML = `<span class="font-bold">Request Terkirim:</span> ${data.message} <br><span class="font-mono text-[10px] text-emerald-400">Request ID: ${data.request_id}</span>`;
                    setTimeout(() => {
                        closeResearchModal();
                    }, 3500);
                } else {
                    resArea.className = 'text-xs p-2.5 rounded bg-rose-950/60 border border-rose-500/30 text-rose-200';
                    resArea.innerText = data.message || 'Gagal memproses permintaan riset.';
                }
            } catch (err) {
                resArea.classList.remove('hidden');
                resArea.className = 'text-xs p-2.5 rounded bg-rose-950/60 border border-rose-500/30 text-rose-200';
                resArea.innerText = 'Terjadi kesalahan jaringan saat mengirim permintaan riset.';
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerText = 'Jalankan Riset';
            }
        }

        async function toggleWatchlist(ticker, btnElement) {
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            try {
                const response = await fetch("{{ route('dashboard.watchlist.toggle') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ ticker: ticker })
                });
                const data = await response.json();
                if (data.success) {
                    if (data.in_watchlist) {
                        btnElement.classList.add('text-[#E5C49B]', 'border-[#E5C49B]');
                        btnElement.classList.remove('text-[#F5F1E8]/30', 'border-[#F5F1E8]/10');
                        btnElement.title = 'Dalam Pemantauan Aktif';
                    } else {
                        btnElement.classList.remove('text-[#E5C49B]', 'border-[#E5C49B]');
                        btnElement.classList.add('text-[#F5F1E8]/30', 'border-[#F5F1E8]/10');
                        btnElement.title = 'Tambahkan ke Pemantauan';
                    }
                }
            } catch (err) {
                console.error('Watchlist toggle failed:', err);
            }
        }
    </script>
</body>
</html>
