# SECURITY REVIEW — correction pass
Scope: what changed in this pass plus the items the audit/instructions named. Findings are from code reading; nothing was exploited or executed.

## 1. Authorization and approval controls
| Control | Where | Notes |
|---|---|---|
| Approver ≠ requester (approve **and** reject), enforced server-side | `FinancialApprovalService::assertMayDecide` | UI only explains |
| Approver and requester re-read from DB at execution; must be **active** staff with an allowed role | `…::claimAndExecute` | A requester suspended after submitting cannot have the request executed |
| Single-transaction claim+execute; row lock; decided once | same | No stuck "approved"; failure recorded as `failed` and retryable by a different person |
| Payload integrity (canonical hash) before execution; idempotency key bound to payload hash (409) | same | Hash survives MySQL JSON key reordering |
| One pending request per identical payload (DB-unique `open_key`) | migration `…100001` | |
| Investment correction only via approval; re-validates active/unpaid and unchanged original values | `InvestmentCorrectionService` | No alternate route found (re-checked by `grep`): the only code that **edits** `amount/profit_percentage/expected_profit/total_return` of an existing investment is `ProfitAdjustmentService` (approval-gated) and this service; settlement changes only `status`. New positions are *created* by the investor invest flow, deposit approval, ledger allocation and the demo-data command — creation, not correction |
| Self-adjustment refused | `BalanceAdjustmentService::assertNotSelf` | also at execution |
| Cumulative splitting control | `BalanceAdjustmentService::requiresApproval` | restrictive default; **collusion between two requesters is not prevented by thresholds** — that is what the second approver is for |
| Deduction validated against locked current balance before any request exists | `submitOrApply` | |
| Remaining direct balance writers | settlement, ledger allocation/principal, deposit/withdrawal approval, investor invest | **Out of scope, unchanged**: each locks and records its own movement; deposit/withdrawal approvals are still single-approver (decision #4) |

## 2. Staff status (F-15)
Allowlist (`active` only; NULL ⇒ active). Enforced by the three role middlewares **and** a new middleware on the entire `auth:sanctum` group (so `/api/user`, profile, notifications are covered); the token is revoked on the first refused request; logout stays reachable; login also refuses. Queues/schedulers are not HTTP and are unaffected. `unfreeze` cannot reactivate suspended/deactivated accounts. **Risk to check before deploy:** any staff legitimately `frozen`/`inactive` is now locked out.

## 3. Mass assignment (F-12)
Protected from `$fillable`: `role`, `status`, `balance`, `withdrawal_pin`, `investor_rank_id`, and now `kyc_status`, `kyc_verified`, `kyc_verified_at`. Call sites reviewed: register (unguarded), PIN (property), admin status (`forceFill`), KYC approve/reject/submit (now `forceFill`), seeders (unguarded), profile update (validated keys only). Tests assert protected fields cannot be mass-assigned (strict mode throws). **Silent discard remains the production default** (Laravel behaviour); a whole-suite strict run is required to find any other offender.

## 4. Token storage (F-11) — assessment, **no migration performed**
Current: Laravel Sanctum **personal access (bearer) tokens** created at login/registration, stored in `localStorage`, sent as `Authorization: Bearer`. There is no `config/sanctum.php` (framework defaults, no token expiry set). The SPA (Vercel) and API (separate host) are **cross-site**.
Risk: any script running in the page origin can read the token (XSS ⇒ account takeover). Mitigations in place: server strict sanitiser, client sanitiser at all 5 `dangerouslySetInnerHTML` sinks (grep: `RichText`, `MarvflowThread`, `MarvflowMessages`, `AdminEmailCenter`×2 — all wrapped), CSS property allowlist, no inline script allowed.
A safe migration is **possible but is a project**, not a flag: (1) Sanctum SPA cookie mode needs a shared parent domain (or `SameSite=None; Secure` with CSRF-cookie handshake across sites) — `SESSION_DOMAIN` is `null` today; (2) `supports_credentials` is already `true` in CORS; (3) every axios call needs `withCredentials` + CSRF header; (4) mobile/other clients using bearer tokens need to keep working during a transition; (5) logout/rotation/expiry semantics must be re-tested. Recommended order: set token expiration and rotate on privilege change now (cheap), add a strict CSP via the host, then pilot cookie auth on staging behind a flag. **Do not ship a half-migrated scheme.** Decision #7.

## 4b. HTML sanitisation
- **Server** (`RichTextSanitizer`): strict allowlist; styles per-declaration validated (no `position`, `url()`, `var()`, `@import`); `javascript:`/`data:` links removed; email profile image `src` limited to `https` **only when `PRIVACY_EMAIL_EXTERNAL_IMAGES=true`**, else raster `data:` URIs only (decision #6). New regression test for `position:fixed` on both profiles.
- **Client** (`safeHtml.js`): was accepting *any* CSS property that merely avoided a few bad substrings, so `position:fixed;inset:0;z-index:9999` (click-jacking/phishing overlay) survived. Now a **property allowlist** (`sanitizeStyle`); unsafe declarations are dropped, the attribute is removed when nothing remains. Executed: 4 logic tests; DOM-level tests written, not executed.
- **DOMPurify not added.** The instruction asks for a maintained sanitizer; adding it requires the registry and a regenerated lockfile, which are blocked here. The current hand-rolled DOMParser allowlist is defence in depth behind the server sanitiser. **Recommendation:** add `dompurify` when the lockfile is regenerated and keep `safeHtml` as the policy wrapper (`DOMPurify.sanitize(html, {ALLOWED_TAGS, ALLOWED_ATTR, FORBID_ATTR:['style' except allow-listed]})`).

## 5. Docker / deployment
`chmod 777` removed; container runs as `www-data`; only `storage/` and `bootstrap/cache/` writable; entrypoint 0755 root-owned. The startup schema gate logs loudly and keeps serving by default (`SCHEMA_CHECK_MODE=warn`, so a mis-ordered deploy cannot take the live site down); `enforce` is an explicit opt-in; migrations only by the explicit `migrate` role. **Not changed:** the PHP built-in server (`php -S`) as the web process — acceptable for staging, replace with php-fpm/nginx before production. Docker build **not executed**; verify the non-root user can write logs/cache on the platform's volume mounts.

## 6. Reconciliation command (F-02)
Reviewed: only `SELECT`s; writes only the CSV; output contains ids, amounts, statuses (no names/emails/phones). Now refuses to overwrite without `--force`, creates with exclusive mode, `0600`, and refuses a path inside the public web root. Feature remains OFF; no cutover.

## 7. Items found but not fixed
- Built-in server (above); single-approver deposit/withdrawal approvals (#4); token in localStorage (#7); no brute-force lockout review performed; rate limiting on new approval routes uses the existing `throttle:30,1` only.

## 8. Second-pass additions
- **Self-lockout:** an administrator cannot suspend, freeze or deactivate their own account (422) — with staff access now an allowlist, doing so would end their own access (and potentially the last admin's).
- **Retry cannot double-apply:** requests whose execution cannot be proven after an interruption are flagged `needs_manual_verification`; the server refuses retry; only cancel + fresh request after human verification.
- **Deadlocks:** retried as a whole before anything is committed; a `failed` record is never written into a rolled-back transaction.
- **Decided duplicates:** an idempotent re-submission of an already-decided request reports its real state and never "awaiting approval".
- **Session end:** a restricted staff session is ended client-side with a notice (previously half-logged-in).
- Full details: `R8_APPROVAL_SERVICE_REVIEW.md`, `R9_MIDDLEWARE_REVIEW.md`.
