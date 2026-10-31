// Browser regression for the 2026-10-31 acceptance audit (A01–A15, D01–D02).
// Runs real clicks/keys in headless Chrome against a local server backed by
// a throwaway SQLite database prepared with scripts/qa/acceptance-fixture.php
// (synthetic accounts only). See scripts/qa/README.md.
//
//   node scripts/qa/acceptance.mjs <out-dir> [only-prefix]
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { launch, OVERFLOW, sleep } from './cdp.mjs';

const OUT = process.argv[2] ?? 'storage/app/qa-acceptance';
const ONLY = process.argv[3] ?? '';
const BASE = process.env.QA_BASE ?? 'http://localhost:8014';
const p = await launch({ base: BASE, out: OUT });
const results = [];

async function check(id, name, fn) {
    if (ONLY && !id.startsWith(ONLY)) return;
    p.errors.length = 0;
    try {
        const detail = await fn();
        const errors = p.errors.filter((e) => !/favicon|ERR_ABORTED/.test(e));
        results.push({ id, name, ok: errors.length === 0, detail, errors });
    } catch (error) {
        results.push({
            id,
            name,
            ok: false,
            detail: String(error.message ?? error),
        });
        await p.shot(`FAIL-${id}`).catch(() => {});
    }
}
const assert = (cond, message) => {
    if (!cond) throw new Error(message);
};
const text = (selector) =>
    p.eval(
        `document.querySelector(${JSON.stringify(selector)})?.innerText ?? null`,
    );
const tagButton = (pattern, scope = 'main') =>
    p.eval(
        `(() => { const b = [...document.querySelectorAll('${scope} button, ${scope} a')].find((el) => ${pattern}.test(el.textContent)); if (!b) return false; b.setAttribute('data-qa', 'target'); return true; })()`,
    );
const dialogs = () =>
    p.eval(`document.querySelectorAll('[role=dialog]').length`);
const focusInDialog = () =>
    p.eval(`!!document.activeElement?.closest('[role=dialog]')`);
const xsrfPost = (url, body) =>
    p.eval(
        `(async () => { const t = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/)||[])[1]||''); const r = await fetch(${JSON.stringify(url)}, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': t }, body: JSON.stringify(${JSON.stringify(body)}) }); return r.status; })()`,
    );

// ---------------------------------------------------------------- public
await p.width(1440);

for (const path of [
    '/',
    '/admissions',
    '/about',
    '/contact',
    '/learning',
    '/school-life',
]) {
    await check(
        `A01${path}`,
        `visit CTA opens the dialog on ${path}`,
        async () => {
            await p.go(path);
            const found = await tagButton('/ვიზიტ|გაიცანი/');
            if (!found) return 'no visit CTA on this page';
            await p.click('[data-qa=target]');
            assert(
                await p.waitFor(
                    `document.querySelector('[role=dialog]')`,
                    4000,
                ),
                'dialog did not open',
            );
            assert((await dialogs()) === 1, 'expected exactly one dialog');
            assert(await focusInDialog(), 'focus did not move into the dialog');
            const title = await p.eval(
                `document.querySelector('[role=dialog]').getAttribute('aria-labelledby') && document.getElementById(document.querySelector('[role=dialog]').getAttribute('aria-labelledby'))?.textContent`,
            );
            assert(
                title === 'ვიზიტის დაგეგმვა',
                'dialog is not labelled: ' + title,
            );
            await p.key('Escape', 'Escape', 27);
            assert(
                await p.waitFor(
                    `!document.querySelector('[role=dialog]')`,
                    3000,
                ),
                'Escape did not close',
            );
            assert(
                await p.eval(
                    `document.activeElement?.getAttribute('data-qa') === 'target'`,
                ),
                'focus did not return to the CTA',
            );
            return 'opens, labelled, focus in/out';
        },
    );
}

await check(
    'A01-book',
    'booking the single seat: validation, save, full, double submit',
    async () => {
        await p.go('/admissions');
        await tagButton('/დაგეგმე ვიზიტი/');
        await p.click('[data-qa=target]');
        await p.waitFor(
            `document.querySelector('[role=dialog] input[name=visit_slot_id][value="1"]')`,
        );
        await p.shot('A01-dialog-open');
        // Native validation blocks an empty send; nothing is stored.
        await p.click('[role=dialog] button[type=submit]');
        assert(
            await p.eval(
                `document.querySelectorAll('[role=dialog] :invalid').length > 0`,
            ),
            'empty form was not flagged',
        );
        await p.click('[role=dialog] input[name=visit_slot_id][value="1"]');
        await p.click('#guardian_name');
        await p.type('QA მშობელი');
        await p.click('#contact_value');
        await p.type('qa-parent@example.test');
        await p.click('#consent_given');
        await p.shot('A01-dialog-filled');
        // Double click on send: one booking, the second click is ignored while processing.
        await p.click('[role=dialog] button[type=submit]');
        await p.click('[role=dialog] button[type=submit]').catch(() => {});
        assert(
            await p.waitFor(`!document.querySelector('[role=dialog]')`, 6000),
            'dialog stayed open after send',
        );
        await sleep(500);
        await p.shot('A01-booked-toast');
        const toast = await p.eval(
            `document.querySelector('[data-sonner-toast]')?.innerText ?? ''`,
        );
        const slots = await p.eval(
            `fetch('/admissions/visit-slots', { headers: { Accept: 'application/json' } }).then((r) => r.json())`,
        );
        const slot = slots.slots.find((s) => s.id === 1);
        assert(
            slot && slot.remaining === 0,
            'seat not taken: ' + JSON.stringify(slot),
        );
        // Reopen: the full slot is shown and cannot be chosen.
        await tagButton('/დაგეგმე ვიზიტი/');
        await p.click('[data-qa=target]');
        await p.waitFor(
            `document.querySelector('[role=dialog] input[name=visit_slot_id][value="1"]')`,
        );
        assert(
            await p.eval(
                `document.querySelector('[role=dialog] input[name=visit_slot_id][value="1"]').disabled`,
            ),
            'full slot still selectable',
        );
        await p.shot('A01-slot-full');
        await p.key('Escape', 'Escape', 27);
        return `toast: ${toast.slice(0, 80)}`;
    },
);

