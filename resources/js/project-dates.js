// เลื่อน / แก้วัน (projects/partials/_dates): the window's own sums - days moved (and Mon-Fri days), and since the first due date -
// and the FAT / delivery rounds that go along: where each lands, and a warning when one ends up after the new due date.
// Global, not an inline script: Planning fetches the window into its page, where an inline script would not run.
export default function pmoProjectDates(f, due, first) {
    const day = (s) => (s ? new Date(s + 'T00:00:00') : null);
    const between = (a, b) => Math.round((day(b) - day(a)) / 86400000);
    const ymd = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    const thai = (s) => { const d = day(s); return d ? String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + '/' + (d.getFullYear() + 543) : ''; };
    return {
        f,
        shift() { return due && this.f.due && this.f.due !== due ? between(due, this.f.due) : null; },
        total() { return first && this.f.due ? between(first, this.f.due) : null; },
        workdays() {
            const [a, b] = [day(due), day(this.f.due)].sort((x, y) => x - y);
            let n = 0;
            for (const d = new Date(a); d < b; d.setDate(d.getDate() + 1)) { if (d.getDay() % 6 !== 0) n++; }
            return n;
        },
        // a round's choice in the mode open: its own field per mode, so switching modes keeps both
        action(e) { return this.f.kind === 'pending' ? e.wait : e.post; },
        // where a round lands on the postponement ('' = it waits / stays waiting)
        lands(e) {
            const a = e.post;
            if (a === 'pending' || (a === 'keep' && e.pending)) return '';
            if (a === 'shift') { const n = this.shift(); if (n === null || e.pending && !e.date) return e.date; const d = day(e.date); d.setDate(d.getDate() + n); return ymd(d); }
            if (a === 'date') return e.on || '';
            return e.date;
        },
        landsText(e) { const d = this.lands(e); return d ? thai(d) : 'รอวันใหม่'; },
        moved(e) { return this.f.kind === 'postpone' && this.lands(e) && this.lands(e) !== e.date; },
        // said, never refused: a round left after the new due date
        late(e) { return this.f.kind === 'postpone' && this.f.due && this.lands(e) && this.lands(e) > this.f.due; },
        warnings() { return this.f.kind === 'postpone' ? this.f.events.filter((e) => this.late(e)).map((e) => e.label + ' (' + thai(this.lands(e)) + ')') : []; },
        followDays() { const n = this.f.follow ? between(ymd(new Date()), this.f.follow) : null; return n === null ? '' : (n <= 0 ? 'วันนี้' : 'อีก ' + n + ' วัน'); },
    };
}
