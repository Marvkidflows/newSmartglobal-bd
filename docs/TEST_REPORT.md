> **SUPERSEDED (4–5 Nov 2026):** the correction passes changed behaviour described below. Use `docs/correction-pass/` as the current source of truth.

# Test report — status vocabulary: IMPLEMENTED · STATIC-CHECKED · WRITTEN-NOT-EXECUTED · EXECUTED-PASSED · EXECUTED-FAILED · NOT-VERIFIED

## Environment actually available: Linux, Node 22; **no PHP, Composer, MySQL; network disabled.**

## A. EXECUTED (real evidence)
| Check | Command | Result |
|---|---|---|
| Entrypoint role logic (F-05), stubbed `php` | `sh scripts/test-entrypoint.sh` | **8 / 8 PASS**, exit 0 (web never migrates; migrate refuses w/o `MIGRATE_CONFIRM`; failing migration exits 1; pending-migration guard; worker unchanged; unknown role rejected). Proves shell logic only — **not** real `artisan`/MySQL. |
| Shell syntax | `sh -n docker-entrypoint.sh` | OK |
| PHP token/bracket sanity (NOT `php -l`) | Pygments-based `tools/phpsanity.py` over `app database routes tests config` | 470 files OK, 0 flagged. Catches gross slips (unbalanced brackets/lexer errors) only; it was shown to flag a deliberately broken file. **Not a substitute for `php -l`.** |
| JS module loads | `node import safeHtml.js` | loads; exports present. Logic not run (no DOM available). |

## B. WRITTEN, NOT EXECUTED (no PHP/Node-test runtime) — run them yourself
| Area (task §9) | File | Tests |
|---|---|---:|
| F-01 insufficient balance, atomic debit+investment, rollback on failure, cent boundary, overspend, plan limits, idempotency | tests/Feature/Remediation/InvestorInvestmentAtomicityTest.php | 8 |
| F-01/F-03 **MySQL parallel processes**: 10 racing invests, 2×full balance, admin adds vs invests, duplicate key in parallel | tests/MySql/MySqlConcurrencyTest.php (+ `tests/Support/concurrent_worker.php`, `phpunit.mysql.xml`) | 4 |
| F-02 duplicate deposit approval, legacy double-representation characterisation, separated flow, withdrawal approve/reject/double-approve, read-only reconciliation command | DepositAccountingTest.php | 5 |
| F-03/F-14 locked decimal adjustments, audit fields, idempotency, reset approval, no self-approval, reject, threshold, failed approval, financial route, bulk preview/approval/cap, role matrix | BalanceAdjustmentControlsTest.php | 14 |
| F-04 preview, mandatory authorisation, approval, per-investment before/after, floor, reversal, conflict-safe reversal, role, rank-never-adjusts | ProfitAdjustmentControlsTest.php | 9 |
| F-15 non-active staff refused (4 roles × 3 statuses), active OK, login blocked, real token revoked, investor unaffected | StaffAccountStatusTest.php | 5 |
| F-12 fillable, fill() ignores, profile update fuzz, admin status ops still work | MassAssignmentTest.php | 4 |
| F-11 22-payload battery × 2 sanitiser profiles, safe links, idempotence | tests/Unit/SanitizerPayloadTest.php | 4 methods (≈ 46 cases) |
| F-11 client sanitiser incl. "token cannot be read" | fe `src/utils/safeHtml.test.js` | 6 (+16 payload cases) |
| Patch 05 benefit fulfilment | tests/Feature/Rank/BenefitFulfilmentTest.php (from patch) | per patch |
| Changed existing test | MarvflowRestrictionsTest (messaging-only → all-protected-endpoints), RankProposedCriteriaTest (`update`→`forceFill`) | — |

## C. Required by task §9 but **NOT newly written** (rely on pre-existing tests, not executed here)
Decimal boundary (`tests/Unit/DecimalTest`, `DecimalArithmeticTest`), FX/currency & missing rate (`FxControlsTest`, `RankCurrency*Test`), rank one-cent/cycle boundaries, duplicate/disputed investments, rank concurrency (`tests/Feature/Rank/*`), notification delivery & retry, partial principal redemption (`FinancialDecisionsTest`), bulk deterministic lock-order under real contention (only the MySQL race test touches locks). **I did not re-run or extend these. Developer-reported baseline 491 passed / 1 skipped / 4,013 assertions predates every change and is not evidence for this package.**

## D. NOT VERIFIED
Frontend `npm ci`, `npm test`, `npm run build` (lockfile lacks Vitest/jsdom/Testing‑Library — see SECURITY_REPORT F‑10; supplied `node_modules` lack Vitest and ship a non-Linux Rollup binary); any PHP syntax/runtime error; migrations; MySQL behaviour; browser behaviour.

## E. Commands to run (in this order)
```
composer install
php -l on changed files:  git diff --name-only | grep '\.php$' | xargs -n1 php -l
php artisan test                                   # SQLite full suite — fix failures first
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=ssi_remediation_test DB_USERNAME=… DB_PASSWORD=… SSI_MYSQL_CONCURRENCY=1
php artisan test --configuration=phpunit.mysql.xml --testsuite=MySQL
sh scripts/test-entrypoint.sh
# frontend
npm install        # regenerates package-lock.json with Vitest/jsdom/Testing-Library; commit it
npm ci && npm test && npm run build
```
