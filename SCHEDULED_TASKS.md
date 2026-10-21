# Scheduled tasks — enabling the investment-maturity payout safely

## What was wrong
`app/Console/Kernel.php` defined two schedules, but **Laravel 12 never loads that class**, so neither ever ran.
Separately, `investments:update-maturity` (which **credits investor balances**) and the Admin **Complete** button
could each pay the same investment again (no lock, no status re-check), and Complete accepted cancelled or already-completed investments.

## What changed
| Item | Now |
|---|---|
| Schedules | Defined in `routes/console.php`; `Kernel.php` no longer defines them (so no double scheduling). |
| `gaming:sync-fixtures` | Every 30 min. **On** by default (`SCHEDULE_FIXTURE_SYNC=true`). |
| `investments:update-maturity` | Daily 00:00 UTC. **Off** by default (`SCHEDULE_INVESTMENT_MATURITY=false`). |
| Paying an investment | One routine (`InvestmentSettlementService`): transaction + row lock + status re-read. Only `active` can complete; profit credited exactly once. Used by both the button and the command. |
| Admin **Complete** | Returns **422** for a completed/cancelled investment ("nothing was credited"). |
| Command safety | Never pays an active investment that has **no end date**; new `--dry-run` reports without changing anything. |

The amount credited is unchanged: `expected_profit`.

## ⚠ The first real run pays the whole backlog
Because the command has never run, **every investment that matured while it wasn't running is still `active`** and will be settled together on the first run.

## Enable it (PowerShell, from the project folder)

```powershell
# 1. PREVIEW — changes nothing, pays nothing
php artisan investments:update-maturity --dry-run
#    shows: how many would complete, total profit, number of investors, how overdue the oldest is,
#    and how many active investments have no end date (those are never paid automatically)

# 2. Give those numbers to the Financial team and get written approval.

# 3. Turn it on (Railway: add the variable to the SCHEDULER service, then redeploy/restart)
#    SCHEDULE_INVESTMENT_MATURITY=true
php artisan config:clear

# 4. Confirm it is registered (it is always listed; the flag decides whether it fires)
php artisan schedule:list

# 5. After the first 00:00 UTC run, check the audit trail in the log
Select-String -Path storage\logs\*.log -Pattern "investment settlement"
```

Each settlement writes a log line: investment id, `source` (`admin` / `schedule`), amount credited, and the refusal reason if skipped.

**To stop it:** set `SCHEDULE_INVESTMENT_MATURITY=false` and run `php artisan config:clear`. Payments already made are **not** reversed automatically.

**Not covered here:** the payout writes no `FinancialAuditLog` entry (the old code didn't either); the log line above is the only trail. Worth adding if Finance wants an immutable record.

## Your full-suite run: 6 failures, none in the rank module
`php artisan test` → 270 passed, 6 failed. All 112 rank tests passed. The 6:

| Test | Cause | Mine? |
|---|---|---|
| `ExampleTest` — "Please provide a valid cache path" | The zip I gave you was missing `storage/framework/views` (and cache/sessions/logs) — my packaging dropped the placeholder files. **Fixed in the new zip.** This also hid the real error behind the two 500s below. | **Yes (packaging)** |
| `FinancialTeamTest` dashboard, "admin retains full admin access" (500) | `AdminDashboardController` uses MySQL-only `MONTH()`, which sqlite (used by tests) doesn't have; works on MySQL. | No — file unchanged |
| `FinancialMailingAddressTest` "guests are rejected" (403 not 401) | Test calls `actingAs(marvflow)` and then makes the "guest" request in the same test; `actingAs` persists, so it isn't a guest. | No — test and code unchanged |
| `FinancialMailingAddressTest` "admin kyc review reads the correct address" | Response shape in the test doesn't match what `AdminKycController::show` returns. | No — both unchanged |
| `FinancialTransactions…` "financial cannot use admin endpoints" (404 not 403) | Test never creates investment #1, so route binding 404s before the permission check. | No — test and code unchanged |

To confirm the last four are pre-existing, run `php artisan test` on your **original** project folder: they should fail there too.
