<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-slate-900">ตั้งค่า</h1>
    </x-slot>

    <x-settings-layout space="space-y-4">
        {{-- the list filters as you type (name, code, description, team): every department is on this one page --}}
        <div x-data="{ q: '', hit(el) { const q = this.q.trim().toLowerCase(); return !q || el.dataset.find.toLowerCase().includes(q); },
                       shown() { return [...this.$root.querySelectorAll('[data-find]')].filter(el => this.hit(el)).length; } }"
             class="space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-0 flex-1 basis-64 sm:max-w-sm">
                    <label for="department-search" class="sr-only">ค้นหาแผนก</label>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
                    <input id="department-search" type="search" x-model="q" autocomplete="off" @keydown.escape="q = ''"
                           placeholder="ค้นหาชื่อแผนก / รหัส / ทีม" class="h-11 w-full rounded-lg border-slate-300 pl-9 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <p class="text-sm text-slate-600" role="status">
                    <span x-show="!q.trim()">ทั้งหมด {{ $departments->count() }} แผนก</span>
                    <span x-show="q.trim()" x-cloak>พบ <span x-text="shown()"></span> แผนก</span>
                </p>
                <button type="button" @click="$dispatch('open-modal', 'add-department')"
                        class="ml-auto inline-flex h-11 items-center gap-2 rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
                    เพิ่มแผนก
                </button>
            </div>

        <x-card class="overflow-hidden">
            <div class="-m-5 divide-y divide-slate-100">
                <p x-show="q.trim() && !shown()" x-cloak class="px-5 py-10 text-center text-sm text-slate-600">ไม่พบแผนกที่ตรงกับ “<span x-text="q.trim()"></span>”</p>
                @foreach ($departments as $department)
                    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 px-5 py-4"
                         data-find="{{ $department->name }} {{ $department->code }} {{ $department->description }} {{ $department->teams->pluck('name')->implode(' ') }}" x-show="hit($el)">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-slate-800">{{ $department->name }} <span class="text-xs font-medium text-slate-500">{{ $department->code }}</span></p>
                            @if ($department->description)
                                <p class="text-sm text-slate-600">{{ $department->description }}</p>
                            @endif
                            @if ($department->users_count)
                                <p class="mt-0.5 text-sm text-slate-600">{{ $department->users_count }} ผู้ใช้งาน</p>
                            @else
                                <p class="mt-0.5 inline-block rounded bg-amber-100 px-1.5 text-xs font-medium text-amber-800" title="งานและแจ้งเตือนที่ส่งมาแผนกนี้จะไม่มีใครเห็น">ยังไม่มีผู้ใช้ — ไม่มีใครได้รับงาน/แจ้งเตือน</p>
                            @endif
                            {{-- ทีม: only a department that splits its people (the sales department) needs any --}}
                            <div class="mt-2 flex flex-wrap items-center gap-1.5" x-data="{ adding: false }">
                                @foreach ($department->teams as $team)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 py-0.5 pl-3 pr-0.5 text-sm font-medium text-slate-700">
                                        {{ $team->name }} <span class="text-slate-500">· {{ $team->users_count }} คน</span>
                                        <button type="button" @click="$dispatch('open-modal', 'edit-team-{{ $team->id }}')" class="inline-flex h-8 items-center rounded-full px-2.5 text-blue-700 hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500" aria-label="แก้ไขทีม {{ $team->name }}">แก้ไข</button>
                                    </span>
                                @endforeach
                                <button type="button" x-show="!adding" @click="adding = true; $nextTick(() => $refs.name.focus())" class="inline-flex h-9 items-center rounded-lg px-2 text-sm font-semibold text-blue-700 hover:bg-blue-50">+ เพิ่มทีม</button>
                                <form x-show="adding" x-cloak method="POST" action="{{ route('settings.departments.teams.store', $department) }}" class="flex flex-wrap items-center gap-1.5" @keydown.escape="adding = false">
                                    @csrf
                                    <label class="sr-only" for="team-new-{{ $department->id }}">ชื่อทีมใหม่ของ {{ $department->name }}</label>
                                    <input id="team-new-{{ $department->id }}" x-ref="name" name="name" maxlength="100" required placeholder="ชื่อทีม เช่น ทีม A" class="h-10 w-44 rounded-lg border-slate-300 text-sm">
                                    <button class="h-10 rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700">เพิ่ม</button>
                                    <button type="button" @click="adding = false" class="h-10 px-2 text-sm text-slate-600">ยกเลิก</button>
                                </form>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <button type="button" @click="$dispatch('open-modal', 'edit-department-{{ $department->id }}')" class="inline-flex h-10 items-center rounded-lg px-3 text-sm font-semibold text-blue-700 hover:bg-blue-50" aria-label="แก้ไขแผนก {{ $department->name }}">แก้ไข</button>
                            <form method="POST" action="{{ route('settings.departments.destroy', $department) }}" data-confirm="ลบแผนก {{ $department->name }}?" onsubmit="return confirm(this.dataset.confirm)">
                                @csrf @method('DELETE')
                                <button class="inline-flex h-10 items-center rounded-lg px-3 text-sm font-semibold text-red-700 hover:bg-red-50" aria-label="ลบแผนก {{ $department->name }}">ลบ</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
        </div>
    </x-settings-layout>

    <x-modal name="add-department">
        <form method="POST" action="{{ route('settings.departments.store') }}" class="p-6">
            @csrf
            <h3 class="text-base font-semibold text-slate-900 mb-4">เพิ่มแผนก</h3>
            <div class="space-y-4">
                <div>
                    <x-input-label value="ชื่อแผนก" />
                    <x-text-input name="name" class="mt-1 block w-full" required />
                </div>
                <div>
                    <x-input-label value="รหัสแผนก (ภาษาอังกฤษ สั้น ๆ เช่น QC)" />
                    <x-text-input name="code" class="mt-1 block w-full" required />
                </div>
                <div>
                    <x-input-label value="คำอธิบาย (ไม่บังคับ)" />
                    <textarea name="description" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm"></textarea>
                </div>
                <div class="flex gap-4">
                    <div>
                        <x-input-label value="สี" />
                        <input type="color" name="color" value="{{ '#7b68ee' }}" class="mt-1 h-9 w-16 rounded border-slate-300">
                    </div>
                    <div class="flex-1">
                        <x-input-label value="ไอคอน (อีโมจิ)" />
                        <x-text-input name="icon" maxlength="8" class="mt-1 block w-full" :value="''" />
                    </div>
                </div>
                <label class="flex items-start gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="sees_all" value="1"  class="mt-0.5 rounded border-slate-300 text-blue-600">
                    <span>เห็นงานของทุกแผนก (อ่านอย่างเดียว เหมือน PM มองเห็น แต่ไม่ได้สิทธิ์จ่ายงาน/ลบ) — ช่องเดียวกับ “เห็นทุกโครงการ” ใน <a href="{{ route('settings.permissions.edit') }}" class="text-blue-600 hover:underline">สิทธิ์การใช้งาน</a></span>
                </label>

            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" x-data @click="$dispatch('close-modal', 'add-department')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">เพิ่มแผนก</button>
            </div>
        </form>
    </x-modal>

    @foreach ($departments->flatMap->teams as $team)
        <x-modal :name="'edit-team-'.$team->id">
            <div class="p-6 space-y-4">
                <h3 class="text-base font-semibold text-slate-900">แก้ทีม</h3>
                <form method="POST" action="{{ route('settings.teams.update', $team) }}" class="space-y-4">
                    @csrf @method('PUT')
                    <div>
                        <x-input-label for="team-name-{{ $team->id }}" value="ชื่อทีม" />
                        <x-text-input id="team-name-{{ $team->id }}" name="name" maxlength="100" class="mt-1 block w-full" :value="$team->name" required />
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" x-data @click="$dispatch('close-modal', 'edit-team-{{ $team->id }}')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                        <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">บันทึก</button>
                    </div>
                </form>
                <form method="POST" action="{{ route('settings.teams.destroy', $team) }}" data-confirm="ลบทีม {{ $team->name }}? ผู้ใช้ในทีมจะไม่มีทีม (ไม่ถูกลบ)" onsubmit="return confirm(this.dataset.confirm)" class="border-t border-slate-100 pt-4">
                    @csrf @method('DELETE')
                    <button class="text-sm font-medium text-red-600 hover:underline">ลบทีมนี้</button>
                    <span class="text-xs text-slate-500">ผู้ใช้ {{ $team->users_count }} คนในทีมจะไม่มีทีม แต่ไม่ถูกลบ</span>
                </form>
            </div>
        </x-modal>
    @endforeach

    @foreach ($departments as $department)
        <x-modal :name="'edit-department-'.$department->id">
            <form method="POST" action="{{ route('settings.departments.update', $department) }}" class="p-6">
                @csrf @method('PUT')
                <h3 class="text-base font-semibold text-slate-900 mb-4">แก้ไขแผนก</h3>
                <div class="space-y-4">
                    <div>
                        <x-input-label value="ชื่อแผนก" />
                        <x-text-input name="name" class="mt-1 block w-full" :value="$department->name" required />
                    </div>
                    <div>
                        <x-input-label value="รหัสแผนก (ภาษาอังกฤษ สั้น ๆ เช่น QC)" />
                        <x-text-input name="code" class="mt-1 block w-full" :value="$department->code" required />
                    </div>
                    <div>
                        <x-input-label value="คำอธิบาย (ไม่บังคับ)" />
                        <textarea name="description" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ $department->description }}</textarea>
                    </div>
                <div class="flex gap-4">
                    <div>
                        <x-input-label value="สี" />
                        <input type="color" name="color" value="{{ $department->color ?: '#7b68ee' }}" class="mt-1 h-9 w-16 rounded border-slate-300">
                    </div>
                    <div class="flex-1">
                        <x-input-label value="ไอคอน (อีโมจิ)" />
                        <x-text-input name="icon" maxlength="8" class="mt-1 block w-full" :value="$department->icon" />
                    </div>
                </div>
                <div>
                    <x-input-label value="วันที่ที่แผนกนี้ลงในเช็คลิสต์ (ไม่บังคับ)" />
                    <x-text-input name="handoff_date_label" maxlength="40" placeholder="เช่น วันของเข้า" class="mt-1 block w-full" :value="$department->handoff_date_label" />
                    <p class="mt-1 text-xs text-slate-500">ใส่ชื่อแล้ว ทุกรายการในเช็คลิสต์ของแผนกนี้มีช่องวันที่นี้ · ถึงวันหรือเลยวันแล้วยังไม่ติ๊ก แผนกนี้ได้แจ้งเตือน (เลยวัน PM ได้ด้วย) · ถ้ามีงานแผนกอื่นเทียบด้วย ฝั่งที่เทียบได้แจ้งเตือนแทน</p>
                </div>
                <label class="flex items-start gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="sees_all" value="1" @checked($department->sees_all) class="mt-0.5 rounded border-slate-300 text-blue-600">
                    <span>เห็นงานของทุกแผนก (อ่านอย่างเดียว เหมือน PM มองเห็น แต่ไม่ได้สิทธิ์จ่ายงาน/ลบ) — ช่องเดียวกับ “เห็นทุกโครงการ” ใน <a href="{{ route('settings.permissions.edit') }}" class="text-blue-600 hover:underline">สิทธิ์การใช้งาน</a></span>
                </label>

                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="is_active" value="1" @checked($department->is_active) class="rounded border-slate-300 text-blue-600">
                        เปิดใช้งาน
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" x-data @click="$dispatch('close-modal', 'edit-department-{{ $department->id }}')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">บันทึก</button>
                </div>
            </form>
        </x-modal>
    @endforeach
</x-app-layout>
