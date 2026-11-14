# Task 5 - Phase 1 Initial Inspection Report
Date: 7 Nov 2026. Scope: Task 5 only (referral bonus management + withdrawal fee configuration). Work done in an isolated copy; nothing in the original upload was modified.

## 1. What already existed
| Area | Finding |
|---|---|
| Referral attribution | **Exists.** `users.referral_code` (unique) and `users.referred_by`, written once in `RegisterController::submitStage1` for a brand-new email. Stage 4 accepts a `referral_code` but ignores it (so attribution cannot be rewritten later). Profile update validates a fixed field list and does not include either column. |
| Referral link/code UI | **Partial.** `InvestorReferrals` showed code + link and counted referred users; "bonus" was hard-coded 0. Code generation was `Str::random(8)` with no collision check. |
| Referral rewards / ledger / statuses / fraud controls / Financial UI | **Missing.** An unused legacy `referrals` table + `Referral` model (with `bonus_amount`) exists; it is not wired to anything and was left untouched. |
| Registration funnel (visited / started / completed) | **Missing** (only the user row existed). |
| Rank module "qualified referral" rule | **Exists separately** (`RankAdditionalCriteriaService::referralCounts`: any / verified_account / verified_and_invested). It is configuration for ranks, not a reward policy. Task 5 does not touch it - see Management decision 12. |
| Wallet | **`users.balance`** is the wallet. Credits/debits go through `BalanceAdjustmentService` (row lock, idempotency key, `balance_adjustments`, audit). A feature-flagged `WalletLedger` (informational legs) also exists. No second wallet/ledger was created. |
| Withdrawals | Investor submits (PIN, balance and one-pending checks); **approval** (Admin/Financial) debits `amount` from `users.balance` inside a transaction; rejection/hold move no money. No fee, no transaction reference, no idempotency; the one-pending check + insert was not atomic. |
| Financial dashboard / permissions | `FinancialMiddleware` (role `financial` or `admin`) + per-action gates (`financial.*`), `FinancialAuditService` / `financial_audit_logs`, dual-approval service for balance adjustments. **There is only one `financial` role** - no Financial Administrator vs Financial Staff split. The Task 2 "Registration Team" role does not exist yet. |
| Decimal handling | `App\Support\Decimal` (exact string arithmetic, half-up, 2 dp). Used for all Task 5 money. |
| Tests | PHPUnit, SQLite in-memory by default; MySQL parallel-process harness in `tests/MySql`. |

## 2. Compatibility constraints honoured
`withdrawals.amount` keeps meaning "gross requested" and is exactly what approval debits. Deposit processing, ROI, ranks, loans, rank thresholds and approval authority are unchanged.

## 3. Shared files likely to conflict with Tasks 2-4
`routes/api.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/Auth/RegisterController.php` (Task 2 will almost certainly edit it), `app/Models/Withdrawal.php`, `app/Services/Ledger/WalletLedger.php`, `app/Http/Controllers/Admin/AdminWithdrawalController.php`, frontend `src/App.jsx`, `src/layouts/FinancialLayout.jsx`, `src/services/api.js`, `src/pages/public/RegisterPage.jsx`. All edits are small and additive; see COMPLETION_REPORT section 11.

## 4. Risks / dependencies
No PHP runtime in the authoring environment, so nothing PHP was executed (see TEST_EXECUTION_REPORT). Rank module and Task 5 now have two independent definitions of a "qualified referral" until Management aligns them. Task 2's Registration Team role must be added to default-deny review when it lands.

## 5. Decisions needing Management approval
See MANAGEMENT_DECISIONS.md. Until they are made the referral policy is **unapproved** and the fee policy is **none/inactive**.

---
**Integration note (superseded statements).** In the integrated project `referrals:scan --no-backfill` is scheduled hourly at minute 17 (`routes/console.php`) and the historic backfill is manual; withdrawal-fee reverse/waive/refund is disabled by default (`WITHDRAWAL_FEE_ADJUSTMENTS_ENABLED=false`); the investor programme amount is hidden until the policy is approved. See `docs/integration/`.
