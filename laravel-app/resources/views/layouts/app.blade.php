<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name', 'Hermes Market Intelligence') }}</title>
    <!-- Tailwind CSS CDN for instant robust local styling -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Alpine.js for lightweight reactivity -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        darkBg: '#0f172a',
                        cardBg: '#1e293b',
                        borderBg: '#334155',
                        accentBlue: '#38bdf8',
                        accentGreen: '#4ade80',
                        accentRed: '#f87171',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen font-sans flex flex-col">
    <!-- Navbar -->
    <header class="bg-slate-800 border-b border-slate-700 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between h-16">
            <div class="flex items-center space-x-3">
                <div class="w-9 h-9 bg-sky-500 rounded-lg flex items-center justify-center font-bold text-slate-900 text-xl shadow-lg shadow-sky-500/20">
                    ⚡
                </div>
                <div>
                    <h1 class="text-base font-bold text-white tracking-wide">Hermes Market Intelligence</h1>
                    <p class="text-xs text-sky-400 font-mono">Control Plane &bull; BEI Research</p>
                </div>
            </div>

            <!-- Navigation Links -->
            <nav class="flex items-center space-x-1 sm:space-x-3 text-sm font-medium">
                <a href="{{ route('dashboard.overview') }}" class="px-3 py-1.5 rounded-md transition {{ request()->routeIs('dashboard.overview') ? 'bg-sky-600 text-white' : 'text-slate-300 hover:bg-slate-700' }}">Overview</a>
                <a href="{{ route('dashboard.market') }}" class="px-3 py-1.5 rounded-md transition {{ request()->routeIs('dashboard.market') ? 'bg-sky-600 text-white' : 'text-slate-300 hover:bg-slate-700' }}">Market Monitor</a>
                <a href="{{ route('dashboard.news') }}" class="px-3 py-1.5 rounded-md transition {{ request()->routeIs('dashboard.news') ? 'bg-sky-600 text-white' : 'text-slate-300 hover:bg-slate-700' }}">News & Events</a>
                <a href="{{ route('dashboard.history') }}" class="px-3 py-1.5 rounded-md transition {{ request()->routeIs('dashboard.history') ? 'bg-sky-600 text-white' : 'text-slate-300 hover:bg-slate-700' }}">AI History</a>
                <a href="{{ route('dashboard.health') }}" class="px-3 py-1.5 rounded-md transition {{ request()->routeIs('dashboard.health') ? 'bg-sky-600 text-white' : 'text-slate-300 hover:bg-slate-700' }}">System Health</a>
                <a href="{{ route('dashboard.settings') }}" class="px-3 py-1.5 rounded-md transition {{ request()->routeIs('dashboard.settings') ? 'bg-sky-600 text-white' : 'text-slate-300 hover:bg-slate-700' }}">Settings</a>
            </nav>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="bg-slate-900 border-t border-slate-800 text-xs text-slate-500 py-4 text-center">
        <p>Hermes Market Intelligence &bull; AI Research Engine &bull; Strictly Zero Live Broker Execution</p>
    </footer>
</body>
</html>
