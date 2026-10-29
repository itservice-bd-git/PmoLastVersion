{{--
    Checklist popover (the original page's): opens under the checklist pill of a cabinet - in the project modal or in the
    left panel. Header + progress, then Task › Sub Task (department, workflow state, progress, "+") › items (tick = one operation)
    ✎ rename, 📝 remark, ↑↓ order and ✕ delete an item. Every change is checked by PMO
    (only the department that accepted the work, or PMO roles, may tick / add / delete) and bounces back if refused.
--}}
<template x-if="cl && clCab">
    <div class="fixed z-[60] flex flex-col rounded-[10px] border border-slate-200 bg-white p-3 shadow-2xl"
         :style="'top:' + cl.top + 'px;left:' + cl.left + 'px;width:' + cl.w + 'px;max-height:' + cl.h + 'px'" @click.outside="if (!$event.target.closest('.flatpickr-calendar')) closeChecklist()" role="dialog" aria-label="Checklist">
        <div class="mb-2 flex items-center justify-between">
            <span class="truncate text-[13px] font-bold text-slate-900" x-text="clTitle()"></span>
            <span class="ml-2 flex shrink-0 items-center gap-2">
                <span class="text-xs font-semibold text-slate-500 tabular-nums" x-text="clProgress().done + '/' + clProgress().total + ' · ' + clProgress().pct + '%'"></span>
                <button type="button" @click="clToggleBig()" class="rounded-md border border-slate-200 px-1.5 py-0.5 text-xs text-slate-500 hover:bg-slate-100" :title="cl.big ? 'ย่อ' : 'ขยายเต็มจอ'" :aria-label="cl.big ? 'ย่อหน้าต่าง' : 'ขยายหน้าต่าง'" x-text="cl.big ? '⤡' : '⤢'"></button>
                <button type="button" @click="closeChecklist()" class="px-1 text-lg leading-none text-slate-400 hover:text-slate-700" aria-label="ปิด">&times;</button>
            </span>
        </div>
        <p class="-mt-1 mb-2 flex items-center gap-2 text-[12px] text-slate-400"><span class="min-w-0 flex-1 truncate" x-text="clCab.mo + ' · ' + clCab.text"></span>
            <a :href="cabinetPage(clCab)" class="shrink-0 font-medium text-blue-600 hover:underline" title="หน้าตู้: แก้แบบตาราง เลือกหลายรายการ ย้าย / คัดลอก วางจาก Excel">แก้แบบตาราง ↗</a></p>
        <div class="mb-2.5 h-1.5 overflow-hidden rounded-[3px] bg-slate-100"><div class="h-full rounded-[3px] transition-all" :class="clProgress().pct === 100 ? 'bg-emerald-500' : 'bg-blue-600'" :style="'width:' + clProgress().pct + '%'"></div></div>
        {{-- Settings > สีสถานะ Checklist: items show colour dots only, so this says what each colour means; a colour filters to it --}}
        <div x-show="ckTally(clCab).length" class="mb-2 flex flex-wrap items-center gap-1 rounded-md bg-slate-50 px-2 py-1.5 text-[13px]">
            <span class="mr-1 font-semibold text-slate-900">สีนี้คืออะไร</span>
            <template x-for="s in ckTally(clCab)" :key="'cf' + s.id"><button type="button" @click="ckToggleFilter(s.id)" :aria-pressed="ckFilter.includes(s.id)" :class="remarkChip(ckFilter.includes(s.id))"><span class="h-2 w-2 rounded-full" :style="'background:' + s.dot"></span><span x-text="s.name + ' ' + s.n"></span></button></template>
            <button type="button" x-show="ckFilter.length" @click="ckFilter = []" class="h-7 px-1.5 font-semibold text-blue-700 hover:underline">ล้างตัวกรอง</button>
        </div>

        {{-- a Sub Task that compares with another (template pairs): each item with the other list's item of the same name beside it --}}
        <div class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto">
          <div class="flex min-w-0 flex-col gap-1">
            {{-- Settings > การส่งต่องาน: the date this department gives its items for the next one, for the whole cabinet at once --}}
            <template x-for="d in clDateDepts()" :key="'cd' + d">
                <div class="flex items-center gap-1.5 rounded-md bg-amber-50 px-1.5 py-1 text-[12px] text-amber-900">
                    <span class="min-w-0 flex-1 truncate" x-text="'ใส่' + deptOf(d).dateLabel + 'ทั้งตู้ · ' + deptOf(d).name"></span>
                    <input type="text" placeholder="dd/mm/yyyy" x-init="dateField($el, () => '', (v) => setCabDate(d, v), true, 'h-6 w-[6.25rem] !py-0 !pr-2 !text-[12px]')">
                </div>
            </template>
            <template x-for="(g, gi) in clGroups()" :key="gi + g.task">
                <div>
                    {{-- Task --}}
                    <p class="truncate pt-1.5 text-[12px] font-bold uppercase tracking-wide text-slate-400" x-text="g.task" :title="g.task"></p>
                    <template x-for="w in g.subs" :key="w.id">
                        <div class="mt-1 rounded-md border border-slate-100">
                            {{-- Sub Task: its department, where it is in the workflow, its progress, "+" --}}
                            <div class="flex items-center gap-1.5 rounded-t-md bg-slate-50 px-1.5 py-1 text-[13px]" :data-cl-key="w.id">
                                {{-- tick / untick every item of this Sub Task at once (asks first) --}}
                                <input type="checkbox" x-show="w.items.length && !readOnly" :checked="w.items.length && w.done === w.items.length" x-effect="$el.indeterminate = w.done > 0 && w.done < w.items.length"
                                       @click.prevent="clTickAll(w)" class="h-4 w-4 shrink-0 cursor-pointer rounded border-slate-300 text-emerald-600"
                                       :title="w.done === w.items.length ? 'เอาเครื่องหมายออกทั้งหมด' : 'ติ๊กทั้งหมดในงานนี้'" :aria-label="'ติ๊กทั้งหมด: ' + w.name">
                                <span class="h-2 w-2 shrink-0 rounded-full" :style="'background-color:' + deptOf(w.deptId).color" :title="deptOf(w.deptId).name"></span>
                                <span class="min-w-0 flex-1 break-words font-semibold text-slate-700" x-text="w.name" :title="w.name + ' · ' + deptOf(w.deptId).name"></span>
                                <span class="shrink-0 text-[12px] text-slate-400" x-text="stateWord(w.state)"></span>
                                <span class="shrink-0 text-[12px] tabular-nums text-slate-500" x-show="w.items.length" x-text="w.done + '/' + w.items.length"></span>
                                <button type="button" x-show="!readOnly" @click="clAdd(w.id)" class="shrink-0 rounded px-1 text-sm font-bold leading-none text-blue-600 hover:bg-blue-50" title="เพิ่มรายการในงานนี้" aria-label="เพิ่มรายการ">+</button>
                            </div>
                            <p x-show="w.remark" class="whitespace-pre-line bg-amber-50 px-2 py-1 text-[12px] text-amber-900" x-text="'📝 ' + w.remark"></p>
                            {{-- compares with (template pairs): which Sub Task, its department, where its work is, a link to it --}}
                            <template x-for="l in clLinkedOf(w.id)" :key="'lh' + l.id">
                                <a :href="l.href" target="_blank" class="flex flex-wrap items-center gap-x-1.5 border-b border-dashed border-slate-200 bg-slate-50 px-1.5 py-1 text-[12px] hover:bg-blue-50">
                                    <span class="font-bold text-slate-500">⇄ เทียบกับ</span>
                                    <span class="h-2 w-2 shrink-0 rounded-full" :style="'background-color:' + deptOf(l.deptId).color"></span>
                                    <span class="font-semibold text-slate-800" x-text="l.deptName || deptOf(l.deptId).name"></span>
                                    <span class="text-slate-600" x-text="'· ' + l.title"></span>
                                    <span class="rounded-full px-1.5" :class="l.late ? 'bg-red-100 font-semibold text-red-700' : 'bg-white text-slate-600 ring-1 ring-slate-200'" x-text="l.state + (l.due ? ' · ครบ ' + fmtBE(l.due) : '')"></span>
                                    <span class="ml-auto tabular-nums text-slate-500" x-text="l.done + '/' + l.total + ' · ไปดู ↗'"></span>
                                </a>
                            </template>
                            <template x-for="i in w.items" :key="i.id + (i.expected || '')">
                              <div x-show="ckShown(i)" :class="clLinkedOf(w.id).length && clSide() ? 'grid grid-cols-2' : ''">
                              <div>
                                <label class="group relative flex cursor-pointer items-start gap-2 px-1.5 py-1 hover:bg-slate-50" :data-cl-key="i.id">
                                    <input type="checkbox" :checked="i.done" @change="tick(clTask, clCab, i)" :disabled="readOnly" class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded border-slate-300 text-emerald-600 disabled:cursor-default disabled:opacity-60">
                                    <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 gap-y-1">
                                    <span class="min-w-0 break-words text-[13px] leading-snug" :class="i.done ? 'line-through text-slate-400' : 'text-slate-800'"
                                          :title="i.text + (i.remark ? ' — ' + i.remark : '') + (i.from ? ' · รายการจากแผนกที่เทียบ (แก้ชื่อ / ลบ ที่ต้นทาง)' : '')"><span x-show="i.from" class="mr-0.5 text-[12px] text-blue-500">🔗</span><span x-text="i.text"></span></span>
                                    {{-- its statuses as colour dots only; a tap opens the panel under the item --}}
                                    <button type="button" x-show="(i.tags || []).length" @click.prevent="ckOpen(i)" :aria-expanded="ckPick === i.id" :aria-label="'สถานะ: ' + ckTags(i).map((s) => s.name).join(', ')" :title="ckTags(i).map((s) => s.name).join(' · ')"
                                            class="inline-flex h-6 items-center gap-1 rounded-full border bg-white px-1.5" :class="ckPick === i.id ? 'border-slate-800' : 'border-slate-200 hover:border-slate-400'">
                                        <template x-for="s in ckTags(i)" :key="s.id"><span class="h-2.5 w-2.5 rounded-full" :style="'background:' + s.dot + (s.active ? '' : ';opacity:.5')"></span></template>
                                    </button>
                                    </span>
                                    <span x-show="i.remark" class="shrink-0 text-[12px]" :title="i.remark">📝</span>
                                    <span x-show="deptOf(i.deptId).dateLabel && !canEditStage(i.deptId) && i.expected" class="shrink-0 rounded bg-amber-50 px-1 text-[12px] text-amber-800" :title="deptOf(i.deptId).dateLabel" x-text="'📦 ' + fmtBE(i.expected)"></span>
                                    {{-- statuses: always at the item's right, as the dots after its name (the same panel) --}}
                                    <button type="button" x-show="ckStatuses.length && !readOnly" @click.prevent="ckOpen(i)" :aria-expanded="ckPick === i.id" :aria-label="'ติดสถานะให้ ' + i.text" title="ติดสถานะ"
                                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border" :class="ckPick === i.id ? 'border-slate-800 bg-slate-800 text-white' : 'border-slate-200 bg-white text-slate-500 hover:border-slate-400 hover:text-slate-800'"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9-9-9z"/><circle cx="8" cy="8" r="1.5"/></svg></button>
                                    <span x-show="!readOnly" :class="ckStatuses.length ? '[@media(hover:hover)]:right-9' : '[@media(hover:hover)]:right-0.5'" class="flex shrink-0 [@media(hover:hover)]:absolute [@media(hover:hover)]:top-0.5 [@media(hover:hover)]:rounded [@media(hover:hover)]:bg-white [@media(hover:hover)]:shadow-sm [@media(hover:hover)]:ring-1 [@media(hover:hover)]:ring-slate-200 items-center opacity-0 group-hover:opacity-100 focus-within:opacity-100 [@media(hover:none)]:opacity-100">
                                        <button type="button" @click.prevent="clMove(i, 'up')" class="px-0.5 text-[13px] text-slate-400 hover:text-slate-800" title="ขึ้น" aria-label="ย้ายขึ้น">↑</button>
                                        <button type="button" @click.prevent="clMove(i, 'down')" class="px-0.5 text-[13px] text-slate-400 hover:text-slate-800" title="ลง" aria-label="ย้ายลง">↓</button>
                                        <button type="button" x-show="!i.from" @click.prevent="clRename(i)" class="px-0.5 text-[13px] text-slate-400 hover:text-blue-600" title="แก้ชื่อ" aria-label="แก้ชื่อรายการ">✎</button>
                                        <button type="button" @click.prevent="clRemark(i)" class="px-0.5 text-[13px] text-slate-400 hover:text-blue-600" title="หมายเหตุ" aria-label="หมายเหตุ">📝</button>
                                        <button type="button" x-show="!i.from" @click.prevent="clDelete(i)" class="rounded px-1 text-[13px] leading-none text-red-600 hover:bg-red-50" title="ลบ" aria-label="ลบรายการ">✕</button>
                                    </span>
                                </label>
                                <p x-show="i.remark" class="-mt-0.5 whitespace-pre-line break-words pb-1 pl-8 pr-1.5 text-[12px] text-slate-500" x-text="'📝 ' + i.remark"></p>
                                {{-- the status panel: each by name (× takes it off), "+ เพิ่ม" a colour (a group's replaces its other); saved at once --}}
                                <div x-show="ckPick === i.id" role="group" :aria-label="'สถานะของ ' + i.text" @keydown.escape.stop="ckPick = null" class="mb-1.5 ml-8 mr-1.5 flex flex-col gap-1.5 rounded-lg border border-slate-200 bg-slate-50 p-2">
                                    <div x-show="(i.tags || []).length" class="flex flex-wrap gap-1">
                                        <template x-for="s in ckTags(i)" :key="s.id"><span class="inline-flex h-7 items-center gap-1 rounded-full pl-2 text-[12px] font-semibold" :class="readOnly ? 'pr-2' : 'pr-0.5'" :style="ckChip(s)" :title="s.desc || s.name"><span class="h-2 w-2 rounded-full" :style="'background:' + s.dot"></span><span x-text="s.name + (s.active ? '' : ' (ปิดใช้แล้ว)')"></span><button type="button" x-show="!readOnly" @click="ckSet(i, i.tags.filter((t) => t !== s.id))" class="flex h-6 w-6 items-center justify-center rounded-full text-[14px] hover:bg-white/60" :aria-label="'เอา ' + s.name + ' ออก'">×</button></span></template>
                                    </div>
                                    <div x-show="!readOnly && ckAddable(i).length" class="flex flex-wrap items-center gap-1">
                                        <span class="text-[12px] text-slate-600">+ เพิ่ม</span>
                                        <template x-for="s in ckAddable(i)" :key="s.id"><button type="button" @click="ckAdd(i, s.id)" :title="s.desc || s.name" class="inline-flex h-7 items-center gap-1 rounded-full border border-dashed border-slate-400 bg-white px-2 text-[12px] font-semibold text-slate-700 hover:border-slate-600"><span class="h-2 w-2 rounded-full" :style="'background:' + s.dot"></span><span x-text="s.name + (ckRival(i, s) ? ' (แทน ' + ckRival(i, s).name + ')' : '')"></span></button></template>
                                    </div>
                                    <p x-show="!(i.tags || []).length && (readOnly || !ckAddable(i).length)" class="text-[12px] text-slate-600">ยังไม่มีสถานะ</p>
                                </div>
                                {{-- Settings > การส่งต่องาน: the date this item is expected (e.g. วันของเข้า), for the department that follows --}}
                                <template x-if="deptOf(i.deptId).dateLabel && canEditStage(i.deptId)">
                                    <div class="-mt-0.5 flex items-center gap-1.5 pb-1 pl-8 pr-1.5 text-[12px] text-amber-800">
                                        <span x-text="'📦 ' + deptOf(i.deptId).dateLabel"></span>
                                        <input type="text" placeholder="dd/mm/yyyy" x-init="dateField($el, () => clItemDate(i.id), (v) => setItemDate(i.id, v), true, 'h-6 w-[6.25rem] !py-0 !pr-2 !text-[12px]')">
                                        <button type="button" x-show="i.expected" @click="setItemDate(i.id, null)" class="text-slate-400 hover:text-red-600" title="ล้างวันที่" aria-label="ล้างวันที่">✕</button>
                                    </div>
                                </template>
                              </div>
                              {{-- the other list's item it is a copy of (or of the same name): beside it when there is room, under it otherwise --}}
                              <div x-show="clLinkedOf(w.id).length" class="border-dashed border-slate-200 bg-slate-50/70 py-1 pr-1.5 text-[13px]" :class="clSide() ? 'border-l pl-1.5' : 'pl-8'">
                                <template x-for="m in clMatches(w.id, i)" :key="m.l.id + m.id">
                                    <div class="flex flex-wrap items-start gap-x-2">
                                        <span class="w-4 shrink-0 text-center" :class="m.done ? 'text-emerald-600' : 'text-slate-300'" x-text="m.done ? '✓' : '○'"></span>
                                        <span class="min-w-[60%] flex-1 break-words leading-snug" :class="m.done ? 'text-slate-800' : 'text-slate-500'" x-text="m.text" :title="m.text + (m.remark ? ' — ' + m.remark : '')"></span>
                                        <span x-show="m.expected" class="shrink-0 rounded px-1 text-[12px]" :class="linkedLate(m) ? 'bg-red-50 font-semibold text-red-600' : 'bg-amber-50 text-amber-800'"
                                              :title="deptOf(m.l.deptId).dateLabel || ''" x-text="'📦 ' + fmtBE(m.expected)"></span>
                                        <template x-for="h in (m.receipts || [])" :key="h.dept"> <span class="shrink-0 rounded-full px-1.5 text-[12px] font-medium" :class="rcptClass(h)" x-text="rcptText(h)"></span>
                                        </template>
                                    </div>
                                </template>
                                <span x-show="!clMatches(w.id, i).length" class="block text-[12px] leading-5 text-slate-400">— ไม่มีในรายการที่เทียบ</span>
                              </div>
                              </div>
                            </template>
                            {{-- in the other list only --}}
                            <template x-for="m in clUnmatched(w)" :key="'um' + m.l.id + m.id">
                                <div :class="clSide() ? 'grid grid-cols-2' : ''">
                                    <div class="flex items-center gap-2 px-1.5 py-1 text-[12px] text-slate-400">— ยังไม่มีในรายการนี้</div>
                                    <div class="flex flex-wrap items-start gap-x-2 border-dashed border-slate-200 bg-slate-50/70 py-1 pr-1.5 text-[13px]" :class="clSide() ? 'border-l pl-1.5' : 'pl-8'">
                                        <span class="w-4 shrink-0 text-center" :class="m.done ? 'text-emerald-600' : 'text-slate-300'" x-text="m.done ? '✓' : '○'"></span>
                                        <span class="min-w-[60%] flex-1 break-words leading-snug" :class="m.done ? 'text-slate-800' : 'text-slate-500'" x-text="m.text"></span>
                                        <span x-show="m.expected" class="shrink-0 rounded px-1 text-[12px]" :class="linkedLate(m) ? 'bg-red-50 font-semibold text-red-600' : 'bg-amber-50 text-amber-800'" x-text="'📦 ' + fmtBE(m.expected)"></span>
                                        <template x-for="h in (m.receipts || [])" :key="h.dept"> <span class="shrink-0 rounded-full px-1.5 text-[12px] font-medium" :class="rcptClass(h)" x-text="rcptText(h)"></span>
                                        </template>
                                    </div>
                                </div>
                            </template>
                            <p x-show="!w.items.length && cl.adding !== w.id" class="px-1.5 py-1 text-[12px] text-slate-400">ยังไม่มีรายการ</p>
                            <input x-show="cl.adding === w.id" :id="'cl-draft-' + w.id" x-model="clDraft" type="text" maxlength="255" placeholder="ชื่อรายการ... (Enter เพิ่ม · วางจาก Excel ได้หลายแถว)"
                                   @keydown.enter.prevent="clCommit()" @keydown.escape.stop.prevent="cl = { ...cl, adding: null }; clDraft = ''" @blur="clCommit()" @paste="clPaste($event, w.id)"
                                   class="m-1 w-[calc(100%-0.5rem)] rounded-md border-slate-300 px-2 py-1 text-[13px]">
                            {{-- "ดูอย่างเดียว": a list this Sub Task only follows - its date and who received each item; nothing to tick --}}
                            <template x-for="l in clWatchedOf(w.id)" :key="'wl' + l.id">
                                <div class="border-t border-dashed border-slate-200 bg-slate-50/70">
                                    <a :href="l.href" target="_blank" class="flex flex-wrap items-center gap-x-1.5 px-1.5 py-1 text-[12px]">
                                        <span class="font-bold text-slate-500">👁 ดูอย่างเดียว</span>
                                        <span class="h-2 w-2 shrink-0 rounded-full" :style="'background-color:' + deptOf(l.deptId).color"></span>
                                        <span class="font-semibold text-slate-800" x-text="l.deptName || deptOf(l.deptId).name"></span>
                                        <span class="text-slate-600" x-text="'· ' + l.title"></span>
                                        <span class="ml-auto tabular-nums text-slate-500" x-text="'ของเข้า ' + l.got + '/' + l.total + ' · ไปดู ↗'"></span>
                                    </a>
                                    <template x-for="m in l.items" :key="m.id">
                                        <div class="flex items-start gap-2 px-1.5 py-1 text-[13px]">
                                            <span class="w-4 shrink-0 text-center" :class="m.received ? 'text-emerald-600' : 'text-slate-300'" x-text="m.received ? '✓' : '○'"></span>
                                            <div class="min-w-0 flex-1">
                                            <span class="block break-words" :class="m.received ? 'text-slate-800' : 'text-slate-500'" x-text="m.text"></span>
                                            <div class="mt-0.5 flex flex-wrap items-center gap-1">
                                            <span class="shrink-0 rounded px-1 text-[12px]" :class="m.expected ? (linkedLate(m) ? 'bg-red-50 font-semibold text-red-600' : 'bg-amber-50 text-amber-800') : 'text-slate-400'"
                                                  :title="l.dateLabel" x-text="m.expected ? '📦 ' + fmtBE(m.expected) : 'ยังไม่ลงวัน'"></span>
                                            <template x-for="h in (m.receipts || [])" :key="h.dept"> <span class="shrink-0 rounded-full px-1.5 text-[12px] font-medium" :class="rcptClass(h)" x-text="rcptText(h)"></span>
                                            </template>
                                            </div>
                                            </div>
                                        </div>
                                    </template>
                                    
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </template>
            <p x-show="clGroups().length === 0" class="px-1 py-2.5 text-xs leading-relaxed text-slate-400">ยังไม่มีงานที่จ่ายให้แผนกในตู้นี้</p>
          </div>
        </div>
        <button type="button" x-show="remarkCount(clTask)" @click.stop="openRemarks(cl.taskId)" class="mt-2 self-end text-[12px] font-semibold text-blue-600 hover:underline"
                x-text="'ดูหมายเหตุทั้งโครงการ (' + remarkCount(clTask) + ') ›'"></button>
    </div>
</template>
