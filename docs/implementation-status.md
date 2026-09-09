# Implementation Status

განახლებულია: 2026-09-10 · ფაზა: 0 (შემოწმება და foundation-ის გეგმა)

## რა შემოწმდა

- **Repository**: `C:\Users\Gvineee\OneDrive\Desktop\Claude AI Projects\aisi.edu.ge` არ არის git repository (`git status` → `fatal: not a git repository`). Laravel აპლიკაცია ჯერ არ არსებობს — მხოლოდ `docs/` და `design/` საქაღალდეებია.
- **დოკუმენტები**: წაკითხულია `docs/01-existing-site-audit.md`, `docs/02-product-and-platform-spec.md`, `docs/03-brand-and-marketing.md`. სამივე მკაფიოდ აღნიშნავს, რომ რიცხვები/ვადები/ტექსტები დასადასტურებელია სკოლისგან და არა უკვე დამტკიცებული ფაქტი.
- **`design/` საქაღალდე — მნიშვნელოვანი აღმოჩენა**: ეს არ არის აისის ბრენდირებული UI მაკეტი. ეს არის ჯერ დაუსრულებელი, ინგლისურენოვანი auto-generated scaffold (`vinext` + Cloudflare `wrangler` + `@openai/sites-vite-plugin`, იხ. `design/package.json`, `design/.openai/hosting.json`), რომლის `app/page.tsx` მთლიანად skeleton/loading-state placeholder-ია სათაურით „Building your site / Your site is taking shape". არ არსებობს არც ერთი რეალური გვერდის კომპოზიცია (hero, პროგრამები, მიღება და ა.შ.), არც აისის ლოგო/ფერები/ქართული ტიპოგრაფია გამოყენებული. რეალურად გამოსადეგია მხოლოდ:
  - `design/components/ui/*` — სრული shadcn/ui + Radix-ტიპის (`@base-ui/react`) კომპონენტების ნაკრები (accordion-დან tooltip-მდე), Tailwind v4 + `class-variance-authority` პატერნით.
  - `design/app/globals.css`-ის token-ური სტრუქტურა (`--radius`, `--color-*` oklch ცვლადები, `@theme inline`) — ესთეტიკური მიმართულების ნაცვლად, არქიტექტურული პატერნი როგორ ავაწყოთ token-ები.
  - ეს stack (`vinext`/`wrangler`/Next.js) **არ არის** თავსებადი CLAUDE.md-ის მოთხოვნილ Laravel + Inertia + Vite არქიტექტურასთან და პირდაპირ არ გადმოიტანება; მხოლოდ კომპონენტების source (`.tsx` ფაილები TypeScript-ში) და დიზაინ-ტოკენების პატერნი ინახება reference-ად.
  - **ვიზუალური ენა ამ ეტაპზე მხოლოდ `docs/03-brand-and-marketing.md`-შია განსაზღვრული**: ფერები (Midnight #132B45, Dawn #F5683C, White, Cloud #F4F7FA, Teal #147D78, Muted #526579), Noto Sans Georgian ტიპოგრაფია, ლოგოს კონცეფცია (წიგნი + მზე). ეს არის შემოთავაზებული მიმართულება და არა დამტკიცებული ბრენდბუქი.
  - გადაწყვეტილება ჩაწერილია `docs/decisions/0002-design-source-of-truth.md`-ში.
- **Runtime-ები ამ მანქანაზე** (Windows 11, PowerShell + Herd):
  - PHP 8.4.24 CLI (Laravel Herd, `pdo_pgsql`, `pgsql`, `redis`, `pdo_sqlite`, `sqlite3` ჩართული) ✅
  - Composer 2.10.2 ✅
  - Node v24.20.0, npm 11.19.0 ✅
  - Git 2.55.0 ✅
  - **PostgreSQL server — არ არის დაინსტალირებული/გაშვებული.** `psql` PATH-ში არ არის; Herd-ის `services/` საქაღალდე ცარიელია (Herd Pro-ს services manager არ არის კონფიგურირებული).
  - **Redis server — არ არის დაინსტალირებული/გაშვებული.** `redis-cli` არ არსებობს.
  - **Docker — არ არის დაინსტალირებული** (`docker` ბრძანება ვერ მოიძებნა).
  - ეს ცარიელი ადგილებია და არა გადაწყვეტილება; საჭიროა მომხმარებლის არჩევანი (იხ. ღია საკითხები).

## შესრულებული (ფაზა 0)

- [x] ფაილების/runtime-ების ინვენტარი (ეს დოკუმენტი)
- [x] `docs/decisions/0001-stack-and-versions.md` — დაფიქსირებული პაკეტების მიზნობრივი ვერსიები
- [x] `docs/decisions/0002-design-source-of-truth.md` — `design/`-ის სტატუსისა და ვიზუალური წყაროს გადაწყვეტილება
- [x] `.env.example` — მხოლოდ placeholder მნიშვნელობებით
- [x] git repository ინიციალიზებული ამ საქაღალდეში

## შემდეგი ნაბიჯები (ბლოკავს ფაზა 1-ის დაწყებას)

1. **გადაწყვეტილება საჭიროა მომხმარებლისგან**: ლოკალური PostgreSQL/Redis როგორ მოვაწყოთ ამ Windows მანქანაზე — Docker Desktop-ის დაყენება, თუ native Windows სერვისები (მაგ. PostgreSQL-ის ოფიციალური installer + Memurai/Redis-ის Windows ალტერნატივა), თუ Herd Pro-ს services manager. ეს ცვლის ლოკალურ dev setup ინსტრუქციას `docs/decisions/`-ში.
2. Laravel 13 skeleton-ის `composer create-project` + React starter kit-ის ინსტალაცია ამ საქაღალდეში, ვერსიების დაფიქსირებით lockfile-ში.
3. Tenancy foundation-ის მიგრაციები (`tenants`, `tenant_domains`, `tenant_memberships`, `brand_settings`, `feature_entitlements`) და მეორე tenant-ის seed იზოლაციის ტესტისთვის.
4. CMS/Admissions/Visit მოდულების და საჯარო გვერდების React კომპონენტების აწყობა ბრენდის token-ებზე (არა `design/`-ის placeholder-ზე).

## ცნობილი ხარვეზები / დაუდასტურებელი მონაცემები

ყველა ის საკითხი, რაც `docs/02-product-and-platform-spec.md`-ის მე-14 თავშია ჩამოთვლილი (რეალური საფასური/საფეხურები, მოსწავლეთა რაოდენობა, EduPage წვდომა, მეურვის უფლებების პროცესი, ბანკის კონტრაქტი და ა.შ.) რჩება დაუდასტურებელი. ამ ეტაპზე მასზე არაფერია აშენებული.

## საჭირო მონაცემები გასაგრძელებლად

- სკოლის დადასტურება: contact-ის, ისტორიის, ლოგოს/ფერების საბოლოო ვერსია (თუ ბრენდბუქი შემდგომში მოვა, ჩაანაცვლებს `docs/03`-ს).
- ლოკალური dev გარემოს გადაწყვეტილება (იხ. ზემოთ, პუნქტი 1).
