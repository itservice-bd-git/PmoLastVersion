// Planning board · The left panel "งานที่ต้องเสร็จ": the day picked, what is due / running that day.
// One part of the planningBoard Alpine component (see ./index.js); `this` is the whole component.
import { MONTHS, WEEKDAYS, PRIORITIES, TH_DAYS, PRIORITY_RANK, TH_MON_SHORT, AVATAR_COLORS, MAX_LANES, SHIP_COLOR, TL_DAY, pad, ymd, parse, addDays, diffDays, hexToRgba, beFormat, beParse, csrf } from './shared';

export default ({ config, board, starKey, loadStars, t0 }) => ({
    // =================================================== left panel: "งานที่ต้องเสร็จ"
    shiftFocus(n) { this.focusDate = addDays(this.focusDate, n); const d = parse(this.focusDate); this.year = d.getFullYear(); this.month = d.getMonth(); },
    // the day stepper's word and sentence fragments, as the original words them
    focusWord() {
        const n = diffDays(this.focusDate, this.today);
        return { 0: 'วันนี้', 1: 'พรุ่งนี้', 2: 'มะรืนนี้', '-1': 'เมื่อวาน', '-2': 'เมื่อวานซืน' }[n] || 'วัน' + TH_DAYS[parse(this.focusDate).getDay()];
    },
    focusWhen() { // "วันนี้" / "พรุ่งนี้" / ... / "วันพุธที่ 08/10/2569"
        const n = diffDays(this.focusDate, this.today);
        return { 0: 'วันนี้', 1: 'พรุ่งนี้', 2: 'มะรืนนี้', '-1': 'เมื่อวาน', '-2': 'เมื่อวานซืน' }[n] || 'วัน' + TH_DAYS[parse(this.focusDate).getDay()] + 'ที่ ' + this.fmtBE(this.focusDate);
    },
    // the day a cabinet is due, as seen in the current view (a department's own date when one department is in view)
    cabEff(t, c) {
        if (!c) return this.dueFor(t);
        if (this.dept) return (c.deptDates && c.deptDates[this.dept]) || (this.stageOf(t, this.dept) || {}).due || t.date;
        return c.date || t.date;
    },
    cabIsDone(t, c) {
        if (!c) return this.isDone(t);
        if (this.dept) { const p = this.cabProgress(c, this.dept); if (p.total > 0) return p.done === p.total; }
        return this.cabDone(c);
    },
    taskCabs(t) { const done = t.subtasks.filter((c) => this.cabDone(c)).length; return t.subtasks.length ? { done, total: t.subtasks.length } : { done: this.isDone(t) ? 1 : 0, total: 1 }; },
    // Cabinets (not projects) due on the chosen day, and the overdue backlog when looking at today; grouped by project,
    // highest priority first - this is the original panel's bucketing.
    focusBuckets() {
        const today = this.today, late = [], due = [];
        let total = 0, done = 0;
        for (const t of this.visible()) {
            if (t.ship) continue;   // งานขนส่ง: its rounds are listed under "งานในวันที่" (and on งานจัดส่ง), not as due work
            const units = (t.subtasks.length ? t.subtasks : [null]).map((c) => ({ c, ed: this.cabEff(t, c), done: this.cabIsDone(t, c) })).filter((u) => u.ed);
            const dueU = units.filter((u) => u.ed === this.focusDate);
            const lateU = this.focusDate === today ? units.filter((u) => u.ed < today && !u.done) : [];
            if (dueU.length) { due.push({ t, cabs: dueU }); total += dueU.length; done += dueU.filter((u) => u.done).length; }
            if (lateU.length) { late.push({ t, cabs: lateU }); total += lateU.length; }
        }
        const byEntry = (a, b) => (PRIORITY_RANK[b.t.priority] || 0) - (PRIORITY_RANK[a.t.priority] || 0) || a.t.so.localeCompare(b.t.so);
        const sortCabs = (g) => g.cabs.sort((a, b) => (a.done - b.done) || a.ed.localeCompare(b.ed) || String(a.c && a.c.mo).localeCompare(String(b.c && b.c.mo)));
        [late, due].forEach((list) => { list.sort(byEntry); list.forEach(sortCabs); });
        return { late, due, total, done };
    },
    focusSections() {
        const b = this.focusBuckets(), count = (groups) => groups.reduce((n, g) => n + g.cabs.length, 0), out = [];
        if (b.late.length) out.push({ key: 'late', label: 'เลยกำหนด', danger: true, count: count(b.late), groups: b.late });
        out.push({ key: 'due', label: 'ครบกำหนด' + this.focusWhen(), danger: false, count: count(b.due), groups: b.due });
        // not in the original: what is merely RUNNING on that day (a click on a calendar day lands here), minus what is already listed above
        const listed = new Set([...b.late, ...b.due].map((g) => g.t.id));
        const run = this.dayTasks(this.focusDate).filter((t) => !listed.has(t.id)).map((t) => ({ t, cabs: t.ship
            ? this.dels(t).filter((d) => !d.pending && d.date <= this.focusDate && this.focusDate <= (d.end || d.date))
                .map((d) => ({ c: null, label: 'งานขนส่ง · รอบ ' + d.round + ' · ' + this.delState(d), ed: d.end || d.date, done: d.status === 'delivered', key: 'd' + d.id, did: d.id }))
            : (t.subtasks.length ? t.subtasks : [null]).map((c) => ({ c, ed: this.cabEff(t, c), done: this.cabIsDone(t, c) })) })).filter((g) => g.cabs.length);
        out.push({ key: 'run', label: 'งานในวันที่ ' + this.fmtBE(this.focusDate), danger: false, count: run.length, groups: run });
        // handed out with no due date yet (they cannot be on the calendar): one row per department
        const undated = this.visible().map((t) => ({ t, cabs: (t.deptStages || []).filter((st) => st.undated > 0 && (!this.dept || st.deptId === this.dept))
            .map((st) => ({ c: null, label: this.deptOf(st.deptId).name + ' · ' + st.undated + ' งาน', ed: '', done: false, key: st.deptId })) })).filter((g) => g.cabs.length);
        // template pairs: what the Sub Tasks my work compares with said is coming (late, or within 7 days) and I have not ticked yet
        const soon = addDays(this.today, 7);
        const incoming = this.visible().map((t) => ({ t, cabs: t.subtasks.flatMap((c) => (c.linked || []).flatMap((l) => l.items
            .filter((i) => i.expected && !i.received && i.expected <= soon)
            .map((i) => ({ c, label: this.deptOf(l.deptId).name + ' · ' + i.text, ed: i.expected, done: false, key: i.id })))) }))
            .filter((g) => g.cabs.length).map((g) => ({ ...g, cabs: g.cabs.sort((a, b) => a.ed.localeCompare(b.ed)) }));
        if (incoming.length) out.push({ key: 'incoming', label: '📦 จากแผนกต้นทาง (ภายใน 7 วัน)', danger: false, count: incoming.reduce((n, g) => n + g.cabs.length, 0), groups: incoming });
        if (undated.length) out.push({ key: 'undated', label: 'ยังไม่มีวันส่ง', danger: true, count: undated.reduce((n, g) => n + g.cabs.length, 0), groups: undated });
        return out;
    },
    focusCounts() { const b = this.focusBuckets(); return { total: b.total, done: b.done, pct: b.total ? Math.round(b.done / b.total * 100) : 0 }; },
    focusEmpty() { return this.focusSections().every((sec) => sec.groups.length === 0); },
    // one cabinet row's date: "เลย N วัน" (red) when overdue, else dd/mm/yyyy (พ.ศ.)
    rowDate(ed) { if (!ed) return 'ไม่มีวันส่ง'; const n = diffDays(ed, this.today); return n < 0 ? 'เลย ' + (-n) + ' วัน' : this.fmtBE(ed); },
    rowName(c, t, u) { if (u && u.label) return u.label; return c ? ((c.mo || '').trim() ? c.mo + ' ' + c.text : c.text) : '(ทั้งโปรเจค — ยังไม่มีตู้)'; },
    rowColor(t, c) { return c ? this.cabColor(c) : this.statusColor(t); },

    // =================================================== day list
    dayTasks(date) {
        const ids = new Set(this.calEntries().filter((e) => e.from <= date && date <= e.to).map((e) => e.t.id));
        return this.visible().filter((t) => ids.has(t.id)).sort((a, b) => a.so.localeCompare(b.so));
    },
    // clicking a day in the calendar shows that day in the left panel ("งานที่ต้องเสร็จ"): what is due, and what is running
    selectDay(date) {
        this.focusDate = date;
        if (window.innerWidth < 1024) window.scrollTo({ top: 0, behavior: 'smooth' });   // on a phone the panel is above the calendar
    },

});
