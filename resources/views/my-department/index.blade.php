<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">งานแผนกของฉัน / My Department</h2>
    </x-slot>

    @if (! $department)
        <x-card>
            <p class="text-sm text-slate-600">บัญชีของคุณยังไม่ได้ผูกกับแผนก จึงยังไม่มีงานให้แสดง กรุณาติดต่อผู้ดูแลระบบเพื่อกำหนดแผนกในหน้า Users</p>
        </x-card>
    @else
    <div class="max-w-7xl mx-auto space-y-4"
         x-data="myDepartment(@js([
             'tasksUrl' => route('my-department.tasks'),
             'exportUrl' => route('my-department.export'),
             'departmentId' => $department->id,
             'departmentName' => $department->name,
             'today' => today()->format('Y-m-d'),
         ]))">

        {{-- Toolbar - one row: navigation + label on the left, department picker and
             the Month/Week/List view switch on the right. --}}
        <x-card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <button type="button" @click="prevMonth()" class="w-9 h-9 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50" aria-label="ก่อนหน้า">&lt;</button>
                    <button type="button" @click="goToday()" class="px-3 h-9 rounded-lg border border-slate-300 text-sm font-medium text-slate-700 hover:bg-slate-50">วันนี้</button>
                    <button type="button" @click="nextMonth()" class="w-9 h-9 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50" aria-label="ถัดไป">&gt;</button>
                    <h3 class="ml-2 text-lg font-semibold text-slate-900" x-text="rangeLabel()"></h3>
                    <span x-show="loading" class="text-xs text-slate-400">กำลังโหลด...</span>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    {{-- Client-side only - Calendar/List hide individual completed Sub
                         Tasks (visibleItems()/dayItems()/listItems()); Timeline still
                         aggregates into bars, so it hides a whole Project/Department group
                         only once every Sub Task inside it is done. --}}
                    <label class="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer select-none">
                        <input type="checkbox" x-model="hideCompleted" @change="toggleHideCompleted()" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        ซ่อนงานที่เสร็จแล้ว
                    </label>
                    @if ($canViewOthers)
                        {{-- "ทุกแผนก" (point 1/27, PMO/Admin only - resolveDepartment() enforces
                             this server-side too, this isn't just a hidden option). Not
                             x-model.number anymore since 'all' is a string, not a number. --}}
                        <select x-model="departmentId" @change="changeDepartment()" class="rounded-lg border-slate-300 text-sm" aria-label="Department">
                            <option value="all">ทุกแผนก (PMO)</option>
                            @foreach ($departments as $dept)
                                <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <span class="text-sm text-slate-500">แผนก <span class="font-semibold text-slate-800">{{ $department->name }}</span></span>
                    @endif
                    {{-- Project/Status filters (point 5) - narrow within whatever Department
                         scope already applies (single department, or ทุกแผนก); kept in the
                         same Alpine state as departmentId, so Timeline/List read the same
                         filters already baked into this.items, with no per-view wiring. --}}
                    <select x-model="projectId" @change="changeProjectFilter()" class="rounded-lg border-slate-300 text-sm" aria-label="Project">
                        <option value="">ทุก Project</option>
                        @foreach ($projects as $p)
                            <option value="{{ $p->id }}">{{ $p->project_no }}</option>
                        @endforeach
                    </select>
                    <select x-model="statusFilter" @change="changeStatusFilter()" class="rounded-lg border-slate-300 text-sm" aria-label="Status">
                        <option value="">ทุกสถานะ</option>
                        <option value="ASSIGNED">รอรับงาน</option>
                        <option value="ACCEPTED">รับงานแล้ว</option>
                        <option value="IN_PROGRESS">กำลังทำ</option>
                        <option value="COMPLETED">เสร็จแล้ว</option>
                        <option value="OVERDUE">เกินกำหนด</option>
                    </select>
                    <button type="button" @click="exportCsv()" class="px-3 py-1.5 rounded-lg border border-slate-300 bg-white text-sm font-medium text-slate-600 hover:bg-slate-50">Export CSV</button>
                    <div x-show="mainView === 'calendar'" class="inline-flex rounded-lg border border-slate-300 overflow-hidden text-sm font-medium">
                        <button type="button" @click="setCalendarRange('month')" :class="view === 'calendar' && range === 'month' ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-3 py-1.5">เดือน</button>
                        <button type="button" @click="setCalendarRange('7')" :class="view === 'calendar' && range === '7' ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-3 py-1.5 border-l border-slate-300">สัปดาห์</button>
                        <button type="button" @click="view = 'list'" :class="view === 'list' ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-3 py-1.5 border-l border-slate-300">รายการ</button>
                    </div>
                    {{-- Top-level page switch: ปฏิทิน = the whole block above unchanged
                         (with its own Month/Week/List), Timeline = new Gantt-style view,
                         same items()/department scope and date window (range/anchor). --}}
                    <div class="inline-flex rounded-lg border border-slate-300 overflow-hidden text-sm font-medium">
                        <button type="button" @click="mainView = 'calendar'" :class="mainView === 'calendar' ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-3 py-1.5">ปฏิทิน</button>
                        <button type="button" @click="mainView = 'timeline'; $nextTick(() => requestAnimationFrame(() => scrollTimelineToToday()))" :class="mainView === 'timeline' ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-3 py-1.5 border-l border-slate-300">Timeline</button>
                    </div>
                </div>
            </div>
        </x-card>

        {{-- Calendar - full width; clicking a date opens the Day Detail Panel
             (slide-out, see openDayPanel() in _script.blade.php) instead of an
             inline card next to the grid. --}}
        <div x-show="mainView === 'calendar' && view === 'calendar'">
            <x-card class="!p-0 overflow-hidden">
                <div class="grid grid-cols-7 border-b border-slate-200 bg-slate-50 text-center text-xs font-medium text-slate-500">
                    <template x-for="w in weekdayHeaders()"><div class="py-3" x-text="w"></div></template>
                </div>

                {{-- One block per week: a row of clickable date numbers, then a bars
                     area where a multi-day item is drawn as a single continuous bar
                     spanning its columns (instead of a chip repeated in every day it
                     touches). Desktop only - mobile keeps the per-day count badge. --}}
                <template x-for="week in weekRows()" :key="week[0].date">
                    <div class="border-b border-slate-100">
                        <div class="grid grid-cols-7">
                            <template x-for="d in week" :key="d.date">
                                <button type="button" @click="selectDate(d.date)"
                                        :class="d.inRange ? 'bg-white' : 'bg-slate-50 text-slate-400'"
                                        class="relative border-r border-slate-100 last:border-r-0 p-2 md:p-2.5 pb-1 md:pb-1 text-left hover:bg-blue-50/40 focus:outline-none flex items-center justify-between gap-1">
                                    {{-- Selected date: a ring just on the number + a soft tint on the cell -
                                         not a border around the whole cell, so the grid stays visually clean. --}}
                                    <span class="absolute inset-0 pointer-events-none" :class="selectedDate === d.date ? 'bg-blue-50/70' : ''"></span>
                                    <span :class="[
                                              d.date === today ? 'bg-blue-600 text-white font-semibold' : 'text-slate-700',
                                              selectedDate === d.date && d.date !== today ? 'ring-2 ring-blue-500' : '',
                                          ]" class="relative inline-flex items-center justify-center w-7 h-7 md:w-8 md:h-8 rounded-full text-xs md:text-sm shrink-0" x-text="d.day"></span>
                                    <span x-show="overdueCount(d.date) > 0" class="relative hidden md:inline text-[10px] font-semibold text-red-700 bg-red-100 rounded px-1" x-text="overdueCount(d.date)" title="เกินกำหนด"></span>
                                    {{-- Mobile: just a count of Sub Tasks active that day, no cards --}}
                                    <span x-show="dayItems(d.date).length > 0" :class="overdueCount(d.date) > 0 ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700'" class="relative md:hidden rounded-full px-1.5 text-[10px] font-semibold" x-text="dayItems(d.date).length"></span>
                                </button>
                            </template>
                        </div>
                        {{-- One continuous bar per Sub Task/Assignment (point 6/9/32) -
                             Department + Sub Task name, spanning every day it's active
                             (weekItemBars()) instead of repeating a chip in each day cell.
                             Up to 2 lanes shown per week, "+N งาน" per day opens the Day
                             Detail Panel (selectDate()) for the rest, same as clicking the
                             date number. --}}
                        <div class="hidden md:grid grid-cols-7 gap-1 px-0.5 pb-1.5" style="grid-auto-rows: 24px;">
                            <template x-for="b in weekVisibleBars(week)" :key="week[0].date + '-bar-' + b.item.id">
                                <div @click.stop="openDetail(b.item)"
                                     :style="'grid-column:' + b.colStart + ' / span ' + b.colSpan + '; grid-row:' + (b.lane + 1) + ';'"
                                     :class="[departmentColor(b.item.department_name).bar, b.item.is_overdue ? 'ring-1 ring-red-400' : '']"
                                     :title="b.item.department_name + ' · ' + b.item.name + ' · ' + b.item.cabinet_mo + ' · ' + b.item.checklists_completed + '/' + b.item.checklists_total"
                                     class="flex items-center gap-1 rounded-md px-1.5 text-[10px] leading-tight cursor-pointer hover:brightness-95 hover:shadow-sm transition overflow-hidden">
                                    <span x-show="b.continuesBefore" class="shrink-0">◄</span>
                                    <span x-show="b.item.assignment_status === 'COMPLETED'" class="shrink-0" title="เสร็จแล้ว">✓</span>
                                    <span x-show="b.item.is_overdue" class="shrink-0 font-bold" title="เกินกำหนด">!</span>
                                    <span class="truncate font-semibold" x-text="b.item.department_name"></span>
                                    <span class="truncate opacity-75" x-text="'· ' + b.item.name"></span>
                                    <span x-show="b.continuesAfter" class="shrink-0 ml-auto">►</span>
                                </div>
                            </template>
                            <template x-for="(d, idx) in week" :key="d.date + '-overflow'">
                                <p x-show="dayOverflowCount(week, d.date) > 0"
                                   @click.stop="selectDate(d.date)"
                                   :style="'grid-column:' + (idx + 1) + '; grid-row: 3;'"
                                   class="self-start text-[10px] font-medium text-blue-600 hover:underline cursor-pointer px-1"
                                   x-text="'+' + dayOverflowCount(week, d.date) + ' งาน'"></p>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Unscheduled work (point 29) - each Sub Task shown individually, never
                     grouped/counted by Project, and never dropped just because it has no
                     Start/Due Date to place on the grid. --}}
                <div x-show="unscheduledItems().length > 0" class="border-t border-slate-100 bg-amber-50/60 text-xs text-amber-800 divide-y divide-amber-100/70">
                    <template x-for="i in unscheduledItems()" :key="'unscheduled-' + i.id">
                        <button type="button" @click="openDetail(i)" class="w-full flex items-center justify-between gap-3 px-4 py-2 text-left hover:bg-amber-100/50">
                            <span>⚠ <span x-text="i.project_no"></span> · <span x-text="i.department_name"></span> · <span x-text="i.name"></span></span>
                            <span class="font-semibold text-amber-900 shrink-0">ดู →</span>
                        </button>
                    </template>
                </div>

                {{-- Color legend: Department colors (point 8/32) - which Departments are
                     currently on the Calendar, each with one color everywhere it appears. --}}
                <div x-show="departmentLegendItems().length > 0" class="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-4 py-2.5 border-t border-slate-100">
                    <template x-for="l in departmentLegendItems()" :key="l.name">
                        <span class="inline-flex items-center gap-1.5 text-xs text-slate-600">
                            <span :class="l.color.dot" :style="l.color.dotStyle" class="w-2.5 h-2.5 rounded-full shrink-0"></span>
                            <span x-text="l.name"></span>
                        </span>
                    </template>
                </div>
            </x-card>
        </div>

        {{-- List - one row per Sub Task/Assignment (point 25/41), same granularity as
             the Calendar now, not an aggregated Project row. Grouped under a Department
             header once "ทุกแผนก" can mix multiple Departments together; a single-
             department view shows no header (one implicit section, see listSections()).
             Click a row to open the Right Side Work Detail Panel. --}}
        <div x-show="mainView === 'calendar' && view === 'list'">
            <x-card class="!p-0 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">Project</th>
                            <th class="px-4 py-2 text-left font-medium">Cabinet</th>
                            <th class="px-4 py-2 text-left font-medium">งาน (Sub Task)</th>
                            <th class="px-4 py-2 text-left font-medium">กำหนดส่ง</th>
                            <th class="px-4 py-2 text-left font-medium">สถานะ</th>
                            <th class="px-4 py-2 text-left font-medium">Checklist</th>
                        </tr>
                    </thead>
                    <template x-for="section in listSections()" :key="'sec-' + (section.department_id ?? 'x')">
                        <tbody class="divide-y divide-slate-100">
                            <tr x-show="section.department_id !== null" class="bg-slate-50/60">
                                <td colspan="6" class="px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                    <span x-text="section.department_name"></span>
                                    <span class="text-slate-400 font-normal normal-case ml-1" x-text="'· ' + section.rows.length + ' งาน'"></span>
                                </td>
                            </tr>
                            <template x-for="i in section.rows" :key="'li' + i.id + '-' + (section.department_id ?? 0)">
                                <tr class="hover:bg-blue-50 cursor-pointer" @click="openDetail(i)">
                                    <td class="px-4 py-2">
                                        <p class="font-medium text-blue-600" x-text="i.project_no"></p>
                                        <p class="text-xs text-slate-500 truncate max-w-xs" x-text="i.project_name"></p>
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap text-slate-600" x-text="i.cabinet_mo + ' · ' + i.cabinet_name"></td>
                                    <td class="px-4 py-2" :class="subtaskNameClass(i)" x-text="i.name"></td>
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        <span x-text="fmt(i.due_date)"></span>
                                        <span x-show="i.is_overdue" class="ml-1 text-[11px] font-semibold text-red-600">เกินกำหนด</span>
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        <span :class="badgeClass(i)" class="rounded-full px-2 py-0.5 text-xs font-medium" x-text="shortStatusLabel(i)"></span>
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        <div class="flex items-center gap-2 w-24">
                                            <div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden">
                                                <div class="h-1.5 rounded-full bg-emerald-500" :style="'width:' + i.progress + '%'"></div>
                                            </div>
                                            <span class="text-xs text-slate-500 tabular-nums shrink-0" x-text="i.checklists_completed + '/' + i.checklists_total"></span>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </template>
                    <tbody x-show="listItems().length === 0">
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">ไม่มีงานตามเงื่อนไขที่เลือก</td></tr>
                    </tbody>
                </table>
                <p class="px-4 py-2 text-xs text-slate-400 border-t border-slate-100">แสดงงานในช่วงเวลาที่เลือก รวมงานที่เกินกำหนดและงานที่ยังไม่ระบุวัน</p>
            </x-card>
        </div>

        {{-- Timeline (Gantt-style) - same items()/department scope as Calendar (no new
             fetch, no new endpoint), using the same date window (range/anchor) as
             Calendar. One row per Project normally; under "ทุกแผนก" (point 41) one row
             per Department instead - clicking a Department bar drills into it
             (selectDepartment()), which switches Timeline right back to per-Project
             rows for that one Department. Click a Project row to open the Right
             Detail Panel (Project -> Cabinet -> Sub Task). --}}
        <div x-show="mainView === 'timeline'">
            <x-card class="!p-0 overflow-hidden">
                {{-- Search - client-side only, filters the rows already loaded (this.items);
                     never re-fetches or widens the department scope. A match on a Cabinet/
                     Sub Task/Project inside a row keeps that whole row visible. --}}
                <div class="flex items-center gap-2 px-3 py-2 border-b border-slate-200">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 text-slate-400 shrink-0">
                        <circle cx="11" cy="11" r="7"></circle>
                        <path stroke-linecap="round" d="M21 21l-4.3-4.3"></path>
                    </svg>
                    <input type="text" x-model="timelineSearch" placeholder="ค้นหาโครงการ, ลูกค้า, ตู้ (เลข MO/ชื่อ), หรือชื่องาน..."
                           class="flex-1 border-0 p-0 text-sm text-slate-700 placeholder:text-slate-400 focus:ring-0">
                    <button type="button" x-show="timelineSearch" @click="timelineSearch = ''" class="text-slate-400 hover:text-slate-600 text-xs shrink-0">ล้าง ✕</button>
                </div>

                <div class="overflow-x-auto" x-ref="timelineScroll">
                    <div class="min-w-max relative">
                        {{-- "Where are we" marker: a line through every row at today's column,
                             not just the header cell - stays useful once there are many rows. --}}
                        <div x-show="todayLineLeft() !== null" class="absolute top-0 bottom-0 w-px bg-blue-400 z-[5] pointer-events-none" :style="'left:' + todayLineLeft() + 'px'"></div>

                        <div class="grid grid-cols-[200px_1fr] border-b border-slate-200 bg-slate-50">
                            <div class="sticky left-0 z-10 bg-slate-50 px-3 py-2 border-r border-slate-200 text-xs font-medium text-slate-500" x-text="isAllDepartments ? 'แผนก' : 'Project'"></div>
                            <div class="grid" :style="'grid-template-columns:' + timelineColTemplate()">
                                <template x-for="d in timelineDays()" :key="d.date">
                                    <div class="text-center py-2 text-xs font-medium border-l border-slate-100" :class="d.date === today ? 'bg-blue-50 text-blue-700 font-semibold' : 'text-slate-500'" x-text="d.day"></div>
                                </template>
                            </div>
                        </div>

                        <p x-show="timelineRows().length === 0" class="text-sm text-slate-400 py-16 text-center" x-text="timelineSearch.trim() ? 'ไม่พบรายการที่ตรงกับ &quot;' + timelineSearch + '&quot;' : 'ไม่มีงานในช่วงเวลานี้'"></p>

                        <template x-for="row in timelineRows()" :key="row.group.kind === 'department' ? 'd' + row.group.department_id : 'p' + row.group.project_id">
                            <div class="grid grid-cols-[200px_1fr] border-b border-slate-100 hover:bg-slate-50/50">
                                <div class="sticky left-0 z-10 bg-white px-3 py-2 border-r border-slate-200 text-xs text-slate-700 min-w-0" :title="rowTitle(row.group)">
                                    <template x-if="row.group.kind === 'department'">
                                        <div>
                                            <p class="font-semibold truncate" x-text="row.group.department_name"></p>
                                            <p class="truncate text-slate-500" x-text="row.group.project_count + ' Project'"></p>
                                        </div>
                                    </template>
                                    <template x-if="row.group.kind !== 'department'">
                                        <div>
                                            <p class="font-semibold truncate" x-text="row.group.project_no"></p>
                                            <p class="truncate text-slate-500" x-text="row.group.project_name"></p>
                                        </div>
                                    </template>
                                </div>
                                <div class="grid py-1.5" :style="'grid-template-columns:' + timelineColTemplate()">
                                    <div @click="row.group.kind === 'department' ? selectDepartment(row.group.department_id) : openProjectPanel(row.group)"
                                         :class="[rowColor(row.group).bar, row.group.overdue > 0 ? 'ring-2 ring-red-400' : '']"
                                         :style="'grid-column:' + row.colStart + ' / span ' + row.colSpan + ';' + rowColor(row.group).style"
                                         :title="rowTitle(row.group)"
                                         class="flex items-center gap-1.5 rounded-md px-2 h-9 text-xs truncate cursor-pointer hover:brightness-95 hover:shadow-sm transition">
                                        <span x-show="row.continuesBefore" class="shrink-0">◄</span>
                                        <span x-show="row.group.progress === 100" class="shrink-0">✓</span>
                                        <span x-show="isHigh(row.group)" class="font-bold text-red-600 shrink-0">!</span>
                                        <span class="truncate font-medium" x-text="row.group.kind === 'department' ? row.group.department_name : row.group.project_no"></span>
                                        {{-- Point 2: both count and status breakdown, shown together (more room here than the Calendar cell) --}}
                                        <span class="truncate opacity-75" x-text="rowSummaryText(row.group)"></span>
                                        <span x-show="row.continuesAfter" class="shrink-0 ml-auto">►</span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="timelineOffWindowCount() > 0" class="flex items-center justify-between gap-3 px-4 py-2 border-t border-slate-100 bg-amber-50/60 text-xs text-amber-800">
                    <span>⚠ <span x-text="timelineOffWindowCount()"></span> <span x-text="isAllDepartments ? 'แผนก' : 'Project'"></span> อยู่นอกช่วงเวลานี้ หรือยังไม่ระบุวันที่</span>
                    <button type="button" @click="mainView = 'calendar'; view = 'list'" class="font-semibold text-amber-900 hover:underline shrink-0">ดูรายการ</button>
                </div>

                <div x-show="(isAllDepartments ? departmentLegendItems() : legendItems()).length > 0" class="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-4 py-2.5 border-t border-slate-100">
                    <template x-for="l in (isAllDepartments ? departmentLegendItems() : legendItems())" :key="l.name">
                        <span class="inline-flex items-center gap-1.5 text-xs text-slate-600">
                            <span :class="l.color.dot" :style="l.color.dotStyle" class="w-2.5 h-2.5 rounded-full shrink-0"></span>
                            <span x-text="l.name"></span>
                        </span>
                    </template>
                </div>
            </x-card>
        </div>

        @include('my-department.partials._detail-panel')

        @include('my-department.partials._day-panel')
        @include('my-department.partials._project-panel')
    </div>
    @endif

    @push('scripts')
    @include('my-department.partials._script')
    @endpush
</x-app-layout>
