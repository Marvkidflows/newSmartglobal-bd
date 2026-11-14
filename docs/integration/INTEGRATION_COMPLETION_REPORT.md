# SSI Tasks 2–5 — Integration Completion Report (final remediation round)

**Status: INTEGRATED, NOT VERIFIED BY EXECUTION.** All four tasks are merged into the original project, but this build environment had **no PHP, Composer, database or network**, so no PHP file was linted, no migration run, no PHPUnit test executed, and the frontend build/tests could not run. Do not treat the system as accepted until the commands in `MIGRATION_AND_TEST_GUIDE.md` pass on your machine.

## 1. What was integrated
| Task | Integrated | Source handling |
|---|---|---|
| 2 Registration Team | dashboard, applications, KYC review, status, notes, request-info/follow-up, assignment, activity, consent-aware tracking, `registration_member`/`registration_lead`, admin oversight, resume-by-emailed-code security | applied as delivered (it was built on the current original; only its stale `console.php` was replaced) |
| 3 Mailing | separate public/investor environments, consent + unsubscribe ledger, campaigns, templates, departments/mailboxes, delivery-failure monitoring, webhooks, permissions | overlay applied; registration roles made recognisable (never `investor.*`) |
| 4 News & Media | public/members/verified-investor feeds, CMS, scheduling, moderation, image/video, signed media URLs | overlay applied; investor news de-duplicated |
| 5 Referral + Withdrawal fees | codes/links, funnel events, reward ledger, policy versioning, fraud flags, fee policy/calculator/quote/snapshot/sub-ledger | **only genuine Task 5 changes merged**; its stale copies of original files discarded |

## 2. Verification status (honest)
| Check | Result |
|---|---|
| PHP syntax (`php -l`) on changed files | **BLOCKED** — no PHP runtime |
| `artisan route:list`, duplicate-route check by Laravel | **BLOCKED** |
| Migrations against an isolated database | **BLOCKED** (reviewed by reading only — see §4) |
| All PHPUnit tests (Task 2: 106 + PrivacyPolicy; Task 3: ~50; Task 4: ~30; Task 5: ~80; original Rank/financial suites) | **WRITTEN or pre-existing, NOT EXECUTED by me** |
| Babel parse of all 173 frontend JS/JSX files | **PASSED** (0 failures) |
| Babel scope analysis of all frontend files for undefined identifiers (missing imports) | **PASSED** (0; two `DOMParser` hits are a browser global) |
| Every original backend route (396) and frontend `<Route>` (92) still exists; no original file missing | **PASSED** (static comparison) |
| Index/foreign-key names in the 9 new migrations ≤ 64 characters (MySQL limit; heuristic parser, 116 names) | **PASSED** (0 over) — not proven by running the migrations |
| Every relative import in `frontend/src` resolves to a file | **PASSED** (0 unresolved) |
| Every `use App\…` / `App\…::class` reference in `app/` and `routes/` points to an existing file | **PASSED** (0 missing) |
| Frontend API calls (`api.js`, `mailingApi.js`, `newsApi.js`) have a matching backend route (crude suffix match) | **PASSED** (3 apparent misses are index routes declared as `'/'` inside prefix groups) |
| Duplicate route names | **INCONCLUSIVE — correction of an earlier "passed"**: a textual scan cannot apply group name-prefixes (`admin.`, `financial.`, …); it lists 61 same-suffix names that come from different prefixed groups, as in your original. Only `php artisan route:cache` can prove there is no collision (Guide §B) |
| Earlier lexical bracket-balance attempt on PHP | **DISCARDED** (mis-tokenised) and replaced by the checks below |
| String/comment-aware `{} () []` balance on PHP (validated first on your 505 original PHP files: 0 false positives) | **PASSED** on all 648 integrated PHP files (control: 0 false positives on your 505 originals) — **this is NOT `php -l`** and cannot catch other syntax errors |
| Namespace and class name match the file path for every class under `app/` and `tests/` (autoload) | **PASSED** (0 mismatches) |
| Every `[Controller::class, 'method']` in `api.php`, `mailing.php`, `news.php` points at an existing method (500 references) | **PASSED** |
| Vitest / `npm run build` | **BLOCKED** — the shipped `node_modules` has Windows-only rollup/esbuild binaries |
| Browser / end-to-end walkthroughs (registration→KYC, mailing separation, news visibility, referral credit, fee display) | **NOT TESTED** (covered only by the written tests listed above) |

