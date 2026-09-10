# Claude Code — ახალი სკოლის პლატფორმის მოდულების ტექნიკური დავალება

განახლებულია: 2026-09-11. ეს დავალება აგრძელებს `CLAUDE-FINISH-MIGRATION.md`-ს. მიზანია `design/app/platform/`-ში დამტკიცებული ეკრანების რეალურ Laravel 13 + Inertia React პლატფორმად გადატანა. ჯერ წაიკითხე `CLAUDE.md`, `START-HERE-CLAUDE.md`, მიმდინარე `docs/implementation-status.md` და ეს ფაილი. Repository პარალელურად იცვლებოდა: ყოველთვის ხელახლა შეამოწმე არსებული models, migrations, policies, routes და tests; სწორი კოდი გააფართოვე, თავიდან ნუ დაწერ.

## 1. დიზაინის წყარო და UX კონტრაქტი

Reference ფაილებია `design/app/platform/page.tsx`, `PlatformSuite.tsx`, `platform.css`; preview — `http://localhost:3000/platform`. ყველა დიზაინის ფაილის სრული inventory და Laravel mapping მოცემულია `docs/09-design-source-map.md`-ში — წაიკითხე source-ის შეცვლამდე. მარცხენა მენიუდან იხსნება 12 ხედი: დღის ცენტრი, შეტყობინებები, პორტფოლიო, პროგრესი/ტაბელი, გაყვანა/თანხმობა, ჯანმრთელობა, მასწავლებელი, ჩანაცვლება, მიღების CRM, სერვისები, სკოლის პულსი და პლატფორმის მართვა.

ეს ინტერაქციული დიზაინის სპეციფიკაციაა და არა production კომპონენტი. Laravel აპში გამოიყენე არსებული `resources/js/layouts/portal/portal-layout.tsx`, UI primitives, Wayfinder/routes, shared brand props და design tokens. Vinext კოდი runtime-ში პირდაპირ არ დააიმპორტო.

- შეინარჩუნე `#132B45`, `#F5683C`, ღია ფონები, თხელი საზღვრები, მცირე radius, BPG Nino Mtavruli სათაურებში და Noto Sans Georgian სხეულში. ქართულზე uppercase transformation არ გამოიყენო.
- desktop-ზე sidebar; mobile-ზე priority/bottom navigation. 360px-ზე overflow არ იყოს; ძირითადი ტექსტი 16px+, ხშირი labels 14px+, touch target 44×44.
- გამოგონილი სახელები, თანხები, ჯანმრთელობის ჩანაწერები და სტატისტიკა production seed-ში არ გადაიტანო. ცარიელ მონაცემზე ხარისხიანი empty state აჩვენე.
- ყველა ღილაკს რეალური action/route ჰქონდეს. `href="#"`, ცრუ download/success და client-only ცვლილება დაუშვებელია.
- ყველა ეკრანს ჰქონდეს loading, empty, validation error, forbidden და success მდგომარეობა; semantic headings/tables, keyboard focus და WCAG 2.2 AA კონტროლი.

## 2. საერთო ტექნიკური წესები

შეინარჩუნე Laravel 13, Inertia React 19, strict TypeScript, Tailwind v4, PostgreSQL/SQLite tests და production MySQL/Percona თავსებადობა. ფული integer minor units-ში + currency; timestamps UTC-ში, ჩვენება tenant timezone-ში. JSON გამოიყენე settings/metadata-სთვის და არა ძირითადი relational მონაცემის დასამალად.

ყველა school-owned ცხრილს ჰქონდეს `tenant_id`, საჭირო composite index/unique constraint და tenant შემოწმება policy, route binding, query, job, cache, export და storage path-შიც. `BelongsToTenant` მარტო საკმარისი არაა. შეინარჩუნე არსებული `PortalContext`: active role server-ზე უნდა დადასტურდეს ამ tenant-ის active membership-იდან; UI role selector უფლებას არ ქმნის.

