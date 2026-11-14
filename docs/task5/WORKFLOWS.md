# Task 5 - Workflows

## Referral
1. Investor shares code/link. Opening the link (`/register?ref=CODE`) logs `link_visited`.
2. Stage 1 creates the account with `referred_by` (once; never editable) and logs `registration_started`.
3. Stage 4 completion logs `registration_completed` and creates **one** reward row, `pending`, amount snapshotted from the current policy (default USD 5.00). A registration never qualifies by itself.
4. Eligibility is assessed against the current policy: activated (completed + email verified + active) AND every enabled criterion AND limits. Results: `policy_not_approved` | `not_eligible` | `eligible`. Fraud signals set `suspicious`.
5. A Financial user **approves** (needs approved policy + eligible + not suspicious + not own reward) -> `approved` (still a liability, **not paid**).
6. A Financial user **credits** -> one transaction: lock reward row, `BalanceAdjustmentService::applySigned` (row lock + idempotency key `referral-reward-{id}-credit`), status `credited`, unique `credit_reference` (DB-unique). Any failure rolls everything back.
7. Reject (pending/approved) needs a reason. Administrator-only reversal appends `reversal_reference/reason`, deducts through the same mechanism, keeps the credit reference.
Every step writes `referral_reward_events` and a `financial_audit_logs` row.

Fraud signals: self-referral, same phone as another account, duplicate ID number, shared IP **and** same address (IP alone is never a flag), >= N registrations through one code in 24h (default 5), rapid creation (soft; only counts with another signal). Flags never move money; approval is refused until a Financial user clears the flag with a reason.

## Withdrawal fee
1. Investor enters an amount -> `GET quote` shows gross / fee / net (+ explanation). If a fee applies the investor ticks an explicit confirmation.
2. `POST withdrawals` recomputes the fee server-side, compares with `confirmed_fee/net`, then (investor row locked) checks balance + no pending withdrawal, inserts the withdrawal with gross/fee/net/policy id/calculation/reference, status `pending`, fee_status `charged` (disclosed, nothing debited).
3. Approval (existing logic, unchanged) debits **gross** once; the fee is retained from it and the investor is paid **net**; fee_status -> `collected` (one `collected` event, idempotent).
4. Rejection -> `not_collected` (no money was ever taken). Hold keeps `charged`.
5. Financial may later `reverse` / `waive` a collected fee (linked to the collection event; optional wallet refund, once) or flag it `review`.
6. Reports separate fees charged vs collected vs not collected vs reversed; `reconcile()` checks gross = fee + net, status/fee-status consistency, missing or double collection.
Fee rules: fixed once; percentage of GROSS; combined = fixed + percentage rounded once (half-up, 2dp, exact decimals); min/max clamp; fee must stay below gross; a fee applies only if the policy is active **and** Management-approved **and** effective; policy versions are append-only and cannot be back-dated; submitted withdrawals never change.
