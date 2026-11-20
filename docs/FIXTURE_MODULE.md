# Football fixtures / prediction module — scope, dependencies, safe switches (D-21)

**Status:** outside every requirement document. Management decision **D5** (keep, disable by default, or remove) is **open**. Nothing has been removed or disabled by default.

**What it is.** A points-only prediction game: admins and a sync job import fixtures from football-data.org; investors predict outcomes for points. It never reads or writes a balance, wallet, deposit, withdrawal, investment or ledger row. `tests/Feature/Remediation/FixtureModuleIsolationTest.php` fails if any module file references those, or if a new fixture/prediction file appears that is not covered.

**Dependencies.** Environment variable `FOOTBALL_DATA_API_KEY` (read in `config/services.php`; never committed). Scheduler flag `SCHEDULE_FIXTURE_SYNC` (default `true`, `config/scheduling.php`). The sync runs every 30 minutes, `withoutOverlapping`, `onOneServer`, and only when the scheduler process runs (see `DEPLOY_WORKER_AND_SCHEDULER.md`). Tables: fixtures, fixture markets/predictions, prediction rounds/entries, competitions, fixture sync logs.

**Switching it off (no code change).** Set `SCHEDULE_FIXTURE_SYNC=false`: the scheduled sync stops; the pages and admin screens still work on existing data. Leaving `FOOTBALL_DATA_API_KEY` empty also stops imports (each attempt is logged as a failed sync).

**Not done, needs Management (D5).** Removing the module, hiding it from investor navigation, or changing the default to off. Any wagering or prize feature would need separate legal review and is outside this module as built.
