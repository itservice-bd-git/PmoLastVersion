{{-- การจัดส่ง: every round of the project's delivery (newest first) with what went, on what, and who received it; for
     Admin / PM the window that keeps it as a draft, sends it to the delivery department, fixes it (ProjectDeliveryController). The steps after
     that are on "งานจัดส่ง" (DeliveryController). Planning shows the same rounds. --}}
@php
    $rounds = $project->deliveries()->with('vehicle', 'rejecter')->get();
    $canDeliver = auth()->user()->canDispatchWork() && ! $project->isLocked();
    $win = \App\Http\Controllers\ProjectDeliveryController::windowData($project);
    $cabName = collect($win['cabinets'])->pluck('name', 'id');
    $arrived = $rounds->where('status', \App\Models\ProjectDelivery::DELIVERED)->flatMap(fn ($d) => $d->cabinet_ids)->unique()->count();
    $total = count($win['cabinets']);
    $next = $rounds->where('status', '!=', \App\Models\ProjectDelivery::DELIVERED)->sortBy(fn ($d) => $d->firstDay()->format('Y-m-d').$d->delivery_time)->first();
    $form = fn (?\App\Models\ProjectDelivery $d) => \App\Http\Controllers\ProjectDeliveryController::formData($project, $d);
    $old = session()->getOldInput();
    $errorKeys = ['window_from', 'window_to', 'cabinet_ids', 'method', 'vehicle_type', 'crew_size', 'delivery'];
    $deliveryPage = auth()->user()->allows('edit.delivery');
