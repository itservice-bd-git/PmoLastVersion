{{-- "ไม่รับงาน": the one reason dialog of the app (layouts/app). Pages ask through window.askRejectReason({ title, detail })
     -> Promise<string|null> (null = cancelled), so the cabinet page's plain script and Planning's Alpine share it. --}}
@php
    $quick = ['คิวงานเต็ม', 'ไม่ใช่งานของแผนกนี้', 'ข้อมูล / แบบยังไม่ครบ', 'ของ / วัสดุยังไม่พร้อม', 'วันส่งเร็วเกินไป'];
@endphp
<div x-data="{
        open: false, title: '', detail: '', reason: '', resolve: null,
        ask(e) {
            Object.assign(this, { title: e.detail.title || 'ไม่รับงาน', detail: e.detail.detail || '', reason: '', resolve: e.detail.resolve, open: true });
            this.$nextTick(() => setTimeout(() => this.$refs.reason.focus(), 60));
        },
        pick(q) { this.reason = this.reason.trim() && !this.reason.startsWith(q) ? q + ' — ' + this.reason.trim() : q; this.$refs.reason.focus(); },
        finish(value) { if (!this.open) return; this.open = false; this.resolve && this.resolve(value); this.resolve = null; },
        send() { const r = this.reason.trim(); if (r) this.finish(r); },
     }"
     x-on:ask-reject-reason.window="ask($event)"
     x-on:keydown.escape.window="finish(null)"
     x-show="open" x-cloak
     class="fixed inset-0 z-[80] flex items-end justify-center px-4 py-6 sm:items-center"
     role="dialog" aria-modal="true" aria-labelledby="reject-dialog-title">
    <div x-show="open" x-transition.opacity.duration.200ms class="absolute inset-0 bg-ae-navy/70" @click="finish(null)"></div>

    <form @submit.prevent="send()" x-show="open"
          x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-4 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
          x-transition:leave="ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
          class="relative w-full max-w-lg overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
        <div class="flex items-start gap-3 px-6 pb-4 pt-5">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"/></svg>
            </span>
            <div class="min-w-0 flex-1">
                <h2 id="reject-dialog-title" class="text-base font-bold text-ae-navy" x-text="title"></h2>
                <p class="mt-0.5 break-words text-sm text-slate-500" x-show="detail" x-text="detail"></p>
            </div>
            <button type="button" @click="finish(null)" class="-mr-2 -mt-1 rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="ปิด">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        <div class="space-y-3 px-6 pb-5">
            <div>
                <p class="mb-1.5 text-xs font-semibold text-slate-500">เหตุผลที่พบบ่อย</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($quick as $q)
                        <button type="button" @click="pick(@js($q))"
                                class="rounded-full border px-3 py-1 text-sm transition"
                                :class="reason.startsWith(@js($q)) ? 'border-red-300 bg-red-50 text-red-700' : 'border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50'">{{ $q }}</button>
                    @endforeach
                </div>
            </div>
            <div>
                <label for="reject-reason" class="mb-1.5 block text-xs font-semibold text-slate-500">เหตุผล <span class="text-red-600">*</span></label>
                <textarea id="reject-reason" x-ref="reason" x-model="reason" rows="3" maxlength="500" required
                          @keydown.ctrl.enter.prevent="send()" @keydown.meta.enter.prevent="send()"
                          placeholder="เลือกด้านบน หรือพิมพ์รายละเอียดเพิ่ม เช่น ควรส่งให้แผนกไหน / ต้องการอะไรเพิ่ม"
                          class="block w-full resize-none rounded-lg border-slate-300 text-sm focus:border-red-400 focus:ring-red-200"></textarea>
                <p class="mt-1 text-right text-[12px] text-slate-400" x-text="reason.length + '/500'"></p>
            </div>
            <p class="flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2 text-xs leading-relaxed text-slate-600">
                <svg class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 11v5M12 8h.01"/></svg>
                <span>งานจะกลับไปเป็น “ยังไม่มอบหมาย” · PM ได้รับแจ้งเตือนพร้อมเหตุผลนี้ และจ่ายงานใหม่ได้</span>
            </p>
        </div>

        <div class="flex flex-col-reverse gap-2 border-t border-slate-100 bg-slate-50/60 px-6 py-4 sm:flex-row sm:justify-end">
            <button type="button" @click="finish(null)" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100">ยกเลิก</button>
            <button type="submit" :disabled="!reason.trim()"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-40">ส่งงานคืน PM</button>
        </div>
    </form>
</div>
