# CHANGELOG — correction pass (4 Nov 2026), on top of the first remediation
All entries are code changes. **None has been executed against PHP/MySQL** (see TEST_EXECUTION_REPORT.md).

## Backend
### Added
- `InvestmentCorrectionService` — single path for amount/profit-rate corrections: records original + proposed values, decimal arithmetic, second approval, re-validation (state and original values) at execution, audit before/after, investor notified only after commit.
- `FinancialApprovalService` rewrite — one-transaction claim+execute (no stuck "approved"), states `pending/executing/executed/rejected/failed/cancelled/expired` (+ legacy `approved`), retry, cancel, expiry, recovery of stuck rows, payload-bound idempotency (409), DB-unique open key against duplicates, canonical (MySQL-JSON-safe) payload hash, execution-time re-check of requester and approver (role + `active` status).
- `FinancialApprovalEvent` (append-only history) + migration `2026_11_04_100001_harden_financial_approval_workflow` (additive, reversible).
- `BalanceAdjustmentService::submitOrApply` (R3 deduction pre-validation under row lock, self-adjustment refusal, cumulative window, per-requester serialisation) and `applySigned` (exactly-once task results).
- `EnsureStaffAccountActive` middleware on the whole authenticated group; `StaffAccountGuard` allowlist.
- Commands: `ssi:approvals-maintain` (expire/recover, `--dry-run`), `ssi:schema-check` (read-only startup gate).
- Config: `financial_controls.*` (cumulative window/limit, expiry, approver roles, correction approval, profit cap), `financial.deposit_valuation_mode`, `privacy.email_external_images`.
- Tests: `ApprovalWorkflowHardeningTest` (21), `DepositValuationModeTest` (2), `MySqlApprovalConcurrencyTest` (11), `MySqlTestCase`, plus additions to existing tests.
### Changed
- `FinancialInvestorController::correctInvestment` → request (202) instead of direct write; `authorization_reference` required.
- `AdminUserController` / `FinancialInvestorController` balance endpoints → `submitOrApply`; `unfreeze` only from `frozen`.
- `AdminTaskController::complete` — assignment row locked and state re-checked under the lock; decimal-safe; routed through the audited service (adds `FinancialAuditLog`); same negative-loss rule.
- `User::$fillable` — removed `kyc_status`, `kyc_verified`, `kyc_verified_at`; writers converted to `forceFill()`.
- Profit approver roles default **admin-only** (decision #3).
- `DepositPaymentService` — explicit valuation mode, default = original float calculation (R6).
- `RichTextSanitizer` — external email images off by default (decision #6), container-safe config lookup.
- `ReconcileLegacyDeposits` — no overwrite without `--force`, exclusive create, `0600`, refuses web root.
- `Dockerfile` — no `chmod 777`, non-root `www-data`, script 0755. `docker-entrypoint.sh` — schema gate for web/worker/scheduler/background (`SCHEMA_CHECK_MODE=warn|enforce|off`, **default warn** since 5 Nov: the live site must never be taken offline by a mis-ordered deploy). `composer.json` — `setup` / `post-create-project-cmd` no longer migrate.
### Fixed (tests)
- R1 helper rename; R2 five `forceFill` conversions; `FinancialTransactionsAndCommunicationsTest` correction tests rewritten for the approval flow; `EmailImageEmbedderTest` split for the image switch.
### Removed
- Nothing.

## Frontend
- **Added** `pages/shared/ApprovalsInbox.jsx` (+ `/admin/approvals`, `/financial/approvals`, nav entries), `utils/approvalResponse.js`, `utils/approvalView.js`, `approvalService` in `services/api.js`, tests `approvalResponse.test.js`, `approvalView.test.js`, `safeStyle.test.js`.
- **Changed** `AdminGlobalManagement.jsx` (authorization reference, reason ≥10, 200 vs 202), `AdminUserDetail.jsx`, `FinancialInvestorDetail.jsx`, `FinancialInvestments.jsx` (200 vs 202; correction needs authorization reference), `safeHtml.js` (CSS property allowlist; `position:fixed` etc. removed).
- **Not changed:** `package.json`, `package-lock.json` (F-10 blocked).

---
# Second pass (response to reviewer) — additions on top of the above
## Backend
- **Added** `RespondsWithApproval` (decided duplicates answer 200 with their real state; pending ⇒ 202); profit-history fields `status`, `authorization_reference`, `reversed_at`, `reversal_reason`, `reversal_pending_request_id` (+ `reversed_at` datetime cast); `needs_manual_verification` on approval requests; `scripts/verify-all.sh`, `Dockerfile.verify`, `docker-compose.verify.yml`.
- **Changed** `FinancialApprovalService`: deadlock/lock-wait errors rethrown and the claim retried (`DB::transaction(…, 3)`); `recoverStuck()` flags undeterminable rows and **retry is refused for them** (cancel + resubmit after manual verification). `AdminUserController`: an administrator can no longer suspend/freeze/deactivate their **own** account (422).
- **Tests** fixed: invalid `inactive` status data (also in a first-remediation test), class-wide strict mode narrowed, new tests for decided-duplicate, reversal history, manual-verification, self-lockout, allowlist on unsaved models. (R12)
## Frontend
- **Added** profit-adjustment **reversal** modal + status/"reversal pending" column in `AdminGlobalManagement.jsx`; `reverseProfit` / `getProfitItems` API methods; `reversalState` helper; exported presentational components of the Approvals inbox (`ApprovalDetailView`, `ApprovalsTable`); `utils/sessionNotice.js`; `scripts/verify-frontend.sh`; `scripts/render-harness/` (render verification).
- **Changed** `services/api.js` interceptor: a 403 `account_restricted` now ends the session with a notice (R9); `LoginPage.jsx` shows the notice.
## Not changed (deliberately)
No financial business rule, threshold, cap, rounding policy, rank rule, feature flag or cutover setting. `package.json` / `package-lock.json` untouched (F-10 blocked).

---
# Third pass (5 Nov 2026: proceed on documented safe defaults; site is live)
- **Added** `ManagementDefaults` register + `ssi:defaults-report [--check|--json]` + `ManagementDefaultsTest` (every default labelled NOT APPROVED BY MANAGEMENT; deviations detected). **Added** read-only `ssi:financial-snapshot` (`--out`, `--compare`, `--hash`, `--strict`, `--force`) + `FinancialSnapshotTest`. Runner: step 03b (defaults) and step 09 (snapshot → migration down/up → strict compare).
- **Changed** startup schema gate default **enforce → warn** (never takes the live site offline); `.env.example` updated; shell tests 14 → 16.
- **Verified, unchanged:** items 2, 4, 5, 10 are preserved existing rules; item 1 never invents a value (disabled when nothing is configured).
- **Docs:** `MANAGEMENT_DEFAULTS_REGISTER.md`, `FINAL_VERIFICATION_REPORT.md`; migration doc gained the preservation-proof section and the live-site (no-downtime) sequence.
- **No** financial rule, threshold, cap, rounding policy, flag or cutover setting changed; no data migration exists in this release.
