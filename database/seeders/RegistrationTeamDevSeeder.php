<?php
// LOCATION: database/seeders/RegistrationTeamDevSeeder.php   (NEW FILE)
//
// LOCAL / DEVELOPMENT-ONLY seeder: creates ONE Registration Team test login so the Registration Team dashboard can be tested on its own.
//
// Safety properties (each is covered by tests/Feature/Registration/RegistrationDevSeederTest.php):
//   * Runs only when APP_ENV is local / development / testing. production, staging and unknown environments are refused.
//   * Creates only a registration_member or registration_lead account, never admin, financial, marvflow or investor.
//   * Holds exactly the permissions of the existing Registration roles (the existing role checks / Gates); it grants nothing extra and
//     does not touch the mailing/news permission tables.
//   * The e-mail address must be on a reserved test domain, so it can never be a real person's mailbox.
//   * The password comes from DEV_REGISTRATION_TEST_PASSWORD in your local .env. There is no default and nothing is printed or logged.
//   * Idempotent: running it again never creates a duplicate and never changes an existing account's password or role.
//   * It refuses (loudly) to touch an existing account that is not a Registration Team account.
//   * It is intentionally NOT listed in DatabaseSeeder, so `migrate --seed` / `db:seed` can never run it by accident.
//
// Run:  php artisan db:seed --class=RegistrationTeamDevSeeder
//
// E-mail verification is not weakened: like every staff account in this codebase (see app/Console/Commands/RegistrationCreateStaff.php)
// the account is created already verified, because staff have no self-service sign-up. Nothing in the login/verification code is changed.

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class RegistrationTeamDevSeeder extends Seeder
{
    public function run(): void
    {
        $cfg = (array) config('registration_dev_seed');

        $this->assertDevelopmentEnvironment($cfg);

        $email    = strtolower(trim((string) ($cfg['email'] ?? '')));
        $name     = trim((string) ($cfg['name'] ?? '')) ?: 'Registration Test Member';
        $role     = trim((string) ($cfg['role'] ?? ''));
        $password = (string) ($cfg['password'] ?? '');

        $this->assertAllowedRole($role, $cfg);
        $this->assertReservedTestEmail($email, $cfg);

        $existing = User::where('email', $email)->first();
        if ($existing !== null) {
            // Never convert, re-role or re-password an existing account. Only an existing Registration Team account is accepted as
            // "already seeded"; anything else under this address is a conflict the developer must resolve by hand.
            if (!in_array($existing->role, (array) $cfg['allowed_roles'], true)) {
                throw new RuntimeException("Refusing to continue: {$email} already belongs to a non-Registration account. It was not touched. Choose a different DEV_REGISTRATION_TEST_EMAIL.");
            }
            $this->say("Registration test account {$email} already exists (#{$existing->id}, {$existing->role}). Left unchanged.");
            return;
        }

        $this->assertUsablePassword($password, $cfg);

        $user = new User();
        // role / status are deliberately not mass-assignable on User (F-12), so forceFill is used, same as registration:create-staff.
        $user->forceFill([
            'name'                   => $name,
            'full_name'              => $name,
            'email'                  => $email,
            'password'               => Hash::make($password),
            'role'                   => $role,
            'status'                 => 'active',
            'email_verified_at'      => now(),
            'registration_stage'     => 4,
            'registration_completed' => true,
            'balance'                => 0,
        ])->save();

        $this->say("Created {$role} test account #{$user->id} ({$email}). Log in with the password you set in DEV_REGISTRATION_TEST_PASSWORD.");
    }

    private function assertDevelopmentEnvironment(array $cfg): void
    {
        $allowed = (array) ($cfg['allowed_environments'] ?? []);
        if ($allowed === [] || !app()->environment($allowed)) {
            throw new RuntimeException('Refusing to run: this seeder is for local development only (APP_ENV must be one of: ' . implode(', ', $allowed) . '). Current APP_ENV: ' . app()->environment() . '. No account was created.');
        }
    }

    private function assertAllowedRole(string $role, array $cfg): void
    {
        if (!in_array($role, (array) ($cfg['allowed_roles'] ?? []), true)) {
            throw new RuntimeException('Refusing to run: role must be registration_member or registration_lead. No account was created.');
        }
    }

    private function assertReservedTestEmail(string $email, array $cfg): void
    {
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Refusing to run: DEV_REGISTRATION_TEST_EMAIL is not a valid e-mail address. No account was created.');
        }
        foreach ((array) ($cfg['allowed_email_suffixes'] ?? []) as $suffix) {
            if (str_ends_with($email, strtolower($suffix))) {
                return;
            }
        }
        throw new RuntimeException('Refusing to run: the test e-mail must be on a reserved test domain (.test, .local, .localhost, .invalid or example.com/org/net) so it can never be a real mailbox. No account was created.');
    }

    private function assertUsablePassword(string $password, array $cfg): void
    {
        $min = (int) ($cfg['min_password_length'] ?? 12);
        if ($password === '') {
            throw new RuntimeException('Refusing to run: set DEV_REGISTRATION_TEST_PASSWORD in your local .env (a password you choose, at least ' . $min . ' characters). Nothing is hard-coded. No account was created.');
        }
        if (strlen($password) < $min) {
            throw new RuntimeException("Refusing to run: DEV_REGISTRATION_TEST_PASSWORD must be at least {$min} characters. No account was created.");
        }
    }

    /** $this->command is null when the seeder is invoked directly from a test; stay quiet then. */
    private function say(string $message): void
    {
        if ($this->command !== null) {
            $this->command->info($message);
        }
    }
}
