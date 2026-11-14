# Decisions required from Management / Financial Team (nothing here is assumed approved)

| # | Decision | Why it is needed | Safe default now |
|---|---|---|---|
| D1 | **F-02 cutover**: approve enabling `financial.separate_deposit_approval` (+ `ledger_enabled`) on staging, then production; and how to treat each account flagged by the reconciliation report (honour as cash, as principal, or split). | Legacy approvals credit cash AND open principal for the same deposit. | Flags OFF; report only; no balance changed. |
| D2 | Approve the unique index on `investment_accounts.source_deposit_id` (database/controlled SQL) after duplicates check = 0 rows. | DB-level duplicate-funding guard. | Not applied. |
| D3 | Second-approval policy: threshold (placeholder USD 10,000.00), which roles approve which operation, bulk cap (placeholder 500 accounts). | Segregation of duties (F-14). | Defaults in `config/financial_controls.php`. |
| D4 | Is a **separate approver mandatory for deposit and withdrawal approval** too? (Not implemented: those remain single-approver, Financial or Admin.) | F-14 residual. | Single approver. |
| D5 | Staff suspension policy: app-wide lockout of suspended/deactivated staff (implemented) vs messaging-only (previous). | Reverses an earlier documented decision. | App-wide lockout. |
| D6 | Authorisation evidence standard for changing existing contractual returns (what counts as `authorization_reference`). | F-04. | Free-text, required, min 3 chars. |
| D7 | Profit-adjust approvers: allow Financial role to approve admin-initiated changes? | Role design. | Admin or Financial, never the requester. |
| D8 | Written approval before any ROI / lending / reinvestment benefit is activated; wording and eligibility. | Task §7. | All locked. |
| D9 | Benefit fulfilment: roles, notifications, admin screen, first benefits to enable. | §8. | Service present, not exposed. |
| D10 | Token storage: move from `localStorage` to Sanctum SPA cookie auth (HttpOnly, SameSite) — needs same-site domains / CORS / CSRF design and testing. | F-11. | Unchanged; mitigated by sanitising. |
| D11 | FX: approved sources, maximum rate age per currency, crypto stays non-eligible. | F-16. | As coded (no default rate; stale only when approved). |
| D12 | Credential rotation: audit says secrets were in supplied archives. | Hygiene. | Rotate before staging. |
