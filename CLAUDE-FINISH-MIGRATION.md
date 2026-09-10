# Claude Code — დასრულების დავალება, შემოწმება 2026-09-11

მომხმარებლის მოთხოვნა: არსებული Laravel საიტი სრულად არ ემთხვევა დამტკიცებულ დიზაინსა და ფუნქციონალს. გააგრძელე არსებული პროექტი და მიიყვანე რეალურად გამოსაყენებელ მდგომარეობამდე. არ შექმნა ახალი აპლიკაცია და არ ჩაანაცვლო უკვე მომუშავე დომენები. ეს ფაილი არის განახლებული პრიორიტეტების დავალება; სრული მოთხოვნები რჩება START-HERE-CLAUDE.md და docs/02, 06, 07, 08-ში.

## შემოწმების საზღვრები

Codex-მა შეამოწმა მიმდინარე ლოკალური source, routes, კომპონენტები, importer და implementation-status. საჯარო https://aisi.edu.ge/ 2026-09-11 შემოწმებისას აბრუნებს 526-ს. ამიტომ production-ის ვიზუალური ან authenticated ფუნქციური QA არ შესრულებულა. სტატუს-დოკუმენტში აღწერილი deploy/DB რაოდენობები არის Claude-ის წინა ანგარიში და ამ აუდიტში დამოუკიდებლად არ გადამოწმებულა. ტესტები ამ აუდიტში არ გაშვებულა. ჯერ რეალური გარემო დაადასტურე და ანგარიშში განასხვავე source evidence / tested locally / tested production.

## დადასტურებული ხარვეზები

| პრიორიტეტი | არსებული მტკიცებულება                                                                                   | საჭირო შედეგი                                                                                                                                                                                                                         |
| ---------- | ------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P0         | საჯარო HTTPS პასუხი 526; docs/implementation-status.md აღწერს origin certificate პრობლემას              | ვალიდური origin TLS და საჯარო გამართული HTTPS. SSL verification არ გამორთო პრობლემის საბოლოო გადაწყვეტად. არსებული წვდომით იმუშავე; თუ certificate admin წვდომა აკლია, ზუსტად აღწერე საჭირო მოქმედება და გააგრძელე დანარჩენი სამუშაო. |
| P1         | resources/js/components/public/logo.tsx ისევ კვადრატულ ნიშანს + ცალკე აკრეფილ სახელს/„სკოლა“-ს აჩვენებს | გამოიყენე მომხმარებლის მიერ დამტკიცებული design/public/aisi-drawn-logo.png — ეს სრული ჰორიზონტალური ლოგოა, უკვე შეიცავს სახელს. აღარ დაუმატო მეორე წარწერა.                                                                           |
| P1         | resources/js/layouts/public/public-layout.tsx nav იყენებს text-sm font-medium-ს                         | BPG Nino Mtavruli Bold, accent #F5683C, ზომები 16px desktop / 15px შუალედური / 18px mobile; სათაურების მსგავსად source Georgian Mkhedruli ტექსტი, uppercase გარდაქმნის გარეშე.                                                        |
| P1         | resources/js/pages/public/page.tsx block union შემოიფარგლება hero/programs/life/text/contact_cta-ით     | გადაიტანე design/app/AisiConcept.tsx-ის მთელი საჯარო კომპოზიცია, CMS მონაცემებზე: hero CTA, სკოლის ისტორია, პროგრამები, პორტალის პრეზენტაცია, რეალური სიახლეები, სრული კონტაქტი და footer. შეადარე სექცია-სექციად, არა მხოლოდ ფერები. |
| P1         | resources/js/layouts/portal/portal-layout.tsx თავად აღწერს sidebar/bottom-nav-ს როგორც შემდგომ სამუშაოს | დიზაინის სრული desktop/sidebar/mobile bottom-navigation რეალურ უფლებებსა და routes-ზე.                                                                                                                                                |
| P1         | DashboardController მხოლოდ guardian/teacher branches-ს ამუშავებს; დანარჩენს no-role უჩვენებს            | მოსწავლე, დირექტორი, ადმინი იღებს საკუთარ dashboard-ს, სწორი შესაძლებლობებით; მრავალროლიან მომხმარებელს ჰქონდეს სერვერის მიერ ნებადართული კონტექსტის არჩევა.                                                                          |
| P1         | content:import default --only=pages,posts; მიმდინარე პაკეტში 92 ჩანაწერია                               | ყველა source key-ზე გადაწყვეტილება; legacy მასწავლებლები/დოკუმენტები/მედია/ბიბლიოთეკა არ დაიკარგოს როგორც out-of-scope.                                                                                                               |
| P1         | routes/web.php-ში არ ჩანს CMS მართვის სრული routes; წინა ანგარიში editor-ს დაუმთავრებლად ასახელებს      | უფლებებზე დაფუძნებული draft/preview/edit/publish/revert/merge queue, source comparison და audit trail.                                                                                                                                |
| P2         | მიმდინარე domain/routes ინვენტარში არ ჩანს სრული finance, clubs, appointments, consent ნაკადები         | docs/06 და design/app/SchoolServices.tsx-ის სცენარები გახდეს მუდმივი მონაცემებით მომუშავე მოდულები, შესაბამისი წესებით.                                                                                                               |

