{{-- The การจัดส่ง window of PM (ProjectDeliveryController): which cabinets, the day it goes, the kinds of vehicle and how
     many people, where - sent to the delivery department, which puts the vehicles and the people on it (DeliveryController). On the project page (_delivery) and fetched into
     Planning's project window (_delivery-window, saved there as JSON). Needs $project and an x-data holding `f` (formData),
     `cabs` and `vehicles` (windowData). --}}
<x-modal name="project-delivery" maxWidth="xl">
    <template x-if="f">
        <form method="POST" :action="f.url" class="space-y-4 p-6"
              x-data="{ get unfinished() { return cabs.filter((c) => f.cabinet_ids.includes(c.id) && c.progress < 100); } }">
            @csrf
            <input type="hidden" name="_method" :value="f.verb">
            <input type="hidden" name="_delivery" :value="f.id || ''">{{-- which round to reopen on a validation error (_delivery) --}}
            <h3 class="text-base font-semibold text-slate-900" x-text="f.title + ' · {{ $project->project_no }}'"></h3>
            @if ($errors->hasAny(['delivery_date', 'delivery_time', 'cabinet_ids', 'method', 'vehicle_types', 'vehicle_types.0', 'crew_size', 'delivery']))
                <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $errors->first() }}</p>
            @endif
            {{-- sent back: why, to fix and send again --}}
            <div x-show="f.rejected" class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900" role="status">
                <p class="font-bold">แผนกจัดส่งไม่รับงาน · <span x-text="f.rejected && f.rejected.reason"></span></p>
                <p x-show="f.rejected && f.rejected.note" x-text="f.rejected && f.rejected.note"></p>
                <p class="text-xs text-red-700" x-text="f.rejected ? 'โดย ' + f.rejected.by + ' · ' + f.rejected.at + ' · เลือกวันใหม่แล้วกด “ส่งให้จัดส่งอีกครั้ง”' : ''"></p>
            </div>
            <p x-show="f.draft" class="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700" role="status"
               x-text="'ร่าง · แผนกจัดส่งยังไม่เห็นรอบนี้' + (f.all ? ' · ลงไว้เป็นทุกตู้ ตรวจตู้ที่ติ๊กไว้ก่อนส่ง' : '')"></p>
            <p x-show="f.picked && !f.locked" class="rounded-lg bg-teal-50 px-3 py-2 text-sm text-teal-900" role="status"
               x-text="'แผนกจัดส่งจัดรถแล้ว: ' + f.picked + (f.was_date && f.delivery_date !== f.was_date ? ' · เลื่อนวันแล้ว รถ / คนเดิมยังอยู่ แผนกจัดส่งจะได้แจ้งให้ตรวจว่าวันใหม่ยังว่าง' : '')"></p>
            <p x-show="f.billed && !f.locked" class="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">แจ้งเปิดบิลแล้ว · เปลี่ยนตู้ไม่ได้ (เลื่อนวันได้)</p>
            <p x-show="f.locked" class="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">รอบนี้ออกจากโรงงานแล้ว แก้ไม่ได้ · ย้อนสถานะได้ที่หน้า "งานจัดส่ง"</p>
            <fieldset :disabled="f.locked" class="space-y-4">
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-800">ตู้ที่ส่งรอบนี้ *</legend>
                    <div class="mt-1.5 grid max-h-52 grid-cols-1 gap-x-4 overflow-y-auto text-sm text-slate-700 sm:grid-cols-2">
                        <template x-for="c in cabs" :key="c.id">
                            <label class="flex min-h-[2.25rem] items-center gap-2" :class="c.round && c.round !== f.id ? 'text-slate-400' : ''">
                                <input type="checkbox" name="cabinet_ids[]" :value="c.id" x-model.number="f.cabinet_ids" :disabled="(c.round && c.round !== f.id) || f.billed" class="rounded">
                                <span x-text="c.name"></span>
                                <span class="text-xs" :class="c.round && c.round !== f.id ? '' : (c.progress < 100 ? 'text-amber-700' : 'text-slate-500')"
                                      x-text="c.round && c.round !== f.id ? '· ' + c.where : '· ผลิต ' + c.progress + '%'"></span>
                            </label>
                        </template>
                    </div>
                </fieldset>
                <p x-show="!cabs.length" class="-mt-2 text-sm text-slate-500">โครงการนี้ยังไม่มีตู้ · บันทึกร่างไว้ก่อนได้ (นับเป็นทุกตู้) แล้วส่งเมื่อมีตู้</p>
                <p x-show="cabs.length && !cabs.some((c) => !c.round || c.round === f.id)" class="-mt-2 text-sm text-slate-500">ทุกตู้อยู่ในรอบส่งอื่นแล้ว · แก้รอบเดิม หรือเพิ่มตู้ในโครงการก่อน</p>
                <p x-show="!f.locked && unfinished.length" class="-mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status"
                   x-text="unfinished.map((c) => c.name + ' (' + c.progress + '%)').join(', ') + ' ยังผลิตไม่ครบ · นัดได้ แต่ตรวจให้พร้อมก่อนวันส่ง'"></p>
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-800">การขนส่ง</legend>
                    <div class="mt-1.5 flex flex-wrap gap-2">
                        @foreach (array_keys(\App\Models\ProjectDelivery::METHOD_LABELS) as $key)
                            <label class="inline-flex h-9 cursor-pointer items-center rounded-full border px-3.5 text-sm font-semibold"
                                   :class="f.method === '{{ $key }}' ? 'border-slate-800 bg-slate-800 text-white' : 'border-slate-300 bg-white text-slate-700'">
                                <input type="radio" name="method" value="{{ $key }}" x-model="f.method" class="sr-only">{{ \App\Models\ProjectDelivery::methodName($key) }}</label>
                        @endforeach
                    </div>
                </fieldset>
                {{-- the day: PM's alone; the delivery department puts the vehicles and the people on it --}}
                <div class="grid grid-cols-[minmax(0,1fr)_8rem] gap-3">
                    <label class="block text-sm font-semibold text-slate-800">วันส่ง *
                        <input type="date" name="delivery_date" x-model="f.delivery_date" required class="mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal">
                    </label>
                    <label class="block text-sm font-semibold text-slate-800">เวลาถึงหน้างาน
                        <input type="text" name="delivery_time" x-model="f.delivery_time" placeholder="เช่น 09:00" inputmode="numeric" maxlength="5" autocomplete="off"
                               x-init="window.flatpickr && window.flatpickr($el, { enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true, allowInput: true, defaultHour: 9, defaultDate: f.delivery_time || null, onChange: (d, v) => { f.delivery_time = v; } })"
                               class="mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal tabular-nums">
                    </label>
                </div>
                <p x-show="f.was_date && f.delivery_date !== f.was_date" class="-mt-2 text-xs font-semibold text-amber-800"
                   x-text="'เลื่อนวันส่งจาก ' + f.was_date.split('-').reverse().join('/') + ' · บันทึกลงประวัติของโครงการ'"></p>
                {{-- the kinds of vehicle (several) and how many people --}}
                <fieldset x-show="f.method === 'company'">
                    <legend class="text-sm font-semibold text-slate-800">ประเภทรถที่ต้องใช้<span x-show="f.types.length"> *</span> <span class="font-normal text-slate-500">(เลือกได้หลายแบบ)</span></legend>
                    <div class="mt-1.5 flex flex-wrap gap-2">
                        <template x-for="t in f.types" :key="t">
                            <label class="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-full border px-3.5 text-sm font-semibold"
                                   :class="f.vehicle_types.includes(t) ? 'border-teal-700 bg-teal-50 text-teal-800' : 'border-slate-300 bg-white text-slate-700'">
                                <input type="checkbox" name="vehicle_types[]" :value="t" x-model="f.vehicle_types" :disabled="f.method !== 'company'" class="rounded text-teal-700"><span x-text="t"></span></label>
                        </template>
                    </div>
                    <p x-show="!f.types.length" class="mt-1 text-xs text-slate-500">ยังไม่มีประเภทรถ · ให้แผนกจัดส่งใส่ประเภทให้รถแต่ละคันที่ ตั้งค่า › รถขนส่ง</p>
                </fieldset>
                <label x-show="f.method !== 'pickup'" class="block w-40 text-sm font-semibold text-slate-800">จำนวนคน
                    <input type="number" name="crew_size" x-model="f.crew_size" :disabled="f.method === 'pickup'" min="1" max="20" inputmode="numeric" placeholder="เช่น 3" class="mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal tabular-nums">
                </label>
                <p x-show="f.method !== 'pickup'" class="-mt-2 text-xs text-slate-500">แผนกจัดส่งเลือกเองว่าไปกี่คัน คันไหน ใครไป · จำนวนคนรวมคนขับ</p>
                <label x-show="f.method === 'external'" class="block text-sm font-semibold text-slate-800">บริษัทขนส่ง / ทะเบียน
                    <input type="text" name="carrier" x-model="f.carrier" maxlength="150" placeholder="เช่น ไทยขนส่ง · 70-1234" class="mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal">
                </label>
                <label class="block text-sm font-semibold text-slate-800">สถานที่ส่ง
                    <input type="text" name="site" x-model="f.site" maxlength="255" placeholder="เช่น โรงงานบางนา ประตู 2" class="mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal">
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm font-semibold text-slate-800">ผู้ติดต่อหน้างาน
                        <input type="text" name="contact_name" x-model="f.contact_name" maxlength="100" class="mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal">
                    </label>
                    <label class="block text-sm font-semibold text-slate-800">เบอร์โทร
                        <input type="text" name="contact_phone" x-model="f.contact_phone" maxlength="30" inputmode="tel" class="mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal tabular-nums">
                    </label>
                </div>
                <label class="block text-sm font-semibold text-slate-800">หมายเหตุถึงแผนกจัดส่ง / คนขับ
                    <input type="text" name="note" x-model="f.note" maxlength="500" placeholder="เช่น ต้องใช้เครน · เข้าได้หลัง 8 โมง" class="mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal">
                </label>
            </fieldset>
            <div class="flex items-center gap-2 pt-1">
                <button type="button" x-show="f.deleteUrl" @click="if (confirm('ยกเลิกรอบส่งนี้?')) { const d = document.getElementById('delivery-delete-form'); d.action = f.deleteUrl; d.requestSubmit(); }"
                        class="h-10 rounded-lg px-3 text-sm font-semibold text-red-700 hover:bg-red-50">ยกเลิกรอบนี้</button>
                <button type="button" @click="$dispatch('close-modal', 'project-delivery')" class="ml-auto h-10 rounded-lg px-4 text-sm font-semibold text-slate-600 hover:bg-slate-100">ปิด</button>
                {{-- a new round or a draft: keep it as a draft, or send it (the button's send=0/1) --}}
                <button type="submit" name="send" value="0" x-show="!f.locked && (!f.id || f.draft)"
                        class="h-10 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">บันทึกร่าง</button>
                <button type="submit" name="send" value="1" x-show="!f.locked" :disabled="(!f.cabinet_ids.length && (!f.id || f.draft)) || !f.delivery_date" class="h-10 rounded-lg bg-slate-800 px-5 text-sm font-semibold text-white hover:bg-slate-700 disabled:opacity-50"
                        x-text="!f.id || f.draft ? 'ส่งให้แผนกจัดส่ง' : (f.status === 'rejected' ? 'ส่งให้จัดส่งอีกครั้ง' : 'บันทึก')"></button>
            </div>
        </form>
    </template>
</x-modal>
<form id="delivery-delete-form" method="POST" class="hidden">@csrf @method('DELETE')</form>
