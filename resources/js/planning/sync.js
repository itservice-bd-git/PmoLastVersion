// Planning board · Saving: one operation per user action to planning.sync, the page updated first, the server's answer applied.
// One part of the planningBoard Alpine component (see ./index.js); `this` is the whole component.
import { MONTHS, WEEKDAYS, PRIORITIES, TH_DAYS, PRIORITY_RANK, TH_MON_SHORT, AVATAR_COLORS, MAX_LANES, SHIP_COLOR, TL_DAY, pad, ymd, parse, addDays, diffDays, hexToRgba, beFormat, beParse, csrf } from './shared';

export default ({ config, board, starKey, loadStars, t0 }) => ({
    // =================================================== saving: one operation per action
    async post(ops, files) {
        if (files && files.length) {   // a comment with photos / files: multipart, to its own endpoint
            const o = ops[0], fd = new FormData();
            fd.append('taskId', o.taskId); fd.append('text', o.text || '');
            if (o.alertDept) fd.append('alertDept', o.alertDept);
            (o.mentions || []).forEach((id) => fd.append('mentions[]', id));
            if (o.replyTo) fd.append('replyTo', o.replyTo);
            files.forEach((f) => fd.append('files[]', f));
            const r = await fetch(this.urls.comment, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: fd });
            if (r.status === 419 || r.status === 401) throw new Error('หมดเวลาเข้าสู่ระบบ — รีเฟรชหน้านี้แล้วเข้าสู่ระบบใหม่');
            const d = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error((d.errors && Object.values(d.errors).flat()[0]) || d.message || ('เกิดข้อผิดพลาด ' + r.status));
            return d;
        }
        const res = await fetch(this.urls.sync, { method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ ops }) });
        if (res.status === 419 || res.status === 401) throw new Error('หมดเวลาเข้าสู่ระบบ — รีเฟรชหน้านี้แล้วเข้าสู่ระบบใหม่');
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || ('เกิดข้อผิดพลาด ' + res.status));
        return data;
    },
    // Sends ops one batch at a time (in order). Optimistic edits are made by the caller BEFORE this runs.
    send(ops, files) {
        const ids = [...new Set(ops.map((o) => o.taskId).filter(Boolean))];
        const release = () => ids.forEach((id) => { this.pending[id] = Math.max(0, (this.pending[id] || 1) - 1); });
        ids.forEach((id) => { this.pending[id] = (this.pending[id] || 0) + 1; });
        this.saving++;
        this.queue = this.queue
            .then(() => this.post(ops, files))
            .then((data) => {
                release();
                const msgs = (data.results || []).filter((r) => r.message).map((r) => (r.ok ? '' : '⚠️ ') + r.message);
                if (msgs.length) window.showToast([...new Set(msgs)].slice(0, 3).join(' · '), (data.results || []).some((r) => !r.ok) ? 'error' : 'success');
                this.apply(data);
                this.savedAt = new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
            })
            .catch((err) => { release(); window.showToast('บันทึกไม่สำเร็จ: ' + err.message, 'error'); return this.refresh(true); })
            .finally(() => { this.saving--; });
        return this.queue;
    },
    // merge the server's answer: replace only the projects it sent, and never one that has a newer edit still queued
    apply(data) {
        const before = this.cur ? this.cur.comments.length : null;
        const busy = (id) => (this.pending[id] || 0) > 0;
        if (data.partial) {
            (data.tasks || []).forEach((nt) => {
                if (busy(nt.id)) return;
                if ((nt.boardNo ?? null) !== this.boardNo) {   // moved to another board: it leaves this one
                    const k = this.tasks.findIndex((t) => t.id === nt.id);
                    if (k >= 0) this.tasks.splice(k, 1);
                    if (this.modalId === nt.id) this.closeModal();
                    return;
                }
                const i = this.tasks.findIndex((t) => t.id === nt.id);
                if (i >= 0) this.tasks.splice(i, 1, nt); else this.tasks.push(nt);
            });
            (data.removed || []).forEach((id) => {
                if (busy(id)) return;
                const i = this.tasks.findIndex((t) => t.id === id);
                if (i >= 0) this.tasks.splice(i, 1);
                if (this.modalId === id) this.closeModal();
            });
        } else if (data.root) {
            this.replaceAll(data.root.boards[config.boardId]);
        }
        if (data.created && this.taskById(data.created)) this.openModal(data.created);   // a project just created / copied / imported
        if (before !== null && this.cur && this.cur.comments.length > before) this.scrollFeed();
    },
    replaceAll(b) {
        const keep = new Map(this.tasks.filter((t) => (this.pending[t.id] || 0) > 0).map((t) => [t.id, t]));
        this.tasks = b.tasks.map((t) => keep.get(t.id) || t);
        this.departments = b.departments;
        this.statuses = b.statuses;
        this.pmoStatusList = b.pmoStatusList || [];
        this.labels = b.labels || [];   // names / colours may have been changed in Settings since this page loaded
        this.ckStatuses = b.checklistStatuses || [];
        if (this.modalId && !this.taskById(this.modalId)) this.closeModal();
    },
    async refresh(force) {
        try {
            const res = await fetch(this.urls.data, { headers: Object.assign({ 'Accept': 'application/json' }, (!force && this.etag) ? { 'If-None-Match': this.etag } : {}) });
            if (res.status === 304 || !res.ok) return;
            this.etag = res.headers.get('ETag');
            this.replaceAll((await res.json()).root.boards[config.boardId]);
        } catch (e) { /* offline: keep what we have */ }
    },

    // =================================================== editing (each one: update the page now, then one operation)
    editProject(t, fields) {
        const prev = {};
        Object.keys(fields).forEach((k) => { prev[k] = t[k]; t[k] = fields[k]; });
        this.send([{ type: 'project', taskId: t.id, fields }]);
    },
    setStageDates(t, deptId, start, end) {
        const s = this.stageOf(t, deptId);
        if (!s) return;
        s.start = start; s.end = end; if (end) s.due = end;
        this.send([{ type: 'stage_dates', taskId: t.id, deptId, start, due: end }]);
    },
    setDue(t, deptId, iso) {
        const s = this.stageOf(t, deptId);
        if (!s) return;
        s.due = iso;
        this.send([{ type: 'stage_due', taskId: t.id, deptId, due: iso }]);
    },
    setCabinetDue(t, c, iso) {
        c.date = iso;
        this.send([{ type: 'cabinet_due', taskId: t.id, cabinetId: c.id, due: iso }]);
    },
    tick(t, c, item) {
        if (this.readOnly) return;
        item.done = !item.done;
        this.send([{ type: 'checklist', taskId: t.id, cabinetId: c.id, itemId: item.id, done: item.done }]);
    },
    // the box on a Sub Task in the checklist window: tick all its items, or untick all once every one is ticked
    clTickAll(w) {
        if (this.readOnly) return;
        const done = w.done < w.items.length;
        const todo = w.items.filter((i) => !!i.done !== done);
        if (!todo.length || !confirm((done ? 'ติ๊กทั้งหมด ' : 'เอาเครื่องหมายออกทั้งหมด ') + todo.length + ' รายการใน "' + w.name + '"?')) return;
        todo.forEach((i) => { i.done = done; });
        this.send(todo.map((i) => ({ type: 'checklist', taskId: this.clTask.id, cabinetId: this.clCab.id, itemId: i.id, done })));
    },
    // extra fields per department (Settings > ฟิลด์เพิ่มเติม): the value is kept on the department's stage of the project
    canEditStage(deptId) { return !this.readOnly && (this.isPmo || this.myDepts.includes(deptId)); },
    fieldValue(t, deptId, f) { const s = this.stageOf(t, deptId); return (s && s.fields && s.fields[f.id]) || ''; },
    fieldOptions(f) { return (f.groups || []).flatMap((g) => (g.options || []).map((o) => ({ id: o.id, text: (g.name ? g.name + ' · ' : '') + o.label }))); },
    setField(t, deptId, f, value) {
        const s = this.stageOf(t, deptId);
        if (!s) return;
        if (!s.fields) s.fields = {};
        if (value) s.fields[f.id] = value; else delete s.fields[f.id];
        this.send([{ type: 'stage_field', taskId: t.id, deptId, fieldId: f.id, value }]);
    },
    async step(t, deptId, step) {
        if (step === 'accept' && !confirm('ยืนยันรับงาน? หลังรับงานแล้วจะเปลี่ยนแผนกที่รับผิดชอบไม่ได้')) return;
        let reason;
        if (step === 'reject') {   // "ไม่รับงาน": every piece still waiting for this department in the project goes back to PMO
            const s = this.stageOf(t, deptId);
            reason = await window.askRejectReason({ title: 'ไม่รับงาน' + (s && s.pmo && s.pmo.waiting ? ' ' + s.pmo.waiting + ' งาน' : ''),
                detail: [t.so, t.title, this.deptOf(deptId).name].filter(Boolean).join(' · ') });
            if (!reason) return;
        }
        this.stageDept = null;
        this.send([{ type: 'stage_step', taskId: t.id, deptId, step, reason }]);
    },
});
