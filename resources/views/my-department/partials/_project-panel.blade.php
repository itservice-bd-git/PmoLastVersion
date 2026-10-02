{{--
    Right Detail Panel: Project -> Cabinet -> Task -> Sub Task drill-down. Opens
    without a page refresh from any Project bar/row (Calendar, Timeline, List, Day
    panel) via openProjectPanel(group) - see _script.blade.php.

    Desktop (lg: and up): a docked slide-over, fixed width, Calendar/Timeline/List
    stay fully visible (and usable) to its left - no dimming backdrop, since this is
    meant to feel like a permanent side panel (ClickUp-style), not a blocking modal.
    Below lg: (mobile/tablet) it becomes a full-width drawer instead.

    Dense, ClickUp-referenced layout: this is a drill-down into a Project the user
    already picked, not a query page, so there's deliberately no Search/Filter/Sort
    here (see Calendar/Timeline/List for that) and no 5-card status grid - just a
    compact Header, a one-line Summary, and a flat divided Cabinet list (no card
    per Cabinet). Sub Task rows (_subtask-row.blade.php) reuse the exact same
    dotClass()/badgeClass()/act()/openDetail() as everywhere else, so Accept/Start/
    Checklist keep working unchanged; a row click opens the existing single-Sub-
    Task detail modal (with the full _item-actions button set), also unchanged.
--}}
<template x-if="panel">
    {{-- z-40, strictly below the Sub Task detail modal's z-50 (components/modal.blade.php)
         and rendered before it in the DOM - the modal must always stack ABOVE this panel,
         otherwise this panel's own click-catcher backdrop intercepts real mouse clicks
         meant for the modal (checkboxes, buttons) and closes the panel underneath it. --}}
    <div id="project-detail-panel" class="fixed inset-0 z-40" @keydown.escape.window="closeProjectPanel()">
        {{-- Transparent click-catcher (outside-click closes the panel) - intentionally
             not a dimming overlay, so the page behind stays fully legible. --}}
        <div class="absolute inset-0" @click="closeProjectPanel()"></div>

        <div class="absolute top-0 right-0 h-full w-full lg:w-[34rem] bg-white shadow-2xl border-l border-slate-200 flex flex-col"
             @click.stop
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full">

            {{-- Header - Project No./color + close on one line, Name/Customer/
                 Department+dates stacked below it, no card/box wrapping it. --}}
            <div class="px-4 py-2 border-b border-slate-100 shrink-0">
                <div class="flex items-center justify-between gap-2">
                    <div class="relative flex items-center gap-1.5 min-w-0">
                        <button type="button" @click.stop="colorPickerOpen = !colorPickerOpen"
                                class="shrink-0 w-3 h-3 rounded-full border border-black/10 hover:scale-110 transition"
                                :style="'background-color:' + (panel.color || taskColor(panel).hex)"
                                title="เปลี่ยนสี Project" aria-label="เปลี่ยนสี Project" aria-haspopup="true" :aria-expanded="colorPickerOpen.toString()"></button>
                        <p class="text-xs text-slate-400 truncate" x-text="panel.project_no"></p>

                        {{-- Color picker popover - presets (same hues the auto-assign could
                             have picked) plus a native color input for anything else;
                             "รีเซ็ต" clears the override and goes back to automatic. --}}
                        <div x-show="colorPickerOpen" x-cloak @click.outside="colorPickerOpen = false"
                             class="absolute left-0 top-5 z-10 bg-white border border-slate-200 rounded-lg shadow-lg p-3 w-56">
                            <p class="text-[11px] font-medium text-slate-500 mb-2">สี Project</p>
                            <div class="grid grid-cols-5 gap-2 mb-2.5">
                                <template x-for="hex in presetColors()" :key="hex">
                                    <button type="button" @click="setProjectColor(hex)"
                                            class="w-6 h-6 rounded-full border-2"
                                            :class="panel.color === hex ? 'border-slate-800' : 'border-transparent'"
                                            :style="'background-color:' + hex" :aria-label="hex"></button>
                                </template>
                            </div>
                            <div class="flex items-center justify-between gap-2 pt-2 border-t border-slate-100">
                                <label class="flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer">
                                    <input type="color" :value="panel.color || taskColor(panel).hex" @input="setProjectColor($event.target.value)" class="w-6 h-6 p-0 border-0 rounded cursor-pointer">
                                    กำหนดเอง
                                </label>
                                <button type="button" x-show="panel.color" @click="resetProjectColor()" class="text-xs text-blue-600 hover:underline">รีเซ็ต</button>
                            </div>
                        </div>
                    </div>
                    <button type="button" @click="closeProjectPanel()" class="shrink-0 text-slate-400 hover:text-slate-600 text-xl leading-none" aria-label="ปิด">&times;</button>
                </div>
                <h3 class="text-lg font-semibold text-slate-900 truncate leading-snug" x-text="panel.project_name"></h3>
                <p class="text-sm text-slate-500 truncate" x-text="panel.customer_name"></p>
                <p class="text-xs text-slate-400 mt-0.5">
                    <span x-text="departmentName"></span>
                    <span class="mx-1">·</span>
                    <span x-text="fmt(panel.dept_start)"></span><span class="mx-1">→</span><span x-text="fmt(panel.dept_due)"></span>
                    <template x-if="remainingDaysInfo(panel.dept_due, panel.progress === 100)">
                        <span>
                            <span class="mx-1">·</span>
                            <span :class="remainingDaysInfo(panel.dept_due, panel.progress === 100).class" x-text="remainingDaysInfo(panel.dept_due, panel.progress === 100).text"></span>
                        </span>
                    </template>
                </p>
            </div>

            <div class="flex-1 overflow-y-auto">
                {{-- Summary - one compact line + bar; no 5-card status grid. --}}
                <div class="px-4 py-2 border-b border-slate-100">
                    <div class="flex items-center justify-between text-xs text-slate-500 mb-1">
                        <span>
                            <span class="font-semibold text-slate-700" x-text="panel.total_subtasks"></span> งาน
                            <span x-show="panel.done"><span class="mx-1 text-slate-300">·</span><span class="font-medium text-emerald-600" x-text="panel.done"></span> เสร็จ</span>
                            <span x-show="panel.working"><span class="mx-1 text-slate-300">·</span><span class="font-medium text-teal-600" x-text="panel.working"></span> กำลังทำ</span>
                            <span x-show="panel.overdue"><span class="mx-1 text-slate-300">·</span><span class="font-medium text-red-600" x-text="panel.overdue"></span> เกิน</span>
                        </span>
                        <span class="font-semibold text-slate-700 tabular-nums" x-text="panel.progress + '%'"></span>
                    </div>
                    <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-1.5 rounded-full bg-emerald-500 transition-all" :style="'width:' + panel.progress + '%'"></div>
                    </div>
                </div>

                {{-- Cabinets - a flat divided list, no card per Cabinet (hierarchy comes
                     from typography/indent/divider, not border/rounded boxes). Collapsed
                     rows always render (cheap summary); a Cabinet's Task/Sub Task detail
                     only renders once expanded (template x-if, not x-show) so a Project
                     with many Cabinets doesn't render every Sub Task row at once. --}}
                <div class="px-4 pt-2 pb-1 flex items-center justify-between gap-2 flex-wrap">
                    <p class="text-sm font-semibold text-slate-700 shrink-0">Cabinets <span class="text-slate-400 font-normal" x-text="panelCabinets().length"></span></p>
                    <label class="flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer select-none">
                        <input type="checkbox" x-model="hideCompletedSubtasks" @change="toggleHideCompletedSubtasks()" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        ซ่อนงานที่เสร็จแล้ว
                    </label>
                    <button type="button" x-show="panelCabinets().length > 0" @click="toggleExpandAllCabinets()" class="text-xs font-medium text-blue-600 hover:underline shrink-0" x-text="anyCabinetExpanded() ? 'Collapse all' : 'Expand all'"></button>
                </div>
                <div class="border-t border-slate-100 divide-y divide-slate-100">
                    <template x-for="c in panelCabinets()" :key="c.cabinet_id">
                        <div>
                            <button type="button" @click="toggleCabinetExpand(c.cabinet_id)"
                                    class="w-full flex items-start gap-2 px-4 py-2 text-left hover:bg-slate-50 focus:outline-none focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-blue-300 transition"
                                    :class="panelExpanded[c.cabinet_id] ? 'bg-blue-50/50' : ''">
                                <svg class="shrink-0 w-3.5 h-3.5 mt-0.5 text-slate-400 transition-transform" :class="panelExpanded[c.cabinet_id] ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="flex items-center gap-1.5 min-w-0">
                                            <span class="text-sm font-medium truncate" :class="c.done === c.items.length ? 'text-slate-500' : 'text-slate-800'" x-text="c.cabinet_mo"></span>
                                            <span x-show="c.overdue > 0" class="shrink-0 text-red-500 text-xs font-bold" title="มีงานเกินกำหนด">!</span>
                                            <svg x-show="c.overdue === 0 && c.done === c.items.length" class="shrink-0 w-3 h-3 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                        </span>
                                        <span class="shrink-0 text-xs text-slate-400" x-text="c.items.length + ' งาน'"></span>
                                    </span>
                                    <span class="block text-xs truncate mt-0.5" :class="c.done === c.items.length ? 'text-emerald-600' : 'text-slate-400'">
                                        <span x-text="c.cabinet_name"></span>
                                        <template x-if="c.done === c.items.length">
                                            <span><span class="mx-1">·</span>เสร็จทั้งหมด</span>
                                        </template>
                                        <template x-if="c.done !== c.items.length && remainingDaysInfo(c.dueDate, false)">
                                            <span>
                                                <span class="mx-1">·</span>
                                                <span :class="remainingDaysInfo(c.dueDate, false).class" x-text="remainingDaysInfo(c.dueDate, false).text"></span>
                                            </span>
                                        </template>
                                    </span>
                                </span>
                            </button>
                            <template x-if="panelExpanded[c.cabinet_id]">
                                <div>
                                    {{-- A user can still manually expand a fully-completed Cabinet
                                         (point 6 - the Cabinet row itself is never hidden) - with
                                         every Task filtered out by hideCompletedSubtasks, show why
                                         it's empty instead of a blank expanded Cabinet. --}}
                                    <p x-show="visibleTasksFor(c).length === 0" class="text-xs text-slate-400 text-center py-3 pl-7">ไม่มีงานค้าง (เสร็จแล้วทั้งหมด)</p>
                                    <template x-for="t in visibleTasksFor(c)" :key="c.cabinet_id + '-' + (t.task_name || 'x')">
                                        <div>
                                            {{-- Task - a group header, not a card; indented from the
                                                 Cabinet row above so the hierarchy reads at a glance. --}}
                                            <div class="flex items-center justify-between pl-7 pr-4 py-1.5 bg-slate-50/60 border-b border-slate-100">
                                                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 truncate" x-text="t.task_name"></p>
                                                <p class="shrink-0 text-[11px] text-slate-400 ml-2" x-text="t.items.length"></p>
                                            </div>
                                            <div class="divide-y divide-slate-100">
                                                <template x-for="i in t.items" :key="'p' + i.id">
                                                    @include('my-department.partials._subtask-row', ['v' => 'i'])
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>
                    <p x-show="panelCabinets().length === 0" class="text-sm text-slate-400 text-center py-6">ไม่มี Cabinet</p>
                </div>
            </div>
        </div>
    </div>
</template>
