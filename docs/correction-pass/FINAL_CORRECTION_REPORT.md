# SSI remediation — FINAL CORRECTION REPORT
Date: 4 Nov 2026 (second pass, after the reviewer's response to the first correction report) · Team: independent Laravel/React engineering team · Scope: correction pass after the second independent audit (3 Nov 2026) **plus** the review items 1–9 (§8).

## 0. FINAL STATUS: **1. NOT READY — critical/high blockers remain**
Not because the findings were ignored — the code for R1–R9, R11 and F-15 is written and has now been desk-reviewed a second time (§8) — but because **nothing in the PHP/MySQL/npm chain could be executed in the authoring environment, which was re-checked at the start of the second pass (still no PHP, MySQL, Docker or network)** (see §2). The acceptance gate requires executed PHP tests, executed MySQL concurrency tests, a clean `npm ci` and a production build. None of those exist yet. This report does not claim any of them.

Production deployment is **not** authorised by this work. No feature flag was switched on, no migration was run against any database, no historical financial record was touched.

## 1. Baseline decision
| Package | Verdict |
|---|---|
| `smartInvest-backend-REMEDIATION.zip` / `smartInvest-frontend-REMEDIATION.zip` | **Used as the starting point.** These are the delivered remediation packages; the supplied patches derive from them. |
| `backend-remediation.patch`, `frontend-remediation.patch` | Reference only (they are candidate→final deltas, not base→final). |
| `smartInvest-backend-FULL` / `smartInvest-frontend-RANKS_7` | Pre-remediation originals. Used to understand the original code. **`smartInvest-backend-FULL` contains a live `.env` and a `.git` directory with uncommitted edits — neither was carried forward. Rotate any credential in that `.env`.** |
| `FINAL_REGRESSION_AUDIT_REPORT.md` | Treated as a list of claims to verify independently, not as truth. R1–R4, R7 and the Docker/lockfile points were each re-verified in code before being changed. |

## 2. What was actually executed (and what was not)
| Check | Result |
|---|---|
| PHP / PHPUnit / `php -l` / MySQL / composer / `npm ci` / vitest / `vite build` | **Not available. Not executed.** The sandbox has no PHP, no MySQL, no network; the supplied `node_modules` is a Windows install (no Linux Rollup/esbuild binaries). |
| Structural PHP check (`phpcheck.py`: strings/comments/heredocs/brackets/`<?php` tag) over `app routes config database tests bootstrap` | **Executed — 0 problems in 479 files.** Proven to flag a seeded fault. **This is NOT `php -l`.** |
| Test-class override scan (all `tests/**` classes vs ancestors) | **Executed** — the only incompatible override was R1 (now fixed). |
| `scripts/test-entrypoint.sh` (shell, stub `php`) | **Executed — 16/16 pass** (`evidence/entrypoint_test_result.txt`). Proves the entrypoint shell logic only. |
| R6 deposit-rounding simulation (2,000,000 cases) | **Executed** (`evidence/r6_rounding_comparison.py`, `r6_result.txt`). A Python *emulation* of PHP `round()`, not PHP. |
| Frontend: parse of all 139 `src` files with `@babel/parser` + relative-import/named-export/declared-dependency check | **Executed — 0 problems** (`evidence/fe_static_check.mjs`). Proven to flag seeded faults. **Not a build.** |
| Frontend pure-logic tests (27) under a minimal vitest stand-in | **Executed — 27/27 pass** (`evidence/frontend_pure_logic_tests_shim.txt`). **Not vitest, no DOM.** |
| **Approvals inbox + Global Management render harness** (18 checks): the real components server-rendered with `react-dom/server` against backend-shaped fixtures, with negative controls | **Executed — 18/18 pass** (`evidence/inbox_render_harness_result.txt`; re-runnable: `scripts/render-harness/run.sh`). **Not a browser** — no clicks, effects or network. |
| Test review tooling (route resolution, helper existence, override scan) | **Executed** — found 5 defects (`R12_TEST_REVIEW.md`) |
| Verification runners `scripts/verify-all.sh`, `scripts/verify-frontend.sh` | Control flow of `verify-all.sh` **executed with stub php** (`evidence/verify_runner_logic_test_STUB_ONLY.txt` — **every PASS there is a stub**). Neither has been run for real |
| All other tests written in this pass (PHP feature/unit/MySQL; jsdom sanitiser DOM tests) | **Written, NOT executed.** |

## 3. Correction checklist — R1…R13
| Finding | Description | Status | Files changed | Test evidence |
|---|---|---|---|---|
| **R1** | `investor()` signature clash fatal | **Fixed (code)** | `tests/Feature/Remediation/InvestorInvestmentAtomicityTest.php` (helper → `walletInvestor`, 8 refs) | Override scan executed: no other clash. PHPUnit load **not executed**. |
| **R2** | 5 tests silently discarded `balance` | **Fixed (code)** | `AdminTaskTest` (4), `FinancialTeamTest` (1) → `forceFill()->save()`; `tests/TestCase.php` adds opt-in strict mode (`SSI_STRICT_MASS_ASSIGNMENT=1`) used by the new tests; `MassAssignmentTest`, `RankFoundationTest` | Not executed. **Whole-suite strict run still needed** to enumerate any remaining silent discards. |
| **R3** | Excess deduction queued (202) instead of 422 | **Fixed (code)** | `BalanceAdjustmentService::submitOrApply`, both controllers | `ApprovalWorkflowHardeningTest` (written, not executed) + `FinancialTransactionsAndCommunicationsTest` expectation (422) now met by design |
| **R4** | Admin profit screen 422; 202 shown as success; no approvals inbox; no reversal UI | **Partial** (code + render verification; **no browser**) | `AdminGlobalManagement.jsx` (reason ≥10, authorization reference, 200/202, **profit-adjustment reversal modal + status/pending-reversal column**), `AdminUserDetail.jsx`, `FinancialInvestorDetail.jsx`, `FinancialInvestments.jsx`, `pages/shared/ApprovalsInbox.jsx` (+ exported presentational components), `utils/approvalResponse.js`, `utils/approvalView.js`, `services/api.js`, `App.jsx`, both layouts; backend `AdminGlobalManagementController` (history: status/reference/pending reversal), `RespondsWithApproval` | Executed: 18 server-render checks (what each role/state sees), 27 logic tests. **Not executed: any click flow, API integration, Vite build, browser (27 scripted rows in `BROWSER_TEST_SCRIPT.md`)** |
| **R5** | API compatibility | **Partial — documented** | see §5 | — |
| **R6** | USD valuation / rounding | **Partial — evidence produced, decision required** | `DepositPaymentService`, `config/financial.php`, `docs/R6_ROUNDING_COMPARISON.md` | Simulation executed. Default restored to the original calculation. Finance approval needed. |
| **R7** | `correct()` bypass; direct balance writers | **Fixed (code)** | new `InvestmentCorrectionService`, `FinancialInvestorController`, `AdminTaskController` (race + float + unaudited write) | Written, not executed |
| **R8** | Approval service weaknesses | **Fixed (code); re-reviewed — 2 further defects found and fixed** (`R8_APPROVAL_SERVICE_REVIEW.md`) | `FinancialApprovalService` (rewrite; deadlock-retry; manual-verification guard), `FinancialApprovalController`, models, migration `2026_11_04_100001`, `MaintainFinancialApprovals`, `RespondsWithApproval`, `routes/api.php`, `config/financial_controls.php` | Written, not executed. MySQL tests written, not executed |
| **R9** | Staff-status edges | **Fixed (code); reviewed for side effects** (`R9_MIDDLEWARE_REVIEW.md`) — added admin self-lockout protection and client session-end handling | `StaffAccountGuard` (allowlist), `EnsureStaffAccountActive` on the whole `auth:sanctum` group, `bootstrap/app.php`, `routes/api.php`, `LoginController`, `AdminUserController` (unfreeze rule, self-lockout), frontend `services/api.js` interceptor, `utils/sessionNotice.js`, `LoginPage.jsx` | Written, not executed (27-logic tests incl. `sessionNotice` executed under the stand-in) |
| **R10** | Migration entrypoint | **Fixed** | `Dockerfile` (no `chmod 777`, non-root, 0755 script), `docker-entrypoint.sh` (read-only schema gate), `SchemaReadinessCheck`, `composer.json` (setup no longer migrates) | Shell: 14/14 executed. Docker build **not executed**. |
| **R11** | Reconciliation | **Fixed (code)** | `ReconcileLegacyDeposits` (no overwrite without `--force`, exclusive create, 0600, refuses public path) | Written, not executed |
| **R12** | Unverifiable test assumptions | **Reviewed — 5 defects found and fixed; residual unverifiable assumptions listed** (`R12_TEST_REVIEW.md`) | `StaffAccountStatusTest` (pre-existing test used an invalid enum value), `ApprovalWorkflowHardeningTest`, `MySqlApprovalConcurrencyTest`, `TestCase`, models, trait | Static tooling executed; **no test has been run** — an executable suite + runner are provided (`scripts/verify-all.sh`) |
| **R13** | safeHtml edge | **Partial** | `safeHtml.js` (CSS property allowlist; `position:fixed` etc. removed), `RichTextSanitizer` (external images off by default) | Style logic executed (shim). DOM tests written, not executed. **DOMPurify not added** (no registry access; see SECURITY_REVIEW). |

## 4. Original F-01…F-16 status
| ID | Status now |
|---|---|
| F-01 invest atomicity | Unchanged from first remediation; MySQL race test exists; not executed |
| F-02 legacy reconciliation | Read-only, now overwrite-safe; **cutover remains OFF**; no writes added |
| F-03 balance/bulk/profit via service | All three endpoints go through services; `applySigned` added for task results |
| F-04 profit adjustment dual approval | Kept; default approver is now **Admin-only** pending decision #3 |
| F-05 migrations | Web never migrates; migrate role explicit; **schema gate added** |
| F-06–F-08 | **Not defined in the supplied findings register** (it lists F-01…F-05, F-09…F-16). The audit report associates them with the Dockerfile: `chmod 777` is now **fixed** (non-root user, 0775/0664 on `storage`+`bootstrap/cache` only); the built-in PHP server remains **unchanged** |
| F-09 docs | New docs added; first-remediation docs carry a superseded-notice |
| F-10 frontend deps | **BLOCKED** — re-attempted: no network (DNS fails), npm cannot reach the registry; lockfile still lacks test packages. `scripts/verify-frontend.sh` (`LOCK_REGEN=1`) regenerates it on a networked machine |
| F-11 XSS / token | Client CSS allowlist + external-image switch; **token still in localStorage** (assessment in SECURITY_REVIEW) |
| F-12 mass assignment | Verified call sites; **KYC state also removed from `$fillable`**; tests fixed |
| F-13 decimal arithmetic | Adjustments, correction, task results decimal-safe; deposit valuation gated (R6) |
| F-14 balance controls | Self-adjustment blocked; cumulative window; dual approval kept |
| F-15 staff status | Allowlist + global middleware |
| F-16 | Review only |

## 5. API / behaviour changes introduced by this pass (R5 register)
| Change | Reason | Client impact / migration path |
|---|---|---|
| `POST /financial/investments/{id}/correct` now **202** + request id; requires `authorization_reference` | R7 | Frontend updated; old clients get 422 (missing reference) then 202 |
| `POST …/balance` (admin & financial): excessive deduction **422** before queuing; self-adjustment **422**; large or *cumulative* adjustments **202** | R3, F-14 | Frontend 200/202 handled; 202 body adds `status`, `duplicate` |
| `Idempotency-Key` reused with a different body → **409** | R8 | New; no existing client sends the header |
| Identical pending request returns the existing one (`duplicate:true`) | R8 | Additive |
| `POST /admin/users/{id}/unfreeze` → **422** unless account is `frozen`; `freeze` via adjust only for `active` | R9 | Admin must use *activate* to reinstate suspended accounts |
| Profit adjustment approval roles: **admin only** by default | Decision #3 | Set `FIN_PROFIT_APPROVER_ROLES=admin,financial` to restore previous behaviour |
| Non-active staff (incl. `frozen`) get **403 `account_restricted`** on every authenticated route except logout | F-15 | Same JSON shape as before |
| Email external `https` images dropped by default | Decision #6 | `PRIVACY_EMAIL_EXTERNAL_IMAGES=true` restores |
| Task completion: concurrent double-click now 422 on the second call; adds `FinancialAuditLog` row; response unchanged for the first | R7 | None |
| New routes `GET /financial/approvals/{id}`, `POST …/retry`, `POST …/cancel` | R8 | Additive |
| Not changed: `affected_count` semantics (counts investments actually changed), min reason length 10 for profit adjustment (introduced by the first remediation) | — | Documented only |

## 6. Blockers (must be cleared before "READY FOR ISOLATED STAGING TESTING")
1. On a machine with PHP 8.4 + Composer + MySQL 8 run `bash scripts/verify-all.sh` (or `docker-compose.verify.yml`) and attach `verification-logs/<ts>/`. Expect first-run failures in tests written blind.
2. **F-10 / lockfile**: on a networked machine run `LOCK_REGEN=1 bash scripts/verify-frontend.sh` (regenerates `package-lock.json`, then clean `npm ci`, vitest, build, render harness); commit the new lockfile. Never hand-edit it.
3. Finance/Management written decisions (`REMAINING_MANAGEMENT_DECISIONS.md`), at minimum #1, #3, #5, #9.
4. Browser walk-through: the 27 rows of `BROWSER_TEST_SCRIPT.md` against a staging API.
5. Rotate the credentials found in the original package's `.env`.

## 7. Known residual risks (honest list)
- Everything above that is "written, not executed" may contain defects PHP would catch immediately.
- Direct `balance` writers still outside `BalanceAdjustmentService`, by design: settlement, ledger allocation/principal return, deposit/withdrawal approval, investor invest. Each is row-locked with its own records; none was modified in this pass.
- A cumulative threshold can still be exceeded across *different requesters* acting together (collusion) — that is what dual approval is for.
- `php artisan serve`-style built-in server in the Dockerfile (F-07) is unchanged.
- Rank engine files were **not modified**. The R6 deposit-valuation default was restored to the original calculation specifically so rank qualification inputs do not move.

## 8. Response to the reviewer's items 1–9
| # | Item | Outcome | Where |
|---|---|---|---|
| 1 | Complete R4 — reversal interface, inbox verification | Reversal modal + status + "reversal pending" in the profit history, backed by new history fields; inbox split into exported components and **server-rendered against fixtures (18/18)**. A real-browser verification is **not possible here** → 27-row script | `BROWSER_TEST_SCRIPT.md`, `evidence/inbox_render_harness_result.txt` |
| 2 | R12 — review every new test; executable suite | 5 defects found/fixed (one in a first-remediation test); runner `verify-all.sh` that cannot report a skipped step as passed; `Dockerfile.verify` + compose | `R12_TEST_REVIEW.md`, `scripts/` |
| 3 | F10 — regenerate lockfile, `npm ci`, `npm test`, `npm run build` | **BLOCKED — no network.** Nothing simulated. Exact procedure scripted | `scripts/verify-frontend.sh` |
| 4 | R8 review | 2 defects fixed (double-apply on retry of undeterminable rows; deadlock handling); verdict table for transactions/retry/cancel/expiry/duplicates | `R8_APPROVAL_SERVICE_REVIEW.md` |
| 5 | R9 review | Admin self-lockout closed; client session end implemented; effect matrix across investor/admin/financial/MarvFlow | `R9_MIDDLEWARE_REVIEW.md` |
| 6 | Precise list of outstanding tests | Generated from code: 979 PHP methods (59 added since the REMEDIATION baseline) + MySQL + frontend + browser | `OUTSTANDING_TESTS.md` |
| 7 | Verified vs unexecuted | Three tiers, every claim mapped | `VERIFIED_VS_UNEXECUTED.md` |
| 8 | No financial rule changes without written approval | None changed. Deposit valuation default = original calculation; no new thresholds/caps invented; the only new *policy-like* behaviours are security guards (self-lockout, retry refusal, decided-duplicate reporting) and are listed in the changelog | `CHANGELOG.md`, `REMAINING_MANAGEMENT_DECISIONS.md` |
| 9 | Production, flags, cutover disabled | Unchanged and untouched; checklist keeps them OFF | `DEPLOYMENT_CHECKLIST.md` |

**Final status remains: NOT READY.** (A third pass — 5 Nov 2026 — applied the documented safe defaults by instruction, added the defaults register, the warn-by-default schema gate and the read-only financial snapshot; see `FINAL_VERIFICATION_REPORT.md` for exact results and `MANAGEMENT_DEFAULTS_REGISTER.md`. Using a default is not a Management approval.) The acceptance conditions that are unmet: executed PHP tests, executed MySQL concurrency tests, clean `npm ci` + frontend tests + production build, browser verification of the approvals workflow, Finance/Management decisions.
