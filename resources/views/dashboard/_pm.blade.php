{{-- The dashboard's "สัปดาห์นี้" view (Admin / PM); data from App\Services\PmWeek. A week as Kanban columns, Monday to
     Friday (Saturday and Sunday go into Friday): every FAT round, delivery and cabinets due of the day ($pm['board']), what needs a hand first,
     coloured by $tags below (FAT blue, ขนส่ง pink, งานผลิต (one a project, on its delivery day; coloured from its end date) grey/orange/red/green), over the full width. A panel opens beside it when asked for (the board narrows to make room): the week's overview ($pm['overview']: projects
     running and how they stand, deliveries, the projects furthest behind plan, the stuck work to chase), and, once a card
     is pressed, that appointment's details (production, cabinets finished, the round's cabinets and their Tasks).
     (the "ภาพรวมสัปดาห์" button), or a pressed card's details; ✕ or Escape puts it away. Below xl it sits under the board.
     The colours above filter the cards. A desktop (xl) sees it all in one screen, each day scrolling inside itself.
     Type roles: 17/600 title · 13/600 section · 14/600 name · 12 meta · 22/600 figure. --}}
@php
    $board = $pm['board'];
    $o = $pm['overview'];
    $states = [
        'late' => ['เลยวันส่ง', 'text-[#b91c1c]', 'bg-[#ef4444]'],
        'watch' => ['ช้ากว่าแผน', 'text-[#9a3412]', 'bg-[#f97316]'],
        'ok' => ['ตามแผน', 'text-[#047857]', 'bg-[#10b981]'],
        'close' => ['ผลิตครบ', 'text-[#1d4ed8]', 'bg-[#3b82f6]'],
        'notstarted' => ['ยังไม่เริ่ม', 'text-slate-600', 'bg-slate-400'],
    ];
    // a card's colour says what it is and how it stands: งานผลิต grey (on plan) / orange (a Task due within 2 days) / red (a
    // Task past its due date) / pink (Checklist done, not packed: พร้อมห่อ) / green (packed, or gone out); FAT blue - [label, chip, card, dot]
    // a card weighs as much as it needs a hand: red and orange a pale fill in a 2px edge, green / pink / FAT a pale fill in a
    // thin edge, grey (on plan) plain white - and a pilot lamp in its colour at the top right - [fill, edge, edge width]
    $edges = ['red' => '#dc2626', 'orange' => '#ea580c', 'fat' => '#2563eb', 'gray' => '#94a3b8', 'green' => '#db2777', 'shipped' => '#16a34a', 'pending' => '#64748b'];
    $looks = [
        'red' => ['#fef2f2', '#dc2626', 2], 'orange' => ['#fff7ed', '#ea580c', 2],
        'green' => ['#fdf2f8', '#f472b6', 1], 'shipped' => ['#f0fdf4', '#4ade80', 1], 'fat' => ['#eff6ff', '#60a5fa', 1],
        'gray' => ['#ffffff', '#cfd4da', 1], 'pending' => ['#ffffff', '#94a3b8', 1],
    ];
    $tags = [
        'red' => ['เกินกำหนด', 'bg-[#dc2626] text-white', 'bg-[#fef2f2] border-[#fca5a5]', 'bg-[#dc2626]'],
        'orange' => ['ใกล้ครบกำหนด', 'bg-[#ea580c] text-white', 'bg-[#fff7ed] border-[#fdba74]', 'bg-[#ea580c]'],
        'fat' => ['FAT', 'bg-[#2563eb] text-white', 'bg-[#eff6ff] border-[#93c5fd]', 'bg-[#2563eb]'],
        'gray' => ['ตามแผน', 'bg-slate-500 text-white', 'bg-slate-50 border-slate-300', 'bg-slate-400'],
        'green' => ['พร้อมห่อ', 'bg-[#db2777] text-white', 'bg-[#fdf2f8] border-[#f9a8d4]', 'bg-[#db2777]'],
        'shipped' => ['ห่อตู้แล้ว', 'bg-[#16a34a] text-white', 'bg-[#f0fdf4] border-[#86efac]', 'bg-[#16a34a]'],
        // รอฝ่ายขายแจ้งกำหนดส่ง: on no day, listed above the week (not a colour of the filter)
        'pending' => [\App\Models\Project::PENDING_LABEL, 'bg-slate-600 text-white', 'bg-white border-dashed border-slate-400', 'bg-slate-500'],
    ];
    $tones = ['red' => 'text-[#b91c1c]', 'amber' => 'text-[#c2410c]', 'green' => 'text-[#15803d]'];
    // a work card's chip: where the project's work is (PmWeek::STAGES)
    $stageChips = ['notstarted' => 'bg-slate-500 text-white', 'making' => 'bg-[#1d4ed8] text-white', 'made' => 'bg-[#db2777] text-white',
        'ready' => 'bg-[#16a34a] text-white', 'out' => 'bg-[#15803d] text-white', 'there' => 'bg-[#047857] text-white'];
    $week = fn (int $offset) => route('dashboard', array_filter(['view' => 'pm', 'mine' => $pm['mine'] ? 1 : null, 'week' => $offset ?: null]));
    $kpis = [
        ['โครงการที่ดำเนินการ', $o['running'], 'โครงการ', 'ยังไม่เริ่มอีก '.$o['notStarted'], 'text-slate-900'],
        ['ตามแผน', $o['onPlan'], 'โครงการ', 'ผลิตได้ทันเวลา', 'text-[#047857]'],
        ['ล่าช้า', $o['late'] + $o['watch'], 'โครงการ', 'เลยวันส่ง '.$o['late'].' · ช้ากว่าแผน '.$o['watch'], $o['late'] + $o['watch'] ? 'text-[#b91c1c]' : 'text-slate-900'],
        ['ส่งมอบสัปดาห์นี้', $o['deliveries'], 'รอบ', 'ส่งแล้ว '.$o['delivered'].' · รอส่ง '.($o['deliveries'] - $o['delivered']), 'text-slate-900'],
    ];
