# RANKS_7 — Final Verification Report

**Status: NOT production-ready. Controlled local testing and demonstration only.**
Nothing here was deployed, no production setting or data was touched, no webhook was registered with Telegram, and no real payment or investor data was used.

## How to read this report

Every result is tagged with how it was established. These are not interchangeable.

| Tag | Meaning |
|---|---|
| **[AUTO-USER]** | PHPUnit output produced on the project owner's Windows machine and pasted into the conversation. The report author did not execute it. |
| **[INSPECT]** | The author read the source code. Shows what the code does. It does not prove runtime behaviour. |
| **[PARTIAL]** | A real tool was run, but it is not the project's official check. Scope stated each time. |
| **[NOT RUN]** | Not executed. Reason and exact manual steps given. |

**Authoring environment limits.** The author's sandbox has no PHP, no Composer, no MySQL, no browser, and no DNS (npm, Packagist and GitHub all failed to resolve). So: no PHPUnit run, no `npm ci` / `npm run build`, no MySQL migration, and no browser test could be performed by the author. Section 12 lists them with the steps to run them.

---

## 1. Project and environment

- Backend: Laravel 12.62.0, Sanctum 4.3.2. Frontend: React 18 + Vite 5 (`frontend/`, `package.json` name `smart-system-investment`). [INSPECT]
- Not a Git checkout (no `.git` in the supplied archives), so branch and working-tree state could not be reported.
- Automated tests run on in-memory SQLite per `phpunit.xml`. Production is MySQL. The test suite therefore cannot touch the production database. [INSPECT]
- Owner's machine: Windows, PHP/Composer present (tests ran). Path seen in the log: `...\smartInvest-backend-RANKS_7 (1)`. The frontend is evidently not a sibling of that folder (inferred from the skipped test, see §5).

## 2. Changes made

| File | Change | Why |
|---|---|---|
| `app/Http/Controllers/Admin/AdminDashboardController.php` | Month grouping uses a per-driver SQL expression; the MySQL branch emits the same `MONTH(created_at)` as before. The `catch` now calls `report($e)` and returns file path / line only when `APP_DEBUG` is on. | `MONTH()` does not exist in SQLite, so both dashboards threw a 500 in tests. The old catch also leaked server file paths to callers. |
| `bootstrap/app.php` | The five role middlewares (`admin`, `financial`, `marvflow`, `marvflow.lead`, `investor`) are placed before `SubstituteBindings`. | Model binding used to run before the role check, so a wrong-role caller got 404 for a missing id and 403 for an existing one (an id-enumeration oracle). |
| `tests/Feature/FinancialMailingAddressTest.php` | `forgetGuards()` before the guest request; KYC assertions read `submission.*`. | Test bugs: the "guest" was still the previous `actingAs` user; the endpoint wraps its payload in `submission` (the frontend and `RankBadgeCoverageTest` rely on that). |
| `tests/Feature/FinancialTransactionsAndCommunicationsTest.php` | Added a 403 assertion against an investment that exists, plus an unchanged-end-date check. | Proves the role gate, not only the missing-id case. |
| `tests/Feature/Rank/RankTelegramDepositTest.php` | **Added 2 tests** (additions only): `test_a_usd_declaration_without_verified_receipt_cannot_be_approved_or_credited` and `test_invalid_conversion_data_cannot_be_used_to_bypass_verification`. | Closes a coverage gap. No existing test covered an investor *declaring USD* without staff verification, or negative / malformed / null rates, an unsupported currency, and zero / negative paid amounts. |
| `phpunit.xml` | `force="true"` on `DB_CONNECTION` and `DB_DATABASE`. | Without `force`, PHPUnit does not override an already-set OS environment variable, so a stray `DB_CONNECTION` could point tests at a real database. |

**The two new tests have never been executed.** They were written against helpers and response shapes read from the source and checked for balanced syntax, but without PHP. Run them first (§12). If either fails, treat it as a defect in the test, not in the app, until shown otherwise.

## 3. Backend installation and migration

| Check | Result |
|---|---|
| `composer install` | **[NOT RUN]** (no PHP / network in the authoring sandbox). The owner's machine ran the suite, so dependencies were present there. |
| Local MySQL migration, `migrate:status`, host and database-name check | **[NOT RUN]** (no MySQL). Steps in §12. |
| Migration review | **[INSPECT]** `2026_10_21_100001_harden_deposit_payment_verification` adds a UNIQUE index on `investment_accounts.source_deposit_id`. It will **fail on any database that already holds duplicate non-null values** there. Run the pre-check in §12 before migrating a database that has data. A new empty database is unaffected. |

