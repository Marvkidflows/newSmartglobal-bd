# BROWSER TEST SCRIPT — manual, against a STAGING API + built frontend
Nothing here has been run. Record the result of every row (PASS/FAIL, tester, date, screenshot name). A row is not passed until someone has done it.
Prerequisites: two active Admins (A1, A2), two active Financial users (F1, F2), one investor "Ada" with balance ≥ $20,000 and one active investment (1000 @ 10 %), `FIN_PROFIT_APPROVER_ROLES=admin`, cumulative/threshold defaults, expiry disabled.

| # | Role | Steps | Expected | Result |
|---|---|---|---|---|
| B1 | A1 | Admin → Global Mgmt → Profit: +2 %, scope global, reason "x", no reference → Submit | Blocked client-side: reason ≥ 10 chars, then reference required (no request sent) | |
| B2 | A1 | Same with valid reason + reference "BOARD-1" → confirm | Toast **"Request submitted and awaiting approval. Request #N · status: pending."** with ⏳ — **no success toast**; Ada's investment rate unchanged | |
| B3 | A1 | Admin → Approvals | Request #N listed as *Pending*, type "Profit rate adjustment", "you" marker, button **View** (not Review) | |
| B4 | A1 | Open #N | Shows reason, reference, requester A1, time; **no Approve/Reject**; message "You submitted this request…"; **Cancel request** present | |
| B5 | A2 | Approvals → Review #N | Approve & Reject present; before/after details; audit history shows *submitted* | |
| B6 | A2 | Reject with note < 5 chars | Client message; nothing sent. With valid note → status *rejected*, history shows reject; rate unchanged | |
| B7 | A1 | Re-submit the same adjustment, A2 approves | Status *executed*; Ada's investment rate 12 %, expected profit recalculated; history: submitted → approved → execution started → executed | |
| B8 | A1 | Double-click Approve/Reject/Retry quickly (as the approver) | Only one request fires; buttons disable while busy | |
| B9 | A1 | Global Mgmt → History | Row shows status badge *Applied*, **Reverse…** button | |
| B10 | A1 | Reverse… → reason "short" | Blocked (≥ 10 chars). Valid reason → ⏳ pending toast; row now shows **"Reversal pending (#M)"**, no Reverse button | |
| B11 | A2 | Approve #M | Row becomes *Reversed*; Ada's rate back to 10 % | |
| B12 | A1 | Re-submit a request identical to an already-executed one (same Idempotency-Key via API client or UI double submit) | Message "An identical request (#N) already exists and is executed" — **not** "awaiting approval" | |
| B13 | F1 | Financial → Ada → Adjust balance → Deduct $25,000 (more than balance) | Inline error "Cannot deduct more than the current balance."; **no request in Approvals** | |
| B14 | F1 | Deduct $15,000 | ⏳ pending; balance unchanged; Approvals shows it; F1 cannot approve own | |
| B15 | F2 | Before approving, an Admin adjusts Ada down to $5,000 elsewhere; F2 approves #K | Error toast with server reason; request shows **failed** with red banner + attempts; balance unchanged | |
| B16 | F2/A1 | Restore balance; A1 (not requester) → Retry | Executed. The original requester has no Retry button | |
| B17 | F1 | Financial → Investments → Correct record (amount 2000) without authorization reference | Blocked client-side. With reference → ⏳ pending, record unchanged | |
| B18 | A1 | Approve the correction | Executed; amount 2000, expected profit 200; Ada notified **only now** | |
| B19 | F1 | Three balance adjustments that sum over the limit within 24 h | The third goes pending | |
| B20 | F1 | Requester cancels own pending request (reason ≥ 5) | Status *cancelled*; cannot be approved afterwards | |
| B21 | Admin | Suspend/freeze/deactivate **your own** account | Refused with "You cannot … your own account" | |
| B22 | Admin | Freeze F2 while F2 is signed in; F2 clicks anything | F2 is redirected to `/login` with the "staff account is frozen" notice; F2 cannot log in until unfrozen; F2 token no longer works | |
| B23 | Admin | Unfreeze a **suspended** investor | Refused ("Use activate…"); status unchanged | |
| B24 | Any non-approver role (marvflow member) | Visit `/admin/approvals`, `/financial/approvals` | Route guards redirect; API returns 403 | |
| B25 | A1 | Approvals filters: status, type, requester, from/to date | Each narrows the list correctly; "Stuck" lists only interrupted ones | |
| B26 | Operator | `php artisan ssi:approvals-maintain --dry-run` after creating a legacy `approved` row | Listed; the inbox shows it as *stuck*; for bulk/correction rows after recovery: **no Retry**, banner says verify manually | |
| B27 | Mobile width (≈ 390 px) | Open Approvals list and a detail modal | Usable, no horizontal page scroll (tables scroll inside their container) | |
