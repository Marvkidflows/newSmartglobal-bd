# Registration security remediation report (enumeration closure)

**Status: implemented and statically checked; NOT executed.** No PHP/PHPUnit/MySQL/browser run was possible. Written tests: 21 new + updated existing ones.

## 1. The vulnerability (confirmed from the code)
`POST /api/register/stage1` was public and answered differently by case: completed/staff/inactive email → 422 "already registered"; unfinished investor → 200 + code emailed; new email → 200 + **account created + token + OTP email + Telegram alert**; a phone owned by another account → 422 on `phone`. A caller could therefore (a) learn whether an email/phone is registered, (b) create unlimited junk accounts, (c) trigger unlimited OTP emails to third parties and Telegram alerts. Response timing also differed (a mail send happened only in some cases).

## 2. Is email verification before account creation necessary?
**Yes.** With the account created at stage 1 there is no way to return the same answer for an existing and a new email (a new email must return a usable token; an existing one must not). The only way to make all cases identical is to stop creating the account at stage 1 and to give the token only to someone who has proved control of the mailbox.

## 3. New design
| Step | Behaviour |
|---|---|
| `POST /api/register/stage1` | Validates **format only** (name, email syntax, phone format, password, privacy acknowledgement). Creates **nothing**, issues **no token**, raises **no Telegram alert**, records no referral/tracker event. Returns the same 200 body for every email and phone: `verification_required: true`, `email_sent: true`, a constant `retry_after_seconds`, a generic "if this address can be used…" message |
| What happens silently (after the response is sent) | new email → a 6-digit code is emailed (new table `registration_email_challenges`); unfinished investor → the existing resume code is emailed to the address on file; completed / admin / staff / Registration / suspended → **nothing is sent, read or changed** |
| `POST /api/register/verify` (and the old `/register/resume/verify`) | Takes email + code + the form. Unknown email, ineligible account, wrong/expired/locked/undelivered code → one identical 422. New email + correct code → the account is created **in the same transaction that consumes the code** (role `investor`, status `active`, balance 0 — all server-set), then the token is returned and the Telegram alert/tracker/referral event run. Unfinished investor + correct code → unchanged Task 2 atomic resume |
| Phone already registered | Not checked at stage 1. Checked only **after a correct code** (email ownership proved). The code stays valid so the visitor can correct the phone |
| Rate limits (additional defences) | route throttle 10/min (unchanged); per-IP hourly budget counting **every** stage-1 request (30) and verify request (60) with a generic 429; per-**address** cooldown (60 s) and hourly cap (5) enforced in the database and counted over all rows, so rotating IPs does not help and a victim's inbox cannot be flooded; 5 wrong guesses destroy a code; codes expire in 10 minutes and are single-use |
| Timing | all issuing/sending work runs after the response; stage 1 and verify are padded to a floor (`REGISTRATION_MIN_RESPONSE_MS`, default 400 ms). This removes the large differences (mail send, DB writes); it is **not** a formal constant-time guarantee |
| Email content | the new-email code message contains **no text from the request** (no name), so it cannot carry attacker-chosen wording to a third party |

## 4. What is preserved
Public registration can only create an `investor` (role/status/balance/KYC/rank fields in the request are ignored, in both stage 1 and verify). Staff/admin/Registration/completed/inactive accounts can never be resumed or taken over: the eligibility check (`RegistrationResumeService::eligible`) is evaluated before any code is looked at, and a new-email code can never be applied to an existing account. OTP security is unchanged (HMAC-stored, single use, expiry, attempt cap, fail-closed delivery status). Resume stays atomic (InnoDB transaction). Rotating `APP_KEY` invalidates in-flight codes (10 minutes).

## 5. Residual risk (honest)
* **Phone numbers.** An attacker who can read a mailbox (any throwaway address) can still learn whether a phone number is registered, by completing verify with a *correct* code and that phone: each probe costs one mailbox verification and the answer is budgeted per IP (default 20/hour). Closing it entirely requires proof of **phone** ownership (an SMS code) or deferring duplicate-phone handling to KYC review — both are **Management decisions** (SMS provider cost / business rule), not changed here.
* Timing is mitigated, not proven constant.
* An email owner whose address is **already registered** and who requests a code receives nothing and sees the generic message; that is the price of not revealing registration. The frontend wording says "if this email can be used".
* Accounts created at stage 1 by the OLD flow and left unfinished keep working (resume path).

## 6. Frontend changes (needed because stage 1 no longer returns a token)
`RegisterPage.jsx`/`registrationResume.js`: stage 1 always shows the existing "enter the code we emailed" screen (the Task 2 resume UI); the verify body now also carries `referral_code` (the account is created at verify); messages are generic. The `registrationResume.test.js` assertion about the referral code was changed accordingly (contract change). **Not built or run here.**

## 7. Tests (21 new, in `RegistrationEnumerationTest`; written, not executed)
identical stage-1 responses for new / unfinished / completed / admin / Registration / suspended emails; phone does not change the response; no account or token at stage 1; mail only when a code is due (never for completed/staff/inactive); the email contains no request text; no Telegram at stage 1 and exactly one after proof; probing 20 addresses creates nothing; every verify failure identical; wrong code + registered phone gives no phone error; duplicate phone only after the code and the code survives; phone-conflict budget; cooldown; hourly cap across IPs; per-IP stage-1 budget with generic 429; 5 wrong guesses destroy the code; single use + expiry; rollback keeps the code; undelivered code never usable; a new-email code cannot take over a staff or unfinished account; response-time floor; public registration still investor-only.
Updated (contract change, assertions kept in spirit): `RegistrationResumeSecurityTest` (stage 1 now 200 for staff/suspended; brand-new email creates account only after verify; failed-delivery tests assert an identical response), `RegistrationRoleSecurityTest`, `RegistrationEmailTest`, `PrivacyPolicyRegistrationTest`, Task 5 `ReferralRewardTest`/`Task5IntegrationTest` (registration helpers), `registrationResume.test.js`. The superseded `RegistrationEnumerationThrottleTest` was removed (it tested the old per-IP "disclosure" mitigation).

## 8. Files
New: migration `2026_11_10_100001_create_registration_email_challenges_table`, `RegistrationEmailChallenge`, `RegistrationEmailChallengeService`, `RegistrationEmailCodeMail` + view, `tests/Concerns/RegistersWithEmailCode`, `RegistrationEnumerationTest`. Modified: `RegisterController`, `routes/api.php`, `config/registration.php`, `RegistrationMaintain` (prunes codes older than 7 days), `phpunit.xml` (floor off in tests), `.env.example`, frontend files above.
