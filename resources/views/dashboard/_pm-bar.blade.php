{{-- Beside the dashboard tabs on "สัปดาห์นี้" (App\Services\PmWeek): every project or only the PM's own, and full screen.
     Swapped in with #pm-board when the week changes (resources/js/pm-week.js). --}}
<div id="pm-bar" class="flex flex-wrap items-center gap-x-4 gap-y-2">
    @if ($pm['myProjects'] > 0)
        <nav aria-label="โครงการที่แสดง" class="inline-flex rounded-lg bg-slate-200 p-1 text-sm font-semibold">
            @foreach ([0 => 'ทุกโครงการ', 1 => 'ที่ฉันเป็น PM ('.$pm['myProjects'].')'] as $mine => $label)
                <a href="{{ route('dashboard', array_filter(['view' => 'pm', 'mine' => $mine ?: null, 'week' => $pm['offset'] ?: null])) }}" data-pm-nav="{{ $mine ? 'mine' : 'all' }}" @if ($pm['mine'] === (bool) $mine) aria-current="page" @endif
                   class="rounded-md px-3 py-1 {{ $pm['mine'] === (bool) $mine ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">{{ $label }}</a>
            @endforeach
        </nav>
    @endif
    {{-- for a meeting-room screen, like สรุปงาน's --}}
    <button type="button" x-data @click="document.getElementById('pm-board')?.requestFullscreen?.()"
            class="hidden h-9 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 xl:inline-flex">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>
        เต็มจอ
    </button>
</div>