@endphp
<section id="delivery" class="scroll-mt-20 rounded-xl border border-slate-200 bg-white p-5" aria-label="การจัดส่ง"
         x-data="{ f: null, cabs: @js($win['cabinets']), vehicles: @js($win['vehicles']),
                   open(v) { this.f = JSON.parse(JSON.stringify(v)); this.$dispatch('open-modal', 'project-delivery'); } }"
         @if ($errors->hasAny($errorKeys) && $old)
             x-init="f = { ...@js($form($rounds->firstWhere('id', (int) ($old['_delivery'] ?? 0)))), ...@js(\Illuminate\Support\Arr::except($old, ['_token', '_method', '_delivery', 'cabinet_ids'])), cabinet_ids: (@js($old['cabinet_ids'] ?? [])).map(Number) }; $nextTick(() => $dispatch('open-modal', 'project-delivery'))"
         @endif>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <span class="inline-flex h-6 items-center rounded bg-teal-700 px-2 text-xs font-extrabold tracking-wide text-white">ส่ง</span>
        <h3 class="text-base font-bold text-ae-navy">การจัดส่ง</h3>
        @if ($rounds->isNotEmpty())
            <span class="text-base font-bold tabular-nums text-slate-900">ส่งแล้ว {{ $arrived }} / {{ $total }} ตู้</span>
            @if ($total && $total <= 12)
                <span class="flex gap-0.5" aria-hidden="true">@for ($i = 0; $i < $total; $i++)<span class="h-2 w-5 rounded-sm {{ $i < $arrived ? 'bg-teal-600' : 'bg-slate-200' }}"></span>@endfor</span>
            @endif
            @if ($next && $next->isOpen())
                <span class="text-sm {{ $next->status === 'rejected' ? 'font-semibold text-red-700' : ($next->status === 'draft' ? 'text-slate-500' : 'text-slate-600') }}">รอบถัดไป {{ $next->windowText() }} · {{ $next->stateLabel() }}</span>
            @elseif ($next)
                @php $in = (int) today()->diffInDays($next->delivery_date, false); @endphp
                <span class="text-sm {{ $in < 0 ? 'font-semibold text-orange-700' : 'text-slate-600' }}">รอบถัดไป {{ $next->when() }} · {{ $in > 0 ? 'อีก '.$in.' วัน' : ($in === 0 ? 'วันนี้' : 'เลยวันนัด '.abs($in).' วัน · ยังไม่ได้บันทึกว่าส่งถึง') }}</span>
            @endif
        @else
            <span class="text-sm text-slate-500">ยังไม่ได้นัดส่ง</span>
        @endif
        @if ($canDeliver)
            <button type="button" @click="open(@js($form(null)))" class="ml-auto inline-flex h-9 items-center rounded-lg {{ $rounds->isEmpty() ? 'border border-dashed border-slate-400 bg-white text-slate-700 hover:bg-slate-50' : 'bg-slate-800 text-white hover:bg-slate-700' }} px-3 text-sm font-semibold">+ นัดส่ง{{ $rounds->isEmpty() ? '' : 'รอบใหม่' }}</button>
        @endif
    </div>

    @if ($rounds->isNotEmpty())
        <div class="mt-3 divide-y divide-slate-100 border-t border-slate-100">
            @foreach ($rounds->sortByDesc('round') as $d)
                <div class="grid grid-cols-[4.5rem_minmax(0,1fr)] gap-x-3 gap-y-2 py-3 sm:grid-cols-[4.5rem_minmax(0,1fr)_auto]">
                    <span class="text-sm font-bold text-teal-700">รอบที่ {{ $d->round }}</span>
                    <div class="min-w-0 space-y-1.5 text-sm text-slate-700">
                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="font-bold tabular-nums text-slate-900">{{ $d->status === 'delivered' && $d->delivered_at ? $d->delivered_at->thaiDate().' · ถึง '.$d->delivered_at->format('H:i').' น.' : $d->when() }}</span>
                            <span class="st-pill rounded-full px-2.5 py-0.5 text-xs font-bold" style="--c: {{ $d->stateColor() }}">{{ $d->stateLabel() }}{{ $d->status === 'departed' && $d->departed_at ? ' '.$d->departed_at->format('H:i') : '' }}</span>
                            @if ($d->status === 'delivered')<span class="text-xs text-slate-500">นัด {{ $d->when() }}</span>@endif
                            @if ($d->isOverdue())<span class="text-xs font-semibold text-orange-700">{{ $d->isOpen() ? 'เลยช่วงวันแล้ว · ยังไม่ได้เลือกวัน' : 'เลยวันนัดแล้ว' }}</span>@endif
                            @if ($d->delivery_date && $d->status !== 'delivered')<span class="text-xs text-slate-500">ช่วงที่ให้ {{ \App\Models\ProjectDelivery::range($d->window_from, $d->window_to) }}</span>@endif
                        </p>
                        @if ($d->status === 'rejected')
                            <p class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[13px] text-red-800">แผนกจัดส่งไม่รับงาน · {{ $d->rejected_reason }}{{ $d->rejected_note ? ' · '.$d->rejected_note : '' }} <span class="text-red-600">· โดย {{ $d->rejecter?->name }} {{ $d->rejected_at?->format('d/m H:i') }}</span></p>
                        @elseif ($d->status === 'waiting')
                            <p class="text-[13px] text-amber-800">รอแผนกจัดส่งเลือกวันส่งในช่วงนี้</p>
                        @elseif ($d->status === 'draft')
                            <p class="text-[13px] text-slate-500">ร่าง · ยังไม่ได้ส่งให้แผนกจัดส่ง</p>
                        @endif
                        <p class="flex flex-wrap gap-1">
                            @if (! $d->cabinet_ids)<span class="rounded-md border border-dashed border-slate-300 px-1.5 text-xs font-semibold text-slate-600">ทุกตู้</span>@endif
                            @foreach ($d->cabinet_ids ?? [] as $cid)
                                <span class="rounded-md border px-1.5 text-xs font-semibold tabular-nums {{ $d->status === 'delivered' ? 'border-teal-200 bg-teal-50 text-teal-800' : 'border-slate-300 text-slate-700' }}">{{ $cabName[$cid] ?? 'ตู้ที่ลบแล้ว' }}{{ $d->status === 'delivered' ? ' ✓' : '' }}</span>
                            @endforeach
                        </p>
                        <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 text-[13px]">
                            <dt class="text-slate-500">รถ</dt>
                            <dd>
                                @if ($d->method === 'company' && ! $d->vehicle_id)
                                    {{ $d->request() ?: '-' }}
                                @else
                                    {{ $d->transport() ?? '-' }}
                                    @if ($d->request())<span class="text-slate-500">· PM ขอ {{ $d->request() }}</span>@endif
                                    @if ($d->otherType())<span class="rounded bg-amber-100 px-1 text-xs font-bold text-amber-800">คนละประเภท</span>@endif
                                @endif
                            </dd>
                            @if ($d->people())
                                <dt class="text-slate-500">คน</dt>
                                <dd class="{{ $d->crew_size && count($d->people()) < $d->crew_size ? 'text-amber-800' : '' }}">{{ $d->peopleText() }}</dd>
                            @endif
                            @if ($d->site || $d->contact_name)
                                <dt class="text-slate-500">ส่งที่</dt>
                                <dd>{{ collect([$d->site, $d->contact_name, $d->contact_phone])->filter()->implode(' · ') }}</dd>
                            @endif
                            @if ($d->status === 'delivered')
                                <dt class="text-slate-500">ผู้รับ</dt>
                                <dd>{{ collect([$d->receiver, $d->doc_no ? 'ใบส่งของ '.$d->doc_no : null])->filter()->implode(' · ') }}</dd>
                            @endif
                            @if ($d->issue)
                                <dt class="text-orange-700">ปัญหา</dt><dd class="text-orange-800">{{ $d->issue }}</dd>
                            @endif
                            @if ($d->note)
                                <dt class="text-slate-500">หมายเหตุ</dt><dd>{{ $d->note }}</dd>
                            @endif
                        </dl>
                    </div>
                    @if ($canDeliver || $deliveryPage)
                        <div class="col-span-2 flex flex-wrap gap-2 sm:col-span-1 sm:flex-col sm:items-end">
                            @if ($canDeliver && $d->status === 'draft')
                                <button type="button" @click="open(@js($form($d)))" class="h-9 rounded-lg bg-slate-800 px-3 text-sm font-semibold text-white hover:bg-slate-700">ตรวจแล้วส่งให้แผนกจัดส่ง</button>
                            @elseif ($canDeliver && $d->status === 'rejected')
                                <button type="button" @click="open(@js($form($d)))" class="h-9 rounded-lg bg-slate-800 px-3 text-sm font-semibold text-white hover:bg-slate-700">แก้แล้วส่งอีกครั้ง</button>
                            @elseif ($canDeliver && ! in_array($d->status, ['departed', 'delivered'], true))
                                <button type="button" @click="open(@js($form($d)))" class="h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">แก้</button>
                            @endif
                            @if ($deliveryPage && ! in_array($d->status, ['rejected', 'draft'], true))
                                <a href="{{ route('deliveries.index', ['round' => $d->id]) }}" class="inline-flex h-9 items-center rounded-lg px-3 text-sm font-semibold text-teal-700 hover:bg-teal-50">เปิดในงานจัดส่ง</a>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($canDeliver)
        @include('projects.partials._delivery-form')
    @endif
</section>
