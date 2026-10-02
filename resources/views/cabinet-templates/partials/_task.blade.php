<div class="border border-slate-200 rounded-lg" data-task-row="{{ $taskTemplate->id }}" x-data="{ addSubtask: false, editTask: false }">
    <div class="flex items-center justify-between px-4 py-3 bg-slate-50 rounded-t-lg">
        <div class="flex items-center gap-2 min-w-0">
            <span class="drag-handle shrink-0 select-none" title="ลากเพื่อจัดลำดับ">⋮⋮</span>
            <span class="font-semibold text-slate-800" data-task-name="{{ $taskTemplate->id }}">{{ $taskTemplate->name }}</span>
        </div>
        <div class="flex items-center gap-3 shrink-0">
            <button type="button" @click="editTask = !editTask" class="text-xs font-medium text-blue-600 hover:underline">Edit</button>
            <button type="button" @click="addSubtask = !addSubtask" class="text-xs font-medium text-blue-600 hover:underline">+ Sub Task</button>
            <form class="task-template-delete-form" method="POST" action="{{ route('task-templates.destroy', $taskTemplate) }}" data-confirm="ลบ Task นี้พร้อม Sub Task/Checklist ทั้งหมด?">
                @csrf @method('DELETE')
                <button class="text-xs font-medium text-red-600 hover:underline">Delete</button>
            </form>
        </div>
    </div>

    <div x-show="editTask" x-cloak class="px-4 py-3 border-b border-slate-100 bg-slate-50">
        <p class="text-xs text-red-600 mb-2 task-edit-error" data-task-id="{{ $taskTemplate->id }}" hidden></p>
        <form method="POST" action="{{ route('task-templates.update', $taskTemplate) }}" class="task-template-edit-form space-y-3" data-task-id="{{ $taskTemplate->id }}">
            @csrf @method('PUT')
            <div>
                <x-input-label value="Task Name" class="text-xs" />
                <x-text-input name="name" class="mt-1 block w-full text-sm" value="{{ $taskTemplate->name }}" required />
            </div>
            <div>
                <x-input-label value="Description" class="text-xs" />
                <textarea name="description" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ $taskTemplate->description }}</textarea>
            </div>
            <button class="px-3 py-2 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700">Save</button>
        </form>
    </div>

    <div x-show="addSubtask" x-cloak class="px-4 py-3 border-b border-slate-100 bg-white">
        <form class="subtask-template-add-form" method="POST" action="{{ route('subtask-templates.store', $taskTemplate) }}" data-task-id="{{ $taskTemplate->id }}">
            @csrf
            <div class="flex flex-wrap items-end gap-2">
                <div class="flex-1 min-w-[160px]">
                    <x-input-label value="Sub Task Name" class="text-xs" />
                    <x-text-input name="name" class="mt-1 block w-full text-sm" required />
                </div>
                <div class="flex-1 min-w-[160px]">
                    <x-input-label value="Default Department" class="text-xs" />
                    <select name="department_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                        <option value="">-</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="px-3 py-2 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700">Add</button>
            </div>
        </form>
    </div>

    <div class="divide-y divide-slate-100" data-subtask-list data-reorder-url="{{ route('subtask-templates.reorder') }}">
        @forelse ($taskTemplate->subtaskTemplates as $subtaskTemplate)
            @include('cabinet-templates.partials._subtask', ['subtaskTemplate' => $subtaskTemplate, 'departments' => $departments])
        @empty
            <p class="px-4 py-3 text-xs text-slate-400" data-empty-placeholder>ยังไม่มี Sub Task</p>
        @endforelse
    </div>
</div>
