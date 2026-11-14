# Registration Team test account (local development only)

Creates ONE Registration Team login so you can test the Registration Team dashboard on its own.
It never runs in production, never creates an Admin/Financial account, has no default password, and is safe to run twice.

## 1. Prerequisites (local machine only)

* `APP_ENV=local` in your local `.env` (the seeder refuses any other value except `development` / `testing`).
* Migrations are up to date: `php artisan migrate` (the Registration roles come from migration `2026_11_06_100001`).

## 2. Choose a password (never commit it)

Add to your **local** `.env` (it is git-ignored; do not paste it into any committed file, chat or ticket):

```
DEV_REGISTRATION_TEST_PASSWORD=<a password you choose, at least 12 characters>
# optional:
# DEV_REGISTRATION_TEST_EMAIL=registration.dev@ssi-dev.test     (must end in .test / .local / .localhost / .invalid, or be @example.com/.org/.net)
# DEV_REGISTRATION_TEST_NAME="Registration Test Member"
# DEV_REGISTRATION_TEST_ROLE=registration_member                 (or registration_lead)
```

If you have ever run `php artisan config:cache` locally, run `php artisan config:clear` first.

## 3. Create the account

```
php artisan db:seed --class=RegistrationTeamDevSeeder
```

Expected output: `Created registration_member test account #<id> (registration.dev@ssi-dev.test) ...`
Running it again prints `... already exists ... Left unchanged.` and changes nothing (no duplicate, password and role untouched).

It is intentionally **not** in `DatabaseSeeder`, so `migrate --seed` can never create it.

## 4. Log in and test

1. Backend: `php artisan serve` (http://localhost:8000). Frontend: `npm run dev` (http://localhost:5173).
2. Open `/login`, sign in with the e-mail above and the password you put in `.env`.
3. You are taken to `/registration/dashboard`. Use **Log out** (top bar or sidebar) to end the session.
4. To test the Admin round-trip, sign in as your normal Admin, click **Registration Team** in the sidebar, then **Back to Admin Dashboard**
   (sidebar and top bar). Resize the browser below 900px to test the mobile drawer.
5. To check separation, while signed in as the test account try `/admin/dashboard`, `/financial/dashboard`, `/admin/mailing/investor`:
   each should bounce you back to `/registration/dashboard`. The API refuses the same calls with 403.

## 5. Changing or removing the test account

* Change its password in the app: **My account** (`/registration/profile`). The seeder will not overwrite it.
* Remove it: `php artisan tinker`, then `App\Models\User::where('email','registration.dev@ssi-dev.test')->delete();`

## 6. What the seeder refuses (all covered by `RegistrationDevSeederTest`)

* `APP_ENV` other than local / development / testing (production, staging, unknown).
* Any role other than `registration_member` / `registration_lead`.
* A missing or shorter-than-12-character `DEV_REGISTRATION_TEST_PASSWORD`.
* An e-mail that is not on a reserved test domain.
* An address that already belongs to an Admin, Financial or investor account (that account is left untouched).

E-mail verification and login are not changed: like every staff account here, the test account is created already verified because staff have no
self-service sign-up (same as `registration:create-staff`).
