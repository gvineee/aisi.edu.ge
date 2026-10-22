# უსაფრთხოების checklist (OWASP ASVS 5.0-ზე დაფუძნებული)

განახლებულია: 2026-10-03. მოთხოვნილია `CLAUDE-PLATFORM-MODULES.md` §15-ით. ეს **არ არის** ASVS-ის სერტიფიცირება ან სრული აუდიტი — ეს არის გადამოწმებადი სია: რა არის კოდში, რა რჩება ნაწილობრივ და რა არ არის. ყოველი „✓“ ეყრდნობა კონკრეტულ კოდს/ტესტს.

სტატუსი: ✓ გაკეთებულია და ტესტით დაფარულია · ◐ ნაწილობრივ · ✗ არ არის

| სფერო (ASVS) | სტატუსი | მტკიცებულება | რა აკლია |
|---|---|---|---|
| V8 Authorization — tenant isolation | ✓ | `BelongsToTenant` + explicit `tenant_id` checks; cross-tenant ტესტები (Messaging, Finance, Substitution, Platform, Health…) | — |
| V8 Authorization — ობიექტის დონე (IDOR) | ✓ | controller-ებში `abort_unless(... tenant_id ...)` + role/guardian/assignment შემოწმება read endpoint-ებზეც. **route-ების ინვენტარი** (`RouteInventoryTest`, 2026-10-22): ყველა route ან `auth`-ის მიღმაა, ან საჯარო allowlist-შია, მიზეზის მითითებით. ყოველი ავტორიზებული route (200+) სტუმრად მოწმდება: შედეგი login ან 4xx უნდა იყოს. private disk-ის ჩაშენებული `/storage/{path}` route გამორთულია (`serve => false`): ფაილები მხოლოდ policy-შემოწმებული controller-ით გაიცემა | ინვენტარი სტუმარს ამოწმებს; როლებს შორის (მაგ. მშობელი → პერსონალის route) და tenant-ებს შორის — მოდულის ტესტები, არა ავტომატური ინვენტარი |
| V8 Guardian flags (academic/financial/pickup) | ✓ | `GuardianLink` flags; FinanceSandboxTest, Gradebook, Pickup ტესტები | — |
| V6 Authentication — MFA privileged roles | ✓ | `EnsureAdminMfa` (admin/director/accountant), finance/health 2FA, `platform` middleware; `AdminMfaEnforcementTest` | WebAuthn/passkey არ არის |
| V6 Rate limiting | ◐ | login (Fortify), 2FA, admissions lead, pickup codes/verify, invitations, messages/broadcast, payment start, webhooks, report-card verify | გლობალური per-tenant ლიმიტი; abuse monitoring |
| V7 Session management | ◐ | Laravel session (Redis production), CSRF ყველა web form-ზე (webhooks — ხელმოწერით) | „სხვა მოწყობილობებიდან გასვლა“/device list UI არ არის |
| V6 Privileged re-auth | ✓ | `RequireRecentPassword` (`reauth`): მოწვევა/როლის გაუქმება, health permission grant/revoke, support access decision; 2FA settings (starter kit) | guardian link-ის რედაქტირება ცალკე UI-ში ჯერ არ არის |
| V3 Secure headers + CSP | ◐ | `SecurityHeaders`: nosniff, X-Frame-Options, Referrer-Policy, Permissions-Policy, HSTS (HTTPS); CSP nonce-ით (Vite + inline bootstrap script), `SECURITY_CSP=report-only` | `enforce` — მხოლოდ ბრაუზერში ყველა გვერდის გადამოწმების შემდეგ; report-uri endpoint |
| V5 File handling | ✓ | private `local` disk, MIME/size allow-list, sha256, `scan_status` + `ScanStatus::servable`, policy-checked download (portfolio, health care plan, message attachments) | `ClamdFileScanner` (INSTREAM, `UPLOADS_SCANNER=clamd`) მზადაა და ტესტირებულია; სერვერზე clamd-ის დაყენება ღიაა — მანამდე `NullFileScanner` = `skipped` |
| V5 SVG/brand upload | ✓ | brand logo: SVG უარყოფილი, ფერები ვალიდირდება (`PlatformAdministrationTest`) | — |
| V11 Cryptography / sensitive data | ◐ | health ველები encrypted cast-ით; pickup codes HMAC; ფული integer | key rotation runbook |
| V16 Logging / audit | ✓ | `AuditLogger` (როლები, guardian links, შეფასება, დასწრება, ფინანსი, export, support access, ჩანაცვლება, broadcast) — payload/ტოკენი არ იწერება | ცენტრალიზებული log shipping/alerting |
| V13 Webhooks | ✓ | ხელმოწერა, unique provider event id, idempotent, amount/currency შემოწმება, out-of-order (FinanceSandboxTest) | outbound webhooks არ გვაქვს |
| V14 Data protection — notifications | ✓ | ელფოსტაში შეტყობინების ტექსტი/booking note არ იგზავნება (`MessagingExtrasTest`, `AppointmentReminderTest`) | — |
| V14 Indexing of private areas | ✓ | `NoIndexPrivateAreas` + robots Disallow | — |
| V13 Secrets | ◐ | `.env` (placeholders `.env.example`-ში), არაფერი commit-ში | secret manager / rotation |
| Telemetry / analytics | ✓ | მოსწავლის დონის tracking არ არის; director pulse — მხოლოდ aggregate, health გამორიცხულია | — |
| Operations — backup/restore | ✗ | `docs/deployment-and-rollback.md` გეგმა | restore drill production-ზე არ ჩატარებულა |
| Operations — queue/scheduler monitoring | ✗ | scheduler (`appointments:send-reminders`) და queue jobs არსებობს | production-ზე worker/cron/failed-jobs monitoring არ არის აწყობილი |

## შემდეგი უსაფრთხოების ნაბიჯები (პრიორიტეტით)

1. Production: queue worker (supervisor) + `* * * * * php artisan schedule:run` cron + failed jobs alert.
2. Backup + restore drill (`clpctl db:export` + storage), შედეგი ჩაიწეროს.
3. სერვერზე clamd → `UPLOADS_SCANNER=clamd`, შემდეგ `UPLOADS_ALLOW_UNSCANNED=false` (adapter უკვე არის).
4. CSP: ბრაუზერის console-ში report-only დარღვევების შემოწმება → `SECURITY_CSP=enforce`.
5. Device/session list.
