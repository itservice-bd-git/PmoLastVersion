{{--
    Day Detail Panel: Sub Tasks/Assignments active on the selected Calendar day
    (point 6/9 of the Department Work Schedule redesign - one row per Sub Task,
    not a Project bar). Opens without a page refresh when a date is clicked
    (selectDate() -> openDayPanel() in _script.blade.php).

    Not _subtask-row.blade.php: that partial deliberately hides Project/Cabinet/
    Department (the Project Panel already shows them via its own headers before
    drilling down to a row) - here, a day can mix Sub Tasks from different
    Projects/Departments/Cabinets at once, so each row needs to show that context
    itself.

    z-30, below the Right Detail Panel's z-40 (_project-panel.blade.php) and the Work
    Detail Panel's z-50 (_detail-panel.blade.php) - a row here opens the Work Detail
    Panel on top (this panel stays open underneath), so closing it drops the user
    back into the same day list.
--}}
<template x-if="dayPanelOpen">
    <div id="day-detail-panel" class="fixed inset-0 z-30" @keydown.escape.window="closeDayPanel()">
        {{-- Transparent click-catcher (outside-click closes the panel) - intentionally
             not a dimming overlay, so the page behind stays fully legible. --}}
        <div class="absolute inset-0" @click="closeDayPanel()"></div>

        <div class="absolute top-0 right-0 h-full w-full lg:w-[26rem] bg-white shadow-2xl border-l border-slate-200 flex flex-col"
             @click.stop
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full">

            <div class="px-4 py-3 border-b border-slate-100 flex items-start justify-between gap-3 shrink-0">
                <div class="min-w-0">
                    <h4 class="text-sm font-semibold text-slate-900" x-text="dayHeading()"></h4>
                    <p class="text-xs text-slate-500 mt-0.5">งาน <span x-text="dayItems(selectedDate).length"></span> รายการ · <span x-text="isAllDepartments ? 'ทุกแผนก' : departmentName"></span></p>
                </div>
                <button type="button" @click="closeDayPanel()" class="shrink-0 text-slate-400 hover:text-slate-600 text-2xl leading-none" aria-label="ปิด">&times;</button>
            </div>

            <div class="flex-1 overflow-y-auto px-4 py-3 space-y-2.5">
                <template x-for="i in dayItems(selectedDate)" :key="'d' + i.id">
                    <div @click="openDetail(i)"
                         :class="departmentColor(i.department_name).accent"
                         :style="departmentColor(i.department_name).accentStyle"
                         :title="i.department_name + ' · ' + i.name"
                         class="rounded-xl border-t border-r border-b border-slate-200 border-l-4 p-3.5 hover:bg-slate-50/70 cursor-pointer transition">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-xs font-semibold text-slate-500" x-text="i.department_name"></p>
                            <span x-show="isHigh(i)" class="shrink-0 text-[10px] font-semibold text-red-700 bg-red-50 rounded px-1.5 py-0.5" x-text="i.priority_label"></span>
                        </div>
                        <p class="text-sm font-semibold leading-snug mt-0.5" :class="subtaskNameClass(i)" x-text="i.name"></p>
                        <p class="text-xs text-slate-400 mt-0.5" x-text="i.project_no + ' · ' + i.cabinet_mo + ' · ' + i.cabinet_name"></p>
                        <div class="flex flex-wrap items-center gap-1.5 mt-2">
                            <span :class="badgeClass(i)" class="rounded-full px-2 py-0.5 text-xs font-medium" x-text="shortStatusLabel(i)"></span>
                            <span x-show="i.is_overdue" class="rounded-full px-2 py-0.5 text-xs font-medium bg-red-100 text-red-700" x-text="'เกินกำหนด'"></span>
                        </div>
                        <div class="mt-2.5">
                            <div class="flex items-center justify-between text-xs text-slate-400 mb-1">
                                <span>Checklist</span>
                                <span class="font-medium text-slate-600" x-text="i.checklists_completed + '/' + i.checklists_total"></span>
                            </div>
                            <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                <div class="h-1.5 rounded-full bg-emerald-500 transition-all" :style="'width:' + i.progress + '%'"></div>
                            </div>
                        </div>
                    </div>
                </template>
                <div x-show="dayItems(selectedDate).length === 0" class="flex flex-col items-center justify-center text-center py-16 md:py-20">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="w-14 h-14 text-slate-300 mb-3">
                        <rect x="3" y="5" width="18" height="16" rx="2"></rect>
                        <path stroke-linecap="round" d="M3 10h18M8 3v4M16 3v4"></path>
                        <path stroke-linecap="round" d="M9 15l2.5 2.5L15.5 13"></path>
                    </svg>
                    <p class="text-base font-medium text-slate-400">ไม่มีงานในวันนี้</p>
                    <p class="text-sm text-slate-400 mt-1">เลือกวันอื่นเพื่อดูงาน หรือดูงานทั้งหมดในมุมมองรายการ</p>
                </div>
            </div>
        </div>
    </div>
</template>
