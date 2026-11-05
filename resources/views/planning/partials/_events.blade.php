{{-- FAT / จัดส่ง (two tabs, one template): the month's FAT appointments or delivery rounds alone, without the project bars
     (planning-board.js ev*). Laid out as เดือน is: one bar per appointment over its days (a FAT of several days is one long
     bar). The toolbar's filters still apply; a bar opens the FAT / delivery window (Admin / PM) or the project's window. --}}
<div x-show="mode === 'fat' || mode === 'del'" x-cloak>
    <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold text-slate-600">
        <span x-text="mode === 'fat' ? 'นัด FAT เดือนนี้ ' + evCount().fat + ' นัด' : 'รอบส่งเดือนนี้ ' + evCount().del + ' รอบ'"></span>
        <button type="button" x-show="mode === 'del' && (evCount().wait || evNeed)" @click="evNeed = !evNeed" :aria-pressed="evNeed" title="เฉพาะรอบที่แผนกจัดส่งยังไม่ได้เลือกวัน / ไม่รับงาน"
                :class="evNeed ? 'border-amber-700 bg-amber-700 text-white' : 'border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100'" class="inline-flex h-7 items-center gap-1.5 rounded-full border px-3">
            <span x-text="evState('delivery', 'waiting').name"></span> <span class="tabular-nums" x-text="evCount().wait"></span>
        </button>
    </div>

    <div class="grid grid-cols-7 border-b border-slate-200 bg-white text-[12px] font-bold text-slate-500">
        <template x-for="w in weekdays()"><div class="px-3 py-2" x-text="w"></div></template>
    </div>
    <template x-for="week in weekRows()" :key="'ev' + week[0].date">
        <div class="border-b border-slate-200">
            <div class="grid grid-cols-7">
                <template x-for="d in week" :key="'evd' + d.date">
                    <button type="button" @click="selectDay(d.date)" :aria-pressed="focusDate === d.date"
                            :class="[d.date === today ? 'bg-blue-50/70' : (isWeekend(d.date) ? 'bg-slate-50/70' : 'bg-white'), focusDate === d.date ? 'ring-2 ring-inset ring-blue-300' : '']"
                            class="border-r border-slate-100 px-3 pb-1 pt-2 text-left last:border-r-0 hover:bg-blue-50/50 focus:outline-none">
                        <span :class="d.date === today ? 'bg-blue-600 text-white' : (d.inMonth ? 'text-slate-800' : 'text-slate-300')"
                              class="inline-flex h-[1.6rem] min-w-[1.6rem] items-center justify-center rounded-full text-xs font-semibold" x-text="d.day"></span>
                    </button>
                </template>
            </div>
            <div class="relative">
                <div class="pointer-events-none absolute inset-0 grid grid-cols-7" aria-hidden="true">
                    <template x-for="d in week" :key="'evbg' + d.date">
                        <div :class="d.date === today ? 'bg-blue-50/70' : (isWeekend(d.date) ? 'bg-slate-50/70' : '')" class="border-r border-slate-100 last:border-r-0"></div>
                    </template>
                </div>
                <div class="relative grid grid-cols-7 gap-y-1 px-0.5 pb-1.5" style="grid-auto-rows: 58px; min-height: 24px;">
                    <template x-for="b in evShownBars(week)" :key="'evb' + b.key">
                        <button type="button" @click="evGo(b)" :class="evCls(b)" :style="'grid-column:' + b.col + ' / span ' + b.span + ';grid-row:' + (b.lane + 1) + ';--c:' + evColor(b)" :title="evTitle(b)">
                            <b x-text="(b.before ? '◄ ' : '') + evHead(b) + (b.after ? ' ►' : '')"></b><span x-text="b.t.so + ' ' + b.t.title"></span><span x-text="evMeta(b)"></span>
                        </button>
                    </template>
                    <template x-for="(d, idx) in week" :key="'evm' + d.date">
                        <button type="button" x-show="evHidden(week, d.date) > 0" @click="evOpen = { ...evOpen, [week[0].date]: true }" :style="'grid-column:' + (idx + 1) + '; grid-row: 5;'"
                                class="self-start justify-self-start px-2 text-left text-[11.5px] font-medium text-blue-600 hover:underline" x-text="'+ อีก ' + evHidden(week, d.date)"></button>
                    </template>
                </div>
                <div x-show="evOpen[week[0].date]" class="relative flex justify-center pb-1.5">
                    <button type="button" @click="evOpen = { ...evOpen, [week[0].date]: false }" class="rounded px-2 text-[11.5px] font-medium text-blue-600 hover:underline">▲ ย่อกลับ</button>
                </div>
            </div>
        </div>
    </template>
    <p x-show="!evCount().shown" class="py-10 text-center text-sm text-slate-400" x-text="mode === 'fat' ? 'ไม่มีนัด FAT ในเดือนนี้ตามเงื่อนไขที่เลือก' : 'ไม่มีรอบส่งในเดือนนี้ตามเงื่อนไขที่เลือก'"></p>
    <div class="flex flex-wrap gap-x-4 gap-y-1 px-4 py-2.5 text-[12px] text-slate-500">
        <template x-for="(s, i) in evLegend()" :key="i">
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" :style="'background-color:' + s.color"></span><span x-text="s.name"></span></span>
        </template>
        @if (auth()->user()->isAdmin())<a href="{{ route('settings.statuses.edit') }}" class="ml-auto text-blue-600 hover:underline">ตั้งชื่อ / สี</a>@endif
    </div>
</div>
