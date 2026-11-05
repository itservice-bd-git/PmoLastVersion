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
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            @foreach ($row['cabinets'] as $c)
                                @if ($packable)
                                    <form method="POST" action="{{ $row['urls']['pack'] }}">
                                        @csrf
                                        <input type="hidden" name="cabinet_ids[]" value="{{ $c['id'] }}"><input type="hidden" name="packed" value="{{ $c['packed'] ? 0 : 1 }}">
                                        <button class="inline-flex h-8 items-center gap-1 rounded-full border px-2.5 text-xs font-semibold tabular-nums {{ $c['packed'] ? 'border-transparent bg-emerald-100 text-emerald-800 hover:bg-emerald-200' : 'border-slate-300 bg-white text-slate-700 hover:border-emerald-500 hover:text-emerald-800' }}"
                                                title="{{ $c['packed'] ? 'ห่อแล้ว '.$c['at'].' · กดเพื่อเอาออก' : 'กดเมื่อห่อตู้นี้เสร็จ' }}" aria-pressed="{{ $c['packed'] ? 'true' : 'false' }}">
                                            {{ $c['packed'] ? '✓' : '○' }} {{ $c['name'] }}
                                        </button>
                                    </form>
                                @else
                                    <span class="inline-flex h-7 items-center rounded-full px-2.5 text-xs font-semibold tabular-nums {{ $c['packed'] || in_array($row['status'], ['departed', 'delivered'], true) ? 'bg-emerald-50 text-emerald-800' : 'border border-slate-300 text-slate-700' }}">{{ $c['name'] }}</span>
                                @endif
                            @endforeach
                            @if ($packable && $row['packedCount'] < count($row['cabinets']) - 1)
                                <form method="POST" action="{{ $row['urls']['pack'] }}">
                                    @csrf
                                    @foreach ($row['cabinets'] as $c)@unless ($c['packed'])<input type="hidden" name="cabinet_ids[]" value="{{ $c['id'] }}">@endunless @endforeach
                                    <input type="hidden" name="packed" value="1">
                                    <button class="inline-flex h-8 items-center rounded-full px-2.5 text-xs font-semibold text-teal-700 hover:bg-teal-50">ห่อครบทุกตู้</button>
                                </form>
                            @endif
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
                                <label class="flex min-h-[2.25rem] items-center gap-2"><input type="checkbox" name="cabinet_ids[]" :value="c.id" x-model.number="dlv.cabinet_ids" class="rounded"><span x-text="c.name"></span></label>
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
