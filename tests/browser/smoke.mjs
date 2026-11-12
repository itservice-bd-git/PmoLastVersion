// A walk through the app in a real browser: what the PHP tests cannot see (Alpine / JS errors, a button calling a
// method that is gone, a page that no longer opens). Run it before shipping a refactor:
//
//   php tests/browser/users.php up
//   php artisan serve --port=8000            (another terminal)
//   node tests/browser/smoke.mjs             (BASE=http://127.0.0.1:8000 by default)
//   php tests/browser/users.php down
//
// Needs Playwright (npm i -D playwright && npx playwright install chromium), or PLAYWRIGHT=<path to its index.js>.
// It only reads: it opens pages, windows and tabs, never saves anything.
let pw;
try { pw = (await import(process.env.PLAYWRIGHT || 'playwright')).default; } catch (e) {
    console.error('Playwright is not installed: npm i -D playwright && npx playwright install chromium (or set PLAYWRIGHT=/path/to/playwright/index.js)');
    process.exit(2);
}
const { chromium, devices } = pw;
const base = process.env.BASE || 'http://127.0.0.1:8000';
const errors = [], log = [];
const comp = `(() => { const el = [...document.querySelectorAll('[x-data]')].find((e) => { try { return typeof Alpine.$data(e).calEntries === 'function'; } catch { return false; } }); return Alpine.$data(el); })()`;

async function session(b, email, opts, who, steps) {
    const c = await b.newContext(opts); const p = await c.newPage();
    p.on('pageerror', (e) => errors.push(`${who} ${p.url()} pageerror: ${e.message}`));
    p.on('console', (m) => { if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) errors.push(`${who} ${p.url()} console: ${m.text()}`); });
    p.on('response', (r) => { if (r.status() >= 500) errors.push(`${who} ${r.request().method()} ${r.url()}: HTTP ${r.status()}`); });   // a server error behind any request, not only the page's own
    p.on('dialog', (d) => d.dismiss());
    await p.goto(base + '/login'); await p.fill('input[name=email]', email); await p.fill('input[name=password]', 'probe-pass-1');
    await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
    await steps(p);
    await c.close();
}
const step = async (p, name, fn) => { try { await fn(); await p.waitForTimeout(300); log.push('ok   ' + name); } catch (e) { log.push('FAIL ' + name + ': ' + e.message.split('\n')[0]); } };
const open = (p, who, url, expect = [200]) => step(p, `${who} ${url}`, async () => {
    const r = await p.goto(base + url, { waitUntil: 'networkidle' });
    if (!expect.includes(r.status())) throw new Error('HTTP ' + r.status());
});

const b = await chromium.launch();
await session(b, 'probe-adm@example.test', { viewport: { width: 1440, height: 1000 } }, 'admin', async (p) => {
    for (const u of ['/dashboard', '/projects', '/projects/create', '/deliveries', '/deliveries?tab=all', '/notifications', '/reports/today', '/reports/production', '/reports/ceo', '/settings', '/cabinets', '/cabinet-templates', '/planning/classic'])
        await open(p, 'admin', u);
    const pid = await p.evaluate(async () => (await (await fetch('/planning/data')).json()).root.boards.pmo.tasks[0]?.id?.slice(1));
    if (pid) await open(p, 'admin', '/projects/' + pid);
    const cab = await p.evaluate(() => document.querySelector('a[href*="/cabinets/"]')?.getAttribute('href'));
    if (cab) await open(p, 'admin', new URL(cab, base).pathname);
    const tpl = await p.goto(base + '/cabinet-templates').then(() => p.evaluate(() => document.querySelector('a[href*="/cabinet-templates/"]:not([href$="create"])')?.getAttribute('href')));
    if (tpl) await open(p, 'admin', new URL(tpl, base).pathname);

    await open(p, 'admin', '/planning/board');
    for (const m of ['ไทม์ไลน์', 'แดชบอร์ด', 'FAT', 'จัดส่ง', 'เดือน'])
        await step(p, 'planning mode ' + m, () => p.getByRole('button', { name: m, exact: true }).first().click());
    await step(p, 'planning project window follows modalId (getter cur)', async () => {
        const ok = await p.evaluate(`(() => { const d = ${comp}; const ids = d.tasks.slice(0, 2).map((t) => t.id); d.openModal(ids[0]); const a = d.cur && d.cur.id === ids[0]; d.openModal(ids[1] || ids[0]); const z = d.cur && d.cur.id === (ids[1] || ids[0]); d.closeModal(); return a && z; })()`);
        if (!ok) throw new Error('cur is stale');
    });
    await step(p, 'planning checklist getters', async () => {
        const ok = await p.evaluate(`(() => { const d = ${comp}; const t = d.tasks.find((x) => x.subtasks.length); if (!t) return true; d.cl = { taskId: t.id, cabId: t.subtasks[0].id }; const ok = d.clTask && d.clCab && d.clCab.id === t.subtasks[0].id; d.cl = null; return ok && d.clTask === null; })()`);
        if (!ok) throw new Error('clTask / clCab wrong');
    });
    await step(p, 'planning left panel days', () => p.evaluate(`(() => { const d = ${comp}; d.shiftFocus(1); d.shiftFocus(-1); })()`));
    await step(p, 'planning search', async () => { await p.fill('input[placeholder^="ค้นหา"]', 'a'); await p.fill('input[placeholder^="ค้นหา"]', ''); });
    await step(p, 'planning remarks tab', () => p.evaluate(`(() => { const d = ${comp}; d.openRemarks(d.tasks[0].id); d.closeModal(); })()`));
});
await session(b, 'probe-ship@example.test', { ...devices['iPhone 13'] }, 'ship-phone', async (p) => {
    for (const u of ['/deliveries', '/deliveries?tab=delivered', '/notifications', '/planning/board']) await open(p, 'ship', u);
    for (const [tab, label] of [['waiting', 'จัดรถ / คน'], ['scheduled', 'รายการตู้'], ['scheduled', 'ข้อความส่งงาน'], ['departed', 'ส่งถึงแล้ว'], ['delivered', 'แก้ข้อมูลส่งถึง']])
        await step(p, `ship deliveries ${tab}: ${label}`, async () => {
            await p.goto(base + '/deliveries?tab=' + tab, { waitUntil: 'networkidle' });
            const btn = p.locator('#dl-rows article button', { hasText: label }).first();
            if (await btn.count()) { await btn.click(); await p.waitForTimeout(600); await p.keyboard.press('Escape'); }
        });
    await open(p, 'ship', '/planning/board');
    await step(p, 'ship calendar tabs', async () => { for (const i of [1, 2, 0]) await p.locator('[role=group][aria-label="แสดงในปฏิทิน"] button').nth(i).click(); });
    await step(p, 'ship day notes window', async () => { await p.locator('button[aria-label^="หมายเหตุของแผนก"]').first().click(); await p.keyboard.press('Escape'); });
    await step(p, 'ship รอบส่ง drawer', async () => {
        const has = await p.evaluate(`(() => { const d = ${comp}; const t = d.tasks.find((x) => x.ship); if (t) d.openShip(t); return !!t; })()`);
        if (has) { await p.waitForSelector('iframe', { timeout: 5000 }); await p.keyboard.press('Escape'); }
    });
});
await b.close();
console.log(log.join('\n'));
console.log(errors.length ? '\nERRORS\n' + errors.join('\n') : '\nno errors');
process.exit(log.some((l) => l.startsWith('FAIL')) || errors.length ? 1 : 0);
