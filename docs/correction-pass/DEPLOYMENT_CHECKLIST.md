# DEPLOYMENT CHECKLIST — isolated STAGING only. Production is not authorised by this package.

## Gate 0 — evidence (all must be ticked with captured output)
- [ ] `bash scripts/verify-all.sh` run for real with a MySQL test database: exit 0, no SKIPPED steps — attach `verification-logs/<ts>/`
- [ ] `LOCK_REGEN=1 bash scripts/verify-frontend.sh` then `bash scripts/verify-frontend.sh`: exit 0; regenerated `package-lock.json` committed — attach logs
- [ ] All 27 rows of `BROWSER_TEST_SCRIPT.md` executed on staging and recorded
- [ ] `sha256sum -c SHA256SUMS.txt` OK
- [ ] `php -l` clean on every PHP file
- [ ] SQLite suite run; failures triaged (expect some in blind-written tests); `SSI_STRICT_MASS_ASSIGNMENT=1` run reviewed
- [ ] MySQL suite run on an isolated `*_test` database — all invariants hold
- [ ] `npm install` (regenerates lockfile) → `npm ci` → `npm test` → `npm run build` all green; lockfile committed
- [ ] Management decisions #1, #3, #5, #9 answered in writing; others acknowledged (see `REMAINING_MANAGEMENT_DECISIONS.md`)
- [ ] Credentials from the original package's `.env` rotated; no `.env`/`vendor`/`node_modules`/`.git` in the artifacts

## Gate 1 — environment (staging)
- [ ] `FEATURE FLAGS OFF`: `FINANCIAL_LEDGER_ENABLED`, legacy-deposit cutover flags, ROI/lending locks — all unchanged from the approved "OFF" state
- [ ] `FINANCIAL_DEPOSIT_VALUATION_MODE=legacy_float` (until Finance approves `exact_decimal`)
- [ ] `FIN_PROFIT_APPROVER_ROLES=admin` and **at least two active Admin accounts** exist (otherwise no profit change can be approved)
- [ ] At least two active Financial or Admin accounts exist (otherwise no balance/correction can be approved)
- [ ] No staff account is `frozen`/`inactive`/`suspended` unless intended (they are now locked out of everything)
- [ ] `PRIVACY_EMAIL_EXTERNAL_IMAGES=false`; `SCHEMA_CHECK_MODE=warn` (default; switch to `enforce` only after the migrate job succeeded)
- [ ] `APP_DEBUG=false`, real `APP_KEY`, TLS, CORS origins set to the staging frontend only

## Gate 2 — release sequence (see `DATABASE_MIGRATION_AND_ROLLBACK.md`)
1. Backup. 2. Migrate role, single instance, `MIGRATE_CONFIRM=yes`. 3. `php artisan ssi:schema-check` = 0. 4. Deploy web → worker → scheduler. 5. `php artisan ssi:approvals-maintain --dry-run` and review. 6. Deploy frontend.
Never: run migrations from the web process, from `composer setup`, or against production from this package.

## Gate 3 — staging acceptance (manual, via the real UI)
- [ ] Admin profit adjustment: submit with reason ≥10 chars + authorization reference → toast says "Request submitted and awaiting approval." with request id; **rates unchanged**
- [ ] Second Admin opens Approvals → sees before/after, requester, reason, reference → approves → status `executed`; investments recalculated; audit history shows submitted/approved/execution_started/executed
- [ ] Same Admin cannot approve/reject own request (UI explains; API 422)
- [ ] Financial user deducts more than the balance → inline 422, **no request appears**
- [ ] Large deduction → 202 pending; reduce wallet meanwhile; approve → `failed` with reason, balance untouched; restore wallet; second approver **Retry** → executed
- [ ] Investment correction (Financial) → 202; record unchanged; second approver executes; investor notified only then
- [ ] Split-adjustment test: three adjustments summing over the limit → third goes pending
- [ ] Cancel a pending request (requester); expired/cancelled request cannot be approved
- [ ] Suspended/frozen staff cannot call `/api/user`; unfreezing a suspended investor is refused
- [ ] Task completion double-click credits once
- [ ] Reconciliation report: `php artisan ssi:reconcile-legacy-deposits --out=…` on a **copy**, file is `0600`, second run refuses to overwrite

## Gate 4 — reviewers
Finance reviews the reconciliation CSV and the R6 comparison on staging data; Management signs the decisions. Only then reconsider "READY FOR PRODUCTION REVIEW". No production cutover without a separate written authorisation.
