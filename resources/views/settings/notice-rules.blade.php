<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2>
    </x-slot>

    @php
        $events = \App\Models\ChecklistNoticeRule::EVENTS;
        $eventHints = ['date' => 'แผนกนี้ลง / เลื่อนวันของรายการ', 'tick' => 'แผนกนี้ติ๊กรายการ', 'cabinet' => 'แผนกนี้ติ๊กครบทุกรายการของตู้', 'before' => 'เตือนล่วงหน้าก่อนวันที่ลงไว้', 'late' => 'เลยวันที่ลงไว้แล้วยังไม่ได้รับ'];
        $deptNames = $departments->pluck('name', 'id');
        $userNames = $users->pluck('name', 'id');
        $whoLabel = fn ($r) => match (true) {
            $r === 'linked' => 'แผนกที่เทียบด้วย',
            $r === 'owner' => 'ผู้รับผิดชอบงานฝั่งที่เทียบ',
            $r === 'pm' => 'PM ของโครงการ',
            str_starts_with($r, 'dept:') => $deptNames[(int) substr($r, 5)] ?? 'แผนกที่ลบแล้ว',
            str_starts_with($r, 'user:') => $userNames[(int) substr($r, 5)] ?? 'ผู้ใช้ที่ปิดแล้ว',
            default => $r,
        };
        $ruleData = $rules->map(fn ($r) => ['id' => $r->id, 'event' => $r->event, 'department_id' => (string) $r->department_id, 'cabinet_template_id' => (string) ($r->cabinet_template_id ?? ''),
            'days' => $r->days ?? 2, 'repeat' => $r->repeat, 'message' => $r->message, 'recipients' => $r->recipients, 'is_active' => $r->is_active,
            'url' => route('settings.notice-rules.update', $r)])->keyBy('id');
    @endphp

    <div class="max-w-none mx-auto">
        <x-settings-nav />
        <div class="space-y-5" x-data="noticeRules(@js(['rules' => $ruleData, 'storeUrl' => route('settings.notice-rules.store'), 'depts' => $deptNames, 'old' => session()->getOldInput() ?: null]))">

        <x-card title="กฎแจ้งเตือน Checklist" subtitle="แจ้งเตือนระหว่างแผนกที่ใช้รายการร่วมกัน (คู่เทียบในเทมเพลต) เช่น คลังรับของแล้วแจ้งจัดซื้อและช่าง · แผนกที่ยังไม่มีกฎของเหตุการณ์นั้นจะแจ้งแบบเดิม · ผู้ใช้แต่ละคนยังปิดของตัวเองได้ที่ “การแจ้งเตือน”">
            <x-slot name="actions">
                <button type="button" @click="openNew()" class="inline-flex items-center gap-1.5 rounded-lg bg-[#0F4C81] px-3 py-2 text-sm font-semibold text-white hover:bg-ae-navy">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>เพิ่มกฎ
                </button>
            </x-slot>

            <div class="-mx-5 -my-4 overflow-x-auto">
                <table class="w-full min-w-[56rem] text-sm">
                    <thead class="bg-slate-50 text-left text-xs text-slate-500">
                        <tr>
                            <th class="py-2.5 pl-5 pr-3 font-semibold">เปิด</th>
                            <th class="px-3 font-semibold">เมื่อ</th>
                            <th class="px-3 font-semibold">ข้อความ</th>
                            <th class="px-3 font-semibold">ส่งถึง</th>
                            <th class="px-3 font-semibold">ส่ง</th>
                            <th class="pl-3 pr-5"><span class="sr-only">จัดการ</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($rules as $rule)
                            <tr class="align-top {{ $rule->is_active ? '' : 'bg-slate-50/60 text-slate-400' }}">
                                <td class="py-3 pl-5 pr-3">
                                    <form method="POST" action="{{ route('settings.notice-rules.update', $rule) }}">
                                        @csrf @method('PUT') <input type="hidden" name="toggle" value="1">
                                        <button role="switch" aria-checked="{{ $rule->is_active ? 'true' : 'false' }}" aria-label="เปิดใช้กฎนี้"
                                                class="relative inline-block h-5 w-9 rounded-full transition {{ $rule->is_active ? 'bg-[#1479D7]' : 'bg-slate-300' }}">
                                            <span class="absolute top-0.5 h-4 w-4 rounded-full bg-white transition-all {{ $rule->is_active ? 'left-[1.125rem]' : 'left-0.5' }}"></span>
                                        </button>
                                    </form>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="font-semibold {{ $rule->is_active ? ($rule->event === 'late' ? 'text-amber-700' : 'text-slate-900') : '' }}">{{ $rule->eventLabel() }}</div>
                                    <div class="mt-0.5 text-xs text-slate-500">ฝั่ง <b class="text-slate-700">{{ $rule->department?->name }}</b>{{ $rule->cabinetTemplate ? ' · '.$rule->cabinetTemplate->name : '' }}{{ $rule->repeat ? ' · เตือนซ้ำทุกวัน' : '' }}</div>
                                </td>
                                <td class="px-3 py-3 text-slate-700">{{ $rule->message }}</td>
                                <td class="px-3 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ((array) $rule->recipients as $r)
                                            <span class="rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{{ $whoLabel($r) }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs text-slate-500">{{ $rule->isDaily() ? 'รอบเช้า' : 'ทันที' }}</td>
                                <td class="whitespace-nowrap py-3 pl-3 pr-5 text-right">
                                    <button type="button" @click="openEdit({{ $rule->id }})" class="text-xs font-medium text-blue-600 hover:underline">แก้ไข</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-8 text-center text-sm text-slate-400">ยังไม่มีกฎ — ทุกแผนกแจ้งเตือนแบบเดิม · กด “เพิ่มกฎ” เพื่อเริ่ม</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        {{-- add / edit: one form, its address and method follow the rule being edited --}}
        <x-modal name="notice-rule" maxWidth="2xl" :show="$errors->any()">
            <form method="POST" :action="editing ? rules[editing].url : storeUrl" class="flex max-h-[90vh] flex-col">
                @csrf
                <template x-if="editing"><span><input type="hidden" name="_method" value="PUT"><input type="hidden" name="rule_id" :value="editing"></span></template>
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h3 class="text-base font-bold text-ae-navy" x-text="editing ? 'แก้ไขกฎแจ้งเตือน' : 'เพิ่มกฎแจ้งเตือน'"></h3>
                    <button type="button" @click="$dispatch('close-modal', 'notice-rule')" class="text-2xl leading-none text-slate-400 hover:text-slate-600" aria-label="ปิด">&times;</button>
                </div>

                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-5">
                    @if ($errors->any())
                        <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $errors->first() }}</p>
                    @endif

                    <fieldset>
                        <legend class="mb-2 text-sm font-bold text-slate-900">1. เมื่อ</legend>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                            @foreach ($events as $key => $label)
                                <label class="cursor-pointer rounded-lg border px-3 py-2.5 transition" :class="f.event === '{{ $key }}' ? 'border-[#1479D7] bg-[#EAF4FF] ring-1 ring-[#1479D7]' : 'border-slate-200 hover:border-slate-300'">
                                    <input type="radio" name="event" value="{{ $key }}" x-model="f.event" class="sr-only">
                                    <span class="block text-sm font-semibold text-slate-900">{{ $label }}</span>
                                    <span class="block text-xs text-slate-500">{{ $eventHints[$key] }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div x-show="f.event === 'before' || f.event === 'late'" class="mt-2 flex flex-wrap items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
                            <label for="rule-days" x-text="f.event === 'late' ? 'เมื่อเลยวันที่ลงไว้' : 'ก่อนถึงวันที่ลงไว้'"></label>
                            <input id="rule-days" type="number" name="days" min="1" max="60" x-model="f.days" class="w-20 rounded-lg border-slate-300 py-1 text-sm" :disabled="!(f.event === 'before' || f.event === 'late')">
                            <span>วันทำงาน</span>
                            <label x-show="f.event === 'late'" class="ml-2 inline-flex items-center gap-1.5"><input type="checkbox" name="repeat" value="1" x-model="f.repeat" class="rounded border-slate-300 text-blue-600"> เตือนซ้ำทุกวันจนกว่าจะรับ</label>
                        </div>
                    </fieldset>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <label class="block text-sm font-bold text-slate-900">2. งานของแผนก
                            <select name="department_id" x-model="f.department_id" required class="mt-1 block w-full rounded-lg border-slate-300 text-sm font-normal">
                                <option value="">— เลือกแผนก —</option>
                                @foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-bold text-slate-900">ใช้กับเทมเพลต
                            <select name="cabinet_template_id" x-model="f.cabinet_template_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm font-normal">
                                <option value="">ทุกเทมเพลต</option>
                                @foreach ($templates as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                            </select>
                        </label>
                    </div>

                    <div>
                        <label for="rule-msg" class="text-sm font-bold text-slate-900">3. ข้อความ</label>
                        <input id="rule-msg" type="text" name="message" x-model="f.message" x-ref="msg" maxlength="255" required placeholder="เช่น มีของแล้ว นำไปประกอบได้ — {รายการ}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                            <span>แทรก:</span>
                            @foreach (\App\Models\ChecklistNoticeRule::VARS as $v)
                                <button type="button" @click="insert('{{ $v }}')" class="rounded-md border border-blue-200 bg-[#EAF4FF] px-2 py-0.5 text-[#0F4C81] hover:bg-blue-100">{{ $v }}</button>
                            @endforeach
                        </div>
                    </div>

                    <fieldset>
                        <legend class="mb-2 text-sm font-bold text-slate-900">4. ส่งถึง <span class="font-normal text-slate-500">(เลือกได้หลายอย่าง)</span></legend>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                            @foreach ([['linked', 'แผนกที่เทียบด้วย', 'ทุกคนในแผนกฝั่งที่ใช้รายการร่วมกัน'], ['owner', 'ผู้รับผิดชอบงานฝั่งที่เทียบ', 'ผู้ทำของ Sub Task ที่เทียบกัน'], ['pm', 'PM ของโครงการ', 'Project Manager']] as [$v, $label, $hint])
                                <label class="flex cursor-pointer items-start gap-2 rounded-lg border px-3 py-2" :class="f.recipients.includes('{{ $v }}') ? 'border-[#1479D7] bg-[#EAF4FF]' : 'border-slate-200'">
                                    <input type="checkbox" name="recipients[]" value="{{ $v }}" x-model="f.recipients" class="mt-0.5 rounded border-slate-300 text-blue-600">
                                    <span><span class="block text-sm font-semibold text-slate-900">{{ $label }}</span><span class="block text-xs text-slate-500">{{ $hint }}</span></span>
                                </label>
                            @endforeach
                        </div>
                        <p class="mb-1 mt-3 text-xs font-semibold text-slate-500">แผนก (ทุกคนในแผนก)</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($departments as $d)
                                <label class="cursor-pointer rounded-full border px-3 py-1 text-sm" :class="f.recipients.includes('dept:{{ $d->id }}') ? 'border-[#1479D7] bg-[#EAF4FF] text-[#0F4C81]' : 'border-slate-200 text-slate-600'">
                                    <input type="checkbox" name="recipients[]" value="dept:{{ $d->id }}" x-model="f.recipients" class="sr-only">{{ $d->name }}
                                </label>
                            @endforeach
                        </div>
                        <details class="mt-3" :open="f.recipients.some((r) => r.startsWith('user:'))">
                            <summary class="cursor-pointer text-xs font-semibold text-slate-500">ระบุชื่อคน <span class="font-normal" x-text="countUsers() ? '(' + countUsers() + ' คน)' : ''"></span></summary>
                            <div class="mt-2 grid max-h-44 grid-cols-1 gap-1 overflow-y-auto rounded-lg border border-slate-200 p-2 sm:grid-cols-2">
                                @foreach ($users as $u)
                                    <label class="flex items-center gap-2 rounded px-1.5 py-1 text-sm hover:bg-slate-50">
                                        <input type="checkbox" name="recipients[]" value="user:{{ $u->id }}" x-model="f.recipients" class="rounded border-slate-300 text-blue-600">
                                        <span>{{ $u->name }}</span><span class="text-xs text-slate-400">{{ $deptNames[$u->department_id] ?? '' }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </details>
                    </fieldset>

                    <div class="rounded-lg border border-slate-100 bg-slate-50 p-3">
                        <p class="mb-2 text-xs font-bold text-slate-500">ตัวอย่างที่ผู้รับจะเห็น · <span class="font-normal" x-text="f.event === 'before' || f.event === 'late' ? 'ส่งรอบเช้า' : 'ส่งทันที'"></span></p>
                        <div class="flex gap-3 rounded-lg border border-slate-100 bg-white p-3">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#EAF4FF] text-[#0F4C81]">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                            </span>
                            <div class="min-w-0 text-sm">
                                <p class="font-semibold text-slate-900">SO-ตัวอย่าง · โครงการตัวอย่าง</p>
                                <p class="text-slate-700" x-text="preview()"></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 border-t border-slate-100 px-6 py-4">
                    <label class="mr-auto inline-flex items-center gap-2 text-sm text-slate-700">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" x-model="f.is_active" class="rounded border-slate-300 text-blue-600"> เปิดใช้กฎนี้
                    </label>
                    <template x-if="editing">
                        <span class="flex gap-2">
                            <button type="submit" :form="'rule-test-' + editing" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">ลองส่งหาฉัน</button>
                            <button type="submit" :form="'rule-delete-' + editing" class="px-3 py-2 text-sm font-medium text-red-600 hover:underline">ลบ</button>
                        </span>
                    </template>
                    <button type="button" @click="$dispatch('close-modal', 'notice-rule')" class="px-3 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                    <button class="rounded-lg bg-[#0F4C81] px-4 py-2 text-sm font-semibold text-white hover:bg-ae-navy">บันทึกกฎ</button>
                </div>
            </form>
        </x-modal>

        {{-- the dialog's "ลองส่งหาฉัน" / "ลบ" submit these (a form cannot sit inside another) --}}
        @foreach ($rules as $rule)
            <form id="rule-test-{{ $rule->id }}" method="POST" action="{{ route('settings.notice-rules.test', $rule) }}" class="hidden">@csrf</form>
            <form id="rule-delete-{{ $rule->id }}" method="POST" action="{{ route('settings.notice-rules.destroy', $rule) }}" onsubmit="return confirm('ลบกฎนี้?')" class="hidden">@csrf @method('DELETE')</form>
        @endforeach
        </div>
    </div>

    <script>
        function noticeRules({ rules, storeUrl, depts, old }) {
            const blank = () => ({ event: 'tick', department_id: '', cabinet_template_id: '', days: 2, repeat: false, message: '', recipients: ['linked'], is_active: true });
            return {
                rules, storeUrl, editing: old && old.rule_id && rules[old.rule_id] ? Number(old.rule_id) : null,
                // a form sent back with an error opens again as it was typed
                f: old ? Object.assign(blank(), old, { recipients: old.recipients || [], repeat: old.repeat === '1', is_active: old.is_active !== '0' }) : blank(),
                openNew() { this.editing = null; this.f = blank(); this.$dispatch('open-modal', 'notice-rule'); },
                openEdit(id) {
                    const r = this.rules[id];
                    this.editing = id;
                    this.f = { event: r.event, department_id: r.department_id, cabinet_template_id: r.cabinet_template_id, days: r.days, repeat: r.repeat, message: r.message, recipients: [...r.recipients], is_active: r.is_active };
                    this.$dispatch('open-modal', 'notice-rule');
                },
                insert(v) {
                    const el = this.$refs.msg, at = el.selectionStart ?? this.f.message.length;
                    this.f.message = this.f.message.slice(0, at) + v + this.f.message.slice(at);
                    this.$nextTick(() => { el.focus(); el.setSelectionRange(at + v.length, at + v.length); });
                },
                countUsers() { return this.f.recipients.filter((r) => r.startsWith('user:')).length; },
                preview() {
                    const dept = depts[this.f.department_id] || 'แผนก';
                    const n = this.f.event === 'before' || this.f.event === 'late' ? String(this.f.days || 1) : '1';
                    const text = (this.f.message || '…').split('{รายการ}').join('"Breaker 100A"').split('{ตู้}').join('WH/MO/00001')
                        .split('{วันที่}').join('15/11/2569').split('{n}').join(n).split('{โครงการ}').join('SO-ตัวอย่าง').split('{แผนก}').join(dept);
                    return dept + ': ' + text;
                },
            };
        }
    </script>
</x-app-layout>
