{{-- การจัดส่ง: every round of the project's delivery (newest first) with its day, its vehicles and people, where it is in
     the four steps (ห่อตู้ → แจ้งเปิดบิล → ออกจากโรงงาน → ส่งถึง) and who received it; for Admin / PM the window that keeps
     it as a draft, sends it to the delivery department, moves its day (ProjectDeliveryController). The steps after that are
     on "งานจัดส่ง" (DeliveryController). Planning shows the same rounds. --}}
@php
    $rounds = $project->deliveries()->with('vehicles.vehicle', 'rejecter', 'biller')->get();
    $roundFiles = \App\Models\ProjectDelivery::filesOf($rounds->where('status', 'delivered')->pluck('id'));   // sent with ส่งถึงแล้ว
    $canDeliver = auth()->user()->canDispatchWork() && ! $project->isLocked();
    $win = \App\Http\Controllers\ProjectDeliveryController::windowData($project);
    $cabName = collect($win['cabinets'])->pluck('name', 'id');
    $arrived = $rounds->where('status', \App\Models\ProjectDelivery::DELIVERED)->flatMap(fn ($d) => $d->cabinet_ids)->unique()->count();
    $total = count($win['cabinets']);
    $next = $rounds->where('status', '!=', \App\Models\ProjectDelivery::DELIVERED)->sortBy(fn ($d) => $d->firstDay()->format('Y-m-d').$d->delivery_time)->first();
    $form = fn (?\App\Models\ProjectDelivery $d) => \App\Http\Controllers\ProjectDeliveryController::formData($project, $d);
    $old = session()->getOldInput();
    $errorKeys = ['delivery_date', 'delivery_end_date', 'delivery_time', 'cabinet_ids', 'method', 'vehicle_types', 'vehicle_types.0', 'crew_size', 'delivery'];
    $deliveryPage = auth()->user()->allows('edit.delivery');
    $dlMoves = \App\Services\EventDates::history($project, \App\Services\EventDates::DELIVERY);   // ประวัติการเลื่อน, by round