@endphp

{{-- the week buttons and the 5-minute refresh swap in this part only (resources/js/pm-week.js); the state filter carries over --}}
<div id="pm-board" x-data="{ sel: null, overview: false, pend: false, filter: window.pmFilter || '', q: window.pmQ || '', timer: null, destroy() { clearInterval(this.timer) },
              hit(text) { const q = this.q.trim().toLowerCase(); return ! q || q.split(/\s+/).every(w => text.includes(w)) } }"
     @keydown.escape.window="sel = null; overview = false; pend = false" x-effect="window.pmFilter = filter; window.pmQ = q"
     x-init="timer = setInterval(() => { if (! document.hidden && ! document.fullscreenElement && ! sel && ! overview && ! pend) (window.pmWeekReload || (() => location.reload()))() }, 300000)"
     class="flex flex-col gap-4 tabular-nums xl:h-[calc(100dvh-12.25rem)] xl:min-h-[30rem] [&:fullscreen]:h-screen [&:fullscreen]:bg-[#dce5ee] [&:fullscreen]:p-6">

    {{-- the week shown, and the states that filter the cards --}}
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <nav aria-label="เลือกสัปดาห์" class="inline-flex h-8 items-center rounded-lg border border-slate-300 bg-white text-sm text-slate-700">
            <a href="{{ $week($pm['offset'] - 1) }}" data-pm-nav="prev" class="flex h-full items-center px-3 hover:bg-slate-50" aria-label="สัปดาห์ก่อน">‹</a>
            <a href="{{ $week(0) }}" data-pm-nav="this" class="flex h-full items-center border-x border-slate-200 px-3 font-semibold hover:bg-slate-50" @if ($pm['offset'] === 0) aria-current="page" @endif>สัปดาห์นี้</a>
            <a href="{{ $week($pm['offset'] + 1) }}" data-pm-nav="next" class="flex h-full items-center px-3 hover:bg-slate-50" aria-label="สัปดาห์หน้า">›</a>
        </nav>
        <h2 class="text-[17px] font-semibold text-slate-900">{{ $board['label'] }}</h2>
        @if ($board['today'])
            <p class="inline-flex items-center gap-1.5 text-[13px] text-slate-600"><span class="h-2 w-2 rounded-full bg-[#0B1F3A]" aria-hidden="true"></span>วันนี้ <b class="font-semibold text-slate-900">{{ $board['today'] }}</b></p>
        @endif
        {{-- find a card: SO, project, sales, PM or site (words in any order) --}}
        <label class="relative flex h-8 w-full items-center sm:w-64">
            <span class="sr-only">ค้นหาการ์ด</span>
            <svg class="pointer-events-none absolute left-2.5 h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="9" cy="9" r="5.5"/><path d="m13.5 13.5 3.5 3.5"/></svg>
            <input type="search" x-model="q" @keydown.escape.stop="q = ''" placeholder="ค้นหา SO, ชื่อโครงการ, เซล, PM" autocomplete="off"
                   class="h-full w-full rounded-lg border border-slate-300 bg-white pl-8 pr-2 text-[13px] text-slate-900 placeholder:text-slate-400 focus:border-[#1479D7] focus:outline-none focus:ring-2 focus:ring-[#1479D7]/30">
        </label>
        <div class="ml-auto flex flex-wrap items-center gap-x-4 gap-y-1" role="group" aria-label="กรองการ์ดตามสี">
            @foreach (collect($tags)->except('pending') as $s => [$label, $chip, $bg, $dot])
                @php $c = $edges[$s]; $n = $board['tags'][$s]; $quiet = $s === 'gray'; @endphp
                <button type="button" @click="filter = filter === '{{ $s }}' ? '' : '{{ $s }}'" :aria-pressed="(filter === '{{ $s }}').toString()"
                        :style="filter === '{{ $s }}' ? 'background: {{ $c }}; border-color: {{ $c }}; color: #fff' : 'background: {{ $quiet ? '#fff' : $c.'14' }}; border-color: {{ $quiet ? '#cfd4da' : $c.'59' }}; color: {{ $quiet ? '#475569' : $c }}'"
                        class="group inline-flex h-8 items-center gap-2 rounded-full border px-3 text-[13px] font-semibold transition-colors duration-150 hover:brightness-95 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#1479D7] focus-visible:ring-offset-1 {{ $n ? '' : 'opacity-60' }}">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" aria-hidden="true"
                          :style="filter === '{{ $s }}' ? 'background: #fff' : '{{ $quiet ? 'background:#fff; box-shadow: inset 0 0 0 2px #b8bec6' : 'background:'.$c.'; box-shadow: 0 0 6px 1px '.$c.'80' }}'"></span>
                    {{ $label }}
                    <span class="text-[15px] font-bold leading-none tabular-nums">{{ $n }}</span>
                </button>
            @endforeach
            {{-- put off with no new dates yet (on no day of the week): how many, and the list in the panel --}}
            @if (count($board['pending']))
                <button type="button" @click="pend = ! (pend && ! sel); overview = false; sel = null" :aria-pressed="(pend && ! sel).toString()" aria-controls="pm-panel"
                        :class="pend && ! sel ? 'border-slate-600 bg-slate-100 text-slate-900' : 'border-dashed border-slate-400 bg-white text-slate-700 hover:bg-slate-50'"
                        class="inline-flex h-8 items-center gap-1.5 rounded-lg border px-3 text-[13px] font-semibold">{{ \App\Services\PmWeek::PENDING_STATE }}
                    <span class="rounded-full bg-slate-600 px-1.5 text-xs leading-5 text-white">{{ count($board['pending']) }}</span></button>
            @endif
            <button type="button" @click="overview = ! (overview && ! sel); pend = false; sel = null" :aria-pressed="(overview && ! sel).toString()" aria-controls="pm-panel"
                    :class="overview && ! sel ? 'border-[#1268bd] bg-[#eff6ff] text-[#1268bd]' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'"
                    class="inline-flex h-8 items-center rounded-lg border px-3 text-[13px] font-semibold">ภาพรวมสัปดาห์</button>
        </div>
    </div>

    {{-- the panel's column opens and closes by easing (the board narrows smoothly); none for reduced motion --}}
    <div class="grid gap-4 xl:min-h-0 xl:flex-1 xl:overflow-hidden xl:transition-[grid-template-columns,gap] xl:duration-200 xl:ease-out motion-reduce:transition-none"
         :class="sel || overview || pend ? 'xl:grid-cols-[minmax(0,1fr)_23.75rem] xl:gap-4' : 'xl:grid-cols-[minmax(0,1fr)_0rem] xl:gap-0'">

        {{-- the week: a column a working day --}}
        <div class="-mx-1 flex overflow-x-auto rounded-xl bg-white shadow-[0_0_0_1px_rgba(38,62,92,0.12),0_4px_16px_-8px_rgba(38,62,92,0.25)] xl:mx-0 xl:grid xl:min-h-0 xl:grid-cols-5 xl:overflow-hidden">
            @foreach ($board['days'] as $d)
                <section class="flex w-64 shrink-0 flex-col border-l border-slate-200 first:border-l-0 xl:w-auto xl:min-h-0 {{ $d['today'] ? 'bg-[#f5f8fb] shadow-[inset_0_0_0_1.5px_#262a30]' : ($d['past'] ? 'bg-slate-50/70' : 'bg-white') }}"
                         aria-label="{{ $d['name'] }} {{ $d['n'] }}{{ $d['today'] ? ' (วันนี้)' : ($d['past'] ? ' (ผ่านไปแล้ว)' : '') }}" @if ($d['today']) aria-current="date" @endif>
                    <h3 class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-2 text-[13px] font-semibold {{ $d['today'] ? 'border-b-[3px] border-[#b4622d] bg-[#262a30] text-white' : ($d['past'] ? 'border-b border-slate-200 text-slate-400' : 'border-b border-slate-200 text-slate-600') }}">
                        <span class="whitespace-nowrap">{{ $d['short'] }} <span class="{{ $d['today'] ? 'text-white' : ($d['past'] ? 'text-slate-400' : 'text-slate-900') }}">{{ $d['n'] }}</span></span>
                        @if ($d['today'])<span class="rounded-full bg-white/15 px-2 py-0.5 text-[11px] font-semibold leading-none text-white ring-1 ring-inset ring-white/30">วันนี้</span>@endif
                        @if (count($d['items']))<span class="ml-auto whitespace-nowrap text-xs font-normal {{ $d['today'] ? 'text-slate-300' : 'text-slate-500' }}">{{ count($d['items']) }} รายการ</span>@endif
                    </h3>
                    <ul class="flex flex-col gap-2.5 p-2 xl:min-h-0 xl:flex-1 xl:overflow-y-auto xl:overscroll-contain">
                        @forelse ($d['items'] as $it)
                            @php [$sText, $sClass, $sDot] = $states[$it['state']]; [, $kChip, $kBg, $kDot] = $tags[$it['tag']]; [$fill, $edge, $edgeW] = $looks[$it['tag']] ?? $looks['gray']; $lamp = $edges[$it['tag']] ?? '#94a3b8'; @endphp
                            <li x-show="(! filter || filter === '{{ $it['tag'] }}') && hit(@js(mb_strtolower(implode(' ', array_filter([$it['no'], $it['name'], $it['sales'], $it['team'] ?? null, $it['pm'], $it['site'], $it['kindName'], $it['ev']])))))">
                                <button type="button" data-project-chip :data-chip-id="{{ $it['projectId'] }}" @click="overview = false; $dispatch('open-project-panel', { id: {{ $it['projectId'] }}, url: @js($it['url']), round: @js($it['roundId'] ?? null) })" title="{{ $it['name'] }}"
                                        style="background-color: {{ $fill }}; border-color: {{ $edge }}; border-width: {{ $edgeW }}px"
                                        class="relative flex w-full flex-col gap-1 rounded-lg border-solid px-3 py-2.5 text-left shadow-[0_1px_3px_rgba(15,23,42,0.10)] transition-[box-shadow,filter] duration-150 hover:shadow-[0_4px_12px_-4px_rgba(15,23,42,0.2)] hover:brightness-[0.985] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#1479D7] focus-visible:ring-offset-1 motion-reduce:transition-none">
                                    <span class="flex w-full items-start gap-2">
                                        <span class="line-clamp-2 min-w-0 flex-1 text-[15px] font-semibold leading-snug text-slate-900">{{ $it['name'] }}</span>
                                        {{-- the pilot lamp: its colour, lit (grey on plan: unlit) --}}
                                        <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" aria-hidden="true"
                                              style="{{ $it['tag'] === 'gray' ? 'background:#fff; box-shadow: inset 0 0 0 2px #b8bec6' : 'background:'.$lamp.'; box-shadow: 0 0 0 2px #fff, 0 0 7px 1px '.$lamp.'99' }}"></span>
                                    </span>
                                    <span class="flex w-full flex-wrap items-baseline gap-x-1.5 text-xs leading-snug">
                                        <span class="shrink-0 rounded px-1.5 py-px text-[11px] font-semibold {{ isset($it['stage']) ? $stageChips[$it['stage']] : $kChip }}">{{ $it['kindName'] }}</span>
                                        <span class="min-w-0 font-medium {{ isset($it['evColor']) ? '' : ($tones[$it['tone']] ?? 'text-slate-600') }}" @isset($it['evColor']) style="color: {{ $it['evColor'] }}" @endisset>{{ $it['ev'] }}</span>
                                    </span>
                                    <span class="flex w-full min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs leading-snug text-slate-500">
                                        <span class="shrink-0 font-medium text-slate-600">{{ $it['no'] }}</span>
                                        @if ($it['jobType'])<span class="shrink-0 rounded bg-slate-100 px-1.5 text-[11px] font-medium text-slate-700 ring-1 ring-inset ring-slate-200">{{ $it['jobType'] }}</span>@endif
                                        @if ($it['sales'])<span class="min-w-0 max-w-full truncate">· เซล {{ $it['sales'] }}</span>@endif
                                        @if ($it['team'] ?? null)<span class="shrink-0 rounded bg-[#eef2ff] px-1.5 text-[11px] font-medium text-[#3730a3] ring-1 ring-inset ring-[#c7d2fe]">{{ $it['team'] }}</span>@endif
                                    </span>
                                    {{-- the delivery round and only its cabinets --}}
                                    @isset($it['round'])
                                        <span class="flex w-full min-w-0 items-start gap-1.5 text-xs leading-snug text-slate-700" title="{{ implode(', ', $it['roundCabinets']) }}">
                                            <span class="shrink-0 rounded bg-white/80 px-1.5 font-semibold text-slate-800 ring-1 ring-inset ring-slate-300">รอบ {{ $it['round'] }}{{ $it['roundCount'] > 1 ? '/'.$it['roundCount'] : '' }}</span>
                                            <span class="line-clamp-2 min-w-0">{{ count($it['roundCabinets']) }} ตู้ · {{ implode(', ', $it['roundCabinets']) }}</span>
                                        </span>
                                    @endisset
                                    @if ($it['site'])
                                        <span class="flex w-full min-w-0 items-start gap-1 text-xs leading-snug text-slate-600" title="ส่งที่ {{ $it['site'] }}"><svg class="mt-[2px] h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 17.5s5.5-4.6 5.5-9a5.5 5.5 0 10-11 0c0 4.4 5.5 9 5.5 9z"/><circle cx="10" cy="8.5" r="2"/></svg><span class="line-clamp-1">{{ $it['site'] }}</span></span>
                                    @endif
                                    @isset($it['ship'])
                                        <span class="flex w-full min-w-0 items-start gap-1 text-xs leading-snug" title="สถานะขนส่ง"><svg class="mt-[2px] h-3.5 w-3.5 shrink-0 text-slate-500" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 5h10v8H2zM12 8h3.5L18 11v2h-6z"/><circle cx="5.5" cy="14.5" r="1.5"/><circle cx="14.5" cy="14.5" r="1.5"/></svg><span class="min-w-0"><span class="text-slate-500">ขนส่ง</span> <span class="font-medium" style="color: {{ $it['shipColor'] }}">{{ $it['ship'] }}</span></span></span>
                                    @endisset
                                    <span class="mt-0.5 flex w-full flex-wrap items-center justify-between gap-x-2 gap-y-0.5 text-xs leading-snug">
                                        <span class="inline-flex min-w-0 flex-wrap items-center gap-x-1.5"><span class="text-slate-500">โครงการ</span><span class="inline-flex items-center gap-1.5 whitespace-nowrap"><span class="h-1.5 w-1.5 rounded-full {{ $sDot }}" aria-hidden="true"></span><span class="font-medium {{ $sClass }}">{{ $sText }}</span></span></span>
                                        <span class="whitespace-nowrap text-slate-500">ผลิต <b class="font-semibold {{ $it['made'] >= 100 ? 'text-[#047857]' : 'text-slate-900' }}">{{ $it['made'] }}%</b> · ตู้เสร็จ <b class="font-semibold {{ $it['total'] && $it['done'] >= $it['total'] ? 'text-[#047857]' : 'text-slate-900' }}">{{ $it['done'] }}/{{ $it['total'] }}</b></span>
                                    </span>
                                    @if ($it['why'] ?? null)<span class="text-xs leading-snug {{ $sClass }}">{{ $it['why'] }}</span>@endif
                                </button>
                            </li>
                        @empty
                            <li class="py-6 text-center text-[13px] text-slate-500">ไม่มีงาน</li>
                            @endforelse
                            @if (count($d['items']))
                                <li x-show="q.trim() && ! @js(array_map(fn ($it) => mb_strtolower(implode(' ', array_filter([$it['no'], $it['name'], $it['sales'], $it['team'] ?? null, $it['pm'], $it['site'], $it['kindName'], $it['ev']]))), $d['items'])).some(t => hit(t))" class="py-6 text-center text-[13px] text-slate-500">ไม่พบในวันนี้</li>
                            @endif
                    </ul>
                </section>
            @endforeach
        </div>

        {{-- the panel beside the board (the board narrows while it is open): the week's overview, or the pressed card's details --}}
        <aside id="pm-panel" aria-label="ภาพรวมและรายละเอียด" x-show="sel || overview || pend" x-cloak
               x-transition:enter="transition-opacity duration-200 ease-out motion-reduce:transition-none" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
               class="flex flex-col overflow-hidden rounded-xl bg-white shadow-[inset_0_0_0_1px_#e5e7eb] xl:min-h-0 xl:w-[23.75rem]">

            {{-- put off, waiting on sales for new dates: one line each, pressed = its details (✕ there comes back here) --}}
            <div x-show="! sel && pend" class="flex flex-col gap-4 p-4 xl:min-h-0 xl:flex-1 xl:overflow-y-auto">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="flex items-baseline gap-2 text-[17px] font-semibold leading-tight text-slate-900">{{ \App\Services\PmWeek::PENDING_STATE }}
                        <span class="text-[13px] font-normal text-slate-500">{{ count($board['pending']) }} รายการ</span></h3>
                    <button type="button" @click="pend = false" class="-mr-1 rounded-md px-2 py-1 text-[13px] font-semibold text-[#1268bd] hover:bg-slate-50" aria-label="ปิดรายการรอเซลแจ้งวันส่ง">✕ ปิด</button>
                </div>
                {{-- a card each: SO and its kind, when it was put off, the name, who sells / manages it, how far it is made --}}
                <ul class="flex flex-col gap-2">
                    @foreach ($board['pending'] as $it)
                        <li x-show="hit(@js(mb_strtolower(implode(' ', array_filter([$it['no'], $it['name'], $it['sales'], $it['pm'], $it['jobType']])))))">
                            <button type="button" data-project-chip :data-chip-id="{{ $it['projectId'] }}" @click="pend = false; $dispatch('open-project-panel', { id: {{ $it['projectId'] }}, url: @js($it['url']), round: @js($it['roundId'] ?? null) })" title="{{ $it['name'] }}"
                                    class="group flex w-full flex-col gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-left transition-colors duration-150 hover:border-[#1479D7] hover:bg-[#f8fbff] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#1479D7] motion-reduce:transition-none">
                                <span class="flex w-full flex-wrap items-center gap-x-1.5 gap-y-1 text-xs">
                                    <span class="font-semibold text-slate-700">{{ $it['no'] }}</span>
                                    @if ($it['jobType'])<span class="rounded bg-slate-100 px-1.5 text-[11px] font-medium text-slate-700 ring-1 ring-inset ring-slate-200">{{ $it['jobType'] }}</span>@endif
                                    <span class="ml-auto whitespace-nowrap text-slate-500">{{ \Illuminate\Support\Str::before($it['when'], ' · ') }}</span>
                                </span>
                                <span class="line-clamp-2 text-sm font-semibold leading-snug text-slate-900 group-hover:text-[#1268bd]">{{ $it['name'] }}</span>
                                <span class="text-xs text-slate-500">{{ collect([$it['sales'] ? 'เซล '.$it['sales'] : null, 'PM '.$it['pm']])->filter()->implode(' · ') }}</span>
                                <span class="mt-0.5 flex w-full items-center gap-2 text-xs">
                                    <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100" role="img" aria-label="ผลิตแล้ว {{ $it['made'] }}%">
                                        <span class="block h-full rounded-full bg-slate-500" style="width: {{ max(0, min(100, $it['made'])) }}%"></span>
                                    </span>
                                    <span class="whitespace-nowrap tabular-nums text-slate-600">ผลิต <b class="font-semibold text-slate-900">{{ $it['made'] }}%</b> · ตู้เสร็จ <b class="font-semibold text-slate-900">{{ $it['done'] }}/{{ $it['total'] }}</b></span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- overview --}}
            <div x-show="! sel && ! pend" class="flex flex-col gap-6 p-4 xl:min-h-0 xl:flex-1 xl:overflow-y-auto">
                <div>
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-[17px] font-semibold leading-tight text-slate-900">ภาพรวมสัปดาห์</h3>
                        <button type="button" @click="overview = false" class="-mr-1 rounded-md px-2 py-1 text-[13px] font-semibold text-[#1268bd] hover:bg-slate-50" aria-label="ปิดภาพรวมสัปดาห์">✕ ปิด</button>
                    </div>
                    <p class="mb-3 mt-1 text-xs text-slate-500">{{ $board['label'] }} · ข้อมูล ณ {{ $pm['asOf']->format('H:i') }} น.</p>
                    <dl class="grid grid-cols-2 gap-2">
                        @foreach ($kpis as [$label, $n, $unit, $sub, $color])
                            <div class="rounded-lg bg-slate-50 px-3 py-2.5">
                                <dt class="text-xs text-slate-500">{{ $label }}</dt>
                                <dd class="mt-0.5 flex items-baseline gap-1"><span class="text-[22px] font-semibold leading-tight {{ $color }}">{{ $n }}</span><span class="text-xs text-slate-500">{{ $unit }}</span></dd>
                                <dd class="mt-0.5 truncate text-xs text-slate-500" title="{{ $sub }}">{{ $sub }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                <div>
                    <h4 class="mb-2 flex items-baseline justify-between text-[13px] font-semibold text-slate-700">ความคืบหน้าโครงการ <span class="text-xs font-normal text-slate-500">ขีด = ควรได้ตามเวลา</span></h4>
                    <ul class="flex flex-col gap-2.5">
                        @forelse ($o['progress'] as $p)
                            @php [$pText, $pClass, $pBar] = $states[$p['state']]; @endphp
                            <li>
                                <a href="{{ $p['url'] }}" class="flex items-baseline justify-between gap-2 text-sm leading-snug hover:text-[#1479D7]">
                                    <span class="min-w-0 truncate font-semibold">{{ $p['name'] }}</span>
                                    <span class="shrink-0 text-xs text-slate-500">{{ $p['made'] }}% · <span class="font-medium {{ $pClass }}">{{ $pText }}</span></span>
                                </a>
                                <span class="relative mt-1.5 block h-1 rounded-full bg-slate-100" role="img" aria-label="ผลิตแล้ว {{ $p['made'] }}%{{ $p['plan'] !== null ? ' ควรได้ '.$p['plan'].'%' : '' }}">
                                    <span class="block h-full rounded-full {{ $pBar }}" style="width: {{ max(0, min(100, $p['made'])) }}%"></span>
                                    @if ($p['plan'] !== null)<span class="absolute -top-[3px] h-2.5 w-0.5 rounded bg-slate-700" style="left: calc({{ $p['plan'] }}% - 1px)"></span>@endif
                                </span>
                            </li>
                        @empty
                            <li class="text-[13px] text-slate-500">ไม่มีโครงการที่กำลังผลิต</li>
                        @endforelse
                    </ul>
                </div>

                {{-- the projects being made with nothing on this week's board (not yet all delivered) --}}
                <div>
                    <h4 class="mb-1 flex items-baseline justify-between text-[13px] font-semibold text-slate-700">โครงการอื่นที่กำลังทำ <span class="text-xs font-normal text-slate-500">{{ count($o['others']) }} โครงการ · ไม่มีงานสัปดาห์นี้</span></h4>
                    <ul>
                        @forelse ($o['others'] as $p)
                            @php [$pText, $pClass, $pDot] = $states[$p['state']] ?? $states['notstarted']; @endphp
                            <li class="border-t border-slate-100 first:border-t-0">
                                <a href="{{ $p['url'] }}" class="flex items-center gap-2.5 py-2 hover:[&_b]:text-[#1479D7]" title="{{ $p['name'] }}">
                                    <span class="h-2 w-2 shrink-0 rounded-full {{ $pDot }}" aria-hidden="true"></span>
                                    <span class="min-w-0 flex-1">
                                        <b class="block truncate text-sm font-semibold text-slate-900">{{ $p['name'] }}</b>
                                        <span class="block text-xs text-slate-500"><span class="font-medium {{ $pClass }}">{{ $pText }}</span> · ส่ง {{ $p['due'] }}</span>
                                    </span>
                                    <span class="shrink-0 text-xs font-semibold text-slate-700">{{ $p['made'] }}%</span>
                                </a>
                            </li>
                        @empty
                            <li class="text-[13px] text-slate-500">ทุกโครงการที่กำลังทำมีงานในสัปดาห์นี้</li>
                        @endforelse
                    </ul>
                </div>

                <div>
                    <h4 class="mb-1 text-[13px] font-semibold text-slate-700">งานที่ต้องติดตามเร่งด่วน</h4>
                    <ul>
                        @forelse ($o['urgent'] as $u)
                            <li class="border-t border-slate-100 py-2 first:border-t-0">
                                <a href="{{ $u['url'] }}" class="flex gap-2.5 hover:[&_b]:text-[#1479D7]">
                                    <span class="mt-[7px] h-2 w-2 shrink-0 rounded-full" style="background: {{ $u['color'] }}" aria-hidden="true"></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-baseline justify-between gap-2 text-sm leading-snug">
                                            <b class="min-w-0 truncate font-semibold text-slate-900">{{ $u['project'] }}</b>
                                            <span class="shrink-0 text-xs font-semibold {{ $u['late'] ? 'text-[#dc2626]' : 'text-[#b45309]' }}">{{ $u['by'] }}</span>
                                        </span>
                                        <span class="mt-0.5 block text-xs leading-snug text-slate-500"><span class="font-medium {{ $u['late'] ? 'text-[#dc2626]' : 'text-[#b45309]' }}">{{ $u['what'] }}</span> · {{ $u['who'] }}</span>
                                    </span>
                                </a>
                            </li>
                        @empty
                            <li class="rounded-lg bg-emerald-50 px-3 py-2 text-[13px] text-emerald-700">ไม่มีงานที่ติดปัญหา</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            {{-- a card's details --}}
            <template x-if="sel">
                <div class="flex flex-col xl:min-h-0 xl:flex-1" x-init="if (innerWidth < 1280) $el.scrollIntoView({ block: 'start', behavior: 'smooth' })">
                    <div class="border-b border-slate-200 px-4 pb-4 pt-3">
                        <button type="button" @click="sel = null" class="-ml-1 rounded-md px-1 py-1 text-[13px] font-semibold text-[#1268bd] hover:bg-slate-50" aria-label="ปิดรายละเอียด">✕ ปิด</button>
                        {{-- the FAT round / delivery and where it stands --}}
                        <p class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="rounded-md px-2 py-1 text-xs font-semibold" :class="@js(collect($tags)->map(fn ($t) => $t[1]))[sel.tag]" x-text="sel.kindName + ' · ' + sel.title"></span>
                            <span class="text-[13px] font-semibold" :class="sel.evColor ? '' : ({ red: 'text-[#b91c1c]', amber: 'text-[#c2410c]', green: 'text-[#15803d]' }[sel.tone] || 'text-slate-700')" :style="sel.evColor ? 'color: ' + sel.evColor : ''" x-text="sel.ev"></span>
                        </p>
                        <p class="mt-1 text-xs text-slate-500" x-text="sel.when"></p>
                        <h3 class="mt-1 text-[17px] font-semibold leading-snug text-slate-900" x-text="sel.name"></h3>
                        <p class="mt-1.5 flex items-center gap-1.5 text-xs">
                            <span class="h-1.5 w-1.5 rounded-full" :class="{ late: 'bg-[#ef4444]', watch: 'bg-[#f97316]', ok: 'bg-[#10b981]', close: 'bg-[#3b82f6]' }[sel.state] || 'bg-slate-400'"></span>
                            <span class="font-medium" :class="{ late: 'text-[#b91c1c]', watch: 'text-[#9a3412]', ok: 'text-[#047857]', close: 'text-[#1d4ed8]' }[sel.state] || 'text-slate-600'" x-text="sel.stateText"></span>
                            <span class="text-slate-500" x-text="'· PM ' + sel.pm"></span>
                        </p>
                        <p x-show="sel.why" class="mt-1.5 rounded-md px-2.5 py-1.5 text-[13px] leading-snug" :class="sel.state === 'late' ? 'bg-[#fef2f2] text-[#b91c1c]' : 'bg-[#fff7ed] text-[#9a3412]'"
                           x-text="'สาเหตุ: ' + sel.why"></p>
                        <dl class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-[13px] leading-snug">
                            <dt class="text-slate-500">เลข SO</dt><dd class="font-medium text-slate-900" x-text="sel.no"></dd>
                            <dt class="text-slate-500">ประเภทงาน</dt><dd class="text-slate-900" x-text="sel.jobType || 'ยังไม่ระบุ'" :class="sel.jobType ? '' : 'text-slate-500'"></dd>
                            <dt class="text-slate-500">เซล</dt><dd class="text-slate-900" x-text="sel.sales || 'ยังไม่ระบุ'" :class="sel.sales ? '' : 'text-slate-500'"></dd>
                            <dt class="text-slate-500">ทีม</dt><dd class="text-slate-900" x-text="sel.team || 'ยังไม่ระบุ'" :class="sel.team ? '' : 'text-slate-500'"></dd>
                            <template x-if="sel.ship">
                                <dt class="text-slate-500">ขนส่ง</dt>
                            </template>
                            <template x-if="sel.ship">
                                <dd class="font-medium" :style="'color: ' + sel.shipColor" x-text="sel.ship"></dd>
                            </template>
                            <template x-if="sel.ship && sel.ship !== 'ยังไม่นัดส่ง'">
                                <dt class="text-slate-500">สถานที่ส่ง</dt>
                            </template>
                            <template x-if="sel.ship && sel.ship !== 'ยังไม่นัดส่ง'">
                                <dd class="min-w-0">
                                    <span class="break-words text-slate-900" x-text="sel.site || 'ยังไม่ระบุ'" :class="sel.site ? '' : 'text-slate-500'"></span>
                                    <a x-show="sel.map" :href="sel.map" target="_blank" rel="noopener" class="ml-1 whitespace-nowrap font-semibold text-[#1268bd] hover:underline">เปิดแผนที่ ›</a>
                                </dd>
                            </template>
                        </dl>
                    </div>
                    <div class="flex flex-col gap-6 p-4 xl:min-h-0 xl:flex-1 xl:overflow-y-auto">
                        <dl class="grid grid-cols-2 gap-2">
                            <div class="rounded-lg bg-slate-50 px-3 py-2.5"><dt class="text-xs text-slate-500">ผลิตแล้ว (ทั้งโครงการ)</dt><dd class="mt-0.5 text-[22px] font-semibold leading-tight" x-text="sel.made + '%'"></dd></div>
                            <div class="rounded-lg bg-slate-50 px-3 py-2.5"><dt class="text-xs text-slate-500" x-text="sel.kind === 'work' ? 'ตู้ของโครงการที่เสร็จ' : 'ตู้ในนัดนี้ที่เสร็จ'"></dt><dd class="mt-0.5 text-[22px] font-semibold leading-tight" x-text="sel.done + '/' + sel.total"></dd></div>
                        </dl>

                        {{-- the round's cabinets; open one to see its Tasks --}}
                        <div>
                            <h4 class="mb-1 flex items-baseline justify-between text-[13px] font-semibold text-slate-700">
                                <span x-text="sel.kind === 'work' ? 'ตู้ของโครงการ' : 'ตู้ในนัดนี้'"></span>
                            </h4>
                            <p x-show="! sel.cabinetList.length" class="text-[13px] text-slate-500">นัดนี้ยังไม่ได้ระบุตู้</p>
                            <ul class="divide-y divide-slate-100">
                                <template x-for="(c, n) in sel.cabinetList" :key="sel.id + '-' + n">
                                    <li>
                                        {{-- a cabinet with a Task past due: a small orange dot before its MO (pointing at it says how many) --}}
                                        <details class="group" x-data="{ late: c.tasks.filter(t => t.late && ! t.done).length }">
                                            <summary class="flex cursor-pointer list-none items-center gap-2 rounded-md py-2 hover:bg-slate-50 [&::-webkit-details-marker]:hidden">
                                                <svg class="h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                                                <span class="min-w-0 flex-1" :title="c.mo + ' ' + c.name + (late ? ' · มีงานเลยกำหนด ' + late + ' งาน' : '')">
                                                    <span class="flex min-w-0 items-center gap-1.5">
                                                        <span x-show="late" class="h-2 w-2 shrink-0 rounded-full bg-[#f97316]" role="img" :aria-label="'มีงานเลยกำหนด ' + late + ' งาน'"></span>
                                                        <span class="truncate text-sm font-semibold text-slate-900" x-text="c.mo"></span>
                                                    </span>
                                                    <span class="block truncate text-xs text-slate-500" x-text="c.name"></span>
                                                    {{-- the delivery round it is in and where that round stands for it (ห่อ → เปิดบิล → ออกรถ → ส่งถึง) --}}
                                                    <span class="mt-1 flex flex-wrap items-center gap-1 text-[11px] leading-none">
                                                        <span class="mr-0.5 font-medium" :class="c.round ? 'text-slate-600' : 'text-[#c2410c]'" x-text="c.round ? 'รอบ ' + c.round + (c.roundDraft ? ' (ร่าง)' : '') : 'ยังไม่อยู่ในรอบส่ง'"></span>
                                                        <template x-for="st in (c.roundShip || [])" :key="st.key">
                                                            <span class="inline-flex items-center gap-0.5 rounded px-1.5 py-[3px] ring-1 ring-inset" :title="st.label + (st.done ? 'แล้ว' : ' · ยังไม่ทำ')"
                                                                  :class="st.done ? 'bg-[#ecfdf5] text-[#047857] ring-[#a7f3d0]' : 'bg-white text-slate-400 ring-slate-200'">
                                                                <svg x-show="st.done" class="h-2.5 w-2.5" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>
                                                                <span x-text="st.label"></span>
                                                            </span>
                                                        </template>
                                                    </span>
                                                </span>
                                                <span class="shrink-0 text-xs font-semibold" :class="c.done ? 'text-[#047857]' : 'text-slate-700'" x-text="c.done ? 'เสร็จแล้ว' : c.progress + '%'"></span>
                                            </summary>
                                            <ul class="mb-2 ml-[7px] border-l border-slate-200 pl-4">
                                                <template x-for="(t, m) in c.tasks" :key="m">
                                                    <li>
                                                        <div class="flex items-baseline justify-between gap-2 py-1 text-xs leading-snug">
                                                        <span class="flex min-w-0 items-center gap-1.5">
                                                            {{-- past due: a red warning beside the Task's name --}}
                                                            <svg x-show="t.late && ! t.done" class="h-3.5 w-3.5 shrink-0 text-[#dc2626]" viewBox="0 0 24 24" fill="currentColor" role="img" aria-label="เลยกำหนด"><path fill-rule="evenodd" d="M9.4 3.9a3 3 0 0 1 5.2 0l7.1 12.4A3 3 0 0 1 19.1 21H4.9a3 3 0 0 1-2.6-4.7L9.4 3.9ZM12 8.5a1 1 0 0 0-1 1v4a1 1 0 1 0 2 0v-4a1 1 0 0 0-1-1Zm0 7.5a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4Z" clip-rule="evenodd"/></svg>
                                                            <span class="min-w-0 truncate" :class="t.done ? 'text-slate-500' : (t.late ? 'font-medium text-[#b91c1c]' : 'text-slate-800')" :title="t.name" x-text="t.name"></span>
                                                        </span>
                                                        <span class="shrink-0 font-medium" :class="t.done ? 'text-[#047857]' : (t.late ? 'text-[#dc2626]' : 'text-slate-600')"
                                                              x-text="t.done ? 'เสร็จ' : (t.late ? 'เลยกำหนด · ' : '') + t.progress + '%'"></span>
                                                    </div>
                                                        {{-- the delivery steps whose items sit in this Task, as its sub items (ticked by งานจัดส่ง) --}}
                                                        <template x-for="(st, k) in (t.ship || [])" :key="k">
                                                            <span class="ml-3 flex items-center justify-between gap-2 border-l border-dashed border-slate-300 py-0.5 pl-2.5 text-xs leading-snug">
                                                                <span class="flex min-w-0 items-center gap-1.5">
                                                                    <span class="grid h-3.5 w-3.5 shrink-0 place-items-center rounded-[3px] ring-1 ring-inset" :class="st.done ? 'bg-[#16a34a] ring-[#16a34a]' : 'bg-white ring-slate-300'">
                                                                        <svg x-show="st.done" class="h-2.5 w-2.5 text-white" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>
                                                                    </span>
                                                                    <span :class="st.done ? 'text-slate-800' : 'text-slate-500'" x-text="st.label"></span>
                                                                </span>
                                                                <span class="shrink-0" :class="st.done ? 'text-[#047857]' : 'text-slate-400'" x-text="st.done ? st.at : 'ยังไม่ทำ'"></span>
                                                            </span>
                                                        </template>
                                                    </li>
                                                </template>
                                                <li x-show="! c.tasks.length" class="py-1 text-xs text-slate-500">ยังไม่มี Task</li>
                                                <li class="pt-1"><a :href="c.url" class="text-xs font-semibold text-[#1268bd] hover:underline">เปิดหน้าตู้ ›</a></li>
                                            </ul>
                                        </details>
                                    </li>
                                </template>
                            </ul>
                        </div>

                    </div>
                    <div class="border-t border-slate-200 px-4 py-3">
                        <a :href="sel.url" class="flex h-10 items-center justify-center rounded-lg bg-[#1268bd] text-sm font-semibold text-white hover:bg-[#0f5aa3]">เปิดหน้าโครงการ</a>
                    </div>
                </div>
            </template>
        </aside>
    </div>
</div>
