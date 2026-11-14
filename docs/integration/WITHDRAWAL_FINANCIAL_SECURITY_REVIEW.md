# Withdrawal financial security review

**Method:** code reading of `InvestorWithdrawalController`, `AdminWithdrawalController` (approve/reject/hold), `WithdrawalFeeService`, `WithdrawalFeeCalculator`, `BalanceAdjustmentService`, `WalletLedger`, compared with the ORIGINAL `store()`. **Nothing was executed.** 11 single-database tests and 6 MySQL concurrency tests were written for it (not run).

## 1. Result in one paragraph
I found **no defect in the withdrawal submission, approval or rejection paths** that required a code change. The rewritten `store()` is stricter than the original (the original had no transaction, no lock and no idempotency). One related gap was found elsewhere and closed: referral credit/reversal (and the disabled fee refund) moved wallet money through `applySigned`, which bypassed the ORIGINAL dual-approval threshold (§5).

## 2. Flow as implemented
| Step | What the code does | Verdict |
|---|---|---|
| Authorisation | route is investor-only; `user_id` is always the authenticated user; status, fee, gross/net, `processed_by` are built server-side (`forceFill`) and never read from the request | OK |
| Validation | amount numeric ≥ 10; PIN present and `Hash::check`; method whitelist; the fee calculator additionally rejects zero, negative, non-numeric and more than 2 decimals | OK |
| Fee quote | `WithdrawalFeeCalculator`: string-decimal maths; **no fee** unless a policy is active **and** approved **and** effective (not future) **and** type ≠ none; fee ≥ gross is refused; rounding once | OK |
| Disclosure | if a fee applies (or any confirmation is supplied) the investor's `confirmed_fee`/`confirmed_net` must equal the server's figures, else 422 `FEE_CONFIRMATION_REQUIRED` with the quote | OK |
| Atomicity | one transaction; `lockForUpdate` on the investor row; idempotency lookup, balance check (`balance ≥ gross`), one-pending check and insert all under that lock | OK (original had none) |
| Idempotency | key hashed per user, unique index; a repeat returns the first withdrawal (`duplicate: true`) | OK |
| Submission effect | **no balance change**; a `pending_withdrawal` ledger entry (direction `info`) records the request; fee frozen on the row (`fee_status = charged` only when a fee applies) | OK |
| Approval | transaction → lock withdrawal → status must be pending/hold → lock investor → refuse 422 if `balance < amount` → mark approved → **one** atomic `decrement(balance, amount)` (amount = gross) → `BalanceAdjustment` (before/after) → `completed_payout` ledger entry (unique `entry_key`) → principal bookkeeping → `onApproved` (fee `charged → collected`, once, unique `event_key`) | OK |
| Second approval | blocked by the status check under the row lock → 422 | OK |
| Rejection | transaction → lock → pending/hold only → status rejected → `onRejected` (`not_collected`); **no debit exists to undo** | OK |
| Lock order | approval: withdrawal → investor; administrator deduction: actor → investor; submission: investor only. No path holds the investor lock and then waits for a lock the others hold | no cycle found |

## 3. Your checklist
| Requirement | Evidence | Verified by execution? |
|---|---|---|
| Cannot withdraw more than the balance | locked check at submission **and** re-check under lock at approval | No (tests written) |
| Fee cannot be deducted twice | fee is inside the gross debit (not a second debit); `onApproved` only acts on `charged`; unique fee-event key; second approval refused | No |
| Withdrawal cannot be approved twice | status re-check under `lockForUpdate` | No (MySQL rounds test) |
| Concurrent deduction cannot cause a negative balance | both paths lock the investor row and re-check the balance; `decrement` runs under the lock | **No — MySQL run required** |
| Rejected withdrawals leave no deduction | rejection never touches the balance | No |
| No unapproved fee policy can charge | calculator returns 0.00 unless active+approved+effective; only an Administrator can approve (reference required) | No |
| Ledger consistent with balance movements | each approval writes one `BalanceAdjustment` (before/after) and one `completed_payout` entry in the same transaction; reconciliation report checks gross = fee + net and status/fee consistency | No |
| Existing behaviour unchanged with no fee policy | fee 0.00, net = gross, `amount` = gross, debit = amount; no fee events | No |

## 4. Observations (not changed; Management/engineering may decide)
1. **Only `pending` blocks a new request.** A withdrawal on `hold` does not, so an investor can have a held request and a new pending one. Approvals re-check the balance, so this cannot overdraw; it is identical to the original behaviour. Decision: should `hold` also count as outstanding?
2. **`WithdrawalFeePolicy::current()` = the latest effective row, whatever its approval state.** Saving a newer *unapproved or inactive* draft silently switches fees **off** for new requests (fail-safe for investors, but a draft can mask an approved policy). Decision: should only approved+active versions be considered?
3. Balance comparisons in approval use PHP numeric comparison of decimal values (original code, unchanged). All fee arithmetic uses string decimals.
4. The original has no investor cancellation, payment-failure or post-approval reversal (see the implementation plan).

## 5. Gap closed: referral credit / reversal bypassed the dual-approval threshold
`config/financial_controls.php` (original) requires a second approver for a single wallet add/deduct at or above `balance_threshold_usd` (default 10,000 USD, a Management placeholder) — enforced in `BalanceAdjustmentService::submitOrApply`. Referral credit, referral reversal and the fee refund call `applySigned` directly. **Correction:** `ReferralRewardService::credit/reverse` and `WithdrawalFeeService::adjust` (refund) now refuse, fail-closed, an amount at or above that same existing threshold, telling the operator to use the balance-adjustment approval workflow. No new authority or number is introduced; with the default threshold and a $5 reward nothing changes. Tests: 3 in `ReferralRewardTest`.

## 6. What must still be run
`WithdrawalFinancialSecurityTest` (SQLite, safe) and `MySqlWithdrawalRoundsTest` + `MySqlApprovalConcurrencyTest` (isolated MySQL) — guide §3 and §6.
