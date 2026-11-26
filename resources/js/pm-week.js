// Dashboard "สัปดาห์นี้" (dashboard/_pm, _pm-bar): the week buttons (‹ สัปดาห์นี้ ›), "ทุกโครงการ / ที่ฉันเป็น PM" and the
// 5-minute refresh fetch the page and swap in only #pm-board and #pm-bar, instead of loading the whole page again. The
// address bar follows (a link can be shared, Back / Forward step through the weeks); Alpine starts the new parts by itself.
// Anything that does not come back as this page (logged out, an error) is opened as a normal page load instead.
const PARTS = ['pm-board', 'pm-bar'];
let latest = 0;

async function load(url, push) {
    const board = document.getElementById('pm-board');
    if (!board) {
        window.location.assign(url);
        return;
    }
    const mine = ++latest;   // a later click wins over an answer still on its way
    const focused = document.activeElement?.closest?.('[data-pm-nav]')?.dataset.pmNav;
    board.setAttribute('aria-busy', 'true');
    board.style.opacity = '0.6';
    try {
        const res = await fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
        const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
        if (mine !== latest) return;
        if (!res.ok || !doc.getElementById('pm-board')) throw new Error('not the dashboard');
        PARTS.forEach((id) => {
            const now = document.getElementById(id);
            const next = doc.getElementById(id);
            if (now && next) now.replaceWith(document.importNode(next, true));
            else if (now) now.remove();
        });
        if (push) window.history.pushState({ pmWeek: true }, '', url);
        // keyboard users stay on the button they pressed
        if (focused) document.querySelector(`[data-pm-nav="${focused}"]`)?.focus();
    } catch (e) {
        if (mine === latest) window.location.assign(url);
    }
}

export default function pmWeek() {
    window.pmWeekReload = () => load(window.location.href, false);
    document.addEventListener('click', (e) => {
        const a = e.target.closest?.('a[data-pm-nav]');
        if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        load(a.href, true);
    });
    window.addEventListener('popstate', () => {
        if (document.getElementById('pm-board')) load(window.location.href, false);
    });
}
