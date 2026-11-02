@php
    $actions = app(\App\Services\SubtaskAssignmentService::class)->actionsFor($subtask, auth()->user());
    $locked = $subtask->is_department_locked;
    $canEditDepartment = auth()->user()?->canDispatchWork() ?? false;
    $badge = [
        'UNASSIGNED' => 'bg-slate-100 text-slate-600',
        'ASSIGNED' => 'bg-amber-100 text-amber-700',
        'ACCEPTED' => 'bg-indigo-100 text-indigo-700',
        'IN_PROGRESS' => 'bg-blue-100 text-blue-700',
        'COMPLETED' => 'bg-emerald-100 text-emerald-700',
    ][$subtask->assignment_status] ?? 'bg-slate-100 text-slate-600';
    $trail = collect([
        $subtask->accepted_at ? 'รับงานโดย '.($subtask->acceptedBy?->name ?? '-').' '.$subtask->accepted_at->thaiDateTime() : null,
        $subtask->started_at ? 'เริ่มงานโดย '.($subtask->startedBy?->name ?? '-').' '.$subtask->started_at->thaiDateTime() : null,
        $subtask->completed_at ? 'เสร็จงานโดย '.($subtask->completedBy?->name ?? '-').' '.$subtask->completed_at->thaiDateTime() : null,
    ])->filter()->implode(' · ');
@endphp
@php $step = ['UNASSIGNED' => 0, 'ASSIGNED' => 1, 'ACCEPTED' => 2, 'IN_PROGRESS' => 3, 'COMPLETED' => 4][$subtask->assignment_status] ?? 0; @endphp
{{-- จ่าย → รับ → เริ่ม → เสร็จ at a glance (the cabinet page leaves it out: 'stepBar' => false) --}}
@if ($stepBar ?? true)
<span class="inline-flex gap-0.5" data-step-bar role="img" aria-label="ขั้นการจ่ายงาน {{ $step }} จาก 4 (จ่าย → รับ → เริ่ม → เสร็จ)" title="จ่าย → รับ → เริ่ม → เสร็จ">
    @for ($i = 1; $i <= 4; $i++)
        <span class="h-1.5 w-3 rounded-full {{ $i <= $step ? ($step === 4 ? 'bg-emerald-500' : 'bg-[#0F4C81]') : 'bg-slate-200' }}"></span>
    @endfor
</span>
@endif
<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap {{ $badge }}" data-assignment-status
      @if ($trail) title="{{ $trail }}" @endif>{{ $subtask->assignment_status_label }}</span>
@if ($subtask->assignment_status === 'UNASSIGNED' && $subtask->rejected_at)
    {{-- sent back ("ไม่รับงาน"): who and why, until it is handed out again --}}
    <span class="inline-flex max-w-xs items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700"
          title="{{ ($subtask->rejectedBy?->name ?? '-').' · '.$subtask->rejected_at->thaiDateTime().' — '.$subtask->rejected_reason }}">
        <span class="whitespace-nowrap">⛔ {{ $subtask->rejectedDepartment?->name ?? 'แผนก' }} ไม่รับงาน:</span>
        <span class="truncate">{{ $subtask->rejected_reason }}</span>
    </span>
@endif

@php
    // the department's colour, as on Planning
    $dot = $subtask->department_id ? '<span class="h-2 w-2 shrink-0 rounded-full" style="background: '.e(\App\Services\PlanningBoard::departmentColors()[$subtask->department_id] ?? '#94a3b8').'" aria-hidden="true"></span>' : '';
@endphp
@if ($locked)
    <span class="inline-flex items-center gap-1 text-xs font-medium text-slate-600 cursor-not-allowed"
          title="ไม่สามารถเปลี่ยนแผนกได้ เนื่องจากแผนกรับงานแล้ว">
        {!! $dot !!}{{ $subtask->department?->name ?? '-' }} <span aria-hidden="true">🔒</span>
    </span>
@elseif (! $canEditDepartment)
    <span class="inline-flex items-center gap-1 text-xs font-medium text-slate-500"
          title="เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่มีสิทธิ์เปลี่ยนแผนก">
        {!! $dot !!}{{ $subtask->department?->name ?? '- ยังไม่จ่ายงาน -' }}
    </span>
@else
    <span class="inline-flex items-center gap-1.5">{!! $dot !!}
    <select class="assignment-department-select rounded-lg border-slate-300 text-xs py-1 pl-2 pr-7"
            data-url="{{ route('cabinet-subtasks.department', $subtask) }}"
            data-previous="{{ $subtask->department_id }}"
            aria-label="Department">
        <option value="">- ยังไม่จ่ายงาน -</option>
        @foreach ($departments as $department)
            <option value="{{ $department->id }}" @selected($subtask->department_id === $department->id)>{{ $department->name }}</option>
        @endforeach
    </select>
    </span>
