# Implementation Status

განახლებულია: 2026-09-11 · ეტაპი 1 დასრულებულია (ბრენდის სინქრონიზაცია + სრული საჯარო საიტი, ახლა დიზაინთან მჭიდრო fidelity-თი — იხ. ქვემოთ); **ეტაპი 2 მნიშვნელოვნად დაწინაურებულია** (როლზე დაფუძნებული dashboard-ები ყველა როლისთვის — guardian/teacher/student/director/admin —, სრული sidebar/bottom-nav portal layout, დოკუმენტების ცენტრის Stage 1+2, კონტენტის მიგრაციის importer ყველა 92 source key-ს ცხადი გადაწყვეტილებით). `CLAUDE-PLATFORM-MODULES.md`-ის **Phase A ("საერთო საფუძველი და vertical slice") ამჟამად სრულადაა დაფარული**: PortalLayout/sidebar/mobile nav ✓, role/context (`PortalContext`) ✓, დღის ცენტრი (`BuildDailyActionFeed`) ✓, Messaging ✓, tenant/role/guardian auth ყველგან ✓. **Phase B დაწყებულია: Portfolio ✓** (draft→submit→publish/return, student/teacher/guardian ხილვადობის წესები — დეტალები ქვემოთ). **ადმინისტრაციული ხარვეზები (CMS UI, წევრების მართვა, მედიის ფაილების რეალური გადმოტანა) ამ ეტაპზე დახურულია** — დეტალები ქვემოთ. დანარჩენი Phase B-C-D-E (Assessment/Gradebook, Health, Pickup/Consent, Admissions CRM, Finance, Transport/Meals/Clubs, Platform white-label) ჯერ არ დაწყებულა — დეტალები `docs/design-parity-checklist.md`-ში. სრული ტესტ-suite: **190/190 passed, 843 assertions** (Pint/PHPStan level 7/`npm run types:check`/`npm run build` ყველა სუფთა). **ყველა ცვლილება production-ზეც დეპლოირებული და რეალურ ბრაუზერში/`curl`-ით დამოწმებულია** (`https://aisi.edu.ge`) — 19 რეალური მიგრირებული ფოტო ფაქტობრივად არსებობს production storage-ზე, hero-ს/header-ის/nav-ის ვიზუალური ბაგები გასწორებულია, **მასწავლებლების ცალკე ბლოკი აშენდა და 17 რეალური მასწავლებელი გამოქვეყნებულია**, **20 რეალური ამბავი + 10 რეალური გვერდი გამოქვეყნებულია (9 ყალბი WordPress-თემის Lorem-Ipsum პოსტი და ორმაგი/დუბლირებული გვერდი განზრახ არ გამოქვეყნებულა)**, და **ისტორიული სიახლეების რეალური თარიღები აღდგენილია** (ადრე ყველა publish ღილაკზე დაწკაპებისას "დღეს" თარიღი იწერებოდა).

### 2026-09-15 (გაგრძელება) — Substitution-ის production ვერიფიკაცია: ნაპოვნი root-cause ხარვეზი (`subjects`/`lessons` შექმნის გზის არარსებობა) და გასწორება

ზემოთ ჩაწერილი 2026-09-15-ის დეპლოის შემდეგ ცალკე სესიამ სცადა Substitution-ის create→assign→dashboard-ის ჯაჭვის რეალურ production-ზე ვერიფიცირება (SSH, read-only tinker) და ვერ შეძლო: production-ს ჰქონდა ზუსტად 1 მომხმარებელი (admin), **0** `school_classes`/`lessons`/`staff_absences`/`substitution_assignments` და **0** active tenant membership `role=teacher`-ით. გაცდენის დაფიქსირება მოითხოვს არსებულ მასწავლებელს (არცერთი არ არსებობს), ჩანაცვლების დანიშვნა კი — არსებულ `lesson_id`-ს.

**რეალური root-cause** (იგივე კლასის ხარვეზი, რაც 2026-09-14-ის Assignments-ის ფიქსში): `LessonController::store` და `routes/web.php`-ის `lessons.store` route უკვე არსებობდა და მუშაობდა (დადასტურებულია `tests/Feature/Timetable/LessonConflictTest.php`-ით), მაგრამ **სრულიად მიუწვდომელი იყო ნებისმიერი რეალური UI-დან** — `resources/js`-ში არცერთ გვერდს არასდროს გაუგზავნია მოთხოვნა ამ route-ზე (`grep`-ით დადასტურებული ნულოვანი დამთხვევა). უფრო მეტიც, **არცერთ route/controller-ს არასდროს შეეძლო `Subject`-ის შექმნა** (მხოლოდ `TenantSeeder`/`ProductionSeeder`) — `Lesson`-ს კი აუცილებლად სჭირდება `subject_id`. SubstitutionController-ის კომენტარში მცდარად ეწერა "lessons/attendance/teacher_assignments-ს already real" — ეს არასწორი დაშვება იყო; `Lesson`-ის შექმნის გზა არასდროს ყოფილა რეალურად აშენებული production-ისთვის.

