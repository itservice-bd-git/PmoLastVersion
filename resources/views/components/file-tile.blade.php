{{-- One attached file as a square tile (งานจัดส่ง): a photo shows itself, a document its kind (PDF, XLSX …). A photo or a
     PDF opens in <x-file-viewer> (the page must have one); the rest opens / downloads as a link. Its size comes from the
     caller's class (h-11 w-11, aspect-square w-full …). $file: ProjectDelivery::fileView(). --}}
@props(['file'])
@php $kind = $file['kind'] ?? null; @endphp
<a href="{{ $file['url'] }}" target="_blank" rel="noopener" title="{{ $file['name'] }} · {{ $file['size'] }}"
   aria-label="{{ $kind === 'image' ? 'ดูรูป' : 'เปิดไฟล์' }} {{ $file['name'] }}"
   @if ($kind) @click.prevent="$dispatch('pmo-file', {{ \Illuminate\Support\Js::from(['url' => $file['url'], 'name' => $file['name'], 'kind' => $kind]) }})" @endif
   {{ $attributes->merge(['class' => 'block shrink-0 overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-900/10 transition-shadow duration-150 hover:ring-2 hover:ring-teal-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2']) }}>
    @if ($kind === 'image')
        <img src="{{ $file['url'] }}" alt="" loading="lazy" decoding="async" class="h-full w-full object-cover">
    @else
        <span class="flex h-full w-full flex-col items-center justify-center gap-0.5 text-slate-500">
            <svg class="h-[42%] w-[42%]" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
            <span class="text-[10px] font-bold leading-none tracking-wide text-slate-600">{{ $file['ext'] ?? '' }}</span>
        </span>
    @endif
</a>
