@props([
    'variant' => 'primary', // primary (gold), secondary (surface-2), outline, danger
    'size' => 'md', // sm, md, lg
    'type' => 'button',
    'class' => '',
])

@php
    $baseStyles = "inline-flex items-center justify-center font-semibold tracking-wide rounded-lg transition duration-150 ease-in-out focus:outline-none focus:ring-2 focus:ring-[#E5C49B]/50 disabled:opacity-50 disabled:cursor-not-allowed";
    
    $sizeStyles = match($size) {
        'sm' => 'px-2.5 py-1 text-xs',
        'lg' => 'px-5 py-2.5 text-sm',
        default => 'px-3.5 py-1.5 text-xs',
    };

    $variantStyles = match($variant) {
        'secondary' => 'bg-[#0D2B4E] hover:bg-[#153660] text-[#F5F1E8] border border-[#F5F1E8]/15',
        'outline' => 'bg-transparent hover:bg-[#0A213D] text-[#F5F1E8] border border-[#F5F1E8]/20 hover:border-[#E5C49B]/50',
        'danger' => 'bg-rose-900/60 hover:bg-rose-800 text-rose-200 border border-rose-500/30',
        default => 'bg-[#E5C49B] hover:bg-[#D6B284] text-[#05152B] shadow-sm',
    };
@endphp

<button type="{{ $type }}" {{ $attributes->merge(['class' => "$baseStyles $sizeStyles $variantStyles $class"]) }}>
    {{ $slot }}
</button>
