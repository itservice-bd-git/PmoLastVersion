// The cabinet page (cabinets/show): its checklists kept current, links to one Sub Task, "+ เพิ่ม Task / Sub Task" without
// a reload, the progress bars / status badges following every tick, and the status badge's menu. The page passes its
// values as window.pmoCabinetPage ({ liveUrl, version, cabinetId, newSubtask, newTask }); without them nothing here runs.
export default function registerCabinetPage() {
    const cfg = window.pmoCabinetPage;
    if (!cfg) return;

    // every list kept current without a reload: asked every 15 s while the tab is shown, and right after a change here
    // (a list shared with another Sub Task follows at once). Only a stamp comes back when nothing moved.
    (() => {
        const url = cfg.liveUrl;
        let version = cfg.version;
        let asking = false, again = false;
        async function check() {
            if (document.hidden) return;
            if (asking) { again = true; return; }
            asking = true;
            try {
                const res = await fetch(url + '?v=' + encodeURIComponent(version), { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;
                const d = await res.json();
                const moved = d.version !== version && d.lists;
                version = d.version;
                if (!moved) return;
                window.dispatchEvent(new CustomEvent('checklists-live', { detail: d }));
                (d.progress || []).forEach((p) => window.dispatchEvent(new CustomEvent('checklist-progress', { detail: p })));
            } catch (e) { /* offline for a moment: the next round tries again */ }
            finally {
                asking = false;
                if (again) { again = false; check(); }
            }
        }
        setInterval(check, 15000);
        document.addEventListener('visibilitychange', check);
        window.addEventListener('checklist-changed', () => setTimeout(check, 200));
    })();

    // one Sub Task (x-data): a link to it opens it. #subtask-{id} goes straight there (the browser's own jump);
    // #goto-subtask-{id} (from สรุปงาน) shows the top of the page first, then scrolls down to it, its name marked for
    // a few seconds (marked). The address is left with the plain #subtask-{id}, so a reload or a copied link goes
    // straight there.
    window.linkedSubtask = function linkedSubtask(id) {
        return {
            marked: false,
            init() {
                const el = this.$el;
                if (id === cfg.newSubtask) {   // just added: stay on it (not the top of the page), ready to type its first item
                    el.open = true;
                    setTimeout(() => {
                        el.scrollIntoView({ block: 'center' });
                        const draft = el.querySelector('input[x-ref="draft"]');
                        if (draft && draft.offsetParent) draft.focus({ preventScroll: true });
                    }, 300);
                    return;
                }
                if (location.hash === '#subtask-' + id) { el.open = true; return; }
                if (location.hash !== '#goto-subtask-' + id) return;
                el.open = true;
                this.marked = true;
                history.replaceState(history.state, '', '#subtask-' + id);
                const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
                setTimeout(() => el.scrollIntoView({ behavior, block: 'start' }), 400);
                setTimeout(() => { this.marked = false; }, 8000);
            },
        };
    }

    // "+ เพิ่ม Task / Sub Task": saved without leaving the page. The new one is taken from the page as the server now
    // draws it and put just above its "+ เพิ่ม" (Alpine starts it by itself), with its edit window and the bars above it.
    document.addEventListener('submit', async (e) => {
        const form = e.target.closest('form[data-add-inline]');
        if (!form) return;
        e.preventDefault();
        const kind = form.dataset.addInline;   // task | subtask
        const send = form.querySelector('button:not([type=button])');
        send.disabled = true;
        try {
            const res = await fetch(form.action, { method: 'POST', body: new FormData(form),
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.message || 'บันทึกไม่สำเร็จ กรุณาลองใหม่');
            const doc = new DOMParser().parseFromString(await (await fetch(location.pathname + location.search)).text(), 'text/html');
            const fresh = doc.getElementById((kind === 'task' ? 'task-card-' : 'subtask-') + data.id);
            if (!fresh) { location.reload(); return; }
            const box = form.closest('[x-data]');
            if (kind === 'subtask') box.parentElement.querySelector(':scope > p.text-slate-400')?.remove();   // "ยังไม่มี Sub Task"
            box.before(fresh);
            doc.querySelectorAll('[data-edit-modal="' + kind + '-' + data.id + '"]').forEach((m) => document.body.append(m));
            const taskId = kind === 'task' ? data.id : fresh.closest('[id^="task-card-"]').id.replace('task-card-', '');
            ['task-' + taskId, 'cabinet-' + cfg.cabinetId].forEach((k) => ['progress-value-', 'progress-fill-', 'status-badge-', 'checklist-count-'].forEach((pre) => {
                const now = document.getElementById(pre + k), next = doc.getElementById(pre + k);
                if (now && next && !fresh.contains(now)) now.replaceWith(next);
            }));
            form.reset();
            Alpine.$data(box).open = false;
            window.showToast(data.message);
            // ready for the next step: a new Task opens its "+ เพิ่ม Sub Task", a new Sub Task its checklist
            setTimeout(() => {
                if (kind === 'task') {
                    [...fresh.querySelectorAll('button')].find((b) => b.textContent.trim() === '+ เพิ่ม Sub Task')?.click();
                    setTimeout(() => fresh.querySelector('form[data-add-inline] input[name="name"]')?.focus({ preventScroll: true }), 50);
                } else {
                    fresh.open = true;
                    const draft = fresh.querySelector('input[x-ref="draft"]');
                    if (draft && draft.offsetParent) draft.focus({ preventScroll: true });
                }
            }, 50);
        } catch (err) {
            window.showToast(err.message, 'error');
        } finally {
            send.disabled = false;
        }
    });

    // a Task just added: stay on it (not the top of the page), its "+ เพิ่ม Sub Task" open and ready to type
    if (cfg.newTask) document.addEventListener('alpine:initialized', () => setTimeout(() => {
        const card = document.getElementById('task-card-' + cfg.newTask);
        if (!card) return;
        card.scrollIntoView({ block: 'center' });
        [...card.querySelectorAll('button')].find((b) => b.textContent.trim() === '+ เพิ่ม Sub Task')?.click();
    }, 300));

    // the checklist editor (x-checklist-editor) reports every change: the Sub Task / Task / cabinet bars follow
    window.addEventListener('checklist-progress', (e) => {
        const d = e.detail;
        ['subtask', 'task', 'cabinet'].forEach((k) => {
            if (!d[k]) return;
            updateProgress(k + '-' + d[k].id, d[k].progress);
            updateStatusBadge(k + '-' + d[k].id, d[k].status);
            updateChecklistCount(k + '-' + d[k].id, d[k].completed_checklists, d[k].total_checklists);
        });
        if (d.assignment && typeof applyAssignment === 'function') applyAssignment(d);   // Automation moved the work
    });

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

    // the Sub Task status badge's menu: open / close (a click in a <summary> would otherwise fold the Sub Task)
    function closeStatusMenus(except) {
        document.querySelectorAll('[data-status-menu]').forEach((box) => {
            if (box === except) return;
            box.querySelector('[data-status-menu-panel]')?.classList.add('hidden');
            box.querySelector('[data-status-menu-toggle]')?.setAttribute('aria-expanded', 'false');
        });
    }
    document.addEventListener('click', (e) => {
        const toggle = e.target.closest('[data-status-menu-toggle]');
        if (!toggle) {
            if (!e.target.closest('[data-status-menu-panel]') || e.target.closest('.assignment-action')) closeStatusMenus();
            return;
        }
        e.preventDefault();
        const box = toggle.closest('[data-status-menu]');
        const panel = box.querySelector('[data-status-menu-panel]');
        closeStatusMenus(box);
        const open = panel.classList.toggle('hidden') === false;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {   // fixed, just under the badge (the row wraps, so it can't hang off a parent)
            const r = toggle.getBoundingClientRect();
            panel.style.position = 'fixed';
            panel.style.top = (r.bottom + 4) + 'px';
            panel.style.left = Math.min(r.left, innerWidth - panel.offsetWidth - 16) + 'px';
        }
        if (open) panel.querySelector('button')?.focus();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeStatusMenus(); });
    addEventListener('scroll', () => closeStatusMenus(), { passive: true });

    function updateStatusBadge(id, status) {
        const badge = document.getElementById('status-badge-' + id);
        if (!badge) return;
        const [classes, label] = STATUS_MAP[status] || ['bg-slate-100 text-slate-600', status];
        badge.className = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium whitespace-nowrap ' + classes;
        badge.textContent = label;
    }
}
