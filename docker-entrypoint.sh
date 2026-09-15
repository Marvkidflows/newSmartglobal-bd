#!/bin/sh
set -e

# One image, three possible Railway services. Set RAILWAY_PROCESS_TYPE on
# each service's Variables tab to pick which one it runs — defaults to
# "web" so the existing web service needs no changes.
#
#   web       — the app itself (unchanged behavior: migrate + serve)
#   worker    — processes the `jobs` table (QUEUE_CONNECTION=database).
#               Without this running somewhere, anything dispatched with
#               ->dispatch() (e.g. SendBulkEmailJob from Email Center's
#               Bulk Send) just sits as "queued" and never actually sends.
#   scheduler — runs scheduled commands from app/Console/Kernel.php
#               (investments:update-maturity, gaming:sync-fixtures) every
#               minute. Without this running somewhere, those never fire
#               either — same class of problem as the worker above.
#
# Only the web service should run migrations, so worker/scheduler skip
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

  *)
    echo "Unknown RAILWAY_PROCESS_TYPE: $ROLE (expected web, worker, or scheduler)" >&2
    exit 1
    ;;
esac