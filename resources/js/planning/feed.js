// Planning board · Remarks (every level of a project in one list), comments, and work waiting to be accepted.
// One part of the planningBoard Alpine component (see ./index.js); `this` is the whole component.
import { MONTHS, WEEKDAYS, PRIORITIES, TH_DAYS, PRIORITY_RANK, TH_MON_SHORT, AVATAR_COLORS, MAX_LANES, SHIP_COLOR, TL_DAY, pad, ymd, parse, addDays, diffDays, hexToRgba, beFormat, beParse, csrf } from './shared';

// a project's remarks, worked out once per copy of its cabinets (a new copy arrives with every save / poll)
let remarkMemo = new WeakMap();

export default ({ config, board, starKey, loadStars, t0 }) => ({
    // ---- checklist items: rename, remark, order ----
    clRename(item) {
        const text = prompt('ชื่อรายการ', item.text);
        if (text === null || !text.trim()) return;
        item.text = text.trim();
        this.send([{ type: 'checklist_rename', taskId: this.cl.taskId, itemId: item.id, text: item.text }]);
    },
    clRemark(item) {
        const remark = prompt('หมายเหตุของรายการนี้', item.remark || '');
        if (remark === null) return;
        item.remark = remark.trim();
        remarkMemo = new WeakMap();
        this.send([{ type: 'checklist_remark', taskId: this.cl.taskId, itemId: item.id, remark: item.remark }]);
    },
    clMove(item, dir) { this.send([{ type: 'checklist_move', taskId: this.cl.taskId, itemId: item.id, dir }]); },
    // ---- comments: edit / delete your own, reply, acknowledge an alert, view a file ----
    editComment(c) {
        const text = prompt('แก้ไขคอมเมนต์', c.text);
        if (text === null || !text.trim()) return;
        c.text = text.trim(); c.edited = true;
        this.send([{ type: 'comment_edit', taskId: this.cur.id, commentId: c.id, text: c.text }]);
    },
    deleteComment(c) {
        if (!confirm('ลบคอมเมนต์นี้?')) return;
        this.cur.comments = this.cur.comments.filter((x) => x.id !== c.id);
        this.send([{ type: 'comment_delete', taskId: this.cur.id, commentId: c.id }]);
    },
    startReply(c) { this.replyTo = c; this.$nextTick(() => this.$refs.commentBox && this.$refs.commentBox.focus()); },
    commentById(id) { return (this.cur ? this.cur.comments : []).find((c) => c.id === id) || null; },
    ackAlert(c) {
        if (c.alert) { c.alert.acked = true; c.alert.acks = c.alert.acks.concat({ name: this.me.name, at: Date.now() }); }
        if (c.receipt && c.receipt.askMe) { c.receipt = { ...c.receipt, askMe: false, acked: c.receipt.acked + 1, pending: c.receipt.pending.filter((n) => n !== this.me.name) }; }
        this.cur.alertPending = this.cur.comments.some((x) => (x.alert && x.alert.forMe && !x.alert.acked) || (x.receipt && x.receipt.askMe));
        this.send([{ type: 'alert_ack', taskId: this.cur.id, commentId: c.id }]);
    },
    openFile(f, ev) {
        if (!f.url) return;
        const pdf = /\.pdf$/i.test(f.name);
        if (!f.image && !pdf) return;   // other files: the link opens / downloads them as usual
        ev.preventDefault();
        this.viewer = { url: f.url, name: f.name, kind: f.image ? 'image' : 'pdf' };
    },
    // =================================================== modal / popovers
    // the open project lives in the address bar (?project=12): Back from a project / cabinet page, a reload or a copied
    // link comes back to it open; ?board= is kept, the one-off ?stage= / ?accept= of a notice are dropped
    projectInUrl(id) {
        try {
            const u = new URL(location.href);
            if (id) u.searchParams.set('project', String(id).replace(/^p/, '')); else u.searchParams.delete('project');
            u.searchParams.delete('stage'); u.searchParams.delete('accept');
            if (u.href !== location.href) history.replaceState(history.state, '', u);
        } catch (e) { /* old browser: just not remembered */ }
    },
    // ---- "หมายเหตุ": every remark of the project in one list - the cabinets', Tasks', Sub Tasks' and checklist items' ----
    // (only what this user was sent: PlanningBoard already keeps other departments' work out)
    remarkRows(t) {
        const subs = t && t.subtasks;
        if (!subs) return [];
        if (remarkMemo.has(subs)) return remarkMemo.get(subs);
        const rows = [];
        subs.forEach((c) => {
            const cab = { cabId: c.id, cabLabel: [c.mo, c.text].filter(Boolean).join(' · ') };
            if (c.remark) rows.push({ ...cab, key: c.id, level: 'ตู้', deptId: '', path: 'ข้อมูลตู้', text: c.remark, by: '' });
            (c.taskRemarks || []).forEach((r) => rows.push({ ...cab, key: c.id + r.id, level: 'Task', deptId: '', path: r.task, text: r.text, by: r.by, task: r.task }));
            (c.works || []).forEach((w) => {
                if (w.remark) rows.push({ ...cab, key: c.id + w.id, level: 'Sub Task', deptId: w.deptId, path: w.task + ' › ' + w.name, text: w.remark, by: w.remarkBy || '', subId: w.id });
                c.checklist.forEach((i) => {
                    if (i.subId === w.id && i.remark) rows.push({ ...cab, key: c.id + i.id, level: 'Checklist', deptId: w.deptId, path: w.task + ' › ' + w.name + ' › ' + i.text, text: i.remark, by: i.remarkBy || '', subId: w.id, itemId: i.id });
                });
            });
        });
        remarkMemo.set(subs, rows);
        return rows;
    },
    remarkCount(t) { return this.remarkRows(t).length; },
    remarkChip(on) { return 'inline-flex h-7 items-center gap-1.5 rounded-full border px-2.5 text-[12px] font-semibold ' + (on ? 'border-slate-800 bg-slate-800 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-slate-400'); },
    cabRemarkCount(t, c) { return this.remarkRows(t).filter((r) => r.cabId === c.id).length; },
    remarkDepts(t) { return [...new Set(this.remarkRows(t).map((r) => r.deptId).filter(Boolean))]; },
    remarkGroups(t) {
        const groups = [];
        this.remarkRows(t).filter((r) => (!this.remarkDept || r.deptId === this.remarkDept) && (!this.remarkCab || r.cabId === this.remarkCab)).forEach((r) => {
            let g = groups[groups.length - 1];
            if (!g || g.cabId !== r.cabId) { g = { cabId: r.cabId, label: r.cabLabel, rows: [] }; groups.push(g); }
            g.rows.push(r);
        });
        return groups;
    },
    openRemarks(taskId, cabId = '') {
        if (this.modalId !== taskId) this.openModal(taskId);
        this.cl = null;
        this.remarkCab = cabId;
        this.feedTab = 'remarks';
    },
    // "ไปที่รายการ": the cabinet's checklist window, scrolled to the Sub Task / item and flashed; a cabinet's own remark opens its record
    goRemark(t, r, ev) {
        const c = t.subtasks.find((x) => x.id === r.cabId);
        if (!c) return;
        if (r.level === 'ตู้') {
            if (!this.cabOpen[c.id]) this.toggleCab(t, c);
            return;
        }
        this.cl = null;
        this.openChecklist(t.id, c.id, ev);
        const key = r.itemId || r.subId;
        if (!key) return;
        setTimeout(() => {
            const el = document.querySelector('[data-cl-key="' + key + '"]');
            if (!el) return;
            el.scrollIntoView({ block: 'center' });
            el.classList.add('ring-2', 'ring-amber-400');
            setTimeout(() => el.classList.remove('ring-2', 'ring-amber-400'), 2000);
        }, 60);
    },
    openModal(id) { this.projectInUrl(id); this.modalId = id; this.replyTo = null; this.cabOpen = {}; this.feedTab = 'activity'; this.remarkDept = this.dept || ''; this.remarkCab = ''; this.logTree.id = null; this.stageDept = null; this.cl = null; this.comment = ''; this.alertDept = ''; this.scrollFeed(); },
    setModalSize(size) { this.modalSize = size; try { localStorage.setItem('planning.modalSize', size); } catch (e) { /* storage blocked: it just won't be remembered */ } },
    closeModal() { this.modalId = null; this.stageDept = null; this.cl = null; this.projectInUrl(null); },
    onEscape() { if (this.escHeld) { this.escHeld = false; return; } if (this.dn) { this.dn = null; return; } if (this.ship) { if (this.ship.panel) this.shipEsc(); else this.closeShip(); return; } if (this.dispatch) { this.dispatch = null; return; } if (this.cabDialog) { this.cabDialog = null; return; } if (this.viewer) { this.viewer = null; return; } if (this.statusPop) { this.statusPop = null; return; } if (this.resPop) { this.resPop = null; return; } if (this.newProject) { this.newProject = null; return; } if (this.drawer) { this.drawer = null; return; } if (this.cl) this.closeChecklist(); else if (this.stageDept) this.stageDept = null; else if (this.modalId) this.closeModal();  },
    // ---- รอรับงาน: work this user may accept (their department's - or any, for Admin / PM allowed to act for departments) ----
    waitingList() {
        return this.tasks.flatMap((t) => (t.deptStages || []).filter((s) => s.pmo && s.pmo.canAccept && (!this.dept || s.deptId === this.dept))
            .map((s) => ({ t, s, days: s.pmo.waitingSince ? Math.max(0, diffDays(this.today, s.pmo.waitingSince)) : 0 })))
            .sort((a, b) => b.days - a.days || String(a.s.end || '9999').localeCompare(String(b.s.end || '9999')));
    },
    waitingCount() { return this.waitingList().reduce((n, w) => n + w.s.pmo.waiting, 0); },
    // ---- "เลือกรับ…": pick which of a department's waiting work to accept (or send back) ----
    openPick(t, deptId) {
        const on = {};
        this.pickCabinets(t, deptId).forEach((c) => c.rows.forEach((r) => { on[r.id] = true; }));   // all ticked: accepting everything stays one click
        this.stageDept = null;
        this.pick = { t, deptId, on, view: 'cabinet', q: '', open: {} };   // cabinet by cabinet first; "ตามงาน" one click away
    },
    pickCabinets(t, deptId, q = '') {
        const needle = q.trim().toLowerCase();
        return (t.subtasks || []).map((c) => ({ id: c.id, mo: c.mo, name: c.text,
            rows: (c.works || []).filter((w) => w.deptId === deptId && w.state === 'ASSIGNED')
                .map((w) => ({ ...w, mo: c.mo, waited: w.since ? Math.max(0, diffDays(this.today, w.since)) : 0, soon: !!w.due && diffDays(w.due, this.today) <= 3 }))
                .filter((w) => !needle || (c.mo + ' ' + c.text + ' ' + w.task + ' ' + w.name).toLowerCase().includes(needle)) }))
            .filter((c) => c.rows.length);
    },
    // "ตามงาน": one row per Task › Sub Task, holding that work of every cabinet
    pickWorks() {
        const groups = new Map();
        this.pickCabinets(this.pick.t, this.pick.deptId, this.pick.q).forEach((c) => c.rows.forEach((r) => {
            const key = r.task + ' › ' + r.name, g = groups.get(key) || { key, task: r.task, name: r.name, rows: [], items: 0, soon: 0, due: null };
            g.rows.push(r); g.items += r.items; g.soon += r.soon ? 1 : 0;
            if (r.due && (!g.due || r.due < g.due)) g.due = r.due;
            groups.set(key, g);
        }));
        return [...groups.values()];
    },
    pickRows() { return this.pick ? this.pickCabinets(this.pick.t, this.pick.deptId, this.pick.q).flatMap((c) => c.rows) : []; },
    // what will be sent: every ticked one, the search only narrows what is shown
    pickIds() { return this.pick ? this.pickCabinets(this.pick.t, this.pick.deptId).flatMap((c) => c.rows).filter((r) => this.pick.on[r.id]).map((r) => r.id) : []; },
    pickTotal() { return this.pick ? this.pickCabinets(this.pick.t, this.pick.deptId).reduce((n, c) => n + c.rows.length, 0) : 0; },
    // a cabinet's block in "ตามตู้": open when opened, when searching, or when there are only a few cabinets
    pickCabOpen(c) { const o = this.pick.open['c' + c.id]; return o !== undefined ? o : (!!this.pick.q || this.pickCabinets(this.pick.t, this.pick.deptId).length <= 3); },
    pickSet(rows, value) { const on = { ...this.pick.on }; rows.forEach((r) => { on[r.id] = value; }); this.pick.on = on; },
    pickAllOn(rows) { return rows.length > 0 && rows.every((r) => this.pick.on[r.id]); },
    async pickSend(step) {
        const p = this.pick, ids = this.pickIds();
        if (!ids.length) return;
        let reason;
        if (step === 'reject') {
            reason = await window.askRejectReason({ title: 'ไม่รับงาน ' + ids.length + ' งาน', detail: [p.t.so, p.t.title, this.deptOf(p.deptId).name].filter(Boolean).join(' · ') });
            if (!reason) return;
        }
        this.pick = null;
        this.send([{ type: 'subtasks_step', taskId: p.t.id, step, ids, reason }]);
    },
    acceptAll() {
        const list = this.waitingList();
        if (!list.length || !confirm('รับงานทั้งหมด ' + this.waitingCount() + ' งาน ใน ' + new Set(list.map((w) => w.t.id)).size + ' โครงการ?\nหลังรับงานแล้วจะเปลี่ยนแผนกที่รับผิดชอบไม่ได้')) return;
        this.send(list.map((w) => ({ type: 'stage_step', taskId: w.t.id, deptId: w.s.deptId, step: 'accept' })));
    },
    // a notice's link: the department's window with its "รับงาน" button (and with ?accept=1, ask to accept right away)
    openStageFromLink(t, deptId, accept) {
        const st = this.stageOf(t, deptId), el = document.querySelector('[data-stage-chip="' + deptId + '"]');
        if (!st || !el) return;
        el.scrollIntoView({ block: 'center' });
        this.openStage(deptId, { currentTarget: el });
        if (!accept) return;
        if (st.pmo.canAccept) this.step(t, deptId, 'accept');
        else window.showToast(st.pmo.waiting ? 'คุณไม่ใช่สมาชิกแผนกนี้ — กดรับงานแทนไม่ได้' : 'งานของแผนกนี้รับไปแล้ว', 'info');
    },
    openStage(deptId, ev) {
        if (this.stageDept === deptId) { this.stageDept = null; return; }
        const r = ev.currentTarget.getBoundingClientRect();
        this.stagePos = { top: Math.min(r.bottom + 6, window.innerHeight - 330), left: Math.max(8, Math.min(r.left, window.innerWidth - 300)) };
        this.stageDept = deptId;
    },
});
