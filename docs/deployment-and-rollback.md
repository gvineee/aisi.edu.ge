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
