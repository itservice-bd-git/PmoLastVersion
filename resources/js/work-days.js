/**
 * "จำนวนวัน" next to a Sub Task's start / due dates: type how many working days (Mon-Fri, as WorkingDaysCalculator) and
 * the due date follows from the start - or from today when no start is picked, which then becomes the start. Typing a due
 * date by hand shows its span in days instead. Nothing is stored for it: the days are always start..due counted again.
 *
 * Markup: a box with [data-span] holding inputs [data-span-start] (optional), [data-span-due] and [data-span-days]. The
 * dates are set the way a person would (value + input + change events), so x-model, @change and plain forms all follow.
 */
const iso = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
const parse = (s) => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null; };
const weekend = (d) => d.getDay() === 0 || d.getDay() === 6;

/** The day the n-th working day falls on, counting the start itself when it is one. */
export function addWorkDays(start, n) {
    const d = parse(start);
    if (!d || !(n >= 1)) return '';
    while (weekend(d)) d.setDate(d.getDate() + 1);
    for (let left = Math.floor(n) - 1; left > 0;) {
        d.setDate(d.getDate() + 1);
        if (!weekend(d)) left--;
    }
    return iso(d);
}

/** Working days from start to due, both counted ('' when either is missing or due is before start). */
export function workDaysBetween(start, due) {
    const a = parse(start), b = parse(due);
    if (!a || !b || b < a) return '';
    let n = 0;
    for (const d = new Date(a); d <= b; d.setDate(d.getDate() + 1)) if (!weekend(d)) n++;
    return n || '';
}

function setDate(el, value) {
    if (!el || el.value === value) return;
    el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
}

const parts = (el) => {
    const box = el.closest('[data-span]');
    return box ? { start: box.querySelector('[data-span-start]'), due: box.querySelector('[data-span-due]'), days: box.querySelector('[data-span-days]') } : null;
};

let busy = false;   // our own date changes must not count as a person's

function fromDays(p) {
    const n = parseInt(p.days.value, 10);
    if (!(n >= 1)) return;
    const today = iso(new Date());
    busy = true;
    try {
        if (p.start && !p.start.value) setDate(p.start, today);
        setDate(p.due, addWorkDays(p.start ? p.start.value : today, n));
    } finally { busy = false; }
}

export function registerWorkDays() {
    window.addWorkDays = addWorkDays;
    window.workDaysBetween = workDaysBetween;

    document.addEventListener('change', (e) => {   // the days count once typed (Enter / leaving the box), not per keystroke
        if (busy || !e.target.matches) return;
        const p = parts(e.target);
        if (!p || !p.days) return;
        if (e.target.matches('[data-span-days]')) {
            fromDays(p);
        } else if (e.target.matches('[data-span-start]')) {
            if (p.days.value) fromDays(p);   // a new start moves the due date, the days stay
        } else if (e.target.matches('[data-span-due]')) {
            p.days.value = workDaysBetween(p.start ? p.start.value : iso(new Date()), p.due.value);
        }
    });
}