@endphp
{{-- A panel over the page when asked (หัวโครงการ › "ดูทุกรอบ", สถานะโครงการ › ส่งมอบ, a link to #delivery: the pmo-delivery event, 'show');
     'new' opens the window of a new round. The window stays in the page. --}}
<section id="delivery" class="scroll-mt-20" aria-label="การจัดส่ง"
         x-data="{ f: null, cabs: @js($win['cabinets']), vehicles: @js($win['vehicles']), shown: location.hash === '#delivery',
                   open(v) { this.f = JSON.parse(JSON.stringify(v)); this.$dispatch('open-modal', 'project-delivery'); } }"
         @pmo-delivery.window="if ($event.detail === 'new') { @if ($canDeliver) open(@js($form(null))) @endif } else { shown = true }"
         @if ($errors->hasAny($errorKeys) && $old)
             x-init="f = { ...@js($form($rounds->firstWhere('id', (int) ($old['_delivery'] ?? 0)))), ...@js(\Illuminate\Support\Arr::except($old, ['_token', '_method', '_delivery', 'cabinet_ids'])), cabinet_ids: (@js($old['cabinet_ids'] ?? [])).map(Number) }; $nextTick(() => $dispatch('open-modal', 'project-delivery'))"
         @endif>
  {{-- a panel from the right, over the page (its forms' windows open above it) --}}
  {{-- Snappy: the backdrop fades and the panel slides (transform only); focus moves without scrolling the page; Esc closes a
       form open above it first, not both --}}
  <div x-show="shown" x-cloak role="dialog" aria-modal="true" aria-label="การจัดส่ง"
       x-transition:leave="transition-[visibility] duration-150"
       class="fixed inset-0 z-40 flex justify-end"
       x-effect="document.body.classList.toggle('overflow-hidden', shown)"
       @keydown.escape.window="if (! document.body.classList.contains('overflow-y-hidden')) shown = false">
    <div x-show="shown" @click="shown = false" aria-hidden="true" class="absolute inset-0 bg-slate-900/40"
         x-transition:enter="transition-opacity duration-150 ease-out" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"></div>
    <div x-show="shown" x-transition:enter="transition-transform duration-200 ease-[cubic-bezier(.16,1,.3,1)]" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
         x-transition:leave="transition-transform duration-150 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
         x-init="$watch('shown', (v) => v && $nextTick(() => $refs.closeDelivery.focus({ preventScroll: true })))"
         class="relative flex h-full w-full max-w-2xl flex-col bg-white shadow-[-12px_0_32px_-12px_rgba(11,31,58,.25)] will-change-transform">
      <button type="button" x-ref="closeDelivery" @click="shown = false" aria-label="ปิด"
              class="absolute right-3 top-3 z-10 flex h-10 w-10 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
      </button>
      <div class="flex-1 overflow-y-auto p-5 sm:p-6">
    {{-- the head: what this is, how much has arrived, the next round; the one way to add a round --}}
    <div class="space-y-2.5 pr-12">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <span class="inline-flex h-6 items-center rounded bg-teal-700 px-2 text-xs font-extrabold tracking-wide text-white">ส่ง</span>
            <h3 class="text-lg font-bold text-ae-navy">การจัดส่ง</h3>
            @if ($canDeliver)
                <button type="button" @click="open(@js($form(null)))" class="ml-auto inline-flex h-11 items-center rounded-lg {{ $rounds->isEmpty() ? 'border border-dashed border-slate-400 bg-white text-slate-700 hover:bg-slate-50' : 'bg-slate-800 text-white hover:bg-slate-700' }} px-3.5 text-sm font-semibold sm:h-9">+ นัดส่ง{{ $rounds->isEmpty() ? '' : 'รอบใหม่' }}</button>
            @endif
        </div>
        @if ($rounds->isNotEmpty())
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <span class="text-base font-bold tabular-nums text-slate-900">ส่งแล้ว {{ $arrived }} / {{ $total }} ตู้</span>
                @if ($total)
                    <span class="block h-2 w-40 overflow-hidden rounded-full bg-slate-200" aria-hidden="true"><span class="block h-full rounded-full bg-teal-600" style="width: {{ (int) round($arrived / $total * 100) }}%"></span></span>
                @endif
            </div>
            @if ($next && $next->isOpen())
                <p class="text-sm {{ $next->status === 'rejected' ? 'font-semibold text-red-700' : ($next->date_pending_at ? 'text-indigo-800' : 'text-slate-600') }}">รอบถัดไป {{ $next->date_pending_at ? \App\Models\Project::PENDING_LABEL : $next->when().' · '.$next->stateLabel() }}</p>
            @elseif ($next)
                @php $in = (int) today()->diffInDays($next->delivery_date, false); @endphp
                <p class="text-sm {{ $in < 0 ? 'font-semibold text-orange-700' : 'text-slate-600' }}">รอบถัดไป {{ $next->when() }} · {{ $in > 0 ? 'อีก '.$in.' วัน' : ($in === 0 ? 'วันนี้' : 'เลยวันนัด '.abs($in).' วัน · ยังไม่ได้บันทึกว่าส่งถึง') }}</p>
            @endif
        @else
            <p class="text-sm text-slate-600">ยังไม่ได้นัดส่ง{{ $canDeliver ? ' · กด "+ นัดส่ง" เพื่อลงวันส่งรอบแรก' : '' }}</p>
        @endif
    </div>

    @if ($rounds->isNotEmpty())
        <div class="mt-4 divide-y divide-slate-100 border-t border-slate-200">
            @foreach ($rounds->sortByDesc('round') as $d)
                @php
                    $moves = $dlMoves->get($d->id, collect());
                    $count = $moves->whereNotNull('n')->count();
                    $cabIds = $d->cabinet_ids ?? [];
                @endphp
                <article class="grid grid-cols-1 gap-x-4 gap-y-2.5 py-4 sm:grid-cols-[minmax(0,1fr)_auto]" aria-label="รอบที่ {{ $d->round }}">
                    <div class="min-w-0 space-y-2 text-sm text-slate-700">
                        {{-- the round, its day and where it is --}}
                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="text-sm font-bold text-teal-700">รอบที่ {{ $d->round }}</span>
                            <span class="font-bold tabular-nums {{ $d->date_pending_at ? 'text-indigo-700' : 'text-slate-900' }}">{{ $d->date_pending_at ? 'รอกำหนดส่ง' : ($d->status === 'delivered' && $d->delivered_at ? $d->delivered_at->thaiDate().' · ถึง '.$d->delivered_at->format('H:i').' น.' : $d->when()) }}</span>
                            <span class="st-pill rounded-full px-2.5 py-0.5 text-xs font-bold" style="--c: {{ $d->stateColor() }}">{{ $d->stateLabel() }}{{ $d->status === 'departed' && $d->departed_at ? ' '.$d->departed_at->format('H:i') : '' }}</span>
                            @if ($d->date_pending_at)
                                {{-- รอฝ่ายขายแจ้งกำหนดส่ง (ProjectDates): no day yet; the new one comes with the project's --}}
                                <span class="rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-bold text-indigo-800">{{ \App\Models\Project::PENDING_LABEL }}</span>
                            @elseif ($d->isOverdue())
                                <span class="text-xs font-semibold text-orange-700">{{ $d->isOpen() ? 'เลยวันส่งแล้ว · ยังไม่ได้จัดรถ' : 'เลยวันนัดแล้ว' }}</span>
                            @endif
                            @if ($count)<span class="rounded-full bg-orange-100 px-2.5 py-0.5 text-xs font-bold text-orange-800">เลื่อน {{ $count }} ครั้ง</span>@endif
                        </p>
                        @if ($d->status === 'delivered')<p class="text-xs text-slate-500">นัด {{ $d->when() }}</p>@endif
                        {{-- what the pill does not say: why it came back, or that it is not sent yet --}}
                        @if ($d->status === 'rejected')
                            <p class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[13px] text-red-800">แผนกจัดส่งไม่รับงาน · {{ $d->rejected_reason }}{{ $d->rejected_note ? ' · '.$d->rejected_note : '' }} <span class="text-red-700">· โดย {{ $d->rejecter?->name }} {{ $d->rejected_at?->format('d/m H:i') }}</span></p>
                        @elseif ($d->status === 'draft')
                            <p class="text-[13px] text-slate-600">ยังไม่ได้ส่งให้แผนกจัดส่ง</p>
                        @endif

                        {{-- the cabinets: how many, their numbers on demand (the packing shows on the steps below) --}}
                        @if (! $cabIds)
                            <p class="text-[13px] font-semibold text-slate-700">ทุกตู้</p>
                        @else
                            <details class="group">
                                <summary class="inline-flex min-h-[2.25rem] cursor-pointer list-none items-center gap-1.5 rounded-lg text-[13px] [&::-webkit-details-marker]:hidden">
                                    <span class="font-semibold text-slate-800">{{ count($cabIds) }} ตู้</span>
                                    <span class="font-semibold text-blue-700 group-open:hidden">ดูรายชื่อ</span><span class="hidden font-semibold text-blue-700 group-open:inline">ซ่อนรายชื่อ</span>
                                    <svg class="h-3.5 w-3.5 text-blue-700 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"/></svg>
                                </summary>
                                <p class="mt-1.5 flex flex-wrap gap-1">
                                    @foreach ($cabIds as $cid)
                                        @php $isPacked = $d->status !== 'delivered' && $d->isPacked((int) $cid); @endphp
                                        <span class="rounded-md border px-1.5 text-xs font-semibold tabular-nums {{ $d->status === 'delivered' ? 'border-teal-200 bg-teal-50 text-teal-800' : ($isPacked ? 'border-emerald-500 text-emerald-700' : 'border-slate-300 text-slate-700') }}"
                                              @if ($isPacked) title="ห่อแล้ว" @endif>{{ $cabName[$cid] ?? 'ตู้ที่ลบแล้ว' }}</span>
                                    @endforeach
                                </p>
                            </details>
                        @endif

                        @if ($d->status !== 'draft' && $d->status !== 'rejected' && $cabIds)
                            @include('projects.partials._delivery-steps', ['d' => $d])
                        @endif

                        <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-0.5 text-[13px]">
                            <dt class="text-slate-500">รถ</dt>
                            <dd>
                                @if ($d->method === 'company' && $d->vehicles->isEmpty())
                                    {{ $d->request() ? 'ขอ '.$d->request() : '-' }}
                                @else
                                    {{ $d->transport() ?? '-' }}
                                    @if ($d->request())<span class="text-slate-500">· PM ขอ {{ $d->request() }}</span>@endif
                                    @if ($d->shortOfVehicles())<span class="rounded bg-amber-100 px-1 text-xs font-bold text-amber-800">{{ $d->vehiclesShortText() }}</span>@endif
                                    @if ($d->otherType())<span class="rounded bg-amber-100 px-1 text-xs font-bold text-amber-800">มี {{ implode(', ', $d->otherTypes()) }} ที่ไม่ได้ขอ</span>@endif
                                @endif
                            </dd>
                            @if ($d->people())
                                <dt class="text-slate-500">คน</dt>
                                <dd class="{{ $d->shortOfPeople() ? 'text-amber-800' : '' }}">{{ $d->peopleText() }}</dd>
                            @endif
                            @if ($d->site || $d->contact_name || $d->contact_phone)
                                <dt class="text-slate-500">ส่งที่</dt>
                                <dd class="break-words">{{ collect([$d->site, $d->contact_name])->filter()->implode(' · ') }}@if ($d->contact_phone){{ $d->site || $d->contact_name ? ' · ' : '' }}<span class="whitespace-nowrap tabular-nums">{{ $d->contact_phone }}</span>@endif</dd>
                            @endif
                            @if ($d->status === 'delivered')
                                <dt class="text-slate-500">ผู้รับ</dt>
                                <dd>{{ collect([$d->receiver, $d->doc_no ? 'ใบส่งของ '.$d->doc_no : null])->filter()->implode(' · ') }}</dd>
                                @if ($roundFiles->has($d->id))
                                    <dt class="text-slate-500">ไฟล์</dt>
                                    {{-- as งานจัดส่ง shows them: square tiles, a photo itself and a document its kind; a photo / PDF opens over
                                         the page (<x-file-viewer> below). Past eight, seven and +n, which shows the rest here --}}
                                    @php $fs = $roundFiles[$d->id]->map(fn ($f) => \App\Models\ProjectDelivery::fileView($f))->values(); @endphp
                                    <dd x-data="{ all: false }"><ul class="flex flex-wrap gap-1.5 pb-1 pt-0.5" aria-label="ไฟล์ตอนส่งถึง {{ $fs->count() }} ไฟล์">
                                        @foreach ($fs as $i => $f)
                                            <li @if ($fs->count() > 8 && $i >= 7) x-show="all" x-cloak @endif><x-file-tile :file="$f" class="h-14 w-14" /></li>
                                        @endforeach
                                        @if ($fs->count() > 8)
                                            <li x-show="! all"><button type="button" @click="all = true" aria-label="ดูไฟล์ทั้งหมด {{ $fs->count() }} ไฟล์"
                                                        class="flex h-14 w-14 items-center justify-center rounded-lg bg-slate-100 text-sm font-bold tabular-nums text-slate-700 ring-1 ring-slate-900/10 hover:ring-2 hover:ring-teal-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2">+{{ $fs->count() - 7 }}</button></li>
                                        @endif
                                    </ul></dd>
                                @endif
                            @endif
                            @if ($d->issue)
                                <dt class="text-orange-700">ปัญหา</dt><dd class="text-orange-800">{{ $d->issue }}</dd>
                            @endif
                            @if ($d->note)
                                <dt class="text-slate-500">หมายเหตุ</dt><dd class="break-words">{{ $d->note }}</dd>
                            @endif
                        </dl>
                        @if ($moves->isNotEmpty())
                            <details class="group rounded-lg border border-slate-200">
                                <summary class="flex min-h-[2.75rem] cursor-pointer list-none items-center gap-2 px-3 text-[13px] font-semibold text-slate-700 hover:bg-slate-50 [&::-webkit-details-marker]:hidden">
                                    <svg class="h-4 w-4 shrink-0 text-slate-500 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"/></svg>
                                    ประวัติการเลื่อนวัน ({{ $moves->count() }})
                                </summary>
                                <div class="border-t border-slate-100 px-3">@include('projects.partials._date-moves', ['moves' => $moves])</div>
                            </details>
                        @endif
                    </div>
                    @if ($canDeliver || $deliveryPage)
                        <div class="flex flex-wrap gap-2 sm:flex-col sm:items-end">
                            @if ($canDeliver && $d->status === 'draft')
                                <button type="button" @click="open(@js($form($d)))" class="h-11 rounded-lg bg-slate-800 px-3.5 text-sm font-semibold text-white hover:bg-slate-700 sm:h-9">ตรวจแล้วส่งให้แผนกจัดส่ง</button>
                            @elseif ($canDeliver && $d->status === 'rejected')
                                <button type="button" @click="open(@js($form($d)))" class="h-11 rounded-lg bg-slate-800 px-3.5 text-sm font-semibold text-white hover:bg-slate-700 sm:h-9">เลือกวันใหม่แล้วส่ง</button>
                            @elseif ($canDeliver && ! in_array($d->status, ['departed', 'delivered'], true))
                                <button type="button" @click="open(@js($form($d)))" class="h-11 rounded-lg border border-slate-300 bg-white px-3.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:h-9">เลื่อนวัน / แก้</button>
                            @endif
                            @if ($deliveryPage && ! in_array($d->status, ['rejected', 'draft'], true))
                                <a href="{{ route('deliveries.index', ['round' => $d->id]) }}" class="inline-flex h-11 items-center rounded-lg px-3 text-sm font-semibold text-teal-700 hover:bg-teal-50 sm:h-9">เปิดในงานจัดส่ง</a>
                            @endif
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

      </div>
    </div>
        </div>
      </div>
    </template>
  </div>

    @if ($canDeliver)
        @include('projects.partials._delivery-form')
    @endif
    <x-file-viewer />
</section>
