# Database migration and rollback — correction pass
**Authorisation:** running a migration requires a recorded deployment approval. `MIGRATE_CONFIRM=yes` is an accident guard, **not** authorisation. Never run against production from this document without that approval. Nothing here was run against any database.

## 1. What the migration does
`database/migrations/2026_11_04_100001_harden_financial_approval_workflow.php` — **additive only**:
- `financial_approval_requests`: new **nullable** columns `target_user_id`(idx), `amount_usd`, `authorization_reference`, `open_key`(unique), `expires_at`(idx), `cancelled_by`, `cancelled_at`, `cancel_reason`, `execution_started_at`, `execution_attempts` (default 0), `last_error`.
- New table `financial_approval_events` (append-only audit history).
It reads/updates/deletes no existing row; no balance, investment or ledger table is touched. It **requires** `2026_11_03_100001_create_controlled_financial_operations` to have run first.
Existing rows: `open_key` is NULL for rows that already exist, so they are not part of duplicate detection; legacy rows left in `approved` are handled by `ssi:approvals-maintain` (§4).

## 2. Pre-flight (isolated copy first)
1. Restore last night's backup into an **isolated** MySQL instance. 2. `RAILWAY_PROCESS_TYPE=migrate MIGRATE_CONFIRM=yes MIGRATE_PRETEND=true sh docker-entrypoint.sh` (prints SQL, applies nothing). 3. Apply for real **on the copy**, run `php artisan ssi:schema-check` (must exit 0), run the MySQL suite there. 4. Record timings; the ALTER on `financial_approval_requests` is small (low-volume table).
Check before staging: `SELECT status, COUNT(*) FROM financial_approval_requests GROUP BY status;` — any `approved`/`executing` rows are legacy-stuck requests (see §4).

## 3. Apply (staging, after approval)
**The site is live — nothing here requires taking it offline.** The migration is additive and backward compatible (old code ignores the new nullable columns and the new table), so the safe order is: migrate first, then roll the code. The startup schema gate defaults to `SCHEMA_CHECK_MODE=warn` (logs `[schema] NOT READY` and keeps serving) so a mis-ordered deploy cannot cause an outage; until the migration has run, only the new approval features (corrections, approvals inbox) return errors, existing flows are unaffected. Switch to `SCHEMA_CHECK_MODE=enforce` deliberately, after the migrate job has succeeded, if you want a release to refuse to serve on an unready schema:
1. Take a backup. 2. Run the **migrate** process type (`RAILWAY_PROCESS_TYPE=migrate`, `MIGRATE_CONFIRM=yes`) — one instance only (`--isolated` needs a shared cache store; otherwise guarantee a single runner). 3. Verify `php artisan ssi:schema-check`. 4. Only then roll out web, worker, scheduler.

## 4. Stuck / legacy approval rows
`php artisan ssi:approvals-maintain --dry-run` lists them; without `--dry-run` it reconciles: records found ⇒ marked `executed`; provably not executed ⇒ `failed` (retryable by a *different* authorised person); undeterminable (bulk/profit-reversal/correction) ⇒ `failed` with "MANUAL VERIFICATION REQUIRED". It never re-executes anything and never moves money. Not scheduled by default.

## 5. Rollback
| Situation | Action |
|---|---|
| Migration applied, app not yet rolled out, want to revert | `php artisan migrate:rollback --step=1 --force` (runs `down()`: drops `financial_approval_events` and the new columns/indexes). Safe **only** while no request has been created with the new columns; otherwise the data in them is lost — take a backup first |
| New app code misbehaves after rollout | Redeploy the previous release image **without** rolling the schema back: the previous code ignores the new nullable columns/table. This is the preferred rollback |
| Previous release + schema gate | The *previous* release has no schema gate, so it starts regardless |
| Pending requests created by the new code | They stay in `financial_approval_requests`; the previous code can still read them (status strings are unchanged apart from `failed/cancelled/expired/executing`, which it does not know) — treat as **needing manual review** before rolling back |
| Data restore | Backup taken in §3 step 1. Never restore over production without Finance sign-off |
No rollback path touches balances, investments, deposits or ledgers because this migration never does.

## 6. Proving existing financial records are preserved (read-only)
Statically verified: the `up()` of every migration in this release (`2026_11_02_100001`, `2026_11_03_100001`, `2026_11_04_100001`) is **purely additive** — no `update`, `delete`, `insert`, raw SQL, `truncate` or `change()`; every `drop…` is inside `down()`. No seeder or data backfill ships in this release.
Runtime proof is provided by the read-only command `php artisan ssi:financial-snapshot` (selects only; writes just its own `0600` JSON file; never overwrites without `--force`; refuses the web root):
```
php artisan ssi:financial-snapshot --out=/secure/before.json --hash        # BEFORE the migration (live site is fine; --hash is heavier: prefer a copy / off-peak)
# … run the migrate job, then deploy …
php artisan ssi:financial-snapshot --compare=/secure/before.json           # AFTER — live-site mode
php artisan ssi:financial-snapshot --compare=/secure/before.json --strict  # AFTER — on an isolated, quiet COPY of the database
```
Live-site semantics: investors keep transacting, so only rows with `id ≤` the snapshot's recorded max id are re-measured. **Append-only tables** (balance adjustments, financial audit log, wallet ledger, principal movements, transactions, investment allocations, USD valuations, FX snapshots, rank-change history, profit-adjustment items): **any** difference ⇒ exit 1. **Mutable tables** (users, investment accounts, deposits, withdrawals, profit adjustments, approval requests): differences are reported (expected on a live DB) and only fail with `--strict`. Compare counts, decimal sums and a per-table row hash. **Not executed** (no PHP); tests are written in `FinancialSnapshotTest`. Run it on a **copy of production** first; never point destructive tooling at production.
Note: `down()` of `2026_11_04` intentionally discards the values in the columns it added (they exist only for the new workflow), so a strict compare of `financial_approval_requests` after a rollback+re-apply is expected to differ if such rows exist.
