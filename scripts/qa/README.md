# Browser acceptance checks

Headless Chrome (DevTools Protocol, no extra packages) drives the real app
with real clicks and keys. Synthetic accounts only; never point it at
production or a database with real families.

## Run

```bash
# 1. A throwaway SQLite database (the fixture refuses any other).
touch storage/app/qa-fixes/qa.sqlite
export DB_CONNECTION=sqlite DB_DATABASE="$PWD/storage/app/qa-fixes/qa.sqlite" \
       SESSION_DRIVER=file QUEUE_CONNECTION=sync CACHE_STORE=file MAIL_MAILER=log
php artisan migrate:fresh --seed --force
php scripts/qa/acceptance-fixture.php

# 2. Serve it (php -S keeps the environment; `artisan serve` does not).
npm run build
(cd public && php -S 127.0.0.1:8014 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php)

# 3. In another shell:
node scripts/qa/acceptance.mjs storage/app/qa-fixes/after        # everything
node scripts/qa/acceptance.mjs storage/app/qa-fixes/after A13    # ids starting with A13
```

The run books visit slots and saves CMS edits, so start again from step 1
for a clean result. Screenshots and `results.json` land in the output folder.

`CHROME_PATH` overrides the Chrome binary, `QA_BASE` the address
(default `http://localhost:8014`; the seed maps `localhost` to the school).

## Files

- `cdp.mjs` — the minimal driver (navigate, click, type, screenshot, login with TOTP).
- `acceptance.mjs` — public site: visit dialog (A01), library pages (A02),
  contrast (A06), links (A08), design geometry and overflow (D01/D02).
- `acceptance-portal.mjs` — lost session during upload (A03).
- `acceptance-portal-roles.mjs` — signed-in roles: A04–A15.
- `acceptance-fixture.php` — synthetic roles, 27 library items, single-seat
  visit slots, a conversation and a date-only invoice.
