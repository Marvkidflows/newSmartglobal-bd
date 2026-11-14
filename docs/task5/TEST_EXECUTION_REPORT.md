# Task 5 - Tests: written vs executed

## Environment limitation (read first)
The authoring environment had **no PHP, no Composer, no MySQL and no network**. Therefore **no PHPUnit test was executed, no migration was run, and no PHP file was compiled.** Every PHP test below is *written but NOT executed*. Do not treat this delivery as verified until the commands in section 3 pass on a machine with PHP.

## 1. What was actually executed
| Check | Result |
|---|---|
| Lexical bracket/paren/brace balance script over every new/changed PHP file (24 source files + 6 test files + Withdrawal model) | 0 problems. **This is a weak sanity check, not a syntax check.** |
| Existence check that every `Decimal::*` helper called by Task 5 code exists in `App\Support\Decimal` | all present |
| `@babel/parser` (JSX) parse of the 8 changed/new frontend files | all parse |
| Frontend production build / vitest | **Not run** - the uploaded `node_modules` contains only win32 native binaries for rollup/esbuild; the bundled `dist/` is the stale pre-Task-5 build and must be rebuilt (`npm ci && npm run build`). |

## 2. Tests written, NOT executed (80 methods)
| File | Methods | Covers |
|---|---|---|
| `tests/Unit/Task5/WithdrawalFeeCalculatorTest.php` | 9 (+8 data-set cases) | fixed / percentage / combined, base = gross, half-up rounding on exact decimals, reconciliation gross = fee + net, inactive/unapproved/none/future policy = 0.00, min/max, fee >= amount refused, invalid amounts |
| `tests/Feature/Task5/WithdrawalFeeTest.php` | 22 | zero-fee default compatibility, quote, confirmation required/mismatch, freezing, tampered fields ignored, later policy change, approval debits gross once + collects once + second approval refused, rejection/hold fee status, pending/balance rules, idempotent resubmission, own-data only, policy versioning/audit/approval rules/back-dating/future effective, reversal linked + once, refund once, review flag, reconciliation detection, decimal round trip |
| `tests/Feature/Task5/ReferralRewardTest.php` | 37 | seeded USD 5.00 unapproved default, unique codes, attribution at stage 1, unknown code ignored, persistence through onboarding + one pending reward, attribution not editable, visit beacon, registration-alone-not-qualifying, policy approval rules, criteria enforcement (KYC alone / deposit alone insufficient), unverified account, activation events, count limit, approve != credit, credit once + unique reference + audit, failed credit leaves no false status, own-reward restrictions, amount change history (old rewards unchanged), reject rules, self-referral, duplicate identity/phone, IP-alone not fraud, velocity, flag/unflag, reversal admin-only / audited / never overdraws, no delete, investor view (pending never "paid"), masking + own data only, programme conditions, started-not-completed, summary liabilities, filters/search/pagination, export permission + formula safety, backfill command |
| `tests/Feature/Task5/Task5PermissionsTest.php` | 6 | guest 401, investor/other staff 403 on **every** new financial endpoint, financial/admin allowed, suspended staff refused, investors cannot configure fees, no cross-investor leakage |
| `tests/Feature/Task5/Task5IntegrationTest.php` | 1 | link visit -> registration -> KYC -> evaluate -> approve -> credit -> investor view -> fee-bearing withdrawal -> approval -> reconciliation |
| `tests/MySql/MySqlTask5ConcurrencyTest.php` | 5 | 10 parallel credits = 1 credit; approve vs reject race; 8 parallel withdrawals = 1; 6 parallel approvals = 1 debit + 1 fee collection; MySQL decimal round trip |

Not covered by any test (flows do not exist in the codebase): withdrawal *cancellation* by the investor, *failed payment processing*, *reversal of an approved withdrawal*. The fee service handles any non-approved terminal state generically, but these paths cannot be exercised until those workflows exist.

## 3. How to execute (exact commands)
```
cd backend && composer install && cp .env.example .env && php artisan key:generate
php artisan test tests/Unit/Task5 tests/Feature/Task5            # SQLite in-memory (phpunit.xml)
php artisan test                                                  # full regression: existing financial/rank tests must still pass
# MySQL concurrency (test database only - the harness refuses names without "test"):
SSI_MYSQL_CONCURRENCY=1 DB_DATABASE=ssi_test ... vendor/bin/phpunit -c phpunit.mysql.xml tests/MySql/MySqlTask5ConcurrencyTest.php
cd ../frontend && npm ci && npm run build && npm test
```
Expect to fix small first-run mismatches (the tests were written against code read, not run). Report any failure rather than weakening an assertion.

---
**Integration note (superseded statements).** In the integrated project `referrals:scan --no-backfill` is scheduled hourly at minute 17 (`routes/console.php`) and the historic backfill is manual; withdrawal-fee reverse/waive/refund is disabled by default (`WITHDRAWAL_FEE_ADJUSTMENTS_ENABLED=false`); the investor programme amount is hidden until the policy is approved. See `docs/integration/`.
