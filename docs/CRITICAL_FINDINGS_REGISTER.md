> **SUPERSEDED (4–5 Nov 2026):** the correction passes changed behaviour described below. Use `docs/correction-pass/` as the current source of truth.

# Critical findings register (post-remediation status; "Fixed" = code changed, **not execution-verified**)
| ID | Sev | Status | What was done | Remaining / required |
|---|---|---|---|---|
| F-01 | Critical | **Fixed in code; unverified** | Single transaction, user row `lockForUpdate`, balance re-read, decimal checks, conditional decrement, optional idempotency key, plan limits re-checked. | Run atomicity + **MySQL concurrency** tests. Frontend does not send an Idempotency-Key yet (row lock is the real guard). |
| F-02 | Critical | **Mitigated, NOT cut over** | Duplicate approval already row-locked (test added). Read-only reconciliation command; controlled unique-index SQL; cutover plan; flags left OFF. | Management D1/D2. Legacy overlap remains for existing/ongoing flags-OFF approvals. Withdrawal-of-principal guard for legacy mode not built (needs D1). |
| F-03 | High | **Fixed in code; unverified** | Shared `BalanceAdjustmentService` (lock, re-read, decimal, relative SQL, audit, idempotency), preview, ordered locks, atomic bulk with reported skips, second approval, cap. | No approval UI. Run tests. |
| F-04 | High | **Fixed in code; unverified** | Per-investment items (before/after), authorisation reference, approval, reversal, preview, locks, decimals. | Approval UI; D6/D7. |
| F-05 | High | **Fixed; shell logic executed** | Web no longer migrates; `migrate` role; non-zero exits; isolated lock; guard; docs. | Real staging run; platform pipeline must call the migrate job. Dockerfile `chmod 777` not changed. |
| F-09 | High (process) | **Corrected (new docs)** | F09_CORRECTION.md, APPLY.md. | Originals of APPLY/RELEASE_READINESS/DECIMAL_MIGRATION reports were not supplied; patch‑02 suite + before/after rank comparison not run. |
| F-10 | Low | **Partly** | Stray `InvestorEmailController copy.php` removed; `.gitignore` already UTF-8/ASCII-clean; package hygiene enforced. | **`package-lock.json` NOT updated** (needs registry): run `npm install`. "frontend-03 patch" was not supplied. |
| F-11 | Medium | **Partly** | All server HTML paths reviewed (sanitised on write; investor email read path now sanitised; stray duplicate removed); client `safeHtml` on 5 sinks; payload tests written. | Token still in `localStorage` (D10). Client/server tests unexecuted. |
| F-12 | Low | **Fixed in code; unverified** | 4 fields removed from fillable; call sites converted (register, admin status, 2 seeders, 1 test file). | Full suite must confirm no missed call site (a missed one would silently ignore a write). |
| F-13 | Medium | **Partly** | Settlement credit, deposit FX conversion, investor/admin/bulk/profit paths use `Decimal`. | `UsdValuationService`, display/tolerance floats, ~300 other float sites not touched (by design: triage required). |
| F-14 | Medium | **Partly** | Configurable second approval for reset/bulk/large/profit; approver ≠ requester; role lists. | Deposit/withdrawal approval still single-approver (D4). |
| F-15 | — | **Fixed in code; unverified** | `StaffAccountGuard` in 4 middleware + login; token revoked. | D5. |
| F-16 | — | **Reviewed (read-only)** | Existing code: no default rate of 1; immutable snapshots with `supersedes_id`; stale only when an age is approved; crypto blocked. | No new tests; float rate maths in `UsdValuationService`; "corrected rate must not rewrite earlier rank eligibility" not proven — needs MySQL FX test. |
