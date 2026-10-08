{{--
    Checklist popover (the original page's): opens under the checklist pill of a cabinet - in the project modal or in the
    left panel. Header + progress, the cabinet's items (tick = one operation), grouped by department, "+" to add an item
    and ✕ to delete one. Names are read-only: PMO has no "rename" for checklist items. Every change is checked by PMO
    (only the department that accepted the work, or PMO roles, may tick / add / delete) and bounces back if refused.
--}}
<template x-if="cl && clCab">
    <div class="fixed z-[60] flex w-[260px] flex-col rounded-[10px] border border-slate-200 bg-white p-3 shadow-2xl max-h-[min(460px,calc(100vh-24px))]"
         :style="'top:' + cl.top + 'px;left:' + cl.left + 'px'" @click.outside="closeChecklist()" role="dialog" aria-label="Checklist">
        <div class="mb-2 flex items-center justify-between">
            <span class="truncate text-[13px] font-bold text-slate-900" x-text="clTitle()"></span>
            <span class="ml-2 shrink-0 text-xs font-semibold text-slate-500 tabular-nums" x-text="clProgress().done + '/' + clProgress().total + ' · ' + clProgress().pct + '%'"></span>
        </div>
        <p class="-mt-1 mb-2 truncate text-[11px] text-slate-400" x-text="clCab.mo + ' · ' + clCab.text"></p>
        <div class="mb-2.5 h-1.5 overflow-hidden rounded-[3px] bg-slate-100"><div class="h-full rounded-[3px] transition-all" :class="clProgress().pct === 100 ? 'bg-emerald-500' : 'bg-blue-600'" :style="'width:' + clProgress().pct + '%'"></div></div>

        <div class="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto">
            <template x-for="g in clGroups()" :key="g.deptId">
                <div>
                    <div x-show="!dept" class="flex items-center gap-1.5 pt-2 pb-0.5 text-[11px] font-bold text-slate-500">
                        <span class="h-2 w-2 shrink-0 rounded-full" :style="'background-color:' + deptOf(g.deptId).color"></span><span x-text="deptOf(g.deptId).name"></span>
                        <button type="button" @click="clAdd(g.deptId)" class="ml-auto rounded px-1 text-sm font-bold text-blue-600 hover:bg-blue-50" title="เพิ่มรายการ" aria-label="เพิ่มรายการ">+</button>
                    </div>
                    <template x-for="i in g.items" :key="i.id">
                        <label class="group flex cursor-pointer items-center gap-2 rounded-md px-1 py-1 hover:bg-slate-50">
                            <input type="checkbox" :checked="i.done" @change="tick(clTask, clCab, i)" class="h-4 w-4 shrink-0 cursor-pointer rounded border-slate-300 text-emerald-600">
                            <span class="min-w-0 flex-1 truncate text-[13px]" :class="i.done ? 'line-through text-slate-400' : 'text-slate-800'" x-text="i.text" :title="i.text"></span>
                            <button type="button" @click.prevent="clDelete(i)" class="shrink-0 rounded px-1.5 py-0.5 text-[13px] leading-none text-red-600 opacity-0 hover:bg-red-50 group-hover:opacity-100 focus:opacity-100" title="ลบ" aria-label="ลบรายการ">✕</button>
                        </label>
                    </template>
                    <p x-show="g.items.length === 0 && cl.adding !== g.deptId" class="px-1 py-1 text-xs text-slate-400">ยังไม่มีรายการ</p>
                    <input x-show="cl.adding === g.deptId" id="cl-draft" x-model="clDraft" type="text" maxlength="255" placeholder="ชื่อรายการ... (Enter เพิ่ม, Esc ยกเลิก)"
                           @keydown.enter.prevent="clCommit()" @keydown.escape.stop.prevent="cl = { ...cl, adding: null }; clDraft = ''" @blur="clCommit()"
                           class="mt-1 w-full rounded-md border-slate-300 px-2 py-1 text-[13px]">
                </div>
            </template>
            <p x-show="clGroups().length === 0" class="px-1 py-2.5 text-xs leading-relaxed text-slate-400">ยังไม่มีรายการ — checklist มาจากเทมเพลตของ PMO</p>
        </div>
        {{-- when one department is in view the "+" lives at the bottom, as on the original page --}}
        <button type="button" x-show="dept && cl.adding !== dept" @click="clAdd(dept)" class="mt-1.5 self-start px-0.5 py-1 text-[13px] text-blue-600 hover:underline">+ เพิ่มรายการ</button>
    </div>
</template>
