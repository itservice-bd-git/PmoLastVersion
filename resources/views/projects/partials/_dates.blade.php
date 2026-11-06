{{-- เลื่อน / แก้วัน (App\Services\ProjectDates) of a project (รายละเอียดโครงการ, _info) or a cabinet (หน้าตู้): the history of
     every change, and for Admin / PM the form, the only place their dates change once they have them.
     The page works out $dates / $canDates first and passes 'subject' (the Project or the Cabinet). --}}
@php
    $isCabinet = $subject instanceof \App\Models\Cabinet;
    $dateUrl = $isCabinet ? route('cabinets.dates.store', $subject) : route('projects.dates.store', $subject);
    $dateTitle = $isCabinet ? 'ตู้ '.($subject->mo_no ?: $subject->cabinet_name).' · '.$subject->project->project_no
        : $subject->project_no.' · '.$subject->project_name;
    $dueWord = $isCabinet ? 'กำหนดเสร็จ' : 'วันครบกำหนด';
    $reasons = $canDates ? \App\Models\ProjectDateReason::where('is_active', true)->orderBy('sequence')->orderBy('id')->get(['id', 'name']) : collect();
    $dateErrors = $errors->hasAny(['kind', 'due_date', 'start_date', 'reason_id', 'note']);
    $old = session()->getOldInput();
    $form = [
        'kind' => $old['kind'] ?? 'postpone',
        'due' => $dateErrors ? ($old['due_date'] ?? '') : '',
        'changeStart' => $dateErrors && ! empty($old['change_start']),
        'start' => $dateErrors ? ($old['start_date'] ?? '') : (string) $subject->start_date?->format('Y-m-d'),
        'reason' => $dateErrors ? (int) ($old['reason_id'] ?? 0) : 0,
        'note' => $dateErrors ? ($old['note'] ?? '') : '',
        'notify' => ! $dateErrors || ! empty($old['notify']),
    ];
