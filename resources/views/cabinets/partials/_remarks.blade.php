{{--
    "หมายเหตุ" tab of the cabinet page: every remark of the cabinet's work in one table (Remarks::forCabinet - only the
    work this user sees). Filter by department / Task here; "ไปที่งาน" goes back to the work list and opens that Sub Task / item.
    Uses the page's tab / remarkTask / remarkDept (cabinets/show).
--}}
<div x-show="tab === 'remarks'" x-cloak
     x-data="{
        rows: @js($remarks->values()),
        depts() { const seen = new Map(); this.rows.forEach((r) => { if (r.deptId && !seen.has(r.deptId)) seen.set(r.deptId, { id: r.deptId, name: r.dept, color: r.color }); }); return [...seen.values()]; },
        shown() { return this.rows.filter((r) => (!this.remarkDept || r.deptId === this.remarkDept) && (!this.remarkTask || r.taskId === this.remarkTask)); },
        taskName() { const r = this.rows.find((x) => x.taskId === this.remarkTask); return r ? r.task : ''; },
        goRemark(r) {
            this.tab = 'tasks';
            const find = () => (r.itemId && document.getElementById('checklist-' + r.itemId)) || null;
            this.$nextTick(() => {
                const sub = r.subtaskId ? document.getElementById('subtask-' + r.subtaskId) : null;
                const card = r.taskId ? document.getElementById('task-card-' + r.taskId) : null;
                [sub, card && card.querySelector('details')].forEach((d) => { if (d) d.open = true; });
                let tries = 0;
                const land = () => {
                    const el = find() || (r.itemId && tries < 20 ? null : (sub || card));
                    if (!el) { tries++; setTimeout(land, 100); return; }   // the item list may still be loading
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    el.classList.add('ring-2', 'ring-amber-400', 'rounded-lg');
                    setTimeout(() => el.classList.remove('ring-2', 'ring-amber-400', 'rounded-lg'), 2200);
                };
                land();
            });
        },
     }">
    <x-card class="!p-0">
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-3">
            <button type="button" @click="remarkDept = null" class="inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-sm font-medium"
                    :class="!remarkDept ? 'border-slate-800 bg-slate-800 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-slate-400'">ทุกแผนก</button>
            <template x-for="d in depts()" :key="d.id">
                <button type="button" @click="remarkDept = d.id" class="inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-sm font-medium"
                        :class="remarkDept === d.id ? 'border-slate-800 bg-slate-800 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-slate-400'">
                    <span class="h-2 w-2 rounded-full" :style="'background-color:' + d.color"></span><span x-text="d.name"></span>
                </button>
            </template>
            <button type="button" x-show="remarkTask" @click="remarkTask = null" class="inline-flex h-8 items-center gap-1 rounded-full border border-amber-300 bg-amber-50 px-3 text-sm font-medium text-amber-800 hover:bg-amber-100"
                    :aria-label="'เลิกกรองงาน ' + taskName()"><span x-text="taskName()"></span> ✕</button>
            <span class="ml-auto text-xs text-slate-500" x-text="shown().length + ' รายการ'"></span>
        </div>

        @if ($remarks->isEmpty())
            <p class="px-5 py-12 text-center text-sm text-slate-500">ยังไม่มีหมายเหตุในตู้นี้</p>
        @else
            <p x-show="shown().length === 0" class="px-5 py-12 text-center text-sm text-slate-500">ไม่มีหมายเหตุตามที่เลือก</p>
            <div class="overflow-x-auto">
                <table x-show="shown().length" class="w-full min-w-[46rem] text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500">
                        <tr>
                            <th class="w-24 px-5 py-2.5">ระดับ</th>
                            <th class="w-64 px-3 py-2.5">อยู่ที่</th>
                            <th class="px-3 py-2.5">หมายเหตุ</th>
                            <th class="w-44 px-3 py-2.5">เขียนโดย</th>
                            <th class="w-24 px-5 py-2.5"><span class="sr-only">ไปที่งาน</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="r in shown()" :key="r.key || 'cabinet'">
                            <tr class="align-top">
                                <td class="px-5 py-2.5"><span class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700" x-text="r.level"></span></td>
                                <td class="px-3 py-2.5 text-xs text-slate-600">
                                    <span class="flex items-start gap-1.5"><span class="mt-1 h-2 w-2 shrink-0 rounded-full" :style="'background-color:' + r.color" :title="r.dept"></span><span class="break-words" x-text="r.path"></span></span>
                                </td>
                                <td class="whitespace-pre-line break-words px-3 py-2.5 text-slate-800" x-text="r.text"></td>
                                <td class="px-3 py-2.5 text-xs text-slate-500" x-text="r.who || '-'"></td>
                                <td class="px-5 py-2.5 text-right">
                                    <button type="button" x-show="r.taskId" @click="goRemark(r)" class="whitespace-nowrap text-xs font-semibold text-blue-600 hover:underline">ไปที่งาน ›</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
</div>