Controllers იყოს თხელი; გამოიყენე Form Requests, Policies და domain Actions. State transition-ები transaction-ში, race-sensitive მოქმედებები row lock/version check-ით. მნიშვნელოვანი ცვლილება `AuditLogger`-ით. lists paginate/filter/search-ით; N+1 eager loading-ით გამორიცხე; დიდი export/notification/aggregation queue-ში. Cache key ყოველთვის შეიცავს tenant-სა და scope-ს.

რეკომენდებული ახალი დომენებია `Communications`, `Portfolio`, `Assessment`, `Safety`, `Health`, `Staffing`, `Operations`, `Finance`, `Analytics`, `Platform`; არსებული `Admissions`, `Academics`, `Timetable`, `Documents` გააფართოვე.

## 3. დღის ცენტრი — `/portal/today`

ეს იყოს ყველა როლის საწყისი გვერდი. შექმენი server-side `DailyActionFeed`, რომელიც ავტორიზებული წყაროებიდან აერთიანებს დღევანდელ გაკვეთილებს, საპასუხო შეტყობინებებს, consent მოთხოვნებს, შეხვედრებს, invoice-ს, დასამტკიცებელ დოკუმენტებსა და ჩანაცვლებებს. ერთ item-ს ჰქონდეს stable key, type, title, due_at, priority, context/child label, permitted route და completed_at. giant duplicate table აუცილებელი არაა; გამოიყენე source adapters/read model.

მშობლის feed გაფილტრე guardian link permissions-ით; child switch-ზე server-ზე გადაამოწმე ownership. მასწავლებელი ხედავს საკუთარ assignment-ებს; დირექტორი approval/operations signals-ს. unauthorized წყაროს არსებობაც არ გაიცეს. overdue/urgent პირველია, timezone/day boundary tenant-ის მიხედვით. დასრულების შემდეგ feed რეალურად განახლდეს; empty state ამბობს, რომ დღე მოწესრიგებულია.

## 4. კომუნიკაცია — `/portal/messages`

მოდელი: `conversations` (subject/type/creator/archive), `conversation_participants` (user/role_context/joined/muted/last_read_message), `messages` (sender/body/timestamps), `message_deliveries` (recipient/channel/provider/status/timestamps/failure), `action_requests` (type/target/due/status), `notification_preferences`. Attachments ცალკე private asset relation-ით.

კლასის/ოჯახის recipient-ები server-ზე resolve და გაგზავნისას snapshot rows-ად შეინახე. მასობრივი გაგზავნა queue/retry/backoff/provider idempotency-ით. UI: unread, needs-response, search, archive, delivery summary; read receipt მხოლოდ უფლებამოსილი გამომგზავნისთვის. ჯანმრთელობის/ფინანსური დეტალი notification body-ში მინიმუმამდე დაიყვანე.

## 5. პორტფოლიო — `/portal/students/{student}/portfolio`

შექმენი `portfolio_items` (student/year/title/description/subject/visibility/date/status/creator), `portfolio_assets`, `portfolio_tags`, `portfolio_feedback`, `achievements`, `portfolio_exports`. Workflow: draft → submitted → published/returned → archived. მოსწავლე, მშობელი და მასწავლებელი განსხვავებული policy-ებით; მასწავლებელი მხოლოდ შესაბამის assignment-ზე.

ფაილი private storage-ში MIME/size/extension/checksum/scan status-ით; pending/failed scan არ გაიცეს. PDF export background job-ით, source revision/checksum-ით და მოკლევადიანი authorized download-ით. public share default-ად გამორთულია; თუ tenant ჩართავს, token იყოს ვადიანი და revocable.

## 6. პროგრესი, შეფასება და ტაბელი

Routes: `/portal/students/{student}/progress`, `/portal/gradebook`, `/portal/report-cards`. Entities: `assessment_periods`, `assessment_categories`, `assessments`, `assessment_results`, `competencies`, `competency_levels`, `student_competency_observations`, `report_cards`, `report_card_subjects`, `report_card_comments`, `report_card_approvals`.

