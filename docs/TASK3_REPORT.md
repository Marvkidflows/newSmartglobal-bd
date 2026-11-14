# SSI Website — TASK 3 COMPLETION REPORT
## Separate General Public Mailing from Investor Mailing (+ departmental mailboxes)

Delivered as an **overlay**: new files plus four tiny focused patches. Nothing from Task 2, Rank & Benefits or any financial workflow was modified.
Built against `smartInvest-backend-FULL (2)` and `smartInvest-frontend-RANKS_7`.

---
## 0. READ THIS FIRST — what was and was not verified

| Item | Status |
|---|---|
| PHP code (migrations, models, services, controllers, jobs, routes) | **Written. NOT executed.** The build sandbox has no PHP runtime and no network, so `php -l`, `php artisan migrate`, and PHPUnit could not be run. |
| PHPUnit tests (50 test methods in 4 files) | **Written, NOT executed. No test result is claimed.** |
| Static checks actually performed | Brace/paren balance scan on all new PHP files (passed); manual line-by-line review, which found and fixed 3 real bugs (see §9). |
| Frontend (React) | New/changed JSX + JS **parsed successfully with Babel** (syntax only). A full `vite build` could **not** be run (the shipped `node_modules` contains Windows-only rollup/esbuild binaries). The UI was **not** opened in a browser. |
| Brevo inbound/delivery webhooks, sender-verification check | Written against Brevo's documented payload shapes; **never exercised against a live Brevo account.** |

**Before accepting this task, run (on a machine with PHP 8.2+):**
```
php artisan migrate
php artisan test --filter=Mailing        # expect to fix small issues; none are claimed passing
cd frontend && npm run build
```
I expect the first run may surface small defects (this was written blind). Please send me the output and I will fix them.

---
## 1. What was built
* **Two environments, enforced server-side** (`public` / `investor`): separate permissions, audiences, consent purposes, templates, campaigns, history and dashboards.
* **Public mailing:** contact/enquiry form, newsletter + announcement sign-up (double opt-in), signed confirm/unsubscribe pages (RFC 8058 one-click compatible), consent ledger, campaigns, templates, scheduling, delivery + failure monitoring.
* **Investor mailing:** campaigns restricted to *verified, eligible investors*; one-to-one support correspondence; investor-visible history of their own messages; optional-mail preferences.
* **Departments & staff mailboxes:** departments, membership, one personal mailbox per staff member (+ optional department address), assignment, incoming/outgoing records, staff-activity audit.
* **Admin UI:** `Public Mailing` and `Investor Mailing` pages (Overview, Campaigns, Templates, Subscribers/Consent, Unsubscribes, Delivery & history, Failed emails, Departments & mailboxes, Departmental history, Audit trail, Permissions).

