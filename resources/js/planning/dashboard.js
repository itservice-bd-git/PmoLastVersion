// Planning board · แดชบอร์ด.
// One part of the planningBoard Alpine component (see ./index.js); `this` is the whole component.
import { MONTHS, WEEKDAYS, PRIORITIES, TH_DAYS, PRIORITY_RANK, TH_MON_SHORT, AVATAR_COLORS, MAX_LANES, SHIP_COLOR, TL_DAY, pad, ymd, parse, addDays, diffDays, hexToRgba, beFormat, beParse, csrf } from './shared';

export default ({ config, board, starKey, loadStars, t0 }) => ({
    // =================================================== dashboard
    // a department's name as a tag in its own colour (the bars stay one colour, so the colour says who, not how far)
    deptTag(id) { const d = this.deptOf(id); return 'background-color:' + hexToRgba(d.color, 0.16) + ';border-color:' + hexToRgba(d.color, 0.55); },
    deptLate(s) { return !this.stageDone(s) && !!s.end && s.end < this.today; },
    // the dashboard of ONE project (the "โครงการ" picker, or "แดชบอร์ด" in a project's window)
    openProjectDash(id) { this.closeModal(); this.dashProject = id; this.mode = 'dashboard'; window.scrollTo({ top: 0, behavior: 'smooth' }); },
    projectDash() {
        const t = this.taskById(this.dashProject);
        if (!t) return null;
        const scope = this.dept || null, cp = this.progress(t, scope);
        const stages = (t.deptStages || []).filter((s) => !scope || s.deptId === scope).map((s) => ({
            s, dp: this.deptOf(s.deptId), status: this.stageStatus(s), late: this.deptLate(s), prog: this.progress(t, s.deptId),
            lateDays: this.deptLate(s) ? diffDays(this.today, s.end) : 0,
        }));
        const cabs = t.subtasks.map((c) => ({
            c, status: this.cabStatus(c), prog: this.cabProgress(c, scope), due: c.ownDue || c.date,
            depts: Object.keys(c.deptRanges || {}).filter((d) => !scope || d === scope).map((d) => ({ id: d, prog: this.cabProgress(c, d), done: !!c.deptRanges[d].done })),
        }));
        return {
            t, cp, stages, cabs,
            cabsDone: cabs.filter((x) => this.cabDone(x.c)).length,
            stagesDone: stages.filter((x) => this.stageDone(x.s)).length,
            late: stages.filter((x) => x.late).length,
            waiting: stages.reduce((n, x) => n + ((x.s.pmo && x.s.pmo.waiting) || 0), 0),
        };
    },
    dash() {
        // the open projects only, as the projects list (ProjectController@index): a Completed / Cancelled one is
        // history there (ปิดแล้ว), whatever its Planning status still says
        const locked = (t) => ['completed', 'cancelled'].includes(t.info && t.info.pmoStatus);
        const list = this.visible().filter((t) => !locked(t)), today = this.today;
        const doneOf = (t) => this.doneFor(t), dueOf = (t) => this.dueFor(t);
        // no due date of its own: never late (its date here is a stand-in - its latest Sub Task's, or the day made)
        const noOwnDate = (t) => !!(t.warnings && t.warnings.noDue);
        const undated = (t) => !this.dept && noOwnDate(t);   // the cards count a picked department by its own dates
        const late = (t) => !undated(t) && !!this.overdueFor(t);
        // the table: the projects list's order, by the project's own dates even while a department is picked -
        // late first, then รอฝ่ายขายแจ้งกำหนดส่ง (never late), then the nearest due date, then the order made;
        // no due date last
        const rank = (t) => (t.pending ? 1 : noOwnDate(t) ? 3 : !this.isDone(t) && t.date < today ? 0 : 2);
        const order = (a, b) => (rank(a) - rank(b))
            || (rank(a) === 3 ? 0 : String(a.date).localeCompare(String(b.date)))
            || (Number(String(a.id).slice(1)) - Number(String(b.id).slice(1)));
        const items = list.flatMap((t) => this.items(t, this.dept || null));
        const doneItems = items.filter((i) => i.done).length;
        const bucket = [{ label: 'เลยกำหนด', n: 0, late: true }, { label: 'ภายใน 7 วัน', n: 0 }, { label: '8–14 วัน', n: 0 }, { label: '15–21 วัน', n: 0 }, { label: '22+ วัน', n: 0 }];
        list.filter((t) => !doneOf(t) && !undated(t) && dueOf(t)).forEach((t) => {
            const d = diffDays(dueOf(t), today);
            bucket[d < 0 ? 0 : d <= 7 ? 1 : d <= 14 ? 2 : d <= 21 ? 3 : 4].n++;
        });
        const depts = this.departments.map((dp) => {
            const stages = list.map((t) => this.stageOf(t, dp.id)).filter(Boolean);
            const its = list.flatMap((t) => this.items(t, dp.id));
            const done = its.filter((i) => i.done).length;
            return { dp, stages: stages.length, stagesDone: stages.filter((s) => this.stageDone(s)).length, late: stages.filter((s) => this.deptLate(s)).length, done, total: its.length, pct: its.length ? Math.round(done / its.length * 100) : 0 };
        }).filter((r) => r.stages > 0);
        return {
            total: list.length,
            completed: list.filter(doneOf).length,
            doing: list.filter((t) => !doneOf(t) && this.statusOf(t).id === 's_doing').length,
            late: list.filter(late).length,
            checkPct: items.length ? Math.round(doneItems / items.length * 100) : 0, checkDone: doneItems, checkTotal: items.length,
            bucket, bucketMax: Math.max(1, ...bucket.map((b) => b.n)), depts,
            rows: list.slice().sort(order),
        };
    },

});
