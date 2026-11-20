# Queue worker and scheduler — deployment guide (D-19, D-20, D-21)

Nothing in this repository starts a worker or a scheduler. This page says what must exist on the host, how to run them without duplicating work, and how to check the configuration safely. **Do not start either in production until the owner has approved it.**

## What depends on them
| Feature | Needs worker | Needs scheduler |
|---|---|---|
| Queued e-mail (mailing module, notices) | yes (when `QUEUE_CONNECTION` is not `sync`) | no |
| Rank alerts and "approaching next rank" notices | yes | yes (`ranks:evaluate`, `ranks:progress-notices`) |
| Scheduled campaigns, news publishing | no | yes (every minute) |
| Referral re-assessment (read-only toward money) | no | yes (hourly) |
| Registration maintenance | no | yes (hourly) |
| **Profit payout `investments:update-maturity`** | no | yes, **and OFF by default** |
| Football fixture sync | no | yes (every 30 min) |

## Run them as two separate processes
- Worker: `php artisan queue:work --tries=3 --backoff=30 --max-time=3600` (see `Procfile.example`). Several identical workers are safe: each job is claimed once from the queue.
- Scheduler: `php artisan schedule:work`, on **exactly one** process. Every scheduled task also uses `withoutOverlapping()` and `onOneServer()`, but `onOneServer()` only works if `CACHE_STORE` is shared (`database` or `redis`, not `file` or `array`).
- Use `QUEUE_CONNECTION=database` (the example default) and keep `failed_jobs` monitored.

## The payout job stays gated
`SCHEDULE_INVESTMENT_MATURITY` is false by default. Turning it on is not enough: a scheduled run pays only when `INVESTMENT_MATURITY_APPROVAL_REF` and `INVESTMENT_MATURITY_MAX_RUN_TOTAL` are set (Finance sign-off after reviewing `php artisan investments:update-maturity --dry-run`). A backlog above the approved ceiling pays nobody. See D-05 in the changelog.

## Football fixtures (decision D5 open)
Enabled by default; takes no stake and has no code path to wallets (checked by a test). Without `FOOTBALL_DATA_API_KEY` each run logs a failure. To switch it off without removing anything: `SCHEDULE_FIXTURE_SYNC=false` and `php artisan config:clear`.

## E-mail provider (decision D21 open)
`MAIL_MAILER=log` or `array` never delivers. Production needs a real provider (`MAIL_MAILER=brevo` / `MAILING_MAILER=brevo` with `BREVO_API_KEY` set on the host), a verified sending domain (SPF, DKIM, DMARC) and the department mailboxes registered as verified senders. None of that can be verified from this repository, and no credential or domain is supplied here. Delivery is proven only by a real test message.

## Safe check (read-only)
`php artisan ssi:check-deployment` prints variable **names and states only** (never values), sends nothing, starts nothing, changes nothing, and exits 1 if production would silently not deliver mail, drop queued jobs, run the payout job without an approval record, or run with debug on.
