# Rank downgrade policy: completion report (7 November 2026)

## 1. Files inspected
Code: `app/Services/Rank/RankEvaluationService.php`, `RankReviewService.php`, `RankChangeService.php`, `RankNotifier.php`, `RankAuditService.php`, `RankEligibilityCalculator.php`, `RankInvestmentLedger.php`; `app/Models/RankDowngradeReview.php`, `InvestorRankChange.php`; `app/Http/Controllers/Admin/RankManagementController.php`; `app/Providers/AppServiceProvider.php` (ability definitions); `app/Http/Middleware/*` (role and staff-status guards); `routes/api.php`; `config/ranks.php`; `app/Console/Commands/*`; `routes/console.php`; migrations for `rank_downgrade_reviews`, `investor_rank_changes`, users roles.
Documents: `RANK_MODULE.md`.
Tests opened and read: `RankEvaluationTest`, `RankManualChangeTest`, `RankNotificationTest`, `RankLedgerVerificationTest` (stale valuation), `RankBenefitsFrameworkTest`, `FinancialDecisionsTest`, `RankTestCase`, `FinancialTestCase`, and parts of `RankSpecComplianceTest`. Others (`RankHoldTest`, `RankProposedCriteriaTest`, `RankDecisionsTest`, `RankAuthorizationTest`, `RankRouteSecuritySweepTest`) were reviewed by name and result in your run output, not line by line.
`RankManagementController` was inspected only at the two ability checks (`ranks.downgrade`, lines 224 and 259) and its routes.

## 2. Files created or modified
| File | Change |
|---|---|
| `app/Services/Rank/RankEvaluationService.php` | MODIFIED. A cleared review closes its episode; the same shortfall returning later opens a new review and one alert. Episode 0 keeps the original fingerprint, so every existing row is unchanged. `acknowledgeShortfall` uses the same episode rule. No rank-writing code touched |
| `tests/Feature/Rank/RankDowngradeReviewPolicyTest.php` | NEW (replaces the earlier version of the same name from `backend-phase7-downgrade.zip`). 10 tests |
| `RANK_MODULE.md` | MODIFIED. One sentence added to the downgrade bullet and a new section 15 |
| `docs/RANK_DOWNGRADE_POLICY.md` | NEW |
| `docs/RANK_DOWNGRADE_COMPLETION_REPORT.md` | NEW (this file) |
No migration, no config change, no frontend change, no change to balances, deposits, withdrawals, ROI, referral rewards, thresholds, or Registration or other modules.

## 3. Confirmation: no automatic downgrade code path exists
Confirmed by inspection (see policy section 3): three writers of `users.investor_rank_id`; two are upward-only and one is an explicit administrator action; the review sync never writes a rank; no setting, command or scheduled job exists. Guarded by a source-level test.

## 4. Test results
**Executed by you, SQLite (relevant existing tests, all passed in the whole-folder run: 551 passed, 1 skipped, 0 failed):** never auto-downgraded and flagged once; stricter rule flags holders; review clears itself; alert sent once per unchanged shortfall; admin decides by downgrading and by retaining with a reason; downgrade recorded with previous, new, actor and time; hold rules; pending, rejected and held withdrawals reduce no principal; stale valuation excluded until re-verified; automatic upgrades; route security sweep.
**Written, NOT executed:** `RankDowngradeReviewPolicyTest` (10 tests) and the `RankEvaluationService` change. Run:
`php -d memory_limit=-1 vendor\bin\pest --filter=RankDowngradeReviewPolicyTest` then `php -d memory_limit=-1 vendor\bin\pest tests\Feature\Rank`.
**Not run at all:** MySQL; the browser.

## 5. Remaining security or operational gaps
1. The new episode logic and the 10 tests are unexecuted.
2. No finer "Rank Management administrator" group exists; all admins can decide (policy section 6.1).
3. A stale-valuation review does not state the cause in words (6.2): awaiting approval.
4. Staleness by age is assessed only where Management approved a maximum rate age (6.3).
5. Staff alerts depend on a queue worker; the scheduler sweep needs a running scheduler (see RANK_MODULE.md section 2).
6. Financial staff approve or reject UPGRADE proposals under the existing policy; confirm that is intended.
7. The guard test lists the three permitted rank writers; a future legitimate writer needs the test updated deliberately.

STOPPED for your review and approval, as instructed.
