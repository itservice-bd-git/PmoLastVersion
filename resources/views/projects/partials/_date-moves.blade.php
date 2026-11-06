{{-- ประวัติการเลื่อน of a round's วัน FAT / วันส่ง (App\Services\EventDates::history), newest first. Needs $moves; $withRound
     names the round on each line (FAT: the rounds' moves in one list). --}}
<ol class="divide-y divide-slate-100">
    @foreach ($moves as $c)
        @php $days = $c->days(); @endphp
        <li class="flex gap-3 py-2">
            <span class="inline-flex h-6 w-[4.5rem] shrink-0 items-center justify-center rounded-md text-xs font-bold {{ $c->n ? 'bg-orange-700 text-white' : 'bg-slate-200 text-slate-700' }}">{{ $c->n ? 'ครั้งที่ '.$c->n : 'แก้ไข' }}</span>
            <div class="min-w-0 flex-1 space-y-0.5 text-sm">
                <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    @if ($withRound ?? false)<span class="text-xs font-semibold text-slate-500">รอบที่ {{ $c->round }}</span>@endif
                    <span class="tabular-nums text-slate-500">{{ $c->fromText() }}</span><span aria-hidden="true" class="text-slate-400">→</span><span class="sr-only">เป็น</span>
                    <span class="font-bold tabular-nums text-slate-900">{{ $c->toText() }}</span>
                    @if ($days)<span class="font-bold {{ $days > 0 ? 'text-orange-700' : 'text-emerald-700' }}">{{ ($days > 0 ? '+' : '').$days }} วัน</span>@endif
                    @if ($c->reason_name)
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ \App\Models\ProjectDateReason::chip($c->reason?->color) }}">{{ $c->reason_name }}</span>
                    @elseif (! $c->n)
                        <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600">ลงวันผิด · ไม่นับเป็นการเลื่อน</span>
                    @endif
                </p>
                @if ($c->note)<p class="whitespace-pre-line break-words text-slate-700">{{ $c->note }}</p>@endif
                <p class="text-xs text-slate-500">{{ $c->changedBy?->name ?? '-' }} · {{ $c->created_at->thaiDateTime() }}</p>
            </div>
        </li>
    @endforeach
</ol>
