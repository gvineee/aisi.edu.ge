# Implementation Status

განახლებულია: 2026-09-11 · ფაზა: 1 მნიშვნელოვნად დაწინაურებული (ბრენდის სინქრონიზაცია + გაფართოებული საჯარო საიტი). ეს ვერსია აერთიანებს/ასწორებს წინა ჩანაწერების წინააღმდეგობებს (`START-HERE-CLAUDE.md`-ის მოთხოვნისამებრ) რეალურ, ახლახან გაშვებულ შემოწმებებზე დაყრდნობით.

## რეალურად დამოწმებული, მუშა ფუნქციონალი

ყველა ქვემოთ ჩამოთვლილი შემოწმებულია ავტომატური ტესტებით (`php artisan test`) და რეალურ ლოკალურ სერვერზე Playwright headless ბრაუზერით (არა მხოლოდ დაწერილი კოდით).

### გარემო

- PHP 8.4.24 (Herd), Composer 2.10.2, Node 24.20.0.
- **PostgreSQL 17.11** — Windows service (`postgresql-x64-17`), ავტომატურად იწყება.
- **Redis 8.10.1** — Scoop-ის portable ბინარი; **არ არის Windows service** (admin არ იყო ხელმისაწვდომი) — სესიის დასაწყისში საჭირო: `powershell -File scripts/dev-services.ps1`.
- Laravel `laravel/react-starter-kit:dev-main`-იდან (Packagist tag ჯერ Laravel 12-ზეა): `laravel/framework v13.31.0`, `inertiajs/inertia-laravel v3.3.3`, `laravel/fortify v1.39.0`, `laravel/wayfinder v0.1.21`, React 19.2, TypeScript strict, Tailwind v4.
- Georgian UI: `APP_LOCALE=ka`, `<html lang="ka">` დამოწმებული რეალურ HTTP პასუხში.

### ბრენდი და ტიპოგრაფია — 2026-09-11-ის რებრენდინგის სინქრონიზაცია

- **BPG Nino Mtavruli Bold** დამატებულია რეალურ აპში: `resources/fonts/bpg-nino-mtavruli-bold.ttf`, `@font-face` + `--font-heading`/`--font-body` ტოკენები `resources/css/app.css`-ში, გამოყენებული `h1–h4`-სა და `[data-slot=dialog-title]`-ზე გლობალურად (`@layer base`).
  - ლიცენზია **დაუდასტურებელია** — `resources/fonts/BPG-LICENSE-NOTE.txt` და კომენტარი `app.css`-ში ზუსტად აღწერს რისკს.
  - **კრიტიკული წესი დაცულია**: არსად გამოიყენება `text-transform: uppercase`/`toUpperCase()`/`toLocaleUpperCase('ka')` ქართულ სათაურებზე. ნაპოვნი და გასწორებული ერთი რეალური დარღვევა (`home.tsx`-ის hero eyebrow-ზე `uppercase` კლასი) — ეს ზუსტად ის შეცდომაა, რომლისგანაც `START-HERE-CLAUDE.md` აფრთხილებდა.
  - **დამოწმებულია** Playwright-ით: `getComputedStyle(h1).fontFamily === '"BPG Nino Mtavruli", "Noto Sans Georgian", sans-serif'`, ფონტის ფაილი იტვირთება 0 შეცდომით (dev და production build ორივეში).
