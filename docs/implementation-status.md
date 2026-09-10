# Implementation Status

განახლებულია: 2026-09-10 (გვიან ღამით) · ფაზა: 1 დაწყებულია (foundation + skeleton)

## რა შემოწმდა / შესრულდა

### გარემო

- PHP 8.4.24 (Herd), Composer 2.10.2, Node 24.20.0, npm 11.19.0, Git 2.55.0 — ყველა ხელმისაწვდომი.
- **PostgreSQL 17.11** დაყენებულია როგორც ოფიციალური Windows service (`postgresql-x64-17`, EnterpriseDB installer). ავტომატურად იწყება Windows-ის ჩართვისას. `aisi_local` ბაზა შექმნილია; superuser `postgres` / პაროლი `postgres` (მხოლოდ ლოკალური dev, `.env`-შია).
  - შენიშვნა ინსტალაციაზე: winget-ით გაშვებული installer თავიდან ჩამოიკიდა (non-admin სესიაში elevation-ს ელოდებოდა), მაგრამ საბოლოოდ თვითონ ავიდა ადმინის უფლებით და service-ად დარეგისტრირდა — გაუგებარია ზუსტად რატომ (შესაძლოა ანგარიშს აქვს silent-elevation policy). თუ სამომავლოდ საჭირო გახდება ხელახლა დაყენება, გაითვალისწინეთ, რომ ეს პროცესი შეიძლება არაპროგნოზირებადად დაიკიდოს non-interactive shell-ში.
- **Redis 8.10.1** დაყენებულია Scoop-ის (`scoop install redis`) portable ბინარებით `C:\Users\Gvineee\scoop\apps\redis`. **არ არის Windows service** — ამ მანქანაზე ადმინის უფლება არ იყო ხელმისაწვდომი (`Memurai` MSI-ის ინსტალაცია ჩავარდა access-denied-ით სერვისის რეგისტრაციაზე). ყოველ სესიაზე ხელით გაშვება საჭიროა: `powershell -File scripts/dev-services.ps1` (ან პირდაპირ `redis-server.exe`). PHP-ს აქვს `redis` (phpredis) გაფართოება უკვე ჩართული.
- Docker Desktop და WSL2 არ არის დაყენებული ამ მანქანაზე (არც სცადა — Scoop-ის portable მარშრუტმა იმუშავა უფრო სწრაფად non-admin სესიაში).
- Scoop თავად დაყენდა non-admin, user-scope პაკეტის მენეჯერად (`~/scoop`), რადგან `winget`-ით Memurai-ს service-ის რეგისტრაცია ვერ მოხერხდა ადმინის უფლების გარეშე.

### Laravel skeleton

- `composer create-project laravel/react-starter-kit:dev-main` — **მნიშვნელოვანი დაკვირვება**: `laravel/react-starter-kit`-ის Packagist-ზე tagged ვერსია (`v1.0.1`) კვლავ `laravel/framework: ^12.0`-ზეა დამოკიდებული; Laravel 13-ის მხარდაჭერა (`^13.17`, Fortify, Wayfinder, Inertia 3.x) მხოლოდ `main` branch-ზეა, ჯერ არ არის tagged release-ად. ამიტომ დაყენებულია `dev-main` კონსტრეინტით.
- დაყენებული ვერსიები: `laravel/framework v13.31.0`, `inertiajs/inertia-laravel v3.3.3`, `laravel/fortify v1.39.0`, `laravel/wayfinder v0.1.21`, PHP-ის მოთხოვნა `^8.3` (ჩვენთან 8.4.24).
- Frontend: React 19.2, Inertia React 3.0, TypeScript strict (`"strict": true`), Tailwind v4, Radix UI პრიმიტივები, `laravel-vite-plugin` v3, Vite 8.
- Skeleton გადმოტანილია პროექტის root-ში (`robocopy /MOVE`); `docs/`, `design/`, `CLAUDE.md`, `.git` ხელუხლებელია.
- `.env`/`.env.example` მორგებულია: `APP_NAME=Aisi`, `APP_LOCALE=ka`, `DB_CONNECTION=pgsql` → `aisi_local`, `SESSION/CACHE/QUEUE_CONNECTION=redis`.
- **დამოწმებულია მუშაობს**: `php artisan --version` → `Laravel Framework 13.31.0`; `php artisan migrate:fresh` წარმატებით გაეშვა PostgreSQL-ზე (users/cache/jobs/passkeys/2FA მიგრაციები); `Cache::put/get` წარმატებით გაეშვა Redis-ზე; `npm run types:check` — 0 შეცდომა.
- **ჯერ არ შემოწმებულა**: `npm run dev`/`vite build` რეალურ ბრაუზერში, `composer run dev` concurrently-სკრიპტი (server+queue+logs+vite ერთად), SSR build.

### დიზაინის წყარო — გასწორებული დასკვნა

პირველი ინვენტარისას (`docs/decisions/0002`) `design/`-ში მხოლოდ ინგლისურენოვანი loading-skeleton იყო. მას შემდეგ handoff-ს დაემატა:

