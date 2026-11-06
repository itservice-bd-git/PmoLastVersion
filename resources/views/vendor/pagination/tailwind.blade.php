{{-- Every list's paging (->links()), in Thai and the app's slate palette: "แสดง 1–15 จาก 25" and the pages; on a phone
     just ก่อนหน้า / ถัดไป with where you are. Replaces the framework's English view. --}}
@if ($paginator->hasPages())
    @php
        $item = 'inline-flex h-9 min-w-[2.25rem] items-center justify-center border px-3 text-sm font-medium tabular-nums';
        $link = $item.' border-slate-200 bg-white text-slate-700 hover:bg-slate-50 hover:text-slate-900 focus-visible:relative focus-visible:z-10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500';
        $off = $item.' cursor-not-allowed border-slate-200 bg-white text-slate-300';
        $prev = '<svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>';
        $next = '<svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>';
        $range = $paginator->firstItem() ? $paginator->firstItem().'–'.$paginator->lastItem() : $paginator->count();
    @endphp
    <nav role="navigation" aria-label="เลือกหน้า" class="flex items-center justify-between gap-3">
        <p class="text-sm text-slate-600">แสดง <span class="font-semibold tabular-nums text-slate-800">{{ $range }}</span> จาก <span class="font-semibold tabular-nums text-slate-800">{{ number_format($paginator->total()) }}</span></p>

        {{-- a phone: back / on, and where you are --}}
        <div class="flex items-center gap-2 sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="{{ $off }} rounded-lg" aria-disabled="true">ก่อนหน้า</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $link }} rounded-lg">ก่อนหน้า</a>
            @endif
            <span class="text-sm tabular-nums text-slate-500">{{ $paginator->currentPage() }}/{{ $paginator->lastPage() }}</span>
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $link }} rounded-lg">ถัดไป</a>
            @else
                <span class="{{ $off }} rounded-lg" aria-disabled="true">ถัดไป</span>
            @endif
        </div>

        {{-- wider: every page --}}
        <div class="hidden -space-x-px sm:flex">
            @if ($paginator->onFirstPage())
                <span class="{{ $off }} rounded-l-lg px-2" aria-disabled="true" aria-label="หน้าก่อน">{!! $prev !!}</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $link }} rounded-l-lg px-2" aria-label="หน้าก่อน">{!! $prev !!}</a>
            @endif
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="{{ $item }} border-slate-200 bg-white text-slate-400" aria-disabled="true">{{ $element }}</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page" class="{{ $item }} relative z-[1] border-slate-800 bg-slate-800 font-semibold text-white">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="{{ $link }}" aria-label="ไปหน้า {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $link }} rounded-r-lg px-2" aria-label="หน้าถัดไป">{!! $next !!}</a>
            @else
                <span class="{{ $off }} rounded-r-lg px-2" aria-disabled="true" aria-label="หน้าถัดไป">{!! $next !!}</span>
            @endif
        </div>
    </nav>
@endif
