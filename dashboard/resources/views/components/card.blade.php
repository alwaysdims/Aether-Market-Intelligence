@props([
    'title' => null,
    'subtitle' => null,
    'badge' => null,
    'badgeClass' => 'bg-[#E5C49B]/10 text-[#E5C49B] border border-[#E5C49B]/30',
    'class' => '',
])

<div {{ $attributes->merge(['class' => 'bg-[#0A213D] border border-[#F5F1E8]/10 rounded-xl p-5 shadow-sm ' . $class]) }}>
    @if($title || $subtitle || $badge || isset($headerAction))
        <div class="flex items-start justify-between gap-4 mb-4 pb-3 border-b border-[#F5F1E8]/10">
            <div>
                @if($title)
                    <div class="flex items-center gap-2">
                        <h3 class="text-sm font-semibold text-[#F5F1E8] tracking-tight uppercase">{{ $title }}</h3>
                        @if($badge)
                            <span class="px-2 py-0.5 rounded text-[10px] font-mono font-medium {{ $badgeClass }}">
                                {{ $badge }}
                            </span>
                        @endif
                    </div>
                @endif
                @if($subtitle)
                    <p class="text-xs text-[#F5F1E8]/50 mt-0.5">{{ $subtitle }}</p>
                @endif
            </div>
            @if(isset($headerAction))
                <div>
                    {{ $headerAction }}
                </div>
            @endif
        </div>
    @endif

    <div>
        {{ $slot }}
    </div>
</div>
