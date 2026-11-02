{{-- The วัน FAT window (ProjectFatController): on the project page (_fat) and fetched into Planning's project window
     (_fat-window, saved there as JSON). Needs $project, $fatCabinets, $cabName and an x-data holding `f` (formData) and `due`. --}}
<x-modal name="project-fat" maxWidth="lg">
    <template x-if="f">
        <form method="POST" :action="f.url" class="space-y-4 p-6">
            @csrf
            <input type="hidden" name="_method" :value="f.method">
            <h3 class="text-base font-semibold text-slate-900" x-text="f.title + ' · {{ $project->project_no }}'"></h3>
            @if ($errors->hasAny(['fat_date', 'fat_end_date', 'fat_time', 'status', 'cabinet_ids', 'note']))
                <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $errors->first() }}</p>
            @endif
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_7rem]">
                <label class="block text-sm font-semibold text-slate-800">วันที่ *
                    <input type="date" name="fat_date" x-model="f.fat_date" required class="mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal">
                </label>
                {{-- a FAT over several days: its last day (empty = one day) --}}
                <label class="block text-sm font-semibold text-slate-800">ถึงวันที่ <span class="font-normal text-slate-500">(ถ้าหลายวัน)</span>
                    <input type="date" name="fat_end_date" x-model="f.fat_end_date" :min="f.fat_date" class="mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal">
                </label>
                <label class="block text-sm font-semibold text-slate-800">เวลา
                    {{-- 24-hour (a native time field follows the browser's locale and may show AM / PM) --}}
                    <input type="text" name="fat_time" x-model="f.fat_time" placeholder="เช่น 13:30" inputmode="numeric" maxlength="5" autocomplete="off"
                           x-init="window.flatpickr($el, { enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true, allowInput: true, defaultHour: 9, defaultDate: f.fat_time || null, onChange: (d, v) => { f.fat_time = v; } })"
                           class="mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal tabular-nums">
                </label>
            </div>
            <p x-show="due && (f.fat_end_date || f.fat_date) > due && f.status !== 'passed'" class="-mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" role="alert">
                FAT หลังวันครบกำหนดของโครงการ ({{ $project->due_date?->thaiDate() }}) · บันทึกได้ แต่ควรเลื่อนวันส่งมอบด้วย</p>
            <fieldset>
                <legend class="text-sm font-semibold text-slate-800">สถานะ</legend>
                <div class="mt-1.5 flex flex-wrap gap-2">
                    @foreach (\App\Models\ProjectFat::STATUS_LABELS as $key => $label)
                        <label class="inline-flex h-10 cursor-pointer items-center gap-2 rounded-full border px-3.5 text-sm font-semibold"
                               :class="f.status === '{{ $key }}' ? 'border-slate-800 bg-slate-800 text-white' : 'border-slate-300 bg-white text-slate-700'">
                            <input type="radio" name="status" value="{{ $key }}" x-model="f.status" class="sr-only">{{ $key === 'failed' ? 'ไม่ผ่าน · ต้องนัดใหม่' : $label }}</label>
                    @endforeach
                </div>
            </fieldset>
            <fieldset>
                <legend class="text-sm font-semibold text-slate-800">ตู้ที่เข้า FAT</legend>
                <div class="mt-1.5 space-y-1 text-sm text-slate-700">
                    <label class="flex min-h-[2.25rem] items-center gap-2"><input type="radio" name="scope" value="all" x-model="f.scope"> ทุกตู้ ({{ $fatCabinets->count() }})</label>
                    <label class="flex min-h-[2.25rem] items-center gap-2"><input type="radio" name="scope" value="some" x-model="f.scope"> เฉพาะตู้ที่เลือก</label>
                    <div x-show="f.scope === 'some'" class="grid max-h-48 grid-cols-2 gap-x-3 overflow-y-auto pl-6">
                        @foreach ($fatCabinets as $c)
                            <label class="flex min-h-[2.25rem] items-center gap-2"><input type="checkbox" name="cabinet_ids[]" value="{{ $c->id }}" x-model.number="f.cabinet_ids" class="rounded"> {{ $cabName($c) }}</label>
                        @endforeach
                    </div>
                </div>
            </fieldset>
            <label class="block text-sm font-semibold text-slate-800">หมายเหตุ
                <textarea name="note" x-model="f.note" rows="2" maxlength="500" placeholder="เช่น ลูกค้า 3 คน · เหตุผลที่ไม่ผ่าน" class="mt-1 block w-full rounded-lg border-slate-300 text-sm font-normal"></textarea>
            </label>
            <div class="flex items-center gap-2 pt-1">
                <button type="button" x-show="f.deleteUrl" @click="if (confirm('ลบวัน FAT รอบนี้?')) { const d = document.getElementById('fat-delete-form'); d.action = f.deleteUrl; d.requestSubmit(); }"
                        class="h-10 rounded-lg px-3 text-sm font-semibold text-red-700 hover:bg-red-50">ลบรอบนี้</button>
                <button type="button" @click="$dispatch('close-modal', 'project-fat')" class="ml-auto h-10 rounded-lg px-4 text-sm font-semibold text-slate-600 hover:bg-slate-100">ยกเลิก</button>
                <button type="submit" class="h-10 rounded-lg bg-slate-800 px-5 text-sm font-semibold text-white hover:bg-slate-700">บันทึก</button>
            </div>
        </form>
    </template>
</x-modal>
<form id="fat-delete-form" method="POST" class="hidden">@csrf @method('DELETE')</form>
