{{-- New notices while a page stays open (layouts/app): the bell (pmoBell) sends each new / updated one as "pmo-notice", shown
     here as a small card at the top right for a few seconds - click to open it (counts as รับทราบ), × to dismiss. Something to
     do ("ต้องทำ") gets "รับทราบ" (and "รับงาน" for new work) right on the card. Urgent ones still open the alert window
     (x-status-report) as well. --}}
<div x-data="{
        list: [],
        push(n) {
            const t = { ...n, key: n.id + ':' + Date.now(), show: true, timer: null };
            this.list = [t, ...this.list.filter((x) => x.id !== n.id)].slice(0, 3);
            this.arm(t);
        },
        arm(t) { clearTimeout(t.timer); t.timer = setTimeout(() => this.drop(t), 9000); },
        hold(t) { clearTimeout(t.timer); },
        drop(t) { t.show = false; setTimeout(() => { this.list = this.list.filter((x) => x.key !== t.key); }, 200); },
        async ack(t) {
            this.drop(t);
            try {
                await fetch(t.ackUrl, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
            } catch (e) { /* offline: it stays under ต้องทำ */ }
            window.dispatchEvent(new CustomEvent('pmo-bell-refresh'));
        },
     }"
     x-on:pmo-notice.window="push($event.detail)"
     class="pointer-events-none fixed right-4 top-20 z-[75] flex w-[22rem] max-w-[calc(100vw-2rem)] flex-col gap-2"
     aria-live="polite">
    <template x-for="t in list" :key="t.key">
        <div x-show="t.show" @mouseenter="hold(t)" @mouseleave="arm(t)"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-x-4" x-transition:enter-end="opacity-100 translate-x-0"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="pointer-events-auto relative overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
            <form method="POST" :action="t.readUrl">
                @csrf
                <button class="flex w-full items-start gap-3 py-3 pl-3 pr-9 text-left hover:bg-slate-50">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#EAF4FF] text-[#0F4C81]">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-slate-900" x-text="t.title"></span>
                        <span class="line-clamp-2 block text-sm text-slate-700" x-text="t.latest || t.body"></span>
                        <span class="mt-0.5 block text-[12px] text-slate-400">เมื่อสักครู่ · คลิกเพื่อเปิด</span>
                    </span>
                </button>
            </form>
            <div x-show="t.ackUrl" class="flex justify-end gap-1.5 px-3 pb-2.5 text-[13px]">
                <a x-show="t.acceptUrl" :href="t.acceptUrl" class="rounded-md border border-[#0F4C81] px-3 py-1 font-semibold text-[#0F4C81] hover:bg-blue-50">รับงาน</a>
                <button type="button" @click="ack(t)" class="rounded-md bg-[#0F4C81] px-3 py-1 font-semibold text-white hover:bg-[#0A3760]">รับทราบ</button>
            </div>
            <button type="button" @click="drop(t)" class="absolute right-1.5 top-1.5 rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="ปิด">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
    </template>
</div>
