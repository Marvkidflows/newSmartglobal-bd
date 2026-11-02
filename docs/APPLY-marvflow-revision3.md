# MarvFlow ↔ SSI messaging — cumulative package (revision 3)

> ## READ FIRST — verification status
> **This code has never been executed.** The build sandbox has no PHP and the uploaded `node_modules` holds Windows-only binaries, so:
> no PHP test, no real migration, no `npm run build`, nothing in a browser has been run by me. What I *did* run is listed in section 9
> (static checks, and the raw SQL executed on a real SQLite database). **Do not treat this as verified or production-ready until sections 4, 5 and 6 pass on your
> staging copy.** Nothing was deployed; no production database or file was touched.

## 1. What is in this package (cumulative)
It contains **every change** from your pristine upload, in one place — you do **not** need the earlier overlay packages:

| Layer | Contents |
|---|---|
| Original overlay 1 | MarvFlow → Admin/Financial messages: table, model, controllers, inbox pages, sanitizer, notification, 13 tests |
| Original overlay 2 | Professional email composer (font, size, colour, spacing, links…), "Message Admin" (`kind`), 14 + 9 tests |
| Two-way replies | `thread_id` / `direction` / `recipient_id`; replies from Admin/Financial and follow-ups from the owner; unread counts; bell click-through; sidebar badges; 20 tests |
| Continuous quick messages | consecutive quick messages stay in one conversation; "Start a new conversation"; 10 tests |
| Restricted accounts + pending delivery | only `active` accounts may use the messaging endpoints; Admin-only pending replies for restricted owners; 12 tests |

`backend/` and `frontend/` hold the **complete final version** of the 35 files that differ from your upload (19 backend, 16 frontend). I confirmed that every file shipped by the
two original overlays (34 file entries; some paths appear in both) is present (none missing); both original test files are included **unmodified**.
**Foreign keys are deliberately NOT added** (deferred as you decided). Login, tokens, the app-wide suspension policy, investor/staff/Dev-Team messaging and all financial logic are **untouched**.

## 2. Your six confirmations — answered against the evidence

| # | Requirement | Status | Evidence / caveat |
|---|---|---|---|
| 1 | ZIP contains everything (overlays, composer, replies, quick threading, restrictions) | **Confirmed** (file level) | Built from the byte-difference between your pristine uploads and the final tree; cumulative patches apply to your pristine upload and reproduce the final tree byte-for-byte; every original-overlay file present (34 entries, some shared). |
| 2 | Migration order correct, all apply together | **Confirmed by inspection and by running the equivalent SQL; `php artisan migrate` NOT run** | Four files `2026_10_26 → 10_27 → 10_28 → 10_29`, all after your last migration (`2026_10_25`). Each only references columns that exist at that point. The same DDL executed in that order on SQLite 3.45 (see `verification/`). Run `--pretend` first (section 3). |
| 3 | Pending replies unreachable to restricted users via any endpoint or notification | **Confirmed by code audit; PHP tests written, not run** | All 15 messaging routes carry `EnsureMessagingAccountActive` (403 before any code runs); the model is used in exactly 3 files; every owner-side query either filters on the owner's own rows or `pending_since IS NULL`; pending replies create **no** notification (`notified_count = 0`). *Residual, by design:* a restricted user can still call non-messaging endpoints such as the generic notifications list — it shows only alerts created **before** the restriction (subject only); none exist for pending replies. |
| 4 | Reactivation releases pending replies exactly once | **Confirmed by design + SQL execution; concurrency NOT testable here** | Release runs only on requests that already passed the active check, inside a transaction that locks the owner's row, updates only rows still pending, then alerts once per conversation. Sequential idempotence executed in SQL and covered by a PHP test (not run). *Caveats:* if the owner already has an **unread** alert for that same conversation, no second bell item is created (the badge still updates); and in a rare race where an Admin's save lands just after reactivation, that reply stays pending until the owner's next messaging request, then is released (never lost). |
| 5 | Existing conversations/messages remain compatible | **Confirmed for data (SQL executed); existing PHP tests NOT run** | The migrations only `ADD COLUMN` with NULL/defaults; legacy rows keep their data and become one-message conversations (`thread_id NULL`, `direction to_ssi`, `kind email`, not pending) — executed on SQLite. No existing test file was edited. *Behaviour that intentionally changes:* the SSI inbox lists one row per conversation; further quick messages join the latest quick conversation; suspended/frozen/deactivated/inactive accounts can no longer use these endpoints. |
| 6 | Rollback accounts for pending replies and history | **Confirmed in code and text** | `down()` of `10_29` refuses while any reply is pending; `down()` of `10_28` refuses while any SSI reply exists; both change nothing when refusing. Guard queries executed in SQL. Full procedure in section 7, including the data loss caused by rolling back `10_27` and `10_26`. |