Raw score, maximum, normalized percent და final grade ცალკეა; grade scale/version ინახება. ცვლილებას reason/version/audit. დახურული period ჩვეულებრივ edit-ს კრძალავს; reopen მხოლოდ უფლებითა და მიზეზით. ტაბელი: draft → teacher_complete → homeroom_review → director_approved → published; მშობელი მხოლოდ published-ს ხედავს. PDF-ში tenant brand, revision, generated_at და verification code/URL; private download authorization-ით.

გრაფიკი არ ქმნის არარსებულ პროგნოზს; აჩვენებს პერიოდსა და მონაცემთა მოცულობას. OneRoster/Ed-Fi-სთვის stable external identifiers/export adapter მოამზადე, მაგრამ certification არ გამოაცხადო.

## 7. გაყვანა და თანხმობები

Routes: `/portal/pickup`, `/portal/consents`, staff verification ცალკე. Entities: `authorized_pickup_people`, `pickup_authorizations`, `pickup_codes`, `pickup_events`, `consent_templates`, `consent_requests`, `consent_responses`.

Pickup code cryptographically random, DB-ში hash, მოკლე validity, single-use, rate-limited verify, atomic consume და audit. student ID კოდში არ ჩააშენო. verifier იღებს მინიმალურ identity confirmation-ს. fallback/emergency override ცალკე permission + reason + audit-ით.

Consent template version immutable. პასუხი ინახავს version/checksum, actor, guardian relationship და timestamp-ს. ტექსტის ცვლილება ახალ მოთხოვნას ქმნის; უარი სრულფასოვანი არჩევანია; revocation/cutoff server-ზე.

## 8. ჯანმრთელობის ჩანაწერი

Route `/portal/students/{student}/health`. დაამატე granular capabilities: `health.view`, `health.manage`, `health.incident.create`, `health.audit.view`, ფართო admin role-ის ნაცვლად. Entities: `student_health_profiles`, `health_conditions`, `medications`, `emergency_contacts`, `care_plan_documents`, `health_incidents`, `health_access_events`.

განსაკუთრებით მგრძნობიარე fields application-level encryption-ით. ყველა read/detail/export/download access event აღირიცხოს; logs-ში condition/phone/body არ მოხვდეს. Emergency view იყოს time-bound, reason-required და reviewable. UI default-ზე აჩვენებს მხოლოდ მოქმედებისთვის საჭირო alert-ს. მედიკამენტის დოზა/სამედიცინო რჩევა სისტემამ არ გამოიგონოს. Retention/delete/export policy configurable იყოს იურიდიულ დადასტურებამდე.

## 9. მასწავლებელი და ჩანაცვლება

არსებული timetable, attendance და assignments შეინარჩუნე. Teacher home აერთიანებს lessons, attendance completion, pending assessments, messages და classes-ს. Lesson detail: გეგმა, private/shared resources, დავალება, დასწრება და შეფასება. assignment-ის გარეშე სხვა კლასზე წვდომა არ არის.

ჩანაცვლება: `staff_absences`, `substitution_requests`, `substitution_assignments`, `lesson_handoffs`; candidate შეიძლება computed იყოს. შესაბამისობა ითვალისწინებს lesson conflict-ს, availability-ს, qualification-ს, workload-ს და tenant rules-ს. დანიშვნა transaction-ში ხელახლა ამოწმებს conflict-ს; parallel click ორს ვერ ნიშნავს. Notification მხოლოდ commit-ის შემდეგ. შემცვლელის roster/material access კონკრეტულ გაკვეთილსა და დროს ებმის.

## 10. მიღების CRM

არსებული `AdmissionLead` data migration/backward compatibility-ით გააფართოვე. Entities: `admission_cycles`, `admission_applicants`, `admission_guardians`, `admission_pipeline_stages`, `admission_applications`, `admission_tasks`, `admission_appointments`, `admission_documents`, `admission_decisions`, `admission_offers`, `lead_sources`.

