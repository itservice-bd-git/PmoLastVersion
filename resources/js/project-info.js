/** "ข้อมูลโครงการ": the cabinet table's search / filters / pages, and which alert popover is open (one at a time). */
export default function pmoProjectInfo(rows) {
    const STATUS = { not_started: ['ยังไม่เริ่ม', 'bg-slate-100 text-slate-600'], in_progress: ['กำลังผลิต', 'bg-blue-50 text-blue-700'],
        completed: ['เสร็จแล้ว', 'bg-emerald-50 text-emerald-700'], on_hold: ['พักไว้', 'bg-amber-50 text-amber-700'] };
    const RISK = { high: ['สูง', 'text-red-600'], watch: ['เฝ้าระวัง', 'text-orange-600'], ok: ['ปกติ', 'text-slate-500'], none: ['-', 'text-slate-400'] };
    return {
        rows, q: '', status: '', type: '', risk: '', page: 1, per: 20, alert: null, filters: false,
        activeFilters() { return [this.status, this.type, this.risk].filter(Boolean).length; },
        filtered() {
            const q = this.q.trim().toLowerCase();
            return this.rows.filter((r) => (! q || (r.mo + ' ' + (r.model || '')).toLowerCase().includes(q))
                && (! this.status || r.status === this.status)
                && (! this.type || r.type === this.type)
                && (! this.risk || (this.risk === 'delayed' ? r.delayed : (this.risk === 'late-tasks' ? r.lateTasks > 0 : r.risk === this.risk))));
        },
        pages() { return Math.max(1, Math.ceil(this.filtered().length / this.per)); },
        paged() { this.page = Math.min(this.page, this.pages()); return this.filtered().slice((this.page - 1) * this.per, this.page * this.per); },
        statusLabel: (s) => (STATUS[s] || [s])[0], statusClass: (s) => (STATUS[s] || ['', 'bg-slate-100 text-slate-600'])[1],
        riskLabel: (r) => RISK[r][0], riskClass: (r) => RISK[r][1],
        // the due column only speaks up when it matters: late (red) or within a week (orange)
        dueLabel: (d) => d === null ? '' : (d < 0 ? '· เลย ' + (-d) + ' วัน' : (d === 0 ? '· วันนี้' : '· ' + d + ' วัน')),
        dueClass: (d) => d === null ? '' : (d < 0 ? 'text-red-600' : (d <= 7 ? 'text-orange-600' : 'text-slate-400')),
    };
}
