// Portal half of scripts/qa/acceptance.mjs (signed-in roles, synthetic accounts).
import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    'base64',
);

export default async function portal({
    p,
    check,
    assert,
    tagButton,
    sleep,
    OVERFLOW,
    out,
}) {
    const chooseFile = async (selector, path) => {
        await p.send('DOM.enable');
        const { root } = await p.send('DOM.getDocument', { depth: -1 });
        const { nodeId } = await p.send('DOM.querySelector', {
            nodeId: root.nodeId,
            selector,
        });
        await p.send('DOM.setFileInputFiles', { nodeId, files: [path] });
    };
    const toasts = () =>
        p.eval(
            `[...document.querySelectorAll('[data-sonner-toast]')].map((t) => t.innerText).join(' | ')`,
        );

    await check(
        'A03-stale',
        'a form whose token went stale reloads with a note, never "sent"',
        async () => {
            // Browsers pass Laravel 13's Sec-Fetch-Site check, so a stale token is
            // provoked outside the browser; the browser then loads that session.
            const base = process.env.QA_BASE ?? 'http://localhost:8014';
            const first = await fetch(base + '/admissions');
            await first.text();
            const cookie = first.headers
                .getSetCookie()
                .map((c) => c.split(';')[0])
                .join('; ');
            const post = await fetch(base + '/admissions/leads', {
                method: 'POST',
                redirect: 'manual',
                headers: {
                    cookie,
                    'X-Inertia': 'true',
                    'X-Requested-With': 'XMLHttpRequest',
                    Referer: base + '/admissions',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    guardian_name: 'QA',
                    contact_method: 'phone',
                    contact_value: '+995555',
                    consent_given: true,
                }),
            });
            await post.text();
            assert(
                post.status === 409 &&
                    post.headers.get('x-inertia-location') ===
                        base + '/admissions',
                'got ' + post.status,
            );
            const session = (
                post.headers
                    .getSetCookie()
                    .find((c) => c.startsWith('aisi-session=')) ?? cookie
            )
                .split(';')[0]
                .split('=');
            await p.signOut();
            await p.send('Network.setCookie', {
                name: session[0],
                value: session.slice(1).join('='),
                url: base,
            });
            await p.go('/admissions');
            assert(
                await p.waitFor(
                    `[...document.querySelectorAll('[data-sonner-toast]')].some((t) => t.innerText.includes('თავიდან'))`,
                    5000,
                ),
                'note not shown after the reload',
            );
            await p.shot('A03-stale-public');
            return await toasts();
        },
    );

    await check(
        'A03',
        'upload after signing out elsewhere: no false success, then a real upload',
        async () => {
            const file = resolve(out, 'qa-upload.png');
            writeFileSync(file, PNG);
            await p.width(1440);
            await p.login('teacher@aisi.test');
            await p.go('/documents');
            await tagButton('/ფაილის ატვირთვა/');
            await p.click('[data-qa=target]');
            await p.click('#doc-title');
            await p.type('QA სესიის ტესტი');
            await chooseFile(
                'form[enctype="multipart/form-data"] input[type=file]',
                file,
            );
            // The same browser signs out in another tab.
            await p.eval(
                `(async () => { const t = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/)||[])[1]||''); await fetch('/logout', { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': t, Accept: 'text/html' } }); })()`,
            );
            await p.click(
                'form[enctype="multipart/form-data"] button[type=submit]',
            );
            assert(
                await p.waitFor(`location.pathname === '/login'`, 8000),
                'did not reach sign-in: ' + (await p.eval('location.pathname')),
            );
            await sleep(600);
            const page = await p.eval(`document.body.innerText`);
            const shown = await toasts();
            await p.shot('A03-session-lost');
            assert(
                !/აიტვირთა/.test(shown + page.replace(/ვერ აიტვირთა/g, '')),
                'false success shown: ' + shown,
            );
            assert(
                page.includes('არ შენახულა'),
                'no note that nothing was saved',
            );

            // Signed in again: back on the documents page, nothing stored; a real upload works.
            await p.login('teacher@aisi.test');
            await p.go('/documents');
            await tagButton('/ფაილის ატვირთვა/');
            await p.click('[data-qa=target]');
            await p.click('#doc-title');
            await p.type('QA ნამდვილი ატვირთვა');
            await chooseFile(
                'form[enctype="multipart/form-data"] input[type=file]',
                file,
            );
            await p.click(
                'form[enctype="multipart/form-data"] button[type=submit]',
            );
            assert(
                await p.waitFor(
                    `/აიტვირთა/.test([...document.querySelectorAll('[data-sonner-toast]')].map((t) => t.innerText).join())`,
                    8000,
                ),
                'no success toast for a real upload',
            );
            assert(
                await p.waitFor(
                    `document.body.innerText.includes('QA ნამდვილი ატვირთვა')`,
                    4000,
                ),
                'uploaded file not listed',
            );
            assert(
                !(await p.eval(
                    `document.body.innerText.includes('QA სესიის ტესტი')`,
                )),
                'the lost upload was stored',
            );
            await p.shot('A03-real-upload');
            return 'lost: sign-in with note; real: listed + toast';
        },
    );
}
