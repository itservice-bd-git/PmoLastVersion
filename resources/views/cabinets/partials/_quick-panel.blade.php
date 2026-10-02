{{--
    Cabinet Quick Detail Panel - opens from a Cabinet card click on the Project
    > ตู้ไฟฟ้า tab (see projects/show.blade.php) without leaving that page.
    Alpine state (cabinetQuickPanel()) is spread into the page's existing root
    x-data; see _quick-panel-script.blade.php for why/how every action here
    reuses the real Cabinet/Assignment/Checklist/Attachment system verbatim.

    Same slide-over mechanics as My Department's Right Detail Panel (docked on
    desktop, no dimming backdrop, full-width drawer below lg:) but wider, per
    spec: ~48-55vw desktop (clamped 650-900px), ~70vw tablet, 100vw mobile.
--}}
<template x-if="cabinetPanelOpen">
    <div id="cabinet-quick-panel" class="fixed inset-0 z-40" @keydown.escape.window="handleEscape()">
        <div class="absolute inset-0" @click="closeCabinetPanel()"></div>

        <div class="absolute top-0 right-0 h-full w-full md:w-[70vw] lg:w-[52vw] lg:min-w-[650px] lg:max-w-[900px] bg-white shadow-2xl border-l border-slate-200 flex flex-col"
             @click.stop
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full">

            {{-- Loading skeleton - never a blank panel while the request is in flight --}}
            <template x-if="cabinetPanelLoading">
                <div class="p-4 space-y-4 animate-pulse">
                    <div class="flex items-center justify-between">
                        <div class="space-y-2">
                            <div class="h-5 w-32 bg-slate-200 rounded"></div>
                            <div class="h-3 w-24 bg-slate-100 rounded"></div>
                        </div>
                        <div class="h-6 w-20 bg-slate-200 rounded-full"></div>
                    </div>
                    <div class="h-2 w-full bg-slate-100 rounded-full"></div>
                    <div class="space-y-2 pt-4">
                        <div class="h-10 bg-slate-100 rounded"></div>
                        <div class="h-10 bg-slate-100 rounded"></div>
                        <div class="h-10 bg-slate-100 rounded"></div>
                    </div>
                </div>
            </template>

            {{-- Error state - the panel stays open, user can retry (point 31) --}}
            <template x-if="!cabinetPanelLoading && cabinetPanelError">
                <div class="flex-1 flex flex-col items-center justify-center gap-3 p-6 text-center">
                    <p class="text-sm text-slate-500">ไม่สามารถโหลดข้อมูลตู้ได้</p>
                    <button type="button" @click="loadCabinet()" class="px-3 py-1.5 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700">ลองใหม่</button>
                    <button type="button" @click="closeCabinetPanel()" class="text-xs text-slate-400 hover:text-slate-600">ปิด</button>
                </div>
            </template>

            <template x-if="!cabinetPanelLoading && !cabinetPanelError && cabinetDetail">
                <div class="flex-1 flex flex-col min-h-0">
                    {{-- Header --}}
                    <div class="px-4 py-3 border-b border-slate-100 shrink-0">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <h3 class="text-base font-bold text-slate-900 truncate" x-text="cabinetDetail.cabinet.mo_no"></h3>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium whitespace-nowrap shrink-0"
                                          :class="workStatusBadgeClass(cabinetDetail.cabinet.status)"
                                          x-text="cabinetDetail.cabinet.status_label"></span>
                                </div>
                                <p class="text-xs text-slate-500 mt-0.5 truncate" x-text="cabinetDetail.cabinet.cabinet_name"></p>
                            </div>
                            <button type="button" @click="closeCabinetPanel()" class="shrink-0 text-slate-400 hover:text-slate-600 text-xl leading-none" aria-label="ปิด">&times;</button>
                        </div>
                        <p class="text-xs text-slate-400 mt-1.5">
                            Due <span x-text="fmt(cabinetDetail.cabinet.due_date)"></span>
                            <template x-if="remainingDaysInfo(cabinetDetail.cabinet.days_remaining, cabinetDetail.cabinet.status === 'completed')">
                                <span>
                                    <span class="mx-1">·</span>
                                    <span :class="remainingDaysInfo(cabinetDetail.cabinet.days_remaining, cabinetDetail.cabinet.status === 'completed').class"
                                          x-text="remainingDaysInfo(cabinetDetail.cabinet.days_remaining, cabinetDetail.cabinet.status === 'completed').text"></span>
                                </span>
                            </template>
                        </p>
                        {{-- Full Cabinet Page is never removed - this is purely a shortcut (point 5) --}}
                        <a :href="cabinetDetail.cabinet.show_url" class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:underline">
                            เปิดหน้าตู้แบบเต็ม
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                        </a>
                    </div>

                    {{-- Tabs --}}
                    <div class="flex items-center gap-1 px-4 border-b border-slate-200 shrink-0">
                        <button type="button" @click="panelTab = 'tasks'"
                                :class="panelTab === 'tasks' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                                class="px-3 py-2 text-sm font-medium border-b-2 -mb-px">งาน (<span x-text="cabinetDetail.tasks.length"></span> กลุ่ม)</button>
                        <button type="button" @click="panelTab = 'attachments'"
                                :class="panelTab === 'attachments' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                                class="px-3 py-2 text-sm font-medium border-b-2 -mb-px">ไฟล์แนบ (<span x-text="cabinetDetail.cabinet.attachments.length"></span>)</button>
                    </div>

                    <div class="flex-1 overflow-y-auto">
                        {{-- ================= TASKS TAB ================= --}}
                        <div x-show="panelTab === 'tasks'">
                            {{-- Progress --}}
                            <div class="px-4 py-3 border-b border-slate-100">
                                <div class="flex items-center justify-between text-xs mb-1.5">
                                    <span class="text-slate-500">Progress</span>
                                    <span class="font-bold text-slate-800 tabular-nums" x-text="cabinetDetail.cabinet.progress + '%'"></span>
                                </div>
                                <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                                    <div class="h-2 rounded-full transition-all"
                                         :class="cabinetDetail.cabinet.progress >= 100 ? 'bg-emerald-500' : cabinetDetail.cabinet.progress >= 60 ? 'bg-blue-500' : cabinetDetail.cabinet.progress >= 30 ? 'bg-amber-500' : 'bg-slate-300'"
                                         :style="'width:' + cabinetDetail.cabinet.progress + '%'"></div>
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-1 mt-3">
                                    <template x-for="t in cabinetDetail.tasks" :key="'sum-' + t.id">
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="text-slate-500 truncate" x-text="t.name"></span>
                                            <span class="font-semibold tabular-nums shrink-0 ml-1"
                                                  :class="t.progress >= 100 ? 'text-emerald-600' : t.progress > 0 ? 'text-blue-600' : 'text-slate-400'"
                                                  x-text="t.progress + '%'"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            {{-- Hide completed toggle --}}
                            <div class="px-4 py-2 border-b border-slate-100 flex items-center justify-end">
                                <label class="flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer select-none">
                                    <input type="checkbox" x-model="hideCompletedSubtasks" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                    ซ่อนงานที่เสร็จแล้ว
                                </label>
                            </div>

                            {{-- Task accordion - every Task starts collapsed, no auto-expand (point 9) --}}
                            <div class="divide-y divide-slate-100">
                                <template x-for="task in cabinetDetail.tasks" :key="task.id">
                                    <div>
                                        <button type="button" @click="toggleTask(task.id)"
                                                class="w-full flex items-center justify-between gap-2 px-4 py-2.5 text-left hover:bg-slate-50 focus:outline-none focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-blue-300"
                                                :class="expandedTasks[task.id] ? 'bg-blue-50/50' : ''">
                                            <span class="flex items-center gap-2 min-w-0">
                                                <svg class="shrink-0 w-3.5 h-3.5 text-slate-400 transition-transform" :class="expandedTasks[task.id] ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                                                <span class="text-sm font-medium text-slate-800 truncate" x-text="task.name"></span>
                                            </span>
                                            <span class="flex items-center gap-3 shrink-0 text-xs">
                                                <span class="font-semibold tabular-nums"
                                                      :class="task.progress >= 100 ? 'text-emerald-600' : task.progress > 0 ? 'text-blue-600' : 'text-slate-400'"
                                                      x-text="task.progress + '%'"></span>
                                                <span class="text-slate-400" x-text="visibleSubtasks(task).length + '/' + task.subtasks.length"></span>
                                            </span>
                                        </button>

                                        <template x-if="expandedTasks[task.id]">
                                            <div class="divide-y divide-slate-100 bg-white">
                                                <template x-for="s in visibleSubtasks(task)" :key="s.id">
                                                    <div>
                                                        {{-- Dense Sub Task row (point 11) --}}
                                                        <div @click="toggleSubtaskDetail(s.id)"
                                                             class="group flex items-center gap-2 pl-10 pr-4 py-2.5 cursor-pointer hover:bg-slate-50 transition">
                                                            <span class="shrink-0 w-4 flex items-center justify-center">
                                                                <svg x-show="s.status === 'completed'" class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                                                <span x-show="s.status !== 'completed'" class="w-2 h-2 rounded-full"
                                                                      :class="s.is_overdue ? 'bg-red-500' : s.status === 'in_progress' ? 'bg-blue-500' : s.status === 'on_hold' ? 'bg-amber-400' : 'bg-slate-300'"></span>
                                                            </span>

                                                            <p class="flex-1 min-w-0 text-sm truncate" :class="subtaskNameClass(s)" :title="s.name" x-text="s.name"></p>

                                                            <span class="shrink-0 w-20">
                                                                <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[11px] font-medium" :class="workStatusBadgeClass(s.status)" x-text="workStatusLabel(s.status)"></span>
                                                            </span>

                                                            <span class="shrink-0 w-12 text-right text-xs text-slate-400 tabular-nums" x-text="s.checklists_completed + '/' + s.checklists_total"></span>

                                                            {{-- [จ่ายงาน]/edit only ever shows for a user who can actually
                                                                 dispatch work (same canDispatchWork() gate as
                                                                 cabinets.partials._assignment) - UI hide here is a
                                                                 convenience, SubtaskAssignmentService::changeDepartment()
                                                                 still enforces this server-side regardless. --}}
                                                            <span class="shrink-0 w-32 flex items-center justify-end" @click.stop>
                                                                <button type="button" x-show="cabinetDetail.can_dispatch_work && needsAssignment(s)" @click="openAssignModal(s)"
                                                                        class="px-2 py-1 rounded-md bg-blue-600 text-white text-[11px] font-semibold hover:bg-blue-700">จ่ายงาน</button>
                                                                <span x-show="!(cabinetDetail.can_dispatch_work && needsAssignment(s))" class="text-[11px] text-slate-500 truncate flex items-center gap-1">
                                                                    <span x-show="s.is_department_locked" aria-hidden="true">🔒</span>
                                                                    <span x-text="s.assignment_status === 'UNASSIGNED' ? '- ยังไม่จ่ายงาน -' : (s.department_name || '-') + ' · ' + s.assignment_status_label"></span>
                                                                </span>
                                                            </span>

                                                            <svg class="shrink-0 w-4 h-4 text-slate-300 transition-transform" :class="expandedSubtaskId === s.id ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                                                        </div>

                                                        {{-- Inline Sub Task detail (point 23/24) - dates, remark, full
                                                             assignment controls (server-rendered _assignment partial,
                                                             reused verbatim), and the checklist. --}}
                                                        <template x-if="expandedSubtaskId === s.id">
                                                            <div class="pl-10 pr-4 pb-3 bg-slate-50/60 space-y-3" @click.stop>
                                                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500 pt-2">
                                                                    <span>Start: <span class="font-medium text-slate-700" x-text="fmt(s.start_date)"></span></span>
                                                                    <span>Due: <span class="font-medium text-slate-700" x-text="fmt(s.due_date)"></span></span>
                                                                    <span x-show="s.owner_name">ผู้รับผิดชอบ: <span class="font-medium text-slate-700" x-text="s.owner_name"></span></span>
                                                                </div>
                                                                <p x-show="s.remark" class="text-xs text-slate-500" x-text="'หมายเหตุ: ' + s.remark"></p>

                                                                <div :data-assignment="s.id" class="flex flex-wrap items-center gap-2" x-html="s.assignment_html"></div>
                                                                <button type="button" x-show="cabinetDetail.can_dispatch_work && !s.is_department_locked && s.assignment_status !== 'UNASSIGNED'"
                                                                        @click="openAssignModal(s)" class="text-[11px] font-medium text-blue-600 hover:underline">แก้ไขการจ่ายงาน (วันที่/หมายเหตุ)</button>

                                                                <div>
                                                                    <p class="text-[11px] font-medium text-slate-500 mb-1.5">Checklist</p>
                                                                    {{-- Disabled (not just erroring on click) until the assigned
                                                                         department accepts the work - same rule/flag My Department's
                                                                         detail modal already shows (checklist_editable, from
                                                                         CabinetSubtask::isChecklistEditableBy()); the toggle endpoint
                                                                         still enforces this server-side regardless. --}}
                                                                    <p x-show="s.checklists.length > 0 && !s.checklist_editable" class="text-xs text-slate-400 mb-2">ติ๊ก Checklist ได้เมื่อแผนกรับงานแล้วและยังไม่เสร็จงาน (เฉพาะสมาชิกแผนก)</p>
                                                                    <ul class="space-y-1.5">
                                                                        <template x-for="c in s.checklists" :key="c.id">
                                                                            <li class="flex items-center gap-2 text-sm">
                                                                                <input type="checkbox" :checked="c.is_completed" :disabled="!s.checklist_editable || !!c.busy" @change="toggleChecklistItem(s, c, $event)" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 disabled:opacity-50">
                                                                                <span :class="c.is_completed ? 'text-slate-400 line-through' : 'text-slate-700'" x-text="c.name"></span>
                                                                            </li>
                                                                        </template>
                                                                        <li x-show="s.checklists.length === 0" class="text-xs text-slate-400">ยังไม่มี Checklist</li>
                                                                    </ul>
                                                                </div>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>
                                                <p x-show="visibleSubtasks(task).length === 0" class="pl-10 pr-4 py-3 text-xs text-slate-400">ไม่มีงานค้าง (เสร็จแล้วทั้งหมด)</p>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>

                        {{-- ================= ATTACHMENTS TAB ================= --}}
                        <div x-show="panelTab === 'attachments'" class="p-4 space-y-3">
                            <div class="flex items-center gap-2">
                                <input type="file" x-ref="attachmentFileInput" @change="attachmentFile = $event.target.files[0]" class="text-xs flex-1">
                                <input type="text" x-model="attachmentDescription" placeholder="คำอธิบาย (ถ้ามี)" class="rounded-lg border-slate-300 text-xs flex-1">
                                <button type="button" @click="uploadAttachment()" :disabled="!attachmentFile || attachmentUploading"
                                        class="px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 disabled:opacity-50 shrink-0">อัปโหลด</button>
                            </div>
                            <ul class="divide-y divide-slate-100 border-t border-slate-100">
                                <template x-for="a in cabinetDetail.cabinet.attachments" :key="a.id">
                                    <li class="py-2.5 flex items-center justify-between gap-3">
                                        <a :href="a.url" target="_blank" class="min-w-0">
                                            <p class="text-sm font-medium text-blue-600 truncate" x-text="a.original_name"></p>
                                            <p class="text-xs text-slate-400 truncate" x-text="a.size_for_humans + ' · ' + a.uploaded_by_name + ' · ' + a.created_at"></p>
                                        </a>
                                        <button type="button" @click="deleteAttachment(a)" class="shrink-0 text-xs text-red-500 hover:underline">ลบ</button>
                                    </li>
                                </template>
                                <li x-show="cabinetDetail.cabinet.attachments.length === 0" class="py-6 text-center text-sm text-slate-400">ยังไม่มีไฟล์แนบ</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        {{-- Assignment Modal (point 14/38) - above the panel's own z-40 --}}
        <template x-if="assignModalOpen">
            {{-- ESC is handled by handleEscape() on the panel's own listener above
                 (point 35 - closes this modal first, not both layers at once). --}}
            <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-slate-900/40" @click="closeAssignModal()"></div>
                <div class="relative bg-white rounded-xl shadow-xl w-full max-w-sm p-5" @click.stop>
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="text-sm font-semibold text-slate-900">จ่ายงาน</h4>
                        <button type="button" @click="closeAssignModal()" class="text-slate-400 hover:text-slate-600 text-xl leading-none" aria-label="ปิด">&times;</button>
                    </div>
                    <p class="text-sm font-medium text-slate-800" x-text="assignTarget?.name"></p>
                    <p class="text-xs text-slate-400 mb-4">MO <span x-text="cabinetDetail?.cabinet?.mo_no"></span></p>

                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1">แผนก *</label>
                            <select x-model="assignForm.department_id" class="w-full rounded-lg border-slate-300 text-sm">
                                <option value="">- ยังไม่จ่ายงาน -</option>
                                <template x-for="d in (cabinetDetail?.departments || [])" :key="d.id">
                                    <option :value="d.id" x-text="d.name"></option>
                                </template>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-500 mb-1">เริ่มงาน</label>
                                <input type="date" x-model="assignForm.start_date" class="w-full rounded-lg border-slate-300 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 mb-1">กำหนดเสร็จ</label>
                                <input type="date" x-model="assignForm.due_date" class="w-full rounded-lg border-slate-300 text-sm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1">หมายเหตุ</label>
                            <textarea x-model="assignForm.remark" rows="2" class="w-full rounded-lg border-slate-300 text-sm"></textarea>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 mt-5">
                        <button type="button" @click="closeAssignModal()" class="px-3 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                        <button type="button" @click="submitAssign()" :disabled="assignSaving"
                                class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 disabled:opacity-50">จ่ายงาน</button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
