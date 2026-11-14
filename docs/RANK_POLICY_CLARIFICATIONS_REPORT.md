# Rank policy clarifications: completion report (7 November 2026)

Starting point: `rank-downgrade-policy-final.zip` applied on the existing project. Work done in an isolated copy; the original project was not touched. Nothing was run against any database.

## 1. Policy changes and what was done
| # | Management decision | What was done |
|---|---|---|
| 1 | Any user with the `admin` role may decide a downgrade review | **Already true; confirmed, no code change.** `ranks.downgrade` is `role === 'admin'`. No new role or group created. Financial, MarvFlow, investor and unknown (e.g. registration) roles are denied. Audit (admin id and name, decision, reason, time) and "a decided review cannot be decided again" preserved. New test: two different admins each decide a review and each is recorded |
| 1 | Public registration must never allow self-assigned or taken-over admin | **Defect found and fixed.** Role is hard-coded to investor and request values are ignored (confirmed). BUT the "resume unfinished registration" branch applied to ANY existing account with `registration_completed = false`, including staff: it overwrote the password and returned a login token. `DemoDataSeeder` creates its admin with that flag unset. One condition added in `RegisterController::submitStage1`: staff (any non-investor) accounts are refused like completed ones |
| 2 | Review explains a possible valuation-related shortfall | New read-only `RankValuationNote`; `RankPresenter::review` adds `valuation_note` (open reviews only); `RankNotifier::deliverReview` uses cautious wording and says Financial verification is required; admin screens show an info banner (`ValuationReviewNote.jsx`) and soften "no longer meets" wording when the note exists. No auto-downgrade, no guessed rate, nothing treated as zero, rank unchanged |
| 2a | Wording correction (8 Nov 2026) | The currency banner on the investor admin page (`AdminRankInvestor.jsx`) said excluded records "count as $0 until reviewed". It now says they are excluded from the qualifying figures until their valuations are verified, are not treated as zero, and no exchange rate is assumed. Text only; no calculation or financial logic changed |
| 3 | Maximum currency-rate age: leave to Management | **Nothing introduced.** A new test proves a five-year-old verified valuation is not treated as stale. Note for Management: an older, separate setting `ranks.rate_date_tolerance_days` (default 7) exists; it governs acknowledgement when recording a rate and was not changed or used here |
| 4 | Financial keeps upgrade authority | Unchanged. New test: Financial approves an upgrade proposal (200) and gets 403 on retain and on downgrade |
| 5 | Preserve existing policy | No downgrade automation, thresholds, upgrade behaviour, financial calculations, lending or benefits touched |

## 2. Files changed or new (all relative to the project roots)
Backend (`backend/`):
* MODIFIED `app/Http/Controllers/Auth/RegisterController.php` (one condition + comment)
* NEW `app/Services/Rank/RankValuationNote.php`
* MODIFIED `app/Services/Rank/RankPresenter.php` (one added field)
* MODIFIED `app/Services/Rank/RankNotifier.php` (alert wording and two payload fields)
* NEW `tests/Feature/Rank/RankValuationReviewNoteTest.php` (6 tests)
* NEW `tests/Feature/RegistrationRoleSecurityTest.php` (5 tests)
* MODIFIED `tests/Feature/Rank/RankDowngradeReviewPolicyTest.php` (13 tests, +3: every admin decides, Financial 403 with upgrade authority intact, no rate-age limit)
* MODIFIED `RANK_MODULE.md`, `docs/RANK_DOWNGRADE_POLICY.md`
* NEW `docs/RANK_POLICY_CLARIFICATIONS_REPORT.md` (this file)
Frontend (`frontend/`):
* NEW `src/components/ValuationReviewNote.jsx`, `src/components/ValuationReviewNote.test.jsx` (3 tests)
* MODIFIED `src/pages/admin/ranks/RankReviewsTab.jsx`, `src/pages/admin/ranks/AdminRankInvestor.jsx`
No migration, no config change, no change to any Registration Team or other module beyond the one guard above.

## 3. Tests: written versus executed
**Written, NOT executed (no PHP or Node dependencies in the authoring environment):** all 14 new backend tests (6 + 5 + 3 added), the 10 earlier policy tests as revised, the 3 frontend tests, and every code change above.
**Executed by you earlier (before these changes):** whole `tests/Feature/Rank` folder 551 passed, 1 skipped, 0 failed; frontend `vitest` 60 passed and `vite build` succeeded.
Run:
`php -d memory_limit=-1 vendor\bin\pest --filter=RegistrationRoleSecurityTest`
`php -d memory_limit=-1 vendor\bin\pest --filter=RankValuationReviewNoteTest`
`php -d memory_limit=-1 vendor\bin\pest --filter=RankDowngradeReviewPolicyTest`
`php -d memory_limit=-1 vendor\bin\pest tests\Feature\Rank` and `php -d memory_limit=-1 vendor\bin\pest tests\Feature\Registration*`
`npm test` then `npm run build` in the frontend.

## 4. Confirmations
Not changed: rank thresholds and requirements, automatic upgrade behaviour, balances, deposits, withdrawals, ROI, referrals, other financial calculations, lending, benefits, the review-only process, Registration Team or other modules.

## 5. Remaining gaps
1. Nothing here has been executed.
2. Any already-deployed database where a staff account has `registration_completed = false` was exposed before this fix. Check it: `SELECT id, email, role FROM users WHERE role <> 'investor' AND registration_completed = 0;` and rotate those passwords if there is any doubt. (Run this on your own database; I did not.)
3. The existing `rate_date_tolerance_days` default of 7 is a hard-coded number of days Management has not necessarily approved; it is untouched.
4. Alerts need a queue worker; the sweep needs a running scheduler. Not verified on MySQL.
