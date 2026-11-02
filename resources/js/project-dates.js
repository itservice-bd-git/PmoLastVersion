// เลื่อน / แก้วัน (projects/partials/_dates): the window's own sums - days moved (and Mon-Fri days), and since the first due date.
// Global, not an inline script: Planning fetches the window into its page, where an inline script would not run.
export default function pmoProjectDates(f, due, first) {
    const day = (s) => (s ? new Date(s + 'T00:00:00') : null);
    const between = (a, b) => Math.round((day(b) - day(a)) / 86400000);
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
    };
}
