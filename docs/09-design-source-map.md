# აისის დიზაინის ფაილების სრული რუკა და Laravel-ში გადატანის კონტრაქტი

განახლებულია: 2026-09-11. ეს დოკუმენტი პასუხობს ორ კითხვას: სად არის დიზაინის თითოეული ნაწილი და რა უნდა გააკეთოს Claude Code-მა, რომ Laravel აპში იგივე ვიზუალი და რეალური ფუნქციონალი მიიღოს.

## 1. ჭეშმარიტების წყარო და პრიორიტეტი

დიზაინის აქტიური წყარო არის მხოლოდ `design/` საქაღალდე. Laravel-ის მიმდინარე `resources/js/` ვერსია შეიძლება ჩამორჩებოდეს დიზაინს. წინააღმდეგობისას გამოიყენე ეს რიგი:

1. მომხმარებლის უახლესი გადაწყვეტილება: დახატული ჰორიზონტალური ლოგო, BPG Nino Mtavruli სათაურები და მცირე სტაფილოსფერი მენიუ.
2. `design/app/platform/` — ახალი 12 მოდულის სამუშაო ეკრანები.
3. `design/app/AisiConcept.tsx` + `design/app/globals.css` — საჯარო საიტი და პირველი პორტალის კონცეფცია.
4. `design/app/SchoolServices.tsx`, `DocumentCenter.tsx`, `SchoolNews.tsx` და მათი CSS.
5. `design/app/brand/` — ბრენდის პრეზენტაცია; ეს არ არის production route-ის მოთხოვნა.
6. `design/app/school/` — ძველი საიტიდან გადმოტანილი კონტენტის review/catalog view; production CMS-ში პირდაპირ არ კოპირდება.

რეალური backend, უსაფრთხოება და ბიზნესწესები განისაზღვრება `CLAUDE-PLATFORM-MODULES.md`, `CLAUDE-FINISH-MIGRATION.md`, `docs/02-product-and-platform-spec.md`, `docs/07-document-management-spec.md` და `docs/08-content-migration.md`-ით. ვიზუალური prototype არასოდეს აუქმებს server authorization-ს.

## 2. გასაშვები მისამართები

| Route | დანიშნულება | მთავარი source |
|---|---|---|
| `/` | საჯარო საიტი + საწყისი მშობელი/მოსწავლე/მასწავლებელი/admin/director პორტალის პროტოტიპი | `app/AisiConcept.tsx`, `globals.css` |
| `/platform` | ახალი 12 მოდულის ერთიანი სამუშაო დიზაინი | `app/platform/PlatformSuite.tsx`, `platform.css` |
| `/school` | გადმოტანილი გვერდების/სიახლეების საძიებო review | `app/school/page.tsx`, `school/content.json`, `school.css` |
| `/brand` | ლოგო, ფერები, ხმა და მარკეტინგის ბრენდის დაფა | `app/brand/page.tsx`, `brand.css` |

ადგილობრივი დიზაინი მუშაობს `design/`-ში `npm run dev`-ით. Build: `npm run build`. ეს Vinext/Next reference პროექტია. Laravel-ში მისი framework wrapper, build config ან route სისტემა არ გადაიტანო.

## 3. ფაილებისა და ფოლდერების სრული დანიშნულება

### `design/app/`

| ფაილი | რას შეიცავს | Laravel-ში სამიზნე |
|---|---|---|
| `layout.tsx` | მხოლოდ reference პროექტის HTML shell, ქართული ენა, metadata და global CSS import | Laravel root Blade/Inertia shell-ში მხოლოდ `lang="ka"`, metadata/robots-ის სწორი გარემოსეული ლოგიკა |
| `page.tsx` | `/` route wrapper; მხოლოდ `AisiConcept`-ს ხსნის | Laravel route არ კოპირდება; გამოიყენე არსებული public controller/page |
| `AisiConcept.tsx` | მთავარი საჯარო header/hero/about/programs/portal presentation/news/contact/footer; პირველი portal shell, role demo, dashboard/schedule/library/payments/settings; საერთო `Logo` | დაყავი `PublicLayout`, `PortalLayout`, public section components და role-specific pages-ად |
| `globals.css` | მთლიანი საწყისი დიზაინის tokens, typography, public/portal responsive rules, dialog/table states, logo/menu-ის საბოლოო overrides | გადაიტანე Laravel-ის Tailwind theme/CSS-ში semantic tokens-ად; ბოლო override-ები პრიორიტეტულია ძველ წესებზე |
| `SchoolNews.tsx` | `school/content.json`-იდან 3 ისტორიული სიახლის ბარათი | რეალური published `Post` query + `NewsCard`; ისტორიული თარიღი შეინარჩუნე |
| `SchoolServices.tsx` | შეხვედრის დრო, კლუბი/waitlist, თანხმობა და გაცდენის განაცხადი; მხოლოდ local demo state | ცალკე Inertia pages/forms და რეალური domain actions, capacity/policy/audit-ით |
| `services.css` | სერვისების cards, forms, status და responsive წესები | შესაბამის Laravel components-ში styles/tokens |
| `DocumentCenter.tsx` | თანამშრომლის დოკუმენტის draft/version/submit და დირექტორის approve/return დემო | არსებული Documents domain + pages; `docs/07-document-management-spec.md`-ის workflow |
| `documents.css` | დოკუმენტების table, editor, history და approval states | არსებული documents pages/components-ის ვიზუალური reference |

