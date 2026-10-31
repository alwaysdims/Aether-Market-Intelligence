@extends('layouts.app')

@section('page_title', 'Profil Analis & Identitas Sistem')

@section('content')
<div class="space-y-6">

    <!-- Header Strip -->
    <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center space-x-2 mb-1">
                <span class="w-2.5 h-2.5 rounded-full bg-[#E5C49B]"></span>
                <h1 class="text-lg font-bold text-[#F5F1E8] tracking-tight uppercase">Profil Analis & Identitas Aether</h1>
            </div>
            <p class="text-xs text-[#F5F1E8]/60">
                Kelola informasi akun analis terotorisasi, nama tampilan aplikasi, logo institusional, dan preferensi alokasi riset.
            </p>
        </div>

        <span class="text-xs text-[#E5C49B] bg-[#0D2B4E] border border-[#E5C49B]/30 px-3 py-1.5 rounded font-mono">
            User ID: {{ $analyst['telegram_id'] }} &bull; Otorisasi Aktif
        </span>
    </div>

    @if(session('success'))
        <div class="bg-emerald-950/60 border border-emerald-500/40 text-emerald-200 px-4 py-3 rounded-lg text-xs font-mono flex items-center space-x-2">
            <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if($errors->any())
        <div class="bg-rose-950/60 border border-rose-500/40 text-rose-200 px-4 py-3 rounded-lg text-xs font-mono">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Column 1: Analyst Profile Card -->
        <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
            <h2 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">
                Profil Pengguna Terdaftar
            </h2>

            <div class="flex items-center space-x-4 pt-2">
                <div class="w-14 h-14 rounded-lg bg-[#0D2B4E] border border-[#E5C49B]/30 flex items-center justify-center text-[#E5C49B] text-xl font-bold font-mono">
                    {{ substr($analyst['name'], 0, 2) }}
                </div>
                <div>
                    <h3 class="text-sm font-bold text-[#F5F1E8]">{{ $analyst['name'] }}</h3>
                    <p class="text-xs text-[#F5F1E8]/60">{{ $analyst['role'] }}</p>
                    <span class="inline-block mt-1 text-[10px] bg-emerald-950/60 text-emerald-400 border border-emerald-500/30 font-mono px-2 py-0.5 rounded">
                        ALLOWLIST_AUTHORIZED
                    </span>
                </div>
            </div>

            <div class="border-t border-[#F5F1E8]/10 pt-3 space-y-2 text-xs font-mono">
                <div class="flex justify-between py-1">
                    <span class="text-[#F5F1E8]/40">Email:</span>
                    <span class="text-[#F5F1E8]/80">{{ $analyst['email'] }}</span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-[#F5F1E8]/40">Telegram Chat ID:</span>
                    <span class="text-[#E5C49B]">{{ $analyst['telegram_id'] }}</span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-[#F5F1E8]/40">Sesi Terdaftar:</span>
                    <span class="text-[#F5F1E8]/60">{{ $analyst['authorized_at'] }}</span>
                </div>
            </div>

            <!-- Profile Edit Form -->
            <form action="{{ route('dashboard.profile.update') }}" method="POST" class="border-t border-[#F5F1E8]/10 pt-4 space-y-3">
                @csrf
                @if(session('status'))
                    <div class="p-2.5 rounded bg-emerald-950/70 border border-emerald-500/40 text-emerald-300 text-xs font-mono">
                        {{ session('status') }}
                    </div>
                @endif
                @if($errors->any())
                    <div class="p-2.5 rounded bg-rose-950/70 border border-rose-500/40 text-rose-300 text-xs font-mono">
                        @foreach($errors->all() as $err)
                            <div>&bull; {{ $err }}</div>
                        @endforeach
                    </div>
                @endif
                <div>
                    <label class="block text-[10px] font-mono uppercase text-[#F5F1E8]/50 mb-1">Nama Tampilan Analis</label>
                    <input type="text" name="analyst_name" value="{{ old('analyst_name', $analyst['name']) }}" required class="w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded px-3 py-2 text-xs text-[#F5F1E8] focus:outline-none focus:border-[#E5C49B]">
                </div>
                <div>
                    <label class="block text-[10px] font-mono uppercase text-[#F5F1E8]/50 mb-1">Jabatan / Role Analis</label>
                    <input type="text" name="analyst_title" value="{{ old('analyst_title', $analyst['role']) }}" required class="w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded px-3 py-2 text-xs text-[#F5F1E8] focus:outline-none focus:border-[#E5C49B]">
                </div>
                <div>
                    <label class="block text-[10px] font-mono uppercase text-[#F5F1E8]/50 mb-1">Email Kontak</label>
                    <input type="email" name="email" value="{{ old('email', $analyst['email']) }}" required class="w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded px-3 py-2 text-xs text-[#F5F1E8] focus:outline-none focus:border-[#E5C49B]">
                </div>
                <button type="submit" class="w-full px-4 py-2 bg-[#0D2B4E] hover:bg-[#153660] text-[#E5C49B] border border-[#E5C49B]/30 rounded text-xs font-semibold tracking-wide transition">
                    Simpan Perubahan Profil
                </button>
            </form>
        </div>

        <!-- Column 2 & 3: Brand Identity & Allocation Targets -->
        <div class="lg:col-span-2 space-y-6">

            <!-- Brand Identity Management -->
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
                <div class="flex items-center justify-between border-b border-[#F5F1E8]/10 pb-3">
                    <div>
                        <h2 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">
                            Identitas Visual & Logo Sistem
                        </h2>
                        <p class="text-xs text-[#F5F1E8]/60 mt-0.5">
                            Kustomisasi nama institusi dan logo platform (format PNG/JPG, maksimal 2MB. SVG dan executable ditolak).
                        </p>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center gap-6 pt-2">
                    <!-- Current Logo Preview -->
                    <div class="flex flex-col items-center p-4 bg-[#0D2B4E] border border-[#F5F1E8]/10 rounded-lg w-40 shrink-0">
                        <span class="text-[10px] font-mono uppercase text-[#F5F1E8]/40 mb-2">Preview Logo</span>
                        <div class="w-20 h-20 rounded bg-[#05152B] border border-[#E5C49B]/30 flex items-center justify-center p-2 overflow-hidden">
                            <img src="{{ asset('images/logo.png') }}?v={{ time() }}" alt="Aether Logo" class="max-h-full max-w-full object-contain">
                        </div>
                        <span class="text-[9px] font-mono text-[#F5F1E8]/50 mt-2">public/images/logo.png</span>
                    </div>

                    <!-- Brand Form -->
                    <form action="{{ route('dashboard.brand.update') }}" method="POST" enctype="multipart/form-data" class="flex-1 space-y-3">
                        @csrf
                        @if($errors->has('logo'))
                            <div class="p-2.5 rounded bg-rose-950/70 border border-rose-500/40 text-rose-300 text-xs font-mono">
                                {{ $errors->first('logo') }}
                            </div>
                        @endif
                        <div>
                            <label class="block text-[10px] font-mono uppercase text-[#F5F1E8]/50 mb-1">Nama Tampilan Sistem</label>
                            <input type="text" name="system_name" value="{{ old('system_name', $brandConfig['system_name'] ?? 'Aether Market Intelligence') }}" required class="w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded px-3 py-2 text-xs text-[#F5F1E8] focus:outline-none focus:border-[#E5C49B]">
                        </div>

                        <div>
                            <label class="block text-[10px] font-mono uppercase text-[#F5F1E8]/50 mb-1">Unggah Logo Baru (PNG / JPG, Maks 2MB)</label>
                            <input type="file" name="logo" accept="image/png,image/jpeg,image/jpg" class="w-full bg-[#0D2B4E] border border-[#F5F1E8]/15 rounded px-3 py-1.5 text-xs text-[#F5F1E8]/80 file:mr-3 file:py-1 file:px-2.5 file:rounded file:border-0 file:text-[11px] file:bg-[#E5C49B] file:text-[#05152B] file:font-semibold hover:file:bg-[#D6B284]">
                            <p class="text-[10px] text-[#F5F1E8]/40 mt-1">File biner executable (.exe, .sh) dan SVG mentah ditolak otomatis untuk keamanan.</p>
                        </div>

                        <div class="pt-2 flex items-center space-x-3">
                            <button type="submit" class="px-4 py-2 bg-[#E5C49B] hover:bg-[#D6B284] text-[#05152B] rounded text-xs font-semibold tracking-wide transition">
                                Perbarui Identitas Sistem
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Allocation Preference Target (Explicit Non-Transactional Disclaimer) -->
            <div class="bg-[#0A213D] border border-[#F5F1E8]/10 rounded-lg p-5 space-y-4">
                <div class="flex items-center justify-between border-b border-[#F5F1E8]/10 pb-3">
                    <div>
                        <h2 class="text-xs font-mono font-bold uppercase tracking-wider text-[#E5C49B]">
                            Target Alokasi Portofolio Pengguna
                        </h2>
                        <p class="text-xs text-[#F5F1E8]/60 mt-0.5">
                            Preferensi strategi alokasi riset (target pengguna untuk pemantauan, bukan eksekusi transaksi otomatis).
                        </p>
                    </div>
                    <span class="text-[10px] font-mono bg-amber-950/60 text-amber-300 border border-amber-500/30 px-2 py-0.5 rounded">
                        NON-TRANSACTIONAL TARGET
                    </span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                    <div class="bg-[#0D2B4E] border border-[#F5F1E8]/10 rounded-lg p-4 space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-bold text-[#F5F1E8]">Taktikal / Swing Trading</span>
                            <span class="text-sm font-mono font-bold text-[#E5C49B]">50%</span>
                        </div>
                        <div class="w-full bg-[#05152B] rounded-full h-2">
                            <div class="bg-[#E5C49B] h-2 rounded-full" style="width: 50%"></div>
                        </div>
                        <p class="text-[10px] text-[#F5F1E8]/50">
                            Fokus pada setup momentum berbasis likuiditas, fraksi tick resmi BEI, dan horizon 5 hari bursa.
                        </p>
                    </div>

                    <div class="bg-[#0D2B4E] border border-[#F5F1E8]/10 rounded-lg p-4 space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-bold text-[#F5F1E8]">Fundamental / Investasi</span>
                            <span class="text-sm font-mono font-bold text-cyan-400">50%</span>
                        </div>
                        <div class="w-full bg-[#05152B] rounded-full h-2">
                            <div class="bg-cyan-400 h-2 rounded-full" style="width: 50%"></div>
                        </div>
                        <p class="text-[10px] text-[#F5F1E8]/50">
                            Fokus pada valuasi wajar, ROE di atas rata-rata industri, solvabilitas DER sehat, dan dividen.
                        </p>
                    </div>
                </div>

                <div class="bg-[#05152B] border border-[#F5F1E8]/10 rounded p-3 text-[11px] text-[#F5F1E8]/60 space-y-1">
                    <p class="font-bold text-amber-300">Pemberitahuan Audit Kepatuhan:</p>
                    <p>
                        Target komposisi 50% Trading / 50% Investasi merupakan target perencanaan pribadi pengguna.
                        Sistem Aether Market Intelligence beroperasi dalam mode strictly advisory/read-only tanpa koneksi API broker,
                        tanpa hak akses order, dan tidak pernah melakukan transaksi beli/jual sintetis maupun riil.
                    </p>
                </div>
            </div>

        </div>

    </div>

</div>
@endsection
