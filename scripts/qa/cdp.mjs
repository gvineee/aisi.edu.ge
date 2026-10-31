// Minimal headless-Chrome driver over the DevTools Protocol, shared by the
// QA scripts. Local synthetic accounts only (*@aisi.test, seed password).
import { spawn } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** RFC 6238 code for a base32 test secret. */
export function totp(secret, at = Date.now()) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of secret.replace(/=+$/, ''))
        bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(at / 30000)));
    const h = createHmac('sha1', key).update(counter).digest();
    const o = h[h.length - 1] & 15;
    return String((h.readUInt32BE(o) & 0x7fffffff) % 1e6).padStart(6, '0');
}

export async function launch({ base, out, port = 9334 }) {
    const chromePath =
        process.env.CHROME_PATH ??
        'C:/Program Files/Google/Chrome/Application/chrome.exe';
    mkdirSync(out, { recursive: true });
    const chrome = spawn(
        chromePath,
        [
            '--headless=new',
            `--remote-debugging-port=${port}`,
            `--user-data-dir=${resolve(out, '.profile')}`,
            '--no-first-run',
            '--disable-gpu',
            '--lang=ka',
            'about:blank',
        ],
        { stdio: 'ignore' },
    );

    let version;
    for (let i = 0; i < 160 && !version; i++) {
        try {
            version = await (
                await fetch(`http://127.0.0.1:${port}/json/version`)
            ).json();
        } catch {
            await sleep(250);
        }
    }
    const ws = new WebSocket(version.webSocketDebuggerUrl);
    await new Promise((r) => ws.addEventListener('open', r));
    let id = 0;
    const pending = new Map();
    const listeners = [];
    ws.addEventListener('message', (e) => {
        const msg = JSON.parse(e.data);
        if (msg.id && pending.has(msg.id)) {
            pending.get(msg.id)(msg);
            pending.delete(msg.id);
        } else listeners.forEach((l) => l(msg));
    });
    const raw = (method, params = {}, sessionId) =>
        new Promise((resolve, reject) => {
            const mid = ++id;
            pending.set(mid, (m) =>
                m.error
                    ? reject(new Error(method + ': ' + m.error.message))
                    : resolve(m.result),
            );
            ws.send(JSON.stringify({ id: mid, method, params, sessionId }));
        });

    const { targetId } = await raw('Target.createTarget', {
        url: 'about:blank',
    });
    const { sessionId: s } = await raw('Target.attachToTarget', {
        targetId,
        flatten: true,
    });
    const send = (method, params = {}) => raw(method, params, s);
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.enable');

    const errors = [];
    listeners.push((m) => {
        if (m.sessionId !== s) return;
        if (m.method === 'Runtime.exceptionThrown')
            errors.push(
                m.params.exceptionDetails.exception?.description?.slice(
                    0,
                    300,
                ) ?? 'exception',
            );
        if (
            m.method === 'Runtime.consoleAPICalled' &&
            m.params.type === 'error'
        )
            errors.push(
                String(
                    m.params.args?.[0]?.value ??
                        m.params.args?.[0]?.description ??
                        '',
                ).slice(0, 300),
            );
    });

    const page = {
        send,
        errors,
        async eval(expression) {
            const r = await send('Runtime.evaluate', {
                expression,
                awaitPromise: true,
                returnByValue: true,
            });
            if (r.exceptionDetails)
                throw new Error(
                    'eval: ' +
                        (r.exceptionDetails.exception?.description ??
                            r.exceptionDetails.text),
                );
            return r.result.value;
        },
        async go(path, settle = 1200) {
            const loaded = new Promise((r) => {
                const l = (m) => {
                    if (
                        m.sessionId === s &&
                        m.method === 'Page.loadEventFired'
                    ) {
                        listeners.splice(listeners.indexOf(l), 1);
                        r();
                    }
                };
                listeners.push(l);
            });
            await send('Page.navigate', { url: base + path });
            await Promise.race([loaded, sleep(15000)]);
            await sleep(settle);
        },
        async waitFor(expression, timeout = 8000) {
            const until = Date.now() + timeout;
            while (Date.now() < until) {
                if (await page.eval(`!!(${expression})`).catch(() => false))
                    return true;
                await sleep(150);
            }
            return false;
        },
        async width(w, h = 860) {
            await send('Emulation.setDeviceMetricsOverride', {
                width: w,
                height: h,
                deviceScaleFactor: 1,
                mobile: w < 768,
            });
            await sleep(300);
        },
        async shot(name, full = false) {
            const shot = await send('Page.captureScreenshot', {
                format: 'png',
                captureBeyondViewport: full,
            });
            writeFileSync(
                join(out, name + '.png'),
                Buffer.from(shot.data, 'base64'),
            );
        },
        /** Real mouse click in the middle of the first element matching the selector. */
        async click(selector) {
            const box = await page.eval(
                `(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return null; el.scrollIntoView({ block: 'center' }); const r = el.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; })()`,
            );
            if (!box) throw new Error('no element ' + selector);
            await sleep(150);
            for (const type of ['mousePressed', 'mouseReleased'])
                await send('Input.dispatchMouseEvent', {
                    type,
                    x: box.x,
                    y: box.y,
                    button: 'left',
                    clickCount: 1,
                });
            await sleep(400);
        },
        async key(key, code = key, keyCode = 0) {
            for (const type of ['keyDown', 'keyUp'])
                await send('Input.dispatchKeyEvent', {
                    type,
                    key,
                    code,
                    windowsVirtualKeyCode: keyCode,
                });
            await sleep(120);
        },
        async type(text) {
            await send('Input.insertText', { text });
        },
        async signOut() {
            await send('Network.clearBrowserCookies');
        },
        async login(email, { mfa = false } = {}) {
            await send('Network.clearBrowserCookies');
            await page.go('/login');
            const result = await page.eval(`(async () => {
                const token = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
                const post = (url, body) => fetch(url, { method: 'POST', credentials: 'same-origin', redirect: 'follow',
                    headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token(), Accept: 'text/html' }, body: JSON.stringify(body) });
                let r = await post('/login', { email: ${JSON.stringify(email)}, password: 'password' });
                if (${mfa}) r = await post('/two-factor-challenge', { code: ${JSON.stringify(mfa ? totp('JBSWY3DPEHPK3PXP') : '')} });
                return r.status + ' ' + new URL(r.url).pathname;
            })()`);
            return result;
        },
        close() {
            ws.close();
            chrome.kill();
        },
    };

    return page;
}

/** Root horizontal overflow, ignoring content inside its own scroll box. */
export const OVERFLOW = `(() => {
    const de = document.documentElement, cw = de.clientWidth, off = [];
    document.querySelectorAll('body *').forEach((el) => {
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.right <= cw + 1 || getComputedStyle(el).position === 'fixed') return;
        for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            const o = getComputedStyle(p).overflowX;
            if (o === 'auto' || o === 'scroll' || o === 'hidden' || o === 'clip') return;
        }
        off.push(el.tagName.toLowerCase() + '.' + String(el.className?.baseVal ?? el.className).split(' ').slice(0, 2).join('.') + '→' + Math.round(r.right));
    });
    return { cw, sw: de.scrollWidth, off: off.slice(0, 5) };
})()`;
