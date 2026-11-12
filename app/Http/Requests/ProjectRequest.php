<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectFollowers;
use Illuminate\Foundation\Http\FormRequest;

/** The new-project form (store: starts as Draft) and the edit page (update: the route's project). */
class ProjectRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // amounts as typed ("1,250,000") -> plain numbers
        $this->merge(collect(['contract_value', 'margin'])->filter(fn ($k) => $this->has($k))
            ->mapWithKeys(fn ($k) => [$k => Project::cleanAmount($this->input($k))])->all());
    }

    public function rules(): array
    {
        $project = $this->project();

        return [
            'project_no' => ['required', 'string', 'max:50', 'unique:projects,project_no,'.($project?->id ?? 'NULL').',id,deleted_at,NULL'],
            'project_name' => ['required', 'string', 'max:255'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'contract_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'margin' => ['nullable', 'numeric', 'max:9999999999999'],
            'po_no' => ['nullable', 'string', 'max:100'],
            'sales_order_no' => ['nullable', 'string', 'max:100'],
            'project_owner' => ['nullable', 'string', 'max:255'],
            // พนักงานขาย: ticked from the sales people (they follow the project), plus names typed for anyone without an account
            'sales_ids' => ['nullable', 'array'],
            'sales_ids.*' => ['integer', 'exists:users,id'],
            'sales_person' => ['nullable', 'string', 'max:255'],
            // Admin / PM only (a manager already on the project before this rule may stay when it is edited)
            'project_manager_id' => ['nullable', 'exists:users,id', function (string $attr, mixed $value, \Closure $fail) use ($project) {
                if ($value && (int) $value !== $project?->project_manager_id && ! User::whereKey($value)->whereIn('role', Project::MANAGER_ROLES)->exists()) {
                    $fail('ผู้จัดการโครงการต้องเป็น Admin หรือ Project Manager');
                }
            }],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'job_type_id' => ['nullable', 'exists:job_types,id'],
            'board_id' => ['nullable', 'exists:boards,id'],
            // a new project has no status field (it starts as Draft); it is changed on the edit page / Planning
            'status' => [$project ? 'required' : 'nullable', 'in:'.implode(',', array_keys(Project::$statuses))],
            'priority' => ['required', 'in:'.implode(',', array_keys(Project::$priorities))],
        ];
    }

    public function messages(): array
    {
        return ['contract_value.numeric' => 'มูลค่างานต้องเป็นตัวเลข', 'margin.numeric' => 'Margin ต้องเป็นตัวเลข'];
    }

    /** The validated fields ready to save: the sales names joined, and what this person may not see left out (the old values stay). */
    public function projectData(): array
    {
        $data = $this->validated();
        $project = $this->project();

        if (! $project) {
            $data['status'] ??= Project::STATUS_DRAFT;
        } else {
            foreach (['start_date', 'due_date'] as $f) {   // set ones move only through "เลื่อน / แก้วัน" (ProjectDates, with a reason)
                if ($project->{$f}) {
                    unset($data[$f]);
                }
            }
        }
        $data['sales_person'] = ProjectFollowers::salesText($data['sales_ids'] ?? [], $data['sales_person'] ?? null);
        unset($data['sales_ids']);
        // Settings > สิทธิ์การใช้งาน
        if (! $this->user()->allows('data.finance')) {
            unset($data['contract_value'], $data['margin']);
        }
        if (! $this->user()->allows('data.contact')) {
            unset($data['customer_phone']);
        }

        return $data;
    }

    private function project(): ?Project
    {
        $p = $this->route('project');

        return $p instanceof Project ? $p : null;
    }
}