await check(
    'A01-race',
    'two families send for the last seat at once: one wins',
    async () => {
        await p.go('/admissions');
        const statuses = await p.eval(`(async () => {
        const t = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/)||[])[1]||'');
        const send = (n) => fetch('/admissions/leads', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': t },
            body: JSON.stringify({ guardian_name: 'QA ოჯახი ' + n, contact_method: 'phone', contact_value: '+995 555 00000' + n, visit_slot_id: 2, consent_given: true }) }).then((r) => r.status);
        return Promise.all([send(1), send(2)]);
    })()`);
        const slots = await p.eval(
            `fetch('/admissions/visit-slots', { headers: { Accept: 'application/json' } }).then((r) => r.json())`,
        );
        const slot = slots.slots.find((s) => s.id === 2);
        assert(
            statuses.filter((s) => s < 300 || s === 302).length === 1,
            'statuses ' + statuses,
        );
        assert(slot.remaining === 0, 'remaining ' + slot.remaining);
        return 'statuses ' + statuses.join(',');
    },
);

await check('A01-mobile', 'visit dialog on a 360px phone', async () => {
    await p.width(360, 740);
    await p.go('/admissions');
    await tagButton('/დაგეგმე ვიზიტი/');
    await p.click('[data-qa=target]');
    assert(
        await p.waitFor(`document.querySelector('[role=dialog]')`),
        'no dialog',
    );
    const box = await p.eval(
        `(() => { const r = document.querySelector('[role=dialog]').getBoundingClientRect(); return { l: r.left, r: r.right, scroll: document.querySelector('[role=dialog]').scrollHeight > document.querySelector('[role=dialog]').clientHeight }; })()`,
    );
    assert(
        box.l >= 0 && box.r <= 360,
        'dialog off screen ' + JSON.stringify(box),
    );
    await p.shot('A01-dialog-360');
    await p.key('Escape', 'Escape', 27);
    await p.width(1440);
    return JSON.stringify(box);
});

await check(
    'A02',
    'library pages: next, deep link, back/forward, search keeps q',
    async () => {
        await p.go('/library?page=3');
        assert(
            (await p.eval(`location.search`)) === '?page=3',
            'deep link reset to ' + (await p.eval('location.search')),
        );
        assert(
            await p.eval(
                `document.querySelector('nav[aria-label="კატალოგის გვერდები"] [aria-current=page]')?.textContent.trim() === '3'`,
            ),
            'page 3 not current',
        );
        const last = await p.eval(
            `[...document.querySelectorAll('main li h2')].map((h) => h.textContent)`,
        );
        assert(
            last.includes('QA წიგნი 27') || last.length > 0,
            'no items on the last page',
        );
        await p.go('/library');
        await p.eval(`window.scrollTo(0, document.body.scrollHeight)`);
        await p.shot('A02-library-pagination');
        await tagButton('/შემდეგი/');
        await p.click('[data-qa=target]');
        assert(
            await p.waitFor(`location.search === '?page=2'`),
            'next did not go to page 2',
        );
        await p.eval(`history.back()`);
        assert(
            await p.waitFor(`location.search === ''`),
            'back did not return to page 1',
        );
        await p.eval(`history.forward()`);
        assert(
            await p.waitFor(`location.search === '?page=2'`),
            'forward did not return to page 2',
        );
        await p.click('input[type=search]');
        await p.type('მათემატიკა');
        assert(
            await p.waitFor(`location.search.includes('q=')`),
            'search did not reach the URL',
        );
        const count = await p.eval(
            `document.querySelectorAll('main li h2').length`,
        );
        await p.eval(`window.scrollTo(0, document.body.scrollHeight)`);
        const nav = await p.eval(
            `document.querySelector('nav[aria-label="კატალოგის გვერდები"] a')?.getAttribute('href') ?? ''`,
        );
        assert(nav.includes('q='), 'pager drops q: ' + nav);
        const digital = await p.eval(
            `(async () => { const r = await fetch('/library?q=27', { headers: { 'X-Inertia': 'true', 'X-Requested-With': 'XMLHttpRequest' } }); return r.status; })()`,
        );
        return `search results on page: ${count}; pager link ${nav}; ${digital}`;
    },
);

