// The save paths in a real browser: forms sent, windows saved, checklists ticked, a round taken from จัดรถ to ตรวจแล้ว.
// Unlike smoke.mjs it WRITES, so run it only against a copy of the database, never the one you work on:
//
//   mysqldump -uroot avatar_pmo_v1 | grep -v "KEYS \*/;$" | mysql -uroot pmo_e2e     (a fresh copy; see CLAUDE.md)
//   DB_DATABASE=pmo_e2e php tests/browser/users.php up
//   cd public && DB_DATABASE=pmo_e2e php -S 127.0.0.1:8001 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
//   BASE=http://127.0.0.1:8001 node tests/browser/e2e.mjs
//   (afterwards drop pmo_e2e, and remove the files it uploaded: it prints them)
//
// Needs Playwright, as smoke.mjs (PLAYWRIGHT=<path to its index.js>).
let pw;
try { pw = (await import(process.env.PLAYWRIGHT || 'playwright')).default; } catch (e) {
    console.error('Playwright is not installed: npm i -D playwright && npx playwright install chromium (or set PLAYWRIGHT=/path/to/playwright/index.js)');
    process.exit(2);
}
if (!process.env.BASE) { console.error('Set BASE to the server running on the COPY of the database (e.g. BASE=http://127.0.0.1:8001).'); process.exit(2); }
const { chromium, devices } = pw;
const base = process.env.BASE;
const errors = [], log = [];
const stamp = String(Date.now()).slice(-6);
const comp = `(() => { const el = [...document.querySelectorAll('[x-data]')].find((e) => { try { return typeof Alpine.$data(e).calEntries === 'function'; } catch { return false; } }); return Alpine.$data(el); })()`;

async function session(b, email, opts, who, steps) {
    const c = await b.newContext({ ...opts, acceptDownloads: true }); const p = await c.newPage();
    p.on('pageerror', (e) => errors.push(`${who} ${p.url()} pageerror: ${e.message}`));
    p.on('console', (m) => { if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) errors.push(`${who} ${p.url()} console: ${m.text()}`); });
    p.on('response', (r) => { if (r.status() >= 500) errors.push(`${who} ${r.request().method()} ${r.url()}: HTTP ${r.status()}`); });
    p.on('dialog', (d) => d.accept());   // "ยืนยันการลบ…?" - yes
    await p.goto(base + '/login'); await p.fill('input[name=email]', email); await p.fill('input[name=password]', 'probe-pass-1');
    await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
    try { await steps(p, c); } catch (e) { log.push(`FAIL ${who} (stopped): ${e.message.split('\n')[0]}`); }
    await c.close();
}
async function step(p, name, fn) {
    try { await fn(); log.push('ok   ' + name); }
    catch (e) { log.push(`FAIL ${name}: ${e.message.split('\n')[0]}`); throw e; }
}
// each page settles first: a form sent while its first background fetches still run can lose the one-time flash message
const go = (p, url) => p.goto(url, { waitUntil: 'networkidle' });
const flash = async (p, text) => { await p.getByText(text).first().waitFor({ timeout: 8000 }); };
const send = (p, sel) => Promise.all([p.waitForNavigation(), p.locator(sel).evaluate((f) => f.requestSubmit())]);

const b = await chromium.launch();
const ctx = {};

