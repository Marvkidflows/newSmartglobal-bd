# Unresolved issues, limitations and management decisions

## Verification gaps (largest risk)
1. **Nothing PHP was executed** (no lint, no migration, no tests, no route load). Run Guide §B–D first.
2. Frontend build/tests not run here (Windows-only native modules). Run Guide §E.
3. Brevo delivery, inbound mail and webhooks have never run against a live account.

## Integration decisions I made (please confirm or reverse)
1. **Withdrawal `store()` rewrite from Task 5 accepted** (existing financial flow touched; behaviour identical when no fee policy is approved). Review this file by hand before deployment.
2. **Registration roles can be given mailbox/contributor rights**, but never `investor.*` mailing permission (hard block).
3. **Old announcements not migrated** into the new news module; kept under `/investor/announcements`. A one-feed migration needs your approval.
4. Unapproved referral amount is not advertised to investors; pending reward rows (amount snapshot) are still shown, labelled pending/not paid.

## Known limitations
* ~~Delegated staff had no screens~~ — **addressed** (permission-driven Mailing / News & Media screens in each department; admin link to Registration).
* The original has no investor withdrawal cancellation, failed-payment or reversal-of-approved-withdrawal flow; none was created, so fee adjustment for those cases is untested/unreachable.
* Task 5 seeds a **referral policy row of USD 5.00 that is unapproved with every criterion off**. It is inert, but if you do not want the figure in the database at all, delete that seed row before first migrate.
* `referrals:scan --no-backfill` **is** scheduled hourly at :17 (re-assessment only, never approves/credits); the historic backfill (`php artisan referrals:scan`) is manual and unscheduled.
* Registration export and staff password change are now implemented. Maker/checker is still **not** implemented (proposal).
* Task 2 enumeration of registered emails/phones at stage 1 is unchanged (pre-existing).
* `RegistrationResume` atomicity needs InnoDB; rotating `APP_KEY` invalidates in-flight codes.

## Management decisions still required
Task 2: definition of "Submitted"; reviewer visibility of full ID numbers; tracking-notice wording (tracking OFF by default); follow-up defaults (24h/72h/max 3).
Task 5: the 16 items in `docs/task5/MANAGEMENT_DECISIONS.md` (eligible-referral definition, KYC/deposit/investment criteria, caps, reward payout/reversal rules, fee amount/type, fee handling on rejected/reversed withdrawals, accounting treatment, dual control).
Task 3/4: consent-policy version wording; who may publish investor-only news; media size caps and retention.

## Added in the gap-closure round
* **MySQL concurrency: NOT proven resolved.** Most likely a test counting error (corrected); the new rounds test and the existing suites must be run on MySQL — `MYSQL_CONCURRENCY_INVESTIGATION.md` §4 states exactly what result closes it.
* **Stage-1 enumeration closed except phone-after-email-proof.** Decision E2: add SMS (phone-ownership) verification, or defer duplicate-phone handling to the Registration Team's KYC review, or accept the budgeted residual. Also decide the go-live mail provider SLA: registration now depends on code delivery.
* **Withdrawal cancellation / failed payment / reversal:** decisions D1–D7 in WITHDRAWAL_CANCELLATION_REVERSAL_PROPOSAL.md. The fee reverse/waive/refund action stays disabled until D3/D6 are approved.
* **Maker/checker:** decisions M1–M5 in MAKER_CHECKER_PROPOSAL.md.
* **Export scope:** should Registration *members* (not only leads/admins) be able to export? Currently lead/admin only.
* `referrals:scan --no-backfill` is scheduled; the one-off historic backfill remains a manual decision (it would create Pending rows at the unapproved amount for existing referrals).

## Added in the final-remediation round
* **Withdrawal observations** (`WITHDRAWAL_FINANCIAL_SECURITY_REVIEW.md` §4): W1 should a `hold` withdrawal also block a new request? W2 should only approved+active fee-policy versions be considered (today a newer unapproved draft switches fees off)?
* **Cancellation / failed payment / reversal:** implementation plan ready; decisions D1–D8 (`WITHDRAWAL_CANCELLATION_REVERSAL_PROPOSAL.md`). Nothing activated.
* **Maker/checker:** the original engine exists (approver ≠ requester; 10,000 USD placeholder threshold). Decisions M1–M6 (`MAKER_CHECKER_PROPOSAL.md`). The only code change is the fail-closed threshold guard on referral credit/reversal/fee refund.
* **The original threshold (10,000 USD) is a Management placeholder** — confirm or replace.

## Added after the local test runs
* **Notification failures after a committed admin action:** DECIDED and implemented for `suspend()` only (`SUSPEND_NOTIFICATION_PATCH.md`; tests written, not yet run). **`freeze()`, `unfreeze()` and `activate()` still have the un-guarded `notify()`** (a Brevo outage returns 500 although the status was saved) — decide whether to apply the same handling.
* **Create a `.env.testing` with no real credentials** so tests can never use live Brevo/Telegram/Cloudinary keys.
* **Confirm `npm run build`** and run the **MySQL concurrency suites** (still not run).
