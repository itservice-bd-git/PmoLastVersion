{{-- the pieces of a "ความคืบหน้าสัปดาห์นี้" row, shared by the phone card and the table: $part = added | bar | work --}}
@switch($part)
    @case('added')
        @if ($r['added'] > 0)
            <span class="text-xl font-bold tabular-nums text-[#17805E]">+{{ $r['added'] }}%</span>
        @elseif ($r['ticks'] > 0)
            <span class="text-sm font-medium text-slate-600">ทำต่อ ยังไม่ขยับ %</span>
        @else
            <span class="inline-flex whitespace-nowrap rounded bg-[#FFF1E6] px-2 py-0.5 text-sm font-semibold text-[#B3400B]">ไม่มีความคืบหน้า</span>
        @endif
        @break
    @case('bar')
        <span class="block text-sm text-slate-700"><span class="tabular-nums">{{ $r['start'] }}%</span> → <strong class="tabular-nums text-slate-900">{{ $r['now'] }}%</strong></span>
        <span class="mt-1.5 flex h-2.5 overflow-hidden rounded-full bg-slate-200" role="img" aria-label="ต้นสัปดาห์ {{ $r['start'] }}% ตอนนี้ {{ $r['now'] }}% เพิ่มขึ้น {{ $r['added'] }}%">
            <span class="block h-full bg-slate-400" style="width: {{ min(100, $r['start']) }}%"></span>
            <span class="block h-full bg-[#1F9D74]" style="width: {{ min(100 - min(100, $r['start']), $r['added']) }}%"></span>
        </span>
        @break
    @case('work')
        @if ($r['ticks'] || $r['subtasksDone'] || $r['cabinetsDone'])
            <span class="block whitespace-nowrap">Checklist <strong class="tabular-nums text-slate-900">{{ $r['ticks'] }}</strong> รายการ</span>
            <span class="block whitespace-nowrap">งานย่อยเสร็จ <strong class="tabular-nums text-slate-900">{{ $r['subtasksDone'] }}</strong> · ตู้เสร็จ <strong class="tabular-nums text-slate-900">{{ $r['cabinetsDone'] }}</strong></span>
            <span class="block text-slate-500">ตู้ผลิตเสร็จแล้ว {{ $r['cabinetsMade'] }}/{{ $r['cabinets'] }}</span>
        @else
            <span class="text-slate-500">ยังไม่มีงานที่ทำ · ตู้ผลิตเสร็จแล้ว {{ $r['cabinetsMade'] }}/{{ $r['cabinets'] }}</span>
        @endif
        @break
@endswitch
