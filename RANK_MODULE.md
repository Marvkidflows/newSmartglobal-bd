# Investor Rank & Badge Module — setup, operations, verification, demo

Ranks (Bronze → Silver → Gold → Platinum → Diamond → Elite) are **classification labels only**.
Nothing in this module writes balances, profits, withdrawals, deposits or payment priority. Evaluation only
*reads* investment/deposit/KYC data; its only writes are the two rank columns on `users`, its own tables, and notifications.

---

## 0. Verification status (read this first)

| Claim | How it was established | Status |
|---|---|---|
| Code is structurally valid (PHP braces/parens, JSX parses, imports/icons resolve) | Static checks | ✅ Verified |
| Every rank admin action is behind a backend Gate; routes sit inside `auth:sanctum` + `admin` | Mechanical audit of all 24 controller actions + route nesting | ✅ Verified by inspection |
| Rank code performs **no writes** to money tables (registry/evaluator/validator contain zero write calls; only `users.investor_rank_id` / `rank_changed_at` are written) | Static audit with positive control | ✅ Verified by inspection |
| `.env` is git-ignored, never committed, not in the packages; `.env.example` holds placeholders only | Git history + content checks (values not printed) | ✅ Verified |
| `afterCommit` jobs fire inside `RefreshDatabase` tests; the schedule is registered in a file Laravel 12.62 loads | Read from `vendor/` framework source | ✅ Verified from source |
| Migrations run on sqlite; **112 rank tests** (everything up to and including the proposed-criteria work) | **Executed by the project owner** (`php artisan test --filter=Rank`): **112 passed, 743 assertions, 0 failed.** | ✅ Verified |
| Settlement (14) and Management-decision (21) tests | **Written after the 112-test run — never executed** (as far as reported). | ⛔ **NOT VERIFIED — run `php artisan test --filter="InvestmentSettlement|RankDecisions"`** |
| USD valuation, reinvestment/cycle, duplicate, cancelled and early-completion rules: `RankCurrencyTest` (32 tests) plus updated assertions in `RankDecisionsTest` / `RankProposedCriteriaTest` | **Written — never executed.** Two earlier tests were deliberately rewritten because the rules changed (an end date that is merely shortened no longer makes a cycle count). | ⛔ **NOT VERIFIED — run `php artisan test --filter=Rank`** |
| Full suite (`php artisan test`) | Owner's run: 270 passed, **6 failed — none in the rank module**: 1 caused by my zip missing `storage/framework/views` (fixed), 5 look pre-existing (MySQL-only `MONTH()` in the dashboard under sqlite; tests with `actingAs` leakage / wrong response shape / missing fixture). | ⚠️ Pre-existing failures not fixed (see `SCHEDULED_TASKS.md`) |
| The rank commands are registered with the Laravel scheduler | `php artisan schedule:list` output from the owner's run lists `ranks:evaluate --scheduled` (02:00) and `--retry-notifications` (every 15 min) and **nothing else** | ✅ Verified (registration only — not that a scheduler process is running in production) |
| A queue worker is running in production | Cannot be observed from the repo | ⛔ **NOT VERIFIED — use §2.3** |
| The scheduler process is running in production and fires the 02:00 sweep | Cannot be observed from the repo | ⛔ **NOT VERIFIED — use §2.3** |
| The proposed thresholds are **not active anywhere** except where the dev-only loader was run | Search: no migration, seeder, entrypoint, schedule or route loads them; the loader refuses unless `APP_ENV` ∈ {local, testing, development} **and** the DB is sqlite/loopback | ✅ Verified by inspection (tests written, not run) |
| Rank thresholds are in USD, and a stored number is **never assumed** to be USD | Inspected deposits, withdrawals, investments and wallet-funded investing: each amount is a bare `decimal(15,2)` with **no currency, rate or USD value anywhere**. Implemented per-record **verified USD valuations** (§11) kept outside the financial tables; the engine uses only those. | ⛔ Code + tests written, not run |
| Production records' currency | Every existing investment/deposit has **no valuation**, so each counts as **$0** and is listed for review until the Financial team verifies it. | ⚠️ **Open — data work + Management sign-off required (§11)** |
| **Multi-currency update (§12):** supported-currency list, rate-sanity warnings, any-currency bulk verification, per-currency breakdown, dev demo data | Implemented in the existing valuation design (no rebuild, no migration). `tests/Feature/Rank/RankMultiCurrencyTest.php` (22 tests) **executed in the build sandbox (PHP 8.3, sqlite)**: pass. Full rank folder: 212 passed. | ✅ Verified in sandbox — **re-run on your machine (PHP 8.4)** |
| **Currency source of truth:** the platform stores NO currency, rate or converted amount on deposits / investments / withdrawals, and has no bank-transfer FX source | Inspected migrations, models, controllers, `MarketDataService` (USD-quoted display prices only) and investor forms (labelled "(USD)"). | ⚠️ **Open (§12.4): the investor-facing forms still label amounts USD and carry no currency field** |
| **Telegram-coordinated, multi-currency deposits (§13)** | New nullable deposit payment fields, staff "record payment" action, auto-valuation of staff-verified approved deposits. `tests/Feature/Rank/RankTelegramDepositTest.php` (36 tests, incl. the approval gate, retries, webhook security) executed in the build sandbox (PHP 8.3, sqlite): pass. Full suite 412 passed / 14 failed — the same 14 fail on the untouched RANKS_4 baseline (9 only because the sandbox lacks `ext-bcmath`). Not run: MySQL, `npm run build` (§13.6). | ✅ Verified in sandbox — **run `php artisan migrate` and re-run on your machine** |
| UI renders correctly at desktop/tablet/mobile | No browser / `vite build` available (installed `node_modules` is for another OS). CSS reviewed by inspection only. | ⛔ **NOT VERIFIED visually — manual check (§7 step 8)** |

---

## 1. Deploy / setup

```bash
php artisan migrate --force   # 7 additive migrations: 2026_10_15_100001‥100005, 2026_10_16_100001 (holds) and 2026_10_17_100001 (usd_valuations). No existing table is dropped or altered.
php artisan config:clear
```

