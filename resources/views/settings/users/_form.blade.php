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
    <div>
        <x-input-label value="Position" />
        <x-text-input name="position" class="mt-1 block w-full" :value="old('position', $u?->position)" />
    </div>
    <label class="flex items-center gap-2 text-sm text-slate-600">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $u?->is_active ?? true)) class="rounded border-slate-300 text-blue-600">
        Active
    </label>
</div>
