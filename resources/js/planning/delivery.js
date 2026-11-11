// Planning board · การจัดส่ง: the rounds on the calendar, the FAT / จัดส่ง tabs, the รอบส่ง drawer for the delivery department.
// One part of the planningBoard Alpine component (see ./index.js); `this` is the whole component.
import { MONTHS, WEEKDAYS, PRIORITIES, TH_DAYS, PRIORITY_RANK, TH_MON_SHORT, AVATAR_COLORS, MAX_LANES, SHIP_COLOR, TL_DAY, pad, ymd, parse, addDays, diffDays, hexToRgba, beFormat, beParse, csrf } from './shared';

export default ({ config, board, starKey, loadStars, t0 }) => ({
    // ---- การจัดส่ง (ProjectDeliveryController via PlanningBoard::delivery): a "ส่ง" line along the bottom of the bar on each
    // round's day, its tab at the right end; the project window lists the rounds and each cabinet's ----
    dels(t, c) { return (t.deliveries || []).filter((d) => !c || d.cabs.includes(c.id)); },
    delDone(t) { return this.dels(t).filter((d) => d.status === 'delivered').reduce((n, d) => n + d.cabs.length, 0); },
    delNext(t) { return this.dels(t).filter((d) => d.status !== 'delivered').sort((a, b) => (a.date + a.time).localeCompare(b.date + b.time))[0]; },
    delHead(t) {
        const n = this.delNext(t);
        return 'ส่งแล้ว ' + this.delDone(t) + '/' + t.subtasks.length + ' ตู้' + (n ? ' · รอบถัดไป ' + this.fmtShort(n.date) + (n.time ? ' · ' + n.time : '') + ' · ' + (n.wait ? this.delState(n) : n.stepText) : '');
    },
    // wait = no vehicle on it yet (ร่าง / รอจัดรถ / ไม่รับงาน): drawn dashed on PM's day
    delKey(d) { return d.status !== 'scheduled' ? d.status : ({ company: 'booked', external: 'external', pickup: 'pickup' }[d.method] || 'booked'); },
    delColor(d) { return this.evState('delivery', this.delKey(d)).color; },
    delState(d) { return d.pending ? 'รอฝ่ายขายแจ้งกำหนดส่ง' : this.evState('delivery', this.delKey(d)).name + (d.status === 'departed' && d.dep ? ' ' + d.dep : ''); },
    delCls() { return 'st-pill'; },
    delWhen(d) { if (d.pending) return 'รอกำหนดส่ง'; return d.status === 'delivered' && d.at ? this.fmtShort(d.at.slice(0, 10)) + ' · ถึง ' + d.at.slice(11) : this.delRange(d) + (d.time ? ' · ' + d.time : ''); },
    // the four steps after the vehicles (ห่อ n/m → แจ้งเปิดบิล → ออกจากโรงงาน → ส่งถึง): [{ label, fill %, now, done }]
    delSteps(d) {
        const n = d.step || 0, all = d.cabs.length || 1;
        return ['ห่อ ' + (d.packed || []).length + '/' + d.cabs.length, 'แจ้งเปิดบิล', 'ออกรถ', 'ส่งถึง'].map((label, i) => {
            const fill = i === 0 ? (n >= 1 ? 100 : Math.round(((d.packed || []).length / all) * 100)) : (n > i ? 100 : 0);
            return { label, fill, now: n === i, done: n > i };
        });
    },
    // "20/11", "20–22/11 (3 วัน)" (a round over several days, as fatRange)
    delRange(d) { return this.fatRange({ date: d.date, end: d.end }) + (d.end && d.end !== d.date ? ' (' + (diffDays(d.end, d.date) + 1) + ' วัน)' : ''); },
    delCar(d) { return (d.method === 'company' ? '🚚 ' : '') + (d.carFull || d.car || '-') + (d.people ? ' · ' + d.people : (d.crew ? ' · ' + d.crew + ' คน' : '')); },
    delCabs(d) { return d.cabs.length ? d.cabs.length + ' ตู้' : 'ทุกตู้'; },   // a draft from the new-project page: every cabinet
    delCabName(t, id) { const c = t.subtasks.find((x) => x.id === id); return c ? c.mo || c.text : '-'; },
    // a cabinet row: "ส่ง 12/11 · 4 ล้อ" / "นัดส่ง 20/11 · 6 ล้อเครน" / "รอจัดรถ 20/11 · ห่อแล้ว"
    delCab(t, c) {
        const d = this.dels(t, c)[0];
        const packed = d && d.status !== 'delivered' && (d.packed || []).includes(c.id) ? ' · ห่อแล้ว' : '';
        return d ? { cls: d.wait ? 'dl-chip st-chip dl-need' : d.status === 'delivered' ? 'dl-chip st-chip' : 'dl-chip', style: d.wait || d.status === 'delivered' ? '--c:' + this.delColor(d) : '',
            text: d.pending ? 'รอกำหนดส่ง' + packed : d.wait ? this.delState(d) + ' ' + this.fatRange(d) + packed : (d.status === 'delivered' ? 'ส่ง ' : 'นัดส่ง ') + (d.status === 'delivered' && d.at ? this.fmtShort(d.at.slice(0, 10)) : this.fatRange(d)) + ' · ' + (d.method === 'company' ? '🚚 ' : '') + d.car + packed } : null;
    },
    delLabel(d) {
        if (d.pending) return 'ส่ง · รอกำหนดส่ง';   // waiting for sales: the day drawn is the old one
        if (d.status === 'delivered') return this.evState('delivery', 'delivered').name + ' ' + this.delCabs(d);
        const days = d.end && d.end !== d.date ? ' ' + (diffDays(d.end, d.date) + 1) + ' วัน' : '';
        if (d.wait) return 'ส่ง' + days + (d.time ? ' ' + d.time : '') + ' · ' + this.delState(d);
        return 'ส่ง' + days + (d.time ? ' ' + d.time : '') + ' · ' + (d.status === 'scheduled' ? d.stepText : this.delState(d)) + ' · ' + (d.method === 'company' ? '🚚 ' : '') + d.car;
    },
    // the short words of a round card / a timeline segment (the delivery department's view, calendar.js roundCards)
    delShort(d) {
        if (d.pending) return 'รอกำหนดส่ง';
        if (d.status === 'delivered') return '✓ ' + this.evState('delivery', 'delivered').name + (d.at ? ' ' + d.at.slice(11) : '');
        if (d.wait) return this.delState(d) + (d.time ? ' · ' + d.time : '');
        return (d.time ? d.time + ' · ' : '') + (d.status === 'scheduled' ? d.stepText : d.status === 'departed' ? 'ออกรถแล้ว' : this.delState(d));
    },
    delBandCls(d) { return 'del-band' + (d.wait ? ' del-wait' : ''); },
    delTitle(t, d) {
        return 'ส่งรอบที่ ' + d.round + ' · ' + t.so + ' · ' + this.fmtBE(d.date) + (d.end && d.end !== d.date ? ' – ' + this.fmtBE(d.end) : '') + (d.time ? ' ' + d.time + ' น.' : '')
            + ' · ' + this.delCabs(d) + ' · ' + this.delState(d) + (d.status === 'scheduled' ? ' · ' + d.stepText : '') + ' · ' + (d.carFull || d.car || '-') + (d.crew ? ' · ' + d.crew + ' คน' : '') + (d.why ? ' · ' + d.why : '');
    },
    // the month: each round on this bar's days (a cabinet's bar: the rounds it is in)
    delBands(b) {
        if (this.roundCards()) return [];   // the delivery department: each round is its own card (calendar.js monthEntries)
        const days = diffDays(b.ce, b.cs) + 1;
        return this.dels(b.t, b.c).filter((d) => !d.pending && d.date <= b.ce && (d.end || d.date) >= b.cs)   // a round over several days: its days on this bar
            .map((d) => { const a = d.date < b.cs ? b.cs : d.date, z = (d.end || d.date) > b.ce ? b.ce : (d.end || d.date); return { a, z, d }; })
            .map(({ a, z, d }) => ({ id: d.id, left: (diffDays(a, b.cs) / days) * 100, width: ((diffDays(z, a) + 1) / days) * 100, cls: this.delBandCls(d), color: this.delColor(d), label: this.delLabel(d), title: this.delTitle(b.t, d) }));
    },
    // ---- FAT / จัดส่ง (_events): the tab's appointments alone, laid out as เดือน is (one bar over its days, lanes per week) ----
    evAll(kind) {
        const out = [];
        this.visible().forEach((t) => {
            if (kind === 'fat' && t.fat && !t.fat.pending) out.push({ key: 'f' + t.id, kind, from: t.fat.date, to: t.fat.end || t.fat.date, time: t.fat.time, t, f: t.fat });
            if (kind === 'del') this.dels(t).filter((d) => !d.pending).forEach((d) => out.push({ key: 'd' + d.id, kind, from: d.date, to: d.end || d.date, time: d.time, t, d }));
        });
        return out;
    },
    evShown(e) { return !this.evNeed || e.kind !== 'del' || (e.d.wait && e.d.status !== 'draft'); },   // รอจัดรถ / ไม่รับงาน: sent, no vehicle yet (not PM's drafts)
    evBars(week) {
        const ws = week[0].date, we = week[week.length - 1].date, laneEnd = [], bars = [];
        const list = this.evAll(this.mode).filter((e) => this.evShown(e) && e.from <= we && e.to >= ws)
            .sort((a, b) => a.from.localeCompare(b.from) || (a.time || '99').localeCompare(b.time || '99') || a.t.so.localeCompare(b.t.so));
        for (const e of list) {
            const cs = e.from < ws ? ws : e.from, ce = e.to > we ? we : e.to;
            const a = week.findIndex((d) => d.date === cs), z = week.findIndex((d) => d.date === ce);
            let lane = laneEnd.findIndex((end) => end < a);
            if (lane === -1) { lane = laneEnd.length; laneEnd.push(-1); }
            laneEnd[lane] = z;
            bars.push({ ...e, lane, col: a + 1, span: z - a + 1, before: e.from < ws, after: e.to > we });
        }
        return bars;
    },
    evShownBars(week) { return this.evOpen[week[0].date] ? this.evBars(week) : this.evBars(week).filter((b) => b.lane < MAX_LANES); },
    evHidden(week, date) { return this.evOpen[week[0].date] ? 0 : this.evBars(week).filter((b) => b.lane >= MAX_LANES && b.from <= date && date <= b.to).length; },
    // the header's numbers: the month on screen only
    evCount() {
        const m1 = this.year + '-' + pad(this.month + 1) + '-01', m2 = this.year + '-' + pad(this.month + 1) + '-31', inMonth = (e) => e.from <= m2 && e.to >= m1;
        const fat = this.mode === 'fat' ? this.evAll('fat').filter(inMonth) : [], del = this.mode === 'del' ? this.evAll('del').filter(inMonth) : [];
        return { fat: fat.length, del: del.length, wait: del.filter((e) => e.d.wait && e.d.status !== 'draft').length, shown: [...fat, ...del].filter((e) => this.evShown(e)).length };
    },
    evCls(e) { return 'ev-card' + (e.kind === 'fat' ? (e.f.status === 'failed' ? ' line-through' : '') : (e.d.wait ? ' ev-need' : '')); },
    evColor(e) { return e.kind === 'fat' ? this.fatColor(e.t) : this.delColor(e.d); },
    // the tab's legend: every state of its group, as Settings > สถานะงาน names and colours them
    evLegend() { return Object.values(this.evs[this.mode === 'fat' ? 'fat' : 'delivery'] || {}); },
    evHead(e) {
        if (e.kind === 'fat') return 'FAT' + (e.time ? ' ' + e.time : '') + (e.to !== e.from ? ' · ' + this.fatRange(e.f) + ' (' + (diffDays(e.to, e.from) + 1) + ' วัน)' : '');
        if (e.d.wait || e.d.pending) return this.delState(e.d) + (e.time ? ' · ' + e.time : '') + (e.to !== e.from ? ' · ' + this.delRange(e.d) : '');
        return e.d.status === 'delivered' ? this.evState('delivery', 'delivered').name + (e.d.at ? ' ' + e.d.at.slice(11) : '') : 'ส่ง' + (e.time ? ' ' + e.time : '') + (e.to !== e.from ? ' · ' + this.delRange(e.d) : '');
    },
    evMeta(e) {
        if (e.kind === 'fat') return (e.f.round > 1 ? 'รอบ ' + e.f.round + ' · ' : '') + (e.f.cabinets ? e.f.cabinets.length + ' ตู้' : 'ทุกตู้') + ' · ' + this.fatStatus(e.t) + (this.fatLate(e.t) ? ' · ' + this.evState('fat', 'late').name : '');
        const d = e.d;
        if (d.why) return 'รอบ ' + d.round + ' · ' + d.why;
        return 'รอบ ' + d.round + ' · ' + (d.status === 'scheduled' ? d.stepText + ' · ' : '') + (d.method === 'company' ? '🚚 ' : '') + (d.car || '-') + (d.crew ? ' · ' + d.crew + ' คน' : '') + ' · ' + this.delCabs(d) + (d.status === 'departed' ? ' · ' + this.delState(d) : '');
    },
    evTitle(e) { return e.kind === 'fat' ? this.fatTitle(e.t) : this.delTitle(e.t, e.d); },
    // Admin / PM open the FAT / delivery window itself (an open project, as the project window's buttons); others the project's window
    evGo(e) { if (e.kind === 'del' && this.shipUrl) return this.openShip(e.t, e.d.id); if (!this.canFat(e.t)) return this.openModal(e.t.id); return e.kind === 'fat' ? this.openFat(e.t) : this.openDelivery(e.t, e.d); },
    tlDels(r) {
        const days = this.tlDays();
        return this.dels(r.t, r.c).filter((d) => !d.pending).map((d) => ({ d, i: days.findIndex((x) => x.date >= d.date), z: days.findLastIndex((x) => x.date <= (d.end || d.date)) })).filter((x) => x.i >= 0 && x.z >= x.i)
            .map(({ d, i, z }) => ({ id: d.id, col: i + 1, span: z - i + 1, cls: this.delBandCls(d), color: this.delColor(d), label: this.delLabel(d), title: this.delTitle(r.t, d) }))
            .map((g, n, all) => {
                if (!this.roundCards()) return g;
                // the delivery department: a segment on the round's days, its short words to its right as far as the next round
                const next = all.filter((x) => x.col > g.col).map((x) => x.col).sort((a, b) => a - b)[0] || days.length + 1;
                const room = (next - g.col - g.span) * TL_DAY - 10;
                return { ...g, cls: g.cls + ' del-seg', label: room >= 44 ? this.delShort(this.dels(r.t).find((d) => d.id === g.id)) : '', room };
            });
    },
});
