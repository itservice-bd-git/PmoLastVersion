{{-- A photo / PDF over the page, as Planning's (planning/partials/_overlays.blade.php): opened by a pmo-file event
     ({ url, name, kind: 'image' | 'pdf' }) that <x-file-tile> sends. Esc closes it and only it: caught on the way down
     (capture) so a panel or window under it stays open. Put it once in a page, outside any transformed panel. --}}
<div x-data="{ viewer: null }" @pmo-file.window="viewer = $event.detail; $nextTick(() => $refs.viewerClose?.focus({ preventScroll: true }))"
     @keydown.escape.window.capture="if (viewer) { $event.stopPropagation(); viewer = null }">
    <template x-if="viewer">
        <div data-file-viewer class="fixed inset-0 z-[70] flex flex-col bg-slate-900/90" role="dialog" aria-modal="true" :aria-label="viewer.name">
            <div class="flex items-center gap-3 px-4 py-2 text-sm text-white">
                <span class="min-w-0 flex-1 truncate" x-text="viewer.name"></span>
                <a :href="viewer.url" target="_blank" rel="noopener" class="rounded border border-white/40 px-2 py-0.5 text-xs hover:bg-white/10">เปิดแท็บใหม่ / ดาวน์โหลด</a>
                <button type="button" x-ref="viewerClose" @click="viewer = null" class="flex h-10 w-10 items-center justify-center text-2xl leading-none text-white/70 hover:text-white" aria-label="ปิด">&times;</button>
            </div>
            <div class="flex min-h-0 flex-1 items-center justify-center p-3" @click.self="viewer = null">
                <img x-show="viewer.kind === 'image'" :src="viewer.url" alt="" class="max-h-full max-w-full rounded object-contain">
                <iframe x-show="viewer.kind === 'pdf'" :src="viewer.kind === 'pdf' ? viewer.url : 'about:blank'" class="h-full w-full max-w-5xl rounded bg-white" title="PDF"></iframe>
            </div>
        </div>
    </template>
</div>