## 4. Full backend test totals

- **[AUTO-USER]** Full suite on the owner's machine (SQLite): **425 passed, 0 failed, 1 skipped, 2,352 assertions**, 100.84 s.
- **[AUTO-USER]** The five previously failing tests each passed individually, and the three affected files passed together (**60 passed, 303 assertions**).
- **Not yet true:** the totals after this report's additions. Expected on a green run: **427 passed, 1 skipped**. That is a prediction, not a result.

## 5. The skipped test

- `Tests\Feature\Rank\RankIconsTest::test_the_frontend_badge_component_maps_every_supported_icon`
- Skip reason (from the source): `Frontend source not alongside the backend in this checkout.` The test looks for `../frontend/src/components/RankBadge.jsx` relative to the backend. On the owner's machine the frontend is not a sibling folder, so it self-skips.
- Criticality: **low.** It checks that each rank icon has a UI mapping. It involves no deposit, payment, currency, Telegram, authorization or money logic.
- **[PARTIAL]** The exact assertion was reproduced outside PHPUnit (same regex, icon list read from `InvestorRank::SUPPORTED_ICONS`) against the real `RankBadge.jsx`. All six icons (`shield`, `shield-check`, `award`, `star`, `diamond`, `crown`) are mapped. This is a reproduction, not a PHPUnit run.
- To make it run: place the `frontend` folder next to the backend folder (same parent directory), then `php artisan test --filter=RankIconsTest`.

## 6. Frontend

| Check | Result |
|---|---|
| `npm ci` | **[NOT RUN]**. It failed in the authoring sandbox with `ENOTFOUND registry.npmjs.org`. The partial `node_modules` it left was deleted. |
| `npm run build` | **[NOT RUN]**. vite and the React plugin could not be installed. **The frontend build has not been verified.** |
| Parse check, all 102 `.js` / `.jsx` files (esbuild) | **[PARTIAL]** 0 parse failures. |
| Bundle from `src/main.jsx` with relative imports resolved and npm packages left external | **[PARTIAL]** Succeeded, 0 errors. This shows there are no syntax errors and no broken relative imports. It does **not** show that vite or the plugin work, that CSS and asset handling are correct, or that the app renders. |
| Third-party imports vs `package.json` | **[INSPECT]** All 10 packages imported by `src/` are declared. None missing. |
| API contract, deposits | **[INSPECT]** The frontend's deposit and payment calls map to existing routes. `confirmDeposit` sends `_method=PUT` over POST, matching the backend `PUT .../confirm`. |

## 7. Browser end-to-end testing

**[NOT RUN]: every scenario.** No browser exists in the authoring sandbox, and neither the Laravel backend nor the Vite frontend can start there. Nothing in this section was observed in a browser.

Where the same behaviour is covered by automated tests (all on SQLite, **[AUTO-USER]**), the matrix in §14 names the test. That is not a substitute for the browser run, which still has to confirm what the screens display.

## 8. Telegram security

- **[INSPECT]** `VerifyTelegramWebhook` returns **503** when the configured secret is shorter than 16 characters (disabled until configured), **403** on a missing or wrong `X-Telegram-Bot-Api-Secret-Token` header, and compares with `hash_equals`. It is applied to `/telegram/webhook` together with `throttle:120,1`.
- **[INSPECT]** `TelegramWebhookController` imports no deposit, wallet, investment, rate or rank class. It forwards the investor's text to staff and sends a fixed acknowledgement. It has no code path that approves, credits, records a rate, or creates an investment.
- **[AUTO-USER]** `RankTelegramDepositTest`: webhook disabled until configured; rejects a missing token; a weak secret counts as unconfigured; the old authenticated webhook path is gone; Telegram messages never approve or change a deposit. All passed.
- **Observation, not changed:** the controller does `Log::info('Telegram webhook received', $update)`, which writes the full payload (investor message text and Telegram IDs) to the application log. That is private investor data in logs. Recommend redacting it before any shared environment.
- **[NOT RUN]** Valid-vs-invalid header requests against a running local server. No webhook was or should be registered with Telegram.

## 9. Currency, USD confirmation and duplicate-credit integrity

