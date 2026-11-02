# MarvFlow → SSI Admin / Financial Team messages

## Architecture found (Phase 1 — from the code)
* **One backend, one database, one `users` table.** MarvFlow is not a separate system: its two roles (`marvflow_member`, `marvflow_lead`) are rows in the same `users` table, served by the same `routes/api.php` behind their own `marvflow` middleware. No cross-system API, shared secret or second database is involved or needed.
* **Roles:** `admin`, `financial`, `marvflow_member`, `marvflow_lead`, `investor` (string column `users.role`). Middleware: `admin`, `financial` (admin also passes), `marvflow` (admin deliberately does **not** pass).
* **Existing channels — none fits:** the Admin↔Financial staff channel (`staff_messages`) is plain text, no subject, Admin/Financial only. "Contact Development Team" (`team_requests`) is SSI→MarvFlow and an SSI user only sees requests they created, so MarvFlow cannot start a thread that Admin/Financial would see. Investor `messages` require an investor id. Hence one small new table.
* **Rich text:** the project already uses `react-quill` (Quill 1.3.7) in the Email Center, but its toolbar has only headings/bold/italic/underline/lists/link/image. It was **reused and extended** (font size, alignment, line spacing, paragraph spacing). There was **no HTML sanitiser** anywhere in the backend.

## Recipient policy (inferred from existing behaviour — SSI to confirm)
Existing precedent: the staff channel is a single shared thread and its alerts go to **every** user of the receiving role. The same is implemented here: a message is addressed to a **role** (`admin` | `financial`, a fixed list — never a user id from the client). It is visible to, and alerts, **every active user holding that role**. Admins can also open the Financial inbox (the existing "admins retain overall platform control" rule) but that never marks a message read.
**Not decided by this work:** whether Financial messages should instead go to one designated account or a distribution list. Changing it is a small change in `MarvflowMessageController::activeRecipients()` / the inbox query.

## What was built
| Layer | File |
|---|---|
| DB | `database/migrations/2026_10_26_100001_create_marvflow_messages_table.php` (one new table; rollback drops only it) |
| Model | `app/Models/MarvflowMessage.php` |
| Sanitiser | `app/Services/RichTextSanitizer.php` (allow-list) |
| MarvFlow API | `app/Http/Controllers/Marvflow/MarvflowMessageController.php` |
| SSI inbox API | `app/Http/Controllers/MarvflowInboxController.php` |
| Alert | `app/Notifications/MarvflowMessageNotification.php` (subject only, never the body) |
| UI | `components/MarvflowRichEditor.{jsx,css}`, `pages/marvflow/MarvflowMessages.jsx`, `pages/shared/MarvflowInbox.jsx`, nav + routes |
| Tests | `tests/Feature/MarvflowMessagesTest.php` (13) |

## Endpoints
* MarvFlow (`marvflow` middleware): `GET /api/marvflow/messages/recipients` · `GET /api/marvflow/messages` (own sent) · `POST /api/marvflow/messages` (`recipient`, `subject`, `body`, `client_token`; 20/min) · `GET /api/marvflow/messages/{id}` (own only)
* Admin inbox: `GET /api/admin/marvflow-messages` · `…/unread-count` · `…/{id}`
* Financial inbox: `GET /api/financial/marvflow-messages` · `…/unread-count` · `…/{id}`
* Pages: MarvFlow `/marvflow/messages` (Compose + Sent) · Admin `/admin/marvflow-messages` · Financial `/financial/marvflow-messages`

## Delivery and status
* "Delivered" means: the row is saved **and** the inbox endpoints return it to the addressed role. The same table is the system of record and the inbox — there is no separate copy to get out of sync.
* The UI shows success only from the server's 201/200 reply. Statuses are only **sent** (saved, in the SSI inbox) and **read** (opened by a user whose role is the addressed role; reader and time recorded). Nothing claims "delivered to a phone/email".
* **No active account for the chosen role** → 422, nothing saved, clear message; the recipient picker also shows it as unavailable.
* **Retries:** the client sends a `client_token` (kept across retries, renewed after a confirmed send). `UNIQUE(sender_id, client_token)` + a pre-check mean a retry returns the saved message (HTTP 200, `duplicate: true`) with no second row and no second alert.
* Audit record: sender, recipient role, created/read timestamps, status, reader, alert count on the row itself. Errors are logged with ids only (never subject/body/credentials).

