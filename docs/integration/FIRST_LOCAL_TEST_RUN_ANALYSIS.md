# First local test run — analysis and fixes

Source: the console output you pasted (run on Windows, SQLite in-memory). **I have not executed anything**; the fixes below are corrections made by reading the failures. They are unverified until you re-run. Results marked "reported" are yours, not mine.

## 1. What your run showed
Passing (reported): `RegistrationRoleSecurityTest` (5), `RegistrationEmailTest` (3), `MailingConsentTest` (8), `MailingDeliveryTest` (20), `MailingSeparationTest` (9), `NewsWorkflowTest` (12), most of `NewsVisibilityTest`/`NewsMediaTest`/`MailingPermissionsTest`/`WithdrawalFeeCalculatorTest`, 75 of 81 shown Task 5 tests, and 14 of 15 in `PrivacyPolicyRegistrationTest`. Not shown (cut off or not reached): the Rank suites, most of `tests/Feature/Registration`, the tail of `ReferralRewardTest`, anything after the memory crash.

## 2. Every failure, its cause, and the fix
| # | Failure | Cause | Kind | Fix |
|---|---|---|---|---|
| 1 | `Allowed memory size of 134217728 bytes exhausted` (full run) | PHP CLI `memory_limit` is 128 MB; the suite is large | environment | Set `memory_limit = 1G` in `php.ini` (XAMPP: `C:\\xampp\\php\\php.ini`) **and** run `php -d memory_limit=-1 artisan test`; or run folders separately |
| 2 | `RegistrationReviewTest`: *Cannot override final method PHPUnit\\Framework\\TestCase::status()* | test helper named `status` clashes with PHPUnit 11+ | **test bug** (Task 2, never run before) | helper renamed `changeStatus` (21 uses) |
| 3 | `PrivacyPolicyRegistrationTest › resuming an incomplete registration…`: emailed code was `null` | Laravel's test kernel re-runs earlier requests' "after response" callbacks on every later request in one test. A stale callback from the first stage-1 fired after the account existed, issued a resume code and started the 60-second cooldown, so the second request sent nothing | **robustness issue in my new code** (not reachable in production: one request = one process) | the after-response closure is now **run-once** |
| 4 | `MailingPermissionsTest › essential kind is not available in public mailing`: 403 instead of 422 | the controller's permission check (403) ran before the validation that rejects the kind (422) | **code ordering** (Task 3) | public + `essential` now returns **422 on `kind`** before any permission check; investor-environment 403 unchanged |
| 5 | `NewsMediaTest › video upload…`: 422 "Only MP4 or WebM" for a valid test MP4 | the check required the platform's libmagic to name the file exactly `video/mp4`; libmagic names MP4/WebM variants differently across platforms (this could also reject real uploads) | **code fragility** (Task 4) | the **container signature** (`ftyp` / EBML) now decides the type, the extension must match, and the sniffed MIME must be one of the tolerated video/octet-stream names; non-video content is still refused |
| 6 | `NewsVisibilityTest › feed payload never contains storage internals…`: found `"path"` | `"path"` is Laravel's **paginator URL** key (`http://…/api/public/news`), not a storage path | **test too broad** | storage internals (`"path"` and the other needles) are asserted inside the items; the rest of the needles still apply to the whole payload |
| 7 | `WithdrawalFeeCalculatorTest › invalid amounts…`: *Too few arguments* | `@dataProvider` doc-comments are not supported by PHPUnit 11+ | **test bug** | `#[DataProvider('badAmounts')]` attribute |
| 8 | `ReferralRewardTest › configured criteria are enforced`: `deposits.payment_method` NOT NULL | the test's raw insert omitted a required column | **test bug** | `payment_method` added |
| 9 | `ReferralRewardTest › only an administrator can approve the policy…` and `WithdrawalFeeTest › admin approval requires a management reference…`: *reason must be at least 3 characters* | tests sent `'reason' => 'r'`; the controller rule (≥ 3) is intended | **test bug** | tests send a real reason |
| 10 | **`WithdrawalFeeTest › confirmed submission freezes…`: snapshot `fee_type` was `none`, expected `fixed`** | **real defect (Task 5)** in `WithdrawalFeeCalculator`: `$base + [...]` — PHP's array `+` keeps the LEFT value on duplicate keys, so a charged fee was quoted and frozen with `fee_type = none` and null `fixed_amount`/`percentage` (the fee, net and explanation were right) | **code defect** — wrong audit snapshot and wrong `fee_type` in the quote API | `array_merge($base, [...])`; regression test added to `WithdrawalFeeCalculatorTest` |
| 11 | `WithdrawalFinancialSecurityTest`: no `…:pending` / `…:payout` ledger entry | the wallet ledger is **off by default** (`FINANCIAL_LEDGER_ENABLED=false`), so no row is written | **test assumption** (mine) | the test enables `financial.ledger_enabled`; the MySQL rounds test sets `FINANCIAL_LEDGER_ENABLED=true` for its child processes |