**[INSPECT] — how it works**
- Declared payment, staff-verified receipt, and USD valuation are separate fields (`paid_currency` / `paid_amount`, `payment_verified_at`, `approved_amount` / `fx_rate`). The investor's `confirm` endpoint only *declares*; it cannot set verification.
- Approval is blocked unless `payment_verified_at` is set. A non-USD payment needs a positive rate, a source of at least 3 characters, a rate date and a payment date (none in the future). The approved USD figure must follow paid × rate within `RANKS_APPROVED_AMOUNT_TOLERANCE_PERCENT` (default 1%) unless staff acknowledge a difference. All of this is re-checked **inside the approval transaction**, so a figure changed after verification cannot slip through.
- The `confirm_usd` shortcut requires a written reason (5+ characters), is attributed to the staff member, and is refused outright if the investor declared any non-USD currency.
- Approval runs in a single `DB::transaction`: deposit row locked, status checked inside the lock (only `pending` / `hold`), blockers re-checked, audit record written in the same transaction, user row locked before the balance increment, a `BalanceAdjustment` ledger row written, and an investment created only if none exists for `source_deposit_id`.
- Database backstop: a UNIQUE index on `investment_accounts.source_deposit_id`, so one deposit can open at most one investment even in a race.

**[AUTO-USER] — covered by passing tests**
`a deposit with unknown payment cannot be approved credited or invested`, `a declared non usd payment cannot be approved until the rate is recorded`, `non usd payments need a rate a source and a payment date`, `the approved amount must follow from the paid amount and rate`, `a figure changed after verification blocks approval`, `a retry cannot credit or invest twice`, `the database itself refuses a second investment for one deposit`, `double approval is rejected and audited once`, `a rejected deposit creates no investment and no valuation`, `pending held and rejected deposits never count`, and the `RankCurrencyTest` / `RankMultiCurrencyTest` groups.

**Limits that matter**
1. **Concurrency is not proven.** `lockForUpdate()` does nothing on SQLite, and every test runs sequentially. The suite proves that a *repeated* approval is rejected. True parallel-request safety rests on MySQL/InnoDB row locking plus the UNIQUE index, which is sound by design but **unverified**. Verify it against local MySQL (§12, step 7) before anyone claims race protection.
2. **"Verified receipt" is staff attestation, not independent evidence.** The system records that an authorized staff member says the money was received (note of 5+ characters, attributed, audited). It does not require an uploaded bank slip or statement match, and a typed rate with a 3-character source is accepted. This follows the existing design and cannot be made "independent" by code alone. **Decision for SSI Management:** should evidence (an attached proof, a second approver, or a minimum note) be mandatory before approval?
3. The new tests (§2) add explicit coverage for a declared-USD deposit with no verification, and for zero, negative, malformed and null rates plus unsupported currency and non-positive amounts. They have not been run.

## 10. Rank & badge rules

- **[INSPECT]** Thresholds in `config/ranks_proposed.php` match Management's table exactly: Bronze $100 / 1 cycle, Silver $1,000 / 2, Gold $5,000 / 3, Platinum $10,000 / 5, Diamond $20,000 / 8, Elite $50,000 / 12. They are minimums, not caps, and the highest qualifying rank wins.
- **Status of the rules:** that file is labelled *proposed, not approved*, is data-only, and its loader refuses to run outside local / testing / development or on a non-local database host. In production, an authorized Admin enters the approved criteria in the dashboard. `RANKS_CURRENCY_CONFIRMED` defaults to **false**, which blocks activation of any money-based criterion until Management confirms the ranking currency.
- **[AUTO-USER]** `RankProposedCriteriaTest`, `RankDecisionsTest`, `RankMultiCurrencyTest`, `RankEvaluationTest`, `RankNotificationTest`, `RankAuditTest`, `RankHoldTest` and `RankManualChangeTest` all passed. They cover: every threshold boundary (one cycle or one cent short never qualifies), conversion with a recorded rate, a huge raw amount in a weak currency not buying a rank, unverified currency counting as zero, completed-cycle rules (an early-marked completion does not count before the scheduled end date), no automatic downgrades, no duplicate upgrades or notifications, and `ranks never change balances profits withdrawals or financial records`.
- **[NOT RUN]** Observing badges and rank screens in a browser.

## 11. Authorization and role boundaries

- **[AUTO-USER]** Guest, investor, financial, admin and other-staff matrices all passed, including `financial cannot use admin endpoints to move money or change status`, `no non admin role can reach any rank route`, and `financial user cannot access admin routes`.
- **[INSPECT]** The role-gate reorder (§2) was checked: none of the five middlewares reads a route-bound model. Every existing 404 assertion in the suite uses a user whose role matches the route group, so none should change. Behaviour change to be aware of: a wrong-role or suspended user now gets 403 / 401 before any "not found" lookup.
- **[NOT RUN]** The post-change full suite.

