#!/bin/sh
set -e

# One image, four possible Railway services. Set RAILWAY_PROCESS_TYPE on
# each service's Variables tab to pick which one it runs — defaults to
# "web" so the existing web service needs no changes.
#
#   web        — the app itself (unchanged behavior: migrate + serve)
#   worker     — processes the `jobs` table (QUEUE_CONNECTION=database)
#                only. Without this running somewhere, anything
#                dispatched with ->dispatch() (e.g. SendBulkEmailJob from
#                Email Center's Bulk Send) just sits as "queued" and
#                never actually sends.
#   scheduler  — runs scheduled commands from app/Console/Kernel.php
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
# Only the web service should run migrations, so the other roles skip
# that step entirely rather than racing each other on every deploy.
ROLE="${RAILWAY_PROCESS_TYPE:-web}"

case "$ROLE" in
  web)
    echo "Clearing config and cache..."
    php artisan config:clear
    php artisan cache:clear

    echo "Running migrations..."
    # Ignore migration errors if table already exists
    php artisan migrate --force || echo "Some tables already exist, skipping..."

    echo "Starting PHP server..."
    exec php -S 0.0.0.0:${PORT:-8000} -t public
    ;;

  worker)
    echo "Starting queue worker..."
    # --max-time restarts the worker periodically (Railway brings it right
    # back up) instead of letting one long-lived PHP process accumulate
    # memory forever.
    exec php artisan queue:work --sleep=3 --tries=3 --timeout=60 --max-time=3600
    ;;

  scheduler)
    echo "Starting scheduler..."
    # schedule:work is Laravel's built-in blocking scheduler loop — it
    # calls schedule:run every minute internally, so no system cron is
    # needed on Railway.
    exec php artisan schedule:work
    ;;

  background)
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
    echo "Unknown RAILWAY_PROCESS_TYPE: $ROLE (expected web, worker, scheduler, or background)" >&2
    exit 1
    ;;
esac