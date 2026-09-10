# სკოლის დოკუმენტების ერთიანი ცენტრი — რეალური სისტემის სპეციფიკაცია

მომხმარებლის დამატებითი მოთხოვნა: მასწავლებლებისთვის და დირექტორისთვის ფაილების/დოკუმენტების ერთიანი მართვა. ეს არის სამუშაო ფუნქციური დანართი; იგი ემატება სკოლის პლატფორმას, არა დამოუკიდებელი Drive-ის ასლი.

## 1. პრაქტიკული საფუძველი და პროდუქტის მიზანი

[SharePoint-ის ოფიციალური გზამკვლევი](https://support.microsoft.com/en-us/sharepoint/lists/documents-and-library/how-versioning-works-in-lists-and-libraries) აღწერს ვერსიებს, მონახაზების ხილვადობასა და დამტკიცების წესებს. [Google Drive-ის approvals](https://support.google.com/drive/answer/9387535) აერთიანებს განხილვას, დადასტურება/უარს და განხილვისას დოკუმენტის ჩაკეტვას. აისისთვის ვიღებთ ამ პროცესებს: ერთი მოქმედი ვერსია, გასაგები პასუხისმგებელი და გადაწყვეტილების ისტორია. მათი სერვისების ინტეგრაცია ამ ეტაპზე განხორციელებული ან დადასტურებული არ არის.

სკოლის ამოცანებია: სასწავლო გეგმის მომზადება; კათედრის/დირექტორის განხილვა; ბრძანებებისა და ოქმების მართვა; დამტკიცებული წესების თანამშრომლებზე გავრცელება; საჭირო დოკუმენტის პოვნა; დაკარგული ვერსიების თავიდან აცილება.

## 2. ინფორმაციის სტრუქტურა

საწყისი სამუშაო სივრცეები, სკოლის მიერ მორგებადი:

1. სასწავლო გეგმები — წელი/კათედრა/საგანი/კლასი.
2. პროექტები და ღონისძიებები — პროგრამა, მონაწილეობის წესები, ანგარიშები.
3. სკოლის წესები და პოლიტიკები — მოქმედი ვერსია, ძალაში შესვლის თარიღი, გაცნობის დადასტურება.
4. სხდომები და ოქმები — სხდომა, მონაწილეთა შესაბამისი წვდომა, მოქმედებები.
5. ბრძანებები — მონახაზი, განხილვა, ნომერი, ძალაში შესვლა, არქივი.
6. შაბლონები — სკოლის ბლანკი, გეგმის ფორმა, განაცხადი, ოქმის ფორმა.
7. თანამშრომლის პირადი სამუშაო — არასაჯარო მონახაზები.
8. შეზღუდული საცავები — საკადრო/ფინანსური ან სხვა სენსიტიური ჩანაწერები; არა საერთო თანამშრომლების საქაღალდე.

საქაღალდესთან ერთად გამოიყენეთ მეტამონაცემები: წელი, დოკუმენტის ტიპი, დეპარტამენტი, ავტორი/პასუხისმგებელი, საგანი/კლასი სადაც საჭიროა, ტეგები, სტატუსი, ვადა. მხოლოდ საქაღალდის სახელზე დამოკიდებულება არ არის საკმარისი. სათაურის შეცვლა stable ID-სა და ბმულს არ ცვლის.

## 3. როლები და მოქმედებები

| როლი | შესაძლებლობა | შეზღუდვა |
|---|---|---|
| მასწავლებელი | თავისი მონახაზი, მინიჭებული სამუშაო სივრცის ფაილები, ახალი ვერსია, განხილვაზე გაგზავნა | თავის დოკუმენტს საბოლოოდ არ ამტკიცებს, თუ პოლიტიკა სხვა დამოუკიდებელ approver-ს ითხოვს |
| კათედრის ხელმძღვანელი | მის კათედრაზე განხილვა/შენიშვნა და შემდეგი ნაბიჯი | სხვა კათედრის private სამუშაო ავტომატურად მიუწვდომელია |
| დირექტორი | მისთვის მინიჭებული გადაწყვეტილებები, დამტკიცება/დაბრუნება, სკოლის დოკუმენტების oversight | ტიტული არ იძლევა ყველა restricted medical/HR მონაცემზე ავტომატურ წვდომას |
| საქმისმწარმოებელი | რეესტრი, ნომერი, გამოქვეყნება/გავრცელება შესაბამისი უფლებით | შინაარსის დამტკიცება დამოუკიდებელი permission-ია |
| ტექნიკური ადმინისტრატორი | კონფიგურაცია/საცავის ოპერირება | ტექნიკური როლი შინაარსის approval უფლება არ არის |
| თანამშრომელი-მკითხველი | მისთვის გამოქვეყნებული დამტკიცებული ვერსიის ნახვა, საჭირო გაცნობის დადასტურება | მონახაზებისა და განხილვის შენიშვნების ნახვა ცალკე წესით |

საჭირო permissions: `documents.view`, `create`, `edit_draft`, `upload_version`, `submit`, `review`, `approve`, `publish`, `archive`, `manage_access`, `manage_retention`, `acknowledge`, `export`. ისინი არის tenant/workspace/document კონტექსტზე მიბმული და არა მხოლოდ role name-ზე.

თითოეული read/preview/download/search/history/comment endpoint policy-ით შემოწმდეს. დირექტორი, როგორც როლი, დაემატოს identity/tenant membership მოდელს არსებული permissions-ის არქიტექტურის გაფართოებით. თუ Governance/Audit domain უკვე არსებობს, ხელახლა არ შექმნათ.

## 4. დოკუმენტის ციკლი და ვერსიები

სამუშაო რედაქცია: draft → in_review → approved ან changes_requested → revised draft → in_review. გამოქვეყნება: approved → published → superseded/archived. საჭიროების შემთხვევაში withdrawn/cancelled ცალკე სტატუსებია; არ გამოიყენოთ deleted როგორც ყველა მდგომარეობის შემცვლელი.

**ვერსია immutable ფაილი/შინაარსია.** `documents` ინახავს latest working version-სა და current published version-ს ცალ-ცალკე. დამტკიცებული v1-ის შემდეგ v2 მონახაზის არსებობა მკითხველს v2-ს არ აჩვენებს. მიმდინარე დამტკიცებული შინაარსი ხელმისაწვდომია, სანამ ახალი ვერსია გამოქვეყნდება.

მნიშვნელოვანი წესები:

- ცვლილება ქმნის ახალ version ID-ს, checksum-ს, ავტორსა და დროს; დამტკიცებული bytes არ გადაიწერება.
- approval request მიებმის კონკრეტულ version ID/hash-სა და reviewer snapshot-ს. ახალი ვერსია ძველ approval-ს არ იღებს.
- განხილვისას შემოწმებული version დაილოქოს; ცვლილება იწყებს ახალ ციკლს ან აუქმებს მიმდინარე მოთხოვნას დოკუმენტირებული წესით.
- შენახვისას optimistic concurrency/version token; stale edit მიიღებს conflict პასუხს, არ ჩუმად გადაწერს ახალ სამუშაოს.
- აღდგენა ნიშნავს ძველი ვერსიის საფუძველზე ახალი draft-ის შექმნას; ძველი ისტორია არ იცვლება და დამტკიცება ავტომატურად არ აღდგება.
- დაბრუნებისთვის სავალდებულოა განმარტება; დამტკიცების/უარის მონაწილე და timestamp audit-ში რჩება.
- default თვითდამტკიცება აკრძალეთ იქ, სადაც დამოუკიდებელი განხილვა მოთხოვნილია. substitute approver დროით შეზღუდულად და audit-ით დაინიშნოს.
- რამდენიმე approver: sequential ან all-required მოდელი კონფიგურაციით; ერთის უარი ასრულებს შესაბამის ციკლს. approver-ის ჩანაცვლებისას ძველი გადაწყვეტილება არ გაქრეს.

დამტკიცება და ელექტრონული ხელმოწერა სხვადასხვა რამეა. შიდა approval ღილაკი არ აღწეროთ როგორც იურიდიულად კვალიფიციური ხელმოწერა. გარე ხელმოწერის ინტეგრაციას დასჭირდება სკოლის კონკრეტული მოთხოვნა და პროვაიდერი.

## 5. ეკრანები

### ჩემი სამუშაო

ჩემი მონახაზები, შესასწორებლად დაბრუნებული, განსახილველი და მოახლოებული ვადები. თითოეულში: დოკუმენტი, ზუსტი ვერსია, პასუხისმგებელი და შემდეგი ქმედება. რიცხვები რეალურ query-ს ასახავდეს და permission-aware იყოს.

### ფაილების ცენტრი

საქაღალდეები/კათედრები, ძიება, სტატუსის/წლის/ტიპის ფილტრები, ბარათები ან ცხრილი; stable URL. ფაილის დეტალი: preview, ვერსიები, კომენტარები, approval history, წვდომა და საჭირო გაცნობის სია. ძიების snippet-იც იმავე policy-ს უნდა ემორჩილებოდეს.

### დირექტორის სამუშაო სია

მისთვის მინიჭებული დოკუმენტები ვადით, ავტორით, ტიპით, ცვლილების შეჯამებით და კონკრეტული ვერსიით. მოქმედებები: ნახვა, კომენტარი, დამტკიცება, შესასწორებლად დაბრუნება. ჯგუფური approval პირველ MVP-ში არ დაამატოთ — განსაკუთრებით მაშინ, თუ დოკუმენტი არ წაუკითხავს.

### შაბლონები და რეესტრი

შაბლონიდან ახალი მონახაზი ქმნის ახალ document-ს და ინახავს source template version-ს. დამტკიცებული ბრძანების ნომერი ენიჭება სკოლის კონფიგურირებული, concurrency-safe მიმდევრობით; გაუქმებული ნომერი history-ში რჩება და ჩუმად ხელახლა არ გამოიყენება. შეთანხმებული numbering policy-ის გარეშე ოფიციალური ნომრები არ გამოიგონოთ.

### გაცნობის დადასტურება

გამოქვეყნებული წესის კონკრეტული ვერსია შეიძლება მოითხოვდეს თანამშრომლის „გავეცანი“-ს. მიღებული ქვითარი ინახავს user/version/time-ს. ჩამოტვირთვა ან email-ის მიწოდება გაცნობას არ უდრის. ახალი მნიშვნელოვანი ვერსია ქმნის ახალ საჭიროებას. read receipt დისციპლინურ დასკვნად ავტომატურად არ გადაიქცეს.

## 6. მონაცემთა მოდელი და Laravel კონტურები

გამოიყენეთ არსებული `app/Domain/Governance`/Identity/Tenancy/Audit ერთეულები საჭიროებისამებრ; DocumentManagement შეიძლება იყოს მათი მკაფიო ქვემოდული ან ცალკე domain. არ შექმნათ პარალელური audit სისტემა მხოლოდ იმიტომ, რომ ამ დოკუმენტში სახელი სხვაგვარადაა მოცემული.

- `document_workspaces`: tenant, title, classification, parent/group context.
- `documents`: tenant, workspace, owner, responsible, type, title, latest_version_id, published_version_id, lifecycle status, retention category, archived_at.
- `document_versions`: document, tenant, ordinal, private blob key, checksum, original filename, verified MIME, size, content metadata, author, created_at, scan state. unique(document_id, ordinal).
- `document_access_grants`: tenant, resource, principal user/group, permission set; inheritance/override წესი explicit.
- `approval_requests`: document_version, initiator, workflow snapshot, state, due_at, lock/version token.
- `approval_steps` და `approval_decisions`: assigned reviewer, order/rule, decision, comment, acted_at; duplicate გადაწყვეტილება idempotent.
- `document_comments`: exact version/thread, author, body, resolved state; sanitization.
- `document_acknowledgements`: published version, recipient, notified_at, acknowledged_at.
- `document_register_entries`: tenant/category/year/number, document, issued_at; შესაბამისი unique constraints.
- არსებულ audit events-ში document create/upload/download (საჭიროების მიხედვით), grant/revoke, submit/approve/return, publish/restore/archive/export/retention actions.

Actions: CreateDraft, UploadVersion, SubmitForReview, DecideApproval, PublishVersion, RestoreAsDraft, ArchiveDocument, GrantAccess, AcknowledgeVersion. transitions ერთ database transaction-ში, version/state/permission-ის განმეორებითი შემოწმებით. validation მარტო frontend-ში არ იყოს.

მარშრუტების კონტურები: index, show, versions, upload-version, submit-review, approval-decision, publish, download, restore-as-draft, archive, acknowledge. ზუსტი სახელები მოარგეთ უკვე არსებულ პროექტს. ყველა მათგანში tenant route binding + policy + audit + rate limit შესაბამისი რისკის მიხედვით.

## 7. უსაფრთხო ფაილები და preview

MVP-ში ნებადართული ფორმატები სკოლის საჭიროებით განსაზღვრეთ: PDF/DOCX/XLSX/PPTX და შესაბამისი სურათები. executable, HTML/script და macro-enabled Office default აკრძალულია. მარტო extension-ს ნუ ენდობით; MIME/sniffing, ზომა, checksum, quarantine/ანტივირუსი. archive/zip პირველი ეტაპის ატვირთვებში საჭირო არ არის.

ორიგინალები private tenant-prefixed storage-ში. მოკლევადიანი signed download მხოლოდ policy-ის შემდეგ; URL-ში საიდუმლო დოკუმენტის სათაური არ ჩასვათ. preview იმავე უფლებებით და იზოლირებული conversion worker-ით, timeout/resource limits-ით. quarantine ფაილს preview/download/approval უფლება არ აქვს. დიდი export-ები queue-ში, expiry-ით და დამოუკიდებელი წვდომის შემოწმებით.

სრული ტექსტის ძიება/OCR მოგვიანებით, მხოლოდ სკანირების და დაშვებული classification-ის შემდეგ. ძიების ინდექსი ACL/tenant-ის გათვალისწინებით; ისტორიული snippet/cached preview permission revocation-ის შემდეგ მიუწვდომელი უნდა გახდეს. დროებითი signed URL-ების revocation-ის შეზღუდვები documented TTL-ით შეამცირეთ; ძალიან სენსიტიურზე proxy download გამოიყენეთ.

Word-ის/Excel-ის ონლაინ ერთობლივი რედაქტორი ნულიდან არ ააშენოთ. MVP არის upload/version/preview/review. Microsoft/Google/OnlyOffice/Collabora ინტეგრაცია ცალკე გადაწყვეტილებაა ლიცენზიის, hosting-ის, data residency-ისა და permission sync-ის შეფასებით. გარე ანგარიში/კონტენტი ამ დავალებით არ დაუკავშირებია მომხმარებელს.

## 8. შენახვის პოლიტიკა და ოპერირება

კატეგორიების მიხედვით retention სკოლის უფლებამოსილმა პირმა განსაზღვროს; ყველა დოკუმენტისთვის ერთნაირი თვითნებური ვადა არ დააწესოთ. archive, soft delete, საბოლოო წაშლა და legal hold განსხვავდება. hold ბლოკავს ავტომატურ purge-ს. restore/read logs და backup recovery საჭიროებისამებრ შემოწმდეს.

backup მოიცავს ბაზასა და შესაბამის immutable blobs-ს; მხოლოდ DB backup არ არის სრული აღდგენა. orphan upload cleanup დაიშვას მხოლოდ quarantine/unreferenced ფაილებზე მკაფიო ასაკის ზღვრით; ისტორიული referenced version არ წაიშალოს. storage quotas, scan queue, failed conversions და pending approvals ადმინისტრაციულ monitoring-ში აისახოს.

## 9. განხორციელების ეტაპები

1. tenant/workspace permissions + უსაფრთხო upload/version storage + index/search/filter/preview.
2. მასწავლებელი → დირექტორი single-reviewer workflow, გადაწყვეტილების ისტორია, immutable approved version.
3. published version და თანამშრომლებზე გაცნობის დადასტურება; template library.
4. კათედრის მრავალსაფეხურიანი განხილვა, რეესტრი, ვადები/reminders და კონტროლირებადი delegation.
5. retention/restore drills, OCR/გარე editor ინტეგრაცია მხოლოდ საჭიროების დადასტურების შემდეგ.

ეს მოდული დაიწყეთ identity/tenant permissions foundation-ის შემდეგ. არ დაელოდოთ live payment პროვაიდერს — დამოუკიდებელი სამუშაოა. ფაილების ინფრასტრუქტურა სასწავლო რესურსებსაც შეიძლება გამოადგეს, მაგრამ access scope-ები არ გააერთიანოთ ბრმად.

## 10. პროტოტიპის რეალური საზღვრები

დაემატა `DocumentCenter.tsx`, `documents.css` და „დირექტორი“ როლი. მასწავლებელს/ადმინისტრაციას/დირექტორს პორტალში აქვთ „დოკუმენტები“; მობილურზე ზემოთ ცალკე ღილაკი. მუშაობს: საქაღალდის/სტატუსის ფილტრი, ძიება, ტექსტური preview, ისტორია, ახალი ტექსტური მონახაზი, ახალი ვერსია, მასწავლებლის გაგზავნა და დირექტორის approve/return (შენიშვნა აუცილებელია).

შიდა staff როლებს შორის გადართვა ინარჩუნებს ამ კომპონენტის დროებით მონაცემებს: მასწავლებლის გაგზავნილი დემო ჩანაწერი დირექტორისთვის ჩანს. გვერდის reload ან staff სივრციდან გასვლა state-ს შლის. ეს არის ფიქციური საერთო დემო dataset და არა real ACL. რეალური ატვირთვა, ბაზა, მუდმივი audit timestamp, რეალური მრავალვერსიული ფაილის ჩამოტვირთვა, restore, sharing და თანამშრომლის ხელმოწერა არ განხორციელებულა.

შეხვედრებისა და სხვა SchoolServices-ის დამოუკიდებელი დემო state ცალკე რჩება; არ ივარაუდოთ საერთო backend მხოლოდ UI კავშირის საფუძველზე.

## 11. აუცილებელი ტესტები

- tenant/user/workspace საზღვრები index/show/search/snippet/history/download/upload/approve/export-ზე.
- მასწავლებელი ვერ ამტკიცებს თავის მოთხოვნას; ტექნიკური admin ვერ იღებს დირექტორის permission-ს მხოლოდ UI-დან.
- approval უკავშირდება ზუსტ checksum/version-ს; ახალი revision ძველ approval-ს ვერ იყენებს.
- დამტკიცებული bytes უცვლელია; working v2-ის დროს reader იღებს published v1-ს.
- ორი პარალელური save/decision არ ქმნის დაკარგულ ცვლილებას ან ორმაგ transition-ს.
- return reason აუცილებელია; cancelled/replaced request-ზე დაგვიანებული approve უარყოფილია.
- quarantine/არასწორი MIME/ზედმეტი ზომა/expired URL და გაუქმებული grant სწორად მუშავდება.
- restore ქმნის ახალ draft-ს; ძველი audit და approval არ იცვლება.
- ახალი published version საჭირო acknowledgement-ს ახალ მოთხოვნად ქმნის.
- rollback/backup restore აღადგენს DB-სა და blobs-ს თანხვედრილად; hold ბლოკავს purge-ს.

მიღების შედეგში გაარჩიეთ: დაწერილია / ტესტირებულია / ბრაუზერში დადასტურებულია / გარე ინტეგრაცია დარჩენილია. არც demo state და არც წარმატებული compilation არ ამტკიცებს production უსაფრთხოებას.
