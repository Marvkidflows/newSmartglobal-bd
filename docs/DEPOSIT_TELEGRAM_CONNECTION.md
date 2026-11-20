# Deposit → automatic Telegram connection (Stage 1)

Local / testing only. Nothing here was run against production.

## What the code actually did before (traced, not assumed)

| Layer | Finding |
|---|---|
| Frontend | `src/pages/investor/InvestorDeposits.jsx`: Step 1 quiz+amount → `POST /investor-investment/investor/deposits/initiate` → **Step 2 page with a manual "Message … on Telegram" button** → Step 3 payment proof (`PUT …/{id}/confirm`) → Step 4 success. |
| Frontend | A hard-coded fallback agent ("Agent Frank", `@AgentlanFrank`, `t.me/Regionalrepresentative`) was shown whenever the API returned no agent. |
| Backend | `InvestorDepositController@initiate` already saves the deposit as `pending`, writes a `pending_deposit` ledger entry (direction `info`, no money moves) and alerts staff. It credits nothing and creates no investment. |
| Backend | **There was no per-investor agent assignment.** `getAgent()` returned one global value (`TELEGRAM_AGENT_USERNAME`) with a hard-coded default `AgentlanFrank`. Everyone went to the same contact. |
| Backend | A bot exists (`TelegramService`, token + group chat ids). It only knows group chats; there were **no agent usernames or chat ids stored anywhere**. |
| Backend | No duplicate-submit protection; notifications fired on every call. |
| Approval | Unchanged and untouched: `Admin/AdminDepositController@approve` behind `can:financial.act-on-transactions`, the payment-verification gate, wallet credit and investment creation all happen only there. |

**Management's brief assumes agents are already assigned to investors. In the code they are not.** This stage therefore adds the smallest additive data model needed to make "their actual assigned agent" real, and keeps the old single-representative behaviour as the fallback so nothing changes for investors until an agent is assigned.

## New behaviour

`Submit → saved PENDING (server) → investor's own agent resolved server-side → Telegram opened automatically → confirmation page`

Agent resolution (`App\Services\DepositAgentResolver`), in order:
1. **Assigned agent**: `users.assigned_agent_id` → must be an **active** `financial`/`admin` account **with a valid Telegram username**. If the assignment is invalid (inactive, wrong role) it is ignored. If the agent is valid but has no usable username the investor gets the "details unavailable" message — they are *not* silently handed to somebody else's contact.
2. **Existing deployment-wide representative**: `TELEGRAM_AGENT_USERNAME` (+ optional `TELEGRAM_AGENT_NAME`). This is the pre-existing policy for investors without an assignment. **There is no built-in default any more.**
3. Otherwise `agent: null` → frontend shows a clear fallback message; the deposit is still saved.

Frontend:
- On click (a user gesture) a tab is reserved with `window.open('', '_blank')`; once the server confirms the deposit is saved, that tab is pointed at the agent's `https://t.me/<username>` link. This is the reliable way to get past popup blockers for an asynchronous flow, and the SSI tab stays open so the investor can come back for Step 3 (proof).
- If the browser refuses, the page says so and shows a real link/button (never claims it opened). If there is no agent link, nothing is opened, the blank tab is closed, and a fallback message with the reference is shown.
- Only `https://t.me/<valid-username>` links are ever opened.
- There is no required "go to Telegram" button. Step 2 is now the *confirmation* page (reference, status Pending, the fallback if needed, "I've made my payment" → Step 3 as before).
- Browsers cannot report whether the Telegram app itself launched; "opened" means a browser tab was navigated to the link. The wording reflects that, and a small "open it again" link is always available.

Duplicates:
- Frontend: synchronous lock (not just a disabled button), one idempotency key per (plan, amount) reused for retries, fresh key after success.
- Backend: `deposits.idempotency_key`, UNIQUE per investor. A retry returns the **same** deposit (HTTP 200, `duplicate: true`) and sends **no** second ledger entry or Telegram message. A race between two identical requests is caught by the unique index.

Notifications:
- The existing staff-chat alert is kept (now escaped, and sent only for newly created deposits).
- The assigned agent is DM'd **only if** their `telegram_chat_id` is stored (Telegram does not let bots message people who never started the bot — a username alone is not enough). `deposits.agent_notified_at` and the `agent_notified` response flag are set **only when Telegram answers `ok:true`**. HTTP 200 with `ok:false`, HTTP errors and exceptions all count as not delivered, are logged without the token, and never affect the saved deposit.
- The DM contains: reference, investor name + account id, plan, intended USD amount. No email, phone, balances, bank/payment details or proof. The prefilled message the investor sends the agent contains only name + reference (the amount is no longer prefilled).

API exposure: the response contains `agent: {name, link}` only. Chat ids and the raw username column are never returned. The client cannot choose the agent, the owner or the status (`user_id`, `agent`, `status` in the body are ignored).

## Safeguards that did not change
Deposit status is always `pending` on submission. No wallet credit, no investment, no ledger credit. Approval, rejection, hold, payment-verification, role middleware and gates are untouched.

## Setup for local testing
```
php artisan migrate        # 2026_11_19_100001_add_deposit_agent_routing (additive, nullable columns)
# optional single representative for investors with no individual agent:
#   .env: TELEGRAM_AGENT_USERNAME=real_handle   TELEGRAM_AGENT_NAME="SSI Desk"
# per-investor assignment (no admin UI yet - see "Decisions needed"):
php artisan ssi:deposit-agent telegram agent@example.com @agent_handle --chat-id=123456789
php artisan ssi:deposit-agent assign   investor@example.com agent@example.com
php artisan ssi:deposit-agent show     investor@example.com      # what that investor would be connected to
php artisan ssi:deposit-agent unassign investor@example.com
```
Use real handles you control; the system will not invent any. `--chat-id` is optional (without it the investor is still connected, the agent is just not DM'd by the bot; the staff group alert is unchanged).

## Decisions needed from management
1. **Who may assign agents, and through which screen?** Only the artisan command exists. An admin UI needs a role/permission decision, so it was deliberately not built.
2. Should an investor whose assigned agent has no Telegram username fall back to the shared representative (currently: no — they see the "unavailable" message)?
3. If an agent is reassigned later, should open (pending) deposits follow the new agent? (Currently the link is resolved live from the investor's current assignment.)

## Tests
Backend: `tests/Feature/DepositTelegramConnectionTest.php` (new, 23 tests) and `tests/Feature/Rank/RankTelegramDepositTest.php` (one test updated: it relied on the removed hard-coded default, so it now configures `TELEGRAM_AGENT_USERNAME`). Run: `php artisan test --filter="DepositTelegramConnectionTest|RankTelegramDepositTest"`.
Frontend: `src/utils/depositTelegram.test.js` (`npm test`); real-browser scenarios: `scripts/deposit-browser-check/` (needs `playwright`; see header of `build.mjs`).

`docs/app/` in the backend is an older snapshot of source files and is not loaded by the application; it still contains the old default agent and was intentionally left alone.
