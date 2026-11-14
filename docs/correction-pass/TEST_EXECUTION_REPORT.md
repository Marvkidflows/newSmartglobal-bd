# TEST EXECUTION REPORT — correction pass
**Rule followed: a test is "passed" only if it was executed and its output captured. Everything else is "written, not executed".**

## A. Executed in the authoring sandbox (outputs in `evidence/`)
| # | What | Tool | Result | What it proves — and does not |
|---|---|---|---|---|
| 1 | Entrypoint shell tests `sh scripts/test-entrypoint.sh` | `sh` + stub `php` | **16/16 PASS** | Role handling, migrate guard, schema gate modes (default warn keeps serving; enforce refuses; off skips), gate on web/worker/scheduler/background, migrate never gated. Does **not** run Laravel or Docker |
| 2 | Structural PHP check `phpcheck.py` over `app routes config database tests bootstrap/app.php` | Python | **0 problems / 479 files** (seeded-fault check: detected) | Balanced brackets/strings/heredocs, `<?php` tag. **Not `php -l`**: cannot see undefined methods, wrong types, bad syntax inside balanced code |
| 3 | Test-class override scan | Python | Only R1 clash (now fixed) | Static; PHPUnit not run |
| 4 | R6 old-vs-new rounding, 2,000,000 random cases | Python emulating PHP `round()` | pre-rounding (PHP ≤ 8.3): **0 differences**; no-pre-rounding (PHP 8.4 style): **1,420 differences (0.071 %), max 0.01 USD** | Magnitude and mechanism. Real PHP 8.4 behaviour must be confirmed on staging data |
| 5 | Frontend parse (`@babel/parser`, JSX) + import/export/dependency resolution | Node | **139/139 parsed, 0 problems** (seeded-fault check: detected) | Syntax and wiring. **Not a build** |
| 6 | Frontend pure-logic tests (200/202 handling, view helpers incl. reversal/manual-verification, CSS allowlist, session notice) | Node + minimal vitest stand-in | **27/27 PASS** | Logic of 4 modules. **Not vitest; no DOM** |
| 7 | **Approvals inbox / Global Management render harness** (real components via `react-dom/server`, backend-shaped fixtures, negative controls) | Node + `@babel/core` JSX transform | **18/18 PASS** | What each role/state sees. **Not a browser** (no events/effects/network) |
| 8 | Test-review tooling: route resolution, helper existence, override scan | Python | **Executed** (found 5 defects, fixed) | Static only |
| 9 | `verify-all.sh` control flow with stub `php`/`composer` | bash | skip/fail/exit-code semantics confirmed (`verify_runner_logic_test_STUB_ONLY.txt` — **all PASS lines there are stubs**) | Nothing about the application |

## B. Blocked — cannot run here (exact reasons)
| Required item | Reason |
|---|---|
| PHP lint across app/routes/config/database/tests | No PHP binary |
| PHPUnit class-loading check, SQLite full suite, focused remediation tests | No PHP |
| MySQL integration + concurrency tests, migration dry-run on isolated DB | No PHP, no MySQL |
| `npm ci` (clean), vitest, production `vite build`, lockfile regeneration | **Re-checked in the second pass: DNS resolution fails, npm cannot reach the registry.** Lockfile lacks test packages; supplied `node_modules` is a Windows install (missing `@rollup/rollup-linux-x64-gnu`, `@esbuild/linux-x64`) |
| Browser verification of the approvals inbox and adjustment screens | No browser/API |

## C. Written in this pass, NOT executed
- `tests/Feature/Remediation/ApprovalWorkflowHardeningTest.php` — 21 tests: R3 (both endpoints, no request created, balance untouched, re-validation at execution), F-14 (self-adjust, cumulative splitting), idempotency 409, duplicate pending, canonical hash, event history order, append-only events, cancel, expiry (and disabled by default), suspended/frozen approver or requester, retry, legacy-state recovery + dry-run, no alternate route for corrections, profit cap, staff status matrix (`frozen/suspended/deactivated/inactive/unknown`), unfreeze rule, KYC mass assignment, `applySigned` exactly-once and negative-loss rule.
- `DepositValuationModeTest` (2), additions to `AdminTaskTest` (exactly-once completion), `MassAssignmentTest` (strict mode), `DepositAccountingTest` (reconcile overwrite), `RichTextSanitizerProfilesTest` (positioning CSS), `EmailImageEmbedderTest` (image switch), rewritten correction tests in `FinancialTransactionsAndCommunicationsTest`.
- **MySQL** `tests/MySql/MySqlApprovalConcurrencyTest.php` — real parallel processes: two approvers on one request; 6 identical parallel submissions; one idempotency key with two payloads; JSON hash round-trip; double deposit approval; withdrawal vs investment; balance deduction vs withdrawal; two simultaneous task completions; correction vs settlement; correction vs profit adjustment; profit reversal vs settlement. Existing `MySqlConcurrencyTest` kept (investments; adjustment races).
- jsdom DOM tests in `safeHtml.test.js` (positioning CSS).

### Required-scenario coverage (instruction §14)
| Scenario | Written? |
|---|---|
| Two simultaneous investment requests | existing |
| Two simultaneous task completions | **new** |
| Correction vs settlement | **new** |
| Correction vs profit adjustment | **new** |
| Profit adjustment/reversal vs settlement | **new** (reversal vs settlement; adjustment vs settlement is covered by the same invariant but its own test is not separate) |
| Two approvers, same approval | **new** |
| Parallel duplicate approval submissions | **new** |
| Double deposit approval | **new** |
| Withdrawal vs investment | **new** |
| Balance adjustment vs concurrent withdrawal | **new** |
| Idempotency key reused with different payload | **new** |
| JSON payload hash round-trip | **new** |

## D. Exact verification procedure (machine with PHP 8.4, Composer, MySQL 8, Node 20/22 and network)
Everything below is automated by two runners that log each step and exit non-zero unless every step ran and passed (a skipped step is never reported as passed).
```
sha256sum -c SHA256SUMS.txt                                   # integrity of the delivery
# BACKEND  (or: docker compose -f docker-compose.verify.yml up -d db && docker compose -f docker-compose.verify.yml run --rm app bash scripts/verify-all.sh)
cd smartInvest-backend-CORRECTED
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=ssi_correction_test DB_USERNAME=… DB_PASSWORD=…     # isolated database; name must contain "test"
bash scripts/verify-all.sh                                    # logs → verification-logs/<ts>/ (php -l, SQLite suite, strict run, focused tests, MySQL suite, migrate+schema-check, migration rollback)
# FRONTEND
cd ../smartInvest-frontend-CORRECTED
LOCK_REGEN=1 bash scripts/verify-frontend.sh                  # FIRST time: regenerates package-lock.json (commit it), then npm ci, vitest, build, render harness
bash scripts/verify-frontend.sh                               # afterwards
# BROWSER: BROWSER_TEST_SCRIPT.md (27 rows) against a staging API
```
Attach every `verification-logs/<ts>/` folder to the next report. Until then the status stays **NOT READY**.
