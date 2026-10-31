@props([
    'title' => 'Data Tidak Tersedia',
    'message' => 'Belum ada rekod data untuk kriteria yang dipilih.',
    'icon' => null,
    'class' => '',
])

<div {{ $attributes->merge(['class' => 'bg-[#0A213D]/60 border border-[#F5F1E8]/10 rounded-xl p-8 text-center ' . $class]) }}>
    <div class="w-12 h-12 rounded-full bg-[#0D2B4E] border border-[#F5F1E8]/10 flex items-center justify-center mx-auto mb-3 text-[#E5C49B]/70">
        @if($icon)
            {{ $icon }}
        @else
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg>
        @endif
    </div>
    <h4 class="text-sm font-semibold text-[#F5F1E8] mb-1">{{ $title }}</h4>
    <p class="text-xs text-[#F5F1E8]/50 max-w-md mx-auto">{{ $message }}</p>
    @if(isset($action))
        <div class="mt-4">
            {{ $action }}
        </div>
    @endif
</div>
