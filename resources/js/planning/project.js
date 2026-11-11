// Planning board · The project window: pickers, status, tags, people, extra fields, the record, money, an expanded cabinet, what is missing, printing.
// One part of the planningBoard Alpine component (see ./index.js); `this` is the whole component.
import { MONTHS, WEEKDAYS, PRIORITIES, TH_DAYS, PRIORITY_RANK, TH_MON_SHORT, AVATAR_COLORS, MAX_LANES, SHIP_COLOR, TL_DAY, pad, ymd, parse, addDays, diffDays, hexToRgba, beFormat, beParse, csrf } from './shared';

export default ({ config, board, starKey, loadStars, t0 }) => ({
    // ---- @ picker ----
    mentionList() { const q = this.mentionQuery.toLowerCase(); return this.people.filter((p) => !q || p.name.toLowerCase().includes(q)).slice(0, 6); },
    // opens the list while the text just before the caret is "@something" (start of the text or after a space)
    onCommentInput(ev) {
        const el = ev.target, upto = el.value.slice(0, el.selectionStart), m = upto.match(/(?:^|\s)@([^\s@]{0,30})$/);
        if (m) { this.mentionQuery = m[1]; this.mentionOpen = true; this.mentionIdx = 0; } else { this.mentionOpen = false; }
    },
    pickMention(p) {
        const el = this.$refs.commentBox, pos = el.selectionStart, m = el.value.slice(0, pos).match(/(^|\s)@([^\s@]{0,30})$/);
        if (!m) return;
        const start = pos - m[2].length - 1;   // the "@"
        this.comment = el.value.slice(0, start) + '@' + p.name + ' ' + el.value.slice(pos);
        this.mentionOpen = false;
        this.$nextTick(() => { const c = start + p.name.length + 2; el.focus(); el.setSelectionRange(c, c); });
    },
    onCommentKey(ev) {
        const list = this.mentionOpen ? this.mentionList() : [];
        if (list.length) {
            if (ev.key === 'ArrowDown') { ev.preventDefault(); this.mentionIdx = (this.mentionIdx + 1) % list.length; return; }
            if (ev.key === 'ArrowUp') { ev.preventDefault(); this.mentionIdx = (this.mentionIdx - 1 + list.length) % list.length; return; }
            if ((ev.key === 'Enter' || ev.key === 'Tab') && !ev.isComposing) { ev.preventDefault(); this.pickMention(list[this.mentionIdx] || list[0]); return; }
            if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); this.mentionOpen = false; return; }
        }
        if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) { ev.preventDefault(); this.postComment(); }
    },
    // ids of the people whose "@Name" is still in the text when it is sent
    mentionedIds(text) { return this.people.filter((p) => text.includes('@' + p.name)).map((p) => p.id); },
    // a comment split into plain text and "@Name" pieces so tags can be highlighted without injecting HTML
    commentParts(text) {
        const names = this.people.map((p) => p.name).sort((a, b) => b.length - a.length).map((n) => n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
        if (!names.length) return [{ t: text, m: false }];
        return text.split(new RegExp('(@(?:' + names.join('|') + '))', 'g')).filter((x) => x !== '').map((x) => ({ t: x, m: x.startsWith('@') && this.people.some((p) => '@' + p.name === x) }));
    },
    async postComment() {
        const t = this.cur, text = this.comment.trim();
        if (!t || (!text && !this.files.length) || this.posting) return;
        this.posting = true;
        const alert = this.alertDept || null, files = this.files;
        t.comments.push({ id: 'tmp' + Date.now(), author: 'คุณ', text: text || '(แนบไฟล์)', at: Date.now(), files: files.map((f) => ({ name: f.name, url: '', size: '', image: false })) });
        this.comment = ''; this.alertDept = ''; this.files = [];
        this.mentionOpen = false; this.scrollFeed();
        const replyTo = this.replyTo ? this.replyTo.id : null; this.replyTo = null;
        try { await this.send([{ type: 'comment', taskId: t.id, text, alertDept: alert, mentions: this.mentionedIds(text), replyTo }], files); } finally { this.posting = false; }
    },
    pickFiles(ev) {
        const room = 5 - this.files.length;
        this.files = this.files.concat([...ev.target.files].slice(0, Math.max(0, room)));
        ev.target.value = '';
    },
    // ---- status picked by hand, tags, departments, cabinets (the buttons only show to who may use them; PMO enforces the same) ----
    setStatus(t, statusId) {
        t.manualStatus = statusId !== '';
        t.statusId = statusId || 'pmo_' + t.info.pmoStatus;
        this.send([{ type: 'project', taskId: t.id, fields: { statusId } }]);   // '' = none picked: PMO's own project status shows
    },
    setStageStatus(t, deptId, statusId) {
        const s = this.stageOf(t, deptId);
        if (s) { s.manualStatus = statusId !== ''; if (statusId) s.statusId = statusId; }
        this.send([{ type: 'stage_status', taskId: t.id, deptId, statusId }]);   // '' = back to automatic; the answer carries the real one
    },
    labelOf(id) { return this.labels.find((l) => l.id === id) || { id, name: id, color: '#98a2b3' }; },
    setLabels(t, ids) { t.labels = ids; this.send([{ type: 'labels_set', taskId: t.id, labels: ids }]); },
    toggleLabel(t, id) { this.setLabels(t, t.labels.includes(id) ? t.labels.filter((x) => x !== id) : t.labels.concat(id)); },
    createLabel(t) {
        const name = (prompt('ชื่อแท็กใหม่') || '').trim();
        if (name) this.send([{ type: 'label_create', name }]);   // no project on it: PMO answers with the whole board, the new tag is in the list
    },
    freeDepts(t) { return this.departments.filter((d) => !(t.deptStages || []).some((s) => s.deptId === d.id)); },
    addStage(t, deptId) { if (deptId) this.send([{ type: 'stage_add', taskId: t.id, deptId }]); },
    removeStage(t, deptId) {
        if (!confirm('เอาแผนก ' + this.deptOf(deptId).name + ' ออกจากโครงการนี้? (ทำได้เมื่อแผนกยังไม่รับงาน)')) return;
        this.stageDept = null;
        this.send([{ type: 'stage_remove', taskId: t.id, deptId }]);
    },
    // ---- people in charge ----
    team() { const seen = new Set(), list = []; [this.me, ...this.people].forEach((p) => { if (p && !seen.has(p.id)) { seen.add(p.id); list.push(p); } }); return list; },
    personOf(id) { return this.team().find((p) => p.id === id) || { id, name: '#' + id }; },
    toggleOwner(t, id) {
        id = Number(id);
        t.owners = t.owners.includes(id) ? t.owners.filter((x) => x !== id) : t.owners.concat(id);
        this.send([{ type: 'assignees_set', taskId: t.id, userIds: t.owners }]);
    },
    // ---- status picked from a bar / a cabinet ----
    openStatusPop(ev, t, c) {
        if (!this.canEdit) return;   // statuses are set by Admin / PM
        const r = ev.currentTarget.getBoundingClientRect();
        this.statusPop = { taskId: t.id, cabId: c ? c.id : null, top: Math.min(r.bottom + 4, window.innerHeight - 280), left: Math.max(8, Math.min(r.left, window.innerWidth - 220)) };
    },
    pickStatus(statusId) {
        const p = this.statusPop, t = p && this.taskById(p.taskId);
        this.statusPop = null;
        if (!t) return;
        if (!p.cabId) { this.setStatus(t, statusId); return; }
        const c = t.subtasks.find((x) => x.id === p.cabId);
        if (c) { c.manualStatus = statusId !== ''; if (statusId) c.statusId = statusId; }
        this.send([{ type: 'cabinet_status', taskId: t.id, cabinetId: p.cabId, statusId }]);
    },
    // ---- extra fields shown on the bars (Settings > ฟิลด์เพิ่มเติม › แสดงบนปฏิทิน) ----
    fieldChips(t) {
        const out = [];
        (t.deptStages || []).forEach((s) => (this.deptOf(s.deptId).fields || []).forEach((f) => {
            const v = f.showOnChip && s.fields && s.fields[f.id];
            if (!v) return;
            const o = this.fieldOptions(f).find((x) => x.id === v);
            out.push(o ? o.text.split(' · ').pop() : (f.type === 'link' ? f.name : v));
        }));
        return out;
    },
    // ---- the project's record (Admin / PM) ----
    editInfo(t, key, value) {
        t.info[key] = value;
        this.send([{ type: 'project', taskId: t.id, fields: { [key]: value } }]);
    },
    // ---- money (มูลค่างาน / Margin): shown 1,250,000.00, typed with or without commas ----
    money(v) { const n = parseFloat(String(v ?? '').replace(/[\s,฿]|บาท/g, '')); return isNaN(n) ? null : n; },
    fmtMoney(v) { const n = this.money(v); return n === null ? '' : n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    marginPct(o) { const v = this.money(o.contractValue), m = this.money(o.margin); return v && m !== null ? (m / v * 100).toFixed(1) + ' %' : ''; },
    editMoney(t, key, el) {
        const raw = el.value.trim(), n = this.money(raw);
        if (raw !== '' && n === null) { window.showToast('กรุณากรอกตัวเลข', 'error'); el.value = this.fmtMoney(t.info[key]); return; }
        t.info[key] = n;
        el.value = this.fmtMoney(n);
        this.send([{ type: 'project', taskId: t.id, fields: { [key]: n === null ? '' : String(n) } }]);
    },
    staffFor(deptId) { return this.staff.filter((p) => !deptId || p.deptId === deptId || (p.deptIds || []).includes(deptId) || p.pmo); },
    // ---- an expanded cabinet: its record + every sub task (dates, the person doing it, remark), loaded when opened ----
    async toggleCab(t, c) {
        if (this.cabOpen[c.id]) { const o = { ...this.cabOpen }; delete o[c.id]; this.cabOpen = o; return; }
        this.cabOpen = { ...this.cabOpen, [c.id]: { loading: true, cabinet: null, subtasks: [], taskId: t.id } };
        try {
            const res = await fetch(config.urlsCabinet.replace('__ID__', c.id.slice(1)), { headers: { 'Accept': 'application/json' } });
            if (!res.ok) throw new Error();
            const data = await res.json();
            if (this.cabOpen[c.id]) this.cabOpen = { ...this.cabOpen, [c.id]: { loading: false, taskId: t.id, ...data } };
        } catch (e) { if (this.cabOpen[c.id]) this.cabOpen = { ...this.cabOpen, [c.id]: { loading: false, error: true, cabinet: null, subtasks: [], taskId: t.id } }; }
    },
    editCabInfo(t, c, key, value) {
        const o = this.cabOpen[c.id];
        if (o && o.cabinet) o.cabinet[key] = value;
        if (key === 'dueDate') c.ownDue = value || null;
        this.send([{ type: 'cabinet_info', taskId: t.id, cabinetId: c.id, fields: { [key]: value } }]);
    },
    editSubInfo(t, s, key, value) {
        s[key] = value;
        this.send([{ type: 'subtask_info', taskId: t.id, subtaskId: s.id, fields: { [key]: value } }]);
    },
    // "วัน": working days from the start (today when there is none) - start and due go in one save
    editSubDays(t, s, days) {
        const n = parseInt(days, 10);
        if (!(n >= 1)) return;
        const now = new Date();
        const startDate = s.startDate || [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-');
        const dueDate = window.addWorkDays(startDate, n);
        Object.assign(s, { startDate, dueDate });
        this.send([{ type: 'subtask_info', taskId: t.id, subtaskId: s.id, fields: { startDate, dueDate } }]);
    },
    stateLabel(state) { return { ASSIGNED: 'รอรับ', ACCEPTED: 'รับแล้ว', IN_PROGRESS: 'กำลังทำ', COMPLETED: 'เสร็จ', UNASSIGNED: 'ยังไม่จ่าย' }[state] || state; },
    // ---- what is still missing on a project ----
    warningList(t) {
        const w = t.warnings || {}, out = [];
        if (w.rejected) out.push({ text: w.rejected + ' งานถูกส่งคืน (แผนกไม่รับงาน) — จ่ายใหม่', act: 'dispatch' });
        if (w.free) out.push({ text: w.free + ' งานย่อยยังไม่ได้จ่ายให้แผนก', act: 'dispatch' });
        if (w.undated) out.push({ text: w.undated + ' งานที่จ่ายแล้วยังไม่มีวันส่ง', act: '' });
        if (w.cabNoDue) out.push({ text: w.cabNoDue + ' ตู้ยังไม่มีวันครบกำหนด', act: '' });
        if (w.noManager) out.push({ text: 'ยังไม่ได้กำหนด Project Manager', act: 'info' });
        if (w.noDue) out.push({ text: 'โครงการยังไม่มีวันครบกำหนด', act: '' });
        return out;
    },
    // ---- printing a work order: the department in view, the day in the left panel ----
    printWorkOrder() {
        const dept = this.dept || this.me.deptId;
        if (!dept) { window.showToast('เลือกแผนกก่อน (ตัวเลือกแผนกด้านบน) แล้วกดพิมพ์ใบสั่งงานอีกครั้ง', 'error'); return; }
        window.open(this.urls.workOrder + '?' + new URLSearchParams({ dept, date: this.focusDate }), '_blank');
    },
    deleteProject() {
        const t = this.cur;
        if (!t || !confirm('ย้ายโครงการ ' + t.so + ' ไปถังขยะ? (กู้คืนได้จากหน้าถังขยะ)')) return;
        this.closeModal();
        this.tasks.splice(this.tasks.indexOf(t), 1);   // gone from the page at once; if PMO refuses, its answer puts the project back
        this.send([{ type: 'delete', taskId: t.id }]);
    },

});
