<script>
    // Cabinet Quick Detail Panel - slides in from the right on the Project >
    // ตู้ไฟฟ้า tab instead of navigating to the full Cabinet page. Spread into
    // that page's existing root x-data (see projects/show.blade.php), not a
    // separate Alpine root, so it shares the same `tab` scope.
    //
    // Reuses the real assignment/progress/checklist system end to end - no
    // parallel logic:
    //   - cabinets.show (this same route, JSON instead of Blade) for data
    //   - cabinet-subtasks.accept/start/complete + .update (Assignment Modal)
    //   - checklists.toggle for checklist ticks
    //   - cabinets.partials._assignment + _assignment-scripts.blade.php,
    //     rendered server-side per Sub Task and shown via x-html inside this
    //     row's expanded detail - its delegated `document` listeners already
    //     handle whatever this injects, and now also dispatch
    //     'pmo:assignment-updated' (see _assignment-scripts.blade.php) which
    //     this component listens for to keep its own reactive row summary
    //     (badge/department text in the COLLAPSED row) in step with whatever
    //     that shared script just changed in the DOM.
    function cabinetQuickPanel() {
        return {
            cabinetPanelOpen: false,
            cabinetPanelLoading: false,
            cabinetPanelError: false,
            cabinetPanelUrl: null,
            cabinetDetail: null, // { cabinet, tasks, departments, can_dispatch_work }
            expandedTasks: {}, // task_id -> bool
            expandedSubtaskId: null, // at most one Sub Task's inline detail open at a time
            hideCompletedSubtasks: false,

            assignModalOpen: false,
            assignTarget: null, // the subtask object (from cabinetDetail) being assigned/edited
            assignForm: { department_id: '', start_date: '', due_date: '', remark: '' },
            assignSaving: false,

            panelTab: 'tasks', // 'tasks' | 'attachments'
            attachmentFile: null,
            attachmentDescription: '',
            attachmentUploading: false,

            init() {
                document.addEventListener('pmo:assignment-updated', (e) => this.applyAssignmentEvent(e.detail));
            },

            csrf() {
                return document.querySelector('meta[name="csrf-token"]').content;
            },

            // ---- Panel open/close ----
            openCabinetPanel(cabinetId, showUrl) {
                this.cabinetPanelOpen = true;
                this.cabinetPanelUrl = showUrl;
                this.cabinetDetail = null;
                this.cabinetPanelError = false;
                this.expandedTasks = {};
                this.expandedSubtaskId = null;
                this.panelTab = 'tasks';
                this.attachmentFile = null;
                this.attachmentDescription = '';
                this.loadCabinet();
            },
            closeCabinetPanel() {
                this.cabinetPanelOpen = false;
            },
            // ESC closes whichever layer is on top first - the Assignment
            // Modal, if open, rather than the whole panel underneath it.
            handleEscape() {
                if (this.assignModalOpen) {
                    this.closeAssignModal();
                    return;
                }
                this.closeCabinetPanel();
            },
            async loadCabinet() {
                this.cabinetPanelLoading = true;
                this.cabinetPanelError = false;
                try {
                    const res = await fetch(this.cabinetPanelUrl, { headers: { Accept: 'application/json' } });
                    if (!res.ok) throw new Error();
                    this.cabinetDetail = await res.json();
                } catch (e) {
                    this.cabinetPanelError = true;
                } finally {
                    this.cabinetPanelLoading = false;
                }
            },

            // ---- Task accordion (point 9: every Task starts collapsed, no auto-expand) ----
            toggleTask(taskId) {
                this.expandedTasks[taskId] = !this.expandedTasks[taskId];
            },
            // Presentation-only filter (point 8) - Task/Cabinet totals elsewhere
            // always read task.subtasks.length / the server's own counts, never this.
            visibleSubtasks(task) {
                if (!this.hideCompletedSubtasks) return task.subtasks;
                return task.subtasks.filter((s) => s.status !== 'completed');
            },
            toggleSubtaskDetail(subtaskId) {
                this.expandedSubtaskId = this.expandedSubtaskId === subtaskId ? null : subtaskId;
            },

            // ---- Presentation helpers (Work Status - distinct from Assignment Status, point 12) ----
            workStatusLabel(status) {
                return { not_started: 'ยังไม่เริ่ม', in_progress: 'กำลังทำ', completed: 'เสร็จ', on_hold: 'พัก' }[status] || status;
            },
            workStatusBadgeClass(status) {
                return {
                    not_started: 'bg-slate-100 text-slate-500',
                    in_progress: 'bg-blue-100 text-blue-700',
                    completed: 'bg-emerald-100 text-emerald-700',
                    on_hold: 'bg-amber-100 text-amber-700',
                }[status] || 'bg-slate-100 text-slate-500';
            },
            subtaskNameClass(s) {
                if (s.status === 'completed') return 'text-slate-400 font-normal';
                if (s.is_overdue) return 'text-slate-900 font-semibold';
                if (s.status === 'in_progress') return 'text-slate-800 font-medium';
                return 'text-slate-700 font-medium';
            },
            // A legacy Sub Task can be Completed with no Assignment at all
            // (point 22) - never show [จ่ายงาน] as if work still needs dispatching.
            needsAssignment(s) {
                return s.assignment_status === 'UNASSIGNED' && s.status !== 'completed';
            },
            // Server already computed days_remaining (Cabinet/Sub Task date-only,
            // timezone-safe) - this only formats it, no date math duplicated here.
            remainingDaysInfo(daysRemaining, isComplete) {
                if (daysRemaining === null || daysRemaining === undefined) return null;
                const n = daysRemaining;
                if (n < 0) {
                    if (isComplete) return null;
                    return { text: 'เกินกำหนด ' + Math.abs(n) + ' วัน', class: 'text-red-600 font-medium' };
                }
                if (n === 0) return { text: 'ครบกำหนดวันนี้', class: 'text-orange-600 font-medium' };
                if (n <= 3) return { text: 'เหลือ ' + n + ' วัน', class: 'text-orange-600 font-medium' };
                return { text: 'เหลือ ' + n + ' วัน', class: 'text-slate-400' };
            },
            fmt(s) {
                return s ? s.split('-').reverse().join('/') : '-';
            },

            // ---- Assignment Modal (point 14/15 - Department + Start/Due + Remark,
            // the exact field set cabinet-subtasks.update already supports, nothing
            // invented) ----
            openAssignModal(subtask) {
                this.assignTarget = subtask;
                this.assignForm = {
                    department_id: subtask.department_id ?? '',
                    start_date: subtask.start_date ?? '',
                    due_date: subtask.due_date ?? '',
                    remark: subtask.remark ?? '',
                };
                this.assignModalOpen = true;
            },
            closeAssignModal() {
                this.assignModalOpen = false;
                this.assignTarget = null;
            },
            async submitAssign() {
                if (!this.assignTarget) return;
                this.assignSaving = true;
                try {
                    const res = await fetch(this.assignTarget.urls.update, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrf(),
                        },
                        body: JSON.stringify({
                            // Passed through unchanged - this modal only edits
                            // Department/Start/Due/Remark, never Work Status/Owner.
                            status: this.assignTarget.status,
                            owner_id: this.assignTarget.owner_id,
                            department_id: this.assignForm.department_id || null,
                            start_date: this.assignForm.start_date || null,
                            due_date: this.assignForm.due_date || null,
                            remark: this.assignForm.remark || null,
                        }),
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(data.message || 'บันทึกไม่สำเร็จ กรุณาลองใหม่');

                    // Dates/remark aren't part of the shared assignment payload/html -
                    // patch them into local state directly; department/status fields
                    // flow through the same applyAssignment() the inline row uses, via
                    // the pmo:assignment-updated event it now dispatches.
                    this.assignTarget.start_date = this.assignForm.start_date || null;
                    this.assignTarget.due_date = this.assignForm.due_date || null;
                    this.assignTarget.remark = this.assignForm.remark || null;
                    if (data.assignment && typeof applyAssignment === 'function') {
                        applyAssignment(data);
                    }
                    window.showToast(data.message || 'บันทึกเรียบร้อยแล้ว');
                    this.closeAssignModal();
                } catch (err) {
                    window.showToast(err.message, 'error');
                } finally {
                    this.assignSaving = false;
                }
            },
            // Keeps the collapsed row's own reactive summary (badge/department
            // text) in step with _assignment-scripts.blade.php's shared handler,
            // whether that ran from this panel's inline detail view or from this
            // same Sub Task rendered on the Cabinet full page/Dispatch page in
            // another tab - applyAssignment() already updated the live DOM; this
            // just mirrors the same fields into this component's own state.
            applyAssignmentEvent(data) {
                if (!this.cabinetDetail || !data?.assignment) return;
                for (const task of this.cabinetDetail.tasks) {
                    const subtask = task.subtasks.find((s) => s.id === data.assignment.id);
                    if (subtask) {
                        Object.assign(subtask, data.assignment);
                        if (data.html) subtask.assignment_html = data.html;
                        return;
                    }
                }
            },

            // ---- Checklist (reuses checklists.toggle verbatim - same endpoint
            // My Department's toggleChecklist() calls, same ProgressService
            // rollup; this just also syncs the Cabinet Card left behind on the
            // Project page, point 33) ----
            async toggleChecklistItem(subtask, checklist, event) {
                if (!subtask.checklist_editable || checklist.busy) return;
                const want = event.target.checked;
                checklist.busy = true;
                try {
                    const res = await fetch(checklist.toggle_url, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrf(),
                        },
                        body: JSON.stringify({ is_completed: want }),
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(data.message || 'บันทึกไม่สำเร็จ');

                    checklist.is_completed = data.checklist.is_completed;
                    checklist.completed_by_name = data.checklist.completed_by_name;
                    checklist.completed_at_formatted = data.checklist.completed_at_formatted;

                    subtask.status = data.subtask.status;
                    subtask.checklists_completed = data.subtask.completed_checklists;
                    subtask.checklists_total = data.subtask.total_checklists;

                    const task = this.cabinetDetail.tasks.find((t) => t.id === data.task.id);
                    if (task) task.progress = data.task.progress;

                    if (this.cabinetDetail.cabinet.id === data.cabinet.id) {
                        this.cabinetDetail.cabinet.progress = data.cabinet.progress;
                        this.cabinetDetail.cabinet.checklist_counts = {
                            completed: data.cabinet.completed_checklists,
                            total: data.cabinet.total_checklists,
                        };
                        this.syncCabinetCard(data.cabinet.id, data.cabinet.progress);
                    }
                } catch (err) {
                    event.target.checked = !want;
                    window.showToast(err.message, 'error');
                } finally {
                    checklist.busy = false;
                }
            },
            // Updates the Project page's own Cabinet card behind the panel (point
            // 33) - plain DOM writes to the same id="cabinet-{id}" progress bar/
            // value elements the card already renders, no reload, no re-fetch.
            syncCabinetCard(cabinetId, progress) {
                const fill = document.getElementById('progress-fill-cabinet-' + cabinetId);
                if (fill) {
                    fill.style.width = progress + '%';
                    fill.classList.remove('bg-emerald-500', 'bg-blue-500', 'bg-amber-500', 'bg-slate-300');
                    fill.classList.add(progress >= 100 ? 'bg-emerald-500' : progress >= 60 ? 'bg-blue-500' : progress >= 30 ? 'bg-amber-500' : 'bg-slate-300');
                }
                const value = document.getElementById('cabinet-card-progress-' + cabinetId);
                if (value) value.textContent = progress + '%';
            },

            // ---- Attachments tab (reuses cabinet-attachments.store/destroy
            // verbatim - no new attachment system, point 7) ----
            async uploadAttachment() {
                if (!this.attachmentFile) return;
                this.attachmentUploading = true;
                try {
                    const formData = new FormData();
                    formData.append('file', this.attachmentFile);
                    if (this.attachmentDescription) formData.append('description', this.attachmentDescription);
                    const res = await fetch(this.cabinetDetail.cabinet.attachments_upload_url, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        body: formData,
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(data.message || 'อัปโหลดไม่สำเร็จ');
                    this.cabinetDetail.cabinet.attachments.unshift(data.attachment);
                    this.attachmentFile = null;
                    this.attachmentDescription = '';
                    this.$refs.attachmentFileInput && (this.$refs.attachmentFileInput.value = '');
                    window.showToast(data.message);
                } catch (err) {
                    window.showToast(err.message, 'error');
                } finally {
                    this.attachmentUploading = false;
                }
            },
            async deleteAttachment(attachment) {
                if (!confirm('ลบไฟล์ "' + attachment.original_name + '"?')) return;
                try {
                    const res = await fetch(attachment.destroy_url, {
                        method: 'DELETE',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(data.message || 'ลบไม่สำเร็จ');
                    this.cabinetDetail.cabinet.attachments = this.cabinetDetail.cabinet.attachments.filter((a) => a.id !== attachment.id);
                    window.showToast(data.message);
                } catch (err) {
                    window.showToast(err.message, 'error');
                }
            },
        };
    }
</script>