## 3. Back up, apply, migrate (staging first)
### 3.0 Backup (the machine holding the real data)
```bash
cp -a /path/to/backend  /path/to/backend.bak-$(date +%F)
cp -a /path/to/frontend /path/to/frontend.bak-$(date +%F)     # or commit/tag the current state in git
mysqldump --single-transaction -u USER -p DBNAME > full-dump-$(date +%F).sql     # full dump is best; at minimum:
mysqldump --single-transaction -u USER -p DBNAME marvflow_messages notifications migrations users > msg-dump-$(date +%F).sql
```
Expected: two `.sql` files, non-empty. (`marvflow_messages` does not exist yet if no overlay was applied — then dump the others.)

### 3.1 Apply the code — choose ONE path
**Path A — your project is the original upload (no earlier overlay applied):**
```bash
cd /path/to/backend  && git apply --check /path/to/patches/A-onto-your-original-project/backend.patch  && git apply /path/to/patches/A-onto-your-original-project/backend.patch
cd /path/to/frontend && git apply --check /path/to/patches/A-onto-your-original-project/frontend.patch && git apply /path/to/patches/A-onto-your-original-project/frontend.patch
```
**Path B — the two earlier overlays are already applied (paths in these patches start with `a/backend/` or `a/frontend/`, hence `-p2`):**
```bash
cd /path/to/backend  && git apply -p2 --check /path/to/patches/B-onto-project-with-both-earlier-overlays/backend.patch  && git apply -p2 /path/to/patches/B-onto-project-with-both-earlier-overlays/backend.patch
cd /path/to/frontend && git apply -p2 --check /path/to/patches/B-onto-project-with-both-earlier-overlays/frontend.patch && git apply -p2 /path/to/patches/B-onto-project-with-both-earlier-overlays/frontend.patch
```
**Expected:** `--check` prints nothing and exits 0; the apply prints nothing. **If `--check` fails** your files differ from the snapshot I used: stop, nothing was changed; compare by hand against the complete files in `backend/` and `frontend/`
(do **not** blindly overwrite shared files such as `routes/api.php`, `App.jsx`, the three layouts, `api.js`, the three notification pages). `MarvflowLayout.jsx` uses **CRLF** line endings — keep them.
Without git: `patch -p1 --dry-run < file` (Path A) or `patch -p2 --dry-run < file` (Path B), then without `--dry-run`.

### 3.2 Migrate
```bash
php artisan migrate:status        # expect the four 2026_10_26…29 files as "Pending" (fewer if overlays were already migrated)
php artisan migrate --pretend     # expect ONLY: create table marvflow_messages (if new) and ALTER TABLE marvflow_messages ADD COLUMN / CREATE INDEX statements
php artisan migrate --force       # on STAGING after the backup
php artisan migrate:status        # expect all four "Ran"
```
**Verify the result (MySQL):**
```sql
SELECT COUNT(*) FROM marvflow_messages;      -- must equal the number you noted before migrating
SELECT COUNT(*) FROM marvflow_messages
 WHERE thread_id IS NOT NULL OR recipient_id IS NOT NULL OR pending_since IS NOT NULL OR direction <> 'to_ssi';   -- expect 0 immediately after migrating
SHOW CREATE TABLE marvflow_messages;         -- expect columns thread_id, direction, recipient_id, pending_since, kind; ONLY the two original foreign keys (sender_id, read_by) — none added
```

## 4. Backend tests (staging/local only)
Prerequisites: PHP ≥ 8.2, `composer install`. First **confirm the tests cannot touch real data**:
```bash
grep -n "DB_CONNECTION\|DB_DATABASE" phpunit.xml      # expect: sqlite  and  :memory:  (both force="true")
```
Run these, in this order. "Expected" = the result I predict from reading the code; none has been executed.
| Command | Expected |
|---|---|
| `php artisan test tests/Unit/RichTextSanitizerTest.php` | 9 tests, 0 failures |
| `php artisan test tests/Feature/MarvflowMessagesTest.php` | 13 tests, 0 failures |
| `php artisan test tests/Feature/MarvflowDirectMessagesTest.php` | 14 tests, 0 failures |
| `php artisan test tests/Feature/MarvflowRepliesTest.php` | 20 tests, 0 failures |
| `php artisan test tests/Feature/MarvflowQuickThreadingTest.php` | 10 tests, 0 failures |
| `php artisan test tests/Feature/MarvflowRestrictionsTest.php` | 12 tests, 0 failures |
| `php artisan test` (whole suite) | **287 test methods** by my count (209 pre-existing + 78 from the overlays and this work), 0 failures / 0 errors |

