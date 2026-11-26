@props(['title' => null, 'subtitle' => null])

<div {{ $attributes->merge(['class' => 'bg-white rounded-xl border border-slate-200 shadow-sm']) }}>
    @if ($title)
        {{-- the actions drop under the title on a narrow screen rather than squeezing it --}}
        <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <div class="min-w-0 flex-1 basis-64">
                <h3 class="text-base font-semibold text-ae-navy">{{ $title }}</h3>
                @if ($subtitle)
                    <p class="text-sm text-slate-600 mt-0.5 max-w-prose">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="shrink-0">{{ $actions }}</div>
            @endisset
        </div>
    @endif
    <div class="p-5">
        {{ $slot }}
    </div>
</div>
