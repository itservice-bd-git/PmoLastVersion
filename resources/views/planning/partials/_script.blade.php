<script>
    {{--
        The Planning page's Alpine component (same pattern as my-department/partials/_script).
        Data: the board built by App\Services\PlanningBoard (project -> task, cabinet -> subtask with checklist items tagged by
        department, "deptStages" = one chip per department). Writes: ONE operation per user action, POSTed to planning.sync
        (App\Services\PlanningSync -> PMO's own services and rules). The page updates optimistically; the server's answer
        carries the true copy of the edited projects, so a refused edit simply bounces back with the reason in a toast.
    --}}
    document.addEventListener('alpine:init', () => {
        const MONTHS = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
        const WEEKDAYS = ['จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.']; // Monday first
        const PRIORITIES = {
            none: { label: 'ไม่ระบุ', color: '#98a2b3' }, low: { label: 'ต่ำ', color: '#3b82f6' }, medium: { label: 'ปานกลาง', color: '#eab308' },
            high: { label: 'สูง', color: '#f97316' }, urgent: { label: 'ด่วน', color: '#ef4444' },
        };
        const TH_DAYS = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
        const PRIORITY_RANK = { urgent: 4, high: 3, medium: 2, low: 1, none: 0 };
        const TH_MON_SHORT = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        const AVATAR_COLORS = ['#2563eb', '#0e9384', '#d97706', '#db2777', '#0891b2', '#65a30d', '#dc2626', '#7c3aed'];
        const MAX_LANES = 4; // bars per week before "+N"
        const TL_DAY = 36;   // timeline column width (px)
        const pad = (n) => String(n).padStart(2, '0');
        const ymd = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        const parse = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
        const addDays = (s, n) => { const d = parse(s); d.setDate(d.getDate() + n); return ymd(d); };
        const diffDays = (a, b) => Math.round((parse(a) - parse(b)) / 86400000);
        const hexToRgba = (hex, a) => { const n = parseInt(hex.slice(1), 16); return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')'; };
        // dates are shown dd/mm/yyyy in พ.ศ. (like the rest of this page: "ตุลาคม 2569"); the value sent / stored stays YYYY-MM-DD
        const beFormat = (date, fmt) => (fmt === 'Y-m-d' ? ymd(date) : pad(date.getDate()) + '/' + pad(date.getMonth() + 1) + '/' + (date.getFullYear() + 543));
        const beParse = (str, fmt) => {
            if (!str) return undefined;
            if (fmt === 'Y-m-d') return parse(str);
            const m = String(str).trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
            if (!m) return undefined;
            const y = Number(m[3]) > 2400 ? Number(m[3]) - 543 : Number(m[3]), d = new Date(y, Number(m[2]) - 1, Number(m[1]));
            return d.getMonth() === Number(m[2]) - 1 ? d : undefined;
        };
        const csrf = () => document.querySelector('meta[name="csrf-token"]').content;

        Alpine.data('planningBoard', (config) => {
            const board = config.root.boards[config.boardId];
            // "★" is a personal bookmark kept in THIS browser only (per user) - nothing is sent to PMO
            const starKey = 'planning.stars.' + config.userId;
            const loadStars = () => { try { const v = JSON.parse(localStorage.getItem(starKey) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } };
            const t0 = parse(config.today);

            return {
                // ---- data (the board) ----
                tasks: board.tasks,
                departments: board.departments,
                statuses: board.statuses,
                labels: board.labels || [],
                boards: config.boards || [],
                boardNo: config.boardNo ?? null,
                mine: config.mine || [], mineOnly: false,
                files: [], cabForm: null,
                today: config.today,
                canEdit: config.canEdit,
                isPmo: config.isPmo,
                myDept: config.myDept,
                urls: config.urls,
                priorities: PRIORITIES,

                // ---- view state ----
                mode: 'month',                    // 'month' | 'timeline' | 'dashboard'
                year: t0.getFullYear(),
                month: t0.getMonth(),
                focusDate: config.today,          // the left panel's day
                search: '',
                priority: 'all',                  // 'all' | 'urgent' | 'high'
                dept: config.myDept || '',        // '' = every department; a department user always starts on their own
                hideDone: false,
                moreOpen: false,
                modalId: null,                    // open project modal
                stageDept: null,                  // open department popover (inside the modal)
                stagePos: { top: 0, left: 0 },
                cl: null,                         // open checklist popover: { taskId, cabId, top, left, adding: deptId|null }
                clDraft: '',                      // text of the item being added
                comment: '', alertDept: '', posting: false,
                drag: null,
                // @-tagging in comments: the people the picker offers (the server re-checks every tag), and the open picker
                people: config.people || [],
                stars: loadStars(), starOnly: false,
                mentionOpen: false, mentionQuery: '', mentionIdx: 0,
                // project modal size: 'normal' | 'wide' | 'full' (remembered in this browser only)
                feedTab: 'activity',
                // CSV of the month on screen (the department filter, or every department for PMO roles with none chosen)
                exportUrl() {
                    const p = (n) => String(n).padStart(2, '0'), last = new Date(this.year, this.month + 1, 0).getDate();
                    const q = new URLSearchParams({ from: this.year + '-' + p(this.month + 1) + '-01', to: this.year + '-' + p(this.month + 1) + '-' + p(last), department_id: this.dept ? String(this.dept).replace('d_', '') : 'all' });
                    return this.urls.export + '?' + q;
                },
                // change log tree (the project page's Activity Log), loaded when the tab is opened
                logTree: { nodes: [], total: 0, open: {}, loading: false, error: '', id: null },
                logWords: { cabinet: 'Cabinet', task: 'Task', subtask: 'Sub Task', checklist: 'Checklist' },
                logDot(icon) { return { check: 'bg-emerald-500', plus: 'bg-blue-500', trash: 'bg-red-400' }[icon] || 'bg-slate-300'; },
                logToggle(key) { this.logTree.open[key] = !this.logTree.open[key]; },
                logSetAll(v) { const walk = (l) => l.forEach((n) => { this.logTree.open[n.key] = v; walk(n.children || []); }); walk(this.logTree.nodes); },
                logRows() {
                    const out = [], open = this.logTree.open;
                    const walk = (list, depth) => list.forEach((n) => {
                        out.push({ id: 'n-' + n.key, depth, node: n });
                        if (!open[n.key]) return;
                        walk(n.children || [], depth + 1);
                        (n.activities || []).forEach((a) => out.push({ id: 'a-' + n.key + '-' + a.id, depth: depth + 1, act: a }));
                    });
                    walk(this.logTree.nodes, 0);
                    return out;
                },
                async openLog() {
                    this.feedTab = 'log';
                    const t = this.cur, lt = this.logTree;
                    if (!t || !t.logUrl || (lt.id === t.id && !lt.error)) return;
                    Object.assign(lt, { nodes: [], total: 0, open: {}, loading: true, error: '', id: t.id });
                    try {
                        const res = await fetch(t.logUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                        if (!res.ok) throw new Error();
                        const data = await res.json();
                        if (lt.id !== t.id) return;
                        lt.nodes = data.nodes; lt.total = data.total;
                    } catch (e) { if (lt.id === t.id) lt.error = 'โหลดประวัติการเปลี่ยนแปลงไม่สำเร็จ'; }
                    finally { if (lt.id === t.id) lt.loading = false; }
                },
                modalSize: (() => { try { const v = localStorage.getItem('planning.modalSize'); return ['normal', 'wide', 'full'].includes(v) ? v : 'wide'; } catch (e) { return 'wide'; } })(),

                // ---- saving ----
                pending: {}, queue: Promise.resolve(), saving: 0, savedAt: '', etag: null,

                init() {
                    // a notification's link (?project=ID) opens that project; an unknown / not-visible one says so instead of doing nothing
                    if (config.open) {
                        this.$nextTick(() => {
                            const t = this.taskById('p' + config.open);
                            if (t) this.openModal(t.id); else window.showToast('ไม่พบโครงการนี้ หรือคุณไม่มีสิทธิ์ดู', 'error');
                            try { history.replaceState(null, '', location.pathname); } catch (e) { /* ignore */ }
                        });
                    }
                    // pick up other people's changes every minute, but never while something is being sent or edited
                    setInterval(() => {
                        if (this.saving || document.hidden || this.modalId || this.stageDept) return;
                        this.refresh();
                    }, 60000);
                },

                // =================================================== lookups
                statusOf(t) { return this.statuses.find((s) => s.id === t.statusId) || this.statuses[0]; },
                statusColor(t) { return this.statusOf(t).color; },
                // "เกินกำหนด" bars use that status's colour (Settings > สถานะงาน), not a fixed red
                lateColor() { const s = this.statuses.find((x) => x.id === 's_fail'); return s ? s.color : '#ef4444'; },
                isDone(t) { return !!this.statusOf(t).isDone; },
                deptOf(id) { return this.departments.find((d) => d.id === id) || { id, name: id, color: '#98a2b3', icon: '' }; },
                stageOf(t, deptId) { return (t.deptStages || []).find((s) => s.deptId === deptId) || null; },
                stageDone(s) { return !!s.done; },
                taskById(id) { return this.tasks.find((t) => t.id === id) || null; },
                get cur() { return this.modalId ? this.taskById(this.modalId) : null; },
                flag(t) { return t.priority && t.priority !== 'none' ? PRIORITIES[t.priority].color : ''; },
                chipStyle(hex, done) { return 'background-color:' + hexToRgba(hex, done ? 0.12 : 0.17) + ';border-left-color:' + hex; },
                fmtBE(s) { if (!s) return '-'; const [y, m, d] = s.split('-'); return d + '/' + m + '/' + (Number(y) + 543); },
                isStarred(t) { return this.stars.includes(t.id); },
                toggleStar(t) {
                    this.stars = this.isStarred(t) ? this.stars.filter((x) => x !== t.id) : [...this.stars, t.id];
                    try { localStorage.setItem(starKey, JSON.stringify(this.stars)); } catch (e) { /* not remembered */ }
                },
                // a department chip's due date as the original page words it: "17 ต.ค.", "วันนี้" / "พรุ่งนี้" (amber), overdue (red)
                stageInfo(s) {
                    if (!s.due) return { label: '—', cls: 'text-slate-400' };
                    const diff = diffDays(s.due, this.today), d = parse(s.due);
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
                daysFromToday(date) { return diffDays(date, this.today); },
                fmtShort(s) { return s ? s.slice(8) + '/' + s.slice(5, 7) : ''; },
                isWeekend(date) { const n = parse(date).getDay(); return n === 0 || n === 6; },
                // a task's date as seen in the current department view: that department's own due date, else the project's
                dueFor(t) { if (this.dept) { const s = this.stageOf(t, this.dept); if (s) return s.due; } return t.date; },
                doneFor(t) { if (this.dept) { const s = this.stageOf(t, this.dept); if (s) return this.stageDone(s) || this.isDone(t); } return this.isDone(t); },
                overdueFor(t) { return !this.doneFor(t) && this.dueFor(t) && this.dueFor(t) < this.today; },
                items(t, deptId) { // checklist items of a task, optionally only one department's
                    return t.subtasks.flatMap((c) => c.checklist.filter((i) => !deptId || i.deptId === deptId));
                },
                progress(t, deptId) { const it = this.items(t, deptId); const done = it.filter((i) => i.done).length; return { done, total: it.length, pct: it.length ? Math.round(done / it.length * 100) : 0 }; },
                cabStatus(c) { return this.statuses.find((s) => s.id === c.statusId) || this.statuses[0]; },
                cabColor(c) { return this.cabStatus(c).color; },
                cabDone(c) { return !!this.cabStatus(c).isDone; },
                cabProgress(c, deptId) { const it = c.checklist.filter((i) => !deptId || i.deptId === deptId); const done = it.filter((i) => i.done).length; return { done, total: it.length, pct: it.length ? Math.round(done / it.length * 100) : 0 }; },

                // =================================================== filtering
                visible() {
                    const q = this.search.trim().toLowerCase();
                    return this.tasks.filter((t) => {
                        if (this.hideDone && this.doneFor(t)) return false;
                        if (this.priority !== 'all' && t.priority !== this.priority) return false;
                        if (this.starOnly && !this.isStarred(t)) return false;
                        if (this.mineOnly && !this.mine.includes(Number(t.id.slice(1)))) return false;
                        if (this.dept && !this.stageOf(t, this.dept)) return false;
                        if (q && ![t.so, t.title, ...t.subtasks.map((c) => c.mo + ' ' + c.text)].join(' ').toLowerCase().includes(q)) return false;
                        return true;
                    });
                },

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
                weekBars(week) {
                    const ws = week[0].date, we = week[week.length - 1].date;
                    const entries = this.visible().map((t) => {
                        const s = this.dept ? this.stageOf(t, this.dept) : null;
                        const to = s ? s.due : t.date;
                        const from = s ? s.due : ((t.startDate && t.startDate <= t.date) ? t.startDate : t.date);
                        return { t, from, to, done: this.doneFor(t), late: this.overdueFor(t) };
                    }).filter((e) => e.from && e.to && e.from <= we && e.to >= ws)
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
                shownBars(week) { return this.weekBars(week).filter((b) => b.lane < MAX_LANES); },
                hiddenCount(week, date) { return this.weekBars(week).filter((b) => b.lane >= MAX_LANES && b.cs <= date && date <= b.ce).length; },
                barStyle(b) {
                    const color = b.late ? this.lateColor() : this.statusColor(b.t);
                    return 'grid-column:' + b.col + ' / span ' + b.span + ';grid-row:' + (b.lane + 1) + ';' + this.chipStyle(color, b.done);
                },
                barTitle(b) { return b.t.so + ' ' + b.t.title + ' — ' + this.statusOf(b.t).name + (this.dept ? ' · ' + this.deptOf(this.dept).name : ''); },

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
                    return this.visible().map((t) => {
                        const s = this.dept ? this.stageOf(t, this.dept) : null;
                        const to = s ? s.due : t.date, from = s ? s.due : ((t.startDate && t.startDate <= t.date) ? t.startDate : t.date);
                        return { t, from, to };
                    }).filter((r) => r.from <= we && r.to >= ws).sort((a, b) => a.from.localeCompare(b.from)).map((r) => {
                        const cs = r.from < ws ? ws : r.from, ce = r.to > we ? we : r.to;
                        const a = days.findIndex((d) => d.date === cs), b = days.findIndex((d) => d.date === ce);
                        return { ...r, col: a + 1, span: b - a + 1, before: r.from < ws, after: r.to > we, done: this.doneFor(r.t), late: this.overdueFor(r.t) };
                    });
                },
                tlTodayLeft() { const i = this.tlDays().findIndex((d) => d.date === this.today); return i < 0 ? null : 220 + i * TL_DAY + TL_DAY / 2; },

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
                    const run = this.dayTasks(this.focusDate).filter((t) => !listed.has(t.id)).map((t) => ({ t, cabs: (t.subtasks.length ? t.subtasks : [null]).map((c) => ({ c, ed: this.cabEff(t, c), done: this.cabIsDone(t, c) })) }));
                    out.push({ key: 'run', label: 'งานในวันที่ ' + this.fmtBE(this.focusDate), danger: false, count: run.length, groups: run });
                    return out;
                },
                focusCounts() { const b = this.focusBuckets(); return { total: b.total, done: b.done, pct: b.total ? Math.round(b.done / b.total * 100) : 0 }; },
                focusEmpty() { return this.focusSections().every((sec) => sec.groups.length === 0); },
                // one cabinet row's date: "เลย N วัน" (red) when overdue, else dd/mm/yyyy (พ.ศ.)
                rowDate(ed) { const n = diffDays(ed, this.today); return n < 0 ? 'เลย ' + (-n) + ' วัน' : this.fmtBE(ed); },
                rowName(c, t) { return c ? ((c.mo || '').trim() ? c.mo + ' ' + c.text : c.text) : '(ทั้งโปรเจค — ยังไม่มีตู้)'; },
                rowColor(t, c) { return c ? this.cabColor(c) : this.statusColor(t); },

                // =================================================== day list
                dayTasks(date) {
                    return this.visible().filter((t) => {
                        const s = this.dept ? this.stageOf(t, this.dept) : null;
                        const to = s ? s.due : t.date, from = s ? s.due : ((t.startDate && t.startDate <= t.date) ? t.startDate : t.date);
                        return from <= date && date <= to;
                    }).sort((a, b) => a.so.localeCompare(b.so));
                },
                // clicking a day in the calendar shows that day in the left panel ("งานที่ต้องเสร็จ"): what is due, and what is running
                selectDay(date) {
                    this.focusDate = date;
                    if (window.innerWidth < 1024) window.scrollTo({ top: 0, behavior: 'smooth' });   // on a phone the panel is above the calendar
                },

                // =================================================== dashboard
                dash() {
                    const list = this.visible(), today = this.today;
                    const doneOf = (t) => this.doneFor(t), dueOf = (t) => this.dueFor(t);
                    const items = list.flatMap((t) => this.items(t, this.dept || null));
                    const doneItems = items.filter((i) => i.done).length;
                    const bucket = [{ label: 'เลยกำหนด', n: 0, late: true }, { label: 'ภายใน 7 วัน', n: 0 }, { label: '8–14 วัน', n: 0 }, { label: '15–21 วัน', n: 0 }, { label: '22+ วัน', n: 0 }];
                    list.filter((t) => !doneOf(t) && dueOf(t)).forEach((t) => {
                        const d = diffDays(dueOf(t), today);
                        bucket[d < 0 ? 0 : d <= 7 ? 1 : d <= 14 ? 2 : d <= 21 ? 3 : 4].n++;
                    });
                    const depts = this.departments.map((dp) => {
                        const stages = list.map((t) => this.stageOf(t, dp.id)).filter(Boolean);
                        const its = list.flatMap((t) => this.items(t, dp.id));
                        const done = its.filter((i) => i.done).length;
                        return { dp, stages: stages.length, stagesDone: stages.filter((s) => s.done).length, late: stages.filter((s) => !s.done && s.due && s.due < today).length, done, total: its.length, pct: its.length ? Math.round(done / its.length * 100) : 0 };
                    }).filter((r) => r.stages > 0);
                    return {
                        total: list.length,
                        completed: list.filter(doneOf).length,
                        doing: list.filter((t) => !doneOf(t) && this.statusOf(t).id === 's_doing').length,
                        late: list.filter((t) => this.overdueFor(t)).length,
                        checkPct: items.length ? Math.round(doneItems / items.length * 100) : 0, checkDone: doneItems, checkTotal: items.length,
                        bucket, bucketMax: Math.max(1, ...bucket.map((b) => b.n)), depts,
                        rows: list.slice().sort((a, b) => (this.overdueFor(b) - this.overdueFor(a)) || String(dueOf(a)).localeCompare(String(dueOf(b)))),
                    };
                },

                // =================================================== saving: one operation per action
                async post(ops, files) {
                    if (files && files.length) {   // a comment with photos / files: multipart, to its own endpoint
                        const o = ops[0], fd = new FormData();
                        fd.append('taskId', o.taskId); fd.append('text', o.text || '');
                        if (o.alertDept) fd.append('alertDept', o.alertDept);
                        (o.mentions || []).forEach((id) => fd.append('mentions[]', id));
                        files.forEach((f) => fd.append('files[]', f));
                        const r = await fetch(this.urls.comment, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: fd });
                        if (r.status === 419 || r.status === 401) throw new Error('หมดเวลาเข้าสู่ระบบ — รีเฟรชหน้านี้แล้วเข้าสู่ระบบใหม่');
                        const d = await r.json().catch(() => ({}));
                        if (!r.ok) throw new Error((d.errors && Object.values(d.errors).flat()[0]) || d.message || ('เกิดข้อผิดพลาด ' + r.status));
                        return d;
                    }
                    const res = await fetch(this.urls.sync, { method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ ops }) });
                    if (res.status === 419 || res.status === 401) throw new Error('หมดเวลาเข้าสู่ระบบ — รีเฟรชหน้านี้แล้วเข้าสู่ระบบใหม่');
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(data.message || ('เกิดข้อผิดพลาด ' + res.status));
                    return data;
                },
                // Sends ops one batch at a time (in order). Optimistic edits are made by the caller BEFORE this runs.
                send(ops, files) {
                    const ids = [...new Set(ops.map((o) => o.taskId).filter(Boolean))];
                    const release = () => ids.forEach((id) => { this.pending[id] = Math.max(0, (this.pending[id] || 1) - 1); });
                    ids.forEach((id) => { this.pending[id] = (this.pending[id] || 0) + 1; });
                    this.saving++;
                    this.queue = this.queue
                        .then(() => this.post(ops, files))
                        .then((data) => {
                            release();
                            const msgs = (data.results || []).filter((r) => r.message).map((r) => (r.ok ? '' : '⚠️ ') + r.message);
                            if (msgs.length) window.showToast([...new Set(msgs)].slice(0, 3).join(' · '), (data.results || []).some((r) => !r.ok) ? 'error' : 'success');
                            this.apply(data);
                            this.savedAt = new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
                        })
                        .catch((err) => { release(); window.showToast('บันทึกไม่สำเร็จ: ' + err.message, 'error'); return this.refresh(true); })
                        .finally(() => { this.saving--; });
                    return this.queue;
                },
                // merge the server's answer: replace only the projects it sent, and never one that has a newer edit still queued
                apply(data) {
                    const before = this.cur ? this.cur.comments.length : null;
                    const busy = (id) => (this.pending[id] || 0) > 0;
                    if (data.partial) {
                        (data.tasks || []).forEach((nt) => {
                            if (busy(nt.id)) return;
                            if ((nt.boardNo ?? null) !== this.boardNo) {   // moved to another board: it leaves this one
                                const k = this.tasks.findIndex((t) => t.id === nt.id);
                                if (k >= 0) this.tasks.splice(k, 1);
                                if (this.modalId === nt.id) this.closeModal();
                                return;
                            }
                            const i = this.tasks.findIndex((t) => t.id === nt.id);
                            if (i >= 0) this.tasks.splice(i, 1, nt); else this.tasks.push(nt);
                        });
                        (data.removed || []).forEach((id) => {
                            if (busy(id)) return;
                            const i = this.tasks.findIndex((t) => t.id === id);
                            if (i >= 0) this.tasks.splice(i, 1);
                            if (this.modalId === id) this.closeModal();
                        });
                    } else if (data.root) {
                        this.replaceAll(data.root.boards[config.boardId]);
                    }
                    if (before !== null && this.cur && this.cur.comments.length > before) this.scrollFeed();
                },
                replaceAll(b) {
                    const keep = new Map(this.tasks.filter((t) => (this.pending[t.id] || 0) > 0).map((t) => [t.id, t]));
                    this.tasks = b.tasks.map((t) => keep.get(t.id) || t);
                    this.departments = b.departments;
                    this.statuses = b.statuses;
                    this.labels = b.labels || [];   // names / colours may have been changed in Settings since this page loaded
                    if (this.modalId && !this.taskById(this.modalId)) this.closeModal();
                },
                async refresh(force) {
                    try {
                        const res = await fetch(this.urls.data, { headers: Object.assign({ 'Accept': 'application/json' }, (!force && this.etag) ? { 'If-None-Match': this.etag } : {}) });
                        if (res.status === 304 || !res.ok) return;
                        this.etag = res.headers.get('ETag');
                        this.replaceAll((await res.json()).root.boards[config.boardId]);
                    } catch (e) { /* offline: keep what we have */ }
                },

                // =================================================== editing (each one: update the page now, then one operation)
                editProject(t, fields) {
                    const prev = {};
                    Object.keys(fields).forEach((k) => { prev[k] = t[k]; t[k] = fields[k]; });
                    this.send([{ type: 'project', taskId: t.id, fields }]);
                },
                setDue(t, deptId, iso) {
                    const s = this.stageOf(t, deptId);
                    if (!s) return;
                    s.due = iso;
                    this.send([{ type: 'stage_due', taskId: t.id, deptId, due: iso }]);
                },
                setCabinetDue(t, c, iso) {
                    c.date = iso;
                    this.send([{ type: 'cabinet_due', taskId: t.id, cabinetId: c.id, due: iso }]);
                },
                tick(t, c, item) {
                    item.done = !item.done;
                    this.send([{ type: 'checklist', taskId: t.id, cabinetId: c.id, itemId: item.id, done: item.done }]);
                },
                // extra fields per department (Settings > ฟิลด์เพิ่มเติม): the value is kept on the department's stage of the project
                canEditStage(deptId) { return this.isPmo || deptId === this.myDept; },
                fieldValue(t, deptId, f) { const s = this.stageOf(t, deptId); return (s && s.fields && s.fields[f.id]) || ''; },
                fieldOptions(f) { return (f.groups || []).flatMap((g) => (g.options || []).map((o) => ({ id: o.id, text: (g.name ? g.name + ' · ' : '') + o.label }))); },
                setField(t, deptId, f, value) {
                    const s = this.stageOf(t, deptId);
                    if (!s) return;
                    if (!s.fields) s.fields = {};
                    if (value) s.fields[f.id] = value; else delete s.fields[f.id];
                    this.send([{ type: 'stage_field', taskId: t.id, deptId, fieldId: f.id, value }]);
                },
                step(t, deptId, step) {
                    if (step === 'accept' && !confirm('ยืนยันรับงาน? หลังรับงานแล้วจะเปลี่ยนแผนกที่รับผิดชอบไม่ได้')) return;
                    this.stageDept = null;
                    this.send([{ type: 'stage_step', taskId: t.id, deptId, step }]);
                },
                // ---- @ picker ----
                mentionList() { const q = this.mentionQuery.toLowerCase(); return this.people.filter((p) => !q || p.name.toLowerCase().includes(q)).slice(0, 6); },
                // opens the list while the text just before the caret is "@something" (start of the text or after a space)
                onCommentInput(ev) {
                    const el = ev.target, upto = el.value.slice(0, el.selectionStart), m = upto.match(/(?:^|\s)@([^\s@]{0,30})$/);
                    if (m) { this.mentionQuery = m[1]; this.mentionOpen = true; this.mentionIdx = 0; } else { this.mentionOpen = false; }
                },
                pickMention(p) {
                    const el = this.$refs.commentBox, pos = el.selectionStart, m = el.value.slice(0, pos).match(/(^|\s)@([^\s@]{0,30})$/);
                    if (!m) return;
                    const start = pos - m[2].length - 1;   // the "@"
                    this.comment = el.value.slice(0, start) + '@' + p.name + ' ' + el.value.slice(pos);
                    this.mentionOpen = false;
                    this.$nextTick(() => { const c = start + p.name.length + 2; el.focus(); el.setSelectionRange(c, c); });
                },
                onCommentKey(ev) {
                    const list = this.mentionOpen ? this.mentionList() : [];
                    if (list.length) {
                        if (ev.key === 'ArrowDown') { ev.preventDefault(); this.mentionIdx = (this.mentionIdx + 1) % list.length; return; }
                        if (ev.key === 'ArrowUp') { ev.preventDefault(); this.mentionIdx = (this.mentionIdx - 1 + list.length) % list.length; return; }
                        if ((ev.key === 'Enter' || ev.key === 'Tab') && !ev.isComposing) { ev.preventDefault(); this.pickMention(list[this.mentionIdx] || list[0]); return; }
                        if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); this.mentionOpen = false; return; }
                    }
                    if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) { ev.preventDefault(); this.postComment(); }
                },
                // ids of the people whose "@Name" is still in the text when it is sent
                mentionedIds(text) { return this.people.filter((p) => text.includes('@' + p.name)).map((p) => p.id); },
                // a comment split into plain text and "@Name" pieces so tags can be highlighted without injecting HTML
                commentParts(text) {
                    const names = this.people.map((p) => p.name).sort((a, b) => b.length - a.length).map((n) => n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
                    if (!names.length) return [{ t: text, m: false }];
                    return text.split(new RegExp('(@(?:' + names.join('|') + '))', 'g')).filter((x) => x !== '').map((x) => ({ t: x, m: x.startsWith('@') && this.people.some((p) => '@' + p.name === x) }));
                },
                async postComment() {
                    const t = this.cur, text = this.comment.trim();
                    if (!t || (!text && !this.files.length) || this.posting) return;
                    this.posting = true;
                    const alert = this.alertDept || null, files = this.files;
                    t.comments.push({ id: 'tmp' + Date.now(), author: 'คุณ', text: text || '(แนบไฟล์)', at: Date.now(), files: files.map((f) => ({ name: f.name, url: '', size: '', image: false })) });
                    this.comment = ''; this.alertDept = ''; this.files = [];
                    this.mentionOpen = false; this.scrollFeed();
                    try { await this.send([{ type: 'comment', taskId: t.id, text, alertDept: alert, mentions: this.mentionedIds(text) }], files); } finally { this.posting = false; }
                },
                pickFiles(ev) {
                    const room = 5 - this.files.length;
                    this.files = this.files.concat([...ev.target.files].slice(0, Math.max(0, room)));
                    ev.target.value = '';
                },
                // ---- status picked by hand, tags, departments, cabinets (the buttons only show to who may use them; PMO enforces the same) ----
                setStatus(t, statusId) {
                    t.manualStatus = statusId !== '';
                    if (statusId) t.statusId = statusId;
                    this.send([{ type: 'project', taskId: t.id, fields: { statusId } }]);   // '' = back to automatic; the answer carries the real one
                },
                labelOf(id) { return this.labels.find((l) => l.id === id) || { id, name: id, color: '#98a2b3' }; },
                setLabels(t, ids) { t.labels = ids; this.send([{ type: 'labels_set', taskId: t.id, labels: ids }]); },
                toggleLabel(t, id) { this.setLabels(t, t.labels.includes(id) ? t.labels.filter((x) => x !== id) : t.labels.concat(id)); },
                createLabel(t) {
                    const name = (prompt('ชื่อแท็กใหม่') || '').trim();
                    if (name) this.send([{ type: 'label_create', name }]);   // no project on it: PMO answers with the whole board, the new tag is in the list
                },
                freeDepts(t) { return this.departments.filter((d) => !(t.deptStages || []).some((s) => s.deptId === d.id)); },
                addStage(t, deptId) { if (deptId) this.send([{ type: 'stage_add', taskId: t.id, deptId }]); },
                removeStage(t, deptId) {
                    if (!confirm('เอาแผนก ' + this.deptOf(deptId).name + ' ออกจากโครงการนี้? (ทำได้เมื่อแผนกยังไม่รับงาน)')) return;
                    this.stageDept = null;
                    this.send([{ type: 'stage_remove', taskId: t.id, deptId }]);
                },
                addCabinet(t) {
                    const f = this.cabForm;
                    if (!f || !f.mo.trim() || !f.text.trim()) return;
                    this.send([{ type: 'cabinet_add', taskId: t.id, mo: f.mo.trim(), text: f.text.trim() }]);
                    this.cabForm = null;
                },
                renameCabinet(t, c) {
                    const mo = prompt('เลข MO', c.mo); if (mo === null) return;
                    const text = prompt('ชื่อตู้', c.text); if (text === null) return;
                    if (!mo.trim() || !text.trim()) return;
                    c.mo = mo.trim(); c.text = text.trim();
                    this.send([{ type: 'cabinet_rename', taskId: t.id, cabinetId: c.id, mo: c.mo, text: c.text }]);
                },
                deleteCabinet(t, c) {
                    if (!confirm('ลบตู้ ' + c.mo + ' ' + c.text + '? (กู้คืนได้ที่ถังขยะ)')) return;
                    t.subtasks = t.subtasks.filter((x) => x.id !== c.id);
                    this.send([{ type: 'cabinet_delete', taskId: t.id, cabinetId: c.id }]);
                },
                setBoard(t, boardId) { this.send([{ type: 'board_set', taskId: t.id, boardId }]); },
                deleteProject() {
                    const t = this.cur;
                    if (!t || !confirm('ย้ายโครงการ ' + t.so + ' ไปถังขยะ? (กู้คืนได้จากหน้าถังขยะ)')) return;
                    this.closeModal();
                    this.tasks.splice(this.tasks.indexOf(t), 1);   // gone from the page at once; if PMO refuses, its answer puts the project back
                    this.send([{ type: 'delete', taskId: t.id }]);
                },

                // =================================================== modal / popovers
                openModal(id) { this.modalId = id; this.feedTab = 'activity'; this.logTree.id = null; this.stageDept = null; this.cl = null; this.comment = ''; this.alertDept = ''; this.scrollFeed(); },
                setModalSize(size) { this.modalSize = size; try { localStorage.setItem('planning.modalSize', size); } catch (e) { /* storage blocked: it just won't be remembered */ } },
                closeModal() { this.modalId = null; this.stageDept = null; this.cl = null; },
                onEscape() { if (this.cl) this.closeChecklist(); else if (this.stageDept) this.stageDept = null; else if (this.modalId) this.closeModal();  },
                openStage(deptId, ev) {
                    if (this.stageDept === deptId) { this.stageDept = null; return; }
                    const r = ev.currentTarget.getBoundingClientRect();
                    this.stagePos = { top: Math.min(r.bottom + 6, window.innerHeight - 330), left: Math.max(8, Math.min(r.left, window.innerWidth - 300)) };
                    this.stageDept = deptId;
                },
                // ---- checklist popover (as in the original page): header + progress, items you can tick, grouped by department, + add / delete ----
                get clTask() { return this.cl ? this.taskById(this.cl.taskId) : null; },
                get clCab() { const t = this.clTask; return t ? (t.subtasks.find((c) => c.id === this.cl.cabId) || null) : null; },
                openChecklist(taskId, cabId, ev) {
                    if (this.cl && this.cl.cabId === cabId && this.cl.taskId === taskId) { this.closeChecklist(); return; }
                    const r = ev.currentTarget.getBoundingClientRect();
                    this.stageDept = null; this.clDraft = '';
                    this.cl = { taskId, cabId, top: Math.max(8, Math.min(r.bottom + 6, window.innerHeight - 340)), left: Math.max(8, Math.min(r.left - 120, window.innerWidth - 276)), adding: null };
                },
                closeChecklist() { this.cl = null; this.clDraft = ''; },
                clScope() { return this.dept || null; },
                clProgress() { return this.clCab ? this.cabProgress(this.clCab, this.clScope()) : { done: 0, total: 0, pct: 0 }; },
                clTitle() { return this.dept ? 'Checklist · ' + this.deptOf(this.dept).name : 'Checklist (ทุกแผนก)'; },
                // the departments shown: the one in view, or every department that has work in this project (empty ones too, so "+" is there)
                clGroups() {
                    const t = this.clTask, c = this.clCab;
                    if (!t || !c) return [];
                    const ids = this.dept ? [this.dept] : t.deptStages.map((s) => s.deptId);
                    return ids.map((id) => ({ deptId: id, items: c.checklist.filter((i) => i.deptId === id) }));
                },
                clAdd(deptId) { this.cl = { ...this.cl, adding: deptId }; this.clDraft = ''; this.$nextTick(() => { const el = document.getElementById('cl-draft'); if (el) el.focus(); }); },
                clCommit() {
                    if (!this.cl) return;
                    const text = this.clDraft.trim(), deptId = this.cl.adding;
                    this.clDraft = '';
                    this.cl = { ...this.cl, adding: null };
                    if (!deptId || !text) return;
                    const t = this.clTask, c = this.clCab;
                    c.checklist.push({ id: 'tmp' + Date.now(), text, done: false, deptId });   // shown at once; PMO's answer carries the real item
                    this.send([{ type: 'checklist_add', taskId: t.id, cabinetId: c.id, deptId, text }]);
                },
                clDelete(item) {
                    const t = this.clTask, c = this.clCab;
                    if (!t || !c) return;
                    c.checklist.splice(c.checklist.indexOf(item), 1);
                    this.send([{ type: 'checklist_delete', taskId: t.id, cabinetId: c.id, itemId: item.id }]);
                },
                timeline() { this.mode = 'timeline'; this.$nextTick(() => { const el = this.$refs.tlScroll; const i = this.tlDays().findIndex((d) => d.date === this.today); if (el && i >= 0) el.scrollLeft = Math.max(0, i * TL_DAY - 160); }); },

                // dd/mm/yyyy date field (flatpickr, same as the rest of the app). `get` reads the current value, `set` receives 'YYYY-MM-DD' or null.
                dateField(el, get, set, enabled, width) {
                    if (!window.flatpickr) { el.type = 'date'; el.value = get() || ''; el.disabled = !enabled; el.addEventListener('change', () => set(el.value || null)); return; }
                    window.flatpickr(el, {
                        dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y', allowInput: true, clickOpens: enabled, formatDate: beFormat, parseDate: beParse,
                        altInputClass: (width || 'w-[6.75rem]') + ' rounded-lg border-slate-300 px-2 py-1 pr-7 text-[13px] ' + (enabled ? '' : 'bg-slate-50 text-slate-500'),
                        defaultDate: get() || null,
                        onChange: (d, str) => { if (enabled && str !== (get() || '')) set(str || null); },
                    });
                    if (!enabled && el._flatpickr && el._flatpickr.altInput) el._flatpickr.altInput.disabled = true;
                },
            };
        });
    });
</script>
