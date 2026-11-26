{{-- งานจัดส่ง · ปฏิทินสัปดาห์ (DeliveryController::week): the week's rounds as Monday-to-Friday columns, laid out as the
     dashboard's "สัปดาห์นี้" - today's column in the house navy, days gone by greyed, cards stacking down each day, a panel
     beside the board for the round pressed. The steps themselves are worked on จัดการรอบส่ง (the step tabs), one press away. --}}
@php
    $week = fn (int $offset) => route('deliveries.index', array_filter(['view' => 'week', 'week' => $offset ?: null]));
    $pin = '<svg class="mt-[2px] h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 17.5s5.5-4.6 5.5-9a5.5 5.5 0 10-11 0c0 4.4 5.5 9 5.5 9z"/><circle cx="10" cy="8.5" r="2"/></svg>';
    $truck = '<svg class="mt-[2px] h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 5h10v8H2zM12 8h3.5L18 11v2h-6z"/><circle cx="5.5" cy="14.5" r="1.5"/><circle cx="14.5" cy="14.5" r="1.5"/></svg>';
    $steps = ['ห่อตู้', 'แจ้งเปิดบิล', 'ออกจากโรงงาน', 'ส่งถึง'];
@endphp
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-slate-900">งานจัดส่ง</h1>
    </x-slot>

    <div id="dl-week" x-data="{ sel: null, filter: '' }" @keydown.escape.window="sel = null"
         class="-mx-2 sm:-mx-3 lg:-mx-4 flex flex-col gap-4 tabular-nums xl:h-[calc(100dvh-8.5rem)] xl:min-h-[30rem]">

        {{-- the two views of the page --}}
        <div class="flex flex-wrap items-center gap-3">
            <nav aria-label="มุมมองงานจัดส่ง" class="inline-flex rounded-xl bg-slate-200 p-1 text-sm font-semibold">
                <a href="{{ route('deliveries.index', ['view' => 'list']) }}" class="rounded-lg px-3 py-1.5 text-slate-600 hover:text-slate-900">จัดการรอบส่ง</a>
                <a href="{{ route('deliveries.index', ['view' => 'week']) }}" aria-current="page" class="rounded-lg bg-white px-3 py-1.5 text-slate-900 shadow-sm">ปฏิทินสัปดาห์</a>
            </nav>
        </div>

        {{-- the week shown, today, and the states that filter the cards --}}
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <nav aria-label="เลือกสัปดาห์" class="inline-flex h-8 items-center rounded-lg border border-slate-300 bg-white text-sm text-slate-700">
                <a href="{{ $week($offset - 1) }}" class="flex h-full items-center px-3 hover:bg-slate-50" aria-label="สัปดาห์ก่อน">‹</a>
                <a href="{{ $week(0) }}" class="flex h-full items-center border-x border-slate-200 px-3 font-semibold hover:bg-slate-50" @if ($offset === 0) aria-current="page" @endif>สัปดาห์นี้</a>
                <a href="{{ $week($offset + 1) }}" class="flex h-full items-center px-3 hover:bg-slate-50" aria-label="สัปดาห์หน้า">›</a>
            </nav>
            <h2 class="text-[17px] font-semibold text-slate-900">{{ $label }}</h2>
            @if ($todayText)
                <p class="inline-flex items-center gap-1.5 text-[13px] text-slate-600"><span class="h-2 w-2 rounded-full bg-[#0B1F3A]" aria-hidden="true"></span>วันนี้ <b class="font-semibold text-slate-900">{{ $todayText }}</b></p>
            @endif
            <div class="ml-auto flex flex-wrap items-center gap-x-4 gap-y-1" role="group" aria-label="กรองตามสถานะรอบส่ง">
                <span class="text-xs text-slate-500">สถานะรอบส่ง</span>
                @foreach ($states as $s)
                    <button type="button" @click="filter = filter === '{{ $s['key'] }}' ? '' : '{{ $s['key'] }}'" :aria-pressed="(filter === '{{ $s['key'] }}').toString()"
                            :class="filter === '{{ $s['key'] }}' ? 'underline decoration-2 underline-offset-4' : ''"
                            class="inline-flex min-h-8 items-center gap-1.5 text-[13px] text-slate-600 hover:text-slate-900">
                        <span class="h-2 w-2 rounded-full" style="background: {{ $s['color'] }}" aria-hidden="true"></span>{{ $s['label'] }}
                        <span class="font-semibold text-slate-900">{{ $s['n'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- the panel's column opens and closes by easing (the board narrows smoothly); none for reduced motion --}}
        <div class="grid gap-4 xl:min-h-0 xl:flex-1 xl:overflow-hidden xl:transition-[grid-template-columns,gap] xl:duration-200 xl:ease-out motion-reduce:transition-none"
             :class="sel ? 'xl:grid-cols-[minmax(0,1fr)_23.75rem] xl:gap-4' : 'xl:grid-cols-[minmax(0,1fr)_0rem] xl:gap-0'">

            {{-- the week: a column a working day --}}
            <div class="-mx-1 flex overflow-x-auto rounded-xl bg-white shadow-[inset_0_0_0_1px_#e5e7eb] xl:mx-0 xl:grid xl:min-h-0 xl:grid-cols-5 xl:overflow-hidden">
                @foreach ($days as $d)
                    <section class="flex w-60 shrink-0 flex-col border-l border-slate-200 first:border-l-0 xl:w-auto xl:min-h-0 {{ $d['today'] ? 'bg-[#f3f6fa] shadow-[inset_0_0_0_1px_#0B1F3A]' : ($d['past'] ? 'bg-slate-50' : '') }}"
                             aria-label="{{ $d['short'] }} {{ $d['n'] }}{{ $d['today'] ? ' (วันนี้)' : ($d['past'] ? ' (ผ่านไปแล้ว)' : '') }}" @if ($d['today']) aria-current="date" @endif>
                        <h3 class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b px-3 py-2 text-[13px] font-semibold {{ $d['today'] ? 'border-[#0B1F3A] bg-[#0B1F3A] text-white' : ($d['past'] ? 'border-slate-200 text-slate-400' : 'border-slate-200 text-slate-600') }}">
                            <span class="whitespace-nowrap">{{ $d['short'] }} <span class="{{ $d['today'] ? 'text-white' : ($d['past'] ? 'text-slate-400' : 'text-slate-900') }}">{{ $d['n'] }}</span></span>
                            @if ($d['today'])<span class="rounded-full bg-white/15 px-2 py-0.5 text-[11px] font-semibold leading-none text-white ring-1 ring-inset ring-white/30">วันนี้</span>@endif
                            @if (count($d['items']) + count($d['delivered']))<span class="ml-auto whitespace-nowrap text-xs font-normal {{ $d['today'] ? 'text-slate-300' : 'text-slate-500' }}">{{ count($d['items']) ? 'ค้าง '.count($d['items']).' รอบ' : 'ส่งครบแล้ว' }}</span>@endif
                        </h3>
                        <ul class="flex flex-col gap-2 p-1.5 xl:min-h-0 xl:flex-1 xl:overflow-y-auto xl:overscroll-contain">
                            @foreach ($d['items'] as $r)
                                <li x-show="! filter || filter === '{{ $r['status'] }}'">
                                    <button type="button" @click="sel = @js($r)" title="{{ $r['name'] }}"
                                            :class="sel && sel.id === {{ $r['id'] }} ? 'ring-2 ring-[#1479D7]' : 'hover:ring-1 hover:ring-slate-300'"
                                            style="background: {{ $r['color'] }}0f; border-color: {{ $r['color'] }}40"
                                            class="flex w-full flex-col gap-0.5 rounded-lg border px-2.5 py-2 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-[#1479D7]">
                                        <span class="flex w-full flex-wrap items-baseline justify-between gap-x-1.5 text-xs leading-snug">
                                            <span class="font-semibold" style="color: {{ $r['color'] }}">{{ $r['state'] }}</span>
                                            @if ($r['weekend'] || $r['late'])<span class="font-medium {{ $r['late'] ? 'text-[#dc2626]' : 'text-slate-500' }}">{{ $r['late'] ? 'เลยวันส่ง' : $r['weekend'] }}</span>@endif
                                        </span>
                                        <span class="text-xs leading-snug text-slate-500"><span class="font-medium text-slate-600">{{ $r['project'] }}</span> · รอบ {{ $r['round'] }} · ตู้ {{ count($r['cabinets']) }}</span>
                                        <span class="line-clamp-2 text-sm font-semibold leading-snug text-slate-900">{{ $r['name'] }}</span>
                                        @if ($r['site'])
                                            <span class="flex w-full min-w-0 items-start gap-1 text-xs leading-snug text-slate-600" title="ส่งที่ {{ $r['site'] }}">{!! $pin !!}<span class="line-clamp-1">{{ $r['site'] }}</span></span>
                                        @endif
                                        <span class="flex w-full min-w-0 items-start gap-1 text-xs leading-snug text-slate-600">{!! $truck !!}<span class="line-clamp-1">{{ $r['transport'] }}{{ $r['crew_size'] ? ' · '.$r['crew_size'].' คน' : '' }}</span></span>
                                        {{-- what has to happen next, in words, and how far it has come --}}
                                        <span class="mt-1 flex w-full flex-wrap items-baseline justify-between gap-x-2 border-t border-dashed pt-1.5 text-xs leading-snug" style="border-color: {{ $r['color'] }}40">
                                            <span class="font-semibold {{ $r['status'] === 'rejected' ? 'text-[#b91c1c]' : 'text-[#0f766e]' }}">ต่อไป: {{ $r['next'] }}</span>
                                            <span class="text-slate-500">ทำแล้ว {{ $r['stepsDone'] }}/4 ขั้น</span>
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                            @if (! count($d['items']))
                                <li x-show="filter !== 'delivered'" class="py-6 text-center text-[13px] text-slate-500">{{ count($d['delivered']) ? 'ไม่มีงานค้าง' : 'ไม่มีรอบส่ง' }}</li>
                            @endif
                            {{-- delivered: only when "ส่งถึงแล้ว" is picked above (the week opens on the work still to do), one line each --}}
                            @if (count($d['delivered']))
                                <li x-show="filter === 'delivered'" class="mt-1 flex items-center gap-2 px-1 text-xs font-medium text-slate-500">
                                    <span class="h-px flex-1 bg-slate-200" aria-hidden="true"></span>ส่งถึงแล้ว · {{ count($d['delivered']) }} รอบ<span class="h-px flex-1 bg-slate-200" aria-hidden="true"></span>
                                </li>
                                @foreach ($d['delivered'] as $r)
                                    <li x-show="filter === 'delivered'">
                                        <button type="button" @click="sel = @js($r)" title="{{ $r['name'] }}"
                                                :class="sel && sel.id === {{ $r['id'] }} ? 'ring-2 ring-[#1479D7]' : 'hover:ring-1 hover:ring-slate-300'"
                                                class="flex w-full items-start gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-[#1479D7]">
                                            <svg class="mt-[3px] h-3.5 w-3.5 shrink-0 text-[#047857]" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>
                                            <span class="min-w-0 text-xs leading-snug"><span class="text-slate-500">{{ $r['project'] }}</span> <span class="line-clamp-1 font-medium text-slate-700">{{ $r['name'] }}</span></span>
                                        </button>
                                    </li>
                                @endforeach
                            @endif
                        </ul>
                    </section>
                @endforeach
            </div>

            {{-- the round pressed: what goes where, by what, its steps; the work itself on จัดการรอบส่ง --}}
            <aside id="dl-panel" aria-label="รายละเอียดรอบส่ง" x-show="sel" x-cloak
                   x-transition:enter="transition-opacity duration-200 ease-out motion-reduce:transition-none" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                   class="flex flex-col overflow-hidden rounded-xl bg-white shadow-[inset_0_0_0_1px_#e5e7eb] xl:min-h-0 xl:w-[23.75rem]">
                <template x-if="sel">
                    <div class="flex flex-col xl:min-h-0 xl:flex-1">
                        <div class="border-b border-slate-200 px-4 pb-4 pt-3">
                            <button type="button" @click="sel = null" class="-ml-1 rounded-md px-1 py-1 text-[13px] font-semibold text-[#1268bd] hover:bg-slate-50" aria-label="ปิดรายละเอียด">✕ ปิด</button>
                            <p class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="rounded-md px-2 py-1 text-xs font-semibold" :style="'color: ' + sel.color + '; background: ' + sel.color + '14'" x-text="sel.state"></span>
                                <span class="text-xs text-slate-500" x-text="'ส่งมอบรอบ ' + sel.round + ' · ' + sel.when"></span>
                            </p>
                            <h3 class="mt-2 text-[17px] font-semibold leading-snug text-slate-900" x-text="sel.name"></h3>
                            <p class="mt-2 rounded-lg px-3 py-2 text-[13px] font-semibold" :class="sel.done ? 'bg-emerald-50 text-[#047857]' : (sel.status === 'rejected' ? 'bg-red-50 text-[#b91c1c]' : 'bg-teal-50 text-[#0f766e]')"
                               x-text="sel.done ? '✓ ส่งถึงแล้ว · ครบทุกขั้น' : 'ต่อไป: ' + sel.next + ' · ทำแล้ว ' + sel.stepsDone + '/4 ขั้น'"></p>
                            <dl class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-[13px] leading-snug">
                                <dt class="text-slate-500">เลข SO</dt><dd class="font-medium text-slate-900" x-text="sel.project"></dd>
                                <dt class="text-slate-500">สถานที่ส่ง</dt>
                                <dd class="min-w-0">
                                    <span class="break-words" :class="sel.site ? 'text-slate-900' : 'text-slate-500'" x-text="sel.site || 'ยังไม่ระบุ'"></span>
                                    <a x-show="sel.map || sel.nav" :href="sel.nav || sel.map" target="_blank" rel="noopener" class="ml-1 whitespace-nowrap font-semibold text-[#1268bd] hover:underline">เปิดแผนที่ ›</a>
                                </dd>
                                <template x-if="sel.contact"><dt class="text-slate-500">ผู้ติดต่อ</dt></template>
                                <template x-if="sel.contact"><dd class="text-slate-900" x-text="sel.contact"></dd></template>
                                <dt class="text-slate-500">รถ</dt><dd class="text-slate-900" x-text="sel.transport"></dd>
                                <dt class="text-slate-500">คน</dt><dd :class="sel.people ? 'text-slate-900' : 'text-slate-500'" x-text="sel.people || (sel.status === 'waiting' ? 'ยังไม่จัด · PM ขอ ' + sel.crew_size + ' คน' : '-')"></dd>
                                <dt class="text-slate-500">ตู้</dt><dd class="text-slate-900" x-text="sel.cabinets.length + ' ตู้ · ห่อแล้ว ' + sel.packedCount"></dd>
                            </dl>
                        </div>
                        <div class="flex flex-col gap-5 p-4 xl:min-h-0 xl:flex-1 xl:overflow-y-auto">
                            <div>
                                <h4 class="mb-2 text-[13px] font-semibold text-slate-700">ขั้นตอน</h4>
                                <ol class="flex flex-col gap-2">
                                    @foreach ($steps as $k => $label)
                                        <li class="flex items-center gap-2.5 text-[13px]" :class="sel.step.n > {{ $k }} || sel.step.n === {{ $k }} ? 'text-slate-900' : 'text-slate-400'">
                                            <span class="inline-flex h-[18px] w-[18px] shrink-0 items-center justify-center rounded-full text-white"
                                                  :class="sel.step.n > {{ $k }} ? 'bg-[#0f766e]' : (sel.step.n === {{ $k }} && sel.status !== 'rejected' ? 'bg-[#5eead4]' : 'bg-slate-200')">
                                                <svg x-show="sel.step.n > {{ $k }}" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>
                                            </span>{{ $label }}
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                            <ul x-show="sel.cabinets.length" class="divide-y divide-slate-100 text-[13px]">
                                <template x-for="c in sel.cabinets" :key="c.id">
                                    <li class="flex items-baseline justify-between gap-2 py-1.5"><span class="min-w-0 truncate"><b class="font-medium text-slate-900" x-text="c.name"></b> <span class="text-slate-500" x-text="c.model"></span></span><span class="shrink-0 text-xs" :class="c.packed ? 'text-[#047857]' : 'text-slate-500'" x-text="c.packed ? 'ห่อแล้ว' : 'ยังไม่ห่อ'"></span></li>
                                </template>
                            </ul>
                            <p x-show="sel.note" class="rounded-lg bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-900" x-text="'หมายเหตุจาก PM · ' + sel.note"></p>
                            <p x-show="sel.rejected" class="rounded-lg bg-red-50 px-3 py-2 text-xs leading-relaxed text-red-800" x-text="'ไม่รับงาน · ' + sel.rejected"></p>
                        </div>
                        <div class="grid grid-cols-2 gap-2 border-t border-slate-200 px-4 py-3">
                            <a :href="sel.manage" class="col-span-2 flex h-10 items-center justify-center rounded-lg bg-[#0f766e] text-sm font-semibold text-white hover:bg-[#115e59]">จัดการรอบนี้</a>
                            <a x-show="sel.driverUrl" :href="sel.driverUrl" target="_blank" rel="noopener" class="col-span-2 flex h-9 items-center justify-center rounded-lg border border-slate-300 text-[13px] font-semibold text-slate-700 hover:bg-slate-50">ลิงก์คนขับ</a>
                        </div>
                    </div>
                </template>
            </aside>
        </div>
    </div>
</x-app-layout>
