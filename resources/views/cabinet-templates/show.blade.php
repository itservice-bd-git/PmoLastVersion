<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2">
            <h2 class="text-lg font-semibold text-slate-900">{{ $template->name }}</h2>
            @if ($template->is_default)
                <span class="text-[10px] uppercase tracking-wide bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded font-semibold">Default</span>
            @endif
        </div>
    </x-slot>

    <div class="max-w-4xl mx-auto space-y-6">
        <x-settings-nav />
        <x-card>
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-sm text-slate-500">Code: <span class="font-medium text-slate-700">{{ $template->code }}</span></p>
                    @if ($template->description)
                        <p class="text-sm text-slate-600 mt-2">{{ $template->description }}</p>
                    @endif
                </div>
                <a href="{{ route('cabinet-templates.edit', $template) }}" class="px-3 py-2 rounded-lg bg-slate-100 text-sm font-medium text-slate-700 hover:bg-slate-200 shrink-0">Edit Template</a>
            </div>
        </x-card>

        <x-card title="Production Tasks" subtitle="โครงสร้าง Task → Sub Task → Checklist ของเทมเพลตนี้ — ลากไอคอน ⋮⋮ เพื่อจัดลำดับ">
            <div class="space-y-3" data-task-list data-reorder-url="{{ route('task-templates.reorder') }}">
                @foreach ($template->taskTemplates as $taskTemplate)
                    @include('cabinet-templates.partials._task', ['taskTemplate' => $taskTemplate, 'departments' => $departments])
                @endforeach
            </div>

            <div class="mt-4 pt-4 border-t border-slate-100" x-data="{ addTask: false }">
                <button type="button" @click="addTask = !addTask" class="text-sm font-medium text-blue-600 hover:underline">+ Add Task</button>
                <div x-show="addTask" x-cloak class="mt-3">
                    <form class="task-template-add-form" method="POST" action="{{ route('task-templates.store', $template) }}">
                        @csrf
                        <div class="flex items-end gap-2">
                            <div class="flex-1">
                                <x-input-label value="Task Name" class="text-xs" />
                                <x-text-input name="name" class="mt-1 block w-full text-sm" placeholder="e.g. QC / Packing" required />
                            </div>
                            <button class="px-3 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">Add Task</button>
                        </div>
                    </form>
                </div>
            </div>
        </x-card>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('submit', async (e) => {
            if (!e.target.matches('.task-template-edit-form')) return;
            e.preventDefault();

            const form = e.target;
            const taskId = form.dataset.taskId;
            const errorEl = document.querySelector('.task-edit-error[data-task-id="' + taskId + '"]');
            const submitBtn = form.querySelector('button');
            submitBtn.disabled = true;
            if (errorEl) errorEl.hidden = true;

            const formData = new FormData(form);

            try {
                const res = await fetch(form.action, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        name: formData.get('name'),
                        description: formData.get('description'),
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Request failed');

                const nameEl = document.querySelector('[data-task-name="' + taskId + '"]');
                if (nameEl) nameEl.textContent = data.task_template.name;

                const container = form.closest('[x-data]');
                if (container && window.Alpine) {
                    window.Alpine.$data(container).editTask = false;
                }
                window.showToast('Changes saved');
            } catch (err) {
                if (errorEl) {
                    errorEl.textContent = err.message || 'ไม่สามารถบันทึกได้ กรุณาลองใหม่';
                    errorEl.hidden = false;
                } else {
                    window.showToast(err.message || 'ไม่สามารถบันทึกได้ กรุณาลองใหม่', 'error');
                }
            } finally {
                submitBtn.disabled = false;
            }
        });

        document.addEventListener('submit', async (e) => {
            if (!e.target.matches('.subtask-template-edit-form')) return;
            e.preventDefault();

            const form = e.target;
            const id = form.dataset.subtaskId;
            const submitBtn = form.querySelector('button');
            const formData = new FormData(form);
            submitBtn.disabled = true;

            try {
                const res = await fetch(form.action, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        name: formData.get('name'),
                        department_id: formData.get('department_id') || null,
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Request failed');

                const nameEl = document.querySelector('[data-subtask-name="' + id + '"]');
                if (nameEl) nameEl.textContent = data.subtask_template.name;

                const deptEl = document.querySelector('[data-subtask-department="' + id + '"]');
                if (deptEl) deptEl.textContent = data.subtask_template.department_name ?? '';

                const container = form.closest('[x-data]');
                if (container && window.Alpine) {
                    window.Alpine.$data(container).editSubtask = false;
                }
                window.showToast('Changes saved');
            } catch (err) {
                window.showToast(err.message || 'ไม่สามารถบันทึกได้ กรุณาลองใหม่', 'error');
            } finally {
                submitBtn.disabled = false;
            }
        });

        document.addEventListener('submit', async (e) => {
            if (!e.target.matches('.checklist-template-edit-form')) return;
            e.preventDefault();

            const form = e.target;
            const id = form.dataset.checklistTemplateId;
            const submitBtn = form.querySelector('button');
            const nameInput = form.querySelector('input[name="name"]');
            submitBtn.disabled = true;

            try {
                const res = await fetch(form.action, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ name: nameInput.value }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Request failed');

                const nameEl = document.querySelector('[data-checklist-template-name="' + id + '"]');
                if (nameEl) nameEl.textContent = '☐ ' + data.checklist_template.name;

                const container = form.closest('[x-data]');
                if (container && window.Alpine) {
                    window.Alpine.$data(container).editingChecklist = false;
                }
                window.showToast('Changes saved');
            } catch (err) {
                window.showToast(err.message || 'ไม่สามารถบันทึกได้ กรุณาลองใหม่', 'error');
            } finally {
                submitBtn.disabled = false;
            }
        });

        function csrfToken() {
            return document.querySelector('meta[name="csrf-token"]').content;
        }

        function removeEmptyPlaceholder(container) {
            const placeholder = container.querySelector(':scope > [data-empty-placeholder]');
            if (placeholder) placeholder.remove();
        }

        function maybeRestoreEmptyPlaceholder(container, itemSelector, text, tag, className) {
            if (container.querySelector(':scope > ' + itemSelector)) return;
            if (container.querySelector(':scope > [data-empty-placeholder]')) return;
            const el = document.createElement(tag);
            el.className = className;
            el.setAttribute('data-empty-placeholder', '');
            el.textContent = text;
            container.appendChild(el);
        }

        // ---- Add Task / Sub Task / Checklist (async, no reload) ----

        document.addEventListener('submit', async (e) => {
            if (!e.target.matches('.task-template-add-form')) return;
            e.preventDefault();

            const form = e.target;
            const formData = new FormData(form);
            const submitBtn = form.querySelector('button');
            submitBtn.disabled = true;

            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                    body: formData,
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Request failed');

                const list = document.querySelector('[data-task-list]');
                list.insertAdjacentHTML('beforeend', data.html);
                initSortable(list.parentElement);

                form.reset();
                const container = form.closest('[x-data]');
                if (container && window.Alpine) window.Alpine.$data(container).addTask = false;
                window.showToast('Task added');
            } catch (err) {
                window.showToast(err.message || 'ไม่สามารถเพิ่ม Task ได้ กรุณาลองใหม่', 'error');
            } finally {
                submitBtn.disabled = false;
            }
        });

        document.addEventListener('submit', async (e) => {
            if (!e.target.matches('.subtask-template-add-form')) return;
            e.preventDefault();

            const form = e.target;
            const formData = new FormData(form);
            const submitBtn = form.querySelector('button');
            submitBtn.disabled = true;

            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                    body: formData,
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Request failed');

                const taskRow = document.querySelector('[data-task-row="' + form.dataset.taskId + '"]');
                const list = taskRow.querySelector(':scope > [data-subtask-list]');
                removeEmptyPlaceholder(list);
                list.insertAdjacentHTML('beforeend', data.html);
                initSortable(list);

                form.reset();
                const container = form.closest('[x-data]');
                if (container && window.Alpine) window.Alpine.$data(container).addSubtask = false;
                window.showToast('Sub Task added');
            } catch (err) {
                window.showToast(err.message || 'ไม่สามารถเพิ่ม Sub Task ได้ กรุณาลองใหม่', 'error');
            } finally {
                submitBtn.disabled = false;
            }
        });

        document.addEventListener('submit', async (e) => {
            if (!e.target.matches('.checklist-template-add-form')) return;
            e.preventDefault();

            const form = e.target;
            const formData = new FormData(form);
            const submitBtn = form.querySelector('button');
            submitBtn.disabled = true;

            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                    body: formData,
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Request failed');

                const subtaskRow = document.querySelector('[data-subtask-row="' + form.dataset.subtaskId + '"]');
                const list = subtaskRow.querySelector(':scope > [data-checklist-list]');
                removeEmptyPlaceholder(list);
                list.insertAdjacentHTML('beforeend', data.html);
                initSortable(list);

                form.reset();
                const container = form.closest('[x-data]');
                if (container && window.Alpine) window.Alpine.$data(container).addChecklist = false;
                window.showToast(data.count > 1 ? (data.count + ' checklists added') : 'Checklist added');
            } catch (err) {
                window.showToast(err.message || 'ไม่สามารถเพิ่ม Checklist ได้ กรุณาลองใหม่', 'error');
            } finally {
                submitBtn.disabled = false;
            }
        });

        // ---- Delete Task / Sub Task / Checklist (async, no reload) ----

        document.addEventListener('submit', async (e) => {
            const form = e.target;
            let row = null;
            let emptyInfo = null;
            let label = '';

            if (form.matches('.task-template-delete-form')) {
                row = form.closest('[data-task-row]');
                label = 'Task deleted';
            } else if (form.matches('.subtask-template-delete-form')) {
                row = form.closest('[data-subtask-row]');
                label = 'Sub Task deleted';
                emptyInfo = { container: row.closest('[data-task-row]').querySelector(':scope > [data-subtask-list]'), selector: '[data-subtask-row]', text: 'ยังไม่มี Sub Task', tag: 'p', className: 'px-4 py-3 text-xs text-slate-400' };
            } else if (form.matches('.checklist-template-delete-form')) {
                row = form.closest('[data-checklist-row]');
                label = 'Checklist deleted';
                emptyInfo = { container: row.closest('[data-subtask-row]').querySelector(':scope > [data-checklist-list]'), selector: '[data-checklist-row]', text: 'ยังไม่มี Checklist', tag: 'li', className: 'text-xs text-slate-300' };
            } else {
                return;
            }

            e.preventDefault();
            if (!confirm(form.dataset.confirm || 'ลบรายการนี้?')) return;

            const btn = form.querySelector('button');
            btn.disabled = true;

            try {
                const res = await fetch(form.action, {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                });
                if (!res.ok) {
                    const data = await res.json().catch(() => ({}));
                    throw new Error(data.message || 'Request failed');
                }

                row.remove();
                if (emptyInfo) {
                    maybeRestoreEmptyPlaceholder(emptyInfo.container, emptyInfo.selector, emptyInfo.text, emptyInfo.tag, emptyInfo.className);
                }
                window.showToast(label);
            } catch (err) {
                window.showToast(err.message || 'ไม่สามารถลบได้ กรุณาลองใหม่', 'error');
                btn.disabled = false;
            }
        });

        // ---- Drag & drop reordering (SortableJS) ----
        // Each level only reorders within its own parent container - Sortable's
        // `group` is left undefined per instance (default), so items can never
        // be dragged between different containers (different Sub Tasks,
        // different Tasks, etc.), only reordered within the one they started in.

        function persistOrder(container, attr, snapshot) {
            const url = container.dataset.reorderUrl;
            const items = [...container.querySelectorAll(':scope > [' + attr + ']')].map((el, index) => ({
                id: Number(el.getAttribute(attr)),
                sequence: index + 1,
            }));

            fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ items }),
            }).then((res) => {
                if (!res.ok) throw new Error('Request failed');
                window.showToast('Order updated');
            }).catch(() => {
                if (snapshot) snapshot.forEach((el) => container.appendChild(el));
                window.showToast('ไม่สามารถบันทึกลำดับได้ กรุณาลองใหม่', 'error');
            });
        }

        function initSortableList(container, attr) {
            if (!container || container.dataset.sortableInit) return;
            container.dataset.sortableInit = '1';

            let snapshot = null;

            new window.Sortable(container, {
                handle: '.drag-handle',
                animation: 150,
                filter: '[data-empty-placeholder]',
                // Pointer-based dragging instead of native HTML5 DnD - gives full
                // control over the drag/ghost styling below (native DnD's system
                // drag image can't be restyled) and works on touch devices.
                forceFallback: true,
                fallbackOnBody: true,
                fallbackClass: 'sortable-fallback',
                ghostClass: 'sortable-ghost',
                chosenClass: 'sortable-chosen',
                dragClass: 'sortable-drag',
                onStart: (evt) => {
                    snapshot = [...evt.from.children];
                },
                onEnd: () => {
                    persistOrder(container, attr, snapshot);
                    snapshot = null;
                },
            });
        }

        function initSortable(scope) {
            (scope || document).querySelectorAll('[data-task-list]').forEach((el) => initSortableList(el, 'data-task-row'));
            (scope || document).querySelectorAll('[data-subtask-list]').forEach((el) => initSortableList(el, 'data-subtask-row'));
            (scope || document).querySelectorAll('[data-checklist-list]').forEach((el) => initSortableList(el, 'data-checklist-row'));
        }

        document.addEventListener('DOMContentLoaded', () => initSortable(document));
    </script>
    @endpush
</x-app-layout>
