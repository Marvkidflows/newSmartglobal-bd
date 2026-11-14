# SSI Investor Rank, Benefits & Privileges: remediation and staging-readiness report

Date: 7 November 2026. Basis: the 6 November implementation audit, the remediation directive, and the test results you pasted. Nothing here is claimed as passed unless you ran it and sent me the result. "Written" means the code and tests exist but have not been run.

## 7. Staging readiness verdict (the answer first)
**NOT READY for isolated staging testing yet.** The SQLite results are strong and nothing found blocks the design, but three things must happen first:
1. The newest zips (see section 2, items 9 to 12) have to be applied and the **full SQLite suite re-run green**. They contain code and about 40 tests nobody has run.
2. The **MySQL run has never completed.** A static check on 7 Nov found one defect that would have aborted `migrate:fresh` on MySQL (a 65-character index name; MySQL allows 64). SQLite hid it. Follow `docs/MYSQL_VERIFICATION_RUNBOOK.md`.
3. A person has to open the screens (27 browser rows, tablet, phone).
When steps 1 and 2 pass with 0 failures, the right verdict becomes **READY FOR ISOLATED STAGING TESTING**. It will not be READY FOR PRODUCTION REVIEW until the Management decisions in section 6 exist and the browser checks are done.

## 1. Remediation summary: every audit finding
| Audit finding | Result |
|---|---|
| R-32 no "approaching the next rank" notice | **Fixed and executed** (32 tests passed) |
| R-33 next-rank notice only Bronze to Silver | **Fixed and executed** (every step; Bronze to Silver stays behind its pending benefit) |
| R-6 downgrade handling | **Management ruled: review-only.** Verified from code and executed tests; one defect fixed (a cleared review blocked a later recurrence), **written, not run** |
| Pending withdrawals / unverified / stale valuations vs downgrades | Existing behaviour verified; explicit tests **written, not run**; hold-withdrawal and incentive-vs-payout tests **executed** |
| R-36 full rank export | Already existed; audit trail and four columns added; **executed** (7 tests) |
| R-15 plan terms on the commit screen | Was missing; **fixed** (`PlanCommitTerms`), frontend tests executed, browser **not tested** |
| `component_key` collision | **Fixed and executed** |
| Route security (auth, gates, rate limits) | **Verified**, no gap; standing sweep test **executed** (4 tests) |
| Stale-valuation review gave no explanation | **Fixed**, **written, not run** |
| Public registration vs admin accounts | **Defect found and fixed** (a staff account with an unset registration flag could be taken over), **written, not run** |
| Benefits catalogue vs the framework | **Verified**: 64 rows, counts match the framework per rank; operational items correctly `pending_approval`; nothing financial active. No code change |
| Mobile and tablet layout | Static check: **4 admin tables lacked the scroll wrapper**; fixed, **written, not run**; rendering **not tested** |
| MySQL compatibility | **One migration defect found and fixed** (written); the real MySQL run is **NOT TESTED** |
| Stray files | Removed (confirmed unreferenced, byte-identical duplicates) |
| Maximum currency-rate age | **Left to Management**; nothing introduced |
| Staff accounts: automatic downgrade, lending, ROI, separated deposit flow | **Still off** (see section 4) |

## 2. Delivered packages, in the order to apply
| # | Package | Contents | Run result you reported |
|---|---|---|---|
| 1 | `backend-overlay-ONLY-changed-files.zip` | corrected backend, minus older files | applied |
| 2 | `frontend-overlay-ONLY-changed-files.zip` | corrected frontend | applied; vitest and build passed |
| 3 | `backend-fixes-round2/3/4.zip` | test and service fixes | applied; suite green |
| 4 | `backend-phase1-notifications.zip` | R-32, R-33 | applied; **32 passed** |
| 5 | `backend-phase2-tests.zip` | hold withdrawal, incentive vs payout | applied; **3 passed** |
| 6 | `backend-phase3-security.zip` | route sweep | applied; **4 passed** |
| 7 | `backend-phase4-export.zip` | export audit and columns | applied; **7 passed** |
| 8 | `frontend-phase5-commit-terms.zip` | commit-screen terms | applied; **60 frontend tests passed, build OK** |
| 9 | `rank-downgrade-policy-final.zip` | review-only policy, episode fix, 10 tests | **not reported** |
| 10 | `rank-policy-clarifications.zip` | admin authority, valuation note, registration fix | **not reported** |
| 11 | this wrap-up zip | MySQL index fix, table scroll wrappers, guard test, docs, runbook | **not run** |
(`backend-phase7-downgrade.zip` is superseded by item 9.)

