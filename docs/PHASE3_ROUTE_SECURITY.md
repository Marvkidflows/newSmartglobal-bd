# Phase 3: admin API authentication and authorization

RESULT OF THE REVIEW (static, 396 routes parsed from routes/api.php): no genuine authorization gap found; no code change needed.

| Directive item | Finding | Evidence |
|---|---|---|
| Authentication on every admin rank / financial-control endpoint | All 91 rank, loan, incentive and financial-control routes sit inside `auth:sanctum` + `staff.active` + a role middleware | route parse; new sweep test |
| Server-side gates, not frontend only | 61 of 64 rank/loan/benefit/incentive routes check a Gate in the method; 2 more through a shared `toggle()` that checks `ranks.manage-criteria`; the last is the investor's own read | route parse + controller read |
| Rate limiting | All 56 state-changing sensitive routes carry a throttle (5 to 60 per minute) | route parse; new sweep test |
| Investors cannot change own rank/badge | Rank fields are not mass assignable; `non admins cannot assign or downgrade even by direct api call` | RankFoundationTest, RankManualChangeTest (passed) |
| Financial staff cannot assign/configure ranks | Financial routes expose review/export only; `financial team may approve and reject but not configure` | RankSpecComplianceTest (passed) |
| 401 / 403 | `no guest can reach any rank route`, `no non admin role can reach any rank route` | RankAuthorizationTest (passed) |
| Benefit approvals | `only an admin can change benefits and others get 403`; `the financial team cannot approve or activate incentives`; `the author cannot approve their own incentive term` | RankBenefitsFrameworkTest (passed) |
| Criteria changes | `non admins cannot manage criteria or read history` | RankCriteriaTest (passed) |
| Currency verification separation of duties | `confirmation cannot be given before a review or by the reviewer`; `only authorised roles can review confirm revoke or activate` | RankCurrencyPolicyTest, RankCurrencyTest (passed) |
| Exports need the export permission | `unauthorised users cannot configure approve award or export`; export refused tests | RankSpecComplianceTest, RankExportAuditTest (passed) |
| Loan administration separate | `only admins can configure review or decide loans`; whole lock suite | RankBenefitsFrameworkTest, LendingLockTest (passed) |
| Manual override off unless approved, fully recorded | `with overrides disabled nothing can be forced through`; `when authorized an override needs a reason and is recorded with the admin and justification` | RankDecisionsTest, RankManualChangeTest (passed) |
| Audit logging of sensitive changes | rank audit, criteria history, benefit audit, loan events, export audit (new) | RankAuditTest etc. (passed) |

GAP FOUND: no test asserted rate limiting, and nothing would stop a future route being added without authentication, the staff guard, a role or a throttle.
ADDED: tests/Feature/Rank/RankRouteSecuritySweepTest.php, a standing sweep of the live route table (NOT EXECUTED until you run it).

LOW-SEVERITY NOTE (unchanged): GET /api/notifications/mark-all-read changes data on a GET request. It needs a bearer token so CSRF does not apply, but it should be POST; changing it needs a frontend change too.
