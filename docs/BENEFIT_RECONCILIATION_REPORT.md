# 64-benefit reconciliation (updated after remediation)

Source: `AUDIT_64_BENEFIT_RECONCILIATION.csv` (64 rows; seed matches CSV 64/64 per the audit; copy kept as `BENEFIT_RECONCILIATION_64_INPUT.csv`). Counts below are recomputed from that CSV. **Nothing in this table was verified by execution.**

| Bucket (task §8) | Count | Detail |
|---|---:|---|
| 1. Operational (platform feature exists) | 21 | `Delivered by = platform` (17 audited "YES", 4 partial). Correctness not execution-verified. 11 are active by default (10 recognition platform + 1 service). |
| 2. Displayed only | 41 | `Delivered by = operational` catalogue entries: shown/configurable, **no assignee, evidence or delivery record in the base**. |
| 3. Configured but inactive | 53 | 50 `pending_approval` + 3 `subject_to_eligibility`. Only 11 default to active. |
| 4. Require manual (staff) fulfilment | 41 | Same 41 as bucket 2. Patch 05's `BenefitFulfilmentService` (request → assign → start → complete with evidence reference; decline/cancel with reason; row-locked; audited) is now **in the package but not exposed**: no route, controller or admin screen. |
| 5. Not implemented | 2 | `loan-dashboard` (rows for two ranks): lending locked, dashboard not built. |
| 6. Require additional Management approval | ≥ 17 | All ROI (5), loan (11) and reinvestment (1) entries (16 `pending_approval` + 1 `subject_to_eligibility`) are **financial** and stay locked pending written Management + Financial Team approval. Loyalty (6) and other `pending_approval` entries need Management sign-off before activation. |

## What was implemented / not
* Implemented (inactive, unreachable over HTTP): patch‑05 workflow for **non-financial, operational, published, non-consent** benefits only; the service refuses financial categories and requires the investor's current rank entitlement. Audit rows via the existing append-only `rank_benefit_audit`. Its own tests ship with the patch (**not executed**).
* **Not implemented:** routes/controller/admin screen/notification wording for fulfilment; any ROI, lending, reinvestment or withdrawal-priority behaviour (deliberately). No benefit flag was changed. Rank changes never touch wallets, profits or lending (test `test_rank_evaluation_never_adjusts_profit_or_balances`, **not executed**).
* Decision needed: who may assign/complete fulfilments (suggest Admin only, Financial excluded), investor notification channel/wording, which of the 41 manual benefits to switch on first.
