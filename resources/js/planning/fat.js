// Planning board · วัน FAT: its tag on the calendar, its window, its states.
// One part of the planningBoard Alpine component (see ./index.js); `this` is the whole component.
import { MONTHS, WEEKDAYS, PRIORITIES, TH_DAYS, PRIORITY_RANK, TH_MON_SHORT, AVATAR_COLORS, MAX_LANES, SHIP_COLOR, TL_DAY, pad, ymd, parse, addDays, diffDays, hexToRgba, beFormat, beParse, csrf } from './shared';

export default ({ config, board, starKey, loadStars, t0 }) => ({
    // ---- วัน FAT (the latest round of a project, set on the project page): a "FAT" tag on its day ----
    fatOn(date) { return this.visible().filter((t) => t.fat && !t.fat.pending && t.fat.date <= date && date <= (t.fat.end || t.fat.date)); },
    fatLate(t) { return t.fat.status !== 'passed' && !!t.date && (t.fat.end || t.fat.date) > t.date; },
    // the FAT / delivery states' names and colours (Settings > สถานะงาน): '--c' / '--fat' / '--del' on the element, app.css does the rest
    evState(group, key) { return (this.evs[group] || {})[key] || { name: key, color: '#64748b' }; },
    fatState(t) { return t.fat.status !== 'scheduled' ? t.fat.status : this.fatLate(t) ? 'late' : 'scheduled'; },
    fatColor(t) { return this.evState('fat', this.fatState(t)).color; },
    fatTag() { return 'st-tag shrink-0 rounded px-1 text-[11px] font-extrabold tracking-wide'; },
    // a round waiting with its project for sales to give the dates (ProjectDates): said instead of its state
    fatStatus(t) { return t.fat.pending ? 'รอฝ่ายขายแจ้งกำหนดส่ง' : this.evState('fat', t.fat.status).name; },
    fatPill() { return 'st-pill'; },
    fatChip(t) { return 'st-chip flex h-6 w-full min-w-0 items-center gap-1 rounded-md border pl-0.5 pr-1.5 text-left text-[12px] font-semibold' + (t.fat.status === 'failed' ? ' line-through' : ''); },
    // "29/10", "29–31/10", "30/10–02/11"
    fatRange(f) {
        const [, m1, d1] = f.date.split('-'), [, m2, d2] = (f.end || f.date).split('-');
        return f.end && f.end !== f.date ? (m1 === m2 ? d1 + '–' + d2 + '/' + m2 : d1 + '/' + m1 + '–' + d2 + '/' + m2) : d1 + '/' + m1;
    },
    // the tab on every FAT line (month and timeline alike): the line sits on its days, so how many and the time
    fatLabel(f) { return f.pending ? 'FAT · รอกำหนดส่ง' : 'FAT' + (f.end && f.end !== f.date ? ' ' + (diffDays(f.end, f.date) + 1) + ' วัน' : '') + (f.time ? ' · ' + f.time : ''); },
    fatBandCls() { return 'fat-band'; },
    // the FAT days as a striped band over a bar of the month (a cabinet's bar only when that cabinet is in the round)
    fatBand(b) {
        const f = b.t.fat;
        if (!f || f.pending || b.t.ship || (b.c && f.cabinets && !f.cabinets.includes(b.c.id))) return null;
        const from = f.date > b.cs ? f.date : b.cs, to = (f.end || f.date) < b.ce ? (f.end || f.date) : b.ce;
        if (from > to) return null;
        const days = diffDays(b.ce, b.cs) + 1, a = diffDays(from, b.cs), e = diffDays(to, b.cs) + 1;
        return { left: (a / days) * 100, width: ((e - a) / days) * 100, cls: this.fatBandCls(b.t), color: this.fatColor(b.t), label: this.fatLabel(f) };
    },
    // "+n โครงการ": does a project hidden that day have its FAT on it
    hiddenFat(week, date) { return this.weekBars(week).some((b) => b.lane >= MAX_LANES && b.cs <= date && date <= b.ce && this.fatBand({ ...b, cs: date, ce: date })); },
    // "เลื่อน n": a project / cabinet postponed n times (ProjectDates via PlanningBoard; null = never)
    resChip(res, long) { return long ? 'เลื่อน ' + res.rounds + ' ครั้ง' + (res.days ? ' · ' + (res.days > 0 ? '+' : '') + res.days + ' วัน' : '') + ' ›' : 'เลื่อน ' + res.rounds; },
    resDays(n) { return n ? ' (' + (n > 0 ? '+' : '') + n + ')' : ''; },
    fmtShort(s) { if (!s) return '-'; const [, m, d] = s.split('-'); return d + '/' + m; },
    showRes(ev, res, pinned = false) {
        if (!res) return;
        const r = ev.currentTarget.getBoundingClientRect();
        this.resPop = { res, pinned, top: Math.max(8, Math.min(r.bottom + 6, window.innerHeight - 90 - 22 * res.list.length)), left: Math.max(8, Math.min(r.left, window.innerWidth - 348)) };
    },
    resLines(res) {
        return ['เลื่อนแล้ว ' + res.rounds + ' ครั้ง' + (res.days ? ' · รวม ' + (res.days > 0 ? '+' : '') + res.days + ' วัน' : ''), 'ครบกำหนดตอนแรก ' + this.fmtBE(res.firstDue),
            'ดูรายละเอียด / เลื่อนวัน ที่หน้า' + (res.url.includes('/cabinets/') ? 'ตู้' : 'โครงการ') + ' ↗'];
    },
    resRound(r) { return ' · ' + (r.reason || 'ไม่ระบุสาเหตุ') + ' · ' + this.fmtShort(r.from) + ' → ' + this.fmtShort(r.to) + this.resDays(r.days); },
    // "เลื่อน / แก้วัน" and "ลง / แก้วัน FAT": หน้าโครงการ's own windows (ProjectDateController@form, ProjectFatController@form)
    // fetched into the page; they save as JSON, no reload
    canMoveDates(t) { return this.isPmo && !(t.warnings || {}).noDue; },   // as หน้าโครงการ: Admin / PM, once the project has a due date
    canFat(t) { return this.isPmo && !['completed', 'cancelled'].includes(t.pmoStatus); },   // Admin / PM, an open project
    openDates(t) { return this.openPmoWindow(t.pmoUrl + '/dates', 'project-dates', 'เลื่อนวัน'); },
    openFat(t) { return this.openPmoWindow(t.pmoUrl + '/fat', 'project-fat', 'วัน FAT'); },
    // รอบส่ง (the delivery department): a งานขนส่ง bar or a round opens งานจัดส่ง's own card and steps for that project,
    // at that round (did); anything else on a bar opens the project as before
    barGo(t, did) { if (this.shipUrl && (t.ship || did)) return this.openShip(t, did); this.openModal(t.id); },
    openShip(t, did) {
        const d = did ? this.dels(t).find((x) => x.id === did) : null;
        this.shipFrom = document.activeElement;   // focus goes back there when it closes
        this.ship = { t, did: did || null, round: d ? d.round : null, url: this.shipUrl + '&project=' + String(t.id).replace(/^p/, '') + (did ? '&round=' + did : ''),
                      loaded: false, failed: false, panel: false, saved: false };
        this.shipTimer();
    },
    // no answer in 20 s: say so, with ลองใหม่ (shared hosting can be slow, a phone can lose its signal)
    shipTimer() { clearTimeout(this._shipWait); this._shipWait = setTimeout(() => { if (this.ship && !this.ship.loaded) this.ship.failed = true; }, 20000); },
    // the frame finished loading: งานจัดส่ง's list is there, or it is the login page (session over: the whole page goes
    // to it, then back here), or something else (an error page): ลองใหม่
    shipLoaded(el) {
        if (!this.ship) return;
        let doc = null;
        try { doc = el.contentDocument; } catch (e) { /* not ours */ }
        if (doc && doc.getElementById('dl-rows')) { this.ship.loaded = true; this.ship.failed = false; clearTimeout(this._shipWait); return; }
        if (doc && doc.querySelector('form input[name=password]')) { location.reload(); return; }
        if (doc && doc.URL === 'about:blank') return;
        this.ship.failed = true; clearTimeout(this._shipWait);
    },
    retryShip() { if (!this.ship) return; Object.assign(this.ship, { loaded: false, failed: false, panel: false }); this.$refs.shipFrame.src = this.ship.url; this.shipTimer(); },
    // a tap on the dimmed header: Esc inside the frame (its open panel closes, as its own Esc does)
    shipEsc() { try { this.$refs.shipFrame.contentWindow.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); } catch (e) { /* not loaded */ } },
    shipFullUrl() { return this.shipUrl.split('?')[0] + (this.ship && this.ship.did ? '?round=' + this.ship.did : ''); },
    closeShip() {
        const saved = this.ship && this.ship.saved, from = this.shipFrom;
        clearTimeout(this._shipWait);
        this.ship = null; this.shipFrom = null;
        if (saved) this.refresh(true);
        if (from && from.isConnected && from.focus) from.focus({ preventScroll: true });
    },
    openDelivery(t, d) { return this.openPmoWindow(t.pmoUrl + '/delivery' + (d ? '?delivery=' + d.id : ''), 'project-delivery', 'การจัดส่ง'); },   // d = that round, else a new one
    async openPmoWindow(url, modal, what) {
        const host = document.getElementById('pb-dates');
        try {
            const res = await fetch(url, { headers: { 'Accept': 'text/html' } });
            if (!res.ok) throw new Error(res.status);
            host.innerHTML = await res.text();
        } catch (e) { window.showToast('เปิดหน้าต่าง' + what + 'ไม่สำเร็จ', 'error'); return; }
        this.pmoModal = modal;
        host.onsubmit = (e) => this.savePmoWindow(e);
        if (!this.datesEsc) {   // Esc closes that window only, not the project window under it (onEscape)
            this.datesEsc = true;
            window.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && [...document.querySelectorAll('#pb-dates > div > div')].some((m) => m.style.display !== 'none')) this.escHeld = true;
            }, true);
        }
        setTimeout(() => window.dispatchEvent(new CustomEvent('open-modal', { detail: modal })), 0);
    },
    async savePmoWindow(e) {
        e.preventDefault();
        const form = e.target, btn = e.submitter || form.querySelector('[type=submit]');   // the button pressed (การจัดส่ง: บันทึกร่าง / ส่ง)
        const body = new FormData(form, e.submitter && e.submitter.name ? e.submitter : undefined);
        if (btn) btn.disabled = true;
        try {
            const res = await fetch(form.action, { method: 'POST', body, headers: { 'Accept': 'application/json' } });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                const msg = data.errors ? Object.values(data.errors)[0][0] : (data.message || 'บันทึกไม่สำเร็จ');
                if (form.classList.contains('hidden')) { window.showToast(msg, 'error'); return; }   // the FAT window's delete form
                let p = form.querySelector('[role=alert]');
                if (!p) { p = document.createElement('p'); p.setAttribute('role', 'alert'); p.className = 'rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800'; (form.querySelector(':scope > h3') || form.children[1]).after(p); }
                p.textContent = msg;
                return;
            }
            window.dispatchEvent(new CustomEvent('close-modal', { detail: this.pmoModal }));
            window.showToast(data.message);
            await this.refresh(true);
            this.$nextTick(() => this.syncDateFields());
        } catch (err) {
            window.showToast('บันทึกไม่สำเร็จ: ' + err.message, 'error');
        } finally { if (btn) btn.disabled = false; }
    },
    // the date fields are set once (dateField); after "เลื่อน / แก้วัน" saved, show the dates the page now holds
    syncDateFields() {
        document.querySelectorAll('input.flatpickr-input').forEach((el) => { if (el._pbGet && el._flatpickr) el._flatpickr.setDate(el._pbGet() || null, false); });
    },
    hideRes() { if (this.resPop && !this.resPop.pinned) this.resPop = null; },
    fatTitle(t) {
        const f = t.fat;
        return 'FAT' + (f.round > 1 ? ' รอบที่ ' + f.round : '') + ' · ' + t.so + ' ' + t.title + ' · ' + this.fmtBE(f.date) + (f.end && f.end !== f.date ? ' – ' + this.fmtBE(f.end) : '') + (f.time ? ' ' + f.time + ' น.' : '')
            + ' · ' + this.fatStatus(t) + (this.fatLate(t) ? ' · ' + this.evState('fat', 'late').name : '') + (f.note ? ' · ' + f.note : '');
    },
    // the timeline: the day column of a row's FAT (a cabinet row only when that cabinet is in the round), else 0
    tlFat(r) {
        const f = r.t.fat, days = this.tlDays();
        if (!f || r.t.ship || f.pending || (r.c && f.cabinets && !f.cabinets.includes(r.c.id))) return null;
        const a = days.findIndex((d) => d.date >= f.date), z = days.findLastIndex((d) => d.date <= (f.end || f.date));
        return a < 0 || z < a ? null : { col: a + 1, span: z - a + 1, label: this.fatLabel(f) };
    },
});
