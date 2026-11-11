// Planning board · The checklist popover, checklist statuses, handoff dates, and the date field helper.
// One part of the planningBoard Alpine component (see ./index.js); `this` is the whole component.
import { MONTHS, WEEKDAYS, PRIORITIES, TH_DAYS, PRIORITY_RANK, TH_MON_SHORT, AVATAR_COLORS, MAX_LANES, SHIP_COLOR, TL_DAY, pad, ymd, parse, addDays, diffDays, hexToRgba, beFormat, beParse, csrf } from './shared';

export default ({ config, board, starKey, loadStars, t0 }) => ({
    // ---- checklist popover (as in the original page): header + progress, items you can tick, grouped by department, + add / delete ----
    get clTask() { return this.cl ? this.taskById(this.cl.taskId) : null; },
    get clCab() { const t = this.clTask; return t ? (t.subtasks.find((c) => c.id === this.cl.cabId) || null) : null; },
    openChecklist(taskId, cabId, ev) {
        if (this.cl && this.cl.cabId === cabId && this.cl.taskId === taskId) { this.closeChecklist(); return; }
        const r = ev.currentTarget.getBoundingClientRect();
        this.stageDept = null; this.clDraft = ''; this.ckPick = null; this.ckFilter = [];
        let big = false;
        try { big = localStorage.getItem('planning.clBig') === '1'; } catch (e) { /* not remembered */ }
        this.cl = { taskId, cabId, adding: null, big, anchor: { top: r.top, bottom: r.bottom, left: r.left, width: r.width } };
        this.clPlace();
    },
    // where the window goes: normal = up to 560 x 720 next to the pill (960 wide when a department passes work to this
    // one, so the two lists sit side by side); big = most of the screen (notebook / iPad); always on screen
    clPlace() {
        const W = window.innerWidth, H = window.innerHeight, a = this.cl.anchor;
        const linked = ((this.clCab && this.clCab.linked) || []).some((l) => !l.watch);
        const w = this.cl.big ? Math.min(1000, W - 16) : Math.min(linked ? 960 : 560, W - 16);
        const h = this.cl.big ? H - 24 : Math.min(720, H - 24);
        const top = this.cl.big ? 12 : Math.max(8, Math.min(a.bottom + 6, H - h - 12));
        const left = this.cl.big ? Math.round((W - w) / 2) : Math.max(8, Math.min(a.left + a.width / 2 - w / 2, W - w - 8));
        this.cl = { ...this.cl, w, h, top, left };
    },
    // Settings > การส่งต่องาน: this department's list and the one passing work to it, next to each other to compare
    clSide() { return !!(this.cl && this.cl.w >= 640 && this.clCab && (this.clCab.linked || []).some((l) => !l.watch)); },
    clToggleBig() {
        this.cl = { ...this.cl, big: !this.cl.big };
        try { localStorage.setItem('planning.clBig', this.cl.big ? '1' : ''); } catch (e) { /* not remembered */ }
        this.clPlace();
    },
    closeChecklist() { this.cl = null; this.clDraft = ''; },
    // ---- checklist statuses (Settings > สีสถานะ Checklist): coloured tags, several per item; a group's ones replace each other ----
    ckTags(i) { return (i.tags || []).map((id) => this.ckStatuses.find((s) => s.id === id)).filter(Boolean); },
    // the other departments of the linked list (ChecklistStatuses::peersFor), read only:
    // a list shown beside this one (compared / watched) carries its own statuses there; the rest only in the item's panel
    ckPeers(i) {
        const beside = new Set([...this.clLinkedOf(i.subId), ...this.clWatchedOf(i.subId)].map((l) => l.id));
        return (i.peerTags || []).filter((p) => !beside.has(p.sub)).map((p) => { const s = this.ckStatuses.find((x) => x.id === p.id); return s && { ...s, dept: p.dept }; }).filter(Boolean);
    },
    ckPeerDepts(i) { return [...new Set(this.ckPeers(i).map((s) => s.dept))]; },
    ckOf(ids) { return (ids || []).map((id) => this.ckStatuses.find((s) => s.id === id)).filter(Boolean); },
    ckTitle(i) { return this.ckTags(i).map((s) => s.name).join(' · '); },
    ckChip(s) { return 'background:' + s.bg + ';color:' + s.fg + (s.active ? '' : ';opacity:.7'); },
    // how many items of a cabinet (the department in view) carry each status: the cabinet row's chips, the window's filter
    ckTally(c) {
        const n = {};
        (c ? c.checklist : []).filter((i) => !this.dept || i.deptId === this.dept).forEach((i) => (i.tags || []).forEach((t) => { n[t] = (n[t] || 0) + 1; }));
        return this.ckStatuses.filter((s) => n[s.id]).map((s) => ({ ...s, n: n[s.id] }));
    },
    ckShown(i) { return !this.ckFilter.length || (i.tags || []).some((t) => this.ckFilter.includes(t)); },
    ckToggleFilter(id) { this.ckFilter = this.ckFilter.includes(id) ? this.ckFilter.filter((x) => x !== id) : [...this.ckFilter, id]; },
    openChecklistTag(t, c, id, ev) { this.openChecklist(t.id, c.id, ev); if (this.cl) this.ckFilter = [id]; },
    // items show colour dots only ("สีนี้คืออะไร" says what each is); the dots or the toolbar's tag button open a panel under
    // the item: each status by name with ×, "+ เพิ่ม" what it may still get (active, for its department); saved at once
    ckAddable(i) {
        const has = i.tags || [], dept = Number(String(i.deptId || '').replace(/\D/g, '')) || 0;
        return this.ckStatuses.filter((s) => !has.includes(s.id) && s.active && (!s.depts.length || !dept || s.depts.includes(dept)));
    },
    ckRival(i, s) { return s.group ? this.ckTags(i).find((x) => x.group === s.group) : null; },
    ckOpen(i) { this.ckPick = this.ckPick === i.id ? null : i.id; },
    ckAdd(i, id) {
        const s = this.ckStatuses.find((x) => x.id === Number(id));
        if (!s) return;
        const rival = this.ckRival(i, s);
        this.ckSet(i, [...(i.tags || []).filter((t) => !rival || t !== rival.id), s.id]);
    },
    ckSet(i, tags) {
        // a status set to tick the item ticks it on the server too (ChecklistStatuses::set): show it at once
        if (!i.done && tags.some((id) => !(i.tags || []).includes(id) && (this.ckStatuses.find((s) => s.id === id) || {}).ticks)) i.done = true;
        i.tags = this.ckStatuses.map((s) => s.id).filter((id) => tags.includes(id));
        this.send([{ type: 'checklist_tags', taskId: this.cl.taskId, itemId: i.id, tags: i.tags }]);
    },
    clScope() { return this.dept || null; },
    clProgress() { return this.clCab ? this.cabProgress(this.clCab, this.clScope()) : { done: 0, total: 0, pct: 0 }; },
    clTitle() { return this.dept ? 'Checklist · ' + this.deptOf(this.dept).name : 'Checklist (ทุกแผนก)'; },
    // Task › Sub Task › items, in the cabinet's own order (only the department in view when one is); a Sub Task with no
    // item yet is listed too, so its "+" is there
    clGroups() {
        const c = this.clCab;
        if (!c) return [];
        const tasks = [];
        (c.works || []).filter((w) => !this.dept || w.deptId === this.dept).forEach((w) => {
            let g = tasks[tasks.length - 1];
            if (!g || g.task !== w.task) { g = { task: w.task, subs: [] }; tasks.push(g); }
            const items = c.checklist.filter((i) => i.subId === w.id);
            g.subs.push({ ...w, items, done: items.filter((i) => i.done).length });
        });
        return tasks;
    },
    // ---- Settings > การส่งต่องาน: the date a department gives its items for the next one, and what the senders did ----
    clItem(id) { const c = this.clCab; return c ? c.checklist.find((x) => x.id === id) || null : null; },
    clItemDate(id) { const i = this.clItem(id); return i ? i.expected : null; },
    setItemDate(id, v) {
        const i = this.clItem(id);
        if (!i || (i.expected || null) === (v || null)) return;
        i.expected = v || null;
        this.send([{ type: 'checklist_date', taskId: this.cl.taskId, itemId: id, date: v || '' }]);
    },
    clDateDepts() {
        const c = this.clCab;
        if (!c) return [];
        return [...new Set((c.works || []).map((w) => w.deptId))].filter((d) => (!this.dept || d === this.dept) && this.deptOf(d).dateLabel && this.canEditStage(d)
            && c.checklist.some((i) => i.deptId === d));
    },
    setCabDate(deptId, v) {
        const c = this.clCab;
        if (!c || !v) return;
        c.checklist.filter((i) => i.deptId === deptId).forEach((i) => { i.expected = v; });
        this.send([{ type: 'checklist_date_all', taskId: this.cl.taskId, cabinetId: c.id, deptId, date: v }]);
    },
    // pairs: the lists a Sub Task of mine compares with; one list - a copy of mine (i.from) beside the item it was made
    // from, an own item of the same name beside that one
    clLinkedOf(subId) { return ((this.clCab && this.clCab.linked) || []).filter((l) => l.forId === subId && !l.watch); },
    // "ดูอย่างเดียว": lists a Sub Task of mine only follows - their date and who received each item, beneath its own list
    clWatchedOf(subId) { return ((this.clCab && this.clCab.linked) || []).filter((l) => l.forId === subId && l.watch); },
    clNorm(s) { return String(s || '').trim().replace(/\s+/g, ' ').toLowerCase(); },
    clTaken(subId) { return new Set(((this.clCab && this.clCab.checklist) || []).filter((i) => i.subId === subId && i.from).map((i) => i.from)); },
    clMatches(subId, item) {
        if (item.from) return this.clLinkedOf(subId).flatMap((l) => l.items.filter((x) => x.id === item.from).map((x) => ({ ...x, l })));
        const n = this.clNorm(item.text), taken = this.clTaken(subId);
        return this.clLinkedOf(subId).flatMap((l) => l.items.filter((x) => !taken.has(x.id) && this.clNorm(x.text) === n).map((x) => ({ ...x, l })));
    },
    clUnmatched(w) {
        const taken = this.clTaken(w.id), mine = new Set(w.items.filter((i) => !i.from).map((i) => this.clNorm(i.text)));
        return this.clLinkedOf(w.id).flatMap((l) => l.items.filter((x) => !taken.has(x.id) && !mine.has(this.clNorm(x.text))).map((x) => ({ ...x, l })));
    },
    // a receiving department's "รับแล้ว" (or a watched list's own "ติ๊กแล้ว") on a linked item
    rcptText(h) { const v = h.verb || 'รับ'; return h.received ? '✓ ' + h.dept + ' ' + v + 'แล้ว' + (h.at ? ' ' + this.fmtBE(h.at) : '') : 'รอ' + h.dept + v; },
    rcptClass(h) { return h.received ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'; },
    linkedLate(i) { return !!i.expected && !i.received && i.expected < this.today; },
    stateWord(state) { return { ASSIGNED: 'รอรับ', ACCEPTED: 'รับแล้ว', IN_PROGRESS: 'กำลังทำ', COMPLETED: 'เสร็จ' }[state] || ''; },
    clAdd(subId) { this.cl = { ...this.cl, adding: subId }; this.clDraft = ''; this.$nextTick(() => { const el = document.getElementById('cl-draft-' + subId); if (el) el.focus(); }); },
    // several lines pasted (e.g. from Excel): one item per line, its cells joined - as the full editor's default
    clPaste(ev, subId) {
        const text = (ev.clipboardData || window.clipboardData).getData('text');
        const lines = text.replace(/\r/g, '').split('\n').map((l) => l.split('\t').map((x) => x.trim()).filter(Boolean).join(' ')).filter(Boolean);
        if (lines.length < 2) return;
        ev.preventDefault();
        const t = this.clTask, c = this.clCab, w = (c.works || []).find((x) => x.id === subId), deptId = w ? w.deptId : '';
        if (!confirm('เพิ่ม ' + lines.length + ' รายการ?\n\n' + lines.slice(0, 5).join('\n') + (lines.length > 5 ? '\n…' : ''))) return;
        this.clDraft = '';
        this.cl = { ...this.cl, adding: null };
        lines.forEach((text, i) => c.checklist.push({ id: 'tmp' + Date.now() + i, text, done: false, deptId, subId }));
        this.send(lines.slice(0, 200).map((text) => ({ type: 'checklist_add', taskId: t.id, cabinetId: c.id, deptId, subtaskId: subId, text })));
    },
    // "ติดตามโครงการ": its news comes to me too (anyone who sees it, view-only people as well)
    isFollowing(t) { return !!t && (t.followers || []).includes(this.me.id); },
    async toggleFollow(t) {
        try {
            const r = await fetch((config.urlsFollow || '').replace('__ID__', String(t.id).replace(/^p/, '')), { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } });
            const d = await r.json();
            if (!r.ok) throw new Error(d.message || 'บันทึกไม่สำเร็จ');
            t.followers = d.followers;
            window.showToast(d.message);
        } catch (e) { window.showToast(e.message, 'error'); }
    },
    cabinetPage(c, subId) { return (config.urlsCabinetPage || '').replace('__ID__', String(c.id).replace(/^c/, '')) + (subId ? '#subtask-' + String(subId).replace(/^s/, '') : ''); },
    clCommit() {
        if (!this.cl) return;
        const text = this.clDraft.trim(), subId = this.cl.adding;
        this.clDraft = '';
        this.cl = { ...this.cl, adding: null };
        if (!subId || !text) return;
        const t = this.clTask, c = this.clCab, w = (c.works || []).find((x) => x.id === subId);
        const deptId = w ? w.deptId : '';
        c.checklist.push({ id: 'tmp' + Date.now(), text, done: false, deptId, subId });   // shown at once; PMO's answer carries the real item
        this.send([{ type: 'checklist_add', taskId: t.id, cabinetId: c.id, deptId, subtaskId: subId, text }]);
    },
    clDelete(item) {
        const t = this.clTask, c = this.clCab;
        if (!t || !c) return;
        c.checklist.splice(c.checklist.indexOf(item), 1);
        this.send([{ type: 'checklist_delete', taskId: t.id, cabinetId: c.id, itemId: item.id }]);
    },
    timeline() { this.mode = 'timeline'; this.$nextTick(() => { const el = this.$refs.tlScroll; const i = this.tlDays().findIndex((d) => d.date === this.today); if (el && i >= 0) el.scrollLeft = Math.max(0, i * TL_DAY - 160); }); },

    // dd/mm/yyyy date field (flatpickr, same as the rest of the app). `get` reads the current value, `set` receives 'YYYY-MM-DD' or null.
    dateField(el, get, set, enabled, width) {
        if (!window.flatpickr) { el.type = 'date'; el.value = get() || ''; el.disabled = !enabled; el.addEventListener('change', () => set(el.value || null)); return; }
        window.flatpickr(el, {
            dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y', allowInput: true, clickOpens: enabled, formatDate: beFormat, parseDate: beParse, disableMobile: true,   // the พ.ศ. calendar on phones too (app.js)
            altInputClass: (width || 'w-[6.75rem]') + ' rounded-lg border-slate-300 px-2 py-1 pr-7 text-[13px] ' + (enabled ? '' : 'bg-slate-50 text-slate-500'),
            defaultDate: get() || null,
            onChange: (d, str) => { if (enabled && str !== (get() || '')) set(str || null); },
        });
        el._pbGet = get;   // syncDateFields() reads it again after a change made outside the field
        if (!enabled && el._flatpickr && el._flatpickr.altInput) el._flatpickr.altInput.disabled = true;
    },
});
