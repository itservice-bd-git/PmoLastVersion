{{-- Settings > สาเหตุการเลื่อน (ProjectDateReasonController): the reasons offered in หน้าโครงการ › "เลื่อน / แก้วัน" --}}
@php $colors = \App\Models\ProjectDateReason::COLORS; @endphp
<x-app-layout>
    <x-slot name="header"><h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2></x-slot>
    <div class="max-w-none mx-auto space-y-4">
        <x-settings-nav />
        <x-card title="สาเหตุการเลื่อนวันโครงการ" subtitle="ให้เลือกตอนกด &quot;เลื่อน / แก้วัน&quot; ในหน้าโครงการ · สาเหตุที่มีในประวัติแล้วลบไม่ได้ จะถูกปิดไว้แทน (ประวัติเดิมยังแสดงชื่อเดิม)">
            <div class="divide-y divide-slate-100">
                @forelse ($reasons as $r)
                    <div class="flex flex-wrap items-center gap-2 py-2">
                        <div class="flex flex-col">
                            <form method="POST" action="{{ route('settings.date-reasons.move', $r) }}">@csrf @method('PATCH')<input type="hidden" name="dir" value="up">
                                <button class="px-1 text-xs text-slate-500 hover:text-slate-900" aria-label="เลื่อน {{ $r->name }} ขึ้น" @disabled($loop->first)>▲</button></form>
                            <form method="POST" action="{{ route('settings.date-reasons.move', $r) }}">@csrf @method('PATCH')<input type="hidden" name="dir" value="down">
                                <button class="px-1 text-xs text-slate-500 hover:text-slate-900" aria-label="เลื่อน {{ $r->name }} ลง" @disabled($loop->last)>▼</button></form>
                        </div>
                        <form method="POST" action="{{ route('settings.date-reasons.update', $r) }}" class="flex min-w-0 flex-1 flex-wrap items-center gap-2" x-data="{ color: @js($r->color) }">@csrf @method('PUT')
                            <span class="inline-flex h-7 items-center rounded-full px-3 text-sm font-semibold" :class="@js(collect($colors)->map(fn ($c) => implode(' ', $c)))[color]">{{ $r->name }}</span>
                            <label class="sr-only" for="reason-name-{{ $r->id }}">ชื่อสาเหตุ</label>
                            <input id="reason-name-{{ $r->id }}" type="text" name="name" value="{{ $r->name }}" maxlength="100" required class="min-w-[12rem] flex-1 rounded-lg border-slate-300 text-sm">
                            <label class="sr-only" for="reason-color-{{ $r->id }}">สี</label>
                            <select id="reason-color-{{ $r->id }}" name="color" x-model="color" class="rounded-lg border-slate-300 text-sm">
                                @foreach (array_keys($colors) as $c)<option value="{{ $c }}">{{ ['indigo' => 'คราม', 'amber' => 'เหลือง', 'sky' => 'ฟ้า', 'orange' => 'ส้ม', 'red' => 'แดง', 'emerald' => 'เขียว', 'slate' => 'เทา'][$c] }}</option>@endforeach
                            </select>
                            <label class="inline-flex items-center gap-1.5 text-sm text-slate-700"><input type="checkbox" name="is_active" value="1" @checked($r->is_active) class="rounded border-slate-300"> ใช้งาน</label>
                            <span class="text-xs text-slate-500">ใช้ไป {{ $used[$r->id] ?? 0 }} ครั้ง</span>
                            <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">บันทึก</button>
                        </form>
                        <form method="POST" action="{{ route('settings.date-reasons.destroy', $r) }}" onsubmit="return confirm('ลบสาเหตุ &quot;{{ $r->name }}&quot;?')">@csrf @method('DELETE')
                            <button class="text-xs text-red-600 hover:underline">ลบ</button>
                        </form>
                    </div>
                @empty
                    <p class="py-2 text-sm text-slate-500">ยังไม่มีสาเหตุ — เพิ่มด้านล่าง</p>
                @endforelse
            </div>
        </x-card>
        <x-card title="เพิ่มสาเหตุ">
            <form method="POST" action="{{ route('settings.date-reasons.store') }}" class="flex flex-wrap gap-2">@csrf
                <label class="sr-only" for="reason-new">ชื่อสาเหตุ</label>
                <input id="reason-new" type="text" name="name" required maxlength="100" placeholder="เช่น รอหน้างานลูกค้า" class="min-w-[12rem] flex-1 rounded-lg border-slate-300 text-sm">
                <label class="sr-only" for="reason-new-color">สี</label>
                <select id="reason-new-color" name="color" class="rounded-lg border-slate-300 text-sm">
                    @foreach (array_keys($colors) as $c)<option value="{{ $c }}">{{ ['indigo' => 'คราม', 'amber' => 'เหลือง', 'sky' => 'ฟ้า', 'orange' => 'ส้ม', 'red' => 'แดง', 'emerald' => 'เขียว', 'slate' => 'เทา'][$c] }}</option>@endforeach
                </select>
                <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">+ เพิ่ม</button>
            </form>
        </x-card>
    </div>
</x-app-layout>
