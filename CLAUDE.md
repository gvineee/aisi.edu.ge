# Claude Code — აისის ახალი საიტისა და სკოლის პლატფორმის განხორციელება

## ამ პროექტის დანიშნულება

შექმენი Laravel 13-ზე დაფუძნებული სასკოლო ვებპლატფორმა React + TypeScript + Inertia ფრონტით. აისი პირველი სკოლაა; პროდუქტი მომავალში სხვა სკოლებზე ბრენდის შეცვლით უნდა გავრცელდეს ერთი საერთო codebase-ით. ამ საქაღალდეში არსებული დიზაინი არის კონცეფცია და საცდელი ინტერაქცია, არა უკვე მომუშავე backend.

პირველად წაიკითხე `docs/02-product-and-platform-spec.md`, შემდეგ `docs/`-ში არსებული აუდიტი, ბრენდის/მარკეტინგის დოკუმენტები და `design/` პროტოტიპის ფაილები. ფაილების ზუსტი სახელები ინვენტარით გადაამოწმე. დოკუმენტებში „შემოთავაზებული“, „სადემონსტრაციო“ და „დასაზუსტებელი“ არ გადააქციო სკოლის ფაქტებად.

## მოქმედების საზღვრები

- მიმდინარე handoff არ ნიშნავს ყველა ფაზის ერთბაშად აშენებას. თუ მომხმარებელი უბრალოდ გთხოვს დაწყებას, სრულად შეასრულე ფაზა 1-ის სამუშაო შეთანხმებულ ლოკალურ გარემოში; შემდეგ წარმოადგინე შემოწმებადი შედეგი და დარჩენილი ფაზები.
- პირველი ნაბიჯი არის ინვენტარი და არსებული ფაილების/ცვლილებების შემოწმება. არ გადაწერო დიზაინი ან სხვისი ცვლილებები ბრმად; არ შექმნა ახალი repository, თუ უკვე არსებობს.
- არ გამოიყენო production მონაცემები, ნამდვილი ბავშვის პირადი ინფორმაცია, რეალური გადახდა ან production deploy საცდელი სამუშაოსთვის. გარე გაშვება და ფინანსური credentials დაეყრდნოს მომხმარებლის კონკრეტულ ავტორიზაციას.
- დიზაინის მაგალითები გადაიტანე ფუნქციურ კომპონენტებად; ყალბი წარმატების toast-ით რეალური შენახვა/გადახდა არ ჩაანაცვლო.
- თუ სპეციფიკაცია და არსებული დიზაინის დემო ერთმანეთს განსხვავდება, ბიზნესწესი აიღე სპეციფიკაციიდან; ვიზუალური ენა დიზაინიდან. არსებითი წინააღმდეგობა აღწერე და გადაწყვეტილება დოკუმენტში შეიტანე.

## ტექნიკური გადაწყვეტილებები

Laravel `^13.0`, PHP 8.4 (ყველა dependency-სთან გადაამოწმე), ოფიციალური Laravel React starter kit, TypeScript strict, Inertia, Vite, PostgreSQL, Redis, S3-compatible storage. გამოიყენე ოფიციალურად მხარდაჭერილი მიმდინარე თავსებადი ვერსიები და commit lockfiles. Laravel 13 მოითხოვს მინიმუმ PHP 8.3-ს; იხილე https://laravel.com/framework/docs/13.x/releases და https://laravel.com/framework/docs/13.x/starter-kits . შეუთავსებელი package არ დაამატო მხოლოდ ნაცნობობის გამო.

ერთი მოდულური მონოლითი, არა მიკროსერვისები. Laravel routes/controllers/Form Requests/policies + domain actions/services; React pages/components მხოლოდ UI-სა და local interaction state-ს მართავს. ბიზნესლოგიკა და უფლებები server-ზეა. საჯარო კონტენტი ხელმისაწვდომი იყოს initial HTML-ში SSR/დოკუმენტირებული rendering გადაწყვეტით; private პორტალი არ ინდექსირდეს.

რეკომენდებული საბოლოო განლაგება: `app/Domain/{Tenancy,Identity,Content,Admissions,Academics,Timetable,Learning,Library,Finance,Communications}`, `resources/js/{pages,components,layouts,lib}`, `tests/{Feature,Unit,Browser}`, `docs/decisions/`. Framework-ის კონვენციები შეინარჩუნე; ზედმეტი abstract repository ან custom framework არ შექმნა. Laravel ფაილების root-ში ჩასმისას შეინარჩუნე ამ handoff-ის docs/design.

## უცვლელი მოთხოვნები

