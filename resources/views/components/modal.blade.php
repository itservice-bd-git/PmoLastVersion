@props([
    'name',
    'show' => false,
    'maxWidth' => '2xl',
    // a panel from the right, as the app's other panels (FAT, การจัดส่ง, งานจัดส่ง): the same events, Esc, focus and
    // page lock as the window; full screen on a phone. A short confirmation stays a window.
    'panel' => false,
])

@php
$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
][$maxWidth];
$panelWidth = ['sm' => 'max-w-md', 'md' => 'max-w-lg', 'lg' => 'max-w-xl', 'xl' => 'max-w-xl', '2xl' => 'max-w-2xl'][array_search($maxWidth, ['sm' => 'sm:max-w-sm', 'md' => 'sm:max-w-md', 'lg' => 'sm:max-w-lg', 'xl' => 'sm:max-w-xl', '2xl' => 'sm:max-w-2xl'], true)];
@endphp

<div
    x-data="{
        show: @js($show),
        focusables() {
            // All focusable element types...
            let selector = 'a, button, input:not([type=\'hidden\']), textarea, select, details, [tabindex]:not([tabindex=\'-1\'])'
            return [...$el.querySelectorAll(selector)]
                // All non-disabled elements...
                .filter(el => ! el.hasAttribute('disabled'))
        },
        firstFocusable() { return this.focusables()[0] },
        lastFocusable() { return this.focusables().slice(-1)[0] },
        nextFocusable() { return this.focusables()[this.nextFocusableIndex()] || this.firstFocusable() },
        prevFocusable() { return this.focusables()[this.prevFocusableIndex()] || this.lastFocusable() },
        nextFocusableIndex() { return (this.focusables().indexOf(document.activeElement) + 1) % (this.focusables().length + 1) },
        prevFocusableIndex() { return Math.max(0, this.focusables().indexOf(document.activeElement)) -1 },
    }"
    x-init="$watch('show', value => {
        if (value) {
            document.body.classList.add('overflow-y-hidden');
            {{ $attributes->has('focusable') ? 'setTimeout(() => firstFocusable().focus(), 100)' : ($panel ? '$nextTick(() => $refs.closePanel && $refs.closePanel.focus({ preventScroll: true }))' : '') }}
        } else {
            document.body.classList.remove('overflow-y-hidden');
        }
    })"
    x-on:open-modal.window="$event.detail == '{{ $name }}' ? show = true : null"
    x-on:close-modal.window="$event.detail == '{{ $name }}' ? show = false : null"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false"
    x-on:keydown.tab.prevent="$event.shiftKey || nextFocusable().focus()"
    x-on:keydown.shift.tab.prevent="prevFocusable().focus()"
    x-show="show"
    @if ($panel)
    {{-- Light to open: the backdrop fades on its own layer and the panel only slides - nothing above the panel changes
         its opacity (a fading parent makes the browser repaint the whole panel every frame, slow in Safari). The outer
         layer only waits for them to finish closing. --}}
    role="dialog" aria-modal="true"
    x-transition:leave="transition-[visibility] duration-150"
    class="fixed inset-0 z-50 flex justify-end"
    style="display: {{ $show ? 'flex' : 'none' }};"
>
    <div x-show="show" x-on:click="show = false" aria-hidden="true" class="absolute inset-0 bg-slate-900/40"
         x-transition:enter="transition-opacity duration-150 ease-out" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"></div>
    <div x-show="show"
         x-transition:enter="transition-transform duration-200 ease-[cubic-bezier(.16,1,.3,1)]" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
         x-transition:leave="transition-transform duration-150 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
         class="relative flex h-full w-full {{ $panelWidth }} flex-col bg-white shadow-[-12px_0_32px_-12px_rgba(11,31,58,.25)] will-change-transform">
        <button type="button" x-ref="closePanel" x-on:click="show = false" aria-label="ปิด"
                class="absolute right-3 top-3 z-10 flex h-10 w-10 items-center justify-center rounded-lg bg-white/90 text-slate-500 hover:bg-slate-100 hover:text-slate-800">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
            {{ $slot }}
        </div>
    </div>
    @else
    class="fixed inset-0 overflow-y-auto px-4 py-6 sm:px-0 z-50"
    style="display: {{ $show ? 'block' : 'none' }};"
>
    <div
        x-show="show"
        class="fixed inset-0 transform transition-all"
        x-on:click="show = false"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div class="absolute inset-0 bg-ae-navy opacity-70"></div>
    </div>

    <div
        x-show="show"
        class="mb-6 bg-white rounded-xl overflow-hidden shadow-xl border border-slate-200 transform transition-all sm:w-full {{ $maxWidth }} sm:mx-auto"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
    >
        {{ $slot }}
    </div>
    @endif
</div>
