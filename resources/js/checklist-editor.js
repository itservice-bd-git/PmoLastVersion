// The one checklist editor (x-checklist-editor): a cabinet's Sub Task list or a template's. Edit in place (click the text),
// add many at once (type, or paste rows from Excel: name | remark | date), select several to delete / move / copy / date,
// drag or Alt+↑↓ to reorder, tick (cabinet lists), and "ยกเลิก" right after a change. Every change goes to
// POST /checklist-editor/{kind}/{id} (ChecklistEditor on the server checks who may and answers with the list as it now is).
export default (cfg) => ({
    kind: cfg.kind,
    ownerId: cfg.ownerId,
    url: cfg.url,
    items: cfg.items || [],
    editable: !!cfg.editable,
    tickable: !!(cfg.editable || cfg.tickable),   // may tick (a department's work still waiting: the tick accepts it)
    dateLabel: cfg.dateLabel || null,
    linked: (cfg.linked || []).filter((l) => !l.watch),     // the Sub Tasks this one compares with (template pairs), read only
    watched: (cfg.linked || []).filter((l) => l.watch),     // the ones it only follows ("ดูอย่างเดียว"): their list, dates, who received
    receivers: cfg.receivers || [],   // the Sub Tasks comparing with this one
    today: (() => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); })(),
    busy: false,
    selected: [],
    editing: null,          // { id, field, value }
    draft: '',
    paste: null,            // rows waiting for "เพิ่ม n รายการ" after a paste
    undo: null,             // { op, text, timer }
    targets: null,          // { move, copyTo, copyFrom } - fetched the first time a dialog needs them
    dialog: null,           // 'move' | 'copyTo' | 'copyFrom'
    dialogPick: [],
    bulkDate: '',
    dragId: null,
    pendingLive: null,      // a fresh list from the page (checklists-live) that came while this one was being edited
    // Settings > สีสถานะ Checklist (a cabinet's lists only): the statuses (the page sets window.pmoChecklistStatuses once)
    statuses: cfg.kind === 'subtask' ? (window.pmoChecklistStatuses || []) : [],
    deptId: cfg.deptId || null,   // the Sub Task's department: a status may be for some departments only
    picking: null,          // the item whose status panel is open
    tagFilter: [],          // "สีนี้คืออะไร": a colour picked there shows only the items carrying it

    // the cabinet page keeps every list current (another department's change, a shared list): take it, unless mid-edit
    init() {
        if (this.kind !== 'subtask') return;
        // the cabinet page's "สถานะในตู้นี้" and Task headers add up every list's statuses (Alpine.store('checklistTags'))
        this.publishTags();
        this.$watch('items', () => this.publishTags());
        window.addEventListener('checklist-tag-filter', (e) => {   // "สถานะในตู้นี้": only the lists that have it filter
            const id = e.detail.id;
            this.tagFilter = id && this.items.some((i) => (i.tags || []).includes(id)) ? [id] : [];
        });
        window.addEventListener('checklists-live', (e) => {
            const l = e.detail.lists && e.detail.lists[this.ownerId];
            if (!l) return;
            this.pendingLive = l;
            this.takeLive();
        });
        ['editing', 'paste', 'dialog', 'busy', 'dragId'].forEach((k) => this.$watch(k, () => this.takeLive()));
    },
    takeLive() {
        if (!this.pendingLive || this.editing || this.paste || this.dialog || this.busy || this.dragId) return;
        const l = this.pendingLive;
        this.pendingLive = null;
        this.items = l.items;
        this.linked = (l.linked || []).filter((x) => !x.watch);
        this.watched = (l.linked || []).filter((x) => x.watch);
        this.selected = this.selected.filter((id) => this.items.some((i) => i.id === id));
    },
    changed() { window.dispatchEvent(new CustomEvent('checklist-changed')); },

    // ---- statuses (Settings > สีสถานะ Checklist): coloured tags, several per item; a group's ones replace each other ----
    get tagOn() { return this.statuses.length > 0; },
    publishTags() { if (window.Alpine) window.Alpine.store('checklistTags').lists[this.ownerId] = this.items.map((i) => ({ id: i.id, done: i.done, tags: i.tags || [] })); },
    st(id) { return this.statuses.find((s) => s.id === id); },
    tagsOf(item) { return (item.tags || []).map((id) => this.st(id)).filter(Boolean); },
    chipStyle(s) { return 'background:' + s.bg + ';color:' + s.fg + (s.active ? '' : ';opacity:.7'); },
    // the rows show colour dots only ("สีนี้คืออะไร" above the list says what each is); a tap on the dots or the toolbar's tag
    // button opens a panel under the row: each status by name with ×, and "+ เพิ่ม" a colour, every change saved at once.
    // "+ เพิ่ม" offers what the item may still get (active, for this department); one of a group replaces the item's other.
    addable(item) {
        const has = item.tags || [];
        return this.statuses.filter((s) => !has.includes(s.id) && s.active && (!s.depts.length || !this.deptId || s.depts.includes(this.deptId)));
    },
    rivalOf(item, s) { return s.group ? this.tagsOf(item).find((x) => x.group === s.group) : null; },
    openPick(item) { if (this.tagOn) this.picking = this.picking === item.id ? null : item.id; },
    addTag(item, id) {
        const s = this.st(Number(id));
        if (!s) return;
        const rival = this.rivalOf(item, s);
        this.saveTags(item, [...(item.tags || []).filter((t) => !rival || t !== rival.id), s.id]);
    },
    removeTag(item, id) { this.saveTags(item, (item.tags || []).filter((t) => t !== id)); },
    async saveTags(item, tags) {
        const before = item.tags || [];
        item.tags = this.statuses.map((s) => s.id).filter((id) => tags.includes(id));   // at once; the answer confirms it
        if (!(await this.send({ type: 'tags', id: item.id, tags }, { quiet: true, offerUndo: false }))) item.tags = before;
    },
    // "แสดงเฉพาะ": the statuses this list has, with how many items carry each
    get tagLegend() {
        return this.statuses.map((s) => ({ ...s, n: this.items.filter((i) => (i.tags || []).includes(s.id)).length })).filter((s) => s.n > 0);
    },
    shown(item) { return !this.tagFilter.length || (item.tags || []).some((t) => this.tagFilter.includes(t)); },
    toggleTagFilter(id) { this.tagFilter = this.tagFilter.includes(id) ? this.tagFilter.filter((x) => x !== id) : [...this.tagFilter, id]; },
    get shownCount() { return this.items.filter((i) => this.shown(i)).length; },

    get doneCount() { return this.items.filter((i) => i.done).length; },
    get allSelected() { return this.items.length > 0 && this.selected.length === this.items.length; },
    isSelected(id) { return this.selected.includes(id); },
    toggleSelect(id) { this.selected = this.isSelected(id) ? this.selected.filter((x) => x !== id) : [...this.selected, id]; },
    toggleAll() { this.selected = this.allSelected ? [] : this.items.map((i) => i.id); },
    // ---- comparing (pairs): one list - a copy (item.from) sits beside the item it was made from; an own item of the same name
    // (an older list, or a finished Sub Task that got no new items) beside that one ----
    norm(s) { return String(s || '').trim().replace(/\s+/g, ' ').toLowerCase(); },
    get linkedIds() { return new Set(this.items.map((i) => i.from).filter(Boolean)); },
    matchesOf(item) {
        if (item.from) return this.linked.flatMap((l) => l.items.filter((i) => i.id === item.from).map((i) => ({ ...i, l })));
        const n = this.norm(item.name), taken = this.linkedIds;
        return this.linked.flatMap((l) => l.items.filter((i) => !taken.has(i.id) && this.norm(i.text) === n).map((i) => ({ ...i, l })));
    },
    get unmatched() {
        const taken = this.linkedIds, mine = new Set(this.items.filter((i) => !i.from).map((i) => this.norm(i.name)));
        return this.linked.flatMap((l) => l.items.filter((i) => !taken.has(i.id) && !mine.has(this.norm(i.text))).map((i) => ({ ...i, l })));
    },
    // where a copy comes from (its name, place and removal are changed there)
    fromOf(item) { const m = item.from && this.matchesOf(item)[0]; return m ? (m.l.deptName || '') + ' · ' + m.l.title : 'Sub Task ที่เทียบ'; },
    async addFromLinked(i) { await this.send({ type: 'add', items: [{ name: i.text }] }, { quiet: true, offerUndo: false }); },
    fmtDate(d) { if (!d) return ''; const [y, m, day] = d.split('-'); return day + '/' + m + '/' + (Number(y) + 543); },

    // ---- talking to the server ----
    async send(op, { quiet = false, offerUndo = true } = {}) {
        if (this.busy) return null;
        this.busy = true;
        try {
            const res = await fetch(this.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(op),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) throw new Error(data.message || 'บันทึกไม่สำเร็จ ลองใหม่อีกครั้ง');
            this.items = data.items;
            this.selected = this.selected.filter((id) => this.items.some((i) => i.id === id));
            if (data.progress) window.dispatchEvent(new CustomEvent('checklist-progress', { detail: data.progress }));
            this.changed();   // a shared list: the other Sub Task on this page follows at once
            if (offerUndo && data.undo) this.offerUndo(data.undo, data.message || 'บันทึกแล้ว');
            else if (!quiet && data.message) window.showToast(data.message);
            return data;
        } catch (e) {
            window.showToast(e.message, 'error');
            return null;
        } finally {
            this.busy = false;
        }
    },
    offerUndo(op, text) {
        if (this.undo) clearTimeout(this.undo.timer);
        this.undo = { op, text, timer: setTimeout(() => { this.undo = null; }, 8000) };
    },
    async doUndo() {
        const u = this.undo;
        if (!u) return;
        clearTimeout(u.timer);
        this.undo = null;
        await this.send(u.op, { offerUndo: false });
    },

    // ---- edit in place ----
    startEdit(item, field) {
        if (!this.editable || (field === 'name' && item.from)) return;
        this.editing = { id: item.id, field, value: field === 'date' ? (item.date || '') : (item[field] || '') };
        this.$nextTick(() => { const el = this.$root.querySelector('[data-edit-input]'); if (el) { el.focus(); if (el.select) el.select(); } });
    },
    async commitEdit(next) {
        const e = this.editing;
        if (!e) return;
        this.editing = null;
        const item = this.items.find((i) => i.id === e.id);
        const value = e.field === 'date' ? e.value : e.value.trim();
        if (item && value !== (e.field === 'date' ? (item.date || '') : (item[e.field] || ''))) {
            if (e.field === 'name' && !value) { window.showToast('ชื่อรายการต้องไม่ว่าง', 'error'); return; }
            await this.send({ type: 'update', id: e.id, [e.field]: value }, { quiet: true });
        }
        if (next === 'down') {   // Enter: on to the next row's name (or the "add" box after the last row)
            const i = this.items.findIndex((x) => x.id === e.id);
            if (this.items[i + 1]) this.startEdit(this.items[i + 1], e.field);
            else this.$nextTick(() => this.$refs.draft && this.$refs.draft.focus());
        } else if (next === 'right' && e.field === 'name') {
            this.startEdit(this.items.find((x) => x.id === e.id), 'remark');
        }
    },
    cancelEdit() { this.editing = null; },

    // ---- add: one typed line, or many pasted rows ----
    async addDraft() {
        const text = this.draft.trim();
        if (!text) return;
        if (/[\n\t]/.test(text)) { this.paste = this.parsePaste(text); return; }
        this.draft = '';
        await this.send({ type: 'add', items: [{ name: text }] }, { quiet: true, offerUndo: false });
        this.$nextTick(() => this.$refs.draft && this.$refs.draft.focus());
    },
    onPaste(ev) {
        const text = (ev.clipboardData || window.clipboardData).getData('text');
        if (!/[\n\t]/.test(text.trim())) return;   // a plain word: let it paste normally
        ev.preventDefault();
        this.paste = this.parsePaste(text);
    },
    // Excel / Sheets: a row per line, cells split by tabs. Each column is given a use the person can change in the preview:
    // the first = ชื่อ, the rest = "รวมกับชื่อ" (e.g. a BOM's qty / unit stay in the name, as typed today); a column of dates
    // becomes the date when this list has one.
    parsePaste(text) {
        const cells = text.replace(/\r/g, '').split('\n').map((l) => l.split('\t').map((c) => c.trim())).filter((r) => r.some((c) => c));
        const width = Math.max(...cells.map((r) => r.length));
        const isDate = (v) => /^\d{1,2}\/\d{1,2}\/\d{4}$|^\d{4}-\d{2}-\d{2}$/.test(v);
        const map = Array.from({ length: width }, (_, i) => {
            if (i === 0) return 'name';
            const col = cells.map((r) => r[i] || '').filter(Boolean);
            return this.dateLabel && col.length && col.every(isDate) ? 'date' : 'join';
        });
        return { cells, map };
    },
    pasteRows() {
        const p = this.paste;
        if (!p) return [];
        return p.cells.map((r) => {
            const pick = (use) => p.map.map((m, i) => (m === use ? r[i] || '' : '')).filter(Boolean);
            const name = p.map.map((m, i) => (m === 'name' || m === 'join' ? r[i] || '' : '')).filter(Boolean).join(' ');
            return { name, remark: pick('remark').join(' '), date: pick('date')[0] || '' };
        }).filter((r) => r.name);
    },
    async addPasted() {
        const rows = this.pasteRows();
        this.paste = null;
        this.draft = '';
        if (rows.length) await this.send({ type: 'add', items: rows });
    },

    // ---- one or many at once ----
    async remove(ids) {
        if (!ids.length) return;
        await this.send({ type: 'delete', ids });   // no "are you sure": the bar below offers ยกเลิก
        this.selected = [];
    },
    async setDate(ids, date) { for (const id of ids) await this.send({ type: 'update', id, date }, { quiet: true, offerUndo: false }); this.bulkDate = ''; },
    async openDialog(name) {
        this.dialogPick = [];
        if (!this.targets) {
            try { this.targets = await (await fetch(this.url + '?targets=1', { headers: { 'Accept': 'application/json' } })).json(); }
            catch (e) { window.showToast('โหลดรายการปลายทางไม่สำเร็จ', 'error'); return; }
        }
        this.dialog = name;
    },
    async runDialog() {
        const name = this.dialog, pick = this.dialogPick;
        if (!pick.length && name !== 'copyFrom') return;
        this.dialog = null;
        if (name === 'move') await this.send({ type: 'move', ids: this.selected, to: pick[0] });
        if (name === 'copyTo') await this.send({ type: 'copy', ids: this.selected, to: pick }, { offerUndo: false });
        if (name === 'copyFrom' && pick.length) await this.send({ type: 'copyFrom', from: pick[0] });
        this.selected = [];
    },

    // ---- order: drag a row, or Alt+↑ / Alt+↓ ----
    async moveTo(fromId, toIndex) {
        const ids = this.items.map((i) => i.id), from = ids.indexOf(fromId);
        if (from < 0 || toIndex < 0 || toIndex >= ids.length || from === toIndex) return;
        ids.splice(toIndex, 0, ids.splice(from, 1)[0]);
        this.items = ids.map((id) => this.items.find((i) => i.id === id));   // at once; the answer confirms it
        await this.send({ type: 'reorder', ids }, { quiet: true, offerUndo: false });
    },
    nudge(item, step) { this.moveTo(item.id, this.items.indexOf(item) + step); },
    dragStart(ev, item) { if (!this.editable) return; this.dragId = item.id; ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', String(item.id)); },
    drop(item) { const id = this.dragId; this.dragId = null; if (id && id !== item.id) this.moveTo(id, this.items.indexOf(item)); },

    // ---- tick (a cabinet's list) ----
    async tick(item, done) {
        item.done = done;
        try {
            const res = await fetch(item.toggleUrl, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ is_completed: done }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.message || 'บันทึกไม่สำเร็จ');
            item.doneBy = data.checklist.completed_by_name; item.doneAt = data.checklist.completed_at_formatted;
            window.dispatchEvent(new CustomEvent('checklist-progress', { detail: data }));
            this.changed();
        } catch (e) {
            item.done = !done;
            window.showToast(e.message, 'error');
        }
    },
});
