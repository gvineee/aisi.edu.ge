# გაშვება და rollback

განახლებულია: 2026-09-10. **პირველი რეალური production deploy შესრულებულია** მომხმარებლის პირდაპირი მითითებით. ქვემოთ პირველი ნაწილი აღწერს რეალურად შესრულებულს, დანარჩენი — თავდაპირველ გეგმას, რომელიც კვლავ ძალაშია მომდევნო deploy-ებისთვის.

## რეალურად შესრულებული deploy — 2026-09-10

**გარემო:** CloudPanel სერვერი `165.245.223.56`, საიტის მომხმარებელი `ais`, საიტის root `/home/aisi/htdocs/aisi.edu.ge`. PHP 8.4.22, Composer 2.10.2, Percona MySQL 8.4.10-10, Redis (PONG), **Node.js არ არის სერვერზე** — ამიტომ `npm run build` ლოკალურად სრულდება და აწყობილი `public/build` აიტვირთება პაკეტში.

**შესრულებული ნაბიჯები (ყველა რეალურად გაშვებული და გადამოწმებული):**

1. ლოკალურად `npm run build` (production assets).
2. `tar` პაკეტი `vendor/`, `node_modules/`, `.git/`, `.env`, `tests/`, `docs/`, `design/`-ის და `content-migration/`-ის მძიმე raw-scrape ნაწილის გარეშე (~1.2MB); `scp`-ით ატვირთვა.
3. CloudPanel-ის placeholder საიტის backup → `_placeholder-backup/` (rollback-ის არტეფაქტი; შენარჩუნებულია).
4. `tar -xzf` საიტის root-ში.
5. `composer install --no-dev --optimize-autoloader` (99 პაკეტი).
6. production `.env` ატვირთვა (`APP_ENV=production`, `APP_DEBUG=false`, `DB_CONNECTION=mysql`, Redis session/cache/queue); `php artisan key:generate --force`.
7. `clpctl system:permissions:reset --directories=770 --files=660 --path=.` — **აუცილებელი**, რადგან PHP-FPM მუშაობს `aisi` მომხმარებლით, ხოლო SSH/deploy — `ais`-ით; ამის გარეშე Laravel ვერ წერს `storage/`-ში.
8. `php artisan storage:link`.
9. `php artisan migrate --force` → **32 migration, ყველა წარმატებით რეალურ MySQL-ზე** (ადრე ლოკალურად MariaDB-ზე გადამოწმებული თავსებადობა დადასტურდა production-ზეც).
10. `php artisan db:seed --class=ProductionSeeder --force` → tenant „აისი", ორივე დომენი, ბრენდი, feature flags, ერთი რეალური ადმინი (`admin@aisi.edu.ge`, პაროლი გენერირდება seed-ის დროს და იბეჭდება ერთხელ), 5 რეალური გამოქვეყნებული გვერდი. **არცერთი დემო მოსწავლე/მასწავლებელი/მშობელი და არცერთი isolation-fixture tenant არ შესულა production-ში** (გადამოწმებული: `total_users=1`, `total_tenants=1`).
11. `php artisan content:import --tenant=aisi` (dry-run) → შედეგი იდენტური ლოკალურს: `create=44, blocked=1, skipped_out_of_scope=47`; შემდეგ `--commit` → 44 draft (15 გვერდი + 29 პოსტი), 45 tracking ჩანაწერი.
12. `php artisan config:cache` + `route:cache`.
13. Smoke checks (ქვემოთ).
14. deploy-არტეფაქტების წაშლა: `aisi-deploy.tar.gz`, `storage/inertia-devtools`, `storage/framework/testing`, `public/fonts-manifest.dev.json`.

**Smoke test შედეგი (origin-ზე, `--resolve aisi.edu.ge:443:127.0.0.1`):** `/`, `/about`, `/learning`, `/school-life`, `/news`, `/library`, `/contact`, `/login`, `/sitemap.xml`, `/robots.txt` → ყველა **200**; `/dashboard` → **302** login-ზე (სწორი ქცევა სტუმრისთვის). `<title>` და `<meta description>` რენდერდება server-side-ზე, `<html lang=ka>`.

