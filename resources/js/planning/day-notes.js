// Planning board · หมายเหตุรายวัน: a department's notes for a day (DayNoteController). The badge on a day in เดือน
// (its count), the left panel's button for the day picked, and one window that lists the day's notes and adds one.
// Only the user's own departments (config.noteDepts); none: the whole feature stays hidden.
// One part of the planningBoard Alpine component (see ./index.js); `this` is the whole component.
import { pad, csrf } from './shared';

export default ({ config }) => ({
    noteDepts: config.noteDepts || [],
    dayNotes: {},            // 'YYYY-MM-DD' -> [{ id, date, dept, body, by, at, edited, mine }]
    notesMonth: null,        // the month they were loaded for ('YYYY-MM'), so a month already shown is not asked again
    // the window: the day, where it opens from (a badge / the left panel), the text being added or edited
    dn: null,                // { date, x, y, text, dept, edit: null | id, editText, busy, error }

    hasNotes() { return this.noteDepts.length > 0; },
    notesOn(date) { return this.dayNotes[date] || []; },
    noteDeptName(id) { const d = this.noteDepts.find((x) => x.id === id); return d ? d.name : ''; },

    // the month on screen (and the days the grid shows of the months either side)
    async loadDayNotes(force) {
        if (!this.hasNotes()) return;
        const key = this.year + '-' + pad(this.month + 1);
        if (!force && this.notesMonth === key) return;
        const from = new Date(this.year, this.month, 1 - 7), to = new Date(this.year, this.month + 1, 7);
        const ymdOf = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        try {
            const res = await fetch(config.urls.dayNotes + '?from=' + ymdOf(from) + '&to=' + ymdOf(to), { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const map = {};
            (await res.json()).notes.forEach((n) => { (map[n.date] = map[n.date] || []).push(n); });
            this.dayNotes = map;
            this.notesMonth = key;
        } catch (e) { /* the badges simply stay as they were */ }
    },

    openDayNotes(date, ev) {
        if (!this.hasNotes()) return;
        const r = ev && ev.currentTarget ? ev.currentTarget.getBoundingClientRect() : null;
        const w = 340, x = r ? Math.max(8, Math.min(window.innerWidth - w - 8, r.right - w)) : 8, y = r ? r.bottom + 6 : 80;
        this.dn = { date, x, y, text: '', dept: this.noteDepts[0].id, edit: null, editText: '', busy: false, error: '' };
        this.$nextTick(() => { const t = document.getElementById('dn-text'); if (t) t.focus(); });
    },
    closeDayNotes() { this.dn = null; },

    async dayNoteSend(method, url, body) {
        this.dn.busy = true; this.dn.error = '';
        try {
            const res = await fetch(url, { method, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() }, body: body ? JSON.stringify(body) : undefined });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) { this.dn.error = (data.errors && Object.values(data.errors)[0][0]) || data.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง'; return null; }
            return data;
        } catch (e) { this.dn.error = 'ติดต่อเซิร์ฟเวอร์ไม่ได้ ลองอีกครั้ง'; return null; } finally { if (this.dn) this.dn.busy = false; }
    },
    async addDayNote() {
        const text = this.dn.text.trim();
        if (!text || this.dn.busy) return;
        const data = await this.dayNoteSend('POST', config.urls.dayNotes, { department_id: this.dn.dept, date: this.dn.date, body: text });
        if (!data) return;
        this.dayNotes = { ...this.dayNotes, [this.dn.date]: [...this.notesOn(this.dn.date), data.note] };
        this.dn.text = '';
    },
    editDayNote(n) { this.dn.edit = n.id; this.dn.editText = n.body; this.dn.error = ''; },
    async saveDayNote(n) {
        const text = this.dn.editText.trim();
        if (!text || this.dn.busy) return;
        const data = await this.dayNoteSend('PATCH', config.urls.dayNotes + '/' + n.id, { body: text });
        if (!data) return;
        this.dayNotes = { ...this.dayNotes, [n.date]: this.notesOn(n.date).map((x) => (x.id === n.id ? data.note : x)) };
        this.dn.edit = null;
    },
    async deleteDayNote(n) {
        if (this.dn.busy || !window.confirm('ลบหมายเหตุนี้?')) return;
        if (!(await this.dayNoteSend('DELETE', config.urls.dayNotes + '/' + n.id))) return;
        this.dayNotes = { ...this.dayNotes, [n.date]: this.notesOn(n.date).filter((x) => x.id !== n.id) };
    },
});
