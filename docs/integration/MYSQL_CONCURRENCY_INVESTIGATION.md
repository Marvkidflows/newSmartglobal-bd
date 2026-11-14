# MySQL concurrency investigation (balance deduction vs withdrawal approval)

**The issue is NOT proven resolved.** It cannot be until the suites below are executed on MySQL. This report separates what is established by reading from what only a run can establish.

## 1. Established by reading the code
* Both operations lock the **investor row** before the balance check and the debit, so the balance check and the decrement are serialised per investor. Approval additionally locks the withdrawal row first. No lock-order cycle exists between approval (withdrawal→investor), administrator deduction (actor→investor) and submission (investor).
* Therefore the only serial outcomes are safe: approval first (balance 20.00, deduction refused 422) or deduction first (balance 50.00, approval refused 422). An overdraw needs **both** to succeed (−30.00).
* The recorded old failure (`422,200`, "both succeeded (130 > 100)") matches the *safe* approval-first outcome, because the test counted the approval's own `BalanceAdjustment` row (type `deduct`, reason "Withdrawal #N approved") as an administrator deduction. That counting error was corrected earlier (admin_id filter) without relaxing any invariant. This explains the failure; it does not prove it.

## 2. What the tests now distinguish
| Entry | How it is identified |
|---|---|
| Administrator deduction | `BalanceAdjustment` type `deduct` with `admin_id` = the administrator |
| Withdrawal-approval debit | `BalanceAdjustment` type `deduct`, reason `Withdrawal #<id> approved` |
| Payout ledger entry | `WalletLedgerEntry` `entry_key = withdrawal:<id>:payout` |
| Fee collection | `WithdrawalFeeEvent` `event = collected` (one per withdrawal) |
| Anything else | asserted to be **zero** (no unexplained rows) |
Can the tests detect a real overdraw? **Yes**: balance ≥ 0, balance == start − 80×(approval debits) − 50×(admin deductions), "not both succeeded", and exactly one winner. In a real overdraw the balance is −30.00 and the equation/non-negative checks fail.

## 3. Coverage (6 tests in the new `MySqlWithdrawalRoundsTest`, plus the existing suites)
* **5 repeated rounds** of deduction vs approval on a fresh investor each time (interleavings vary), with per-round diagnostics and the outcome tally printed;
* two simultaneous approvals of one withdrawal → exactly one debit and one payout entry (3 rounds);
* approval vs rejection racing → one final state, rejected leaves no deduction (3 rounds);
* the same idempotency key submitted twice at once → one withdrawal; different keys → one pending only;
* fee-bearing withdrawal approved twice at once → fee collected once, debit = gross.
Existing: `MySqlApprovalConcurrencyTest` (corrected counting), `MySqlTask5ConcurrencyTest` (referral credit once, fee once), `MySqlConcurrencyTest` (original).

## 4. What must be executed locally and what result closes the issue
Run (isolated `*_test` MySQL only; guide §6), each separately:
1. `vendor\bin\pest --configuration=phpunit.mysql.xml tests\MySql\MySqlWithdrawalRoundsTest.php`
2. `vendor\bin\pest --configuration=phpunit.mysql.xml tests\MySql\MySqlApprovalConcurrencyTest.php --filter=cannot_overdraw` (repeat 3–5 times)
3. `vendor\bin\pest --configuration=phpunit.mysql.xml tests\MySql\MySqlApprovalConcurrencyTest.php`
4. `vendor\bin\pest --configuration=phpunit.mysql.xml tests\MySql\MySqlTask5ConcurrencyTest.php`
**The issue is closed only if** all pass (none skipped), the rounds test reports both outcome types or at least one, and no assertion mentions a negative balance. **Any** "NEGATIVE BALANCE", "both debits succeeded" or "unexplained balance adjustment rows" message is a real defect: stop, do not deploy, send me the output. A *skipped* test is not a pass.

## 5. Run time
`MySqlTestCase` runs `migrate:fresh` (~120 migrations) before every test and each race starts separate PHP processes with a 3-second barrier; the new file has ~15 races, so allow 15–30 minutes. The earlier 3.3-hour run is unexplained (slow disk/antivirus on the MySQL data directory is the usual cause); consider a faster test instance (e.g. `innodb_flush_log_at_trx_commit=2` on the TEST server only).
