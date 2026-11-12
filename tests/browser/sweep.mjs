// Use every screen: on each page press every visible button / tab / menu (one at a time, the page fresh each time) and
// record whether something happened (DOM changed, a window opened, the URL moved, a download) and any JS / server error.
// Submit buttons of forms and log-out are skipped (the e2e test covers saving). Dialogs (confirm) are dismissed.
// On a COPY of the database (it presses everything, some presses save). Usage, as e2e.mjs (users.php up on the copy, serve it):
//   BASE=http://127.0.0.1:8001 OUT=sweep.json PLAYWRIGHT=<playwright index.js> node tests/browser/sweep.mjs
// To tell new problems from old ones, run it also on an older commit (a git worktree served on another port, same fresh copy).
const pw = (await import(process.env.PLAYWRIGHT || 'playwright')).default;
const { chromium, devices } = pw;
const base = process.env.BASE, out = process.env.OUT;
const results = [];
const SEL = 'button, [role=tab], summary, [\\@click]:not(button), [x-on\\:click]:not(button), a[href="#"], a[href^="javascript"]';

async function settle(p) {
    await p.waitForLoadState('domcontentloaded');
    await p.waitForFunction(() => !!window.Alpine, null, { timeout: 8000 }).catch(() => {});
    await p.waitForTimeout(350);
}

async function sweep(b, who, email, opts, pages) {
    const c = await b.newContext({ ...opts, acceptDownloads: true });
    const p = await c.newPage();
    let errs = [];
    p.on('pageerror', (e) => errs.push('pageerror: ' + e.message.split('\n')[0]));
    p.on('console', (m) => { if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) errs.push('console: ' + m.text().split('\n')[0]); });
    p.on('response', (r) => { if (r.status() >= 500) errs.push(`HTTP ${r.status()} ${r.request().method()} ${new URL(r.url()).pathname}`); });
    p.on('dialog', (d) => d.dismiss());
    await p.goto(base + '/login'); await p.fill('input[name=email]', email); await p.fill('input[name=password]', 'probe-pass-1');
    await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);

    for (const path of pages) {
        errs = [];
        const r = await p.goto(base + path); await settle(p);
        results.push({ who, page: path, label: '(open page)', reacted: r.ok(), errors: [...errs], status: r.status() });
        // the controls to press: visible, not a form's submit, not log-out; the same control repeated on many rows twice at most
        const list = await p.evaluate((sel) => {
            const seen = {}; const out = [];
            [...document.querySelectorAll(sel)].forEach((el, i) => {
                if (!el.offsetParent && getComputedStyle(el).position !== 'fixed') return;
                const r = el.getBoundingClientRect(); if (r.width < 2 || r.height < 2) return;
                if (el.closest('form') && (el.type === 'submit' || (el.tagName === 'BUTTON' && !el.getAttribute('type')))) return;
                const text = (el.getAttribute('aria-label') || el.textContent || el.title || '').trim().replace(/\s+/g, ' ').slice(0, 50);
                if (/ออกจากระบบ|log ?out/i.test(text) || el.closest('form[action$="/logout"]')) return;
                const h = (el.getAttribute('@click') || el.getAttribute('x-on:click') || '').replace(/\(.*$/s, '');
                const key = el.tagName + '|' + text.replace(/\d+/g, '#') + '|' + h;
                seen[key] = (seen[key] || 0) + 1; if (seen[key] > 2) return;
                out.push({ i, text: text || '(' + (h || el.tagName.toLowerCase()) + ')' });
            });
            return out;
        }, SEL);
        for (const item of list.slice(0, 70)) {
            errs = [];
            if (p.url() !== base + path) { await p.goto(base + path); await settle(p); }
            else { await p.reload(); await settle(p); }
            const before = p.url();
            const ok = await p.evaluate(({ sel, i }) => {
                const el = document.querySelectorAll(sel)[i]; if (!el) return false;
                window.__mut = 0; new MutationObserver((m) => { window.__mut += m.length; }).observe(document.body, { subtree: true, childList: true, attributes: true, characterData: true });
                el.setAttribute('data-sweep', '1'); return true;
            }, { sel: SEL, i: item.i });
            if (!ok) { results.push({ who, page: path, label: item.text, reacted: null, note: 'gone after reload', errors: [] }); continue; }
            let popup = false, download = false;
            const pop = c.waitForEvent('page', { timeout: 1200 }).then((q) => { popup = true; return q.close(); }).catch(() => {});
            const dl = p.waitForEvent('download', { timeout: 1200 }).then(() => { download = true; }).catch(() => {});
            let clickErr = '';
            await p.locator('[data-sweep="1"]').click({ timeout: 2500 }).catch((e) => { clickErr = e.message.split('\n')[0]; });
            await p.waitForTimeout(500);
            await Promise.all([pop, dl]);
            const moved = p.url() !== before;
            const mut = moved ? 1 : await p.evaluate(() => window.__mut || 0).catch(() => 1);
            results.push({ who, page: path, label: item.text, reacted: !!(mut || moved || popup || download), how: moved ? 'navigated' : popup ? 'new tab' : download ? 'download' : mut ? 'changed' : 'nothing', clickErr, errors: [...errs] });
        }
    }
    await c.close();
}

const b = await chromium.launch();
const adminPages = ['/dashboard', '/projects', '/projects/create', '/projects/9', '/projects/9?tab=comment', '/projects/9/edit', '/cabinets', '/cabinets/31',
    '/cabinet-templates', '/cabinet-templates/1', '/planning/board', '/deliveries', '/deliveries?tab=waiting', '/deliveries?tab=scheduled', '/deliveries?tab=delivered',
    '/assignments', '/reports/today', '/reports/production', '/reports/ceo', '/notifications', '/trash', '/settings', '/settings/notice-rules', '/settings/notifications'];
const shipPages = ['/deliveries', '/deliveries?tab=waiting', '/deliveries?tab=scheduled', '/deliveries?tab=departed', '/deliveries?tab=delivered', '/planning/board', '/notifications', '/cabinets/31'];
await sweep(b, 'admin-desktop', 'probe-adm@example.test', { viewport: { width: 1440, height: 1000 } }, adminPages);
await sweep(b, 'ship-phone', 'probe-ship@example.test', { ...devices['iPhone 13'] }, shipPages);
await b.close();
(await import('fs')).writeFileSync(out, JSON.stringify(results, null, 1));
const n = results.length, dead = results.filter((r) => r.reacted === false).length, bad = results.filter((r) => r.errors.length).length;
console.log(`${n} presses, ${dead} no reaction, ${bad} with errors -> ${out}`);
