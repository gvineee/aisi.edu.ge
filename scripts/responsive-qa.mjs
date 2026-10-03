// Headless responsive QA over real Laravel routes via the Chrome DevTools Protocol.
// Usage: node scripts/responsive-qa.mjs <out-dir>  (QA_BASE, CHROME_PATH env optional; logs in as local seed users *@aisi.test).
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const OUT = process.argv[2];
const BASE = process.env.QA_BASE ?? 'http://127.0.0.1:8000';
const CHROME = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9333;
mkdirSync(join(OUT, 'shots'), { recursive: true });

const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${join(OUT, 'profile')}`, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let version;
for (let i = 0; i < 40 && !version; i++) {
    try { version = await (await fetch(`http://127.0.0.1:${PORT}/json/version`)).json(); } catch { await sleep(250); }
}
const ws = new WebSocket(version.webSocketDebuggerUrl);
await new Promise((r) => ws.addEventListener('open', r));
let id = 0;
const pending = new Map();
const listeners = [];
ws.addEventListener('message', (e) => {
    const msg = JSON.parse(e.data);
    if (msg.id && pending.has(msg.id)) { pending.get(msg.id)(msg); pending.delete(msg.id); }
    else listeners.forEach((l) => l(msg));
});
const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const mid = ++id;
    pending.set(mid, (m) => (m.error ? reject(new Error(method + ': ' + m.error.message)) : resolve(m.result)));
    ws.send(JSON.stringify({ id: mid, method, params, sessionId }));
});

const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
const { sessionId: s } = await send('Target.attachToTarget', { targetId, flatten: true });
await send('Page.enable', {}, s);
await send('Runtime.enable', {}, s);
const consoleErrors = [];
listeners.push((m) => {
    if (m.sessionId !== s) return;
    if (m.method === 'Runtime.exceptionThrown') consoleErrors.push(m.params.exceptionDetails.exception?.description?.slice(0, 200) ?? 'exception');
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') consoleErrors.push(String(m.params.args?.[0]?.value ?? '').slice(0, 200));
});
const evaluate = async (expression) => (await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true }, s)).result.value;

async function go(path) {
    const loaded = new Promise((r) => { const l = (m) => { if (m.sessionId === s && m.method === 'Page.loadEventFired') { listeners.splice(listeners.indexOf(l), 1); r(); } }; listeners.push(l); });
    await send('Page.navigate', { url: BASE + path }, s);
    await Promise.race([loaded, sleep(15000)]);
    await sleep(1200);
}

async function login(email) {
    await send('Network.clearBrowserCookies', {}, s).catch(() => {});
    await send('Network.enable', {}, s);
    await send('Network.clearBrowserCookies', {}, s);
    await go('/login');
    return evaluate(`(async () => {
        const token = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        const r = await fetch('/login', { method: 'POST', credentials: 'same-origin', redirect: 'manual',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, 'Accept': 'text/html' },
            body: JSON.stringify({ email: '${email}', password: 'password' }) });
        return r.type + ' ' + r.status;
    })()`);
}

const CHECK = `(() => {
    const de = document.documentElement, cw = de.clientWidth;
    const off = [];
    if (de.scrollWidth > cw + 1) document.querySelectorAll('body *').forEach((el) => {
        const r = el.getBoundingClientRect();
        if (r.right > cw + 1 && r.width > 0 && getComputedStyle(el).position !== 'fixed') {
            let scrollParent = el.parentElement, isolated = false;
            while (scrollParent && scrollParent !== document.body) { const o = getComputedStyle(scrollParent).overflowX; if (o === 'auto' || o === 'scroll' || o === 'hidden') { isolated = true; break; } scrollParent = scrollParent.parentElement; }
            if (!isolated) off.push(el.tagName.toLowerCase() + '.' + String(el.className?.baseVal ?? el.className).split(' ').slice(0, 3).join('.') + ' →' + Math.round(r.right));
        }
    });
    const small = [];
    if (cw < 768) document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [role=button]').forEach((el) => {
        const r = el.getBoundingClientRect();
        if (r.width > 0 && r.height > 0 && (r.height < 24 || r.width < 24) && getComputedStyle(el).visibility !== 'hidden') small.push((el.innerText || el.getAttribute('aria-label') || el.tagName).trim().slice(0, 30) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height));
    });
    const unlabeled = [...document.querySelectorAll('input:not([type=hidden]):not([type=submit]), select, textarea')].filter((el) => !el.labels?.length && !el.getAttribute('aria-label') && !el.getAttribute('aria-labelledby')).map((el) => el.name || el.id || el.type);
    return { title: document.title.slice(0, 40), url: location.pathname, sw: de.scrollWidth, cw, h1: document.querySelectorAll('h1').length,
        overflow: [...new Set(off)].slice(-4), smallTargets: small.slice(0, 5), unlabeled: unlabeled.slice(0, 5),
        serverError: /Server Error|Whoops|Internal Server Error|500 \\|/.test(document.body.innerText) };
})()`;

const WIDTHS = [360, 390, 768, 1440];
const SETS = [
    { who: null, pages: ['/', '/about', '/official-documents', '/admissions', '/school-life', '/news', '/teachers', '/library', '/contact', '/login'] },
    { who: 'parent@aisi.test', pages: ['/dashboard', '/portal/today', '/portal/messages', '/portal/consents', '/portal/appointments', '/portal/pickup'] },
    { who: 'teacher@aisi.test', pages: ['/dashboard', '/portal/teacher', '/portal/my-coverage', '/portal/messages', '/portal/gradebook', '/portal/assignments', '/portal/report-cards'] },
    { who: 'student@aisi.test', pages: ['/dashboard', '/portal/my-assignments', '/portal/messages'] },
];
const results = [];
for (const set of SETS) {
    if (set.who) results.push({ login: set.who, result: await login(set.who) });
    for (const width of WIDTHS) {
        await send('Emulation.setDeviceMetricsOverride', { width, height: 820, deviceScaleFactor: 1, mobile: width < 768 }, s);
        for (const path of set.pages) {
            consoleErrors.length = 0;
            await go(path);
            const r = await evaluate(CHECK);
            r.width = width; r.as = set.who ?? 'guest'; r.requested = path; r.consoleErrors = [...consoleErrors].slice(0, 3);
            results.push(r);
            if (width === 360 || width === 1440) {
                const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }, s);
                writeFileSync(join(OUT, 'shots', `${(set.who ?? 'guest').split('@')[0]}${path.replace(/\//g, '_') || '_home'}-${width}.png`), Buffer.from(shot.data, 'base64'));
            }
        }
    }
}
writeFileSync(join(OUT, 'results.json'), JSON.stringify(results, null, 1));
ws.close();
chrome.kill();
const problems = results.filter((r) => r.overflow?.length || r.serverError || r.consoleErrors?.length || r.unlabeled?.length || r.smallTargets?.length);
console.log(`checked ${results.filter((r) => r.width).length} page×width; problems ${problems.length}`);
for (const r of results.filter((r) => r.login)) console.log('login', r.login, r.result);
for (const p of problems) console.log(JSON.stringify({ w: p.width, as: p.as, req: p.requested, at: p.url, overflow: p.overflow, small: p.smallTargets, unlabeled: p.unlabeled, err: p.serverError, console: p.consoleErrors }));
