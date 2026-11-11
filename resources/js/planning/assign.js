// Planning board · Handing work out: a new cabinet from a template, dates per department, sub tasks with no department yet, new / copied / restored projects.
// One part of the planningBoard Alpine component (see ./index.js); `this` is the whole component.
import { MONTHS, WEEKDAYS, PRIORITIES, TH_DAYS, PRIORITY_RANK, TH_MON_SHORT, AVATAR_COLORS, MAX_LANES, SHIP_COLOR, TL_DAY, pad, ymd, parse, addDays, diffDays, hexToRgba, beFormat, beParse, csrf } from './shared';

export default ({ config, board, starKey, loadStars, t0 }) => ({
    // ---- a new cabinet from a template, its sub tasks handed to departments in the same window ----
    templateOf(id) { return this.templates.find((x) => String(x.id) === String(id)) || null; },
    openCabDialog(t) {
        const def = this.templates.find((x) => x.default) || this.templates[0];
        this.cabDialog = { taskId: t.id, mo: '', text: '', templateId: def ? String(def.id) : 'none', assign: {}, dates: {}, cabDue: {},
            cabStart: '', cabEnd: t.date || '', start: '', due: t.date || '', dueMode: 'same', beforeDays: 0, deptDates: {} };
        this.pickTemplate();
    },
    pickTemplate() {   // the template's own departments are the starting choice
        const d = this.cabDialog, tpl = this.templateOf(d.templateId);
        d.assign = {}; d.dates = {};
        (tpl ? tpl.subtasks : []).forEach((s) => { d.assign[s.id] = s.deptId || ''; d.dates[s.id] = { start: d.start, due: d.due }; });
    },
    // ---- dates of the work being handed out: one date for all, the cabinet's own due date, or N days before it;
    //      every row stays editable afterwards (each piece of work may have its own dates) ----
    applyDates(box) {
        const before = (iso, n) => { if (!iso) return ''; const d = parse(iso); d.setDate(d.getDate() - (parseInt(n, 10) || 0)); return ymd(d); };
        Object.keys(box.dates).forEach((k) => {
            const cab = box.cabDue[k] || (box === this.cabDialog ? box.cabEnd : '');
            const due = box.dueMode === 'cabinet' ? (cab || box.due) : box.dueMode === 'before' ? (cab ? before(cab, box.beforeDays) : box.due) : box.due;
            box.dates[k] = { start: box.start, due };
        });
    },
    // a row of the simple "+ เพิ่มแผนก" list covers the same work in several cabinets: show its date when they agree
    groupDate(g, key) { const v = [...new Set(g.ids.map((id) => (this.dispatch.dates[id] || {})[key] || ''))]; return v.length === 1 ? v[0] : ''; },
    setGroupDate(g, key, value) { g.ids.forEach((id) => { this.dispatch.dates[id] = { ...(this.dispatch.dates[id] || {}), [key]: value }; }); },
    // ---- dates when handing work out: ONE start - end per department per project (the department orders its own work) ----
    chosenDepts(box) {
        if (box === this.dispatch && box.preset && !box.perCabinet) return [box.preset];
        return [...new Set(Object.values(box.assign).filter(Boolean))];
    },
    deptRange(box, deptId) {
        if (!box.deptDates[deptId]) {
            const t = this.taskById(box.taskId), s = t && this.stageOf(t, deptId);
            // the department's range already in this project, else the project's own dates
            box.deptDates[deptId] = { start: (s && s.end ? s.start : t && t.startDate) || '', due: (s && s.end ? s.end : t && t.date) || '' };
        }
        return box.deptDates[deptId];
    },
    setAllDates(box) { this.applyDates(box); },
    setAllCabDepts(deptId) { Object.keys(this.cabDialog.assign).forEach((k) => { this.cabDialog.assign[k] = deptId; }); },
    addCabinet() {
        const d = this.cabDialog;
        if (!d || !d.mo.trim() || !d.text.trim()) return;
        const assign = Object.fromEntries(Object.entries(d.assign).filter(([, v]) => v).map(([k, v]) => [k, { dept: v, start: this.deptRange(d, v).start || null, due: this.deptRange(d, v).due || null }]));
        this.send([{ type: 'cabinet_add', taskId: d.taskId, mo: d.mo.trim(), text: d.text.trim(), templateId: d.templateId, assign, startDate: d.cabStart || null, dueDate: d.cabEnd || null }]);
        this.cabDialog = null;
    },
    // ---- handing out sub tasks that have no department yet (from a template), per cabinet or for the whole project ----
    freeCount(t) { return (t.subtasks || []).reduce((n, c) => n + (c.free || []).length, 0); },
    openDispatch(t, cabId, preset) {
        const assign = {};
        const dates = {}, cabDue = {}, due = t.date || '';
        (t.subtasks || []).filter((c) => !cabId || c.id === cabId).forEach((c) => (c.free || []).forEach((s) => { assign[s.id] = ''; cabDue[s.id] = c.ownDue || ''; dates[s.id] = { start: '', due: c.ownDue || due }; }));
        this.stageDept = null;
        // each cabinet's own due date is the starting point when the cabinets have one
        const mode = Object.values(cabDue).some((v) => v) ? 'cabinet' : 'same';
        this.dispatch = { taskId: t.id, cabId: cabId || null, preset: preset || '', assign, dates, cabDue, start: '', due, dueMode: mode, beforeDays: 0, picked: {}, perCabinet: false, deptDates: {} };
        if (preset) this.suggestFor(preset);
    },
    // "+ เพิ่มแผนก": the same piece of work sits in every cabinet made from one template -> one row per piece of work,
    // ticking it hands that work to the department in every cabinet at once
    dispatchGroups() {
        const groups = new Map();
        this.dispatchCabinets().forEach((c) => (c.free || []).forEach((s) => {
            const key = s.task + ' › ' + s.name;
            const g = groups.get(key) || { key, task: s.task, name: s.name, ids: [], checklists: 0, usual: '', rejected: '' };
            g.ids.push(s.id); g.checklists += s.checklists; g.usual = g.usual || s.usual || ''; g.rejected = g.rejected || s.rejected || '';
            groups.set(key, g);
        }));
        return [...groups.values()];
    },
    // tick what is this department's work: what the same piece of work was usually given to before (PMO's history);
    // when there is no history, the department's code (e.g. BUSBAR) in the work's name
    suggestFor(deptId) {
        const code = (this.deptOf(deptId).code || '').toLowerCase(), picked = {};
        this.dispatchGroups().forEach((g) => {
            if (g.usual ? g.usual === deptId : (code.length >= 3 && (g.task + ' ' + g.name).toLowerCase().includes(code))) picked[g.key] = true;
        });
        this.dispatch.picked = picked;
    },
    setPreset(deptId) { this.dispatch.preset = deptId; if (deptId) this.suggestFor(deptId); },
    pickAllGroups(on) { const p = {}; if (on) this.dispatchGroups().forEach((g) => { p[g.key] = true; }); this.dispatch.picked = p; },
    pickedCount() { const d = this.dispatch; return this.dispatchGroups().filter((g) => d.picked[g.key]).reduce((n, g) => n + g.ids.length, 0); },
    dispatchCabinets() {
        const d = this.dispatch, t = d && this.taskById(d.taskId);
        return t ? t.subtasks.filter((c) => (!d.cabId || c.id === d.cabId) && (c.free || []).length) : [];
    },
    setCabDispatch(c, deptId) { (c.free || []).forEach((s) => { this.dispatch.assign[s.id] = deptId; }); },
    sendDispatch() {
        const d = this.dispatch;
        if (d.preset && !d.perCabinet) {   // ticked pieces of work -> that department, in every cabinet
            this.dispatchGroups().filter((g) => d.picked[g.key]).forEach((g) => g.ids.forEach((id) => { d.assign[id] = d.preset; }));
        }
        const assign = Object.fromEntries(Object.entries(d.assign).filter(([, v]) => v).map(([k, v]) => [k, {
            dept: v, start: this.deptRange(d, v).start || null, due: this.deptRange(d, v).due || null }]));
        this.dispatch = null;
        if (Object.keys(assign).length) this.send([{ type: 'subtask_assign', taskId: d.taskId, assign }]);
    },
    newWorkForDept() {   // nothing from the template fits: one new sub task "งาน <แผนก>" in every cabinet (no checklist)
        const d = this.dispatch;
        this.dispatch = null;
        this.send([{ type: 'stage_add', taskId: d.taskId, deptId: d.preset, start: this.deptRange(d, d.preset).start || null, due: this.deptRange(d, d.preset).due || null }]);
    },
    renameCabinet(t, c) {
        const mo = prompt('เลข MO', c.mo); if (mo === null) return;
        const text = prompt('ชื่อตู้', c.text); if (text === null) return;
        if (!mo.trim() || !text.trim()) return;
        c.mo = mo.trim(); c.text = text.trim();
        this.send([{ type: 'cabinet_rename', taskId: t.id, cabinetId: c.id, mo: c.mo, text: c.text }]);
    },
    deleteCabinet(t, c) {
        if (!confirm('ลบตู้ ' + c.mo + ' ' + c.text + '? (กู้คืนได้ที่ถังขยะ)')) return;
        t.subtasks = t.subtasks.filter((x) => x.id !== c.id);
        this.send([{ type: 'cabinet_delete', taskId: t.id, cabinetId: c.id }]);
    },
    setBoard(t, boardId) { this.send([{ type: 'board_set', taskId: t.id, boardId }]); },
    // ---- new / copied / restored / imported projects (PMO roles; the trash: who may manage it) ----
    openNewProject() {
        this.newProject = { so: '', title: '', customer: '', customerPhone: '', startDate: '', date: '', priority: 'none', managerId: '', jobTypeId: '', contractValue: '', margin: '', boardId: this.boardNo ? 'b' + this.boardNo : '' };
        this.$nextTick(() => this.$refs.newSo && this.$refs.newSo.focus());
    },
    createProject() {
        const f = this.newProject;
        if (!f || !f.so.trim() || !f.title.trim() || !f.customer.trim()) return;
        this.send([{ type: 'project_create', so: f.so.trim(), title: f.title.trim(), customer: f.customer.trim(), customerPhone: f.customerPhone.trim(), startDate: f.startDate || null, date: f.date || null,
            priority: f.priority, managerId: f.managerId || null, jobTypeId: f.jobTypeId || null, contractValue: f.contractValue, margin: f.margin, boardId: f.boardId }]);
        this.newProject = null;
    },
    duplicateProject(t) {
        const so = (prompt('เลขที่โครงการใหม่ (คัดลอกจาก ' + t.so + ' พร้อมตู้ งาน และ checklist)') || '').trim();
        if (!so) return;
        const title = prompt('ชื่อโครงการใหม่', t.title + ' (สำเนา)');
        if (title === null) return;
        this.send([{ type: 'project_duplicate', taskId: t.id, so, title: title.trim() }]);
    },
    restoreProject(p) {
        this.trash = this.trash.filter((x) => x.id !== p.id);
        this.send([{ type: 'project_restore', taskId: p.id }]);
    },
    starredTasks() { return this.tasks.filter((t) => this.isStarred(t)); },
});