### `design/app/platform/`

| ფაილი | რას აკეთებს | შენიშვნა |
|---|---|---|
| `page.tsx` | `/platform` wrapper და CSS import | Laravel-ში არ გადაიტანო wrapper-ის სახით |
| `PlatformSuite.tsx` | sidebar/mobile nav და 12 ცალკე სამუშაო view; ინტერაქციული filters/selects/switches | თითო view გახდეს საკუთარი authorized Laravel route/page; hardcoded data შეიცვალოს typed server props-ით |
| `platform.css` | ახალი portal-ის სრული ვიზუალური სისტემა და 1100/800/520px responsive ქცევა | ვიზუალური parity-ის მთავარი წყარო ახალი მოდულებისთვის |

`PlatformSuite.tsx`-ის შიდა view-ების რუკა:

| Prototype function | ეკრანი | Laravel route/page მიზანი |
|---|---|---|
| `Today` | დღის მოქმედებები და განრიგი | `/portal/today`, `portal/today.tsx`, server `DailyActionFeed` |
| `Inbox` | filters, conversation list, action request detail | `/portal/messages`, index/show split ან responsive master-detail |
| `Portfolio` | მოსწავლის ნამუშევარი, achievement და PDF | `/portal/students/{student}/portfolio` |
| `Progress` | metrics, competency bars, teacher comment, report card | `/portal/students/{student}/progress` და `/portal/report-cards` |
| `Safety` | pickup code/person და consent list | `/portal/pickup`, `/portal/consents` |
| `Health` | protected alert/profile და incident history | `/portal/students/{student}/health` granular permissions-ით |
| `Teacher` | next lesson, pending work და classes | `/portal/teacher` ან role-resolved `/portal/today` detail links-ით |
| `Substitute` | absence, candidate conflict და handoff files | `/portal/staffing/substitutions` |
| `Admissions` | CRM stats, pipeline და families | `/portal/admissions` |
| `Operations` | transport, meals და clubs | `/portal/transport`, `/portal/meals`, `/portal/clubs` |
| `Director` | attendance/billing/approvals pulse | `/portal/director` |
| `Platform` | tenants, branding და feature switches | `/platform-admin/tenants`; tenant admin-ისგან ცალკე auth boundary |

### `design/app/school/`

| ფაილი | დანიშნულება | Production წესი |
|---|---|---|
| `content.json` | `content-migration`-იდან შექმნილი 71 preview ჩანაწერი | source of truth არაა; importer მუშაობს `content-migration/cms-import.json`-ზე |
| `page.tsx` | category/search/list/detail review interface | CMS review queue-ს UX reference; draft კონტენტი საჯაროდ არ უნდა გამოჩნდეს |
| `school.css` | review/archive და news cards-ის სტილი | შესაძლებელია News/CMS pages-ში გამოყენება |

### `design/app/brand/`

`page.tsx` აჩვენებს ბრენდის lockup-ს, სლოგანს, ფერთა პალიტრას, საკომუნიკაციო ხმასა და გამოყენების ნიმუშებს. `brand.css` მხოლოდ presentation board-ის სტილია. `/brand` production-ში სავალდებულო არ არის; ბრენდის რეალური admin UI უნდა შეიქმნას `/platform` view-ის მიხედვით.

### `design/public/`

