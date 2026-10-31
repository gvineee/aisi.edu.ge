// Role checks for A04–A15 (portal), run after acceptance-portal.mjs.
const OVERFLOW = `(() => { const de = document.documentElement; return { cw: de.clientWidth, sw: de.scrollWidth }; })()`;
const UNLABELED = `[...document.querySelectorAll('input:not([type=hidden]), select, textarea')].filter((el) => el.offsetParent !== null && !el.labels?.length && !el.getAttribute('aria-label') && !el.getAttribute('aria-labelledby')).map((el) => el.name || el.id || el.type)`;

export default async function roles({ p, check, assert, sleep }) {
    const selectAndType = async (selector, text) => {
        await p.click(selector);
        await p.eval(
            `document.querySelector(${JSON.stringify(selector)}).select()`,
        );
        await p.type(text);
    };

    await check(
        'A04',
        'conversation fits at every width, reply box reachable',
        async () => {
            await p.login('parent@aisi.test');
            const out = [];
            for (const width of [360, 390, 768, 1024, 1440]) {
                await p.width(width);
                await p.go('/portal/messages/1');
                const o = await p.eval(OVERFLOW);
                const reply = await p.eval(
                    `(() => { const t = document.querySelector('#reply-body'), b = t?.form?.querySelector('button[type=submit]'); if (!t || !b) return null; const r = b.getBoundingClientRect(); return r.width > 0 && r.right <= document.documentElement.clientWidth + 1; })()`,
                );
                assert(o.sw <= o.cw + 1, `${width}: ${o.sw}>${o.cw}`);
                assert(reply, `${width}: reply/send not visible`);
                if (width === 768 || width === 360)
                    await p.shot(`A04-messages-${width}`);
                out.push(`${width}:${o.sw}/${o.cw}`);
            }
            await p.width(1440);
            return out.join(' ');
        },
    );

    await check(
        'A09-A15',
        'day card follows the real day; a date-only due date has no hour',
        async () => {
            await p.go('/dashboard');
            const day = await p.eval(
                `document.querySelector('main')?.innerText ?? ''`,
            );
            assert(
                !day.includes('იწყება 09:00-ზე. მოემზადე'),
                'fixed 09:00 text still shown',
            );
            const due = day.match(/ვადა: [^\n·]+/)?.[0] ?? '';
            assert(
                due !== '' && !/\d\d:\d\d/.test(due),
                'due shows a time: ' + due,
            );
            await p.shot('A09-A15-parent-today', true);
            const sentence = day.match(
                /(დღეს გაკვეთილი არ არის|პირველი გაკვეთილი[^.]*|შემდეგი გაკვეთილი[^.]*|ახლა მიმდინარეობს[^.]*|დღევანდელი გაკვეთილები[^.]*|არასასწავლო დღეა[^.]*)/,
            )?.[0];
            assert(sentence, 'no day sentence');
            return `${due}; ${sentence}`;
        },
    );

    await check(
        'MENU-family',
        'family menu: no "school management" group',
        async () => {
            const groups = await p.eval(
                `[...document.querySelectorAll('nav[aria-label="პორტალის ნავიგაცია"] summary')].map((s) => s.textContent.replace('›', '').trim())`,
            );
            assert(!groups.includes('სკოლის მართვა'), 'still: ' + groups);
            return groups.join(', ');
        },
    );

    await check(
        'A14',
        'account menu with profile and security, also on a phone',
        async () => {
            for (const width of [1440, 390]) {
                await p.width(width);
                await p.go('/dashboard');
                await p.click('button[aria-label^="ანგარიშის მენიუ"]');
                assert(
                    await p.waitFor(
                        `document.querySelector('[role=menu]')`,
                        3000,
                    ),
                    `${width}: menu did not open`,
                );
                const items = await p.eval(
                    `[...document.querySelectorAll('[role=menuitem]')].map((i) => i.textContent.trim())`,
                );
                assert(
                    ['პროფილი', 'უსაფრთხოება', 'გასვლა'].every((w) =>
                        items.some((t) => t.startsWith(w)),
                    ),
                    `${width}: ${items}`,
                );
                await p.shot(`A14-account-menu-${width}`);
                await p.key('Escape', 'Escape', 27);
            }
            await p.width(1440);
            await p.go('/settings/profile');
            assert(
                await p.eval(
                    `!!document.querySelector('nav[aria-label="პორტალის ნავიგაცია"]')`,
                ),
                'profile page is not in the portal chrome',
            );
            assert(
                !(await p.eval(
                    `/Repository|Documentation|Profile settings|Save\\b/.test(document.body.innerText)`,
                )),
                'starter text on the profile page',
            );
            await p.shot('A14-profile-in-portal');
            return 'ok';
        },
    );

    await check(
        'A10',
        'student answer and teacher score/feedback have names',
        async () => {
            await p.login('teacher@aisi.test');
            await p.go('/portal/assignments');
            const teacherUnlabeled = await p.eval(UNLABELED);
            await p.login('student@aisi.test');
            await p.go('/portal/my-assignments');
            await p.eval(
                `[...document.querySelectorAll('main button')].find((b) => /გაგზავნა/.test(b.textContent))?.click()`,
            );
            await sleep(400);
            const studentUnlabeled = await p.eval(UNLABELED);
            assert(
                studentUnlabeled.length === 0,
                'student: ' + studentUnlabeled,
            );
            assert(
                teacherUnlabeled.length === 0,
                'teacher: ' + teacherUnlabeled,
            );
            return 'ok';
        },
    );

    await check(
        'A05',
        'CMS lists fit 360px and the status filter is labelled',
        async () => {
            await p.login('editor@aisi.test');
            await p.width(360);
            const out = [];
            for (const path of ['/portal/cms/pages', '/portal/cms/posts']) {
                await p.go(path);
                const o = await p.eval(OVERFLOW);
                const missing = await p.eval(UNLABELED);
                assert(o.sw <= o.cw + 1, `${path}: ${o.sw}>${o.cw}`);
                assert(missing.length === 0, `${path}: unlabeled ${missing}`);
                out.push(`${path} ${o.sw}/${o.cw}`);
            }
            await p.go('/portal/cms/pages');
            await p.shot('A05-cms-360');
            await p.width(1440);
            return out.join(' ');
        },
    );

    await check(
        'A13',
        'editor changes a heading and a link with forms; preview shows it',
        async () => {
            await p.login('editor@aisi.test');
            await p.go('/portal/cms/pages');
            const href = await p.eval(
                `[...document.querySelectorAll('main a[href$="/edit"]')].map((a) => a.getAttribute('href'))[0]`,
            );
            await p.go(href);
            assert(
                !(await p.eval(`!!document.querySelector('textarea#blocks')`)),
                'raw JSON textarea is still the editor',
            );
            const tagged = await p.eval(`(() => {
            const field = (re, qa) => { const l = [...document.querySelectorAll('section label')].find((x) => re.test(x.textContent.trim())); if (l) document.getElementById(l.htmlFor)?.setAttribute('data-qa', qa); return !!l; };
            return { heading: field(/^სათაური$/, 'heading'), link: field(/ბმული/, 'link') };
        })()`);
            assert(tagged.heading, 'no heading field');
            await selectAndType(
                '[data-qa=heading]',
                'QA ვიზუალური რედაქტირება',
            );
            if (tagged.link) {
                await selectAndType('[data-qa=link]', 'javascript:alert(1)');
                await p.click('form button[type=submit]');
                assert(
                    await p.waitFor(
                        `[...document.querySelectorAll('[role=alert]')].some((a) => a.textContent.includes('დაუშვებელია'))`,
                        6000,
                    ),
                    'unsafe link not refused',
                );
                await p.shot('A13-unsafe-link');
                await p.eval(
                    `(() => { const l = [...document.querySelectorAll('section label')].find((x) => /ბმული/.test(x.textContent.trim())); document.getElementById(l.htmlFor)?.setAttribute('data-qa', 'link'); const h = [...document.querySelectorAll('section label')].find((x) => x.textContent.trim() === 'სათაური'); document.getElementById(h.htmlFor)?.setAttribute('data-qa', 'heading'); })()`,
                );
                await selectAndType('[data-qa=link]', '/about');
                if (
                    (await p.eval(
                        `document.querySelector('[data-qa=heading]').value`,
                    )) !== 'QA ვიზუალური რედაქტირება'
                ) {
                    await selectAndType(
                        '[data-qa=heading]',
                        'QA ვიზუალური რედაქტირება',
                    );
                }
            }
            await p.click('form button[type=submit]');
            await sleep(1500);
            await p.shot('A13-block-editor', true);
            const preview = await p.eval(
                `document.querySelector('a[href$="/preview"]')?.getAttribute('href')`,
            );
            assert(preview, 'no preview link');
            await p.go(preview);
            assert(
                await p.eval(
                    `document.body.innerText.includes('QA ვიზუალური რედაქტირება')`,
                ),
                'preview does not show the edit',
            );
            await p.shot('A13-preview');
            return (
                'saved via forms; preview ok; unsafe link ' +
                (tagged.link ? 'refused' : 'n/a')
            );
        },
    );

    await check('A11', 'Georgian MFA screen and branded 403/404', async () => {
        await p.signOut();
        await p.go('/login');
        await p.eval(
            `(async () => { const t = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/)||[])[1]||''); await fetch('/login', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': t, Accept: 'text/html' }, body: JSON.stringify({ email: 'admin@aisi.test', password: 'password' }) }); })()`,
        );
        await p.go('/two-factor-challenge');
        const text = await p.eval(`document.body.innerText`);
        assert(
            !/Authentication code|Recovery code|Continue/.test(text),
            'English on MFA',
        );
        const missing = await p.eval(UNLABELED);
        assert(missing.length === 0, 'MFA input unlabeled: ' + missing);
        await p.shot('A10-A11-mfa');
        await p.login('librarian@aisi.test');
        await p.go('/portal/members');
        assert(
            await p.eval(
                `document.body.innerText.includes('წვდომა შეზღუდულია')`,
            ),
            '403 not branded',
        );
        await p.shot('A11-403');
        await p.go('/no-such-page');
        assert(
            await p.eval(
                `document.body.innerText.includes('გვერდი ვერ მოიძებნა')`,
            ),
            '404 not branded',
        );
        await p.shot('A11-404');
        return 'ok';
    });

    await check(
        'A12',
        'platform operator lands in the console without starter links',
        async () => {
            await p.login('platform@aisi.test', { mfa: true });
            await p.go('/dashboard');
            assert(
                await p.waitFor(
                    `location.pathname === '/platform-admin'`,
                    5000,
                ),
                'landed on ' + (await p.eval('location.pathname')),
            );
            const text = await p.eval(`document.body.innerText`);
            assert(
                !/Repository|Documentation|ვინაობის დადასტურება/.test(text),
                'starter links or verification offer present',
            );
            await p.shot('A12-platform-console');
            await p.go('/settings/profile');
            assert(
                await p.eval(
                    `!!document.querySelector('nav[aria-label="პლატფორმის ნავიგაცია"]')`,
                ),
                'operator profile not in the platform chrome',
            );
            await p.shot('A12-operator-profile');
            return 'ok';
        },
    );
}
