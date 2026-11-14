# MANAGEMENT DEFAULTS REGISTER — decisions outstanding, safe defaults in force
Instruction of 5 Nov 2026: *proceed using the documented safe defaults.* **Using a default is NOT an approval.** Every row below is **NOT APPROVED BY MANAGEMENT**. Nothing in the code, configuration or documentation says otherwise.

**Classification**
- **Preserved existing rule** — the rule that existed before the remediation is kept unchanged; nothing new introduced.
- **Temporary technical safeguard** — a conservative restriction added by the remediation until Management decides; replaceable by a recorded decision.
- **Documented risk** — nothing changed; risk recorded.
**Confirmation** — *required*: Management/Finance must confirm or replace it before it is treated as policy. *optional*: no existing rule was changed; confirm only if you want to change it.

**How to see what is actually in force:** `php artisan ssi:defaults-report` (read-only; prints the effective value of each item from live configuration). `php artisan ssi:defaults-report --check` exits 1 if any value differs from the documented safe default — an override then needs a recorded approval. `--json` for the audit trail. The same check is step 03b of `scripts/verify-all.sh` and is covered by `ManagementDefaultsTest` (written, not executed).

| # | Topic | Default in force | Classification | Confirmation | Switch (change only with a recorded approval) |
|---|---|---|---|---|---|
| 1 | Cumulative adjustment threshold | Window **24 h**. Limit = `FIN_CUMULATIVE_THRESHOLD_USD` if set; otherwise the **existing** per-adjustment threshold (`FIN_APPROVAL_THRESHOLD_USD`, repo placeholder `10000.00`); if neither is configured the restriction is **disabled** — no value is invented | Temporary technical safeguard | **Required** (the 10,000 placeholder itself is also unapproved) | `FIN_CUMULATIVE_WINDOW_HOURS`, `FIN_CUMULATIVE_THRESHOLD_USD`, `FIN_APPROVAL_THRESHOLD_USD` |
| 2 | Profit adjustment limits | Existing validation unchanged (adjustment −100…+100 points; corrected rate 0…1000 %); **no new cap** | Preserved existing rule | Optional | `FIN_PROFIT_PERCENTAGE_CAP` (empty) |
| 3 | Who approves profit adjustments / reversals | **Admin only**, and a **different** active Admin than the requester (hard rule) | Temporary technical safeguard | **Required** | `FIN_PROFIT_APPROVER_ROLES=admin` |
| 4 | Deposit & withdrawal approvals | Existing **single-approval** workflow preserved; nothing added or removed | Preserved existing rule | Optional | `FINANCIAL_SEPARATE_DEPOSIT_APPROVAL` (leave OFF) |
| 5 | FX valuation & rounding | **`legacy_float`** (original calculation). A non-USD payment requires a positive rate **plus source and date**; an unknown rate is never assumed to be 1; currency, amount, rate, source and rate date are stored with the deposit | Preserved existing rule | **Required** (before any move to `exact_decimal`; see `R6_ROUNDING_COMPARISON.md`) | `FINANCIAL_DEPOSIT_VALUATION_MODE` |
| 6 | External images in email rich text | **Disabled** (embedded raster `data:` images still work) | Temporary technical safeguard | **Required** | `PRIVACY_EMAIL_EXTERNAL_IMAGES` |
| 7 | Authentication token mechanism | **Unchanged**: Sanctum bearer token in `localStorage`. No untested migration. Risk documented | Documented risk | **Required** | — (see `SECURITY_REVIEW.md` §4) |
| 8 | Approval expiry & recovery | Automatic expiry **disabled**; recovery is the documented **manual** process (`php artisan ssi:approvals-maintain`, not scheduled) | Temporary technical safeguard | **Required** | `FIN_APPROVAL_EXPIRY_HOURS`, `FIN_STUCK_EXECUTION_MINUTES` |
| 9 | Investment amount / profit-rate corrections | **Every** correction needs a **different** authorised approver (Admin or Financial); the protection must not be disabled | Temporary technical safeguard | **Required** | `FIN_APPROVAL_INVESTMENT_CORRECTION`, `FIN_DUAL_APPROVAL_ENABLED` (leave ON) |
| 10 | Task LOSS handling | Existing rule preserved: a verified loss may take the wallet below zero; applied **exactly once**, row-locked, with `BalanceAdjustment` + `FinancialAuditLog` | Preserved existing rule | Optional | code-level |
| 11 | Retry & cancellation of approval requests | Retry: authorised **non-requester** only, **never** for requests flagged `needs_manual_verification`. Cancel: the requester or an active Admin, only `pending`/`failed` | Temporary technical safeguard | **Required** | code-level |
| 12 | Staff account status | Only **`active`** staff have access; `suspended`, `frozen`, `deactivated` and any unknown status are refused (the documented frozen-account restriction); investors keep their own restriction model | Temporary technical safeguard | **Required** | code-level (`StaffAccountGuard::ALLOWED_STATUSES`) |

**Other behaviour introduced as security guards (not financial rules; also unapproved):** an administrator cannot suspend/freeze/deactivate their own account; an idempotent re-submission of an already-decided request reports its real state; the startup schema check **warns and keeps serving by default** (so the live site cannot be taken offline by a mis-ordered deploy; `SCHEMA_CHECK_MODE=enforce` is an explicit opt-in).
**What is deliberately NOT done:** no new financial limit, threshold, cap or rounding rule was invented or activated; no feature flag or cutover setting was changed; no data migration or historical correction exists in this release.
