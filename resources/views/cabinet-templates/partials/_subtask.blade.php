<div class="px-4 py-3" data-subtask-row="{{ $subtaskTemplate->id }}" x-data="{ addChecklist: false, editSubtask: false }">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2 min-w-0">
            <span class="drag-handle shrink-0 select-none" title="ลากเพื่อจัดลำดับ">⋮⋮</span>
            <span class="text-sm font-medium text-slate-700" data-subtask-name="{{ $subtaskTemplate->id }}">{{ $subtaskTemplate->name }}</span>
            <span class="text-xs text-slate-400" data-subtask-department="{{ $subtaskTemplate->id }}">{{ $subtaskTemplate->department?->name }}</span>
        </div>
        <div class="flex items-center gap-3 shrink-0">
            <button type="button" @click="editSubtask = !editSubtask" class="text-xs font-medium text-blue-600 hover:underline">Edit</button>
            <button type="button" @click="addChecklist = !addChecklist" class="text-xs font-medium text-blue-600 hover:underline">+ Checklist</button>
            <form class="subtask-template-delete-form" method="POST" action="{{ route('subtask-templates.destroy', $subtaskTemplate) }}" data-confirm="ลบ Sub Task นี้พร้อม Checklist ทั้งหมด?">
                @csrf @method('DELETE')
                <button class="text-xs font-medium text-red-600 hover:underline">Delete</button>
            </form>
        </div>
    </div>

    <div x-show="editSubtask" x-cloak class="mt-2 mb-2 p-3 bg-slate-50 rounded-lg">
        <form method="POST" action="{{ route('subtask-templates.update', $subtaskTemplate) }}" class="subtask-template-edit-form flex flex-wrap items-end gap-2" data-subtask-id="{{ $subtaskTemplate->id }}">
            @csrf @method('PUT')
            <div class="flex-1 min-w-[160px]">
                <x-input-label value="Sub Task Name" class="text-xs" />
                <x-text-input name="name" class="mt-1 block w-full text-sm" value="{{ $subtaskTemplate->name }}" required />
            </div>
            <div class="flex-1 min-w-[160px]">
                <x-input-label value="Default Department" class="text-xs" />
                <select name="department_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                    <option value="">-</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected($subtaskTemplate->department_id === $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>
            <button class="px-3 py-2 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700">Save</button>
        </form>
    </div>

    <div x-show="addChecklist" x-cloak class="mt-2">
        <form class="checklist-template-add-form" method="POST" action="{{ route('checklist-templates.store', $subtaskTemplate) }}" data-subtask-id="{{ $subtaskTemplate->id }}">
            @csrf
            <div class="flex items-end gap-2">
                <div class="flex-1">
                    <textarea name="names" rows="3" required
                              placeholder="พิมพ์ชื่อ Checklist บรรทัดละ 1 รายการ เช่น&#10;ตรวจสอบอุปกรณ์&#10;เช็ครอยเชื่อม&#10;ทำความสะอาด"
                              class="block w-full rounded-lg border-slate-300 text-sm"></textarea>
                    <p class="text-xs text-slate-400 mt-1">พิมพ์ได้หลายรายการพร้อมกัน ขึ้นบรรทัดใหม่ = 1 Checklist</p>
                </div>
                <button class="px-3 py-2 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 self-start">Add</button>
            </div>
        </form>
    </div>

    <ul class="mt-2 ml-4 space-y-1" data-checklist-list data-reorder-url="{{ route('checklist-templates.reorder') }}">
        @forelse ($subtaskTemplate->checklistTemplates as $checklistTemplate)
            @include('cabinet-templates.partials._checklist', ['checklistTemplate' => $checklistTemplate])
        @empty
            <li class="text-xs text-slate-300" data-empty-placeholder>ยังไม่มี Checklist</li>
        @endforelse
    </ul>
</div>