## 1. ბრენდი და დიზაინი — პირველი დასრულებული ეტაპი

ჯერ წაიკითხე design/app/AisiConcept.tsx, globals.css, SchoolNews.tsx, SchoolServices.tsx, DocumentCenter.tsx და შესაბამისი CSS. ბოლო მომხმარებლის არჩევანია დახატული aisi-drawn-logo.png; ძველი logo-concept.png და aisi-wordmark.svg აღარ არის მთავარი ლოგო. მოათავსე სრული ლოგო tenant-ის ბრენდის storage-ში. Brand contract-ს დაუმატე სრული lockup-ის/ცალკე სიმბოლოს მხარდაჭერა ისე, რომ სხვა სკოლის სახელის ავტომატური ჩვენება არ გაფუჭდეს. არ გაწელო კვადრატში. Reference ზომა 210×70 desktop, 150×50 mobile, 174×58 portal; object-fit:contain. შეცვალე აქტიური tenant ჩანაწერი idempotent update-ით: მარტო seeder-ის შეცვლა არსებულ ბაზას არ განაახლებს.

დიზაინი ვიზუალური წყაროა, ხოლო რეალური საიტი Laravel/Inertia-ზე უნდა დარჩეს. localhost:3000-ის გამართვა Laravel-ის ან production-ის დასრულებას არ ნიშნავს. არ გადაიტანო დემო სახელები, სტატისტიკა, კონცეფციის ზოლი და mock role switcher production-ში. ცარიელ რეალურ მონაცემზე შექმენი ხარისხიანი empty state. /brand რჩება დიზაინის მასალად, არა სავალდებულო საჯარო route-ად.

## 2. კონტენტის დასრულება

წაიკითხე content-migration/README.md, cms-import.json, asset-manifest.json, library-catalog.json და failures.json. 476 HTML ასლი არ არის 476 უნიკალური სტატია. პაკეტი შეიცავს 317 ფაილს, 92 draft ჩანაწერს, 70 გარე ბიბლიოთეკის ბმულს. დაუმთავრებელია 37 timeout და 724 აღმოჩენილი URL; მედიის API-ის 176/141 სხვაობაც ღიაა. ნუ გამოაცხადებ სრულ მიგრაციას ამ სხვაობების აღურიცხავად.

დააჯგუფე დარჩენილი ბმულები ენების/canonical/content hash-ის მიხედვით, შეამოწმე უნიკალური მასალა; დუბლიკატობა ავტომატურად არ ივარაუდო. ორიგინალი შეინარჩუნე. templates, merge candidates, obsolete და approved მასალა განასხვავე. ყველა ჩანაწერს ჰქონდეს import/merge/archive/blocked გადაწყვეტილება. იმპორტის ნახვა საჯარო გამოქვეყნებას არ ნიშნავს: შექმენი განხილვის რეალური UI და გამოიყენე მხოლოდ დამტკიცებული მასალა, ისტორიული თარიღების შენარჩუნებით. არც stale მიღების პირობები და არც სავარაუდო მიმდინარე ავტორიზაცია არ გამოაქვეყნო როგორც ახალი ფაქტი.

