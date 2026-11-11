{{-- one cabinet on the truck (driver/show): its name big, its MO and what it is under it, how many at the right --}}
<li class="flex items-center gap-3 px-4 py-3">
    <div class="min-w-0 flex-1">
        <p class="break-words text-base font-semibold leading-snug text-slate-900">{{ $c['name'] }}</p>
        @if ($c['mo'] || $c['detail'])
            <p class="mt-0.5 break-words text-sm text-slate-600">@if ($c['mo'])<span class="tabular-nums">MO {{ $c['mo'] }}</span>@endif{{ $c['mo'] && $c['detail'] ? ' · ' : '' }}{{ $c['detail'] }}</p>
        @endif
    </div>
    @if ($c['qty'] > 1)<span class="shrink-0 rounded-md bg-slate-100 px-2 py-0.5 text-sm font-bold tabular-nums text-slate-800" aria-label="{{ $c['qty'] }} ตู้">× {{ $c['qty'] }}</span>@endif
</li>