| Asset | სტატუსი და გამოყენება |
|---|---|
| `aisi-drawn-logo.png` | მომხმარებლის მიერ არჩეული **საბოლოო მიმდინარე ლოგო**. სრული ჰორიზონტალური lockup-ია — მზე/წიგნი და დახატული „აისი“ ერთ PNG-ში. გვერდით აღარ დაამატო ტექსტური „აისი“ ან „სკოლა“. გამოიყენე მხოლოდ აისის tenant-ზე. Reference ზომა: public 210×70, portal 174×58, mobile 150×50; `object-fit:contain`, aspect ratio არ დაარღვიო. |
| `logo-concept.png` | ძველი სიმბოლოს კონცეფცია; აღარ არის primary logo. გამოიყენე მხოლოდ ისტორიის/ალტერნატივის აღსანიშნავად. |
| `aisi-wordmark.svg` | უარყოფილი შუალედური ტექსტური wordmark; production-ში არ გამოიყენო. |
| `school-life.jpg` | სკოლის შენობის არქიტექტურული ვიზუალიზაცია; არა დადასტურებული დღევანდელი ფოტო. გამოყენება/აქტუალობა სკოლამ უნდა დაადასტუროს. |
| `bpg-nino-mtavruli-bold.ttf` | სათაურებისა და public menu-ის შრიფტი. Georgian source string მხედრულად რჩება; `text-transform:none`. კომერციული embedding პირობები გაშვებამდე გადაამოწმე. |
| `noto-georgian-regular.ttf`, `noto-georgian-bold.ttf` | body/UI ტექსტი. ლიცენზია `FONT-LICENSE.txt` (SIL OFL). |
| `migrated/` | ძველი საიტიდან preview-ში გამოყენებული მედიის ლოკალური ასლები | საბოლოო import მხოლოდ asset manifest/checksum/rights review-ის შემდეგ, tenant storage-ში |

### `design/components/ui/`

ეს არის reference პროექტის ხელმისაწვდომი UI primitives: dialog, table, select, sidebar, empty, tabs, sheet, switch, skeleton და სხვა. `AisiConcept` ამჟამად იყენებს dialog/table/select/sidebar/empty-ს. Laravel პროექტს უკვე აქვს საკუთარი UI stack; კომპონენტების source ბრმად არ გადააკოპირო. იგივე semantic primitive გამოიყენე არსებული Laravel import-ებიდან და ვიზუალურად მოარგე.

### `design/` root configuration

`package.json`/`package-lock.json` — reference-ის dependencies და commands; `vite.config.ts`/`next.config.ts` — Vinext build; `tsconfig.json` — strict typing/path alias; `components.json` — UI catalog config; `.oxlintrc.json`/`.oxfmtrc.json` — reference lint/format. ეს ფაილები Laravel root-ის config-ს არ ანაცვლებს.

## 4. ზუსტი ვიზუალური კონტრაქტი

| ნაწილი | მოთხოვნა |
|---|---|
| ძირითადი მუქი ფერი | `#132B45` |
| CTA/აქტიური აქცენტი | `#F5683C`; პატარა თეთრ ტექსტზე კონტრასტი ცალკე შეამოწმე |
| სამუშაო ფონი | `#F1F5F7`–`#F3F6F8` |
| საზღვარი | დაახლოებით `#DCE5EB` |
| მთავარი სათაურები | BPG Nino Mtavruli Bold, source Georgian Mkhedruli, `text-transform:none` |
| body/UI | Noto Sans Georgian regular/bold |
| ბარათის radius | დაახლოებით 10–14px; არა გადაჭარბებული pill UI |
| კონტროლები | 44px მინიმალური touch target; focus ring ხილული |
| public menu | BPG Nino Mtavruli; desktop 16px, საშუალო 15px, mobile menu 18px; `#F5683C` |
| logo | მხოლოდ `aisi-drawn-logo.png`, დამატებითი აკრეფილი descriptor-ის გარეშე |
| desktop portal | ფიქსირებული/sidebar navigation + sticky top bar + compact data surfaces |
| mobile portal | sidebar იმალება; ხელმისაწვდომია scrollable priority navigation ან შესაბამისი bottom nav; content ერთ სვეტად |

`globals.css`-ში რამდენიმე ისტორიული override დარჩა; CSS cascade-ის **ბოლო მოქმედი წესები** ასახავს მომხმარებლის ბოლო არჩევანს. Laravel-ში ძველი და ახალი override-ების მთელი ისტორია არ გადაიტანო — შექმენი სუფთა tokens/component variants ზემოთ მოცემული საბოლოო მნიშვნელობებით.

## 5. ფუნქციონალის წყარო

