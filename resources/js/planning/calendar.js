// Planning board · เดือน and ไทม์ไลน์: what the calendar draws, the week lanes, the bars, dragging a bar to another day.
// One part of the planningBoard Alpine component (see ./index.js); `this` is the whole component.
import { MONTHS, WEEKDAYS, PRIORITIES, TH_DAYS, PRIORITY_RANK, TH_MON_SHORT, AVATAR_COLORS, MAX_LANES, SHIP_COLOR, TL_DAY, pad, ymd, parse, addDays, diffDays, hexToRgba, beFormat, beParse, csrf } from './shared';

export default ({ config, board, starKey, loadStars, t0 }) => ({
    // =================================================== month grid
    rangeLabel() { return MONTHS[this.month] + ' ' + (this.year + 543); },
    weekdays() { return WEEKDAYS; },
    gridDays() {
        const first = new Date(this.year, this.month, 1), last = new Date(this.year, this.month + 1, 0);
        const from = new Date(first); from.setDate(from.getDate() - ((from.getDay() + 6) % 7));
        const to = new Date(last); to.setDate(to.getDate() + ((7 - to.getDay()) % 7));
        const days = [];
        for (const d = new Date(from); d <= to; d.setDate(d.getDate() + 1)) {
            days.push({ date: ymd(d), day: d.getDate(), inMonth: d.getMonth() === this.month });
        }
        return days;
    },
    weekRows() { const days = this.gridDays(), rows = []; for (let i = 0; i < days.length; i += 7) rows.push(days.slice(i, i + 7)); return rows; },
    shiftMonth(n) { const d = new Date(this.year, this.month + n, 1); this.year = d.getFullYear(); this.month = d.getMonth(); },
    goToday() { const d = parse(this.today); this.year = d.getFullYear(); this.month = d.getMonth(); this.focusDate = this.today; },

    // One bar per project over its dates; in a department view one single-day chip on that department's due date.
    // ---- what the calendar draws (month + timeline + "งานในวันที่"): one entry per project - or per cabinet with
    //      "แยกรายตู้" - over its dates in the current view. In a department's view that is the department's own range
    //      (start - end of all its work in the project); unfinished work past its end runs on to TODAY (red), so late
    //      work is always in sight. Work with no due date is not drawn (it is listed in the left panel instead).
    rangeOf(t, c) {
        const today = this.today;
        const make = (start, end, done) => {
            if (!end) return null;
            const late = !done && end < today;
            return { from: start && start <= end ? start : end, to: late ? today : end, end, done, late };
        };
        if (t.ship) { const r = this.shipSpan(t); return r ? make(r.start, r.end, r.done) : null; }
        if (this.dept) {
            const r = c ? (c.deptRanges || {})[this.dept] : this.stageOf(t, this.dept);
            return r ? make(r.start, r.end, c ? !!r.done : (this.stageDone(r) || this.isDone(t))) : null;
        }
        if (c) {
            const rs = Object.values(c.deptRanges || {}).filter((r) => r.end);
            if (!rs.length) return null;
            return make(rs.map((r) => r.start || r.end).sort()[0], rs.map((r) => r.end).sort().pop(), rs.every((r) => r.done));
        }
        return make(t.startDate, t.date, this.isDone(t));
    },
    calEntries() {
        const out = [];
        for (const t of this.visible()) {
            if (this.byCabinet && t.subtasks.length) {
                // "แยกรายตู้": a finished cabinet is left out too while finished work is hidden
                t.subtasks.filter((c) => !(this.hideDone && this.cabDone(c))).forEach((c) => { const r = this.withFat(t, c, this.rangeOf(t, c)); if (r) out.push({ t, c, key: t.id + c.id, ...r }); });
            } else {
                const r = this.withFat(t, null, this.rangeOf(t, null));
                if (r) out.push({ t, c: null, key: t.id, ...r });
            }
        }
        return out;
    },
    // a bar runs over its FAT and delivery days too (a FAT after the due date / before the start), so the band always sits on its own
    // project; the due date (end) stays, only the drawn range grows
    withFat(t, c, r) {
        if (!r) return r;
        // its delivery days too (a round after the due date); a round waiting for sales has no day to draw
        const f = t.fat && !t.fat.pending && !t.ship ? t.fat : null, days = this.dels(t, c).filter((d) => !d.pending).flatMap((d) => [d.date, d.end || d.date]);
        if (f && !(c && f.cabinets && !f.cabinets.includes(c.id))) days.push(f.date, f.end || f.date);
        if (!days.length) return r;
        days.sort();
        return { ...r, from: days[0] < r.from ? days[0] : r.from, to: days[days.length - 1] > r.to ? days[days.length - 1] : r.to };
    },
    // "แสดงงานที่เสร็จแล้ว": how many the calendars leave out now, and the switch (remembered)
    doneHidden() { return this.hideDone ? this.visible(true).filter((t) => this.doneFor(t)).length : 0; },
    setShowDone(on) { this.hideDone = !on; try { localStorage.setItem('planning.showDone', on ? '1' : ''); } catch (e) { /* not remembered */ } },
    setByCabinet(on) { this.byCabinet = on; try { localStorage.setItem('planning.byCabinet', on ? '1' : ''); } catch (e) { /* not remembered */ } },
    // how much is going on that day (unfinished entries running through it) and whether any of it is late - the badge on the day
    weekBars(week) {
        const ws = week[0].date, we = week[week.length - 1].date;
        const entries = this.calEntries().filter((e) => e.from <= we && e.to >= ws)
          .sort((a, b) => (a.from === b.from ? a.t.so.localeCompare(b.t.so) : a.from.localeCompare(b.from)));
        const laneEnd = [], bars = [];
        for (const e of entries) {
            const cs = e.from < ws ? ws : e.from, ce = e.to > we ? we : e.to;
            const a = week.findIndex((d) => d.date === cs), b = week.findIndex((d) => d.date === ce);
            let lane = laneEnd.findIndex((end) => end < a);
            if (lane === -1) { lane = laneEnd.length; laneEnd.push(-1); }
            laneEnd[lane] = b;
            bars.push({ ...e, lane, cs, ce, col: a + 1, span: b - a + 1, before: e.from < ws, after: e.to > we });
        }
        return bars;
    },
    shownBars(week) { return this.openWeeks[week[0].date] ? this.weekBars(week) : this.weekBars(week).filter((b) => b.lane < MAX_LANES); },
    hiddenCount(week, date) { return this.openWeeks[week[0].date] ? 0 : this.weekBars(week).filter((b) => b.lane >= MAX_LANES && b.cs <= date && date <= b.ce).length; },
    // "+n โครงการ" opens the whole week (every bar, its FAT band too); "▲ ย่อกลับ" closes it
    toggleWeek(week, open) { this.openWeeks = { ...this.openWeeks, [week[0].date]: open }; },
    barStyle(b) {
        const color = this.barColor(b.t);   // the bar is always the PROJECT's status colour (งานขนส่ง its own); late shows as "เลย n วัน"
        return 'grid-column:' + b.col + ' / span ' + b.span + ';grid-row:' + (b.lane + 1) + ';' + this.chipStyle(color, b.done);
    },
    barTitle(b) {
        if (b.t.ship) return b.t.so + ' ' + b.t.title + ' — งานขนส่ง (ไม่ใช่งาน Sub Task): รอบส่ง ' + this.dels(b.t).filter((d) => !d.pending).length + ' รอบ · ' + this.fmtBE(b.from) + ' – ' + this.fmtBE(b.end) + (b.late ? ' (ยังไม่ส่งถึง เลย ' + diffDays(this.today, b.end) + ' วัน)' : '');
        return b.t.so + ' ' + b.t.title + (b.c ? ' · ' + b.c.mo + ' ' + b.c.text : '') + ' — สถานะโครงการ: ' + this.statusOf(b.t).name + (this.dept && this.stageOf(b.t, this.dept) ? ' · ' + this.deptOf(this.dept).name + ': ' + this.stageStatus(this.stageOf(b.t, this.dept)).name : '')
            + ' · ' + this.fmtBE(b.from) + ' – ' + this.fmtBE(b.end) + (b.late ? ' (เลยกำหนด ' + diffDays(this.today, b.end) + ' วัน)' : '');
    },
    barLabel(b) { return (b.before ? '◄ ' : '') + (b.c ? (b.c.mo || b.c.text) + ' · ' + b.t.so : b.t.so + ' ' + b.t.title) + (b.late ? ' · เลย ' + diffDays(this.today, b.end) + ' วัน' : ''); },

    // drag a bar onto another day (admin / PM): the project moves by that many days
    dragStart(e, t) { if (!this.isPmo) { e.preventDefault(); return; } this.drag = t.id; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', t.id); },
    dropOn(date) {
        const t = this.drag ? this.taskById(this.drag) : null;
        this.drag = null;
        if (!t || !this.isPmo || this.dept) return;
        const anchor = (t.startDate && t.startDate <= t.date) ? t.startDate : t.date;
        const delta = diffDays(date, anchor);
        if (!delta) return;
        const fields = { date: addDays(t.date, delta) };
        if (t.startDate) fields.startDate = addDays(t.startDate, delta);
        this.editProject(t, fields);
    },

    // =================================================== timeline
    tlDays() { const n = new Date(this.year, this.month + 1, 0).getDate(); return Array.from({ length: n }, (_, i) => ({ date: this.year + '-' + pad(this.month + 1) + '-' + pad(i + 1), day: i + 1 })); },
    tlTemplate() { return 'repeat(' + this.tlDays().length + ', ' + TL_DAY + 'px)'; },
    tlRows() {
        const days = this.tlDays(), ws = days[0].date, we = days[days.length - 1].date;
        return this.calEntries().filter((r) => r.from <= we && r.to >= ws).sort((a, b) => a.from.localeCompare(b.from)).map((r) => {
            const cs = r.from < ws ? ws : r.from, ce = r.to > we ? we : r.to;
            const a = days.findIndex((d) => d.date === cs), b = days.findIndex((d) => d.date === ce);
            return { ...r, col: a + 1, span: b - a + 1, before: r.from < ws, after: r.to > we };
        });
    },
    tlTodayLeft() { const i = this.tlDays().findIndex((d) => d.date === this.today); return i < 0 ? null : 220 + i * TL_DAY + TL_DAY / 2; },

});
