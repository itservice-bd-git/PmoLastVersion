<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">งานแผนกของฉัน / My Department</h2>
    </x-slot>

    @if (! $department)
        <x-card>
            <p class="text-sm text-slate-600">บัญชีของคุณยังไม่ได้ผูกกับแผนก จึงยังไม่มีงานให้แสดง กรุณาติดต่อผู้ดูแลระบบเพื่อกำหนดแผนกในหน้า Users</p>
        </x-card>
    @else
    {{--
        Laid out like Avatar Planning (index.html): a left "งานที่ต้องเสร็จ" panel + one toolbar with the เดือน / ไทม์ไลน์ /
        แดชบอร์ด switch, search, priority filters, status legend. It bleeds to the edges of the page (negative margins
        cancel layouts/app's <main> padding). Data, panels and permissions are the existing ones - only the presentation
        and the Project-level bars are new; "แยกแถบตามงานย่อย" in ตัวเลือก brings back one bar per Sub Task.
    --}}
    <div class="-m-4 sm:-m-6 lg:-m-8 flex flex-col lg:flex-row min-h-[calc(100vh-4rem)] bg-white"
         x-data="myDepartment(@js([
             'tasksUrl' => route('my-department.tasks'),
             'exportUrl' => route('my-department.export'),
             'dashboardUrl' => route('my-department.dashboard'),
             'projectUrl' => route('my-department.project', ['project' => '__ID__']),
             'departmentId' => $department->id,
             'departmentName' => $department->name,
             'userId' => auth()->id(),
             'today' => today()->format('Y-m-d'),
         ]))">

        {{-- ===================== Left panel: งานที่ต้องเสร็จ ===================== --}}
        <aside class="lg:w-[300px] lg:shrink-0 border-b lg:border-b-0 lg:border-r border-slate-200">
          {{-- the border belongs to the full-height column; only its content sticks under the app header --}}
          <div class="p-4 space-y-3 lg:sticky lg:top-16 lg:max-h-[calc(100vh-4rem)] lg:overflow-y-auto">
            <h3 class="text-base font-bold text-slate-900">งานที่ต้องเสร็จ</h3>

            <div class="flex items-center gap-1.5">
                <button type="button" @click="shiftFocus(-1)" class="w-8 h-8 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50" aria-label="วันก่อนหน้า">&lsaquo;</button>
                <button type="button" @click="focusToday()" class="flex-1 h-8 rounded-lg border border-slate-300 px-2 text-xs text-slate-700 hover:bg-slate-50 truncate">
                    <span x-show="focusDate === today" class="font-semibold text-blue-600 mr-1">วันนี้</span><span x-text="fmtBE(focusDate)"></span>
                </button>
                <button type="button" @click="shiftFocus(1)" class="w-8 h-8 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50" aria-label="วันถัดไป">&rsaquo;</button>
                <button type="button" @click="$dispatch('open-status-report')" class="w-8 h-8 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50 inline-flex items-center justify-center" title="รายงานสถานะงาน" aria-label="รายงานสถานะงาน">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                </button>
                <button type="button" onclick="window.print()" class="w-8 h-8 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50 inline-flex items-center justify-center" title="พิมพ์" aria-label="พิมพ์">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V3h12v6M6 18H4a1 1 0 01-1-1v-6a2 2 0 012-2h14a2 2 0 012 2v6a1 1 0 01-1 1h-2M7 14h10v7H7z"/></svg>
                </button>
            </div>

            <div>
                <div class="flex items-center justify-between text-xs text-slate-500 mb-1">
                    <span>ความคืบหน้า</span><span class="tabular-nums" x-text="focusCounts().done + '/' + focusCounts().total + ' งาน'"></span>
                </div>
                <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden"><div class="h-1.5 rounded-full bg-emerald-500 transition-all" :style="'width:' + focusCounts().pct + '%'"></div></div>
            </div>

            <template x-for="sec in focusSections()" :key="sec.key">
                <div x-show="sec.count > 0" class="space-y-2">
                    <p class="flex items-center justify-between text-xs font-semibold" :class="sec.tone">
                        <span x-text="sec.label"></span>
                        <span class="rounded-full bg-slate-100 px-1.5 text-[11px] text-slate-600 tabular-nums" x-text="sec.count"></span>
                    </p>
                    <template x-for="g in sec.groups" :key="sec.key + '-' + g.project_id">
                        <div class="rounded-lg border border-slate-200 bg-slate-50/60">
                            <div class="flex items-center gap-1.5 px-2.5 py-2">
                                <svg x-show="flagClass(g)" :class="flagClass(g)" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3v18h2v-7h11l-2-4 2-4H7V3H5z"/></svg>
                                <button type="button" @click="openProjectModal(g.project_id)" class="min-w-0 flex-1 truncate text-left text-sm font-semibold text-slate-800 hover:text-blue-600 hover:underline" x-text="g.project_no + ' ' + g.project_name"></button>
                                <span class="text-[11px] text-slate-500 tabular-nums" x-text="g.items.length + ' งาน'"></span>
                            </div>
                            <ul class="border-t border-slate-200/70 divide-y divide-slate-100">
                                <template x-for="i in g.items" :key="'f' + i.id">
                                    <li>
                                        <button type="button" @click="openDetail(i)" class="w-full flex items-center gap-2 px-2.5 py-1.5 text-left hover:bg-white">
                                            <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="'background-color:' + statusColor(itemStatusKey(i))"></span>
                                            <span class="min-w-0 flex-1 truncate text-xs text-slate-700" x-text="i.cabinet_mo + ' · ' + i.name"></span>
                                            <span x-show="i.checklists_total > 0" class="rounded bg-slate-200/70 px-1 text-[10px] font-semibold text-slate-600 tabular-nums" x-text="i.checklists_completed + '/' + i.checklists_total"></span>
                                            <span class="text-[10px] text-slate-400 tabular-nums" x-text="fmt(i.due_date).slice(0, 5)"></span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>
                </div>
            </template>

            <div x-show="groupCount(focusDueGroups()) === 0 && groupCount(focusOverdueGroups()) === 0" class="py-8 text-center">
                <svg class="mx-auto w-8 h-8 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 12l3 3 5-6"/></svg>
                <p class="mt-2 text-sm text-slate-400" x-text="focusDate === today ? 'ไม่มีงานที่ต้องเสร็จวันนี้' : 'ไม่มีงานที่ต้องเสร็จวันนี้'"></p>
            </div>
          </div>
        </aside>

        {{-- ===================== Main ===================== --}}
        <div class="flex-1 min-w-0 flex flex-col">

            {{-- Toolbar: sticky under the app header, two rows like Avatar Planning --}}
            <div class="sticky top-16 z-20 bg-white border-b border-slate-200 px-4 py-2.5 space-y-2">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <div class="inline-flex overflow-hidden rounded-lg border border-slate-300 text-sm">
                        <button type="button" @click="prevMonth()" class="px-2.5 h-8 text-slate-600 hover:bg-slate-50" aria-label="ก่อนหน้า">&lsaquo;</button>
                        <button type="button" @click="goToday(); focusToday()" class="px-3 h-8 border-x border-slate-300 font-medium text-slate-700 hover:bg-slate-50">วันนี้</button>
                        <button type="button" @click="nextMonth()" class="px-2.5 h-8 text-slate-600 hover:bg-slate-50" aria-label="ถัดไป">&rsaquo;</button>
                    </div>
                    <h3 class="text-base font-bold text-slate-900" x-text="mainView === 'dashboard' ? 'แดชบอร์ด' : rangeLabel()"></h3>
                    <span x-show="loading" class="text-xs text-slate-400">กำลังโหลด...</span>

                    <div class="inline-flex overflow-hidden rounded-lg border border-slate-300 text-sm font-medium">
                        <button type="button" @click="showMonth()" :class="mainView === 'calendar' && view === 'calendar' ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-3 h-8">เดือน</button>
                        <button type="button" @click="showTimeline()" :class="mainView === 'timeline' ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-3 h-8 border-l border-slate-300">ไทม์ไลน์</button>
                        <button type="button" @click="showDashboard()" :class="mainView === 'dashboard' ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-3 h-8 border-l border-slate-300">แดชบอร์ด</button>
                    </div>

                    <div class="relative flex-1 min-w-[12rem] max-w-md">
                        <svg class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M21 21l-4.3-4.3"/></svg>
                        <input type="search" x-model="search" @input.debounce.200ms="rebuild()" placeholder="ค้นหางาน / ลูกค้า / ตู้..."
                               class="w-full h-8 rounded-lg border-slate-300 pl-8 pr-2 text-sm placeholder:text-slate-400">
                    </div>

                    <div class="inline-flex items-center gap-1.5 text-xs font-medium">
                        <button type="button" @click="setPriorityFilter('all')" :class="priorityFilter === 'all' ? 'border-slate-900 text-slate-900 ring-1 ring-slate-900' : 'border-slate-300 text-slate-600 hover:bg-slate-50'" class="h-7 rounded-md border bg-white px-2.5">ทั้งหมด</button>
                        <button type="button" @click="setPriorityFilter('urgent')" :class="priorityFilter === 'urgent' ? 'border-red-500 ring-1 ring-red-500' : 'border-slate-300 hover:bg-slate-50'" class="h-7 inline-flex items-center gap-1 rounded-md border bg-white px-2.5 text-slate-700"><svg class="w-3.5 h-3.5 text-red-600" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3v18h2v-7h11l-2-4 2-4H7V3H5z"/></svg>ด่วน</button>
                        <button type="button" @click="setPriorityFilter('high')" :class="priorityFilter === 'high' ? 'border-orange-500 ring-1 ring-orange-500' : 'border-slate-300 hover:bg-slate-50'" class="h-7 inline-flex items-center gap-1 rounded-md border bg-white px-2.5 text-slate-700"><svg class="w-3.5 h-3.5 text-orange-500" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3v18h2v-7h11l-2-4 2-4H7V3H5z"/></svg>สูง</button>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <button type="button" @click="toggleQuickFilter('mineOnly')" :aria-pressed="mineOnly"
                            :class="mineOnly ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-50'"
                            class="h-8 inline-flex items-center gap-1.5 rounded-lg border px-3 text-xs font-medium">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path stroke-linecap="round" d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>งานของฉัน</button>
                    <button type="button" @click="toggleQuickFilter('starredOnly')" :aria-pressed="starredOnly"
                            :class="starredOnly ? 'bg-amber-500 border-amber-500 text-white' : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-50'"
                            class="h-8 inline-flex items-center gap-1 rounded-lg border px-3 text-xs font-medium">★ ดาว</button>

                    @if ($canViewOthers)
                        {{-- "ทุกแผนก" is admin/PM only - resolveDepartment() enforces it server-side, this is not just a hidden option. --}}
                        <select x-model="departmentId" @change="changeDepartment()" class="h-8 rounded-lg border-slate-300 py-0 pl-3 pr-8 text-xs font-medium" aria-label="Department">
                            <option value="all">ทุกแผนก</option>
                            @foreach ($departments as $dept)
                                <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <span class="h-8 inline-flex items-center rounded-lg border border-slate-300 px-3 text-xs font-medium text-slate-600">{{ $department->name }}</span>
                    @endif

                    <div class="ml-auto flex items-center gap-3">
                        <div class="hidden sm:flex items-center gap-2" aria-label="สีสถานะ">
                            <template x-for="k in ['assigned', 'in_progress', 'accepted', 'completed', 'overdue']" :key="k">
                                <span class="w-2.5 h-2.5 rounded-full" :style="'background-color:' + statusColor(k)" :title="statusLabel(k)"></span>
                            </template>
                        </div>

                        {{-- ตัวเลือก: everything the old toolbar had that Avatar Planning's does not --}}
                        <div class="relative" @click.outside="moreOpen = false" @keydown.escape.window="moreOpen = false">
                            <button type="button" @click="moreOpen = !moreOpen" class="h-8 inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 text-xs font-medium text-slate-600 hover:bg-slate-50" :aria-expanded="moreOpen">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M7 12h10M10 18h4"/></svg>ตัวเลือก
                            </button>
                            <div x-show="moreOpen" x-cloak class="absolute right-0 mt-2 w-72 rounded-xl border border-slate-200 bg-white p-3 shadow-lg space-y-3 text-sm z-30">
                                <label class="flex items-center gap-2 text-slate-700 cursor-pointer"><input type="checkbox" x-model="splitBars" class="rounded border-slate-300 text-blue-600"> แยกแถบตามงานย่อย (Sub Task)</label>
                                <label class="flex items-center gap-2 text-slate-700 cursor-pointer"><input type="checkbox" x-model="hideCompleted" @change="toggleHideCompleted()" class="rounded border-slate-300 text-blue-600"> ซ่อนงานที่เสร็จแล้ว</label>
                                <div class="space-y-2">
                                    <select x-model="projectId" @change="changeProjectFilter()" class="w-full rounded-lg border-slate-300 text-sm" aria-label="Project">
                                        <option value="">ทุก Project</option>
                                        @foreach ($projects as $p)<option value="{{ $p->id }}">{{ $p->project_no }}</option>@endforeach
                                    </select>
                                    <select x-model="statusFilter" @change="changeStatusFilter()" class="w-full rounded-lg border-slate-300 text-sm" aria-label="Status">
                                        <option value="">ทุกสถานะ</option>
                                        <option value="ASSIGNED">รอรับงาน</option>
                                        <option value="ACCEPTED">รับงานแล้ว</option>
                                        <option value="IN_PROGRESS">กำลังทำ</option>
                                        <option value="COMPLETED">เสร็จแล้ว</option>
                                        <option value="OVERDUE">เกินกำหนด</option>
                                    </select>
                                </div>
                                <div class="flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                                    <button type="button" @click="showMonth(); moreOpen = false" class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-50">ปฏิทินรายเดือน</button>
                                    <button type="button" @click="mainView = 'calendar'; setCalendarRange('7'); moreOpen = false" class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-50">สัปดาห์ (7 วัน)</button>
                                    <button type="button" @click="mainView = 'calendar'; view = 'list'; moreOpen = false" class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-50">มุมมองรายการ</button>
                                    <button type="button" @click="exportCsv(); moreOpen = false" class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-50">Export CSV</button>
                                </div>
                            </div>
                        </div>

                        @if (auth()->user()->canDispatchWork())
                            <a href="{{ route('projects.create') }}" class="w-9 h-9 inline-flex items-center justify-center rounded-full bg-blue-600 text-white text-xl leading-none shadow hover:bg-blue-700" title="สร้างโครงการใหม่" aria-label="สร้างโครงการใหม่">+</a>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ===================== เดือน (Month / 7-day grid) ===================== --}}
            <div x-show="mainView === 'calendar' && view === 'calendar'">
                <div class="grid grid-cols-7 border-b border-slate-200 bg-white px-0 text-[11px] font-bold text-slate-500">
                    <template x-for="w in weekdayHeaders()"><div class="px-3 py-2" x-text="w"></div></template>
                </div>

                <template x-for="week in weekRows()" :key="week[0].date">
                    <div class="border-b border-slate-200">
                        <div class="grid grid-cols-7">
                            <template x-for="d in week" :key="d.date">
                                <button type="button" @click="selectDate(d.date)"
                                        :class="[d.date === today ? 'bg-blue-50/70' : (isWeekend(d.date) ? 'bg-slate-50/70' : 'bg-white'), selectedDate === d.date && d.date !== today ? '!bg-blue-50' : '']"
                                        class="border-r border-slate-100 last:border-r-0 px-3 pt-2 pb-1 text-left hover:bg-blue-50/50 focus:outline-none">
                                    <span :class="d.date === today ? 'bg-blue-600 text-white' : (d.inRange ? 'text-slate-800' : 'text-slate-300')"
                                          class="inline-flex items-center justify-center min-w-[1.6rem] h-[1.6rem] rounded-full text-xs font-semibold" x-text="d.day"></span>
                                </button>
                            </template>
                        </div>

                        {{-- bars: one row per lane; faint column lines + weekend tint sit behind them --}}
                        <div class="relative">
                            <div class="absolute inset-0 grid grid-cols-7 pointer-events-none" aria-hidden="true">
                                <template x-for="d in week" :key="'bg-' + d.date">
                                    <div :class="d.date === today ? 'bg-blue-50/70' : (isWeekend(d.date) ? 'bg-slate-50/70' : '')" class="border-r border-slate-100 last:border-r-0"></div>
                                </template>
                            </div>
                            <div class="relative grid grid-cols-7 gap-y-1 px-0.5 pb-1.5" style="grid-auto-rows: 46px; min-height: 24px;">
                                <template x-for="b in weekShownBars(week)" :key="week[0].date + '-' + b.key">
                                    <div @click.stop="openBar(b)"
                                         :style="'grid-column:' + b.colStart + ' / span ' + b.colSpan + '; grid-row:' + (b.lane + 1) + ';' + chipStyle(b.status)"
                                         :title="b.title + ' — ' + b.sub + ' — ' + statusLabel(b.status)"
                                         class="mx-0.5 min-w-0 cursor-pointer overflow-hidden rounded-md border-l-[3px] px-2 py-1 flex flex-col justify-center hover:brightness-95 hover:shadow-sm transition">
                                        <span class="truncate text-xs font-medium leading-tight" :class="b.done ? 'line-through text-slate-500' : 'text-slate-800'"
                                              x-text="(b.continuesBefore ? '◄ ' : '') + b.title"></span>
                                        <span class="mt-0.5 flex items-center gap-1.5">
                                            <span x-show="b.badge" class="inline-flex min-w-[18px] h-[18px] items-center justify-center rounded px-1 text-[10px] font-bold text-white tabular-nums" :style="'background-color:' + statusColor(b.status)" x-text="b.badge"></span>
                                            <svg x-show="flagClass(b.ref)" :class="flagClass(b.ref)" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3v18h2v-7h11l-2-4 2-4H7V3H5z"/></svg>
                                            <span x-show="b.continuesAfter" class="ml-auto text-[10px] text-slate-500">►</span>
                                        </span>
                                    </div>
                                </template>
                                <template x-for="(d, idx) in week" :key="d.date + '-more'">
                                    <p x-show="weekHiddenCount(week, d.date) > 0" @click.stop="selectDate(d.date)"
                                       :style="'grid-column:' + (idx + 1) + '; grid-row:' + 5 + ';'"
                                       class="self-start px-2 text-[10px] font-medium text-blue-600 hover:underline cursor-pointer"
                                       x-text="'+' + weekHiddenCount(week, d.date) + ' รายการ'"></p>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>

                {{-- Unscheduled work - never dropped just because it has no Start/Due date to place on the grid. --}}
                <div x-show="unscheduledItems().length > 0" class="border-t border-slate-100 bg-amber-50/60 text-xs text-amber-800 divide-y divide-amber-100/70">
                    <template x-for="i in unscheduledItems()" :key="'unscheduled-' + i.id">
                        <button type="button" @click="openDetail(i)" class="w-full flex items-center justify-between gap-3 px-4 py-2 text-left hover:bg-amber-100/50">
                            <span>⚠ <span x-text="i.project_no"></span> · <span x-text="i.department_name"></span> · <span x-text="i.name"></span></span>
                            <span class="font-semibold text-amber-900 shrink-0">ดู →</span>
                        </button>
                    </template>
                </div>
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

            {{-- ===================== ไทม์ไลน์ ===================== --}}
            <div x-show="mainView === 'timeline'">
                <div class="overflow-x-auto" x-ref="timelineScroll">
                    <div class="min-w-max relative">
                        <div x-show="todayLineLeft() !== null" class="absolute top-0 bottom-0 w-px bg-blue-400 z-[5] pointer-events-none" :style="'left:' + todayLineLeft() + 'px'"></div>

                        <div class="grid grid-cols-[200px_1fr] border-b border-slate-200 bg-white">
                            <div class="sticky left-0 z-10 bg-white px-3 py-2 border-r border-slate-200 text-xs font-bold text-slate-500">โปรเจค</div>
                            <div class="grid" :style="'grid-template-columns:' + timelineColTemplate()">
                                <template x-for="d in timelineDays()" :key="d.date">
                                    <div class="py-2 text-center text-xs font-medium border-l border-slate-100" :class="isWeekend(d.date) ? 'bg-slate-50/70' : ''">
                                        <span :class="d.date === today ? 'bg-blue-600 text-white font-bold' : 'text-slate-500'" class="inline-flex items-center justify-center min-w-[1.4rem] h-[1.4rem] rounded-full" x-text="parseInt(d.date.slice(8), 10)"></span>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <p x-show="timelineRows().length === 0" class="text-sm text-slate-400 py-16 text-center" x-text="search.trim() ? 'ไม่พบรายการที่ตรงกับ &quot;' + search + '&quot;' : 'ไม่มีงานในช่วงเวลานี้'"></p>

                        <template x-for="row in timelineRows()" :key="'p' + row.group.project_id">
                            <div class="grid grid-cols-[200px_1fr] border-b border-slate-100 hover:bg-slate-50/40">
                                <div class="sticky left-0 z-10 bg-white px-3 py-3 border-r border-slate-200 text-xs text-slate-700 min-w-0 flex items-center gap-1.5" :title="groupTitle(row.group)">
                                    <svg x-show="flagClass(row.group)" :class="flagClass(row.group)" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3v18h2v-7h11l-2-4 2-4H7V3H5z"/></svg>
                                    <span class="truncate font-medium" x-text="row.group.project_no + ' ' + row.group.project_name"></span>
                                </div>
                                <div class="grid items-center py-3" :style="'grid-template-columns:' + timelineColTemplate()">
                                    <div @click="openProjectPanel(row.group)"
                                         :style="'grid-column:' + row.colStart + ' / span ' + row.colSpan + ';' + chipStyle(groupStatusKey(row.group))"
                                         :title="groupTitle(row.group)"
                                         class="relative flex items-center gap-1.5 h-8 rounded-md border-l-[3px] px-2 text-xs cursor-pointer hover:brightness-95 hover:shadow-sm transition">
                                        <span class="absolute -top-1.5 right-2 w-2.5 h-2.5 rounded-full ring-2 ring-white" :style="'background-color:' + statusColor(groupStatusKey(row.group))"></span>
                                        <span x-show="row.continuesBefore" class="shrink-0">◄</span>
                                        <span class="truncate font-semibold" :class="row.group.progress === 100 ? 'line-through text-slate-500' : 'text-slate-800'" x-text="row.group.project_no + ' ' + row.group.project_name"></span>
                                        <span class="truncate text-[10px] text-slate-500" x-text="'· ' + row.group.cabinet_count + ' ตู้ · เสร็จ ' + row.group.done + '/' + row.group.total_subtasks"></span>
                                        <span x-show="row.continuesAfter" class="shrink-0 ml-auto">►</span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="timelineOffWindowCount() > 0" class="flex items-center justify-between gap-3 px-4 py-2 border-t border-slate-100 bg-amber-50/60 text-xs text-amber-800">
                    <span>⚠ <span x-text="timelineOffWindowCount()"></span> โปรเจคอยู่นอกช่วงเวลานี้ หรือยังไม่ระบุวันที่</span>
                    <button type="button" @click="mainView = 'calendar'; view = 'list'" class="font-semibold text-amber-900 hover:underline shrink-0">ดูรายการ</button>
                </div>
            </div>

            {{-- ===================== แดชบอร์ด ===================== --}}
            <div x-show="mainView === 'dashboard'" class="p-4 sm:p-5 space-y-5" x-cloak>
                @if ($canViewOthers)
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="text-slate-500">กรองแผนก:</span>
                        <button type="button" @click="departmentId = 'all'; changeDepartment()" :class="departmentId === 'all' ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-50'" class="rounded-full border px-3 py-1 font-medium">ทุกแผนก</button>
                        @foreach ($departments as $dept)
                            <button type="button" @click="departmentId = {{ $dept->id }}; changeDepartment()" :class="Number(departmentId) === {{ $dept->id }} ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-50'" class="rounded-full border px-3 py-1 font-medium">{{ $dept->name }}</button>
                        @endforeach
                    </div>
                @endif

                <p x-show="dashLoading && !dash" class="py-16 text-center text-sm text-slate-400">กำลังโหลด...</p>

                <template x-if="dash">
                    <div class="space-y-5">
                        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
                            <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-slate-900" x-text="dash.projects.length"></p><p class="text-xs text-slate-500 mt-1">โปรเจคทั้งหมด</p></div>
                            <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-emerald-600" x-text="dash.totals.completed"></p><p class="text-xs text-slate-500 mt-1">งานเสร็จแล้ว</p></div>
                            <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-blue-600" x-text="dash.totals.in_progress"></p><p class="text-xs text-slate-500 mt-1">กำลังทำ</p></div>
                            <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-red-600" x-text="dash.totals.overdue"></p><p class="text-xs text-slate-500 mt-1">เลยกำหนด</p></div>
                            <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-slate-900" x-text="dash.totals.checklist_pct + '%'"></p><p class="text-xs text-slate-500 mt-1" x-text="'เช็คลิสต์รวม (' + dash.totals.checklist_done + '/' + dash.totals.checklist_total + ')'"></p></div>
                        </div>

                        <div>
                            <h4 class="text-sm font-bold text-slate-900">ภาระงานรายสัปดาห์ <span class="font-normal text-xs text-slate-400">(งานที่ยังไม่เสร็จ ตามวันครบกำหนด)</span></h4>
                            <div class="mt-2 rounded-xl border border-slate-200 p-4">
                                <div class="flex items-end gap-4 h-44" role="img" aria-label="จำนวนงานที่ยังไม่เสร็จแยกตามช่วงวันครบกำหนด">
                                    <template x-for="b in dash.buckets" :key="b.key">
                                        <div class="flex-1 flex flex-col items-center justify-end h-full" :title="b.label + ': ' + b.count + ' งาน'">
                                            <span class="text-xs font-semibold tabular-nums" :class="b.overdue && b.count > 0 ? 'text-red-700' : 'text-slate-700'" x-text="b.count"></span>
                                            <div class="w-full max-w-[56px] rounded-t" :class="[b.overdue ? 'bg-red-600' : 'bg-blue-600', b.count === 0 ? 'opacity-30' : '']"
                                                 :style="'height:' + (b.count === 0 ? 3 : Math.max(6, Math.round(b.count / Math.max(1, ...dash.buckets.map((x) => x.count)) * 100))) + '%'"></div>
                                            <span class="mt-1.5 text-[11px] text-center" :class="b.overdue ? 'text-red-700 font-medium' : 'text-slate-500'" x-text="b.label"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h4 class="text-sm font-bold text-slate-900">ความคืบหน้าแต่ละแผนก</h4>
                            <div class="mt-2 rounded-xl border border-slate-200 divide-y divide-slate-100">
                                <template x-for="r in dash.departments" :key="r.id">
                                    <div class="grid grid-cols-[minmax(7rem,12rem)_1fr_auto] items-center gap-4 px-4 py-2.5">
                                        <span class="text-sm font-medium text-slate-800 truncate" x-text="r.name"></span>
                                        <div class="h-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-2 rounded-full bg-blue-500" :style="'width:' + r.checklist_pct + '%'"></div></div>
                                        <span class="text-xs text-slate-500 whitespace-nowrap tabular-nums">เสร็จ <span x-text="r.completed + '/' + r.total"></span><template x-if="r.overdue > 0"><span> · <b class="text-red-600" x-text="'เลย ' + r.overdue"></b></span></template></span>
                                    </div>
                                </template>
                                <p x-show="dash.departments.length === 0" class="px-4 py-6 text-center text-sm text-slate-400">ยังไม่มีข้อมูลแผนก</p>
                            </div>
                        </div>

                        <div>
                            <h4 class="text-sm font-bold text-slate-900" x-text="'โปรเจคทั้งหมด (' + dash.projects.length + ')'"></h4>
                            <div class="mt-2 overflow-x-auto rounded-xl border border-slate-200">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-slate-50 text-xs text-slate-500">
                                        <tr>
                                            <th class="px-4 py-2 text-left font-semibold">โปรเจค</th>
                                            <th class="px-4 py-2 text-left font-semibold">วันครบกำหนด</th>
                                            <th class="px-4 py-2 text-left font-semibold">สถานะ</th>
                                            <th class="px-4 py-2 text-left font-semibold">แผนก</th>
                                            <th class="px-4 py-2 text-left font-semibold">เช็คลิสต์</th>
                                            <th class="px-4 py-2 text-right font-semibold">ตู้</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <template x-for="p in dash.projects" :key="p.project_id">
                                            <tr>
                                                <td class="px-4 py-2.5"><a :href="'{{ url('projects') }}/' + p.project_id" class="font-semibold text-blue-600 hover:underline" x-text="p.project_no"></a><p class="text-xs text-slate-500" x-text="p.project_name"></p></td>
                                                <td class="px-4 py-2.5 whitespace-nowrap">
                                                    <span x-text="fmtBE(p.due_date)"></span>
                                                    <p x-show="p.status === 'overdue' && p.days_remaining !== null" class="text-[11px] font-semibold text-red-600" x-text="'เลย ' + Math.abs(p.days_remaining) + ' วัน'"></p>
                                                </td>
                                                <td class="px-4 py-2.5"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :style="'background-color:' + statusColor(p.status) + '26;color:' + statusColor(p.status)" x-text="statusLabel(p.status)"></span></td>
                                                <td class="px-4 py-2.5 text-xs text-slate-600" x-text="p.departments.join(', ')"></td>
                                                <td class="px-4 py-2.5 whitespace-nowrap">
                                                    <div class="flex items-center gap-2 w-32"><div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden"><div class="h-1.5 rounded-full bg-emerald-500" :style="'width:' + p.checklist_pct + '%'"></div></div><span class="text-xs text-slate-500 tabular-nums" x-text="p.checklist_pct + '% · ' + p.checklist_done + '/' + p.checklist_total"></span></div>
                                                </td>
                                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums" x-text="p.cabinets"></td>
                                            </tr>
                                        </template>
                                        <tr x-show="dash.projects.length === 0"><td colspan="6" class="px-4 py-8 text-center text-slate-400">ยังไม่มีโปรเจคในขอบเขตนี้</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        @include('my-department.partials._project-modal')

        @include('my-department.partials._detail-panel')

        @include('my-department.partials._day-panel')
        @include('my-department.partials._project-panel')
    </div>
    @endif

    @push('scripts')
    @include('my-department.partials._script')
    @endpush
</x-app-layout>
