<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2>
    </x-slot>

    <div class="max-w-3xl mx-auto">
        <x-settings-nav />

        <x-card title="การแจ้งเตือนของฉัน" subtitle="ตั้งได้เฉพาะของตัวเอง — แจ้งเตือนเร่งด่วน (มอบหมายงาน, แท็ก @, แจ้งเตือนเข้าแผนก) จะส่งถึงคุณเสมอตราบใดที่เปิดการแจ้งเตือนอยู่">
            <form method="POST" action="{{ route('settings.notifications.update') }}" class="space-y-4">
                @csrf @method('PUT')

                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" name="enabled" value="1" @checked($pref->enabled) class="mt-0.5 rounded border-slate-300 text-blue-600">
                    <span><span class="font-semibold text-slate-800">เปิดการแจ้งเตือน</span><br><span class="text-xs text-slate-500">ปิด = ไม่มีการแจ้งเตือนใดเข้ากระดิ่งของคุณเลย</span></span>
                </label>

                <fieldset class="space-y-3 border-t border-slate-100 pt-4">
                    <legend class="text-xs font-semibold uppercase tracking-wide text-slate-400 pb-1">แจ้งเตือนเพิ่มเมื่อ…</legend>
                    @foreach ([
                        ['progress', 'แผนกรับงาน / เริ่มงาน', 'เมื่อแผนกกดรับงานหรือเริ่มงานในโครงการที่คุณดูแล'],
                        ['completed', 'แผนกทำงานเสร็จ', 'เมื่อแผนกกดปิดงาน'],
                        ['reminders', 'งานใกล้ครบกำหนด / เกินกำหนด', 'การเตือนรายวัน'],
                    ] as [$name, $title, $hint])
                        <label class="flex items-start gap-3 text-sm">
                            <input type="checkbox" name="{{ $name }}" value="1" @checked($pref->{$name}) class="mt-0.5 rounded border-slate-300 text-blue-600">
                            <span><span class="font-semibold text-slate-800">{{ $title }}</span><br><span class="text-xs text-slate-500">{{ $hint }}</span></span>
                        </label>
                    @endforeach
                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="only_my_department" value="1" @checked($pref->only_my_department) class="mt-0.5 rounded border-slate-300 text-blue-600">
                        <span><span class="font-semibold text-slate-800">เฉพาะงานของแผนกฉัน</span><br><span class="text-xs text-slate-500">ข้ามข่าวความคืบหน้าและการเตือนของแผนกอื่น (ใช้กับผู้ที่มีแผนกเท่านั้น)</span></span>
                    </label>
                </fieldset>

                <div class="flex justify-end pt-2">
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">บันทึก</button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
