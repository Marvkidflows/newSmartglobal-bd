#!/bin/sh
# F-05 verification: exercises docker-entrypoint.sh with a STUB `php` (no PHP/DB needed). Proves the shell logic only:
#  - web never migrates, - migrate refuses without confirmation, - a failing migration exits non-zero, - web/worker/scheduler/background refuse to start on a not-ready schema.
# It does NOT prove artisan/MySQL behaviour; run the staging procedure in docs/MIGRATION_DEPLOYMENT.md for that.
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; SHIM="$(mktemp -d)"; fail=0
cat > "$SHIM/php" <<'STUB'
#!/bin/sh
echo "php $*" >> "$STUB_LOG"
case "$*" in
  *"migrate --force --isolated"*) [ "${STUB_MIGRATE_FAIL:-0}" = "1" ] && { echo "SQLSTATE[42S01]: simulated failure" >&2; exit 1; }; exit 0;;
  *"ssi:schema-check"*) [ "${STUB_SCHEMA_FAIL:-0}" = "1" ] && { echo "1 pending migration(s): 2026_11_04_100001_harden_financial_approval_workflow" >&2; exit 1; }; exit 0;;
esac
exit 0
STUB
chmod +x "$SHIM/php"; export PATH="$SHIM:$PATH" STUB_LOG="$SHIM/calls.log"
t(){ name="$1"; want="$2"; shift 2; : > "$STUB_LOG"; env "$@" sh "$ROOT/docker-entrypoint.sh" >"$SHIM/out" 2>&1; got=$?
  if [ "$got" = "$want" ]; then echo "PASS $name (exit $got)"; else echo "FAIL $name (exit $got, wanted $want)"; fail=1; fi; }
t "web does not migrate"            0 RAILWAY_PROCESS_TYPE=web;           grep -q "migrate --force" "$STUB_LOG" && { echo "FAIL web called migrate"; fail=1; }
t "migrate refuses w/o confirm"     2 RAILWAY_PROCESS_TYPE=migrate
t "migrate succeeds"                0 RAILWAY_PROCESS_TYPE=migrate MIGRATE_CONFIRM=yes
t "migrate FAILURE is non-zero"     1 RAILWAY_PROCESS_TYPE=migrate MIGRATE_CONFIRM=yes STUB_MIGRATE_FAIL=1
t "web DEFAULT (warn) keeps serving when schema not ready" 0 RAILWAY_PROCESS_TYPE=web STUB_SCHEMA_FAIL=1
t "web enforce refuses when schema not ready"  1 RAILWAY_PROCESS_TYPE=web STUB_SCHEMA_FAIL=1 SCHEMA_CHECK_MODE=enforce
t "web starts when schema ready"        0 RAILWAY_PROCESS_TYPE=web
t "web explicit warn mode continues"    0 RAILWAY_PROCESS_TYPE=web STUB_SCHEMA_FAIL=1 SCHEMA_CHECK_MODE=warn
t "worker DEFAULT (warn) keeps running" 0 RAILWAY_PROCESS_TYPE=worker STUB_SCHEMA_FAIL=1
t "web off mode skips the check"        0 RAILWAY_PROCESS_TYPE=web STUB_SCHEMA_FAIL=1 SCHEMA_CHECK_MODE=off; grep -q "ssi:schema-check" "$STUB_LOG" && { echo "FAIL off mode still ran the check"; fail=1; }
t "worker enforce refuses when not ready" 1 RAILWAY_PROCESS_TYPE=worker STUB_SCHEMA_FAIL=1 SCHEMA_CHECK_MODE=enforce
t "scheduler enforce refuses when not ready" 1 RAILWAY_PROCESS_TYPE=scheduler STUB_SCHEMA_FAIL=1 SCHEMA_CHECK_MODE=enforce
t "background enforce refuses when not ready" 1 RAILWAY_PROCESS_TYPE=background STUB_SCHEMA_FAIL=1 SCHEMA_CHECK_MODE=enforce
t "worker starts when schema ready"     0 RAILWAY_PROCESS_TYPE=worker
t "migrate never runs the schema gate"  0 RAILWAY_PROCESS_TYPE=migrate MIGRATE_CONFIRM=yes STUB_SCHEMA_FAIL=1; grep -q "ssi:schema-check" "$STUB_LOG" && { echo "FAIL migrate ran the gate"; fail=1; }
t "unknown role rejected"           1 RAILWAY_PROCESS_TYPE=bogus
rm -rf "$SHIM"; exit $fail