### ორი რეალური, გარემოსპეციფიკური ბაგი — ნაპოვნი და გასწორებული deploy-ის დროს

1. **`view.compiled = false`.** tar-პაკეტმა გამორიცხა ცარიელი `storage/framework/{views,cache,sessions}` საქაღალდეები. Laravel-ის framework-default `config/view.php` იყენებს `realpath(storage_path('framework/views'))`-ს, რომელიც არარსებულ საქაღალდეზე აბრუნებს `false`-ს — და `config:cache`-მა ეს `false` სამუდამოდ ჩააკეთა cache-ში → ყველა გვერდი 500 („Please provide a valid cache path"). **გამოსწორება:** საქაღალდეების შექმნა და `config:clear && config:cache`. **მომავალი deploy-ისთვის:** ეს საქაღალდეები უნდა არსებობდეს *config cache-ის აწყობამდე*.
2. **`sitemap.xml` → 500 `syntax error, unexpected identifier "version"`.** სერვერზე `short_open_tag=On` (ლოკალურად `Off` — ამიტომ ტესტები მწვანე იყო). PHP `<?xml`-ს კითხულობს როგორც PHP-ის გახსნის ტეგს, Blade ამ რეგიონს დაუკომპილირებელს ტოვებს და view იშლება. **გამოსწორება:** XML-დეკლარაცია გადავიდა `SitemapController`-ში (ჩვეულებრივი PHP string, არანაირი ორაზროვნება), Blade-ში მხოლოდ `<urlset>` დარჩა. კომენტარი მიზეზით კოდშივეა.

### დარჩენილი, ჩვენს კონტროლს გარეთ მყოფი blocker

საიტი origin-ზე სრულად მუშაობს, მაგრამ **საჯაროდ ჯერ არ ჩანს: Cloudflare აბრუნებს `HTTP 526` (Invalid SSL Certificate).** მიზეზი დადასტურებულია: origin-ს აქვს მხოლოდ CloudPanel-ის **self-signed** სერტიფიკატი (`subject == issuer == CN=aisi.edu.ge`, გამოშვებული 2026-09-10 09:41), ხოლო Cloudflare-ის SSL რეჟიმი მოითხოვს ვალიდურ origin-სერტიფიკატს („Full (strict)"). Cloudflare origin-ს *აღწევს* (nginx access log სავსეა Cloudflare-ის IP-ებით).

გამოსავალი (სამივე მოითხოვს წვდომას, რომელიც deploy-მომხმარებელს არ აქვს — ამ საიტის `clpctl` შემოიფარგლება `db:export/import`, `system:permissions:reset`, `varnish-cache:purge`-ით, sudo/root არ არის):

- **A (უსწრაფესი):** Cloudflare → SSL/TLS → Overview → რეჟიმი „Full (strict)"-იდან „Full"-ზე. მაშინვე ამუშავებს; ტრაფიკი კვლავ დაშიფრულია, უბრალოდ სერტიფიკატი არ ვალიდირდება.
- **B (სწორი):** CloudPanel admin UI → საიტი `aisi.edu.ge` → SSL/TLS → ახალი Let's Encrypt სერტიფიკატი; შემდეგ „Full (strict)" რჩება. თუ ACME challenge ვერ გაივლის Cloudflare-ის proxy-ში, დროებით გამორთეთ proxy (grey cloud), გამოუშვით სერტიფიკატი, ჩართეთ ისევ.
- **C (ასევე სწორი):** Cloudflare → SSL/TLS → Origin Server → Origin Certificate-ის შექმნა და origin-ზე დაყენება CloudPanel-იდან; „Full (strict)" რჩება.

### ჯერ არ გაკეთებული production-ზე

- **queue worker არ მუშაობს** (`QUEUE_CONNECTION=redis`, მაგრამ supervisor/worker პროცესი არ არის აწყობილი). ამჟამად კრიტიკული არაფერი არ არის queue-ზე დამოკიდებული (`MAIL_MAILER=log`), მაგრამ რეალურ ელფოსტამდე ეს აუცილებელია.
- **რეალური SMTP არ არის** — `MAIL_MAILER=log`, ანუ წერილები არ იგზავნება, მხოლოდ ლოგში იწერება.
- **S3/object storage არ არის მიბმული** (`FILESYSTEM_DISK=local`); დოკუმენტების ცენტრი ამიტომ ლოკალურ private დისკზე ინახავს.
- **backup/restore drill არ ჩატარებულა** ამ სერვერზე (`clpctl db:export` ხელმისაწვდომია და ამისთვისაა).
- 301 redirect mapping ძველი URL-ებიდან **არ არის ჩართული** (`content-migration/redirect-map.csv` მხოლოდ შემოთავაზებაა). ეს ჩანს ლოგებშიც: crawler-ები კვლავ ძველ WordPress URL-ებს ითხოვენ.

## ამჟამინდელი მდგომარეობა (ლოკალური გარემო)

- ლოკალური დეველოპმენტი ერთ მანქანაზე (`docs/implementation-status.md`), PostgreSQL/SQLite; production — MySQL (იხ. `docs/decisions/0001-stack-and-versions.md`).
- Git remote: `github.com/gvineee/aisi.edu.ge`.

## გაშვებამდე საჭირო ნაბიჯები (თანმიმდევრობით)

1. **ჰოსტინგის არჩევანი და გარემოს მომზადება**: PHP 8.4+ runtime, PostgreSQL (managed ან self-hosted), Redis, S3-თავსებადი storage, Node build-ის შესაძლებლობა (Vite build-ისთვის; production runtime-ს Node არ სჭირდება, თუ SSR არ ჩაირთვება).
2. **Secrets მართვა**: production `.env` არასოდეს დაემატოს repository-ს; `APP_KEY`, DB credentials, S3 keys, payment credentials — ცალკე secrets manager-ში.
3. **Backup სტრატეგია**: PostgreSQL-ის ავტომატური backup + point-in-time recovery-ის დადასტურება ჰოსტინგის მომწოდებელთან, სანამ ნებისმიერი რეალური მონაცემი შევა ბაზაში.
4. **Migration rehearsal staging-ზე**: `php artisan migrate --force` მხოლოდ staging-ზე ჯერ, production-მდე. Destructive migration (column drop, data loss) ცალკე გეგმას საჭიროებს — **ამ პროექტში დესტრუქციული migration ჯერ არ დაწერილა.**
5. **Content/media მიგრაცია** ძველი `aisi.edu.ge`-დან: URL/redirect inventory (`docs/01`), 301 mapping, dry-run import, duplicate report — ჯერ არ დაწყებულა (docs/02 §12).
6. **Smoke test checklist** (გასაშვები ყოველი deploy-ის შემდეგ):
   - `GET /` → 200, სწორი tenant-ის ბრენდი
   - `GET /sitemap.xml`, `/robots.txt` → 200
   - migration-ის შემდეგ `php artisan test` მწვანეა staging-ზეც
   - queue worker (`php artisan queue:work`) მუშაობს და ამუშავებს test job-ს
   - ლოგირება/error monitoring იღებს ტესტურ შეცდომას

## Deploy პროცედურა (გეგმა)

```
1. backup (DB + storage)
2. კოდის deploy (backward-compatible migration-ებით)
3. php artisan migrate --force
4. worker restart (queue), cache clear/warm
5. smoke checks (ზემოთ)
6. თუ SSR ჩაირთვება მომავალში: SSR პროცესის restart + health check
```

## Rollback პროცედურა (გეგმა)

- კოდის rollback წინა release-ზე **საკმარისი არ არის**, თუ migration-მა შეცვალა schema — საჭიროა შესაბამისი `down()` migration ან backward-compatible მიდგომა (ახალი optional column, არა rename/drop იმავე deploy-ში).
- ფინანსური/სენსიტიური მონაცემის დაზიანების შემთხვევაში: აღდგენა backup-იდან, არა manual data fix, თუ არ არის აუდიტირებული.
- ყოველი rollback ინციდენტი დაფიქსირდეს `docs/implementation-status.md`-ში ან ცალკე incident log-ში.

## რაც ცნობილად აკლია ამ გეგმას

- კონკრეტული ჰოსტინგის მომწოდებელი და SLA რიცხვები (uptime/RPO/RTO) — `docs/02` §12-ში მითითებული რიცხვები არის **სამიზნე, არა გარანტია** არჩეულ პროვაიდერამდე.
- CI/CD pipeline-ის კონკრეტული კონფიგურაცია (GitHub Actions/სხვა) — ჯერ არ არის დაწერილი ამ repository-ში.
- DNS/MX ჩანაწერების მიგრაციის კონკრეტული ნაბიჯები — დამოკიდებულია საბოლოო hosting/დომენის გადაწყვეტილებაზე.

ეს დოკუმენტი განახლდება, როცა ეს გადაწყვეტილებები მიიღება — არ დაემატება გამოგონილი კონკრეტიკა მანამდე.

## ფონური სამუშაოების მონიტორინგი (2026-10-22)

- `/up` ამოწმებს აპლიკაციას, scheduler-ის heartbeat-ს (≤ 10 წთ) და database queue-ს (15 წუთზე ძველი დაუმუშავებელი ამოცანა არ უნდა იყოს). ჩავარდნისას აბრუნებს 500-ს; deploy smoke ამასაც ამოწმებს.
- **გასაკეთებელი (მომხმარებლის ანგარიშით):** გარე uptime-მონიტორი `https://aisi.edu.ge/up`-ზე, 5 წუთიანი ინტერვალით და ელფოსტის შეტყობინებით. ის ერთადერთია, რაც cron-ის სრულ გაჩერებას შეამჩნევს.
- `ops:check` (ყოველ საათში): ახალი failed jobs-ის ან პრობლემის შემთხვევაში წერილი მიდის აქტიურ platform operator-ებს და `OPS_ALERT_EMAIL`-ზე (არასავალდებულო, `.env`). წერილი სინქრონულად იგზავნება, queue-ს გვერდის ავლით.

## Scheduler და queue (განახლება 2026-10-03)

ახალი ფუნქციები ეყრდნობა queue-ს და scheduler-ს — production-ზე ორივე აუცილებელია, სანამ რეალურ ელფოსტას ჩავრთავთ:

- **cron:** `* * * * * cd /home/aisi/htdocs/aisi.edu.ge && php artisan schedule:run >> /dev/null 2>&1` (შეხვედრის შეხსენებები — `appointments:send-reminders`, hourly).
- **queue worker** (supervisor/CloudPanel): `php artisan queue:work --tries=3 --max-time=3600`; deploy-ის შემდეგ `php artisan queue:restart`. Queue-ზეა: ელფოსტის შეტყობინებები, კლასის განცხადებები (`DeliverMessageBroadcast`), ფაილის სკანირება, ჩანაცვლების/მოწვევის/შეხსენების წერილები.
- **failed jobs:** `php artisan queue:failed` რეგულარულად; alert-ის აწყობა ღიაა (`docs/security-asvs-checklist.md`).
- **MFA deploy-ის შემდეგ:** admin/director/accountant პორტალში შესვლისას 2FA-ს ჩართვას მოითხოვს (`ADMIN_MFA_REQUIRED`).

## Deploy — 2026-10-14 (`4fdd202..ddc18e3`)

- Backup: `/home/aisi/htdocs/_backups/` — `db-20261014.sql.gz` (59 ცხრილი, dump დასრულებული), `files-20261014.tar.gz` (გადაწერილი ფაილები), `robots-20261014.txt`. შენიშვნა: `clpctl db:export` მუშაობს `aisi` მომხმარებლით და `--file`-ს **აბსოლუტური** path სჭირდება.
- 18 ახალი migration (grade scales → message attachments) რეალურ MySQL-ზე წარმატებით; `composer install` (dompdf), config/route/view cache, `queue:restart`, maintenance down/up.
- Smoke (origin): საჯარო 10 გვერდი + `/login`, `/sitemap.xml`, `/robots.txt` → 200; `/dashboard`, `/platform-admin` → 302; საჯარო URL Cloudflare-ით → 200. Security headers და CSP report-only ჩანს. ლოგში ახალი შეცდომა არ არის.
- Scheduler: `ais` მომხმარებლის crontab — `* * * * * umask 002 && cd … && php artisan schedule:run`.
- Queue worker: supervisor სერვერზე არ არის → `QUEUE_SCHEDULED_WORKER=true` (`.env`), scheduler ყოველ წუთში უშვებს `queue:work --stop-when-empty --max-time=50` (`575a7fb`). failed jobs: 0.
- **ღიაა:** nginx და Laravel ორივე აგზავნის `X-Frame-Options`/`Referrer-Policy`-ს (განსხვავებული მნიშვნელობით) — გასასუფთავებელია.
- Rollback: `files-20261014.tar.gz`-ის გახსნა + `db-20261014.sql.gz`-ის `clpctl db:import`, შემდეგ cache rebuild.

## Deploy — 2026-10-14 (მე-2, `ddc18e3..08e5e0d`)

- Backup: `_backups/files-20261014-2.tar.gz`, `_backups/env-20261014` (600).
- migration `legacy_redirects`; `content:sync-redirects aisi --commit` → **30 redirect** (published import-ები; 44 გამოუქვეყნებელი/გამოტოვებული ჩანაწერი განზრახ 404-ზე რჩება) + ხელით `/online-library/ → /library`.
- შემოწმებული live: ქართული percent-encoded, `/en/…`, `/ru/…` ძველი URL-ები → 301 ახალ სიახლეზე; `/wp-login.php` → 404; `/about` → 200, `canonical` + `hreflang` (`ka`, `x-default`) `https://aisi.edu.ge/...`-ზე.
- ახალი გამოქვეყნებისას: `php artisan content:sync-redirects aisi --commit` (idempotent, manual-ს არ ცვლის); ცალკეული: `php artisan content:redirect aisi <ძველი> </ახალი>`.

## Deploy — 2026-10-18 (`08e5e0d..9fd45f9`, `bash deploy/deploy.sh`)

- შინაარსი: ხალხის ხელით მართვა, ბუღალტერია ბანკის API-ის გარეშე (`FINANCE_ONLINE_PAYMENTS=false`), შიდა დოკუმენტები; დოკუმენტის წვდომის გაცემა/გაუქმება ახლა `reauth`-ს მოითხოვს.
- Backup: `_backups/db-20261018-135449.sql.gz` (65 KB), `files-20261018-135449.tar.gz`, `env-20261018-135449` (600).
- `php artisan down` → unpack → `composer install` (ცვლილება არ ყოფილა) → 2 migration რეალურ MySQL-ზე (batch 15) → config/route/view cache → `queue:restart` → `up`.
- Smoke: საჯარო გვერდები, `/sitemap.xml`, `/robots.txt`, `/login` → 200; `/documents`, `/portal/finance`, `/portal/members` (სტუმარი) → 302. scheduler-ში ჩანს `finance:generate-fee-invoices` (03:00 UTC = 07:00 თბილისი) და `appointments:send-reminders`. failed jobs: 0; ლოგში ახალი შეცდომა არ არის. 2026-10-14 16:56-ის `legacy_redirects` შეცდომები იმ deploy-ის migration-მდე წამებიდანაა; ახლა ამას maintenance mode უშლის ხელს.
- Rollback: `php artisan down`; `files-…` გახსნა, `db-…` → `clpctl db:import`; cache rebuild; `up`.
- **ღიაა:** ფინანსები production-ready არ არის ბუღალტრის UAT-მდე; სკოლის რეკვიზიტები (`finance_settings`) ცარიელია; nginx-ის დუბლირებული `X-Frame-Options`/`Referrer-Policy`.

## Deploy — 2026-10-19 (`9fd45f9..f8544e2`, `bash deploy/deploy.sh`)

- შინაარსი: საკრედიტო ნოტები (migration `2026_10_18_000001_create_credit_notes`, batch 16); dompdf-ის საკუთარი temp დირექტორია (`storage/framework/cache/dompdf-tmp`); ინვოისის გვერდის ქართული ტექსტის გასწორება.
- Backup: `_backups/db-20261019-222636.sql.gz` (66 KB), `files-20261019-222636.tar.gz`, `env-20261019-222636` (600).
- Smoke: საჯარო გვერდები და `/sitemap.xml` → 200; `/login` → 200; `/portal/finance`, `/documents` და `/portal/finance/credit-notes/1/pdf` სტუმრისთვის → 302. scheduler-ის ორივე ამოცანა ჩანს. ლოგში ახალი შეცდომა არ არის; `storage/framework/cache` ჩაწერადია (სისტემური temp: `/tmp`).
- production-ზე PDF ჯერ არავის შეუქმნია — პირველი რეალური ინვოისის/ნოტის PDF-ის შემდეგ შესამოწმებელია `storage/framework/cache/dompdf-fonts/installed-fonts.json`.
- Rollback: `php artisan down`; `files-…` გახსნა, `db-…` → `clpctl db:import` (ან მხოლოდ სქემისთვის `php artisan migrate:rollback --step=1`); cache rebuild; `up`.

## Deploy — 2026-10-21 (`f8544e2..a44aa25`, მომხმარებელმა გაუშვა `! bash deploy/deploy.sh`)

- შინაარსი: სკოლის ვიზიტის ონლაინ დაჯავშნა; კლასის წიგნების სიები და ბიბლიოთეკის ცირკულაცია; ბიბლიოთეკარის როლი. 52 ფაილი, 2 migration (batch 17).
- Backup: `_backups/db-20261021-090103.sql.gz` (67 KB), `files-20261021-090103.tar.gz`, `env-20261021-090103` (600).
- Smoke: `/`, `/about`, `/login`, `/admissions/visit-slots` → 200 (`{"slots":[]}` — გუნდს ჯერ დრო არ დაუმატებია); `/portal/books`, `/portal/book-lists`, `/portal/school-visits`, `/portal/library-desk` სტუმრისთვის → 302. scheduler-ში ჩანს `admissions:send-visit-reminders` და `library:run-notices` (ყოველ საათში). failed jobs: 0; ლოგში ახალი შეცდომა არ არის.
- ბიბლიოთეკის ცირკულაცია (`feature:library`) გამორთულია, სანამ სკოლა არ ჩართავს; წიგნების სიები და ვიზიტის დაჯავშნა ჩართულია.
- Rollback: `php artisan down`; `files-…` გახსნა, `db-…` → `clpctl db:import` (ან მხოლოდ სქემისთვის `php artisan migrate:rollback --step=2`); cache rebuild; `up`.

## Deploy — 2026-10-21 (მე-2, `20f30f3..208c6df`, მომხმარებელმა გაუშვა `! bash deploy/deploy.sh`)

- შინაარსი: დოკუმენტების ცენტრის მე-3 ეტაპი — გაცნობის დადასტურება და შაბლონები; მასწავლებლის დღის ცენტრის 360px გასწორება. 23 ფაილი, 1 migration (batch 18).
- Backup: `_backups/db-20261021-143527.sql.gz` (68 KB), `files-20261021-143527.tar.gz`, `env-20261021-143527` (600).
- migration რეალურ MySQL-ზე წარმატებით გაეშვა. FK-ის მოკლე სახელი `doc_acks_request_fk` production-ზეც შემოწმდა (`information_schema`). ავტომატურად შექმნილი 70-სიმბოლოიანი სახელი იქ ჩავარდებოდა, MariaDB-ზე ტესტმა ეს deploy-მდე აჩვენა.
- Smoke: `/`, `/about`, `/login` → 200; `/documents`, `/documents/templates`, `/documents/my-work` სტუმრისთვის → 302. scheduler-ის 4 ამოცანა ჩანს. failed jobs: 0; ლოგში ახალი შეცდომა არ არის.
- Rollback: `php artisan down`; `files-…` გახსნა, `db-…` → `clpctl db:import` (ან მხოლოდ სქემისთვის `php artisan migrate:rollback --step=1`); cache rebuild; `up`.

## Deploy — 2026-10-22 (`6e85f21..73da5cc`, მომხმარებელმა გაუშვა `! bash deploy/deploy.sh`)

- შინაარსი:
  - დოკუმენტების ცენტრის მე-4 ეტაპი: რეცენზენტების ჯაჭვი, განხილვის ვადა და შეხსენება, შემცვლელი;
  - controller-ების toast-ები ახლა კლიენტამდე აღწევს.
  - 35 ფაილი, 1 migration (batch 19).
- Backup: `_backups/db-20261022-144559.sql.gz`, `files-20261022-144559.tar.gz`, `env-20261022-144559`.
- migration რეალურ MySQL-ზე 794 ms-ში გაეშვა. მანამდე MariaDB-ზე შემოწმდა: up/down/up და დოკუმენტების 42 ტესტი.
- Smoke: `/` და `/login` → 200; `/portal/finance` და `/documents` სტუმრისთვის → 302. scheduler-ის 5 ამოცანა ჩანს, მათ შორის ახალი `documents:send-review-reminders` (ყოველ საათში). ლოგში ახალი შეცდომა არ არის.
- ყველა სივრცე რჩება „ერთი გადაწყვეტილების“ რეჟიმში (ნაგულისხმევი), სანამ დირექცია ჯაჭვს არ დააყენებს. ქცევა deploy-მდე არსებულს ემთხვევა.
- Rollback: `php artisan down`; `files-…` გახსნა, `db-…` → `clpctl db:import` (ან მხოლოდ სქემისთვის `php artisan migrate:rollback --step=1`); cache rebuild; `up`.
- გასწორდა: deploy-ის ბოლო შეტყობინება ყოველთვის `--step=2`-ს წერდა. ახლა `deploy.sh` ითვლის, რამდენი migration გაეშვა ამ deploy-ში, და ამ რიცხვს ბეჭდავს.

## Deploy — 2026-10-23 (`73da5cc..c299a71`, მომხმარებელმა გაუშვა `! bash deploy/deploy.sh`)

- შინაარსი:
  - route-ების ინვენტარი;
  - private disk-ის `/storage/{path}` route-ები გამორთულია;
  - ფონური სამუშაოების მონიტორინგი: heartbeat, `/up`, `ops:check`.
  - 8 ფაილი, migration არ ყოფილა.
- Backup: `_backups/db-20261023-115347.sql.gz`, `files-20261023-115347.tar.gz`.
- Smoke: `/`, `/login`, `/up` → 200; `/portal/finance`, `/documents` სტუმრისთვის → 302. scheduler-ში ჩანს `ops-heartbeat` (ყოველ წუთში) და `ops:check` (ყოველ საათში). ლოგში ახალი შეცდომა არ არის.
- deploy-ის ახალმა ანგარიშმა სწორად აჩვენა: „Migrations run by this deploy: 0“.
- დარჩენილია (მომხმარებლის ანგარიშით): გარე uptime-მონიტორი `https://aisi.edu.ge/up`-ზე.

## Deploy — 2026-10-23 (მე-2, `1338556..2e9be6f`, მომხმარებელმა გაუშვა `! bash deploy/deploy.sh`)

- შინაარსი: საჯარო გვერდების ტექსტი initial HTML-ში Inertia SSR-ის გარეშე (`resources/views/partials/public-content.blade.php`, `app.blade.php`). 2 ფაილი, migration არ ყოფილა, `public/build` არ შეცვლილა.
- Backup: `_backups/db-20261023-130149.sql.gz`, `files-20261023-130149.tar.gz`.
- Smoke: `/`, `/login`, `/up` → 200; `/portal/finance`, `/documents` სტუმრისთვის → 302. scheduler-ის 7 ამოცანა ჩანს. ლოგში ახალი შეცდომა არ არის.
- შემოწმებული live:
  - `/about`-ის raw HTML-ში არის `<h1>` და ყველა `<h2>` ბლოკი;
  - `/news`-ში არის სტატიების ბმულები;
  - inline script-ს CSP nonce აქვს;
  - `/login`-ზე ბლოკი არ არის.
  - JS-იანი ბრაუზერის screenshot-ი 1280px-ზე ძველს ემთხვევა: ბლოკი არ ჩანს.
- შენიშვნა: production HTML მინიფიცირებულია და ატრიბუტებს ბრჭყალები მოხსნილი აქვს (`id=public-content`), ამიტომ `grep 'id="public-content"'` იქ 0-ს აბრუნებს.
- Rollback: `php artisan down`; `files-…` გახსნა; `view:cache`; `up`.

## Deploy — 2026-10-25 (`2e9be6f..1771421`, `bash deploy/deploy.sh`)

- შინაარსი:
  - CSP ნაგულისხმევად `enforce`-ია (`config/security.php`);
  - ამბების სათაურის ბმული 24px-მდე გაიზარდა;
  - ახალი `public/build`;
  - 4 ფაილი, migration არ ყოფილა.
- Backup: `_backups/db-20261025-113757.sql.gz`, `files-20261025-113757.tar.gz`.
- Smoke: `/`, `/login`, `/up` → 200; `/portal/finance`, `/documents` სტუმრისთვის → 302. scheduler-ის 7 ამოცანა ჩანს. ლოგში ახალი შეცდომა არ არის.
- production-ის `.env`-ში `SECURITY_CSP` არ ეწერა, ამიტომ კოდის ნაგულისხმევი მნიშვნელობა მოქმედებს. live პასუხი `Content-Security-Policy` ჰედერს აბრუნებს (აღარ არის `-Report-Only`).
- headless Chrome-ით შემოწმდა 13 საჯარო გვერდი ჩართული CSP-ით: ყველა რენდერდება, `securitypolicyviolation` 0. `/news` ახალ bundle-ს (`news-index-CojFXsG6.js`) ტვირთავს.
- Rollback მხოლოდ CSP-ისთვის: `.env`-ში `SECURITY_CSP=report-only` → `php artisan config:cache`. სრული rollback: `php artisan down`; `files-…` გახსნა; cache rebuild; `up`.

## Deploy — 2026-10-25 (მე-2, `1771421..809f9b4`, `bash deploy/deploy.sh`) + სატესტო სკოლა

- შინაარსი: ბრძანება `school:test-fixtures`. 1 ფაილი, migration არ ყოფილა.
- Backup: `_backups/db-20261025-120111.sql.gz`, `files-20261025-120111.tar.gz`.
- Smoke: `/`, `/login`, `/up` → 200; `/portal/finance`, `/documents` → 302. ლოგში ახალი შეცდომა არ არის.
- შემდეგ გაეშვა `php artisan school:test-fixtures --tenant=aisi --force` (audit: `test_fixtures.created`):
  - 40 კლასი (1ა–10დ, მიმდინარე წელი „2026“; სკოლის კლასები „1“ და „2“ ხელუხლებელია);
  - 121 მოსწავლე, 40 მასწავლებელი, 1000 გაკვეთილი;
  - 9 შესასვლელი ანგარიში `@test-aisi.invalid` დომენზე. პაროლები მხოლოდ ლოკალურ, git-იდან გამორიცხულ `test-accounts.txt`-შია.
- შემოწმდა live-ზე:
  - მშობლით, მასწავლებლითა და მოსწავლით შესვლა მუშაობს;
  - 12 პორტალის გვერდი რენდერდება ჩართული CSP-ით, დარღვევა 0;
  - ბაზაში ორმაგად დაკავებული მასწავლებელი 0;
  - teacher01-ს კვირაში 30 გაკვეთილი აქვს.
- წაშლა რეალური გაშვების წინ: `php artisan school:test-fixtures --tenant=aisi --remove --force` (მხოლოდ ამ ბრძანების შექმნილს შლის).