// ---- PM / admin, desktop ----
await session(b, 'probe-adm@example.test', { viewport: { width: 1440, height: 1000 } }, 'admin', async (p) => {
    const no = 'E2E-' + stamp;
    await step(p, 'new project form (ProjectRequest, store)', async () => {
        await go(p, base + '/projects/create');
        await p.fill('input[name=project_no]', no);
        await p.fill('input[name=project_name]', 'ทดสอบ e2e ' + stamp);
        await p.fill('input[name=customer_name]', 'ลูกค้าทดสอบ');
        await p.fill('input[name=contract_value]', '1,250,000');
        await send(p, 'form[action$="/projects"]');
        await flash(p, 'สร้างโครงการเรียบร้อยแล้ว');
        ctx.project = p.url().match(/projects\/(\d+)/)[1];
    });
    await step(p, 'new project form refuses a used number (unique, Thai message)', async () => {
        await go(p, base + '/projects/create');
        await p.fill('input[name=project_no]', no); await p.fill('input[name=project_name]', 'x'); await p.fill('input[name=customer_name]', 'x');
        await send(p, 'form[action$="/projects"]');
        if (!/projects\/create/.test(p.url())) throw new Error('a duplicate number was saved');
    });
    await step(p, 'edit project (ProjectRequest, update; amount typed with commas)', async () => {
        await go(p, `${base}/projects/${ctx.project}/edit`);
        await p.fill('input[name=project_name]', 'ทดสอบ e2e แก้แล้ว ' + stamp);
        await send(p, `form[action$="/projects/${ctx.project}"]`);
        await flash(p, 'บันทึกการแก้ไขโครงการเรียบร้อยแล้ว');
        await p.getByText('ทดสอบ e2e แก้แล้ว ' + stamp).first().waitFor();
    });
    await step(p, 'project list search + CSV export (ProjectList)', async () => {
        await go(p, `${base}/projects?q=${no}`);
        await p.getByText('ทดสอบ e2e แก้แล้ว ' + stamp).first().waitFor();
        const csv = await p.evaluate(async (q) => { const r = await fetch('/projects/export?q=' + q); return { ok: r.ok, type: r.headers.get('content-type'), text: await r.text() }; }, no);
        if (!csv.ok || !/csv/.test(csv.type) || !csv.text.includes(no)) throw new Error('CSV did not carry the project');
    });
    await step(p, 'project comment with a file', async () => {
        await go(p, `${base}/projects/${ctx.project}?tab=comment`);
        const form = p.locator('form[action$="/notes"]:visible').first();
        await form.locator('textarea[name=note]').fill('คอมเมนต์ทดสอบ e2e ' + stamp);
        const file = form.locator('input[type=file]');
        if (await file.count()) await file.setInputFiles({ name: 'e2e.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64') });
        await Promise.all([p.waitForNavigation(), form.evaluate((f) => f.requestSubmit())]);
        await flash(p, 'บันทึกรายการเรียบร้อยแล้ว');
        await p.getByText('คอมเมนต์ทดสอบ e2e ' + stamp).first().waitFor();
    });
    await step(p, 'add a cabinet from the project page', async () => {
        await go(p, `${base}/projects/${ctx.project}`);
        await p.getByRole('button', { name: '+ เพิ่มตู้' }).first().click();
        const form = p.locator(`form[action$="/projects/${ctx.project}/cabinets"]`).first();
        await form.locator('input[name=mo_no]').fill('MO-E2E-' + stamp);
        await form.locator('input[name=cabinet_name]').fill('ตู้ทดสอบ');
        await Promise.all([p.waitForNavigation(), form.evaluate((f) => f.requestSubmit())]);
        await flash(p, 'สร้างตู้ไฟฟ้าเรียบร้อยแล้ว');   // it opens the new cabinet's page
        ctx.cabinet = (p.url().match(/cabinets\/(\d+)/) || [])[1];
        if (!ctx.cabinet) throw new Error('not on the new cabinet page: ' + p.url());
    });
    await step(p, 'cabinet page: + เพิ่ม Task without a reload (cabinet-page.js)', async () => {
        await go(p, `${base}/cabinets/${ctx.cabinet}`);
        await p.getByRole('button', { name: '+ เพิ่ม Task' }).click();
        const form = p.locator('form[data-add-inline="task"]');
        await form.locator('input[name=name]').fill('Task ทดสอบ');
        const url = p.url();
        await form.getByRole('button', { name: 'เพิ่ม', exact: true }).click();
        await p.locator('[id^="task-card-"]').filter({ hasText: 'Task ทดสอบ' }).waitFor({ timeout: 8000 });
        if (p.url() !== url) throw new Error('the page reloaded');
    });
    await step(p, 'cabinet page: + เพิ่ม Sub Task opens by itself and saves inline', async () => {
        const form = p.locator('form[data-add-inline="subtask"]:visible').first();
        await form.waitFor({ timeout: 5000 });
        await form.locator('input[name=name]').fill('Sub Task ทดสอบ');
        await form.getByRole('button', { name: 'เพิ่ม', exact: true }).click();
        const sub = p.locator('[id^="subtask-"]').filter({ hasText: 'Sub Task ทดสอบ' }).first();
        await sub.waitFor({ timeout: 8000 });
        ctx.subtask = (await sub.getAttribute('id')).replace('subtask-', '');
    });
    await step(p, 'checklist: add an item, tick it, the bars follow (checklist-progress)', async () => {
        const sub = p.locator('#subtask-' + ctx.subtask);
        await sub.evaluate((el) => { el.open = true; });
        const draft = sub.locator('input[x-ref="draft"]');
        await draft.fill('รายการทดสอบ e2e'); await draft.press('Enter');
        await sub.getByText('รายการทดสอบ e2e').waitFor({ timeout: 8000 });
        const tick = sub.locator('label, li, div').filter({ hasText: 'รายการทดสอบ e2e' }).locator('input[type=checkbox]:not([aria-label^="เลือก"])').last();
        await tick.check();
        await p.waitForFunction((id) => (document.getElementById('progress-value-subtask-' + id)?.textContent || '').trim() === '100%', ctx.subtask, { timeout: 8000 });
        await p.reload({ waitUntil: 'networkidle' });
        const after = await p.evaluate((id) => (document.getElementById('progress-value-subtask-' + id)?.textContent || '').trim(), ctx.subtask);
        if (after !== '100%') throw new Error('tick not saved: ' + after);
    });
    await step(p, 'delete the project, then restore it from the trash (can:manage-trash)', async () => {
        await go(p, `${base}/projects/${ctx.project}`);
        await Promise.all([p.waitForNavigation(), p.locator(`form[action$="/projects/${ctx.project}"]`).filter({ has: p.locator('input[name=_method][value=DELETE]') }).evaluate((f) => f.requestSubmit())]);
        await flash(p, 'ลบโครงการเรียบร้อยแล้ว');
        await go(p, base + '/trash');
        await Promise.all([p.waitForNavigation(), p.locator(`form[action$="/trash/projects/${ctx.project}/restore"]`).evaluate((f) => f.requestSubmit())]);
        const r = await go(p, `${base}/projects/${ctx.project}`);
        if (r.status() !== 200) throw new Error('restored project does not open: ' + r.status());
        await p.getByText('Sub Task ทดสอบ').first().waitFor({ timeout: 5000 }).catch(() => {});   // the cabinet tree came back with it
    });
    await step(p, 'Settings > แจ้งเตือน Checklist: add a rule (notice-rules.js)', async () => {
        await go(p, base + '/settings/notice-rules');
        await p.locator('button[\\@click="openNew()"]').click();
        const form = p.locator('form:has(#rule-msg)');   // the rule window's form (store / update)
        const dept = await form.locator('select[name=department_id] option:not([value=""])').first().getAttribute('value');
        await form.locator('select[name=department_id]').selectOption(dept);
        await form.locator('#rule-msg').fill('กฎทดสอบ e2e {รายการ}');
        if (!(await p.getByText('Breaker 100A').first().isVisible())) throw new Error('preview did not follow the message');
        await Promise.all([p.waitForNavigation(), form.evaluate((f) => f.requestSubmit())]);
        await p.getByText('กฎทดสอบ e2e').first().waitFor({ timeout: 5000 });
    });
    await step(p, 'the bell: open = seen saved (bell.js)', async () => {
        await go(p, base + '/dashboard');
        const seen = p.waitForResponse((r) => /notifications\/(seen|read-all)/.test(r.url()) && r.request().method() === 'POST', { timeout: 5000 }).catch(() => null);
        await p.evaluate(() => { const d = Alpine.$data(document.querySelector('[x-data^="pmoBell"]')); d.feed.unread = Math.max(1, d.feed.unread); });
        await p.locator('[x-data^="pmoBell"] button').first().click();
        const r = await seen;
        if (!r || !r.ok()) throw new Error('no seen POST answered ok');
    });
    await step(p, 'PM sends a delivery round from the project page (DeliveryRequests)', async () => {
        await go(p, `${base}/projects/${ctx.project}#delivery`);   // the การจัดส่ง panel, as its links open it
        await p.getByRole('button', { name: '+ นัดส่ง' }).click();
        const form = p.locator('form[\\:action="f.url"]');
        await form.waitFor();
        await form.locator('input[name="cabinet_ids[]"]').first().check();
        await form.locator('label').filter({ has: p.locator('input[name=method][value=company]') }).click();
        await form.locator('button[aria-pressed]').first().click();   // one kind of vehicle
        const day = new Date(Date.now() + 3 * 864e5).toISOString().slice(0, 10);
        await form.locator('input[name=delivery_date]').evaluate((el, d) => el._flatpickr ? el._flatpickr.setDate(d, true) : (el.value = d, el.dispatchEvent(new Event('input'))), day);   // the date picker
        await form.locator('[name=site]').fill('หน้างานทดสอบ e2e');
        await Promise.all([p.waitForNavigation(), form.locator('button[name=send][value="1"]').click()]);
        await flash(p, 'ส่งให้แผนกจัดส่งแล้ว');
    });
    await step(p, 'CEO report prints to PDF (ceo-report.js page)', async () => {
        await go(p, base + '/reports/ceo');
        await p.emulateMedia({ media: 'print' });
        const pdf = await p.pdf({ format: 'A4' });
        await p.emulateMedia({ media: 'screen' });
        if (pdf.length < 5000) throw new Error('PDF too small: ' + pdf.length);
        ctx.pdf = pdf.length;
    });
});

