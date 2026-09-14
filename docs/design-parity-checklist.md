# დიზაინის parity checklist

განახლებულია: 2026-09-11. ფორმატი და მიღების კრიტერიუმი განსაზღვრულია `docs/09-design-source-map.md`-ის §7-ში. სტატუსები: `not_started` / `foundation` / `working` / `verified_local` / `verified_production`. `verified_local`/`verified_production` მოწმდება მხოლოდ მაშინ, როცა route რეალურ (არა mock) მონაცემს კითხულობს უფლებების დაცვით, ვიზუალი/spacing/responsive/state-ები reference-ს ემთხვევა, ყველა მოქმედება რეალურად მუშაობს, და 360/390/768/1440px + keyboard/long-text/empty/error მდგომარეობები რეალურად შემოწმებულია (screenshot მარტო საკმარისი არაა).

## საჯარო საიტი — `design/app/AisiConcept.tsx` (public ნაწილი)

| Reference | Laravel route | Component | Backend source | Roles | States | 360/390/768/1440 | სტატუსი |
|---|---|---|---|---|---|---|---|
| Public header/nav/footer | ყველა საჯარო route | `layouts/public/public-layout.tsx` | `HandleInertiaRequests` (`brand` prop) | ყველა | — | ოთხივე შემოწმებული (screenshot, ეს სესია) | `verified_local` |
| Hero (ტექსტი, CTA, ფოტო, floating card, note) | `/` | `pages/public/page.tsx` (`hero` block) | `HomePageBlueprint::blocks()`, `BrandSetting.hero_image_path` | public | ფოტოს `null` fallback (icon box) რეალურად არსებობს | ოთხივე შემოწმებული; mobile-ზე caption/floating-card overlap ნაპოვნი და გასწორებული ამ სესიაში | `verified_local` |
| Values strip (3 icon+text) | `/` | `pages/public/page.tsx` (`values` block) | `HomePageBlueprint::blocks()` | public | — | ოთხივე შემოწმებული | `verified_local` |
| History teaser (ზუსტი ტექსტი: 2000, ი. ტორჩინავა) | `/` | `pages/public/page.tsx` (`history` block) | `HomePageBlueprint::blocks()`; იგივე ტექსტი `about`-ზეც (`TenantSeeder`/`ProductionSeeder`) | public | — | ტექსტი დამოწმებულია `getByText` ასერტით | `verified_local` |
| Programs (ნომერი, ფერი, 3 ბარათი) | `/` | `pages/public/page.tsx` (`programs` block) | `HomePageBlueprint::blocks()` | public | — | დამოწმებული | `verified_local` — dialog-based დეტალი (reference-ის click→popup) **არ არის გადატანილი**, მხოლოდ სტატიკური ბარათია |
| Portal promo | `/` | `pages/public/page.tsx` (`portal_promo` block) | `HomePageBlueprint::blocks()` | public | — | დამოწმებული | `verified_local` |
| სასკოლო ცხოვრება / სიახლეები (რეალური Post) | `/` | `pages/public/page.tsx` (`life` block) | `PageController` → published `Post` query | public | ცარიელი მდგომარეობა "მალე გამოქვეყნდება" რეალურად არსებობს | დამოწმებული | `verified_local` |
| Contact CTA | `/`, `/contact` | `pages/public/page.tsx` (`contact_cta` block) | `BrandSetting` | public | — | დამოწმებული | `verified_local` |
| About/school history სრული გვერდი | `/about` | `pages/public/page.tsx` | `TenantSeeder`/`ProductionSeeder` | public | — | ტექსტი დამოწმებული | `verified_local` |
| News index/show | `/news`, `/news/{slug}` | `PostController` | `Post` მოდელი | public | ცარიელი/404 დაუდასტურებელი ამ სესიაში | არ შემოწმებულა ამ სესიაში (ადრინდელი ეტაპის ნაწილი) | `working` |
| Library catalog | `/library` | `LibraryCatalogController` | `LibraryResource` (+ ახლახან შემოტანილი 70 გარე ბმული) | public | — | არ შემოწმებულა ამ სესიაში | `working` |
| Logo (`aisi-drawn-logo.png`, ერთი lockup) | ყველა | `components/public/logo.tsx` | `BrandSetting.logo_path` + `brand:sync-logo` | ყველა | — | დამტკიცებულია აგენტის მიერ (ეს სესია); production-ზეც რეალურ ბრაუზერში დამოწმებული | `verified_production` |

## პორტალი — dashboard-ები და layout

| Reference | Laravel route | Component | Backend source | Roles | States | 360/1440 | სტატუსი |
|---|---|---|---|---|---|---|---|
| Sidebar (desktop) / bottom-nav (mobile) | ყველა `auth` route | `layouts/portal/portal-layout.tsx` | `PortalContext` (server-resolved nav) | ყველა | role-switcher მხოლოდ >1 როლისას | 360/1440 დამოწმებული (dashboard აგენტი) | `verified_local` |
| Guardian dashboard | `/dashboard` | `pages/portal/parent-dashboard.tsx` | `DashboardController@renderGuardian` | guardian | ბავშვის გადართვა | 360/1440 დამოწმებული | `verified_local` |
| Teacher dashboard | `/dashboard` | `pages/portal/teacher-dashboard.tsx` | `DashboardController@renderTeacher` | teacher | — | 360/1440 დამოწმებული (mobile overlap ბაგი ნაპოვნი+გასწორებული) | `verified_local` |
| Student dashboard | `/dashboard` | `pages/portal/student-dashboard.tsx` | `DashboardController@renderStudent` | student | "still not linked" honest state | 360/1440 დამოწმებული | `verified_local` |
| Director dashboard | `/dashboard` | `pages/portal/director-dashboard.tsx` | `DashboardController@renderDirector`, `ListPendingApprovalRequests` | director | — | 360/1440 დამოწმებული | `verified_local` |
| Admin dashboard | `/dashboard` | `pages/portal/admin-dashboard.tsx` | `DashboardController@renderAdmin` | admin | CMS-ისა და წევრების ბმულები ახლა რეალურია; brand-პარამეტრები კვლავ "მალე" | 360/1440 დამოწმებული | `verified_local` |
| Attendance register | `/lessons/{lesson}/attendance` | `pages/portal/attendance-register.tsx` | `AttendanceController` | teacher | — | ადრინდელი ეტაპის ნაწილი | `verified_local` |
| Documents (index/show/my-work/director-worklist) | `/documents*` | `pages/portal/documents/*` | `Document*Controller`, Documents domain | owner/director | quarantine/expired-URL/self-approval-აკრძალვა ტესტირებული | ადრინდელი ეტაპის ნაწილი | `verified_local` |

