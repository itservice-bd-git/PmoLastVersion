// x-activity-tree: the Activity panel (a project's / cabinet's history as a tree, with search and CSV export).
// Its x-data is "activityTree(url, exportUrl)" - registered with Alpine from app.js.
import Alpine from 'alpinejs';

function activityTree(url, exportUrl) {
    const svg = (d) => '<svg class="w-4 h-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' + d + '</svg>';
    const ICONS = {
        cabinet: svg('<rect x="4" y="3" width="12" height="14" rx="1.5"/><path d="M10 3v14M7.5 10h.01M12.5 10h.01"/>'),
        task: svg('<rect x="4" y="3" width="12" height="14" rx="1.5"/><path d="M7 7h6M7 10h6M7 13h3"/>'),
        subtask: svg('<path d="M5 4v6a2 2 0 002 2h8M12 9l3 3-3 3"/>'),
        checklist: svg('<circle cx="10" cy="10" r="6.5"/><path d="M7.5 10l2 2 3-4"/>'),
        project: svg('<path d="M3 6a1.5 1.5 0 011.5-1.5H8l1.5 2h6A1.5 1.5 0 0117 8v6.5a1.5 1.5 0 01-1.5 1.5h-11A1.5 1.5 0 013 14.5V6z"/>'),
        other: svg('<circle cx="10" cy="10" r="6.5"/><path d="M10 6.5v4M10 13.5h.01"/>'),
        plus: svg('<path d="M10 5v10M5 10h10"/>'),
        edit: svg('<path d="M12.5 4.5l3 3L8 15l-3.5.5L5 12l7.5-7.5z"/>'),
        check: svg('<path d="M4.5 10.5l3.5 3.5 7.5-8"/>'),
        circle: svg('<circle cx="10" cy="10" r="5.5"/>'),
        arrow: svg('<path d="M4 10h11M11 6l4 4-4 4"/>'),
        play: svg('<path d="M7 5l8 5-8 5V5z"/>'),
        copy: svg('<rect x="7" y="7" width="9" height="9" rx="1.5"/><path d="M13 7V5.5A1.5 1.5 0 0011.5 4h-6A1.5 1.5 0 004 5.5v6A1.5 1.5 0 005.5 13H7"/>'),
        clip: svg('<path d="M15 9.5l-5 5a3 3 0 01-4.2-4.2l5.5-5.5a2 2 0 112.8 2.8l-5.5 5.5a1 1 0 01-1.4-1.4L12 7"/>'),
        trash: svg('<path d="M5 6h10M8 6V4.5h4V6M6.5 6l.5 9.5h6l.5-9.5"/>'),
        dot: svg('<circle cx="10" cy="10" r="1.5"/>'),
    };
    const WORDS = { cabinet: 'Cabinet', task: 'Task', subtask: 'Sub Task', checklist: 'Checklist' };
    const iso = (d) => d.toISOString().slice(0, 10);

    return {
        url, exportUrl, nodes: [], users: [], total: 0, open: {}, loading: true, error: '', range: 'all',
        f: { from: '', to: '', category: '', entity: '', user_id: '', q: '', sort: 'desc' },
        seq: 0,

        init() { this.load(); },

        hasFilter() { return Object.values(this.f).some((v, i) => v && Object.keys(this.f)[i] !== 'sort'); },

        applyRange() {
            const today = new Date();
            if (this.range === 'all') { this.f.from = ''; this.f.to = ''; }
            else if (this.range === 'today') { this.f.from = this.f.to = iso(today); }
            else if (this.range !== 'custom') {
                this.f.from = iso(new Date(today.getTime() - (Number(this.range) - 1) * 864e5));
                this.f.to = iso(today);
            }
            if (this.range !== 'custom') this.load();
        },

        params() {
            const params = new URLSearchParams();
            Object.entries(this.f).forEach(([k, v]) => v && params.set(k, v));
            return params;
        },

        // What's on screen is what's exported: same filters, flattened to rows.
        exportCsv() { window.location = this.exportUrl + '?' + this.params(); },

        async load() {
            const mine = ++this.seq;
            const params = this.params();
            this.loading = true; this.error = '';
            try {
                const res = await fetch(this.url + '?' + params, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (!res.ok) throw new Error();
                const data = await res.json();
                if (mine !== this.seq) return; // a newer filter change already won
                this.nodes = data.nodes; this.total = data.total;
                if (this.users.length === 0) this.users = data.users;
                // A search shows its ancestor path: open every node that survived it.
                if (this.f.q) this.setAll(true);
            } catch (e) {
                this.error = 'โหลดประวัติการเปลี่ยนแปลงไม่สำเร็จ';
            } finally {
                if (mine === this.seq) this.loading = false;
            }
        },

        toggle(key) { this.open[key] = !this.open[key]; },

        setAll(value) {
            const walk = (list) => list.forEach((n) => { this.open[n.key] = value; walk(n.children); });
            walk(this.nodes);
        },
        expandAll() {
            // Rendering every activity of a big project at once is what this
            // tree exists to avoid - make that an explicit choice.
            if (this.total > 400 && !confirm('มี ' + this.total + ' กิจกรรม การขยายทั้งหมดอาจทำให้หน้าช้า ต้องการดำเนินการต่อหรือไม่?')) return;
            this.setAll(true);
        },
        collapseAll() { this.setAll(false); },

        // Flatten only what's visible: a collapsed node contributes its own row and nothing below it.
        rows() {
            const out = [];
            const walk = (list, depth) => list.forEach((n) => {
                out.push({ id: 'n-' + n.key, depth, node: n });
                if (!this.open[n.key]) return;
                walk(n.children, depth + 1);
                n.activities.forEach((a) => out.push({ id: 'a-' + n.key + '-' + a.id, depth: depth + 1, act: a }));
            });
            walk(this.nodes, 0);
            return out;
        },

        icon(kind) { return ICONS[kind] || ICONS.dot; },
        typeWord(n) { return n.word || WORDS[n.type] || ''; },
        actionColor(kind) {
            return { check: 'text-emerald-600', plus: 'text-blue-600', trash: 'text-red-500', circle: 'text-slate-400' }[kind] || 'text-slate-500';
        },
    };
}

export default function registerActivityTree() {
    Alpine.data('activityTree', activityTree);
}
