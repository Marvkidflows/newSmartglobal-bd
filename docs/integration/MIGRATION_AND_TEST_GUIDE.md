# Migration and test guide (Windows PowerShell / XAMPP)

**Nothing in this guide has been executed by the author** (no PHP, Composer, MySQL or browser was available). Every command is a procedure for you to run. Report any failure with its full output.

## 0. Hard rules
1. **Never run `migrate:fresh`, `migrate:refresh`, `db:wipe`, `db:seed` or any test against your original or production database.** `migrate:fresh` is only for a database you just created empty for testing (Scenario 1) — it destroys every table in the database named in `.env`.
2. Before any Laravel command, check which database it will use: `php artisan tinker --execute="echo config('database.connections.mysql.database');"`
3. The automated suite (§3) needs **no database server**: `phpunit.xml` forces in-memory SQLite and fake mail/queue/HTTP.
4. The MySQL concurrency suites (§6) refuse to run unless the environment variable `DB_DATABASE` contains the word `test`, and they **wipe that database** (`migrate:fresh` per test).
5. Keep `.env` private. Create it from `.env.example`; never commit it or zip it.

## 1. Requirements
PHP ^8.2 with `intl mbstring curl pdo_mysql pdo_sqlite sqlite3 dom xml xmlwriter libxml tokenizer fileinfo openssl json ctype gd`; Composer 2; Node 18/20/22+; MySQL/MariaDB (InnoDB) for §4–§6.
```powershell
php -v
php -m | Select-String -Pattern '^(dom|intl|mbstring|curl|pdo_sqlite|sqlite3|pdo_mysql|xml|xmlwriter|libxml|tokenizer|fileinfo|openssl|json|ctype|gd)$'
```
In `php.ini` set `memory_limit = 1G` (the concurrency tests start separate PHP processes that ignore `php -d`), enable the extensions above, restart Apache/MySQL.

> **Memory / runner:** the suite exceeds PHP's default 128 MB CLI limit. This is the command that works on your machine: `php -d memory_limit=-1 vendor\bin\pest` (add a path or `--filter=` to narrow it). `php artisan test` needs the same `-d memory_limit=-1`.
> **Ledger:** `FINANCIAL_LEDGER_ENABLED` is false by default, so no wallet-ledger rows are written unless you enable it (tests that assert ledger rows turn it on themselves).
> **First local run:** see `FIRST_LOCAL_TEST_RUN_ANALYSIS.md` for the failures found and fixed.

## 2. Install and static checks (no database)
```powershell
mkdir C:\ssi-int; Expand-Archive .\SSI_Integrated_Backend.zip -DestinationPath C:\ssi-int
cd C:\ssi-int\ssi-backend-integrated
composer install                       # if pdo_mysql is missing: composer install --ignore-platform-req=ext-pdo_mysql
Copy-Item .env.example .env
php artisan key:generate
php artisan config:clear

# 2.1 PHP syntax of every project file (prints only problems)
Get-ChildItem -Recurse -Include *.php app,routes,database,config,tests | ForEach-Object { $o = php -l $_.FullName 2>&1; if ($o -notmatch 'No syntax errors') { $o } }

# 2.2 routes load; duplicate route NAMES make route:cache fail (this is the real duplicate check)
php artisan route:list --path=registration
php artisan route:list --path=mailing
php artisan route:list --path=news
php artisan route:list --path=referral
php artisan route:list --path=withdrawal
php artisan route:cache
php artisan route:clear

# 2.3 scheduler: expect referrals:scan --no-backfill (hourly at :17), registration:maintain, mailing:dispatch-scheduled, news:publish-scheduled, ranks:progress-notices
php artisan schedule:list
```

