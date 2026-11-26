<x-app-layout>
    <x-slot name="header"><h1 class="text-lg font-semibold text-slate-900">ตั้งค่า</h1></x-slot>
    <x-settings-layout space="space-y-4">
        <x-card title="แท็ก (ป้ายกำกับ)" subtitle="ติดแท็กให้โครงการได้ในหน้าต่างโครงการบน Planning ทุกคนที่แก้โครงการได้ติด/ถอดแท็กได้ — สร้างและลบแท็กทำได้เฉพาะ Admin/PM">
            <div class="space-y-2">
                @forelse ($labels as $l)
                    <div class="flex flex-wrap items-center gap-2">
                        <form method="POST" action="{{ route('settings.labels.update', $l) }}" class="flex min-w-0 flex-1 basis-72 items-center gap-2">@csrf @method('PUT')
                            <label class="sr-only" for="label-color-{{ $l->id }}">สีของแท็ก {{ $l->name }}</label>
                            <input id="label-color-{{ $l->id }}" type="color" name="color" value="{{ $l->color }}" class="h-10 w-12 shrink-0 rounded border-slate-300">
                            <label class="sr-only" for="label-name-{{ $l->id }}">ชื่อแท็ก</label>
                            <input id="label-name-{{ $l->id }}" type="text" name="name" value="{{ $l->name }}" maxlength="40" required class="h-10 min-w-0 flex-1 rounded-lg border-slate-300 text-sm">
                            <span class="shrink-0 text-sm text-slate-600">{{ $l->projects_count }} โครงการ</span>
                            <button class="h-10 shrink-0 rounded-lg border border-slate-300 px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">บันทึก</button>
                        </form>
                        <form method="POST" action="{{ route('settings.labels.destroy', $l) }}" data-confirm="ลบแท็ก {{ $l->name }}? จะถูกถอดออกจากทุกโครงการ" onsubmit="return confirm(this.dataset.confirm)">@csrf @method('DELETE')
                            <button class="h-10 rounded-lg px-3 text-sm font-semibold text-red-700 hover:bg-red-50" aria-label="ลบแท็ก {{ $l->name }}">ลบ</button>
                        </form>
                    </div>
                @empty
                    <p class="text-sm text-slate-400">ยังไม่มีแท็ก</p>
                @endforelse
            </div>
        </x-card>
        <x-card title="เพิ่มแท็ก">
            <form method="POST" action="{{ route('settings.labels.store') }}" class="flex gap-2">@csrf
                <input type="color" name="color" value="#7b68ee" class="h-9 w-12 rounded border-slate-300">
                <input type="text" name="name" required maxlength="40" placeholder="ชื่อแท็ก เช่น ด่วนลูกค้า" class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm">
                <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">+ เพิ่ม</button>
            </form>
        </x-card>
    </x-settings-layout>
</x-app-layout>
