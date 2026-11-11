{{-- ลิงก์คนขับ (DriverLinkController): the driver, no login, on a phone at the site - which round, the photos, ส่งถึงแล้ว.
     One column, big buttons (gloves, sunlight); nothing else of the system is shown. --}}
@php
    $max = \App\Models\ProjectDelivery::FILES_MAX;
    $units = array_sum(array_column($cabinets, 'qty'));   // pieces on the truck (a line may be 2 of the same)
    $shown = 5;                                            // past this, the rest fold away (a project can send 18)
@endphp
<x-embed-layout>
    <div class="mx-auto max-w-md space-y-4 pb-6">
        <div class="flex items-center gap-2 text-sm font-semibold text-slate-500">
            <img src="{{ asset('images/logo.png') }}" alt="" class="h-6 w-6">Avatar Electric · ส่งงาน
        </div>

        {{-- which job: the round first (what the driver was told), the project and where under it --}}
        <section class="rounded-xl border border-slate-200 bg-white p-4">
            <h1 class="text-xl font-bold leading-snug text-slate-900">{{ $d->project->project_no }} <span class="whitespace-nowrap">รอบ {{ $d->round }}</span></h1>
            <p class="mt-0.5 text-base text-slate-700">{{ $d->project->project_name }}</p>
            @if ($d->site)
                <p class="mt-3 flex gap-2 text-base text-slate-900">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-teal-700" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>
                    <span class="min-w-0 break-words"><span class="sr-only">ส่งที่ </span>{{ $d->site }}</span>
                </p>
            @endif
            @if ($nav = $d->navUrl())
                {{-- the pin PM / the delivery department put on, else a search for the site's words --}}
                <a href="{{ $nav }}" target="_blank" rel="noopener"
                   class="mt-3 flex h-12 w-full items-center justify-center gap-2 rounded-xl border border-teal-700 bg-white text-base font-bold text-teal-800 hover:bg-teal-50">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 11 18-8-8 18-2-8z"/></svg>
                    {{ $d->map_url ? 'นำทางไปหน้างาน' : 'ค้นหาสถานที่ในแผนที่' }}
                </a>
            @endif
        </section>

        {{-- what is on the truck: one row per cabinet - its name big, its MO and what it is under it, how many at the right --}}
        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white" aria-labelledby="cabs-head">
            <h2 id="cabs-head" class="flex items-baseline justify-between gap-3 border-b border-slate-200 px-4 py-3 text-base font-bold text-slate-900">
                ตู้ที่ส่งรอบนี้ <span class="text-sm font-semibold tabular-nums text-slate-600">{{ $units }} ตู้</span>
            </h2>
            <ul class="divide-y divide-slate-100">
                @foreach (array_slice($cabinets, 0, $shown) as $c) @include('driver._cabinet') @endforeach
            </ul>
            @if (count($cabinets) > $shown)
                <details class="group border-t border-slate-100">
                    <summary class="flex min-h-[3rem] cursor-pointer list-none items-center justify-center gap-1.5 px-4 text-sm font-semibold text-teal-800 hover:bg-teal-50 [&::-webkit-details-marker]:hidden">
                        <span class="group-open:hidden">ดูอีก {{ count($cabinets) - $shown }} ตู้</span><span class="hidden group-open:inline">ย่อรายการ</span>
                        <svg class="h-4 w-4 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </summary>
                    <ul class="divide-y divide-slate-100 border-t border-slate-100">
                        @foreach (array_slice($cabinets, $shown) as $c) @include('driver._cabinet') @endforeach
                    </ul>
                </details>
            @endif
            @if (! $cabinets)<p class="px-4 py-4 text-sm text-slate-600">ไม่มีรายการตู้ · ถามแผนกจัดส่ง</p>@endif
        </section>

        @if ($state === 'done')
            <section class="rounded-xl border border-emerald-200 bg-emerald-50 p-5 text-center" role="status">
                <svg class="mx-auto h-12 w-12 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>
                <p class="mt-2 text-lg font-bold text-emerald-900">ได้รับแล้ว ขอบคุณครับ</p>
                <p class="mt-1 text-sm text-emerald-800">บันทึกส่งถึง {{ $d->delivered_at?->thaiDate() }}{{ $d->delivered_at && $d->delivered_at->format('H:i') !== '00:00' ? ' '.$d->delivered_at->format('H:i').' น.' : '' }} แล้ว{{ $d->review_at ? ' · แผนกจัดส่งจะตรวจอีกครั้ง' : '' }} · ปิดหน้านี้ได้เลย</p>
            </section>
        @elseif ($state === 'wait')
            <section class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-center" role="status">
                <p class="text-base font-bold text-amber-900">รอบนี้ยังไม่ได้ออกจากโรงงาน</p>
                <p class="mt-1 text-sm text-amber-900">เมื่อรถออกแล้ว เปิดลิงก์นี้อีกครั้งเพื่อส่งรูปตอนส่งถึง</p>
            </section>
        @elseif ($state === 'closed')
            <section class="rounded-xl border border-slate-200 bg-white p-5 text-center" role="status">
                <p class="text-base font-bold text-slate-900">ลิงก์นี้ใช้ไม่ได้แล้ว</p>
                <p class="mt-1 text-sm text-slate-600">ติดต่อแผนกจัดส่งเพื่อขอลิงก์ใหม่</p>
            </section>
        @else
            {{-- each pick ADDS (a phone's camera gives one photo a time); the input's list is rebuilt so all of them go up --}}
            <form method="POST" action="{{ request()->fullUrl() }}" enctype="multipart/form-data" class="space-y-4"
                  x-data="{ files: [], error: '', saving: false, max: {{ $max }},
                            shrinking: false,
                            async add(input) {
                                this.shrinking = true;   // a phone's photos made small first (window.pmoShrinkImage, app.js)
                                const add = await Promise.all([...input.files].map((f) => (window.pmoShrinkImage ? window.pmoShrinkImage(f) : f)));
                                this.shrinking = false;
                                const big = add.filter((f) => f.size > 20 * 1024 * 1024), ok = add.filter((f) => f.size <= 20 * 1024 * 1024);
                                const room = Math.max(0, this.max - this.files.length);
                                this.error = big.length ? 'รูปใหญ่เกิน 20 MB · ไม่ได้เพิ่ม' : (ok.length > room ? 'ส่งได้ครั้งละไม่เกิน ' + this.max + ' รูป' : '');
                                this.files = this.files.concat(ok.slice(0, room).map((f) => ({ file: f, name: f.name, preview: (f.type || '').startsWith('image/') ? URL.createObjectURL(f) : '' })));
                                this.sync(input);
                            },
                            drop(i, input) { const f = this.files[i]; if (f && f.preview) URL.revokeObjectURL(f.preview); this.files.splice(i, 1); this.error = ''; this.sync(input); },
                            sync(input) {
                                try { const dt = new DataTransfer(); this.files.forEach((f) => dt.items.add(Alpine.raw(f).file)); input.files = dt.files; }
                                catch (e) { this.files = [...input.files].map((f) => ({ file: f, name: f.name, preview: (f.type || '').startsWith('image/') ? URL.createObjectURL(f) : '' })); }
                            } }"
                  @submit="if (!files.length) { $event.preventDefault(); error = 'ถ่ายรูปอย่างน้อย 1 รูป'; return; } saving = true">
                @csrf
                @if ($errors->any())
                    <p class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800" role="alert">{{ $errors->first() }}</p>
                @endif

                <section class="space-y-3 rounded-xl border border-slate-200 bg-white p-4">
                    <h2 class="text-base font-bold text-slate-900">ถ่ายรูปตอนส่งถึง</h2>
                    <p class="text-sm text-slate-600">ใบส่งของที่ลูกค้าเซ็นรับ และรูปตู้ที่หน้างาน · ได้ถึง {{ $max }} รูป</p>
                    <ul x-show="files.length" class="grid grid-cols-3 gap-2" aria-label="รูปที่จะส่ง" aria-live="polite">
                        <template x-for="(f, i) in files" :key="i">
                            <li class="relative aspect-square overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-900/10">
                                <template x-if="f.preview"><img :src="f.preview" alt="" class="h-full w-full object-cover"></template>
                                <template x-if="!f.preview"><span class="flex h-full w-full items-center justify-center p-1 text-center text-xs font-semibold text-slate-600" x-text="f.name"></span></template>
                                <button type="button" @click="drop(i, $refs.pick)" :aria-label="'เอารูปที่ ' + (i + 1) + ' ออก'" class="absolute right-0 top-0 flex h-11 w-11 items-start justify-end p-1">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-900/75 text-white ring-2 ring-white" aria-hidden="true"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" viewBox="0 0 24 24"><path d="M6 18 18 6M6 6l12 12"/></svg></span>
                                </button>
                            </li>
                        </template>
                    </ul>
                    <label x-show="files.length < max" class="flex min-h-[3.5rem] cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-teal-600 bg-teal-50 px-4 text-base font-bold text-teal-800 focus-within:ring-2 focus-within:ring-teal-600">
                        <svg class="h-6 w-6 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8.5A1.5 1.5 0 0 1 4.5 7h2.3l1.4-2h7.6l1.4 2h2.3A1.5 1.5 0 0 1 21 8.5v9A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5z"/><circle cx="12" cy="13" r="3.5"/></svg>
                        <span x-text="files.length ? 'ถ่าย / เพิ่มรูปอีก (' + files.length + '/' + max + ')' : 'ถ่ายรูป หรือเลือกรูป'"></span>
                        <input type="file" name="files[]" x-ref="pick" multiple accept="image/jpeg,image/png,.pdf" @change="add($event.target)" class="sr-only">
                    </label>
                    <p x-show="error" x-text="error" class="text-sm font-semibold text-red-700" role="alert"></p>
                </section>

                <section class="space-y-2 rounded-xl border border-slate-200 bg-white p-4">
                    <label for="issue" class="block text-base font-bold text-slate-900">หมายเหตุ <span class="font-normal text-slate-600">(ถ้ามี)</span></label>
                    <textarea id="issue" name="issue" rows="4" maxlength="500" placeholder="เช่น ลูกค้ารับไว้ก่อน · ตู้มีรอยขีดข่วนด้านซ้าย · วางตู้ไว้หน้าห้องไฟ"
                              class="block w-full resize-y rounded-lg border-slate-300 px-3 py-2.5 text-base leading-relaxed placeholder:text-slate-500 focus:border-teal-600 focus:ring-teal-600">{{ old('issue') }}</textarea>
                </section>

                <button type="submit" :disabled="saving || shrinking" class="flex h-14 w-full items-center justify-center rounded-xl bg-teal-700 text-lg font-bold text-white shadow-sm hover:bg-teal-800 disabled:opacity-60"
                        x-text="shrinking ? 'กำลังย่อรูป…' : (saving ? 'กำลังส่ง…' : 'ส่งถึงแล้ว · ส่งกลับ')">ส่งถึงแล้ว · ส่งกลับ</button>
                <p class="text-center text-xs text-slate-500">กดแล้วระบบบันทึกว่าส่งถึงตอนนี้ ทุกตู้ในรอบนี้</p>
            </form>
        @endif
    </div>
</x-embed-layout>
