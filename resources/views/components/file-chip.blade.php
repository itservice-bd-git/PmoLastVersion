{{-- One attached file as Planning's comments show it: a photo's picture beside its name. A photo or a PDF opens in
     <x-file-viewer> (the page must have one); anything else opens / downloads as a link. $file: url, name, size, kind
     ('image' | 'pdf' | null) - ProjectDelivery::fileView(). --}}
@props(['file'])
@php $kind = $file['kind'] ?? null; @endphp
<a href="{{ $file['url'] }}" target="_blank" rel="noopener" title="{{ $file['name'] }}"
   @if ($kind) @click.prevent="$dispatch('pmo-file', {{ \Illuminate\Support\Js::from(['url' => $file['url'], 'name' => $file['name'], 'kind' => $kind]) }})" @endif
   {{ $attributes->merge(['class' => 'inline-flex max-w-full items-center gap-1 overflow-hidden rounded-lg border border-slate-200 bg-white text-[12px] text-slate-600 hover:border-blue-400']) }}>
    @if ($kind === 'image')<img src="{{ $file['url'] }}" alt="" loading="lazy" decoding="async" class="h-14 w-14 shrink-0 object-cover">@endif
    <span class="truncate px-2 py-1">📎 {{ $file['name'] }}{{ ($file['size'] ?? '') !== '' ? ' · '.$file['size'] : '' }}</span>
</a>