## Security
* Only authenticated `marvflow_*` users can send (middleware); sender is always `auth()->user()`; recipient is validated against a fixed list — smuggled `recipient_id`/`sender_id`/`role` fields are ignored (tested).
* Rich text sanitised **on the server at write and again on read** (allow-list: p, br, strong/b, em/i, u, s, ul, ol, li, span, div, blockquote; only `font-size` 8–48 px/pt, `text-align`, `line-height` 1–3.99, `margin-top/bottom` ≤48 px). Scripts, styles, iframes, objects, svg, forms, images, links, comments, every attribute except filtered `style` (so all event handlers) are removed. Subject is plain text.
* Alerts carry the subject only. MarvFlow users cannot read either SSI inbox; Financial users cannot read the Admin inbox; one MarvFlow member cannot open another's sent message (404). No credentials involved (same backend).
* Not touched: investor balances, deposits, withdrawals, investments, rank criteria, financial permissions, existing messaging tables.

## Deploy
```bash
php artisan migrate --force      # adds marvflow_messages only
php artisan config:clear
php artisan test --filter=MarvflowMessagesTest     # NOT RUN by the author (no PHP in the build sandbox)
npm run build                                       # NOT RUN by the author
```

## Verification status — read this
* **Not run:** backend tests (no PHP here), the production build (no working `node_modules` here), and any browser. The 13 tests document the intended behaviour; expect to fix small first-run defects.
* **Run:** bracket check on the PHP; TypeScript-parser syntax check on all changed JSX (0 errors); import/icon-import checks; and a contract check that every value the editor can emit (6 sizes, 5 line heights, 4 paragraph spacings, alignments, tags) is accepted by the sanitiser's exact rules.
* **Not exercised:** Quill's custom toolbar pickers/labels in a real browser, and the sanitiser against real PHP/libxml. Please test the rich-text toolbar by hand: apply each control, send, and open the message in the Admin inbox.

## Limitations / open items
* SSI users cannot reply from the inbox (out of scope; replies still go through the existing "Contact Dev Team" channel).
* Rich text is plain formatting only — no links, images or attachments in these messages.
* **Pre-existing, not changed:** the Email Center stores admin-authored HTML unsanitised and the investor Emails page renders it with `dangerouslySetInnerHTML`. `RichTextSanitizer` could be applied there too.
* The recipient policy above needs SSI Management's confirmation.


---

# Update: professional email interface + "Message Admin" (quick messages)

## Status found before this change
Composer existed with recipient / subject / body and a partial toolbar (size, bold, italic, underline, alignment, lists, line and
paragraph spacing). Missing: strikethrough, font family, colour, highlight, indentation, hyperlinks, undo/redo, expandable editor,
Discard with confirmation, and the whole Message Admin workflow. The sanitiser removed links, colours and fonts.

