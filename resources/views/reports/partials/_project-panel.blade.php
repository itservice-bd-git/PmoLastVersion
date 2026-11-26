{{-- The project panel (reports/projects, and the dashboard's สัปดาห์นี้): the chosen project in full - heading, the production
     chart, its facts, its delivery, the work of the days shown, its latest moves, its cabinets to checklist. Needs an
     x-data of projectSummary (resources/js/project-summary.js) around it, or projectPanelHost on the dashboard. --}}
        {{-- the chosen project, in full: slides in from the right over the cards, which stay where they are. Another card swaps
             what it shows (a short fade); a click outside it, the close button or Esc closes it --}}
        <aside id="project-panel" x-show="expandedId !== null" x-cloak role="dialog" aria-modal="false" aria-label="รายละเอียดโครงการ" tabindex="-1"
               @click.outside="closeFromOutside($event)" @keydown.escape.window="closePanel()"
               x-transition:enter="transform transition ease-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
               x-transition:leave="transform transition ease-in duration-200" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
               class="fixed inset-y-0 right-0 z-50 flex w-full flex-col border-l sm:max-w-[560px] lg:max-w-[min(880px,calc(100vw-5rem))] border-slate-200 bg-white shadow-[-12px_0_32px_-12px_rgba(15,23,42,0.22)] focus:outline-none">
            <button type="button" @click="closePanel()" aria-label="ปิด" title="ปิด (Esc)"
                    class="absolute right-3 top-3 z-20 flex h-10 w-10 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div class="flex-1 overflow-y-auto overscroll-contain">
                {{-- the dashboard: open at once, its project on its way --}}
                <p x-show="typeof loadingOne !== 'undefined' && loadingOne && !selectedRows().length" class="flex items-center gap-2 px-5 py-10 text-sm text-slate-500" role="status">
                    <svg class="h-4 w-4 animate-spin text-slate-400" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                    กำลังโหลดข้อมูลโครงการ…
                </p>
                <template x-for="department in selectedRows()" :key="department.id">
                    <article class="project-card-full flex min-h-full flex-col px-5 pb-6" :class="highlightedDepartments[department.id] ? 'bg-blue-50' : ''">
                            <header class="sticky top-0 z-10 -mx-5 flex items-start gap-3 border-b border-slate-100 bg-white/95 px-5 pb-3 pr-14 pt-4 backdrop-blur">
                                    <div class="min-w-0 flex-1">
                                        <h4 class="text-xl font-semibold leading-snug text-ae-navy" :title="department.name" x-text="department.name"></h4>
                                        <p class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <a :href="department.url" class="text-sm font-semibold tabular-nums text-slate-600 underline-offset-4 hover:text-ae-navy hover:underline" x-text="department.projectNo"></a>
                                            <span x-show="department.team" class="rounded-full bg-[#eef2ff] px-2 py-0.5 text-xs font-medium text-[#3730a3] ring-1 ring-inset ring-[#c7d2fe]" x-text="department.team"></span>
                                            <span x-show="department.statusLabel" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600" x-text="department.statusLabel"></span>
                                        </p>
                                        <p x-show="department.customer" class="mt-0.5 text-sm text-slate-500" :title="department.customer" x-text="department.customer"></p>
                                    </div>
                                    <a :href="department.url" class="hidden h-9 shrink-0 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-ae-navy transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 sm:inline-flex">เปิดหน้าโครงการ</a>
                            </header>

                            {{-- two columns on a wide panel: how it stands (the chart, the facts, the work now, the latest movements) |
                                 what is in it (cabinets to checklist); one column, in that order, when narrow --}}
                            <div class="lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:gap-x-8">
                                <div class="min-w-0">
                                {{-- production % over time (solid, filled) against the plan (dashed: the start to 100% on the due date);
                                     the dot is today. The words say it too, so the colour is never the only sign --}}
                                <div class="mt-4 rounded-xl bg-slate-50 px-3.5 pb-2.5 pt-3 ring-1 ring-inset ring-slate-100">
                                    <div class="flex items-end justify-between gap-3">
                                        <p class="leading-none">
                                            <span class="block text-sm text-slate-600">ผลิต</span>
                                            <span class="text-4xl font-semibold tabular-nums tracking-tight text-slate-900" x-count="department.progress"></span><span class="ml-0.5 text-lg font-semibold text-slate-500">%</span><span x-show="department.curve.gain" class="ml-1.5 inline-flex items-center gap-0.5 rounded-full px-1.5 py-px text-xs font-semibold tabular-nums align-middle" :class="department.curve.gain > 0 ? 'bg-teal-50 text-teal-700' : 'bg-slate-100 text-slate-600'" :title="gainText(department)"><template x-if="department.curve.gain > 0"><span class="contents"><svg class="h-3 w-3" fill="currentColor" viewBox="0 0 12 12" aria-hidden="true"><path d="M6 2.5l4 5H2z"/></svg></span></template><template x-if="department.curve.gain < 0"><span class="contents"><svg class="h-3 w-3" fill="currentColor" viewBox="0 0 12 12" aria-hidden="true"><path d="M6 9.5l-4-5h8z"/></svg></span></template><span x-text="Math.abs(department.curve.gain) + '%'"></span><span class="sr-only" x-text="gainText(department)"></span></span>
                                        </p>
                                        <p class="text-right text-sm leading-snug">
                                            <span class="block tabular-nums" :class="{ late: 'font-semibold text-red-700', soon: 'font-semibold text-orange-700', none: 'text-slate-600' }[department.dueTone]" x-text="'ส่ง ' + department.dueDate"></span>
                                            <span x-show="department.daysLeft !== null" class="block tabular-nums" :class="{ late: 'font-semibold text-red-700', soon: 'font-semibold text-orange-700', none: 'text-slate-500' }[department.dueTone]"
                                                  x-text="department.daysLeft < 0 ? 'เลย ' + (-department.daysLeft) + ' วัน' : (department.daysLeft === 0 ? 'ครบกำหนดวันนี้' : 'อีก ' + department.daysLeft + ' วัน')"></span>
                                        </p>
                                    </div>
                                    {{-- against the plan: a word as well as a colour --}}
                                    <p class="mt-2 inline-flex rounded-full px-2.5 py-0.5 text-sm font-medium"
                                       :class="{ done: 'bg-teal-50 text-teal-800', behind: 'bg-red-50 text-red-700', slow: 'bg-orange-50 text-orange-800', ok: 'bg-[#eef2f7] text-ae-navy', none: 'bg-slate-100 text-slate-600' }[curveTone(department)]" x-text="curveText(department)"></p>
                                    <div class="relative mt-2" @mousemove="hoverAt($event, department, 'panel')" @mouseleave="hover = null">
                                    <svg class="block h-[72px] w-full overflow-visible" viewBox="0 0 300 72" preserveAspectRatio="none" role="img" :aria-label="curveDescription(department)">
                                        <line x-show="axisMark(department.curve, 'panel') && axisMark(department.curve, 'panel').kind === 'today'" :x1="markX(department.curve, 'panel')" :x2="markX(department.curve, 'panel')" y1="0" :y2="CHART_H" stroke="#94a3b8" stroke-dasharray="1 3" vector-effect="non-scaling-stroke"/>
                                        <line x1="0" :x2="CHART_W" :y1="chartY(50)" :y2="chartY(50)" stroke="#e2e8f0" stroke-dasharray="2 3" vector-effect="non-scaling-stroke"/>
                                        <line x1="0" :x2="CHART_W" :y1="chartY(100)" :y2="chartY(100)" stroke="#e2e8f0" vector-effect="non-scaling-stroke"/>
                                        <line x1="0" :x2="CHART_W" :y1="chartY(0)" :y2="chartY(0)" stroke="#cbd5e1" vector-effect="non-scaling-stroke"/>
                                        <line x-show="department.curve.dueDay !== null && department.curve.dueDay > 0" :x1="chartX(department.curve, department.curve.dueDay || 0)" :x2="chartX(department.curve, department.curve.dueDay || 0)" y1="0" :y2="CHART_H" stroke="#94a3b8" stroke-dasharray="3 3" vector-effect="non-scaling-stroke"/>
                                        <path class="bars-rise" :d="barsPath(department.curve, 'past')" :fill="tickColor(department)" fill-opacity="0.35"/><path class="bars-rise" :d="barsPath(department.curve, 'today')" :fill="tickColor(department)" fill-opacity="0.85"/>
                                        <polyline x-show="department.curve.dueDay !== null" class="curve-plan" :points="planPoints(department.curve)" fill="none" style="stroke: var(--pj-plan)" stroke-width="1.5" stroke-dasharray="5 4" vector-effect="non-scaling-stroke"/>
                                        <g class="curve-run">
                                            <polyline :points="actualPoints(department.curve)" fill="none" :stroke="panelLineColor(department)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
                                        </g>
                                    </svg>
                                    {{-- today's dot: HTML, so it stays round however wide the card is --}}
                                    <div class="relative -mt-[72px] h-[72px]" aria-hidden="true">
                                        <span class="dot-pop absolute h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full shadow-[0_1px_2px_rgba(15,23,42,0.25)] ring-2 ring-white" :style="'background:' + panelLineColor(department) + ';left:' + (chartX(department.curve, department.curve.todayDay) / CHART_W * 100) + '%;top:' + (chartY(department.progress) / CHART_H * 100) + '%'"></span>
                                    </div>
                                    {{-- pointing at the chart: the day under the pointer, its % made (and planned) and its ticks --}}
                                    <span x-show="hovering(department, 'panel')" x-cloak class="pointer-events-none absolute top-0 h-[72px] w-px bg-slate-400" :style="{ left: (hover ? hover.x : 0) + '%' }" aria-hidden="true"></span>
                                    <span x-show="hovering(department, 'panel')" x-cloak class="pointer-events-none absolute bottom-full z-20 mb-1 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs text-white shadow-lg"
                                          :style="{ left: (hover ? hover.x : 0) + '%', transform: 'translateX(-' + (hover ? hover.x : 0) + '%)' }" x-text="hover ? hover.text : ''"></span>
                                    </div>
                                    {{-- the dates under the chart: its start, then today or the due date where they fall, then its end --}}
                                    <div class="relative mt-1 h-4 text-xs tabular-nums text-slate-500" aria-hidden="true">
                                        <span class="absolute left-0" x-text="department.curve.startLabel"></span>
                                        <span x-show="axisMark(department.curve, 'panel')" class="absolute -translate-x-1/2 whitespace-nowrap font-semibold text-slate-700" :style="{ left: (axisMark(department.curve, 'panel')?.pct || 0) + '%' }" x-text="axisMark(department.curve, 'panel')?.label"></span>
                                        <span class="absolute right-0 font-semibold text-slate-700" x-text="department.curve.endLabel"></span>
                                    </div>
                                </div>

                                                        {{-- ข้อมูลโครงการ: who, when, its cabinets, its next delivery and FAT (ProjectTree::info, with the tree) --}}
                            <dl x-show="treeFor(department.id).info.length" class="mt-4 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm">
                                <template x-for="line in treeFor(department.id).info" :key="line[0]">
                                    <div class="contents">
                                        <dt class="whitespace-nowrap text-slate-500" x-text="line[0]"></dt>
                                        <dd class="min-w-0 break-words text-slate-800" x-text="line[1]"></dd>
                                    </div>
                                </template>
                            </dl>
                            <p x-show="!treeFor(department.id).info.length && treeFor(department.id).status === 'loading'" class="mt-4 animate-pulse text-sm text-slate-400">กำลังโหลดข้อมูลโครงการ…</p>

                            {{-- การจัดส่ง: has it gone yet - the cabinets that arrived of all, then each round: when, its state as
                                 งานจัดส่ง says it, how it goes, and its four steps (ห่อ, เปิดบิล, ออกจากโรงงาน, ส่งถึง) with their days --}}
                            <section x-show="treeFor(department.id).deliveries" class="mt-5 border-t border-slate-100 pt-4">
                                <div class="flex items-baseline justify-between gap-3">
                                    <h5 class="text-sm font-semibold text-slate-900">การจัดส่ง</h5>
                                    <span x-show="treeFor(department.id).deliveries?.cabinets" class="flex items-center gap-2 text-xs tabular-nums text-slate-600">
                                        <span x-text="'ส่งถึงแล้ว ' + (treeFor(department.id).deliveries?.arrived || 0) + ' / ' + (treeFor(department.id).deliveries?.cabinets || 0) + ' ตู้'"></span>
                                        <span class="h-1.5 w-16 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><span class="block h-full rounded-full bg-green-600" :style="{ width: (treeFor(department.id).deliveries?.cabinets ? Math.round(treeFor(department.id).deliveries.arrived / treeFor(department.id).deliveries.cabinets * 100) : 0) + '%' }"></span></span>
                                    </span>
                                </div>
                                <p x-show="treeFor(department.id).deliveries && !treeFor(department.id).deliveries.rounds.length" class="mt-2 text-sm text-slate-500">ยังไม่นัดส่ง</p>
                                <ol class="mt-2 space-y-2.5">
                                    <template x-for="round in shownRounds(department.id)" :key="round.id">
                                        <li class="rounded-lg border border-slate-200 px-3 py-2.5">
                                            <div class="flex items-start justify-between gap-3">
                                                <p class="min-w-0">
                                                    <span class="block text-sm font-semibold text-slate-900" x-text="'รอบ ' + round.round + ' · ' + round.when"></span>
                                                    <span class="block truncate text-xs text-slate-500" :title="round.meta" x-text="round.meta"></span>
                                                </p>
                                                <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold" :style="{ color: round.color, backgroundColor: round.color + '1a' }" x-text="round.state"></span>
                                            </div>
                                            {{-- which cabinets go in this round --}}
                                            <ul x-show="round.cabinetNames.length" class="mt-2 flex flex-wrap gap-1.5" :aria-label="'ตู้ในรอบ ' + round.round">
                                                <template x-for="name in round.cabinetNames" :key="name">
                                                    <li class="max-w-full truncate rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-700" :title="name" x-text="name"></li>
                                                </template>
                                            </ul>
                                            {{-- the four steps: a filled dot and a solid line once done --}}
                                            <ol class="mt-2.5 grid grid-cols-4" :aria-label="'ขั้นการจัดส่งรอบ ' + round.round">
                                                <template x-for="(step, i) in round.steps" :key="i">
                                                    <li class="relative flex flex-col items-center text-center">
                                                        <span x-show="i > 0" class="absolute right-1/2 top-[5px] h-0.5 w-full" :class="step.done ? 'bg-green-500' : 'bg-slate-200'" aria-hidden="true"></span>
                                                        <span class="relative z-[1] h-3 w-3 rounded-full ring-2 ring-white" :class="step.done ? 'bg-green-600' : 'border-2 border-slate-300 bg-white'" aria-hidden="true"></span>
                                                        <span class="mt-1 text-[11px] leading-tight" :class="step.done ? 'font-medium text-slate-800' : 'text-slate-400'" x-text="step.label"></span>
                                                        <span x-show="step.at" class="text-[10px] tabular-nums text-slate-400" x-text="step.at"></span>
                                                        <span class="sr-only" x-text="step.done ? 'เสร็จแล้ว' : 'ยังไม่ถึง'"></span>
                                                    </li>
                                                </template>
                                            </ol>
                                        </li>
                                    </template>
                                </ol>
                            </section>

{{-- the days shown: what came in and got done (and the small chart), then what it holds now and where --}}
                            <section class="mt-5 border-t border-slate-100 pt-4">
                                <h5 class="text-sm font-semibold text-slate-900" x-text="'งานย่อย (Sub Task) ' + periodWord()"></h5>
                            {{-- what moved in the days shown: the two counts, and the small chart of them --}}
                            <div class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] items-end gap-4">
                                <dl class="space-y-1.5">
                                    <div class="flex items-baseline gap-2">
                                        <dt class="flex w-[6.5rem] items-center gap-1.5 text-sm text-slate-600" title="Sub Task ที่ส่งเข้าแผนกให้ทำ"><span class="h-2.5 w-2.5 rounded-sm bg-slate-400"></span>ส่งเข้าแผนก</dt>
                                        <dd class="text-xl font-semibold tabular-nums text-slate-900" x-count="department.received"></dd>
                                        <dd class="text-xs tabular-nums text-slate-500" x-show="department.received" x-text="department.receivedCabinets + ' ตู้'"></dd>
                                    </div>
                                    <div class="flex items-baseline gap-2">
                                        <dt class="flex w-[6.5rem] items-center gap-1.5 text-sm text-slate-600" title="Sub Task ที่ทำเสร็จ (Checklist ครบ หรือกดเสร็จงาน)"><span class="h-2.5 w-2.5 rounded-sm bg-teal-700"></span>ทำเสร็จ</dt>
                                        <dd class="text-xl font-semibold tabular-nums text-slate-900" x-count="department.completed"></dd>
                                        <dd class="text-xs tabular-nums text-slate-500" x-show="department.completed" x-text="department.completedCabinets + ' ตู้'"></dd>
                                    </div>
                                </dl>

                            {{-- งานเข้า | เสร็จ per bar, one scale for every project. One day: its week, that day in full colour;
                                 a range: the range by day / week / month (DailyWork::chartBars) --}}
                            <div class="min-w-0" role="img" :aria-label="chartDescription(department)">
                                <p class="mb-1 text-right text-xs text-slate-500" x-text="summary.range.chartLabel === '7 วันล่าสุด' ? 'ย้อนหลัง 7 วัน' : 'กราฟ' + summary.range.chartLabel"></p>
                                <div class="flex h-10 items-end border-b border-slate-200" :class="department.chart.length > 10 ? 'gap-[2px]' : 'gap-1.5'">
                                    <template x-for="(bar, barIndex) in department.chart" :key="bar.date">
                                        <div class="group relative flex h-full flex-1 items-end justify-center gap-[2px]" :style="'--b:' + barIndex">
                                            <div class="bar-rise w-1/2 max-w-[10px] rounded-t-[4px] bg-slate-400" :class="bar.isHighlighted ? '' : 'opacity-40'" :style="barHeight(bar.received)"></div>
                                            <div class="bar-rise w-1/2 max-w-[10px] rounded-t-[4px] bg-teal-700" :class="bar.isHighlighted ? '' : 'opacity-40'" :style="barHeight(bar.completed)"></div>
                                            <div class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2.5 py-1.5 text-sm text-white shadow-lg group-hover:block">
                                                <span x-text="bar.label"></span> · ส่งเข้าแผนก <b x-text="bar.received"></b> · ทำเสร็จ <b x-text="bar.completed"></b>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                                <div x-show="department.chart.length <= 8" class="mt-1 flex gap-1.5">
                                    <template x-for="bar in department.chart" :key="bar.date">
                                        <span class="flex-1 text-center text-xs" :class="bar.isHighlighted && summary.range.dayCount === 1 ? 'font-semibold text-slate-900' : 'text-slate-500'" x-text="bar.shortLabel"></span>
                                    </template>
                                </div>
                                <div x-show="department.chart.length > 8" x-cloak class="mt-1 flex justify-between text-xs text-slate-500">
                                    <span x-text="department.chart[0]?.label"></span><span x-text="department.chart[department.chart.length - 1]?.label"></span>
                                </div>
                            </div>
                            </div>
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
                                <span x-show="department.pending"><span class="font-semibold text-slate-900" x-text="'ค้าง ' + department.pending"></span><span x-text="' · รอรับ ' + department.waiting + ' · กำลังทำ ' + department.inProgress"></span></span>
                                <span x-show="!department.pending" class="text-slate-500">ไม่มีงานค้าง</span>
                                    <span x-show="department.pendingDepartments.length" class="w-full truncate text-slate-500"
                                          :title="department.pendingDepartments.map((d) => d.name + ' ' + d.count).join(' · ')"
                                          x-text="'ค้างที่ ' + department.pendingDepartments.map((d) => d.name + ' ' + d.count).join(' · ')"></span>
                            </div>
                            </section>

                            {{-- ความเคลื่อนไหวล่าสุด: this project's latest lines of the feed (the days shown); not on the dashboard (showEvents) --}}
                            <section x-show="showEvents && projectEvents(department.id).length" class="mt-5 border-t border-slate-100 pt-4">
                                <h5 class="text-sm font-semibold text-slate-900" x-text="'ความเคลื่อนไหว' + periodWord()"></h5>
                                <ul class="mt-1.5 divide-y divide-slate-100">
                                    <template x-for="event in projectEvents(department.id)" :key="event.key">
                                        <li>
                                            <a :href="event.url" class="flex items-baseline gap-2 py-1.5 text-sm hover:bg-slate-50 focus-visible:bg-slate-50 focus-visible:outline-none">
                                                <span class="w-11 shrink-0 text-xs tabular-nums text-slate-500" x-text="event.time || '—'"></span>
                                                <span class="shrink-0 rounded px-1.5 py-0.5 text-xs font-semibold" :class="panelEventTag(event.type)" x-text="event.typeLabel"></span>
                                                <span x-show="event.departmentId" class="shrink-0 font-medium text-slate-700" x-text="departmentName(event.departmentId)"></span>
                                                <span class="min-w-0 truncate text-slate-500" :title="eventDetail(event)" x-text="eventDetail(event)"></span>
                                            </a>
                                        </li>
                                    </template>
                                </ul>
                            </section>
                                </div>
                                <div class="min-w-0">
                            {{-- ตู้ → Task → Sub Task → Checklist: each level's % only (App\Services\ProjectTree), opened one level at a
                                 time. Asked for when the panel opens the project (projectSummary.loadTree) --}}
                            <section class="mt-5 border-t border-slate-100 pt-4 lg:mt-4 lg:border-t-0 lg:pt-0">
                                {{-- where it is late: a button saying how many Sub Tasks are past their due date; a spot pressed opens its
                                     cabinet and Task and lights the Sub Task up (projectSummary.goToLate) --}}
                                <div class="relative flex items-center justify-between gap-3">
                                    <h5 class="text-sm font-semibold text-slate-900">ตู้และงาน</h5>
                                    <button type="button" x-show="lateSpots(department.id).length" @click="lateListOpen = !lateListOpen" :aria-expanded="lateListOpen.toString()"
                                            class="inline-flex h-8 items-center gap-1.5 rounded-full bg-orange-50 px-3 text-xs font-semibold text-orange-800 ring-1 ring-inset ring-orange-200 hover:bg-orange-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 3.5h.01M10.3 3.9 2.4 17.6A2 2 0 0 0 4.1 20.6h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                                        <span x-text="'ช้า ' + lateSpots(department.id).length + ' จุด'"></span>
                                        <svg class="h-3.5 w-3.5 transition-transform" :class="lateListOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
                                    </button>
                                </div>
                                <ul x-show="lateListOpen && lateSpots(department.id).length" x-cloak @click.outside="lateListOpen = false"
                                    class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-orange-200 bg-white py-1 shadow-[0_8px_20px_-10px_rgba(15,23,42,0.3)]">
                                    <template x-for="spot in lateSpots(department.id)" :key="spot.key">
                                        <li>
                                            <button type="button" @click="goToLate(spot)" class="flex w-full items-start gap-2 px-3 py-2 text-left hover:bg-orange-50 focus:outline-none focus-visible:bg-orange-50">
                                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-orange-500" aria-hidden="true"></span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-sm font-medium text-slate-900" x-text="spot.subtask"></span>
                                                    <span class="block truncate text-xs text-slate-500" x-text="spot.cabinet + ' › ' + spot.task + (spot.department ? ' · ' + spot.department : '')"></span>
                                                </span>
                                                <span class="shrink-0 text-xs font-semibold tabular-nums text-orange-700" x-text="spot.due ? 'กำหนด ' + spot.due : ''"></span>
                                            </button>
                                        </li>
                                    </template>
                                </ul>
                                <p x-show="treeFor(department.id).status === 'loading' && !treeFor(department.id).cabinets.length" class="mt-2 animate-pulse text-sm text-slate-500">กำลังโหลด…</p>
                                <p x-show="treeFor(department.id).status === 'error'" x-cloak class="mt-2 text-sm text-slate-600">โหลดไม่สำเร็จ — <button type="button" @click="loadTree(department.id)" class="font-medium text-blue-700 underline underline-offset-4 hover:text-blue-900">ลองใหม่</button></p>
                                <p x-show="treeFor(department.id).status === 'ok' && !treeFor(department.id).cabinets.length" x-cloak class="mt-2 text-sm text-slate-500">ยังไม่มีตู้ในโครงการนี้</p>
                                {{-- the dashboard's round card: only that round (ดูทุกรอบ: all of them) --}}
                                <p x-show="focusText(department.id)" class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-md bg-slate-100 px-2.5 py-1.5 text-xs text-slate-700">
                                    <span x-text="focusText(department.id)"></span>
                                    <button type="button" @click="focusRound = null; lateListOpen = false" class="ml-auto font-semibold text-blue-700 underline-offset-4 hover:underline">ดูทุกรอบ</button>
                                </p>
                                {{-- grouped by delivery round (its day and state), then the cabinets in no round; no round at all: one list --}}
                                <template x-for="group in cabinetGroups(department.id)" :key="group.key">
                                    <div class="mt-3 first:mt-2">
                                        <p x-show="group.title" class="mb-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                            <span class="rounded px-1.5 py-0.5 font-semibold" :style="group.color ? { color: group.color, backgroundColor: group.color + '1a' } : { color: '#475569', backgroundColor: '#f1f5f9' }" x-text="group.title"></span>
                                            <span class="text-slate-500" x-text="group.meta"></span>
                                        </p>
                                <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                                    <template x-for="cabinet in group.cabinets" :key="cabinet.id">
                                        <li>
                                            <details class="group/cab" :id="'pt-cab-' + cabinet.id">
                                                <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2.5 hover:bg-slate-50 focus:outline-none focus-visible:bg-slate-50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-600 [&::-webkit-details-marker]:hidden">
                                                    <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200 group-open/cab:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                                    <svg class="h-5 w-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 3h12a1 1 0 011 1v15H5V4a1 1 0 011-1zM5 19h14M7.5 19v2M16.5 19v2M13 6.5l-2.5 4.5h3L11 15.5"/></svg>
                                                    <span class="min-w-0 flex-1">
                                                        <span class="flex min-w-0 items-center gap-1.5">
                                                            <span class="truncate text-sm font-semibold text-slate-900" :title="cabinet.name" x-text="cabinet.name || cabinet.moNo"></span>
                                                            <svg x-show="cabinetIsLate(cabinet)" class="h-4 w-4 shrink-0 text-orange-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-label="มีงานช้าในตู้นี้" role="img"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 3.5h.01M10.3 3.9 2.4 17.6A2 2 0 0 0 4.1 20.6h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                                                        </span>
                                                        <span class="block truncate text-xs text-slate-500" x-text="[cabinet.moNo, cabinet.quantity > 1 ? cabinet.quantity + ' ตู้' : '', cabinet.fat].filter(Boolean).join(' · ')"></span>
                                                    </span>
                                                    {{-- its delivery, as an icon: arrived (✓), on the way (speed lines), not gone yet, or in no round (faint, dashed) --}}
                                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full"
                                                          :style="cabinet.deliveryChip ? { color: cabinet.deliveryChip.color, backgroundColor: cabinet.deliveryChip.color + '1a' } : { color: '#cbd5e1' }"
                                                          :title="cabinet.deliveryChip ? cabinet.deliveryChip.text : 'ยังไม่อยู่ในรอบส่ง'">
                                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true" :stroke-dasharray="cabinet.deliveryChip ? null : '2 2'"><path stroke-linecap="round" stroke-linejoin="round" d="M2 7h11v9H2zM13 10h4l3 3v3h-7M6 19a2 2 0 100-4 2 2 0 000 4zM16 19a2 2 0 100-4 2 2 0 000 4z"/>
                                                            <path x-show="cabinet.deliveryChip?.step === 'arrived'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M5 11.5l2 2 4-4"/>
                                                            <path x-show="cabinet.deliveryChip?.step === 'gone'" stroke-linecap="round" stroke-width="2" d="M5 10h5M4 13h3.5"/>
                                                        </svg>
                                                        <span class="sr-only" x-text="cabinet.deliveryChip ? cabinet.deliveryChip.text : 'ยังไม่อยู่ในรอบส่ง'"></span>
                                                    </span>
                                                    <span class="flex shrink-0 items-center gap-2"><span class="h-1.5 w-16 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><span class="bar-grow block h-full rounded-full" :style="{ background: resultColor(cabinet.result), width: Math.min(100, cabinet.progress) + '%' }" :title="resultText(cabinet)"></span></span><span class="w-10 text-right text-sm font-semibold tabular-nums" :class="cabinet.result === 'late' ? 'text-orange-700' : 'text-slate-900'" x-text="cabinet.progress + '%'"></span><span class="sr-only" x-text="resultText(cabinet)"></span></span>
                                                </summary>
                                                <ul class="border-t border-slate-100 bg-slate-50/60 py-1">
                                                    <template x-for="task in cabinet.tasks" :key="task.id">
                                                        <li>
                                                            <details class="group/task" :id="'pt-task-' + task.id">
                                                                <summary class="flex cursor-pointer list-none items-center gap-2 py-2 pl-8 pr-3 hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-600 [&::-webkit-details-marker]:hidden">
                                                                    <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200 group-open/task:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                                                    <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800" :title="task.name" x-text="task.name"></span>
                                                                    <span class="flex shrink-0 items-center gap-2"><span class="h-1.5 w-12 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><span class="bar-grow block h-full rounded-full" :style="{ background: resultColor(task.result), width: Math.min(100, task.progress) + '%' }" :title="resultText(task)"></span></span><span class="w-10 text-right text-sm font-semibold tabular-nums" :class="task.result === 'late' ? 'text-orange-700' : 'text-slate-900'" x-text="task.progress + '%'"></span><span class="sr-only" x-text="resultText(task)"></span></span>
                                                                </summary>
                                                                <ul class="pb-1">
                                                                    <template x-for="subtask in task.subtasks" :key="subtask.id">
                                                                        <li>
                                                                            <details class="group/sub" :id="'pt-sub-' + subtask.id">
                                                                                <summary class="flex cursor-pointer list-none items-center gap-2 py-1.5 pl-14 pr-3 hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-600 [&::-webkit-details-marker]:hidden">
                                                                                    <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200 group-open/sub:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                                                                    <span class="min-w-0 flex-1">
                                                                                        <span class="flex min-w-0 items-center gap-1.5">
                                                                                            <span class="truncate text-sm text-slate-800" :title="subtask.name" x-text="subtask.name"></span>
                                                                                            <svg x-show="subtask.result === 'late'" class="h-3.5 w-3.5 shrink-0 text-orange-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-label="เลยกำหนด" role="img"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 3.5h.01M10.3 3.9 2.4 17.6A2 2 0 0 0 4.1 20.6h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                                                                                        </span>
                                                                                        <span x-show="subtask.department" class="block truncate text-xs text-slate-500" x-text="subtask.department"></span>
                                                                                    </span>
                                                                                    <span class="flex shrink-0 items-center gap-2"><span class="h-1.5 w-10 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><span class="bar-grow block h-full rounded-full" :style="{ background: resultColor(subtask.result), width: Math.min(100, subtask.progress) + '%' }" :title="resultText(subtask)"></span></span><span class="w-10 text-right text-sm font-semibold tabular-nums" :class="subtask.result === 'late' ? 'text-orange-700' : 'text-slate-900'" x-text="subtask.progress + '%'"></span><span class="sr-only" x-text="resultText(subtask)"></span></span>
                                                                                </summary>
                                                                                <ul class="pb-1.5 pl-20 pr-3">
                                                                                    <template x-for="item in subtask.checklist" :key="item.id">
                                                                                        <li class="flex items-start gap-2 py-0.5 text-sm" :class="item.done ? 'text-slate-500' : 'text-slate-800'">
                                                                                            <svg x-show="item.done" class="mt-0.5 h-4 w-4 shrink-0 text-teal-700" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                                                                            <span x-show="!item.done" class="mt-1 h-3 w-3 shrink-0 rounded-full border-2 border-slate-300" aria-hidden="true"></span>
                                                                                            <span class="min-w-0" x-text="item.name"></span>
                                                                                            <span class="sr-only" x-text="item.done ? 'เสร็จแล้ว' : 'ยังไม่เสร็จ'"></span>
                                                                                        </li>
                                                                                    </template>
                                                                                    <li x-show="!subtask.checklist.length" class="py-0.5 text-xs text-slate-500">ไม่มี Checklist</li>
                                                                                </ul>
                                                                            </details>
                                                                        </li>
                                                                    </template>
                                                                    <li x-show="!task.subtasks.length" class="py-1 pl-14 text-xs text-slate-500">ไม่มี Sub Task</li>
                                                                </ul>
                                                            </details>
                                                        </li>
                                                    </template>
                                                    <li x-show="!cabinet.tasks.length" class="py-1.5 pl-8 text-xs text-slate-500">ไม่มี Task</li>
                                                </ul>
                                            </details>
                                        </li>
                                    </template>
                                </ul>
                                    </div>
                                </template>
                            </section>

                                </div>
                            </div>
                            {{-- to the project itself --}}
                            <a :href="department.url" class="mt-6 inline-flex sm:hidden h-11 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-ae-navy transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600">
                                เปิดหน้าโครงการ
                            </a>
                    </article>
                </template>
            </div>
        </aside>
