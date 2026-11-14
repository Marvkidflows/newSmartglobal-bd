# SSI Rank Downgrade Policy: REVIEW ONLY

**Decision:** Management, 7 November 2026. SSI keeps a review-only downgrade system. Automatic rank downgrades must not be introduced: no code path, no switch, no grace-period job.
**Status of this document:** describes the code as inspected on 7 Nov 2026. See the completion report for what has and has not been executed.

## 1. The rule in one paragraph
The system may promote an investor automatically (or propose a promotion for approval, depending on `ranks.require_approval`). It never lowers a rank on its own. When an investor stops meeting the requirements of the rank they hold, the system opens a **downgrade review** for authorised administrators and leaves the rank unchanged. A person decides: **retain** (with a reason) or **downgrade** (with a reason). Every decision is recorded.

## 2. How it works in the code
| Step | What happens | Where |
|---|---|---|
| Evaluation | prerequisites (active account, verified KYC) and the CURRENT rank's active rule are re-evaluated | `RankEvaluationService::evaluateUser` |
| Upgrade (unchanged) | only ranks ABOVE the current level are considered; automatic upgrade when approval is off, otherwise a pending review for an administrator or Financial to approve | `evaluateUser`, `applyAutomaticUpgrade`, `RankReviewService::approve` (refuses unless the proposed rank is higher) |
| Shortfall | failed prerequisites plus unmet conditions of the current rule; if none, any open review is closed as `cleared` with a recorded reason | `syncDowngradeReview`, `shortfall` |
| Review | one row per EPISODE of an unchanged shortfall (fingerprint of rank, criterion version, failed conditions) with a unique key (investor, fingerprint) | `syncDowngradeReview`, `currentEpisode` |
| Alert | queued after commit, one per review; delivery is idempotent | `SendDowngradeReviewNotificationJob`, `RankNotifier::deliverReview` |
| Decision | `POST /api/admin/ranks/reviews/{id}/retain` or `POST /api/admin/ranks/investors/{id}/downgrade`; both require `ranks.downgrade` | `RankManagementController`, `RankChangeService` |
| Record | the review (`resolved_by`, `resolved_by_name`, `resolution_reason`, `resolved_at`) and an append-only `investor_rank_changes` row (actor, reason, previous and new rank, timestamp, `is_automatic = false`) | `RankChangeService::retainReview`, `applyManual` |

## 3. Confirmed: no automatic downgrade code path exists
Only three places write `users.investor_rank_id`:
1. `RankEvaluationService::applyAutomaticUpgrade`: reached only for ranks above the current level (the loop stops at the current level).
2. `RankReviewService::approve`: refused when the investor already holds the proposed rank or a higher one.
3. `RankChangeService::applyManual`: an administrator's explicit assign, downgrade or override (`ranks.assign`, `ranks.downgrade`, `ranks.override`).
`syncDowngradeReview` only creates or closes a review. `config/ranks.php` has no downgrade or grace setting, no console command or scheduled job mentions downgrades, and `InvestorRankChange::DOWNGRADE` is recorded only by `applyManual`. A source-level test (`test_there_is_no_automatic_downgrade_path_setting_command_or_scheduled_job`) fails if this ever changes.

## 4. Financial protection rules
| Case | Behaviour |
|---|---|
| Pending withdrawal | reduces no qualifying principal, so there is no shortfall and no review |
| Rejected or held withdrawal | same: no principal movement (covered by `FinancialDecisionsTest`, `Phase2AcceptanceGapsTest`) |
| Unverified record | adds nothing and takes nothing away from principal or cycles; cannot cause a review or a downgrade |
| Stale or unresolved currency valuation | never valued at a guess and no rate is assumed: the record is left out of the figures, a review opens for staff, the rank is untouched. When Financial verifies the valuation the investor is re-evaluated and, if eligible, the review closes itself (`cleared`) with a recorded reason and the rank stays |
| Same shortfall returning after it was cleared | a new episode: a new review and one new alert (fixed 7 Nov 2026) |
| Same unchanged shortfall while open or after a decision | no second review, no second alert |

## 5. Who may do what (Management decision, 7 Nov 2026)
**Any user who already has the `admin` role may decide a downgrade review.** There is no separate "Rank Management administrator" role or narrower permission group at this stage.
| Role | View own rank and notices | Verify records and currency | Approve or reject UPGRADE proposals | Decide downgrade reviews (retain or downgrade) |
|---|---|---|---|---|
| Administrator (`admin`, every existing account) | yes | yes | yes | **yes** (`ranks.downgrade`) |
| Financial (`financial`) | n/a | **yes** | **yes** (`ranks.review`, existing policy, unchanged) | **no** (403) |
| Registration staff | no such role exists today | n/a | n/a | **no**: unknown roles are denied by default |
| MarvFlow (`marvflow_member`, `marvflow_lead`) | n/a | no | no | **no** (403) |
| Investor | yes | no | no | **no** (403) |
Permissions are enforced on the server: route middleware, then `Gate::authorize('ranks.downgrade')` inside the controller methods. Financial verification of a valuation never gives downgrade authority.

**Public registration cannot create or take over an admin.** `RegisterController` hard-codes `role = investor` and ignores any role, status, balance, KYC or rank value in the request (these columns are not mass-assignable). The "resume an unfinished registration" branch is now limited to investor accounts: an existing staff account is refused with the same "already registered" message, so its password can no longer be overwritten and no login token can be issued (see the clarifications report: this closed an account-takeover path for staff accounts whose registration flag was unset).

## 6. Review explanation when a currency valuation may be the cause (7 Nov 2026)
While a review is open, if any of the investor's investment records has a valuation that is missing (unverified), stale (amount changed after verification) or unresolved (in the reconciliation queue), the review and the staff alert say that the shortfall **may be valuation-related**, that those records are left out of the figures and are **not counted as zero** (no exchange rate is assumed), and that **Financial verification is required before deciding**. They state that this is not a definitive failure and that the rank has not been changed. The rank stays unchanged until an administrator decides. Source: `RankValuationNote` (read-only, reads the same ledger as the engine). Shown in the admin Reviews tab and on the investor's admin page. A closed review carries no note.

## 7. Decisions and open points
1. **Maximum currency-rate age: LEFT TO MANAGEMENT.** None is introduced, no default is invented, and no rate is classified as stale by age. A valuation is stale only when its record was changed after verification. (Existing setting `ranks.rate_date_tolerance_days`, default 7, predates this work: it decides when recording a rate needs an acknowledgement because the rate date is far from the payment date. It is not used to classify a valuation as stale and was not changed; Management may wish to review that number separately.)
2. **Financial upgrade authority: unchanged.** Financial may approve or reject upgrade proposals; this gives no downgrade authority.
3. **Automatic downgrade: not introduced** (sections 3 and the guard test).
4. Remaining operational dependencies: a queue worker for staff alerts and a running scheduler for the sweep.
