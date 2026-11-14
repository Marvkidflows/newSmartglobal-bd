#!/usr/bin/env bash
# LOCATION: scripts/verify-all.sh   — run on a machine with PHP 8.4 (+ext intl, mbstring, curl, pdo_mysql, bcmath), Composer and (for step 7) MySQL 8.
#
# Runs every backend verification step in order, writes a full log per step to verification-logs/<timestamp>/ and a SUMMARY.txt.
# Honesty rules: a step that cannot run is recorded as SKIPPED — never PASS. The script exits NON-ZERO if any step FAILED or any REQUIRED step was
# SKIPPED, so a green exit means every step genuinely ran and passed. It uses an isolated test database and an ephemeral APP_KEY; it never reads or
# writes .env and never touches a real database (DB_DATABASE must contain "test").
#
# NOTE: step 09 proves the migration preserves records only for the (mostly empty) TEST database. To prove it on realistic data run, on a COPY of the
# production database (never production itself): ssi:financial-snapshot --out=before.json --hash → migrate → ssi:financial-snapshot --compare=before.json --strict.
# MySQL (step 7): export DB_HOST DB_PORT DB_DATABASE (must contain "test") DB_USERNAME DB_PASSWORD   before running, or use docker-compose.verify.yml.
set -u
cd "$(dirname "$0")/.."
TS="$(date +%Y%m%d-%H%M%S)"; LOG="verification-logs/$TS"; mkdir -p "$LOG"
SUMMARY="$LOG/SUMMARY.txt"; FAILS=0; SKIPS=0
export LOG_DIR="$PWD/$LOG"
export APP_ENV=testing APP_KEY="${APP_KEY:-base64:$(head -c 32 /dev/urandom | base64)}"

record() { printf '%-4s %-46s %s\n' "$1" "$2" "${3:-}" | tee -a "$SUMMARY"; }
step() {  # step <id> <title> <required: yes|no> <command...>
  local id="$1" title="$2" req="$3"; shift 3
  echo ">>> $id $title" >&2
  if "$@" >"$LOG/$id.log" 2>&1; then record PASS "$id $title" "(log: $LOG/$id.log)"
  else record FAIL "$id $title" "(exit $?; see $LOG/$id.log)"; FAILS=$((FAILS+1)); fi
}
skip() { record SKIP "$1 $2" "REASON: $3"; SKIPS=$((SKIPS+1)); echo "SKIPPED: $3" > "$LOG/$1.log"; }

{ echo "verify-all.sh  $TS"; echo "host: $(uname -a)"; php -v 2>&1 | head -1; composer --version 2>&1 | head -1; mysql --version 2>&1 | head -1; } | tee "$LOG/00-environment.txt" >> "$SUMMARY"
command -v php >/dev/null || { record FAIL "00 php present" "php not found — install PHP 8.4"; exit 2; }
command -v composer >/dev/null || { record FAIL "00 composer present" "composer not found"; exit 2; }

step 01-composer-install "composer install" yes composer install --no-interaction --prefer-dist
step 02-php-lint "php -l on every PHP file" yes bash -c 'find app routes config database tests bootstrap -name "*.php" -print0 | xargs -0 -n1 php -l | grep -v "^No syntax errors" ; test ${PIPESTATUS[1]} -eq 0'
# NOTE: step 02 passes only if php -l printed nothing but "No syntax errors" lines for all files.
step 03-entrypoint-shell "scripts/test-entrypoint.sh" yes sh scripts/test-entrypoint.sh
step 03b-defaults-in-force "ssi:defaults-report --check (documented safe defaults are in force)" yes php artisan ssi:defaults-report --check
step 04-sqlite-suite "php artisan test (SQLite, full default suite)" yes php artisan test --log-junit "$LOG/04-junit.xml"
step 05-strict-mass-assignment "full suite with SSI_STRICT_MASS_ASSIGNMENT=1 (R2)" yes env SSI_STRICT_MASS_ASSIGNMENT=1 php artisan test --log-junit "$LOG/05-junit-strict.xml"
step 06-correction-tests "focused correction/remediation tests" yes php artisan test --filter='Remediation|SanitizerPayload|EmailImageEmbedder|RichTextSanitizer|AdminTaskTest|FinancialTransactionsAndCommunications|StaffAccountStatus|MarvflowRestrictions'

if [ -n "${DB_DATABASE:-}" ] && [ -n "${DB_USERNAME:-}" ] && case "$DB_DATABASE" in *test*) true;; *) false;; esac; then
  export SSI_MYSQL_CONCURRENCY=1 DB_CONNECTION=mysql
  step 07-mysql-concurrency "MySQL concurrency + locking suite" yes php artisan test --configuration=phpunit.mysql.xml --testsuite=MySQL --log-junit "$LOG/07-junit-mysql.xml"
  step 08-mysql-migrate-and-schema-check "migrate fresh on the TEST db, then ssi:schema-check" yes bash -c 'php artisan migrate:fresh --force && php artisan ssi:schema-check'
  step 09-mysql-migration-rollback "snapshot → migration down()/up() → schema-check → strict snapshot compare" yes bash -c 'php artisan ssi:financial-snapshot --out="$LOG_DIR/snapshot-before.json" --hash --force && php artisan migrate:rollback --step=1 --force && php artisan migrate --force && php artisan ssi:schema-check && php artisan ssi:financial-snapshot --compare="$LOG_DIR/snapshot-before.json" --strict'
else
  skip 07-mysql-concurrency "MySQL concurrency + locking suite" "no MySQL test database configured (set DB_HOST/DB_DATABASE (name containing 'test')/DB_USERNAME/DB_PASSWORD)"
  skip 08-mysql-migrate-and-schema-check "migrate fresh + ssi:schema-check" "no MySQL test database configured"
  skip 09-mysql-migration-rollback "migration down()/up()" "no MySQL test database configured"
fi

{ echo; echo "FAILED steps: $FAILS    SKIPPED steps: $SKIPS"; } | tee -a "$SUMMARY"
if [ "$FAILS" -gt 0 ] || [ "$SKIPS" -gt 0 ]; then echo "RESULT: NOT VERIFIED (failures or skipped steps). Do not claim readiness." | tee -a "$SUMMARY"; exit 1; fi
echo "RESULT: all steps ran and passed. Attach $LOG/ to the report. (Frontend + browser checks are separate: scripts/verify-frontend.sh, docs/correction-pass/BROWSER_TEST_SCRIPT.md)" | tee -a "$SUMMARY"
