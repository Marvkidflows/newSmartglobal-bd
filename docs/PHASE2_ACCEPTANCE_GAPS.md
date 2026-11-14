# Phase 2: financial acceptance scenarios

STATUS: tests written, NOT EXECUTED until you run: `php -d memory_limit=-1 vendor\bin\pest --filter=Phase2AcceptanceGapsTest`

## What your own passing run already proved (tests/Feature/Rank, SQLite)
| Scenario | Test (all passed) |
|---|---|
| Pending withdrawal reduces no principal | FinancialDecisionsTest: a pending withdrawal reduces no principal even when classified |
| Rejected withdrawal reduces no principal | FinancialDecisionsTest: a rejected withdrawal reduces no principal and cannot be approved afterwards |
| Completed principal withdrawal reduces the position | FinancialDecisionsTest: an approved principal withdrawal reduces the position by the principal component only |
| Profit-only withdrawal | FinancialDecisionsTest: a profit only withdrawal reduces no principal |
| Partial / full redemption | DecimalLedgerPrecisionTest; FinancialDecisionsTest: a full redemption removes the principal from current eligibility |
| No double deduction | FinancialDecisionsTest: the same withdrawal cannot deduct principal twice (and the separate-redemption case) |
| Reinvestment creates no cycle | FinancialDecisionsTest: reinvestment never duplicates principal or creates a cycle |
| Duplicate investments / cycles | RankCurrencyTest, RankLedgerVerificationTest, RankSpecComplianceTest |
| Incomplete currency metadata | RankTelegramDepositTest: legacy approved deposits without a conversion are flagged and never rank; FxControlsTest |
| component_key collision | DecimalLedgerPrecisionTest: a classified withdrawal must add up exactly (fixed; two principal components now get distinct keys) |

## Added here (the two scenarios with no test)
1. HOLD withdrawal: the app has no "cancelled" withdrawal state (pending / approved / rejected / hold). The remaining non-completed state, hold, is now tested: no principal movement, no wallet debit, no other investor touched.
2. Incentive vs payout: one incentive term is walked through draft, pending approval, approved (switch off) and active; at every step the same matured position settles to exactly the baseline balance, ledger lines (only `profit_credit`), stored profit and return. A second test shows a new investment stores the plan's percentage and return unchanged while a draft term exists for the investor's rank.

## Honest limits
* The payout-path test proves the settlement service ignores incentives; it cannot prove no future code will read them. Keep the test.
* Cancelled withdrawal as a state does not exist: if Management wants investors to be able to cancel, that is a new feature and needs a decision.
