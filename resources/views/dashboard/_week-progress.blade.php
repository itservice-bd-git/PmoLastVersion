{{-- ภาพรวมผู้บริหาร: "ความคืบหน้าสัปดาห์นี้" - the projects on the สัปดาห์นี้ board and how much of each was made in the week,
     all read on this page: a row opens in place to each cabinet, what was done in it and what is left.
     Data from App\Services\WeekProgress ($weekProgress). --}}
@php
    $w = $weekProgress;
    $t = $w['totals'];
    $rows = collect($w['rows']);
    // the board card's colour, so a project reads the same here as on สัปดาห์นี้
    $tagColor = ['red' => '#D93A31', 'orange' => '#C2410C', 'gray' => '#64748B', 'green' => '#1F9D74', 'shipped' => '#BE185D', 'fat' => '#1479D7', 'pending' => '#475569'];
    $weekLink = fn (int $o) => route('dashboard', ['view' => 'exec', 'week' => $o]);
    $title = $w['offset'] === 0 ? 'ความคืบหน้าสัปดาห์นี้' : ($w['offset'] === -1 ? 'ความคืบหน้าสัปดาห์ที่แล้ว' : 'ความคืบหน้าย้อนหลัง '.abs($w['offset']).' สัปดาห์');
    $stats = [
        ['โครงการในสัปดาห์', $t['projects'], 'โครงการ', 'text-[#1C1D21]'],
        ['มีความคืบหน้า', $t['moved'], 'โครงการ', 'text-[#17805E]'],
        ['ไม่มีความคืบหน้า', $t['still'], 'โครงการ', $t['still'] ? 'text-[#B3400B]' : 'text-[#1C1D21]'],
        ['ตู้ที่ผลิตเสร็จ', $t['cabinets'], 'ตู้', 'text-[#1C1D21]'],
    ];
    $grid = 'sm:grid-cols-[10.5rem_minmax(0,1fr)_11rem_7.5rem_1.25rem]';
@endphp

