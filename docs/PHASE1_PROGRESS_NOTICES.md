# Phase 1: approaching-rank and next-rank notices (audit R-32, R-33)

STATUS: IMPLEMENTED, NOT EXECUTED (no PHP in the authoring environment). Run `php -d memory_limit=-1 vendor\bin\pest --filter=RankProgressNoticeTest`, then the whole `tests\Feature\Rank` folder (existing tests that count notifications were read, not run).

## What was added
| Item | Path |
|---|---|
| Settings | `config/ranks.php` -> `progress_notices` (env `RANKS_PROGRESS_NOTICES_ENABLED`, `RANKS_APPROACHING_MILESTONES` default `75,90`, `RANKS_APPROACHING_REARM_BUFFER` default 5) |
| Migration (additive, one new table) | `database/migrations/2026_11_06_100001_create_rank_progress_notices_table.php` |
| Model | `app/Models/RankProgressNotice.php` |
| Service | `app/Services/Rank/RankProgressNoticeService.php` (reads `InvestorRankProgress`, the dashboard's own calculation) |
| Queue job | `app/Jobs/SendRankProgressNoticesJob.php` |
| Command | `ranks:progress-notices [--sync] [--user=ID]` |
| Schedule | `routes/console.php`: daily 03:00 UTC |
| R-33 | `RankBenefitService::notifyNextRankEligibility` now covers every step up, not only Bronze to Silver |
| Tests | `tests/Feature/Rank/RankProgressNoticeTest.php` |

## Rules
* Progress = the LOWER of the money bar and the cycle bar. Deposits, referrals, unverified or stale records never count (they never reach the calculation).
* 100% is not a milestone: meeting the requirements is announced by the eligibility notice (R-33).
* Nothing is sent: while the next rank's criteria are inactive; for the highest rank; for unranked, suspended or revoked investors; when the master switch is off.
* One notice per (investor, next rank, milestone); a jump across several milestones sends one notice. Progress must fall more than the buffer below a milestone before it can notify again.
* Bronze to Silver eligibility notice stays behind the management-controlled benefit `bronze.next-rank-notice` (still pending approval). Every other step is a platform notice behind `ranks.progress_notices.enabled`.
* No rank, balance, review, return or incentive is read for writing or changed. The only writes are the notification and the bookkeeping row.

## Decision for Management
Confirm the default milestones (75, 90) and that the non-Bronze eligibility notices may be on by default. Set `RANKS_PROGRESS_NOTICES_ENABLED=false` to switch all of it off.
