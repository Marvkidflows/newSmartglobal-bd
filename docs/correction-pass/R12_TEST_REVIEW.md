# R12 — review of every newly written test for incorrect assumptions
Method: nothing could be run, so each test file written or changed in this pass was checked **against the code it exercises**, with tooling where possible. Defects found are listed first; then how each class of assumption was verified; then what remains unverifiable without PHP.

## 1. Defects found and fixed (all would have produced false failures or a PHP error on first run)
| # | Defect | Where | Found by | Fix |
|---|---|---|---|---|
| 1 | Persisted users with status **`inactive`** (and an unknown value). `users.status` is now `enum(active,suspended,frozen,deactivated)`: `inactive` was removed by migration `2026_07_07_151311`; on SQLite that violates the CHECK constraint, on MySQL it is an invalid enum value. **This was also in a first-remediation test that was never executed** (`StaffAccountStatusTest::test_non_active_staff_are_refused…`) and in my own staff-status test | `StaffAccountStatusTest`, `ApprovalWorkflowHardeningTest` | Reading the enum migrations vs the test data | Persisted users use only valid values (`suspended/deactivated/frozen`); `inactive`/unknown/case/whitespace variants are asserted on **unsaved** models in a new `test_allowlist_blocks_inactive_and_unknown_statuses` (same technique `MarvflowRestrictionsTest` already uses) |
| 2 | Strict mass-assignment enabled for the **whole** new test class: any pre-existing silent discard in unrelated app code (e.g. an audit-log attribute) would have failed unrelated tests | `ApprovalWorkflowHardeningTest::setUp` | Review of blast radius | Strict mode now only in the one test that needs it; whole-suite strict run is a separate, explicit step (`SSI_STRICT_MASS_ASSIGNMENT=1`, `verify-all.sh` step 05) |
| 3 | Duplicate/idempotent re-submission of an **already-decided** request was reported as **202 "awaiting approval"** — a product defect surfaced while checking test expectations | all approval-submitting endpoints | Review of idempotency path vs the UI rule "never say pending when it is not" | New `RespondsWithApproval` trait: pending ⇒ 202; decided ⇒ **200** `pending_approval:false` with the real status. Test added |
| 4 | `reversed_at` used as a date without a cast (`->toDateTimeString()` on a plain string would throw in the new profit-history payload) | `ProfitAdjustment` model | Tracing the new history fields | `reversed_at` cast to `datetime` |
| 5 | Retry of a request whose execution state could not be determined could **double-apply** money | `FinancialApprovalService` | Desk review (see R8 review) | `needs_manual_verification` flag; retry refused; test added |

## 2. Assumptions verified (by what means)
| Class of assumption | Verified by | Result |
|---|---|---|
| Every URL a new/changed test calls exists with that method | `route_resolve.py` flattens `routes/api.php` (394 routes) and matches each URL | all resolve (the only "misses" are string-concatenation artefacts in the checker and pre-existing negative tests) |
| Every `$this->helper()` / `$this->property` exists in the class chain or PHPUnit/Laravel | `helper_scan.py` | 0 undefined (1 hit: `assertStringNotContainsStringIgnoringCase`, a real PHPUnit method) |
| No incompatible method override (R1 class of error) | `override_scan.py` over all 78 test classes | only legal `setUp/tearDown(): void` overrides remain |
| Model `$fillable` covers every key used in `Model::create()` in tests (matters under strict mode) | read `BalanceAdjustment`, `FinancialApprovalRequest/Event`, `InvestmentAccount`, `ProfitAdjustment`, `Deposit`, `Task`… | covered |
| Request validation accepts the bodies the tests send (reason lengths, `type` values, required `amount`, `authorization_reference`) | read both controllers' `validate()` rules | consistent; Admin balance `reason` has no minimum, Financial has `min:5` — bodies satisfy both |
| Idempotency header name / format | read `ReadsIdempotencyKey` (`Idempotency-Key`, `^[A-Za-z0-9_\-]{8,64}$`) | test keys comply |
| Fixtures match working fixtures | compared with `FinancialTestCase::withdrawal()/plan()`, `AdminTaskTest::createTaskWithAssignment`, `makeInvestment`, existing MySQL tests | match |
| Settlement semantics used by the MySQL invariants (pays `expected_profit`, `end_date <= today`, command `investments:update-maturity`) | read `UpdateInvestmentMaturity` and `InvestmentSettlementService` | match |
| Withdrawal approval deducts at approval time (assumed by two MySQL invariants) | read `AdminWithdrawalController` (`decrement('balance')` in the approval transaction) | match |
| Existing restriction tests still hold under the global staff middleware | read every test that restricts a staff account (`MarvflowRestrictionsTest`, `MarvflowMessagesTest`, `StaffAccountStatusTest`) | consistent after fix #1 |

## 3. Still unverifiable without PHP/MySQL (residual R12 risk — do not read as "correct")
- Exact JSON shapes of the new `GET /financial/approvals[/{id}]` (`request.events` count 4, `request.can_retry`, `needs_manual_verification`).
- That Laravel's test `actingAs(...,'sanctum')` + `TransientToken` makes `currentAccessToken()->delete()` throw (the guard wraps it in try/catch; assumed benign).
- `travel()` interacting with `expires_at` casts; `Req::where(...)->update(['updated_at'=>…])` honoured.
- Timing-based MySQL assertions are invariant-style, but real lock-wait behaviour and the `fireTogether` barrier are untested; `investments:update-maturity` raced against HTTP in one OS process model.
- Whether any pre-existing test elsewhere in the 979 relies on behaviour changed in this pass (profit approver roles, correction endpoint, unfreeze rules, external email images, frozen-staff access). Searched by pattern; a full run is the only proof.