### Key rules implemented
1. **Verified investor** = `role=investor` AND `status=active` AND `kyc_verified=true` AND `email_verified_at` set (`InvestorEligibility`). Checked when the audience is built **and again at the moment of sending** (a person who is suspended in between is skipped).
2. Public bulk mail **never** reaches verified investors; investor mail **never** goes to anyone else. Cross-environment audience types are rejected (422). A rank audience with no ranks is empty, never "everyone".
3. Public content cannot use investor merge tags (`{{rank}}`), unknown tags (`{{balance}}` etc.) or investor account numbers (`INVSTR-…`). No campaign can include balances.
4. **Consent:** `newsletter`/`announcement` need a current `granted` subscription for the exact purpose; purposes are environment-bound (`public_*` can't authorise investor mail). Every change appends a timestamped ledger row (purpose, source, policy version, salted IP hash). Public sign-up is double opt-in.
5. **Essential vs marketing:** `essential`, `transactional`, `enquiry`, `support` never consult marketing consent, so unsubscribing cannot switch off account/security notices. `essential` campaigns exist only in investor mailing and need the separate `investor.send_essential` permission; they are audited.
6. **Duplicate-send protection (3 layers):** atomic `draft|scheduled→queued` claim (second "Send" → 409); unique per-recipient idempotency key; atomic `queued→sending` claim per message before the transport is touched. Create is idempotent via `client_token`.
7. **Header-injection:** CR/LF/NUL/`%0a`/`%0d` rejected with 422 in subject, names, addresses (validation closure) **and** stripped again in `MailHeaderGuard` before every send; recipient must be exactly one plain address.
8. **Rate limiting:** public contact and subscribe: 5/min **and** 20/day per IP (separate counters), honeypot field, identical-submission de-dupe (10 min), one automatic acknowledgement per address per 24 h (prevents using the form to spam third parties).
9. **Credentials:** no password, key or token is stored per mailbox. `credential_ref` accepts only an env-var *name* (`^[A-Z0-9_]{3,64}$`). One provider key (`BREVO_API_KEY`) lives in the environment. No shared passwords by design.
10. **Honest mailbox status:** a new mailbox is `not_connected`. It becomes `connected` only when Brevo's verified-sender list (`GET /v3/senders`) reports that exact address as active. Otherwise mail is sent from the default verified sender with the mailbox as Reply-To.
11. **Logging/audit:** `mail_audit_logs` for every admin action (campaign create/send/cancel, template, mailbox, permission, viewing a message body). Metadata is scrubbed of bodies/passwords/tokens. Application logs carry ids only — no addresses, bodies or secrets. Provider error text is redacted before storage.
12. IDs are looked up **after** the permission middleware (not via route-model binding), so unauthorised staff cannot probe which record ids exist.

---
## 2. Complete new / changed file list
**NEW — backend**
```
config/mailing.php
database/migrations/2026_11_08_100001_create_mailing_core_tables.php
database/migrations/2026_11_08_100002_create_mailing_consent_tables.php
database/migrations/2026_11_08_100003_create_mailing_campaign_tables.php
routes/mailing.php
resources/views/emails/mailing.blade.php
app/Mail/MailingMail.php
app/Jobs/SendMailingMessageJob.php
app/Jobs/StartMailingCampaignJob.php
app/Console/Commands/MailingDispatchScheduled.php
app/Http/Middleware/RequireMailingPermission.php
app/Http/Controllers/Mailing/{Concerns,CampaignController,CommunicationController,DepartmentController,SubscriberController,PublicMailingController,InvestorMailingController,WebhookController}.php
app/Models/Mailing/{MailAuditLog,MailCampaign,MailConsent,MailContact,MailDepartment,MailMailbox,MailMessage,MailPermission,MailSubscription,MailTemplate}.php
app/Services/Mailing/{AudienceResolver,CampaignService,ConsentService,InvestorEligibility,MailHeaderGuard,MailboxService,MailingAudit,MailingPermissions,MessageSender,OutboundService,PublicMailingService,SafeText,WebhookProcessor}.php
tests/Feature/Mailing/{MailingTestHelpers,MailingSeparationTest,MailingConsentTest,MailingPermissionsTest,MailingDeliveryTest}.php
```
**NEW — frontend**
```
src/services/mailingApi.js
src/pages/admin/AdminMailing.jsx
src/pages/admin/AdminMailing.css
src/pages/public/MailingForms.jsx        (PublicContactForm, NewsletterSignup — not auto-mounted)
```
**CHANGED (shared files — one-line/focused patches in /patches)**
```
backend/routes/api.php                     +1 line  (require __DIR__.'/mailing.php')
backend/routes/console.php                 +1 scheduler line
frontend/src/App.jsx                       +1 import, +2 routes
frontend/src/layouts/AdminLayout.jsx       +2 nav items
```
**NOT touched:** `app/Models/User.php`, `AppServiceProvider.php`, `bootstrap/app.php`, `src/services/api.js`, any Rank/Benefit/financial file, any existing mailing controller (`AdminEmailController`, `FinancialEmailController`, `InvestorEmailController`, `SentEmail`, `EmailTemplate`).

---
## 3. Database migration instructions
```
php artisan migrate            # 3 additive migrations; no existing table is altered
```
Creates: `mail_permissions`, `mail_departments` (seeded with 4 departments via `insertOrIgnore`: general-enquiries, registration, investor-support, financial), `mail_department_members`, `mail_mailboxes`, `mail_contacts`, `mail_subscriptions`, `mail_consents`, `mail_templates`, `mail_campaigns`, `mail_messages`, `mail_audit_logs`.
**Rollback:** `php artisan migrate:rollback --step=3` drops only those tables in FK-safe order; nothing else is affected (the seeded departments disappear with their table). Works on MySQL and SQLite (no raw SQL).

---
## 4. Environment / configuration (no secrets in code)
Add to `.env` (all optional except where noted):
```
MAILING_MAILER=brevo                    # default; uses the existing SSI 'brevo' mailer
MAILING_FROM_ADDRESS=                   # falls back to MAIL_FROM_ADDRESS (must be a Brevo-verified sender)
MAILING_FROM_NAME="Smart System Investment"
MAILING_WEBHOOK_SECRET=                 # REQUIRED to enable provider webhooks; leave empty to disable them
BREVO_API_KEY=                          # already used by SSI; also used for the mailbox sender check
MAILING_CONSENT_POLICY_VERSION=2026-11
MAILING_CONTACT_PER_MINUTE / _PER_DAY   # (informational; limits are set in routes/mailing.php)
```
Operations: a **queue worker** (`php artisan queue:work`) and the **scheduler** (`php artisan schedule:run` each minute) must be running for queued sends and scheduled campaigns.

---
## 5. API endpoint documentation
All staff routes: `Authorization: Bearer <token>`, prefix `/api`. `{env}` ∈ `public|investor`; any other value → 404.

### Public (no auth)
| Method & path | Purpose | Limits |
|---|---|---|
| `POST /public/mailing/contact` | Enquiry `{name,email,subject?,message,subscribe_newsletter?,website(honeypot)}` → 202 | 5/min, 20/day per IP |
| `POST /public/mailing/subscribe` | `{email,name?,purposes:[public_newsletter,public_announcements]}` → 202, identical response for known/unknown addresses | 5/min, 20/day |
| `GET/POST /public/mailing/unsubscribe/{ref}/{purpose}` | Signed link. GET shows a button; **POST** unsubscribes (also RFC 8058 one-click) | 60/min |
| `GET/POST /public/mailing/confirm/{ref}/{purpose}` | Signed (7 days) double-opt-in confirmation; POST grants | 60/min |
| `POST /mailing/webhooks/delivery` · `/mailing/webhooks/inbound` | Provider events; `X-SSI-Webhook-Secret` or Bearer = `MAILING_WEBHOOK_SECRET`; 401 if unset/wrong | 300/min |

### Verified investor (`investor` + `check.account`)
`GET /investor/mailing/history` · `GET /investor/mailing/history/{id}` (own only) · `GET|PUT /investor/mailing/preferences` `{investor_newsletter,investor_announcements}`

### Staff — environment-independent
| Path | Permission |
|---|---|
| `GET /mailing/my/messages` | any staff (scoped to own departments; admin sees all) |
| `GET /mailing/messages/{id}` (opens body, audited) | `{env}.view` of the message's environment **or** member of its department |
| `GET /mailing/departments` | `departments.view` or `.manage` |
| `POST /mailing/departments` · `PUT /mailing/departments/{id}` · `PUT …/{id}/members` · `POST …/{id}/mailboxes` · `PUT|DELETE /mailing/mailboxes/{id}` · `POST /mailing/mailboxes/{id}/check` | `departments.manage` |
| `GET /mailing/audit` | `audit.view` |
| `GET /mailing/permissions` · `PUT /mailing/permissions/{user}` | **admin only** |
| `POST /mailing/public/subscribers` (double-opt-in invite) · `POST /mailing/public/contacts/{id}/status` | `public.manage` |

### Staff — per environment `/mailing/{env}/…`
| Path | Permission |
|---|---|
| `GET dashboard` · `GET messages` · `GET failures` · `GET campaigns` · `GET campaigns/{id}` · `GET templates` | `{env}.view` |
| `GET audiences` · `GET campaigns/{id}/audience-preview` (count only) | `{env}.manage` or `.send` |
| `POST campaigns` · `PUT|DELETE campaigns/{id}` · `POST|PUT|DELETE templates` · `POST messages/{id}/assign` | `{env}.manage` |
| `POST campaigns/{id}/schedule|send|cancel` · `POST messages` (1-to-1/reply) · `POST messages/{id}/retry` | `{env}.send` (+ `investor.send_essential` for `kind=essential`) |
| `GET subscribers` · `POST subscribers/{id}/unsubscribe` · `GET unsubscribes` · `GET consents?email=` | `{env}.manage` (investor address lists protected) |

Filters: `messages|failures` accept `status, direction, kind, department_id, campaign_id, from, to, q, per_page`; `campaigns` accepts `status, q`; `subscribers` accepts `purpose, status, q`.
Error codes: 401 unauthenticated · 403 no permission / not staff / suspended staff · 404 unknown env/record/wrong-environment record · 409 already started/not retryable · 422 validation (including header injection, forbidden merge tags, ineligible recipient) · 429 rate limited.

---
## 6. Roles & permissions
| Permission | Grants |
|---|---|
| `public.view` / `.manage` / `.send` | See / create-edit-templates-subscriber lists / schedule-send-cancel-retry **public** mailing |
| `investor.view` / `.manage` / `.send` | Same, for **investor** mailing |
| `investor.send_essential` | Create/send `essential` notices (bypass marketing consent) |
| `departments.view` / `.manage` | See / administer departments, members, mailboxes |
| `audit.view` | Read the mailing audit trail |

* **Admin:** holds all implicitly. **Only admins** grant/revoke permissions (UI: *Permissions* tab; API: `PUT /mailing/permissions/{user}`).
* **Other staff** (`financial`, `marvflow_member`, `marvflow_lead`): nothing by default. **Investors can never hold a permission.**
* **Department membership** gives read access to that department's correspondence only, without environment-wide rights.
* Public ≠ investor: holding every `public.*` permission gives **no** access to any investor endpoint (tested).
* Suspended/deactivated staff are refused via the existing `StaffAccountGuard`.

---
## 7. Tests
**Written (50 methods) — none executed here:**
* `MailingSeparationTest` (9): public excludes verified investors; investor audience only verified/eligible (KYC, suspended, unverified email); cross-environment audience rejected; empty rank audience; public content restrictions; ineligible-recipient rejection both ways; send-time eligibility re-check; investor sees only own history.
* `MailingConsentTest` (8): double opt-in + ledger (purpose, timestamp, source); signed-link tamper/GET-safe/POST; unsubscribed skipped; marketing skipped without consent while essential delivered; unsubscribing never stops essential; purposes environment-bound; no enumeration; investor purposes not subscribable publicly.
* `MailingPermissionsTest` (13): unauthenticated/investor/ungranted refused; public↔investor permission isolation; view/manage/send levels; essential permission; admin-only grants; wrong-environment record; department scoping + staff-activity audit; audit without bodies; invalid requests; script stripping.
* `MailingDeliveryTest` (20): success; failure (secret redaction + failures list); retry cap; manual retry; single-send guarantee; campaign double-start 409 + no duplicate rows; idempotent create; scheduling (not-early, once-only, past rejected, cancel); header injection (6 vectors + unit); contact rate limit (429); enquiry storage/ack-once/de-dupe; honeypot; history filters; mailbox honesty + Brevo check (faked HTTP); mailbox validation; webhook auth/bounce/complaint; inbound routing/idempotency.

**Executed:** none (no PHP available). **Frontend syntax parse:** 5 files OK. **Passed tests claimed:** 0.

---
## 8. Known limitations
1. **Not executed** — see §0. Treat as unverified until the test suite is run.
2. **Receiving real mail** into staff mailboxes needs provider-side setup (Brevo inbound parsing route + the webhook URL + secret). The code stores inbound payloads once that is configured; it does not poll an IMAP/POP inbox. Until then the Departments tab will show mailboxes as *not connected* and only website enquiries (contact form) and webhook-delivered mail appear as incoming.
3. `connected` means "verified outbound sender at Brevo", not "has an inbox". Real per-person inboxes (Google Workspace / Microsoft 365 / a hosted mail server) must be provisioned with the mail host; SSI still needs to choose that service (**management decision**).
4. Webhook field names follow Brevo's documented shapes but are untested against live traffic.
5. UI is mounted in the **Admin** layout only. Non-admin staff (financial / MarvFlow) with granted permissions can use the API, but their dashboards have no menu item yet (needs a small patch to their layouts — deliberately not done to avoid touching other teams' files).
6. The public contact form component (`MailingForms.jsx`) is supplied but **not wired** into the existing `ContactPage.jsx`; one import + `<PublicContactForm />` does it. The older `POST /public/contact-support` is left untouched.
7. Existing investor mail features (`AdminEmailController`, `SentEmail`, Financial email) keep working unchanged and are **not** migrated into the new log; investor messages sent through the new module appear in `/investor/mailing/history`, not on the old `/investor/emails` page. Consolidating the two is recommended later.
8. Campaign bodies are edited as HTML in a textarea with a live sanitised preview; the existing `RichComposer` was not reused (unknown props, avoids coupling).
9. No in-app notification to the creator when a campaign finishes; progress is visible on the Campaigns/Overview tabs.
10. In sync-queue mode a transient failure is not auto-retried by the worker (it stays `queued` for the next manual/retry action); with the normal database queue it is released with a 60 s delay.
11. Audience preview counts iterate the audience (fine for thousands, not optimised for millions).
12. GDPR-style erasure/export of a contact is not implemented (records can be suppressed/unsubscribed).

---
## 9. Defects found and fixed during my own review (so you know what "blind" review caught)
1. Laravel passes `':a,b'` middleware parameters as separate arguments; the permission middleware originally only read the first → fixed with variadic parameters.
2. `connection_status` is not mass-assignable on `MailMailbox`; creation/update/deactivation now use `refresh()`/`forceFill()`.
3. A test re-registered `Http::fake()` (the first stub would have won) → replaced by `Http::sequence()`.

---
## 10. Shared-file conflict list
| File | Change | Conflict risk with Task 2 |
|---|---|---|
| `routes/api.php` | **append** 1 `require` line at the very end | Low — an append; if Task 2 also appends, merge both lines |
| `routes/console.php` | **append** 1 scheduler line | Low |
| `src/App.jsx` | +1 import after `AdminEmailCenter`; +2 `<Route>` lines inside `/admin` after `email-center` | Low–Medium: if Task 2 edits the same region, keep both |
| `src/layouts/AdminLayout.jsx` | +2 items in the nav array after "Email Center" | Low–Medium |
| `User.php`, `AppServiceProvider.php`, `bootstrap/app.php`, `api.js` | **untouched** | None |

Task 3 uses no `users` table changes and adds no migration that alters a Task 2 table; table names are all prefixed `mail_`.

---
## 11. Integration instructions
1. Copy the contents of `backend/` and `frontend/` over the respective project roots (all new files; nothing is overwritten except the 4 shared files below).
2. Apply the patches from `/patches` (`git apply patches/api.php.patch` from `backend/`, etc.) **or** make the edits by hand — each is 1–3 lines (see the patch files). Do not overwrite the whole shared file.
3. `composer dump-autoload` (not strictly needed; PSR-4).
4. `php artisan migrate`
5. Add the `.env` keys in §4; run the queue worker and scheduler.
6. In the admin UI: **Public Mailing → Permissions** — grant staff the permissions they need. Create mailboxes under *Departments & mailboxes*, then **Check connection**.
7. Configure Brevo (outside this codebase): verify each sender address; set transactional-event and inbound-parse webhooks to `POST /api/mailing/webhooks/delivery` and `/inbound` with the shared secret.
8. Run `php artisan test --filter=Mailing` and `npm run build` and send me the results.

---
**Integration note (superseded statements).** After the Tasks 2–5 integration: delegated staff reach mailing through permission-driven screens at `/{financial|marvflow|registration}/mailing` (not only the admin pages); the Registration roles are recognised as staff but can never hold `investor.*` permissions, see investor departments/messages/audit rows, or join non-public departments. See `docs/integration/`.
