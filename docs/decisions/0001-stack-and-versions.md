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

## განახლება 2026-09-10 — production DB engine იცვლება PostgreSQL-დან MySQL-ზე

**კონტექსტი:** მომხმარებელმა მოგვცა SSH წვდომა რეალურ CloudPanel-სერვერზე (`165.245.223.56`), სადაც საიტისთვის უკვე გამოყოფილია საკუთარი disk/DB. Read-only reconnaissance-მა დაადასტურა:

- სერვერზე დაყენებულია მხოლოდ **MySQL-თავსებადი Percona Server 8.4.10-10** — PostgreSQL საერთოდ არ არსებობს.
- საიტის SSH მომხმარებელს (`ais`) არ აქვს `sudo`/root წვდომა და `clpctl`-იც site-scoped-ია (მხოლოდ db import/export, permissions reset, varnish purge) — ანუ PostgreSQL-ის დაყენება ამ სერვერზე შესაძლებელი არ არის ჩვენი მხრიდან.
- CloudPanel-მა ამ საიტისთვის უკვე შექმნა MySQL ბაზა (`ais`/`ais`), რომლის re-provisioning root-ის გარეშე შეუძლებელია.

**გადაწყვეტილება:** production-ის DB engine ხდება **MySQL/Percona** (ის, რაც სერვერზე რეალურად არსებობს), PostgreSQL-ის მაგივრად, რომელიც `CLAUDE.md`-ში იყო თავდაპირველი დაშვება. ეს არის ცნობილი, დოკუმენტირებული გადახრა თავდაპირველი tech-decision-იდან — მიზეზი ინფრასტრუქტურული შეზღუდვაა (host-ს არ აქვს root/PostgreSQL), არა კოდის დიზაინის არჩევანი.

- **Production**: `DB_CONNECTION=mysql`, MySQL/Percona 8.4.x.
- **ლოკალური დეველოპმენტი/ტესტები**: ჯერჯერობით რჩება PostgreSQL 17 (ლოკალურად უკვე დაყენებული და მუშა) და/ან SQLite ტესტების scoped run-ებისთვის. ეს ნიშნავს, რომ ლოკალური და production DB engine დროებით განსხვავდება — მისაღები რისკია, რადგან კოდი იყენებს მხოლოდ Eloquent Schema Builder-ის სტანდარტულ, cross-database მეთოდებს (არცერთ migration-ში არ არის Postgres-სპეციფიკური raw SQL, `ilike`, array/enum ტიპები თუ Postgres-ონლი constraint-ები). `LibraryCatalogController`-ის ძიების query-ც უკვე დაწერილია `like`-ით (არა `ilike`) სპეციალურად ამ cross-compatibility-სთვის.
- **გადამოწმება (ჩატარებულია, არა თეორიული)**: ლოკალურად დაყენდა MariaDB 12.3.3 (MySQL-თავსებადი, Scoop portable) და მასზე გაეშვა მთლიანი migration set (26 migration) + სრული ტესტ-suite (`phpunit.xml`-ის დროებითი `DB_CONNECTION=mysql` override-ით). შედეგი: **73/73 ტესტი გავლილია MySQL-ზეც**. ერთი რეალური შეუთავსებლობა აღმოჩნდა და გასწორდა — `teacher_assignments`-ის ოთხსვეტიანი unique constraint-ის auto-generated სახელი (`teacher_assignments_tenant_id_user_id_school_class_id_subject_unique`, 70 სიმბოლო) აღემატებოდა MySQL-ის 64-სიმბოლოიან identifier-ლიმიტს (PostgreSQL-ს ეს ლიმიტი არ აქვს ამ ფორმით — assign-ისას მოკლდება). მიგრაციაში დაემატა მოკლე, ხელით მითითებული სახელი (`teacher_assignments_unique_assignment`). დანარჩენი ყველა migration/query (`json()` სვეტები, `foreignId`+`constrained`, მრავალსვეტიანი unique-ები, `LibraryCatalogController`-ის `like`-ძებნა) იმუშავა უცვლელად.
- MariaDB-ის ლოკალური ინსტანცია მხოლოდ ამ ერთჯერადი გადამოწმებისთვის იყო აწყობილი (Scoop-ის portable ბინარი, არა service) და შემოწმების დასრულების შემდეგ გაჩერდა — ის არ არის მუდმივი ლოკალური dev-გარემოს ნაწილი.
- **სამომავლო**: როცა/თუ production infra შეიცვლება (მაგ. root წვდომა გაჩნდება ან სერვერი შეიცვლება), ეს გადაწყვეტილება შეიძლება გადაისინჯოს. მანამდე ყველა ახალი migration/query წერილობით უნდა შემოწმდეს MySQL-თან თავსებადობაზეც, არა მხოლოდ PostgreSQL-თან.

## განახლება — რეალურად დაყენებული ვერსიები (იმავე დღეს)

- **`laravel/react-starter-kit`-ის Packagist tag (`v1.0.1`) კვლავ Laravel 12-ზეა** (`laravel/framework: ^12.0`); Laravel 13-ის მხარდაჭერა მხოლოდ `main` branch-ზეა. დაყენებულია `composer create-project laravel/react-starter-kit:dev-main` კონსტრეინტით, `--stability=dev`-ით.
- დაყენებული ვერსიები: `laravel/framework v13.31.0`, `inertiajs/inertia-laravel v3.3.3`, `laravel/fortify v1.39.0`, `laravel/wayfinder v0.1.21`, React 19.2, Inertia React 3.0, TypeScript strict, Tailwind v4, Vite 8, `laravel-vite-plugin` v3.
- **PostgreSQL 17.11** — ოფიციალური EnterpriseDB Windows installer, დარეგისტრირებული როგორც Windows service (`postgresql-x64-17`).
- **Redis 8.10.1** — Scoop-ის portable ბინარი (არა Windows service; admin უფლება არ იყო ხელმისაწვდომი ამ მანქანაზე Memurai-ს/officiial installer-ის სერვისის რეგისტრაციისთვის). დეტალები `docs/implementation-status.md`-ში.
- `laravel/react-starter-kit`-ის `dev-main` branch-ზე დამოკიდებულობა **დროებითია** — როგორც კი ოფიციალური Laravel 13-თავსებადი tagged ვერსია გამოვა (`v1.1.0`+ სავარაუდოდ), `composer.json`-ის კონსტრეინტი უნდა შეიცვალოს tagged ვერსიაზე reproducibility-სთვის.
