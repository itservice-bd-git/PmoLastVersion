{{-- Settings > สีสถานะ Checklist: the coloured tags a cabinet's checklist item can carry, several per item (App\Services\ChecklistStatuses).
     One form (a modal) adds and edits; a status in use is switched off rather than deleted. --}}
@php
    $palette = collect(\App\Models\ChecklistStatus::PALETTE)->map(fn ($p, $k) => ['key' => $k, 'name' => $p[0], 'dot' => $p[1], 'bg' => $p[2], 'fg' => $p[3]])->values();
    $deptNames = $departments->pluck('name', 'id');
    $rows = $statuses->map(fn ($s) => [
        'id' => $s->id, 'name' => $s->name, 'description' => (string) $s->description, 'color' => $s->color, 'group_name' => (string) $s->group_name,
        'scope' => $s->department_ids ? 'some' : 'all', 'department_ids' => array_map('intval', $s->department_ids ?? []),
        'url' => route('settings.checklist-statuses.update', $s),
    ])->keyBy('id');
    $old = session()->getOldInput();
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2>
    </x-slot>

    <div x-data="{
            palette: @js($palette), rows: @js($rows), storeUrl: @js(route('settings.checklist-statuses.store')),
            f: null,
            blank() { return { id: null, name: '', description: '', color: 'red', group_name: '', scope: 'all', department_ids: [], url: this.storeUrl }; },
            open(id) { this.f = id ? JSON.parse(JSON.stringify(this.rows[id])) : this.blank(); this.$dispatch('open-modal', 'checklist-status'); this.$nextTick(() => this.$refs.name && this.$refs.name.focus()); },
            sw(key) { return this.palette.find((p) => p.key === key) || this.palette[0]; },
         }"
         x-init="@if ($errors->any() && $old) f = { ...blank(), ...@js($old), id: @js($old['id'] ?? null), url: @js(isset($old['id']) && $rows->has((int) $old['id']) ? $rows[(int) $old['id']]['url'] : route('settings.checklist-statuses.store')), department_ids: (@js($old['department_ids'] ?? [])).map(Number) }; $nextTick(() => $dispatch('open-modal', 'checklist-status')); @endif">
        <div class="max-w-none mx-auto space-y-4">
        <x-settings-nav />

        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-ae-navy">สีสถานะ Checklist</h3>
                    <p class="mt-0.5 text-sm text-slate-600">ป้ายสีที่ติดให้รายการ Checklist ได้ เพื่อบอกเรื่องที่ติ๊กอย่างเดียวบอกไม่ได้ · รายการหนึ่งติดได้หลายสถานะ</p>
                </div>
                @if ($statuses->isNotEmpty())
                    <button type="button" @click="open(null)" class="inline-flex h-10 items-center rounded-lg bg-slate-800 px-4 text-sm font-semibold text-white hover:bg-slate-700">+ เพิ่มสถานะ</button>
                @endif
            </div>

            @if ($statuses->isEmpty())
                {{-- first time: what it is, a look at the chips, two ways to start --}}
                <div class="mx-auto flex max-w-xl flex-col items-center gap-3 px-5 py-10 text-center">
                    <p class="text-base font-semibold text-slate-900">ยังไม่มีสีสถานะ</p>
                    <p class="text-sm leading-relaxed text-slate-600">ตั้งชื่อให้แต่ละสี เช่น "ของสั่งนอก" เป็นสีแดง แล้วทุกแผนกจะติดป้ายนี้ให้รายการ Checklist ได้ รายการหนึ่งติดได้หลายสถานะ และกรองดูเฉพาะสถานะได้</p>
                    <div class="flex flex-wrap justify-center gap-1.5" aria-label="ตัวอย่างป้าย">
                        @foreach ([['ของสั่งนอก', 'red'], ['รอของ', 'orange'], ['ลูกค้าแก้แบบ', 'violet']] as [$n, $c])
                            @php [, $dot, $bg, $fg] = \App\Models\ChecklistStatus::PALETTE[$c]; @endphp
                            <span class="inline-flex h-7 items-center gap-1.5 rounded-full px-2.5 text-[13px] font-semibold" style="background: {{ $bg }}; color: {{ $fg }}"><span class="h-2 w-2 rounded-full" style="background: {{ $dot }}"></span>{{ $n }}</span>
                        @endforeach
                    </div>
                    <div class="mt-2 flex flex-wrap justify-center gap-2">
                        <button type="button" @click="open(null)" class="inline-flex h-11 items-center rounded-lg bg-slate-800 px-5 text-sm font-semibold text-white hover:bg-slate-700">+ เพิ่มสถานะแรก</button>
                        <form method="POST" action="{{ route('settings.checklist-statuses.starter') }}">@csrf
                            <button class="inline-flex h-11 items-center rounded-lg border border-slate-300 bg-white px-5 text-sm font-semibold text-slate-700 hover:bg-slate-50">เริ่มจากชุดตัวอย่าง 4 สถานะ</button>
                        </form>
                    </div>
                    <p class="text-[13px] text-slate-500">ชุดตัวอย่าง: ของสั่งนอก · รอของ · ของเข้าแล้ว · ลูกค้าแก้แบบ แก้ชื่อและสีได้ภายหลัง</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead class="border-b border-slate-100 text-left text-[13px] text-slate-500">
                            <tr>
                                <th class="w-16 px-3 py-2 font-medium"><span class="sr-only">ลำดับ</span></th>
                                <th class="px-2 py-2 font-medium">ชื่อ / ความหมาย</th>
                                <th class="px-2 py-2 font-medium">กลุ่ม</th>
                                <th class="px-2 py-2 font-medium">แผนกที่ใช้ได้</th>
                                <th class="px-2 py-2 text-right font-medium">ใช้อยู่</th>
                                <th class="px-3 py-2"><span class="sr-only">จัดการ</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($statuses as $s)
                                @php [, $dot, $bg, $fg] = \App\Models\ChecklistStatus::PALETTE[$s->color] ?? \App\Models\ChecklistStatus::PALETTE['slate']; @endphp
                                <tr class="{{ session('status_new') === $s->id ? 'bg-blue-50' : '' }} {{ $s->is_active ? '' : 'text-slate-500' }}">
                                    <td class="px-3 py-2">
                                        <form method="POST" action="{{ route('settings.checklist-statuses.move', $s) }}" class="flex">@csrf @method('PATCH')
                                            <button name="dir" value="up" class="flex h-8 w-7 items-center justify-center rounded text-slate-500 hover:bg-slate-100 hover:text-slate-900 disabled:opacity-30" @disabled($loop->first) aria-label="เลื่อน {{ $s->name }} ขึ้น">↑</button>
                                            <button name="dir" value="down" class="flex h-8 w-7 items-center justify-center rounded text-slate-500 hover:bg-slate-100 hover:text-slate-900 disabled:opacity-30" @disabled($loop->last) aria-label="เลื่อน {{ $s->name }} ลง">↓</button>
                                        </form>
                                    </td>
                                    <td class="px-2 py-2.5">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="inline-flex h-7 items-center gap-1.5 rounded-full px-2.5 text-[13px] font-semibold {{ $s->is_active ? '' : 'opacity-60' }}" style="background: {{ $bg }}; color: {{ $fg }}"><span class="h-2 w-2 rounded-full" style="background: {{ $dot }}"></span>{{ $s->name }}</span>
                                            @if (session('status_new') === $s->id)<span class="rounded-full bg-blue-100 px-2 py-0.5 text-[12px] font-semibold text-blue-800">เพิ่งเพิ่ม</span>@endif
                                            @unless ($s->is_active)<span class="rounded-full bg-slate-100 px-2 py-0.5 text-[12px] font-semibold text-slate-600">ปิดใช้อยู่</span>@endunless
                                        </div>
                                        @if ($s->description)<p class="mt-1 text-[13px] text-slate-600">{{ $s->description }}</p>@endif
                                    </td>
                                    <td class="px-2 py-2.5 text-slate-700">{{ $s->group_name ? $s->group_name.' · เลือกได้อย่างเดียว' : '—' }}</td>
                                    <td class="px-2 py-2.5 text-slate-700">{{ $s->department_ids ? collect($s->department_ids)->map(fn ($id) => $deptNames[$id] ?? null)->filter()->implode(', ') : 'ทุกแผนก' }}</td>
                                    <td class="px-2 py-2.5 text-right tabular-nums text-slate-700">{{ number_format($s->checklists_count) }} รายการ</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right">
                                        <button type="button" @click="open({{ $s->id }})" class="inline-flex h-9 items-center rounded-md px-2.5 text-[13px] font-semibold text-blue-700 hover:bg-blue-50">แก้ไข</button>
                                        @if ($s->checklists_count || ! $s->is_active)
                                            <form method="POST" action="{{ route('settings.checklist-statuses.toggle', $s) }}" class="inline">@csrf @method('PATCH')
                                                <button class="inline-flex h-9 items-center rounded-md px-2.5 text-[13px] font-semibold {{ $s->is_active ? 'text-red-700 hover:bg-red-50' : 'text-blue-700 hover:bg-blue-50' }}">{{ $s->is_active ? 'ปิดใช้' : 'เปิดใช้' }}</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('settings.checklist-statuses.destroy', $s) }}" class="inline" onsubmit="return confirm('ลบสถานะ “{{ $s->name }}”? ยังไม่มีรายการใดใช้')">@csrf @method('DELETE')
                                                <button class="inline-flex h-9 items-center rounded-md px-2.5 text-[13px] font-semibold text-red-700 hover:bg-red-50">ลบ</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-slate-100 px-5 py-3 text-[13px] leading-relaxed text-slate-600">สถานะที่มีรายการใช้อยู่ลบไม่ได้ ให้ "ปิดใช้" แทน: รายการเดิมยังแสดงป้ายนั้น แต่เลือกใหม่ไม่ได้ · ลำดับในตารางคือลำดับในเมนูเลือกสถานะ</p>
            @endif
        </section>
        </div>

    {{-- add / edit: name, colour, meaning, departments, group - with a live look at the chip and the menu row --}}
    <x-modal name="checklist-status" maxWidth="2xl">
        <template x-if="f">
            <form method="POST" :action="f.url" class="grid gap-6 p-6 md:grid-cols-[minmax(0,1fr)_15rem]">
                @csrf
                <template x-if="f.id"><input type="hidden" name="_method" value="PUT"></template>
                <input type="hidden" name="id" :value="f.id">
                <div class="space-y-4">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900" x-text="f.id ? 'แก้สถานะ' : 'เพิ่มสถานะใหม่'"></h3>
                        <p class="text-[13px] text-slate-500">ช่องที่มี * ต้องกรอก</p>
                    </div>
                    @if ($errors->any())
                        <div class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $errors->first() }}</div>
                    @endif
                    <label class="block text-sm font-semibold text-slate-800">ชื่อสถานะ *
                        <input x-ref="name" type="text" name="name" x-model="f.name" maxlength="30" required class="mt-1 block h-11 w-full rounded-lg border-slate-300 text-base font-normal">
                        <span class="mt-1 block text-[13px] font-normal text-slate-500">สั้น อ่านได้ทันที ไม่เกิน 30 ตัวอักษร · <span x-text="f.name.length + '/30'"></span></span>
                    </label>
                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-800">สี *</legend>
                        <input type="hidden" name="color" :value="f.color">
                        <div class="mt-2 flex flex-wrap gap-2">
                            <template x-for="p in palette" :key="p.key">
                                <button type="button" @click="f.color = p.key" :aria-pressed="f.color === p.key" :aria-label="'สี' + p.name" :title="p.name"
                                        class="h-10 w-10 rounded-lg" :style="'background:' + p.dot + (f.color === p.key ? ';box-shadow:0 0 0 3px #fff,0 0 0 5px ' + p.dot : '')"></button>
                            </template>
                        </div>
                        <p class="mt-1.5 text-[13px] text-slate-500">ระบบปรับสีพื้นและตัวอักษรของป้ายให้อ่านชัดเอง · สถานะต่างกันควรใช้สีต่างกัน</p>
                    </fieldset>
                    <label class="block text-sm font-semibold text-slate-800">ความหมาย
                        <input type="text" name="description" x-model="f.description" maxlength="120" class="mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal" placeholder="เช่น อุปกรณ์ที่ต้องสั่งซื้อจากผู้ขายภายนอก">
                        <span class="mt-1 block text-[13px] font-normal text-slate-500">ขึ้นในเมนูเลือกสถานะ ใต้ชื่อ ช่วยให้ทุกแผนกใช้ตรงกัน</span>
                    </label>
                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-800">ใช้ได้กับแผนก</legend>
                        <div class="mt-1.5 space-y-1.5 text-sm text-slate-700">
                            <label class="flex min-h-[2.25rem] items-center gap-2"><input type="radio" name="scope" value="all" x-model="f.scope" class="text-slate-800"> ทุกแผนก</label>
                            <label class="flex min-h-[2.25rem] items-center gap-2"><input type="radio" name="scope" value="some" x-model="f.scope" class="text-slate-800"> เฉพาะแผนกที่เลือก</label>
                            <div x-show="f.scope === 'some'" class="grid grid-cols-2 gap-x-3 gap-y-1 pl-6 sm:grid-cols-3">
                                @foreach ($departments as $d)
                                    <label class="flex min-h-[2.25rem] items-center gap-2"><input type="checkbox" name="department_ids[]" value="{{ $d->id }}" x-model.number="f.department_ids" class="rounded text-slate-800"> {{ $d->name }}</label>
                                @endforeach
                            </div>
                            <p class="text-[13px] text-slate-500">ดูจากแผนกของ Sub Task ที่รายการนั้นอยู่</p>
                        </div>
                    </fieldset>
                    <label class="block text-sm font-semibold text-slate-800">กลุ่ม (ไม่บังคับ)
                        <input type="text" name="group_name" x-model="f.group_name" maxlength="40" list="status-groups" class="mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal" placeholder="ไม่อยู่ในกลุ่ม · เช่น การรับของ">
                        <datalist id="status-groups">@foreach ($groups as $g)<option value="{{ $g }}">@endforeach</datalist>
                        <span class="mt-1 block text-[13px] font-normal text-slate-500">สถานะในกลุ่มเดียวกันติดพร้อมกันไม่ได้ เช่น "รอของ" กับ "ของเข้าแล้ว" · ติดอันใหม่ อันเดิมในกลุ่มจะถูกแทน</span>
                    </label>
                </div>

                <aside aria-label="ตัวอย่าง" class="flex flex-col gap-3 rounded-xl bg-slate-50 p-4">
                    <p class="text-[13px] font-semibold text-slate-600">หน้าตาเมื่อใช้งาน</p>
                    <div class="flex flex-col gap-1.5 rounded-lg border border-slate-200 bg-white p-3">
                        <span class="text-sm text-slate-900">Main Breaker 400A</span>
                        <span class="inline-flex h-7 w-fit items-center gap-1.5 rounded-full px-2.5 text-[13px] font-semibold" :style="'background:' + sw(f.color).bg + ';color:' + sw(f.color).fg">
                            <span class="h-2 w-2 rounded-full" :style="'background:' + sw(f.color).dot"></span><span x-text="f.name || 'ชื่อสถานะ'"></span></span>
                    </div>
                    <div class="flex items-center gap-2.5 rounded-lg border border-slate-200 bg-white p-2.5">
                        <span class="h-3 w-3 shrink-0 rounded-full" :style="'background:' + sw(f.color).dot"></span>
                        <span class="flex min-w-0 flex-col"><span class="text-sm font-semibold text-slate-900" x-text="f.name || 'ชื่อสถานะ'"></span>
                            <span class="text-[13px] text-slate-600" x-text="f.description || 'ความหมาย'"></span></span>
                    </div>
                    <p class="text-[13px] text-slate-500">บนป้าย: ชื่อ · ในเมนูเลือก: ชื่อ + ความหมาย</p>
                    <div class="mt-auto flex flex-col gap-2">
                        <button type="submit" class="h-11 rounded-lg bg-slate-800 text-sm font-semibold text-white hover:bg-slate-700">บันทึก</button>
                        <button type="button" @click="$dispatch('close-modal', 'checklist-status')" class="h-11 rounded-lg border border-slate-300 bg-white text-sm font-semibold text-slate-700 hover:bg-slate-50">ยกเลิก</button>
                    </div>
                </aside>
            </form>
        </template>
    </x-modal>
    </div>
</x-app-layout>