1. ყველა tenant-owned ჩანაწერი შეიცავს tenant_id-ს; სკოლის კონტექსტი მხოლოდ სანდო domain + membership-იდან მოდის. მეორე tenant seed-ით დაამტკიცე იზოლაცია.
2. tenant scope მოიცავს policy, route binding, validation, relation, raw query, job, cache key, file path, search და export-ს. client tenant_id-ს ნუ ენდობი.
3. მშობლის წვდომა დასტურდება აქტიური guardian link-ით და კონკრეტული აკადემიური/ფინანსური უფლებით; მასწავლებლისა — მინიჭებული საგნით/კლასით.
4. admin/finance/platform ოპერატორებისთვის MFA. სხვა როლებს მინიმალური საჭირო ნებართვა. წვდომის შემოწმება საჭიროა read endpoint-ზეც.
5. ატვირთული პირადი ფაილები private storage-ში, policy-ით შემოწმებული მოკლევადიანი download. MIME/size validation და quarantine/scanning workflow.
6. ყველა თანხა integer თეთრებში + currency; გადახდის დაბრუნების გვერდი არ ამტკიცებს წარმატებას. ხელმოწერილი/სერვერულად გადამოწმებული webhook + idempotency + reconciliation.
7. მნიშვნელოვანი ცვლილება audit log-ში: როლი/guardian link, შეფასება, დასწრება, ფინანსები, ექსპორტი, მხარდაჭერის წვდომა. token/პაროლი/მთლიანი სენსიტიური payload არ ჩაწერო.
8. სახელი/ლოგო/ფერი/კონტაქტი/ლოკალი მოდის tenant settings-იდან. აისი არ იყოს hardcoded domain logic-ში.
9. ქართული UI პირველია; ტექსტები translation keys-ით. ფულის ჩვენება GEL/₾, თარიღი ka-GE, დრო Asia/Tbilisi; ბაზის timestamp UTC.
10. demo მონაცემები მკაფიოდ განცალკევდეს production seed-ისგან. მაკეტის ციფრები, გუნდი, მიღების ვადა და სარეკლამო მტკიცება არ გამოაქვეყნო ფაქტებად.

## სამუშაოს თანმიმდევრობა

### ფაზა 0 — შემოწმება და foundation-ის გეგმა

შეამოწმე ფაილები, runtime-ები, repository status, ადგილობრივი ინსტრუქციები. დაწერე მოკლე `docs/implementation-status.md` არსებული/შესაქმნელი/დაბლოკილი საკითხებით. დააფიქსირე package ვერსიები და rendering/tenancy გადაწყვეტილებები `docs/decisions/`-ში. მოამზადე `.env.example` მხოლოდ placeholders-ით.

### ფაზა 1 — სრული საჯარო საიტი

შექმენი framework skeleton დაცულ staging/local გარემოში; tenancy foundation, brand settings, admin auth, CMS pages/posts/media და მიღება/ვიზიტი. გადაიტანე `design/`-ის ვიზუალური ენა React კომპონენტებში; ყველა ბმულს/ღილაკს ჰქონდეს შესაბამისი რეალური მოქმედება ან მკაფიო disabled state. საჯარო გვერდები: მთავარი, შესახებ, პროგრამები/დეტალი, მიღება, სასკოლო ცხოვრება, სიახლე/დეტალი, კონტაქტი. მონაცემის არარსებობისას გამოიყენე მონიშნული შევსების ადგილი.

რედაქტორმა უნდა შეძლოს draft → preview → publish და დაბრუნება ვერსიაზე. ვიზიტის დაჯავშნა server-ზე ინახება ტევადობის კონტროლით და queued შეტყობინებით. გააკეთე SEO metadata, sitemap, robots, canonical/hreflang, 301 mapping scaffolding და error pages. დასრულება: წარმოადგინე ლოკალური preview, გავლილი შემოწმებები და დარჩენილი კონტენტის სია.

### ფაზა 2 — პორტალის ბირთვი

შექმენი სკოლის სტრუქტურა, წლიური enrollment, guardian links, მოწვევები და role-based dashboards. დაამატე განრიგის ვერსიები/კონფლიქტები, დასწრება, დავალებები/submissions, წიგნების სია, in-app/email შეტყობინებები. პირველი rollout ერთ კლასზე. ყველა public-looking demo portal screen გადააკეთე რეალურ მონაცემზე და loading/empty/error მდგომარეობებზე.

### ფაზა 3 — შეფასება და ფინანსები

