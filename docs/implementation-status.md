# Implementation Status

განახლებულია: 2026-09-10 (ღამის სესია) · ფაზა: 1 მიმდინარეობს (tenancy + საჯარო მთავარი გვერდის პირველი ვერტიკალური slice)

## რეალურად დამოწმებული, მუშა ფუნქციონალი

ყველა ქვემოთ ჩამოთვლილი შემოწმებულია ავტომატური ტესტებით და/ან რეალურ ლოკალურ სერვერზე (`php artisan serve` + PostgreSQL + Redis), არა მხოლოდ დაწერილი კოდით.

### გარემო
- PHP 8.4.24 (Herd), Composer 2.10.2, Node 24.20.0 — უცვლელი ფაზა 0-დან.
- **PostgreSQL 17.11** — ოფიციალური Windows service (`postgresql-x64-17`), ავტომატურად იწყება. `aisi_local` ბაზა, superuser `postgres`/`postgres` (ლოკალური dev).
- **Redis 8.10.1** — Scoop-ის portable ბინარი; **არ არის Windows service** (admin უფლება არ იყო ხელმისაწვდომი) — ყოველ სესიაზე ხელით/სკრიპტით გასაშვები: `powershell -File scripts/dev-services.ps1`.
- Laravel skeleton `laravel/react-starter-kit:dev-main`-იდან: `laravel/framework v13.31.0`, `inertiajs/inertia-laravel v3.3.3`, `laravel/fortify v1.39.0`, `laravel/wayfinder v0.1.21`, React 19.2, TypeScript strict, Tailwind v4.
- Georgian UI: `APP_LOCALE=ka`, self-hosted Noto Sans Georgian (`public/fonts/`, SIL OFL), `<html lang="ka">` დამოწმებულია რეალურ HTTP პასუხში.

### Tenancy foundation (`app/Domain/Tenancy`)
- ცხრილები: `tenants`, `tenant_domains`, `tenant_memberships`, `brand_settings`, `feature_entitlements`.
- `App\Domain\Tenancy\CurrentTenant` — request-scoped context, `App\Http\Middleware\ResolveTenant` — global middleware, სანდო request host-იდან (`tenant_domains.domain`) იჭერს tenant-ს; უცნობი host → 404 (არა default tenant-ზე fallback).
- `App\Domain\Tenancy\Concerns\BelongsToTenant` trait — global scope + auto-stamp on create, დოკუმენტირებული როგორც **დამხმარე**, არა ერთადერთი დაცვა (CLAUDE.md-ის მოთხოვნისამებრ).
- `HandleInertiaRequests` აზიარებს `brand` prop-ს (სახელი/ლოგო/ფერები/locale/კონტაქტი) ყოველ გვერდზე — **არსად არაა "აისი" ან ფერები hardcoded** React კომპონენტში.
- **ტესტები** (`tests/Feature/Tenancy/TenantIsolationTest.php`, 4 ტესტი): უცნობი host → 404; თითო domain მხოლოდ საკუთარ tenant-ს ემსახურება; global scope მალავს სხვა tenant-ის ჩანაწერებს; **explicit tenant_id ფილტრიც** (defense-in-depth გზა) ბლოკავს cross-tenant წვდომას თუნდაც global scope-ის გარეშე.

### Content (CMS საწყისი) — `app/Domain/Content`
- `pages` + `page_revisions` ცხრილები (draft/published სტატუსი, `published_at`, SEO title/description, ვერსიის ისტორიისთვის საფუძველი). **რედაქტორის UI (draft→preview→publish→revert) ჯერ არ არის აშენებული** — მხოლოდ schema/model საფუძველი.
- `HomeController` რეალურად კითხულობს tenant-ის გამოქვეყნებულ "home" გვერდს DB-დან (არა hardcoded ტექსტი) და აძლევს Inertia-ს.

