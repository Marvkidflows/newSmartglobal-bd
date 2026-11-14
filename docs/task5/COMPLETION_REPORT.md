# Task 5 - Completion report (Referral Bonus Management + Withdrawal Fee Configuration)
Date: 7 Nov 2026. **Status: implemented, NOT verified by execution** (no PHP runtime was available - see TEST_EXECUTION_REPORT.md). Do not treat any critical financial workflow as passed until the test commands have been run.

## 1. Status of features
**Implemented (code + tests written, not executed):** referral codes (collision-checked), link-visit/registration funnel events, immutable attribution, Pending->Approved->Credited / Rejected / Reversed rewards ledger with full event + audit history, configurable and versioned reward amount (default USD 5.00) and eligibility criteria, fraud flags, Financial approval/credit/reject/reverse/flag controls, summary/list/search/filter/pagination/CSV export, investor referral page (pending vs approved vs credited kept separate), configurable versioned withdrawal fee (fixed / percentage / combined, min/max), fee quote and explicit investor confirmation, server-side recalculation, frozen fee on each withdrawal, transaction reference, idempotent + atomic submission, fee sub-ledger, collected/not-collected/reversed/waived/review accounting, reconciliation report, Financial dashboard pages.
**Awaiting Management approval (inactive by design):** the referral policy (unapproved; no criteria enabled) and the withdrawal fee (type `none`, inactive, unapproved). No reward can be approved/credited and no fee is charged until an Administrator records approval references. See MANAGEMENT_DECISIONS.md.
**Partially implemented / not possible:** cancelled / failed-payment / reversed *withdrawal* handling is generic only (those workflows do not exist in the system). Financial Administrator vs Financial Staff cannot be distinguished (single `financial` role) - Admin-only is used for approval of policies and reward reversal. Dual control on approve vs credit is not enforced.
**Not done:** a live end-to-end demonstration, frontend tests for the new pages, a rebuilt `dist/`.

## 2. Database migration
`2026_11_07_100001_create_task5_referral_and_withdrawal_fee_tables.php` (additive, reversible):
new tables `referral_policies`, `referral_events`, `referral_rewards` (unique `referred_id`, unique `credit_reference`, unique `reversal_reference`), `referral_reward_events`, `withdrawal_fee_policies`, `withdrawal_fee_events` (unique `event_key`); new nullable columns on `withdrawals`: `gross_amount, fee_amount, net_amount, fee_currency, fee_policy_id, fee_calculation, fee_status, fee_confirmed_at, transaction_reference (unique), idempotency_key (unique)`. Backfill is a pure copy (gross = amount, fee = 0, net = amount, reference `WD-{id}`); `amount` is never rewritten. Seeds one unapproved USD 5.00 referral policy and one `none`/inactive fee policy. Existing referred investors are *not* auto-converted: run `php artisan referrals:scan` (creates Pending rows only).
Run: `php artisan migrate` (take a backup first - this is a financial schema). Rollback: `php artisan migrate:rollback --step=1`.

## 3-5. Files, endpoints, workflows
CHANGED_FILES.md, API_ENDPOINTS.md, WORKFLOWS.md.  6. PERMISSION_MATRIX.md.  7. MANAGEMENT_DECISIONS.md.  8. TEST_EXECUTION_REPORT.md.

## 9. Security and financial-integrity findings
**Controls built:** gates default-deny on every new endpoint and re-checked in controllers and services; reward/fee/policy tables are mass-assignment-proof (`$guarded=['*']` / `forceFill`); append-only policy, event and audit rows (model-level update/delete blocked); row locks (`FOR UPDATE`) on reward transitions, withdrawal submission (investor row) and fee adjustments; DB-unique references and idempotency keys against double credit / double fee; wallet moves only through `BalanceAdjustmentService`; credit + status commit atomically; investor-supplied fee figures are only compared, never trusted; IDOR-safe investor queries (`referrer_id = auth id`, masked referred persons); public beacon is throttled and identical for unknown codes (no code enumeration); CSV export neutralises spreadsheet formulas; backfill/scan/evaluate never move money.
**Pre-existing observations (not changed):**
1. Referral code lookup at stage 1 is case-sensitive and accepts a code belonging to any role; Task 5 reward assessment additionally requires an investor referrer.
2. The legacy `referrals` table / `Referral` model are unused and now superseded - recommend retiring after sign-off.
3. Rank module and Task 5 each define "qualified referral" independently (Decision 12).
4. Frontend withdrawal form minimum is 50, backend minimum is 10.
5. Approval/audit code paths cast amounts to `(float)` for audit payloads only; balances use decimal strings.
**Residual risks:** nothing executed; same Financial user can approve then credit; a pending reward keeps its creation-time amount if the policy later changes; the `shared_ip_and_address` fraud rule needs address/IP data that may be sparse; velocity thresholds are heuristics; wallet ledger legs are skipped when `WalletLedger` is disabled (the fee sub-ledger and audit log are not).

## 10. Outstanding defects / risks
No defects are *known*, but absence of execution means unknown defects are likely at first run. Treat the first full test run as part of acceptance.

## 11. Shared files that may conflict with Tasks 2-4
| File | Change | Why |
|---|---|---|
| `routes/api.php` | +1 public route, +1 investor route (quote), throttle on withdrawal store, +Financial route blocks | new endpoints |
| `app/Providers/AppServiceProvider.php` | +7 Gate definitions | permissions |
| `app/Http/Controllers/Auth/RegisterController.php` | collision-checked code generation; funnel events at stage 1 and 4 (wrapped in `rescue`) | referral tracking. **Highest conflict risk with Task 2** |
| `app/Http/Controllers/Admin/AdminWithdrawalController.php` | `onApproved`/`onRejected` fee hooks inside the existing transactions; fee fields in list/show | fee accounting |
| `app/Models/Withdrawal.php` | casts only (`$fillable` untouched) | fee columns |
| `app/Services/Ledger/WalletLedger.php` | 3 classifications added to the allow-list | informational ledger legs |
| `app/Http/Controllers/Investor/InvestorWithdrawalController.php`, `InvestorReferralController.php` | rewritten (original behaviour preserved, additive output) | fee disclosure / referral view |
| frontend `App.jsx`, `FinancialLayout.jsx`, `api.js`, `RegisterPage.jsx`, `InvestorWithdrawals.jsx`, `InvestorReferrals.jsx` | routes, nav, API methods, beacon, fee UI, referral page | UI |
Not modified: deposits, ROI/investments, ranks and thresholds, loans, Rank & Benefits, Tasks 2-4.

## 12. Manual setup
1. Backup DB; `composer install`; `php artisan migrate`.
2. `php artisan referrals:scan` once (optional backfill of Pending rows).
3. Rebuild the frontend (`npm ci && npm run build`).
4. Leave policies as seeded until Management decides; then an **Administrator** saves each policy with `policy_approved` and the approval reference (Financial users can only draft versions).
5. Re-review the permission matrix when Task 2's Registration Team role is merged.

---
**Integration note (superseded statements).** In the integrated project `referrals:scan --no-backfill` is scheduled hourly at minute 17 (`routes/console.php`) and the historic backfill is manual; withdrawal-fee reverse/waive/refund is disabled by default (`WITHDRAWAL_FEE_ADJUSTMENTS_ENABLED=false`); the investor programme amount is hidden until the policy is approved. See `docs/integration/`.