## 3. Automated tests (in-memory SQLite — safe, no database server)
Run in this order; run each command separately and keep the output.
```powershell
# 3.1 your ORIGINAL suites first (baseline: they must behave as before the integration)
php -d memory_limit=-1 artisan test tests/Feature/Rank
php -d memory_limit=-1 artisan test tests/Feature/Remediation
php -d memory_limit=-1 artisan test tests/Unit

# 3.2 Task 2 — Registration (includes the registration-resume security tests)
php artisan test tests/Feature/Registration
php artisan test tests/Feature/PrivacyPolicyRegistrationTest.php

# 3.3 Task 3 — Mailing, Task 4 — News & Media
php artisan test tests/Feature/Mailing
php artisan test tests/Feature/News

# 3.4 Task 5 — run the two directories SEPARATELY
php artisan test tests/Unit/Task5
php artisan test tests/Feature/Task5

# 3.5 gap-closure and final-remediation tests (written, never run)
php artisan test tests/Feature/Registration/IntegrationGapClosureTest.php
php artisan test tests/Feature/Registration/RegistrationEnumerationTest.php          # verify-first registration, enumeration, abuse limits
php artisan test tests/Feature/Task5/WithdrawalFinancialSecurityTest.php            # withdrawal financial review (single database)
php artisan test --filter=test_a_credit_at_or_above_the_dual_approval_threshold_is_refused_and_moves_nothing
php artisan test --filter=test_fee_reversal_waiver_and_refund_are_disabled_by_default

# 3.6 everything together
php -d memory_limit=-1 artisan test
```
Tests that inject faults or use `Mail::shouldReceive`, model events, SQLite savepoints or `travel()` are the most likely to show a first-run mismatch. Send me the output; do not weaken assertions.

## 4. SCENARIO 1 — a brand-new EMPTY test database (the only place `migrate:fresh` is allowed)
```powershell
# MySQL (as root):  CREATE DATABASE ssi_integration_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
# Edit .env:  DB_CONNECTION=mysql  DB_HOST=127.0.0.1  DB_DATABASE=ssi_integration_test  DB_USERNAME=<test user>  DB_PASSWORD=<...>
#             MAIL_MAILER=log   QUEUE_CONNECTION=sync        (nothing is sent to real people)
php artisan tinker --execute="echo config('database.connections.mysql.database');"     # must print ssi_integration_test
php artisan migrate:fresh --force            # DESTRUCTIVE — empty test database only
php artisan migrate:status                   # nothing "Pending"
php artisan ssi:schema-check                 # read-only: fails if tables/columns the code needs are missing
php artisan registration:create-staff reg1@example.test "Reg Member" --role=registration_member
php artisan registration:create-staff reglead@example.test "Reg Lead" --role=registration_lead
php artisan registration:settings            # prints the Task 2 defaults that await approval
php artisan serve
```
Optional throwaway-SQLite migration check (no MySQL needed):
```powershell
New-Item database\integration-check.sqlite -ItemType File
$env:DB_CONNECTION="sqlite"; $env:DB_DATABASE="$PWD\database\integration-check.sqlite"
php artisan migrate:fresh --force
Remove-Item Env:DB_CONNECTION; Remove-Item Env:DB_DATABASE; Remove-Item database\integration-check.sqlite
```
SQLite limitation: the `users.role` enum migrations are MySQL-only (they skip other drivers); SQLite relies on the edited `0001_01_01_000000_create_users_table.php` that lists every role.

## 5. SCENARIO 2 — a RESTORED COPY of existing SSI data (never `migrate:fresh`)
```powershell
# 1. Take a fresh dump of the real database from the real server (read-only operation) and record its checksum:
#      mysqldump --single-transaction --routines <real_db> > ssi-real.sql ; Get-FileHash ssi-real.sql
# 2. Restore it into a NEW database whose name contains "test", e.g. ssi_copy_test (CREATE DATABASE first), then point .env at that copy only.
php artisan tinker --execute="echo config('database.connections.mysql.database');"     # must print ssi_copy_test
php artisan migrate --pretend > migrate-plan.sql    # read it: only the 10 new migrations should appear
php artisan migrate --force                         # applies ONLY the pending migrations; existing data is kept
php artisan migrate:status
php artisan ssi:schema-check
php artisan referrals:scan --no-backfill            # safe re-assessment only (see §8)
```
Compare row counts of `users`, `deposits`, `withdrawals`, `investment_accounts`, `balance_adjustments` before and after; `users.balance` totals must be identical. The Task 5 migration only **adds nullable columns** to `withdrawals` and copies `amount` into `gross_amount`/`net_amount` (fee 0) and `WD-<id>` into `transaction_reference`; `amount` itself is never changed.