## 3. Test execution report
**Executed and passed (SQLite in-memory, Windows):**
* Full suite after the round 2 to 4 fixes: **1,130 passed, 2 skipped, 0 failed** (15,641 assertions).
* `tests\Feature\Rank` after phases 1, 2, 4: **551 passed, 1 skipped, 0 failed** (4,464 assertions). The skip is `RankIconsTest` (looks for the frontend beside the backend).
* `RankProgressNoticeTest` 32; `Phase2AcceptanceGapsTest` 3; `RankExportAuditTest` 7; `RankRouteSecuritySweepTest` 4.
* Frontend `vitest`: 7 files, **60 tests passed**; `vite build` succeeded (a chunk-size warning only).
**Written, not run:** every test in packages 9 to 11: 10 + 3 policy tests, `RegistrationRoleSecurityTest` (5), `RankValuationReviewNoteTest` (6), `MigrationMysqlCompatibilityTest` (3), 3 frontend tests, plus all code changes in those packages.
**Not run at all:** MySQL (`migrate:fresh`, `ssi:schema-check`, rollback, the MySQL suite, the 2 cent-boundary tests SQLite skips), Docker builds, the 27 browser rows, tablet and mobile rendering, the real queue worker and scheduler.
`npm install` reported 18 vulnerabilities (2 critical, 8 high); not reviewed.

## 4. Financial safety report (code defaults)
| Control | State |
|---|---|
| Six rank rules | inactive drafts (`ranks:load-proposed` without `--activate`) |
| `ranks.require_approval` | true |
| `ranks.allow_manual_override` | false |
| ROI incentives (`ranks.incentives_activation_enabled`) | false; no payout code reads incentives (executed test) |
| Lending (`lending.enabled`) | false; locked server-side (executed `LendingLockTest`) |
| Separated deposit flow | off until Financial approves |
| Currency controls | no guessed or default rates; crypto not valued; unverified, stale and unresolved records are left out and flagged (executed tests) |
| Automatic downgrade | does not exist; a source-level test (written) fails if one is added |
| Investor balances and entitlements | **not modified**: all work was code, tests and documents; no migration or test was run against any real database by me. The one new table (`rank_progress_notices`) and the renamed index are additive/new-database only |
The values above are what the code ships with; your server's `.env` may differ and must be checked.

