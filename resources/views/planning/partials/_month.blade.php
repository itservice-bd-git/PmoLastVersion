{{-- เดือน: a project is one bar over its dates (tinted by its status, flag = priority, badge = cabinets); in a department view a single chip on that department's due date. --}}
<div x-show="mode === 'month'">
    <div class="grid grid-cols-7 border-b border-slate-200 bg-white text-[11px] font-bold text-slate-500">
        <template x-for="w in weekdays()"><div class="px-3 py-2" x-text="w"></div></template>
    </div>

    <template x-for="week in weekRows()" :key="week[0].date">
        <div class="border-b border-slate-200">
            <div class="grid grid-cols-7">
                <template x-for="d in week" :key="d.date">
                    <button type="button" @click="selectDay(d.date)" @dragover.prevent @drop.prevent="dropOn(d.date)" :aria-pressed="focusDate === d.date"
                            :class="[d.date === today ? 'bg-blue-50/70' : (isWeekend(d.date) ? 'bg-slate-50/70' : 'bg-white'), focusDate === d.date ? 'ring-2 ring-inset ring-blue-300' : '']"
                            class="border-r border-slate-100 last:border-r-0 px-3 pt-2 pb-1 text-left hover:bg-blue-50/50 focus:outline-none">
                        <span :class="d.date === today ? 'bg-blue-600 text-white' : (d.inMonth ? 'text-slate-800' : 'text-slate-300')"
                              class="inline-flex items-center justify-center min-w-[1.6rem] h-[1.6rem] rounded-full text-xs font-semibold" x-text="d.day"></span>
                    </button>
                </template>
            </div>

            <div class="relative">
                <div class="absolute inset-0 grid grid-cols-7 pointer-events-none" aria-hidden="true">
                    <template x-for="d in week" :key="'bg-' + d.date">
                        <div :class="d.date === today ? 'bg-blue-50/70' : (isWeekend(d.date) ? 'bg-slate-50/70' : '')" class="border-r border-slate-100 last:border-r-0"></div>
                    </template>
                </div>
                <div class="relative grid grid-cols-7 gap-y-1 px-0.5 pb-1.5" style="grid-auto-rows: 46px; min-height: 24px;">
                    <template x-for="b in shownBars(week)" :key="week[0].date + b.t.id">
                        <div @click.stop="openModal(b.t.id)" :draggable="isPmo && !dept" @dragstart="dragStart($event, b.t)"
                             :style="barStyle(b)" :title="barTitle(b)"
                             class="mx-0.5 min-w-0 cursor-pointer overflow-hidden rounded-md border-l-[3px] px-2 py-1 flex flex-col justify-center hover:brightness-95 hover:shadow-sm transition">
                            <span class="truncate text-xs font-medium leading-tight" :class="b.done ? 'line-through text-slate-500' : 'text-slate-800'"
                                  x-text="(b.before ? '◄ ' : '') + b.t.so + ' ' + b.t.title"></span>
                            <span class="mt-0.5 flex items-center gap-1.5">
                                <span class="inline-flex min-w-[18px] h-[18px] items-center justify-center rounded px-1 text-[10px] font-bold text-white tabular-nums" :style="'background-color:' + (b.late ? lateColor() : statusColor(b.t))" x-text="b.t.subtasks.length"></span>
                                <svg x-show="flag(b.t)" :style="'color:' + flag(b.t)" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3v18h2v-7h11l-2-4 2-4H7V3H5z"/></svg>
                                <span x-show="b.after" class="ml-auto text-[10px] text-slate-500">►</span>
                            </span>
                        </div>
                    </template>
                    <template x-for="(d, idx) in week" :key="d.date + '-more'">
                        <p x-show="hiddenCount(week, d.date) > 0" @click.stop="selectDay(d.date)" :style="'grid-column:' + (idx + 1) + '; grid-row: 5;'"
                           class="self-start px-2 text-[10px] font-medium text-blue-600 hover:underline cursor-pointer" x-text="'+' + hiddenCount(week, d.date) + ' โครงการ'"></p>
                    </template>
                </div>
            </div>
        </div>
    </template>
    <p x-show="visible().length === 0" class="py-16 text-center text-sm text-slate-400">ไม่มีโครงการตามเงื่อนไขที่เลือก</p>
</div>
