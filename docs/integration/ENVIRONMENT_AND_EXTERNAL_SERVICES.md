# Environment variables and external services you must configure (no secret values included)

Copy `.env.example` to `.env` and fill in. Never commit `.env`. **Rotate** any credential that was ever in the original `.env` you shared (Brevo, Telegram, football/market data keys) if that file was transmitted outside your team.

## Core (already used by SSI)
`APP_KEY` (`php artisan key:generate`), `APP_URL` (**must be the public API URL — signed news-media and unsubscribe links are built from it**), `DB_*`, `MAIL_*`, `BREVO_API_KEY`, `TELEGRAM_*`, queue/cache/session settings.

## Task 2
`REGISTRATION_VISIT_TRACKING` (default false = no visitor tracking), `REGISTRATION_NOTIFY_ON_VISIT`, plus optional tuning vars listed in `.env.example`. Tracking notice wording awaits Management approval. Staff are created with `php artisan registration:create-staff`.

## Task 3 — Mailing (external provider: Brevo)
`MAILING_MAILER` (default `brevo`), `MAILING_FROM_ADDRESS` (must be a **Brevo-verified sender**), `MAILING_FROM_NAME`, `MAILING_WEBHOOK_SECRET` (empty = webhooks disabled), `MAILING_CONSENT_POLICY_VERSION`, `BREVO_API_KEY`.
Still required for live use:
1. Verify each sender/department address in Brevo (a mailbox shows `connected` only when Brevo lists it as an active sender).
2. In Brevo, point delivery-event and inbound-parse webhooks to `POST {APP_URL}/api/mailing/webhooks/delivery` and `/api/mailing/webhooks/inbound`, sending the secret in header `X-SSI-Webhook-Secret` (or Bearer). **Never exercised against a live Brevo account.**
3. SPF/DKIM/DMARC for the sending domain.
4. A running **queue worker** (`php artisan queue:work`) and the **scheduler** (`php artisan schedule:run` every minute) — needed for queued sends, scheduled campaigns/news, `registration:maintain`.

## Task 4 — News & Media
`NEWS_MEDIA_DISK` (default `local`, a private disk). On ephemeral hosts (e.g. Railway) use a persistent volume or S3-compatible **private** disk, otherwise uploads vanish on redeploy. `NEWS_MAX_IMAGE_KB`, `NEWS_MAX_VIDEO_KB`. PHP extensions `gd` and `fileinfo` must be enabled (image re-encode / MIME sniffing). Check web-server/PHP upload limits (`upload_max_filesize`, `post_max_size`) cover the video cap. Embeds are YouTube/Vimeo only.

## Task 5 and gap-closure variables
`REGISTRATION_STAGE1_ATTEMPTS_PER_HOUR` (30), `REGISTRATION_VERIFY_ATTEMPTS_PER_HOUR` (60), `REGISTRATION_MIN_RESPONSE_MS` (400; response-time floor against timing side channels), `WITHDRAWAL_FEE_ADJUSTMENTS_ENABLED` (default false — keep false until Management approves fee-refund rules), `REGISTRATION_DISCLOSURE_LIMIT_PER_HOUR` (default 20). The scheduler must run (`php artisan schedule:run` every minute) for `referrals:scan --no-backfill` (hourly), `registration:maintain`, `mailing:dispatch-scheduled`, `news:publish-scheduled` and the original Rank schedules. No other new variables. Nothing is payable until a Management-approved policy is saved by an **Administrator** in the Financial dashboard.

## Frontend
`VITE_API_URL` (backend base URL WITHOUT a trailing `/api` — the app appends `/api` itself), `VITE_APP_NAME`.

## Registration email delivery (now essential)
Registration cannot start without a working mailer: stage 1 emails a verification code and **no account exists until it is entered**. Configure `MAIL_*`/Brevo (`registration.resume_mailer`) and a verified sender before go-live; with `MAIL_MAILER=log` the code appears in `storage/logs/laravel.log` (test environments only).

## Existing flag worth knowing: `FINANCIAL_LEDGER_ENABLED`
Default `false` (original project). With it off, withdrawal submission/approval write no `wallet_ledger_entries`. Turn it on only after you decide you want the ledger in production.
