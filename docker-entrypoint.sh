#!/bin/sh
set -e

# One image, four possible Railway services. Set RAILWAY_PROCESS_TYPE on
# each service's Variables tab to pick which one it runs — defaults to
# "web" so the existing web service needs no changes.
#
#   web        — the app itself. F-05: it NO LONGER migrates. Schema changes are an explicit deployment step (role "migrate" below).
#   migrate    — one-shot deployment job: runs `php artisan migrate --force` ONCE, exits non-zero on any failure, never serves traffic.
#                Run it as a release/pre-deploy command on exactly ONE service; make web/worker/scheduler start only after it exits 0.
#   worker     — processes the `jobs` table (QUEUE_CONNECTION=database)
#                only. Without this running somewhere, anything
#                dispatched with ->dispatch() (e.g. SendBulkEmailJob from
#                Email Center's Bulk Send) just sits as "queued" and
#                never actually sends.
#   scheduler  — runs scheduled commands defined in routes/console.php (NOT app/Console/Kernel.php — Laravel 12 ignores it)
#                (investments:update-maturity, gaming:sync-fixtures)
#                every minute, only. Without this running somewhere,
#                those never fire either — same class of problem as the
#                worker above.
#   background — worker + scheduler together, for when only one spare
#                service (besides web) is available on the current plan.
#                Only ever run this on ONE service at a time — running
#                it alongside a separate worker/scheduler service too
#                would double-process the same jobs and, worse, could
#                double-fire investments:update-maturity and credit
#                profit twice on the same matured investment.
#
# No long-running role runs migrations. Only the one-shot "migrate" role does, so services can never race to migrate the same database.
ROLE="${RAILWAY_PROCESS_TYPE:-web}"

# Read-only schema readiness gate for every long-running role (never migrates). SCHEMA_CHECK_MODE: warn (DEFAULT) | enforce | off.
# warn    => log a loud "[schema] NOT READY" and KEEP SERVING. This is the default because the site is live: a mis-ordered deploy must never take it
#            offline. (The migration is additive and backward compatible, so apply it FIRST, then roll the code — docs/DATABASE_MIGRATION_AND_ROLLBACK.md.)
# enforce => refuse to start while migrations are pending / required tables or columns are missing. Switch to this deliberately, AFTER the migrate job
#            has succeeded, if you want a release to be unable to serve against a schema it was not built for.
# off     => skip the check. MIGRATE_CONFIRM is an accident guard, NOT authorisation.
schema_gate() {
  MODE="${SCHEMA_CHECK_MODE:-warn}"
  if [ "$MODE" = "off" ]; then echo "[schema] check disabled (SCHEMA_CHECK_MODE=off)" >&2; return 0; fi
  if OUT="$(php artisan ssi:schema-check 2>&1)"; then echo "[schema] ready"; return 0; fi
  echo "[schema] NOT READY:" >&2; echo "$OUT" >&2
  if [ "$MODE" != "enforce" ]; then echo "[schema] WARNING: continuing (SCHEMA_CHECK_MODE=$MODE). Apply the migrate job; set SCHEMA_CHECK_MODE=enforce once it has run." >&2; return 0; fi
  echo "FATAL: schema is not ready for this release. Run the 'migrate' deployment job first. Refusing to start $ROLE." >&2
  exit 1
}

