// สรุปงานรายโครงการ (reports/projects): x-data="projectSummary({...})". The work-summary page's component (dates, live
// refresh, cards / table, the feed), with a card per project instead of per department, and each project's production
// line against its plan (App\Services\ProductionCurve). reports/today is not touched by this file.
const SEEN_KEY = 'pmo_projects_seen';         // project id => { day, ticked, progress, at }: when this browser last opened its card
const TEAM_KEY = 'pmo_projects_team';         // the team filter: a team's name, '-' (no team), or nothing (every team)
const SORT_KEY = 'pmo_projects_sort';         // due | activity | progressLow | progressHigh | name
const NO_TEAM = '-';
const GROUP_KEY = 'pmo_projects_group';       // month | none
const VIEW_KEY = 'pmo_projects_view2';         // weeks | cards | table, this page's own (สรุปงาน keeps its own); 2: opens on weeks

/**
 * x-count="expression": a whole number that runs to its value instead of jumping - from 0 when it first shows, from the
 * last value when the live refresh changes it - easing out over COUNT_MS. A card's numbers start with its line (--i, the
 * card's place, as the page's CSS times .curve-run). Reduced motion: the number as it is.
 */
const COUNT_MS = 1000;

function registerCountDirective(Alpine) {
    if (!Alpine || Alpine.__pmoCount) return;
    Alpine.__pmoCount = true;
    Alpine.directive('count', (el, { expression }, { evaluateLater, effect, cleanup }) => {
        const read = evaluateLater(expression);
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let shown = 0;
        let frame = null;
        let first = true;
        el.textContent = '0';
        effect(() => read((value) => {
            const target = Math.round(Number(value) || 0);
            if (frame) cancelAnimationFrame(frame);
            if (reduce || target === shown) {
                shown = target;
                el.textContent = String(target);
                first = false;
                return;
            }
            const from = shown;
            const place = Math.min(parseInt(getComputedStyle(el).getPropertyValue('--i'), 10) || 0, 24);
            const delay = first ? place * 40 + 120 : 0;   // the same beat as the card's line
            first = false;
            let start = null;
            const step = (now) => {
                start ??= now + delay;
                const t = Math.max(0, Math.min(1, (now - start) / COUNT_MS));
                shown = Math.round(from + (target - from) * (1 - Math.pow(1 - t, 3)));
                el.textContent = String(shown);
                frame = t < 1 ? requestAnimationFrame(step) : null;
            };
            frame = requestAnimationFrame(step);
        }));
        cleanup(() => frame && cancelAnimationFrame(frame));
    });
}

