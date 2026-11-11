// Planning board · Lookups (a task / cabinet / department by id, names, colours, dates) and what the filters let through.
// One part of the planningBoard Alpine component (see ./index.js); `this` is the whole component.
import { MONTHS, WEEKDAYS, PRIORITIES, TH_DAYS, PRIORITY_RANK, TH_MON_SHORT, AVATAR_COLORS, MAX_LANES, SHIP_COLOR, TL_DAY, pad, ymd, parse, addDays, diffDays, hexToRgba, beFormat, beParse, csrf } from './shared';

export default ({ config, board, starKey, loadStars, t0 }) => ({
    // =================================================== lookups
    // a PROJECT's status: the one Admin / PM picked (Settings > สถานะงาน), else PMO's own project status (pmo_*)
    statusOf(t) { return this.statuses.find((s) => s.id === t.statusId) || this.pmoStatusList.find((s) => s.id === t.statusId) || this.pmoStatusList[0] || this.statuses[0]; },
    statusColor(t) { return this.statusOf(t).color; },
    // the bar colours in use (the calendar colours bars by project status): Settings' list, then PMO's ones shown by a project
    legendStatuses() { const used = new Set(this.tasks.map((t) => this.statusOf(t).id)); return this.statuses.concat(this.pmoStatusList.filter((s) => used.has(s.id))); },
    isDone(t) { return !!this.statusOf(t).isDone; },
    deptOf(id) { return this.departments.find((d) => d.id === id) || { id, name: id, color: '#98a2b3', icon: '' }; },
    stageOf(t, deptId) { return (t.deptStages || []).find((s) => s.deptId === deptId) || null; },
    // a DEPARTMENT's status in a project: worked out from its work (รอรับ / รับแล้ว / กำลังทำ / เสร็จ / เกินกำหนด), or set by Admin / PM
    stageStatus(s) { return this.statuses.find((x) => x.id === s.statusId) || this.statuses[0] || { name: '', color: '#98a2b3' }; },
    stageDone(s) { return s.manualStatus ? !!this.stageStatus(s).isDone : !!s.done; },
    taskById(id) { return this.tasks.find((t) => t.id === id) || null; },
    get cur() { return this.modalId ? this.taskById(this.modalId) : null; },
    flag(t) { return t.priority && t.priority !== 'none' ? PRIORITIES[t.priority].color : ''; },
    chipStyle(hex, done) { return 'background-color:' + hexToRgba(hex, done ? 0.12 : 0.17) + ';border-left-color:' + hex; },
    fmtBE(s) { if (!s) return '-'; const [y, m, d] = s.split('-'); return d + '/' + m + '/' + (Number(y) + 543); },
    // "04/11/2569", "04–06/11/2569", "30/10/2569 – 02/11/2569" (as ProjectFat::dateRange)
    fmtRangeBE(a, b) { if (!b || b === a) return this.fmtBE(a); return a.slice(0, 7) === b.slice(0, 7) ? a.slice(8) + '–' + this.fmtBE(b) : this.fmtBE(a) + ' – ' + this.fmtBE(b); },
    fatDays(f) { return f.end && f.end !== f.date ? diffDays(f.end, f.date) + 1 : 1; },
    // the projects list's countdown chip under the due date: [text, classes]
    dueChip(date) {
        const n = this.daysFromToday(date);
        return [n < 0 ? 'เลยกำหนด ' + (-n) + ' วัน' : n === 0 ? 'ครบกำหนดวันนี้' : 'เหลือ ' + n + ' วัน', n < 0 ? 'bg-red-100 text-red-700' : n <= 7 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500'];
    },
    isStarred(t) { return this.stars.includes(t.id); },
    toggleStar(t) {
        this.stars = this.isStarred(t) ? this.stars.filter((x) => x !== t.id) : [...this.stars, t.id];
        try { localStorage.setItem(starKey, JSON.stringify(this.stars)); } catch (e) { /* not remembered */ }
    },
    // a department chip's due date as the original page words it: "17 ต.ค.", "วันนี้" / "พรุ่งนี้" (amber), overdue (red)
    stageInfo(s) {
        const end = 'end' in s ? s.end : s.due;   // the department's real end date (null = nothing dated yet)
        if (!end) return { label: s.done ? '—' : 'ไม่มีวันส่ง', cls: s.done ? 'text-slate-400' : 'text-amber-700 font-semibold' };
        const diff = diffDays(end, this.today), d = parse(end);
        let label = d.getDate() + ' ' + TH_MON_SHORT[d.getMonth()], cls = 'text-slate-500';
        if (!s.done) {
            if (diff === 0) { label = 'วันนี้'; cls = 'text-amber-700 font-semibold'; }
            else if (diff === 1) { label = 'พรุ่งนี้'; cls = 'text-amber-700 font-semibold'; }
            else if (diff === 2) { cls = 'text-amber-700 font-semibold'; }
            else if (diff < 0) { cls = 'text-red-600 font-bold'; }
        } else { cls = 'text-emerald-700'; }
        return { label, cls };
    },
    fmtDateTime(ts) { const d = new Date(ts); return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + (d.getFullYear() + 543) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); },
    avatarColor(name) { let h = 0; for (const ch of String(name || '')) h = (h * 31 + ch.charCodeAt(0)) >>> 0; return AVATAR_COLORS[h % AVATAR_COLORS.length]; },
    initial(name) { return String(name || '?').trim().charAt(0).toUpperCase(); },
    scrollFeed() { this.$nextTick(() => { const el = this.$refs.feedBox; if (el) el.scrollTop = el.scrollHeight; }); },
    daysFromToday(date) { return date ? diffDays(date, this.today) : 0; },
    isWeekend(date) { const n = parse(date).getDay(); return n === 0 || n === 6; },
    // a task's date as seen in the current department view: that department's own due date, else the project's
    // งานขนส่ง (t.ship, PlanningBoard): a project the delivery department has no Sub Task in, only rounds sent to it -
    // drawn over its rounds' days (first to last), done when every round is delivered, in a colour of its own
    shipSpan(t) { const ds = this.dels(t).filter((d) => !d.pending); return ds.length ? { start: ds.map((d) => d.date).sort()[0], end: ds.map((d) => d.end || d.date).sort().pop(), done: ds.every((d) => d.status === 'delivered') } : null; },
    barColor(t) { return t.ship ? SHIP_COLOR : this.statusColor(t); },
    dueFor(t) { if (t.ship) { const r = this.shipSpan(t); return r ? r.end : null; } if (this.dept) { const s = this.stageOf(t, this.dept); if (s) return s.end; } return t.date; },
    doneFor(t) { if (t.ship) { const r = this.shipSpan(t); return !r || r.done; } if (this.dept) { const s = this.stageOf(t, this.dept); if (s) return this.stageDone(s) || this.isDone(t); } return this.isDone(t); },
    // a project waiting for sales to give its new date (รอฝ่ายขายแจ้งกำหนดส่ง) is never late by it; a picked department's own dates still count
    overdueFor(t) { return !(t.pending && !this.dept) && !this.doneFor(t) && this.dueFor(t) && this.dueFor(t) < this.today; },
    items(t, deptId) { // checklist items of a task, optionally only one department's
        return t.subtasks.flatMap((c) => c.checklist.filter((i) => !deptId || i.deptId === deptId));
    },
    progress(t, deptId) { const it = this.items(t, deptId); const done = it.filter((i) => i.done).length; return { done, total: it.length, pct: it.length ? Math.round(done / it.length * 100) : 0 }; },
    cabStatus(c) { return this.statuses.find((s) => s.id === c.statusId) || this.statuses[0]; },
    cabColor(c) { return this.cabStatus(c).color; },
    cabDone(c) { return !!this.cabStatus(c).isDone; },
    cabProgress(c, deptId) { const it = c.checklist.filter((i) => !deptId || i.deptId === deptId); const done = it.filter((i) => i.done).length; return { done, total: it.length, pct: it.length ? Math.round(done / it.length * 100) : 0 }; },

    // =================================================== filtering
    // withDone: finished work counted too (the dashboard always; the "แสดงงานที่เสร็จแล้ว (n)" count)
    visible(withDone = this.mode === 'dashboard') {
        const q = this.search.trim().toLowerCase();
        return this.tasks.filter((t) => {
            if (!withDone && this.hideDone && this.doneFor(t)) return false;
            if (this.priority !== 'all' && t.priority !== this.priority) return false;
            if (this.starOnly && !this.isStarred(t)) return false;
            if (this.mineOnly && !this.mine.includes(Number(t.id.slice(1)))) return false;
            if (this.dept && !t.ship && !this.stageOf(t, this.dept)) return false;
            if (this.multiDepts.length && !t.ship && !this.multiDepts.some((d) => this.stageOf(t, d))) return false;
            if (q && ![t.so, t.title, ...t.subtasks.map((c) => c.mo + ' ' + c.text)].join(' ').toLowerCase().includes(q)) return false;
            return true;
        });
    },

});
