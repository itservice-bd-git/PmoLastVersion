<script>
    document.addEventListener('alpine:init', () => {
        const MONTHS = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
        const WEEKDAYS = ['วันอาทิตย์','วันจันทร์','วันอังคาร','วันพุธ','วันพฤหัสบดี','วันศุกร์','วันเสาร์'];
        const STATUS_RANK = { IN_PROGRESS: 0, ACCEPTED: 1, ASSIGNED: 2, COMPLETED: 3 };
        // Categorical colors for "which Project is this" (bar identity), kept away from
        // blue/indigo since those are the brand/primary-action color everywhere else
        // in the app and would be confused for "selected"/"primary" rather than a category.
        // `hex` is the Tailwind 500-shade of the same color (this app doesn't
        // customize these in tailwind.config.js, only blue/indigo) - used as the
        // swatch preview and as the base for a user's custom per-Project color
        // (projects.color) in taskColor() below; bar/dot/accent stay Tailwind
        // classes for the (far more common) auto-assigned, uncustomized case.
        const TASK_PALETTE = [
            { bar: 'bg-orange-100 text-orange-800', dot: 'bg-orange-500', accent: 'border-l-orange-400', hex: '#f97316' },
            { bar: 'bg-emerald-100 text-emerald-800', dot: 'bg-emerald-500', accent: 'border-l-emerald-400', hex: '#10b981' },
            { bar: 'bg-purple-100 text-purple-800', dot: 'bg-purple-500', accent: 'border-l-purple-400', hex: '#a855f7' },
            { bar: 'bg-pink-100 text-pink-800', dot: 'bg-pink-500', accent: 'border-l-pink-400', hex: '#ec4899' },
            { bar: 'bg-teal-100 text-teal-800', dot: 'bg-teal-500', accent: 'border-l-teal-400', hex: '#14b8a6' },
            { bar: 'bg-amber-100 text-amber-800', dot: 'bg-amber-500', accent: 'border-l-amber-400', hex: '#f59e0b' },
            { bar: 'bg-cyan-100 text-cyan-800', dot: 'bg-cyan-500', accent: 'border-l-cyan-400', hex: '#06b6d4' },
            { bar: 'bg-rose-100 text-rose-800', dot: 'bg-rose-500', accent: 'border-l-rose-400', hex: '#f43f5e' },
            { bar: 'bg-fuchsia-100 text-fuchsia-800', dot: 'bg-fuchsia-500', accent: 'border-l-fuchsia-400', hex: '#d946ef' },
            { bar: 'bg-lime-100 text-lime-800', dot: 'bg-lime-500', accent: 'border-l-lime-400', hex: '#84cc16' },
        ];
        const hashString = (s) => { let h = 0; for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0; return h; };
        const hexToRgba = (hex, alpha) => {
            const full = hex.replace('#', '').replace(/^([0-9a-f]{3})$/i, '$1$1');
            const n = parseInt(full, 16);
            return 'rgba(' + ((n >> 16) & 255) + ', ' + ((n >> 8) & 255) + ', ' + (n & 255) + ', ' + alpha + ')';
        };
        const pad = (n) => String(n).padStart(2, '0');
        const ymd = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        const parse = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
        const csrf = () => document.querySelector('meta[name="csrf-token"]').content;

        async function api(url, method = 'GET', body) {
            const res = await fetch(url, {
                method,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: body ? JSON.stringify(body) : undefined,
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.message || 'ไม่สามารถดำเนินการได้ กรุณาลองใหม่');
            return data;
        }

        Alpine.data('myDepartment', (config) => {
            const start = parse(config.today);

            return {
                mainView: 'calendar', // 'calendar' (Month/Week/List) | 'timeline' (Gantt-style) - both grouped by Project
                timelineSearch: '', // client-side only - filters timelineGroups(), never re-fetches or widens the department scope
                hideCompleted: false, // now a Project-level filter (see visibleProjectGroups()) - remembered per-browser via localStorage
                view: 'calendar',
                range: 'month', // 'month' | '7' (rolling 7 days, "สัปดาห์")
                anchor: config.today, // first day of a rolling range
                today: config.today,
                year: start.getFullYear(),
                month: start.getMonth(),
                selectedDate: config.today,
                departmentId: config.departmentId,
                departmentName: config.departmentName,
                items: [], // raw Sub Tasks from the server - unchanged shape/scope
                projectGroupsList: [], // Sub Tasks grouped by Project - see computeProjectGroups()
                dayMap: {}, // date -> Project groups active that date (Calendar day panel)
                weekBarsMap: {}, // Calendar month/week bars, one per Project
                loading: false,
                loadSeq: 0,
                detail: null, // single Sub Task detail (existing modal, unchanged)
                detailLoading: false,
                panel: null, // open Right Detail Panel - one Project group, or null
                panelExpanded: {}, // cabinet_id -> bool, which Cabinets are expanded inside the open panel
                colorPickerOpen: false, // open Project color picker popover in the panel header
                // Hides COMPLETED Sub Task rows inside the open panel's Cabinet list -
                // presentation only (see visibleTasksFor()), never touches the true
                // counts in the Project Summary / Cabinet row. Deliberately a separate
                // state from hideCompleted above, which hides whole 100%-done PROJECTS
                // on Calendar/Timeline/List - different granularity, different scope.
                hideCompletedSubtasks: false,
                dayPanelOpen: false, // open Day Detail Panel (selected day's Projects) - see selectDate()/openDayPanel()

                init() {
                    // Remembered per-browser only (not synced anywhere) - if it can't be
                    // read (private mode, blocked storage), just falls back to unchecked.
                    try {
                        this.hideCompleted = localStorage.getItem('myDepartment.hideCompleted') === '1';
                        this.hideCompletedSubtasks = localStorage.getItem('avatar_pmo_hide_completed_subtasks') === '1';
                    } catch (e) { /* ignore */ }
                    this.load();
                },
                toggleHideCompleted() {
                    this.rebuild();
                    try {
                        localStorage.setItem('myDepartment.hideCompleted', this.hideCompleted ? '1' : '0');
                    } catch (e) { /* ignore */ }
                },
                toggleHideCompletedSubtasks() {
                    try {
                        localStorage.setItem('avatar_pmo_hide_completed_subtasks', this.hideCompletedSubtasks ? '1' : '0');
                    } catch (e) { /* ignore */ }
                },

                // ---- Calendar math ----
                addDays(dateStr, n) {
                    const d = parse(dateStr);
                    d.setDate(d.getDate() + n);
                    return ymd(d);
                },

                // First and last day of the range being viewed (excludes grid padding).
                rangeBounds() {
                    if (this.range === 'month') {
                        return [ymd(new Date(this.year, this.month, 1)), ymd(new Date(this.year, this.month + 1, 0))];
                    }
                    return [this.anchor, this.addDays(this.anchor, 6)];
                },

                rangeLabel() {
                    if (this.range === 'month') return MONTHS[this.month] + ' ' + this.year;
                    const [from, to] = this.rangeBounds();
                    return this.fmt(from) + ' – ' + this.fmt(to);
                },

                // Month/30-day: Sunday-aligned weeks (days outside the range are dimmed). 7-day: exactly 7 cells from the anchor.
                gridDays() {
                    const [first, last] = this.rangeBounds();
                    let from = parse(first), to = parse(last);
                    if (this.range !== '7') {
                        from.setDate(from.getDate() - from.getDay());
                        to.setDate(to.getDate() + (6 - to.getDay()));
                    }
                    const days = [];
                    for (let d = new Date(from); d <= to; d.setDate(d.getDate() + 1)) {
                        const date = ymd(d);
                        days.push({ date, day: d.getDate(), inRange: date >= first && date <= last });
                    }
                    return days;
                },

                weekdayHeaders() {
                    const names = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
                    return this.range === '7'
                        ? this.gridDays().map((d) => names[parse(d.date).getDay()])
                        : names;
                },

                // Calendar grid as weeks (rows of 7) so a multi-day Project bar can be drawn
                // as one continuous bar spanning its columns, instead of a separate chip
                // repeated in every day it touches.
                weekRows() {
                    const days = this.gridDays();
                    const weeks = [];
                    for (let i = 0; i < days.length; i += 7) weeks.push(days.slice(i, i + 7));
                    return weeks;
                },

                // Project bars are 2 lines tall (name + Cabinet/Task count), so fewer fit
                // per lane than the old single-line Sub Task bars did.
                maxLanes() { return this.range === '7' ? 6 : 3; },

                fmt(s) { return s ? s.split('-').reverse().join('/') : '-'; },

                // Days remaining until a due date (Project Header, Cabinet row) - date-only
                // comparison (both sides parsed via parse(), which drops time entirely), so
                // no off-by-one from timezones. `isComplete` suppresses the "เกินกำหนด" text
                // for an already-fully-done Project/Cabinet (section 16) - purely a derived
                // UI label, never touches assignment_status/is_overdue on any Sub Task.
                remainingDaysInfo(dateStr, isComplete) {
                    if (!dateStr) return null;
                    const n = Math.round((parse(dateStr) - parse(this.today)) / 86400000);
                    if (n < 0) {
                        if (isComplete) return null;
                        return { text: 'เกินกำหนด ' + Math.abs(n) + ' วัน', class: 'text-red-600 font-medium' };
                    }
                    if (n === 0) return { text: 'ครบกำหนดวันนี้', class: 'text-orange-600 font-medium' };
                    if (n <= 3) return { text: 'เหลือ ' + n + ' วัน', class: 'text-orange-600 font-medium' };
                    return { text: 'เหลือ ' + n + ' วัน', class: 'text-slate-400' };
                },

                dayHeading() {
                    const d = parse(this.selectedDate);
                    return WEEKDAYS[d.getDay()] + ' ' + this.fmt(this.selectedDate);
                },

                // ---- Navigation ----
                prevMonth() { this.shift(-1); },
                nextMonth() { this.shift(1); },
                shift(dir) {
                    if (this.range === 'month') {
                        const d = new Date(this.year, this.month + dir, 1);
                        this.year = d.getFullYear();
                        this.month = d.getMonth();
                        this.selectedDate = ymd(d);
                    } else {
                        this.anchor = this.addDays(this.anchor, dir * 7);
                        this.selectedDate = this.anchor;
                    }
                    this.load();
                },
                goToday() {
                    const t = parse(this.today);
                    this.year = t.getFullYear();
                    this.month = t.getMonth();
                    this.anchor = this.today;
                    this.selectedDate = this.today;
                    this.load();
                },
                setRange(range) {
                    if (range !== this.range) {
                        // Keep the day being looked at in view when switching.
                        const focus = this.selectedDate;
                        this.range = range;
                        const d = parse(focus);
                        this.year = d.getFullYear();
                        this.month = d.getMonth();
                        this.anchor = focus;
                        this.load();
                    }
                },
                // Month/Week/List are one 3-way toggle - the first two also switch back to the calendar.
                setCalendarRange(range) {
                    this.view = 'calendar';
                    this.setRange(range);
                },
                selectDate(date) {
                    const d = parse(date);
                    if (this.range === 'month' && (d.getFullYear() !== this.year || d.getMonth() !== this.month)) {
                        this.year = d.getFullYear();
                        this.month = d.getMonth();
                        this.selectedDate = date;
                        this.load();
                        this.openDayPanel();
                        return;
                    }
                    this.selectedDate = date;
                    this.openDayPanel();
                },
                openDayPanel() {
                    this.dayPanelOpen = true;
                },
                closeDayPanel() {
                    this.dayPanelOpen = false;
                },
                changeDepartment() {
                    this.load();
                },

                // ---- Data ----
                async load() {
                    const days = this.gridDays();
                    const seq = ++this.loadSeq;
                    this.loading = true;
                    try {
                        const q = new URLSearchParams({
                            from: days[0].date,
                            to: days[days.length - 1].date,
                            department_id: this.departmentId,
                        });
                        const data = await api(config.tasksUrl + '?' + q.toString());
                        if (seq !== this.loadSeq) return; // a newer request superseded this one
                        this.items = data.items.map((i) => ({ ...i, busy: false }));
                        this.departmentName = data.department.name;
                        this.panel = null; // department/window changed - a stale drill-down panel would show wrong data
                        this.rebuild();
                    } catch (err) {
                        if (seq === this.loadSeq) window.showToast(err.message, 'error');
                    } finally {
                        if (seq === this.loadSeq) this.loading = false;
                    }
                },

                rank(i) {
                    return [i.is_overdue ? 0 : 1, this.isHigh(i) ? 0 : 1, STATUS_RANK[i.assignment_status] ?? 9];
                },
                compare(a, b) {
                    const ra = this.rank(a), rb = this.rank(b);
                    for (let k = 0; k < ra.length; k++) if (ra[k] !== rb[k]) return ra[k] - rb[k];
                    return a.id - b.id;
                },

                // ---- Project grouping ----
                // Every Sub Task that shares a project_id becomes one Project group: one
                // Event/Bar on Calendar/Timeline, one row in List. Aggregates are derived
                // purely from the same items[] already scoped by the server - no new
                // endpoint, no new department/permission logic.
                computeProjectGroups() {
                    const map = new Map();
                    for (const i of this.items) {
                        if (!map.has(i.project_id)) {
                            map.set(i.project_id, {
                                project_id: i.project_id,
                                project_no: i.project_no,
                                project_name: i.project_name,
                                customer_name: i.customer_name,
                                priority: i.priority,
                                priority_label: i.priority_label,
                                color: i.color,
                                project_color_url: i.project_color_url,
                                cabinetIds: new Set(),
                                taskIds: new Set(),
                                waiting: 0, accepted: 0, working: 0, done: 0, overdue: 0,
                                dept_start: null, dept_due: null,
                                items: [],
                            });
                        }
                        const g = map.get(i.project_id);
                        g.cabinetIds.add(i.cabinet_id);
                        g.taskIds.add(i.cabinet_task_id);
                        g.items.push(i);
                        if (i.is_overdue) g.overdue++;
                        if (i.assignment_status === 'ASSIGNED') g.waiting++;
                        else if (i.assignment_status === 'ACCEPTED') g.accepted++;
                        else if (i.assignment_status === 'IN_PROGRESS') g.working++;
                        else if (i.assignment_status === 'COMPLETED') g.done++;
                        const from = i.start_date || i.due_date, to = i.due_date || i.start_date;
                        if (from && (!g.dept_start || from < g.dept_start)) g.dept_start = from;
                        if (to && (!g.dept_due || to > g.dept_due)) g.dept_due = to;
                    }
                    return [...map.values()].map((g) => {
                        const total = g.waiting + g.accepted + g.working + g.done;
                        return {
                            ...g,
                            cabinet_count: g.cabinetIds.size,
                            task_count: g.taskIds.size,
                            total_subtasks: total,
                            // Point 9: completed_subtasks / total_department_subtasks * 100 - a
                            // distinct metric from each Sub Task's own checklist-based progress.
                            progress: total ? Math.round((g.done / total) * 100) : 0,
                        };
                    });
                },

                // Project-level equivalent of the old visibleItems(): hides a whole Project
                // only once every one of its department Sub Tasks is COMPLETED.
                visibleProjectGroups() {
                    return this.hideCompleted
                        ? this.projectGroupsList.filter((g) => g.progress !== 100)
                        : this.projectGroupsList;
                },

                rankGroup(g) {
                    return [g.overdue > 0 ? 0 : 1, this.isHigh(g) ? 0 : 1];
                },
                compareGroups(a, b) {
                    const ra = this.rankGroup(a), rb = this.rankGroup(b);
                    for (let k = 0; k < ra.length; k++) if (ra[k] !== rb[k]) return ra[k] - rb[k];
                    return a.project_id - b.project_id;
                },

                // Precompute per-visible-day Project lists and Calendar bars so the grid
                // doesn't re-scan every group on every cell render.
                rebuild() {
                    this.projectGroupsList = this.computeProjectGroups();

                    const visible = this.visibleProjectGroups().filter((g) => g.dept_start || g.dept_due);
                    const map = {};
                    for (const d of this.gridDays()) {
                        const list = visible.filter((g) => g.dept_start <= d.date && d.date <= g.dept_due);
                        if (list.length) map[d.date] = list.sort((a, b) => this.compareGroups(a, b));
                    }
                    this.dayMap = map;

                    const barsMap = {};
                    for (const week of this.weekRows()) barsMap[week[0].date] = this.computeWeekBars(week);
                    this.weekBarsMap = barsMap;
                },

                // Bars for one week: each visible Project group gets a single div spanning
                // from its (week-clipped) Department Start to Department Due column, stacked
                // into the fewest lanes that avoid overlap (same greedy placement as before,
                // just against Project date ranges instead of single Sub Task ranges).
                computeWeekBars(week) {
                    const weekStart = week[0].date, weekEnd = week[6].date;
                    const dayIndex = (date) => week.findIndex((d) => d.date === date);

                    const candidates = this.visibleProjectGroups()
                        .filter((g) => g.dept_start || g.dept_due)
                        .filter((g) => {
                            const from = g.dept_start || g.dept_due, to = g.dept_due || g.dept_start;
                            return from <= weekEnd && to >= weekStart;
                        })
                        .map((g) => {
                            const from = g.dept_start || g.dept_due, to = g.dept_due || g.dept_start;
                            const clipStart = from < weekStart ? weekStart : from;
                            const clipEnd = to > weekEnd ? weekEnd : to;
                            const colStart = dayIndex(clipStart) + 1;
                            return {
                                group: g,
                                colStart,
                                colSpan: dayIndex(clipEnd) - colStart + 2,
                                continuesBefore: from < weekStart,
                                continuesAfter: to > weekEnd,
                            };
                        })
                        .sort((a, b) => a.colStart - b.colStart || b.colSpan - a.colSpan || this.compareGroups(a.group, b.group));

                    const maxLanes = this.maxLanes();
                    const laneEnd = []; // laneEnd[lane] = last occupied column in that lane
                    const bars = [];
                    let overflow = 0;
                    for (const c of candidates) {
                        let lane = laneEnd.findIndex((end) => end < c.colStart);
                        if (lane === -1) lane = laneEnd.length;
                        if (lane >= maxLanes) { overflow++; continue; }
                        laneEnd[lane] = c.colStart + c.colSpan - 1;
                        bars.push({ ...c, lane });
                    }
                    return { bars, overflow, laneCount: Math.min(Math.max(laneEnd.length, 1), maxLanes) };
                },

                weekBars(weekStartDate) { return this.weekBarsMap[weekStartDate] || { bars: [], overflow: 0, laneCount: 1 }; },

                dayProjectGroups(date) { return this.dayMap[date] || []; },
                overdueCount(date) { return this.dayProjectGroups(date).reduce((sum, g) => sum + g.overdue, 0); },

                // Sub Tasks with no Start/Due Date at all, rolled up per Project so the area
                // under the Calendar shows one summary line per Project instead of one row
                // per undated Sub Task.
                undatedProjectGroups() {
                    return this.visibleProjectGroups()
                        .map((g) => ({ group: g, count: g.items.filter((i) => !i.start_date && !i.due_date).length }))
                        .filter((x) => x.count > 0);
                },

                // List follows the same range as the calendar: a Project overlapping it,
                // plus any Project with overdue or entirely-undated work.
                inWindowGroup(g) {
                    if (g.overdue > 0 || (!g.dept_start && !g.dept_due)) return true;
                    const [from, to] = this.rangeBounds();
                    return (g.dept_start || g.dept_due) <= to && (g.dept_due || g.dept_start) >= from;
                },

                listProjectGroups() {
                    return this.visibleProjectGroups().filter((g) => this.inWindowGroup(g)).sort((a, b) => {
                        const da = a.dept_due || '9999-99-99', db = b.dept_due || '9999-99-99';
                        return da === db ? a.project_id - b.project_id : (da < db ? -1 : 1);
                    });
                },

                // ---- Timeline (Gantt-style) ----
                // Reuses this.items as-is (already scoped to the selected department by
                // the backend - see MyDepartmentController@tasks) and the SAME date
                // window as the Calendar (range/anchor/year/month). One row per Project.
                timelineDays() {
                    return this.gridDays().filter((d) => d.inRange);
                },
                // Fixed pixel columns, not 1fr - inside an overflow-x-auto/shrink-to-fit
                // container, 1fr has no definite width to resolve against and blows up
                // to an oversized, unpredictable column width.
                timelineColWidth() { return this.range === '7' ? 96 : 32; },
                timelineColTemplate() {
                    return 'repeat(' + (this.timelineDays().length || 1) + ', ' + this.timelineColWidth() + 'px)';
                },
                // Scrolls the Timeline's horizontal container so "today" starts in
                // view instead of the window's first day - called once when switching
                // into Timeline (doesn't touch Calendar's own nav/scroll at all).
                scrollTimelineToToday() {
                    const days = this.timelineDays();
                    const idx = days.findIndex((d) => d.date === this.today);
                    if (idx === -1 || !this.$refs.timelineScroll) return;
                    this.$refs.timelineScroll.scrollLeft = Math.max(0, idx * this.timelineColWidth() - 150);
                },
                // "Where are we" marker: a vertical line through every row at today's
                // column, in addition to the header's own highlighted day cell - useful
                // once there are many Project rows and the header alone scrolls out of view.
                todayLineLeft() {
                    const days = this.timelineDays();
                    const idx = days.findIndex((d) => d.date === this.today);
                    if (idx === -1) return null;
                    return 200 + idx * this.timelineColWidth() + this.timelineColWidth() / 2;
                },
                // One row per Project, spanning its Department Start -> Department Due,
                // clipped to the visible window (same clip/column technique as
                // computeWeekBars, just against the whole window instead of one week).
                timelineGroups() {
                    const days = this.timelineDays();
                    if (!days.length) return [];
                    const winStart = days[0].date, winEnd = days[days.length - 1].date;
                    const dayIndex = (date) => days.findIndex((d) => d.date === date);

                    let result = this.visibleProjectGroups()
                        .filter((g) => g.dept_start && !(g.dept_start > winEnd || g.dept_due < winStart))
                        .map((g) => {
                            const from = g.dept_start, to = g.dept_due;
                            const clipStart = from < winStart ? winStart : from;
                            const clipEnd = to > winEnd ? winEnd : to;
                            const colStart = dayIndex(clipStart) + 1;
                            return {
                                group: g,
                                colStart,
                                colSpan: dayIndex(clipEnd) - colStart + 1,
                                continuesBefore: from < winStart,
                                continuesAfter: to > winEnd,
                            };
                        })
                        .sort((a, b) => a.colStart - b.colStart || this.compareGroups(a.group, b.group));

                    // Search: matches the Project (no./name/customer), or any Cabinet/Sub
                    // Task name inside it - a matching Sub Task keeps its whole Project row
                    // visible (not just a fragment of it) so the Gantt context stays intact.
                    const q = this.timelineSearch.trim().toLowerCase();
                    if (q) {
                        result = result.filter((r) => {
                            const g = r.group;
                            return g.project_no.toLowerCase().includes(q) ||
                                g.project_name.toLowerCase().includes(q) ||
                                (g.customer_name || '').toLowerCase().includes(q) ||
                                g.items.some((i) =>
                                    i.cabinet_mo.toLowerCase().includes(q) ||
                                    i.cabinet_name.toLowerCase().includes(q) ||
                                    i.name.toLowerCase().includes(q)
                                );
                        });
                    }
                    return result;
                },
                // Projects that can't be drawn as a bar in the current window: no dated
                // work at all, or entirely outside it (e.g. an old overdue Project before
                // this month).
                timelineOffWindowCount() {
                    const days = this.timelineDays();
                    const groups = this.visibleProjectGroups();
                    if (!days.length) return groups.length;
                    const winStart = days[0].date, winEnd = days[days.length - 1].date;
                    return groups.filter((g) => !g.dept_start || g.dept_start > winEnd || g.dept_due < winStart).length;
                },

                // ---- Visuals ----
                isHigh(x) { return x.priority === 'high' || x.priority === 'urgent'; },

                // Identity color for a Project - the same Project always gets the same
                // color everywhere (Calendar bar, Timeline row, legend, and the accent on
                // each Sub Task card inside the Right Detail Panel), since every item now
                // also carries its own project_no. A user can override the auto-assigned
                // palette color per Project (see setProjectColor() below) - `bar`/`dot`/
                // `accent` stay empty strings in that case (nothing to add to :class) and
                // `style`/`dotStyle`/`accentStyle` carry the inline-style equivalent
                // instead, since an arbitrary hex can't be expressed as a Tailwind class.
                taskColor(x) {
                    const base = TASK_PALETTE[hashString(x.project_no || '') % TASK_PALETTE.length];
                    if (!x.color) return { ...base, style: '', dotStyle: '', accentStyle: '' };
                    return {
                        bar: '', dot: '', accent: '', hex: x.color,
                        style: 'background-color:' + hexToRgba(x.color, 0.16) + ';color:' + x.color,
                        dotStyle: 'background-color:' + x.color,
                        accentStyle: 'border-left-color:' + x.color,
                    };
                },

                // Hex swatch presets for the color picker - one per TASK_PALETTE entry,
                // so a user picking a "preset" still lands on a color the auto-assign
                // could have picked anyway (no jarring new hues entering the app).
                presetColors() {
                    return TASK_PALETTE.map((p) => p.hex);
                },

                // Distinct Projects currently on the calendar, for the color legend.
                // Alphabetical so the legend order doesn't jump around as data reloads.
                legendItems() {
                    const seen = new Map();
                    for (const g of this.visibleProjectGroups()) {
                        if ((g.dept_start || g.dept_due) && !seen.has(g.project_no)) {
                            seen.set(g.project_no, this.taskColor(g));
                        }
                    }
                    return [...seen.entries()].sort((a, b) => a[0].localeCompare(b[0])).map(([name, color]) => ({ name, color }));
                },

                // Small solid-color indicator dot (checklist progress bar), status precedence.
                dotClass(i) {
                    return {
                        completed: 'bg-emerald-500',
                        overdue: 'bg-red-500',
                        near_due: 'bg-orange-500',
                        ASSIGNED: 'bg-slate-400',
                        ACCEPTED: 'bg-blue-500',
                        IN_PROGRESS: 'bg-teal-500',
                    }[this.statusKey(i)] || 'bg-slate-400';
                },

                // Shared precedence for a single Sub Task's "dominant" color, used inside
                // the Right Detail Panel's item cards (same as the old per-item cards).
                statusKey(i) {
                    if (i.assignment_status === 'COMPLETED') return 'completed';
                    if (i.is_overdue) return 'overdue';
                    if (i.is_near_due) return 'near_due';
                    return i.assignment_status; // ASSIGNED | ACCEPTED | IN_PROGRESS
                },

                // Pure assignment-status dot (bar indicator) - unlike dotClass() this
                // never lets near_due/overdue override it, so "accepted" vs "not yet
                // accepted" stays visible at a glance even on a task that's also due
                // soon (which already has its own red ring/badge for that separately).
                assignmentDotClass(i) {
                    return {
                        ASSIGNED: 'bg-slate-400',
                        ACCEPTED: 'bg-blue-500',
                        IN_PROGRESS: 'bg-teal-500',
                    }[i.assignment_status] || 'bg-slate-400';
                },

                badgeClass(i) {
                    return {
                        ASSIGNED: 'bg-slate-100 text-slate-600',
                        ACCEPTED: 'bg-blue-100 text-blue-700',
                        IN_PROGRESS: 'bg-teal-100 text-teal-700',
                        COMPLETED: 'bg-emerald-100 text-emerald-700',
                    }[i.assignment_status] || 'bg-slate-100 text-slate-600';
                },
                // Short status word for the compact Sub Task row's badge - the server's
                // own assignment_status_label (e.g. "กำลังดำเนินงาน", "เสร็จงานแล้ว") is
                // meant for the full-size detail view and is too long to fit a narrow
                // badge column without wrapping to 2 lines; this is display-only, the
                // underlying assignment_status/workflow is untouched.
                shortStatusLabel(i) {
                    return {
                        ASSIGNED: 'รอรับ',
                        ACCEPTED: 'รับแล้ว',
                        IN_PROGRESS: 'กำลังทำ',
                        COMPLETED: 'เสร็จ',
                    }[i.assignment_status] || i.assignment_status_label;
                },
                // Visual priority for the compact Sub Task row's name (point 10): a
                // COMPLETED Sub Task is still fully readable but de-emphasized (muted,
                // no bold) so the eye lands on what's still outstanding first; Overdue
                // stands out the most, In Progress a step above the Waiting/Accepted
                // default. No row ever gets a dark full-row background - color/weight
                // on the name text is the only emphasis signal.
                subtaskNameClass(i) {
                    if (i.assignment_status === 'COMPLETED') return 'text-slate-400 font-normal';
                    if (i.is_overdue) return 'text-slate-900 font-semibold';
                    if (i.assignment_status === 'IN_PROGRESS') return 'text-slate-800 font-medium';
                    return 'text-slate-700 font-medium';
                },
                priorityClass(i) {
                    return this.isHigh(i) ? 'bg-red-50 text-red-700 font-semibold' : 'bg-slate-100 text-slate-600';
                },

                // Whole calendar days spanned, inclusive of both ends (e.g. 28/09-02/10 = 5 วัน). Null if no date at all.
                durationDays(i) {
                    if (!i.start_date && !i.due_date) return null;
                    const s = parse(i.start_date || i.due_date), e = parse(i.due_date || i.start_date);
                    return Math.round((e - s) / 86400000) + 1;
                },

                // Full breakdown for a Project bar's hover tooltip / title.
                groupTitle(g) {
                    return g.project_no + ' · ' + g.project_name +
                        ' — ' + g.cabinet_count + ' Cabinets · ' + g.task_count + ' Tasks' +
                        ' — รอ ' + g.waiting + ' · รับงานแล้ว ' + g.accepted + ' · กำลังทำ ' + g.working + ' · เสร็จ ' + g.done +
                        (g.overdue ? ' · เกินกำหนด ' + g.overdue : '');
                },

                // ---- Right Detail Panel (Project -> Cabinet -> Sub Task drill-down) ----
                // Panel is a drill-down into a Project the user already picked, not a
                // query page (no Search/Filter/Sort - see Calendar/Timeline/List for
                // that). Every Cabinet always opens collapsed, even when there's only
                // one - a big Project can have many Cabinets, so the user should see a
                // clean overview first and decide for themselves what to open; expanded
                // state is never carried over between Projects either.
                openProjectPanel(group) {
                    this.panel = group;
                    this.panelExpanded = {};
                    this.colorPickerOpen = false;
                },
                closeProjectPanel() {
                    this.panel = null;
                    this.colorPickerOpen = false;
                },

                // Tags the open Project with a custom Calendar color (or null to go back
                // to the auto-assigned palette) - optimistic update across items/panel so
                // Calendar/Timeline/Legend/List repaint immediately, then persisted via
                // projects.color; reverted with a toast if the request fails.
                async setProjectColor(hex) {
                    if (!this.panel) return;
                    const projectId = this.panel.project_id;
                    const url = this.panel.project_color_url;
                    const prev = this.panel.color;
                    this.colorPickerOpen = false;
                    this.panel.color = hex;
                    for (const i of this.items) if (i.project_id === projectId) i.color = hex;
                    this.rebuild();
                    const fresh = this.projectGroupsList.find((g) => g.project_id === projectId);
                    if (fresh) this.panel = fresh;
                    try {
                        await api(url, 'PATCH', { color: hex });
                    } catch (err) {
                        this.panel.color = prev;
                        for (const i of this.items) if (i.project_id === projectId) i.color = prev;
                        this.rebuild();
                        const revert = this.projectGroupsList.find((g) => g.project_id === projectId);
                        if (revert) this.panel = revert;
                        window.showToast(err.message, 'error');
                    }
                },
                resetProjectColor() {
                    this.setProjectColor(null);
                },
                toggleCabinetExpand(cabinetId) {
                    this.panelExpanded[cabinetId] = !this.panelExpanded[cabinetId];
                },
                expandAllCabinets() {
                    for (const c of this.panelCabinets()) this.panelExpanded[c.cabinet_id] = true;
                },
                collapseAllCabinets() {
                    this.panelExpanded = {};
                },
                anyCabinetExpanded() {
                    return Object.values(this.panelExpanded).some(Boolean);
                },
                toggleExpandAllCabinets() {
                    if (this.anyCabinetExpanded()) this.collapseAllCabinets();
                    else this.expandAllCabinets();
                },
                // The open Project's Sub Tasks, grouped by Cabinet for the panel's
                // Cabinet rows (each expandable to reveal its Sub Tasks, grouped again
                // by Task - see groupByTask()).
                panelCabinets() {
                    if (!this.panel) return [];
                    const byCabinet = new Map();
                    for (const i of this.panel.items) {
                        if (!byCabinet.has(i.cabinet_id)) {
                            byCabinet.set(i.cabinet_id, { cabinet_id: i.cabinet_id, cabinet_mo: i.cabinet_mo, cabinet_name: i.cabinet_name, items: [] });
                        }
                        byCabinet.get(i.cabinet_id).items.push(i);
                    }
                    return [...byCabinet.values()]
                        .sort((a, b) => a.cabinet_mo.localeCompare(b.cabinet_mo))
                        .map((c) => {
                            const items = c.items.sort((a, b) => this.compare(a, b));
                            const dueDates = items.map((i) => i.due_date).filter(Boolean).sort();
                            return {
                                ...c,
                                items,
                                done: items.filter((i) => i.assignment_status === 'COMPLETED').length,
                                waiting: items.filter((i) => i.assignment_status === 'ASSIGNED').length,
                                accepted: items.filter((i) => i.assignment_status === 'ACCEPTED').length,
                                working: items.filter((i) => i.assignment_status === 'IN_PROGRESS').length,
                                overdue: items.filter((i) => i.is_overdue).length,
                                dueDate: dueDates[0] || null,
                                tasks: this.groupByTask(items),
                            };
                        });
                },

                // What a Cabinet's Task groups actually render, once hideCompletedSubtasks
                // is applied - a COMPLETED Sub Task drops out of its Task's item list, and
                // a Task left with none drops out entirely (point 5), so e.g. an all-done
                // EQUIPMENT section disappears rather than showing "EQUIPMENT · 0". Purely
                // a display filter over c.tasks (itself built from the true, unfiltered
                // items in panelCabinets()) - Project/Cabinet summary counts always read
                // the unfiltered c.tasks/c.items, never this.
                visibleTasksFor(c) {
                    if (!this.hideCompletedSubtasks) return c.tasks;
                    return c.tasks
                        .map((t) => ({ ...t, items: t.items.filter((i) => i.assignment_status !== 'COMPLETED') }))
                        .filter((t) => t.items.length > 0);
                },

                // Sub Tasks of one Cabinet, grouped by their parent Task - the panel's
                // Task -> Sub Task display layer (point 5's hierarchy), purely a display
                // grouping over the same items, no new data.
                groupByTask(items) {
                    const map = new Map();
                    for (const i of items) {
                        if (!map.has(i.cabinet_task_id)) map.set(i.cabinet_task_id, { task_name: i.task_name, items: [] });
                        map.get(i.cabinet_task_id).items.push(i);
                    }
                    return [...map.values()];
                },

                // ---- Actions (existing accept/start/complete endpoints) ----
                async act(item, action) {
                    if (item.busy) return;
                    // Accepting locks the department immediately (same rule as the Cabinet page) - confirm before it's irreversible.
                    if (action === 'accept' && !confirm('ยืนยันรับงาน? หลังรับงานแล้วจะไม่สามารถเปลี่ยนแผนกได้')) return;
                    item.busy = true;
                    try {
                        const data = await api(item.urls[action], 'POST');
                        this.applyAssignment(item.id, data.assignment);
                        window.showToast(data.message);
                    } catch (err) {
                        window.showToast(err.message, 'error');
                    } finally {
                        item.busy = false;
                    }
                },

                applyAssignment(id, a) {
                    const patch = {
                        assignment_status: a.assignment_status,
                        assignment_status_label: a.assignment_status_label,
                        is_department_locked: a.is_department_locked,
                        can_accept: a.can_accept,
                        can_start: a.can_start,
                        can_complete: a.can_complete,
                    };
                    if (a.assignment_status === 'COMPLETED') {
                        patch.is_overdue = false;
                        patch.is_near_due = false;
                    }

                    const shared = this.items.find((i) => i.id === id);
                    if (shared) Object.assign(shared, patch);

                    if (this.detail && this.detail.id === id) {
                        Object.assign(this.detail, patch);
                        // Checklist ticking: only department members, and only between accept and complete.
                        this.detail.checklist_editable = ['ACCEPTED', 'IN_PROGRESS'].includes(a.assignment_status) && (a.can_start || a.can_complete);
                    }

                    this.rebuild();

                    // A status transition changes its Project's aggregate counts (waiting/
                    // accepted/working/done) - if that Project's panel is open, point it at
                    // the freshly recomputed group so the counts shown don't go stale.
                    if (this.panel && shared) {
                        const fresh = this.projectGroupsList.find((g) => g.project_id === shared.project_id);
                        if (fresh) this.panel = fresh;
                    }
                },

                // Opens the Sub Task detail/Checklist modal. Deliberately does not touch
                // `this.panel` - the Right Detail Panel stays open underneath, so closing
                // this modal drops the user right back into the same Cabinet/Task they were
                // browsing instead of needing to reopen the Project panel from scratch.
                async openDetail(item) {
                    this.detail = { ...item, busy: false, checklists: [] };
                    this.detailLoading = true;
                    this.$dispatch('open-modal', 'my-department-detail');
                    try {
                        const data = await api(item.urls.detail);
                        // Sync back into the shared item so calendar/list/detail stay one object.
                        if (this.detail && this.detail.id === item.id) this.detail = { ...data.item, busy: false, checklists: data.item.checklists.map((c) => ({ ...c, busy: false })) };
                    } catch (err) {
                        window.showToast(err.message, 'error');
                    } finally {
                        this.detailLoading = false;
                    }
                },

                async toggleChecklist(c, event) {
                    const want = event.target.checked;
                    c.busy = true;
                    try {
                        const data = await api(c.toggle_url, 'PATCH', { is_completed: want });
                        c.is_completed = data.checklist.is_completed;
                        c.completed_by_name = data.checklist.completed_by_name;
                        c.completed_at = data.checklist.completed_at_formatted;

                        const patch = {
                            progress: data.subtask.progress,
                            checklists_completed: data.subtask.completed_checklists,
                            checklists_total: data.subtask.total_checklists,
                        };
                        Object.assign(this.detail, patch);
                        const shared = this.items.find((i) => i.id === this.detail.id);
                        if (shared) Object.assign(shared, patch);
                    } catch (err) {
                        event.target.checked = !want;
                        window.showToast(err.message, 'error');
                    } finally {
                        c.busy = false;
                    }
                },
            };
        });
    });
</script>
