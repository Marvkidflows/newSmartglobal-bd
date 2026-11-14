# R9 — review of the global staff-status middleware for unintended effects
Change under review: `StaffAccountGuard` allowlist (`active` only) + `EnsureStaffAccountActive` on the whole `auth:sanctum` route group (`routes/api.php`), `LoginController` refusal, unfreeze rules. **Unexecuted.**

| Area | Effect | Assessment |
|---|---|---|
| **Investors** | `isStaffRole()` is false for `investor` ⇒ the middleware is a no-op; investor suspension/frozen/deactivation keep using `CheckAccountStatus` exactly as before | **No change** |
| **Administrators** | Active admins unaffected. A non-active admin is refused on every authenticated route (403 `account_restricted`, token revoked) except logout | Intended. **Unintended consequence found:** an admin could suspend/freeze/deactivate **their own** account and lock themselves out. **Fixed:** those three endpoints now return 422 for one's own account (`refuseSelfLockout`), test added |
| **Financial** | Same as admin; also covered on `/api/user`, profile, notifications (previously reachable) | Intended. **Check before deploy:** no financial user is legitimately `frozen` |
| **MarvFlow members/leads** | A non-active member previously lost only messaging (first remediation already widened to protected endpoints); now loses *all* authenticated access incl. `/api/user`, profile | Intended. Reactivation: they must log in again (token revoked; login refuses while blocked) |
| **`frozen` staff** | Previously not blocked by the staff guard (messaging only); now blocked everywhere | **Behaviour change**, Management decision #12 |
| **`inactive`** | Not a valid enum value any more (removed 2026-07-07); the allowlist still blocks it if it ever appears | No effect today; tests corrected (R12 #1) |
| **NULL / empty status** | Treated as `active` (same as the rest of the codebase) | Preserved |
| **Logout** | Exempt (`api/logout`) so a client can always end its session | Preserved |
| **Public routes** (login, register, password reset, public pages) | Outside the middleware group | Unaffected |
| **Queues / scheduler / notifications / artisan** | Middleware is HTTP-only | Unaffected (suspended staff are still skipped by notification fan-out as before) |
| **Frontend session handling** | Server returns 403 + revokes token; the old interceptor reacted to 401 only, leaving a half-logged-in UI | **Fixed:** interceptor ends the session on `account_restricted`, stores a one-time notice, redirects to `/login`; login page shows it (`utils/sessionNotice.js`, unit-tested) |
| **Performance** | One attribute read per request, no query (user already resolved by Sanctum) | Negligible |
| **Impersonation / "login as"** | None exists in the codebase (searched) | n/a |
| **Existing tests** | `MarvflowRestrictionsTest`, `MarvflowMessagesTest` (suspended financial user), `StaffAccountStatusTest` re-read: consistent, except the invalid `inactive` test data (fixed) | See R12 |

Residual: any workflow where staff are *expected* to operate while `frozen`/`suspended` would now fail — none found in the code or tests; confirm with operations. Everything above is a static review; the tests listed in `OUTSTANDING_TESTS.md` §A (`StaffAccountStatusTest`, `ApprovalWorkflowHardeningTest::test_frozen_staff…`, `MarvflowRestrictionsTest`) are the proof and have not been run.