## What changed
| Area | Change |
|---|---|
| DB | `2026_10_27_100001_add_kind_to_marvflow_messages_table` — ONE defaulted column `kind` (`email` \| `direct`). Existing rows become `email` and stay readable. No new table: quick messages reuse the same inbox, read tracking, idempotency and alerts |
| API | `POST /api/marvflow/messages/direct` (`body`, `client_token`; 30/min). `GET /api/marvflow/messages?kind=` and `GET /api/{admin,financial}/marvflow-messages?kind=` filters; responses gain `kind`, `kind_label` (+ `unread_by_kind` on the inbox) |
| Sanitiser | `RichTextSanitizer` **extended in place** (no second sanitiser): safe links, `color`/`background-color` (#rgb, #rrggbb, rgb()), `font-family` from a fixed list, `ql-indent-1..8` class |
| Editor | `MarvflowRichEditor` — the existing `react-quill` extended; no new library |
| UI | MarvFlow page with three entry points: **Compose Email**, **Message Admin**, **Sent**; nav items "Compose Email" and "Message Admin"; Admin inbox gains All / Emails / Quick messages filter |

## Message Admin — how it works
* One text box (plain text, max 2000 characters, Ctrl+Enter sends), shown as a conversation history (oldest to newest) with "Sent" / "Read by …".
* **Recipient policy unchanged:** a quick message is ALWAYS addressed to the `admin` role (server-fixed, not a parameter) and is visible to, and alerts, every active admin — the same rule as email to the Admin. Client-supplied recipient/sender/role/kind/subject are ignored (tested).
* Stored subject is empty; the UI labels it "Quick message". Body is escaped to text, so markup can only ever display as text.
* Read status is the same verified signal as email: set only when a user whose role is `admin` opens it in the Admin inbox.
* Alerts for quick messages say who sent one — never the text.

## Professional email toolbar (all inline styles / editor classes, matching the server allow-list)
Font family (Arial, Georgia, Verdana, Tahoma, Courier) · size (12–32 px) · bold · italic · underline · **strikethrough** · text colour · highlight · alignment (left/centre/right/justify) · bulleted/numbered lists · indent/outdent · line spacing · paragraph spacing · **hyperlinks** (http/https/mailto) · **undo/redo** · clear formatting · expand/collapse editor. Formatting applies to the body only; To and Subject are separate fields. "Default" entries remove a format.
Links in the editor must start with https:// or mailto: (a hint is shown); anything else is unwrapped by the server (text kept).

## Discard / drafts
Discard asks for confirmation. **Save Draft is NOT implemented:** there is no draft infrastructure, and a server-side draft would add a third message state that every inbox query would have to exclude (a leak risk). Instead the page warns before the browser tab is closed with unsent content.

## Tests (written; NOT run — no PHP in the build sandbox)
`tests/Feature/MarvflowMessagesTest.php` (13, unchanged) · `tests/Feature/MarvflowDirectMessagesTest.php` (14: quick send to every admin, member and lead, history + read status, kind filters, plain-text safety, validation, recipient/sender tampering, alerts without content, retries, no active admin, authorisation, other member's messages, pre-upgrade rows readable, extended formatting round trip, hostile link/style neutralisation) · `tests/Unit/RichTextSanitizerTest.php` (9).

## What WAS verified (real browser, Chrome 131 headless, Quill 1.3.7 browser build, the component's own source)
* All controls render; real clicks on Bold, Undo, Redo, Clear and the Font/Size/Line/Para/Colour/Align pickers, list and indent buttons produce the expected HTML; choosing "Default" removes the format.
* Every tag, style, class and link the real editor emitted is accepted by the server's rules (checked with a port of the sanitiser's rules read from the PHP source).
* No horizontal overflow at 320, 375, 390, 430 and 1024 px for the editor and for the chat/sent/reading layouts (after fixing one real overflow: a long unbroken title); 40 px minimum touch targets. Screenshots in `evidence/`.
* Found and fixed during verification: pickers displayed their first value as if already applied (added a Default entry); a regex in `safeHref` that would have failed open; one overflow.
## What was NOT verified
PHP execution (every backend test, the migration, the sanitiser on real libxml), the Vite production build, the React pages inside the running app (only the editor and static copies of the page layouts were browser-tested), and the SSI inbox in a browser.

## Remaining limitations
No Save Draft; no replies from SSI to MarvFlow inside this channel; no attachments in these messages; Quill 1.3.7 logs a deprecation warning for the browser's `DOMNodeInserted` event (existing in the Email Center too; no effect seen).


---

# Update (revision 3): two-way conversations, continuous quick messages, restricted accounts
This supersedes the earlier statements "SSI users cannot reply from the inbox" and "recipient policy unchanged" where they conflict.
Full apply / test / rollback instructions: see `APPLY.md` in the delivery package.

* **Conversations.** A conversation is its first message plus replies, stored in the SAME `marvflow_messages` table (`thread_id`, `direction`
  `to_ssi|to_marvflow`, `recipient_id`; migration `2026_10_28_100001`). Existing rows are untouched and each is a conversation of one.
  Admin/Financial reply from their inbox (`POST /api/{admin,financial}/marvflow-messages/{id}/reply`); the MarvFlow conversation owner follows up
  (`POST /api/marvflow/messages/{id}/reply`). Only the owner can read or answer a conversation on the MarvFlow side.
* **Quick messages** from one user to the Admin continue ONE running conversation (chosen on the server); `new_conversation: true` starts another.
* **Restricted accounts.** Only `active` accounts may use these endpoints (`EnsureMessagingAccountActive`, 15 routes, MarvFlow and inbox sides); others get
  403 `account_restricted`. Login and all other endpoints are unchanged.
* **Pending delivery.** If the conversation owner is restricted, only an Admin may reply, after explicit confirmation (`confirm_pending`); the reply is stored
  with `pending_since` set (migration `2026_10_29_100001`): no alert, invisible to and uncounted for the owner, released once, atomically, the first time the
  owner uses messaging while active. Financial users cannot reply in that situation.
* **Not done on purpose:** database foreign keys on `thread_id` / `recipient_id` (deferred); changes to login or the global suspension policy.
