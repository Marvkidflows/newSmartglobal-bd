# Rollback procedure
| Change | How to roll back | Data impact |
|---|---|---|
| Code (all items) | Redeploy previous image / re-apply base candidate. | None on its own. |
| New migrations | `php artisan migrate:rollback --step=2 --force` on **staging**; production only with approval + backup. | Drops the approval/profit-item tables and added nullable columns: **approval history and profit before/after records are lost** — export them first. |
| Profit adjustment applied in error | Use the **reverse** endpoint (second approval), not a schema rollback. Items that changed since are reported as conflicts and left untouched. | Restores recorded before-values only. |
| Balance adjustment in error | Post a compensating adjustment (full audit trail); never edit balances directly. | Append-only history kept. |
| Dual-approval too strict operationally | Set `FIN_APPROVAL_*` / `FIN_DUAL_APPROVAL_ENABLED` env (no deploy). | Behaviour only. |
| Staff guard too strict | Revert `StaffAccountGuard` calls in the four middleware + LoginController (documented in APPLY.md §4). | Behaviour only. |
| `web` no longer migrating | Not a rollback case: run the migrate job. | — |
| Unique index F-02 (if later applied) | `ALTER TABLE investment_accounts DROP INDEX uq_investment_accounts_source_deposit;` | None. |