@endif

@if ($actions['can_accept'])
    <button type="button" class="assignment-action px-2 py-1 rounded-lg bg-indigo-600 text-white text-xs font-semibold hover:bg-indigo-700"
            data-url="{{ route('cabinet-subtasks.accept', $subtask) }}" data-confirm="ยืนยันรับงาน? หลังรับงานแล้วจะไม่สามารถเปลี่ยนแผนกได้">รับงาน</button>
@endif
@if ($actions['can_reject'])
    <button type="button" class="assignment-action px-2 py-1 rounded-lg border border-red-200 bg-white text-red-600 text-xs font-semibold hover:bg-red-50"
            data-url="{{ route('cabinet-subtasks.reject', $subtask) }}" data-reason="{{ $subtask->name }} · {{ $subtask->cabinetTask?->cabinet?->mo_no }}">ไม่รับงาน</button>
@endif
@if ($actions['can_start'])
    <button type="button" class="assignment-action assignment-step-button px-2 py-1 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700"
            data-url="{{ route('cabinet-subtasks.start', $subtask) }}">เริ่มงาน</button>
@endif
@if ($actions['can_complete'])
    <button type="button" class="assignment-action assignment-step-button px-2 py-1 rounded-lg bg-emerald-600 text-white text-xs font-semibold hover:bg-emerald-700"
            data-url="{{ route('cabinet-subtasks.complete', $subtask) }}">เสร็จงาน</button>
@endif

@php
    // the cabinet page's status menu (opened from the Sub Task's status badge): every step, the next one clickable
    $at = ['UNASSIGNED' => 0, 'ASSIGNED' => 1, 'ACCEPTED' => 2, 'IN_PROGRESS' => 3, 'COMPLETED' => 4][$subtask->assignment_status] ?? 0;
    $only = 'เฉพาะแผนก '.($subtask->department?->name ?? '-');
    $menu = [
        ['รับงาน', 'cabinet-subtasks.accept', 2, $actions['can_accept'], $subtask->accepted_at ? 'โดย '.($subtask->acceptedBy?->name ?? '-').' '.$subtask->accepted_at->thaiDateTime() : null,
            $at === 0 ? 'ยังไม่จ่ายงาน' : $only, 'ยืนยันรับงาน? หลังรับงานแล้วจะไม่สามารถเปลี่ยนแผนกได้'],
        ['เริ่มงาน', 'cabinet-subtasks.start', 3, $actions['can_start'], $subtask->started_at ? 'โดย '.($subtask->startedBy?->name ?? '-').' '.$subtask->started_at->thaiDateTime() : null,
            $at < 2 ? 'ต้องรับงานก่อน' : $only, null],
        ['เสร็จงาน', 'cabinet-subtasks.complete', 4, $actions['can_complete'], $subtask->completed_at ? 'โดย '.($subtask->completedBy?->name ?? '-').' '.$subtask->completed_at->thaiDateTime() : null,
            $at < 3 ? 'ต้องเริ่มงานก่อน' : $only, null],
    ];
@endphp
<div class="absolute left-0 top-full z-30 mt-1 hidden w-64 rounded-xl border border-slate-200 bg-white p-1.5 text-left shadow-lg" data-status-menu-panel role="menu" aria-label="อัปเดตสถานะงาน">
    <p class="px-2 pb-1 pt-0.5 text-[11px] font-semibold text-slate-500">สถานะงาน · {{ $subtask->assignment_status_label }}</p>
    @foreach ($menu as [$label, $route, $reach, $can, $by, $why, $confirm])
        @if ($at >= $reach)
            <div class="flex items-start gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-700" role="menuitem" aria-disabled="true">
                <span class="mt-0.5 text-emerald-600" aria-hidden="true">✓</span>
                <span class="min-w-0"><span class="font-medium">{{ $label }}แล้ว</span>@if ($by)<span class="block text-xs text-slate-500">{{ $by }}</span>@endif</span>
            </div>
        @elseif ($can)
            <button type="button" role="menuitem" class="assignment-action flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-semibold text-blue-700 hover:bg-blue-50 focus:bg-blue-50 focus:outline-none"
                    data-url="{{ route($route, $subtask) }}" @if ($confirm) data-confirm="{{ $confirm }}" @endif>
                <span aria-hidden="true">▶</span>{{ $label }}
            </button>
        @else
            <div class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-400" role="menuitem" aria-disabled="true">
                <span aria-hidden="true">○</span>{{ $label }} <span class="ml-auto text-xs">({{ $why }})</span>
            </div>
        @endif
    @endforeach
</div>