The existing `docker-entrypoint.sh` already runs `migrate --force` for the `web` role.

### Environment flags (`config/ranks.php`)

| Env | Default | Meaning |
|---|---|---|
| `RANKS_ALLOW_MANUAL_OVERRIDE` | `false` | **Management decision.** `true` lets an Admin make a manual exception to the configured criteria (assign a rank the investor doesn't qualify for, **or** lower an investor who still qualifies), with a mandatory justification, recorded as `authorized_override`. `false` = the rules can never be bypassed. |
| `RANKS_CHUNK_SIZE` | `200` | Investors per queued chunk in a re-evaluation run |
| `RANKS_PREVIEW_LIMIT` | `2000` | Max investors the "Preview who qualifies" dry run examines |
| `RANKS_NOTIFICATION_STALE_MINUTES` | `10` | A `pending` alert older than this is treated as stuck and retried |
| `RANKS_REQUIRE_ACTIVE_ACCOUNT` | `true` | Prerequisite for **every** rank grant (automatic and manual): `users.status = active`. |
| `RANKS_REQUIRE_VERIFIED_KYC` | `true` | Prerequisite for every rank grant: KYC approved **and** `kyc_verified`. The platform's only verification mechanism; Management to confirm whether other compliance rules exist (§9). |
| `RANKS_CURRENCY_POLICY_SEPARATE_CONFIRMER` | `true` | The person who confirms the currency policy must differ from the person who reviewed it (separation of duties). **Management decision** — turn off only if a single person may do both. (The former the confirmed currency policy (Investor Ranks → Currency policy) flag was **removed**: money criteria are unlocked in the app under *Investor Ranks → Currency policy*, never by editing `.env`.) |
| `RANKS_DUPLICATE_WINDOW_SECONDS` | `60` | An investment created within this many seconds of an earlier one with the same investor, plan, amount and start date is treated as a double-submit duplicate and excluded until Financial confirms it. |
| `RANKS_RATE_DEVIATION_PERCENT` | `20` | A typed exchange rate that differs from the most recent recorded rate for the **same currency** by more than this percent is flagged; the person must tick an acknowledgement before it saves (§12). |
| `RANKS_RATE_DATE_TOLERANCE_DAYS` | `7` | A rate date more than this many days from the record's creation date is flagged the same way (§12). |
| `RANKS_ACCOUNT_PREFIX` / `RANKS_ACCOUNT_PAD` | `INVSTR-` / `5` | Account-ID display format (same as the Financial dashboard) |

---

## 2. Production background processing — exact configuration and how to verify it

### 2.1 What the repository configures

`docker-entrypoint.sh` selects the process by the `RAILWAY_PROCESS_TYPE` variable (default `web`):

| `RAILWAY_PROCESS_TYPE` | Runs | Needed for the rank module |
|---|---|---|
| `web` | migrate + PHP server | API |
| `worker` | `php artisan queue:work --sleep=3 --tries=3 --timeout=60 --max-time=3600` | **upgrades, re-evaluations, alerts** |
| `scheduler` | `php artisan schedule:work` | **daily sweep + alert retry** |
| `background` | both of the above with auto-restart loops, logging to `storage/logs/worker.log` and `storage/logs/scheduler.log` | both, on **one** service |

**Required:** at least one service running `worker` (or `background`) and one running `scheduler` (or `background`) — never `background` *and* a separate worker/scheduler (double processing).
Every rank job uses the **default queue** on the **default connection** (`QUEUE_CONNECTION=database`), which is exactly what `queue:work` consumes. Job-level `$tries`/`$timeout` take precedence over the worker flags.

**Recommended:** set `CACHE_STORE=database` in production. The schedule uses `onOneServer()`/`withoutOverlapping()` and the per-investor job uses `ShouldBeUnique`; these want a cache every container shares. (`file` works but is per-container, which only weakens de-duplication; correctness is still protected by database uniqueness constraints.)
Tables required: `jobs`, `failed_jobs`, `cache`, `cache_locks` — all created by existing migrations.

### 2.2 The Laravel 12 scheduler — verified finding about the existing code

This app runs Laravel **12.62.0** (`vendor/laravel/framework`). `Application::configure()->withKernels()` binds the console kernel to
`Illuminate\Foundation\Console\Kernel`; **nothing in the app or framework binds `App\Console\Kernel`**. Consequences:

* ✅ The rank schedule is defined in **`routes/console.php`** (loaded via `withRouting(commands: …)`), which the framework *does* load:
  * `ranks:evaluate --scheduled` — daily **02:00 UTC**
  * `ranks:evaluate --retry-notifications` — every **15 minutes**
* ✅ **Fixed (this was a pre-existing issue):** the two tasks in `app/Console/Kernel.php` —
  `investments:update-maturity` (daily 00:00 UTC) and `gaming:sync-fixtures` (every 30 min) — were **never registered**, so `schedule:work` never ran them.
  They are now defined in `routes/console.php`, gated by `config/scheduling.php`, and the dead copy in `Kernel.php` was emptied so they can't be scheduled twice.
  * `gaming:sync-fixtures` — **on by default** (`SCHEDULE_FIXTURE_SYNC=true`).
  * `investments:update-maturity` **pays profit into balances**, so it is **OFF by default** (`SCHEDULE_INVESTMENT_MATURITY=false`). Because it has never run, the first run would settle *every* already-matured investment at once. **Follow `SCHEDULED_TASKS.md` (dry-run → Financial approval → enable).**
  * `schedule:list` shows both tasks even while the flag is off; the flag is checked when the scheduler fires.
* ✅ **Also fixed:** `AdminInvestmentController::complete()` credited profit on every click and would complete cancelled investments. It now shares one locked, idempotent routine with the scheduled command (`InvestmentSettlementService`): only an **active** investment can be completed, once.

### 2.3 Verification commands (run these in the production environment)

```bash
# A. The scheduler knows about the rank sweep (expect both rank lines)
php artisan schedule:list
#   0 2 * * *      php artisan ranks:evaluate --scheduled
#   */15 * * * *   php artisan ranks:evaluate --retry-notifications
#   0 0 * * *      php artisan investments:update-maturity    (listed, but runs only if SCHEDULE_INVESTMENT_MATURITY=true)
#   0,30 * * * *   php artisan gaming:sync-fixtures           (runs unless SCHEDULE_FIXTURE_SYNC=false)

# B. Queue configuration the app actually sees
php artisan about --only=environment        # QUEUE/CACHE drivers
php artisan queue:failed                    # any dead jobs

# C. END-TO-END PROOF the worker is consuming rank jobs
php artisan tinker --execute="echo DB::table('jobs')->count();"     # note the number
php artisan ranks:evaluate                                          # queues a full run
#   wait ~1 minute, then:
php artisan tinker --execute="echo DB::table('jobs')->count();"     # must fall back to 0 (worker drained it)
#   and Admin → Investor Ranks → Activity: the run shows "Completed". If it sits at "Pending"/"Stalled", no worker is running.

# D. END-TO-END PROOF the scheduler process is alive
#   scheduler log shows "Running [php artisan …]" every minute; for the daily sweep:
grep -h "ranks:evaluate sweep" storage/logs/*.log     # one line per day at ~02:00 UTC
#   ("sweep skipped … no active criteria" is a valid, expected line until criteria are activated)
```

**Do not treat the worker or scheduler as operational until C and D succeed in the real environment.**

Manual commands: `php artisan ranks:evaluate` (full run) · `--user=123` (one investor, synchronous) · `--retry-notifications` · `--scheduled` (what the cron runs).

---

## 3. How it behaves

* **Source of truth:** `users.investor_rank_id` (null = Unranked) → `investor_ranks`. Not mass-assignable. Account ID `INVSTR-00012` is derived from `users.id`.
* **Criteria:** stored in `rank_criteria` + `rank_criterion_conditions`; one active rule set per rank; conditions combine as **ALL**, **ANY**, or **grouped** (all within a group, any one group). Management's **proposed** thresholds (§8) have been supplied and are available as **inactive drafts**; **no criterion is active in any environment** until Management gives final approval and the currency, reinvestment and other checks (§9–§11) are complete.
* **Fields** (extensible via `RankFieldRegistry::register()`): total invested, active investment amount, total approved deposits, investment count, completed investment count, longest investment duration, account age, KYC status, account verified, referral count.
* **Validation** rejects: unknown field, operator/type mismatch, missing/negative/non-numeric/fractional-for-integer values, bad enum option, `between` min > max, duplicate conditions, self-contradicting rules, identical rules on two ranks, higher rank easier than lower rank. Drafts can be saved inactive; conflicts block activation with an explanation.
* **Automatic upgrades (upgrade-only):** triggered per investor when an investment, approved deposit or KYC changes (queued after commit, de-duplicated); when Admin activates/edits criteria (queued chunked run over investors *below* that rank); and by the daily sweep. Highest qualifying rank wins. Concurrency: row lock + "only if strictly higher" + `UNIQUE(investor_id, sequence)`.
* **Prerequisites (compliance gates):** independent of the criteria, an investor must have an **active account** and be **verified**. While either fails: no automatic upgrade; a manual grant is an *exception* (override policy + justification, audited); and a ranked investor who later fails one is **flagged under Reviews — never downgraded automatically**. Re-evaluation is triggered when `status`, `kyc_status` or `kyc_verified` changes.
* **Alerts:** one Admin notification per upgrade (deterministic id ⇒ no duplicates), created only after commit; delivery state tracked in `rank_change_notifications`; failures are visible in **Activity** and retried automatically (every 15 min) or by the **Retry** button. Alerts contain no balances.
* **Downgrade review:** an investor who stops meeting their current rank's rule is **never** auto-downgraded; they appear in **Reviews** (one alert per unchanged shortfall). Admin **retains** (reason, audited) or **downgrades** (reason, audited).
* **A manual downgrade cannot be undone by the next evaluation:**
  * *Ordinary downgrade* (investor genuinely fails the rule): the engine can't restore it because they don't qualify. If they later genuinely qualify, the upgrade is legitimate.
  * *Exception downgrade* (investor **still qualifies** for ranks above the new one — requires `RANKS_ALLOW_MANUAL_OVERRIDE=true` and a justification; recorded `authorized_override` with the held ranks in its snapshot): the system places a **hold** (`investor_rank_holds`) on exactly those ranks. A hold entry is released only when eligibility legitimately changes — the investor stops meeting that rule, **or the rule's actual conditions change** (renaming or re-describing a rule does *not* release it) — or when an Admin assigns a rank manually, or an automatic upgrade supersedes it. The investor page shows an "Automatic upgrades are held" banner while a hold is active.
* **Audit:** `investor_rank_changes` and `rank_criteria_history` are append-only (model guard throws on update/delete; no HTTP route exists to edit/delete). Each automatic change stores the rule **as applied** plus the evaluation result; exception downgrades also store the held ranks. `investor_rank_holds`, `rank_downgrade_reviews` and `rank_change_notifications` are mutable *state* tables, not the audit record.

---

## 4. Screens

Sidebar → **Investor Ranks**: `/admin/ranks/investors` · `/criteria` · `/reviews` · `/currency` · `/audit` · `/activity` · `/admin/ranks/investors/:id` · bell → `/admin/notifications`.
Badges appear beside names on: investor header/dashboard/profile, **investor Task page header and Task History**, admin user list/detail, admin tasks, deposits, withdrawals, messages, financial investor list/detail.

## 5. API (Admin only unless noted; enforced by Gates `ranks.*`)

`/api/admin/ranks`: `GET /` · `GET /investors` · `GET /investors/{id}` (includes `hold`) · `POST /investors/{id}/assign` · `POST /investors/{id}/downgrade` ·
`GET /reviews` · `POST /reviews/{id}/retain` · `GET /audit` · `GET /alerts` · `POST /alerts/retry` ·
`GET /criteria/schema` · `GET /criteria` · `POST /criteria` · `PUT /criteria/{id}` · `POST /criteria/{id}/activate|deactivate` · `DELETE /criteria/{id}` · `POST /criteria/validate` · `POST /criteria/preview` · `GET /criteria/history` ·
`GET|POST /runs` · `GET /runs/{id}` · `POST /runs/{id}/resume`.
Currency verification (Financial team **and** Admin; gates `financial.view-records` / `financial.verify-currency`): `GET /api/financial/currency/summary` · `GET /api/financial/currency/records` · `POST /api/financial/currency/records/{investment|deposit}/{id}/verify` · `POST /api/financial/currency/bulk-usd`.
Admin notifications: `GET /api/admin/notifications`, `POST …/{id}/read`, `DELETE …/{id}`.
Investor (read-only): `GET /api/investor-investment/rank`; `GET /api/investor-investment/tasks` now also returns `investor{name, account_id, rank}`.

| Gate | Allows |
|---|---|
| `ranks.view` | overview, investors, reviews, alerts, criteria list/schema, runs (read) |
| `ranks.manage-criteria` | create/edit/activate/deactivate/remove/validate/preview criteria; start/resume runs |
| `ranks.assign` | manual assign/raise |
| `ranks.downgrade` | manual downgrade; retain on review |
| `ranks.override` | exception to configured criteria (also needs `RANKS_ALLOW_MANUAL_OVERRIDE=true`) |
| `ranks.view-audit` | rank audit log and criteria history |

All six currently resolve to `role === 'admin'`; `financial`, `marvflow_*` and investors get 403 (guests 401).

---

## 6. Running the tests

```bash
php artisan test --filter=Rank          # 165 tests in tests/Feature/Rank/ (threshold numbers there are fixture data only)
php artisan test                        # full regression
```
`phpunit.xml` uses in-memory sqlite. This module fixed one pre-existing blocker for that: `2026_09_20_111205_add_marvflow_roles_to_users_table.php` ran raw MySQL `ALTER TABLE` unguarded and would fail `migrate` on sqlite; it now has the same `getDriverName() !== 'mysql'` guard as its sibling migrations (no change on MySQL).
**Record the real pass/fail output before the demo.**

**Results so far (original 78 tests, run by the project owner): 71 passed, 7 failed.** Every failure was a wrong expectation in the test, now fixed:

| Test | Why it failed | Fix |
|---|---|---|
| snapshot still explains the decision… | creating + activating a rule makes it version 2, not 1 | compare with the version that was live |
| highest qualifying rank… / stale evaluation… | rules were activated *after* the investor had data, so each activation (correctly) upgraded them one rung at a time → 3 and 2 records | create the investor after the rules |
| admin receives one notification… | 2nd admin was created after the alert had already been delivered inline | create the admin first |
| delivery failure… | admins were suspended after delivery had already succeeded | suspend them before the upgrade |
| grouped logic… | missing `actingAs` (401) | authenticate |
| rank commands are registered… | `schedule:list` pads cron columns with extra spaces | regex tolerates whitespace |

**Behaviour worth knowing (correct, but surprising):** activating a rule re-evaluates *existing* investors immediately. Bringing several ranks live one at a time from Bronze upward moves an investor up one rung per activation (a record and an alert each). Activate **higher ranks first** (Elite → Bronze) and they jump straight to their true rank. The dev loader already does this; the dashboard now says so.

---

## 7. Demonstration checklist

Prereqs: worker + scheduler verified (§2.3 C/D); at least 3 test investors with different investment totals **whose records have been verified in USD (§11)**; `RANKS_ALLOW_MANUAL_OVERRIDE` decided; Management's proposed thresholds loaded as drafts (§8) — final approval still pending.

**Before starting — baseline (for step 7):** for the demo investors note `balance` (Financial → Investors → investor) and run
`SELECT id, balance FROM users WHERE id IN (…);` plus row counts of `deposits`, `withdrawals`, `balance_adjustments`, `financial_audit_logs`.

| # | Step | Where | Expected result | ☐ |
|---|---|---|---|---|
| 1 | **Create criteria** | Investor Ranks → *Rank Criteria* → Add criteria on a rank → build conditions → **Validate** → **Preview who qualifies** → **Save & activate** | Validation message; preview count; card shows *Active*; no code/SQL used. Try one invalid rule (e.g. Gold easier than Silver) → rejected with explanation. Repeat for ALL / ANY / grouped. | |
| 2 | **Automatic upgrade** | *Activity* tab, then investor page | A run appears (Pending → Running → **Completed**, never "Completed" early); a qualifying investor now has the new badge; a non-qualifying one is unchanged. | |
| 3 | **Admin notification** | Bell icon → `/admin/notifications` | **Exactly one** alert per upgrade: investor, account ID, from → to, rule met, "Automatic", link. Re-running *Re-evaluate all* creates **no** duplicate. | |
| 4 | **Audit record** | *Audit Log* tab (filter *Automatic*) and investor page → *Criteria applied* | Row: previous → new rank, **System (Rank Engine)**, reason, snapshot of the rule as applied, timestamp. Manual changes show the Admin's name. No edit/delete controls exist. | |
| 5 | **Update criteria → re-evaluate** | Edit a live rule to be easier → *Activity* | New run; newly-qualifying investors upgraded + one alert each; *Audit Log → Criteria changes* shows who/when/before→after. | |
| 6 | **Downgrade review** | Edit a rule to be stricter → *Reviews* tab → investor page | Affected investors **keep their rank** and appear under *Reviews* with the unmet conditions (one alert, not repeated). **Retain** one (reason) and **Downgrade** another (reason) → both audited. Run *Re-evaluate all* → the downgraded investor is **not** restored. (If override is enabled: downgrade an investor who still qualifies → "Automatic upgrades are held" banner, and re-evaluation still doesn't restore them.) | |
| 7 | **Balances unchanged** | Repeat the baseline queries/screens | Identical balances and table counts after steps 1–6. | |
| 8 | **Badges everywhere** | Log in as an investor and as admin; check at ≥1280px, ~768px and ~375px widths | Badge beside the name on: investor header, dashboard, profile, **Task page header & Task History**; admin users list/detail, tasks, deposits, withdrawals, messages; financial investors list/detail. Long names truncate and the badge never wraps off-screen. After an upgrade the investor's badge updates within ~1 min or on window focus **without logging out**. | |

**Proposed-criteria walk-through (development environment only):** run `php artisan ranks:load-proposed` → review the six drafts under *Rank Criteria* → activate → create/choose investors at the boundaries ($999 vs $1,000 with 2 cycles; $49,999 vs $50,000 with 12 cycles) → confirm the ranks; suspend one ranked investor and un-verify another → confirm they are **flagged under Reviews, not downgraded**.

Also show: a failed-alert scenario (Activity → *Retry failed alerts*), and that a non-admin gets 403 on `/api/admin/ranks/*`.

---

## 8. Proposed Management criteria — development only

SSI Management has **supplied** these thresholds as a **proposal**; **final approval is still pending**, so they are not active anywhere. They are kept as data in `config/ranks_proposed.php`; nothing reads that file except the loader below.

| Rank | Investment amount (proposed band) | Min. completed cycles | Loaded as |
|---|---|---|---|
| Bronze | $100 – $999 | 1 | amount ≥ 100 **AND** cycles ≥ 1 |
| Silver | $1,000 – $4,999 | 2 | amount ≥ 1,000 **AND** cycles ≥ 2 |
| Gold | $5,000 – $9,999 | 3 | amount ≥ 5,000 **AND** cycles ≥ 3 |
| Platinum | $10,000 – $19,999 | 5 | amount ≥ 10,000 **AND** cycles ≥ 5 |
| Diamond | $20,000 – $49,999 | 8 | amount ≥ 20,000 **AND** cycles ≥ 8 |
| Elite | $50,000 and above | 12 | amount ≥ 50,000 **AND** cycles ≥ 12 |

```bash
php artisan ranks:load-proposed              # six INACTIVE drafts (review them in the dashboard first)
php artisan ranks:load-proposed --activate   # create + activate (re-evaluates investors; needs a queue worker)
php artisan ranks:load-proposed --admin=you@ssi.com   # record a specific Admin as author
```

* **Refuses to run** unless `APP_ENV` is `local`, `testing` or `development` **and** the default DB is sqlite or a loopback/container host (`127.0.0.1`, `localhost`, `host.docker.internal`, `mysql`, `db`). The second check exists because `APP_ENV=local` is common on a machine whose `.env` still points at a shared/remote database.
* Goes through the same validated, versioned, audited service as the dashboard (author recorded in the criteria history). Re-running is safe (existing rules are skipped).
* Activates **top-down (Elite → Bronze)** so an investor already in the system jumps straight to the highest rank they qualify for — one audit record and one alert — instead of climbing rung by rung.
* **Production:** the loader is not run by migrations, deploys, the scheduler or any route. In production the approved criteria are entered by an Admin in **Investor Ranks → Rank Criteria**; until then every rank has no active criteria and nobody is ranked automatically.

### How the criteria are configured in the Admin Dashboard (no code, no SQL)

Investor Ranks → **Rank Criteria** → pick a rank → **Add criteria** (or **Edit**):

1. Name it; choose how conditions combine (**all** / **any** / **custom groups**).
2. Add conditions from validated dropdowns, e.g. **Total invested amount** · *is at least* · `1000` and **Completed investment cycles** · *is at least* · `2`. (Use *is between* if a hard upper limit is wanted, e.g. `100 – 999`.)
3. **Validate** (explains conflicts, e.g. a higher rank easier than a lower one) → **Preview who qualifies** → **Save & activate** (or save inactive).
4. Editing a live rule, or activating one, queues re-evaluation; qualifying investors are upgraded, nobody is downgraded. **Deactivate** stops a rule without changing anyone's rank. Every change is recorded with who/when/before→after.

The page also shows the eligibility **prerequisites** (active + verified) that apply to every rank.

---

## 9. Definitions used, assumptions, and what is still open

| Term | Implemented as |
|---|---|
| Ranking currency | **USD**, fixed — what Management's thresholds mean. |
| Investment amount | The **cumulative verified-USD value** of the investor's non-cancelled, non-duplicate investment records (active + completed). |
| Verified USD value | A valuation (§11) whose snapshot of the record's amount **still equals** the record's current amount. A USD record counts 1:1; any other currency counts as *amount × the recorded rate* (rate, source and date are mandatory). Never assumed from what the UI displays. |
| Completed cycle | One investment **record**, counted once, with status `completed`, amount **> 0**, that has genuinely ended: **both** its recorded end date **and** its plan-scheduled end (start date + plan duration) have been reached. |
| Reinvestment / wallet-funded | **No marker exists and the funding source proves nothing, so none is used.** Any separate, genuine investment record is its own record (and, once completed, its own cycle) whether it was funded from a deposit or the wallet and whether or not it follows an earlier completed one. Editing, extending or updating an investment changes the *same* row, so it can never add a cycle. |
| Early Admin completion | Does **not** count until the scheduled end date. An Admin shortening the countdown (the countdown tools can reduce/set/override end dates) does not bring the scheduled end forward. No exception exists unless Management approves one. The completion/payout code is not touched by rank evaluation. |
| Cancelled investment | Excluded from amount, cycles and counts. |
| Duplicate record | Same investor + plan + amount + start date as an **earlier** non-cancelled record and created within 60 s of it. Excluded from amount, cycles and counts, and flagged, until Financial confirms it is a separate genuine investment. |
| Active account / Verified | `users.status = active` / `kyc_status = approved` and `kyc_verified = true` — the only compliance requirements. |
| Thresholds | Minimums only; the highest qualifying rank wins; no upper limit unless Management asks (an Admin can add one with "is between"). |

**Open items (need Management or Financial — none has been guessed):**

1. **Existing data is unverified.** Every current investment and deposit has no valuation, so it counts as **$0** and sits in the Currency queue. Money criteria cannot go live until Financial has worked the queue (§11) and Management signs off (the confirmed currency policy (Investor Ranks → Currency policy)).
2. **Currency of the wallet and of plan limits is not recorded anywhere.** Wallet-funded investments are handled by the same per-record verification, so Financial's statement of each record's currency is what counts.
3. **Pre-existing behaviour worth Financial's confirmation (not changed by this work):** approving a deposit **credits the wallet and also creates an investment of the same amount**, with no link back to the deposit and no wallet deduction; the investor can then invest wallet balance again. So "total invested" can exceed money actually paid in. If that is not intended it affects what the thresholds mean.
4. **Duplicate rule** (same investor/plan/amount/start date within 60 s) is a safeguard for the wallet-funded path, which has no lock or idempotency key (also pre-existing). Please confirm it is acceptable; `RANKS_DUPLICATE_WINDOW_SECONDS` adjusts it.
5. **Scheduled-end rule** relies on the plan's duration. If an Admin later edits a plan's duration, the scheduled end of existing records follows it.
6. **Final approval** of the thresholds and of activation. Other compliance rules: none added. Hard rank bands: none.

**Existing-system risks found (not changed here):** the unguarded wallet-funded investing path above (no transaction/lock), and the deposit double-effect in item 3. The earlier `complete()` double-credit and unscheduled maturity task were fixed in a separate, requested change (`SCHEDULED_TASKS.md`).

---

## 10. Management decisions — how each is implemented and verified

| Decision | Implementation | Status |
|---|---|---|
| Ranking currency is **USD**; never assume a stored number is USD; use verified USD, or a recorded rate with the original preserved; flag unknown/missing-rate records; do not activate affected criteria until verified | `usd_valuations` (append-only; original amount + currency + rate + source + date + computed USD + who). Engine uses **only** verified values; unknown/stale records add $0 and are listed in the Currency queue; money criteria are locked behind the confirmed currency policy (Investor Ranks → Currency policy). | Code + tests written, **not run** |
| A new investment counts as a separate cycle only with its own record; wallet-funded and post-completion reinvestments may count; edits/extensions add nothing; exclude cancelled and duplicates; one investment counted once | Ledger counts each record once; funding source ignored; no reinvestment marker invented; cancelled and duplicate-looking records excluded; one valuation (the newest) per record. | Code + tests written, **not run** |
| Early Admin completion does not count until the end date; completed + positive amount + end reached; no exception; do not modify financial completion/payout | Both recorded and plan-scheduled end must have passed. `complete()` / maturity logic untouched by this work. | Code + tests written, **not run** |
| Amount = cumulative non-cancelled; corrections via the audited Financial process | Only the audited `correctInvestment` edits an amount; a correction makes the valuation **stale** and returns the record to review. | Code + tests |
| Active account + approved KYC only | `RankEligibility` — exactly two requirements. | Tests (previously run: pass) |
| Minimum thresholds, highest wins, no upper limit, editable in the dashboard | Proposed rules load as `>=` conditions; no code change needed to edit. | Tests (previously run: pass) |
| Manual overrides off by default; reason + audit when enabled | `RANKS_ALLOW_MANUAL_OVERRIDE=false`; reason + justification + audit when enabled. | Tests (previously run: pass) |

Standing requirements: all six proposed rules stay **inactive** (the loader creates drafts, and `--activate` is dev-only **and** blocked by the currency gate); no automatic downgrades (flagged under *Reviews*); Admin alert + audit on every automatic upgrade; one `RankBadge` component for all badges; rank code never writes money tables (a test scans for it and a static scan was run over every rank service, including the new ones).

---

## 11. Currency verification workflow

**Who / where:** Financial team (Financial → *Currency (USD)*) and Admin (Investor Ranks → *Currency (USD)*). Both use the same screen and API.

1. Open the queue (default view: **Needs review**). Records appear when their currency is **unknown**, when their **amount changed** after verification (e.g. a Financial correction), or when they **look like duplicates**.
2. For each record click **Verify…**: pick the currency of the stored amount from the list (ISO-4217 + configured extras such as USDT; a typo is refused) and write a note of what you checked.
   * **USD** → counts 1:1.
   * **Any other currency** → also enter the **exchange rate to USD, its source, and its date** (all required; the date cannot be in the future). The USD value is computed by the server; the original amount and currency are preserved.
3. For many records in the **same currency**, tick them and use **Verify … selected**: choose the currency and, if it is not USD, record one rate, source and date. Each record still gets its own valuation row, its own USD figure and its own audit entry (needs a written basis; recorded under your name). Records in different currencies are verified in separate batches.
4. A duplicate-looking record stays excluded until you tick **"I have confirmed this is a separate, genuine investment"** while verifying it.
5. Every verification is append-only, written to the **Financial audit log**, and **re-evaluates that investor**. It **never edits the investment, deposit or any balance.**

**Roll-out order (production):** `php artisan migrate --force` → Financial works the queue (or Management decides to accept the exclusions) → in the app, **Investor Ranks → Currency policy**: the Financial Team records its review, then an Administrator (a different person by default) confirms it for Management → an Admin previews, then activates the approved criteria in the dashboard (**highest rank first**). Until the policy is confirmed, production has no active money criteria. See §12.6.

### To use the proposed rules in a development database
```powershell
php artisan ranks:load-proposed                       # six inactive drafts — always allowed
# to ACTIVATE in dev: in the app, have a Financial user record the review and a DIFFERENT admin confirm it (Investor Ranks → Currency policy)
php artisan config:clear
php artisan ranks:load-proposed --activate
```
Money criteria only count records with a verified USD value, so in dev also verify some records (Currency tab) or the totals will be $0. The currency policy is never confirmed automatically — not by migrations, seeders or commands.

---

## 12. Multi-currency investments (bank transfers and investments in any currency)

### 12.1 Rule
Management's thresholds are **unchanged and are in USD** (Bronze 100 + 1 cycle · Silver 1,000 + 2 · Gold 5,000 + 3 · Platinum 10,000 + 5 · Diamond 20,000 + 8 · Elite 50,000 + 12). They are never multiplied, reduced or rewritten per currency. An investment in another currency is **converted to USD with a recorded rate and then compared**. A raw amount is never compared across currencies; an amount whose currency has not been verified counts as **$0** (never as USD).

### 12.2 What the platform already provided, and what it did not
* Provided (reused, not rebuilt): the per-record **verified valuation** — currency, original amount, exchange rate, rate source, rate date, USD value, who verified it — append-only, mirrored into the Financial audit log, re-evaluating the investor on every change (§11).
* **Not provided anywhere in the platform:** a currency or exchange-rate column on deposits, bank transfers, investments or withdrawals, or any bank-transfer FX source. (`MarketDataService` only supplies USD-quoted display prices.) **No rate is invented or fetched automatically**: a person records the rate, its source and its date.

### 12.3 What this update added
| Area | Change |
|---|---|
| Currency validation | `CurrencyCatalog`: only real ISO-4217 codes (from PHP intl) plus `config('ranks.extra_currencies')` (USDT, USDC, BTC, ETH) are accepted — a typo such as `NRG` is refused. Listing a code never implies a rate: every non-USD code still needs a rate, source and date. `GET /financial/currency/supported` feeds the picker. |
| Rate handling | `GET /financial/currency/rates?currency=NGN` lists rates already recorded (reuse only — the person still confirms them). A rate that differs from the latest recorded rate for that currency by more than `RANKS_RATE_DEVIATION_PERCENT`, or whose date is more than `RANKS_RATE_DATE_TOLERANCE_DAYS` from the record's date, is **blocked until acknowledged** (`acknowledge_rate_warnings`); the acknowledged warnings are stored in the audit entry. |
| Bulk | `POST /financial/currency/bulk-verify` — one currency + one recorded rate for many records (per-record valuation + audit). `bulk-usd` is kept. |
| Visibility | Investor page: "Verified investments by currency" (records, original total, recorded rate(s), USD counted). |
| Dev demo | `php artisan ranks:demo-data [--evaluate]` — see 12.5. |

### 12.4 Unresolved currency limitations (need Management / product decisions)
1. **The investor-facing deposit and invest forms still label amounts "(USD)" and store no currency.** Changing them alters live financial flows and was out of scope. Until Management decides (add a currency + rate at capture, or keep the post-hoc verification), Financial verifies each record after the fact.
2. **No authoritative FX source exists.** Management should name the rate source (e.g. central-bank reference rate on the transfer date) and who may record it.
3. **Existing production records are unverified → count as $0** until Financial works the queue (§11). Nothing is guessed.
4. Rounding is half-up to the cent per record; the sum of per-record USD values is what is compared with a threshold.
5. A deposit approved by Admin creates an investment with no link back to the deposit, so a deposit's currency is not inherited by its investment; each record is verified on its own.
6. Rank evaluation remains classification-only: it writes only `users.investor_rank_id` / `rank_changed_at`, the rank audit tables and notifications (asserted in `test_multi_currency_ranking_never_changes_money_or_records`).

### 12.5 Development demonstration (proposed criteria active, controlled test data)
```powershell
# LOCAL .env only:  APP_ENV=local   (then confirm the currency policy in the app: Financial review + a different Admin's confirmation)
php artisan config:clear
php artisan ranks:load-proposed --activate     # six PROPOSED, unapproved rules
php artisan ranks:demo-data --evaluate         # 7 labelled demo investors + a table of expected vs reached rank
```
`ranks:demo-data` refuses outside `local`/`testing`/`development` and on a non-local database, only creates `demo.rank.N@demo.invalid` investors, labels every rate **"DEMO FIXTURE — test data, not a real exchange rate"**, never opens the currency gate and never activates criteria. Cases: NGN 6,000,000 raw = $3,900 → Silver; GBP exact $5,000 → Gold; EUR $4,967.99 → Silver; JPY $100.50 → Bronze; $60,500 with 11 cycles → Diamond (not Elite); 12 cycles with no verified currency → no rank; mixed USD/EUR/GHS $51,600 + 12 cycles → Elite.

---

## 13. Telegram-coordinated deposits and multi-currency funding

### 13.1 How the existing flow works (inspected, unchanged in principle)
Investor starts a deposit (plan + amount) → the app creates a **pending** deposit with a reference (`DEP-…`), notifies SSI staff and returns the **Telegram representative's link** → the investor arranges and makes the payment through that representative (bank transfer in any currency, bitcoin, ethereum, usdt — the methods SSI already supports) → the investor confirms the method / uploads proof → **Admin or Financial** approves (same endpoint and permission as before): the wallet is credited with `amount` and an investment of `amount` is opened. **Telegram is only the conversation**: the webhook code only forwards text to staff and can approve nothing (asserted by a test).

### 13.2 What was missing
A deposit stored one bare `amount` and a method — **no record of what was actually paid, in which currency, at what rate, or with which bank reference**, and the approval credited that `amount` whatever currency it really was.

### 13.3 What was added (additive; no existing column or rule changed)
| Item | Detail |
|---|---|
| Migration `2026_10_20_100001_add_payment_details_to_deposits` | Nullable: `paid_amount`, `paid_currency`, `fx_rate`, `rate_source`, `rate_date`, `approved_amount`, `external_reference`, `funding_channel` (telegram/app/other), `payment_note`, `payment_verified_by/at`; and `investment_accounts.source_deposit_id`. Run `php artisan migrate`. |
| Investor (optional, at *confirm*) | May declare `paid_amount`, `paid_currency`, `external_reference`, `funding_channel`. Stored as **unverified**; never changes `amount`, status or rank. |
| Staff action `POST /admin|financial/deposits/{id}/payment-details` | Same people/permission as approve (`financial.act-on-transactions` for Financial). Only while **pending / on hold**. Records amount paid, a supported currency, rate + source + date (non-USD), payer reference, channel, and the **approved USD amount** (default = paid × rate; staff may set another figure). Sets `amount` to that approved figure (the old figure is kept in the Financial audit entry). **Does not approve anything and never touches a balance.** Rate sanity warnings and duplicate-reference refusal as in §12. |
| On approval (existing action) | **Refused unless the payment is verified (§13.5).** When allowed, credits the staff-approved USD `amount` exactly once, opens the linked investment, and records the USD value of the deposit and the investment citing the recorded paid amount / rate / source. Records created before payment recording existed stay **unverified → $0 → in the Currency review queue**. |
| Screens | Admin → Deposits and Financial → Transactions: "Payment…/Record payment…" modal, plus the paid amount/currency/verified state in the list and detail. Investor page: "Approved deposits by funding currency". |

### 13.4 Rank rules (unchanged thresholds)
Only **approved** deposits/investments with a **verified USD value** count. Pending, on-hold and rejected deposits never count (a rejected deposit opens no investment); cancelled investments and duplicates are excluded; completed-cycle rules, highest-rank logic, upgrade-only behaviour, audit, notifications and Admin criteria are untouched. Rank evaluation still writes only rank data.

### 13.5 Final hardening (RANKS_7)
| Item | Detail |
|---|---|
| **Approval safety gate** | `POST …/deposits/{id}/approve` now **refuses (422, `code: payment_unverified`)** unless the actual payment is verified: amount paid, currency, and — for non-USD — a positive rate, its source and date, and the payment date, with the approved USD figure following from them (tolerance `RANKS_APPROVED_AMOUNT_TOLERANCE_PERCENT`, default 1 %; larger differences need an explicit, audited acknowledgement). Re-checked at approval, so a figure changed after verification blocks it. Nothing is ever assumed to be USD. |
| **Existing USD flow preserved** | The approver may send `confirm_usd: true` + a `reason` to attest, under their name, that the amount was received in USD (recorded as a verified USD payment). Refused if the investor declared another currency. The Admin/Financial screens open the payment form when approval is refused. |
| **No double credit / investment** | Approval is row-locked and status-checked (a retry gets 422); the investment is linked by `source_deposit_id`, guarded in code **and by a UNIQUE index**. |
| **Telegram webhook** | Moved to the public `POST /api/telegram/webhook` (it previously sat inside the authenticated investor group, so Telegram could not reach it). It requires Telegram's `X-Telegram-Bot-Api-Secret-Token` and is **disabled (503) unless `TELEGRAM_WEBHOOK_SECRET` (16+ chars) is set**; wrong token → 403. It only forwards text to staff and cannot approve or change anything. To enable: set `TELEGRAM_WEBHOOK_SECRET`, then register the webhook with the same value as `secret_token` (Telegram Bot API `setWebhook`). **Not done here — needs your authorization.** |
| **Investor form** | "(USD)" removed from the deposit form; step-by-step explanation (message the Telegram representative → pay as instructed → submit proof → staff verify → credited only after approval; Telegram does not process payments); the investor states the **amount and currency actually paid** (never assumed). |
| Migration | `2026_10_21_100001_harden_deposit_payment_verification` (adds `payment_date`, `amount_difference_acknowledged`, the unique index). |

**Operational change to announce:** pending deposits can no longer be approved with one click unless their payment is recorded first (or USD is explicitly confirmed with a reason).

### 13.6 Remaining limitations
1. No authoritative FX source: staff type the rate and its source; nothing is fetched. Management should name one.
2. Old deposits/investments have no payment record → unverified → $0 for ranking until Financial verifies them (§11).
3. Other wallet screens (invest-from-wallet, withdrawals, plans) are USD-denominated by design; only the deposit flow was made multi-currency.
4. The webhook must be registered with Telegram (secret token) before it receives anything.
5. Not run here: the real `npm run build` (registry unreachable) and MySQL (not installed). See the final report for what was run instead.


### 12.6 Currency policy (unlocking money-based criteria)

Money-based criteria (`total_invested`, `active_investment_amount`, `total_approved_deposits`) can be saved as drafts at any time, but can only be **activated** while a currency policy is confirmed. The confirmation lives in the database (`rank_currency_policies`), is versioned, attributable and audited — it is **not** an `.env` setting.

| Step | Who | Where | Gate |
|---|---|---|---|
| 1. Review the rules and the review queue | Financial Team or Admin | Financial → Currency verification, or Investor Ranks → Currency policy | `financial.review-currency-policy` |
| 2. Confirm on behalf of Management, choosing how unverified records are treated | **Admin only**, by default a different person from the reviewer | Investor Ranks → Currency policy | `ranks.confirm-currency-policy` |
| Withdraw the confirmation | Admin only | same | `ranks.confirm-currency-policy` |

* **Bound to the rules.** The confirmation stores a hash of the rules and the config values they depend on (rate-deviation %, rate-date tolerance, approved-amount tolerance, duplicate window, extra currency codes). If any of them change, the policy shows as *Changed since confirmed* and money criteria lock until re-reviewed and re-confirmed.
* **Unverified records** — Management chooses at confirmation: *accept that they count as 0* (the exact number must be typed in as acknowledgement) or *require the queue to be cleared* before any money criterion is activated (the activation error then lists the counts and the first affected record ids).
* **Errors.** An activation refused for currency reasons returns HTTP 422 with `errors.conflicts` (as before) plus `currency_gate.blockers[]` = `{type, message, action{label,path}, details}` — types `currency_unconfirmed`, `currency_pending`, `currency_stale`, `currency_records`.
* **Audit.** `currency_policy.reviewed / confirmed / revoked` go to `financial_audit_logs`; criteria `activated` history rows for money criteria record `currency_policy_version` / `currency_policy_hash`; activation and deactivation were already logged in `rank_criteria_history`.
* **Never touched:** investments, deposits, withdrawals, balances, profits and existing investor ranks. Withdrawing the policy does not demote anyone; the engine simply ignores money criteria again.
