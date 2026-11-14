# Withdrawal cancellation, failed payment and reversal — technical implementation plan

**Nothing here is implemented or active.** No accounting, fee-refund or approval rule has been invented. Every new state below needs the Management decisions in §7 first. The only parts that already exist and are active are marked **(exists)**.

## 1. What exists today (verified in the code)
| Situation | Existing behaviour |
|---|---|
| Statuses | `pending`, `hold`, `approved`, `rejected` (no `cancelled`, `paid`, `payment_failed`, `reversed`) |
| Submission | no wallet movement; ledger `pending_withdrawal` (info); fee frozen on the row (`charged` only if an approved fee policy exists) |
| Rejection before payment **(exists)** | status `rejected`; **no debit ever happened**, so nothing to restore; fee status `not_collected`; audit record |
| Approval **(exists)** | one gross debit (`BalanceAdjustment` + `completed_payout` ledger entry); fee `charged → collected` inside the gross (not a second debit) |
| Investor cancellation | **none** |
| Payment failure after approval / payout confirmation | **none** — approval is treated as the payout |
| Reversal after approval | **none** for the withdrawal itself. Fee reverse/waive/refund-to-wallet exists in Task 5 but is **disabled by default** (`WITHDRAWAL_FEE_ADJUSTMENTS_ENABLED=false`) |

## 2. Cases, proposed treatment and what each needs
| Case | Proposed state change | Wallet | Fee (options — Management must choose) | Ledger / audit |
|---|---|---|---|---|
| **A. Investor cancels before approval** (`pending`, optionally `hold`) | `pending/hold → cancelled` (terminal) | none (nothing was debited) | not collected (consistent with rejection) | status-history row; fee event `not_collected`; audit `withdrawal.cancelled`; in-app notice |
| **B. Staff rejects before payment** | **(exists)** | none | `not_collected` **(exists)** | **(exists)** |
| **C. Cancellation after approval** | only if Management allows; `approved → cancelled` only while unpaid | compensating **credit of the gross** with unique key `wd-{{id}}-cancel` | collected fee: retained / refunded / waived — decision D3 | reversal of `completed_payout` (new `withdrawal_payout_reversed` entry, never an edit), fee event `reversed` linked to the collection |
| **D. Payment fails after approval** | `approved → payment_failed` (needs a payout-confirmation step that does not exist) | re-credit the gross automatically or on staff action — decision D2 | per D3 | same as C |
| **E. Reversal after completed payout** | `paid → reversed` | credit only the amount actually recovered — decision D4 | per D3 | same as C plus recovery reference |
| **F. Mark paid (new optional step)** | `approved → paid` | none | stays `collected` | audit `withdrawal.paid` |

## 3. Implementation outline (once decisions exist)
1. **Schema (additive):** `withdrawals.status` gains `cancelled`, `payment_failed`, `paid`, `reversed` (string column — no enum change needed if it is a string; verify); new append-only `withdrawal_status_history` (withdrawal_id, from, to, actor_id, reason, reference, created_at); `WithdrawalFeeEvent` already supports `related_event_id`.
2. **Service `WithdrawalLifecycleService`** (single entry point; controllers never touch balances): every transition in **one transaction**: lock withdrawal → lock investor → validate allowed transition from the *locked* status → write history → wallet credit only through `BalanceAdjustmentService::applySigned` with idempotency key `wd-{{id}}-{{event}}` → ledger entry with unique `entry_key = withdrawal:{{id}}:{{event}}` → fee event with unique `event_key` → audit.
3. **Duplicate protection:** (a) status precondition under the row lock — a second attempt finds a terminal status and returns the earlier result; (b) unique idempotency/`entry_key`/`event_key` constraints make a duplicate credit or refund impossible even if (a) were bypassed; (c) a withdrawal may be compensated at most once (terminal states `cancelled`, `rejected`, `reversed`); (d) refund ≤ collected fee and only if no `reversed/waived` event exists.
4. **Dual-approval threshold:** any credit at or above `financial_controls.balance_threshold_usd` must go through the existing `FinancialApprovalService` (a guard already refuses it in referral/fee-refund paths).
5. **Reconciliation:** extend `WithdrawalFeeService::reconcile()` with: Σ credits for a withdrawal ≤ Σ debits; `cancelled/rejected` ⇒ no net debit; `reversed` ⇒ exactly one compensating entry.
6. **Frontend:** investor "Cancel request" button (PIN required) only for `pending`; staff screens show history; all states appear in the investor withdrawal list with the disclosed gross / fee / net.
7. **Tests:** transition matrix (every illegal transition refused), duplicate click/refresh/retry, concurrent cancel vs approve (MySQL), fee options per case, ledger/balance equality, permissions per role (Registration/MarvFlow always 403).

## 4. Permissions (proposal)
Investor: cancel own `pending` only. Financial: reject, hold, mark paid/failed, cancel `pending/hold` on request. **Administrator + a second approver** (see MAKER_CHECKER_PROPOSAL.md): any wallet credit after approval (C, D, E) and any fee refund. Registration, MarvFlow, mailing/news staff: none.

## 5. Why nothing was built
Each of C–E and every fee treatment is an **accounting policy** (is the fee income reversed? is a refund owed? who may authorise?). Building any would invent policy. A (cancel before approval, no money moved, fee not collected) mirrors the existing rejection rule and is the only candidate that could be built without new accounting policy — it still adds an investor-facing action and a new status, so it also awaits your approval.

## 6. Current safety net while this is undecided
No path can create a second debit or a second fee; fee adjustments are off; rejection before payment works; the reconciliation report flags inconsistent fee states.

## 7. Management decisions required
D1 May investors cancel, until which status? · D2 On payment failure is the wallet re-credited automatically or by staff? · D3 Fee retained / refunded / waived for each of A, C, D, E · D4 Is reversal after payout allowed, who authorises? · D5 Time limits · D6 Accounting treatment of fee income and refunds · D7 Investor-facing disclosure wording (legal) · D8 Is a payout-confirmation (`paid`) step wanted?
