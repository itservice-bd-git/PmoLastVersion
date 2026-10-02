{{--
    Compact Sub Task row for the Project Detail Panel's Cabinet accordion - a dense
    list row (ClickUp-style), not a card. $v = JS variable name holding the item
    (same item shape this.items/this.detail already use everywhere else).

    Presentation only - reuses the exact same state/actions as everywhere else:
    dotClass()/badgeClass() for status color (same precedence as Calendar/List),
    act()/openDetail() for the workflow, and item.can_accept/can_start/busy (already
    computed server-side, so Permission/Department Lock are untouched). The row
    click opens the existing Sub Task detail/Checklist modal - no new detail UI.
    Project/Cabinet/Department/Dates/Priority/Instruction deliberately aren't
    repeated here - the user already scanned down to this row via the Cabinet/Task
    headers above it; that detail is one click away in the modal.
--}}
<div @click="openDetail({{ $v }})"
     class="group flex items-center gap-2 pl-12 pr-3 py-3 lg:py-2.5 cursor-pointer hover:bg-slate-50 transition">
    {{-- Status icon: checkmark when done, a colored dot otherwise (same color
         precedence dotClass() already uses everywhere - completed/overdue/near_due/status). --}}
    <span class="shrink-0 w-4 flex items-center justify-center">
        <svg x-show="{{ $v }}.assignment_status === 'COMPLETED'" class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
        </svg>
        <span x-show="{{ $v }}.assignment_status !== 'COMPLETED'" :class="dotClass({{ $v }})" class="w-2 h-2 rounded-full"></span>
    </span>

    {{-- Name - the one piece of information this row exists to show; gets the
         remaining width first, truncates with a title tooltip for the full text.
         Color/weight (subtaskNameClass) carries visual priority - Overdue stands
         out most, Completed is muted, no strikethrough (hard to scan after). --}}
    <p class="flex-1 min-w-0 text-sm truncate" :class="subtaskNameClass({{ $v }})" :title="{{ $v }}.name" x-text="{{ $v }}.name"></p>

    {{-- Status - compact badge, never a full-row background. Uses a short word
         (shortStatusLabel) rather than the server's longer assignment_status_label,
         which wrapped to 2 lines in this narrow column. --}}
    <span class="shrink-0 w-14">
        <span :class="badgeClass({{ $v }})" class="inline-flex items-center whitespace-nowrap rounded-full px-1.5 py-0.5 text-[11px] font-medium" x-text="shortStatusLabel({{ $v }})"></span>
    </span>

    {{-- Checklist - x/y only, no progress bar (that stays in the detail view) --}}
    <span class="shrink-0 w-12 text-right text-xs text-slate-400 tabular-nums" x-text="{{ $v }}.checklists_completed + '/' + {{ $v }}.checklists_total"></span>

    {{-- Action - at most one compact quick action, hidden by default on desktop
         and revealed on row hover/focus (so the row stays visually quiet until
         needed); always visible below lg: since touch has no hover. Everything
         else (Complete, Checklist, full detail) is reached via the row click
         above, where the full action set from _item-actions.blade.php still
         lives, unchanged. --}}
    <span class="shrink-0 w-12 flex items-center justify-end" @click.stop>
        <button type="button" x-show="{{ $v }}.assignment_status === 'ASSIGNED' && {{ $v }}.can_accept" :disabled="!!{{ $v }}.busy"
                @click="act({{ $v }}, 'accept')"
                class="px-2 py-1 rounded-md bg-indigo-600 text-white text-[11px] font-semibold hover:bg-indigo-700 disabled:opacity-50 opacity-100 lg:opacity-0 lg:group-hover:opacity-100 lg:group-focus-within:opacity-100 transition">รับ</button>
        <button type="button" x-show="{{ $v }}.assignment_status === 'ACCEPTED' && {{ $v }}.can_start" :disabled="!!{{ $v }}.busy"
                @click="act({{ $v }}, 'start')"
                class="px-2 py-1 rounded-md bg-blue-600 text-white text-[11px] font-semibold hover:bg-blue-700 disabled:opacity-50 opacity-100 lg:opacity-0 lg:group-hover:opacity-100 lg:group-focus-within:opacity-100 transition">เริ่ม</button>
        <svg x-show="!((({{ $v }}.assignment_status === 'ASSIGNED') && {{ $v }}.can_accept) || (({{ $v }}.assignment_status === 'ACCEPTED') && {{ $v }}.can_start))"
             class="w-4 h-4 text-slate-300 group-hover:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
        </svg>
    </span>
</div>