## 6. MySQL concurrency suites — run each SEPARATELY, on an isolated test database only
Prepare once (a dedicated empty database whose name contains `test`; these tests wipe it):
```powershell
# MySQL (as root):  CREATE DATABASE ssi_concurrency_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
$env:SSI_MYSQL_CONCURRENCY = "1"
$env:DB_CONNECTION = "mysql"; $env:DB_HOST = "127.0.0.1"; $env:DB_PORT = "3306"
$env:DB_DATABASE = "ssi_concurrency_test"; $env:DB_USERNAME = "<test user>"; $env:DB_PASSWORD = "<...>"
```
Then, one at a time (each takes many minutes; the original runbook `docs/MYSQL_VERIFICATION_RUNBOOK.md` estimates 20–40 min for the whole folder):
```powershell
# 6.1 balance deduction vs withdrawal approval, approvals, investments (ORIGINAL suite — do not skip)
vendor\bin\pest --configuration=phpunit.mysql.xml tests\MySql\MySqlApprovalConcurrencyTest.php

# 6.2 the single test behind the old recorded failure
vendor\bin\pest --configuration=phpunit.mysql.xml tests\MySql\MySqlApprovalConcurrencyTest.php --filter=cannot_overdraw

# 6.2b REPEATED rounds: deduction vs approval, double approval, approval vs rejection, idempotency, fee once (new)
vendor\bin\pest --configuration=phpunit.mysql.xml tests\MySql\MySqlWithdrawalRoundsTest.php

# 6.3 Task 5: referral credit once, fee collected once, idempotent submission
vendor\bin\pest --configuration=phpunit.mysql.xml tests\MySql\MySqlTask5ConcurrencyTest.php

# 6.4 other original MySQL concurrency tests
vendor\bin\pest --configuration=phpunit.mysql.xml tests\MySql\MySqlConcurrencyTest.php
```
Afterwards clear the variables: `Remove-Item Env:SSI_MYSQL_CONCURRENCY,Env:DB_CONNECTION,Env:DB_HOST,Env:DB_PORT,Env:DB_DATABASE,Env:DB_USERNAME,Env:DB_PASSWORD`.
If a test is **skipped** it printed why (usually `DB_DATABASE` lacks "test" or `SSI_MYSQL_CONCURRENCY` is unset) — a skip is not a pass. See `MYSQL_CONCURRENCY_INVESTIGATION.md` for what the old failure most likely was, what each test distinguishes, and **exactly what result closes the issue** (the issue is NOT closed until you run these).

## 7. SCENARIO 3 — production deployment (only after §2–§6 pass and Management signs off)
Use your **existing** procedure (`docs/MIGRATION_DEPLOYMENT.md`, `docs/correction-pass/`, `docs/ROLLBACK_PROCEDURE.md`): `web` never migrates; run the one-shot `migrate` role with `MIGRATE_CONFIRM=yes`, first `MIGRATE_PRETEND=true`, then the real run, requiring exit 0.
1. Full backup/snapshot of the production database and the `storage` volume; record the checksum; rehearse the restore.
2. Rehearse the whole sequence on a restored copy (Scenario 2) first.
3. `php artisan migrate --pretend` — review; then `php artisan migrate --force --isolated`. **Never** `migrate:fresh`, `db:wipe` or seeders.
4. `php artisan ssi:schema-check`; set `NEWS_MEDIA_DISK` to a persistent private disk; configure Brevo senders and webhook secret (see `ENVIRONMENT_AND_EXTERNAL_SERVICES.md`); start the queue worker and the scheduler.
5. The controlled script `database/controlled/F02_unique_source_deposit.sql` is **not** a migration and stays un-run until Management and Financial approve it in writing (its first step must return zero rows).
6. Keep `WITHDRAWAL_FEE_ADJUSTMENTS_ENABLED=false`; do not approve a referral or fee policy until Management decisions are recorded.

