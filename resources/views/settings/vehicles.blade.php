{{-- Settings > รถขนส่ง (VehicleController, Access 'edit.delivery'): the company's vehicles, offered in หน้าโครงการ › การจัดส่ง and
     งานจัดส่ง, and the reasons "ไม่รับงาน" offers --}}
@php
    $field = 'rounded-lg border-slate-300 text-sm';
@endphp
<x-app-layout>
    <x-slot name="header"><h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2></x-slot>
    <div class="max-w-none mx-auto space-y-4">
        <x-settings-nav />
        <datalist id="vehicle-types">@foreach ($types as $t)<option value="{{ $t }}"></option>@endforeach</datalist>
        <x-card title="รถของบริษัท" subtitle="PM เลือกแค่ &quot;ประเภท&quot; ตอนนัดส่ง (เลือกได้จากประเภทของรถที่ใช้งานอยู่) แผนกจัดส่งเลือกคันจริงตอนเลือกวันส่ง · &quot;รับได้ประมาณ&quot; ใช้เตือนเมื่อส่งตู้เกินจำนวนนี้ (ตู้ขนาดไม่เท่ากัน จึงแค่เตือน) · รถที่มีในประวัติการส่งแล้วลบไม่ได้ จะถูกปิดไว้แทน">
            <div class="divide-y divide-slate-100">
                @forelse ($vehicles as $v)
                    <div class="flex flex-wrap items-end gap-2 py-3 {{ $v->is_active ? '' : 'opacity-60' }}">
                        <form method="POST" action="{{ route('settings.vehicles.update', $v) }}" class="flex min-w-0 flex-1 flex-wrap items-end gap-2">@csrf @method('PUT')
                            <label class="flex flex-col text-xs font-semibold text-slate-600">ชื่อเรียก *
                                <input type="text" name="name" value="{{ $v->name }}" required maxlength="100" class="{{ $field }} mt-1 w-40 font-normal"></label>
                            <label class="flex flex-col text-xs font-semibold text-slate-600">ประเภท
                                <input type="text" name="type" value="{{ $v->type }}" maxlength="50" list="vehicle-types" placeholder="เช่น 6 ล้อเครน" class="{{ $field }} mt-1 w-36 font-normal"></label>
                            <label class="flex flex-col text-xs font-semibold text-slate-600">ทะเบียน
                                <input type="text" name="plate" value="{{ $v->plate }}" maxlength="30" class="{{ $field }} mt-1 w-28 font-normal"></label>
                            <label class="flex flex-col text-xs font-semibold text-slate-600">รับได้ประมาณ (ตู้)
                                <input type="number" name="capacity" value="{{ $v->capacity }}" min="1" max="999" class="{{ $field }} mt-1 w-24 font-normal tabular-nums"></label>
                            <label class="flex flex-col text-xs font-semibold text-slate-600">อุปกรณ์
                                <input type="text" name="equipment" value="{{ $v->equipment }}" maxlength="100" placeholder="เช่น เครน 3 ตัน" class="{{ $field }} mt-1 w-36 font-normal"></label>
                            <label class="flex flex-col text-xs font-semibold text-slate-600">คนขับประจำ
                                <input type="text" name="driver_name" value="{{ $v->driver_name }}" maxlength="100" class="{{ $field }} mt-1 w-32 font-normal"></label>
                            <label class="flex flex-col text-xs font-semibold text-slate-600">เบอร์คนขับ
                                <input type="text" name="driver_phone" value="{{ $v->driver_phone }}" maxlength="30" class="{{ $field }} mt-1 w-32 font-normal tabular-nums"></label>
                            <label class="inline-flex h-[38px] items-center gap-1.5 text-sm text-slate-700"><input type="checkbox" name="is_active" value="1" @checked($v->is_active) class="rounded border-slate-300"> ใช้งาน</label>
                            <span class="inline-flex h-[38px] items-center text-xs text-slate-500">ส่งไป {{ $used[$v->id] ?? 0 }} รอบ</span>
                            <button class="h-[38px] rounded-lg border border-slate-300 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50">บันทึก</button>
                        </form>
                        <form method="POST" action="{{ route('settings.vehicles.destroy', $v) }}" onsubmit="return confirm('ลบรถ &quot;{{ $v->label() }}&quot;?')">@csrf @method('DELETE')
                            <button class="h-[38px] text-xs text-red-600 hover:underline">ลบ</button>
                        </form>
                    </div>
                @empty
                    <p class="py-2 text-sm text-slate-500">ยังไม่มีรถ — เพิ่มด้านล่าง</p>
                @endforelse
            </div>
        </x-card>
        <x-card title="เพิ่มรถ">
            <form method="POST" action="{{ route('settings.vehicles.store') }}" class="flex flex-wrap items-end gap-2">@csrf
                <label class="flex flex-col text-xs font-semibold text-slate-600">ชื่อเรียก *
                    <input type="text" name="name" required maxlength="100" placeholder="เช่น รถเครนคันใหญ่" class="{{ $field }} mt-1 w-40 font-normal"></label>
                <label class="flex flex-col text-xs font-semibold text-slate-600">ประเภท
                    <input type="text" name="type" maxlength="50" list="vehicle-types" placeholder="เช่น 6 ล้อเครน" class="{{ $field }} mt-1 w-36 font-normal"></label>
                <label class="flex flex-col text-xs font-semibold text-slate-600">ทะเบียน
                    <input type="text" name="plate" maxlength="30" placeholder="3กข-1234" class="{{ $field }} mt-1 w-28 font-normal"></label>
                <label class="flex flex-col text-xs font-semibold text-slate-600">รับได้ประมาณ (ตู้)
                    <input type="number" name="capacity" min="1" max="999" class="{{ $field }} mt-1 w-24 font-normal tabular-nums"></label>
                <label class="flex flex-col text-xs font-semibold text-slate-600">อุปกรณ์
                    <input type="text" name="equipment" maxlength="100" placeholder="เช่น เครน 3 ตัน" class="{{ $field }} mt-1 w-36 font-normal"></label>
                <label class="flex flex-col text-xs font-semibold text-slate-600">คนขับประจำ
                    <input type="text" name="driver_name" maxlength="100" class="{{ $field }} mt-1 w-32 font-normal"></label>
                <label class="flex flex-col text-xs font-semibold text-slate-600">เบอร์คนขับ
                    <input type="text" name="driver_phone" maxlength="30" class="{{ $field }} mt-1 w-32 font-normal tabular-nums"></label>
                <button class="h-[38px] rounded-lg bg-slate-800 px-4 text-sm font-semibold text-white hover:bg-slate-700">+ เพิ่มรถ</button>
            </form>
        </x-card>
        <x-card title="เหตุผลไม่รับงาน" subtitle="ให้แผนกจัดส่งเลือกตอนกด &quot;ไม่รับงาน&quot; ในหน้างานจัดส่ง · บรรทัดละ 1 เหตุผล · มีตัวเลือก &quot;อื่น ๆ&quot; ให้พิมพ์เองเสมอ">
            <form method="POST" action="{{ route('settings.vehicles.reasons') }}" class="space-y-2">@csrf @method('PUT')
                <textarea name="reasons" rows="5" maxlength="2000" class="{{ $field }} block w-full max-w-md">{{ implode("\n", $reasons) }}</textarea>
                <button class="h-[38px] rounded-lg border border-slate-300 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50">บันทึก</button>
            </form>
        </x-card>
    </div>
</x-app-layout>
