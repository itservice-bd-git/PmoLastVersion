{{-- Workflow buttons for one item. $v = name of the JS variable holding it, $modal = inside the Work Detail Panel (vs. the compact row's action slot elsewhere). --}}
<span class="inline-flex items-center gap-2" @click.stop>
    <button type="button" x-show="{{ $v }}.assignment_status === 'ASSIGNED' && {{ $v }}.can_accept" :disabled="!!{{ $v }}.busy"
            @click.stop="act({{ $v }}, 'accept')"
            class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white text-xs font-semibold hover:bg-indigo-700 disabled:opacity-50">รับงาน</button>
    <span x-show="{{ $v }}.assignment_status === 'ASSIGNED' && !{{ $v }}.can_accept" class="text-xs text-slate-400">รอแผนกรับงาน</span>

    <button type="button" x-show="{{ $v }}.assignment_status === 'ACCEPTED' && {{ $v }}.can_start" :disabled="!!{{ $v }}.busy"
            @click.stop="act({{ $v }}, 'start')"
            class="px-2.5 py-1 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 disabled:opacity-50">เริ่มงาน</button>
    <span x-show="{{ $v }}.assignment_status === 'ACCEPTED' && !{{ $v }}.can_start" class="text-xs text-slate-400">รับงานแล้ว</span>

@if ($modal)
    <button type="button" x-show="{{ $v }}.assignment_status === 'IN_PROGRESS' && {{ $v }}.can_complete" :disabled="!!{{ $v }}.busy"
            @click.stop="act({{ $v }}, 'complete')"
            class="px-2.5 py-1 rounded-lg bg-emerald-600 text-white text-xs font-semibold hover:bg-emerald-700 disabled:opacity-50">เสร็จงาน</button>
@else
    <button type="button" x-show="{{ $v }}.assignment_status === 'IN_PROGRESS'"
            @click.stop="openDetail({{ $v }})"
            class="px-2.5 py-1 rounded-lg border border-slate-300 text-slate-700 text-xs font-semibold hover:bg-slate-50">ดู Checklist</button>
@endif

    <span x-show="{{ $v }}.assignment_status === 'COMPLETED'" class="text-xs font-medium text-emerald-700">เสร็จแล้ว ✓</span>
</span>
