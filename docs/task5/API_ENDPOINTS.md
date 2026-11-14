# Task 5 - API endpoints (all under `/api`)
Authentication is Sanctum bearer unless stated. Money values are strings with 2 decimals.

## Public
| Method / path | Purpose |
|---|---|
| `POST /referrals/visit` `{code}` | Throttled (30/min), write-only link-visit beacon. Same `{success:true}` response for unknown codes. |
| `POST /register/stage1` (existing) | Unchanged contract; `referral_code` attributes the new account once and logs `registration_started`. |
| `POST /register/stage4` (existing) | Unchanged contract; on first completion logs `registration_completed` and creates the Pending reward row. |

## Investor (`/investor-investment/investor/...`)
| Method / path | Purpose |
|---|---|
| `GET referrals` | Extended: `referral_code`, `referral_link`, `referrals[]` (masked person, registration/activation status, eligibility, reward status, `paid`, explanation), `totals` and `counts` by status, `programme` (active flag + plain-language conditions). `total_bonus` = credited only. |
| `GET withdrawals/quote?amount=` | `{quote:{gross,fee,net,currency,fee_type,fee_applies,explanation,available_balance,sufficient_balance}}`. 422 for zero/negative/invalid/>2dp or fee >= amount. |
| `POST withdrawals` | Existing body + `confirmed_fee`, `confirmed_net` (required when a fee applies). Optional `Idempotency-Key` header. 422 `FEE_CONFIRMATION_REQUIRED` (with fresh quote) if they differ from the server calculation. 201 on success; 200 `duplicate:true` on a replay. |
| `GET withdrawals` | Existing keys + `gross_amount`, `fee_amount`, `net_amount`, `currency`, `fee_status`, `fee_explanation`, `transaction_reference`. |

## Financial Administration (`/financial/...`, role `financial` or `admin`)
| Method / path | Gate | Purpose |
|---|---|---|
| `GET referrals/summary` | view-referrals | Funnel counts, liabilities (pending/approved/total), credited & reversed value, policy state |
| `GET referrals` | view-referrals | Filters `status, eligibility, suspicious, q, from, to, page, per_page(<=100)` |
| `GET referrals/{id}` | view-referrals | Detail, eligibility checks, reward history, funnel events |
| `POST referrals/{id}/evaluate` | manage-referrals | Re-assess eligibility/fraud flags (moves nothing) |
| `POST referrals/{id}/approve` `{note?}` | manage-referrals | pending -> approved (needs approved policy, eligible, unflagged) |
| `POST referrals/{id}/reject` `{reason}` | manage-referrals | pending/approved -> rejected |
| `POST referrals/{id}/credit` | manage-referrals | approved -> credited; credits `users.balance` once |
| `POST referrals/{id}/reverse` `{reason}` | reverse-referral-reward (admin) | credited -> reversed; deducts, never below zero |
| `POST referrals/{id}/flag` / `unflag` `{reason}` | manage-referrals | Suspicion flag (no money effect) |
| `GET referrals-export` | view-referrals | CSV, same filters, formula-injection safe |
| `GET referral-policy` / `POST referral-policy` | view / configure | Policy history; new version `{reward_amount, criteria{...}, policy_approved?, approval_reference?, effective_from?, reason}` |
| `GET withdrawal-fees/policy` / `POST withdrawal-fees/policy` | view / configure | Current + history; new version `{fee_type, fixed_amount?, percentage?, min_fee?, max_fee?, is_active, policy_approved?, approval_reference?, effective_from?, reason}` |
| `GET withdrawal-fees/summary` | view-withdrawal-fees | Gross, charged, net, collected, not collected, reversed/adjusted, review, outstanding + reconciliation issues |
| `GET withdrawal-fees/withdrawals` | view-withdrawal-fees | Paginated, filters `status, fee_status, q` |
| `GET withdrawal-fees/withdrawals/{id}/events` | view-withdrawal-fees | Fee event history |
| `POST withdrawal-fees/withdrawals/{id}/adjust` `{action: reverse\|waive\|review, reason, refund_to_wallet?}` | adjust-withdrawal-fee | Fee adjustment linked to the original collection |
| `GET withdrawal-fees/reconciliation` | view-withdrawal-fees | `{reconciled, issues[]}` |
| Existing `GET /financial/withdrawals`, `/admin/withdrawals` | unchanged | now also return gross/fee/net/fee_status/transaction_reference |

Artisan: `php artisan referrals:scan [--no-backfill]` backfills Pending rows for pre-existing referred investors and re-assesses Pending rewards. It never approves or credits.
