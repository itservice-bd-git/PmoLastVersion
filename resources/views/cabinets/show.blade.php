<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2">
            <a href="{{ route('projects.show', $cabinet->project) }}" class="text-sm text-slate-400 hover:text-slate-600">{{ $cabinet->project->project_no }}</a>
            <span class="text-slate-300">/</span>
            <h2 class="text-lg font-semibold text-slate-900">{{ $cabinet->mo_no }}</h2>
        </div>
    </x-slot>

    @php
        $initialTab = in_array(request('tab'), ['tasks', 'attachments', 'comment', 'activity']) ? request('tab') : 'tasks';
    @endphp
    <div class="max-w-screen-2xl mx-auto space-y-6" x-data="{ tab: '{{ $initialTab }}' }">

        <x-card>
            <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-bold text-slate-900">{{ $cabinet->cabinet_name }}</h1>
                        <x-status-badge :status="$cabinet->status" id="cabinet-{{ $cabinet->id }}" />
                    </div>
                    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-1 mt-3 text-sm">
                        <div><dt class="text-slate-400 text-xs">Cabinet Type</dt><dd class="font-medium text-slate-700">{{ $cabinet->cabinet_type ?? '-' }}</dd></div>
                        <div><dt class="text-slate-400 text-xs">ขนาดตู้</dt><dd class="font-medium text-slate-700">{{ $cabinet->size ?? '-' }}</dd></div>
                        <div><dt class="text-slate-400 text-xs">Quantity</dt><dd class="font-medium text-slate-700">{{ $cabinet->quantity }}</dd></div>
                        <div><dt class="text-slate-400 text-xs">Start Date</dt><dd class="font-medium text-slate-700">{{ optional($cabinet->start_date)->format('d/m/Y') ?? '-' }}</dd></div>
                        <div><dt class="text-slate-400 text-xs">Due Date</dt><dd class="font-medium text-slate-700">{{ optional($cabinet->due_date)->format('d/m/Y') ?? '-' }}</dd></div>
                        <div>
                            <dt class="text-slate-400 text-xs">วันคาดว่าจะเสร็จ</dt>
                            <dd class="font-medium {{ $cabinet->expected_completion_date && $cabinet->due_date && $cabinet->expected_completion_date->gt($cabinet->due_date) ? 'text-red-600' : 'text-slate-700' }}">
                                {{ optional($cabinet->expected_completion_date)->format('d/m/Y') ?? '-' }}
                            </dd>
                        </div>
                        <div><dt class="text-slate-400 text-xs">วันทำงานทั้งหมด (ไม่รวมเสาร์-อาทิตย์)</dt><dd class="font-medium text-slate-700">{{ $cabinet->total_working_days !== null ? $cabinet->total_working_days.' วัน' : '- (ต้องตั้ง Start/Due Date ก่อน)' }}</dd></div>
                    </dl>
                </div>
                <div class="flex gap-2 shrink-0">
                    <a href="{{ route('cabinets.edit', $cabinet) }}" class="px-3 py-2 rounded-lg bg-slate-100 text-sm font-medium text-slate-700 hover:bg-slate-200">Edit</a>
                    <form method="POST" action="{{ route('cabinets.destroy', $cabinet) }}" onsubmit="return confirm('ยืนยันการลบตู้ {{ $cabinet->mo_no }} นี้? ข้อมูล Task/Sub Task/Checklist ทั้งหมดของตู้นี้จะถูกลบไปด้วย')">
                        @csrf @method('DELETE')
                        <button class="px-3 py-2 rounded-lg bg-red-50 text-sm font-medium text-red-600 hover:bg-red-100">Delete</button>
                    </form>
                </div>
            </div>

            <div class="mt-5">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs font-medium text-slate-500">Cabinet Progress</span>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-bold text-slate-800" id="progress-value-cabinet-{{ $cabinet->id }}">{{ $cabinet->progress }}%</span>
                        <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-700 bg-blue-50 px-2.5 py-1 rounded-full">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <span id="checklist-count-cabinet-{{ $cabinet->id }}">{{ $cabinet->checklist_counts['completed'] }}/{{ $cabinet->checklist_counts['total'] }}</span> Checklists
                        </span>
                    </div>
                </div>
                <x-progress-bar :value="$cabinet->progress" id="cabinet-{{ $cabinet->id }}" />
            </div>

            @if ($cabinet->description)
                <div class="mt-4 pt-4 border-t border-slate-100 text-sm space-y-1">
                    <p class="text-slate-600">{{ $cabinet->description }}</p>
                </div>
            @endif
        </x-card>

        <div class="flex gap-1 border-b border-slate-200">
            <button type="button" @click="tab = 'tasks'"
                    :class="tab === 'tasks' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px">Tasks</button>
            <button type="button" @click="tab = 'attachments'"
                    :class="tab === 'attachments' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px">ไฟล์แนบ ({{ $cabinet->attachments->count() }})</button>
            <button type="button" @click="tab = 'comment'"
                    :class="tab === 'comment' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px">Comment</button>
            <button type="button" @click="tab = 'activity'"
                    :class="tab === 'activity' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px">Activity Log</button>
        </div>

        <div x-show="tab === 'comment'" x-cloak>
            <x-comment-thread :comments="$comments" note-route="cabinets.notes.store" :note-model="$cabinet"
                               title="Comment" subtitle="ความคิดเห็น/บันทึกของตู้นี้ เรียงล่าสุดก่อน" />
        </div>

        <div x-show="tab === 'activity'" x-cloak>
            <x-card title="Activity Log" subtitle="ประวัติการเปลี่ยนแปลงทั้งหมดของตู้นี้ เรียงล่าสุดก่อน">
                <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                    @forelse ($activityLogs as $log)
                        <div class="px-5 py-3 flex items-start gap-3 text-sm">
                            <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-xs font-semibold text-slate-500 shrink-0">
                                {{ $log->user ? mb_substr($log->user->name, 0, 1) : '?' }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-slate-700">
                                    <span class="font-medium text-slate-900">{{ $log->user?->name ?? 'ระบบ' }}</span>
                                    {{ $log->description }}
                                </p>
                                <p class="text-xs text-slate-400 mt-0.5">{{ $log->created_at->format('d/m/Y H:i') }}</p>
                            </div>
                        </div>
                    @empty
                        <p class="px-5 py-6 text-center text-slate-400 text-sm">ยังไม่มีประวัติการเปลี่ยนแปลง</p>
                    @endforelse
                </div>
                @if ($activityLogs->hasPages())
                    <div class="mt-4 -mx-5 -mb-5 px-5 pb-2">
                        {{ $activityLogs->onEachSide(1)->links() }}
                    </div>
                @endif
            </x-card>
        </div>

        <div x-show="tab === 'attachments'" x-cloak>
            <x-card title="ไฟล์แนบ" :subtitle="$cabinet->attachments->count().' ไฟล์'">
                <x-slot name="actions">
                    <button type="button" x-data @click="$dispatch('open-modal', 'add-attachment')" class="text-sm font-medium text-blue-600 hover:underline">+ อัปโหลดไฟล์</button>
                </x-slot>

                <ul class="divide-y divide-slate-100 -mx-5 -mb-5">
                    @forelse ($cabinet->attachments as $attachment)
                        <li class="px-5 py-3 flex items-center gap-3 text-sm">
                            <svg class="w-5 h-5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                            <div class="min-w-0 flex-1">
                                <a href="{{ $attachment->url }}" target="_blank" class="font-medium text-blue-600 hover:underline break-all">{{ $attachment->original_name }}</a>
                                @if ($attachment->description)
                                    <p class="text-slate-500 text-xs mt-0.5">{{ $attachment->description }}</p>
                                @endif
                                <p class="text-slate-400 text-xs mt-0.5">
                                    {{ $attachment->size_for_humans }} · อัปโหลดโดย {{ $attachment->uploadedBy?->name ?? 'ระบบ' }}, {{ $attachment->created_at->format('d/m/Y H:i') }}
                                </p>
                            </div>
                            <form method="POST" action="{{ route('cabinet-attachments.destroy', $attachment) }}" onsubmit="return confirm('ลบไฟล์นี้?')" class="shrink-0">
                                @csrf @method('DELETE')
                                <button class="text-xs text-red-500 hover:underline">Delete</button>
                            </form>
                        </li>
                    @empty
                        <li class="px-5 py-6 text-center text-slate-400 text-sm">ยังไม่มีไฟล์แนบ</li>
                    @endforelse
                </ul>
            </x-card>
        </div>

        <div x-show="tab === 'tasks'" class="space-y-4">
            @foreach ($cabinet->tasks as $task)
                <x-card class="!p-0">
                    <details open class="group">
                        <summary class="list-none cursor-pointer px-5 py-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                            <div class="flex flex-wrap items-center gap-3 min-w-0">
                                <svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                                <h3 class="font-semibold text-slate-900">{{ $task->name }}</h3>
                                <x-status-badge :status="$task->status" id="task-{{ $task->id }}" />
                                @if ($task->start_date)
                                    <span class="text-xs text-slate-400 whitespace-nowrap">เริ่ม {{ $task->start_date->format('d/m/Y') }}</span>
                                @endif
                                @if ($task->due_date)
                                    <span class="text-xs text-slate-400 whitespace-nowrap">จบ {{ $task->due_date->format('d/m/Y') }}</span>
                                    @if ($task->status !== \App\Models\CabinetTask::STATUS_COMPLETED)
                                        <span class="text-xs font-medium px-1.5 py-0.5 rounded whitespace-nowrap {{ $task->days_remaining < 0 ? 'bg-red-100 text-red-700' : ($task->days_remaining <= 3 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500') }}">
                                            {{ $task->days_remaining >= 0 ? 'เหลือ '.$task->days_remaining.' วัน' : 'เลยกำหนด '.abs($task->days_remaining).' วัน' }}
                                        </span>
                                    @endif
                                @endif
                                <button type="button" x-data @click.stop.prevent="$dispatch('open-modal', 'edit-task-{{ $task->id }}')" class="text-xs font-medium text-blue-600 hover:underline shrink-0">Edit</button>
                            </div>
                            <div class="w-full sm:w-52 sm:shrink-0">
                                <div class="flex items-center justify-end gap-2 mb-1">
                                    <span class="text-sm font-bold text-slate-800" id="progress-value-task-{{ $task->id }}">{{ $task->progress }}%</span>
                                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-blue-700 bg-blue-50 px-2 py-0.5 rounded-full">
                                        <span id="checklist-count-task-{{ $task->id }}">{{ $task->checklist_counts['completed'] }}/{{ $task->checklist_counts['total'] }}</span> Checklists
                                    </span>
                                </div>
                                <x-progress-bar :value="$task->progress" size="sm" id="task-{{ $task->id }}" />
                            </div>
                        </summary>

                        <div class="border-t border-slate-100 divide-y divide-slate-100">
                            @forelse ($task->subtasks as $subtask)
                                <details class="pl-12 pr-5 py-3 border-l-2 border-slate-100 ml-5" x-data="{ addChecklist: false, copyChecklist: false }">
                                    <summary class="list-none cursor-pointer flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 sm:min-w-[22rem] flex-1">
                                            <span class="text-sm font-medium text-slate-800 min-w-[10rem] max-w-xs">{{ $subtask->name }}</span>
                                            <x-status-badge :status="$subtask->status" id="subtask-{{ $subtask->id }}" />
                                            <div class="flex flex-wrap items-center gap-2" data-assignment="{{ $subtask->id }}" @click.stop>
                                                @include('cabinets.partials._assignment', ['subtask' => $subtask, 'departments' => $departments])
                                            </div>
                                            @if ($subtask->start_date)
                                                <span class="text-xs text-slate-400 whitespace-nowrap">เริ่ม {{ $subtask->start_date->format('d/m/Y') }}</span>
                                            @endif
                                            @if ($subtask->due_date)
                                                <span class="text-xs text-slate-400 whitespace-nowrap">จบ {{ $subtask->due_date->format('d/m/Y') }}</span>
                                                @if ($subtask->status !== \App\Models\CabinetSubtask::STATUS_COMPLETED)
                                                    <span class="text-xs font-medium px-1.5 py-0.5 rounded whitespace-nowrap {{ $subtask->days_remaining < 0 ? 'bg-red-100 text-red-700' : ($subtask->days_remaining <= 3 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500') }}">
                                                        {{ $subtask->days_remaining >= 0 ? 'เหลือ '.$subtask->days_remaining.' วัน' : 'เลยกำหนด '.abs($subtask->days_remaining).' วัน' }}
                                                    </span>
                                                @endif
                                            @endif
                                        </div>
                                        <div class="flex flex-wrap items-center gap-3">
                                            <div class="w-full sm:w-44">
                                                <div class="flex items-center justify-end gap-2 mb-1">
                                                    <span class="text-sm font-bold text-slate-800" id="progress-value-subtask-{{ $subtask->id }}">{{ $subtask->progress }}%</span>
                                                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-blue-700 bg-blue-50 px-2 py-0.5 rounded-full">
                                                        <span id="checklist-count-subtask-{{ $subtask->id }}">{{ $subtask->checklists->where('is_completed', true)->count() }}/{{ $subtask->checklists->count() }}</span> Checklists
                                                    </span>
                                                </div>
                                                <x-progress-bar :value="$subtask->progress" size="sm" id="subtask-{{ $subtask->id }}" />
                                            </div>
                                            <button type="button" @click.stop.prevent="addChecklist = !addChecklist" class="text-xs font-medium text-blue-600 hover:underline">+ Checklist</button>
                                            <button type="button" @click.stop.prevent="copyChecklist = !copyChecklist" class="text-xs font-medium text-blue-600 hover:underline">คัดลอกจาก Sub Task อื่น</button>
                                            <button type="button" x-data @click.stop.prevent="$dispatch('open-modal', 'edit-subtask-{{ $subtask->id }}')" class="text-xs font-medium text-blue-600 hover:underline">Edit</button>
                                        </div>
                                    </summary>

                                    <ul class="mt-3 ml-7 space-y-2" data-checklist-list="{{ $subtask->id }}">
                                        @forelse ($subtask->checklists as $checklist)
                                            <li class="flex items-center gap-2 text-sm">
                                                <input type="checkbox"
                                                       class="checklist-toggle rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                                       data-checklist-id="{{ $checklist->id }}"
                                                       data-url="{{ route('checklists.toggle', $checklist) }}"
                                                       @checked($checklist->is_completed)>
                                                <span class="{{ $checklist->is_completed ? 'text-slate-400 line-through' : 'text-slate-700' }}" data-checklist-label="{{ $checklist->id }}">
                                                    {{ $checklist->name }}
                                                </span>
                                                @if ($checklist->is_completed && $checklist->completedBy)
                                                    <span class="text-xs text-slate-400" data-checklist-meta="{{ $checklist->id }}">
                                                        — {{ $checklist->completedBy->name }}, {{ $checklist->completed_at?->format('d/m/Y H:i') }}
                                                    </span>
                                                @else
                                                    <span class="text-xs text-slate-400" data-checklist-meta="{{ $checklist->id }}"></span>
                                                @endif
                                                <span class="checklist-remark-display ml-auto cursor-pointer text-xs border-b border-dashed border-slate-300 hover:border-slate-400 hover:text-slate-600 truncate max-w-[8rem] {{ $checklist->remark ? 'text-slate-500' : 'text-slate-300 italic' }}"
                                                      data-checklist-id="{{ $checklist->id }}" title="กดเพื่อแก้ไขหมายเหตุ">{{ $checklist->remark ?: 'หมายเหตุ' }}</span>
                                                <form method="POST" action="{{ route('checklists.update-remark', $checklist) }}" class="hidden items-center gap-1" data-checklist-id="{{ $checklist->id }}">
                                                    @csrf @method('PATCH')
                                                    <input type="text" name="remark" value="{{ $checklist->remark }}"
                                                           placeholder="หมายเหตุ" title="หมายเหตุ"
                                                           class="checklist-remark-input rounded border-slate-300 text-xs py-0.5 px-1.5 w-40">
                                                </form>
                                                <form method="POST" action="{{ route('checklists.destroy', $checklist) }}" onsubmit="return confirm('ลบ Checklist นี้?')">
                                                    @csrf @method('DELETE')
                                                    <button class="text-xs text-red-500 hover:underline">Delete</button>
                                                </form>
                                            </li>
                                        @empty
                                            <li class="text-xs text-slate-400">ยังไม่มี Checklist</li>
                                        @endforelse
                                    </ul>

                                    <div x-show="addChecklist" x-cloak class="mt-3 ml-7">
                                        <form method="POST" action="{{ route('checklists.store', $subtask) }}" class="flex items-end gap-2">
                                            @csrf
                                            <div class="flex-1">
                                                <textarea name="names" rows="2" required
                                                          placeholder="พิมพ์ชื่อ Checklist บรรทัดละ 1 รายการ (เฉพาะตู้นี้ ไม่กระทบ Template)"
                                                          class="block w-full rounded-lg border-slate-300 text-sm"></textarea>
                                            </div>
                                            <button class="px-3 py-2 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 self-start">Add</button>
                                        </form>
                                    </div>

                                    <div x-show="copyChecklist" x-cloak class="mt-3 ml-7">
                                        <form method="POST" action="{{ route('checklists.copy', $subtask) }}" class="checklist-copy-form flex items-end gap-2" data-subtask-id="{{ $subtask->id }}">
                                            @csrf
                                            <div class="flex-1">
                                                <select name="source_subtask_id" required class="block w-full rounded-lg border-slate-300 text-sm">
                                                    <option value="">-- เลือก Sub Task ต้นทางที่จะคัดลอก Checklist มา --</option>
                                                    @foreach ($cabinet->tasks as $otherTask)
                                                        <optgroup label="{{ $otherTask->name }}">
                                                            @foreach ($otherTask->subtasks as $otherSubtask)
                                                                @continue($otherSubtask->id === $subtask->id)
                                                                <option value="{{ $otherSubtask->id }}">{{ $otherSubtask->name }} ({{ $otherSubtask->checklists->count() }} รายการ)</option>
                                                            @endforeach
                                                        </optgroup>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <button class="px-3 py-2 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 self-start shrink-0">Copy</button>
                                        </form>
                                    </div>
                                </details>
                            @empty
                                <p class="px-5 py-4 text-sm text-slate-400">ยังไม่มี Sub Task</p>
                            @endforelse
                        </div>
                    </details>
                </x-card>
            @endforeach
        </div>
    </div>

    <x-modal name="add-attachment">
        <form method="POST" action="{{ route('cabinet-attachments.store', $cabinet) }}" enctype="multipart/form-data" class="p-6">
            @csrf
            <h3 class="text-base font-semibold text-slate-900 mb-4">อัปโหลดไฟล์แนบ — {{ $cabinet->mo_no }}</h3>
            <div class="space-y-4">
                <div>
                    <x-input-label value="ไฟล์" />
                    <input type="file" name="file" required
                           class="mt-1 block w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-slate-100 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200">
                    <p class="text-xs text-slate-400 mt-1">รองรับ PDF, Word, Excel, รูปภาพ, DWG/DXF — ไม่เกิน 20 MB</p>
                    <x-input-error :messages="$errors->get('file')" class="mt-1" />
                </div>
                <div>
                    <x-input-label value="รายละเอียด (ไฟล์นี้คืออะไร)" />
                    <textarea name="description" rows="2" placeholder="เช่น แบบ Wiring Diagram แก้ไขล่าสุด" class="mt-1 block w-full rounded-lg border-slate-300 text-sm"></textarea>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" x-data @click="$dispatch('close-modal', 'add-attachment')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">อัปโหลด</button>
            </div>
        </form>
    </x-modal>

    @foreach ($cabinet->tasks as $task)
        <x-modal :name="'edit-task-'.$task->id" :show="old('task_id') == $task->id">
            <form method="POST" action="{{ route('cabinet-tasks.update', $task) }}" class="p-6">
                @csrf @method('PUT')
                <input type="hidden" name="task_id" value="{{ $task->id }}">
                <h3 class="text-base font-semibold text-slate-900 mb-4">Edit Task — {{ $task->name }}</h3>
                <div class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <x-input-label value="Start Date" />
                            <input type="date" name="start_date"
                                   value="{{ old('task_id') == $task->id ? old('start_date') : optional($task->start_date)->format('Y-m-d') }}"
                                   class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                        </div>
                        <div>
                            <x-input-label value="Due Date" />
                            <input type="date" name="due_date"
                                   value="{{ old('task_id') == $task->id ? old('due_date') : optional($task->due_date)->format('Y-m-d') }}"
                                   class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                            @error('due_date')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div>
                        <x-input-label value="Remark" />
                        <textarea name="remark" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ $task->remark }}</textarea>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" x-data @click="$dispatch('close-modal', 'edit-task-{{ $task->id }}')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">Save</button>
                </div>
            </form>
        </x-modal>

        @foreach ($task->subtasks as $subtask)
            <x-modal :name="'edit-subtask-'.$subtask->id" :show="old('subtask_id') == $subtask->id">
                <form method="POST" action="{{ route('cabinet-subtasks.update', $subtask) }}" class="p-6">
                    @csrf @method('PUT')
                    <input type="hidden" name="subtask_id" value="{{ $subtask->id }}">
                    <h3 class="text-base font-semibold text-slate-900 mb-4">Edit Sub Task — {{ $subtask->name }}</h3>
                    @error('due_date')
                        <p class="text-xs text-red-600 mb-3">{{ $message }}</p>
                    @enderror
                    <div class="space-y-4">
                        <div>
                            <x-input-label value="Status" />
                            <select name="status" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                                @foreach ($subtaskStatuses as $key => $label)
                                    <option value="{{ $key }}" @selected($subtask->status === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-slate-400 mt-1">Progress คำนวณจาก Checklist อัตโนมัติ สถานะจะอัปเดตตามเปอร์เซ็นต์ เว้นแต่ตั้งเป็น On Hold</p>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <x-input-label value="Department" />
                                {{-- Disabled once the department has accepted the work (backend rejects a change anyway), or if this user can't dispatch work - disabled fields aren't submitted either way. --}}
                                <select name="department_id" data-modal-department="{{ $subtask->id }}" @disabled($subtask->is_department_locked || ! auth()->user()?->canDispatchWork())
                                        @if ($subtask->is_department_locked) title="ไม่สามารถเปลี่ยนแผนกได้ เนื่องจากแผนกรับงานแล้ว"
                                        @elseif (! auth()->user()?->canDispatchWork()) title="เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่มีสิทธิ์เปลี่ยนแผนก" @endif
                                        class="mt-1 block w-full rounded-lg border-slate-300 text-sm disabled:bg-slate-100 disabled:text-slate-500 disabled:cursor-not-allowed">
                                    <option value="">-</option>
                                    @foreach ($departments as $department)
                                        <option value="{{ $department->id }}" @selected($subtask->department_id == $department->id)>{{ $department->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <x-input-label value="Owner" />
                                <select name="owner_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                                    <option value="">-</option>
                                    @foreach ($users as $user)
                                        <option value="{{ $user->id }}" @selected($subtask->owner_id == $user->id)>{{ $user->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <x-input-label value="วันเริ่ม" />
                                <input type="date" name="start_date"
                                       value="{{ old('subtask_id') == $subtask->id ? old('start_date') : optional($subtask->start_date)->format('Y-m-d') }}"
                                       class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                                <p class="text-xs text-slate-400 mt-1">วันที่เริ่มทำงานย่อยนี้</p>
                            </div>
                            <div>
                                <x-input-label value="วันครบกำหนด" />
                                <input type="date" name="due_date"
                                       value="{{ old('subtask_id') == $subtask->id ? old('due_date') : optional($subtask->due_date)->format('Y-m-d') }}"
                                       class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                                <p class="text-xs text-slate-400 mt-1">วันที่ต้องทำงานย่อยนี้ให้เสร็จ</p>
                            </div>
                        </div>
                        <div>
                            <x-input-label value="Remark" />
                            <textarea name="remark" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ $subtask->remark }}</textarea>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" x-data @click="$dispatch('close-modal', 'edit-subtask-{{ $subtask->id }}')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                        <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">Save</button>
                    </div>
                </form>
            </x-modal>
        @endforeach
    @endforeach

    @push('scripts')
    @include('cabinets.partials._assignment-scripts')
    <script>
        document.addEventListener('change', async (e) => {
            if (!e.target.matches('.checklist-toggle')) return;

            const checkbox = e.target;
            const url = checkbox.dataset.url;
            const id = checkbox.dataset.checklistId;
            const isCompleted = checkbox.checked;
            checkbox.disabled = true;

            try {
                const res = await fetch(url, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ is_completed: isCompleted }),
                });
                if (!res.ok) throw new Error('Request failed');
                const data = await res.json();

                const label = document.querySelector('[data-checklist-label="'+id+'"]');
                if (label) label.classList.toggle('line-through', isCompleted);
                if (label) label.classList.toggle('text-slate-400', isCompleted);
                if (label) label.classList.toggle('text-slate-700', !isCompleted);

                const meta = document.querySelector('[data-checklist-meta="'+id+'"]');
                if (meta) {
                    meta.textContent = data.checklist.is_completed
                        ? ('— ' + (data.checklist.completed_by_name ?? '') + ', ' + (data.checklist.completed_at_formatted ?? ''))
                        : '';
                }

                updateProgress('subtask-' + data.subtask.id, data.subtask.progress);
                updateStatusBadge('subtask-' + data.subtask.id, data.subtask.status);
                updateChecklistCount('subtask-' + data.subtask.id, data.subtask.completed_checklists, data.subtask.total_checklists);

                updateProgress('task-' + data.task.id, data.task.progress);
                updateStatusBadge('task-' + data.task.id, data.task.status);
                updateChecklistCount('task-' + data.task.id, data.task.completed_checklists, data.task.total_checklists);

                updateProgress('cabinet-' + data.cabinet.id, data.cabinet.progress);
                updateStatusBadge('cabinet-' + data.cabinet.id, data.cabinet.status);
                updateChecklistCount('cabinet-' + data.cabinet.id, data.cabinet.completed_checklists, data.cabinet.total_checklists);
            } catch (err) {
                checkbox.checked = !isCompleted;
                alert('ไม่สามารถบันทึกได้ กรุณาลองใหม่');
            } finally {
                checkbox.disabled = false;
            }
        });

        document.addEventListener('click', (e) => {
            if (!e.target.matches('.checklist-remark-display')) return;

            const display = e.target;
            const form = display.nextElementSibling;
            const input = form.querySelector('.checklist-remark-input');

            display.classList.add('hidden');
            form.classList.remove('hidden');
            form.classList.add('flex');
            input.focus();
            input.select();
        });

        document.addEventListener('keydown', (e) => {
            if (!e.target.matches('.checklist-remark-input')) return;
            if (e.key !== 'Enter') return;
            e.preventDefault();
            e.target.blur();
        });

        document.addEventListener('focusout', (e) => {
            if (!e.target.matches('.checklist-remark-input')) return;

            const input = e.target;
            const form = input.closest('form');
            const display = form.previousElementSibling;

            display.textContent = input.value || 'หมายเหตุ';
            display.classList.toggle('text-slate-500', !!input.value);
            display.classList.toggle('text-slate-300', !input.value);
            display.classList.toggle('italic', !input.value);

            form.classList.add('hidden');
            form.classList.remove('flex');
            display.classList.remove('hidden');
        });

        document.addEventListener('change', async (e) => {
            if (!e.target.matches('.checklist-remark-input')) return;

            const input = e.target;
            const form = input.closest('form');
            input.disabled = true;

            try {
                const res = await fetch(form.action, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ remark: input.value || null }),
                });
                if (!res.ok) throw new Error('Request failed');
            } catch (err) {
                alert('ไม่สามารถบันทึกหมายเหตุได้ กรุณาลองใหม่');
            } finally {
                input.disabled = false;
            }
        });

        document.addEventListener('submit', async (e) => {
            if (!e.target.matches('.checklist-copy-form')) return;
            e.preventDefault();

            const form = e.target;
            const select = form.querySelector('select[name="source_subtask_id"]');
            if (!select.value) {
                alert('กรุณาเลือก Sub Task ต้นทาง');
                return;
            }

            const submitBtn = form.querySelector('button');
            submitBtn.disabled = true;

            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ source_subtask_id: select.value }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Request failed');

                const ul = document.querySelector('[data-checklist-list="' + form.dataset.subtaskId + '"]');
                if (ul) {
                    ul.innerHTML = '';
                    data.checklists.forEach((c) => ul.appendChild(buildChecklistRow(c)));
                    if (window.initDatePickers) window.initDatePickers(ul);
                }

                updateProgress('subtask-' + data.subtask.id, data.subtask.progress);
                updateStatusBadge('subtask-' + data.subtask.id, data.subtask.status);
                updateChecklistCount('subtask-' + data.subtask.id, data.subtask.completed_checklists, data.subtask.total_checklists);

                updateProgress('task-' + data.task.id, data.task.progress);
                updateStatusBadge('task-' + data.task.id, data.task.status);
                updateChecklistCount('task-' + data.task.id, data.task.completed_checklists, data.task.total_checklists);

                updateProgress('cabinet-' + data.cabinet.id, data.cabinet.progress);
                updateStatusBadge('cabinet-' + data.cabinet.id, data.cabinet.status);
                updateChecklistCount('cabinet-' + data.cabinet.id, data.cabinet.completed_checklists, data.cabinet.total_checklists);

                select.value = '';
            } catch (err) {
                alert(err.message || 'ไม่สามารถคัดลอกได้ กรุณาลองใหม่');
            } finally {
                submitBtn.disabled = false;
            }
        });

        function buildChecklistRow(c) {
            const li = document.createElement('li');
            li.className = 'flex items-center gap-2 text-sm';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'checklist-toggle rounded border-slate-300 text-blue-600 focus:ring-blue-500';
            checkbox.dataset.checklistId = c.id;
            checkbox.dataset.url = c.toggle_url;
            checkbox.checked = c.is_completed;

            const label = document.createElement('span');
            label.className = c.is_completed ? 'text-slate-400 line-through' : 'text-slate-700';
            label.dataset.checklistLabel = c.id;
            label.textContent = c.name;

            const meta = document.createElement('span');
            meta.className = 'text-xs text-slate-400';
            meta.dataset.checklistMeta = c.id;

            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

            const remarkDisplay = document.createElement('span');
            remarkDisplay.className = 'checklist-remark-display ml-auto cursor-pointer text-xs border-b border-dashed border-slate-300 hover:border-slate-400 hover:text-slate-600 truncate max-w-[8rem] text-slate-300 italic';
            remarkDisplay.dataset.checklistId = c.id;
            remarkDisplay.title = 'กดเพื่อแก้ไขหมายเหตุ';
            remarkDisplay.textContent = 'หมายเหตุ';

            const remarkForm = document.createElement('form');
            remarkForm.method = 'POST';
            remarkForm.action = c.remark_url;
            remarkForm.className = 'hidden items-center gap-1';
            remarkForm.dataset.checklistId = c.id;
            remarkForm.innerHTML = '<input type="hidden" name="_token" value="' + csrfToken + '">'
                + '<input type="hidden" name="_method" value="PATCH">'
                + '<input type="text" name="remark" value="" placeholder="หมายเหตุ" title="หมายเหตุ" class="checklist-remark-input rounded border-slate-300 text-xs py-0.5 px-1.5 w-40">';

            const deleteForm = document.createElement('form');
            deleteForm.method = 'POST';
            deleteForm.action = c.destroy_url;
            deleteForm.innerHTML = '<input type="hidden" name="_token" value="' + csrfToken + '">'
                + '<input type="hidden" name="_method" value="DELETE">'
                + '<button class="text-xs text-red-500 hover:underline">Delete</button>';
            deleteForm.addEventListener('submit', (e) => {
                if (!confirm('ลบ Checklist นี้?')) e.preventDefault();
            });

            li.append(checkbox, label, meta, remarkDisplay, remarkForm, deleteForm);
            return li;
        }

        function updateProgress(id, value) {
            const fill = document.getElementById('progress-fill-' + id);
            const label = document.getElementById('progress-value-' + id);
            if (fill) {
                fill.style.width = value + '%';
                fill.classList.remove('bg-emerald-500', 'bg-blue-500', 'bg-amber-500', 'bg-slate-300');
                fill.classList.add(value >= 100 ? 'bg-emerald-500' : value >= 60 ? 'bg-blue-500' : value >= 30 ? 'bg-amber-500' : 'bg-slate-300');
            }
            if (label) label.textContent = value + '%';
        }

        function updateChecklistCount(id, completed, total) {
            const el = document.getElementById('checklist-count-' + id);
            if (el) el.textContent = completed + '/' + total;
        }

        const STATUS_MAP = {
            not_started: ['bg-slate-100 text-slate-600', 'Not Started'],
            in_progress: ['bg-blue-100 text-blue-700', 'In Progress'],
            completed: ['bg-emerald-100 text-emerald-700', 'Completed'],
            on_hold: ['bg-amber-100 text-amber-700', 'On Hold'],
        };

        function updateStatusBadge(id, status) {
            const badge = document.getElementById('status-badge-' + id);
            if (!badge) return;
            const [classes, label] = STATUS_MAP[status] || ['bg-slate-100 text-slate-600', status];
            badge.className = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium whitespace-nowrap ' + classes;
            badge.textContent = label;
        }
    </script>
    @endpush
</x-app-layout>
