@props(['value' => 0, 'label' => null, 'size' => 'md', 'id' => null])

@php
    $value = max(0, min(100, (int) $value));
    $barColor = fn ($v) => match (true) {
        $v >= 100 => 'bg-emerald-500',
        $v >= 60 => 'bg-blue-500',
        $v >= 30 => 'bg-amber-500',
        default => 'bg-slate-300',
    };
    $height = $size === 'sm' ? 'h-1.5' : 'h-2.5';
@endphp

<div {{ $attributes->merge(['class' => 'w-full']) }} @if($id) data-progress-group="{{ $id }}" @endif>
    @if ($label)
        <div class="flex items-center justify-between text-xs font-medium text-slate-500 mb-1">
            <span>{{ $label }}</span>
            <span class="text-slate-700 font-semibold" @if($id) id="progress-value-{{ $id }}" @endif>{{ $value }}%</span>
        </div>
    @endif
    <div class="w-full {{ $height }} rounded-full bg-slate-100 overflow-hidden">
        <div class="{{ $height }} {{ $barColor($value) }} rounded-full transition-all"
             style="width: {{ $value }}%"
             @if($id) id="progress-fill-{{ $id }}" data-color-fn="progress" @endif></div>
    </div>
</div>
