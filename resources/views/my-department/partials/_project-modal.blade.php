{{--
    Wide "โครงการ" modal (Avatar Planning's task-modal): opened from a calendar / timeline bar or the left panel.
    Left: the Project itself - name, status, priority, dates, "ส่งต่อแผนก" chips, cabinets with their Sub Tasks.
    Right: Activity (comments + system log) with a compose box. Everything the user edits is autosaved (PMO roles only;
    everyone else gets the same layout read-only). Sub Task rows keep using the existing accept/start/complete endpoints,
    and open the existing detail panel (z-50, above this z-40) for checklists.
--}}
<template x-if="pmOpen">
    <div id="project-modal" class="fixed inset-0 z-40 flex items-start sm:items-center justify-center p-2 sm:p-6" role="dialog" aria-modal="true"
         @keydown.escape.window="if (!detailPanelOpen) closeProjectModal()">
        <div class="absolute inset-0 bg-slate-900/40" @click="closeProjectModal()"></div>

        <div class="relative flex w-full max-w-[1000px] max-h-[calc(100vh-1rem)] sm:max-h-[calc(100vh-3rem)] flex-col rounded-2xl bg-white shadow-2xl border border-slate-200" @click.stop>
            {{-- header --}}
            <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3.5 shrink-0">
                <h3 class="text-base font-semibold text-slate-900">โครงการ</h3>
                <div class="flex items-center gap-2">
                    <a x-show="pm" :href="pm && pm.project.urls.show" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20V10m6 10V4m6 16v-7m4 7H2"/></svg>เปิดหน้าโครงการ
                    </a>
                    <button type="button" @click="closeProjectModal()" class="text-2xl leading-none text-slate-400 hover:text-slate-600" aria-label="ปิด">&times;</button>
                </div>
            </div>

            <p x-show="pmLoading" class="py-24 text-center text-sm text-slate-400">กำลังโหลด...</p>

            <template x-if="pm">
                <div class="flex min-h-0 flex-1 flex-col md:flex-row">
                    {{-- ============ left: the Project ============ --}}
                    <div class="min-h-0 flex-1 overflow-y-auto p-5 space-y-5">
                        <div class="flex gap-3">
                            <div class="w-32 shrink-0 rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700" x-text="pm.project.project_no" title="เลขที่โครงการ"></div>
                            <template x-if="pm.project.can_edit">
                                <input type="text" x-model="pm.project.project_name" @input.debounce.700ms="pm.project.project_name.trim() && saveProject('project_name', pm.project.project_name.trim())"
                                       maxlength="255" class="min-w-0 flex-1 rounded-lg border-slate-300 text-lg font-semibold text-slate-900" aria-label="ชื่อโครงการ">
                            </template>
                            <template x-if="!pm.project.can_edit">
                                <div class="min-w-0 flex-1 truncate rounded-lg border border-slate-200 px-3 py-2 text-lg font-semibold text-slate-900" x-text="pm.project.project_name"></div>
                            </template>
                        </div>
                        <p class="-mt-3 text-xs text-slate-500" x-text="pm.project.customer_name + (pm.project.manager_name ? ' · PM: ' + pm.project.manager_name : '')"></p>
                        <p x-show="pm.project.locked" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">โครงการนี้ปิดแล้ว (Completed/Cancelled) จึงแก้ไขงานย่อยไม่ได้ — เปลี่ยนสถานะกลับก่อนหากต้องการแก้ไข</p>

                        {{-- meta: status / priority / dates / who --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 divide-y sm:divide-y-0 sm:divide-x divide-slate-200 rounded-xl border border-slate-200">
                            <div class="p-3">
                                <p class="text-[11px] font-medium text-slate-500 mb-1.5">สถานะ</p>
                                <select x-model="pm.project.status" @change="saveProject('status', pm.project.status)" :disabled="!pm.project.can_edit" class="w-full rounded-lg border-slate-300 text-sm font-semibold disabled:bg-slate-50">
                                    <template x-for="(label, key) in pm.project.statuses" :key="key"><option :value="key" x-text="label" :selected="key === pm.project.status"></option></template>
                                </select>
                            </div>
                            <div class="p-3">
                                <p class="text-[11px] font-medium text-slate-500 mb-1.5">ความสำคัญ</p>
                                <div class="flex items-center gap-2">
                                    <svg x-show="flagClass(pm.project)" :class="flagClass(pm.project)" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3v18h2v-7h11l-2-4 2-4H7V3H5z"/></svg>
                                    <select x-model="pm.project.priority" @change="saveProject('priority', pm.project.priority)" :disabled="!pm.project.can_edit" class="w-full rounded-lg border-slate-300 text-sm disabled:bg-slate-50">
                                        <template x-for="(label, key) in pm.project.priorities" :key="key"><option :value="key" x-text="label" :selected="key === pm.project.priority"></option></template>
                                    </select>
                                </div>
                            </div>
                            <div class="p-3 sm:border-t sm:border-slate-200 sm:!border-l-0">
                                <p class="text-[11px] font-medium text-slate-500 mb-1.5">กำหนดเวลา</p>
                                <template x-if="pm.project.can_edit">
                                    <div class="flex items-center gap-1.5 text-xs text-slate-500">
                                        <span>เริ่ม</span><input type="text" x-init="initDate($el, 'start_date')" placeholder="dd/mm/yyyy" class="w-[6.75rem] rounded-lg border-slate-300 px-2 py-1 text-sm">
                                        <span>→ จบ</span><input type="text" x-init="initDate($el, 'due_date')" placeholder="dd/mm/yyyy" class="w-[6.75rem] rounded-lg border-slate-300 px-2 py-1 text-sm">
                                    </div>
                                </template>
                                <p x-show="!pm.project.can_edit" class="text-sm text-slate-700" x-text="'เริ่ม ' + fmt(pm.project.start_date) + ' → จบ ' + fmt(pm.project.due_date)"></p>
                            </div>
                            <div class="p-3 sm:border-t sm:border-slate-200">
                                <p class="text-[11px] font-medium text-slate-500 mb-1.5">ผู้รับผิดชอบ (PM)</p>
                                <p class="text-sm text-slate-700" x-text="pm.project.manager_name || 'ยังไม่ระบุ'"></p>
                            </div>
                        </div>

                        {{-- ส่งต่อแผนก --}}
                        <div>
                            <p class="mb-1.5 text-xs font-semibold text-slate-600">ส่งต่อแผนก</p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="d in pm.departments" :key="d.id">
                                    <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm" :title="d.done + '/' + d.total + ' งานเสร็จ'">
                                        <span class="font-medium text-slate-800" x-text="d.name"></span>
                                        <span x-show="d.due_date" class="text-xs font-semibold" :class="d.overdue > 0 ? 'text-red-600' : (d.due_date === today ? 'text-amber-600' : 'text-slate-500')" x-text="dueLabel(d.due_date)"></span>
                                        <span class="rounded-full bg-slate-100 px-1.5 text-[11px] font-semibold text-slate-600 tabular-nums" x-text="d.pct + '%'"></span>
                                    </span>
                                </template>
                                <p x-show="pm.departments.length === 0" class="text-sm text-slate-400">ยังไม่ได้จ่ายงานให้แผนก</p>
                            </div>
                        </div>

                        {{-- ตู้ --}}
                        <div>
                            <p class="mb-1.5 text-xs font-semibold text-slate-600">ตู้ <span class="text-slate-400 font-normal" x-text="'(' + pm.cabinets.length + ')'"></span></p>
                            {{-- same look as the original Planning page: borderless rows, ringed status dot, checklist pill, date, progress bar under the name --}}
                            <div class="flex flex-col gap-1">
                                <template x-for="c in pm.cabinets" :key="c.id">
                                    <div class="rounded-md hover:bg-slate-50/70">
                                        <button type="button" @click="toggleCabinet(c.id)" class="w-full px-1 py-1.5 text-left" :aria-expanded="!!pmExpanded[c.id]">
                                            <div class="flex items-center gap-2">
                                                <span class="h-[18px] w-[18px] shrink-0 rounded-full border-2 border-white" :style="'background-color:' + statusColor(cabinetStatusKey(c)) + ';box-shadow:0 0 0 1.5px ' + statusColor(cabinetStatusKey(c))" :title="statusLabel(cabinetStatusKey(c))"></span>
                                                <span class="min-w-[44px] flex-1 truncate text-[13px]" :class="cabinetStatusKey(c) === 'completed' ? 'line-through text-slate-400' : 'text-slate-500'" x-text="c.mo"></span>
                                                <span class="flex-[4] truncate text-[13px]" :class="cabinetStatusKey(c) === 'completed' ? 'line-through text-slate-400' : 'text-slate-800'" x-text="c.name"></span>
                                                <span class="inline-flex shrink-0 items-center gap-1 rounded-xl border bg-white px-2.5 py-[3px] text-[11px] font-semibold"
                                                      :class="c.checklists_total > 0 && c.checklists_completed === c.checklists_total ? 'border-emerald-500 text-emerald-600' : 'border-slate-300 text-slate-500'">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 12l3 3 5-6"/></svg><span class="tabular-nums" x-text="c.checklists_completed + '/' + c.checklists_total"></span>
                                                </span>
                                                <span class="w-[88px] shrink-0 rounded-lg border border-slate-200 px-2 py-1 text-[13px] text-slate-500 tabular-nums" x-text="c.due_date ? fmtBE(c.due_date) : 'dd/mm/yyyy'"></span>
                                                <span class="text-slate-400 transition-transform" :class="pmExpanded[c.id] ? 'rotate-90' : ''">&rsaquo;</span>
                                            </div>
                                            <div class="mt-1 ml-[26px] mr-0.5 h-[5px] overflow-hidden rounded-[3px] bg-slate-100"><div class="h-full rounded-[3px] transition-all" :class="c.pct === 100 ? 'bg-emerald-500' : 'bg-blue-600'" :style="'width:' + c.pct + '%'"></div></div>
                                        </button>
                                        <ul x-show="pmExpanded[c.id]" x-cloak class="ml-[26px] mb-1 rounded-lg bg-slate-50/70 divide-y divide-slate-100">
                                            <template x-for="i in c.items" :key="i.id">
                                                <li class="flex flex-wrap items-center gap-2 px-3 py-2 pl-9">
                                                    <span class="w-2.5 h-2.5 shrink-0 rounded-full" :style="'background-color:' + statusColor(itemStatusKey(i))"></span>
                                                    <button type="button" @click="openDetail(i)" class="min-w-0 flex-1 truncate text-left text-sm text-slate-700 hover:text-blue-600 hover:underline" x-text="i.name"></button>
                                                    <span class="text-xs text-slate-500" x-text="i.department_name"></span>
                                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :style="'background-color:' + statusColor(itemStatusKey(i)) + '26;color:' + statusColor(itemStatusKey(i))" x-text="i.is_overdue ? 'เกินกำหนด' : i.assignment_status_label"></span>
                                                    <span x-show="i.checklists_total > 0" class="rounded bg-slate-200/70 px-1 text-[10px] font-semibold text-slate-600 tabular-nums" x-text="i.checklists_completed + '/' + i.checklists_total"></span>
                                                    <span class="w-10 text-right text-[11px] text-slate-400 tabular-nums" x-text="dueLabel(i.due_date)"></span>
                                                    <button type="button" x-show="i.can_accept" @click="act(i, 'accept')" :disabled="!!i.busy" class="rounded-md bg-blue-600 px-2 py-0.5 text-[11px] font-semibold text-white hover:bg-blue-700 disabled:opacity-50">รับงาน</button>
                                                    <button type="button" x-show="i.can_start" @click="act(i, 'start')" :disabled="!!i.busy" class="rounded-md bg-blue-600 px-2 py-0.5 text-[11px] font-semibold text-white hover:bg-blue-700 disabled:opacity-50">เริ่มงาน</button>
                                                    <button type="button" x-show="i.can_complete" @click="act(i, 'complete')" :disabled="!!i.busy" class="rounded-md bg-emerald-600 px-2 py-0.5 text-[11px] font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">เสร็จงาน</button>
                                                </li>
                                            </template>
                                        </ul>
                                    </div>
                                </template>
                                <p x-show="pm.cabinets.length === 0" class="px-3 py-6 text-center text-sm text-slate-400">ยังไม่มีตู้ที่จ่ายงานให้แผนก</p>
                            </div>
                        </div>

                        {{-- รายละเอียด --}}
                        <details class="rounded-xl border border-slate-200" :open="!!pm.project.description">
                            <summary class="cursor-pointer select-none px-3 py-2 text-sm font-medium text-slate-600">รายละเอียด</summary>
                            <div class="px-3 pb-3">
                                <textarea x-model="pm.project.description" @input.debounce.800ms="saveProject('description', pm.project.description || null)" :readonly="!pm.project.can_edit"
                                          rows="4" maxlength="5000" placeholder="หมายเหตุ / ขอบเขตงาน..." class="w-full rounded-lg border-slate-300 text-sm read-only:bg-slate-50"></textarea>
                            </div>
                        </details>
                    </div>

                    {{-- ============ right: Activity ============ --}}
                    <aside class="flex min-h-[18rem] md:min-h-0 md:w-80 shrink-0 flex-col border-t md:border-t-0 md:border-l border-slate-200 bg-slate-50/50">
                        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                            <span class="text-sm font-semibold text-slate-700">Activity</span>
                            <span class="text-[11px] text-slate-400" x-text="pm.activity.length + ' รายการ'"></span>
                        </div>
                        <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3 space-y-3">
                            <p x-show="pm.activity.length === 0" class="py-8 text-center text-xs text-slate-400">ยังไม่มีความเคลื่อนไหว<br>เขียนอัพเดตหรือความคิดเห็นได้ด้านล่าง</p>
                            <template x-for="a in pm.activity" :key="a.id">
                                <div class="text-sm" :class="a.is_note ? '' : 'text-slate-500'">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="truncate text-xs font-semibold" :class="a.is_note ? 'text-slate-800' : 'text-slate-500'" x-text="a.user_name"></span>
                                        <span class="shrink-0 text-[10px] text-slate-400" x-text="a.created_at"></span>
                                    </div>
                                    <p class="mt-0.5 whitespace-pre-line break-words" :class="a.is_note ? 'rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-slate-800' : 'text-xs'" x-text="a.description"></p>
                                    <div x-show="a.attachments.length" class="mt-1 flex flex-wrap gap-1">
                                        <template x-for="f in a.attachments" :key="f.url">
                                            <a :href="f.url" target="_blank" rel="noopener" class="inline-flex max-w-full items-center gap-1 rounded-md border border-slate-200 bg-white px-1.5 py-0.5 text-[11px] text-blue-600 hover:underline" :title="f.size">
                                                <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8l-8.5 8.5a5 5 0 01-7-7l9-9a3.3 3.3 0 014.7 4.7l-9 9a1.7 1.7 0 01-2.4-2.4l8.3-8.3"/></svg><span class="truncate" x-text="f.name"></span>
                                            </a>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div class="border-t border-slate-200 p-3 space-y-2">
                            <textarea x-model="pmComment" rows="2" maxlength="2000" placeholder="เขียนอัพเดต / ความคิดเห็น...  (Enter ส่ง, Shift+Enter ขึ้นบรรทัด)"
                                      @keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); postComment(); }"
                                      class="w-full rounded-lg border-slate-300 text-sm"></textarea>
                            <p x-show="pmFiles.length" class="truncate text-[11px] text-slate-500" x-text="'แนบ ' + pmFiles.length + ' ไฟล์: ' + pmFiles.map((f) => f.name).join(', ')"></p>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-500 hover:bg-slate-50" title="แนบไฟล์ (PDF, Word, Excel, รูป, DWG)">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8l-8.5 8.5a5 5 0 01-7-7l9-9a3.3 3.3 0 014.7 4.7l-9 9a1.7 1.7 0 01-2.4-2.4l8.3-8.3"/></svg>
                                    <input type="file" multiple x-ref="pmFileInput" @change="pickFiles($event)" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.dwg,.dxf" class="hidden">
                                </label>
                                <select x-show="pm.alert_departments.length" x-model="pmAlertDept" class="min-w-0 flex-1 rounded-lg border-slate-300 py-1 text-xs" title="ส่งเป็นการแจ้งเตือนไปแผนก (เด้ง popup)">
                                    <option value="">ไม่แจ้งเตือน</option>
                                    <template x-for="d in pm.alert_departments" :key="d.id"><option :value="d.id" x-text="'แจ้งแผนก ' + d.name"></option></template>
                                </select>
                                <span x-show="!pm.alert_departments.length" class="flex-1"></span>
                                <button type="button" @click="postComment()" :disabled="pmPosting || !pmComment.trim()" class="h-8 rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">ส่ง</button>
                            </div>
                        </div>
                    </aside>
                </div>
            </template>

            {{-- footer --}}
            <div class="flex shrink-0 items-center gap-3 border-t border-slate-100 px-5 py-3">
                <button type="button" x-show="pm && pm.project.can_edit" @click="deleteProject()" class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">ลบโครงการ</button>
                <div class="flex-1"></div>
                <span x-show="pm && pm.project.can_edit" class="text-xs" :class="pmError ? 'text-red-600' : 'text-slate-400'"
                      x-text="pmSaving ? 'กำลังบันทึก...' : (pmError ? 'บันทึกไม่สำเร็จ' : (pmSavedAt ? '✓ บันทึกอัตโนมัติ ' + pmSavedAt : '✓ บันทึกอัตโนมัติ'))"></span>
                <button type="button" @click="closeProjectModal()" class="rounded-lg border border-slate-300 px-4 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">ปิด</button>
            </div>
        </div>
    </div>
</template>
