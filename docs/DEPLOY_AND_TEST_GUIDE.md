> **SUPERSEDED (4–5 Nov 2026):** the correction passes changed behaviour described below. Use `docs/correction-pass/` as the current source of truth.

# Deploy & test guide (staging only — isolated database)
1. Rotate every credential that was inside the supplied archives. Create **staging-only** DB/user/keys. Never reuse production values.
2. `composer install --no-dev` for the image; `composer install` locally for tests.
3. Static: `git diff --name-only | grep '\.php$' | xargs -n1 php -l`.
4. SQLite suite: `php artisan test` (fix failures before anything else; baseline claims are stale).
5. MySQL suite (isolated `*_test` DB; DESTROYS its schema): see TEST_REPORT §E.
6. Migration on staging per MIGRATION_DEPLOYMENT.md (pretend → migrate role → exit 0 → start services).
7. Smoke on staging: invest (ok / insufficient / double-click), admin add/deduct, reset → 202 → approve by second user, profit preview/request/approve/reverse, suspended admin refused, investor email view, benefits unchanged (all financial flags still OFF).
8. Reconciliation: `php artisan ssi:reconcile-legacy-deposits --out=/secure/path.csv` on a **copy**; hand to Finance.
9. Frontend: `npm install && npm ci && npm test && npm run build`; browser pass of messages/email preview.
10. Keep ROI/lending/benefit flags OFF; nothing here authorises enabling them.
Environment variables added: `FIN_DUAL_APPROVAL_ENABLED`, `FIN_APPROVAL_BALANCE_RESET`, `FIN_APPROVAL_BULK_BALANCE`, `FIN_APPROVAL_PROFIT_ADJUST`, `FIN_APPROVAL_THRESHOLD_USD`, `FIN_BULK_MAX_ACCOUNTS`, `MIGRATE_CONFIRM`, `MIGRATE_PRETEND`, `REQUIRE_MIGRATIONS_CURRENT`.
