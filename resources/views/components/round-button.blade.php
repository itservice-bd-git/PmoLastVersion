{{-- A button in a round's card on งานจัดส่ง (deliveries/index): `main` is the round's next step - teal, full width on a
     phone - else one of the others, all the same width, its icon slate. `icon` names one of the shapes below. --}}
@props(['main' => false, 'icon' => null])
@php
    $paths = [
        'cabinets' => '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-6 9 2 2 4-4"/>',
        'message' => '<path d="M4 5h16v11H8l-4 4z"/><path d="M8 9h8M8 12h5"/>',
        'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
        'refuse' => '<circle cx="12" cy="12" r="9"/><path d="m5.7 5.7 12.6 12.6"/>',
    ];
@endphp
<button type="button" {{ $attributes->class($main
    ? 'inline-flex h-11 items-center rounded-lg bg-teal-700 px-3 text-sm font-semibold text-white hover:bg-teal-800 col-span-2 w-full justify-center md:col-span-1'.($icon ? ' gap-1.5' : '')
    : 'inline-flex h-11 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 w-full min-w-0 gap-1.5') }}>
    @if ($icon)<svg @class(['h-4 w-4 shrink-0', 'text-slate-500' => ! $main]) fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">{!! $paths[$icon] !!}</svg>@endif<span class="truncate">{{ $slot }}</span>
</button>
