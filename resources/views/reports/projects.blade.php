{{-- "สรุปงานรายโครงการ" (reports/projects): App\Services\DailyWork by project ($data), a page of its own beside "สรุปงาน"
     (reports/today, which this file does not touch). One card per project: what came in and got done in the days shown,
     what it holds now and where, and its production % over time against the plan (App\Services\ProductionCurve), drawn
     as a line. Live like สรุปงาน: x-data="projectSummary" (resources/js/project-summary.js) builds on its component. --}}
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-slate-900">สรุปงานรายโครงการ</h1>
    </x-slot>

    @include('reports.partials._project-styles')

    @if ($noDepartment)
        <div class="mx-auto max-w-2xl rounded-xl border border-amber-200 bg-amber-50 p-6 text-base text-amber-900">
            บัญชีนี้ยังไม่ได้อยู่ในแผนกใด จึงยังไม่มีงานให้แสดง — ติดต่อผู้ดูแลระบบเพื่อกำหนดแผนก
        </div>
    @else
    <div x-data="projectSummary(@js(['summary' => $data, 'dataUrl' => route('reports.projects.data'), 'pageUrl' => route('reports.projects'), 'treeUrl' => route('reports.projects.tree', ['project' => '__ID__']), 'refreshSeconds' => $refreshSeconds]))"
         class="work-summary theme-switchgear -mx-2 flex flex-col gap-3 text-sm text-slate-800 sm:-mx-3 lg:-mx-4">

        {{-- the month shown: ‹ › a month back / on, or pick one (the months with projects due, and this one). The cards are the
             projects due in it; the numbers count that month (this month: so far) --}}
        <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
            <div class="flex items-center gap-1.5">
                <button type="button" @click="shiftMonth(-1)" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50" aria-label="เดือนก่อน">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <label class="relative flex h-11 items-center rounded-lg border border-slate-300 bg-white hover:bg-slate-50">
                    <svg class="pointer-events-none absolute left-3 h-5 w-5 text-slate-500" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <span class="sr-only">เลือกเดือน</span>
                    <select x-model="monthKey" @change="showMonth(monthKey)" class="h-full cursor-pointer appearance-none rounded-lg border-0 bg-transparent py-0 pl-10 pr-9 text-lg font-semibold text-slate-900 focus:ring-2 focus:ring-blue-600">
                        <template x-for="month in monthOptions()" :key="month.key">
                            <option :value="month.key" x-text="month.label" :selected="month.key === monthKey"></option>
                        </template>
                    </select>
                </label>
                <button type="button" @click="shiftMonth(1)" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50" aria-label="เดือนถัดไป">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
                <button type="button" x-show="monthKey !== summary.today.slice(0, 7)" x-cloak @click="showMonth(summary.today.slice(0, 7))" class="h-11 rounded-lg px-3 text-sm font-medium text-blue-700 hover:bg-blue-50">กลับเดือนนี้</button>
            </div>

            {{-- ทีมขาย and the order: one line, both remembered --}}
            <div class="flex items-center gap-2">
                <label class="sr-only" for="pj-team">ทีมขาย</label>
                <select id="pj-team" @change="setTeamFilter($event.target.value || null)" class="h-11 rounded-lg border-slate-300 bg-white py-0 pl-3 pr-8 text-sm font-medium text-slate-800 focus:border-blue-600 focus:ring-blue-600">
                    <option value="" :selected="teamFilter === null">ทุกทีม</option>
                    <template x-for="team in teams()" :key="team.key">
                        <option :value="team.key" :selected="teamFilter === team.key" x-text="team.label + ' (' + team.count + ')'"></option>
                    </template>
                </select>
                <label class="sr-only" for="pj-sort">เรียงตาม</label>
                <select id="pj-sort" x-model="sortBy" @change="saveSort()" class="h-11 rounded-lg border-slate-300 bg-white py-0 pl-3 pr-8 text-sm font-medium text-slate-800 focus:border-blue-600 focus:ring-blue-600">
                    <option value="due">เรียง: ใกล้ส่งก่อน</option>
                    <option value="activity">เรียง: เคลื่อนไหวล่าสุด</option>
                    <option value="progressLow">เรียง: % ผลิต น้อย → มาก</option>
                    <option value="progressHigh">เรียง: % ผลิต มาก → น้อย</option>
                    <option value="name">เรียง: ชื่อ ก → ฮ</option>
                </select>
            </div>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                {{-- live state: a word as well as a colour --}}
                <span class="inline-flex h-9 items-center gap-2 rounded-full px-3 font-medium" :class="liveBadgeClass()">
                    <span class="relative flex h-2.5 w-2.5" x-show="connection === 'ok' && summary.range.isLive">
                        <span class="absolute inline-flex h-full w-full rounded-full bg-emerald-500 opacity-75 motion-safe:animate-ping"></span>
                        <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-600"></span>
                    </span>
                    <span x-text="liveBadgeText()"></span>
                </span>
                <div class="hide-in-fullscreen inline-flex rounded-lg border border-slate-300 bg-white p-0.5" role="group" aria-label="รูปแบบการแสดง">
                    <button type="button" @click="setViewMode('weeks')" class="h-9 rounded-md px-3 font-medium" :class="viewMode === 'weeks' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'" :aria-pressed="viewMode === 'weeks'">สัปดาห์</button>
                    <button type="button" @click="setViewMode('cards')" class="h-9 rounded-md px-3 font-medium" :class="viewMode === 'cards' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'" :aria-pressed="viewMode === 'cards'">การ์ด</button>
                    <button type="button" @click="setViewMode('table')" class="h-9 rounded-md px-3 font-medium" :class="viewMode === 'table' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'" :aria-pressed="viewMode === 'table'">ตาราง</button>
                </div>
                <button type="button" @click="toggleFullscreen()" class="inline-flex h-10 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 font-medium text-slate-700 hover:bg-slate-50">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4h4M20 8V4h-4M4 16v4h4M20 16v4h-4"/></svg>
                    <span x-text="isFullscreen ? 'ออกจากเต็มจอ' : 'เต็มจอ'">เต็มจอ</span>
                </button>
            </div>
        </div>

        {{-- the month in one line: how many projects, how they stand (each a filter - tap again to show all), and how many
             moved; the projects shown follow it (statusFilter) --}}
        <div class="flex flex-wrap items-center gap-x-1.5 gap-y-2 rounded-xl border border-slate-200 bg-white px-2 py-2 shadow-[0_1px_2px_rgba(15,23,42,0.05)]" role="group" aria-label="กรองตามสถานะ">
            <button type="button" @click="setStatusFilter(null)" :aria-pressed="statusFilter === null"
                    class="inline-flex h-10 items-baseline gap-1.5 rounded-lg px-3 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600"
                    :class="statusFilter === null ? 'bg-slate-100' : 'hover:bg-slate-50'">
                <b class="self-center text-2xl font-semibold tabular-nums text-slate-900" x-count="baseRows().length">0</b>
                <span class="self-center text-sm text-slate-600">โครงการ</span>
            </button>
            <span class="mx-1 hidden h-6 w-px bg-slate-200 sm:block" aria-hidden="true"></span>
            <template x-for="status in statusCounts()" :key="status.key">
                <button type="button" @click="setStatusFilter(status.key)" :aria-pressed="statusFilter === status.key" :disabled="!status.count"
                        class="inline-flex h-10 items-center gap-2 rounded-lg px-3 text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 disabled:cursor-default disabled:opacity-40"
                        :class="statusFilter === status.key ? 'bg-slate-100 ring-1 ring-inset ring-slate-300' : 'enabled:hover:bg-slate-50'">
                    <span class="lamp h-2.5 w-2.5 rounded-full" :class="status.dot" aria-hidden="true"></span>
                    <span class="text-slate-700" x-text="status.label"></span>
                    <b class="font-semibold tabular-nums text-slate-900" x-text="status.count"></b>
                </button>
            </template>
            <button type="button" @click="setStatusFilter('moving')" :aria-pressed="statusFilter === 'moving'" :disabled="!movingCount()"
                    class="inline-flex h-10 items-center gap-2 rounded-lg px-3 text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 disabled:cursor-default disabled:opacity-40 sm:ml-auto"
                    :class="statusFilter === 'moving' ? 'bg-slate-100 ring-1 ring-inset ring-slate-300' : 'enabled:hover:bg-slate-50'">
                <span class="relative flex h-2.5 w-2.5" aria-hidden="true">
                    <span x-show="summary.range.isLive" class="live-ripple absolute inline-flex h-full w-full rounded-full bg-emerald-500 opacity-75 motion-safe:animate-ping"></span>
                    <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-600"></span>
                </span>
                <span class="text-slate-700" x-text="'มีงานเดิน' + periodWord()"></span>
                <b class="font-semibold tabular-nums text-slate-900" x-text="movingCount()"></b>
            </button>
        </div>

        <div class="grid gap-4" :class="viewMode === 'table' ? 'xl:grid-cols-[minmax(0,1fr)_380px]' : ''">
            {{-- per project --}}
            <section class="min-w-0">
                <p x-show="!monthRows().length" x-cloak class="mb-3 rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">ไม่มีโครงการที่ครบกำหนดส่งในเดือนนี้</p>
                <p x-show="monthRows().length && (teamFilter !== null || statusFilter !== null) && !shownRows().length" x-cloak class="mb-3 rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">ทีมนี้ไม่มีโครงการในช่วงที่เลือก — <button type="button" @click="setTeamFilter(null)" class="font-medium text-blue-700 underline underline-offset-4">ดูทั้งหมด</button></p>

                {{-- every project as a small card (the production line against the plan, the due date). The cards never move: a tap
                     opens the project in the panel on the right (#project-panel), another card swaps the panel's project, a
                     click outside it (or Esc) closes it.
                     Projects with nothing in the days shown and nothing held are faded (they come last) --}}
                {{-- สัปดาห์: the month as weeks (Monday to Sunday, cut at the month's ends), like the dashboard's week board - each
                     column the projects due in it, this week standing out; a project opens in the panel (weeksOfMonth) --}}
                <div x-show="viewMode === 'weeks'" class="-mx-1 overflow-x-auto px-1 pb-2">
                    <div class="grid gap-3" :style="{ gridTemplateColumns: 'repeat(' + weeksOfMonth().length + ', minmax(13.5rem, 1fr))' }">
                        <template x-for="week in weeksOfMonth()" :key="week.key">
                            <section class="flex min-w-0 flex-col rounded-xl border"
                                     :class="week.isCurrent ? 'border-slate-900 bg-white shadow-[0_0_0_1px_#0f172a]' : (week.isPast ? 'border-slate-200 bg-slate-50/70' : 'border-slate-200 bg-slate-50')">
                                <header class="flex items-baseline justify-between gap-2 rounded-t-xl border-b px-3 py-2.5"
                                        :class="week.isCurrent ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200'">
                                    <h4 class="min-w-0">
                                        <span class="block text-sm font-semibold" :class="week.isCurrent ? 'text-white' : (week.isPast ? 'text-slate-500' : 'text-slate-900')" x-text="'สัปดาห์ที่ ' + week.number + (week.isCurrent ? ' · สัปดาห์นี้' : '')"></span>
                                        <span class="block text-xs tabular-nums" :class="week.isCurrent ? 'text-slate-300' : 'text-slate-500'" x-text="week.label"></span>
                                    </h4>
                                    <span class="shrink-0 text-xs tabular-nums" :class="week.isCurrent ? 'text-slate-300' : 'text-slate-500'" x-text="week.rows.length ? week.rows.length + ' โครงการ' : ''"></span>
                                </header>
                                <ul class="flex flex-col gap-2 p-2">
                                    <template x-for="department in week.rows" :key="'week-' + department.id">
                                        <li>
                                            <button type="button" data-project-chip :data-chip-id="department.id" @click="toggleExpanded(department.id)"
                                                    :aria-expanded="isExpanded(department.id)" aria-controls="project-panel" :aria-label="department.name + ' ' + department.projectNo + ' — ' + curveDescription(department)"
                                                    class="relative w-full rounded-lg border bg-white px-2.5 py-2 text-left shadow-[0_1px_2px_rgba(15,23,42,0.05)] transition hover:border-slate-300 hover:shadow-[0_4px_10px_-4px_rgba(15,23,42,0.18)] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600"
                                                    :class="[isExpanded(department.id) ? 'border-blue-600 ring-2 ring-blue-600' : 'border-slate-200', highlightedDepartments[department.id] ? 'bg-blue-50' : '', department.isQuiet && !isExpanded(department.id) ? 'opacity-75' : '']">
                                                <span class="flex min-w-0 items-center gap-1.5 text-xs text-slate-500">
                                                    <span class="shrink-0 font-medium tabular-nums" x-text="department.projectNo"></span>
                                                    <span x-show="department.team" class="truncate rounded-full bg-[#eef2ff] px-1.5 font-medium text-[#3730a3] ring-1 ring-inset ring-[#c7d2fe]" x-text="department.team"></span>
                                                    {{-- งานขยับ (count badge, the card's top-right corner): Checklist items ticked since the count was last reset - it runs
                                                         up as the live refresh brings more; a tap starts it again from 0 (markSeen), without opening the project --}}
                                                    <span x-show="badgeTicks(department) > 0" role="button" tabindex="0" @click.stop="markSeen(department.id)" @keydown.enter.stop.prevent="markSeen(department.id)" @keydown.space.stop.prevent="markSeen(department.id)"
                                                          class="count-badge absolute -right-2 -top-2 z-10 flex h-6 min-w-[1.5rem] cursor-pointer items-center justify-center rounded-full bg-slate-900 px-1.5 text-xs font-bold tabular-nums text-white shadow-[0_2px_6px_rgba(15,23,42,0.25)] ring-2 ring-white transition hover:scale-110 focus:outline-none focus-visible:ring-blue-600 motion-reduce:hover:scale-100"
                                                          :class="highlightedDepartments[department.id] ? 'scale-110' : ''" :title="movementText(department) + ' · กดเพื่อเริ่มนับใหม่'">
                                                        <span x-count="badgeTicks(department)">0</span>
                                                        <span class="sr-only" x-text="'งานขยับ ' + badgeTicks(department) + ' งาน · ' + movementText(department) + ' กดเพื่อเริ่มนับใหม่'"></span>
                                                    </span>
                                                </span>
                                                <span class="mt-0.5 line-clamp-2 text-sm font-semibold leading-snug text-slate-900" :title="department.name" x-text="department.name"></span>
                                                <span class="mt-2 flex items-center gap-2">
                                                    <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><span class="bar-grow block h-full rounded-full" :style="{ width: Math.min(100, department.progress) + '%', background: curveColor(department) }"></span></span>
                                                    <span class="text-xs font-semibold tabular-nums text-slate-900" x-text="department.progress + '%'"></span>
                                                </span>
                                                <span class="mt-1 flex items-center justify-between gap-2 text-xs tabular-nums">
                                                    <span :class="{ late: 'font-semibold text-red-700', soon: 'font-semibold text-orange-700', none: 'text-slate-500' }[department.dueTone]" x-text="'ส่ง ' + dueShort(department) + (department.daysLeft !== null && department.daysLeft < 0 ? ' · เลย ' + (-department.daysLeft) + ' วัน' : '')"></span>
                                                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset" :class="statusOf(department).pill" x-text="statusOf(department).word"></span>
                                                </span>
                                            </button>
                                        </li>
                                    </template>
                                    <li x-show="!week.rows.length" class="px-1 py-3 text-center text-xs text-slate-400">ไม่มีโครงการส่ง</li>
                                </ul>
                            </section>
                        </template>
                    </div>
                </div>

                {{-- แบ่งตามเดือน (groupBy = month): a heading per month of the due date - this month marked, each folds - then the
                     projects waiting for a date; ไม่แบ่ง: one grid. The team filter and the order work inside each group. --}}
                <div x-show="viewMode === 'cards'" class="space-y-5">
                    <template x-for="group in groupedRows()" :key="group.key">
                        <section>
                            <button type="button" x-show="group.label" @click="toggleGroup(group.key)" :aria-expanded="!isFolded(group.key)"
                                    class="mb-2 flex w-full items-center gap-2 rounded-lg py-1 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600">
                                <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200" :class="isFolded(group.key) ? '' : 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                <h4 class="text-base font-semibold text-slate-900" x-text="group.label"></h4>
                                <span x-show="group.isCurrent" class="rounded-full bg-slate-900 px-2 py-0.5 text-xs font-semibold text-white">เดือนนี้</span>
                                <span class="text-sm tabular-nums text-slate-500" x-text="group.rows.length + ' โครงการ' + (group.average !== null ? ' · ผลิตเฉลี่ย ' + group.average + '%' : '')"></span>
                                <span class="ml-2 h-px flex-1 bg-slate-200" aria-hidden="true"></span>
                            </button>
                <div x-show="!isFolded(group.key)" class="grid items-start gap-3 [grid-template-columns:repeat(auto-fill,minmax(16rem,1fr))]">
                    <template x-for="(department, index) in group.rows" :key="department.id">
                        <div class="min-w-0" :style="'--i:' + index">
                            <div :id="'project-chip-' + department.id" :data-chip-id="department.id" data-project-chip @click="toggleExpanded(department.id)" @keydown.enter.prevent="toggleExpanded(department.id)" @keydown.space.prevent="toggleExpanded(department.id)"
                                 role="button" tabindex="0" :aria-expanded="isExpanded(department.id)" aria-controls="project-panel" :aria-label="department.projectNo + ' ' + department.name + ' — กดเพื่อดูรายละเอียด'"
                                 class="relative flex cursor-pointer flex-col rounded-xl border bg-white p-3.5 shadow-[0_1px_2px_rgba(15,23,42,0.05)] transition duration-200 hover:-translate-y-px hover:shadow-[0_6px_16px_-6px_rgba(15,23,42,0.18)] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 motion-reduce:hover:translate-y-0"
                                 :class="[isExpanded(department.id) ? 'border-blue-600 ring-2 ring-blue-600' : 'border-slate-200 hover:border-slate-300', highlightedDepartments[department.id] ? 'bg-blue-50' : '', department.isQuiet && !isExpanded(department.id) ? 'opacity-75' : '']">
                                {{-- name (and the live dot), then how far and how it stands in one word, then when --}}
                                <div class="flex items-start gap-2">
                                    <span class="min-w-0 flex-1">
                                        <span class="line-clamp-2 text-[15px] font-semibold leading-snug text-ae-navy" :title="department.name" x-text="department.name"></span>
                                        <span class="mt-0.5 flex min-w-0 items-center gap-1.5 text-xs text-slate-500">
                                            <span class="shrink-0 tabular-nums" x-text="department.projectNo"></span>
                                            <span x-show="department.team" class="truncate rounded-full bg-[#eef2ff] px-1.5 font-medium text-[#3730a3] ring-1 ring-inset ring-[#c7d2fe]" x-text="department.team"></span>
                                        </span>
                                    </span>
                                    {{-- งานขยับ (count badge, the card's top-right corner): Checklist items ticked since the count was last reset - it runs
                                         up as the live refresh brings more; a tap starts it again from 0 (markSeen), without opening the project --}}
                                    <span x-show="badgeTicks(department) > 0" role="button" tabindex="0" @click.stop="markSeen(department.id)" @keydown.enter.stop.prevent="markSeen(department.id)" @keydown.space.stop.prevent="markSeen(department.id)"
                                          class="count-badge absolute -right-2 -top-2 z-10 flex h-6 min-w-[1.5rem] cursor-pointer items-center justify-center rounded-full bg-slate-900 px-1.5 text-xs font-bold tabular-nums text-white shadow-[0_2px_6px_rgba(15,23,42,0.25)] ring-2 ring-white transition hover:scale-110 focus:outline-none focus-visible:ring-blue-600 motion-reduce:hover:scale-100"
                                          :class="highlightedDepartments[department.id] ? 'scale-110' : ''" :title="movementText(department) + ' · กดเพื่อเริ่มนับใหม่'">
                                        <span x-count="badgeTicks(department)">0</span>
                                        <span class="sr-only" x-text="'งานขยับ ' + badgeTicks(department) + ' งาน · ' + movementText(department) + ' กดเพื่อเริ่มนับใหม่'"></span>
                                    </span>
                                </div>
                                <div class="mt-3 flex items-center gap-2.5">
                                    <p class="leading-none"><span class="text-3xl font-semibold tabular-nums tracking-tight text-slate-900" x-count="department.progress"></span><span class="text-base font-semibold text-slate-500">%</span></p>
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset" :class="statusOf(department).pill"><span class="lamp h-2 w-2 rounded-full" :class="{ done: 'bg-teal-700', late: 'bg-red-600', behind: 'bg-orange-500', ok: 'bg-slate-400' }[statusOf(department).key]" aria-hidden="true"></span><span x-text="statusOf(department).text"></span></span>
                                </div>
                                {{-- the chart as in the panel: % made (the line) against the plan (dashed), ticks per day (bars),
                                     the due date, today's dot, the dates under it; pointing reads a day --}}
                                <div class="mt-3 rounded-lg bg-slate-50 px-2.5 pb-1.5 pt-2 ring-1 ring-inset ring-slate-100">
                                <div class="relative" @mousemove="hoverAt($event, department, 'card')" @mouseleave="hover = null">
                                <svg class="block h-[72px] w-full overflow-visible" viewBox="0 0 300 72" preserveAspectRatio="none" role="img" :aria-label="curveDescription(department)">
                                    <line x-show="axisMark(department.curve, 'card') && axisMark(department.curve, 'card').kind === 'today'" :x1="markX(department.curve, 'card')" :x2="markX(department.curve, 'card')" y1="0" :y2="CHART_H" stroke="#94a3b8" stroke-dasharray="1 3" vector-effect="non-scaling-stroke"/>
                                    <line x1="0" :x2="CHART_W" :y1="chartY(50)" :y2="chartY(50)" stroke="#e2e8f0" stroke-dasharray="2 3" vector-effect="non-scaling-stroke"/>
                                    <line x1="0" :x2="CHART_W" :y1="chartY(100)" :y2="chartY(100)" stroke="#e2e8f0" vector-effect="non-scaling-stroke"/>
                                    <line x1="0" :x2="CHART_W" :y1="chartY(0)" :y2="chartY(0)" stroke="#cbd5e1" vector-effect="non-scaling-stroke"/>
                                    <line x-show="department.curve.dueDay !== null && department.curve.dueDay > 0" :x1="chartX(department.curve, department.curve.dueDay || 0)" :x2="chartX(department.curve, department.curve.dueDay || 0)" y1="0" :y2="CHART_H" stroke="#94a3b8" stroke-dasharray="3 3" vector-effect="non-scaling-stroke"/>
                                    <path class="bars-rise" :d="barsPath(department.curve, 'past')" :fill="tickColor(department)" fill-opacity="0.35"/><path class="bars-rise" :d="barsPath(department.curve, 'today')" :fill="tickColor(department)" fill-opacity="0.85"/>
                                    <polyline x-show="department.curve.dueDay !== null" class="curve-plan" :points="planPoints(department.curve)" fill="none" style="stroke: var(--pj-plan)" stroke-width="1.5" stroke-dasharray="5 4" vector-effect="non-scaling-stroke"/>
                                    <g class="curve-run">
                                        <polyline :points="actualPoints(department.curve)" fill="none" :stroke="curveColor(department)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
                                    </g>
                                </svg>
                                {{-- today's dot: HTML, so it stays round however wide the card is --}}
                                <div class="relative -mt-[72px] h-[72px]" aria-hidden="true">
                                    <span class="dot-pop absolute h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full shadow-[0_1px_2px_rgba(15,23,42,0.25)] ring-2 ring-white" :style="'background:' + curveColor(department) + ';left:' + (chartX(department.curve, department.curve.todayDay) / CHART_W * 100) + '%;top:' + (chartY(department.progress) / CHART_H * 100) + '%'"></span>
                                </div>
                                {{-- pointing at the chart: the day under the pointer, its % made (and planned) and its ticks --}}
                                <span x-show="hovering(department, 'card')" x-cloak class="pointer-events-none absolute top-0 h-[72px] w-px bg-slate-400" :style="{ left: (hover ? hover.x : 0) + '%' }" aria-hidden="true"></span>
                                <span x-show="hovering(department, 'card')" x-cloak class="pointer-events-none absolute bottom-full z-20 mb-1 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs text-white shadow-lg"
                                      :style="{ left: (hover ? hover.x : 0) + '%', transform: 'translateX(-' + (hover ? hover.x : 0) + '%)' }" x-text="hover ? hover.text : ''"></span>
                                </div>
                                {{-- the dates under the chart: its start, then today or the due date where they fall, then its end --}}
                                <div class="relative mt-1 h-4 text-xs tabular-nums text-slate-500" aria-hidden="true">
                                    <span class="absolute left-0" x-text="department.curve.startLabel"></span>
                                    <span x-show="axisMark(department.curve, 'card')" class="absolute -translate-x-1/2 whitespace-nowrap font-semibold text-slate-700" :style="{ left: (axisMark(department.curve, 'card')?.pct || 0) + '%' }" x-text="axisMark(department.curve, 'card')?.label"></span>
                                    <span class="absolute right-0 font-semibold text-slate-700" x-text="department.curve.endLabel"></span>
                                </div>
                                </div>
                                <p class="mt-2 text-sm tabular-nums" :class="{ late: 'font-semibold text-red-700', soon: 'font-semibold text-orange-700', none: 'text-slate-600' }[department.dueTone]"
                                   x-text="'ส่ง ' + dueShort(department) + (department.daysLeft === null ? '' : (department.daysLeft < 0 ? ' · เลย ' + (-department.daysLeft) + ' วัน' : (department.daysLeft === 0 ? ' · วันนี้' : ' · อีก ' + department.daysLeft + ' วัน')))"></p>
                            </div>
                        </div>
                    </template>
                </div>
                        </section>
                    </template>
                </div>

                <div x-show="viewMode === 'table'" x-cloak class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                    <table class="w-full min-w-[720px] text-left text-sm tabular-nums">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-4 py-3 font-semibold">โครงการ</th>
                                <th class="px-3 py-3 font-semibold">ทีมขาย</th>
                                <th class="px-3 py-3 text-right font-semibold">ผลิต</th>
                                <th class="px-3 py-3 font-semibold">กำหนดส่ง</th>
                                <th class="px-3 py-3 text-right font-semibold">งานเข้า</th>
                                <th class="px-3 py-3 text-right font-semibold">ตู้</th>
                                <th class="px-3 py-3 text-right font-semibold">เสร็จ</th>
                                <th class="px-3 py-3 text-right font-semibold">ตู้</th>
                                <th class="px-3 py-3 text-right font-semibold">รอรับ</th>
                                <th class="px-3 py-3 text-right font-semibold">กำลังทำ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="department in shownRows()" :key="department.id">
                                <tr :class="[department.isQuiet ? 'text-slate-400' : '', highlightedDepartments[department.id] ? 'bg-blue-50' : '']">
                                    <td class="max-w-[18rem] px-4 py-2.5">
                                        <a :href="department.url" class="block truncate font-semibold text-ae-navy hover:underline" :title="department.name" x-text="department.name"></a>
                                        <p class="text-xs tabular-nums" :class="department.isQuiet ? '' : 'text-slate-500'" x-text="department.projectNo"></p>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2.5"><span x-show="department.team" class="rounded-full bg-[#eef2ff] px-2 py-0.5 text-xs font-medium text-[#3730a3] ring-1 ring-inset ring-[#c7d2fe]" x-text="department.team"></span><span x-show="!department.team" class="text-slate-400">-</span></td>
                                    <td class="px-3 py-2.5 text-right" x-text="department.progress + '%'"></td>
                                    <td class="whitespace-nowrap px-3 py-2.5" :class="{ late: 'font-semibold text-red-700', soon: 'font-semibold text-orange-700', none: '' }[department.dueTone]"
                                        x-text="department.dueDate + (department.daysLeft !== null && department.daysLeft < 0 ? ' (เลย ' + (-department.daysLeft) + ' วัน)' : '')"></td>
                                    <td class="px-3 py-2.5 text-right text-base font-semibold" x-text="department.received"></td>
                                    <td class="px-3 py-2.5 text-right" x-text="department.receivedCabinets"></td>
                                    <td class="px-3 py-2.5 text-right text-base font-semibold" x-text="department.completed"></td>
                                    <td class="px-3 py-2.5 text-right" x-text="department.completedCabinets"></td>
                                    <td class="px-3 py-2.5 text-right" x-text="department.waiting"></td>
                                    <td class="px-3 py-2.5 text-right" x-text="department.inProgress"></td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot class="border-t-2 border-slate-200 bg-slate-50 font-semibold text-slate-900">
                            <tr>
                                <td class="px-4 py-3" colspan="4">รวม</td>
                                <td class="px-3 py-3 text-right text-base" x-text="summary.totals.received"></td>
                                <td class="px-3 py-3 text-right" x-text="summary.totals.receivedCabinets"></td>
                                <td class="px-3 py-3 text-right text-base" x-text="summary.totals.completed"></td>
                                <td class="px-3 py-3 text-right" x-text="summary.totals.completedCabinets"></td>
                                <td class="px-3 py-3 text-right" x-text="summary.totals.waiting"></td>
                                <td class="px-3 py-3 text-right" x-text="summary.totals.inProgress"></td>
                            </tr>
                        </tfoot>
                    </table>
                    <p class="border-t border-slate-100 px-4 py-2 text-sm text-slate-500">
                            งานเข้า / เสร็จ / ค้าง = งานของโครงการนั้นในทุกแผนก · ผลิต = ความคืบหน้าเฉลี่ยของตู้ · ตู้ = จำนวนตู้ของงานนั้น (นับตู้ละครั้ง)
                    </p>
                </div>

                <p x-show="!summary.departments.length" x-cloak class="rounded-xl border border-slate-200 bg-white p-6 text-center text-base text-slate-500">ยังไม่มีโครงการที่กำลังดำเนินการ</p>
            </section>

            {{-- what happened, newest first --}}
            <section x-show="viewMode === 'table'" x-cloak class="flex min-w-0 flex-col rounded-xl border border-slate-200 bg-white shadow-sm xl:max-h-[calc(100vh-9rem)]">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
                    <h3 class="text-base font-semibold text-slate-900" x-text="'ความเคลื่อนไหว' + periodWord()"></h3>
                    <button type="button" x-show="feedFilter" x-cloak @click="feedFilter = null" class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-3 py-1 text-sm font-medium text-blue-700 hover:bg-blue-100">
                        <span x-text="'เฉพาะ ' + feedFilterName()"></span><span aria-hidden="true">✕</span><span class="sr-only">แสดงทั้งหมด</span>
                    </button>
                </header>
                <ol class="flex-1 divide-y divide-slate-100 overflow-y-auto">
                    {{-- one heading per run of the same project, its events under it --}}
                    <template x-for="projectGroup in feedByProject()" :key="projectGroup.key">
                        <li class="py-2">
                            <div class="z-10 flex xl:sticky xl:top-0 items-baseline gap-2 bg-white/95 px-4 pb-1 pt-1 backdrop-blur">
                                <h4 class="line-clamp-2 min-w-0 flex-1 text-base font-semibold leading-snug text-slate-900" :title="projectGroup.projectName" x-text="projectGroup.projectName || projectGroup.projectNo || 'ไม่ระบุโครงการ'"></h4>
                                <span x-show="projectGroup.projectName && projectGroup.projectNo" class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-medium tabular-nums text-slate-600" x-text="projectGroup.projectNo"></span>
                            </div>
                            <ul>
                                <template x-for="event in projectGroup.events" :key="event.key">
                                    <li class="transition-colors duration-700" :class="highlightedEvents[event.key] ? 'bg-blue-50' : ''">
                                        <a :href="event.url" @click="acknowledge(event)" @auxclick="acknowledge(event)" class="flex gap-3 px-4 py-1.5 hover:bg-slate-50 focus-visible:bg-slate-50 focus-visible:outline-none">
                                            <span class="shrink-0 pt-0.5 text-sm tabular-nums text-slate-500" :class="event.date ? 'w-14' : 'w-11'" :title="event.time ? '' : 'ไม่ทราบเวลา'">
                                                <span x-show="event.date" class="block text-xs font-medium text-slate-600" x-text="event.date"></span>
                                                <span x-text="event.time || '—'"></span>
                                            </span>
                                            <div class="min-w-0 flex-1 text-sm">
                                                <p class="flex flex-wrap items-center gap-x-2">
                                                    <span class="rounded px-1.5 py-0.5 font-semibold" :class="eventTagClass(event.type)" x-text="event.typeLabel"></span>
                                                    <span x-show="event.isNew && event.type === 'received'" class="rounded-full bg-blue-600 px-2 py-0.5 text-xs font-semibold text-white" title="ยังไม่มีใครเปิดดูงานนี้">ใหม่</span>
                                                    <span x-show="event.departmentId" class="font-medium text-slate-700" x-text="departmentName(event.departmentId)"></span>
                                                </p>
                                                <p class="mt-0.5 truncate text-slate-500" :title="eventDetail(event)" x-text="eventDetail(event)"></p>
                                            </div>
                                        </a>
                                    </li>
                                </template>
                            </ul>
                        </li>
                    </template>
                    <li x-show="!feedFilter && summary.workEventCount > listedWorkEventCount()" x-cloak class="px-4 py-3 text-center text-sm text-slate-500"
                        x-text="'แสดงงาน ' + listedWorkEventCount() + ' รายการล่าสุด จากทั้งหมด ' + summary.workEventCount + ' รายการ' + (summary.range.dayCount > 1 ? ' — เลือกช่วงสั้นลงเพื่อดูละเอียดขึ้น' : '')"></li>
                    <li x-show="!filteredFeed().length" class="px-4 py-10 text-center text-base text-slate-500" x-text="emptyFeedText()"></li>
                </ol>
            </section>
        </div>

        @include('reports.partials._project-panel')

    </div>
    @endif

</x-app-layout>
