<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** Settings > แผนก: adding a department (store) and editing one (update: the department is in the route). */
class DepartmentRequest extends FormRequest
{
    public function rules(): array
    {
        $department = $this->route('department');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:departments,code'.($department ? ','.$department->id : '')],
            'description' => ['nullable', 'string'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon' => ['nullable', 'string', 'max:8'],
        ] + ($department ? [
            'is_active' => ['sometimes', 'boolean'],
            'handoff_date_label' => ['nullable', 'string', 'max:40'],
        ] : []);
    }
}