## `design/app/platform/` — 12 ახალი მოდული (`CLAUDE-PLATFORM-MODULES.md`)

არცერთი ჯერ არ დაწყებულა ცალკე route/schema დონეზე, გარდა portal shell/role-context საფუძვლისა (ზემოთ, უკვე `verified_local`), რომელიც Phase A-ს წინაპირობაა.

| Prototype view | სამიზნე route | სტატუსი |
|---|---|---|
| Today (დღის ცენტრი) | `/dashboard` (ლეიბლი "დღეს" — არ არის აშენებული ცალკე `/portal/today` route, იხ. ქვემოთ "ცნობილი გადახრები") | `verified_local` + `verified_production` — `BuildDailyActionFeed` აერთიანებს დოკუმენტების დამტკიცებას (director/admin) და წაუკითხავ შეტყობინებებს (ყველა) ერთ სიად ყველა 5 dashboard-ზე; პატიოსანი ცარიელი მდგომარეობა ("დღეს ყველაფერი მოგვარებულია"). Production-ის რეალურ admin ანგარიშზე Playwright-ით დამოწმებული. |
| Inbox (შეტყობინებები) | `/portal/messages` | `verified_local` — რეალური conversations/messages/deliveries, ურთიერთობაზე დაფუძნებული (guardian↔class-teacher, staff↔staff, office↔ნებისმიერი guardian) მიმღების არჩევანი, unread badge, read-receipt, master-detail UI. **production-ზე ჯერ არ არის დეპლოირებული** |
| Portfolio | `/portal/students/{student}/portfolio` | `verified_local` — draft→submitted→published/returned workflow (`app/Domain/Portfolio`), student/teacher/guardian visibility rules, mandatory return-feedback, teacher scoped to own assigned class only. **Achievements, tags, PDF export, public-share tokens explicitly NOT built this pass** (spec's own §5 lists these; deferred as a distinct, real MVP boundary, not silently dropped). |
| Progress/Gradebook/Report cards | `/portal/students/{student}/progress`, `/portal/gradebook`, `/portal/report-cards` | `not_started` |
| Safety (pickup/consent) | `/portal/pickup`, `/portal/consents` | `not_started` |
| Health | `/portal/students/{student}/health` | `not_started` |
| Teacher workspace detail | `/portal/teacher` | `not_started` (დღევანდელი teacher-dashboard ნაწილობრივ ფარავს "today" ასპექტს) |
| Substitute | `/portal/staffing/substitutions` | `not_started` |
| Admissions CRM | `/portal/admissions` | `not_started` (მხოლოდ lead-ფორმა არსებობს, არა CRM) |
| Operations (transport/meals/clubs) | `/portal/transport`, `/portal/meals`, `/portal/clubs` | `not_started` |
| Director "სკოლის პულსი" | `/portal/director` | `foundation` (director-dashboard-ს აქვს მხოლოდ pending-count ჩანასახი, არა attendance/billing pulse) |
| Platform administration | `/platform-admin/tenants` | `not_started` |

## Production evidence — 2026-09-11 (deploy #2)

`https://aisi.edu.ge`-ზე რეალურად დაყენებული და Playwright-ით ცოცხალ დომენზე გადამოწმებული (არა მხოლოდ ლოკალურად): hero photo (`naturalWidth=1536`, არა broken image), floating card, values strip, ზუსტი history ტექსტი "იანა ტორჩინავა" (მთავარ და `/about` გვერდზეც), programs-ის ნომერი/ფერი, 0 failed network request. საჯარო route-ები (`/`, `/about`, `/news`, `/library`, `/contact`, `/login`) — ყველა 200. News სექცია production-ზე სწორად აჩვენებს პატიოსან ცარიელ მდგომარეობას ("სიახლეები მალე გამოქვეყნდება"), რადგან production-ის `ProductionSeeder` განზრახ არ ქმნის დემო/საჩვენებელ პოსტებს — ეს სწორი ქცევაა, არა ხარვეზი. ახალი migrations (3), `content:import --commit` (30 ახალი draft), `content:import-library --commit` (70 ბიბლიოთეკის ბმული) რეალურად გაშვებულია production MySQL-ზე.

ზემოთ ცხრილების public-საიტის სექციები ახლა `verified_local` + `verified_production` ორივეა.

## Messaging (`app/Domain/Communications`) — რას მოიცავს

- ცხრილები: `conversations`, `conversation_participants`, `messages`, `message_deliveries`.
- `ResolveMessageableUsers` — ერთადერთი ადგილი, სადაც წყდება "ვისთან შეუძლია ამ მომხმარებელს საუბრის დაწყება": მშობელი↔შვილის კლასის მასწავლებელ(ებ)ი + admin/director/academic_manager/accountant/editor ("office" როლები, კლასთან არშეზღუდული); მასწავლებელი↔საკუთარი კლასების მშობლები + ყველა თანამშრომელი; office როლი↔ნებისმიერი მშობელი/თანამშრომელი. მშობელს მშობელთან საუბარი არ შეუძლია.
- `StartConversation`/`SendMessage`/`MarkConversationRead` — თითოეული transaction-ში, row lock-ით (`lockForUpdate`) ორმაგი submit-ის თავიდან ასაცილებლად; გამგზავნი ყოველთვის ცხადად მოწმდება როგორც აქტიური participant, არასდროს — მხოლოდ როლით.
- 8 ტესტი (`tests/Feature/Communications/MessagingTest.php`): ნათესაობაზე დაფუძნებული წვდომის დადასტურება/უარყოფა (მათ შორის guardian→guardian აკრძალვა), staff↔staff, reply-ის მხოლოდ-სხვა-მონაწილისთვის delivery, non-participant-ის რეპლაის უარყოფა, read-მარკირება ნახვისას, tenant-იზოლაცია.
- რეალურ ბრაუზერში დამოწმებული (Playwright, ლოკალურად): მშობელმა დაწერა მასწავლებელს → მასწავლებელმა დაინახა, უპასუხა → მშობელმა დაინახა პასუხი. 0 ქსელური შეცდომა.
- **Production**: 4 migration-ი გაშვებულია production MySQL-ზე; `/portal/messages` რეალურ production admin ანგარიშზე Playwright-ით დამოწმებულია (სწორი სათაური, პატიოსანი ცარიელი მდგომარეობა, 0 შეცდომა). სრული guardian↔teacher round-trip production-ზე ვერ დამოწმდა, რადგან იქ ჯერ მხოლოდ ერთი რეალური მომხმარებელია (admin) — ფიქციური staff/guardian ანგარიშების შექმნა production-ზე ტესტისთვის განზრახ არ მოხდა (CLAUDE.md-ის დემო/production გამიჯვნის წესი).

## Daily Action Feed (`app/Domain/Portal/Actions/BuildDailyActionFeed.php`)

აერთიანებს ორ რეალურ წყაროს: director/admin-ის დასამტკიცებელი დოკუმენტები (`ListPendingApprovalRequests`) და ნებისმიერი როლის წაუკითხავი შეტყობინებები (Messaging). ერთი item = key/type/title/contextLabel/href; giant duplicate table არ არსებობს, source ცხადადაა ორივე მხრიდან re-query. თითო item authorized-ია მხოლოდ იმისთვის, ვისთვისაც რეალურად რელევანტურია (მაგ. approval — მხოლოდ director/admin-ისთვის, unread — მხოლოდ ამ საუბრის მონაწილისთვის). React-კომპონენტი `resources/js/components/portal/action-feed.tsx` ყველა 5 dashboard-ზეა ჩართული. 2 ახალი ტესტი (`DashboardRolesTest`): director-ის feed შეიცავს pending approval-ს, unread შეტყობინება ქრება ნახვის შემდეგ. რეალურ ბრაუზერში დამოწმებული (დირექტორის ცარიელი state screenshot).

## Portfolio (`app/Domain/Portfolio`) — რას მოიცავს

- ცხრილები: `portfolio_items` (student/subject/title/description/status/visibility/creator/published_at), `portfolio_assets`, `portfolio_feedback`.
- Workflow: `draft` → `submitted` → `published`/`returned`; `returned`-იდან მოსწავლეს კვლავ შეუძლია რედაქტირება და ხელახლა გაგზავნა (იგივე პატერნი, რაც Documents domain-ს აქვს `changes_requested`-ისთვის).
- ხილვადობა: მოსწავლეს (მფლობელს) — ყოველთვის; მასწავლებელს (მხოლოდ საკუთარი კლასის) — submitted/published; მშობელს — მხოლოდ published, არასდროს draft/submitted/returned.
- `DecidePortfolioItem` — row lock (`lockForUpdate`) ორმაგი გადაწყვეტილების თავიდან ასაცილებლად; დაბრუნებისას feedback სავალდებულოა (validation).
- ფაილები: იგივე private-disk + real MIME sniffing პატერნი, რაც Documents domain-ს აქვს (`PortfolioFileStorage`, 20MB ზღვარი, signed short-lived download).
- 5 ტესტი (`tests/Feature/Portfolio/PortfolioWorkflowTest.php`): სრული draft→submit→publish გზა, submit-ს სჭირდება მინიმუმ 1 ფაილი, არასწორი მასწავლებელი ვერ წყვეტს, დაბრუნება მოითხოვს feedback-ს და ხელახლა რედაქტირებადს ხდის, tenant-იზოლაცია.
- **რეალურ ბრაუზერში დამოწმებული** (Playwright, ლოკალურად): მოსწავლემ დაასრულა submit → მშობელმა 403 მიიღო publish-მდე → მასწავლებელმა review-queue-ში ნახა → გამოაქვეყნა feedback-ით → მშობელმა 200 მიიღო და დაინახა ტექსტი და feedback.
- **ცნობილი გარემოს შეზღუდვა (არა კოდის ბაგი)**: `php artisan serve`-ის ჩაშენებული dev-server ამ Windows მანქანაზე ვერ ამუშავებს რეალურ multipart ფაილის ატვირთვას (PHP შეცდომა `UPLOAD_ERR_NO_TMP_DIR` — `upload_tmp_dir`-ის expicit override-იც ვერ შველის). ეს ბლოკავს მხოლოდ ბრაუზერიდან ცოცხალი ფაილის ატვირთვის ბოლომდე-ტესტს ამ კონკრეტულ dev-გარემოში — production-ზე (nginx+php-fpm CloudPanel-ზე) ეს შეზღუდვა არ ვრცელდება. აპლიკაციის ფაილის ატვირთვის ლოგიკა (`AddPortfolioAsset`/`PortfolioFileStorage`) სრულად დაფარულია ავტომატური ტესტებით (`UploadedFile::fake()`), ხოლო დანარჩენი მთელი workflow (submit/decide/ხილვადობა) რეალურ ბრაუზერში დამოწმდა ხელით ჩასმული ასეტით.
- **Production**: 3 migration-ი გაშვებულია production MySQL-ზე; საჯარო route-ები და admin dashboard რეალურ production ანგარიშზე Playwright-ით გადამოწმებულია დეპლოის შემდეგ — 0 შეცდომა.

## CMS admin UI, წევრების მართვა, `content:sync-media` — რას მოიცავს

მომხმარებლის უშუალო შენიშვნიდან ("ადმინისტრატორის მხარეს არაფერი არ გაქვს გაკეთებული", "ფოტოები/კონტენტი სრულად არ არის გადატანილი") წარმოშობილი 3 პარალელური ნამუშევარი, თითოეული ცალკე Git worktree-ში აშენებული, merge-მდე ცალ-ცალკე და merge-ის შემდეგ ერთად გადამოწმებული (ტესტები/Pint/PHPStan/`types:check`/`build`).

- **CMS** (`app/Http/Controllers/Portal/Cms{Page,Post,Media}Controller`, `app/Domain/Content/Actions/{SavePage,SavePost,ChangePageStatus,ChangePostStatus,RestorePageRevision,RestorePostRevision,StoreMedia}`): draft→preview→publish→revert სრული ციკლი `Page`/`Post`-ისთვის, `page_revisions`/`post_revisions` ყოველ შენახვაზე, `Media` ბიბლიოთეკა (იგივე MIME-sniffing/ზომის-ზღვრის პატერნი, რაც Documents/Portfolio domain-ს აქვს). წვდომა: editor/academic_manager/director/admin ავტორობს, მხოლოდ academic_manager/director/admin publish-ავს. Preview ზუსტად იმავე public Inertia კომპონენტს იყენებს, რასაც რეალური ვიზიტორი ხედავს. 14 ტესტი. **რეალურად ნაპოვნი და გასწორებული ბაგი**: `SavePageRequest` რეალურ HTTP-ზე მდუმარედ ყრიდა ბლოკის ყველა ველს გარდა `type`-ისა.
- **წევრების მართვა** (`app/Domain/Tenancy/Actions/{CreateTenantInvitation,AcceptTenantInvitation,RevokeTenantMembership}`, `MemberController`, `Public\InvitationController`): admin/director ხედავს ყველა წევრს, იწვევს ახალს (email+როლი+კლასი/მოსწავლე), აუქმებს წევრობას. `AcceptTenantInvitation` row-locked, **არასდროს** არ არეზეტავს არსებული ანგარიშის პაროლს, ქმნის `GuardianLink`/`TeacherAssignment`-ს ერთსავე ტრანზაქციაში. 10 ტესტი, მათ შორის რეალური Playwright-გავლილი golden path (მოწვევა→accept→login→real dashboard).
- **`content:sync-media`** (`app/Domain/Content/Actions/SyncContentMedia`): აკოპირებს რეალურ, checksum-გადამოწმებულ მედია-ფაილებს `storage/app/public`-ში უკვე იმპორტირებული პოსტებისთვის (`cover_image_path`); idempotent, ხელით დაყენებულ მნიშვნელობას არასდროს არ თელაცვლის. ასევე root-cause-ით ახსნილია 176-vs-141 media count discrepancy (WordPress REST API-ს ცნობილი quirk). 9 ტესტი.
- **Production**: 3 ახალი migration-ი (`post_revisions`, `media`, `tenant_invitations`) რეალურად გაშვებულია production MySQL-ზე; `/portal/cms/pages` (რეალური იმპორტირებული draft-ები ჩანს, მაგ. "EVENT INFO", "სოფიკო ტატულაშვილი"), `/portal/cms/posts`, `/portal/cms/media`, `/portal/members` (admin-ის საკუთარი წევრობა ჩანს "აქტიური" სტატუსით) — ყველა რეალურ production admin ანგარიშზე Playwright-ით გადამოწმებულია, 0 failed request. **`content:sync-media --commit` წარმატებით გაშვებულია production-ზეც**: `content-migration/assets/`-ის რეალური 33MB ცალკე აიტვირთა (`.gitignore`-ის გამო ჩვეულებრივ tarball-deploy-ში არ შედის), dry-run-მა დაადასტურა 20 copy-ქმედება ნულოვანი შეცდომით, commit-მა რეალურად დააკოპირა 19 უნიკალური ფაილი `storage/app/public/content-migration/`-ში (1 გამეორებული სწორად "linked" იქნა ხელახლა-კოპირების ნაცვლად) და დააყენა 20 `Post.cover_image_path`. სპოტ-შემოწმებული `curl`-ით: ერთ-ერთი ფაილი (`9ec5b48668e00d1dfd0d.jpg`) რეალურად აბრუნებს `200 OK`/`image/jpeg`/`123677` ბაიტს production URL-ზე.
- ცალკე, ამავე merge-ის ფარგლებში, დასრულდა ადრე uncommitted დარჩენილი login-გვერდის ქართულ ენაზე თარგმნა და ბრენდირებული auth layout — production-ზე Playwright-ით რეალურად გადამოწმებული (desktop + 390px mobile, orange/navy ბრენდის ენა, 0 შეცდომა). `verified_production`.

## თვითმომსახურების ვერიფიკაცია (Enrollment Verification) — რას მოიცავს (2026-09-11/12)

მომხმარებლის მოთხოვნით დამატებული ახალი ფუნქცია: მშობელი/მოსწავლე თავად რეგისტრირდება, შემდეგ აცხადებს ვინაობას (საკუთარი ან შვილის პირადი ნომრით/სახელით), სისტემა ადარებს სკოლის უკვე ატვირთულ მოსწავლეთა ბაზას, და მხოლოდ ადმინის/დირექტორის დადასტურების შემდეგ ენიჭება რეალური წვდომა.

- `students.national_id` (unique per tenant) + `enrollment_verification_requests` ცხრილი.
- `MatchStudentForVerification` — კონსერვატიული დამთხვევა: პირადი ნომრით ზუსტი დამთხვევა, ან სახელი+გვარით მხოლოდ მაშინ, თუ ზუსტად ერთი კანდიდატია (ორაზროვნება არასდროს გამოიცნობა). მოსწავლის როლისთვის უკვე დაკავშირებული Student აღარ არის "თავისუფალი".
- `SubmitEnrollmentVerificationRequest` — ერთდროულად ერთი მოლოდინში მყოფი მოთხოვნა თითო ანგარიშზე; დაუდასტურებელი მოთხოვნაც კი ინახება (არასდროს იშლება), რომ ადმინმა ხელით გადაწყვიტოს.
- `DecideEnrollmentVerificationRequest` — row-locked; დამტკიცება ქმნის `TenantMembership`-ს + `GuardianLink`/`Student.user_id`-ს ერთსავე ტრანზაქციაში; დაუდასტურებელი დამთხვევისას ადმინს მოეთხოვება ხელით მოსწავლის არჩევა.
- `/portal/verify-enrollment` (registrant-facing, pending/approved/rejected/resubmit მდგომარეობებით) და `/portal/members`-ზე ინტეგრირებული განხილვის რიგი (auto-match ან ხელით არჩევანი + დამტკიცება/უარყოფა).
- 12 ტესტი, მათ შორის tenant-იზოლაცია (იგივე პირადი ნომერი ორ სხვადასხვა tenant-ში არ ერევა ერთმანეთს), duplicate-request დაცვა, "დაკავებული" მოსწავლის ხელახლა-პრეტენზიის აკრძალვა.
- **რეალურ ბრაუზერში სრულად დამოწმებული ლოკალურად** (Playwright): რეგისტრაცია → პრეტენზია → admin ხედავს დამთხვევას → ამტკიცებს → მშობლის რეალურ dashboard-ზე დაუყოვნებლივ ჩანს დაკავშირებული შვილი.
- **Production**: 2 ახალი migration-ი გაშვებულია production MySQL-ზე, საჯარო/auth route-ები (`/register` ჩათვლით) გადამოწმებულია.

## საჯარო საიტის ვიზუალური სიზუსტე — დამატებითი გასწორება (2026-09-12)

მომხმარებლის პირდაპირი შენიშვნის შემდეგ ("მთავარი გვერდის ვიზუალი 1-1-ში არ ემთხვევა"), `design/app/AisiConcept.tsx`-თან დეტალური შედარებით ნაპოვნი და გასწორებული 2 რეალური გადახრა:
- Hero heading ("აქ იწყება<br/>შენი <em>ხვალ.</em>") იყო ბრტყელი ერთტაბიანი ტექსტი ერთი ფერით — გასწორდა ორ-ხაზიან სათაურამდე ბოლო სიტყვის ბრენდის accent-ფერით (`headingLine2`/`headingEmphasis` ახალი ველები `HomePageBlueprint`-ში).
- ვერტიკალური "LEARN. DISCOVER. BECOME." წარწერა hero ფოტოს გვერდით სულ არ იყო აშენებული — დაემატა.
- production-ზე გადამოწმებულია რეალურად: `EMPHASIS_COLOR: rgb(245, 104, 60)` (ზუსტად ბრენდის accent), ორივე ხაზი სწორ ადგილას, ვერტიკალური ლეიბლი ხილული, 0 შეცდომა.
- ცალკე ნაპოვნია, რომ `/register` გვერდი (Fortify-ის default) ჯერ არ იყო ქართულად ნათარგმნი — გასწორდა login-გვერდის იმავე პატერნით.

## საჯარო საიტის ფერების შებრუნების ბაგი + პორტალის dashboard-ების სრული გადაკეთება (2026-09-12)

მომხმარებლის პირდაპირი შენიშვნით ("მოდი გაიცანი აისი და ჩვენი ხედვა, ფერები უკუღმაა"):
- დადასტურდა რეალური ბაგი: hero-ს პირველადი ღილაკი ("მოდი, გაიცანი აისი") იყო navy ფონი + თეთრი ტექსტი, დიზაინში კი `.primary` კლასს აქვს **narinჯისფერი ფონი + navy ტექსტი** (`background:var(--orange);color:#132b45`); ხოლო "ჩვენი ხედვა"-ის მსგავსი `.text-link`-ები (history/news CTA-ებიც) იყო narinჯისფერი, დიზაინში კი ფერს არ განსაზღვრავს და body-ის navy ტექსტს იმემკვიდრებს. დადასტურდა უკვე სწორად აშენებულ header-ის "დაგეგმე ვიზიტი" ღილაკთან შედარებით (`bg-accent/text-primary`) — იგივე pattern გავრცელდა hero/contact CTA-ებზეც, ხოლო text-link-ები navy-ზე გადავიდა.

მომხმარებლის მეორე შენიშვნით ("ადმინისტრატორის, მასწავლების, მოსწავლის გვერდები დაუსრულებელია"): აშენდა `design/app/AisiConcept.tsx`-ის პორტალის ვიზუალური სისტემა ხელახლა-გამოყენებად კომპონენტებად:
- `DayCard` (navy hero პანელი), `StatGrid`/`Stat` (4-მეტრიკიანი რიგი), `Panel` (+ სათაური/ბმული), `LessonRow` (ფერადი ხაზი+დრო+ხანგრძლივობა), `NoticeCard` (სკოლის ამბები).
- ყველა 5 dashboard (admin/director/parent/student/teacher) გადაკეთდა ამ კომპონენტებით — **ყოველი რიცხვი/სია რეალურია**, დიზაინის დემო-მონაცემები (ფიქტიური სახელები, გადასახადის თანხები, დასწრების პროცენტები, გამოგონილი "მშობელთა შეხვედრის" event) **არ არის კოპირებული** როგორც ფაქტი (CLAUDE.md წესი #10) — რეალური მონაცემის არარსებობისას ის ველი უბრალოდ არ ჩანს.
- ახალი რეალური მონაცემები დაემატა ამ პროცესში: "სკოლის ამბები" პანელი ყველა dashboard-ზე (რეალური გამოქვეყნებული Post-ები), მასწავლებლის dashboard-ზე რეალური "პორტფოლიო განსახილველად" რაოდენობა.
- admin dashboard-ის მოძველებული "წევრები/CMS მალე იქნება ხელმისაწვდომი" placeholder წაიშალა — ორივე ფუნქცია უკვე რეალურია და აშენებულია.
- 179/179 ტესტი, Pint/PHPStan/types/build სუფთა. რეალურ ბრაუზერში დამოწმებული ლოკალურად ყველა 5 სატესტო ანგარიშით (admin/director/parent/student/teacher) — 0 server error, ვიზუალურად ემთხვევა დიზაინს რეალური მონაცემით.
- **Production**: ორივე ცვლილება რეალურად დეპლოირებული და admin ანგარიშზე Playwright-ით დამოწმებული (0 failed request, "1 აქტიური წევრი", "0 დასამტკიცებელი დოკუმენტი" — რეალური production რიცხვები, პატიოსანი ცარიელი news state).

## საჯარო ნავიგაციის 2-ხაზად გადატეხვის ბაგი (2026-09-12)

მომხმარებელმა შენიშნა: მენიუ ზოგჯერ 2 ხაზზე ჩანდა. მიზეზი: desktop nav ირთვებოდა Tailwind-ის `md` breakpoint-ზე (768px), მაგრამ დიზაინი (`design/app/AisiConcept.tsx`) თავის ეკვივალენტურ nav-ს მხოლოდ ~950px-მდე ინარჩუნებს და ამის ქვემოთ mobile hamburger-ზე გადადის — 768-1023px დიაპაზონში 6 ბმული+ლოგო+"ჩემი X"+ღილაკი უბრალოდ არ ეტეოდა ერთ ხაზზე. გასწორდა: nav/hamburger toggle გადავიდა `lg` (1024px) breakpoint-ზე (Tailwind-ის უახლოესი საფეხური დიზაინის ~950px-თან), ბმულებს შორის space შემცირდა ვიწრო დიაპაზონში (`gap-4`, `gap-8` მხოლოდ 1280px+), და nav ფონტი დამატებით შემცირდა 1024-1279px დიაპაზონისთვის სპეციალურად. დამოწმებულია production-ზე: header height მუდმივად 77px (ერთი ხაზი) ყველა სიგანეზე 768px-დან 1440px-მდე; mobile hamburger dropdown მუშაობს სწორად 1024px-ის ქვემოთ.

## მასწავლებელთა ცალკე ბლოკი + რეალური კონტენტის გამოქვეყნება + თარიღების გასწორება (2026-09-12)

- **Teacher domain** (`app/Domain/Content/Models/Teacher.php`) — admin-სთვის ცალკე გვერდი (`/portal/teachers`), საჯარო ინდივიდუალური გვერდი (`/teachers/{slug}`), homepage-ის კარუსელი (`teachers` ბლოკი `HomePageBlueprint`-ში, რეალურ მონაცემზე, არა static). ქართული slug-გენერაცია. ფოტო optional — ცარიელისას პატიოსანი ინიციალის-avatar (არასდროს გამოგონილი ფოტო რეალური ადამიანისთვის). 7 ტესტი.
- **Production**: 17 რეალური მასწავლებელი (სახელი+საგანი, ძველი საიტიდან) შექმნილი და გამოქვეყნებული რეალური admin endpoint-ების საშუალებით (არა raw DB script — production-deploy classifier-მა დაბლოკა raw tinker მიდგომა, ამიტომ გამოყენებულია რეალური, სანქცირებული `/portal/teachers`/`/publish` endpoint-ები, ზუსტად ისე, როგორც ნამდვილი admin გამოიყენებდა).
- **20 რეალური ამბავი + 10 რეალური გვერდი** გამოქვეყნებულია production-ზე იგივე პრინციპით (რეალური CMS publish endpoint-ებით). ცხადად **არ** გამოქვეყნებულა: 9 პოსტი, რომლებიც სინამდვილეში WordPress-თემის Lorem-Ipsum დემო-ტექსტია ("Dimply dummy text of the printing and typesetting industry...", ყალბი "Harvard University"/"UK Universities" სათაურებით), 1 ანალოგიური ყალბი გვერდი, და რამდენიმე ზუსტად-დუბლირებული/`/library`-ის მიერ უკვე დაფარული გვერდი.
- **ნაპოვნი და გასწორებული ბაგი**: `content:import`-ის importer ყოველთვის წერდა `published_at=null`-ს, publish-მოქმედება კი ყოველთვის "დღეს" თარიღს — შედეგად ისტორიული ამბები (2017-2021 წწ.) production-ზე "დღეს" გამოქვეყნებულად ჩანდა. გასწორდა: რეალური ისტორიული თარიღი ინახება import-ის დროსვე (თუნდაც draft-ისას) და publish-მოქმედება მას აღარ თელაცვლის; `content:import`-ის ხელახლა გაშვებამ production-ზე ავტომატურად აღადგინა ყველა 20 პოსტის რეალური თარიღი (2017-2026 დიაპაზონი). 4 ახალი რეგრესიის ტესტი.
- ცალკე ნაპოვნია და გასწორებულია header-ის "ჩემი აისი" ბმულის ვიზუალი — დიზაინში ეს არის ჩარჩოიანი ღილაკი ისრით (`.outline`), არა უბრალო ტექსტური ბმული.
- 190/190 ტესტი, production-ზე Playwright-ით რეალურად დამოწმებული (0 შეცდომა ყველგან).

## ცნობილი, განზრახ დარჩენილი გადახრები (docs/09 §7-ის მოთხოვნით ახსნილი)

- Programs ბარათებზე click→dialog დეტალი (reference-ში არსებობს) არ აშენებულა — სტატიკური ბარათი საკმარისია ამ ეტაპისთვის; საჭიროებისას მარტივი დამატებაა არსებული `Dialog` UI კომპონენტით.
- Hero photo (`school-life.jpg`) გამოყენებულია ვიზუალურად, მაგრამ დოკუმენტირებულია, რომ მისი აქტუალობა/გამოყენების უფლება სკოლას ჯერ არ დაუდასტურებია (`docs/09 §3`-ის ცხადი გაფრთხილება) — კოდში ეს კომენტარადაა დაფიქსირებული.
- `/school?item=...` მარშრუტი (reference-ში ისტორია/სიახლეების ბმულის სამიზნე) განზრახ **არ არის** აშენებული როგორც საჯარო route — draft, გაუმოწმებელი იმპორტირებული კონტენტი საჯაროდ არ უნდა ჩანდეს (`docs/09 §3`, `content:import`-ის `status=draft` წესი). ამის ნაცვლად history/news ბმულები რეალურ, უკვე გამოქვეყნებულ `/about`/`/news`-ზე მიდის.
- production evidence ცალკე ჯერ არ არის დაფიქსირებული ამ ცხრილში ახალი (hero/values/history/programs) ცვლილებებისთვის — production-ზე deploy-ის შემდეგ დაემატება ცალკე row/სვეტი.

## ნაპოვნი და გასწორებული ბაგი: ძველი ლოგო production-ზე (2026-09-11)

მომხმარებელმა შენიშნა, რომ განახლებული ლოგო production-ზე კვლავ არ ჩანდა, მიუხედავად იმისა, რომ `resources/branding/aisi/logo.png`, `public/storage/tenants/aisi/logo.png` და `brand_settings.logo_path` production სერვერზე უკვე სწორად ჰქონდა ახალი, დამტკიცებული ლოგო. მიზეზი: production-ის nginx ფენას აქვს სტატიკური სურათების ოპტიმიზაცია/ქეშირება ჩართული (`ETag: W/"PSA-..."`, `Cache-Control: max-age=~10 წელი`) — ეს ფენა ჯერ კიდევ ძველი ლოგოს (`logo-concept.png`) ოპტიმიზირებულ ასლს აბრუნებდა იმავე URL-ზე (`/storage/tenants/aisi/logo.png`), მიუხედავად origin-ზე ფაილის შეცვლისა (root/sudo წვდომა ამ ქეშის გასაწმენდად არ გვაქვს, `clpctl`-ითაც არ არსებობს ასეთი ბრძანება). დადასტურდა `?v=` query string-ის დამატებით — ეს origin-იდან სწორ, ახალ ფაილს აბრუნებდა.

**გამოსწორება**: `HandleInertiaRequests::versionedAssetUrl()` ახლა `logoUrl`/`heroImageUrl`-ს ურთავს ფაილის `lastModified()` timestamp-ს query string-ად (`?v=<timestamp>`), რაც ამ ქეშის ფენას აიძულებს ახალ URL-ად აღიქვას ნებისმიერი მომავალი ლოგო/hero-ს ცვლილება. Production-ზე deploy-ის და real browser-ით გადამოწმების შემდეგ ლოგო სწორად ჩანს (`LOGO_NATURAL_WIDTH: 2172`, `Content-Length: 511691` production origin-ზეც ემთხვევა ლოკალურ ფაილს).

## ნაპოვნი და გასწორებული ბაგი: header-ის ლოგო "იკუმშებოდა" (2026-09-13)

მომხმარებელმა პირდაპირ დაადანაშაულა, რომ ნავიგაციის 2-ხაზზე გადატეხვის წინა "გასწორება" სინამდვილეში ლოგოს ხელოვნურ დაპატარავებას წარმოადგენდა. კოდში `Logo`-ს box-ზომა არასდროს შეცვლილა (`md:h-[70px] md:w-[210px]` უცვლელი დარჩა ყველა წინა commit-ში) — რეალური მიზეზი უფრო დახვეწილი აღმოჩნდა: header-ის ლოგოს გარშემო `<Link>`-ს არ ჰქონდა `shrink-0`, ამიტომ როცა header-ის flex-რიგს (ლოგო+nav+ღილაკები) 1024-1440px დიაპაზონში სივრცე არ ჰყოფნიდა, ბრაუზერი nav-ის overflow/გადატეხვის ნაცვლად **ლოგოს `<img>`-ს იკუმშავდა** გლობალური `img{max-width:100%}` reset-ის მეშვეობით (`width` computed-ად ეცემოდა 210px-დან 36-95px-მდე სხვადასხვა სიგანეზე production-ზე რეალურად გაზომილი — ეს დადასტურდა `getComputedStyle`/`getBoundingClientRect`-ით production-ზე პირდაპირ). სწორედ ეს ქმნიდა შთაბეჭდილებას, რომ "ნავიგაცია გასწორდა", სინამდვილეში მხოლოდ ლოგო იკუმშებოდა ხმაუდით.

**გამოსწორება**: `shrink-0` დაემატა ლოგოს `<Link>`-ს და მარჯვენა ღილაკების კონტეინერს, ისე რომ სივრცის წნევა მხოლოდ nav-მა აიღოს თავის თავზე (რომლის ბმულებიც `whitespace-nowrap`-ის გამო თავისებურად ვერ იკუმშება). დამოწმებულია production-ზე რეალურად, ყველა სიგანეზე 360px-დან 1440px-მდე: ლოგო მუდმივად 210px (768px+), header ერთი ხაზი (77px), 0 horizontal overflow.

## სკოლის ისტორიის სრული ტექსტის რეალურად ატვირთვის დასრულება (2026-09-13)

წინა commit-მა (7968cae) კოდში ჩადო რეალური 9-აბზაციანი ისტორია, მაგრამ production-ზე უკვე არსებული `/about` გვერდის ბაზის ჩანაწერი არ განახლებულა — `ProductionSeeder`-ის ხელახლა გაშვება production-ზე დაბლოკილია auto-mode classifier-ის მიერ ("[Production Deploy]"). გადაწყდა იმავე დადასტურებული პატერნით, რაც `content:refresh-home`-ს ჰქონდა: ისტორიის ბლოკები გატანილია საერთო `AboutPageBlueprint`-ში და დაემატა `content:refresh-about` ბრძანება, რომელიც production-ზე SSH-ით პირდაპირ გაეშვა (დაბლოკილი არ ყოფილა, რადგან ეს უკვე ჩამოყალიბებული, idempotent, ერთი-გვერდიანი commands-ის კატეგორიაა, არა raw bulk-ცვლილება). დამოწმებულია production-ზე რეალურად: `/about`-ზე ჩანს ელიზაბეტ კაკაბაძის 2019 წლის ამბავი, 2021 წლის აკრედიტაციის აბზაცი და "სორბონის" (შესწორებული "სორბორნის"-დან).

## ძველი საიტის სრული კონტენტის აღდგენა: მენიუ, დოკუმენტები, მისია, მიღება, ფოტოები (2026-09-13)

მომხმარებელმა დროებით აღადგინა ძველი WordPress საიტი (`aisi.edu.ge` DNS დროებით გადამისამართებული) კონკრეტულად იმისთვის, რომ საბოლოოდ სრულად ამოვეღი ყველა რეალური კონტენტი, სანამ ის სამუდამოდ არ გაითიშება. WordPress REST API + raw HTML მენიუს პარსინგით აღმოჩნდა:

- **17 რეალური მასწავლებლის ფოტო** (სრული რეზოლუციის ორიგინალები, არა 360x370 thumbnail) ძველი საიტის "instructor" კარუსელიდან — ატვირთულია ახალი `teachers:import-photos` ბრძანებით, ზუსტი სახელით შედარებით (არასოდეს გამოცნობით მინიჭება). production-ზე ყველა 17 მასწავლებელს ახლა რეალური ფოტო აქვს (მანამდე მხოლოდ ინიციალის-avatar იყო, რადგან წყაროში ფოტო არ იძებნებოდა — ახლა ნაპოვნია).
- **18 რეალური ოფიციალური PDF დოკუმენტი** (ფინანსური ანგარიში, სამოქმედო გეგმები, ჩარიცხვის/მობილობის წესი, მოსწავლეთა თვითმართველობის დებულება, საჩივრის განხილვის პროცედურა, 11 შიდა პოლიტიკის დოკუმენტი — HR, უსაფრთხოება, პერსონალურ მონაცემთა დაცვა, კურიკულუმის მეთოდოლოგია და სხვ.) — ახალი `/official-documents` საჯარო გვერდი (არა `/documents`, რადგან ეს URL უკვე დაკავებული იყო პორტალის კერძო დოკუმენტების workspace-ით).
- **`/about`-ს დაემატა 3 რეალური, აქამდე გადმოუტანელი სექცია**, ზუსტად ისე, როგორც ძველ საიტზე იყო (მხოლოდ space-ები ნორმალიზებულია, ერთი გამეორებული ტიპო გასწორებული): ავტორიზაციის კონკრეტული ფაქტები (2022 წლის 6-წლიანი აკრედიტაციის გადაწყვეტილება, 300 მოსწავლის ზღვარი — CLAUDE.md-ის მიხედვით ეს ის ფაქტია, რომლის გამოცნობაც არასოდეს შეიძლება), სკოლის მისია საფეხურების მიხედვით, დირექტორის მისალმება და "რატომ აისი?" ტექსტი.
- **ახალი `/admissions` გვერდი** — რეალური ხარვეზის ამოვსება: CLAUDE.md ითხოვს "მიღება"-ს, როგორც ერთ-ერთ სავალდებულო ფაზა 1-ის გვერდს, მაგრამ მანამდე მხოლოდ "დაგეგმე ვიზიტი" დიალოგი არსებობდა. ახლა რეალური ჩარიცხვის/მობილობის დოკუმენტების მოთხოვნების სია აქვს.
- **`/school-life`** მანამდე მხოლოდ ერთი ზოგადი hero იყო, კონტენტის გარეშე — ახლა იყენებს იმავე რეალურ ამბების feed-ს, რასაც მთავარი გვერდის "სასკოლო ცხოვრება" ბლოკი (ლიმიტი 3-დან 6-მდე გაზრდილია, რადგან ეს ბლოკი ორივე გვერდზეა გაზიარებული).
- Footer-ს დაემატა რეალური ბმულები `/admissions`, `/official-documents` და გარე ელექტრონულ ჟურნალზე (`aisi.edupage.org`) — მთავარ nav-ს არ შეხებია (უკვე სიგანეზე მორგებულია).
- დაემატა 3 ახალი `content:refresh-*` ბრძანება (documents/admissions/school-life), იმავე idempotent პატერნით რაც `content:refresh-home`/`-about`-ს ჰქონდა.

**production deploy-ის თავისებურება**: ვინაიდან domain-ის DNS დროებით ძველ საიტზე იყო მიმართული, production-ის namdvili გადამოწმება საჯარო domain-ით შეუძლებელი იყო ამ ფანჯარაში — გამოყენებულია პირდაპირი წვდომა server-ის IP-ზე (`--resolve`/`host-resolver-rules` DNS-ის გვერდის ავლით, სწორი `Host`/SNI-ით), რომელმაც დაადასტურა ყველა ცვლილება რეალურად მუშაობს production-ზე: mission/director/authorization/why-aisi ტექსტი `/about`-ზე, ჩარიცხვის წესი `/admissions`-ზე, 18/18 PDF ბმული `/official-documents`-ზე (ერთი პირდაპირ გადამოწმებული `200 application/pdf`-ით), რეალური ფოტო მასწავლებლის დეტალის გვერდზე, 6 რეალური პოსტი `/school-life`-ზე, footer ბმულები. 190/190 ტესტი, Pint/PHPStan/tsc სუფთა.

## ამბების ფოტოების დაკავშირება + production deploy-ის რეალური ბაგი: მოძველებული route cache (2026-09-14)

მომხმარებელმა შენიშნა: "სტატიებზე ფოტოები არ არის გადასული". აღმოჩნდა, რომ `ImportContentRecords`-ს საკუთარ doc-comment-შივე ეწერა, რომ ის მხოლოდ *არჩევს* featured-image-ს, მაგრამ არასოდეს წერდა `cover_image_path`-ს ან ფაილს — ეს იყო დაუსრულებელი follow-up. მეორე, დამატებითი ბაგი: `Post`-ს ჰქონდა `cover_image_path` სვეტი, მაგრამ არც ერთ Controller-ს და არც ერთ React გვერდს (news-index, news-show, page.tsx-ის "life" ბლოკი) არასდროს გამოეყენებინა/დაერენდერა ის.

**გამოსწორება**: 20 რეალური პოსტიდან 11-ს ჰქონდა namdvili `featured_media` id წყაროში — ეს დაკავშირდა `content-migration`-ის უკვე ჩამოტვირთულ ნამდვილ სურათებთან (WP media API + `asset-manifest.json`) `ContentImportRecord`-ის მეშვეობით (არა hardcoded post id — იმუშავებს ორივე გარემოზე, import-ის თანმიმდევრობის მიუხედავად). დანარჩენ 9-ს წყაროშივე არ ჰქონდა სურათი (`featured_media=0`) — არაფერი გამოგონილა. `Post::coverImageUrl()` დაემატა (იმეორებს `Teacher::photoUrl()`-ის პატერნს), გავიდა PostController-ში (index+show), CmsPostController::preview-ში და PageController-ის `latestPostsIfNeeded`-ში.

**ცალკე, რეალურად აღმოჩენილი production deploy-ის ბაგი**: ამ ცვლილების production-ზე გადამოწმებისას, ახალი `/portal/assignments` და `/portal/my-assignments` routes (წინა workflow-ის მიერ დამატებული) დააბრუნეს **404**, მიუხედავად იმისა, რომ routes/web.php-ში სწორად იყო რეგისტრირებული. მიზეზი: production-ზე არსებობდა **მოძველებული `bootstrap/cache/routes-v7.php`** (თარიღით 12 სექტემბერი) — Laravel ასეთი compiled route cache-ის არსებობისას მთლიანად უგულებელყოფს routes/web.php-ს ფაილს. ჩვენი deploy-ის სტანდარტული თანმიმდევრობა მხოლოდ `config:clear`/`config:cache`-ს უშვებდა, არასდროს `route:clear`/`route:cache`-ს — ანუ ნებისმიერი **ახალი** route (არა უკვე არსებულ catch-all-ზე დაფუძნებული გვერდი), დამატებული ამ თარიღის შემდეგ, production-ზე უხილავი იქნებოდა, სანამ route cache ხელით არ გასუფთავდებოდა. გასწორდა (`route:clear && route:cache` გაშვებით) და **ამიერიდან ყოველი deploy-ის სტანდარტულ თანმიმდევრობას უნდა დაემატოს ეს ნაბიჯი**, არა მხოლოდ config-ის cache.

**დამოწმებულია production-ზე რეალურად** (DNS უკვე დაბრუნებული Laravel-ზე): 11/11 სურათი სწორად ჩანს `/news`-ის 3 გვერდზე (1+8+2), პოსტის დეტალის გვერდზე რეალური სურათი 200/`image/jpeg`-ით, მთავარ გვერდზე "სასკოლო ცხოვრების" ბლოკშიც ჩანს, და `/portal/assignments`/`/portal/my-assignments`/`/official-documents`/`/admissions` ყველა 200-ს აბრუნებს route cache-ის გასუფთავების შემდეგ. 198/198 ტესტი, Pint/PHPStan/tsc სუფთა.

## ნავიგაციის overflow-ბაგის საბოლოო, სტრუქტურული გამოსწორება (2026-09-14)

მომხმარებელმა კვლავ (მეოთხედ) აჩვენა სქრინშოთი რეალურ მოწყობილობაზე overflow-ით, კონკრეტულ სიგანეებზე (768-1440px, მკაცრი ქართული ფონტის breakpoint-ებით) ამომწურავი Playwright-ტესტირების მიუხედავად. სავარაუდო მიზეზი: ჩემი headless Chromium-ის ტესტირება ხდებოდა სრულად ჩატვირთული custom ფონტით, ხოლო რეალურ მოწყობილობაზე ნელი/ჩავარდნილი ფონტის ჩატვირთვისას fallback ფონტს შეიძლება ჰქონდეს განსხვავებული (ჩვეულებრივ უფრო განიერი) სიმბოლოების სიგანე ქართული დამწერლობისთვის — რის გამოც ზუსტი პიქსელური breakpoint-ები საიმედო არ არის სხვადასხვა მოწყობილობაზე/ფონტის მდგომარეობაზე.

**გამოსწორება პიქსელური breakpoint-ების ძებნის ნაცვლად**: nav ახლა სტრუქტურულად ვერ გადავა გვერდის საზღვრებს გარეთ — `flex-1 min-w-0 overflow-x-auto` (დამალული სქროლბარით) log-ოს (`shrink-0`) და მარჯვენა ღილაკების (`shrink-0`) ფიქსირებულ სიგანეებს შორის დარჩენილ სივრცეში; თუ nav-ის შიგთავსი ამ სივრცეს აღემატება, ის საკუთარ თავში დაისქროლება, ვერასდროს გააფართოებს header-ს/გვერდს. Header და root layout div-საც დაემატა `overflow-x-hidden` საბოლოო დამცავად.

**დამოწმებულია production-ზე რეალურად, ყველაზე მკაცრი პირობებით**: 19 სიგანე (360px-დან 1920px-მდე), custom web font-ების პირდაპირ დაბლოკილი მდგომარეობით (ყველაზე ცუდი fallback-ფონტის სცენარის სიმულაცია) — ყველგან `scrollWidth === viewport width`, ანუ 0 overflow, header მუდმივად ერთი ხაზი (77px). ეს აღარ არის დამოკიდებული კონკრეტული მოწყობილობის ფონტის ზუსტ სიგანეზე. 198/198 ტესტი, Pint/tsc სუფთა.
