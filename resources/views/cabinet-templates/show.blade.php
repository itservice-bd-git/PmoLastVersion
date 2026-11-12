<x-app-layout>
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-2">
            <x-back-link :href="route('cabinet-templates.index')" label="เทมเพลตทั้งหมด" />
            <h2 class="text-lg font-semibold text-slate-900">{{ $template->name }}</h2>
            @if ($template->is_default)
                <span class="text-[11.5px] uppercase tracking-wide bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded font-semibold">Default</span>
            @endif
        </div>
    </x-slot>

    <div class="max-w-none mx-auto space-y-6">
        <x-settings-nav />
        <x-card>
            @php
                $subs = $template->taskTemplates->flatMap->subtaskTemplates;
                $usedBy = $template->cabinets()->withTrashed()->count();
                // "เทียบกับ…": every Sub Task of the template, for each Sub Task's picker (_subtask)
                $pairOptions = $template->taskTemplates->flatMap(fn ($t) => $t->subtaskTemplates->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'task' => $t->name, 'dept' => (string) $s->department?->name]))->values();
                $hasPairs = $subs->contains(fn ($s) => $s->pairSources->isNotEmpty());
            @endphp
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
                        <span>รหัส <span class="font-medium text-slate-700">{{ $template->code }}</span></span>
                        @unless ($template->is_active)<span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11.5px] font-semibold text-slate-500" title="ไม่แสดงให้เลือกตอนสร้างตู้">ปิดใช้งาน</span>@endunless
                    </div>
                    @if ($template->description)
                        <p class="text-sm text-slate-600 mt-1">{{ $template->description }}</p>
                    @endif
                    {{-- the size of it, and how much it is used (as the page was loaded) --}}
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        @foreach ([['Task', $template->taskTemplates->count()], ['Sub Task', $subs->count()], ['Checklist', $subs->sum(fn ($s) => $s->checklistTemplates->count())], ['ใช้กับตู้', $usedBy]] as [$label, $n])
                            <span class="rounded-lg bg-slate-50 px-2.5 py-1 text-slate-500 ring-1 ring-slate-200"><b class="tabular-nums text-slate-900">{{ number_format($n) }}</b> {{ $label }}</span>
                        @endforeach
                    </div>
                </div>
                @edittemplates
                <div class="flex shrink-0 flex-wrap justify-end gap-2">
                    <button type="button" x-data @click="$dispatch('open-modal', 'template-sync')" class="px-3 py-2 rounded-lg bg-blue-50 text-sm font-medium text-blue-700 hover:bg-blue-100"
                            title="เพิ่มรายการที่ตู้เดิม (ที่สร้างจากเทมเพลตนี้) ยังไม่มี">ส่งรายการใหม่ไปตู้เดิม</button>
                    @if ($pairCabinets)
                        <form method="POST" action="{{ route('cabinet-templates.pairs.apply', $template) }}"
                              onsubmit="return confirm(@js('ใช้คู่เทียบ Checklist ของเทมเพลตนี้กับตู้เดิม '.$pairCabinets.' ตู้ (โครงการที่ยังไม่ปิด)?'."\n\nคู่เทียบเดิมของตู้เหล่านั้นจะถูกแทนด้วยของเทมเพลต"))">@csrf
                            <button class="px-3 py-2 rounded-lg bg-blue-50 text-sm font-medium text-blue-700 hover:bg-blue-100" title="Sub Task ที่ตรงกัน (จากเทมเพลต หรือชื่อเดียวกัน) จะเทียบกันเหมือนในเทมเพลต">ใช้คู่เทียบกับตู้เดิม ({{ $pairCabinets }} ตู้)</button></form>
                    @endif
                    <a href="{{ route('cabinet-templates.export', $template) }}" class="px-3 py-2 rounded-lg bg-slate-100 text-sm font-medium text-slate-700 hover:bg-slate-200" title="Task | Sub Task | แผนก | Checklist | หมายเหตุ">Export Excel</a>
                    <form method="POST" action="{{ route('cabinet-templates.duplicate', $template) }}" onsubmit="return confirm('ทำสำเนาเทมเพลตนี้ทั้งหมด?')">@csrf
                        <button class="px-3 py-2 rounded-lg bg-slate-100 text-sm font-medium text-slate-700 hover:bg-slate-200">ทำสำเนา</button></form>
                    <a href="{{ route('cabinet-templates.edit', $template) }}" class="px-3 py-2 rounded-lg bg-slate-100 text-sm font-medium text-slate-700 hover:bg-slate-200" title="ชื่อ รหัส คำอธิบาย ค่าเริ่มต้น เปิด/ปิดใช้งาน">แก้ไขข้อมูล</a>
                    <form method="POST" action="{{ route('cabinet-templates.destroy', $template) }}"
                          onsubmit="return confirm(@js('ลบเทมเพลต “'.$template->name.'” ทั้งหมด (Task / Sub Task / Checklist)?'.($usedBy ? "\n\nตู้ {$usedBy} ตู้ที่สร้างจากเทมเพลตนี้ยังอยู่ครบ (เป็นสำเนาแยกกัน) แต่จะส่งรายการใหม่จากเทมเพลตไปให้ไม่ได้อีก" : '').($template->is_default ? "\n\nเทมเพลตนี้เป็นค่าเริ่มต้น (Default) — ตู้ใหม่จะไม่มีเทมเพลตเริ่มต้นจนกว่าจะตั้งอันอื่น" : '')."\n\nกู้คืนไม่ได้"))">
                        @csrf @method('DELETE')
                        <button class="px-3 py-2 rounded-lg bg-red-50 text-sm font-medium text-red-700 hover:bg-red-100">ลบเทมเพลต</button>
                    </form>
                </div>
                @endedittemplates
            </div>
        </x-card>

        <x-card title="โครงสร้างงาน" subtitle="Task → Sub Task (แผนก) → Checklist — ลาก ⋮⋮ เพื่อจัดลำดับ · กดชื่อ Task เพื่อยุบ / ขยาย">
            <div class="-mt-2 mb-3 flex justify-end gap-1 text-xs">
                <button type="button" x-data @click="$dispatch('tpl-fold', true)" class="rounded-md px-2 py-1 text-slate-500 hover:bg-slate-100">ยุบทั้งหมด</button>
                <button type="button" x-data @click="$dispatch('tpl-fold', false)" class="rounded-md px-2 py-1 text-slate-500 hover:bg-slate-100">ขยายทั้งหมด</button>
            </div>
            <div class="space-y-3" data-task-list data-reorder-url="{{ route('task-templates.reorder') }}">
                @foreach ($template->taskTemplates as $taskTemplate)
                    @include('cabinet-templates.partials._task', ['taskTemplate' => $taskTemplate, 'departments' => $departments])
                @endforeach
            </div>

            @edittemplates
            <div class="mt-4 pt-4 border-t border-slate-100" x-data="{ addTask: false }">
                <button type="button" @click="addTask = !addTask" class="rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-sm font-medium text-blue-600 hover:bg-blue-50">+ เพิ่ม Task</button>
                <div x-show="addTask" x-cloak class="mt-3">
                    <form class="task-template-add-form" method="POST" action="{{ route('task-templates.store', $template) }}">
                        @csrf
                        <div class="flex items-end gap-2">
                            <div class="flex-1">
                                <x-input-label value="ชื่อ Task" class="text-xs" />
                                <x-text-input name="name" class="mt-1 block w-full text-sm" placeholder="เช่น QC / แพ็กสินค้า" required />
                            </div>
                            <button class="px-3 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">เพิ่ม</button>
                        </div>
                    </form>
                </div>
            </div>
            @endedittemplates
        </x-card>
    </div>

    @edittemplates
    {{-- "ส่งรายการใหม่ไปตู้เดิม": preview per cabinet, then add (App\Services\TemplateTools) --}}
    <x-modal name="template-sync" maxWidth="2xl">
        <div class="p-6" x-data="{
                url: @js(route('cabinet-templates.sync', $template)), applyUrl: @js(route('cabinet-templates.sync.apply', $template)),
                rows: null, picked: [], busy: false,
                async load() { this.rows = null; try { this.rows = (await (await fetch(this.url, { headers: { Accept: 'application/json' } })).json()).cabinets; this.picked = this.rows.map((r) => r.id); } catch (e) { window.showToast('โหลดไม่สำเร็จ', 'error'); } },
                async apply() {
                    if (this.busy || !this.picked.length) return;
                    this.busy = true;
                    try {
                        const res = await fetch(this.applyUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ cabinet_ids: this.picked }) });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok) throw new Error(data.message || 'ไม่สำเร็จ');
                        window.showToast(data.message);
                        this.$dispatch('close-modal', 'template-sync');
                    } catch (e) { window.showToast(e.message, 'error'); } finally { this.busy = false; }
                },
             }" @open-modal.window="$event.detail === 'template-sync' && load()">
            <h3 class="text-base font-semibold text-slate-900">ส่งรายการใหม่ไปตู้เดิม</h3>
            <p class="mb-3 text-xs text-slate-500">เพิ่ม Checklist ของเทมเพลตนี้ที่ตู้ที่สร้างไปแล้วยังไม่มี — <b>เพิ่มเท่านั้น</b> ไม่แก้ / ไม่ลบรายการเดิม · ข้ามตู้ที่เสร็จแล้ว งานที่เสร็จแล้ว และโครงการที่ปิดแล้ว · รายการที่ตู้ลบทิ้งเองจะไม่ถูกเพิ่มกลับ</p>
            <p x-show="rows === null" class="py-6 text-center text-sm text-slate-400">กำลังตรวจ…</p>
            <p x-show="rows && !rows.length" class="py-6 text-center text-sm text-emerald-700">✓ ทุกตู้มีรายการครบตามเทมเพลตแล้ว</p>
            <div x-show="rows && rows.length" class="max-h-80 overflow-y-auto rounded-lg border border-slate-200">
                <table class="w-full text-xs">
                    <thead class="sticky top-0 bg-slate-50 text-left text-slate-500"><tr><th class="w-8 p-2"></th><th class="p-2">ตู้</th><th class="p-2">โครงการ</th><th class="p-2 text-right">จะเพิ่ม</th></tr></thead>
                    <tbody>
                        <template x-for="r in (rows || [])" :key="r.id">
                            <tr class="border-t border-slate-100 align-top">
                                <td class="p-2"><input type="checkbox" :value="r.id" x-model.number="picked" class="rounded border-slate-300"></td>
                                <td class="p-2"><span class="font-medium text-slate-800" x-text="r.mo"></span> <span class="text-slate-500" x-text="r.name"></span>
                                    <div class="text-[12px] text-slate-400" x-text="r.sample.join(', ') + (r.missing > r.sample.length ? ' …' : '')"></div></td>
                                <td class="p-2 text-slate-600" x-text="r.project"></td>
                                <td class="p-2 text-right font-semibold tabular-nums" x-text="r.missing + ' รายการ'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" @click="$dispatch('close-modal', 'template-sync')" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100">ปิด</button>
                <button type="button" x-show="rows && rows.length" @click="apply()" :disabled="busy || !picked.length" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                        x-text="'เพิ่มให้ ' + picked.length + ' ตู้'"></button>
            </div>
        </div>
    </x-modal>
    @endedittemplates

</x-app-layout>
