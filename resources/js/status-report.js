export default function statusReport(cfg) {
    const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
    const get = (k, d) => { try { return localStorage.getItem(k) ?? d; } catch (e) { return d; } };
    const set = (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* storage blocked - preference just won't stick */ } };

    return {
        cfg, open: false, step: 'report', busy: false, data: null,
        pref: get('statusReport.pref', 'daily'),

        async load() {
            const res = await fetch(cfg.url, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) throw new Error('status report ' + res.status);
            this.data = await res.json();
        },

        // Page load: urgent alerts always win; otherwise the daily report if the preference allows and there is work.
        async init() {
            try {
                await this.load();
                if (this.data.alerts.length > 0) { this.step = 'alerts'; this.open = true; return; }
                const due = this.pref === 'always' || (this.pref === 'daily' && get('statusReport.lastShown', '') !== this.data.date);
                if (due && this.data.totals.pending > 0) { this.markShown(); this.step = 'report'; this.open = true; }
            } catch (e) { /* the popup is a convenience - never break the page over it */ }
        },

        // the bell saw a new urgent notice while the page was open
        async showAlerts() {
            try { await this.load(); if (this.data.alerts.length > 0) { this.step = 'alerts'; this.open = true; } } catch (e) { /* ignore */ }
        },

        async openReport() {
            try { await this.load(); this.step = 'report'; this.open = true; } catch (e) { window.showToast && window.showToast('โหลดรายงานไม่สำเร็จ', 'error'); }
        },

        markShown() { set('statusReport.lastShown', this.data.date); },
        savePref() { set('statusReport.pref', this.pref); },
        close() { this.open = false; },

        async acknowledge() {
            this.busy = true;
            try {
                await fetch(cfg.ackUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                    body: JSON.stringify({ ids: this.data.alerts.map((a) => a.id) }),
                });
                this.data.alerts = [];
                // after the alerts, fall through to the report when it would have shown anyway
                const due = this.pref === 'always' || (this.pref === 'daily' && get('statusReport.lastShown', '') !== this.data.date);
                if (due && this.data.totals.pending > 0) { this.markShown(); this.step = 'report'; } else { this.open = false; }
            } finally { this.busy = false; }
        },
    };
}
