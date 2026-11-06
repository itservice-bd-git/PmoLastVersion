{{-- งานจัดส่ง (DeliveryController, Access 'edit.delivery'): the delivery department's rounds by step - the one place it
     works. PM set the day; here the vehicles and the people go on it (or ไม่รับงาน), each cabinet is packed, แจ้งเปิดบิล,
     it leaves and arrives. Each step posts to DeliveryFlow, which ticks the cabinets' Checklist to match. --}}
@php
    $field = 'mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal';
    $btn = 'inline-flex h-9 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50';
    $main = 'inline-flex h-9 items-center rounded-lg bg-teal-700 px-3 text-sm font-semibold text-white hover:bg-teal-800';
    $steps = ['ห่อตู้', 'แจ้งเปิดบิล', 'ออกจากโรงงาน', 'ส่งถึง'];
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <h2 class="text-lg font-semibold text-slate-900">งานจัดส่ง</h2>
            <span class="text-sm text-slate-500">จัดรถ / คนตามวันที่ PM ลง · ห่อตู้ · แจ้งเปิดบิล · ออกจากโรงงาน · ส่งถึง (Checklist ของตู้ติ๊กให้เอง)</span>
            <a href="{{ route('settings.vehicles.index') }}" class="ml-auto text-sm font-semibold text-teal-700 hover:underline">จัดการรถ / เหตุผลไม่รับงาน</a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-6xl space-y-4"
         x-data="{ r: null, busy: @js((object) $busy), vehicles: @js($vehicles), reasons: @js($reasons), people: @js($people), asg: { rows: [], carrier: '' }, rej: {}, dlv: {},
                   // จัดรถ / คน: the round's vehicles, or one empty line per kind PM asked for
                   openAssign(row) {
                       this.r = row;
                       const blank = (t) => ({ vehicle_id: '', label: '', driver_name: '', crew: [], want: t || '' });
                       const rows = row.vehicles.length ? row.vehicles.map((v) => ({ vehicle_id: v.vehicle_id, label: v.label, driver_name: v.driver_name, crew: [...v.crew], want: '' }))
                           : (row.method === 'company' && row.types.length ? row.types.map(blank) : [blank('')]);
                       this.asg = { rows, carrier: row.carrier };
                       this.$dispatch('open-modal', 'dl-assign');
                   },
                   car(id) { return this.vehicles.find((v) => String(v.id) === String(id)); },
                   // the vehicles of the kind the line was opened for first, then the others PM asked for, then the rest
                   carList(want) { const t = this.r ? this.r.types : []; const rank = (v) => (want && v.type === want ? 2 : (t.includes(v.type) ? 1 : 0)); return [...this.vehicles].sort((a, b) => rank(b) - rank(a)); },
                   pickCar(row) { const v = this.car(row.vehicle_id); if (v && !row.driver_name) row.driver_name = v.driver; },
                   takenElsewhere(row) { return row.vehicle_id && this.asg.rows.filter((x) => String(x.vehicle_id) === String(row.vehicle_id)).length > 1; },
                   // another round on the same vehicle on any of this round's days
                   busyOn(row) { const v = this.busy[row.vehicle_id] || {}; return [...new Set(this.r.days.flatMap((day) => (v[day] || []).filter((x) => x.id !== this.r.id).map((x) => x.label)))]; },
                   notAsked(row) { const v = this.car(row.vehicle_id); return v && v.type && this.r.types.length && !this.r.types.includes(v.type) ? v.type : ''; },
                   get going() { return this.asg.rows.flatMap((x) => [x.driver_name, ...x.crew]).filter((n) => n && n.trim()).length; },
                   get canSave() { return this.r && (this.r.method !== 'company' || this.asg.rows.some((x) => x.vehicle_id)) && !this.asg.rows.some((x) => this.takenElsewhere(x)); },
                   // รายการตู้ / ห่อตู้: a panel; each tick saves at once (JSON) and the round's card behind it is redrawn from the page
                   cab: { open: false, row: null, list: [], filter: 'all', q: '', error: '', saving: 0 },
                   openCabs(row) {
                       const list = row.cabinets.map((c) => ({ ...c, busy: false }));
                       const todo = list.filter((c) => !c.packed).length;
                       this.cab = { open: true, row, list, filter: row.packable && todo && todo < list.length ? 'todo' : 'all', q: '', error: '', saving: 0 };
                   },
                   cabCount(f) { return this.cab.list.filter((c) => f === 'todo' ? !c.packed : f === 'done' ? c.packed : true).length; },
                   get cabShown() {
                       const q = this.cab.q.trim().toLowerCase();
                       return this.cab.list.filter((c) => (this.cab.filter === 'todo' ? !c.packed : this.cab.filter === 'done' ? c.packed : true)
                           && (!q || [c.name, c.model, c.type, c.size].join(' ').toLowerCase().includes(q)));
                   },
                   async packCabs(list, packed) {
                       list = list.filter((c) => c.packed !== packed && !c.busy);
                       if (!list.length || !this.cab.row.packable) return;
                       const row = this.cab.row;
                       this.cab.error = ''; this.cab.saving++;
                       list.forEach((c) => { c.busy = true; c.packed = packed; });   // shown at once, put back if refused
                       try {
                           const res = await fetch(row.urls.pack, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                               body: JSON.stringify({ cabinet_ids: list.map((c) => c.id), packed: packed ? 1 : 0 }) });
                           const data = await res.json().catch(() => ({}));
                           if (!res.ok) throw new Error((data.errors && Object.values(data.errors)[0][0]) || data.message || 'บันทึกไม่ได้ ลองอีกครั้ง');
                           this.cab.list.forEach((c) => { if (c.busy && !list.includes(c)) return; c.packed = !!data.packed[c.id]; c.at = data.packed[c.id] || ''; });   // another tick still on its way keeps its own
                           this.redrawRound(row.id);
                       } catch (e) {
                           list.forEach((c) => { c.packed = !packed; });
                           this.cab.error = e.message;
                       } finally {
                           list.forEach((c) => { c.busy = false; });
                           this.cab.saving--;
                       }
                   },
                   // the round's card on the page (ห่อ n/m, แจ้งเปิดบิล) from a fresh copy of this page, without reloading it
                   redrawRound(id) {
                       clearTimeout(this._redraw);
                       this._redraw = setTimeout(async () => {
                           const html = await fetch(location.href, { headers: { 'Accept': 'text/html' } }).then((r) => r.text()).catch(() => null);
                           const fresh = html && new DOMParser().parseFromString(html, 'text/html').getElementById('round-' + id);
                           const old = document.getElementById('round-' + id);
                           if (fresh && old) old.replaceWith(fresh);
                       }, 500);
                   },
                   openReject(row) { this.r = row; this.rej = { reason: '', note: '' }; this.$dispatch('open-modal', 'dl-reject'); },
                   openDeliver(row) { this.r = row; const now = new Date(); this.dlv = { date: row.delivered_date || now.toISOString().slice(0, 10), time: row.delivered_time || String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0'),
                       receiver: row.receiver, doc_no: row.doc_no, issue: row.issue, cabinet_ids: row.cabinets.map((c) => c.id) }; this.$dispatch('open-modal', 'dl-deliver'); } }">

        @if ($errors->any())
            <p class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ $errors->first() }}</p>
        @endif

        <nav class="flex gap-1 overflow-x-auto rounded-xl border border-slate-200 bg-white p-1.5 text-sm" aria-label="ขั้นงาน">
            @foreach (\App\Http\Controllers\DeliveryController::TABS as $key => $label)
                @php $n = $tabs[$key]->count(); @endphp
                <a href="{{ route('deliveries.index', ['tab' => $key]) }}" @if ($tab === $key) aria-current="page" @endif
                   class="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 font-medium {{ $tab === $key ? 'bg-slate-800 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    {{ $label }}
                    @if ($n && $key !== 'delivered')
                        <span class="rounded-full px-1.5 text-xs font-bold tabular-nums {{ $tab === $key ? 'bg-white/20' : (in_array($key, ['waiting', 'today', 'rejected'], true) ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600') }}">{{ $n }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white" aria-label="{{ \App\Http\Controllers\DeliveryController::TABS[$tab] }}">
            @forelse ($rows as $row)
                @php
                    $before = in_array($row['status'], ['waiting', 'rejected', 'scheduled'], true);   // packing / แจ้งเปิดบิล may happen
                    $packable = $before && ! $row['billed'];
                @endphp
                <article class="grid gap-x-4 gap-y-3 border-t border-slate-100 px-4 py-3 first:border-t-0 md:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)_minmax(0,0.9fr)_auto] {{ $round === $row['id'] ? 'bg-amber-50/60 ring-2 ring-inset ring-amber-300' : '' }}"
                         id="round-{{ $row['id'] }}">
                    {{-- what goes: each cabinet with its ห่อแล้ว button --}}
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold text-slate-900">{{ $row['project'] }} <span class="font-semibold text-slate-600">{{ $row['name'] }}</span></p>
                        <p class="text-[13px] text-slate-600">รอบ {{ $row['round'] }} · {{ count($row['cabinets']) }} ตู้{{ $row['site'] ? ' · '.$row['site'] : '' }}</p>
                        {{-- the cabinets: a short line here, every one of them (name, type, size, how many) and the ห่อ buttons in the panel --}}
                        @php $cabNames = collect($row['cabinets'])->pluck('name'); @endphp
                        <div class="mt-1.5 flex flex-wrap items-center gap-2">
                            <p class="min-w-0 flex-1 truncate text-xs text-slate-600" title="{{ $cabNames->implode(', ') }}">{{ $cabNames->take(4)->implode(', ') }}{{ $cabNames->count() > 4 ? ' +'.($cabNames->count() - 4).' ตู้' : '' }}</p>
                            <button type="button" @click="openCabs(@js($row))"
                                    class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg px-3 text-sm font-semibold {{ $packable && ! $row['allPacked'] ? 'bg-teal-700 text-white hover:bg-teal-800' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-6 9 2 2 4-4"/></svg>
                                {{ $packable ? 'ห่อตู้ · รายการตู้' : 'รายการตู้' }} ({{ count($row['cabinets']) }})
                            </button>
                        </div>
                        {{-- the four steps --}}
                        <ol class="mt-2 grid max-w-sm grid-cols-4 gap-1" aria-label="ขั้น: {{ $row['step']['text'] }}">
                            @foreach ($steps as $i => $label)
                                @php $fill = $i === 0 ? ($row['step']['n'] >= 1 ? 100 : (int) round($row['packedCount'] / max(1, count($row['cabinets'])) * 100)) : ($row['step']['n'] > $i ? 100 : 0); @endphp
                                <li class="min-w-0"><span class="block h-1.5 overflow-hidden rounded-full bg-slate-200"><span class="block h-full {{ $row['step']['n'] === $i && $fill < 100 ? 'bg-sky-600' : 'bg-emerald-600' }}" style="width: {{ $fill }}%"></span></span>
                                    <span class="block truncate text-[11px] {{ $row['step']['n'] === $i ? 'font-bold text-slate-900' : ($row['step']['n'] > $i ? 'text-emerald-700' : 'text-slate-500') }}">{{ $i === 0 ? 'ห่อ '.$row['packedCount'].'/'.count($row['cabinets']) : $label }}</span></li>
                            @endforeach
                        </ol>
                    </div>
                    {{-- on what: PM's request, the vehicles put on it --}}
                    <div class="min-w-0 space-y-0.5 text-[13px] text-slate-700">
                        @if ($row['request'])<p class="text-slate-500">PM ขอ <span class="font-semibold text-slate-700">{{ $row['request'] }}</span></p>@endif
                        @forelse ($row['vehicles'] as $v)
                            <p><span class="font-semibold">{{ $row['method'] === 'company' ? '🚚 ' : '' }}{{ $v['name'] }}</span>
                                @if ($v['type'] && $row['types'] && ! in_array($v['type'], $row['types'], true))<span class="ml-1 rounded bg-amber-100 px-1 text-xs font-bold text-amber-800">ไม่ได้ขอ</span>@endif
                                @if ($v['driver_name'] || $v['crew'])<span class="text-slate-500"> · {{ implode(', ', array_filter([$v['driver_name'], ...$v['crew']])) }}</span>@endif</p>
                        @empty
                            @if ($row['method'] !== 'company')<p class="font-semibold">{{ $row['transport'] }}</p>@endif
                        @endforelse
                        @if ($row['fewer'])<p class="font-semibold text-amber-800">{{ $row['fewer'] }} · น้อยกว่าที่ขอ</p>@endif
                        @if ($row['people'])<p class="{{ $row['short'] ? 'font-semibold text-amber-800' : 'text-slate-500' }}">👷 {{ $row['people'] }}</p>@endif
                        @if ($row['contact'])<p class="text-slate-500">📞 {{ $row['contact'] }}</p>@endif
                    </div>
                    {{-- when: PM's day --}}
                    <div class="min-w-0 text-[13px]">
                        @if ($row['status'] === 'delivered')
                            <p class="font-semibold text-slate-800">ส่งถึง {{ $row['delivered'] }}</p>
                            <p class="text-slate-500">ผู้รับ {{ $row['receiver'] }}{{ $row['doc_no'] ? ' · ใบส่งของ '.$row['doc_no'] : '' }}</p>
                        @else
                            <p class="font-semibold {{ $row['late'] ? 'text-orange-700' : 'text-slate-800' }}">{{ $row['when'] }}</p>
                            <p class="{{ $row['late'] ? 'font-semibold text-orange-700' : 'text-slate-500' }}">
                                {{ $row['late'] ? 'เลยวันส่งแล้ว · ยังไม่ได้บันทึกว่าส่งถึง' : ($row['departed'] ? 'ออก '.$row['departed'] : ($row['during'] ? ($row['left'] === 0 ? 'วันนี้' : 'อยู่ในช่วงส่ง') : ($row['left'] > 0 ? 'อีก '.$row['left'].' วัน' : ''))) }}
                            </p>
                            @if ($row['sender'] && in_array($row['status'], ['waiting', 'rejected'], true))<p class="text-slate-500">PM {{ $row['sender'] }}</p>@endif
                            @if ($row['billed'])<p class="text-slate-500">แจ้งเปิดบิล {{ $row['billed'] }}</p>@endif
                        @endif
                        <span class="st-pill mt-1 inline-block rounded-full px-2 text-xs font-bold" style="--c: {{ $row['color'] }}">{{ $row['state'] }}</span>
                    </div>
                    {{-- the next step's button first --}}
                    <div class="flex flex-wrap content-start gap-2 md:max-w-[13rem] md:justify-end">
                        @if ($row['status'] === 'waiting')
                            @if ($row['method'] === 'pickup')
                                <form method="POST" action="{{ $row['urls']['assign'] }}">@csrf<button class="{{ $main }}">รับงาน</button></form>
                            @else
                                <button type="button" class="{{ $main }}" @click="openAssign(@js($row))">จัดรถ / คน</button>
                            @endif
                            <button type="button" class="{{ $btn }}" @click="openReject(@js($row))">ไม่รับงาน</button>
                        @endif
                        @if ($before && $row['status'] !== 'rejected' && $row['allPacked'] && ! $row['billed'])
                            <form method="POST" action="{{ $row['urls']['bill'] }}">@csrf<button class="{{ $row['status'] === 'scheduled' ? $main : $btn }}">แจ้งเปิดบิล</button></form>
                        @endif
                        @if ($row['status'] === 'scheduled')
                            @if ($row['billed'])
                                <form method="POST" action="{{ $row['urls']['depart'] }}">@csrf<button class="{{ $main }}">{{ \App\Models\ProjectDelivery::statusName('departed') }}</button></form>
                            @elseif (! $row['allPacked'])
                                <span class="inline-flex h-9 items-center rounded-lg border border-dashed border-slate-300 px-3 text-sm text-slate-500">ห่อให้ครบก่อน ({{ $row['packedCount'] }}/{{ count($row['cabinets']) }})</span>
                            @endif
                            @if ($row['method'] !== 'pickup')
                                <button type="button" class="{{ $btn }}" @click="openAssign(@js($row))">แก้รถ / คน</button>
                            @endif
                        @endif
                        @if ($row['status'] === 'departed')
                            <button type="button" class="{{ $main }}" @click="openDeliver(@js($row))">{{ \App\Models\ProjectDelivery::statusName('delivered') }}</button>
                        @endif
                        @if ($row['status'] === 'delivered')
                            <button type="button" class="{{ $btn }}" @click="openDeliver(@js($row))">แก้</button>
                        @endif
                        @if (in_array($row['status'], ['departed', 'delivered'], true) || ($before && $row['billed']))
                            <form method="POST" action="{{ $row['urls']['back'] }}">@csrf<button class="{{ $btn }}" title="ย้อนจาก “{{ $row['step']['text'] }}” หนึ่งขั้น · Checklist ที่ติ๊กไว้จะเอาออกด้วย">ย้อนขั้น</button></form>
                        @endif
                        @if ($row['status'] === 'rejected')
                            <span class="text-sm text-slate-500">รอ PM เลือกวันใหม่แล้วส่งมาอีกครั้ง</span>
                        @endif
                    </div>
                    @if ($row['rejected'] || $row['issue'] || $row['note'])
                        <p class="text-[13px] md:col-span-4 {{ $row['rejected'] || $row['issue'] ? 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-red-800' : 'text-slate-600' }}">
                            {{ $row['rejected'] ? 'ไม่รับงาน · '.$row['rejected'] : ($row['issue'] ? 'ปัญหาที่หน้างาน: '.$row['issue'] : 'หมายเหตุ: '.$row['note']) }}
                        </p>
                    @endif
                </article>
            @empty
                <p class="px-4 py-10 text-center text-sm text-slate-500">
                    {{ ['waiting' => 'ไม่มีรอบที่รอจัดรถ', 'today' => 'วันนี้ไม่มีรอบส่ง', 'scheduled' => 'ยังไม่มีรอบที่จัดรถแล้ว', 'departed' => 'ไม่มีรถที่ออกจากโรงงานอยู่',
                        'delivered' => 'ยังไม่มีรอบที่ส่งถึงใน 30 วันนี้', 'rejected' => 'ไม่มีรอบที่ส่งกลับให้ PM'][$tab] }}
                </p>
            @endforelse
        </section>

        {{-- จัดรถ / คน: as many vehicles as it takes, each with its driver and the people who go on it; the day is PM's --}}
        {{-- รายการตู้ / ห่อตู้: every cabinet of the round, big rows to tap (gloves, phones); a tick saves at once --}}
        <div x-show="cab.open" x-cloak role="dialog" aria-modal="true" :aria-label="cab.row ? 'รายการตู้ ' + cab.row.project + ' รอบ ' + cab.row.round : 'รายการตู้'"
             x-transition:enter="transition-opacity duration-150 ease-out" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="transition-opacity duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="fixed inset-0 z-40 flex justify-end bg-slate-900/40" @click.self="cab.open = false"
             x-effect="document.body.classList.toggle('overflow-hidden', cab.open)" @keydown.escape.window="cab.open = false">
            <div x-show="cab.open" x-transition:enter="transition-transform duration-200 ease-[cubic-bezier(.16,1,.3,1)]" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                 x-transition:leave="transition-transform duration-150 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
                 x-init="$watch('cab.open', (v) => v && $nextTick(() => $refs.closeCabs.focus({ preventScroll: true })))"
                 class="relative flex h-full w-full max-w-xl flex-col bg-white shadow-[-12px_0_32px_-12px_rgba(11,31,58,.25)] will-change-transform">
                <template x-if="cab.row">
                    <div class="flex min-h-0 flex-1 flex-col">
                        {{-- what round, how far, which ones --}}
                        <div class="space-y-3 border-b border-slate-200 p-4 pr-14 sm:p-5 sm:pr-16">
                            <div>
                                <p class="truncate text-base font-bold text-slate-900"><span x-text="cab.row.project"></span> <span class="font-semibold text-slate-600" x-text="cab.row.name"></span></p>
                                <p class="text-sm text-slate-600" x-text="'รอบ ' + cab.row.round + ' · ' + cab.row.when + ' · ' + cab.list.length + ' ตู้'"></p>
                            </div>
                            <div>
                                <div class="flex items-baseline justify-between text-sm"><span class="font-semibold text-slate-800">ห่อแล้ว</span><span class="font-bold tabular-nums text-slate-900" x-text="cabCount('done') + ' / ' + cab.list.length"></span></div>
                                <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-emerald-600 transition-[width] duration-300" :style="'width:' + (cab.list.length ? cabCount('done') / cab.list.length * 100 : 0) + '%'"></div></div>
                            </div>
                            <p x-show="!cab.row.packable" class="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700" x-text="cab.row.billed ? 'แจ้งเปิดบิลแล้ว · ดูได้อย่างเดียว (ย้อนขั้นก่อนถึงจะแก้การห่อได้)' : 'รอบนี้ออกจากโรงงานแล้ว · ดูได้อย่างเดียว'"></p>
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="inline-flex rounded-lg bg-slate-100 p-1 text-sm" role="group" aria-label="แสดง">
                                    <template x-for="f in [['todo', 'ยังไม่ห่อ'], ['done', 'ห่อแล้ว'], ['all', 'ทั้งหมด']]" :key="f[0]">
                                        <button type="button" @click="cab.filter = f[0]" :aria-pressed="cab.filter === f[0]"
                                                class="inline-flex h-9 items-center gap-1.5 rounded-md px-3 font-semibold" :class="cab.filter === f[0] ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'">
                                            <span x-text="f[1]"></span><span class="tabular-nums text-xs" :class="cab.filter === f[0] ? 'text-slate-600' : 'text-slate-500'" x-text="cabCount(f[0])"></span>
                                        </button>
                                    </template>
                                </div>
                                <label x-show="cab.list.length > 8" class="min-w-[10rem] flex-1">
                                    <span class="sr-only">ค้นหาตู้</span>
                                    <input type="search" x-model="cab.q" placeholder="ค้นหา MO / ชื่อ / ขนาด" autocomplete="off" class="block h-11 w-full rounded-lg border-slate-300 text-sm">
                                </label>
                            </div>
                        </div>

                        {{-- the cabinets --}}
                        <ul class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto overscroll-contain">
                            <template x-for="c in cabShown" :key="c.id">
                                <li>
                                    <button type="button" @click="packCabs([c], !c.packed)" :disabled="!cab.row.packable || c.busy" :aria-pressed="c.packed"
                                            class="flex min-h-[3.75rem] w-full items-center gap-3 px-4 py-2.5 text-left enabled:hover:bg-slate-50 disabled:cursor-default sm:px-5" :class="c.busy ? 'opacity-60' : ''">
                                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2 transition-colors" :class="c.packed ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 bg-white text-transparent'" aria-hidden="true">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 5 5 9-10"/></svg>
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-baseline gap-x-2"><span class="font-bold tabular-nums text-slate-900" x-text="c.name"></span><span x-show="c.model" class="min-w-0 break-words text-sm text-slate-700" x-text="c.model"></span></span>
                                            <span x-show="c.type || c.size || c.qty > 1" class="block text-xs text-slate-600" x-text="[c.type, c.size ? 'ขนาด ' + c.size : '', c.qty > 1 ? c.qty + ' ตู้' : ''].filter(Boolean).join(' · ')"></span>
                                        </span>
                                        <span class="shrink-0 text-right text-xs font-semibold" :class="c.packed ? 'text-emerald-700' : 'text-slate-500'" x-text="c.packed ? 'ห่อแล้ว' + (c.at ? ' ' + c.at : '') : 'ยังไม่ห่อ'"></span>
                                    </button>
                                </li>
                            </template>
                            <li x-show="!cabShown.length" class="px-5 py-10 text-center text-sm text-slate-500" x-text="cab.q ? 'ไม่พบตู้ที่ค้นหา' : (cab.filter === 'todo' ? 'ห่อครบทุกตู้แล้ว' : 'ยังไม่มีตู้ที่ห่อ')"></li>
                        </ul>

                        {{-- saving / a refusal, and every one left in one go --}}
                        <div class="space-y-2 border-t border-slate-200 p-4 sm:px-5">
                            <p x-show="cab.error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" x-text="cab.error"></p>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-slate-500" aria-live="polite" x-text="cab.saving ? 'กำลังบันทึก…' : (cab.row.packable ? 'กดที่ตู้เพื่อห่อ / เอาออก · บันทึกทันที' : '')"></span>
                                <button type="button" x-show="cab.row.packable && cabCount('todo')" @click="packCabs(cab.list, true)"
                                        class="ml-auto inline-flex h-11 items-center rounded-lg bg-teal-700 px-4 text-sm font-semibold text-white hover:bg-teal-800" x-text="'ห่อครบทุกตู้ที่เหลือ (' + cabCount('todo') + ')'"></button>
                                <button type="button" x-show="!cab.row.packable || !cabCount('todo')" @click="cab.open = false" class="ml-auto inline-flex h-11 items-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">ปิด</button>
                            </div>
                        </div>
                    </div>
                </template>
                <button type="button" x-ref="closeCabs" @click="cab.open = false" aria-label="ปิด"
                        class="absolute right-3 top-3 z-10 flex h-10 w-10 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
        </div>

        <x-modal name="dl-assign" maxWidth="2xl">
            <template x-if="r">
                <form method="POST" :action="r.urls.assign" class="space-y-4 p-6">
                    @csrf
                    <div>
                        <h3 class="text-base font-semibold text-slate-900" x-text="(r.vehicles.length ? 'แก้รถ / คน' : 'จัดรถ / คน') + ' · ' + r.project + ' รอบ ' + r.round"></h3>
                        <p class="mt-1 text-sm text-slate-600" x-text="r.cabinets.length + ' ตู้ (' + r.cabinets.map((c) => c.name).join(', ') + ')' + (r.site ? ' · ' + r.site : '')"></p>
                        <p class="mt-1 flex flex-wrap gap-1.5 text-sm">
                            <span class="rounded-md bg-teal-50 px-2 font-semibold text-teal-900" x-text="'ส่ง ' + r.when + ' (PM กำหนด)'"></span>
                            <span x-show="r.request" class="rounded-md bg-slate-100 px-2 text-slate-700" x-text="'PM ขอ ' + r.request"></span>
                        </p>
                    </div>
                    <label x-show="r.method === 'external'" class="block text-sm font-semibold text-slate-800">บริษัทขนส่ง
                        <input type="text" name="carrier" x-model="asg.carrier" :disabled="r.method !== 'external'" maxlength="150" class="{{ $field }}">
                    </label>
                    <div class="space-y-3">
                        <template x-for="(v, i) in asg.rows" :key="i">
                            <fieldset class="rounded-xl border border-slate-200 p-3">
                                <legend class="flex w-full items-center gap-2 px-1 text-sm font-semibold text-slate-800">
                                    <span x-text="'คันที่ ' + (i + 1)"></span>
                                    <span x-show="v.want" class="text-xs font-normal text-slate-500" x-text="'(PM ขอ ' + v.want + ')'"></span>
                                </legend>
                                <div class="grid gap-2 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_auto]">
                                    <select x-show="r.method === 'company'" :name="'vehicles[' + i + '][vehicle_id]'" x-model="v.vehicle_id" @change="pickCar(v)" :disabled="r.method !== 'company'"
                                            class="block h-10 w-full rounded-lg border-slate-300 text-sm" :aria-label="'รถคันที่ ' + (i + 1)">
                                        <option value="">เลือกรถ</option>
                                        <template x-for="c in carList(v.want)" :key="c.id">
                                            <option :value="String(c.id)" :selected="String(c.id) === String(v.vehicle_id)"
                                                    x-text="c.label + (c.type && c.type !== c.name ? ' · ' + c.type : '') + (c.capacity ? ' · ~' + c.capacity + ' ตู้' : '') + (c.equipment ? ' · ' + c.equipment : '') + (r.types.length && c.type && !r.types.includes(c.type) ? ' (ไม่ได้ขอ)' : '') + (c.active ? '' : ' (เลิกใช้)')"></option>
                                        </template>
                                    </select>
                                    <input x-show="r.method === 'external'" type="text" :name="'vehicles[' + i + '][label]'" x-model="v.label" :disabled="r.method !== 'external'" maxlength="150" placeholder="รถ / ทะเบียน" class="block h-10 w-full rounded-lg border-slate-300 text-sm">
                                    <input type="text" :name="'vehicles[' + i + '][driver_name]'" x-model="v.driver_name" list="dl-people" maxlength="100" autocomplete="off"
                                           :placeholder="car(v.vehicle_id) && car(v.vehicle_id).driver ? car(v.vehicle_id).driver + ' (ประจำรถ)' : 'คนขับ'" class="block h-10 w-full rounded-lg border-slate-300 text-sm" :aria-label="'คนขับคันที่ ' + (i + 1)">
                                    <button type="button" @click="asg.rows.splice(i, 1)" x-show="asg.rows.length > 1" class="h-10 rounded-lg px-2 text-sm text-slate-400 hover:bg-slate-100 hover:text-red-700" :aria-label="'เอาคันที่ ' + (i + 1) + ' ออก'">✕</button>
                                </div>
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <span class="text-xs text-slate-500">ไปด้วย</span>
                                    <template x-for="(n, j) in v.crew" :key="j">
                                        <span class="inline-flex items-center">
                                            <input type="text" :name="'vehicles[' + i + '][crew][]'" x-model="v.crew[j]" list="dl-people" maxlength="100" autocomplete="off" placeholder="ชื่อ" class="block h-9 w-32 rounded-lg border-slate-300 text-sm">
                                            <button type="button" @click="v.crew.splice(j, 1)" class="h-9 px-1.5 text-slate-400 hover:text-slate-700" aria-label="เอาคนนี้ออก">✕</button>
                                        </span>
                                    </template>
                                    <button type="button" @click="v.crew.push('')" class="text-sm font-semibold text-teal-700 hover:underline">+ เพิ่มคน</button>
                                </div>
                                <p x-show="takenElsewhere(v)" class="mt-2 rounded-lg bg-red-50 px-3 py-1.5 text-sm text-red-800">รถคันนี้ใส่ซ้ำ</p>
                                <p x-show="v.vehicle_id && busyOn(v).length" class="mt-2 rounded-lg bg-amber-50 px-3 py-1.5 text-sm text-amber-900" x-text="'ช่วงวันนั้นรถคันนี้มีงาน ' + busyOn(v).join(', ') + ' แล้ว (เตือนเท่านั้น)'"></p>
                                <p x-show="notAsked(v)" class="mt-2 rounded-lg bg-amber-50 px-3 py-1.5 text-sm text-amber-900" x-text="'PM ไม่ได้ขอ ' + notAsked(v) + ' · บันทึกได้ PM จะได้แจ้งเตือน'"></p>
                            </fieldset>
                        </template>
                    </div>
                    <button type="button" @click="asg.rows.push({ vehicle_id: '', label: '', driver_name: '', crew: [], want: '' })" class="text-sm font-semibold text-teal-700 hover:underline">+ เพิ่มรถ</button>
                    <datalist id="dl-people"><template x-for="n in people" :key="n"><option :value="n"></option></template></datalist>
                    <p class="text-sm font-semibold tabular-nums" :class="r.crew_size && going < r.crew_size ? 'text-amber-700' : 'text-slate-600'"
                       x-text="asg.rows.length + ' คัน · ' + going + (r.crew_size ? '/' + r.crew_size : '') + ' คน' + (r.crew_size && going < r.crew_size ? ' · น้อยกว่าที่ PM ขอ (บันทึกได้ PM จะได้แจ้งเตือน)' : '')"></p>
                    <div class="flex items-center gap-2 pt-1">
                        <button type="button" x-show="r.status === 'waiting'" @click="$dispatch('close-modal', 'dl-assign'); openReject(r)" class="h-10 rounded-lg px-3 text-sm font-semibold text-red-700 hover:bg-red-50">ไม่รับงาน</button>
                        <button type="button" @click="$dispatch('close-modal', 'dl-assign')" class="ml-auto h-10 rounded-lg px-4 text-sm font-semibold text-slate-600 hover:bg-slate-100">ปิด</button>
                        <button type="submit" :disabled="!canSave" class="h-10 rounded-lg bg-teal-700 px-5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50">บันทึก</button>
                    </div>
                </form>
            </template>
        </x-modal>

        {{-- ไม่รับงาน: a reason (Settings > รถขนส่ง) or "อื่น ๆ" with its words; back to PM for another day --}}
        <x-modal name="dl-reject" maxWidth="lg">
            <template x-if="r">
                <form method="POST" :action="r.urls.reject" class="space-y-4 p-6">
                    @csrf
                    <h3 class="text-base font-semibold text-slate-900" x-text="'ไม่รับงาน · ' + r.project + ' รอบ ' + r.round + ' · ' + r.when"></h3>
                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-800">เหตุผล *</legend>
                        <div class="mt-1.5 flex flex-wrap gap-2">
                            <template x-for="why in [...reasons, 'อื่น ๆ']" :key="why">
                                <label class="inline-flex h-9 cursor-pointer items-center rounded-full border px-3.5 text-sm font-semibold"
                                       :class="rej.reason === why ? 'border-red-300 bg-red-100 text-red-800' : 'border-slate-300 bg-white text-slate-700'">
                                    <input type="radio" name="reason" :value="why" x-model="rej.reason" class="sr-only"><span x-text="why"></span></label>
                            </template>
                        </div>
                    </fieldset>
                    <label class="block text-sm font-semibold text-slate-800">รายละเอียด <span x-show="rej.reason === 'อื่น ๆ'">*</span>
                        <textarea name="note" x-model="rej.note" rows="3" maxlength="500" :required="rej.reason === 'อื่น ๆ'" placeholder="เช่น 10 ล้อเข้าศูนย์ 17–18/11 ว่างอีกที 19/11" class="mt-1 block w-full rounded-lg border-slate-300 text-sm font-normal"></textarea>
                    </label>
                    <p class="text-xs text-slate-500">ส่งกลับให้ PM เลือกวันใหม่ · ไม่รับงานได้เฉพาะรอบที่ยังไม่ได้จัดรถ</p>
                    <div class="flex items-center gap-2 pt-1">
                        <button type="button" @click="$dispatch('close-modal', 'dl-reject')" class="ml-auto h-10 rounded-lg px-4 text-sm font-semibold text-slate-600 hover:bg-slate-100">ยกเลิก</button>
                        <button type="submit" :disabled="!rej.reason || (rej.reason === 'อื่น ๆ' && !rej.note.trim())" class="h-10 rounded-lg bg-red-700 px-5 text-sm font-semibold text-white hover:bg-red-800 disabled:opacity-50">ส่งกลับให้ PM</button>
                    </div>
                </form>
            </template>
        </x-modal>

        {{-- ส่งถึงแล้ว: when, who received it, the delivery note, a problem, the cabinets that arrived --}}
        <x-modal name="dl-deliver" maxWidth="lg">
            <template x-if="r">
                <form method="POST" :action="r.urls.deliver" class="space-y-4 p-6">
                    @csrf
                    <h3 class="text-base font-semibold text-slate-900" x-text="'{{ \App\Models\ProjectDelivery::statusName('delivered') }} · ' + r.project + ' รอบ ' + r.round"></h3>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-sm font-semibold text-slate-800">ส่งถึงวันที่ *
                            <input type="date" name="delivered_date" x-model="dlv.date" required class="{{ $field }}">
                        </label>
                        <label class="block text-sm font-semibold text-slate-800">เวลา
                            <input type="text" name="delivered_time" x-model="dlv.time" placeholder="เช่น 10:40" inputmode="numeric" maxlength="5" autocomplete="off"
                                   x-init="window.flatpickr($el, { enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true, allowInput: true, defaultDate: dlv.time || null, onChange: (d, v) => { dlv.time = v; } })"
                                   class="{{ $field }} tabular-nums">
                        </label>
                        <label class="block text-sm font-semibold text-slate-800">ชื่อผู้รับ *
                            <input type="text" name="receiver" x-model="dlv.receiver" required maxlength="150" class="{{ $field }}">
                        </label>
                        <label class="block text-sm font-semibold text-slate-800">เลขใบส่งของ
                            <input type="text" name="doc_no" x-model="dlv.doc_no" maxlength="50" class="{{ $field }}">
                        </label>
                    </div>
                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-800">ตู้ที่ส่งถึง <span class="font-normal text-slate-500">(ตู้ที่ไม่ได้ส่ง เอาติ๊กออก PM จะนัดรอบใหม่ได้)</span></legend>
                        <div class="mt-1.5 grid grid-cols-2 gap-x-4 text-sm text-slate-700">
                            <template x-for="c in r.cabinets" :key="c.id">
                                <label class="flex min-h-[2.25rem] items-center gap-2"><input type="checkbox" name="cabinet_ids[]" :value="c.id" x-model.number="dlv.cabinet_ids" class="rounded"><span x-text="c.name + (c.model ? ' · ' + c.model : '')"></span></label>
                            </template>
                        </div>
                    </fieldset>
                    <label class="block text-sm font-semibold text-slate-800">ปัญหาที่หน้างาน <span class="font-normal text-slate-500">(มีแล้ว PM ได้แจ้งเตือนแบบต้องทำ)</span>
                        <input type="text" name="issue" x-model="dlv.issue" maxlength="500" placeholder="เช่น ตู้มีรอยขีดข่วนด้านซ้าย · ลูกค้ารับไว้ก่อน" class="{{ $field }}">
                    </label>
                    <div class="flex items-center gap-2 pt-1">
                        <button type="button" @click="$dispatch('close-modal', 'dl-deliver')" class="ml-auto h-10 rounded-lg px-4 text-sm font-semibold text-slate-600 hover:bg-slate-100">ปิด</button>
                        <button type="submit" :disabled="!dlv.cabinet_ids.length" class="h-10 rounded-lg bg-teal-700 px-5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50">บันทึก</button>
                    </div>
                </form>
            </template>
        </x-modal>
    </div>
</x-app-layout>