## 12. Checks not performed, and how to perform them

| Check | Blocker | Steps |
|---|---|---|
| New tests + full suite | No PHP in the authoring sandbox | `php artisan test` (expect 427 passed, 1 skipped if both new tests pass) |
| Frontend `npm ci` / build | No registry access | `cd frontend; npm ci; npm run build` |
| MySQL migration | No MySQL | See "Local MySQL" below |
| Browser flows | No browser, no running app | See §13 and §14 |
| Concurrent approval on MySQL | Needs MySQL + two parallel requests | Local MySQL step 7 below |

### Local MySQL (dedicated, disposable database)

Use a **new, empty** database that exists only for this test. Never point these at a shared or production host.

1. Create it (PowerShell, MySQL on this machine):
   `mysql -u root -p -e "CREATE DATABASE ssi_local_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`
2. In the backend `.env`, set by name: `APP_ENV=local`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=ssi_local_test`, `DB_USERNAME`, `DB_PASSWORD`, `QUEUE_CONNECTION=database`, `TELEGRAM_WEBHOOK_SECRET` (any local string of 16+ characters), and for the rank demo `RANKS_CURRENCY_CONFIRMED=true`. Leave the bot token, chat IDs and API keys blank locally.
3. **Verify the target before anything else** (must print `127.0.0.1` and `ssi_local_test`):
   `php artisan tinker --execute="echo config('database.connections.mysql.host').' / '.config('database.connections.mysql.database');"`
4. If (and only if) the database already contains data from an earlier run, check for duplicates before migrating:
   `SELECT source_deposit_id, COUNT(*) FROM investment_accounts WHERE source_deposit_id IS NOT NULL GROUP BY source_deposit_id HAVING COUNT(*) > 1;`
5. `php artisan migrate` (not `migrate:fresh`), then `php artisan migrate:status` (every migration should read `Ran`), then `php artisan route:list --path=api | Select-String "telegram|deposits"`.
6. Check `storage/logs/laravel.log` for SQL errors or missing columns.
7. Concurrency probe: with the app running and a verified pending deposit, send two approval requests at the same moment (two terminals, or a short script). Expect exactly one success and one 422, one balance credit, one `BalanceAdjustment`, one investment, and one `deposit.approved` audit row.

## 13. Run the local demonstration

Backend, in `smartInvest-backend-RANKS_7` (each in its own terminal):
```
php artisan serve                 # http://localhost:8000
php artisan queue:work            # required: rank alerts and evaluation run as queued jobs
php artisan schedule:work         # optional: daily rank sweep and alert retry
```
Frontend, in the `frontend` folder:
```
npm ci
npm run dev                       # Vite; /api is proxied to http://localhost:8000
```
The maturity payout (`SCHEDULE_INVESTMENT_MATURITY`) is **off by default and must stay off** for the demo.

**Synthetic accounts.** Create them in `php artisan tinker`, choosing your own throwaway local password (`Hash::make` is required because the model has no `hashed` cast):
```php
use App\Models\User; use Illuminate\Support\Facades\Hash;
$pw = Hash::make('CHOOSE-A-LOCAL-PASSWORD');
User::create(['name'=>'Demo Admin','email'=>'admin@demo.invalid','password'=>$pw,'role'=>'admin','status'=>'active']);
User::create(['name'=>'Demo Finance','email'=>'finance@demo.invalid','password'=>$pw,'role'=>'financial','status'=>'active']);
User::create(['name'=>'Demo Investor','email'=>'investor@demo.invalid','password'=>$pw,'role'=>'investor','status'=>'active','registration_stage'=>4,'registration_completed'=>true,'kyc_status'=>'approved','kyc_verified'=>true]);
```
This snippet was **not executed**. If the investor cannot log in, check the registration fields. Seed the rank demo: `php artisan ranks:load-proposed --admin=admin@demo.invalid` (creates inactive drafts), then `php artisan ranks:demo-data --evaluate --admin=admin@demo.invalid` (labelled `@demo.invalid` investors with **fixture** exchange rates, not market rates).

## 14. Management test script and matrix

Mark each row yourself. "Automated" means a passing test on SQLite, **not** a browser observation.

| # | Scenario (steps) | Expected | Automated coverage | Browser |
|---|---|---|---|---|
| 1 | Investor: Deposits → start a deposit, pick a method | Instructions and a unique `DEP-…` reference shown; deposit appears Pending in history | `investor starts a deposit and gets the telegram agent link but nothing is paid or approved` | NOT RUN |
| 2 | Investor confirms payment with proof and amount/currency | Stored as *declared, unverified*; nothing credited | `investor declaration is stored unverified…` | NOT RUN |
| 3 | Investor attempts approval or direct credit | Refused (403); balance unchanged | `investor cannot reach any financial endpoint`; new USD-declaration test | NOT RUN |
| 4 | Finance: open the deposit; approve with no payment recorded | Blocked: "payment has not been verified"; balance unchanged | `a deposit with unknown payment cannot be approved…` | NOT RUN |
| 5 | Finance: record a valid USD payment, then approve | Approved once; balance +amount; one investment; audit row | `approval credits the approved usd amount and links the investment` | NOT RUN |
| 6 | Finance: record NGN payment with a valid rate, source and date, then approve | USD figure follows paid × rate; original NGN kept for audit | `non usd payments need a rate…`; `the approved deposit keeps the original payment for audit` | NOT RUN |
| 7 | Finance: record with rate 0, −0.5, text, or blank | Rejected with a rate error; still unverified | existing `rate 0` test + **new** `invalid conversion data…` (unrun) | NOT RUN |
| 8 | Finance: unsupported currency; zero or negative amount | Rejected; nothing recorded | `recording is validated and never assumes usd` + new test (unrun) | NOT RUN |
| 9 | Investor declares USD; staff approve without verifying | Blocked (422); not credited | **new** `a usd declaration without verified receipt…` (unrun) | NOT RUN |
| 10 | Approve with the USD shortcut and a 2-character reason | Refused: reason too short | new test (unrun) | NOT RUN |
| 11 | Approve with the shortcut when the investor declared NGN | Refused: record it with a rate | `a declared non usd payment cannot be approved until the rate is recorded` | NOT RUN |
| 12 | Reject a deposit with a reason | Investor sees Rejected + reason; no credit, no investment, audited | `a rejected deposit creates no investment and no valuation`; `reject and hold are audited with reason` | NOT RUN |
| 13 | Click Approve twice quickly on an approved deposit | Second click refused; one credit | `double approval is rejected and audited once`; `a retry cannot credit or invest twice` | NOT RUN |
| 14 | Two simultaneous approvals (MySQL) | One success, one refusal | **No automated proof** (SQLite ignores locks) | NOT RUN |
| 15 | Finance user calls an Admin-only action (countdown extend, freeze) | 403, nothing changes | `financial cannot use admin endpoints to move money…` | NOT RUN |
| 16 | Webhook with no secret configured | 503, nothing happens | `the webhook is disabled until a secret is configured` | NOT RUN |
| 17 | Webhook wrong / missing header; correct header | 403 / accepted, forwards text only | `…rejects requests without telegrams secret token`; `telegram messages never approve…` | NOT RUN |
| 18 | Rank: run the demo seed and evaluate | Investors land on the documented ranks; badge shows | `RankMultiCurrencyTest` demo-command tests; `RankProposedCriteriaTest` | NOT RUN |
| 19 | Rank: investment just short of a threshold (one cent / one cycle) | Not upgraded | `every cycle minimum is enforced…`; `every amount minimum is enforced…` | NOT RUN |
| 20 | Rank evaluation then compare balances and deposits | Money records unchanged | `ranks never change balances profits withdrawals or financial records` | NOT RUN |

## 15. Remaining risks and decisions for SSI Management

1. **Not production-ready.** The Docker image, deployment and production MySQL behaviour were not examined.
2. **Frontend build unverified.** Only a parse and import check was done.
3. **Browser behaviour unverified:** screens, messages and error states.
4. **Concurrency unproven** until the MySQL probe in §12 is done.
5. **Management decisions:** (a) whether payment verification must require attached evidence or a second approver (§9); (b) approval of the proposed rank thresholds, and confirmation of the ranking currency (`RANKS_CURRENCY_CONFIRMED`); (c) whether `RANKS_ALLOW_MANUAL_OVERRIDE` should ever be enabled.
6. **Privacy:** the Telegram webhook logs full payloads (§8).
7. **Behaviour change** from the middleware reorder: role checks now precede record lookups (§11).
8. The two new tests and the `phpunit.xml` change are unexecuted.
9. The dashboard's per-driver SQL was reasoned from SQLite behaviour and the user's passing tests, not run against MySQL here. The MySQL branch is byte-identical to the original query, so production risk is low, but confirm the admin dashboard loads on MySQL during the demo.