დაამატე სკოლის დამტკიცებული შეფასების წესები და გამოქვეყნების workflow; contracts/invoices/payments/allocations/refunds. პროვაიდერის რეალური API პირობების მიღებამდე გამოიყენე მხოლოდ sandbox adapter მკაფიო ნიშნულით. შეასრულე duplicate/out-of-order webhook, partial/overpayment და reconciliation ტესტები. ბუღალტრის UAT-ის გარეშე ფინანსური მოდული production-ready-დ არ გამოაცხადო.

### ფაზა 4 — მეორე სკოლა და SaaS

ჩართე მეორე tenant, განსხვავებული ბრენდი/დომენი, feature entitlements და onboarding/offboarding. SaaS subscription არ აურიო მოსწავლის tuition-ს. platform support წვდომა იყოს დროებითი, დასაბუთებული და აუდიტირებადი. თვითრეგისტრაცია/გეგმების billing რეალური კომერციული წესების შემდეგ.

## დიზაინის განხორციელების წესები

- შეინარჩუნე დიზაინის spacing, ტიპოგრაფიული იერარქია, ფერები/token-ები, rounded shapes და მზის/აისის ბრენდის მიმართულება; final visual assets გამოიყენე არსებული ფაილებიდან.
- მობილურზე 360px-დან ყველაფერი მუშაობდეს; პორტალის bottom navigation მაქსიმუმ 5 პუნქტი; safe-area padding; დიდი ცხრილები card/day view-ით ან იზოლირებული horizontal scroll-ით.
- focus, keyboard navigation, dialogs focus management, labels, validation summaries, 44px touch targets, reduced-motion. WCAG 2.2 AA სამიზნე.
- ცარიელი dashboard არ შეავსო შემთხვევითი ყალბი ფინანსებით/შეფასებებით. გრაფიკებს ჰქონდეს პერიოდი, განმარტება და ტექსტური ალტერნატივა.
- service worker-ში ნუ დააკეშავ პირად/ფინანსურ responses-ს; დასაწყისში PWA მხოლოდ app shell/offline fallback.
- logo/brand configuration ვალიდირდება: დაშვებული ფაილი, ზომა და ფერები; arbitrary script ან არასანიტიზებული SVG არ მიიღოს მომხმარებლის upload-მა.

## აუცილებელი ტესტები და დასრულების განსაზღვრება

თითო ფაზაში გაუშვი ცვლილებასთან შესაბამისი lint/typecheck/build და meaningful tests. გამოიყენე backend feature tests policy/tenant/transaction წესებისთვის და browser tests კრიტიკული მომხმარებლის გზებისთვის. UI reversible კოსმეტიკისთვის ზედმეტი unit tests არ შექმნა.

აუცილებელი შემთხვევები: tenant A ვერ ხედავს tenant B-ს object/file/export/search შედეგს; guardian revoked; unassigned teacher; unpublished grade; duplicate payment event; mismatched amount/currency; concurrent allocation; expired signed URL; განრიგის გადაფარვა; admission validation; მობილური overflow; keyboard-only form. დეტალური სია მოცემულია product spec-ში.

ყოველი ეტაპის ბოლოს განაახლე implementation-status: შესრულებული ფუნქციები, ტესტის ზუსტი შედეგები, ასაწყობი/გასაშვები ინსტრუქცია, ცნობილი ხარვეზები და საჭირო მონაცემები. არ დაწერო „ყველაფერი მზადაა“, თუ კონტენტის, ავტორიზაციის, ბანკის, მიგრაციის ან production ოპერირების ნაწილი ჯერ არ შემოწმებულა.

## მიგრაცია და გაშვება

შეინარჩუნე ძველი URL-ების inventory და redirect mapping. მედიის და მოსწავლეთა მონაცემების import მხოლოდ დამოწმებული export-ით, preview/dry-run და დუბლიკატების ანგარიშით. migration rehearsal და backup restore ჩაატარე staging-ში. production deploy გეგმა მოიცავს backup-ს, backward-compatible migration-ს, worker/SSR restart-ს, smoke checks-ს და rollback-ს. სკოლის DNS-ის შეცვლისას საფოსტო ჩანაწერები არ დაკარგო.

## კითხვები, რომელთა პასუხი არ უნდა გამოიგონო

რეალური საფასური, საფეხურები და სასწავლო პროგრამა; მიღების თარიღები; სკოლის ავტორიზაციის სტატუსი; მოსწავლეთა/მასწავლებელთა რაოდენობა; ფოტოებზე უფლებები; მეურვეთა უფლებამოსილების წესი; grading policy; ბანკის API/merchant credentials; სახელმწიფო ჟურნალის API ხელმისაწვდომობა; შენახვის სამართლებრივი ვადები; ჰოსტინგი და SLA. დამოუკიდებელი სამუშაო გააგრძელე, შესაბამისი ინტეგრაცია მონიშნე pending-ად.
