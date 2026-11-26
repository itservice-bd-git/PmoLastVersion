@props(['value' => 0, 'label' => null, 'size' => 'md', 'id' => null])

@php
    $value = max(0, min(100, (int) $value));
    $height = $size === 'sm' ? 'h-1.5' : 'h-2.5';
@endphp

{{-- the "progress-2" look (resources/css/app.css): a round light-blue track, orange filling in steps once as the page opens --}}
<div {{ $attributes->merge(['class' => 'w-full']) }} @if($id) data-progress-group="{{ $id }}" @endif>
    @if ($label)
        <div class="flex items-center justify-between text-xs font-medium text-slate-500 mb-1">
            <span>{{ $label }}</span>
            <span class="text-slate-700 font-semibold" @if($id) id="progress-value-{{ $id }}" @endif>{{ $value }}%</span>
        </div>
    @endif
    <div class="progress-2 w-full {{ $height }}" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $value }}" @if ($label) aria-label="{{ $label }}" @endif>
        <div class="progress-2-fill {{ $height }} transition-[width] duration-300"
             style="width: {{ $value }}%"
             @if($id) id="progress-fill-{{ $id }}" @endif></div>
    </div>
</div>
