# VERIFIED FIXES vs CODE CHANGES THAT HAVE NOT BEEN EXECUTED
Three tiers, never blended:
- **TIER 1 — EXECUTED & PASSED.** The thing was actually run and its output is in `evidence/`. Says exactly what that proves.
- **TIER 2 — STATICALLY CHECKED.** A tool was executed over the *source* (parse, brackets, imports, route table, helper existence). It did **not** run the code under test. A pass here means "no defect of this kind was detected", not "it works".
- **TIER 3 — WRITTEN, NOT EXECUTED.** Code or tests that nobody has run. Treat as unproven; the likeliest place for first-run failures.

## TIER 1 — executed and passed (and what that does and does not prove)
| Evidence file | What ran | Result | Proves | Does NOT prove |
|---|---|---|---|---|
| `entrypoint_test_result.txt` | `sh scripts/test-entrypoint.sh` (shell, **stub php**) | 16/16 pass | roles, migrate guard, schema-gate modes (**default warn never refuses to start**; enforce refuses; off skips), gate on web/worker/scheduler/background, migrate never gated | that Laravel, Docker or `ssi:schema-check` work |
| `r6_rounding_comparison.py` / `r6_result.txt` | 2,000,000-case simulation (Python emulating PHP `round()`) | pre-rounding: 0 differences; no pre-rounding: 1,420 (0.071 %), max 0.01 USD | the mechanism and magnitude of the R6 difference | real PHP 8.4 numbers |
| `frontend_pure_logic_tests_shim.txt` | 27 pure-logic tests under a **minimal vitest stand-in** (`vitest-shim.mjs`) | 27/27 pass | the logic of `approvalResponse`, `approvalView` (incl. reversal state, manual-verification blocker), `safeStyle`, `sessionNotice` | vitest compatibility, jsdom, React behaviour |
| `inbox_render_harness_result.txt` | 18 checks: the **real** `ApprovalDetailView`, `ApprovalsTable`, `ApprovalsInbox`, `AdminGlobalManagement` components server-rendered with `react-dom/server` against fixtures shaped like the backend response; includes negative controls | 18/18 pass | what each role/state sees: approve/reject only for eligible non-requesters; requester sees explanation + Cancel; unauthorised role sees explanation; failed banner + Retry only when allowed; stuck + manual-verification states have no Retry; executed shows after-values; before/after tables; every filter control present; Global Mgmt now has the authorization-reference field and "Submit for Approval" wording; no "Apply …" wording for pending operations | clicks, effects, network calls, real API responses, CSS/layout, a real browser |
| `verify_runner_logic_test_STUB_ONLY.txt` | `scripts/verify-all.sh` with **fake** php/composer | skip/fail/exit-code semantics as designed | that the runner can never report a skipped step as passed | **no application result** (every PASS line in that file is a stub) |

## TIER 2 — statically checked (tool ran over the source)
| Check | Result | Evidence |
|---|---|---|
| PHP structural check (brackets/strings/heredocs/`<?php`) — **not `php -l`** | 0 problems, all PHP files (re-run on the packaged copy) | `phpcheck.py` |
| Frontend parse of all `src` files with `@babel/parser` + relative import / named export / declared-dependency resolution | 0 problems in 139 files | `fe_static_check.mjs`, `fe_static_check_result.txt` |
| Test-class override scan (R1 class of error) | only legal `setUp/tearDown(): void` overrides | `override_scan.py` |
| Test URLs resolve against the flattened route table | all resolve | `route_resolve.py` |
| `$this->helper()` / `$this->property` exist in the class chain | 0 undefined | `helper_scan.py` |
| `composer.json` valid JSON; shell scripts pass `sh -n` / `bash -n` | yes | — |
| Packaged ZIPs: every file matches `FILE_INVENTORY.txt`; patches reproduce the packages byte-for-byte | yes | `SHA256SUMS.txt` + inventory |

## TIER 3 — written, NOT executed (unproven)
| Area | Items |
|---|---|
| **R1** | Helper rename — class now loads *in theory*; PHPUnit load not run |
| **R2** | 5 `forceFill` conversions; KYC fillable removal + 3 production call sites; strict-mode infra |
| **R3** | `submitOrApply` pre-validation, both controllers; tests |
| **R4 (backend)** | Reversal-state fields in profit history; `RespondsWithApproval`; tests |
| **R4 (frontend)** | Anything needing a browser or build: reversal modal flow, inbox data loading/actions, toasts, session-end redirect, login notice, responsive layout |
| **R6** | `DepositPaymentService` mode switch (default = original calculation, unverified by PHP) |
| **R7** | `InvestmentCorrectionService`; correction endpoint → 202; `AdminTaskController::complete` lock/decimal/audit |
| **R8** | Whole `FinancialApprovalService` rewrite incl. deadlock retry, manual-verification guard, recovery command, events, migration `2026_11_04` |
| **R9** | `StaffAccountGuard` allowlist, global middleware, login refusal, unfreeze rules, self-lockout protection |
| **R10** | Dockerfile non-root / permissions (**image never built**), composer script edits |
| **R11** | Reconciliation overwrite protection / `0600` / public-path refusal |
| **R13 / F11** | Client CSS allowlist DOM tests (needs jsdom), server `position:fixed` regression test, external-image switch |
| **Tests** | All PHP tests listed in `OUTSTANDING_TESTS.md` §A–B (59 new methods since the REMEDIATION baseline) and the entire 979-method suite; all 11 + 4 MySQL concurrency tests |
| **F10** | Lockfile regeneration, `npm ci`, vitest, `vite build` — **blocked, nothing run** |

## Added in the third pass
Tier 1: shell tests now **16/16** (warn-by-default gate). Tier 2: migration statement scan (additive only); PHP structural check now 487 files. Tier 3 (written, **not executed**): `ManagementDefaultsTest`, `FinancialSnapshotTest`, `ssi:defaults-report`, `ssi:financial-snapshot`, runner steps 03b/09. See `FINAL_VERIFICATION_REPORT.md` for exact numbers.

## Honest bottom line
Tier 1 proves the new frontend *logic* and *rendering of states*, the shell entrypoint logic, and the R6 mechanism. **Nothing in the application's PHP has run, nothing has touched MySQL, nothing has been built by Vite, nothing has been opened in a browser.**
