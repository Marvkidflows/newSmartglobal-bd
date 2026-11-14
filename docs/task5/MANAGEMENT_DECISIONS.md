# Task 5 - Decisions required from Management
Nothing below has been assumed. Configurable capability exists for each; the safe default is shown.

| # | Decision | Safe default in this build |
|---|---|---|
| 1 | Exact definition of an eligible activated referral | No criteria enabled; policy **unapproved**; nothing can be approved/credited. A policy cannot be approved with zero criteria. |
| 2 | Is KYC approval mandatory? | Optional switch `require_kyc_approved` (off) |
| 3 | Must the referred investor deposit / invest / complete a cycle? | Switches `require_approved_deposit`, `require_investment`, `require_completed_cycle` (all off) |
| 4 | Must the referrer remain an active investor? | Switch `referrer_must_be_active` (off); a referrer must always be an investor and never the same account |
| 5 | Limits on number / total value of rewards per referrer | Optional `max_rewards_per_referrer`, `max_total_value_per_referrer` (none) |
| 6 | Where rewards are paid | Investor wallet (`users.balance`, USD) via the existing balance mechanism; no other account type exists |
| 7 | Can rewards be reversed, and when? | Reversal exists, **Administrator only**, reason mandatory, refused if the investor no longer holds the funds |
| 8 | Withdrawal fee amount and type | `none`, inactive, unapproved |
| 9 | Fee differences by currency / method / investor category | Not implemented; single USD policy. Only the wallet currency is accepted - no exchange rate is ever assumed |
| 10 | Fee on failed / cancelled / rejected / reversed withdrawals | Mechanical default only: money moves at approval, so a rejected/held withdrawal never has a fee taken (`not_collected`). Reversal/waiver of a *collected* fee is an explicit, reasoned Financial action; refund to wallet is optional and needs the existing balance-adjust permission. No cancel/failed-payment flow exists in the codebase to hook |
| 11 | Accounting treatment of collected fees | Fee sub-ledger (`withdrawal_fee_events`) + informational wallet-ledger legs; no revenue-account posting exists to integrate with |
| 12 | Align Task 5 eligibility with the Rank module's "qualified referral" rule | Independent today |
| 13 | Is a pending reward re-priced if the amount changes before approval? | No - the amount is snapshotted when the reward row is created |
| 14 | Separate approver for Approve vs Credit (dual control)? | Not enforced; the same Financial user may approve then credit. Recommend routing through the existing approval-request service |
| 15 | Who is a "Financial Administrator"? | Only `admin` may declare a policy/fee Management-approved; `financial` may draft versions |
| 16 | Reward cap / rate limits to deter farming | Velocity flag only (5 per 24h per code, configurable) |