@endphp
<div id="project-dates" x-data="pmoProjectDates(@js($form), @js($subject->due_date?->format('Y-m-d')), @js($dates['firstDue']?->format('Y-m-d')))"
     @if ($dateErrors) x-init="$nextTick(() => $dispatch('open-modal', 'project-dates'))" @endif>
    @if ($dates['changes']->isNotEmpty())
        <x-modal panel name="project-date-history" maxWidth="xl">
            <div class="p-6">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-base font-semibold text-slate-900">ประวัติการเลื่อน</h3>
                    @if ($dates['rounds'])
                        <span class="rounded-full bg-orange-100 px-2.5 py-0.5 text-sm font-bold text-orange-800">เลื่อนแล้ว {{ $dates['rounds'] }} ครั้ง{{ $dates['days'] ? ' · รวม '.($dates['days'] > 0 ? '+' : '').$dates['days'].' วัน' : '' }}</span>
                    @endif
                </div>
                <p class="text-sm text-slate-600">{{ $dateTitle }}{{ $dates['firstDue'] && $dates['rounds'] ? ' · '.$dueWord.'ตอนแรก '.$dates['firstDue']->thaiDate() : '' }}</p>
                <div class="mt-3 max-h-[60vh] overflow-y-auto">
                    <ol class="mt-1 divide-y divide-slate-100">
                        @foreach ($dates['changes'] as $c)
                            @php $days = $c->dueDays(); @endphp
                            <li class="flex gap-3 py-2.5">
                                <span class="inline-flex h-7 w-20 shrink-0 items-center justify-center rounded-md text-xs font-bold {{ $c->round ? 'bg-orange-700 text-white' : 'bg-slate-200 text-slate-700' }}">{{ $c->round ? 'ครั้งที่ '.$c->round : 'แก้ไข' }}</span>
                                <div class="min-w-0 flex-1 space-y-1">
                                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                                        @if ($days !== null)
                                            <span class="tabular-nums text-slate-500">{{ $c->old_due_date->thaiDate() }}</span><span aria-hidden="true" class="text-slate-400">→</span><span class="font-bold tabular-nums text-slate-900">{{ $c->new_due_date->thaiDate() }}</span>
                                            <span class="font-bold {{ $days > 0 ? 'text-orange-700' : 'text-emerald-700' }}">{{ ($days > 0 ? '+' : '').$days }} วัน</span>
                                        @endif
                                        @if ($c->old_start_date?->format('Y-m-d') !== $c->new_start_date?->format('Y-m-d'))
                                            <span class="text-slate-600">วันเริ่ม <span class="tabular-nums">{{ $c->old_start_date?->thaiDate() ?? '-' }}</span> → <span class="font-semibold tabular-nums">{{ $c->new_start_date?->thaiDate() ?? '-' }}</span></span>
                                        @endif
                                        @if ($c->reason_name)
                                            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ \App\Models\ProjectDateReason::chip($c->reason?->color) }}">{{ $c->reason_name }}</span>
                                        @elseif (! $c->round)
                                            <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600">แก้ข้อมูลผิด · ไม่นับเป็นการเลื่อน</span>
                                        @endif
                                    </p>
                                    @if ($c->note)<p class="whitespace-pre-line break-words text-sm text-slate-700">{{ $c->note }}</p>@endif
                                    <p class="text-xs text-slate-500">{{ $c->changedBy?->name ?? '-' }} · {{ $c->created_at->thaiDateTime() }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" @click="$dispatch('close-modal', 'project-date-history')" class="h-11 rounded-lg px-4 text-sm font-semibold text-slate-700 hover:bg-slate-100">ปิด</button>
                    @if ($canDates)
                        <button type="button" @click="$dispatch('close-modal', 'project-date-history'); $dispatch('open-modal', 'project-dates')" class="h-11 rounded-lg bg-blue-600 px-5 text-sm font-bold text-white hover:bg-blue-700">เลื่อน / แก้วัน</button>
                    @endif
                </div>
            </div>
        </x-modal>
    @endif

    @if ($canDates)
        <x-modal panel name="project-dates" maxWidth="lg">
            <form method="POST" action="{{ $dateUrl }}" class="space-y-4 p-6">
                @csrf
                <div>
                    <h3 class="text-base font-semibold text-slate-900">เลื่อน / แก้วัน{{ $isCabinet ? 'ตู้' : 'โครงการ' }}</h3>
                    <p class="text-sm text-slate-600">{{ $dateTitle }}</p>
                </div>
                @if ($dateErrors)
                    <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $errors->first() }}</p>
                @endif

                <fieldset>
                    <legend class="sr-only">ชนิดการเปลี่ยนวัน</legend>
                    <div class="grid grid-cols-2 overflow-hidden rounded-lg border border-slate-300">
                        <label class="flex min-h-[3.5rem] cursor-pointer flex-col justify-center px-3 py-2" :class="f.kind === 'postpone' ? 'bg-ae-navy text-white' : 'bg-white text-slate-800'">
                            <input type="radio" name="kind" value="postpone" x-model="f.kind" class="sr-only">
                            <span class="text-sm font-bold">เลื่อน{{ $dueWord }}</span>
                            <span class="text-xs" :class="f.kind === 'postpone' ? 'text-blue-100' : 'text-slate-600'">นับเป็นครั้งที่ {{ $dates['rounds'] + 1 }} · ต้องใส่สาเหตุ</span>
                        </label>
                        <label class="flex min-h-[3.5rem] cursor-pointer flex-col justify-center border-l border-slate-300 px-3 py-2" :class="f.kind === 'correct' ? 'bg-ae-navy text-white' : 'bg-white text-slate-800'">
                            <input type="radio" name="kind" value="correct" x-model="f.kind" class="sr-only">
                            <span class="text-sm font-bold">แก้ไขวันที่ลงผิด</span>
                            <span class="text-xs" :class="f.kind === 'correct' ? 'text-blue-100' : 'text-slate-600'">ไม่นับเป็นการเลื่อน · ยังเก็บประวัติ</span>
                        </label>
                    </div>
                </fieldset>

                <div class="grid grid-cols-2 items-end gap-3">
                    <div>
                        <p class="text-sm font-semibold text-slate-700">{{ $dueWord }}ตอนนี้</p>
                        <p class="mt-1 flex h-11 items-center rounded-lg bg-slate-100 px-3 text-sm tabular-nums text-slate-700">{{ $subject->due_date?->thaiDate() ?? '-' }}</p>
                    </div>
                    <label class="block text-sm font-semibold text-slate-700">{{ $dueWord }}ใหม่ <span x-show="f.kind === 'postpone'" class="text-red-700">*</span>
                        <input type="date" name="due_date" x-model="f.due" :required="f.kind === 'postpone'" class="mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal">
                    </label>
                </div>
                <p x-show="shift() !== null" class="-mt-2 flex flex-wrap gap-2 text-sm">
                    <span class="rounded-full px-2.5 py-0.5 font-bold" :class="shift() > 0 ? 'bg-orange-100 text-orange-800' : 'bg-emerald-100 text-emerald-800'" x-text="(shift() > 0 ? '+' : '') + shift() + ' วัน (' + workdays() + ' วันทำงาน)'"></span>
                    <span x-show="total() !== null && total() !== shift() && f.kind === 'postpone'" class="rounded-full bg-slate-100 px-2.5 py-0.5 text-slate-700" x-text="'รวมจากวันแรก ' + (total() > 0 ? '+' : '') + total() + ' วัน'"></span>
                </p>

                <div>
                    <label class="inline-flex min-h-[2.25rem] items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="change_start" value="1" x-model="f.changeStart" class="h-4 w-4 rounded border-slate-300">
                        เปลี่ยนวันเริ่มด้วย (ตอนนี้ {{ $subject->start_date?->thaiDate() ?? 'ยังไม่ได้ใส่' }})
                    </label>
                    <label x-show="f.changeStart" class="mt-1 block text-sm font-semibold text-slate-700">วันเริ่มใหม่
                        <input type="date" name="start_date" x-model="f.start" :disabled="!f.changeStart" class="mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal">
                    </label>
                </div>

                <fieldset x-show="f.kind === 'postpone'">
                    <legend class="text-sm font-semibold text-slate-700">สาเหตุ <span class="text-red-700">*</span></legend>
                    <div class="mt-1.5 flex flex-wrap gap-2">
                        @forelse ($reasons as $r)
                            <label class="inline-flex min-h-[2.5rem] cursor-pointer items-center rounded-full border px-3.5 text-sm"
                                   :class="f.reason === {{ $r->id }} ? 'border-ae-navy bg-ae-navy font-bold text-white' : 'border-slate-300 bg-white text-slate-700'">
                                <input type="radio" name="reason_id" value="{{ $r->id }}" x-model.number="f.reason" class="sr-only">{{ $r->name }}</label>
                        @empty
                            <p class="text-sm text-slate-600">ยังไม่มีสาเหตุให้เลือก — Admin เพิ่มได้ที่ ตั้งค่า › สาเหตุการเลื่อน</p>
                        @endforelse
                    </div>
                </fieldset>

                <label class="block text-sm font-semibold text-slate-700">รายละเอียด <span x-show="f.kind === 'postpone'" class="text-red-700">*</span>
                    <textarea name="note" x-model="f.note" rows="3" maxlength="1000" :required="f.kind === 'postpone'"
                              :placeholder="f.kind === 'postpone' ? 'เช่น หน้างานลูกค้าเลื่อนเทพื้นห้องไฟฟ้าอีก 2 สัปดาห์' : 'เช่น ลงวันผิดตอนเปิดโครงการ'"
                              class="mt-1 block w-full rounded-lg border-slate-300 text-sm font-normal"></textarea>
                </label>

                <label x-show="f.kind === 'postpone'" class="flex min-h-[2.25rem] items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
                    <input type="checkbox" name="notify" value="1" x-model="f.notify" class="h-4 w-4 rounded border-slate-300">
                    @php $notifyDepts = \App\Services\ProjectDates::departments($subject)->pluck('name'); @endphp
                    <span>แจ้ง PM ผู้รับผิดชอบ ผู้ติดตามโครงการ{{ $notifyDepts->isNotEmpty() ? ' และทุกคนในแผนกที่มีงาน'.($isCabinet ? 'ในตู้นี้' : '').' ('.$notifyDepts->implode(', ').')' : '' }}</span>
                </label>
                <p class="text-xs text-slate-600">{{ $isCabinet ? 'วันของงานย่อยในตู้ไม่เปลี่ยนตาม — เลื่อนเองที่ Sub Task หรือ Planning' : 'วันของงานย่อยและตู้ไม่เปลี่ยนตาม — เลื่อนเองที่หน้าตู้หรือ Planning' }}</p>

                <div class="flex items-center justify-end gap-2 pt-1">
                    <button type="button" @click="$dispatch('close-modal', 'project-dates')" class="h-11 rounded-lg px-4 text-sm font-semibold text-slate-700 hover:bg-slate-100">ยกเลิก</button>
                    <button type="submit" class="h-11 rounded-lg bg-blue-600 px-5 text-sm font-bold text-white hover:bg-blue-700"
                            x-text="f.kind === 'postpone' ? 'บันทึกการเลื่อน (ครั้งที่ {{ $dates['rounds'] + 1 }})' : 'บันทึกการแก้ไข'">บันทึก</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>

