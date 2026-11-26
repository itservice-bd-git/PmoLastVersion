@props(['status', 'id' => null])

@php
    $map = [
        'draft' => ['bg-slate-100 text-slate-600', 'ร่าง'],
        'planning' => ['bg-indigo-100 text-indigo-700', 'วางแผน'],
        'not_started' => ['bg-slate-100 text-slate-600', 'ยังไม่เริ่ม'],
        'in_progress' => ['bg-blue-100 text-blue-700', 'กำลังดำเนินการ'],
        'completed' => ['bg-emerald-100 text-emerald-700', 'เสร็จสิ้น'],
        'on_hold' => ['bg-amber-100 text-amber-700', 'พักไว้'],
        'cancelled' => ['bg-red-100 text-red-700', 'ยกเลิก'],
    ];
    [$classes, $label] = $map[$status] ?? ['bg-slate-100 text-slate-600', ucfirst(str_replace('_', ' ', $status ?? '-'))];
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium whitespace-nowrap $classes"]) }}
      @if($id) id="status-badge-{{ $id }}" @endif>
    {{ $label }}
</span>
