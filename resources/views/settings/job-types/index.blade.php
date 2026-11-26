<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-slate-900">ตั้งค่า</h1>
    </x-slot>

    <x-settings-layout space="space-y-4">
        <x-card title="ประเภทงาน">
            <x-slot name="actions">
                <button type="button" x-data @click="$dispatch('open-modal', 'add-job-type')" class="inline-flex h-10 items-center rounded-lg px-3 text-sm font-semibold text-blue-700 hover:bg-blue-50">+ เพิ่มประเภทงาน</button>
            </x-slot>

            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @foreach ($jobTypes as $jobType)
                    <div class="px-5 py-3 flex items-center justify-between">
                        <div>
                            <p class="font-medium text-slate-800">{{ $jobType->name }}</p>
                            @if ($jobType->description)
                                <p class="text-sm text-slate-600">{{ $jobType->description }}</p>
                            @endif
                            <p class="mt-0.5 text-sm text-slate-600">{{ $jobType->projects_count }} โครงการ</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <button type="button" x-data @click="$dispatch('open-modal', 'edit-job-type-{{ $jobType->id }}')" class="inline-flex h-10 items-center rounded-lg px-3 text-sm font-semibold text-blue-700 hover:bg-blue-50" aria-label="แก้ไขประเภทงาน {{ $jobType->name }}">แก้ไข</button>
                            <form method="POST" action="{{ route('settings.job-types.destroy', $jobType) }}" onsubmit="return confirm('ลบประเภทงานนี้?')">
                                @csrf @method('DELETE')
                                <button class="inline-flex h-10 items-center rounded-lg px-3 text-sm font-semibold text-red-700 hover:bg-red-50" aria-label="ลบประเภทงาน {{ $jobType->name }}">ลบ</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
    </x-settings-layout>

    <x-modal name="add-job-type">
        <form method="POST" action="{{ route('settings.job-types.store') }}" class="p-6">
            @csrf
            <h3 class="text-base font-semibold text-slate-900 mb-4">เพิ่มประเภทงาน</h3>
            <div class="space-y-4">
                <div>
                    <x-input-label value="ชื่อประเภทงาน" />
                    <x-text-input name="name" class="mt-1 block w-full" required />
                </div>
                <div>
                    <x-input-label value="คำอธิบาย (ไม่บังคับ)" />
                    <textarea name="description" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm"></textarea>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" x-data @click="$dispatch('close-modal', 'add-job-type')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">เพิ่ม</button>
            </div>
        </form>
    </x-modal>

    @foreach ($jobTypes as $jobType)
        <x-modal :name="'edit-job-type-'.$jobType->id">
            <form method="POST" action="{{ route('settings.job-types.update', $jobType) }}" class="p-6">
                @csrf @method('PUT')
                <h3 class="text-base font-semibold text-slate-900 mb-4">แก้ไขประเภทงาน</h3>
                <div class="space-y-4">
                    <div>
                        <x-input-label value="ชื่อประเภทงาน" />
                        <x-text-input name="name" class="mt-1 block w-full" :value="$jobType->name" required />
                    </div>
                    <div>
                        <x-input-label value="คำอธิบาย (ไม่บังคับ)" />
                        <textarea name="description" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ $jobType->description }}</textarea>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="is_active" value="1" @checked($jobType->is_active) class="rounded border-slate-300 text-blue-600">
                        Active
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" x-data @click="$dispatch('close-modal', 'edit-job-type-{{ $jobType->id }}')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">บันทึก</button>
                </div>
            </form>
        </x-modal>
    @endforeach
</x-app-layout>