## 3. Real defects found by running the tests
Only **#10** (calculator snapshot) is a defect in money-adjacent code, and it affected the audit snapshot and the quote's `fee_type`, **not** the fee, net or debit amounts. #3 (run-once) and #4/#5 are robustness/ordering corrections. Everything else was test code that had never been run.

## 4. Important note about the wallet ledger
`FINANCIAL_LEDGER_ENABLED` is **false by default** in your original project. With it off, withdrawals and approvals write **no** `wallet_ledger_entries` rows — the balance, `balance_adjustments` and the withdrawal row remain the record. The ledger-consistency review therefore applies only when the flag is on; decide whether it should be enabled in production.

## 5. Re-run order
```powershell
php -d memory_limit=-1 artisan test tests/Feature/Registration/RegistrationReviewTest.php
php artisan test tests/Feature/PrivacyPolicyRegistrationTest.php
php artisan test tests/Feature/Mailing/MailingPermissionsTest.php
php artisan test tests/Feature/News
php artisan test tests/Unit/Task5
php artisan test tests/Feature/Task5
php artisan test tests/Feature/Registration
php -d memory_limit=-1 artisan test tests/Feature/Rank
php -d memory_limit=-1 artisan test
```
Send me any remaining failure with its message.

---
# Second local run (full suite, reported by you)
**Result: 1,508 passed, 6 failed, 2 skipped (18,480 assertions), 379 s** — run with `php -d memory_limit=-1 vendor\bin\pest`. The previous round's failures (calculator snapshot, essential notices, news video, ledger-flag tests, PHPUnit 11 incompatibilities, resume code) no longer appear. All 21 `RegistrationEnumerationTest` tests, `RegistrationAccessTest`, `RegistrationReviewTest`, `RegistrationTrackingTest` and `RegistrationHardeningTest` passed. The 2 skipped are most likely the MySQL-only suites (they skip without `SSI_MYSQL_CONCURRENCY`) — a skip is not a pass.

