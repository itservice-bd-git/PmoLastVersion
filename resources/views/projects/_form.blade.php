@php
    $p = $project ?? null;
@endphp

{{-- shared by projects/create and projects/edit. A new project has no status field (it starts as Draft); editing shows it.
     Sales Order / Project Owner are no longer asked for (their old values stay on the record). --}}
<div class="grid grid-cols-1 md:grid-cols-2 gap-5"
     x-data="{ value: @js((string) old('contract_value', $p?->contract_value !== null ? number_format((float) $p->contract_value, 2) : '')),
               margin: @js((string) old('margin', $p?->margin !== null ? number_format((float) $p->margin, 2) : '')),
               num(v) { const n = parseFloat(String(v || '').replace(/[\s,฿]|บาท/g, '')); return isNaN(n) ? null : n; },
               pct() { const v = this.num(this.value), m = this.num(this.margin); return v && m !== null ? (m / v * 100).toFixed(1) + ' %' : ''; },
               withVat() { const v = this.num(this.value); return v === null ? '' : (Math.round(v * {{ 1 + \App\Models\Project::VAT_RATE }} * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); } }">
    <div>
        <x-input-label for="project_no" value="เลขที่โครงการ" />
        <x-text-input id="project_no" name="project_no" class="mt-1 block w-full" :value="old('project_no', $p?->project_no)" required />
        <x-input-error :messages="$errors->get('project_no')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="project_name" value="ชื่อโครงการ" />
        <x-text-input id="project_name" name="project_name" class="mt-1 block w-full" :value="old('project_name', $p?->project_name)" required />
        <x-input-error :messages="$errors->get('project_name')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="customer_name" value="ลูกค้า" />
        <x-text-input id="customer_name" name="customer_name" class="mt-1 block w-full" :value="old('customer_name', $p?->customer_name)" required />
        <x-input-error :messages="$errors->get('customer_name')" class="mt-1" />
    </div>
    @if (auth()->user()->allows('data.contact'))   {{-- Settings > สิทธิ์การใช้งาน --}}
    <div>
        <x-input-label for="customer_phone" value="เบอร์ติดต่อ" />
        <x-text-input id="customer_phone" name="customer_phone" type="tel" class="mt-1 block w-full" :value="old('customer_phone', $p?->customer_phone)" maxlength="50" placeholder="เช่น 081-234-5678" />
        <x-input-error :messages="$errors->get('customer_phone')" class="mt-1" />
    </div>
    @endif
    <div>
        <x-input-label for="po_no" value="เลขที่ใบสั่งซื้อ (PO)" />
        <x-text-input id="po_no" name="po_no" class="mt-1 block w-full" :value="old('po_no', $p?->po_no)" />
    </div>
    {{-- พนักงานขาย: tick the sales people (several at once) - they follow the project and hear its news (ProjectFollowers);
         a project made before this keeps its typed names, and a name that is one of them comes ticked --}}
    @php
        $salesPeople ??= collect();
        $typed = collect(preg_split('/\s*,\s*/u', (string) $p?->sales_person))->filter();
        $salesTicked = collect(old('sales_ids', ($salesChosen ?? []) ?: $salesPeople->whereIn('name', $typed->all())->pluck('id')->all()))->map(fn ($id) => (int) $id);
        $salesOther = old('sales_person', \App\Services\ProjectFollowers::otherNames($p?->sales_person, $salesPeople->whereIn('id', $salesTicked->all())));
    @endphp
    <div class="md:col-span-2">
        <span class="block text-sm font-medium text-slate-700">พนักงานขาย <span class="font-normal text-slate-400">— เลือกได้หลายคน · คนที่เลือกจะติดตามโครงการนี้และได้รับแจ้งเตือนความเคลื่อนไหว</span></span>
        <div class="mt-1 flex flex-wrap gap-2 rounded-lg border border-slate-200 bg-white p-2">
            @forelse ($salesPeople as $person)
                <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-slate-200 px-3 py-1 text-sm text-slate-700 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 has-[:checked]:text-blue-800">
                    <input type="checkbox" name="sales_ids[]" value="{{ $person->id }}" @checked($salesTicked->contains($person->id)) class="rounded border-slate-300 text-blue-600">
                    {{ $person->name }}
                </label>
            @empty
                <span class="px-1 py-1 text-xs text-slate-400">ยังไม่มีผู้ใช้ในแผนกขาย (ตั้งค่า → ผู้ใช้งาน)</span>
            @endforelse
        </div>
        <x-text-input id="sales_person" name="sales_person" class="mt-2 block w-full" :value="$salesOther" placeholder="ชื่ออื่นที่ไม่มีบัญชีในระบบ (คั่นด้วย ,)" />
        <x-input-error :messages="$errors->get('sales_person')" class="mt-1" />
        <x-input-error :messages="$errors->get('sales_ids')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="project_manager_id" value="ผู้จัดการโครงการ (PM)" />
        <select id="project_manager_id" name="project_manager_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
            <option value="">-</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}" @selected(old('project_manager_id', $p?->project_manager_id) == $user->id)>{{ $user->name }}</option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('project_manager_id')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="job_type_id" value="ประเภทงาน" />
        <select id="job_type_id" name="job_type_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
            <option value="">-</option>
            @foreach ($jobTypes as $jobType)
                <option value="{{ $jobType->id }}" @selected(old('job_type_id', $p?->job_type_id) == $jobType->id)>{{ $jobType->name }}</option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('job_type_id')" class="mt-1" />
    </div>
    @if (($boards ?? collect())->isNotEmpty())
        <div>
            <x-input-label for="board_id" value="บอร์ด (Planning)" />
            <select id="board_id" name="board_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                <option value="">PMO (บอร์ดหลัก)</option>
                @foreach ($boards as $board)
                    <option value="{{ $board->id }}" @selected(old('board_id', $p?->board_id ?? request()->integer('board')) == $board->id)>{{ $board->name }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('board_id')" class="mt-1" />
        </div>
    @endif
    <div>
        <x-input-label for="payment_terms" value="เงื่อนไขการชำระ" />
        <x-text-input id="payment_terms" name="payment_terms" class="mt-1 block w-full" :value="old('payment_terms', $p?->payment_terms)" />
        <x-input-error :messages="$errors->get('payment_terms')" class="mt-1" />
    </div>
    {{-- once the project has a date it moves only through หน้าโครงการ › "เลื่อน / แก้วัน" (with a reason; App\Services\ProjectDates) --}}
    @foreach (['start_date' => 'วันเริ่ม', 'due_date' => 'วันครบกำหนด'] as $field => $label)
        <div>
            <x-input-label :for="$p?->{$field} ? null : $field" :value="$label" />
            @if ($p?->{$field})
                <p class="mt-1 flex h-[2.625rem] items-center justify-between gap-2 rounded-lg bg-slate-100 px-3 text-sm">
                    <span class="tabular-nums text-slate-800">{{ $p->{$field}->thaiDate() }}</span>
                    <a href="{{ route('projects.show', $p) }}#project-dates" class="text-xs font-semibold text-blue-700 hover:underline">เลื่อน / แก้วัน →</a>
                </p>
            @else
                <x-text-input type="date" :id="$field" :name="$field" class="mt-1 block w-full" :value="old($field)" />
            @endif
        </div>
    @endforeach
    @if ($p)
        <div>
            <x-input-label for="status" value="สถานะ" />
            <select id="status" name="status" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                @foreach ($statuses as $key => $label)
                    <option value="{{ $key }}" @selected(old('status', $p->status) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <div>
        <x-input-label for="priority" value="ความสำคัญ" />
        <select id="priority" name="priority" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
            @foreach ($priorities as $key => $label)
                <option value="{{ $key }}" @selected(old('priority', $p?->priority ?? 'normal') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    @if (auth()->user()->allows('data.finance'))
    <div>
        <x-input-label for="contract_value" value="มูลค่างาน ก่อน VAT (บาท)" />
        <x-text-input id="contract_value" name="contract_value" inputmode="decimal" class="mt-1 block w-full" x-model="value" placeholder="เช่น 1,250,000" />
        <x-input-error :messages="$errors->get('contract_value')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="contract_value_vat" value="มูลค่างานรวม VAT 7% (บาท)" />
        {{-- worked out from มูลค่างาน as it is typed; not sent / not stored --}}
        <input id="contract_value_vat" type="text" readonly tabindex="-1" :value="withVat()" placeholder="คำนวณจากมูลค่างานอัตโนมัติ"
               class="mt-1 block w-full rounded-md border-slate-200 bg-slate-50 text-slate-700 shadow-sm">
    </div>
    <div>
        <x-input-label for="margin" value="Margin (บาท)" />
        <x-text-input id="margin" name="margin" inputmode="decimal" class="mt-1 block w-full" x-model="margin" placeholder="เช่น 225,000" />
        <p class="mt-1 text-xs text-slate-500" x-show="pct()" x-text="'คิดเป็น ' + pct() + ' ของมูลค่างาน'"></p>
        <x-input-error :messages="$errors->get('margin')" class="mt-1" />
    </div>
    @endif
    {{-- a new project (Admin / PM): its FAT and its delivery, with the same fields as the project page's windows (_fat-form,
         _delivery-form) except the cabinets - there are none yet, so both are for every cabinet. FAT round 1 is saved as
         it is; delivery round 1 always as a draft, sent to the delivery department later from the project page, once its
         cabinets are there (ProjectController::storeAppointments). --}}
    @if (! $p && auth()->user()->canDispatchWork())
        @php
            $vehicleTypes = \App\Models\Vehicle::types();
            $box = 'mt-1 block h-11 w-full rounded-lg border-slate-300 text-sm font-normal';
        @endphp
        <div class="space-y-4 md:col-span-2"
             x-data="{ fat: @js((string) old('fat_date')), fatEnd: @js((string) old('fat_end_date')), fatTime: @js((string) old('fat_time')),
                       fatStatus: @js(old('fat_status', 'scheduled')), method: @js(old('delivery_method', 'company')), due: '' }"
             x-init="$nextTick(() => { const d = document.getElementById('due_date'); if (d) { due = d.value; d.addEventListener('change', () => { due = d.value; }); } })">
            {{-- วัน FAT: as the FAT window --}}
            <fieldset class="rounded-xl border border-slate-200 p-4">
                <legend class="px-1 text-sm font-semibold text-slate-800">วัน FAT <span class="font-normal text-slate-500">(ไม่บังคับ · ทุกตู้)</span></legend>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_7rem]">
                    <label class="block text-sm font-semibold text-slate-800">วันที่
                        <input type="date" name="fat_date" x-model="fat" class="{{ $box }}">
                    </label>
                    <label class="block text-sm font-semibold text-slate-800">ถึงวันที่ <span class="font-normal text-slate-500">(ถ้าหลายวัน)</span>
                        <input type="date" name="fat_end_date" x-model="fatEnd" :min="fat" class="{{ $box }}">
                    </label>
                    <label class="block text-sm font-semibold text-slate-800">เวลา
                        <input type="text" name="fat_time" x-model="fatTime" placeholder="เช่น 13:30" inputmode="numeric" maxlength="5" autocomplete="off"
                               x-init="window.flatpickr($el, { enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true, allowInput: true, defaultHour: 9, defaultDate: fatTime || null, onChange: (d, v) => { fatTime = v; } })"
                               class="{{ $box }} tabular-nums">
                    </label>
                </div>
                <p x-show="due && fat && (fatEnd || fat) > due && fatStatus !== 'passed'" class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" role="alert">
                    FAT หลังวันครบกำหนดของโครงการ · บันทึกได้ แต่ควรเลื่อนวันส่งมอบด้วย</p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="text-sm font-semibold text-slate-800">สถานะ</span>
                    @foreach (array_keys(\App\Models\ProjectFat::STATUS_LABELS) as $key)
                        <label class="inline-flex h-10 cursor-pointer items-center rounded-full border px-3.5 text-sm font-semibold"
                               :class="fatStatus === '{{ $key }}' ? 'border-slate-800 bg-slate-800 text-white' : 'border-slate-300 bg-white text-slate-700'">
                            <input type="radio" name="fat_status" value="{{ $key }}" x-model="fatStatus" class="sr-only">{{ \App\Support\EventStatuses::name('fat', $key) }}</label>
                    @endforeach
                </div>
                <label class="mt-3 block text-sm font-semibold text-slate-800">หมายเหตุ
                    <input type="text" name="fat_note" value="{{ old('fat_note') }}" maxlength="500" placeholder="เช่น ลูกค้า 3 คน" class="{{ $box }}">
                </label>
                @foreach (['fat_date', 'fat_end_date', 'fat_time', 'fat_status', 'fat_note'] as $k)
                    <x-input-error :messages="$errors->get($k)" class="mt-1" />
                @endforeach
            </fieldset>

            {{-- การจัดส่ง: as PM's delivery window; kept as a draft --}}
            <fieldset class="rounded-xl border border-slate-200 p-4">
                <legend class="px-1 text-sm font-semibold text-slate-800">การจัดส่ง <span class="font-normal text-slate-500">(ไม่บังคับ · ทุกตู้ · บันทึกเป็นร่าง)</span></legend>
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-slate-800">การขนส่ง</span>
                        @foreach (array_keys(\App\Models\ProjectDelivery::METHOD_LABELS) as $key)
                            <label class="inline-flex h-9 cursor-pointer items-center rounded-full border px-3.5 text-sm font-semibold"
                                   :class="method === '{{ $key }}' ? 'border-slate-800 bg-slate-800 text-white' : 'border-slate-300 bg-white text-slate-700'">
                                <input type="radio" name="delivery_method" value="{{ $key }}" x-model="method" class="sr-only">{{ \App\Models\ProjectDelivery::methodName($key) }}</label>
                        @endforeach
                    </div>
                    <div x-show="method === 'company'" class="grid grid-cols-[minmax(0,1fr)_7rem] gap-3">
                        <label class="block text-sm font-semibold text-slate-800">ประเภทรถ
                            <select name="delivery_vehicle_type" :disabled="method !== 'company' || {{ $vehicleTypes ? 'false' : 'true' }}" class="{{ $box }}">
                                <option value="">{{ $vehicleTypes ? 'เลือกประเภทรถ' : 'ยังไม่มีประเภทรถ (ตั้งค่า › รถขนส่ง)' }}</option>
                                @foreach ($vehicleTypes as $t)
                                    <option value="{{ $t }}" @selected(old('delivery_vehicle_type') === $t)>{{ $t }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-semibold text-slate-800">จำนวนคน
                            <input type="number" name="delivery_crew_size" value="{{ old('delivery_crew_size') }}" :disabled="method !== 'company'" min="1" max="20" inputmode="numeric" placeholder="เช่น 3" class="{{ $box }} tabular-nums">
                        </label>
                    </div>
                    <p x-show="method === 'company'" class="-mt-2 text-xs text-slate-500">แผนกจัดส่งเลือกรถคันจริงและคนที่ไปเอง · จำนวนคนรวมคนขับ</p>
                    <label x-show="method === 'external'" class="block text-sm font-semibold text-slate-800">บริษัทขนส่ง / ทะเบียน
                        <input type="text" name="delivery_carrier" value="{{ old('delivery_carrier') }}" :disabled="method !== 'external'" maxlength="150" placeholder="เช่น ไทยขนส่ง · 70-1234" class="{{ $box }}">
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-sm font-semibold text-slate-800">ส่งได้ตั้งแต่
                            <input type="date" name="delivery_from" value="{{ old('delivery_from') }}" class="{{ $box }}">
                        </label>
                        <label class="block text-sm font-semibold text-slate-800">ถึง
                            <input type="date" name="delivery_to" value="{{ old('delivery_to') }}" class="{{ $box }}">
                        </label>
                    </div>
                    <p class="-mt-2 text-xs text-slate-500">ใส่ช่วงวันที่ลูกค้ารับของได้ แผนกจัดส่งเลือกวันส่งจริงกับเวลาเอง</p>
                    <label class="block text-sm font-semibold text-slate-800">สถานที่ส่ง
                        <input type="text" name="delivery_site" value="{{ old('delivery_site') }}" maxlength="255" placeholder="เช่น โรงงานบางนา ประตู 2" class="{{ $box }}">
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-sm font-semibold text-slate-800">ผู้ติดต่อหน้างาน
                            <input type="text" name="delivery_contact_name" value="{{ old('delivery_contact_name') }}" maxlength="100" class="{{ $box }}">
                        </label>
                        <label class="block text-sm font-semibold text-slate-800">เบอร์โทร
                            <input type="text" name="delivery_contact_phone" value="{{ old('delivery_contact_phone') }}" maxlength="30" inputmode="tel" class="{{ $box }} tabular-nums">
                        </label>
                    </div>
                    <label class="block text-sm font-semibold text-slate-800">หมายเหตุถึงแผนกจัดส่ง / คนขับ
                        <input type="text" name="delivery_note" value="{{ old('delivery_note') }}" maxlength="500" placeholder="เช่น ต้องใช้เครน · เข้าได้หลัง 8 โมง" class="{{ $box }}">
                    </label>
                </div>
                @foreach (['delivery_from', 'delivery_to', 'delivery_method', 'delivery_vehicle_type', 'delivery_crew_size', 'delivery_carrier', 'delivery_site', 'delivery_contact_name', 'delivery_contact_phone', 'delivery_note'] as $k)
                    <x-input-error :messages="$errors->get($k)" class="mt-1" />
                @endforeach
                <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">บันทึกเป็น <b>ร่าง</b> รอบส่งที่ 1 (ทุกตู้) แผนกจัดส่งยังไม่เห็น · พอมีตู้แล้วกด "ตรวจแล้วส่งให้แผนกจัดส่ง" ที่หน้าโครงการ</p>
            </fieldset>
        </div>
    @endif
    <div class="md:col-span-2">
        <x-input-label for="description" value="คำอธิบาย" />
        <textarea id="description" name="description" rows="3" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ old('description', $p?->description) }}</textarea>
    </div>
</div>
