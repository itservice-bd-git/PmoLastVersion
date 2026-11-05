{{-- งานจัดส่ง (DeliveryController, Access 'edit.delivery'): the delivery department's rounds by step. Each row's buttons open
     one of the windows below (pick the day / ไม่รับงาน / ส่งถึงแล้ว), filled from its row; they post to DeliveryFlow's steps. --}}
@php
    $field = 'mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal';
    $btn = 'inline-flex h-9 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50';
    $main = 'inline-flex h-9 items-center rounded-lg bg-teal-700 px-3 text-sm font-semibold text-white hover:bg-teal-800';
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <h2 class="text-lg font-semibold text-slate-900">งานจัดส่ง</h2>
            <span class="text-sm text-slate-500">เลือกวันส่งในช่วงที่ PM ให้ · บันทึกออกจากโรงงาน / ส่งถึงแล้ว</span>
            <a href="{{ route('settings.vehicles.index') }}" class="ml-auto text-sm font-semibold text-teal-700 hover:underline">จัดการรถ / เหตุผลไม่รับงาน</a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-6xl space-y-4"
         x-data="{ r: null, busy: @js((object) $busy), vehicles: @js($vehicles), reasons: @js($reasons), people: @js($people), pick: {}, rej: {}, dlv: {},
                   wd: ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'],
                   // the people: the driver, then as many others as PM asked for (more may be added)
                   openPick(row) { this.r = row; const v = this.vehicles.find((x) => String(x.id) === String(row.vehicle_id));
                       this.pick = { date: row.date, time: row.time, vehicle_id: row.vehicle_id, driver_name: row.driver_name || (v ? v.driver : ''), carrier: row.carrier,
                           crew: [...row.crew, ...Array(Math.max(0, (row.crew_size || 1) - 1 - row.crew.length)).fill('')] }; this.$dispatch('open-modal', 'dl-pick'); },
                   get going() { return [this.pick.driver_name, ...(this.pick.crew || [])].filter((n) => n && n.trim()).length; },
                   // the vehicles of the kind PM asked for first
                   get carList() { const t = this.r ? this.r.vehicle_type : ''; return [...this.vehicles].sort((a, b) => (t && b.type === t) - (t && a.type === t)); },
                   openReject(row) { this.r = row; this.rej = { reason: '', note: '' }; this.$dispatch('open-modal', 'dl-reject'); },
                   openDeliver(row) { this.r = row; const now = new Date(); this.dlv = { date: row.delivered_date || now.toISOString().slice(0, 10), time: row.delivered_time || String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0'),
                       receiver: row.receiver, doc_no: row.doc_no, issue: row.issue, cabinet_ids: row.cabinets.map((c) => c.id) }; this.$dispatch('open-modal', 'dl-deliver'); },
                   // the window's days, each: is the vehicle already out on another round that day
                   days() {
                       if (!this.r) return [];
                       const out = [], d = new Date(this.r.from + 'T00:00:00'), end = new Date(this.r.to + 'T00:00:00');
                       for (; d <= end; d.setDate(d.getDate() + 1)) {
                           const iso = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
                           out.push({ iso, wd: this.wd[d.getDay()], day: d.getDate(), busy: this.busyOn(iso) });
                       }
                       return out;
                   },
                   busyOn(iso) { const v = this.busy[this.pick.vehicle_id] || {}; return (v[iso] || []).filter((x) => x.id !== this.r.id).map((x) => x.label); },
                   get vehicle() { return this.vehicles.find((v) => String(v.id) === String(this.pick.vehicle_id)); } }">

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
                <article class="grid gap-x-4 gap-y-2 border-t border-slate-100 px-4 py-3 first:border-t-0 md:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)_auto] md:items-center {{ $round === $row['id'] ? 'bg-amber-50/60 ring-2 ring-inset ring-amber-300' : '' }}"
                         id="round-{{ $row['id'] }}">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold text-slate-900">{{ $row['project'] }} <span class="font-semibold text-slate-600">{{ $row['name'] }}</span></p>
                        <p class="text-[13px] text-slate-600">รอบ {{ $row['round'] }} · {{ count($row['cabinets']) }} ตู้{{ $row['site'] ? ' · '.$row['site'] : '' }}</p>
                        <p class="mt-1 flex flex-wrap gap-1">
                            @foreach ($row['cabinets'] as $c)<span class="rounded-md border border-slate-300 px-1.5 text-xs font-semibold tabular-nums text-slate-700">{{ $c['name'] }}</span>@endforeach
                        </p>
                    </div>
                    <div class="min-w-0 text-[13px] text-slate-700">
                        @if ($row['request'])<p class="text-slate-500">PM ขอ <span class="font-semibold text-slate-700">{{ $row['request'] }}</span></p>@endif
                        @if ($row['vehicle_id'] || $row['method'] !== 'company')
                            <p class="font-semibold">{{ $row['method'] === 'company' ? '🚚 ' : '' }}{{ $row['transport'] }}@if ($row['other'])<span class="ml-1 rounded bg-amber-100 px-1 text-xs font-bold text-amber-800">คนละประเภท</span>@endif</p>
                        @endif
                        @if ($row['people'])<p class="{{ $row['short'] ? 'font-semibold text-amber-800' : 'text-slate-500' }}">👷 {{ $row['people'] }}</p>@endif
                        @if ($row['contact'])<p class="text-slate-500">📞 {{ $row['contact'] }}</p>@endif
                    </div>
                    <div class="min-w-0 text-[13px]">
                        @if (in_array($row['status'], ['waiting', 'rejected'], true))
                            <p class="font-semibold {{ $row['late'] ? 'text-red-700' : ($row['soon'] ? 'text-orange-700' : 'text-slate-800') }}">ช่วงส่งได้ {{ $row['window'] }}</p>
                            <p class="{{ $row['late'] ? 'text-red-700' : ($row['soon'] ? 'text-orange-700' : 'text-slate-500') }}">
                                {{ $row['late'] ? 'เลยช่วงวันแล้ว' : ($row['left'] === 0 ? 'วันนี้วันสุดท้ายที่เลือกได้' : 'เลือกได้อีก '.$row['left'].' วัน') }}
                            </p>
                            @if ($row['sender'])<p class="text-slate-500">PM {{ $row['sender'] }}</p>@endif
                        @elseif ($row['status'] === 'delivered')
                            <p class="font-semibold text-slate-800">ส่งถึง {{ $row['delivered'] }}</p>
                            <p class="text-slate-500">ผู้รับ {{ $row['receiver'] }}{{ $row['doc_no'] ? ' · ใบส่งของ '.$row['doc_no'] : '' }}</p>
                        @else
                            <p class="font-semibold {{ $row['late'] ? 'text-orange-700' : 'text-slate-800' }}">{{ $row['when'] }}</p>
                            <p class="{{ $row['late'] ? 'font-semibold text-orange-700' : 'text-slate-500' }}">{{ $row['late'] ? 'เลยวันส่งแล้ว · ยังไม่ได้บันทึกว่าส่งถึง' : ($row['departed'] ? 'ออก '.$row['departed'] : 'ช่วงที่ PM ให้ '.$row['window']) }}</p>
                        @endif
                        <span class="st-pill mt-1 inline-block rounded-full px-2 text-xs font-bold" style="--c: {{ $row['color'] }}">{{ $row['state'] }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2 md:justify-end">
                        @switch($row['status'])
                            @case('waiting')
                                <button type="button" class="{{ $main }}" @click="openPick(@js($row))">เลือกวันส่ง</button>
                                <button type="button" class="{{ $btn }}" @click="openReject(@js($row))">ไม่รับงาน</button>
                                @break
                            @case('scheduled')
                                <form method="POST" action="{{ $row['urls']['depart'] }}">@csrf<button class="{{ $main }}">{{ \App\Models\ProjectDelivery::statusName('departed') }}</button></form>
                                <button type="button" class="{{ $btn }}" @click="openDeliver(@js($row))">{{ \App\Models\ProjectDelivery::statusName('delivered') }}</button>
                                <button type="button" class="{{ $btn }}" @click="openPick(@js($row))">เลื่อน / แก้</button>
                                @break
                            @case('departed')
                                <button type="button" class="{{ $main }}" @click="openDeliver(@js($row))">{{ \App\Models\ProjectDelivery::statusName('delivered') }}</button>
                                <form method="POST" action="{{ $row['urls']['back'] }}">@csrf<button class="{{ $btn }}">ย้อนสถานะ</button></form>
                                @break
                            @case('delivered')
                                <button type="button" class="{{ $btn }}" @click="openDeliver(@js($row))">แก้</button>
                                <form method="POST" action="{{ $row['urls']['back'] }}">@csrf<button class="{{ $btn }}">ย้อนสถานะ</button></form>
                                @break
                            @default
                                <span class="text-sm text-slate-500">รอ PM แก้แล้วส่งมาอีกครั้ง</span>
                        @endswitch
                    </div>
                    @if ($row['rejected'] || $row['issue'] || $row['note'])
                        <p class="text-[13px] md:col-span-4 {{ $row['rejected'] || $row['issue'] ? 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-red-800' : 'text-slate-600' }}">
                            {{ $row['rejected'] ? 'ไม่รับงาน · '.$row['rejected'] : ($row['issue'] ? 'ปัญหาที่หน้างาน: '.$row['issue'] : 'หมายเหตุ: '.$row['note']) }}
                        </p>
                    @endif
                </article>
            @empty
                <p class="px-4 py-10 text-center text-sm text-slate-500">
                    {{ ['waiting' => 'ไม่มีรอบที่รอกำหนดวัน', 'today' => 'วันนี้ไม่มีรอบส่ง', 'scheduled' => 'ยังไม่มีรอบที่กำหนดวันแล้ว', 'departed' => 'ไม่มีรถที่ออกจากโรงงานอยู่',
                        'delivered' => 'ยังไม่มีรอบที่ส่งถึงใน 30 วันนี้', 'rejected' => 'ไม่มีรอบที่ส่งกลับให้ PM'][$tab] }}
                </p>
            @endforelse
        </section>

        {{-- pick the day (and the time, the driver, maybe another vehicle) --}}
        <x-modal name="dl-pick" maxWidth="lg">
            <template x-if="r">
                <form method="POST" :action="r.urls.schedule" class="space-y-4 p-6">
                    @csrf
                    <div>
                        <h3 class="text-base font-semibold text-slate-900" x-text="(r.date ? 'เลื่อน / แก้วันส่ง' : 'เลือกวันส่ง') + ' · ' + r.project + ' รอบ ' + r.round"></h3>
                        <p class="mt-1 text-sm text-slate-600" x-text="r.cabinets.length + ' ตู้ (' + r.cabinets.map((c) => c.name).join(', ') + ')' + (r.site ? ' · ' + r.site : '')"></p>
                        <p class="text-sm font-semibold text-teal-800" x-text="(r.sender ? 'PM ' + r.sender + ' ' : '') + 'ให้ส่งได้ ' + r.window"></p>
                    </div>
                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-800">วันส่ง *</legend>
                        <input type="hidden" name="delivery_date" :value="pick.date">
                        <div class="mt-1.5 grid grid-cols-7 gap-1 text-center">
                            <template x-for="d in days()" :key="d.iso">
                                <button type="button" @click="pick.date = d.iso" :aria-pressed="pick.date === d.iso"
                                        class="rounded-lg py-1.5 text-sm font-semibold leading-tight"
                                        :class="pick.date === d.iso ? 'bg-teal-700 text-white' : 'bg-teal-50 text-teal-900 hover:bg-teal-100'"
                                        :style="d.busy.length ? 'box-shadow: inset 0 -3px 0 #f59e0b' : ''" :title="d.busy.length ? 'รถคันนี้มีรอบอื่น: ' + d.busy.join(', ') : ''">
                                    <span class="block text-[11px] font-normal" x-text="d.wd"></span><span x-text="d.day"></span>
                                </button>
                            </template>
                        </div>
                        <p class="mt-1.5 text-xs text-slate-500">เลือกได้เฉพาะวันในช่วงที่ PM ให้ · ขีดส้ม = รถคันนี้มีรอบอื่นวันนั้น (เตือนเท่านั้น)</p>
                        <p x-show="pick.date && busyOn(pick.date).length" class="mt-1 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status"
                           x-text="'วันที่เลือก รถคันนี้มีรอบอื่นแล้ว: ' + busyOn(pick.date).join(', ')"></p>
                    </fieldset>
                    {{-- the vehicle (those of the kind PM asked for first; another kind is allowed, PM hears it) --}}
                    <div x-show="r.method === 'company'">
                        <label class="block text-sm font-semibold text-slate-800">รถ * <span x-show="r.vehicle_type" class="font-normal text-slate-500" x-text="'· PM ขอ ' + r.vehicle_type"></span>
                            <select name="vehicle_id" x-model="pick.vehicle_id" :disabled="r.method !== 'company'" :required="r.method === 'company'" class="{{ $field }}"
                                    @change="const was = vehicles.find((v) => String(v.id) === String(r.vehicle_id)); if (!pick.driver_name || pick.driver_name === (was && was.driver) || pick.driver_name === r.driver_name) pick.driver_name = vehicle ? vehicle.driver : ''">
                                <option value="">เลือกรถ</option>
                                <template x-for="v in carList" :key="v.id">
                                    <option :value="String(v.id)" :selected="String(v.id) === String(pick.vehicle_id)"
                                            x-text="v.label + (v.type && v.type !== v.name ? ' · ' + v.type : '') + (v.capacity ? ' · ~' + v.capacity + ' ตู้' : '') + (v.equipment ? ' · ' + v.equipment : '') + (r.vehicle_type && v.type !== r.vehicle_type ? ' (คนละประเภท)' : '') + (v.active ? '' : ' (เลิกใช้)')"></option>
                                </template>
                            </select>
                        </label>
                        <p x-show="vehicle && r.vehicle_type && vehicle.type && vehicle.type !== r.vehicle_type" class="mt-1 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status"
                           x-text="vehicle ? 'PM ขอ ' + r.vehicle_type + ' แต่คันนี้เป็น ' + vehicle.type + ' · บันทึกได้ PM จะได้แจ้งเตือน' : ''"></p>
                    </div>
                    <label x-show="r.method === 'external'" class="block text-sm font-semibold text-slate-800">บริษัทขนส่ง / ทะเบียน
                        <input type="text" name="carrier" x-model="pick.carrier" :disabled="r.method !== 'external'" maxlength="150" class="{{ $field }}">
                    </label>
                    {{-- the people who go: typed (names typed before are offered) --}}
                    <fieldset x-show="r.method !== 'pickup'">
                        <legend class="flex w-full items-center text-sm font-semibold text-slate-800">คนที่ไปส่ง
                            <span class="ml-2 text-xs font-bold tabular-nums" :class="r.crew_size && going < r.crew_size ? 'text-amber-700' : 'text-slate-500'"
                                  x-text="r.crew_size ? going + '/' + r.crew_size + ' คน' + (going < r.crew_size ? ' · ยังไม่ครบที่ PM ขอ' : '') : going + ' คน'"></span></legend>
                        <div class="mt-1.5 space-y-2">
                            <label class="flex items-center gap-2 text-sm text-slate-600"><span class="w-14 shrink-0">คนขับ</span>
                                <input type="text" name="driver_name" x-model="pick.driver_name" list="dl-people" maxlength="100" autocomplete="off" :disabled="r.method === 'pickup'"
                                       :placeholder="vehicle && vehicle.driver ? vehicle.driver + ' (ประจำรถ)' : 'ชื่อคนขับ'" class="block h-10 w-full rounded-lg border-slate-300 text-sm"></label>
                            <template x-for="(n, i) in pick.crew" :key="i">
                                <label class="flex items-center gap-2 text-sm text-slate-600"><span class="w-14 shrink-0" x-text="'คนที่ ' + (i + 2)"></span>
                                    <input type="text" name="crew[]" x-model="pick.crew[i]" list="dl-people" maxlength="100" autocomplete="off" :disabled="r.method === 'pickup'" placeholder="ชื่อ" class="block h-10 w-full rounded-lg border-slate-300 text-sm">
                                    <button type="button" @click="pick.crew.splice(i, 1)" class="h-10 shrink-0 rounded-lg px-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700" :aria-label="'เอาคนที่ ' + (i + 2) + ' ออก'">✕</button>
                                </label>
                            </template>
                        </div>
                        <button type="button" @click="pick.crew.push('')" class="mt-1.5 text-sm font-semibold text-teal-700 hover:underline">+ เพิ่มคน</button>
                    </fieldset>
                    <datalist id="dl-people"><template x-for="n in people" :key="n"><option :value="n"></option></template></datalist>
                    <label class="block w-40 text-sm font-semibold text-slate-800">เวลาถึงหน้างาน
                        <input type="text" name="delivery_time" x-model="pick.time" placeholder="เช่น 09:00" inputmode="numeric" maxlength="5" autocomplete="off"
                               x-init="window.flatpickr($el, { enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true, allowInput: true, defaultHour: 9, defaultDate: pick.time || null, onChange: (d, v) => { pick.time = v; } })"
                               class="{{ $field }} tabular-nums">
                    </label>
                    <div class="flex items-center gap-2 pt-1">
                        <button type="button" x-show="r.status === 'waiting'" @click="$dispatch('close-modal', 'dl-pick'); openReject(r)" class="h-10 rounded-lg px-3 text-sm font-semibold text-red-700 hover:bg-red-50">ไม่รับงาน</button>
                        <button type="button" @click="$dispatch('close-modal', 'dl-pick')" class="ml-auto h-10 rounded-lg px-4 text-sm font-semibold text-slate-600 hover:bg-slate-100">ปิด</button>
                        <button type="submit" :disabled="!pick.date" class="h-10 rounded-lg bg-teal-700 px-5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50">บันทึกวันส่ง</button>
                    </div>
                </form>
            </template>
        </x-modal>

        {{-- ไม่รับงาน: a reason (Settings > รถขนส่ง) or "อื่น ๆ" with its words; back to PM --}}
        <x-modal name="dl-reject" maxWidth="lg">
            <template x-if="r">
                <form method="POST" :action="r.urls.reject" class="space-y-4 p-6">
                    @csrf
                    <h3 class="text-base font-semibold text-slate-900" x-text="'ไม่รับงาน · ' + r.project + ' รอบ ' + r.round"></h3>
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
                        <textarea name="note" x-model="rej.note" rows="3" maxlength="500" :required="rej.reason === 'อื่น ๆ'" placeholder="เช่น คิวรถเต็มถึง 27/11 ขอเลื่อนเป็น 28–29/11" class="mt-1 block w-full rounded-lg border-slate-300 text-sm font-normal"></textarea>
                    </label>
                    <p class="text-xs text-slate-500">ส่งกลับให้ PM พร้อมเหตุผล · ไม่รับงานได้เฉพาะรอบที่ยังไม่ได้เลือกวัน</p>
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