// ---- the delivery department, on a phone ----
const row = (p) => p.locator('[x-data^="deliveriesPage"] article').filter({ hasText: 'E2E-' + stamp }).first();
await session(b, 'probe-ship@example.test', { ...devices['iPhone 13'] }, 'ship', async (p) => {
    if (!ctx.project) throw new Error('no project from the admin part');
    await step(p, 'จัดรถ / คน: a vehicle and its driver, saved without a reload (deliveries.js)', async () => {
        await go(p, base + '/deliveries?tab=waiting');
        await row(p).getByRole('button', { name: 'จัดรถ / คน' }).click();
        const form = p.locator('form[\\:action="r.urls.assign"]');
        await form.waitFor();
        const sel = form.locator('select[name="vehicles[0][vehicle_id]"]');
        const v = await sel.locator('option:not([value=""])').first().getAttribute('value');
        await sel.selectOption(v);
        await form.locator('input[name="vehicles[0][driver_name]"]').fill('คนขับทดสอบ');
        await form.locator('button[type=submit]').click();
        await p.waitForFunction(() => !Alpine.$data(document.querySelector('[x-data^="deliveriesPage"]')).asg.open, null, { timeout: 10000 });
    });
    await step(p, 'ห่อครบ + แจ้งเปิดบิล + ออกจากโรงงาน in the cabinets window', async () => {
        await go(p, base + '/deliveries?tab=scheduled');
        await row(p).getByRole('button', { name: /^ห่อตู้/ }).click();
        await p.getByRole('button', { name: /^ห่อครบ/ }).click();
        await p.waitForFunction(() => { const c = Alpine.$data(document.querySelector('[x-data^="deliveriesPage"]')).cab; return c.saving === 0 && c.list.every((x) => x.packed); }, null, { timeout: 10000 });
        await p.locator('button[\\@click="cabAct(\'bill\')"]').click();
        await p.waitForFunction(() => Alpine.$data(document.querySelector('[x-data^="deliveriesPage"]')).cab.row?.billed, null, { timeout: 10000 });
        await p.locator('button[\\@click="cabAct(\'depart\')"]').click();
        await p.waitForFunction(() => Alpine.$data(document.querySelector('[x-data^="deliveriesPage"]')).cab.row?.status === 'departed' || !Alpine.$data(document.querySelector('[x-data^="deliveriesPage"]')).cab.open, null, { timeout: 10000 });
    });
    await step(p, 'ข้อความส่งงาน carries the driver link', async () => {
        await go(p, base + '/deliveries?tab=departed');
        await row(p).getByRole('button', { name: 'ข้อความส่งงาน' }).click();
        const text = await p.evaluate(() => Alpine.$data(document.querySelector('[x-data^="deliveriesPage"]')).brief.text);
        ctx.driver = (text.match(/https?:\/\/\S+\/drive\/\d+\?\S+/) || [])[0];   // DriverLinkController::urlFor, signed
        if (!ctx.driver) throw new Error('no driver link in the message');
    });
    await step(p, 'driver link (no login): photo + ส่งถึงแล้ว -> รอตรวจ', async () => {
        const d = await b.newContext({ ...devices['iPhone 13'] });
        const q = await d.newPage();
        q.on('pageerror', (e) => errors.push(`driver pageerror: ${e.message}`));
        q.on('response', (r) => { if (r.status() >= 500) errors.push(`driver ${r.url()}: HTTP ${r.status()}`); });
        await q.goto(ctx.driver.replace(/^https?:\/\/[^/]+/, base), { waitUntil: 'networkidle' });
        await q.locator('input[type=file][name="files[]"]').setInputFiles({ name: 'site.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64') });
        await q.locator('#issue').fill('ทดสอบ e2e: ส่งถึงแล้ว');
        await q.waitForFunction(() => !document.querySelector('form button[type=submit]').disabled, null, { timeout: 10000 });
        await Promise.all([q.waitForNavigation(), q.locator('form button[type=submit]').click()]);
        await d.close();
        await go(p, base + '/deliveries?tab=delivered');
        await row(p).getByText('รอตรวจ').first().waitFor({ timeout: 5000 });
    });
    await step(p, 'ตรวจแล้ว · แจ้ง PM', async () => {
        await row(p).getByRole('button', { name: /ตรวจแล้ว/ }).click();
        await p.locator('[x-ref=rvOk]').click();
        await p.waitForFunction(() => !Alpine.$data(document.querySelector('[x-data^="deliveriesPage"]')).rv, null, { timeout: 5000 });
        await go(p, base + '/deliveries?tab=delivered');
        if (await row(p).getByText('รอตรวจ').count()) throw new Error('still รอตรวจ');
    });
    await step(p, 'Planning หมายเหตุรายวัน: add, edit, delete (day-notes.js)', async () => {
        await go(p, base + '/planning/board');
        await p.evaluate(`(() => { const d = ${comp}; d.openDayNotes(d.focusDate); })()`);
        const box = p.locator('#dn-text'); await box.waitFor();
        await box.fill('หมายเหตุ e2e ' + stamp);
        await p.locator('form[\\@submit\\.prevent="addDayNote()"] button[type=submit]').click();
        await p.getByText('หมายเหตุ e2e ' + stamp).first().waitFor({ timeout: 5000 });
        const ok = await p.evaluate(`(async () => { const d = ${comp}; const n = d.dn.list ? d.dn.list.find((x) => x.body.includes('${stamp}')) : d.notesOn(d.dn.date).find((x) => x.body.includes('${stamp}'));
            d.editDayNote(n); d.dn.editText = 'แก้แล้ว ${stamp}'; await d.saveDayNote(n);
            const m = d.notesOn(d.dn.date).find((x) => x.body === 'แก้แล้ว ${stamp}'); if (!m) return 'edit not saved';
            await d.deleteDayNote(m); return d.notesOn(d.dn.date).some((x) => x.body.includes('${stamp}')) ? 'not deleted' : 'ok'; })()`);
        if (ok !== 'ok') throw new Error(ok);
    });
});

console.log(log.join('\n'));
console.log(errors.length ? '\nERRORS\n' + errors.join('\n') : '\nno errors');
console.log('\ncreated:', JSON.stringify(ctx));
await b.close();
process.exit(log.some((l) => l.startsWith('FAIL')) || errors.length ? 1 : 0);