| # | Failure | Cause | Kind | Fix |
|---|---|---|---|---|
| 1 | `IntegrationGapClosureTest › registration staff cannot use investor mailing…`: 404 instead of 403 | the test requested `/api/mailing/investor/overview`, which does not exist (the route is `/dashboard`) | **test bug** | URL corrected |
| 2 | `… never see investor departments…`: the public department was missing for a staff member holding only `departments.view` | my department filter hid public departments from users without a `public.*` grant — **stricter than intended** | **code (mine)** | public departments are always listed to anyone who may list departments; investor/both only with an `investor.*` grant (never Registration roles) |
| 3 | `… staff password change…`: the other token was NOT revoked | **real defect (mine)**: with a transient/session token `method_exists(getKey)` was false, so nothing was revoked | **code defect** | all other tokens are revoked; only a real current Sanctum token is kept. A second test uses a genuine Bearer token |
| 4 | `RegistrationFollowUpTest › follow up is not available once…`: a mail was sent | the test asserts "nothing sent" after approving, but approval legitimately emails the applicant | **test bug** (Task 2, never run before) | the mail fake is reset after the approval; the follow-up attempt must send nothing |
| 5 | `RegistrationResumeSecurityTest › staff accounts flagged incomplete…`: 429 | the route throttle `throttle:10,1` has no name, so **stage 1, verify and the other 10/min routes share one bucket per IP**; the loop over roles makes more than 10 calls | **test bug** | the test disables the route throttle (the per-IP budgets are tested separately) |
| 6 | `… failed deliveries still count toward the hourly cap`: `MailFake::__construct(): Argument #1 must be MailManager, null` | `Mail::fake()` was called while the failing Mockery mailer was still installed in the container | **test bug** | the mock is cleared (`clearResolvedInstance` + `forgetInstance('mail.manager')`) before faking |

**Observation (not changed):** because the unnamed `throttle:10,1` bucket is shared, one visitor doing stage 1 + code entry + a resend consumes several of the 10 requests/minute. That is the original project's throttle design; the registration endpoints stay within it for a normal journey.

## Run commands that work on your machine
```powershell
php -d memory_limit=-1 vendor\bin\pest                         # whole suite
php -d memory_limit=-1 vendor\bin\pest tests\Feature\Registration
php -d memory_limit=-1 vendor\bin\pest --filter=staff_password_change
```

---
# Third local run (full suite) and frontend run, reported by you
**Backend: 1,512 passed, 3 failed, 2 skipped (18,503 assertions), 666 s.** All six failures from the previous round are gone.
**Frontend (`npm test`): 11 test files, 80 tests passed**, including `registrationResume.test.js`. The `npm run build` output was not in what you pasted, so the production build is **still unconfirmed**.

## The 3 remaining failures — all in your ORIGINAL tests, none caused by the integration
`ApprovalWorkflowHardeningTest › unfreeze cannot reactivate a suspended account`, `… an administrator cannot suspend freeze or deactivate their own account`, and `MassAssignmentTest › legitimate admin status changes still work` each got a **500: "Could not reach the remote Brevo server"**.
* I compared the files byte-for-byte with your original: `AdminUserController`, `AccountStatusNotification`, `config/mail.php`, `tests/TestCase.php` and all three test files are **identical to the original**. The integration did not touch this path.
* Cause: `AccountStatusNotification` hard-codes `->mailer('brevo')`, which bypasses the test setting `MAIL_MAILER=array`. Suspend/freeze/unfreeze therefore attempt a **real Brevo send**. Offline, that is a 500; **online with a real `BREVO_API_KEY` in `.env`, these tests would send real emails.** They presumably passed before only because the machine could reach Brevo.
* Fix (test isolation only): `Mail::fake()` in `ApprovalWorkflowHardeningTest::setUp` and in the one `MassAssignmentTest` test. Database notifications are untouched and no assertion was changed.
* **Recommended safety step:** create a `.env.testing` **without real keys** (blank `BREVO_API_KEY`, `TELEGRAM_*`, `CLOUDINARY_URL`, `MAIL_MAILER=array`). Because `phpunit.xml` sets `APP_ENV=testing`, Laravel will then load it instead of `.env`, so no test can ever use live credentials.
* Production observation (original code, not changed): `suspend()` saves the new status and *then* calls `notify()` with no guard, so a Brevo outage returns a 500 even though the account **was** suspended. Decision for you: should notification failures be caught and logged so the admin action still reports success?

## Still not confirmed
The 2 skipped tests (the MySQL suites unless you set `SSI_MYSQL_CONCURRENCY`), `npm run build`, and the manual browser walkthrough.

**Follow-up:** Management decided that a notification failure must not fail a saved suspension; implemented in `AdminUserController::suspend()` — see `SUSPEND_NOTIFICATION_PATCH.md`.
