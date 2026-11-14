# APPLY — SSI remediation package (supersedes earlier APPLY.md)

Apply to **the candidate backend** (`smartInvest-backend-PHASE-B-clean`, sha256 `e0dee9a7…15dd2`) — not to the base. Patch file: `backend-remediation.patch` (`patch -p1` from the project root, or use the full package, which already contains everything).
Frontend: `frontend-remediation.patch` onto `smartInvest-frontend-PHASE-E-clean` (`874b7259…ffa`).

## Behaviour changes that affect production (flags OFF) — read before applying
1. **F-01** Legacy wallet→investment is now one transaction under a user-row lock; insufficient funds are refused with the same 422 text. Optional `Idempotency-Key` header: repeat ⇒ HTTP 409.
2. **F-05** The `web` role **no longer runs migrations**. Deploys must run the `migrate` role first (docs/MIGRATION_DEPLOYMENT.md). If your platform relied on web auto-migrating, schema changes will silently *not* apply until you add that job.
3. **F-03/F-04/F-14** Admin tools: bulk balance, balance *reset*, a single adjustment ≥ `FIN_APPROVAL_THRESHOLD_USD` (placeholder 10,000.00) and every profit adjustment now return **HTTP 202 `pending_approval`** and change nothing until a *different* authorised user approves (`POST /api/financial/approvals/{id}/approve`). **No approval screen exists in the frontend yet** — approvers must use the API until one is built. To restore immediate execution (not recommended): `FIN_DUAL_APPROVAL_ENABLED=false` (balance tools only) / `FIN_APPROVAL_PROFIT_ADJUST=false`.
4. **F-15** Suspended / deactivated / inactive admin, financial and MarvFlow accounts get 403 `account_restricted` on every protected route, their token is revoked, and they cannot log in. This reverses the earlier "messaging-only" restriction; one test was rewritten accordingly. Needs Management confirmation.
5. **F-12** `role`, `status`, `balance`, `withdrawal_pin` are not mass-assignable. Any custom code not in this repo that relied on it will silently ignore those keys.
6. **F-13** Settlement profit credit and deposit FX conversion use exact decimals (half-up to cents).
7. Investor email read path now sanitises HTML again on output.
8. Patch 02 (already in the candidate) also changes live behaviour — see F09_CORRECTION.md.

## Not changed (by design)
No financial feature flag is switched on. Rank thresholds untouched. No migration is run, no balance or investment is rewritten. Deposit/investment accounting cutover (F-02) remains behind `financial.separate_deposit_approval` and is **not approved** (docs/FINANCIAL_INTEGRITY_REPORT.md §F-02).

## New migration (additive, reversible, NOT yet run anywhere)
`2026_11_03_100001_create_controlled_financial_operations` (2 new tables + nullable columns). Patch‑05: `2026_11_02_100001_create_benefit_fulfilments_table`.
Order: `migrate --pretend` on a staging copy → review SQL → `migrate` role → verify.
