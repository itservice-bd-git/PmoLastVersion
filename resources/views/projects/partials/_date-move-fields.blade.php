{{-- A round's day moved in its window (FAT / การจัดส่ง): postpone (counts, เลื่อนครั้งที่ n of the round) or a date typed
     wrong, why and what happened - kept in ประวัติการเลื่อน (App\Services\EventDates). Needs `moved` and `f` (formData:
     moves, move_kind, move_reason_id, move_note) in the Alpine scope. --}}
@php $moveReasons = \App\Services\EventDates::reasons(); @endphp
<div x-show="moved" x-cloak class="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-3">
    <fieldset>
        <legend class="sr-only">ชนิดการเปลี่ยนวัน</legend>
        <div class="grid grid-cols-2 overflow-hidden rounded-lg border border-slate-300 bg-white">
            <label class="flex min-h-[3.25rem] cursor-pointer flex-col justify-center px-3 py-1.5" :class="f.move_kind !== 'correct' ? 'bg-ae-navy text-white' : 'text-slate-800'">
                <input type="radio" name="move_kind" value="postpone" x-model="f.move_kind" class="sr-only">
                <span class="text-sm font-bold">เลื่อนวัน</span>
                <span class="text-xs" :class="f.move_kind !== 'correct' ? 'text-blue-100' : 'text-slate-600'" x-text="'นับเป็นครั้งที่ ' + ((f.moves || 0) + 1) + ' ของรอบนี้'"></span>
            </label>
            <label class="flex min-h-[3.25rem] cursor-pointer flex-col justify-center border-l border-slate-300 px-3 py-1.5" :class="f.move_kind === 'correct' ? 'bg-ae-navy text-white' : 'text-slate-800'">
                <input type="radio" name="move_kind" value="correct" x-model="f.move_kind" class="sr-only">
                <span class="text-sm font-bold">แก้วันที่ลงผิด</span>
                <span class="text-xs" :class="f.move_kind === 'correct' ? 'text-blue-100' : 'text-slate-600'">ไม่นับเป็นการเลื่อน · ยังเก็บประวัติ</span>
            </label>
        </div>
    </fieldset>
    @if ($moveReasons->isNotEmpty())
        <fieldset x-show="f.move_kind !== 'correct'">
            <legend class="text-sm font-semibold text-slate-800">สาเหตุที่เลื่อน</legend>
            <div class="mt-1.5 flex flex-wrap gap-2">
                @foreach ($moveReasons as $r)
                    <label class="inline-flex min-h-[2.5rem] cursor-pointer items-center rounded-full border px-3.5 text-sm"
                           :class="f.move_reason_id === {{ $r->id }} ? 'border-ae-navy bg-ae-navy font-bold text-white' : 'border-slate-300 bg-white text-slate-700'">
                        <input type="radio" name="move_reason_id" value="{{ $r->id }}" x-model.number="f.move_reason_id" :disabled="f.move_kind === 'correct'" class="sr-only">{{ $r->name }}</label>
                @endforeach
            </div>
        </fieldset>
    @endif
    <label class="block text-sm font-semibold text-slate-800">รายละเอียดการเลื่อน
        <input type="text" name="move_note" x-model="f.move_note" maxlength="500" autocomplete="off"
               :placeholder="f.move_kind === 'correct' ? 'เช่น ลงวันผิดตอนนัด' : 'เช่น ลูกค้าขอเลื่อนเพราะหน้างานยังไม่พร้อม'"
               class="mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal">
    </label>
</div>
