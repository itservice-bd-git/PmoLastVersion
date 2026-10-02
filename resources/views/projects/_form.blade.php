@php
    $p = $project ?? null;
@endphp

<div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div>
        <x-input-label for="project_no" value="Project No." />
        <x-text-input id="project_no" name="project_no" class="mt-1 block w-full" :value="old('project_no', $p?->project_no)" required />
        <x-input-error :messages="$errors->get('project_no')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="project_name" value="Project Name" />
        <x-text-input id="project_name" name="project_name" class="mt-1 block w-full" :value="old('project_name', $p?->project_name)" required />
        <x-input-error :messages="$errors->get('project_name')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="customer_name" value="Customer" />
        <x-text-input id="customer_name" name="customer_name" class="mt-1 block w-full" :value="old('customer_name', $p?->customer_name)" required />
        <x-input-error :messages="$errors->get('customer_name')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="po_no" value="PO Number" />
        <x-text-input id="po_no" name="po_no" class="mt-1 block w-full" :value="old('po_no', $p?->po_no)" />
    </div>
    <div>
        <x-input-label for="sales_order_no" value="Sales Order / Reference" />
        <x-text-input id="sales_order_no" name="sales_order_no" class="mt-1 block w-full" :value="old('sales_order_no', $p?->sales_order_no)" />
    </div>
    <div>
        <x-input-label for="project_owner" value="Project Owner" />
        <x-text-input id="project_owner" name="project_owner" class="mt-1 block w-full" :value="old('project_owner', $p?->project_owner)" />
        <x-input-error :messages="$errors->get('project_owner')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="sales_person" value="Sales Person" />
        <x-text-input id="sales_person" name="sales_person" class="mt-1 block w-full" :value="old('sales_person', $p?->sales_person)" />
        <x-input-error :messages="$errors->get('sales_person')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="project_manager_id" value="Project Manager" />
        <select id="project_manager_id" name="project_manager_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
            <option value="">-</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}" @selected(old('project_manager_id', $p?->project_manager_id) == $user->id)>{{ $user->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <x-input-label for="job_type_id" value="ประเภทงาน" />
        <select id="job_type_id" name="job_type_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
            <option value="">-</option>
            @foreach ($jobTypes as $jobType)
                <option value="{{ $jobType->id }}" @selected(old('job_type_id', $p?->job_type_id) == $jobType->id)>{{ $jobType->name }}</option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('job_type_id')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="payment_terms" value="เงื่อนไขการชำระ" />
        <x-text-input id="payment_terms" name="payment_terms" class="mt-1 block w-full" :value="old('payment_terms', $p?->payment_terms)" />
        <x-input-error :messages="$errors->get('payment_terms')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="start_date" value="Start Date" />
        <x-text-input type="date" id="start_date" name="start_date" class="mt-1 block w-full" :value="old('start_date', $p?->start_date?->format('Y-m-d'))" />
    </div>
    <div>
        <x-input-label for="due_date" value="Due Date" />
        <x-text-input type="date" id="due_date" name="due_date" class="mt-1 block w-full" :value="old('due_date', $p?->due_date?->format('Y-m-d'))" />
    </div>
    <div>
        <x-input-label for="status" value="Status" />
        <select id="status" name="status" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
            @foreach ($statuses as $key => $label)
                <option value="{{ $key }}" @selected(old('status', $p?->status ?? 'draft') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <x-input-label for="priority" value="Priority" />
        <select id="priority" name="priority" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
            @foreach ($priorities as $key => $label)
                <option value="{{ $key }}" @selected(old('priority', $p?->priority ?? 'normal') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="md:col-span-2">
        <x-input-label for="description" value="Description" />
        <textarea id="description" name="description" rows="3" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ old('description', $p?->description) }}</textarea>
    </div>
</div>