Notes: (a) PHPUnit may report a different total if some tests use attributes instead of `test_` method names. (b) **Run the full suite once on your untouched project first** so you know its baseline — I cannot tell whether any pre-existing test already fails.
(c) Expect small first-run defects in the new tests/code; send me the failure output and I will fix them in the isolated copy.
`php artisan test --filter=Marvflow` is *not* recommended (it can match other test names); use the file paths above.

## 5. Frontend build
```bash
cd /path/to/frontend && npm ci && npm run build
```
**Expected:** `npm ci` finishes (deprecation/audit warnings are normal); `npm run build` ends with `built in …s`, exit code 0, and a populated `dist/`. A "chunks larger than 500 kB" warning is acceptable. **Any error** (especially an unresolved import or a JSX syntax error) must be reported to me.
(The package has no lint or test scripts — only `dev`, `build`, `preview`.)

## 6. Manual checks in the running staging app (desktop and ~375 px phone width)
Create users on staging: two MarvFlow members (A, B), one Admin, one Financial. To restrict a user on **staging only**: `UPDATE users SET status='suspended' WHERE email='…';` (use `'frozen'` or `'deactivated'` to test those; `'inactive'` may not be storable — see section 8).
1. **Basic two-way:** A sends an email to Admin → Admin sidebar badge `1`; bell item "Open conversation →" opens it; Admin replies (rich text) → A's sidebar badge `1` and bell; A opens Sent → both messages, replies again → Admin badge increments. **B cannot open it** (not found).
2. **Quick messages:** A sends three quick messages in a row → Admin inbox shows **one** conversation with three messages. After Admin replies, A's next message appears in the same conversation. "Start a new conversation" → Admin now sees two. Reload: a "New conversation" divider separates them in A's chat.
3. **Restricted owner:** suspend A. A's Messages / Message Admin pages show "Messaging is unavailable while your account is suspended…"; nothing can be sent. As **Admin** open one of A's conversations: yellow warning and tick box; sending without ticking is blocked; ticking and sending → toast "stored as pending delivery", message shows a "Pending delivery" note, **A gets no bell item**. As **Financial** (for a Financial-addressed conversation): the reply box is replaced by "Only an Admin can reply…".
   API check (token of the suspended user): `curl -s -H "Authorization: Bearer $T" -H "Accept: application/json" $API/api/marvflow/messages` → HTTP 403 with `{"success":false,"code":"account_restricted","status":"suspended","message":"Messaging is unavailable while your account is suspended. Please contact SSI support."}`.
   DB check: `SELECT id, sender_id, recipient_id, pending_since, notified_count FROM marvflow_messages WHERE direction='to_marvflow';` → pending row has `pending_since` set and `notified_count = 0`; and no new row in `notifications` for A.
4. **Release:** set A back to `active`, open the app as A → within one request the badge shows the pending replies and **exactly one** new bell item for that conversation:
   `SELECT COUNT(*) FROM notifications WHERE notifiable_id=<A id> AND type='App\\Notifications\\MarvflowMessageNotification' AND read_at IS NULL AND JSON_EXTRACT(data,'$.thread_id')=<conversation id>;` → `1`. Reload several times → still `1`; `pending_since` is now NULL on those rows.
5. **Restricted staff:** suspend the Admin account → the MarvFlow inbox page shows the restriction message and cannot reply (other Admin pages behave as before — login is unchanged).
6. **Regression:** investor messages, the Admin↔Financial staff channel and "Contact Dev Team" still work.