## 5. Updated acceptance matrix
PASS means executed and passed by you. Package 9 to 11 behaviour is PARTIAL until it is run.
| ID | Requirement | Status | Evidence / outstanding |
|---|---|---|---|
| R-1 | Six ranks with Board thresholds | PASS | `RankProposedCriteriaTest` (thresholds exactly Management's) |
| R-2 | Money AND cycles mandatory | PASS | `RankCoreRequirementsTest` |
| R-3 | Highest rank assigned automatically | BLOCKED | `require_approval` is a Management setting; rules inactive until activation sign-offs |
| R-4 | Not by deposits, referrals or claims alone | PASS | `RankSpecComplianceTest`, `RankCurrencyTest` |
| R-5 | Reassess on change | PARTIAL | tests pass; queue worker and scheduler not verified on a server |
| R-6 | Downgrade handling | PARTIAL | review-only per Management; existing tests pass; episode fix written, not run |
| R-7 | Changes recorded with reason and time | PASS | `RankAuditTest`, `RankManualChangeTest` |
| R-8 | Boundary conditions | PARTIAL | SQLite pass; cent boundary skipped on SQLite: MySQL NOT TESTED |
| R-9 | Thresholds configurable by admins | PASS | `RankCriteriaTest` |
| R-10 | Benefits catalogue per rank | PASS | 64 rows, counts match the framework; `RankBenefitsPhase2Test` |
| R-11 | Benefits configurable and audited | PASS | `RankBenefitsFrameworkTest` |
| R-12 | Fulfilment workflow | PASS | `BenefitFulfilmentTest` |
| R-13 | ROI engine configurable and restricted | PASS | incentive tests; engine does not touch payouts |
| R-14 | No rank changes contractual return | PASS | `Phase2AcceptanceGapsTest` (executed) |
| R-15 | Terms shown before investing | PARTIAL | component built and tested; browser NOT TESTED |
| R-16 | Lending framework exists | PASS | `RankBenefitsFrameworkTest` |
| R-17 | Lending disabled, no guarantee | PASS | `LendingLockTest` |
| R-18 | Six distinct badges | PARTIAL | `RankIconsTest`, `RankBadgeCoverageTest` pass; visual NOT TESTED |
| R-19 | Investor cannot edit own rank | PASS | `RankFoundationTest`, `RankManualChangeTest` |
| R-20 | Admin panel complete | PARTIAL | tabs exist; not walked in a browser; table wrappers written, not run |
| R-21 | Server-side permissions | PASS | `RankAuthorizationTest`, `RankRouteSecuritySweepTest` |
| R-22 | Manual override fully recorded | PASS | `RankDecisionsTest`, `RankManualChangeTest` |
| R-23 | Deposit gives wallet cash, not principal | PASS (SQLite) | `FinancialDecisionsTest`; flag off by default |
| R-24 | Wallet debit and allocation linked | PARTIAL | SQLite pass; MySQL concurrency NOT TESTED |
| R-25 | Principal withdrawals reduce the position | PASS (SQLite) | `FinancialDecisionsTest`, `DecimalLedgerPrecisionTest` |
| R-26 | Cycle completion distinct from payout | PASS | `FinancialDecisionsTest` |
| R-27 | Reinvestment makes no cycle | PASS | `FinancialDecisionsTest` |
| R-28 | FX: original, rate, source, time, USD, status kept | PASS (SQLite) | `FxControlsTest` |
| R-29 | No guessed or stale rates; crypto off | PASS | `FxControlsTest`, `RankCurrencyTest` |
| R-30 | Optional criteria separate | PASS | `RankSpecComplianceTest` |
| R-31 | Rank change notifications | PASS | `RankNotificationTest`; delivery NOT TESTED |
| R-32 | Approaching-rank notice | PASS | `RankProgressNoticeTest` (32) |
| R-33 | Next-rank qualification notice | PASS | `RankProgressNoticeTest` |
| R-34 | Duplicate prevention | PASS | `RankProgressNoticeTest`, `RankNotificationTest` |
| R-35 | Progress uses the real calculation | PASS | `InvestorRankCriteriaViewTest` |
| R-36 | Rank export | PASS | `RankExportAuditTest` (7) |

| # | 26 scenarios | Status |
|---|---|---|
| 1 to 6 | each rank meets both requirements | PASS |
| 7 | money met, cycles failed | PASS |
| 8 | deposits but no principal | PASS |
| 9, 10 | partial and full principal withdrawal | PASS |
| 11 | profit-only withdrawal | PASS |
| 12, 13 | pending, rejected withdrawal | PASS (a held withdrawal also PASS) |
| 14 | reinvestment | PASS |
| 15, 16 | duplicate investments, cycles | PASS |
| 17, 18 | FX unavailable, stale | PASS |
| 19 | downgrade | PARTIAL (review-only per Management; episode fix not run) |
| 20 | manual override | PASS |
| 21, 22 | unauthorised change, self-edit | PASS |
| 23 | unapproved incentive | PASS |
| 24 | investor uses lending | PASS |
| 25 | notifications | PASS |
| 26 | incomplete currency metadata | PASS |
Cancelled withdrawal: no such state exists in the application (pending, approved, rejected, hold); a decision for Management if investors should be able to cancel.

## 6. Outstanding decisions
| Decision | Who | If left pending |
|---|---|---|
| Activate the six rank rules; confirm the currency and principal sign-offs | Management and Financial Team | no rank is awarded automatically |
| Keep mandatory approval of upgrades (`require_approval`) | Management | stays on (safe) |
| Maximum currency-rate age (none introduced); the older `rate_date_tolerance_days` default of 7 | Management and Financial Team | only records changed after verification count as stale |
| Separated deposit flow on or off | Financial Team | legacy flow stays (investment created on approval) |
| Any ROI incentive; the lending programme; each `pending_approval` benefit | Management, legal | stay off |
| Stored "I have read these terms" acknowledgement on investing | Management, legal | terms are shown, no tick box |
| Staff blocked platform-wide when suspended or frozen | Management | stays on (a change from earlier messaging-only behaviour) |
| 75% and 90% notice milestones; non-Bronze notices on by default | Management | on with those defaults; `RANKS_PROGRESS_NOTICES_ENABLED=false` switches off |
| Investors able to cancel a withdrawal | Management | not possible |
| Public contact form posts to a route that does not exist (`/contact`) | Management and developers | form always shows an error |
| Check production for staff accounts with `registration_completed = 0` | Developers | possible exposure remains (query in the clarifications report) |
