# გაშვება და rollback — გეგმა (ჯერ არ შესრულებული)

განახლებულია: 2026-09-11. **ეს დოკუმენტი აღწერს გეგმას, არა შესრულებულ ოპერაციას.** production-ზე გადასვლა, DNS ცვლილება ან real school data-ს import ამ პრომპტის საფუძველზე არ შესრულებულა (`START-HERE-CLAUDE.md` ეტაპი 6-ის მოთხოვნისამებრ).

## ამჟამინდელი მდგომარეობა

- აპლიკაცია მუშაობს მხოლოდ ლოკალურად, ერთ დეველოპერის მანქანაზე (`docs/implementation-status.md`).
- Staging/production გარემო ჯერ არ არსებობს. ჰოსტინგის მომწოდებელი არ არის არჩეული (იხ. `docs/integrations-and-open-decisions.md`).
- Git repository ლოკალურია; remote-ზე push არ მომხდარა.

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
