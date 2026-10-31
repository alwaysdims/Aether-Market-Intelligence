@props([
    'variant' => 'neutral', // neutral, success, warning, danger, accent, info
    'class' => '',
])

@php
    $styles = match($variant) {
        'success' => 'bg-emerald-950/60 text-emerald-300 border-emerald-500/30',
        'warning' => 'bg-amber-950/60 text-amber-300 border-amber-500/30',
        'danger' => 'bg-rose-950/60 text-rose-300 border-rose-500/30',
        'accent' => 'bg-[#E5C49B]/10 text-[#E5C49B] border-[#E5C49B]/30',
        'info' => 'bg-sky-950/60 text-sky-300 border-sky-500/30',
        default => 'bg-[#0D2B4E] text-[#F5F1E8]/70 border-[#F5F1E8]/15',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center px-2 py-0.5 rounded text-[11px] font-mono font-medium border $styles $class"]) }}>
    {{ $slot }}
</span>