Pipeline stage tenant-configurable/orderable; გადასვლას history actor/from/to/reason/time-ით. Duplicate detection normalized phone/email fingerprint-ით და human merge workflow-ით. Visit slot capacity transaction/lock-ით. განაცხადს resumable expiring token და rate limit; files quarantine/scan. Offer acceptance idempotent onboarding action-ით ქმნის student/guardian/membership-ს. Funnel counts რეალური query-დან; CSV export permission + audit-ით. Marketing consent admission processing-ისგან ცალკეა.

## 11. ტრანსპორტი, კვება და კლუბები

მოდულები დამოუკიდებელი feature entitlement-ებით ჩაირთოს.

- Transport: `transport_routes`, `transport_stops`, `transport_runs`, `student_transport_assignments`, `vehicle_events`. Provider-ის გარეშე live GPS არ აჩვენო; მხოლოდ planned/status data. Guardian მხოლოდ საკუთარი ბავშვის route-ს ხედავს.
- Meals: `meal_menus`, `meal_items`, `meal_allergens`, `student_dietary_profiles`, `meal_selections`. Health allergy-იდან მხოლოდ მინიმალური authorized projection; სრული health დეტალი operations-ს არ მიეცეს. Menu publish/versioned.
- Clubs: `clubs`, `club_sessions`, `club_enrollments`, `club_waitlist_entries`. Capacity transaction + unique enrollment; სავსეზე ordered waitlist; cancellation promotion atomic job-ით. Fee უკავშირდება Finance invoice item-ს.

## 12. Finance

Entities: `billing_accounts`, `invoices`, `invoice_items`, `payment_intents`, `payments`, `payment_allocations`, `refunds`, `provider_webhook_events`. თანხა integer minor units + currency. Invoice number tenant/fiscal scope-ში უნიკალური; void/credit note history-ით. Guardian financial data-ს მხოლოდ შესაბამისი GuardianLink permission-ით ხედავს.

Redirect success არ ნიშნავს payment success-ს. Webhook: signature, unique provider event id, idempotent handler, expected amount/currency/account validation, reconciliation; duplicate და out-of-order events იმუშავოს. Tenant trusted merchant mapping-იდან იძებნება, request tenant_id-დან არა. Provider-ის გარეშე UI invoice/history-only იყოს.

## 13. დირექტორის „სკოლის პულსი“

Route `/portal/director`. Cards: დღევანდელი attendance, lateness, approval queue, მიმდინარე collection; trend charts და attention queue deep links-ით. Metric-ს ჰქონდეს definition, period, denominator, last_computed და allowed drilldown. ჯერ correctness-first query/index; aggregate tables/jobs მხოლოდ profiling-ის საფუძველზე. Inactive records defined წესით გამოირიცხოს; health/individual sensitive data aggregate-ში არ მოხვდეს.

## 14. White-label platform administration

Tenant admin და platform operator მკაცრად გაყავი. Platform capability ცალკე model/middleware-ით; support access იყოს request/reason/approval/time limit/visible banner/audit-ით — silent impersonation არა.

Entities: `subscription_plans`, `tenant_subscriptions`, `plan_features`, არსებული `feature_entitlements`, `tenant_onboarding_steps`, `platform_audit_events`. Wizard: identity/domain → brand → locale/timezone → academic year/classes → staff invites → modules → review. ნაბიჯები resumable/idempotent. Domain verification token/DNS state; unknown host fallback არა. Feature-ის გამორთვა მონაცემს არ შლის. Brand-ში full lockup/icon/light/dark variants. `design/public/aisi-drawn-logo.png` მხოლოდ აისის tenant-ზე გამოიყენე.

Provider-ის გარეშე subscription foundation/configuration გააკეთე და არა გამოგონილი ფასები. Tenant export/offboarding/retention/deletion approval flow დაადოკუმენტირე.

## 15. ინტეგრაცია, უსაფრთხოება და ოპერაციები

Versioned internal contracts და stable external IDs. OneRoster 1.2-ის პირველი scope: CSV dry-run/import/export validation academic sessions, classes, courses, users, enrollments; results მხოლოდ assessment-ის შემდეგ. Ed-Fi mapping ცალკე adapter/ADR-ით. Credentials encrypted/scoped/rotatable; outbound webhooks signed + retry/delivery log.