{{-- ‹ › load the other week into this section without leaving the page (the links still work without JS) --}}
<section aria-labelledby="ov-week" class="rounded-xl bg-white px-4 py-5 shadow-sm ring-1 ring-slate-200 sm:px-6"
         x-data="{ busy: false, open: null, async go(url) {
             if (this.busy) return;
             this.busy = true;
             try {
                 const r = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                 if (! r.ok) throw new Error(r.status);
                 history.replaceState(null, '', url);
                 this.$root.outerHTML = await r.text();
             } catch (e) { location.href = url; }
         } }" :aria-busy="busy" :class="busy && 'opacity-60'">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 id="ov-week" class="text-lg font-bold text-[#1C1D21]">{{ $title }}</h3>
        </div>
        <nav aria-label="เลือกสัปดาห์" class="flex items-center gap-1 rounded-lg ring-1 ring-slate-200">
            <a href="{{ $weekLink($w['offset'] - 1) }}" @click.prevent="go($el.href)" @if ($w['offset'] <= -8) aria-disabled="true" @endif
               class="inline-flex min-h-10 min-w-10 items-center justify-center rounded-l-lg text-slate-700 hover:bg-slate-100 {{ $w['offset'] <= -8 ? 'pointer-events-none opacity-40' : '' }}" aria-label="สัปดาห์ก่อนหน้า">‹</a>
            <span class="px-2 text-sm font-semibold tabular-nums text-slate-800">{{ $w['label'] }}</span>
            @if ($w['offset'] < 0)
                <a href="{{ $weekLink($w['offset'] + 1) }}" @click.prevent="go($el.href)" class="inline-flex min-h-10 min-w-10 items-center justify-center text-slate-700 hover:bg-slate-100" aria-label="สัปดาห์ถัดไป">›</a>
                <a href="{{ $weekLink(0) }}" @click.prevent="go($el.href)" class="inline-flex min-h-10 items-center rounded-r-lg px-3 text-sm font-semibold text-[#1479D7] hover:bg-slate-100">สัปดาห์นี้</a>
            @else
                <span class="inline-flex min-h-10 min-w-10 items-center justify-center text-slate-300" aria-hidden="true">›</span>
            @endif
        </nav>
    </div>

    {{-- the week in one sentence, then the four numbers behind it --}}
    @if ($w['sentence'])
        <div class="mb-4 rounded-lg border-l-4 {{ $t['moved'] ? 'border-[#1F9D74] bg-emerald-50' : 'border-[#C2410C] bg-[#FFF1E6]' }} px-4 py-3">
            <p class="text-[17px] font-semibold text-[#1C1D21]">{{ $w['sentence'] }}</p>
            @if ($t['ticks'])
                <p class="mt-0.5 text-sm text-slate-700">บันทึกงานเสร็จใน Checklist {{ number_format($t['ticks']) }} รายการ · งานย่อยเสร็จ {{ number_format($t['subtasks']) }} งาน</p>
            @endif
            @if ($t['moved'] && $w['still'])
                <p class="mt-0.5 text-sm font-medium text-[#B3400B]">ไม่มีความคืบหน้า: {{ implode(', ', $w['still']) }}</p>
            @endif
        </div>
    @endif

    <dl class="mb-5 grid grid-cols-2 gap-x-4 gap-y-3 rounded-lg bg-slate-50 px-4 py-3 sm:grid-cols-4">
        @foreach ($stats as [$label, $n, $unit, $tone])
            <div><dt class="text-sm text-slate-600">{{ $label }}</dt>
                <dd class="text-2xl font-bold tabular-nums {{ $tone }}">{{ number_format($n) }} <span class="text-sm font-normal text-slate-600">{{ $unit }}</span></dd></div>
        @endforeach
    </dl>

    @if ($rows->isEmpty())
        <p class="rounded-lg bg-slate-50 px-4 py-6 text-center text-[15px] text-slate-600">ไม่มีโครงการบนบอร์ดสัปดาห์นี้</p>
    @else
        <p class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600">
            <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-slate-400"></span>ผลิตแล้วก่อนสัปดาห์นี้</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-[#1F9D74]"></span>ทำเพิ่มในสัปดาห์นี้</span>
        </p>

        <div class="hidden gap-4 rounded-lg bg-[#26282E] px-4 py-2.5 text-left text-sm font-semibold text-white sm:grid {{ $grid }}">
            <span>เลขที่ SO</span><span>โครงการ</span><span>% ผลิต ต้นสัปดาห์ → ตอนนี้</span><span class="text-right">เพิ่มขึ้น</span><span></span>
        </div>
        <ul class="divide-y divide-slate-100">
            @foreach ($rows as $r)
                @php $color = $tagColor[$r['tag']] ?? '#64748B'; @endphp
                <li>
                    <button type="button" @click="open = open === {{ $r['id'] }} ? null : {{ $r['id'] }}" :aria-expanded="open === {{ $r['id'] }}"
                            class="grid w-full grid-cols-[minmax(0,1fr)_auto] items-start gap-x-4 gap-y-2 rounded-lg px-2 py-3 text-left hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#F28C28] sm:px-4 {{ $grid }}"
                            :class="open === {{ $r['id'] }} && 'bg-slate-50'">
                        <span class="min-w-0">
                            <span class="inline-flex items-center gap-2 font-bold text-[#1C1D21]"><span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $color }}"></span>{{ $r['no'] }}</span>
                            <span class="block pl-[18px] text-sm font-medium" style="color: {{ $color }}">{{ $r['ev'] }}</span>
                        </span>
                        <span class="text-right sm:hidden">@include('dashboard._week-progress-part', ['part' => 'added', 'r' => $r])</span>
                        <span class="col-span-2 min-w-0 sm:col-span-1">
                            <span class="block text-[15px] font-medium text-slate-800">{{ $r['name'] }}</span>
                            <span class="block text-sm text-slate-600">{{ collect([$r['jobType'], $r['pm'] ? 'PM '.$r['pm'] : null, $r['when']])->filter()->implode(' · ') }}</span>
                        </span>
                        <span class="col-span-2 sm:col-span-1">@include('dashboard._week-progress-part', ['part' => 'bar', 'r' => $r])</span>
                        <span class="hidden text-right sm:block">@include('dashboard._week-progress-part', ['part' => 'added', 'r' => $r])</span>
                        <svg class="hidden h-5 w-5 text-slate-400 transition-transform sm:block" :class="open === {{ $r['id'] }} && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
                    </button>

                    {{-- opened in place: the week's work on this project, cabinet by cabinet --}}
                    <div x-show="open === {{ $r['id'] }}" x-cloak class="mb-3 min-w-0 rounded-lg border border-slate-200 bg-white px-4 py-3 sm:ml-4">
                        <p class="text-sm text-slate-700">@include('dashboard._week-progress-part', ['part' => 'work', 'r' => $r])</p>
                        @if ($r['departments'])
                            <p class="mt-1 text-sm text-slate-700">แผนกที่ทำ: {{ collect($r['departments'])->map(fn ($n, $d) => $d.' '.$n.' รายการ')->implode(' · ') }}</p>
                        @endif
                        <ul class="mt-3 grid grid-cols-[minmax(0,1fr)] gap-2 lg:grid-cols-2">
                            @foreach ($r['cabinetList'] as $c)
                                <li class="min-w-0 rounded-lg bg-slate-50 px-3 py-2.5">
                                    <span class="flex items-baseline justify-between gap-2">
                                        <span class="min-w-0 truncate text-[15px] font-semibold text-slate-800">{{ $c['mo'] }} <span class="font-normal text-slate-600">{{ $c['name'] }}</span></span>
                                        <span class="shrink-0 text-sm tabular-nums text-slate-700">{{ $c['start'] }}% → <strong class="text-slate-900">{{ $c['now'] }}%</strong>
                                            @if ($c['now'] > $c['start'])<span class="font-semibold text-[#17805E]">+{{ $c['now'] - $c['start'] }}%</span>@endif</span>
                                    </span>
                                    <span class="mt-1.5 flex h-2 overflow-hidden rounded-full bg-slate-200" aria-hidden="true">
                                        <span class="block h-full bg-slate-400" style="width: {{ min(100, $c['start']) }}%"></span>
                                        <span class="block h-full bg-[#1F9D74]" style="width: {{ max(0, min(100, $c['now']) - min(100, $c['start'])) }}%"></span>
                                    </span>
                                    <span class="mt-1.5 block text-sm text-slate-700">
                                        @if ($c['done'])
                                            <span class="font-medium text-[#17805E]">ทำสัปดาห์นี้:</span> {{ collect($c['done'])->map(fn ($n, $task) => $task.' '.$n)->implode(' · ') }}
                                        @else
                                            <span class="text-slate-500">สัปดาห์นี้ไม่มีงานที่ทำในตู้นี้</span>
                                        @endif
                                    </span>
                                    <span class="block text-sm text-slate-600">
                                        @if ($c['now'] >= 100)
                                            <span class="font-medium text-[#17805E]">ผลิตเสร็จแล้ว</span>
                                        @elseif ($c['left'])
                                            ยังเหลือ: {{ collect($c['left'])->map(fn ($n, $task) => $task.' '.$n)->implode(' · ') }}
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                        <a href="{{ $r['url'] }}" target="_blank" rel="noopener" class="mt-3 inline-block text-sm font-semibold text-[#1479D7] hover:underline">เปิดหน้าโครงการในแท็บใหม่</a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
