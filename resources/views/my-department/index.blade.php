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
                    {{-- Client-side only - shared by Calendar/List/Timeline, never re-fetches.
                         rebuild() refreshes Calendar's precomputed dayMap/weekBarsMap; List
                         and Timeline recompute on their own since they read it live. --}}
                    <label class="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer select-none">
                        <input type="checkbox" x-model="hideCompleted" @change="toggleHideCompleted()" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        ซ่อนงานที่เสร็จแล้ว
                    </label>
                    @if ($canViewOthers)
                        <select x-model.number="departmentId" @change="changeDepartment()" class="rounded-lg border-slate-300 text-sm" aria-label="Department">
                            @foreach ($departments as $dept)
                                <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <span class="text-sm text-slate-500">แผนก <span class="font-semibold text-slate-800">{{ $department->name }}</span></span>
                    @endif
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
                                    {{-- Mobile: just a count of Projects active that day, no bars --}}
                                    <span x-show="dayProjectGroups(d.date).length > 0" :class="overdueCount(d.date) > 0 ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700'" class="relative md:hidden rounded-full px-1.5 text-[10px] font-semibold" x-text="dayProjectGroups(d.date).length"></span>
                                </button>
                            </template>
                        </div>
                        <div class="hidden md:grid grid-cols-7 gap-y-1 px-0.5 pb-2" :style="'grid-auto-rows: 44px'">
                            {{-- One bar per Project (not per Sub Task) - see computeWeekBars()/projectGroups() --}}
                            <template x-for="bar in weekBars(week[0].date).bars" :key="week[0].date + '-' + bar.group.project_id">
                                <div @click.stop="openProjectPanel(bar.group)"
                                     :class="[taskColor(bar.group).bar, bar.group.overdue > 0 ? 'ring-2 ring-red-400' : '']"
                                     :style="'grid-column:' + bar.colStart + ' / span ' + bar.colSpan + '; grid-row:' + (bar.lane + 1) + ';' + taskColor(bar.group).style"
                                     :title="groupTitle(bar.group)"
                                     class="flex flex-col justify-center gap-0.5 rounded-md px-2 py-1 text-xs leading-tight cursor-pointer hover:brightness-95 hover:shadow-sm transition overflow-hidden">
                                    <span class="flex items-center gap-1.5 truncate">
                                        <span x-show="bar.continuesBefore" class="shrink-0" title="เริ่มก่อนหน้านี้">◄</span>
                                        <span x-show="bar.group.progress === 100" class="shrink-0" title="เสร็จแล้ว">✓</span>
                                        <span x-show="isHigh(bar.group)" class="font-bold text-red-600 shrink-0" title="Priority สูง">!</span>
                                        <span class="truncate font-medium" x-text="bar.group.project_no + ' · ' + bar.group.project_name"></span>
                                        <span x-show="bar.continuesAfter" class="shrink-0 ml-auto" title="ต่อเนื่องถึงสัปดาห์ถัดไป">►</span>
                                    </span>
                                    <span class="truncate text-[10px] opacity-75" x-text="bar.group.cabinet_count + ' Cabinets · ' + bar.group.task_count + ' Tasks'"></span>
                                </div>
                            </template>
                            <p x-show="weekBars(week[0].date).overflow > 0"
                               @click="view = 'list'"
                               class="col-span-7 text-[11px] font-medium text-blue-600 hover:underline cursor-pointer px-1"
                               x-text="'+' + weekBars(week[0].date).overflow + ' โปรเจกต์ — ดูรายการทั้งหมด'"></p>
                        </div>
                    </div>
                </template>

                {{-- One line per Project with undated Sub Tasks (point 10) - not one row
                     per Sub Task, so this stays short even with many undated items. --}}
                <div x-show="undatedProjectGroups().length > 0" class="border-t border-slate-100 bg-amber-50/60 text-xs text-amber-800 divide-y divide-amber-100/70">
                    <template x-for="u in undatedProjectGroups()" :key="'undated-' + u.group.project_id">
                        <button type="button" @click="openProjectPanel(u.group)" class="w-full flex items-center justify-between gap-3 px-4 py-2 text-left hover:bg-amber-100/50">
                            <span>⚠ <span x-text="u.group.project_no"></span> — <span x-text="u.count"></span> งานยังไม่กำหนดวัน</span>
                            <span class="font-semibold text-amber-900 shrink-0">ดู →</span>
                        </button>
                    </template>
                </div>

                {{-- Color legend: each distinct Project on the calendar keeps one color
                     everywhere it appears (bar, day-panel card accent, panel item accent). --}}
                <div x-show="legendItems().length > 0" class="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-4 py-2.5 border-t border-slate-100">
                    <template x-for="l in legendItems()" :key="l.name">
                        <span class="inline-flex items-center gap-1.5 text-xs text-slate-600">
                            <span :class="l.color.dot" :style="l.color.dotStyle" class="w-2.5 h-2.5 rounded-full shrink-0"></span>
                            <span x-text="l.name"></span>
                        </span>
                    </template>
                </div>
            </x-card>
        </div>

        {{-- List - one row per Project (point 8); click a row to open the Right Detail
             Panel, where per-Sub-Task actions live now. --}}
        <div x-show="mainView === 'calendar' && view === 'list'">
            <x-card class="!p-0 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">Project</th>
                            <th class="px-4 py-2 text-left font-medium">Customer</th>
                            <th class="px-4 py-2 text-left font-medium">Cabinets</th>
                            <th class="px-4 py-2 text-left font-medium">Tasks</th>
                            <th class="px-4 py-2 text-left font-medium">Start</th>
                            <th class="px-4 py-2 text-left font-medium">Due</th>
                            <th class="px-4 py-2 text-left font-medium">Waiting</th>
                            <th class="px-4 py-2 text-left font-medium">In Progress</th>
                            <th class="px-4 py-2 text-left font-medium">Completed</th>
                            <th class="px-4 py-2 text-left font-medium">Progress</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="g in listProjectGroups()" :key="g.project_id">
                            <tr class="hover:bg-blue-50 cursor-pointer" @click="openProjectPanel(g)">
                                <td class="px-4 py-2">
                                    <p class="font-medium text-blue-600" x-text="g.project_no"></p>
                                    <p class="text-xs text-slate-500" x-text="g.project_name"></p>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap text-slate-600" x-text="g.customer_name"></td>
                                <td class="px-4 py-2 whitespace-nowrap" x-text="g.cabinet_count"></td>
                                <td class="px-4 py-2 whitespace-nowrap" x-text="g.task_count"></td>
                                <td class="px-4 py-2 whitespace-nowrap" x-text="fmt(g.dept_start)"></td>
                                <td class="px-4 py-2 whitespace-nowrap" x-text="fmt(g.dept_due)"></td>
                                <td class="px-4 py-2 whitespace-nowrap" x-text="g.waiting"></td>
                                <td class="px-4 py-2 whitespace-nowrap" x-text="g.working"></td>
                                <td class="px-4 py-2 whitespace-nowrap" x-text="g.done"></td>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <div class="flex items-center gap-2 w-28">
                                        <div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden">
                                            <div class="h-1.5 rounded-full bg-emerald-500" :style="'width:' + g.progress + '%'"></div>
                                        </div>
                                        <span class="text-xs text-slate-500 tabular-nums shrink-0" x-text="g.progress + '%'"></span>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="listProjectGroups().length === 0"><td colspan="10" class="px-4 py-8 text-center text-slate-400">ไม่มี Project ตามเงื่อนไขที่เลือก</td></tr>
                    </tbody>
                </table>
                <p class="px-4 py-2 text-xs text-slate-400 border-t border-slate-100">แสดง Project ในช่วงเวลาที่เลือก รวม Project ที่มีงานเกินกำหนดและงานที่ยังไม่ระบุวัน</p>
            </x-card>
        </div>

        {{-- Timeline (Gantt-style) - same items()/department scope as Calendar (no new
             fetch, no new endpoint), one row per Project (point 7), using the same date
             window (range/anchor) as Calendar. Click a row to open the Right Detail Panel. --}}
        <div x-show="mainView === 'timeline'">
            <x-card class="!p-0 overflow-hidden">
                {{-- Search - client-side only, filters the Project rows already loaded
                     (this.items); never re-fetches or widens the department scope. A match
                     on a Cabinet/Sub Task inside a Project keeps that Project's row visible. --}}
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
                            <div class="sticky left-0 z-10 bg-slate-50 px-3 py-2 border-r border-slate-200 text-xs font-medium text-slate-500">Project</div>
                            <div class="grid" :style="'grid-template-columns:' + timelineColTemplate()">
                                <template x-for="d in timelineDays()" :key="d.date">
                                    <div class="text-center py-2 text-xs font-medium border-l border-slate-100" :class="d.date === today ? 'bg-blue-50 text-blue-700 font-semibold' : 'text-slate-500'" x-text="d.day"></div>
                                </template>
                            </div>
                        </div>

                        <p x-show="timelineGroups().length === 0" class="text-sm text-slate-400 py-16 text-center" x-text="timelineSearch.trim() ? 'ไม่พบ Project ที่ตรงกับ &quot;' + timelineSearch + '&quot;' : 'ไม่มีงานในช่วงเวลานี้'"></p>

                        <template x-for="row in timelineGroups()" :key="row.group.project_id">
                            <div class="grid grid-cols-[200px_1fr] border-b border-slate-100 hover:bg-slate-50/50">
                                <div class="sticky left-0 z-10 bg-white px-3 py-2 border-r border-slate-200 text-xs text-slate-700 min-w-0" :title="groupTitle(row.group)">
                                    <p class="font-semibold truncate" x-text="row.group.project_no"></p>
                                    <p class="truncate text-slate-500" x-text="row.group.project_name"></p>
                                </div>
                                <div class="grid py-1.5" :style="'grid-template-columns:' + timelineColTemplate()">
                                    <div @click="openProjectPanel(row.group)"
                                         :class="[taskColor(row.group).bar, row.group.overdue > 0 ? 'ring-2 ring-red-400' : '']"
                                         :style="'grid-column:' + row.colStart + ' / span ' + row.colSpan + ';' + taskColor(row.group).style"
                                         :title="groupTitle(row.group)"
                                         class="flex items-center gap-1.5 rounded-md px-2 h-9 text-xs truncate cursor-pointer hover:brightness-95 hover:shadow-sm transition">
                                        <span x-show="row.continuesBefore" class="shrink-0">◄</span>
                                        <span x-show="row.group.progress === 100" class="shrink-0">✓</span>
                                        <span x-show="isHigh(row.group)" class="font-bold text-red-600 shrink-0">!</span>
                                        <span class="truncate font-medium" x-text="row.group.project_no"></span>
                                        {{-- Point 2: both count and status breakdown, shown together (more room here than the Calendar cell) --}}
                                        <span class="truncate opacity-75" x-text="'· ' + row.group.cabinet_count + ' Cab · ' + row.group.task_count + ' Task · รอ ' + row.group.waiting + ' · ทำ ' + row.group.working + ' · เสร็จ ' + row.group.done"></span>
                                        <span x-show="row.continuesAfter" class="shrink-0 ml-auto">►</span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="timelineOffWindowCount() > 0" class="flex items-center justify-between gap-3 px-4 py-2 border-t border-slate-100 bg-amber-50/60 text-xs text-amber-800">
                    <span>⚠ <span x-text="timelineOffWindowCount()"></span> Project อยู่นอกช่วงเวลานี้ หรือยังไม่ระบุวันที่</span>
                    <button type="button" @click="mainView = 'calendar'; view = 'list'" class="font-semibold text-amber-900 hover:underline shrink-0">ดูรายการ</button>
                </div>

                <div x-show="legendItems().length > 0" class="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-4 py-2.5 border-t border-slate-100">
                    <template x-for="l in legendItems()" :key="l.name">
                        <span class="inline-flex items-center gap-1.5 text-xs text-slate-600">
                            <span :class="l.color.dot" :style="l.color.dotStyle" class="w-2.5 h-2.5 rounded-full shrink-0"></span>
                            <span x-text="l.name"></span>
                        </span>
                    </template>
                </div>
            </x-card>
        </div>

        {{-- Sub Task detail/Checklist - opened from a Sub Task card inside the Right
             Detail Panel's Cabinet accordion. Deliberately does NOT close/replace the
             panel (see openDetail() in _script.blade.php) - the panel stays open behind
             it, so closing this modal drops you back into the same Cabinet you were
             browsing, ready to open another Sub Task without reopening the panel. --}}
        <x-modal name="my-department-detail" maxWidth="2xl">
            <div class="p-6" x-show="detail">
                <template x-if="detail">
                    <div>
                        <div class="flex items-start justify-between gap-3 mb-4">
                            <div>
                                <p class="text-xs text-slate-400" x-text="detail.project_no + ' · ' + detail.project_name"></p>
                                <h3 class="text-base font-semibold text-slate-900" x-text="detail.name"></h3>
                            </div>
                            <div class="flex flex-wrap gap-1 justify-end">
                                <span :class="badgeClass(detail)" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium">
                                    <span x-show="detail.assignment_status === 'COMPLETED'">✓</span>
                                    <span x-text="detail.assignment_status_label"></span>
                                </span>
                                <span x-show="detail.is_overdue" class="rounded-full px-2 py-0.5 text-xs font-medium bg-red-100 text-red-700">เกินกำหนด</span>
                            </div>
                        </div>

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
                            <ul class="space-y-1.5 max-h-64 overflow-y-auto">
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

                        <div class="mt-6 flex flex-wrap items-center justify-between gap-2">
                            <a :href="detail.cabinet_url" class="text-sm font-medium text-blue-600 hover:underline">เปิดหน้า Cabinet →</a>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="$dispatch('close-modal', 'my-department-detail')" class="px-4 py-2 text-sm font-medium text-slate-600">ปิด</button>
                                @include('my-department.partials._item-actions', ['v' => 'detail', 'modal' => true])
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </x-modal>

        @include('my-department.partials._day-panel')
        @include('my-department.partials._project-panel')
    </div>
    @endif

    @push('scripts')
    @include('my-department.partials._script')
    @endpush
</x-app-layout>
