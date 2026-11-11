// Planning board · The board's data, what is on screen, and the save queue's state.
// One part of the planningBoard Alpine component (see ./index.js); `this` is the whole component.
import { MONTHS, WEEKDAYS, PRIORITIES, TH_DAYS, PRIORITY_RANK, TH_MON_SHORT, AVATAR_COLORS, MAX_LANES, SHIP_COLOR, TL_DAY, pad, ymd, parse, addDays, diffDays, hexToRgba, beFormat, beParse, csrf } from './shared';

export default ({ config, board, starKey, loadStars, t0 }) => ({
    // ---- data (the board) ----
    tasks: board.tasks,
    departments: board.departments,
    statuses: board.statuses,
    pmoStatusList: board.pmoStatusList || [],
    labels: board.labels || [],
    ckStatuses: board.checklistStatuses || [],   // Settings > สีสถานะ Checklist
    ckPick: null, ckFilter: [],     // the item whose status panel is open, the colours "สีนี้คืออะไร" filters to
    boards: config.boards || [],
    boardNo: config.boardNo ?? null,
    mine: config.mine || [], mineOnly: false,
    files: [],
    templates: config.templates || [],
    jobTypes: config.jobTypes || [], pmoStatuses: config.pmoStatuses || {}, staff: config.staff || [],
    cabOpen: {},                      // expanded cabinet rows: { c12: { loading, cabinet, subtasks } }
    cabDialog: null,                  // the "new cabinet" window: MO, name, template, and which department gets each of its sub tasks
    dispatch: null,                   // the "hand out work" window: { taskId, cabId, preset, assign: { s12: 'd_5' } }
    pick: null,                       // "เลือกรับ…": { t, deptId, on: { s12: true } } - which waiting work of a department to accept / send back
    me: config.me || { id: config.userId, name: config.userName, avatar: null, deptId: '' },
    canTrash: !!config.canTrash,
    perms: config.perms || {},        // Settings > สิทธิ์การใช้งาน (the server leaves out / refuses the rest anyway)
    trash: config.trash || [], managers: config.managers || [],
    drawer: null,                     // 'trash' | 'stars' side drawer
    newProject: null,                 // the "new project" window's form
    viewer: null,                     // { url, name, kind: 'image' | 'pdf' } photo / PDF shown over the page
    openWeeks: {},                    // the month's weeks opened by "+n โครงการ" (first day => true): every bar shows
    resPop: null,                     // { res, top, left, pinned } "เลื่อน n" history box from a chip (hover; pinned = clicked, has a link)
    statusPop: null,                  // { taskId, cabId?, top, left } quick status picker opened from a bar / cabinet
    replyTo: null,                    // the comment being answered
    multiDepts: [],
    byCabinet: (() => { try { return localStorage.getItem('planning.byCabinet') === '1'; } catch (e) { return false; } })(),   // "แยกรายตู้"                   // extra filter: projects any of these departments work on
    today: config.today,
    canEdit: config.canEdit,
    isPmo: config.isPmo,
    myDept: config.myDept,
    myDepts: config.myDepts || (config.myDept ? [config.myDept] : []),   // my department and the ones I look after too
    readOnly: !!config.readOnly,   // "ดูได้อย่างเดียว": the page only shows
    shipUrl: config.shipUrl || null,   // the delivery department: งานจัดส่ง for one project, in the รอบส่ง window (PlanningController)
    ship: null,                        // that window open: { t, url, did, round, loaded, failed, panel, saved }
    urls: config.urls,
    priorities: PRIORITIES,

    // ---- view state ----
    mode: 'month',                    // 'month' | 'timeline' | 'dashboard' | 'fat' | 'del' (the FAT / จัดส่ง tabs)
    evs: config.eventStatuses || {},  // Settings > สถานะงาน: FAT / delivery state => { name, color } (App\Support\EventStatuses)
    evNeed: false,                    // จัดส่ง: only the rounds with no vehicle yet
    evOpen: {},                       // FAT / จัดส่ง: weeks opened by "+ อีก n" (first day => true)
    year: t0.getFullYear(),
    month: t0.getMonth(),
    focusDate: config.today,          // the left panel's day
    search: '',
    priority: 'all',                  // 'all' | 'urgent' | 'high'
    dept: config.myDept || '',        // '' = every department; a department user always starts on their own
    // the calendars (month / timeline / FAT / จัดส่ง) leave finished work out unless asked; remembered on this computer
    hideDone: (() => { try { return localStorage.getItem('planning.showDone') !== '1'; } catch (e) { return true; } })(),
    moreOpen: false,
    modalId: null,                    // open project modal
    stageDept: null,                  // open department popover (inside the modal)
    dashProject: '',                  // the dashboard shows this one project ('' = every project in view)
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
    remarkDept: '', remarkCab: '',   // the "หมายเหตุ" tab's filters
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
                if (t && config.openStage) setTimeout(() => this.openStageFromLink(t, config.openStage, config.autoAccept), 150);
                this.projectInUrl(t ? t.id : null);   // keeps ?project= (and ?board=), drops the one-off ?stage= / ?accept=
            });
        }
        // pick up other people's changes every minute, but never while something is being sent or edited
        // pick up other people's changes: every 15 s, and at once when the tab comes back into view (an unchanged board costs a 304)
        const poll = () => { if (!this.saving && !document.hidden && !this.stageDept && !this.cl && !this.newProject) this.refresh(); };
        setInterval(poll, 15000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
        window.addEventListener('resize', () => { if (this.cl && this.cl.anchor) this.clPlace(); });   // e.g. an iPad turned round
        // the รอบส่ง window's frame (embed-layout): a step saved (the calendar behind shows it at once), one of its panels
        // open / closed (this header dims with it), Esc with nothing of its own open (the window closes)
        window.addEventListener('message', (e) => {
            const m = e.origin === location.origin && e.data ? e.data : {};
            if (m.type === 'pmo-delivery-saved') { if (this.ship) this.ship.saved = true; this.refresh(true); }
            else if (m.type === 'pmo-ship-panel' && this.ship) this.ship.panel = !!m.open;
            else if (m.type === 'pmo-ship-close' && this.ship) this.closeShip();
        });
    },

});