export default function registerProjectSummary() {
    registerCountDirective(window.Alpine);

    /**
     * The project panel on another page (the dashboard's สัปดาห์นี้): projectSummary without its page - no list, no polling.
     * open(id) asks for that one project (reports/projects/{id}/one) and opens the panel on it; a project it can't have
     * (no permission, gone) opens the project's own page instead.
     */
    window.projectPanelHost = (config) => {
        const empty = { by: 'project', today: config.today, updatedAt: '', totals: {}, departments: [], feed: [], newWork: [], departmentNames: {}, chartMax: 1,
            range: { from: config.today, to: config.today, dayCount: 1, isLive: true, isToday: false, label: '', chartLabel: '7 วันล่าสุด', previous: { from: config.today, to: config.today }, next: null } };
        const panel = window.projectSummary({ ...config, summary: empty });
        return {
            ...panel,
            init() {
                try {
                    const seen = JSON.parse(localStorage.getItem(SEEN_KEY) || '{}');
                    this.seen = Object.fromEntries(Object.entries(seen).filter(([, mark]) => mark && mark.day === config.today));
                } catch (error) { /* nothing seen yet */ }
                setInterval(() => this.refreshOpen(), config.refreshSeconds * 1000);
                document.addEventListener('visibilitychange', () => this.refreshOpen());
            },
            /** the open panel's project again (its row, its levels, its delivery), quietly: what it shows stays meanwhile */
            async refreshOpen() {
                const id = this.expandedId;
                if (id === null || document.hidden) return;
                this.loadTree(id);
                try {
                    const response = await fetch(config.oneUrl.replace('__ID__', id), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
                    if (!response.ok) return;
                    const data = await response.json();
                    if (this.expandedId === id) this.summary = data;
                } catch (error) { /* offline for a moment: keep what it shows */ }
            },
            loadingOne: false,   // the panel is open and its project is on its way ("กำลังโหลด…")
            showEvents: false,   // no "ความเคลื่อนไหว" in the dashboard's panel
            async open(id, url, round = null) {
                // the same project from another round's card: only what it shows changes
                if (this.expandedId === id) {
                    if (this.focusRound === round) { this.closePanel(); return; }
                    this.focusRound = round;
                    this.lateListOpen = false;
                    return;
                }
                this.focusRound = round;
                // open at once (its levels asked for now, beside its row): "กำลังโหลด…" until the row is here
                this.loadingOne = !this.summary.departments.some((row) => row.id === id);
                this.expandedId = null;
                this.toggleExpanded(id);
                try {
                    const response = await fetch(config.oneUrl.replace('__ID__', id), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
                    if (!response.ok) throw new Error(String(response.status));
                    const data = await response.json();
                    if (this.expandedId !== id) return;   // another project was opened meanwhile
                    this.summary = data;
                    this.monthKey = (data.range.from || config.today).slice(0, 7);
                    this.markSeen(id);
                } catch (error) {
                    if (this.expandedId === id && url) window.location.href = url;
                } finally {
                    if (this.expandedId === id) this.loadingOne = false;
                }
            },
        };
    };
    window.projectSummary = (config) => {
        const base = window.workSummary(config);
        let palette = null;   // the page's data colours (--pj-* on .work-summary, the theme's), read once

        return {
        ...base,
        seen: {},   // SEEN_KEY, today's only
        teamFilter: null,   // null = every team
        statusFilter: null, // null = all | ok | behind | late | done | moving (the line under the month)
        hover: null,        // the chart being pointed at: { id, where (card | panel), x (% across), text }
        sortBy: 'due',
        groupBy: 'month',
        monthKey: (config.summary.range.from || config.summary.today).slice(0, 7),   // the month shown: yyyy-mm
        folded: {},         // group key => true: that month's cards are folded away (this visit only)
        expandedId: null,   // the project shown in the panel on the right (null: closed)
        lateListOpen: false,   // the panel's "ช้า N จุด" list, opened
        showEvents: true,      // the panel's "ความเคลื่อนไหว" (the dashboard's panel leaves it out)
        focusRound: null,      // the dashboard: the card's delivery round (its id; 'none': what has no delivery date) - the panel shows only its cabinets
        /** the page's refresh: its numbers, and the open panel's levels and delivery (a status changed meanwhile shows) */
        refreshIfLive() {
            base.refreshIfLive.call(this);
            if (this.expandedId !== null && !document.hidden) this.loadTree(this.expandedId);
        },
        init() {
            base.init.call(this);
            try {
                const seen = JSON.parse(localStorage.getItem(SEEN_KEY) || '{}');
                this.seen = Object.fromEntries(Object.entries(seen).filter(([, mark]) => mark && mark.day === this.summary.today));
            } catch (error) { /* blocked storage: nothing seen yet */ }
            try {
                this.teamFilter = localStorage.getItem(TEAM_KEY) || null;
                const sort = localStorage.getItem(SORT_KEY);
                if (['due', 'activity', 'progressLow', 'progressHigh', 'name'].includes(sort)) this.sortBy = sort;
                if (localStorage.getItem(GROUP_KEY) === 'none') this.groupBy = 'none';
            } catch (error) { /* every team, nearest due first */ }
            try {
                const saved = localStorage.getItem(VIEW_KEY);
                this.viewMode = ['weeks', 'cards', 'table'].includes(saved) ? saved : 'weeks';
            } catch (error) { this.viewMode = 'weeks'; }
        },
        setViewMode(mode) {
            this.viewMode = mode;
            try { localStorage.setItem(VIEW_KEY, mode); } catch (error) { /* not remembered */ }
        },

        // เคลื่อนไหว, on the small card: what moved in the days shown - or, once its card was opened today (live pages only), what
        // moved since: the ticks and the % rise start again from 0 then (markSeen)
        seenMark(row) {
            const mark = this.seen[row.id];
            return mark && mark.day === this.summary.today && this.summary.range.isLive ? mark : null;
        },
        badgeTicks(row) {
            const mark = this.seenMark(row);
            return mark ? Math.max(0, row.tickedToday - mark.ticked) : row.ticked;
        },
        badgeGain(row) {
            const mark = this.seenMark(row);
            return mark ? row.progress - mark.progress : (row.curve.gain || 0);
        },
        isMoving(row) {
            if (this.seenMark(row)) return this.badgeTicks(row) > 0 || this.badgeGain(row) !== 0;
            return row.ticked + row.received + row.completed > 0;
        },
        movementText(row) {
            const mark = this.seenMark(row);
            const last = row.lastTickLabel ? ' · ติ๊กล่าสุด ' + row.lastTickLabel + ' น.' : '';
            if (mark) return 'ตั้งแต่ดูล่าสุด ' + mark.at + ' น. · ติ๊ก Checklist ' + this.badgeTicks(row) + ' ข้อ' + last;
            return 'มีความเคลื่อนไหว' + this.periodWord() + ' · ติ๊ก Checklist ' + row.ticked + ' ข้อ · งานเข้า ' + row.received + ' · เสร็จ ' + row.completed + last;
        },
        /** its card was opened: count again from here (today's ticks and the % now), remembered in this browser for today */
        markSeen(id) {
            const row = this.summary.departments.find((candidate) => candidate.id === id);
            if (!row || !this.summary.range.isLive) return;
            const now = new Date();
            this.seen[id] = { day: this.summary.today, ticked: row.tickedToday, progress: row.progress,
                at: String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0') };
            try { localStorage.setItem(SEEN_KEY, JSON.stringify(this.seen)); } catch (error) { /* counted again only on this visit */ }
        },
        /** the small ▲ beside the %: how much it rose in the days shown ($small: the card's, since it was last opened) */
        gainText(row, small = false) {
            const mark = small ? this.seenMark(row) : null;
            const gain = small ? this.badgeGain(row) : (row.curve.gain || 0);
            return (gain >= 0 ? 'เพิ่มขึ้น ' : 'ลดลง ') + Math.abs(gain) + '% ' + (mark ? 'ตั้งแต่ดูล่าสุด ' + mark.at + ' น.' : this.periodWord());
        },
        /** the live refresh: a card lights up when it moved since the last look - a tick counts, not only work in or done */
        highlightChanges(latest) {
            const moved = (row) => row.ticked + row.received + row.completed;
            const before = Object.fromEntries(this.summary.departments.map((row) => [row.id, moved(row)]));
            latest.departments.forEach((row) => {
                if (before[row.id] !== undefined && moved(row) > before[row.id]) this.highlightBriefly(this.highlightedDepartments, row.id, 2500);
            });
            latest.feed.forEach((event) => {
                if (this.seenEventKeys.has(event.key)) return;
                this.seenEventKeys.add(event.key);
                this.highlightBriefly(this.highlightedEvents, event.key, 6000);
            });
        },

        /** the strip's projects: those on the page not closed, by production - made (100%), not started (0%), the rest */
        projectCounts() {
            const running = this.monthRows().filter((row) => !row.statusLabel);
            const made = running.filter((row) => row.progress >= 100).length;
            const notStarted = running.filter((row) => row.progress <= 0).length;
            return { running: running.length, made, notStarted, making: running.length - made - notStarted };
        },

        // the month shown: its projects are those due in it (monthRows), the numbers count its days (this month: so far)
        THAI_MONTHS: ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'],
        monthLabel(key) {
            return this.THAI_MONTHS[Number(key.slice(5, 7)) - 1] + ' ' + (Number(key.slice(0, 4)) + 543);
        },
        /** the months to pick from: those with projects due, this month, the one shown - in order */
        monthOptions() {
            const keys = new Set(this.summary.departments.filter((row) => row.curve && row.curve.due).map((row) => row.curve.due.slice(0, 7)));
            keys.add(this.summary.today.slice(0, 7));
            keys.add(this.monthKey);
            const now = this.summary.today.slice(0, 7);
            return [...keys].sort().map((key) => ({ key, label: this.monthLabel(key) + (key === now ? ' (เดือนนี้)' : '') }));
        },
        /** a month: its first day to its last - this month to today; one still to come has no days yet, so today's */
        showMonth(key) {
            this.monthKey = key;
            const [y, m] = key.split('-').map(Number);
            const first = key + '-01';
            const last = key + '-' + String(new Date(y, m, 0).getDate()).padStart(2, '0');
            const today = this.summary.today;
            this.showRange(first > today ? today : first, last > today ? today : last);
        },
        shiftMonth(step) {
            const [y, m] = this.monthKey.split('-').map(Number);
            const date = new Date(y, m - 1 + step, 1);
            this.showMonth(date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0'));
        },
        /** the words after the numbers' names: "งานเข้าเดือนนี้", "งานเข้าเดือนพฤศจิกายน" (a month to come: today's) */
        periodWord() {
            const now = this.summary.today.slice(0, 7);
            if (this.monthKey === now) return 'เดือนนี้';
            if (this.monthKey > now) return 'วันนี้';
            return 'เดือน' + this.THAI_MONTHS[Number(this.monthKey.slice(5, 7)) - 1];
        },
        /**
         * สัปดาห์: the month in weeks, Monday to Sunday, cut at its first and last day; each with the projects due in it (the
         * team filter and the order apply), this week and the past ones known
         */
        weeksOfMonth() {
            const [y, m] = this.monthKey.split('-').map(Number);
            const lastDay = new Date(y, m, 0).getDate();
            const today = this.summary.today;
            const iso = (d) => this.monthKey + '-' + String(d).padStart(2, '0');
            const short = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'][m - 1];
            const rows = this.shownRows();
            const weeks = [];
            let start = 1;
            while (start <= lastDay) {
                const weekday = (new Date(y, m - 1, start).getDay() + 6) % 7;   // Monday 0 … Sunday 6
                const end = Math.min(lastDay, start + 6 - weekday);
                const from = iso(start), to = iso(end);
                weeks.push({
                    key: from, number: weeks.length + 1,
                    label: (start === end ? start : start + '–' + end) + ' ' + short,
                    isCurrent: today >= from && today <= to, isPast: to < today,
                    rows: rows.filter((row) => row.curve.due >= from && row.curve.due <= to)
                        .sort((a, b) => (this.sortBy === 'due' ? a.curve.due.localeCompare(b.curve.due) : 0)),
                });
                start = end + 1;
            }
            return weeks;
        },
        /** a due date, short: "พ. 25 พ.ย." */
        dueShort(row) {
            if (!row.curve.due) return row.dueDate;
            const [y, m, d] = row.curve.due.split('-').map(Number);
            const date = new Date(y, m - 1, d);
            return ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'][date.getDay()] + ' ' + d + ' ' + ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'][m - 1];
        },
        /**
         * A project's standing in one word, the same everywhere on the page: ผลิตครบ (100%), เลยวันส่ง (past its due date),
         * ช้ากว่าแผน (behind the plan's line, curveTone), ตามแผน - with the pill's colours
         */
        statusOf(row) {
            const tone = this.curveTone(row);
            const key = row.progress >= 100 ? 'done' : (row.daysLeft !== null && row.daysLeft < 0 ? 'late' : (['behind', 'slow'].includes(tone) ? 'behind' : 'ok'));
            const gap = row.curve && row.curve.gap !== null && row.curve.gap < 0 ? ' ' + (-row.curve.gap) + '%' : '';
            return {
                key,
                word: { done: 'ผลิตครบ', late: 'เลยวันส่ง', behind: 'ช้ากว่าแผน', ok: 'ตามแผน' }[key],
                text: { done: 'ผลิตครบ', late: 'เลยวันส่ง ' + (row.daysLeft !== null ? -row.daysLeft + ' วัน' : ''), behind: 'ช้ากว่าแผน' + gap, ok: 'ตามแผน' }[key].trim(),
                pill: {
                    done: 'bg-teal-50 text-teal-800 ring-teal-200', late: 'bg-red-50 text-red-700 ring-red-200',
                    behind: 'bg-orange-50 text-orange-800 ring-orange-200', ok: 'bg-slate-100 text-slate-700 ring-slate-200',
                }[key],
            };
        },
        statusCounts() {
            const rows = this.baseRows();
            const count = (key) => rows.filter((row) => this.statusOf(row).key === key).length;
            return [
                { key: 'ok', label: 'ตามแผน', dot: 'bg-slate-400', count: count('ok') },
                { key: 'behind', label: 'ช้ากว่าแผน', dot: 'bg-orange-500', count: count('behind') },
                { key: 'late', label: 'เลยวันส่ง', dot: 'bg-red-600', count: count('late') },
                { key: 'done', label: 'ผลิตครบ', dot: 'bg-teal-700', count: count('done') },
            ];
        },
        movingCount() {
            return this.baseRows().filter((row) => this.isMoving(row)).length;
        },
        setStatusFilter(key) {
            this.statusFilter = this.statusFilter === key ? null : key;
        },
        /** the month's projects of the team chosen (before the status filter) */
        baseRows() {
            const month = this.monthRows();
            const known = this.teamFilter !== null && month.some((row) => (row.team || NO_TEAM) === this.teamFilter);
            return known ? month.filter((row) => (row.team || NO_TEAM) === this.teamFilter) : month;
        },
        monthRows() {
            return this.summary.departments.filter((row) => row.curve && row.curve.due && row.curve.due.slice(0, 7) === this.monthKey);
        },

        // ทีมขาย and the order: the cards and the table show shownRows()
        teams() {
            const counts = {};
            this.monthRows().forEach((row) => { const key = row.team || NO_TEAM; counts[key] = (counts[key] || 0) + 1; });
            return Object.entries(counts)
                .map(([key, count]) => ({ key, count, label: key === NO_TEAM ? 'ไม่มีทีม' : key }))
                .sort((a, b) => (a.key === NO_TEAM) - (b.key === NO_TEAM) || a.label.localeCompare(b.label, 'th'));
        },
        setTeamFilter(team) {
            this.teamFilter = team;
            try { team ? localStorage.setItem(TEAM_KEY, team) : localStorage.removeItem(TEAM_KEY); } catch (error) { /* not remembered */ }
        },
        saveGroup() {
            try { localStorage.setItem(GROUP_KEY, this.groupBy); } catch (error) { /* not remembered */ }
        },
        /**
         * The cards in groups: by the month of the due date (past months too, their cards say how late), then those waiting
         * for a date, then the closed ones; each in the chosen order (shownRows). ไม่แบ่ง: one group with no heading.
         */
        groupedRows() {
            const rows = this.shownRows();
            if (this.groupBy !== 'month' || this.monthKey) return [{ key: 'all', label: '', rows, isCurrent: false, average: null }];   // a month at a time
            const months = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
            const thisMonth = this.summary.today.slice(0, 7);
            const groups = {};
            rows.forEach((row) => {
                const key = row.statusLabel ? 'z-closed' : (row.curve.due ? row.curve.due.slice(0, 7) : 'y-nodate');
                (groups[key] = groups[key] || []).push(row);
            });
            return Object.keys(groups).sort().map((key) => {
                const list = groups[key];
                const label = key === 'z-closed' ? 'ปิดโครงการแล้ว'
                    : key === 'y-nodate' ? 'รอเซลแจ้งวันส่ง / ยังไม่ลงวันส่ง'
                    : months[Number(key.slice(5, 7)) - 1] + ' ' + (Number(key.slice(0, 4)) + 543);
                const average = key.startsWith('z') ? null : Math.round(list.reduce((sum, row) => sum + row.progress, 0) / list.length);
                return { key, label, rows: list, isCurrent: key === thisMonth, average };
            });
        },
        isFolded(key) {
            return !!this.folded[key];
        },
        toggleGroup(key) {
            this.folded[key] = !this.folded[key];
        },
        saveSort() {
            try { localStorage.setItem(SORT_KEY, this.sortBy); } catch (error) { /* not remembered */ }
        },
        /**
         * The rows shown: the team's (a team no longer on the page counts as every team), in the chosen order. "ใกล้ส่งก่อน" is
         * the server's own order (running first, with work first, nearest due date first); the others re-sort, closed last.
         */
        shownRows() {
            const status = this.statusFilter;
            const rows = this.baseRows().filter((row) => status === null || (status === 'moving' ? this.isMoving(row) : this.statusOf(row).key === status));
            if (this.sortBy === 'due') return rows;
            const closedLast = (a, b) => (a.statusLabel ? 1 : 0) - (b.statusLabel ? 1 : 0);
            const by = {
                activity: (a, b) => (b.lastTickAt || 0) - (a.lastTickAt || 0),
                progressLow: (a, b) => a.progress - b.progress,
                progressHigh: (a, b) => b.progress - a.progress,
                name: (a, b) => a.name.localeCompare(b.name, 'th'),
            }[this.sortBy];
            return [...rows].sort((a, b) => closedLast(a, b) || by(a, b) || a.projectNo.localeCompare(b.projectNo));
        },

        // the panel on the right: the chosen project in full. The cards never move; another card swaps what it shows
        isExpanded(id) {
            return this.expandedId === id;
        },
        /** a card: open it in the panel, or close the panel when it is the one already shown */
        toggleExpanded(id) {
            if (this.expandedId === id) {
                this.closePanel();
                return;
            }
            const opening = this.expandedId === null;
            if (!opening) this.markSeen(this.expandedId);   // the one being left: seen up to now
            this.markSeen(id);
            this.expandedId = id;
            this.lateListOpen = false;
            this.loadTree(id);
            if (opening) this.$nextTick(() => document.getElementById('project-panel')?.focus({ preventScroll: true }));
        },
        closePanel() {
            if (this.expandedId === null) return;
            const id = this.expandedId;
            this.markSeen(id);   // what came in while it was open was seen too
            this.expandedId = null;
            // back to its card or its week's item, whichever is on show
            this.$nextTick(() => [...document.querySelectorAll('[data-chip-id="' + id + '"]')].find((el) => el.offsetParent)?.focus({ preventScroll: true }));
        },
        /** a click outside the panel closes it - unless it lands on a card, which swaps the panel's project instead */
        closeFromOutside(event) {
            if (event.target.closest('[data-project-chip]')) return;
            this.closePanel();
        },
        /** the panel's project as a one-item list (x-for keyed by it, so a swap starts its fade again) */
        selectedRows() {
            const row = this.summary.departments.find((candidate) => candidate.id === this.expandedId);
            return row ? [row] : [];
        },
        /** the panel's "ตู้และงาน" (ProjectTree), asked for when it opens a project; kept per project while the page is open */
        trees: {},
        treeFor(id) {
            return this.trees[id] || { status: 'loading', cabinets: [], info: [], deliveries: null };
        },
        async loadTree(id) {
            const known = this.trees[id];
            this.trees[id] = { status: 'loading', cabinets: known ? known.cabinets : [], info: known ? known.info : [], deliveries: known ? known.deliveries : null };   // shown again at once, then refreshed
            try {
                const response = await fetch(config.treeUrl.replace('__ID__', id), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store',
                });
                if (!response.ok) throw new Error(String(response.status));
                const data = await response.json();
                this.trees[id] = { status: 'ok', cabinets: data.cabinets, info: data.info || [], deliveries: data.deliveries || null };
            } catch (error) {
                this.trees[id] = { status: known ? 'ok' : 'error', cabinets: known ? known.cabinets : [], info: known ? known.info : [], deliveries: known ? known.deliveries : null };
            }
        },
        /** the panel's "ความเคลื่อนไหว": this project's latest lines of the feed */
        projectEvents(id) {
            return this.summary.feed.filter((event) => event.projectId === id).slice(0, 5);
        },

        // the feed, of one project when its card is picked
        filteredFeed() {
            if (this.feedFilter === this.NEW_CABINET) return this.summary.feed.filter((event) => event.type === this.NEW_CABINET);
            if (this.feedFilter) return this.summary.feed.filter((event) => event.projectId === this.feedFilter);
            return this.summary.feed;
        },
        emptyFeedText() {
            if (this.feedFilter === this.NEW_CABINET) return 'ยังไม่มีตู้เข้าใหม่' + this.periodWord();
            if (this.feedFilter) return 'โครงการนี้ยังไม่มีความเคลื่อนไหว' + this.periodWord();
            return 'ยังไม่มีความเคลื่อนไหว' + this.periodWord();
        },
        departmentName(id) {
            return (this.summary.departmentNames || {})[id] || '';
        },
        feedFilterName() {
            if (this.feedFilter === this.NEW_CABINET) return 'ตู้/อุปกรณ์เข้าใหม่';
            return this.summary.departments.find((row) => row.id === this.feedFilter)?.projectNo || '';
        },

        // the production chart: 0 - 100% up, the start to the later of today and the due date across (CHART_W x CHART_H)
        CHART_W: 300,
        CHART_H: 72,
        chartX(curve, day) {
            return Math.round(day / curve.axisDays * this.CHART_W * 10) / 10;
        },
        chartY(percent) {
            return Math.round((1 - Math.min(100, Math.max(0, percent)) / 100) * (this.CHART_H - 4) * 10) / 10 + 2;
        },
        /**
         * The bars under the line: Checklist items ticked per day (or per curve.barDays days), as one SVG path - Alpine can't
         * repeat inside an <svg>. Scaled to the project's own busiest bar, up to BAR_SHARE of the chart's height.
         */
        BAR_SHARE: 0.55,
        barsPath(curve, which = 'all') {   // which: all | past (all but the last) | today (the last only)
            const bars = curve.bars || [];
            const last = bars.length - 1;
            const most = Math.max(0, ...bars);
            if (!most) return '';
            const base = this.chartY(0);
            const room = (this.CHART_H - 4) * this.BAR_SHARE;
            return bars.map((count, i) => {
                if (!count || (which === 'past' && i === last) || (which === 'today' && i !== last)) return '';
                const x0 = this.chartX(curve, i * curve.barDays);
                const x1 = this.chartX(curve, Math.min((i + 1) * curve.barDays, curve.axisDays));
                const width = Math.max(1.2, (x1 - x0) * 0.62);
                const x = x0 + ((x1 - x0) - width) / 2;
                const top = base - Math.max(2, count / most * room);
                return 'M' + x.toFixed(1) + ' ' + base + 'V' + top.toFixed(1) + 'H' + (x + width).toFixed(1) + 'V' + base + 'Z';
            }).join('');
        },
        /** the panel's small chart in words (สรุปงาน's own says เข้า / เสร็จ) */
        chartDescription(row) {
            return 'Sub Task ' + this.summary.range.chartLabel + ': ' + row.chart.map((bar) => bar.label + ' ส่งเข้าแผนก ' + bar.received + ' ทำเสร็จ ' + bar.completed).join(', ');
        },
        /** the tree's bars: how the work came out - teal done, navy going, orange past its due date, grey not started */
        resultColor(result) {
            const c = this.colors();
            return { done: c.done, going: c.progress, late: c.slow, notstarted: c.idle }[result] || c.progress;
        },
        /** where the project is late: its Sub Tasks past their due date and not done, cabinet › Task › Sub Task */
        lateSpots(id) {
            const spots = [];
            for (const cabinet of this.cabinetGroups(id).flatMap((group) => group.cabinets)) {
                for (const task of cabinet.tasks || []) {
                    for (const subtask of task.subtasks || []) {
                        if (subtask.result !== 'late') continue;
                        spots.push({ key: subtask.id, cabinetId: cabinet.id, taskId: task.id, subtaskId: subtask.id,
                            cabinet: cabinet.name || cabinet.moNo, task: task.name, subtask: subtask.name, department: subtask.department, due: subtask.dueLabel });
                    }
                }
            }
            return spots;
        },
        /** the panel's cabinets by delivery round: each round (its day, state, colour), then the ones in no round */
        /** the delivery rounds the panel shows: the card's round only when one was pressed ('none': those with no date) */
        shownRounds(id) {
            const rounds = this.treeFor(id).deliveries?.rounds || [];
            if (this.focusRound === null) return rounds;
            if (this.focusRound === 'none') return rounds.filter((round) => !round.dated);
            return rounds.filter((round) => round.id === this.focusRound);
        },
        cabinetGroups(id) {
            const groups = this.allCabinetGroups(id);
            if (this.focusRound === null) return groups;
            if (this.focusRound === 'none') {
                const undated = new Set(this.shownRounds(id).map((round) => 'r' + round.id));
                return groups.filter((group) => group.key === 'none' || undated.has(group.key));
            }
            return groups.filter((group) => group.key === 'r' + this.focusRound);
        },
        /** the panel's focus on one round, said above its cabinets ("ดูทุกรอบ" lets it go) */
        focusText(id) {
            if (this.focusRound === null) return '';
            if (this.focusRound === 'none') return 'แสดงเฉพาะตู้ที่ยังไม่มีวันส่ง';
            const round = (this.treeFor(id).deliveries?.rounds || []).find((r) => r.id === this.focusRound);
            return round ? 'แสดงเฉพาะรอบ ' + round.round + ' · ' + round.when : '';
        },
        allCabinetGroups(id) {
            const tree = this.treeFor(id);
            const cabinets = tree.cabinets || [];
            const rounds = tree.deliveries?.rounds || [];
            if (!rounds.length) return [{ key: this.focusRound === 'none' ? 'none' : 'all', title: '', meta: '', color: null, cabinets }];
            const placed = new Set();
            const groups = rounds.map((round) => {
                const ids = new Set(round.cabinetIds || []);
                const list = cabinets.filter((cabinet) => ids.has(cabinet.id));
                list.forEach((cabinet) => placed.add(cabinet.id));
                return { key: 'r' + round.id, title: 'รอบ ' + round.round + ' · ' + round.when, meta: round.state + ' · ' + list.length + ' ตู้', color: round.color, cabinets: list };
            }).filter((group) => group.cabinets.length);
            const loose = cabinets.filter((cabinet) => !placed.has(cabinet.id));
            if (loose.length) groups.push({ key: 'none', title: 'ยังไม่อยู่ในรอบส่ง', meta: loose.length + ' ตู้', color: null, cabinets: loose });
            return groups;
        },
        cabinetIsLate(cabinet) {
            return (cabinet.tasks || []).some((task) => (task.subtasks || []).some((subtask) => subtask.result === 'late'));
        },
        /** a late spot pressed: open its cabinet and Task, bring its Sub Task into view and light it up a moment */
        goToLate(spot) {
            this.lateListOpen = false;
            const cabinet = document.getElementById('pt-cab-' + spot.cabinetId);
            const task = document.getElementById('pt-task-' + spot.taskId);
            if (cabinet) cabinet.open = true;
            if (task) task.open = true;
            this.$nextTick(() => {
                const row = document.getElementById('pt-sub-' + spot.subtaskId);
                if (!row) return;
                const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                row.scrollIntoView({ block: 'center', behavior: still ? 'auto' : 'smooth' });
                row.classList.remove('late-flash');
                void row.offsetWidth;   // restart the flash
                row.classList.add('late-flash');
                row.querySelector('summary')?.focus({ preventScroll: true });
            });
        },
        resultText(item) {
            return { done: 'เสร็จแล้ว', going: 'กำลังทำ', late: 'เลยกำหนด ' + (item.dueLabel || ''), notstarted: 'ยังไม่เริ่ม' }[item.result] || '';
        },
        // the dates of a chart: its axis runs from curve.start (day 0) to curve.axisDays
        dateOn(curve, day) {
            const [y, m, d] = curve.start.split('-').map(Number);
            const date = new Date(y, m - 1, d + day);
            const days = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
            const months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
            return days[date.getDay()] + ' ' + date.getDate() + ' ' + months[date.getMonth()];
        },
        /**
         * The one date marked inside the axis (its ends are labelled already): today when the due date is still ahead, the
         * due date when it has passed - left out when it sits too near an end to label (where: card | panel, their widths).
         */
        axisMark(curve, where = 'panel') {
            const due = curve.dueDay;
            const mark = due !== null && due > 0 && due < curve.todayDay
                ? { kind: 'due', day: due, label: 'ส่ง ' + this.dateOn(curve, due).split(' ').slice(1).join(' ') }
                : (curve.todayDay < curve.axisDays ? { kind: 'today', day: curve.todayDay, label: 'วันนี้' } : null);
            if (!mark) return null;
            mark.pct = Math.round(mark.day / curve.axisDays * 1000) / 10;
            // room for the labels at the ends ("9 พ.ย." left, "ส่ง 30 พ.ย." / "วันนี้" right): a card is narrow
            const [low, high] = where === 'card' ? [24, 62] : [16, 74];
            return mark.pct >= low && mark.pct <= high ? mark : null;
        },
        markX(curve, where = 'panel') {
            const mark = this.axisMark(curve, where);
            return mark ? this.chartX(curve, mark.day) : 0;
        },
        /** % made on a day, read off the line (straight between its points); null after today */
        percentOn(curve, day) {
            if (day > curve.todayDay) return null;
            const points = curve.actual;
            for (let i = 1; i < points.length; i++) {
                const [d0, p0] = points[i - 1];
                const [d1, p1] = points[i];
                if (day <= d1) return Math.round(p0 + (p1 - p0) * (d1 === d0 ? 1 : (day - d0) / (d1 - d0)));
            }
            return points.length ? points[points.length - 1][1] : 0;
        },
        /** the plan on a day: a straight line from 0 at the start to 100% on the due date; null without a due date */
        planOn(curve, day) {
            if (curve.dueDay === null) return null;
            return curve.dueDay <= 0 ? 100 : Math.min(100, Math.round(day / curve.dueDay * 100));
        },
        hoverAt(event, row, where) {
            const box = event.currentTarget.getBoundingClientRect();
            const across = Math.min(1, Math.max(0, (event.clientX - box.left) / box.width));
            const curve = row.curve;
            const day = Math.round(across * curve.axisDays);
            const made = this.percentOn(curve, day);
            const plan = this.planOn(curve, day);
            const bar = day <= curve.todayDay ? (curve.bars || [])[Math.floor(day / curve.barDays)] : null;
            const parts = [this.dateOn(curve, day) + (day === curve.todayDay ? ' (วันนี้)' : (day === curve.dueDay ? ' (วันส่ง)' : ''))];
            if (made !== null) parts.push('ผลิต ' + made + '%');
            if (plan !== null) parts.push('แผน ' + plan + '%');
            if (bar !== null && bar !== undefined) parts.push('ติ๊ก ' + bar + ' ข้อ' + (curve.barDays > 1 ? ' (' + curve.barDays + ' วัน)' : ''));
            if (made === null) parts.push('ยังไม่ถึง');
            this.hover = { id: row.id, where, x: Math.round(across * 1000) / 10, text: parts.join(' · ') };
        },
        hovering(row, where) {
            return this.hover !== null && this.hover.id === row.id && this.hover.where === where;
        },
        /** the bars in words, for the chart's label: how many ticked, on how many days */
        barsText(curve) {
            const bars = curve.bars || [];
            const total = bars.reduce((sum, count) => sum + count, 0);
            return total ? 'ติ๊ก Checklist รวม ' + total + ' ข้อ' + (curve.barDays > 1 ? ' (แท่งละ ' + curve.barDays + ' วัน)' : ' (แท่งละวัน)') : 'ยังไม่มีการติ๊ก';
        },
        actualPoints(curve) {
            return curve.actual.map(([day, percent]) => this.chartX(curve, day) + ',' + this.chartY(percent)).join(' ');
        },
        actualArea(curve) {
            const last = curve.actual[curve.actual.length - 1];
            return '0,' + this.chartY(0) + ' ' + this.actualPoints(curve) + ' ' + this.chartX(curve, last[0]) + ',' + this.chartY(0);
        },
        planPoints(curve) {
            if (curve.dueDay === null) return '';
            const dueDay = Math.max(curve.dueDay, 0);
            return '0,' + this.chartY(dueDay === 0 ? 100 : 0) + ' ' + this.chartX(curve, dueDay) + ',' + this.chartY(100);
        },
        /** ahead of / on / behind the plan: the line's colour and the words beside the % */
        curveTone(row) {
            if (row.progress >= 100) return 'done';
            if (row.curve.gap === null) return 'none';
            if (row.curve.gap <= -20 || row.dueTone === 'late') return 'behind';
            return row.curve.gap < -5 ? 'slow' : 'ok';
        },
        /**
         * The page's colours, one meaning each: navy = progress (the % line, the tree's bars), teal = done / up, grey = the
         * plan, the ticks and work that came in, orange / red = behind. (The live dot keeps สรุปงาน's green.)
         */
        panelLineColor(row) {
            return this.curveColor(row);
        },
        /** the page's data colours, from its CSS variables (a theme such as .theme-amber sets them) */
        colors() {
            if (palette) return palette;
            const style = getComputedStyle(this.$root);
            const read = (name, fallback) => style.getPropertyValue(name).trim() || fallback;
            palette = {
                progress: read('--pj-progress', '#0B1F3A'), done: read('--pj-done', '#0F766E'), slow: read('--pj-slow', '#ea580c'),
                behind: read('--pj-behind', '#dc2626'), idle: read('--pj-idle', '#cbd5e1'), tick: read('--pj-tick', ''),
            };
            return palette;
        },
        /** the bars under the line: the theme's tick colour, else the line's */
        tickColor(row) {
            return this.colors().tick || this.curveColor(row);
        },
        panelEventTag(type) {
            return {
                received: 'bg-slate-100 text-slate-700',
                accepted: 'bg-slate-100 text-slate-700',
                started: 'bg-amber-50 text-amber-800',
                completed: 'bg-teal-50 text-teal-800',
                new_cabinet: 'bg-slate-100 text-slate-700',
            }[type] || 'bg-slate-100 text-slate-700';
        },
        curveColor(row) {
            const c = this.colors();
            return { done: c.done, behind: c.behind, slow: c.slow, ok: c.progress, none: c.progress }[this.curveTone(row)];
        },
        curveText(row) {
            const gap = row.curve.gap;
            if (row.progress >= 100) return 'ผลิตครบแล้ว';
            if (gap === null) return 'ยังไม่มีวันส่งให้เทียบแผน';
            if (gap === 0) return 'ตรงตามแผน (' + row.curve.planToday + '%)';
            return (gap < 0 ? 'ช้ากว่าแผน ' + (-gap) : 'เร็วกว่าแผน ' + gap) + '% · แผนวันนี้ ' + row.curve.planToday + '%';
        },
        curveDescription(row) {
            return 'ผลิต ' + row.progress + '% · ' + this.curveText(row) + ' · ส่ง ' + row.dueDate + ' · ' + this.barsText(row.curve);
        },
        };
    };
}
