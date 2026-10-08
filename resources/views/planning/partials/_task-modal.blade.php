{{--
    Wide project modal. Left: the project (name, status, priority, dates), "ส่งต่อแผนก" chips (click one: its due date,
    its workflow buttons), the cabinets with their checklists you can tick right here. Right: Activity + compose.
    Everything the user changes is sent at once as one operation (see _script); a refused edit bounces back with the reason.
--}}
<template x-if="cur">
    <div class="fixed inset-0 z-40 flex items-start sm:items-center justify-center" :class="modalSize === 'full' ? 'p-1' : 'p-2 sm:p-6'" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-slate-900/40" @click="closeModal()"></div>

        {{-- size: normal 1000px / wide 1240px / full = (almost) the whole screen; the activity column grows with it --}}
        <div class="relative flex w-full flex-col rounded-2xl bg-white shadow-2xl border border-slate-200"
             :class="{ 'max-w-[1000px] max-h-[calc(100vh-1rem)] sm:max-h-[calc(100vh-3rem)]': modalSize === 'normal',
                       'max-w-[1240px] max-h-[calc(100vh-1rem)] sm:max-h-[calc(100vh-2rem)]': modalSize === 'wide',
                       'max-w-none h-[calc(100vh-0.5rem)]': modalSize === 'full' }" @click.stop="stageDept = null; closeChecklist()">
            <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3.5 shrink-0">
                <h3 class="text-base font-semibold text-slate-900">โครงการ</h3>
                <div class="flex items-center gap-2">
                    <div class="hidden sm:inline-flex overflow-hidden rounded-lg border border-slate-300 text-xs font-medium" role="group" aria-label="ขนาดหน้าต่าง">
                        <button type="button" @click="setModalSize('normal')" :class="modalSize === 'normal' ? 'bg-slate-800 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-2.5 py-1.5" title="ขนาดปกติ">ปกติ</button>
                        <button type="button" @click="setModalSize('wide')" :class="modalSize === 'wide' ? 'bg-slate-800 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-2.5 py-1.5 border-l border-slate-300" title="กว้าง">กว้าง</button>
                        <button type="button" @click="setModalSize('full')" :class="modalSize === 'full' ? 'bg-slate-800 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-2.5 py-1.5 border-l border-slate-300" title="เต็มจอ">เต็มจอ</button>
                    </div>
                    <button type="button" @click="toggleStar(cur)" :aria-pressed="isStarred(cur)" :title="isStarred(cur) ? 'เอาดาวออก' : 'ติดดาว (เก็บไว้ในเบราว์เซอร์นี้)'" class="rounded-lg p-1.5 hover:bg-slate-100" :class="isStarred(cur) ? 'text-amber-500' : 'text-slate-400 hover:text-slate-600'">
                        <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" :fill="isStarred(cur) ? 'currentColor' : 'none'"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>
                    </button>
                    <a :href="cur.pmoUrl" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100" title="เปิดหน้าโครงการใน PMO">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20V10m6 10V4m6 16v-7m4 7H2"/></svg>แดชบอร์ด
                    </a>
                    <button type="button" @click="closeModal()" class="text-2xl leading-none text-slate-400 hover:text-slate-600" aria-label="ปิด">&times;</button>
                </div>
            </div>

            <div class="flex min-h-0 flex-1 flex-col md:flex-row">
                {{-- ============ left: the project ============ --}}
                <div class="min-h-0 flex-1 overflow-y-auto p-5 space-y-4">
                    <div class="flex gap-2.5">
                        <div class="w-[126px] shrink-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-lg font-semibold text-slate-900" x-text="cur.so" title="เลขที่โครงการ"></div>
                        <template x-if="canEdit">
                            <input type="text" x-model="cur.title" @input.debounce.700ms="cur.title.trim() && editProject(cur, { title: cur.title.trim() })" maxlength="255" class="min-w-0 flex-1 rounded-lg border-slate-300 py-2 text-lg font-semibold text-slate-900" aria-label="ชื่อโครงการ">
                        </template>
                        <div x-show="!canEdit" class="min-w-0 flex-1 truncate rounded-lg border border-slate-300 px-3 py-2 text-lg font-semibold text-slate-900" x-text="cur.title"></div>
                    </div>

                    {{-- same box as the original page: status (wider) | priority, then the dates; light grey, hairline dividers --}}
                    <div class="grid grid-cols-1 overflow-hidden rounded-[10px] border border-slate-200 bg-slate-50 sm:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                        <div class="flex min-w-0 flex-col gap-1 px-3 py-2">
                            <span class="text-[10.5px] font-bold tracking-wide text-slate-400">สถานะ</span>
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex w-fit items-center rounded-[7px] px-3.5 py-[7px] text-[13px] font-semibold text-white" :style="'background-color:' + statusColor(cur)" x-text="statusOf(cur).name"></span>
                                <select x-show="canEdit" class="h-8 w-40 rounded-[7px] border-slate-300 bg-white px-2 py-0 text-xs" :value="cur.manualStatus ? cur.statusId : ''" @change="setStatus(cur, $event.target.value)" title="ตั้งสถานะเอง หรือให้ระบบคำนวณจากความคืบหน้าของแผนก">
                                    <option value="" :selected="!cur.manualStatus">อัตโนมัติ (จากแผนก)</option>
                                    <template x-for="s in statuses" :key="s.id"><option :value="s.id" x-text="s.name" :selected="cur.manualStatus && cur.statusId === s.id"></option></template>
                                </select>
                            </span>
                        </div>
                        <div class="flex min-w-0 flex-col gap-1 border-t border-slate-200 px-3 py-2 sm:border-l sm:border-t-0">
                            <span class="text-[10.5px] font-bold tracking-wide text-slate-400">ความสำคัญ</span>
                            <select x-model="cur.priority" @change="editProject(cur, { priority: cur.priority })" :disabled="!canEdit" :style="'color:' + priorities[cur.priority].color"
                                    class="h-8 w-full rounded-[7px] border-slate-300 bg-white px-2 py-1 text-[13px] font-semibold disabled:bg-slate-50">
                                <template x-for="(p, key) in priorities" :key="key"><option :value="key" :style="'color:' + p.color" x-text="'⚑ ' + p.label" :selected="key === cur.priority"></option></template>
                            </select>
                        </div>
                        <div class="flex min-w-0 flex-col gap-1 border-t border-slate-200 px-3 py-2">
                            <span class="text-[10.5px] font-bold tracking-wide text-slate-400">กำหนดเวลา</span>
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <span class="font-bold text-slate-600">เริ่ม</span>
                                <span class="relative"><input type="text" placeholder="dd/mm/yyyy" x-init="dateField($el, () => cur && cur.startDate, (v) => editProject(cur, { startDate: v }), canEdit, 'w-[8.75rem]')"><svg class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2.5"/><path stroke-linecap="round" d="M3 10h18M8 3v4m8-4v4"/></svg></span>
                                <span class="text-slate-400">→</span><span class="font-bold text-slate-600">จบ</span>
                                <span class="relative"><input type="text" placeholder="dd/mm/yyyy" x-init="dateField($el, () => cur && cur.date, (v) => v && editProject(cur, { date: v }), canEdit, 'w-[8.75rem]')"><svg class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2.5"/><path stroke-linecap="round" d="M3 10h18M8 3v4m8-4v4"/></svg></span>
                            </div>
                        </div>
                        <div class="hidden border-slate-200 sm:block sm:border-l sm:border-t"></div>
                    </div>

                    {{-- tags + the people in charge (= the departments that hold work on the project) + which board --}}
                    <div class="space-y-2">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="w-24 shrink-0 text-xs font-semibold text-slate-600">แท็ก</span>
                            <template x-for="id in cur.labels" :key="id">
                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold text-white" :style="'background-color:' + labelOf(id).color">
                                    <span x-text="labelOf(id).name"></span>
                                    <button type="button" x-show="canEdit" @click="toggleLabel(cur, id)" class="leading-none opacity-80 hover:opacity-100" aria-label="เอาแท็กออก">×</button>
                                </span>
                            </template>
                            <select x-show="canEdit" class="h-7 w-28 rounded-full border-slate-300 py-0 text-xs" @change="$event.target.value === '+new' ? createLabel(cur) : toggleLabel(cur, $event.target.value); $event.target.value = ''">
                                <option value="">+ แท็ก</option>
                                <template x-for="l in labels.filter((x) => !cur.labels.includes(x.id))" :key="l.id"><option :value="l.id" x-text="l.name"></option></template>
                                <option x-show="isPmo" value="+new">+ สร้างแท็กใหม่…</option>
                            </select>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="w-24 shrink-0 text-xs font-semibold text-slate-600">ผู้รับผิดชอบ</span>
                            <template x-for="id in cur.assignees" :key="id">
                                <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-2.5 py-0.5 text-xs font-semibold text-slate-700"><span class="h-2 w-2 rounded-full" :style="'background-color:' + deptOf(id).color"></span><span x-text="deptOf(id).name"></span></span>
                            </template>
                            <span x-show="cur.assignees.length === 0" class="text-xs text-slate-400">ยังไม่มีแผนก</span>
                        </div>
                        <div x-show="isPmo && boards.length > 1" class="flex flex-wrap items-center gap-1.5">
                            <span class="w-24 shrink-0 text-xs font-semibold text-slate-600">บอร์ด</span>
                            <select class="h-7 w-40 rounded-lg border-slate-300 py-0 text-xs" @change="setBoard(cur, $event.target.value)">
                                <template x-for="b in boards" :key="b.id"><option :value="b.id" x-text="b.name" :selected="b.id === (boardNo ? 'b' + boardNo : 'main')"></option></template>
                            </select>
                        </div>
                    </div>

                    {{-- ส่งต่อแผนก --}}
                    <div>
                        <p class="mb-1.5 text-xs font-semibold text-slate-600">ส่งต่อแผนก</p>
                        <div class="flex flex-wrap items-center gap-x-1 gap-y-1.5">
                            <template x-for="(s, i) in cur.deptStages" :key="s.deptId">
                                <span class="inline-flex items-center gap-1">
                                    <span x-show="i > 0" class="px-px text-[15px] leading-none text-slate-400">&rsaquo;</span>
                                    <button type="button" @click.stop="openStage(s.deptId, $event)" :aria-expanded="stageDept === s.deptId"
                                            class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border px-2.5 py-[5px] text-[12.5px] hover:border-blue-500 hover:bg-blue-50/40"
                                            :class="[s.done ? 'border-emerald-200 bg-emerald-50' : 'border-slate-300 bg-white', stageDept === s.deptId ? 'border-blue-500 ring-1 ring-blue-500' : '']">
                                        <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="'background-color:' + deptOf(s.deptId).color"></span>
                                        <span x-show="deptOf(s.deptId).icon" x-text="deptOf(s.deptId).icon"></span>
                                        <span class="font-semibold" :class="s.done ? 'text-emerald-700' : 'text-slate-800'" x-text="deptOf(s.deptId).name"></span>
                                        <span :class="stageInfo(s).cls" x-text="stageInfo(s).label"></span>
                                        <span x-show="s.done" class="font-bold text-emerald-500">✓</span>
                                        <span x-show="!s.done && progress(cur, s.deptId).total > 0" class="rounded-full bg-slate-100 px-1.5 text-[11px] text-slate-500 tabular-nums" x-text="progress(cur, s.deptId).pct + '%'"></span>
                                    </button>
                                </span>
                            </template>
                            <select x-show="isPmo && freeDepts(cur).length" class="h-8 w-36 rounded-lg border-dashed border-slate-300 py-0 text-xs font-semibold text-blue-600" @change="addStage(cur, $event.target.value); $event.target.value = ''">
                                <option value="">+ เพิ่มแผนก</option>
                                <template x-for="d in freeDepts(cur)" :key="d.id"><option :value="d.id" x-text="d.name"></option></template>
                            </select>
                            <a x-show="isPmo" :href="urls.assign" target="_blank" class="inline-flex items-center rounded-lg border border-dashed border-slate-300 px-[11px] py-[5px] text-[12.5px] font-bold text-blue-600 hover:border-blue-500 hover:bg-blue-50/40" title="จ่ายงานให้แผนก (เปิดหน้าจ่ายงานของ PMO)">+</a>
                            <p x-show="cur.deptStages.length === 0" class="text-sm text-slate-400">ยังไม่ได้จ่ายงานให้แผนก</p>
                        </div>
                    </div>

                    {{-- ตู้ + checklist: same look as the original Planning page (borderless rows, ringed status dot, checklist pill, date field, progress bar under the name) --}}
                    <div>
                        <div class="mb-1.5 flex flex-wrap items-center gap-2">
                            <p class="text-xs font-semibold text-slate-600">ตู้ <span class="font-normal text-slate-400" x-text="'(' + cur.subtasks.length + ')'"></span>
                            <span class="font-normal text-slate-400" x-text="'(checklist: ' + (dept ? deptOf(dept).name : 'ทุกแผนก') + ')'"></span></p>
                            <button type="button" x-show="isPmo" @click="cabForm = cabForm ? null : { mo: '', text: '' }" class="rounded-lg border border-dashed border-slate-300 px-2 py-0.5 text-xs font-bold text-blue-600 hover:border-blue-500">+ เพิ่มตู้</button>
                        </div>
                        <template x-if="cabForm">
                        <form @submit.prevent="addCabinet(cur)" class="mb-2 flex flex-wrap items-center gap-2 rounded-lg bg-slate-50 p-2">
                                <input type="text" x-model="cabForm.mo" placeholder="เลข MO" maxlength="100" class="h-8 w-32 rounded-lg border-slate-300 text-xs" required>
                                <input type="text" x-model="cabForm.text" placeholder="ชื่อตู้" maxlength="255" class="h-8 min-w-0 flex-1 rounded-lg border-slate-300 text-xs" required>
                                <button class="h-8 rounded-lg bg-blue-600 px-3 text-xs font-semibold text-white hover:bg-blue-700">เพิ่ม</button>
                            </form>
                        </template>
                        <div class="flex flex-col gap-1">
                            <template x-for="c in cur.subtasks" :key="c.id">
                                <div class="rounded-md px-1 py-1.5 hover:bg-slate-50">
                                    <div class="flex items-center gap-2">
                                        <span class="h-[18px] w-[18px] shrink-0 rounded-full border-2 border-white" :style="'background-color:' + cabColor(c) + ';box-shadow:0 0 0 1.5px ' + cabColor(c)" :title="cabStatus(c).name"></span>
                                        <div class="flex min-w-0 flex-1 items-center gap-2 text-left text-[13px]" :class="cabDone(c) ? 'line-through text-slate-400' : ''">
                                            <span class="min-w-[44px] flex-1 truncate" :class="cabDone(c) ? '' : 'text-slate-500'" x-text="c.mo"></span>
                                            <span class="flex-[4] truncate" :class="cabDone(c) ? '' : 'text-slate-800'" x-text="c.text"></span>
                                        </div>
                                        <button type="button" @click.stop="openChecklist(cur.id, c.id, $event)" class="inline-flex shrink-0 items-center gap-1 rounded-xl border bg-white px-2.5 py-[3px] text-[11px] font-semibold hover:bg-slate-100"
                                                :class="cabProgress(c, dept || null).total > 0 && cabProgress(c, dept || null).done === cabProgress(c, dept || null).total ? 'border-emerald-500 text-emerald-600' : 'border-slate-300 text-slate-500 hover:border-blue-500 hover:text-blue-600'" title="Checklist ของตู้นี้ (คลิกเพื่อเปิด)">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 12l3 3 5-6"/></svg><span class="tabular-nums" x-text="cabProgress(c, dept || null).done + '/' + cabProgress(c, dept || null).total"></span>
                                        </button>
                                        <span x-show="isPmo" class="flex shrink-0 items-center">
                                            <button type="button" @click.stop="renameCabinet(cur, c)" class="px-1 text-slate-400 hover:text-blue-600" title="แก้ชื่อ / เลข MO" aria-label="แก้ชื่อตู้">✎</button>
                                            <button type="button" @click.stop="deleteCabinet(cur, c)" class="px-1 text-slate-400 hover:text-red-600" title="ลบตู้" aria-label="ลบตู้">🗑</button>
                                        </span>
                                        <template x-if="canEdit">
                                            <span class="relative shrink-0" title="กำหนดส่งของตู้นี้ (ไม่ใส่ = ตามวันโปรเจค)">
                                                <input type="text" placeholder="dd/mm/yyyy" x-init="dateField($el, () => c.date, (v) => setCabinetDue(cur, c, v), true)">
                                                <svg class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2.5"/><path stroke-linecap="round" d="M3 10h18M8 3v4m8-4v4"/></svg>
                                            </span>
                                        </template>
                                        <span x-show="!canEdit" class="relative shrink-0 w-[88px] rounded-lg border border-slate-200 px-2 py-1 text-[13px] text-slate-500 tabular-nums" x-text="c.date ? fmtBE(c.date) : 'dd/mm/yyyy'"></span>
                                    </div>
                                    <div class="mt-1 ml-[26px] mr-0.5 h-[5px] overflow-hidden rounded-[3px] bg-slate-100" title="ความคืบหน้า checklist">
                                        <div class="h-full rounded-[3px] transition-all" :class="cabProgress(c, dept || null).pct === 100 ? 'bg-emerald-500' : 'bg-blue-600'" :style="'width:' + cabProgress(c, dept || null).pct + '%'"></div>
                                    </div>
                                </div>
                            </template>
                            <p x-show="cur.subtasks.length === 0" class="px-3 py-6 text-center text-sm text-slate-400">ยังไม่มีตู้ที่จ่ายงานให้แผนก</p>
                        </div>
                    </div>

                    <details class="border-t border-slate-200 pt-2.5" x-data="{ o: !!cur.description }" :open="o" @toggle="o = $el.open">
                        <summary class="flex cursor-pointer list-none items-center gap-1.5 py-0.5 text-[12.5px] font-semibold text-slate-500 [&::-webkit-details-marker]:hidden">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8zM14 3v5h5M9 13h6M9 17h6"/></svg>
                            <span>รายละเอียด</span><span class="text-[11px]" x-text="o ? '▾' : '▸'"></span>
                        </summary>
                        <div class="mt-2.5">
                            <label class="mb-1 block text-xs font-semibold text-slate-600">รายละเอียด</label>
                            <textarea x-model="cur.description" @input.debounce.800ms="editProject(cur, { description: cur.description })" :readonly="!canEdit" rows="3" maxlength="5000" placeholder="หมายเหตุ / ขอบเขตงาน..." class="min-h-[64px] w-full rounded-lg border-slate-300 text-sm read-only:bg-slate-50"></textarea>
                        </div>
                    </details>
                </div>

                {{-- ============ right: Activity ============ --}}
                <aside class="flex min-h-[18rem] md:min-h-0 shrink-0 flex-col border-t md:border-t-0 md:border-l border-slate-200 bg-slate-50"
                       :class="modalSize === 'normal' ? 'md:w-80' : (modalSize === 'wide' ? 'md:w-96' : 'md:w-[28rem]')">
                    <div class="flex items-center justify-between gap-2 border-b border-slate-200 px-4 py-3 text-[13px] font-bold">
                        <div class="flex items-center gap-1" role="tablist">
                            <button type="button" role="tab" @click="feedTab = 'activity'" :aria-selected="feedTab === 'activity'" class="rounded-full px-2.5 py-1 text-[13px] font-bold" :class="feedTab === 'activity' ? 'bg-slate-800 text-white' : 'text-slate-500 hover:bg-slate-200'">Activity</button>
                            <button type="button" role="tab" x-show="cur.logUrl" @click="openLog()" :aria-selected="feedTab === 'log'" class="rounded-full px-2.5 py-1 text-[13px] font-bold" :class="feedTab === 'log' ? 'bg-slate-800 text-white' : 'text-slate-500 hover:bg-slate-200'" title="บันทึกการเปลี่ยนแปลงทั้งหมดของโครงการ">การดำเนินการ</button>
                        </div>
                        <a :href="cur.pmoUrl" class="inline-flex items-center gap-1.5 rounded-full border border-slate-300 bg-white px-2.5 py-1 text-[12.5px] font-semibold text-slate-500 hover:bg-slate-50 hover:text-slate-800" title="ดู/แนบไฟล์ที่หน้าโครงการ">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8l-8.5 8.5a5 5 0 01-7-7l9-9a3.3 3.3 0 014.7 4.7l-9 9a1.7 1.7 0 01-2.4-2.4l8.3-8.3"/></svg>ไฟล์แนบ
                        </a>
                    </div>
                    <div x-show="feedTab === 'activity'" x-ref="feedBox" x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)" class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto px-3.5 py-3">
                        <p x-show="cur.comments.length === 0" class="px-2 py-6 text-center text-xs leading-[1.7] text-slate-400">ยังไม่มีความเคลื่อนไหว<br>เขียนอัพเดตหรือความคิดเห็นได้ด้านล่าง</p>
                        <template x-for="c in cur.comments" :key="c.id">
                            <div>
                                <div class="flex gap-2">
                                    <span class="flex h-[26px] w-[26px] shrink-0 items-center justify-center rounded-full text-xs font-bold text-white" :style="'background-color:' + avatarColor(c.author)" x-text="initial(c.author)"></span>
                                    <div class="min-w-0 flex-1">
                                        <div class="mb-0.5 flex items-baseline gap-1.5"><span class="text-[12.5px] font-semibold text-slate-900" x-text="c.author"></span><span class="text-[11px] text-slate-400" x-text="fmtDateTime(c.at)"></span></div>
                                        <div class="flex flex-wrap gap-1.5 empty:hidden" x-show="(c.files || []).length">
                                            <template x-for="f in (c.files || [])" :key="f.name + f.url">
                                                <a :href="f.url || '#'" target="_blank" rel="noopener" class="inline-flex max-w-full items-center gap-1 overflow-hidden rounded-lg border border-slate-200 bg-white text-[11px] text-slate-600 hover:border-blue-400">
                                                    <img x-show="f.image && f.url" :src="f.url" class="h-14 w-14 object-cover" alt="">
                                                    <span class="truncate px-2 py-1" x-text="'📎 ' + f.name + (f.size ? ' · ' + f.size : '')"></span>
                                                </a>
                                            </template>
                                        </div>
                                        <p class="whitespace-pre-wrap break-words text-[13px] leading-normal text-slate-900"><template x-for="(part, i) in commentParts(c.text)" :key="i"><span :class="part.m ? 'rounded bg-indigo-50 px-[3px] font-semibold text-blue-700' : ''" x-text="part.t"></span></template></p>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                    {{-- Change log = the project page's Activity Log tree (Cabinet > Task > Sub Task > Checklist > activity), read-only, PMO roles only --}}
                    <div x-show="feedTab === 'log'" x-cloak class="flex min-h-0 flex-1 flex-col overflow-hidden">
                        <div class="flex items-center gap-2 border-b border-slate-200 px-3.5 py-1.5 text-[11px] text-slate-400">
                            <span class="flex-1" x-text="logTree.total ? logTree.total + ' กิจกรรม · ล่าสุดก่อน' : ''"></span>
                            <button type="button" @click="logSetAll(true)" class="text-blue-600 hover:underline">ขยายทั้งหมด</button><span>|</span>
                            <button type="button" @click="logSetAll(false)" class="text-blue-600 hover:underline">ยุบทั้งหมด</button>
                        </div>
                        <div class="min-h-0 flex-1 overflow-y-auto bg-white" style="--ind: 16px">
                            <p x-show="logTree.loading" class="px-4 py-6 text-center text-xs text-slate-400">กำลังโหลด...</p>
                            <p x-show="logTree.error" x-text="logTree.error" class="px-4 py-6 text-center text-xs text-red-500"></p>
                            <p x-show="!logTree.loading && !logTree.error && logTree.nodes.length === 0" class="px-4 py-6 text-center text-xs text-slate-400">ยังไม่มีประวัติการเปลี่ยนแปลง</p>
                            <template x-for="r in logRows()" :key="r.id">
                                <div class="relative border-b border-slate-50">
                                    <template x-for="d in r.depth" :key="d"><span class="absolute bottom-0 top-0 border-l border-slate-200" :style="'left:calc(var(--ind) * ' + (d - 1) + ' + 17px)'"></span></template>
                                    <template x-if="r.node">
                                        <button type="button" @click="logToggle(r.node.key)" class="relative flex min-h-[34px] w-full items-center gap-1.5 pr-3 text-left hover:bg-slate-50" :class="r.node.type === 'cabinet' ? 'bg-slate-50/60' : ''" :style="'padding-left: calc(var(--ind) * ' + r.depth + ' + 14px)'">
                                            <svg class="h-3 w-3 shrink-0 text-slate-400 transition-transform" :class="logTree.open[r.node.key] ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="currentColor"><path d="M7 4l6 6-6 6V4z"/></svg>
                                            <span class="shrink-0 text-[10.5px] text-slate-400" x-text="r.node.word || logWords[r.node.type] || ''"></span>
                                            <span class="min-w-0 flex-1 truncate text-[12.5px]" :class="r.node.type === 'cabinet' ? 'font-semibold text-slate-800' : 'text-slate-700'" x-text="r.node.label"></span>
                                            <span class="shrink-0 rounded-full bg-slate-100 px-1.5 text-[11px] font-medium tabular-nums text-slate-600" x-text="r.node.count"></span>
                                        </button>
                                    </template>
                                    <template x-if="r.act">
                                        <div class="relative py-1.5 pr-3 text-xs" :style="'padding-left: calc(var(--ind) * ' + r.depth + ' + 14px)'">
                                            <div class="flex items-start gap-1.5">
                                                <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full" :class="logDot(r.act.icon)"></span>
                                                <span class="min-w-0 flex-1 text-slate-800" x-text="r.act.title"></span>
                                            </div>
                                            <div class="ml-3 mt-0.5 text-[11px] text-slate-400"><span x-text="r.act.actor"></span> · <span x-text="r.act.at"></span></div>
                                            <template x-for="c in r.act.changes" :key="c.label">
                                                <p class="ml-3 break-words text-[11px] text-slate-500"><span class="text-slate-400" x-text="c.label + ': '"></span><span x-text="c.old"></span> <span class="text-slate-400">→</span> <span class="font-medium text-slate-800" x-text="c.new"></span></p>
                                            </template>
                                            <p x-show="r.act.detail" class="ml-3 break-words text-[11px] text-slate-500" x-text="r.act.detail"></p>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                    <div x-show="feedTab === 'activity'" class="border-t border-slate-200 bg-white px-3 py-2.5">
                        <div class="relative">
                            {{-- @ picker: type "@" and a name; ↑↓ + Enter/Tab picks, Esc closes. The tagged people get a notice (bell + popup). --}}
                            <div x-show="mentionOpen && mentionList().length" x-cloak class="absolute bottom-full left-0 z-10 mb-1 max-h-48 w-full overflow-y-auto rounded-[10px] border border-slate-200 bg-white p-1 shadow-lg" role="listbox" aria-label="แท็กคน">
                                <template x-for="(p, i) in mentionList()" :key="p.id">
                                    <button type="button" @mousedown.prevent="pickMention(p)" @mouseenter="mentionIdx = i" role="option" :aria-selected="i === mentionIdx"
                                            class="flex w-full items-center gap-2 rounded-[7px] px-2 py-1.5 text-left text-[13px]" :class="i === mentionIdx ? 'bg-indigo-50' : ''">
                                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white" :style="'background-color:' + avatarColor(p.name)" x-text="initial(p.name)"></span>
                                        <span class="min-w-0 flex-1 truncate font-semibold" x-text="p.name"></span><span class="shrink-0 text-[11px] text-slate-400" x-text="p.dept || ''"></span>
                                    </button>
                                </template>
                            </div>
                            <textarea x-ref="commentBox" x-model="comment" rows="2" maxlength="2000" placeholder="เขียนอัพเดต / ความคิดเห็น...  (@ แท็กคน · Enter ส่ง)"
                                      @input="onCommentInput($event)" @click="onCommentInput($event)" @keydown="onCommentKey($event)" @blur="setTimeout(() => mentionOpen = false, 150)"
                                      class="min-h-[40px] w-full resize-none rounded-lg border-slate-300 px-2.5 py-2 text-[13px]"></textarea>
                        </div>
                        {{-- photos / files picked for the next comment (up to 5; same types and size limit as the project page) --}}
                        <div x-show="files.length" class="mt-2 flex flex-wrap gap-1.5">
                            <template x-for="(f, i) in files" :key="i">
                                <span class="inline-flex max-w-[12rem] items-center gap-1 rounded-lg bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">
                                    <span class="truncate" x-text="'📎 ' + f.name"></span>
                                    <button type="button" @click="files.splice(i, 1)" class="text-slate-400 hover:text-red-600" aria-label="เอาไฟล์ออก">×</button>
                                </span>
                            </template>
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                            <input type="file" x-ref="fileInput" multiple class="hidden" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.dwg,.dxf" @change="pickFiles($event)">
                            <button type="button" @click="$refs.fileInput.click()" :disabled="files.length >= 5" class="h-8 shrink-0 rounded-lg border border-slate-300 px-2 text-slate-500 hover:bg-slate-50 disabled:opacity-40" title="แนบรูป / ไฟล์ (สูงสุด 5 ไฟล์ ไฟล์ละ 20MB)" aria-label="แนบไฟล์">📎</button>
                            <select x-show="isPmo" x-model="alertDept" class="h-8 w-40 shrink-0 rounded-lg border-slate-300 py-0 text-xs" title="ส่งเป็นการแจ้งเตือนไปแผนก (เด้ง popup)">
                                <option value="">ไม่แจ้งเตือน</option>
                                <template x-for="d in departments" :key="d.id"><option :value="d.id" x-text="'แจ้งเตือน → ' + d.name"></option></template>
                            </select>
                            <div class="flex-1"></div>
                            <button type="button" @click="postComment()" :disabled="posting || (!comment.trim() && !files.length)" class="h-8 rounded-lg bg-blue-600 px-3.5 text-[13px] font-semibold text-white hover:bg-blue-700 disabled:opacity-50">ส่ง</button>
                        </div>
                    </div>
                </aside>
            </div>

            <div class="flex shrink-0 items-center gap-3 border-t border-slate-100 px-5 py-3">
                <button type="button" x-show="isPmo" @click="deleteProject()" class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">ลบโครงการ</button>
                <div class="flex-1"></div>
                <span class="text-xs text-slate-400" x-text="saving ? 'กำลังบันทึก...' : (savedAt ? '✓ บันทึกอัตโนมัติ ' + savedAt : '✓ บันทึกอัตโนมัติ')"></span>
                <button type="button" @click="closeModal()" class="rounded-lg border border-slate-300 px-4 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">ปิด</button>
            </div>
        </div>

        {{-- department popover: its due date, where its Sub Tasks are in the workflow, and the buttons the user may press --}}
        <template x-if="stageDept && stageOf(cur, stageDept)">
            <div class="fixed z-50 w-72 rounded-xl border border-slate-200 bg-white p-3 shadow-xl space-y-2.5" :style="'top:' + stagePos.top + 'px;left:' + stagePos.left + 'px'" @click.stop>
                <p class="flex items-center gap-2 text-sm font-semibold text-slate-900"><span class="w-2.5 h-2.5 rounded-full" :style="'background-color:' + deptOf(stageDept).color"></span><span x-text="deptOf(stageDept).name"></span>
                    <span x-show="stageOf(cur, stageDept).done" class="rounded-full bg-emerald-100 px-2 text-[11px] font-semibold text-emerald-700">เสร็จแล้ว</span></p>
                <div class="flex items-center gap-2 text-xs text-slate-500"><span>กำหนดส่ง</span>
                    <input type="text" placeholder="dd/mm/yyyy" x-init="dateField($el, () => { const s = stageOf(cur, stageDept); return s && s.due; }, (v) => setDue(cur, stageDept, v), canEdit)">
                </div>
                {{-- the extra fields (Settings > ฟิลด์เพิ่มเติม) this department fills in for the project --}}
                <template x-for="f in (deptOf(stageDept).fields || [])" :key="f.id">
                    <label class="flex items-center gap-2 text-xs text-slate-500">
                        <span class="w-24 shrink-0 truncate" x-text="f.name" :title="f.name"></span>
                        <template x-if="!f.type">
                            <select class="h-8 min-w-0 flex-1 rounded-lg border-slate-300 py-0 text-xs" :disabled="!canEditStage(stageDept)" @change="setField(cur, stageDept, f, $event.target.value)">
                                <option value="" :selected="!fieldValue(cur, stageDept, f)">— ไม่ระบุ —</option>
                                <template x-for="o in fieldOptions(f)" :key="o.id"><option :value="o.id" x-text="o.text" :selected="fieldValue(cur, stageDept, f) === o.id"></option></template>
                            </select>
                        </template>
                        <template x-if="f.type">
                            <span class="flex min-w-0 flex-1 items-center gap-1">
                                <input :type="f.type === 'link' ? 'url' : 'text'" maxlength="500" class="h-8 min-w-0 flex-1 rounded-lg border-slate-300 py-0 text-xs" :value="fieldValue(cur, stageDept, f)" :disabled="!canEditStage(stageDept)"
                                       :placeholder="f.type === 'link' ? 'https://…' : 'พิมพ์…'" @change="setField(cur, stageDept, f, $event.target.value)">
                                <a x-show="f.type === 'link' && /^https?:\/\//i.test(fieldValue(cur, stageDept, f))" :href="fieldValue(cur, stageDept, f)" target="_blank" rel="noopener noreferrer" class="text-blue-600" title="เปิดลิงก์">↗</a>
                            </span>
                        </template>
                    </label>
                </template>
                <template x-for="p in [stageOf(cur, stageDept).pmo]" :key="'pmo'">
                    <div class="space-y-2">
                        <p class="text-xs text-slate-600">งานของแผนก: รอรับ <b x-text="p.waiting"></b> · รับแล้ว <b x-text="p.accepted"></b> · กำลังทำ <b x-text="p.working"></b> · เสร็จ <b x-text="p.done"></b></p>
                        <div class="flex flex-wrap gap-2" x-show="p.canAccept || p.canStart || p.canComplete">
                            <button type="button" x-show="p.canAccept" @click="step(cur, stageDept, 'accept')" class="rounded-lg bg-blue-600 px-3 py-1 text-xs font-semibold text-white hover:bg-blue-700">รับงาน</button>
                            <button type="button" x-show="p.canStart" @click="step(cur, stageDept, 'start')" class="rounded-lg bg-blue-600 px-3 py-1 text-xs font-semibold text-white hover:bg-blue-700">เริ่มงาน</button>
                            <button type="button" x-show="p.canComplete" @click="step(cur, stageDept, 'complete')" class="rounded-lg bg-emerald-600 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-700">เสร็จงาน</button>
                        </div>
                        <p x-show="!(p.canAccept || p.canStart || p.canComplete)" class="text-[11px] text-slate-400">รับ / เริ่ม / ปิดงาน ทำได้เฉพาะสมาชิกของแผนกนี้</p>
                    </div>
                </template>
                <button type="button" x-show="isPmo && stageOf(cur, stageDept).pmo.accepted + stageOf(cur, stageDept).pmo.working + stageOf(cur, stageDept).pmo.done === 0" @click="removeStage(cur, stageDept)" class="text-xs font-medium text-red-600 hover:underline">เอาแผนกนี้ออกจากโครงการ</button>
                <p class="text-xs text-slate-500" x-text="'Checklist ' + progress(cur, stageDept).done + '/' + progress(cur, stageDept).total + ' (' + progress(cur, stageDept).pct + '%)'"></p>
            </div>
        </template>
    </div>
</template>
