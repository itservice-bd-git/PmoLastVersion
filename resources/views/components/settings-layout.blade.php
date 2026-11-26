{{-- Every Settings page (and the trash, the templates): the settings menu beside the page on a wide screen (xl, 1280px+ -
     the app's own sidebar takes 256px), above it everywhere narrower, folded under the current page's name.
     'space' is the gap between the page's own blocks (space-y-4 / space-y-6). --}}
@props(['space' => ''])
<div {{ $attributes->merge(['class' => '-mx-2 sm:-mx-3 lg:-mx-4 xl:grid xl:grid-cols-[15rem_minmax(0,1fr)] xl:items-start xl:gap-6']) }}>
    <x-settings-nav />
    <div class="min-w-0 {{ $space }}">
        {{ $slot }}
    </div>
</div>
