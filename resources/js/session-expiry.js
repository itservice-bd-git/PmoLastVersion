/**
 * The login ran out (the page was left in the background or the computer slept past SESSION_LIFETIME): the first call
 * that comes back "not logged in" - usually the bell's poll as the tab comes back into view - reloads the page, so the
 * server sends the person to log in and, after that, back to the same page. A form sent after that is handled on the
 * server (bootstrap/app.php, TokenMismatchException).
 *
 * "Not logged in" is a 401 / 419, or a call that was redirected to the login page (a fetch without Accept: JSON).
 * The login page says why (#session-expired-note); a page that loads still logged in (a stale CSRF token only) says
 * to try again.
 */
const FLAG = 'pmo-session-expired';

// the flag holds when the page was reloaded for it: a page that comes back logged out again right away goes to the
// login page itself instead of reloading round and round
const flag = {
    set() { try { sessionStorage.setItem(FLAG, String(Date.now())); } catch (e) { /* blocked storage: no note, the reload still happens */ } },
    take() {
        try { const at = Number(sessionStorage.getItem(FLAG)) || 0; sessionStorage.removeItem(FLAG); return at; } catch (e) { return 0; }
    },
};

const path = (url) => { try { return new URL(url, location.href).pathname.replace(/\/+$/, ''); } catch (e) { return ''; } };

export default function watchSession() {
    const at = flag.take(), was = at > 0;

    const note = document.getElementById('session-expired-note');
    if (note) { note.hidden = !was; return; }   // the login page

    const login = document.querySelector('meta[name="login-url"]')?.content;
    if (!login) return;                           // only the logged-in layout watches
    if (was) queueMicrotask(() => window.showToast?.('ระบบโหลดหน้านี้ใหม่ให้แล้ว ลองทำอีกครั้ง', 'error'));

    const loginPath = path(login);
    const ownCall = (input) => {
        const url = typeof input === 'string' ? input : (input?.url ?? String(input));
        try { return new URL(url, location.href).origin === location.origin; } catch (e) { return false; }
    };
    let leaving = false;
    const send = window.fetch.bind(window);
    window.fetch = async (input, init) => {
        const res = await send(input, init);
        if (!leaving && ownCall(input)
            && (res.status === 401 || res.status === 419 || (res.redirected && path(res.url) === loginPath))) {
            leaving = true;
            flag.set();
            if (Date.now() - at < 30000) location.assign(login); else location.reload();
        }
        return res;
    };
}
