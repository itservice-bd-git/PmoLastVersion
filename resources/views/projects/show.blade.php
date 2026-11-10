<x-app-layout>
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-2">
            <x-back-link :href="route('projects.index')" label="รายการโครงการ" />
            <h2 class="truncate whitespace-nowrap text-lg font-semibold text-slate-900">{{ $project->project_no }}</h2>
            {{-- the card below shows it too: on a phone the bar keeps its room for the project number --}}
            <span class="hidden sm:inline-flex"><x-project-status :status="$project->status" /></span>
        </div>
    </x-slot>

    @php
        $tabs = array_merge(['info'], auth()->user()->allows('data.comments') ? ['comment', 'activity'] : []);
        // "ข้อมูลโครงการ" holds what the older "โปรเจ็ค", "ใบสั่งผลิต/MO" and "ภาพรวม" tabs showed; links that still name them land there
        $asked = in_array(request('tab'), ['project', 'cabinets', 'overview'], true) ? 'info' : request('tab');
        $initialTab = in_array($asked, $tabs, true) ? $asked : 'info';
    @endphp
    <div class="pmo-project max-w-screen-2xl mx-auto space-y-4" x-data="{ tab: '{{ $initialTab }}' }" x-init="$watch('tab', (t) => keepTabInUrl(t, 'info'))">

        <x-closed-project-banner :project="$project" />

        {{-- หัวโครงการ: the project on the left (name, SO, customer, status / risk / job type, follow), its FAT and next
             delivery on the right with the buttons that open their windows (_fat / _delivery, through the pmo-fat /
             pmo-delivery events), the rest of the actions in the ⋯ menu. --}}
        @php
            $hdrFat = $project->fats()->get()->last();
            $hdrRounds = $project->deliveries()->with('vehicles.vehicle')
                ->when(! \App\Services\DeliveryFlow::canBook(auth()->user()), fn ($q) => $q->where('status', '!=', \App\Models\ProjectDelivery::DRAFT))->get();
            $hdrNext = $hdrRounds->where('status', '!=', \App\Models\ProjectDelivery::DELIVERED)->sortBy(fn ($d) => $d->firstDay()->format('Ymd').$d->delivery_time)->first();
            $hdrCanAppt = auth()->user()->canDispatchWork() && ! $project->isLocked();
            $hdrRisk = ['on_track' => ['ปกติ', 'bg-emerald-50 text-emerald-800', 'bg-emerald-500'], 'watch' => ['เฝ้าระวัง', 'bg-orange-50 text-orange-800', 'bg-orange-500'],
                'behind' => ['สูง', 'bg-red-50 text-red-800', 'bg-red-500']][$overview['summary']['pace'] ?? ''] ?? null;
            $today = today();
            // FAT and the next delivery round: the date large; under it how many days (or what happened) and the time
            $range = fn ($a, $b) => \App\Models\ProjectDelivery::range($a, $b && $b->gt($a) ? $b : $a);
            $fatDays = $hdrFat ? (int) $today->diffInDays($hdrFat->fat_date, false) : null;
            $fatEnd = $hdrFat ? (int) $today->diffInDays($hdrFat->lastDay(), false) : null;
            $fatTime = $hdrFat?->fat_time ? $hdrFat->fat_time.' น.' : null;
            // [the date (null = none), the line under it, its colour, the button]
            $fatTile = match (true) {
                ! $hdrFat => [null, $project->due_date ? 'ครบกำหนดโครงการ '.$project->due_date->thaiDate() : '', 'text-slate-600', '+ ลงวัน FAT'],
                $hdrFat->status === 'passed' => [$range($hdrFat->fat_date, $hdrFat->fat_end_date), 'ผ่านแล้ว', 'text-emerald-700', 'แก้วัน FAT'],
                $hdrFat->status === 'failed' => [$range($hdrFat->fat_date, $hdrFat->fat_end_date), 'ไม่ผ่าน · นัดรอบใหม่', 'text-red-700', 'นัด FAT รอบใหม่'],
                $fatEnd < 0 => [$range($hdrFat->fat_date, $hdrFat->fat_end_date), 'เลย '.abs($fatEnd).' วัน · ยังไม่บันทึกผล', 'text-red-700', 'บันทึกผล FAT'],
                $fatDays <= 0 => [$range($hdrFat->fat_date, $hdrFat->fat_end_date), 'วันนี้'.($fatTime ? ' · '.$fatTime : ''), 'text-blue-700', 'แก้วัน FAT'],
                default => [$range($hdrFat->fat_date, $hdrFat->fat_end_date), 'อีก '.$fatDays.' วัน'.($fatTime ? ' · '.$fatTime : ''), 'text-slate-700', 'แก้วัน FAT'],
            };
            $dlDays = $hdrNext ? (int) $today->diffInDays($hdrNext->firstDay(), false) : null;
            $dlEnd = $hdrNext ? (int) $today->diffInDays($hdrNext->lastDay(), false) : null;
            $dlTime = $hdrNext?->delivery_time ? $hdrNext->delivery_time.' น.' : null;
            $dlTile = match (true) {
                ! $hdrRounds->count() => [null, '', 'text-slate-600'],
                ! $hdrNext => [$range($hdrRounds->max(fn ($d) => $d->lastDay()), null), 'ส่งครบแล้ว · '.$hdrRounds->count().' รอบ', 'text-emerald-700'],
                $dlEnd < 0 => [$range($hdrNext->firstDay(), $hdrNext->lastDay()), 'เลย '.abs($dlEnd).' วัน · ยังไม่บันทึกว่าส่งถึง', 'text-red-700'],
                $dlDays <= 0 => [$range($hdrNext->firstDay(), $hdrNext->lastDay()), 'วันนี้'.($dlTime ? ' · '.$dlTime : ''), 'text-blue-700'],
                default => [$range($hdrNext->firstDay(), $hdrNext->lastDay()), 'อีก '.$dlDays.' วัน'.($dlTime ? ' · '.$dlTime : ''), 'text-slate-700'],
            };
            $followers = $project->followers()->orderBy('name')->get(['users.id', 'name']);
            $following = $followers->contains('id', auth()->id());
        @endphp
        <section aria-label="หัวโครงการ" class="relative rounded-2xl border border-slate-200 bg-white p-4 shadow-[0_1px_2px_rgba(11,31,58,.05),0_6px_18px_-12px_rgba(11,31,58,.18)] sm:p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-stretch lg:gap-7">
                <div class="flex min-w-0 flex-1 flex-col justify-center gap-2.5 pr-12 lg:pr-0">
                    <div>
                        <h1 class="text-xl font-bold leading-snug text-ae-navy sm:text-[22px]">{{ $project->project_name }}</h1>
                        <p class="mt-0.5 flex flex-wrap gap-x-3.5 text-sm text-slate-600">
                            <span class="font-bold tabular-nums text-slate-900">{{ $project->project_no }}</span>
                            @if ($project->customer_name)<span>{{ $project->customer_name }}</span>@endif
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <x-project-status :status="$project->status" />
                        <x-priority-flag :priority="$project->priority" />
                        @if ($hdrRisk)
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold {{ $hdrRisk[1] }}" title="ผลิตแล้ว {{ $overview['summary']['progress'] }}% · เวลาผ่านไป {{ $overview['summary']['timeElapsed'] }}%">
                                <span class="h-1.5 w-1.5 rounded-full {{ $hdrRisk[2] }}"></span>ความเสี่ยง : {{ $hdrRisk[0] }}</span>
                        @endif
                        <span class="rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $project->jobType ? 'border-slate-200 text-slate-700' : 'border-dashed border-slate-300 text-slate-500' }}">ประเภทงาน : {{ $project->jobType?->name ?? 'ยังไม่ระบุ' }}</span>
                        {{-- "ติดตามโครงการ": anyone who sees the project (ProjectFollowers); its พนักงานขาย follow it already --}}
                        <form method="POST" action="{{ route('projects.follow', $project) }}" class="ml-1">
                            @csrf
                            <button class="inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $following ? 'border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' }}"
                                    title="{{ $following ? 'กดเพื่อเลิกติดตาม' : 'รับแจ้งเตือนความเคลื่อนไหวของโครงการนี้' }}" aria-pressed="{{ $following ? 'true' : 'false' }}">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                                {{ $following ? 'ติดตามอยู่' : 'ติดตามโครงการ' }}
                            </button>
                        </form>
                        @if ($followers->isNotEmpty())
                            <span class="text-xs text-slate-500" title="ผู้ติดตาม">ผู้ติดตาม: {{ $followers->pluck('name')->implode(', ') }}</span>
                        @endif
                    </div>
                </div>

                {{-- FAT and the next delivery round: how many days, the date, the button --}}
                <div class="grid grid-cols-2 gap-3 lg:mr-12 lg:flex lg:gap-0">
                    <div class="flex min-w-0 flex-col gap-0.5 rounded-xl border border-slate-200 p-3 lg:min-w-[11rem] lg:rounded-none lg:border-0 lg:border-l lg:px-5 lg:py-0.5">
                        <span class="text-[13px] font-semibold text-slate-600">วันที่ FAT @if ($hdrFat && $hdrFat->round > 1)<span class="font-normal">รอบที่ {{ $hdrFat->round }}</span>@endif</span>
                        <span class="text-xl font-bold leading-tight tabular-nums sm:text-2xl {{ $fatTile[0] ? 'text-ae-navy' : 'text-slate-500' }}">{{ $fatTile[0] ?? 'ยังไม่ได้นัด' }}</span>
                        <span class="flex flex-wrap items-center gap-1 text-[13px] font-semibold tabular-nums {{ $fatTile[2] }}">
                            @if ($hdrFat?->status === 'passed')<svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg>@endif
                            {{ $fatTile[1] ?: ' ' }}</span>
                        <span class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                            @if ($hdrCanAppt)
                                <button type="button" @click="tab = 'info'; $nextTick(() => $dispatch('pmo-fat', 'edit'))"
                                        class="inline-flex h-10 items-center rounded-lg px-3 text-[13px] font-semibold sm:h-8 {{ $fatTile[2] === 'text-red-700' && $hdrFat?->status !== 'failed' ? 'bg-red-700 text-white hover:bg-red-800' : ($hdrFat ? 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50' : 'border border-dashed border-slate-400 bg-white text-slate-800 hover:bg-slate-50') }}">{{ $fatTile[3] }}</button>
                            @endif
                            @if ($hdrFat)
                                <button type="button" @click="tab = 'info'; $nextTick(() => $dispatch('pmo-fat', 'show'))" class="inline-flex min-h-10 items-center text-[13px] font-semibold text-blue-700 hover:underline sm:min-h-0">รายละเอียด</button>
                            @endif
                        </span>
                    </div>
                    <div class="flex min-w-0 flex-col gap-0.5 rounded-xl border border-slate-200 p-3 lg:min-w-[12.5rem] lg:rounded-none lg:border-0 lg:border-l lg:py-0.5 lg:pl-5 lg:pr-0">
                        <span class="text-[13px] font-semibold text-slate-600">วันที่ส่งมอบ @if ($hdrNext && $hdrRounds->count() > 1)<span class="font-normal">รอบ {{ $hdrNext->round }} จาก {{ $hdrRounds->count() }}</span>@endif</span>
                        <span class="text-xl font-bold leading-tight tabular-nums sm:text-2xl {{ $dlTile[0] ? 'text-ae-navy' : 'text-slate-500' }}">{{ $dlTile[0] ?? 'ยังไม่ได้นัด' }}</span>
                        <span class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[13px] tabular-nums">
                            <span class="font-semibold {{ $dlTile[2] }}">{{ $dlTile[1] ?: ' ' }}</span>
                            @if ($hdrNext)<span class="st-pill rounded-full px-2 py-0.5 text-xs font-bold" style="--c: {{ $hdrNext->stateColor() }}">{{ $hdrNext->status === 'scheduled' && $hdrNext->cabinet_ids ? $hdrNext->step()['text'] : $hdrNext->stateLabel() }}</span>
                                <span class="text-slate-600">{{ $hdrNext->cabinetsText() }}</span>@endif
                        </span>
                        <span class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                            @if ($hdrCanAppt)
                                <button type="button" @click="tab = 'info'; $nextTick(() => $dispatch('pmo-delivery', 'new'))"
                                        class="inline-flex h-10 items-center rounded-lg bg-blue-600 px-3 text-[13px] font-semibold text-white hover:bg-blue-700 sm:h-8">ลงวันส่งมอบ</button>
                            @endif
                            @if ($hdrRounds->count())
                                <button type="button" @click="tab = 'info'; $nextTick(() => $dispatch('pmo-delivery', 'show'))" class="inline-flex min-h-10 items-center text-[13px] font-semibold text-blue-700 hover:underline sm:min-h-0">ดูทุกรอบ</button>
                            @endif
                        </span>
                    </div>
                </div>
            </div>

            @editplan
            {{-- the rest of the actions; deleting moves the whole project to the trash, so it sits last --}}
            <div class="absolute right-3 top-3 sm:right-4 sm:top-4" x-data="{ menu: false }" @click.outside="menu = false" @keydown.escape="menu = false">
                <button type="button" @click="menu = ! menu" :aria-expanded="menu" aria-haspopup="true" aria-label="เมนูเพิ่มเติม"
                        class="flex h-10 w-10 items-center justify-center rounded-[10px] border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 sm:h-9 sm:w-9">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
                </button>
                <div x-show="menu" x-cloak x-transition.opacity class="absolute right-0 z-30 mt-1 w-52 overflow-hidden rounded-xl border border-slate-200 bg-white p-1 text-sm shadow-xl">
                    <a href="{{ route('projects.edit', $project) }}" class="block rounded-lg px-3 py-2 font-medium text-slate-700 hover:bg-slate-50">แก้ไขโครงการ</a>
                    @if (! $project->isLocked() && $project->due_date)
                        <button type="button" @click="menu = false; tab = 'info'; $nextTick(() => $dispatch('open-modal', 'project-dates'))" class="block w-full rounded-lg px-3 py-2 text-left font-medium text-slate-700 hover:bg-slate-50">เลื่อน / แก้วันโครงการ</button>
                    @endif
                    <button type="button" @click="menu = false; $dispatch('open-modal', 'checklist-bulk')" class="block w-full rounded-lg px-3 py-2 text-left font-medium text-slate-700 hover:bg-slate-50">แก้ Checklist ทั้งโครงการ</button>
                    <div class="my-1 h-px bg-slate-100"></div>
                    <form method="POST" action="{{ route('projects.destroy', $project) }}" onsubmit="return confirm('ยืนยันการลบโครงการนี้? ตู้และงานทั้งหมดใต้โครงการจะย้ายไปถังขยะ (Admin/PM กู้คืนได้)')">
                        @csrf @method('DELETE')
                        <button class="block w-full rounded-lg px-3 py-2 text-left font-medium text-red-600 hover:bg-red-50">ลบโครงการ</button>
                    </form>
                </div>
            </div>
            @endeditplan
        </section>

        <div class="flex gap-1 overflow-x-auto px-1" role="tablist" aria-label="ส่วนของโครงการ">
            <button type="button" @click="tab = 'info'" role="tab" :aria-selected="tab === 'info'"
                    :class="tab === 'info' ? 'bg-ae-navy text-white' : 'text-slate-600 hover:bg-slate-200/60 hover:text-slate-800'"
                    class="whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold">ข้อมูลโครงการ</button>
            @if (auth()->user()->allows('data.comments'))
            <button type="button" @click="tab = 'comment'" role="tab" :aria-selected="tab === 'comment'"
                    :class="tab === 'comment' ? 'bg-ae-navy text-white' : 'text-slate-600 hover:bg-slate-200/60 hover:text-slate-800'"
                    class="whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold">Comment</button>
            <button type="button" @click="tab = 'activity'" role="tab" :aria-selected="tab === 'activity'"
                    :class="tab === 'activity' ? 'bg-ae-navy text-white' : 'text-slate-600 hover:bg-slate-200/60 hover:text-slate-800'"
                    class="whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold">Activity Log</button>
            @endif
        </div>

        <div x-show="tab === 'info'" role="tabpanel">
            @include('projects.partials._info')
        </div>

        @if (auth()->user()->allows('data.comments'))
        <div x-show="tab === 'comment'" x-cloak>
            <x-comment-thread :comments="$comments" note-route="projects.notes.store" :note-model="$project"
                               title="Comment" subtitle="ความคิดเห็น/บันทึกของโครงการนี้ เรียงล่าสุดก่อน" />
        </div>

        <div x-show="tab === 'activity'" x-cloak>
            <x-activity-tree :url="route('projects.activity-tree', $project)" :export-url="auth()->user()->allows('page.export') ? route('projects.activity-export', $project) : null" subtitle="ประวัติการเปลี่ยนแปลงทั้งหมดของโครงการนี้ จัดกลุ่มตามตู้/งาน เรียงล่าสุดก่อน" />
        </div>
        @endif

    </div>

    @editplan
        @include('projects.partials._checklist-bulk')
    @endeditplan

    <!-- Modal: Add Cabinet -->
    <x-modal panel name="add-cabinet">
        <form method="POST" action="{{ route('cabinets.store', $project) }}" class="p-6">
            @csrf
            <h3 class="text-base font-semibold text-slate-900 mb-4">เพิ่มตู้</h3>
            <div class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <x-input-label value="เลข MO" />
                        <x-text-input name="mo_no" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label value="ชื่อตู้" />
                        <x-text-input name="cabinet_name" class="mt-1 block w-full" required />
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <x-input-label value="ประเภทตู้" />
                        <x-text-input name="cabinet_type" class="mt-1 block w-full" placeholder="เช่น MDB, DB, MCC" />
                    </div>
                    <div>
                        <x-input-label value="ขนาดตู้" />
                        <x-text-input name="size" class="mt-1 block w-full" placeholder="เช่น 600x800x2000 มม." />
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <x-input-label value="วันเริ่ม" />
                        <x-text-input type="date" name="start_date" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label value="วันครบกำหนด" />
                        <x-text-input type="date" name="due_date" class="mt-1 block w-full" />
                    </div>
                </div>
                <div>
                    <x-input-label value="วันคาดว่าจะเสร็จ" />
                    <x-text-input type="date" name="expected_completion_date" class="mt-1 block w-full" />
                    <p class="text-xs text-slate-400 mt-1">ต้องไม่เกินวัน Due Date (ไม่บังคับ ใส่ทีหลังได้)</p>
                </div>
                <div>
                    <x-input-label value="เทมเพลตการผลิต" />
                    <select name="cabinet_template_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                        @foreach ($cabinetTemplates as $template)
                            <option value="{{ $template->id }}" @selected($template->is_default)>{{ $template->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-400 mt-1">ระบบจะคัดลอก Task/Sub Task/Checklist จากเทมเพลตนี้มาเป็นของตู้นี้โดยเฉพาะ</p>
                </div>
            </div>
            <div class="panel-foot mt-6 flex justify-end gap-3">
                <button type="button" x-data @click="$dispatch('close-modal', 'add-cabinet')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">เพิ่มตู้</button>
            </div>
        </form>
    </x-modal>

    <!-- Modals: Copy Cabinet -->
    @foreach ($project->cabinets as $cabinet)
        <x-modal panel :name="'copy-cabinet-'.$cabinet->id" :show="old('source_cabinet_id') == $cabinet->id">
            <form method="POST" action="{{ route('cabinets.copy', $cabinet) }}" class="p-6">
                @csrf
                <input type="hidden" name="source_cabinet_id" value="{{ $cabinet->id }}">
                <h3 class="text-base font-semibold text-slate-900 mb-1">คัดลอกตู้ — {{ $cabinet->mo_no }}</h3>
                <p class="text-xs text-slate-500 mb-4">ระบบจะคัดลอก Task/Sub Task/Checklist ทั้งหมดของตู้นี้ไปเป็นตู้ใหม่ (ไม่รวมวันที่ ความคืบหน้า และไฟล์แนบ)</p>
                <div class="space-y-4">
                    <div>
                        <x-input-label value="เลข MO ใหม่" />
                        <x-text-input name="mo_no"
                                      value="{{ old('source_cabinet_id') == $cabinet->id ? old('mo_no') : '' }}"
                                      class="mt-1 block w-full" required />
                        @if (old('source_cabinet_id') == $cabinet->id)
                            <x-input-error :messages="$errors->get('mo_no')" class="mt-1" />
                        @endif
                    </div>
                    <div>
                        <x-input-label value="ชื่อตู้" />
                        <x-text-input name="cabinet_name"
                                      value="{{ old('source_cabinet_id') == $cabinet->id ? old('cabinet_name') : $cabinet->cabinet_name }}"
                                      class="mt-1 block w-full" required />
                    </div>
                </div>
                <div class="panel-foot mt-6 flex justify-end gap-3">
                    <button type="button" x-data @click="$dispatch('close-modal', 'copy-cabinet-{{ $cabinet->id }}')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">คัดลอกตู้</button>
                </div>
            </form>
        </x-modal>
    @endforeach

    @push('scripts')
        @include('cabinets.partials._assignment-scripts')
        <script>
            /** "ข้อมูลโครงการ": the cabinet table's search / filters / pages, and which alert popover is open (one at a time). */
            function pmoProjectInfo(rows) {
                const STATUS = { not_started: ['ยังไม่เริ่ม', 'bg-slate-100 text-slate-600'], in_progress: ['กำลังผลิต', 'bg-blue-50 text-blue-700'],
                    completed: ['เสร็จแล้ว', 'bg-emerald-50 text-emerald-700'], on_hold: ['พักไว้', 'bg-amber-50 text-amber-700'] };
                const RISK = { high: ['สูง', 'text-red-600'], watch: ['เฝ้าระวัง', 'text-orange-600'], ok: ['ปกติ', 'text-slate-500'], none: ['-', 'text-slate-400'] };
                return {
                    rows, q: '', status: '', type: '', risk: '', page: 1, per: 20, alert: null, filters: false,
                    activeFilters() { return [this.status, this.type, this.risk].filter(Boolean).length; },
                    filtered() {
                        const q = this.q.trim().toLowerCase();
                        return this.rows.filter((r) => (! q || (r.mo + ' ' + (r.model || '')).toLowerCase().includes(q))
                            && (! this.status || r.status === this.status)
                            && (! this.type || r.type === this.type)
                            && (! this.risk || (this.risk === 'delayed' ? r.delayed : (this.risk === 'late-tasks' ? r.lateTasks > 0 : r.risk === this.risk))));
                    },
                    pages() { return Math.max(1, Math.ceil(this.filtered().length / this.per)); },
                    paged() { this.page = Math.min(this.page, this.pages()); return this.filtered().slice((this.page - 1) * this.per, this.page * this.per); },
                    statusLabel: (s) => (STATUS[s] || [s])[0], statusClass: (s) => (STATUS[s] || ['', 'bg-slate-100 text-slate-600'])[1],
                    riskLabel: (r) => RISK[r][0], riskClass: (r) => RISK[r][1],
                    // the due column only speaks up when it matters: late (red) or within a week (orange)
                    dueLabel: (d) => d === null ? '' : (d < 0 ? '· เลย ' + (-d) + ' วัน' : (d === 0 ? '· วันนี้' : '· ' + d + ' วัน')),
                    dueClass: (d) => d === null ? '' : (d < 0 ? 'text-red-600' : (d <= 7 ? 'text-orange-600' : 'text-slate-400')),
                };
            }
        </script>
    @endpush
</x-app-layout>
