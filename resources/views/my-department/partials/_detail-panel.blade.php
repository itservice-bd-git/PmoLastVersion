{{--
    Right Side Work Detail Panel: one Sub Task/Assignment, with its Checklist.
    Opens without a page refresh from a Sub Task card anywhere it appears
    (Calendar, List, Day Panel, Project Panel's Cabinet accordion) via
    openDetail(item) - see _script.blade.php. Replaces the old centered modal
    (x-modal) so it matches the rest of the app's docked-panel pattern
    (_day-panel.blade.php, _project-panel.blade.php) instead of a floating box.

    z-50, ABOVE the Project Panel's z-40 and the Day Panel's z-30, and rendered
    after both in the DOM - whichever of those the user drilled down from stays
    open underneath (openDetail() never touches `panel`/`dayPanelOpen`), so
    closing this panel drops them right back into the same Cabinet/Task/Day.
--}}
<template x-if="detailPanelOpen">
    <div id="work-detail-panel" class="fixed inset-0 z-50" @keydown.escape.window="closeDetailPanel()">
        {{-- Transparent click-catcher (outside-click closes the panel) - intentionally
             not a dimming overlay, so the page behind (and any panel open underneath)
             stays fully legible. --}}
        <div class="absolute inset-0" @click="closeDetailPanel()"></div>

        <div class="absolute top-0 right-0 h-full w-full lg:w-[30rem] bg-white shadow-2xl border-l border-slate-200 flex flex-col"
             @click.stop
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full">

            <template x-if="detail">
                <div class="flex flex-col h-full">
                    <div class="px-4 py-3 border-b border-slate-100 flex items-start justify-between gap-3 shrink-0">
                        <div class="min-w-0">
                            <p class="text-xs text-slate-400 truncate" x-text="detail.project_no + ' · ' + detail.project_name"></p>
                            <h3 class="text-base font-semibold text-slate-900 truncate" x-text="detail.name"></h3>
                            <div class="flex flex-wrap gap-1 mt-1">
                                <span :class="badgeClass(detail)" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium">
                                    <span x-show="detail.assignment_status === 'COMPLETED'">✓</span>
                                    <span x-text="detail.assignment_status_label"></span>
                                </span>
                                <span x-show="detail.is_overdue" class="rounded-full px-2 py-0.5 text-xs font-medium bg-red-100 text-red-700">เกินกำหนด</span>
                            </div>
                        </div>
                        <button type="button" @click="closeDetailPanel()" class="shrink-0 text-slate-400 hover:text-slate-600 text-2xl leading-none" aria-label="ปิด">&times;</button>
                    </div>

                    <div class="flex-1 overflow-y-auto px-4 py-3">
                        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                            <div><dt class="text-xs text-slate-400">Project</dt><dd class="font-medium text-slate-700" x-text="detail.project_no"></dd></div>
                            <div><dt class="text-xs text-slate-400">Cabinet</dt><dd class="font-medium text-slate-700" x-text="detail.cabinet_mo + ' · ' + detail.cabinet_name"></dd></div>
                            <div><dt class="text-xs text-slate-400">Task</dt><dd class="font-medium text-slate-700" x-text="detail.task_name"></dd></div>
                            <div><dt class="text-xs text-slate-400">Sub Task</dt><dd class="font-medium text-slate-700" x-text="detail.name"></dd></div>
                            <div>
                                <dt class="text-xs text-slate-400">Department</dt>
                                <dd class="font-medium text-slate-700">
                                    <span x-text="detail.department_name || departmentName"></span>
                                    <span x-show="detail.is_department_locked" title="ไม่สามารถเปลี่ยนแผนกได้ เนื่องจากแผนกรับงานแล้ว">🔒</span>
                                </dd>
                            </div>
                            <div><dt class="text-xs text-slate-400">Priority</dt><dd><span :class="priorityClass(detail)" class="rounded px-1.5 py-0.5 text-xs" x-text="detail.priority_label"></span></dd></div>
                            <div><dt class="text-xs text-slate-400">Start Date</dt><dd class="font-medium text-slate-700" x-text="fmt(detail.start_date)"></dd></div>
                            <div>
                                <dt class="text-xs text-slate-400">Due Date</dt>
                                <dd class="font-medium text-slate-700">
                                    <span x-text="fmt(detail.due_date)"></span>
                                    <span x-show="durationDays(detail)" class="text-slate-400 font-normal" x-text="'(' + durationDays(detail) + ' วัน)'"></span>
                                </dd>
                            </div>
                            <div x-show="detail.owner_name"><dt class="text-xs text-slate-400">ผู้รับผิดชอบ</dt><dd class="font-medium text-slate-700" x-text="detail.owner_name"></dd></div>
                        </dl>

                        <div class="mt-4">
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="text-slate-500">Checklist Progress</span>
                                <span class="font-semibold text-slate-700"><span x-text="detail.progress"></span>% · <span x-text="detail.checklists_completed + '/' + detail.checklists_total"></span></span>
                            </div>
                            <div class="h-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-2 bg-blue-600 transition-all" :style="'width:' + detail.progress + '%'"></div></div>
                        </div>

                        {{-- Checklist - only the remaining (not-yet-done) items are listed;
                             a ticked item drops out of this list immediately instead of
                             staying visible with a strikethrough, so the list only ever
                             shows what's left to do. The count above already covers "how
                             many are done" - this section is just "what's left". --}}
                        <div class="mt-4">
                            <div class="flex items-center justify-between text-xs mb-2">
                                <span class="font-medium text-slate-500">Checklist</span>
                                <span class="font-semibold text-slate-700" x-text="'เหลือ ' + (detail.checklists_total - detail.checklists_completed) + ' จาก ' + detail.checklists_total"></span>
                            </div>
                            <p x-show="detailLoading" class="text-sm text-slate-400">กำลังโหลด...</p>
                            <p x-show="!detailLoading && detail.checklists_total === 0" class="text-sm text-slate-400">ยังไม่มี Checklist</p>
                            <p x-show="!detailLoading && detail.checklists_total > 0 && detail.checklists_completed === detail.checklists_total" class="text-sm font-medium text-emerald-600">เสร็จครบทุกรายการแล้ว ✓</p>
                            <p x-show="!detailLoading && detail.checklists_total > 0 && !detail.checklist_editable" class="text-xs text-slate-400 mb-2">ติ๊ก Checklist ได้เมื่อแผนกรับงานแล้วและยังไม่เสร็จงาน (เฉพาะสมาชิกแผนก)</p>
                            <ul class="space-y-1.5">
                                <template x-for="c in (detail.checklists || []).filter((c) => !c.is_completed)" :key="c.id">
                                    <li class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                               :checked="c.is_completed" :disabled="!detail.checklist_editable || !!c.busy"
                                               @change="toggleChecklist(c, $event)">
                                        <span class="text-slate-700" x-text="c.name"></span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </div>

                    <div class="px-4 py-3 border-t border-slate-100 flex flex-wrap items-center justify-between gap-2 shrink-0">
                        <a :href="detail.cabinet_url" class="text-sm font-medium text-blue-600 hover:underline">เปิดหน้า Cabinet →</a>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="closeDetailPanel()" class="px-4 py-2 text-sm font-medium text-slate-600">ปิด</button>
                            @include('my-department.partials._item-actions', ['v' => 'detail', 'modal' => true])
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