- OWASP ASVS 5.0-ზე დაფუძნებული verifiable checklist; გაკეთებულზე მეტი compliance არ გამოაცხადო.
- MFA platform/admin/director/accountant/health privileged roles-ზე; session/device management, rate limits, CSRF, secure headers და privileged re-auth.
- authorization tests ყველა read/write/export/download endpoint-ზე, IDOR/cross-tenant სცენარებით.
- attachments private; short-lived signed route + policy recheck; public storage path არა.
- secrets environment/secret manager-ში; logs/error pages redacted.
- backups, restore rehearsal, queues/scheduler/failed jobs monitoring და retention runbook.
- student-level analytics tracking default-ად არა; telemetry მინიმალური/pseudonymous.

## 16. განხორციელების ფაზები

**A — საერთო საფუძველი და vertical slice:** PortalLayout/sidebar/mobile nav, role/context, დღის ცენტრი და message action request, სრული tenant/role/guardian auth და responsive states.

**B — აკადემიური ბირთვი:** portfolio, assessment/competency, gradebook/report card და teacher workspace ინტეგრაცია.

**C — ოჯახი და უსაფრთხოება:** pickup/consent, appointments და მინიმალური მკაცრად დაცული health record.

**D — ოპერაციები:** substitution, admissions CRM, clubs; შემდეგ transport/meals. Finance ცალკე security review-ით.

**E — სხვა სკოლებისთვის პროდუქტი:** onboarding, plan entitlements, custom domains, support access, export/offboarding და standards adapters.

ფაზა დასრულებულია მხოლოდ backend + policies + UI + tests + docs + migration/rollback-ით; schema ან mock page მარტო დასრულება არაა.

## 17. ტესტები და მიღების პირობები

Behavior-focused coverage:

1. Tenant A ვერ კითხულობს/ცვლის/export/download-ს Tenant B-დან guessed ID, nested binding, job ან cache გზით.
2. Guardian მხოლოდ მიბმულ children-ს ხედავს; academic/financial flags რეალურად ზღუდავს მონაცემს.
3. Teacher მხოლოდ assignment კლასზე მოქმედებს; substitute permission lesson/time-შია შეზღუდული.
4. Forged role უარყოფილია; inactive membership წვდომას კარგავს.
5. Message delivery idempotency, immutable consent, pickup single-use concurrency, appointment/club capacity race.
6. Grade/report transitions, closed period rejection და revision/audit.
7. Health read audit და unauthorized export/download rejection.
8. Payment bad signature/duplicate/wrong amount/out-of-order webhook.
9. Platform operator/tenant admin საზღვარი; disabled feature route server-ზეც იბლოკება.
10. MySQL/Percona identifier/index/JSON თავსებადობა და მნიშვნელოვანი SQLite/PostgreSQL განსხვავებები.

გაუშვი Laravel tests, Pint, PHPStan, `npm run check`, `npm run types:check`, production build. Browser QA რეალურ Laravel routes-ზე 360/390/768/1440: focus, text enlargement, long Georgian text, loading/empty/error, mobile navigation. Design screenshot Laravel parity-ის მტკიცებულება არაა.

შექმენი `docs/platform-modules-status.md` მატრიცით: `module | routes | schema | policy | UI states | tests | local evidence | production evidence | status | blocker`. სტატუსები: `not_started`, `foundation`, `working`, `verified_local`, `verified_production`. Production deploy incremental migrations + backup/rollback-ით; `migrate:fresh`, demo seed ან destructive reset არა. SSL 526 თუ კვლავ არსებობს, ცალკე blocker-ად ჩაწერე და დანარჩენი სამუშაო გააგრძელე.

საბოლოო ანგარიშში ჩამოწერე end-to-end journeys, design view → Laravel route/component mapping, authorization/race tests, local/production evidence და დარჩენილი ზუსტი blockers. არ დაწერო „ყველაფერი დასრულებულია“, თუ 12 ხედვიდან რომელიმე მხოლოდ mock UI, route ან schema-ა.
