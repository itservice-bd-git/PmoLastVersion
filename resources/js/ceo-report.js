// "โครงการ / ตู้" in ตัวเลือกรายงาน (no Alpine on this page): an SO box is the whole project and ticks its cabinets;
// unticking one of them leaves the others as single cabinets; counts x/y; search over SO, MO, names, customers
// Its own Vite entry: this print page does not load app.js (no Alpine).
const picker = document.querySelector('[data-cab-picker]');
if (picker) {
    const groups = [...picker.querySelectorAll('[data-cab-group]')];
    const search = picker.querySelector('[data-cab-search]');
    const none = picker.querySelector('[data-cab-none]');
    const sync = (g) => {
        const boxes = [...g.querySelectorAll('[data-cab]')], on = boxes.filter((b) => b.checked).length, all = g.querySelector('[data-cab-all]');
        g.querySelector('[data-cab-count]').textContent = on + '/' + boxes.length;
        all.checked = boxes.length > 0 && on === boxes.length;
        all.indeterminate = on > 0 && on < boxes.length;
    };
    const show = () => {
        const q = search.value.trim().toLowerCase();
        let any = false;
        groups.forEach((g) => {
            const soHit = ! q || g.dataset.text.includes(q);
            let hits = 0;
            g.querySelectorAll('[data-cab-row]').forEach((row) => {
                const hit = soHit || row.dataset.text.includes(q);
                row.hidden = ! hit;
                hits += hit ? 1 : 0;
            });
            g.hidden = ! soHit && ! hits;
            if (q && ! soHit && hits) g.open = true;
            any = any || ! g.hidden;
        });
        none.hidden = any;
    };
    groups.forEach((g) => {
        const all = g.querySelector('[data-cab-all]');
        all.addEventListener('click', (e) => e.stopPropagation());   // ticking it does not fold the group
        all.addEventListener('change', () => { g.querySelectorAll('[data-cab]').forEach((b) => { b.checked = all.checked; }); sync(g); });
        g.querySelectorAll('[data-cab]').forEach((b) => b.addEventListener('change', () => sync(g)));
        sync(g);
    });
    search.addEventListener('input', show);
    search.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });   // Enter searches, it does not send the form
    show();
}
