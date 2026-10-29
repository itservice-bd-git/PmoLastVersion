@php $u = $user ?? null; @endphp

<div class="space-y-4">
    <div>
        <x-input-label value="Name" />
        <x-text-input name="name" class="mt-1 block w-full" :value="old('name', $u?->name)" required />
        <x-input-error :messages="$errors->get('name')" class="mt-1" />
    </div>
    <div>
        <x-input-label value="Username" />
        <x-text-input type="text" name="email" class="mt-1 block w-full" :value="old('email', $u?->email)" required />
        <x-input-error :messages="$errors->get('email')" class="mt-1" />
    </div>
    <div>
        <x-input-label :value="$u ? 'New Password (leave blank to keep current)' : 'Password'" />
        <x-text-input type="password" name="password" class="mt-1 block w-full" :required="!$u" />
        <x-input-error :messages="$errors->get('password')" class="mt-1" />
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
            <x-input-label value="Role" />
            <select name="role" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                @foreach ($roles as $key => $label)
                    <option value="{{ $key }}" @selected(old('role', $u?->role) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label value="Department" />
            <select name="department_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                <option value="">-</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected(old('department_id', $u?->department_id) == $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
        </div>
    </div>
    {{-- departments looked after on top of their own: they see and do that work as one of its members --}}
    @php $extra = collect(old('extra_department_ids', $u?->extraDepartments()->pluck('departments.id')->all() ?? []))->map(fn ($id) => (int) $id); @endphp
    <div>
        <x-input-label value="ดูแลแผนกเพิ่มเติม" />
        <p class="text-xs text-slate-500">เช่น หัวหน้าจัดซื้อที่ดูแลคลังด้วย — เห็นและทำงานของแผนกที่ติ๊กได้เหมือนคนในแผนกนั้น (รับงาน ติ๊ก Checklist ได้รับแจ้งเตือนงานเข้า) · สิทธิ์การใช้งานยังเป็นของแผนกหลัก</p>
        <div class="mt-1.5 flex flex-wrap gap-2">
            @foreach ($departments as $department)
                <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-slate-200 px-3 py-1 text-sm text-slate-700 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 has-[:checked]:text-blue-800">
                    <input type="checkbox" name="extra_department_ids[]" value="{{ $department->id }}" @checked($extra->contains($department->id)) class="rounded border-slate-300 text-blue-600">
                    {{ $department->name }}
                </label>
            @endforeach
        </div>
        <x-input-error :messages="$errors->get('extra_department_ids')" class="mt-1" />
    </div>
    <div>
        <x-input-label value="Position" />
        <x-text-input name="position" class="mt-1 block w-full" :value="old('position', $u?->position)" />
    </div>
    <label class="flex items-center gap-2 text-sm text-slate-600">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $u?->is_active ?? true)) class="rounded border-slate-300 text-blue-600">
        Active
    </label>
</div>
