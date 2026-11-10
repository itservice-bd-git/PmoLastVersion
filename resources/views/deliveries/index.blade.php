{{-- งานจัดส่ง (DeliveryController, Access 'edit.delivery'): the delivery department's rounds by step - the one place it
     works. PM set the day; here the vehicles and the people go on it (or ไม่รับงาน), each cabinet is packed, แจ้งเปิดบิล,
     it leaves and arrives. Each step posts to DeliveryFlow, which ticks the cabinets' Checklist to match. --}}
@php
    $field = 'mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal';
    $btn = 'inline-flex h-9 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50';
    $main = 'inline-flex h-9 items-center rounded-lg bg-teal-700 px-3 text-sm font-semibold text-white hover:bg-teal-800';
    $steps = ['ห่อตู้', 'แจ้งเปิดบิล', 'ออกจากโรงงาน', 'ส่งถึง'];
    // small line icons for the card's details (one family, one stroke)
    $icons = [
        'truck' => 'M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12',
        'people' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
        'phone' => 'M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z',
    ];
    $icon = fn (string $k) => '<svg class="mt-[3px] h-3.5 w-3.5 shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="'.$icons[$k].'"/></svg>';
    $check = '<svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>';
    $keep = fn (string $text, string $sep = ' · ') => collect(explode($sep, $text))->map(fn ($part) => '<span class="whitespace-nowrap">'.e($part).'</span>')->implode(e($sep));
    $phone = fn (string $text) => preg_replace('/(\+?\d[\d\- ]{5,}\d)/', '<span class="whitespace-nowrap">$1</span>', e($text));
    $cabIcon = '<svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-6 9 2 2 4-4"/></svg>';
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex min-w-0 items-baseline gap-3">
            <h2 class="shrink-0 text-lg font-semibold text-slate-900">งานจัดส่ง</h2>
            <span class="hidden truncate text-sm text-slate-500 xl:block">จัดรถ / คนตามวันที่ PM ลง · ห่อตู้ · แจ้งเปิดบิล · ออกจากโรงงาน · ส่งถึง (Checklist ของตู้ติ๊กให้เอง)</span>
        </div>
    </x-slot>

    <div class="mx-auto max-w-6xl space-y-4"
         x-data="{ r: null, tab: @js($tab), busy: @js((object) $busy), vehicles: @js($vehicles), reasons: @js($reasons), people: @js($people), dlv: { cabinet_ids: null }, toast: '',
                   rej: { open: false, reason: '', note: '', saving: false, error: '', errors: {} },
                   asg: { open: false, rows: [], carrier: '', saving: false, error: '', errors: {} },
                   // จัดรถ / คน: the round's vehicles, or one empty line per kind PM asked for
                   openAssign(row) {
                       this.r = row;
                       const blank = (t) => ({ vehicle_id: '', label: '', driver_name: '', crew: [], want: t || '' });
                       const rows = row.vehicles.length ? row.vehicles.map((v) => ({ vehicle_id: v.vehicle_id, label: v.label, driver_name: v.driver_name, crew: [...v.crew], want: '' }))
                           : (row.method === 'company' && row.types.length ? row.types.map(blank) : [blank('')]);
                       this.asg = { open: true, rows, carrier: row.carrier, saving: false, error: '', errors: {} };
                   },
                   asgErr(k) { return (this.asg.errors[k] || [])[0] || ''; },
                   // vehicles filled in so far (a picked one, or an external one's words)
                   get asgCars() { return this.asg.rows.filter((x) => this.r.method === 'company' ? x.vehicle_id : (x.label || '').trim()).length; },
                   saveAssign(form) { if (this.canSave) this.submit(form, this.asg); },
                   // a panel's form saved without reloading: the panel closes, the tabs and the list are drawn again from a fresh
                   // copy of this tab; refused, it stays open with the reason (st: asg / rej)
                   async submit(form, st) {
                       if (st.saving) return;
                       st.saving = true; st.error = ''; st.errors = {};
                       try {
                           const res = await fetch(form.action, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: new FormData(form) });
                           const data = await res.json().catch(() => ({}));
                           if (!res.ok) { st.errors = data.errors || {}; st.error = (data.errors && Object.values(data.errors)[0][0]) || data.message || 'บันทึกไม่ได้ ลองอีกครั้ง'; return; }
                           st.open = false;
                           this.say(data.message || 'บันทึกแล้ว');
                           await this.refreshList();
                       } catch (e) {
                           st.error = 'บันทึกไม่ได้ ตรวจการเชื่อมต่อแล้วลองอีกครั้ง';
                       } finally {
                           st.saving = false;
                       }
                   },
                   // a card's one-tap step (ออกจากโรงงาน, รับงาน): saved without reloading, the list drawn again
                   async quick(url) {
                       if (this._quick) return;
                       this._quick = true;
                       try {
                           const res = await fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
                           const data = await res.json().catch(() => ({}));
                           this.say(res.ok ? (data.message || 'บันทึกแล้ว') : ((data.errors && Object.values(data.errors)[0][0]) || data.message || 'บันทึกไม่ได้ ลองอีกครั้ง'));
                           if (res.ok) await this.refreshList();
                       } catch (e) {
                           this.say('บันทึกไม่ได้ ตรวจการเชื่อมต่อแล้วลองอีกครั้ง');
                       } finally {
                           this._quick = false;
                       }
                   },
                   say(m) { this.toast = m; clearTimeout(this._toast); this._toast = setTimeout(() => { this.toast = ''; }, 3500); },
                   async refreshList() {
                       const u = new URL(location.href); u.searchParams.set('tab', this.tab);
                       const html = await fetch(u, { headers: { 'Accept': 'text/html' } }).then((x) => (x.ok ? x.text() : null)).catch(() => null);
                       if (!html) { location.reload(); return; }
                       const doc = new DOMParser().parseFromString(html, 'text/html');
                       ['dl-tabs', 'dl-rows'].forEach((id) => { const n = doc.getElementById(id), o = document.getElementById(id); if (n && o) o.replaceWith(n); });
                       const live = doc.getElementById('dl-live');
                       if (live) { const d = JSON.parse(live.textContent); this.busy = d.busy; this.people = d.people; }
                   },
                   car(id) { return this.vehicles.find((v) => String(v.id) === String(id)); },
                   // จัดรถ / คน: what goes, to judge the vehicles - how many cabinets (each one's quantity), the same sizes
                   // together (biggest group first), and how many the chosen vehicles carry (รถขนส่ง's ~n ตู้; 0 = not set)
                   cabUnits(cs) { return cs.reduce((n, c) => n + (c.qty || 1), 0); },
                   cabSizes(cs) { const m = new Map(); cs.forEach((c) => { const k = (c.size || '').trim() || 'ไม่ระบุขนาด'; m.set(k, (m.get(k) || 0) + (c.qty || 1)); });
                                  return [...m].map(([size, n]) => ({ size, n })).sort((a, b) => b.n - a.n); },
                   get asgRoom() { return this.asg.rows.reduce((n, v) => n + (Number((this.car(v.vehicle_id) || {}).capacity) || 0), 0); },
                   // the vehicles of the kind the line was opened for first, then the others PM asked for, then the rest
                   carList(want) { const t = this.r ? this.r.types : []; const rank = (v) => (want && v.type === want ? 2 : (t.includes(v.type) ? 1 : 0)); return [...this.vehicles].sort((a, b) => rank(b) - rank(a)); },
                   pickCar(row) { const v = this.car(row.vehicle_id); if (v && !row.driver_name) row.driver_name = v.driver; },
                   takenElsewhere(row) { return row.vehicle_id && this.asg.rows.filter((x) => String(x.vehicle_id) === String(row.vehicle_id)).length > 1; },
                   // another round on the same vehicle on any of this round's days
                   busyOn(row) { const v = this.busy[row.vehicle_id] || {}; return [...new Set(this.r.days.flatMap((day) => (v[day] || []).filter((x) => x.id !== this.r.id).map((x) => x.label)))]; },
                   notAsked(row) { const v = this.car(row.vehicle_id); return v && v.type && this.r.types.length && !this.r.types.includes(v.type) ? v.type : ''; },
                   get going() { return this.asg.rows.flatMap((x) => [x.driver_name, ...x.crew]).filter((n) => n && n.trim()).length; },
                   get canSave() { return this.r && (this.r.method !== 'company' || this.asg.rows.some((x) => x.vehicle_id)) && !this.asg.rows.some((x) => this.takenElsewhere(x)); },
                   // ห่อตู้ → แจ้งเปิดบิล → ออกจากโรงงาน, and ย้อนขั้น: one panel; each tick saves at once (JSON) and the round's card
                   // behind it is redrawn from the page; its bottom bar is always the next thing to do (cabStep)
                   cab: { open: false, row: null, list: [], filter: 'all', q: '', error: '', saving: 0, act: '', back: false },
                   openCabs(row) {
                       const list = row.cabinets.map((c) => ({ ...c, busy: false }));
                       const todo = list.filter((c) => !c.packed).length;
                       this.cab = { open: true, row, list, filter: row.packable && todo && todo < list.length ? 'todo' : 'all', q: '', error: '', saving: 0, act: '', back: false };
                   },
                   // pack: cabinets still to pack · bill: all packed, แจ้งเปิดบิล next · wait: all packed, sent back to PM ·
                   // billed: แจ้งเปิดบิลแล้ว, not left yet · after: left the factory / arrived (look only)
                   get cabStep() {
                       const r = this.cab.row;
                       if (!r) return '';
                       if (r.billed && ['waiting', 'rejected', 'scheduled'].includes(r.status)) return 'billed';
                       if (!r.packable) return 'after';
                       if (this.cabCount('todo')) return 'pack';
                       return r.status === 'rejected' ? 'wait' : 'bill';
                   },
                   // แจ้งเปิดบิล / ออกจากโรงงาน / ย้อนขั้น from the panel: it carries on with the round as the server has it now
                   async cabAct(kind) {
                       const row = this.cab.row;
                       if (this.cab.act || this.cab.saving) return;
                       this.cab.act = kind; this.cab.error = '';
                       try {
                           const res = await fetch(row.urls[kind], { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
                           const data = await res.json().catch(() => ({}));
                           if (!res.ok) throw new Error((data.errors && Object.values(data.errors)[0][0]) || data.message || 'บันทึกไม่ได้ ลองอีกครั้ง');
                           this.say(data.message || 'บันทึกแล้ว');
                           if (kind === 'depart') {
                               this.cab.open = false;
                           } else if (data.row) {
                               const now = Object.fromEntries(data.row.cabinets.map((c) => [c.id, c]));
                               this.cab.row = data.row;
                               this.cab.list.forEach((c) => { if (now[c.id]) { c.packed = now[c.id].packed; c.at = now[c.id].at; } });
                           }
                           this.cab.back = false;
                           this.refreshList();
                       } catch (e) {
                           this.cab.error = e.message;
                       } finally {
                           this.cab.act = '';
                       }
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
                   openReject(row) { this.r = row; this.rej = { open: true, reason: '', note: '', saving: false, error: '', errors: {} }; },
                   openDeliver(row) { this.r = row; const now = new Date(); this.dlv = { date: row.delivered_date || now.toISOString().slice(0, 10), time: row.delivered_time || String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0'),
                       receiver: row.receiver, doc_no: row.doc_no, issue: row.issue, cabinet_ids: row.cabinets.map((c) => c.id) }; this.$dispatch('open-modal', 'dl-deliver'); } }">

        @if ($errors->any())
            <p class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ $errors->first() }}</p>
        @endif

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <nav id="dl-tabs" class="flex min-w-0 basis-full gap-1 sm:basis-auto sm:flex-1 overflow-x-auto rounded-xl border border-slate-200 bg-white p-1.5 text-sm" aria-label="ขั้นงาน">
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
            <a href="{{ route('settings.vehicles.index') }}" class="shrink-0 text-sm font-semibold text-teal-700 hover:underline">จัดการรถ / เหตุผลไม่รับงาน</a>
        </div>

        {{-- what the panels need that changes once something is saved; read again by refreshList() --}}
        <script type="application/json" id="dl-live">@json(['busy' => (object) $busy, 'people' => $people])</script>
        <section id="dl-rows" class="overflow-hidden rounded-xl border border-slate-200 bg-white" aria-label="{{ \App\Http\Controllers\DeliveryController::TABS[$tab] }}">
            @forelse ($rows as $row)
                @php
                    $before = in_array($row['status'], ['waiting', 'rejected', 'scheduled'], true);   // packing / แจ้งเปิดบิล may happen
                    $packable = $before && ! $row['billed'];
                @endphp
                <article class="grid gap-x-4 gap-y-3 break-words border-t border-slate-100 px-4 py-3 first:border-t-0 md:grid-cols-[minmax(0,1.9fr)_minmax(0,1fr)_minmax(0,0.9fr)_auto] {{ $round === $row['id'] ? 'bg-amber-50/60 ring-2 ring-inset ring-amber-300' : '' }}"
                         id="round-{{ $row['id'] }}">
                    @php
                        $toBill = $packable && $row['allPacked'] && $row['status'] !== 'rejected';
                        $cabLabel = $packable && ! $row['allPacked'] ? 'ห่อตู้ '.$row['packedCount'].'/'.count($row['cabinets']) : ($toBill ? 'แจ้งเปิดบิล' : 'รายการตู้ ('.count($row['cabinets']).')');
                        // the panel's button leads the card when packing / แจ้งเปิดบิล is what is next (not before the vehicles)
                        $cabLeads = $row['status'] === 'scheduled' && (($packable && ! $row['allPacked']) || $toBill);
                        $cabTitles = collect($row['cabinets'])->map(fn ($c) => $c['model'] ?: $c['name']);
                        $cabButton = fn (bool $lead) => '<button type="button" @click="openCabs('.e(\Illuminate\Support\Js::from($row)).')" class="'.($lead ? $main : $btn).' gap-1.5">'.$cabIcon.e($cabLabel).'</button>';
                    @endphp
                    {{-- what goes: the cabinets' names (every detail in the panel), and how far it is --}}
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold text-slate-900" title="{{ $row['project'] }} {{ $row['name'] }}">{{ $row['project'] }} <span class="font-semibold text-slate-600">{{ $row['name'] }}</span></p>
                        <p class="flex min-w-0 text-[13px] text-slate-600"><span class="shrink-0">รอบ {{ $row['round'] }} · {{ count($row['cabinets']) }} ตู้</span>@if ($row['site'])<span class="truncate" title="{{ $row['site'] }}">&nbsp;· {{ $row['site'] }}</span>@endif</p>
                        <p class="mt-1.5 flex items-baseline gap-1.5 text-[13px]">
                            <span class="line-clamp-2 min-w-0 text-slate-700" title="{{ $cabTitles->implode(', ') }}">{{ $cabTitles->take(8)->implode(', ') }}</span>
                            @if ($cabTitles->count() > 2)<button type="button" @click="openCabs(@js($row))" class="shrink-0 font-semibold text-teal-700 hover:underline">ดูทั้งหมด</button>@endif
                        </p>
                        {{-- the four steps --}}
                        <ol class="mt-2.5 grid max-w-md grid-cols-4 gap-1.5" aria-label="ขั้น: {{ $row['step']['text'] }}">
                            @foreach ($steps as $i => $label)
                                @php $fill = $i === 0 ? ($row['step']['n'] >= 1 ? 100 : (int) round($row['packedCount'] / max(1, count($row['cabinets'])) * 100)) : ($row['step']['n'] > $i ? 100 : 0); @endphp
                                <li class="min-w-0"><span class="block h-1.5 overflow-hidden rounded-full bg-slate-200"><span class="block h-full {{ $row['step']['n'] === $i && $fill < 100 ? 'bg-sky-600' : 'bg-emerald-600' }}" style="width: {{ $fill }}%"></span></span>
                                    <span class="mt-0.5 block truncate text-[11px] {{ $row['step']['n'] === $i ? 'font-bold text-slate-900' : ($row['step']['n'] > $i ? 'text-emerald-700' : 'text-slate-500') }}">{{ $i === 0 ? 'ห่อ '.$row['packedCount'].'/'.count($row['cabinets']) : $label }}</span></li>
                            @endforeach
                        </ol>
                    </div>
                    {{-- on what: PM's request, the vehicles put on it (after the day on a phone) --}}
                    <div class="order-2 min-w-0 space-y-1 text-[13px] text-slate-700 md:order-none">
                        @if ($row['request'])<p class="text-slate-500">PM ขอ <span class="font-semibold text-slate-700">{{ $row['request'] }}</span></p>@endif
                        @forelse ($row['vehicles'] as $v)
                            <p class="flex gap-1.5">@if ($row['method'] === 'company'){!! $icon('truck') !!}@endif<span class="line-clamp-2 min-w-0" title="{{ $v['name'] }}{{ ($v['driver_name'] || $v['crew']) ? ' · '.implode(', ', array_filter([$v['driver_name'], ...$v['crew']])) : '' }}"><span class="font-semibold">{{ $v['name'] }}</span>
                                @if ($v['type'] && $row['types'] && ! in_array($v['type'], $row['types'], true))<span class="ml-1 rounded bg-amber-100 px-1 text-xs font-bold text-amber-800">ไม่ได้ขอ</span>@endif
                                @if ($v['driver_name'] || $v['crew'])<span class="text-slate-500"> · {{ implode(', ', array_filter([$v['driver_name'], ...$v['crew']])) }}</span>@endif</span></p>
                        @empty
                            @if ($row['method'] !== 'company')<p class="font-semibold">{{ $row['transport'] }}</p>@endif
                        @endforelse
                        @if ($row['fewer'])<p class="font-semibold text-amber-800">{{ $row['fewer'] }} · น้อยกว่าที่ขอ</p>@endif
                        @if ($row['people'])<p class="flex gap-1.5 {{ $row['short'] ? 'font-semibold text-amber-800' : 'text-slate-500' }}">{!! $icon('people') !!}<span class="min-w-0">{{ $row['people'] }}</span></p>@endif
                        @if ($row['contact'])<p class="flex gap-1.5 text-slate-500">{!! $icon('phone') !!}<span class="line-clamp-2 min-w-0" title="{{ $row['contact'] }}">{!! $phone($row['contact']) !!}</span></p>@endif
                    </div>
                    {{-- when: PM's day (right under the cabinets on a phone) --}}
                    <div class="order-1 min-w-0 text-[13px] md:order-none">
                        @if ($row['status'] === 'delivered')
                            <p class="font-semibold text-slate-800">ส่งถึง {{ $row['delivered'] }}</p>
                            <p class="text-slate-500">ผู้รับ {{ $row['receiver'] }}{{ $row['doc_no'] ? ' · ใบส่งของ '.$row['doc_no'] : '' }}</p>
                        @elseif ($row['pending'])
                            {{-- รอฝ่ายขายแจ้งกำหนดส่ง (ProjectDates): the old day struck out until the new one comes --}}
                            <p class="text-slate-500 line-through">{{ $row['when'] }}</p>
                            <p class="font-semibold text-indigo-800">{{ \App\Models\Project::PENDING_LABEL }}</p>
                            <p class="text-slate-500">ห่อตู้ได้ · จัดรถเมื่อมีวันแล้ว</p>
                        @else
                            <p class="font-semibold {{ $row['late'] ? 'text-orange-700' : 'text-slate-800' }}">{!! $keep($row['when']) !!}</p>
                            <p class="{{ $row['late'] ? 'font-semibold text-orange-700' : 'text-slate-500' }}">
                                {{ $row['late'] ? 'เลยวันส่งแล้ว · ยังไม่ได้บันทึกว่าส่งถึง' : ($row['departed'] ? 'ออก '.$row['departed'] : ($row['during'] ? ($row['left'] === 0 ? 'วันนี้' : 'อยู่ในช่วงส่ง') : ($row['left'] > 0 ? 'อีก '.$row['left'].' วัน' : ''))) }}
                            </p>
                            @if ($row['sender'] && in_array($row['status'], ['waiting', 'rejected'], true))<p class="text-slate-500">PM {{ $row['sender'] }}</p>@endif
                            @if ($row['billed'])<p class="text-slate-500">แจ้งเปิดบิล {{ $row['billed'] }}</p>@endif
                        @endif
                        <span class="st-pill mt-1 inline-block rounded-full px-2 text-xs font-bold" style="--c: {{ $row['color'] }}">{{ $row['state'] }}</span>
                    </div>
                    {{-- one main button, always first: what is next for this round; then the others --}}
                    <div class="order-3 flex flex-wrap content-start gap-2 md:order-none md:max-w-[13rem] md:justify-end">
                        @if ($row['pending'])
                            {!! $cabButton(false) !!}
                        @elseif ($row['status'] === 'waiting')
                            @if ($row['method'] === 'pickup')
                                <button type="button" class="{{ $main }}" @click="quick(@js($row['urls']['assign']))">รับงาน</button>
                            @else
                                <button type="button" class="{{ $main }}" @click="openAssign(@js($row))">จัดรถ / คน</button>
                            @endif
                            {!! $cabButton(false) !!}
                            <button type="button" class="{{ $btn }}" @click="openReject(@js($row))">ไม่รับงาน</button>
                        @elseif ($row['status'] === 'scheduled')
                            @if ($cabLeads)
                                {!! $cabButton(true) !!}
                            @elseif ($row['billed'])
                                <button type="button" class="{{ $main }}" @click="quick(@js($row['urls']['depart']))">{{ \App\Models\ProjectDelivery::statusName('departed') }}</button>
                                {!! $cabButton(false) !!}
                            @else
                                {!! $cabButton(false) !!}
                            @endif
                            @if ($row['method'] !== 'pickup')
                                <button type="button" class="{{ $btn }}" @click="openAssign(@js($row))">แก้รถ / คน</button>
                            @endif
                        @elseif ($row['status'] === 'departed')
                            <button type="button" class="{{ $main }}" @click="openDeliver(@js($row))">{{ \App\Models\ProjectDelivery::statusName('delivered') }}</button>
                            {!! $cabButton(false) !!}
                        @elseif ($row['status'] === 'delivered')
                            <button type="button" class="{{ $btn }}" @click="openDeliver(@js($row))">แก้ข้อมูลส่งถึง</button>
                            {!! $cabButton(false) !!}
                        @else
                            <span class="w-full text-sm text-slate-500 md:text-right">รอ PM เลือกวันใหม่แล้วส่งมาอีกครั้ง</span>
                            {!! $cabButton(false) !!}
                        @endif
                    </div>
                    @if ($row['rejected'] || $row['issue'] || $row['note'])
                        <p class="order-4 text-[13px] md:order-none md:col-span-4 {{ $row['rejected'] || $row['issue'] ? 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-red-800' : 'text-slate-600' }}">
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
             x-transition:leave="transition-[visibility] duration-150"
             class="fixed inset-0 z-40 flex justify-end"
             x-effect="document.body.classList.toggle('overflow-hidden', cab.open || asg.open || rej.open)"
             @keydown.escape.window="if (! document.body.classList.contains('overflow-y-hidden')) cab.open = false">
            <div x-show="cab.open" @click="cab.open = false" aria-hidden="true" class="absolute inset-0 bg-slate-900/40"
                 x-transition:enter="transition-opacity duration-150 ease-out" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"></div>
            <div x-show="cab.open" x-transition:enter="transition-transform duration-200 ease-[cubic-bezier(.16,1,.3,1)]" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                 x-transition:leave="transition-transform duration-150 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
                 x-init="$watch('cab.open', (v) => v && $nextTick(() => $refs.closeCabs.focus({ preventScroll: true })))"
                 class="relative flex h-full w-full max-w-xl flex-col break-words bg-white shadow-[-12px_0_32px_-12px_rgba(11,31,58,.25)] will-change-transform">
                <template x-if="cab.row">
                    <div class="flex min-h-0 flex-1 flex-col">
                        {{-- what round, how far, which ones --}}
                        <div class="space-y-3 border-b border-slate-200 p-4 pr-14 sm:p-5 sm:pr-16">
                            <div>
                                <p class="truncate text-base font-bold text-slate-900" :title="cab.row.project + ' ' + cab.row.name"><span x-text="cab.row.project"></span> <span class="font-semibold text-slate-600" x-text="cab.row.name"></span></p>
                                <p class="text-sm text-slate-600" x-text="'รอบ ' + cab.row.round + ' · ' + cab.row.when + ' · ' + cab.list.length + ' ตู้'"></p>
                            </div>
                            {{-- ห่อตู้ → แจ้งเปิดบิล --}}
                            <div>
                                <ol class="flex items-center gap-2 text-sm" aria-label="ขั้น">
                                    <li class="flex items-center gap-1.5 font-semibold" :class="cabCount('todo') ? 'text-slate-900' : 'text-emerald-700'">
                                        <span class="flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-bold" :class="cabCount('todo') ? 'bg-sky-600 text-white' : 'bg-emerald-600 text-white'" aria-hidden="true"><span x-show="cabCount('todo')">1</span><span x-show="!cabCount('todo')">{!! $check !!}</span></span>
                                        <span x-text="'ห่อตู้ ' + cabCount('done') + '/' + cab.list.length"></span>
                                    </li>
                                    <li class="h-px w-6 shrink-0 bg-slate-300" aria-hidden="true"></li>
                                    <li class="flex min-w-0 items-center gap-1.5 font-semibold" :class="cab.row.billed ? 'text-emerald-700' : (cabStep === 'bill' ? 'text-slate-900' : 'text-slate-500')">
                                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold" :class="cab.row.billed ? 'bg-emerald-600 text-white' : (cabStep === 'bill' ? 'bg-sky-600 text-white' : 'border border-slate-300 text-slate-500')" aria-hidden="true"><span x-show="!cab.row.billed">2</span><span x-show="cab.row.billed">{!! $check !!}</span></span>
                                        <span class="truncate" x-text="cab.row.billed ? 'แจ้งเปิดบิล ' + cab.row.billed : 'แจ้งเปิดบิล'"></span>
                                    </li>
                                </ol>
                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-emerald-600 transition-[width] duration-300" :style="'width:' + (cab.list.length ? cabCount('done') / cab.list.length * 100 : 0) + '%'"></div></div>
                            </div>
                            <p x-show="cabStep === 'billed' || cabStep === 'after'" class="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700"
                               x-text="cabStep === 'billed' ? 'แจ้งเปิดบิลแล้ว · แก้การห่อไม่ได้ (ย้อนขั้นก่อนถึงจะแก้ได้)' : 'รอบนี้' + (cab.row.status === 'delivered' ? 'ส่งถึงแล้ว' : 'ออกจากโรงงานแล้ว') + ' · ดูได้อย่างเดียว'"></p>
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

                        {{-- the cabinets, in columns: เลข MO | ชื่อตู้ (type · size · how many) | ห่อแล้ว; big rows to tap --}}
                        @php $cols = 'grid grid-cols-[1.75rem_minmax(0,7rem)_minmax(0,1fr)_auto] items-center gap-x-3 sm:grid-cols-[1.75rem_9rem_minmax(0,1fr)_auto]'; @endphp
                        <ul class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto overscroll-contain" aria-label="รายการตู้">
                            <li x-show="cabShown.length" class="{{ $cols }} sticky top-0 z-[1] border-b border-slate-200 bg-slate-50 px-4 py-1.5 text-xs font-semibold text-slate-500 sm:px-5" aria-hidden="true">
                                <span></span><span>เลข MO</span><span>ชื่อตู้ · ประเภท · ขนาด</span><span class="text-right">สถานะ</span>
                            </li>
                            <template x-for="c in cabShown" :key="c.id">
                                <li>
                                    <button type="button" @click="packCabs([c], !c.packed)" :disabled="!cab.row.packable || c.busy" :aria-pressed="c.packed"
                                            :aria-label="(c.model ? c.name + ' ' + c.model : c.name) + (c.packed ? ' ห่อแล้ว' : ' ยังไม่ห่อ')"
                                            class="{{ $cols }} min-h-[3.75rem] w-full px-4 py-2.5 text-left enabled:hover:bg-slate-50 disabled:cursor-default sm:px-5" :class="c.busy ? 'opacity-60' : ''">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-full border-2 transition-colors" :class="c.packed ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 bg-white text-transparent'" aria-hidden="true">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 5 5 9-10"/></svg>
                                        </span>
                                        {{-- เลข MO (none: -) --}}
                                        <span class="break-words text-sm font-bold tabular-nums text-slate-900" x-text="c.model ? c.name : '-'"></span>
                                        {{-- ชื่อตู้, then what it is --}}
                                        <span class="min-w-0">
                                            <span class="block break-words text-sm font-semibold text-slate-800" x-text="c.model || c.name"></span>
                                            <span x-show="c.type || c.size || c.qty > 1" class="block text-xs text-slate-600" x-text="[c.type, c.size ? 'ขนาด ' + c.size : '', c.qty > 1 ? c.qty + ' ตู้' : ''].filter(Boolean).join(' · ')"></span>
                                        </span>
                                        <span class="text-right text-xs font-semibold leading-tight" :class="c.packed ? 'text-emerald-700' : 'text-slate-500'">
                                            <span class="block" x-text="c.packed ? 'ห่อแล้ว' : 'ยังไม่ห่อ'"></span>
                                            <span x-show="c.packed && c.at" class="block font-normal tabular-nums" x-text="c.at"></span>
                                        </span>
                                    </button>
                                </li>
                            </template>
                            <li x-show="!cabShown.length" class="px-5 py-10 text-center text-sm text-slate-500" x-text="cab.q ? 'ไม่พบตู้ที่ค้นหา' : (cab.filter === 'todo' ? 'ห่อครบทุกตู้แล้ว' : 'ยังไม่มีตู้ที่ห่อ')"></li>
                        </ul>

                        {{-- the next thing to do: ห่อที่เหลือ → แจ้งเปิดบิล → ออกจากโรงงาน; ย้อนขั้น asks first --}}
                        <div class="space-y-2 border-t border-slate-200 p-4 sm:px-5">
                            <p x-show="cab.error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" x-text="cab.error"></p>
                            {{-- ย้อนขั้น: sure? --}}
                            <div x-show="cab.back" class="space-y-2">
                                <p class="text-sm text-slate-800">ย้อนจาก <span class="font-bold" x-text="'“' + cab.row.step.text + '”'"></span> หนึ่งขั้น · Checklist ที่ติ๊กไว้ของขั้นนี้จะเอาออกด้วย</p>
                                <div class="flex items-center gap-2">
                                    <button type="button" @click="cab.back = false" class="ml-auto inline-flex h-11 items-center rounded-lg px-4 text-sm font-semibold text-slate-600 hover:bg-slate-100">ไม่ย้อน</button>
                                    <button type="button" @click="cabAct('back')" :disabled="!!cab.act" class="inline-flex h-11 items-center rounded-lg bg-red-700 px-4 text-sm font-semibold text-white hover:bg-red-800 disabled:opacity-50" x-text="cab.act === 'back' ? 'กำลังย้อน…' : 'ย้อนขั้น'"></button>
                                </div>
                            </div>
                            <div x-show="!cab.back" class="flex flex-wrap items-center gap-2">
                                {{-- ห่อตู้ --}}
                                <template x-if="cabStep === 'pack'">
                                    <div class="contents">
                                        <span class="min-w-0 flex-1 text-xs text-slate-500" aria-live="polite" x-text="cab.saving ? 'กำลังบันทึก…' : 'กดที่ตู้เพื่อห่อ / เอาออก · แจ้งเปิดบิลได้เมื่อห่อครบ'"></span>
                                        <button type="button" @click="packCabs(cab.list, true)" class="inline-flex h-11 items-center rounded-lg bg-teal-700 px-4 text-sm font-semibold text-white hover:bg-teal-800" x-text="'ห่อครบที่เหลือ (' + cabCount('todo') + ')'"></button>
                                    </div>
                                </template>
                                {{-- แจ้งเปิดบิล --}}
                                <template x-if="cabStep === 'bill'">
                                    <div class="contents">
                                        <span class="min-w-0 flex-1 text-sm"><span class="inline-flex items-center gap-1 font-semibold text-emerald-700">{!! $check !!}<span x-text="'ห่อครบ ' + cab.list.length + '/' + cab.list.length + ' แล้ว'"></span></span>
                                            <span class="block text-xs text-slate-500" x-text="cab.saving ? 'กำลังบันทึก…' : 'แจ้งฝ่ายบัญชีเปิดบิลรอบนี้ · เอาการห่อออกได้จนกว่าจะแจ้ง'"></span></span>
                                        <button type="button" @click="cabAct('bill')" :disabled="!!cab.act || cab.saving > 0" class="inline-flex h-11 items-center rounded-lg bg-teal-700 px-5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50" x-text="cab.act === 'bill' ? 'กำลังแจ้ง…' : 'แจ้งเปิดบิล'"></button>
                                    </div>
                                </template>
                                <template x-if="cabStep === 'wait'">
                                    <div class="contents">
                                        <span class="min-w-0 flex-1 text-sm text-slate-600">ห่อครบแล้ว · รอ PM ส่งรอบนี้มาใหม่ก่อนแจ้งเปิดบิล</span>
                                        <button type="button" @click="cab.open = false" class="inline-flex h-11 items-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">ปิด</button>
                                    </div>
                                </template>
                                {{-- แจ้งเปิดบิลแล้ว: ออกจากโรงงาน next (once the vehicles are on it) --}}
                                <template x-if="cabStep === 'billed'">
                                    <div class="contents">
                                        <button type="button" @click="cab.back = true" class="inline-flex h-11 items-center rounded-lg px-3 text-sm font-semibold text-slate-600 hover:bg-slate-100">ย้อนขั้น</button>
                                        <span x-show="cab.row.status !== 'scheduled'" class="ml-auto text-xs text-slate-500">จัดรถ / คนก่อนถึงจะออกจากโรงงานได้</span>
                                        <button type="button" x-show="cab.row.status === 'scheduled'" @click="cabAct('depart')" :disabled="!!cab.act" class="ml-auto inline-flex h-11 items-center rounded-lg bg-teal-700 px-5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50" x-text="cab.act === 'depart' ? 'กำลังบันทึก…' : 'ออกจากโรงงาน'"></button>
                                    </div>
                                </template>
                                {{-- ออกจากโรงงาน / ส่งถึงแล้ว: look, or one step back --}}
                                <template x-if="cabStep === 'after'">
                                    <div class="contents">
                                        <button type="button" @click="cab.back = true" class="inline-flex h-11 items-center rounded-lg px-3 text-sm font-semibold text-slate-600 hover:bg-slate-100">ย้อนขั้น</button>
                                        <button type="button" @click="cab.open = false" class="ml-auto inline-flex h-11 items-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">ปิด</button>
                                    </div>
                                </template>
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

        {{-- จัดรถ / คน: a panel (full screen on a phone) - PM's request and how much of it is filled stay on top, the save button
             at the bottom; saved without reloading (saveAssign) --}}
        <div x-show="asg.open" x-cloak role="dialog" aria-modal="true" :aria-label="r ? (r.vehicles.length ? 'แก้รถ / คน ' : 'จัดรถ / คน ') + r.project + ' รอบ ' + r.round : 'จัดรถ / คน'"
             x-transition:leave="transition-[visibility] duration-150"
             class="fixed inset-0 z-40 flex justify-end"
             @keydown.escape.window="if (! document.body.classList.contains('overflow-y-hidden')) asg.open = false">
            <div x-show="asg.open" @click="asg.open = false" aria-hidden="true" class="absolute inset-0 bg-slate-900/40"
                 x-transition:enter="transition-opacity duration-150 ease-out" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"></div>
            <div x-show="asg.open" x-transition:enter="transition-transform duration-200 ease-[cubic-bezier(.16,1,.3,1)]" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                 x-transition:leave="transition-transform duration-150 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
                 x-init="$watch('asg.open', (v) => v && $nextTick(() => $refs.closeAsg.focus({ preventScroll: true })))"
                 class="relative flex h-full w-full max-w-xl flex-col break-words bg-white shadow-[-12px_0_32px_-12px_rgba(11,31,58,.25)] will-change-transform">
                <template x-if="r">
                    <form method="POST" :action="r.urls.assign" class="flex min-h-0 flex-1 flex-col" @submit.prevent="saveAssign($el)">
                        @csrf
                        {{-- the round, what PM asked for, how much of it is filled --}}
                        <div class="space-y-2.5 border-b border-slate-200 p-4 pr-14 sm:p-5 sm:pr-16">
                            <div>
                                <p class="text-xs font-semibold text-slate-500" x-text="r.vehicles.length ? 'แก้รถ / คน' : 'จัดรถ / คน'"></p>
                                <p class="truncate text-base font-bold text-slate-900" :title="r.project + ' ' + r.name"><span x-text="r.project"></span> <span class="font-semibold text-slate-600" x-text="r.name"></span></p>
                                <p class="line-clamp-2 text-sm text-slate-600" :title="r.site" x-text="'รอบ ' + r.round + ' · ' + r.when + ' · ' + r.cabinets.length + ' ตู้' + (r.site ? ' · ' + r.site : '')"></p>
                            </div>
                            <p x-show="r.request" class="text-sm text-slate-600">PM ขอ <span class="font-semibold text-slate-900" x-text="r.request"></span></p>
                            <div x-show="r.method !== 'pickup'" class="flex flex-wrap gap-2 text-sm">
                                <span x-show="r.method === 'company' && r.types.length" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 font-semibold tabular-nums"
                                      :class="asgCars >= r.types.length ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800'"
                                      ><span x-text="'รถ ' + asgCars + '/' + r.types.length + ' คัน'"></span><span x-show="asgCars >= r.types.length">{!! $check !!}</span></span>
                                <span x-show="r.method === 'company' && !r.types.length" class="rounded-full bg-slate-100 px-2.5 py-0.5 font-semibold tabular-nums text-slate-700" x-text="'รถ ' + asgCars + ' คัน'"></span>
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 font-semibold tabular-nums"
                                      :class="!r.crew_size ? 'bg-slate-100 text-slate-700' : (going >= r.crew_size ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800')"
                                      ><span x-text="r.crew_size ? 'คน ' + going + '/' + r.crew_size : going + ' คน'"></span><span x-show="r.crew_size && going >= r.crew_size">{!! $check !!}</span></span>
                            </div>
                        </div>

                        {{-- each vehicle: which one, its driver, who else goes --}}
                        <div class="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain p-4 sm:p-5">
                            {{-- what goes, to judge the vehicles and people: how many, the sizes together, against what the chosen
                                 vehicles carry; then each cabinet (name, MO, type, size, how many) - a long list opens in place --}}
                            <section x-data="{ more: false }" x-effect="r.id; more = false" aria-label="ตู้ในรอบนี้" class="rounded-xl border border-slate-200">
                                <div class="space-y-2 border-b border-slate-100 px-3 py-2.5">
                                    <p class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm">
                                        <span class="font-bold text-slate-900" x-text="'ตู้ในรอบนี้ ' + cabUnits(r.cabinets) + ' ตู้'"></span>
                                        <span x-show="cabUnits(r.cabinets) !== r.cabinets.length" class="text-xs text-slate-500" x-text="'(' + r.cabinets.length + ' รายการ)'"></span>
                                        <span x-show="r.method === 'company' && asgRoom" class="ml-auto inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums"
                                              :class="asgRoom >= cabUnits(r.cabinets) ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800'"
                                              x-text="'รถที่เลือกจุ ~' + asgRoom + ' ตู้' + (asgRoom >= cabUnits(r.cabinets) ? '' : ' · ไม่พอ')"></span>
                                    </p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <template x-for="g in cabSizes(r.cabinets)" :key="g.size">
                                            <span class="inline-flex max-w-full items-baseline gap-1 rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">
                                                <span class="min-w-0" x-text="g.size" :class="g.size === 'ไม่ระบุขนาด' ? 'font-normal text-slate-500' : ''"></span><span class="shrink-0 tabular-nums text-slate-500" x-text="'× ' + g.n"></span>
                                            </span>
                                        </template>
                                    </div>
                                </div>
                                <ul class="divide-y divide-slate-100 text-sm">
                                    <li class="hidden grid-cols-[minmax(0,1fr)_minmax(0,6.5rem)_minmax(0,8rem)_2.5rem] gap-x-3 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-500 sm:grid" aria-hidden="true">
                                        <span>ชื่อตู้</span><span>ประเภท</span><span>ขนาด</span><span class="text-right">จำนวน</span>
                                    </li>
                                    <template x-for="(c, i) in r.cabinets" :key="c.id">
                                        <li x-show="more || i < 5" class="px-3 py-2 sm:grid sm:grid-cols-[minmax(0,1fr)_minmax(0,6.5rem)_minmax(0,8rem)_2.5rem] sm:items-baseline sm:gap-x-3">
                                            <span class="block min-w-0">
                                                <span class="block break-words font-semibold text-slate-900" x-text="c.model || c.name"></span>
                                                <span x-show="c.model" class="block text-xs tabular-nums text-slate-500" x-text="c.name"></span>
                                            </span>
                                            {{-- a phone: type · size · how many on one line --}}
                                            <span class="block text-xs text-slate-600 sm:hidden" x-text="[c.type, c.size, (c.qty || 1) + ' ตู้'].filter(Boolean).join(' · ')"></span>
                                            <span class="hidden break-words text-slate-700 sm:block" x-text="c.type || '-'"></span>
                                            <span class="hidden break-words tabular-nums text-slate-700 sm:block" x-text="c.size || '-'"></span>
                                            <span class="hidden text-right tabular-nums text-slate-700 sm:block" x-text="c.qty || 1"></span>
                                        </li>
                                    </template>
                                </ul>
                                <button type="button" x-show="!more && r.cabinets.length > 5" @click="more = true"
                                        class="flex h-10 w-full items-center justify-center border-t border-slate-100 text-sm font-semibold text-teal-700 hover:bg-slate-50"
                                        x-text="'ดูทั้งหมด ' + r.cabinets.length + ' รายการ'"></button>
                            </section>
                            <label x-show="r.method === 'external'" class="block text-sm font-semibold text-slate-800">บริษัทขนส่ง
                                <input type="text" name="carrier" x-model="asg.carrier" :disabled="r.method !== 'external'" maxlength="150" class="{{ $field }}">
                            </label>
                            <template x-for="(v, i) in asg.rows" :key="i">
                                <fieldset class="rounded-xl border p-3" :class="asgErr('vehicles.' + i + '.vehicle_id') || takenElsewhere(v) ? 'border-red-300' : 'border-slate-200'">
                                    <legend class="flex w-full items-center gap-2 px-1 text-sm font-semibold text-slate-800">
                                        <span x-text="'คันที่ ' + (i + 1)"></span>
                                        <span x-show="v.want" class="text-xs font-normal text-slate-500" x-text="'PM ขอ ' + v.want"></span>
                                        <button type="button" @click="asg.rows.splice(i, 1)" x-show="asg.rows.length > 1" class="ml-auto h-8 rounded-lg px-2 text-xs font-semibold text-slate-500 hover:bg-red-50 hover:text-red-700" :aria-label="'เอาคันที่ ' + (i + 1) + ' ออก'">เอาออก</button>
                                    </legend>
                                    <div class="grid gap-2 sm:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                                        <select x-show="r.method === 'company'" :name="'vehicles[' + i + '][vehicle_id]'" x-model="v.vehicle_id" @change="pickCar(v)" :disabled="r.method !== 'company'"
                                                class="block h-11 w-full rounded-lg border-slate-300 text-sm" :aria-label="'รถคันที่ ' + (i + 1)">
                                            <option value="">เลือกรถ</option>
                                            <template x-for="c in carList(v.want)" :key="c.id">
                                                <option :value="String(c.id)" :selected="String(c.id) === String(v.vehicle_id)"
                                                        x-text="c.label + (c.type && c.type !== c.name ? ' · ' + c.type : '') + (c.capacity ? ' · ~' + c.capacity + ' ตู้' : '') + (c.equipment ? ' · ' + c.equipment : '') + (r.types.length && c.type && !r.types.includes(c.type) ? ' (ไม่ได้ขอ)' : '') + (c.active ? '' : ' (เลิกใช้)')"></option>
                                            </template>
                                        </select>
                                        <input x-show="r.method === 'external'" type="text" :name="'vehicles[' + i + '][label]'" x-model="v.label" :disabled="r.method !== 'external'" maxlength="150" placeholder="รถ / ทะเบียน" class="block h-11 w-full rounded-lg border-slate-300 text-sm">
                                        <input type="text" :name="'vehicles[' + i + '][driver_name]'" x-model="v.driver_name" list="dl-people" maxlength="100" autocomplete="off"
                                               :placeholder="car(v.vehicle_id) && car(v.vehicle_id).driver ? car(v.vehicle_id).driver + ' (ประจำรถ)' : 'คนขับ'" class="block h-11 w-full rounded-lg border-slate-300 text-sm" :aria-label="'คนขับคันที่ ' + (i + 1)">
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <span class="text-xs text-slate-500">ไปด้วย</span>
                                        <template x-for="(n, j) in v.crew" :key="j">
                                            <span class="inline-flex items-center">
                                                <input type="text" :name="'vehicles[' + i + '][crew][]'" x-model="v.crew[j]" list="dl-people" maxlength="100" autocomplete="off" placeholder="ชื่อ" class="block h-10 w-32 rounded-lg border-slate-300 text-sm">
                                                <button type="button" @click="v.crew.splice(j, 1)" class="h-10 px-1.5 text-slate-400 hover:text-slate-700" aria-label="เอาคนนี้ออก"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
                                            </span>
                                        </template>
                                        <button type="button" @click="v.crew.push('')" class="h-10 text-sm font-semibold text-teal-700 hover:underline">+ เพิ่มคน</button>
                                    </div>
                                    <p x-show="asgErr('vehicles.' + i + '.vehicle_id')" class="mt-2 rounded-lg bg-red-50 px-3 py-1.5 text-sm text-red-800" x-text="asgErr('vehicles.' + i + '.vehicle_id')"></p>
                                    <p x-show="takenElsewhere(v)" class="mt-2 rounded-lg bg-red-50 px-3 py-1.5 text-sm text-red-800">รถคันนี้ใส่ซ้ำ</p>
                                    <p x-show="v.vehicle_id && busyOn(v).length" class="mt-2 rounded-lg bg-amber-50 px-3 py-1.5 text-sm text-amber-900" x-text="'ช่วงวันนั้นรถคันนี้มีงาน ' + busyOn(v).join(', ') + ' แล้ว (เตือนเท่านั้น)'"></p>
                                    <p x-show="notAsked(v)" class="mt-2 rounded-lg bg-amber-50 px-3 py-1.5 text-sm text-amber-900" x-text="'PM ไม่ได้ขอ ' + notAsked(v) + ' · บันทึกได้ PM จะได้แจ้งเตือน'"></p>
                                </fieldset>
                            </template>
                            <button type="button" @click="asg.rows.push({ vehicle_id: '', label: '', driver_name: '', crew: [], want: '' })"
                                    class="flex h-11 w-full items-center justify-center rounded-xl border border-dashed border-slate-300 text-sm font-semibold text-teal-700 hover:bg-slate-50">+ เพิ่มรถอีกคัน</button>
                            <datalist id="dl-people"><template x-for="n in people" :key="n"><option :value="n"></option></template></datalist>
                        </div>

                        {{-- short of what PM asked (allowed), a refusal, save --}}
                        <div class="space-y-2 border-t border-slate-200 p-4 sm:px-5">
                            <p x-show="r.method === 'company' && r.types.length && asgCars < r.types.length || r.crew_size && going < r.crew_size" class="text-xs text-amber-800">
                                น้อยกว่าที่ PM ขอ · บันทึกได้ PM จะได้แจ้งเตือน</p>
                            <p x-show="asg.error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" x-text="asg.error"></p>
                            <div class="flex items-center gap-2">
                                <button type="button" x-show="r.status === 'waiting'" @click="asg.open = false; openReject(r)" class="h-11 rounded-lg px-3 text-sm font-semibold text-red-700 hover:bg-red-50">ไม่รับงาน</button>
                                <button type="submit" :disabled="!canSave || asg.saving"
                                        class="ml-auto inline-flex h-11 items-center rounded-lg bg-teal-700 px-5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50"
                                        x-text="asg.saving ? 'กำลังบันทึก…' : (r.vehicles.length ? 'บันทึกรถ / คน' : (r.method === 'pickup' ? 'รับงาน' : 'บันทึกจัดรถ'))"></button>
                            </div>
                        </div>
                    </form>
                </template>
                <button type="button" x-ref="closeAsg" @click="asg.open = false" aria-label="ปิด"
                        class="absolute right-3 top-3 z-10 flex h-10 w-10 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
        </div>

        {{-- saved: a short note at the bottom --}}
        <div x-show="toast" x-cloak x-transition.opacity role="status" aria-live="polite"
             class="fixed inset-x-0 bottom-4 z-50 mx-auto w-fit max-w-[calc(100%-2rem)] rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-lg" x-text="toast"></div>

        {{-- ไม่รับงาน: a panel as จัดรถ / คน - a reason (Settings > รถขนส่ง) or "อื่น ๆ" with its words; back to PM for another
             day; saved without reloading --}}
        <div x-show="rej.open" x-cloak role="dialog" aria-modal="true" :aria-label="r ? 'ไม่รับงาน ' + r.project + ' รอบ ' + r.round : 'ไม่รับงาน'"
             x-transition:leave="transition-[visibility] duration-150"
             class="fixed inset-0 z-40 flex justify-end"
             @keydown.escape.window="if (! document.body.classList.contains('overflow-y-hidden')) rej.open = false">
            <div x-show="rej.open" @click="rej.open = false" aria-hidden="true" class="absolute inset-0 bg-slate-900/40"
                 x-transition:enter="transition-opacity duration-150 ease-out" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"></div>
            <div x-show="rej.open" x-transition:enter="transition-transform duration-200 ease-[cubic-bezier(.16,1,.3,1)]" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                 x-transition:leave="transition-transform duration-150 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
                 x-init="$watch('rej.open', (v) => v && $nextTick(() => $refs.closeRej.focus({ preventScroll: true })))"
                 class="relative flex h-full w-full max-w-xl flex-col break-words bg-white shadow-[-12px_0_32px_-12px_rgba(11,31,58,.25)] will-change-transform">
                <template x-if="r">
                    <form method="POST" :action="r.urls.reject" class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit($el, rej)">
                        @csrf
                        {{-- the round sent back --}}
                        <div class="space-y-2.5 border-b border-slate-200 p-4 pr-14 sm:p-5 sm:pr-16">
                            <div>
                                <p class="text-xs font-semibold text-red-700">ไม่รับงาน</p>
                                <p class="truncate text-base font-bold text-slate-900" :title="r.project + ' ' + r.name"><span x-text="r.project"></span> <span class="font-semibold text-slate-600" x-text="r.name"></span></p>
                                <p class="line-clamp-2 text-sm text-slate-600" :title="r.site" x-text="'รอบ ' + r.round + ' · ' + r.when + ' · ' + r.cabinets.length + ' ตู้' + (r.site ? ' · ' + r.site : '')"></p>
                                {{-- the cabinets' names, as on the card (all of them here; a long list opens in place) --}}
                                <div x-data="{ all: false, cut: false }" x-effect="r.id; all = false; $nextTick(() => cut = $refs.names.scrollHeight > $refs.names.clientHeight + 1)" class="mt-1 text-sm text-slate-800">
                                    <p x-ref="names" :class="all ? 'max-h-40 overflow-y-auto' : 'line-clamp-3'" x-text="r.cabinets.map((c) => c.model || c.name).join(', ')"></p>
                                    <button type="button" x-show="cut && !all" @click="all = true" class="text-sm font-semibold text-teal-700 hover:underline">ดูทั้งหมด</button>
                                </div>
                            </div>
                            <p x-show="r.request" class="text-sm text-slate-600">PM ขอ <span class="font-semibold text-slate-900" x-text="r.request"></span></p>
                        </div>

                        {{-- why --}}
                        <div class="min-h-0 flex-1 space-y-5 overflow-y-auto overscroll-contain p-4 sm:p-5">
                            <fieldset>
                                <legend class="text-sm font-semibold text-slate-800">เหตุผล *</legend>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <template x-for="why in [...reasons, 'อื่น ๆ']" :key="why">
                                        <label class="inline-flex h-11 cursor-pointer items-center rounded-full border px-4 text-sm font-semibold has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-red-300"
                                               :class="rej.reason === why ? 'border-red-300 bg-red-100 text-red-800' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'">
                                            <input type="radio" name="reason" :value="why" x-model="rej.reason" class="sr-only"><span x-text="why"></span></label>
                                    </template>
                                </div>
                            </fieldset>
                            <label class="block text-sm font-semibold text-slate-800">รายละเอียด <span x-show="rej.reason === 'อื่น ๆ'">*</span>
                                <span class="block text-xs font-normal text-slate-500">บอก PM ว่าวันไหนพร้อม จะได้เลือกวันใหม่ได้ทันที</span>
                                <textarea name="note" x-model="rej.note" rows="4" maxlength="500" :required="rej.reason === 'อื่น ๆ'" placeholder="เช่น 10 ล้อเข้าศูนย์ 17–18/11 ว่างอีกที 19/11" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm font-normal"></textarea>
                            </label>
                            <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">ส่งกลับให้ PM เลือกวันใหม่ · PM ได้แจ้งเตือนพร้อมเหตุผล · ไม่รับงานได้เฉพาะรอบที่ยังไม่ได้จัดรถ</p>
                        </div>

                        {{-- a refusal, send back --}}
                        <div class="space-y-2 border-t border-slate-200 p-4 sm:px-5">
                            <p x-show="rej.error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" x-text="rej.error"></p>
                            <div class="flex items-center gap-2">
                                <button type="button" x-show="r.method === 'company' || r.method === 'external'" @click="rej.open = false; openAssign(r)" class="h-11 rounded-lg px-3 text-sm font-semibold text-slate-600 hover:bg-slate-100">กลับไปจัดรถ</button>
                                <button type="submit" :disabled="!rej.reason || (rej.reason === 'อื่น ๆ' && !rej.note.trim()) || rej.saving"
                                        class="ml-auto inline-flex h-11 items-center rounded-lg bg-red-700 px-5 text-sm font-semibold text-white hover:bg-red-800 disabled:opacity-50"
                                        x-text="rej.saving ? 'กำลังส่งกลับ…' : 'ส่งกลับให้ PM'"></button>
                            </div>
                        </div>
                    </form>
                </template>
                <button type="button" x-ref="closeRej" @click="rej.open = false" aria-label="ปิด"
                        class="absolute right-3 top-3 z-10 flex h-10 w-10 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
        </div>

        {{-- ส่งถึงแล้ว: when, who received it, the delivery note, a problem, the cabinets that arrived --}}
        <x-modal panel name="dl-deliver" maxWidth="lg">
            {{-- only once ส่งถึงแล้ว is opened (openDeliver fills dlv): r alone is set by the other panels too --}}
            <template x-if="r && dlv.cabinet_ids">
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
                        {{-- the name first and its MO under it (as จัดรถ / คน); one column on a phone --}}
                        <div class="mt-1.5 grid grid-cols-1 gap-x-4 text-sm text-slate-700 sm:grid-cols-2">
                            <template x-for="c in r.cabinets" :key="c.id">
                                <label class="flex min-h-[2.75rem] items-start gap-2.5 py-1.5"><input type="checkbox" name="cabinet_ids[]" :value="c.id" x-model.number="dlv.cabinet_ids" class="mt-0.5 shrink-0 rounded">
                                    <span class="min-w-0"><span class="block font-medium text-slate-800" x-text="c.model || c.name"></span><span x-show="c.model" class="block text-xs tabular-nums text-slate-500" x-text="c.name"></span></span></label>
                            </template>
                        </div>
                    </fieldset>
                    <label class="block text-sm font-semibold text-slate-800">ปัญหาที่หน้างาน <span class="font-normal text-slate-500">(มีแล้ว PM ได้แจ้งเตือนแบบต้องทำ)</span>
                        <input type="text" name="issue" x-model="dlv.issue" maxlength="500" placeholder="เช่น ตู้มีรอยขีดข่วนด้านซ้าย · ลูกค้ารับไว้ก่อน" class="{{ $field }}">
                    </label>
                    <div class="panel-foot flex items-center gap-2">
                        <button type="button" @click="$dispatch('close-modal', 'dl-deliver')" class="ml-auto h-10 rounded-lg px-4 text-sm font-semibold text-slate-600 hover:bg-slate-100">ปิด</button>
                        <button type="submit" :disabled="!dlv.cabinet_ids.length" class="h-10 rounded-lg bg-teal-700 px-5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50">บันทึก</button>
                    </div>
                </form>
            </template>
        </x-modal>
    </div>
</x-app-layout>
