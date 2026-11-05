{{-- วัน FAT (the customer's acceptance test at the factory): the latest round, its earlier rounds, and for Admin / PM the
     form that sets it (ProjectFatController). The same round shows on Planning's calendar and timeline (PlanningBoard). --}}
@php
    $fats = $project->fats()->get();
    $fat = $fats->last();
    $history = $fats->slice(0, -1)->reverse();
    $canFat = auth()->user()->canDispatchWork() && ! $project->isLocked();
    $fatCabinets = $project->cabinets()->orderBy('sequence')->orderBy('id')->get(['id', 'mo_no', 'cabinet_name']);
    $cabName = fn ($c) => $c->mo_no ?: $c->cabinet_name;
    $colour = fn (?\App\Models\ProjectFat $f) => \App\Support\EventStatuses::color('fat', $f ? $f->stateKey($project) : 'scheduled');   // Settings > สถานะงาน
    $days = $fat ? (int) today()->diffInDays($fat->fat_date, false) : null;
    $daysLeft = $fat ? (int) today()->diffInDays($fat->lastDay(), false) : null;   // a FAT over several days: still running until its last day
    $late = $fat?->isAfterDue($project);
    $form = fn (?\App\Models\ProjectFat $f) => \App\Models\ProjectFat::formData($project, $f, $fat);   // edit the latest round, or a new one
    $old = session()->getOldInput();
@endphp
<section class="rounded-xl border border-slate-200 bg-white p-5" aria-label="วัน FAT"
         x-data="{ due: @js($project->due_date?->format('Y-m-d')), f: null,
                   open(v) { this.f = JSON.parse(JSON.stringify(v)); this.$dispatch('open-modal', 'project-fat'); } }"
         @if ($errors->hasAny(['fat_date', 'fat_end_date', 'fat_time', 'status', 'cabinet_ids', 'note']) && $old)
             x-init="f = { ...@js($form($fat && ($old['_method'] ?? '') === 'PUT' ? $fat : null)), ...@js(\Illuminate\Support\Arr::only($old, ['fat_date', 'fat_end_date', 'fat_time', 'status', 'scope', 'note'])), cabinet_ids: (@js($old['cabinet_ids'] ?? [])).map(Number) }; $nextTick(() => $dispatch('open-modal', 'project-fat'))"
         @endif>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <span class="st-tag inline-flex h-6 items-center rounded px-2 text-xs font-extrabold tracking-wide" style="--c: {{ $colour($fat) }}">FAT</span>
        <h3 class="text-base font-bold text-ae-navy">วัน FAT</h3>
        @if ($fat)
            <span class="text-base font-bold tabular-nums text-slate-900">{{ $fat->when() }}</span>
            <span class="st-pill rounded-full px-2.5 py-0.5 text-xs font-bold" style="--c: {{ $colour($fat) }}">{{ $fat->round > 1 ? 'รอบที่ '.$fat->round.' · ' : '' }}{{ $fat->statusLabel() }}</span>
            @if ($fat->status === 'scheduled')
                <span class="text-sm {{ $daysLeft < 0 ? 'font-semibold text-red-700' : 'text-slate-600' }}">{{ $days > 0 ? 'อีก '.$days.' วัน' : ($daysLeft >= 0 ? ($fat->days() > 1 ? 'กำลัง FAT วันที่ '.(1 - $days).' จาก '.$fat->days() : 'วันนี้') : 'ผ่านมา '.abs($daysLeft).' วัน · ยังไม่ได้บันทึกผล') }}</span>
            @endif
        @else
            <span class="text-sm text-slate-500">ยังไม่ได้นัด</span>
        @endif
        @if ($canFat)
            <span class="ml-auto flex flex-wrap gap-2">
                @if ($fat && $fat->status !== 'failed')
                    <button type="button" @click="open(@js($form($fat)))" class="inline-flex h-9 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">แก้วัน FAT</button>
                @endif
                @if (! $fat || $fat->status === 'failed')
                    <button type="button" @click="open(@js($form(null)))" class="inline-flex h-9 items-center rounded-lg {{ $fat ? 'bg-slate-800 text-white hover:bg-slate-700' : 'border border-dashed border-slate-400 bg-white text-slate-700 hover:bg-slate-50' }} px-3 text-sm font-semibold">{{ $fat ? 'นัด FAT รอบใหม่' : '+ ลงวัน FAT' }}</button>
                @endif
            </span>
        @endif
    </div>

    @if ($fat)
        <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm text-slate-700">
            <span><span class="text-slate-500">ตู้ที่เข้า FAT:</span>
                {{ $fat->cabinet_ids ? $fatCabinets->whereIn('id', $fat->cabinet_ids)->map($cabName)->implode(', ') : 'ทุกตู้ ('.$fatCabinets->count().')' }}</span>
            @if ($fat->note)<span class="whitespace-pre-line"><span class="text-slate-500">หมายเหตุ:</span> {{ $fat->note }}</span>@endif
        </div>
        @if ($late)
            <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" role="alert">FAT ช้ากว่าวันครบกำหนดของโครงการ ({{ $project->due_date->thaiDate() }}) {{ $project->due_date->diffInDays($fat->lastDay()) }} วัน · เลื่อนวันส่งมอบ หรือขยับวัน FAT</p>
        @endif
        @if ($history->isNotEmpty())
            <div class="mt-3 border-t border-slate-100 pt-2 text-sm">
                <p class="text-xs font-semibold text-slate-500">ประวัติ FAT</p>
                @foreach ($history as $h)
                    <p class="mt-1 flex flex-wrap items-baseline gap-x-2 text-slate-600">
                        <span class="tabular-nums {{ $h->status === 'failed' ? 'line-through' : '' }}">รอบที่ {{ $h->round }} · {{ $h->when() }}</span>
                        <span class="st-pill rounded-full px-2 text-xs font-bold" style="--c: {{ $colour($h) }}">{{ $h->statusLabel() }}</span>
                        @if ($h->note)<span class="text-slate-500">{{ $h->note }}</span>@endif
                    </p>
                @endforeach
            </div>
        @endif
    @endif

    @if ($canFat)
        @include('projects.partials._fat-form')
    @endif
</section>