Prototype-ში ღილაკის მუშაობა მხოლოდ intended interaction-ს აჩვენებს. იგივე ვიზუალის მიღება საკმარისი არაა: production ქცევა ასეთია:

- role navigation მოდის server-ის allowed capabilities-დან;
- child selection გადაამოწმებს guardian link-ს;
- filters/search/pagination URL/query state-სა და server result-ზე მუშაობს;
- form submit გადის Form Request + Policy + Domain Action-ზე;
- state transition არის transaction/audit-ით და race-safe;
- private file გადის authorized short-lived download-ზე;
- finance success დგინდება provider webhook/reconciliation-ით;
- sensitive health read-იც audit event-ია;
- notification/exports/aggregates queue-შია საჭიროების მიხედვით;
- ყველა feature entitlement server route-ზეც აღსრულდება და არა მხოლოდ მენიუს დამალვით.

ზუსტი schema/workflows/tests წერია `CLAUDE-PLATFORM-MODULES.md`-ში. დოკუმენტების სპეციფიკა — `docs/07-document-management-spec.md`; ძველი კონტენტის იმპორტი — `docs/08-content-migration.md`.

## 6. რეკომენდებული Laravel frontend დაყოფა

შეინარჩუნე repository-ის მიმდინარე naming conventions, მაგრამ პასუხისმგებლობები დაახლოებით ასე დაყავი:

```text
resources/js/
  layouts/public/public-layout.tsx
  layouts/portal/portal-layout.tsx
  layouts/platform/platform-admin-layout.tsx
  components/brand/tenant-logo.tsx
  components/portal/{portal-sidebar,mobile-navigation,daily-action-card}.tsx
  components/public/{hero,programs,portal-preview,news-grid,contact-cta}.tsx
  pages/portal/{today,messages/...}.tsx
  pages/portal/students/{portfolio,progress,health}.tsx
  pages/portal/{teacher,director,admissions,...}.tsx
  pages/platform/tenants/...tsx
```

არ შექმნა ერთი giant `PlatformSuite` production-ში. Prototype function-ები აღწერს views-ს; production-ში shared shell/components და ცალკე route pages გამოიყენე. Inertia props-ს დაუწერე explicit TypeScript types; nullable/permission-dependent ველები სწორად აღწერე. Frontend არ უნდა მიიღოს unauthorized record და მერე უბრალოდ დამალოს.

## 7. parity checklist — როდის ითვლება გადატანილად

Claude Code-მა შექმნას/განაახლოს `docs/design-parity-checklist.md`, სადაც თითო row შეიცავს: reference route/view, Laravel route, component, backend source, roles, empty/loading/error/success, 360/390/768/1440 evidence და სტატუსს.

ეკრანი `verified` არის მხოლოდ მაშინ, როცა:

1. Laravel route რეალურ მონაცემს კითხულობს უფლებების დაცვით;
2. ძირითადი განლაგება, ფერი, typography, spacing, responsive collapse და state visuals reference-ს ემთხვევა;
3. ყველა ხილული მოქმედება end-to-end მუშაობს;
4. demo literal production bundle/data-ში არ დარჩა;
5. keyboard/mobile/long Georgian text/200% zoom/empty/error/loading შემოწმებულია;
6. tenant/role/IDOR tests გავლილია;
7. local და production evidence ცალ-ცალკეა დაფიქსირებული.

Screenshot comparison მარტო ვერ ადასტურებს ფუნქციონალს; route/schema მარტო ვერ ადასტურებს დიზაინს. ორივე მხარეა საჭირო. Pixel-level განსხვავება დასაშვებია მხოლოდ Laravel-ის accessibility ან რეალური მონაცემის აუცილებელი საჭიროებისთვის და checklist-ში უნდა აიხსნას.

## 8. Claude Code-ის წაკითხვის სავალდებულო რიგი

1. `CLAUDE.md`
2. `START-HERE-CLAUDE.md`
3. `CLAUDE-FINISH-MIGRATION.md`
4. `CLAUDE-PLATFORM-MODULES.md`
5. ეს `docs/09-design-source-map.md`
6. უშუალოდ `design/app/platform/`, შემდეგ `AisiConcept.tsx` და დანარჩენი კომპონენტები
7. მიმდინარე Laravel source/tests/status, სანამ რაიმეს შეცვლის

ამ რიგით Claude მიიღებს დიზაინის ზუსტ წყაროს, რეალური ფუნქციონალის კონტრაქტს და მიმდინარე კოდის მდგომარეობას ერთდროულად.
