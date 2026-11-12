// งานจัดส่ง (deliveries/index): the page's Alpine component - the rounds' cards and their panels (จัดรถ / คน, ไม่รับงาน,
// รายการตู้, ส่งถึงแล้ว, ข้อความส่งงาน, ตรวจแล้ว), the copy buttons, the photos picked. Registered from app.js before Alpine
// starts; the page passes what only the server knows as `config` (deliveries/index: x-data="deliveriesPage(...)").
import Alpine from 'alpinejs';

export default function registerDeliveriesPage() {
    Alpine.data('deliveriesPage', (config) => ({
        r: null, tab: config.tab, busy: config.busy, vehicles: config.vehicles, reasons: config.reasons, people: config.people, dlv: { cabinet_ids: null }, toast: '',
        rej: { open: false, reason: '', note: '', saving: false, error: '', errors: {} },
        asg: { open: false, rows: [], carrier: '', saving: false, error: '', errors: {} },
        // จัดรถ / คน: the round's vehicles, or one empty line per kind PM asked for
        openAssign(row) {
            this.r = row;
            const blank = (t) => ({ vehicle_id: '', label: '', driver_name: '', crew: [], want: t || '' });
            const rows = row.vehicles.length ? row.vehicles.map((v) => ({ vehicle_id: v.vehicle_id, label: v.label, driver_name: v.driver_name, crew: [...v.crew], want: '' }))
                : (row.method === 'company' && row.types.length ? row.types.map(blank) : [blank('')]);
            this.asg = { open: true, rows, carrier: row.carrier, map: row.map, saving: false, error: '', errors: {} };
        },
        asgErr(k) { return (this.asg.errors[k] || [])[0] || ''; },
        // vehicles filled in so far (a picked one, or an external one's words)
        get asgCars() { return this.asg.rows.filter((x) => this.r.method === 'company' ? x.vehicle_id : (x.label || '').trim()).length; },
        saveAssign(form) { if (this.canSave) this.submit(form, this.asg); },
        // a panel's form saved without reloading: the panel closes, the tabs and the list are drawn again from a fresh
        // copy of this tab; refused, it stays open with the reason (st: asg / rej)
        async submit(form, st) {
            if (st.saving) return;
            st.saving = true; st.error = ''; st.errors = {};
            try {
                const res = await fetch(form.action, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: new FormData(form) });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) { st.errors = data.errors || {}; st.error = (data.errors && Object.values(data.errors)[0][0]) || data.message || 'บันทึกไม่ได้ ลองอีกครั้ง'; return; }
                st.open = false;
                this.say(data.message || 'บันทึกแล้ว');
                await this.refreshList();
            } catch (e) {
                st.error = 'บันทึกไม่ได้ ตรวจการเชื่อมต่อแล้วลองอีกครั้ง';
            } finally {
                st.saving = false;
            }
        },
        // a card's one-tap step (ออกจากโรงงาน, รับงาน): saved without reloading, the list drawn again
        async quick(url) {
            if (this._quick) return;
            this._quick = true;
            try {
                const res = await fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
                const data = await res.json().catch(() => ({}));
                this.say(res.ok ? (data.message || 'บันทึกแล้ว') : ((data.errors && Object.values(data.errors)[0][0]) || data.message || 'บันทึกไม่ได้ ลองอีกครั้ง'));
                if (res.ok) await this.refreshList();
            } catch (e) {
                this.say('บันทึกไม่ได้ ตรวจการเชื่อมต่อแล้วลองอีกครั้ง');
            } finally {
                this._quick = false;
            }
        },
        // ตรวจแล้ว · แจ้ง PM asks once more, saying what PM will get
        rv: null,
        askReview(row) { this.rv = row; this.$nextTick(() => this.$refs.rvOk && this.$refs.rvOk.focus({ preventScroll: true })); },
        async doReview() { const row = this.rv; this.rv = null; if (row) await this.quick(row.urls.review); },
        // ข้อความส่งงาน: the LINE message for the crew written from the round (DeliveryBrief), changed here if need be
        // (the time to leave, the map link …) and copied; nothing is saved - it opens fresh from the round each time
        brief: { open: false, row: null, text: '' },
        openBrief(row) { this.brief = { open: true, row, text: row.brief }; },
        // one tap copies; the button says so for a moment (copied = its key). The Clipboard API needs https - on plain
        // http (the office network) a hidden text field is copied instead.
        copied: null,
        async copyText(text, key, said) {
            let ok = false;
            try { if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(text); ok = true; } } catch (e) { /* below */ }
            if (!ok) {
                const t = document.createElement('textarea');
                t.value = text; t.setAttribute('readonly', ''); t.style.cssText = 'position:fixed;top:0;left:0;opacity:0;font-size:16px';
                document.body.appendChild(t); t.focus(); t.select(); t.setSelectionRange(0, text.length);
                try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                t.remove();
            }
            if (!ok) { window.prompt('คัดลอกข้อความนี้', text); return; }
            this.copied = key; clearTimeout(this._copied); this._copied = setTimeout(() => { this.copied = null; }, 2500);
            this.say(said);
        },
        say(m) { this.toast = m; clearTimeout(this._toast); this._toast = setTimeout(() => { this.toast = ''; }, 3500);
                 // in Planning's รอบส่ง window: something was saved (or refused), so the calendar behind asks again
                 if (window.parent !== window) window.parent.postMessage({ type: 'pmo-delivery-saved' }, location.origin); },
        async refreshList() {
            const u = new URL(location.href); u.searchParams.set('tab', this.tab);
            const html = await fetch(u, { headers: { 'Accept': 'text/html' } }).then((x) => (x.ok ? x.text() : null)).catch(() => null);
            if (!html) { location.reload(); return; }
            const doc = new DOMParser().parseFromString(html, 'text/html');
            ['dl-tabs', 'dl-rows'].forEach((id) => { const n = doc.getElementById(id), o = document.getElementById(id); if (n && o) o.replaceWith(n); });
            const live = doc.getElementById('dl-live');
            if (live) { const d = JSON.parse(live.textContent); this.busy = d.busy; this.people = d.people; }
        },
        car(id) { return this.vehicles.find((v) => String(v.id) === String(id)); },
        // จัดรถ / คน: what goes, to judge the vehicles - how many cabinets (each one's quantity), the same sizes
        // together (biggest group first), and how many the chosen vehicles carry (รถขนส่ง's ~n ตู้; 0 = not set)
        cabUnits(cs) { return cs.reduce((n, c) => n + (c.qty || 1), 0); },
        cabSizes(cs) { const m = new Map(); cs.forEach((c) => { const k = (c.size || '').trim() || 'ไม่ระบุขนาด'; m.set(k, (m.get(k) || 0) + (c.qty || 1)); });
                       return [...m].map(([size, n]) => ({ size, n })).sort((a, b) => b.n - a.n); },
        get asgRoom() { return this.asg.rows.reduce((n, v) => n + (Number((this.car(v.vehicle_id) || {}).capacity) || 0), 0); },
        // the vehicles of the kind the line was opened for first, then the others PM asked for, then the rest
        carList(want) { const t = this.r ? this.r.types : []; const rank = (v) => (want && v.type === want ? 2 : (t.includes(v.type) ? 1 : 0)); return [...this.vehicles].sort((a, b) => rank(b) - rank(a)); },
        pickCar(row) { const v = this.car(row.vehicle_id); if (v && !row.driver_name) row.driver_name = v.driver; },
        takenElsewhere(row) { return row.vehicle_id && this.asg.rows.filter((x) => String(x.vehicle_id) === String(row.vehicle_id)).length > 1; },
        // another round on the same vehicle on any of this round's days
        busyOn(row) { const v = this.busy[row.vehicle_id] || {}; return [...new Set(this.r.days.flatMap((day) => (v[day] || []).filter((x) => x.id !== this.r.id).map((x) => x.label)))]; },
        notAsked(row) { const v = this.car(row.vehicle_id); return v && v.type && this.r.types.length && !this.r.types.includes(v.type) ? v.type : ''; },
        get going() { return this.asg.rows.flatMap((x) => [x.driver_name, ...x.crew]).filter((n) => n && n.trim()).length; },
        get canSave() { return this.r && (this.r.method !== 'company' || this.asg.rows.some((x) => x.vehicle_id)) && !this.asg.rows.some((x) => this.takenElsewhere(x)); },
        // ห่อตู้ → แจ้งเปิดบิล → ออกจากโรงงาน, and ย้อนขั้น: one panel; each tick saves at once (JSON) and the round's card
        // behind it is redrawn from the page; its bottom bar is always the next thing to do (cabStep)
        cab: { open: false, row: null, list: [], filter: 'all', q: '', error: '', saving: 0, act: '', back: false },
        openCabs(row) {
            const list = row.cabinets.map((c) => ({ ...c, busy: false }));
            const todo = list.filter((c) => !c.packed).length;
            this.cab = { open: true, row, list, filter: row.packable && todo && todo < list.length ? 'todo' : 'all', q: '', error: '', saving: 0, act: '', back: false };
        },
        // pack: cabinets still to pack · bill: all packed, แจ้งเปิดบิล next · wait: all packed, sent back to PM ·
        // billed: แจ้งเปิดบิลแล้ว, not left yet · after: left the factory / arrived (look only)
        get cabStep() {
            const r = this.cab.row;
            if (!r) return '';
            if (r.billed && ['waiting', 'rejected', 'scheduled'].includes(r.status)) return 'billed';
            if (!r.packable) return 'after';
            if (this.cabCount('todo')) return 'pack';
            return r.status === 'rejected' ? 'wait' : 'bill';
        },
        // แจ้งเปิดบิล / ออกจากโรงงาน / ย้อนขั้น from the panel: it carries on with the round as the server has it now
        async cabAct(kind) {
            const row = this.cab.row;
            if (this.cab.act || this.cab.saving) return;
            this.cab.act = kind; this.cab.error = '';
            try {
                const res = await fetch(row.urls[kind], { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error((data.errors && Object.values(data.errors)[0][0]) || data.message || 'บันทึกไม่ได้ ลองอีกครั้ง');
                this.say(data.message || 'บันทึกแล้ว');
                if (kind === 'depart') {
                    this.cab.open = false;
                } else if (data.row) {
                    const now = Object.fromEntries(data.row.cabinets.map((c) => [c.id, c]));
                    this.cab.row = data.row;
                    this.cab.list.forEach((c) => { if (now[c.id]) { c.packed = now[c.id].packed; c.at = now[c.id].at; } });
                }
                this.cab.back = false;
                this.refreshList();
            } catch (e) {
                this.cab.error = e.message;
            } finally {
                this.cab.act = '';
            }
        },
        cabCount(f) { return this.cab.list.filter((c) => f === 'todo' ? !c.packed : f === 'done' ? c.packed : true).length; },
        get cabShown() {
            const q = this.cab.q.trim().toLowerCase();
            return this.cab.list.filter((c) => (this.cab.filter === 'todo' ? !c.packed : this.cab.filter === 'done' ? c.packed : true)
                && (!q || [c.name, c.model, c.type, c.size].join(' ').toLowerCase().includes(q)));
        },
        async packCabs(list, packed) {
            list = list.filter((c) => c.packed !== packed && !c.busy);
            if (!list.length || !this.cab.row.packable) return;
            const row = this.cab.row;
            this.cab.error = ''; this.cab.saving++;
            list.forEach((c) => { c.busy = true; c.packed = packed; });   // shown at once, put back if refused
            try {
                const res = await fetch(row.urls.pack, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ cabinet_ids: list.map((c) => c.id), packed: packed ? 1 : 0 }) });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error((data.errors && Object.values(data.errors)[0][0]) || data.message || 'บันทึกไม่ได้ ลองอีกครั้ง');
                this.cab.list.forEach((c) => { if (c.busy && !list.includes(c)) return; c.packed = !!data.packed[c.id]; c.at = data.packed[c.id] || ''; });   // another tick still on its way keeps its own
                this.redrawRound(row.id);
            } catch (e) {
                list.forEach((c) => { c.packed = !packed; });
                this.cab.error = e.message;
            } finally {
                list.forEach((c) => { c.busy = false; });
                this.cab.saving--;
            }
        },
        // the round's card on the page (ห่อ n/m, แจ้งเปิดบิล) from a fresh copy of this page, without reloading it
        redrawRound(id) {
            clearTimeout(this._redraw);
            this._redraw = setTimeout(async () => {
                const html = await fetch(location.href, { headers: { 'Accept': 'text/html' } }).then((r) => r.text()).catch(() => null);
                const fresh = html && new DOMParser().parseFromString(html, 'text/html').getElementById('round-' + id);
                const old = document.getElementById('round-' + id);
                if (fresh && old) old.replaceWith(fresh);
            }, 500);
        },
        openReject(row) { this.r = row; this.rej = { open: true, reason: '', note: '', saving: false, error: '', errors: {} }; },
        openDeliver(row) { this.r = row; const now = new Date(); this.dlv = { date: row.delivered_date || now.toISOString().slice(0, 10), time: row.delivered_time || String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0'),
            issue: row.issue, cabinet_ids: row.cabinets.map((c) => c.id), files: [], fileError: '', saving: false }; this.$dispatch('open-modal', 'dl-deliver'); },
        // the files picked for ส่งถึงแล้ว: each pick ADDS to the ones already picked (a phone's camera gives one photo a
        // time, so photo after photo), up to FILES_MAX; checked here first (the server checks again). The input's own
        // list is rebuilt from them (DataTransfer) so the form sends them all; a browser without it keeps the last pick.
        async pickFiles(input) {
            this.dlv.shrinking = true;   // a phone's photos made small first (window.pmoShrinkImage, app.js)
            const add = await Promise.all([...input.files].map((f) => (window.pmoShrinkImage ? window.pmoShrinkImage(f) : f)));
            this.dlv.shrinking = false;
            const max = config.filesMax;
            const big = add.filter((f) => f.size > 20 * 1024 * 1024), ok = add.filter((f) => f.size <= 20 * 1024 * 1024);
            const room = Math.max(0, max - this.dlv.files.length);
            this.dlv.fileError = big.length ? big.map((f) => f.name).join(', ') + ' ใหญ่เกิน 20 MB · ไม่ได้เพิ่ม'
                : (ok.length > room ? 'แนบได้ครั้งละไม่เกิน ' + max + ' ไฟล์ · ' + (ok.length - room) + ' ไฟล์หลังไม่ได้เพิ่ม' : '');
            this.dlv.files = this.dlv.files.concat(ok.slice(0, room).map((f) => this.fileTile(f)));
            this.syncFiles(input);
        },
        // a photo is shown from the phone itself before it goes up (the tile marked ใหม่)
        fileTile(f) { const ext = (f.name.includes('.') ? f.name.split('.').pop() : '').toLowerCase();
            return { file: f, name: f.name, size: f.size < 1048576 ? Math.max(1, Math.round(f.size / 1024)) + ' KB' : (f.size / 1048576).toFixed(1) + ' MB',
                     ext: ext.toUpperCase().slice(0, 4), preview: ['jpg', 'jpeg', 'png'].includes(ext) || (f.type || '').startsWith('image/') ? URL.createObjectURL(f) : '' }; },
        syncFiles(input) {
            try {
                const dt = new DataTransfer();
                this.dlv.files.forEach((f) => dt.items.add(Alpine.raw(f).file));
                input.files = dt.files;
            } catch (e) {   // an old browser: only what it holds now goes up, so show just that
                this.dlv.files.forEach((f) => f.preview && URL.revokeObjectURL(f.preview));
                this.dlv.files = [...input.files].map((f) => this.fileTile(f));
            }
        },
        dropFile(i, input) { const f = this.dlv.files[i]; if (f && f.preview) URL.revokeObjectURL(f.preview); this.dlv.files.splice(i, 1); this.dlv.fileError = ''; this.syncFiles(input); },
        unpickFiles(input) { this.dlv.files.forEach((f) => f.preview && URL.revokeObjectURL(f.preview)); this.dlv.files = []; this.dlv.fileError = ''; input.value = ''; }
    }));
}