await check(
    'A06',
    'header links ≥ 4.5:1 and the current page marked',
    async () => {
        await p.go('/news');
        const r = await p.eval(`(() => {
        // rgb(0–255), or color(srgb 0–1) as color-mix() is reported.
        const lum = (c) => { const scale = c.startsWith('color(') ? 1 : 255; const [r, g, b] = c.match(/[\\d.]+/g).map(Number).slice(0, 3).map((v) => { v /= scale; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
        const a = document.querySelector('.public-navigation-link');
        const color = getComputedStyle(a).color;
        const ratio = (1.05) / (lum(color) + 0.05);
        const current = document.querySelector('.public-navigation-link[aria-current=page]');
        return { color, ratio: Math.round(ratio * 100) / 100, current: current?.textContent, underline: current && getComputedStyle(current).textDecorationLine };
    })()`);
        assert(r.ratio >= 4.5, 'contrast ' + r.ratio);
        assert(
            r.current === 'ამბები' && r.underline.includes('underline'),
            'current page not marked',
        );
        await p.shot('A06-header');
        return JSON.stringify(r);
    },
);

await check(
    'A08',
    'every hash link has a target; vision link leads somewhere',
    async () => {
        const out = [];
        for (const path of [
            '/',
            '/admissions',
            '/about',
            '/learning',
            '/school-life',
            '/official-documents',
            '/contact',
        ]) {
            await p.go(path);
            const missing = await p.eval(
                `[...document.querySelectorAll('a[href^="#"]')].map((a) => a.getAttribute('href')).filter((h) => h.length > 1 && !document.getElementById(h.slice(1)))`,
            );
            assert(missing.length === 0, `${path}: missing targets ${missing}`);
            out.push(
                `${path}:${await p.eval(`[...document.querySelectorAll('main a')].find((a) => a.textContent.includes('ჩვენი ხედვა'))?.getAttribute('href') ?? '-'`)}`,
            );
        }
        return out.join(' ');
    },
);

await check(
    'D01',
    'home hero geometry at 1440 matches the reference',
    async () => {
        await p.go('/');
        const m = await p.eval(
            `(() => { const h = document.querySelector('h1'), r = h.getBoundingClientRect(), cs = getComputedStyle(h), f = document.querySelector('.public-photo-frame').getBoundingClientRect(); return { size: cs.fontSize, lh: cs.lineHeight, left: Math.round(r.left), frame: Math.round(f.width) + 'x' + Math.round(f.height) }; })()`,
        );
        assert(
            m.size === '57.6px' && m.lh === '69.12px',
            'type ' + JSON.stringify(m),
        );
        await p.shot('D01-home-1440');
        return JSON.stringify(m);
    },
);

await check('D02', 'stage cards lead to the stage description', async () => {
    await p.go('/');
    await p.eval(
        `document.querySelector('#stage-2')?.scrollIntoView({ block: 'center' })`,
    );
    await sleep(300);
    await p.shot('D02-programs-1440');
    await p.click('#stage-2 a');
    assert(
        await p.waitFor(
            `location.pathname === '/learning' && location.hash === '#stage-2'`,
        ),
        'went to ' + (await p.eval('location.href')),
    );
    return await p.eval(
        `document.getElementById('stage-2')?.innerText.split('\\n').slice(0, 3).join(' | ')`,
    );
});

for (const width of [360, 390, 768, 1024, 1440]) {
    await check(
        `D01-${width}`,
        `public pages have no horizontal overflow at ${width}`,
        async () => {
            await p.width(width);
            const bad = [];
            for (const path of [
                '/',
                '/about',
                '/learning',
                '/admissions',
                '/school-life',
                '/news',
                '/library',
                '/contact',
            ]) {
                await p.go(path, 600);
                const o = await p.eval(OVERFLOW);
                if (o.sw > o.cw + 1)
                    bad.push(`${path} ${o.sw}>${o.cw} ${o.off.join(' ')}`);
            }
            await p.go('/', 600);
            await p.shot(`D01-home-${width}`);
            assert(bad.length === 0, bad.join('; '));
            return 'ok';
        },
    );
}
await p.width(1440);

// ---------------------------------------------------------------- portal
const helpers = {
    p,
    check,
    assert,
    text,
    tagButton,
    xsrfPost,
    sleep,
    out: OUT,
};
await (await import('./acceptance-portal.mjs')).default(helpers);
await (await import('./acceptance-portal-roles.mjs')).default(helpers);

writeFileSync(join(OUT, 'results.json'), JSON.stringify(results, null, 1));
p.close();
const failed = results.filter((r) => !r.ok);
for (const r of results)
    console.log(
        `${r.ok ? 'PASS' : 'FAIL'} ${r.id} — ${r.name}${r.ok ? '' : `\n     ${r.detail ?? ''} ${(r.errors ?? []).join(' | ')}`}`,
    );
console.log(`\n${results.length - failed.length}/${results.length} passed`);
process.exit(failed.length ? 1 : 0);
