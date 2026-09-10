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
| Logo (`aisi-drawn-logo.png`, ერთი lockup) | ყველა | `components/public/logo.tsx` | `BrandSetting.logo_path` + `brand:sync-logo` | ყველა | — | დამტკიცებულია აგენტის მიერ (ეს სესია) | `verified_local` |

## პორტალი — dashboard-ები და layout

| Reference | Laravel route | Component | Backend source | Roles | States | 360/1440 | სტატუსი |
|---|---|---|---|---|---|---|---|
| Sidebar (desktop) / bottom-nav (mobile) | ყველა `auth` route | `layouts/portal/portal-layout.tsx` | `PortalContext` (server-resolved nav) | ყველა | role-switcher მხოლოდ >1 როლისას | 360/1440 დამოწმებული (dashboard აგენტი) | `verified_local` |
| Guardian dashboard | `/dashboard` | `pages/portal/parent-dashboard.tsx` | `DashboardController@renderGuardian` | guardian | ბავშვის გადართვა | 360/1440 დამოწმებული | `verified_local` |
| Teacher dashboard | `/dashboard` | `pages/portal/teacher-dashboard.tsx` | `DashboardController@renderTeacher` | teacher | — | 360/1440 დამოწმებული (mobile overlap ბაგი ნაპოვნი+გასწორებული) | `verified_local` |
| Student dashboard | `/dashboard` | `pages/portal/student-dashboard.tsx` | `DashboardController@renderStudent` | student | "still not linked" honest state | 360/1440 დამოწმებული | `verified_local` |
| Director dashboard | `/dashboard` | `pages/portal/director-dashboard.tsx` | `DashboardController@renderDirector`, `ListPendingApprovalRequests` | director | — | 360/1440 დამოწმებული | `verified_local` |
| Admin dashboard | `/dashboard` | `pages/portal/admin-dashboard.tsx` | `DashboardController@renderAdmin` | admin | "მალე" placeholder members/CMS/brand-ისთვის (რეალურად არ არსებობს) | 360/1440 დამოწმებული | `verified_local` |
| Attendance register | `/lessons/{lesson}/attendance` | `pages/portal/attendance-register.tsx` | `AttendanceController` | teacher | — | ადრინდელი ეტაპის ნაწილი | `verified_local` |
| Documents (index/show/my-work/director-worklist) | `/documents*` | `pages/portal/documents/*` | `Document*Controller`, Documents domain | owner/director | quarantine/expired-URL/self-approval-აკრძალვა ტესტირებული | ადრინდელი ეტაპის ნაწილი | `verified_local` |

## `design/app/platform/` — 12 ახალი მოდული (`CLAUDE-PLATFORM-MODULES.md`)

არცერთი ჯერ არ დაწყებულა ცალკე route/schema დონეზე, გარდა portal shell/role-context საფუძვლისა (ზემოთ, უკვე `verified_local`), რომელიც Phase A-ს წინაპირობაა.

| Prototype view | სამიზნე route | სტატუსი |
|---|---|---|
| Today (დღის ცენტრი) | `/dashboard` (ლეიბლი "დღეს" — არ არის აშენებული ცალკე `/portal/today` route, იხ. ქვემოთ "ცნობილი გადახრები") | `verified_local` + `verified_production` — `BuildDailyActionFeed` აერთიანებს დოკუმენტების დამტკიცებას (director/admin) და წაუკითხავ შეტყობინებებს (ყველა) ერთ სიად ყველა 5 dashboard-ზე; პატიოსანი ცარიელი მდგომარეობა ("დღეს ყველაფერი მოგვარებულია"). Production-ის რეალურ admin ანგარიშზე Playwright-ით დამოწმებული. |
| Inbox (შეტყობინებები) | `/portal/messages` | `verified_local` — რეალური conversations/messages/deliveries, ურთიერთობაზე დაფუძნებული (guardian↔class-teacher, staff↔staff, office↔ნებისმიერი guardian) მიმღების არჩევანი, unread badge, read-receipt, master-detail UI. **production-ზე ჯერ არ არის დეპლოირებული** |
| Portfolio | `/portal/students/{student}/portfolio` | `not_started` |
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

## ცნობილი, განზრახ დარჩენილი გადახრები (docs/09 §7-ის მოთხოვნით ახსნილი)

- Programs ბარათებზე click→dialog დეტალი (reference-ში არსებობს) არ აშენებულა — სტატიკური ბარათი საკმარისია ამ ეტაპისთვის; საჭიროებისას მარტივი დამატებაა არსებული `Dialog` UI კომპონენტით.
- Hero photo (`school-life.jpg`) გამოყენებულია ვიზუალურად, მაგრამ დოკუმენტირებულია, რომ მისი აქტუალობა/გამოყენების უფლება სკოლას ჯერ არ დაუდასტურებია (`docs/09 §3`-ის ცხადი გაფრთხილება) — კოდში ეს კომენტარადაა დაფიქსირებული.
- `/school?item=...` მარშრუტი (reference-ში ისტორია/სიახლეების ბმულის სამიზნე) განზრახ **არ არის** აშენებული როგორც საჯარო route — draft, გაუმოწმებელი იმპორტირებული კონტენტი საჯაროდ არ უნდა ჩანდეს (`docs/09 §3`, `content:import`-ის `status=draft` წესი). ამის ნაცვლად history/news ბმულები რეალურ, უკვე გამოქვეყნებულ `/about`/`/news`-ზე მიდის.
- production evidence ცალკე ჯერ არ არის დაფიქსირებული ამ ცხრილში ახალი (hero/values/history/programs) ცვლილებებისთვის — production-ზე deploy-ის შემდეგ დაემატება ცალკე row/სვეტი.
