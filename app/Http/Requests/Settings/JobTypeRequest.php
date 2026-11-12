<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** Settings > ประเภทงาน: adding a job type (store) and editing one (update: the job type is in the route). */
class JobTypeRequest extends FormRequest
{
    public function rules(): array
    {
        $jobType = $this->route('jobType');

        return [
            'name' => ['required', 'string', 'max:255', 'unique:job_types,name'.($jobType ? ','.$jobType->id : '')],
            'description' => ['nullable', 'string'],
        ] + ($jobType ? ['is_active' => ['sometimes', 'boolean']] : []);
    }
}