### საჯარო მთავარი გვერდი (`resources/js/pages/public/home.tsx`)
- აშენებულია `AisiConcept.tsx`-ის სტრუქტურის მიხედვით (`docs/04`-ის component-mapping ცხრილის დაცვით): hero, პროგრამების ბარათები, "სასკოლო ცხოვრება" სექცია, კონტაქტის CTA — ყველა მონაცემი DB-ის `page.blocks`-იდან.
- `PublicLayout` (header/nav/footer) — ბრენდის ტოკენები CSS custom properties-ით (`--brand-primary` და ა.შ.), მობილური მენიუ, 44px+ touch targets.
- **დამოწმებულია რეალურ სერვერზე**: `GET /` → 200, `<html lang="ka">`, Inertia-ს `data-page` payload შეიცავს რეალურ ბრენდისა და გვერდის მონაცემებს (არა demo/hardcoded).

### მიღების ლიდის ფორმა (Admissions — ნაწილობრივი)
- `admission_leads` ცხრილი + `AdmissionLead` მოდელი + `StoreAdmissionLeadRequest` (მხოლოდ სახელი/კონტაქტი/სასურველი კლასი/თანხმობა — **არანაირი ბავშვის პირადი ნომერი ან სამედიცინო ინფორმაცია**, spec-ის მოთხოვნისამებრ).
- `VisitRequestDialog` (React, shadcn/ui + Inertia `<Form>` კომპონენტი) რეალურად აგზავნის `POST /admissions/leads`-ზე — **არა დემო local-state success**, კონცეფციისგან განსხვავებით.
- Rate limiting (`throttle:5,1`), დუბლიკატის აღნიშვნა (იგივე კონტაქტი 24 საათში → `duplicate_of_lead_id`), flash toast წარმატებაზე.
- **ტესტები** (`tests/Feature/Admissions/AdmissionLeadTest.php`, 5 ტესტი): მოქმედი ჩანაწერი, თანხმობის გარეშე უარყოფა, ბავშვის პირადი ველის არარსებობა, დუბლიკატის აღნიშვნა, rate limit 429.
- **არ არის აშენებული**: სრული ვიზიტის slot/კალენდრის ჯავშანი ტევადობის კონტროლით (spec-ის „ვიზიტები: დროის სლოტები, ტევადობა..." ჯერ არ სრულდება — ეს ამჟამად მხოლოდ ინტერესის ლიდის ფორმაა).

### Seed მონაცემები
- `TenantSeeder` (მხოლოდ ლოკალური/dev, არასოდეს production-ზე): tenant "აისი" (domains: `localhost`, `127.0.0.1`, `aisi.test`), brand_settings რეალური ბრენდის ტოკენებით, admin მომხმარებელი, გამოქვეყნებული Home გვერდი; მეორე, დამოუკიდებელი tenant izoliაციის ტესტისთვის.

### რეალურ ბრაუზერში გატესტვა (Playwright, headless Chromium)

- `composer run dev` გაშვებულია, `http://localhost:8000/` გახსნილია headless ბრაუზერში 1280×900 და 390×844 (მობილური) ზომებზე — ორივეზე გვერდი სწორად რენდერდება, ჰორიზონტალური overflow არ არის, ლოგო/ფერები/ტექსტი სწორია. სქრინშოთები დათვალიერებულია ვიზუალურად.
- „დაგეგმე ვიზიტი" ღილაკი რეალურად ხსნის დიალოგს ყველა ველით (სახელი, ტელეფონი/ელფოსტა, კლასი, თარიღი, თანხმობა).
- **ნაპოვნი და გასწორებული რეალური ბაგი**: Noto Sans Georgian ფონტები (`@font-face src: url('/fonts/...')`) 404-ობდა dev რეჟიმში, რადგან absolute `/fonts/...` bare path Vite dev server-ის საკუთარ origin-თან (`[::1]:5173`) იჭრებოდა, არა Laravel-ის `public/`-თან. გადავიდა Vite-ის asset pipeline-ზე: ფონტის ფაილები გადავიდა `public/fonts/` → `resources/fonts/`, CSS-ში `url('../fonts/...')` ფარდობითი მისამართით — Vite ორივე რეჟიმში (dev და `npm run build`) სწორად ამუშავებს/hash-ავს მათ. დამოწმებულია Playwright-ის request-monitoring-ით (0 failed request).
- **საინტერესო აღმოჩენა**: dev რეჟიმში (`vp dev`) Inertia-ს SSR module graph ავტომატურად თბება და რეალურად რენდერავს სრულ body-ს (`data-server-rendered="true"`), მიუხედავად იმისა, რომ production SSR supervisor არ გვაქვს გაშვებული — ეს მხოლოდ dev convenience-ია, production build-ს ეს არ ეხება (`build:ssr` script არსებობს, მაგრამ persistant SSR პროცესი ჯერ არ არის სქემაში).
- **ოპერაციული შენიშვნა ტესტებზე**: `public/hot` ფაილის არსებობისას (ანუ `composer run dev`/`npm run dev` გაშვებულია) `php artisan test`-იც გადადის Vite dev-server რეჟიმზე და Inertia SSR ერთვება ტესტების HTTP პასუხშიც, რაც ცვლის `<title>`-ის ფორმატს (client-side app.tsx-ის `title` callback-ის მიხედვით ემატება ` - {app.name}`). ეს ნორმალურია და production/CI-ს არ ეხება (იქ `public/hot` არ არსებობს), მაგრამ **ტესტების გაშვებამდე დარწმუნდით, რომ dev server გამორთულია** (`rm public/hot` საკმარისია, თუ პროცესი უკვე მოკლულია).

### sitemap.xml / robots.txt
- `GET /sitemap.xml` — გენერირებული tenant-ის საკუთარი გამოქვეყნებული გვერდებიდან (არა static ფაილი); draft გვერდები და სხვა tenant-ის URL-ები არასოდეს ჩნდება.
- `GET /robots.txt` — Allow: /, მიუთითებს sitemap-ზე; კომენტარშია შენიშვნა, რომ პორტალის რეალური routes უნდა დაემატოს Disallow-ად ფაზა 2-ში.
- ტესტები (`tests/Feature/Content/SitemapTest.php`): sitemap არ შეიცავს draft გვერდს და არც სხვა tenant-ის გვერდს.

## ცნობილი ხარვეზები / ჯერ არ დამტკიცებული

- **SSR node-პროცესი არ არის გაშვებული** (`config/inertia.php`-ში `ssr.enabled=true` მზადაა, მაგრამ `resources/js/ssr.tsx` entry point ჯერ არ არსებობს და supervisor-იც არა). ამის ნაცვლად ავირჩიეთ CLAUDE.md-ის ალტერნატივა: „მიზანმიმართული Blade საჯარო rendering გადაწყვეტილება" — `resources/views/app.blade.php` პირდაპირ კითხულობს `$page['props']['page']['seoTitle'/'seoDescription']`-ს და გამოაქვს რეალურ `<title>`/`<meta description>`-ად, JS-ის გარეშეც. **დამოწმებულია** ტესტით (`tests/Feature/Content/HomePageSeoTest.php`) და რეალურ HTTP პასუხში. სრული body-content (არა მხოლოდ title/description) კვლავ მხოლოდ ჰიდრაციის შემდეგ ჩნდება ბრაუზერში — თუ მომავალში საჭირო გახდება crawler-ისთვის სრული body-ც JS-ის გარეშე, საჭირო იქნება სრული SSR-ის ჩართვა.
- დარჩენილი საჯარო გვერდები (docs/02, თავი 4): შესახებ, სწავლა (ცალკე URL თითო პროგრამას), მიღება (სრული, ვიზიტის slot-ებით), კონტაქტი, სიახლეები, კალენდარი, რესურსები — ჯერ არ არის აშენებული, მხოლოდ Home.
- CMS რედაქტორის UI (draft/preview/publish/revert) — მხოლოდ schema, არა admin ეკრანი.
- sitemap/robots/canonical/hreflang/301-mapping — ჯერ არ არის.
- `npm run dev`-ის ცოცხალი (HMR) რეჟიმი არ შემოწმებულა ბრაუზერში ვიზუალურად — მხოლოდ production build + HTTP smoke test.
- Portal (Phase 2 scope): dashboards, schedule, attendance, library, payments — არ დაწყებულა.
- `logo-concept.png`/`school-life.jpg` კვლავ კონცეფციური მასალაა (იხ. `docs/04`, სექცია 4) — production-მდე სჭირდება ვექტორიზაცია და სკოლის დადასტურება.

## შესრულებული ტესტები (ზუსტი შედეგი)

```
php artisan test          → 51/51 passed, 180 assertions
./vendor/bin/pint         → fixed (0 remaining issues after fix)
./vendor/bin/phpstan analyse --memory-limit=1G → 0 errors (level 7)
npm run types:check       → 0 errors
npm run check             → 0 warnings/errors (68 files; design/, docs/, CLAUDE.md excluded — see vite.config.ts)
npm run build             → succeeds, manifest includes public/home
```

## აწყობის/გაშვების ინსტრუქცია (ეს მანქანა)

```powershell
# ერთხელ, სესიის დასაწყისში (Redis არ არის service):
powershell -File scripts/dev-services.ps1

# დეველოპმენტი:
composer run dev   # server + queue + logs + vite ერთად
# ან ცალ-ცალკე:
php artisan serve
npm run dev
```

`.env`-ში: `DB_CONNECTION=pgsql`, `DB_DATABASE=aisi_local`, `DB_USERNAME=postgres`, `DB_PASSWORD=postgres`; `SESSION/CACHE/QUEUE_CONNECTION=redis`.

`php artisan migrate:fresh --seed` აღადგენს ორივე ტესტ-tenant-ს (`aisi`, `second-school-demo`) `TenantSeeder`-იდან.

## შემდეგი ნაბიჯები (პრიორიტეტით)

1. დარჩენილი საჯარო გვერდები + CMS admin editor (draft/preview/publish/revert UI).
2. სრული ვიზიტის slot-ჯავშანი ტევადობის კონტროლით (ამჟამინდელი lead-ფორმის დამატებით).
3. `docs/04-design-handoff.md`-ის დანარჩენი component-mapping ერთეულების გადატანა (PortalLayout, TimetableDay და ა.შ.) — ფაზა 2-ის დაწყებისას.
4. canonical/hreflang tags და ძველი საიტის 301-mapping scaffolding (sitemap/robots უკვე მზადაა).
5. სრული Inertia SSR (თუ crawler-ს დასჭირდება page body-ც JS-ის გარეშე, არა მხოლოდ title/description).

## შენიშვნა დიზაინის პროტოტიპის განახლებაზე

სესიის განმავლობაში `design/`-ს დაემატა სათაურის შრიფტი **BPG Nino Mtavruli Bold** (`design/public/bpg-nino-mtavruli-bold.ttf`, წყარო fonts.ge). `docs/04-design-handoff.md`-ის განახლება თავადვე აღნიშნავს, რომ ამ 2008 წლის შრიფტის კომერციული SaaS-ში embedding-ის ლიცენზია **დაუდასტურებელია** და გადასამოწმებელია production-მდე. სანამ ეს არ დადასტურდება, რეალურ Laravel აპში (`resources/css/app.css`, `public/fonts/`) ეს შრიფტი **არ არის და არ უნდა დაემატოს** — ამჟამად მხოლოდ Noto Sans Georgian (SIL OFL, უკვე დამოწმებული ლიცენზია) გამოიყენება `home.tsx`/`public-layout.tsx`-ში.

## ცნობილი გარემოს რისკები

- პროექტი დევს OneDrive-სინქრონიზებულ საქაღალდეში — რეკომენდირებულია გამონაკლისში ჩამატება ან non-synced მდებარეობაზე გადატანა (მომხმარებლის გადასაწყვეტი, არ შევცვალე).
- PostgreSQL-ის winget-installer-ის თავდაპირველი დაკიდების მიზეზი ბოლომდე ახსნილი არ არის, თუმცა საბოლოოდ წარმატებით დარეგისტრირდა Windows service-ად.

## საჭირო მონაცემები გასაგრძელებლად

უცვლელი — იხილეთ `docs/02-product-and-platform-spec.md`-ის მე-14 თავი.