- ახალი wordmark: `resources/js/components/public/logo.tsx` — მზე+წიგნის ნიშანი (უცვლელი) + „აისი" BPG ფონტით + ერთი „სკოლა" აღწერა (ძველი ორენოვანი „სკოლა • AISI SCHOOL" მოცილებულია). სახელი ყოველთვის `brand.name`-იდან, არასოდეს hardcoded.
- ახალი სლოგანი/ტექსტები DB-ში (`TenantSeeder`): hero heading „აქ იწყება შენი ხვალ.", eyebrow „სკოლა ახალი შესაძლებლობებისთვის". პორტალის ბმული „ჩემი {tenant.name}" (production-ში სხვა სკოლისთვის სახელი ავტომატურად იცვლება).
- `/brand` (დიზაინის პრეზენტაცია) **განზრახ არ არის** გადატანილი საჯარო Laravel routes-ში — `START-HERE-CLAUDE.md`-ის მითითებით ეს რჩება მხოლოდ დიზაინის მასალად `design/app/brand/`-ში.

### Tenancy foundation (`app/Domain/Tenancy`)

- ცხრილები: `tenants`, `tenant_domains`, `tenant_memberships`, `brand_settings`, `feature_entitlements`.
- `ResolveTenant` middleware — სანდო request host-იდან (`tenant_domains.domain`); უცნობი host → 404 (არა default tenant fallback).
- `BelongsToTenant` trait — global scope + auto-stamp, დოკუმენტირებული როგორც **დამხმარე**, არა ერთადერთი დაცვა.
- `HandleInertiaRequests` აზიარებს `brand` prop-ს ყოველ გვერდზე.
- **ტესტები** (`TenantIsolationTest`, 4): უცნობი host 404; per-domain isolation; global scope; explicit `tenant_id` defense-in-depth.

### Content domain (`app/Domain/Content`) — გაფართოებული

- `pages` (draft/published, SEO fields, `page_revisions` ვერსიისთვის საფუძველი) — **CMS admin editor UI ჯერ არ არის**, მხოლოდ schema/model + public rendering.
- `posts` (ახალი — „აისის ამბები"): slug/title/excerpt/body/status/published_at.
- `PageController` — ერთი გენერული Inertia კომპონენტი (`public/page.tsx`) ყველა CMS გვერდისთვის (home ჩათვლით), typed blocks-ით (`hero`, `programs`, `text`, `life`, `contact_cta`).
- `PostController` — ამბების სია (გვერდვდაყოფით) და ცალკეული ამბავი.

### საჯარო საიტი — რეალურად აშენებული და შემოწმებული გვერდები

| გვერდი | Route | კონტროლერი | სტატუსი |
|---|---|---|---|
| მთავარი | `/` | `PageController@home` | ✅ ტესტი + ბრაუზერი |
| სკოლის შესახებ | `/about` | `PageController@show` | ✅ ტესტი + ბრაუზერი |
| სწავლა | `/learning` | `PageController@show` | ✅ ტესტი + ბრაუზერი |
| სასკოლო ცხოვრება | `/school-life` | `PageController@show` | ✅ ტესტი + ბრაუზერი |
| აისის ამბები (სია) | `/news` | `PostController@index` | ✅ ტესტი + ბრაუზერი |
| ცალკეული ამბავი | `/news/{slug}` | `PostController@show` | ✅ ტესტი |
| ბიბლიოთეკის კატალოგი | `/library` | `LibraryCatalogController` | ✅ ტესტი + ბრაუზერი (ძიება, empty state, ცალკე tenant-ის იზოლაცია) |
| კონტაქტი | `/contact` | `PageController@show` | ✅ ტესტი + ბრაუზერი |
| sitemap.xml | `/sitemap.xml` | `SitemapController` | ✅ ტესტი — pages + posts + library, draft/სხვა-tenant არასოდეს |
| robots.txt | `/robots.txt` | `RobotsController` | ✅ ტესტი |

ყველა ეს გვერდი `PublicLayout`-ითაა გახვეული (header/nav/footer, ბრენდის ტოკენები, მობილური მენიუ, „ჩემი {name}" ბმული → `/login`-ზე რეალურად).

**დარჩენილი საჯარო გვერდები** (docs/02 თავი 4): მასწავლებლების ცალკე გვერდი/პროფილები, ღონისძიებების კალენდარი, თითოეული პროგრამის ცალკე URL (ამჟამად ერთ `/learning` გვერდზეა სამივე ბარათი). მიღების **სრული** ნაკადი (ვიზიტის slot/ტევადობა) ჯერ არ არსებობს — იხილეთ ქვემოთ.

### მიღების ლიდის ფორმა (Admissions — ნაწილობრივი)

- `admission_leads` + `StoreAdmissionLeadRequest` (მხოლოდ სახელი/კონტაქტი/კლასი/თანხმობა, არანაირი ბავშვის პირადი/სამედიცინო მონაცემი).
- `VisitRequestDialog` რეალურად INSERT-ავს DB-ში (`POST /admissions/leads`), throttle 5/წთ, 24-საათიანი დუბლიკატის აღნიშვნა.
- **ტესტები** (5): მოქმედი ჩანაწერი, თანხმობის გარეშე უარყოფა, ბავშვის ID ველის არარსებობა, დუბლიკატის flag, 429 rate limit.
- **არ არის აშენებული**: რეალური ვიზიტის slot/კალენდარი ტევადობის კონტროლით — ეს რჩება კონკრეტულ შემდეგ ამოცანად.

### Seed მონაცემები (`TenantSeeder`, მხოლოდ dev/local)

- Tenant "აისი": domains `localhost`/`127.0.0.1`/`aisi.test`; brand tokens; admin user; 5 გამოქვეყნებული Page (home/about/learning/school-life/contact); 2 Post; 3 LibraryResource.
- მეორე, დამოუკიდებელი tenant ("second-school-demo") მხოლოდ იზოლაციის დასამტკიცებლად.

## ცნობილი ხარვეზები / ჯერ არ დამტკიცებული (ერთი, გასწორებული სია)

- **SSR node-პროცესი არ არის მუდმივად გაშვებული.** `config/inertia.php`-ში `ssr.enabled=true`; dev-ში (`vp dev`) SSR module graph ავტომატურად თბება და სრულ body-საც რენდერავს (`data-server-rendered="true"`) — ეს dev convenience-ია, **production-ისთვის persistent SSR პროცესი ჯერ არ არის მოწყობილი**. ამის ნაცვლად, production-ისთვის `app.blade.php` პირდაპირ კითხულობს `page.props.page.seoTitle/seoDescription`-ს და გამოაქვს რეალურ `<title>`/`<meta description>`-ად JS-ის გარეშეც (დამტკიცებული ტესტით `HomePageSeoTest`). **სრული body-content კვლავ მხოლოდ ჰიდრაციის შემდეგ ჩნდება production build-ში** — თუ crawler-ს/JS-less კლიენტს დასჭირდება სრული HTML, საჭირო იქნება `resources/js/ssr.tsx` + supervisor.
- CMS admin editor (draft→preview→publish→revert UI) — მხოლოდ schema, არა ეკრანი.
- სრული ვიზიტის/მიღების ნაკადი (slot booking, ტევადობა, staff სამუშაო სია) — მხოლოდ საწყისი lead-ფორმაა.
- canonical/hreflang tags, ძველი საიტის 301-mapping — ჯერ არ არის (sitemap/robots უკვე მზადაა).
- Identity/Family (ეტაპი 2): onboarding, invitations, guardian links, permissions — არ დაწყებულა.
- Academics/Portals (ეტაპი 2-3): კლასები, განრიგი, დასწრება, შეფასებები, დავალებები, პორტალის dashboards — არ დაწყებულა.
- Finance (ეტაპი 4), Communications (ეტაპი 3), Platform onboarding (ეტაპი 5), Deployment/UAT (ეტაპი 6) — არ დაწყებულა.
- `logo-concept.png`/`school-life.jpg` კვლავ კონცეფციური მასალაა — production-მდე სჭირდება ვექტორიზაცია/სკოლის დადასტურება.
- Georgian date formatting (`toLocaleDateString('ka-GE')`) headless Chromium-ის ერთ ვარიანტში (`chrome-headless-shell`) ციფრულ ფორმატზე დაბრუნდა შეზღუდული ICU მონაცემების გამო (`9/9/2026` ნაცვლად ქართული თვის სახელისა); სრულ Chromium/რეალურ ბრაუზერებში `Intl`-ს ka-GE მხარდაჭერა სტანდარტულია — გადასამოწმებელია რეალურ მოწყობილობაზე.

## შესრულებული ტესტები (ზუსტი, ბოლო გაშვება)

```
php artisan test                               → 60/60 passed, 224 assertions
./vendor/bin/pint --parallel                    → 0 issues (auto-fixed 2 files this session)
./vendor/bin/phpstan analyse --memory-limit=1G  → 0 errors (level 7)
npm run types:check (tsc --noEmit)              → 0 errors
npm run check                                   → 0 warnings/errors, 72 files (design/, docs/, CLAUDE.md excluded — vite.config.ts)
npm run build                                   → succeeds; BPG + Noto fonts hashed into public/build/assets
Playwright (headless Chromium): home, about, learning, school-life, news index, library, contact
  → ყველა 200, 0 failed network request, BPG font computed-style დამტკიცებული
```

## აწყობის/გაშვების ინსტრუქცია (ეს მანქანა)

```powershell
powershell -File scripts/dev-services.ps1   # Redis ხელით (Postgres უკვე service)
composer run dev                            # server + queue + vite ერთად
# ან: php artisan serve  &&  npm run dev
```

`php artisan migrate:fresh --seed` აღადგენს ორივე ტესტ-tenant-ს. სადემონსტრაციო ანგარიშზე შესვლა (მხოლოდ ლოკალურად, `http://localhost:8000/login`): ელფოსტა `admin@aisi.test`, პაროლი — Laravel-ის `UserFactory`-ის სტანდარტული dev-ტესტების default (`Hash::make('password')`, ანუ სიტყვასიტყვით `password`; ეს არის framework-ის ჩვეულებრივი convention ტესტებისთვის, არა production-ის საიდუმლო). production seed-ში ეს მომხმარებელი/პაროლი არასოდეს არ უნდა გამოჩნდეს.

**ტესტების გაშვებამდე** დარწმუნდით `public/hot` არ არსებობს (dev server გამორთულია), თორემ Inertia SSR ერთვება ტესტების HTTP პასუხშიც და თან-ტეხავს string-ზუსტ SEO assertion-ებს (ეს ცნობილი, უვნებელი ეფექტია, არა production-ის ბაგი).

## შემდეგი კონკრეტული ამოცანა

1. **იდენტობა და ოჯახი** (ეტაპი 2): staff/guardian invitations, guardian_links ცხრილი და policy, პირველი role-based dashboard (მშობელი ხედავს მხოლოდ დაკავშირებულ შვილს).
2. კლასები/სასწავლო წელი/ჩარიცხვა — აკადემიური სტრუქტურის საფუძველი განრიგისა და დასწრებისთვის.
3. CMS admin editor (draft/preview/publish/revert) რედაქტორის როლისთვის.
4. სრული ვიზიტის slot-ჯავშანი ტევადობის კონტროლით.
5. `docs/04`-ის დანარჩენი component-mapping (PortalLayout, TimetableDay, InvoiceTable, AttendanceRegister) — ეტაპი 2-3-ის დაწყებისას.

## საჭირო მონაცემები გასაგრძელებლად

უცვლელი — იხილეთ `docs/02-product-and-platform-spec.md`-ის მე-14 თავი და `START-HERE-CLAUDE.md`-ის მე-9 თავი (credentials/school facts არ მოვიგონეთ).
