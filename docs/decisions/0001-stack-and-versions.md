# 0001 — Stack და პაკეტების ვერსიები

თარიღი: 2026-09-10 · სტატუსი: მიღებული (ფაზა 0)

## კონტექსტი

`CLAUDE.md` მოითხოვს Laravel `^13.0`-ს PHP 8.4-ზე, ოფიციალურ React starter kit-ს, TypeScript strict, Inertia, Vite, PostgreSQL, Redis, S3-თავსებად storage-ს. ეს დოკუმენტი აფიქსირებს ზუსტ target-ვერსიებს, რომლებიც lockfile-ებში ჩაიკეტება Laravel skeleton-ის შექმნისას.

## გადაწყვეტილება

ლოკალურ მანქანაზე დადასტურებულია:

| კომპონენტი | ვერსია | წყარო |
|---|---|---|
| PHP | 8.4.24 (Herd, NTS) | `php -v` |
| Composer | 2.10.2 | `composer -V` |
| Node.js | 24.20.0 | `node -v` |
| npm | 11.19.0 | `npm -v` |
| Git | 2.55.0.windows.5 | `git --version` |

დაინსტალირებისას გამოვიყენებთ:

- **Laravel**: `^13.0`, PHP `^8.3` მინიმუმი (ჩვენი gerimo 8.4.24-ზე მუშაობს) — [releases](https://laravel.com/framework/docs/13.x/releases).
- **Starter kit**: ოფიციალური Laravel React starter kit (Inertia + React + TypeScript + Vite) — [starter kits](https://laravel.com/framework/docs/13.x/starter-kits). Composer-ის/npm-ის ზუსტი ქვე-ვერსიები დაფიქსირდება `composer.lock`/`package-lock.json`-ში პირველი `composer create-project`-ის დროს; ეს დოკუმენტი მხოლოდ major/minor მიზნებს აფიქსირებს ხელახლა-გამოგონების თავიდან ასაცილებლად.
- **TypeScript**: strict mode ჩართული `tsconfig.json`-ში (`"strict": true"`), starter kit-ის default-ის თანახმად შემოწმებული.
- **Database**: PostgreSQL (ვერსია დადგინდება ლოკალური provisioning გადაწყვეტილებისას — იხ. `implementation-status.md`-ის ღია საკითხი #1). Laravel-ის `pgsql` driver.
- **Cache/Queue**: Redis, Laravel queue driver-ით; Horizon განიხილება მას შემდეგ, რაც queue supervisor-ის deployment გარემო ცნობილი გახდება.
- **Storage**: S3-compatible driver (`league/flysystem-aws-s3-v3` ან Laravel-ის built-in S3 adapter), ცალკე public/private disk კონფიგურაცია.
- **UI კომპონენტები**: shadcn/ui პატერნის ხელახლა აწყობა plain Vite + React-ზე (არა Next.js/vinext-ზე დამოკიდებული ვერსია), Tailwind CSS-ის უახლესი Laravel starter kit-თან თავსებადი მაჟორული ვერსიით.

## რატომ

- ზუსტი ვერსიების ჩაწერა Composer/npm ინსტალაციამდე ხელს უშლის "shipped with whatever was latest today" დრიფტს და აძლევს გუნდს baseline-ს, რომლის მიმართაც lockfile diff-ები აზრიანია.
- PHP 8.4.24 უკვე დაყენებულია (Herd) — დამატებითი ინსტალაცია არ სჭირდება.

## შედეგები

- ყოველი მომდევნო `composer.json`/`package.json` ცვლილება ამ დოკუმენტთან შეუსაბამობისას მოითხოვს ან დოკუმენტის განახლებას, ან დასაბუთებას commit message-ში.

## განახლება — რეალურად დაყენებული ვერსიები (იმავე დღეს)

- **`laravel/react-starter-kit`-ის Packagist tag (`v1.0.1`) კვლავ Laravel 12-ზეა** (`laravel/framework: ^12.0`); Laravel 13-ის მხარდაჭერა მხოლოდ `main` branch-ზეა. დაყენებულია `composer create-project laravel/react-starter-kit:dev-main` კონსტრეინტით, `--stability=dev`-ით.
- დაყენებული ვერსიები: `laravel/framework v13.31.0`, `inertiajs/inertia-laravel v3.3.3`, `laravel/fortify v1.39.0`, `laravel/wayfinder v0.1.21`, React 19.2, Inertia React 3.0, TypeScript strict, Tailwind v4, Vite 8, `laravel-vite-plugin` v3.
- **PostgreSQL 17.11** — ოფიციალური EnterpriseDB Windows installer, დარეგისტრირებული როგორც Windows service (`postgresql-x64-17`).
- **Redis 8.10.1** — Scoop-ის portable ბინარი (არა Windows service; admin უფლება არ იყო ხელმისაწვდომი ამ მანქანაზე Memurai-ს/officiial installer-ის სერვისის რეგისტრაციისთვის). დეტალები `docs/implementation-status.md`-ში.
- `laravel/react-starter-kit`-ის `dev-main` branch-ზე დამოკიდებულობა **დროებითია** — როგორც კი ოფიციალური Laravel 13-თავსებადი tagged ვერსია გამოვა (`v1.1.0`+ სავარაუდოდ), `composer.json`-ის კონსტრეინტი უნდა შეიცვალოს tagged ვერსიაზე reproducibility-სთვის.