## 7. Safe rollback — read all of it
**Never `php artisan migrate:rollback --step=1`.** Use the exact file, newest first. `--path` limits the rollback to the named file, **and only if it belongs to the most recent batch**; otherwise Laravel prints "Migration not found" and does nothing (safe). Always run `--pretend` first and check `migrate:status`.
```bash
php artisan migrate:rollback --path=database/migrations/2026_10_29_100001_add_pending_delivery_to_marvflow_messages_table.php --pretend
php artisan migrate:rollback --path=database/migrations/2026_10_29_100001_add_pending_delivery_to_marvflow_messages_table.php
```
| Migration | What `down()` does | Pending replies / history |
|---|---|---|
| `2026_10_29` pending_since | drops 1 index + 1 column | **Refuses** (throws, changes nothing) while any reply is still pending. Pending replies release automatically when their owners next use messaging while active — usually just wait. If you must proceed now: fresh dump; `SELECT * FROM marvflow_messages WHERE pending_since IS NOT NULL;` (keep this export); tell the Admins who wrote them; then `DELETE FROM marvflow_messages WHERE pending_since IS NOT NULL;` and roll back. |
| `2026_10_28` threading | drops 3 indexes + `thread_id`, `direction`, `recipient_id` | **Refuses** while any SSI reply exists (it would otherwise look like an inbound MarvFlow message). Dump first; decide what to do with the replies. MarvFlow follow-ups are real inbound messages and simply become standalone messages (no loss). |
| `2026_10_27` kind | drops `kind` | **Data-changing:** quick messages become indistinguishable from emails (empty subject). Do not roll back with live data unless you restore from the dump. |
| `2026_10_26` create table | **drops the whole table** | **All message history is lost.** Never run without a verified dump. |

**Code rollback:** rolling back only the *code* while the new columns exist is **not safe** — the previous code has no account check and ignores `pending_since`, so restricted users could receive pending replies and SSI replies would show in the SSI inbox as inbound messages.
Either keep this code, or first remove pending/reply rows as above. **If anything looks wrong, restore the SQL dump from section 3.0** rather than improvising.

## 8. Separate security considerations (reported, NOT changed here)
- **Login policy:** `LoginController` blocks only `deactivated`; **`suspended`, `frozen` (and `inactive`) users can still log in** and reach every endpoint outside investor financial writes (`CheckAccountStatus` is applied to investor routes only). This delivery restricts **messaging only**.
- **`inactive` status:** the July migration redefines `users.status` as `ENUM('active','suspended','frozen','deactivated')`, dropping `inactive`, while `AdminUserController` still accepts it. The middleware refuses `inactive` as requested; I could not verify whether that value can still be stored.
- **Token revocation:** `AdminUserController::deactivate()` deletes tokens; I did not verify whether setting `deactivated` via the generic update endpoint does the same.
- **Deleting staff:** `marvflow_messages.sender_id` cascades, so deleting an Admin/Financial user deletes the replies they wrote (and deleting a MarvFlow user deletes their conversations). Relevant to the deferred foreign-key review.
- **Pre-existing:** the Email Center stores admin HTML unsanitised and the investor Emails page renders it with `dangerouslySetInnerHTML` (`RichTextSanitizer` could be reused there).

## 9. What I did and did NOT execute
| Check | Executed by me? | Result |
|---|---|---|
| Raw SQL of the code (conversation stats, latest-activity ordering, owner-side pending filters, release update, rollback guard queries) + the four migrations' DDL in order + legacy-row compatibility, on a real SQLite 3.45 database | **Yes** (`verification/sqlite_sql_check.py`, output in `verification/sqlite_sql_check.output.txt`) | 16 / 16 passed. *This is hand-mirrored SQL, not Laravel/PHP.* One of my own expected values was wrong on the first run and was corrected (the code was not at fault). |
| Babel parse of all 13 packaged JS/JSX files; relative imports resolve | **Yes** | 0 failures |
| PHP bracket/quote balance on all 18 packaged PHP files | **Yes** | all balanced (this is **not** a PHP syntax check) |
| Cumulative patches apply to your pristine upload and reproduce the final tree byte-for-byte; delta patches apply to base + both overlays | **Yes** | all four apply cleanly |
| Code audit: 15/15 messaging routes gated; model used in only 3 files; owner-side queries filter pending rows | **Yes** (by reading/grepping) | confirmed |
| Diff review: no financial / balance / deposit / withdrawal / investment / profit / payment / rank / login / account-policy file touched; no existing test edited | **Yes** | confirmed |
| **PHP syntax lint, all PHPUnit tests (new + existing), the migrations via `artisan`, rollbacks, `lockForUpdate` on MySQL, the sanitizer on real libxml** | **NO** | not run — no PHP here |
| **`npm ci` / `npm run build`** | **NO** | not run — Windows-only `node_modules` |
| **Anything in a browser / the running app** | **NO** | not run |