მედიის tenant mapping, featured image, library links, წვდომის წესები და ახალი slug-ები დაამუშავე. 301 რუკა შეამოწმე loops/404-ზე. მეორე tenant-ს აისის ფოტოები და სახელი არ უნდა გადაეცეს. წყაროს ცვლილებამ ადგილობრივი რედაქცია არ გადაწეროს.

## 3. რეალური პორტალები და სკოლის მართვა

არსებული დასწრება, განრიგი და დოკუმენტების approval არ გადააკეთო თავიდან: შეინარჩუნე ქცევა და tests, შეუსაბამე დიზაინს. დაასრულე სტუდენტის საკუთარი მონაცემები; მშობლის ბავშვების გადართვა; მასწავლებლის კლასები/რესურსები/დასწრება; დირექტორის დავალებები/დოკუმენტების დამტკიცება; ადმინის წევრები/მოწვევები/CMS/ბრენდი.

დოკუმენტებისთვის შეამოწმე upload → ახალი immutable version → submit → დაბრუნება მიზეზით → ხელახალი submit → approval → უფლებამოსილი download. published ვერსია არ შეცვალოს ახალი draft-ის ატვირთვამ. folder/workspace, search/filter, grants, history, restore/archive და upload limits სრულად იყოს აღწერილი და გამოყენებადი; private ფაილები საჯარო URL-ით არ გაიცეს. docs/07 სრული მოთხოვნების წყაროა.

შემდეგ ეტაპებად დაამატე ვიზიტის/მშობელთა შეხვედრის slot capacity და კონფლიქტები, წრეების ტევადობა/waitlist, გაცდენის განაცხადი და თანხმობები. Finance: ინვოისი, გადახდის ისტორია და უფლებები; პროვაიდერის webhook-ის signature/idempotency და თანხის server-side reconciliation. რეალური პროვაიდერის პარამეტრების გარეშე არ აჩვენო გამოგონილი successful payment. ყველა ფორმა რეალურად ინახებოდეს, ჰქონდეს validation/error/loading/empty states და server authorization.

## 4. გადამოწმება და დასრულების კრიტერიუმი

- შეადგინე docs/design-parity-checklist.md: თითო სექცია/ეკრანი → reference → Laravel component/route → მდგომარეობა → მტკიცებულება.
- Laravel-ის რეალური გვერდები შეადარე reference-ს 360, 390, 768, 1440px ზომებზე. გადაიღე screenshots, გადაამოწმე logo/font/assets, overflow, მენიუ, CTA, keyboard focus და mobile navigation. მარტო npm build არ არის ვიზუალური QA.
- არსებული check/build/PHP tests და სტატიკური შემოწმებები გაუშვი, ახალი behavior-ის meaningful tests დაამატე. განსაკუთრებით tenant isolation, role access, draft visibility, import rerun/conflicts, appointment capacity, document authorization.
- production-ის მონაცემებს არ გაუშვა truncate/migrate:fresh ან demo seed. Production deploy გააკეთე არსებული მომხმარებლის ავტორიზაციის ფარგლებში, backup/rollback და incremental migration-ებით. ახალი საჭირო წვდომა ზუსტად აღწერე; კონფიგურაციები და secrets ანგარიშში არ ჩაწერო.
- გადაამოწმე საჯარო HTTPS home/news/library/contact/login, static assets, protected redirect და დამტკიცებული კონტენტი რეალურ დომენზე. ლოკალური და deployed მდგომარეობა ცალ-ცალკე აღწერე.
- განაახლე docs/implementation-status.md და docs/deployment-and-rollback.md; ძველი ურთიერთსაწინააღმდეგო „დასრულებულია/შემდეგია“ ჩანაწერები შეასწორე.

იმუშავე ეტაპობრივად, მაგრამ არ გაჩერდე პირველი კარგი header-ის შემდეგ. საბოლოო პასუხში ჩამოწერე: რა მუშაობს რეალურად, რა გადაიტანე დიზაინიდან, რა შემოწმდა რომელ გარემოში, რა დარჩა და ზუსტად რაზეა დამოკიდებული. დაუმთავრებელ მოდულს ნუ დაარქმევ დასრულებულს მხოლოდ იმიტომ, რომ route/schema არსებობს.
