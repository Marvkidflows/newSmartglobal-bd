# Maker/checker (dual control) — review and implementation plan

**No existing permission or workflow was changed.** One fail-closed guard was added (§3) that only *enforces an existing rule*. Everything else below is a plan awaiting Management decisions.

## 1. What already exists in the ORIGINAL project (`FinancialApprovalService`, `config/financial_controls.php`)
A real maker/checker engine for **balance_adjust, bulk_balance, profit_adjust, profit_reverse, investment_correction**:
* initiators: `admin`, `financial` (active staff only); approvers per operation from config (`balance_adjust`: admin+financial; profit changes: admin only by default);
* **hard rule: the approver must be a different person from the requester** (self-approval and self-rejection are refused); payload hash re-checked at execution; one execution only (state machine, unique events);
* a single wallet add/deduct **at or above `balance_threshold_usd`** (default 10,000 USD, a Management placeholder) needs a second approver; resets, bulk changes, profit and investment corrections always do; cumulative-limit control for threshold splitting; optional expiry.
Everything is placeholder-valued "pending Management" in the original and the safe defaults are on.

## 2. Who can initiate **and** approve today (outside that engine)
| Action | Initiate | Approve/decide | Same person possible? |
|---|---|---|---|
| Balance adjustment below the threshold | Admin/Financial | none (executes immediately) | yes (by design: no second person below the threshold) |
| Balance adjustment ≥ threshold, reset, bulk, profit, correction | Admin/Financial | different Admin/Financial (per config) | **no** — enforced |
| Referral policy draft | Financial/Admin | Administrator (with written reference, ≥1 criterion) | an Administrator can draft **and** approve alone |
| Referral reward approve | Financial/Admin | — | — |
| Referral reward credit | Financial/Admin | — | **yes**: the same person may approve and then credit (only "not your own reward" is checked) |
| Referral reward reversal | Administrator | — | single person |
| Withdrawal fee policy draft / approve | Financial/Admin / Administrator + reference | | same pattern as the referral policy |
| Withdrawal approve / reject / hold | Financial/Admin | single actor (original behaviour, no second person) | yes |
| Fee reverse / waive / refund | Financial/Admin | **disabled by default** | — |

## 3. Implemented this round (enforces an existing rule only)
Referral **credit**, referral **reversal** and fee **refund** move the wallet through `BalanceAdjustmentService::applySigned`, which bypassed the original threshold rule. They now **refuse** an amount at or above the existing `balance_threshold_usd` (when dual approval is enabled), directing the operator to the balance-adjustment approval workflow. No new role, authority or number was introduced; at the default threshold a $5 reward is unaffected. Tests: 3.

## 4. Proposed implementation (needs the decisions in §6)
| Action | Proposed rule | Mechanism (reuse, not a new engine) |
|---|---|---|
| Referral reward credit | credit by someone **other than** the approver | add `credited_by ≠ approved_by` check in `ReferralRewardService::credit` (2 lines + test) |
| Policy draft vs approval (referral, fee) | approver ≠ drafter | compare `configured_by` with the approving Administrator in `savePolicy` |
| Reward reversal; fee refund; withdrawal cancellation/reversal | initiated by Financial, **approved by a different Administrator** | register new operations (`referral_reverse`, `withdrawal_fee_refund`, `withdrawal_reversal`) in `FinancialApprovalService::OPERATIONS` with `approver_roles` config — the engine already enforces requester ≠ approver, payload hash and single execution |
| Withdrawal approval | optional: second approver above a Management-set amount | same engine operation `withdrawal_approve` |
Audit: the engine already records requester, approver, hashes and events; referral/policy actions add `maker_id`/`checker_id` to their existing event rows.

## 5. Why not implemented
Each rule changes who can move money and would silently alter existing Financial/Administrator authority. The original's own thresholds are still marked "placeholder pending Management", so adding more mandatory approvals on top would invent policy. The affected workflows therefore **remain unchanged**.

## 6. Management decisions required
M1 Must the approver of a reward differ from its crediter? · M2 Must a policy's drafter differ from its approver? · M3 Which actions need a second person, and above what amounts (reuse or replace the 10,000 USD placeholder)? · M4 Which roles may be checkers per action (Financial may approve Admin-initiated profit changes?) · M5 Emergency single-administrator override — allowed, and how is it logged and reported? · M6 Should withdrawal approval above an amount need a second approver?
