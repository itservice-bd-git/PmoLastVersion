// รายงานวันนี้ (reports/today): x-data="workSummary({...})" - set on window before Alpine starts (app.js).
export default function registerWorkSummary() {
    window.workSummary = (config) => ({
        NEW_CABINET: 'new_cabinet',   // DailyWork::EVENT_NEW_CABINET: the feed type, and the filter of the "ตู้/อุปกรณ์เข้าใหม่" tile
        config,
        summary: config.summary,
        viewMode: 'cards',            // cards | table
        feedFilter: null,             // null (everything) | a department id | NEW_CABINET
        newWorkOpen: false,           // the "งานเข้าใหม่" panel
        newWorkDepartment: null,      // in it: null (every department) | one department id
        connection: 'ok',             // ok | error | expired
        datePicker: null,
        highlightedDepartments: {},
        highlightedEvents: {},
        isFullscreen: false,
        seenEventKeys: new Set(),

        init() {
            try { if (localStorage.getItem('pmo_today_view') === 'table') this.viewMode = 'table'; } catch (error) { /* blocked storage: cards */ }
            this.seenEventKeys = new Set(this.summary.feed.map((event) => event.key));
            setInterval(() => this.refreshIfLive(), config.refreshSeconds * 1000);
            document.addEventListener('visibilitychange', () => { if (!document.hidden) this.refreshIfLive(); });
            document.addEventListener('fullscreenchange', () => { this.isFullscreen = document.fullscreenElement === this.$root; });
        },
        // a range ending today stays live; just today also moves on to the new day after midnight
        refreshIfLive() {
            if (!this.summary.range.isLive || document.hidden) return;
            const query = this.summary.range.isToday ? '' : this.rangeQuery(this.summary.range.from, this.summary.range.to);
            this.fetchSummary(query, true);
        },
        rangeQuery(from, to) {
            return from === to && from === this.summary.today ? '' : '?from=' + from + '&to=' + to;
        },
        async fetchSummary(query, isRefresh = false) {
            try {
                const response = await fetch(config.dataUrl + query, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store',
                });
                if (response.status === 401 || response.status === 419) { this.connection = 'expired'; return; }
                if (!response.ok) { this.connection = 'error'; return; }
                const latest = await response.json();
                const sameRange = latest.range.from === this.summary.range.from && latest.range.to === this.summary.range.to;
                if (isRefresh && sameRange) this.highlightChanges(latest);
                else this.seenEventKeys = new Set(latest.feed.map((event) => event.key));
                this.summary = latest;
                this.connection = 'ok';
            } catch (error) {
                this.connection = 'error';   // offline for a moment: keep the numbers we have, try again next time
            }
        },
        /** briefly light up what moved since the last look */
        highlightChanges(latest) {
            const movedBefore = Object.fromEntries(this.summary.departments.map((department) => [department.id, department.received + department.completed]));
            latest.departments.forEach((department) => {
                const before = movedBefore[department.id];
                if (before !== undefined && department.received + department.completed > before) this.highlightBriefly(this.highlightedDepartments, department.id, 2500);
            });
            latest.feed.forEach((event) => {
                if (this.seenEventKeys.has(event.key)) return;
                this.seenEventKeys.add(event.key);
                this.highlightBriefly(this.highlightedEvents, event.key, 6000);
            });
        },
        highlightBriefly(highlights, key, milliseconds) {
            highlights[key] = true;
            setTimeout(() => { delete highlights[key]; }, milliseconds);
        },
        showRange(from, to) {
            if (!from) return;
            to = to || from;
            if (from > to) [from, to] = [to, from];
            const query = this.rangeQuery(from, to);
            history.replaceState(null, '', config.pageUrl + query);
            this.fetchSummary(query);
        },
        isShowing(range) {
            return range.from === this.summary.range.from && range.to === this.summary.range.to;
        },
        toIsoDate(date) {
            return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
        },
        quickRanges() {
            const today = this.summary.today;
            const [year, month, day] = today.split('-').map(Number);
            const daysAgo = (count) => this.toIsoDate(new Date(year, month - 1, day - count));
            return [
                { key: 'today', label: 'วันนี้', from: today, to: today },
                { key: 'yesterday', label: 'เมื่อวาน', from: daysAgo(1), to: daysAgo(1) },
                { key: 'last7', label: '7 วัน', from: daysAgo(6), to: today },
                { key: 'last30', label: '30 วัน', from: daysAgo(29), to: today },
                { key: 'thisMonth', label: 'เดือนนี้', from: this.toIsoDate(new Date(year, month - 1, 1)), to: today },
            ];
        },
        /** one calendar for one day or a range (first click = from, second = to); Thai names, ค.ศ. values */
        openDatePicker() {
            if (!window.flatpickr) return;
            if (!this.datePicker) {
                this.datePicker = window.flatpickr(this.$refs.datePickerInput, {
                    mode: 'range', dateFormat: 'Y-m-d', disableMobile: true, positionElement: this.$refs.datePickerButton,
                    onReady: (selectedDates, dateText, picker) => picker.calendarContainer.classList.add('work-summary-calendar'),
                    locale: {
                        firstDayOfWeek: 1, rangeSeparator: ' ถึง ',
                        weekdays: { shorthand: ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'], longhand: ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'] },
                        months: {
                            shorthand: ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'],
                            longhand: ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'],
                        },
                    },
                    onClose: (selectedDates) => {
                        if (!selectedDates.length) return;
                        const range = { from: this.toIsoDate(selectedDates[0]), to: this.toIsoDate(selectedDates[selectedDates.length - 1]) };
                        if (!this.isShowing(range)) this.showRange(range.from, range.to);
                    },
                });
            }
            this.datePicker.set('maxDate', this.summary.today);
            this.datePicker.setDate([this.summary.range.from, this.summary.range.to], false);
            this.datePicker.open();
        },
        setViewMode(mode) {
            this.viewMode = mode;
            try { localStorage.setItem('pmo_today_view', mode); } catch (error) { /* not remembered */ }
        },
        /** "งานเข้าใหม่": the panel, with every department's work (null) or one department's */
        openNewWork(departmentId) {
            this.newWorkDepartment = departmentId;
            this.newWorkOpen = true;
            this.$nextTick(() => this.$refs.newWorkClose?.focus());
        },
        /** the departments with work not opened yet, most first */
        newWorkDepartments() {
            return this.summary.departments.filter((department) => department.unacknowledged)
                .sort((a, b) => b.unacknowledged - a.unacknowledged || a.name.localeCompare(b.name, 'th'));
        },
        newWorkOf(departmentId) {
            return this.summary.newWork.filter((event) => event.departmentId === departmentId);
        },
        /** the panel's list: a heading per department, its work newest first (DailyWork lists at most NEW_WORK_LIMIT each) */
        newWorkGroups() {
            return this.newWorkDepartments()
                .filter((department) => this.newWorkDepartment === null || department.id === this.newWorkDepartment)
                .map((department) => ({
                    key: 'department-' + department.id, title: (department.icon ? department.icon + ' ' : '') + department.name, total: department.unacknowledged,
                    events: this.newWorkOf(department.id),
                }))
                .filter((group) => group.events.length);
        },
        toggleFeedFilter(filter) {
            this.feedFilter = this.feedFilter === filter ? null : filter;
        },
        toggleFullscreen() {
            if (document.fullscreenElement) document.exitFullscreen();
            else if (this.$root.requestFullscreen) this.$root.requestFullscreen();
        },
        /** the word after a heading: "งานเข้าวันนี้", "งานเข้าวันนั้น", "งานเข้าช่วงนี้" */
        periodWord() {
            if (this.summary.range.isToday) return 'วันนี้';
            return this.summary.range.dayCount === 1 ? 'วันนั้น' : 'ช่วงนี้';
        },
        filteredFeed() {
            if (this.feedFilter === this.NEW_CABINET) return this.summary.feed.filter((event) => event.type === this.NEW_CABINET);
            if (this.feedFilter) return this.summary.feed.filter((event) => event.departmentId === this.feedFilter);
            return this.summary.feed;
        },
        listedWorkEventCount() {
            return this.summary.feed.filter((event) => event.type !== this.NEW_CABINET).length;
        },
        // newest first, so a project only gets a new heading when other work came in between
        feedByProject() {
            const groups = [];
            this.filteredFeed().forEach((event) => {
                const lastGroup = groups[groups.length - 1];
                if (lastGroup && lastGroup.projectNo === event.projectNo) lastGroup.events.push(event);
                else groups.push({ key: 'project-' + event.key, projectNo: event.projectNo, projectName: event.projectName, events: [event] });
            });
            return groups;
        },
        /**
         * Opening work that came in marks it seen (is_active = 1) for everyone. keepalive: the link leaves the page
         * straight away; a new tab stays here, so the badges are taken off now and the counts fetched again.
         */
        acknowledge(event) {
            if (!event.subtaskId || !event.isNew) return;
            const markSeen = (list) => list.forEach((item) => { if (item.subtaskId === event.subtaskId) item.isNew = false; });
            markSeen(this.summary.feed);
            markSeen(this.summary.newWork);
            fetch(config.acknowledgeUrl, {
                method: 'POST', keepalive: true,
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                body: JSON.stringify({ subtask_id: event.subtaskId }),
            }).then(() => this.fetchSummary(this.rangeQuery(this.summary.range.from, this.summary.range.to), true)).catch(() => { /* seen next time */ });
        },
        eventDetail(event) {
            return [event.title, event.detail].filter(Boolean).join(' · ');
        },
        emptyFeedText() {
            if (this.feedFilter === this.NEW_CABINET) return 'ยังไม่มีตู้เข้าใหม่' + this.periodWord();
            if (this.feedFilter) return 'แผนกนี้ยังไม่มีความเคลื่อนไหว' + this.periodWord();
            return 'ยังไม่มีความเคลื่อนไหว' + this.periodWord();
        },
        departmentName(id) {
            const department = this.summary.departments.find((candidate) => candidate.id === id);
            return department ? department.name : '';
        },
        feedFilterName() {
            return this.feedFilter === this.NEW_CABINET ? 'ตู้/อุปกรณ์เข้าใหม่' : this.departmentName(this.feedFilter);
        },
        barHeight(value) {
            return 'height:' + (value ? Math.max(10, Math.round(value / this.summary.chartMax * 100)) : 0) + '%';
        },
        chartDescription(department) {
            return this.summary.range.chartLabel + ': ' + department.chart.map((bar) => bar.label + ' เข้า ' + bar.received + ' เสร็จ ' + bar.completed).join(', ');
        },
        eventTagClass(type) {
            return {
                received: 'bg-blue-50 text-blue-700',
                accepted: 'bg-slate-100 text-slate-700',
                started: 'bg-amber-50 text-amber-800',
                completed: 'bg-emerald-50 text-emerald-700',
                new_cabinet: 'bg-violet-50 text-violet-700',
            }[type] || 'bg-slate-100 text-slate-700';
        },
        liveBadgeClass() {
            if (this.connection === 'expired') return 'bg-red-50 text-red-700';
            if (this.connection === 'error') return 'bg-amber-50 text-amber-800';
            return this.summary.range.isLive ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600';
        },
        liveBadgeText() {
            if (this.connection === 'expired') return 'หมดเวลาเข้าสู่ระบบ — โหลดหน้าใหม่';
            if (this.connection === 'error') return 'เชื่อมต่อไม่ได้ · จะลองใหม่เอง';
            return this.summary.range.isLive ? 'สด · อัปเดต ' + this.summary.updatedAt : 'ดูย้อนหลัง';
        },
    });
}
