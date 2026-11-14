# MySQL verification runbook (Phase 8)

STATUS: **NOT RUN.** Nothing below has been executed by the author. SQLite is not valid evidence for exact-money behaviour or concurrency; this is the step that decides whether the module can go to isolated staging.

## What a static check already found (7 Nov 2026)
Reading all 110 migrations for the failures SQLite hides: **one**, `profit_adjustment_items_profit_adjustment_id_investment_id_unique` (65 characters; MySQL allows 64), would have aborted `migrate:fresh`. It now has an explicit name (`pai_adjustment_investment_unique`). No other index, foreign-key, composite-key size, text/JSON default or unguarded raw-SQL problem was found. Every 2026-10 and 2026-11 migration has a `down()`. This does not replace the run below; it only removes one known blocker. The new `MigrationMysqlCompatibilityTest` keeps it from coming back.

## Prerequisites
* **MySQL 8**, not MariaDB (`mysql --version`). PHP extension `pdo_mysql` enabled.
* `php.ini`: `memory_limit = 1G` (the concurrency tests start separate PHP processes that ignore `php -d`).
* A throw-away database whose name contains `test`. **Never** the development or production database: `migrate:fresh` deletes every table.
* No stray `php.exe` left running from an earlier attempt: `Get-Process php* | Stop-Process -Force`.

## Steps (PowerShell, in the backend folder)
```powershell
mysql -u YOUR_USER -p -e "CREATE DATABASE ssi_remediation_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

$env:DB_CONNECTION="mysql"; $env:DB_HOST="127.0.0.1"; $env:DB_PORT="3306"
$env:DB_DATABASE="ssi_remediation_test"; $env:DB_USERNAME="YOUR_USER"; $env:DB_PASSWORD="YOUR_PASSWORD"
$env:SSI_MYSQL_CONCURRENCY="1"
echo $env:DB_DATABASE          # MUST print ssi_remediation_test before you continue

php -d memory_limit=-1 artisan migrate:fresh --force        # 1. every migration on MySQL
php artisan ssi:schema-check                                # 2. the schema the code expects exists
php -d memory_limit=-1 artisan migrate:rollback --step=3    # 3. the newest migrations roll back...
php -d memory_limit=-1 artisan migrate --force              #    ...and apply again

php -d memory_limit=-1 vendor\bin\pest --configuration=phpunit.mysql.xml --testsuite=MySQL --testdox   # 4. concurrency (20 to 40 minutes, silent between tests)
php -d memory_limit=-1 vendor\bin\pest --filter="cent boundary"                                          # 5. the two tests SQLite skips
php -d memory_limit=-1 vendor\bin\pest tests\Feature\Rank                                                # 6. the rank suite on MySQL
```
Then close the PowerShell window so the `$env:` variables disappear.

## What to send back
For each numbered step: the summary line, and the first error block of anything that failed. Report MySQL as **NOT TESTED** anywhere these steps have not completed.

## Pass criteria for "ready for isolated staging"
Steps 1 to 3 finish with no error; step 4 and 5 have 0 failures; step 6 has 0 failures; the SQLite full suite is green after all current zips are applied.