**გასწორება**: ახალი `LessonController::index`/`storeSubject` + ახალი გვერდი `portal/timetable` (nav item „განრიგი", მხოლოდ academic_manager/admin — იგივე შეზღუდვა, რაც `LessonController::store`-ს უკვე ჰქონდა თავისივე docblock-ის მიხედვით). ახლა რეალურად შესაძლებელია: admin ქმნის საგანს → ქმნის გაკვეთილს (არსებული `lessons.store`-ის საშუალებით, ახლა პირველად რეალურად მიღწევადი) → აცხადებს მასწავლებლის გაცდენას → ხედავს "საჭიროებს შემცვლელს" worklist-ს → ნიშნავს შემცვლელს → შემცვლელი ხედავს dashboard-ზე. ბიზნესლოგიკა (`CheckLessonConflicts`, `AssignSubstitute`, `CheckSubstituteAvailability`) უცვლელი დარჩა — მხოლოდ create-გზა დაემატა, ზუსტად იგივე ნიმუშით, რითაც `AcademicStructureController` გაასწორა Assignments-ის root blocker.

**ამავე დროს ნაპოვნი და გასწორებული ცალკე ხარვეზი (tenant isolation)**: რადგან ეს ვალიდაცია ვხსნიდი production-ისთვის რეალურად ხელმისაწვდომს, შევამოწმე `LessonController::store`-ის საკუთარი ვალიდაცია — მას არასდროს შეუმოწმებია, რომ client-ის მიერ გამოგზავნილი `academic_year_id`/`school_class_id`/`subject_id`/`room_id` ეკუთვნის მოქმედ tenant-ს (მხოლოდ `teacher_id`-ის tenant-შემოწმება არასდროს არსებობდა და ეს განზრახ უცვლელი დავტოვე, რადგან `LessonConflictTest`-ის არსებული ფიქსტურები ამაზეა აგებული). CLAUDE.md-ის უცვლელი მოთხოვნა #1/#2-ის მიხედვით ("client tenant_id-ს ნუ ენდობი" — ეს ვრცელდება ნებისმიერ tenant-owned FK-ზეც) დავამატე ცხადი tenant_id-შემოწმება `StoreLessonRequest`-ში ამ ოთხივე ველისთვის — მანამდე academic_manager-ს შეეძლო სხვა სკოლის კლასის/საგნის/წლის/ოთახის id-ის გამოცნობით შეექმნა Lesson, რომელიც სხვა tenant-ის მონაცემს გაუჟონავდა თავისივე რელაციებით. ეს კოდი მანამდე არასდროს ყოფილა რეალურად exercised (unreachable იყო), ამიტომ production-ზე ეს არასდროს ყოფილა ფაქტობრივად ექსპლუატირებული — ეს არის preventive ფიქსი, დამატებული ზუსტად იმ მომენტში, როცა ეს გზა პირველად ხდება რეალურად მისაწვდომი.

**ასევე დამატებულია**: `Lesson`-ის შექმნა ახლა გადის `App\Domain\Timetable\Actions\CreateLesson`-ის გავლით (მანამდე inline იყო კონტროლერში, `AuditLogger`-ის გარეშე) — ეს ამატებს `lesson.created` აუდიტ-ჩანაწერს (CLAUDE.md-ის უცვლელი მოთხოვნა #7), თანმიმდევრულად `CreateAcademicYear`/`CreateSchoolClass`-ის არქიტექტურულ ნიმუშთან.

**ტესტები**: ახალი `tests/Feature/Timetable/TimetableManagementTest.php` (7 შემთხვევა) — role-gating, აუდიტ-ჩანაწერი, tenant-isolation (school_class/subject/academic_year/room — 4 ცალკე შემთხვევა), და სრული replay წინა broken-გზისა (subject→lesson→absence→substitutions-გვერდი, ყველა რეალური HTTP endpoint-ით). სრული suite: **217/217 passed, 1023 assertions** (Pint/PHPStan level 7/`npm run types:check`/`npm run build` ყველა სუფთა; `npm run check`-ის უკვე-არსებული 19-ფაილიანი ფორმატირების drift ჩემს ახალ/შეცვლილ ფაილებში არ ჩანს).

**ჯერ კიდევ არ არის დახურული (ცნობილი, ცალკე)**: production-ზე ჯერ არცერთ რეალურ მასწავლებელს არ მიუღია მოწვევა და არ დაუდასტურებია ანგარიში (`members`-ის უკვე არსებული invite-flow-ის საშუალებით) — ეს სკოლის საკუთარი გადაწყვეტილებაა (რეალური საშტატო განრიგი), არა ჩვენი მოგონილი მონაცემი. ასევე ამ სესიას არ ჰქონდა წვდომა production admin ანგარიშზე ვიზუალურად (ბრაუზერით) დასადასტურებლად — ეს ცალკე, ინფრასტრუქტურული საკითხია (production admin-ის ერთჯერადი პაროლი დაკარგულია; `sessions`-ის read-only წაკითხვაც დაბლოკილი იყო გარემოს permission-სისტემით), არა კოდის ხარვეზი; რეკომენდაცია: production admin-ისთვის დაემატოს დოკუმენტირებული, აუდიტირებადი პაროლის-აღდგენის Artisan command (`php artisan tinker`-ის ნაცვლად), რომ მომავალი ვერიფიკაციები აღარ იყოს ამაზე დაბლოკილი — ეს ცალკე, არა-production-write ცვლილებაა, ამ ფიქსის scope-ის მიღმა.

**Production deploy — 2026-09-15**: commit `6501db1` რეალურად დეპლოირებულია იმავე დადგენილი ნიმუშით: `npm run build` → `git diff --name-only 1720f0f..HEAD -- app database resources routes` სიით შეცვლილი 10 ფაილი (`database`-ში ცვლილება არ ყოფილა — Subject/Lesson/Room ცხრილები უკვე არსებობდა) + მთლიანი ახალი `public/build` → `scp` → `tar -xzf` → `clpctl system:permissions:reset --path=. --directories=770 --files=660` (ამჯერად საჭირო გახდა ცხადი `--path`/`--directories`/`--files` არგუმენტები — უარგუმენტო გამოძახება ახლა ცარიელი mode-ს გამო ჩავარდა; დოკუმენტირებულია აქ მომავალი დეპლოისთვის) → `storage/framework/views/*.php` წაშლა → `migrate --force` ("Nothing to migrate", schema უცვლელი) → `route:clear && route:cache` (ახალი `portal/timetable*` route-ებისთვის) → `config:clear && config:cache`.

`php artisan route:list --path=timetable` production-ზე ადასტურებს ორივე ახალ route-ს (`timetable.index`, `timetable.subjects.store`). Read-only tinker: `users=1`, `subjects=0`, `lessons=0`, `school_classes=0`, `staff_absences=0`, `substitution_assignments=0` — **უცვლელი** მანამდელთან შედარებით (ეს იყო code/schema-only დეპლოი, არცერთი production ჩანაწერი არ შეგვქმნია). Smoke checks: `/`, `/about`, `/learning`, `/school-life`, `/news`, `/library`, `/contact`, `/login`, `/robots.txt`, `/sitemap.xml`, `/teachers` → ყველა 200; `/portal/timetable`, `/portal/substitutions`, `/portal/academic-structure`, `/portal/members`, `/portal/assignments` → ყველა 302 → `/login` (სწორი, არაავტორიზებული სტუმრისთვის). დეპლოის დროებითი tar.gz სერვერიდან წაშლილია.

**კვლავ ღია დარჩენილი** (ინფრასტრუქტურული, არა კოდის ხარვეზი): ამ ან წინა სესიას არ ჰქონდა წვდომა production admin ანგარიშზე ბრაუზერით ვიზუალურად დასადასტურებლად (ერთჯერადი seed-პაროლი დაკარგულია; `sessions`-ის read-only წაკითხვა დაბლოკილი იყო გარემოს permission-სისტემით) — რეკომენდაცია ზემოთაა ჩაწერილი (დოკუმენტირებული admin-პაროლის აღდგენის Artisan command).

### 2026-09-15 — Teacher Substitution production deploy: ნაპოვნი MySQL identifier-length ხარვეზი და გასწორება

Teacher Substitution მოდულის (`42cff72` — `StaffAbsence`/`SubstitutionAssignment`, absence-report → assign → dashboard-ზე ხილვადობის ჯაჭვი) დეპლოის დაწყებისას აღმოჩნდა, რომ წინა სესიაში ეს დეპლოი უკვე ნაწილობრივ დაწყებული ყოფილიყო და შეწყვეტილიყო: production-ზე `migrate:status` აჩვენებდა `2026_09_22_000002_create_substitution_assignments_table`-ს **`Pending`**-ად, მაგრამ read-only `Schema::hasTable`/`Schema::getIndexes()`-ით დადასტურდა, რომ თვითონ ცხრილი უკვე **არსებობდა** production MySQL-ზე (სვეტები და foreign key-ები ხელუხლებელი) სამივე განზრახული ინდექსის (`tenant_id+substitute_teacher_id+date` და ორი დანარჩენი) გარეშე.

**Root cause**: production DB engine MySQL/Percona-ა (იხ. `docs/decisions/0001-stack-and-versions.md`); `Schema::index()`-ის ავტომატურად გენერირებული სახელი `(tenant_id, substitute_teacher_id, date)` სვეტების კომბინაციისთვის 64-სიმბოლოიან MySQL identifier ლიმიტს სცდებოდა. MySQL `CREATE TABLE`-ს მოსდევს ცალკე `ALTER TABLE ... ADD INDEX` თითო ინდექსზე — პირველი `CREATE TABLE` წარმატებით შესრულდა, მაგრამ ინდექსის დამატება ამ სახელის სიგრძის გამო ჩავარდა, migration-იც შუაზე შეწყდა და არასდროს მონიშნულა "Ran"-ად. ეს ზუსტად იგივე კლასის ხარვეზია, რაც `docs/decisions/0001-stack-and-versions.md`-შია დოკუმენტირებული.

**გასწორება** (`991534f`): ინდექსს ცალსახა მოკლე სახელი მიენიჭა (`substitution_assignments_substitute_date_idx`); migration ახლა იცავს ამ ზუსტ ნახევრად-დასრულებულ მდგომარეობას — თუ ცხრილი უკვე არსებობს, აღარ ცდილობს `Schema::create`-ს ხელახლა (რაც "table already exists"-ს გამოიწვევდა), არამედ მხოლოდ აკლიის სამივე ინდექსს `Schema::hasIndex()`-ის შემოწმებით.

**Production deploy — 2026-09-15**: commit `991534f` (fix) + `42cff72` (მოდული) რეალურად დეპლოირებულია (`npm run build` → `git diff --name-only 35bbd42..HEAD -- app database resources routes` სიით შეცვლილი `app`/`database`/`resources`/`routes` ფაილები + მთლიანი ახალი `public/build` → `scp` → `tar -xzf` → `clpctl system:permissions:reset` → `storage/framework/views/*.php` წაშლა → `migrate --force` (ინდექსების missing-ინდექსების დამატება, 83ms, batch 10, ახლა `Ran`) → **`route:clear && route:cache`** (ახალი `portal/substitutions*` route-ებისთვის, იხ. 2026-09-14-ის route-cache ცნობილი ბაგი) → `config:clear && config:cache`. `php artisan migrate:status` production-ზე ადასტურებს ორივე migration-ს `Ran`-ად; `Schema::getIndexes()` ადასტურებს სამივე ინდექსის რეალურად არსებობას; `route:list --path=substitutions` ადასტურებს ყველა 4 route-ს. Smoke checks: `/`, `/about`, `/learning`, `/school-life`, `/news`, `/library`, `/contact`, `/login`, `/robots.txt`, `/sitemap.xml`, `/teachers` → ყველა 200; `/portal/substitutions`, `/portal/academic-structure`, `/portal/members`, `/portal/assignments` → ყველა 302 login-ზე (სწორი). Read-only tinker: `users=1`, `staff_absences=0`, `substitution_assignments=0`, `tenants=1` **უცვლელი** — მხოლოდ schema შეიცვალა, არცერთი production ჩანაწერი არ შეგვქმნია. წინა შეწყვეტილი მცდელობიდან დარჩენილი `a-deploy-substitution-fix.tar.gz` (სერვერზე ატვირთული, მაგრამ არასდროს გაშლილი) და ორივე ლოკალური/დისტანციური deploy tarball წაშლილია.

**ლოკალურ ტესტ-suite-ში**: `php artisan test --filter=Substitution` → 5/5 passed, 56 assertions (fix-ის დამატების შემდეგ ხელახლა გაშვებული).

### 2026-09-14 — production-ზე Assignments-ის ვერიფიკაცია: ნაპოვნი root-cause ხარვეზი (`academic_years`/`school_classes` შექმნის გზის არარსებობა) და გასწორება

Assignments მოდულის (`dea9814`) production-ზე რეალური ვერიფიკაციისას (SSH-ით, read-only tinker-ით) დადასტურდა: production-ს ჰქონდა ზუსტად **1 მომხმარებელი** (`admin@aisi.edu.ge`) და **0** `teacher_assignments`/`school_classes`/`academic_years`/`students`/`tenant_invitations`. Route-ები, migration-ები, controller-ები და built frontend asset-ები რეალურად deploy-ილი იყო (`routes/web.php`-ში ყველა 8 assignment route, `assignments`/`assignment_submissions` ცხრილები, `assignments-*.js`/`my-assignments-*.js` bundle-ები) — მაგრამ create→submit→grade ციკლი **ვერანაირად ვერ გაივლიდა რეალურ HTTP endpoint-ზე**, რადგან:

- `AssignmentController@index` სწორად ითხოვს აქტიურ `teacher` როლს (ეს **არ** არის ბაგი — admin-ისთვის 403 სწორი ქცევაა, არა production-ის ხარვეზი).
- **რეალური root-cause**: არცერთ route/controller-ში არასდროს არსებობდა `AcademicYear`/`SchoolClass`/`Student` ჩანაწერის შექმნის გზა — მხოლოდ `TenantSeeder`/`ProductionSeeder` წერდა მათ. `MemberController`-ის მასწავლებლის მოწვევის ფორმა კლასის dropdown-ს ავსებს `SchoolClass::query()`-დან, რომელიც ცარიელი იყო; `TeacherAssignment` კი მხოლოდ მოწვევის accept-ით იქმნება `school_class_id`-ით — ანუ ცარიელ კლასების სიაზე მასწავლებლის მოწვევაც კი ვერ დასრულდებოდა რეალურად production-ზე.

**გასწორება**: ახალი `AcademicStructureController` (`app/Http/Controllers/Portal/AcademicStructureController.php`) + domain actions (`app/Domain/Academics/Actions/{CreateAcademicYear,CreateSchoolClass,CreateStudent}.php`) — admin/director/academic_manager-ს შეუძლია რეალურად, HTTP-ით შექმნას სასწავლო წელი (`is_current` ურთიერთგამომრიცხავია tenant-ში, ტრანზაქციაში), კლასი (academic_year-ის tenant-კუთვნილება მოწმდება) და მოსწავლე (school_class-ის tenant-კუთვნილება მოწმდება; `national_id` უნიკალურია tenant-ში, დუბლიკატი — validation error, არა crash). ახალი გვერდი `portal/academic-structure` (nav item „სასწავლო წლები" admin/director/academic_manager-ისთვის, `PortalContext`). ყველა ცვლილება `AuditLogger`-ში იწერება (`academic_year.created`/`school_class.created`/`student.created`).

ეს კონკრეტულად აღადგენს იმ გზას, რომელიც production-ის ვერიფიკაციამ დაბლოკილად დაადასტურა: admin ქმნის წელს→კლასს→მოსწავლეს→რეალურად იწვევს მასწავლებელს კონკრეტულ კლასზე (`MemberController`-ის უკვე არსებული flow) → მასწავლებელი accept-ავს → იქმნება რეალური `TeacherAssignment` → Assignment-ის შექმნა/publish/submit/grade ახლა შესაძლებელია ბოლომდე. ახალი ტესტი (`tests/Feature/Academics/AcademicStructureTest.php`, 8 შემთხვევა) სწორედ ამ ჯაჭვს ამოწმებს ბოლომდე (მათ შორის ორივე ახალი tenant-იზოლაციის შემთხვევა და დუბლიკატის validation), non-admin 403-ს, და is_current-ის ურთიერთგამომრიცხავობას.

**ტესტები ამ ცვლილების შემდეგ**: `php artisan test` → **206/206 passed, 922 assertions**; Pint/PHPStan (level 7) სუფთაა; `npm run types:check` სუფთაა; `npm run build` წარმატებული. (`npm run check` პროექტში ცალკე, ამ ცვლილებამდე არსებულ 18 ფაილში ავლენს ფორმატირების გადახრებს — ჩემი ახალი/შეცვლილი ფაილები არცერთში არაა; pre-existing drift, ამ fix-ის scope-ის მიღმაა.)

**პროცესის შენიშვნა**: Assignments-ის commit-ის (`dea9814`) შემდეგ არც `docs/implementation-status.md` განახლებულა, არც "production deploy evidence" commit გაკეთებულა (განსხვავებით Portfolio/Daily Action Feed-ისგან) — ანუ მოდული deploy-ილი იყო, მაგრამ არასდროს ბოლომდე არ ყოფილა რეალურად გავლილი. ეს ჩანაწერი ავსებს ორივე ხარვეზს.

**ჯერ კიდევ არ არის აშენებული** (ცნობილი, განზრახ): academic year/class/student **რედაქტირება/წაშლა** (მხოლოდ create); production-ზე admin-მა ჯერ არ შეუქმნია რეალური სასწავლო წელი/კლასი/მოსწავლე ამ ახალი UI-დან — ეს სკოლის საკუთარი გადაწყვეტილებაა (real academic year dates/roster), არა ჩვენი მოგონილი მონაცემი; ჩვენ მხოლოდ გზა გავხსენით.

**Production deploy — 2026-09-14**: commit `49f234b` რეალურად დეპლოირებულია (`npm run build` → შეცვლილი `app`/`routes` ფაილები + მთლიანი ახალი `public/build` → `scp` → `tar -xzf` → `clpctl system:permissions:reset` → **`route:clear && route:cache`** (აუცილებელი ახალი route-ებისთვის, იხ. 2026-09-14-ის ცნობილი route-cache ბაგი ზემოთ) **→** `config:clear && config:cache` → `migrate --force` ("Nothing to migrate", schema უცვლელია). `php artisan route:list --path=academic-structure` production-ზე ადასტურებს ყველა 4 ახალ route-ს რეგისტრირებულს. Smoke checks: `/`, `/about`, `/learning`, `/school-life`, `/news`, `/library`, `/contact`, `/login`, `/robots.txt`, `/sitemap.xml`, `/teachers` → ყველა 200; `/portal/academic-structure`, `/portal/members`, `/portal/assignments`, `/portal/my-assignments` → ყველა 302 login-ზე (სწორი, სტუმრისთვის). Read-only tinker-ით დადასტურებულია: `users=1`, `academic_years=0`, `school_classes=0`, `students=0` **უცვლელი** — ჩვენ არცერთი production ჩანაწერი არ შეგვიქმნია (school-ის საკუთარი რეალური მონაცემია, არა ჩვენი მოგონილი placeholder). დეპლოის დროებითი ფაილები (`aisi-deploy-*.tar.gz`, `public/build.old`, `~/deploy-tmp`) წაშლილია სერვერიდან.

### 2026-09-12 — მასწავლებელთა ცალკე ბლოკი, რეალური კონტენტის გამოქვეყნება, თარიღების გასწორება

მომხმარებლის მოთხოვნით: მასწავლებლებმა მიიღეს **საკუთარი Teacher domain** (არა generic CMS Page) — ცალკე admin-გვერდი (`/portal/teachers`), რეალური ინდივიდუალური საჯარო გვერდი (`/teachers/{slug}`) და კარუსელი მთავარ გვერდზე. ქართული სახელებისთვის სპეციალური slug-გენერაცია (Laravel-ის `Str::slug()` მხოლოდ ლათინურ დამწერლობას თარგმნის, ქართული სახელი ცარიელ სტრიქონად აქცევდა). ფოტო არასავალდებულოა — არცერთ რეალურ მასწავლებელს არ ჰქონდა ნამდვილი ფოტო ძველ საიტზე, ამიტომ ცარიელისას პატიოსანი ინიციალის-წრე ჩანს (არასდროს გამოგონილი ფოტო).

ასევე დეტალურად შემოწმდა და გამოქვეყნდა **მხოლოდ რეალური** მიგრირებული კონტენტი (20 ამბავი + 10 გვერდი + 17 მასწავლებელი) — ცხადად ამოღებულია 9 ყალბი WordPress-თემის დემო-პოსტი ("Dimply dummy text of the printing and typesetting industry...") და დუბლირებული/superseded გვერდები. ამ პროცესში ნაპოვნი იქნა **რეალური ბაგი**: `content:import` importer-ს ყოველთვის `published_at=null` ჰქონდა დაწერილი, მიუხედავად იმისა, რომ წყარო მონაცემებს რეალური ისტორიული თარიღი გააჩნდათ; publish-ღილაკი კი ყოველთვის "დღეს" თარიღს წერდა — შედეგად ყველა ისტორიული ამბავი (მათ შორის 2017-2021 წლების) production-ზე "დღეს" გამოქვეყნებულად ჩანდა. გასწორდა: importer ინახავს რეალურ თარიღს (თუნდაც draft-ის დროსაც), publish მოქმედება მას აღარ თელაცვლის, ხოლო `content:import`-ის ხელახლა გაშვებამ უკვე production-ზეც ავტომატურად გამოასწორა ყველა არასწორად "დღეს"-დათარიღებული პოსტი რეალურ თარიღებზე (2017-2026 დიაპაზონში).

190/190 ტესტი (4 ახალი), production-ზე რეალურად დამოწმებული Playwright-ით/production admin ანგარიშით.

### 2026-09-12 — ფერების შებრუნების ბაგის გასწორება + ყველა პორტალის dashboard-ის ხელახლა აშენება

მომხმარებელმა ცხადად აღნიშნა: (1) hero-ს ღილაკის/ბმულის ფერები `design/app/AisiConcept.tsx`-თან შედარებით შებრუნებულია, (2) admin/teacher/student dashboard-ები არასრულია და დიზაინს არ შეესაბამება. ორივე დადასტურდა რეალურ ბაგად: `.primary` ღილაკს დიზაინში აქვს narinჯისფერი ფონი+navy ტექსტი (ჩემს კოდში იყო პირიქით), `.text-link`-ებს — navy (ჩემში იყო narinჯისფერი). გასწორდა header-ის უკვე-სწორი ღილაკის pattern-ის მიხედვით. დიზაინის პორტალის ვიზუალური სისტემა (day-card/stat-grid/panel/lesson-row/notice-card) აშენდა ხელახლა-გამოყენებად კომპონენტებად და გამოყენებულია ყველა 5 dashboard-ზე რეალური მონაცემით (დემო-რიცხვები არასდროს კოპირებულა). 179/179 ტესტი, production-ზე დეპლოირებული და admin ანგარიშზე დამოწმებული. დეტალები `docs/design-parity-checklist.md`-ში.

### 2026-09-12 — თვითმომსახურების ვერიფიკაცია + საჯარო საიტის ვიზუალური სიზუსტის დამატებითი გასწორება

მშობელი/მოსწავლე თავად რეგისტრირდება და აცხადებს ვინაობას (პირადი ნომრით ან სახელით) — სისტემა ადარებს სკოლის ატვირთულ მოსწავლეთა ბაზას (`national_id`), ქმნის `enrollment_verification_requests` ჩანაწერს (auto-match ან admin-ის ხელით გადაწყვეტისთვის), და მხოლოდ admin/director-ის დამტკიცების შემდეგ იქმნება რეალური `TenantMembership`+`GuardianLink`/`Student.user_id`. 12 ტესტი, სრული Playwright golden-path (რეგისტრაცია→პრეტენზია→დამტკიცება→რეალური dashboard). ასევე გასწორდა hero heading-ის/ვერტიკალური ლეიბლის დაკარგული ვიზუალური სიზუსტე design-თან და `/register` გვერდის ქართული თარგმანი. 179/179 ტესტი, production-ზე დეპლოირებული და დამოწმებული. დეტალები `docs/design-parity-checklist.md`-ში.

### 2026-09-11 — CMS admin UI, წევრების მართვა, მედია სინქრონიზაცია (3 პარალელური აგენტი)

მომხმარებელმა შენიშნა: (1) ადმინისტრატორის მხარეს პრაქტიკულად არაფერი აშენებულა (dashboard პირდაპირ წერდა "CMS ... მალე იქნება ხელმისაწვდომი"), და (2) production-ზე კონტენტი/ფოტოები არ იყო სრულად გადატანილი. ორივე დადასტურდა რეალურად: production-ს ჰქონდა 50 გვერდი + 29 პოსტი, **ყველა draft**-ად, და **storage/app/public-ში საერთოდ არცერთი მიგრირებული ფოტო არ არსებობდა** (მხოლოდ ლოგო/hero). სამივე ხარვეზი გადაწყდა 3 პარალელური აგენტით (Git worktree იზოლაციით), თითოეული მე გადავამოწმე (ტესტები/Pint/PHPStan/build-ი ცალკე და merge-ის შემდეგ ერთად) სანამ master-ში შევუერთე:

1. **CMS (Pages/Posts/Media)** — `app/Http/Controllers/Portal/Cms{Page,Post,Media}Controller`, `SavePage`/`SavePost` (ყოველ შენახვაზე revision-ი), `ChangePageStatus`/`ChangePostStatus` (publish/unpublish, audit-logged), `RestorePageRevision`/`RestorePostRevision` (მხოლოდ კონტენტი, არასდროს status). წვდომა: editor/academic_manager/director/admin ავტორობს/ხედავს draft-ს, publish მხოლოდ academic_manager/director/admin-ს შეუძლია. Preview იყენებს ზუსტად იმავე public Inertia კომპონენტს — არასდროს დაშორდება რეალურ საიტს. **რეალურად ნაპოვნი და გასწორებული ბაგი**: `SavePageRequest` რეალურ HTTP-ზე მდუმარედ ყრიდა ბლოკის ყველა ველს გარდა `type`-ისა (Laravel-ის `validated()`-ის ცნობილი quirk).
2. **წევრების მართვა + მოწვევები** — `tenant_invitations` ცხრილი, admin/director-ის წევრების სია + მოწვევის ფორმა + გაუქმება/გაუქმება, საჯარო `invitations/{token}` accept-flow. `AcceptTenantInvitation` row-locked და **არასდროს** არ არეზეტავს არსებული ანგარიშის პაროლს — მოწვევის ბმული მხოლოდ ელფოსტის მფლობელობის დასტურია, არა authorization არსებული ანგარიშის დასაბრუნებლად. Guardian/Teacher მოწვევა ავტომატურად ქმნის შესაბამის `GuardianLink`/`TeacherAssignment`-ს.
3. **`content:sync-media`** — რეალურად აკოპირებს 317 დაქეშილი მედია-ფაილიდან რეზოლუციადს ფაილებს production-ის `storage/app/public`-ში (checksum გადამოწმებით manifest-თან, idempotent, არასდროს არ თელაცვლის ხელით დაყენებულ `cover_image_path`-ს). ასევე root-cause-ით ახსნილია 176-vs-141 media count discrepancy (WordPress REST API-ს ცნობილი quirk: `X-WP-Total` ითვლება per-item permission ფილტრამდე).

ასევე ამავე დროს დასრულდა (ცალკე, აგენტების მუშაობის დროს ადრე დაწყებული, uncommitted დარჩენილი) login გვერდის ქართულ ენაზე თარგმნა და ბრენდირებული auth layout.

### 2026-09-11 — Portfolio (`app/Domain/Portfolio`, `CLAUDE-PLATFORM-MODULES.md` §5)

draft→submitted→published/returned workflow; მოსწავლე ხედავს საკუთარს ყოველთვის, მასწავლებელი (მხოლოდ თავისი კლასის) — submitted/published-ს, მშობელი — მხოლოდ published-ს. 5 ტესტი + რეალურ ბრაუზერში სრული ციკლი დამოწმებული (student→submit→teacher publish+feedback→guardian view). **ცნობილი გარემოს შეზღუდვა**: ამ ლოკალურ Windows მანქანაზე `php artisan serve`-ის ჩაშენებულ dev-server-ს PHP-ის `UPLOAD_ERR_NO_TMP_DIR` ბაგი აქვს რეალურ browser-ფაილის ატვირთვაზე (production-ის nginx+php-fpm-ზე ეს პრობლემა არ არსებობს) — აპლიკაციის ატვირთვის ლოგიკა თავად სრულად არის დაფარული ავტომატური ტესტებით. დეტალები `docs/design-parity-checklist.md`-ში.

### 2026-09-11 — Messaging (`app/Domain/Communications`, `CLAUDE-PLATFORM-MODULES.md` §4)

პირველი რეალურად მომუშავე მოდული ახალი 12-დან: `conversations`/`conversation_participants`/`messages`/`message_deliveries`, `ResolveMessageableUsers` (ნათესაობაზე დაფუძნებული მიმღების არჩევანი — მშობელი↔შვილის მასწავლებელი/office-როლები, staff↔staff — არასდროს "ნებისმიერი tenant-ის მომხმარებელი"), `/portal/messages` UI (master-detail, unread badge, read-on-view). 8 ახალი ტესტი, რეალურ ბრაუზერში დამოწმებული (მშობელი→მასწავლებელი→პასუხი round-trip). დეტალები `docs/design-parity-checklist.md`-ში. **მხოლოდ ლოკალურად დამოწმებული ამ ჩანაწერის დროისთვის — production deploy შემდეგია.**

**რატომ Messaging და არა Daily Center პირველი**: `CLAUDE-PLATFORM-MODULES.md`-ის Daily Center ("დღის ცენტრი") კონცეპტუალურად აერთიანებს რამდენიმე წყაროს (გაკვეთილები, დოკუმენტების დასამტკიცებელი, შეტყობინებები, თანხმობები...). სანამ მხოლოდ ერთი რეალური "action"-წყარო არსებობდა (დოკუმენტების დამტკიცება), ცალკე აგრეგატორის აშენება ნაადრევი აბსტრაქცია იქნებოდა (CLAUDE.md-ის პრინციპი: "სამი მსგავსი ხაზი სჯობია ნაადრევ აბსტრაქციას"). ახლა, Messaging-ის დამატებით, აქვს აზრი Daily Action Feed-ის აშენებას — ეს არის უახლოესი კონკრეტული ნაბიჯი.

### 2026-09-11 — დიზაინის ზუსტი fidelity (`design/app/AisiConcept.tsx`-თან)

მომხმარებლის ცხადი მითითებით („გამოიყენე დამტკიცებული... ტექსტები ზუსტად ისე როგორც ფაილებია, არ გადაუხვიო"), საჯარო მთავარი გვერდი განახლდა AisiConcept.tsx-ის ტექსტთან/სექციებთან სიტყვასიტყვითი თანხვედრით (ადრე გამარტივებული ვერსია იყო):

- **ჰერო**: დაემატა რეალური ფოტო (`school-life.jpg` → `BrandSetting.hero_image_path`, ახალი idempotent `brand:sync-hero-image` command, იგივე პატერნი რაც `brand:sync-logo`-ს აქვს), floating card, hero-note. **დოკუმენტირებული გაფრთხილება კოდის კომენტარში**: ეს ფოტო არქიტექტურული ვიზუალიზაციაა, არა დადასტურებული დღევანდელი ფოტო — აქტუალობა/უფლება სკოლას ჯერ არ დაუდასტურებია (`docs/09-design-source-map.md`-ის ცხადი მითითებით).
- **ახალი `values` ბლოკის ტიპი** — 3-icon "ცოდნა/გარემო/შესაძლებლობა" ზოლი, ზუსტად reference-ის ტექსტით.
- **History ბლოკის ზუსტი ტექსტი**: "სკოლა აისი 2000 წელს... დამფუძნებელია პედაგოგიკის დოქტორი იანა ტორჩინავა" — ჩანაცვლდა წინა ფრთხილი placeholder-ი ("ეს ტექსტი დასაზუსტებელია..."), მომხმარებლის ცხადი გადაწყვეტილებით გამოეყენებინა დამტკიცებული ტექსტი ზუსტად. იგივე ტექსტი განახლდა `/about` გვერდზეც (`TenantSeeder`, `ProductionSeeder`).
- **Programs ბლოკი**: დაემატა ნომერი (01/02/03) და ფერადი აქცენტი თითო ბარათზე (reference-ის peach/blue/green), მაგრამ click→dialog დეტალი **არ აშენებულა** (მომავალი პოლიშისთვის დარჩა — checklist-ში მონიშნული).
- Real browser verification (Playwright, 360/390/768/1440px): ერთი რეალური mobile ბაგი ნაპოვნი და გასწორებული — hero-photo-ს caption pill (`bottom-3 right-3`) ეჯახებოდა floating card-ს ვიწრო ეკრანებზე; გადავიდა `top-3 right-3`-ზე, კონფლიქტი აღარ არის.
- ახალი, სავალდებულო `docs/design-parity-checklist.md` (`docs/09-design-source-map.md`-ის ფორმატით) — თითო ეკრანი/სექცია → route → component → backend → roles → states → responsive evidence → სტატუსი.

## რეალურად დამოწმებული, მუშა ფუნქციონალი

ყველა ქვემოთ ჩამოთვლილი შემოწმებულია ავტომატური ტესტებით (`php artisan test`) და რეალურ ლოკალურ სერვერზე Playwright headless ბრაუზერით (არა მხოლოდ დაწერილი კოდით).

### გარემო

- PHP 8.4.24 (Herd), Composer 2.10.2, Node 24.20.0.
- **PostgreSQL 17.11** — Windows service (`postgresql-x64-17`), ავტომატურად იწყება.
- **Redis 8.10.1** — Scoop-ის portable ბინარი; **არ არის Windows service** (admin არ იყო ხელმისაწვდომი) — სესიის დასაწყისში საჭირო: `powershell -File scripts/dev-services.ps1`.
- Laravel `laravel/react-starter-kit:dev-main`-იდან (Packagist tag ჯერ Laravel 12-ზეა): `laravel/framework v13.31.0`, `inertiajs/inertia-laravel v3.3.3`, `laravel/fortify v1.39.0`, `laravel/wayfinder v0.1.21`, React 19.2, TypeScript strict, Tailwind v4.
- Georgian UI: `APP_LOCALE=ka`, `<html lang="ka">` დამოწმებული რეალურ HTTP პასუხში.

### Production infrastructure — სერვერზე წვდომა და DB engine-ის გადაწყვეტილება (2026-09-10)

- მომხმარებელმა მოგვცა SSH წვდომა რეალურ CloudPanel-სერვერზე (`165.245.223.56`, site user `ais`, dedicated `~/.ssh/aisi_server_deploy` keypair, არა პირადი გასაღების ხელახალი გამოყენება). Read-only reconnaissance: PHP 7.1–8.5 ყველა ხელმისაწვდომია, Node.js არ არის, disk-ზე 68GB თავისუფალია, საიტი placeholder-ზეა (`/home/aisi/htdocs/aisi.edu.ge/public/index.php`).
- **აღმოჩენილი კონფლიქტი**: სერვერზე დაყენებულია მხოლოდ MySQL-თავსებადი Percona Server 8.4.10-10 — PostgreSQL საერთოდ არ არსებობს, site-user-ს root/sudo არ აქვს (`clpctl`-იც site-scoped) და დამოუკიდებლად ვერ დააყენებდით. ეს ეწინააღმდეგებოდა `CLAUDE.md`-ის თავდაპირველ PostgreSQL-ის დაშვებას.
- მომხმარებელს დაესვა კონკრეტული არჩევანი (AskUserQuestion) და აირჩია: **production-ის DB engine იცვლება MySQL/Percona-ზე**, ლოკალურად ტესტებისთვის ჯერჯერობით რჩება PostgreSQL/SQLite. სრული დასაბუთება/დეტალები → `docs/decisions/0001-stack-and-versions.md`-ის „განახლება 2026-09-10" სექცია.
- **რეალურად გადამოწმებული (არა თეორიულად)**: ლოკალურად აიგო MariaDB 12.3.3 (Scoop portable, დროებით, მხოლოდ ამ შემოწმებისთვის) და მასზე გაეშვა 26-ივე migration + სრული ტესტ-suite (`phpunit.xml`-ის დროებითი override-ით) → **73/73 გავლილი**. ერთი რეალური ბაგი ნაპოვნი და გასწორებულია: `database/migrations/2026_09_11_000007_create_teacher_assignments_table.php`-ის ოთხსვეტიანი unique constraint-ის auto-generated სახელი (70 სიმბოლო) აღემატებოდა MySQL-ის 64-სიმბოლოიან identifier-ლიმიტს — დაემატა ხელით მოკლე სახელი (`teacher_assignments_unique_assignment`). დანარჩენი მთელი schema (მათ შორის ყველა `json()` სვეტი და მრავალსვეტიანი unique constraint-ი) იმუშავა უცვლელად.
- MariaDB-ის ლოკალური ინსტანცია შემოწმების შემდეგ **გაჩერებულია** — ის არ არის ლოკალური dev-გარემოს მუდმივი ნაწილი; ლოკალურად კვლავ PostgreSQL/SQLite გამოიყენება.
- **2026-09-10: production deploy რეალურად შესრულებულია** მომხმარებლის პირდაპირი მითითებით („გაუშვი დეფლოი"). სრული, ნაბიჯ-ნაბიჯ ჩანაწერი, ორი გარემოსპეციფიკური ბაგი (რომლებიც deploy-ის დროს ნაპოვნი და გასწორებული იქნა) და დარჩენილი Cloudflare-ის blocker → `docs/deployment-and-rollback.md`-ის „რეალურად შესრულებული deploy" სექცია.
  - მოკლედ: 32 migration რეალურ Percona MySQL 8.4-ზე გავლილი; `ProductionSeeder`-ით tenant „აისი" + ერთი რეალური ადმინი + 5 გამოქვეყნებული გვერდი (**არცერთი დემო ანგარიში/tenant production-ში — გადამოწმებული `total_users=1`, `total_tenants=1`**); `content:import` -ით 44 draft (15 გვერდი + 29 პოსტი) ძველი საიტიდან; ყველა საჯარო route origin-ზე **200**, `/dashboard` → 302 login-ზე.
  - **საიტი საჯაროდ ჯერ არ ჩანს** — Cloudflare აბრუნებს `526 Invalid SSL Certificate`, რადგან origin-ს მხოლოდ CloudPanel-ის self-signed სერტიფიკატი აქვს. ეს **ჩვენს წვდომას გარეთაა** (საიტის `clpctl`-ს არ აქვს `lets-encrypt` ბრძანება, sudo/root არ არის) და მოითხოვს ან Cloudflare-ის SSL რეჟიმის „Full"-ზე გადართვას, ან ვალიდური სერტიფიკატის გამოშვებას CloudPanel-ის admin UI-დან.
- **security note**: სერვერის SSH პაროლი და MySQL DB პაროლი მომხმარებელმა chat-ში გამოაშკარა ტექსტად გამოგზავნა (CloudPanel-ის auto-generated credentials) — ეს არცერთ ფაილში/commit-ში/log-ში არ ჩაწერილა; მომხმარებელს ურჩიეთ ორივეს rotation.

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

### Identity & Family — ეტაპი 2 დაწყებულია (`app/Domain/Academics`)

- ცხრილები: `academic_years`, `school_classes`, `students`, `guardian_links` (**ცალკე revocable permission ბულეანები**: `can_view_academic`/`can_view_financial`/`can_pickup`/`can_receive_notifications`, არა ერთი "is guardian" flag), `teacher_assignments`.
- **`DashboardController`** (`GET /dashboard`, `auth`+`verified` middleware) — server-side განსაზღვრავს მომხმარებლის როლს მიმდინარე tenant-ში (`TenantMembership`) და აჩვენებს შესაბამის რეალურ მონაცემს:
  - **მშობელი** (`portal/parent-dashboard.tsx`): მხოლოდ საკუთარი, აქტიური `guardian_links`-ის ბავშვები (სახელი, კლასი, უფლებები). ბავშვის switcher რეალურია (არა დემო მასივი).
  - **როლის გარეშე მომხმარებელი** (`portal/no-role.tsx`): პატიოსანი ცარიელი მდგომარეობა, არა გამოგონილი კონტენტი.
  - `PortalLayout` — ბრენდის ტოკენებით, "ჩემი {tenant.name}", რეალური logout ღილაკი (`Form` + wayfinder `logout.form()`).
- **ტესტები** (`tests/Feature/Portal/`, 4): მშობელი ხედავს მხოლოდ საკუთარ ბავშვს (docs/02 კრიტიკული შემოწმება #2); **guardian_link-ის გაუქმება მაშინვე მალავს ბავშვს** (იგივე request-ში `is_active=false` → შემდეგი dashboard load 0 ბავშვს აბრუნებს); როლის გარეშე მომხმარებელი ცარიელ მდგომარეობას ხედავს; მასწავლებლის assignment query არასოდეს აბრუნებს სხვა მასწავლებლის კლასს (docs/02 კრიტიკული შემოწმება #3-ის query-pattern დონეზე).
- **დამოწმებულია რეალურ ბრაუზერში**: login (`parent@aisi.test` / `password`) → `/dashboard` → რეალური ბავშვის ბარათი ("ნიკა დემო-ის დღე", VI კლასი, უფლებების badge-ები), 0 failed request.
- **არ არის აშენებული**: სრული PortalLayout sidebar/bottom-navigation (docs/04-ის მაპინგი) — ეს მოვა schedule/library/payments ფუნქციონალთან ერთად; მასწავლებლის საკუთარი dashboard გვერდი (ამჟამად მხოლოდ query-pattern დამტკიცებულია ტესტით, არა UI); ადმინისტრაციის dashboard.

### განრიგი და დასწრება — ეტაპი 2 გაგრძელება (`app/Domain/Timetable`, `app/Domain/Learning`, `app/Domain/Governance`)

- ცხრილები: `subjects`, `rooms`, `lessons` (კვირის განმეორებადი slot — `starts_at`/`ends_at` სკოლის დროის სარტყელში, არა UTC, დანარჩენი "date-like" ველების ანალოგიურად), `lesson_exceptions` (cancelled/substitution/room_change), `attendance_records`, `audit_events`.
- **`CheckLessonConflicts`** (`app/Domain/Timetable/Actions`) — server-side ბლოკავს ერთდროულ overlap-ს იმავე მასწავლებელზე, ოთახზე ან კლასზე, `[start, end)` წესით (შეხების წერტილი კონფლიქტი არ არის). გამოძახებულია `StoreLessonRequest::withValidator`-იდან — ვალიდაციის შეცდომა, არა გამონაკლისი.
- **`LessonController@store`** — მხოლოდ `academic_manager`/`admin` როლს შეუძლია განრიგის შექმნა (`TenantMembership::userHasAnyActiveRole`).
- **`ResolveDailyLessons`** — კვირის განმეორებად lessons-ს კონკრეტულ თარიღზე გადააქცევს, `lesson_exceptions`-ის გათვალისწინებით (გაუქმება/ჩანაცვლება/ოთახის შეცვლა). გამოიყენება პარენტისა და მასწავლებლის dashboard-ებში.
- **`AttendanceController`** — მხოლოდ ლექციის საკუთარმა მასწავლებელმა (`lesson.teacher_id === auth user`) შეუძლია ნახოს/ჩაწეროს დასწრება; ერთი ჩანაწერი თითო (lesson, student, occurred_on) კომბინაციაზე (DB unique + upsert, არა დუბლიკატი); **ცვლილება იწერება `audit_events`-ში** (CLAUDE.md ინვარიანტი #7).
- **პორტალის dashboard-ები** ახლა რეალურ განრიგს აჩვენებენ: მშობელი ხედავს შვილის დღევანდელ გაკვეთილებს (თუ `can_view_academic`), მასწავლებელი — საკუთარ დღევანდელ გაკვეთილებს "დასწრება" ბმულით.
- **ტესტები** (`tests/Feature/Timetable/`, 9): იმავე მასწავლებლის/ოთახის overlap ბლოკირდება; საზღვრის შეხება (`09:45`→`09:45`) **არ** არის კონფლიქტი; სხვა დღეს არასდროს არ ეჯახება; მხოლოდ manager/admin ქმნის lesson-ს; დანიშნული მასწავლებელი წერს დასწრებას; **სხვა** მასწავლებელი 403-ს იღებს; ხელახალი submission ანახლებს არსებულ ჩანაწერს (არა დუბლიკატი); დასწრების ცვლილება წერს audit event-ს.
- **დამოწმებულია რეალურ ბრაუზერში**: login as `teacher@aisi.test` → დღევანდელი გაკვეთილი ჩანს → "დასწრება" → მოსწავლის მონიშვნა "ესწრება" → შენახვა → real success toast; login as `parent@aisi.test` → იგივე გაკვეთილი ჩანს ბავშვის დღევანდელ განრიგში სწორი მასწავლებლითა და ოთახით. 0 failed request.
- **არ არის აშენებული**: განრიგის რედაქტირების/წაშლის UI (`store` მხოლოდ), `lesson_exceptions`-ის შექმნის UI (schema/logic მზადაა, ფორმა არა), კვირის ბადის/დღის სრული ვიზუალური კალენდარი (ამჟამად მხოლოდ "დღეს" სიაა), მასწავლებლის კლასის სრული ჟურნალის ისტორიის ნახვის გვერდი.

### დოკუმენტების ცენტრი — Stage 1+2 (`app/Domain/Documents`)

`docs/07-document-management-spec.md`-ის Stage 1+2 (workspace/permissions/upload/version/preview საფუძველი + მასწავლებელი→დირექტორი single-reviewer დამტკიცების ნაკადი). **Stage 3-5 (publish-ის ცალკე ეტაპი, acknowledgement, template registry, მრავალსაფეხურიანი განხილვა, retention/OCR/გარე editor) განზრახ არ არის დაწყებული.**

- ცხრილები (`2026_09_13_0000{01..06}`): `document_workspaces`, `documents`, `document_versions`, `document_access_grants`, `approval_requests`, `approval_decisions`. `documents.latest_version_id`/`published_version_id` შეგნებულად არ არის DB foreign key (circular reference migration-ის დროს) — მთლიანობა დაცულია მხოლოდ Action-კლასებიდან ამ სვეტების ჩაწერით (`CreateDraftDocument`, `UploadDocumentVersion`, `DecideApproval`, `RestoreAsDraft`).
- ახალი role `TenantMembership::ROLE_DIRECTOR`, seed-ით `director@aisi.test`.
- `DocumentFileStorage`: private disk, tenant-prefixed path, რეალური MIME sniffing (არა client extension), 20MB ზღვარი, signed short-lived download.
- **რეალური immutability და concurrency დამოწმებულია ტესტით**: approved ვერსია არასდროს იცვლება ახალი draft-ის ატვირთვისას; ერთსა და იმავე `approval_requests` row-ზე ორი გადაწყვეტილება (`lockForUpdate()`-ით) — მეორე უარყოფილია 409-ით. Multi-connection race ნამდვილ production DB-ზე (MySQL/Postgres) დაცულია row-lock-ით; ეს sqlite in-memory ტესტ-გარემოში პირდაპირ ვერ სიმულირდება (ცნობილი ტესტ-შეზღუდვა, არა კოდის ხარვეზი).
- Portal routes `/documents*` + 4 React გვერდი (`index`/`show`/`my-work`/`director-worklist`), `teacher-dashboard.tsx`-ზე "დოკუმენტები" ბმული.
- ტესტები: `tests/Feature/Documents/DocumentWorkflowTest.php` → **14/14 passed, 40 assertions** (tenant-იზოლაცია, თვითდამტკიცების აკრძალვა, draft/published გამიჯვნა, ორმაგი გადაწყვეტილების უარყოფა, MIME/ზომა/quarantine/expired-URL, guardian-ს წვდომა არ აქვს).
- რეალურ ბრაუზერში დამოწმებული (Playwright, Postgres dev-ბაზაზე, fixture-ები ტესტის შემდეგ წაშლილია): მასწავლებელმა ატვირთა → submit → დირექტორმა დაამტკიცა → DB-ში დადასტურდა `published_version_id`. **გარემოს აღმოჩენა (არა ამ ფუნქციის ბაგი)**: `php artisan serve` + production build ერთად ვერ უმკლავდება ბრაუზერის ერთდროულ ბევრ asset-მოთხოვნას (ვლინდება `/login`-ზეც, ჩვენი კოდის გარეშე) — გამოსავალი რეალური `npm run dev` flow-ია.
- **ცნობილი, განზრახ გადადებული ხარვეზები**: `DocumentAccessGrant` ცხრილი არსებობს, მაგრამ არცერთ policy-შემოწმებაში ჯერ არ გამოიყენება (მხოლოდ base role: owner/director/admin); admin/director-ს არ აქვს საკუთარი dashboard ეკრანი (პირდაპირ URL-ით მოდის `/documents/director-worklist`-ზე); ტექსტური preview/OCR არ არსებობს, მხოლოდ metadata+download; ახალი workspace-ის შექმნის UI არ არსებობს (ერთი, "სასწავლო გეგმები", მხოლოდ seeder-შია).

### კონტენტის მიგრაცია — Content Import Command (`app/Domain/Content/Actions/ImportContentRecords`)

`docs/08-content-migration.md`-ის §6 (რეალური CMS importer უკვე შეგროვებული `content-migration/cms-import.json`-იდან — 92 ჩანაწერი, `collection-report.json`-ის მიხედვით 476/513 გვერდი და 317 asset რეალურად არქივირებული).

- ცხრილი `content_import_records` (`2026_09_13_000001`) — idempotency key `(tenant_id, source_system, source_key)`, morph კავშირი `Page`/`Post`-თან, `source_checksum`/`local_checksum` conflict-დეტექციისთვის; `Page`/`Post` მოდელები თავად უცვლელი დარჩა.
- `php artisan content:import {--tenant=} {--commit} {--only=pages,posts}` — default dry-run, `--tenant` სავალდებულო (არასდროს default tenant-ს არ ირჩევს), ყველაფერი შექმნილი უპირობოდ `draft`-ია (importer-ს არასდროს შეუძლია გამოქვეყნება).
- ტესტები: `tests/Feature/ContentImport/ImportContentRecordsTest.php` → **11/11 passed, 38 assertions** (dry-run=0 ცვლილება; idempotent მეორე commit; ლოკალურად რედაქტირებული გვერდი `conflict`-ად ინიშნება და არ გადაიწერება; tenant A/B იზოლაცია; უცნობი tenant slug ჩავარდება; დასახელებული merge-candidate ჯგუფები დროშავდება, არ ერწყმის ავტომატურად).
- **რეალურად ნაპოვნი და გასწორებული ბაგი**: პირველ ვერსიაში blocked ჩანაწერზე მეორე `--commit` აწარმოებდა unique-constraint crash-ს (idempotency lookup ხდებოდა blocked-შემოწმების შემდეგ, არა მის წინ) — გასწორებულია, რეგრესიის ტესტით დაფარული.
- **რეალურად გაშვებულია** ლოკალურ "aisi" tenant-ზე (არა თეორიულად): 44 draft Page/Post შექმნილია, 1 დაბლოკილი (ძველი placeholder-გვერდი, source id 54), 47 out-of-scope (`documents`/`teachers` ტიპები — შესაბამისი მოდელი ჯერ არ არსებობს, მონაცემიც არასანდოა). ხელით პროვოცირებული conflict (ლოკალურად რედაქტირებული გვერდი) სწორად აღინიშნა და არ გადაიწერა.
- **Data quality — ადამიანის განხილვა სჭირდება**: `posts`-ის რამდენიმე ჩანაწერი (source id 13, 30–36, 40) აშკარად WordPress თემის ინგლისურენოვანი დემო სტატიებია ("Harvard University Tops the Shanghai Ranking Again" და სხვ.), არა აისის რეალური სიახლე — importer-მა ისინი (სწორად) draft-ად შემოიტანა, მაგრამ განხილვისას უნდა დაარქივდეს, არ გამოქვეყნდეს.
- **განზრახ გადადებული**: 301 redirect-ების ჩართვა, media ბაიტების რეალური გადატანა (checksum/path მხოლოდ manifest-შია), OCR, `documents`/`teachers` ტიპების იმპორტი, ავტომატური merge (5 ცნობილი დუბლიკატი ცალკე draft-ადაა, გადაწყვეტილება ადამიანს რჩება), ნებისმიერი გამოქვეყნება.
- **მნიშვნელოვანი**: ლოკალურ dev ბაზაში ახლა რეალურად არსებობს 44 ახალი draft გვერდი/პოსტი, რომელთა სანახავად/დასამტკიცებლად **ჯერ არ არსებობს admin UI** (CMS editor ეკრანი კვლავ ცნობილი ხარვეზია — იხ. ქვემოთ). ისინი ხელმისაწვდომია მხოლოდ პირდაპირი DB query-თი/tinker-ით, სანამ editor screen არ აშენდება.

### Seed მონაცემები (`TenantSeeder`, მხოლოდ dev/local)

- Tenant "აისი": domains `localhost`/`127.0.0.1`/`aisi.test`; brand tokens; admin user; 5 გამოქვეყნებული Page; 2 Post; 3 LibraryResource; 1 academic year + 1 class + 1 student + 1 guardian (`parent@aisi.test`) + 1 teacher (`teacher@aisi.test`).
- მეორე, დამოუკიდებელი tenant ("second-school-demo") მხოლოდ იზოლაციის დასამტკიცებლად.

## ცნობილი ხარვეზები / ჯერ არ დამტკიცებული (ერთი, გასწორებული სია)

- **SSR node-პროცესი არ არის მუდმივად გაშვებული.** `config/inertia.php`-ში `ssr.enabled=true`; dev-ში (`vp dev`) SSR module graph ავტომატურად თბება და სრულ body-საც რენდერავს (`data-server-rendered="true"`) — ეს dev convenience-ია, **production-ისთვის persistent SSR პროცესი ჯერ არ არის მოწყობილი**. ამის ნაცვლად, production-ისთვის `app.blade.php` პირდაპირ კითხულობს `page.props.page.seoTitle/seoDescription`-ს და გამოაქვს რეალურ `<title>`/`<meta description>`-ად JS-ის გარეშეც (დამტკიცებული ტესტით `HomePageSeoTest`). **სრული body-content კვლავ მხოლოდ ჰიდრაციის შემდეგ ჩნდება production build-ში** — თუ crawler-ს/JS-less კლიენტს დასჭირდება სრული HTML, საჭირო იქნება `resources/js/ssr.tsx` + supervisor.
- **CMS admin editor (draft→preview→publish→revert UI) — მხოლოდ schema, არა ეკრანი.** ეს ახლა უფრო აქტუალურია: content-import command-მა უკვე შექმნა 44 რეალური draft გვერდი/პოსტი ლოკალურ dev ბაზაში, რომელთა განსახილველად/გამოსაქვეყნებლად admin UI ჯერ არ არსებობს (მხოლოდ პირდაპირი DB წვდომაა შესაძლებელი).
- დოკუმენტების ცენტრის Stage 3-5 (publish-ის ცალკე ეტაპი, acknowledgement, multi-reviewer, template registry, retention/OCR, გარე editor ინტეგრაცია) — განზრახ არ დაწყებულა; დეტალები ზემოთ.
- Content import-ის შემდეგი ეტაპები (301 redirects, media byte migration, `documents`/`teachers` ტიპები, ავტომატური merge) — განზრახ არ დაწყებულა; დეტალები ზემოთ.
- სრული ვიზიტის/მიღების ნაკადი (slot booking, ტევადობა, staff სამუშაო სია) — მხოლოდ საწყისი lead-ფორმაა.
- canonical/hreflang tags, ძველი საიტის 301-mapping — ჯერ არ არის (sitemap/robots უკვე მზადაა; `redirect-map.csv` მხოლოდ შემოთავაზებული რუკაა, გააქტიურებული არაა).
- შეფასებები/დავალებები, წიგნადის სრული ცირკულაცია — არ დაწყებულა.
- Finance (ეტაპი 4), Communications (ეტაპი 3), Platform onboarding (ეტაპი 5), Deployment/UAT (ეტაპი 6) — არ დაწყებულა.
- `logo-concept.png`/`school-life.jpg` კვლავ კონცეფციური მასალაა — production-მდე სჭირდება ვექტორიზაცია/სკოლის დადასტურება.
- Georgian date formatting (`toLocaleDateString('ka-GE')`) headless Chromium-ის ერთ ვარიანტში (`chrome-headless-shell`) ციფრულ ფორმატზე დაბრუნდა შეზღუდული ICU მონაცემების გამო (`9/9/2026` ნაცვლად ქართული თვის სახელისა); სრულ Chromium/რეალურ ბრაუზერებში `Intl`-ს ka-GE მხარდაჭერა სტანდარტულია — გადასამოწმებელია რეალურ მოწყობილობაზე.
- `php artisan serve` (single-threaded PHP built-in server) production build-თან ერთად ვერ უმკლავდება ბრაუზერის ერთდროულ ბევრ asset-მოთხოვნას (ვლინდება `/login`-ზეც, ახალი კოდის გარეშეც) — ლოკალური გადამოწმებისას აუცილებლად გამოიყენეთ `npm run dev`/`composer run dev`, არა production build + `artisan serve` კომბინაცია.

## შესრულებული ტესტები (ზუსტი, ბოლო გაშვება — 2026-09-10, დოკუმენტების ცენტრისა და კონტენტ-იმპორტის დამატების შემდეგ)

```
php artisan test                               → 98/98 passed, 362 assertions
./vendor/bin/pint --test                        → passed
./vendor/bin/phpstan analyse --memory-limit=1G  → 0 errors (level 7)
npm run types:check (tsc --noEmit)              → 0 errors
npm run check                                   → 0 warnings/errors, 80 files (design/, docs/, CLAUDE.md, content-migration/ excluded — vite.config.ts)
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

`php artisan migrate:fresh --seed` აღადგენს ორივე ტესტ-tenant-ს. სადემონსტრაციო ანგარიშებზე შესვლა (მხოლოდ ლოკალურად, `http://localhost:8000/login`): `admin@aisi.test` (ადმინი), `parent@aisi.test` (მშობელი → `/dashboard` აჩვენებს "ნიკა დემო"-ს), `teacher@aisi.test` (მასწავლებელი, dashboard UI ჯერ არა). პაროლი ყველასთვის — Laravel-ის `UserFactory`-ის სტანდარტული dev-ტესტების default (`Hash::make('password')`, ანუ სიტყვასიტყვით `password`; ეს არის framework-ის ჩვეულებრივი convention ტესტებისთვის, არა production-ის საიდუმლო). production seed-ში ეს მომხმარებლები/პაროლი არასოდეს არ უნდა გამოჩნდეს.

**ტესტების გაშვებამდე** დარწმუნდით `public/hot` არ არსებობს (dev server გამორთულია), თორემ Inertia SSR ერთვება ტესტების HTTP პასუხშიც და თან-ტეხავს string-ზუსტ SEO assertion-ებს (ეს ცნობილი, უვნებელი ეფექტია, არა production-ის ბაგი).

## შემდეგი კონკრეტული ამოცანა

1. **Staff/guardian invitations** — ამჟამად მშობელი/მასწავლებელი/admin მომხმარებლები მხოლოდ seeder-ით იქმნება; საჭიროა რეალური მოწვევის ნაკადი (ტოკენით, ვადიანი, ერთჯერადი).
2. **განრიგი და დასწრება** — `school_classes`/`students` სტრუქტურაზე დაშენება (timetable_versions, lessons, attendance ცხრილები).
3. CMS admin editor (draft/preview/publish/revert) რედაქტორის როლისთვის.
4. სრული ვიზიტის slot-ჯავშანი ტევადობის კონტროლით.
5. მასწავლებლის საკუთარი dashboard UI (query-pattern უკვე დამტკიცებულია ტესტით).
6. `docs/04`-ის დანარჩენი component-mapping (სრული PortalLayout sidebar, TimetableDay, InvoiceTable, AttendanceRegister) — ეტაპი 2-3-ის გაგრძელებისას.

## საჭირო მონაცემები გასაგრძელებლად

უცვლელი — იხილეთ `docs/02-product-and-platform-spec.md`-ის მე-14 თავი და `START-HERE-CLAUDE.md`-ის მე-9 თავი (credentials/school facts არ მოვიგონეთ).
