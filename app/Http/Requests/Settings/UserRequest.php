<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** Settings > ผู้ใช้งาน: adding a user (store: a password is required) and editing one (update: blank keeps it). */
class UserRequest extends FormRequest
{
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            // Plain username for now, not a real email address (2026-09-30).
            'email' => ['required', 'string', 'max:255', 'unique:users,email'.($user ? ','.$user->id : '')],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => ['required', 'in:'.implode(',', array_keys(User::roleLabels()))],
            'department_id' => ['nullable', 'exists:departments,id'],
            // "ดูแลแผนกเพิ่มเติม": departments they work for on top of their own (User::departmentIds)
            'extra_department_ids' => ['nullable', 'array'],
            'extra_department_ids.*' => ['integer', 'exists:departments,id'],
            'position' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