## 3. Acceptance conditions — what I can and cannot confirm
| Condition | Status |
|---|---|
| All four tasks present in the original project | **Yes (by file/route/import inspection)** |
| Rank & Benefits intact | **Yes by construction**: no Rank file differs from the original (130 backend + 30 frontend Rank files compared, all byte-identical; see ORIGINAL_VS_INTEGRATED_COMPARISON.md). Not executed |
| No privileged account via public registration / resume | Guard **present** (`RegisterController` uses Task 2 `resume->eligible()`; Task 5's weaker version dropped). Role is server-set `investor`. **Not executed** |
| Registration Team cannot reach financial/investor-sensitive data | Registration roles appear in no `financial.*` gate; refused by admin/financial/investor/marvflow middleware; barred from `investor.*` mailing perms. **Not executed** |
| Public vs investor mailing separated | Implemented in Task 3 code (audience/consent/permission separation). **Not executed** |
| News visibility/permissions | Implemented server-side in Task 4. **Not executed** |
| Referral cannot be credited twice | DB-unique `credit_reference` + `referred_id`, status machine. **Not executed** (incl. the MySQL concurrency test) |
| Fee cannot be deducted twice | unique `event_key`, idempotency key, approval-time collection once. **Not executed** |
| Unapproved financial policies inactive | Migration seeds referral policy **unapproved** with no criteria enabled and fee policy `none`/inactive; calculator charges only active+approved+effective; a referral policy cannot be approved with zero criteria. **Not executed** |
| Backend/frontend routes compatible | Static check passed (§2) |
| Set-up from the documented instructions | Written; **not run** |

## 4. Migration review (by reading)
* 119 migrations total (110 original + 9 new); no duplicate table creation introduced by Tasks 2–5 (the pre-existing duplicate `announcements` create is in the original).
* Order: `2026_11_06_100001` registration roles → `100002` tracking tables → `2026_11_07_100001` resume challenges and Task 5 tables (independent) → `2026_11_08_100001` delivery_status, mailing core (3 files) → `2026_11_09_100001` news. Same-timestamp prefixes sort by filename and have no dependency on each other.
* Foreign keys reference only `users` and tables created earlier in the same or earlier migration; Task 5 tables use plain indexed columns (no FKs). Task 5 adds only nullable columns to `withdrawals` and backfills `gross=amount, fee=0, net=amount, reference WD-<id>` (a pure copy; `amount` untouched).
* `2026_11_06_100001_add_registration_roles` is MySQL-only (`MODIFY COLUMN` enum, idempotent); `down()` refuses if registration accounts exist.
* Task 5's rewrite of `2026_11_03_100001_create_controlled_financial_operations` was **not** taken; the original is unchanged.
* Rollback: each task's `down()` drops only its own tables/columns. Task 5 `down()` drops the withdrawal columns (**data in them is lost**) — never roll back after fees have been collected.
* **Not run against any database.**

## 5. Changes to existing financial behaviour (documented, as required)
1. `InvestorWithdrawalController::store()` restructured by Task 5 (fee quote, `lockForUpdate` on the investor row, balance + one-pending checks moved inside one transaction, optional Idempotency-Key). With no fee policy approved, the fee is 0.00 and `amount` still means "gross requested" and is exactly what approval debits. `AdminWithdrawalController` approve/reject call `WithdrawalFeeService::onApproved/onRejected` inside the existing transaction (no-ops without a fee).
2. `WalletLedger::CLASSIFICATIONS` gained three informational classifications (`withdrawal_fee_collected`, `withdrawal_fee_reversed`, `referral_reward_credit`). Balances still move only through `BalanceAdjustmentService`.
3. Investor withdrawal API rows now include gross/fee/net/reference/fee-status fields (additive).
No deposit, wallet-credit or Rank calculation was changed. **Limitation:** the original has no investor cancellation, failed-payment or reversal-of-approved-withdrawal workflow; none was invented.

## 6. Other integration fixes made
* Rebuilt `routes/console.php` from the original (Task 2/5 copies were stale).
* Registration roles added to Task 3/4 staff-role lists; hard-blocked from `investor.*` mailing permissions.
* Investor "programme" reward amount hidden until the referral policy is approved.
* One investor news experience (see conflict report).
* `.env.example` extended with the new variable names (no values).
* Removed the live `.env`, stray SQLite files, logs and compiled views from the packages.

## 7. Gap-closure round (added after the first integration)
**Changed**
1. Delegated staff: `GET /api/mailing/my/permissions`, `GET /api/news-admin/my/permissions`; permission-aware mailing/news pages; `StaffMailing`/`StaffNews` screens at `/{financial|marvflow|registration}/{mailing|news}`; menu links shown only when a permission exists; admin menu link to `/registration/dashboard`. Backend scoping so Registration roles never see investor departments, messages or audit rows and cannot join non-public departments.
2. `referrals:scan --no-backfill` scheduled hourly (:17), non-overlapping; `evaluate()` gained a `$scheduled` mode (skips decided rows, logs only changes). Manual run: `php artisan referrals:scan` (adds the one-off backfill).
3. ~~Stage-1 anti-enumeration budget — mitigation only.~~ **Superseded in the final-remediation round (§9): stage 1 no longer discloses anything.**
4. Registration CSV export (lead/admin) and staff password-change page/endpoint.
5. Fee reverse/waive/refund disabled by default (`WITHDRAWAL_FEE_ADJUSTMENTS_ENABLED=false`); existing tests for it enable the flag explicitly, plus a new default-off test.
6. Removed `rank-test-results.txt`, `mysql-results.txt`, `routes.json` from the package (see STALE_RESULTS_INVESTIGATION.md).
7. New written-not-executed tests: `IntegrationGapClosureTest` (6), `RegistrationEnumerationThrottleTest` (2), `test_fee_reversal_waiver_and_refund_are_disabled_by_default` (1).

**Deliberately not changed:** Rank thresholds/downgrade policy; any financial calculation; deposit/withdrawal/wallet logic; the $5 seed row (kept: `ReferralPolicy::current()` and Task 5's own tests rely on a policy row; it is unapproved with all criteria off); withdrawal cancellation/failed-payment/reversal (proposal only); maker/checker (proposal only); full stage-1 enumeration fix (needs verify-first redesign); old announcements migration.

**Not executed:** none of the above PHP was run; frontend re-checked statically only (173 files parse, 0 unresolved imports).

## 8. Final-corrections round (verification against the original source)
**Corrections made**
1. **Documentation:** every statement that `referrals:scan` is unscheduled was corrected (the actual `routes/console.php` schedules `referrals:scan --no-backfill` hourly at :17; backfill is manual). `UNRESOLVED_ISSUES_AND_DECISIONS.md` and `SHARED_FILE_CONFLICT_REPORT.md` were the stale ones.
2. **Guide rewritten:** the three database scenarios (new empty DB / restored copy / production) are separate, `migrate:fresh` is confined to the empty test database, the two MySQL suites (and the third original one) each have their own command, and the Task 5 unit and feature suites have separate commands.
3. **MySQL concurrency analysis (item D):** locking and transaction behaviour documented from the code; the test was found to **double-count the approval's own audit row**, which turns the safe `[422, 200]` outcome into a false failure; the counting was corrected without relaxing any invariant. **Runtime verification is outstanding.**
4. **Regression found and fixed:** `MarvflowLayout.jsx` (original CRLF) had been rewritten with LF line endings by my earlier edit, making a 9-line change look like a 116-line rewrite; the original line endings are restored. A scan of all modified files for line-ending changes found no other case.
5. **Comparison with the original** (new report) found **no accidental removal**: 0 missing routes, 0 missing files, 0 modified Rank files, `composer.json`/`composer.lock`/`package.json`/F02 SQL identical.

**Verified by reading (not executed):** delegated-staff permissions are enforced by the backend (route middleware, `MailingPermissions::can/forUser`, `NewsPermissions`, controller-level environment filters), not only by hidden menus; Registration roles are in no financial, admin, investor or investor-mail permission path; seeded referral policy is unapproved with all criteria off and the investor view hides the amount; fee reverse/waive/refund is off by default.

**Still not executed:** PHP syntax, migrations, all PHPUnit/Pest and MySQL suites, frontend Vitest and production build, browser walkthroughs.

## 9. Final unresolved-risk closure round
**Implemented (code):** verify-first registration that closes email enumeration, junk-account creation and OTP/Telegram abuse; a fail-closed guard that stops referral credit/reversal and fee refunds bypassing the ORIGINAL dual-approval threshold; code pruning; stricter MySQL concurrency tests. **Reviewed, no defect found:** the whole withdrawal submission/approval/rejection/fee/ledger flow. **Plans only (Management decisions):** withdrawal cancellation/failed payment/reversal, maker/checker.

**Static checks this round (all passed; none is a runtime test):** 173 frontend files parse, 0 undefined identifiers, 0 unresolved imports; 648 PHP files balanced; every namespace/class matches its path; 500 route→controller-method references resolve; 0 missing class references; 0 original routes/files missing; 0 Rank files changed; index/FK names ≤ 64 chars (the new migration's single index name is `reg_email_chal_email_created_idx`).

**Executed tests: NONE.** Written and unexecuted: `RegistrationEnumerationTest` (21), `WithdrawalFinancialSecurityTest` (11), `MySqlWithdrawalRoundsTest` (6, MySQL), 3 dual-approval-threshold tests in `ReferralRewardTest`, the earlier gap-closure tests, and the updated existing registration/Task 5 tests.

| Risk | Original problem | Correction implemented | Tests added | Tests executed | Remaining action |
|---|---|---|---|---|---|
| Email enumeration at stage 1 | 422 / code / account+token differed by email state | Stage 1 creates nothing and returns one identical response; account only at `/register/verify` after an emailed code; staff/completed/inactive get nothing | `RegistrationEnumerationTest` (21) + updated registration tests | None | Run tests; **frontend build/test not run**; confirm mailer is configured |
| Phone enumeration | 422 "phone registered" for anyone | Phone checked only after a correct emailed code; per-IP budget on that answer | in the same suite | None | **Management:** SMS proof or KYC-time duplicate handling to close fully |
| Junk accounts / OTP & Telegram abuse | each probe created an account, an OTP email and a Telegram alert | No account/alert before email proof; per-address DB cooldown (60 s) + hourly cap (5); per-IP budgets; 5-guess lockout | suite above | None | Run tests |
| Timing side channel | mail send only in some cases | work after response + response-time floor (400 ms) | floor test | None | Not a formal constant-time proof |
| Withdrawal `store()` rewrite | unreviewed financial change | Full review; no defect; observations W1/W2 documented | `WithdrawalFinancialSecurityTest` (11) | None | Run tests; decide W1/W2 |
| Dual-approval bypass | referral credit/reversal/fee refund skipped the original second-approver threshold | Fail-closed refusal at/above the existing `balance_threshold_usd` | 3 tests | None | Run tests; confirm the 10,000 USD placeholder |
| Overdraw under concurrency | one-shot test, mis-counted entries; failure unexplained | Counting corrected; new repeated-rounds suite classifying every entry | `MySqlWithdrawalRoundsTest` (6) | **None — MySQL not available** | **Run the MySQL suites (guide §6); issue stays open until they pass** |
| Cancellation / failed payment / reversal | not in the original | Implementation plan; nothing activated | – | – | Management decisions D1–D8 |
| Maker/checker | same person can approve+credit a reward; policy draft+approve | Existing engine documented; plan; no authority changed | – | – | Management decisions M1–M6 |
| Fee adjustments/refunds | feature existed (dormant) | Disabled by default | default-off test | None | Keep off until approved |
| Stale result files | old results could be mistaken for current | Removed from packages; investigated | – | – | – |

## 10. First local test run (reported by you) and corrections
You ran part of the suite locally. Of what was shown, most Task 3/4/5 and registration tests passed; 1 memory crash and ~11 failures were analysed in `FIRST_LOCAL_TEST_RUN_ANALYSIS.md`: **one real Task 5 defect** (the fee calculator reported `fee_type none` for a charged fee — snapshot/quote only, amounts were correct), three code robustness/ordering fixes (run-once after-response callback, essential-notice validation order, video container detection) and seven test faults (PHPUnit 11 incompatibilities, missing fixture column, too-short reasons, an over-broad `path` check, ledger flag assumption). All fixes are **unverified until re-run**. The earlier statement "Executed tests: NONE" referred to my own environment; the pass/fail figures above are yours.

## 11. Second local run (full suite, reported by you)
**1,508 passed, 6 failed, 2 skipped (18,480 assertions).** The six were two code faults of mine (department filter too strict; password change did not revoke other tokens when the current token was transient) and four test faults (wrong URL, an over-strict "no mail" assertion after an approval, the shared 10/min route throttle, a leftover Mockery mailer). All corrected and **unverified until re-run**; details in `FIRST_LOCAL_TEST_RUN_ANALYSIS.md`. MySQL concurrency suites: the 2 skips mean they have **not** yet run — that risk remains open.

## 12. Third local run (reported by you)
Backend **1,512 passed / 3 failed / 2 skipped**; frontend **80/80 passed**. The 3 failures were original `Remediation` tests that attempt a real Brevo send through a notification that hard-codes the `brevo` mailer; `Mail::fake()` was added to those tests (isolation only). `npm run build` and the MySQL concurrency suites remain unconfirmed.

## 13. Suspension notification patch
`AdminUserController::suspend()` now keeps a saved suspension successful when the notification fails (logged securely, reported in the response, in-app notification preserved). One original file modified, one test file added; tests written, not executed. See `SUSPEND_NOTIFICATION_PATCH.md`.
