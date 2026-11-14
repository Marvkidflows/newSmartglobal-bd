> **SUPERSEDED (4–5 Nov 2026):** the correction passes changed behaviour described below. Use `docs/correction-pass/` as the current source of truth.

# Migration deployment procedure (F-05)

## What changed
`docker-entrypoint.sh` roles: `web` (serve only; never migrates), **`migrate`** (one-shot job), `worker`, `scheduler`/background (unchanged, never migrate).
* `migrate` refuses to run unless `MIGRATE_CONFIRM=yes` (exit 2).
* `MIGRATE_PRETEND=true` prints SQL, applies nothing.
* Real run: `php artisan migrate --force --isolated`; any failure ⇒ exit code ≠ 0 and a `FATAL` log line; nothing is swallowed (the old `|| echo` is gone).
* `--isolated` takes an atomic cache lock so two migrate jobs cannot race (needs a shared cache store, e.g. database/redis; with the `array` driver run exactly one job).
* Optional guard: `REQUIRE_MIGRATIONS_CURRENT=true` makes `web` refuse to start while migrations are pending.
Shell logic verified with a stub `php` (`sh scripts/test-entrypoint.sh`, 8 checks). **Real `artisan migrate` was not run.**

## Isolated staging procedure (never production data)
1. Provision a **separate** MySQL database + credentials for staging. Restore a *sanitised* copy or start empty. Never point at production.
2. Take a backup/snapshot (`mysqldump --single-transaction`), record its checksum.
3. Dry run: `RAILWAY_PROCESS_TYPE=migrate MIGRATE_CONFIRM=yes MIGRATE_PRETEND=true sh docker-entrypoint.sh` — review the SQL.
4. Run: `RAILWAY_PROCESS_TYPE=migrate MIGRATE_CONFIRM=yes sh docker-entrypoint.sh`; require exit 0.
5. Only then start web/worker/scheduler (deploy pipeline: migrate job → on success → roll services). If step 4 fails the pipeline must stop; do not start the new web build.
6. Verify: `php artisan migrate:status` (nothing pending), smoke tests, then the MySQL suite (DEPLOY_AND_TEST_GUIDE.md).

## Rollback
* Application: redeploy previous image (the old image's web role will auto-migrate again — use the *old* tag only with the old schema).
* Schema (staging): `php artisan migrate:rollback --step=2 --force` reverts the two new migrations (down() drops only objects they added). Never roll back on production without a backup restore plan.
* Data: restore the pre-migration dump. The new migrations do not modify existing rows, so data rollback is normally unnecessary.