## 8. Referral scan
Scheduled automatically in `routes/console.php`: `referrals:scan --no-backfill`, hourly at minute 17, non-overlapping. It only re-assesses **existing Pending** rewards; it never approves, credits, rejects or touches a balance, and records an audit event only when eligibility or flags change. The one-off backfill of historic referrals is **manual** and not scheduled:
```powershell
php artisan referrals:scan                 # re-assess + backfill historic referrals as Pending rows (decide first)
php artisan referrals:scan --no-backfill   # re-assess only
```

## 9. Frontend
```powershell
mkdir C:\ssi-int\fe; Expand-Archive .\SSI_Integrated_Frontend.zip -DestinationPath C:\ssi-int\fe
cd C:\ssi-int\fe\ssi-frontend-integrated
Copy-Item .env.example .env            # set VITE_API_URL=http://localhost:8000  (no trailing /api)
npm ci
npm test
npm run build
npm run dev
```

## 10. Manual walkthrough (isolated database, `MAIL_MAILER=log`)
1. **Registration → KYC:** register at `/register`: stage 1 now only emails a code (with `MAIL_MAILER=log` read the 6-digit code in `storage/logs/laravel.log`), enter it, and the account is created; then stages 2–4. As `reg1@example.test` review, request information, approve. That account must get 403 from `/api/financial/*`, `/api/admin/*`, `/api/investor/*`.
2. **Delegated staff:** grant a Registration member `public.view` → the Registration menu shows **Mailing**, no investor tab, no Permissions tab; `GET /api/mailing/investor/overview` → 403. A Financial user with no grants sees no Mailing / News & Media links.
3. **Mailing separation:** public subscribers cannot be selected for investor campaigns; unsubscribe links work.
4. **News:** `/news` (guest), a signed-in non-investor, and a verified investor at `/investor/news`; old announcement links open under `/investor/announcements/...`.
5. **Referral:** register through a referral link → reward stays **Pending**; nothing payable until an Administrator approves a policy with ≥ 1 criterion.
6. **Withdrawal fee:** with no policy, fee is 0.00; approve a test fee policy (test DB only) and confirm gross / fee / net are shown before confirming.
7. **Rank & Benefits:** `/admin/ranks`, `/investor/rank` unchanged.

## 11. Rollback risks (by migration)
| Migration | `down()` | Risk |
|---|---|---|
| `2026_11_06_100001_add_registration_roles_to_users_table` | MySQL: shrinks the role enum | **Refuses** if registration accounts exist; otherwise safe |
| `2026_11_06_100002_create_registration_tracking_tables`, `2026_11_07_100001_create_registration_resume_challenges_table`, `2026_11_08_100001_add_delivery_status_…`, `2026_11_10_100001_create_registration_email_challenges_table` | drops only its own tables/column | Loses registration history/visit records and in-flight verification codes (harmless: visitors request a new code) |
| `2026_11_07_100001_create_task5_referral_and_withdrawal_fee_tables` | drops its tables and the added `withdrawals` columns | **Loses referral rewards, ledgers, fee policy and per-withdrawal fee snapshots** once used — export first; do not roll back after any fee was collected |
| `2026_11_08_100001_create_mailing_core_tables`, `…100002_create_mailing_consent_tables`, `…100003_create_mailing_campaign_tables` | drop their tables | Loses consent/unsubscribe ledgers (compliance records) — export first |
| `2026_11_09_100001_create_news_media_tables` | drops its tables | Loses articles/media records (files in storage remain) |
Do **not** use `migrate:rollback --step=N` on a shared database: the newest N migrations may include original ones. Prefer restoring the pre-migration backup.

## 12. What changed in this guide for the final-remediation round
New env names `REGISTRATION_STAGE1_ATTEMPTS_PER_HOUR`, `REGISTRATION_VERIFY_ATTEMPTS_PER_HOUR`, `REGISTRATION_MIN_RESPONSE_MS` (tests force the last to 0 in `phpunit.xml`); migration #10; the registration journey is now stage 1 → emailed code → verify; new test files `RegistrationEnumerationTest`, `WithdrawalFinancialSecurityTest`, `MySqlWithdrawalRoundsTest`; `php artisan registration:maintain` also prunes verification codes older than 7 days.