- `design/app/AisiConcept.tsx` — სრული, ქართულენოვანი, აისი-ბრენდირებული საჯარო საიტისა და პორტალის ინტერაქციული კონცეფცია (როლის გადამრთველით: მშობელი/მოსწავლე/მასწავლებელი/ადმინისტრაცია).
- `design/app/globals.css` — რეალური ბრენდის token-ები (`docs/03`-თან შესაბამისი Midnight/Dawn), Noto Sans Georgian ფონტები, სრული responsive/accessibility grooming.
- `design/public/logo-concept.png`, `school-life.jpg`, ფონტის ფაილები.
- `brand-assets/logo-concept.png` (root-ში) — იგივე ლოგოს კონცეფცია (გახსნილი წიგნი + ამომავალი მზე), დამოუკიდებლად დამატებული.
- `CLAUDE.md`-ში ახალი მითითება `docs/04-design-handoff.md`-ზე — **ეს ფაილი ჯერ არ არსებობს დისკზე**. ველოდებით; `docs/decisions/0002` განახლდა შესაბამისად და გავაგრძელებთ `AisiConcept.tsx` + `docs/03`-ის საფუძველზე.

დეტალები: `docs/decisions/0002-design-source-of-truth.md` (განახლებული ვერსია).

## შესრულებული (checklist)

- [x] ფაილების/runtime-ების ინვენტარი
- [x] `docs/decisions/0001-stack-and-versions.md`, `0002-design-source-of-truth.md` (გასწორებული)
- [x] `.env.example`, `.env` (ლოკალური, placeholder-ების გარეშე მხოლოდ .env.example-ში)
- [x] git repository ინიციალიზებული, Phase 0 commit
- [x] PostgreSQL 17 (Windows service) + Redis 8.10.1 (Scoop portable) ლოკალურად მუშაობს
- [x] Laravel 13 + React starter kit (dev-main) დაყენებული და გადატანილი root-ში
- [x] მიგრაციები გაეშვა PostgreSQL-ზე; cache გაეშვა Redis-ზე
- [x] `scripts/dev-services.ps1` — Redis-ის ხელით გაშვების დამხმარე სკრიპტი

## შემდეგი ნაბიჯები

1. **Tenancy foundation**: `tenants`, `tenant_domains`, `tenant_memberships`, `brand_settings`, `feature_entitlements` მიგრაციები + მოდელები + domain-resolving middleware + policy scaffolding. მეორე tenant-ის seed იზოლაციის ტესტისთვის (`docs/02`, თავი 7.1 და კრიტიკული შემოწმება #1).
2. საჯარო საიტის გვერდები (`AisiConcept.tsx`-ის სექციური სტრუქტურის მიხედვით, მაგრამ DB-ით backed კონტენტით): მთავარი, შესახებ, სწავლა, სასკოლო ცხოვრება, კონტაქტი.
3. მიღება/ვიზიტის ჯავშნის რეალური backend (ტევადობის კონტროლი, queued შეტყობინება) — კონცეფციის დემო ფორმის ნაცვლად.
4. `npm run dev` / `composer run dev` რეალურ ბრაუზერში გატესტვა მანამდე, სანამ "მუშაობს" ჩაითვლება დასრულებულად.
5. `docs/04-design-handoff.md`-ის გამოჩენისას გადავამოწმოთ ხომ არ ცვლის ზემოთ მოცემულ გეგმას.

## ცნობილი ხარვეზები / რისკები

- **Redis არ არის Windows service** — მანქანის გადატვირთვის შემდეგ საჭიროა ხელით გაშვება (`scripts/dev-services.ps1`). Staging/production-ზე ეს არ იქნება პრობლემა (Linux-based hosting), მაგრამ ლოკალურ dev-ზე გასათვალისწინებელია.
- **პროექტი დევს OneDrive-სინქრონიზებულ საქაღალდეში.** `vendor/`, `node_modules/` ახლა ათასობით ფაილს ამატებს ამ საქაღალდეს, რომელსაც OneDrive შეეცდება დასინქრონება — ეს ანელებს file I/O-ს (`php artisan` ბრძანებები 10-30 წამს იღებდა პირველ გაშვებაზე) და შეიძლება OneDrive-ის quota/სინქრონიზაციის საკითხები შექმნას. რეკომენდაცია: დაამატეთ ეს საქაღალდე OneDrive-ის "always keep on this device" გამონაკლისებში, ან გადაიტანეთ პროექტი non-synced მდებარეობაზე — ეს მომხმარებლის გადასაწყვეტია, არ შევცვალე ავტომატურად.
- PostgreSQL-ის winget-installer-ის თავდაპირველი ჩამოკიდების მიზეზი ბოლომდე ახსნილი არ არის (იხ. ზემოთ).
- ყველა დანარჩენი `docs/02`-ის მე-14 თავის დაუდასტურებელი საკითხი (რეალური საფასური, მოსწავლეთა რაოდენობა, EduPage წვდომა და ა.შ.) ჯერ კვლავ ღიაა.

## საჭირო მონაცემები გასაგრძელებლად

იხილეთ `docs/02-product-and-platform-spec.md`-ის მე-14 თავი — არაფერი შეცვლილა აქედან.
