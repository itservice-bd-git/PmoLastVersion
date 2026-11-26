// Settings > สิทธิ์การใช้งาน (settings/permissions): one state for both views - "ตามแผนก" (one department's switches) and
// "ตารางรวม" (every department side by side). The table's checkboxes are the ones the form sends (perm[column][key]); the
// department view only changes the same state. Edits are counted, marked, and kept from being lost by leaving the page.
export default function permissionTable({ table, columns, implied, needs, labels }) {
    const copy = (t) => JSON.parse(JSON.stringify(t));
    const remembered = (key, ok) => {
        try {
            const v = localStorage.getItem(key);
            return v !== null && ok(v) ? v : null;
        } catch {
            return null;
        }
    };
    const remember = (key, v) => {
        try { localStorage.setItem(key, v); } catch { /* private window: the choice is not kept */ }
    };

    return {
        v: copy(table),
        v0: copy(table),
        view: remembered('pmo.permissions.view', (v) => v === 'dept' || v === 'table') || 'dept',
        col: remembered('pmo.permissions.col', (v) => columns.some((c) => c.key === v)) || (columns[0] && columns[0].key),
        copyFrom: '',
        saving: false,

        init() {
            this.$watch('view', (v) => remember('pmo.permissions.view', v));
            this.$watch('col', (v) => { remember('pmo.permissions.col', v); this.copyFrom = ''; });
            window.addEventListener('beforeunload', (e) => {
                if (this.saving || !this.dirty()) return;
                e.preventDefault();
                e.returnValue = '';
            });
        },

        // the box itself, or switched on by another one (edit.manage brings edit.work)
        has(c, k) { return !!(this.v[c][k] || (implied[k] && this.v[c][implied[k]])); },
        via(c, k) { return !this.v[c][k] && implied[k] && this.v[c][implied[k]] ? labels[implied[k]] : ''; },
        // on, but useless without another one that is off (จ่ายงาน without จัดการโครงการ)
        lacks(c, k) { return needs[k] && this.v[c][k] && !this.has(c, needs[k]) ? labels[needs[k]] : ''; },
        set(c, k, on) { this.v[c][k] = on; },

        changed(c, k) { return this.v[c][k] !== this.v0[c][k]; },
        dirtyIn(c) { return Object.keys(this.v[c]).filter((k) => this.changed(c, k)).length; },
        dirty() { return Object.keys(this.v).reduce((n, c) => n + this.dirtyIn(c), 0); },
        onIn(c) { return Object.keys(this.v[c]).filter((k) => this.has(c, k)).length; },
        total() { return Object.keys(table[columns[0].key] || {}).length; },

        // a whole department at once (the table's column header)
        allOn(c) { return Object.values(this.v[c]).every(Boolean); },
        someOn(c) { return Object.values(this.v[c]).some(Boolean) && !this.allOn(c); },
        setAll(c, on) { Object.keys(this.v[c]).forEach((k) => { this.v[c][k] = on; }); },
        copyCol() {
            if (!this.copyFrom || this.copyFrom === this.col) return;
            this.v[this.col] = { ...this.v[this.copyFrom] };
            this.copyFrom = '';
        },
        undo() { this.v = copy(this.v0); },
        colName(c) { const col = columns.find((x) => x.key === c); return col ? col.name : ''; },
    };
}