case "$ROLE" in
  web)
    echo "Clearing config and cache..."
    php artisan config:clear
    php artisan cache:clear

    # F-05: migrations are NOT run here. Startup is gated on a READ-ONLY schema check instead (see schema_gate above).
    schema_gate

    # PHP's built-in server handles ONE request at a time by default, so a single slow call (Brevo, CoinPaprika, football-data...) made every
    # other visitor wait. PHP_CLI_SERVER_WORKERS makes it fork several workers so requests run in parallel. Tune with the PHP_CLI_SERVER_WORKERS
    # variable on Railway (default 4; each worker uses memory, see php-memory.ini). NOTE: PHP documents the built-in server as a development
    # server; a proper production server (FrankenPHP, or Nginx + PHP-FPM) is the longer-term fix and needs its own build and test.
    export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
    echo "Starting PHP server (${PHP_CLI_SERVER_WORKERS} workers)..."
    exec php -S 0.0.0.0:${PORT:-8000} -t public
    ;;

  migrate)
    # Explicit, single-run deployment operation. Any failure => non-zero exit => the deployment stops. Nothing is swallowed.
    echo "[migrate] $(date -u +'%Y-%m-%dT%H:%M:%SZ') starting. DB host: ${DB_HOST:-unset}  DB name: ${DB_DATABASE:-unset}"
    if [ "${MIGRATE_CONFIRM:-}" != "yes" ]; then
      echo "[migrate] REFUSING to run: set MIGRATE_CONFIRM=yes to confirm this is an intentional, approved migration run." >&2
      exit 2
    fi
    php artisan config:clear || { echo "[migrate] FATAL: config:clear failed" >&2; exit 1; }
    if [ "${MIGRATE_PRETEND:-false}" = "true" ]; then
      echo "[migrate] dry run (--pretend): SQL only, nothing is applied."
      php artisan migrate --pretend --force || { echo "[migrate] FATAL: pretend failed" >&2; exit 1; }
      echo "[migrate] pretend completed."
      exit 0
    fi
    # Serialise concurrent migrate jobs: Laravel's --isolated takes an atomic cache lock (needs a shared cache store; with array cache use ONE job only).
    if php artisan migrate --force --isolated; then
      echo "[migrate] $(date -u +'%Y-%m-%dT%H:%M:%SZ') completed successfully."
      exit 0
    else
      code=$?
      echo "[migrate] FATAL: migration FAILED (exit $code). Deployment must stop. Do NOT start web/worker/scheduler on this release." >&2
      exit "$code"
    fi
    ;;

  worker)
    schema_gate
    echo "Starting queue worker..."
    # --max-time restarts the worker periodically (Railway brings it right
    # back up) instead of letting one long-lived PHP process accumulate
    # memory forever.
    exec php artisan queue:work --sleep=3 --tries=3 --timeout=60 --max-time=3600
    ;;

  scheduler)
    schema_gate
    echo "Starting scheduler..."
    # schedule:work is Laravel's built-in blocking scheduler loop — it
    # calls schedule:run every minute internally, so no system cron is
    # needed on Railway.
    exec php artisan schedule:work
    ;;

  background)
    schema_gate
    # Runs both in the background, each wrapped in its own restart loop
    # rather than a bare `&` job: queue:work / schedule:work are meant to
    # loop forever, but if one exits for any reason — including a clean,
    # silent exit code 0 (this actually happened once: Laravel's queue
    # worker checks a "should restart" cache flag on every loop and
    # exits 0 the instant it sees one) — a bare `&` job would just stay
    # dead with nothing noticing. The loop restarts it within 2 seconds
    # and logs it, so a silent exit becomes a brief hiccup, not a
    # permanently dead process.
    #
    # No `wait -n` here (that's bash-only and caused its own outage
    # earlier — dash's `wait` doesn't support it) — each loop already
    # restarts itself forever, so the plain POSIX `wait` at the bottom
    # just needs to block, which it does correctly either way.
    echo "Starting queue worker (background, auto-restart)..."
    ( while true; do
        php artisan queue:work --sleep=3 --tries=3 --timeout=60 --max-time=3600 \
          >> storage/logs/worker.log 2>&1
        echo "$(date -u +'%Y-%m-%d %H:%M:%S') queue:work exited (code $?) — restarting in 2s" >> storage/logs/worker.log
        sleep 2
      done ) &

    echo "Starting scheduler (background, auto-restart)..."
    ( while true; do
        php artisan schedule:work \
          >> storage/logs/scheduler.log 2>&1
        echo "$(date -u +'%Y-%m-%d %H:%M:%S') schedule:work exited (code $?) — restarting in 2s" >> storage/logs/scheduler.log
        sleep 2
      done ) &

    wait
    ;;

  *)
    echo "Unknown RAILWAY_PROCESS_TYPE: $ROLE (expected web, migrate, worker, scheduler, or background)" >&2
    exit 1
    ;;
esac